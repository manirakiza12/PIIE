# Phase 1.3 — database-enforced payment transaction uniqueness

## Scope and identity decision

Implemented one focused migration on `application_payments`; no old migrations,
CI/CD, environment files, credentials, deployment settings or provider adapters
were changed. MarzPay remains enabled. No PesaPal adapter or Phase 2 work was added.

The frozen V1 identity contract covers the three existing application adapters:
`marzpay`, `stripe`, `flutterwave`. `method` holds the provider, while
`gateway_txn_id` holds MarzPay's verified transaction UUID, Stripe's Checkout
Session ID, or Flutterwave's server-verified transaction ID. Reference remains
the merchant/application reference, never a substitute provider identity.
Offline proof payments use `method=offline`; cash/bank/waived rows do not reserve
an online identity. Existing nullable columns remain nullable and unchanged.

Generated `settled_provider` is nullable VARCHAR(30), utf8mb4_bin, from the
lowercased/space-trimmed provider label. Generated `settled_provider_txn_id` is
nullable VARBINARY(764), retaining every byte of the original up-to-191-character
utf8mb4 ID. Both are STORED on MySQL/MariaDB. A paid row participates only when
its normalized provider is one of the existing adapters and its external ID is
not blank (space/tab/CR/LF-only identities remain NULL). The unique index is
`application_payments_settled_provider_txn_unique` on both generated columns.

VARBINARY deliberately improves the earlier VARCHAR binary-collation proposal:
PAD SPACE comparison must not silently collapse opaque trailing-space IDs.
Transaction IDs are not lowercased, trimmed, invented or rewritten. The preflight
flags case/whitespace variants for explicit review; it does not assume two opaque
variants represent the same transaction. Future provider canonicalization changes
require a separately reviewed contract/migration. Provider label variants cannot
evade the unique scope.

Scope is provider-wide, including across schools, consistent with the settlement
boundary's existing external-ID reuse policy. Adding school_id would reopen the
demonstrated duplicate-credit gap. Inspection found no persisted merchant/account
namespace requiring a different key. MarzPay uses transaction UUIDs; Stripe and
Flutterwave verification retrieve provider objects by ID. Multi-account/mode
namespace guarantees cannot be proven from historical rows alone: the preflight
reports that absence. Collisions are conservatively rejected rather than allowed
to create a second credit. Do not invent account IDs or use credentials as IDs.

