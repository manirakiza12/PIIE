#!/usr/bin/env bash
# Deliberately reject exec: model the production #137 failure, not a healthy host.
set -euo pipefail
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case "$1" in
  exec) echo 'OCI runtime exec failed: SetSSB requires API level >= 4 (effective API level: 1)' >&2; exit 128;;
  inspect)
    case "$*" in
      *'.State.Running'*) echo true;;
      *'.Mounts'*) echo "$FIXTURE_BASE:/srv/piie";;
      *'.State.Pid'*) echo 12345;;
      *'.Image'*) printf 'sha256:%064d\n' 0;;
      *'inspect piie-db --format'*) printf 'MARIADB_DATABASE=forge\nMARIADB_ROOT_PASSWORD=test-password\n';;
      *'.Config.Env'*) printf 'APP_RUNTIME_MARKER=private-fixture-value\n';;
      *) echo 'Unexpected inspect' >&2; exit 2;;
    esac;;
  cp)
    [ "$*" = 'cp -L piie-app:/etc/apache2/sites-enabled/000-default.conf -' ]
    tar -c -C "$FIXTURE_BASE" apache.conf;;
  top)
    printf 'PID COMMAND\n12345 %s\n' "${FIXTURE_INIT:-apache2}";;
  kill)
    [ "$*" = 'kill --signal=USR1 piie-app' ]
    if [ "${FAIL_RELOAD_ONCE:-0}" = 1 ] && [ ! -f "$FIXTURE_BASE/reload-failed" ]; then
      touch "$FIXTURE_BASE/reload-failed"; exit 1
    fi;;
  run)
    # No host-runtime or confinement workaround is allowed in these clients.
    [[ "$*" != *--privileged* && "$*" != *--security-opt* ]]
    if [[ "$*" != *--entrypoint* ]]; then
      # Existing database client path, not the application namespace.
      [[ "$*" == *'--network piie-stack_private --env-file'* ]]
      if [[ "$*" == *'exec mariadb-dump'* ]]; then head -c 2000 /dev/urandom; fi
      exit 0
    fi
    [ "$2" = --rm ] && [ "$3" = --pull=never ]
    [ "$4" = --volumes-from ] && [ "$5" = piie-app ]
    [ "$6" = --network ] && [ "$7" = container:piie-app ]
    [ "$8" = --env-file ] && [ -r "$9" ]
    [ "$(stat -c %a "$9")" = 600 ]
    grep -qx APP_RUNTIME_MARKER=private-fixture-value "$9"
    printf '%s\n' "$9" >> "$FIXTURE_BASE/env-paths.log"
    shift 9
    [ "$1" = --user ] && [ "$2" = www-data ]
    [ "$3" = --workdir ]
    case "$4" in /srv/piie/current|/srv/piie/releases/*) ;; *) exit 2;; esac
    [ "$5" = --entrypoint ]
    client_command=$6
    [[ "$7" =~ ^sha256:[0-9a-f]{64}$ ]]
    shift 7
    case "$client_command $*" in
      'test -r /srv/piie/shared/.env') [ "${FAIL_ENV:-0}" = 0 ];;
      'test -w /srv/piie/shared/storage') [ "${FAIL_STORAGE:-0}" = 0 ];;
      'php -r '*) [ "${FAIL_PHP:-0}" = 0 ];;
      'php artisan migrate --pretend '*) [ "${FAIL_PREVIEW:-0}" = 0 ];;
      'php artisan migrate:status') if [ "${PENDING_MIGRATIONS:-0}" = 1 ]; then echo Pending; fi;;
      'php artisan '*) exit 0;;
      'curl --fail '*) [ "${FAIL_HEALTH:-0}" = 0 ];;
      *) echo "Unexpected application client command: $client_command" >&2; exit 2;;
    esac;;
  *) echo "Unexpected Docker command: $1" >&2; exit 2;;
esac
