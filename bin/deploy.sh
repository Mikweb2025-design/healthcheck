#!/usr/bin/env bash
# Packs and installs the app into a Nextcloud AIO container over ssh. Usage: bin/deploy.sh user@host [container]
set -euo pipefail
cd "$(dirname "$0")/.."
HOST="${1:?ssh target}"; C="${2:-nextcloud-aio-nextcloud}"
TGZ="$(bin/pack.sh | tail -n1)"
scp -q "$TGZ" "$HOST:/root/hc-app.tgz"
ssh "$HOST" "set -e; docker cp /root/hc-app.tgz $C:/tmp/hc-app.tgz; rm -f /root/hc-app.tgz
docker exec $C sh -c 'cd /var/www/html/custom_apps && rm -rf healthcheck && tar xzf /tmp/hc-app.tgz && chown -R www-data:www-data healthcheck && rm /tmp/hc-app.tgz'
docker exec -u www-data $C php occ app:enable healthcheck >/dev/null && docker exec -u www-data $C php occ app:list | grep -E '^  - healthcheck'"
