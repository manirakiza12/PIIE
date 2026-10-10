<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: GET /teacher/attendance returned HTTP 500 ("Call to a member
 * function toArray() on null") when a teacher_permissions row pointed at a
 * class that no longer exists. Orphaned permission rows must be skipped.
 */
class TeacherDailyAttendanceOrphanClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated in-memory SQLite: never the developer database.
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');

        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->timestamps();
        });
        Schema::create('exam_categories', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('school_id')->nullable(); $t->timestamps(); });
        Schema::create('sessions', function (Blueprint $t) { $t->id(); $t->string('session_title')->nullable(); $t->unsignedBigInteger('school_id')->nullable(); $t->string('status')->nullable(); $t->timestamps(); });
        Schema::create('schools', function (Blueprint $t) { $t->id(); $t->string('title')->nullable(); $t->unsignedBigInteger('running_session')->nullable(); $t->timestamps(); });
        Schema::create('daily_attendances', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('class_id')->nullable(); $t->unsignedBigInteger('section_id')->nullable(); $t->unsignedBigInteger('school_id')->nullable(); $t->unsignedBigInteger('session_id')->nullable(); $t->unsignedBigInteger('student_id')->nullable(); $t->integer('timestamp')->nullable(); $t->integer('status')->nullable(); $t->timestamps(); });
        Schema::create('teacher_permissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('section_id')->nullable();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->integer('marks')->nullable();
            $t->integer('attendance')->nullable();
            $t->timestamps();
        });
    }

    public function test_daily_attendance_skips_permissions_whose_class_was_deleted(): void
    {
        DB::table('classes')->insert(['id' => 40, 'name' => 'Existing class', 'school_id' => 1]);
        DB::table('teacher_permissions')->insert([
            ['class_id' => 1, 'teacher_id' => 3, 'school_id' => 1],   // orphan: class 1 does not exist
            ['class_id' => 40, 'teacher_id' => 3, 'school_id' => 1],
        ]);

        $teacher = new User();
        $teacher->id = 3;
        $teacher->role_id = 3;
        $teacher->school_id = 1;
        $this->actingAs($teacher);

        $view = (new TeacherController())->dailyAttendance();

        $classes = array_values($view->getData()['classes']);
        $this->assertCount(1, $classes);
        $this->assertSame(40, (int) $classes[0]['id']);
    }

    /** Teacher 3 holds one orphaned permission (class 1 deleted) and one valid (class 40). */
    private function actAsTeacherWithAnOrphanedPermission(): void
    {
        DB::table('classes')->insert(['id' => 40, 'name' => 'Existing class', 'school_id' => 1]);
        DB::table('schools')->insert(['id' => 1, 'title' => 'S', 'running_session' => 1]);
        DB::table('teacher_permissions')->insert([
            ['class_id' => 1, 'section_id' => 1, 'teacher_id' => 3, 'school_id' => 1, 'marks' => 1, 'attendance' => 1],
            ['class_id' => 40, 'section_id' => 1, 'teacher_id' => 3, 'school_id' => 1, 'marks' => 1, 'attendance' => 1],
        ]);

        $teacher = new User();
        $teacher->id = 3;
        $teacher->role_id = 3;
        $teacher->school_id = 1;
        $this->actingAs($teacher);
    }

    private function assertOnlyTheExistingClassSurvives(array $classes): void
    {
        $classes = array_values($classes);
        $this->assertCount(1, $classes);
        $this->assertSame(40, (int) $classes[0]['id']);
    }

    public function test_marks_page_skips_permissions_whose_class_was_deleted(): void
    {
        $this->actAsTeacherWithAnOrphanedPermission();
        $view = (new TeacherController())->marks();
        $this->assertOnlyTheExistingClassSurvives($view->getData()['classes']);
    }

    public function test_syllabus_list_skips_permissions_whose_class_was_deleted(): void
    {
        $this->actAsTeacherWithAnOrphanedPermission();
        $view = (new TeacherController())->list_of_syllabus(new \Illuminate\Http\Request());
        $this->assertOnlyTheExistingClassSurvives($view->getData()['permitted_classes']);
    }

    public function test_syllabus_modal_skips_permissions_whose_class_was_deleted(): void
    {
        $this->actAsTeacherWithAnOrphanedPermission();
        $view = (new TeacherController())->show_syllabus_modal(new \Illuminate\Http\Request());
        $this->assertOnlyTheExistingClassSurvives($view->getData()['classes']);
    }

    public function test_take_attendance_skips_permissions_whose_class_was_deleted(): void
    {
        $this->actAsTeacherWithAnOrphanedPermission();
        $view = (new TeacherController())->takeAttendance();
        $this->assertOnlyTheExistingClassSurvives($view->getData()['classes']);
    }

    public function test_daily_attendance_filter_skips_permissions_whose_class_was_deleted(): void
    {
        $this->actAsTeacherWithAnOrphanedPermission();
        $request = \Illuminate\Http\Request::create('/teacher/attendance/filter', 'GET', [
            'month' => 'January', 'year' => '2026', 'class_id' => 40, 'section_id' => 1,
        ]);
        $view = (new TeacherController())->dailyAttendanceFilter($request);
        $this->assertOnlyTheExistingClassSurvives($view->getData()['classes']);
    }
}
