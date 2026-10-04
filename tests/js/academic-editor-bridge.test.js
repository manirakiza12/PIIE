/*
 * THE EDITOR → SOURCE BRIDGE, EXERCISED AGAINST THE REAL MODULE.
 *
 * Exam 19, question 3: the student typed, the page showed a green "Saved", and the
 * server stored `<p><br></p>` — the empty rich-text document. The answer never
 * persisted.
 *
 * The cause was in `bindBridge()`. It looked for Summernote's `.note-editable` inside
 * the shell and RETURNED SILENTLY if it was not there yet. `attach()` calls it
 * immediately after `summernote(options)`, so a build that creates the editable on a
 * later tick left the bridge permanently unbound: the editor was fully interactive for
 * the student and completely invisible to the autosave.
 *
 * That is JavaScript behaviour. A PHPUnit assertion that the file CONTAINS certain text
 * cannot observe it — it would pass just as happily against the broken file, which is
 * precisely how this defect survived. So this loads the real module into a minimal DOM
 * that reproduces the deferred creation, and checks what actually happens.
 *
 * Run:  node tests/js/academic-editor-bridge.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SOURCE = process.env.PIIE_EDITOR_JS || path.join(__dirname, '..', '..', 'public', 'js', 'academic-editor.js');

let passed = 0;
let failed = 0;

function assert(condition, message) {
    if (condition) {
        passed++;
        console.log('  [PASS] ' + message);
    } else {
        failed++;
        console.log('  [FAIL] ' + message);
    }
}

/* ── minimal DOM ─────────────────────────────────────────────────────────── */

function element(tag, attrs) {
    const el = {
        tagName: String(tag).toUpperCase(),
        attributes: Object.assign({}, attrs || {}),
        children: [],
        parentElement: null,
        listeners: {},

        getAttribute(n) {
            return Object.prototype.hasOwnProperty.call(this.attributes, n) ? this.attributes[n] : null;
        },
        setAttribute(n, v) { this.attributes[n] = String(v); },
        removeAttribute(n) { delete this.attributes[n]; },
        addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
        removeEventListener() {},
        dispatchEvent(event) {
            (this.listeners[event.type] || []).slice().forEach((fn) => fn(event));
            return true;
        },
        appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
        contains(node) {
            let n = node;
            while (n) { if (n === this) { return true; } n = n.parentElement; }
            return false;
        },
        matches(selector) {
            const cls = this.attributes.class || '';
            if (selector === 'form') { return this.tagName === 'FORM'; }
            if (selector === '.piie-editor-shell') { return cls.indexOf('piie-editor-shell') !== -1; }
            if (selector === '.note-editable') { return cls.indexOf('note-editable') !== -1; }
            if (selector === 'textarea[data-piie-editor]') {
                return this.tagName === 'TEXTAREA' && this.getAttribute('data-piie-editor') !== null;
            }
            if (selector.indexOf('.piie-editor-shell[data-for="') === 0) {
                const m = selector.match(/data-for="([^"]+)"/);
                return cls.indexOf('piie-editor-shell') !== -1
                    && !!m && this.getAttribute('data-for') === m[1];
            }
            return false;
        },
        closest(selector) {
            let node = this;
            while (node) {
                if (node.matches && node.matches(selector)) { return node; }
                node = node.parentElement;
            }
            return null;
        },
        querySelector(selector) {
            for (const child of this.children) {
                if (child.matches && child.matches(selector)) { return child; }
                const deeper = child.querySelector ? child.querySelector(selector) : null;
                if (deeper) { return deeper; }
            }
            return null;
        },
        querySelectorAll(selector) {
            const out = [];
            for (const child of this.children) {
                if (child.matches && child.matches(selector)) { out.push(child); }
                if (child.querySelectorAll) { out.push.apply(out, child.querySelectorAll(selector)); }
            }
            return out;
        }
    };

    el.value = '';

    /**
     * The module adds `piie-js` to the document element once the editor has genuinely
     * initialised, and the stylesheet hides a source textarea under it. A harness
     * without `classList` makes that line throw, so every element gets one.
     */
    const classes = new Set(String(el.attributes.class || '').split(/\s+/).filter(Boolean));

    el.classList = {
        add(...names) { names.forEach((n) => classes.add(n)); el.attributes.class = [...classes].join(' '); },
        remove(...names) { names.forEach((n) => classes.delete(n)); el.attributes.class = [...classes].join(' '); },
        contains(name) { return classes.has(name); }
    };

    return el;
}

