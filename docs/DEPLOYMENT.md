# PIIE deployment pipeline

Local → GitHub push → CI → (manual, approved) deploy → live verification.
**Status: prepared, NOT enabled.** `piie-deploy.yml` has only a `workflow_dispatch`
trigger (no `push`/`pull_request`), every job requires the repo variable
`PIIE_DEPLOY_ENABLED=true`, and the `deploy` job runs in the `production` environment,
whose branch policy admits `main` only and which requires a reviewer.

## Architecture

```
/home/piie/deployments/piie/
  releases/<UTC-stamp>-<sha12>/   append-only, last 5 kept
  shared/.env                     created once by hand; NEVER written by a deploy
  shared/storage/                 uploads, logs, framework cache (survives releases)
  backups/                        db-*.sql.gz (14 kept), storage-*.tar.gz (3 kept)
  current -> releases/<id>        atomic symlink swap
  bin/                            deploy/remote/*.sh uploaded by the workflow
```

Each release has `.env -> shared/.env`, `storage/{app,logs,framework} -> shared/storage/*`,
`public/storage -> shared/storage/app/public`.

## Packaging
`deploy/build-release.sh` (run in CI job `release-package`, Node 24 + PHP 8.3):
`git archive HEAD` (tracked files only, `.gitattributes` `export-ignore` drops tests, docs, scripts,
SQL dumps, `storage/app`, root helper scripts) → `composer install --no-dev --optimize-autoloader`
from `composer.lock` → tarball + sha256 → `deploy/verify-release.sh` (fails on `.env`, tests,
node_modules, `.sql`, key material, dev packages, missing `vendor/autoload.php`).
The repo has **no Mix config**: compiled assets are committed, so there is no frontend build
and the host needs no Node. If `webpack.mix.js` is added, the script builds with Node in CI.
The deploy workflow downloads the artefact CI built for that exact SHA; it never rebuilds.

## Safety rules enforced in code
- CI for the exact SHA must have succeeded (`gate` job); typed phrase `DEPLOY TO PRODUCTION`.
- `environment: production` with required reviewers = manual approval; `concurrency` = one at a time.
- Pinned host key (no ssh-keyscan); key fingerprint must equal v3; key files deleted at the end.
- Server uses `/usr/local/php83/bin/php` explicitly (bare `php` is 8.2.27).
- `mode: stage` (default): unpack + link + `migrate --pretend` only. Nothing live changes.
- `mode: activate`: refused unless `public_html/index.php` is the release shell (`PIIE-RELEASE-SHELL`, installed by `deploy/remote/docroot.sh`).
  Then: verified DB dump (gzip test, size, "Dump completed", sha256) and storage archive →
  `migrate --pretend` scan (DROP/TRUNCATE/DELETE aborts unless `ALLOW_DESTRUCTIVE_MIGRATIONS=1`) →
  `migrate --force` (forward-only) → atomic swap → health check (6×) → auto code rollback.
- Scripts never call `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed`, or `rsync --delete`,
  and never create/overwrite `.env`.
- `deploy/check-migrations.sh` scans `up()` of migrations added since `main` for destructive calls.

## Rollback
Code: `ssh piie@HOST -p PORT /home/piie/deployments/piie/bin/rollback.sh [release-id]` (default: previous release).
Database: **manual, deliberate.** Migrations are forward-only and the DB is not auto-restored, because
a restore discards student records written since the backup. If a migration must be undone:
1. put the site in maintenance (`php83 artisan down` in `current`);
2. `sha256sum -c backups/db-<id>.sql.gz.sha256`; restore into a scratch DB first and inspect;
3. only then `zcat backups/db-<id>.sql.gz | mysql ...`; swap `current` back; `artisan up`.
Storage: `tar -xzf backups/storage-<id>.tar.gz -C shared`.

That one archive covers **both** persistent trees — `shared/storage/app` (Laravel uploads) and
`shared/public-uploads` (everything served from `public/assets/uploads`) — so a single restore puts
both back. Note the archive layout changed: archives taken before 2026-10-06 contain `app/...` and
restore with `-C shared/storage`; run `tar -tzf` on the archive to see which layout it has.

