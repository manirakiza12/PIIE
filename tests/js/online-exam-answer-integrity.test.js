/*
 * BROWSER-LEVEL REGRESSION FOR THE EXAM 20 DEFECTS.
 *
 * These drive the ACTUAL shipped files — `public/js/academic-editor.js`,
 * `public/assets/js/summernote-lite.min.js` and
 * `public/js/exam-restricted-mode.js` — inside a real DOM, against markup that mirrors
 * what `student.online_exam.take` renders. That is deliberate: a mocked editor proves
 * nothing about the code that failed in production, and the exam 19/20 defects were
 * each a discrepancy between what the page claimed and what the real editor did.
 *
 * Run: node --test tests/js/
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const REPO = path.resolve(__dirname, '..', '..');

function read(relative) {
    return fs.readFileSync(path.join(REPO, relative), 'utf8');
}

/** A page whose body is `html`, with the shipped assets evaluated in order. */
function page(html) {
    const dom = new JSDOM('<!doctype html><html><head>'
        + '<link rel="stylesheet" href="assets/css/summernote-lite.min.css">'
        + '<link rel="stylesheet" href="css/academic-editor.css">'
        + '</head><body>' + html + '</body></html>', {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
    });

    const w = dom.window;

    // jsdom has no layout and no canvas. Summernote's font probe and toolbar
    // measurement both use them; without these it throws inside `attach()`, which is
    // itself the scenario several tests below reproduce deliberately.
    w.document.execCommand = w.document.execCommand || (() => true);

    const realCreate = w.document.createElement.bind(w.document);

    w.document.createElement = function (tag, ...rest) {
        const el = realCreate(tag, ...rest);

        if (String(tag).toLowerCase() === 'canvas') {
            el.getContext = () => ({ font: '', measureText: () => ({ width: 10 }) });
        }

        return el;
    };

    return w;
}

function load(w) {
    w.eval(read('public/assets/js/jquery.min.js'));
    w.eval(read('public/assets/js/summernote-lite.min.js'));
    w.eval(read('public/js/academic-editor.js'));
}

function settle(ms = 400) {
    return new Promise(r => setTimeout(r, ms));
}

// ── markup mirroring the Blade output ────────────────────────────────────────

function editorField(questionId, value = '') {
    return '<div class="piie-editor-shell" data-for="exam-answer-' + questionId + '">'
        + '<textarea id="exam-answer-' + questionId + '" name="answers[' + questionId + ']"'
        + ' class="piie-editor-source form-control eForm-control exam-answer-input exam-answer-essay"'
        + ' rows="6" data-piie-editor data-placeholder="Your answer..." data-height="260"'
        + ' data-allow-images="0" data-question-id="' + questionId + '" data-answer-type="text"'
        + ' data-testid="essay-editor">' + value + '</textarea></div>';
}

/** Short Answer is deliberately NOT an editor. See the take.blade.php note. */
function shortAnswerField(questionId, value = '') {
    return '<div class="exam-answer-short-answer">'
        + '<label class="form-label" for="exam-answer-short-' + questionId + '">Your answer</label>'
        + '<textarea class="form-control eForm-control exam-answer-input exam-answer-text"'
        + ' id="exam-answer-short-' + questionId + '" name="answers[' + questionId + ']"'
        + ' rows="3" data-question-id="' + questionId + '" data-answer-type="text"'
        + ' data-testid="short-answer-input" placeholder="Type your answer here.">'
        + value + '</textarea></div>';
}

function examPage() {
    return page(
        '<div id="examTakeRoot" data-piie-exam-area="1">'
        + '<div class="card" id="question-block-49"><p><strong>Q3.</strong> Explain the law of demand.</p>'
        + editorField(49) + '</div>'
        + '<div class="card" id="question-block-50"><p><strong>Q4.</strong> State the compound interest formula.</p>'
        + shortAnswerField(50) + '</div>'
        + '</div>'
        + '<div class="alert d-none" data-testid="editor-health-warning"></div>'
        + '<span class="save-status" id="save-status-49"></span>'
        + '<span class="save-status" id="save-status-50"></span>'
        + '<a class="question-nav-dot" id="nav-dot-49" data-question-state="unanswered">3</a>'
        + '<a class="question-nav-dot" id="nav-dot-50" data-question-state="unanswered">4</a>'
        + '<span id="nav-dot-label-49"></span><span id="nav-dot-label-50"></span>'
    );
}

