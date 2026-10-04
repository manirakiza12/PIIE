<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\Subject;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use App\Support\CourseOffering\CourseOfferingService;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\Permissions\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Step 4: the HEI Lecturer academic workspace.
 *
 * Two institutions, two lecturers, parallel Course Offerings. Everything a
 * lecturer may reach must be derived from that lecturer's own
 * CourseOfferingLecturerAllocation, tenant-checked, and nothing else.
 */
class LecturerWorkspaceTest extends TestCase
{
    use StaffModuleTestHelper;

    private array $tenants = [];
    private User $lecturerA;
    private User $lecturerB;
    private User $foreignLecturer;
    private User $k12Teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->string('academic_calendar_pattern')->nullable();
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->unsignedBigInteger('granted_by')->nullable(); $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->string('description')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps(); });
        Schema::create('staff_role_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('staff_role_id'); $table->string('permission', 100); $table->timestamps(); });
        Schema::create('user_staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('staff_role_id'); $table->unsignedBigInteger('assigned_by')->nullable(); $table->timestamps(); });
        $this->academicSchema();

        $this->tenants['a'] = $this->institution(1, 'higher_ed');
        $this->tenants['b'] = $this->institution(2, 'higher_ed');
        $this->tenants['k12'] = $this->institution(3, 'k12');

        $this->lecturerA = $this->lecturer($this->tenants['a'], 'Lecturer A', ['live_classes.view', 'live_classes.create']);
        $this->lecturerB = $this->lecturer($this->tenants['a'], 'Lecturer B', ['live_classes.view']);
        $this->foreignLecturer = $this->lecturer($this->tenants['b'], 'Foreign Lecturer', ['live_classes.view']);
        $this->k12Teacher = $this->lecturer($this->tenants['k12'], 'K12 Teacher', ['live_classes.view']);
    }

    // ---------------------------------------------------------------- 1, 2, 5

    public function test_lecturer_sees_only_the_course_offerings_they_are_allocated_to(): void
    {
        $mine = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $theirs = $this->allocated($this->tenants['a'], $this->lecturerB, 'BBIT2201', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress');
        $this->allocated($this->tenants['a'], $this->lecturerA, 'MATH1101', CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT, 'open');

        $response = $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.index'));
        $response->assertOk()
            ->assertSee('My Course Offerings')
            ->assertSee('BBIT3102')
            ->assertSee('MATH1101')
            ->assertDontSee('BBIT2201');

        // An unallocated offering cannot be reached by direct URL either.
        $this->get(route('teacher.course_offerings.show', $theirs->id))->assertNotFound();
        $this->get(route('teacher.course_offerings.students', $theirs->id))->assertNotFound();
        $this->assertNotNull($mine);
    }

    // -------------------------------------------------------------------- 3, 4

    public function test_lecturer_role_is_displayed_and_he_i_uses_lecturer_terminology(): void
    {
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT2101', CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR, 'in_progress');

        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.index'))
            ->assertOk()
            ->assertSee('Primary Lecturer')
            ->assertSee('Lab Instructor')
            // HEI presentation terminology.
            ->assertSee('Course Unit')
            ->assertDontSee('Total Teacher');
    }

    // -------------------------------------------------------------- 14: IDOR

    public function test_cross_tenant_and_cross_lecturer_offers_are_denied(): void
    {
        $mine = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $foreign = $this->allocated($this->tenants['b'], $this->foreignLecturer, 'BSCS4001', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');

        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $mine->id))->assertOk();

        // Lecturer A reaching Lecturer B's Offering.
        $theirs = $this->allocated($this->tenants['a'], $this->lecturerB, 'BBIT2201', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress');
        $this->get(route('teacher.course_offerings.show', $theirs->id))->assertNotFound();

        // Lecturer A reaching another institution's Offering.
        $this->get(route('teacher.course_offerings.show', $foreign->id))->assertNotFound();
        $this->get(route('teacher.course_offerings.students', $foreign->id))->assertNotFound();

        // The foreign lecturer's own list never leaks the other institution.
        $this->actingAs($this->foreignLecturer)->get(route('teacher.course_offerings.index'))
            ->assertOk()->assertSee('BSCS4001')->assertDontSee('BBIT3102');
    }

    // ------------------------------------------------- 5, 6, 7: lifecycle rule

    public function test_only_an_in_force_active_allocation_grants_current_teaching_access(): void
    {
        $active = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $planned = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT2101', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress', 'planned');
        $ended = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT1105', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress', 'ended');
        $cancelled = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT1109', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress', 'cancelled');

        $access = app(LecturerCourseOfferingAccess::class);
        $this->assertTrue($access->teachingActionsAllowed($access->resolveForLecturer($this->lecturerA, $active->id)));
        $this->assertFalse($access->teachingActionsAllowed($access->resolveForLecturer($this->lecturerA, $planned->id)));
        $this->assertFalse($access->teachingActionsAllowed($access->resolveForLecturer($this->lecturerA, $ended->id)));

        // Ended and cancelled allocations never grant current teaching access.
        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $ended->id))
            ->assertOk()->assertSee('Your teaching allocation is not currently in force.');
        $this->get(route('teacher.course_offerings.show', $cancelled->id))->assertNotFound();

        // A future-dated active allocation is not yet in force either.
        DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $active->id)
            ->update(['starts_on' => now()->addYear()->toDateString()]);
        $this->assertFalse($access->teachingActionsAllowed($access->resolveForLecturer($this->lecturerA, $active->id)));
    }

    // -------------------------------------------------------- 13: lifecycle

    public function test_offering_lifecycle_decides_which_teaching_actions_are_offered(): void
    {
        $access = app(LecturerCourseOfferingAccess::class);
        $cases = [
            'draft' => false,
            'open' => true,
            'in_progress' => true,
            'completed' => false,
            'cancelled' => false,
        ];
        $headlines = [
            'draft' => 'This Course Offering is still being prepared.',
            'open' => 'Registration is open for this Course Offering.',
            'in_progress' => 'Teaching is currently in progress for this Course Offering.',
            'completed' => 'This Course Offering has been completed. Teaching records remain available for reference.',
            'cancelled' => 'This Course Offering was cancelled.',
        ];
        foreach ($cases as $status => $expected) {
            $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'UNIT-'.strtoupper($status), CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, $status);
            $resolved = $access->resolveForLecturer($this->lecturerA, $offering->id);
            $this->assertSame($expected, $access->teachingActionsAllowed($resolved), "Status {$status}");
            $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $offering->id))
                ->assertOk()
                ->assertSee($headlines[$status])
                ->assertSee('Course Unit');
        }
    }

    public function test_completed_offering_keeps_a_readable_record_and_cancelled_does_not_act(): void
    {
        $completed = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT2101', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'completed');
        $this->confirmStudent($completed, 'S-100', 'Confirmed Student');
        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $completed->id))
            ->assertOk()
            ->assertSee('This Course Offering has been completed. Teaching records remain available for reference.')
            ->assertSee('View students');

        $cancelled = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT1105', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'cancelled');
        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $cancelled->id))
            ->assertOk()
            ->assertSee('This Course Offering was cancelled.')
            ->assertSee('Not available for this Course Offering');
    }

    // ------------------------------------------------------------- 6: roster

    public function test_roster_is_course_offering_scoped_confirmed_only_and_tenant_safe(): void
    {
        $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $other = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT2101', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress');

        $this->confirmStudent($offering, 'S-100', 'Confirmed Student');
        $this->confirmStudent($offering, 'S-200', 'Registered Only Student', CourseRegistration::STATUS_REGISTERED);
        $this->confirmStudent($other, 'S-300', 'Other Offering Student');
        // Same programme, same cohort, same institution - but a different Offering.
        $this->confirmStudent($offering, 'S-999', 'Foreign Tenant Student', CourseRegistration::STATUS_CONFIRMED, $this->tenants['b']);

        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.students', $offering->id))
            ->assertOk()
            ->assertSee('S-100')->assertSee('Confirmed Student')
            // Not confirmed, different Offering, or another tenant.
            ->assertDontSee('S-200')
            ->assertDontSee('S-300')
            ->assertDontSee('S-999');
    }

    // ------------------------------------------- 6: no admin power for lecturer

    public function test_lecturer_gains_no_registration_confirmation_or_placement_power(): void
    {
        $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $this->confirmStudent($offering, 'S-100', 'Confirmed Student');
        $page = $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.students', $offering->id));
        $page->assertOk()->assertSee('This roster is read-only');

        foreach (['register', 'confirm', 'drop', 'lifecycle'] as $forbidden) {
            $this->assertStringNotContainsString(
                "admin/course-offerings/{$offering->id}/{$forbidden}",
                $page->getContent()
            );
        }
        // No lecturer-facing POST route exists for any of these actions.
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === "teacher/course-offerings/{$offering->id}/registrations"
            ),
            'A lecturer registration route must not exist.'
        );

        // And the administrator's own endpoints refuse a lecturer outright, both
        // for their own Offering and for one belonging to somebody else.
        $this->post(route('admin.course_offerings.registrations.store', $offering->id), ['student_id' => 1])->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.bulk', $offering->id), ['mode' => 'selected', 'student_ids' => [1]])->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.confirm', [$offering->id, 1]))->assertForbidden();
        $this->post(route('admin.course_offerings.open', $offering->id))->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.drop', [$offering->id, 1]), ['reason' => 'x'])->assertForbidden();
        $this->get(route('admin.course_offerings.create'))->assertForbidden();
    }

    // ---------------------------------------------------------- 4: filters

    public function test_lecturer_offerings_can_be_filtered_by_year_period_and_status(): void
    {
        $open = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'open');
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT2101', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress');
        $nextYear = (int) DB::table('academic_years')->insertGetId(['school_id' => $this->tenants['a']['school'], 'label' => '2026/2027 Part Two', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        $nextPeriod = (int) DB::table('academic_periods')->insertGetId(['school_id' => $this->tenants['a']['school'], 'academic_year_id' => $nextYear, 'type' => 'semester', 'label' => 'Semester 3', 'sequence' => 3, 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        $later = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT4102', CourseOfferingLecturerAllocation::ROLE_CO_LECTURER, 'in_progress', 'active', $nextYear, $nextPeriod);

        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.index'))
            ->assertOk()->assertSee('BBIT3102')->assertSee('BBIT2101')->assertSee('BBIT4102');

        $this->get(route('teacher.course_offerings.index', ['status' => 'open']))
            ->assertOk()->assertSee('BBIT3102')->assertDontSee('BBIT2101')->assertDontSee('BBIT4102');

        $this->get(route('teacher.course_offerings.index', ['academic_year_id' => $nextYear]))
            ->assertOk()->assertSee('BBIT4102')->assertDontSee('BBIT3102');

        $this->get(route('teacher.course_offerings.index', ['academic_period_id' => $open->academic_period_id]))
            ->assertOk()->assertSee('BBIT3102')->assertDontSee('BBIT4102');

        $this->assertNotNull($later);
    }

    public function test_list_never_exposes_internal_database_identifiers(): void
    {
        $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $content = $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.index'))->assertOk()->getContent();

        $this->assertStringContainsString('teacher/course-offerings/'.$offering->id, $content, 'The offering is addressable by its own route.');
        $this->assertStringNotContainsString('Course Offering #', $content);
        $this->assertStringNotContainsString('Allocation #', $content);
    }

    // --------------------------------------------------------- 15: empty states

    public function test_empty_states_read_as_human_sentences(): void
    {
        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.index'))
            ->assertOk()
            ->assertSee('You do not currently have any Course Offerings assigned.');

        $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $this->get(route('teacher.course_offerings.students', $offering->id))
            ->assertOk()
            ->assertSee('No confirmed students are currently registered for this Course Offering.');
        $this->get(route('teacher.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('No Live Classes have been scheduled yet.');
    }

    // ------------------------------------------- 12: dashboard + navigation

    public function test_hei_lecturer_dashboard_and_navigation_expose_the_workspace(): void
    {
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');

        $this->actingAs($this->lecturerA)->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('View My Course Offerings')
            ->assertSee('My Course Offerings')
            ->assertSee(route('teacher.course_offerings.index'), false);

        $this->get(route('teacher.course_offerings.index'))
            ->assertOk()
            ->assertSee('My Course Offerings', false);
    }

    public function test_k12_institution_keeps_teacher_terminology_and_no_course_offering_navigation(): void
    {
        $this->actingAs($this->k12Teacher)->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertDontSee('View My Course Offerings');

        $this->get(route('teacher.course_offerings.index'))->assertOk()->assertSee('Subject');
    }

    // ------------------- 6b: no institution-wide staffing statistics for lecturers

    /**
     * THE INSTITUTION OWNER'S INSTRUCTION.
     *
     * Lecturers must not be shown institution-wide staffing statistics. The
     * "Teacher - Total Teacher" and "Staff - Total Staff" cards are removed from
     * `/teacher/dashboard` entirely: label, number and icon.
     *
     * Asserted for THREE separate accounts across TWO institutions - an HEI lecturer,
     * a second HEI lecturer, and a k12 teacher - because the requirement is about the
     * role and not about one person. A change that only hid the cards from a
     * particular dashboard would still pass a single-account test.
     */
    public function test_no_lecturer_sees_institution_wide_staffing_statistics(): void
    {
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $this->allocated($this->tenants['a'], $this->lecturerB, 'BBIT2201', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');

        foreach ([$this->lecturerA, $this->lecturerB, $this->k12Teacher] as $lecturer) {
            $html = $this->actingAs($lecturer)
                ->get(route('teacher.dashboard'))
                ->assertOk()
                ->getContent();

            // The labels AND their numbers' captions.
            $this->assertStringNotContainsString('Total Teacher', $html,
                'a lecturer must not be shown the institution-wide teacher count');
            $this->assertStringNotContainsString('Total Staff', $html,
                'a lecturer must not be shown the institution-wide staff count');

            // The card headings.
            $this->assertStringNotContainsString('Teacher_icon', $html,
                'the card and its icon must be removed, not merely its number');
            $this->assertStringNotContainsString('Staff_icon', $html,
                'the card and its icon must be removed, not merely its number');

            // Lecturer-RELEVANT cards are untouched.
            $this->assertStringContainsString('Total Student', $html);
            $this->assertStringContainsString('Total Parent', $html);
            $this->assertStringContainsString('Upcoming Events', $html);
        }
    }

    /**
     * The removal must not become a data leak into the page by another route, and
     * must not have quietly emptied the whole grid.
     */
    public function test_removing_the_staffing_cards_leaves_a_balanced_grid(): void
    {
        $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');

        $html = $this->actingAs($this->lecturerA)->get(route('teacher.dashboard'))->assertOk()->getContent();

        // The My Course Offerings section is preserved.
        $this->assertStringContainsString('My Course Offerings', $html);
        $this->assertStringContainsString(route('teacher.course_offerings.index'), $html);

        // Exactly two short-detail cards remain, not four and not none. Counted on
        // `total_no`, which appears once per card: `dashboard_ShortListItem` also
        // matches the plural container and the welcome banner, so it would report 4
        // for 2 cards and pass a removal that never happened.
        $this->assertSame(2, substr_count($html, 'class="total_no"'),
            'two lecturer-relevant cards must remain');

        // The two halves of the row are the same width class, so neither can be
        // stranded beside a gap. `ms-auto` on the events block is what produced the
        // half-empty tablet layout.
        $this->assertSame(2, substr_count($html, 'class="col-lg-6"'),
            'both halves of the dashboard row must share one width class');
        $this->assertStringNotContainsString('ms-auto', $html,
            'no block may be pushed right with a margin, or a narrow viewport strands it');

        // Nothing may force a width: an empty space must be reflowed, not clipped.
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\b(min-width|width)\s*:/',
            $html,
            'the dashboard must not carry a fixed width that cannot reflow'
        );
    }

    /**
     * The staffing counts must not merely be hidden - they must not be fetched.
     *
     * They lived as inline queries in the VIEW, and the controller passed nothing at
     * all. So the assertion is on the view source: with the cards gone, the queries
     * that fed them are gone too, which is where the work actually was.
     */
    public function test_the_lecturer_dashboard_does_not_query_staffing_counts(): void
    {
        $view = (string) file_get_contents(resource_path('views/teacher/dashboard.blade.php'));

        // Roles 2 (admin), 3 (teacher), 4 (accountant) and 5 (librarian) fed the Staff
        // card; role 3 alone fed the Teacher card.
        foreach ([2, 3, 4, 5] as $roleId) {
            $this->assertStringNotContainsString(
                "'role_id', {$roleId}",
                $view,
                "the lecturer dashboard must not count role {$roleId}"
            );
        }

        // And the controller passes no staffing data of its own.
        $controller = (string) file_get_contents(app_path('Http/Controllers/TeacherController.php'));
        $method = substr(
            $controller,
            strpos($controller, 'function teacherDashboard'),
            400
        );

        $this->assertStringContainsString("view('teacher.dashboard')", $method,
            'the dashboard must still render');
        $this->assertStringNotContainsString('role_id', $method,
            'the lecturer dashboard controller must not compute staffing counts');
    }

    /**
     * The Administrator keeps its own staffing statistics.
     *
     * Asserted on the admin view sources rather than by rendering them: the
     * instruction is that the LECTURER screen loses the cards and the admin screens
     * keep theirs, so what is under test is that the admin views were not edited.
     */
    public function test_the_administrator_dashboards_keep_their_staffing_statistics(): void
    {
        foreach (['admin/dashboard_director', 'admin/dashboard_hr'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));

            $this->assertStringContainsString('Total Staff', $source,
                "{$view} must keep its staff statistic");
        }

        // The librarian and parent dashboards are separate screens with their own
        // copies of the teacher statistic, and are out of scope here.
        $this->assertStringContainsString(
            'Total Teacher',
            (string) file_get_contents(resource_path('views/librarian/dashboard.blade.php'))
        );
        $this->assertStringContainsString(
            'Total Teacher',
            (string) file_get_contents(resource_path('views/parent/dashboard.blade.php'))
        );
    }

    // ------------------------------------ 7: existing Live Class authority kept

    public function test_workspace_does_not_weaken_existing_live_class_authorization(): void
    {
        $offering = $this->allocated($this->tenants['a'], $this->lecturerA, 'BBIT3102', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $notMine = $this->allocated($this->tenants['a'], $this->lecturerB, 'BBIT2201', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, 'in_progress');
        $this->liveClass($offering, 'A Class');

        // The workspace lists only this Offering's Live Classes; it does not become
        // a way around the existing Live Class authorization.
        $this->actingAs($this->lecturerA)->get(route('teacher.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('A Class')
            ->assertDontSee('B Class')
            ->assertDontSee('No Live Classes have been scheduled yet.');

        $this->get(route('teacher.course_offerings.show', $notMine->id))->assertNotFound();

        // Existing Live Class rules still apply unchanged to a visible Offering.
        $this->assertSame(1, DB::table('live_classes')->where('course_offering_id', $offering->id)->count());
    }

    // ------------------------------------------------------------- helpers

    private function institution(int $number, string $type): array
    {
        $school = $this->makeSchool();
        // The Academic Period deliberately spans "now" so an allocation inside it is
        // genuinely in force; the suite must not depend on the calendar's date.
        $year = (int) DB::table('academic_years')->insertGetId(['school_id' => $school, 'label' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $period = (int) DB::table('academic_periods')->insertGetId(['school_id' => $school, 'academic_year_id' => $year, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('schools')->where('id', $school)->update(['school_type' => $type, 'current_academic_year_id' => $year, 'current_academic_period_id' => $period]);
        $programmeId = (int) DB::table('programmes')->insertGetId(['school_id' => $school, 'name' => "Programme {$number}", 'code' => "PRG-{$number}", 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ['school' => $school, 'year' => $year, 'period' => $period, 'programme' => $programmeId];
    }

    private function lecturer(array $institution, string $name, array $permissions): User
    {
        $lecturer = User::factory()->create([
            'name' => $name, 'role_id' => 3, 'school_id' => $institution['school'],
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
        foreach ($permissions as $permission) {
            DB::table('user_permissions')->insert(['school_id' => $institution['school'], 'user_id' => $lecturer->id, 'permission' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $lecturer;
    }

    private function allocated(array $institution, User $lecturer, string $unitCode, string $role, string $offeringStatus, string $allocationStatus = 'active', ?int $yearId = null, ?int $periodId = null): CourseOffering
    {
        $yearId ??= $institution['year'];
        $periodId ??= $institution['period'];
        $subjectId = Subject::where('school_id', $institution['school'])->where('code', $unitCode)->value('id');
        if ($subjectId === null) {
            $subjectId = DB::table('subjects')->insertGetId(['school_id' => $institution['school'], 'name' => $unitCode, 'code' => $unitCode, 'created_at' => now(), 'updated_at' => now()]);
        }
        $subjectId = (int) $subjectId;

        $offering = app(CourseOfferingService::class)->createDraft($institution['school'], $subjectId, $yearId, $periodId);
        $period = DB::table('academic_periods')->where('id', $periodId)->first();
        $membershipId = $this->studyPlanEntry($institution, $subjectId, $period);
        app(CourseOfferingService::class)->addApplicability($institution['school'], $offering->id, $membershipId);
        if ($offeringStatus !== 'draft') {
            app(CourseOfferingService::class)->open($institution['school'], $offering->id);
        }
        $allocations = app(CourseOfferingLecturerAllocationService::class);

        // The administrator's real order: allocate first, then teach, then close.
        // A completed Offering is reached only after its allocation is ended,
        // which is the same completion-consistency rule Step 3 governs.
        if ($offeringStatus === 'in_progress' || $offeringStatus === 'completed') {
            app(CourseOfferingService::class)->start($institution['school'], $offering->id);
        }
        // A cancelled Offering is reached only while the lecturer is still
        // allocated: the academic office withdraws the delivery, not the person.
        $writable = true;
        if ($writable) {
            $allocation = $allocations->createPlanned($institution['school'], $offering->id, $lecturer->id, $role, '2026-09-01', '2027-06-30');
            if ($offeringStatus !== 'draft' && $allocationStatus === 'active') {
                $allocations->activate($institution['school'], $allocation->id);
            } elseif ($offeringStatus !== 'draft' && $allocationStatus === 'ended') {
                $allocations->activate($institution['school'], $allocation->id);
                $allocations->end($institution['school'], $allocation->id, '2026-10-31');
            } elseif ($offeringStatus !== 'draft' && $allocationStatus === 'cancelled') {
                $allocations->cancel($institution['school'], $allocation->id, 'Staffing withdrawn');
            }
        }
        if ($offeringStatus === 'completed') {
            // Teaching finished: the appointment is closed out before completion.
            $openAllocation = DB::table('course_offering_lecturer_allocations')
                ->where('course_offering_id', $offering->id)->where('status', 'active')->first();
            if ($openAllocation) {
                $allocations->end($institution['school'], $openAllocation->id, '2026-10-31');
            }
            app(CourseOfferingService::class)->complete($institution['school'], $offering->id);
        }
        if ($offeringStatus === 'cancelled') {
            app(CourseOfferingService::class)->cancel($institution['school'], $offering->id, 'Programme withdrawn');
        }

        return $offering->fresh();
    }

    /** An approved Study Plan entry for this Course Unit in this Academic Period. */
    private function studyPlanEntry(array $institution, int $subjectId, object $period): int
    {
        $curriculumId = (int) DB::table('curricula')->insertGetId([
            'school_id' => $institution['school'], 'programme_id' => $institution['programme'],
            'version' => '2026-V1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stageId = (int) DB::table('curriculum_stages')->insertGetId([
            'school_id' => $institution['school'], 'curriculum_id' => $curriculumId,
            'label' => 'Year 2', 'sequence' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $institution['school'], 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'curriculum_stage_id' => $stageId, 'period_type' => $period->type, 'period_sequence' => $period->sequence,
            'classification' => 'compulsory', 'credits' => '3.00', 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function confirmStudent(CourseOffering $offering, string $code, string $name, string $status = CourseRegistration::STATUS_CONFIRMED, ?array $institution = null): void
    {
        $institution ??= $this->tenants['a'];
        $student = User::factory()->create(['name' => $name, 'code' => $code, 'role_id' => 7, 'school_id' => $institution['school'], 'account_status' => 'active']);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $institution['school'], 'year_of_study' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_registrations')->insert([
            'school_id' => $institution['school'], 'student_id' => $student->id, 'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id, 'curriculum_membership_id' => 1,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function liveClass(CourseOffering $offering, string $title): int
    {
        return (int) DB::table('live_classes')->insertGetId([
            'school_id' => $offering->school_id, 'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id, 'title' => $title, 'platform' => 'jitsi',
            'status' => 'scheduled', 'is_published' => true, 'scheduled_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function academicSchema(): void
    {
        // The staff/admissions helper already provides subjects, programmes and
        // student_profiles; only the HEI academic-delivery tables are added here.
        $tables = [
            'academic_years' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); },
            'academic_periods' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id'); $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); },
            'curricula' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id'); $t->string('version'); $t->unsignedBigInteger('effective_academic_year_id')->nullable(); $t->string('status'); $t->timestamps(); },
            'curriculum_stages' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->timestamps(); },
            'curriculum_memberships' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('curriculum_stage_id'); $t->string('period_type')->nullable(); $t->unsignedSmallInteger('period_sequence')->nullable(); $t->string('classification'); $t->decimal('credits', 6, 2); $t->unsignedSmallInteger('sequence')->default(0); $t->timestamps(); },
            'course_offerings' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id'); $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps(); },
            'course_offering_curriculum_memberships' => function (Blueprint $t): void { $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('curriculum_membership_id'); $t->unsignedBigInteger('subject_id'); $t->timestamps(); $t->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']); },
            'course_offering_lecturer_allocations' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps(); },
            'course_registrations' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable(); $t->unsignedBigInteger('course_offering_id')->nullable(); $t->unsignedBigInteger('curriculum_membership_id')->nullable(); $t->decimal('registered_credits', 6, 2)->nullable(); $t->string('registered_classification')->nullable(); $t->string('status', 20)->default('registered'); $t->timestamps(); $t->unique(['school_id', 'student_id', 'course_offering_id']); },
            'live_classes' => function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('course_offering_id')->nullable(); $t->unsignedBigInteger('teacher_id')->nullable(); $t->string('title'); $t->text('description')->nullable(); $t->string('platform')->nullable(); $t->text('meeting_url')->nullable(); $t->string('meeting_id')->nullable(); $t->string('meeting_password')->nullable(); $t->dateTime('scheduled_at')->nullable(); $t->dateTime('ends_at')->nullable(); $t->string('timezone')->nullable(); $t->date('start_date')->nullable(); $t->time('start_time')->nullable(); $t->time('end_time')->nullable(); $t->string('status')->default('draft'); $t->boolean('is_published')->default(false); $t->boolean('attendance_enabled')->default(true); $t->text('recording_url')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps(); },
        ];
        foreach ($tables as $table => $definition) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
    }
}
