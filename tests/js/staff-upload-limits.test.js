const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function formHarness() {
    const template = fs.readFileSync(path.join(__dirname, '../../resources/views/admin/staff/_create_script.blade.php'), 'utf8');
    const source = template.slice(template.indexOf('        var uploadForm'), template.indexOf('        // ---------------------------------------------------- file pickers'))
        .replace(/\{\{ \(int\) \$maxKb \* 1024 \}\}/g, '5242880')
        .replace(/\{\{ \\App\\Support\\Staff\\StaffUploadLimits::TOTAL_BYTES \}\}/g, '20971520')
        .replace(/@json\(\\App\\Support\\Staff\\StaffUploadLimits::MESSAGE\)/g, JSON.stringify('Combined staff uploads must not exceed 20 MiB.'));
    const handlers = {};
    let inputs = [];
    const error = { hidden: true, focus() { this.focused = true; } };
    const form = { querySelectorAll: () => inputs, addEventListener: (name, handler) => { handlers[name] = handler; } };
    vm.runInNewContext(source, { document: { getElementById: id => id === 'staff-create-form' ? form : error } });
    return { error, setInputs: value => { inputs = value; }, change: () => handlers.change(), submit() {
        let prevented = false;
        handlers.submit({ preventDefault() { prevented = true; } });
        return prevented;
    } };
}
const input = (name, size) => ({ name, files: [{ size }] });

test('photo and document boundaries are accepted; larger files block submission', () => {
    const h = formHarness();
    h.setInputs([input('photo', 4194304), input('documents[0][file]', 5242880)]);
    assert.equal(h.submit(), false);
    h.setInputs([input('photo', 4194305)]);
    h.change();
    assert.match(h.error.textContent, /Profile photo exceeds 4 MiB/);
    assert.equal(h.submit(), true);
    assert.equal(h.error.focused, true);
    h.setInputs([input('documents[2][file]', 5242881)]);
    assert.equal(h.submit(), true);
    assert.match(h.error.textContent, /Supporting document exceeds 5 MiB/);
});

test('combined boundary includes dynamic rows and clears after removing files', () => {
    const h = formHarness();
    const rows = Array.from({ length: 4 }, (_, i) => input(`documents[${i}][file]`, 5242880));
    h.setInputs(rows);
    assert.equal(h.submit(), false);
    h.setInputs([...rows, input('photo', 1)]);
    assert.equal(h.submit(), true);
    assert.match(h.error.textContent, /Combined staff uploads/);
    h.setInputs(rows.slice(0, 1));
    h.change();
    assert.equal(h.error.hidden, true);
    assert.equal(h.submit(), false);
});
