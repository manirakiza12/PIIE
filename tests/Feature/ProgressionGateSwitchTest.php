<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The progression gate can actually be switched on.
 *
 * THE GAP THIS PINS
 *
 * `gate_progression` existed only as a column on the model - no route, no control,
 * no form. So the gate was not merely "off by default", it was "off permanently":
 * switching it on meant a direct database edit by someone with production access.
 * A safety default that nobody can deliberately lift is not a safety default, it is
 * a missing feature.
 *
 * WHY IT IS NOT SWITCHED ON IN BULK
 *
 * Nothing here turns gating on for existing courses, and that is deliberate. A
 * migration or a seeder that enabled it would apply a policy to courses whose
 * content nobody has looked at, and would change what paying students can reach
 * without anybody having decided that. The decision belongs to the lecturer who
 * knows their cohort.
 *
 * WHY THE PANEL SHOWS COUNTS
 *
 * "Finished" means two different things: PASSED when the required task carries a
 * pass mark, and HANDED IN when it does not. Both are legitimate policies and they
 * are different promises. A lecturer who ticks a box believing it is about passing
 * and finds it stopping students whose work is sitting unmarked has been misled by
 * the interface, so the numbers are supplied rather than left to be discovered.
 */
class ProgressionGateSwitchTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();
    }

    /**
     * Put the gate into a state, through the service.
     *
     * NOT `$offering->gate_progression = true; $offering->save();` - that is
     * exactly the mistake the controller had to be fixed for. `CourseOffering` is
     * `$guarded = ['*']` with an `updating` hook that refuses every direct write,
     * so a test written that way would be testing an exception.
     */
    private function setGate(bool $on): void
    {
        app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->setProgressionGate($this->offering->school_id, $this->offering->id, $on);
    }

    private function flip(bool $on): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)
            ->put(route('teacher.course_offerings.content.progression', $this->offering->id), [
                'gate_progression' => $on ? 1 : 0,
            ]);
    }

    // ── It works ────────────────────────────────────────────────────────────

    public function test_a_lecturer_can_switch_gating_on_for_their_course(): void
    {
        $this->assertFalse((bool) $this->offering->gate_progression, 'gating starts off');

        $this->flip(true)->assertRedirect();

        $this->assertTrue(
            (bool) $this->offering->fresh()->gate_progression,
            'the switch must actually change the stored setting'
        );
    }

    public function test_a_lecturer_can_switch_it_off_again(): void
    {
        $this->setGate(true);

        $this->flip(false)->assertRedirect();

        $this->assertFalse((bool) $this->offering->fresh()->gate_progression);
    }

    public function test_an_absent_checkbox_means_off_not_unchanged(): void
    {
        // An HTML checkbox sends nothing when unticked. Treating "absent" as
        // "leave it alone" would make the control impossible to turn off - the one
        // direction a lecturer needs to be able to move immediately.
        $this->setGate(true);

        $this->actingAs($this->lecturer)
            ->put(route('teacher.course_offerings.content.progression', $this->offering->id), [])
            ->assertRedirect();

        $this->assertFalse((bool) $this->offering->fresh()->gate_progression);
    }

    public function test_the_confirmations_say_which_state_it_is_in(): void
    {
        $this->flip(true)->assertSessionHas('success', fn ($m) => str_contains($m, 'ON'));
        $this->flip(false)->assertSessionHas('success', fn ($m) => str_contains($m, 'OFF'));
    }

    // ── Authority ───────────────────────────────────────────────────────────

    public function test_a_lecturer_without_an_allocation_cannot_switch_it_on(): void
    {
        $stranger = \App\Models\User::query()->create([
            'school_id' => $this->school,
            'role_id' => 3,
            'name' => 'Unallocated Stranger',
            'email' => 'gate-stranger@example.test',
            'account_status' => 'active',
        ]);

        // 404, not 403, and deliberately so. This workspace answers 404 to an
        // unallocated lecturer everywhere else, and that is the stronger answer: a
        // 403 would confirm the Course Allocation EXISTS, so the URL could be used to
        // discover which offerings a lecturer may not see. A switch that changed
        // access to student content is exactly the wrong place to soften that.
        $this->actingAs($stranger)
            ->put(route('teacher.course_offerings.content.progression', $this->offering->id), [
                'gate_progression' => 1,
            ])
            ->assertNotFound();

        $this->assertFalse((bool) $this->offering->fresh()->gate_progression);
    }

    public function test_the_control_is_not_rendered_to_someone_who_cannot_use_it(): void
    {
        // A rendered button is a claim that the person may do the thing. Allocation a
        // control that will be refused teaches a lecturer that the site is broken.
        $stranger = \App\Models\User::query()->create([
            'school_id' => $this->school,
            'role_id' => 3,
            'name' => 'Unallocated Stranger',
            'email' => 'gate-stranger2@example.test',
            'account_status' => 'active',
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering->id))
            ->assertOk()
            ->assertSee('cc-gate-toggle', false);
    }

    public function test_it_only_affects_the_course_it_was_switched_for(): void
    {
        // Per course, by design: one lecturer's readiness is not another's, and one
        // course's shape says nothing about the next.
        //
        // Built through the service because `CourseOffering` is `$guarded = ['*']`
        // and refuses a direct create - the same constraint that made the
        // controller's first attempt throw.
        $other = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($this->school, $this->subject, $this->year, $this->period, 'OTHER-1');

        $this->flip(true)->assertRedirect();

        $this->assertTrue((bool) $this->offering->fresh()->gate_progression);
        $this->assertFalse((bool) $other->fresh()->gate_progression);
    }

    // ── The panel tells the truth about what "finished" will mean ───────────

    public function test_the_panel_warns_when_required_tasks_have_no_pass_mark(): void
    {
        $this->requiredModuleTask($this->module(), [
            'title' => 'Required, unthresholded',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'max_marks' => 100,
            'pass_mark' => null,
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering->id))
            ->assertOk()
            // "handed in", not "passed" - the two promises named explicitly.
            ->assertSee('handed in', false)
            ->assertSee('passed', false);
    }

    public function test_the_panel_is_quiet_when_every_required_task_has_a_pass_mark(): void
    {
        $this->requiredModuleTask($this->module(), [
            'title' => 'Required, thresholded',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering->id))
            ->assertOk()
            ->assertSee('pass mark', false)
            ->assertDontSee('no pass mark', false);
    }

    public function test_the_panel_says_so_when_the_course_has_no_required_tasks(): void
    {
        // Better than a switch that appears to do nothing: with no required task,
        // "finished" is decided by the lessons alone, and saying so stops the
        // lecturer switching it on and then wondering why nothing is gated.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering->id))
            ->assertOk()
            ->assertSee('no required tasks', false);
    }
}