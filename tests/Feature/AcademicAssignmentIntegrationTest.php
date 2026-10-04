<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\OnlineExam;
use App\Models\TeacherPermission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class AcademicAssignmentIntegrationTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
    }

    public function test_teacher_assignment_exposes_subjects_from_assigned_class(): void
    {
        $teacher = $this->makeUser(3, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        TeacherPermission::create(['class_id' => $classId, 'section_id' => 0, 'school_id' => 1, 'teacher_id' => $teacher->id, 'marks' => 1, 'attendance' => 1, 'updated_at' => time()]);

        $assignedClassIds = TeacherPermission::where('teacher_id', $teacher->id)->pluck('class_id');
        $this->assertTrue($assignedClassIds->contains($classId));
        $this->assertDatabaseHas('subjects', ['id' => $subjectId, 'class_id' => $classId, 'school_id' => 1]);
    }

    public function test_unassigned_teacher_has_no_class_subject_access(): void
    {
        $teacher = $this->makeUser(3, 1);
        $classId = $this->makeClass(1);
        $this->makeSubject(1, $classId);

        $this->assertFalse(TeacherPermission::where('teacher_id', $teacher->id)->where('class_id', $classId)->exists());
    }

    public function test_student_enrollment_is_unique_and_drives_exam_visibility(): void
    {
        $classA = $this->makeClass(1); $classB = $this->makeClass(1);
        $student = $this->makeUser(7, 1);
        Enrollment::updateOrCreate(['user_id' => $student->id, 'school_id' => 1], ['class_id' => $classA, 'section_id' => 0, 'department_id' => 0, 'session_id' => 1]);
        Enrollment::updateOrCreate(['user_id' => $student->id, 'school_id' => 1], ['class_id' => $classB, 'section_id' => 0, 'department_id' => 0, 'session_id' => 1]);

        $this->assertSame(1, Enrollment::where('user_id', $student->id)->where('school_id', 1)->count());
        $examA = $this->makeExam(['class_id' => $classB, 'workflow_state' => 'published', 'is_published' => 1]);
        $this->assertTrue(OnlineExam::visibleToStudent(1, $classB)->whereKey($examA)->exists());
        $this->assertFalse(OnlineExam::visibleToStudent(1, $classA)->whereKey($examA)->exists());
    }

    public function test_school_isolation_applies_to_assignment_and_enrollment(): void
    {
        $teacher = $this->makeUser(3, 1); $classOtherSchool = $this->makeClass(2);
        TeacherPermission::create(['class_id' => $classOtherSchool, 'section_id' => 0, 'school_id' => 2, 'teacher_id' => $teacher->id, 'marks' => 1, 'attendance' => 1, 'updated_at' => time()]);

        $this->assertFalse(TeacherPermission::where('teacher_id', $teacher->id)->where('school_id', 1)->where('class_id', $classOtherSchool)->exists());
        $this->assertSame(0, DB::table('enrollment')->where('school_id', 1)->where('user_id', $teacher->id)->count());
    }

    public function test_education_level_keeps_core_terms_contextual(): void
    {
        $this->assertSame('Lecturer', academic_term('teacher', 1));
        // Course Units are the catalogue; Programme Cohorts are a separate domain.
        $this->assertSame('Course Units', academic_term('subjects', 1));
        $this->assertSame('Classes', academic_term('classes', 1));

        DB::table('schools')->insert([
            'id' => 2,
            'title' => 'Secondary Test School',
            'school_type' => 'k12',
            'education_level' => 'secondary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('Teacher', academic_term('teacher', 2));
        $this->assertSame('Subjects', academic_term('subjects', 2));
        $this->assertSame('Classes', academic_term('classes', 2));

        DB::table('schools')->insert([
            'id' => 3,
            'title' => 'Primary Test School',
            'school_type' => 'k12',
            'education_level' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame('Academic Term', academic_term('session', 3));
        $this->assertSame('Section / Stream', academic_term('section', 3));
    }

    public function test_tertiary_exam_persists_programme_and_session_and_filters_student_visibility(): void
    {
        $programmeId = DB::table('programmes')->insertGetId([
            'school_id' => 1, 'name' => 'Test Programme', 'code' => 'TP', 'is_active' => 1,
        ]);
        $sessionId = DB::table('sessions')->insertGetId([
            'school_id' => 1, 'session_title' => '2026 Semester 1', 'status' => 1,
        ]);
        $classId = $this->makeClass(1);
        $subjectId = DB::table('subjects')->insertGetId([
            'school_id' => 1, 'class_id' => null, 'programme_id' => $programmeId,
            'name' => 'Test Course', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->makeUser(7, 1);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => 1, 'programme_id' => $programmeId, 'status' => 'active']);
        Enrollment::create(['user_id' => $student->id, 'class_id' => $classId, 'section_id' => 0, 'school_id' => 1, 'session_id' => $sessionId, 'department_id' => 0]);

        $exam = OnlineExam::create([
            'school_id' => 1, 'title' => 'Programme Exam', 'subject_id' => $subjectId,
            'class_id' => $classId, 'programme_id' => $programmeId, 'session_id' => $sessionId,
            'workflow_state' => 'published', 'is_published' => 1, 'exam_type' => 'quiz',
            'total_marks' => 10, 'pass_mark' => 5, 'duration_mins' => 30,
        ]);

        $this->assertSame($programmeId, (int) $exam->fresh()->programme_id);
        $this->assertSame($sessionId, (int) $exam->fresh()->session_id);
        $this->assertTrue(OnlineExam::visibleToStudent(1, $classId, $programmeId, [$sessionId])->whereKey($exam->id)->exists());
        $this->assertFalse(OnlineExam::visibleToStudent(1, $classId, $programmeId + 1, [$sessionId])->whereKey($exam->id)->exists());
        $this->assertFalse(OnlineExam::visibleToStudent(1, $classId, $programmeId, [$sessionId + 1])->whereKey($exam->id)->exists());
    }
}
