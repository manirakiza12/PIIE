(function () {
    'use strict';

    function initializeExistingStudentForm(form) {
        if (!form || form.dataset.existingStudentReady === 'true') return;
        form.dataset.existingStudentReady = 'true';

        const financeToggle = form.querySelector('#record_fees');
        const financeFields = form.querySelector('#existing_student_fee_fields');
        const academicYear = form.querySelector('#fee_academic_year_id');
        const feeSession = form.querySelector('#fee_session_id');
        const feeStructure = form.querySelector('#fee_structure_id');
        const programme = form.querySelector('#programme_id');
        const intake = form.querySelector('#intake_session_id');
        const yearOfStudy = form.querySelector('#year_of_study');
        const cohortContext = form.querySelector('#cohort-context');
        const cohortYearData = form.querySelector('#cohort-entry-year-map');
        let cohortEntryYears = {};

        try {
            cohortEntryYears = cohortYearData ? JSON.parse(cohortYearData.textContent) : {};
        } catch (error) {
            cohortEntryYears = {};
        }

        function showMessage(field, message) {
            if (!field) return;
            field.textContent = message;
            field.hidden = false;
        }

        function hideMessage(field) {
            if (field) field.hidden = true;
        }

        function setDetails(toggle, details) {
            if (!toggle || !details) return;
            details.hidden = !financeToggle.checked || !toggle.checked;
            details.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = details.hidden;
                field.required = !details.hidden;
            });
        }

        function syncFinanceVisibility() {
            if (!financeToggle || !financeFields) return;
            const enabled = financeToggle.checked;
            financeFields.hidden = !enabled;
            financeToggle.setAttribute('aria-expanded', enabled ? 'true' : 'false');

            financeFields.querySelectorAll('input, select, textarea').forEach(function (field) {
                const dependentDetails = field.closest('#prior_payment_details, #award_details, #study_waiver_details');
                field.disabled = !enabled || Boolean(dependentDetails && dependentDetails.hidden);
                field.required = enabled && ['fee_academic_year_id', 'fee_structure_id', 'actual_fee_amount'].includes(field.id);
            });

            const priorAmount = form.querySelector('#prior_paid_amount');
            const priorDetails = form.querySelector('#prior_payment_details');
            if (priorAmount && priorDetails) {
                priorDetails.hidden = !enabled || Number(priorAmount.value || 0) <= 0;
                priorDetails.querySelectorAll('input, select, textarea').forEach(function (field) {
                    field.disabled = priorDetails.hidden;
                    field.required = !priorDetails.hidden;
                });
            }
            setDetails(form.querySelector('#record_award'), form.querySelector('#award_details'));
            setDetails(form.querySelector('#record_study_waiver'), form.querySelector('#study_waiver_details'));
        }

        function refreshFeeStructures() {
            if (!feeStructure) return;
            const programmeId = programme ? programme.value : '';
            const sessionId = feeSession ? feeSession.value : '';
            let selectedWasHidden = false;
            let visibleCount = 0;
            Array.from(feeStructure.options).forEach(function (option, index) {
                if (index === 0) return;
                const programmeMatches = !option.dataset.programmeId || option.dataset.programmeId === programmeId;
                const sessionMatches = !option.dataset.sessionId || option.dataset.sessionId === sessionId;
                option.hidden = !programmeMatches || !sessionMatches;
                if (!option.hidden) visibleCount += 1;
                if (option.value === feeStructure.value && option.hidden) selectedWasHidden = true;
            });
            if (selectedWasHidden) {
                feeStructure.value = '';
                showMessage(form.querySelector('#fee-structure-context-message'), 'The fee type no longer matches this programme and session. Choose a matching fee type.');
            } else if (feeStructure.value || visibleCount > 0) {
                hideMessage(form.querySelector('#fee-structure-context-message'));
            }
            feeStructure.disabled = !financeToggle?.checked || !sessionId;
            feeStructure.required = Boolean(financeToggle?.checked && sessionId);
        }

        function refreshFeeSessions() {
            if (!academicYear || !feeSession) return;
            const selectedYearId = academicYear.value;
            const financeEnabled = Boolean(financeToggle?.checked);
            let visibleCount = 0;
            Array.from(feeSession.options).forEach(function (option, index) {
                if (index === 0) return;
                const visible = selectedYearId !== '' && option.dataset.academicYearId === selectedYearId;
                option.hidden = !visible;
                if (visible) visibleCount += 1;
            });

            const selectedOption = feeSession.selectedOptions[0];
            if (selectedOption && selectedOption.value && selectedOption.hidden) {
                feeSession.value = '';
                showMessage(form.querySelector('#fee-session-context-message'), 'The previous session belongs to another year. Choose a session for the selected Academic Year.');
            }

            const message = form.querySelector('#fee-session-context-message');
            if (!selectedYearId) {
                feeSession.value = '';
                showMessage(message, 'Select an Academic Year to load its fee sessions.');
            } else if (visibleCount === 0) {
                feeSession.value = '';
                showMessage(message, 'No fee sessions are linked to this Academic Year. Ask an administrator to map a session before creating an invoice.');
            } else if (!selectedOption || !selectedOption.value || !selectedOption.hidden) {
                hideMessage(message);
            }

            feeSession.disabled = !financeEnabled || !selectedYearId || visibleCount === 0;
            feeSession.required = financeEnabled && visibleCount > 0;
            refreshFeeStructures();
        }

        function refreshCohortContext() {
            if (!cohortContext) return;
            if (programme?.value && intake?.value) {
                const years = cohortEntryYears[programme.value + '|' + intake.value] || [];
                cohortContext.textContent = years.length
                    ? 'Configured cohort entry year' + (years.length > 1 ? 's' : '') + ': ' + years.join(', ') + '. Placement is assigned separately.'
                    : 'No configured cohort links this programme and intake to an entry year. Placement is assigned separately.';
            } else {
                cohortContext.textContent = 'Programme and intake are profile details; assign an approved curriculum later in Student Placement.';
            }
        }

        function showPasswordField() {
            const manual = form.querySelector('input[name="password_option"][value="manual"]');
            const wrap = form.querySelector('#password_manual_wrap');
            const input = form.querySelector('#password');
            if (!wrap || !input) return;
            wrap.hidden = !manual?.checked;
            input.required = !wrap.hidden;
        }

        financeToggle?.addEventListener('change', function () {
            syncFinanceVisibility();
            refreshFeeSessions();
        });
        form.querySelector('#prior_paid_amount')?.addEventListener('input', syncFinanceVisibility);
        form.querySelector('#record_award')?.addEventListener('change', syncFinanceVisibility);
        form.querySelector('#record_study_waiver')?.addEventListener('change', syncFinanceVisibility);
        form.querySelectorAll('input[name="password_option"]').forEach(function (radio) {
            radio.addEventListener('change', showPasswordField);
        });
        academicYear?.addEventListener('change', refreshFeeSessions);
        feeSession?.addEventListener('change', refreshFeeStructures);
        programme?.addEventListener('change', function () {
            refreshCohortContext();
            refreshFeeStructures();
        });
        intake?.addEventListener('change', refreshCohortContext);
        yearOfStudy?.addEventListener('change', refreshCohortContext);

        form.addEventListener('submit', function (event) {
            if (financeToggle?.checked && (!academicYear?.value || !feeSession?.value || feeSession.disabled)) {
                event.preventDefault();
                if (!academicYear?.value) {
                    showMessage(form.querySelector('#fee-session-context-message'), 'Select an Academic Year before submitting Finance setup.');
                    academicYear?.focus();
                } else {
                    showMessage(form.querySelector('#fee-session-context-message'), 'An invoice needs a fee session linked to the selected Academic Year.');
                    feeSession?.focus();
                }
            }
        });

        syncFinanceVisibility();
        showPasswordField();
        refreshCohortContext();
        refreshFeeSessions();

        if (window.jQuery && window.jQuery.fn.daterangepicker && window.moment) {
            window.jQuery(form.querySelector('#birthday')).daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                minYear: 1901,
                maxYear: parseInt(window.moment().format('YYYY'), 10),
            });
        }
    }

    function initializeForms(root) {
        if (!root) return;
        if (root.matches && root.matches('#existing-student-form')) initializeExistingStudentForm(root);
        root.querySelectorAll?.('#existing-student-form').forEach(initializeExistingStudentForm);
    }

    function init() {
        initializeForms(document);
        if (!document.body || !window.MutationObserver || document.body.dataset.existingStudentObserver === 'true') return;
        document.body.dataset.existingStudentObserver = 'true';
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) initializeForms(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('piie:content-loaded', init);
    window.initializeExistingStudentForm = initializeExistingStudentForm;
}());
