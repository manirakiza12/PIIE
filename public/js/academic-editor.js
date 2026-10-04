/*
 * THE ACADEMIC EDITOR - one implementation for every rich-text surface in PIIE.
 *
 * ── WHY SUMMERNOTE, AGAIN, AND NOT A HOMEMADE EDITOR ──────────────────────
 *
 * Summernote Lite is already vendored, already loaded by the application layout,
 * and already used by four views. Building a contenteditable editor beside it
 * would mean two editing models in one product: two sets of keyboard shortcuts,
 * two paste behaviours, two undo stacks, and two places for a formatting bug to
 * hide. So the toolbar, the plugins and the initialisation below are the ONE
 * place any of that is decided, and the Blade component
 * `components.academic-editor` is the ONE place it is mounted.
 *
 * WHAT THE FOUR PRE-EXISTING INITS GOT WRONG, AND WHAT CHANGED
 *
 * Those four views each carried their own copy of the toolbar array, their own
 * copy of a table plugin, their own copy of a sub/superscript pair, and their own
 * copy of a "the editor could not be loaded" notice. They had already drifted:
 * the assignment form offered a table button, the lesson form did not; the
 * assignment form had no special-characters button, so a lecturer had no way to
 * type ≤ or Σ except by hand. Collapsing them is the point of this file.
 *
 * ── WHAT THE TOOLBAR NOW OFFERS, AND WHY EACH ITEM IS HERE ────────────────
 *
 *   bold / italic / underline        academic emphasis
 *   style (paragraph + h1..h6)      headings and body text
 *   fontname                        a typeface an examiner may ask for
 *   ul / ol                         bullet and numbered lists
 *   indent / outdent                step-by-step derivations
 *   left/center/right/justify       alignment
 *   link                            citations
 *   table                           data, and comparison answers
 *   picture                         images, WHERE AUTHORISED (see below)
 *   superscript / subscript         H2O, x squared, footnotes
 *   specialchars (vendored)         punctuation and general symbols
 *   piieSymbol                      MATHEMATICAL symbols - see below
 *   piieEquation                    LaTeX notation for later rendering
 *   quote / clear / undo / redo / fullscreen
 *
 * ── WHY A SEPARATE MATHEMATICS SYMBOL PICKER ──────────────────────────────
 *
 * The vendored specialchars dataset is the HTML entity set: quotation marks,
 * currency symbols, accented letters. It contains no mathematics at all - no ≤,
 * no Σ, no √, no α. A Business Mathematics lecturer writing a question about
 * inequalities would have had to reach for a browser's Insert Symbol dialog, and
 * a student answering one would have had no way to write the symbol at all. That
 * is the single most concrete gap this toolbar closes, so the symbols are here
 * rather than left to a system dialog the page does not control.
 *
 * ── WHY EQUATIONS ARE STORED, NOT RENDERED ────────────────────────────────
 *
 * The equation button inserts `<span data-latex="...">fallback text</span>`.
 * The fallback is what a reader without a maths renderer sees, and it is why the
 * notation is safe to accept: PIIE stores a string and a plain-text reading, and
 * executes nothing. The server-side sanitizer already allows exactly this shape
 * and nothing else, so the notation cannot become an injection point.
 *
 * ── IMAGES ARE A GOVERNED DECISION, NOT A TOOLBAR DECISION ────────────────
 *
 * `allowImages: false` removes the picture button AND makes the paste handler
 * drop any pasted image. The reason is not caution for its own sake: an image is
 * a request the reader's browser makes to a third-party host, on the reader's
 * connection, and it tells that host who opened the page. Where a deployment has
 * not authorised external images, offering the button would be offering a
 * tracking pixel with a nicer appearance. The server enforces the same rule
 * independently - a stored image the server refuses is a broken image, and the
 * toolbar and the sanitizer are two independent doors to the same rule.
 */
