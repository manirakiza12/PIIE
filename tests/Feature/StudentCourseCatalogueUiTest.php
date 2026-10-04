<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use App\Support\CourseRegistration\CourseRegistrationService;
use App\Support\CourseRegistration\StudentCourseOfferingDiscovery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\Feature\Support\CourseOfferingEligibilityFixture;
use Tests\TestCase;

/**
 * The student course catalogue — the card grid on My Courses.
 *
 * ── WHAT IS ASSERTED, AND WHY EACH ONE MATTERS ─────────────────────────────
 *
 * Visibility and exclusion are asserted first, because a card grid is a wider
 * surface than a table: a card shows a title, an image and a lecturer, and each
 * of those is something that must not leak. The exclusion tests come before the
 * cosmetic ones deliberately.
 *
 * The search and filters are asserted as an AUTHORISATION boundary, not just a
 * convenience. They narrow a collection that `discover()` already restricted, and
 * the tests prove that a crafted query string cannot widen it — a filter that
 * returned anything the student was not already entitled to would be the single
 * most damaging thing this feature could get wrong.
 *
 * The cover tests pin the one genuinely surprising decision in this phase: a
 * cover image is offered on CONFIRMED cards only, because
 * `CourseCoverImage::assertMayView()` admits a student on a confirmed
 * registration and nothing else. Rendering an `<img>` anywhere else would put a
 * URL in the page that the server answers 404.
 */
class StudentCourseCatalogueUiTest extends TestCase
{
    use AdmissionsTestHelper;
    use CourseOfferingEligibilityFixture;

