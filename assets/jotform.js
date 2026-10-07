(function () {
  'use strict';
  if (!window.ghcaAcdJotform) return;

  function bindCatalogOverride(select) {
    var form = select.closest('form');
    var reason = form && form.querySelector('[data-ghca-override-reason]');
    var warning = form && form.querySelector('[data-ghca-catalog-warning]');
    if (!form || !reason || !warning) return;
    function update() {
      var mismatch = !select.value || String(select.value) !== String(select.dataset.matchCatalogId || '');
      reason.required = mismatch;
      warning.hidden = !mismatch;
    }
    select.addEventListener('change', update);
    update();
  }
  document.querySelectorAll('[data-ghca-catalog-select]').forEach(bindCatalogOverride);

  function request(data) {
    var body = new URLSearchParams(data);
    body.set('nonce', window.ghcaAcdJotform.nonce);
    return fetch(window.ghcaAcdJotform.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function (response) { return response.json(); });
  }

  var modalRelease = null;
  var modalLauncher = null;

  function focusTrap(container, initial) {
    if (!container) return function () {};
    var previous = document.activeElement;
    var selector = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])';
    function items() {
      return Array.prototype.slice.call(container.querySelectorAll(selector)).filter(function (item) {
        return item.offsetWidth > 0 || item.offsetHeight > 0 || item === document.activeElement;
      });
    }
    function onKey(event) {
      if (event.key !== 'Tab') return;
      var list = items();
      if (!list.length) return;
      if (event.shiftKey && document.activeElement === list[0]) { event.preventDefault(); list[list.length - 1].focus(); }
      else if (!event.shiftKey && document.activeElement === list[list.length - 1]) { event.preventDefault(); list[0].focus(); }
      else if (!container.contains(document.activeElement)) { event.preventDefault(); list[0].focus(); }
    }
    container.addEventListener('keydown', onKey);
    var target = initial || items()[0] || container;
    try { target.focus({preventScroll: true}); } catch (err) { target.focus(); }
    return function () {
      container.removeEventListener('keydown', onKey);
      if (previous && typeof previous.focus === 'function') {
        try { previous.focus({preventScroll: true}); } catch (err) { previous.focus(); }
      }
    };
  }

  function copyBrandVariables(modal) {
    var source = document.querySelector('.ghca-acd');
    if (!source || !window.getComputedStyle) return;
    var computed = window.getComputedStyle(source);
    ['--ghca-primary', '--ghca-primary-dark', '--ghca-primary-soft', '--ghca-secondary', '--ghca-accent'].forEach(function (name) {
      var value = computed.getPropertyValue(name).trim();
      if (value) modal.style.setProperty(name, value);
    });
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    if (window.ghcaAcdOverlay) window.ghcaAcdOverlay.close(modal);
    if (modalRelease) { modalRelease(); modalRelease = null; }
    modalLauncher = null;
  }

  function openModal(modal, launcher) {
    if (!modal) return;
    modalLauncher = launcher || modalLauncher;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    copyBrandVariables(modal);
    if (window.ghcaAcdOverlay) window.ghcaAcdOverlay.open(modal, modalLauncher, function () { closeModal(modal); });
    var panel = modal.querySelector('.ghca-jotform-modal__panel');
    var closeButton = modal.querySelector('[data-ghca-doc-close]');
    if (modalRelease) modalRelease();
    modalRelease = focusTrap(panel, closeButton);
  }

  function mintDocumentUrl(documentId, access, trainingId) {
    var requestData = {action: 'ghca_acd_jotform_document_token', document_id: documentId, access: access};
    if (trainingId) requestData.training_id = trainingId;
    return request(requestData).then(function (payload) {
      if (!payload.success || !payload.data.url) throw new Error('token');
      return payload.data.url;
    });
  }

  function openDocument(documentId, access, trainingId, title, trigger) {
    var wanted = access || 'preview';
    if (wanted === 'download' || typeof window.ghcaAcdOpenCertificateModal !== 'function') {
      mintDocumentUrl(documentId, wanted, trainingId)
        .then(function (url) {
          if (wanted === 'download') { window.location.assign(url); }
          else { window.open(url, '_blank', 'noopener,noreferrer'); }
        })
        .catch(function () { window.alert(window.ghcaAcdJotform.error); });
      return;
    }
    Promise.all([mintDocumentUrl(documentId, 'preview', trainingId), mintDocumentUrl(documentId, 'download', trainingId)])
      .then(function (urls) { window.ghcaAcdOpenCertificateModal(urls[0], title || '', urls[1], trigger); })
      .catch(function () { window.alert(window.ghcaAcdJotform.error); });
  }

  function ensureModal() {
    var modal = document.getElementById('ghca-jotform-modal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'ghca-jotform-modal';
    modal.className = 'ghca-jotform-modal ghca-acd__overlay ghca-acd__overlay--documents';
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
   modal.innerHTML = '<div class="ghca-jotform-modal__backdrop" data-ghca-doc-close></div><section class="ghca-jotform-modal__panel ghca-acd__v2-modal ghca-acd__v2-modal--wide" role="dialog" aria-modal="true" aria-labelledby="ghca-jotform-modal-title"><header class="ghca-acd__v2-modal-header"><div class="ghca-acd__v2-modal-heading"><h2 id="ghca-jotform-modal-title">All employee documents</h2><p class="ghca-jotform-modal__intro" data-ghca-doc-intro>Protected employee-submitted and external-training records.</p></div><button type="button" data-ghca-doc-close aria-label="Close employee documents">×</button></header><div class="ghca-jotform-modal__body ghca-acd__v2-modal-body"><form class="ghca-jotform-modal__search ghca-acd__v2-modal-tools" data-ghca-doc-search-form><input type="text" placeholder="Search documents" aria-label="Search employee documents" data-ghca-doc-search><button type="submit" class="ghca-acd__sr-only">Search</button></form><div class="ghca-jotform-modal__result-line ghca-acd__v2-result-line" data-ghca-doc-result-line><span data-ghca-doc-count>Loading documents…</span><span>Newest first</span></div><div data-ghca-doc-results></div></div><footer class="ghca-acd__v2-modal-footer" data-ghca-doc-pages></footer></section>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (event) {
      if (event.target.closest('[data-ghca-doc-close]')) closeModal(modal);
      var open = event.target.closest('[data-ghca-document-open]');
      if (open) {
        var row = open.closest('li');
        var strong = row ? row.querySelector('strong') : null;
        openDocument(open.getAttribute('data-ghca-document-open'), open.getAttribute('data-action'), open.getAttribute('data-training-id'), strong ? strong.textContent : '', open);
      }
      var page = event.target.closest('[data-ghca-doc-page]');
      if (page) loadDocuments(modal, parseInt(page.getAttribute('data-ghca-doc-page'), 10));
   });
   modal.querySelector('[data-ghca-doc-search-form]').addEventListener('submit', function (event) {
     event.preventDefault();
     loadDocuments(modal, 1);
   });
    return modal;
  }

  function loadDocuments(modal, page) {
    var results = modal.querySelector('[data-ghca-doc-results]');
 var search = modal.querySelector('[data-ghca-doc-search]').value || '';
 var count = modal.querySelector('[data-ghca-doc-count]');
    results.textContent = 'Loading…';
    request({action: 'ghca_acd_jotform_documents', employee_id: modal.dataset.employeeId, page: page || 1, search: search})
      .then(function (payload) {
        if (!payload.success) throw new Error('list');
        var items = payload.data.items || [];
     results.innerHTML = items.length ? '<ul class="ghca-jotform-list ghca-jotform-list--v2">' + items.map(function (item) {
       var id = parseInt(item.id, 10) || 0;
       var approved = String(item.status || '').toLowerCase() === 'approved';
       var attribute = item.kind === 'manual' ? 'data-ghca-manual-open' : 'data-ghca-document-open';
       var actions = item.viewable === false ? '' : '<button type="button" ' + attribute + '="' + id + '" data-action="preview">Preview</button><button type="button" ' + attribute + '="' + id + '" data-action="download">Download</button>';
       var origin = item.kind === 'manual' ? ' <span>Manual entry</span>' : '';
       return '<li><span class="ghca-jotform-list__file" aria-hidden="true">' + escapeHtml(item.type) + '</span><span class="ghca-jotform-list__content"><strong>' + escapeHtml(item.name) + '</strong><small class="ghca-jotform-list__meta"><span>' + escapeHtml(item.form) + '</span><span>' + escapeHtml(item.date) + '</span>' + origin + '</small></span><span class="ghca-jotform-list__status' + (approved ? ' is-approved' : '') + '">' + escapeHtml(item.status) + '</span><span class="ghca-jotform-list__actions">' + actions + '</span></li>';
     }).join('') + '</ul>' : '<p>' + escapeHtml(window.ghcaAcdJotform.empty) + '</p>';
     var total = parseInt(payload.data.total, 10);
     if (!Number.isFinite(total)) total = items.length;
     count.textContent = total === 1 ? 'Showing 1 document' : 'Showing ' + total + ' documents';
     var pages = modal.querySelector('[data-ghca-doc-pages]');
     var currentPage = parseInt(payload.data.page, 10) || 1;
     var pageCount = parseInt(payload.data.pages, 10) || 1;
     pages.innerHTML = '<div class="ghca-acd__v2-pager"><span>Page ' + currentPage + ' of ' + pageCount + '</span><span class="ghca-jotform-list__actions">' + (currentPage > 1 ? '<button type="button" data-ghca-doc-page="' + (currentPage - 1) + '">Previous</button>' : '<button type="button" disabled>Previous</button>') + (currentPage < pageCount ? '<button type="button" data-ghca-doc-page="' + (currentPage + 1) + '">Next</button>' : '<button type="button" disabled>Next</button>') + '<button type="button" data-ghca-doc-close>Close</button></span></div>';
   }).catch(function () { results.textContent = window.ghcaAcdJotform.error; count.textContent = 'Documents unavailable'; });
  }

  function escapeHtml(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }

  document.addEventListener('click', function (event) {
    var open = event.target.closest('[data-ghca-document-open]');
    if (open && !open.closest('#ghca-jotform-modal')) { openDocument(open.getAttribute('data-ghca-document-open'), open.getAttribute('data-action'), open.getAttribute('data-training-id')); return; }
    var all = event.target.closest('[data-ghca-documents-all]');
    if (all) {
      var modal = ensureModal();
   modal.dataset.employeeId = all.getAttribute('data-ghca-documents-all');
   modal.querySelector('[data-ghca-doc-search]').value = '';
   var employeeName = all.getAttribute('data-ghca-documents-name') || '';
   modal.querySelector('[data-ghca-doc-intro]').textContent = 'Protected employee-submitted and external-training records' + (employeeName ? ' for ' + employeeName : '') + '.';
      openModal(modal, all);
      loadDocuments(modal, 1);
    }
  });

  document.addEventListener('keydown', function (event) {
    var modal = document.getElementById('ghca-jotform-modal');
    if (event.key !== 'Escape' || !modal || modal.hidden) return;
    if (window.ghcaAcdOverlay && !window.ghcaAcdOverlay.isTop(modal)) return;
    event.preventDefault();
    closeModal(modal);
  });
}());
