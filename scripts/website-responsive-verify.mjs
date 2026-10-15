// Read-only Chrome checks against public pages. Never submits a form.
import {spawn} from 'node:child_process';
import {existsSync, readFileSync, writeFileSync, mkdirSync, mkdtempSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const origin = 'https://piie.ac.ug';
const verifyContacts = process.argv.includes('--contacts');
const directory = path.resolve(verifyContacts ? 'output/public-contact-browser' : 'output/website-responsive-2026-10-15');
mkdirSync(directory, {recursive:true});
const profile = mkdtempSync(path.join(tmpdir(),'piie-responsive-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',[
  '--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check',
  '--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'
],{stdio:'ignore',windowsHide:true});
const pause = ms => new Promise(resolve=>setTimeout(resolve,ms));
let ws;
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
  const pages=['/','/about-us','/academic-programmes','/academic-programmes?category=postgraduate','/academic-programmes?category=diploma&level=PGD','/academic-programmes?category=bachelors','/academic-programmes?category=diploma&level=Diploma','/academic-programmes?category=certificate','/admissions','/research-and-innovation','/careers','/contact-us','/login','/password/reset','/applicant/login','/applicant/register','/apply'];
  const widths=[320,375,390,768,1024,1366,1440];
  for(const route of pages){
    currentPath=route;
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:true},sessionId);
    const navigation=await send('Page.navigate',{url:origin+route},sessionId);
    if(navigation.errorText) throw new Error(`${route}: ${navigation.errorText}`);
    let loaded=false;
    for(let i=0;i<200;i++){
      if(await evaluate(`location.origin===${JSON.stringify(origin)} && document.readyState==='complete' && document.body.innerText.trim().length>10 && !!document.querySelector('main,form,.piie-site')`)){loaded=true;break;}
      await pause(100);
    }
    if(!loaded) throw new Error(`Public page failed to load: ${route}`);
    await evaluate(`Promise.race([document.fonts.ready,new Promise(r=>setTimeout(r,2500))]).then(()=>true)`);
    // Load real below-the-fold content, including lazy images.
    await evaluate(`(async()=>{for(let y=0;y<Math.min(document.documentElement.scrollHeight,30000);y+=850){scrollTo(0,y);await new Promise(r=>setTimeout(r,35));}scrollTo(0,0);})()`);
    await pause(200);
    if(route==='/academic-programmes'){
      const detail=await evaluate(`document.querySelector('a[href*="/programmes/"]')?.getAttribute('href')??null`);
      if(detail){const url=new URL(detail,origin);if(url.origin===origin&&!pages.includes(url.pathname))pages.push(url.pathname);}
    }
    for(const width of widths){
      await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600},sessionId);await pause(120);
      await evaluate('scrollTo(0,0)');
      const geometry=await evaluate(`(()=>{
        const visible=e=>{const s=getComputedStyle(e),r=e.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&Number(s.opacity)!==0&&r.width>0&&r.height>0;};
        const overflow=[...document.querySelectorAll('main *,header *,footer *,form *')].filter(visible).map(e=>({e,r:e.getBoundingClientRect()})).filter(({r})=>r.left< -1||r.right>innerWidth+1).slice(0,10).map(({e,r})=>({tag:e.tagName,class:e.className,left:Math.round(r.left),right:Math.round(r.right),text:e.textContent.trim().slice(0,70)}));
        const brokenImages=[...document.images].filter(e=>e.complete&&e.naturalWidth===0&&visible(e)).map(e=>e.getAttribute('src'));
        const inputs=[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(visible).map(e=>({name:e.name,width:Math.round(e.getBoundingClientRect().width)}));
        return {viewport:innerWidth,scrollWidth:document.documentElement.scrollWidth,bodyWidth:document.body.scrollWidth,overflow,brokenImages,inputs,hasViewport:!!document.querySelector('meta[name=viewport]'),font:getComputedStyle(document.body).fontFamily};
      })()`);
      const menu=await evaluate(`(()=>{const b=document.querySelector('[data-pii-burger]');return {present:!!b,visible:!!b&&getComputedStyle(b).display!=='none'};})()`);
      if(menu.visible){
        await evaluate(`document.querySelector('[data-pii-burger]').click()`);await pause(80);
        Object.assign(menu,await evaluate(`(()=>{const b=document.querySelector('[data-pii-burger]'),p=document.getElementById('piie-mobile-nav');return {opens:b.getAttribute('aria-expanded')==='true'&&getComputedStyle(p).display!=='none',openScrollWidth:document.documentElement.scrollWidth,links:p.querySelectorAll('a').length};})()`));
        await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`);await pause(50);
        menu.escapeCloses=await evaluate(`document.querySelector('[data-pii-burger]').getAttribute('aria-expanded')==='false'`);
        if(!menu.escapeCloses)await evaluate(`document.querySelector('[data-pii-burger]').click()`);
      }
      const dropdowns=await evaluate(`(()=>{return [...document.querySelectorAll('[data-pii-dropdown]')].filter(e=>getComputedStyle(e).display!=='none'&&e.getBoundingClientRect().width>0).map(e=>{const b=e.querySelector('button');b.click();const opens=b.getAttribute('aria-expanded')==='true';document.body.dispatchEvent(new MouseEvent('click',{bubbles:true}));return {opens,outsideCloses:b.getAttribute('aria-expanded')==='false'};});})()`);
      let passwordToggleWorks=true;
      if(route==='/login')passwordToggleWorks=await evaluate(`(()=>{const b=document.getElementById('togglePasswordBtn'),p=document.getElementById('passwordInput');if(!b||!p)return false;b.click();const shown=p.type==='text';b.click();return shown&&p.type==='password';})()`);
      const contacts = verifyContacts ? await evaluate(`(()=>{const ribbon=document.querySelector('.piie-utility');if(!ribbon)return {pass:true,applicable:false};const phone=ribbon.querySelector('a[href="tel:+256788099193"]');const visible=!!phone&&phone.getBoundingClientRect().width>0&&phone.getBoundingClientRect().height>0;const email=!!ribbon.querySelector('a[href="mailto:info@piie.ac.ug"]');const website=!!ribbon.querySelector('a[href="https://piie.ac.ug"]');const noOldDomain=!document.documentElement.outerHTML.toLowerCase().includes('tdiibt.ac.ug');return {applicable:true,visible,email,website,noOldDomain,pass:visible&&email&&website&&noOldDomain};})()`) : {pass:true};
      const row={route,width,...geometry,menu,dropdowns,passwordToggleWorks,contacts,pass:contacts.pass&&geometry.hasViewport&&geometry.scrollWidth<=width+1&&geometry.bodyWidth<=width+1&&passwordToggleWorks&&dropdowns.every(d=>d.opens&&d.outsideCloses)&&(!menu.visible||(menu.opens&&menu.escapeCloses&&menu.openScrollWidth<=width+1))};
      results.push(row);
      if([375,1366].includes(width)||!row.pass){
        const name=(route==='/'?'home':route.replace(/[^a-z0-9]+/gi,'-').replace(/^-|-$/g,''));
        const shot=await send('Page.captureScreenshot',{format:'png'},sessionId);writeFileSync(path.join(directory,`${name}-${width}.png`),Buffer.from(shot.data,'base64'));
      }
    }
    console.log(JSON.stringify({route,checks:widths.length,failures:results.filter(r=>r.route===route&&!r.pass).map(r=>({width:r.width,scrollWidth:r.scrollWidth,menu:r.menu,overflow:r.overflow}))}));
  }
  const report={origin,checkedAt:new Date().toISOString(),results,exceptions,failedDocuments,passed:results.every(r=>r.pass)&&exceptions.length===0&&failedDocuments.length===0};
  writeFileSync(path.join(directory,'report.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify({checks:results.length,layoutFailures:results.filter(r=>!r.pass).length,javascriptErrors:exceptions.length,failedDocuments,report:path.join(directory,'report.json')}));
  if(!report.passed)process.exitCode=1;
}finally{ws?.close();chrome.kill();}