/** Fires a real `input` event on a contenteditable, as typing does. */
function typeInto(w, editable, html) {
    editable.innerHTML = html;
    editable.dispatchEvent(new w.Event('input', { bubbles: true }));
}

function firstEditable(w, questionId) {
    const shell = w.document.querySelector('#question-block-' + questionId + ' .piie-editor-shell');

    return shell && shell.querySelector('.note-editable');
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. THE EDITOR BRIDGE — typed text must reach the autosave-visible field
// ═══════════════════════════════════════════════════════════════════════════════

test('ESSAY: typing into the editor is visible to the autosave as answer text', async () => {
    const w = examPage();
    load(w);
    await settle();

    const editable = firstEditable(w, 49);
    assert.ok(editable, 'the essay editor must mount');

    typeInto(w, editable, '<p>Demand falls as price rises.</p>');
    await settle(80);

    // What autosave reads. `codeFor` asks the EDITOR, never only the hidden textarea.
    assert.equal(w.PIIEAademicEditor.codeFor('answers[49]'), '<p>Demand falls as price rises.</p>');

    // And the field itself, which is what the page's `input` listener is bound to.
    const area = w.document.querySelector('#question-block-49 textarea[data-piie-editor]');
    assert.equal(area.value, '<p>Demand falls as price rises.</p>',
        'the source textarea must carry the typed text so autosave sees it');
});

test('ESSAY: rich-text formatting survives the round trip through the editor', async () => {
    const w = examPage();
    load(w);
    await settle();

    const markup = '<p>Because <strong>price</strong> and quantity move '
        + '<em>inversely</em>.</p><ul><li>ceteris paribus</li></ul>'
        + '<p><span data-latex="P = a - bQ">P = a - bQ</span></p>';

    typeInto(w, firstEditable(w, 49), markup);
    await settle(80);

    assert.equal(w.PIIEAademicEditor.codeFor('answers[49]'), markup,
        'lists, emphasis and mathematical notation must not be flattened');
});

test('ESSAY: a restored answer is read back by the page, not just shown', async () => {
    const w = examPage();
    load(w);
    await settle();

    const saved = '<p>The law of demand.</p>';
    w.PIIEAademicEditor.setCode('answers[49]', saved);

    assert.equal(w.PIIEAademicEditor.codeFor('answers[49]'), saved);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 2. SHORT ANSWER — no editor dependency at all
// ═══════════════════════════════════════════════════════════════════════════════

test('SHORT ANSWER: the field is a real textarea that needs no editor to work', async () => {
    const w = examPage();
    load(w);
    await settle();

    const field = w.document.querySelector('[data-testid="short-answer-input"]');

    assert.ok(field, 'the short answer must render a control');
    assert.equal(field.tagName, 'TEXTAREA');
    assert.equal(field.dataset.questionId, '50');
    // Still found by exactly the selector the autosave uses.
    assert.match(field.className, /exam-answer-input/);
});

test('SHORT ANSWER: typing fires the same input event the autosave listens for', async () => {
    const w = examPage();
    load(w);
    await settle();

    const field = w.document.querySelector('[data-testid="short-answer-input"]');
    let fired = 0;

    field.addEventListener('input', () => { fired++; });
    field.value = 'A = P(1 + r/n)^(nt)';
    field.dispatchEvent(new w.Event('input', { bubbles: true }));

    assert.equal(fired, 1);
    assert.equal(field.value, 'A = P(1 + r/n)^(nt)',
        'plain-text answers must survive exactly, including mathematical notation');
});

test('SHORT ANSWER: an editor failure elsewhere cannot affect it', async () => {
    const w = examPage();

    load(w);

    // The essay's initialisation throws, as a vendor-level failure can.
    const original = w.jQuery.fn.summernote;
    let first = true;

    w.jQuery.fn.summernote = function (...args) {
        if (first && typeof args[0] === 'object' && args[0] !== null) {
            first = false;
            throw new Error('vendor init failure');
        }
        return original.apply(this, args);
    };

    w.PIIEAademicEditor.attachAll(w.document);
    await settle(50);

    const field = w.document.querySelector('[data-testid="short-answer-input"]');
    assert.ok(field, 'the short answer survives an editor failure — it has no editor');
    assert.equal(field.disabled, false);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 3. THE FAILURE MODE THAT PRODUCED EXAM 20's Q4 — reproduced and then fixed
// ═══════════════════════════════════════════════════════════════════════════════

test('one editor failing must not leave a later field hidden and unusable', async () => {
    const twoEditors = page(
        '<div id="examTakeRoot">'
        + '<div class="card" id="question-block-49">' + editorField(49) + '</div>'
        + '<div class="card" id="question-block-50">' + editorField(50) + '</div>'
        + '</div>'
    );

    load(twoEditors);

    const summernote = twoEditors.jQuery.fn.summernote;
    let first = true;

    twoEditors.jQuery.fn.summernote = function (...args) {
        if (first && typeof args[0] === 'object' && args[0] !== null) {
            first = false;
            throw new Error('vendor init failure');
        }
        return summernote.apply(this, args);
    };

    twoEditors.PIIEAademicEditor.attachAll(twoEditors.document);
    await settle(50);

    // The failing field is REVEALED: `piie-editor-source` removed, so
    // `html.piie-js .piie-editor-shell > textarea.piie-editor-source { display:none }`
    // no longer applies. Before this fix the field kept the class and was invisible.
    const failed = twoEditors.document.querySelector('#question-block-49 textarea[data-piie-editor]');

    assert.ok(!failed.classList.contains('piie-editor-source'),
        'the failed field would be invisible to the student');
    assert.equal(failed.getAttribute('data-piie-editor-failed'), '1');
    failed.value = 'typed into the fallback';
    assert.equal(failed.value, 'typed into the fallback');

    // THE ESSENTIAL ASSERTION: the SECOND field was still attempted and still works.
    // One exception must not cost the whole paper.
    const second = twoEditors.document.querySelector('#question-block-50 textarea[data-piie-editor]');
    const secondEditable = twoEditors.document.querySelector('#question-block-50 .note-editable');

    assert.ok(secondEditable, 'the field after the failing one must still mount its editor');
    assert.equal(second.getAttribute('data-piie-editor-ready'), '1');
    assert.equal(secondEditable.getAttribute('data-piie-bridge-ready'), '1');

    typeInto(twoEditors, secondEditable, '<p>The second answer.</p>');
    await settle(80);
    assert.equal(twoEditors.PIIEAademicEditor.codeFor('answers[50]', twoEditors.document.getElementById('question-block-50')),
        '<p>The second answer.</p>');
});

test('a vendor failure AFTER the editable mounted still leaves a SAVABLE field', async () => {
    // Reproduces the exact exam 20 question 3 condition: Summernote creates the
    // editable (the student sees a working rich-text editor) and then throws while
    // building the rest of itself.
    const w = page('<div id="examTakeRoot"><div id="question-block-49">' + editorField(49) + '</div></div>');

    load(w);

    const original = w.jQuery.fn.summernote;

    w.jQuery.fn.summernote = function (...args) {
        // Let the editor mount, then fail — which is what a toolbar or font-measurement
        // error looks like from the outside.
        const result = original.apply(this, args);

        if (typeof args[0] === 'object' && args[0] !== null) {
            throw new Error('vendor failed after mounting');
        }

        return result;
    };

    w.PIIEAademicEditor.attachAll(w.document);
    await settle(50);

    const area = w.document.querySelector('#question-block-49 textarea[data-piie-editor]');
    const editable = firstEditable(w, 49);

    assert.ok(editable, 'the editor the student can see stays');

    // The critical assertion: the bridge IS bound, so the student's typing becomes a
    // save. Before this fix the field had no `data-piie-editor-ready` and no bridge —
    // usable to the student, invisible to autosave.
    assert.equal(area.getAttribute('data-piie-editor-ready'), '1',
        'a mounted editor must be marked ready so its content can be saved');
    assert.equal(editable.getAttribute('data-piie-bridge-ready'), '1',
        'a mounted editor must be bridged, or the answer is lost');

    const coverage = w.PIIEAademicEditor.bridgeCoverage(w.document);
    assert.equal(coverage.unbridged.length, 0);
    assert.equal(coverage.wired, 1);

    // And the end-to-end consequence: typing is now readable by the page.
    typeInto(w, editable, '<p>Demand falls as price rises.</p>');
    await settle(80);
    assert.equal(w.PIIEAademicEditor.codeFor('answers[49]'), '<p>Demand falls as price rises.</p>');
});

test('a vendor failure BEFORE the editable mounted reveals a working plain field', async () => {
    const w = page('<div id="examTakeRoot"><div id="question-block-49">' + editorField(49) + '</div></div>');

    load(w);

    w.jQuery.fn.summernote = function () {
        throw new Error('vendor failed before mounting');
    };

    w.PIIEAademicEditor.attachAll(w.document);
    await settle(50);

    const area = w.document.querySelector('#question-block-49 textarea[data-piie-editor]');

    assert.equal(area.getAttribute('data-piie-editor-failed'), '1');
    // The stylesheet hides `.piie-editor-source` under `html.piie-js`, so this class
    // is the difference between a visible field and exam 20's empty card.
    assert.ok(!area.classList.contains('piie-editor-source'),
        'the field must be revealed — a hidden textarea with no editor is unusable');

    const shell = area.closest('.piie-editor-shell');
    assert.ok(shell.querySelector('.piie-editor-notice'),
        'the student is told the editor did not load rather than being left guessing');
});

test('an editor that never mounted is reported as unmounted, not as unbridged', async () => {
    const w = examPage();
    load(w);
    await settle();

    // A field the editor never reached, alongside one that did.
    const orphan = w.document.createElement('div');

    orphan.className = 'piie-editor-shell';
    orphan.innerHTML = '<textarea data-piie-editor name="answers[60]" data-question-id="60"></textarea>';
    w.document.getElementById('examTakeRoot').appendChild(orphan);

    const coverage = w.PIIEAademicEditor.bridgeCoverage(w.document);

    assert.ok(coverage.unmounted.includes('60'), 'an absent editor is "unmounted"');
    assert.ok(!coverage.unbridged.includes('60'),
        '"unbridged" means typed-into-but-not-saved, which is a different fault');
    assert.equal(coverage.wired + coverage.unmounted.length + coverage.unbridged.length,
        coverage.total);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 4. THE EXAM-RESTRICTED SCOPE — the copy defect that testing found
// ═══════════════════════════════════════════════════════════════════════════════

function restrictedPage() {
    const w = page(
        '<div id="examTakeRoot" data-piie-exam-area="1">'
        + '<div class="card" id="question-block-49">'
        + '<p class="piie-prose online-exam-question-statement"><strong>Q3.</strong> Explain the law of demand.</p>'
        + editorField(49)
        + '</div></div>'
        + '<footer id="siteFooter"><p>Site footer text a student may read and copy is not exam content.</p></footer>'
    );

    w.document.documentElement.classList.add('piie-exam-restricted');
    w.eval(read('public/js/exam-restricted-mode.js'));
    w.PIIEExamRestricted.install();

    return w;
}

function fire(w, type, selector, init = {}) {
    const target = w.document.querySelector(selector);

    const event = type === 'keydown'
        ? new w.KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init })
        : new w.Event(type, { bubbles: true, cancelable: true, ...init });

    Object.defineProperty(event, 'target', { value: target, writable: false });
    target.dispatchEvent(event);

    return event.defaultPrevented;
}

test('restricted mode: the question STATEMENT is inside the protected area', () => {
    const w = restrictedPage();

    assert.ok(w.PIIEExamRestricted.hasProtectedArea(), 'a protected area must be found');
    assert.ok(w.PIIEExamRestricted.isRestrictedTarget(w.document.querySelector('#question-block-49 p')),
        'the question text is exam content and must be protected');
});

test('restricted mode: copy, cut, paste and the context menu are blocked on the question text', () => {
    const w = restrictedPage();

    for (const type of ['copy', 'cut', 'paste', 'dragstart', 'contextmenu']) {
        assert.ok(fire(w, type, '#question-block-49 p'),
            type + ' must be blocked on the question statement');
    }
});

test('restricted mode: the same actions are blocked inside the answer editor', () => {
    const w = restrictedPage();

    for (const type of ['copy', 'cut', 'paste', 'contextmenu']) {
        assert.ok(fire(w, type, '#exam-answer-49'), type + ' must be blocked in the answer field');
    }
});

test('restricted mode: clipboard and navigation shortcuts are recognised', () => {
    const w = restrictedPage();

    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'c', ctrlKey: true }), 'Ctrl+C');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'x', ctrlKey: true }), 'Ctrl+X');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'v', ctrlKey: true }), 'Ctrl+V');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'c', metaKey: true }), 'Cmd+C');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'Insert', shiftKey: true }), 'Shift+Insert');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'p', ctrlKey: true }), 'Ctrl+P');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'F5' }), 'F5');
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'ArrowLeft', altKey: true }), 'Alt+Left');
});

