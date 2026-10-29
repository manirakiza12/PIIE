'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

test('real vendor mounts one editor per field and submits the visible prompt', async () => {
    const dom = new JSDOM(`<html><body><form><div class="piie-editor-shell">
        <textarea id="prompt" name="prompt" data-piie-editor class="piie-editor-source"></textarea>
        </div><button type="submit">Save</button></form></body></html>`,
        { runScripts: 'outside-only', url: 'https://piie.test' });
    const w = dom.window;
    try {
        const style = w.document.createElement('style');
        style.textContent = fs.readFileSync('public/css/academic-editor.css', 'utf8');
        w.document.head.appendChild(style);
        for (const file of ['public/assets/vendors/jquery/jquery-3.6.0.min.js',
            'public/assets/js/summernote-lite.min.js', 'public/js/academic-editor.js']) {
            w.eval(fs.readFileSync(file, 'utf8'));
        }
        w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
        await new Promise(resolve => setTimeout(resolve, 100));
        const api = w.PIIEAademicEditor;
        assert.ok(w.jQuery.summernote.plugins.piieEquation, 'equation plugin registers after vendor readiness');
        api.attachAll(w.document);
        assert.equal(w.document.querySelectorAll('.note-editable').length, 1);
        const editable = w.document.querySelector('.note-editable');
        const area = w.document.querySelector('#prompt');
        editable.innerHTML = '<h2>Administrative logs</h2><p>Describe the <strong>daily log</strong>.</p>';
        editable.dispatchEvent(new w.Event('input', { bubbles: true }));
        const form = area.form;
        form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
        assert.equal(new w.FormData(form).get('prompt'), editable.innerHTML);
        assert.equal(w.getComputedStyle(area).display, 'none');
        editable.innerHTML = '<p><br></p>';
        form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
        assert.equal(new w.FormData(form).get('prompt'), '<p><br></p>');
    } finally {
        w.close();
    }
});