(function (window, document) {
    'use strict';

    var $ = window.jQuery;

    /* ── the one honest answer when the editor cannot load ───────────────
     * The textarea underneath is a real, submitted field. So when the editor
     * fails to initialise we REVEAL it rather than leaving a student staring at
     * a dead box: the field is still usable, still posts, and still validates.
     * Silently doing nothing would look identical to a bug and would lose the
     * work in progress. */
    function revealPlainTextarea(textarea) {
        var shell = textarea.closest ? textarea.closest('.piie-editor-shell') : null;
        textarea.style.display = '';
        textarea.classList.remove('piie-editor-source');
        textarea.setAttribute('rows', '10');
        if (shell) { shell.classList.add('piie-editor-fallback'); }
    }

    function reportUnavailable(textarea) {
        if (!textarea) { return; }
        revealPlainTextarea(textarea);
        var shell = textarea.closest ? textarea.closest('.piie-editor-shell') : null;
        if (shell && !shell.querySelector('.piie-editor-notice')) {
            var notice = document.createElement('div');
            notice.className = 'alert alert-warning m-2 piie-editor-notice';
            notice.setAttribute('role', 'status');
            notice.innerHTML = '<strong>The rich-text editor did not load.</strong> '
                + 'The field below still works and everything you type will be saved, '
                + 'but formatting, tables and equations are unavailable on this device.';
            shell.insertBefore(notice, textarea);
        }
    }

    var API = {
        /*
         * Whether the full editor can run, DECIDED AT BOOT rather than at parse
         * time - because at parse time Summernote may not have been executed yet,
         * and reading it there is how this ends up reporting "unavailable" on a
         * page where the editor is about to arrive. `start()` sets it.
         *
         * Pessimistic by default, which is the safe direction: it means the
         * textarea stays visible and usable until something proves otherwise.
         */
        available: false,

        /**
         * The editable that most recently held the caret.
         *
         * Needed because `document.activeElement` inside a contenteditable is
         * sometimes the editable and sometimes a child node, and sometimes nothing
         * at all once the toolbar button took focus. This remembers the answer, so
         * a symbol-picker or paste click always goes back to the question the
         * student was writing in. See `activeEditable()`.
         */
        lastFocusedEditable: null,

        /* ── the mathematical symbol set ─────────────────────────────────
         * Grouped by what a syllabus actually needs, in the order a marker
         * reaches for them. Each carries an HTML entity so the glyph is
         * transported as text rather than as a file, which matters on a
         * low-bandwidth connection. */
        MATH_SYMBOLS: [
            { group: 'Relations' },
            { g: '≤', e: '&le;', t: 'less than or equal to' },
            { g: '≥', e: '&ge;', t: 'greater than or equal to' },
            { g: '≠', e: '&ne;', t: 'not equal to' },
            { g: '≈', e: '&asymp;', t: 'approximately equal to' },
            { g: '≡', e: '&equiv;', t: 'identical to' },
            { g: '∝', e: '&prop;', t: 'proportional to' },

            { group: 'Arithmetic' },
            { g: '×', e: '&times;', t: 'multiplied by' },
            { g: '÷', e: '&divide;', t: 'divided by' },
            { g: '±', e: '&plusmn;', t: 'plus or minus' },
            { g: '−', e: '&minus;', t: 'minus' },
            { g: '√', e: '&radic;', t: 'square root' },
            { g: '∛', e: '&#8731;', t: 'cube root' },
            { g: '∞', e: '&infin;', t: 'infinity' },
            { g: '∑', e: '&sum;', t: 'sum' },
            { g: '∏', e: '&prod;', t: 'product' },
            { g: '∫', e: '&int;', t: 'integral' },
            { g: '∂', e: '&part;', t: 'partial derivative' },
            { g: '%', e: '%', t: 'percent' },

            { group: 'Sets and logic' },
            { g: '∈', e: '&isin;', t: 'is an element of' },
            { g: '∉', e: '&notin;', t: 'is not an element of' },
            { g: '⊂', e: '&sub;', t: 'is a subset of' },
            { g: '⊆', e: '&sube;', t: 'is a subset of or equal to' },
            { g: '∪', e: '&cup;', t: 'union' },
            { g: '∩', e: '&cap;', t: 'intersection' },
            { g: '∀', e: '&forall;', t: 'for all' },
            { g: '∃', e: '&exist;', t: 'there exists' },
            { g: '¬', e: '&not;', t: 'not' },
            { g: '∧', e: '&and;', t: 'and' },
            { g: '∨', e: '&or;', t: 'or' },

            { group: 'Greek' },
            { g: 'α', e: '&alpha;', t: 'alpha' }, { g: 'β', e: '&beta;', t: 'beta' },
            { g: 'γ', e: '&gamma;', t: 'gamma' }, { g: 'δ', e: '&delta;', t: 'delta' },
            { g: 'ε', e: '&epsilon;', t: 'epsilon' }, { g: 'θ', e: '&theta;', t: 'theta' },
            { g: 'λ', e: '&lambda;', t: 'lambda' }, { g: 'μ', e: '&mu;', t: 'mu' },
            { g: 'π', e: '&pi;', t: 'pi' }, { g: 'ρ', e: '&rho;', t: 'rho' },
            { g: 'σ', e: '&sigma;', t: 'sigma' }, { g: 'τ', e: '&tau;', t: 'tau' },
            { g: 'φ', e: '&phi;', t: 'phi' }, { g: 'ω', e: '&omega;', t: 'omega' },
            { g: 'Δ', e: '&Delta;', t: 'capital delta' }, { g: 'Σ', e: '&Sigma;', t: 'capital sigma' },
            { g: 'Ω', e: '&Omega;', t: 'capital omega' },

            { group: 'Arrows' },
            { g: '→', e: '&rarr;', t: 'maps to' },
            { g: '⇒', e: '&rArr;', t: 'implies' },
            { g: '⇔', e: '&hArr;', t: 'if and only if' },
            { g: '↔', e: '&harr;', t: 'left right arrow' }
        ],

        /**
         * Paste cleanup for Microsoft Word and Google Docs.
         *
         * ── WHY THIS IS CLIENT-SIDE AND STILL NOT TRUSTED ───────────────
         * Cleaning here is a courtesy: the author sees tidy markup immediately
         * and does not discover the problem after saving. It is NOT a security
         * control. The sanitizer rebuilds the HTML from an allowlist on the
         * server, so a payload that survived this function unchanged is still
         * refused. Two independent doors, one rule.
         *
         * ── WHAT WORD ACTUALLY PASTES ───────────────────────────────────
         *   - `<!--[if gte mso 9]><xml>…</xml><![endif]-->`  conditional
         *     comments, which are how Word hides content from one engine only
         *   - `class="MsoNormal"` and every other `Mso*` class
         *   - `mso-*` inline styles, including `mso-margin-top-alt:auto`
         *   - `<o:p></o:p>` spacer tags with no counterpart in HTML
         *   - `<span style="mso-spacerun:yes">` runs of padding spaces
         *   - an inline `<!--StartFragment-->` pair
         *   - Google Docs' `<b id="docs-internal-guid-…">` and
         *     `font-weight:normal` spans that carry no formatting intent
         *
         * Every one of those is removed, and the READABLE TEXT is always kept.
         * Losing an author's words to make a paste tidy would be a worse bug
         * than a stray class attribute, which the sanitizer would have removed
         * anyway.
         */
        tidyPastedHtml: function (html) {
            if (!html) { return ''; }

            var doc = document.implementation.createHTMLDocument('');
            var host = doc.createElement('div');
            host.innerHTML = html;

            // Word conditional comments, including the paired form.
            var walker = doc.createTreeWalker(host, NodeFilter.SHOW_COMMENT, null, false);
            var comments = [];
            while (walker.nextNode()) { comments.push(walker.currentNode); }
            comments.forEach(function (node) {
                if (node.parentNode) { node.parentNode.removeChild(node); }
            });

            var all = host.querySelectorAll('*');
            Array.prototype.forEach.call(all, function (el) {
                var tag = el.tagName.toLowerCase();

                // <o:p> and friends have no HTML meaning; unwrap so the text stays.
                if (tag.indexOf('o:') === 0) {
                    while (el.firstChild) { el.parentNode.insertBefore(el.firstChild, el); }
                    el.parentNode.removeChild(el);
                    return;
                }

                // Every mso-* declaration, and the class attributes that carry them.
                var style = el.getAttribute('style');
                if (style) {
                    var kept = style.split(';')
                        .filter(function (declaration) {
                            return declaration.trim()
                                && declaration.split(':')[0].trim().toLowerCase().indexOf('mso-') !== 0;
                        })
                        .join(';');
                    if (kept) { el.setAttribute('style', kept); } else { el.removeAttribute('style'); }
                }

                if (el.className && typeof el.className === 'string'
                    && /\bMso[A-Za-z]*\b/.test(el.className)) {
                    el.className = el.className
                        .split(/\s+/)
                        .filter(function (name) { return !/^Mso/.test(name); })
                        .join(' ');
                    if (!el.className) { el.removeAttribute('class'); }
                }

                // Google's internal identity attribute.
                if (el.hasAttribute('id') && /^docs-internal-guid-/.test(el.getAttribute('id'))) {
                    el.removeAttribute('id');
                }
            });

            // Spacer runs: Word writes these as a styled span full of spaces. The
            // text is kept; only the padding is dropped, because a paragraph
            // indent is now available from the toolbar and is the honest way to
            // express that.
            var spacers = host.querySelectorAll('span[style*="mso-spacerun"], span[style*="spacerun"]');
            Array.prototype.forEach.call(spacers, function (el) {
                while (el.firstChild) { el.parentNode.insertBefore(el.firstChild, el); }
                el.parentNode.removeChild(el);
            });

            // Word closes a paste with a trailing empty paragraph. Dropping only
            // trailing empties keeps a deliberate blank line in the middle.
            var body = host;
            while (body.lastChild && body.lastChild.nodeType === 1
                && /^P$/i.test(body.lastChild.tagName)
                && !body.lastChild.textContent.trim()) {
                body.removeChild(body.lastChild);
            }

            return body.innerHTML;
        },

        /* ══════════════════════════════════════════════════════════════════
         * THE EDITOR → SOURCE-FIELD BRIDGE
         * ══════════════════════════════════════════════════════════════════
         *
         * ── THE DEFECT, MEASURED ON REAL DATA ─────────────────────────────
         *
         * A page's autosave works by listening for `input` on the FIELD:
         *
         *     document.querySelectorAll('.exam-answer-input')
         *         .forEach(el => el.addEventListener('input', ...));
         *
         * With a plain textarea that is correct. With this component the source
         * textarea is only a FIELD - Summernote hides it and the person types into
         * a `.note-editable` element it creates. Typing there fires `input` on THAT
         * element and never on the textarea, so the autosave never became dirty,
         * the debounce never fired, and the ten-second sweep had nothing to sweep.
         *
         * Exam 18 proved it. Kyeyune Amos answered two written questions and one
         * True/False. The database holds exactly ONE answer row - the True/False.
         * The two written answers were never saved, and the attempt was closed by
         * the timer (`submitted_via = 'timeout'`), so they were lost outright. The
         * page had told the student "Answers are saved automatically."
         *
         * That is the worst failure mode an exam can have: it looks correct and
         * loses work. So the bridge is added HERE, in the editor, rather than in
         * each of the pages that consume it - which is the same reason this file
         * exists at all.
         *
         * ── WHY A REAL `input` EVENT, NOT A CALLBACK SOMEWHERE ─────────────
         *
         * Dispatching the DOM event means every existing consumer works UNCHANGED:
         * the exam attempt's dirty tracking, debounce, revision counter, offline
         * retry, conflict handling and the sweep before submit all already listen
         * for it, and the assignment and lesson forms get the same repair for free.
         * A new bespoke hook would have needed each page rewired, and the next
         * page author would have had to know it existed.
         *
         * ── WHY NOT SUMMERNOTE'S `onChange` CALLBACK ───────────────────────
         *
         * Because it is a property of Summernote's internals which differ between
         * versions, and this bundle is "Summernote Lite" - a trimmed build. Binding
         * the DOM event the browser is guaranteed to fire does not depend on which
         * Summernote build is vendored. The `onChange` callback is still registered
         * below as a SECOND path, because a recovery restore goes through
         * `summernote('code', …)` rather than through typing.
         */
        bridgeToSource: function (editable) {
            if (!editable) { return; }

            var area = API.sourceTextareaFor(editable);

            if (!area) { return; }

            var code;

            // The same read the form submit uses, so what autosave persists and what
            // the browser posts can never disagree.
            var code = API.contentOf(editable, area);

            if (typeof code !== 'string') { return; }

            if (area.value !== code) {
                // Assigning `.value` does NOT itself fire `input`, so this cannot
                // loop. The event below is the whole point, and it is dispatched
                // exactly once per real edit.
                area.value = code;
            }

            try {
                area.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (e) {
                // Very old browsers have no Event constructor. The field is already
                // current, so only the automatic trigger is lost, not the answer.
            }
        },

        /** The source textarea an editable belongs to. Never a page-wide guess. */
        sourceTextareaFor: function (editable) {
            if (!editable || !editable.closest) { return null; }
            var shell = editable.closest('.piie-editor-shell');
            return shell ? shell.querySelector('textarea[data-piie-editor]') : null;
        },

        /**
         * THE EDITOR THE PERSON IS ACTUALLY IN.
         *
         * ── THE SECOND HALF OF THE SAME BUG ───────────────────────────────
         *
         * `insertHtml()` used to target `$('.note-editable').last()`. With one
         * editor on a page that is a harmless guess. On an exam with several
         * written questions it means the symbol picker, the equation button and
         * every plain-text paste went into the LAST question on the paper - so a
         * student answering Q1 clicked a symbol and watched the text appear in
         * Q2's box, and then could not type into Q1 "correctly" at all. That is
         * the second half of the reported symptom, and it had the same cause.
         *
         * Resolution order, most reliable first:
         *   1. `document.activeElement` - who has the caret;
         *   2. the last editable to have had focus, remembered on `focusin`
         *      (contenteditable focus can sit on a child, not the editable);
         *   3. the DOM selection's containing editable;
         *   4. the first editable on the page.
         *
         * Never `.last()`: it is not "the last one the user was in", it is only
         * the last one in document order.
         */
        activeEditable: function () {
            var active = document.activeElement;

            if (active && active.classList && active.classList.contains('note-editable')) {
                return active;
            }

            if (API.lastFocusedEditable && document.body.contains(API.lastFocusedEditable)) {
                return API.lastFocusedEditable;
            }

            var selection = window.getSelection ? window.getSelection() : null;

            if (selection && selection.anchorNode) {
                var node = selection.anchorNode.nodeType === 1
                    ? selection.anchorNode
                    : selection.anchorNode.parentElement;
                var fromSelection = node && node.closest ? node.closest('.note-editable') : null;

                if (fromSelection) { return fromSelection; }
            }

            return document.querySelector('.note-editable');
        },

        /**
         * Put the caret at the end of an editable that is not currently focused.
         *
         * Without this, `pasteHTML` into an unfocused editor inserts wherever the
         * browser's stale selection happens to be - commonly position 0 - so the
         * text lands BEFORE everything the student had already written.
         */
        focusEndOf: function (editable) {
            if (!editable || !window.getSelection) { return; }

            try {
                editable.focus();

                var range = document.createRange();
                range.selectNodeContents(editable);
                range.collapse(false);

                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            } catch (e) { /* focus is best-effort; insertion still happens */ }
        },

        /* ── options ──────────────────────────────────────────────────────── */
        optionsFor: function (config) {
            var cfg = config || {};
            var height = cfg.height || 360;

            var toolbar = [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'superscript', 'subscript', 'strikethrough', 'clear']],
                ['fontname', ['fontname']],
                ['para', ['ul', 'ol']],
                ['alignment', ['left', 'center', 'right', 'justify']],
                ['insert', ['link', 'table', 'piieSymbol', 'piieEquation', 'specialchars']],
                ['list', ['outdent', 'indent']],
                ['quote', ['blockquote']],
                ['history', ['undo', 'redo']],
                ['fullscreen', ['fullscreen']]
            ];

            // Images are a governed decision, so the button appears only when the
            // deployment allows them. See the file header for why.
            if (cfg.allowImages) {
                toolbar[5].splice(1, 0, 'picture');
            }

            return {
                height: height,
                placeholder: cfg.placeholder || 'Write here. Use the toolbar for headings, lists, tables and equations.',
                disableDragAndDrop: true,
                // The image and link popovers offer no value over the dialogs and
                // are a source of overlay misplacement on small screens.
                popover: { image: [], link: [] },
                toolbar: toolbar,
                callbacks: {
                    onInit: function () { API.nameTheEditor(cfg); },
                    /**
                     * SUMMERNOTE'S OWN "THE EDITABLE EXISTS" SIGNAL.
                     *
                     * Registered for builds that provide it. IT IS NOT PROVIDED BY THE
                     * VENDORED ONE: `public/assets/js/summernote-lite.min.js` is
                     * Summernote Lite 0.8.18, and the string `onCreateEditor` does not
                     * occur in it. That callback was being treated in the code comments
                     * as "the authoritative moment" for binding the bridge, on the
                     * strength of a callback that has never once fired in this
                     * application — so the comment described a safety net that was not
                     * there.
                     *
                     * `bindBridge()` is therefore the authority, and it does not depend
                     * on a vendor callback: it looks for the editable directly and
                     * retries (0 ms, 250 ms, and on every `attachAll` pass). Keeping the
                     * callback registered costs nothing and helps on a future build, but
                     * the bridge no longer pretends to depend on it.
                     */
                    onCreateEditor: function (editable) {
                        API.bindBridgeFor(cfg, editable);
                    },
                    onPaste: function (e) { return API.handlePaste(e, cfg); }
                }
            };
        },

        /**
         * The editor is the surface a person types into, so the label's `for`
         * stops pointing at something reachable once Summernote has built its
         * own editable region. Handing the same accessible name across is the
         * difference between a labelled control and an unlabelled one.
         */
        nameTheEditor: function (cfg) {
            if (!cfg || !cfg.labelId) { return; }
            var scope = cfg.scope || document;
            var editable = scope.querySelector('.piie-editor-shell[data-for="' + cfg.fieldId + '"] .note-editable');
            if (!editable) { return; }

            editable.setAttribute('aria-labelledby', cfg.labelId);
            editable.setAttribute('role', 'textbox');
            editable.setAttribute('aria-multiline', 'true');
            if (cfg.placeholder) {
                editable.setAttribute('data-placeholder', cfg.placeholder);
            }
        },

        /**
         * A pasted fragment is tidied, then handed to the editor as HTML.
         *
         * Returning nothing from `onPaste` means "I have handled it", which stops
         * Summernote inserting the original unprocessed fragment afterwards. An
         * image in a paste is only kept where images are authorised, because the
         * paste is a second way an image can arrive and the toolbar's decision
         * must not be circumventable by copying one out of a browser.
         */
        handlePaste: function (e, cfg) {
                var clipboard = e.originalEvent
                    && (e.originalEvent.clipboardData || e.originalEvent.originalClipboardData);
                if (!clipboard) { return; }

                var html = clipboard.getData('text/html');
                var text = clipboard.getData('text/plain');

                // The editable this PASTE happened in, from Summernote's own context.
                // Preferred over `activeEditable()` because it is not a guess at all:
                // the browser told us which editor received the keystroke.
                var target = (e.currentTarget && e.currentTarget.classList
                    && e.currentTarget.classList.contains('note-editable'))
                    ? e.currentTarget
                    : API.activeEditable();

                if (!html) {
                    if (text) { API.insertHtml(text.replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n'), target); }
                    return;
                }

                var tidied = API.tidyPastedHtml(html);

                if (!cfg || !cfg.allowImages) {
                    tidied = tidied.replace(/<img\b[^>]*>/gi, '');
                }

                if (tidied.replace(/<[^>]+>/g, '').trim() === '' && !/<img/i.test(tidied)) {
                    if (text) { API.insertHtml(text, target); }
                    return;
                }

                API.insertHtml(tidied, target);
            },

            /**
             * Insert markup into the editable the person is IN.
             *
             * `target` is the editable when the caller knows it. When it is omitted
             * the caret's editor is resolved by `activeEditable()`.
             */
            insertHtml: function (html, target) {
                var editable = target || API.activeEditable();

                if (!editable) { return; }

                // Only move the caret when this editor is NOT the one currently
                // being typed into. Doing it unconditionally would throw the caret
                // to the end of the answer every time a symbol was inserted
                // mid-sentence.
                if (editable !== document.activeElement) {
                    API.focusEndOf(editable);
                }

                try {
                    $(editable).summernote('pasteHTML', html);
                } catch (e) { /* no editor running on that node */ }

                // `pasteHTML` edits the DOM directly, so it does not raise the
                // `input` event the bridge listens for. Raising it here is what makes
                // an inserted symbol an ANSWER rather than an unsaved decoration.
                API.bridgeToSource(editable);
            },

        /* ══════════════════════════════════════════════════════════════════
         * FIELD-NAMED ACCESS, for a form's own dirty and recovery logic
         * ══════════════════════════════════════════════════════════════════ */

        /**
         * Every editor on the page, keyed by its form field NAME.
         *
         * The name rather than the DOM id, because the name is also what the server
         * receives: a snapshot keyed by field name is a snapshot of the form as it
         * would be submitted, and a page may legitimately have two editors with the
         * same name - the assignment page's draft zone and hand-in zone both carry
         * the same question answers - which is why the first match wins and the
         * caller is expected to scope by a containing element when that matters.
         */
        fieldsIn: function (root) {
            var scope = root || document;
            var found = {};

            Array.prototype.forEach.call(
                scope.querySelectorAll('textarea[data-piie-editor]'),
                function (area) {
                    if (area.name && !found[area.name]) { found[area.name] = area; }
                }
            );

            return found;
        },

        /**
         * The document in one field, as HTML.
         *
         * Returns the EDITOR's document when the editor is running and the raw
         * textarea value when it is not - so a snapshot taken with JavaScript
         * half-working is still a snapshot of the real content rather than of an
         * empty box. That matters because the alternative is a recovery that
         * silently overwrites good work with nothing.
         */
        codeFor: function (name, root) {
            var area = API.fieldsIn(root)[name];

            if (!area) { return null; }

            // DOM first, for the same reason `syncAll()` does it: the vendor getter
            // can report an empty string for a document the author is looking at,
            // and an autosave that persisted that empty string would destroy the
            // answer on the next save.
            var viaDom = API.editableFor(area);

            // One read, shared with the form submit, so what autosave persists and what
            // the browser posts can never disagree.
            return viaDom ? API.contentOf(viaDom, area) : area.value;
        },

        /**
         * The contenteditable region belonging to one source field, if any.
         *
         * Resolved through the component's own shell rather than by guessing at a
         * document-wide "the editor", because a page can hold many editors and the
         * whole point of this module is that each field answers for itself.
         */
        editableFor: function (area) {
            if (! area || ! area.closest) { return null; }

            var shell = area.closest('.piie-editor-shell');

            return shell ? shell.querySelector('.note-editable') : null;
        },

        /**
         * Put a document into one field.
         *
         * Writes through the editor when it is running, and into the textarea when
         * it is not, so a restore works in the same states a save does.
         */
        setCode: function (name, html, root) {
            var area = API.fieldsIn(root)[name];

            if (!area) { return false; }

            return API.writeTo(area, html);
        },

        /**
         * PUT A DOCUMENT INTO ONE FIELD, WHERE THE PERSON CAN SEE IT LAND.
         *
         * The write side of the same problem `contentOf()` solves. Preferred order:
         *
         *   1. the vendor, when it is the instance that mounted this editor — it keeps
         *      Summernote's own state and undo history in step with what is on screen;
         *   2. the EDITABLE REGION itself, when an editor is mounted but never flagged
         *      ready. Writing only the textarea here would restore text INVISIBLY: the
         *      field would hold the recovered copy, the very next sweep would copy the
         *      still-empty editor over it, and the recovery would be lost;
         *   3. the textarea, when no editor exists at all.
         */
        writeTo: function (area, html) {
            var value = html === null || html === undefined ? '' : String(html);

            if (area.getAttribute('data-piie-editor-ready') === '1' && $ && $.fn) {
                try {
                    $(area).summernote('code', value);

                    return true;
                } catch (e) { /* fall through to the region the person sees */ }
            }

            var editable = API.editableFor(area);

            if (editable && typeof editable.innerHTML === 'string') {
                editable.innerHTML = value;

                return true;
            }

            area.value = value;

            return true;
        },

        /**
         * WHAT THE PERSON ACTUALLY SEES IN THIS EDITOR.
         *
         * ── WHY THE DOM IS READ BEFORE THE SUMMERNOTE GETTER ───────────────
         *
         * Summernote Lite is a trimmed build and its `summernote('code')` getter
         * cannot be assumed to return the live document; it can report an empty
         * string while the person is looking at their text.
         *
         * The contenteditable element's own `innerHTML` is the ground truth and is
         * read FIRST: it cannot lie about what is on screen, because it IS what is on
         * screen. The vendor getter is the fallback, and the field's own value is the
         * last resort, which is correct when no editor is mounted at all.
         */
        contentOf: function (editable, area) {
            // An EDITABLE THAT EXISTS IS AUTHORITATIVE — INCLUDING WHEN IT IS EMPTY.
            //
            // An author who deletes the last word and saves must post an empty field.
            // If an empty editable fell through to `area.value`, the question they
            // just erased would be saved instead, and the server would have no way to
            // know: the text is perfectly valid, just not what they meant.
            if (editable && typeof editable.innerHTML === 'string') {
                return editable.innerHTML;
            }

            if (editable && $ && $.fn) {
                try {
                    var viaApi = $(editable).summernote('code');

                    if (typeof viaApi === 'string') {
                        return viaApi;
                    }
                } catch (e) { /* fall through to the field */ }
            }

            // No editor is mounted at all, so the field's own value is the only
            // content there is. This is the progressive-enhancement path and it is
            // exactly right here.
            return area ? area.value : '';
        },

        /**
         * COPY EVERY EDITOR'S CONTENT INTO ITS SOURCE FIELD.
         *
         * ── EXAM 21, "final test" ──────────────────────────────────────────
         *
         * A lecturer typed a question into the visible editor, pressed Add Question,
         * and the server replied "The question field is required."
         *
         * This used to read the vendor getter and write the result straight back
         * into the VENDOR INSTANCE - summernote('code', summernote('code')). That is
         * a no-op on the element the browser serialises. `area.value` was never
         * written, so the textarea posted its empty initial value no matter what was
         * on screen. It also only considered fields flagged
         * `data-piie-editor-ready`, so a field whose editor mounted late was
         * skipped entirely.
         *
         * It now assigns `area.value`, which is the field the browser reads.
         */
        syncAll: function (root) {
            var scope = root || document;
            var areas = scope.querySelectorAll('textarea[data-piie-editor]');
            var synced = 0;

            Array.prototype.forEach.call(areas, function (area) {
                var shell = area.closest ? area.closest('.piie-editor-shell') : null;
                var editable = shell ? shell.querySelector('.note-editable') : null;

                // The ready flag is deliberately NOT required. A field the person can
                // see and type into must never be skipped, whatever the editor's own
                // internal state believes about itself.
                var code = API.contentOf(editable, area);

                // No emptiness guard here. An editor the author has emptied must write
                // that emptiness into the field, or the sweep would preserve text they
                // deliberately deleted. With no editor mounted, `contentOf()` returns
                // the field's own value and the assignment below is a no-op.
                if (typeof code !== 'string') { return; }

                if (area.value !== code) {
                    area.value = code;
                    synced++;
                }
            });

            return synced;
        },

        /**
         * Initialise one editor.
         *
         * Plugins are registered once, at load time, BEFORE any editor starts -
         * which is why there is no destroy/reinitialise dance here. The four
         * inline implementations this replaces each had to init, register
         * plugins, destroy and init again, because they defined the plugins
         * after the first initialisation.
         */
        attach: function (textarea, config) {
            if (!textarea) { return null; }

            if (!API.available) { reportUnavailable(textarea); return null; }

            if (textarea.getAttribute('data-piie-editor-ready') === '1') {
                return $(textarea).data('piieEditor');
            }

            var cfg = Object.assign({}, config || {});
            cfg.fieldId = textarea.getAttribute('id') || '';
            cfg.allowImages = cfg.allowImages === true;

            if (!cfg.labelId && textarea.id) {
                // The component always renders a label; this is the fallback for
                // a field rendered by hand, so it still gets a usable name.
                var label = document.querySelector('label[for="' + textarea.id + '"]');
                if (label && !label.id) { label.id = textarea.id + '-label'; cfg.labelId = label.id; }
            }

            var options = API.optionsFor(cfg);

            /**
             * ONE FIELD MUST NOT BE ABLE TO BREAK THE PAGE.
             *
             * `summernote(options)` builds a toolbar, measures a font and creates DOM.
             * All three can throw — a font probe, a browser quirk, a plugin this trimmed
             * build does not have — and the previous version let the exception escape.
             *
             * That was not a cosmetic fault. `academic-editor.css` hides the source
             * textarea as soon as `<html>` carries `piie-js`, and `start()` adds that
             * class BEFORE any field is attached. So a field whose initialisation threw
             * was left with `display: none` and nothing in its place: on exam 20 the
             * student saw question 4 as a card with only a number and marks, and had
             * nothing at all to type into. Worse, the exception unwound `attachAll`'s
             * loop, so every field AFTER the failing one was never attempted either.
             *
             * Two things follow, and both are here:
             *
             *   1. the exception is caught, and the field is REVEALED as a working plain
             *      textarea — the same fallback used when Summernote is absent, and the
             *      same one the stylesheet documents;
             *   2. `attachAll()` isolates each field, so one failure costs one field.
             *
             * A student with an unformatted answer box is an inconvenience. A student
             * with an invisible one loses their work while the page reports it saved.
             *
             * ── WHICH RECOVERY, DEPENDING ON HOW FAR IT GOT ──────────────────
             *
             * The exception can strike before or after Summernote creates the editable,
             * and the two need OPPOSITE recoveries:
             *
             *   editable absent → the field is a hidden textarea with nothing in its
             *                    place. REVEAL it as a plain, working, still-submitted
             *                    field. That is `reportUnavailable()`.
             *
             *   editable present → the editor is LIVE and fully usable to the student;
             *                    only our setup bookkeeping was skipped. Revealing the
             *                    textarea here would put TWO controls on the page for
             *                    one answer — one the student is typing into and one
             *                    autosave may read — which is worse than the original
             *                    fault. So the field is marked ready, the bridge is
             *                    bound, and the student keeps the editor they can see.
             *
             * This second case is not hypothetical: it is what a Summernote build that
             * throws while building its toolbar or measuring its fonts does, and it is
             * how exam 20's question 3 became an editor that looked perfect and
             * recorded nothing.
             */
            try {
                $(textarea).summernote(options);
            } catch (e) {
                var shell = textarea.closest ? textarea.closest('.piie-editor-shell') : null;
                var liveEditable = shell ? shell.querySelector('.note-editable') : null;

                if (!liveEditable) {
                    reportUnavailable(textarea);
                    textarea.setAttribute('data-piie-editor-failed', '1');

                    return null;
                }

                textarea.setAttribute('data-piie-editor-ready', '1');
                textarea.setAttribute('data-piie-editor-partial', '1');
                API.bindBridge(textarea);

                return null;
            }

            textarea.setAttribute('data-piie-editor-ready', '1');
            $(textarea).data('piieEditor', options);

            API.bindBridge(textarea);

            return options;
        },

        /**
         * Wire the editable this textarea owns back to the textarea.
         *
         * Bound ONCE per editor. The `input` listener is on the `.note-editable`
         * Summernote just built, because that is the element the person actually
         * types into - see `bridgeToSource()` for the whole story and why this is
         * the defect it is.
         */
        bindBridge: function (textarea) {
            var shell = textarea.closest ? textarea.closest('.piie-editor-shell') : null;
            if (!shell) { return false; }

            var editable = shell.querySelector('.note-editable');

            // ── THE EDITABLE MAY NOT EXIST YET ─────────────────────────────────
            //
            // `attach()` calls this immediately after `summernote(options)`, which
            // assumes Summernote has inserted its editable synchronously. Summernote
            // Lite does not guarantee that: when the editable is built on a later
            // tick, `shell.querySelector('.note-editable')` is null, this function
            // returns, and the bridge is NEVER bound — silently, with no error.
            //
            // The consequence is precisely exam 19's question 3. A save fired from
            // somewhere else (the pre-submit sweep, a blur) wrote the empty
            // `<p><br></p>` document; the student then typed, every keystroke fired
            // `input` on the editable where nobody was listening, the textarea never
            // went dirty, and nothing was ever saved again. The page reported "Saved"
            // for work it had never received.
            //
            // So binding is retried on Summernote's own creation callback AND on a
            // short timer, and it is idempotent. The bridge is the single thing
            // standing between a student and losing their work, so it may not depend
            // on a vendor's internal timing.
            if (!editable) {
                if (textarea.getAttribute('data-piie-bridge-pending') !== '1') {
                    textarea.setAttribute('data-piie-bridge-pending', '1');

                    // Belt and braces: whichever of these fires first wins, and the
                    // `data-piie-bridge-ready` guard makes the second a no-op.
                    window.setTimeout(function () {
                        textarea.removeAttribute('data-piie-bridge-pending');
                        API.bindBridge(textarea);
                    }, 0);

                    window.setTimeout(function () {
                        textarea.removeAttribute('data-piie-bridge-pending');
                        API.bindBridge(textarea);
                    }, 250);
                }

                return false;
            }

            textarea.removeAttribute('data-piie-bridge-pending');

            if (editable.getAttribute('data-piie-bridge-ready') === '1') { return true; }

            editable.setAttribute('data-piie-bridge-ready', '1');

            // Typing, paste, undo and every formatting command all end in `input` on
            // the editable. This one listener is what makes a written answer visible to
            // the page's autosave.
            editable.addEventListener('input', function () { API.bridgeToSource(editable); });

            // Focus is tracked so a toolbar click (which takes focus away from the
            // editable) can still insert back into the right question.
            editable.addEventListener('focus', function () { API.lastFocusedEditable = editable; });

            /**
             * THE SOURCE IS SYNCHRONISED AT ONCE, NOT ONLY ON THE NEXT KEYSTROKE.
             *
             * Without this, an answer that was already in the editor before the bridge
             * existed — restored from the server on a resumed attempt, or re-typed in a
             * session where the first save raced the bridge — sits in the editor while
             * the textarea still reads empty. The autosave then persists the empty
             * value and the student's work is lost on the next save. Reading the editor
             * into the field on bind closes that window.
             */
            try {
                var current = API.contentOf(editable, textarea);

                if (typeof current === 'string' && current !== '' && textarea.value !== current) {
                    textarea.value = current;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } catch (e) { /* the first bind is enough if this cannot be read */ }

            return true;
        },

        /**
         * Remember which editable was last in use, document-wide.
         *
         * `focusin` rather than `focus` because it bubbles, so one listener covers
         * every editor on the page instead of one per editor. Bound once, at load,
         * and it does nothing when the page has no editor at all.
         */
        trackActiveEditable: function () {
            if (!document.addEventListener) { return; }

            document.addEventListener('focusin', function (event) {
                var node = event.target;

                if (node && node.classList && node.classList.contains('note-editable')) {
                    API.lastFocusedEditable = node;
                }
            }, true);
        },

        /**
         * BIND THE BRIDGE AGAINST AN EDITABLE SUMMERNOTE JUST HANDED US.
         *
         * Takes the editable directly rather than searching for it, because
         * `onCreateEditor` already knows exactly which one it is. `attach()` and this
         * are two doors to the same idempotent routine.
         */
        bindBridgeFor: function (cfg, editable) {
            if (!editable || !cfg) { return false; }

            // `cfg.fieldId` is set in `attach()`; without it there is nothing to
            // resolve, and `attach()` will call `bindBridge()` once it is.
            var area = cfg.fieldId ? API.areaFor(cfg) : null;

            if (!area) { return false; }

            if (editable.getAttribute('data-piie-bridge-ready') === '1') { return true; }

            editable.setAttribute('data-piie-bridge-ready', '1');
            editable.addEventListener('input', function () { API.bridgeToSource(editable); });
            editable.addEventListener('focus', function () { API.lastFocusedEditable = editable; });

            area.removeAttribute('data-piie-bridge-pending');

            return true;
        },

        /** The source textarea this config owns, resolved through its shell. */
        areaFor: function (cfg) {
            var scope = (cfg && cfg.scope) || document;
            var shell = scope.querySelector('.piie-editor-shell[data-for="' + cfg.fieldId + '"]');

            return shell ? shell.querySelector('textarea[data-piie-editor]') : null;
        },

        /**
         * HAS EVERY EDITOR ON THE PAGE GOT A BRIDGE?
         *
         * Read by the exam page before it trusts an answer, because a written answer
         * cannot be saved by an editor that is not wired to the field. Exposed rather
         * than merely logged, because "it looks like it is working" is exactly the
         * failure this whole mechanism exists to prevent.
         */
        bridgeCoverage: function (root) {
            var scope = root || document;
            var areas = scope.querySelectorAll('textarea[data-piie-editor]');
            var wired = 0;
            var missing = [];
            var unmounted = [];
            var unbridged = [];

            Array.prototype.forEach.call(areas, function (area) {
                var shell = area.closest ? area.closest('.piie-editor-shell') : null;
                var editable = shell ? shell.querySelector('.note-editable') : null;

                if (editable && editable.getAttribute('data-piie-bridge-ready') === '1') {
                    wired++;
                    return;
                }

                // Reported in TWO buckets, because they fail differently and a caller
                // needs to tell them apart:
                //
                //   `unmounted` — no editable exists at all. The editor did not start.
                //   `unbridged` — the editable is there and typed into, but nothing is
                //                 forwarding it to the field, so the student's typing is
                //                 invisible to the page's own save routine. This is the
                //                 exam 19 question 3 failure.
                //
                // A field whose editor never mounted is still usable as a plain textarea
                // (the failure path reveals it), so the exam page does not need to alarm
                // about it. A field that IS mounted and unbridged is the dangerous one,
                // because it looks perfect and records nothing.
                var name = area.getAttribute('data-question-id') || area.name || area.id || '(unnamed)';

                if (editable) {
                    unbridged.push(name);
                } else {
                    unmounted.push(name);
                }

                missing.push(name);
            });

            return {
                total: areas.length,
                wired: wired,
                missing: missing,
                unmounted: unmounted,

                // Returned because it is computed for exactly this purpose. The two
                // buckets mean opposite things to the exam page: an `unmounted` field
                // still works as a plain textarea and must not alarm a student, while
                // an `unbridged` field LOOKS perfect and records nothing. A caller that
                // cannot tell them apart can only treat both as fatal, which is how a
                // working paper ends up blocked on a harmless editor failure.
                unbridged: unbridged
            };
        },

        /**
         * IS EVERY EDITOR ON THIS PAGE ACTUALLY MOUNTED AND REACHABLE?
         *
         * Used before a submission to refuse to finalise an attempt while a field the
         * student was told they could type into is not answering.
         */
        readiness: function (root) {
            var scope = root || document;
            var areas = scope.querySelectorAll('textarea[data-piie-editor]');
            var ready = 0;
            var problems = [];

            Array.prototype.forEach.call(areas, function (area) {
                var shell = area.closest ? area.closest('.piie-editor-shell') : null;
                var editable = shell ? shell.querySelector('.note-editable') : null;

                if (editable && editable.getAttribute('data-piie-bridge-ready') === '1') {
                    ready++;
                } else {
                    problems.push({
                        name: area.name || area.id || '(unnamed)',
                        question: area.getAttribute('data-question-id') || null,
                        mounted: !!editable
                    });
                }
            });

            return { total: areas.length, ready: ready, problems: problems };
        },

        /**
         * SYNC ON SUBMIT, IN THE CAPTURE PHASE.
         *
         * Capture rather than bubble so nothing downstream can serialise a stale value
         * first, and bound once per document rather than per form, so a page with several
         * editors needs a single listener.
         */
        installSubmitSync: function () {
            if (! document.addEventListener || API.submitSyncInstalled) { return; }

            API.submitSyncInstalled = true;

            document.addEventListener('submit', function (event) {
                var form = event.target;

                if (! form || ! form.querySelector) { return; }

                // Only forms that actually contain one of our editors.
                if (! form.querySelector('textarea[data-piie-editor]')) { return; }

                API.syncAll(form);
            }, true);

            /**
             * CLICK AND ENTER, FOR A CONTROL THAT IS NOT A PLAIN FORM SUBMIT.
             *
             * `submit` alone covers a submit button and Enter-in-a-field, because the
             * browser serialises only after the submit event completes. It does NOT
             * cover a `<button type="button">` with a scripted handler, or a handler
             * that builds its own FormData - both read the field without any submit
             * event ever firing, which is precisely how a form can post a stale value.
             *
             * Syncing on the interaction that could begin such a request means the
             * field already holds the right value by the time any handler reads it.
             * Every path is idempotent and capture-phase, so overlapping triggers cost
             * nothing and nothing downstream can see a stale value first.
             */
            var syncNearestForm = function (event) {
                var node = event.target;

                if (! node || ! node.closest) { return; }

                var shell = node.closest('.piie-editor-shell');

                if (! shell) { return; }

                var form = shell.closest ? shell.closest('form') : null;

                if (form && form.querySelector('textarea[data-piie-editor]')) {
                    API.syncAll(form);
                }
            };

            document.addEventListener('click', syncNearestForm, true);

            document.addEventListener('keydown', function (event) {
                if (event && (event.key === 'Enter' || event.keyCode === 13)) {
                    syncNearestForm(event);
                }
            }, true);
        },

        /** Attach to every editor the component marked up, then wire their forms. */
        attachAll: function (root) {
            var scope = root || document;
            var fields = scope.querySelectorAll('textarea[data-piie-editor]');

            Array.prototype.forEach.call(fields, function (textarea) {
                var config = {
                    placeholder: textarea.getAttribute('data-placeholder') || null,
                    allowImages: textarea.getAttribute('data-allow-images') === '1',
                    height: parseInt(textarea.getAttribute('data-height') || '360', 10),
                    scope: textarea.closest('.piie-editor-shell') || scope
                };

                // Isolated per field. `attach()` already contains its own vendor call;
                // this second boundary is for the rest of the setup, because a page with
                // one broken editor must still get working editors for every other
                // question on the paper.
                try {
                    API.attach(textarea, config);
                } catch (e) {
                    reportUnavailable(textarea);
                    textarea.setAttribute('data-piie-editor-failed', '1');
                }
            });

            API.wireRecovery(scope);

            // Sync every mounted editor into its source field before ANY form on this
            // page serialises. This is what makes a question statement typed into the
            // lecturer's editor reach the server at all: exam 20 stored '<p><br></p>' for
            // all four question texts because the textarea posted its own markup.
            API.installSubmitSync();
        },

        /**
         * Unsaved-work recovery, OFFERED and never applied silently.
         *
         * A recovered copy replaces what the author wrote without asking, and the
         * author has no way to know which version is now in the field. So the copy
         * is shown with a timestamp and two explicit choices, and doing nothing
         * keeps what is on screen.
         */
        wireRecovery: function (scope) {
            var forms = (scope || document).querySelectorAll('form[data-piie-draft-key]');

            Array.prototype.forEach.call(forms, function (form) {
                if (form.getAttribute('data-piie-recovery-ready') === '1') { return; }
                form.setAttribute('data-piie-recovery-ready', '1');

                var key = form.getAttribute('data-piie-draft-key');
                if (!key || !window.localStorage) { return; }

                var recovery = null;
                try {
                    var raw = window.localStorage.getItem(key);
                    if (raw) { recovery = JSON.parse(raw); }
                } catch (e) { return; }

                if (!recovery || !recovery.saved_at || !recovery.fields) { return; }

                var when = new Date(recovery.saved_at);
                if (isNaN(when.getTime())) { return; }

                // Anything the author has already typed here wins over a snapshot
                // from an earlier visit, so a recovery is never offered over live
                // work.
                var hasLiveWork = Array.prototype.some.call(
                    form.querySelectorAll('textarea[data-piie-editor]'),
                    function (area) { return area.value && area.value.trim() !== '' && area.value.trim() !== '<p><br></p>'; }
                );
                if (hasLiveWork) { return; }

                var box = form.querySelector('[data-piie-recovery]');
                if (!box) { return; }

                box.innerHTML = '<div class="alert alert-warning m-3" role="alert">'
                    + '<strong>Unsaved work was found on this device</strong> (from '
                    + when.toLocaleString() + ').'
                    + '<span class="d-block mt-2">'
                    + '<button type="button" class="btn btn-sm btn-primary" data-piie-recovery-apply>Restore it</button> '
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-piie-recovery-dismiss>Keep what is here</button>'
                    + '</span></div>';
                box.hidden = false;

                var apply = box.querySelector('[data-piie-recovery-apply]');
                var dismiss = box.querySelector('[data-piie-recovery-dismiss]');

                apply.addEventListener('click', function () {
                    Object.keys(recovery.fields).forEach(function (name) {
                        var area = form.querySelector('[name="' + name + '"]');
                        if (!area) { return; }

                        // Through the shared writer, so an author who chooses to restore
                        // sees the restored text rather than a field that silently holds
                        // it while the editor on screen stays empty.
                        API.writeTo(area, recovery.fields[name]);
                    });
                    box.hidden = true;
                });

                dismiss.addEventListener('click', function () {
                    try { window.localStorage.removeItem(key); } catch (e) { /* private mode */ }
                    box.hidden = true;
                });
            });
        },

        /* ── plugin registration, once, at load time ────────────────────────── */
        registerPlugins: function () {
            if (!API.available || !$.summernote || !$.summernote.plugins) { return; }
            if ($.summernote.plugins.piieTable) { return; }

            var ui = $.summernote.ui;

            /* ── table ──────────────────────────────────────────────────────
             * Summernote Lite ships no table dialog of its own. Built through
             * its own API rather than beside it, so the editor still owns the
             * document, and every cell carries a real `scope` so a screen reader
             * announces the column a figure belongs to. */
            $.summernote.plugins.piieTable = function (context) {
                context.addButton('piieTable', 'table', {
                    tooltip: 'Insert a table',
                    icon: '<i class="fa fa-table"></i>',
                    click: function () {
                        var html = ui.dialog({
                            title: 'Insert a table',
                            callback: function (dialogHtml) {
                                var $d = $(dialogHtml);
                                var rows = parseInt($d.find('[name="rows"]').val(), 10) || 2;
                                var cols = parseInt($d.find('[name="cols"]').val(), 10) || 2;

                                rows = Math.min(Math.max(rows, 1), 30);
                                cols = Math.min(Math.max(cols, 1), 12);

                                var out = '<table><thead><tr>';
                                for (var c = 0; c < cols; c++) {
                                    out += '<th scope="col">Heading ' + (c + 1) + '</th>';
                                }
                                out += '</tr></thead><tbody>';
                                for (var r = 0; r < rows; r++) {
                                    out += '<tr>';
                                    for (var k = 0; k < cols; k++) { out += '<td>Cell</td>'; }
                                    out += '</tr>';
                                }
                                return out + '</tbody></table>';
                            }
                        });

                        if (html) { context.invoke('insertNode', $.parseHTML(html)[0]); }
                    }
                });
            };

            /* ── sub / superscript ──────────────────────────────────────────
             * `document.execCommand` is deprecated and absent in some embedded
             * webviews, so the wrapper falls back to wrapping the selection in
             * the element directly. Either way the result is a plain <sub> or
             * <sup>, which is what the sanitizer allows. */
            function subSuper(command) {
                return function (context) {
                    context.addButton(command, command, {
                        tooltip: command === 'superscript' ? 'Superscript' : 'Subscript',
                        icon: command === 'superscript'
                            ? '<i class="fa fa-superscript"></i>'
                            : '<i class="fa fa-subscript"></i>',
                        click: function () {
                            if (document.queryCommandSupported && document.queryCommandSupported(command)) {
                                document.execCommand(command, false, null);
                                return;
                            }

                            var selection = window.getSelection();
                            if (!selection || selection.isCollapsed) { return; }

                            var range = selection.getRangeAt(0);
                            var element = document.createElement(command === 'superscript' ? 'sup' : 'sub');
                            element.appendChild(range.extractContents());
                            range.insertNode(element);
                            selection.removeAllRanges();
                        }
                    });
                };
            }

            $.summernote.plugins.superscript = subSuper('superscript');
            $.summernote.plugins.subscript = subSuper('subscript');

            /* ── mathematical symbols ──────────────────────────────────────
             * A plain overlay rather than a Summernote dialog, because a symbol
             * picker is a GRID of targets and Summernote's dialog contract only
             * returns a string from a text field. The overlay is not an editor
             * and does not own a caret: it inserts through the editor's own API,
             * so undo, the toolbar and the sanitized round-trip all still work
             * exactly as they do for any other insertion. */
            $.summernote.plugins.piieSymbol = function (context) {
                context.addButton('piieSymbol', 'piieSymbol', {
                    tooltip: 'Mathematical symbols',
                    icon: '<i class="fa fa-calculator"></i>',
                    click: function () { API.openSymbolPicker(context); }
                });
            };

            /* ── equation notation ──────────────────────────────────────────
             * Stores LaTeX and a plain-text fallback. Nothing is executed, and
             * the server allowlist permits exactly `span[data-latex]` and
             * `span[data-equation]` and nothing else, so the notation cannot
             * become an injection point. The fallback is what a reader without a
             * renderer sees, which is why it is required rather than optional. */
            $.summernote.plugins.piieEquation = function (context) {
                context.addButton('piieEquation', 'piieEquation', {
                    tooltip: 'Insert an equation',
                    icon: '<i class="fa fa-superscript"></i>',
                    click: function () {
                        var html = ui.dialog({
                            title: 'Insert an equation',
                            callback: function (dialogHtml) {
                                var $d = $(dialogHtml);
                                var latex = ($d.find('[name="latex"]').val() || '').trim();
                                if (!latex) { return ''; }

                                var fallback = ($d.find('[name="fallback"]').val() || '').trim();
                                if (!fallback) { fallback = latex; }

                                var escape = function (s) {
                                    return String(s)
                                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                };

                                return '<span data-latex="' + escape(latex) + '">'
                                    + escape(fallback) + '</span>';
                            }
                        });

                        if (html) { context.invoke('insertNode', $.parseHTML(html)[0]); }
                    }
                });
            };
        },

        /* ── the symbol overlay ───────────────────────────────────────────── */
        openSymbolPicker: function (context) {
            if (document.querySelector('.piie-symbol-overlay')) { return; }

            var overlay = document.createElement('div');
            overlay.className = 'piie-symbol-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', 'Mathematical symbols');

            var groups = [];
            var current = null;
            API.MATH_SYMBOLS.forEach(function (entry) {
                if (entry.group) { current = { title: entry.group, items: [] }; groups.push(current); return; }
                if (current) { current.items.push(entry); }
            });

            var body = groups.map(function (group) {
                var buttons = group.items.map(function (symbol) {
                    return '<button type="button" class="piie-symbol" data-entity="' + symbol.e
                        + '" title="' + symbol.t + '" aria-label="' + symbol.t + '">'
                        + symbol.g + '</button>';
                }).join('');
                return '<section class="piie-symbol-group"><h3>' + group.title + '</h3>'
                    + '<div class="piie-symbol-grid">' + buttons + '</div></section>';
            }).join('');

            overlay.innerHTML = '<div class="piie-symbol-panel">'
                + '<header><h2>Mathematical symbols</h2>'
                + '<button type="button" class="piie-symbol-close" aria-label="Close">&times;</button></header>'
                + '<div class="piie-symbol-body">' + body + '</div></div>';

            document.body.appendChild(overlay);

            var close = function () {
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                document.removeEventListener('keydown', onKey);
            };

            var onKey = function (event) {
                if (event.key === 'Escape') { close(); }
            };

            overlay.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('.piie-symbol') : null;
                if (button) {
                    // Inserted through the editor's own API, so the caret, the
                    // undo stack and the sanitized round-trip all behave exactly
                    // as they do for any other insertion.
                    var entity = button.getAttribute('data-entity');
                    var glyph = document.createElement('span');
                    glyph.innerHTML = entity;

                    context.invoke('editor.insertNode', glyph.firstChild);
                    close();
                    return;
                }

                if (event.target === overlay || event.target.closest('.piie-symbol-close')) { close(); }
            });

            document.addEventListener('keydown', onKey);

            var first = overlay.querySelector('.piie-symbol');
            if (first) { first.focus(); }
        }
    };

    // NOT registered here. Registration needs Summernote to be present, and at
    // parse time it may not have been executed yet - so start() does it, once
    // the boot sequence has confirmed Summernote is really on the page.

    window.PIIEAademicEditor = API;

    /* Boot on DOMContentLoaded, and immediately if the document is already
     * parsed - which it is when this file is loaded with `defer` off and placed
     * at the end of a body, and is NOT when a view is rendered into an already
     * live page. Both are handled so neither can silently do nothing. */
    function boot() {
        // WAIT FOR SUMMERNOTE, BRIEFLY, BEFORE DECIDING IT IS MISSING.
        //
        // This script registers its plugins and initialises the editors as soon as
        // it runs, so if it happens to run before Summernote is on the page it would
        // find nothing to register against and fall back - silently, on a page where
        // everything was in fact available. Document order normally prevents that,
        // but a promise about document order is one a page author can break, and a
        // fallback that fires because of a race is not a fallback.
        //
        // The wait is bounded and short. A student should never be looking at an
        // empty box waiting for a script, and a genuinely absent editor has to be
        // reported rather than retried forever.
        if (summernoteIsReady()) {
            start();
            return;
        }

        var waited = 0;
        var every = 40;
        var limit = 3000;   // 3 seconds. Generous for a local asset, far too long
                            // to be mistaken for a broken page.

        var poll = window.setInterval(function () {
            waited += every;

            if (summernoteIsReady()) {
                window.clearInterval(poll);
                start();

                return;
            }

            if (waited >= limit) {
                window.clearInterval(poll);
                start();
            }
        }, every);
    }

    function summernoteIsReady() {
        return !!(window.jQuery
            && window.jQuery.fn
            && typeof window.jQuery.fn.summernote === 'function'
            && window.jQuery.summernote
            && window.jQuery.summernote.plugins);
    }

    function start() {
        // The plugins can only be registered once Summernote is actually present,
        // so this is where registration belongs - and `registerPlugins()` is
        // idempotent, so being reached twice is harmless.
        API.registerPlugins();

        // THE PROGRESSIVE-ENHANCEMENT FLAG.
        //
        // The stylesheet hides the source textarea only under `html.piie-js`, and
        // this class is added only when the editor has genuinely initialised. So
        // a browser that never runs this script keeps a visible, working,
        // submitting textarea instead of an empty box with a dead toolbar.
        //
        // Added only when Summernote really is here. Adding it on a page where the
        // editor failed would hide a perfectly good textarea and leave the reader
        // with nothing.
        if (summernoteIsReady()) {
            API.available = true;
            document.documentElement.classList.add('piie-js');
        } else {
            API.available = false;
        }

        API.attachAll(document);
    }

    /**
     * ####################################################################
     * #  THE SUBMIT SYNC - the fix for "I typed the question and it       #
     * #  said there was no text"                                          #
     * ####################################################################
     *
     * `API.syncAll` has existed since this file was written, and its own comment
     * describes calling it "on `submit`". It was never called: no view referenced
     * it, and nothing in this file bound it. The only thing keeping the textarea in
     * step with the editor was Summernote's own per-keystroke internal sync.
     *
     * That is not a guarantee, and the failure it produces is the worst kind for an
     * exam or an assignment:
     *
     *   - a lecturer TYPES a question, sees it in the editor, presses Save;
     *   - the textarea has not been re-read yet, so the POST body carries the old
     *     value - usually empty;
     *   - the server correctly refuses it: "Write the question. A question with no
     *     text is not a question.";
     *
     * Nothing on the page says the text was lost. The lecturer's own words are gone
     * from the request, and the message points at the QUESTION rather than at the
     * field that dropped it. This was reported live.
     *
     * So the wiring the comment promised is added here, as a CAPTURE-phase listener
     * on the document:
     *
     *   - CAPTURE, because a page's own handler may `preventDefault()` in the
     *     bubble phase to validate first. Capture runs before it, so the value is
     *     already correct by the time any other handler sees the form;
     *   - on `document`, not per form, because the editor is mounted on a dozen
     *     pages whose forms are written by different authors, and a listener bound
     *     to a form that has not been wired yet would simply never fire.
     *
     * It is a no-op for any field that has nothing to copy — `syncAll()` writes
     * only what it can read, and leaves the field's own value alone otherwise — so
     * the plain-textarea fallback is unaffected.
     *
     * `snapshotForm()` below reads through `codeFor()`, which asks the editable
     * region first, so a page's own save-and-restore logic gets the same answer
     * this does.
     */
    function wireSubmitSync() {
        // Registered HERE, at module load, rather than only from `attachAll()`.
        //
        // The listener is idempotent, so `attachAll()` calling it again costs
        // nothing, and registering it this early means the guarantee does not
        // depend on Summernote having loaded — if the editor failed to mount, the
        // field's own value is still what must be posted, and nothing should be
        // able to serialise ahead of the sweep.
        API.installSubmitSync();
    }

    if (document.addEventListener) {
        wireSubmitSync();
        API.trackActiveEditable();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(window, document));
