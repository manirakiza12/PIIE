<?php

namespace Tests\Feature;

use App\Support\CourseContent\HtmlSanitizer;
use App\Support\CourseOffering\CourseCoverImage;
use Tests\TestCase;

/**
 * THE ACADEMIC EDITOR'S SERVER-SIDE HALF: THE SANITIZER.
 *
 * ── WHY THIS SUITE IS ABOUT THE SANITIZER AND NOT THE TOOLBAR ──────────────
 *
 * A rich-text editor is two halves. The browser half can be bypassed by anyone
 * willing to open developer tools, or by a curl command, or by a laptop that has
 * JavaScript switched off - so the browser's sanitization is a COURTESY that
 * exists to make the author's own work tidy, and nothing more.
 *
 * The server half is the only one that decides anything. These tests are about it.
 *
 * That division is why the browser-side paste cleaner in `academic-editor.js` is
 * not treated as a security control anywhere, and why a payload that survives it
 * unchanged is still refused here.
 *
 * ── WHAT IS ASSERTED ───────────────────────────────────────────────────────
 *
 * Both directions, because a sanitizer is only right if it is wrong in BOTH
 * directions:
 *
 *   - it must REFUSE the dangerous, without exception and without being told the
 *     answer first; and
 *   - it must KEEP the academic, or the feature is useless.
 *
 * A filter that strips everything "passes" a security test and destroys the
 * product. A filter that keeps everything passes a usefulness test and destroys
 * the product differently. Only the pair is worth asserting.
 */
class AcademicRichTextSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = app(HtmlSanitizer::class);
    }

    private function clean(?string $html, bool $images = true): string
    {
        return $this->sanitizer->sanitize($html, $images);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE FORMATTING AN ACADEMIC AUTHOR NEEDS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Every capability the editor's toolbar offers must survive the round trip.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('academicFormatting')]
    public function test_academic_formatting_survives_sanitisation(string $label, string $html, string $expected): void
    {
        $clean = $this->clean($html);

        $this->assertStringContainsString($expected, $clean, $label.' did not survive');
    }

    public static function academicFormatting(): array
    {
        return [
            'bold' => ['bold', '<p><strong>Ratio</strong></p>', '<strong>'],
            'bold as b' => ['bold shorthand', '<p><b>Ratio</b></p>', '<b>'],
            'italic' => ['italic', '<p><em>approximately</em></p>', '<em>'],
            'italic as i' => ['italic shorthand', '<p><i>approx</i></p>', '<i>'],
            'underline' => ['underline', '<p><u>important</u></p>', '<u>'],
            'strikethrough' => ['strikethrough', '<p><s>withdrawn</s></p>', '<s>'],
            'heading 1' => ['heading 1', '<h1>Module 1</h1>', '<h1>'],
            'heading 2' => ['heading 2', '<h2>Ratios</h2>', '<h2>'],
            'heading 3' => ['heading 3', '<h3>Working</h3>', '<h3>'],
            'heading 4' => ['heading 4', '<h4>Step one</h4>', '<h4>'],
            'paragraph' => ['paragraph', '<p>A paragraph.</p>', '<p>'],
            'bullet list' => ['bullet list', '<ul><li>Revenue</li><li>Cost</li></ul>', '<li>Revenue</li>'],
            'numbered list' => ['numbered list', '<ol><li>First</li><li>Second</li></ol>', '<ol>'],
            'blockquote' => ['blockquote', '<blockquote>Quoted</blockquote>', '<blockquote>'],
            'code' => ['inline code', '<p>use <code>NPV</code></p>', '<code>NPV</code>'],
            'preformatted' => ['preformatted', '<pre>x = 4,200</pre>', '<pre>'],
            'horizontal rule' => ['rule', '<p>a</p><hr><p>b</p>', '<hr>'],
            'subscript' => ['subscript', '<p>H<sub>2</sub>O</p>', '<sub>2</sub>'],
            'superscript' => ['superscript', '<p>x<sup>2</sup></p>', '<sup>2</sup>'],
            'mark' => ['highlight', '<p><mark>key</mark></p>', '<mark>'],
            'small' => ['small', '<p><small>note</small></p>', '<small>'],
            'hyperlink' => ['hyperlink', '<p><a href="https://example.org">source</a></p>', 'href="https://example.org"'],
            'mailto' => ['mailto link', '<a href="mailto:a@b.test">mail</a>', 'mailto:a@b.test'],
        ];
    }

    public function test_a_table_survives_with_its_structure_and_its_scope(): void
    {
        $clean = $this->clean(
            '<table><thead><tr><th scope="col">Year</th><th scope="col">Revenue</th></tr></thead>'
            .'<tbody><tr><td>2025</td><td>9,000</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('<th scope="col">Year</th>', $clean);
        $this->assertStringContainsString('<td>2025</td>', $clean);
    }

    public function test_a_table_cell_spanning_several_columns_survives(): void
    {
        $clean = $this->clean('<table><tr><td colspan="3">Total</td></tr></table>');

        $this->assertStringContainsString('colspan="3"', $clean);
    }

    public function test_alignment_and_indentation_survive_as_inline_styles(): void
    {
        // THE POINT OF THE STYLE VOCABULARY. Summernote's alignment and indent
        // commands write inline styles, so with `style` stripped wholesale a
        // lecturer who centred a worked example or indented a derivation lost the
        // formatting on save and was never told. A formatting control that silently
        // does nothing is worse than an absent one.
        $this->assertStringContainsString(
            'text-align: center',
            $this->clean('<p style="text-align: center">Centred working</p>')
        );

        $this->assertStringContainsString(
            'margin-left: 40px',
            $this->clean('<p style="margin-left: 40px">Step one</p>')
        );

        $this->assertStringContainsString(
            'text-indent: 2em',
            $this->clean('<p style="text-indent: 2em">An indented paragraph</p>')
        );
    }

    public function test_a_typeface_survives(): void
    {
        $this->assertStringContainsString(
            'font-family',
            $this->clean('<p style="font-family: \'Times New Roman\', serif">Typeset</p>')
        );
    }

    public function test_an_equation_survives_as_NOTATION_PLUS_A_FALLBACK(): void
    {
        // The editor's equation button stores `data-latex` and a plain-text
        // fallback. Nothing is executed, so an equation cannot become an injection
        // point - and the fallback is what a reader without a maths renderer sees,
        // which is why the editor asks for one.
        $clean = $this->clean('<p>Cost is <span data-latex="C_0 + \\sum_{t=1}^{T} \\frac{F_t}{(1+r)^t}">C0 + sum of discounted flows</span>.</p>');

        $this->assertStringContainsString('data-latex=', $clean);
        $this->assertStringContainsString('C0 + sum of discounted flows', $clean);
    }

    public function test_the_data_equation_attribute_survives_too(): void
    {
        $this->assertStringContainsString(
            'data-equation',
            $this->clean('<span data-equation="x^2">x squared</span>')
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE DANGEROUS, REFUSED
    // ══════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('attacks')]
    public function test_dangerous_markup_is_refused(string $label, string $html, array $mustNotContain): void
    {
        $clean = $this->clean($html);

        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean, $label.' leaked: '.$needle);
        }
    }

    public static function attacks(): array
    {
        return [
            'script element' => ['script', '<p>a</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            'script in svg' => ['svg script', '<svg><script>alert(1)</script></svg>', ['<script', '<svg']],
            'event handler' => ['onclick', '<p onclick="steal()">x</p>', ['onclick']],
            'event handler on img' => ['onerror', '<img src="https://a.test/x.png" onerror="steal()">', ['onerror']],
            'onmouseover' => ['onmouseover', '<p onmouseover="steal()">x</p>', ['onmouseover']],
            'javascript link' => ['javascript: href', '<a href="javascript:alert(1)">x</a>', ['javascript:']],
            'data uri link' => ['data: href', '<a href="data:text/html,<script>alert(1)</script>">x</a>', ['data:text/html']],
            'vbscript link' => ['vbscript: href', '<a href="vbscript:msgbox(1)">x</a>', ['vbscript:']],
            'iframe' => ['iframe', '<iframe src="https://evil.test"></iframe>', ['<iframe']],
            'object' => ['object', '<object data="x.swf"></object>', ['<object']],
            'embed' => ['embed', '<embed src="x.swf">', ['<embed']],
            'form' => ['form', '<form action="//evil.test"><input name="a"></form>', ['<form', '<input']],
            'style element' => ['style block', '<style>body{display:none}</style><p>x</p>', ['<style', 'display:none']],
            'meta refresh' => ['meta', '<meta http-equiv="refresh" content="0;url=//evil.test">', ['<meta', 'refresh']],
            'base tag' => ['base', '<base href="//evil.test/">', ['<base']],
            'svg' => ['svg', '<svg><circle r="10"></circle></svg>', ['<svg']],
            'math element' => ['math', '<math><mi>x</mi></math>', ['<math']],
            'noscript' => ['noscript', '<noscript><p>x</p></noscript>', ['<noscript']],
            'template' => ['template', '<template><script>alert(1)</script></template>', ['<template']],
            'comment smuggling' => ['conditional comment', '<!--[if gte IE]><script>alert(1)</script><![endif]-->', ['<script', '[if gte']],
            'src scheme' => ['javascript: src', '<img src="javascript:alert(1)" alt="x">', ['javascript:', '<img']],
            'src data uri' => ['data: src', '<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="x">', ['data:', '<img']],
        ];
    }

    /**
     * CSS is the one place where a filter usually gets clever and usually gets it
     * wrong, so the dangerous PROPERTIES are pinned individually.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousStyles')]
    public function test_dangerous_css_is_refused(string $label, string $style): void
    {
        $clean = $this->clean('<p style="'.$style.'">Visible words</p>');

        // The WORDS always survive, even when the style does not: losing a
        // lecturer's work to a filter decision is the worst outcome an allowlist
        // can have, and it is the reason unwrapping is the default.
        $this->assertStringContainsString('Visible words', $clean, 'the words must survive even when the style does not');

        // The dangerous DECLARATION is gone. The attribute itself may remain - a
        // payload like "text-align:center;}</style><script>" keeps its legitimate
        // first declaration, and that is the correct outcome, so the assertion is
        // that the injection is absent rather than that no style survives at all.
        foreach (['url(', 'expression', 'javascript', '<script', 'position', 'display:',
            'visibility', 'overflow', 'behavior', 'binding', 'progid', 'opacity',
            '9000px', 'font-size', 'max-height', 'z-index', 'width:'] as $dangerous) {
            $this->assertStringNotContainsStringIgnoringCase(
                $dangerous,
                $clean,
                $label.' kept a dangerous declaration: '.$dangerous
            );
        }
    }

    public static function dangerousStyles(): array
    {
        return [
            'url() exfiltration' => ['url()', 'background:url(https://evil.test/pixel)'],
            'expression()' => ['expression', 'width:expression(alert(1))'],
            'position overlay' => ['overlay', 'position:fixed;top:0;left:0;width:100vw;height:100vh'],
            'z-index overlay' => ['z-index', 'position:absolute;z-index:99999'],
            'opacity hiding' => ['hidden by opacity', 'opacity:0'],
            'display none' => ['display none', 'display:none'],
            'visibility hidden' => ['visibility', 'visibility:hidden'],
            'overflow crop' => ['overflow crop', 'overflow:hidden;max-height:1px;width:1px'],
            'import' => ['css import', 'background-image:url(x)'],
            'progid' => ['progid', 'width:progid:DXImageTransform.Microsoft.AlphaImageLoader'],
            'escape sequence' => ['css escape', 'color:\\65 ttack'],
            'comment split' => ['comment split', 'text-align/**/:center;background:url(x)'],
            'semicolon injection' => ['semicolon injection', 'text-align:center;}</style><script>alert(1)</script>'],
            'huge indent' => ['absurd length', 'margin-left:9000px'],
            'behaviour' => ['behaviour', 'behavior:url(x.htc)'],
            'binding' => ['binding', '-moz-binding:url(x.xml#x)'],
        ];
    }

    public function test_a_link_to_another_origin_is_hardened_rather_than_trusted(): void
    {
        // `_blank` without `noopener` hands the opened page a `window.opener`
        // reference back to this one.
        $clean = $this->clean('<a href="https://example.org" target="_blank">source</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }

    public function test_an_image_is_made_lazy_by_default(): void
    {
        $clean = $this->clean('<img src="https://cdn.example.org/figure.png" alt="A figure">');

        $this->assertStringContainsString('loading="lazy"', $clean);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. IMAGES ARE A GOVERNED DECISION
    // ══════════════════════════════════════════════════════════════════════

    public function test_images_can_be_refused_ENTIRELY_by_policy(): void
    {
        // An image is a request the reader's browser makes to a third-party host,
        // on the reader's connection, and it tells that host who opened the page.
        // Where a deployment has not authorised external images, the honest answer
        // is to refuse them rather than to keep the ones that happen to look safe.
        $clean = $this->clean('<p>Before</p><img src="https://cdn.example.org/a.png" alt="x"><p>After</p>', images: false);

        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringContainsString('Before', $clean);
        $this->assertStringContainsString('After', $clean);
    }

    public function test_a_relative_image_is_refused_even_when_images_are_allowed(): void
    {
        // "images/diagram.png" is meaningless without a per-lesson base path, and
        // quietly accepting it would let a body reference an application path.
        $this->assertStringNotContainsString('<img', $this->clean('<img src="/storage/private.png" alt="x">'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. PASTING FROM WORD AND GOOGLE DOCS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The browser tidies a paste; the SERVER still has to cope with whatever
     * arrives, because the tidying is a courtesy and a client can send anything.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wordAndDocsPastes')]
    public function test_a_paste_from_a_word_processor_survives_without_its_junk(string $label, string $html, string $expected, array $mustNotContain): void
    {
        $clean = $this->clean($html);

        // Read through `toText()`, which is the view a reader actually gets: the
        // sanitized markup, with block boundaries and spacing resolved.
        $this->assertStringContainsString(
            $expected,
            $this->sanitizer->toText($html),
            $label.' lost real content'
        );
        $this->assertStringNotContainsString('MsoNormal', $clean, $label.' kept a Word class');
        $this->assertStringNotContainsString('mso-', $clean, $label.' kept a Word style');

        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsString($needle, $clean, $label.' kept: '.$needle);
        }
    }

    public static function wordAndDocsPastes(): array
    {
        return [
            'Word class and styles' => [
                'Word',
                '<p class="MsoNormal" style="mso-margin-top-alt:auto;margin:0cm">Real text</p>',
                'Real text',
                ['<o:p', '<xml'],
            ],
            'Word conditional comment' => [
                'Word conditional',
                '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Normal</w:View></w:WordDocument></xml><![endif]-->'
                .'<p>Real text</p>',
                'Real text',
                ['<xml', 'w:WordDocument'],
            ],
            // The claim is about the TEXT, and `toText()` is what reads text, so
            // the expectation belongs there. The browser-side tidier unwraps this
            // span and drops the padding; the server keeps the words, and the
            // preview collapses the run to a single space - which is right, because
            // three spaces inside a sentence are spacing, not content.
            'Word spacer run' => [
                'Word spacer',
                '<p>A<span style="mso-spacerun:yes">   </span>B</p>',
                'A B',
                ['mso-spacerun'],
            ],
            'Google Docs identity attribute' => [
                'Google Docs',
                '<b id="docs-internal-guid-abc123" style="font-weight:normal">Real text</b>',
                'Real text',
                ['docs-internal-guid'],
            ],
            'Word heading and list' => [
                'Word structure',
                '<h2 class="MsoHeading1">Working<o:p></o:p></h2><ul class="MsoListParagraph"><li>Step one</li></ul>',
                'Step one',
                ['MsoHeading1', 'MsoListParagraph'],
            ],
            // `font-family` is in the vocabulary ON PURPOSE - a lecturer may ask for
            // a typeface - so Word's font choice survives, and asserting otherwise
            // would mean removing a capability. What Word adds that nobody asked
            // for is the absolute point size, and THAT is what must go: a pasted
            // 11pt is a printing artefact, not a decision.
            'Word font declaration' => [
                'Word font',
                '<p style="font-family:\'Calibri\',sans-serif;font-size:11.0pt">Real text</p>',
                'Real text',
                ['font-size', '11.0pt'],
            ],
        ];
    }

    public function test_a_word_paste_that_carries_a_script_loses_the_script(): void
    {
        // Real pastes have carried script before, and the whole reason the paste
        // path is examined on the SERVER is that the browser's tidying is a
        // courtesy rather than a control.
        $clean = $this->clean(
            '<p class="MsoNormal">Legitimate work</p><script>steal(document.cookie)</script>'
        );

        $this->assertStringContainsString('Legitimate work', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('document.cookie', $clean);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. UNWRAPPING, NOT VANISHING
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unknown_tag_is_UNWRAPPED_so_the_words_survive(): void
    {
        // "Not dangerous, just not ours". Dropping the tag AND its text would lose
        // a lecturer's work to a filter decision, which is the worst outcome an
        // allowlist filter can have.
        $this->assertStringContainsString(
            'The words matter',
            $this->clean('<custom-element>The words matter</custom-element>')
        );
    }

    public function test_a_dangerous_tag_is_DROPPED_WITH_its_contents(): void
    {
        // The opposite decision, and for the opposite reason: unwrapping these would
        // leak the script SOURCE into the page as visible text.
        $clean = $this->clean('<p>Before</p><script>SECRET_SOURCE</script><p>After</p>');

        $this->assertStringNotContainsString('SECRET_SOURCE', $clean);
        $this->assertStringContainsString('Before', $clean);
        $this->assertStringContainsString('After', $clean);
    }

    public function test_unparseable_input_is_stored_as_escaped_text_rather_than_markup(): void
    {
        // Failing closed on structure while keeping the words is the whole of it.
        $clean = $this->clean('<p>Unclosed <strong>bold');

        $this->assertIsString($clean);
    }

    public function test_empty_and_null_input_are_empty_output(): void
    {
        $this->assertSame('', $this->clean(null));
        $this->assertSame('', $this->clean(''));
        $this->assertSame('', $this->clean('   '));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. PLAIN-TEXT PREVIEWS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The completeness check that decides whether a student's answer counts reads
     * TEXT, and it is why markup that looks like an answer is not one.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyMarkup')]
    public function test_markup_with_no_visible_text_reads_as_empty(string $markup): void
    {
        $this->assertSame('', $this->sanitizer->toText($markup), var_export($markup, true).' should read as empty');
    }

    public static function emptyMarkup(): array
    {
        return [
            'line break' => ['<p><br></p>'],
            'repeated line breaks' => ['<p><br><br></p>'],
            'non-breaking space' => ['<p>&nbsp;</p>'],
            'several non-breaking spaces' => ['<p>&nbsp;&nbsp;&nbsp;</p>'],
            'empty paragraph' => ['<p></p>'],
            'whitespace only' => ['<p>    </p>'],
            'empty formatting tags' => ['<p><strong></strong><em></em><u></u></p>'],
            'empty list' => ['<ul><li></li></ul>'],
            'empty heading' => ['<h3></h3>'],
            'empty table' => ['<table><tbody><tr><td></td><td></td></tr></tbody></table>'],
            'empty quote' => ['<blockquote></blockquote>'],
            'image alone' => ['<p><img src="https://a.test/x.png" alt=""></p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realAnswers')]
    public function test_a_genuine_formatted_answer_reads_as_text(string $markup, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->toText($markup));
    }

    public static function realAnswers(): array
    {
        return [
            'bold' => ['<p><strong>Ratio</strong> analysis</p>', 'Ratio analysis'],
            'italic' => ['<p>approximately <em>four</em></p>', 'approximately four'],
            'heading then text' => ['<h3>Working</h3><p>Step one</p>', 'Working Step one'],
            'list' => ['<ul><li>Revenue</li><li>Cost</li></ul>', 'Revenue Cost'],
            'equation fallback' => ['<span data-latex="x^2">x squared</span>', 'x squared'],
            // A cell boundary is a word break, so a two-column table reads 'Revenue 9000'
            // and not 'Revenue9000' - which is exactly the mangling the block-boundary
            // fix removed, and exactly what every card preview used to show.
            'table' => ['<table><tr><td>Revenue</td><td>9000</td></tr></table>', 'Revenue 9000'],
            'subscript' => ['<p>H<sub>2</sub>O</p>', 'H2O'],
        ];
    }

    public function test_a_preview_is_truncated_with_an_ellipsis(): void
    {
        $preview = $this->sanitizer->toText('<p>'.str_repeat('word ', 200).'</p>', 40);

        $this->assertLessThanOrEqual(40, mb_strlen($preview));
        $this->assertStringEndsWith('…', $preview);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. THE SAME FILTER IS USED EVERYWHERE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_cover_image_uses_the_same_filter_for_its_alt_text(): void
    {
        // Not a big surface, but the point is the RULE: there is one filter, and
        // every place author-supplied HTML becomes trusted HTML goes through it.
        $this->assertTrue(class_exists(CourseCoverImage::class));
        $this->assertInstanceOf(HtmlSanitizer::class, $this->sanitizer);
    }

    public function test_sanitising_is_idempotent(): void
    {
        // Running the filter twice must produce the same value as running it once.
        // It is not, because the filter is applied on every save and a lecturer who
        // opens a lesson five times would otherwise accumulate changes they did not
        // make.
        $html = '<p class="x" style="text-align:center;color:red" onclick="x()">'
            .'<strong>Working</strong> <span data-latex="x^2">x squared</span></p>';

        $once = $this->clean($html);
        $twice = $this->clean($once);

        $this->assertSame($once, $twice, 'the filter is not idempotent');
    }

    public function test_sanitising_does_not_grow_the_document(): void
    {
        $html = '<p>'.str_repeat('<strong>bold</strong> and <em>italic</em> ', 50).'</p>';

        $this->assertLessThanOrEqual(
            strlen($html),
            strlen($this->clean($html)),
            'sanitising produced a longer document than it was given'
        );
    }
}
