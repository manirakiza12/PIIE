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
    // JSDOM has no canvas; Summernote measures fonts during a successful init.
    w.HTMLCanvasElement.prototype.getContext = () => ({ measureText: text => ({ width: text.length * 8 }) });
    try {
        const style = w.document.createElement('style');
        style.textContent = fs.readFileSync('public/css/academic-editor.css', 'utf8');
        w.document.head.appendChild(style);
        for (const file of ['public/assets/vendors/jquery/jquery-3.6.0.min.js',
            'public/assets/js/summernote-lite.min.js',
            'public/assets/css/plugin/specialchars/summernote-ext-specialchars.js', 'public/js/academic-editor.js']) {
            w.eval(fs.readFileSync(file, 'utf8'));
            if (file.endsWith('summernote-lite.min.js')) {
                const vendor = w.jQuery.fn.summernote;
                w.jQuery.fn.summernote = function (...args) {
                    try { return vendor.apply(this, args); }
                    catch (error) { w.editorFailure = error.stack; throw error; }
                };
            }
        }
        w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
        await new Promise(resolve => setTimeout(resolve, 100));
        const api = w.PIIEAademicEditor;
        assert.ok(w.jQuery.summernote.plugins.piieEquation, 'equation plugin registers after vendor readiness');
        api.attachAll(w.document);
        assert.equal(w.document.querySelectorAll('.note-editable').length, 1);
        const editable = w.document.querySelector('.note-editable');
        const area = w.document.querySelector('#prompt');
        assert.ok(w.jQuery(area).data('summernote'), 'initialization must finish with a real vendor context: '+w.editorFailure);
        // Alignment and indentation are supplied by the native paragraph menu.
        for (const icon of ['align-left', 'align-center', 'align-right', 'align-justify', 'align-outdent', 'align-indent']) {
            assert.ok(w.document.querySelector('.note-para .note-dropdown-menu button .note-icon-' + icon),
                'paragraph menu offers ' + icon);
        }
        for (let i = 0; i < 4; i++) {
            w.jQuery(area).summernote('code');
            api.attachAll(w.document);
        }
        assert.equal(w.document.querySelectorAll('.note-editor').length, 1, 'recovery reads must not create more editors');
        const cleaned=api.tidyPastedHtml('<h2>Keep formatting</h2><p onclick="alert(1)">Text</p><script>alert(1)</script><a href="javascript:alert(1)">Link</a>');
        assert.match(cleaned,/<h2>Keep formatting<\/h2>/);
        assert.doesNotMatch(cleaned,/onclick|<script|javascript:/);
        api.setCode('prompt', '<h3>Recovered</h3><p><em>Text</em></p>', area.form);
        assert.equal(area.value, '<h3>Recovered</h3><p><em>Text</em></p>');
        assert.equal(w.document.querySelectorAll('.note-editor').length, 1);
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

async function lessonPage(errors = [], initialBody = '', recovery = null) {
    const dom = new JSDOM(`<html><body>
      <div id="cc-recovery" class="d-none"><strong></strong><button type="button" data-cc-recovery-action="restore">Restore</button></div>
      <form id="cc-lesson-form" action="/teacher/course-offerings/30/content/lessons/12" data-cc-draft-key="lesson-test">
        <button type="button" data-cc-step-tab="1"></button><button type="button" data-cc-step-tab="2"></button>
        <div id="cc-step-1" data-cc-step="1"><input id="cc-title" name="title" value="Ratios"><div id="cc-title-note"></div>
        <input id="cc-summary" name="summary"><textarea id="cc-objectives" name="learning_objectives"></textarea>
        <input id="cc-minutes" name="estimated_minutes"><select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></div>
        <div id="cc-step-2" data-cc-step="2" hidden><div class="piie-editor-shell"><textarea id="cc-body" name="body" data-piie-editor></textarea></div></div>
        <button type="button" data-cc-preview>Preview</button><button type="submit" name="status" value="published">Publish</button>
        <div id="cc-save-status"></div>
      </form><form id="cc-resource-form"><input name="body"><input name="title"></form></body></html>`,
      { runScripts: 'outside-only', url: 'https://piie.test' });
    const w = dom.window;
    await new Promise(resolve => w.addEventListener('load', resolve, {once:true}));
    w.HTMLCanvasElement.prototype.getContext = () => ({ measureText: text => ({width:text.length*8}) });
    w.scrollTo = () => {};
    w.document.querySelector('#cc-body').value = initialBody;
    if (recovery) w.localStorage.setItem('lesson-test', JSON.stringify(recovery));
    for (const file of ['public/assets/vendors/jquery/jquery-3.6.0.min.js','public/assets/js/summernote-lite.min.js',
        'public/assets/css/plugin/specialchars/summernote-ext-specialchars.js','public/js/academic-editor.js']) w.eval(fs.readFileSync(file,'utf8'));
    const template = fs.readFileSync('resources/views/teacher/course_offerings/content/lesson_form.blade.php','utf8');
    const script = template.match(/<script>([\s\S]*?)<\/script>/)[1].replace('@json($lessonErrors->keys())', JSON.stringify(errors));
    w.eval(script);
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
    return dom;
}

test('actual lesson steps, recovery snapshot, preview and submit keep a single editor and the submitted body', async () => {
    const dom = await lessonPage(); const w = dom.window;
    try {
        const doc=w.document, api=w.PIIEAademicEditor, form=doc.querySelector('#cc-lesson-form');
        doc.querySelector('[data-cc-step-tab="2"]').click();
        api.setCode('body','<h2>Ratios</h2><p><strong>Assets</strong> / <em>liabilities</em></p><ul><li>Calculate</li></ul><table><tr><td>10</td></tr></table>',form);
        doc.querySelector('.note-editable').dispatchEvent(new w.Event('input',{bubbles:true}));
        const html=api.codeFor('body',form);
        for(let i=0;i<4;i++) { doc.querySelector('[data-cc-step-tab="1"]').click(); doc.querySelector('[data-cc-step-tab="2"]').click(); api.attachAll(doc); }
        await new Promise(resolve=>setTimeout(resolve,1650));
        assert.equal(JSON.parse(w.localStorage.getItem('lesson-test')).body,html);
        assert.equal(doc.querySelectorAll('.note-editor').length,1);
        let preview;
        w.HTMLFormElement.prototype.submit=function(){ preview=new w.FormData(this); };
        doc.querySelector('[data-cc-preview]').click();
        assert.equal(preview.get('body'),html);
        assert.equal(doc.querySelectorAll('.note-editor').length,1);
        const publish=doc.querySelector('button[type="submit"]');
        form.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:publish}));
        assert.equal(new w.FormData(form).get('body'),html);
        assert.equal(new w.FormData(form).get('status'),'published');
        assert.equal(publish.disabled,true);
        assert.equal(form.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:publish})),false);
        assert.equal(doc.querySelectorAll('.note-editor').length,1);
    } finally { w.close(); }
});