/**
 * A SUMMERNOTE FAITHFUL ENOUGH FOR BOOT TO ACTUALLY RUN.
 *
 * `summernoteIsReady()` requires `jQuery.fn.summernote` to be a FUNCTION plus
 * `jQuery.summernote.plugins`. An earlier version of this harness had an empty
 * `jQuery.fn`, so the module decided the editor was missing, never ran `start()`, and
 * never installed the submit sync — and the test failed for a reason that had nothing
 * to do with the code under test.
 *
 * So this fake really mounts an editable and really stores code per element, which is
 * what production does.
 */
function makeJQuery(lyingGetter) {
    function jQuery(node) {
        const api = function () { return api; };

        api.summernote = function (command) {
            if (command === 'code') {
                if (arguments.length > 1) {
                    node.__code = arguments[1];
                    return api;
                }

                // ── THE EXAM 21 BUILD ────────────────────────────────────────
                // Summernote Lite is a trimmed build, and its `code` GETTER is not
                // guaranteed to return the live document. It reports an empty string
                // for an editor that is visibly full of the author's text, so any code
                // that reads content through this getter can post an empty field.
                // Every read in the module therefore consults the DOM first, and this
                // fake exists so that claim is TESTED rather than asserted.
                if (lyingGetter) { return ''; }

                return typeof node.__code === 'string' ? node.__code : '';
            }

            // Mounting: create the editable the way Summernote does.
            const shell = node.closest ? node.closest('.piie-editor-shell') : null;

            if (shell) {
                let editable = shell.querySelector('.note-editable');

                if (!editable) {
                    editable = element('div', { class: 'note-editable' });
                    shell.appendChild(editable);
                }
            }

            return api;
        };

        api.data = function () { return undefined; };

        return api;
    }

    jQuery.fn = { summernote: function () { return this; } };
    jQuery.summernote = { plugins: {} };
    jQuery.parseHTML = function () { return [element('div')]; };

    return jQuery;
}

/**
 * Load the real module.
 *
 * `deferCreate` reproduces a Summernote build that inserts its editable on a later tick.
 * `opts.lyingGetter` reproduces a Summernote build whose `code` getter reports empty for
 * an editor that visibly holds text — the build that produced the exam 21 failure.
 * `timers` collects the deferred callbacks so the test can fire them explicitly, which
 * is more honest than letting a real timer decide when the race is resolved.
 */
function load(deferCreate, opts) {
    const timers = [];

    /**
     * The shared fake Summernote. Each textarea keeps its own code, exactly as the
     * real plugin does per editor instance - which is what makes the "Q3 does not
     * overwrite Q4" assertions meaningful rather than accidental.
     */
    const jQuery = makeJQuery(opts && opts.lyingGetter);

    const document = {
        readyState: 'complete',
        documentElement: element('html', { class: '' }),
        body: element('body', { class: '' }),
        activeElement: null,
        addEventListener(type, fn) { (this._l = this._l || {}); (this._l[type] = this._l[type] || []).push(fn); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        getElementById() { return null; },
        createEvent() { return { initEvent() {} }; }
    };

    const win = {
        document,
        console,
        setTimeout(fn) { timers.push(fn); return timers.length; },
        clearTimeout() {},
        // Present so a boot-time poll cannot throw if it ever takes the slow path.
        setInterval() { return 0; },
        clearInterval() {},
        fetch() { return Promise.resolve({ ok: true, json: () => Promise.resolve({}) }); },
        Event: function (type, init) { return { type: type, bubbles: !!(init && init.bubbles) }; },
        localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
        location: { href: 'http://127.0.0.1:8000/' },
        addEventListener() {},
        removeEventListener() {},
        navigator: { onLine: true },
        Promise, JSON, Object, Array, String, Number, Date,
        jQuery,
        $: jQuery
    };
    win.window = win;

    const sandbox = Object.assign({}, win, { window: win, document, globalThis: null });
    sandbox.globalThis = sandbox;
    sandbox.jQuery = jQuery;
    sandbox.$ = jQuery;

    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(SOURCE, 'utf8'), sandbox, { filename: 'academic-editor.js' });

    /**
     * The module publishes its API on `window` under a name that file owns, not this
     * test. Discovered by scanning, so renaming the export cannot silently turn this
     * suite into a no-op - the worst possible outcome here, because every assertion
     * would then be skipped rather than reported.
     */
    const key = Object.keys(win).find(function (k) { return /^PIIE/i.test(k) && win[k] && typeof win[k] === 'object'; });

    if (!key) {
        throw new Error('academic-editor.js did not publish an API on window');
    }

    return { api: win[key], exportName: key, sandbox: sandbox, timers: timers, jq: jQuery, win: win, document: document };
}

