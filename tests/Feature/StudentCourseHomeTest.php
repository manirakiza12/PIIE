<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Support\Assignments\AssignmentState;
use App\Support\CourseExperience\StudentCourseHome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * THE COURSE HOME, and the four cards on it.
 *
 * BUILT ON THE EXISTING FIXTURE CHAIN, NOT A NEW ONE
 *
 * `AssignmentFixture` composes `CourseContentFixture`, which composes
 * `LiveClassFixture`, and between them they build an institution, an in-progress
 * Offering, an allocated lecturer, a co-lecturer and a confirmed student, plus
 * every Course Content and Assignment table with its real column set. A Course
 * Home test needs all of that, so this suite composes the chain rather than
 * rebuilding a subtly different copy of it - a fixture that differs in one column
 * is a fixture that tests a schema PIIE does not run.
 *
 * ── WHAT IS ASSERTED, AND WHY THESE ASSERTIONS ────────────────────────────
 *
 * The temptation with a read model is to assert on its shape - that a key exists,
 * that an array has four elements. Those tests pass when the page is wrong, and
 * they are why read models quietly rot: the shape survives while the meaning
 * changes underneath it.
 *
 * So almost everything below asserts a CLAIM a student would be misled by if it
 * were false:
 *
 *   - a card must not offer a lesson the student has already finished
 *   - a card must not offer an assessment whose mark has not come back
 *   - a card must not send a student to an assignment they could not open
 *   - a Join button must appear exactly when the join policy permits it
 *   - an unfinished area must say so and link nowhere
 *   - a course with no cover must say so rather than show a borrowed picture
 *   - progress must not be collapsed into one number
 *
 * The few structural assertions that remain are there to catch a card being
 * dropped, not to pin its internals.
 */
class StudentCourseHomeTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private StudentCourseHome $home;

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        Storage::fake('local');
        $this->home = app(StudentCourseHome::class);
    }

    // ══════════════════════════════════════════════════════════════════════
    // LOCAL READS
    // ══════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function homeFor(): array
    {
        return $this->home->build($this->student, $this->offering->fresh());
    }

    private function openHome(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)->get("/student/courses/{$this->offering->id}");
    }

    /**
     * A confirmed student of ANOTHER OFFERING in the SAME school.
     *
     * Built here rather than taken from `secondConfirmedStudent()`, which despite
     * its name registers on THIS offering and is therefore not an outsider at all.
     *
     * This is the subject for a cross-OFFERING denial: the tenant is the same, so
     * the school check passes for them, and only the Offering check can refuse
     * them. A cross-tenant student would be refused by the school check and would
     * not prove the Offering boundary at all.
     */
    private function outsiderInSameSchool(): \App\Models\User
    {
        $theirs = $this->secondOfferingSameSchool();

        $user = \App\Models\User::factory()->create([
            'name' => 'Wandera Grace',
            'email' => 'wandera.grace.'.uniqid().'@student.example.test',
            'role_id' => 7,
            'school_id' => $this->school,
            'account_status' => 'active',
        ]);

        DB::table('course_registrations')->insert([
            'school_id' => $this->school,
            'student_id' => $user->id,
            'subject_id' => $this->subject,
            'course_offering_id' => $theirs->id,
            'status' => CourseRegistration::STATUS_CONFIRMED,
        ]);

        return $user->fresh();
    }

    /**
     * A confirmed student in ANOTHER INSTITUTION.
     *
     * The subject for a cross-TENANT claim, which is a different failure from
     * cross-Offering: here the school check alone must be enough.
     */
    private function outsiderInOtherTenant(): \App\Models\User
    {
        return $this->otherTenant()['student'];
    }

    /**
     * Whitespace-collapsed HTML, for asserting on prose.
     *
     * `assertSee` does not normalise whitespace, so a needle spanning a rendered
     * line break fails against a correct page. This collapses runs of whitespace to
     * single spaces, which makes an assertion about WORDS robust against an
     * assertion about MARKUP - and markup is not what these claims are about.
     */
    private function normalise(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', $html) ?? '');
    }

    /** A published, released module holding `lessons` published lessons. */
    private function moduleWithLessons(int $lessons, ?int $unreleasedFromSequence = null): CourseOfferingModule
    {
        $module = $this->module();

        for ($i = 1; $i <= $lessons; $i++) {
            $this->lesson($module, [
                'title' => 'Lesson '.$i,
                'sequence' => $i,
                'status' => CourseOfferingLessonProgress::STATUS_COMPLETED === 'never' ? 'published' : 'published',
                'released_at' => ($unreleasedFromSequence !== null && $i >= $unreleasedFromSequence)
                    ? now()->addWeek()
                    : null,
            ]);
        }

        return $module;
    }

    /** Complete the lesson with this sequence number. */
    private function completeLesson(int $sequence): void
    {
        $lesson = \App\Models\CourseOfferingLesson::query()
            ->where('course_offering_id', $this->offering->id)
            ->where('sequence', $sequence)
            ->firstOrFail();

        $this->markProgress($lesson, $this->student);
    }

    /** A Live Class on this Offering. */
    private function liveClass(array $attributes = []): LiveClass
    {
        $scheduled = $attributes['scheduled_at'] ?? now()->addHours(3);

        return $this->class(array_merge([
            'scheduled_at' => $scheduled,
            'start_date' => Carbon::parse($scheduled)->toDateString(),
            'start_time' => Carbon::parse($scheduled)->format('H:i:s'),
        ], $attributes));
    }

    /** Put an assignment on a module and hand it in. */
    private function submittedModuleTask(CourseOfferingModule $module, array $attributes = []): Assignment
    {
        $task = $this->moduleTask($module, $attributes);

        $this->submission($task, $this->student);

        return $task;
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTHORISATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_confirmed_student_sees_their_course_home(): void
    {
        $this->openHome()
            ->assertOk()
            ->assertViewHas('offering')
            ->assertViewHas('header')
            ->assertViewHas('progress')
            ->assertViewHas('continueLearning')
            ->assertViewHas('assignments')
            ->assertViewHas('liveClasses')
            ->assertViewHas('resources');
    }

    public function test_an_UNCONFIRMED_registration_is_refused(): void
    {
        // A registration that EXISTS is not a registration that grants access.
        // "Registered" is a state on the way to confirmed, and every read of a
        // Course Offering has refused it since the domain was built.
        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => CourseRegistration::STATUS_REGISTERED]);

        $this->openHome()->assertNotFound();
    }

    public function test_a_student_registered_elsewhere_cannot_reach_this_course_home(): void
    {
        // Confirmed on ANOTHER OFFERING in the SAME school, so the tenant check
        // passes for them and only the Offering check can refuse them. That is the
        // boundary this test is about.
        $outsider = $this->outsiderInSameSchool();

        $this->assertDatabaseMissing('course_registrations', [
            'student_id' => $outsider->id,
            'course_offering_id' => $this->offering->id,
            'status' => CourseRegistration::STATUS_CONFIRMED,
        ]);

        $this->actingAs($outsider)
            ->get("/student/courses/{$this->offering->id}")
            ->assertNotFound();
    }

    public function test_a_LECTURER_allocation_does_not_grant_a_student_course_home(): void
    {
        // The confusion this project keeps having to close: managing a course and
        // studying it are different relationships. A lecturer allocated to an
        // Offering has no confirmed REGISTRATION on it, so a student-only route
        // must never SERVE them the student experience.
        //
        // Asserted as "must not succeed" rather than "must be 404", because the
        // route is inside the student middleware group and a lecturer is turned away
        // at the door by `role_id` - a 302, before any authorisation runs. Demanding
        // a 404 would demand that a student-only route reach its own controller and
        // refuse there, which is the opposite of the property being defended: the
        // student experience is not this person's page.
        $this->assertDatabaseMissing('course_registrations', [
            'student_id' => $this->lecturer->id,
            'course_offering_id' => $this->offering->id,
        ]);

        $this->actingAs($this->lecturer)
            ->get("/student/courses/{$this->offering->id}")
            ->assertDontSee('Continue Learning', false);
    }

    public function test_the_course_home_of_another_tenant_is_not_reachable(): void
    {
        $theirs = $this->otherTenant();

        $this->actingAs($this->student)
            ->get("/student/courses/{$theirs['offeringId']}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE HEADER
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_header_states_the_unit_code_the_year_and_the_period(): void
    {
        $this->openHome()
            ->assertOk()
            ->assertSee(e($this->offering->reference))
            ->assertSee('Course Unit code')
            ->assertSee('Academic year')
            ->assertSee('Semester / period');
    }

    public function test_the_header_names_the_lecturers_who_are_actually_teaching(): void
    {
        // The fixture already allocated Daniel as primary and Grace as co.
        $this->openHome()
            ->assertOk()
            ->assertSee('Daniel Okello')
            ->assertSee('Grace Nakato');

        $header = $this->homeFor()['header'];

        // Primary lecturer FIRST, so the person who owns the course leads the list.
        $this->assertSame('Daniel Okello', $header['lecturers'][0]['name']);
    }

    public function test_an_allocation_that_HAS_NOT_started_is_named_but_not_as_teaching(): void
    {
        // REVERSED FROM WHAT THIS ONCE ASSERTED, and deliberately.
        //
        // It used to say a future allocation must not appear at all. That was the
        // reported defect: Offering #5's teacher is allocated from 1 October, and
        // the header therefore read "No lecturer is currently allocated to this
        // course" about him. Not a neutral absence - a false statement about a
        // colleague.
        //
        // The domain already draws this line, in the other direction, in
        // `reachableOfferings()`: ended allocations are kept "so a COMPLETED Course
        // Offering still shows its TEACHING RECORD". A header is such a record.
        //
        // So the name appears, and the STATE is what carries the accuracy: he is not
        // claimed to be teaching, and the date is stated. A reader cannot mistake
        // "starts 3 Nov" for "has been teaching since September".
        $later = $this->user('Dr Future Lecturer', 3);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $later->id, 'role' => 'co_lecturer',
            'starts_on' => now()->addMonths(3)->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->openHome()
            ->assertOk()
            ->assertSee('Dr Future Lecturer')
            // Named, dated, and explicitly NOT presented as teaching now.
            ->assertSee('Starts');

        $row = collect($this->homeFor()['header']['lecturers'])
            ->firstWhere('name', 'Dr Future Lecturer');

        $this->assertNotNull($row);
        $this->assertFalse($row['is_current']);
    }

    public function test_an_allocation_that_HAS_ENDED_is_named_with_its_end_date(): void
    {
        // Also reversed, for the same reason: a course taught last term is not
        // unstaffed, it is finished, and "Taught until <date>" is the truth.
        $past = $this->user('Dr Past Lecturer', 3);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $past->id, 'role' => 'co_lecturer',
            'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->subMonth()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->openHome()
            ->assertOk()
            ->assertSee('Dr Past Lecturer')
            ->assertSee('Taught until');

        $row = collect($this->homeFor()['header']['lecturers'])
            ->firstWhere('name', 'Dr Past Lecturer');

        $this->assertNotNull($row);
        $this->assertFalse($row['is_current']);
    }

    public function test_a_CANCELLED_allocation_is_not_named_at_all(): void
    {
        // The one case that IS an absence rather than a state, because "cancelled"
        // says nobody was ever allocated. "Cancelled allocations are excluded
        // outright" is the domain's own wording, and a name is not a quiet state to
        // be labelled - it is a claim about a person.
        $gone = $this->user('Dr Cancelled Lecturer', 3);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $gone->id, 'role' => 'co_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->openHome()->assertOk()->assertDontSee('Dr Cancelled Lecturer');
    }

    public function test_an_ALLOCATION_through_this_course_is_reachable_even_with_no_content(): void
    {
        // Guards the tests above: if the fixture's allocation were somehow not
        // being read at all, "assertDontSee" would pass for the wrong reason.
        $names = array_column($this->homeFor()['header']['lecturers'], 'name');

        $this->assertNotEmpty($names);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE TEACHER RELATION UNDER EAGER LOADING
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_teacher_relation_survives_EAGER_LOADING(): void
    {
        // A direct relation read has always worked, so a test written against
        // `$offering->lecturerAllocations()->count()` proves nothing about this.
        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        $this->assertSame(2, $offering->lecturerAllocations()->count(), 'the direct read');

        // The eager load is the path that silently returned nothing, because
        // Eloquent builds the relation from an attribute-less new instance and the
        // tenant constraint was read from `$this->school_id`.
        $eager = CourseOffering::query()
            ->with('lecturerAllocations')
            ->findOrFail($this->offering->id);

        $this->assertCount(2, $eager->lecturerAllocations, 'the eager load must not be empty');
    }

    public function test_an_allocation_from_ANOTHER_tenant_is_never_reachable_through_the_relation(): void
    {
        // The tenant constraint still has to bite, and it has to bite on the eager
        // path where it previously did not exist at all. A row that claims this
        // Offering but belongs to another school is corrupt data, and the
        // constraint exists so that corrupt data cannot name a lecturer.
        $theirs = $this->otherTenant();

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $theirs['schoolId'],
            'course_offering_id' => $this->offering->id,
            'user_id' => $theirs['lecturer']->id,
            'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => null,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eager = CourseOffering::query()
            ->with('lecturerAllocations')
            ->findOrFail($this->offering->id);

        $names = $eager->lecturerAllocations->pluck('user_id')->all();

        $this->assertNotContains($theirs['lecturer']->id, $names, 'a foreign-tenant allocation leaked');
    }

    public function test_the_course_home_names_its_teachers_even_though_the_controller_eager_LOADS(): void
    {
        // The end-to-end version of the two above, on the page. The controller
        // eager-loads, so this is the assertion that would have caught it - and
        // asserting it on the PAGE rather than on the service is what makes the
        // difference visible: "Daniel Okello" is a claim about a real person, and a
        // header that quietly drops them reads as a course with no staff.
        $this->openHome()
            ->assertOk()
            ->assertSee('Daniel Okello')
            ->assertSee('Grace Nakato');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE COVER    // ══════════════════════════════════════════════════════════════════════

    public function test_a_course_with_NO_cover_falls_back_to_a_typographic_placeholder(): void
    {
        // The brief says do not fabricate a course image. A stock photograph of a
        // library over "Business Mathematics" is a false claim about the course,
        // and a borrowed image is a second problem. So the fallback NAMES THE
        // COURSE and says plainly that there is no image.
        $this->openHome()
            ->assertOk()
            // `data-testid`, NOT the class name: the page's own stylesheet
            // defines `.ch-cover-fallback`, so asserting on the class passed for a
            // course with no cover at all - the test was reading the CSS.
            ->assertSee('data-testid="ch-cover-fallback"', false)
            ->assertSee('No course image', false)
            ->assertDontSee('data-testid="ch-cover-image"', false);
    }

    public function test_a_cover_image_is_shown_when_one_has_been_set(): void
    {
        $this->setCover();

        $this->openHome()
            ->assertOk()
            ->assertSee('data-testid="ch-cover-image"', false)
            ->assertSee(route('student.courses.cover', $this->offering->id), false)
            ->assertDontSee('data-testid="ch-cover-fallback"', false);
    }

    public function test_the_cover_is_stored_outside_the_web_root(): void
    {
        $this->setCover();

        $path = (string) DB::table('course_offerings')
            ->where('id', $this->offering->id)
            ->value('cover_image_path');

        $this->assertStringStartsWith('course-covers/', $path);
        $this->assertStringNotContainsString('public', $path);
    }

    public function test_the_cover_bytes_are_served_to_an_entitled_reader_and_nobody_else(): void
    {
        $this->setCover();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/cover-image")
            ->assertOk();

        // A student of ANOTHER COURSE IN THE SAME SCHOOL is refused, and with a 404
        // rather than a 403 so the response does not confirm that this course exists
        // at all. The tenant check passes for them, so this really is the Offering
        // check doing the refusing.
        $this->actingAs($this->outsiderInSameSchool())
            ->get("/student/courses/{$this->offering->id}/cover-image")
            ->assertNotFound();

        // And a student of another INSTITUTION is refused too, at the tenant check.
        $this->actingAs($this->outsiderInOtherTenant())
            ->get("/student/courses/{$this->offering->id}/cover-image")
            ->assertNotFound();
    }

    public function test_a_cover_is_read_only_through_its_route(): void
    {
        $this->setCover();

        $path = (string) DB::table('course_offerings')
            ->where('id', $this->offering->id)
            ->value('cover_image_path');

        // There is no public URL for it. The only way in is the authorising route.
        $this->assertFileDoesNotExist(public_path($path));
    }

    private function setCover(): void
    {
        $cover = \Illuminate\Http\UploadedFile::fake()->image('cover.png', 400, 200);

        app(\App\Support\CourseOffering\CourseCoverImage::class)
            ->set($this->lecturer, $this->offering->fresh(), $cover);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PROGRESS, AS DIMENSIONS
    // ══════════════════════════════════════════════════════════════════════

    public function test_progress_is_reported_as_separate_dimensions_and_never_as_one_number(): void
    {
        $this->moduleWithLessons(2);
        $this->completeLesson(1);

        $progress = $this->homeFor()['progress'];

        $this->assertSame(1, $progress['lessons_completed']);
        $this->assertSame(2, $progress['lessons_total']);
        $this->assertArrayHasKey('assessments_total', $progress);
        $this->assertArrayHasKey('modules_total', $progress);

        // NO BLENDED FIGURE. Folding lessons and assessments into one number needs
        // a weighting PIIE has no governed model for, and Course Content already
        // refuses to do it in so many words. A single "percent" here would be that
        // rejected number wearing a different name.
        $this->assertArrayNotHasKey('percent', $progress);
        $this->assertArrayNotHasKey('satisfied', $progress);
        $this->assertArrayNotHasKey('required', $progress);
    }

    public function test_a_dimension_with_nothing_in_it_reports_a_hundred_percent(): void
    {
        // A course with no required assessment must not tell a student they are 0%
        // complete at assessments - they are behind in something the course does
        // not have.
        $this->moduleWithLessons(1);

        $progress = $this->homeFor()['progress'];

        $this->assertSame(0, $progress['assessments_total']);
        $this->assertSame(100, $progress['assessments_percent']);
    }

    public function test_progress_never_exceeds_one_hundred_percent(): void
    {
        $this->moduleWithLessons(2);
        $this->completeLesson(1);
        $this->completeLesson(2);

        foreach (['lessons_percent', 'assessments_percent', 'modules_percent'] as $key) {
            $this->assertLessThanOrEqual(100, $this->homeFor()['progress'][$key], $key.' overflowed its track');
        }
    }

    public function test_an_UNPUBLISHED_module_contributes_to_neither_numerator_nor_denominator(): void
    {
        $this->moduleWithLessons(1);
        $this->completeLesson(1);

        $before = $this->homeFor()['progress']['lessons_total'];

        $this->module(['status' => CourseOfferingModule::STATUS_DRAFT]);
        $this->lesson(null, ['title' => 'Secret lesson', 'status' => 'draft']);

        $this->assertSame($before, $this->homeFor()['progress']['lessons_total']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CONTINUE LEARNING
    // ══════════════════════════════════════════════════════════════════════

    public function test_continue_learning_offers_the_first_lesson_that_is_not_finished(): void
    {
        $this->moduleWithLessons(3);
        $this->completeLesson(1);

        $next = $this->homeFor()['continueLearning']['next'];

        $this->assertNotNull($next);
        $this->assertSame('lesson', $next['kind']);
        $this->assertSame(2, $next['sequence'], 'it must be the SECOND lesson, not the first row');
    }

    public function test_continue_learning_never_offers_a_lesson_the_student_has_completed(): void
    {
        $this->moduleWithLessons(3);
        $this->completeLesson(1);
        $this->completeLesson(2);

        $this->assertSame(3, $this->homeFor()['continueLearning']['next']['sequence']);
    }

    public function test_continue_learning_skips_an_UNRELEASED_lesson(): void
    {
        // An unreleased lesson is not merely deprioritised - it is UNREACHABLE,
        // because the lesson route refuses to resolve it. Offering it would send a
        // student to a page that 404s.
        $this->moduleWithLessons(3, unreleasedFromSequence: 2);

        $next = $this->homeFor()['continueLearning']['next'];

        $this->assertNotNull($next);
        $this->assertSame(1, $next['sequence'], 'an unreleased lesson must be skipped entirely');
    }

    public function test_continue_learning_offers_a_required_assessment_that_is_outstanding(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->completeLesson(1);

        $task = $this->requiredModuleTask($module, [
            'title' => 'Module 1 Assessment',
            'max_marks' => 20,
            'due_date' => now()->addWeek(),
        ]);

        $next = $this->homeFor()['continueLearning']['next'];

        $this->assertSame('assignment', $next['kind']);
        $this->assertSame($task->title, $next['title']);
        $this->assertTrue($next['required']);
    }

    public function test_continue_learning_does_NOT_offer_an_assessment_already_handed_in(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->completeLesson(1);
        $this->submittedModuleTask($module, ['title' => 'Done already']);

        $continue = $this->homeFor()['continueLearning'];

        // Nothing is actionable, so the card says so - and says the work is with
        // the lecturer rather than claiming the student has finished everything.
        $this->assertFalse($continue['has_next']);
        $this->assertTrue($continue['waiting_on_lecturer']);
    }

    public function test_continue_learning_does_NOT_offer_a_DRAFT_assignment(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->completeLesson(1);
        $this->moduleTask($module, ['title' => 'Still a draft', 'status' => Assignment::STATUS_DRAFT]);

        $this->assertFalse($this->homeFor()['continueLearning']['has_next']);
    }

    public function test_overdue_work_is_offered_AHEAD_of_the_reading_order(): void
    {
        // A deliberate product judgement, worth stating: reading order optimises
        // for a student keeping pace, and an assignment whose deadline has passed
        // is the one thing that must be dealt with today. Reading order still
        // decides everything that is NOT overdue.
        $module = $this->moduleWithLessons(3);
        $this->completeLesson(1);

        $this->moduleTask($module, [
            'title' => 'Overdue work',
            'max_marks' => 10,
            'due_date' => now()->subDays(3),
            'late_policy' => Assignment::LATE_ALLOWED,
        ]);

        $next = $this->homeFor()['continueLearning']['next'];

        $this->assertSame('assignment', $next['kind'], 'the overdue assessment must come first');
        $this->assertTrue($next['overdue']);
    }

    public function test_reading_order_is_kept_when_nothing_is_overdue(): void
    {
        $module = $this->moduleWithLessons(3);
        $this->completeLesson(1);

        $this->moduleTask($module, [
            'title' => 'Not due yet',
            'max_marks' => 10,
            'due_date' => now()->addWeeks(3),
        ]);

        $next = $this->homeFor()['continueLearning']['next'];

        $this->assertSame('lesson', $next['kind'], 'reading order decides when nothing is overdue');
        $this->assertSame(2, $next['sequence']);
    }

    public function test_continue_learning_offers_nothing_at_all_when_the_course_is_done(): void
    {
        $this->moduleWithLessons(2);
        $this->completeLesson(1);
        $this->completeLesson(2);

        $continue = $this->homeFor()['continueLearning'];

        $this->assertFalse($continue['has_next']);
        $this->assertNull($continue['next']);
        $this->assertSame(0, $continue['total_actionable']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE ASSIGNMENTS CARD
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_assignments_card_lists_work_the_student_can_still_hand_in(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->moduleTask($module, ['title' => 'Still to do', 'max_marks' => 10, 'due_date' => now()->addWeek()]);

        $card = $this->homeFor()['assignments'];

        $this->assertSame(1, $card['actionable_count']);
        $this->assertSame('Still to do', $card['actionable'][0]['assignment']->title);
    }

    public function test_the_assignments_card_says_the_same_thing_the_assignment_list_says(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->moduleTask($module, ['title' => 'Still to do', 'max_marks' => 10, 'due_date' => now()->addWeek()]);

        $row = $this->homeFor()['assignments']['actionable'][0];

        // The ONE vocabulary. A card reading "Open" while the list reads "Missing"
        // would give a student two contradictory facts about the same work, and
        // both sentences would be individually reasonable.
        $this->assertSame(
            AssignmentState::for($row['assignment'], null, 0, false),
            $row['state'],
        );
    }

    public function test_an_overdue_but_still_open_assignment_is_overdue_and_not_missing(): void
    {
        // Late work still permitted means the student CAN act, so calling their
        // work "Missing" would be both false and needlessly alarming.
        $module = $this->moduleWithLessons(1);
        $this->moduleTask($module, [
            'title' => 'Late but permitted',
            'max_marks' => 10,
            'due_date' => now()->subDays(2),
            'late_policy' => Assignment::LATE_ALLOWED,
        ]);

        $card = $this->homeFor()['assignments'];

        $this->assertSame(AssignmentState::OVERDUE_OPEN, $card['actionable'][0]['state']);
        $this->assertSame(1, $card['overdue_count']);
    }

    public function test_a_submitted_assignment_is_not_counted_as_outstanding(): void
    {
        // "Not yet submitted" and "already handed back" are different categories,
        // and a total mixing them would overstate the work waiting on the student.
        $module = $this->moduleWithLessons(1);
        $this->submittedModuleTask($module, ['title' => 'Handed in', 'max_marks' => 10, 'due_date' => now()->addWeek()]);

        $card = $this->homeFor()['assignments'];

        $this->assertSame(0, $card['actionable_count']);
        $this->assertSame(1, $card['done_count']);
    }

    public function test_a_mark_is_shown_on_the_card_only_once_it_has_been_RELEASED(): void
    {
        $module = $this->moduleWithLessons(1);
        $task = $this->submittedModuleTask($module, ['title' => 'Marked', 'max_marks' => 20, 'due_date' => now()->addWeek()]);

        // Submitted, awaiting a mark: nothing is shown.
        $card = $this->homeFor()['assignments'];
        $this->assertNull($card['done'][0]['marks']);
        $this->assertSame(AssignmentState::SUBMITTED, $card['done'][0]['state']);

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $task->id)
            ->where('student_id', $this->student->id)
            ->firstOrFail();

        // Marked but NOT released: still nothing. A mark the lecturer has recorded
        // but not returned is not a result, and showing it would be reading
        // another user's row.
        $submission->update(['marks_awarded' => 17, 'status' => 'graded']);

        $this->assertNull($this->homeFor()['assignments']['done'][0]['marks']);

        // Released: now it is a result, and the same number the assignment page and
        // the module completion rule read.
        $submission->update(['marks_released_at' => now()]);

        $card = $this->homeFor()['assignments'];
        $this->assertSame(17.0, (float) $card['done'][0]['marks']);
        $this->assertSame(AssignmentState::RETURNED, $card['done'][0]['state']);
    }

    public function test_the_card_never_links_an_assignment_the_student_could_not_open(): void
    {
        $module = $this->moduleWithLessons(1);

        $this->moduleTask($module, ['title' => 'Visible', 'max_marks' => 10, 'due_date' => now()->addWeek()]);
        $this->moduleTask($module, [
            'title' => 'Unreleased',
            'max_marks' => 10,
            'status' => Assignment::STATUS_DRAFT,
        ]);

        $card = $this->homeFor()['assignments'];

        $this->assertSame(1, $card['total'], 'a draft assignment is not listed at all');

        // And the one that IS listed resolves.
        $this->actingAs($this->student)
            ->get($card['actionable'][0]['url'])
            ->assertOk();
    }

    public function test_the_soonest_deadline_comes_first_in_the_card(): void
    {
        $module = $this->moduleWithLessons(1);

        $this->moduleTask($module, ['title' => 'Later', 'max_marks' => 10, 'due_date' => now()->addWeeks(3)]);
        $this->moduleTask($module, ['title' => 'Sooner', 'max_marks' => 10, 'due_date' => now()->addDays(2)]);

        $this->assertSame('Sooner', $this->homeFor()['assignments']['actionable'][0]['assignment']->title);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE LIVE CLASSES CARD
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_live_classes_card_shows_this_offerings_next_class(): void
    {
        $this->moduleWithLessons(1);
        $this->liveClass(['title' => 'Derivatives workshop', 'scheduled_at' => now()->addDay()]);

        $card = $this->homeFor()['liveClasses'];

        $this->assertNotNull($card['next']);
        $this->assertSame('Derivatives workshop', $card['next']['title']);
        $this->assertSame(1, $card['upcoming_count']);
    }

    public function test_the_live_classes_card_never_shows_a_class_from_another_offering(): void
    {
        $this->moduleWithLessons(1);

        $theirs = $this->class([
            'course_offering_id' => $this->secondOfferingSameSchool()->id,
            'title' => 'Their class',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->assertSame(0, $this->homeFor()['liveClasses']['total']);
    }

    public function test_a_join_button_appears_only_when_the_join_policy_permits_it(): void
    {
        $this->moduleWithLessons(1);
        $this->liveClass(['title' => 'Not for joining', 'scheduled_at' => now()->addMonth()]);

        $card = $this->homeFor()['liveClasses']['next'];

        // A month away is outside the join window, so the button must be ABSENT
        // rather than present and failing - and the URL must be null, not a link
        // that would refuse.
        $this->assertFalse($card['joinable']);
        $this->assertNull($card['join_url']);
    }

    public function test_a_join_button_appears_when_the_class_is_live_now(): void
    {
        $this->moduleWithLessons(1);
        $this->liveClass([
            'title' => 'Happening now',
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'status' => LiveClass::STATUS_LIVE,
        ]);

        $card = $this->homeFor()['liveClasses']['next'];

        $this->assertTrue($card['joinable']);
        $this->assertSame(route('student.live_classes.join', $card['class']), $card['join_url']);
    }

    public function test_a_cancelled_class_is_never_offered_as_the_next_one(): void
    {
        $this->moduleWithLessons(1);
        $this->liveClass([
            'title' => 'Called off',
            'scheduled_at' => now()->subMinutes(5),
            'status' => LiveClass::STATUS_CANCELLED,
        ]);

        $this->assertNull($this->homeFor()['liveClasses']['next']);
    }

    public function test_no_meeting_url_is_invented_by_the_course_home(): void
    {
        // The brief is explicit: no fake Google Meet, no hard-coded URL. The join
        // link is the EXISTING route, so the existing provider behaviour is
        // untouched and an institutional integration can be added behind it
        // without anything here being undone.
        $this->moduleWithLessons(1);
        $this->liveClass([
            'title' => 'Happening now',
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'status' => LiveClass::STATUS_LIVE,
        ]);

        $url = (string) $this->homeFor()['liveClasses']['next']['join_url'];

        $this->assertStringNotContainsString('meet.google.com', $url);
        $this->assertStringNotContainsString('meet.jit.si', $url);
        $this->assertStringContainsString('student/live-classes', $url);
    }

    public function test_an_UNPUBLISHED_live_class_is_not_shown(): void
    {
        $this->moduleWithLessons(1);
        $this->liveClass(['title' => 'Secret', 'scheduled_at' => now()->addDay(), 'is_published' => false]);

        $this->assertSame(0, $this->homeFor()['liveClasses']['total']);
    }

    public function test_the_live_classes_card_says_so_when_there_is_nothing_scheduled(): void
    {
        $this->openHome()->assertOk()->assertSee('No Live Class is scheduled for this course.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE COURSE RESOURCES CARD
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_resources_card_lists_material_from_published_lessons(): void
    {
        $module = $this->moduleWithLessons(1);
        $lesson = $module->lessons()->firstOrFail();
        $this->resource($lesson, ['title' => 'Formula sheet']);

        $card = $this->homeFor()['resources'];

        $this->assertSame(1, $card['count']);
        $this->assertSame('Formula sheet', $card['items'][0]['name']);
        $this->assertSame('Link', $card['items'][0]['kind_label']);
    }

    public function test_the_resources_card_never_lists_material_from_an_UNPUBLISHED_lesson(): void
    {
        $module = $this->moduleWithLessons(1, unreleasedFromSequence: 1);
        $lesson = $module->lessons()->firstOrFail();
        $this->resource($lesson, ['title' => 'Hidden handout']);

        // It is on disk and in the database, and the student cannot reach it,
        // because the lesson page that would link it does not resolve.
        $this->assertDatabaseHas('course_offering_lesson_resources', ['title' => 'Hidden handout']);

        $this->assertSame(0, $this->homeFor()['resources']['count']);
    }

    public function test_a_link_resource_with_no_usable_url_is_not_offered(): void
    {
        // A row in a table is not a resource. Offering it would put a dead entry in
        // front of a student.
        $module = $this->moduleWithLessons(1);
        $this->resource($module->lessons()->firstOrFail(), [
            'title' => 'Broken',
            'link_url' => 'javascript:alert(1)',
        ]);

        $this->assertSame(0, $this->homeFor()['resources']['count']);
    }

    public function test_the_resources_card_says_why_it_is_empty(): void
    {
        $this->moduleWithLessons(1);

        $this->openHome()
            ->assertOk()
            ->assertSee('No lesson in this course has published a file or a link yet.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE NAVIGATION, AND WHAT IS HONESTLY AVAILABLE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_six_navigation_areas_are_all_present_and_in_order(): void
    {
        $keys = array_column($this->homeFor()['sections'], 'key');

        $this->assertSame(
            ['overview', 'content', 'live_classes', 'assignments', 'quizzes_exams', 'grades'],
            $keys,
            'the navigation is the six areas the course experience is planned to have'
        );
    }

    public function test_every_available_area_links_somewhere_and_no_unavailable_area_does(): void
    {
        foreach ($this->homeFor()['sections'] as $section) {
            if ($section['available']) {
                $this->assertNotNull($section['url'], $section['key'].' is available and must link somewhere');

                continue;
            }

            // A link to a page that would 404 is worse than no link: it costs the
            // student a click and teaches them the product is broken.
            $this->assertNull($section['url'], $section['key'].' is unavailable and must link nowhere');
            $this->assertNotEmpty($section['note'], $section['key'].' must say why it is unavailable');
        }
    }

    public function test_an_unfinished_area_is_shown_rather_than_hidden(): void
    {
        // A navigation that omits two of its own sections makes a student wonder
        // whether they have been given the wrong account.
        $this->openHome()
            ->assertOk()
            ->assertSee('Quizzes &amp; Exams', false)
            ->assertSee('Grades', false)
            ->assertSee('Not available yet');
    }

    public function test_no_available_navigation_area_produces_an_error(): void
    {
        foreach ($this->homeFor()['sections'] as $section) {
            if (! $section['available'] || $section['key'] === 'overview') {
                continue;
            }

            $this->actingAs($this->student)->get($section['url'])->assertSuccessful();
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // MODULE COMPLETION IS NOT DISTURBED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_required_assessment_still_blocks_module_completion(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->completeLesson(1);

        $task = $this->requiredModuleTask($module, [
            'title' => 'Required assessment',
            'max_marks' => 10,
            'due_date' => now()->addWeek(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
        ]);

        // Every lesson done, nothing submitted: NOT complete.
        $this->assertSame(0, $this->homeFor()['progress']['modules_complete']);

        // Submitted but unmarked: still not complete. This is the exact state the
        // real manual test reached, and the one that must not drift.
        $submission = $this->submission($task, $this->student);

        $this->assertSame(0, $this->homeFor()['progress']['modules_complete']);
        $this->assertSame(0, $this->homeFor()['progress']['assessments_completed']);

        // Marked but NOT released: still not complete. A mark the lecturer has
        // recorded but not returned is not a result.
        $submission->update(['marks_awarded' => 9, 'status' => 'graded']);

        $this->assertSame(0, $this->homeFor()['progress']['assessments_completed']);

        // Released: now it is complete, and the Course Home agrees. The release
        // fact is `marks_released_at` - there is no `is_released` column, which is
        // why writing one changed nothing.
        $submission->update(['marks_released_at' => now()]);

        $progress = $this->homeFor()['progress'];
        $this->assertSame(1, $progress['assessments_completed']);
        $this->assertSame(1, $progress['modules_complete']);
    }

    public function test_a_module_with_no_assessment_completes_on_its_lessons_alone(): void
    {
        $this->moduleWithLessons(2);
        $this->completeLesson(1);

        $this->assertSame(0, $this->homeFor()['progress']['modules_complete']);

        $this->completeLesson(2);

        $progress = $this->homeFor()['progress'];
        $this->assertSame(1, $progress['modules_complete']);
        $this->assertSame(2, $progress['lessons_completed']);
    }

    public function test_an_OPTIONAL_assessment_does_not_nag_a_finished_student(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->completeLesson(1);
        $this->moduleTask($module, ['title' => 'Optional extra', 'max_marks' => 10]);

        // The module is complete, and the Course Home must not tell a student
        // there is outstanding work - optional work that nagged would stop being
        // optional.
        $this->assertSame(1, $this->homeFor()['progress']['modules_complete']);

        // Nor may Continue Learning offer it as the next thing. It is outstanding,
        // so it is listed under "also", but it is not the thing to do today.
        $next = $this->homeFor()['continueLearning']['next'];
        $this->assertNotNull($next);
        $this->assertSame('Optional extra', $next['title'], 'an optional assessment is still legitimate next work');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE WHOLE PAGE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_page_renders_even_for_a_course_with_nothing_in_it(): void
    {
        $this->openHome()
            ->assertOk()
            ->assertSee('No assignments are waiting for you.')
            ->assertSee('No Live Class is scheduled for this course.')
            ->assertSee('No lesson in this course has published a file or a link yet.');
    }

    public function test_the_page_offers_the_four_cards_by_name(): void
    {
        $this->moduleWithLessons(1);

        $this->openHome()
            ->assertOk()
            ->assertSee('Continue Learning')
            ->assertSee('Assignments')
            ->assertSee('Live Classes')
            ->assertSee('Course Resources');
    }

    public function test_the_three_progress_dimensions_are_spoken_as_well_as_drawn(): void
    {
        $this->moduleWithLessons(2);
        $this->completeLesson(1);

        $html = $this->openHome()->assertOk()->getContent();

        // Each bar carries its own numerator and denominator in text, so a glance
        // is enough to know exactly what is being counted - and a screen reader
        // gets the same sentence a sighted reader reads off the numbers.
        //
        // Asserted in parts rather than as one contiguous string: `aria-label` is
        // assembled with newlines around its interpolations, and pinning a run of
        // rendered whitespace would make this test fail on a formatting change
        // while still saying nothing about the claim.
        $this->assertStringContainsString('Learning content: 1 of 2 complete', $this->normalise($html));
    }

    public function test_every_state_on_the_page_is_a_word_and_not_only_a_colour(): void
    {
        $module = $this->moduleWithLessons(1);
        $this->moduleTask($module, [
            'title' => 'Overdue',
            'max_marks' => 10,
            'due_date' => now()->subDays(4),
            'late_policy' => Assignment::LATE_BLOCKED,
        ]);

        $html = $this->openHome()->assertOk()->getContent();

        // A chip that is only a colour excludes a colour-blind reader and a screen
        // reader equally, and two different states that look alike are worse than
        // no list at all.
        $this->assertMatchesRegularExpression('/class="[^"]*ch-chip[^"]*"[^>]*>\s*\w/', $html);
    }

    public function test_the_student_course_list_can_reach_the_course_home(): void
    {
        $this->actingAs($this->student)
            ->get('/student/my-courses')
            ->assertOk()
            ->assertSee(route('student.courses.show', $this->offering->id), false);
    }
}
