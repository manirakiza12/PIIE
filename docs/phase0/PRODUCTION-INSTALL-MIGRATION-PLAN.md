# Production install + upload migration — procedure for review

Status: **NOT EXECUTED / AWAITING PHASE 0 PREFLIGHT**. Nothing in this document
has been run, no production file, permission, container, database, unit or release
was touched. Every command below is presented for approval. Each phase ends in a
STOP gate; do not continue past a failed gate. No production writes or deployment
are authorized until the Phase 0 production preflight results are reviewed; this
document is not a recommendation to change the live server.

Target change: merge PR #224 (`fix/production-upload-mail-persistence`,
`928157d`) and activate it **after** the protected handoff is installed on the
host. Upload repair/conversion is performed by the activation itself under the
deployment lock, with a union backup and an automatic code-pointer rollback. The
existing passing code changes are preserved; this document is documentation only.

**Execution order** (clock order; section numbers are review sections and are not
clock order): Phase 0 §3 preflight → Phase 1 §4 backup → Phase 2 §5 baseline
artifact → Phase 3 §7.1 merge + CI → Phase 4 §6 install → Phase 5 §7.2–7.3
activation → Phase 6 §9 verification → Phase 7 §9 delegation step (restart
policy, runtime unit, scheduler).

Mnemonic used later in this document: Phase 0 = §3, Phase 1 = §4, Phase 2 = §5,
Phase 3 = §7.1, Phase 4 = §6, Phase 5 = §7.2–7.3, Phase 6 = §9 verification,
Phase 7 = §9 delegation. Section numbers are intentionally non-sequential
relative to clock order; follow the phases, not the section numbers.

---

## 1. Verified facts vs. implementation requirements

| Verified fact | What the implementation requires | Status |
|---|---|---|
| Active release `/srv/piie/releases/f23cff8` | `container-preflight.sh` accepts `releases/f23cff8` | OK |
| Active SHA `f23cff88f15a35fd3d7606df929c26cd951ee288` | `EXPECTED_DEPLOYED_SHA`, `BASELINE_SHA` | OK |
| Mount `/home/piie/deployments/piie` → `/srv/piie` | `verify_runtime_mount` compares host inode with `/proc/<pid>/root/srv/piie` | OK |
| Shared uploads `root:root 700` | `set_upload_permissions` → `root:www-data 2775` dirs / `664` files | Repaired by activation |
| Current release uploads `root:root 755` | `validate_uploads` + merge into shared; legacy dir left intact | OK |
| Admissions dir `root:root 700` | recursive no-follow repair covers it | Repaired by activation |
| Both trees 188 files / ~79 MB, `diff -qr` clean | union backup, existing file wins collisions | OK |
| Release uploads is a real directory | `merge-uploads.py` folds it into shared; only the protected release dirs get the durable symlink, the legacy dir is left intact | OK |
| No self-bind mount on `/home/piie/deployments` | `deployment_guard` refuses without it | **Must be installed (Phase 3)** |
| `/usr/local/lib/piie/bin` has existing scripts | install block backs up every file it replaces, aborts on symlink/hardlink | OK |
| No PIIE systemd units | 4 units installed, `systemd-analyze verify` first | **Must be installed** |
| `piie-app` restart policy `unless-stopped` | `runtime-supervisor.sh` refuses unless `no` | **Deferred to Phase 6** |

### Blocking review findings

1. **Merging does not install anything.** The activate job calls
   `/usr/local/lib/piie/bin/runtime-deploy.sh`. Without Phase 3 it fails closed
   at `verify_runtime_mount` / `secure_layout`.
2. **Restart policy is a deliberate conflict.** `runtime-supervisor.sh` requires
   `RestartPolicy=no`; changing it during install removes Docker auto-restart
   until activation. Per the agreed decision it is **deferred to Phase 6**, after
   the first protected activation. Trade-off: during the install→activate window
   a reboot starts PIIE through `unless-stopped` without the pin (same as today,
   no regression). See §11 for the alternative.
3. **The legacy cron must not be removed during install.** `run-scheduler.sh`
   refuses a legacy `current`, and the timer is not enabled yet. Removing
   `/etc/cron.d/piie-scheduler` in Phase 3 would stop scheduling until Phase 6.
   The procedure therefore **retires cron in Phase 6**, after the timer is proven.
4. **The Mailcow network is not changed by activation, and remains UNRESOLVED.**
   `container-deploy.sh` explicitly does not recreate the container. PR #224 only
   adds `compose.production.yaml` (a future-reconciliation artefact). The live fix
   needs either a container network attach (lost on recreation) or a compose
   recreation. `compose.production.yaml` is **not a safe drop-in** for the running
   container: `app` has `build: .` (would build a new image, not the verified
   release), no `image:` pin, and no reproduction of the container's env and
   volumes. **This is a separate, blocked decision, not part of this deployment
   and not part of upload deployment.** Do not attach, recreate or reconfigure the
   Mailcow network as part of any phase in this document. Handle mail with its own
   reviewed change (see §11 item 3).
5. **Unverified prerequisites that can abort Phase 3.** `deployment-guard.sh`
   requires `/home/piie/deployments`, `/home/piie/deployments/piie` and
   `shared` to be **root-owned and not group/other-writable**
   (`root_directory`). `repair-uploads.py` / `merge-uploads.py` require a real
   **`www-data` group**. `container-preflight.sh` requires the
   `piie-deploy_private` network, the `mariadb:11.4` image and
   `.env` mode `640|600`. All are checked in Phase 0 and are STOP gates.
6. **Baseline import compares live bytes.** `preserve-baseline.sh` compares every
   live code file against the original CI archive. Any hotfix applied on the host
   makes it fail closed. That is correct, but it must be expected.

---

## 2. Variables used below

Run everything as root on the VPS. On the workstation, `gh` is already
authenticated as `benjatruevine`.

```sh
BASE=/home/piie/deployments/piie
PIN=/home/piie/deployments
BIN=/usr/local/lib/piie/bin
BASELINE_SHA=f23cff88f15a35fd3d7606df929c26cd951ee288
BASELINE_RUN=41996698933
BASELINE_ASSET=piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288
```

