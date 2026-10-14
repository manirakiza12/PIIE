const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync('public/assets/js/live-class-room.js', 'utf8');

function fixture({beacon = true, provider = 'ok', attendanceId = 12, moderator = false, height = 900, top = 320} = {}) {
    const dom = new JSDOM('<div id="jitsi-meet-container"></div><script id="piie-live-class-room-config" type="application/json"></script>', {url:'https://piie.example.test/student/live-classes/1/join', runScripts:'outside-only'});
    const config = {domain:'meet.example.test', roomName:'class-1', displayName:'Student', isModerator:moderator, jwt:'user-scoped-test-token', attendanceId, leaveUrl:'/student/live-classes/1/leave', csrfToken:'test-csrf', loadError:'Open External to join the meeting.'};
    dom.window.document.querySelector('#piie-live-class-room-config').textContent = JSON.stringify(config);
    Object.defineProperty(dom.window, 'innerHeight', {value:height, writable:true});
    dom.window.document.querySelector('#jitsi-meet-container').getBoundingClientRect = () => ({top});
    const events = {}, beacons = [], fetches = [];
    let options;
    if (provider !== 'missing') dom.window.JitsiMeetExternalAPI = function (domain, provided) {
        if (provider === 'throws') throw new Error('Provider refused to initialize');
        options = provided;
        this.addEventListener = (name, listener) => {events[name] = listener;};
    };
    dom.window.navigator.sendBeacon = (url, data) => {beacons.push({url, data}); return beacon;};
    dom.window.fetch = (url, provided) => {fetches.push({url, ...provided}); return Promise.resolve({ok:true});};
    dom.window.eval(script);
    return {dom, events, beacons, fetches, options, config};
}

test('switching tabs or locking a phone does not finish meeting attendance', () => {
    const f = fixture();
    Object.defineProperty(f.dom.window.document, 'visibilityState', {value:'hidden'});
    f.dom.window.document.dispatchEvent(new f.dom.window.Event('visibilitychange'));
    assert.equal(f.beacons.length, 0);
    assert.equal(f.fetches.length, 0);
    f.events.videoConferenceLeft();
    assert.equal(f.beacons.length, 1);
    assert.equal(f.beacons[0].data.get('attendance_id'), '12');
    assert.equal(f.beacons[0].data.get('_token'), 'test-csrf');
    f.dom.window.close();
});

test('meeting height fits below the actual header and follows viewport resizing', () => {
    const f = fixture({height:900, top:405});
    const room = f.dom.window.document.querySelector('#jitsi-meet-container');
    assert.equal(room.style.height, '471px');
    f.dom.window.innerHeight = 780;
    f.dom.window.dispatchEvent(new f.dom.window.Event('resize'));
    assert.equal(room.style.height, '351px');
    assert.equal(f.beacons.length, 0);
    f.dom.window.close();
});

test('meeting exit and page departure send attendance once', () => {
    const f = fixture();
    f.events.readyToClose();
    f.events.videoConferenceLeft();
    f.dom.window.dispatchEvent(new f.dom.window.Event('pagehide'));
    assert.equal(f.beacons.length, 1);
    f.dom.window.close();
});

test('page departure records a leave even without a provider leave event', () => {
    const f = fixture();
    f.dom.window.dispatchEvent(new f.dom.window.Event('pagehide'));
    assert.equal(f.beacons.length, 1);
    f.dom.window.close();
});

test('rejected sendBeacon uses one CSRF-protected keepalive request', () => {
    const f = fixture({beacon:false});
    f.events.videoConferenceLeft();
    f.events.readyToClose();
    assert.equal(f.fetches.length, 1);
    assert.equal(f.fetches[0].method, 'POST');
    assert.equal(f.fetches[0].keepalive, true);
    assert.equal(f.fetches[0].body.get('_token'), 'test-csrf');
    f.dom.window.close();
});

test('failed leave delivery can be retried by a later departure event', async () => {
    const f = fixture({beacon:false});
    f.dom.window.fetch = () => Promise.reject(new Error('offline'));
    f.events.videoConferenceLeft();
    await new Promise(resolve => setImmediate(resolve));
    f.dom.window.fetch = (url, provided) => {f.fetches.push({url, ...provided}); return Promise.resolve({ok:true});};
    f.events.readyToClose();
    assert.equal(f.fetches.length, 1);
    f.dom.window.close();
});

test('missing or failing provider shows a usable external fallback', () => {
    for (const provider of ['missing', 'throws']) {
        const f = fixture({provider});
        assert.equal(f.dom.window.document.querySelector('#jitsi-meet-container').textContent, f.config.loadError);
        assert.equal(f.beacons.length, 0);
        f.dom.window.close();
    }
});

test('embed retains the current participant token and role-specific mute behavior', () => {
    for (const moderator of [true, false]) {
        const f = fixture({moderator});
        assert.equal(f.options.jwt, f.config.jwt);
        assert.equal(f.options.configOverwrite.startWithAudioMuted, !moderator);
        f.dom.window.close();
    }
});

test('disabled attendance does not send departure requests', () => {
    const f = fixture({attendanceId:null});
    f.dom.window.dispatchEvent(new f.dom.window.Event('pagehide'));
    assert.equal(f.beacons.length, 0);
    assert.equal(f.fetches.length, 0);
    f.dom.window.close();
});
