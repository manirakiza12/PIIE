# PIIE self-bind handoff: proposed installation, not executed

This replaces INSTALL-HANDOFF-CORRECTION.md and MOUNT-HANDOFF-DESIGN.md. Do not
install the earlier f170253 correction. No push, merge, install, activation,
production permission changes, database changes or reboot were performed.

## Security and compatibility

The enrolled /home/piie/deployments inode is self-bind mounted. That mountpoint
cannot be renamed by piie, even though piie owns /home/piie. Its parent home entry
is protected by root-owned /home. Root guards reject symlinks, absent mounts,
changed recorded home/deployment/base inodes and unsafe directory/record modes.
They also compare BASE's device/inode with /proc/<app-pid>/root/srv/piie: Docker's
textual Source alone is insufficient. No home ownership change is needed.

The mount is restored by piie-deployment-pin.service at boot. PIIE runtime and
scheduler units Require/After it, and every installed privileged entry point
checks directly as well. RemainAfterExit is not treated as proof the mount exists.
There is no unmount stop hook. A changed filesystem device identity after a reboot
fails closed; an administrator must investigate rather than auto-enroll a new tree.

Docker's existing restart policy must be delegated to piie-runtime.service using
`docker update --restart=no piie-app`. This preserves the same container ID,
image, entrypoint, mounts, volumes, networks and running process. It changes only
PIIE app restart policy. Without this explicit installation change, Docker could
start PIIE independently of the mount prerequisite: production remains NO-GO.
The guarded unit starts/waits on this existing container only. It orders After
Docker but never Requires/Wants, starts, stops, or restarts the shared daemon.
Mailcow, piie-db restart policy, and unrelated service units are unchanged.

The optional compose.selfbind.override.yaml records the same app-only policy for
future separately approved Compose reconciliation. Do not run compose up now.
An unguarded future administrator/Compose operation can bypass this boundary;
root operators must preserve the restart contract. Root authority is trusted.

Incoming and releases remain piie-writable CI staging. Protected application code
is rebuilt from an independently approved archive in .secure/releases/RID. The
snapshot is private, copied with no-follow regular-file checks, and checksum
verified after copying. Strict extraction checks full SHA/RID, rejects links,
traversal, secrets, special files and duplicate paths, and preserves executable
bits while removing non-root writes. Only bootstrap/cache is runtime writable.
Root stage followed by activation of the same RID verifies the same approval and
archive checksum, quarantines the non-current protected tree, and rebuilds it from
the new private snapshot. Partial/unapproved trees and existing activation backups
are refused; never overwrite those as an automatic retry.

The root-private deployment lock is shared by deployment, baseline import,
rollback, runtime startup and scheduler. Deployment takes it exclusively; scheduler
skips a minute if busy. Current is atomically switched to .secure/releases/RID.
Apache DocumentRoot stays /srv/piie/current/public. Relative environment, storage
and upload links account for the extra .secure path component. Shared .env owner,
group, mode and content are checked and never chmod/chowned. Upload-only repair
and merge use no-follow descriptors; existing files win. Storage is not repaired
or recursively chowned. Scheduler output uses journald, not root shell redirection
through home. Installation removes only the old PIIE cron entry to avoid duplication.

## Trusted f23cff8 baseline

Obtain the original independently approved production CI archive, its SHA-256 and
full 40-character commit SHA starting f23cff8. If it cannot be obtained, STOP:
RELEASE_SHA, a fresh tar of mutable live code, or a checksum invented from that tar
is not independent approval. No baseline can be automatically declared trusted.

preserve-baseline.sh privately verifies/extracts that archive, compares all live
application code bytes and executable status against it, and approves the protected
copy only on success. The comparison excludes Laravel cache/storage, .env/public
storage links, upload data, and known deployment report files. These exclusions
are runtime data, not the trusted code. A mismatch fails closed. A failed partial
protected copy is retained for review; it is never silently overwritten.

The original releases/f23cff8 and current pointer remain unchanged during import.
First authorized activation rechecks the legacy baseline code. It creates durable
upload links in candidate and protected baseline, and uses the protected baseline
for automatic recovery. A failed first activation can therefore return current to
.secure/releases/f23cff8 instead of the mutable legacy path. Explicit rollback also
requires a protected, approved target and durable uploads. It never undoes SQL.

## Preconditions and installation commands (root, after explicit approval only)

Pause concurrent PIIE deployment operations before installing scripts. Use a
root-controlled reviewed checkout; inspect `namei -l "$PWD"`. Do not run these
commands now. None of the commands below activates application code or runs PHP,
SQL, Docker Compose, a container restart, or a reboot. Mount creation and the
app-only restart-policy change still require explicit installation approval.