Replace `<MAIN_SHA>` with the full 40-character SHA of `origin/main` **after**
the PR is merged, and `<RID>` with the release id printed by the activation job.

---

## 3. Read-only preflight (no writes)

```sh
set -eu
BASE=/home/piie/deployments/piie
PIN=/home/piie/deployments

echo "== host =="
id; uname -a
df -h /home /var
free -m

echo "== required tooling =="
for c in docker python3 flock mountpoint sha256sum findmnt systemctl install; do
  command -v "$c" || { echo "STOP: missing $c"; exit 1; }
done
python3 --version
getent group www-data || { echo "STOP: www-data group missing"; exit 1; }

echo "== guard prerequisites (owner must be 0, mode must not include g+w/o+w) =="
stat -c '%U:%G %a %n' / /home /home/piie "$PIN" "$BASE" "$BASE/shared" /usr/local/lib/piie/bin
namei -l "$BASE"
namei -l /usr/local/lib/piie/bin

echo "== protected layout =="
[ -L "$BASE/current" ] || { echo "STOP: current is not a symlink"; exit 1; }
readlink "$BASE/current"
cat "$BASE/current/RELEASE_SHA"
[ "$(cat "$BASE/current/RELEASE_SHA")" = f23cff88f15a35fd3d7606df929c26cd951ee288 ] \
  || { echo "STOP: live baseline SHA changed"; exit 1; }
stat -c '%U:%G %a %n' "$BASE/shared/.env"

echo "== mount/pin state =="
mountpoint -q "$PIN" && echo "PIN=MOUNTED" || echo "PIN=NOT-MOUNTED (expected: install Phase 3)"
findmnt -T "$PIN" -o TARGET,SOURCE,FSTYPE,OPTIONS || true
stat -c '%d:%i' "$PIN" "$BASE"

echo "== docker state (read-only) =="
docker inspect -f 'app running={{.State.Running}} pid={{.State.Pid}} restart={{.HostConfig.RestartPolicy.Name}} image={{.Image}}' piie-app
docker inspect -f 'db  running={{.State.Running}} restart={{.HostConfig.RestartPolicy.Name}}' piie-db
docker inspect -f '{{range .Mounts}}{{.Type}} {{.Source}}:{{.Destination}}{{println}}{{end}}' piie-app
docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{println}}{{end}}' piie-app
docker network inspect piie-deploy_private >/dev/null && echo "NETWORK=OK"
docker image inspect mariadb:11.4 >/dev/null && echo "DB_IMAGE=OK"

PID=$(docker inspect -f '{{.State.Pid}}' piie-app)
[ "$(stat -Lc '%d:%i' "$BASE")" = "$(stat -Lc '%d:%i' "/proc/$PID/root/srv/piie")" ] \
  && echo "CONTAINER_MOUNT=OK" || { echo "STOP: container mount inode differs"; exit 1; }

echo "== upload trees (read-only) =="
LIVE=$(readlink -f "$BASE/current/public/assets/uploads")
echo "LIVE=$LIVE"
for d in "$BASE/shared/public-uploads" "$LIVE"; do
  echo "-- $d"
  stat -c '%U:%G %a' "$d"
  echo -n "symlinks=";      find "$d" -type l | wc -l
  echo -n "hardlinks=";     find "$d" -type f -links +1 | wc -l
  echo -n "special=";       find "$d" ! -type f ! -type d | wc -l
  echo -n "files=";         find "$d" -type f | wc -l
  du -sb "$d"
done
diff -qr "$BASE/releases/f23cff8/public/assets/uploads" "$BASE/shared/public-uploads" \
  || echo "UPLOAD_TREES=DIFFER (STOP and review)"

echo "== pending migrations (read-only) =="
IMG=$(docker inspect -f '{{.Image}}' piie-app)
TMP=$(mktemp); chmod 600 "$TMP"
docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' piie-app > "$TMP"
docker run --rm --pull=never --volumes-from piie-app --network container:piie-app \
  --env-file "$TMP" --user www-data --workdir /srv/piie/current \
  --entrypoint php "$IMG" artisan migrate:status
rm -f "$TMP"

echo "== mail facts (read-only; no secrets printed) =="
IMG=$(docker inspect -f '{{.Image}}' piie-app)
docker network inspect mailcowdockerized_mailcow-network \
  --format '{{range .Containers}}{{.Name}} {{end}}' || true
docker run --rm --pull=never --network container:piie-app \
  --entrypoint getent "$IMG" hosts postfix || echo "POSTFIX_DNS=FAIL"
docker run --rm --pull=never --network container:piie-app \
  --entrypoint php "$IMG" -r '$s=@fsockopen("postfix",25,$e,$m,5); echo $s?"SMTP_TCP=OK\n":"SMTP_TCP=FAIL\n";'
```

**STOP if any of these is true**

- `www-data` group missing, `python3` < 3.8, `flock`/`mountpoint` missing.
- `/home/piie/deployments`, `$BASE` or `$BASE/shared` is not `root`-owned, or its
  mode has group/other write.
- `current` is not `releases/f23cff8`, or `RELEASE_SHA` differs.
- Container mount inode differs, or `piie-app`/`piie-db` not running.
- `piie-deploy_private` or `mariadb:11.4` missing.
- Any symlink, hardlink or special file inside either upload tree.
- `diff -qr` not clean (re-record counts before continuing).
- `.env` mode is not `640` or `600`.
- **Any pending migration** → do not activate; review it first (use
  `code_only=true` only after that review).

Record the outputs. They are the pre-change evidence for Phase 5 and Phase 6.

---

## 4. Independent backup + verification of both upload trees

This backup is deliberately outside the deployment tree, so it survives a failed
install, a failed activation and a failed rollback.

