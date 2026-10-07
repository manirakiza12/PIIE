# Phase 1.1 baseline verification

## Root cause recorded before the fix

`academic_education_level()` in `app/Helpers/CommonHelper.php` keeps a function-static
`$levels` array indexed only by school ID. PHP retains that array across Laravel
application instances and disconnected/recreated databases in the same process.
It also retains outdated values after a school changes its education level.

Minimal reproduction on the unchanged helper:

```
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --order-by=default --filter 'test_a_student_sees_an_invoice_older_than_30_days_by_default|SubjectCatalogueAlignmentTest'
```

Result: 4 tests, 11 assertions, 1 failure, 1 error. The invoice test renders
student navigation for school 1 using the secondary fallback. The subsequent
catalogue fixture recreates school 1 as higher_ed/tertiary, but the helper still
returns secondary. This drives both `Subjects` terminology and the legacy Class
validation branch in `AdminController::subjectCreate()`. The catalogue class
alone passes; payment code is not involved in this reproducing sequence.

The defect is production helper state lifetime, not surviving database rows or
missing test teardown. Long-lived PHP workers and school configuration changes
can also encounter stale classifications. The minimal fix is to read the current
school configuration instead of keeping a process-lifetime cache. The existing
education-level preference, school_type fallback and translated labels remain.

Evidence logs are in ignored `storage/logs/phase11-minimal-single.log` and
`storage/logs/phase11-minimal.xml` (the latter includes the full invoice class).

The first class in the previously failing Phase 1 shard 8 was
`StudentExamResultsTest`. Its first test,
`test_a_student_sees_their_own_published_grades`, also reproduces both original
failures when followed by the two higher-education catalogue tests. This was
confirmed against a separate copy of the HEAD helper using PHP's
`auto_prepend_file`; production working files were not reverted. Evidence:
`storage/logs/phase11-original-shard-source.log` (3 tests, 5 assertions,
1 failure, 1 error).

## Changes and regression evidence

- Production: removed only the function-static education-level cache. No
  controller validation, terminology matrix, translation or payment code changed.
- Tests: two regression methods in `SubjectCatalogueAlignmentTest` exercise a
  replaced in-memory database with a reused school ID, and current explicit
  education-level overrides / school_type fallback after configuration updates.
- Both regressions fail with the original helper. The configuration-update test
  also fails independently with `Lecturer` instead of `Instructor` after a
  tertiary-to-vocational change (`phase11-regression-level-before.log`).
- No global teardown, fixture reset policy, order change or assertion weakening.
- Catalogue, invoice and academic assignment selection: 16 tests, 66 assertions,
  all passed (`phase11-root-fix.xml`).

## Phase 1 re-verification

`phase11-payment-focused.xml`: 224 tests, 1,259 assertions, no failures/errors/skips.
Included ApplicationPaymentFoundationTest, AdmissionPaymentTest, ApplicantPortalTest,
ApplicantStripeReturnVerificationTest, MarzPayServiceTest, MarzPayWebhookTest,
AdmissionConversionTest, AdmissionDownstreamCompatibilityTest,
AdmissionsReviewWorkflowTest, EnrollmentDefaultsTest, StudentPortalActivationTest,
PaymentHandoffSecurityTest, PaymentSettingsPermissionTest,
LegacyPaymentGatewayRoutesTest, RbacRouteAuthorizationTest,
RbacPermissionEngineTest and RouteActionIntegrityTest.

This includes unpaid submission; draft / needs_correction initiation rejection;
submitted initiation; partial, cumulative, exact and excess fee payments;
failed/rejected exclusion; waivers; settlement replay; external transaction reuse;
amount/currency/reference/identity/school mismatch rejection; rollback; and the
offline tuition decline regression. Providers remain mocked/faked. No external
provider requests were made by verification.

## Complete inventory

`scripts/verify-phase11-suite.php` discovers PHPUnit's configured inventory with
data-set identities, assigns complete classes to deterministic contiguous shards,
runs each shard in a fresh process, and compares every JUnit case with discovery.
The script refuses duplicate discovery IDs and retains individual logs/reports.

Final evidence: `storage/logs/phase11-suite-20261007-095242/summary.json`.

- Discovered / executed: **3,367 / 3,367** (previous 3,365 plus two regressions).
- Passed: **3,341**; assertions: **30,877**.
- Failures / errors / warnings: **0**; skipped: **26**.
- All eight process exit codes: **0**.
- Duplicate / missing / unexpected IDs: **0 / 0 / 0**.
- All skips are the existing `LiveClassModuleTest` schema-dependent cases.
- No pre-existing failures remain in the configured PHPUnit inventory.
- Focused and negative-control runs are separate evidence, not added to full-suite
  aggregate totals. Root-level pre-existing test copies are outside the configured
  `tests/Feature` suite, as before.

