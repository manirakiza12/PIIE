#!/usr/bin/env bash
set -euo pipefail
BASE=/home/piie/deployments/piie
[ "$(id -u)" = 0 ] || { echo 'Container deployment requires the verified VPS administrator.'; exit 1; }
command -v docker >/dev/null
[ "$(docker inspect -f '{{.State.Running}}' piie-app)" = true ]
[ "$(docker inspect -f '{{.State.Running}}' piie-db)" = true ]
docker inspect -f '{{range .Mounts}}{{.Source}}:{{.Destination}}{{println}}{{end}}' piie-app | grep -Fx "$BASE:/srv/piie"
case "$(readlink "$BASE/current")" in releases/*) ;; *) echo 'Current must be a relative release link'; exit 1;; esac
[ -s "$BASE/current/RELEASE_SHA" ]
[ -s "$BASE/shared/.env" ]
[ -d "$BASE/shared/storage" ]
[ "$(stat -c %a "$BASE/shared/.env")" = 640 ] || [ "$(stat -c %a "$BASE/shared/.env")" = 600 ]
docker exec --user www-data piie-app test -r /srv/piie/shared/.env
docker exec --user www-data piie-app test -w /srv/piie/shared/storage
docker exec piie-app php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("pdo_mysql") ? 0 : 1);'
docker exec piie-app grep -F 'DocumentRoot /srv/piie/current/public' /etc/apache2/sites-enabled/000-default.conf
docker exec piie-db sh -c 'command -v mariadb-dump >/dev/null && test -n "$MARIADB_DATABASE" && test -n "$MARIADB_ROOT_PASSWORD"'
echo 'Verified live Docker runtime, shared environment, storage, PHP and database backup tooling.'
