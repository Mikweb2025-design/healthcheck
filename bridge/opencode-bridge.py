#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""
OpenAI-compatible bridge to a local OpenCode install, so Health Check (running in a container) can use OpenCode models
that only answer inside OpenCode itself (the free "Zen" models).

POST /v1/chat/completions  ->  `opencode run --pure --format json -m <model> <prompt>`

Safety: bearer token required; listens only on the address given in HC_BRIDGE_HOST (the Docker gateway, never a public
address); only models starting with "opencode/" are accepted (so no other provider key on the host can be used); every
call runs in an empty directory with its own OpenCode config that denies edit/bash/webfetch and has no MCP servers;
body limit 256 KB; at most 2 calls at a time. Run it as an unprivileged user (see install-bridge.sh).
Privacy: the prompt goes to the OpenCode model provider. Health Check only sends aggregates, but free models may be
used by their provider to improve the model: check their terms before using real health data.
"""
import hmac, json, os, subprocess, sys, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HOST = os.environ.get('HC_BRIDGE_HOST', '127.0.0.1')
PORT = int(os.environ.get('HC_BRIDGE_PORT', '8788'))
TOKEN = os.environ.get('HC_BRIDGE_TOKEN', '')
OPENCODE = os.environ.get('HC_BRIDGE_OPENCODE', 'opencode')
HOME = os.environ.get('HC_BRIDGE_HOME', '/var/lib/hcbridge')
TIMEOUT = int(os.environ.get('HC_BRIDGE_TIMEOUT', '120'))
MAX_BODY = 256 * 1024
SLOTS = threading.Semaphore(2)


def prompt_of(messages):
    parts = []
    for m in messages:
        role = str(m.get('role', 'user')).upper()
        parts.append('[%s]\n%s' % ('SYSTEM' if role in ('SYSTEM', 'DEVELOPER') else role, str(m.get('content', ''))))
    return '\n\n'.join(parts)


def text_of(stdout):
    out = []
    for line in stdout.splitlines():
        try:
            e = json.loads(line)
        except ValueError:
            continue
        if e.get('type') == 'text' and (e.get('part') or {}).get('text'):
            out.append(e['part']['text'])
    return ''.join(out).strip()


def run_opencode(model, prompt):
    work = os.path.join(HOME, 'work')
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin:/snap/bin'), 'HOME': HOME, 'XDG_CONFIG_HOME': os.path.join(HOME, 'cfg'),
           'OPENCODE_CONFIG': os.path.join(HOME, 'cfg', 'opencode.json')}
    p = subprocess.run([OPENCODE, 'run', '--pure', '--format', 'json', '-m', model, prompt], cwd=work, env=env,
                       stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=TIMEOUT)
    return text_of(p.stdout)


class H(BaseHTTPRequestHandler):
    server_version = 'hc-opencode-bridge'

    def log_message(self, fmt, *a):
        sys.stderr.write('%s %s\n' % (self.address_string(), fmt % a))

    def send_json(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def do_POST(self):
        auth = self.headers.get('Authorization', '')
        if not TOKEN or not hmac.compare_digest(auth, 'Bearer ' + TOKEN):
            return self.send_json(401, {'error': {'message': 'unauthorized'}})
        if self.path.split('?')[0] != '/v1/chat/completions':
            return self.send_json(404, {'error': {'message': 'not found'}})
        n = int(self.headers.get('Content-Length') or 0)
        if n <= 0 or n > MAX_BODY:
            return self.send_json(413, {'error': {'message': 'bad size'}})
        try:
            req = json.loads(self.rfile.read(n))
            model = str(req['model'])
            msgs = req['messages']
            assert isinstance(msgs, list) and msgs
        except Exception:
            return self.send_json(400, {'error': {'message': 'bad request'}})
        if not model.startswith('opencode/') or any(c.isspace() or c in ';|&$`' for c in model):
            return self.send_json(400, {'error': {'message': 'only opencode/* models'}})
        if not SLOTS.acquire(timeout=5):
            return self.send_json(429, {'error': {'message': 'busy'}})
        try:
            ans = run_opencode(model, prompt_of(msgs))
        except subprocess.TimeoutExpired:
            return self.send_json(504, {'error': {'message': 'timeout'}})
        except Exception as e:
            return self.send_json(502, {'error': {'message': 'opencode failed: %s' % type(e).__name__}})
        finally:
            SLOTS.release()
        if not ans:
            return self.send_json(502, {'error': {'message': 'empty answer'}})
        self.send_json(200, {'id': 'hc-%d' % int(time.time() * 1000), 'object': 'chat.completion', 'created': int(time.time()), 'model': model,
                             'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': ans}, 'finish_reason': 'stop'}]})


if __name__ == '__main__':
    if not TOKEN or len(TOKEN) < 24:
        sys.exit('HC_BRIDGE_TOKEN (at least 24 characters) is required')
    if HOST in ('0.0.0.0', '::', ''):
        sys.exit('refusing to listen on all interfaces: set HC_BRIDGE_HOST to the Docker gateway address')
    ThreadingHTTPServer((HOST, PORT), H).serve_forever()
