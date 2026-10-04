<?php

namespace App\Support\CourseContent;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The only place authored academic HTML is allowed to become trusted HTML.
 *
 * EVERY ACADEMIC SURFACE PASSES THROUGH HERE
 *
 * Lesson bodies, assignment instructions, question prompts, learning objectives,
 * lecturer feedback and a student's written answer are all rich text, and all of
 * them end up rendered in somebody else's browser. One filter for all of them is
 * deliberate: a second, looser copy is a second set of rules to get wrong, and the
 * one that is wrong will be the one nobody tests.
 *
 * WHY THIS EXISTS RATHER THAN A LIBRARY
 *
 * Rendering author-supplied HTML without filtering is a stored-XSS hole with a
 * lecturer's login behind it. Filtering it with a regex or a strip_tags() call is
 * worse than not filtering at all - it produces broken markup while still letting
 * the dangerous parts through.
 *
 * So the filter is a real HTML5 parse followed by an ALLOWLIST walk:
 *
 *   1. Parse the fragment as HTML.
 *   2. Keep an element only if its tag is on the allowlist. Anything else is
 *      unwrapped (its text survives) or, for the genuinely dangerous tags,
 *      dropped whole along with their contents.
 *   3. Keep an attribute only if it is on the allowlist FOR THAT TAG. Every
 *      on* handler and anything unlisted is removed.
 *   4. Re-check URLs. Only http, https and mailto survive, and a relative URL is
 *      rejected rather than assumed safe, because a lesson body is rendered on
 *      several origins and a protocol-relative //evil.test is an XSS vector.
 *   5. If `style` survives, rebuild it from an ALLOWED PROPERTY list with a
 *      validated value for each. Never passed through as written.
 *
 * Nothing is added back afterwards, so an element the allowlist did not
 * anticipate cannot appear in the output even if the parser invents it.
 *
 * ── WHY INLINE STYLES ARE NOW PERMITTED AT ALL, AND HOW ───────────────────
 *
 * The editor's alignment and indentation commands work by writing inline styles
 * (`text-align:center`, `margin-left:40px`). With `style` stripped wholesale - as
 * it was - a lecturer who centred a worked example or indented a derivation lost
 * the formatting on save, and was never told. A formatting control that silently
 * does nothing is worse than an absent one, because the author trusts it.
 *
 * So `style` is permitted, but NOT as written. It is rebuilt property by property
 * from `ALLOWED_STYLE_PROPERTIES`, and every value is matched against a pattern
 * chosen so that no property in the list can carry a url(), an expression(), a
 * function call, an escape, a comment or a semicolon. Two properties in the list
 * are enumerations of keywords. The result is that the whole set of reachable CSS
 * is a fixed, tiny vocabulary of presentation values chosen by the toolbar.
 *
 * WHAT THIS STILL CANNOT DO, AND THAT IS THE POINT
 * A reader can still centre, indent and set a typeface. A reader cannot position
 * anything, overlap anything, hide anything, load anything, or hide text from a
 * marker - none of those are in the vocabulary, so no amount of crafted input
 * reaches them.
 *
 * EQUATIONS ARE RESERVED, NOT BUILT
 *
 * `span[data-latex]` and `span[data-equation]` are allowed, and the editor has a
 * button that produces them. That is the whole of it: PIIE stores the notation
 * and can render it later with a maths library, but it does not interpret or
 * execute anything now, so an equation cannot become an injection point.
 */
