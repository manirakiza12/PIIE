> Superseded: use [SELF-BIND-INSTALL.md](SELF-BIND-INSTALL.md). Earlier commands below are historical and must not be installed.

# PIIE mount-compatible handoff: final investigation

Decision: NO-GO for installing output/f170253-correction.patch or activating it.
This report supersedes its installation instructions. No production deployment
implementation is added by this investigation; the new patch is a layout proof test
and this report. No push, merge, production access, permission changes or activation
were performed.

## Finding

A root-owned .secure subtree under the existing root-owned deployment base would
be visible as /srv/piie/.secure through the existing Docker bind mount. But piie
owns /home/piie and can rename its root-owned child deployments. The running
container retains its original mounted inode; host /home/piie/... lookups can then
resolve a different tree. Root-owned descendants alone cannot stop that attack.
Protecting only .secure without fixing host path resolution is unsafe.

The existing correction also requires root ownership of releases, breaking CI
staging, and root ownership of home, exceeding the desired ownership scope.

## Real isolated tests

Run as root on a disposable Linux system:

    bash deploy/tests/mount-handoff-layout.sh

The script creates a private mount namespace and synthetic paths under /var/lib,
uses nobody as the unprivileged piie surrogate and www-data for runtime writes,
and performs real rename, bind mount, flock and permission operations. It does
not use Docker, a database, production paths, or production data. Ten checks
pass; see output/mount-handoff-layout-results.txt.

It proves ordinary path redirection, stable bind alias behavior, private artifact
copy/checksum protection, code write denial, cache writes, pointer/lock controls,
container-like path visibility, environment preservation, pointer rollback,
continued unprivileged staging, and rename prevention by a self-bind on the
root-owned deployments directory. The artifact is synthetic text; this test does
not validate production tar handling, full release identity, Apache reload, Docker
restart behavior, boot ordering, or atomic application activation.

## Minimal alternatives requiring separate approval

A. Add a root-owned self-bind mount on /home/piie/deployments. As a mountpoint it
cannot be renamed by piie. The /home/piie entry itself has a root-controlled parent
/home, which must be verified. No home ownership change is required. The pin must
be present before any privileged use of this path, and restored securely before
PIIE starts after reboot. Do not alter Mailcow or the Docker daemon to implement
this requirement; establish a PIIE-scoped lifecycle plan first.

B. Add a root-controlled bind alias /var/lib/piie/runtime pointing at the exact
inode already mounted in piie-app. All privileged host code, pointers, backups,
locks, scheduler host log paths and rollback operations must use that alias.
An alias protects a running deployment from ancestor replacement, but by itself
does not protect Docker's configured original source path at a future restart.
A PIIE-specific source-integrity/startup guard or alternative source protection is
therefore still required. Do not silently rebind an alias to a different inode.

Neither alternative changes the existing container bind mount. Both add a host
mount. The self-bind is the smaller path change; neither has been installed.

## Proposed root prerequisite commands: NOT an installation of the old patch

These commands are proposals for alternative A only, after explicit approval,
using a root-controlled reviewed shell environment. Run without concurrent PIIE
operations. Stop on any failed check. The commands change no ownership or data,
but the mount command DOES add a host mount. They are not authorized to run now.

