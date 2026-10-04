<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\User;
use App\Support\CourseExams\CourseOfferingAssessments;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\OnlineExams\OnlineExamRecipients;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * WHO GETS TOLD ABOUT AN EXAM.
 *
 * ── THE DEFECT THIS PINS ───────────────────────────────────────────────────
 *
 * The report was "students were not receiving Online Exams". Tracing it found a
 * leak that the visible symptom pointed away from.
 *
 * `OnlineExamPortalNotifier::eligibleStudents()` and
 * `OnlineExamAnnouncementNotifier::eligibleStudents()` each narrowed recipients by
 * `class_id` alone:
 *
 *     if ($exam->class_id) $query->whereExists(... enrollment ...);
 *
 * A Course Offering exam has `class_id = NULL` BY DESIGN, so the narrowing never
 * ran and both degraded to "every student in the school". Simulated against the
 * real database, publishing an exam on Offering 5 would have notified 4 students
 * when only 3 are confirmed on it - including "Fag alex", who is confirmed on
 * nothing.
 *
 * That is the same class-only blind spot that made exam VISIBILITY school-wide and
 * was fixed for the pages. The notification side had never been joined to it, so a
 * page that correctly showed a student nothing would still have emailed them that
 * an exam was available. The leak is worse than the original symptom: the student
 * is left believing they have work to do.
 *
 * ── WHAT IS ASSERTED, AND WHY EACH HALF MATTERS ────────────────────────────
 *
 *   - an OFFERING exam reaches only confirmed students, and never a student
 *     confirmed on a DIFFERENT offering, nor one whose registration is merely
 *     `registered` or `dropped`;
 *   - a LEGACY class exam keeps its enrolment rule exactly;
 *   - a LEGACY school-wide exam (class_id NULL, offering NULL) is STILL school-wide,
 *     because nine live exams depend on that and §13 forbids changing it;
 *   - the notifier and the exam's own visibility gate agree, student for student -
 *     so a notification can never describe an exam that page refuses.
 */
class OnlineExamRecipientsTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
        $this->assertFixtureTablesMatchProduction();
    }

    private function examFor(array $overrides = []): OnlineExam
    {
        $id = $this->makeExam(array_merge([
            'school_id' => 1,
            'workflow_state' => 'published',
            'is_published' => 1,
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
            'created_by' => null,
            'creator_id' => null,
        ], $overrides));

        return OnlineExam::query()->findOrFail($id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE OFFERING ARM
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_OFFERING_exam_reaches_ONLY_confirmed_students_of_THAT_offering(): void
    {
        $offering = $this->makeOffering(1, ['reference' => 'REcipients-2026-S1']);

        $confirmed = $this->makeUser(7, 1, 'active', 'Confirmed Student');
        $this->confirmStudent($offering, $confirmed);

        // Registered but NOT confirmed - the state the eligibility rules refuse.
        $pending = $this->makeUser(7, 1, 'active', 'Registered Only');
        $this->confirmStudent($offering, $pending, 'registered');

        // Dropped.
        $dropped = $this->makeUser(7, 1, 'active', 'Dropped Student');
        $this->confirmStudent($offering, $dropped, 'dropped');

        // Confirmed on a DIFFERENT offering entirely.
        $other = $this->makeOffering(1, ['reference' => 'OTHER-2026-S1']);
        $elsewhere = $this->makeUser(7, 1, 'active', 'Confirmed Elsewhere');
        $this->confirmStudent($other, $elsewhere);

        // Not on any course.
        $nowhere = $this->makeUser(7, 1, 'active', 'No Courses');

        $exam = $this->examFor(['course_offering_id' => $offering->id]);

        $recipients = OnlineExamRecipients::forExam($exam);

        $this->assertContains($confirmed->id, $recipients, 'a confirmed student must be reached');
        $this->assertNotContains($pending->id, $recipients, 'a merely REGISTERED student must not be reached');
        $this->assertNotContains($dropped->id, $recipients, 'a dropped student must not be reached');
        $this->assertNotContains($elsewhere->id, $recipients, 'a student confirmed on ANOTHER offering must not be reached');
        $this->assertNotContains($nowhere->id, $recipients, 'a student on no course must not be reached');

        $this->assertCount(1, $recipients, 'exactly one of the five is eligible');
    }

    public function test_an_OFFERING_exam_never_reaches_ACROSS_a_tenant(): void
    {
        $theirs = $this->otherInstitution();

        $exam = $this->examFor([
            'school_id' => 1,
            'course_offering_id' => $this->makeOffering(1, ['reference' => 'MINE'])->id,
        ]);

        $this->assertNotContains(
            $theirs['student']->id,
            OnlineExamRecipients::forExam($exam),
            'another institution\'s student must never be reached'
        );
    }

    public function test_an_OFFERING_exam_reaches_nobody_when_the_REGISTRATION_table_is_absent(): void
    {
        // Fail CLOSED. An absent table must never widen who is contacted - the
        // failure mode of "cannot check, so contact everyone" is exactly the leak
        // this class exists to close.
        $offering = $this->makeOffering(1, ['reference' => 'NO-REG-TABLE']);
        $student = $this->makeUser(7, 1, 'active', 'Would Be Eligible');
        $this->confirmStudent($offering, $student);

        $exam = $this->examFor(['course_offering_id' => $offering->id]);

        $this->assertCount(1, OnlineExamRecipients::forExam($exam), 'precondition: reachable while the table exists');

        \Illuminate\Support\Facades\Schema::drop('course_registrations');

        $this->assertSame(
            [],
            OnlineExamRecipients::forExam($exam),
            'with no registration table, nobody may be contacted'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE LEGACY ARMS ARE UNCHANGED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LEGACY_class_exam_still_uses_ENROLMENT(): void
    {
        $classId = $this->makeClass(1);

        $inClass = $this->makeUser(7, 1, 'active', 'In The Class');
        $this->enrollStudent($inClass->id, 1, $classId);

        $notInClass = $this->makeUser(7, 1, 'active', 'Not In The Class');

        $exam = $this->examFor(['class_id' => $classId]);

        $recipients = OnlineExamRecipients::forExam($exam);

        $this->assertContains($inClass->id, $recipients);
        $this->assertNotContains($notInClass->id, $recipients);
    }

    public function test_a_LEGACY_SCHOOL_WIDE_exam_is_STILL_school_wide(): void
    {
        // `class_id` NULL and `course_offering_id` NULL has always meant "every
        // student in the school", and nine live exams depend on it. §13 forbids
        // changing that meaning to accommodate Course Offerings - so it is pinned.
        $a = $this->makeUser(7, 1, 'active', 'Student One');
        $b = $this->makeUser(7, 1, 'active', 'Student Two');

        $exam = $this->examFor(['class_id' => null, 'course_offering_id' => null]);

        $recipients = OnlineExamRecipients::forExam($exam);

        $this->assertContains($a->id, $recipients);
        $this->assertContains($b->id, $recipients, 'a school-wide legacy exam must remain school-wide');
    }

    public function test_a_LEGACY_exam_never_reaches_ACROSS_a_tenant(): void
    {
        $theirs = $this->otherInstitution();

        $exam = $this->examFor(['class_id' => null, 'course_offering_id' => null, 'school_id' => 1]);

        $this->assertNotContains($theirs['student']->id, OnlineExamRecipients::forExam($exam));
    }

    public function test_staff_are_never_recipients_of_a_STUDENT_notification(): void
    {
        $exam = $this->examFor(['class_id' => null, 'course_offering_id' => null]);

        $lecturer = $this->makeUser(3, 1, 'active', 'A Lecturer');

        $this->assertNotContains(
            $lecturer->id,
            OnlineExamRecipients::forExam($exam),
            'a school-wide EXAM notification is for students, not for staff'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. NOTIFICATION AND VISIBILITY MUST AGREE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE NOTIFIER AND THE PAGE MUST ANSWER THE SAME QUESTION.
     *
     * This is the property whose absence caused the defect: eligibility was fixed
     * on the pages and left alone in the notifiers, so a student could be shown
     * nothing and emailed something. Asserted by comparing the two sets directly -
     * if a future change moves either one, this fails.
     */
    public function test_the_NOTIFIER_and_the_VISIBILITY_GATE_agree_student_for_student(): void
    {
        $offering = $this->makeOffering(1, ['reference' => 'AGREE-2026-S1']);

        $confirmed = $this->makeUser(7, 1, 'active', 'Confirmed Here');
        $this->confirmStudent($offering, $confirmed);

        $elsewhere = $this->makeUser(7, 1, 'active', 'Confirmed There');
        $this->confirmStudent($this->makeOffering(1, ['reference' => 'THERE-2026-S1']), $elsewhere);

        $nowhere = $this->makeUser(7, 1, 'active', 'Confirmed Nowhere');

        $exam = $this->examFor(['course_offering_id' => $offering->id]);
        $exam->questions()->create(['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        $assessments = app(CourseOfferingAssessments::class);

        foreach ([$confirmed, $elsewhere, $nowhere] as $student) {
            $notified = in_array($student->id, OnlineExamRecipients::forExam($exam), true);
            $canSee = $assessments->forStudent($student, $offering) !== [];

            $this->assertSame(
                $notified,
                $canSee,
                "notified and visible must agree for [{$student->name}] - a student must never be "
                    .'emailed an exam they cannot open, nor left unaware of one they can'
            );
        }
    }

    public function test_the_single_student_form_agrees_with_the_list(): void
    {
        // `includes()` must not be a second query with its own idea of eligibility,
        // or the two forms could diverge - which is how the original defect was
        // possible in the first place.
        $offering = $this->makeOffering(1, ['reference' => 'SINGLE-2026-S1']);
        $confirmed = $this->makeUser(7, 1, 'active', 'In The Set');
        $this->confirmStudent($offering, $confirmed);
        $outsider = $this->makeUser(7, 1, 'active', 'Not In The Set');

        $exam = $this->examFor(['course_offering_id' => $offering->id]);

        $this->assertTrue(OnlineExamRecipients::includes($exam, $confirmed->id));
        $this->assertFalse(OnlineExamRecipients::includes($exam, $outsider->id));

        $this->assertSame(
            OnlineExamRecipients::countForExam($exam),
            count(OnlineExamRecipients::forExam($exam)),
            'the count must report the set it resolves'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. THE NOTIFIERS THEMSELVES, THROUGH THEIR OWN ENTRY POINTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_portal_notifier_writes_to_confirmed_students_ONLY(): void
    {
        $offering = $this->makeOffering(1, ['reference' => 'NOTIFY-2026-S1']);

        $confirmed = $this->makeUser(7, 1, 'active', 'Confirmed Student');
        $this->confirmStudent($offering, $confirmed);

        $outsider = $this->makeUser(7, 1, 'active', 'Unrelated Student');

        $exam = $this->examFor(['course_offering_id' => $offering->id]);

        \App\Support\OnlineExams\OnlineExamPortalNotifier::eligibleStudents(
            $exam,
            'exam_published',
            'Exam Available',
            'An assessment is available.'
        );

        $notified = DB::table('online_exam_user_notifications')
            ->where('online_exam_id', $exam->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($confirmed->id, $notified, 'the confirmed student must be notified');
        $this->assertNotContains(
            $outsider->id,
            $notified,
            'an unrelated student must NOT be notified about another course\'s exam'
        );

        $this->assertCount(1, $notified, 'exactly one notification, to the one eligible student');
    }

    public function test_publishing_NOTIFIES_the_same_students_the_PAGE_will_show(): void
    {
        // The end-to-end shape of the defect, as a single assertion: publish, and
        // then ask both questions - "who was told?" and "who can open it?".
        $offering = $this->makeOffering(1, ['reference' => 'E2E-2026-S1']);
        $exam = $this->examFor(['course_offering_id' => $offering->id]);
        $exam->questions()->create(['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        $told = $this->makeUser(7, 1, 'active', 'Told And Can See');
        $this->confirmStudent($offering, $told);

        $notTold = $this->makeUser(7, 1, 'active', 'Not Told And Cannot See');
        $this->confirmStudent($this->makeOffering(1, ['reference' => 'UNRELATED']), $notTold);

        \App\Support\OnlineExams\OnlineExamPortalNotifier::eligibleStudents(
            $exam, 'exam_published', 'Exam Available', 'Available.'
        );

        $notifiedIds = DB::table('online_exam_user_notifications')
            ->where('online_exam_id', $exam->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $assessments = app(CourseOfferingAssessments::class);

        $this->assertContains($told->id, $notifiedIds);
        $this->assertNotEmpty($assessments->forStudent($told, $offering));

        $this->assertNotContains($notTold->id, $notifiedIds);
        $this->assertSame([], $assessments->forStudent($notTold, $offering));
    }
}
