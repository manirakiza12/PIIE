# Laravel security migration: L0/L1

## Authorized scope

L0 baseline freeze and L1 compatibility characterization on Laravel 9 only.
No dependency, database, migration, settlement, admissions, exam, live-class,
PDF/JWT, CI, commit, push, merge or deployment changes are part of this work.

Baseline branch: `feature/pesapal-api-adapter`.
Baseline HEAD: `153414dce912bfd79b3ad84313b7326eab1c4b9b`.
Frozen origin/main: `5ddcb8d89ed4472de1f9729f312e5cf73503605a`.

## Dependency freeze

| Package | Installed and locked |
| --- | --- |
| laravel/framework | 9.52.22 |
| laravel/sanctum | 2.15.1 |
| laravel/ui | 3.4.6 |
| spatie/laravel-ignition | 1.7.2 |
| nunomaduro/collision | 6.4.0 |
| phpunit/phpunit | 9.6.36 |
| barryvdh/laravel-dompdf | 3.1.2 |
| dompdf/dompdf | 3.1.6 |
| firebase/php-jwt | 7.2.1 |
| league/commonmark | 2.10.3 |

Composer JSON SHA-256: `44EA4AA53D913A11F99528A76ADAF4D40D39F0C777B7A18A9C314598C047A112`.
Composer lock SHA-256: `EC3C0860A51922E6961300C0EEF6B09FDA76F98996CE6C2F205E7519F3B7B28D`.

The current audit exits 1 with five laravel/framework advisory records:
PKSA-d5tc-s1qs-h781, PKSA-m5cs-t1y6-qpcs, PKSA-3r5d-mb8f-1qw9,
PKSA-mdq4-51ck-6kdq and PKSA-8qx3-n5y5-vvnd.
No audit ignores or enforcement changes are authorized.

## L1 behavior under test

Only the CORS class at its existing global middleware position changes:
Fruitcake\Cors\HandleCors becomes Illuminate\Http\Middleware\HandleCors.
The installed Laravel 9.52.22 supplies that class. The existing CORS paths,
wildcard origins/methods/headers and credentials=false configuration are retained.
For a multi-origin allowlist, a disallowed origin receives no origin permission
header. A single-origin configuration can return its fixed configured origin;
the browser denies access when that value differs from the requesting origin.
The wildcard configuration also emits its static header on requests without
Origin. Nine parity cases verify these existing legacy/built-in behaviors.
CORS is not a replacement for authentication or tenant authorization.

CorsCompatibilityTest exercises the full HTTP kernel, restricted-origin and
preflight behavior, credential/header settings, anonymous/invalid-token rejection,
bearer identity, and the real locale/applicant tenant middleware on a test-only
route. OPTIONS can complete before authentication; the actual protected request
must still reject an unauthenticated caller.

ApiAuthenticationTest retains its original schema fixture and adds token hashing,
ownership, historical rows without expires_at, global expiration, forged token
ID/secret rejection, cross-school mutation denial, stateful session classification
and CSRF coverage. CSRF tests disable Laravel's PHPUnit bypass only inside the
test container so they exercise the real token check and exclusions.

AuthenticationCompatibilityTest exercises actual web/applicant login, logout and
password reset endpoints, including admin, lecturer, student and parent accounts.
Test-only protected endpoints use the existing role middleware and record the
authenticated identity/school. The separate applicant guard, public-school
boundary and inactive applicant behavior are retained. Existing RBAC suites
remain authoritative for legacy behavior: some disabled legacy users can log in
but are rejected by their portal middleware; this phase does not redesign login.

Fixtures use isolated in-memory SQLite through Tests/CreatesApplication.php.
No production database or persistent production token rows are modified.

## L2 proposal only: Sanctum schema

Do not implement this section as part of L1.

Existing schema: database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php.
Current User uses HasApiTokens; ApiController::login issues tokens.
Sanctum 2.15.1 does not write per-token expires_at. Sanctum 3/4 does.

Propose one NEW additive migration, retaining the original create-table migration:

- Column: personal_access_tokens.expires_at, nullable timestamp.
- Default/data: NULL, no historical backfill, no forced expiry or token revocation.
- Position: after last_used_at on MySQL if desired; ordering is not required.
- Index: recommend named personal_access_tokens_expires_at_index to match
  Sanctum 4's schema and support expiry pruning. It is not required for
  individual token authentication correctness.
- Historical rows: remain valid according to the existing global
  sanctum.expiration policy; NULL does not bypass a configured global lifetime.
- MySQL 8: standard nullable timestamp and ordinary nonunique index.
- SQLite: supported nullable timestamp/index; avoid MySQL-only raw SQL.
- Up: verify actual existing schema, add column/index once, retain all token IDs,
  hashes, abilities, owners and timestamps.
- Application rollback: retain the additive nullable column/index while restoring
  old application/dependencies; Sanctum 2 ignores the extra column.