```sh
set -eu
BASE=/home/piie/deployments/piie
TS=$(date -u +%Y%m%dT%H%M%SZ)
BK=/var/backups/piie-migration-$TS
install -d -m 700 -o root -g root "$BK"
LIVE=$(readlink -f "$BASE/current/public/assets/uploads")
printf '%s\n' "$LIVE" > "$BK/live.path"
printf '%s\n' "$BK"      > /root/piie-migration-backup.path

# 1. Type/owner/mode/size manifests (detect anything not a plain file/dir).
( cd "$BASE/shared/public-uploads" && find . -printf '%y %u %g %m %s %p\n' | sort ) > "$BK/shared.stat.txt"
( cd "$LIVE"                       && find . -printf '%y %u %g %m %s %p\n' | sort ) > "$BK/live.stat.txt"

# 2. Per-file checksums, relative paths, newline-delimited so `sha256sum -c` works.
( cd "$BASE/shared/public-uploads" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ) > "$BK/shared.sha256"
( cd "$LIVE"                       && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ) > "$BK/live.sha256"
# A newline in a filename would corrupt the checksum format: refuse it outright.
python3 - "$BASE/shared/public-uploads" "$LIVE" <<'PY'
import os, sys
for root in sys.argv[1:]:
    for dirpath, dirnames, filenames in os.walk(root):
        for name in dirnames + filenames:
            if "\n" in name:
                sys.exit("STOP: newline in filename: " + os.path.join(dirpath, name))
print("FILENAME_CHECK_OK")
PY

# 3. Archives (root preserves owner/mode; no links exist per Phase 0).
tar -cpzf "$BK/shared-public-uploads.tar.gz" -C "$BASE/shared" public-uploads
tar -cpzf "$BK/live-public-uploads.tar.gz"   -C "$(dirname "$LIVE")" "$(basename "$LIVE")"
gzip -t "$BK/shared-public-uploads.tar.gz"
gzip -t "$BK/live-public-uploads.tar.gz"
tar -tzf "$BK/shared-public-uploads.tar.gz" > "$BK/shared.tar.list"
tar -tzf "$BK/live-public-uploads.tar.gz"   > "$BK/live.tar.list"

# 4. Extract the shared archive and prove it equals the source before any change.
V=$(mktemp -d); trap 'rm -rf -- "$V"' EXIT
tar -xpzf "$BK/shared-public-uploads.tar.gz" -C "$V"
diff -qr "$BASE/shared/public-uploads" "$V/public-uploads"
( cd "$V/public-uploads" && sha256sum -c --quiet "$BK/shared.sha256" )
[ "$(find "$V/public-uploads" -type f | wc -l)" = "$(find "$BASE/shared/public-uploads" -type f | wc -l)" ]
rm -rf -- "$V"; trap - EXIT

# 5. Database dump (this is the only Phase-1 action that touches the DB, read-only).
DBENV=$(mktemp); chmod 600 "$DBENV"; trap 'rm -f "$DBENV"' EXIT
docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' |
  grep -E '^MARIADB_(DATABASE|ROOT_PASSWORD)=' > "$DBENV"
docker run --rm --pull=never --network piie-deploy_private --env-file "$DBENV" mariadb:11.4 sh -ec \
  'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --host=piie-db --user=root --single-transaction --routines --triggers --events --hex-blob "$MARIADB_DATABASE"' |
  gzip > "$BK/database.sql.gz"
rm -f "$DBENV"; trap - EXIT
gzip -t "$BK/database.sql.gz"
[ "$(stat -c %s "$BK/database.sql.gz")" -gt 100 ] || { echo "STOP: empty DB dump"; exit 1; }

# 5a. Isolated restore test (no production impact). Restore the dump into a
# throwaway database on a throwaway container and prove it is loadable and
# structurally complete, then drop it. This is the only way to know the backup
# is actually restorable; gzip -t only proves the stream is intact.
RESTORE_DB="piie_restore_test_$(date -u +%Y%m%d%H%M%S)"
DBENV=$(mktemp); chmod 600 "$DBENV"; trap 'rm -f "$DBENV"' EXIT
docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' |
  grep -E '^MARIADB_ROOT_PASSWORD=' > "$DBENV"
# Start an isolated MariaDB using the same image; no host port, no volume reuse.
RID=$(docker run -d --rm --name "$RESTORE_DB" --network piie-deploy_private \
  --env-file "$DBENV" -e MARIADB_DATABASE="$RESTORE_DB" mariadb:11.4)
trap 'docker rm -f "$RESTORE_DB" >/dev/null 2>&1 || true; rm -f "$DBENV"' EXIT
# Wait for readiness (bounded).
for i in $(seq 1 60); do
  docker exec "$RESTORE_DB" mariadb-admin ping --silent >/dev/null 2>&1 && break
  sleep 2
done
docker exec "$RESTORE_DB" mariadb-admin ping --silent >/dev/null 2>&1 \
  || { echo "STOP: restore DB did not become ready"; exit 1; }
# Apply the dump.
gunzip -c "$BK/database.sql.gz" | docker exec -i "$RESTORE_DB" \
  sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$RESTORE_DB"'
# Verify: table count and a basic sanity check (no empty dump, FK integrity).
TABLES=$(docker exec "$RESTORE_DB" sh -c \
  'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -N -B -uroot -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"'"$RESTORE_DB"'\";"')
echo "RESTORE_TABLE_COUNT=$TABLES"
[ "${TABLES:-0}" -gt 0 ] || { echo "STOP: restored DB has no tables"; exit 1; }
# Spot-check a few critical tables exist.
for t in users schools packages; do
  docker exec "$RESTORE_DB" sh -c \
    "MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" mariadb -N -B -uroot -e \"SELECT 1 FROM $t LIMIT 1;\"" >/dev/null 2>&1 \
    || echo "WARN: table $t missing or empty in restored DB"
done
# Tear down the isolated instance.
docker rm -f "$RESTORE_DB" >/dev/null
rm -f "$DBENV"; trap - EXIT
echo "ISOLATED_RESTORE_OK tables=$TABLES"

# Recovery limitations (documented): the dump is a single point-in-time snapshot
# taken with --single-transaction; it is NOT point-in-time / binlog recovery.
# Restoring it loses every write made after Phase 1 (Phase 1 timestamp in
# $BK). Restore is a SEPARATE APPROVED ACTION (see §10 "Database"), never
# automatic, and requires maintenance mode + lock. It does not undo code or
# upload changes; forward migrations are retained by design. Validate the
# restored dump's table count against the live DB before relying on it.

# 6. Environment fingerprint (never the contents).
stat -c '%U:%G %a' "$BASE/shared/.env" > "$BK/env.stat.txt"
sha256sum "$BASE/shared/.env"          > "$BK/env.sha256.txt"

# 7. Seal and verify.
cp "$BASE/current/RELEASE_SHA" "$BK/RELEASE_SHA.before"
( cd "$BK" && sha256sum shared-public-uploads.tar.gz live-public-uploads.tar.gz database.sql.gz \
    shared.sha256 live.sha256 shared.stat.txt live.stat.txt > SHA256SUMS && sha256sum -c SHA256SUMS )
ls -l "$BK"
```

