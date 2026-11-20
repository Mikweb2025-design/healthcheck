#!/usr/bin/env bash
# Installs the OpenCode bridge as a systemd service on the Docker host. Run as root on the server:
#   HC_BRIDGE_HOST=<docker gateway, e.g. 172.18.0.1> bash install-bridge.sh
# Prints the URL and the token to paste into Health Check → Administration. See bridge/README.md.
set -euo pipefail
HOST="${HC_BRIDGE_HOST:?set HC_BRIDGE_HOST to the Docker gateway address (docker network inspect <net>)}"
PORT="${HC_BRIDGE_PORT:-8788}"
DIR="$(cd "$(dirname "$0")" && pwd)"
id hcbridge >/dev/null 2>&1 || useradd --system --home-dir /var/lib/hcbridge --create-home --shell /usr/sbin/nologin hcbridge
mkdir -p /var/lib/hcbridge/cfg /var/lib/hcbridge/work /opt/hc-opencode-bridge
cat > /var/lib/hcbridge/cfg/opencode.json <<'J'
{"$schema":"https://opencode.ai/config.json","permission":{"edit":"deny","bash":"deny","webfetch":"deny"},"mcp":{},"share":"disabled","autoupdate":false}
J
chown -R hcbridge: /var/lib/hcbridge
install -m 0755 "$DIR/opencode-bridge.py" /opt/hc-opencode-bridge/opencode-bridge.py
[ -f /etc/hc-opencode-bridge.env ] || { umask 077; printf 'HC_BRIDGE_TOKEN=%s\n' "$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40)" > /etc/hc-opencode-bridge.env; }
chmod 600 /etc/hc-opencode-bridge.env
cat > /etc/systemd/system/hc-opencode-bridge.service <<U
[Unit]
Description=Health Check OpenCode bridge
After=network-online.target docker.service

[Service]
User=hcbridge
EnvironmentFile=/etc/hc-opencode-bridge.env
Environment=HC_BRIDGE_HOST=$HOST HC_BRIDGE_PORT=$PORT
ExecStart=/usr/bin/python3 /opt/hc-opencode-bridge/opencode-bridge.py
Restart=on-failure
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/hcbridge
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
U
systemctl daemon-reload && systemctl enable --now hc-opencode-bridge
sleep 1; systemctl is-active hc-opencode-bridge
echo "URL:   http://$HOST:$PORT/v1/chat/completions"
echo "Model: opencode/big-pickle   (any opencode/* model from 'opencode models')"
echo "Token: $(sed -n 's/^HC_BRIDGE_TOKEN=//p' /etc/hc-opencode-bridge.env)"