- Explicit migration down, only if separately approved: drop the named index then
  the column; never drop the token table or delete rows. Warn that per-token expiry
  information would be lost if that destructive rollback is actually performed.
- Gate: fresh and populated fixtures on SQLite and disposable MySQL 8; prove
  historical NULL-token authentication, global expiry, and future per-token expiry.

References:
[Sanctum 3 upgrade](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md);
[Sanctum 4.3.3 schema](https://github.com/laravel/sanctum/blob/v4.3.3/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php).

## L2 proposal only: fixture and inventory compatibility

A. Add the reviewed token migration above; do not publish a duplicate create-table migration.
B. Apply it after the original token migration in ApiAuthenticationTest and other
   token fixtures (including CorsCompatibilityTest). Update L1's absence assertion
   to verify nullable expires_at; preserve historical-row and ownership assertions.
C. Teach scripts/verify-phase11-suite.php both PHPUnit 9's testCaseClass/
   testCaseMethod format and PHPUnit 11's namespaced tests/testClass/testMethod
   format. Normalize dataset identities against actual JUnit keys and preserve
   deterministic class shards.
D. Fail on zero discovered tests before starting shards and zero executed tests
   afterwards. Regression fixtures must also prove duplicate, missing, unexpected,
   discovery failure and test failure/error detection. Report errors independently
   from failures, without converting either into skips.
E. Eleven fixtures currently bind path.public. Plan a shared version-aware setter:
   use Application::usePublicPath when available, otherwise retain the Laravel 9
   binding. Verify real temporary upload paths and cleanup before removing fallback.
   Affected files under tests/Feature: AcademicFinanceTenantSecurityTest.php,
   ClubTenantOwnershipTest.php, DocumentAndParentTenantSecurityTest.php,
   FinalSecurityHardeningTest.php, LiveClassMaterialRealUploadTest.php,
   LiveClassMaterialUploadSecurityTest.php, ProgrammeCatalogueBridgeTest.php,
   ProgrammeCoverImageTest.php, StudentAccountAndAdminSurvivabilitySecurityTest.php,
   TenantIsolationSweepTest.php and WebsiteCmsTenantOwnershipTest.php.
F. PaymentTransactionUniquenessTest.php:193,227 constructs QueryException using
   Laravel 9's three arguments. Plan a version-aware fixture factory supporting
   Laravel 10+'s connection-name-first four-argument constructor, while keeping
   current Laravel 9 tests executable. Preserve PDO errorInfo and both unique-key
   and unrelated-error regression cases. No settlement implementation change.

The L2 verifier, helpers and migration are proposals, not implemented here.
Laravel ^12.69.3 remains a later target; L2/L3/L4 require separate approval.

## Preserved unrelated untracked files

The following original 27 files are outside this phase. Names and SHA-256 hashes
must remain identical to the L0 snapshot:

```text
E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855 FinalSecurityHardeningTest.php
E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855 TenantIsolationSweepTest.php
F95B07AB10ABD13CD9CE5A41C08B75B3CD73BFBBF7615D0CBF3B76FABC00150A scripts/audit-logo-provenance.php
00D557EBD56FED5B398A80E4562E58B7590E7AE44D85E1C0D80D6F9C8D70285F scripts/audit-markup.php
55177A840E0830909BF1472784DC6B4CEBEAB36DABF8509F2EC2A04AC6392FC0 scripts/audit-public-site.php
C7104247BE856A88A9805BD68FFACA88D71EC16365283EF6D0ACBC248424AE7A scripts/audit-section-images.php
836D84CC7E3904F2A5A2D36796BA36457956CEC95EF12FF4D3AE4F6764A324F6 scripts/check-design-system-coverage.php
1FF33ADB913111FE5E1F92B8C7056D05CD20B18EA63B3C93EAF0EB87D7F7CE76 scripts/cleanup-cms-probe-rows.php
32CB76B0CCB3B590CBDF793E7364BA11BB9BBE305712B3D7442C237BAD4DEC62 scripts/create-careers-page.php
D9D99D755901E7F3179E21AEDE51C0FD97573C1C8E0172BD636A11B57CD8C68F scripts/image-inventory.php
CB15651B4C71F2FBFBFD57D7169C8E408FDA73600864CE1C83882B4A97C4A575 scripts/inspect-identity-statements.php
A936A0A93F9313F5F80CF73509D47F43F372B17FC80914332AB2182E3DDA9E9C scripts/inspect-image-and-featured.php
1E5EE7E7F84ACB337CDBBBFFF61B5B93AF817873F70F93DA38CB72A2196A42D6 scripts/inspect-settings-sources.php
6A54BFA166A2A253589C4093F9CF5B36AFF4E413A3F3219E689E0A6BE16F2238 scripts/inspect-website-cms.php
CB4E07C971C29B2F82219F3997E0FD91FE7BCEF8BBB59625A72213B1B4955AED scripts/lint-blade.php
B868DC1803FA790C8C3B216C31A54E48EFF2E9FAA7DFE87EB2E8F508F30B24EB scripts/probe-cms-to-public-chain.php
59E9AED8F9F760A4731FC2F21FB9987920739BBEA3D449CB9296D66E25CB00AA scripts/probe-featured-flag.php
EA9DEC392A9CE291CE439E89B0A11018A6F3E6789FFF7A0CC7300BD77C462268 scripts/probe-image-optimizer.php
5547A04AFA2B6F0CBE89229702FE7EBBD9FAD3E07D0B703ABDE152720B337878 scripts/probe-live-enquiry-inbox.php
99D8E47A5C5A2C12D201689851CDD613FEF6B0956087E181F606650BAF462AF2 scripts/probe-programme-image-chain.php
EF9FF295E61B643DD50E0330FD4E1F0AE0EB578E91A8E5C3A09CEB52A8C701F0 scripts/probe-videos.php
9468019054D1D393FCC52F28D29799CA63882FE28C52A28452DD27E9041056E6 scripts/render-public-page.php
105C77C16E65DF8A75FD6C7FD65C313A93D56CF3D782CE99787D00B45D799F9D scripts/seed-official-contact-details.php
04E617EC60D73F6A82853890E79286544F8E40ED2E3ECA9F2E7A477D7D7D65AB scripts/survey-course-architecture.php
4AC854F599194ECDF319239D6CB7815FD2A3F3C670D645551B059A9195469D6C scripts/survey-refinement-inputs.php
E82A517F87CA6E1AB52AA02F68F04EB1C18FD4B70367E55D94C5D304556BE826 scripts/verify-refinement-artefacts.php
F137DC9F7D792FCD3DEE5F37FEBF4C84BC68A0287E8C7995D4AD025E7B84D78E scripts/verify-super-admin-live.php
```

## L0 deterministic baseline

Run: `php -d extension=pdo_sqlite scripts/verify-phase11-suite.php`.
Result directory (ignored): storage/logs/phase11-suite-20261007-185256.
3,476 discovered/executed; 3,450 passed; zero failures/errors;
26 existing skips; 31,567 assertions; zero duplicates/missing/unexpected.
All eight fresh-process shards exited 0. The same 26 skip identities are retained
for the L1 comparison (five CourseOfferingAcceptancePreparationTest and
21 LiveClassModuleTest cases).

## L1 focused verification

- CORS: 19 passed, 56 assertions, including nine old/new response parity cases.
- API/Sanctum: 13 passed, 55 assertions; the original token schema is unchanged.
- Authentication: 44 passed, 269 assertions across web roles and applicant guard.
- Combined security/auth/tenant/RBAC selection: 312 passed, 7,350 assertions.
- Combined application selection: 392 passed, 2,565 assertions. This includes
  PesaPalServiceTest (84/524), PDF security compatibility (4/22), Jitsi (7/32),
  critical exam selection (84/970), and other payment/admissions cases (213/1,017).
- Node/browser: 27 browser tests plus 14 standalone revision tests passed;
  editor bridge recorded 24 passing assertions. No skipped or failing JS cases.
- Deployment: all nine sandbox suites and shell syntax checks passed in the
  existing piie-review:8.3 Linux container. Repository bind mount was read-only
  and networking disabled. Migration scanner: six passed, zero failed.
- Git Bash/NTFS is not a valid POSIX permission-test environment: its initial
  sandbox invocation failed permission/symlink checks. The unchanged suites
  were rerun on Linux; no script or assertion was weakened.

JUnit/logs are ignored artifacts under storage/logs/l1-*.

## L1 final deterministic inventory and scope

Result directory: storage/logs/phase11-suite-20261007-193056.
3,547 discovered/executed; 3,521 passed; zero failures/errors/warnings;
26 skipped; 31,919 assertions; zero duplicates/missing/unexpected.
All nine fresh-process shards exited 0. Independent JUnit comparison confirms
the same 26 skip identities AND reasons, and no missing L0 test cases.
The 71 additional cases are API/Sanctum (8), authentication (44) and CORS (19).

Final audit: exit 1, five laravel/framework records only, unchanged from L0.
Installed/locked versions and both Composer hashes remain identical to L0.
The original 27 untracked-file hashes match. HEAD and origin/main remain frozen.

Intentional files: app/Http/Kernel.php, tests/Feature/ApiAuthenticationTest.php,
tests/Feature/CorsCompatibilityTest.php,
tests/Feature/AuthenticationCompatibilityTest.php and this document.
Only the kernel middleware registration changes production code.
No migration, dependency, payment/PesaPal, admissions, exam, live-class,
PDF/JWT or CI implementation changes. No production/persistent database changes.
Nothing staged, committed, pushed, merged or deployed. L2 is not started.

Ready to commit L1: YES, subject to approval.
Ready to start L2: YES, subject to separate approval of its proposed scope.
Ready to upgrade Laravel / merge Phase 2.2 / start PesaPal Phase 2.3 / deploy: NO.
