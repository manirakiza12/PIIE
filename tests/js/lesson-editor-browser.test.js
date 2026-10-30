'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync,existsSync,mkdtempSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const path=require('node:path');
const {spawn}=require('node:child_process');
const chrome=process.env.CHROME_BIN || (process.platform==='win32' ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : '/usr/bin/google-chrome');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

test('real Chrome formats lesson text, tables and links without duplicating the compact editor',{
    skip:!existsSync(chrome) && 'Chrome is required for native contenteditable formatting',timeout:60000,
},async()=>{
    const profile=mkdtempSync(path.join(tmpdir(),'piie-lesson-editor-'));
    const child=spawn(chrome,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
    let socket;
    try {
        const portFile=path.join(profile,'DevToolsActivePort');
        for(let i=0;i<200&&!existsSync(portFile);i++) await pause(100);
        assert.ok(existsSync(portFile),'Chrome started');
        const port=readFileSync(portFile,'utf8').split('\n')[0];
        const targets=await(await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        let sequence=0; const pending=new Map();
        socket.onmessage=event=>{
            const result=JSON.parse(event.data);
            if(result.id&&pending.has(result.id)) {const {resolve,reject}=pending.get(result.id);pending.delete(result.id);result.error?reject(Error(JSON.stringify(result.error))):resolve(result.result);}
        };
        const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
        const evaluate=async expression=>{
            const result=await send('Runtime.evaluate',{expression,returnByValue:true});
            assert.ok(!result.exceptionDetails,JSON.stringify(result.exceptionDetails));return result.result.value;
        };
        const styles=readFileSync('public/assets/css/summernote-lite.min.css','utf8')+readFileSync('public/css/academic-editor.css','utf8')+
            readFileSync('resources/views/teacher/course_offerings/content/_surface_styles.blade.php','utf8').match(/<style>([\s\S]*?)<\/style>/)[1];
        await send('Page.enable');
        await send('Page.navigate',{url:'data:text/html;charset=utf-8,'+encodeURIComponent(`<style>${styles}</style><form id="cc-lesson-form"><div class="piie-editor-shell"><textarea id="body" name="body" data-piie-editor data-height="280"></textarea></div></form>`)});
        for(let i=0;i<50;i++) {if(await evaluate('!!document.querySelector("#body")')) break;await pause(20);}
        for(const file of ['public/assets/vendors/jquery/jquery-3.6.0.min.js','public/assets/js/summernote-lite.min.js','public/assets/css/plugin/specialchars/summernote-ext-specialchars.js','public/js/academic-editor.js']) await evaluate(readFileSync(file,'utf8'));
        const result=await evaluate(`(()=>{
            const api=window.PIIEAademicEditor,source=document.querySelector('#body'),form=source.form;
            api.attachAll(document);
            const $source=jQuery(source),editable=document.querySelector('.note-editable');
            $source.summernote('code','<p>Lesson text</p>');
            editable.focus();
            const range=document.createRange();range.selectNodeContents(editable.firstChild);
            const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);
            $source.summernote('editor.setLastRange');
            document.querySelector('.note-btn-bold').click();
            document.querySelector('.note-btn-italic').click();
            const formatted=editable.innerHTML;
            $source.summernote('formatBlock','h2');
            const headed=editable.innerHTML;
            api.focusEndOf(editable);
            $source.summernote('insertUnorderedList');
            const listed=editable.innerHTML;
            api.focusEndOf(editable);
            $source.summernote('insertTable','2x2');
            api.focusEndOf(editable);
            $source.summernote('createLink',{text:'Reference',url:'https://example.org/reading',isNewWindow:true});
            for(let i=0;i<4;i++){api.attachAll(document);api.insertHtml('<p>More learning</p>',editable);api.codeFor('body',form);}
            let pastePrevented=false;
            api.handlePaste({originalEvent:{clipboardData:{getData:type=>type==='text/html'?'<p>Pasted once</p>':'Pasted once'}},currentTarget:editable,preventDefault(){pastePrevented=true;}},{allowImages:true});
            api.syncAll(form);
            return {context:!!$source.data('summernote'),editors:document.querySelectorAll('.note-editor').length,
                formatted,headed,listed,pastePrevented,html:editable.innerHTML,submitted:new FormData(form).get('body'),height:editable.getBoundingClientRect().height};
        })()`);
        assert.equal(result.context,true);
        assert.equal(result.editors,1);
        assert.match(result.formatted,/<(b|strong)[ >]/);
        assert.match(result.formatted,/<(i|em)[ >]/);
        assert.match(result.headed,/<h2[ >]/);
        assert.match(result.listed,/<ul[ >]/);
        assert.match(result.html,/<table[ >]/);
        assert.match(result.html,/https:\/\/example.org\/reading/);
        assert.equal(result.submitted,result.html);
        assert.equal(result.pastePrevented,true);
        assert.equal(result.html.split('Pasted once').length-1,1);
        assert.ok(result.height>=200&&result.height<=300,`compact editing area: ${result.height}px`);
    } finally {
        if(socket) socket.close();child.kill();await pause(500);
        const resolved=path.resolve(profile);
        if(path.dirname(resolved)===path.resolve(tmpdir())&&path.basename(resolved).startsWith('piie-lesson-editor-')) {try{rmSync(resolved,{recursive:true,force:true});}catch{}}
    }
});