class HtmlSanitizer
{
    /**
     * Tag => attributes allowed on it.
     *
     * `class` is permitted on a small, named set of tags only, because the
     * lesson stylesheet keys off it. A blanket class allowlist on <div> would let
     * an author borrow the application's own utility classes and restyle the
     * surrounding page.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        // Structure
        'p' => ['style'], 'br' => [], 'hr' => [],
        'div' => ['class', 'style'], 'span' => ['class', 'style', 'data-latex', 'data-equation'],
        'figure' => ['class', 'style'], 'figcaption' => ['class'],
        'section' => ['class', 'style'], 'article' => ['class'],
        // Headings
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'],
        'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        // Inline formatting
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        's' => [], 'strike' => [], 'del' => [], 'ins' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'mark' => [], 'abbr' => ['title'],
        // Lists
        'ul' => ['class'], 'ol' => ['class', 'start', 'style'], 'li' => ['class', 'style'],
        'dl' => [], 'dt' => [], 'dd' => [],
        // Quotes and code
        'blockquote' => ['cite', 'style'], 'pre' => ['class'], 'code' => ['class'],
        // Tables
        'table' => ['class'], 'caption' => ['class'],
        'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => ['class', 'style'], 'th' => ['colspan', 'rowspan', 'scope', 'class', 'style'],
        'td' => ['colspan', 'rowspan', 'class', 'style'],
        // Media. `src` is URL-checked; there is deliberately no <video>/<audio>
        // here yet, so "responsive and light" is not quietly undermined by a
        // player that autoplays a large file on a metered connection.
        'img' => ['src', 'alt', 'title', 'width', 'height', 'class', 'loading', 'style'],
        'a' => ['href', 'title', 'target', 'rel'],
    ];

    /**
     * THE INLINE-STYLE VOCABULARY. Property => value pattern.
     *
     * Every pattern is anchored and excludes, by construction: `(`, `)`, `;`, `\`,
     * `"`, `'`, `@`, `<`, `>`, `{`, `}` and any `/`. That single exclusion is why
     * a url() for exfiltration, an expression(), a comment-splitting trick and a
     * property-injection via a missing semicolon are all unreachable - not
     * because each was thought about separately, but because the character set is
     * small enough to reason about in one go.
     *
     * `text-align` and `text-decoration` are pure enumerations. The two lengths
     * are integers of pixels bounded at 200, which is far past any real
     * indentation and far short of anything a layout can be driven by.
     * `font-family` is the widest, and still admits only a comma-separated list of
     * quoted or bare family names.
     *
     * @var array<string, string>
     */
    private const ALLOWED_STYLE_PROPERTIES = [
        'text-align' => '/^(left|right|center|centre|justify)$/',
        'text-indent' => '/^([0-9]{1,3})(px|em)$/',
        'margin-left' => '/^([0-9]{1,3})(px|em)$/',
        'text-decoration' => '/^(underline|line-through|none)$/',
        'font-family' => "/^[A-Za-z0-9'\" ,.-]{1,120}$/",
    ];

    /** No length in the vocabulary may exceed this. */
    private const MAX_STYLE_LENGTH_PX = 200;

    /**
     * Tags dropped WITH their contents. Unwrapping these would leak script
     * source or style text into the page as visible text.
     *
     * @var list<string>
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'link', 'meta', 'base', 'noscript', 'template', 'svg', 'math',
        'audio', 'video', 'source', 'track', 'canvas', 'map', 'area',
    ];

    /** URL schemes academic content may link or embed. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * @param  string|null  $html
     * @param  bool  $allowImages  Whether `<img>` may survive at all.
     *
     * The flag is a POLICY decision, not a convenience. An image is a request to
     * a third-party host that the reader's browser makes on the reader's
     * connection, and it can report who opened the page to that host. Where an
     * institution has not authorised external images, the honest answer is to
     * refuse them rather than to quietly keep the ones that happen to look safe.
     */
    public function sanitize(?string $html, bool $allowImages = true): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Force UTF-8 interpretation. Without this a mangled byte sequence can
        // make the parser and the browser disagree about where a tag ends.
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The wrapper gives the parser a document to resolve relative URLs
        // against; it is removed again before the value is stored.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="cc-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            // Unparseable input is not stored as markup. Falling back to
            // escaped text keeps the lecturer's words without keeping the risk.
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        $root = $document->getElementById('cc-root');
        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->clean($root, $allowImages);

        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /** Recursively filter one node's children. */
    private function clean(DOMNode $node, bool $allowImages): void
    {
        // Iterate over a snapshot: the loop mutates the live child list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Text and CDATA are safe; comments can hide conditional
                // script in old IE and have no place in academic content. This is
                // also what makes a paste from Microsoft Word safe without any
                // Word-specific rule: Word's conditional comments are comments.
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // Not dangerous, just not ours: unwrap it so the lecturer's words
                // survive the filter instead of vanishing with the tag.
                $this->clean($child, $allowImages);
                $this->unwrap($node, $child);

                continue;
            }

            $this->filterAttributes($child, self::ALLOWED[$tag]);

            // A paste from Microsoft Word leaves its naming convention behind, and
            // `class` is legitimately on the allowlist for tags like <ul> and <li>
            // because the stylesheet keys off it. So `class="MsoListParagraph"`
            // survived every subsequent save, accumulating in stored academic
            // content where it is inert, meaningless to a reader, and makes two
            // documents that should be identical compare unequal.
            //
            // The convention is stable and unmistakable, so this is safe and cheap.
            // The WORDS are untouched - only the class names go.
            if ($child->hasAttribute('class')) {
                $this->stripOfficeClassNames($child);
            }

            // `style` is never trusted as written. It is rebuilt from the
            // vocabulary, so anything not on the list is gone and every surviving
            // value has been matched against a pattern that admits no function,
            // url, escape or comment.
            if ($child->hasAttribute('style')) {
                $this->filterStyle($child);
            }

