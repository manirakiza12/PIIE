(function () {
  'use strict';
  const editable = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea';

  function paginate(form) {
    if (form.hasAttribute('data-workspace-continuous')) return;
    if (form.dataset.workspaceSteps || !form.closest('.workspace-surface, .workspace-page')) return;
    const candidates = [form, ...form.querySelectorAll('.form-row, .eForm-layouts, .row')].map(function (container) {
      return {container:container, fields:Array.from(container.children).filter(function (child) {
        return child.matches('div, fieldset') && child.querySelector(editable) &&
          !child.querySelector('button[type="submit"], input[type="submit"]') &&
          !child.hidden && !child.classList.contains('d-none');
      })};
    }).sort(function (a, b) { return b.fields.length - a.fields.length; });
    const selected = candidates[0];
    if (!selected || selected.fields.length < (innerWidth < 576 ? 5 : 8)) return;
    form.dataset.workspaceSteps = 'true';
    const bar = document.createElement('nav');
    bar.className = 'workspace-steps';
    bar.setAttribute('aria-label', 'Form steps');
    const previous = document.createElement('button');
    previous.type = 'button'; previous.className = 'btn btn-outline-secondary'; previous.textContent = 'Previous';
    const status = document.createElement('span');
    status.setAttribute('aria-live', 'polite');
    const next = document.createElement('button');
    next.type = 'button'; next.className = 'btn btn-primary'; next.textContent = 'Next';
    bar.append(previous, status, next);
    selected.container.before(bar);
    let page = 0;
    function capacity() { return innerWidth < 576 ? 3 : innerWidth < 992 ? 4 : 6; }
    function groups() {
      const result = [];
      let group = [], weight = 0;
      selected.fields.forEach(function (field) {
        const fieldWeight = Math.max(1, Math.min(capacity(), field.querySelectorAll(editable).length));
        if (group.length && weight + fieldWeight > capacity()) { result.push(group); group = []; weight = 0; }
        group.push(field); weight += fieldWeight;
      });
      if (group.length) result.push(group);
      return result;
    }
    function show(index) {
      const pages = groups();
      const total = pages.length;
      page = Math.max(0, Math.min(index, total - 1));
      selected.fields.forEach(function (field) {
        field.classList.toggle('workspace-step-hidden', !pages[page].includes(field));
      });
      previous.disabled = page === 0;
      next.disabled = page === total - 1;
      status.textContent = 'Step ' + (page + 1) + ' of ' + total;
    }
    previous.addEventListener('click', function () { show(page - 1); });
    next.addEventListener('click', function () {
      const visible = selected.fields.filter(function (field) { return !field.classList.contains('workspace-step-hidden'); });
      const invalid = visible.flatMap(function (field) { return Array.from(field.querySelectorAll(editable)); })
        .find(function (field) { return field.willValidate && !field.validity.valid; });
      if (invalid) { invalid.reportValidity(); return; }
      show(page + 1);
    });
    let pendingInvalid = false;
    form.addEventListener('invalid', function (event) {
      const index = selected.fields.findIndex(function (field) { return field.contains(event.target); });
      if (index < 0 || !selected.fields[index].classList.contains('workspace-step-hidden')) return;
      event.preventDefault();
      if (pendingInvalid) return;
      pendingInvalid = true;
      const control = event.target;
      setTimeout(function () {
        show(groups().findIndex(function (group) { return group.includes(selected.fields[index]); }));
        pendingInvalid = false;
        control.reportValidity();
      }, 0);
    }, true);
    form.workspaceResize = function () { show(page); };
    show(0);
  }

  function prepare(root) {
    root.querySelectorAll('form').forEach(function (form) {
      if (form.querySelectorAll(editable).length < 3) return;
      const containers = [form, ...form.querySelectorAll('.form-row, .eForm-layouts')];
      containers.forEach(function (container) {
        const children = Array.from(container.children);
        const fields = children.filter(function (child) {
          return child.matches('div') && !child.matches('.row, [class*="col-"], .tab-content') &&
            child.querySelectorAll(editable).length === 1 && !child.querySelector('textarea, .note-editor, button[type="submit"], input[type="submit"]');
        });
        if (fields.length >= 3) {
          container.classList.add('workspace-form-grid');
          fields.forEach(function (field) { field.classList.add('workspace-field'); });
        }
        children.forEach(function (child) {
          if (child.matches('div') && child.querySelector('button[type="submit"], input[type="submit"]') &&
              !child.querySelector(editable)) child.classList.add('workspace-actions');
        });
      });
      paginate(form);
    });
    prepareTables(root);
  }

  function prepareTables(root) {
    root.querySelectorAll('table').forEach(function (table) {
      if (table.closest('.table-responsive, .note-editor, .dataTables_scrollBody')) return;
      const wrapper = document.createElement('div');
      wrapper.className = 'table-responsive';
      table.before(wrapper);
      wrapper.appendChild(table);
    });
  }

  function init() {
    window.addEventListener('resize', function () {
      document.querySelectorAll('form[data-workspace-steps]').forEach(function (form) { form.workspaceResize(); });
    });
    document.querySelectorAll('.modal:not(.workspace-surface)').forEach(function (modal) {
      if (modal.hasAttribute('data-compact-dialog')) return;
      if (modal.id === 'large-scrollable-modal' || modal.querySelector('form ' + editable)) modal.classList.add('workspace-surface');
    });
    const surfaces = document.querySelectorAll('.workspace-surface');
    function sync() {
      document.body.classList.toggle('workspace-open', !!document.querySelector('.workspace-surface.show'));
    }
    surfaces.forEach(function (surface) {
      ['show.bs.offcanvas', 'show.bs.modal'].forEach(function (event) {
        surface.addEventListener(event, function () { document.body.classList.add('workspace-open'); });
      });
      ['hidden.bs.offcanvas', 'hidden.bs.modal'].forEach(function (event) { surface.addEventListener(event, sync); });
      new MutationObserver(function () { prepare(surface); }).observe(surface, {childList:true, subtree:true});
      prepare(surface);
    });
    // Dedicated create/edit pages use the whole width too; listing filters do not.
    const content = document.querySelector('#page-print-area, .main_content, .ap-main');
    if (content) prepareTables(content);
    const formRoute = /\/(?:[^/]*[_-])?(create|add|edit|single|profile|application|wizard|password|settings|documents)(?:[_-][^/]*)?(\/|$)/i.test(location.pathname);
    if (content && formRoute && content.querySelector('form') && !content.querySelector('.dataTable')) {
      document.body.classList.add('workspace-page');
      prepare(content);
      const back = document.createElement('a');
      const role = location.pathname.split('/')[1];
      back.className = 'workspace-back btn btn-outline-secondary';
      back.textContent = 'Back to workspace';
      back.href = ['admin', 'superadmin', 'teacher', 'student', 'parent', 'accountant', 'bursar', 'librarian', 'warden', 'staff', 'applicant'].includes(role) ? '/' + role + '/dashboard' : '/';
      if (document.referrer) {
        const referrer = new URL(document.referrer);
        if (referrer.origin === location.origin && referrer.pathname !== location.pathname) back.href = referrer.href;
      }
      content.prepend(back);
    }
  }
  window.prepareWorkspaceUI = prepare;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
