<?php

/**
 * Backward-compatibility proof for the two Google migrations, on an ISOLATED
 * sqlite database. Your live database is never touched.
 *
 * What it checks, in order:
 *   1. a pre-migration `live_classes` table, populated, upgrades without data loss
 *   2. the new columns are nullable, so every existing row survives
 *   3. rollback removes exactly the two columns and nothing else
 *   4. re-running up() is safe (both migrations guard on hasColumn/hasTable)
 *   5. the connection table is created and dropped cleanly, and nothing else moves
 */

require __DIR__.'/../vendor/autoload.php';

if (! extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "ABORT: pdo_sqlite is not available; the probe cannot run in isolation.\n");
    exit(2);
}

// Fail closed: any uncaught error must produce a non-zero exit code.
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'PROBE ERROR: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations use the Schema facade, so the application has to be booted. It
 * is then pointed at an IN-MEMORY sqlite connection so the application's real
 * database - and its 49 live_classes rows - are never touched.
 */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'google_probe',
    'database.connections.google_probe' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ],
]);
DB::purge('google_probe');
DB::setDefaultConnection('google_probe');

// Safety interlock: refuse to continue unless the default is the in-memory sqlite DB.
if (DB::connection()->getName() !== 'google_probe'
    || DB::connection()->getDriverName() !== 'sqlite'
    || DB::connection()->getDatabaseName() !== ':memory:') {
    fwrite(STDERR, "ABORT: default connection is not the isolated in-memory sqlite database.\n");
    exit(2);
}

$schema = Schema::connection('google_probe');

/** The live_classes shape as it existed BEFORE this feature. */
$schema->create('live_classes', function (Blueprint $t): void {
    $t->id();
    $t->unsignedBigInteger('school_id');
    $t->string('title');
    $t->string('platform')->default('jitsi');
    $t->string('meeting_url', 500)->nullable();
    $t->string('meeting_id', 150)->nullable();
    $t->string('meeting_password', 150)->nullable();
    $t->dateTime('scheduled_at')->nullable();
    $t->dateTime('ends_at')->nullable();
    $t->string('status')->default('scheduled');
    $t->timestamps();
});

$before = [
    1 => ['title' => 'Existing Jitsi Class', 'platform' => 'jitsi', 'status' => 'scheduled'],
    2 => ['title' => 'Existing Zoom Class', 'platform' => 'zoom', 'status' => 'scheduled'],
    3 => ['title' => 'Existing Meet Class', 'platform' => 'google_meet', 'status' => 'scheduled'],
];
foreach ($before as $row) {
    DB::connection('google_probe')->table('live_classes')->insert($row + ['school_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    echo '  '.($ok ? 'PASS' : 'FAIL').'  '.$label."\n";
}

echo "1. UPGRADE AN EXISTING TABLE\n";
$m2 = require __DIR__.'/../database/migrations/2026_10_04_000002_add_google_calendar_fields_to_live_classes.php';
$m2->up();

check('google_calendar_event_id exists', $schema->hasColumn('live_classes', 'google_calendar_event_id'));
check('google_conference_status exists', $schema->hasColumn('live_classes', 'google_conference_status'));
check('pre-existing columns untouched', $schema->hasColumn('live_classes', 'meeting_url')
    && $schema->hasColumn('live_classes', 'platform') && $schema->hasColumn('live_classes', 'status'));
check('all 3 pre-existing rows survived', DB::connection('google_probe')->table('live_classes')->count() === 3);
check('existing rows have NULL google fields', DB::connection('google_probe')->table('live_classes')->whereNull('google_calendar_event_id')->whereNull('google_conference_status')->count() === 3);
check('no pre-existing data was rewritten', DB::connection('google_probe')->table('live_classes')->where('title', 'Existing Meet Class')->value('platform') === 'google_meet');
check('meeting_password still present', $schema->hasColumn('live_classes', 'meeting_password'));

$m1 = require __DIR__.'/../database/migrations/2026_10_04_000001_create_google_account_connections_table.php';
$m1->up();
check('google_account_connections created', $schema->hasTable('google_account_connections'));
check('live_classes unaffected by migration 1', DB::connection('google_probe')->table('live_classes')->count() === 3);

$db = DB::connection('google_probe');
$db->table('live_classes')->insert(['school_id' => 1, 'title' => 'New Meet Class', 'google_calendar_event_id' => 'evt_1', 'google_conference_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
$db->table('live_classes')->insert(['school_id' => 1, 'title' => 'New Jitsi Class (no google fields)', 'created_at' => now(), 'updated_at' => now()]);
check('google fields accept values and NULL', $db->table('live_classes')->where('google_calendar_event_id', 'evt_1')->count() === 1
    && $db->table('live_classes')->whereNull('google_calendar_event_id')->count() === 4);
$db->table('live_classes')->whereIn('title', ['New Meet Class', 'New Jitsi Class (no google fields)'])->delete();

echo "\n2. RE-RUN IS SAFE (idempotent guards)\n";
$m1->up();
$m2->up();
check('migration 1 re-run is a no-op', $schema->hasTable('google_account_connections'));
check('migration 2 re-run did not error', $schema->hasColumn('live_classes', 'google_calendar_event_id'));
check('rows still 3 after re-run', DB::connection('google_probe')->table('live_classes')->count() === 3);

echo "\n3. ROLLBACK\n";
$m2->down();
check('google_calendar_event_id removed', ! $schema->hasColumn('live_classes', 'google_calendar_event_id'));
check('google_conference_status removed', ! $schema->hasColumn('live_classes', 'google_conference_status'));
check('original columns restored intact', $schema->hasColumn('live_classes', 'meeting_url')
    && $schema->hasColumn('live_classes', 'meeting_password') && $schema->hasColumn('live_classes', 'scheduled_at'));
check('DATA SURVIVES ROLLBACK', DB::connection('google_probe')->table('live_classes')->count() === 3
    && DB::connection('google_probe')->table('live_classes')->where('title', 'Existing Jitsi Class')->count() === 1);

$m1->down();
check('google_account_connections dropped', ! $schema->hasTable('google_account_connections'));
check('live_classes table still exists after full rollback', $schema->hasTable('live_classes'));

echo "\n4. FORWARD AGAIN AFTER ROLLBACK\n";
$m2->up();
$m1->up();
check('re-applies cleanly', $schema->hasColumn('live_classes', 'google_calendar_event_id')
    && $schema->hasTable('google_account_connections'));
check('data still intact', DB::connection('google_probe')->table('live_classes')->count() === 3);

echo "\n".($failures === 0
    ? "ALL CHECKS PASSED\n"
    : $failures." CHECK(S) FAILED\n");

exit($failures === 0 ? 0 : 1);