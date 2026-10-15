// Rendered Laravel pages with synthetic data; only localhost serves fixtures.
import {createServer} from 'node:http';
import {spawn} from 'node:child_process';
import {readFileSync,writeFileSync,existsSync,mkdtempSync,statSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve('public'),fixtures=path.resolve('output/messaging-review'),origin='http://127.0.0.1:8773';
const server=createServer((req,res)=>{
    const url=new URL(req.url,origin), pages={'/student-inbox':'student-inbox.html','/student-thread':'student-thread.html','/moderation':'moderation.html'};
    if(url.pathname.startsWith('/messages/')) {res.setHeader('Content-Type','application/json');res.end(JSON.stringify({messages:[],count:2,read_through:2,typing:false,writable:true,ok:true}));return;}
    const base=pages[url.pathname]?fixtures:root,file=path.resolve(base,pages[url.pathname]??decodeURIComponent(url.pathname).replace(/^\/+/,''));
    if(!file.startsWith(base+path.sep)||!existsSync(file)||!statSync(file).isFile()){res.writeHead(404);res.end('Fixture resource unavailable');return;}
    const types={'.html':'text/html','.css':'text/css','.js':'application/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2','.woff':'font/woff'};
    res.setHeader('Content-Type',types[path.extname(file)]??'application/octet-stream');res.end(readFileSync(file));
});
await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(8773,'127.0.0.1',resolve);});
const profile=mkdtempSync(path.join(tmpdir(),'piie-chat-review-'));
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{stdio:'ignore',windowsHide:true});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));let ws;
try{
    for(let i=0;i<100&&!existsSync(path.join(profile,'DevToolsActivePort'));i++)await pause(100);
    const port=Number(readFileSync(path.join(profile,'DevToolsActivePort'),'utf8').split('\n')[0]);
    const metadata=await(await fetch(`http://127.0.0.1:${port}/json/version`)).json();
    ws=new WebSocket(metadata.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
    let sequence=0;const waiting=new Map(),errors=[];
    ws.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description??message.params.exceptionDetails.text);if(message.id){const pending=waiting.get(message.id);if(!pending)return;waiting.delete(message.id);message.error?pending.reject(new Error(JSON.stringify(message.error))):pending.resolve(message.result);}});
    const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence;waiting.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
    const target=await send('Target.createTarget',{url:'about:blank'}),{sessionId}=await send('Target.attachToTarget',{targetId:target.targetId,flatten:true});
    const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},sessionId);if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description??'Evaluation failed');return result.result.value;};
    await send('Page.enable',{},sessionId);await send('Runtime.enable',{},sessionId);await send('Network.enable',{},sessionId);
    await send('Network.setBlockedURLs',{urls:['https://*']},sessionId);
    const results=[];
    for(const page of ['student-inbox','student-thread','moderation'])for(const width of [375,768,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600},sessionId);
        await send('Page.navigate',{url:origin+'/'+page},sessionId);
        for(let i=0;i<100;i++){if(await evaluate('document.readyState==="complete" && !!document.querySelector(".portal-messaging")'))break;await pause(100);}
        await pause(350);
        const geometry=await evaluate(`(()=>{const p=document.querySelector('.portal-messaging');const search=p.querySelector('#msg-search');return {viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,panelWidth:p.getBoundingClientRect().width,panelOverflow:p.scrollWidth>p.clientWidth+1,searchVisible:search?search.getBoundingClientRect().width>0:null,heading:p.querySelector('h1').textContent,missingLabels:Array.from(p.querySelectorAll('input:not([type=hidden]),textarea,select')).filter(e=>!e.labels?.length&&!e.getAttribute('aria-label')).map(e=>e.id)};})()`);
        assert(!geometry.panelOverflow,JSON.stringify({page,width,geometry}));assert(geometry.documentWidth<=geometry.viewport+2,JSON.stringify({page,width,geometry}));assert.equal(geometry.missingLabels.length,0);if(page==='student-inbox')assert(geometry.searchVisible);
        const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true},sessionId);writeFileSync(path.join(fixtures,`${page}-${width}.png`),Buffer.from(shot.data,'base64'));
        results.push({page,width,...geometry});
    }
    writeFileSync(path.join(fixtures,'browser-results.json'),JSON.stringify({checks:results,errors},null,2));
    assert.equal(errors.length,0,JSON.stringify(errors));console.log(JSON.stringify({checks:results.length,errors:errors.length}));
}finally{if(ws)ws.close();chrome.kill();server.close();}
