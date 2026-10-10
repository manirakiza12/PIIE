<?php
namespace Tests\Feature\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Real approved fixture entitlement for normal school management regression tests. */
trait ActiveSchoolSubscriptionFixture
{
    protected function grantActiveFixtureSubscription(int $school): void
    {
        if (!Schema::hasTable('packages')) Schema::create('packages',function(Blueprint $table){
            $table->id();$table->string('name');$table->float('price');$table->string('interval');$table->integer('days');$table->integer('status');$table->text('features')->default('[]');
        });
        if (!Schema::hasTable('subscriptions')) Schema::create('subscriptions',function(Blueprint $table){
            $table->id();$table->integer('school_id');$table->integer('package_id');$table->float('paid_amount');
            $table->integer('active');$table->integer('expire_date');$table->integer('date_added');
        });
        $package=DB::table('packages')->value('id') ?? DB::table('packages')->insertGetId([
            'name'=>'Fixture subscription','price'=>100,'interval'=>'monthly','days'=>1,'status'=>1]);
        $record=['school_id'=>$school,'package_id'=>$package,'paid_amount'=>100,'active'=>1,
            'expire_date'=>strtotime('+30 days'),'date_added'=>strtotime(date('Y-m-d'))];
        if(Schema::hasColumn('subscriptions','status'))$record['status']='1';
        DB::table('subscriptions')->insert($record);
    }
}
