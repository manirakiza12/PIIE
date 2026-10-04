<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Calendar bookkeeping on `live_classes`.
 *
 * ── WHY THIS IS ADDITIVE AND NULLABLE ───────────────────────────────────────
 *
 * Every existing row keeps working untouched: `platform` may be jitsi or zoom, in
 * which case these stay NULL forever and nothing reads them. That is why there is
 * no NOT NULL, no default and no backfill — a backfill would have to invent event
 * ids Google never issued, and a fabricated id is worse than an absent one
 * because it looks resolvable.
 *
 * ── WHY AN EVENT ID AT ALL ──────────────────────────────────────────────────
 *
 * `meeting_url` already holds the Meet link, so on its own the row is
 * "presentable". But editing or cancelling a class then has no way to reach the
 * calendar entry: the only handle is the URL, which Google will not accept as an
 * identifier for an update or a delete. Without the event id, "cancel class" and
 * "edit class" would leave orphans in every lecturer's real calendar — classes
 * PIIE believes it has cancelled, still sitting on Google with a live join link.
 *
 * That orphan is a security problem, not a tidiness one. The link is the only
 * thing a student needs to join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_classes', function (Blueprint $table): void {
            if (! Schema::hasColumn('live_classes', 'google_calendar_event_id')) {
                /**
                 * Google's own opaque event identifier. Nullable, and with NO index.
                 *
                 * The nullable part is load-bearing: every one of the 49 existing
                 * classes predates this column, and a NOT NULL column with no
                 * default would fail on them.
                 *
                 * There is deliberately no index here. An earlier draft added one,
                 * on the reasoning that cancelling and editing would look a class up
                 * by event id — but no query does that yet; the event id is only
                 * WRITTEN and read off the row already being edited. An index that
                 * serves no query costs writes on the table every lecturer touches,
                 * and it made `down()` fail: `dropColumn()` does not remove an index
                 * that depends on the column, which leaves a dangling definition on
                 * SQLite and is silently dropped on MySQL. The two drivers
                 * disagreeing about rollback is a worse problem than a missing index.
                 *
                 * When reconciliation is actually implemented, the index belongs in
                 * the same migration that introduces the lookup.
                 */
                $table->string('google_calendar_event_id')->nullable()->after('meeting_id');
            }

            if (! Schema::hasColumn('live_classes', 'google_conference_status')) {
                /**
                 * What Google told us about the conference, in its own words.
                 *
                 * 'pending' is a real and important state: Google creates Meet
                 * conferences asynchronously, so a freshly-created event can come
                 * back with no conference data yet. Treating that as success would
                 * publish a class with no join link; treating it as failure would
                 * discard a class that works a minute later. It is recorded, and
                 * PIIE shows the class as scheduled but not yet joinable.
                 *
                 * Nullable rather than defaulted, so "no Google involvement" and
                 * "Google has not answered yet" stay distinguishable.
                 */
                $table->string('google_conference_status', 32)->nullable()->after('google_calendar_event_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('live_classes', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('live_classes', 'google_conference_status')) {
                $columns[] = 'google_conference_status';
            }
            if (Schema::hasColumn('live_classes', 'google_calendar_event_id')) {
                $columns[] = 'google_calendar_event_id';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};