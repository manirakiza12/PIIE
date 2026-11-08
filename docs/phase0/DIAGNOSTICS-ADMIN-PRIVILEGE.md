# Diagnostics administrator privilege correction (reviewed, not executed)

Status: **NOT EXECUTED**. No VPS, sudoers, container, database, or deployment
state was changed. This document records the reviewed correction for the
`PIIE Production Diagnostics` workflow.

## Root cause

`deploy/diagnostics/production-readiness.sh` begins with:

```sh
[ "$(id -u)" = 0 ] || fail 'Verified VPS administrator required'
```

The workflow executed it as the unprivileged SSH user (`PIIE_SSH_USER`, the `piie`
deploy account) via `bash --noprofile --norc -s` over stdin, so the UID check
failed. The SSH key, host pin, and read-only behavior were never the problem.

## Security audit of production-readiness.sh

Repository-level (verified from source):

- Read-only: the only filesystem write is a `mktemp` temp file in `/tmp` (mode
  600, removed by trap). No production file, permission, service, or container is
  modified. Database access uses `START TRANSACTION READ ONLY` and SELECT only.
  Docker runs use `--read-only --cap-drop=ALL --security-opt no-new-privileges`.
  `systemctl is-active` is a status query only.
- Ephemeral Docker operations use read-only host mounts
  (`--mount .../srv/piie,readonly`) and a read-only root filesystem; they do not
  mount any writable volume and do not touch Mailcow (no Mailcow image, network,
  or container is referenced).
- The script takes no arguments (`$# = 0` guard) and uses absolute paths; it does
  not depend on the working directory. `DIAG_BASELINE`/`DIAG_TARGET`/
  `DIAG_ROLLBACK` are hardcoded in the script, not externally injected.
- External dependencies: bash, id, readlink, cat, date, df, du, awk, find, stat,
  docker, curl, php (in-container), python3, mariadb:11.4 image, grep, sort, sed,
  wc, tail, mktemp, chmod, trap, systemctl, sha256sum, gzip, tar. All are
  system binaries resolved through sudo's `secure_path`.

Live VPS verification (NOT performed here; requires server evidence):

- Ownership and modes of `/usr`, `/usr/local`, `/usr/local/lib`,
  `/usr/local/lib/piie`, `/usr/local/lib/piie/bin`, and the installed script.
- Sudoers configuration and effective sudo policy for the `piie` account.
- Parent-directory write permissions and mount state.

## Proposed correction

Install `production-readiness.sh` as a root-owned, non-writable entry point and
invoke it with `sudo -n` (exact path, no arguments). This keeps the root UID
gate as defense in depth and avoids root SSH or broad sudo.

Proposed workflow change (see the diff in this PR):

```sh
ssh ... 'sudo -n /usr/local/lib/piie/bin/production-readiness.sh'
```

Proposed sudoers drop-in (root-owned, mode 0440), e.g.
`/etc/sudoers.d/piie-diagnostics`:

```
piie ALL=(root) NOPASSWD: /usr/local/lib/piie/bin/production-readiness.sh ""
```

The trailing `""` forbids arguments. The script remains mode 0555, root:root, so
the `piie` account and the GitHub workflow cannot replace it.

## VPS installation procedure (console, root, after PR merge)

```sh
install -o root -g root -m 0555 /path/to/reviewed/production-readiness.sh \
  /usr/local/lib/piie/bin/production-readiness.sh
chown root:root /usr/local/lib/piie/bin/production-readiness.sh
chmod 0555 /usr/local/lib/piie/bin/production-readiness.sh
printf '%s\n' 'piie ALL=(root) NOPASSWD: /usr/local/lib/piie/bin/production-readiness.sh ""' \
  > /etc/sudoers.d/piie-diagnostics
chown root:root /etc/sudoers.d/piie-diagnostics
chmod 0440 /etc/sudoers.d/piie-diagnostics
visudo -cf /etc/sudoers.d/piie-diagnostics
```

## Verification steps

```sh
sudo -l -U piie
sudo -u piie sudo -n /usr/local/lib/piie/bin/production-readiness.sh
sudo -u piie /usr/local/lib/piie/bin/production-readiness.sh
```

The first must show only the allowed command; the second must pass the UID gate and
reach the release checks; the third must fail with "Verified VPS administrator
required".

## Rollback procedure

```sh
rm /etc/sudoers.d/piie-diagnostics
```

Then revert the workflow PR. Optionally remove the installed script. The root UID
gate remains, so no privilege persists.

## Preserved controls

SSH identity pin (`SHA256:HnARD+o96mEq/ZObScCf5YgfXvV7RW3qrN/aF3LI4LA`), strict
host verification (`SHA256:fnVx92azTndsKgv+ZLYmnmWr/4iLDmtnvBU5Y4MFkbw`), the
root UID gate, and read-only diagnostic behavior are unchanged. No Mailcow,
container, database, or deployment-state change is made by this correction.
