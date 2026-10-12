<?php

/**
 * WHAT GATING WOULD ACTUALLY DO, PER COURSE OFFERING.
 *
 * Read-only. Run it before deciding whether to switch progression gating on.
 */

// This file lives in scripts/, so the application root is one level up.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CourseOffering;
use App\Models\CourseOfferingModule;
use Illuminate\Support\Facades\DB;

$offers = CourseOffering::query()->orderBy('id')->get();

printf("%-4s %-34s %8s %8s %7s %6s %6s %6s %8s %8s%s",
    'id', 'subject', 'students', 'modules', 'gated', 'tasks', 'thresh', 'reqd', 'attempts', 'progress', PHP_EOL);

foreach ($offers as $o) {
    $students = DB::table('course_registrations')
        ->where('course_offering_id', $o->id)->where('status', 'confirmed')->count();

    $modules = CourseOfferingModule::query()
        ->where('course_offering_id', $o->id)
        ->where('status', '!=', CourseOfferingModule::STATUS_ARCHIVED)->count();

    // Gating is a property of the COURSE OFFERING, not of each module: one switch,
    // so a lecturer cannot half-gate a course without meaning to.
    $gated = $o->gate_progression ? 'ON' : 'off';

    // A threshold is what makes "finished" mean "passed" rather than "handed in".
    $tasks = DB::table('assignments')->where('course_offering_id', $o->id)->count();
    $thresh = DB::table('assignments')
        ->where('course_offering_id', $o->id)->whereNotNull('pass_mark')->count();
    $reqd = DB::table('assignments')
        ->where('course_offering_id', $o->id)->where('requirement_role', 'required')->count();

    $attempts = DB::table('assignment_submissions')
        ->where('course_offering_id', $o->id)->where('is_draft', false)->count();

    printf("%-4d %-34s %8d %8d %7s %6d %6d %6d %8d %8s%s",
        $o->id,
        substr((string) ($o->subject?->name ?? $o->reference), 0, 33),
        $students, $modules, $gated, $tasks, $thresh, $reqd, $attempts,
        $o->starts_at ? substr((string) $o->starts_at, 0, 10) : '-',
        PHP_EOL);
}

echo PHP_EOL;
echo 'READING THE COLUMNS'.PHP_EOL;
echo '  gated   modules with progression gating switched on'.PHP_EOL;
echo '  thresh  tasks carrying a pass mark (a task without one cannot gate on a PASS)'.PHP_EOL;
echo '  reqd    tasks marked REQUIRED (only these can hold a student back)'.PHP_EOL;
echo '  attempts real attempts handed in - the work students would be unable to'.PHP_EOL;
echo '            reach, or would have to redo, if a gate were closed on them.'.PHP_EOL;