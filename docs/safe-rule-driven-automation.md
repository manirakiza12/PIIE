# Safe automation after acceptance

Baseline: `main` at `2f8340d` (28 October 2026). The read-only automation audit was supplied in the task conversation. Its findings were checked against the source before implementation. This branch is for review only; no production operations, merge or deployment were performed.

## Existing automation retained

| Recommendation | Existing authority / implementation | Decision |
| --- | --- | --- |
| Payment reconciliation | `ApplicantPesaPalPayment`, `TuitionPesaPalPayment`, `ApplicationPaymentSettlement`, `TuitionFeeSettlement`, provider credential/identity validation; applicant reconciliation schedule | Preserve. Provider status is fetched server-side; stored merchant reference, tracking identity, tenant credentials, currency, amount and final status govern settlement. No payment is created or marked paid by the new preparation path. |
| Reminders and retries | Existing entries in `app/Console/Kernel.php`: application reconciliation, applicant notification retries, invoice email retries, Live Class reminders/link refresh, exam reminders/result emails and portal-message reminders | Preserve existing commands, timing, delivery histories, bounded retry rules and preferences. Account activation uses the encrypted `ApplicantNotificationDelivery` outbox; invoice creation uses the existing opt-in `InvoiceEmail` hook. No duplicate reminder jobs added. |
| Course enrolment and rosters | `AutomaticCourseEnrolment`, `CourseOfferingRoster`, `LecturerCourseOfferingAccess::teachingRoster` | Preserve existing eligibility, electives, offerings and confirmed-registration rosters. No group membership or historical registration is created or modified. |
| Approved lecturer reuse | Existing active lecturer allocation for the **same** offering, role and effective dates; `LiveClassAccessService::activeManagerAllocationsForOffering` and `LecturerCourseOfferingAccess` | Preserve valid existing reuse. Do not activate a planned appointment, extend an expired one, infer appointment from a course unit, or copy an appointment to a different offering. Cross-delivery carry-forward approval is absent. |
| Exam results | `OnlineExam::isResultVisibleFor` requires explicit publication; the model explains that `immediate` controls when an administrator may publish, not automatic publication | Preserve human publication. A per-exam timing value is not an institutional authorization for unattended release. |

## Implemented gaps

An authorized **accept** transition now prepares the canonical student account and profile using `StudentProvisioningService`. The application stays accepted. No curriculum assignment, Programme Year, academic progression, teaching/student/lecturer group, or academic enrolment is inferred. Registration numbers and password setup use the existing services. The activation outbox is recorded only on account creation; retries do not reset credentials or repeat the initial activation.

`admissions.automation_student_id` records the account identity established by preparation. Existing linked applicant identity can be reused only in the same tenant with the same compatible profile. An unlinked account with the same email is an exception, including a same-school account: email alone does not authorize identity attachment. A disabled or incompatible account, foreign applicant, changed programme/intake or transferred profile stops preparation without rewriting those records. Historical enrolled applications without this new link retain their existing flow.

Mandatory invoice preparation uses Finance-owned mandatory Fee Structures, not catalogue tuition, payment claims or an inferred fee. It requires an active student/profile programme and the institution's active current Finance Session. An explicit academic-year mapping must match the current academic year. Class fees, other programmes/sessions/tenants and optional fees are excluded. All applicable fee records are checked before creating an invoice. Missing fees, invalid/nonpositive amounts and ambiguous semester billing stop the invoice item and identify Finance as owner. `per_semester` lacks an approved semester billing key, so those obligations remain Finance-prepared rather than being guessed from a session.

The existing invoice generator is reused with its fee/session duplicate and legacy-title checks. New invoices are **unpaid**, with zero paid amount. Existing invoices, payments, awards and balances are preserved. The school, application, account/profile, Finance Session and fee records are locked during preparation; account/invoice writes and outbox records roll back together on an unexpected failure. Blockers in invoice configuration can leave the independently valid account prepared, with no invoice writes. Newly prepared accounts use this guarded path on Fee Manager as well, preventing the older backfill from bypassing a queued Finance blocker. A later manual enrolment preserves their profile and does not invoke unguarded auto-billing.

One new non-overlapping command runs every five minutes. It retries accepted applications and the invoices of already-linked enrolled applications when Finance setup arrives later. It chunks all matching records, rather than repeatedly processing only the first page. Existing scheduler entries remain unchanged. No transfer, curriculum approval, Finance discretion, staff authorization, subjective marking, payroll, leave or hostel rule is changed.

## Exceptions and unsupported recommendations

The durable `automation_exceptions` queue is unique by institution, workflow and application (or policy). It retains first/last observation, occurrence count, resolution time, reason and responsible office. Changes and resolutions are audited. Repeated runs update the same item. An exception resolves only after business data revalidation succeeds; resolving it never itself authorizes a business action.

Account/invoice blockers appear on the existing, authorized, tenant-scoped admissions review screen. Authorized server operators can list the full institution queue and its resolved history:

```sh
php artisan automation:exceptions --school=1
php artisan automation:exceptions --school=1 --history
```

