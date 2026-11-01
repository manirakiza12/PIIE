<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Support\CourseContent\CourseContentService;
use App\Support\CourseContent\LearningSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class SelfPacedLearningRemediationTest extends TestCase
{
    use AssignmentFixture;

    private function open(CourseOfferingLesson $lesson)
    {
        return $this->actingAs($this->student)->get(route('student.courses.content.lessons.show', [$this->offering->id, $lesson->id]));
    }

    private function finish(CourseOfferingLesson $lesson)
    {
        return $this->actingAs($this->student)->post(route('student.courses.content.lessons.complete', [$this->offering->id, $lesson->id]));
    }

    private function sequential(bool $enabled = true): void
    {
        DB::table('course_offerings')->where('id', $this->offering->id)->update(['sequential_lessons' => $enabled]);
        $this->offering->refresh();
    }

    public function test_multiple_lessons_can_be_completed_on_the_same_day_and_revisited(): void
    {
        $this->sequential();
        $module = $this->module();
        $first = $this->lesson($module);
        $second = $this->lesson($module);
        $this->open($second)->assertNotFound();
        $this->finish($second)->assertNotFound();
        $this->finish($first)->assertRedirect();
        $this->open($second)->assertOk();
        $this->finish($second)->assertRedirect();
        $this->open($first)->assertOk();
        $this->assertSame(2, CourseOfferingLessonProgress::where('status', 'completed')->count());
    }

    public function test_progress_survives_days_logout_and_another_session_without_duplicate_completion(): void
    {
        $this->sequential();
        $module = $this->module();
        $first = $this->lesson($module);
        $second = $this->lesson($module);
        $this->finish($first)->assertRedirect();
        $stamp = CourseOfferingLessonProgress::first()->completed_at;
        auth()->logout();
        session()->flush();
        $this->travel(4)->days();
        $this->student = $this->student->fresh();
        $this->open($second)->assertOk();
        $this->finish($first)->assertRedirect();
        $this->assertTrue(CourseOfferingLessonProgress::where('course_offering_lesson_id', $first->id)->first()->completed_at->equalTo($stamp));
        $tree = app(CourseContentService::class)->studentContent($this->student, $this->offering);
        $this->assertSame(1, $tree['completed']);
        $this->assertSame(50, $tree['percent']);
        $this->travelBack();
    }

    public function test_flexible_learning_permits_any_published_lesson_without_completing_the_first(): void
    {
        $module = $this->module();
        $this->lesson($module);
        $this->open($this->lesson($module))->assertOk();
        $this->assertSame(0, CourseOfferingLessonProgress::where('status', 'completed')->count());
    }

    public function test_continue_learning_and_lesson_links_follow_the_same_sequence_gate(): void
    {
        $this->sequential();
        $module = $this->module();
        $first = $this->lesson($module, ['title' => 'First log lesson']);
        $second = $this->lesson($module, ['title' => 'Second log lesson']);
        $route = route('student.courses.content', $this->offering->id);
        $secondUrl = route('student.courses.content.lessons.show', [$this->offering->id, $second->id]);
        $this->actingAs($this->student)->get($route)->assertOk()->assertSee('Locked')->assertDontSee($secondUrl, false);
        $this->finish($first)->assertRedirect();
        $this->actingAs($this->student)->get($route)->assertOk()->assertSee($secondUrl, false);
    }

    public function test_institution_release_time_unlocks_automatically_and_draft_parent_stays_private(): void
    {
        if (! Schema::hasColumn('schools', 'timezone')) {
            Schema::table('schools', fn ($t) => $t->string('timezone')->nullable());
        }
        DB::table('schools')->where('id', $this->school)->update(['timezone' => 'Africa/Kampala']);
        config(['app.timezone' => 'UTC']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-29 14:31:59', 'UTC'));
        // Ensure lecturer allocation is active at the traveled date (starts_on must be <= 2026-10-29)
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->where('user_id', $this->lecturer->id)
            ->update(['starts_on' => '2026-10-28']);
        $module = $this->module(['title' => 'Introduction to Administrative Logs']);
        $lesson = app(CourseContentService::class)->createLesson($this->lecturer, $module, [
            'title' => 'Introduction to Administrative Logs', 'body' => '<p>How to maintain administrative logs.</p>',
            'assignment_id' => $this->publishedAssignment()->id,
            'status' => 'published', 'released_at' => '2026-10-29T17:32',
        ]);
        $this->assertSame('14:32', $lesson->released_at->format('H:i'));
        $this->assertSame('2026-10-29T17:32', app(LearningSchedule::class)->local($lesson->released_at, $this->lecturer));
        $this->open($lesson)->assertNotFound();
        $this->assertSame(0, app(CourseContentService::class)->studentContent($this->student, $this->offering)['total']);
        $this->travel(1)->seconds();
        $this->open($lesson)->assertOk();
        $this->assertSame(1, app(CourseContentService::class)->studentContent($this->student, $this->offering)['total']);
        $module->update(['status' => 'draft']);
        $this->open($lesson)->assertNotFound();
        $this->travelBack();
    }

    public function test_draft_and_future_lessons_cannot_be_completed_or_downloaded_early(): void
    {
        Storage::fake('local');
        $lesson = $this->lesson(null, ['status' => 'draft']);
        $resource = app(CourseContentService::class)->attachFileResource($this->lecturer, $lesson, UploadedFile::fake()->create('logs.pdf', 10, 'application/pdf'));
        $this->open($lesson)->assertNotFound();
        $this->finish($lesson)->assertNotFound();
        $this->actingAs($this->student)->get(route('student.courses.content.resources.show', $resource->id))->assertNotFound();
        $lesson->update(['status' => 'published', 'released_at' => now()->addDay()]);
        $this->open($lesson)->assertNotFound();
        $this->assertDatabaseCount('course_offering_lesson_progress', 0);
    }

    public function test_real_local_upload_ignores_the_inactive_null_url_and_downloads_privately(): void
    {
        Storage::fake('local');
        $lesson = $this->lesson();
        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), [
            'type' => 'file', 'link_url' => null, 'file' => UploadedFile::fake()->create('logs.pdf', 12, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $resource = $lesson->resources()->firstOrFail();
        Storage::disk('local')->assertExists($resource->stored_name);
        $this->actingAs($this->student)->get(route('student.courses.content.resources.show', $resource->id))->assertOk();
        $this->assertSame('logs.pdf', $resource->original_name);
    }

    public function test_active_inputs_are_required_and_unsafe_extensions_are_rejected(): void
    {
        $lesson = $this->lesson();
        foreach ([['type' => 'file', 'link_url' => null], ['type' => 'link', 'link_url' => null],
            ['type' => 'file', 'file' => UploadedFile::fake()->create('payload.php', 1)]] as $payload) {
            $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), $payload)
                ->assertRedirect()->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('course_offering_lesson_resources', 0);
    }

    public function test_storage_failure_never_creates_attachment_metadata(): void
    {
        $lesson = $this->lesson();
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(CourseContentService::class)->attachFileResource($this->lecturer, $lesson, UploadedFile::fake()->create('logs.pdf', 10));
            $this->fail('Storage failure must be reported.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertDatabaseCount('course_offering_lesson_resources', 0);
        }
    }

    public function test_audit_reports_release_and_missing_files_without_changing_learning_data(): void
    {
        $this->lesson();
        $this->artisan('course-content:audit', ['offering' => $this->offering->id])
            ->expectsOutputToContain('Confirmed registrations: 1')->assertSuccessful();
        $this->assertDatabaseCount('course_offering_lesson_progress', 0);
    }

    public function test_already_published_module_has_no_redundant_publish_now_action(): void
    {
        $actions = \App\Support\CourseContent\CourseOfferingModuleLifecycle::actionsFor($this->module());
        $this->assertArrayNotHasKey('publish_now', $actions);
        $this->assertArrayHasKey('schedule', $actions);
    }
}