## Prerequisites still needed from you
1. **Document root** (verified read-only 2026-10-04): the vhost serves `public_html` itself, a flat app root; it cannot be a symlink to a release. Convert it ONCE to a thin shell with `docroot.sh plan` then `apply`. Until then only `stage` works.
2. **Seed `shared/`** once by hand: copy the live `.env` to `shared/.env` (chmod 600), copy live
   `storage/` (uploads) into `shared/storage/`. Confirm the `.env` has `APP_DEBUG=false`.
3. **`shared/public-uploads` permissions — ONE-TIME, needs root.** This is the only step the
   unprivileged deploy script cannot do for itself, so it is stated here rather than papered over.

   The deployment user (`piie`) merges baseline artefacts; PHP-FPM (`www-data`) writes real user
   uploads. For both to work the directory must be group-owned by `www-data` and **setgid**, so that
   everything created later inherits the group and stays group-writable:

   ```sh
   mkdir -p /home/piie/deployments/piie/shared/public-uploads
   chown -R piie:www-data /home/piie/deployments/piie/shared/public-uploads
   chmod 2775 /home/piie/deployments/piie/shared/public-uploads
   ```

   Run it **once**, by hand, as root. Afterwards `deploy-release.sh` maintains the mode on the
   top-level directory and on any directory it creates itself, and `preflight.sh` verifies group,
   mode and the setgid bit on every run.

   The deploy script never calls `sudo` and never issues `chmod 777`, and it does not recursively
   change ownership or permissions of existing uploads — those may be live production files.

   To verify runtime writability properly (also needs root, once):

   ```sh
   sudo -u www-data touch /home/piie/deployments/piie/shared/public-uploads/.probe && rm -f /home/piie/deployments/piie/shared/public-uploads/.probe
   ```

   `preflight.sh` runs that check automatically when `sudo -n` is available and reports
   "unverified" rather than passing silently when it is not.
3. **Host**: confirm `mysqldump`, PHP 8.3 extensions (`deploy/remote/preflight.sh` reports them),
   ≥2 GB free; ideally set DirectAdmin PHP selector to 8.3.
4. **GitHub**: secrets `PIIE_SSH_HOST`, `PIIE_SSH_PORT`, `PIIE_SSH_USER`, `PIIE_SSH_KEY`,
   `PIIE_SSH_KNOWN_HOSTS` (environment `production`); environment `production` with required reviewer;
   variable `PIIE_DEPLOY_ENABLED` (leave unset until ready).
5. **Branch content**: the pushed branch lacks many locally-untracked files (controllers, models,
   migrations, views). Deploying HEAD ships only what is committed. Commit and review the local work
   first, and resolve the 5 known PHPUnit failures or accept them in writing.
6. **Workflow dispatch** only runs from the default branch: merge to `main` (needs your approval).

## Activation (in order)
1. Complete prerequisites 1–5. 2. Run `preflight.sh` over SSH; all `FAIL` lines must be clear.
3. Merge the workflow to `main`. 4. Set variable `PIIE_DEPLOY_ENABLED=true`.
5. Dispatch with `mode: stage`; inspect the pretend-migration output on the server.
6. Dispatch with `mode: activate`; approve in the `production` environment; watch health check.
7. Rehearse `rollback.sh`. Afterwards set `PIIE_DEPLOY_ENABLED` back to unset if you want the gate closed.

## First cutover (one-time, separate from normal deployments)
`deploy/remote/first-cutover.sh` converts the legacy flat `public_html` to the release shell. It does not
change `deploy-release.sh`, whose `activate` guard still requires the shell.
1. Stage the release: `deploy-release.sh <id> <artefact> <sha256> stage`.
2. `first-cutover.sh check <id>` (read-only) must print `READY`.
3. `PIIE_CONFIRM_FIRST_CUTOVER="CUTOVER <id> <db-name>" first-cutover.sh run <id>`.
   It verifies prerequisites, takes and verifies DB + storage backups, reviews migrations (`--pretend`;
   destructive SQL aborts), runs forward-only migrations, sets `current`, runs `docroot.sh apply` and
   health-checks. A failed health check reverts the docroot and removes `current` (legacy site keeps serving).
   Migrations are not undone; the backup is the recovery path.
It refuses to run twice, and refuses if `current` or the shell already exists.
Tests: `deploy/tests/first-cutover-sandbox.sh` (also run in CI).
