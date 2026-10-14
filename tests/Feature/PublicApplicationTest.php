<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Applicant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The public admissions entry point.
 *
 * The guarantees here used to belong to a single anonymous form that created
 * an Admission outright. That form is gone — the apply page at `/admissions/apply`
 * (moved from `/apply`, which now 301-redirects; see ApplyUrlMoveTest) is a
 * landing page and the first real write is applicant registration — so the same
 * guarantees (no school selector, tenant resolved from configuration, validation,
 * honeypot, rate limiting) are asserted against the registration endpoint.
 * See ApplicantPortalTest for the application flow that follows.
 */
class PublicApplicationTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Alice',
            'last_name'             => 'Applicant',
            'email'                 => 'alice@example.com',
            'phone_code'            => '+256',
            'phone_number'          => '0700111222',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
            'terms'                 => '1',
        ], $overrides);
    }

    public function test_programme_selection_survives_registration_and_is_preselected_without_changing_an_existing_choice(): void
    {
        $school = $this->makeSchool();
        $this->configurePrimarySchool($school);
        $programme = $this->makeProgramme($school, ['name' => 'Bachelor of Computing', 'is_published' => 1]);
        $this->makeIntakeSession($school);
        $this->get(route('apply.form', ['programme_id' => $programme]))->assertOk()
            ->assertSessionHas('public_programme_selection', ['school_id' => $school, 'programme_id' => $programme]);
        $this->post(route('applicant.register.submit'), $this->registrationPayload())->assertRedirect();
        $this->get(route('applicant.application.step', 'programme'))->assertOk()
            ->assertSee('value="'.$programme.'" selected', false);
        $admission = Admission::firstOrFail();
        $existing = $this->makeProgramme($school);
        $admission->update(['programme_id' => $existing]);
        $this->get(route('applicant.application.step', 'programme'))->assertOk()
            ->assertSee('value="'.$existing.'" selected', false);
    }

    public function test_contextual_application_links_reject_foreign_inactive_and_unpublished_programmes(): void
    {
        $school = $this->makeSchool();
        $this->configurePrimarySchool($school);
        foreach ([
            $this->makeProgramme($this->makeSchool(), ['is_published' => 1]),
            $this->makeProgramme($school, ['is_published' => 1, 'is_active' => 0]),
            $this->makeProgramme($school),
        ] as $programme) {
            $this->get(route('apply.form', ['programme_id' => $programme]))->assertNotFound();
        }
    }

    public function test_apply_page_shows_no_school_selector_and_uses_configured_institution(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->makeProgramme($schoolId, ['name' => 'BSc Computer Science']);

        $response = $this->get(route('apply.form'));

        $response->assertStatus(200);
        $response->assertSee('BSc Computer Science');
        $response->assertDontSee('name="school_id"', false);
        $response->assertDontSee('Select a school', false);
    }

    public function test_registration_page_shows_no_school_selector(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);

        $response = $this->get(route('applicant.register'));

        $response->assertStatus(200);
        $response->assertDontSee('name="school_id"', false);
    }

    public function test_registration_creates_the_applicant_under_the_configured_school_without_any_school_input(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);

        $response = $this->post(route('applicant.register.submit'), $this->registrationPayload());

        $response->assertRedirect(route('applicant.dashboard'));

        $applicant = Applicant::first();
        $this->assertNotNull($applicant);
        $this->assertSame($schoolId, (int) $applicant->school_id);
        $this->assertSame('Alice', $applicant->first_name);
    }

    public function test_registration_is_rejected_without_required_fields(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);

        $response = $this->post(route('applicant.register.submit'), []);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'email', 'phone_code', 'phone_number', 'password', 'terms']);
        $this->assertSame(0, Applicant::count());
    }

    public function test_honeypot_field_silently_blocks_bot_registrations(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);

        $response = $this->post(route('applicant.register.submit'), $this->registrationPayload([
            'website' => 'http://spam.example.com',
        ]));

        $response->assertRedirect(route('applicant.login'));
        $this->assertSame(0, Applicant::count());
        $this->assertSame(0, Admission::count());
    }

    public function test_registration_endpoint_is_rate_limited(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);

        // Attempts are deliberately invalid. A *successful* registration signs
        // the applicant in, and every later request is then bounced by the
        // applicant.guest middleware before it reaches the throttle — so a
        // valid payload would only ever land one attempt on the limiter.
        // Abusive traffic is exactly the unauthenticated, failing kind this
        // asserts against.
        $garbage = ['first_name' => 'Bot'];

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('applicant.register.submit'), $garbage)->assertStatus(302);
        }

        $this->post(route('applicant.register.submit'), $garbage)->assertStatus(429);
        $this->assertSame(0, Applicant::count());
    }

    public function test_applications_are_unavailable_when_no_institution_is_configured(): void
    {
        // No schools at all — PublicTenantResolver has nothing to fall back to.
        $this->get(route('apply.form'))->assertStatus(503);
    }
}
