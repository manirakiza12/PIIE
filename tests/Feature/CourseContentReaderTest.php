<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Course Content: the student reader and lesson progress.
 *
 * The other suite covers what a lecturer may author. This one covers what a
 * student may SEE and what may be counted as their learning, which is a
 * different set of questions and a different set of failure modes.
 *
 * The central property under test throughout:
 *
 *   PROGRESS IS DERIVED FROM ELIGIBLE PUBLISHED LEARNING REQUIREMENTS, NOT FROM
 *   PAGE VISITS.
 *
 * Opening a lesson records engagement. It never records completion. Progress
 * counts published, released lessons in published, released modules - nothing
 * else contributes to either the numerator or the denominator.
 */
class CourseContentReaderTest extends TestCase
{
    // Brings CourseContentFixture with it, plus the assignment tables. The
    // student reader now lists module assessments and computes module completion
    // from them, so rendering the page genuinely reads those tables - and a
    // fixture that does not build them would be testing a page that cannot work.
    use AssignmentFixture;

    // ══════════════════════════════════════════════════════════════════════
    // STUDENT REGISTRATION AUTHORITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_confirmed_registrant_can_open_their_course_content(): void
    {
        $module = $this->module();
        $this->lesson($module);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertSee('Business Mathematics')
            ->assertSee('Fractions, Ratios &amp; Percentages', false);
    }

