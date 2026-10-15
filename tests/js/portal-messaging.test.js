const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const source = readFileSync('public/js/portal-messaging.js', 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture() {
    const dom = new JSDOM(`<section id="portal-chat" data-poll="/updates" data-read="/read" data-typing="/typing" data-send="/send">
        <ol id="msg-history"><li data-id="1" data-mine="1"><p>Earlier</p><span class="msg-receipt"> · Sent</span></li></ol>
        <button id="msg-older">Earlier</button><div id="msg-status"></div><p id="msg-typing" hidden>Typing</p>
        <form id="msg-compose"><input type="hidden" name="_token" value="csrf-value"><input type="hidden" name="client_message_id" value="submission-one">
        <textarea name="message" id="msg-text" required></textarea><button type="submit">Send</button></form></section>`,
        {url:'https://piie.test/messages/threads/1',runScripts:'outside-only',pretendToBeVisual:true});
    const calls = [], timers = [];
    let nextSend = {message:{id:3,text:'Sent text',mine:true,read:false,time:'2026-10-15T08:00:00Z'}};
    let nextPoll = {messages:[],read_through:0,typing:false,writable:true};
    Object.defineProperty(dom.window.document,'hasFocus',{value:()=>true});
    dom.window.setInterval = fn => {timers.push(fn);return 1;};
    dom.window.fetch = async (url,options) => {
        calls.push({url,options});
        let data = String(url).startsWith('/updates') ? nextPoll : url === '/send' ? nextSend : {ok:true};
        if (data instanceof Error) throw data;
        return {ok:!data.error,status:data.error||200,redirected:false,headers:{get:()=> 'application/json'},json:async()=>data};
    };
    dom.window.eval(source);
    return {dom,calls,timers,send:value=>{nextSend=value;},poll:value=>{nextPoll=value;}};
}

test('poll renders incoming HTML as text, updates receipts and acknowledges visible messages',async()=>{
    const f=fixture();await tick();
    f.poll({messages:[{id:2,text:'<img src=x onerror=alert(1)>',mine:false,read:false,time:'2026-10-15T08:00:00Z'}],read_through:1,typing:true,writable:true});
    await f.timers[0]();
    const d=f.dom.window.document;
    assert.equal(d.querySelector('[data-id="2"] p').textContent,'<img src=x onerror=alert(1)>');
    assert.equal(d.querySelector('#msg-history img'),null);
    assert.equal(d.querySelector('[data-id="1"] .msg-receipt').textContent,' · Read');
    assert.equal(d.getElementById('msg-typing').hidden,false);
    assert(f.calls.some(c=>c.url==='/read'&&JSON.parse(c.options.body).through===2));
    f.dom.window.close();
});

test('successful send uses CSRF and a submission token, confirms storage and clears the submitted draft',async()=>{
    const f=fixture();await tick();const d=f.dom.window.document;
    d.getElementById('msg-text').value='Sent text';
    d.getElementById('msg-compose').dispatchEvent(new f.dom.window.Event('submit',{cancelable:true}));await tick();
    const call=f.calls.find(c=>c.url==='/send');
    assert.equal(call.options.headers['X-CSRF-TOKEN'],'csrf-value');
    assert.equal(JSON.parse(call.options.body).client_message_id,'submission-one');
    assert.equal(d.getElementById('msg-text').value,'');
    assert.equal(d.getElementById('msg-status').textContent,'Message sent.');
    assert.equal(d.querySelectorAll('[data-id="3"]').length,1);
    f.dom.window.close();
});

test('failed sends retain the draft and reuse the same token on retry',async()=>{
    const f=fixture();await tick();const d=f.dom.window.document;
    f.send(new Error('Disconnected'));d.getElementById('msg-text').value='Keep this draft';
    const submit=()=>d.getElementById('msg-compose').dispatchEvent(new f.dom.window.Event('submit',{cancelable:true}));
    submit();await tick();submit();await tick();
    assert.equal(d.getElementById('msg-text').value,'Keep this draft');
    const calls=f.calls.filter(c=>c.url==='/send');assert.equal(calls.length,2);
    assert.equal(JSON.parse(calls[0].options.body).client_message_id,JSON.parse(calls[1].options.body).client_message_id);
    assert.equal(d.querySelector('[type=submit]').disabled,false);
    f.dom.window.close();
});

test('sending does not skip incoming messages that arrived before the outgoing acknowledgement',async()=>{
    const f=fixture();await tick();const d=f.dom.window.document;
    d.getElementById('msg-text').value='Sent text';d.getElementById('msg-compose').dispatchEvent(new f.dom.window.Event('submit',{cancelable:true}));await tick();
    f.poll({messages:[{id:2,text:'Arrived before send',mine:false,read:false,time:'2026-10-15T08:00:00Z'},
        {id:3,text:'Sent text',mine:true,read:false,time:'2026-10-15T08:00:00Z'}],read_through:0,typing:false,writable:true});
    await f.timers[0]();
    assert(f.calls.some(c=>c.url==='/updates?after=1'));
    assert.equal(d.querySelectorAll('[data-id="2"]').length,1);assert.equal(d.querySelectorAll('[data-id="3"]').length,1);
    assert.deepEqual(Array.from(d.querySelectorAll('#msg-history li')).map(n=>Number(n.dataset.id)),[1,2,3]);
    f.dom.window.close();
});

test('revoked send access disables the composer and does not remove history',async()=>{
    const f=fixture();await tick();f.poll({messages:[],read_through:0,typing:false,writable:false});await f.timers[0]();
    const d=f.dom.window.document;assert.equal(d.getElementById('msg-text').disabled,true);
    assert.equal(d.querySelectorAll('#msg-history li').length,1);f.dom.window.close();
});

test('session and rate-limit failures show actionable status without losing draft text',async()=>{
    const f=fixture();await tick();const d=f.dom.window.document;
    f.send({error:429});d.getElementById('msg-text').value='Unsent draft';
    d.getElementById('msg-compose').dispatchEvent(new f.dom.window.Event('submit',{cancelable:true}));await tick();
    assert.match(d.getElementById('msg-status').textContent,/wait a minute/);assert.equal(d.getElementById('msg-text').value,'Unsent draft');
    f.dom.window.close();
});