    private const COVER_PATH = 'course-covers/11/abcdef0123456789abcdef0123456789.jpg';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        // `programme_based` structure plus the current academic context the
        // student route resolves through. Same additions the sibling HEI tests make.
        \Illuminate\Support\Facades\Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });

        $this->createTables();
        $this->seedAcademicStructure();

        // The shared fixture builds `course_offerings` from a hand-written schema
        // that predates the cover columns. Added here, conditionally, rather than
        // in the fixture, so this test does not oblige the twenty-odd other test
        // classes that use that fixture to change. The "no cover column at all"
        // case is covered separately and must still work.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('course_offerings', 'cover_image_path')) {
            \Illuminate\Support\Facades\Schema::table('course_offerings', function (Blueprint $table): void {
                $table->string('cover_image_path')->nullable();
                $table->string('cover_image_name')->nullable();
                $table->string('cover_image_mime')->nullable();
                $table->unsignedBigInteger('cover_image_size')->nullable();
                $table->timestamp('cover_image_updated_at')->nullable();
            });
        }

        $this->student = $this->placedStudent(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function register(int $offeringId, int $membershipId, string $status = 'registered'): int
    {
        $registration = app(CourseRegistrationService::class)
            ->registerStudentForOffering($this->school, $this->student->id, $offeringId, $membershipId, $this->student->id);

        if ($status !== 'registered') {
            DB::table('course_registrations')->where('id', $registration->id)->update(['status' => $status]);
        }

        return (int) $registration->id;
    }

    private function allocateLecturer(int $offeringId, string $name, string $role = 'primary_lecturer', int $schoolId = null): void
    {
        $lecturer = User::create([
            'name' => $name, 'email' => str($name)->slug().'@example.com',
            'password' => bcrypt('x'), 'role_id' => 6, 'school_id' => $schoolId ?? $this->school,
        ]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $schoolId ?? $this->school, 'course_offering_id' => $offeringId,
            'user_id' => $lecturer->id, 'role' => $role,
            'starts_on' => now()->subDay()->toDateString(), 'status' => 'active',
        ]);
    }

    private function giveCover(int $offeringId, string $path = self::COVER_PATH): void
    {
        Storage::fake('local');
        Storage::disk('local')->put($path, 'bytes');
        DB::table('course_offerings')->where('id', $offeringId)->where('school_id', $this->school)->update([
            'cover_image_path' => $path,
            'cover_image_name' => 'cover.jpg',
            'cover_image_mime' => 'image/jpeg',
            'cover_image_size' => 5,
        ]);
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->student)
            ->get(route('student.my_courses').($query ? '?'.http_build_query($query) : ''));
    }

    /**
     * The course titles actually rendered on cards.
     *
     * Assertions use this rather than a plain substring search because the page
     * echoes the search term back into the filter input. An `assertDontSee` on a
     * course code would then pass or fail for the wrong reason: the string is
     * present in `value="bbit1101"` whether or not a card was rendered at all.
     *
     * @return array<int,string>
     */
    private function cardTitles(string $html): array
    {
        preg_match_all('/sc-card__title">(.*?)</s', $html, $matches);

        return array_map('trim', $matches[1] ?? []);
    }

    /** @return array<int,string> */
    private function cardCodes(string $html): array
    {
        preg_match_all('/sc-card__code">(.*?)</s', $html, $matches);

        return array_map('trim', $matches[1] ?? []);
    }

    private function cardCount(string $html): int
    {
        return substr_count($html, 'data-sc-card=');
    }

    // ── Eligible visibility ───────────────────────────────────────────────────

    public function test_an_eligible_student_sees_their_courses_as_cards(): void
    {
        $response = $this->page();

        $response->assertOk();
        // Offering 11 is the Year 1 unit for a Year 1 student on cohort 1, and it is
        // the ONLY eligible one: offering 12 is the same unit but in 2027/2028, and
        // 21/31 belong to other stages.
        $this->assertSame(['BBIT1101'], $this->cardTitles($response->getContent()));
        $this->assertSame(1, $this->cardCount($response->getContent()));
        $response->assertSee('Available to Register');
    }

    public function test_a_card_carries_the_title_code_status_and_offering(): void
    {
        $this->allocateLecturer(11, 'Dr Amina Okello');

        $html = $this->page()->assertOk()->getContent();

        // Title and code.
        $this->assertStringContainsString('BBIT1101', $html);
        // Allocated lecturer.
        $this->assertStringContainsString('Dr Amina Okello', $html);
        // Status.
        $this->assertStringContainsString('Available to Register', $html);
        // Academic context and the Offering reference.
        $this->assertStringContainsString('2026/2027', $html);
        $this->assertStringContainsString('Semester 1', $html);
        $this->assertStringContainsString('REF-11', $html);
    }

    // ── Exclusion ─────────────────────────────────────────────────────────────

    public function test_a_student_never_sees_a_course_they_are_not_placed_for(): void
    {
        // A Year 3 student on the same curriculum. Offering 21 is the Year 2 unit
        // and 11 is Year 1, so eligibility — not visibility luck — must exclude them.
        $year3 = $this->placedStudent(3);

        $html = $this->actingAs($year3)->get(route('student.my_courses'))->assertOk()->getContent();

        $this->assertContains('BBIT3102', $this->cardTitles($html), 'the Year 3 unit should be offered');
        $this->assertNotContains('BBIT1101', $this->cardTitles($html), 'a Year 1 unit leaked to a Year 3 student');
        $this->assertNotContains('BBIT2101', $this->cardTitles($html), 'a Year 2 unit leaked to a Year 3 student');
    }

    public function test_a_student_sees_only_their_own_registrations(): void
    {
        $this->register(11, 111, 'confirmed');
        $other = $this->placedStudent(2);
        app(CourseRegistrationService::class)->registerStudentForOffering(
            $this->school, $other->id, 21, 121, $other->id
        );

        $html = $this->page()->assertOk()->getContent();

        $this->assertContains('BBIT1101', $this->cardTitles($html), 'own confirmed registration missing');
        $this->assertNotContains('BBIT2101', $this->cardTitles($html), "another student's registration leaked");
    }

    public function test_a_filters_cannot_widen_the_catalogue_beyond_discovery(): void
    {
        $year3 = $this->placedStudent(3);

        // Every filter value is one the Year 3 student could NOT otherwise reach.
        foreach ([['q' => 'BBIT1101'], ['year' => '2027/2028'], ['period' => 'Semester 2'], ['q' => 'REF-21']] as $query) {
            $html = $this->actingAs($year3)->get(route('student.my_courses').'?'.http_build_query($query))
                ->assertOk()->getContent();

            // Measured on rendered cards, not on raw text: the filter input echoes
            // the search term back, so a substring check would be meaningless here.
            $this->assertNotContains('BBIT1101', $this->cardTitles($html), 'a filter exposed a course: '.json_encode($query));
            $this->assertNotContains('BBIT2101', $this->cardTitles($html), 'a filter exposed a course: '.json_encode($query));
        }
    }

    public function test_another_tenants_offering_never_appears(): void
    {
        DB::table('course_offerings')->insert([
            'id' => 900, 'school_id' => $this->foreignSchool, 'subject_id' => 999,
            'academic_year_id' => 1, 'academic_period_id' => 1,
            'reference' => 'REF-FOREIGN', 'status' => 'open',
        ]);

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringNotContainsString('REF-FOREIGN', $html);
        $this->assertStringNotContainsString('student/courses/900/cover-image', $html);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function test_search_matches_title_code_and_lecturer(): void
    {
        $this->allocateLecturer(11, 'Dr Amina Okello');

        $this->assertContains('BBIT1101', $this->cardTitles($this->page(['q' => 'BBIT1101'])->getContent()));
        $this->assertContains('BBIT1101', $this->cardTitles($this->page(['q' => 'amina'])->getContent()), 'search must reach the lecturer name');
        $this->assertContains('BBIT1101', $this->cardTitles($this->page(['q' => 'ref-11'])->getContent()), 'search must reach the reference');
    }

    public function test_search_requires_every_term_to_match(): void
    {
        $this->allocateLecturer(11, 'Dr Amina Okello');

        $this->assertContains('BBIT1101', $this->cardTitles($this->page(['q' => 'bbit amina'])->getContent()));
        // "nonsense" matches nothing, so no card survives.
        $this->assertSame(0, $this->cardCount($this->page(['q' => 'bbit nonsense'])->getContent()));
    }

    public function test_search_is_case_insensitive_and_whitespace_tolerant(): void
    {
        $this->assertContains('BBIT1101', $this->cardTitles($this->page(['q' => '  bBiT1101  '])->getContent()));
    }

    // ── Year and semester filters ─────────────────────────────────────────────

    public function test_the_year_and_semester_filters_narrow_the_visible_cards(): void
    {
        $all = $this->page()->assertOk()->getContent();
        $this->assertSame(1, $this->cardCount($all), 'unfiltered baseline');

        $semesterOnly = $this->page(['period' => 'Semester 1'])->getContent();
        $this->assertSame(1, $this->cardCount($semesterOnly), 'a matching filter must hide nothing');

        $noMatch = $this->page(['period' => 'Semester 2'])->getContent();
        $this->assertSame(0, $this->cardCount($noMatch));
        $this->assertStringContainsString('No courses match your search in this section.', $noMatch);
    }

    public function test_an_unmatched_filter_says_zero_rather_than_pretending_there_is_nothing(): void
    {
        // The distinction matters: "you have no courses" sends a student looking for
        // a course they actually have.
        $html = $this->page(['q' => 'zzzz-nothing'])->getContent();

        $this->assertStringContainsString('No courses match your search in this section.', $html);
        $this->assertStringContainsString('0 / 1', $html);
        $this->assertStringNotContainsString('You have no course registrations yet', $html);
    }

    public function test_the_filter_dropdown_keeps_its_options_while_a_filter_is_applied(): void
    {
        // Picking a year must not remove that year from the list, or the student
        // cannot widen the selection again without clearing the whole form.
        $html = $this->page(['year' => '2026/2027'])->getContent();

        $this->assertStringContainsString('<option value="2026/2027" selected', $html);
        $this->assertStringContainsString('All years', $html);
    }

    public function test_the_search_and_filters_are_a_get_form(): void
    {
        $html = $this->page()->getContent();

        $this->assertStringContainsString('method="GET"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('name="year"', $html);
        $this->assertStringContainsString('name="period"', $html);
    }

    // ── Registration, confirmation and drop are preserved ─────────────────────

    public function test_an_available_card_still_posts_the_offering_id(): void
    {
        $html = $this->page()->getContent();

        $this->assertStringContainsString('name="course_offering_id"', $html);
        // The form must submit only the Offering, never a curriculum membership —
        // the controller chooses the membership server-side.
        $this->assertStringNotContainsString('name="curriculum_membership_id"', $html);
    }

    public function test_the_register_action_still_works_end_to_end(): void
    {
        $this->actingAs($this->student)
            ->post(route('student.my_courses.register'), ['course_offering_id' => 11])
            ->assertRedirect(route('student.my_courses'));

        $this->assertSame(1, DB::table('course_registrations')
            ->where('student_id', $this->student->id)->where('course_offering_id', 11)->count());
    }

    public function test_confirm_and_drop_controls_survive_the_redesign(): void
    {
        $registrationId = $this->register(11, 111, 'confirmed');

        $html = $this->page()->getContent();

        $this->assertStringContainsString(route('student.my_courses.drop', $registrationId), $html);
        $this->assertStringContainsString('Confirmed Courses', $html);
    }

    public function test_a_pending_card_offers_confirm_when_finances_allow_it(): void
    {
        $registrationId = $this->register(11, 111);

        $html = $this->page()->getContent();

        $this->assertStringContainsString(route('student.my_courses.confirm', $registrationId), $html);
    }

    public function test_a_pending_card_withdraws_confirm_while_a_balance_is_outstanding(): void
    {
        $registrationId = $this->register(11, 111);
        DB::table('student_fee_managers')->insert([
            'student_id' => $this->student->id, 'school_id' => $this->school,
            'title' => 'Tuition', 'class_id' => 0, 'payment_method' => 'cash', 'status' => 'unpaid',
            'total_amount' => 500, 'paid_amount' => 0,
        ]);

        $html = $this->page()->getContent();

        // A button the controller will refuse is worse than no button.
        $this->assertStringNotContainsString(route('student.my_courses.confirm', $registrationId), $html);
        $this->assertStringContainsString('Confirmation is unavailable until your outstanding balance is resolved.', $html);
        $this->assertStringContainsString(route('student.fee_manager.list'), $html);
    }

    public function test_drop_is_withheld_once_the_offering_is_in_progress(): void
    {
        $registrationId = $this->register(11, 111, 'confirmed');
        DB::table('course_offerings')->where('id', 11)->update(['status' => 'in_progress']);

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString(route('student.my_courses.drop', $registrationId), $html);
        $this->assertStringContainsString('Withdrawal requires Academic Office assistance.', $html);
    }

    // ── Course-opening routes ─────────────────────────────────────────────────

    public function test_a_confirmed_card_links_to_course_content_and_assignments(): void
    {
        $this->register(11, 111, 'confirmed');

        $html = $this->page()->getContent();

        $this->assertStringContainsString(route('student.courses.content', 11), $html);
        $this->assertStringContainsString(route('student.courses.assignments.index', 11), $html);
        $this->assertStringContainsString('Open Course', $html);
    }

    public function test_a_dropped_card_offers_no_actions_at_all(): void
    {
        $registrationId = $this->register(11, 111, 'confirmed');
        $this->actingAs($this->student)->post(route('student.my_courses.drop', $registrationId));

        $html = $this->page()->getContent();

        $this->assertStringContainsString('Dropped', $html);
        $this->assertStringNotContainsString(route('student.my_courses.confirm', $registrationId), $html);
        $this->assertStringNotContainsString(route('student.my_courses.drop', $registrationId), $html);
        $this->assertStringNotContainsString(route('student.courses.content', 11), $html);
    }

    // ── Cover images ──────────────────────────────────────────────────────────

    public function test_a_confirmed_card_with_a_cover_points_at_the_authorising_route(): void
    {
        $this->register(11, 111, 'confirmed');
        $this->giveCover(11);

        $html = $this->page()->getContent();

        $this->assertStringContainsString(route('student.courses.cover', 11), $html);
        $this->assertStringContainsString('sc-card__media', $html);
    }

    public function test_the_private_storage_path_never_reaches_the_page(): void
    {
        $this->register(11, 111, 'confirmed');
        $this->giveCover(11);

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString('course-covers/11/abcdef', $html);
        $this->assertStringNotContainsString('abcdef0123456789', $html);
    }

    public function test_a_course_without_a_cover_falls_back_to_the_piiE_placeholder(): void
    {
        $this->register(11, 111, 'confirmed');

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString(route('student.courses.cover', 11), $html);
        $this->assertStringContainsString('sc-card__fallback', $html);
        $this->assertStringContainsString('BBIT1101', $html, 'the placeholder should carry the course code');
    }

    public function test_a_pending_card_never_renders_a_cover_url(): void
    {
        // The cover route admits a CONFIRMED registration only. A pending card
        // pointing at it would ship a URL the server answers 404 — a broken image.
        $this->register(11, 111);
        $this->giveCover(11);

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString(route('student.courses.cover', 11), $html);
        $this->assertStringContainsString('sc-card__fallback', $html);
    }

    public function test_an_available_card_never_renders_a_cover_url(): void
    {
        // Not registered at all — the same reason.
        $this->giveCover(11);

        $this->assertStringNotContainsString(route('student.courses.cover', 11), $this->page()->getContent());
    }

    public function test_a_dropped_card_never_renders_a_cover_url(): void
    {
        $registrationId = $this->register(11, 111, 'confirmed');
        $this->giveCover(11);
        $this->actingAs($this->student)->post(route('student.my_courses.drop', $registrationId));

        $this->assertStringNotContainsString(route('student.courses.cover', 11), $this->page()->getContent());
    }

    public function test_the_catalogue_still_works_on_a_schema_with_no_cover_column_at_all(): void
    {
        // The fixture's original schema has no cover columns. Reading the model
        // attribute simply yields nothing, so the grid degrades to placeholders
        // instead of throwing — which is why the lookup is not a column in
        // `discover()`'s select list.
        \Illuminate\Support\Facades\Schema::table('course_offerings', function (Blueprint $table): void {
            $table->dropColumn(['cover_image_path', 'cover_image_name', 'cover_image_mime', 'cover_image_size', 'cover_image_updated_at']);
        });

        $this->register(11, 111, 'confirmed');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('sc-card__fallback', $html);
        $this->assertStringContainsString('BBIT1101', $html);
    }

    // ── Responsive layout contract ────────────────────────────────────────────

    public function test_the_card_grid_declares_four_two_and_one_columns(): void
    {
        $css = File::get(base_path('public/css/student-courses.css'));

        // Base: one column (mobile).
        $this->assertMatchesRegularExpression(
            '/\.sc-grid\s*\{[^}]*grid-template-columns:\s*1fr\s*;/s',
            $css,
            'the grid must default to a single column'
        );
        // Tablet: two.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*768px\)\s*\{\s*\.sc-grid\s*\{[^}]*repeat\(2,/s',
            $css,
            'tablet must be two columns'
        );
        // Desktop: four.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*1200px\)\s*\{\s*\.sc-grid\s*\{[^}]*repeat\(4,/s',
            $css,
            'desktop must be four columns'
        );
    }

    public function test_the_stylesheet_is_loaded_on_the_student_shell(): void
    {
        $this->assertStringContainsString('css/student-courses.css', $this->page()->getContent());
    }

    public function test_the_card_media_box_is_sixteen_by_nine_with_cover(): void
    {
        $css = File::get(base_path('public/css/student-courses.css'));

        $this->assertMatchesRegularExpression('/\.sc-card__media\s*\{[^}]*aspect-ratio:\s*16\s*\/\s*9/s', $css);
        $this->assertMatchesRegularExpression('/\.sc-card__media img\s*\{[^}]*object-fit:\s*cover/s', $css);
    }

    // ── No interference ───────────────────────────────────────────────────────

    public function test_viewing_the_catalogue_creates_no_academic_record(): void
    {
        $before = [
            'offerings' => DB::table('course_offerings')->count(),
            'registrations' => DB::table('course_registrations')->count(),
            'members' => DB::table('course_offering_curriculum_memberships')->count(),
            'allocations' => DB::table('course_offering_lecturer_allocations')->count(),
        ];

        $this->page();
        $this->page(['q' => 'bbit']);
        $this->page(['year' => '2026/2027', 'period' => 'Semester 1']);

        $after = [
            'offerings' => DB::table('course_offerings')->count(),
            'registrations' => DB::table('course_registrations')->count(),
            'members' => DB::table('course_offering_curriculum_memberships')->count(),
            'allocations' => DB::table('course_offering_lecturer_allocations')->count(),
        ];

        $this->assertSame($before, $after, 'rendering the catalogue wrote to an academic table');
    }

    public function test_the_catalogue_does_not_create_a_second_source_of_truth_for_courses(): void
    {
        $this->register(11, 111, 'confirmed');

        $view = app(StudentCourseOfferingDiscovery::class)->discover($this->student);
        $catalogue = app(\App\Support\CourseRegistration\StudentCourseCatalogue::class, ['schoolId' => $this->school])
            ->build($view);

        // Exactly one card per confirmed registration, keyed to the same Offering.
        $this->assertCount(1, $catalogue['sections']['confirmed']['cards']);
        $this->assertSame(
            $view['confirmed']->pluck('offering_id')->map(fn ($id) => (int) $id)->all(),
            $catalogue['sections']['confirmed']['cards']->pluck('offering_id')->all(),
        );
    }

    public function test_the_assignment_route_still_refuses_a_student_who_is_not_registered(): void
    {
        // The card grid links Assignments on confirmed registrations only. This
        // asserts the underlying page still enforces its own rule, so the link
        // being absent is belt-and-braces rather than the only protection.
        $this->actingAs($this->student)->get(route('student.courses.assignments.index', 21))
            ->assertStatus(404);
    }

    public function test_exam_and_offerings_tables_are_untouched_by_the_catalogue(): void
    {
        $before = DB::table('course_offerings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();

        $this->page();

        $this->assertSame($before, DB::table('course_offerings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all());
    }

    // ── Unavailable academic context ──────────────────────────────────────────

    public function test_a_student_without_an_academic_context_sees_a_clear_message_not_an_error(): void
    {
        // `discover()` returns early for a missing year/period. The page must still
        // render 200 with an explanation, exactly as the table layout did.
        DB::table('schools')->where('id', $this->school)->update(['current_academic_period_id' => null]);

        $response = $this->page();

        $response->assertOk();
        $this->assertSame(0, $this->cardCount($response->getContent()));
    }
}
