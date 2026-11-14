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

  /*
   * admin-ajax answers "-1" or "0" (often with 400/403) when the dashboard nonce has expired or the login
   * session ended, which used to surface as "Network error". Tell the person what actually happened.
   */
  var GHCA_SESSION_MSG = 'Your session has expired or this page is out of date. Reload the page and try again.';
  function ghcaReadJson(res) {
    return res.text().then(function (text) {
      var body = (text || '').trim();
      var json = null;
      try { json = JSON.parse(body); } catch (err) { json = null; }
      if (json !== null && typeof json === 'object') { return json; }
      var e = new Error(body === '-1' || body === '0' || res.status === 403 ? 'session' : 'network');
      e.ghcaSession = e.message === 'session';
      throw e;
    });
  }
  window.ghcaReadJson = ghcaReadJson;
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
      fields: ['ghca_cert_view', 'ghca_cert_user', 'ghca_cert_course', 'ghca_cert_search', 'ghca_cert_when', 'ghca_cert_per', 'ghca_cert_orderby', 'ghca_cert_order'],
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
        // A hidden filter (the Certificates employee filter) is cleared only when marked clearable.
        if ((el.type === 'hidden' && !el.hasAttribute('data-ghca-clearable')) || el.type === 'submit' || el.type === 'button' || el.hasAttribute('data-ghca-per-page')) { return false; }
        // Sort and the Certificates view are not filters, so clearing filters leaves them as they are.
        if (el.hasAttribute('data-ghca-sort-select') || el.hasAttribute('data-ghca-not-filter')) { return false; }
        return name === 'all' || el.name === name;
      });
      controls.forEach(function (el) {
        if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; }
        // Enhanced course menus show the cleared value too.
        if (el.tagName === 'SELECT') { el.dispatchEvent(new Event('ghca:sync')); }
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

  /*
   * Certificates tab entry points (slice 9). An employee-specific link sets that employee's filter; every other way
   * in (Reports, Overview, KPI) shows the full authorized list, so an earlier employee filter is not left behind.
   * The list refreshes through the existing filter form; the server checks the employee again.
   */
  // Sets the Certificates employee filter for an entry point. Employee-specific links pass their employee; general
  // entry points (Overview "View all", the Reports card and the Certificates tab itself, by click or keyboard) pass ''
  // so a filter left over from an earlier employee link never carries over.
  function ghcaCertScope(jump) {
    var form = document.querySelector('.ghca-ui form[data-ghca-table="certificates"]');
    var field = form && form.querySelector('[data-ghca-cert-user-field]');
    if (!field) return;
    var want = (jump && jump.getAttribute && jump.getAttribute('data-ghca-cert-user')) || '';
    if (field.value === want) return;
    field.value = want;
    var page = form.querySelector('[data-ghca-page-input]');
    if (page) page.value = '1';
    form.dispatchEvent(new Event('submit', { cancelable: true }));
  }

  function initTabs() {
    document.addEventListener('click', function(e) {
      var jump = e.target.closest('[data-ghca-tab-jump]');
      if (jump) {
        e.preventDefault();
        var jumpTarget = jump.getAttribute('data-ghca-tab-jump');
        if (jumpTarget === 'ghca-tab-certificates') { ghcaCertScope(jump); }
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

      if (targetId === 'ghca-tab-certificates') { ghcaCertScope(null); }
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
        if (nextTarget === 'ghca-tab-certificates') { ghcaCertScope(null); }
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
        // The Administration board is a wider drawer (860px); every other tab keeps the standard width.
        if (isBoard && dialog) { dialog.classList.toggle('is-admin', name === 'administration'); }
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
      if (typeof window.ghcaAcdEditAbort === 'function') { window.ghcaAcdEditAbort(); }
      if (dialog) { dialog.classList.remove('is-admin'); }
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
      .then(ghcaReadJson)
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
        var msg = err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Please check your connection and try again.';
        bodyContainer.innerHTML = errorState(msg);
        fillErrorText(msg);
      });

      if (!release) {
        release = ghcaFocusTrap(dialog, closeBtn);
      }
    }

    function closeDrawer() {
      if (typeof window.ghcaAcdEditAbort === 'function') { window.ghcaAcdEditAbort(); }
      if (dialog) { dialog.classList.remove('is-admin'); }
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
            var badgeEl = drawer.querySelector('[data-ghca-review-badge]');
            drawer.querySelectorAll('[data-ghca-review-text]').forEach(function (el) { el.textContent = json.data.line; });
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
        .then(ghcaReadJson)
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
          v.body.innerHTML = '<p class="ghca-acd__edit-error" role="alert"></p>';
          v.body.firstChild.textContent = err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Please check your connection and try again.';
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

      // The employee is the one this form was rendered for, never whichever drawer was opened last.
      var formUserId = form.getAttribute('data-user-id') || '';
      if (!formUserId || String(formUserId) !== String(currentUserId)) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
        ghcaToast('This form is out of date. Close it and open the employee again. Nothing was saved.', true);
        return;
      }
      var params = new URLSearchParams(new FormData(form));
      form.querySelectorAll('input[type="checkbox"][name$="[mark_complete]"]').forEach(function (checkbox) {
        params.set(checkbox.name, checkbox.checked ? '1' : '0');
      });
      params.append('action', 'ghca_acd_save_employee_records');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', formUserId);

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      })
        .then(ghcaReadJson)
        .then(function (json) {
          if (json && json.success) {
            var savedUserId = formUserId;
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
        .catch(function (err) {
          if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
          ghcaToast(err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Please try again.', true);
        });
    }

    // The drawer calls this when it opens or closes, so a slow form load never lands in another employee's drawer.
    window.ghcaAcdEditAbort = function () {
      if (controller) { controller.abort(); controller = null; }
      currentUserId = null;
      setWide(false);
    };

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

  /*
   * Course menus (slice 9). A native <select> popup cannot wrap long course titles and grows past its control, so
   * select[data-ghca-course-select] is enhanced into a button plus a searchable listbox that fits the viewport and
   * wraps option text. The original <select> stays in the form and keeps the value, name, required rule and change
   * events, so filtering, saving and validation are unchanged. Keyboard: Enter/Space/Arrow Down open; type to
   * filter; Arrow keys, Home and End move; Enter selects; Escape closes; focus returns to the button.
   */
  var ghcaCbxSeq = 0;
  function ghcaEnhanceCourseSelect(sel) {
    if (sel.hasAttribute('data-ghca-cbx-done')) return;
    sel.setAttribute('data-ghca-cbx-done', '');
    var id = 'ghca-cbx-' + (++ghcaCbxSeq);
    var label = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
    var wrap = document.createElement('div');
    wrap.className = 'gh-cbx';
    wrap.innerHTML = '<button type="button" class="gh-select gh-cbx-btn" aria-haspopup="listbox" aria-expanded="false"><span class="gh-cbx-val"></span></button>' +
      '<div class="gh-cbx-pop" hidden><div class="gh-input-wrap"><span class="gh-i i-search" aria-hidden="true"></span><input class="gh-input gh-cbx-q" type="text" role="combobox" aria-autocomplete="list" aria-expanded="true" autocomplete="off"></div>' +
      '<ul class="gh-cbx-list" role="listbox"></ul><p class="gh-small gh-muted gh-cbx-none" hidden>No matches.</p></div>' +
      '<p class="gh-err gh-cbx-err" hidden></p>';
    var btn = wrap.querySelector('.gh-cbx-btn');
    var val = wrap.querySelector('.gh-cbx-val');
    var pop = wrap.querySelector('.gh-cbx-pop');
    var q = wrap.querySelector('.gh-cbx-q');
    var list = wrap.querySelector('.gh-cbx-list');
    var none = wrap.querySelector('.gh-cbx-none');
    var err = wrap.querySelector('.gh-cbx-err');
    btn.id = id + '-btn';
    list.id = id + '-list';
    q.setAttribute('aria-controls', list.id);
    // Not part of the surrounding form: a second text field would stop Enter from submitting the filter form, and
    // the filter text is not a value to send.
    q.setAttribute('form', 'ghca-cbx-no-form');
    q.setAttribute('aria-label', sel.getAttribute('data-ghca-course-search') || 'Filter courses');
    q.placeholder = sel.getAttribute('data-ghca-course-search') || 'Filter courses';
    if (label) {
      if (!label.id) label.id = id + '-label';
      label.htmlFor = btn.id;
      btn.setAttribute('aria-labelledby', label.id + ' ' + btn.id);
      list.setAttribute('aria-labelledby', label.id);
    } else {
      list.setAttribute('aria-label', sel.getAttribute('aria-label') || 'Courses');
    }
    if (sel.required) { btn.setAttribute('aria-required', 'true'); }
    Array.prototype.forEach.call(sel.options, function (o, i) {
      var li = document.createElement('li');
      li.id = id + '-o' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('data-value', o.value);
      li.textContent = o.textContent;
      if (o.disabled) li.setAttribute('aria-disabled', 'true');
      list.appendChild(li);
    });
    // The native control stays in the form (value, name, required) but is taken out of view and tab order.
    sel.classList.add('gh-cbx-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    var active = -1;
    function options() { return Array.prototype.slice.call(list.children).filter(function (li) { return !li.hidden && li.getAttribute('aria-disabled') !== 'true'; }); }
    function sync() {
      var o = sel.options[sel.selectedIndex];
      val.textContent = o ? o.textContent : '';
      Array.prototype.forEach.call(list.children, function (li) { li.setAttribute('aria-selected', li.getAttribute('data-value') === sel.value ? 'true' : 'false'); });
    }
    function setActive(i) {
      var opts = options();
      Array.prototype.forEach.call(list.children, function (li) { li.classList.remove('is-active'); });
      active = opts.length ? Math.max(0, Math.min(i, opts.length - 1)) : -1;
      if (active >= 0) {
        opts[active].classList.add('is-active');
        q.setAttribute('aria-activedescendant', opts[active].id);
        if (opts[active].scrollIntoView) opts[active].scrollIntoView({ block: 'nearest' });
      } else { q.removeAttribute('aria-activedescendant'); }
    }
    function place() {
      // Fit the viewport: at least the control's width, at most 480px or the space available.
      var r = btn.getBoundingClientRect();
      var vw = document.documentElement.clientWidth;
      var width = Math.min(Math.max(r.width, 280), 480, vw - 32);
      pop.style.width = width + 'px';
      var left = Math.min(0, vw - 16 - (r.left + width));
      pop.style.left = left + 'px';
    }
    function filter() {
      var t = q.value.trim().toLowerCase();
      var shown = 0;
      Array.prototype.forEach.call(list.children, function (li) { var hit = !t || li.textContent.toLowerCase().indexOf(t) !== -1; li.hidden = !hit; if (hit) shown++; });
      none.hidden = shown > 0;
      var opts = options();
      var cur = opts.findIndex(function (li) { return li.getAttribute('data-value') === sel.value; });
      setActive(cur >= 0 && !t ? cur : 0);
    }
    function open() {
      if (!pop.hidden) return;
      pop.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      wrap.classList.add('is-open');
      q.value = '';
      place();
      filter();
      q.focus();
    }
    function close(focusBtn) {
      if (pop.hidden) return;
      pop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      wrap.classList.remove('is-open');
      if (focusBtn) btn.focus();
    }
    function choose(li) {
      if (!li || li.getAttribute('aria-disabled') === 'true') return;
      var changed = sel.value !== li.getAttribute('data-value');
      sel.value = li.getAttribute('data-value');
      sync();
      err.hidden = true;
      btn.removeAttribute('aria-invalid');
      close(true);
      if (changed) sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    btn.addEventListener('click', function () { if (pop.hidden) { open(); } else { close(true); } });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); }
    });
    q.addEventListener('input', filter);
    q.addEventListener('keydown', function (e) {
      var opts = options();
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
      else if (e.key === 'End') { e.preventDefault(); setActive(opts.length - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) choose(opts[active]); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
      else if (e.key === 'Tab') { close(false); }
    });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });
    list.addEventListener('click', function (e) { var li = e.target.closest('[role="option"]'); if (li) choose(li); });
    document.addEventListener('mousedown', function (e) { if (!wrap.contains(e.target)) close(false); });
    window.addEventListener('resize', function () { if (!pop.hidden) place(); });
    // Values set from elsewhere (chips, Clear filters, form reset) are reflected in the button.
    sel.addEventListener('change', sync);
    sel.addEventListener('ghca:sync', sync);
    if (sel.form) { sel.form.addEventListener('reset', function () { window.setTimeout(sync, 0); }); }
    // A required menu left empty: say so on the visible control instead of the hidden native one.
    sel.addEventListener('invalid', function (e) {
      e.preventDefault();
      err.textContent = 'Choose an option.';
      err.hidden = false;
      btn.setAttribute('aria-invalid', 'true');
      btn.setAttribute('aria-describedby', id + '-err');
      err.id = id + '-err';
      btn.focus();
    });
    sync();
  }
  function initCourseSelects() {
    var run = function (root) { (root || document).querySelectorAll('.ghca-ui select[data-ghca-course-select]').forEach(ghcaEnhanceCourseSelect); };
    run(document);
    if (typeof MutationObserver !== 'undefined') {
      new MutationObserver(function (records) {
        records.forEach(function (r) { r.addedNodes.forEach(function (n) { if (n.nodeType === 1 && (n.matches('select[data-ghca-course-select]') || n.querySelector('select[data-ghca-course-select]'))) { run(n.parentNode || document); } }); });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  /*
   * Certificate viewer (slice 9; Modal-CertViewer and Modal-CertViewer-States boards). The PDF is fetched from the
   * existing authorized endpoint into memory and shown from a same-page blob URL in the browser's own PDF viewer:
   * no certificate link, public URL or third-party viewer. If the browser cannot show PDFs inline the viewer says
   * so and offers the explicit Download; it never downloads on its own. Download saves the bytes already fetched.
   */
  function initCertViewer() {
    var layer = document.querySelector('.ghca-ui [data-ghca-cv]');
    if (!layer) return;
    var dialog = layer.querySelector('[data-ghca-cv-dialog]');
    var frame = layer.querySelector('[data-ghca-cv-frame]');
    var bar = layer.querySelector('[data-ghca-cv-bar]');
    var titleEl = layer.querySelector('[data-ghca-cv-heading]');
    var subEl = layer.querySelector('[data-ghca-cv-sub]');
    var dl = layer.querySelector('[data-ghca-cv-dl]');
    var chip = layer.querySelector('[data-ghca-cv-chip]');
    var msg = layer.querySelector('[data-ghca-cv-msg]');
    var pagesEl = layer.querySelector('[data-ghca-cv-pages]');
    var zoomEl = layer.querySelector('[data-ghca-cv-zoomval]');
    var st = { trigger: null, focus: null, mint: null, kind: 'certificate', url: '', blob: null, objectUrl: '', file: 'certificate.pdf', page: 1, pages: 1, zoom: 100, fit: true, aspect: 0, widthPt: 0, request: 0, release: null, user: '' };

    function show(state) {
      layer.querySelectorAll('[data-ghca-cv-pane]').forEach(function (el) { el.hidden = el.getAttribute('data-ghca-cv-pane') !== state; });
      layer.setAttribute('data-ghca-cv-current', state);
      var ready = state === 'ready';
      // Preview controls work only on a shown PDF; Download needs fetched bytes; pending has neither.
      bar.hidden = state === 'pending';
      bar.querySelectorAll('button').forEach(function (b) { b.disabled = !ready; });
      layer.querySelectorAll('[data-ghca-cv-newtab]').forEach(function (b) { b.disabled = !st.blob; b.hidden = state === 'pending'; });
      layer.querySelectorAll('.gh-cv-ft [data-ghca-cv-download]').forEach(function (b) { b.disabled = !st.blob; b.hidden = state === 'pending'; });
      if (ready) {
        bar.querySelector('[data-ghca-cv-page="prev"]').disabled = st.page <= 1;
        bar.querySelector('[data-ghca-cv-page="next"]').disabled = st.page >= st.pages;
      }
    }
    function details(t) {
      // Employee documents (drawer Documents tab): the document's own facts instead of course facts.
      var rows = st.kind === 'document' ? [
        ['Employee', t.getAttribute('data-ghca-cv-employee')],
        ['Form', t.getAttribute('data-ghca-cv-context')],
        ['Date', t.getAttribute('data-ghca-cv-date')],
        ['Review', t.getAttribute('data-ghca-cv-status')],
        ['Source', t.getAttribute('data-ghca-cv-source')],
        ['File', '']
      ] : [
        ['Employee', t.getAttribute('data-ghca-cv-employee')],
        ['Group', t.getAttribute('data-ghca-cv-group')],
        ['Course', t.getAttribute('data-ghca-cv-title')],
        ['Completed', t.getAttribute('data-ghca-cv-completed')],
        ['Expires', t.getAttribute('data-ghca-cv-expires')],
        ['Source', t.getAttribute('data-ghca-cv-source')],
        ['File', '']
      ];
      dl.innerHTML = '';
      rows.forEach(function (r) {
        if (r[0] !== 'File' && !r[1]) return;
        var d = document.createElement('div');
        var dt = document.createElement('dt'); dt.textContent = r[0];
        var dd = document.createElement('dd'); dd.textContent = r[1] || '—';
        if (r[0] === 'File') { dd.setAttribute('data-ghca-cv-file', ''); }
        d.appendChild(dt); d.appendChild(dd); dl.appendChild(d);
      });
    }
    // Fit to width: the frame is as tall as the page at the frame's width (never taller than the stage allows), so a
    // landscape certificate is shown whole; a taller page scrolls vertically, never sideways.
    function fitHeight() {
      if (!st.fit || !st.aspect || frame.hidden) { frame.style.height = ''; return; }
      frame.style.height = '';
      var max = frame.clientHeight;
      var w = frame.clientWidth;
      if (!max || !w) return;
      frame.style.height = Math.min(max, Math.round(w * st.aspect) + 6) + 'px';
    }
    function frameSrc() {
      if (!st.objectUrl) return;
      // The browser's PDF viewer reads the page and zoom from the fragment only when it loads a document: changing
      // just the fragment of a loaded PDF is ignored (Chromium), so every change loads a fresh frame. Its own
      // thumbnail panel and toolbar are turned off (navpanes, toolbar): they took about half of the frame's width,
      // so even "fit" showed a small page, and this viewer has its own page, zoom, fit and print controls.
      // view=FitH is Chromium's fit to width; zoom=page-width is Firefox's (pdf.js); each ignores the other.
      var fresh = frame.cloneNode(false);
      fresh.hidden = frame.hidden;
      fresh.src = st.objectUrl + '#page=' + st.page + '&navpanes=0&toolbar=0' + (st.fit ? '&view=FitH&zoom=page-width' : '&zoom=' + st.zoom);
      frame.parentNode.replaceChild(fresh, frame);
      frame = fresh;
      fitHeight();
      zoomEl.textContent = st.fit ? 'Fit' : st.zoom + '%';
      pagesEl.textContent = st.page + ' / ' + st.pages;
    }
    // Page size of the first page from its MediaBox (rotation swaps the sides), for the fit height and for the zoom
    // steps that follow "Fit". Unknown sizes fall back to the CSS height.
    function pageBox(buf) {
      try {
        var text = new TextDecoder('latin1').decode(new Uint8Array(buf));
        var m = text.match(/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/);
        if (!m) return null;
        var w = Math.abs(parseFloat(m[3]) - parseFloat(m[1]));
        var h = Math.abs(parseFloat(m[4]) - parseFloat(m[2]));
        var r = text.match(/\/Rotate\s+(-?\d+)/);
        if (r && Math.abs(parseInt(r[1], 10)) % 180 === 90) { var t = w; w = h; h = t; }
        return w > 0 && h > 0 ? { w: w, h: h } : null;
      } catch (e) { return null; }
    }
    function countPages(buf) {
      try {
        var text = new TextDecoder('latin1').decode(new Uint8Array(buf));
        var m = text.match(/\/Type\s*\/Page(?![a-z])/g);
        return m && m.length ? m.length : 1;
      } catch (e) { return 1; }
    }
    function cleanup() {
      st.request++;
      frame.removeAttribute('src');
      frame.hidden = true;
      frame.style.height = '';
      if (st.objectUrl) { URL.revokeObjectURL(st.objectUrl); }
      st.objectUrl = ''; st.blob = null;
    }
    function load() {
      var mine = ++st.request;
      st.blob = null;
      msg.textContent = '';
      show('loading');
      // Employee documents use single-use access grants: every load (and Try again) asks for a fresh preview grant,
      // which the server checks and audits as a preview, exactly as the previous document preview did.
      var source = st.mint ? st.mint('preview') : Promise.resolve(st.url);
      source.then(function (raw) {
        var url;
        try { url = new URL(raw, window.location.href); } catch (e) { url = null; }
        if (!url || url.origin !== window.location.origin) { throw new Error('origin'); }
        return fetch(url.href, { credentials: 'same-origin' });
      }).then(function (res) {
        var type = res.headers.get('Content-Type') || '';
        return res.arrayBuffer().then(function (buf) { return { res: res, type: type, buf: buf }; });
      }).then(function (r) {
        if (mine !== st.request) return;
        if (r.res.status === 403 || r.res.status === 400) { show('denied'); return; }
        if (r.res.status === 404) { show('unavailable'); return; }
        if (!r.res.ok || !ghcaCertificateResponseIsPdf(r.type, new Uint8Array(r.buf.slice(0, 5)))) { show('error'); return; }
        st.blob = new Blob([r.buf], { type: 'application/pdf' });
        st.objectUrl = URL.createObjectURL(st.blob);
        st.pages = countPages(r.buf);
        st.page = 1; st.zoom = 100; st.fit = true;
        var box = pageBox(r.buf);
        st.aspect = box ? box.h / box.w : 0;
        st.widthPt = box ? box.w : 0;
        var fileDd = layer.querySelector('[data-ghca-cv-file]');
        if (fileDd) { fileDd.textContent = 'PDF · ' + st.pages + (st.pages === 1 ? ' page · ' : ' pages · ') + Math.max(1, Math.round(r.buf.byteLength / 1024)) + ' KB'; }
        if (navigator.pdfViewerEnabled === false) { show('blocked'); return; }
        frame.hidden = false;
        show('ready'); // first, so the frame has its final width (no loading panel beside it) when it is sized
        frameSrc();
      }).catch(function () {
        if (mine === st.request) show('error');
      });
    }
    function open(t, opt) {
      cleanup();
      opt = opt || {};
      st.trigger = t;
      st.focus = opt.focus || t;
      st.mint = typeof opt.mint === 'function' ? opt.mint : null;
      st.kind = st.mint ? 'document' : 'certificate';
      st.url = t.getAttribute('data-ghca-cv-url') || '';
      st.user = t.getAttribute('data-ghca-cv-user') || '';
      st.file = t.getAttribute('data-ghca-cv-file') || 'certificate.pdf';
      var title = t.getAttribute('data-ghca-cv-title') || '';
      var pending = t.getAttribute('data-ghca-cv-state') === 'pending';
      titleEl.textContent = (st.kind === 'document' ? 'Document · ' : 'Certificate · ') + title;
      // Side heading and note: certificate wording by default, document wording for employee documents.
      layer.querySelectorAll('[data-ghca-cv-side-h], [data-ghca-cv-note]').forEach(function (el) {
        if (!el.hasAttribute('data-ghca-cv-cert-text')) { el.setAttribute('data-ghca-cv-cert-text', el.textContent); }
        el.textContent = el.getAttribute(st.kind === 'document' ? 'data-ghca-cv-doc-text' : 'data-ghca-cv-cert-text');
      });
      subEl.textContent = (t.getAttribute('data-ghca-cv-employee') || '') + ' · ' + (pending ? ('completed ' + (t.getAttribute('data-ghca-cv-completed') || '') + ' · no certificate file yet') : st.file);
      if (st.kind === 'document') {
        chip.className = 'gh-st gh-st--' + (t.getAttribute('data-ghca-cv-tone') || 'progress');
        chip.textContent = t.getAttribute('data-ghca-cv-status') || 'Document';
      } else {
        chip.className = 'gh-st ' + (pending ? 'gh-st--duesoon' : 'gh-st--completed');
        chip.textContent = pending ? 'Not generated' : 'Issued';
      }
      details(t);
      layer.hidden = false;
      layer.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--modal-open');
      ghcaAcdOverlay.open(layer, st.focus, close);
      // Start on the close control that is shown at this width (top-left on phones, top-right otherwise).
      var closers = Array.prototype.filter.call(dialog.querySelectorAll('.gh-cv-x, .gh-cv-x-phone'), function (b) { return b.offsetParent !== null; });
      st.release = ghcaFocusTrap(dialog, closers[0] || dialog.querySelector('[data-ghca-cv-close]'));
      if (pending) { show('pending'); } else { load(); }
    }
    function close() {
      if (layer.hidden) return;
      cleanup();
      layer.hidden = true;
      layer.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--modal-open');
      ghcaAcdOverlay.close(layer);
      if (st.release) { st.release(); st.release = null; }
      if (st.focus && st.focus.isConnected) { st.focus.focus(); }
    }
    function save() {
      if (st.mint) {
        // A document download keeps its own grant (checked and audited as a download, sent as an attachment):
        // the preview bytes are not reused for it.
        msg.textContent = 'Preparing the download…';
        st.mint('download').then(function (u) {
          window.location.assign(u);
          msg.textContent = 'Download started: ' + st.file + '.';
        }, function () {
          msg.textContent = 'The download could not be started. Try again.';
        });
        return;
      }
      if (!st.blob) return;
      var u = URL.createObjectURL(st.blob);
      var a = document.createElement('a');
      a.href = u; a.download = st.file;
      document.body.appendChild(a); a.click(); a.remove();
      window.setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
      msg.textContent = 'Saved ' + st.file + '.';
    }
    document.addEventListener('click', function (e) {
      var t = e.target.closest('.ghca-ui [data-ghca-cv-open]');
      if (t) { e.preventDefault(); open(t); return; }
      if (!layer.contains(e.target) || layer.hidden) return;
      if (e.target.closest('[data-ghca-cv-close]')) { e.preventDefault(); close(); return; }
      if (e.target.closest('[data-ghca-cv-download]')) { e.preventDefault(); save(); return; }
      if (e.target.closest('[data-ghca-cv-retry]')) { e.preventDefault(); load(); return; }
      if (e.target.closest('[data-ghca-cv-newtab]')) {
        e.preventDefault();
        if (st.objectUrl) { window.open(st.objectUrl, '_blank', 'noopener'); }
        return;
      }
      if (e.target.closest('[data-ghca-cv-print]')) {
        e.preventDefault();
        try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (err) { msg.textContent = 'This browser can’t print from here. Use Open in new tab, then print.'; }
        return;
      }
      var z = e.target.closest('[data-ghca-cv-zoom]');
      if (z) {
        var k = z.getAttribute('data-ghca-cv-zoom');
        if (k === 'fit') { st.fit = true; }
        else {
          if (st.fit) {
            // Leaving "Fit": step from the size the page is shown at now (100% = 96 px per inch), in 25% steps.
            var now = st.widthPt ? frame.clientWidth / (st.widthPt * 96 / 72) * 100 : 100;
            st.zoom = k === 'in' ? Math.floor(now / 25) * 25 + 25 : Math.ceil(now / 25) * 25 - 25;
          } else {
            st.zoom += k === 'in' ? 25 : -25;
          }
          st.fit = false;
          st.zoom = Math.max(25, Math.min(300, st.zoom));
        }
        frameSrc(); return;
      }
      var p = e.target.closest('[data-ghca-cv-page]');
      if (p) {
        st.page = Math.max(1, Math.min(st.pages, st.page + (p.getAttribute('data-ghca-cv-page') === 'next' ? 1 : -1)));
        frameSrc(); show('ready'); return;
      }
      if (e.target.closest('[data-ghca-cv-to-employee]')) {
        e.preventDefault();
        var who = st.user;
        close();
        var opener = document.querySelector('.ghca-ui [data-ghca-employee-drawer="' + who + '"]');
        if (opener) { opener.click(); }
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !layer.hidden && ghcaAcdOverlay.isTop(layer)) { e.preventDefault(); close(); }
    });
    // Keep the fit height right whenever the stage changes width (window resize, details panel moving below).
    var stage = frame.parentNode;
    if (window.ResizeObserver) {
      var lastW = 0;
      new ResizeObserver(function () { var w2 = stage.clientWidth; if (w2 !== lastW) { lastW = w2; if (!layer.hidden) { fitHeight(); } } }).observe(stage);
    } else {
      window.addEventListener('resize', function () { if (!layer.hidden) { fitHeight(); } });
    }
    // Employee documents (drawer Documents tab and its "View all documents" list): PDFs open here. `mint(access)`
    // returns a promise of a fresh single-use grant URL for 'preview' or 'download'.
    function openDocument(o) {
      var t = document.createElement('span');
      var set = function (k, v) { if (v) { t.setAttribute('data-ghca-cv-' + k, String(v)); } };
      set('title', o.title); set('employee', o.employee); set('context', o.context); set('date', o.date);
      set('status', o.status); set('tone', o.tone); set('source', o.source); set('file', o.file || o.title || 'document.pdf');
      open(t, { focus: o.trigger, mint: o.mint });
    }
    window.ghcaAcdCertViewer = { open: open, close: close, openDocument: openDocument };
  }

  /*
   * Certificates tab selection and ZIP (slice 9). The selection is kept by "user:course" across filtering and paging.
   * Selected certificates the current view does not show are counted and named in the bar; preparing a ZIP that
   * would include them asks first (all, or only those shown), so hidden items are never included by accident.
   * The server re-authorizes every item and refuses the whole request if any is not allowed.
   */
  function initCertSelection() {
    var bar = document.querySelector('.ghca-ui [data-ghca-cert-bar]');
    var mount = document.querySelector('.ghca-ui [data-ghca-table-id="certificates"]');
    if (!bar || !mount) return;
    var selected = {};
    var order = [];
    var busy = false;
    var countEl = bar.querySelector('[data-ghca-cert-count]');
    var hiddenEl = bar.querySelector('[data-ghca-cert-hidden]');
    var keepBtn = bar.querySelector('[data-ghca-cert-keep]');
    var zipBtn = bar.querySelector('[data-ghca-cert-zip]');
    var zipLabel = bar.querySelector('[data-ghca-cert-zip-label]');
    var confirmBox = bar.querySelector('[data-ghca-cert-confirm]');
    var msgEl = bar.querySelector('[data-ghca-cert-msg]');

    function shownKeys() {
      var keys = {};
      mount.querySelectorAll('[data-ghca-cert-select]').forEach(function (cb) { keys[cb.value] = true; });
      return keys;
    }
    function keys() { return order.filter(function (k) { return selected[k]; }); }
    function hiddenKeys() { var shown = shownKeys(); return keys().filter(function (k) { return !shown[k]; }); }
    function message(text, tone) {
      msgEl.textContent = text || '';
      msgEl.hidden = !text;
      msgEl.className = 'gh-small gh-certbar-msg' + (tone ? ' gh-certbar-msg--' + tone : '');
      msgEl.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    }
    function sync() {
      var all = keys();
      mount.querySelectorAll('[data-ghca-cert-select]').forEach(function (cb) { cb.checked = !!selected[cb.value]; });
      var boxes = Array.prototype.slice.call(mount.querySelectorAll('.gh-tbl [data-ghca-cert-select]'));
      var head = mount.querySelector('[data-ghca-cert-select-page]');
      if (head) {
        var on = boxes.filter(function (b) { return b.checked; }).length;
        head.checked = boxes.length > 0 && on === boxes.length;
        head.indeterminate = on > 0 && on < boxes.length;
      }
      var hidden = hiddenKeys();
      bar.hidden = all.length === 0 && msgEl.hidden;
      countEl.textContent = all.length === 1 ? '1 certificate selected' : all.length + ' certificates selected';
      if (hidden.length) {
        var names = hidden.slice(0, 3).map(function (k) { return selected[k]; }).join('; ');
        hiddenEl.textContent = hidden.length + ' not in the current view: ' + names + (hidden.length > 3 ? '; and ' + (hidden.length - 3) + ' more' : '') + '.';
        hiddenEl.hidden = false;
        keepBtn.hidden = false;
      } else {
        hiddenEl.hidden = true;
        keepBtn.hidden = true;
      }
      zipLabel.textContent = all.length > 1 ? 'Download ' + all.length + ' as ZIP' : 'Download selected as ZIP';
      zipBtn.disabled = busy || all.length === 0;
    }
    function toggle(key, label, on) {
      if (on) { if (!selected[key]) { order.push(key); } selected[key] = label || key; }
      else { delete selected[key]; order = order.filter(function (k) { return k !== key; }); }
    }
    function zip(list) {
      if (busy || !list.length) return;
      busy = true;
      confirmBox.hidden = true;
      zipBtn.setAttribute('aria-busy', 'true');
      message('Preparing a ZIP of ' + list.length + (list.length === 1 ? ' certificate…' : ' certificates…'), '');
      sync();
      var p = new URLSearchParams();
      p.append('action', 'ghca_acd_cert_zip');
      p.append('nonce', window.ghcaAcd.nonce);
      list.forEach(function (k) { p.append('items[]', k); });
      fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: p.toString() })
        .then(ghcaReadJson)
        .then(function (json) {
          if (!json || !json.success || !json.data || !json.data.download_url) {
            throw { shown: (json && json.data && json.data.message) || 'The ZIP could not be prepared. Nothing was downloaded.' };
          }
          var d = json.data;
          return fetch(d.download_url, { credentials: 'same-origin' }).then(function (res) {
            var type = res.headers.get('Content-Type') || '';
            return res.arrayBuffer().then(function (buf) {
              var b = new Uint8Array(buf.slice(0, 2));
              if (!res.ok || type.indexOf('application/zip') !== 0 || b[0] !== 0x50 || b[1] !== 0x4b) {
                var text = '';
                try { text = JSON.parse(new TextDecoder().decode(new Uint8Array(buf))).data.message; } catch (e) { text = ''; }
                throw { shown: (text || 'The ZIP could not be downloaded.') + ' Nothing was saved.' };
              }
              var u = URL.createObjectURL(new Blob([buf], { type: 'application/zip' }));
              var a = document.createElement('a');
              a.href = u; a.download = d.name || 'certificates.zip';
              document.body.appendChild(a); a.click(); a.remove();
              window.setTimeout(function () { URL.revokeObjectURL(u); }, 30000);
              message('Saved ' + (d.name || 'certificates.zip') + ' with ' + d.count + (d.count === 1 ? ' certificate.' : ' certificates.') + ' The server copy has been deleted.', 'success');
            });
          });
        })
        .catch(function (err) {
          message(err && err.shown ? err.shown : (err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Nothing was downloaded; the selection is kept.'), 'error');
        })
        .then(function () {
          busy = false;
          zipBtn.removeAttribute('aria-busy');
          sync();
        });
    }
    mount.addEventListener('change', function (e) {
      var cb = e.target.closest('[data-ghca-cert-select]');
      if (cb) { toggle(cb.value, cb.getAttribute('data-label'), cb.checked); message('', ''); sync(); return; }
      var head = e.target.closest('[data-ghca-cert-select-page]');
      if (head) {
        mount.querySelectorAll('.gh-tbl [data-ghca-cert-select]').forEach(function (b) { toggle(b.value, b.getAttribute('data-label'), head.checked); });
        message('', ''); sync();
      }
    });
    bar.addEventListener('click', function (e) {
      if (e.target.closest('[data-ghca-cert-clear]')) { selected = {}; order = []; confirmBox.hidden = true; message('', ''); sync(); return; }
      if (e.target.closest('[data-ghca-cert-keep]')) { hiddenKeys().forEach(function (k) { toggle(k, '', false); }); confirmBox.hidden = true; sync(); return; }
      if (e.target.closest('[data-ghca-cert-zip-cancel]')) { confirmBox.hidden = true; zipBtn.focus(); return; }
      if (e.target.closest('[data-ghca-cert-zip-all]')) { zip(keys()); return; }
      if (e.target.closest('[data-ghca-cert-zip-shown]')) { var shown = shownKeys(); zip(keys().filter(function (k) { return shown[k]; })); return; }
      if (e.target.closest('[data-ghca-cert-zip]')) {
        var hidden = hiddenKeys();
        if (hidden.length) {
          var total = keys().length;
          bar.querySelector('[data-ghca-cert-confirm-text]').textContent = hidden.length + ' of the ' + total + ' selected certificates are not in the current view. Include them?';
          bar.querySelector('[data-ghca-cert-zip-all]').textContent = 'Include all ' + total;
          var visible = total - hidden.length;
          var shownBtn = bar.querySelector('[data-ghca-cert-zip-shown]');
          shownBtn.textContent = 'Only the ' + visible + ' shown';
          shownBtn.disabled = visible === 0;
          confirmBox.hidden = false;
          bar.querySelector('[data-ghca-cert-zip-all]').focus();
          return;
        }
        zip(keys());
      }
    });
    // The table is replaced when filters or the page change; the selection is applied to the new rows.
    if (typeof MutationObserver !== 'undefined') { new MutationObserver(sync).observe(mount, { childList: true }); }
    sync();
  }

  /*
   * Inline User training report (slice 8, Admin-UserReport board). "Open report" on the Reports tab shows the
   * report view in place of the report cards; choosing an employee asks the server for that employee's report
   * (the same permission checks as the /user-report/ page, every time). "Back to reports" returns to the cards.
   * Responses are kept only if they are for the employee most recently chosen.
   */
  function initInlineReport() {
    var view = document.querySelector('.ghca-ui [data-ghca-report]');
    var home = document.querySelector('.ghca-ui [data-ghca-report-home]');
    if (!view || !home) return;
    var input = view.querySelector('[data-ghca-report-input]');
    var list = view.querySelector('[data-ghca-report-list]');
    var none = view.querySelector('[data-ghca-report-none]');
    var body = view.querySelector('[data-ghca-report-body]');
    var status = view.querySelector('[data-ghca-report-status]');
    var options = Array.prototype.slice.call(list.querySelectorAll('[data-ghca-report-option]'));
    var active = -1;
    var current = '';
    var request = 0;
    var opener = null;
    var emptyHtml = body.innerHTML;

    function visible() { return options.filter(function (o) { return !o.hidden; }); }
    function setActive(i) {
      var vis = visible();
      options.forEach(function (o) { o.classList.remove('is-active'); o.setAttribute('aria-selected', 'false'); });
      active = vis.length ? Math.max(0, Math.min(i, vis.length - 1)) : -1;
      if (active >= 0) {
        vis[active].classList.add('is-active');
        vis[active].setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', vis[active].id);
        vis[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }
    function openList() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
    function closeList() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
    function filter() {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      options.forEach(function (o) { var hit = !q || o.getAttribute('data-label').toLowerCase().indexOf(q) !== -1; o.hidden = !hit; if (hit) shown++; });
      if (none) none.hidden = shown > 0;
      openList();
      setActive(0);
    }
    function show(on, from) {
      view.hidden = !on;
      home.hidden = on;
      if (on) { opener = from || opener; input.focus(); }
      else if (opener) { opener.focus(); }
    }
    function load(id, label) {
      current = String(id);
      var mine = ++request;
      input.value = label;
      closeList();
      body.setAttribute('aria-busy', 'true');
      status.textContent = 'Loading the report for ' + label + '…';
      var p = new URLSearchParams();
      p.append('action', 'ghca_acd_user_report');
      p.append('user_id', current);
      p.append('nonce', window.ghcaAcd.nonce);
      fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: p.toString() })
        .then(ghcaReadJson)
        .then(function (json) {
          if (mine !== request) return;
          body.removeAttribute('aria-busy');
          if (json && json.success && json.data && typeof json.data.html === 'string' && String(json.data.user_id) === current) {
            body.innerHTML = json.data.html;
            status.textContent = 'Showing the training report for ' + (json.data.name || label) + '.';
            return;
          }
          body.innerHTML = '<div class="gh-alert gh-alert--danger" role="alert"><div class="gh-alert-body"><span class="gh-alert-t"></span></div></div>';
          body.querySelector('.gh-alert-t').textContent = (json && json.data && json.data.message) || 'The report could not be loaded.';
          status.textContent = '';
        })
        .catch(function (err) {
          if (mine !== request) return;
          body.removeAttribute('aria-busy');
          body.innerHTML = '<div class="gh-alert gh-alert--danger" role="alert"><div class="gh-alert-body"><span class="gh-alert-t"></span></div></div>';
          body.querySelector('.gh-alert-t').textContent = err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Choose the employee again to retry.';
          status.textContent = '';
        });
    }
    function choose(o) { if (o) load(o.getAttribute('data-ghca-report-option'), o.getAttribute('data-label')); }

    document.addEventListener('click', function (e) {
      var open = e.target.closest('.ghca-ui [data-ghca-report-open]');
      if (open) { e.preventDefault(); show(true, open); return; }
      if (e.target.closest('[data-ghca-report-back]')) { e.preventDefault(); show(false); return; }
      if (e.target.closest('[data-ghca-report-print]')) {
        e.preventDefault();
        document.documentElement.classList.add('ghca-print-report');
        window.print();
        window.setTimeout(function () { document.documentElement.classList.remove('ghca-print-report'); }, 0);
        return;
      }
      var opt = e.target.closest('[data-ghca-report-option]');
      if (opt && list.contains(opt)) { e.preventDefault(); choose(opt); input.focus(); return; }
      if (!e.target.closest('[data-ghca-report-picker]')) closeList();
    });
    input.addEventListener('input', filter);
    input.addEventListener('focus', function () { if (!input.value) filter(); });
    input.addEventListener('keydown', function (e) {
      var vis = visible();
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { filter(); } else { setActive(active + 1); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Home' && !list.hidden) { e.preventDefault(); setActive(0); }
      else if (e.key === 'End' && !list.hidden) { e.preventDefault(); setActive(vis.length - 1); }
      else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); choose(vis[active]); } }
      else if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); e.stopPropagation(); closeList(); } }
    });
    // Leaving the Reports tab and coming back starts from the cards again; a chosen report stays loaded.
    view.addEventListener('ghca-report-reset', function () { body.innerHTML = emptyHtml; input.value = ''; current = ''; });
  }

  // Overview lists (Compliance by group, Announcements): show a fixed number of items and scroll only the list body
  // beyond that; the card heading stays put. The height is measured from the items themselves, because rows grow
  // when a long group name or announcement wraps, so exactly N items are visible at any width.
  function ghcaOvpCap(body) {
    var n = parseInt(body.getAttribute('data-ghca-ovp-cap'), 10) || 0;
    var items = body.querySelectorAll(body.getAttribute('data-ghca-ovp-items') || ':scope > *');
    body.style.maxHeight = '';
    if (!n || items.length <= n || !body.clientWidth) {
      body.classList.remove('gh-ovp-scroll');
      body.removeAttribute('tabindex');
      body.removeAttribute('role');
      body.removeAttribute('aria-label');
      return;
    }
    // End exactly at the bottom of the Nth item: no part of the next one shows below it.
    var top = body.getBoundingClientRect().top;
    var last = items[n - 1].getBoundingClientRect().bottom;
    body.style.maxHeight = Math.floor(last - top) + 'px';
    body.classList.add('gh-ovp-scroll');
    // Keyboard users can scroll it even when the list has nothing focusable (read-only announcements).
    body.setAttribute('tabindex', '0');
    body.setAttribute('role', 'region');
    body.setAttribute('aria-label', body.getAttribute('data-ghca-ovp-label') || 'Scrollable list');
  }
  // Equal card heights (desktop): where the grid stretches a card taller than its capped list (Announcements beside a
  // taller Compliance by group, or the other way round), the list shows as many more WHOLE items as fit in the
  // card. Its height never exceeds the room the card already has, so the row height does not change, nothing is
  // clipped and nothing of a next item shows. Short lists stay as they are. Stacked layouts do not stretch cards,
  // so they keep the base caps.
  function ghcaOvpFill(body) {
    if (!body.classList.contains('gh-ovp-scroll') || !body.clientWidth) return;
    var card = body.closest('.gh-card');
    if (!card) return;
    var items = body.querySelectorAll(body.getAttribute('data-ghca-ovp-items') || ':scope > *');
    var bTop = body.getBoundingClientRect().top;
    var cs = getComputedStyle(card);
    var room = card.getBoundingClientRect().bottom - (parseFloat(cs.borderBottomWidth) || 0) - (parseFloat(cs.paddingBottom) || 0) - bTop;
    if (room <= body.getBoundingClientRect().height + 1) return; // the card is not taller than the list
    var fit = 0, edge = 0;
    for (var i = 0; i < items.length; i++) {
      var bottom = items[i].getBoundingClientRect().bottom - bTop + body.scrollTop;
      if (bottom > room + 0.5) break;
      fit = i + 1; edge = bottom;
    }
    if (fit >= items.length) {
      // Everything fits once the card's height is used: no scroll region needed.
      body.style.maxHeight = '';
      body.classList.remove('gh-ovp-scroll');
      body.removeAttribute('tabindex'); body.removeAttribute('role'); body.removeAttribute('aria-label');
      return;
    }
    body.style.maxHeight = Math.floor(edge) + 'px';
  }
  function ghcaOvpLayout(grid) {
    var bodies = Array.prototype.slice.call(grid.querySelectorAll('[data-ghca-ovp-cap]'));
    var kept = bodies.map(function (b) { return b.scrollTop; });
    bodies.forEach(function (b) { ghcaOvpCap(b); });   // 1. base caps: 6 group rows, 3 notes
    bodies.forEach(function (b) { ghcaOvpFill(b); });  // 2. use the height the equal-height row gives each card
    bodies.forEach(function (b, i) { if (kept[i]) { b.scrollTop = kept[i]; } });
  }
  function initOverviewCaps() {
    document.querySelectorAll('.ghca-ui .gh-ovp').forEach(function (grid) {
      if (!grid.querySelector('[data-ghca-ovp-cap]')) return;
      var lastW = -1;
      var run = function () { lastW = grid.clientWidth; ghcaOvpLayout(grid); };
      run();
      // Re-measure when the width changes (resize, layout switch, the Overview tab becoming visible), when a list is
      // re-rendered (an announcement added, edited or deleted) and once web fonts have loaded.
      if (window.ResizeObserver) {
        new ResizeObserver(function () { if (grid.clientWidth !== lastW) { run(); } }).observe(grid);
      } else {
        window.addEventListener('resize', run);
      }
      if (window.MutationObserver) {
        grid.querySelectorAll('[data-ghca-ovp-cap]').forEach(function (body) {
          new MutationObserver(run).observe(body, { childList: true, subtree: true, characterData: true });
        });
      }
      if (document.fonts && document.fonts.ready) { document.fonts.ready.then(run); }
    });
  }

  function initAnnouncements() {
    var modal = document.getElementById('ghca-acd-announce-modal');
    var listEl = document.querySelector('[data-ghca-announce-list]');
    if (!modal || !listEl) return;

    // Slice 8: the Modal-Announcement board (inside the themed wrapper) or the legacy footer modal.
    var isBoard = modal.getAttribute('data-ghca-ui') === 'board';
    var dialog = modal.querySelector('[data-ghca-announce-dialog], .ghca-acd__edit-modal-dialog');
    var form = modal.querySelector('[data-ghca-announce-form]');
    var titleEl = document.getElementById('ghca-acd-announce-modal-title');
    var saveBtn = modal.querySelector('[data-ghca-announce-save], .ghca-acd__edit-btn--save');
    var confirmLayer = document.getElementById('ghca-acd-announce-confirm');
    var release = null;
    var confirmRelease = null;
    var opener = null;
    var pendingDelete = null;
    var DRAFT = 'ghca_acd_announce_draft';
    var FIELDS = ['announce_id', 'title', 'body', 'type', 'url', 'audience', 'audience_group'];

    function field(name) { return form.elements[name]; }
    function setField(name, value) {
      var el = field(name);
      if (el) el.value = value || '';
    }
    function setType(slug) {
      slug = slug || 'update';
      setField('type', slug);
      var seg = modal.querySelector('[data-ghca-announce-types]');
      if (!seg) return;
      var tmp = seg.querySelector('[data-ghca-announce-temp]');
      if (tmp) tmp.remove();
      var hit = false;
      seg.querySelectorAll('[data-ghca-announce-type]').forEach(function (b) { var on = b.getAttribute('data-ghca-announce-type') === slug; b.setAttribute('aria-pressed', on ? 'true' : 'false'); hit = hit || on; });
      if (!hit) {
        // An announcement saved with a type the board does not offer (System) keeps it while edited.
        var b = document.createElement('button');
        b.type = 'button'; b.setAttribute('aria-pressed', 'true'); b.setAttribute('data-ghca-announce-type', slug); b.setAttribute('data-ghca-announce-temp', '1');
        b.textContent = slug.charAt(0).toUpperCase() + slug.slice(1);
        seg.appendChild(b);
      }
    }
    function syncAudience() {
      var wrap = modal.querySelector('[data-ghca-announce-group-field]');
      if (!wrap || !field('audience')) return;
      var on = field('audience').value === 'group';
      wrap.hidden = !on;
      if (field('audience_group')) field('audience_group').required = on;
    }
    function errs(show) {
      var t = modal.querySelector('[data-ghca-announce-title-err]');
      var u = modal.querySelector('[data-ghca-announce-url-err]');
      if (t) { t.hidden = !show.title; field('title').toggleAttribute('aria-invalid', !!show.title); }
      if (u) { u.hidden = !show.url; field('url').toggleAttribute('aria-invalid', !!show.url); }
    }
    function readDraft() { try { return JSON.parse(window.localStorage.getItem(DRAFT) || 'null'); } catch (e) { return null; } }
    function saveDraft() {
      var d = {};
      FIELDS.forEach(function (n) { var el = field(n); if (el) d[n] = el.value; });
      try { window.localStorage.setItem(DRAFT, JSON.stringify(d)); } catch (e) { /* storage unavailable: the open form still holds the text */ }
    }
    function clearDraft() { try { window.localStorage.removeItem(DRAFT); } catch (e) { /* nothing stored */ } }

    function openModal(mode, data, from) {
      data = data || {};
      opener = from || document.activeElement;
      form.reset();
      setField('announce_id', mode === 'edit' ? data.id : '0');
      var note = modal.querySelector('[data-ghca-announce-draft-note]');
      if (note) note.hidden = true;
      if (mode === 'edit') {
        setField('title', data.title);
        setField('body', data.body);
        setField('url', data.url);
        setField('audience', data.audience || 'all');
        setField('audience_group', data.group && data.group !== '0' ? data.group : '');
        setType(data.type || 'update');
      } else {
        setType('update');
        var draft = isBoard ? readDraft() : null;
        if (draft && draft.announce_id === '0') {
          FIELDS.forEach(function (n) { if (n !== 'type' && draft[n] !== undefined) setField(n, draft[n]); });
          setType(draft.type);
          if (note) note.hidden = false;
        }
      }
      syncAudience();
      errs({});
      if (titleEl) titleEl.textContent = mode === 'edit' ? (isBoard ? 'Edit announcement' : 'Edit Announcement') : (isBoard ? 'Add announcement' : 'Add Announcement');
      if (saveBtn) saveBtn.textContent = mode === 'edit' ? (isBoard ? 'Save changes' : 'Save') : 'Publish';
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--edit-open');
      release = ghcaFocusTrap(dialog, field('title'));
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--edit-open');
      if (release) { release(); release = null; }
      if (isBoard && opener && opener.isConnected && opener.focus) opener.focus();
    }

    function post(extra, onDone) {
      var params = new URLSearchParams();
      params.append('nonce', window.ghcaAcd.nonce);
      var format = listEl.getAttribute('data-ghca-announce-format');
      if (format) params.append('format', format);
      Object.keys(extra).forEach(function (k) { params.append(k, extra[k]); });
      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      })
      .then(ghcaReadJson)
      .then(function (json) { onDone(json, null); })
      .catch(function (err) { onDone(null, err || {}); });
    }

    // ⋯ menu on each announcement (board).
    function closeMenus(except) {
      document.querySelectorAll('[data-ghca-ann-menu]').forEach(function (m) {
        if (m === except) return;
        m.hidden = true;
        var b = m.parentNode.querySelector('[data-ghca-ann-menu-btn]');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
    }
    function menuItems(m) { return Array.prototype.slice.call(m.querySelectorAll('[role="menuitem"]')); }

    function openConfirm(id, title, from) {
      pendingDelete = { id: id, from: from };
      confirmLayer.querySelector('[data-ghca-announce-confirm-title]').textContent = '“' + title + '”';
      confirmLayer.hidden = false;
      confirmLayer.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--edit-open');
      confirmRelease = ghcaFocusTrap(confirmLayer.querySelector('[data-ghca-announce-confirm-dialog]'), confirmLayer.querySelector('[data-ghca-announce-keep].gh-btn'));
    }
    function closeConfirm(refocus) {
      confirmLayer.hidden = true;
      confirmLayer.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--edit-open');
      if (confirmRelease) { confirmRelease(); confirmRelease = null; }
      if (refocus && pendingDelete && pendingDelete.from && pendingDelete.from.isConnected) pendingDelete.from.focus();
      pendingDelete = null;
    }
    function doDelete(id, btn) {
      if (btn) btn.disabled = true;
      post({ action: 'ghca_acd_delete_announcement', announce_id: id }, function (json, err) {
        if (btn) btn.disabled = false;
        if (json && json.success && json.data) {
          listEl.innerHTML = json.data.html;
          if (confirmLayer) closeConfirm(false);
          var add = document.querySelector('[data-ghca-announce-add]');
          if (add) add.focus();
          ghcaToast(isBoard ? 'Announcement deleted. Removed from the dashboard.' : (json.data.message || 'Announcement deleted.'), false);
        } else {
          ghcaToast(err && err.ghcaSession ? 'Couldn’t delete. Your session timed out. Nothing was deleted — sign in again and try again.' : ((json && json.data && json.data.message) || 'Could not delete announcement.'), true);
        }
      });
    }

    document.addEventListener('click', function (e) {
      var menuBtn = e.target.closest('[data-ghca-ann-menu-btn]');
      if (menuBtn) {
        e.preventDefault();
        var menu = menuBtn.parentNode.querySelector('[data-ghca-ann-menu]');
        var open = menu.hidden;
        closeMenus(menu);
        menu.hidden = !open;
        menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Inside the scrolling announcements list, open upward when the menu would be cut off at the bottom.
        menu.classList.remove('gh-ann-menu--up');
        var box = open && menu.closest('.gh-ovp-scroll');
        if (box) {
          var b = box.getBoundingClientRect(), m = menu.getBoundingClientRect(), t = menuBtn.getBoundingClientRect();
          if (m.bottom > b.bottom && t.top - b.top >= m.height + 4) { menu.classList.add('gh-ann-menu--up'); }
        }
        if (open) { var first = menuItems(menu)[0]; if (first) first.focus(); }
        return;
      }
      if (!e.target.closest('[data-ghca-ann-menu]')) closeMenus(null);

      var addBtn = e.target.closest('[data-ghca-announce-add]');
      if (addBtn) { e.preventDefault(); openModal('create', null, addBtn); return; }

      var editBtn = e.target.closest('[data-ghca-announce-edit]');
      if (editBtn) {
        e.preventDefault();
        var item = editBtn.closest('[data-announce-id]');
        if (!item) return;
        closeMenus(null);
        openModal('edit', {
          id: item.getAttribute('data-announce-id'),
          title: item.getAttribute('data-announce-title'),
          body: item.getAttribute('data-announce-body'),
          type: item.getAttribute('data-announce-type'),
          url: item.getAttribute('data-announce-url'),
          audience: item.getAttribute('data-announce-audience'),
          group: item.getAttribute('data-announce-group')
        }, item.querySelector('[data-ghca-ann-menu-btn]') || editBtn);
        return;
      }

      var delBtn = e.target.closest('[data-ghca-announce-delete]');
      if (delBtn) {
        e.preventDefault();
        var row = delBtn.closest('[data-announce-id]');
        closeMenus(null);
        if (confirmLayer) {
          openConfirm(delBtn.getAttribute('data-ghca-announce-delete'), row ? row.getAttribute('data-announce-title') : '', row ? row.querySelector('[data-ghca-ann-menu-btn]') : delBtn);
          return;
        }
        if (!window.confirm('Delete this announcement? This cannot be undone.')) return;
        doDelete(delBtn.getAttribute('data-ghca-announce-delete'), delBtn);
        return;
      }
      if (confirmLayer && e.target.closest('[data-ghca-announce-keep]')) { e.preventDefault(); closeConfirm(true); return; }
      var really = e.target.closest('[data-ghca-announce-confirm-delete]');
      if (really && pendingDelete) { e.preventDefault(); doDelete(pendingDelete.id, really); return; }

      var typeBtn = e.target.closest('[data-ghca-announce-type]');
      if (typeBtn && modal.contains(typeBtn)) { e.preventDefault(); setType(typeBtn.getAttribute('data-ghca-announce-type')); return; }

      if (e.target.closest('[data-ghca-announce-close]')) { e.preventDefault(); closeModal(); }
    });

    if (field('audience')) field('audience').addEventListener('change', syncAudience);

    document.addEventListener('keydown', function (e) {
      var menu = e.target.closest && e.target.closest('[data-ghca-ann-menu]');
      if (menu) {
        var items = menuItems(menu);
        var i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); items[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); return; }
        if (e.key === 'Escape' || e.key === 'Tab') {
          var b = menu.parentNode.querySelector('[data-ghca-ann-menu-btn]');
          closeMenus(null);
          if (e.key === 'Escape') { e.preventDefault(); if (b) b.focus(); }
          return;
        }
      }
      if (e.key !== 'Escape') return;
      if (confirmLayer && !confirmLayer.hidden) { closeConfirm(true); return; }
      if (!modal.hidden) closeModal();
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (isBoard) {
        var bad = { title: !field('title').value.trim(), url: field('url').value.trim() !== '' && !field('url').checkValidity() };
        errs(bad);
        if (bad.title) { field('title').focus(); return; }
        if (bad.url) { field('url').focus(); return; }
        if (field('audience_group') && field('audience_group').required && !field('audience_group').value) { field('audience_group').reportValidity(); return; }
      }
      var prev = saveBtn ? saveBtn.textContent : '';
      if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = (window.ghcaAcd && window.ghcaAcd.loading) || 'Saving…'; }

      var data = { action: 'ghca_acd_save_announcement' };
      FIELDS.forEach(function (name) {
        var el = field(name);
        if (el) data[name] = el.value;
      });
      if (data.audience !== 'group') delete data.audience_group;

      post(data, function (json, err) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prev; }
        if (json && json.success && json.data) {
          listEl.innerHTML = json.data.html;
          if (isBoard && data.announce_id === '0') clearDraft();
          closeModal();
          var who = json.data.audience ? ' Who sees it: ' + json.data.audience + '.' : '';
          ghcaToast((json.data.message || 'Saved.') + who, false);
          return;
        }
        if (isBoard && err && err.ghcaSession) {
          // Keep the text: the form stays open, and a new announcement's text is also kept for the next visit.
          if (data.announce_id === '0') saveDraft();
          ghcaToast('Couldn’t publish. Your session timed out. Nothing was published — sign in again and your text will still be here.', true);
          return;
        }
        ghcaToast((json && json.data && json.data.message) || (err ? 'Network error. Nothing was published; your text is still here.' : 'Could not save announcement.'), true);
      });
    });
  }

  // Slice 8: search and Active/Inactive on the ManageUsers board. Rows and cards carry their own status and text.
  function initManageUsersList() {
    var search = document.querySelector('[data-ghca-mu-search]');
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-ghca-mu-status]'));
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-ghca-mu-row]'));
    var none = document.querySelector('[data-ghca-mu-none]');
    var count = document.querySelector('[data-ghca-mu-count]');
    if (!tabs.length) return;
    var status = 'active';
    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var shown = 0;
      items.forEach(function (el) {
        var hit = el.getAttribute('data-status') === status && (!q || el.getAttribute('data-search').indexOf(q) !== -1);
        el.hidden = !hit;
        if (hit && el.tagName === 'TR') shown++;
      });
      if (none) none.hidden = shown > 0;
      if (count) count.textContent = shown + (shown === 1 ? ' employee shown' : ' employees shown');
    }
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        status = t.getAttribute('data-ghca-mu-status');
        tabs.forEach(function (x) { x.setAttribute('aria-pressed', x === t ? 'true' : 'false'); });
        apply();
      });
    });
    if (search) search.addEventListener('input', apply);
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
    // Slice 8: the ManageUsers board (a side drawer inside the themed wrapper). Same form, fields and action.
    var isBoard = modal.getAttribute('data-ghca-ui') === 'board';
    var boardDialog = isBoard ? modal.querySelector('[data-ghca-mu-dialog]') : null;
    var boardSub = isBoard ? modal.querySelector('[data-ghca-mu-sub]') : null;
    var emailErr = isBoard ? modal.querySelector('[data-ghca-mu-email-err]') : null;
    var boardRelease = null;
    var boardOpener = null;
    var labels = isBoard
      ? { add: 'Add employee', edit: 'Edit employee', addBtn: 'Add employee', editBtn: 'Save changes', addSub: 'Creates a WordPress account and enrols them in LearnDash groups.', editSub: 'Changes the account, role, employment type and LearnDash groups.' }
      : { add: 'Add Employee', edit: 'Edit Employee' };

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

    function setMode(edit) {
      titleEl.textContent = edit ? labels.edit : labels.add;
      if (isBoard) {
        if (btnText) btnText.textContent = edit ? labels.editBtn : labels.addBtn;
        if (boardSub) boardSub.textContent = edit ? labels.editSub : labels.addSub;
      }
    }

    function showEmailError(on) {
      if (!emailErr) return;
      emailErr.hidden = !on;
      if (on) { form.elements['email'].setAttribute('aria-invalid', 'true'); form.elements['email'].classList.add('is-invalid'); }
      else { form.elements['email'].removeAttribute('aria-invalid'); form.elements['email'].classList.remove('is-invalid'); }
    }

    function openModal(opener) {
      if (isBoard) {
        boardOpener = opener || document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('ghca-acd--drawer-open');
        boardRelease = ghcaFocusTrap(boardDialog, form.elements['first_name']);
        return;
      }
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      if (isBoard) {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('ghca-acd--drawer-open');
        if (boardRelease) { boardRelease(); boardRelease = null; }
        showEmailError(false);
        var filter = modal.querySelector('[data-ghca-mu-group-filter]');
        if (filter) { filter.value = ''; Array.prototype.forEach.call(modal.querySelectorAll('.gh-mu-g'), function (g) { g.hidden = false; }); }
        if (boardOpener && boardOpener.focus) boardOpener.focus();
      } else {
        modal.style.display = 'none';
        document.body.style.overflow = '';
      }
      form.reset();
      document.getElementById('ghca-acd-user-id').value = '';
      clearTempOptions();
    }

    if (isBoard) {
      modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-ghca-mu-close]')) { e.preventDefault(); closeModal(); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { e.preventDefault(); closeModal(); }
      });
      form.elements['email'].addEventListener('input', function () { if (emailErr && !emailErr.hidden && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.elements['email'].value.trim())) showEmailError(false); });
      var gfilter = modal.querySelector('[data-ghca-mu-group-filter]');
      if (gfilter) {
        gfilter.addEventListener('input', function () {
          var q = gfilter.value.trim().toLowerCase();
          Array.prototype.forEach.call(modal.querySelectorAll('.gh-mu-g'), function (g) { g.hidden = !!q && g.getAttribute('data-name').indexOf(q) === -1; });
        });
      }
      initManageUsersList();
    }

    if (!isBoard && closeBtn) closeBtn.addEventListener('click', closeModal);
    if (!isBoard && cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        setMode(false);
        clearTempOptions();
        openModal(addBtn);
      });
    }

    Array.prototype.forEach.call(editBtns, function (btn) {
      btn.addEventListener('click', function () {
        setMode(true);
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
        
        openModal(btn);
      });
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (isBoard) {
          // novalidate: the board shows its own email message; other required fields use the browser's.
          var email = form.elements['email'];
          // Same rule as the server's is_email(): the domain needs a dot (name@example.org).
          if (!email.checkValidity() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) { showEmailError(true); email.focus(); return; }
          showEmailError(false);
          if (!form.reportValidity()) return;
        }
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

  /*
   * Packet-ready notice (redesign; Drawer-EditRecords board). Shows only what the merge actually returned: page
   * count, items flagged for evidence review and the link's expiry. The download is an explicit link to the existing
   * private, authorized endpoint; it is fetched in the background and saved only if the answer is a real PDF, so an
   * expired or refused link is reported here instead of navigating away. The server deletes the packet after one
   * download, after its TTL, or when the same person starts another packet; the notice says which.
   */
  var ghcaPacketNotices = [];
  var ghcaPacketWatch = null;
  // A notice shown inside the Edit Records card moves to the page tray when that card stops being visible (the
  // drawer closes, Administration goes back, or the drawer switches employee), so the link is never lost with it.
  function ghcaPacketRehome() {
    var tray = document.querySelector('.ghca-ui [data-ghca-packet-tray]');
    if (!tray) return;
    ghcaPacketNotices.forEach(function (n) {
      if (n.el.parentNode === tray) return;
      if (!n.el.isConnected || n.el.offsetParent === null || n.el.closest('[hidden]')) {
        if (n.state === 'ready') ghcaPacketCompact(n, true);
        tray.appendChild(n.el);
      }
    });
  }
  function ghcaPacketWatchSlots() {
    if (ghcaPacketWatch || typeof MutationObserver === 'undefined') return;
    ghcaPacketWatch = new MutationObserver(ghcaPacketRehome);
    ghcaPacketWatch.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden', 'aria-hidden', 'class'] });
  }
  function ghcaPacketLabel(tracker) {
    return tracker === 'orientation' ? 'Orientation packet' : (tracker === 'oltl_training' ? 'OLTL training packet' : 'Annual packet');
  }
  function ghcaPacketSetState(n, state, text) {
    n.state = state;
    n.el.classList.remove('gh-toast--success', 'gh-toast--error', 'gh-toast--neutral', 'is-compact');
    n.el.classList.add(state === 'ready' ? 'gh-toast--success' : (state === 'done' ? 'gh-toast--neutral' : 'gh-toast--error'));
    n.el.setAttribute('data-ghca-packet-state', state);
    var ico = n.el.querySelector('.gh-pkr-ico');
    if (ico && state !== 'ready') { ico.className = 'gh-i gh-pkr-ico ' + (state === 'done' ? 'i-check-circle' : 'i-alert'); }
    var title = n.el.querySelector('.gh-toast-t');
    if (title && state === 'done') { title.textContent = n.label + ' downloaded'; }
    if (title && state !== 'ready' && state !== 'done') { title.textContent = n.label + ' link ' + (state === 'replaced' ? 'replaced' : 'no longer available'); }
    var dismiss = n.el.querySelector('[data-ghca-packet-dismiss]');
    if (dismiss && state !== 'ready') { dismiss.setAttribute('aria-label', 'Close notice'); }
    var link = n.el.querySelector('[data-ghca-packet-download]');
    if (link && state !== 'ready') { link.remove(); }
    // A used, expired or replaced link has no expiry worth showing.
    var exp = n.el.querySelector('[data-ghca-packet-exp]');
    if (exp && state !== 'ready') { exp.textContent = ''; }
    var msg = n.el.querySelector('[data-ghca-packet-msg]');
    msg.textContent = text || '';
    msg.hidden = !text;
    if (state !== 'ready') { n.el.setAttribute('role', state === 'done' ? 'status' : 'alert'); }
    if (n.timer) { clearTimeout(n.timer); n.timer = null; }
  }
  // Compact keeps the title and the link; "Show details" brings the rest back.
  function ghcaPacketCompact(n, on) {
    n.el.classList.toggle('is-compact', !!on);
    var more = n.el.querySelector('[data-ghca-packet-expand]');
    if (more) more.setAttribute('aria-expanded', on ? 'false' : 'true');
  }
  function ghcaPacketReplaced() {
    ghcaPacketNotices.forEach(function (n) {
      if (n.state === 'ready') { ghcaPacketSetState(n, 'replaced', 'Replaced by the packet you started next. Only one packet is kept at a time; generate this one again to download it.'); }
    });
  }
  function ghcaPacketReady(data, tracker, opener) {
    var label = ghcaPacketLabel(data.tracker || tracker);
    var slot = opener.closest('.gh-pk') && opener.closest('.gh-pk').querySelector('[data-ghca-packet-slot]');
    var host = slot || document.querySelector('.ghca-ui [data-ghca-packet-tray]');
    if (!host) { return; }
    var el = document.createElement('div');
    el.className = 'gh-toast gh-toast--success gh-pkr';
    el.setAttribute('role', 'status');
    el.setAttribute('data-ghca-packet-notice', data.tracker || tracker);
    var details = [];
    if (typeof data.pages === 'number' && data.pages > 0) { details.push(data.pages + (data.pages === 1 ? ' page' : ' pages')); }
    if (typeof data.flagged === 'number') { details.push(data.flagged > 0 ? data.flagged + (data.flagged === 1 ? ' item' : ' items') + ' flagged for evidence review' : 'nothing flagged for evidence review'); }
    var expires = typeof data.expires === 'number' && data.expires > 0 ? data.expires * 1000 : 0;
    el.innerHTML = '<span class="gh-i i-check-circle gh-pkr-ico" aria-hidden="true"></span><div class="gh-pkr-body"><div class="gh-toast-t"></div><p class="gh-pkr-p"><span data-ghca-packet-details></span><a href="#" data-ghca-packet-download></a><span data-ghca-packet-exp></span></p><p class="gh-small gh-pkr-msg" data-ghca-packet-msg hidden></p><button type="button" class="gh-pkr-more" data-ghca-packet-expand aria-expanded="false">Show details</button></div><button type="button" class="gh-toast-x" data-ghca-packet-dismiss aria-label="Collapse notice (the download link stays available)"><span class="gh-i gh-i--sm i-x" aria-hidden="true"></span></button>';
    el.querySelector('.gh-toast-t').textContent = label + ' ready';
    el.querySelector('[data-ghca-packet-details]').textContent = details.length ? details.join(' · ') + '. ' : '';
    var link = el.querySelector('[data-ghca-packet-download]');
    link.href = data.download_url;
    link.textContent = 'Download PDF';
    link.setAttribute('aria-label', 'Download ' + label + ' (PDF)');
    el.querySelector('[data-ghca-packet-exp]').textContent = expires ? ' · link expires ' + new Date(expires).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
    host.appendChild(el);
    var n = { el: el, state: 'ready', url: data.download_url, expires: expires, timer: null, label: label };
    el.setAttribute('data-ghca-packet-state', 'ready');
    ghcaPacketNotices.push(n);
    if (slot) { ghcaPacketWatchSlots(); }
    // Time-out collapses the notice to its title and link; it never removes the link.
    n.timer = window.setTimeout(function () { if (n.state === 'ready') { ghcaPacketCompact(n, true); } }, 12000);
    if (expires) {
      window.setTimeout(function () { if (n.state === 'ready') { ghcaPacketSetState(n, 'expired', 'This download link has expired and the private copy was deleted. Generate the packet again.'); } }, Math.max(0, expires - Date.now()));
    }
    el.addEventListener('click', function (e) {
      if (e.target.closest('[data-ghca-packet-expand]')) {
        ghcaPacketCompact(n, false);
        var dl = el.querySelector('[data-ghca-packet-download]');
        if (dl) dl.focus();
        return;
      }
      if (e.target.closest('[data-ghca-packet-dismiss]')) {
        if (n.state === 'ready') { ghcaPacketCompact(n, true); var a = el.querySelector('[data-ghca-packet-download]'); if (a) a.focus(); }
        else { el.remove(); ghcaPacketNotices = ghcaPacketNotices.filter(function (x) { return x !== n; }); }
        return;
      }
      var a = e.target.closest('[data-ghca-packet-download]');
      if (!a) return;
      e.preventDefault();
      if (n.state !== 'ready' || a.getAttribute('aria-busy') === 'true') return;
      if (n.expires && Date.now() >= n.expires) { ghcaPacketSetState(n, 'expired', 'This download link has expired and the private copy was deleted. Generate the packet again.'); return; }
      a.setAttribute('aria-busy', 'true');
      a.textContent = 'Downloading…';
      fetch(n.url, { credentials: 'same-origin' }).then(function (res) {
        var type = res.headers.get('Content-Type') || '';
        var disp = res.headers.get('Content-Disposition') || '';
        return res.arrayBuffer().then(function (buf) { return { res: res, type: type, disp: disp, buf: buf }; });
      }).then(function (r) {
        var bytes = new Uint8Array(r.buf);
        if (r.res.ok && ghcaCertificateResponseIsPdf(r.type, bytes.slice(0, 5))) {
          var m = /filename="?([^";]+)"?/i.exec(r.disp);
          var url = URL.createObjectURL(new Blob([r.buf], { type: 'application/pdf' }));
          var save = document.createElement('a');
          save.href = url; save.download = m ? m[1] : 'compliance-packet.pdf';
          document.body.appendChild(save); save.click(); save.remove();
          window.setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
          ghcaPacketSetState(n, 'done', 'Link used. The PDF was saved and the private copy on the server was deleted, so this link no longer works. To download again, generate a new packet.');
          return;
        }
        var text = '';
        try { var j = JSON.parse(new TextDecoder().decode(bytes)); text = j && j.data && j.data.message; } catch (err) { text = ''; }
        ghcaPacketSetState(n, 'expired', (text || 'The packet could not be downloaded.') + ' Nothing was saved. Generate the packet again.');
      }).catch(function () {
        a.removeAttribute('aria-busy');
        a.textContent = 'Download PDF';
        var msg = el.querySelector('[data-ghca-packet-msg]');
        msg.textContent = 'Could not reach the server. Nothing was saved; the link is still available.';
        msg.hidden = false;
      });
    });
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

    // While a packet is building, every packet button says so and refuses another start.
    function setRunning(on) {
      running = on;
      document.querySelectorAll('[data-ghca-pdf-packet]').forEach(function (b) {
        if (on) { b.setAttribute('aria-disabled', 'true'); b.classList.add('is-busy'); }
        else { b.removeAttribute('aria-disabled'); b.classList.remove('is-busy'); }
      });
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      setRunning(false);
      activeRunToken = 0;
      ghcaAcdOverlay.close(modal);
      if (packetRelease) { packetRelease(); packetRelease = null; }
      packetOpener = null;
    }

    function failState(msg) {
      bar.classList.add('is-error');
      setProgress(100, msg || t('pdfError', 'Packet generation failed. No packet was created. Please try again.'));
      setRunning(false);
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
      setRunning(true);
      if (opener.closest('.ghca-ui')) { ghcaPacketReplaced(); }
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
            if (mj && mj.success && mj.data && mj.data.download_url && opener.closest('.ghca-ui')) {
              // Redesign: no automatic download. The drawer stays open and a ready notice offers the PDF.
              setProgress(100, t('pdfReady', 'Packet ready.'));
              activeJobId = '';
              setRunning(false);
              closeModal();
              ghcaPacketReady(mj.data, tracker, opener);
            } else if (mj && mj.success && mj.data && mj.data.download_url) {
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

    // Drawer Communications tab (redesign): All / Email / SMS show the latest three of that channel, from the
    // existing history endpoint with the same permission and scope checks.
    var drawerHistoryController = null;
    document.addEventListener('click', function (e) {
      var tab = e.target.closest('.ghca-ui [data-ghca-history-channel]');
      if (tab) {
        var wrap = tab.closest('[data-ghca-history-user]');
        var list = wrap && wrap.querySelector('[data-ghca-history-list]');
        var status = wrap && wrap.querySelector('[data-ghca-history-drawer-status]');
        // A pressed tab reloads only after a failed load (so the person can retry).
        if (!wrap || !list || (tab.getAttribute('aria-pressed') === 'true' && !list.hasAttribute('data-ghca-history-error'))) return;
        wrap.querySelectorAll('[data-ghca-history-channel]').forEach(function (b) { b.setAttribute('aria-pressed', b === tab ? 'true' : 'false'); });
        if (drawerHistoryController) drawerHistoryController.abort();
        drawerHistoryController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        list.setAttribute('aria-busy', 'true');
        var p = new URLSearchParams();
        p.append('action', 'ghca_acd_get_communication_history');
        p.append('user_id', wrap.getAttribute('data-ghca-history-user'));
        p.append('ghca_ui', 'board');
        p.append('view', 'drawer');
        p.append('channel', tab.getAttribute('data-ghca-history-channel') || '');
        p.append('nonce', window.ghcaAcd.nonce);
        fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: p.toString(), signal: drawerHistoryController ? drawerHistoryController.signal : undefined })
          .then(ghcaReadJson)
          .then(function (json) {
            list.removeAttribute('aria-busy');
            if (json && json.success && json.data && typeof json.data.html === 'string') {
              list.removeAttribute('data-ghca-history-error');
              list.innerHTML = json.data.html;
              if (status) status.textContent = tab.textContent.trim().replace(/\s+\d+$/, '') + ': ' + (json.data.total || 0) + (Number(json.data.total) === 1 ? ' message' : ' messages');
            } else {
              list.setAttribute('data-ghca-history-error', '');
              list.innerHTML = '<p class="gh-small gh-due--danger" role="alert"></p>';
              list.firstChild.textContent = ((json && json.data && json.data.message) || 'Messages could not be loaded.') + ' Select the tab again to retry.';
            }
          })
          .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            list.removeAttribute('aria-busy');
            list.setAttribute('data-ghca-history-error', '');
            list.innerHTML = '<p class="gh-small gh-due--danger" role="alert"></p>';
            list.firstChild.textContent = err && err.ghcaSession ? GHCA_SESSION_MSG : 'Network error. Select the tab again to retry.';
          });
        return;
      }
      var toggle = e.target.closest('.ghca-ui [data-ghca-ch-toggle]');
      if (toggle) {
        var msg = document.getElementById(toggle.getAttribute('aria-controls'));
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        if (msg) msg.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var lab = toggle.querySelector('span');
        if (lab) lab.textContent = toggle.getAttribute(open ? 'data-hide' : 'data-show');
        toggle.classList.toggle('is-open', open);
      }
    });
    // "Hide message" / "Show message" on the drawer timeline follows the native details state.
    document.addEventListener('toggle', function (e) {
      var d = e.target;
      if (!d || !d.matches || !d.matches('.ghca-ui details[data-ghca-msg-toggle]')) return;
      var sum = d.querySelector('summary');
      if (sum) sum.textContent = sum.getAttribute(d.open ? 'data-hide' : 'data-show');
    }, true);

    var historyState = { userId: '', page: 1, pages: 1, opener: null, release: null, controller: null };
    var closeHistory = function() {};
    var loadHistory = function() {};

    if (historyModal) {
      var historyDialog = historyModal.querySelector('[data-ghca-history-dialog], .ghca-acd__history-modal-dialog');
      var historyIsBoard = historyModal.getAttribute('data-ghca-ui') === 'board';
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
        if (historyIsBoard) { params.append('ghca_ui', 'board'); }
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

      // A PDF preview in the redesigned dashboard opens in its certificate viewer, which asks for its own fresh
      // preview grant (and a download grant only when Download is pressed).
      if (action === 'preview' && btn.getAttribute('data-ghca-doc-pdf') === '1' && window.ghcaAcdCertViewer && typeof window.ghcaAcdCertViewer.openDocument === 'function') {
        window.ghcaAcdCertViewer.openDocument({
          trigger: btn, title: title, mint: mint,
          employee: btn.getAttribute('data-ghca-doc-employee') || '',
          context: btn.getAttribute('data-ghca-doc-context') || '',
          date: btn.getAttribute('data-ghca-doc-date') || '',
          status: btn.getAttribute('data-ghca-doc-status') || '',
          tone: btn.getAttribute('data-ghca-doc-tone') || '',
          source: btn.getAttribute('data-ghca-doc-source') || '',
          file: title
        });
        return;
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
   * Row selection on the redesigned Employees tab (slice 6). The selection is a list of ids the person ticked;
   * "Select all" adds only the rows on the current page. It survives paging and filtering, and the bar always
   * names the selected employees that are not on screen, so nothing hidden is ever included silently.
   */
  function initBulkSelection() {
    var bar = document.querySelector('.ghca-ui [data-ghca-bulkbar]');
    var mount = document.querySelector('.ghca-ui [data-ghca-selectable]');
    if (!bar || !mount) return;
    var max = parseInt(bar.getAttribute('data-max'), 10) || 25;
    var sel = [];           // [{id, name}] in the order selected
    var names = Object.create(null);

    function has(id) { return sel.some(function (s) { return s.id === id; }); }
    function add(id, name) { if (!has(id)) { sel.push({ id: id, name: name }); } names[id] = name; }
    function remove(id) { sel = sel.filter(function (s) { return s.id !== id; }); }
    function visibleIds() {
      var ids = [];
      mount.querySelectorAll('table [data-ghca-select]').forEach(function (cb) { if (ids.indexOf(cb.value) === -1) ids.push(cb.value); });
      return ids;
    }
    function listNames(items, limit) {
      var shown = items.slice(0, limit).map(function (s) { return s.name; });
      return items.length > limit ? shown.join(', ') + ' and ' + (items.length - limit) + ' more' : shown.join(', ');
    }
    function sync() {
      mount.querySelectorAll('[data-ghca-select]').forEach(function (cb) {
        cb.checked = has(cb.value);
        var row = cb.closest('tr, .gh-mcard');
        if (row) { row.classList.toggle('is-selected', cb.checked); }
      });
      var vis = visibleIds();
      var onPage = vis.filter(has).length;
      mount.querySelectorAll('[data-ghca-select-page]').forEach(function (head) {
        head.checked = vis.length > 0 && onPage === vis.length;
        head.indeterminate = onPage > 0 && onPage < vis.length;
      });
      var hidden = sel.filter(function (s) { return vis.indexOf(s.id) === -1; });
      bar.hidden = sel.length === 0;
      bar.querySelector('[data-ghca-bulk-count]').textContent = sel.length + ' selected';
      bar.querySelector('[data-ghca-bulk-names]').textContent = listNames(sel, 3);
      var note = bar.querySelector('[data-ghca-bulk-hidden]');
      note.hidden = hidden.length === 0;
      note.textContent = hidden.length ? hidden.length + ' not shown with the current filters or page: ' + listNames(hidden, 3) + '.' : '';
      var keep = bar.querySelector('[data-ghca-bulk-visible]');
      if (keep) { keep.hidden = hidden.length === 0; }
      var send = bar.querySelector('[data-ghca-bulk-send]');
      if (send) {
        var tooMany = sel.length > max;
        send.disabled = tooMany;
        send.querySelector('[data-ghca-bulk-send-label]').textContent = tooMany ? 'Select ' + max + ' or fewer to send' : 'Send reminder to ' + sel.length;
      }
    }

    mount.addEventListener('change', function (e) {
      var cb = e.target.closest('[data-ghca-select]');
      if (cb) {
        if (cb.checked) { add(cb.value, cb.getAttribute('data-name') || cb.value); } else { remove(cb.value); }
        sync();
        return;
      }
      var head = e.target.closest('[data-ghca-select-page]');
      if (head) {
        var all = head.checked;
        mount.querySelectorAll('table [data-ghca-select]').forEach(function (box) {
          if (all) { add(box.value, box.getAttribute('data-name') || box.value); } else { remove(box.value); }
        });
        sync();
      }
    });
    bar.addEventListener('click', function (e) {
      if (e.target.closest('[data-ghca-bulk-clear]')) { sel = []; sync(); var first = mount.querySelector('[data-ghca-select-page]'); if (first) first.focus(); return; }
      if (e.target.closest('[data-ghca-bulk-visible]')) { var vis = visibleIds(); sel = sel.filter(function (s) { return vis.indexOf(s.id) !== -1; }); sync(); return; }
      var send = e.target.closest('[data-ghca-bulk-send]');
      if (send && !send.disabled && typeof window.ghcaAcdBulkOpen === 'function') {
        window.ghcaAcdBulkOpen(sel.map(function (s) { return { id: s.id, name: s.name }; }), send);
      }
    });
    var exportForm = bar.querySelector('[data-ghca-bulk-export]');
    if (exportForm) {
      exportForm.addEventListener('submit', function (e) {
        if (!sel.length) { e.preventDefault(); return; }
        exportForm.querySelector('[data-ghca-bulk-ids]').value = sel.map(function (s) { return s.id; }).join(',');
      });
    }
    // The filter script replaces the table; re-tick what is still selected.
    if (typeof MutationObserver !== 'undefined') { new MutationObserver(sync).observe(mount, { childList: true }); }
    window.ghcaAcdBulkSelection = { ids: function () { return sel.map(function (s) { return s.id; }); }, clear: function () { sel = []; sync(); } };
    sync();
  }

  /*
   * Bulk reminder dialog: compose, review, results. Every call goes through the server, which checks each
   * recipient again; the browser only sends the ids the person selected and then reviewed.
   */
  function initBulkMessaging() {
    var layer = document.getElementById('ghca-acd-bulk-modal');
    if (!layer || !window.ghcaAcd) return;
    var dialog = layer.querySelector('[data-ghca-bulk-dialog]');
    var q = function (sel) { return layer.querySelector(sel); };
    var steps = { compose: q('[data-ghca-bulk-step="compose"]'), review: q('[data-ghca-bulk-step="review"]'), result: q('[data-ghca-bulk-step="result"]') };
    var btn = { next: q('[data-ghca-bulk-next]'), go: q('[data-ghca-bulk-go]'), back: q('[data-ghca-bulk-back]'), retry: q('[data-ghca-bulk-retry]'), cancel: q('[data-ghca-bulk-cancel]') };
    var state = null;
    var release = null;

    function post(action, extra) {
      var p = new URLSearchParams();
      p.append('action', action);
      p.append('nonce', window.ghcaAcd.nonce);
      Object.keys(extra).forEach(function (k) { p.append(k, extra[k]); });
      return fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: p.toString() }).then(ghcaReadJson);
    }
    function showError(msg) { var el = q('[data-ghca-bulk-error]'); el.textContent = msg || ''; el.hidden = !msg; }
    function failMsg(err) { return err && err.ghcaSession ? GHCA_SESSION_MSG : 'Could not reach the server. Nothing was sent.'; }
    function show(step) {
      Object.keys(steps).forEach(function (k) { steps[k].hidden = k !== step; });
      btn.next.hidden = step !== 'compose';
      btn.go.hidden = step !== 'review';
      btn.back.hidden = step !== 'review';
      btn.retry.hidden = true;
      btn.cancel.textContent = step === 'result' ? 'Close' : 'Cancel';
      var title = q('[data-ghca-bulk-title]');
      title.textContent = step === 'compose' ? 'Send training reminder to ' + state.ids.length : (step === 'review' ? 'Review before sending' : 'Reminder results');
      try { title.focus({ preventScroll: true }); } catch (e) { title.focus(); }
    }
    function nameOf(id) { var r = state.recipients.filter(function (x) { return String(x.id) === String(id); })[0]; return r && r.name ? r.name : (state.names[id] || ('Employee #' + id)); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function chip(tone, text) { return el('span', 'gh-st gh-st--' + tone, text); }

    function close() {
      if (state && state.busy) return;
      layer.hidden = true;
      layer.setAttribute('aria-hidden', 'true');
      ghcaAcdOverlay.close(layer);
      if (release) { release(); release = null; }
      state = null;
    }

    function open(selection, launcher) {
      state = { ids: selection.map(function (s) { return String(s.id); }), names: {}, recipients: [], templates: [], token: '', busy: false, results: {} };
      selection.forEach(function (s) { state.names[s.id] = s.name; });
      layer.hidden = false;
      layer.setAttribute('aria-hidden', 'false');
      ghcaAcdOverlay.open(layer, launcher, function () { if (!steps.review.hidden) { show('compose'); } else { close(); } });
      Object.keys(steps).forEach(function (k) { steps[k].hidden = true; });
      q('[data-ghca-bulk-loading]').hidden = false;
      showError('');
      btn.next.disabled = true; btn.next.hidden = false; btn.go.hidden = true; btn.back.hidden = true; btn.retry.hidden = true;
      q('[data-ghca-bulk-subject]').value = ''; q('[data-ghca-bulk-message]').value = ''; q('[data-ghca-bulk-urgency]').value = 'normal';
      release = ghcaFocusTrap(dialog, q('[data-ghca-bulk-title]'));
      post('ghca_acd_bulk_message_context', { ids: state.ids.join(',') }).then(function (json) {
        q('[data-ghca-bulk-loading]').hidden = true;
        if (!state) return;
        if (!json || !json.success) { showError((json && json.data && json.data.message) || 'Could not check the recipients.'); return; }
        state.recipients = json.data.recipients || [];
        state.templates = json.data.templates || [];
        state.token = json.data.token;
        var who = q('[data-ghca-bulk-who]');
        who.textContent = '';
        var ready = state.recipients.filter(function (r) { return r.ready; });
        var blocked = state.recipients.filter(function (r) { return !r.ready; });
        who.appendChild(el('p', 'gh-p', ready.length + ' of ' + state.recipients.length + ' selected employees can receive an email.'));
        if (blocked.length) {
          var ul = el('ul', 'gh-bulk-list');
          blocked.forEach(function (r) { var li = el('li'); li.appendChild(el('strong', '', r.name || ('Employee #' + r.id))); li.appendChild(chip('failed', 'Won’t be sent')); li.appendChild(el('span', 'gh-small gh-muted', r.reason)); ul.appendChild(li); });
          who.appendChild(ul);
        }
        var sel = q('[data-ghca-bulk-template]');
        sel.querySelectorAll('option:not([value="0"])').forEach(function (o) { o.remove(); });
        state.templates.forEach(function (t) { var o = el('option', '', t.name); o.value = String(t.id); sel.appendChild(o); });
        sel.value = '0';
        show('compose');
        btn.next.disabled = ready.length === 0;
        if (!ready.length) { showError('None of the selected employees can receive an email. Nothing will be sent.'); }
      }).catch(function (err) { q('[data-ghca-bulk-loading]').hidden = true; showError(failMsg(err)); });
    }
    window.ghcaAcdBulkOpen = open;

    q('[data-ghca-bulk-template]').addEventListener('change', function () {
      var t = state && state.templates.filter(function (x) { return String(x.id) === this.value; }, this)[0];
      if (!t) return;
      q('[data-ghca-bulk-subject]').value = t.subject;
      q('[data-ghca-bulk-message]').value = t.message;
      q('[data-ghca-bulk-urgency]').value = t.urgency || 'normal';
    });

    function draft() {
      return { subject: q('[data-ghca-bulk-subject]').value.trim(), message: q('[data-ghca-bulk-message]').value.trim(), urgency: q('[data-ghca-bulk-urgency]').value, template_id: q('[data-ghca-bulk-template]').value };
    }

    steps.compose.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!state || state.busy) return;
      var d = draft();
      if (!d.subject || !d.message) {
        showError('Enter a subject and a message.');
        (d.subject ? q('[data-ghca-bulk-message]') : q('[data-ghca-bulk-subject]')).focus();
        return;
      }
      showError('');
      state.busy = true; btn.next.disabled = true;
      post('ghca_acd_bulk_message_preview', { ids: state.ids.join(','), subject: d.subject, message: d.message, urgency: d.urgency }).then(function (json) {
        state.busy = false; btn.next.disabled = false;
        if (!json || !json.success) { showError((json && json.data && json.data.message) || 'Could not prepare the preview.'); return; }
        state.recipients = json.data.recipients || [];
        state.draft = d;
        var rows = q('[data-ghca-bulk-rows]'); rows.textContent = '';
        var pv = q('[data-ghca-bulk-pv-who]'); pv.textContent = '';
        state.recipients.forEach(function (r) {
          var tr = el('tr');
          tr.appendChild(el('td', '', r.name || ('Employee #' + r.id)));
          tr.appendChild(el('td', 'gh-secondary', r.destination || '—'));
          var td = el('td'); td.appendChild(chip(r.ready ? 'completed' : 'failed', r.ready ? 'Will be sent' : 'Won’t be sent'));
          if (!r.ready && r.reason) { td.appendChild(el('div', 'gh-small gh-muted', r.reason)); }
          tr.appendChild(td); rows.appendChild(tr);
          if (r.ready) { var o = el('option', '', r.name); o.value = String(r.id); pv.appendChild(o); }
        });
        var ready = state.recipients.filter(function (r) { return r.ready; });
        state.readyIds = ready.map(function (r) { return String(r.id); });
        renderPreview();
        q('[data-ghca-bulk-confirm]').checked = false;
        btn.go.disabled = true;
        q('[data-ghca-bulk-go-label]').textContent = 'Send ' + ready.length + (ready.length === 1 ? ' email' : ' emails');
        show('review');
        if (!ready.length) { showError('None of the selected employees can receive this message. Nothing will be sent.'); }
      }).catch(function (err) { state.busy = false; btn.next.disabled = false; showError(failMsg(err)); });
    });

    function renderPreview() {
      var id = q('[data-ghca-bulk-pv-who]').value;
      var r = state.recipients.filter(function (x) { return String(x.id) === id; })[0];
      q('[data-ghca-bulk-pv-subject]').textContent = r ? r.subject : '';
      q('[data-ghca-bulk-pv-message]').textContent = r ? r.message : '';
    }
    q('[data-ghca-bulk-pv-who]').addEventListener('change', renderPreview);
    q('[data-ghca-bulk-confirm]').addEventListener('change', function () { btn.go.disabled = !this.checked || !state || !state.readyIds.length; });
    btn.back.addEventListener('click', function () { if (state && !state.busy) { showError(''); show('compose'); } });

    function send(ids) {
      if (!state || state.busy || !ids.length) return;
      state.busy = true; btn.go.disabled = true; btn.retry.disabled = true; btn.back.disabled = true;
      dialog.setAttribute('aria-busy', 'true');
      showError('');
      var d = state.draft;
      post('ghca_acd_bulk_message_send', { ids: ids.join(','), subject: d.subject, message: d.message, urgency: d.urgency, template_id: d.template_id, token: state.token, reviewed: '1' }).then(function (json) {
        state.busy = false; btn.back.disabled = false; btn.retry.disabled = false;
        dialog.removeAttribute('aria-busy');
        if (!json || !json.success) { showError((json && json.data && json.data.message) || 'Nothing was sent.'); if (steps.result.hidden) { btn.go.disabled = false; } return; }
        (json.data.results || []).forEach(function (r) { state.results[String(r.id)] = r; });
        renderResults();
      }).catch(function (err) {
        state.busy = false; btn.back.disabled = false; btn.retry.disabled = false;
        dialog.removeAttribute('aria-busy');
        // The server may have queued some before the connection dropped. Retrying uses the same batch token, so
        // anyone already queued is reported as "already queued" rather than emailed twice.
        showError(err && err.ghcaSession ? GHCA_SESSION_MSG : 'The connection dropped. Some emails may have been queued. Use Retry to check: anyone already queued is not emailed again.');
        state.readyIds.forEach(function (id) { if (!state.results[id]) { state.results[id] = { id: id, status: 'failed', message: 'Not confirmed: the connection dropped.' }; } });
        renderResults();
      });
    }
    btn.go.addEventListener('click', function () { if (state && q('[data-ghca-bulk-confirm]').checked) { send(state.readyIds.slice()); } });

    // One label per lifecycle status the server reports. Only queued / recovered / pending mean a job exists.
    var BULK_LABEL = {
      queued: ['completed', 'Queued'], recovered: ['completed', 'Queued now (recovered)'], pending: ['progress', 'Already queued'],
      processing: ['progress', 'Sending now'], sent: ['completed', 'Already sent'], busy: ['progress', 'Being scheduled elsewhere'],
      unscheduled: ['failed', 'Saved, not scheduled'], failed: ['failed', 'Not sent'], delivery_failed: ['failed', 'Delivery failed']
    };
    var BULK_RETRY = ['unscheduled', 'failed', 'busy'];
    function renderResults() {
      var list = q('[data-ghca-bulk-results]'); list.textContent = '';
      var c = {};
      Object.keys(BULK_LABEL).forEach(function (k) { c[k] = 0; });
      state.readyIds.forEach(function (id) {
        var r = state.results[id] || { status: 'failed', message: 'No answer for this employee.' };
        var label = BULK_LABEL[r.status] || BULK_LABEL.failed;
        c[r.status in BULK_LABEL ? r.status : 'failed'] += 1;
        var li = el('li'); li.appendChild(el('strong', '', nameOf(id)));
        li.appendChild(chip(label[0], label[1]));
        li.appendChild(el('span', 'gh-small gh-muted', r.message || ''));
        list.appendChild(li);
      });
      var total = state.readyIds.length;
      var parts = [];
      var queued = c.queued + c.recovered + c.pending;
      parts.push(queued + ' of ' + total + ' queued');
      if (c.processing) parts.push(c.processing + ' sending now');
      if (c.sent) parts.push(c.sent + ' already sent');
      if (c.busy) parts.push(c.busy + ' being scheduled by another request');
      if (c.unscheduled) parts.push(c.unscheduled + ' saved but not scheduled (nothing sent)');
      if (c.failed) parts.push(c.failed + ' not sent');
      if (c.delivery_failed) parts.push(c.delivery_failed + ' delivery failed (see Communication History)');
      var problems = c.unscheduled + c.failed + c.delivery_failed + c.busy;
      var sum = q('[data-ghca-bulk-summary]');
      sum.className = 'gh-alert ' + (problems ? 'gh-alert--warning' : 'gh-alert--success');
      sum.textContent = parts.join('. ') + '.';
      show('result');
      var retryIds = state.readyIds.filter(function (id) { return BULK_RETRY.indexOf((state.results[id] || { status: 'failed' }).status) !== -1; });
      btn.retry.hidden = retryIds.length === 0;
      btn.retry.textContent = 'Retry the ' + retryIds.length + ' not queued';
      btn.retry.onclick = function () { send(retryIds); };
    }

    layer.addEventListener('click', function (e) { if (e.target.closest('[data-ghca-bulk-close]')) { close(); } });
  }

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
  initOverviewCaps();
  initInlineReport();
  initCertViewer();
  initCertSelection();
  initCourseSelects();
  initManageUsers();
  initPdfPacket();
  initReminderMessaging();
  initOltlManualReview();
  initManualEntry();
  initManualEvidenceOpen();
  initAuditDates();
  initBulkSelection();
  initBulkMessaging();

})();
