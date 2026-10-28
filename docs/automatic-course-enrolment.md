# Automatic course enrolment

## Verified release and remaining root cause

Protected activation run #218 verified active `RELEASE_SHA`
`660bf9690fb02eec2a2b346e9698faf5c1de74a4`, public smoke checks and enrolment
reconciliation successfully on 28 October 2026. Run #217 was staging only, so its
activation-only checks were skipped. Evidence:
https://github.com/manirakiza12/PIIE/actions/runs/40611078672.

That release automatically confirms eligible registrations, but searches only
existing allocations. Opening an academic period has no curriculum-offering
provisioning step. Therefore required units without an allocation or applicability
link remain absent even when a student qualifies financially and academically.
An offering's governed Open transition does not require a lecturer.

`LecturerCourseOfferingAccess::teachingRoster`, allocation attendance, lecture
access and allocation assessments all correctly read confirmed registrations for
the exact school and Course Allocation. Eligibility alone does not populate
these queries. Missing creation/confirmation, rather than a group-roster join,
explains the zero-student path. The activation log preserved one existing
confirmed registration, and separately reported missing placement and programme
mismatch blockers. Those are not automatically corrected. No authenticated BAASP
student/profile inspection was available; the BAASP Year 1 Semester 1 test is an
explicit synthetic reproduction, not a claim about a particular live student.

## Behaviour

Opening a semester or selecting an already active semester as current prepares
required teaching offerings from approved, effective curriculum entries matching
that period's type and sequence. Archived entries and years are excluded. The
same operation precedes scheduled enrolment, so already opened semesters are
backfilled without staff creating each Course Allocation. This academic setup
also runs when there are no eligible students yet.

Existing active offerings are reused, including missing curriculum links. An
existing draft receives valid required links and is opened through the governed
lifecycle before enrolment. Completed/cancelled offerings are preserved and never
reopened or automatically replaced. Parallel active deliveries without an
unambiguous existing link are reported for review. Shared required units reuse
one offering across approved curricula. The school lock serializes retries and
manual offering creation; it does not prohibit deliberately governed parallel
deliveries.

Missing current lecturers are flagged in the staff allocation list and JSON
decisions. Lecturer assignment is independent and never blocks student enrolment.
No staff member is silently assigned. A later assigned lecturer sees the existing
confirmed students and counts through the normal allocation roster.

The scheduler runs `courses:reconcile-enrolment --apply` every minute, without
overlap. Each student transaction rechecks the school's current active academic
year and period, date bounds, governed programme placement, approved curriculum,
programme year and curriculum period applicability. It uses existing allocations
in Open or In Progress state; Draft, Cancelled, Completed and other periods are
excluded. There is no allocation registration-cutoff field or course cutoff
policy in this release; the intake admission deadline is not a course cutoff.

The existing `StudentRegistrationConfirmationEligibility` calculation is reused:
current Finance Session invoice balances, the same rounded-up 20% threshold,
verified paid balances, approved net invoice awards and active study waivers.
Automatic enrolment scopes invoices to the placed programme plus institution-wide
invoices. It does not calculate fees from a new formula, create invoices, post
payments, approve claims, or alter Fee Structures. A Finance Session explicitly
mapped to another academic year is refused. Pending setup requests remain claims,
not payment or award evidence. Full scholarships qualify through Finance's
approved award and zero net invoice balance; partial awards retain the existing
20% rule on the adjusted invoice.

Required curriculum units receive confirmed registrations automatically. Electives
retain explicit choice through the existing registration process; an existing,
valid pending elective registration can be confirmed automatically. Parallel
allocations without an existing choice are reported for academic review, never
guessed. Elective offerings are not automatically selected or provisioned. No
group assignment is consulted.

School/student/allocation locks and the existing school/student/allocation unique
key serialize retries. Confirmed, dropped/withdrawn and historical records are
preserved. Only matching pending records in the current period can be confirmed;
invalid academic snapshots are reported for review. Each write is audited with
`COURSE_REGISTRATION_AUTO_CONFIRMED` and source `automatic_eligibility`.

## Safe reconciliation and release

Preview is the default; JSON lines identify offering decisions by programme,
programme year, period and unit code, plus internal IDs and decision codes. Blocked
placement decisions include tenant-scoped profile/placement programme codes for
review. No student names or payment details are printed:

```sh
php artisan courses:reconcile-enrolment --school=1 --json
php artisan courses:reconcile-enrolment --school=1 --apply --json
php artisan courses:reconcile-enrolment --school=1 --json
```

Review `blocked` reasons such as missing placement, finance ineligibility or
parallel allocations. Preview writes neither registrations nor audit records.
Applying prepares required offerings and writes eligible current-period
registrations and their audit trail. A `--student` filter limits registrations;
offering preparation remains period-wide within that student's institution.
The post-apply preview should have no `would_create`, `would_open`, `would_link`
or `would_enrol` rows for unchanged inputs.
Newly eligible students will be picked up by the next scheduler run.

The existing protected deployment workflow retains its CI, main-branch,
confirmation, production-environment review and backup gates. Following activation,
SHA verification and public smoke checks, it invokes the same preview/apply/preview
reconciliation script. The deployment log records decisions and idempotence checks.
There are no new migrations. Reverting code does not remove valid registrations.

## Manual checks after activation

1. Choose a student with an active placement matching the current academic year,
   programme year and semester in an approved curriculum. Ensure required units
   are present in the approved curriculum. Open the semester or select it as
   current; missing required offerings should appear automatically as Open.
   A missing lecturer should be flagged while enrolment still proceeds.
2. With verified payment below 20%, confirm the student has no new registrations.
   Submit a pending payment or award claim: it must still not enrol them.
3. Finance verifies payment to exactly 20%, or an authorised administrator grants
   a study waiver or qualifying scholarship through the existing finance screen.
   Wait up to one minute and refresh Student Course Registration: applicable
   required units must be Confirmed without selecting the student or Register.
4. Sign in as the allocated lecturer. Verify the student under My Course
   Allocations → Students, in an editable attendance session, and in the relevant
   published lecture and assessment audience. The student should be able to open
   their allocated learning content.
5. Refresh and run reconciliation again: counts must not increase. A withdrawn
   registration, a different programme year/semester and a completed historical
   allocation must stay unchanged. Electives without an explicit choice must not
   be silently selected.
