# Production deployment command gate

Installation requires an authorized VPS root console. Local verification does
not establish that these production prerequisites have been installed.

The gate fixes the non-interactive sudo failure in deployment run 42517867204.
It retains the main-only workflow, successful CI for the exact SHA, production
environment reviewer, migration checks, backups, health checks and code rollback.

## Prerequisites

Complete the reviewed self-bind installation in [SELF-BIND-INSTALL.md](SELF-BIND-INSTALL.md),
including all root-owned helpers in `/usr/local/lib/piie/bin`, the mount identity
record, protected baseline import and runtime restart ordering. The gate refuses
writable, linked or non-root-owned helper files and directories. Installing this
wrapper alone does not make an unprepared runtime deployable.

Use a root-controlled checkout of the exact reviewed commit. The CI-uploaded
`bin` directory is unprivileged staging and must never be executed through sudo.
Keep an administrator-only backup of the installed helper set and sudo policy.

## Install

From that reviewed checkout, as root:

```sh
install -o root -g root -m 0755 deploy/remote/piie-deploy-gate.sh /usr/local/sbin/piie-deploy-gate
bash -n /usr/local/sbin/piie-deploy-gate
cmp deploy/remote/piie-deploy-gate.sh /usr/local/sbin/piie-deploy-gate
```

Prepare `/etc/sudoers.d/piie-deploy-gate` as root:root, mode 0440:

```sudoers
piie ALL=(root) NOPASSWD: /usr/local/sbin/piie-deploy-gate preflight
piie ALL=(root) NOPASSWD: /usr/local/sbin/piie-deploy-gate deploy-activate *
piie ALL=(root) NOPASSWD: /usr/local/sbin/piie-deploy-gate rollback *
piie ALL=(root) NOPASSWD: /usr/local/sbin/piie-deploy-gate app-run /srv/piie/current php artisan course-content\:audit
```

Validate the proposed drop-in using `visudo -cf` before installing it; then run
`visudo -c`. Keep the administrator console open until verification completes.
Do not grant shell, Docker or arbitrary PHP sudo commands. The wrapper validates
argument counts, identities, paths and Boolean flags even where sudoers uses `*`.
The only application command permitted is the exact course-content audit above.
Root-only `deploy-stage` takes RID, archive, checksum and full candidate SHA;
the workflow's normal staging remains unprivileged and needs no sudo grant.

## Verify

As root, inspect `sudo -l -U piie` and the installed file ownership/modes.
After confirming the runtime prerequisites, run:

```sh
sudo -u piie sudo -n /usr/local/sbin/piie-deploy-gate preflight
```

This executes as root through sudo and should complete the real preflight;
it may start temporary diagnostic clients but does not activate a release.
Direct invocation as piie must fail with `root execution required`.
An unlisted command may be rejected by sudoers before reaching the wrapper.
Never use production activation as an installation test.

Activation argument order is:

```text
deploy-activate RID ART SUM CANDIDATE_SHA DEPLOYED_SHA ROLLBACK_RID CODE_ONLY ALLOW_DESTRUCTIVE
```

All paths and helper locations are fixed. The wrapper has no environment-based
test bypass and gives child helpers a fresh environment with only explicit
validated deployment inputs. Test fixtures rewrite paths in a temporary copy.

## Independent candidate authorization

The local readiness correction requires an additional root-controlled receipt
before activation. A caller-provided checksum and RELEASE_SHA establish integrity
and identity, but do not prove that the candidate was reviewed. The receipt must
be created through the administrator approval path, independently of the `piie`
account and its uploaded archive/checksum. This change is not installed merely
because the original gate was installed.

After checking the exact CI artifact and its SHA-256 through trusted release
evidence, the administrator may create
`/home/piie/deployments/piie/.secure/private/authorized/CANDIDATE_SHA.approved`.
The authorized directory must be root-owned and not group/other writable; the
receipt must be a root-owned, single-link regular file, mode 0600, with exactly
these newline-terminated lines:

```text
piie-deployment-approval-v1
CANDIDATE_SHA
ARTIFACT_SHA256
VERIFIED_DEPLOYED_SHA
VERIFIED_ROLLBACK_RELEASE_ID
CODE_ONLY
ALLOW_DESTRUCTIVE
```

All six input values must match the later activation exactly. The gate verifies
the protected mount/layout first and refuses missing or mismatched receipts
before calling the deployment helper. Neither unprivileged staging nor the
workflow uploads may create this authorization. Approval records created by
`prepare_release` are installation receipts, not substitutes for this prior
authorization. Do not use placeholders or approve a digest obtained solely from
an untrusted upload. Publishing or installing this correction needs approval.

## Recovery

If verification fails, retain the current code pointer and investigate. Restore
the administrator's saved sudo policy/helpers when appropriate, validating with
`visudo -c`. Do not weaken the mount, baseline or ownership guards to force a deploy.
Removing this drop-in revokes the added command permissions. Database restoration
is always a separate administrator decision; code rollback does not undo SQL.
