import {createServer} from 'node:http';
import {spawn} from 'node:child_process';
import {readFileSync,writeFileSync,existsSync,mkdtempSync,mkdirSync,statSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
const output=path.resolve('output/library-upgrade');mkdirSync(output,{recursive:true});
const root=path.resolve('public');
const server=createServer((req,res)=>{
 const pathname=new URL(req.url,'http://localhost').pathname;
 if(pathname.startsWith('/preview/')){
  const name=pathname.slice(9);if(!/^[a-z-]+$/.test(name)){res.writeHead(404);res.end();return;}
  let html=readFileSync(path.join(output,name+'.html'),'utf8').replace(/https?:\/\/(?:localhost|127\.0\.0\.1)(?::[0-9]+)?/g,origin);
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html);return;
 }
 const file=path.resolve(root,decodeURIComponent(pathname).replace(/^\/+/,''));
 if(!file.startsWith(root+path.sep)||!existsSync(file)||!statSync(file).isFile()){res.writeHead(404);res.end();return;}
 const ext=path.extname(file);res.setHeader('Content-Type',({'.css':'text/css','.js':'text/javascript','.woff':'font/woff','.woff2':'font/woff2','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg'})[ext]||'application/octet-stream');res.end(readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin='http://127.0.0.1:'+server.address().port;
const profile=mkdtempSync(path.join(tmpdir(),'piie-library-browser-'));
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
 await send('Page.enable',{},sessionId);await send('Network.enable',{},sessionId);await send('Network.setCacheDisabled',{cacheDisabled:true},sessionId);
 const rows=[];
 for(const page of ['overview','catalogue','title','add-title','edit-title','loans','checkout','return','reservations','reports','rules','requests','notices','fines','blocks','staff-access','student-overview','student-catalogue','student-loans','student-reservations','student-requests','student-fines','student-notices','student-readings','librarian-overview','teacher-overview','teacher-requests','teacher-readings','root-overview','root-settings','root-staff'])for(const width of [375,768,1440]){
  await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600},sessionId);
  await send('Page.navigate',{url:origin+'/preview/'+page},sessionId);await pause(180);
  const result=await send('Runtime.evaluate',{expression:`(async()=>{for(let i=0;i<100&&document.readyState!=='complete';i++)await new Promise(r=>setTimeout(r,50));await document.fonts.ready;const area=document.querySelector('.library-workspace');return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,font:getComputedStyle(area).fontFamily,heading:area.querySelector('h1').textContent,panels:area.querySelectorAll('.library-panel').length,unlabelled:[...area.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>!e.labels?.length).map(e=>e.name),csrf:[...area.querySelectorAll('form[method=post]')].every(f=>f.querySelector('[name=_token]')),overflowControls:[...area.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>{const r=e.getBoundingClientRect();return r.width&&r.right>innerWidth+1&&!e.closest('.table-responsive')}).map(e=>e.name)}})()`,awaitPromise:true,returnByValue:true},sessionId);
  const row={page,width,...result.result.value};rows.push(row);writeFileSync(path.join(output,'browser-report.json'),JSON.stringify(rows,null,2));
  assert(row.heading==='Library',`${page}: heading`);assert(row.scrollWidth<=row.width+1,`${page} ${width}: overflow ${row.scrollWidth}`);assert(row.panels>0,`${page}: no panels`);assert.equal(row.unlabelled.length,0,`${page}: unlabelled controls`);assert(row.csrf,`${page}: missing CSRF`);assert.equal(row.overflowControls.length,0,`${page}: controls overflow`);assert(row.font.includes('Nunito'),`${page}: typography`);
  if(['overview','catalogue','checkout','title','student-overview'].includes(page)&&width!==768){const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(output,page+'-'+width+'.png'),Buffer.from(shot.data,'base64'));}
 }
 console.log(`PASS: ${rows.length} library page/viewport checks, responsive geometry, labelled controls and CSRF forms.`);
}finally{ws?.close();chrome.kill();server.close();}
