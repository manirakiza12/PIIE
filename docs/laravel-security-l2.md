# Laravel security migration: L2 compatibility preparation

L2 remains on Laravel 9.52.22, Sanctum 2.15.1 and PHPUnit 9.6.36. Its
starting checkpoint is `3d372bf777cba6bac5ec846dee7756969f8b40e0`. No Composer
constraints, lockfile, payment implementation or other application behavior
is changed. L1 remains local; no checkpoint is pushed by this work.

## Additive Sanctum schema

`2026_10_08_000001_add_expires_at_to_personal_access_tokens.php` follows the
latest existing migration, `2026_10_07_180000`. The original token-table
migration is unchanged. The new migration requires that table, adds a
nullable `timestamp` named `expires_at` with default NULL and the nonunique
`personal_access_tokens_expires_at_index`. It performs no backfill, token
update, deletion or expiration-policy change. Repeated UP is safe and
repairs a missing index; a conflicting index fails before mutation.

**Operational application rollback should retain the additive column and
index.** Laravel 9/Sanctum 2 tolerate them. Do not automatically run schema
DOWN when rolling the application back to L1. The implemented DDL DOWN is
for an explicitly controlled schema rollback: stop token writers and
quiesce queues first. It refuses populated expiry metadata before changing
schema, and otherwise drops only the index and nullable column, preserving
every original token field. Exercise DOWN/UP in disposable databases;
retaining the column is the normal production rollback strategy.

SQLite tests cover old-schema upgrade, fresh original-plus-additive schema,
historical values, nullable/default semantics, the index, repeated UP,
index repair, missing prerequisite, conflicting index, NULL-only DOWN/UP
and refusal to discard populated expiry metadata. API/CORS fixtures now
apply both migrations. API characterization retains valid NULL-expiry
tokens, invalid-token rejection, ownership, tenant boundaries and the
existing global expiration policy.

The MySQL probe at `scripts/tests/sanctum-expiry-mysql.php` uses Capsule with
explicit disposable connection configuration, never the application .env.
It refuses other hosts/databases. It was run against MySQL **8.0.46** on an
internal Docker network, with no host ports and a read-only source mount.
All 23 checks passed, including original/additive migration history,
unchanged historical fields, NULL expiry, the index, repeated UP, DOWN/UP
and populated-expiry rollback refusal. No persistent application database
was used. The fixture container and network are removed after verification.

## PHPUnit inventory verification

`scripts/verify-phase11-suite.php` retains deterministic class shards and
fresh PHPUnit processes. Its parser/reconciler is now shared with isolated
regression tests through `scripts/support/PhpUnitInventory.php`.

Both PHPUnit 9 discovery and namespaced PHPUnit 11 discovery are supported.
Representative fixtures are based on PHPUnit's official discovery format;
execution identifiers follow the
[PHPUnit 11 JUnit logger](https://github.com/sebastianbergmann/phpunit/blob/11.5/src/Logging/JUnit/JunitXmlLogger.php).
Canonical dataset IDs reconcile numeric, named, escaped, hash-containing,
numeric-looking named and empty named datasets. Regression tests exercise
zero discovery/execution, malformed/unrecognized XML, duplicate discovery
and execution, missing/unexpected cases, nonzero process exit and
failure/error/warning/skip preservation. DTDs are rejected and XML parsing
disables network access. Failure, error and skip counts are separate;
zero counts or invalid evidence fail closed.

The updated verifier was also run against the completed L1 evidence:
3,547 discovered/executed, 3,521 passed, zero failures/errors/warnings,
26 skips, 31,919 assertions and no duplicate/missing/unexpected cases.
The new complete L2 inventory uses the current PHPUnit 9 executable; no
PHPUnit 11 executable or package is installed.

## Test fixture compatibility

Eleven upload/security fixtures call a shared test-only public-path helper.
It uses `usePublicPath` when the application provides that setter. Laravel
9 has no setter, so its existing `path.public` binding remains the fallback.
Realpath checks require an existing child of the system temporary directory
and reject the repository's real public tree. Tests verify the setter path,
current-stack fallback and rejection of unsafe paths. Production public
paths, persistent uploads and deployment symlinks are unchanged.

The two simulated QueryExceptions in `PaymentTransactionUniquenessTest`
use a test helper that selects the three-argument Laravel 9 constructor or
the four-argument constructor including the connection name. Unknown
signatures fail closed. Regression tests preserve SQL, bindings, previous
exception and PDO errorInfo and characterize the future argument order.
Production payment exception handling is unchanged.

## Verification evidence

The initial focused compatibility run passed 86 tests / 268 assertions.
The broader application run passed 1,147 tests / 14,096 assertions without
failures/errors/skips. It covered authentication, CORS, tenant isolation,
upload/security fixtures, payments/admissions, PesaPal, PDF, Jitsi and
critical exam suites. Later added verifier edge controls are included in
the complete inventory.

Infrastructure: browser 27/27; exam revision 14/14; editor bridge 24/24.
All nine deployment suites passed in disposable Linux with networking
disabled and source mounted read-only, including all six migration-scanner
cases. No Windows Git Bash workaround was introduced.

The uncached Composer audit returned exit 1 with five advisory records,
all for `laravel/framework`. Dompdf, JWT and CommonMark have zero advisory
records. No ignore entries or dependency updates are introduced.

Composer file SHA-256 values remain:

* composer.json: `44EA4AA53D913A11F99528A76ADAF4D40D39F0C777B7A18A9C314598C047A112`
* composer.lock: `EC3C0860A51922E6961300C0EEF6B09FDA76F98996CE6C2F205E7519F3B7B28D`

The 27 unrelated files are checked against the hashes committed in the L1
document. L2 changes remain unstaged for review.

## Final current-stack inventory

`storage/logs/phase11-suite-20261008-012252/summary.json` records:

| Metric | Result |
| --- | ---: |
| Discovered | 3,591 |
| Executed | 3,591 |
| Passed | 3,565 |
| Failed | 0 |
| Errors | 0 |
| Warnings | 0 |
| Skipped | 26 |
| Assertions | 32,030 |
| Duplicates / missing / unexpected | 0 / 0 / 0 |

All nine fresh-process shards exited zero. The final inventory includes all
29 verifier regression cases, eight test-helper cases and seven dedicated
SQLite migration cases. All 26 skip identities **and reasons** exactly
match L0 (`phase11-suite-20261007-185256`). No failure was converted to a skip.

All 27 original unrelated files match their recorded SHA-256 hashes. The
real `public/assets/uploads` tree retains its original 100 files and digest
`3baebe54c66bb39cb536573298c8e42dab29d9a6f12b943d1152d39e70171bb4`;
`public/uploads` has no files before or after testing. Public fixture tests
did not write into either real uploads tree.

All 22 intentional PHP files pass syntax checks. Final whitespace checks
pass. There are 26 intentional L2 files (15 tracked modifications and 11
new files), with nothing staged. HEAD and both recorded origin refs remain
at the L1 baseline. No commit, push, merge or deployment is performed.
