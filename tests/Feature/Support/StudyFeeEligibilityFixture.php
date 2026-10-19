<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Learning tests enter after financial clearance; fee gate tests seed their own unpaid cases. */
trait StudyFeeEligibilityFixture
{
    protected function grantStudyEligibilityFixture(User $student): void
    {
        if (! Schema::hasColumn('schools', 'running_session')) {
            Schema::table('schools', fn (Blueprint $table) => $table->unsignedBigInteger('running_session')->default(1));
        }
        if (! Schema::hasTable('student_fee_managers')) {
            Schema::create('student_fee_managers', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('session_id'); $table->decimal('total_amount', 12, 2);
                $table->decimal('paid_amount', 12, 2); $table->string('status');
            });
        }
        $session = DB::table('schools')->where('id', $student->school_id)->value('running_session') ?: 1;
        DB::table('schools')->where('id', $student->school_id)->update(['running_session' => $session]);
        $values = ['school_id' => $student->school_id, 'student_id' => $student->id, 'session_id' => $session,
            'total_amount' => 100, 'paid_amount' => 20, 'status' => 'unpaid'];
        foreach (['title' => 'Current-session tuition', 'class_id' => 0, 'payment_method' => 'pesapal'] as $column => $value) {
            if (Schema::hasColumn('student_fee_managers', $column)) { $values[$column] = $value; }
        }
        DB::table('student_fee_managers')->insert($values);
    }
}
