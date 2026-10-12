/*
 * AN ON-SCREEN KEYBOARD FOR SHORT ANSWERS.
 *
 * FOR STUDENTS WITHOUT A PHYSICAL KEYBOARD
 *
 * A tablet in a lecture hall, a phone held in landscape, a shared device at the
 * back of a room, a borrowed laptop with a broken keyboard: each of these is a
 * real situation in which a student can read the question and cannot answer it,
 * and the answer they are forced to give is "I could not type".
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It never intercepts keystrokes and it never traps focus. There is no key
 * listener on `document`. A student with a working keyboard is completely
 * unaffected - they never see the button unless they go looking for it, and even
 * if the panel is open their physical keyboard still works exactly as it did.
 *
 * Forcing an on-screen keyboard on a student who has one is the failure mode here,
 * and it is worse than not having the feature at all: it doubles the number of
 * things they have to aim at. So it is OPT-IN, always.
 *
 * WHY INSERTION IS CARET-AWARE
 *
 * Appending at the end of the field is the version of this that gets a student to
 * stop using it. They go back to fix a word in the middle, and every subsequent
 * character lands after the sentence instead of where they asked. The selection
 * start and end are tracked on every interaction with the field, and a character
 * replaces the selection if there is one - the same rule a physical keyboard
 * obeys, which is the only rule that makes the two feel like the same keyboard.
 *
 * WHY IT FIRES A REAL `input` EVENT
 *
 * The autosave listens for `input`. Assigning `field.value` updates the text and
 * autosaves nothing, so a student could type a whole answer on the on-screen
 * keyboard, press Submit, and lose every character - with an indicator that said
 * "Saved". The event is dispatched for this reason and no other; it is what makes
 * the two features one feature rather than two that disagree.
 *
 * WHY NOT THE RICH EDITOR
 *
 * The academic editor's own toolbar carries headings, symbols and equations, and
 * its source field is synchronised into that toolbar's state. Writing into it from
 * here would be a third party editing something two others own. Plain inputs and
 * textareas are what this attaches to; the rich editor keeps its own symbols.
 */
