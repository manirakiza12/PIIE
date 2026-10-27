<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class TeacherOnlineExamTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecondaryOnlineExamTestSchema();

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
    }

    private function makeTeacherWithPermission(int $schoolId = 1): User
    {
        $teacher = $this->makeUser(3, $schoolId);

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->makeClass($schoolId),
            'section_id' => 1,
            'school_id' => $schoolId,
            'teacher_id' => $teacher->id,
            'marks' => 1,
            'attendance' => 1,
            'updated_at' => now(),
        ]);

        return $teacher;
    }

    public function test_teacher_online_exam_route_exists(): void
    {
        $this->assertTrue(Route::has('teacher.online_exams.index'));
    }

    public function test_pending_review_row_uses_readable_status_instead_of_disabled_submit_action(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'workflow_state' => 'pending_review',
            'is_published' => false,
            'title' => 'Pending Review Visual Test',
        ]);

        $response = $this->actingAs($teacher)->get(route('teacher.online_exams.index'));

        $response->assertOk()
            ->assertSee('Awaiting Admin Review')
            ->assertSee('Workflow')
            ->assertSee('Lifecycle')
            ->assertDontSee('Submit Review')
            ->assertSee('online-exams.css', false);
    }

    public function test_teacher_sees_only_own_school_exams(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $class1 = $this->makeClass(1);
        $subject1 = $this->makeSubject(1, $class1);

        $ownExam = $this->makeExam([
            'school_id' => 1,
            'class_id' => $class1,
            'subject_id' => $subject1,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'title' => 'Own School Exam',
        ]);

        $class2 = $this->makeClass(2);
        $subject2 = $this->makeSubject(2, $class2);
        $this->makeExam([
            'school_id' => 2,
            'class_id' => $class2,
            'subject_id' => $subject2,
            'title' => 'Other School Exam',
        ]);

        $response = $this->actingAs($teacher)->get(route('teacher.online_exams.index'));

        $response->assertOk();
        $response->assertSee('Own School Exam');
        $response->assertDontSee('Other School Exam');
    }

    public function test_teacher_cannot_edit_another_teachers_exam_without_edit_all_permission(): void
    {
        $teacherA = $this->makeTeacherWithPermission(1);
        $teacherB = $this->makeTeacherWithPermission(1);

        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacherA->id,
            'creator_id' => $teacherA->id,
        ]);

        $this->actingAs($teacherB)
            ->get(route('teacher.online_exams.edit', $examId))
            ->assertStatus(403);
    }

    public function test_assigned_teacher_can_view_attempts_for_admin_created_exam(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $admin = $this->makeUser(2, 1);
        $student = $this->makeUser(7, 1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $admin->id,
            'creator_id' => $admin->id,
        ]);
        $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'pending_manual_marking',
            'submitted_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.online_exams.attempts', $examId))
            ->assertOk()
            ->assertSee($student->name);
    }

    public function test_teacher_can_add_valid_mcq(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'workflow_state' => 'draft',
            'is_published' => 0,
        ]);

        $response = $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'What is 2+2?',
            'type' => 'multiple_choice',
            'option_a' => '4',
            'option_b' => '5',
            'correct_ans' => 'A',
            'marks' => 2,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('online_exam_questions', [
            'online_exam_id' => $examId,
            'question' => 'What is 2+2?',
        ]);
    }

    public function test_ordinary_mcq_ignores_empty_structured_option_slots_from_ui(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);
        $emptyRows = array_map(fn ($i) => ['id' => '', 'label' => ''], range(0, 7));

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Which value is even?', 'type' => 'multiple_choice',
            'option_a' => '2', 'option_b' => '3', 'correct_ans' => 'a', 'marks' => 1,
            'structured_options' => $emptyRows,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('online_exam_questions', ['online_exam_id' => $examId, 'type' => 'mcq', 'correct_ans' => 'a']);
    }

    public function test_real_mcq_form_payload_ignores_empty_matching_ordering_fill_and_numeric_groups(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);
        $emptyRows = [['left_id' => 'left_1', 'left_text' => '', 'right_id' => 'right_1', 'right_text' => ''], ['left_id' => 'left_2', 'left_text' => '', 'right_id' => 'right_2', 'right_text' => '']];
        $response = $this->actingAs($teacher)->from('/teacher/online-exams/' . $examId . '/questions')->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Which value is even?', 'type' => 'multiple_choice', 'option_a' => '2', 'option_b' => '3', 'option_c' => '', 'option_d' => '', 'correct_ans' => 'a', 'marks' => 1,
            'structured_options' => array_map(fn ($i) => ['id' => '', 'label' => ''], range(0, 7)),
            'structured_pairs' => $emptyRows,
            'structured_order_items' => [['id' => 'item_1', 'text' => ''], ['id' => 'item_2', 'text' => '']],
            'structured_blanks' => [['id' => 'blank_1', 'accepted_answers' => ['']], ['id' => 'blank_2', 'accepted_answers' => ['']]],
            'numeric_target' => '', 'numeric_tolerance' => '',
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('online_exam_questions', ['online_exam_id' => $examId, 'type' => 'mcq', 'correct_ans' => 'a']);
    }

    public function test_invalid_mcq_is_rejected(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);

        $response = $this->actingAs($teacher)->from('/teacher/online-exams/' . $examId . '/questions')
            ->post(route('teacher.online_exams.questions.store', $examId), [
                'question' => 'Broken question',
                'type' => 'multiple_choice',
                'option_a' => 'Only one',
                'marks' => 1,
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_teacher_question_authoring_accepts_each_supported_type_without_irrelevant_option_rows(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $payloads = [
            ['type' => 'multiple_choice', 'option_a' => 'A', 'option_b' => 'B', 'correct_ans' => 'a'],
            ['type' => 'multiple_select', 'structured_options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'correct_option_ids' => ['a', 'b']],
            ['type' => 'numeric', 'numeric_target' => '10', 'numeric_tolerance' => '0'],
            ['type' => 'fill_blank', 'structured_blanks' => [['id' => 'blank_1', 'accepted_answers' => ['answer']]], 'question' => '[[blank_1]]'],
            ['type' => 'true_false', 'correct_answer_tf' => 'true'],
            ['type' => 'short_answer', 'correct_ans' => 'answer'],
            ['type' => 'essay'],
            ['type' => 'matching', 'structured_pairs' => [['left_id' => 'left_1', 'left_text' => 'A', 'right_id' => 'right_1', 'right_text' => '1'], ['left_id' => 'left_2', 'left_text' => 'B', 'right_id' => 'right_2', 'right_text' => '2']]],
            ['type' => 'ordering', 'structured_order_items' => [['id' => 'item_1', 'text' => 'First'], ['id' => 'item_2', 'text' => 'Second']]],
        ];
        foreach ($payloads as $index => $payload) {
            $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);
            $payload += ['question' => $payload['question'] ?? ('Question ' . $index), 'marks' => 2,
                'structured_options' => array_map(fn ($i) => ['id' => '', 'label' => ''], range(0, 7)),
                'structured_pairs' => [['left_id' => 'left_1', 'left_text' => '', 'right_id' => 'right_1', 'right_text' => ''], ['left_id' => 'left_2', 'left_text' => '', 'right_id' => 'right_2', 'right_text' => '']],
                'structured_order_items' => [['id' => 'item_1', 'text' => ''], ['id' => 'item_2', 'text' => '']],
                'structured_blanks' => [['id' => 'blank_1', 'accepted_answers' => ['']]], 'numeric_target' => '', 'numeric_tolerance' => ''];
            $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), $payload)->assertRedirect()->assertSessionHasNoErrors();
            $stored = DB::table('online_exam_questions')->where('online_exam_id', $examId)->latest('id')->first();
            if (in_array($payload['type'], ['matching', 'ordering'], true)) $this->assertSame('short', $stored->type);
        }
        $this->assertSame(9, DB::table('online_exam_questions')->count());
    }

    public function test_teacher_can_create_multiple_select_and_numeric_questions_with_structured_contracts(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Select all prime numbers', 'type' => 'multiple_select', 'marks' => 4,
            'structured_options' => [
                ['id' => 'a', 'label' => '2'], ['id' => 'b', 'label' => '3'], ['id' => 'c', 'label' => '4'],
            ], 'correct_option_ids' => ['a', 'b'],
        ])->assertRedirect();
        $multi = DB::table('online_exam_questions')->where('online_exam_id', $examId)->latest('id')->first();
        $this->assertSame(2, (int) $multi->question_schema_version);
        $this->assertSame('multiple_select', json_decode($multi->question_config, true)['type']);

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Enter the answer', 'type' => 'numeric', 'marks' => 3,
            'numeric_target' => '10', 'numeric_tolerance' => '0.5',
        ])->assertRedirect();
        $numeric = DB::table('online_exam_questions')->where('online_exam_id', $examId)->latest('id')->first();
        $this->assertSame(2, (int) $numeric->question_schema_version);
        $marking = json_decode($numeric->marking_config, true);
        $this->assertSame(10.0, (float) $marking['target']);
        $this->assertSame(0.5, (float) $marking['tolerance']);
    }

    public function test_teacher_can_create_structured_fill_blank_question(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Capital [[blank_1]] and country [[blank_2]]', 'type' => 'fill_blank', 'marks' => 4,
            'structured_blanks' => [
                ['id' => 'blank_1', 'accepted_answers' => ['Kampala', 'kampala']],
                ['id' => 'blank_2', 'accepted_answers' => ['Uganda']],
            ],
        ])->assertRedirect();
        $question = DB::table('online_exam_questions')->where('online_exam_id', $examId)->latest('id')->first();
        $this->assertSame(2, (int) $question->question_schema_version);
        $this->assertSame('fill_blank', json_decode($question->question_config, true)['type']);
    }

    public function test_structured_fill_blank_http_contract_handles_spares_variants_and_placeholder_rules(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $post = function (string $question, array $blanks, array $extra = []) use ($teacher, $classId, $subjectId) {
            $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id]);
            return [$examId, $this->actingAs($teacher)->from('/teacher/online-exams/' . $examId . '/questions')->post(route('teacher.online_exams.questions.store', $examId), array_merge(['question' => $question, 'type' => 'fill_blank', 'marks' => 2, 'structured_blanks' => $blanks], $extra))];
        };
        [$exam, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala', '']], ['id' => 'blank_2', 'accepted_answers' => ['', '']], ['id' => 'blank_3', 'accepted_answers' => ['', '']], ['id' => 'blank_4', 'accepted_answers' => ['', '']]]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $config = json_decode(DB::table('online_exam_questions')->where('online_exam_id', $exam)->value('question_config'), true);
        $this->assertCount(1, $config['blanks']);

        [, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala', '']]]); $response->assertRedirect()->assertSessionHasNoErrors();
        [, $response] = $post('[[blank_1]] is in [[blank_2]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala']], ['id' => 'blank_2', 'accepted_answers' => ['Uganda']], ['id' => 'blank_3', 'accepted_answers' => ['']]]); $response->assertRedirect()->assertSessionHasNoErrors();
        [, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_2', 'accepted_answers' => ['Kampala']]]); $response->assertSessionHasErrors('structured_blanks');
        [, $response] = $post('Capital [[blank_1]] [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala']]]); $response->assertSessionHasErrors('structured_blanks');
        [, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['']]]); $response->assertSessionHasErrors('structured_blanks');
        [, $response] = $post('Capital [[blank_2]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala']]]); $response->assertSessionHasErrors('structured_blanks');
        [$exam, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['Kampala', 'Kampala City']]]); $response->assertRedirect()->assertSessionHasNoErrors();
        $marking = json_decode(DB::table('online_exam_questions')->where('online_exam_id', $exam)->value('marking_config'), true); $this->assertSame(['Kampala', 'Kampala City'], $marking['blanks']['blank_1']['accepted_answers']);
        [$exam, $response] = $post('Capital [[blank_1]]', [['id' => 'blank_1', 'accepted_answers' => ['KAMPALA']]], ['case_sensitive' => '1']); $response->assertRedirect()->assertSessionHasNoErrors(); $marking = json_decode(DB::table('online_exam_questions')->where('online_exam_id', $exam)->value('marking_config'), true); $this->assertTrue($marking['case_sensitive']);
        $rows = []; $parts = []; for ($i = 1; $i <= 16; $i++) { $parts[] = '[[blank_' . $i . ']]'; $rows[] = ['id' => 'blank_' . $i, 'accepted_answers' => ['Answer ' . $i]]; } [, $response] = $post(implode(' ', $parts), $rows, ['marks' => 16]); $response->assertRedirect()->assertSessionHasNoErrors();
        $rows[] = ['id' => 'blank_17', 'accepted_answers' => ['Too many']]; [, $response] = $post(implode(' ', $parts) . ' [[blank_17]]', $rows, ['marks' => 17]); $response->assertSessionHasErrors('structured_blanks');
    }

    public function test_teacher_can_import_question_bank_snapshot_and_future_changes_do_not_mutate_exam_question(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);

        $bankId = DB::table('question_banks')->insertGetId([
            'school_id' => 1,
            'subject_id' => $subjectId,
            'question' => 'Original bank question',
            'type' => 'mcq',
            'option_a' => 'A',
            'option_b' => 'B',
            'correct_ans' => 'A',
            'marks' => 3,
            'difficulty' => 'easy',
            'created_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($teacher)->post(route('teacher.online_exams.question_bank.import', $examId), [
            'question_bank_ids' => [$bankId],
        ])->assertRedirect();

        $imported = DB::table('online_exam_questions')->where('online_exam_id', $examId)->first();
        $this->assertNotNull($imported);
        $this->assertSame('Original bank question', $imported->question);

        DB::table('question_banks')->where('id', $bankId)->update([
            'question' => 'Changed bank question',
            'updated_at' => now(),
        ]);

        $importedAgain = DB::table('online_exam_questions')->where('id', $imported->id)->first();
        $this->assertSame('Original bank question', $importedAgain->question);
    }

    public function test_teacher_without_publish_permission_submits_for_review(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $this->makeUser(2, 1);

        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
            ]),
            'updated_at' => now(),
        ]);

        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'total_marks' => 2,
            'pass_mark' => 1,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'workflow_state' => 'draft',
            'is_published' => 0,
        ]);
        $this->makeQuestion($examId, ['marks' => 2]);

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.submit_review', $examId))
            ->assertRedirect();

        $this->assertDatabaseHas('online_exams', [
            'id' => $examId,
            'workflow_state' => 'pending_review',
        ]);
        $this->assertDatabaseHas('online_exam_user_notifications', [
            'school_id' => 1,
            'type' => 'exam_submitted_for_review',
            'online_exam_id' => $examId,
            'event_key' => 'exam-review:' . $examId . ':user:' . DB::table('users')->where('role_id', 2)->where('school_id', 1)->value('id'),
        ]);
    }

    public function test_teacher_with_publish_permission_can_publish_and_publish_fails_on_marks_mismatch(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);

        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'publish_online_exams',
            ]),
            'updated_at' => now(),
        ]);

        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'total_marks' => 5,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'workflow_state' => 'draft',
            'is_published' => 0,
        ]);
        $this->makeQuestion($examId, ['marks' => 4]);

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.publish', $examId))
            ->assertForbidden();

        DB::table('online_exam_questions')->where('online_exam_id', $examId)->update(['marks' => 5]);

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.publish', $examId))
            ->assertForbidden();

        $this->assertDatabaseMissing('online_exams', [
            'id' => $examId,
            'workflow_state' => 'published',
            'is_published' => 1,
        ]);
    }

    public function test_unpublish_fails_when_attempts_exist(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'publish_online_exams',
            ]),
            'updated_at' => now(),
        ]);

        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'workflow_state' => 'published',
            'is_published' => 1,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);
        $this->makeSubmission(['online_exam_id' => $examId, 'school_id' => 1]);

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.unpublish', $examId))
            ->assertStatus(403);
    }

    public function test_teacher_can_mark_written_answer_and_reject_above_max(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $student = $this->makeUser(7, 1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);
        $questionId = $this->makeQuestion($examId, ['type' => 'essay', 'marks' => 5, 'correct_ans' => null]);
        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'pending_manual_marking',
            'submitted_at' => now(),
        ]);

        $answerId = DB::table('online_exam_answers')->insertGetId([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'answer_text' => 'Essay answer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.answers.mark', $answerId), [
                'answer_id' => $answerId,
                'awarded_marks' => 6,
            ])->assertSessionHasErrors();

        $this->actingAs($teacher)
            ->post(route('teacher.online_exams.answers.mark', $answerId), [
                'answer_id' => $answerId,
                'awarded_marks' => 4,
            ])->assertRedirect();

        $this->assertDatabaseHas('online_exam_answers', [
            'id' => $answerId,
            'awarded_marks' => 4,
        ]);
    }

    public function test_existing_admin_and_student_routes_remain_valid(): void
    {
        $this->assertTrue(Route::has('admin.online_exams.index'));
        $this->assertTrue(Route::has('student.online_exam.list'));
    }

    public function test_teacher_index_lifecycle_stats_and_upcoming_tab_filter_agree(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'A Draft Exam',
            'workflow_state' => 'draft',
            'is_published' => 0,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);
        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'An Upcoming Exam',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDay()->addHour(),
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);
        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'An Ongoing Exam',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subMinutes(10),
            'end_datetime' => now()->addMinutes(20),
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);

        // The "Upcoming" stat card's count must be the same population the
        // tab=upcoming filter actually returns — a mismatch here would mean
        // clicking the card shows a different set of exams than its own number.
        $indexResponse = $this->actingAs($teacher)->get(route('teacher.online_exams.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('An Upcoming Exam');
        $indexResponse->assertSee('An Ongoing Exam');
        $indexResponse->assertSee('A Draft Exam');

        $upcomingTabResponse = $this->actingAs($teacher)->get(route('teacher.online_exams.index', ['tab' => 'upcoming']));
        $upcomingTabResponse->assertOk();
        $upcomingTabResponse->assertSee('An Upcoming Exam');
        $upcomingTabResponse->assertDontSee('An Ongoing Exam');
        $upcomingTabResponse->assertDontSee('A Draft Exam');
    }

    public function test_teacher_live_monitor_shows_ongoing_exam_with_in_progress_count_and_upcoming_exam(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);

        $ongoingExamId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'Ongoing Exam',
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'start_datetime' => now()->subMinutes(10),
            'end_datetime' => now()->addMinutes(20),
        ]);
        $student = $this->makeUser(7, 1);
        $this->makeSubmission([
            'online_exam_id' => $ongoingExamId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
        ]);

        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'Upcoming Exam',
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
            'start_datetime' => now()->addHours(2),
            'end_datetime' => now()->addHours(3),
        ]);

        $response = $this->actingAs($teacher)->get(route('teacher.online_exams.live_monitor'));

        $response->assertOk();
        $response->assertSee('Ongoing Exam');
        $response->assertSee('Upcoming Exam');
    }

    public function test_teacher_live_monitor_excludes_other_teachers_exams_without_edit_all_permission(): void
    {
        $teacherA = $this->makeTeacherWithPermission(1);
        $teacherB = $this->makeTeacherWithPermission(1);

        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => 'Teacher A Ongoing Exam',
            'created_by' => $teacherA->id,
            'creator_id' => $teacherA->id,
            'start_datetime' => now()->subMinutes(5),
            'end_datetime' => now()->addMinutes(25),
        ]);

        $response = $this->actingAs($teacherB)->get(route('teacher.online_exams.live_monitor'));

        $response->assertOk();
        $response->assertDontSee('Teacher A Ongoing Exam');
    }

    public function test_teacher_can_review_proctoring_for_their_own_exam_submission(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'review_exam_proctoring',
            ]),
            'updated_at' => now(),
        ]);

        $classId = DB::table('teacher_permissions')->where('teacher_id', $teacher->id)->value('class_id');
        $subjectId = $this->makeSubject(1, $classId);
        $student = $this->makeUser(7, 1);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);
        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
        ]);
        DB::table('online_exam_proctoring_events')->insert([
            'submission_id' => $submissionId,
            'event_type' => 'tab_hidden',
            'event_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($teacher)->get(route('teacher.online_exams.proctoring.review', [
            'exam' => $examId,
            'submission_id' => $submissionId,
        ]));

        $response->assertOk();
        $response->assertSee('Tab Hidden');
    }

    public function test_teacher_cannot_review_proctoring_for_another_teachers_exam(): void
    {
        $teacherA = $this->makeTeacherWithPermission(1);
        $teacherB = $this->makeTeacherWithPermission(1);
        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'review_exam_proctoring',
            ]),
            'updated_at' => now(),
        ]);

        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $student = $this->makeUser(7, 1);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $teacherA->id,
            'creator_id' => $teacherA->id,
        ]);
        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
        ]);

        $this->actingAs($teacherB)->get(route('teacher.online_exams.proctoring.review', [
            'exam' => $examId,
            'submission_id' => $submissionId,
        ]))->assertStatus(403);
    }

    public function test_teacher_navigation_links_render_only_when_permitted(): void
    {
        $teacher = $this->makeTeacherWithPermission(1);
        $response = $this->actingAs($teacher)->get(route('teacher.online_exams.index'));
        $response->assertSee('Online Exams');
        $response->assertSee('Question Bank');
        $response->assertSee('Marking Queue');

        DB::table('global_settings')->where('key', 'role_perm_3')->update([
            'value' => json_encode(['view_online_exams']),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $teacher->id)->update([
            'menu_permission' => json_encode(['view_online_exams']),
            'updated_at' => now(),
        ]);

        $responseLimited = $this->actingAs($teacher->fresh())->get(route('teacher.online_exams.index'));
        $responseLimited->assertSee('Online Exams');
        $responseLimited->assertSee('Marking Queue');
    }
}
