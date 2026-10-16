<?php
namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class ProfileOptionalInformationTest extends TestCase
{
    use StaffModuleTestHelper;

    public function test_admin_profile_handles_missing_and_malformed_optional_information(): void
    {
        $this->bootStaffModuleTestSchema();
        $user = $this->makeAdminUser($this->makeSchool());
        foreach ([null, '', '{}', 'null', 'invalid', '"text"', '{"gender":"Female","birthday":null}'] as $info) {
            $user->update(['user_information' => $info]);
            $this->actingAs($user)->get(route('admin.profile'))->assertOk()
                ->assertSee('name="phone"', false)->assertSee('name="old_photo"', false);
        }
    }

    public function test_superadmin_profile_handles_missing_optional_information(): void
    {
        $this->bootStaffModuleTestSchema();
        $user = $this->makeAdminUser($this->makeSchool());
        \Illuminate\Support\Facades\Schema::create('payment_history', function (\Illuminate\Database\Schema\Blueprint $table) { $table->id(); $table->string('status'); });
        $user->update(['role_id'=>1, 'user_information'=>null]);
        $this->actingAs($user)->get(route('superadmin.profile'))->assertOk();
    }

    public function test_existing_profile_values_and_gender_selection_are_preserved(): void
    {
        $this->bootStaffModuleTestSchema();
        $user = $this->makeAdminUser($this->makeSchool());
        $user->update(['user_information' => json_encode(['phone'=>'+256 788 099193', 'address'=>'Test address', 'gender'=>'Female', 'birthday'=>strtotime('1990-06-15'), 'photo'=>'example.png'])]);
        $response = $this->actingAs($user)->get(route('admin.profile'))->assertOk();
        $doc = new \DOMDocument(); @$doc->loadHTML($response->getContent());
        $xpath = new \DOMXPath($doc);
        $this->assertSame('Female', $xpath->query('//select[@name="gender"]/option[@selected]')->item(0)->getAttribute('value'));
        foreach (['phone'=>'+256 788 099193','address'=>'Test address','eDefaultDateRange'=>'06/15/1990','old_photo'=>'example.png'] as $field=>$value) {
            $this->assertSame($value, $xpath->query('//input[@name="'.$field.'"]')->item(0)->getAttribute('value'));
        }
        $this->assertSame('example.png', json_decode($user->fresh()->user_information, true)['photo']);
    }
}