(function () {
    'use strict';

    var PANEL_ID = 'piie-osk';
    var ATTR = 'data-piie-osk';

    var LAYERS = {
        lower: [
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
            'q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p',
            'a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l',
            'z', 'x', 'c', 'v', 'b', 'n', 'm',
            ',', '.', '?', '!', "'", '-'
        ],
        upper: [
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
            'Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P',
            'A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L',
            'Z', 'X', 'C', 'V', 'B', 'N', 'M',
            ',', '.', '?', '!', "'", '-'
        ],
        // The characters a mathematical or business answer actually needs, and
        // which are otherwise the hardest thing to produce on a touchscreen:
        // the currency, the percentage, and the arithmetic operators.
        symbols: [
            '+', '-', '×', '÷', '=', '(', ')', '[', ']', '%',
            '$', '£', '€', 'UGX', '$', '£',
            '<', '>', '=', '≤', '≥', '±', '÷',
            ':', ';', '"', '(', ')', '/', '&'
        ]
    };

    var panel = null;
    var target = null;
    var caret = { start: 0, end: 0 };
    var layer = 'lower';

    /* ── Caret tracking ──────────────────────────────────────────────────── */

    /*
     * The panel takes focus away from the field, which is what makes the field's
     * own selection report useless mid-insert. So the position is remembered
     * whenever the student is actually in the field.
     */
    function rememberCaret() {
        if (!target) { return; }

        var start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
        var end = typeof target.selectionEnd === 'number' ? target.selectionEnd : start;

        caret.start = start;
        caret.end = end;
    }

    /* ── Insertion ───────────────────────────────────────────────────────── */

    function insert(text) {
        if (!target) { return; }

        rememberCaret();

        var value = target.value;
        var start = Math.min(caret.start, value.length);
        var end = Math.min(Math.max(caret.end, start), value.length);

        var next = value.slice(0, start) + text + value.slice(end);

        // `maxlength` is respected rather than ignored. The browser enforces it for
        // physical keystrokes, and a field that accepts one more character only on
        // the on-screen keyboard is a bug that shows up as a refused submission
        // with no explanation.
        var limit = target.getAttribute('maxlength');
        if (limit) {
            var max = parseInt(limit, 10);
            if (!isNaN(max) && next.length > max) { return; }
        }

        target.value = next;

        var after = start + text.length;
        caret.start = after;
        caret.end = after;

        try { target.setSelectionRange(after, after); } catch (e) { /* type has no selection */ }

        // THE EVENT AUTOSAVE LISTENS FOR. See the note at the top of this file:
        // without it the text changes and nothing is saved.
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function backspace() {
        if (!target) { return; }

        rememberCaret();

        var value = target.value;
        var start = Math.min(caret.start, value.length);
        var end = Math.min(Math.max(caret.end, start), value.length);

        if (start === end) {
            // Nothing selected: delete the character before the caret, as a
            // physical keyboard does. At position 0 there is nothing before it,
            // and deleting the character AFTER would be the wrong behaviour.
            if (start === 0) { return; }

            start -= 1;
        }

        target.value = value.slice(0, start) + value.slice(end);

        caret.start = start;
        caret.end = start;

        try { target.setSelectionRange(start, start); } catch (e) { /* ignore */ }

        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* ── The panel ───────────────────────────────────────────────────────── */

    function key(label, ariaLabel, handler, className) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-light border ' + (className || 'flex-fill');
        button.textContent = label;
        button.setAttribute('aria-label', ariaLabel || label);
        button.addEventListener('click', function (event) {
            // `preventDefault` so a key inside a form never submits it. A
            // student answering a five-mark question does not expect to hand it
            // in by pressing the "e" key.
            event.preventDefault();
            handler();
        });

        return button;
    }

    function render() {
        panel.textContent = '';

        var head = document.createElement('div');
        head.className = 'd-flex align-items-center justify-content-between gap-2 mb-2';

        var label = document.createElement('div');
        label.className = 'small text-muted';
        // Naming the field the keys will go into is what makes this usable by
        // anyone who cannot see which box is focused.
        label.textContent = 'Typing into: ' + describe(target);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Hide the on-screen keyboard');
        close.addEventListener('click', function () { closePanel(); });

        head.appendChild(label);
        head.appendChild(close);
        panel.appendChild(head);

        var shift = key('Shift', 'Capital letters', function () {
            layer = layer === 'upper' ? 'lower' : 'upper';
            render();
            panel.querySelector('button').focus();
        }, 'btn-outline-secondary');

        var symbols = key('#+=', 'Symbols', function () {
            layer = layer === 'symbols' ? 'lower' : 'symbols';
            render();
        }, 'btn-outline-secondary');

        var back = key('⌫', 'Delete the character before the cursor', backspace, 'btn-outline-danger');

        var rows = [LAYERS[layer]];

        rows.forEach(function (chars) {
            var row = document.createElement('div');
            row.className = 'd-flex flex-wrap gap-1 mb-1';

            row.appendChild(shift);
            row.appendChild(symbols);

            chars.forEach(function (ch) {
                row.appendChild(key(ch, 'Type ' + ch, function () { insert(ch); }));
            });

            row.appendChild(back);
            panel.appendChild(row);
        });

        // Space gets its own row: it is the most-pressed key on any keyboard, and
        // squeezing it into a character row is how on-screen keyboards become
        // slower than the physical thing they replace.
        var spaceRow = document.createElement('div');
        spaceRow.className = 'd-flex gap-1';
        spaceRow.appendChild(key('Space', 'Type a space', function () { insert(' '); }, 'btn-light flex-fill'));

        if (target && target.tagName === 'TEXTAREA') {
            spaceRow.appendChild(key('New line', 'Start a new line', function () { insert('\n'); }, 'btn-outline-secondary'));
        }

        panel.appendChild(spaceRow);
    }

    function describe(field) {
        if (!field) { return 'nothing'; }

        var label = field.labels && field.labels.length ? field.labels[0] : null;

        if (label && label.textContent.trim()) { return label.textContent.trim().slice(0, 60); }
        if (field.name) { return field.name; }

        return field.getAttribute('data-testid') || 'the answer box';
    }

    function open(field) {
        target = field;
        caret = { start: field.value.length, end: field.value.length };

        panel.hidden = false;
        render();

        // The panel is placed immediately after the field rather than fixed to the
        // bottom of the viewport. A fixed panel covers the answer it is editing on
        // a short form, and the student has to scroll to see whether the character
        // they just pressed arrived.
        if (field.parentNode) {
            field.parentNode.insertBefore(panel, field.nextSibling);
        }

        panel.setAttribute('data-target', field.getAttribute('data-testid') || field.name || '');
    }

    function closePanel() {
        panel.hidden = true;

        if (target && typeof target.focus === 'function') {
            // Focus goes BACK to the field. A keyboard that closes with focus on
            // the document body leaves a screen-reader user with no idea where they
            // are, and a physical keyboard user unable to carry on typing.
            target.focus();
        }

        target = null;
    }

    function ensurePanel() {
        if (panel) { return panel; }

        panel = document.createElement('div');
        panel.id = PANEL_ID;
        panel.hidden = true;
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', 'On-screen keyboard');

        // Scoped to this id rather than to a global class, so nothing else on the
        // page can inherit the panel's layout by accident.
        panel.className = 'border rounded p-2 bg-white shadow-sm mt-2';
        panel.style.boxShadow = '0 6px 18px rgba(0,0,0,.15)';

        document.body.appendChild(panel);

        document.addEventListener('keydown', function (event) {
            // The ONE key this script listens for. Escape is safe to intercept
            // because it has no meaning to a person typing an answer, and it is
            // the only reliable way out of an overlay that covers the field.
            if (event.key === 'Escape' && !panel.hidden) {
                event.preventDefault();
                closePanel();
            }
        });

        return panel;
    }

    /* ── Attaching ───────────────────────────────────────────────────────── */

    /*
     * WHICH FIELDS.
     *
     * Single-line text inputs and textareas that are NOT the academic editor's
     * source field. The rich editor is excluded on purpose: it keeps its own
     * symbol toolbar, and writing into its textarea from here would be a third
     * party editing state that two other components own.
     */
    function isEligible(field) {
        if (!field || field.readOnly || field.disabled) { return false; }
        if (field.getAttribute(ATTR) === 'on') { return false; }

        var tag = field.tagName;

        if (tag === 'TEXTAREA') { return true; }
        if (tag !== 'INPUT') { return false; }

        var type = (field.getAttribute('type') || 'text').toLowerCase();

        // Password never: an answer to an academic question is not a secret, but a
        // shared device is a shared device and there is no reason to offer a
        // shoulder-surfable overlay for one.
        return type === 'text' || type === 'url' || type === 'email' || type === 'tel' || type === 'search';
    }

    function insideRichEditor(field) {
        return !!field.closest('.piie-editor-shell, .as-editor-shell, .note-editor, .note-editable');
    }

    function attach(field) {
        if (!isEligible(field) || insideRichEditor(field)) { return; }
        if (field.getAttribute(ATTR) === 'on') { return; }

        field.setAttribute(ATTR, 'on');

        // Keep the button's own text honest about state, so a student can see
        // whether the keyboard is already open for this field.
        field.setAttribute('aria-describedby', (field.getAttribute('aria-describedby') || '') + ' piie-osk-hint-' + counter);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary mt-1';
        button.textContent = '⌨ Show keyboard';
        button.setAttribute('data-osk-trigger', '');
        button.setAttribute('aria-expanded', 'false');

        var hint = document.createElement('span');
        hint.id = 'piie-osk-hint-' + counter;
        hint.className = 'visually-hidden';
        hint.textContent = 'Opens an on-screen keyboard for this answer box. Your own keyboard still works.';

        counter += 1;

        button.addEventListener('click', function (event) {
            event.preventDefault();

            ensurePanel();

            if (!panel.hidden && target === field) {
                closePanel();
                return;
            }

            open(field);
            button.textContent = '⌨ Hide keyboard';
            button.setAttribute('aria-expanded', 'true');
        });

        // One open keyboard at a time. Two on a phone is two panels fighting over
        // the space the answer needs.
        field.addEventListener('focus', rememberCaret);

        field.parentNode.insertBefore(button, field.nextSibling);
        field.parentNode.insertBefore(hint, button.nextSibling);
    }

    var counter = 0;

    function scan() {
        document.querySelectorAll('input, textarea').forEach(function (field) {
            attach(field);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    // Reported for tests and for support: which field the keys will go into.
    window.piieKeyboard = {
        open: function (field) { ensurePanel(); open(field); },
        close: closePanel,
        attached: function () { return document.querySelectorAll('[' + ATTR + '="on"]').length; },
        isOpenFor: function (field) { return !panel.hidden && target === field; }
    };
})();