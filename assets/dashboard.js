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
      fields: ['ghca_crs_group', 'ghca_crs_search', 'ghca_crs_cert', 'ghca_crs_per'],
      checkboxFields: [],
      pageField: 'ghca_crs_page'
    }
  };

  document.querySelectorAll('[data-ghca-filter-form]').forEach(function (form) {
    initTableFilters(form);
  });

  function initTableFilters(form) {
    var tableId = form.getAttribute('data-ghca-table') || 'employees';
    var config = tableConfigs[tableId] || tableConfigs.employees;
    var mount = form.parentElement && form.parentElement.querySelector('[data-ghca-table-id="' + tableId + '"]');
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

    function refresh(resetPage) {
      if (resetPage) {
        setPage(1);
      }

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
      }, 400);
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
    var defaultLoadingText = loading ? loading.textContent : '';
    var currentUrl = '';
    var currentDownloadUrl = '';
    var currentTitle = '';
    var release = null;

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

      fetch(sourceUrl, { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('download failed');
          }
          return res.blob();
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
          window.open(sourceUrl, '_blank', 'noopener');
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
      var trigger = e.target.closest('.ghca-acd__cert-trigger');
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

  function activateTab(targetId, moveFocus) {
    if (!targetId) return;

    var tabBtn = document.querySelector('.ghca-acd__tab-btn[data-ghca-tab-target="' + targetId + '"]');
    if (!tabBtn) return;

    var tabContainer = tabBtn.closest('.ghca-acd__tabs');
    var allBtns = tabContainer ? tabContainer.querySelectorAll('.ghca-acd__tab-btn') : document.querySelectorAll('.ghca-acd__tab-btn');
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

      var tabBtn = e.target.closest('.ghca-acd__tab-btn');
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
    document.querySelectorAll('.ghca-acd__tabs').forEach(function (tablist) {
      if (tablist.closest('.ghca-acd__drawer')) return;
      tablist.addEventListener('keydown', function (e) {
        var btns = Array.prototype.slice.call(tablist.querySelectorAll('.ghca-acd__tab-btn'));
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

    var dialog = drawer.querySelector('.ghca-acd__drawer-dialog');
    var closeBtn = drawer.querySelector('[data-ghca-drawer-close]');
    var bodyContainer = document.getElementById('ghca-acd-employee-drawer-body');
    var loadingHtml = bodyContainer.innerHTML;
    var controller = null;
    var release = null;
    var lastUserId = null;
    var selectedTabs = Object.create(null);

    function errorState(message) {
      return '<div class="ghca-acd__overlay-state ghca-acd__overlay-state--error" role="alert">' +
        '<p class="ghca-acd__overlay-state-msg">' + message + '</p>' +
        '<button type="button" class="ghca-acd__overlay-retry" data-ghca-drawer-retry>Try again</button>' +
        '</div>';
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
        tabs.forEach(function (tab) {
          var active = tab.getAttribute('data-ghca-tab') === name;
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
          tab.setAttribute('tabindex', active ? '0' : '-1');
          if (active && moveFocus) tab.focus();
        });
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
      ghcaAcdOverlay.open(drawer, launcher || null, closeDrawer);
      bodyContainer.innerHTML = loadingHtml;
      bodyContainer.setAttribute('aria-busy', 'true');

      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

      var params = new URLSearchParams();
      params.append('action', 'ghca_acd_get_employee_drawer');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', userId);

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
        }
      })
      .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        bodyContainer.removeAttribute('aria-busy');
        bodyContainer.innerHTML = errorState('Network error. Please check your connection and try again.');
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
      return document.querySelector('.ghca-acd__drawer-panel--administration');
    }
    function views() {
      var p = panel();
      if (!p) return null;
      var overview = p.querySelector('[data-ghca-admin-view="overview"]');
      var subpage = p.querySelector('[data-ghca-admin-view="subpage"]');
      var body = p.querySelector('#ghca-acd-edit-subpage-body');
      return (overview && subpage && body) ? { overview: overview, subpage: subpage, body: body } : null;
    }

    function showOverview(focusTrigger) {
      var v = views();
      if (!v) return;
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
      v.overview.hidden = true;
      v.subpage.hidden = false;
      v.body.setAttribute('aria-busy', 'true');
      v.body.innerHTML = '<p class="ghca-acd__drawer-empty">' +
        ((window.ghcaAcd && window.ghcaAcd.loading) || 'Loading…') + '</p>';

      // Move focus to the sub-page heading so keyboard and screen-reader users
      // follow the navigation rather than being left on the old view.
      var heading = v.subpage.querySelector('.ghca-acd__subpage-head h4');
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
      var saveBtn = form.querySelector('.ghca-acd__edit-btn--save');
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

    // Escape backs out of the sub-page, but only when no overlay is above it.
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var v = views();
      if (!v || v.subpage.hidden) return;
      if (ghcaAcdOverlay.top()) return; // an overlay is above the sub-page; let it handle Escape
      showOverview(true);
    });
  }

  function ghcaToast(message, isError) {
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
    var target = document.querySelector('#ghca-acd-employee-drawer [data-ghca-drawer-close]');
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

    function openModal() {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.style.display = 'none';
      document.body.style.overflow = '';
      form.reset();
      document.getElementById('ghca-acd-user-id').value = '';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        titleEl.textContent = 'Add Employee';
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
        form.elements['employment_type'].value = btn.getAttribute('data-employment_type') || '';
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
        if (mSummary) mSummary.className = state(pct >= 5, pct < 5);
        if (mCerts) mCerts.className = state(pct >= 92, pct >= 5 && pct < 92);
        if (mMerge) mMerge.className = isErr ? 'ghca-acd__packet-mirror-step is-error' : state(pct >= 100, pct >= 92);
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
      var drawer = opener.closest('.ghca-acd__drawer');
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
      var dialog = modal.querySelector('.ghca-acd__reminder-modal-dialog');
      var closeBtn = modal.querySelector('.ghca-acd__reminder-modal-close');
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

      closeReminder = function() {
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
        ghcaAcdOverlay.open(modal, reminderOpener, closeReminder);
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

        if (!reminderRelease) reminderRelease = ghcaFocusTrap(dialog, closeBtn);
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

        submitBtn.disabled = true;
        var originalLabel = submitBtn.textContent;
        submitBtn.textContent = 'Queueing…';
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

        post(params, reminderController ? reminderController.signal : undefined).then(function(json) {
          submitBtn.textContent = originalLabel;
          updateChannel(false);
          if (!json || !json.success) {
            setError((json && json.data && json.data.message) || 'The reminder could not be queued.');
            return;
          }
          ghcaToast((json.data && json.data.message) || 'The reminder was queued.', false);
          var employeeId = userIdInput.value;
          ghcaRetargetDrawerFocus(reminderRelease);
          closeReminder();
          if (window.ghcaAcdReloadDrawer) window.ghcaAcdReloadDrawer(employeeId);
        }).catch(function(err) {
          if (err && err.name === 'AbortError') return;
          submitBtn.textContent = originalLabel;
          submitBtn.disabled = false;
          setError('Network error. Please try again.');
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
      var form = e.target.closest('.ghca-acd__manual-entry-form');
      if (!form) return;
      e.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      var status = form.querySelector('.ghca-acd__manual-entry-status');
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

})();
