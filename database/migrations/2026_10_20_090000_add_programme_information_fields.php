<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table): void {
            $table->longText('description')->nullable();
            $table->longText('curriculum_overview')->nullable();
            $table->longText('career_prospects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('programmes', function (Blueprint $table): void {
            $table->dropColumn(['description', 'curriculum_overview', 'career_prospects']);
        });
    }
};
