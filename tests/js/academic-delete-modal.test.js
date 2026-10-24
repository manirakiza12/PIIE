const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const modalSource = readFileSync('resources/views/shared/academic_delete_modal.blade.php', 'utf8')
  .split("@if(session('success'))")[0]
  .replace(/\{\{[\s\S]*?\}\}/g, '')
  .replace(/@if\([^\n]*?\) hidden @endif/g, 'hidden')
  .replace('@csrf @method(\'DELETE\')', '<input type="hidden" name="_token" value="test-token"><input type="hidden" name="_method" value="DELETE">');
const controller = readFileSync('public/assets/js/academic-delete-modal.js', 'utf8');
const bootstrapBundle = readFileSync(require.resolve('bootstrap/dist/js/bootstrap.bundle.js'), 'utf8');

function fixture(successMessage = null, toast = null) {
  const notice = successMessage === null ? '' : `<span hidden data-delete-success>${successMessage}</span>`;
  const dom = new JSDOM(`<head><style>.fade{transition:none!important}</style></head><body>${notice}<main><div class="table-responsive"><button id="curriculum" type="button" data-bs-toggle="modal" data-bs-target="#academic-delete-modal" data-delete-action="/admin/curricula/42" data-delete-title="Delete Programme Curriculum" data-delete-identity="BIO BSc v2 (record #42)" data-delete-reference="BIO BSc v2" data-delete-removes="Version placements and owned registrations" data-delete-keeps="Shared Course Units, accounts and profiles"></button><button id="year" type="button" data-bs-toggle="modal" data-bs-target="#academic-delete-modal" data-delete-action="/admin/academic-structure/years/7" data-delete-title="Delete Academic Year" data-delete-identity="2027/2028 (record #7)" data-delete-reference="2027/2028" data-delete-removes="Year Course Unit links and allocations" data-delete-keeps="Shared Course Units and links in other years"></button></div>${modalSource}</main></body>`, {
    url: 'http://localhost/admin/curricula', runScripts: 'outside-only', pretendToBeVisual: true,
  });
  const win = dom.window;
  if (toast) win.toastr = toast;
  win.eval(bootstrapBundle);
  win.eval(controller);
  win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
  return dom;
}
const afterModalTransition = () => new Promise(resolve => setTimeout(resolve, 400));

test('modal opens from a table, identifies its record, exposes linked-data outcomes and focuses Cancel', async () => {
  const dom = fixture();
  const {document} = dom.window;
  const trigger = document.querySelector('#year');
  trigger.click();
  const modal = document.querySelector('#academic-delete-modal');
  await afterModalTransition();
  assert.equal(modal.parentElement, document.body, 'modal escapes table overflow');
  assert.equal(document.activeElement, modal.querySelector('[data-delete-cancel]'));
  assert.equal(modal.querySelector('#academic-delete-title').textContent, 'Delete Academic Year');
  assert.equal(modal.querySelector('#academic-delete-identity').textContent, '2027/2028 (record #7)');
  assert.equal(modal.querySelector('[data-delete-removes]').textContent, 'Year Course Unit links and allocations');
  assert.match(modal.querySelector('[data-delete-keeps]').textContent, /links in other years/);
  assert.equal(modal.querySelector('[data-delete-form]').action, 'http://localhost/admin/academic-structure/years/7');
  assert.equal(modal.querySelector('[data-delete-form]').elements._token.value, 'test-token');
  assert.equal(modal.getAttribute('aria-labelledby'), 'academic-delete-title');
  assert.equal(modal.getAttribute('aria-describedby'), 'academic-delete-identity academic-delete-summary');
  dom.window.bootstrap.Modal.getInstance(modal).hide();
  await afterModalTransition();
  assert.equal(document.activeElement, trigger, 'closing restores focus to the opener');
  assert.equal(modal.querySelector('[data-delete-form]').hasAttribute('action'), false);
  dom.window.close();
});

test('Cancel, close button, and Escape leave records untouched and clear typed confirmation', async () => {
  const dom = fixture();
  const {document, KeyboardEvent} = dom.window;
  const trigger = document.querySelector('#curriculum');
  trigger.click();
  await afterModalTransition();
  const modal = document.querySelector('#academic-delete-modal');
  const form = modal.querySelector('[data-delete-form]');
  const input = form.elements.confirm_deletion;
  input.value = 'BIO BSc v2';
  input.dispatchEvent(new dom.window.Event('input', {bubbles: true}));
  assert.equal(modal.querySelector('[data-delete-submit]').disabled, false);
  modal.querySelector('[data-delete-cancel]').click();
  await afterModalTransition();
  assert.equal(input.value, '');
  assert.equal(modal.querySelector('[data-delete-submit]').disabled, true);
  assert.equal(form.hasAttribute('action'), false);
  assert.equal(form.dataset.submitted, undefined);

  trigger.click();
  await afterModalTransition();
  modal.querySelector('.btn-close').click();
  await afterModalTransition();
  assert.equal(input.value, '');

  trigger.click();
  await afterModalTransition();
  modal.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
  await afterModalTransition();
  assert.equal(modal.classList.contains('show'), false);
  assert.equal(form.dataset.submitted, undefined);
  dom.window.close();
});

test('only DELETE or the exact reference enables confirmation; valid submit targets the existing route once', () => {
  const dom = fixture();
  const {document} = dom.window;
  document.querySelector('#curriculum').click();
  const modal = document.querySelector('#academic-delete-modal');
  const form = modal.querySelector('[data-delete-form]');
  const input = form.elements.confirm_deletion;
  const button = modal.querySelector('[data-delete-submit]');
  input.value = 'wrong';
  input.dispatchEvent(new dom.window.Event('input', {bubbles: true}));
  assert.equal(button.disabled, true);
  let submissions = 0;
  form.addEventListener('submit', event => { if (!event.defaultPrevented) { submissions += 1; event.preventDefault(); } });
  input.value = 'DELETE';
  input.dispatchEvent(new dom.window.Event('input', {bubbles: true}));
  assert.equal(button.disabled, false);
  form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
  form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
  assert.equal(submissions, 1, 'duplicate submissions are prevented');
  assert.equal(form.action, 'http://localhost/admin/curricula/42');
  assert.equal(form.elements._method.value, 'DELETE');
  dom.window.close();
});

test('successful redirect messages are announced through the existing toast component', () => {
  const calls = [];
  const dom = fixture('Academic Year 2027/2028 deleted.', {success: (...args) => calls.push(args)});
  assert.equal(calls.length, 1);
  assert.equal(calls[0][0], 'Academic Year 2027/2028 deleted.');
  assert.equal(calls[0][1], 'Success');
  assert.equal(calls[0][2].escapeHtml, true);
  dom.window.close();
});
