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
(
  db_env=$(mktemp)
  chmod 600 "$db_env"
  trap 'rm -f "$db_env"' EXIT
  docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' |
    grep -E '^MARIADB_(DATABASE|ROOT_PASSWORD)=' > "$db_env"
  docker run --rm --network piie-stack_private --env-file "$db_env" mariadb:11.4 sh -ec \
    'command -v mariadb-dump >/dev/null; MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --connect-timeout=3 --host=piie-db --user=root -Nse "SELECT 1" | grep -qx 1'
)
echo 'Verified live Docker runtime, shared environment, storage, PHP and database backup tooling.'
