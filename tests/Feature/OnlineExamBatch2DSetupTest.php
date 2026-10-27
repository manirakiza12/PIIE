<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OnlineExams\AnswerKey;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamBatch2DSetupTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecondaryOnlineExamTestSchema();

        DB::table('global_settings')->updateOrInsert(
            ['key' => 'role_perm_3'],
            ['value' => json_encode(['view_online_exams', 'manage_exam_questions', 'create_online_exams', 'edit_own_online_exams']), 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'role_perm_2'],
            ['value' => json_encode(['view_online_exams', 'manage_exam_questions', 'edit_all_online_exams']), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function teacherWithClass(int $schoolId = 1): array
    {
        $teacher = $this->makeUser(3, $schoolId);
        $classId = $this->makeClass($schoolId);
        DB::table('teacher_permissions')->insert([
            'class_id' => $classId, 'section_id' => 0, 'school_id' => $schoolId,
            'teacher_id' => $teacher->id, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);
        return [$teacher, $classId, $this->makeSubject($schoolId, $classId)];
    }

    public function test_teacher_can_create_reusable_question_for_assigned_subject(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();

        $this->actingAs($teacher)->post(route('teacher.online_exams.question_bank.store'), [
            'subject_id' => $subjectId, 'question' => 'Assigned question', 'type' => 'mcq',
            'option_a' => 'A', 'option_b' => 'B', 'correct_ans' => 'a', 'marks' => 2, 'difficulty' => 'easy',
        ])->assertRedirect();

        $this->assertDatabaseHas('question_banks', [
            'created_by' => $teacher->id, 'subject_id' => $subjectId, 'question' => 'Assigned question',
        ]);
    }

    public function test_teacher_cannot_create_reusable_question_for_unassigned_subject(): void
    {
        [$teacher] = $this->teacherWithClass();
        $otherClass = $this->makeClass(1);
        $otherSubject = $this->makeSubject(1, $otherClass);

        $this->actingAs($teacher)->post(route('teacher.online_exams.question_bank.store'), [
            'subject_id' => $otherSubject, 'question' => 'Blocked question', 'type' => 'short',
            'marks' => 1, 'difficulty' => 'easy',
        ])->assertSessionHasErrors('subject_id');

        $this->assertDatabaseMissing('question_banks', ['question' => 'Blocked question']);
    }

    public function test_teacher_can_see_admin_bank_questions_for_an_assigned_subject(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $admin = $this->makeUser(2, 1);
        DB::table('question_banks')->insert([
            'school_id' => 1, 'subject_id' => $subjectId, 'question' => 'Admin shared question',
            'type' => 'mcq', 'marks' => 1, 'difficulty' => 'easy', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($teacher)->get(route('teacher.online_exams.question_bank'))
            ->assertOk()->assertSee('Admin shared question');
    }

    public function test_admin_can_edit_a_reusable_question(): void
    {
        $admin = $this->makeUser(2, 1);
        $questionId = DB::table('question_banks')->insertGetId([
            'school_id' => 1, 'question' => 'Before edit', 'type' => 'short', 'marks' => 1,
            'difficulty' => 'easy', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('admin.question_bank.update', $questionId), [
            'question' => 'After edit', 'type' => 'short', 'marks' => 2, 'difficulty' => 'medium',
        ])->assertRedirect();

        $this->assertDatabaseHas('question_banks', ['id' => $questionId, 'question' => 'After edit', 'marks' => 2]);
    }

    public function test_mcq_authoring_stores_selected_option_key_and_invalid_key_is_rejected(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $examId = $this->makeExam(['class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'is_published' => 0, 'workflow_state' => 'draft']);

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Cow legs?', 'type' => 'multiple_choice', 'option_a' => 'Yes', 'option_b' => 'Maybe',
            'option_c' => 'No', 'marks' => 4, 'correct_ans' => 'a',
        ])->assertRedirect();
        $this->assertDatabaseHas('online_exam_questions', ['online_exam_id' => $examId, 'correct_ans' => 'a']);

        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'Invalid', 'type' => 'multiple_choice', 'option_a' => 'Yes', 'option_b' => 'No',
            'marks' => 1, 'correct_ans' => 'z',
        ])->assertSessionHasErrors('correct_ans');
    }

    public function test_mcq_edit_view_preselects_canonical_key_and_legacy_text_normalizes_safely(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $examId = $this->makeExam(['class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'is_published' => 0, 'workflow_state' => 'draft']);
        $questionId = $this->makeQuestion($examId, ['correct_ans' => 'yes', 'option_a' => 'yes', 'option_b' => 'no']);

        $this->actingAs($teacher)->get(route('teacher.online_exams.questions.index', $examId))
            ->assertOk()->assertSee('value="a"', false);
        $question = \App\Models\OnlineExamQuestion::findOrFail($questionId);
        $this->assertSame('a', AnswerKey::forQuestion($question));
    }

    public function test_question_bank_import_normalizes_legacy_mcq_answer_text_to_snapshot_key(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $examId = $this->makeExam(['class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'is_published' => 0, 'workflow_state' => 'draft']);
        $bankId = DB::table('question_banks')->insertGetId([
            'school_id' => 1, 'subject_id' => $subjectId, 'question' => 'Legacy bank MCQ', 'type' => 'mcq',
            'option_a' => 'Yes', 'option_b' => 'No', 'correct_ans' => 'Yes', 'marks' => 2, 'difficulty' => 'easy',
            'created_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($teacher)->post(route('teacher.online_exams.question_bank.import', $examId), ['question_bank_ids' => [$bankId]])->assertRedirect();
        $this->assertDatabaseHas('online_exam_questions', ['online_exam_id' => $examId, 'question_bank_id' => $bankId, 'correct_ans' => 'a']);
    }

    public function test_true_false_authoring_uses_true_false_contract(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $examId = $this->makeExam(['class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'is_published' => 0, 'workflow_state' => 'draft']);
        $this->actingAs($teacher)->post(route('teacher.online_exams.questions.store', $examId), [
            'question' => 'A cow has legs', 'type' => 'true_false', 'marks' => 1, 'correct_answer_tf' => 'true',
        ])->assertRedirect();
        $this->assertDatabaseHas('online_exam_questions', ['online_exam_id' => $examId, 'type' => 'true_false', 'correct_ans' => 'true']);
        $this->assertNull(AnswerKey::normalize('true_false', 'maybe'));
    }

    public function test_publication_readiness_accepts_legacy_matching_mcq_text_but_rejects_unknown_key(): void
    {
        [$teacher, $classId, $subjectId] = $this->teacherWithClass();
        $examId = $this->makeExam(['class_id' => $classId, 'subject_id' => $subjectId, 'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'total_marks' => 2]);
        $this->makeQuestion($examId, ['marks' => 2, 'option_a' => 'Yes', 'option_b' => 'No', 'correct_ans' => 'Yes']);
        $exam = \App\Models\OnlineExam::findOrFail($examId);
        $this->assertStringNotContainsString('invalid multiple-choice answer key', implode(' ', $exam->publicationReadinessErrors()));
        DB::table('online_exam_questions')->where('online_exam_id', $examId)->update(['correct_ans' => 'not-an-option']);
        $this->assertStringContainsString('invalid multiple-choice answer key', implode(' ', $exam->fresh()->publicationReadinessErrors()));
    }
}
