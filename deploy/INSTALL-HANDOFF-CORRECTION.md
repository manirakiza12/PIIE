> Superseded: use [SELF-BIND-INSTALL.md](SELF-BIND-INSTALL.md). Earlier commands below are historical and must not be installed.

# Installation review for f170253 correction

No production activation, push, Mailcow, sudoers, or database changes were performed.

## Preconditions (read-only inspection first)

The deployer now requires root-owned directories without group/other write access,
with no symlink components, for the entire ancestors of BASE, BASE/releases and
BASE/shared. At the current configured path this includes /home/piie and
/home/piie/deployments. Do not recursively chown shared, storage, uploads or .env.
If any ancestor is user-controlled, installation is blocked until an administrator
has separately reviewed and provisioned these directory controls. Changing a login
home's ownership can affect other workflows; do not apply a blanket recursive fix.
The incoming directory may remain piie-writable. Unprivileged stage-release.sh is
no longer the authoritative staging mechanism; root deployment rebuilds candidates.

Inspect as administrator:

```sh
namei -l /home/piie/deployments/piie/releases
namei -l /home/piie/deployments/piie/shared
namei -l /usr/local/lib/piie/bin
stat -c '%u:%g:%a %n' /home/piie/deployments/piie/shared/.env
sha256sum /home/piie/deployments/piie/shared/.env
```

All installed scripts and their ancestors must also be root-controlled. Existing
runtime storage must retain its www-data write access. The patch does not change
storage permissions. Only bootstrap/cache and shared/public-uploads receive runtime
write access. Application files are normalized to root:root 0644, directories 0755.

## Validate on a disposable Linux host/container as root

Run from a reviewed checkout on a native Linux filesystem (not a Windows mount):

```sh
bash deploy/tests/root-handoff.sh
bash deploy/tests/container-runtime.sh
```

The first suite uses real nobody/www-data identities and real filesystem controls.
The second mocks Docker infrastructure only, with real ownership and mode changes.
Tests use synthetic files and never connect to production or a database.

## Install scripts only, after preconditions are satisfied

Retain administrator-only backup copies of the existing installed deploy script.
From a root-owned reviewed checkout, with no deployer concurrently running:

```sh
install -o root -g root -m 0644 deploy/remote/secure-handoff.sh /usr/local/lib/piie/bin/secure-handoff.sh
install -o root -g root -m 0755 deploy/remote/container-deploy.sh /usr/local/lib/piie/bin/container-deploy.sh
bash -n /usr/local/lib/piie/bin/secure-handoff.sh
bash -n /usr/local/lib/piie/bin/container-deploy.sh
```

Install the helper first. Do not run activate or push as part of installation.
Use a new candidate RID and independently approved full SHA/checksum for a later,
separately authorized staging run. A currently active RID is refused. Existing
candidate directories are quarantined in a root-private .activation.* directory,
and rebuilt from the verified archive; they are never reused as trusted code.
Snapshots/quarantines are retained for inspection; monitor disk space and remove
only individually reviewed inactive snapshots later as administrator.

A passing isolated test result does not authorize production activation. Keep the
production deployment gate closed pending installation review and separate approval.
