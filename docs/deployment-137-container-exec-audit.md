# Deployment 137: application client-container fix

## Evidence and production state

Run: https://github.com/manirakiza12/PIIE/actions/runs/39526061338 (deployment 137, attempt 2).
The full attempt log was downloaded and inspected, including upload, preflight and cleanup.
The selected release was `6731caedaa86d05969cc19ab14e8770d0b3fb091` (staff ID fix).
The remote preflight printed the existing active release and SHA before failing:

- Release: `releases/20261020-175314-d62250a55f45`
- Active SHA: `d62250a55f45688c43ca30adccb9517d09a01fef`

GitHub masks part of this SHA in the log; its remaining characters and release name match
the full SHA recorded by the preceding successful production deployment (PR 69).
This is the active state observed by deployment 137, not a new live-server inspection.
The intended staff ID commit did **not** become active: the install step never ran.
Uploading the archive and deployment scripts did not switch `current`.

## Exact failing command

At 2026-10-21 01:24:14 UTC the remote preflight exited 128 with:

```text
OCI runtime exec failed: ... error adding SSB flag to seccomp filter:
SetSSB requires libseccomp >= 2.5.0 and API level >= 4
(effective version: 2.5.5, API level: 1)
```

The failing command in the checked-out `container-preflight.sh`, line 16, was:

```sh
docker exec --user www-data piie-app test -r /srv/piie/shared/.env
```

The step log has no `bash -x` trace. Identification follows from the exact checked-out
script, `set -euo pipefail`, the successful preceding output, and the first subsequent
Docker command. The database client check appears later and was never reached.

## Complete production exec audit

There were eleven call sites across the production remote scripts, including cron output:

| Original path | Calls | Replacement on this branch |
| --- | ---: | --- |
| Preflight environment read and storage write permission checks | 2 | Same checks as www-data in an app client |
| Preflight PHP version and pdo_mysql | 1 | PHP in an app client |
| Preflight Apache DocumentRoot | 1 | Read actual live config with Docker cp, stream archive without extraction |
| Deployment Artisan helper (discovery, version, migrations, caches, up) | 1 | App client at candidate release working directory |
| Enter maintenance on current release | 1 | App client at current working directory |
| Cleanup: leave maintenance on current release | 1 | App client at current working directory |
| Activation and rollback Apache graceful reloads | 2 | Verify Apache is container init, send USR1 through Docker API |
| Internal HTTP smoke loop | 1 | Client shares live app network namespace, retaining localhost target |
| Generated scheduler cron command | 1 | Shared app client wrapper, current working directory |

The database preflight and backup already used temporary MariaDB client containers;
those paths are preserved. No other `docker exec` sites remain in `deploy/remote`.
The SSH workflow already uploads `deploy/remote/*.sh`, including the new wrapper.

## Client behavior and boundaries

`container-app.sh` uses the live container's immutable image ID with pulling disabled,
inherits its mounts, shares its network namespace, copies container environment overrides
to a private temporary env file, and runs as www-data. Each invocation removes its
temporary credentials and client container. There is no privileged mode or seccomp override.
The actual live Apache config is checked separately because writable-layer configuration
is not inherited from an image. PHP and CLI dependencies must exist in the runtime image
or shared mounts; live-only modifications to image binaries are not copied into clients.

Apache USR1 retains the existing graceful-reload behavior; a non-Apache container init
fails preflight and cannot receive this signal from the wrapper. No signal, deployment,
VPS package change, runtime change, or Docker restart was performed while developing this fix.

## Regression coverage

The Docker fixture rejects **every** exec with status 128 and the production error.
`container-preflight-sandbox.sh` runs the actual preflight and checks app permissions,
PHP, live document root, Apache init, database client, private env cleanup and fail-closed
behavior. `container-runtime.sh` now uses the actual preflight instead of replacing it
with a success stub. It covers stage, changed-baseline rejection, failed migration preview,
activation, code-only/pending-migration gates, HTTP rollback, graceful-reload failure
rollback, maintenance cleanup, uploads/environment preservation and execution of generated cron.

These are isolated Linux sandbox tests with Docker command doubles, not an assertion that
the new clients have already been exercised on the VPS. Production validation remains pending
an explicitly authorized later deployment.
