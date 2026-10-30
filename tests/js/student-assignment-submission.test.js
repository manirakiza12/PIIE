'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {tmpdir} = require('node:os');
const {spawn} = require('node:child_process');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync('public/js/student-assignment-submission.js','utf8');
const fixture = kind => fs.readFileSync('tests/Fixtures/student-assignment/'+kind+'.html','utf8');

test('question autosave sends CSRF, synchronizes rich text and waits for the latest in-flight answer', async () => {
    const dom = new JSDOM('<form id="as-form" data-autosave-url="/save"><input name="_token" value="synthetic-token"><div data-as-question="7"><textarea>Old answer</textarea></div></form><p id="as-autosave-status"></p>', {runScripts:'outside-only',url:'https://piie.test'});
    const w = dom.window;
    try {
        w.setInterval = () => 0;
        const calls = [], responses = [];
        let answer = '<p>First rich answer</p>';
        w.PIIEAademicEditor = {syncAll: host => { host.querySelector('textarea').value = answer; }};
        w.fetch = (url, options) => { calls.push(options.body); return new Promise(resolve => responses.push(resolve)); };
        w.eval(fs.readFileSync('public/js/assignment-autosave.js','utf8'));
        const first = w.piieAutosave.flush();
        assert.equal(calls[0].get('_token'), 'synthetic-token');
        assert.equal(calls[0].get('question_id'), '7');
        assert.equal(calls[0].get('text'), answer);
        answer = '<p>Latest rich answer</p>';
        let done = false;
        const final = w.piieAutosave.flush().then(() => { done = true; });
        assert.equal(done, false);
        responses.shift()({ok:true,json:()=>Promise.resolve({saved:true})});
        await new Promise(resolve => setTimeout(resolve, 0));
        assert.equal(calls.length, 2);
        assert.equal(calls[1].get('text'), answer);
        assert.equal(done, false);
        responses.shift()({ok:true,json:()=>Promise.resolve({saved:true})});
        await Promise.all([first, final]);
        assert.equal(w.piieAutosave.failed(), false);
    } finally { w.close(); }
});

test('any-one selector reveals one method, retains values, and keeps optional attachments collapsed', () => {
    const dom = new JSDOM(fixture('any'), {runScripts:'outside-only',url:'https://piie.test'});
    const w=dom.window; try {
        w.eval(script);
        const select=w.document.querySelector('[data-as-method-select]');
        assert.ok(select);
        assert.equal(w.document.querySelectorAll('[data-as-method]:not([hidden])').length,1);
        const text=w.document.querySelector('[name="text"]');text.value='My unfinished answer';
        select.value='document';select.dispatchEvent(new w.Event('change'));
        assert.equal(text.value,'My unfinished answer');assert.equal(text.disabled,true);
        assert.equal(w.document.querySelector('[name="document"]').disabled,false);
        const optional=w.document.querySelector('details:not(#as-review)');assert.equal(optional.open,false);
        select.value='text';select.dispatchEvent(new w.Event('change'));assert.equal(text.value,'My unfinished answer');
    } finally {w.close();}
});

test('save draft posts the same form to its draft action and repeated submits are suppressed', async () => {
    const dom=new JSDOM(fixture('document'),{runScripts:'outside-only',url:'https://piie.test'});
    const w=dom.window;try {
        const sent=[];w.HTMLFormElement.prototype.submit=function(){sent.push(this.action);};
        w.eval(script);const form=w.document.querySelector('#as-form'),draft=form.querySelector('[data-as-draft]');
        form.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:draft}));
        form.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:draft}));
        await Promise.resolve();await Promise.resolve();
        assert.equal(sent.length,1);assert.match(sent[0],/\/draft$/);
        assert.equal(w.document.querySelectorAll('#as-form').length,1);
    } finally {w.close();}
});