test('restricted mode: ordinary typing and editing are NOT blocked', () => {
    const w = restrictedPage();

    // A student must be able to correct their own work.
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'a' }), false);
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'Backspace' }), false);
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'ArrowLeft' }), false);
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'a', ctrlKey: true }), false);
    // Fullscreen is the student's escape from a browser chrome that covers the editor.
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'F11' }), false);
});

test('restricted mode: the rest of the application is untouched', () => {
    const w = restrictedPage();

    assert.equal(w.PIIEExamRestricted.isRestrictedTarget(w.document.querySelector('#siteFooter p')), false);
    assert.equal(fire(w, 'copy', '#siteFooter p'), false,
        'the clipboard elsewhere in PIIE must keep working');
});

test('restricted mode: it does not install itself on an ordinary page', () => {
    const w = page('<footer id="siteFooter">ordinary page</footer>');

    w.eval(read('public/js/exam-restricted-mode.js'));

    assert.equal(w.PIIEExamRestricted.install(), false,
        'no protected area, no restrictions — and it must say so rather than pretend');
});

// ═══════════════════════════════════════════════════════════════════════════════
// 6. CONFIGURABLE POLICY AND APPROVED ACCOMMODATIONS
// ═══════════════════════════════════════════════════════════════════════════════

function restrictedPageWithSettings(policy) {
    const w = page(
        '<div id="examTakeRoot" data-piie-exam-area="1">'
        + '<div class="card" id="question-block-49">'
        + '<p class="piie-prose online-exam-question-statement"><strong>Q3.</strong> Explain the law of demand.</p>'
        + editorField(49)
        + '</div></div>'
    );

    w.document.documentElement.classList.add('piie-exam-restricted');
    w.PIIEExamRestricted = { settings: policy };
    w.eval(read('public/js/exam-restricted-mode.js'));
    w.PIIEExamRestricted.install();

    return w;
}

