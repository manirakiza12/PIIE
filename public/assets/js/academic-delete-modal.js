(function () {
  'use strict';
  function init() {
    const modal = document.getElementById('academic-delete-modal');
    if (!modal || modal.dataset.deleteReady) return;
    modal.dataset.deleteReady = 'true';
    // Keep the dialog outside responsive tables, cards and transformed workspaces.
    document.body.appendChild(modal);
    const form = modal.querySelector('[data-delete-form]');
    const input = form.elements.confirm_deletion;
    const submit = modal.querySelector('[data-delete-submit]');
    const error = modal.querySelector('[data-delete-error]');
    let opener = null;
    let reference = '';
    let submitting = false;
    function confirmed() {
      return opener && ['DELETE', reference].includes(input.value.trim());
    }
    modal.addEventListener('show.bs.modal', function (event) {
      const trigger = event.relatedTarget;
      if (!trigger || !trigger.matches('[data-delete-action]')) { event.preventDefault(); return; }
      opener = trigger;
      reference = trigger.dataset.deleteReference;
      submitting = false;
      form.reset();
      form.action = trigger.dataset.deleteAction;
      form.querySelector('[name="_delete_target"]').value = trigger.dataset.deleteAction;
      modal.querySelector('#academic-delete-title').textContent = trigger.dataset.deleteTitle;
      modal.querySelector('#academic-delete-identity').textContent = trigger.dataset.deleteIdentity;
      modal.querySelector('[data-delete-removes]').textContent = trigger.dataset.deleteRemoves;
      modal.querySelector('[data-delete-keeps]').textContent = trigger.dataset.deleteKeeps;
      modal.querySelector('[data-delete-reference]').textContent = reference;
      if (modal.dataset.reopenTarget !== trigger.dataset.deleteAction) error.hidden = true;
      modal.dataset.reopenTarget = '';
      submit.disabled = true;
    });
    modal.addEventListener('shown.bs.modal', function () {
      // Start on the safe action; Bootstrap supplies the focus trap and Escape handling.
      if (!error.hidden) error.focus();
      else modal.querySelector('[data-delete-cancel]').focus();
    });
    input.addEventListener('input', function () { submit.disabled = submitting || !confirmed(); });
    form.addEventListener('submit', function (event) {
      if (submitting || !confirmed() || !form.checkValidity()) { event.preventDefault(); return; }
      submitting = true;
      submit.disabled = true;
    });
    modal.addEventListener('hidden.bs.modal', function () {
      form.reset();
      form.removeAttribute('action');
      submit.disabled = true;
      error.hidden = true;
      reference = '';
      submitting = false;
      // Also restore focus when a server validation error reopened the dialog.
      if (opener && opener.isConnected) opener.focus();
      opener = null;
    });
    const retry = Array.from(document.querySelectorAll('[data-delete-action]'))
      .find(function (trigger) { return trigger.dataset.deleteAction === modal.dataset.reopenTarget; });
    if (retry && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).show(retry);
    const success = document.querySelector('[data-delete-success]');
    if (success && window.toastr) {
      window.toastr.success(success.textContent, 'Success', {escapeHtml: true, closeButton: true, progressBar: true});
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
