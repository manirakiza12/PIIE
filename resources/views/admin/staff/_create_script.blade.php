{{-- Progressive enhancement only. Every control here also works without
     JavaScript: the "Other" description fields are rendered hidden or shown by
     the server, and the repeater starts with one usable row. --}}
<script>
    (function () {
        "use strict";

        var EMPTY_FILE = '{{ get_phrase('No file selected') }}';

        // Reveal a paired free-text field when its select is on a specific value.
        function pairToggles(selectId, wrapId, value, other) {
            var select = document.getElementById(selectId);
            var wrap = document.getElementById(wrapId);
            if (!select || !wrap) { return; }
            var field = wrap.querySelector('input, select');
            function sync() {
                var on = other ? select.value !== other : select.value === value;
                wrap.classList.toggle('d-none', !on);
                if (field) { field.required = on; }
            }
            select.addEventListener('change', sync);
            sync();
        }

        pairToggles('title', 'title-other-wrap', 'Other', null);
        pairToggles('emergency_contact_relationship', 'nok-other-wrap', 'Other', null);
        pairToggles('qualification_level', 'qual-level-other-wrap', 'Other', null);

        // ---------------------------------------------------- file pickers
        // The native input stays in the form; this only shows the chosen file
        // name next to the button, and previews an image when one is chosen.
        var previewUrls = {};

        function byId(input, attribute) {
            var id = input.getAttribute(attribute);
            return id ? document.getElementById(id) : null;
        }

        function showFileName(input) {
            var label = byId(input, 'data-filename');
            if (!label) { return; }
            var chosen = !!(input.files && input.files.length);
            label.textContent = chosen ? input.files[0].name : EMPTY_FILE;
            label.classList.toggle('is-set', chosen);
        }

        function canMakeObjectURL() {
            return typeof URL !== 'undefined'
                && typeof URL.createObjectURL === 'function'
                && typeof URL.revokeObjectURL === 'function';
        }

        function resetPreview(input) {
            var preview = byId(input, 'data-preview');
            var placeholder = byId(input, 'data-placeholder');
            if (preview) {
                if (previewUrls[preview.id]) {
                    if (canMakeObjectURL()) { URL.revokeObjectURL(previewUrls[preview.id]); }
                    delete previewUrls[preview.id];
                }
                preview.removeAttribute('src');
                preview.classList.remove('is-loaded');
            }
            if (placeholder) { placeholder.classList.remove('is-hidden'); }
        }

        function bindFileInput(input) {
            if (!input || input.dataset.scBound === '1') { return; }
            input.dataset.scBound = '1';

            input.addEventListener('change', function () {
                showFileName(input);

                var preview = byId(input, 'data-preview');
                var placeholder = byId(input, 'data-placeholder');
                var file = input.files && input.files.length ? input.files[0] : null;

                // The preview is a convenience: a browser without object URLs
                // still shows the chosen file name and still uploads the file.
                var previewable = preview && file
                    && file.type.indexOf('image/') === 0
                    && canMakeObjectURL();

                if (!previewable) {
                    resetPreview(input);
                    return;
                }

                resetPreview(input);
                previewUrls[preview.id] = URL.createObjectURL(file);
                preview.src = previewUrls[preview.id];
                preview.classList.add('is-loaded');
                if (placeholder) { placeholder.classList.add('is-hidden'); }
            });
        }

        document.querySelectorAll('.sc-file-input').forEach(bindFileInput);

        // ------------------------------------------------ supporting documents
        // Add and remove rows, renumbering the input names so the server always
        // receives documents[0..n].
        var list = document.getElementById('documents-list');
        var addButton = document.getElementById('add-document');
        if (!list || !addButton) { return; }

        var MAX = {{ (int) $maxDocuments }};
        var counter = document.getElementById('document-count');

        function renumber() {
            var rows = list.querySelectorAll('.document-row');
            rows.forEach(function (row, index) {
                var renumberRef = function (value) {
                    return value.replace(/documents_\d+_/, 'documents_' + index + '_');
                };

                row.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(/documents\[\d+\]/, 'documents[' + index + ']');
                });
                row.querySelectorAll('[id]').forEach(function (node) {
                    node.id = renumberRef(node.id);
                });
                // Keep every label pointing at its own control after a renumber.
                row.querySelectorAll('label[for]').forEach(function (label) {
                    label.htmlFor = renumberRef(label.htmlFor);
                });
                row.querySelectorAll('[data-filename]').forEach(function (input) {
                    input.setAttribute('data-filename', renumberRef(input.getAttribute('data-filename')));
                });

                var badge = row.querySelector('[data-doc-index]');
                if (badge) { badge.textContent = index + 1; }
            });

            addButton.disabled = rows.length >= MAX;
            if (counter) {
                counter.textContent = rows.length >= MAX
                    ? '{{ get_phrase('Maximum') }} ' + MAX + ' {{ get_phrase('documents') }}'
                    : rows.length + ' / ' + MAX;
            }
        }

        function clearRow(row) {
            row.querySelectorAll('input[type=file]').forEach(function (input) {
                input.value = '';
                resetPreview(input);
                showFileName(input);
            });
            var select = row.querySelector('select');
            if (select) { select.value = ''; }
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest('.remove-document');
            if (!button) { return; }
            var row = button.closest('.document-row');
            // Always leave one row so the section is never empty.
            if (list.querySelectorAll('.document-row').length > 1) {
                row.parentNode.removeChild(row);
            } else {
                clearRow(row);
            }
            renumber();
        });

        addButton.addEventListener('click', function () {
            var template = list.querySelector('.document-row');
            if (!template || list.querySelectorAll('.document-row').length >= MAX) { return; }
            var clone = template.cloneNode(true);
            clearRow(clone);
            clone.querySelectorAll('.invalid-feedback').forEach(function (n) { n.remove(); });
            clone.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
            // A cloned input carries no listeners, so bind the new one too.
            clone.querySelectorAll('.sc-file-input').forEach(function (input) {
                delete input.dataset.scBound;
                bindFileInput(input);
            });
            list.appendChild(clone);
            renumber();
        });

        renumber();
    })();
</script>
