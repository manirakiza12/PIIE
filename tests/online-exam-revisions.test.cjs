// Executes the actual Blade autosave functions with deterministic browser/network doubles.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/student/online_exam/take.blade.php', 'utf8');
const start = source.indexOf('    var dirtyQuestions = {}');
const end = source.indexOf("    document.querySelectorAll('.exam-answer-input')", start);
const tick = () => new Promise(resolve => setImmediate(resolve));

function browser(server = {}) {
    const requests = [], storage = new Map(), elements = new Map();
    const textarea = { value: 'draft' };
    // The navigator dot now carries one class per state, so the double needs the
    // real `toggle(name, force)` semantics rather than a no-op.
    const element = () => {
        const classes = new Set();
        return {
            textContent: '', children: [],
            classList: {
                add(name) { classes.add(name); },
                remove(name) { classes.delete(name); },
                toggle(name, force) { force ? classes.add(name) : classes.delete(name); },
                contains(name) { return classes.has(name); },
            },
            setAttribute() {}, getAttribute() { return null; },
            appendChild(child) { this.children.push(child); },
            addEventListener(_, fn) { this.click = fn; },
        };
    };

    // `currentValueFor()` scopes its read to the question's own card —
    // `document.getElementById('question-block-N').querySelector('textarea...')` — so
    // that an answer can never be read from a different question's control. The double
    // has to model that card, or it would be testing an older, unscoped read.
    const card = {
        querySelector(selector) { return selector.startsWith('textarea') ? textarea : null; },
        querySelectorAll() { return []; },
    };

    const ctx = {
        serverAnswers: server, recoveryKey: 'student.exam.submission', recoveryState: {},
        tabLeaseKey: 'lease', tabId: 'tab-a', tabConflict: false,
        retryTimers: {}, retryAttempts: {}, submissionId: 10, csrfToken: 'csrf', saveAnswerUrl: '/save',
        navigator: { onLine: true }, setInterval() {}, setTimeout() {},
        window: { addEventListener() {}, localStorage: {
            getItem(k) { return storage.get(k) || null; },
            setItem(k, v) { storage.set(k, v); }, removeItem(k) { storage.delete(k); },
        } },
        document: {
            getElementById(id) {
                if (id.startsWith('question-block-')) { return card; }
                if (!elements.has(id)) elements.set(id, element());
                return elements.get(id);
            },
            createElement: element,
            querySelectorAll(selector) {
                // `questionIds()` derives the paper from the DOM rather than from a
                // server payload, so the double has to answer that query for the
                // capture path to be reachable at all.
                if (selector === '[data-question-id]') {
                    return [{ getAttribute: () => '1' }];
                }
                return [];
            },
            querySelector(s) { return s.startsWith('textarea') ? textarea : null; },
        },
        fetch(_, options) { return new Promise(resolve => requests.push({ body: JSON.parse(options.body), resolve })); },
    };
    vm.createContext(ctx);
    vm.runInContext(source.slice(start, end), ctx);
    return { ctx, requests, textarea, storage, elements,
        respond(i, status, data) { requests[i].resolve({ status, ok: status === 200, json: async () => data }); },
    };
}

test('edits use server revision; a delayed acknowledgement preserves and sends the current draft', async () => {
    const b = browser({ 1: { answer_revision: 4 } });
    const c = b.ctx;
    c.answerRevisions[1]++;
    c.dirtyQuestions[1] = true;
    c.saveQuestion(1);
    assert.equal(b.requests[0].body.answer_revision, 5);
    c.answerRevisions[1]++;
    b.textarea.value = 'new draft';
    c.rememberLocalAnswer(1, c.currentValueFor(1));
    b.respond(0, 200, { answer_revision: 5, answer_updated_at: 'server' });
    await tick();
    assert.equal(c.acknowledgedRevisions[1], 5);
    assert.equal(c.dirtyQuestions[1], true);
    assert.equal(c.recoveryState[1].revision, 6);
    assert.equal(b.requests[1].body.answer_revision, 6);
    b.respond(1, 200, { answer_revision: 6 });
    await tick();
    assert.equal(c.dirtyQuestions[1], false);
    assert.equal(c.recoveryState[1], undefined);
});

test('tab A revision five rejected after tab B six stays unsaved and never retries automatically', async () => {
    const b = browser({ 1: { answer_revision: 4 } });
    b.ctx.answerRevisions[1] = 5;
    b.ctx.saveQuestion(1);
    b.respond(0, 409, { status: 'stale', answer_revision: 6, answer_text: 'tab B' });
    await tick();
    assert.equal(b.ctx.dirtyQuestions[1], true);
    assert.equal(b.ctx.recoveryState[1].answer_text, 'draft');
    b.ctx.saveQuestion(1);
    assert.equal(b.requests.length, 1);
    const buttons = b.elements.get('save-status-1').children;
    buttons[1].click();
    assert.equal(b.requests[1].body.answer_revision, 7);
});

