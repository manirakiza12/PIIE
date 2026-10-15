// Browser verification of the synthetic Course Unit fixture. Never submits a form.
import {spawn} from 'node:child_process';
import {existsSync, readFileSync, writeFileSync, mkdirSync, mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {createServer} from 'node:http';

const origin = 'https://piie.ac.ug';
const directory = path.resolve('output/course-unit-setup-browser');
mkdirSync(directory, {recursive:true});
const profile = mkdtempSync(path.join(tmpdir(),'piie-responsive-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',[
  '--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check',
  '--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'
],{stdio:'ignore',windowsHide:true});
const pause = ms => new Promise(resolve=>setTimeout(resolve,ms));
let ws;
const html=readFileSync(path.resolve('output/course-unit-setup-preview.html'),'utf8').replaceAll('http://localhost',origin);
const server=createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const results = [], exceptions = [], failedDocuments = [];
try {
  for(let i=0;i<100&&!existsSync(path.join(profile,'DevToolsActivePort'));i++) await pause(100);
  const port=Number(readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0]);
  const metadata=await(await fetch(`http://127.0.0.1:${port}/json/version`)).json();
  ws=new WebSocket(metadata.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0, currentPath='';
  const waiting=new Map();
  ws.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.id){const pending=waiting.get(message.id);if(!pending)return;waiting.delete(message.id);message.error?pending.reject(new Error(JSON.stringify(message.error))):pending.resolve(message.result);}
    if(message.method==='Runtime.exceptionThrown') exceptions.push({path:currentPath,description:message.params.exceptionDetails.exception?.description??message.params.exceptionDetails.text});
    if(message.method==='Network.responseReceived'&&message.params.type==='Document'&&message.params.response.status>=400) failedDocuments.push({path:currentPath,status:message.params.response.status});
  });
  const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{
    const id=++sequence;waiting.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));
    setTimeout(()=>{if(waiting.has(id)){waiting.delete(id);reject(new Error(`Timed out: ${method}`));}},45000).unref();
  });
  const target=await send('Target.createTarget',{url:'about:blank'});
  const {sessionId}=await send('Target.attachToTarget',{targetId:target.targetId,flatten:true});
  const evaluate=async expression=>{
    const response=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},sessionId);
    if(response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description??response.exceptionDetails.text);
    return response.result.value;
  };
  await send('Page.enable',{},sessionId);await send('Runtime.enable',{},sessionId);await send('Network.enable',{},sessionId);

  await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]},sessionId);
  ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.method==='Fetch.requestPaused'){const p=m.params;send(p.request.method==='POST'?'Fetch.failRequest':'Fetch.continueRequest',{requestId:p.requestId,...(p.request.method==='POST'?{errorReason:'BlockedByClient'}:{})},sessionId).catch(()=>{});}});
  await send('Page.navigate',{url:'http://127.0.0.1:'+server.address().port},sessionId);
  for(let i=0;i<200;i++){if(await evaluate("document.readyState==='complete' && !!document.querySelector('[data-plan]')"))break;await pause(100);}
  await pause(1200);
  const state=await evaluate(`(()=>{const root=document.querySelector('[data-unit-placement]');return {credit:root.querySelector('[name="placement_credits"]').value,stage:root.querySelector('[data-stage]').selectedOptions[0]?.textContent,programme:document.querySelector('[name="programme_id"]').selectedOptions[0]?.textContent,enabled:!root.querySelector('fieldset').disabled};})()`);
  if(state.credit!=='2.00'||state.stage!=='Foundation year'||!state.programme.includes('Business Administration')||!state.enabled)throw Error('Existing selection not restored: '+JSON.stringify(state));
  const toggles=await evaluate(`(()=>{const root=document.querySelector('[data-unit-placement]'),plan=root.querySelector('[data-plan]'),previous=plan.value;plan.value='';plan.dispatchEvent(new Event('change'));const disabled=root.querySelector('fieldset').disabled;plan.value=previous;plan.dispatchEvent(new Event('change'));return disabled&&!root.querySelector('fieldset').disabled&&root.querySelector('[name="placement_credits"]').value==='2.00';})()`);
  if(!toggles)throw Error('Optional placement toggle failed');
  for(const width of [320,375,768,1366]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600},sessionId);await pause(200);
    const metrics=await evaluate('({viewport:innerWidth,width:document.documentElement.scrollWidth,body:document.body.scrollWidth})');
    const pass=metrics.width<=width+1&&metrics.body<=width+1;results.push({width,...metrics,pass});
    const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true},sessionId);
    writeFileSync(path.join(directory,'edit-'+width+'.png'),Buffer.from(shot.data,'base64'));
  }
  const report={state,toggles,results,exceptions,failedDocuments,formSubmissions:0};writeFileSync(path.join(directory,'report.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report));
  if(results.some(r=>!r.pass)||exceptions.length)process.exitCode=1;
} finally {if(ws)ws.close();chrome.kill();server.close();}