**Optional off-host copy (workstation):**

```powershell
scp -r root@<host>:/var/backups/piie-migration-<TS> .\piie-backup-<TS>
```

**STOP if** the extract-and-compare step differs, either tree contains anything
other than regular files/dirs, or the DB dump is empty. Do not proceed to Phase 2.

---

## 5. Obtain and verify the approved f23cff8 baseline

On the workstation:

```powershell
gh run download 41996698933 --repo manirakiza12/PIIE --name piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288 --dir baseline-f23cff8
Get-Content .\baseline-f23cff8\*.sha256
scp .\baseline-f23cff8\piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz `
    .\baseline-f23cff8\piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz.sha256 `
    root@<host>:/home/piie/deployments/piie/incoming/
```

On the VPS:

```sh
set -eu
BASE=/home/piie/deployments/piie
cd "$BASE/incoming"
sha256sum -c piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz.sha256
stat -c '%U:%G %a %s %n' piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz
BASELINE_SUM=$(cut -d' ' -f1 piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz.sha256)
printf 'BASELINE_SUM=%s\n' "$BASELINE_SUM"
```

Expected artifact size 93,971,713 bytes (CI run 41996698933, not expired).
**STOP if** the checksum fails or the archive is not a regular file.

---

## 6. Install the protected handoff (root, no activation)

The code to install must come from an administrator-controlled checkout of the
merged `main` commit, not from the piie-writable `$BASE/bin` and not from
`incoming`. **Do not use `git archive`**: `.gitattributes` marks `/deploy` as
`export-ignore`, so an archive of `deploy/remote` is silently empty. Copy the
committed files and verify them with a manifest instead.

**Restart policy is not changed by this block.** The current Docker restart policy
(`unless-stopped`) is preserved through install and activation; it is changed only
in Phase 7 (§9 delegation), after the new runtime supervision is independently
verified. No `docker update --restart` occurs in Phases 0–6.

```powershell
# Workstation, after the PR is merged and CI on main is green.
git fetch origin main
git switch main
git pull --ff-only
$MAIN = (git rev-parse HEAD).Trim(); $MAIN
$MAIN -eq (git rev-parse origin/main).Trim()             # MUST be True
git status --porcelain -- deploy/remote deploy/systemd   # MUST print nothing
git rev-parse HEAD                                       # MUST equal $MAIN

$prefix = (Get-Location).Path + '\'
$lines = Get-ChildItem deploy\remote, deploy\systemd -File -Recurse | ForEach-Object {
  $rel = $_.FullName.Substring($prefix.Length) -replace '\\','/'
  '{0}  {1}' -f (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower(), $rel
}
[IO.File]::WriteAllText("$PWD\install-manifest.sha256", (($lines -join "`n") + "`n"),
  (New-Object System.Text.UTF8Encoding($false)))

ssh root@<host> "mkdir -m 700 -p /root/piie-install/deploy"
scp -r deploy\remote deploy\systemd root@<host>:/root/piie-install/deploy/
scp install-manifest.sha256          root@<host>:/root/piie-install/
```

On the VPS:

```sh
set -eu
cd /root/piie-install
sha256sum -c --quiet install-manifest.sha256
chown -R root:root /root/piie-install
[ -d deploy/remote ] && [ -d deploy/systemd ]
command -v python3 >/dev/null
namei -l /root/piie-install/deploy/remote
```

Install block (adapted from `deploy/SELF-BIND-INSTALL.md`; cron retirement and
the restart-policy change are **removed** from this block on purpose, see
findings 2 and 3 in §1):

