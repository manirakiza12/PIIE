#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
# Shared application client for deploy, preflight and cron. Never exec into the
# long-running container: some hosts can create clients but reject exec/setns.
set -euo pipefail

app_run() (
  # This function already has a subshell scope. Keep the cleanup path in that
  # scope: Bash can unwind function-local variables before an EXIT trap on error.
  workdir=${1:?application working directory}
  command=${2:?command}
  shift 2
  [[ "$workdir" = /srv/piie/current || "$workdir" =~ ^/srv/piie/\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || { echo 'Invalid application working directory' >&2; exit 1; }
  source "$(dirname "${BASH_SOURCE[0]}")/deployment-guard.sh"
  verify_runtime_mount
  image=$(docker inspect --format '{{.Image}}' piie-app) || exit 1
  [[ "$image" =~ ^sha256:[0-9a-f]{64}$ ]] || { echo 'Invalid application image ID' >&2; exit 1; }
  env_dir=$(mktemp -d) || exit 1
  trap 'rm -rf -- "$env_dir"' EXIT
  chmod 700 "$env_dir" || exit 1
  # Preserve container-level overrides in addition to Laravel's shared .env.
  # Only the private file, never stdout or command arguments, carries secrets.
  (umask 077; docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' piie-app > "$env_dir/app.env") || exit 1
  docker run --rm --pull=never --volumes-from piie-app --network container:piie-app \
    --env-file "$env_dir/app.env" --user www-data --workdir "$workdir" \
    --entrypoint "$command" "$image" "$@"
)

app_apache_master() {
  local pid
  source "$(dirname "${BASH_SOURCE[0]}")/deployment-guard.sh"
  verify_runtime_mount
  pid=$(docker inspect --format '{{.State.Pid}}' piie-app)
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 1
  # A container signal is safe only when its init is the Apache parent itself.
  docker top piie-app -eo pid,comm | awk -v pid="$pid" '$1 == pid && $2 == "apache2" {found=1} END {exit !found}'
}

app_apache_reload() {
  app_apache_master || { echo 'Refusing graceful signal: piie-app init is not Apache' >&2; return 1; }
  docker kill --signal=USR1 piie-app >/dev/null
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  app_run "$@"
fi
