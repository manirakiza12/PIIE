<?php
namespace Tests\Feature;

use App\Models\Admission;
use App\Support\Admissions\ApplicationFee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicationFeeCurrencyTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_currency')->nullable());
        $school = $this->makeSchool(['school_currency'=>'UGX']);
        DB::table('global_settings')->insert([['key'=>'primary_school_id','value'=>(string)$school],['key'=>'system_currency','value'=>'USD']]);
    }

    public function test_public_fee_uses_institution_currency_even_when_global_currency_is_usd(): void
    {
        $this->assertSame('UGX 100,000.00', ApplicationFee::format(100000));
        $school = \App\Support\PublicTenantResolver::resolveSchoolId();
        $this->makeIntakeSession($school, ['application_fee'=>100000]);
        $this->get(route('apply.form'))->assertOk()->assertSee('UGX 100,000.00')->assertDontSee('USD 100,000');
        DB::table('global_settings')->where('key','system_currency')->delete();
        $this->assertSame('UGX', ApplicationFee::currency());
    }

    public function test_obligation_uses_its_own_school_and_retains_a_frozen_currency(): void
    {
        $other = $this->makeSchool(['school_currency'=>'KES']);
        $admission = Admission::findOrFail($this->makeAdmission($other, ['intake_session_id'=>$this->makeIntakeSession($other,['application_fee'=>100000])]));
        $this->assertSame('KES', ApplicationFee::currencyFor($admission));
        ApplicationFee::freezeObligation($admission);
        $this->assertSame('KES', $admission->fresh()->application_fee_currency);
        DB::table('schools')->where('id',$other)->update(['school_currency'=>'UGX']);
        $this->assertSame('KES 100,000.00', ApplicationFee::format(100000,$admission->fresh()));
        ApplicationFee::freezeObligation($admission);
        $this->assertSame('KES', $admission->fresh()->application_fee_currency);
    }

    public function test_unconfigured_or_invalid_school_currency_retains_legacy_fallback(): void
    {
        DB::table('schools')->update(['school_currency'=>null]);
        $this->assertSame('USD', ApplicationFee::currency());
        DB::table('schools')->update(['school_currency'=>'invalid']);
        $this->assertSame('USD', ApplicationFee::currency());
    }
}
