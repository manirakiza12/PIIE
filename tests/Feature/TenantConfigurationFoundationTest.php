<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Support\TenantConfiguration;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TenantConfigurationFoundationTest extends TestCase
{
    private TenantConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = new TenantConfiguration();
    }

    public function test_each_school_resolves_its_own_configuration_and_hei_terminology(): void
    {
        $schoolA = new School(['school_type' => 'higher_ed', 'education_level' => 'tertiary', 'primary_locale' => 'sw', 'academic_calendar_pattern' => 'semester']);
        $schoolB = new School(['school_type' => 'k12', 'primary_locale' => 'fr', 'academic_calendar_pattern' => 'term']);

        $resolvedA = $this->configuration->resolve($schoolA, null, 'en');
        $resolvedB = $this->configuration->resolve($schoolB, null, 'en');

        $this->assertSame('higher_ed', $resolvedA['institution_type']);
        $this->assertSame('tertiary', $resolvedA['education_level']);
        $this->assertSame('semester', $resolvedA['academic_calendar_pattern']);
        $this->assertSame('Lecturer', $resolvedA['terminology_profile']['teacher']);
        $this->assertSame('sw', $resolvedA['locale']);
        $this->assertSame('Teacher', $resolvedB['terminology_profile']['teacher']);
        $this->assertSame('fr', $resolvedB['locale']);
    }

    public function test_locale_resolution_prefers_user_then_tenant_then_platform(): void
    {
        config(['app.locale' => 'en']);
        $tenant = new School(['primary_locale' => 'sw']);

        $this->assertSame('sw', $this->configuration->resolveLocale(null, $tenant, 'en'));
        $this->assertSame('fr', $this->configuration->resolveLocale(new User(['language' => 'fr']), $tenant, 'en'));
        $this->assertSame('en', $this->configuration->resolveLocale(null, new School(), 'en'));
    }

    public function test_missing_tenant_configuration_uses_legacy_safe_defaults(): void
    {
        $school = new School(['school_type' => 'higher_ed', 'school_currency' => '$', 'currency_position' => 'left', 'running_session' => 47]);
        $school->running_session = 47;
        $resolved = $this->configuration->resolve($school, null, 'en');

        $this->assertSame('semester', $resolved['academic_calendar_pattern']);
        $this->assertSame(['code_or_symbol' => '$', 'position' => 'left'], $resolved['currency']);
        $this->assertSame(47, $school->running_session);
        $this->assertSame(config('app.timezone', 'UTC'), $this->configuration->resolve(new School(), null, 'en')['timezone']);
    }

    public function test_configuration_validation_accepts_supported_codes_and_rejects_invalid_values(): void
    {
        $valid = Validator::make([
            'school_type' => 'higher_ed', 'education_level' => 'tertiary', 'primary_locale' => 'sw',
            'country_code' => 'UG', 'timezone' => 'Africa/Kampala', 'academic_calendar_pattern' => 'semester',
        ], TenantConfiguration::configurationRules());
        $this->assertTrue($valid->passes());

        foreach ([
            ['country_code' => 'Uganda'],
            ['country_code' => 'u1'],
            ['primary_locale' => 'english'],
            ['timezone' => 'UTC+3'],
            ['timezone' => 'Not/AZone'],
            ['academic_calendar_pattern' => 'quarter'],
        ] as $invalid) {
            $this->assertFalse(Validator::make($invalid, TenantConfiguration::configurationRules())->passes());
        }
    }

    public function test_terminology_overrides_are_limited_to_known_labels(): void
    {
        $school = new School([
            'school_type' => 'higher_ed',
            'terminology_overrides' => ['teacher' => 'Faculty Member', 'unexpected' => 'Ignored'],
        ]);

        $terminology = $this->configuration->resolve($school, null, 'en')['terminology_profile'];
        $this->assertSame('Faculty Member', $terminology['teacher']);
        $this->assertArrayNotHasKey('unexpected', $terminology);
    }

    public function test_school_update_allowlist_cannot_change_running_session_or_other_columns(): void
    {
        $attributes = TenantConfiguration::schoolUpdateAttributes([
            'title' => 'Edited Tenant',
            'school_currency' => 'KES',
            'running_session' => 999,
            'school_id' => 999,
            'status' => 0,
            'terminology_overrides' => ['teacher' => 'Arbitrary'],
        ]);

        $this->assertSame(['title' => 'Edited Tenant', 'school_currency' => 'KES'], $attributes);
    }
}
