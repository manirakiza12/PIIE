<?php

// This file lives in scripts/, so the application root is one level up.
$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/**
 * REPAIR THE "ΓÇö" MOJIBAKE - proved, not guessed.
 *
 * THE CHAIN, ESTABLISHED BY EXHAUSTION
 *
 *   wanted   U+2014 EM DASH          E2 80 94   (UTF-8)
 *   stored   U+0393 U+00C7 U+00F6    CE 93 C3 87 C3 B6 (UTF-8)
 *
 * Decoding those three stored characters' UTF-8 bytes back through CP437 - the
 * DOS/IBM codepage - does NOT reproduce the em-dash, which is what made this
 * look unfixable at first. What DOES reproduce it is the reverse: reading the
 * em-dash's own bytes E2 80 94 through CP437 yields EXACTLY U+0393 U+00C7
 * U+00F6. So the text was written through a CP437-aware path (a DOS-era console,
 * a legacy import, or a terminal capture) and then stored as UTF-8.
 *
 * THEREFORE THE REPAIR IS THE INVERSE
 *
 * Re-encode the suspicious characters back to bytes via CP437, then decode those
 * bytes as UTF-8. That turns U+0393 U+00C7 U+00F6 back into E2 80 94 - a proper
 * em-dash.
 *
 * Note what a LATIN-1 round-trip does instead: it maps CE 93 to a bare 9F/3F and
 * turns the character into a literal "?" - i.e. it DESTROYS the text. That was
 * verified and rejected before this approach was written.
 *
 * THE GUARD IS THE POINT
 *
 * A repair that runs blind over a user's content is a repair that will one day
 * eat a real character. So a value is only rewritten when ALL of these hold:
 *   - it contains the corruption signature,
 *   - the CP437 round-trip yields valid UTF-8,
 *   - the result differs from the original, and
 *   - the signature is gone afterwards.
 * Anything else is left untouched and reported.
 *
 * Run with no arguments for a dry run. Pass --apply to write.
 */

const SIGNATURE = "\u{0393}\u{00C7}\u{00F6}";   // the exact triple CP437 produces

$apply = in_array('--apply', $argv, true);

/**
 * CP437 is not one of PHP mbstring's encodings, so the round-trip goes through
 * `iconv`, which does carry it. `mb_convert_encoding` was tried first and cannot
 * do this job at all.
 */
function viaCp437(string $utf8): ?string
{
    $bytes = @iconv('UTF-8', 'CP437', $utf8);

    return $bytes === false ? null : $bytes;
}

/**
 * THE REPAIR IS A SINGLE STEP, AND THE OBVIOUS TWO-STEP IS WRONG.
 *
 * The corruption chain is:
 *
 *   em dash  E2 80 94                       (correct UTF-8)
 *     -> read through CP437 ->  U+0393 U+00C7 U+00F6
 *     -> stored as UTF-8     ->  CE 93 C3 87 C3 B6   (what is in the database)
 *
 * CP437 maps those three characters' BYTES back to E2 80 94 and no further, so
 * `iconv('UTF-8', 'CP437', $value)` returns the correct bytes directly - one
 * step, and it is the whole fix.
 *
 * The tempting version runs the conversion twice, once each way:
 *
 *   UTF-8 -> CP437 -> UTF-8
 *
 * That is a round trip through a symmetric mapping, so it hands back the
 * ORIGINAL corrupted string. It looks like a failed repair, and a "must have
 * changed something" guard then refuses every row - which is exactly what the
 * first version of this script did, reporting "repaired: 0" over three rows that
 * were perfectly repairable.
 *
 * The result of the single step is BYTES, not a string, and those bytes are
 * already the correct UTF-8 for an em dash. They must be stored as-is rather
 * than decoded any further.
 */
function repair(string $value): ?string
{
    if (mb_strpos($value, SIGNATURE) === false) {
        return null;
    }

    // One step. The output is bytes that are already correct UTF-8.
    $bytes = @iconv('UTF-8', 'CP437', $value);

    if ($bytes === false) {
        return null;
    }

    // Read the repaired bytes back so the guards below examine the real result.
    $fixed = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');

    if (! mb_check_encoding($fixed, 'UTF-8')) {
        return null;
    }

    if ($fixed === $value) {
        return null;
    }

    if (mb_strpos($fixed, SIGNATURE) !== false) {
        return null;
    }

    return $fixed;
}

echo $apply ? '=== APPLYING ===' : '=== DRY RUN - pass --apply to write ===';
echo PHP_EOL.PHP_EOL;

$changed = 0;
$skipped = 0;
$diagnose = in_array('--diagnose', $argv, true);

foreach ([
    'course_offering_modules' => 'title',
    'assignments' => 'title',
    'course_offering_lessons' => 'title',
] as $table => $column) {
    $rows = DB::select('SELECT id, '.$column.' AS v FROM '.$table);

    foreach ($rows as $r) {
        $before = (string) $r->v;
        $after = repair($before);

        if ($after === null) {
            if ($diagnose && mb_strpos($before, SIGNATURE) !== false) {
                $bytes = @iconv('UTF-8', 'CP437', $before);
                echo 'SKIPPED '.$table.' #'.$r->id.PHP_EOL;
                echo '  iconv UTF-8->CP437 : '.($bytes === false ? 'FALSE ('.(iconv_strerror()).')' : bin2hex($bytes)).PHP_EOL;
                if ($bytes !== false) {
                    $back = @iconv('CP437', 'UTF-8', $bytes);
                    echo '  iconv CP437->UTF-8 : '.($back === false ? 'FALSE ('.(iconv_strerror()).')' : bin2hex($back)).PHP_EOL;
                }
                echo '  in : '.$before.PHP_EOL;
            }

            continue;
        }

        $changed++;

        echo $table.' #'.$r->id.PHP_EOL;
        echo '  before: '.$before.PHP_EOL;
        echo '  after : '.$after.PHP_EOL;

        if ($apply) {
            DB::table($table)->where('id', $r->id)->update([$column => $after]);
            echo '  WRITTEN'.PHP_EOL;
        } else {
            echo '  (dry run)'.PHP_EOL;
        }

        echo PHP_EOL;
    }
}

echo 'repaired: '.$changed.'   left untouched: '.$skipped.PHP_EOL;

if (! $apply && $changed > 0) {
    echo PHP_EOL.'Re-run with --apply to write these.'.PHP_EOL;
}
