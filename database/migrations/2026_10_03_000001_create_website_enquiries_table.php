<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PUBLIC WEBSITE ENQUIRIES.
 *
 * ── WHY A NEW TABLE, GIVEN THE BRIEF ASKED ME TO LOOK FIRST ────────────────
 *
 * The brief said: "Inspect the existing application for a suitable enquiry
 * mechanism before introducing new database structures. If none exists, implement
 * a small dedicated enquiry module."
 *
 * I inspected every public-facing POST route and every candidate table
 * (`scripts/find-public-enquiry-target.php`, since removed). The result:
 *
 *   - the only PUBLIC submissions are the applicant account workflow —
 *     register, log in, apply, upload documents, pay. Every one of them requires
 *     an applicant account and belongs to the admissions pipeline. An "enquiry
 *     about a programme" is not an application, and forcing it into `applicants`
 *     would create accounts for people who never intend to apply.
 *   - `website_items`, `website_sections`, `website_pages` and `website_settings`
 *     are the CMS's own content tables. Writing visitor enquiries into them would
 *     mix untrusted public input into Super Admin's content, where it could be
 *     rendered on the public site.
 *   - there is no `contact_messages`, `inquiries` or `website_enquiries` table.
 *
 * So no suitable mechanism exists, and the brief's fallback applies.
 *
 * ── WHY THIS IS SAFE AND NARROW ─────────────────────────────────────────────
 *
 *   - Nothing existing is read or written by it. No CMS table, no applicant row,
 *     no academic record.
 *   - Nothing is ever rendered on the public site. There is no public "read" route
 *     of any kind; the only reader is the Super Admin inbox, behind `auth` +
 *     `superAdmin`. That satisfies "do not expose submitted enquiries publicly" by
 *     construction rather than by remembering to check.
 *   - `school_id` makes it tenant-scoped like every other table in this schema, so
 *     a second institution on this codebase cannot read the first's enquiries.
 *   - The visitor's IP and user agent are stored for spam triage. They are written
 *     once, never displayed on the public site, and are the only personal data
 *     beyond what the visitor typed into the form.
 *
 * Reversible by dropping one table. No existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_enquiries')) {
            return;
        }

        Schema::create('website_enquiries', function (Blueprint $table) {
            $table->id();

            // Tenant scope, matching every other table in this schema.
            $table->unsignedBigInteger('school_id')->nullable()->index();

            // ── What the visitor typed ───────────────────────────────────────
            // `name` is deliberately NOT `full_name`: `name` is a MySQL function
            // name and an unquoted column of that name is a portability trap.
            $table->string('name', 191);
            $table->string('email', 191);
            $table->string('phone', 64)->nullable();

            // Free-text subject rather than an enum. The set of subjects is an
            // institutional decision (Admissions, Programmes, Finance, HR...) and
            // guessing an enum would reject valid enquiries; the column is indexed
            // so an inbox can group by it, and a select on the form is presentation
            // only. Admin-authored suggestions are supplied by the view.
            $table->string('subject', 191);

            // 5000 characters is far more than any real enquiry and small enough
            // that the inbox never has to deal with an unbounded blob.
            $table->text('message');

            // ── Triage, not workflow ─────────────────────────────────────────
            // `status` is an inbox state only. It deliberately does NOT drive any
            // notification, assignment or reply: those need an institutional
            // decision about who answers what, and fabricating one would put an
            // unapproved process into a column.
            $table->enum('status', ['new', 'read', 'answered', 'spam'])
                ->default('new')
                ->index();

            // Honeypot: a real person never sees or fills this. A bot that fills
            // every field does, which is a cheap first filter with no user impact.
            // See PublicEnquiryController for how it is used.
            $table->string('honeypot', 191)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Who read or answered it, for the audit trail.
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            // The inbox's default ordering: newest first, and unread first within
            // a status filter.
            $table->index(['status', 'created_at'], 'website_enquiries_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_enquiries');
    }
};
