# Live Classes resumed audit — 29 October 2026

## Recovered work

The interrupted `audit/live-class-e2e` worktree was based on production/main
`67be85e70349287f58ffeb4bfaafabccb9e953ff`. Its twelve modified files and
untracked revision migration were preserved and continued. The main workspace
and all other worktrees were left intact. The previous session's final local
log recorded 381 tests, 2,157 assertions and 21 skipped tests.

Recovered fixes cover notification revisions when a schedule returns to an old
time, duration/detail edit announcements, atomic reminder claims, schedule-aware
reminder deduplication, timezone display, truthful closing-window text, and
removing provider URLs from legacy reminder notices. The institution's existing
15-minute lead and closing grace periods remain unchanged.

## Additional work

Email previously caught transport failures without retaining a retry record.
Live Classes now persists encrypted per-recipient email records alongside in-app
delivery and event claims. Transport runs after commit; cron retries pending
records every five minutes, with a ten-minute claim lease and at most five
attempts. A unique class/recipient/event key suppresses ordinary duplicates.
Retries recheck tenant, student role, registration, email address, class status
and schedule/detail revision; obsolete messages are discarded. Delivered or
discarded payloads are erased. SMTP acknowledgement is not proof of inbox
receipt; a crash after SMTP accepts mail but before the database records success
can still cause a duplicate on retry. This is an explicit at-least-once limit.

The existing Google Meet selection fix from PR #125 (commit `3b1d90f`) was reused,
preserving its author. Saved OAuth credentials allow lecturers to select Google
Meet and connect their own calendar; disabled setup and institution ownership
remain enforced. No provider credentials or institutional policy were changed.

The additive migration adds two revision columns and the email delivery table.
Its rollback deliberately retains notification identity/history. Activation must
run migrations; code-only activation is unsuitable. Existing release backups and
code rollback remain the recovery mechanism.

## Verification and limits

Controlled-clock request tests check immediate class details without meeting
secrets, exact opening/closing boundaries, confirmed versus unconfirmed and
foreign-tenant students, and authorized Jitsi redirects. Existing suites cover
lecturer allocations, Meet Now, Google Calendar creation/pending/failure,
participant/moderator JWTs, direct routes, materials, recordings and completion.
Provider HTTP calls are faked; these tests do not establish actual audiovisual
meeting operation or actual email receipt.

The recovered code plus retries passed the 66-test notification/reminder suite
(299 assertions). The broader 453-test run found one missing retry-table fixture
in the timezone email test; that fixture was corrected and all 14 timezone tests
then passed (57 assertions). Full CI is the authoritative integrated gate,
including MySQL migrations and the tests skipped without a migrated database.
Local browser-suite execution lacks jsdom; the existing Node CI installs locked
dependencies and must pass before merge.

Protected diagnostics now reports Live Classes route/schedule presence, revision
schema, aggregate outbox state, SMTP field presence, Google setup/grant presence,
institution zones, queue connection and Jitsi app-id presence. It prints no
meeting URLs, student identifiers, tokens or SMTP credentials and sends no mail.
Presence of configuration is not a successful provider connectivity check.

## External provider boundary

PIIE releases protected meeting access only after backend authorization and the
existing joining window. It cannot recall an external URL already copied or
shared, or impose its clock on a direct provider visit. Google Meet access
depends on the host's Open/Trusted/Restricted and host-first controls
([Google documentation](https://support.google.com/meet/answer/9302870)). Public
Jitsi requires the first participant to authenticate or wait for a moderator
([Jitsi documentation](https://jitsi.github.io/handbook/docs/user-guide/user-guide-start-a-jitsi-meeting/));
room passwords, lobby and server authentication are provider controls
([Jitsi security](https://jitsi.org/security/)). PIIE's participant JWTs do not
establish that a separately operated server enforces the same joining window.
No external early-entry restriction is claimed to have been independently tested.

## Release gates

Main uses active ruleset `26058271`: PR required, no force pushes/deletion, strict
required checks for PHP/MySQL, Node, Composer audit, deployment sandboxes and
release artifact. Production requires a reviewer and the main branch policy.
The initial protected diagnostic run is
https://github.com/manirakiza12/PIIE/actions/runs/40874604060.
No test notifications or synthetic classes may be created in production.
