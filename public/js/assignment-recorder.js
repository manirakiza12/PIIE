/**
 * In-browser audio and video recording for assignment questions.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THE ONE RULE: NOTHING HAPPENS UNTIL A PERSON PRESSES A BUTTON
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This file calls `navigator.mediaDevices.getUserMedia` from exactly one place:
 * inside the click handler of a "Record audio" / "Record video" button the
 * student pressed. It never runs on load, never runs on scroll, never runs on a
 * timer, and never asks on behalf of another question.
 *
 * That matters because a microphone prompt that appears unprompted reads as the
 * site spying rather than the student choosing. A page that records a student the
 * moment it is opened is a page nobody should submit an oral explanation to, and
 * an oral explanation is exactly what the `audio` evidence kind is for.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THE FALLBACK IS THE WHOLE ANSWER WHERE RECORDING DOES NOT WORK
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Every panel that has a recorder also has a plain file picker, and the picker is
 * rendered by the server in the HTML - this file only ever ADDS a way to produce
 * that same file. If `mediaDevices` is missing, if the browser is Firefox on a
 * page that is not a secure context, if permission is refused, or if there is no
 * microphone: the button explains what happened and points at the picker, which
 * keeps working. There is no state in which recording is the ONLY option.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * NOTHING HERE PRETENDS A RECORDING SUCCEEDED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `capture_method=browser_recording` is written into a hidden field ONLY after a
 * real recording has ended and produced a Blob. It is that field and nothing else
 * that distinguishes a recording from a chosen file, and the server decides what
 * to do with it: it validates the file it actually received against the
 * question's own kind, extension allowlist and size limit, and creates the
 * evidence item only from that file. A client that sets the field while sending
 * nothing produces no item at all, and the marker sees a question with no answer -
 * which is the truth.
 *
 * The status text is therefore careful to distinguish three things, because they
 * are genuinely different: nothing has been recorded, a recording is ready TO
 * ATTACH, and the server has not yet confirmed anything.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * MIME AND FILE EXTENSION, AND WHY BOTH ARE CHOSEN HERE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * MediaRecorder picks a container from what the browser supports: `webm` almost
 * everywhere, `mp4` on Safari, and `ogg` on some builds. The server's per-kind
 * allowlist accepts all of them, so the recorder uses the browser's own preferred
 * type and does not transcode. The extension is derived from the same type, so the
 * uploaded filename is honest about what the bytes are.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * NO DEPENDENCIES, NO BUILD STEP
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A plain script served from /js, written in the browser's own dialect as it
 * existed for years. It is loaded with `defer` and only on pages that render a
 * recorder panel.
 */
