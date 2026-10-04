/* ==========================================================================
   PIIE HERO VIDEO CONTROLLER
   --------------------------------------------------------------------------
   Progressive enhancement over markup that already works without this file.

   WITHOUT JAVASCRIPT the hero is fully readable: the <video> carries its
   `poster`, the text is real text, and the CSS gives the banner a solid
   background colour behind the media. Nothing here is required to SEE the
   page - only to control the motion.

   ── WHY THE FIRST VERSION LOOKED LIKE "AUTOPLAY DOES NOT WORK" ────────────
   Three separate things had to be true, and only two of them were:

     1. the browser must accept autoplay        -> muted + playsinline: both present
     2. the file must actually decode           -> it does, 1920x1080 H.264
     3. THE POSTER MUST GET OUT OF THE WAY     -> it did not, ever

   `.piie-hero__poster` is an <img> layered ABOVE the video at z-index 1, and it
   is hidden by the `.is-playing` class on the hero - not by autoplay itself. In
   the first version `setState(true)` was reachable only from the Pause button,
   because every one of its call sites passed `false`. The browser's own autoplay
   never called it. So the video played, muted and looping, perfectly, underneath
   a still photograph, for the entire life of the page.

   The lesson encoded below: playback state is owned by the MEDIA ELEMENT, and the
   script only reports it. Deriving state from "what did our own button just do"
   is what made the control and the display disagree.

   RESPONSIBILITIES
     - report state from `playing`/`pause`/`waiting`, so every path is honest
     - attempt playback once the browser says it CAN play, not before
     - never autoplay above prefers-reduced-motion or on a metered connection
     - keep the control's label and aria-pressed in step with reality
     - surface load errors in the label rather than hiding them

   NO FRAMEWORK, no build step. Plain ES5-compatible script, loaded with `defer`.
   ========================================================================== */