```sh
set -eu
umask 077
REVIEWED=/root/piie-install
BIN=/usr/local/lib/piie/bin
BASE=/home/piie/deployments/piie

. "$REVIEWED/deploy/remote/deployment-guard.sh"
for dir in /usr /usr/local /usr/local/lib /usr/local/lib/piie "$BIN" \
           /etc /etc/systemd /etc/systemd/system /var /var/lib; do
  root_directory "$dir"
done
pin_structure
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]
ENV_MODE=$(stat -c '%u:%g:%a' "$BASE/shared/.env")
ENV_HASH=$(sha256sum "$BASE/shared/.env")

# Root-owned install backup, including container metadata (may contain secrets).
[ ! -L /var/lib/piie ]
[ -e /var/lib/piie ] || mkdir -m 700 /var/lib/piie
root_directory /var/lib/piie
BACK=$(mktemp -d /var/lib/piie/install-backup.XXXXXXXX)
printf '%s\n' "$BACK" > /root/piie-install-backup.path
docker inspect piie-app > "$BACK/piie-app.before.json"

# Replace only these entry points; anything else in $BIN is untouched.
NEW=$(mktemp -d "$BIN/.selfbind-install.XXXXXXXX")
FILES='deployment-guard.sh release-archive.py repair-uploads.py merge-uploads.py secure-handoff.sh pin-deployments.sh preserve-baseline.sh runtime-supervisor.sh run-scheduler.sh container-app.sh check-required-tables.sh container-preflight.sh container-rollback.sh container-deploy.sh runtime-deploy.sh'
for file in $FILES; do
  if [ -e "$BIN/$file" ] || [ -L "$BIN/$file" ]; then
    root_file "$BIN/$file"
    cp -p "$BIN/$file" "$BACK/$file"
  fi
  install -o root -g root -m 0755 "$REVIEWED/deploy/remote/$file" "$NEW/$file"
done
for file in $FILES; do mv -Tf "$NEW/$file" "$BIN/$file"; done
rmdir "$NEW"
for file in $FILES; do case "$file" in *.sh) /bin/bash -n "$BIN/$file";; esac; done

# Protected identity: installation-only enrollment, never re-enrolled at boot.
[ ! -L /etc/piie ]
[ -e /etc/piie ] || mkdir -m 700 /etc/piie
root_directory /etc/piie
if [ -e "$TRUST" ] || [ -L "$TRUST" ]; then
  check_identity
else
  /bin/bash "$BIN/pin-deployments.sh" enroll
fi

for unit in piie-deployment-pin.service piie-runtime.service piie-scheduler.service piie-scheduler.timer; do
  if [ -e "/etc/systemd/system/$unit" ] || [ -L "/etc/systemd/system/$unit" ]; then
    root_file "/etc/systemd/system/$unit"
    cp -p "/etc/systemd/system/$unit" "$BACK/$unit"
  fi
  install -o root -g root -m 0644 "$REVIEWED/deploy/systemd/$unit" "/etc/systemd/system/$unit"
done
systemd-analyze --man=no verify \
  /etc/systemd/system/piie-deployment-pin.service \
  /etc/systemd/system/piie-runtime.service \
  /etc/systemd/system/piie-scheduler.service \
  /etc/systemd/system/piie-scheduler.timer
systemctl daemon-reload
systemctl enable --now piie-deployment-pin.service
/bin/bash "$BIN/pin-deployments.sh" check
verify_runtime_mount

# Create only absent protected directories; never repair an unknown tree.
for dir in "$BASE/.secure" "$BASE/.secure/releases"; do
  if [ ! -e "$dir" ] && [ ! -L "$dir" ]; then mkdir -m 755 "$dir"; fi
  root_directory "$dir"
done
if [ ! -e "$BASE/.secure/private" ] && [ ! -L "$BASE/.secure/private" ]; then
  mkdir -m 700 "$BASE/.secure/private"
fi
secure_layout

# Import the independently approved baseline (Phase 2 artefacts).
BASELINE_ARCHIVE="$BASE/incoming/piie-release-f23cff88f15a35fd3d7606df929c26cd951ee288.tar.gz"
BASELINE_SUM=<checksum from Phase 2>
BASELINE_SHA=f23cff88f15a35fd3d7606df929c26cd951ee288
[ -f "$BASELINE_ARCHIVE" ] && [ ! -L "$BASELINE_ARCHIVE" ]
/bin/bash "$BIN/preserve-baseline.sh" "$BASELINE_ARCHIVE" "$BASELINE_SUM" "$BASELINE_SHA"

# Post-install invariants: nothing live changed.
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]
[ "$(stat -c '%u:%g:%a' "$BASE/shared/.env")" = "$ENV_MODE" ]
[ "$(sha256sum "$BASE/shared/.env")" = "$ENV_HASH" ]
[ "$(docker inspect -f '{{.State.Pid}}' piie-app)" = "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))[0]["State"]["Pid"])' "$BACK/piie-app.before.json")" ]
echo "INSTALL COMPLETE; backup=$BACK; current=$(readlink "$BASE/current")"
```

What this deliberately does **not** do: no container restart/recreate, no PHP,
no SQL migration, no `chmod`/`chown` outside the install targets, no cron change,
no restart-policy change, no `current` change, no upload change.

**STOP if** `root_directory`, `pin_structure`, `check_identity`,
`systemd-analyze verify`, `preserve-baseline.sh` ("Baseline comparison failed")
or any final invariant fails. A failed baseline leaves a partial
`.secure/releases/f23cff8` **retained for review**; do not delete or re-import
blindly.

Verify (read-only):

```sh
/bin/bash /usr/local/lib/piie/bin/pin-deployments.sh check
mountpoint -q /home/piie/deployments && echo PIN_OK
readlink /home/piie/deployments/piie/current
cat /home/piie/deployments/piie/current/RELEASE_SHA
ls -l /home/piie/deployments/piie/.secure/private
readlink /home/piie/deployments/piie/.secure/releases/f23cff8/public/assets/uploads || echo "no uploads link yet (expected)"
systemctl is-enabled piie-deployment-pin.service piie-runtime.service piie-scheduler.timer || true
test -e /etc/cron.d/piie-scheduler && echo "cron still active (expected)"
```

`piie-runtime.service` and `piie-scheduler.timer` must be **disabled** at this
point (`is-enabled` returns `disabled`). If either is enabled, PIIE could start
unguarded after a reboot before the first protected activation.

---

## 7. Merge, CI, and the single activation dispatch

### 7.1 Merge and require green CI on `main`

```powershell
gh pr checks 224 --repo manirakiza12/PIIE          # must be all pass (see §8)
gh pr merge 224 --repo manirakiza12/PIIE --squash --delete-branch
# If squash merges are disabled on the repo, use --merge instead; either way the
# gate only requires HEAD == origin/main plus a green CI run for that exact SHA.
git fetch origin main
$MAIN = (git rev-parse origin/main).Trim(); $MAIN
gh run list --repo manirakiza12/PIIE --branch main --limit 3
```

Wait for the CI run whose `headSha` equals `$MAIN` to finish successfully:

```powershell
gh run list --repo manirakiza12/PIIE --branch main --limit 5 --json databaseId,headSha,status,conclusion,workflowName
```

Both are required by the deploy gate: `HEAD == origin/main` **and** a successful
`piie-ci.yml` run for that exact SHA. If CI on `main` fails, fix it and repeat;
do not dispatch.

### 7.2 Confirm the activation inputs (read-only)

```powershell
# deployed_ref must be an ancestor of main
git merge-base --is-ancestor f23cff88f15a35fd3d7606df929c26cd951ee288 $MAIN
# rollback target must be the protected baseline ID, not the directory name
# (the workflow compares it with the active pointer's basename, which is f23cff8)
```

Inputs for this first activation:

