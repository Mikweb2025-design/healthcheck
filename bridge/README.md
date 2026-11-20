# OpenCode bridge (optional)

Lets Health Check use the OpenCode models that only answer inside OpenCode (the free Zen models) when OpenCode is
installed on the same server as Nextcloud. Nextcloud runs in a container, OpenCode on the host: the bridge is a tiny
OpenAI-compatible HTTP service on the host that runs `opencode run` for each request.

1. On the Docker host, as root (the gateway is the one of the Nextcloud container's network:
   `docker network inspect nextcloud-aio -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}'`):

   ```bash
   HC_BRIDGE_HOST=172.18.0.1 bash bridge/install-bridge.sh
   ```

   It creates the unprivileged user `hcbridge`, a systemd service, a token in `/etc/hc-opencode-bridge.env` and
   **one firewall rule** (container network → gateway, this port only), because hosts with a default-drop INPUT policy
   block container-to-host traffic. The script prints the URL and the token.
2. Nextcloud blocks requests to local addresses. Allow them once:
   `occ config:system:set allow_local_remote_servers --type=boolean --value=true`
3. Health Check → Administration → AI: provider **OpenCode (on this server, via bridge)**, URL as printed, model
   `opencode/big-pickle` (any `opencode models` entry starting with `opencode/`), API key = the token. **Test connection**.

Privacy: the prompt goes to the OpenCode model provider. Health Check only sends aggregates, but free models may be used
by their provider to improve the model; check the terms before using real health data. Remove with
`systemctl disable --now hc-opencode-bridge`.