(function () {
    'use strict';

    /** The statuses a panel can be in, and the wording for each. */
    var IDLE = 'Nothing is recorded until you choose to. Your browser will ask your permission first.';

    function supported() {
        return !!(navigator.mediaDevices
            && navigator.mediaDevices.getUserMedia
            && typeof window.MediaRecorder === 'function');
    }

    /**
     * Why recording cannot be used, in the student's own terms, or null when it can.
     *
     * A secure context is required for getUserMedia. On http://localhost that is
     * treated as secure, which is why this works in local development; on a plain
     * http:// address on a real network it does not, and saying so is more useful
     * than a button that silently fails.
     */
    function whyNot() {
        if (typeof navigator === 'undefined' || !navigator.mediaDevices) {
            return 'This browser does not offer in-browser recording. Use the file picker below instead.';
        }
        if (!window.isSecureContext) {
            return 'Recording needs a secure (https) connection. Use the file picker below instead.';
        }
        if (typeof window.MediaRecorder !== 'function') {
            return 'This browser cannot record. Use the file picker below instead.';
        }
        return null;
    }

    /** The recorder's own preferred type, with the extension it maps to. */
    function preferredType(kind) {
        var candidates = kind === 'audio'
            ? ['audio/webm', 'audio/ogg', 'audio/mp4']
            : ['video/webm', 'video/mp4', 'video/ogg'];

        for (var i = 0; i < candidates.length; i++) {
            if (window.MediaRecorder.isTypeSupported
                && window.MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

    function extensionFor(mime, kind) {
        if (mime.indexOf('webm') !== -1) { return kind === 'audio' ? 'webm' : 'webm'; }
        if (mime.indexOf('ogg') !== -1) { return kind === 'audio' ? 'oga' : 'ogv'; }
        if (mime.indexOf('mp4') !== -1) { return kind === 'audio' ? 'm4a' : 'mp4'; }
        return kind === 'audio' ? 'webm' : 'webm';
    }

    /** Trim trailing zeros the way a person would write a mark: 17 not 17.00. */
    function tidy(value) {
        return String(value).replace(/\.00+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    }

    function Recorder(panel) {
        this.panel = panel;
        this.kind = panel.getAttribute('data-kind');
        this.input = document.getElementById(panel.getAttribute('data-file-input-id'));
        this.staged = panel.querySelector('[data-as-recorder-staged]');
        this.status = panel.querySelector('[data-as-recorder-status]');
        this.button = panel.querySelector('[data-as-recorder-toggle]');

        this.stream = null;
        this.recorder = null;
        this.chunks = [];
        this.blob = null;
        this.url = null;
        this.player = null;
        this.busy = false;
    }

    Recorder.prototype.say = function (message, tone) {
        if (!this.status) { return; }
        this.status.textContent = message;
        this.status.classList.remove('text-muted', 'text-danger', 'text-success', 'text-warning');
        this.status.classList.add(tone || 'text-muted');
    };

    Recorder.prototype.start = function () {
        var self = this;

        if (self.busy) { return; }

        var blocked = whyNot();
        if (blocked) {
            self.say(blocked, 'text-warning');
            return;
        }

        self.busy = true;
        self.say('Asking for permission to use your ' + (self.kind === 'audio' ? 'microphone' : 'camera') + '...');

        navigator.mediaDevices.getUserMedia(
            self.kind === 'audio' ? { audio: true } : { audio: true, video: true }
        ).then(function (stream) {
            self.stream = stream;

            var mimeType = preferredType(self.kind);
            self.chunks = [];

            try {
                self.recorder = mimeType
                    ? new MediaRecorder(stream, { mimeType: mimeType })
                    : new MediaRecorder(stream);
            } catch (error) {
                self.release();
                self.say('This browser could not start the recorder. Use the file picker below instead.', 'text-warning');
                return;
            }

            self.recorder.ondataavailable = function (event) {
                if (event.data && event.data.size > 0) { self.chunks.push(event.data); }
            };

            self.recorder.onstop = function () { self.finish(); };

            self.recorder.start();

            self.button.textContent = 'Stop recording';
            self.button.classList.remove('btn-outline-secondary');
            self.button.classList.add('btn-danger');
            self.say('Recording. Nothing has been saved yet — press Stop when you are done.', 'text-danger');
        }).catch(function (error) {
            self.busy = false;
            self.release();

            var name = error && error.name ? error.name : 'error';
            if (name === 'NotAllowedError' || name === 'SecurityError') {
                self.say(
                    'Permission was not given, so nothing was recorded. Use the file picker below instead.',
                    'text-warning'
                );
            } else if (name === 'NotFoundError') {
                self.say(
                    'No ' + (self.kind === 'audio' ? 'microphone' : 'camera') + ' was found. Use the file picker below instead.',
                    'text-warning'
                );
            } else if (name === 'NotReadableError') {
                self.say(
                    'That ' + (self.kind === 'audio' ? 'microphone' : 'camera') + ' is in use by another program. Use the file picker below instead.',
                    'text-warning'
                );
            } else {
                self.say('Recording is not available here. Use the file picker below instead.', 'text-warning');
            }
        });
    };

    Recorder.prototype.stop = function () {
        if (this.recorder && this.recorder.state !== 'inactive') {
            this.recorder.stop();
        }
    };

    /** A recording has ended. Only NOW is a file offered to the form. */
    Recorder.prototype.finish = function () {
        var self = this;

        self.release();

        if (!self.chunks.length) {
            self.say('Nothing was captured, so nothing was attached.', 'text-warning');
            return;
        }

        var mime = (self.recorder && self.recorder.mimeType) || preferredType(self.kind);
        self.blob = new Blob(self.chunks, { type: mime });
        self.chunks = [];

        var name = 'recording-' + Date.now() + '.' + extensionFor(mime, self.kind);
        var file = new File([self.blob], name, { type: mime });

        // ── Hand the file to the form's own file input ─────────────────────
        // A DataTransfer lets a File be placed into a file input exactly as if
        // the student had chosen it, so the file travels on the NORMAL form post
        // and the server receives it on the field it already validates. The
        // transfer is wrapped because older Safari has no DataTransfer constructor
        // at all: without it the file picker above remains the whole answer, which
        // is exactly the fallback the brief requires.
        if (typeof DataTransfer === 'function') {
            try {
                var transfer = new DataTransfer();
                transfer.items.add(file);
                self.input.files = transfer.files;
            } catch (error) {
                self.say(
                    'Your browser will not let a recording be attached automatically. Choose the recording with the file picker above.',
                    'text-warning'
                );
                return;
            }
        } else {
            self.say(
                'Your browser will not let a recording be attached automatically. Choose the recording with the file picker above.',
                'text-warning'
            );
            return;
        }

        // Provenance. Written ONLY now, and it is not a claim the server trusts -
        // see the note at the top of this file.
        if (self.staged) { self.staged.value = 'browser_recording'; }

        self.showPlayback();
    };

    /** Play it back before it is attached, and offer to do it again. */
    Recorder.prototype.showPlayback = function () {
        var self = this;

        if (self.url) { URL.revokeObjectURL(self.url); }
        if (self.player) { self.player.remove(); }

        self.url = URL.createObjectURL(self.blob);
        self.player = document.createElement(self.kind === 'audio' ? 'audio' : 'video');
        self.player.controls = true;
        self.player.src = self.url;
        self.player.className = 'w-100';
        if (self.kind === 'video') { self.player.style.maxHeight = '240px'; }

        var again = document.createElement('button');
        again.type = 'button';
        again.className = 'btn btn-sm btn-outline-secondary ms-2';
        again.textContent = 'Record again';
        again.addEventListener('click', function () { self.reset(); self.start(); });

        self.panel.appendChild(self.player);
        self.panel.appendChild(again);

        self.say(
            'A recording is ready to attach. Listen to it first — press "Record again" if you want another.',
            'text-success'
        );
    };

    Recorder.prototype.reset = function () {
        var self = this;

        if (self.url) { URL.revokeObjectURL(self.url); }
        if (self.player) { self.player.remove(); }

        self.player = null;
        self.blob = null;

        if (self.staged) { self.staged.value = ''; }
        if (self.input) { self.input.value = ''; }
    };

    /** Stop the tracks. A live microphone indicator is worse than no recorder. */
    Recorder.prototype.release = function () {
        if (this.stream) {
            this.stream.getTracks().forEach(function (track) { track.stop(); });
            this.stream = null;
        }
    };

    Recorder.prototype.attach = function () {
        var self = this;
        self.button.textContent = self.kind === 'audio' ? 'Record audio' : 'Record video';
        self.button.classList.add('btn-outline-secondary');
        self.button.classList.remove('btn-danger');
    };

    function wire(panel) {
        var recorder = new Recorder(panel);
        var blocked = whyNot();

        if (blocked) {
            recorder.say(blocked, 'text-warning');
            panel.setAttribute('data-as-recorder-unavailable', 'true');
            // The button is left in place and does nothing, so the explanation and
            // the picker are visible together. Hiding it would leave a student
            // wondering where the "record" option the lecturer offered went.
            return;
        }

        panel.setAttribute('data-as-recorder-ready', 'true');

        recorder.button.addEventListener('click', function () {
            if (recorder.recorder && recorder.recorder.state !== 'inactive') {
                recorder.stop();
                recorder.attach();
            } else {
                recorder.start();
            }
        });
    }

    function boot() {
        var panels = document.querySelectorAll('[data-as-recorder]');

        Array.prototype.forEach.call(panels, function (panel) {
            if (panel.getAttribute('data-as-recorder-ready')
                || panel.getAttribute('data-as-recorder-unavailable')) {
                return;
            }
            wire(panel);
        });
    }

    // The running total on a marking screen is a SEPARATE script, so a student's
    // submission page never loads code it has no use for.
    function bootTotals() {
        var form = document.querySelector('[data-as-question-total]');
        if (!form) { return; }

        var value = form.querySelector('[data-as-total-value]');
        var count = form.querySelector('[data-as-total-count]');
        var inputs = form.querySelectorAll('[data-as-question-mark]');

        if (!value || !inputs.length) { return; }

        function recalc() {
            var total = 0;
            var marked = 0;

            Array.prototype.forEach.call(inputs, function (input) {
                var raw = (input.value || '').trim();
                if (raw === '') { return; }

                var mark = parseFloat(raw);
                if (isNaN(mark)) { return; }

                // The same bounds the server enforces, shown live. A lecturer is
                // told a mark is over the question's maximum while typing rather
                // than after saving. The server still refuses it regardless.
                var max = parseFloat(input.getAttribute('data-max'));
                if (!isNaN(max) && mark > max) {
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }

                if (mark < 0) {
                    input.classList.add('is-invalid');
                } else {
                    total += mark;
                    marked++;
                }
            });

            value.textContent = tidy(Math.round(total * 100) / 100);
            if (count) { count.textContent = '(' + marked + ' of ' + inputs.length + ' marked)'; }
        }

        Array.prototype.forEach.call(inputs, function (input) {
            input.addEventListener('input', recalc);
        });

        recalc();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); bootTotals(); });
    } else {
        boot();
        bootTotals();
    }
})();
