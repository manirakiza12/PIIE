const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/existing-student-form.js'), 'utf8');

function formMarkup() {
  return `
    <form id="existing-student-form">
      <select id="programme_id"><option value="">Programme</option><option value="10" selected>Programme A</option><option value="11">Programme B</option></select>
      <select id="intake_session_id"><option value="">Intake</option><option value="20" selected>Intake A</option><option value="21">Intake B</option></select>
      <input id="year_of_study" value="1">
      <select id="fee_academic_year_id"><option value="">Year</option><option value="30" selected>2026/27</option><option value="31">2027/28</option></select>
      <div id="cohort-context" role="status"></div>
      <script type="application/json" id="cohort-entry-year-map">{"10|20":["2026/27"],"11|21":["2027/28"]}</script>
      <input type="checkbox" id="record_fees" checked>
      <div id="existing_student_fee_fields">
        <div class="row">
          <select id="fee_session_id">
            <option value="">Choose</option><option value="40" data-academic-year-id="30" selected>Session A</option><option value="41" data-academic-year-id="31">Session B</option>
          </select>
          <div id="fee-session-context-message" hidden></div>
          <select id="fee_structure_id">
            <option value="">Choose fee</option><option value="50" data-name="Fee A" data-fee-type="tuition" data-per-semester="1" data-programme-id="10" data-session-id="40" data-session-academic-year-id="30" data-amount="1500000.00" selected>Fee A · Tuition · UGX 1,500,000 · Per semester</option><option value="51" data-name="Fee B" data-fee-type="tuition" data-per-semester="1" data-programme-id="11" data-session-id="41" data-session-academic-year-id="31" data-amount="600000.00">Fee B · Tuition · UGX 600,000 · Per semester</option><option value="52" data-name="Registration" data-fee-type="registration" data-per-semester="0" data-programme-id="10" data-session-id="" data-amount="100000.00">Registration</option>
          </select>
          <div id="fee-structure-context-message" hidden></div>
          <output id="selected-fee-amount" data-currency="UGX"></output>
          <div id="fee-billing-basis"></div>
          <output id="invoice-period-summary"></output>
          <output id="remaining-fee-balance"></output>
          <input id="received_payment_amount" value="0">
          <div id="received-payment-context-message" hidden></div>
          <div id="received_payment_details" hidden><input id="received_payment_reference"></div>
          <input id="prior_paid_amount" value="0">
        </div>
        <div id="prior_payment_details" hidden><input id="prior_paid_on"></div>
        <input type="checkbox" id="record_award"><div id="award_details" hidden><input id="award_amount"></div>
        <input type="checkbox" id="record_study_waiver"><div id="study_waiver_details" hidden><input id="study_waiver_reason"></div>
      </div>
      <input type="radio" name="password_option" value="auto" checked>
      <input type="radio" name="password_option" value="manual">
      <div id="password_manual_wrap" hidden><input id="password"></div>
    </form>`;
}

function buildForm() {
  const dom = new JSDOM(`<!doctype html><body>${formMarkup()}</body>`, { runScripts: 'outside-only' });
  dom.window.eval(source);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  return dom;
}

test('fee sessions are filtered by academic year and a stale session and fee type are cleared', () => {
  const dom = buildForm();
  const document = dom.window.document;
  const year = document.getElementById('fee_academic_year_id');
  const session = document.getElementById('fee_session_id');
  const fee = document.getElementById('fee_structure_id');

  assert.equal(document.getElementById('existing_student_fee_fields').hidden, false, 'A checked Finance option reveals the complete panel on initialization');
  assert.equal(session.disabled, false, 'Finance controls are enabled after the initializer runs');
  assert.equal(session.options[1].hidden, false);
  assert.equal(session.options[2].hidden, true);
  assert.equal(fee.options[1].hidden, false);
  assert.equal(fee.options[2].hidden, true);
  assert.match(document.getElementById('selected-fee-amount').textContent, /1,500,000/);
  assert.match(document.getElementById('remaining-fee-balance').textContent, /1,500,000/);
  assert.match(document.getElementById('selected-fee-amount').textContent, /Tuition/);
  assert.match(document.getElementById('fee-billing-basis').textContent, /Per semester.*full configured amount.*no multiplier/);
  assert.match(document.getElementById('invoice-period-summary').textContent, /2026\/27.*Session A/);

  const received = document.getElementById('received_payment_amount');
  const balance = document.getElementById('remaining-fee-balance');
  received.value = '250000';
  received.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  assert.equal(document.getElementById('received_payment_details').hidden, false, 'Confirmed-payment details open for a positive amount');
  assert.match(balance.textContent, /1,250,000/, 'Preview uses the configured programme fee less confirmed money');
  received.value = '1600000';
  received.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  assert.match(balance.textContent, /must not exceed/);

  year.value = '31';
  year.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

  assert.equal(session.value, '', 'A session from another academic year cannot remain selected');
  assert.equal(session.options[1].hidden, true);
  assert.equal(session.options[2].hidden, false);
  assert.match(document.getElementById('fee-session-context-message').textContent, /previous session belongs to another year/);
  assert.equal(fee.value, '', 'A fee restricted to the old session cannot remain selected');
  assert.match(document.getElementById('selected-fee-amount').textContent, /Select a Fee type/);
  dom.window.close();
});

