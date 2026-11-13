# Privileged deployment gate update — administrator review runbook

Prepared 2026-11-13. NO PRODUCTION COMMANDS EXECUTED. No receipt created and no release dispatched.

## Decision

The local correction moves identity resolution and mandatory receipt verification into `authorize_activation` in `secure-handoff.sh`. The gate invokes it for every activation, including empty/auto/mixed inputs, and passes resolved identities explicitly to the helper. The helper repeats authorization under its exclusive deployment lock before preflight or release preparation. Identities come from the mount-verified protected current release and its approval marker; explicit caller values must match. A changed baseline or revoked receipt between checks fails closed. Exact receipt bytes bind candidate SHA, archive digest, baseline SHA, rollback ID and both flags.

**GO for administrator source review only, subject to the recorded local test results below. NO-GO for production installation/activation until the remaining prerequisites close and explicit approval is obtained.** The original bypass exists in application candidate commit 829f7f3; that application artifact is not the corrected privileged helper source. Pin the separately reviewed correction commit after review/commit, and install the compatible gate/handoff/container helper set together. Do not use the old candidate commit as the corrected installer source.

Other blockers: complete installed gate/helper contents and metadata have not been inspected; independent candidate archive digest, receipt existence/content, protected baseline approval, mount/runtime readiness, and Inventory #3 failure resolution have not been verified in this session. Baseline values below are operator-confirmed, not independently measured here.

Local verification was repeated for final repository review on 2026-11-13 in WSL Ubuntu 24.04 as root using a temporary native Linux checkout. All four relevant suites passed: `piie-deploy-gate-sandbox.sh`, `container-runtime.sh`, `root-handoff.sh`, and `workflow-deployment-gates.sh`. `bash -n` passed for all top-level deploy, remote, diagnostic and test shell scripts. `git diff --check` passed. Tests use synthetic files and Docker mocks; the protected handoff/runtime suites exercise real Linux ownership, bind mounts, namespaces, locking and unprivileged access. They do not certify production readiness. No GitHub Actions workflow was changed or dispatched; existing runner validation, exact-main/exact-SHA CI gates, environment protection, backup and rollback paths remain intact. This correction adds no environment-controlled bypass or new privileged subcommand.

Final repository review found only three authorization helpers, three regression suites and two administrator documents approved for this security commit. Generated storage previews, encrypted inventory evidence and temporary test runners/logs are excluded. The helper's locked receipt verification precedes preflight, release preparation, migration preview, backup creation and application changes; the direct-helper missing-receipt test also confirms no candidate release or backup directory is created. Staging exits before backup/activation; explicit rollback retains protected identities, mount validation and the exclusive lock. Receipt and baseline authority remains root-controlled; the gate's unlocked precheck is advisory defense in depth, while the helper's locked check is mandatory. Privileged helper replacement must remain quiesced and administrator-controlled, as specified below.

## Source and confirmed differences

Original application candidate commit: `829f7f3834103e39b86af67903b06931af84b9d6`. This correction is a separate helper-source review revision; repository commit creation does not approve production installation. Source of privileged installation must be a clean, administrator-owned checkout at the separately approved correction commit, never `/home/piie/deployments/piie/bin`. Record its full SHA from `git rev-parse HEAD` in the administrator approval record, separately from the unchanged application candidate SHA.

| Item | Installed evidence | Candidate repository behavior |
| --- | --- | --- |
| Baseline arguments | Observed rejection: `REFUSED: invalid full sha: auto` | Resolves empty/auto from protected state; explicit values must match; helper rechecks under lock |
| Candidate authorization | Operator confirms installed gate lacks newer receipt checks | Every activation checks the exact root-controlled seven-line receipt, including direct helper activation |
| Complete file/behavior delta | Not available | Must be established using private `diff`, byte comparisons, and ownership/mode inspection below |

Do not infer other installed behavior from the repository. Current source also validates fixed paths, argument counts, SHA/RID formats and flags, checks root-owned helper files/directories, and launches fixed helpers through `env -i`; whether each feature differs from production needs the direct comparison.

Activation contract (eight arguments after subcommand):

```text
deploy-activate RID ART SUM CANDIDATE_SHA DEPLOYED_SHA ROLLBACK_RID CODE_ONLY ALLOW_DESTRUCTIVE
```

