#!/usr/bin/env bash
# =============================================================================
# PIIE — read-only production inventory
# =============================================================================
# Executed over SSH by .github/workflows/piie-readonly-inventory.yml as the
# unprivileged `piie` deploy account. It must stay safe to run while the
# production deploy freeze is active.
#
# HARD RULES (enforced by review, not by the shell):
#   * no writes anywhere on the host (no mkdir/touch/chmod/chown/tee > file)
#   * no migrations, no artisan commands that boot the application
#   * no container start/stop/restart/exec/run
#   * no SQL and no database credentials
#   * no .env values, no private keys, no secret material in the output
#   * no arguments, no caller-supplied commands
#
# Every read is best-effort: a missing container or unreadable file is
# reported and the collection continues.
# =============================================================================
set -uo pipefail
export LC_ALL=C
FAILED=0

[ "$#" = 0 ] || { echo 'inventory arguments refused'; exit 1; }

BASE=/home/piie/deployments/piie

section() { printf '\n===== %s =====\n' "$*"; }
note() { printf '%s\n' "$*"; }

section "COLLECTION METADATA"
date -u +'utc=%Y-%m-%dT%H:%M:%SZ'
note 'inventory_version=1'
note 'read_only=true'

section "IDENTITY"
id || true
note "effective_user=$(id -un 2>/dev/null || echo unknown)"
getent passwd piie || note 'passwd entry for piie not found'
getent group piie || note 'group entry for piie not found'
hostname -f 2>/dev/null || hostname 2>/dev/null || true
uname -srm 2>/dev/null || true

section "SSH DAEMON CONFIG (effective values, read-only)"
if command -v sshd >/dev/null 2>&1 && sshd -T >/dev/null 2>&1; then
  sshd -T 2>/dev/null | grep -Ei '^(port|listenaddress|permitrootlogin|passwordauthentication|pubkeyauthentication|kbdinteractiveauthentication|permitemptypasswords|allowtcpforwarding|x11forwarding) ' || true
else
  grep -Ei '^[[:space:]]*(Port|ListenAddress|PermitRootLogin|PasswordAuthentication|PubkeyAuthentication|KbdInteractiveAuthentication|PermitEmptyPasswords)[[:space:]]' /etc/ssh/sshd_config 2>/dev/null \
    || note 'sshd_config not readable by this user'
fi

section "SSH HOST KEY FINGERPRINTS (public halves as stored on this host)"
for pub in /etc/ssh/ssh_host_*_key.pub; do
  [ -r "$pub" ] || continue
  printf '%s ' "$pub"
  ssh-keygen -lf "$pub" 2>/dev/null | awk '{print $1, $2, $NF}' || echo 'unreadable'
done

section "LISTENING TCP SOCKETS"
ss -ltn 2>/dev/null || netstat -ltn 2>/dev/null || note 'ss/netstat unavailable'

section "DEPLOY ACCOUNT SSH AUTHORISATIONS (public fingerprints only)"
ls -ld /home/piie /home/piie/.ssh 2>/dev/null || true
if [ -r /home/piie/.ssh/authorized_keys ]; then
  note "authorized_keys_lines=$(wc -l < /home/piie/.ssh/authorized_keys)"
  stat -c 'mode=%a owner=%U:%G size=%s' /home/piie/.ssh/authorized_keys 2>/dev/null || true
  ssh-keygen -lf /home/piie/.ssh/authorized_keys 2>/dev/null | awk '{print $1, $2, $NF}' || note 'authorized_keys has unparsable lines'
else
  note 'authorized_keys not readable by this user'
fi

section "DOCKER DAEMON"
docker version --format 'client={{.Client.Version}} server={{.Server.Version}}' 2>/dev/null || note 'docker unavailable'
docker info --format 'security_options={{json .SecurityOptions}}' 2>/dev/null || true

section "CONTAINERS"
docker ps --no-trunc --format 'name={{.Names}} image={{.Image}} status={{.Status}} ports={{.Ports}}' 2>/dev/null || true

for container in piie-app piie-db; do
  if docker inspect "$container" >/dev/null 2>&1; then
    printf '\n-- %s --\n' "$container"
    docker inspect --format 'image_id={{.Image}}' "$container" 2>/dev/null || true
    docker inspect --format 'state running={{.State.Running}} oom_killed={{.State.OOMKilled}} restarts={{.RestartCount}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container" 2>/dev/null || true
    docker inspect --format 'restart_policy={{.HostConfig.RestartPolicy.Name}} network_mode={{.HostConfig.NetworkMode}}' "$container" 2>/dev/null || true
    docker inspect --format '{{range $name, $net := .NetworkSettings.Networks}}network={{$name}} ip={{$net.IPAddress}}{{println}}{{end}}' "$container" 2>/dev/null || true
    docker inspect --format '{{range .Mounts}}mount type={{.Type}} source={{.Source}} destination={{.Destination}} rw={{.RW}}{{println}}{{end}}' "$container" 2>/dev/null || true
    # Names only: values are secrets and are never emitted.
    docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container" 2>/dev/null | cut -d= -f1 | sed 's/^/env_name=/' || true
  else
    note "container $container not found"
  fi
done

section "IMAGES"
docker image ls --digests --format 'repo={{.Repository}} tag={{.Tag}} digest={{.Digest}} id={{.ID}}' 2>/dev/null | head -n 40 || true
docker image inspect mariadb:11.4 --format 'mariadb_11_4_image_id={{.Id}}' 2>/dev/null || note 'mariadb:11.4 image not present'

section "RELEASE POINTER"
if [ -e "$BASE/current" ]; then
  readlink -f "$BASE/current" 2>/dev/null || true
  note "RELEASE_SHA=$(cat "$BASE/current/RELEASE_SHA" 2>/dev/null || echo unavailable)"