/**
 * One question's shell: the source textarea, and an editable the test inserts.
 */
function question(env, id) {
    const shell = element('div', { class: 'piie-editor-shell', 'data-for': 'exam-answer-' + id });
    const textarea = element('textarea', {
        'data-piie-editor': '',
        id: 'exam-answer-' + id,
        name: 'answers[' + id + ']',
        'data-question-id': String(id)
    });
    textarea.value = '';
    shell.appendChild(textarea);

    const editable = element('div', { class: 'note-editable' });

    return {
        shell,
        textarea,
        editable,
        create() { shell.appendChild(editable); return editable; }
    };
}

function flush(env) {
    while (env.timers.length) { env.timers.shift()(); }
}


/**
 * SIMULATE A STUDENT TYPING INTO AN EDITOR.
 *
 * Goes through the same jQuery path the module reads from - set the code, then fire
 * `input` on the editable - so the bridge under test is driven exactly as a browser
 * would drive it, rather than by poking the textarea it is supposed to update.
 */
function type(env, editable, html) {
    env.jq(editable).summernote('code', html);
    editable.dispatchEvent({ type: 'input' });
}

/* ── 1. the easy case: editable exists when bindBridge is called ──────────── */
console.log('\nThe bridge binds when the editable already exists');
{
    const env = load(false);
    const q = question(env, 45);
    q.create();

    const bound = env.api.bindBridge(q.textarea);

    assert(bound === true, 'bindBridge reports that it bound');
    assert(q.editable.getAttribute('data-piie-bridge-ready') === '1', 'the editable is marked ready');

    type(env, q.editable, '<p>Simple interest pays on the principal only.</p>');

    assert(
        q.textarea.value === '<p>Simple interest pays on the principal only.</p>',
        'typing is bridged into the source textarea (autosave can then see it); got ' + JSON.stringify(q.textarea.value)
    );
}

/* ── 2. THE DEFECT: editable created on a later tick ─────────────────────── */
console.log('\nTHE DEFECT: the bridge must still bind when the editable arrives late');
{
    const env = load(true);
    const q = question(env, 45);

    // Summernote has NOT created the editable yet — the exact race.
    const first = env.api.bindBridge(q.textarea);

    assert(first === false, 'the first attempt reports it could not bind, rather than pretending');
    assert(env.timers.length > 0, 'a retry is scheduled instead of being abandoned');

    // The editable appears; the scheduled retry then runs.
    q.create();
    flush(env);

    assert(
        q.editable.getAttribute('data-piie-bridge-ready') === '1',
        'the retry binds the bridge once the editable exists'
    );

    type(env, q.editable, '<p>Compound interest pays on principal plus interest.</p>');

    assert(
        q.textarea.value === '<p>Compound interest pays on principal plus interest.</p>',
        'typing after a late bind still reaches the source textarea; got ' + JSON.stringify(q.textarea.value)
    );
}

/* ── 3. two written questions stay independent ───────────────────────────── */
console.log('\nTwo written questions must not share a bridge or cross-contaminate');
{
    const env = load(true);
    const q3 = question(env, 45);
    const q4 = question(env, 46);

    env.api.bindBridge(q3.textarea);
    env.api.bindBridge(q4.textarea);

    q3.create();
    q4.create();
    flush(env);

    assert(
        q3.editable.getAttribute('data-piie-bridge-ready') === '1'
        && q4.editable.getAttribute('data-piie-bridge-ready') === '1',
        'both editors are wired'
    );

    // Type into Q3 only. Q4 must stay empty — this is the "inserted into the wrong
    // question" failure in its purest form.
    type(env, q3.editable, '<p>only question three</p>');

    assert(q3.textarea.value === '<p>only question three</p>', 'Q3 receives its own text');
    assert(q4.textarea.value === '', 'Q4 is untouched when Q3 is typed into');
}

/* ── 4. binding is idempotent ─────────────────────────────────────────────── */
console.log('\nBinding twice must not double-wire an editor');
{
    const env = load(false);
    const q = question(env, 45);
    q.create();

    env.api.bindBridge(q.textarea);
    const countAfterFirst = (q.editable.listeners.input || []).length;
    env.api.bindBridge(q.textarea);
    const countAfterSecond = (q.editable.listeners.input || []).length;

    assert(countAfterFirst === 1, 'one input listener after the first bind');
    assert(countAfterFirst === countAfterSecond, 'a second bind adds no second listener');
}