            if ($tag === 'a') {
                $this->hardenLink($child);
            }

            if ($tag === 'img') {
                $this->hardenImage($child, $allowImages);
            }

            $this->clean($child, $allowImages);
        }
    }

    /**
     * Replace an unwrapped element with its children, so no disallowed markup
     * survives but the readable text does.
     */
    private function unwrap(DOMNode $parent, DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }
        $parent->removeChild($element);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function filterAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (! $attribute instanceof DOMAttr) {
                continue;
            }
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    /**
     * REBUILD the inline style from the vocabulary, and drop the attribute
     * entirely if nothing survives.
     *
     * Rebuilding rather than editing is the whole safety property. Parsing
     * `style` by hand is where filters go wrong: `text-align:left;}</style><script>`
     * and `background:url(javascript:…)` are the kinds of thing a naive splitter
     * lets through. Here the input is never interpreted at all beyond splitting
     * on `;` and `:` and matching each fragment against a pattern, and the output
     * is assembled from the constant property names and the matched values - so
     * the stored string can only be a concatenation of things this class chose
     * to allow.
     *
     * The property name is matched against the vocabulary KEY before it is used,
     * so an unknown property cannot introduce a key the output would then carry.
     */
    private function filterStyle(DOMElement $element): void
    {
        $raw = (string) $element->getAttribute('style');
        $kept = [];

        foreach (explode(';', $raw) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));

            $property = strtolower($property);

            if (! isset(self::ALLOWED_STYLE_PROPERTIES[$property])) {
                continue;
            }

            $value = $this->cleanStyleValue($property, $value);

            if ($value === null) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        if ($kept === []) {
            $element->removeAttribute('style');

            return;
        }

        $element->setAttribute('style', implode('; ', $kept));
    }

    /**
     * One property's value, or null to drop the declaration.
     *
     * The bound on length is applied here rather than being left to the pattern,
     * because `200px` and `200em` are both accepted by the same pattern and only
     * one of them is a sane indent. `200em` on a body font is several screens.
     */
    private function cleanStyleValue(string $property, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (! preg_match(self::ALLOWED_STYLE_PROPERTIES[$property], $value)) {
            return null;
        }

        if ($property === 'text-indent' || $property === 'margin-left') {
            if ((int) $value > self::MAX_STYLE_LENGTH_PX) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Remove the class names an office suite attaches on paste.
     *
     * Only the two unmistakable prefixes: `Mso` (Microsoft Office) and `o:`
     * (the `<o:p>` spacer family). Nothing a lecturer would name a class that way,
     * and nothing this application defines - its own styling uses `as-`, `ch-` and
     * `piie-` prefixes, so an author class of their own is left alone.
     *
     * The attribute is removed entirely when nothing recognisable is left, rather
     * than being left as `class=""`.
     */
    private function stripOfficeClassNames(DOMElement $element): void
    {
        $class = (string) $element->getAttribute('class');

        if ($class === '') {
            return;
        }

        $kept = array_values(array_filter(
            preg_split('/\s+/', $class) ?: [],
            static fn (string $name): bool => $name !== ''
                && stripos($name, 'Mso') !== 0
                && stripos($name, 'o:') !== 0
        ));

        if ($kept === []) {
            $element->removeAttribute('class');

            return;
        }

        $element->setAttribute('class', implode(' ', $kept));
    }

    /**
     * A link is the one place an author can smuggle a scheme, so the URL is
     * checked here rather than trusted from the allowlist.
     */
    private function hardenLink(DOMElement $link): void
    {
        $href = trim((string) $link->getAttribute('href'));

        if (! $this->isSafeUrl($href)) {
            $link->removeAttribute('href');
            $link->removeAttribute('target');

            return;
        }

        // A target of _blank without noopener hands the opened page a
        // window.opener reference back to this one.
        if (strtolower((string) $link->getAttribute('target')) === '_blank') {
            $link->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function hardenImage(DOMElement $image, bool $allowImages): void
    {
        $src = trim((string) $image->getAttribute('src'));

        if (! $allowImages || ! $this->isSafeUrl($src)) {
            // A broken image with no src is worse than no image: remove it.
            $image->parentNode?->removeChild($image);

            return;
        }

        // The reader is built for low-bandwidth markets, so images are lazy by
        // default rather than only when the author remembers.
        if (! $image->hasAttribute('loading')) {
            $image->setAttribute('loading', 'lazy');
        }
    }

    /**
     * Only real absolute URLs on known schemes.
     *
     * A RELATIVE url is rejected on purpose. "images/diagram.png" is meaningless
     * without a per-lesson base path this class does not have, and quietly
     * accepting it would let a body reference an application path such as
     * "/storage/...". Better to refuse it and let the author use an absolute
     * link, which is also what the editor's image dialog produces.
     */
    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Reject control characters, which can be used to break out of an
        // attribute value or to disguise a scheme ("java\0script:").
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            return false;
        }

        return in_array($scheme, self::SAFE_SCHEMES, true);
    }

    /**
     * Plain-text preview, for lists, search results and meta descriptions.
     *
     * ── WHY BLOCK BOUNDARIES BECOME SPACES ───────────────────────────────
     *
     * `strip_tags()` alone concatenates across block boundaries:
     *
     *     strip_tags('<h3>Working</h3><p>Step one</p>')  ->  'WorkingStep one'
     *
     * Every preview in the product goes through here - card summaries, list rows,
     * meta descriptions - so a lesson whose body is a heading followed by a
     * paragraph previewed as "WorkingStep one". That is not a truncation, it is a
     * mangling, and it happens at every block boundary in the document.
     *
     * So a closing block tag, and a line break, become a SPACE before stripping.
     * The boundary then survives as the thing it is: a word break. This is also
     * what makes `toText()` honest as a COMPLETENESS check - a document of
     * `<p><br></p>` still reads empty, because a break with no text either side
     * collapses away.
     */
    public function toText(?string $html, int $limit = 200): string
    {
        $markup = (string) $this->sanitize($html);

        // Block-level closers and line breaks, in that order so `</p>` and `<br>`
        // both become separators before any tag is removed.
        $markup = preg_replace('#</(p|div|section|article|h[1-6]|li|tr|td|th|blockquote|pre|figcaption|ul|ol|dd|dt|table|thead|tbody|figure)>#i', ' ', $markup) ?? $markup;
        $markup = preg_replace('#<br\s*/?>#i', ' ', $markup) ?? $markup;

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($markup)) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    /**
     * Does this HTML contain anything a person actually WROTE?
     *
     * ── WHY THIS EXISTS RATHER THAN `toText($html) === ''` ─────────────────────
     *
     * Completeness checks all over the product ask "is this empty?". They were all
     * answering it with `toText()`, which is a PREVIEW function, and a preview
     * answers a different question.
     *
     * Measured, on the two cases that decide it:
     *
     *   <p><br></p>                                    -> ""      correctly empty
     *   <h1><span style="font-family: Arial">Q</span></h1> -> "\u{FEFF}"  NOT empty
     *
     * That second one is a heading and a typeface wrapping U+FEFF - a zero-width
     * NO-BREAK SPACE. It is what an editor submits when someone picks a style and
     * types nothing, and `trim()` does not remove it because it is not in PHP's
     * default character list. So:
     *
     *   - a BLANK styled question passed the "must not be empty" rule and was saved
     *     as an unanswerable question (this is the row that reached Offering 5);
     *   - U+200B, U+200C, U+200D and U+00A0 behave the same way and are equally
     *     invisible to a reader.
     *
     * Refusing those is not pedantry. A published assignment question a student
     * cannot answer, and cannot report as missing because it looks intentional, is
     * worse than a validation message at save time.
     *
     * ── WHAT IT DELIBERATELY DOES NOT REJECT ────────────────────────────────
     *
     * Real text inside any formatting survives, because only the invisible
     * characters are removed:
     *
     *   <h1><span style="font-family: Arial">Question text</span></h1>  -> content
     *   <p><b>Explain the addition.</b></p>                            -> content
     *   <table><tr><td>Profit</td></tr></table>                        -> content
     *
     * ── WHY IT IS NOT A REGEX ON THE RAW MARKUP ────────────────────────────
     *
     * The check runs on the SANITISED text, so `<img src=x>` and `<script>` cannot
     * read as content by existing, and it cannot be fooled by encoded markup. A
     * regex over the raw HTML would have to re-implement tag stripping and would be
     * one more parser to keep in step.
     */
    public function hasMeaningfulText(?string $html): bool
    {
        $text = $this->toText($html, PHP_INT_MAX);

        // The invisible set, named rather than regexed for: U+00A0 is a no-break
        // space (looks blank, is not whitespace to trim), U+200B/C/D are zero-width
        // space / non-joiner / joiner, U+2060 is a word joiner, U+FEFF is the BOM
        // and zero-width no-break space. All render as nothing.
        $invisible = [
            "\u{00A0}",  // no-break space
            "\u{200B}",  // zero-width space
            "\u{200C}",  // zero-width non-joiner
            "\u{200D}",  // zero-width joiner
            "\u{2060}",  // word joiner
            "\u{FEFF}",  // BOM / zero-width no-break space
            "\u{180E}",  // mongolian vowel separator, retired but still emitted
        ];

        return trim(str_replace($invisible, '', $text)) !== '';
    }
}