| input | value | why |
|---|---|---|
| `mode` | `activate` | |
| `confirm` | `DEPLOY TO PRODUCTION` | gate phrase |
| `deployed_ref` | `f23cff88f15a35fd3d7606df929c26cd951ee288` | live baseline SHA |
| `rollback_release` | `f23cff8` | basename of `releases/f23cff8`; becomes `.secure/releases/f23cff8` |
| `code_only` | `true` if Phase 0 showed **zero** pending migrations, else stop | refuses instead of applying un-reviewed migrations |
| `reconcile_enrolment` | `false` | no enrolment writes requested |
| `ref` | omit | gate uses the dispatched `main` tip |

`ALLOW_DESTRUCTIVE_MIGRATIONS` stays `0` (repo variable unset → `0`). The PR adds
**no** migrations, so the migration scan has nothing to approve.

### 7.3 Dispatch

```powershell
gh workflow run piie-deploy.yml --repo manirakiza12/PIIE --ref main `
  -f mode=activate `
  -f confirm='DEPLOY TO PRODUCTION' `
  -f deployed_ref=f23cff88f15a35fd3d7606df929c26cd951ee288 `
  -f rollback_release=f23cff8 `
  -f code_only=true `
  -f reconcile_enrolment=false

gh run list --repo manirakiza12/PIIE --workflow piie-deploy.yml --limit 1
```

The `production` environment pauses the `deploy` job for a required reviewer
(`manirakiza12` or `benjatruevine`; `prevent_self_review=false`). Approve only
after Phase 3 verification is recorded. Then watch:

```powershell
gh run watch <RUN_ID> --repo manirakiza12/PIIE
```

Within the activation (`deploy/remote/container-deploy.sh`, activate mode), with
the deployment lock held, this is exactly what happens to uploads:

1. Union backup of `shared/public-uploads` and the live release uploads into
   `.secure/private/backup-<RID>/public-uploads.tar.gz`; existing shared files
   win collisions.
2. `set_upload_permissions` on the shared tree: validate no symlink/hardlink/
   special file, then `root:www-data`, dirs `2775`, files `664`, no-follow.
3. `artisan down` (maintenance mode).
4. Merge live uploads into shared, then merge the candidate's staged uploads;
   **existing files win** — nothing is overwritten or deleted.
5. `link_uploads` on `.secure/releases/<RID>` and on
   `.secure/releases/f23cff8`: rename the real `public/assets/uploads` to
   `uploads-baseline`, then
   `../../../../../shared/public-uploads`.
6. Migrations (skipped with `code_only=true`), cache clear, `.env`
   content/mode re-check, atomic `current` swap, Apache USR1 reload, `artisan up`,
   HTTP smoke on `/health / /login /careers /applicant/login` and two CSS assets.

### Strategy for uploads that change between backup and activation

Phase 1 (§4) is a point-in-time backup. Uploads created after it are not in that
backup, but they are not lost either: the activation performs its own union backup
at activation time (§7.3 step 1), which captures both the shared tree and the live
release tree exactly as they exist immediately before the merge. The Phase 1 backup
remains the pre-change baseline for rollback of pre-existing files.

Rules applied by the activation (deployment lock held):

1. **Maintenance window.** `artisan down` (§7.3 step 3) is issued before the merge,
   so no new uploads are accepted during the merge window; clients receive
   maintenance responses and retry. This bounds the change window to the pre-down
   state.
2. **Union backup at activation.** `.secure/private/backup-<RID>/public-uploads.tar.gz`
   is created from both trees; existing shared files win collisions. This is the
   authoritative post-change backup.
3. **Merge is additive only.** Live uploads and the candidate's staged uploads are
   merged into shared; existing files win — nothing is overwritten or deleted.
   Therefore uploads created between Phase 1 and activation survive.
4. **No deletion anywhere.** The legacy `releases/f23cff8/public/assets/uploads`
    stays a real directory with its 188 files as a second rollback copy. Public URLs
    (`/assets/uploads/...`) are unchanged because `current` now resolves through the
    shared symlink.
5. **Post-activation verification.** §9 re-checks that every file recorded in
   `shared.sha256` (Phase 1) is still present and unmodified (`NO_FILE_LOST`);
   extra files (new uploads) are acceptable and expected.
6. **Rollback never overwrites newer uploads.** If upload files must be restored,
   only missing files are copied back; never overwrite a newer upload
   (see §10 "Upload files need restoring").

Net effect: an upload created between backup and activation is captured by the
activation union backup, preserved through the additive merge, and remains
available after activation; it is never silently replaced by the older backup.

---

## 8. CI status (item 6)

Already verified, no failures outstanding:

| Item | Result |
|---|---|
| PR #224 CI run `42455786947` (`928157d`) | **success**, all 5 checks pass |
| PHP 8.3 / PHPUnit / MySQL | pass (10m 0s) |
| Deployment script sandbox tests | pass (47s) |
| Node / browser-logic tests | pass (27s) |
| Composer security audit | pass |
| Build and verify release artefact | pass |
| Baseline CI `41996698933` for `f23cff8` | success; artifact not expired, 93,971,713 bytes |
| Local deploy suites | 25/25 pass |
| `AdmissionWizardTest` | 19 tests, 80 assertions, 0 failures |

The deploy workflow itself re-checks CI for the exact commit before it touches
the host. After the merge, the only new CI obligation is the `main` run for
`<MAIN_SHA>`.

---

## 9. Post-change verification (read-only unless stated)