else
  note 'current missing'
  FAILED=1
fi

for release in "$BASE"/releases/*/; do
  [ -d "$release" ] || continue
  printf 'release=%s sha=%s vendor_present=%s\n' \
    "$(basename "$release")" \
    "$(cat "$release/RELEASE_SHA" 2>/dev/null || echo none)" \
    "$([ -s "$release/vendor/autoload.php" ] && echo yes || echo no)"
done

section "SHARED STATE METADATA (no values)"
stat -c 'path=%n mode=%a owner=%U:%G size=%s mtime=%y' "$BASE/shared/.env" 2>/dev/null || note 'shared/.env metadata unavailable'
if [ -r "$BASE/shared/.env" ]; then
  note 'env_variable_names:'
  sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/  \1/p' "$BASE/shared/.env" | sort -u
else
  note 'shared/.env not readable'
fi
stat -c 'path=%n mode=%a owner=%U:%G' "$BASE/shared/storage" 2>/dev/null || true
du -sb "$BASE/shared/storage" 2>/dev/null | awk '{print "shared_storage_bytes="$1}' || true

section "SCHEDULER"
# Cron command lines can contain credentials. Emit counts and metadata only.
crontab -l 2>/dev/null | awk '!/^[[:space:]]*(#|$)/ {n++} END {print "user_cron_entries=" n+0}'
ls -l /etc/cron.d/ 2>/dev/null || note '/etc/cron.d not readable'
if [ -r /etc/cron.d/piie-scheduler ]; then
  printf '/etc/cron.d/piie-scheduler mode=%s\n' "$(stat -c %a /etc/cron.d/piie-scheduler 2>/dev/null || echo unknown)"
else
  note '/etc/cron.d/piie-scheduler not readable'
fi
systemctl is-active cron 2>/dev/null || true
systemctl is-active crond 2>/dev/null || true

section "CAPACITY"
df -PB1 "$BASE" 2>/dev/null || true
df -Pi "$BASE" 2>/dev/null || true
du -sb "$BASE/current" "$BASE/releases" "$BASE/backups" 2>/dev/null || true

section "BACKUP INVENTORY (read-only verification)"
find "$BASE/backups" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort | tail -n 10
LATEST=$(find "$BASE/backups" -mindepth 2 -maxdepth 2 -type f -name SHA256SUMS -printf '%T@ %h\n' 2>/dev/null | sort -nr | sed -n '1s/^[^ ]* //p')
if [ -n "$LATEST" ]; then
  [[ "$LATEST" =~ ^$BASE/backups/[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || { note 'unrecognized backup directory'; exit 1; }
  for member in database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup SHA256SUMS; do
    [ -s "$LATEST/$member" ] && [ ! -L "$LATEST/$member" ] || { note 'backup member missing or linked'; exit 1; }
  done
  AGE=$(( $(date +%s) - $(stat -c %Y "$LATEST/SHA256SUMS") ))
  note "backup_age_seconds=$AGE"
  [ "$AGE" -ge 0 ] && [ "$AGE" -le 86400 ] || { note 'backup freshness FAILED'; FAILED=1; }
  note "latest_backup=${LATEST##*/}"
  ls -l "$LATEST" 2>/dev/null || true
  # Hash environment.backup without printing its credential-bearing contents.
  # Refuse paths outside the four files produced by container-deploy.sh.
  if [ "$(wc -l < "$LATEST/SHA256SUMS")" -eq 4 ] &&
    awk 'NF != 2 || $1 !~ /^[0-9a-f]+$/ || length($1) != 64 || $2 !~ /^(database.sql.gz|storage.tar.gz|public-uploads.tar.gz|environment.backup)$/ {bad=1} {seen[$2]++} END {exit bad || seen["database.sql.gz"] != 1 || seen["storage.tar.gz"] != 1 || seen["public-uploads.tar.gz"] != 1 || seen["environment.backup"] != 1}' "$LATEST/SHA256SUMS"; then
    ( cd "$LATEST" && sha256sum --check --strict SHA256SUMS 2>&1 ) || { note 'backup checksum verification FAILED'; FAILED=1; }
  else
    note 'backup manifest format FAILED'; FAILED=1
  fi
  if gzip -t "$LATEST/database.sql.gz" 2>/dev/null; then
    note 'database_gzip=ok'
  else
    note 'database_gzip=FAIL'
    FAILED=1
  fi
  if gzip -cd "$LATEST/database.sql.gz" 2>/dev/null | tail -n 8 | grep -q 'Dump completed'; then
    note 'database_completion_marker=ok'
  else
    note 'database_completion_marker=MISSING'
    FAILED=1
  fi
  tar -tzf "$LATEST/storage.tar.gz" >/dev/null 2>&1 && note 'storage_archive=ok' || { note 'storage_archive=FAIL'; FAILED=1; }
  tar -tzf "$LATEST/public-uploads.tar.gz" >/dev/null 2>&1 && note 'public_uploads_archive=ok' || { note 'public_uploads_archive=FAIL'; FAILED=1; }
else
  note 'no backup manifest found'
  FAILED=1
fi

section "RELEASE FILE MANIFEST (sha256; excludes vendor, storage, cache and .env)"
if [ -d "$BASE/current" ]; then
  (
    cd "$BASE/current" || exit 1
    find . -type f \
      -not -path './vendor/*' \
      -not -path './storage/*' \
      -not -path './bootstrap/cache/*' \
      -not -path './node_modules/*' \
      -not -name '.env' \
      -print0 2>/dev/null \
      | sort -z \
      | xargs -0 -r sha256sum 2>/dev/null
  )
fi

section "END"
note 'claimed_writes=0'
note "verification_exit=$FAILED"
exit "$FAILED"