test('equal-revision conflict allows explicit server choice without writing', async () => {
    const b = browser();
    b.ctx.answerRevisions[1] = 2;
    b.ctx.saveQuestion(1);
    b.respond(0, 409, { status: 'conflict', answer_revision: 2, answer_text: 'server answer' });
    await tick();
    b.elements.get('save-status-1').children[0].click();
    assert.equal(b.textarea.value, 'server answer');
    assert.equal(b.ctx.dirtyQuestions[1], false);
    assert.equal(b.requests.length, 1);
});

test('recovery uses acknowledged revision, not timestamp, and preserves legacy/conflicting drafts', () => {
    const b = browser({ 1: { answer_revision: 4, updated_at: 'different timestamp' } });
    b.storage.set(b.ctx.recoveryKey, JSON.stringify({ 1: { revision: 5, acknowledged_revision: 4, answer_text: 'recover', server_updated_at: 'old' } }));
    b.ctx.recoverLocalAnswers();
    assert.equal(b.requests[0].body.answer_revision, 5);
    assert.equal(b.textarea.value, 'recover');
    for (const local of [{ revision: 5, acknowledged_revision: 3 }, { revision: 1, server_updated_at: 'old' }]) {
        const conflict = browser({ 1: { answer_revision: 4 } });
        conflict.storage.set(conflict.ctx.recoveryKey, JSON.stringify({ 1: { ...local, answer_text: 'kept' } }));
        conflict.ctx.recoverLocalAnswers();
        assert.equal(conflict.requests.length, 0);
        assert.equal(conflict.ctx.revisionConflicts[1], true);
        assert.equal(conflict.ctx.recoveryState[1].answer_text, 'kept');
    }
});

test('finalized or expired rejection retains recovery and never acknowledges the answer', async () => {
    const b = browser();
    b.ctx.answerRevisions[1] = 1;
    b.ctx.saveQuestion(1);
    b.respond(0, 422, { message: 'Submission is not active' });
    await tick();
    assert.equal(b.ctx.dirtyQuestions[1], true);
    assert.equal(b.ctx.recoveryState[1].revision, 1);
    assert.equal(b.ctx.acknowledgedRevisions[1], undefined);
});

test('pending save flush waits for acknowledgement', async () => {
    const b = browser();
    let callbacks = [];
    b.ctx.setTimeout = fn => callbacks.push(fn);
    const flush = source.slice(source.indexOf('    function flushPendingSaves()'), source.indexOf("    document.getElementById('finalSubmitBtn')"));
    vm.runInContext(flush, b.ctx);
    b.ctx.answerRevisions[1] = 1;
    b.ctx.dirtyQuestions[1] = true;
    const flushed = b.ctx.flushPendingSaves();
    b.respond(0, 200, { answer_revision: 1 });
    await tick();
    callbacks.shift()();
    assert.equal(await flushed, true);
});

test('an acknowledgement cannot remove a different draft written by another tab', async () => {
    const b = browser();
    b.ctx.answerRevisions[1] = 5;
    b.ctx.saveQuestion(1);
    b.storage.set(b.ctx.recoveryKey, JSON.stringify({ 1: { revision: 6, acknowledged_revision: 4, answer_text: 'tab B pending' } }));
    b.respond(0, 200, { answer_revision: 5 });
    await tick();
    assert.equal(JSON.parse(b.storage.get(b.ctx.recoveryKey))[1].revision, 6);
});

// ═══════════════════════════════════════════════════════════════════════════════
// THE NEW GUARANTEES
// ═══════════════════════════════════════════════════════════════════════════════

test('typed text is saved with NO input event at all — a dead bridge cannot lose it', async () => {
    // This is exam 20 question 3. The editor's `input` bridge was never bound, so no
    // DOM event ever fired. The page must still notice the change by reading the
    // field, because correctness may not rest on one listener.
    const b = browser({ 1: { answer_revision: 0, answer_text: '' } });
    const c = b.ctx;

    b.textarea.value = 'Demand falls as price rises.';
    c.captureEditorChanges();

    assert.equal(c.dirtyQuestions[1], true, 'a changed field must become dirty without an event');
    assert.equal(c.answerRevisions[1], 1);

    c.saveQuestion(1);
    assert.equal(b.requests[0].body.answer_text, 'Demand falls as price rises.');

    b.respond(0, 200, { answer_revision: 1 });
    await tick();
    assert.equal(c.dirtyQuestions[1], false);
    // The status text is a Blade expression in this extracted source, so the STATE
    // CLASS is what is asserted - it is what the student and the navigator read.
    assert.ok(b.elements.get('save-status-1').classList.contains('is-saved'));
});

