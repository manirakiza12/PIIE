<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE CREATE PAGE MUST NEVER 500.
 *
 * ── THE REPORTED FAILURE ───────────────────────────────────────────────────
 *
 * `GET /teacher/online-exams/create` returned HTTP 500 for Daniel Okello
 * (user 101) at 2026-09-30 18:04. From storage/logs/laravel.log:
 *
 *   Call to a member function getAttributes() on null
 *     at resources/views/teacher/online_exam/_form.blade.php:160
 *     rendered from resources/views/teacher/online_exam/create.blade.php:14
 *
 * The line was:
 *
 *   :value="old('instructions', $exam->getAttributes()['instructions'] ?? '')"
 *
 * `??` binds to the ARRAY ACCESS, so `$exam->getAttributes()` is evaluated first -
 * and `teacherCreate()` passes `'exam' => null` deliberately, because
 * `$isEdit = !empty($exam)` is what separates the create page from the edit page.
 * The method call therefore ran on null and threw before `??` could suppress it.
 *
 * Every neighbouring line already used the nullsafe idiom
 * (`!empty($exam?->start_datetime) ? ... : ''`). One line was out of step with its own
 * file.
 *
 * `admin/online_exam/modal.blade.php` carried the identical expression, and the admin
 * `create()` also passes `exam => null`, so `/admin/online-exams/create` was the same
 * 500 waiting to happen. Both are pinned here.
 *
 * ── WHY THE CONTROLLER IS NOT WHAT CHANGED ─────────────────────────────────
 *
 * The obvious alternative - handing the form a fresh `new OnlineExam` - would break
 * the create/edit distinction that `$isEdit` provides, so every create page would
 * POST to the update route. Passing null is correct; the view failed to cope with it.
 * These tests therefore assert the VIEW's contract, not the controller's shape.
 */
class OnlineExamCreatePageTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    private ?\App\Models\User $lecturer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
    }

    private function lecturer(): \App\Models\User
    {
        return $this->lecturer ??= $this->makeUser(3, 1, 'active', 'Lecturer A');
    }

    private function offering(): \App\Models\CourseOffering
    {
        return $this->makeOffering(1, ['reference' => 'CREATE-PAGE-2026-S1']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE REPORTED 500
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_TEACHER_create_page_renders_instead_of_500(): void
    {
        $this->actingAs($this->lecturer())
            ->get(route('teacher.online_exams.create'))
            ->assertOk();
    }

    public function test_the_ADMIN_create_page_renders_instead_of_500(): void
    {
        // The twin. `/admin/online-exams/create` passes `exam => null` too, and shared
        // the same expression, so fixing only the reported page would have left this
        // one to fail the next time it was opened.
        $this->actingAs($this->makeUser(2, 1, 'active', 'Administrator'))
            ->get(route('admin.online_exams.create'))
            ->assertOk();
    }

    /**
     * THE CONTROLLER SHAPE THAT MADE IT EXPLODE, PINNED.
     *
     * If someone "fixes" this by passing a fresh model instead of null, the create
     * page silently becomes an edit page and the create route is lost. Asserting the
     * null here makes that regression loud instead of subtle.
     *
     * The signature is matched as `teacherCreate(...)` with any parameter list, and
     * deliberately NOT pinned to the empty one. `teacherCreate()` grew a `Request`
     * when the Course Offering selector was added, and it would have been wrong to
     * weaken this assertion's real subject - "the view is handed a null exam" - into
     * "the view is handed a null exam by a method that happens to take no arguments".
     * The second is an accident of syntax; the first is the contract.
     */
    public function test_the_create_action_REALLY_passes_a_null_exam(): void
    {
        $source = (string) file_get_contents(
            app_path('Http/Controllers/OnlineExamController.php')
        );

        $this->assertMatchesRegularExpression(
            "/function teacherCreate\([^)]*\).*?'exam'\s*=>\s*null/s",
            $source,
            'teacherCreate() passes exam => null, which is what the view must tolerate'
        );

        // The view must therefore GUARD its reads - but not by dropping the raw
        // attribute read, because the editor has to be handed the markup that was
        // actually STORED rather than the accessor.
        //
        // An earlier version of this test asserted that the string
        // `$exam->getAttributes()` was ABSENT from the form. It is still there,
        // correctly, behind the `!empty($exam)` guard, so that assertion failed on a
        // correct fix. What has to hold is that no call is UNGUARDED - asserted
        // properly, across every line of every form, in the next test.
        $form = (string) file_get_contents(
            resource_path('views/teacher/online_exam/_form.blade.php')
        );

        $this->assertStringContainsString(
            '!empty($exam) ? ($exam->getAttributes()',
            $form,
            'the raw column is still read, now behind a null guard'
        );
    }

    /**
     * THE WHOLE CLASS OF DEFECT, NOT JUST THE REPORTED LINE.
     *
     * Every `$exam->method()` call in the exam forms is checked, because the fix that
     * matters is the property "the create form cannot dereference a null exam", and
     * one assertion on one line would not hold if a future edit added another.
     */
    public function test_NO_exam_form_dereferences_a_maybe_null_exam(): void
    {
        $unsafe = [];

        foreach ([
            'teacher/online_exam/_form.blade.php',
            'admin/online_exam/modal.blade.php',
            'teacher/online_exam/edit.blade.php',
        ] as $view) {
            $path = resource_path('views/'.$view);
            if (! is_file($path)) {
                continue;
            }
            foreach (preg_split('/\r\n|\n/', (string) file_get_contents($path)) as $n => $line) {
                if (! preg_match('/\$exam(->|\?->)\w+\(/', $line)) {
                    continue;
                }
                $guarded = str_contains($line, '$exam?->')
                    || str_contains($line, '!empty($exam')
                    || str_contains($line, '$exam &&');
                if (! $guarded) {
                    $unsafe[] = $view.' line '.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $unsafe,
            "an unguarded \$exam method call would 500 the create page:\n".implode("\n", $unsafe)
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. EMPTY STATE, NEVER A 500
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_create_page_loads_with_NO_subjects_classes_or_offerings(): void
    {
        // A lecturer with nothing assigned must see an empty state, not an error.
        // The brief is explicit: an absent optional relationship is never a 500.
        $stranger = $this->makeUser(3, 1, 'active', 'Lecturer With Nothing');

        $this->actingAs($stranger)
            ->get(route('teacher.online_exams.create'))
            ->assertOk();
    }

    public function test_the_create_page_shows_a_MESSAGE_rather_than_an_empty_select(): void
    {
        $html = $this->actingAs($this->lecturer())
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        // The existing subject empty-state, which is what tells a lecturer why the
        // list is empty instead of leaving them staring at a blank dropdown.
        $this->assertStringContainsString('No subjects are assigned to this teacher', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. THE FORM IS STILL THE WORD EDITOR, AND STILL COMPLETE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_create_page_carries_the_WORD_editor_for_instructions(): void
    {
        $html = $this->actingAs($this->lecturer())
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="exam-instructions-editor"', $html);
        $this->assertStringContainsString('academic-editor.js', $html, 'the editor script must be on the page');
    }

    public function test_the_create_page_carries_EVERY_existing_field(): void
    {
        $html = $this->actingAs($this->lecturer())
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        // The existing engine's fields. Asserted individually so a missing one is
        // named, and so a "fix" cannot quietly drop a field to make the page render.
        foreach ([
            'name="title"',
            'name="exam_type"',
            'name="subject_id"',
            'name="class_id"',
            'name="programme_id"',
            'name="session_id"',
            'name="start_datetime"',
            'name="end_datetime"',
            'name="duration_mins"',
            'name="total_marks"',
            'name="pass_mark"',
            'name="max_attempts"',
            'name="result_release_policy"',
            'name="instructions"',
            'name="shuffle_questions"',
            'name="shuffle_options"',
            'name="allow_previous_navigation"',
            'name="auto_submit"',
            'name="webcam_required"',
            'name="fullscreen_required"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "the create form must still offer {$field}");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. GOVERNANCE IS NOT WEAKENED BY ANY OF THIS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturer_still_CANNOT_PUBLISH_what_they_create(): void
    {
        // The create page working must not mean a lecturer's paper is live on save.
        // `StoreOnlineExamRequest` gives a lecturer `pending_review`, never
        // `published`, and the permission behind it is absent for role 3.
        $this->assertFalse(
            app(\App\Support\Permissions\OnlineExamPermissionService::class)
                ->hasBase($this->lecturer(), 'publish_online_exams'),
            'a lecturer must not hold publish_online_exams'
        );
    }

    public function test_a_CREATED_exam_enters_ADMIN_REVIEW_and_is_invisible_to_students(): void
    {
        $lecturer = $this->lecturer();
        $offering = $this->offering();
        $this->allocateLecturer($offering, $lecturer);
        $student = $this->makeUser(7, 1, 'active', 'Student A');
        $this->confirmStudent($offering, $student);

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.exams.store', $offering), [
                'title' => 'A paper awaiting review',
                'exam_type' => 'midterm',
                'subject_id' => $offering->subject_id,
                'start_datetime' => now()->subHour()->format('Y-m-d H:i:s'),
                'end_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
                'duration_mins' => 45,
                'total_marks' => 10,
                'pass_mark' => 5,
                'max_attempts' => 1,
                'result_release_policy' => 'immediate',
                'instructions' => '<p>Answer <b>all</b> questions.</p>',
            ])
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'A paper awaiting review')->firstOrFail();

        $this->assertSame('pending_review', $exam->workflow_state, 'a lecturer lands in the Admin queue');
        $this->assertFalse((bool) $exam->is_published);

        // And the student, already confirmed, still sees nothing.
        $this->assertSame(
            [],
            app(\App\Support\CourseExams\CourseOfferingAssessments::class)->forStudent($student, $offering),
            'an exam awaiting Admin review must not reach a student'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. A STUDENT OR ADMIN CANNOT OPEN THE LECTURER CREATE FORM
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_STUDANT_cannot_open_the_LECTURER_create_page(): void
    {
        $this->actingAs($this->makeUser(7, 1, 'active', 'Student A'))
            ->get(route('teacher.online_exams.create'))
            ->assertStatus(302);
    }

    /**
     * TENANT ISOLATION, WHERE IT ACTUALLY LIVES.
     *
     * The earlier version of this test asserted that `create_online_exams` does not
     * resolve for a teacher in another institution. It DOES resolve - that permission
     * is a role grant, not a tenant grant, and tenancy is enforced where the DATA is
     * read. Asserting the permission service enforced tenancy would have been asserting
     * a control that does not exist and never should: a teacher may hold the
     * permission and still be offered nothing from another school.
     *
     * The control that does exist is `teacherCreate()` reading subjects through
     * `teacherAssignableSubjects($user->id)`, which is school-scoped. So that is what
     * is asserted - together with the permission itself, so the separation is visible
     * rather than assumed.
     */
    public function test_TENANT_ISOLATION_is_applied_to_the_OFFERABLE_subjects(): void
    {
        // Inserted directly rather than through the fixture helper, because the
        // assertion has to recognise the subject BY NAME in the rendered page, and
        // `makeSubject()` takes a school and a class, not a label.
        // Inserted directly rather than through the fixture helper, because the
        // assertion has to recognise the subject BY NAME in the rendered page, and
        // `makeSubject()` takes a school and a class, not a label.
        //
        // Only the columns the fixture actually declares: `school_id` and `name`.
        \Illuminate\Support\Facades\DB::table('subjects')->insert([
            'school_id' => 1,
            'name' => 'School One Business Mathematics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outsider = $this->makeUser(3, 2, 'active', 'Teacher In Another Institution');

        $html = $this->actingAs($outsider)
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'School One Business Mathematics',
            $html,
            'another institution\'s Course Units must never be offered'
        );

        $this->assertTrue(
            app(\App\Support\Permissions\OnlineExamPermissionService::class)
                ->hasBase($outsider, 'create_online_exams'),
            'the permission is a role grant; tenancy is enforced where the data is read'
        );
    }
}