test('validation redisplay and explicit recovery restore keep content and a single instance', async () => {
    const dom=await lessonPage(['assignment_id'],'<h3>Server restored text</h3>',{title:'Recovered lesson',body:'<p>Recovered <b>writing</b></p>'});
    const w=dom.window;
    try {
        const doc=w.document, form=doc.querySelector('#cc-lesson-form');
        assert.equal(doc.querySelector('#cc-step-2').hidden,false);
        assert.equal(w.PIIEAademicEditor.codeFor('body',form),'<h3>Server restored text</h3>');
        doc.querySelector('[data-cc-recovery-action="restore"]').click();
        assert.equal(w.PIIEAademicEditor.codeFor('body',form),'<p>Recovered <b>writing</b></p>');
        const resource=doc.querySelector('#cc-resource-form');
        resource.dispatchEvent(new w.Event('submit',{cancelable:true}));
        assert.equal(new w.FormData(resource).get('body'),'<p>Recovered <b>writing</b></p>');
        assert.equal(doc.querySelectorAll('.note-editor').length,1);
    } finally { w.close(); }
});

test('a plugin failure removes orphan DOM without losing the source or duplicating on retry', async () => {
    const dom=new JSDOM('<form><div class="piie-editor-shell"><textarea id="broken" name="body" data-piie-editor>&lt;p&gt;Saved text&lt;/p&gt;</textarea></div><div class="piie-editor-shell"><textarea id="healthy" name="other" data-piie-editor></textarea></div></form>',{runScripts:'outside-only',url:'https://piie.test'});
    const w=dom.window;
    try {
        await new Promise(resolve=>w.addEventListener('load',resolve,{once:true}));
        w.HTMLCanvasElement.prototype.getContext=()=>({measureText:text=>({width:text.length*8})});
        for(const file of ['public/assets/vendors/jquery/jquery-3.6.0.min.js','public/assets/js/summernote-lite.min.js']) w.eval(fs.readFileSync(file,'utf8'));
        w.jQuery.summernote.plugins.failureProbe=function(context){ if(context.layoutInfo.note.attr('id')==='broken') throw new Error('Injected plugin failure'); };
        w.eval(fs.readFileSync('public/js/academic-editor.js','utf8'));
        const source=w.document.querySelector('#broken');
        for(let i=0;i<4;i++) w.PIIEAademicEditor.attachAll(w.document);
        assert.equal(source.value,'<p>Saved text</p>');
        assert.equal(source.closest('.piie-editor-shell').querySelectorAll('.note-editor').length,0);
        assert.equal(w.document.querySelectorAll('.note-editor').length,1,'the other field keeps its working editor');
        assert.equal(source.classList.contains('piie-editor-source'),false,'fallback source stays usable');
    } finally { w.close(); }
});