RID: UTC timestamp plus candidate SHA's first 12 hex characters. ART: `/home/piie/deployments/piie/incoming/piie-release-829f7f383410.tar.gz`. SUM: 64 lowercase hex characters from independent CI verification, currently unknown. SHA arguments: full lowercase 40-character commits. Flags: `0` or `1`. Required future activation values: explicit deployed SHA `6ac4c97b52ffb939116d3ce541141ee7de434609`, rollback ID `20261112-034734-6ac4c97b52ff`, code-only `0`, destructive `0`. Recheck production immediately before later approval; stale baseline evidence is insufficient.

## Supported installation scope

Follow `deploy/DEPLOY-GATE-INSTALL.md` and the current `deploy/SELF-BIND-INSTALL.md`. `INSTALL-HANDOFF-CORRECTION.md` and `MOUNT-HANDOFF-DESIGN.md` are superseded. Do not replay initial mount enrollment, legacy f23cff8 import, restart-policy changes, Compose, or runtime restarts on an already protected production system.

The documented coherent helper bundle is:

```text
deployment-guard.sh release-archive.py repair-uploads.py merge-uploads.py
secure-handoff.sh pin-deployments.sh preserve-baseline.sh runtime-supervisor.sh
run-scheduler.sh container-app.sh check-required-tables.sh container-preflight.sh
container-rollback.sh container-deploy.sh runtime-deploy.sh piie-deploy-gate.sh
```

Review/validate these together. Gate calls container preflight/deploy/rollback/app helpers; they share deployment-guard and secure-handoff; handoff/deployment call release-archive, repair-uploads and merge-uploads. Runtime and scheduler share the guard/lock contract. The actual minimum changed subset is unknown until installed comparisons are obtained; do not claim every file differs. Prefer the coherent reviewed bundle, byte-identical replacements included, over mixing unverified helper versions. Gate entry point `/usr/local/sbin/piie-deploy-gate` must match the bundle's gate. Do not overwrite other installed diagnostic helpers without separate review; inspect their compatibility and ownership too because the gate scans every top-level `.sh` and `.py` helper.

This correction specifically changes `piie-deploy-gate.sh`, `secure-handoff.sh`, and `container-deploy.sh`; these three must be installed together from the approved correction source. The shared handoff file supplies the mandatory authorization function. The gate precheck alone cannot protect direct helper calls or changes between precheck and deployment lock acquisition. Keep all operations quiesced during replacement and tool rollback. If main advances while reviewing the helper correction, recheck the workflow's exact-current-main requirement and application release approval; never silently substitute an application candidate or relax that rule.

## 1. Pre-install evidence and isolated validation

Use the trusted root console; keep it open throughout. Resolve Inventory #3 privately using `docs/phase0/INVENTORY-EVIDENCE-PRIVACY.md`. Record its actual failure reason and resolution. Never publish decrypted inventory, private keys, environment contents or backup contents.

Set REVIEWED to the approved root-controlled checkout. The following are proposed Linux administrator commands, not commands executed by this task:

```sh
set -euo pipefail
umask 077
REVIEWED=/root/piie-reviewed
SOURCE_SHA=${APPROVED_HELPER_SOURCE_SHA:?set the separately reviewed correction commit SHA}
BASE=/home/piie/deployments/piie
BIN=/usr/local/lib/piie/bin
namei -l "$REVIEWED" "$BIN" /usr/local/sbin/piie-deploy-gate
test "$(git -C "$REVIEWED" rev-parse HEAD)" = "$SOURCE_SHA"
test -z "$(git -C "$REVIEWED" status --porcelain)"
readlink "$BASE/current"
cat "$BASE/current/RELEASE_SHA"
stat -c '%u:%g:%a:%h %n' /usr/local/sbin/piie-deploy-gate
find "$BIN" -maxdepth 1 -type f -exec stat -c '%u:%g:%a:%h %n' {} \;
sudo -l -U piie
visudo -c
findmnt --mountpoint /home/piie/deployments
systemctl cat piie-deployment-pin.service piie-runtime.service piie-scheduler.service piie-scheduler.timer
docker inspect --format '{{.Id}} {{.State.Pid}} {{.HostConfig.RestartPolicy.Name}}' piie-app
```

