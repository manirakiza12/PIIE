<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/** Release requirements: use real guards, no bypass or middleware suppression. */
class SubscriptionAdminIntegrityTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        config([
            'app.bypass_subscription' => false,
            'app.enforce_school_subscriptions' => true,
        ]);

        DB::table('packages')->insert([
            'id' => 1,
            'name' => 'Existing fixture plan',
            'price' => 100,
            'interval' => 'monthly',
            'days' => 1,
            'status' => 1,
        ]);

        Schema::create('payment_history', function (Blueprint $table) {
            $table->id();
            $table->integer('school_id');
            $table->integer('user_id');
            $table->integer('package_id');
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->string('paid_by')->nullable();
            $table->string('document_image')->nullable();
            $table->integer('timestamp')->nullable();
            $table->timestamps();
        });
    }

    public static function schoolStates(): array
    {
        return [['active',200],['expired',302],['suspended',302],['unpaid',302]];
    }

    private function subscription(int $school,string $state): void
    {
        DB::table('subscriptions')->where('school_id',$school)->delete();
        if($state==='unpaid')return;
        DB::table('subscriptions')->insert(['school_id'=>$school,'package_id'=>1,'paid_amount'=>100,
            'active'=>$state==='suspended'?0:1,'expire_date'=>$state==='expired'?strtotime('-30 days'):strtotime('+30 days'),
            'date_added'=>strtotime('-60 days')]);
    }

    #[DataProvider('schoolStates')]
    public function test_school_dashboard_enforces_subscription_state(string $state,int $expected): void
    {
        $school=$this->makeSchool();$this->subscription($school,$state);
        $this->actingAs($this->makeAdminUser($school));
        $response=$this->get(route('admin.dashboard'));
        $response->assertStatus($expected);
        if($expected===302)$response->assertRedirect(route('admin.subscription'));
        $this->assertFalse(config('app.bypass_subscription'));
    }

    public function test_authorized_superadmin_keeps_dashboard_access_when_school_is_expired(): void
    {
        $school=$this->makeSchool();$this->subscription($school,'expired');
        $this->actingAs($this->makeSuperAdmin($school))->get(route('superadmin.dashboard'))->assertOk();
        $this->assertFalse(config('app.bypass_subscription'));
    }

    public function test_expired_school_cannot_enter_admissions_management(): void
    {
        $school=$this->makeSchool();$this->subscription($school,'expired');
        DB::table('global_settings')->insert(['key'=>'primary_school_id','value'=>(string)$school]);
        $this->actingAs($this->makeAdminUser($school))->get(route('admin.hei_admissions.index'))->assertRedirect(route('admin.subscription'));
        $this->assertFalse(config('app.bypass_subscription'));
    }

    public function test_direct_protected_get_and_post_urls_stop_before_controller_actions(): void
    {
        $school=$this->makeSchool();$this->subscription($school,'expired');
        DB::table('global_settings')->insert(['key'=>'primary_school_id','value'=>(string)$school]);
        $this->actingAs($this->makeAdminUser($school));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $before=DB::table('application_payments')->count();
        foreach(['admin.dashboard','admin.hei_admissions.index','admin.hei_admissions.payment.pesapal.settings','admin.leave.index'] as $route){
            $this->get(route($route))->assertRedirect(route('admin.subscription'));
        }
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.register'))->assertRedirect(route('admin.subscription'));
        $this->post(route('admin.leave.approve',['id'=>123]))->assertRedirect(route('admin.subscription'));
        $this->assertSame($before,DB::table('application_payments')->count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_calendar_day_grace_and_lifetime_subscriptions_remain_valid(): void
    {
        $school=$this->makeSchool();$this->actingAs($this->makeAdminUser($school));
        foreach([strtotime(date('Y-m-d')),0] as $expiry){
            DB::table('subscriptions')->where('school_id',$school)->update(['expire_date'=>$expiry]);
            $this->get(route('admin.dashboard'))->assertOk();
        }
        DB::table('subscriptions')->where('school_id',$school)->update(['expire_date'=>strtotime(date('Y-m-d'))-1]);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.subscription'));
    }

    public function test_status_and_active_must_describe_an_approved_subscription_in_the_same_school(): void
    {
        Schema::table('subscriptions',fn(Blueprint $table)=>$table->string('status')->default('1'));
        $school=$this->makeSchool();$foreign=$this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        DB::table('subscriptions')->where('school_id',$school)->update(['status'=>'0']);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.subscription'));
        DB::table('subscriptions')->where('school_id',$school)->update(['status'=>'1']);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(1,DB::table('subscriptions')->where('school_id',$foreign)->where('active',1)->count());
    }

    public function test_recovery_routes_continue_without_running_a_protected_action(): void
    {
        $school=$this->makeSchool();$this->subscription($school,'unpaid');$this->actingAs($this->makeAdminUser($school));
        $this->get(route('admin.subscription.purchase'))->assertOk();
        foreach(['admin.subscription','admin.subscription.payment','admin.subscription.offline_payment',
            'admin.subscription.upgrade_subscription','admin.subscription.marzpay.start','admin.subscription.marzpay.status',
            'admin_free_subcription','admin.admin_subscription_offline_payment'] as $name){
            $this->assertNull(\App\Support\Subscriptions\SchoolSubscriptionAccess::denial($school,$name));
        }
        $this->assertNotNull(\App\Support\Subscriptions\SchoolSubscriptionAccess::denial($school,'admin.hei_admissions.index'));
    }

    public function test_pending_renewal_does_not_remove_the_existing_approved_entitlement(): void
    {
        Schema::table('subscriptions',fn(Blueprint $table)=>$table->string('status')->default('1'));
        $school=$this->makeSchool();$this->actingAs($this->makeAdminUser($school));
        DB::table('subscriptions')->insert(['school_id'=>$school,'package_id'=>1,'paid_amount'=>0,
            'active'=>0,'status'=>'0','expire_date'=>strtotime('+60 days')]);
        $this->get(route('admin.dashboard'))->assertOk();
        // Approved trials/free plans remain valid: approval and expiry, not price, govern access.
        DB::table('subscriptions')->where('school_id',$school)->where('active',1)->update(['paid_amount'=>0]);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_all_school_admin_urls_have_an_enforcing_guard(): void
    {
        $unprotected=[];
        foreach(app('router')->getRoutes() as $route){
            if(!str_starts_with($route->uri(),'admin/')||$route->getName()==='admin.account_disableview')continue;
            $guards=array_map(fn($name)=>is_string($name)?explode(':',$name)[0]:null,$route->gatherMiddleware());
            if(!array_intersect(['admin','school_admin','school_subscription','superAdmin'],$guards))$unprotected[]=$route->uri();
        }
        $this->assertSame([],$unprotected,'School admin URL missing subscription-enforcing authorization');
    }

    public function test_missing_subscription_schema_never_runs_the_protected_action(): void
    {
        $school=$this->makeSchool();$this->actingAs($this->makeAdminUser($school));
        Schema::drop('subscriptions');
        $request=\Illuminate\Http\Request::create('/admin/dashboard');
        $request->setRouteResolver(fn()=>app('router')->getRoutes()->getByName('admin.dashboard'));
        $ran=false;
        $response=(new \App\Http\Middleware\EnsureSchoolSubscription())->handle($request,function()use(&$ran){$ran=true;return response('unsafe');});
        $this->assertSame(503,$response->getStatusCode());$this->assertFalse($ran);
    }

    public function test_expired_school_does_not_block_public_applicant_surfaces(): void
    {
        $school=$this->makeSchool(['status'=>1]);$this->subscription($school,'expired');
        DB::table('global_settings')->insert(['key'=>'primary_school_id','value'=>(string)$school]);
        $this->get('/apply')->assertOk();
        $this->get(route('applicant.register'))->assertOk();
        $this->get(route('applicant.login'))->assertOk();
        foreach(app('router')->getRoutes() as $route){
            if(!str_starts_with($route->getName()??'','applicant.'))continue;
            $guards=array_map(fn($name)=>is_string($name)?explode(':',$name)[0]:null,$route->gatherMiddleware());
            $this->assertSame([],array_values(array_intersect(['admin','school_admin','school_subscription'],$guards)));
        }
    }

    public function test_superadmin_login_and_management_remain_available_for_an_expired_school(): void
    {
        $school=$this->makeSchool(['status'=>0]);$this->subscription($school,'expired');
        $user=$this->makeSuperAdmin($school);$user->update(['password'=>Hash::make('isolated-fixture-password')]);
        $this->post('/login',['email'=>$user->email,'password'=>'isolated-fixture-password'])->assertRedirect(route('superadmin.dashboard'));
        $this->assertAuthenticatedAs($user);
        foreach(['superadmin.dashboard','superadmin.school.list','superadmin.subscription.report','superadmin.subscription.pending',
            'superadmin.subscription.expired_subcription','superadmin.package'] as $route)$this->get(route($route))->assertOk();
        $this->assertFalse(config('app.bypass_subscription'));
    }

    public function test_superadmin_activation_and_renewal_preserve_plan_and_user_identity(): void
    {
        $school=$this->makeSchool();$user=$this->makeSuperAdmin($school);
        DB::table('subscriptions')->where('school_id',$school)->delete();
        $package=DB::table('packages')->insertGetId(['name'=>'Fixture plan','price'=>100,'interval'=>'monthly','days'=>1,'status'=>1]);
        $plans=DB::table('packages')->get()->toJson();$users=DB::table('users')->get()->toJson();
        $this->actingAs($user);
        foreach([1,2] as $cycle){
            $id=DB::table('payment_history')->insertGetId(['school_id'=>$school,'user_id'=>$user->id,'package_id'=>$package,
                'amount'=>100,'status'=>'pending','paid_by'=>'offline']);
            $this->get(route('superadmin.subscription.status',['status'=>'approve','id'=>$id]))->assertRedirect();
            $this->assertSame('approve',DB::table('payment_history')->where('id',$id)->value('status'));
            $this->assertSame(1,DB::table('subscriptions')->where('school_id',$school)->where('active',1)->count());
            $this->assertGreaterThan(time(),DB::table('subscriptions')->where('school_id',$school)->where('active',1)->value('expire_date'));
            $this->assertSame($cycle,DB::table('subscriptions')->where('school_id',$school)->count());
        }
        $this->assertSame($plans,DB::table('packages')->get()->toJson());
        $this->assertSame($users,DB::table('users')->get()->toJson());
    }
}
