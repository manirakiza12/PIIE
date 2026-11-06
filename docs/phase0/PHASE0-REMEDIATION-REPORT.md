# Phase 0 remediation report

Date: 2026-11-06. Branch: fix/phase0-deploy-safety. Baseline: 79779e5;
last historically confirmed deployed release: f23cff8. Phase 0 remains blocked;
this report does not certify deployment readiness or successful production access.

## Preserved and completed work

Continued OpenCode's uncommitted changes after reading its handoff at
`C:/Users/User/AppData/Local/Temp/opencode/phase0/PHASE0-REPORT.md`.
Preserved its route comment encoding repair, additive payroll migration,
token-based scanner, regression fixtures and PHPUnit scanner integration.
Unrelated scratch artifacts and storage snapshots are excluded from the commit.

Fixed case-sensitive fixture paths for Linux. Extended scanner coverage to
helper methods and static destructive calls (13 regression cases). Added real
migration tests for preservation of duplicate draft, approved and paid records,
retry behavior and clean-data unique-index creation. This scanner is a review
guard, not a proof of arbitrary PHP safety: dynamic SQL, indirect runtime calls
and schema/data compatibility still require migration review and isolated tests.

Completed the read-only inventory and a manual main-only workflow, sharing the
deployment lock and protected production environment, independent of the freeze.
The inventory omits environment values, raw cron commands and key comments,
checks backup member paths, checksums, archive integrity and freshness, and emits
a release file manifest. Backup failures return nonzero; partial inventory may
still be retained for diagnosis and must never be interpreted as approval.
Inventory cannot prove application/DB consistency, escrow, successful restore or
preservation of files excluded from its release manifest.

Added trusted-host verification, evidence-led deploy-key recovery, backup/restore
and baseline comparison procedures plus read-only payroll duplicate audit SQL.
Removed a misleading unverified host fingerprint comment without changing any
operational host pin or secret. Existing destructive down() remains: never run
automatic migration rollback on production.

## Validation evidence

- PHP 8.3.36 found in OpenCode's temporary runtime; no PHP 8.1 Laravel execution.
- Scanner standalone: 13 passed, zero failed.
- Scanner shell gate sandbox: six passed, zero failed.
- PHPUnit focused deployment tests: four passed, 14 assertions; 15 existing
  PHPUnit deprecation notices. Preservation tests use explicit in-memory SQLite.
- Shell syntax checks include diagnostics; JavaScript exam tests: 14 passed.
- Public-asset Python tests: three passed.
- Composer audit --locked: no advisories or abandoned packages.
- Full Windows Git Bash sandbox attempt: nine of 15 suites failed, with POSIX
  mode/symlink and Docker-stub incompatibilities among the observed failures.
  No test was weakened. Ubuntu CI must establish the authoritative result.
- Full application PHPUnit, MySQL migrations, route/permission audit, student,
  academic, payment and payroll regression coverage run through existing CI's
  isolated MySQL database; local .env is never reused for these tests.

## Production evidence and blockers

GitHub variable read confirms PIIE_DEPLOY_ENABLED=false (2026-11-06).
Production environment has required reviewers and custom branch policies.
No deployment was dispatched, freeze lifted, credentials changed, protected merge
performed, database accessed, backup transferred or production files modified.

Last failed run 42211372383 rejected public-key authentication before its first
remote command. The exact root cause remains unproven: wrong endpoint/account,
missing authorization, account restrictions or sshd policy require console logs.
Historical host fingerprints conflict; replacing a secret based on a handshake
would be circular verification. Follow HOST-IDENTITY-VERIFICATION.md first.

Unresolved exit gates:

1. Trusted console endpoint/host-key evidence and identified SSH rejection cause.
2. Approved minimal access recovery, then one protected read-only inventory run.
3. Verified actual live SHA and explained file/config differences from f23cff8.
4. Fresh backup checksums, DB/storage/upload integrity and APP_KEY escrow evidence.
5. Explicit data-transfer authorization and successful isolated restoration test.
6. Green exact-commit CI and required PR review/approval before merge.
7. Explicit user authorization to lift the freeze, followed by approved staging
   and activation with production environment review.

The payroll migration never reconciles records. If duplicates exist it warns and
omits the unique index; concurrent payroll generation lacks that DB constraint
until a separate reviewed reconciliation/index migration. Review this limitation
before approving deployment; do not automatically delete duplicate records.

Deployment SHA, deployment run ID, migration execution and production module/data
preservation verification remain unavailable until these gates are satisfied.