SOURCE_SHA identifies the separately approved correction source, not approval to install it. Repeat all validation against that immutable checkout. Inspect each ancestor for root control/no unsafe links. Verify existing mount identity record and app mount inode using the reviewed guard; inspect before sourcing any file. Confirm current resolves to the protected `.secure/releases/20261112-034734-6ac4c97b52ff` tree, its RELEASE_SHA matches, and its existing root-private installation approval marker agrees. Unknown pointer/layout or mismatched evidence is a stop condition, not an invitation to repair/enroll.

Privately compare installed gate and all bundle files against reviewed source. `diff -u` exit 1 means a difference, exit >1 means inspection failure. Review every difference and retain the report in administrator-only storage; do not execute scripts from the CI-writable bin. Confirm Python/system prerequisites, capacity, backups, sudo boundary and root-owned no-link helper metadata. Review all out-of-bundle helpers for compatibility.

Run isolated tests from that exact checkout on a disposable native Linux root filesystem, never against production:

```sh
bash deploy/tests/piie-deploy-gate-sandbox.sh
bash deploy/tests/root-handoff.sh
bash deploy/tests/container-runtime.sh
```

The updated gate sandbox covers explicit, auto, empty and mixed inputs; missing/malformed/unsafe receipts; artifact/flags mismatches; changed protected baselines; invalid pointers/rollback identities; and baseline changes/receipt revocation between precheck and locked helper authorization. The runtime suite checks direct-helper receipt enforcement and retains staging/backup/migration/rollback cases. Also run `bash deploy/tests/workflow-deployment-gates.sh` and shell syntax checks. Retain results. Do not simulate adverse mount/runtime conditions on production.

## 2. Independently verify the candidate artifact

CI #969 / run `43208438934` succeeded for the exact candidate SHA. Through the administrator's authenticated GitHub session, confirm workflow identity, repository, SHA, conclusion and release artifact provenance. Release artifact ID `13939698938`, name `piie-release-829f7f3834103e39b86af67903b06931af84b9d6`, was unexpired at preparation. GitHub artifact-container digest: `sha256:f83a6761c3b5e384b763ec296fb83b1c989f619c452c80f075b1b38d94405482`. This is NOT the enclosed release tarball digest and must not be used in its place.

Download from that exact trusted CI run into private administrator storage, independently of the piie-writable incoming upload:

```sh
gh run view 43208438934 --repo manirakiza12/PIIE
gh run download 43208438934 --repo manirakiza12/PIIE \
  --name piie-release-829f7f3834103e39b86af67903b06931af84b9d6 \
  --dir /root/piie-candidate-evidence
cd /root/piie-candidate-evidence
sha256sum piie-release-829f7f383410.tar.gz
sha256sum -c piie-release-829f7f383410.tar.gz.sha256
bash "$REVIEWED/deploy/verify-release.sh" piie-release-829f7f383410.tar.gz
bash "$REVIEWED/deploy/verify-release-identity.sh" \
  piie-release-829f7f383410.tar.gz 829f7f3834103e39b86af67903b06931af84b9d6
```

First ensure the private destination is absent or an inspected root-owned directory; do not merge into an unknown prior download. Compare the computed tarball digest against the CI checksum/log provenance and the incoming archive. An incoming checksum sidecar alone is not independent authorization. Digest mismatch, expired/unavailable trusted evidence, identity failure, or unsafe archive means STOP. Do not rebuild the release and call it the original artifact.

## 3. Exact candidate authorization requirement — no receipt creation in this runbook

Required path:
`/home/piie/deployments/piie/.secure/private/authorized/829f7f3834103e39b86af67903b06931af84b9d6.approved`

Required exact newline-terminated content (the digest line remains unknown and must not be written as a placeholder):

```text
piie-deployment-approval-v1
829f7f3834103e39b86af67903b06931af84b9d6
<independently verified release tarball SHA-256>
6ac4c97b52ffb939116d3ce541141ee7de434609
20261112-034734-6ac4c97b52ff
0
0
```

Administrator-controlled issuance is a separate authorized action. Receipt: root-owned regular file, no symlink, link count one, mode 0600. Authorized directory: root-owned directory, no symlink, no group/other writes. Protected `.secure/private`: root-owned mode 0700; protected layout/mount identity checks must pass. All six values must exactly match later deployment. Existing `.secure/private/RELEASE_ID.approved` two-line installation markers do not replace candidate authorization. Do not fabricate or silently replace a receipt to accommodate drift.

