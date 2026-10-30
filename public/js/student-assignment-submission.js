(function () {
    'use strict';
    var form = document.getElementById('as-form');
    if (!form) { return; }
    var selector = form.querySelector('[data-as-method-select]');
    function showMethod() {
        if (!selector) { return; }
        form.querySelectorAll('[data-as-method]').forEach(function (panel) {
            // Keep values and selected files in the form when switching methods.
            panel.hidden = panel.getAttribute('data-as-method') !== selector.value;
            panel.querySelectorAll('input,textarea,select').forEach(function (field) { field.disabled = panel.hidden; });
        });
    }
    if (selector) { selector.addEventListener('change', showMethod); showMethod(); }
    form.querySelectorAll('[data-as-question-method]').forEach(function (select) {
        var host = select.closest('[data-question-id]');
        var panels = host.querySelectorAll('[data-as-question-method-panel]');
        // Resume the first populated answer rather than hiding saved work.
        panels.forEach(function (panel) {
            if (panel.querySelector('[data-testid="as-attached-recorded"]') || /Already attached:/.test(panel.textContent)
                || Array.from(panel.querySelectorAll('textarea,input[type="url"]')).some(function (field) { return field.value.trim(); })) {
                select.value = panel.getAttribute('data-as-question-method-panel');
            }
        });
        function show() {
            panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-as-question-method-panel') !== select.value; });
        }
        select.addEventListener('change', show); show();
    });
    // Reopen any field with an error so it is reachable after validation.
    form.querySelectorAll('[aria-invalid="true"]').forEach(function (field) {
        var panel = field.closest('[data-as-method]');
        if (panel && selector) { selector.value = panel.getAttribute('data-as-method'); showMethod(); }
        var details = field.closest('details'); if (details) { details.open = true; }
    });
    var review = document.getElementById('as-review');
    if (review) {
        review.addEventListener('toggle', function () {
            var list = review.querySelector('[data-as-review-list]');
            list.textContent = '';
            form.querySelectorAll('input[type="file"]').forEach(function (field) {
                Array.from(field.files || []).forEach(function (file) {
                    var li = document.createElement('li'); li.textContent = file.name; list.appendChild(li);
                });
            });
        });
    }
    var busy = false;
    form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) { return; }
        event.preventDefault();
        if (busy) { return; }
        busy = true;
        var submitter = event.submitter;
        var draft = submitter && submitter.hasAttribute('data-as-draft');
        var notice = form.querySelector('[data-as-submit-status]');
        if (window.PIIEAademicEditor) { window.PIIEAademicEditor.syncAll(form); }
        form.setAttribute('aria-busy', 'true');
        notice.textContent = draft ? 'Saving draft…' : 'Submitting assignment…';
        var pending = window.piieAutosave ? window.piieAutosave.flush() : Promise.resolve(true);
        pending.then(function (ok) {
            if (!ok || (window.piieAutosave && window.piieAutosave.failed())) {
                throw new Error('Your answers have not finished saving. Your work is still here; please retry.');
            }
            if (draft) { form.action = submitter.getAttribute('formaction'); }
            // Submit this single form after syncing; no second answer copy exists.
            HTMLFormElement.prototype.submit.call(form);
        }).catch(function (error) {
            busy = false; form.removeAttribute('aria-busy'); notice.textContent = error.message;
        });
    }, true);
}());
