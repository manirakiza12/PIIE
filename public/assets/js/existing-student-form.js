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
        const cohortYearData = document.getElementById('cohort-entry-year-map');
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
            financeFields.hidden = !financeToggle.checked;
            financeFields.querySelectorAll(':scope > .row input, :scope > .row select, :scope > .row textarea').forEach(function (field) {
                field.disabled = !financeToggle.checked;
            });

            const priorAmount = form.querySelector('#prior_paid_amount');
            const priorDetails = form.querySelector('#prior_payment_details');
            if (priorAmount && priorDetails) {
                priorDetails.hidden = !financeToggle.checked || Number(priorAmount.value || 0) <= 0;
                priorDetails.querySelectorAll('input, select, textarea').forEach(function (field) {
                    field.disabled = priorDetails.hidden;
                    field.required = !priorDetails.hidden;
                });
            }
            setDetails(form.querySelector('#record_award'), form.querySelector('#award_details'));
            setDetails(form.querySelector('#record_study_waiver'), form.querySelector('#study_waiver_details'));
        }

        function refreshFeeSessions() {
            if (!academicYear || !feeSession) return;
            const selectedYearId = academicYear.value;
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
                showMessage(form.querySelector('#fee-session-context-message'), 'The selected fee session was cleared because it belongs to a different academic year. Choose a session for the year selected above.');
            }
            if (!selectedYearId) {
                feeSession.value = '';
                feeSession.disabled = true;
                showMessage(form.querySelector('#fee-session-context-message'), 'Choose an academic year above before selecting a fee session.');
            } else {
                feeSession.disabled = !financeToggle?.checked || visibleCount === 0;
                if (visibleCount === 0) {
                    feeSession.value = '';
                    showMessage(form.querySelector('#fee-session-context-message'), 'No Finance sessions are linked to this academic year. Ask an administrator to map the correct session before setting up a fee.');
                } else if (!selectedOption || !selectedOption.value || !selectedOption.hidden) {
                    const message = form.querySelector('#fee-session-context-message');
                    if (message) message.hidden = true;
                }
            }
            refreshFeeStructures();
        }

        function refreshFeeStructures() {
            if (!feeStructure) return;
            const programmeId = programme ? programme.value : '';
            const sessionId = feeSession ? feeSession.value : '';
            let selectedWasHidden = false;
            Array.from(feeStructure.options).forEach(function (option, index) {
                if (index === 0) return;
                const programmeMatches = !option.dataset.programmeId || option.dataset.programmeId === programmeId;
                const sessionMatches = !option.dataset.sessionId || option.dataset.sessionId === sessionId;
                option.hidden = !programmeMatches || !sessionMatches;
                if (option.value === feeStructure.value && option.hidden) selectedWasHidden = true;
            });
            if (selectedWasHidden) {
                feeStructure.value = '';
                showMessage(form.querySelector('#fee-structure-context-message'), 'The previous fee type does not match the selected programme and fee session. Choose a matching fee type.');
            } else if (feeStructure.value) {
                const message = form.querySelector('#fee-structure-context-message');
                if (message) message.hidden = true;
            }
        }

        function refreshCohortContext() {
            if (!cohortContext) return;
            if (programme?.value && intake?.value) {
                const years = cohortEntryYears[programme.value + '|' + intake.value] || [];
                if (years.length) {
                    cohortContext.textContent = 'Configured programme cohort entry year' + (years.length > 1 ? 's' : '') + ': ' + years.join(', ') + '. This is cohort context only; the form saves the profile and does not create a curriculum placement or enrolment.';
                } else {
                    cohortContext.textContent = 'No active or completed programme cohort connects this programme and intake to an entry academic year. The programme, intake, and Year of Study remain profile details; create an approved placement after adding the student.';
                }
            } else {
                cohortContext.textContent = 'Programme cohorts can connect a programme and intake to an entry academic year. This form saves profile details only; use Student Placement after creation to assign an approved curriculum and academic year.';
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

        syncFinanceVisibility();
        showPasswordField();
        refreshCohortContext();
        refreshFeeSessions();

        if (window.jQuery && window.jQuery.fn.daterangepicker && window.moment) {
            window.jQuery('#birthday').daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                minYear: 1901,
                maxYear: parseInt(window.moment().format('YYYY'), 10),
            });
        }
    }

    function init() {
        initializeExistingStudentForm(document.getElementById('existing-student-form'));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('piie:content-loaded', init);
    window.initializeExistingStudentForm = initializeExistingStudentForm;
}());