test('programme and intake changes refresh fee-type and cohort-year context independently of Year of Study', () => {
  const dom = buildForm();
  const document = dom.window.document;
  const programme = document.getElementById('programme_id');
  const intake = document.getElementById('intake_session_id');
  const yearOfStudy = document.getElementById('year_of_study');
  const academicYear = document.getElementById('fee_academic_year_id');
  const session = document.getElementById('fee_session_id');
  const fee = document.getElementById('fee_structure_id');
  const context = document.getElementById('cohort-context');

  academicYear.value = '31';
  academicYear.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  session.value = '41';
  session.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  programme.value = '11';
  programme.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(fee.value, '', 'A programme restricted fee is cleared when the programme changes');
  assert.equal(fee.options[1].hidden, true);
  assert.equal(fee.options[2].hidden, false, 'The new programme and selected session fee is refreshed');
  fee.value = '51';
  fee.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.match(document.getElementById('selected-fee-amount').textContent, /600,000/, 'Selecting a programme Fee Structure displays its configured amount');
  assert.match(document.getElementById('remaining-fee-balance').textContent, /600,000/);
  assert.match(context.textContent, /No configured cohort links/);

  intake.value = '21';
  intake.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.match(context.textContent, /Configured cohort entry year: 2027\/28/);

  yearOfStudy.value = '2';
  yearOfStudy.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.match(context.textContent, /Configured cohort entry year: 2027\/28/);
  assert.equal(academicYear.value, '31', 'Progression year does not silently become an academic year');
  dom.window.close();
});

test('finance remains optional and dependent review fields are disabled until selected', () => {
  const dom = buildForm();
  const document = dom.window.document;
  const toggle = document.getElementById('record_fees');
  const fields = document.getElementById('existing_student_fee_fields');
  const session = document.getElementById('fee_session_id');
  const paymentDate = document.getElementById('prior_paid_on');

  toggle.checked = false;
  toggle.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(fields.hidden, true);
  assert.equal(session.disabled, true);
  assert.equal(document.getElementById('received_payment_amount').disabled, true);
  assert.equal(document.getElementById('received_payment_reference').disabled, true);
  assert.equal(paymentDate.disabled, true);

  toggle.checked = true;
  toggle.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(fields.hidden, false);
  assert.equal(session.disabled, false);
  assert.equal(document.getElementById('received_payment_amount').disabled, false);
  assert.equal(paymentDate.disabled, true, 'Review details stay optional until a prior payment is entered');
  dom.window.close();
});

test('Finance controls initialize after the current AJAX modal inserts its form', async () => {
  const dom = new JSDOM('<!doctype html><body><div id="right-modal"></div></body>', { runScripts: 'outside-only' });
  dom.window.eval(source);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  dom.window.document.getElementById('right-modal').innerHTML = formMarkup().replace(' checked', '').replace('id="existing_student_fee_fields"', 'id="existing_student_fee_fields" hidden');
  await new Promise(resolve => dom.window.setTimeout(resolve, 0));

  const document = dom.window.document;
  const toggle = document.getElementById('record_fees');
  const fields = document.getElementById('existing_student_fee_fields');
  const session = document.getElementById('fee_session_id');
  assert.equal(fields.hidden, true);
  assert.equal(session.disabled, true);

  toggle.checked = true;
  toggle.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(fields.hidden, false, 'Opening through AJAX and enabling Finance reveals its fields');
  assert.equal(session.disabled, false);
  assert.equal(document.getElementById('received_payment_amount').disabled, false);
  dom.window.close();
});

test('an academic year with no Finance Session explains setup, keeps programme fees selectable, and blocks invoice submission', () => {
  const dom = buildForm();
  const document = dom.window.document;
  const year = document.getElementById('fee_academic_year_id');
  const session = document.getElementById('fee_session_id');
  const form = document.getElementById('existing-student-form');
  const noSessionYear = document.createElement('option');
  noSessionYear.value = '32';
  noSessionYear.textContent = '2028/2029';
  year.append(noSessionYear);

  year.value = '32';
  year.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

  assert.equal(session.value, '');
  assert.equal(session.disabled, true);
  const fee = document.getElementById('fee_structure_id');
  assert.match(document.getElementById('fee-session-context-message').textContent, /No Finance Session is linked.*create or edit a Finance Session.*Session Manager/);
  assert.equal(fee.disabled, false, 'A missing accounting period does not disable programme Fee Structure selection');
  assert.equal(fee.options[3].hidden, false, 'A non-semester programme fee remains visible independently of the missing session');
  fee.value = '52';
  fee.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.match(document.getElementById('selected-fee-amount').textContent, /Registration.*100,000/);
  assert.match(document.getElementById('fee-billing-basis').textContent, /Not charged per semester/);
  assert.match(document.getElementById('invoice-period-summary').textContent, /No Finance Session is linked/);
  const submit = new dom.window.Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(submit);
  assert.equal(submit.defaultPrevented, true, 'Finance cannot submit an invoice without a session for the selected year');
  dom.window.close();
});
