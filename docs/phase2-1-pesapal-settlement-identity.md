# Phase 2.1 — PesaPal database settlement identity

Scope: schema, read-only preflight and tests only. No provider HTTP adapter,
checkout, callback, IPN, tuition work, credentials or deployment changes.

## Identity contract

Canonical provider: `pesapal`. No aliases are accepted as provider identities.
Official PesaPal API 3.0 describes `order_tracking_id` returned by SubmitOrderRequest
as the unique PesaPal-generated order ID. GetTransactionStatus takes this same ID
as the `orderTrackingId` query parameter:

- https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/submitorderrequest
- https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/gettransactionstatus

The future adapter must store that ID in `gateway_txn_id`, bind the authenticated
status lookup to it, and validate merchant reference, payment/application/school,
amount and currency through ApplicationPaymentSettlement. Neither redirect/IPN
parameters nor successful order submission prove payment. No API calls are added
in this phase. The settlement boundary already compares provider exactly; the
generated identity normalizes provider label case/spaces to prevent DB bypass.

`SettledPaymentIdentity` and the original Phase 1.3 migration remain unchanged.
The new frozen `SettledPaymentIdentityV2` adds only `pesapal` to eligibility.
Paid identified rows participate in `(settled_provider, settled_provider_txn_id)`
provider-wide, including across schools. Transaction IDs remain byte-exact,
untrimmed and case-sensitive, matching V1. Case/whitespace variants are reported
for review; no historical IDs are canonicalized or invented. Future adapter
GUID validation/canonical representation must be reviewed before initiation.

Pending/failed rows, offline/manual methods, unknown providers and null/blank
identities produce NULL generated identity columns. Historical rows remain
readable. Existing MarzPay/Stripe/Flutterwave eligibility and byte semantics
remain unchanged. No account/school namespace is added to weaken uniqueness.

## Migration and preflight

New migration:
`2026_10_07_180000_extend_settled_transaction_identity_for_pesapal`.
MySQL/MariaDB use one ALTER to drop/recreate the existing named unique index
and modify both STORED generated expressions. The index build remains the final
guard against duplicates inserted after preflight. SQLite replaces the VIRTUAL
columns/index within a transaction, including duplicate preflight.
There is no successful migration state without the unique constraint.

UP checks duplicate eligible identities using normalized provider and full HEX
transaction bytes before DDL. An unsafe duplicate aborts without changing rows
or schema. DOWN restores the exact V1 expressions and the same unique index.
Neither direction updates original financial columns.

`php artisan payments:identity-preflight` remains read-only, emits row IDs only,
and exits 1 on unsafe eligible duplicates. It now includes PesaPal missing IDs,
case/whitespace variants, newly eligible paid PesaPal row IDs, ambiguous PesaPal
provider spellings (reported, never mapped), and cross-provider identity reuse.
Cross-provider reuse is permitted by the composite key but reported for review.
The newly eligible list means V2-versus-V1 eligibility, even after V2 is installed.

## Local verification and production gates

Disposable MariaDB 10.4.32, InnoDB, localhost 127.0.0.1:3321,
database `piie_phase21_test`, isolated data directory `storage/phase21-runtime`.
Before destructive database operations the harness asserts SELECT DATABASE()
equals that exact name. It never inherits application database credentials.
The harness under ignored `local-reports/phase21/verify.php` is local evidence,
not a project test to commit.

Verified: duplicate-history preflight and safe abort preserve V1 index/schema;
UP identity generation; duplicate rejection (1062) in each of the four providers
within and across schools; non-settled/manual/null exclusions; DOWN exact V1 DDL
(excluding the unrelated auto-increment counter); PesaPal exclusion on V1;
re-UP protection; original-row preservation; transaction rollback and retry.
DDL and preflight evidence are in ignored `local-reports/phase21/`.
The database was guarded-dropped and the isolated server shut down; only the
verified Phase 2.1 runtime directory was removed. No other database or runtime
was touched. No concurrency/API integration claims are made in this schema phase.

SQLite regression tests additionally verify tenant/amount/currency/reference/
tracking/provider mismatch rejection, replay and a real generated-constraint
conflict handled by the existing settlement boundary without partial credit.
Injected failure after the payment update rolls payment, admission and audit
back; retry succeeds. Laravel HTTP stray requests are forbidden in these tests.

CI already uses mysql:8.0 with guarded disposable ci_test and executes all pending
migrations before PHPUnit. No workflow change is needed. MySQL generated-column
ALTER support was checked against the official manual:
https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-operations.html
The new migration has NOT executed on CI MySQL 8 yet; MariaDB success does not
establish production readiness.

Before eventual authorized deployment: pause writers, run current-data preflight,
review aliases/identity variants/account namespaces, budget table-copy locks/disk,
and rehearse representative data. Disable PesaPal settlement before DOWN because
DOWN intentionally removes PesaPal database protection while retaining its rows.
MySQL/MariaDB DDL cannot be treated as an application transaction rollback.

No commit, staging, push, production connection or deployment occurred.

## Final regression results

- Focused V1/V2 uniqueness and preflight: 18 tests, 127 assertions, all pass.
- Payment/admissions (`Payment|Admission|Applicant|MarzPay|Accountant`):
  251 tests, 1,170 assertions, all pass.
- Complete deterministic fresh-process inventory: 3,385 discovered and executed,
  3,359 passed, zero failed, 26 existing skips, 31,004 assertions. All eight
  shards exited 0; duplicate/missing/unexpected tests: 0/0/0.
  Existing skips: 21 LiveClassModuleTest schema cases and five
  CourseOfferingAcceptancePreparationTest live-write authorization cases.
  Evidence: `storage/logs/phase11-suite-20261007-130604/summary.json`.
- PHP syntax and whitespace checks pass. V1 migration/contract diff is empty.

Phase 2.1 files: PaymentIdentityPreflight.php, SettledPaymentIdentityV2.php,
the new 180000 migration, PesaPalPaymentIdentityTest.php and this document.
The 27 unrelated pre-existing untracked files are untouched. Local reports and
temporary verification harness remain ignored and are excluded from commit scope.

Ready for review/commit and push for MySQL 8 CI: YES. Nothing committed or pushed.
Ready to implement adapter/retire MarzPay/tuition/deploy: NO; await approval.
