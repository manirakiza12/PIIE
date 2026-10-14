import {createServer} from 'node:http';
import {spawn} from 'node:child_process';
import {readFileSync,writeFileSync,existsSync,mkdtempSync,statSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';

const output=path.resolve('output/programme-levels');
const publicRoot=path.resolve('public');
let requests=0;
const server=createServer((req,res)=>{
 const pathname=new URL(req.url,'http://localhost').pathname;
 if(req.method==='POST'){
  let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{
   requests++;assert.equal(JSON.parse(body).name,'Higher Education Certificate (HEC)');
   assert(req.headers['x-csrf-token']);
   res.setHeader('Content-Type','application/json');
   if(requests===1){res.writeHead(422);res.end(JSON.stringify({errors:{name:['Please check the level name.']}}));}
   else res.end(JSON.stringify({name:'Higher Education Certificate (HEC)'}));
  });return;
 }
 if(pathname==='/'){
  res.setHeader('Content-Type','text/html; charset=utf-8');
  res.end(readFileSync(path.join(output,'modal.html'),'utf8').replace(/http:\/\/localhost/g,origin));return;
 }
 const file=path.resolve(publicRoot,decodeURIComponent(pathname).replace(/^\/+/,''));
 if(!file.startsWith(publicRoot+path.sep)||!existsSync(file)||!statSync(file).isFile()){res.writeHead(404);res.end();return;}
 res.setHeader('Content-Type',({'.css':'text/css','.js':'text/javascript'})[path.extname(file)]||'application/octet-stream');res.end(readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin='http://127.0.0.1:'+server.address().port;
const profile=mkdtempSync(path.join(tmpdir(),'piie-programme-level-browser-'));
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore',windowsHide:true});
const pause=ms=>new Promise(r=>setTimeout(r,ms));let ws;
try{
 for(let i=0;i<100&&!existsSync(path.join(profile,'DevToolsActivePort'));i++)await pause(100);
 const port=Number(readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0]);
 const metadata=await(await fetch(`http://127.0.0.1:${port}/json/version`)).json();
 ws=new WebSocket(metadata.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let id=0;const pending=new Map();ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(!m.id)return;const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});ws.send(JSON.stringify({id:key,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await send('Target.createTarget',{url:'about:blank'});const {sessionId}=await send('Target.attachToTarget',{targetId:target.targetId,flatten:true});
 await send('Page.enable',{},sessionId);
 const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},sessionId);if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
 const rows=[];
 for(const width of [375,768,1440]){
  requests=0;
  await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width<600},sessionId);
  await send('Page.navigate',{url:origin},sessionId);await pause(350);
  const result=await evaluate(`(async()=>{
   for(let i=0;i<100&&document.readyState!=='complete';i++)await new Promise(r=>setTimeout(r,30));
   const form=document.getElementById('piie-programme-edit'),code=form.querySelector('#piie-code'),name=form.querySelector('#piie-name');
   code.value='HEC-SCI';name.value='Higher Education Science';
   const add=form.querySelector('#piie-add-level'),input=form.querySelector('#piie-new-level'),save=form.querySelector('#piie-save-level'),panel=form.querySelector('#piie-level-panel');
   add.click();input.value='Higher Education Certificate (HEC)';
   const open=!panel.hidden&&document.activeElement===input;
   save.click();while(save.disabled)await new Promise(r=>setTimeout(r,30));
   const errorVisible=form.querySelector('#piie-level-message').textContent==='Please check the level name.'&&!panel.hidden;
   save.click();while(save.disabled)await new Promise(r=>setTimeout(r,30));
   const selected=form.querySelector('#piie-level').value;
   add.click();form.querySelector('#piie-cancel-level').click();
   return {open,errorVisible,selected,preserved:code.value==='HEC-SCI'&&name.value==='Higher Education Science',closed:panel.hidden,forms:document.querySelectorAll('form').length,width:innerWidth,scrollWidth:document.documentElement.scrollWidth};
  })()`);
  assert(result.open);assert(result.errorVisible);assert(result.preserved);assert(result.closed);assert.equal(result.selected,'Higher Education Certificate (HEC)');assert.equal(result.forms,1);assert(result.scrollWidth<=result.width+1);
  rows.push(result);
  await evaluate(`document.getElementById('piie-add-level').click()`);
  const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(output,'add-level-'+width+'.png'),Buffer.from(shot.data,'base64'));
 }
 writeFileSync(path.join(output,'browser-report.json'),JSON.stringify(rows,null,2));
 console.log('PASS: 3 browser sizes; add, server validation error, retry, auto-selection, cancel, preserved programme entries and no overflow.');
}finally{ws?.close();chrome.kill();server.close();}
