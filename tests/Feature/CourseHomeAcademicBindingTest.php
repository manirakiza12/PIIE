<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Support\CourseExperience\StudentCourseHome;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * THE COURSE HOME HEADER'S DATA BINDING, against the columns production declares.
 *
 * â”€â”€ WHY THIS SUITE IS CAREFUL ABOUT COLUMNS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * The defect it pins was invisible to every other test because the fixture BUILDS
 * its own `academic_years` and `academic_periods` rows. A model read through an
 * attribute the production table does not have returns null, and a fixture row
 * created through the same model accepts whatever the test wrote - so the assertion
 * was really asserting that the fixture agreed with itself.
 *
 * So the load-bearing assertions here are:
 *
 *   - `Schema::hasColumn('academic_years', 'label')`, and the same for periods, so
 *     the test proves the column it binds to is one the real table HAS; and
 *   - the absence of a `name` column, so nobody re-introduces the same binding.
 *
 * `Schema::hasColumn()`, not `information_schema` - the suite runs on SQLite, where
 * information_schema does not exist, so a test written against it would pass only on
 * the development database, which is not a test.
 *
 * â”€â”€ WHY THE TEACHING RECORD IS SHOWN, NOT JUST THE CURRENT TEACHER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * Offering #5's one allocation is `active` with `starts_on` tomorrow, and the old
 * rule demanded `starts_on <= now` before it would print a name. The page therefore
 * said "No lecturer is currently allocated to this course" about a colleague who IS
 * allocated - a false statement, and the kind that makes a colleague look
 * unassigned.
 *
 * The domain already draws this line in the OTHER direction, in
 * `reachableOfferings()`: "Cancelled allocations are excluded outright; ENDED ones
 * are included so a COMPLETED Course Offering still shows its TEACHING RECORD."
 *
 * So the header shows the record and labels each row, using the EXISTING currency
 * test. Teaching AUTHORITY is unchanged, and there is a test below that fails if
 * this ever becomes otherwise.
 */
class CourseHomeAcademicBindingTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        $this->assignmentSetUp();
    }

    private function home(): array
    {
        return app(StudentCourseHome::class)->build($this->student, $this->offering->fresh());
    }

    private function lecturerRow(string $name = 'Daniel Okello'): ?array
    {
        $rows = $this->home()['header']['lecturers'];

        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    private function setAllocation(string $userColumn, int $userId, array $attributes): void
    {
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->where($userColumn, $userId)
            ->update($attributes);
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 1. THE COLUMNS EXIST, AND ARE THE ONES BEING BOUND TO
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_the_academic_year_and_period_carry_a_label_and_not_a_name(): void
    {
        // The real tables. If this ever fails, the binding is wrong and the fix is a
        // column question, not a view question.
        $this->assertTrue(
            Schema::hasColumn('academic_years', 'label'),
            'academic_years must carry the label the Course Home reads'
        );

        $this->assertTrue(
            Schema::hasColumn('academic_periods', 'label'),
            'academic_periods must carry the label the Course Home reads'
        );

        // And there is no `name` to fall back to. The old code read `->name`, which
        // is why the header showed a dash on every real course.
        $this->assertFalse(Schema::hasColumn('academic_years', 'name'));
        $this->assertFalse(Schema::hasColumn('academic_periods', 'name'));
    }

    public function test_no_accessor_could_quietly_re_hide_the_defect(): void
    {
        // A future `name` accessor would make `->name` work again and hide the
        // question, so its absence is asserted rather than assumed.
        $this->assertFalse(method_exists(AcademicYear::class, 'name'));
        $this->assertFalse(method_exists(AcademicPeriod::class, 'name'));
        $this->assertFalse(method_exists(AcademicYear::class, 'getNameAttribute'));
        $this->assertFalse(method_exists(AcademicPeriod::class, 'getNameAttribute'));
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 2. THE REAL VALUES REACH THE HEADER
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_the_header_shows_the_REAL_academic_year(): void
    {
        // A distinctive label, so a hard-coded "2026/2027" could not pass.
        DB::table('academic_years')->where('id', $this->year)->update(['label' => '2026/2027 verified']);

        $header = $this->home()['header'];

        $this->assertSame('2026/2027 verified', $header['academic_year']);
    }

    public function test_the_header_shows_the_REAL_period(): void
    {
        DB::table('academic_periods')->where('id', $this->period)->update(['label' => 'Semester 1 verified']);

        $this->assertSame('Semester 1 verified', $this->home()['header']['period']);
    }

    public function test_the_values_come_from_the_OFFERING_S_OWN_relationships(): void
    {
        // Not from a lookup, not from a default: from the Offering's own
        // `academicYear` / `academicPeriod`. Re-pointing the Offering's ids moves the
        // header, and touches nothing else.
        $otherYear = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $this->school, 'label' => '2027/2028 verified',
            'start_date' => '2027-01-01', 'end_date' => '2028-05-31', 'status' => 'active',
        ]);

        $otherPeriod = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $this->school, 'academic_year_id' => $otherYear, 'type' => 'semester',
            'label' => 'Semester 2 verified', 'sequence' => 2,
            'start_date' => '2027-02-01', 'end_date' => '2027-08-31', 'status' => 'active',
        ]);

        DB::table('course_offerings')->where('id', $this->offering->id)->update([
            'academic_year_id' => $otherYear,
            'academic_period_id' => $otherPeriod,
        ]);

        $header = $this->home()['header'];

        $this->assertSame('2027/2028 verified', $header['academic_year']);
        $this->assertSame('Semester 2 verified', $header['period']);
    }

    public function test_the_page_renders_both_values_rather_than_a_dash(): void
    {
        DB::table('academic_years')->where('id', $this->year)->update(['label' => '2026/2027 verified']);
        DB::table('academic_periods')->where('id', $this->period)->update(['label' => 'Semester 1 verified']);

        $html = $this->openHome()->assertOk()->getContent();

        $this->assertStringContainsString('2026/2027 verified', $html);
        $this->assertStringContainsString('Semester 1 verified', $html);
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 3. THE TEACHING RECORD, AND THE EXISTING CURRENCY SEMANTICS
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_an_ALLOCATION_STARTING_TOMORROW_is_named_and_labelled(): void
    {
        // THE REPORTED FAILURE. The old code demanded `starts_on <= now` before it
        // would print a name, so a course opening tomorrow displayed "No lecturer is
        // currently allocated" about a colleague who IS allocated.
        $this->setAllocation('user_id', $this->lecturer->id, [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addMonths(4)->toDateString(),
            'status' => 'active',
        ]);

        $row = $this->lecturerRow();

        $this->assertNotNull($row, 'a lecturer allocated from tomorrow must still be named');
        $this->assertFalse($row['is_current']);
        $this->assertStringContainsString('Starts', (string) $row['note']);
    }

    public function test_an_ALLOCATION_THAT_HAS_ENDED_is_still_shown_with_its_end_date(): void
    {
        // The domain's own rule, from `reachableOfferings()`: ended ones are
        // included "so a COMPLETED Course Offering still shows its TEACHING RECORD".
        $this->setAllocation('user_id', $this->lecturer->id, [
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subMonth()->toDateString(),
        ]);

        $row = $this->lecturerRow();

        $this->assertNotNull($row, 'an ended allocation is still part of the teaching record');
        $this->assertFalse($row['is_current']);
        $this->assertStringContainsString('Taught until', (string) $row['note']);
    }

    public function test_a_current_allocation_is_labelled_teaching_now(): void
    {
        // The fixture's own allocation starts yesterday, so it IS current - and the
        // ordinary case must not be broken by the new labelling.
        $row = $this->lecturerRow();

        $this->assertNotNull($row);
        $this->assertTrue($row['is_current']);
        $this->assertNull($row['note']);
    }

    public function test_a_CANCELLED_allocation_is_never_named(): void
    {
        // "Cancelled allocations are excluded outright" - the domain's own words.
        // Nobody is allocated by a cancelled allocation, so it is not a quiet state
        // to be labelled: it is not a fact about anyone.
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->update(['status' => 'cancelled']);

        $names = array_column($this->home()['header']['lecturers'], 'name');

        $this->assertNotContains('Daniel Okello', $names);
        $this->assertNotContains('Grace Nakato', $names);
    }

    public function test_a_course_with_genuinely_NOBODY_allocated_says_so(): void
    {
        // The honest absence, now reachable only when it is true.
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->delete();

        $this->assertSame([], $this->home()['header']['lecturers']);

        $this->openHome()
            ->assertOk()
            ->assertSee('No lecturer is allocated to this course.');
    }

    public function test_the_primary_lecturer_leads_the_list(): void
    {
        $names = array_column($this->home()['header']['lecturers'], 'name');

        $this->assertSame('Daniel Okello', $names[0], 'primary lecturer first');
        $this->assertContains('Grace Nakato', $names);
    }

    public function test_no_ALLOCATION_DATES_WERE_CHANGED_to_make_the_display_work(): void
    {
        // The fix must not be "move the dates". Read them back before and after and
        // prove the service only READS them: a label is derived, never a mutation.

        $query = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->orderBy('id')
            ->get(['id', 'status', 'starts_on', 'ends_on'])
            ->toJson();

        $this->home();

        $after = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->orderBy('id')
            ->get(['id', 'status', 'starts_on', 'ends_on'])
            ->toJson();

        $this->assertSame($query, $after, 'building the header must not mutate any allocation');
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 4. THE CURRENCY TEST IS THE EXISTING ONE, AND AUTHORITY IS UNCHANGED
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_the_header_label_agrees_with_the_TEACHING_AUTHORITY(): void
    {
        // A second implementation of the currency rule inside the Course Home would
        // eventually disagree with the authority - most likely on the System Tester
        // exception - and the page would claim a lecturer is teaching when they are
        // not, or the reverse. So the label is checked against the SAME service.
        $this->setAllocation('user_id', $this->lecturer->id, [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => null,
            'status' => 'active',
        ]);

        $access = app(LecturerCourseOfferingAccess::class);

        $row = $this->lecturerRow();
        $this->assertFalse($row['is_current'], 'the header must not claim he is teaching yet');

        // `resolveForLecturer()` answers REACHABILITY, not authority: the allocation
        // exists, so the Offering resolves. That is what lets a completed course show
        // its teaching record, and it is why it is the wrong thing to assert null on.
        $resolved = $access->resolveForLecturer($this->lecturer, (int) $this->offering->id);

        $this->assertNotNull($resolved, 'the allocation exists, so the Offering is reachable');
        $this->assertFalse(
            (bool) $resolved->getAttribute('my_allocation_is_current'),
            'and the Offering itself records that he is not yet teaching'
        );

        // The AUTHORITY gate is the other half, and it is closed.
        $this->assertFalse(
            $access->teachingActionsAllowed($resolved),
            'teaching authority must remain closed until the allocation starts'
        );

        // And the header's label and the Offering's own attribute are the SAME
        // question, so they must answer identically - which is what "one rule, one
        // place" actually means rather than merely claiming it.
        $this->assertSame(
            (bool) $resolved->getAttribute('my_allocation_is_current'),
            $row['is_current'],
            'the header label must be a reading of the authority, not an opinion of its own'
        );
    }

    public function test_teaching_authority_is_NOT_weakened_by_the_display_change(): void
    {
        // THE DECISIVE REGRESSION. Naming a lecturer on a course header must not
        // make them able to teach it, and must not change when they may.
        $this->setAllocation('user_id', $this->lecturer->id, [
            'starts_on' => now()->addWeek()->toDateString(),
            'status' => 'active',
        ]);

        $access = app(LecturerCourseOfferingAccess::class);

        // Reachable - the allocation exists - but NOT allowed to act. Those are
        // different questions and the header answers only the first.
        $resolved = $access->resolveForLecturer($this->lecturer, (int) $this->offering->id);

        $this->assertNotNull($resolved, 'reachability is a separate question from authority');
        $this->assertFalse(
            $access->teachingActionsAllowed($resolved),
            'a future allocation must not grant teaching authority'
        );

        // The header still names him, because naming and authorising are different.
        $this->assertNotNull($this->lecturerRow());
    }

    public function test_the_exposing_method_is_a_delegate_and_not_a_second_rule(): void
    {
        // The exposed method must answer identically to the gate for every case the
        // gate cares about, or the page and the authority can drift apart.
        $access = app(LecturerCourseOfferingAccess::class);

        $cases = [
            'current' => ['status' => 'active', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null],
            'not started' => ['status' => 'active', 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => null],
            'ended' => ['status' => 'active', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->subDay()->toDateString()],
            'planned' => ['status' => 'planned', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null],
            'cancelled' => ['status' => 'cancelled', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null],
        ];

        foreach ($cases as $label => $fields) {
            // A real stdClass, not `(object) $a + (object) $b`, which is not a merge.
            $allocation = new \stdClass();
            $allocation->status = $fields['status'];
            $allocation->starts_on = $fields['starts_on'];
            $allocation->ends_on = $fields['ends_on'];
            $allocation->user_id = $this->lecturer->id;
            $allocation->school_id = $this->school;

            $this->assertSame(
                $label === 'current',
                $access->isAllocationCurrent($allocation),
                "the delegate answered wrongly for: {$label}"
            );
        }
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 5. EAGER LOADING
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_the_teaching_record_survives_EAGER_LOADING(): void
    {
        // The controller eager-loads `lecturerAllocations.lecturer`, and Eloquent
        // builds an eager-load relation from an attribute-less new instance - so a
        // constraint read from `$this->` is null there. This is the assertion that
        // would have caught it, and it only means anything because the controller
        // really does eager-load.
        $this->openHome()->assertOk()->assertSee('Daniel Okello');

        $eager = CourseOffering::query()
            ->with('lecturerAllocations.lecturer')
            ->findOrFail($this->offering->id);

        $this->assertNotEmpty(
            $eager->lecturerAllocations,
            'the eager load must return rows, which it did not before the relation was fixed'
        );

        // And the header built from an EAGER-LOADED Offering is identical to the one
        // built from a plain read, so the two paths cannot differ.
        $fromEager = app(StudentCourseHome::class)->build($this->student, $eager)['header']['lecturers'];
        $fromPlain = $this->home()['header']['lecturers'];

        $this->assertSame(
            array_column($fromPlain, 'name'),
            array_column($fromEager, 'name'),
            'the eager-loaded and directly-read paths must agree'
        );
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 6. TENANT ISOLATION
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_a_lecturer_from_ANOTHER_tenant_is_never_named(): void
    {
        $theirs = $this->otherTenant();

        // An allocation that CLAIMS this Offering but belongs to another school.
        // Corrupt data - and the point is that a corrupt row cannot put another
        // institution's colleague on this header.
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $theirs['schoolId'],
            'course_offering_id' => $this->offering->id,
            'user_id' => $theirs['lecturer']->id,
            'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => null,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eager = CourseOffering::query()
            ->with('lecturerAllocations.lecturer')
            ->findOrFail($this->offering->id);

        // ON IDENTITY, NOT ON A NAME.
        //
        // `otherTenant()` creates a user called 'Grace Nakato', and the fixture chain
        // already created a co-lecturer with the SAME NAME in this school. Asserting
        // on a display string reported the fixture's own legitimate co-lecturer as a
        // leak - two people can share a name, and a tenant test that compares names
        // is not testing tenants.
        $names = array_column(
            app(StudentCourseHome::class)->build($this->student, $eager)['header']['lecturers'],
            'name'
        );

        $resolvedNames = $eager->lecturerAllocations
            ->map(fn ($allocation) => $allocation->lecturer?->name)
            ->all();

        $this->assertNotContains(
            $theirs['lecturer']->id,
            $eager->lecturerAllocations->pluck('user_id')->all(),
            'the foreign allocation row leaked through the relation at all'
        );

        $this->assertContains('Daniel Okello', $names, 'the real allocation still resolves');
        $this->assertCount(2, $names, 'exactly the two real allocations, and no third');
    }

    public function test_a_student_of_another_tenant_cannot_reach_this_header(): void
    {
        $theirs = $this->otherTenant();

        $this->actingAs($theirs['student'])
            ->get("/student/courses/{$this->offering->id}")
            ->assertNotFound();
    }

    public function test_another_tenants_academic_year_cannot_leak_into_this_header(): void
    {
        DB::table('academic_years')->where('id', $this->year)->update(['label' => 'OUR YEAR']);

        // A year of the SECOND INSTITUTION's very own.
        //
        // `otherTenant()` points its Offering at THIS tenant's year, so there was no
        // row to rename and the test was failing on a null - which means it asserted
        // nothing at all. Creating one makes the leak assertion real.
        // ONE other tenant, called once. `otherTenant()` is a BUILDER, so a second
        // call creates a second school and "their year" stops meaning anything.
        $theirs = $this->otherTenant();

        $theirYear = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $theirs['schoolId'],
            'label' => 'THEIR YEAR - must not leak',
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);

        $this->assertSame(
            $theirs['schoolId'],
            (int) DB::table('academic_years')->where('id', $theirYear)->value('school_id'),
            'the year must belong to the second institution for this test to mean anything'
        );

        $html = $this->openHome()->assertOk()->getContent();

        $this->assertStringNotContainsString('must not leak', $html);
        $this->assertStringContainsString('OUR YEAR', $html);
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // 7. THE COVER FALLBACK SURVIVES ALL OF THIS
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    public function test_the_typographic_cover_fallback_is_untouched(): void
    {
        // A header that names its lecturer and its dates correctly still must not
        // fabricate an image.
        DB::table('course_offerings')->where('id', $this->offering->id)->update([
            'cover_image_path' => null, 'cover_image_name' => null,
        ]);

        $this->openHome()
            ->assertOk()
            ->assertSee('data-testid="ch-cover-fallback"', false)
            ->assertSee('No course image', false)
            ->assertDontSee('data-testid="ch-cover-image"', false);
    }

    public function test_the_page_shows_the_lecturers_state_not_only_the_name(): void
    {
        // A name printed without its state would be the new falsehood: "Daniel
        // Okello" alone reads as "has been teaching since yesterday".
        $this->setAllocation('user_id', $this->lecturer->id, [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => null,
        ]);

        $this->openHome()
            ->assertOk()
            ->assertSee('Daniel Okello')
            ->assertSee('data-testid="ch-lecturer-name"', false)
            ->assertSee('Starts');
    }

    private function openHome(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}");
    }
}
