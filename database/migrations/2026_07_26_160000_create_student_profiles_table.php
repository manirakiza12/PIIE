<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds student fields that have no existing authoritative column
     * elsewhere. Deliberately does NOT duplicate: registration number
     * (users.code), name (users.name), email (users.email),
     * phone/gender/dob/address/photo (users.user_information JSON), or portal
     * password (users.password). Historical JSON keys remain untouched.
     */
    public function up()
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('intake_session_id')->nullable();
            $table->unsignedTinyInteger('year_of_study')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('national_id_or_passport', 50)->nullable();
            $table->text('next_of_kin_address')->nullable();
            $table->string('next_of_kin_contact', 30)->nullable();
            $table->string('additional_image')->nullable();
            $table->enum('status', ['active', 'suspended', 'graduated', 'withdrawn', 'deferred'])->default('active');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('programme_id')->references('id')->on('programmes')->onDelete('set null');
            $table->foreign('intake_session_id')->references('id')->on('intake_sessions')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('student_profiles');
    }
};
