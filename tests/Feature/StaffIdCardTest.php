<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\IdCard;
use App\Support\Staff\StaffIdCard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StaffIdCardTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_status')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
        });
        Schema::table('schools', fn (Blueprint $table) => $table->string('phone')->nullable());
        Schema::create('payment_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('status')->nullable();
        });
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20] as $role) {
            DB::table('roles')->insert(['role_id' => $role, 'name' => $role === 3 ? 'Lecturer' : 'Role '.$role, 'school_id' => 0]);
        }
    }

    private function member(int $role = 3): User
    {
        $school = $this->makeSchool(['title' => 'Staff ID Institute']);
        DB::table('schools')->where('id', $school)->update(['phone' => '0770000999']);
        return User::create([
            'school_id' => $school, 'role_id' => $role, 'name' => 'Card Holder '.$role,
            'code' => 'EMP-'.$role, 'email' => uniqid('card.').'@example.com',
            'password' => bcrypt('secret'), 'account_status' => 'active',
            'staff_status' => 'active', 'user_information' => json_encode(['phone' => '0700123456']),
        ]);
    }

    public function test_lecturer_gets_own_staff_id_and_navigation_without_admin_permissions(): void
    {
        $member = $this->member();
        $response = $this->actingAs($member)->get(route('staff.id_card'));
        $response->assertOk()->assertSee('STAFF ID')->assertSee($member->name)->assertSee($member->code);
        $response->assertSee('Lecturer')->assertSee(route('staff.id_card'))->assertSee('My ID Card');
        $response->assertSee(IdCard::INSTITUTION_PHONE)->assertDontSee('0700123456')->assertDontSee('0770000999');
    }

    public function test_all_other_institutional_staff_portals_can_open_their_own_card(): void
    {
        foreach ([1, 2, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20] as $role) {
            $member = $this->member($role);
            $this->actingAs($member)->get(route('staff.id_card'))->assertOk()->assertSee($member->code)->assertSee('STAFF ID')
                ->assertSee('href="'.route('staff.id_card').'"', false);
        }
    }

    public function test_lecturer_id_contact_and_navigation_survive_refresh(): void
    {
        $member = $this->member();
        $this->actingAs($member);
        foreach (range(1, 3) as $request) {
            $this->get(route('staff.id_card'), ['Cache-Control' => 'no-cache'])
                ->assertOk()->assertSee('+256772973574')
                ->assertSee('My ID Card')->assertSee($member->code)
                ->assertDontSee('0700123456')->assertDontSee('0770000999');
        }
    }

    public function test_staff_pdf_download_contains_a_real_single_page_pdf(): void
    {
        $member = $this->member();
        $response = $this->actingAs($member)->get(route('staff.id_card.pdf'));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
        $html = view('staff.id_card_pdf', StaffIdCard::data($member, true))->render();
        $this->assertStringContainsString(IdCard::INSTITUTION_PHONE, $html);
        $this->assertStringNotContainsString('0700123456', $html);
        $this->assertStringContainsString('data:image/', $html);
    }

    public function test_staff_cannot_select_someone_elses_card_via_request_parameters(): void
    {
        $member = $this->member();
        $other = $this->member();
        $other->update(['name' => 'Private Other Staff Name']);
        $this->actingAs($member)->get(route('staff.id_card', ['user_id' => $other->id]))
            ->assertOk()->assertSee($member->name)->assertDontSee($other->name);
    }

    public function test_signed_staff_verification_is_public_and_reflects_employment_status(): void
    {
        $member = $this->member();
        $url = StaffIdCard::verifyUrl($member);
        $this->get($url)->assertOk()->assertSee('VALID STAFF ID')->assertSee($member->name)->assertDontSee('0700123456');
        $member->update(['staff_status' => 'terminated']);
        $this->get($url)->assertOk()->assertSee('THIS ACCOUNT IS NOT ACTIVE')->assertDontSee('VALID STAFF ID');
        $this->get($url.'&changed=1')->assertForbidden();
    }

    public function test_non_staff_guests_and_disabled_staff_cannot_issue_staff_cards(): void
    {
        $this->get(route('staff.id_card'))->assertRedirect(route('login'));
        foreach ([6, 7, 8] as $role) {
            $member = $this->member($role);
            $this->actingAs($member)->get(route('staff.id_card'))->assertForbidden();
            $this->get(route('staff.id_card.pdf'))->assertForbidden();
        }
        $member = $this->member();
        foreach (['suspended', 'inactive', 'terminated'] as $status) {
            $member->update(['staff_status' => $status]);
            $this->actingAs($member)->get(route('staff.id_card'))->assertForbidden();
            $this->get(route('staff.id_card.pdf'))->assertForbidden();
        }
        $member->update(['staff_status' => 'active', 'account_status' => 'disable']);
        $this->get(route('staff.id_card'))->assertForbidden();
    }

    public function test_generic_staff_must_complete_password_setup_before_issuing_a_card(): void
    {
        $member = $this->member(20);
        $member->update(['force_password_change' => true]);
        $this->actingAs($member)->get(route('staff.id_card'))->assertForbidden();
    }

    public function test_existing_student_cards_use_the_same_institution_number_on_screen_and_pdf(): void
    {
        $member = $this->member(7);
        $response = $this->actingAs($member)->get(route('student.id_card'));
        $response->assertOk()->assertSee(IdCard::INSTITUTION_PHONE)->assertDontSee('0700123456')->assertDontSee('0770000999');
        $html = view('student.id_card_pdf', $response->viewData())->render();
        $this->assertStringContainsString(IdCard::INSTITUTION_PHONE, $html);
        $this->assertStringNotContainsString('0700123456', $html);
        $this->assertStringNotContainsString('0770000999', $html);
    }
}
