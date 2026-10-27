# Automatic course enrolment

## Root cause (traced before implementation)

On main ecba040, `CourseRegistrationService::registerStudentForOffering` and
`confirmRegistration` are separate, explicitly invoked actions. There is no
automatic registration job. Both actions require an Open allocation, even when
teaching has started. The registration workspace therefore shows eligible
students without creating confirmed registrations.

`LecturerCourseOfferingAccess::teachingRoster`, allocation attendance, lecture
access and allocation assessments all correctly read confirmed registrations for
the exact school and Course Allocation. Eligibility alone does not populate
these queries. Missing creation/confirmation, rather than a group-roster join,
explains the zero-student path. No production student data was inspected during
this source trace.

## Behaviour

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
guessed. Missing allocations are not created. No group assignment is consulted.

School/student/allocation locks and the existing school/student/allocation unique
key serialize retries. Confirmed, dropped/withdrawn and historical records are
preserved. Only matching pending records in the current period can be confirmed;
invalid academic snapshots are reported for review. Each write is audited with
`COURSE_REGISTRATION_AUTO_CONFIRMED` and source `automatic_eligibility`.

## Safe reconciliation and release

Preview is the default; JSON lines contain internal IDs and decision codes, no
student names or payment details:

```sh
php artisan courses:reconcile-enrolment --school=1 --json
php artisan courses:reconcile-enrolment --school=1 --apply --json
php artisan courses:reconcile-enrolment --school=1 --json
```

Review `blocked` reasons such as missing placement, finance ineligibility or
parallel allocations. Preview writes neither registrations nor audit records.
Applying writes only eligible current-period registrations and their audit trail.
The post-apply preview should have no `would_enrol` rows for unchanged inputs.
Newly eligible students will be picked up by the next scheduler run.

The existing protected deployment workflow retains its CI, main-branch,
confirmation, production-environment review and backup gates. Following activation,
SHA verification and public smoke checks, it invokes the same preview/apply/preview
reconciliation script. The deployment log records decisions and idempotence checks.
There are no new migrations. Reverting code does not remove valid registrations.

## Manual checks after activation

1. Choose a student with an active placement matching the current academic year,
   programme year and semester in an approved curriculum. Ensure required units
   have Open or In Progress Course Allocations and assigned lecturers.
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