Technical references reviewed:
- [MySQL 8 generated columns](https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html)
- [MariaDB generated columns](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns)
- [MarzPay API](https://wallet.wearemarz.com/documentation/api)
- [Stripe Checkout Session](https://docs.stripe.com/api/checkout/sessions)
- [Flutterwave verification](https://developer.flutterwave.com/docs/transaction-verification)

## Historical preflight and migration safety

Reusable read-only command: `php artisan payments:identity-preflight`, optionally
`--database=<configured connection>`. It outputs row IDs, counts, exclusion
reasons, duplicate eligible identities, paid online missing identities,
case/whitespace variant groups, noncanonical rows, and school/account namespace
review. It does not output payloads, personal details or credentials and issues
only SELECT queries. An eligible duplicate returns exit 1. Other warnings require
human review and do not silently mutate or quarantine financial history.

Read-only inspection of available local reconciliation SQL snapshots found no
INSERTs for application_payments. Thus there were no historical MarzPay payment
rows in those snapshots to reconcile. No original database was connected to or
modified, and no claim is made about current production data.

On disposable MariaDB, synthetic duplicate eligible historical rows were reported
and caused migration UP to abort before adding columns/index. The fixture rows
were then explicitly removed only from the disposable test database; this is not
a historical-data cleanup policy. Legitimate existing pending/failed/manual/null
history passed UP unchanged. Both NULL generated columns permit multiple excluded
rows. Known paid duplicates require approved financial reconciliation; there is
no winner selection or automatic ID rewrite.

MySQL/MariaDB UP uses one ALTER for both generated columns and the full unique
index, preceded by a duplicate preflight using exact HEX(transaction) grouping.
If writers introduce a duplicate after that SELECT, the index build must still
fail. No prefix index or probabilistic hash is used. Deployment should pause
writers, audit data immediately beforehand, budget disk/time/table rebuild locks,
and rehearse against a representative copy. MariaDB/MySQL DDL is not equivalent
to a reversible application transaction; do not auto-enable deployment.

DOWN drops only the named unique index and the two generated columns. It leaves
all original financial columns and rows intact. DOWN removes the protection and
must be a deliberate maintenance decision, not an automatic financial reversal.
SQLite regression fixtures use VIRTUAL generated columns with the same unique
semantics because populated SQLite tables cannot ALTER ADD STORED columns.

## Settlement handling and local engine evidence

ApplicationPaymentSettlement retains provider-wide row locks, current reads,
tenant checks, amount/currency/reference/transaction validation and after-commit
notification. It catches only the named MySQL/MariaDB 1062 identity conflict
(and the exact matching SQLite column constraint), after transaction rollback,
and returns rejected. Paid replay still returns already_settled. Unrelated
database errors continue through normal error handling.

Isolated MariaDB 10.4.32 ran with --no-defaults, a fresh data directory under
storage/phase13-runtime, 127.0.0.1:3313, and a 64 MB buffer pool. Database:
`piie_phase13_test`. Before drops, test-fixture deletes or migration rollback,
the harness asserts SELECT DATABASE() equals that exact name. It never inherits
the application's connection credentials. Only targeted admissions fixture schema,
the existing payment-table migration and the new migration were used; this is not
a complete fresh-install migration verification. One unrelated fixture index name
was shortened in the harness for MariaDB's identifier limit.

`scripts/verify-phase13-database.php` retains the reproducible isolated harness.
Evidence in ignored `local-reports/phase13/` includes migration/settlement logs,
preflight JSON, worker logs, payment JUnit and lock snapshots.

Local checks passed:
- Migration UP; legitimate existing rows; NULL/manual compatibility.
- Direct bypass duplicate rejected with 1062, including across schools.
- Separate PHP settlement processes block on actual InnoDB PRIMARY record locks;
  replay returns already_settled, competing payment rejected, one credit only.
- Separate bypassing writer blocks on the unique secondary index itself; after
  winner commit it receives 1062, rolls back, and leaves one paid row only.
- Real canonical-provider conflict raises 1062 inside settlement, returns rejected,
  leaves no partial credit, and MarzPay webhook returns HTTP 200/ignored.
- Injected failure after payment UPDATE rolls payment/paid_at/fee/audit back;
  retry succeeds; malformed evidence and school mismatches remain rejected.
- Historical NULL identities remain readable; insufficient online identities fail closed.
- Migration DOWN and re-UP preserve all original row values.

## Regression and CI

Payment/admissions regressions: 236 tests, 1,308 assertions, zero failures/errors.
The 12 new PHPUnit cases cover migration compatibility and safe duplicate-history
abort, cross-school DB rejection, non-settled/manual/null semantics, exact ID bytes,
provider variants, read-only preflight, replay, real constraint handling, mocked
MySQL named conflict rollback/HTTP response, and unrelated-error propagation.
Real MariaDB concurrency evidence is additional to those cases, not a sequential
substitute or an inflated PHPUnit aggregate.

Complete inventory evidence:
`storage/logs/phase11-suite-20261007-114145/summary.json`, copied to
`local-reports/phase13/full-inventory-summary.json`. The existing deterministic
fresh-process runner executed all 3,379 discovered cases exactly once across eight
shards, all exit 0. Passed 3,353; failures/errors/warnings 0; assertions 30,926;
skipped 26; duplicate/missing/unexpected cases 0/0/0. The existing skips are
21 LiveClassModuleTest schema cases and 5 CourseOfferingAcceptancePreparationTest
cases requiring explicit live acceptance write authorization. No failure became
a skip. The extra 12 cases account for the increase from the prior 3,367 inventory.

CI already provisions mysql:8.0 and runs `php artisan migrate --force` on ci_test
before PHPUnit. Its test application then isolates most feature fixtures on SQLite;
the migration DDL itself is executed against MySQL. No compatibility gap requiring
workflow changes was found. The local MariaDB and SQLite runs do not claim an
executed MySQL 8 CI result; the actual next CI run remains necessary.

## Working-tree separation

- Phase 1: AccountantController, Applicant/PaymentController, MarzPayWebhookController,
  ApplicantNotifier, ApplicationFee, MarzPayService, ApplicationPaymentSettlement,
  DecimalAmount, VerifiedApplicationPayment, ApplicantPortalTest, MarzPayWebhookTest,
  ApplicationPaymentFoundationTest and docs/payment-foundation-phase1.md.
- Phase 1.1: CommonHelper, SubjectCatalogueAlignmentTest, verify-phase11-suite.php,
  docs/phase1-1-baseline-verification.md.
- Phase 1.2: docs/phase1-2-mysql-verification.md and ignored local-reports/phase12*.
- Phase 1.3: new migration, SettledPaymentIdentity, PaymentIdentityPreflight,
  PaymentIdentityPreflightCommand, PaymentTransactionUniquenessTest,
  verify-phase13-database.php, this report and ignored local-reports/phase13.
  ApplicationPaymentSettlement is shared Phase 1/1.3: only duplicate-key handling
  and the corresponding lock comment were added in Phase 1.3.
- Unrelated pre-existing untracked files: the two root test copies and the existing
  CMS/audit/design/probe/refinement scripts listed in the Phase 1.1 report; untouched.

No staging, commit, push, merge, deployment, production connection, original-data
mutation, Docker cleanup or Truevine resource operation occurred.

Cleanup completed after recording evidence: exact-name-guarded DROP of
piie_phase13_test, normal shutdown of the isolated MariaDB server, then deletion
of only the verified absolute storage/phase13-runtime directory. Initial C: free
space was 30,984,859,648 bytes; after cleanup 29,801,971,712 bytes remained.

Final requested git checks were run. Tracked diff remains the original ten files
(232 insertions / 152 deletions); Phase 1.3 additions and the shared settlement
boundary are untracked and therefore do not appear in git diff --stat. git diff
--check passed; new PHP files also passed syntax and trailing-whitespace checks.
git diff --cached --name-only is empty. All unrelated untracked files are preserved.

Remaining production gates: actual MySQL 8 CI execution, authorized current-data
preflight/reconciliation, namespace/variant review, and DDL timing/locking/disk
rehearsal. NULL identity history intentionally remains outside uniqueness;
automatic settlement still fails closed. Unknown/future adapters require a new
schema contract; provider-wide scan contention is unchanged. Schema DOWN removes
the unique protection and must not be used to permit automatic duplicate credits.

Database migration required: YES — IMPLEMENTED.
Ready to commit Phase 1: YES (review-ready, nothing committed).
Ready to push Phase 1: YES (review-ready for CI, nothing pushed).
Ready for Phase 2 — PesaPal: NO (await MySQL 8 CI result and explicit next-phase approval).
