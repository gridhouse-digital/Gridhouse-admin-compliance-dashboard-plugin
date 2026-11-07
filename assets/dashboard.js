(function () {
  'use strict';

  // One small overlay stack keeps nested drawer/popup interactions predictable.
  // Each feature still owns its existing endpoint and close lifecycle.
  var modalStack = [];

  function syncOverlayLock() {
    document.documentElement.classList.toggle('ghca-acd--overlay-open', modalStack.length > 0);
  }

  var ghcaAcdOverlay = {
    open: function (layer, launcher, close) {
      if (!layer) return;
      var existing = modalStack.filter(function (entry) { return entry.layer === layer; })[0];
      if (existing) {
        if (launcher) existing.launcher = launcher;
        if (close) existing.close = close;
      } else {
        modalStack.push({ layer: layer, launcher: launcher || null, close: close || function () {} });
      }
      syncOverlayLock();
    },
    close: function (layer) {
      modalStack = modalStack.filter(function (entry) { return entry.layer !== layer; });
      syncOverlayLock();
    },
    isTop: function (layer) {
      return !!layer && modalStack.length > 0 && modalStack[modalStack.length - 1].layer === layer;
    },
    top: function () {
      return modalStack.length ? modalStack[modalStack.length - 1] : null;
    }
  };
  window.ghcaAcdOverlay = ghcaAcdOverlay;
  // Exposed so the Audit tab's inline script can announce errors through the
  // shared toast (role=alert + aria-live) instead of a native alert().
  window.ghcaAcdToast = function (message, isError) { ghcaToast(message, isError); };
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !modalStack.length) return;
    var top = modalStack[modalStack.length - 1];
    if (!top || typeof top.close !== 'function') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    top.close();
  });

  initCertificateModal();

  if (typeof window.ghcaAcd === 'undefined') {
    return;
  }

  function closeDrawerMoreMenus(target) {
    document.querySelectorAll('.ghca-acd__drawer-more[open]').forEach(function (menu) {
      var selectedAction = target && target.closest && target.closest('.ghca-acd__drawer-more-menu button');
      if (!target || !menu.contains(target) || selectedAction) menu.removeAttribute('open');
    });
  }

  document.addEventListener('click', function (e) {
    closeDrawerMoreMenus(e.target);
  });

  // Escape closes an open More disclosure first; only a closed More lets Escape reach the drawer.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.ghca-acd__drawer-more[open]');
    if (!open) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    open.removeAttribute('open');
    var summary = open.querySelector('summary');
    if (summary && typeof summary.focus === 'function') {
      try { summary.focus({preventScroll: true}); } catch (err) { summary.focus(); }
    }
  }, true);

  // The redesigned drawer's More menu is the same disclosure with board classes. It has its own handler so the
  // legacy one above stays exactly as it was.
  function closeBoardMoreMenus(target) {
    document.querySelectorAll('[data-ghca-more][open]').forEach(function (menu) {
      var selectedAction = target && target.closest && target.closest('[data-ghca-more] .gh-menu-i');
      if (!target || !menu.contains(target) || selectedAction) menu.removeAttribute('open');
    });
  }

  document.addEventListener('click', function (e) {
    closeBoardMoreMenus(e.target);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('[data-ghca-more][open]');
    if (!open) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    open.removeAttribute('open');
    var summary = open.querySelector('summary');
    if (summary && typeof summary.focus === 'function') {
      try { summary.focus({preventScroll: true}); } catch (err) { summary.focus(); }
    }
  }, true);

  var tableConfigs = {
    employees: {
      fields: ['ghca_group', 'ghca_course', 'ghca_status', 'ghca_emp_search', 'ghca_emp_per', 'ghca_orderby', 'ghca_order'],
      checkboxFields: ['ghca_overdue'],
      pageField: 'ghca_emp_page',
      orderbyField: 'ghca_orderby',
      orderField: 'ghca_order'
    },
    inactive_employees: {
      fields: ['ghca_inactive_group', 'ghca_inactive_course', 'ghca_inactive_status', 'ghca_inactive_search', 'ghca_inactive_per', 'ghca_inactive_orderby', 'ghca_inactive_order'],
      checkboxFields: ['ghca_inactive_overdue'],
      pageField: 'ghca_inactive_page',
      orderbyField: 'ghca_inactive_orderby',
      orderField: 'ghca_inactive_order'
    },
    priority: {
      fields: ['ghca_pri_group', 'ghca_pri_type', 'ghca_pri_search', 'ghca_pri_per', 'ghca_orderby', 'ghca_order'],
      checkboxFields: [],
      pageField: 'ghca_pri_page',
      orderbyField: 'ghca_orderby',
      orderField: 'ghca_order'
    },
    courses: {
      // ghca_crs_sort is sent by the redesigned tab only; the legacy table ignores it.
      fields: ['ghca_crs_group', 'ghca_crs_search', 'ghca_crs_cert', 'ghca_crs_per', 'ghca_crs_sort'],
      checkboxFields: [],
      pageField: 'ghca_crs_page'
    },
    // The redesigned Certificates tab (slice 4). There is no legacy version of this table.
    certificates: {
      fields: ['ghca_cert_view', 'ghca_cert_course', 'ghca_cert_search', 'ghca_cert_when', 'ghca_cert_per', 'ghca_cert_orderby', 'ghca_cert_order'],
      checkboxFields: [],
      pageField: 'ghca_cert_page',
      orderbyField: 'ghca_cert_orderby',
      orderField: 'ghca_cert_order'
    }
  };

  document.querySelectorAll('[data-ghca-filter-form]').forEach(function (form) {
    initTableFilters(form);
  });

  /*
   * Board buttons that set one form field and refresh its table: the Certificates sub-tabs (Issued, Expiring,
   * Missing) and the "Show missing" alert button. Pressed state follows the field, so it is never out of step.
   */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-ghca-set-field]');
    if (!btn) { return; }
    var formId = btn.getAttribute('data-ghca-set-form');
    var form = formId ? document.getElementById(formId) : btn.closest('form');
    var name = btn.getAttribute('data-ghca-set-field');
    var field = form && form.elements ? form.elements.namedItem(name) : null;
    if (!form || !field) { return; }
    e.preventDefault();
    field.value = btn.getAttribute('data-ghca-set-value') || '';
    var pressed = null;
    form.querySelectorAll('[data-ghca-set-field="' + name + '"]').forEach(function (other) {
      var on = other.getAttribute('data-ghca-set-value') === field.value;
      other.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (on) { pressed = other; }
    });
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    // From outside the form (the alert), take the user to the list it changed.
    if (formId && pressed) {
      try { pressed.focus({ preventScroll: false }); } catch (err) { pressed.focus(); }
    }
  });

  /*
   * Certificates tab PDF button. Same endpoint and broker as the drawer, ids only. Only a real PDF is saved;
   * anything else leaves nothing behind and says so.
   */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-ghca-cert-pdf]');
    if (!btn) { return; }
    e.preventDefault();
    if (btn.getAttribute('aria-busy') === 'true') { return; }
    var url;
    try {
      url = new URL(btn.getAttribute('data-ghca-cert-pdf') || '', window.location.href);
    } catch (err) { url = null; }
    var failure = (window.ghcaAcd && window.ghcaAcd.certDownloadError) || 'The certificate could not be downloaded. Nothing was saved.';
    if (!url || url.origin !== window.location.origin) {
      if (typeof ghcaToast === 'function') { ghcaToast(failure, true); }
      return;
    }
    var name = (btn.getAttribute('data-ghca-cert-title') || 'certificate').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'certificate';
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    fetch(url.href, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) { throw new Error('download failed'); }
        var type = res.headers && res.headers.get ? res.headers.get('Content-Type') : '';
        return res.arrayBuffer().then(function (buffer) {
          if (!ghcaCertificateResponseIsPdf(type, new Uint8Array(buffer.slice(0, 5)))) { throw new Error('not a pdf'); }
          return new Blob([buffer], { type: 'application/pdf' });
        });
      })
      .then(function (blob) {
        var objectUrl = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = objectUrl;
        link.download = name + '.pdf';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
      })
      .catch(function () {
        if (typeof ghcaToast === 'function') { ghcaToast(failure, true); }
      })
      .finally(function () {
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
      });
  });

  function initTableFilters(form) {
    var tableId = form.getAttribute('data-ghca-table') || 'employees';
    var config = tableConfigs[tableId] || tableConfigs.employees;
    // The redesigned filter card keeps the mount outside the form's parent, so fall back to the page.
    var mount = (form.parentElement && form.parentElement.querySelector('[data-ghca-table-id="' + tableId + '"]')) ||
      document.querySelector('[data-ghca-table-id="' + tableId + '"]');
    var isBoard = form.getAttribute('data-ghca-ui') === 'board';
    var debounceMs = parseInt(form.getAttribute('data-ghca-debounce'), 10) || 400;
    var chipHost = isBoard ? document.querySelector('[data-ghca-chips="' + tableId + '"]') : null;
    var statusHost = isBoard ? document.querySelector('[data-ghca-table-status="' + tableId + '"]') : null;
    var controller = null;
    var searchTimer = null;

    if (!mount) {
      return;
    }

    function getPageInput() {
      return form.querySelector('[data-ghca-page-input]');
    }

    function setPage(page) {
      var input = getPageInput();
      if (input) {
        input.value = String(page);
      }
    }

    function setLoading(isLoading) {
      mount.classList.toggle('is-loading', isLoading);
      mount.setAttribute('aria-busy', isLoading ? 'true' : 'false');
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = isLoading;
      }
    }

    /*
     * A failed refresh leaves the previous rows on screen. Mark them so the
     * staleness is visible, and announce it through the shared toast, which
     * already carries role="alert" + aria-live for screen readers.
     */
    function setError(message) {
      mount.classList.add('is-error');
      if (typeof ghcaToast === 'function') {
        ghcaToast(message, true);
      }
    }

    function buildParams() {
      var data = new FormData(form);
      var params = new URLSearchParams();
      params.append('action', 'ghca_acd_filter_table');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('ghca_table', tableId);
      if (isBoard) {
        params.append('ghca_ui', 'board');
      }

      config.fields.forEach(function (key) {
        params.append(key, data.get(key) || '');
      });

      config.checkboxFields.forEach(function (key) {
        params.append(key, data.get(key) ? '1' : '');
      });

      params.append(config.pageField, data.get(config.pageField) || '1');
      return params;
    }

    function syncUrl() {
      if (!window.history || !window.history.replaceState) {
        return;
      }

      var data = new FormData(form);
      var url = new URL(window.location.href);

      config.fields.forEach(function (key) {
        var val = data.get(key);
        if (val) {
          url.searchParams.set(key, val);
        } else {
          url.searchParams.delete(key);
        }
      });

      config.checkboxFields.forEach(function (key) {
        if (data.get(key)) {
          url.searchParams.set(key, '1');
        } else {
          url.searchParams.delete(key);
        }
      });

      var page = data.get(config.pageField);
      if (page && page !== '1') {
        url.searchParams.set(config.pageField, page);
      } else {
        url.searchParams.delete(config.pageField);
      }

      window.history.replaceState({}, '', url.toString());
    }

    // The rows are replaced wholesale, which would drop keyboard focus from a pager or sort control.
    function focusMarker() {
      var el = document.activeElement;
      if (!isBoard || !el || !mount.contains(el)) {
        return null;
      }
      var page = el.closest('[data-ghca-page]');
      if (page) { return { attr: 'data-ghca-page', value: page.getAttribute('data-ghca-page') }; }
      var sort = el.closest('[data-ghca-sort]');
      if (sort) { return { attr: 'data-ghca-sort', value: sort.getAttribute('data-ghca-sort') }; }
      return null;
    }

    function restoreFocus(marker) {
      if (!marker) { return; }
      var target = mount.querySelector('[' + marker.attr + '="' + marker.value + '"]');
      target = target && (target.matches('button, a, input, select') ? target : target.querySelector('button'));
      if (target && !target.disabled) {
        try { target.focus({ preventScroll: true }); } catch (err) { target.focus(); }
      }
    }

    function refresh(resetPage) {
      if (resetPage) {
        setPage(1);
      }
      var marker = focusMarker();

      if (controller) {
        controller.abort();
      }
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      setLoading(true);

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: buildParams().toString(),
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (json && json.success && json.data && typeof json.data.html === 'string') {
            mount.innerHTML = json.data.html;
            mount.classList.remove('is-error');
            if (chipHost && typeof json.data.chips === 'string') {
              chipHost.innerHTML = json.data.chips;
            }
            if (statusHost && typeof json.data.summary === 'string') {
              statusHost.textContent = json.data.summary;
            }
            restoreFocus(marker);
            syncUrl();
            return;
          }
          /*
           * The server answered but refused (most often an expired nonce, which
           * previously left Apply looking dead). Keep the stale rows visible but
           * mark and announce them rather than failing silently.
           */
          setError(
            (json && json.data && json.data.message) ||
            (window.ghcaAcd && window.ghcaAcd.tableError) ||
            'Could not update the table.'
          );
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return;
          }
          setError((window.ghcaAcd && window.ghcaAcd.tableNetworkError) || 'Could not reach the server.');
        })
        .finally(function () {
          setLoading(false);
        });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      refresh(true);
    });

    form.addEventListener('change', function (e) {
      if (!e.target) {
        return;
      }
      // A board Sort select has no name: it writes "orderby:order" into the form's existing hidden sort fields.
      if (e.target.hasAttribute && e.target.hasAttribute('data-ghca-sort-select')) {
        var parts = (e.target.value || '').split(':');
        var by = form.querySelector('input[name="' + (config.orderbyField || 'ghca_orderby') + '"]');
        var dir = form.querySelector('input[name="' + (config.orderField || 'ghca_order') + '"]');
        if (by) { by.value = parts[0] || ''; }
        if (dir) { dir.value = parts[1] || 'asc'; }
      }
      if (e.target.tagName === 'SELECT' || e.target.type === 'checkbox') {
        refresh(true);
      }
    });

    form.addEventListener('input', function (e) {
      if (!e.target || e.target.type !== 'search') {
        return;
      }
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        refresh(true);
      }, debounceMs);
    });

    form.addEventListener('click', function (e) {
      var resetBtn = e.target.closest('[data-ghca-filter-reset]');
      if (resetBtn) {
        e.preventDefault();
        form.reset();
        setPage(1);
        refresh(false);
        return;
      }
    });

    mount.addEventListener('click', function (e) {
      var pageBtn = e.target.closest('[data-ghca-page]');
      if (!pageBtn || pageBtn.disabled) {
        return;
      }

      var pageField = pageBtn.getAttribute('data-ghca-page-field');
      if (pageField !== config.pageField) {
        return;
      }

      e.preventDefault();
      setPage(pageBtn.getAttribute('data-ghca-page') || '1');
      refresh(false);
    });

    // Clearing one filter (a chip's x) or all of them. Per page, sort and the page field are not filters.
    function clearFilters(name) {
      var controls = Array.prototype.slice.call(form.elements).filter(function (el) {
        if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.hasAttribute('data-ghca-per-page')) { return false; }
        // Sort and the Certificates view are not filters, so clearing filters leaves them as they are.
        if (el.hasAttribute('data-ghca-sort-select') || el.hasAttribute('data-ghca-not-filter')) { return false; }
        return name === 'all' || el.name === name;
      });
      controls.forEach(function (el) {
        if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; }
      });
      refresh(true);
      var first = form.querySelector('input[type="search"]');
      if (first) { first.focus(); }
    }

    function onClear(e) {
      var clear = e.target.closest('[data-ghca-filter-clear]');
      if (!clear) { return; }
      e.preventDefault();
      clearFilters(clear.getAttribute('data-ghca-filter-clear'));
    }

    if (isBoard) {
      if (chipHost) { chipHost.addEventListener('click', onClear); }
      mount.addEventListener('click', onClear);
      // The per-page select lives in the pager, outside the form, so the form's own change event never sees it.
      mount.addEventListener('change', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-ghca-per-page')) {
          refresh(true);
        }
      });
    }

    mount.addEventListener('click', function (e) {
      var sortHeader = e.target.closest('[data-ghca-sort]');
      if (!sortHeader) {
        return;
      }

      var column = sortHeader.getAttribute('data-ghca-sort');
      var order = sortHeader.getAttribute('data-ghca-sort-order');
      
      var inputColumn = form.querySelector('input[name="' + (config.orderbyField || 'ghca_orderby') + '"]');
      var inputOrder = form.querySelector('input[name="' + (config.orderField || 'ghca_order') + '"]');
      
      if (inputColumn) {
        inputColumn.value = column || '';
      }
      if (inputOrder) {
        inputOrder.value = order || 'asc';
      }
      
      refresh(true);
    });
  }

  function initCertificateModal() {
    var modal = document.getElementById('ghca-acd-cert-modal');
    if (!modal) {
      return;
    }

    var dialog = modal.querySelector('.ghca-acd__cert-modal-dialog');
    var frame = modal.querySelector('.ghca-acd__cert-frame');
    var loading = modal.querySelector('.ghca-acd__cert-modal-loading');
    var titleEl = modal.querySelector('#ghca-acd-cert-modal-title');
    var downloadBtn = modal.querySelector('[data-ghca-cert-download]');
    var closeBtn = modal.querySelector('.ghca-acd__cert-modal-close');
    var downloadError = modal.querySelector('[data-ghca-cert-download-error]');
    var defaultLoadingText = loading ? loading.textContent : '';
    var currentUrl = '';
    var currentDownloadUrl = '';
    var currentTitle = '';
    var release = null;

    function setDownloadError(message) {
      if (!downloadError) { return; }
      downloadError.textContent = message || '';
      downloadError.hidden = !message;
    }

    function slugify(value) {
      return (value || 'certificate')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '') || 'certificate';
    }

    function setFrameLoading(isLoading) {
      if (loading) {
        loading.hidden = !isLoading;
      }
      if (frame) {
        frame.hidden = isLoading;
      }
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--modal-open');
      ghcaAcdOverlay.close(modal);
      currentUrl = '';
      currentDownloadUrl = '';
      currentTitle = '';
      setDownloadError('');
      if (frame) {
        frame.onload = null;
        frame.onerror = null;
        frame.removeAttribute('src');
      }
      if (loading) { loading.textContent = defaultLoadingText; loading.classList.remove('is-error'); }
      setFrameLoading(true);
      if (release) { release(); release = null; }
    }

    function safeCertificateUrl(value) {
      try {
        var parsed = new URL(value, window.location.href);
        if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.origin === window.location.origin) {
          return parsed.href;
        }
      } catch (err) {}
      return '';
    }

    function openModal(trigger) {
      var url = safeCertificateUrl(trigger.getAttribute('data-ghca-cert-url') || trigger.getAttribute('href') || '');
      if (!url) {
        return;
      }

      currentUrl = url;
      currentDownloadUrl = safeCertificateUrl(trigger.getAttribute('data-ghca-cert-download-url') || '');
      currentTitle = trigger.getAttribute('data-ghca-cert-title') || '';
      setDownloadError('');

      if (titleEl) {
        titleEl.textContent = currentTitle
          ? ((window.ghcaAcd && window.ghcaAcd.certModalTitle) || 'Certificate') + ': ' + currentTitle
          : ((window.ghcaAcd && window.ghcaAcd.certModalTitle) || 'Certificate');
      }

      if (loading) { loading.textContent = defaultLoadingText; loading.classList.remove('is-error'); }
      setFrameLoading(true);
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--modal-open');
      ghcaAcdOverlay.open(modal, trigger, closeModal);

      if (frame) {
        frame.onload = function () {
          setFrameLoading(false);
        };
        frame.onerror = function () {
          if (loading) {
            loading.textContent = 'This certificate could not be loaded. Try opening it in a new tab.';
            loading.classList.add('is-error');
            loading.hidden = false;
          }
        };
        frame.src = url;
      }

      release = ghcaFocusTrap(dialog, closeBtn);
    }

    function downloadCertificate() {
      var sourceUrl = currentDownloadUrl || currentUrl;
      if (!sourceUrl) {
        return;
      }

      var filename = slugify(currentTitle) + '.pdf';
      var failure = 'Certificate could not be downloaded. Try opening it in a new tab.';
      setDownloadError('');

      fetch(sourceUrl, { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('download failed');
          }
          var contentType = res.headers && res.headers.get ? res.headers.get('Content-Type') : '';
          return res.arrayBuffer().then(function (buffer) {
            if (!ghcaCertificateResponseIsPdf(contentType, new Uint8Array(buffer.slice(0, 5)))) {
              throw new Error('not a pdf');
            }
            return new Blob([buffer], { type: 'application/pdf' });
          });
        })
        .then(function (blob) {
          var objectUrl = URL.createObjectURL(blob);
          var link = document.createElement('a');
          link.href = objectUrl;
          link.download = filename;
          document.body.appendChild(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(objectUrl);
        })
        .catch(function () {
          setDownloadError(failure);
        });
    }

    window.ghcaAcdOpenCertificateModal = function (url, title, downloadUrl, focusEl) {
      var proxy = focusEl || document.createElement('button');
      proxy.setAttribute('data-ghca-cert-url', url);
      proxy.setAttribute('data-ghca-cert-title', title || '');
      if (downloadUrl) { proxy.setAttribute('data-ghca-cert-download-url', downloadUrl); }
      else { proxy.removeAttribute('data-ghca-cert-download-url'); }
      openModal(proxy);
    };

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('.ghca-acd__cert-trigger, [data-ghca-cert-trigger]');
      if (trigger) {
        e.preventDefault();
        openModal(trigger);
        return;
      }

      if (e.target.closest('[data-ghca-cert-close]')) {
        e.preventDefault();
        closeModal();
      }
    });

    if (downloadBtn) {
      downloadBtn.addEventListener('click', downloadCertificate);
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden && ghcaAcdOverlay.isTop(modal)) {
        closeModal();
      }
    });
  }

  // The redesigned overview uses .gh-tab buttons without the legacy classes,
  // whose !important colours would override the board styling.
  var GHCA_TAB = '.ghca-acd__tab-btn, .gh-tab[data-ghca-tab-target]';
  var GHCA_TABLIST = '.ghca-acd__tabs, .gh-tabs[data-ghca-tablist-main]';

  function activateTab(targetId, moveFocus) {
    if (!targetId) return;

    var tabBtn = document.querySelector('.ghca-acd__tab-btn[data-ghca-tab-target="' + targetId + '"], .gh-tab[data-ghca-tab-target="' + targetId + '"]');
    if (!tabBtn) return;

    var tabContainer = tabBtn.closest(GHCA_TABLIST);
    var allBtns = tabContainer ? tabContainer.querySelectorAll(GHCA_TAB) : document.querySelectorAll(GHCA_TAB);
    var allContents = document.querySelectorAll('.ghca-acd__tab-content');

    allBtns.forEach(function(btn) {
      var active = btn === tabBtn;
      btn.classList.toggle('is-active', active);
      // Roving tabindex: only the selected tab stays in the tab order, so the
      // strip is one stop and arrow keys move between tabs.
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
      btn.setAttribute('tabindex', active ? '0' : '-1');
    });
    allContents.forEach(function(content) { content.classList.remove('is-active'); });

    var targetContent = document.getElementById(targetId);
    if (targetContent) {
      targetContent.classList.add('is-active');
    }
    if (moveFocus) {
      tabBtn.focus();
      // On phones the redesigned tab bar scrolls sideways; keep the selected tab fully in view (legacy tabs unchanged).
      if (tabContainer && tabContainer.hasAttribute('data-ghca-tablist-main') && typeof tabBtn.scrollIntoView === 'function') {
        tabBtn.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
    }
  }

  function initTabsFromHash() {
    var hash = (window.location.hash || '').replace('#', '');
    if (!hash) return;
    if (hash === 'ghca-overdue-employees') {
      hash = 'ghca-tab-overdue';
    }
    if (hash.indexOf('ghca-tab-') !== 0) return;
    activateTab(hash);
  }

  function initTabs() {
    document.addEventListener('click', function(e) {
      var jump = e.target.closest('[data-ghca-tab-jump]');
      if (jump) {
        e.preventDefault();
        var jumpTarget = jump.getAttribute('data-ghca-tab-jump');
        activateTab(jumpTarget);
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + jumpTarget);
        } else {
          window.location.hash = jumpTarget;
        }
        return;
      }

      var tabBtn = e.target.closest(GHCA_TAB);
      if (!tabBtn) return;
      var targetId = tabBtn.getAttribute('data-ghca-tab-target');
      if (!targetId) return;

      activateTab(targetId);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + targetId);
      }
    });

    /*
     * Roving keyboard navigation for the dashboard tablist, mirroring
     * initEmployeeDrawerTabs() so both tab systems behave identically.
     * Bound per-tablist rather than on document so the drawer's own tablist
     * (.ghca-acd__drawer-tabs, injected later by AJAX) is never intercepted.
     */
    document.querySelectorAll(GHCA_TABLIST).forEach(function (tablist) {
      if (tablist.closest('.ghca-acd__drawer')) return;
      tablist.addEventListener('keydown', function (e) {
        var btns = Array.prototype.slice.call(tablist.querySelectorAll(GHCA_TAB));
        if (!btns.length) return;
        var current = btns.indexOf(document.activeElement);
        if (current === -1) return;
        var next = current;
        if (e.key === 'ArrowRight') next = (current + 1) % btns.length;
        else if (e.key === 'ArrowLeft') next = (current - 1 + btns.length) % btns.length;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = btns.length - 1;
        else return;
        e.preventDefault();
        var nextTarget = btns[next].getAttribute('data-ghca-tab-target');
        activateTab(nextTarget, true);
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + nextTarget);
        }
      });
    });

    initTabsFromHash();
    window.addEventListener('hashchange', initTabsFromHash);
  }
  
    function initEmployeeDrawer() {
    var drawer = document.getElementById('ghca-acd-employee-drawer');
    if (!drawer) return;

    var dialog = drawer.querySelector('[data-ghca-drawer-dialog], .ghca-acd__drawer-dialog');
    // The redesigned drawer has a real close button after its scrim; prefer it as the first focus target.
    var closeBtn = drawer.querySelector('button[data-ghca-drawer-close]') || drawer.querySelector('[data-ghca-drawer-close]');
    var isBoard = drawer.getAttribute('data-ghca-ui') === 'board';
    var bodyContainer = document.getElementById('ghca-acd-employee-drawer-body');
    var loadingHtml = bodyContainer.innerHTML;
    var controller = null;
    var release = null;
    var lastUserId = null;
    var selectedTabs = Object.create(null);

    function errorState(message) {
      if (isBoard) {
        // Message text comes from the server or this file; it is set as text, never as markup.
        return '<div class="gh-empty gh-dr-error" role="alert"><span class="gh-empty-ico"><span class="gh-i gh-i--xl i-alert" aria-hidden="true"></span></span>' +
          '<strong class="gh-h4" data-ghca-error-msg></strong>' +
          '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-drawer-retry>Try again</button></div>';
      }
      return '<div class="ghca-acd__overlay-state ghca-acd__overlay-state--error" role="alert">' +
        '<p class="ghca-acd__overlay-state-msg">' + message + '</p>' +
        '<button type="button" class="ghca-acd__overlay-retry" data-ghca-drawer-retry>Try again</button>' +
        '</div>';
    }

    function fillErrorText(message) {
      var slot = bodyContainer.querySelector('[data-ghca-error-msg]');
      if (slot) { slot.textContent = message; }
    }

    function initEmployeeDrawerTabs(userId) {
      var tablist = bodyContainer.querySelector('[data-ghca-tablist]');
      if (!tablist) return;
      var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
      var panels = Array.prototype.slice.call(bodyContainer.querySelectorAll('[data-ghca-tabpanel]'));
      if (!tabs.length) return;
      var allowed = tabs.map(function (tab) { return tab.getAttribute('data-ghca-tab'); });
      var remembered = selectedTabs[String(userId)] || 'training';
      if (allowed.indexOf(remembered) === -1) remembered = 'training';

      function selectTab(name, moveFocus) {
        if (allowed.indexOf(name) === -1) name = 'training';
        selectedTabs[String(userId)] = name;
        var activeTab = null;
        tabs.forEach(function (tab) {
          var active = tab.getAttribute('data-ghca-tab') === name;
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
          tab.setAttribute('tabindex', active ? '0' : '-1');
          if (active) activeTab = tab;
        });
        // Focus after every tab has its final state: the selected tab is bolder, so focusing first scrolled the
        // phone tab bar to a width that then changed. The board tab bar also scrolls the tab fully into view.
        if (activeTab && moveFocus) {
          activeTab.focus();
          if (tablist.classList.contains('gh-drh-tabs') && typeof activeTab.scrollIntoView === 'function') {
            activeTab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
          }
        }
        panels.forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-ghca-tabpanel') !== name;
        });
      }

      tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { selectTab(tab.getAttribute('data-ghca-tab'), false); });
        tab.addEventListener('keydown', function (e) {
          var next = index;
          if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
          else if (e.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
          else if (e.key === 'Home') next = 0;
          else if (e.key === 'End') next = tabs.length - 1;
          else return;
          e.preventDefault();
          selectTab(tabs[next].getAttribute('data-ghca-tab'), true);
        });
      });
      selectTab(remembered, false);
    }

    function openDrawer(userId, launcher) {
      lastUserId = userId;
      drawer.hidden = false;
      drawer.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--drawer-open');
      ghcaAcdOverlay.open(drawer, launcher || null, function () {
        if (typeof window.ghcaAcdEditSubpageBack === 'function' && window.ghcaAcdEditSubpageBack()) return;
        closeDrawer();
      });
      bodyContainer.innerHTML = loadingHtml;
      bodyContainer.setAttribute('aria-busy', 'true');

      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

      var params = new URLSearchParams();
      params.append('action', 'ghca_acd_get_employee_drawer');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', userId);
      if (isBoard) { params.append('ghca_ui', 'board'); }

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        signal: controller ? controller.signal : undefined
      })
      .then(function(res) { return res.json(); })
      .then(function(json) {
        bodyContainer.removeAttribute('aria-busy');
        if (json && json.success && json.data && json.data.html) {
          bodyContainer.innerHTML = json.data.html;
          initEmployeeDrawerTabs(String(userId));
        } else {
          bodyContainer.innerHTML = errorState((json && json.data && json.data.message) || 'We couldn’t load this employee.');
          fillErrorText((json && json.data && json.data.message) || 'We couldn’t load this employee.');
        }
      })
      .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        bodyContainer.removeAttribute('aria-busy');
        bodyContainer.innerHTML = errorState('Network error. Please check your connection and try again.');
        fillErrorText('Network error. Please check your connection and try again.');
      });

      if (!release) {
        release = ghcaFocusTrap(dialog, closeBtn);
      }
    }

    function closeDrawer() {
      drawer.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--drawer-open');
      ghcaAcdOverlay.close(drawer);
      if (controller) controller.abort();
      if (release) { release(); release = null; }
    }

    // Let the Edit Records modal re-render the drawer with fresh data after a save.
    window.ghcaAcdReloadDrawer = openDrawer;

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-employee-drawer]');
      if (trigger) {
        e.preventDefault();
        openDrawer(trigger.getAttribute('data-ghca-employee-drawer'), trigger);
        return;
      }

      if (e.target.closest('[data-ghca-drawer-retry]')) {
        e.preventDefault();
        if (lastUserId !== null) openDrawer(lastUserId);
        return;
      }

      var reviewBtn = e.target.closest('[data-ghca-mark-reviewed]');
      if (reviewBtn) {
        e.preventDefault();
        if (reviewBtn.disabled) return;
        var prevLabel = reviewBtn.textContent;
        reviewBtn.disabled = true;
        reviewBtn.textContent = (window.ghcaAcd && window.ghcaAcd.loading) || 'Saving…';

        var rp = new URLSearchParams();
        rp.append('action', 'ghca_acd_mark_reviewed');
        rp.append('nonce', window.ghcaAcd.nonce);
        rp.append('user_id', reviewBtn.getAttribute('data-ghca-mark-reviewed'));

        fetch(window.ghcaAcd.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: rp.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
          reviewBtn.disabled = false;
          reviewBtn.textContent = prevLabel;
          if (json && json.success && json.data) {
            var statusEl = drawer.querySelector('[data-ghca-review-status]');
            var textEl = drawer.querySelector('[data-ghca-review-text]');
            var badgeEl = drawer.querySelector('[data-ghca-review-badge]');
            if (textEl) textEl.textContent = json.data.line;
            if (statusEl) statusEl.classList.remove('is-empty');
            if (badgeEl) { badgeEl.textContent = json.data.badge; badgeEl.hidden = false; }
            ghcaToast(json.data.message || 'Marked as reviewed.', false);
          } else {
            ghcaToast((json && json.data && json.data.message) || 'Could not mark reviewed.', true);
          }
        })
        .catch(function() {
          reviewBtn.disabled = false;
          reviewBtn.textContent = prevLabel;
          ghcaToast('Network error. Please try again.', true);
        });
        return;
      }

      if (e.target.closest('[data-ghca-drawer-close]')) {
        e.preventDefault();
        closeDrawer();
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && !drawer.hidden && ghcaAcdOverlay.isTop(drawer)) {
        closeDrawer();
      }
    });
  }

  /*
   * Edit Records is a sub-page inside the drawer's Administration tab, not an
   * overlay. The drawer body is replaced on every open, so every lookup happens
   * at call time and all handlers are delegated from document.
   *
   * The save path (endpoint, nonce, toast, drawer reload) is unchanged from the
   * previous modal implementation.
   */
  function initEditRecordsSubpage() {
    var currentUserId = null;
    var controller = null;

    function panel() {
      return document.querySelector('.ghca-acd__drawer-panel--administration, [data-ghca-tabpanel="administration"]');
    }
    function views() {
      var p = panel();
      if (!p) return null;
      var overview = p.querySelector('[data-ghca-admin-view="overview"]');
      var subpage = p.querySelector('[data-ghca-admin-view="subpage"]');
      var body = p.querySelector('#ghca-acd-edit-subpage-body');
      return (overview && subpage && body) ? { overview: overview, subpage: subpage, body: body } : null;
    }

    // The redesigned drawer widens while its Edit Records sub-page is open (the Drawer-EditRecords board is 920px).
    function setWide(on) {
      var host = document.getElementById('ghca-acd-employee-drawer');
      var dlg = host && host.querySelector('[data-ghca-drawer-dialog]');
      if (dlg) { dlg.classList.toggle('is-subpage', !!on); }
    }
    function isBoardDrawer() {
      var host = document.getElementById('ghca-acd-employee-drawer');
      return !!host && host.getAttribute('data-ghca-ui') === 'board';
    }

    function showOverview(focusTrigger) {
      var v = views();
      if (!v) return;
      setWide(false);
      v.subpage.hidden = true;
      v.overview.hidden = false;
      if (controller) { controller.abort(); controller = null; }
      if (focusTrigger) {
        var back = v.overview.querySelector('[data-ghca-edit-records]');
        if (back) back.focus();
      }
    }

    function openSubpage(userId) {
      var v = views();
      if (!v) return;
      currentUserId = userId;
      setWide(true);
      v.overview.hidden = true;
      v.subpage.hidden = false;
      v.body.setAttribute('aria-busy', 'true');
      v.body.innerHTML = '<p class="ghca-acd__drawer-empty">' +
        ((window.ghcaAcd && window.ghcaAcd.loading) || 'Loading…') + '</p>';

      // Move focus to the sub-page heading so keyboard and screen-reader users
      // follow the navigation rather than being left on the old view.
      var heading = v.subpage.querySelector('.ghca-acd__subpage-head h4, [data-ghca-subpage-head]');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus();
      }

      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

      var params = new URLSearchParams();
      params.append('action', 'ghca_acd_get_edit_records_form');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', userId);
      if (isBoardDrawer()) { params.append('ghca_ui', 'board'); }

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success && json.data && typeof json.data.html === 'string') {
            v.body.innerHTML = json.data.html;
          } else {
            v.body.innerHTML = '<p class="ghca-acd__edit-error" role="alert">' +
              ((json && json.data && json.data.message) || 'We couldn’t load these records.') + '</p>';
          }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          v.body.innerHTML = '<p class="ghca-acd__edit-error" role="alert">Network error. Please check your connection and try again.</p>';
        })
        .finally(function () {
          v.body.setAttribute('aria-busy', 'false');
        });
    }

    function submitForm(form) {
      var saveBtn = form.querySelector('.ghca-acd__edit-btn--save, [data-ghca-edit-save]');
      var prevText = saveBtn ? saveBtn.textContent : '';
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = (window.ghcaAcd && window.ghcaAcd.loading) || 'Saving…';
      }

      var params = new URLSearchParams(new FormData(form));
      form.querySelectorAll('input[type="checkbox"][name$="[mark_complete]"]').forEach(function (checkbox) {
        params.set(checkbox.name, checkbox.checked ? '1' : '0');
      });
      params.append('action', 'ghca_acd_save_employee_records');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', currentUserId);

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            var savedUserId = currentUserId;
            ghcaToast((json.data && json.data.message) || 'Records updated.', false);
            showOverview(false);
            // Reloading the drawer re-renders the sub-page too, so the form
            // always reflects what was actually persisted.
            if (typeof window.ghcaAcdReloadDrawer === 'function') {
              window.ghcaAcdReloadDrawer(savedUserId);
            }
          } else {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
            ghcaToast((json && json.data && json.data.message) || 'Could not save records.', true);
          }
        })
        .catch(function () {
          if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
          ghcaToast('Network error. Please try again.', true);
        });
    }

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-ghca-edit-records]');
      if (trigger) {
        e.preventDefault();
        openSubpage(trigger.getAttribute('data-ghca-edit-records'));
        return;
      }
      if (e.target.closest('[data-ghca-subpage-back]') || e.target.closest('[data-ghca-edit-close]')) {
        e.preventDefault();
        showOverview(true);
      }
    });

    document.addEventListener('submit', function (e) {
      var form = e.target.closest('[data-ghca-edit-form]');
      if (!form) return;
      e.preventDefault();
      submitForm(form);
    });

    // "Unsaved changes: N fields": how many controls differ from what the server rendered. The redesigned form
    // only; it reads defaults the browser already keeps, so nothing is stored and nothing is sent.
    function countChanged(form) {
      var changed = 0;
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked !== el.defaultChecked) { changed += 1; }
        } else if (el.tagName === 'SELECT') {
          var was = Array.prototype.some.call(el.options, function (o) { return o.defaultSelected; }) ? Array.prototype.filter.call(el.options, function (o) { return o.defaultSelected; })[0].value : el.options[0].value;
          if (el.value !== was) { changed += 1; }
        } else if (el.value !== el.defaultValue) {
          changed += 1;
        }
      });
      return changed;
    }
    function updateDirty(e) {
      var form = e.target && e.target.closest ? e.target.closest('[data-ghca-edit-form]') : null;
      var slot = form && form.querySelector('[data-ghca-edit-dirty]');
      if (!slot) { return; }
      var n = countChanged(form);
      slot.textContent = n === 0 ? 'No unsaved changes' : (n === 1 ? 'Unsaved changes: 1 field' : 'Unsaved changes: ' + n + ' fields');
    }
    document.addEventListener('input', updateDirty);
    document.addEventListener('change', updateDirty);

    // The drawer's overlay close handler invokes this when Escape reaches it.
    window.ghcaAcdEditSubpageBack = function () {
      var v = views();
      if (!v || v.subpage.hidden) return false;
      showOverview(true);
      return true;
    };
  }

  /*
   * Redesigned screens carry a toast stack inside the themed wrapper (.ghca-ui), so toasts there use the
   * board component. Anywhere else the original toast is unchanged. Success clears itself after 6 seconds,
   * an error stays until dismissed, and at most three show at once.
   */
  function ghcaToastWire(stack, toast) {
    var isError = toast.classList.contains('gh-toast--error');
    var timer = null;
    function dismiss() {
      clearTimeout(timer);
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }
    function arm() {
      clearTimeout(timer);
      if (!isError) { timer = setTimeout(dismiss, 6000); }
    }
    var close = toast.querySelector('.gh-toast-x');
    if (close) { close.addEventListener('click', dismiss); }
    toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
    toast.addEventListener('mouseleave', arm);
    toast.addEventListener('focusin', function () { clearTimeout(timer); });
    toast.addEventListener('focusout', arm);
    arm();
    var all = stack.querySelectorAll('.gh-toast');
    for (var i = 0; i < all.length - 3; i++) {
      if (all[i].parentNode) { all[i].parentNode.removeChild(all[i]); }
    }
  }

  function ghcaBoardToast(stack, message, isError) {
    var text = String(message || '');
    var title = text;
    var body = '';
    var split = text.length > 70 ? text.match(/^(.+?[.!?])\s+(.+)$/) : null;
    if (split) { title = split[1]; body = split[2]; }

    function el(tag, cls, content) {
      var node = document.createElement(tag);
      if (cls) { node.className = cls; }
      if (content) { node.textContent = content; }
      return node;
    }
    var toast = el('div', 'gh-toast ' + (isError ? 'gh-toast--error' : 'gh-toast--success'));
    toast.setAttribute('role', isError ? 'alert' : 'status');
    var icon = el('span', 'gh-i ' + (isError ? 'i-x-circle' : 'i-check-circle'));
    icon.setAttribute('aria-hidden', 'true');
    var copy = el('div');
    copy.appendChild(el('div', 'gh-toast-t', title));
    if (body) { copy.appendChild(el('p', '', body)); }
    var close = el('button', 'gh-toast-x');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    var closeIcon = el('span', 'gh-i gh-i--sm i-x');
    closeIcon.setAttribute('aria-hidden', 'true');
    close.appendChild(closeIcon);
    toast.appendChild(icon);
    toast.appendChild(copy);
    toast.appendChild(close);
    stack.appendChild(toast);
    ghcaToastWire(stack, toast);
  }

  function ghcaToast(message, isError) {
    var stack = document.querySelector('.ghca-ui [data-ghca-toasts]');
    if (stack) {
      ghcaBoardToast(stack, message, isError);
      return;
    }
    var toast = document.createElement('div');
    toast.className = 'ghca-acd__toast' + (isError ? ' ghca-acd__toast--error' : '');
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    toast.textContent = message;
    document.body.appendChild(toast);
    void toast.offsetWidth;
    toast.classList.add('ghca-acd__toast--visible');
    setTimeout(function () {
      toast.classList.remove('ghca-acd__toast--visible');
      setTimeout(function () { toast.remove(); }, 300);
    }, isError ? 5000 : 3200);
  }

  // Accessible focus trap for drawers/modals: moves focus in, cycles Tab within
  // the dialog, and restores focus to the trigger on release.
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])';

  /**
   * A certificate download is saved only when the server said application/pdf
   * and the body really starts with "%PDF-". LearnDash can answer 200 text/html
   * (an access message or a login page); that must never become a .pdf file.
   */
  function ghcaCertificateResponseIsPdf(contentType, firstBytes) {
    if (!/^application\/pdf\s*(;|$)/i.test(String(contentType || '').trim())) {
      return false;
    }
    var magic = '%PDF-';
    if (!firstBytes || firstBytes.length < magic.length) {
      return false;
    }
    for (var i = 0; i < magic.length; i++) {
      if (firstBytes[i] !== magic.charCodeAt(i)) {
        return false;
      }
    }
    return true;
  }

  function ghcaFocusTrap(container, initialFocus) {
    if (!container) return function () {};
    var prev = document.activeElement;

    function visible(el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
    }
    function items() {
      return Array.prototype.slice.call(container.querySelectorAll(FOCUSABLE)).filter(visible);
    }
    function onKey(e) {
      if (e.key !== 'Tab') return;
      var f = items();
      if (!f.length) return;
      var first = f[0];
      var last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      else if (!container.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    }
    container.addEventListener('keydown', onKey);

    var target = initialFocus || items()[0] || container;
    try { target.focus({ preventScroll: true }); } catch (err) { target.focus(); }

    function release() {
      container.removeEventListener('keydown', onKey);
      if (prev && typeof prev.focus === 'function') {
        try { prev.focus({ preventScroll: true }); } catch (err) { prev.focus(); }
      }
    }
    release.setRestoreTarget = function (target) {
      if (target && typeof target.focus === 'function') prev = target;
    };
    return release;
  }

  function ghcaDrawerRestoreTarget() {
    var target = document.querySelector('#ghca-acd-employee-drawer button[data-ghca-drawer-close]') || document.querySelector('#ghca-acd-employee-drawer [data-ghca-drawer-close]');
    return target && !target.closest('[hidden]') ? target : null;
  }

  function ghcaRetargetDrawerFocus(release) {
    var target = ghcaDrawerRestoreTarget();
    if (target && release && typeof release.setRestoreTarget === 'function') release.setRestoreTarget(target);
  }

  function initAnnouncements() {
    var modal = document.getElementById('ghca-acd-announce-modal');
    var listEl = document.querySelector('[data-ghca-announce-list]');
    if (!modal || !listEl) return;

    var dialog = modal.querySelector('.ghca-acd__edit-modal-dialog');
    var form = modal.querySelector('[data-ghca-announce-form]');
    var titleEl = document.getElementById('ghca-acd-announce-modal-title');
    var saveBtn = modal.querySelector('.ghca-acd__edit-btn--save');
    var release = null;

    function setField(name, value) {
      var el = form.elements[name];
      if (el) el.value = value || '';
    }

    function openModal(mode, data) {
      data = data || {};
      form.reset();
      setField('announce_id', mode === 'edit' ? data.id : '0');
      if (mode === 'edit') {
        setField('title', data.title);
        setField('body', data.body);
        setField('type', data.type || 'update');
        setField('url', data.url);
      }
      if (titleEl) titleEl.textContent = mode === 'edit' ? 'Edit Announcement' : 'Add Announcement';
      if (saveBtn) saveBtn.textContent = mode === 'edit' ? 'Save' : 'Publish';

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--edit-open');
      release = ghcaFocusTrap(dialog, form.elements['title']);
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--edit-open');
      if (release) { release(); release = null; }
    }

    function post(extra, onDone) {
      var params = new URLSearchParams();
      params.append('nonce', window.ghcaAcd.nonce);
      var format = listEl.getAttribute('data-ghca-announce-format');
      if (format) params.append('format', format);
      Object.keys(extra).forEach(function (k) { params.append(k, extra[k]); });
      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      })
      .then(function (res) { return res.json(); })
      .then(onDone)
      .catch(function () { onDone(null); });
    }

    document.addEventListener('click', function (e) {
      var addBtn = e.target.closest('[data-ghca-announce-add]');
      if (addBtn) { e.preventDefault(); openModal('create'); return; }

      var editBtn = e.target.closest('[data-ghca-announce-edit]');
      if (editBtn) {
        e.preventDefault();
        var item = editBtn.closest('[data-announce-id]');
        if (!item) return;
        openModal('edit', {
          id: item.getAttribute('data-announce-id'),
          title: item.getAttribute('data-announce-title'),
          body: item.getAttribute('data-announce-body'),
          type: item.getAttribute('data-announce-type'),
          url: item.getAttribute('data-announce-url')
        });
        return;
      }

      var delBtn = e.target.closest('[data-ghca-announce-delete]');
      if (delBtn) {
        e.preventDefault();
        if (!window.confirm('Delete this announcement? This cannot be undone.')) return;
        delBtn.disabled = true;
        post({ action: 'ghca_acd_delete_announcement', announce_id: delBtn.getAttribute('data-ghca-announce-delete') }, function (json) {
          if (json && json.success && json.data) {
            listEl.innerHTML = json.data.html;
            ghcaToast(json.data.message || 'Announcement deleted.', false);
          } else {
            delBtn.disabled = false;
            ghcaToast((json && json.data && json.data.message) || 'Could not delete announcement.', true);
          }
        });
        return;
      }

      if (e.target.closest('[data-ghca-announce-close]')) { e.preventDefault(); closeModal(); }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var prev = saveBtn ? saveBtn.textContent : '';
      if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = (window.ghcaAcd && window.ghcaAcd.loading) || 'Saving…'; }

      var data = { action: 'ghca_acd_save_announcement' };
      ['announce_id', 'title', 'body', 'type', 'url'].forEach(function (name) {
        var el = form.elements[name];
        data[name] = el ? el.value : '';
      });

      post(data, function (json) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prev; }
        if (json && json.success && json.data) {
          listEl.innerHTML = json.data.html;
          ghcaToast(json.data.message || 'Saved.', false);
          closeModal();
        } else {
          ghcaToast((json && json.data && json.data.message) || 'Could not save announcement.', true);
        }
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
  }

  function initManageUsers() {
    var modal = document.getElementById('ghca-acd-user-modal');
    if (!modal) return;

    var form = document.getElementById('ghca-acd-user-form');
    var closeBtn = modal.querySelector('.ghca-acd-modal__close');
    var cancelBtn = modal.querySelector('.ghca-acd-modal__cancel');
    var addBtn = document.getElementById('ghca-acd-btn-add-user');
    var editBtns = document.querySelectorAll('.ghca-acd-btn-edit-user');
    var submitBtn = document.getElementById('ghca-acd-user-submit');
    var btnText = submitBtn ? submitBtn.querySelector('.ghca-acd-btn-text') : null;
    var spinner = submitBtn ? submitBtn.querySelector('.ghca-acd-spinner') : null;
    var titleEl = document.getElementById('ghca-acd-user-modal-title');

    // The shared pickers list active values only. A removed custom Employment Type / role
    // is added just for the employee who still holds it, and dropped again afterwards.
    function clearTempOptions() {
      Array.prototype.forEach.call(form.querySelectorAll('option[data-ghca-temp]'), function (opt) {
        opt.parentNode.removeChild(opt);
      });
    }

    function ensureOption(select, value, label) {
      if (!select || !value || !label) return;
      var exists = Array.prototype.some.call(select.options, function (opt) { return opt.value === value; });
      if (exists) return;
      var opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      opt.setAttribute('data-ghca-temp', '1');
      select.appendChild(opt);
    }

    function openModal() {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.style.display = 'none';
      document.body.style.overflow = '';
      form.reset();
      document.getElementById('ghca-acd-user-id').value = '';
      clearTempOptions();
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        titleEl.textContent = 'Add Employee';
        clearTempOptions();
        openModal();
      });
    }

    Array.prototype.forEach.call(editBtns, function (btn) {
      btn.addEventListener('click', function () {
        titleEl.textContent = 'Edit Employee';
        form.elements['user_id'].value = btn.getAttribute('data-user_id');
        form.elements['first_name'].value = btn.getAttribute('data-first_name');
        form.elements['last_name'].value = btn.getAttribute('data-last_name');
        form.elements['email'].value = btn.getAttribute('data-email');
        form.elements['phone'].value = btn.getAttribute('data-phone') || '';
        clearTempOptions();
        ensureOption(form.elements['employment_type'], btn.getAttribute('data-employment_type'), btn.getAttribute('data-employment_type_label'));
        form.elements['employment_type'].value = btn.getAttribute('data-employment_type') || '';
        ensureOption(form.elements['role'], btn.getAttribute('data-role'), btn.getAttribute('data-role_label'));
        if (form.elements['role']) {
          form.elements['role'].value = btn.getAttribute('data-role') || 'subscriber';
        }
        
        var groups = [];
        try {
          groups = JSON.parse(btn.getAttribute('data-groups') || '[]');
        } catch(e) {}
        
        var checkboxes = form.querySelectorAll('input[name="groups[]"]');
        Array.prototype.forEach.call(checkboxes, function (cb) {
          cb.checked = groups.indexOf(parseInt(cb.value, 10)) > -1;
        });
        
        openModal();
      });
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitBtn) submitBtn.disabled = true;
        if (btnText) {
          btnText.dataset.original = btnText.textContent;
          btnText.textContent = 'Saving...';
        }

        var formData = new FormData(form);
        var urlEncoded = new URLSearchParams(formData).toString();

        fetch(ghcaAcd.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: urlEncoded
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
          if (submitBtn) submitBtn.disabled = false;
          if (btnText) btnText.textContent = btnText.dataset.original;

          if (json && json.success) {
            ghcaToast(json.data || 'Saved successfully.', false);
            closeModal();
            setTimeout(function() { window.location.reload(); }, 1500);
          } else {
            ghcaToast(json.data || 'Error saving user.', true);
          }
        })
        .catch(function() {
          if (submitBtn) submitBtn.disabled = false;
          if (btnText) btnText.textContent = btnText.dataset.original;
          ghcaToast('Network error.', true);
        });
      });
    }
  }

  function initPdfPacket() {
    var modal = document.getElementById('ghca-acd-pdf-modal');
    if (!modal) return;

    var bar = modal.querySelector('[data-ghca-pdf-bar]');
    var track = modal.querySelector('[data-ghca-pdf-track]');
    var label = modal.querySelector('[data-ghca-pdf-label]');
    var summaryStep = modal.querySelector('[data-ghca-pdf-step="summary"]');
    var certificateStep = modal.querySelector('[data-ghca-pdf-step="certificates"]');
    var mergeStep = modal.querySelector('[data-ghca-pdf-step="merge"]');
    var running = false;
    var runGeneration = 0;
    var activeRunToken = 0;
    var activeJobId = '';
    var packetRelease = null;
    var packetOpener = null;
    var packetDialog = modal.querySelector('.ghca-acd__pdf-modal-dialog');

    function t(key, fallback) {
      return (window.ghcaAcd && window.ghcaAcd[key]) || fallback;
    }

    function sprintf1(str, a, b) {
      return str.replace('%1$s', a).replace('%2$s', b).replace('%s', a);
    }

    function setProgress(pct, text) {
      bar.style.width = pct + '%';
      track.setAttribute('aria-valuenow', String(pct));
      label.textContent = text;
      // Sub-page mirror (Administration > Edit Records & Packet Generator).
      var mirror = document.querySelector('[data-ghca-packet-mirror]');
      if (mirror) {
        mirror.hidden = false;
        var mPct = mirror.querySelector('[data-ghca-packet-pct]');
        var mBar = mirror.querySelector('[data-ghca-packet-bar]');
        var mTrack = mirror.querySelector('[data-ghca-packet-track]');
        var mLabel = mirror.querySelector('[data-ghca-packet-label]');
        if (mPct) mPct.textContent = String(Math.round(pct));
        if (mBar) mBar.style.width = pct + '%';
        if (mTrack) mTrack.setAttribute('aria-valuenow', String(Math.round(pct)));
        if (mLabel) mLabel.textContent = text;
        var isErr = bar.classList.contains('is-error');
        var state = function (done, active) {
          return 'ghca-acd__packet-mirror-step' + (done ? ' is-done' : (active ? ' is-active' : ''));
        };
        var mSummary = mirror.querySelector('[data-ghca-packet-step="summary"]');
        var mCerts = mirror.querySelector('[data-ghca-packet-step="certificates"]');
        var mMerge = mirror.querySelector('[data-ghca-packet-step="merge"]');
        if (mirror.hasAttribute('data-ghca-board')) {
          // Redesigned mirror: the step keeps its board classes and only its state changes.
          var set = function (el, done, active, err) { if (el) { el.setAttribute('data-state', err ? 'error' : (done ? 'done' : (active ? 'active' : ''))); } };
          set(mSummary, pct >= 5, pct < 5, false);
          set(mCerts, pct >= 92, pct >= 5 && pct < 92, false);
          set(mMerge, pct >= 100, pct >= 92, isErr);
        } else {
          if (mSummary) mSummary.className = state(pct >= 5, pct < 5);
          if (mCerts) mCerts.className = state(pct >= 92, pct >= 5 && pct < 92);
          if (mMerge) mMerge.className = isErr ? 'ghca-acd__packet-mirror-step is-error' : state(pct >= 100, pct >= 92);
        }
      }

      if (summaryStep && certificateStep && mergeStep) {
        summaryStep.className = 'ghca-acd__packet-step' + (pct < 5 ? ' is-active' : ' is-done');
        certificateStep.className = 'ghca-acd__packet-step' + (pct < 5 ? '' : (pct < 92 ? ' is-active' : ' is-done'));
        mergeStep.className = 'ghca-acd__packet-step' + (bar.classList.contains('is-error') ? ' is-error' : (pct < 92 ? '' : (pct < 100 ? ' is-active' : ' is-done')));
      }
    }

    function openModal(opener) {
      packetOpener = opener || packetOpener;
      bar.classList.remove('is-error');
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      ghcaAcdOverlay.open(modal, packetOpener, abortPacket);
      if (!packetRelease) packetRelease = ghcaFocusTrap(packetDialog, modal.querySelector('[data-ghca-pdf-cancel]'));
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      running = false;
      activeRunToken = 0;
      ghcaAcdOverlay.close(modal);
      if (packetRelease) { packetRelease(); packetRelease = null; }
      packetOpener = null;
    }

    function failState(msg) {
      bar.classList.add('is-error');
      setProgress(100, msg || t('pdfError', 'Packet generation failed. No packet was created. Please try again.'));
      running = false;
    }

    function cancelJob(jobId) {
      if (!jobId) return;
      var cp = new URLSearchParams();
      cp.append('action', 'ghca_acd_pdf_cancel');
      cp.append('job_id', jobId);
      post(cp).catch(function() {});
    }

    function cancelActiveJob() {
      var jobId = activeJobId;
      activeJobId = '';
      cancelJob(jobId);
    }

    function abortPacket() {
      activeRunToken = 0;
      cancelActiveJob();
      closeModal();
    }

    function isCurrentRun(runToken) {
      return runToken !== 0 && activeRunToken === runToken;
    }

    function post(params) {
      params.append('nonce', window.ghcaAcd.nonce);
      return fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function(res) { return res.json(); });
    }

    function run(userId, tracker, opener) {
      if (running) return;
      var drawer = opener.closest('.ghca-acd__drawer, #ghca-acd-employee-drawer');
      var period = drawer && drawer.querySelector('[data-ghca-audit-period]');
      var custom = tracker === 'annual' && period && period.querySelector('[data-ghca-audit-mode]').value === 'custom';
      var start, end;
      if (custom) {
        start = period.querySelector('[data-ghca-audit-start]');
        end = period.querySelector('[data-ghca-audit-end]');
        end.setCustomValidity(start.value && end.value && end.value < start.value ? 'End date must be on or after start date.' : '');
        if (!start.reportValidity() || !end.reportValidity()) return;
      }
      var runToken = ++runGeneration;
      activeRunToken = runToken;
      running = true;
      openModal(opener);
      setProgress(3, t('pdfPreparing', 'Preparing packet…'));

      var initParams = new URLSearchParams();
      initParams.append('action', 'ghca_acd_pdf_init');
      initParams.append('user_id', userId);
      initParams.append('tracker', tracker);
      if (custom) {
        initParams.append('audit_start', start.value);
        initParams.append('audit_end', end.value);
      }

      post(initParams).then(function(json) {
        if (!isCurrentRun(runToken)) {
          if (json && json.success && json.data && json.data.job_id) cancelJob(json.data.job_id);
          return;
        }
        if (!json || !json.success || !json.data || !json.data.job_id) {
          failState(json && json.data && json.data.message);
          return;
        }

        var jobId = json.data.job_id;
        activeJobId = jobId;
        var total = parseInt(json.data.total, 10) || 0;

        function mergeJob() {
          if (!isCurrentRun(runToken)) return;
          setProgress(92, t('pdfMerging', 'Merging documents…'));
          var mp = new URLSearchParams();
          mp.append('action', 'ghca_acd_pdf_merge');
          mp.append('job_id', jobId);
          post(mp).then(function(mj) {
            if (!isCurrentRun(runToken)) return;
            if (mj && mj.success && mj.data && mj.data.download_url) {
              setProgress(100, t('pdfDone', 'Done! Starting download…'));
              window.location.assign(mj.data.download_url);
              activeJobId = '';
              window.setTimeout(function() {
                if (isCurrentRun(runToken)) closeModal();
              }, 1500);
            } else {
              failState(mj && mj.data && mj.data.message);
            }
          }).catch(function() {
            if (isCurrentRun(runToken)) failState();
          });
        }

        function fetchNext(i) {
          if (!isCurrentRun(runToken)) return;
          if (i >= total) { mergeJob(); return; }

          setProgress(5 + Math.round((i / total) * 85), sprintf1(t('pdfFetching', 'Fetching certificate %1$s of %2$s…'), String(i + 1), String(total)));

          var fp = new URLSearchParams();
          fp.append('action', 'ghca_acd_pdf_fetch');
          fp.append('job_id', jobId);
          fp.append('index', String(i));
          post(fp).then(function(fj) {
            if (!isCurrentRun(runToken)) return;
            // ABORT policy: any fetch failure ends the job; the server has
            // already deleted the manifest and temp files at this point.
            if (!fj || !fj.success) { failState(fj && fj.data && fj.data.message); return; }
            fetchNext(i + 1);
          }).catch(function() {
            if (isCurrentRun(runToken)) failState();
          });
        }

        if (total === 0) { mergeJob(); } else { fetchNext(0); }
      }).catch(function() {
        if (isCurrentRun(runToken)) failState();
      });
    }

    document.addEventListener('change', function(e) {
      if (!e.target.matches('[data-ghca-audit-mode]')) return;
      var period = e.target.closest('[data-ghca-audit-period]');
      var custom = e.target.value === 'custom';
      period.querySelector('[data-ghca-audit-dates]').hidden = !custom;
      period.querySelectorAll('input').forEach(function(input) {
        input.disabled = !custom;
        input.setCustomValidity('');
      });
    });

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-pdf-packet]');
      if (trigger) {
        e.preventDefault();
        run(trigger.getAttribute('data-ghca-pdf-packet'), trigger.getAttribute('data-tracker') || 'annual', trigger);
        return;
      }
      if (e.target.closest('[data-ghca-pdf-cancel]')) {
        e.preventDefault();
        abortPacket();
      }
    });
  }

  function initReminderMessaging() {
    var modal = document.getElementById('ghca-acd-reminder-modal');
    var historyModal = document.getElementById('ghca-acd-history-modal');
    if (!modal && !historyModal) return;

    function post(params, signal) {
      params.append('nonce', window.ghcaAcd.nonce);
      return fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        signal: signal
      }).then(function(res) { return res.json(); });
    }

    var openReminder = null;
    var closeReminder = function() {};

    if (modal) {
      var dialog = modal.querySelector('.ghca-acd__reminder-modal-dialog, [data-ghca-reminder-dialog]');
      var closeBtn = modal.querySelector('.ghca-acd__reminder-modal-close, [data-ghca-reminder-x]');
      var loading = modal.querySelector('[data-ghca-reminder-loading]');
      var form = modal.querySelector('[data-ghca-reminder-form]');
      var userIdInput = modal.querySelector('[data-ghca-reminder-user-id]');
      var tokenInput = modal.querySelector('[data-ghca-reminder-token]');
    var nameEl = modal.querySelector('[data-ghca-reminder-name]');
    var avatarEl = modal.querySelector('[data-ghca-reminder-avatar]');
      var destinationEl = modal.querySelector('[data-ghca-reminder-destination]');
      var channelInput = modal.querySelector('[data-ghca-reminder-channel]');
      var channelOptions = modal.querySelectorAll('[data-ghca-reminder-channel-option]');
      var urgencyInput = modal.querySelector('[data-ghca-reminder-urgency]');
      var templateInput = modal.querySelector('[data-ghca-reminder-template]');
      var subjectInput = modal.querySelector('[data-ghca-reminder-subject]');
      var messageInput = modal.querySelector('[data-ghca-reminder-message]');
      var countEl = modal.querySelector('[data-ghca-reminder-count]');
      var readinessEl = modal.querySelector('[data-ghca-reminder-readiness]');
      var previewSubject = modal.querySelector('[data-ghca-reminder-preview-subject]');
      var previewMessage = modal.querySelector('[data-ghca-reminder-preview-message]');
      var previewChannel = modal.querySelector('[data-ghca-reminder-preview-channel]');
      var urgencyBadge = modal.querySelector('[data-ghca-reminder-urgency-badge]');
      var previewSms = modal.querySelector('[data-ghca-reminder-preview-sms]');
      var errorEl = modal.querySelector('[data-ghca-reminder-error]');
      var submitBtn = modal.querySelector('[data-ghca-reminder-submit]');
      var smsConfirm = modal.querySelector('[data-ghca-sms-confirm]');
      var smsSafe = modal.querySelector('[data-ghca-sms-safe]');
      // The redesigned modal has a second, confirm step. The legacy modal has none: confirmEl is null there.
      var isBoardModal = modal.getAttribute('data-ghca-ui') === 'board';
      var confirmEl = isBoardModal ? modal.querySelector('[data-ghca-reminder-confirm]') : null;
      var confirmSendBtn = confirmEl ? confirmEl.querySelector('[data-ghca-confirm-send]') : null;
      var confirmed = false;
      var templates = [];
      var context = null;
      var reminderOpener = null;
      var reminderRelease = null;
      var reminderController = null;

      function setError(message) {
        errorEl.hidden = !message;
        errorEl.textContent = message || '';
      }

      function urgencyPrefix(value) {
        var urgency = urgencyInput.value;
        if (urgency === 'normal') return value;
        var prefix = urgency.toUpperCase() + ':';
        return value.trim().toUpperCase().indexOf(prefix) === 0 ? value : prefix + ' ' + value;
      }

      function updatePreview() {
        countEl.textContent = String(messageInput.value.length);
        previewSubject.textContent = urgencyPrefix(subjectInput.value || '');
        previewMessage.textContent = messageInput.value || '';

        if (urgencyBadge) {
          var raised = urgencyInput.value !== 'normal';
          urgencyBadge.hidden = !raised;
          urgencyBadge.textContent = raised ? (urgencyInput.value === 'urgent' ? 'Urgent' : 'Elevated') : '';
          urgencyBadge.className = 'ghca-acd__reminder-urgency-badge' + (urgencyInput.value === 'urgent' ? ' is-urgent' : '');
          if (urgencyBadge.hasAttribute('data-ghca-board')) {
            urgencyBadge.className = 'gh-st gh-mod-badge ' + (urgencyInput.value === 'urgent' ? 'gh-st--overdue' : 'gh-st--duesoon');
          }
        }

        var usesSms = channelInput.value === 'sms' || channelInput.value === 'email_sms';
        var smsOnly = channelInput.value === 'sms';
        if (previewChannel) {
          previewChannel.textContent = smsOnly ? 'SMS' : (usesSms ? 'Email + SMS' : 'Email');
        }
        // SMS carries the body only, so a subject line would misrepresent it.
        previewSubject.hidden = smsOnly;
        if (previewSms) previewSms.hidden = !usesSms;
      }

      function channelLabel(value) {
        if (value === 'sms') return 'SMS';
        if (value === 'email_sms') return 'Email and SMS';
        return 'Email';
      }

      function channelUsesEmail() {
        return channelInput.value === 'email' || channelInput.value === 'email_sms';
      }

      function channelUsesSms() {
        return channelInput.value === 'sms' || channelInput.value === 'email_sms';
      }

      function syncChannelOptions() {
        channelOptions.forEach(function(option) { option.checked = option.value === channelInput.value; });
      }

      function populateTemplate(templateId) {
        var selected = null;
        templates.forEach(function(item) {
          if (String(item.id) === String(templateId)) selected = item;
        });
        if (!selected) return;
        subjectInput.value = selected.subject || '';
        messageInput.value = selected.message || '';
        urgencyInput.value = selected.urgency || 'normal';
        updatePreview();
      }

      function renderTemplateOptions(selectFirst) {
        var selected = templateInput.value;
        templateInput.innerHTML = '<option value="0">Custom message</option>';
        /*
         * Templates used to be hidden outright when they did not apply, so the
         * picker looked empty with no explanation. Anything unusable is now
         * listed as a disabled option carrying the reason, which makes both
         * causes visible: a delivery method it is not allowed for, and a
         * template whose placeholders fail to render.
         */
        templates.forEach(function(item) {
          var option = document.createElement('option');
          option.value = String(item.id);

          var wrongChannel = !Array.isArray(item.channels) || item.channels.indexOf(channelInput.value) === -1;
          var reason = item.reason || '';

          if (reason) {
            option.disabled = true;
            option.textContent = item.name + ' — ' + reason;
          } else if (wrongChannel) {
            option.disabled = true;
            option.textContent = item.name + ' — ' + channelLabel(channelInput.value) + ' not enabled for this template';
          } else {
            option.textContent = item.name;
          }
          templateInput.appendChild(option);
        });
        var keep = templateInput.querySelector('option[value="' + String(selected).replace(/"/g, '') + '"]');
        if (keep && !keep.disabled) {
          templateInput.value = selected;
        } else if (selectFirst) {
          // Skip disabled entries so auto-select cannot land on an unusable one.
          var firstUsable = -1;
          for (var i = 1; i < templateInput.options.length; i++) {
            if (!templateInput.options[i].disabled) { firstUsable = i; break; }
          }
          if (firstUsable > -1) {
            templateInput.selectedIndex = firstUsable;
            populateTemplate(templateInput.value);
          } else {
            templateInput.value = '0';
          }
        } else {
          templateInput.value = '0';
        }
      }

      function updateChannel(selectFirst) {
        if (!context) return;
        var email = channelUsesEmail();
        var sms = channelUsesSms();
        subjectInput.disabled = !email;
        subjectInput.required = email;
        messageInput.maxLength = sms ? 480 : 4000;
        smsConfirm.hidden = !sms;
        smsSafe.required = sms;
        if (!sms) smsSafe.checked = false;
        var ready = (!email || !!context.email_ready) && (!sms || !!context.sms_ready);
        var reasons = [];
        if (email) reasons.push(context.email_ready ? 'Email is ready.' : (context.email_reason || 'Email is unavailable.'));
        if (sms) reasons.push(context.sms_ready ? 'SMS is ready.' : (context.sms_reason || 'SMS is unavailable.'));
        readinessEl.textContent = reasons.join(' ');
        readinessEl.hidden = ready;
        readinessEl.classList.toggle('is-ready', ready);
        readinessEl.classList.toggle('is-blocked', !ready);
        submitBtn.disabled = !ready;
        renderTemplateOptions(!!selectFirst);
        updatePreview();
      }

      // Step 2 of the redesigned modal: a plain summary of what is about to be sent, before it is.
      function selectedText(select) {
        return select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent : select.value;
      }
      function showConfirm() {
        var email = channelUsesEmail();
        var sms = channelUsesSms();
        var name = nameEl.textContent || 'this employee';
        var kind = email && sms ? 'email and text' : (sms ? 'text' : 'email');
        confirmEl.querySelector('[data-ghca-confirm-title]').textContent = 'Send this ' + kind + ' to ' + name + '?';
        var to = [];
        if (email) to.push((context && context.masked_email) || 'Email unavailable');
        if (sms) to.push((context && context.masked_phone) || 'SMS unavailable');
        confirmEl.querySelector('[data-ghca-confirm-to]').textContent = to.join(' · ');
        confirmEl.querySelector('[data-ghca-confirm-subject]').textContent = urgencyPrefix(subjectInput.value || '');
        confirmEl.querySelector('[data-ghca-confirm-subject]').hidden = !email;
        confirmEl.querySelector('[data-ghca-confirm-subject-dt]').hidden = !email;
        confirmEl.querySelector('[data-ghca-confirm-urgency]').textContent = selectedText(urgencyInput);
        confirmEl.querySelector('[data-ghca-confirm-send-label]').textContent = 'Send ' + channelLabel(channelInput.value).toLowerCase().replace('sms', 'SMS');
        dialog.hidden = true;
        confirmEl.hidden = false;
        var back = confirmEl.querySelector('[data-ghca-confirm-back]');
        if (back) back.focus();
      }
      function hideConfirm(focusCompose) {
        if (!confirmEl) return;
        confirmed = false;
        confirmEl.hidden = true;
        dialog.hidden = false;
        if (focusCompose) submitBtn.focus();
      }
      // Escape and the scrim go back one step first, then close.
      function stepBackOrClose() {
        if (confirmEl && !confirmEl.hidden) { hideConfirm(true); return; }
        closeReminder();
      }

      closeReminder = function() {
        if (confirmEl) { confirmEl.hidden = true; dialog.hidden = false; }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('ghca-acd--reminder-open');
        ghcaAcdOverlay.close(modal);
        if (reminderController) { reminderController.abort(); reminderController = null; }
        if (reminderRelease) { reminderRelease(); reminderRelease = null; }
        else if (reminderOpener && reminderOpener.isConnected && typeof reminderOpener.focus === 'function') reminderOpener.focus();
      };

      openReminder = function(trigger, userId, draft) {
        reminderOpener = trigger;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('ghca-acd--reminder-open');
        ghcaAcdOverlay.open(modal, reminderOpener, isBoardModal ? stepBackOrClose : closeReminder);
        loading.textContent = 'Loading reminder options…';
        loading.hidden = false;
        form.hidden = true;
        setError('');
        templates = [];
        context = null;
        templateInput.innerHTML = '<option value="0">Custom message</option>';

        if (reminderController) reminderController.abort();
        reminderController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var params = new URLSearchParams();
        params.append('action', 'ghca_acd_get_reminder_context');
        params.append('user_id', userId);

        post(params, reminderController ? reminderController.signal : undefined).then(function(json) {
          if (!json || !json.success || !json.data) {
            loading.textContent = (json && json.data && json.data.message) || 'The reminder form could not be loaded.';
            return;
          }
          var data = json.data;
          context = data;
          templates = Array.isArray(data.templates) ? data.templates : [];
          userIdInput.value = String(data.employee_id || userId);
          tokenInput.value = data.idempotency_token || '';
        nameEl.textContent = data.employee_name || (trigger && trigger.getAttribute('data-ghca-reminder-name')) || 'Employee';
        if (avatarEl) avatarEl.textContent = nameEl.textContent.split(/\s+/).filter(Boolean).slice(0, 2).map(function(part) { return part.charAt(0); }).join('').toUpperCase();
          destinationEl.textContent = (data.masked_email || 'Email unavailable') + ' · ' + (data.masked_phone || 'SMS unavailable');
          subjectInput.value = draft ? (draft.subject || '') : '';
          messageInput.value = draft ? (draft.message || '') : '';
          urgencyInput.value = draft ? (draft.urgency || 'normal') : 'normal';
          var requestedChannel = draft ? draft.channel : '';
          var requestedReady = requestedChannel === 'email' ? data.email_ready : (requestedChannel === 'sms' ? data.sms_ready : (requestedChannel === 'email_sms' ? data.email_ready && data.sms_ready : false));
          channelInput.value = requestedReady ? requestedChannel : (data.email_ready ? 'email' : (data.sms_ready ? 'sms' : 'email'));
          syncChannelOptions();
          smsSafe.checked = false;
          updateChannel(!draft);
          loading.hidden = true;
          form.hidden = false;
          (channelUsesEmail() ? subjectInput : messageInput).focus();
        }).catch(function(err) {
          if (err && err.name === 'AbortError') return;
          loading.textContent = 'Network error. Please close the form and try again.';
        });

        // The board modal holds two dialogs, one visible at a time, so its trap covers the whole layer.
        if (!reminderRelease) reminderRelease = ghcaFocusTrap(isBoardModal ? modal : dialog, closeBtn);
      };

      templateInput.addEventListener('change', function() { populateTemplate(templateInput.value); });
      subjectInput.addEventListener('input', updatePreview);
      messageInput.addEventListener('input', updatePreview);
      urgencyInput.addEventListener('change', updatePreview);
      channelOptions.forEach(function(option) {
        option.addEventListener('change', function() {
          if (!option.checked) return;
          channelInput.value = option.value;
          updateChannel(true);
        });
      });

      if (confirmEl) {
        confirmEl.querySelector('[data-ghca-confirm-back]').addEventListener('click', function() { hideConfirm(true); });
        confirmSendBtn.addEventListener('click', function() {
          confirmed = true;
          if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
          else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
        });
      }

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        setError('');
        if ((channelUsesEmail() && !subjectInput.value.trim()) || !messageInput.value.trim()) {
          setError(channelUsesEmail() ? 'Enter both a subject and a message.' : 'Enter a message.');
          return;
        }
        if (channelUsesSms() && !smsSafe.checked) {
          setError('Confirm that the SMS contains no sensitive information.');
          return;
        }
        if (channelUsesSms() && messageInput.value.length > 480) {
          setError('SMS reminders are limited to 480 characters.');
          return;
        }
        if (submitBtn.disabled) return;
        if (isBoardModal && !confirmed) { showConfirm(); return; }
        confirmed = false;

        // Legacy: the Send button itself shows the progress. Redesigned: the confirm step's button does.
        var busyBtn = confirmSendBtn || submitBtn;
        var busyText = busyBtn.querySelector('[data-ghca-confirm-send-label]') || busyBtn;
        submitBtn.disabled = true;
        busyBtn.disabled = true;
        var originalLabel = busyText.textContent;
        busyText.textContent = 'Queueing…';
        var params = new URLSearchParams();
        params.append('action', 'ghca_acd_send_reminder');
        params.append('user_id', userIdInput.value);
        params.append('idempotency_token', tokenInput.value);
        params.append('channel', channelInput.value);
        params.append('urgency', urgencyInput.value);
        params.append('template_id', templateInput.value);
        params.append('subject', subjectInput.value);
        params.append('message', messageInput.value);
        if (channelUsesSms() && smsSafe.checked) params.append('sms_safe_confirmed', '1');

        // A failed send keeps everything typed. The redesigned modal says so in a toast, as the board does.
        function sendFailed(message) {
          if (isBoardModal) { hideConfirm(true); ghcaToast(message, true); } else { setError(message); }
        }

        post(params, reminderController ? reminderController.signal : undefined).then(function(json) {
          busyText.textContent = originalLabel;
          busyBtn.disabled = false;
          updateChannel(false);
          if (!json || !json.success) {
            sendFailed((json && json.data && json.data.message) || 'The reminder could not be queued.');
            return;
          }
          ghcaToast((json.data && json.data.message) || 'The reminder was queued.', false);
          var employeeId = userIdInput.value;
          ghcaRetargetDrawerFocus(reminderRelease);
          closeReminder();
          if (window.ghcaAcdReloadDrawer) window.ghcaAcdReloadDrawer(employeeId);
        }).catch(function(err) {
          if (err && err.name === 'AbortError') return;
          busyText.textContent = originalLabel;
          busyBtn.disabled = false;
          submitBtn.disabled = false;
          sendFailed('Network error. Please try again.');
        });
      });
    }

    var historyState = { userId: '', page: 1, pages: 1, opener: null, release: null, controller: null };
    var closeHistory = function() {};
    var loadHistory = function() {};

    if (historyModal) {
      var historyDialog = historyModal.querySelector('.ghca-acd__history-modal-dialog');
      var historyClose = historyModal.querySelector('[data-ghca-history-close]');
      var historyEmployee = historyModal.querySelector('[data-ghca-history-employee]');
      var historyFilters = historyModal.querySelector('[data-ghca-history-filters]');
    var historyCount = historyModal.querySelector('[data-ghca-history-count]');
      var historyResults = historyModal.querySelector('[data-ghca-history-results]');
      var historyPagination = historyModal.querySelector('[data-ghca-history-pagination]');
      var historyPageLabel = historyModal.querySelector('[data-ghca-history-page-label]');
      var previousButton = historyModal.querySelector('[data-ghca-history-page="previous"]');
      var nextButton = historyModal.querySelector('[data-ghca-history-page="next"]');

      function appendHistoryFilters(params) {
        ['channel', 'status', 'date_from', 'date_to', 'search'].forEach(function(name) {
          var field = historyFilters.elements[name];
          if (field && field.value) params.append(name, field.value);
        });
      }

      loadHistory = function(page) {
        if (!historyState.userId) return;
        if (historyState.controller) historyState.controller.abort();
        historyState.controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        historyCount.textContent = 'Loading communication history…';
        historyResults.setAttribute('aria-busy', 'true');
        var params = new URLSearchParams();
        params.append('action', 'ghca_acd_get_communication_history');
        params.append('user_id', historyState.userId);
        params.append('page', String(page || 1));
        appendHistoryFilters(params);

        post(params, historyState.controller ? historyState.controller.signal : undefined).then(function(json) {
          if (!json || !json.success || !json.data) {
            historyCount.textContent = (json && json.data && json.data.message) || 'History could not be loaded.';
            historyResults.innerHTML = '';
            historyResults.removeAttribute('aria-busy');
            historyPagination.hidden = true;
            return;
          }
          historyState.page = Number(json.data.page) || 1;
          historyState.pages = Number(json.data.pages) || 1;
          historyEmployee.textContent = json.data.employeeName || 'Employee';
          historyResults.innerHTML = json.data.html || '';
          historyResults.removeAttribute('aria-busy');
          historyCount.textContent = String(json.data.total || 0) + ((Number(json.data.total) === 1) ? ' communication' : ' communications');
          // Delivery totals from the ledger. Only states the schema records are
          // shown; there is no read/open tracking to report.
          var statsEl = historyModal.querySelector('[data-ghca-history-stats]');
          if (statsEl) {
            var stats = json.data.stats;
            if (stats) {
              ['total', 'email', 'sms', 'failed'].forEach(function (key) {
                var cell = statsEl.querySelector('[data-ghca-history-stat="' + key + '"]');
                if (cell) cell.textContent = String(stats[key] != null ? stats[key] : 0);
              });
              statsEl.hidden = false;
            } else {
              statsEl.hidden = true;
            }
          }

          historyPagination.hidden = Number(json.data.total) === 0;
          historyPageLabel.textContent = 'Page ' + historyState.page + ' of ' + historyState.pages;
          previousButton.disabled = historyState.page <= 1;
          nextButton.disabled = historyState.page >= historyState.pages;
        }).catch(function(err) {
          if (err && err.name === 'AbortError') return;
          historyResults.removeAttribute('aria-busy');
          historyCount.textContent = 'Network error. Please try again.';
        });
      };

      function openHistory(trigger) {
        historyState.userId = trigger.getAttribute('data-user-id') || '';
        if (!historyState.userId) return;
        historyState.opener = trigger;
        historyState.page = 1;
        historyState.pages = 1;
        ['channel', 'status', 'date_from', 'date_to', 'search'].forEach(function(name) {
          var field = historyFilters.elements[name];
          if (field) field.value = '';
        });
        historyModal.setAttribute('data-ghca-history-user', historyState.userId);
         historyModal.hidden = false;
         historyModal.setAttribute('aria-hidden', 'false');
         document.documentElement.classList.add('ghca-acd--reminder-open');
         ghcaAcdOverlay.open(historyModal, historyState.opener, closeHistory);
        historyResults.innerHTML = '';
        loadHistory(1);
        if (!historyState.release) historyState.release = ghcaFocusTrap(historyDialog, historyClose);
      }

      closeHistory = function() {
        historyModal.hidden = true;
         historyModal.setAttribute('aria-hidden', 'true');
         document.documentElement.classList.remove('ghca-acd--reminder-open');
         ghcaAcdOverlay.close(historyModal);
        if (historyState.controller) { historyState.controller.abort(); historyState.controller = null; }
        if (historyState.release) { historyState.release(); historyState.release = null; }
        else if (historyState.opener && historyState.opener.isConnected && typeof historyState.opener.focus === 'function') historyState.opener.focus();
      };

      historyFilters.addEventListener('submit', function(e) {
        e.preventDefault();
        loadHistory(1);
      });
      historyFilters.addEventListener('change', function(e) {
        if (e.target && (e.target.name === 'channel' || e.target.name === 'status')) loadHistory(1);
      });
      historyFilters.addEventListener('reset', function() {
        window.setTimeout(function() { loadHistory(1); }, 0);
      });
      previousButton.addEventListener('click', function() { if (historyState.page > 1) loadHistory(historyState.page - 1); });
      nextButton.addEventListener('click', function() { if (historyState.page < historyState.pages) loadHistory(historyState.page + 1); });

      document.addEventListener('click', function(e) {
        var trigger = e.target.closest('[data-ghca-history-open]');
        if (trigger) {
          e.preventDefault();
          openHistory(trigger);
          return;
        }
        if (e.target.closest('[data-ghca-history-close]')) {
          e.preventDefault();
          closeHistory();
        }
      });
    }

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-reminder]');
      if (trigger && openReminder) {
        e.preventDefault();
        openReminder(trigger, trigger.getAttribute('data-ghca-reminder'), null);
        return;
      }
      if (e.target.closest('[data-ghca-reminder-close]') && modal) {
        e.preventDefault();
        closeReminder();
        return;
      }

      var reuse = e.target.closest('[data-ghca-history-reuse]');
      if (reuse && openReminder && historyState.userId) {
        e.preventDefault();
        if (reuse.disabled) return;
        reuse.disabled = true;
        var draftParams = new URLSearchParams();
        draftParams.append('action', 'ghca_acd_get_communication_draft');
        draftParams.append('user_id', historyState.userId);
        draftParams.append('communication_id', reuse.getAttribute('data-ghca-history-reuse'));
        post(draftParams).then(function(json) {
          reuse.disabled = false;
          if (!json || !json.success || !json.data) {
            ghcaToast((json && json.data && json.data.message) || 'The reminder draft could not be loaded.', true);
            return;
          }
          var userId = historyState.userId;
          var reminderReturn = historyState.opener;
          closeHistory();
          openReminder(reminderReturn || reuse, userId, json.data);
        }).catch(function() {
          reuse.disabled = false;
          ghcaToast('Network error. Please try again.', true);
        });
        return;
      }

      var retry = e.target.closest('[data-ghca-reminder-retry]');
      if (retry) {
        e.preventDefault();
        if (retry.disabled) return;
        retry.disabled = true;
        var retryParams = new URLSearchParams();
        retryParams.append('action', 'ghca_acd_retry_reminder');
        retryParams.append('delivery_id', retry.getAttribute('data-ghca-reminder-retry'));
        post(retryParams).then(function(json) {
          if (!json || !json.success) {
            retry.disabled = false;
            ghcaToast((json && json.data && json.data.message) || 'The delivery could not be retried.', true);
            return;
          }
          ghcaToast((json.data && json.data.message) || 'The delivery was queued for retry.', false);
          if (historyModal && !historyModal.hidden) loadHistory(historyState.page);
          var historyContainer = retry.closest('[data-ghca-history-user]');
          var retryUserId = historyContainer ? historyContainer.getAttribute('data-ghca-history-user') : historyState.userId;
          if (retryUserId && window.ghcaAcdReloadDrawer) {
            ghcaRetargetDrawerFocus(historyState.release);
            window.ghcaAcdReloadDrawer(retryUserId);
          }
        }).catch(function() {
          retry.disabled = false;
          ghcaToast('Network error. Please try again.', true);
        });
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key !== 'Escape') return;
      if (modal && !modal.hidden && ghcaAcdOverlay.isTop(modal)) closeReminder();
      else if (historyModal && !historyModal.hidden && ghcaAcdOverlay.isTop(historyModal)) closeHistory();
    });
  }

  function initOltlManualReview() {
    document.addEventListener('submit', function(e) {
      var form = e.target.closest('.ghca-acd__oltl-manual-form');
      if (!form) return;
      e.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      var status = form.querySelector('.ghca-acd__oltl-form-status');
      if (button) button.disabled = true;
      if (status) status.textContent = 'Saving…';
      fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
        .then(function(response) { return response.json(); })
        .then(function(json) {
          if (!json || !json.success || !json.data || !json.data.html) {
            throw new Error((json && json.data && json.data.message) || 'The OLTL review could not be saved.');
          }
          var card = form.closest('[data-ghca-oltl-card]');
          if (card) card.outerHTML = json.data.html;
          ghcaToast('OLTL manual review saved.', false);
        })
        .catch(function(error) {
          if (button) button.disabled = false;
          if (status) status.textContent = error.message || 'The OLTL review could not be saved.';
        });
    });
  }

  function initManualEntry() {
    document.addEventListener('submit', function(e) {
      var form = e.target.closest('.ghca-acd__manual-entry-form, [data-ghca-manual-form]');
      if (!form) return;
      e.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      var status = form.querySelector('.ghca-acd__manual-entry-status, [data-ghca-manual-status]');
      if (button) button.disabled = true;
      if (status) status.textContent = 'Uploading…';
      fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
        .then(function(response) { return response.json(); })
        .then(function(json) {
          if (!json || !json.success || !json.data || !json.data.html) {
            throw new Error((json && json.data && json.data.message) || 'The certificate could not be saved.');
          }
          var card = form.closest('[data-ghca-manual-entry]');
          if (card) card.outerHTML = json.data.html;
          ghcaToast('Certificate saved for review.', false);
        })
        .catch(function(error) {
          if (button) button.disabled = false;
          if (status) status.textContent = error.message || 'The certificate could not be saved.';
        });
    });
  }

  function initManualEvidenceOpen() {
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('[data-ghca-manual-open]');
      if (!btn) return;
      e.preventDefault();
      var action = btn.getAttribute('data-action') === 'download' ? 'download' : 'preview';
      var trainingId = btn.getAttribute('data-ghca-manual-open');
      var title = '';
      var row = btn.closest('li');
      var strong = row ? row.querySelector('strong') : null;
      if (strong) { title = strong.textContent || ''; }

      // Grants are single-use and short-lived, so every click asks for fresh ones. A preview
      // needs two: the iframe spends one, and the modal's download button needs its own.
      function mint(access) {
        var params = new URLSearchParams();
        params.append('action', 'ghca_acd_jotform_manual_token');
        params.append('nonce', window.ghcaAcd.nonce);
        params.append('training_id', trainingId);
        params.append('file_index', '0');
        params.append('access', access);
        return fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: params })
          .then(function(response) { return response.json(); })
          .then(function(json) {
            if (!json || !json.success || !json.data || !json.data.url) {
              throw new Error((json && json.data && json.data.message) || 'The certificate could not be opened.');
            }
            return json.data.url;
          });
      }

      btn.disabled = true;
      var pending = action === 'download'
        ? mint('download').then(function(url) { window.location.assign(url); })
        : Promise.all([mint('preview'), mint('download')]).then(function(urls) {
            if (typeof window.ghcaAcdOpenCertificateModal === 'function') {
              window.ghcaAcdOpenCertificateModal(urls[0], title, urls[1], btn);
            } else {
              window.open(urls[0], '_blank', 'noopener');
            }
          });
      pending
        .catch(function(error) { ghcaToast(error.message || 'The certificate could not be opened.', true); })
        .then(function() { btn.disabled = false; });
    });
  }

  // Notices rendered by the server (sync finished, reminder logged) get the same dismiss and timing.
  document.querySelectorAll('.ghca-ui [data-ghca-toasts]').forEach(function (stack) {
    stack.querySelectorAll('.gh-toast').forEach(function (toast) { ghcaToastWire(stack, toast); });
  });

  /*
   * Redesigned Audit data tab: saves one row's first-service date, worked-alone date and CSV exclusion
   * through the same action, nonce name and fields as the legacy tab. Dates are checked against the
   * MM/DD/YYYY shape first, because the server stores anything else as empty.
   */
  function initAuditDates() {
    var mdy = /^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/[0-9]{4}$/;
    function realDate(v) {
      if (!mdy.test(v)) return false;
      var p = v.split('/'), d = new Date(+p[2], +p[0] - 1, +p[1]);
      return d.getFullYear() === +p[2] && d.getMonth() === +p[0] - 1 && d.getDate() === +p[1];
    }
    document.addEventListener('click', function (event) {
      var btn = event.target.closest('.ghca-ui [data-ghca-audit-save]');
      if (!btn || btn.disabled) return;
      var row = btn.closest('[data-ghca-audit-row]');
      var wrap = btn.closest('[data-ghca-audit-nonce]');
      if (!row || !wrap || !window.ghcaAcd || !window.ghcaAcd.ajaxUrl) return;
      var status = row.querySelector('[data-ghca-audit-status]');
      var fields = {};
      var bad = null;
      row.querySelectorAll('input[data-ghca-audit-field]').forEach(function (input) {
        var key = input.getAttribute('data-ghca-audit-field');
        if (input.type === 'checkbox') { fields[key] = input.checked ? '1' : '0'; return; }
        var v = input.value.trim();
        input.value = v;
        var ok = v === '' || realDate(v);
        input.setAttribute('aria-invalid', ok ? 'false' : 'true');
        input.classList.toggle('is-invalid', !ok);
        if (!ok && !bad) bad = input;
        fields[key] = v;
      });
      function say(text, tone) {
        if (!status) return;
        status.textContent = text;
        status.className = 'gh-small gh-aud-msg' + (tone ? ' gh-aud-msg--' + tone : '');
      }
      if (bad) {
        say('Use MM/DD/YYYY, a real date, or leave it empty. Nothing was saved.', 'error');
        bad.focus();
        return;
      }
      var label = btn.firstChild;
      var text = label.nodeValue;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      label.nodeValue = 'Saving…';
      say('', '');
      var data = new FormData();
      data.append('action', 'ghca_acd_save_audit_dates');
      data.append('ghca_nonce', wrap.getAttribute('data-ghca-audit-nonce'));
      data.append('user_id', row.getAttribute('data-ghca-audit-row'));
      data.append('first_service', fields.first_service || '');
      data.append('worked_alone', fields.worked_alone || '');
      data.append('exclude_user', fields.exclude || '0');
      fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res && res.success) { say('Saved', 'ok'); }
          else { say((res && typeof res.data === 'string' && res.data) || 'Could not save. Nothing was changed.', 'error'); }
        })
        .catch(function () { say('Could not reach the server. Nothing was saved.', 'error'); })
        .then(function () { btn.disabled = false; btn.removeAttribute('aria-busy'); label.nodeValue = text; });
    });
  }

  initTabs();
  initEmployeeDrawer();
  initEditRecordsSubpage();
  initAnnouncements();
  initManageUsers();
  initPdfPacket();
  initReminderMessaging();
  initOltlManualReview();
  initManualEntry();
  initManualEvidenceOpen();
  initAuditDates();

})();
