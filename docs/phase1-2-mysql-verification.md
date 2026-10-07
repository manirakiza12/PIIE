# Phase 1.2 database verification attempt — 2026-10-07

## Retry result — completed locally, schema approval required

This retry supersedes the blocked result below. Initial C: free space was
30,672,670,720 bytes (28.57 GiB). The isolated XAMPP MariaDB 10.4.32 process
used `--no-defaults`, a newly initialized `storage/phase12-runtime/data`,
127.0.0.1:3312 and a 64 MB buffer pool. No existing database service, Docker,
Truevine, production environment or remote host was used.

Created only `piie_phase12_test`. Every drop was preceded by an exact
`SELECT DATABASE()` assertion. The existing admissions test fixture schema was
adapted in the disposable harness to InnoDB; the actual existing
`2026_08_01_010007_create_application_payments_table.php` migration ran successfully.
This is targeted settlement schema verification, not a full fresh-install
migration run. One unrelated fixture-generated index name exceeded MariaDB's
64-character limit; only the disposable harness shortened that name. After a
guarded drop/recreate, schema setup passed. Application/test sources were unchanged.

Evidence retained in ignored `local-reports/phase12-retry/`: harness, verification
log, supplemental log, two lock-wait JSON files, worker logs, regression JUnit/log,
and cleanup log. Harness copies are evidence, not application code.

- Replay: settled then already_settled; one paid row, unchanged timestamp/audit.
- External transaction reuse: second payment rejected, including another school.
- Real concurrency: separate PHP processes/connections 17 and 19 blocked behind
  connection 15's open settlement transaction. INNODB_LOCK_WAITS and INNODB_LOCKS
  show conflicting X RECORD locks on application_payments PRIMARY. After commit,
  same-payment worker returned already_settled; competing-payment worker rejected.
  Each case retained exactly one paid transaction. Isolation: REPEATABLE-READ.
- Rollback: admission-saving listener threw after payment UPDATE inside the locked
  transaction; payment, paid_at, fee state and audit rolled back. Retry settled.
- Cross-school evidence and payment/admission school mismatch rejected.
- Amount, currency, provider, merchant reference, transaction identity and missing
  identities rejected. Historical cash rows with null currency/reference/transaction
  remained readable and countable; insufficient online identity failed closed.
- Negative control: a direct paid UPDATE bypassed the boundary and persisted two
  paid rows with one provider transaction in separate schools; both applications
  acquired paid fee state. Existing locks protect cooperating callers only.

### Database migration required: YES — proposal only, no migration created

Proposed table: `application_payments`. Add two nullable STORED generated columns:
`settled_provider VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin` and
`settled_provider_txn_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin`.
For both columns, the eligibility expression is `status = 'paid' AND method IN
('marzpay','stripe','flutterwave','pesapal') AND NULLIF(TRIM(gateway_txn_id), '') IS
NOT NULL`; return method / gateway_txn_id respectively when eligible, otherwise NULL.
Add UNIQUE BTREE `application_payments_settled_provider_txn_unique`
(`settled_provider`, `settled_provider_txn_id`). Names and expressions are a proposal,
not applied SQL. PesaPal's name appears only in the proposed future constraint.

Scope is provider-wide across schools, matching the current boundary. Do not add
school_id: that would permit the demonstrated cross-school double credit. Before
approval, confirm each provider's documented ID namespace and case/canonicalization
contract. If IDs are merchant-account scoped, a durable verified account namespace
must be modeled in both evidence and uniqueness; school ID alone is insufficient.
Binary collation preserves case distinctions; canonical provider names must be
validated. Provider lists must be extended with every future automatic adapter.

MariaDB permits multiple NULL keys. Pending/failed/offline/waived rows and historical
paid rows with absent external identity remain readable without invented IDs.
Pending attempts can share IDs; only one can transition to identified paid state.
Known historical paid duplicates cannot be silently grandfathered, deleted or
rewritten: they block index creation until an approved financial reconciliation.
Null-history compatibility means this index alone cannot prohibit a bypass that
writes paid with missing identity. A separately approved legacy-preserving integrity
policy is needed for that stronger guarantee; automatic settlement already rejects
insufficient identity. Preserve the settlement boundary and tenant validation.

