const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/student-invoice-setup.js'), 'utf8');

function setup() {
  const dom = new JSDOM(`<!doctype html><body>
    <form id="student_invoice_setup_form" data-currency="UGX">
      <select id="fee_academic_year_id"><option value="1" selected>2026/27</option></select>
      <select id="fee_session_id"><option value="">Choose</option><option value="10" selected>Semester 1</option><option value="11">Semester 2</option></select>
      <select id="fee_structure_id"><option value="">Choose fee</option><option value="20" data-session="10" data-type="Tuition" data-name="Programme A" data-amount="1500000.00" data-basis="Per semester" selected>Tuition</option><option value="21" data-session="11" data-type="Tuition" data-name="Programme A" data-amount="1500000.00" data-basis="Per semester">Tuition</option><option value="22" data-session="" data-type="Registration" data-name="Programme A" data-amount="100000.00" data-basis="As configured">Registration</option></select>
      <div id="fee_summary"></div>
      <input id="received_payment_amount" value="0">
      <div id="receipt_details"><input id="received_payment_reference"></div>
      <output id="remaining_balance"></output>
    </form>
  </body>`, { runScripts: 'outside-only', url: 'https://piie.example.test/admin/students/1/invoice/setup' });
  dom.window.eval(source);
  return dom;
}

test('invoice setup displays the selected programme fee and recomputes remaining balance through the payment ledger amount', () => {
  const dom = setup();
  const document = dom.window.document;
  assert.match(document.getElementById('fee_summary').textContent, /Tuition · Programme A: UGX 1,500,000\.00 per semester/);
  assert.equal(document.getElementById('remaining_balance').textContent, 'UGX 1,500,000.00');
  const received = document.getElementById('received_payment_amount');
  received.value = '250000';
  received.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  assert.equal(document.getElementById('remaining_balance').textContent, 'UGX 1,250,000.00');
  assert.equal(document.getElementById('receipt_details').hidden, false);
  assert.equal(document.getElementById('received_payment_reference').required, true);
  dom.window.close();
});

test('changing Finance Session clears an incompatible fee and preserves general fees', () => {
  const dom = setup();
  const document = dom.window.document;
  const fee = document.getElementById('fee_structure_id');
  const session = document.getElementById('fee_session_id');
  session.value = '11';
  session.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(fee.value, '', 'A fee tied to the prior session cannot remain selected');
  assert.equal(fee.options[1].hidden, true);
  assert.equal(fee.options[2].hidden, false);
  assert.equal(fee.options[3].hidden, false, 'A general programme fee is valid in either session');
  fee.value = '21';
  fee.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.match(document.getElementById('fee_summary').textContent, /per semester/);
  dom.window.close();
});