## MySQL validation limitation

No MySQL tests executed. Docker was available and the CI workflow uses a disposable
MySQL 8 service, but the local attempt to start an isolated MySQL 8 container
failed during image extraction with an input/output error. A retry failed because
Docker's internal metadata filesystem became read-only. No database credentials
from the application were used. The intended container had `--network none`, no
published ports, no host data volume, and a disposable database name. No container
was successfully started. Existing unrelated containers were not changed.

The available XAMPP binary is MariaDB 10.4.32, not MySQL 8; it was not connected
to, and is not represented as equivalent validation. Restarting or repairing the
user's Docker engine/storage is outside this application change.

MySQL cases A-F (same-payment replay, competing payments for one external ID,
concurrent processing, rollback, tenant mismatch and identity/index assumptions)
therefore remain **unvalidated on MySQL**. SQLite tests cover the sequential
replay, rejection, rollback and tenant outcomes; they do not prove InnoDB locks.

Schema inspection confirms no unique `(method, gateway_txn_id)` constraint and no
provider lookup index. The settlement boundary locks existing provider payment
rows across schools before reading the target/admission, checks other paid rows
for external-ID reuse, and makes paid replays no-ops. Two boundary callers for
the same provider are intended to serialize on these shared rows; this is code
review evidence, not a successful MySQL concurrency experiment.

The realistic duplicate-credit race without that shared locking is two different
pending rows reading "no other paid row" and both committing the same external
transaction. The existing boundary is designed to prevent it for cooperating
callers. Direct/manual database writes and any path bypassing the boundary do not
have a database-enforced uniqueness guarantee. Broad scans/locks can also cause
contention, including contention on unrelated rows depending on the query plan
and isolation level. Actual MySQL behavior, rollback/after-commit handling,
deadlock retry and throughput still require disposable MySQL validation.

A durable provider transaction identity constraint/index is recommended for a
future approved schema phase. Existing duplicate historical IDs and the intended
handling of pending/failed rows must be audited before choosing the constraint;
blindly adding a unique index could reject historical data or legitimate pending
attempts. No migration is demonstrated to be required by this investigation, and
none was created. A schema redesign is not justified as a workaround for a broken
local Docker environment. It may wait only while all automatic adapters retain
this boundary and MySQL validation succeeds; that validation is required before
declaring production readiness or starting PesaPal. If validation disproves the
locking assumptions, stop for migration approval before implementing a schema fix.

## Safety and readiness

Both CI/CD workflows and all deployment scripts remain unchanged. Phase 1 payment
files remain unchanged by Phase 1.1. No staging, commit, push, merge, deployment,
production connection, migration or Phase 2 implementation was performed.

The PHPUnit baseline is green. Final production verification remains incomplete
because of the MySQL blocker: **ready to commit Phase 1: NO; ready for PesaPal: NO**.

## Working-tree file classification

### Phase 1 (preserved)

```
app/Http/Controllers/AccountantController.php
app/Http/Controllers/Applicant/PaymentController.php
app/Http/Controllers/MarzPayWebhookController.php
app/Support/Admissions/ApplicantNotifier.php
app/Support/Admissions/ApplicationFee.php
app/Support/Payments/MarzPayService.php
app/Support/Payments/ApplicationPaymentSettlement.php
app/Support/Payments/DecimalAmount.php
app/Support/Payments/VerifiedApplicationPayment.php
tests/Feature/ApplicantPortalTest.php
tests/Feature/MarzPayWebhookTest.php
tests/Feature/ApplicationPaymentFoundationTest.php
docs/payment-foundation-phase1.md
```

### Phase 1.1

```
app/Helpers/CommonHelper.php
tests/Feature/SubjectCatalogueAlignmentTest.php
scripts/verify-phase11-suite.php
docs/phase1-1-baseline-verification.md
```

### Pre-existing unrelated untracked files (untouched)