test('failed autosave retains work and allows retry without submitting', async () => {
    const dom=new JSDOM(fixture('document'),{runScripts:'outside-only',url:'https://piie.test'});
    const w=dom.window;try {
        let sent=0;w.HTMLFormElement.prototype.submit=()=>sent++;
        w.piieAutosave={flush:()=>Promise.resolve(false),failed:()=>true};w.eval(script);
        const form=w.document.querySelector('#as-form');form.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true}));
        await new Promise(resolve=>setTimeout(resolve,0));
        assert.equal(sent,0);assert.match(form.querySelector('[data-as-submit-status]').textContent,/still here/);
        assert.equal(form.hasAttribute('aria-busy'),false);
    }finally{w.close();}
});

const chrome=process.env.CHROME_BIN||(process.platform==='win32'?'C:/Program Files/Google/Chrome/Application/chrome.exe':'/usr/bin/google-chrome');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
test('real Chrome renders the server-generated assignment at desktop and mobile widths',{
    skip:!fs.existsSync(chrome)&&'Chrome required',timeout:60000,
},async()=>{
    const profile=fs.mkdtempSync(path.join(tmpdir(),'piie-assignment-ui-'));
    const child=spawn(chrome,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
    let socket;try{
        const portFile=path.join(profile,'DevToolsActivePort');
        for(let i=0;i<200&&!fs.existsSync(portFile);i++)await pause(100);
        assert.ok(fs.existsSync(portFile),'Chrome started');
        const port=fs.readFileSync(portFile,'utf8').split('\n')[0];
        const targets=await(await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        let seq=0;const pending=new Map();
        socket.onmessage=event=>{const result=JSON.parse(event.data);if(result.id&&pending.has(result.id)){const {resolve,reject}=pending.get(result.id);pending.delete(result.id);result.error?reject(Error(JSON.stringify(result.error))):resolve(result.result);}};
        const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
        const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true});assert.ok(!result.exceptionDetails,JSON.stringify(result.exceptionDetails));return result.result.value;};
        const css=fs.readFileSync('public/assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css','utf8')+
            fs.readFileSync('resources/views/teacher/course_offerings/assignments/_styles.blade.php','utf8').match(/<style>([\s\S]*?)<\/style>/)[1]+
            fs.readFileSync('resources/views/student/course_assignments/show.blade.php','utf8').match(/<style>([\s\S]*?)<\/style>/)[1];
        await send('Page.enable');fs.mkdirSync('output',{recursive:true});
        for(const width of [1440,390]){
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
            await send('Page.navigate',{url:'data:text/html;charset=utf-8,'+encodeURIComponent('<meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><main class="container-fluid p-3 p-md-4">'+fixture('document')+'</main>')});
            for(let i=0;i<80;i++){if(await evaluate('!!document.querySelector("#as-form")'))break;await pause(20);}
            await evaluate(script);
            const result=await evaluate(`(()=>({forms:document.querySelectorAll('#as-form').length,documents:document.querySelectorAll('input[name=document]').length,overflow:document.documentElement.scrollWidth>innerWidth,submit:document.querySelector('[data-testid=as-submit]').textContent,labels:Array.from(document.querySelectorAll('input:not([type=hidden]),textarea,select')).every(field=>document.querySelector('label[for="'+field.id+'"]'))}))()`);
            assert.equal(result.forms,1);assert.equal(result.documents,1);assert.equal(result.overflow,false);assert.equal(result.labels,true);assert.match(result.submit,/Submit Assignment/);
            await evaluate('document.querySelector("#as-review").open=true');
            const metrics=await send('Page.getLayoutMetrics');
            const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width,height:Math.ceil(metrics.cssContentSize.height),scale:1}});
            fs.writeFileSync('output/student-assignment-'+width+'.png',Buffer.from(screenshot.data,'base64'));
        }
    }finally{
        if(socket)socket.close();child.kill();await pause(400);
        const resolved=path.resolve(profile);if(path.dirname(resolved)===path.resolve(tmpdir())&&path.basename(resolved).startsWith('piie-assignment-ui-')){try{fs.rmSync(resolved,{recursive:true,force:true});}catch{}}
    }
});