test('a re-read of already-acknowledged content does not look like an edit', () => {
    const b = browser({ 1: { answer_revision: 3, answer_text: 'already saved' } });
    const c = b.ctx;

    b.textarea.value = 'already saved';
    c.captureEditorChanges();

    assert.equal(c.dirtyQuestions[1], undefined,
        'reading a saved answer must not queue another save for it');
    assert.equal(b.requests.length, 0);
});

test('an empty field is never reported as an unsaved edit', () => {
    const b = browser({ 1: { answer_revision: 0, answer_text: null } });
    const c = b.ctx;

    b.textarea.value = '';
    c.captureEditorChanges();

    assert.equal(c.dirtyQuestions[1], undefined,
        'a student who left a question blank must not be nagged, and their blank must not be stored');
});

test('one request per question at a time — a second save while one is in flight does not race it', async () => {
    const b = browser({ 1: { answer_revision: 1 } });
    const c = b.ctx;

    c.answerRevisions[1] = 2;
    c.saveQuestion(1);
    assert.equal(b.requests.length, 1);

    // Typing again while the first request is in flight must NOT open a second
    // request; the content stays dirty and is sent once the first is acknowledged.
    b.textarea.value = 'newer draft';
    c.captureEditorChanges();
    c.saveQuestion(1);
    assert.equal(b.requests.length, 1, 'requests for one question must be serialised');
    assert.equal(c.dirtyQuestions[1], true, 'the newer draft must remain unsaved');

    b.respond(0, 200, { answer_revision: 2 });
    await tick();

    assert.equal(b.requests.length, 2, 'the newer draft is sent as soon as the first is acknowledged');
    assert.equal(b.requests[1].body.answer_text, 'newer draft');
});

test('a response belonging to a superseded request is discarded whole', async () => {
    const b = browser({ 1: { answer_revision: 1 } });
    const c = b.ctx;

    c.answerRevisions[1] = 2;
    c.saveQuestion(1);

    // Simulate a newer request having taken over the question while the first is
    // still open. The serialisation above makes this unreachable in normal operation,
    // so it is forced here deliberately: the guard exists so that a late response can
    // NEVER acknowledge content the server does not hold, whatever causes the overlap.
    c.requestSequence[1] = 2;
    c.savingQuestions[1] = false;
    c.dirtyQuestions[1] = true;

    b.respond(0, 200, { answer_revision: 2 });
    await tick();

    // The acknowledged revision must be exactly what it was before the stale response
    // arrived — 1, seeded from the server — not the 2 that response claimed.
    assert.equal(c.acknowledgedRevisions[1], 1,
        'a superseded response must not be applied at all');
    assert.equal(c.dirtyQuestions[1], true, 'it must not clear the dirty flag');
    assert.ok(!b.elements.get('save-status-1').classList.contains('is-saved'));
});

test('a non-JSON error body is a failed save, not a stuck "Saving…"', async () => {
    const b = browser({ 1: { answer_revision: 1 } });
    const c = b.ctx;

    c.answerRevisions[1] = 1;
    c.saveQuestion(1);

    // A gateway timeout page: `r.json()` rejects. An unhandled rejection here would
    // leave the question stuck in "Saving…" forever, which is the failure the
    // requirement-7 rule exists to prevent.
    b.requests[0].resolve({
        status: 502, ok: false,
        json: async () => { throw new Error('Unexpected token < in JSON'); },
    });
    await tick();

    assert.equal(c.dirtyQuestions[1], true);
    assert.equal(c.savingQuestions[1], false);
    assert.ok(!b.elements.get('save-status-1').classList.contains('is-saved'));
});

test('a failed save never displays Saved, in any failure mode', async () => {
    for (const [status, body] of [[422, { message: 'expired' }], [500, {}], [409, { status: 'stale' }]]) {
        const b = browser({ 1: { answer_revision: 1 } });

        b.ctx.answerRevisions[1] = 1;
        b.ctx.saveQuestion(1);
        b.respond(0, status, body);
        await tick();

        assert.ok(!b.elements.get('save-status-1').classList.contains('is-saved'),
            `HTTP ${status} must not display Saved`);
        assert.equal(b.ctx.dirtyQuestions[1], true,
            `HTTP ${status} must leave the answer unsaved, not silently dropped`);
    }
});