Duplicate-data preflight (read-only, on a separately authorized deployment): group
eligible paid rows by the same generated provider and transaction expressions with
binary collation, HAVING COUNT(*) > 1; return IDs, school/admission IDs, amounts,
currency and reference for reconciliation. Also inventory blank/null identities,
case/whitespace variants, unknown provider names, and pending/failed shared IDs.
Never infer identity from amount/reference alone. No production audit was performed.

Deployment: approve reconciliation and namespace semantics first; rehearse on a
representative copy, measure generated-column/index DDL time and locking/disk cost,
and pause settlement writers for final preflight/index creation to avoid a race.
Add handling for duplicate-key conflicts in a separately approved code change so
the losing request fails closed with its transaction fully rolled back. Preserve
after-commit notification and application admission locks. Database uniqueness
arbitrates callback/IPN/poll/webhook races and multiple workers even if a future
writer omits the boundary; it does not validate tenant ownership or provider evidence.

Rollback: failed/uncommitted settlement releases unique-key reservation; retry is
safe. Rolling back the schema drops the unique index then generated columns, removes
the guarantee, and does not reverse paid rows or financial reconciliation. Pause
writers and retain reconciliation evidence; MariaDB DDL is not transactionally
reversible. A rollback must not reopen automatic PesaPal processing without protection.

No application code changed in this retry; the prior complete 3,367-case inventory
does not require repetition. Payment regressions passed: 224 tests / 1,259 assertions,
zero failures/errors. The guarded disposable database drop, isolated server shutdown
and deletion of only the newly created runtime directory completed after evidence
was copied. All pre-existing untracked files were preserved. No migration was created.
Ready to commit Phase 1: NO. Ready for Phase 2/PesaPal: NO. Await migration approval.

Result: blocked before database creation. No application behavior changed.

Docker Desktop 29.8.0 was reachable outside the sandbox. Downloading mysql:8.0
failed with a read-only metadata filesystem at
`/var/lib/desktop-containerd/daemon/io.containerd.metadata.v1.bolt/meta.db`.
No Docker restart, repair, prune, deletion, or unrelated container change occurred.

XAMPP's binary reports MariaDB 10.4.32. An independent disposable system data
directory was initialized at `storage/phase12-runtime/data`. A separate process
was started with `--no-defaults`, that explicit data directory, port 3312,
`--bind-address=127.0.0.1`, and a 64 MB InnoDB buffer pool. The existing XAMPP
process was left untouched. The disposable process (17468) exited after InnoDB
reported a full disk while allocating its redo log. The machine's C: volume
reported 1,241,088 bytes free after the failure. No further start was attempted.

The isolated verification harness could not connect to localhost:3312. It never
created `piie_phase12_test` or a test account and never bootstrapped the application.
No application migrations, settlement operations, provider requests, or production
connections occurred. The unused harness was removed. Startup evidence was copied
to `local-reports/phase12/mariadb-startup.log` and `mariadb-bootstrap.log` before
removing the newly created runtime directory. No database drop was necessary.

Cases A–G remain unexecuted on a MySQL-compatible engine. Lock serialization,
concurrency, rollback, tenant isolation, evidence mismatches, and nullable identity
behavior therefore have no new engine validation. A uniqueness requirement cannot
be determined from this failed infrastructure attempt; no migration was created.
Application locking remains an unvalidated assumption, and paths bypassing the
boundary have no database-enforced external-transaction uniqueness guarantee.

The payment regression suite was not rerun: the requested engine verification
could not take place and no application code changed. Prior evidence remains
224 tests / 1,259 assertions for payment-focused verification and 3,367 executed
cases / 30,877 assertions / zero failures or errors / 26 skips for the full suite.
Those figures are previous results, not Phase 1.2 execution.

Clarification of the Phase 1.1 skip inventory: the saved summary identifies
21 LiveClassModuleTest cases and 5 CourseOfferingAcceptancePreparationTest cases,
not 26 LiveClassModuleTest cases. The latter five intentionally require explicit
live acceptance write authorization; they were not enabled in this attempt.

Nothing staged, committed, pushed, deployed, or migrated. Phase 2 not started.
Ready to commit Phase 1: NO. Ready for Phase 2: NO.
