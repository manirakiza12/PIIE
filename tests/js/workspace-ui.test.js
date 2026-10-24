const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync('public/assets/js/workspace-ui.js', 'utf8');

function fixture(width = 390, path = '/admin/programmes', container = 'form-row', formAttributes = '') {
  const fields = Array.from({length:12}, (_, i) => `<div class="fpb-7"><label for="field${i}">Field ${i}</label><input id="field${i}" name="field${i}" value="value${i}" ${i === 10 ? 'required' : ''}></div>`).join('');
  const dom = new JSDOM(`<body><aside class="sidebar"><nav class="nav-links"><a href="/admin/programmes"><span class="link_name">Programmes</span></a><a href="/admin/students"><span class="link_name">Students</span></a><a href="/logout">Logout</a></nav></aside><div class="menuList">Modules</div><main class="main_content"><form id="page-form" method="post" ${formAttributes}><input name="csrf" type="hidden" value="token"><div class="form-row">${fields}<div><button type="submit">Save</button></div></div></form></main><div class="offcanvas workspace-surface" id="panel"><div class="offcanvas-body"></div></div><div class="modal" id="confirmModal">Confirm deletion</div></body>`, {url:'http://localhost'+path, runScripts:'outside-only'});
  Object.defineProperty(dom.window, 'innerWidth', {value:width, writable:true});
  dom.window.document.querySelector('#page-form > .form-row').className = container;
  dom.window.eval(script);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  return dom;
}

test('dedicated add pages fill the workspace and paginate while preserving all submitted values', () => {
  const dom = fixture(390, '/admin/student/add_student');
  const {document} = dom.window;
  assert(document.body.classList.contains('workspace-page'));
  assert.equal(document.querySelector('.workspace-steps span').textContent, 'Step 1 of 4');
  assert.equal(document.querySelectorAll('.workspace-step-hidden').length, 9);
  document.querySelector('.workspace-steps .btn-primary').click();
  assert.equal(document.querySelector('.workspace-steps span').textContent, 'Step 2 of 4');
  assert.equal(new dom.window.FormData(document.querySelector('form')).get('field0'), 'value0');
  assert.equal(new dom.window.FormData(document.querySelector('form')).get('field11'), 'value11');
  assert.equal(new dom.window.FormData(document.querySelector('form')).get('csrf'), 'token');
  dom.window.innerWidth = 1440;
  dom.window.dispatchEvent(new dom.window.Event('resize'));
  assert.equal(document.querySelector('.workspace-steps span').textContent, 'Step 2 of 2');
  dom.window.close();
});

test('continuous forms inside workspace pages and AJAX modals do not get step controls', () => {
  const dom = fixture(390, '/admin/student/create_modal', 'form-row', 'data-workspace-continuous');
  const form = dom.window.document.querySelector('#page-form');
  assert.equal(form.hasAttribute('data-workspace-steps'), false);
  assert.equal(dom.window.document.querySelectorAll('.workspace-steps').length, 0);
  assert.equal(dom.window.document.querySelectorAll('.workspace-step-hidden').length, 0);
  dom.window.close();
});

test('normal module pages retain their original sidebar links and menu controls', () => {
  const dom = fixture();
  const {document} = dom.window;
  assert(!document.body.classList.contains('workspace-shell'));
  assert(!document.body.classList.contains('workspace-page'));
  assert.equal(document.querySelectorAll('.sidebar a').length, 3);
  assert.equal(document.querySelector('.sidebar a').getAttribute('href'), '/admin/programmes');
  assert.equal(document.querySelector('.menuList').getAttribute('aria-controls'), null);
  assert.equal(document.querySelector('#workspace-module-menu'), null);
  dom.window.close();
});

test('applicant forms using Bootstrap rows also get steps and a dashboard return link', () => {
  const dom = fixture(390, '/applicant/application/personal', 'row');
  const {document} = dom.window;
  assert.equal(document.querySelector('#page-form .workspace-steps span').textContent, 'Step 1 of 4');
  assert.equal(document.querySelector('.workspace-back').pathname, '/applicant/dashboard');
  assert.equal(new dom.window.FormData(document.querySelector('form')).get('field11'), 'value11');
  dom.window.close();
});

test('validation reveals required fields on later steps before focusing them', async () => {
  const dom = fixture(390, '/superadmin/school/create');
  const {document} = dom.window;
  const control = document.querySelector('#field10');
  control.value = '';
  document.querySelector('form').checkValidity();
  await new Promise(resolve => dom.window.setTimeout(resolve, 20));
  assert.equal(document.querySelector('.workspace-steps span').textContent, 'Step 4 of 4');
  assert(!control.closest('.fpb-7').classList.contains('workspace-step-hidden'));
  dom.window.close();
});

test('AJAX form insertion is enhanced once; opening and closing restores the module navigation', async () => {
  const dom = fixture();
  const {document, Event} = dom.window;
  assert(!document.body.classList.contains('workspace-page'));
  const panel = document.querySelector('#panel');
  panel.dispatchEvent(new Event('show.bs.offcanvas'));
  panel.classList.add('show');
  assert(document.body.classList.contains('workspace-open'));
  panel.querySelector('.offcanvas-body').innerHTML = document.querySelector('.main_content').innerHTML;
  await new Promise(resolve => dom.window.setTimeout(resolve, 20));
  dom.window.prepareWorkspaceUI(panel);
  dom.window.prepareWorkspaceUI(panel);
  assert.equal(panel.querySelectorAll('.workspace-steps').length, 1);
  assert(!document.querySelector('#confirmModal').classList.contains('workspace-surface'));
  panel.classList.remove('show');
  panel.dispatchEvent(new Event('hidden.bs.offcanvas'));
  assert(!document.body.classList.contains('workspace-open'));
  dom.window.close();
});

test('tables get one responsive wrapper without changing their cell contents', () => {
  const dom = fixture();
  const panel = dom.window.document.querySelector('#panel');
  panel.innerHTML = '<table><tbody><tr><td>Student record</td></tr></tbody></table>';
  dom.window.prepareWorkspaceUI(panel);
  dom.window.prepareWorkspaceUI(panel);
  assert.equal(panel.querySelectorAll('.table-responsive').length, 1);
  assert.equal(panel.querySelector('td').textContent, 'Student record');
  dom.window.close();
});
