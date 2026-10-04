<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Catches Blade templates that will not compile — the defect that made
 * GET /admin/programme-cohorts/1 return HTTP 500 for every visitor.
 *
 * The specific failure was `resources/views/admin/programme_cohorts/show.blade.php`
 * containing "eligible students@if(...)". Blade's statement pattern starts with
 * \B@, so a directive glued to a preceding WORD character is not recognised and
 * is emitted as literal text, while its matching @endif (preceded by a
 * non-word character) still compiles. The stray `endif` closed the enclosing
 * @if early and orphaned the following @elseif, producing
 * "syntax error, unexpected token elseif, expecting end of file" at RENDER time
 * — a compile error, so it failed for all data, not just incomplete records.
 *
 * Two layers of protection:
 *  1. no Blade directive may be glued to a preceding word character;
 *  2. every template must compile to syntactically valid PHP.
 */
class BladeTemplateIntegrityTest extends TestCase
{
    /** Directives Blade recognises; any of them, glued to a word character, is a bug. */
    private const DIRECTIVES = 'if|elseif|else|unless|isset|empty|foreach|forelse|endforeach|for|endfor|while|endwhile|switch|endswitch|case|default|break|continue|can|canany|cannot|auth|user|json|method|csrf|include|includeWhen|includeIf|extends|section|endsection|yield|yieldOnce|once|push|pushOnce|stack|stackOnce|error|errors|old|selected|checked|disabled|readonly|required|class|style|props|aware|key|value|dd|dump|className|doubleEncode|production|component|slot|use';

    /**
     * Pre-existing, known-broken templates, recorded rather than silently fixed.
     * Each is a genuine 500 when rendered and needs its own decision; this test
     * exists to stop NEW breakage, so the debt is pinned explicitly. Remove an
     * entry only when the template is actually repaired.
     *
     * teacher/attendance/attendance_list.blade.php writes `new CommonController()->...`
     * directly in a Blade file, which is invalid PHP — an instantiating
     * expression needs parentheses: `(new CommonController)->...`. Unrelated to
     * the Programme Cohort work and out of its scope.
     */
    private const KNOWN_UNCOMPILABLE = [
        'teacher/attendance/attendance_list.blade.php',
    ];

    /** Always forward-slashed, so the KNOWN_UNCOMPILABLE list is platform-independent. */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path));
    }

    /** Blade comments are stripped at compile time, so their text can never break anything. */
    private function stripBladeComments(string $source): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    private function bladeFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function test_no_blade_directive_is_glued_to_a_preceding_word_character(): void
    {
        // \B@ means the directive is silently skipped when the character before
        // the @ is a word character. The @endif still compiles, so the enclosing
        // chain is closed early and a later @elseif becomes a parse error.
        $pattern = '/[A-Za-z0-9_]@('.self::DIRECTIVES.')\b/';

        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            foreach (explode("\n", $this->stripBladeComments(file_get_contents($path))) as $number => $line) {
                if (preg_match($pattern, $line, $matches)) {
                    $prefix = substr($matches[0], 0, strlen($matches[0]) - strlen($matches[1]) - 1);
                    $offenders[] = sprintf(
                        '%s:%d -> @%s glued to %s',
                        $this->relative($path),
                        $number + 1,
                        $matches[1],
                        var_export($prefix, true)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A Blade directive glued to a word character is not compiled, while its @endif is.\n"
            ."This orphans the next @elseif and 500s the page. Fix by putting the\n"
            ."directive at the start of a line or after whitespace:\n  - ".implode("\n  - ", $offenders)
        );
    }

    public function test_every_blade_template_compiles_to_syntactically_valid_php(): void
    {
        $failures = [];

        foreach ($this->bladeFiles() as $path) {
            if (in_array($this->relative($path), self::KNOWN_UNCOMPILABLE, true)) {
                continue;
            }

            $compiled = Blade::compileString(file_get_contents($path));

            // TOKEN_PARSE throws a ParseError on invalid PHP, which is exactly
            // the "unexpected token elseif" class of failure, without needing a
            // subprocess.
            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $error) {
                $failures[] = sprintf(
                    '%s -> %s (line %d)',
                    $this->relative($path),
                    $error->getMessage(),
                    $error->getLine()
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            "These Blade templates do not compile and will 500 when rendered:\n  - ".implode("\n  - ", $failures)
        );
    }

    public function test_the_programme_cohort_detail_template_compiles_and_has_no_leftover_directives(): void
    {
        $source = file_get_contents(resource_path('views/admin/programme_cohorts/show.blade.php'));
        $compiled = Blade::compileString($source);

        // The exact regression: a literal @if( surviving compilation means an
        // unbalanced chain, and the surplus @endif that follows it is what
        // orphans the next @elseif.
        $this->assertSame(0, preg_match_all('/@if\(/', $compiled), 'no literal @if( may survive compilation');
        $this->assertSame(0, preg_match_all('/@elseif\(/', $compiled), 'no literal @elseif( may survive compilation');

        token_get_all($compiled, TOKEN_PARSE);
        $this->assertTrue(true, 'the Programme Cohort detail template compiles to valid PHP');
    }
}
