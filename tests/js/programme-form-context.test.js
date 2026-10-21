const {test} = require('node:test');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const init = require('../../public/assets/js/programme-form-context.js');

function form() {
    const dom = new JSDOM(`<form><select name="department_id"><option value="" data-faculty="Select a department" data-hod="Select a department"></option><option value="1" data-faculty="Computing" data-hod="Amina"></option><option value="2" data-faculty="Business" data-hod="Ben"></option></select><input id="piie-faculty" readonly><input id="piie-hod" readonly><select name="is_active"><option value="1">Yes</option><option value="0">No</option></select><input type="hidden" name="is_published" value="0"><input type="checkbox" name="is_published" value="1" checked></form>`);
    return {dom, form:dom.window.document.querySelector('form'), change: node => node.dispatchEvent(new dom.window.Event('change'))};
}

test('department changes update names and clearing selection clears stale context', () => {
    const f = form();
    const department = f.form.querySelector('select[name="department_id"]');
    department.value = '1';
    init(f.form);
    assert.equal(f.form.querySelector('#piie-faculty').value, 'Computing');
    department.value = '2'; f.change(department);
    assert.equal(f.form.querySelector('#piie-faculty').value, 'Business');
    assert.equal(f.form.querySelector('#piie-hod').value, 'Ben');
    department.value = ''; f.change(department);
    assert.equal(f.form.querySelector('#piie-hod').value, 'Select a department');
});

test('inactive status clears publication and reactivation enables the publication choice', () => {
    const f = form(); init(f.form);
    const active = f.form.querySelector('[name="is_active"]');
    const published = f.form.querySelector('input[type="checkbox"]');
    active.value = '0'; f.change(active);
    assert.equal(published.checked, false); assert.equal(published.disabled, true);
    assert.equal(new f.dom.window.FormData(f.form).get('is_published'), '0');
    active.value = '1'; f.change(active);
    assert.equal(published.disabled, false);
});

test('department names remain text and repeated initialization is harmless', () => {
    const f = form();
    const department = f.form.querySelector('[name="department_id"]');
    department.options[1].dataset.hod = '<img src=x onerror=alert(1)>';
    department.value = '1'; init(f.form); init(f.form);
    assert.equal(f.form.querySelector('#piie-hod').value, '<img src=x onerror=alert(1)>');
    assert.equal(f.form.querySelectorAll('img').length, 0);
    assert.doesNotThrow(() => init(null));
});
