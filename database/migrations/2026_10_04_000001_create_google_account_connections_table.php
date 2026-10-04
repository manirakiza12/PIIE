<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own Google account connection, for creating Google Meet conferences.
 *
 * ── WHY A NEW TABLE AND NOT A COLUMN ON `users` ─────────────────────────────
 *
 * A connection is per-user and carries three columns (tokens, expiry, scopes),
 * holds a refresh token, and is something a user can revoke independently of
 * their PIIE account. That is a relationship, not an attribute. More importantly:
 * putting an encrypted token blob in the widest-read table in the application is
 * the wrong blast radius — every accidental `select *` from `users` anywhere in
 * the codebase would then be a query that touches a credential.
 *
 * One row per user. A lecturer reconnects by replacing their own row; there is no
 * history to keep, because a disconnected Google account has nothing to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_account_connections')) {
            return;
        }

        Schema::create('google_account_connections', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('user_id');

            /**
             * Tokens are stored through Laravel's `encrypted` cast, so the column
             * holds ciphertext produced with the app key. Both are `text`, not
             * `string`: a JWT-ish access token can exceed 255 characters, and
             * truncating a credential produces a confusing auth failure rather
             * than an obvious schema error.
             *
             * Column names say `access_token_ciphertext` so that reading this
             * table's raw rows cannot be mistaken for readable tokens, and so a
             * future migration that forgets the cast is visible in the schema.
             */
            $table->text('access_token_ciphertext')->nullable();
            $table->text('refresh_token_ciphertext')->nullable();

            $table->unsignedInteger('access_token_expires_at')->nullable();
            $table->string('scope', 500)->nullable();
            $table->string('token_type', 40)->nullable();

            // Which Google account this actually is, so the lecturer can see they
            // connected the right one. Not a credential, and shown deliberately.
            $table->string('google_email')->nullable();
            $table->string('google_name')->nullable();
            $table->string('google_subject_id', 191)->nullable();

            /**
             * Which Google calendar events are written to. Defaults to 'primary',
             * the connected account's own default calendar.
             */
            $table->string('calendar_id')->default('primary');

            /**
             * Why the connection stopped working, in Google's own terms where
             * possible: 'ok', 'revoked', 'invalid_grant', 'needs_reauth'.
             *
             * `needs_reauth` is the important one: a refresh token can be silently
             * withdrawn (password change, revoked consent, 7-day test-mode expiry)
             * and the only honest response is to tell the lecturer to reconnect
             * rather than to keep retrying and reporting a generic failure.
             */
            $table->string('status', 24)->default('ok');
            $table->text('status_detail')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            // One connection per user. `user_id` is NOT unique-declared at the
            // database level here on purpose: the users table is shared across
            // tenants and a global unique index on user_id is safe, so this simply
            // mirrors that identity. The service enforces one-per-user and upserts
            // on it, and the unique index is what makes that safe under a
            // double-submitted callback.
            $table->unique('user_id');
            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_account_connections');
    }
};