```sh
set -eu
BASE=/home/piie/deployments/piie
BK=$(cat /root/piie-migration-backup.path)

readlink "$BASE/current"                              # .secure/releases/<RID>
cat "$BASE/current/RELEASE_SHA"                       # <MAIN_SHA>
readlink "$BASE/current/public/assets/uploads"        # ../../../../../shared/public-uploads
readlink -f "$BASE/current/public/assets/uploads"     # $BASE/shared/public-uploads
readlink "$BASE/.secure/releases/f23cff8/public/assets/uploads"

stat -c '%U:%G %a' "$BASE/shared/public-uploads"               # root:www-data 2775
find "$BASE/shared/public-uploads" -type d -printf '%m\n' | sort -u   # 2775
find "$BASE/shared/public-uploads" -type f -printf '%m\n' | sort -u   # 664 (before new uploads)
find "$BASE/shared/public-uploads" \( -type l -o -type f -links +1 -o ! -type f ! -type d \) -print  # empty

echo -n "files after: "; find "$BASE/shared/public-uploads" -type f | wc -l   # >= 188
echo -n "files before: "; grep -c '^' "$BK/shared.sha256"

# No pre-change file lost or altered (extra new user uploads are acceptable).
( cd "$BASE/shared/public-uploads" && sha256sum -c --quiet "$BK/shared.sha256" ) && echo "NO_FILE_LOST"

# The legacy copy is untouched (a diff after new uploads legitimately shows extras).
diff -qr "$BASE/releases/f23cff8/public/assets/uploads" "$BASE/shared/public-uploads" \
  || echo "LEGACY_DIFFERS (expected only if new uploads arrived)"
stat -c '%U:%G %a' "$BASE/shared/.env"     # unchanged from Phase 0

# Public delivery and URL stability (pick a real file from the tree).
F=$(cd "$BASE/shared/public-uploads" && find . -type f | head -n1 | sed 's|^\./||')
echo "probe=$F"
curl -fsS -o /dev/null -w '%{http_code}\n' "https://piie.ac.ug/assets/uploads/$F"
curl -fsS https://piie.ac.ug/health; echo
```

Application-level write test as `www-data` (creates and removes one probe file):

```sh
IMG=$(docker inspect -f '{{.Image}}' piie-app)
TMP=$(mktemp); chmod 600 "$TMP"
docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' piie-app > "$TMP"
docker run --rm --pull=never --volumes-from piie-app --network container:piie-app \
  --env-file "$TMP" --user www-data --entrypoint sh "$IMG" -c \
  'set -e; d=/srv/piie/shared/public-uploads; test -w "$d"; : > "$d/.piie-write-probe"; rm -f "$d/.piie-write-probe"; echo WWW_DATA_WRITE_OK'
rm -f "$TMP"
```

Then one manual acceptance test: log in as an applicant, upload a document and a
profile photo, confirm HTTP 200 and that the new file appears under
`shared/public-uploads`, readable by Apache. New files are written by `www-data`
(owner/group `www-data`, typically `644`); the pre-existing 188 files are the ones
normalized to `root:www-data 664` by the repair. This is the actual regression
being fixed (the admissions directory was `root:root 700`).

The workflow's own post-activation steps additionally verify: `RELEASE_SHA`,
`readlink current`, public asset check, `academic-editor.js` byte compare, and
`course-content:audit`.

### Separately approved: delegate restart/scheduling, then retire the old cron

Only after §9 verification passes. This is the separately approved
restart-policy change.

```sh
set -eu
umask 077
BASE=/home/piie/deployments/piie
BACK=$(cat /root/piie-install-backup.path)
. /usr/local/lib/piie/bin/deployment-guard.sh

# 1. Restart policy: app-only, no container restart.
docker inspect piie-app > "$BACK/piie-app.after.json"
docker update --restart=no piie-app >/dev/null
docker inspect piie-app > "$BACK/piie-app.final.json"
python3 -I - "$BACK" <<'PY'
import json, pathlib, sys
f = pathlib.Path(sys.argv[1])
before = json.loads((f / 'piie-app.before.json').read_text())[0]
after  = json.loads((f / 'piie-app.final.json').read_text())[0]
for key in ['Id', 'Image', 'Config', 'Mounts', 'NetworkSettings']:
    assert before[key] == after[key], key
assert before['State']['Pid'] == after['State']['Pid'], 'Container restarted'
bh = dict(before['HostConfig']); ah = dict(after['HostConfig'])
bh.pop('RestartPolicy'); ah.pop('RestartPolicy')
assert bh == ah, 'Other host configuration changed'
assert after['HostConfig']['RestartPolicy']['Name'] == 'no'
PY

# 2. Guarded runtime monitor (waits on the existing container; never recreates it).
systemctl enable --now piie-runtime.service
systemctl is-active piie-runtime.service
journalctl -u piie-runtime.service -n 20 --no-pager

# 3. Scheduler timer: prove one run before removing cron.
systemctl enable --now piie-scheduler.timer
systemctl start piie-scheduler.service
journalctl -u piie-scheduler.service -n 20 --no-pager

# 4. Retire only the PIIE cron, with an administrator-only backup.
if [ -e /etc/cron.d/piie-scheduler ] || [ -L /etc/cron.d/piie-scheduler ]; then
  root_file /etc/cron.d/piie-scheduler
  mv -T /etc/cron.d/piie-scheduler "$BACK/piie-scheduler.cron"
fi
ls /etc/cron.d
```

**STOP if** the metadata assertion fails (a concurrent mutation happened — do not
retry, investigate), the runtime unit is not active, or the scheduler journal shows
an error.

---

## 10. Rollback

Every step below is explicit; nothing is automatic except the activation's own
code-pointer recovery.

| Point of failure | Action |
|---|---|
| Phase 0/1/2 (read-only or backup) | Delete `$BK` only after investigation. No production change was made. |
| Phase 3, before `preserve-baseline.sh` | Restore replaced scripts/units from `$BACK`, `systemctl daemon-reload`, `systemctl disable piie-deployment-pin.service` **without unmounting** the pin, leave `/etc/piie` intact. `current`, uploads, DB unchanged. |
| Phase 3, `preserve-baseline.sh` fails | STOP. The partial `.secure/releases/f23cff8` is retained for review. To abandon: `mv "$BASE/.secure/releases/f23cff8" "$BACK/failed-baseline-f23cff8"` (never silent delete) and repeat the install block after review. |
| Activation fails **before** the pointer swap | Nothing live changed; the trap reloads nothing. `current` remains `releases/f23cff8`. Restore app restart policy if it was already `no`: `docker update --restart=unless-stopped piie-app`. |
| Activation fails **after** the pointer swap | `container-deploy.sh`'s trap switches `current` to `.secure/releases/f23cff8` and reloads Apache. Database forward migrations are retained by design. Re-run Phase 5 checks. |
| Post-activation workflow step fails | The workflow runs `container-rollback.sh <SHA> f23cff8 f23cff88…` automatically (`failure()` + `install` succeeded). |
| Manual code rollback | `sudo /usr/local/lib/piie/bin/container-rollback.sh "$(cat $BASE/current/RELEASE_SHA)" f23cff8 f23cff88f15a35fd3d7606df929c26cd951ee288` |
| Upload files need restoring | Merge never deletes. Restore only missing files, never overwrite newer uploads: `tar -xpzf "$BK/shared-public-uploads.tar.gz" -C /tmp/restore` then copy **only** paths absent from the live tree; re-run `/usr/local/lib/piie/bin/repair-uploads.py "$BASE/shared/public-uploads"`. |
| Full upload revert (last resort) | With the deployment lock held and the app in maintenance, restore `public-uploads` from `$BK/shared-public-uploads.tar.gz`, then `repair-uploads.py`. URL layout is unchanged either way. |
| Database | No automatic undo. `database.sql.gz` (verified by an isolated restore
test in Phase 1, §4) restore is a **separate approved action** and loses every
write made after the Phase 1 backup timestamp; it does not undo code or upload
changes and forward migrations are retained by design. Not point-in-time /
binlog recovery. Requires maintenance mode + deployment lock. |
| Abandon the protected model entirely | `docker update --restart=unless-stopped piie-app`, `systemctl disable --now piie-runtime.service piie-scheduler.timer`, restore `/etc/cron.d/piie-scheduler` from `$BACK`, restore scripts/units from `$BACK`, `systemd daemon-reload`. Leave the pin mount mounted; unmounting a live path is refused by design. |

