/* Shared student/staff conversation controls. No message content enters HTML. */
(() => {
    'use strict';
    const root = document.getElementById('portal-chat');
    if (!root) return;
    const history = document.getElementById('msg-history');
    const form = document.getElementById('msg-compose');
    const textarea = document.getElementById('msg-text');
    const status = document.getElementById('msg-status');
    const sendButton = form.querySelector('button[type=submit]');
    const older = document.getElementById('msg-older');
    const csrf = form.querySelector('[name=_token]').value;
    let busy = false, sending = false, acknowledged = 0, lastTyping = 0;
    let cursor = Math.max(0, ...Array.from(history.querySelectorAll('[data-id]')).map(node => Number(node.dataset.id)));
    function submissionId() {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }
    const ids = () => Array.from(history.querySelectorAll('[data-id]')).map(node => Number(node.dataset.id));
    function message(text, error = false) {status.textContent = text; status.dataset.error = String(error);}
    async function request(url, body) {
        const response = await fetch(url, {method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: {'Accept': 'application/json', ...(body ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf} : {})},
            ...(body ? {body: JSON.stringify(body)} : {})});
        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
            if (response.status === 401 || response.status === 419 || response.redirected) throw new Error('Your session expired. Sign in again; keep a copy of your unsent message.');
            if (response.status === 403) throw new Error('Sending is unavailable. The contact policy, account status or a block may have changed.');
            if (response.status === 429) throw new Error('Please wait a minute before trying again.');
            if (response.status === 422) {
                const result = await response.json();
                throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Check your message and try again.');
            }
            throw new Error('Could not connect. Your message remains here; try again.');
        }
        return response.json();
    }
    function append(items, prepend = false) {
        for (const item of items) {
            if (history.querySelector(`[data-id="${Number(item.id)}"]`)) continue;
            const node = document.createElement('li');
            node.className = 'msg-bubble' + (item.mine ? ' msg-mine' : '');
            node.dataset.id = String(item.id); node.dataset.mine = item.mine ? '1' : '0';
            const text = document.createElement('p'); text.textContent = item.text;
            const small = document.createElement('small');
            const time = document.createElement('time'); time.dateTime = item.time;
            time.textContent = new Date(item.time).toLocaleString(undefined, {timeZone: root.dataset.timezone || 'UTC'});
            const receipt = document.createElement('span'); receipt.className = 'msg-receipt';
            receipt.textContent = item.mine ? (item.read ? ' · Read' : ' · Sent') : '';
            small.append(time, document.createTextNode(" ? #" + item.id), receipt); node.append(text, small);
            const later = Array.from(history.children).find(existing => Number(existing.dataset.id) > Number(item.id));
            history.insertBefore(node, later || null);
        }
        if (items.length) document.getElementById('msg-empty')?.remove();
    }
    async function acknowledge() {
        if (document.hidden || !document.hasFocus()) return;
        const last = cursor;
        if (last <= acknowledged) return;
        await request(root.dataset.read, {through: last}); acknowledged = last;
    }
    async function refresh() {
        if (busy || document.hidden || sending) return;
        busy = true;
        try {
            const previous = cursor;
            const nearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 100;
            const data = await request(root.dataset.poll + '?after=' + previous);
            append(data.messages);
            cursor = Math.max(cursor, ...data.messages.map(item => Number(item.id)));
            for (const node of history.querySelectorAll('[data-mine="1"]')) {
                if (Number(node.dataset.id) <= data.read_through) node.querySelector('.msg-receipt').textContent = ' · Read';
            }
            document.getElementById('msg-typing').hidden = !data.typing;
            textarea.disabled = !data.writable; sendButton.disabled = !data.writable;
            if (data.messages.length && nearBottom) history.scrollTop = history.scrollHeight;
            if (data.messages.length) message('Conversation updated.');
            await acknowledge();
        } catch (error) {message(error.message + ' Automatic refresh will retry.', true);}
        finally {busy = false;}
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending || !form.reportValidity()) return;
        sending = true; sendButton.disabled = true; message('Sending…');
        const submission = textarea.value;
        try {
            const data = await request(root.dataset.send, {message: submission, client_message_id: form.elements.client_message_id.value});
            append([data.message]);
            if (textarea.value === submission) textarea.value = '';
            form.elements.client_message_id.value = submissionId();
            history.scrollTop = history.scrollHeight;
            message('Message sent.');
        } catch (error) {message(error.message, true);}
        finally {sending = false; sendButton.disabled = textarea.disabled;}
    });
    textarea.addEventListener('input', () => {
        if (!textarea.value.trim() || Date.now() - lastTyping < 4000) return;
        lastTyping = Date.now(); request(root.dataset.typing, {typing: true}).catch(() => {});
    });
    older.addEventListener('click', async () => {
        older.disabled = true;
        const previousHeight = history.scrollHeight;
        try {
            const first = Math.min(...ids());
            const data = await request(root.dataset.poll + '?before=' + first);
            append(data.messages, true); older.hidden = data.messages.length < 50;
            history.scrollTop += history.scrollHeight - previousHeight;
        } catch (error) {message(error.message, true);}
        finally {older.disabled = false;}
    });
    history.scrollTop = history.scrollHeight;
    acknowledge().catch(() => {});
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => {if (!document.hidden) refresh();});
    setInterval(refresh, 8000);
})();