test('an approved clipboard exemption really does permit copy and paste in the exam', () => {
    // `clipboard_exempt` exists for a student whose approved assistive tool or screen
    // reader needs to select text. If the exemption did not actually take effect the
    // accommodation would be a lie told to the student on the page.
    const w = restrictedPageWithSettings({ block_clipboard: false, block_context_menu: false });

    assert.equal(w.PIIEExamRestricted.settings().block_clipboard, false);
    assert.equal(fire(w, 'copy', '#question-block-49 p'), false, 'copy must be permitted');
    assert.equal(fire(w, 'paste', '#exam-answer-49'), false, 'paste must be permitted');
    assert.equal(fire(w, 'contextmenu', '#question-block-49 p'), false,
        'the context menu must be permitted — it is how a reader selects text');

    // AND the parts the exemption does NOT cover stay enforced.
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'p', ctrlKey: true }),
        'print must remain blocked — no accommodation requires printing the paper');
});

test('a clipboard exemption still leaves navigation and print enforced', () => {
    const w = restrictedPageWithSettings({
        block_clipboard: false,
        block_context_menu: false,
        block_navigation_shortcuts: true,
    });

    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'F5' }));
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'r', ctrlKey: true }));
    assert.ok(fire(w, 'keydown', '#exam-answer-49', { key: 'p', ctrlKey: true }));
});

