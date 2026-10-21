(function (root) {
    'use strict';
    function init(form) {
        if (!form || form.dataset.programmeContextReady) return;
        form.dataset.programmeContextReady = '1';
        var department = form.querySelector('[name="department_id"]');
        var faculty = form.querySelector('#piie-faculty');
        var hod = form.querySelector('#piie-hod');
        var active = form.querySelector('[name="is_active"]');
        var published = form.querySelector('input[type="checkbox"][name="is_published"]');
        function updateDepartment() {
            var option = department && department.options[department.selectedIndex];
            if (faculty) faculty.value = option ? (option.dataset.faculty || '') : '';
            if (hod) hod.value = option ? (option.dataset.hod || '') : '';
        }
        function updatePublication() {
            if (!active || !published) return;
            published.disabled = active.value !== '1';
            if (published.disabled) published.checked = false;
        }
        if (department) department.addEventListener('change', updateDepartment);
        if (active) active.addEventListener('change', updatePublication);
        updateDepartment();
        updatePublication();
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = init;
    else init(root.document.getElementById('piie-programme-edit'));
}(typeof window === 'undefined' ? globalThis : window));
