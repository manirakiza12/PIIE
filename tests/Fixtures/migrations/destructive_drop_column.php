<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // A column removal, even when it is written on one line.
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('legacy_flag');
        });
    }

    public function down()
    {
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('legacy_flag')->default(false);
        });
    }
};