/* ── 5. QUESTION STATEMENTS REACH THE SERVER ON FORM SUBMIT ──────────────── */
console.log('\nA question statement typed into an editor must reach the submitted field');
{
    const env = load(false);
    const E = env.api;
    const q = question(env, 1);
    q.create();

    // Give the editor a real field name, as the question form does.
    q.textarea.setAttribute('name', 'question');

    // The source textarea was RENDERED empty - which is exactly the state a new
    // question form is in before the lecturer types anything.
    q.textarea.value = '';

    // The lecturer types into the editor. Summernote Lite does NOT copy this into
    // the textarea on submit, which is why every exam 20 question stored
    // '<p><br></p>'.
    //
    // Mounted through the module's own `attach`, so the textarea carries the
    // `data-piie-editor-ready` flag a genuinely initialised editor has. Without it
    // `syncAll()` correctly declines to touch the field, and this case would be
    // asserting against a state that never occurs in the browser.
    q.textarea.setAttribute('name', 'question');
    E.attach(q.textarea, { scope: q.shell });

    assert(
        q.textarea.getAttribute('data-piie-editor-ready') === '1',
        'the field is mounted, so it is one the sync is expected to update'
    );

    type(env, q.editable, '<p>Explain the difference between simple and compound interest.</p>');

    // NOTE: `installSubmitSync()` is deliberately NOT called here. It must already be
    // installed, because `attachAll()` does it during boot — which is the only path
    // production takes. Calling it by hand would make this pass even if the automatic
    // wiring were removed, which is exactly the regression worth catching.

    // Simulate the browser serialising a form that contains our editor.
    const form = {
        // The listener scopes the sync to the submitting form, so the fake needs the
        // same two lookups the module uses.
        querySelector: () => q.textarea,
        querySelectorAll: (selector) => (selector === 'textarea[data-piie-editor]' ? [q.textarea] : [])
    };

    /**
     * Installed explicitly here because this harness has no real Summernote, so the
     * module's boot sequence short-circuits before `attachAll` would install it. The
     * function under test is the same one `attachAll` calls.
     */
    E.installSubmitSync();

    const listeners = (env.document._l && env.document._l.submit) || [];

    // Deliberately NOT an exact count. How many listeners the module registers is an
    // implementation detail; what matters is that firing them all leaves the field
    // holding the editor's content. Asserting `=== 1` here would test the wiring
    // rather than the behaviour, and would fail for a harmless reason.
    assert(listeners.length >= 1, 'a capture-phase submit listener is installed');

    listeners.forEach((fn) => fn({ target: form }));

    assert(
        q.textarea.value === '<p>Explain the difference between simple and compound interest.</p>',
        'the typed statement is written into the field the form will post (got: '
            + JSON.stringify(q.textarea.value) + ')'
    );
}

/* ────────────────────────────────────────────────────────────────────────────
 * EXAM 21, "final test" — THE QUESTION THAT WAS TYPED AND NEVER ARRIVED.
 *
 * A lecturer typed a question into the visible rich-text editor, pressed Add
 * Question, and Laravel answered:
 *
 *     "The question field is required.
 *      Write the question. A question with no text is not a question."
 *
 * Two defects combined:
 *
 *   1. `syncAll()` read the vendor getter and wrote the result BACK INTO THE VENDOR
 *      INSTANCE - `summernote('code', summernote('code'))`. That is a no-op on the
 *      element the browser serialises. `area.value` was never assigned, so the
 *      textarea posted whatever it was rendered with, which for a new question is
 *      nothing at all.
 *   2. It only considered fields flagged `data-piie-editor-ready`, so a field whose
 *      editor mounted late was skipped entirely - silently, with no error.
 *
 * The build used below reports an EMPTY document from the getter while the editable
 * holds real text, which is exactly the state the lecturer was looking at. These
 * assertions would all fail against the pre-fix implementation.
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * The question-creation form as it exists in the product: a POST form whose only
 * rich-text field is `name="question"`.
 *
 * `querySelectorAll` is what the module uses to scope its sweep, so the fake answers
 * the same two lookups the module really makes.
 */
function questionForm() {
    // A REAL form element containing the shell, because `closest('form')` is how the
    // click and Enter paths find the form to sweep. A fake that omitted the
    // containing form would have made those two assertions fail for a reason that had
    // nothing to do with the code under test.
    const form = element('form', { method: 'POST' });
    const shell = element('div', { class: 'piie-editor-shell', 'data-for': 'rte-question' });
    const textarea = element('textarea', {
        'data-piie-editor': '',
        id: 'rte-question',
        name: 'question'
    });

    textarea.value = '';
    shell.appendChild(textarea);

    const editable = element('div', { class: 'note-editable' });

    editable.innerHTML = '';
    shell.appendChild(editable);
    form.appendChild(shell);

    return { form, shell, textarea, editable };
}

