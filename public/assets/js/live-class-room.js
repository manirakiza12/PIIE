(function (window, document) {
    'use strict';
    var container = document.getElementById('jitsi-meet-container');
    var configuration = document.getElementById('piie-live-class-room-config');
    if (!container || !configuration) return;
    var config = JSON.parse(configuration.textContent);

    function fitRoom() {
        var height = window.visualViewport ? window.visualViewport.height : window.innerHeight;
        var top = Math.max(0, container.getBoundingClientRect().top);
        container.style.height = Math.max(240, Math.floor(height - top - 24)) + 'px';
    }
    fitRoom();
    window.addEventListener('resize', fitRoom);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', fitRoom);
    if (document.fonts) document.fonts.ready.then(fitRoom);

    function showLoadError() {
        var message = document.createElement('div');
        message.className = 'alert alert-danger m-3';
        message.textContent = config.loadError;
        container.replaceChildren(message);
    }

    if (typeof window.JitsiMeetExternalAPI !== 'function') {
        showLoadError();
        return;
    }
    var api;
    try {
        api = new window.JitsiMeetExternalAPI(config.domain, {
            roomName: config.roomName,
            parentNode: container,
            width: '100%',
            height: '100%',
            userInfo: { displayName: config.displayName },
            configOverwrite: {
                prejoinPageEnabled: false,
                startWithAudioMuted: !config.isModerator,
                disableDeepLinking: true
            },
            interfaceConfigOverwrite: { MOBILE_APP_PROMO: false },
            ...(config.jwt ? { jwt: config.jwt } : {})
        });
    } catch (error) {
        showLoadError();
        return;
    }

    if (!config.attendanceId) return;
    var leaveSent = false;
    function sendLeave() {
        if (leaveSent) return;
        var data = new window.FormData();
        data.append('_token', config.csrfToken);
        data.append('attendance_id', config.attendanceId);
        try {
            if (window.navigator.sendBeacon && window.navigator.sendBeacon(config.leaveUrl, data)) {
                leaveSent = true;
                return;
            }
        } catch (error) {
            // A rejected beacon still needs the keepalive fallback.
        }
        if (!window.fetch) return;
        leaveSent = true;
        try {
            Promise.resolve(window.fetch(config.leaveUrl, { method: 'POST', body: data, keepalive: true }))
                .then(function (response) { if (!response.ok) leaveSent = false; })
                .catch(function () { leaveSent = false; });
        } catch (error) {
            leaveSent = false;
        }
    }

    api.addEventListener('videoConferenceLeft', sendLeave);
    api.addEventListener('readyToClose', sendLeave);
    window.addEventListener('pagehide', sendLeave);
    // Background tabs, screen locks and phone app switches are not departures.
})(window, document);
