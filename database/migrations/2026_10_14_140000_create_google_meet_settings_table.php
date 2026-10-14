<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('google_meet_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->string('calendar_id')->default('primary');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('platform_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        if (Schema::hasTable('live_classes')) {
            Schema::table('live_classes', function (Blueprint $table) {
                $table->unsignedBigInteger('google_account_owner_id')->nullable();
                $table->string('google_calendar_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_meet_settings');
        if (Schema::hasColumn('live_classes', 'google_account_owner_id')) Schema::table('live_classes', fn (Blueprint $table) => $table->dropColumn(['google_account_owner_id', 'google_calendar_id']));
    }
};