```sh
set -eu
REVIEWED=$PWD
BIN=/usr/local/lib/piie/bin
BASE=/home/piie/deployments/piie

# Helper source defines fixed paths/checks and sanitizes PATH, without changing state.
. "$REVIEWED/deploy/remote/deployment-guard.sh"
for dir in /usr /usr/local /usr/local/lib /usr/local/lib/piie "$BIN" /etc /etc/systemd /etc/systemd/system /var /var/lib; do
  root_directory "$dir"
done
pin_structure
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]
# Keep these fingerprints private; no .env contents are printed.
ENV_MODE=$(stat -c '%u:%g:%a' "$BASE/shared/.env")
ENV_HASH=$(sha256sum "$BASE/shared/.env")

# Root-owned installation backup, including container metadata (may contain secrets).
[ ! -L /var/lib/piie ]
if [ ! -e /var/lib/piie ]; then mkdir -m 700 /var/lib/piie; fi
root_directory /var/lib/piie
BACK=$(mktemp -d /var/lib/piie/install-backup.XXXXXXXX)
docker inspect piie-app > "$BACK/piie-app.before.json"
NEW=$(mktemp -d "$BIN/.selfbind-install.XXXXXXXX")
# Only these root-installed deployment entry points/helpers are replaced.
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

[ ! -L /etc/piie ]
if [ ! -e /etc/piie ]; then mkdir -m 700 /etc/piie; fi
root_directory /etc/piie
# Enrollment is installation-only. Never overwrite an existing record at boot.
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
systemd-analyze --man=no verify /etc/systemd/system/piie-deployment-pin.service /etc/systemd/system/piie-runtime.service /etc/systemd/system/piie-scheduler.service /etc/systemd/system/piie-scheduler.timer
systemctl daemon-reload
systemctl enable --now piie-deployment-pin.service
/bin/bash "$BIN/pin-deployments.sh" check
verify_runtime_mount

# Create only absent PIIE protected directories. Never repair an unknown tree.
for dir in "$BASE/.secure" "$BASE/.secure/releases"; do
  if [ ! -e "$dir" ] && [ ! -L "$dir" ]; then mkdir -m 755 "$dir"; fi
  root_directory "$dir"
done
if [ ! -e "$BASE/.secure/private" ] && [ ! -L "$BASE/.secure/private" ]; then mkdir -m 700 "$BASE/.secure/private"; fi
secure_layout

# Supply these three values from independently approved baseline evidence.
: "${BASELINE_ARCHIVE:?absolute incoming/piie-release-*.tar.gz path required}"
: "${BASELINE_SUM:?approved original archive SHA-256 required}"
: "${BASELINE_SHA:?approved full original commit SHA required}"
/bin/bash "$BIN/preserve-baseline.sh" "$BASELINE_ARCHIVE" "$BASELINE_SUM" "$BASELINE_SHA"
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]
[ "$(stat -c '%u:%g:%a' "$BASE/shared/.env")" = "$ENV_MODE" ]
[ "$(sha256sum "$BASE/shared/.env")" = "$ENV_HASH" ]

# Retire only the PIIE scheduler cron; keep its administrator-only backup.
if [ -e /etc/cron.d/piie-scheduler ] || [ -L /etc/cron.d/piie-scheduler ]; then
  root_file /etc/cron.d/piie-scheduler
  mv -T /etc/cron.d/piie-scheduler "$BACK/piie-scheduler.cron"
fi
# Required reboot guard: do not recreate/restart the container.
docker update --restart=no piie-app >/dev/null
docker inspect piie-app > "$BACK/piie-app.after.json"
python3 -I - "$BACK" <<'PY'
import json, pathlib, sys
folder = pathlib.Path(sys.argv[1])
before = json.loads((folder / 'piie-app.before.json').read_text())[0]
after = json.loads((folder / 'piie-app.after.json').read_text())[0]
for key in ['Id', 'Image', 'Config', 'Mounts', 'NetworkSettings']:
    assert before[key] == after[key], key
assert before['State']['Pid'] == after['State']['Pid'], 'Container restarted'
bh = dict(before['HostConfig']); ah = dict(after['HostConfig'])
bh.pop('RestartPolicy'); ah.pop('RestartPolicy')
assert bh == ah, 'Other host configuration changed'
assert after['HostConfig']['RestartPolicy']['Name'] == 'no'
PY
# Enable future boot ordering, but do not start PHP/scheduler or change current now.
systemctl enable piie-runtime.service piie-scheduler.timer
```

There is deliberately no activation command here. The installed runtime supervisor
and scheduler refuse the legacy pointer until a separately approved first protected
activation. A reboot before that cutover leaves PIIE offline, rather than starting
mutable legacy code. Plan installation and that approval together; preserve the old
cron backup during the scheduler pause. Other applications retain normal startup.
After approved protected activation, starting piie-runtime.service monitors the
already-running app without restarting it; starting the timer resumes scheduling.
Those steps are separate authorization, not part of this local task.

If any enrollment, metadata, environment or baseline check fails, STOP. Do not
re-enroll automatically, unmount a live pin, overwrite a partial baseline or restore
an unguarded restart policy as an automatic fallback. No sudoers edits are needed
or proposed; retain the existing reviewed administrator boundary. Do not grant
piie root access to the uploaded CI scripts, pin helper or general Docker commands.

## Non-destructive verification

On production after an approved installation: pin-deployments.sh check, findmnt
--mountpoint /home/piie/deployments, systemctl cat/show the four PIIE units, Docker
inspect of piie-app, private .env fingerprints and readlink current are read-only.
Do not unmount, rename ancestors, run schedule:run, stop containers or simulate
reboot on production. The adverse cases belong in isolated tests only.

Local tests use real root/unprivileged filesystems, bind mounts, chroot views and
www-data reads/writes, plus Docker mocks for HTTP/PHP/database/control-flow results.
Systemd units are validated offline. Actual production boot, Apache reload and the
original baseline artifact are not certified by synthetic tests.

Decision: local implementation is reviewable; production installation/activation
remains NO-GO until the baseline evidence and explicit mount/restart-policy/cutover
approvals are available. If app restart policy must remain unchanged, the secure
reboot contract cannot be achieved under these constraints.
