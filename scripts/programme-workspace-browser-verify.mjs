import {createServer} from 'node:http';
import {spawn} from 'node:child_process';
import {readFileSync,writeFileSync,existsSync,mkdtempSync,statSync,readdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';

const output=path.resolve('output/programme-workspace-browser'),publicRoot=path.resolve('public');
const pages=readdirSync(output).filter(f=>f.endsWith('.html')).map(f=>f.slice(0,-5));
assert(pages.length>=12,'Capture the programme workspace fixtures first.');
const server=createServer((req,res)=>{
 const pathname=new URL(req.url,'http://localhost').pathname;
 if(req.method!=='GET'){res.writeHead(405);res.end();return;}
 const fixture=path.join(output,pathname.slice(1)+'.html');
 if(pages.includes(pathname.slice(1))&&existsSync(fixture)){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(readFileSync(fixture,'utf8').replaceAll('http://localhost',origin));return;}
 const asset=path.resolve(publicRoot,decodeURIComponent(pathname).replace(/^\/+/,''));
 if(asset.startsWith(publicRoot+path.sep)&&existsSync(asset)&&statSync(asset).isFile()){
  res.setHeader('Content-Type',({'.css':'text/css','.js':'text/javascript','.woff2':'font/woff2','.png':'image/png','.svg':'image/svg+xml'})[path.extname(asset)]||'application/octet-stream');res.end(readFileSync(asset));return;
 }
 if(pathname.startsWith('/admin/')){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({count:0,notifications:[],data:[]}));return;}
 res.writeHead(404);res.end();
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin='http://127.0.0.1:'+server.address().port;
const profile=mkdtempSync(path.join(tmpdir(),'piie-programme-workspace-'));
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore',windowsHide:true});
const pause=ms=>new Promise(r=>setTimeout(r,ms));let ws;
try{
 let port;for(let i=0;i<100;i++){const f=path.join(profile,'DevToolsActivePort');if(existsSync(f)){port=readFileSync(f,'utf8').split('\n')[0];break;}await pause(100);}assert(port,'Chrome did not start');
 const metadata=await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
 ws=new WebSocket(metadata.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let id=0;const pending=new Map(),errors=[];
 ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);if(!m.id)return;const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});ws.send(JSON.stringify({id:key,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await send('Target.createTarget',{url:'about:blank'}),{sessionId}=await send('Target.attachToTarget',{targetId:target.targetId,flatten:true});
 await send('Page.enable',{},sessionId);await send('Runtime.enable',{},sessionId);
 const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},sessionId);if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
 const rows=[];
 for(const page of pages)for(const width of [320,375,768,1366]){
  errors.length=0;await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600},sessionId);
  await send('Page.navigate',{url:origin+'/'+page},sessionId);await pause(180);
  const result=await evaluate(`(async()=>{for(let i=0;i<100&&document.readyState!=='complete';i++)await new Promise(r=>setTimeout(r,20));const area=document.querySelector('#page-print-area'),guide=area.querySelector('aside[aria-label="Academic workflow guidance"]');return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,guide:!!guide,guideLinks:guide?.querySelectorAll('a').length,forms:area.querySelectorAll('form').length,heading:area.querySelector('h4')?.textContent,clipped:[...area.querySelectorAll('button,input,select,textarea')].filter(e=>{if(!e.getClientRects().length||e.closest('.table-responsive'))return false;const r=e.getBoundingClientRect();return r.left< -1||r.right>innerWidth+1;}).map(e=>e.id||e.textContent.trim().slice(0,35))};})()`);
  if(page==='allocation-create'){
   result.allocationForm=await evaluate(`(()=>{const subject=document.getElementById('subject'),year=document.getElementById('year'),period=document.getElementById('period'),placement=document.getElementById('curriculum-placement');subject.value=subject.options[1].value;year.value=year.options[1].value;year.dispatchEvent(new Event('change'));period.value=[...period.options].find(o=>o.value&&!o.disabled).value;period.dispatchEvent(new Event('change'));subject.dispatchEvent(new Event('change'));const matches=[...placement.options].filter(o=>o.value&&!o.disabled);const reference=document.getElementById('reference-preview').value;placement.value=matches[0]?.value||'';subject.value='';subject.dispatchEvent(new Event('change'));return {compatibleChoices:matches.length,referenceGenerated:!!reference,staleSelectionCleared:placement.value===''};})()`);
   assert(result.allocationForm.compatibleChoices>0);assert(result.allocationForm.referenceGenerated);assert(result.allocationForm.staleSelectionCleared);
  }
  rows.push({page,width,...result,errors:[...errors]});
  if(width===320||width===1366){const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(output,`${page}-${width}.png`),Buffer.from(shot.data,'base64'));}
 }
 writeFileSync(path.join(output,'browser-report.json'),JSON.stringify(rows,null,2));
 const failures=rows.filter(r=>r.scrollWidth>r.width+1||!r.guide||r.guideLinks||r.clipped.length||r.errors.length);
 if(failures.length){console.log(JSON.stringify(failures,null,2));process.exitCode=1;}else console.log(`PASS: ${rows.length} page/viewport checks, preserved guides, no guide quick links, no clipped controls or JavaScript exceptions.`);
}finally{ws?.close();chrome.kill();server.close();}
