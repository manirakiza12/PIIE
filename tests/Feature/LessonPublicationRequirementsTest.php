<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use App\Support\CourseContent\CourseContentService;
use App\Support\CourseContent\ModulePublicationCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class LessonPublicationRequirementsTest extends TestCase
{
    use AssignmentFixture;

    private function url(?CourseOfferingLesson $lesson = null): string
    {
        return '/teacher/course-offerings/'.$this->offering->id.'/content/lessons'.($lesson ? '/'.$lesson->id : '');
    }

    public function test_incomplete_draft_is_saved_reopened_and_keeps_rich_text_after_validation_errors(): void
    {
        $module = $this->module();
        $body = '<h2>Ratios</h2><p><strong>Assets</strong> and <em>liabilities</em>.</p><table><tr><td>10</td></tr></table>';
        $this->actingAs($this->lecturer)->post($this->url(), ['title'=>'Draft', 'status'=>'draft', 'body'=>$body, 'course_offering_module_id'=>$module->id])->assertSessionHasNoErrors();
        $lesson = CourseOfferingLesson::firstOrFail();
        $this->get($this->url($lesson).'/edit')->assertOk()->assertSee('Ratios')->assertSee('Required lesson assignment');
        $this->from($this->url($lesson).'/edit')->put($this->url($lesson), ['title'=>'Draft','status'=>'published','body'=>$body])->assertSessionHasErrors('assignment_id')->assertSessionHasInput('body', $body);
        $this->assertSame('draft', $lesson->fresh()->status);
        $this->assertSame(1, CourseOfferingLesson::count());
    }

    public function test_unrelated_assignment_does_not_satisfy_the_lesson_rule(): void
    {
        $this->publishedAssignment();
        $lesson = $this->lesson(null, ['status'=>'draft']);
        $this->actingAs($this->lecturer)->put($this->url($lesson), ['title'=>'Lesson','status'=>'published'])->assertSessionHasErrors('assignment_id');
        $this->assertSame('draft', $lesson->fresh()->status);
    }

    public function test_reusing_a_creation_key_with_different_text_cannot_silently_overwrite_a_saved_draft(): void
    {
        $module = $this->module();
        $data = ['title'=>'Original draft','body'=>'<p>Original writing.</p>','status'=>'draft',
            'course_offering_module_id'=>$module->id,'creation_key'=>(string) Str::uuid()];
        $this->actingAs($this->lecturer)->post($this->url(),$data)->assertSessionHasNoErrors();
        $data['body']='<p>Changed text in a stale tab.</p>';
        $this->post($this->url(),$data)->assertSessionHasErrors('creation_key')->assertSessionHasInput('body',$data['body']);
        $this->assertSame(1,CourseOfferingLesson::count());
        $this->assertSame('<p>Original writing.</p>',CourseOfferingLesson::first()->body);
    }

    public function test_publishing_and_republishing_with_a_valid_link_is_idempotent_and_sanitized(): void
    {
        $module = $this->module();
        $assignment = $this->publishedAssignment();
        $payload = ['title'=>'Linked lesson','status'=>'published','assignment_id'=>$assignment->id,
            'course_offering_module_id'=>$module->id,'creation_key'=>(string) Str::uuid(),
            'body'=>'<h2>Ratios</h2><p><b>Assets</b><script>alert(1)</script></p>'];
        $this->actingAs($this->lecturer)->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $lesson = CourseOfferingLesson::firstOrFail();
        $this->assertSame(1, CourseOfferingLesson::count());
        $this->assertSame(1, DB::table('assignments')->count());
        $this->assertStringNotContainsString('<script', $lesson->body);
        $this->assertStringContainsString('<h2>Ratios</h2>', $lesson->body);
        $payload['body'] = '<p>Deliberately revised content.</p>';
        $this->put($this->url($lesson), $payload)->assertSessionHasNoErrors();
        $this->put($this->url($lesson), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, CourseOfferingLesson::count());
        $this->actingAs($this->student)->get(route('student.courses.content.lessons.show', [$this->offering->id, $lesson->id]))->assertOk()->assertSee('Deliberately revised content.');
    }

    public function test_an_assignment_cannot_be_shared_with_a_different_lesson_or_cross_course_or_school(): void
    {
        $assignment = $this->publishedAssignment();
        $first = $this->lesson(null, ['assignment_id'=>$assignment->id]);
        $second = $this->lesson(null, ['status'=>'draft']);
        $this->actingAs($this->lecturer)->put($this->url($second), ['title'=>'Second','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $assignment->update(['course_offering_id'=>$this->offering->id+100]);
        $this->put($this->url($second), ['title'=>'Second','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $assignment->update(['course_offering_id'=>$this->offering->id, 'school_id'=>$this->school+100]);
        $this->put($this->url($second), ['title'=>'Second','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $outsider = $this->user('Unallocated lecturer', 3);
        $this->actingAs($outsider)->put($this->url($first), ['title'=>'Not mine','status'=>'published'])->assertNotFound();
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_draft_or_invalid_assignments_cannot_satisfy_publication(): void
    {
        $assignment = $this->assignment();
        $lesson = $this->lesson(null, ['status'=>'draft']);
        $this->actingAs($this->lecturer)->put($this->url($lesson), ['title'=>'Lesson','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $assignment->update(['status'=>'published','instructions'=>'<p><br></p>']);
        $this->put($this->url($lesson), ['title'=>'Lesson','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $assignment->update(['instructions'=>'<p>Complete the exercise.</p>','due_date'=>null]);
        $this->put($this->url($lesson), ['title'=>'Lesson','status'=>'published','assignment_id'=>$assignment->id])->assertSessionHasErrors('assignment_id');
        $this->assertSame('draft', $lesson->fresh()->status);
    }

    public function test_an_assignment_in_another_module_cannot_satisfy_this_lesson(): void
    {
        $lesson=$this->lesson(null,['status'=>'draft']);
        $other=$this->module(['title'=>'Other module']);
        $assignment=$this->publishedAssignment(['course_offering_module_id'=>$other->id]);
        $this->actingAs($this->lecturer)->put($this->url($lesson),['title'=>$lesson->title,'status'=>'published','assignment_id'=>$assignment->id])
            ->assertSessionHasErrors('assignment_id');
        $this->assertSame('draft',$lesson->fresh()->status);
    }

    public function test_empty_markup_is_rejected_but_file_only_lessons_keep_attachments_after_edit(): void
    {
        Storage::fake('local');
        $assignment = $this->publishedAssignment();
        $lesson = $this->lesson(null, ['status'=>'draft', 'body'=>'<p>&nbsp;<br></p>','assignment_id'=>$assignment->id]);
        $this->actingAs($this->lecturer)->put($this->url($lesson), ['title'=>'Material','status'=>'published'])->assertSessionHasErrors('body');
        $resource = app(CourseContentService::class)->attachFileResource($this->lecturer, $lesson, UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'));
        $this->put($this->url($lesson), ['title'=>'Material','status'=>'published'])->assertSessionHasNoErrors();
        $this->put($this->url($lesson), ['title'=>'Revised title','status'=>'published'])->assertSessionHasNoErrors();
        $this->assertSame(1, $lesson->resources()->count());
        Storage::disk('local')->assertExists($resource->stored_name);
        $this->actingAs($this->student)->get(route('student.courses.content.resources.show', $resource->id))->assertOk();
    }

    public function test_linked_question_assignment_must_pass_the_existing_question_readiness_rules(): void
    {
        $assignment=$this->publishedAssignment(['max_marks'=>10]);
        $question=$this->question($assignment,['marks'=>5]);
        $lesson=$this->lesson(null,['status'=>'draft']);
        $payload=['title'=>'Assessed lesson','status'=>'published','assignment_id'=>$assignment->id];
        $this->actingAs($this->lecturer)->put($this->url($lesson),$payload)->assertSessionHasErrors('assignment_id');
        $question->update(['marks'=>10]);
        $this->put($this->url($lesson),$payload)->assertSessionHasNoErrors();
        $this->assertSame('published',$lesson->fresh()->status);
    }

    public function test_scheduled_publication_respects_release_and_self_paced_progress(): void
    {
        $this->travelTo(now()->startOfDay());
        $assignment = $this->publishedAssignment();
        $lesson = $this->lesson(null, ['status'=>'draft','assignment_id'=>$assignment->id]);
        $when = now()->addDays(2)->setTimezone('Africa/Kampala')->format('Y-m-d\TH:i');
        $this->actingAs($this->lecturer)->put($this->url($lesson), ['title'=>'Scheduled','status'=>'published','released_at'=>$when])->assertSessionHasNoErrors();
        $read = route('student.courses.content.lessons.show', [$this->offering->id,$lesson->id]);
        $this->actingAs($this->student)->get($read)->assertNotFound();
        $this->travel(3)->days();
        $this->get($read)->assertOk();
        $this->post(route('student.courses.content.lessons.complete', [$this->offering->id,$lesson->id]))->assertRedirect();
        $this->travel(5)->days();
        $this->get($read)->assertOk();
        $this->assertSame(1, $lesson->progressRecords()->where('status','completed')->count());
    }

    public function test_bulk_module_publication_cannot_bypass_lesson_assignment_rule_and_legacy_records_are_retained(): void
    {
        $module = $this->module(['status'=>'draft']);
        $lesson = $this->lesson($module);
        $check = app(ModulePublicationCheck::class);
        $this->assertFalse($check->evaluate($module)['ready']);
        $this->actingAs($this->lecturer)->put('/teacher/course-offerings/'.$this->offering->id.'/content/modules/'.$module->id,
            ['title'=>$module->title,'status'=>'published'])->assertSessionHasErrors('assignment_id');
        $this->assertSame('published', $lesson->fresh()->status, 'audit must not silently unpublish legacy lessons');
        $this->artisan('course-content:audit', ['offering'=>$this->offering->id])->expectsOutputToContain('Published lessons requiring assignment remediation: 1');
        $assignment = $this->publishedAssignment();
        $lesson->update(['assignment_id'=>$assignment->id]);
        $this->assertTrue($check->evaluate($module)['ready']);
    }
}