    public function test_a_student_awaiting_confirmation_cannot_read_content(): void
    {
        // "Registered" is not "confirmed". The authoritative chain puts a valid
        // Course Registration before the student, and confirmed is what Live
        // Class participation uses, so it is what content uses too.
        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'registered']);

        $this->module();
        $this->lesson();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertNotFound();
    }

    public function test_a_student_who_has_dropped_the_course_cannot_read_content(): void
    {
        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'dropped']);

        $this->module();
        $lesson = $this->lesson();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertNotFound();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
            ->assertNotFound();
    }

    public function test_a_student_registered_on_another_offering_cannot_read_this_one(): void
    {
        $this->module();
        $this->lesson();

        // A student confirmed on a DIFFERENT Offering in the same school, and
        // deliberately NOT confirmed on this one. The Offering boundary, not the
        // tenant boundary, is what has to stop them - and a tenant-only test
        // would pass even if the Offering check were missing, because the school
        // check would catch the request anyway.
        $other = $this->secondOfferingSameSchool();
        $stranger = $this->user('Nabirye Sarah', 7);
        DB::table('course_registrations')->insert([
            'school_id' => $this->school, 'student_id' => $stranger->id,
            'subject_id' => $this->subject, 'course_offering_id' => $other->id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($stranger)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertNotFound();
    }

    public function test_a_student_cannot_read_another_tenants_content(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->otherTenantStudent())
            ->get("/student/courses/{$other['offeringId']}/content/lessons/{$other['lessonId']}")
            ->assertNotFound();
    }

    public function test_a_student_cannot_complete_a_lesson_in_another_tenants_course(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->student)
            ->post("/student/courses/{$other['offeringId']}/content/lessons/{$other['lessonId']}/complete")
            ->assertNotFound();

        // No progress row may be created for a lesson they cannot even see.
        $this->assertSame(0, DB::table('course_offering_lesson_progress')
            ->where('course_offering_lesson_id', $other['lessonId'])->count());
    }

    public function test_a_lecturer_cannot_see_the_student_reader(): void
    {
        $this->module();
        $lesson = $this->lesson();

        $response = $this->actingAs($this->lecturer)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        // A lecturer is not a registered student, so the reader must not serve
        // them - the authority is the registration, not the login.
        $this->assertNotContains(200, [$response->getStatusCode()]);
    }

    public function test_a_student_cannot_mark_someone_elses_lesson_complete(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete");

        $progress = DB::table('course_offering_lesson_progress')
            ->where('course_offering_lesson_id', $lesson->id)->get();

        // Only the authenticated student's own row may exist.
        $this->assertCount(1, $progress);
        $this->assertSame($this->student->id, (int) $progress->first()->student_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DRAFT, SCHEDULED AND ARCHIVED CONTENT
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_draft_lesson_is_hidden_from_students(): void
    {
        $module = $this->module();
        $draft = $this->lesson($module, [
            'title' => 'Still being written',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $response->assertDontSee('Still being written');

        // And the lesson itself is a 404, not a 403. A draft must be
        // indistinguishable from a lesson that does not exist, or the reader
        // becomes an oracle for what a lecturer has not published.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$draft->id}")
            ->assertNotFound();
    }

    public function test_a_draft_module_is_hidden_even_though_its_lessons_are_published(): void
    {
        $module = $this->module(['status' => 'draft']);
        $this->lesson($module, ['title' => 'Inside a draft module']);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $response->assertDontSee('Inside a draft module');
    }

    public function test_content_scheduled_for_the_future_is_not_released_early(): void
    {
        $module = $this->module();
        $future = $this->lesson($module, [
            'title' => 'Next week lesson',
            'status' => 'published',
            'released_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");
        $response->assertOk()->assertDontSee('Next week lesson');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$future->id}")
            ->assertNotFound();
    }

    public function test_scheduled_content_is_released_once_its_moment_arrives(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, [
            'title' => 'Released lesson',
            'status' => 'published',
            'released_at' => now()->subMinute(),
        ]);

        $this->assertTrue($lesson->fresh()->isReleasedToStudents());

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
            ->assertOk()
            ->assertSee('Released lesson');
    }

    public function test_archived_content_is_withdrawn_from_students(): void
    {
        $module = $this->module();
        $archived = $this->lesson($module, [
            'title' => 'Withdrawn lesson',
            'status' => 'archived',
        ]);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertDontSee('Withdrawn lesson');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$archived->id}")
            ->assertNotFound();
    }

    public function test_an_archived_module_hides_its_lessons(): void
    {
        $module = $this->module(['status' => 'archived']);
        $this->lesson($module, ['title' => 'Inside an archived module']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertDontSee('Inside an archived module');
    }

    public function test_an_empty_course_explains_itself_rather_than_looking_broken(): void
    {
        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $response->assertSee('Nothing to study yet');
        $response->assertSee('not published any lessons');
        // And no misleading progress bar over a denominator of zero.
        $response->assertDontSee('0 of 0 lessons completed');
    }

    // ══════════════════════════════════════════════════════════════════════
    // PROGRESS
    // ══════════════════════════════════════════════════════════════════════

    public function test_progress_counts_only_eligible_published_lessons(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'One', 'status' => 'published']);
        $this->lesson($module, ['title' => 'Two', 'status' => 'published']);
        // Ineligible: a draft, a future release, and an archived lesson must
        // contribute to NEITHER the numerator nor the denominator.
        $this->lesson($module, ['title' => 'Draft', 'status' => 'draft']);
        $this->lesson($module, ['title' => 'Future', 'status' => 'published', 'released_at' => now()->addWeek()]);
        $this->lesson($module, ['title' => 'Archived', 'status' => 'archived']);

        $this->markProgress($one, $this->student);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $response->assertSee('1 of 2 lessons completed');
        $response->assertSee('50%');
    }

    public function test_progress_reads_as_nothing_done_before_anything_is_completed(): void
    {
        $module = $this->module();
        $this->lesson($module);
        $this->lesson($module);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertSee('0 of 2 lessons completed')
            ->assertSee('0%');
    }

    public function test_opening_a_lesson_records_engagement_but_never_completion(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
            ->assertOk();

        $progress = CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $this->student->id)
            ->first();

        $this->assertNotNull($progress, 'opening a lesson should record engagement');
        $this->assertSame(CourseOfferingLessonProgress::STATUS_IN_PROGRESS, $progress->status);
        $this->assertNull($progress->completed_at);
    }

    public function test_opening_every_lesson_does_not_complete_anything(): void
    {
        // The single most important property in this file. If opening a page
        // counted as learning, "3 of 8 completed" would be a claim about traffic
        // rather than about learning.
        $module = $this->module();
        $lessons = collect(range(1, 4))->map(fn ($n) => $this->lesson($module, ['title' => "Lesson {$n}"]));

        foreach ($lessons as $lesson) {
            $this->actingAs($this->student)
                ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
                ->assertOk();
        }

        $this->assertSame(0, (int) DB::table('course_offering_lesson_progress')
            ->where('status', 'completed')->count());

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertSee('0 of 4 lessons completed');
    }

    public function test_a_student_can_mark_a_lesson_complete(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete")
            ->assertRedirect();

        $progress = CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $this->student->id)
            ->first();

        $this->assertSame(CourseOfferingLessonProgress::STATUS_COMPLETED, $progress->status);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_completing_the_same_lesson_twice_is_idempotent(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);
        $url = "/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete";

        $this->actingAs($this->student)->post($url)->assertRedirect();
        $first = CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $this->student->id)->first();

        $this->actingAs($this->student)->post($url)->assertRedirect();
        $this->actingAs($this->student)->post($url)->assertRedirect();

        $rows = DB::table('course_offering_lesson_progress')
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $this->student->id)->get();

        // One row, not three. A double-counted row would push progress over 100%.
        $this->assertCount(1, $rows);
        $this->assertSame(
            (string) $first->completed_at,
            (string) CourseOfferingLessonProgress::query()
                ->where('course_offering_lesson_id', $lesson->id)
                ->where('student_id', $this->student->id)->first()->completed_at
        );
    }

    public function test_completing_a_lesson_already_completed_does_not_change_the_count(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'One']);
        $two = $this->lesson($module, ['title' => 'Two']);
        $this->markProgress($one, $this->student);

        $url = "/student/courses/{$this->offering->id}/content/lessons/{$one->id}/complete";
        $this->actingAs($this->student)->post($url);
        $this->actingAs($this->student)->post($url);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertSee('1 of 2 lessons completed')
            ->assertSee('50%');
    }

    public function test_completion_requires_the_configured_rule_to_be_honourable(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, ['completion_rule' => 'quiz']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete")
            ->assertRedirect()
            ->assertSessionHas('error');

        // A rule PIIE cannot observe must not be satisfiable by pressing a
        // button: that would be a false claim about learning.
        $this->assertSame(0, (int) DB::table('course_offering_lesson_progress')->count());
    }

    public function test_the_reader_says_so_when_a_lesson_is_completed_by_its_own_activity(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, ['completion_rule' => 'teacher_verification']);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        $response->assertOk();
        $response->assertSee('completed by its own activity');
        $response->assertDontSee('Mark Complete');
    }

    public function test_completion_is_a_post_so_a_link_prefetch_cannot_complete_a_lesson(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);

        // A GET on the completion URL must not exist. A crawler, a prefetcher or
        // a mistyped link must not be able to mark a lesson complete.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete")
            ->assertStatus(405);

        $this->assertSame(0, (int) DB::table('course_offering_lesson_progress')->count());
    }

    public function test_progress_is_personal_to_each_student(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'One']);
        $this->lesson($module, ['title' => 'Two']);

        $other = $this->user('Mugisha Peter', 7);
        $this->confirm($other);

        $this->markProgress($one, $this->student);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertSee('1 of 2 lessons completed');

        $this->actingAs($other)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertSee('0 of 2 lessons completed');
    }

    public function test_completing_a_lesson_removed_from_the_course_stops_counting_it(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'One']);
        $two = $this->lesson($module, ['title' => 'Two']);
        $this->markProgress($one, $this->student);

        // A withdrawn lesson leaves the eligible set, so it leaves the count on
        // both sides. The student's own record is preserved - it is history,
        // not something to delete behind their back.
        $two->update(['status' => 'archived']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertSee('1 of 1 lessons completed')
            ->assertSee('100%');

        $this->assertSame(1, (int) DB::table('course_offering_lesson_progress')->count());
    }

    public function test_the_lesson_list_marks_each_lessons_own_state(): void
    {
        $module = $this->module();
        $done = $this->lesson($module, ['title' => 'Already done']);
        $this->lesson($module, ['title' => 'Not yet']);
        $this->markProgress($done, $this->student);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Not started');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE READER AND ITS NAVIGATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_reader_shows_the_lessons_position_and_its_details(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, [
            'title' => 'Fractions, Ratios & Percentages',
            'learning_objectives' => "Convert a fraction to a decimal\nExplain a percentage",
            'estimated_minutes' => 25,
        ]);
        $this->lesson($module, ['title' => 'Next lesson']);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$one->id}");

        $response->assertOk();
        $response->assertSee('Lesson 1 of 2');
        $response->assertSee('Fractions, Ratios &amp; Percentages', false);
        $response->assertSee('25 minutes');
        $response->assertSee('What you will be able to do');
        $response->assertSee('Convert a fraction to a decimal');
        $response->assertSee('Explain a percentage');
        $response->assertSee('Mark Complete');
    }

    public function test_previous_and_next_navigate_in_reading_order(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'Lesson One']);
        $two = $this->lesson($module, ['title' => 'Lesson Two']);
        $three = $this->lesson($module, ['title' => 'Lesson Three']);

        $middle = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$two->id}");
        $middle->assertOk()->assertSee('Lesson 2 of 3');
        $middle->assertSee(route('student.courses.content.lessons.show', [$this->offering->id, $one->id]), false);
        $middle->assertSee(route('student.courses.content.lessons.show', [$this->offering->id, $three->id]), false);

        $first = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$one->id}");
        $first->assertOk()->assertSee('Lesson 1 of 3');
        $first->assertSee('This is the first lesson');

        $last = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$three->id}");
        $last->assertOk()->assertSee('This is the last available lesson');
    }

    public function test_next_never_walks_a_student_into_a_draft_lesson(): void
    {
        $module = $this->module();
        $published = $this->lesson($module, ['title' => 'Published lesson']);
        $this->lesson($module, ['title' => 'Draft lesson', 'status' => 'draft']);
        $later = $this->lesson($module, ['title' => 'Later published lesson']);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$published->id}");

        $response->assertOk();
        // The draft is not in the released sequence, so it cannot be the "next"
        // lesson and the position count cannot include it.
        $response->assertSee('Lesson 1 of 2');
        $response->assertSee(route('student.courses.content.lessons.show', [$this->offering->id, $later->id]), false);
        $response->assertDontSee('Draft lesson');
    }

    public function test_navigation_spans_modules_in_course_order(): void
    {
        $first = $this->module(['title' => 'Module 1']);
        $second = $this->module(['title' => 'Module 2']);

        $a = $this->lesson($first, ['title' => 'A']);
        $b = $this->lesson($first, ['title' => 'B']);
        $c = $this->lesson($second, ['title' => 'C']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$b->id}")
            ->assertOk()
            ->assertSee('Lesson 2 of 3')
            ->assertSee(route('student.courses.content.lessons.show', [$this->offering->id, $c->id]), false);
    }

    public function test_a_lesson_whose_module_is_released_late_is_still_hidden(): void
    {
        $module = $this->module(['released_at' => now()->addWeek()]);
        $lesson = $this->lesson($module, ['title' => 'Belongs to a scheduled module']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
            ->assertNotFound();
    }

    public function test_the_reader_shows_the_student_own_completion_state(): void
    {
        $module = $this->module();
        $done = $this->lesson($module, ['title' => 'Finished']);
        $this->markProgress($done, $this->student);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$done->id}")
            ->assertOk()
            ->assertSee('You completed this lesson')
            ->assertDontSee('Mark Complete');
    }

    public function test_a_published_module_with_no_released_lessons_is_not_listed(): void
    {
        // A section heading with nothing under it looks broken, so it is not
        // shown - but it is NOT deleted either.
        $this->module(['title' => 'Empty published module']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            ->assertDontSee('Empty published module');

        $this->assertSame(1, (int) DB::table('course_offering_modules')
            ->where('title', 'Empty published module')->count());
    }

    public function test_lessons_are_listed_in_the_lecturers_order(): void
    {
        $module = $this->module();
        $first = $this->lesson($module, ['title' => 'AAA first']);
        $second = $this->lesson($module, ['title' => 'ZZZ second']);

        // Reorder so "ZZZ" comes first, and the reader must follow the server's
        // sequence rather than the title or the id.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/{$module->id}/lessons/order", [
                'order' => [$second->id, $first->id],
            ])->assertRedirect();

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $this->assertLessThan(
            strpos($response->getContent(), 'AAA first'),
            strpos($response->getContent(), 'ZZZ second'),
            'the reader did not follow the server-assigned lesson order'
        );
    }

    public function test_the_lesson_state_is_shown_as_an_icon_and_never_as_a_raw_entity(): void
    {
        // THE REGRESSION THIS PINS - a raw entity shown to a student.
        //
        // The per-lesson state mark was written as a character entity inside a
        // Blade ESCAPED-OUTPUT expression. Escaping turned the entity into
        // literal text, so the browser displayed the characters "&#9675;" before
        // every Not Started title.
        //
        // The sibling checkmark on the lesson page was unaffected because that
        // one was raw HTML - which is precisely why this was easy to misread as
        // a data problem rather than a template one.
        $module = $this->module();
        $fresh = $this->lesson($module, ['title' => 'Never opened']);
        $begun = $this->lesson($module, ['title' => 'Opened not finished']);
        $done = $this->lesson($module, ['title' => 'Finished']);

        $this->markProgress($begun, $this->student, CourseOfferingLessonProgress::STATUS_IN_PROGRESS);
        $this->markProgress($done, $this->student);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");

        $response->assertOk();
        $html = $response->getContent();

        // The literal characters must never reach the page.
        $this->assertStringNotContainsString('&#9675;', $html, 'the Not Started entity is being printed as text');
        $this->assertStringNotContainsString('&amp;#', $html, 'an entity is being double-escaped into visible text');

        // A real icon carries the visual cue instead, for all three states.
        $this->assertStringContainsString('bi-check-circle-fill', $html, 'Completed has no icon');
        $this->assertStringContainsString('bi-circle-half', $html, 'In progress has no icon');
        $this->assertStringContainsString('bi bi-circle text-muted', $html, 'Not started has no icon');

        // And the icon is never the ONLY indication: the state is also written
        // out, so it survives a screen reader and a failed icon font.
        $this->assertStringContainsString('Not started', $html);
        $this->assertStringContainsString('In progress', $html);
        $this->assertStringContainsString('Completed', $html);

        // The icon is decorative, so it is hidden from assistive technology
        // rather than being announced as an unpronounceable glyph.
        $this->assertStringContainsString('bi-circle-half text-warning cc-mark" aria-hidden="true"', $html);
    }

    public function test_engagement_alone_does_not_change_the_progress_figure(): void
    {
        // "In progress" is display state only. Adding the third state to the
        // list must not let a begun lesson count towards "N of M completed",
        // which is the property the whole progress model rests on.
        $module = $this->module();
        $this->lesson($module, ['title' => 'Never opened']);
        $begun = $this->lesson($module, ['title' => 'Opened not finished']);

        $this->markProgress($begun, $this->student, CourseOfferingLessonProgress::STATUS_IN_PROGRESS);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertOk()
            // Two eligible lessons, one of them begun, none completed: the count
            // stays at zero while the list still tells the student it is under
            // way.
            ->assertSee('0 of 2 lessons completed')
            ->assertSee('0%')
            ->assertSee('In progress');
    }
    // ══════════════════════════════════════════════════════════════════════
    // RESOURCES IN THE READER
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_can_open_a_link_resource(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);
        $this->resource($lesson, ['link_url' => 'https://example.org/reading']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}")
            ->assertOk()
            ->assertSee('Further reading')
            ->assertSee('Web link');
    }

    public function test_an_uploaded_resource_is_downloadable_by_a_registered_student(): void
    {
        Storage::fake('local');
        $module = $this->module();
        $lesson = $this->lesson($module);

        $resource = $this->resource($lesson, [
            'title' => 'Practice notes',
            'type' => 'file',
            'link_url' => null,
            'original_name' => 'notes.pdf',
            'stored_name' => 'course-content/1/abc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
        ]);
        Storage::disk('local')->put('course-content/1/abc.pdf', 'notes');

        $this->actingAs($this->student)
            ->get(route('student.courses.content.resources.show', $resource->id))
            ->assertOk()
            ->assertDownload('notes.pdf');
    }

    public function test_a_resource_of_an_unregistered_students_course_is_not_reachable(): void
    {
        Storage::fake('local');
        $module = $this->module();
        $lesson = $this->lesson($module);
        $resource = $this->resource($lesson, [
            'type' => 'file', 'link_url' => null,
            'original_name' => 'notes.pdf', 'stored_name' => 'course-content/1/abc.pdf',
        ]);
        Storage::disk('local')->put('course-content/1/abc.pdf', 'notes');

        // Dropped the course, but still holds the resource id from earlier.
        DB::table('course_registrations')
            ->where('student_id', $this->student->id)->update(['status' => 'dropped']);

        $this->actingAs($this->student)
            ->get(route('student.courses.content.resources.show', $resource->id))
            ->assertNotFound();
    }

    public function test_a_resource_of_a_draft_lesson_is_not_reachable(): void
    {
        Storage::fake('local');
        $module = $this->module();
        $lesson = $this->lesson($module, ['status' => 'draft']);
        $resource = $this->resource($lesson, [
            'type' => 'file', 'link_url' => null,
            'original_name' => 'notes.pdf', 'stored_name' => 'course-content/1/abc.pdf',
        ]);
        Storage::disk('local')->put('course-content/1/abc.pdf', 'notes');

        // The reader is refused, and so is the attachment. A registered student
        // must not be able to reach a draft's file by guessing a resource id.
        $this->actingAs($this->student)
            ->get(route('student.courses.content.resources.show', $resource->id))
            ->assertNotFound();
    }

    public function test_a_resource_in_another_tenants_course_is_not_reachable(): void
    {
        $other = $this->otherTenant();
        $resourceId = (int) DB::table('course_offering_lesson_resources')->insertGetId([
            'school_id' => $other['schoolId'], 'course_offering_id' => $other['offeringId'],
            'course_offering_lesson_id' => $other['lessonId'], 'title' => 'Theirs',
            'type' => 'link', 'link_url' => 'https://evil.test/x',
        ]);

        $this->actingAs($this->student)
            ->get(route('student.courses.content.resources.show', $resourceId))
            ->assertNotFound();
    }

    public function test_a_link_resource_with_no_usable_target_is_not_offered(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module);
        $this->resource($lesson, ['link_url' => 'javascript:alert(1)']);

        // Defence in depth: the write path already refuses this, and the read
        // path must not honour it either if such a row ever existed.
        $this->actingAs($this->student)
            ->get(route('student.courses.content.resources.show', $lesson->resources->first()->id))
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE STUDENT'S ENTRY POINT
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_confirmed_course_offers_a_course_content_link(): void
    {
        $response = $this->actingAs($this->student)->get('/student/my-courses');

        $response->assertOk();

        // The LINK is the contract, so that is what is asserted. The literal label
        // "Course Content" is not: the My Courses card redesign replaced that button
        // text with "Open Course", and a test pinned to a presentation string would
        // break on any wording change while saying nothing about authorisation.
        // Whether the link may be followed is still enforced by the target page.
        $response->assertSee(route('student.courses.content', $this->offering->id), false);
        $response->assertSee('Open Course');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function otherTenantStudent(): \App\Models\User
    {
        $other = $this->otherTenant();

        return $other['student'];
    }
}
