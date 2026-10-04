/*
 * PIIE — EXAM RESTRICTED INTERACTION MODE
 *
 * WHAT THIS IS, AND WHAT IT HONESTLY IS NOT
 * ──────────────────────────────────────────
 * Ordinary page JavaScript can stop a student copying, pasting, cutting, right-clicking
 * or dragging content out of THIS page while it is open. It cannot stop them minimizing
 * the window, Alt+Tab-ing away, switching to another application, photographing the
 * screen with a phone, or opening the same exam on a second device. Those are operating
 * system and hardware behaviours, and no amount of scripting changes them. This file
 * says so in the interface itself rather than implying a guarantee it cannot keep.
 *
 * SCOPE
 * ─────
 * Activated ONLY when the page carries `piie-exam-restricted`, which the attempt page
 * does and no other page in PIIE does. Nothing here is global: the clipboard elsewhere
 * in the application — a teacher pasting a lesson plan, a parent reading a report — is
 * untouched, because breaking that to protect an exam would be a poor trade.
 *
 * WHAT IS BLOCKED
 * ───────────────
 *   copy, cut, paste, drag-start, the context menu, and the clipboard keyboard
 *   equivalents (Ctrl/Cmd + C/X/V, Shift+Insert, middle-click paste on Windows).
 *   Paste is additionally refused inside the rich-text editors themselves, because
 *   Summernote has its own paste path that never fires the document's `paste` event.
 *
 * WHAT IS NOT BLOCKED
 * ───────────────────
 *   Typing, Enter, Backspace, Delete, Tab, arrow keys, caret movement, selection,
 *   undo/redo, and the permitted formatting tools. A restricted mode that stopped a
 *   student correcting a typo would cost more than it protected.
 *
 * FOCUS MONITORING
 * ────────────────
 * Records tab-hidden, window-blur, return, and connection loss/restoration. One
 * focus-loss incident is recorded once: `blur` and `visibilitychange` fire together for
 * a single Alt+Tab, and reporting both would double-count every incident on the record.
 * A short grace window absorbs that pairing, and a single incident is closed by the
 * matching return.
 *
 * ── THE PROTECTED AREA, NOT A LIST OF TAGS ──────────────────────────────────
 *
 * The first implementation decided what to protect by asking "is the event target an
 * input, a textarea, a select, or a Summernote editable?". That was wrong in a way
 * testing found immediately: `isRestrictedTarget()` returned false for the question
 * STATEMENT, which is a `<p>`.
 *
 * The consequence was exactly the behaviour this mode exists to remove. The student
 * selects the question text with the mouse — which works, because `user-select: text` is
 * deliberately allowed on the question so it can be read — right-clicks, and gets a
 * fully working browser context menu with Copy in it. Ctrl+C was blocked, so the keyboard
 * route was closed and the menu route was open.
 *
 * A tag list is the wrong shape for the question "what must not leave this page",
 * because a student can read the exam from any element and the set of readable elements
 * is not knowable in advance. The right question is "is this inside the examination",
 * which is a containment test against the root the page marked as protected. So that is
 * what is used now, and the tag list survives only as a fallback for a page that
 * forgot to declare a root.
 *
 * This is still a deterrence and a record, not a boundary. See the header.
 */
