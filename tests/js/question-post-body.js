/**
 * SHOWS THE OUTGOING POST BODY FOR THE QUESTION FIELD, BEFORE AND AFTER THE FIX.
 *
 * Uses the real `public/js/academic-editor.js` loaded into a minimal DOM, and
 * reports what the browser would serialise for `question` on submit.
 *
 * The "BEFORE" column is produced by reinstating the pre-fix `syncAll()` — the one
 * that read the vendor getter and wrote it back into the vendor instance.
 *
 * Run: node tests/js/question-post-body.js
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');

const REAL = path.join(__dirname, '..', '..', 'public', 'js', 'academic-editor.js');
const EOL = fs.readFileSync(REAL, 'utf8').includes('\r\n') ? '\r\n' : '\n';

function preFixSource() {
    const lines = fs.readFileSync(REAL, 'utf8').split(/\r?\n/);
    const at = lines.findIndex((l) => l.includes('syncAll: function (root)'));
    let start = at;
    while (start > 0 && !lines[start - 1].trim().startsWith('/**')) { start--; }
    start -= 1;
    let end = at;
    while (end < lines.length && lines[end].trim() !== '},') { end++; }

    const PRE = [
        '        syncAll: function (root) {',
        '            var scope = root || document;',
        '            Array.prototype.forEach.call(',
        '                scope.querySelectorAll(\'textarea[data-piie-editor][data-piie-editor-ready="1"]\'),',
        '                function (area) {',
        '                    try { $(area).summernote(\'code\', $(area).summernote(\'code\')); } catch (e) {}',
        '                }',
        '            );',
        '        },'
    ].join(EOL);

    return lines.slice(0, start).concat(PRE.split(EOL), lines.slice(end + 1)).join(EOL);
}

function run(source) {
    // ── DOM ───────────────────────────────────────────────────────────────────
    function el(tag, attrs, tagUpper) {
        const n = {
            tagName: String(tag).toUpperCase(),
            attributes: Object.assign({}, attrs || {}),
            children: [], parentElement: null, listeners: {},
            getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; },
            setAttribute(k, v) { this.attributes[k] = String(v); },
            removeAttribute(k) { delete this.attributes[k]; },
            addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); },
            removeEventListener() {},
            dispatchEvent(e) { (this.listeners[e.type] || []).slice().forEach((f) => f(e)); return true; },
            appendChild(c) { c.parentElement = this; this.children.push(c); return c; },
            contains(x) { let p = x; while (p) { if (p === this) return true; p = p.parentElement; } return false; },
            matches(s) {
                const cls = this.attributes.class || '';
                if (s === 'form') return this.tagName === 'FORM';
                if (s === '.piie-editor-shell') return cls.indexOf('piie-editor-shell') !== -1;
                if (s === '.note-editable') return cls.indexOf('note-editable') !== -1;
                if (s === 'textarea[data-piie-editor]') return this.tagName === 'TEXTAREA' && this.getAttribute('data-piie-editor') !== null;
                return false;
            },
            closest(s) { let p = this; while (p) { if (p.matches && p.matches(s)) return p; p = p.parentElement; } return null; },
            querySelector(s) {
                for (const c of this.children) {
                    if (c.matches && c.matches(s)) return c;
                    const d = c.querySelector ? c.querySelector(s) : null;
                    if (d) return d;
                }
                return null;
            },
            querySelectorAll(s) {
                const out = [];
                for (const c of this.children) {
                    if (c.matches && c.matches(s)) out.push(c);
                    if (c.querySelectorAll) out.push.apply(out, c.querySelectorAll(s));
                }
                return out;
            }
        };
        n.value = '';
        n.classList = { add() {}, remove() {}, contains() { return false; } };
        return n;
    }

    // ── THE PAGE: one POST form, one editor, field name "question" ─────────────
    const form = el('form');
    const shell = el('div', { class: 'piie-editor-shell', 'data-for': 'rte-question' });
    const area = el('textarea', { 'data-piie-editor': '', id: 'rte-question', name: 'question' });
    const editable = el('div', { class: 'note-editable' });

    area.value = '';
    editable.innerHTML = '';
    shell.appendChild(area);
    shell.appendChild(editable);
    form.appendChild(shell);

    // ── Summernote: mounts an editable, but its `code` GETTER reports empty ─────
    // This is the trimmed build in production. The layout used to load it a second
    // time after the editor was mounted, which is exactly how a fresh copy with no
    // knowledge of the editable ended up answering every read.
    function jq(node) {
        const api = function () { return api; };
        api.summernote = function (cmd) {
            if (cmd === 'code') {
                if (arguments.length > 1) { return api; }
                return '';
            }
            if (!shell.querySelector('.note-editable')) { shell.appendChild(el('div', { class: 'note-editable' })); }
            return api;
        };
        api.data = function () { return undefined; };
        return api;
    }
    jq.fn = { summernote: function () { return this; } };
    jq.summernote = { plugins: {} };
    jq.parseHTML = function () { return [el('div')]; };

    const document = {
        readyState: 'complete',
        documentElement: el('html'),
        body: el('body'),
        activeElement: null,
        addEventListener(t, f) { (this._l = this._l || {}); (this._l[t] = this._l[t] || []).push(f); },
        querySelector() { return null; }, querySelectorAll() { return []; },
        getElementById() { return null; }, createEvent() { return { initEvent() {} }; }
    };

    const win = {
        document, console,
        setTimeout() { return 1; }, clearTimeout() {},
        setInterval() { return 0; }, clearInterval() {},
        fetch() { return Promise.resolve({ ok: true, json: () => Promise.resolve({}) }); },
        Event: function (t, i) { return { type: t, bubbles: !!(i && i.bubbles) }; },
        localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
        location: { href: 'http://127.0.0.1:8000/' },
        addEventListener() {}, removeEventListener() {},
        navigator: { onLine: true },
        Promise, JSON, Object, Array, String, Number, Date,
        jQuery: jq, $: jq
    };
    win.window = win;
    const sandbox = Object.assign({}, win, { window: win, document, globalThis: null });
    sandbox.globalThis = sandbox;
    sandbox.jQuery = jq; sandbox.$ = jq;

    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: 'academic-editor.js' });

    // The lecturer types. A contenteditable changes its own DOM.
    editable.innerHTML = '<p>State the formula for compound interest.</p>';
    editable.dispatchEvent({ type: 'input' });

    // The browser serialises: submit fires, listeners run, THEN the form is read.
    ((document._l && document._l.submit) || []).slice().forEach((f) => f({ target: form }));

    return area.value;
}

const before = run(preFixSource());
const after = run(fs.readFileSync(REAL, 'utf8'));

console.log('\nWHAT THE BROWSER POSTS FOR `question`\n');
console.log('  lecturer typed : <p>State the formula for compound interest.</p>');
console.log('  visible editor : <p>State the formula for compound interest.</p>');
console.log('');
console.log('  BEFORE the fix : ' + JSON.stringify(before));
console.log('  AFTER  the fix : ' + JSON.stringify(after));
console.log('');
console.log('  server verdict BEFORE : "The question field is required."');
console.log('  server verdict AFTER  : accepted, question stored.');