```sh
set -eu
BASE=/home/piie/deployments/piie
PIN=/home/piie/deployments

# Read-only checks first: no symlink components, root ownership/no group or other
# write on /home, PIN and BASE; HOME itself remains piie:piie 750.
namei -l "$BASE"
[ ! -L /home ] && [ ! -L /home/piie ] && [ ! -L "$PIN" ] && [ ! -L "$BASE" ]
[ "$(stat -c %u /home)" = 0 ]
HOME_PARENT_MODE=$(stat -c %a /home)
[ $(( 0$HOME_PARENT_MODE & 0022 )) -eq 0 ]
stat -c '%U:%G %a %n' /home /home/piie "$PIN" "$BASE"
PID=$(docker inspect --format '{{.State.Pid}}' piie-app)
case "$PID" in ''|*[!0-9]*|0) exit 1;; esac
[ "$(docker inspect --format '{{.Config.Image}}' piie-app)" = piie-deploy-app ]
[ "$(stat -Lc '%d:%i' "$BASE")" = \
  "$(stat -Lc '%d:%i' "/proc/$PID/root/srv/piie")" ]
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]
stat -c '%u:%g:%a' "$BASE/shared/.env"
sha256sum "$BASE/shared/.env"  # retain checksum privately; never print its content

# New host mount, only if separately approved and PIN is not already a mountpoint.
! mountpoint -q "$PIN"
mount --no-canonicalize --bind "$PIN" "$PIN"
mountpoint -q "$PIN"

# Verify again AFTER pinning; trust no prior pathname check against a rename race.
namei -l "$BASE"
[ ! -L /home/piie ] && [ ! -L "$PIN" ] && [ ! -L "$BASE" ]
[ "$(docker inspect --format '{{.State.Pid}}' piie-app)" = "$PID" ]
[ "$(stat -Lc '%d:%i' "$BASE")" = \
  "$(stat -Lc '%d:%i' "/proc/$PID/root/srv/piie")" ]
[ "$(stat -c '%u:%g:%a' "$PIN")" = 0:0:755 ]
[ "$(stat -c '%u:%g:%a' "$BASE")" = 0:0:755 ]
[ "$(readlink "$BASE/current")" = releases/f23cff8 ]

# Check .secure is absent, including dangling links. Do not fix an existing path
# with recursive chown. mkdir deliberately refuses any existing object.
[ ! -e "$BASE/.secure" ] && [ ! -L "$BASE/.secure" ]
mkdir -m 755 "$BASE/.secure"
mkdir -m 700 "$BASE/.secure/private"
mkdir -m 755 "$BASE/.secure/releases"
```

No deploy scripts should be installed yet. If verification fails after the mount,
stop; do not deploy, select another tree, or automatically unmount it. Have the
administrator investigate the pinned inode and running container first.

## Required implementation after prerequisite/lifecycle approval

- Host input names stay under incoming; releases remains piie:root 755 for CI.
- Require the verified ancestor pin, no symlink components, and root-controlled
  home parent, deployment base and .secure. Do not require root ownership of home
  or releases. Verify BASE inode equals the running container mount, not merely
  Docker's textual Source field. Fail closed if the pin is absent or inconsistent.
- Copy archive to a unique .secure/private directory, verify approved checksum
  after copying, reject unsafe tar entries, and extract only from that snapshot.
- Build code under .secure/releases/RID, verify the full approved SHA and RID,
  preserve required executable bits, remove non-root code write access, and grant
  runtime write access only to cache. Never import untrusted CI-staged code.
- Existing Apache DocumentRoot /srv/piie/current/public stays unchanged. Switch
  current atomically to .secure/releases/RID only during separately approved
  activation. Application clients and table-check helpers need the new working
  directory allowlist. Relative .env/storage/upload links need one extra parent
  traversal compared with legacy releases/RID; test resolved paths explicitly.
- Keep the root-owned deployment lock and atomic current-pointer operations on
  the pinned base. Reject unsafe preexisting lock/temp paths and retained writable
  files; use the same lock in deployment and rollback. Protect lock creation and
  ownership, not just its pathname.
- Scheduler stays on /srv/piie/current. Its installed wrapper is root-controlled;
  host log redirection must use a protected path, after the pin check, or a
  root-controlled /var/log path. Do not keep unguarded host redirection through a
  replaceable ancestor path. Do not run cron during this review.
- Keep releases/f23cff8 intact. Its piie-writable releases parent means it is not
  an immutable rollback target. Before changing current, establish and verify a
  protected rollback copy using independently approved baseline evidence, or
  explicitly retain the legacy target only as an untrusted recovery option.
  A matching RELEASE_SHA file alone is not code attestation. Both automatic
  recovery and explicit rollback need tests for the new protected pointer form.
- Preserve shared/.env owner/group/mode/content exactly, never recurse over shared,
  and limit upload changes to shared/public-uploads. Preserve storage and uploads.
- Add production-layout activation/rollback and restart/missing-pin tests. Existing
  passing root tests do not replace those new integration checks.

The approval gate remains closed until the prerequisite is accepted, lifecycle
behavior is established, and the integrated deployment/rollback patch passes.