(function (window, document) {
    'use strict';

    var MODE_CLASS = 'piie-exam-restricted';

/**
 * The element that is the examination.
 *
 * A CONTAINMENT TEST, and that is the whole fix. See the header note: the previous
 * version enumerated target TAGS, so the question statement — a `<p>` — was outside
 * the protected set and a student could select it and copy it from the browser's own
 * context menu. Anything inside this root is the exam; anything outside is the rest of
 * PIIE and is left alone.
 *
 * Several selectors, because the attempt page is not the only possible host and a
 * control that silently protects nothing is worse than no control at all: it reports
 * "restricted mode active" while the exam is copyable.
 */
var PROTECTED_ROOT_SELECTORS = [
    '#exam-protected-area',
    '#examTakeRoot',
    '[data-piie-exam-area]'
];

    /**
     * Where incidents go. Injected by the view; both are optional so the module can be
     * loaded and exercised without a backend.
     */
    var config = window.PIIEExamRestricted = window.PIIEExamRestricted || {};

    config.endpoint = config.endpoint || null;
    config.csrfToken = config.csrfToken || null;
    config.submissionId = config.submissionId || null;

    /**
     * THE RESOLVED POLICY.
     *
     * Sent from the server, never decided here. Two reasons that matters and neither is
     * about trust:
     *
     *   1. The NOTICE on this page says what the controls do. If the script and the
     *      notice each read their own configuration they will eventually disagree, and a
     *      notice that overstates or understates the controls is worse than none.
     *   2. An APPROVED ACCOMMODATION has to be able to relax one control without
     *      changing the others, and that decision is institutional — it belongs with the
     *      exam, not in a file shipped to every browser.
     *
     * Defaults here are the STRICTEST reading, so a page that forgets to send the
     * settings gets the full controls rather than none.
     */
    var settings = Object.assign({
        enabled: true,
        block_clipboard: true,
        block_context_menu: true,
        block_navigation_shortcuts: true,
        warn_on_focus_loss: true,
        warning_threshold: 1,
        persistent_banner_after: 3,
        focus_loss_grace_ms: 1500,
        accommodation: null
    }, config.settings || {});

    /**
     * How long a focus loss stays "the same incident" before a new one may be opened.
     *
     * Long enough to absorb the blur/visibilitychange pair that a single Alt+Tab
     * produces, short enough that two genuine interruptions in quick succession are
     * still two incidents.
     *
     * Declared AFTER `settings`, and read from it, because the grace window is
     * institutional policy like every other threshold here — and a value read before
     * its source exists is a module that throws on load, which is how a hardening
     * control becomes a page that does not work.
     */
    var INCIDENT_GRACE_MS = settings.focus_loss_grace_ms || 1500;

    /** Per-incident state, so the same interruption is not counted twice. */
    var incident = {
        open: false,
        openedAt: 0,
        sequence: 0,
        lastLostAt: 0,
        // Total NEW incidents, which is what the thresholds are stated in. Distinct
        // from `sequence`, which counts REPORTED events and does not move at all when
        // the report could not be sent.
        count: 0,
        bannerShown: false
    };

    var connectionLost = false;

    /** Has `install()` already bound? See the guard inside it. */
    var installed = false;

    /**
     * The protected root, resolved at install time.
     *
     * Assigned in `install()` and only used by `isRestrictedTarget()`, which is a
     * public function tests call — so it is a parameter-free fallback when the module
     * has not been installed, and `null` (fall back to the tag list) in that case.
     */
    var root = null;

    function now() {
        return Date.now();
    }

    function log(message, detail) {
        if (window.console && window.console.debug) {
            window.console.debug('[exam-restricted] ' + message, detail || '');
        }
    }

    function warn(message) {
        if (window.console && window.console.warn) {
            window.console.warn('[exam-restricted] ' + message);
        }
    }

    /* ── reporting ────────────────────────────────────────────────────────── */

    /**
     * Send one incident to the server.
     *
     * Failures are swallowed on purpose. An exam must not be interrupted because a
     * telemetry endpoint was briefly unreachable — the student's answers are saved by a
     * different mechanism, and losing this record is bad; stopping their typing is
     * worse.
     */
    function report(eventType, detail) {
        if (!config.endpoint || !window.fetch) {
            log('no endpoint configured; not reporting', eventType);
            return;
        }

        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };

        if (config.csrfToken) {
            headers['X-CSRF-TOKEN'] = config.csrfToken;
        }

        try {
            window.fetch(config.endpoint, {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                keepalive: true,
                body: JSON.stringify({
                    submission_id: config.submissionId,
                    event_type: eventType,
                    event_key: 'piie-restricted:' + eventType + ':' + (++incident.sequence),
                    detail: detail || null,
                    client_at: new Date(now()).toISOString()
                })
            }).catch(function () {
                log('incident report failed', eventType);
            });
        } catch (e) {
            log('incident report threw', eventType);
        }
    }

    /* ── blocked clipboard actions ──────────────────────────────────────────── */

    /**
     * Record that the student attempted a clipboard action inside the exam.
     *
     * Separate from `block()`, which handles the DOM event, because the two have
     * different jobs and different lifetimes: `block()` must be synchronous and must
     * never be able to throw into the student's typing, while this is a network call
     * whose failure is irrelevant to whether the copy happened.
     *
     * Rate-limited on the CLIENT so that holding Ctrl+C cannot produce one request per
     * repeat, on top of the server's own dedupe. Two within the window collapse to one,
     * which is what the record should say in any case — a student who tried once and a
     * student who held the key are the same fact for an examiner.
     */
    var lastBlockedReportAt = 0;
    var BLOCKED_REPORT_THROTTLE_MS = 3000;

    function reportBlockedAction(kind) {
        var at = now();

        if (at - lastBlockedReportAt < BLOCKED_REPORT_THROTTLE_MS) {
            return;
        }

        lastBlockedReportAt = at;

        report('clipboard_blocked', {
            action: kind,
            // The event target's tag tells a reviewer what was being taken: the
            // question statement, or the student's own answer. Recorded as a tag name
            // and never as text, because this module must not become a channel that
            // copies content out of the examination.
            target: eventTargetTag(kind)
        });
    }

    var lastBlockedTarget = null;

    function eventTargetTag() {
        return lastBlockedTarget && lastBlockedTarget.tagName
            ? lastBlockedTarget.tagName.toLowerCase()
            : null;
    }

    /**
     * Keyboard shortcuts that must not be silently allowed.
     *
     * The clipboard accelerators are handled above. What is added here is navigation
     * and page-level control:
     *
     *   F5 / Ctrl+R / Ctrl+Shift+R   reload — refreshes the attempt, and combined
     *                                 with autosave is a way to make an in-progress
     *                                 paper disappear for a moment
     *   Ctrl+P                       print
     *   Alt+Left / Alt+Right / Ctrl+ArrowLeft / Right   back and forward
     *   Ctrl+W                       close tab — technically not interceptable in
     *                                 every browser; blocked where it is, because a
     *                                 partial block is better than none
     *
     * WHAT IS DELIBERATELY NOT BLOCKED
     *
     *   F11                          fullscreen toggle. A student must be able to leave
     *                                 fullscreen — that is what the exit-detection is
     *                                 FOR — and a mode that traps them in it removes
     *                                 their only escape from a browser chrome that
     *                                 overlaps the editor on a small screen.
     *   Ctrl+Shift+I/J/C            developer tools. Blocking these is theatre: they are
     *                                 trivially re-enabled, a page that fights them
     *                                 breaks legitimate accessibility tooling, and the
     *                                 student is told in the interface that this is
     *                                 deterrence and not a boundary.
     *   Ctrl+Tab, Ctrl+T, Ctrl+N, Alt+Tab   not interceptable from page script at all.
     *                                 Attempting them would produce a broken keyboard.
     *
     * Every blocked navigation is RECORDED as an incident and produces a warning. None
     * of them ends the attempt. See requirement 20 in the brief: a browser losing focus
     * is not evidence of cheating and must not cost a student their paper.
     */
    function isNavigationShortcut(event) {
        var key = (event.key || '').toLowerCase();
        var accel = event.ctrlKey || event.metaKey;

        if (key === 'f5') {
            return true;
        }

        if (accel && (key === 'r' || key === 'p' || key === 'w')) {
            return true;
        }

        if (event.altKey && (key === 'arrowleft' || key === 'arrowright')) {
            return true;
        }

        if (accel && !event.shiftKey && (key === 'arrowleft' || key === 'arrowright')) {
            return true;
        }

        return false;
    }

    function isPrintShortcut(event) {
        var key = (event.key || '').toLowerCase();

        if (key === 'p' && (event.ctrlKey || event.metaKey)) {
            return true;
        }

        if (key === 'p' && event.shiftKey && event.altKey) {
            return true;
        }

        return false;
    }

    /* ── incident lifecycle ───────────────────────────────────────────────── */

    /**
     * Record leaving the exam.
     *
     * Guarded twice: an open incident absorbs repeats, and anything arriving inside the
     * grace window is treated as the same event. That is what stops a single Alt+Tab —
     * which produces a `blur` and a `visibilitychange` in the same tick — becoming two
     * records.
     */
    function noteLost(reason, eventType) {
        var at = now();
        var type = eventType || 'focus_lost';

        if (incident.open && (at - incident.lastLostAt) < INCIDENT_GRACE_MS) {
            incident.lastLostAt = at;
            log('absorbed a repeat focus loss', reason);

            return false;
        }

        if (incident.open) {
            report('focus_returned', { reason: reason, implicit: true });
        }

        incident.open = true;
        incident.openedAt = at;
        incident.lastLostAt = at;
        incident.count = (incident.count || 0) + 1;

        report(type, { reason: reason });

        // NO WARNING FROM HERE.
        //
        // This function RECORDS, and it returns whether it opened a NEW incident.
        // Whether the student is warned is a separate decision, made by
        // `noteLostOrWarn()` from the resolved policy, because an approved
        // accommodation has to be able to record a focus loss without warning about it.
        //
        // The return value is a plain boolean rather than anything derived from
        // `report()`. An earlier version compared the report counter, which meant the
        // warning and the banner silently stopped working whenever the incident report
        // could not be sent — a student with a flaky connection, or an invigilator
        // watching a screen with no endpoint configured, was told nothing. A student
        // must never stop being warned because the AUDIENCE of the warning could not be
        // reached.
        return true;
    }

    function noteReturned(reason) {
        if (!incident.open) {
            // Returning without a recorded loss still means the student is back; the
            // restoration notice is useful even when the loss was too brief to record.
            if (typeof config.onFocusReturned === 'function') {
                config.onFocusReturned(reason, null);
            }
            return;
        }

        var heldMs = now() - incident.openedAt;

        incident.open = false;

        report('focus_returned', { reason: reason, away_ms: heldMs });

        if (typeof config.onFocusReturned === 'function') {
            config.onFocusReturned(reason, heldMs);
        }
    }

    /* ── restricted interaction ───────────────────────────────────────────── */

    function protectedRoot() {
        for (var i = 0; i < PROTECTED_ROOT_SELECTORS.length; i++) {
            var found = document.querySelector(PROTECTED_ROOT_SELECTORS[i]);

            if (found) {
                return found;
            }
        }

        return null;
    }

    /**
     * IS THIS EVENT INSIDE THE EXAMINATION?
     *
     * The containment test. A root is resolved ONCE at install and reused, because
     * resolving per event is a document query on every keystroke and the protected
     * area does not move.
     *
     * If the page marked itself restricted but declared no root, this FALLS BACK to the
     * old tag list rather than to `false`. `true` means "block it"; `false` means "let
     * it through". On a page whose protection has silently become a no-op, failing
     * closed is the honest direction — and it is bounded to this one page, because
     * `install()` has already established that this IS an examination.
     */
    function isRestrictedTarget(target) {
        if (!target) {
            return false;
        }

        if (root) {
            if (target === root) {
                return true;
            }

            if (target.nodeType === 1 && root.contains && root.contains(target)) {
                return true;
            }

            return false;
        }

        if (!target.closest) {
            return false;
        }

        var node = target;

        while (node && node.nodeType === 1) {
            var tag = (node.tagName || '').toLowerCase();

            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return true;
            }

            if (node.classList && (
                node.classList.contains('note-editable') ||
                node.classList.contains('note-toolbar') ||
                node.classList.contains('piie-editor-shell')
            )) {
                return true;
            }

            node = node.parentElement;
        }

        return false;
    }

    /**
     * Whether a keyboard event is one of the clipboard shortcuts.
     *
     * Mac equivalents are included deliberately: `metaKey` is Cmd, so Cmd+C/X/V and
     * their Shift variants are caught by the same branches.
     */
    function isClipboardShortcut(event) {
        var key = (event.key || '').toLowerCase();

        if (key === 'insert' && event.shiftKey) {
            return true;
        }

        if (event.key === 'ContextMenu') {
            return true;
        }

        var accel = event.ctrlKey || event.metaKey;

        if (!accel) {
            return false;
        }

        return key === 'c' || key === 'x' || key === 'v';
    }

    function block(event, message, detail) {
        if (event.cancelable) {
            event.preventDefault();
        }

        // `stopPropagation` so Summernote's own handlers never see it either. This is
        // the important half for paste: Summernote binds its own paste handler, and
        // preventing the default alone would still let its internal paste run.
        if (event.stopPropagation) {
            event.stopPropagation();
        }

        log('blocked: ' + message, detail || '');
        notify(message);
    }

    var lastNotice = 0;

    function notify(message) {
        // One notice per two seconds, so holding a shortcut does not produce a stream.
        if (now() - lastNotice < 2000) {
            return;
        }

        lastNotice = now();

        if (typeof config.onBlocked === 'function') {
            config.onBlocked(message);
        }
    }

    function install() {
        if (!document.documentElement.classList.contains(MODE_CLASS)
            && !document.body.classList.contains(MODE_CLASS)) {
            return false;
        }

        /**
         * IDEMPOTENCE.
         *
         * Binding twice is not a harmless no-op. `window.blur` and
         * `visibilitychange` are both listened to, so a second install makes one
         * Alt+Tab fire the handlers twice: the first opens an incident and the second
         * absorbs it as "a repeat". The student is warned about a fraction of the
         * interruptions that actually happened, which is the opposite of what a
         * threshold is for.
         *
         * The module installs itself on load, and the page does not call `install()`
         * itself — so this guard is here for the caller who does, not because the
         * current page needs it.
         */
        if (installed) { return true; }
        installed = true;

        root = protectedRoot();

        /* Clipboard and the context menu. Capture phase, so nothing downstream wins. */
        ['copy', 'cut', 'paste', 'dragstart'].forEach(function (type) {
            document.addEventListener(type, function (event) {
                if (!settings.block_clipboard) { return; }
                if (!isRestrictedTarget(event.target)) {
                    return;
                }

                // A blocked clipboard action is EVIDENCE, not just a refusal: a student
                // reaching for the clipboard during an exam is exactly what a marker
                // needs to see in the record. Reported through the same incident
                // endpoint as focus loss, and subject to the same server-side
                // deduplication, so holding a shortcut cannot inflate the count.
                reportBlockedAction(type);
                block(event, type);
            }, true);
        });

        document.addEventListener('contextmenu', function (event) {
            if (!settings.block_context_menu) { return; }
            if (isRestrictedTarget(event.target)) {
                block(event, 'contextmenu');
            }
        }, true);

        /*
         * Summernote builds its own editable region after this module loads, so it is
         * bound here on the document rather than on a node that does not exist yet.
         * `pasteHTML` is the path that actually inserts content, and it is reachable
         * from the keyboard shortcut above as well as the toolbar.
         */
        document.addEventListener('paste', function (event) {
            var node = event.target;

            if (node && node.closest && node.closest('.note-editable')) {
                block(event, 'editor-paste');
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            if (isClipboardShortcut(event)) {
                if (!settings.block_clipboard) { return; }
                lastBlockedTarget = event.target;
                reportBlockedAction('keyboard:' + (event.key || ''));
                block(event, 'clipboard-shortcut:' + (event.key || ''));
                return;
            }

            // Print is separated from navigation because it is a different request with
            // a different meaning: printing the paper is taking the exam off the screen,
            // where a reload is not. Print is never relaxed by an accommodation: it is
            // not a capability anyone needs in order to read the paper, and permitting
            // it would defeat the restriction for anyone who can print.
            if (isPrintShortcut(event)) {
                lastBlockedTarget = event.target;
                report('print_attempted', { via: 'shortcut' });
                block(event, 'print');
                return;
            }

            if (isNavigationShortcut(event)) {
                if (!settings.block_navigation_shortcuts) { return; }
                lastBlockedTarget = event.target;
                report('navigation_attempted', { key: event.key || null });
                block(event, 'navigation');
                return;
            }

            /**
             * F2 is Summernote's fullscreen toggle. Fullscreen is legitimate during an
             * exam — it is how a student gets more of the paper — so it is left alone,
             * as is F11. Fullscreen EXIT is detected by `fullscreenchange`, which the
             * attempt page wires; trapping the student in fullscreen would remove the
             * only way out of a browser chrome that covers the editor.
             */
        }, true);

        /*
         * `beforeprint` is the second door to printing. Ctrl+P can be suppressed; a
         * browser's own print command sometimes cannot be, and the window.print() the
         * student reaches through a menu still reaches here. Reported, never prevented —
         * there is nothing to prevent at this point, and pretending otherwise would mean
         * the record claimed a refusal that did not happen.
         */
        window.addEventListener('beforeprint', function () {
            report('print_attempted', { via: 'beforeprint' });
        });

        /* Middle-click paste, which fires no `paste` event on some platforms. */
        document.addEventListener('mouseup', function (event) {
            if (event.button === 1) {
                block(event, 'middle-click-paste');
            }
        }, true);

        /* Focus monitoring. */
        //
        // RECORDING AND WARNING ARE SEPARATE, AND AN ACCOMMODATION SEPARATES THEM.
        //
        // `read_only_enforced` exists for a student whose approved adjustment involves
        // a reader or a second window that legitimately moves focus. Their focus events
        // are still WRITTEN — the examiner sees exactly what happened — but they are not
        // WARNED about, because telling someone to be careful while their accommodation
        // is doing the opposite is both unkind and useless.
        //
        // What no setting does is END the attempt. See the config file: integrity events
        // are evidence for a human, and an automated consequence for losing focus
        // punishes a dropped connection and an OS notification identically.
        function noteLostOrWarn(reason, eventType) {
            // `noteLost()` tells us whether this was a NEW incident or one absorbed by
            // the grace window. Only a new incident can be warned about.
            if (!noteLost(reason, eventType)) { return; }

            if (!settings.warn_on_focus_loss) { return; }

            // Past the banner threshold the notice stops being transient: the student is
            // told ONCE that it is being recorded, and the banner then stays until the
            // paper is handed in. Once, deliberately — the view's handler reveals a
            // persistent element and updates a count in it, so firing it on every
            // later incident would only churn the DOM and re-announce the same news.
            if (incident.count >= (settings.persistent_banner_after || 3)) {
                if (!incident.bannerShown && typeof config.onPersistentNotice === 'function') {
                    incident.bannerShown = true;
                    config.onPersistentNotice(incident.count);
                }

                return;
            }

            // Below the threshold, and only up to it: a warning repeated on every
            // incident is a warning nobody reads.
            if (incident.count <= (settings.warning_threshold || 1)) {
                if (typeof config.onFocusLost === 'function') {
                    config.onFocusLost(reason);
                }
            }
        }

        window.addEventListener('blur', function () {
            noteLostOrWarn('window-blur');
        });

        window.addEventListener('focus', function () {
            noteReturned('window-focus');
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                // Reported as `tab_hidden`, which is the event this system already
                // records, rather than collapsed into `focus_lost`. They are different
                // facts — a hidden tab and a blurred window are not the same evidence —
                // and a reviewer reading the log should be able to tell them apart.
                noteLostOrWarn('tab-hidden', 'tab_hidden');
            } else if (document.visibilityState === 'visible') {
                noteReturned('tab-visible');
            }
        });

        /* Connection state. */
        window.addEventListener('offline', function () {
            if (connectionLost) {
                return;
            }

            connectionLost = true;
            report('connection_lost', { online: navigator.onLine === false ? false : true });

            if (typeof config.onConnectionChange === 'function') {
                config.onConnectionChange(false);
            }
        });

        window.addEventListener('online', function () {
            if (!connectionLost) {
                return;
            }

            connectionLost = false;
            report('connection_restored', { online: true });

            if (typeof config.onConnectionChange === 'function') {
                config.onConnectionChange(true);
            }
        });

        log('restricted interaction mode installed');

        if (!root) {
            // The page says the exam is protected and no protected area exists. Say so
            // in the console rather than reporting "installed" as though the protection
            // were real — this is the failure that let a previous version claim to be
            // restricted while the question statement stayed copyable.
            warn('restricted mode is active but no protected area was found; '
                + 'falling back to element-type checks, which do NOT cover the question text');
        }

        return true;
    }

    /* ── public surface, so the behaviour can be exercised ────────────────── */

    config.install = install;
    config.noteLost = noteLost;
    config.noteReturned = noteReturned;
    config.isClipboardShortcut = isClipboardShortcut;
    config.isNavigationShortcut = isNavigationShortcut;
    config.isPrintShortcut = isPrintShortcut;
    config.isRestrictedTarget = isRestrictedTarget;
    config.protectedRoot = protectedRoot;

    /** The resolved policy this page is actually running. Exposed for tests. */
    config.settings = function () {
        return Object.assign({}, settings);
    };

    /** Exposed for tests: was a real protected area found? */
    config.hasProtectedArea = function () {
        return root !== null;
    };

    /** Exposed for tests: is an incident currently open? */
    config.incidentState = function () {
        return { open: incident.open, sequence: incident.sequence, count: incident.count || 0 };
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', install);
    } else {
        install();
    }
}(window, document));