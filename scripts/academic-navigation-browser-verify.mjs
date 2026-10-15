// Checks real Laravel-rendered fixtures with local assets; submits no forms.
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFileSync,writeFileSync,existsSync,mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
const directory=path.resolve('output/academic-navigation-browser'),root=path.resolve('public');
const server=createServer((req,res)=>{
 const url=new URL(req.url,'http://127.0.0.1:8774');
 const fixture=/^\/(years|semesters|curricula|curriculum|create-curriculum|registrations)$/.exec(url.pathname);
 const file=fixture?path.join(directory,fixture[1]+'.html'):path.resolve(root,'.'+url.pathname);
 if(req.method!=='GET'||(!fixture&&!file.startsWith(root+path.sep))||!existsSync(file)){res.writeHead(404);res.end();return;}
 const mime={'.html':'text/html','.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'};
 res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');res.end(readFileSync(file));
});
await new Promise(resolve=>server.listen(8774,'127.0.0.1',resolve));
const profile=mkdtempSync(path.join(tmpdir(),'piie-academic-navigation-'));
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore',windowsHide:true});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));let ws;const results=[],errors=[];
try{
 for(let i=0;i<100&&!existsSync(path.join(profile,'DevToolsActivePort'));i++)await pause(100);
 const port=Number(readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0]);
 const meta=await(await fetch(`http://127.0.0.1:${port}/json/version`)).json();ws=new WebSocket(meta.webSocketDebuggerUrl);
 await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));let seq=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(m.id){const p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);}}});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));setTimeout(()=>{if(pending.delete(id))reject(new Error(method+' timed out'));},30000).unref();});
 const target=await send('Target.createTarget',{url:'about:blank'}),{sessionId}=await send('Target.attachToTarget',{targetId:target.targetId,flatten:true});
 const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},sessionId);if(r.exceptionDetails)throw new Error(r.exceptionDetails.text);return r.result.value;};
 await send('Page.enable',{},sessionId);await send('Runtime.enable',{},sessionId);
 await send('Emulation.setFocusEmulationEnabled',{enabled:true},sessionId);
 for(const page of ['years','semesters','curricula','curriculum','create-curriculum','registrations']){
  await send('Page.navigate',{url:'http://127.0.0.1:8774/'+page},sessionId);
  for(let i=0;i<150;i++){if(await evaluate('document.readyState==="complete" && !!document.querySelector(".mainSection-title")'))break;await pause(100);}await pause(400);
  for(const width of [320,375,768,1366]){
   await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600},sessionId);await pause(250);
   const geometry=await evaluate('({width:innerWidth,document:document.documentElement.scrollWidth,body:document.body.scrollWidth})');
   const clippedControls=await evaluate(`([...document.querySelectorAll('.main_content input,.main_content select,.main_content button,.admin-header-actions button')].filter(el=>{if(el.closest('.table-responsive,.modal:not(.show),.dropdown-menu:not(.show)'))return false;const r=el.getBoundingClientRect();return r.width>0&&(r.left< -1||r.right>innerWidth+1)}).map(el=>el.outerHTML.slice(0,150)))`);
   if(geometry.document>width+1||clippedControls.length){
    const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(directory,`${page}-${width}-overflow.png`),Buffer.from(shot.data,'base64'));
    console.log(await evaluate(`JSON.stringify([...document.querySelectorAll('.table-responsive,.main_content,.eSection-wrap,.home-section')].map(el=>({class:el.className,width:el.getBoundingClientRect().width,overflow:getComputedStyle(el).overflow,display:getComputedStyle(el).display})))`));
   }
   // body.scrollWidth includes content of horizontally scrollable tables in
   // Chromium. Check the document viewport and actual controls instead.
   assert(geometry.document<=width+1&&clippedControls.length===0,`${page} ${width} clips content ${JSON.stringify({geometry,clippedControls})}`);
   if(page==='years'||page==='semesters'){
    for(const id of [page==='years'?'createAcademicYear':'createAcademicPeriod']){
     await evaluate(`document.querySelector('[data-bs-target="#${id}"]').click()`);
     for(let i=0;i<30;i++){if(await evaluate(`document.getElementById('${id}').classList.contains('show') && document.activeElement.closest('#${id}') !== null`))break;await pause(100);}
     const state=await evaluate(`({open:document.getElementById('${id}').classList.contains('show'),focus:document.activeElement.closest('#${id}') !== null,active:document.activeElement.outerHTML.slice(0,200),classes:document.getElementById('${id}').className})`);
     assert(state.open&&state.focus,`Dialog did not open or receive focus: ${JSON.stringify(state)}`);
     const box=await evaluate(`(()=>{const r=document.querySelector('#${id} .modal-content').getBoundingClientRect();return {left:r.left,right:r.right};})()`);assert(box.left>=0&&box.right<=width+1,'Dialog outside viewport');
     assert(await evaluate(`(()=>{const input=document.querySelector('#${id} input:not([type="hidden"]),#${id} select');const r=input.getBoundingClientRect();return document.elementFromPoint(r.left+r.width/2,r.top+r.height/2)===input;})()`),'Dialog input is obscured');
     if(page==='years'&&width===375){const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(directory,`${id}-375.png`),Buffer.from(shot.data,'base64'));}
     await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},sessionId);await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},sessionId);await pause(700);
     assert(await evaluate(`!document.getElementById('${id}').classList.contains('show') && !document.body.classList.contains('modal-open')`),'Escape did not dismiss dialog');
    }
    assert(await evaluate(`(()=>{const y=document.getElementById('current_year'),p=document.getElementById('current_period');y.value='';y.dispatchEvent(new Event('change'));return p.value===''&&[...p.options].filter(o=>o.value).every(o=>o.hidden&&o.disabled);})()`),'Periods not restricted to current year');
   }
   if(width===375||width===1366){const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(directory,`${page}-${width}.png`),Buffer.from(shot.data,'base64'));}
   results.push({page,width,passed:true});
  }
 }
 assert.equal(errors.length,0,JSON.stringify(errors));writeFileSync(path.join(directory,'report.json'),JSON.stringify({results,errors,passed:true},null,2));console.log(JSON.stringify({checks:results.length,errors,passed:true}));
}finally{ws?.close();chrome.kill();server.close();}