(function () {
    'use strict';

    var REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

    function matches(query) {
        return typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
    }

    /**
     * Whether motion is appropriate at all.
     *
     * Three separate reasons to decline, all of which a visitor may have expressed
     * deliberately: the OS asks for reduced motion; the browser reports a metered
     * or slow connection; or the device declares no hardware available.
     */
    function motionIsAcceptable() {
        if (matches(REDUCED_MOTION)) { return false; }

        var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

        if (connection) {
            if (connection.saveData === true) { return false; }
            if (connection.effectiveType === 'slow-2g' || connection.effectiveType === '2g') { return false; }
        }

        return true;
    }

    function init(hero) {
        var video = hero.querySelector('.piie-hero__video');
        var toggle = hero.querySelector('.piie-hero__toggle');
        var label = hero.querySelector('.piie-hero__toggle-label');

        if (!video) { return; }

        var wantsMotion = motionIsAcceptable();

        /** The one place the poster, the ARIA state and the label are written. */
        function setState(playing) {
            hero.classList.toggle('is-playing', playing);

            if (toggle) {
                toggle.setAttribute('aria-pressed', playing ? 'true' : 'false');
            }

            if (label) {
                label.textContent = playing ? 'Pause background video' : 'Play background video';
            }
        }

        function attemptPlay() {
            // play() returns a promise in modern browsers and REJECTS when autoplay
            // is blocked. An unhandled rejection here surfaces as a console error on
            // a page that is otherwise fine, so it is handled and REPORTED in the
            // control's label rather than swallowed.
            var result;

            try {
                result = video.play();
            } catch (error) {
                setState(false);
                return;
            }

            if (result && typeof result.catch === 'function') {
                result.catch(function () {
                    setState(false);

                    if (label) {
                        label.textContent = 'Play background video';
                    }
                });
            }
        }

        // ── State comes from the media element, not from our own intent ──────
        // `playing` fires for BOTH an autoplay that started on its own and a play()
        // the visitor asked for, which is exactly the case the first version missed.
        video.addEventListener('playing', function () { setState(true); });
        video.addEventListener('play', function () { setState(true); });
        video.addEventListener('pause', function () { setState(false); });
        video.addEventListener('ended', function () { setState(false); });
        video.addEventListener('waiting', function () { setState(false); });

        /**
         * WHEN THE BROWSER SAYS IT CAN PLAY, ASK IT TO PLAY.
         *
         * `canplay` rather than DOMContentLoaded: on a cold cache the media is not
         * ready at parse time, and asking earlier just produces a rejected promise.
         * This is the second half of the autoplay fix - the first half is the
         * `muted`/`playsinline` attributes, the third is `playing` above.
         */
        ['canplay', 'loadeddata'].forEach(function (event) {
            video.addEventListener(event, function once() {
                video.removeEventListener(event, once);

                if (wantsMotion && video.paused) {
                    attemptPlay();
                }
            });
        });

        // The control only exists when it can do something.
        if (toggle) {
            toggle.hidden = false;

            toggle.addEventListener('click', function () {
                if (video.paused) {
                    attemptPlay();
                } else {
                    video.pause();
                }
            });
        }

        // A visitor who pauses must not have it restarted by a later visibility
        // change, so their choice is recorded from the ELEMENT's own state rather
        // than guessed at from what our button last did.
        if (!wantsMotion) {
            video.removeAttribute('autoplay');
            video.pause();
            setState(false);
            return;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { return; }

            if (video.paused && video.readyState >= 2 && motionIsAcceptable()) {
                // Only resume what was interrupted by the tab, never restart something
                // the visitor deliberately paused. `readyState >= 2` means there is
                // enough buffered to actually start.
                if (video.currentTime > 0 || video.autoplay) {
                    attemptPlay();
                }
            }
        });

        /**
         * A load failure is REPORTED, never hidden.
         *
         * The poster is already on screen and the page is complete, so the honest
         * thing is to say so: drop the dead media layer, and put the reason in the
         * control's own text rather than leaving a control that cannot work.
         */
        video.addEventListener('error', function () {
            hero.classList.remove('is-playing');
            hero.classList.add('is-video-unavailable');
            video.style.display = 'none';

            if (label) { label.textContent = 'Background video unavailable'; }
            if (toggle) { toggle.hidden = true; }
        }, true);

        // Each <source> reports its own failure, so the reason is available rather
        // than collapsed into one opaque error.
        Array.prototype.forEach.call(video.querySelectorAll('source'), function (source) {
            source.addEventListener('error', function () {
                if (window.console && console.warn) {
                    console.warn('[piie-hero] video source failed:', source.getAttribute('src'));
                }
            });
        });

        setState(false);
    }

    function boot() {
        var heroes = document.querySelectorAll('[data-pii-hero]');

        Array.prototype.forEach.call(heroes, function (hero) {
            init(hero);
        });

        // ── Mobile navigation ──────────────────────────────────────────────
        // Visibility is driven by aria-expanded on the button and the class is only
        // a visual mirror, so the state a screen reader reports and the state on
        // screen cannot disagree.
        var burger = document.querySelector('[data-pii-burger]');
        var panel = document.getElementById('piie-mobile-nav');

        if (burger && panel) {
            burger.addEventListener('click', function () {
                var open = burger.getAttribute('aria-expanded') === 'true';

                burger.setAttribute('aria-expanded', open ? 'false' : 'true');
                panel.classList.toggle('is-open', !open);
            });
        }

        // ── Dropdown menus ─────────────────────────────────────────────────
        // Click to open, Escape to close, outside-click closes. Deliberately NOT
        // hover: a hover-only menu cannot be operated from a keyboard.
        function closeAllDropdowns() {
            Array.prototype.forEach.call(document.querySelectorAll('[data-pii-dropdown]'), function (group) {
                group.classList.remove('is-open');

                var trigger = group.querySelector('button');

                if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-pii-dropdown]'), function (group) {
            var button = group.querySelector('button');

            if (!button) { return; }

            button.addEventListener('click', function (event) {
                event.stopPropagation();

                var open = group.classList.contains('is-open');

                closeAllDropdowns();
                group.classList.toggle('is-open', !open);
                button.setAttribute('aria-expanded', open ? 'false' : 'true');
            });

            group.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllDropdowns();
                    button.focus();
                }
            });
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest || !event.target.closest('[data-pii-dropdown]')) {
                closeAllDropdowns();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllDropdowns();

                if (burger && burger.getAttribute('aria-expanded') === 'true') {
                    burger.setAttribute('aria-expanded', 'false');
                    if (panel) { panel.classList.remove('is-open'); }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());