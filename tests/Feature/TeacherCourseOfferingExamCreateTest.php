<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE GENERIC CREATE PAGE MUST BE A COURSE OFFERING PAGE.
 *
 * ── THE DEFECT, AS REPORTED ──────────────────────────────────────────────────
 *
 * `GET /teacher/online-exams/create` rendered, but it asked for the legacy graph:
 * Course Unit/Subject, Class, Programme, Academic Semester. And because
 * `teacherAssignableSubjects()` is built from `teacher_permissions` and
 * `teacher_programme_assignments` - both empty for Daniel Okello, whose entire
 * teaching authority is a `CourseOfferingLecturerAllocation` on Offering 5 - the
 * page told the PRIMARY LECTURER of BBIT1103:
 *
 *     "No subjects are assigned to this teacher. An administrator must assign a
 *      class/subject before this exam can be created."
 *
 * That warning was not a missing permission. It was a missing QUESTION. The page
 * asked "which legacy class do you teach?" to a person whose answer is recorded
 * somewhere else entirely, so no assignment could ever have satisfied it.
 *
 * ── WHY THE FIX IS NOT A LEGACY ASSIGNMENT ───────────────────────────────────
 *
 * Creating `teacher_permissions` rows for Daniel would make the page stop
 * complaining while leaving the architecture wrong: the exam would then be scoped
 * by a class cohort, `applyStudentVisibility()` would admit every student enrolled
 * in that class rather than every student confirmed on the Offering, and the
 * notification recipients would follow the class too. It would convert a correct
 * Course Offering assessment into a legacy one by the back door.
 *
 * So the tests below assert the opposite: that the Offering is the authority, that
 * the legacy graph is NOT consulted, and that a legacy assignment is never
 * fabricated to make a form work.
 *
 * ── BOTH MODES ARE COVERED ───────────────────────────────────────────────────
 *
 *   MODE A  `course_offering_id` present -> the Offering is authoritative
 *   MODE B  `course_offering_id` NULL     -> the legacy class/subject path, intact
 *
 * The legacy half is not a formality. Nine live exams have NULL there, and the
 * NULL-class school-wide arm they depend on is precisely the thing a careless
 * "modernisation" would silently remove.
 */
class TeacherCourseOfferingExamCreateTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected CourseOffering $offering;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
        $this->assertFixtureTablesMatchProduction();

        // The role grant a lecturer needs. Absent it the create page is 403 and none
        // of the UI assertions would mean anything, so this is stated once here.
        DB::table('global_settings')->where('key', 'role_perm_3')->delete();
        DB::table('global_settings')->insert([
            'key' => 'role_perm_3',
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'view_exam_results',
                'mark_exam_answers',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->offering = $this->makeOffering(1);
        $this->lecturer = $this->makeUser(3, 1, 'active', 'Daniel Okello');
        $this->allocateLecturer($this->offering, $this->lecturer);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 0. THE PREMISE, ASSERTED SO IT CANNOT BE "FIXED" THE OTHER WAY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Daniel really does hold NO legacy assignment - which is why the warning was
     * true, and why faking one would have been the wrong repair.
     */
    public function test_the_LECTURER_holds_a_COURSE_OFFERING_allocation_and_NO_legacy_assignment(): void
    {
        $this->assertSame(
            0,
            DB::table('teacher_permissions')->where('teacher_id', $this->lecturer->id)->count(),
            'the premise: no legacy class assignment exists, and none may be created'
        );

        $this->assertSame(
            0,
            DB::table('teacher_programme_assignments')->where('teacher_id', $this->lecturer->id)->count(),
            'the premise: no legacy programme assignment exists either'
        );

        $this->assertSame(
            1,
            DB::table('course_offering_lecturer_allocations')
                ->where('user_id', $this->lecturer->id)
                ->where('course_offering_id', $this->offering->id)
                ->count(),
            'a Course Offering allocation IS the teaching authority'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE WARNING IS GONE FOR A COURSE OFFERING LECTURER
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_COURSE_OFFERING_lecturer_is_NOT_told_they_have_no_subject(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'No subjects are assigned to this teacher',
            $html,
            'a valid Course Offering allocation is sufficient authority; the legacy warning must not appear'
        );
    }

    public function test_the_FIRST_academic_selector_is_the_COURSE_OFFERING(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-testid="exam-course-offering-select"',
            $html,
            'the Course Offering must be offered as a selector, not described in prose'
        );

        // The Offering is the FIRST academic control on the page. Asserted by
        // position rather than by presence, because presence alone would be
        // satisfied by a Course Offering dropdown sitting below four legacy ones -
        // which is exactly the shape that produced the original complaint.
        $offeringAt = strpos($html, 'exam-course-offering-select');
        $subjectAt = strpos($html, 'name="subject_id"');

        $this->assertNotFalse($offeringAt);
        $this->assertNotFalse($subjectAt);
        $this->assertLessThan(
            $subjectAt,
            $offeringAt,
            'the Course Offering selector must come BEFORE the legacy subject selector'
        );
    }

    public function test_ONLY_the_lecturers_OWN_allocations_are_offered(): void
    {
        // A second course in this institution the lecturer is NOT allocated to.
        $notMine = $this->makeOffering(1, ['reference' => 'NOT-MINE-2026-S1']);

        // And another institution's course.
        $theirs = $this->otherInstitution();

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="'.$this->offering->id.'"', $html, 'their own Offering is offered');

        $this->assertStringNotContainsString(
            'value="'.$notMine->id.'"',
            $html,
            'an Offering they are not allocated to must never be offered'
        );

        $this->assertStringNotContainsString(
            'value="'.$theirs['offering']->id.'"',
            $html,
            'another institution\'s Offering must never be offered'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. CHOOSING AN OFFERING DERIVES THE CONTEXT, READ-ONLY
    // ══════════════════════════════════════════════════════════════════════

    public function test_choosing_the_OFFERING_SHOWS_the_DERIVED_academic_context(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create', ['course_offering_id' => $this->offering->id]))
            ->assertOk()
            ->getContent();

        // Relations read LAZILY off the live instance, never with `fresh([...])`.
        //
        // `CourseOffering::subject()` applies its tenant constraint when the
        // relation is DEFINED, so eager loading builds an attribute-less parent,
        // compiles `where school_id is null`, and returns null for a valid Course
        // Unit. `academicYear()`/`academicPeriod()` are guarded against exactly that.
        // Reading them off an instance that HAS `school_id` is correct.
        $offering = $this->offering;

        // Each fact is asserted by its VALUE, not by the presence of a label: a
        // summary that renders the right headings with empty values would pass a
        // presence check and tell a lecturer nothing.
        $this->assertStringContainsString($offering->subject->name, $html, 'the Course is named');
        $this->assertStringContainsString($offering->reference, $html, 'the Offering reference is shown');
        $this->assertStringContainsString('2026/2027', $html, 'the academic year is derived');
        $this->assertStringContainsString('Semester 1', $html, 'the semester/period is derived');
        $this->assertStringContainsString('Daniel Okello', $html, 'the lecturer is named');

        foreach ([
            'exam-offering-course-unit',
            'exam-offering-academic-year',
            'exam-offering-academic-period',
            'exam-offering-lecturer',
        ] as $testid) {
            $this->assertStringContainsString('data-testid="'.$testid.'"', $html);
        }
    }

    public function test_in_OFFERING_mode_the_LEGACY_selectors_are_NOT_rendered(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create', ['course_offering_id' => $this->offering->id]))
            ->assertOk()
            ->getContent();

        // Hidden rather than merely ignored. A Class/Programme/Session control that
        // is guaranteed to be rejected on save is a control that misleads, and every
        // one of those values changes who a paper reaches.
        //
        // Matched as a CONTROL, not as a bare substring: the page's script contains
        // the literal `select[name="subject_id"]` as a query, so a substring check
        // would fail on the JavaScript and prove nothing about the form.
        foreach (['subject_id', 'class_id', 'programme_id', 'session_id'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(select|input)\b[^>]*\bname="'.$field.'"/',
                $html,
                "no {$field} CONTROL may be offered in Course Offering mode"
            );
        }
    }

    public function test_the_ENGINE_fields_and_the_WORD_EDITOR_SURVIVE_the_OFFERING_mode(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create', ['course_offering_id' => $this->offering->id]))
            ->assertOk()
            ->getContent();

        foreach ([
            'name="title"',
            'name="exam_type"',
            'name="start_datetime"',
            'name="end_datetime"',
            'name="duration_mins"',
            'name="total_marks"',
            'name="pass_mark"',
            'name="max_attempts"',
            'name="result_release_policy"',
            'name="shuffle_questions"',
            'name="shuffle_options"',
            'name="allow_previous_navigation"',
            'name="auto_submit"',
            'name="webcam_required"',
            'name="fullscreen_required"',
            'name="course_offering_id"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "the engine must still offer {$field}");
        }

        $this->assertStringContainsString('data-testid="exam-instructions-editor"', $html);
        $this->assertStringContainsString('academic-editor.js', $html);
    }

    public function test_a_TAMPERED_offering_id_in_the_QUERY_resolves_to_NO_context(): void
    {
        $notMine = $this->makeOffering(1, ['reference' => 'NOT-MINE-2026-S1']);

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.create', ['course_offering_id' => $notMine->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'data-testid="exam-offering-context"',
            $html,
            'an Offering the lecturer is not allocated to must not produce a derived summary'
        );
    }

    public function test_a_lecturer_with_NO_offering_and_NO_subject_still_gets_the_legacy_empty_state(): void
    {
        // The warning is for somebody with no academic assignment AT ALL. It is
        // narrowed, not deleted - a lecturer in that position still needs to be told
        // why the form is empty.
        $stranger = $this->makeUser(3, 1, 'active', 'Lecturer With Nothing');

        $html = $this->actingAs($stranger)
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No subjects are assigned to this teacher', $html);
        $this->assertStringNotContainsString('data-testid="exam-course-offering-select"', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. STORE: THE OFFERING IS THE AUTHORITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_storing_through_the_GENERIC_page_binds_the_exam_to_the_OFFERING(): void
    {
        // NO subject_id in the body. That is the point of the whole change: the
        // server already knows the Course Unit and is not going to be asked twice.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload())
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Business Mathematics Test 1')->firstOrFail();

        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
        $this->assertSame((int) $this->offering->subject_id, (int) $exam->subject_id, 'the Course Unit is DERIVED');
        $this->assertSame((int) $this->offering->school_id, (int) $exam->school_id, 'the tenant comes from the Offering');
        $this->assertNull($exam->class_id, 'a class must never be written for a Course Offering exam');
        $this->assertNull($exam->programme_id);
        $this->assertNull($exam->session_id);
    }

    public function test_the_CREATED_exam_goes_STRAIGHT_to_the_ENGINES_question_page(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload())
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Business Mathematics Test 1')->firstOrFail();

        $response->assertRedirect(route('teacher.online_exams.questions.index', $exam->id));
    }

    public function test_CREATING_does_NOT_publish_it(): void
    {
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload())
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Business Mathematics Test 1')->firstOrFail();

        // Governance is untouched by any of this. A lecturer lands in the Admin queue.
        $this->assertSame('pending_review', $exam->workflow_state);
        $this->assertFalse((bool) $exam->is_published);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. SECURITY: THE BROWSER IS NOT BELIEVED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_TAMPERED_subject_id_is_REFUSED_rather_than_silently_replaced(): void
    {
        $otherUnit = $this->makeSubject(1);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                'title' => 'Tampered Course Unit',
                'subject_id' => $otherUnit,
            ]))
            ->assertSessionHasErrors('subject_id');

        $this->assertDatabaseMissing('online_exams', ['title' => 'Tampered Course Unit']);
    }

    public function test_a_POSTED_class_programme_or_SESSION_is_REFUSED(): void
    {
        $classId = $this->makeClass(1);

        foreach (['class_id' => $classId, 'programme_id' => 1, 'session_id' => 1] as $field => $value) {
            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                    'title' => 'Legacy steering via '.$field,
                    $field => $value,
                ]))
                ->assertSessionHasErrors($field);

            $this->assertDatabaseMissing('online_exams', ['title' => 'Legacy steering via '.$field]);
        }
    }

    public function test_ANOTHER_TENANTS_offering_is_REFUSED_on_the_generic_route(): void
    {
        $theirs = $this->otherInstitution();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                'course_offering_id' => $theirs['offering']->id,
            ]))
            ->assertSessionHasErrors('course_offering_id');

        $this->assertDatabaseMissing('online_exams', ['course_offering_id' => $theirs['offering']->id]);
    }

    public function test_an_OFFERING_the_lecturer_is_NOT_allocated_to_is_REFUSED(): void
    {
        $notMine = $this->makeOffering(1, ['reference' => 'NOT-MINE-2026-S1']);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                'course_offering_id' => $notMine->id,
            ]))
            ->assertSessionHasErrors('course_offering_id');

        $this->assertDatabaseMissing('online_exams', ['course_offering_id' => $notMine->id]);
    }

    public function test_an_OFFERING_that_is_NOT_being_taught_is_REFUSED(): void
    {
        $closed = $this->makeOffering(1, ['reference' => 'CLOSED-2026-S1', 'status' => 'completed']);
        $this->allocateLecturer($closed, $this->lecturer);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                'course_offering_id' => $closed->id,
            ]))
            ->assertSessionHasErrors('course_offering_id');

        $this->assertDatabaseMissing('online_exams', ['course_offering_id' => $closed->id]);
    }

    public function test_ANOTHER_LECTURERS_ALLOCATION_does_NOT_grant_authoring(): void
    {
        // A second lecturer in the SAME institution, allocated to a different course.
        $colleagueCourse = $this->makeOffering(1, ['reference' => 'COLLEAGUE-2026-S1']);
        $colleague = $this->makeUser(3, 1, 'active', 'Someone Else');
        $this->allocateLecturer($colleagueCourse, $colleague);

        $this->actingAs($colleague)
            ->post(route('teacher.online_exams.store'), $this->offeringPayload([
                'course_offering_id' => $this->offering->id,
                'title' => 'Poached course paper',
            ]))
            ->assertSessionHasErrors('course_offering_id');

        $this->assertDatabaseMissing('online_exams', ['title' => 'Poached course paper']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. EDIT AND UPDATE KEEP THE SAME AUTHORITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_EDIT_page_of_a_COURSE_OFFERING_exam_shows_the_DERIVED_context(): void
    {
        $exam = $this->offeringExam();

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.edit', $exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="exam-offering-context"', $html);
        $this->assertStringContainsString('Semester 1', $html);

        foreach (['subject_id', 'class_id', 'programme_id', 'session_id'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(select|input)\b[^>]*\bname="'.$field.'"/',
                $html,
                "no {$field} CONTROL is editable on a Course Offering exam"
            );
        }
    }

    public function test_an_OFFERING_exam_is_UPDATABLE_without_resupplying_the_Course_Unit(): void
    {
        $exam = $this->offeringExam(['workflow_state' => 'pending_review', 'is_published' => 0]);

        $this->actingAs($this->lecturer)
            ->put(route('teacher.online_exams.update', $exam->id), $this->offeringPayload([
                'title' => 'Renamed course paper',
            ]))
            ->assertSessionHasNoErrors();

        $exam->refresh();

        $this->assertSame('Renamed course paper', $exam->title);
        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
        $this->assertSame((int) $this->offering->subject_id, (int) $exam->subject_id);
        $this->assertNull($exam->class_id);
    }

    public function test_an_OFFERING_exam_cannot_be_MOVED_to_another_OFFERING(): void
    {
        $other = $this->makeOffering(1, ['reference' => 'OTHER-COURSE-2026-S1']);
        $this->allocateLecturer($other, $this->lecturer);

        $exam = $this->offeringExam();

        $this->actingAs($this->lecturer)
            ->put(route('teacher.online_exams.update', $exam->id), $this->offeringPayload([
                'course_offering_id' => $other->id,
            ]))
            ->assertSessionHasErrors('course_offering_id');

        $this->assertSame(
            (int) $this->offering->id,
            (int) $exam->fresh()->course_offering_id,
            'the exam must stay on its own course'
        );
    }

    public function test_a_LEGACY_exam_cannot_be_CONVERTED_into_a_COURSE_OFFERING_exam(): void
    {
        $legacy = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->makeSubject(1),
            'class_id' => null,
            'course_offering_id' => null,
            'title' => 'A historical legacy paper',
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]));

        $this->actingAs($this->lecturer)
            ->put(route('teacher.online_exams.update', $legacy), [
                'title' => 'A historical legacy paper',
                'exam_type' => 'quiz',
                'subject_id' => $legacy->subject_id,
                'course_offering_id' => $this->offering->id,
                'start_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_datetime' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'duration_mins' => 30,
                'total_marks' => 10,
                'pass_mark' => 5,
                'max_attempts' => 1,
                'result_release_policy' => 'immediate',
            ])
            ->assertSessionHasErrors('course_offering_id');

        $this->assertNull(
            $legacy->fresh()->course_offering_id,
            'backward compatibility means no automatic attachment, in either direction'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. LEGACY MODE IS UNTOUCHED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LEGACY_exam_is_still_created_through_the_GENERIC_page(): void
    {
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);

        // Give this lecturer a genuine legacy appointment, so there is a real
        // legacy workflow to exercise.
        DB::table('teacher_permissions')->insert([
            'class_id' => $classId,
            'section_id' => 1,
            'school_id' => 1,
            'teacher_id' => $this->lecturer->id,
            'marks' => 1,
            'attendance' => 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.store'), [
                'title' => 'A legacy class paper',
                'exam_type' => 'quiz',
                'subject_id' => $subjectId,
                'class_id' => $classId,
                'start_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_datetime' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'duration_mins' => 30,
                'total_marks' => 10,
                'pass_mark' => 5,
                'max_attempts' => 1,
                'result_release_policy' => 'immediate',
            ])
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'A legacy class paper')->firstOrFail();

        $this->assertNull($exam->course_offering_id, 'a legacy exam stays legacy');
        $this->assertSame($subjectId, (int) $exam->subject_id);
        $this->assertSame($classId, (int) $exam->class_id);
    }

    public function test_a_LEGACY_EXAM_edit_page_still_offers_the_LEGACY_selectors(): void
    {
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);

        $legacy = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'subject_id' => $subjectId,
            'class_id' => $classId,
            'course_offering_id' => null,
            'title' => 'An old K12 paper',
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]));

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.edit', $legacy->id))
            ->assertOk()
            ->getContent();

        foreach (['name="subject_id"', 'name="class_id"', 'name="programme_id"', 'name="session_id"'] as $field) {
            $this->assertStringContainsString($field, $html, "{$field} must still be offered for a legacy exam");
        }

        $this->assertStringNotContainsString('data-testid="exam-offering-context"', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. A LECTURER SEES EXAMS FOR COURSES THEY ARE ALLOCATED TO TEACH
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_CO_LECTURER_SEES_an_OFFERING_exam_they_did_NOT_author(): void
    {
        $exam = $this->offeringExam(['title' => 'Co-taught course paper']);

        $coLecturer = $this->makeUser(3, 1, 'active', 'Co Lecturer');
        $this->allocateLecturer($this->offering, $coLecturer, ['role' => 'co_lecturer']);

        $html = $this->actingAs($coLecturer)
            ->get(route('teacher.online_exams.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Co-taught course paper',
            $html,
            'an allocated lecturer must see the assessments of the course they teach'
        );

        // And a lecturer with no allocation must not. The arm added to the ownership
        // scope keys on the allocation table, so it can only widen for real colleagues.
        $stranger = $this->makeUser(3, 1, 'active', 'Unrelated Lecturer');

        $this->assertStringNotContainsString(
            'Co-taught course paper',
            $this->actingAs($stranger)->get(route('teacher.online_exams.index'))->assertOk()->getContent(),
            'an unallocated lecturer must not see it'
        );

        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. THE OFFERING'S OWN TAB NAMES THE COURSE AND THE TERM
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_WORKSPACE_Quizzes_and_Exams_tab_shows_the_COURSE_not_a_legacy_list(): void
    {
        // The workspace tab is wired to the existing Online Exam list - a route that
        // is pinned by `CourseOfferingWorkspaceNavigationTest` and must stay wired
        // there - so the list itself has to carry the course. That is what this
        // asserts, and it is the difference between "these are the assessments for
        // my course" and "here is a generic examination system".
        $exam = $this->offeringExam(['title' => 'A paper of this course']);

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('A paper of this course', $html);
        $this->assertStringContainsString($this->offering->reference, $html, 'the Offering is named on the row');

        $this->assertStringNotContainsString(
            'All classes',
            $html,
            'a Course Offering exam must not be described as reaching all classes'
        );

        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
    }

    public function test_a_LEGACY_row_on_the_same_list_STILL_shows_its_CLASS(): void
    {
        $classId = $this->makeClass(1);

        OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->makeSubject(1, $classId),
            'class_id' => $classId,
            'course_offering_id' => null,
            'title' => 'A legacy class paper',
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]));

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('A legacy class paper', $html);
        $this->assertStringContainsString('Class 1', $html, 'a legacy row keeps its class name');
    }

    public function test_the_Quizzes_and_Exams_tab_NAMES_the_course_and_the_term(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.exams.index', $this->offering))
            ->assertOk()
            ->getContent();

        $offering = $this->offering;

        $this->assertStringContainsString($offering->subject->name, $html, 'the course is named');
        $this->assertStringContainsString('Quizzes &amp; Exams', $html);
        $this->assertStringContainsString('2026/2027', $html, 'the term is stated');
        $this->assertStringContainsString('Semester 1', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. STUDENT ELIGIBILITY AND NOTIFICATION RECIPIENTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_CONFIRMED_student_sees_the_OFFERING_exam_and_a_stranger_does_not(): void
    {
        $exam = $this->offeringExam(['title' => 'Visible to the confirmed only']);

        $confirmed = $this->makeUser(7, 1, 'active', 'Confirmed Student');
        $this->confirmStudent($this->offering, $confirmed);

        // In this institution, on no Offering at all. This is the student the old
        // NULL-class rule would have handed the paper to.
        $stranger = $this->makeUser(7, 1, 'active', 'Student On Another Course');

        $onList = fn (User $student) => $this->actingAs($student)
            ->get(route('student.online_exam.list'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Visible to the confirmed only', $onList($confirmed));
        $this->assertStringNotContainsString('Visible to the confirmed only', $onList($stranger));

        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
    }

    public function test_notification_recipients_are_the_CONFIRMED_students_of_the_OFFERING(): void
    {
        $exam = $this->offeringExam();

        $one = $this->makeUser(7, 1, 'active', 'Confirmed One');
        $this->confirmStudent($this->offering, $one);

        // `registered` is deliberately NOT enough, and this is the same distinction
        // the visibility rule draws.
        $awaiting = $this->makeUser(7, 1, 'active', 'Awaiting Confirmation');
        $this->confirmStudent($this->offering, $awaiting, 'registered');

        $dropped = $this->makeUser(7, 1, 'active', 'Dropped Out');
        $this->confirmStudent($this->offering, $dropped, 'dropped');

        $unrelated = $this->makeUser(7, 1, 'active', 'Not On This Course');

        $recipients = \App\Support\OnlineExams\OnlineExamRecipients::forExam($exam);

        $this->assertContains((int) $one->id, $recipients);
        $this->assertNotContains((int) $awaiting->id, $recipients);
        $this->assertNotContains((int) $dropped->id, $recipients);
        $this->assertNotContains((int) $unrelated->id, $recipients);
    }

    public function test_a_LEGACY_SCHOOL_WIDE_exam_KEPT_its_NULL_class_audience(): void
    {
        // Nine live exams depend on this. Narrowing it "for safety" would silently
        // withdraw a school-wide paper from every student who never sat it.
        $schoolWide = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->makeSubject(1),
            'class_id' => null,
            'course_offering_id' => null,
            'title' => 'Whole institution notice exam',
            'workflow_state' => 'published',
            'is_published' => 1,
        ]));

        $anyone = $this->makeUser(7, 1, 'active', 'Any Student At All');

        $this->assertStringContainsString(
            'Whole institution notice exam',
            $this->actingAs($anyone)->get(route('student.online_exam.list'))->assertOk()->getContent()
        );

        $this->assertContains(
            (int) $anyone->id,
            \App\Support\OnlineExams\OnlineExamRecipients::forExam($schoolWide),
            'the legacy NULL-class arm is character-for-character unchanged'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A published Offering exam, owned by the lecturer, inside its window.
     *
     * `creator_id` AND `created_by` are set because `canManageExam()` requires
     * ownership, and ownership is `creator_id ?: created_by`.
     */
    private function offeringExam(array $overrides = []): OnlineExam
    {
        $id = $this->makeExam(array_merge([
            'school_id' => $this->offering->school_id,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
            'title' => 'Business Mathematics Test 1',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ], $overrides));

        return OnlineExam::query()->findOrFail($id);
    }

    /**
     * A complete, valid Course Offering exam submission.
     *
     * No `subject_id`, no `class_id`, no `programme_id`, no `session_id` - the point
     * being that a Course Offering exam is fully described without them.
     */
    private function offeringPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Business Mathematics Test 1',
            'exam_type' => 'quiz',
            'course_offering_id' => $this->offering->id,
            'start_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_datetime' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration_mins' => 60,
            'total_marks' => 20,
            'pass_mark' => 10,
            'max_attempts' => 1,
            'result_release_policy' => 'after_exam_end',
            'instructions' => '<p>Answer <b>all</b> questions.</p>',
        ], $overrides);
    }
}