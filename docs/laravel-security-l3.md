# Laravel security migration: L3 local compatibility

Starting checkpoint: `162aeaf99e3f6b4ab0ac5cf8274b52f6f8a209bf` on
`feature/pesapal-api-adapter`. Laravel 9.52.22, Sanctum 2.15.1, PHPUnit
9.6.36 and Carbon 2.73.0 remain installed. Composer files are unchanged.
Carbon 3 is not installed: its signed floating-point difference semantics
are characterized using test-only fixtures and the
[official Carbon 3 implementation](https://github.com/briannesbitt/Carbon/blob/3.11.0/src/Carbon/Traits/Difference.php).
No Laravel 12 runtime verification is claimed at this stage.

## Preserved date policies

`WholeDateIntervals` uses native immutable DateTime intervals to retain
Carbon 2's absolute, whole calendar-unit results. It copies dates, aligns
the target timezone to the source, and never mutates either input. This
deliberately preserves calendar-day behavior across DST rather than
substituting elapsed UTC hours for calendar days. Tests pin integer expectations characterized on Carbon 2 for fractions,
reversed dates, different
timezones, both DST transitions and leap days.

Applicant reset expiry remains `whole absolute minutes > configured
expiry`. With the existing 60-minute setting, exactly 60:00 and 60:59 remain
accepted; 61:00 is rejected. Future timestamps retain the same absolute-age
rule: a future minute is accepted, but a future 61 minutes is rejected.
These existing boundaries are characterized, not redesigned. Carbon 3's
default signed difference would otherwise let past expired tokens bypass
the comparison. The native helper preserves integer rounding and sign
independently of Carbon's version. Token validation, consumption, school
scoping and password handling are unchanged.

Leave duration remains whole calendar days plus one. Same-day, consecutive,
month/leap boundaries and fractional-day inputs are covered. Reversed
requests still fail existing validation and create no leave. Entitlement,
approval and ownership rules are unchanged.

Live-class schedule length remains absolute whole minutes, with NULL when
either boundary is absent. Zoom retains an integer minimum of one minute
and UTC start serialization. Attendance retains whole absolute seconds.
Tests characterize exact/fractional intervals, reversed timestamps and
different timezones. Model fixtures follow the application's existing UTC
storage contract. No Jitsi authorization or Google integration logic is
changed; only identified duration calculations use the helper.

Online-exam signed difference already passes `false` explicitly. Its
integer return could nevertheless trigger an implicit float-to-int
precision error for Carbon 3 fractions. The negative control reproduced
`Implicit conversion from float 1.5 to int loses precision`. An explicit
integer cast fixes only that compatibility issue. Deadline selection,
timeout comparison, publication and marking are unchanged. Tests cover
before, exact, after and fractional-second boundaries; a subsecond-before
countdown remains zero while the attempt is not yet expired, as before.

## Mail, logging and uploads

The compatibility run passed 56 tests / 202 assertions, including existing
SafeMail diagnostics and mail-failure resilience tests. Mail-specific
diagnostics redact synthetic passwords, tokens, URLs, SMTP authentication
material and recipient data. Failed mail delivery retains completed
business actions and records safe diagnostics.

New logging characterization confirms StreamHandler error thresholds,
formatter output, generic exception reporting and protected validation
fields. The general exception handler forwards exception objects unchanged;
it is **not** a global secret-redaction layer. The scoped SafeMail redaction
tests do not imply all arbitrary exceptions are sanitized. Transports,
logging configuration and the exception handler remain unchanged.

Existing image/upload suites cover valid image acceptance, invalid-file
rejection and SVG/active-content security. Production upload paths,
validation rules and storage architecture are unchanged.

## Infrastructure and security

Saved logs: `storage/logs/l3-browser.log`, `l3-exam-revision.log`,
`l3-editor-bridge.log`, `l3-deployment-sandbox.log` and
`l3-composer-audit.json`.

Browser: 27 passed; exam revision: 14 passed; editor bridge: 24 passed.
All nine deployment suites passed in disposable Linux with networking
disabled and source mounted read-only. Migration scanner: six passed,
zero failed. No Windows-specific weakening of scripts was introduced.

Uncached Composer audit exited 1 with five advisory records, all belonging
to `laravel/framework`. No advisories are ignored and no packages updated.

No database migration is added and no persistent database is modified.
Payment/PesaPal production code is unchanged. The original 27 unrelated
files are checked against their recorded hashes. Nothing is staged,
committed, pushed, merged or deployed by L3.

The broader focused regression passed **1,336 tests / 15,051 assertions**,
with no failures, errors or skips (`storage/logs/l3-focused.xml`). It covers
authentication, tenancy, RBAC, payments/admissions, PesaPal, PDFs, Jitsi,
critical exams and uploads/storage. PesaPal: 84 tests / 524 assertions; PDF:
4 / 22; Jitsi: 7 / 32. The compatibility run was repeated after correcting
test-double return signatures and pinning Carbon 2 integer expectations;
its final result remains 56 / 202. The earlier in-progress inventory was
stopped and superseded so final inventory evidence covers corrected sources.

Upload snapshots remain identical: `public/uploads` has zero files;
`public/assets/uploads` has 100 files and content digest
`3baebe54c66bb39cb536573298c8e42dab29d9a6f12b943d1152d39e70171bb4`.
The original 27 unrelated file hashes also match.

The exact intentional file list is saved at
`storage/logs/l3-intentional-files.json`. Final deterministic inventory (`storage/logs/phase11-suite-20261008-021741/summary.json`):
**3,641 discovered and executed; 3,615 passed; zero failures, errors or
warnings; 26 skipped; 32,168 assertions; zero duplicates, missing or
unexpected cases.** All nine shard exit codes are zero. The final skip
identities and recorded reasons exactly match the original L0 baseline.
All 12 intentional PHP file hashes match the sources used for this run.

L3 is ready for a separately approved local checkpoint. L4 requires
separate authorization. No staging, commit, push, merge, deployment or
dependency transition was performed.