### Rollback validation against the protected release model

The manual code rollback (`container-rollback.sh`) and the activation's automatic
code-pointer recovery operate only within the protected release model. Before
relying on them, confirm all of the following; if any is false, stop and
investigate rather than dispatching:

- **Protected baseline exists.** `.secure/releases/f23cff8` is present and
  verified (Phase 3 `preserve-baseline.sh`), with `RELEASE_SHA` equal to the
  full 40-character baseline SHA; the protected baseline directory is a real
  directory, not a symlink, and its `public/assets/uploads` is the baseline copy.
- **Pin is mounted and valid.** `/home/piie/deployments` is a mountpoint
  (`pin-deployments.sh check` passes); host inode matches the container's
  `/proc/<pid>/root/srv/piie` (`verify_runtime_mount`).
- **Deployment lock is obtainable.** `flock` on the protected lock succeeds; no
  concurrent activation/rollback is running.
- **Current pointer is unambiguous.** `$BASE/current` is a relative symlink to
  either `releases/f23cff8` or `.secure/releases/<RID>`; its `RELEASE_SHA`
  matches the expected current full SHA. Ambiguous SSH disconnects or cancelled
  jobs require manual read-only reconciliation first.
- **Rollback target is validated.** `rollback_release` (e.g. `f23cff8`) and
  `deployed_ref` (full baseline SHA) are both required and checked against the
  active pointer's basename; `container-rollback.sh <current-full-SHA>
  <target-release-ID> <target-full-SHA>` switches only the `current` link and
  reloads only `piie-app`.
- **No side effects.** Code rollback runs no SQL, no migrations, no enrolment
  reconciliation, no host PHP, no Mailcow commands and no database restore. It
  does not touch shared uploads or `.env`. Database forward migrations are
  retained by design; backward schema compatibility must be established in the
  recovery clone before fallback is approved.
- **Failure handling.** If target reload/health fails after the switch, the
  starting pointer is restored and `piie-app` reloaded; if that recovery also
  fails, a CRITICAL result is emitted requiring administrator intervention (not
  a successful rollback).
- **Restart policy unchanged until Phase 7.** Rollback does not alter the Docker
  restart policy; if the app was already `no`, restore `unless-stopped` per the
  "Activation fails before the pointer swap" row.

The protected release model is the only supported rollback path. Abandoning it
(the "Abandon the protected model entirely" row) is a separate, destructive
decision requiring its own approval.

There is no `chmod 777`, no blanket recursive `chown`, and no broad `find -exec`
anywhere in the path: recursive changes go through the no-follow Python helpers and
fail closed.

---

## 11. Open decisions before executing

1. **Ownership facts** from Phase 0 for `/home/piie/deployments`, `$BASE` and
   `shared`. If any is piie-owned or group-writable, the guard aborts. A
   non-recursive ownership/mode correction of those three directories would be a
   prerequisite with its own approval; `shared/storage` stays www-data-writable.
2. **Restart-policy timing.** Recommended and documented above: defer to Phase 6
   (keeps Docker auto-restart during the window; no boot-time outage risk).
   Alternative (SELF-BIND strict order): change to `no` and enable the runtime
   unit in Phase 3 — then a reboot before activation leaves PIIE **offline** by
   design. Pick one before Phase 6.
3. **Mailcow network (separate change, EXPLICITLY UNRESOLVED).** Activation
   cannot attach the network and does not attempt to. This item is **not** part of
   upload deployment and is not resolved by any phase in this document. Recommended:
   keep this deployment focused on uploads and the handoff; handle mail with its
   own reviewed change that either (a) pins the running image (`image:` instead of
   `build:`), reproduces the container env/volumes and uses the real compose
   project, or (b) documents an explicit `docker network connect piie-app
   mailcowdockerized_mailcow-network` with the known "lost on recreation" caveat.
   Verify `global_settings` SMTP rows first — they override `MAIL_*`. Until that
   separate change is approved, Mailcow network persistence remains unresolved.
4. **`code_only`.** Recommend `true` when Phase 0 shows zero pending migrations.

---

## 12. Approval checklist

- [ ] Phase 0 outputs recorded; every STOP gate clean
- [ ] Phase 1 backup sealed, verified, optionally copied off-host
- [ ] Phase 2 baseline artifact checksum verified on the host
- [ ] PR #224 merged; CI on `<MAIN_SHA>` green
- [ ] Phase 3 install completed; `current` still `releases/f23cff8`; `.env`
      fingerprint unchanged; runtime/timer units **disabled**
- [ ] Explicit approval to activate
- [ ] Activation dispatched with the exact inputs in §7.2
- [ ] Production-environment reviewer approved
- [ ] Phase 5 verification recorded (counts, checksums, modes, probe, upload)
- [ ] Phase 6 restart-policy change + runtime/timer verified, cron retired