The preparation command writes data and sends only the existing approved notification types after commit. Use it only in an authorized environment after review and migration:

```sh
php artisan admissions:prepare-accepted-students --school=1
```

| Item skipped | Required approved data / policy | Responsible office |
| --- | --- | --- |
| Calendar drafts | Approved template records with explicit dates and version/approval provenance | Academic Registrar |
| Timetable drafts | Approved delivery dates, times, rooms, lecturer coverage and conflict rules in a template | Academic Registrar |
| Live Class drafts | Approved dated meeting template, provider configuration and target-offering lecturer authorization | Academic Registrar / ICT |
| Attendance drafts | Approved attendance-session template and real scheduled session; present/absent values must come from actual authorized attendance evidence | Academic Registrar |
| Lecturer carry-forward | Explicit approval naming target delivery and full effective date range; a previous appointment is not approval | Academic Registrar / HOD |
| Automatic result publication | Approved policy defining completeness, objective-only eligibility, integrity/proctoring flags, timing, approver authority and correction/withdrawal handling | Examinations Officer / Academic Board |
| Semester-specific invoicing | Explicit semester-to-invoice obligation and duplicate key, approved fee applicability and Finance Session mapping | Finance / Bursar |

No approved template repository or carry-forward approval exists in the inspected schema. These policy gaps are recorded per institution by the preparation command. No drafts or fabricated calendar dates, rooms, meetings, lecturer grants, attendance values or results are created. Conflict detection for generated drafts cannot safely run without an approved template and target data; missing inputs are the exception instead. Adding an approved template or publication policy later requires a reviewed implementation, not merely clearing the queue.

## Installation and validation

The additive migration `2026_10_28_190000_create_safe_automation_state.php` creates the identity link and exception queue. Until migrated, the new preparation command fails clearly and the acceptance hook leaves the existing flow intact. Existing activation and invoice-email migrations remain prerequisites for durable delivery history. No migration was run against production data. Rollback removes automation metadata only; it does not delete student accounts, profiles, invoices, notifications or audit history. Previously created business records must be reviewed normally.

Tests use disposable in-memory SQLite, fake mail and existing isolated fixtures. They cover authorized acceptance, permissions, retries, initial notification deduplication, late Finance setup, tenant/session/year boundaries, semester/amount ambiguity, optional/class/foreign fee exclusion, legacy invoice preservation, transferred profiles, stale payment caches, failed-write rollback, exception deduplication and tenant-scoped visibility. The configured CI uses disposable MySQL; SQLite tests do not establish concurrent MySQL lock behavior. No production payment, email, student or exam was exercised.

Local checks:

- Full PHP suite run: **4,753 tests, 40,995 assertions, 0 errors, 0 failures, 26 skipped**, verified from the JUnit report. Runtime was 26 minutes 35 seconds on PHP 8.3.35 / Windows. PowerShell also recorded an error record for the suite's existing debug output on stderr; the JUnit counters and PHPUnit summary show no failed tests. The final focused rerun below covers the later Fee Manager/inactive-profile guards and additional tests; the full suite was not repeated after those final additions.
- Final new regression suite: **19 tests, 101 assertions, passing**. This includes a final inactive-profile guard and the Fee Manager bypass regression.
- Initial related regression run: **75 tests, 327 assertions, passing** (acceptance/conversion, existing fee generation, application payment gates, admissions review and invoice emails).
- JavaScript: **76 browser/DOM tests passed** across the initial run and the isolated Chrome rerun; **14 standalone exam-revision tests passed**. The sandboxed real-Chrome test initially timed out; it passed after the browser-launch permission was granted.
- Route/template/source audit: **1,551 routes, 817 templates**, no invalid actions, active missing routes, missing parameters, duplicate route names or syntax errors. PHP test-file syntax, shell-script syntax and `git diff --check` passed.
- `composer validate --no-check-publish` passed with the existing exact-version constraint warning. No PHP/JavaScript lint or type-check script is configured beyond the route/source syntax audit; no type checker was invented for this change.
- Python deployment asset checks: **3 passing**.
- All **12** existing deployment shell suites were attempted in isolated temporary fixtures. After retrying outside filesystem sandbox restrictions, **5 passed and 7 failed**. The failing suites are `backup-readiness-sandbox.sh`, `container-preflight-sandbox.sh`, `container-runtime.sh`, `preflight-uploads-sandbox.sh`, `sandbox.sh`, `security-hardening-sandbox.sh` and `uploads-integration-sandbox.sh`. The logs include Windows-incompatible Unix mode/group/setgid assertions and container fixture checks. These unchanged scripts must be checked on the configured Linux CI host; they are not reported as green. No deployment was attempted.

Review artifacts are retained locally under `output/`: `safe-automation-full.xml`, `safe-automation-full.log`, `safe-automation-targeted.log`, `safe-automation-routes.log`, `safe-automation-deploy-tests.log` and the individual shell-suite logs. No dependency lockfile, deployment script or CI workflow changed. GitHub authentication must be restored before pushing this branch and creating the PR.