test('a full exemption stops blocking but the module still reports the policy it ran', () => {
    const w = restrictedPageWithSettings({
        block_clipboard: false,
        block_context_menu: false,
        block_navigation_shortcuts: false,
    });

    assert.equal(fire(w, 'copy', '#question-block-49 p'), false);
    assert.equal(fire(w, 'keydown', '#exam-answer-49', { key: 'F5' }), false);

    // The policy is readable, so a test — and a page — can tell what was in force.
    assert.equal(w.PIIEExamRestricted.settings().accommodation, null);
    assert.equal(w.PIIEExamRestricted.settings().block_navigation_shortcuts, false);
});

test('absent settings default to the STRICTEST reading, never to "off"', () => {
    // A page that forgets to send its policy must get the full controls. Defaulting to
    // permissive would mean a missing configuration silently unprotects every paper.
    const w = restrictedPageWithSettings(undefined);

    const settings = w.PIIEExamRestricted.settings();

    assert.equal(settings.block_clipboard, true);
    assert.equal(settings.block_context_menu, true);
    assert.equal(settings.block_navigation_shortcuts, true);
    assert.ok(fire(w, 'copy', '#question-block-49 p'));
});

test('focus loss is RECORDED even when an accommodation suppresses the warning', async () => {
    // The distinction that matters: `warn_on_focus_loss: false` silences the MESSAGE,
    // never the record. An adjustment must not make an examination unmonitored.
    const reported = [];
    const w = restrictedPageWithSettings({ warn_on_focus_loss: false });

    w.PIIEExamRestricted.endpoint = 'http://test/incident';
    w.fetch = (url, options) => {
        reported.push(JSON.parse(options.body));
        return Promise.resolve({ ok: true });
    };

    let warned = 0;
    w.PIIEExamRestricted.onFocusLost = () => { warned++; };
    w.PIIEExamRestricted.onPersistentNotice = () => {};

    // The REAL path: the events the browser actually fires.
    w.dispatchEvent(new w.Event('blur'));
    w.dispatchEvent(new w.Event('focus'));

    await settle(20);

    assert.equal(warned, 0, 'no warning is shown');
    assert.ok(reported.some(r => r.event_type === 'focus_lost'),
        'but the incident IS still recorded');
});

