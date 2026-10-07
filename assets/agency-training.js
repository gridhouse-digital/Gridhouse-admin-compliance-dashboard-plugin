(function () {
  'use strict';
  if (!window.ghcaAcd) return;
  var requests = new WeakMap();

  function period(panel) {
    var scope = panel.closest('[data-ghca-admin-view="subpage"]');
    return {
      mode: scope.querySelector('[data-ghca-audit-mode]').value,
      start: scope.querySelector('[data-ghca-audit-start]').value,
      end: scope.querySelector('[data-ghca-audit-end]').value
    };
  }

  async function load(panel, form) {
    var dates = period(panel);
    var body = panel.querySelector('[data-ghca-agency-body]');
    var status = panel.querySelector('[data-ghca-agency-status]');
    var reload = panel.querySelector('[data-ghca-agency-reload]');
    var previous = requests.get(panel);
    // A save is never aborted: the server may already have persisted it.
    if (previous && previous.saving) return;
    if (previous) previous.controller.abort();
    var request = { controller: new AbortController(), saving: !!form };
    requests.set(panel, request);
    if (dates.mode === 'custom' && (!dates.start || !dates.end || dates.end < dates.start)) {
      requests.delete(panel);
      reload.disabled = false;
      panel.setAttribute('aria-busy', 'false');
      body.replaceChildren();
      status.textContent = 'Choose valid start and end dates in Packet Generator, then load confirmations.';
      return;
    }
    var params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
    params.set('action', 'ghca_acd_agency_training');
    params.set('nonce', window.ghcaAcd.nonce);
    params.set('user_id', panel.dataset.ghcaAgency);
    params.set('operation', form ? form.dataset.ghcaAgencyForm : 'load');
    Object.keys(dates).forEach(function (key) { params.set(key, dates[key]); });
    status.textContent = form ? 'Saving…' : 'Loading saved records…';
    panel.setAttribute('aria-busy', 'true');
    reload.disabled = true;
    if (!form) body.replaceChildren();
    body.querySelectorAll('button, input, select').forEach(function (control) { control.disabled = true; });
    try {
      var response = await fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST', credentials: 'same-origin', body: params, signal: request.controller.signal
      });
      var json = await response.json();
      if (!panel.isConnected || requests.get(panel) !== request) return;
      if (JSON.stringify(dates) !== JSON.stringify(period(panel))) {
        requests.delete(panel);
        await load(panel);
        return;
      }
      if (!response.ok || !json.success || !json.data || typeof json.data.html !== 'string') {
        throw new Error(json.data && json.data.message || 'Could not load/save these records. Refresh before retrying.');
      }
      body.innerHTML = json.data.html;
      status.textContent = json.data.message;
      if (form) {
        status.tabIndex = -1;
        status.focus();
      }
    } catch (error) {
      if (error.name !== 'AbortError' && panel.isConnected && requests.get(panel) === request) {
        status.textContent = error.message || 'Connection error. Refresh saved records before retrying.';
        // Keep the attempted values visible, but require a fresh revision before resaving.
      }
    } finally {
      if (requests.get(panel) === request) {
        requests.delete(panel);
        reload.disabled = false;
        panel.setAttribute('aria-busy', 'false');
      }
    }
  }

  document.addEventListener('click', function (event) {
    var refresh = event.target.closest('[data-ghca-agency-reload]');
    if (refresh) { load(refresh.closest('[data-ghca-agency]')); return; }
    var edit = event.target.closest('[data-ghca-edit-records]');
    if (edit) {
      var panel = edit.closest('.ghca-acd__drawer-panel--administration').querySelector('[data-ghca-agency]');
      if (panel) load(panel);
    }
  });
  document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-ghca-audit-mode], [data-ghca-audit-start], [data-ghca-audit-end]')) return;
    var panel = event.target.closest('[data-ghca-admin-view="subpage"]').querySelector('[data-ghca-agency]');
    if (panel) load(panel);
  });
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-ghca-agency-form]');
    if (!form) return;
    event.preventDefault();
    load(form.closest('[data-ghca-agency]'), form);
  });
})();
