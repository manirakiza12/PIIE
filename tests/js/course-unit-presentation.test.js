const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync, existsSync, mkdtempSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

const chrome = process.env.CHROME_BIN || (process.platform === 'win32'
    ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : '/usr/bin/google-chrome');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

test('Assignments remains readable on hover, keyboard focus and disabled states', {
    skip: !existsSync(chrome) && 'Set CHROME_BIN to run the real-browser contrast check', timeout: 60000,
}, async () => {
    const profile = mkdtempSync(path.join(tmpdir(), 'piie-cover-ui-'));
    const child = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
    let socket;
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; i < 200 && !existsSync(portFile); i++) await pause(100);
        assert.ok(existsSync(portFile), 'Chrome started');
        const port = readFileSync(portFile, 'utf8').split('\n')[0];
        const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let sequence = 0;
        const pending = new Map();
        socket.onmessage = event => {
            const result = JSON.parse(event.data);
            if (result.id && pending.has(result.id)) {
                const { resolve, reject } = pending.get(result.id); pending.delete(result.id);
                result.error ? reject(Error(JSON.stringify(result.error))) : resolve(result.result);
            }
        };
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression, returnByValue: true });
            assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        // Load the portal button styles too, so inherited colours take part in the test.
        const css = ['public/assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css', 'public/assets/css/main.css', 'public/assets/css/custom.css', 'public/assets/css/style.css', 'public/css/student-courses.css']
            .filter(existsSync).map(file => readFileSync(file, 'utf8')).join('\n');
        const html = `<style>${css}</style><div class="sc-card__foot"><a href="#" class="eBtn eBtn-sm sc-assignment-action">Assignments</a></div>`;
        await send('Page.enable');
        await send('Page.navigate', { url: 'data:text/html;charset=utf-8,' + encodeURIComponent(html) });
        for (let i = 0; i < 50; i++) {
            if (await evaluate("!!document.querySelector('.sc-assignment-action')")) break;
            await pause(100);
        }
        const colours = () => evaluate(`(() => { const s = getComputedStyle(document.querySelector('a')); return {color:s.color,background:s.backgroundColor,outline:s.outlineStyle}; })()`);
        const contrast = ({ color, background }) => {
            const luminance = value => {
                const rgb = value.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
                return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
            };
            const a = luminance(color), b = luminance(background);
            return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
        };
        assert.ok(contrast(await colours()) >= 4.5, 'normal text contrast');
        const rect = await evaluate("(() => {const r=document.querySelector('a').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()");
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...rect });
        assert.ok(contrast(await colours()) >= 4.5, 'hover text contrast');
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1000, y: 1000 });
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        assert.equal(await evaluate("document.activeElement === document.querySelector('a')"), true);
        const focused = await colours();
        assert.ok(contrast(focused) >= 4.5, 'keyboard focus text contrast');
        assert.notEqual(focused.outline, 'none', 'visible keyboard focus');
        await evaluate("document.querySelector('a').setAttribute('aria-disabled','true')");
        assert.ok(contrast(await colours()) >= 4.5, 'disabled text contrast');
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...rect });
        assert.ok(contrast(await colours()) >= 4.5, 'disabled hover text contrast');
    } finally {
        if (socket) socket.close();
        child.kill();
        // Chrome may still hold profile files briefly on Windows.
        await pause(500);
        try {
            const resolved = path.resolve(profile);
            if (path.dirname(resolved) === path.resolve(tmpdir()) && path.basename(resolved).startsWith('piie-cover-ui-')) {
                rmSync(resolved, { recursive: true, force: true });
            }
        } catch {}
    }
});
