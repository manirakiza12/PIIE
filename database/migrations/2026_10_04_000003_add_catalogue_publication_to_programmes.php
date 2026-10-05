<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programme catalogue: an approved cover image, explicit price metadata, and a
 * first-class website publication state.
 *
 * ── WHY THE ACADEMIC PROGRAMME IS THE SOURCE OF TRUTH ──────────────────────
 *
 * The public marketing site reads `website_items`, and `programmes` is the
 * academic record. Until now nothing connected them, so a published programme
 * existed twice: once as an academic record nobody could see, and once as a CMS
 * row an administrator retyped by hand. Two copies of one qualification cannot
 * stay true, and the public one wins, because it is what an applicant reads.
 *
 * This migration gives `programmes` the three things the public catalogue needs
 * and the academic record did not have. It does NOT merge the two tables and it
 * does NOT touch `subjects` or `course_offerings`: a Programme is an academic
 * qualification, a Course Offering is one delivery of a Subject in a term, and
 * collapsing them would put a term's timetable on a prospectus page.
 *
 * ── EVERY COLUMN IS ADDITIVE, NULLABLE OR DEFAULTED ────────────────────────
 *
 * Existing rows keep working untouched, and `is_published` defaults to 0 so
 * nothing reaches the public site merely because this migration ran. That
 * matters: a schema change must never publish a catalogue as a side effect.
 *
 * ── PRICE METADATA IS MINIMAL AND EXPLICIT ─────────────────────────────────
 *
 * `programmes.tuition_fee` already exists and is NOT reused blindly. It is a
 * bare DECIMAL with no currency and no period, and the platform already carries
 * two other fee amounts with genuinely different meanings:
 *
 *   - `intake_sessions.application_fee` — a one-off charge to APPLY, per intake;
 *   - `student_fee_managers.amount`     — a student's INVOICED balance.
 *
 * So `tuition_fee` is treated as an unlabelled figure until an administrator
 * says what it is. `tuition_fee_basis` records that explicitly, and a basis of
 * NULL is a real state meaning "not yet stated" — which the UI must render as
 * unpriced rather than as zero.
 *
 * Currency is a nullable OVERRIDE, not a second source of truth. The platform
 * already has `schools.school_currency` + `currency_position`, exposed through
 * `TenantConfiguration::resolve()['currency']`, and a programme belongs to one
 * school. So the effective currency is the tenant's unless a programme
 * deliberately overrides it. Inventing a per-programme currency column that
 * competed with the tenant setting would create exactly the two-sources problem
 * this migration exists to remove.
 *
 * ── DUPLICATE PREVENTION ──────────────────────────────────────────────────
 *
 * `website_item_id` records the single CMS row this programme projects onto, and
 * `meta_json.programme_id` on that row records the reverse link. One programme
 * maps to AT MOST ONE item; the database enforces it, so a second publish of the
 * same programme updates the existing row instead of appending a twin.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * NO `Schema::hasTable('programmes')` GUARD HERE — DELIBERATELY.
         *
         * This migration used to open with:
         *
         *     if (! Schema::hasTable('programmes')) {
         *         throw new RuntimeException('programmes table does not exist; ...');
         *     }
         *
         * which looked like the right defensive move and broke the deployment.
         *
         * `migrate --pretend` puts the connection into pretend mode, where queries
         * are LOGGED instead of EXECUTED. Schema::hasTable() runs an
         * information_schema SELECT, receives no rows, and returns FALSE EVEN WHEN
         * THE TABLE EXISTS. Measured on MariaDB 10.6 against a database where
         * `programmes` was present:
         *
         *     Schema::hasTable('programmes')  inside pretend = false
         *     Schema::hasTable('programmes')  outside       = true
         *
         * So the guard always threw under `--pretend`, and
         * first-cutover.sh step 5 is:
         *
         *     PRETEND="$("$PHP" artisan migrate --pretend --force 2>&1)" \
         *         || die "migrate --pretend failed"
         *
         * which means the first cutover could never get past the migration-review
         * gate: it aborted before touching anything, every single time.
         *
         * Nothing is lost by dropping it. If `programmes` really is absent, the
         * Schema::table() call below fails on its own with MySQL 1146
         * "Base table or view not found" — still fail-loud, with a clearer message
         * than a hand-written one, and it points at the real cause.
         *
         * The per-column Schema::hasColumn() guards below are safe to keep. They
         * also return false under pretend, but a false negative there is
         * HARMLESS and in fact correct: it just means the DDL is printed, which is
         * exactly what `--pretend` is for. The asymmetry is the whole lesson — a
         * guard that skips work when a thing is absent survives pretend; a guard
         * that requires a thing to be present does not.
         */

        Schema::table('programmes', function (Blueprint $table): void {
            if (! Schema::hasColumn('programmes', 'cover_image_path')) {
                // The approved public cover. This is PUBLIC marketing material and
                // is served from public/, unlike a Course Offering cover which is
                // private and lives outside the web root. Both exist; they are
                // different objects with different audiences, and Decision 5
                // requires them to keep their own ownership.
                $table->string('cover_image_path', 255)->nullable();
                $table->string('cover_image_name', 191)->nullable();
                $table->string('cover_image_mime', 100)->nullable();
                $table->unsignedBigInteger('cover_image_size')->nullable();
                $table->dateTime('cover_image_updated_at')->nullable();
            }

            if (! Schema::hasColumn('programmes', 'tuition_currency')) {
                // NULL = inherit the tenant currency (schools.school_currency).
                $table->string('tuition_currency', 10)->nullable();
            }

            if (! Schema::hasColumn('programmes', 'tuition_fee_basis')) {
                // NULL = the administrator has not stated what tuition_fee means.
                // Deliberately a plain VARCHAR, not an enum: an enum would make
                // adding a basis a migration, and this value is display metadata.
                $table->string('tuition_fee_basis', 32)->nullable();
            }

            if (! Schema::hasColumn('programmes', 'is_published')) {
                // 0 = not on the public site. Separate from `is_active`, which
                // governs whether the programme is offered ACADEMICALLY (admission
                // portal, applicant eligibility). A programme can be taught and
                // not advertised; conflating the two makes marketing a
                // precondition of teaching.
                $table->tinyInteger('is_published')->default(0)->index();
            }

            if (! Schema::hasColumn('programmes', 'website_sort_order')) {
                // NULL = sort last, after every explicitly ordered programme.
                $table->integer('website_sort_order')->nullable();
            }

            if (! Schema::hasColumn('programmes', 'published_at')) {
                $table->dateTime('published_at')->nullable();
            }

            if (! Schema::hasColumn('programmes', 'website_item_id')) {
                // The single CMS row this programme projects onto. NOT a foreign
                // key on purpose: website_items is CMS-owned and an administrator
                // may delete an item by hand, and a hard FK would then block the
                // delete with a constraint error in a screen that has no business
                // knowing about academic programmes. The service clears this on
                // unpublish, and treats a dangling id as "not published".
                $table->unsignedBigInteger('website_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Column drops only. Any CMS row this migration caused to be published
        // is left in place: a rollback must not silently unpublish a live
        // catalogue, because that is a visible content change dressed as a
        // schema revert. Unpublishing is an explicit admin action.
        Schema::table('programmes', function (Blueprint $table): void {
            foreach ([
                'cover_image_path', 'cover_image_name', 'cover_image_mime',
                'cover_image_size', 'cover_image_updated_at',
                'tuition_currency', 'tuition_fee_basis',
                'is_published', 'website_sort_order', 'published_at',
                'website_item_id',
            ] as $column) {
                if (Schema::hasColumn('programmes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};