<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->decimal('application_fee_amount', 12, 2)->nullable();
            $table->string('application_fee_currency', 10)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn(['application_fee_amount', 'application_fee_currency']));
    }
};