test('repeated focus loss escalates to a persistent notice, and stops repeating the warning', async () => {
    const w = restrictedPageWithSettings({ warn_on_focus_loss: true, warning_threshold: 1, persistent_banner_after: 3 });

    let transient = 0;
    let persistent = 0;
    w.PIIEExamRestricted.onFocusLost = () => { transient++; };
    w.PIIEExamRestricted.onPersistentNotice = () => { persistent++; };

    for (let i = 0; i < 6; i++) {
        w.dispatchEvent(new w.Event('blur'));
        // Past the grace window, so each pair is a NEW incident rather than one
        // absorbed Alt+Tab.
        await settle(20);
        w.dispatchEvent(new w.Event('focus'));
        await settle(20);
    }

    // A warning shown on every incident is a warning nobody reads: it stops at the
    // configured threshold, and the persistent banner takes over from there.
    assert.ok(transient <= 1, 'the transient warning must be rate-limited, saw ' + transient);
    assert.equal(persistent, 1, 'the persistent banner is shown once it is earned');
    assert.ok(w.PIIEExamRestricted.incidentState().count >= 3);
});

// ═══════════════════════════════════════════════════════════════════════════════
// 5. THE AUTOSAVE STATE MACHINE (existing suite, kept green alongside the new code)
// ═══════════════════════════════════════════════════════════════════════════════

test('the autosave functions in take.blade.php remain syntactically loadable', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const start = source.indexOf('    var dirtyQuestions = {}');

    assert.ok(start > 0, 'the autosave block must still be present in the view');

    const end = source.indexOf("document.querySelectorAll('.exam-answer-input')", start);
    const block = source.slice(start, end);

    // Compiled rather than merely read: a syntax error here would silently disable
    // every autosave behaviour on the page.
    assert.doesNotThrow(() => new Function(block));
});

test('flushPendingSaves resolves FALSE when a save is still outstanding', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const flush = source.slice(
        source.indexOf('    function flushPendingSaves()'),
        source.indexOf("    // ── Final submit")
    );

    assert.match(flush, /syncAll/, 'the flush must push editor content into the source fields');
    assert.match(flush, /captureEditorChanges/, 'the flush must read every control directly');
    assert.match(flush, /resolve\(false\)/,
        'a timeout with work outstanding must resolve false, never true');
});

test('Saved is only ever set from a successful response path', () => {
    const source = read('resources/views/student/online_exam/take.blade.php');
    const save = source.slice(
        source.indexOf('    function saveQuestion(questionId)'),
        source.indexOf('    function showRevisionConflict(questionId, server)')
    );

    // Every early return in the response handler must leave the question NOT saved.
    for (const status of ['409', '422', '!r.ok']) {
        assert.ok(save.includes('if (r.status === ' + status + ')')
            || save.includes('if (' + status + ')'),
            'the ' + status + ' branch must be handled before anything is marked saved');
    }

    const savedIndex = save.indexOf("setStatus(questionId, 'saved')");
    const okIndex = save.indexOf('if (!r.ok)');

    assert.ok(okIndex > -1 && savedIndex > okIndex,
        "'saved' must come after the failure branches, never before");
});