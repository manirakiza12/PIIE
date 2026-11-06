# Docker release identity and code rollback contract

This is repository code and fixture verification, not authorization to run it on
production. The freeze, protected reviewers, backup recovery and approved SSH/
administrator boundary remain mandatory. No root SSH, unrestricted sudo or Docker
socket permission is added. Existing uploaded scripts must not become a root
execution boundary merely because their behavior has sandbox tests.

## Forward release

Normal deployment uses the current main commit, not arbitrary older main ancestors
that might carry older guard scripts. Older code is selected only through an
explicit reviewed rollback path. Each artifact must pass both verify-release.sh
and verify-release-identity.sh with the full approved SHA before upload. The latter
requires exactly one regular RELEASE_SHA member, rejecting missing/duplicate/link
markers or a different full SHA. build-release.sh uses the same check.

container-deploy.sh requires EXPECTED_RELEASE_SHA and compares all 40 characters
before app bootstrap. EXPECTED_DEPLOYED_SHA still verifies the baseline before
activation. No historical marker is fabricated: the actual f23cff8 installation
without RELEASE_SHA remains blocked until its independently attested baseline
has a separately reviewed compatibility mechanism.

Docker inspection/permission failure is fatal. runtime-deploy.sh and the workflow
preflight do not select host PHP or legacy deployment when Docker access fails.
piie having no approved privileged execution path remains a blocker; broad sudo
or Docker-group membership is not a remedy.

## Explicit rollback

Activation input rollback_release identifies the existing verified fallback
directory, while deployed_ref identifies its full attested SHA. Both are required
and validated. The Docker code-only recovery entry point is:

```text
container-rollback.sh <expected-current-full-SHA> <target-release-ID> <target-full-SHA>
```

It requires the separately approved administrator execution boundary, obtains the
PIIE deployment lock, verifies current identity/relative pointer, target identity,
target directory and dependencies, verifies Apache parent identity, switches only
the PIIE current link, gracefully reloads only piie-app, and checks the exact health
body using the existing application client. It does not run SQL, migrations,
enrolment reconciliation, host PHP, Mailcow commands or restore a database dump.

If target reload/health fails after the switch, it restores the starting pointer
and reloads piie-app; the command remains failed. If that recovery fails, it emits
a CRITICAL result requiring administrator intervention, not a successful rollback.
The existing in-process activation cleanup now likewise reports failed pointer,
reload or maintenance recovery honestly instead of ignoring errors.

After a successful install step, a later public smoke, asset, reconciliation or
audit failure invokes this explicit recovery path. The workflow remains failed.
Enrolment reconciliation is now disabled by default and requires the separate
reconcile_enrolment input; code rollback never undoes any approved application or
forward migration writes. Backward schema compatibility must be established in
the recovery clone before this fallback is approved.

Ambiguous SSH disconnect, job cancellation, loss of the VPS, or failure before a
successful install acknowledgment needs manual read-only state reconciliation;
do not blindly redispatch activation/rollback. The freeze and concurrency controls
do not turn network acknowledgments into atomic distributed transactions.

## Evidence and limits

release-engineering-sandbox.sh tests real archive and symlink behavior in disposable
POSIX directories with synthetic Docker/application functions: full-SHA mismatches,
duplicate/missing/linked markers, no legacy fallback on failed Docker visibility,
privilege/identity/traversal rejection, successful rollback, target reload/health
failure, wrong health body, and preservation of shared-state sentinels.
container-runtime.sh additionally tests full candidate SHA comparison and critical
reporting when both activation and rollback reload fail. These are not actual
production restore tests or proof of an installed restricted root broker.

Still required before production: root-owned broker/control code outside writable
ancestry, root-controlled approvals and artifact claiming with symlink/race-safe
filesystem handling, Mailcow exclusion, concrete negative privilege tests, reviewed
installation and workflow integration, historical provenance, full recovery backup,
real isolated restore, exact-commit CI and explicit operator activation approvals.
