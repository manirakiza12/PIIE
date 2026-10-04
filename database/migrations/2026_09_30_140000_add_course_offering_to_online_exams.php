<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COURSE OFFERING AS THE ACADEMIC DELIVERY CONTEXT FOR ONLINE EXAMS.
 *
 * ── ONE COLUMN, DELIBERATELY ────────────────────────────────────────────────
 *
 * The existing Online Exam engine is complete and mature: ten versioned question
 * types, a Question Bank, automatic marking for objective types, a manual marking
 * queue, proctoring, publication readiness checks and an explicit Admin
 * publication gate for results. None of that is rebuilt here. The only thing it
 * lacked was a way to say WHICH COURSE OFFERING an assessment belongs to - it
 * could only be attached to the legacy Class/Section/Programme/Session graph.
 *
 * So this adds exactly one nullable, indexed foreign key and stops.
 *
 * ── WHY ACADEMIC YEAR AND PERIOD ARE NOT COLUMNS HERE ───────────────────────
 *
 * A Course Offering already owns `subject_id`, `academic_year_id` and
 * `academic_period_id`. Copying them onto the exam would create a second source of
 * truth that could disagree with the Offering the moment a department corrects a
 * date, and "which year is this exam in?" would then have two answers. The exam
 * reads them through the Offering.
 *
 * The same reasoning is why `class_id` stays NULL for a Course Offering exam. The
 * brief forbids faking higher-education delivery through the legacy Class/Section
 * relationships, and populating them would not merely be a placeholder - it would
 * silently enrol every student of that class into the assessment, bypassing the
 * confirmed Course Registration that is the only legitimate HEI eligibility test.
 * An Offering exam is scoped by its Offering, or it is not scoped at all.
 *
 * ── NULLABLE, ON PURPOSE ────────────────────────────────────────────────────
 *
 * Every one of the nine pre-existing exams is a legacy Class/Section assessment
 * and must keep working untouched. NULL therefore means "not a Course Offering
 * assessment" and that branch is left exactly as it is.
 *
 * No foreign key constraint: the existing `online_exams` table has none, and
 * adding one would make a Course Offering un-deletable while a historic exam
 * still referenced it. The tenant and existence rules are enforced in the
 * resolver, which is where every other Course Offering relation is checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('online_exams', 'course_offering_id')) {
            return;
        }

        Schema::table('online_exams', function (Blueprint $table) {
            $table->unsignedBigInteger('course_offering_id')
                ->nullable()
                ->after('session_id')
                ->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('online_exams', 'course_offering_id')) {
            return;
        }

        Schema::table('online_exams', function (Blueprint $table) {
            $table->dropIndex(['course_offering_id']);
            $table->dropColumn('course_offering_id');
        });
    }
};