## 4. Approved installation commands — blocked until GO and explicit approval

Coordinate a maintenance window for the deployment control plane. Confirm no running or queued deployment/rollback, runtime startup or scheduler operation can execute during replacement. Pause only the PIIE scheduler timer if active, record its prior state, and wait for any running scheduler service to finish; do not stop the app/runtime. Administrator coordination must also prevent queued GitHub runs. Do not change production reviewer rules. Installation is not atomic across the bundle; quiescence and the shared lock are mandatory.

In one root shell, retain the lock through verification/rollback. Inspect root-controlled backup parents before creating directories. Back up the complete installed helper directory, gate and sudo policy with metadata; backup may contain sensitive files.

```sh
# Execute only after all blockers close and installation is explicitly approved.
test "$(id -u)" = 0
. "$REVIEWED/deploy/remote/deployment-guard.sh"
secure_layout
verify_runtime_mount
root_file "$BASE/.secure/deploy.lock"
test "$(stat -c %a "$BASE/.secure/deploy.lock")" = 600
exec 9<>"$BASE/.secure/deploy.lock"
flock -n 9
test "$(readlink "$BASE/current")" = .secure/releases/20261112-034734-6ac4c97b52ff
test "$(cat "$BASE/current/RELEASE_SHA")" = 6ac4c97b52ffb939116d3ce541141ee7de434609
# /var/lib/piie must already be inspected root-controlled, non-linked, mode 0700.
root_directory /var/lib/piie
test "$(stat -c %a /var/lib/piie)" = 700
BACK=$(mktemp -d /var/lib/piie/gate-update.XXXXXXXX)
cp -a "$BIN" "$BACK/bin"
cp -a /usr/local/sbin/piie-deploy-gate "$BACK/piie-deploy-gate"
cp -a /etc/sudoers.d "$BACK/sudoers.d"
cp -a /etc/sudoers "$BACK/sudoers"
readlink "$BASE/current" > "$BACK/current.before"
cat "$BASE/current/RELEASE_SHA" > "$BACK/sha.before"
FILES='deployment-guard.sh release-archive.py repair-uploads.py merge-uploads.py secure-handoff.sh pin-deployments.sh preserve-baseline.sh runtime-supervisor.sh run-scheduler.sh container-app.sh check-required-tables.sh container-preflight.sh container-rollback.sh container-deploy.sh runtime-deploy.sh piie-deploy-gate.sh'
NEW=$(mktemp -d "$BIN/.gate-update.XXXXXXXX")
for file in $FILES; do
  root_file "$BIN/$file" # Missing/unexpected existing installation: STOP and review.
  install -o root -g root -m 0755 "$REVIEWED/deploy/remote/$file" "$NEW/$file"
  case "$file" in *.sh) bash -n "$NEW/$file";; esac
done
# Parse Python without importing/executing it or creating bytecode files.
python3 -I -c 'import ast,pathlib,sys; [ast.parse(p.read_bytes(), filename=str(p)) for p in pathlib.Path(sys.argv[1]).glob("*.py")]' "$NEW"
install -o root -g root -m 0755 "$REVIEWED/deploy/remote/piie-deploy-gate.sh" "$NEW/gate-entry"
bash -n "$NEW/gate-entry"
for file in $FILES; do mv -Tf "$NEW/$file" "$BIN/$file"; done
# Same-directory temporary file makes entry-point replacement atomic.
ENTRY=$(mktemp /usr/local/sbin/.piie-deploy-gate.XXXXXXXX)
install -o root -g root -m 0755 "$NEW/gate-entry" "$ENTRY"
mv -Tf "$ENTRY" /usr/local/sbin/piie-deploy-gate
```

No sudoers change is normally needed for this update. Compare existing policy with the four narrow grants in DEPLOY-GATE-INSTALL.md, validate with `visudo -c`, and STOP if a policy change is needed: prepare it for separate review, never broaden shell/Docker/PHP permissions automatically. Do not execute any gate deployment/rollback operation to test installation.

## 5. Post-install checks

While still quiesced and holding the lock:

