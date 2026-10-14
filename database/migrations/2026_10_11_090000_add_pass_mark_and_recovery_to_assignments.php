<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-Offering Assignments: a lecturer-set pass mark, and the per-student
 * recovery record that "three attempts then rest" needs.
 *
 * WHY PASS MARK COMES FIRST
 *
 * Nothing can be gated on a threshold that does not exist. Moodle's "Restrict
 * access by grade" and Canvas's "Module Requirements" both refuse to configure a
 * prerequisite without a minimum score to compare against. Sequencing the
 * progression engine ahead of this column would mean gating on "a mark exists",
 * which is not what "passed" means.
 *
 * IT ALSO FIXES A LIVE DEFECT
 *
 * `Assignment::RULE_RELEASED_MARK` is satisfied purely by `marks_released_at`
 * being set - `ModuleCompletion::assessmentRow()` reads the state as 'returned'
 * and stops there. So a lecturer who released a mark of 0 out of 100 satisfied a
 * REQUIRED task and completed the module. There was no threshold anywhere in the
 * assignment path. `pass_mark` introduces one; null keeps the old behaviour, so
 * every existing assignment is untouched.
 *
 * WHY THE RECOVERY COLUMNS ARE HERE ALREADY
 *
 * The 48-hour rest period cannot be bolted on afterwards without a second pass
 * over the same table and a second migration to reason about. `attempts_cycle`
 * and `locked_until` are the entire state the feature needs, they are additive
 * and nullable, and a lecturer-set `retry_policy` decides whether a student
 * retries automatically forever or needs a fresh authorisation each cycle. They
 * are inert until Phase 4 reads them; adding them now means the schema is
 * settled before the progression engine depends on it.
 *
 * WHY THE GATE IS NOT IN THIS FILE
 *
 * `course_offerings.gate_progression` - the switch that makes a threshold
 * enforceable - is added by `2026_10_11_091000_add_progression_gate_to_course_offerings`
 * instead. Folding it in here would mean editing an already-applied migration the
 * first time either concern changed, and an edited migration that is already
 * recorded produces silent drift between the file and the database.
 *
 * @see \App\Support\Assignments\AssignmentOutcome for the shared outcome vocabulary
 * @see \App\Support\CourseContent\ProgressionGate for the restriction itself
 *
 * ADDITIVE AND FORWARD-ONLY
 *
 * Every column is nullable or defaulted. No existing row is rewritten, nothing
 * is dropped, and no existing query changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            // The minimum a RELEASED mark must reach for this assignment to count
            // as passed. Nullable on purpose: null means "no threshold", which is
            // how every assignment that exists today behaves.
            $table->decimal('pass_mark', 8, 2)->unsigned()->nullable();

            // Whether a student who has spent every attempt in a cycle may begin
            // another one without a lecturer authorising it.
            //   'automatic' - retry as soon as the rest period expires (Moodle's
            //                 default for a quiz with limited attempts)
            //   'approval'  - a lecturer must authorise the next cycle
            // A lecturer who sets a pass mark is choosing to police this
            // assessment, so the default is the stricter of the two.
            $table->string('retry_policy', 20)->default('approval');

            // How many rest periods a student has served on this assignment. Lets
            // a lecturer see that a student is cycling rather than stuck, and
            // gives Phase 4 an escalation hook without another migration.
            $table->unsignedSmallInteger('attempts_cycle')->default(1);

            // When the current rest period expires. Null means not resting.
            $table->timestamp('locked_until')->nullable();
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            // Which attempt cycle this attempt belonged to, so "three attempts"
            // can be scoped to one cycle rather than to the student's whole
            // history. Defaults to 1, which is exactly how every existing row
            // reads today.
            $table->unsignedSmallInteger('attempt_cycle')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropColumn('attempt_cycle');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['pass_mark', 'retry_policy', 'attempts_cycle', 'locked_until']);
        });
    }
};