```
FinalSecurityHardeningTest.php
TenantIsolationSweepTest.php
scripts/audit-logo-provenance.php
scripts/audit-markup.php
scripts/audit-public-site.php
scripts/audit-section-images.php
scripts/check-design-system-coverage.php
scripts/cleanup-cms-probe-rows.php
scripts/create-careers-page.php
scripts/image-inventory.php
scripts/inspect-identity-statements.php
scripts/inspect-image-and-featured.php
scripts/inspect-settings-sources.php
scripts/inspect-website-cms.php
scripts/lint-blade.php
scripts/probe-cms-to-public-chain.php
scripts/probe-featured-flag.php
scripts/probe-image-optimizer.php
scripts/probe-live-enquiry-inbox.php
scripts/probe-programme-image-chain.php
scripts/probe-videos.php
scripts/render-public-page.php
scripts/seed-official-contact-details.php
scripts/survey-course-architecture.php
scripts/survey-refinement-inputs.php
scripts/verify-refinement-artefacts.php
scripts/verify-super-admin-live.php
```

### Final git checks

`git status --short`:

```
 M app/Helpers/CommonHelper.php
 M app/Http/Controllers/AccountantController.php
 M app/Http/Controllers/Applicant/PaymentController.php
 M app/Http/Controllers/MarzPayWebhookController.php
 M app/Support/Admissions/ApplicantNotifier.php
 M app/Support/Admissions/ApplicationFee.php
 M app/Support/Payments/MarzPayService.php
 M tests/Feature/ApplicantPortalTest.php
 M tests/Feature/MarzPayWebhookTest.php
 M tests/Feature/SubjectCatalogueAlignmentTest.php
?? FinalSecurityHardeningTest.php
?? TenantIsolationSweepTest.php
?? app/Support/Payments/ApplicationPaymentSettlement.php
?? app/Support/Payments/DecimalAmount.php
?? app/Support/Payments/VerifiedApplicationPayment.php
?? docs/payment-foundation-phase1.md
?? docs/phase1-1-baseline-verification.md
?? scripts/audit-logo-provenance.php
?? scripts/audit-markup.php
?? scripts/audit-public-site.php
?? scripts/audit-section-images.php
?? scripts/check-design-system-coverage.php
?? scripts/cleanup-cms-probe-rows.php
?? scripts/create-careers-page.php
?? scripts/image-inventory.php
?? scripts/inspect-identity-statements.php
?? scripts/inspect-image-and-featured.php
?? scripts/inspect-settings-sources.php
?? scripts/inspect-website-cms.php
?? scripts/lint-blade.php
?? scripts/probe-cms-to-public-chain.php
?? scripts/probe-featured-flag.php
?? scripts/probe-image-optimizer.php
?? scripts/probe-live-enquiry-inbox.php
?? scripts/probe-programme-image-chain.php
?? scripts/probe-videos.php
?? scripts/render-public-page.php
?? scripts/seed-official-contact-details.php
?? scripts/survey-course-architecture.php
?? scripts/survey-refinement-inputs.php
?? scripts/verify-phase11-suite.php
?? scripts/verify-refinement-artefacts.php
?? scripts/verify-super-admin-live.php
?? tests/Feature/ApplicationPaymentFoundationTest.php
```

`git diff --stat` (tracked changes only):

```
 app/Helpers/CommonHelper.php                       |   8 +-
 app/Http/Controllers/AccountantController.php      |   2 +-
 .../Controllers/Applicant/PaymentController.php    | 136 ++++++++++-----------
 app/Http/Controllers/MarzPayWebhookController.php  |  71 +++++------
 app/Support/Admissions/ApplicantNotifier.php       |   5 +-
 app/Support/Admissions/ApplicationFee.php          |  61 ++++++---
 app/Support/Payments/MarzPayService.php            |  37 +++++-
 tests/Feature/ApplicantPortalTest.php              |   4 +
 tests/Feature/MarzPayWebhookTest.php               |  34 +++---
 tests/Feature/SubjectCatalogueAlignmentTest.php    |  26 ++++
 10 files changed, 232 insertions(+), 152 deletions(-)
```

`git diff --name-only`:

```
app/Helpers/CommonHelper.php
app/Http/Controllers/AccountantController.php
app/Http/Controllers/Applicant/PaymentController.php
app/Http/Controllers/MarzPayWebhookController.php
app/Support/Admissions/ApplicantNotifier.php
app/Support/Admissions/ApplicationFee.php
app/Support/Payments/MarzPayService.php
tests/Feature/ApplicantPortalTest.php
tests/Feature/MarzPayWebhookTest.php
tests/Feature/SubjectCatalogueAlignmentTest.php
```

`git diff --check`: exit 0; no whitespace errors.
`git diff --cached --name-only`: empty; nothing staged.

File coverage cross-check: all 208 configured test files appear in final JUnit reports; exact absolute-path comparison found zero missing or unexpected files.