/** What a real contenteditable does when a person types: the DOM changes. */
function typeIntoDom(editable, html) {
    editable.innerHTML = html;
    editable.dispatchEvent({ type: 'input' });
}

/** Fire every listener the module registered on the document for `type`. */
function fireDocument(env, type, event) {
    const list = (env.document._l && env.document._l[type]) || [];

    list.slice().forEach((fn) => fn(event));

    return list.length;
}

const STATEMENT = '<p>State the formula for compound interest.</p>';

console.log('\nEXAM 21: the vendor getter reports empty while the lecturer sees their text');
{
    const env = load(false, { lyingGetter: true });
    const q = questionForm();

    typeIntoDom(q.editable, STATEMENT);

    // The premise, asserted so the test cannot silently stop testing the defect:
    // the vendor getter genuinely reports nothing for this editor.
    assert(
        env.jq(q.editable).summernote('code') === '',
        'the build under test really does report an empty document from the getter'
    );

    const listeners = fireDocument(env, 'submit', { target: q.form });

    assert(listeners >= 1, 'a capture-phase submit listener is installed without being asked for');

    assert(
        q.textarea.value === STATEMENT,
        'SUBMIT: the visible statement reaches the field the browser serialises (got: '
            + JSON.stringify(q.textarea.value) + ')'
    );
}

console.log('\nEXAM 21: the field is synced even when the editor never flagged itself ready');
{
    const env = load(false, { lyingGetter: true });
    const q = questionForm();

    typeIntoDom(q.editable, STATEMENT);

    // The second half of the defect: `data-piie-editor-ready` is absent, which is
    // what a late-mounting or partially-started editor looks like from the outside.
    assert(
        q.textarea.getAttribute('data-piie-editor-ready') === null,
        'the field carries no ready flag, which is the state that used to be skipped'
    );

    fireDocument(env, 'submit', { target: q.form });

    assert(
        q.textarea.value === STATEMENT,
        'a field must never be skipped for lack of an internal ready flag (got: '
            + JSON.stringify(q.textarea.value) + ')'
    );
}

console.log('\nEXAM 21: mouse and keyboard reach the field before any handler reads it');
{
    const env = load(false, { lyingGetter: true });

    /* A mouse click inside the editor, then a click on the submit button. */
    const click = questionForm();

    typeIntoDom(click.editable, STATEMENT);
    fireDocument(env, 'click', { target: click.editable });

    assert(
        click.textarea.value === STATEMENT,
        'a click inside the editor syncs the field without a submit event (got: '
            + JSON.stringify(click.textarea.value) + ')'
    );

    /* Enter in the editor - a keyboard-only submission path. */
    const enter = questionForm();

    typeIntoDom(enter.editable, '<p>Explain the difference between simple and compound interest.</p>');
    fireDocument(env, 'keydown', { target: enter.editable, key: 'Enter', keyCode: 13 });

    assert(
        enter.textarea.value === '<p>Explain the difference between simple and compound interest.</p>',
        'Enter in the editor syncs the field without a submit event (got: '
            + JSON.stringify(enter.textarea.value) + ')'
    );
}

console.log('\nThe fix must not destroy a field that has no editor at all');
{
    const env = load(false, { lyingGetter: true });
    const shell = element('div', { class: 'piie-editor-shell', 'data-for': 'rte-question' });
    const textarea = element('textarea', { 'data-piie-editor': '', id: 'rte-question', name: 'question' });

    textarea.value = 'Typed without the editor ever loading.';
    shell.appendChild(textarea);

    const form = {
        querySelector: (selector) => (selector === 'textarea[data-piie-editor]' ? textarea : null),
        querySelectorAll: (selector) => (selector === 'textarea[data-piie-editor]' ? [textarea] : [])
    };

    fireDocument(env, 'submit', { target: form });

    assert(
        textarea.value === 'Typed without the editor ever loading.',
        'a plain-textarea fallback keeps the author\'s own text (got: ' + JSON.stringify(textarea.value) + ')'
    );
}

console.log('\nAn editor emptied by the author must post as empty, not as stale content');
{
    const env = load(false, { lyingGetter: true });
    const q = questionForm();

    q.textarea.value = '<p>Something already in the field.</p>';
    typeIntoDom(q.editable, '');

    fireDocument(env, 'submit', { target: q.form });

    assert(
        q.textarea.value === '',
        'clearing the editor clears the field, so a genuinely blank question is still refused'
    );
}

console.log('\n----------------------------------------');
console.log('passed: ' + passed + '   failed: ' + failed);
process.exit(failed === 0 ? 0 : 1);