```sh
for file in $FILES; do
  cmp "$REVIEWED/deploy/remote/$file" "$BIN/$file"
  root_file "$BIN/$file"
  test "$(stat -c '%u:%g:%a:%h' "$BIN/$file")" = 0:0:755:1
done
cmp "$REVIEWED/deploy/remote/piie-deploy-gate.sh" /usr/local/sbin/piie-deploy-gate
test "$(stat -c '%u:%g:%a:%h' /usr/local/sbin/piie-deploy-gate)" = 0:0:755:1
visudo -c
sudo -l -U piie
secure_layout
verify_runtime_mount
test "$(readlink "$BASE/current")" = "$(cat "$BACK/current.before")"
test "$(cat "$BASE/current/RELEASE_SHA")" = "$(cat "$BACK/sha.before")"
```

Recheck all helper ancestors and out-of-bundle `.sh`/`.py` files for the gate's ownership/link/write requirements. Compare private before/after environment metadata/content fingerprints without exposing them. Reconfirm app container ID/PID and runtime units unchanged. After releasing the lock, but while deployments remain quiesced, execute the supported real preflight:

```sh
flock -u 9
sudo -u piie sudo -n /usr/local/sbin/piie-deploy-gate preflight
```

Preflight can start temporary diagnostic clients; it does not activate code. Any failure remains NO-GO. Direct invocation as piie must refuse root execution. Verify command rejection and receipt adverse cases only in isolated tests, never by sending an activation command to production. Resume the timer only if it was previously active, after successful verification (or successful rollback), and confirm normal scheduler operation. Preserve backup and private diff evidence; clean only the known temporary files after review.

## 6. Gate/helper rollback

Rollback here restores the deployment tools, not application code or database. Keep production operations quiesced; acquire the same shared deployment lock before file replacement. Use the exact recorded BACK directory, verify its root ownership, no links, and backup integrity. Restore every bundle file from `$BACK/bin` through a same-directory temporary file and `mv -Tf`, preserving original owner/group/mode; restore the entry point last the same way. Restore any separately approved sudo-policy change only from its specific saved file; do not overwrite unrelated concurrent policy changes or the entire sudoers directory. Run `bash -n`, byte comparisons against backup, metadata checks and `visudo -c` before unlocking. Because this procedure requires every bundle file to preexist, it introduces no new top-level helper needing deletion.

Example per-file restoration, after validating BACK and the saved regular files:

```sh
for file in $FILES; do
  TEMPFILE=$(mktemp "$BIN/.restore.XXXXXXXX")
  cp --preserve=mode,ownership,timestamps "$BACK/bin/$file" "$TEMPFILE"
  case "$file" in *.sh) bash -n "$TEMPFILE";; esac
  mv -Tf "$TEMPFILE" "$BIN/$file"
done
TEMPFILE=$(mktemp /usr/local/sbin/.restore-gate.XXXXXXXX)
cp --preserve=mode,ownership,timestamps "$BACK/piie-deploy-gate" "$TEMPFILE"
bash -n "$TEMPFILE"
mv -Tf "$TEMPFILE" /usr/local/sbin/piie-deploy-gate
visudo -c
```

Verify current pointer/SHA unchanged, release the lock, and perform the appropriate old-gate preflight. If rollback verification fails, keep deployment operations blocked and investigate from the root console. Never restore the database, unmount the pin, change trust enrollment, relax receipts, or switch current as part of tool rollback.

## GitHub protections and later activation

Read-only GitHub verification on 2026-11-13 found production branch policy allows `main` only; required reviewers are `manirakiza12` and `benjatruevine`. `prevent_self_review=false` is the existing setting; this task did not change it. Recheck environment rules and main-only workflow gate before/after installation; preserve exact-SHA CI checks, typed confirmation, production approval, backups, migration checks, health checks and code rollback. No workflow dispatch belongs to this installation runbook.

After a separately approved, successful gate update and independent candidate receipt authorization, prepare activation with `main`, confirmation `DEPLOY TO PRODUCTION`, mode `activate`, candidate SHA above, freshly verified explicit baseline SHA/ID above, `code_only=false`, `reconcile_enrolment=false`. Request explicit user approval before dispatch. After activation, verify active SHA, public smoke checks and the authenticated redesigned Curriculum Assignment page without submitting mapping/placement actions.
