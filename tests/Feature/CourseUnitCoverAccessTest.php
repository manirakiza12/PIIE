<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Subject;
use App\Support\Images\CourseUnitCoverImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class CourseUnitCoverAccessTest extends TestCase
{
    use AssignmentFixture;

    private function upload(): Subject
    {
        Storage::fake('local');
        Schema::table('subjects', fn (Blueprint $table) => $table->string('cover_image_path')->nullable());
        $subject = Subject::findOrFail($this->offering->subject_id);
        $request = Request::create('/', 'POST');
        $request->files->set('cover_image', UploadedFile::fake()->image('cover.jpg', 320, 180));
        app(CourseUnitCoverImage::class)->save($request, $subject);
        return $subject->fresh();
    }

    public function test_confirmed_student_reads_private_unit_cover_through_existing_course_route(): void
    {
        $subject = $this->upload();
        $response = $this->actingAs($this->student)->get(route('student.courses.cover', $this->offering->id));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse(str_starts_with($subject->cover_image_path, 'public/'));
    }

    public function test_pending_and_foreign_students_cannot_read_unit_cover(): void
    {
        $this->upload();
        DB::table('course_registrations')->where('student_id', $this->student->id)->update(['status' => 'pending']);
        $this->actingAs($this->student)->get(route('student.courses.cover', $this->offering->id))->assertNotFound();
        $this->student->school_id = 999;
        $this->actingAs($this->student)->get(route('student.courses.cover', $this->offering->id))->assertRedirect();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->student);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Controllers\StudentCourseAssignmentController::class)->coverImage($request, $this->offering->id);
    }

    public function test_cover_preview_and_writes_require_academic_structure_permission(): void
    {
        $subject = $this->upload();
        $this->actingAs($this->student)->get(route('admin.subject.cover', $subject->id))->assertRedirect();
        $this->actingAs($this->student)->post(route('admin.subject.update', $subject->id), ['remove_cover_image' => 1])->assertRedirect();
        Storage::disk('local')->assertExists($subject->cover_image_path);
        $this->actingAs($this->lecturer)->get(route('admin.subject.cover', $subject->id))->assertForbidden();
        $this->actingAs($this->lecturer)->post(route('admin.subject.update', $subject->id), ['remove_cover_image' => 1])->assertForbidden();
        $this->assertSame('academic.structure', app(\App\Support\Permissions\PermissionService::class)->routePermission('admin.subject.cover'));
    }
}
