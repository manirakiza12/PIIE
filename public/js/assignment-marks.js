/**
 * The running total on a marking screen.
 *
 * A COPY, not a move. `assignment-recorder.js` carries its own `bootTotals()` so
 * that a function exists in one file, but a student's submission page must not
 * load code about marking - it has no use for it, and every unnecessary byte on
 * that page is a byte a student on a slow connection waits for.
 *
 * DISPLAY ONLY, AND THE COMMENT ABOVE SAYS WHY
 *
 * The figure is never submitted and never stored. `QuestionGradingService`
 * recomputes the total from the marks that are actually saved, so this number can
 * drift from the recorded one and nothing would be affected. It exists so a
 * lecturer can watch the total move while working down a paper.
 *
 * The bounds shown here are the same ones the server enforces - zero, and not more
 * than the question's own maximum - so a slip is caught while typing rather than
 * after saving. The server refuses it regardless; this is only earlier.
 */
(function () {
    'use strict';

    function tidy(value) {
        return String(value).replace(/\.00+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
    }

    function boot() {
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

                var maximum = parseFloat(input.getAttribute('data-max'));
                var over = (!isNaN(maximum) && mark > maximum) || mark < 0;

                if (over) {
                    input.classList.add('is-invalid');
                    return;
                }

                input.classList.remove('is-invalid');
                total += mark;
                marked++;
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
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
