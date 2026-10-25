(function () {
    'use strict';
    const form = document.getElementById('student_invoice_setup_form');
    if (!form || form.dataset.invoiceSetupReady === 'true') return;
    form.dataset.invoiceSetupReady = 'true';

    const year = document.getElementById('fee_academic_year_id');
    const fee = document.getElementById('fee_structure_id');
    const session = document.getElementById('fee_session_id');
    const amount = document.getElementById('received_payment_amount');
    const summary = document.getElementById('fee_summary');
    const receipt = document.getElementById('receipt_details');
    const balance = document.getElementById('remaining_balance');
    const currency = form.dataset.currency || '';
    const money = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function filterStructures() {
        const selectedSession = session ? session.value : '';
        const selectedOption = fee && fee.selectedOptions[0];
        if (!fee) return;
        [...fee.options].forEach((option, index) => {
            if (index === 0) return;
            const incompatible = Boolean(option.dataset.session && option.dataset.session !== selectedSession);
            option.hidden = incompatible;
            option.disabled = incompatible;
        });
        if (selectedOption && selectedOption.disabled) fee.value = '';
    }

    function update() {
        filterStructures();
        const option = fee && fee.selectedOptions[0];
        const configured = option && option.dataset.amount ? Number(option.dataset.amount) : null;
        if (summary) {
            summary.textContent = option && option.value
                ? `${option.dataset.type} · ${option.dataset.name}: ${currency} ${money.format(configured)} ${option.dataset.basis.toLowerCase()}`
                : 'Choose a fee type to see its configured amount and billing basis.';
        }
        const received = Number(amount ? amount.value || 0 : 0);
        if (receipt) {
            receipt.hidden = received <= 0;
            receipt.querySelectorAll('input,select,textarea').forEach((field) => {
                field.disabled = received <= 0;
                field.required = received > 0;
            });
        }
        if (balance) balance.textContent = configured === null
            ? '—' : `${currency} ${money.format(Math.max(0, configured - received))}`;
    }

    if (year) year.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('fee_academic_year_id', year.value);
        window.location.assign(url.toString());
    });
    if (session) session.addEventListener('change', update);
    if (fee) fee.addEventListener('change', update);
    if (amount) amount.addEventListener('input', update);
    update();
})();
