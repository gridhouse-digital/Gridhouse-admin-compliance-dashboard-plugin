(function () {
  'use strict';

  initCertificateModal();

  if (typeof window.ghcaAcd === 'undefined') {
    return;
  }

  var tableConfigs = {
    employees: {
      fields: ['ghca_group', 'ghca_course', 'ghca_status', 'ghca_emp_search', 'ghca_emp_per', 'ghca_orderby', 'ghca_order'],
      checkboxFields: ['ghca_overdue'],
      pageField: 'ghca_emp_page'
    },
    priority: {
      fields: ['ghca_pri_group', 'ghca_pri_type', 'ghca_pri_search', 'ghca_pri_per', 'ghca_orderby', 'ghca_order'],
      checkboxFields: [],
      pageField: 'ghca_pri_page'
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
            syncUrl();
          }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return;
          }
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
      
      var inputColumn = form.querySelector('input[name="ghca_orderby"]');
      var inputOrder = form.querySelector('input[name="ghca_order"]');
      
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
      currentUrl = '';
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

    function openModal(trigger) {
      var url = trigger.getAttribute('data-ghca-cert-url') || trigger.getAttribute('href') || '';
      if (!url) {
        return;
      }

      currentUrl = url;
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
      if (!currentUrl) {
        return;
      }

      var filename = slugify(currentTitle) + '.pdf';

      fetch(currentUrl, { credentials: 'same-origin' })
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
          window.open(currentUrl, '_blank', 'noopener');
        });
    }

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
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });
  }

  function activateTab(targetId) {
    if (!targetId) return;

    var tabBtn = document.querySelector('.ghca-acd__tab-btn[data-ghca-tab-target="' + targetId + '"]');
    if (!tabBtn) return;

    var tabContainer = tabBtn.closest('.ghca-acd__tabs');
    var allBtns = tabContainer ? tabContainer.querySelectorAll('.ghca-acd__tab-btn') : document.querySelectorAll('.ghca-acd__tab-btn');
    var allContents = document.querySelectorAll('.ghca-acd__tab-content');

    allBtns.forEach(function(btn) { btn.classList.remove('is-active'); });
    allContents.forEach(function(content) { content.classList.remove('is-active'); });

    tabBtn.classList.add('is-active');
    var targetContent = document.getElementById(targetId);
    if (targetContent) {
      targetContent.classList.add('is-active');
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

    function errorState(message) {
      return '<div class="ghca-acd__overlay-state ghca-acd__overlay-state--error" role="alert">' +
        '<p class="ghca-acd__overlay-state-msg">' + message + '</p>' +
        '<button type="button" class="ghca-acd__overlay-retry" data-ghca-drawer-retry>Try again</button>' +
        '</div>';
    }

    function openDrawer(userId) {
      lastUserId = userId;
      drawer.hidden = false;
      drawer.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--drawer-open');
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
      if (controller) controller.abort();
      if (release) { release(); release = null; }
    }

    // Let the Edit Records modal re-render the drawer with fresh data after a save.
    window.ghcaAcdReloadDrawer = openDrawer;

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-employee-drawer]');
      if (trigger) {
        e.preventDefault();
        openDrawer(trigger.getAttribute('data-ghca-employee-drawer'));
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
      if (e.key === 'Escape' && !drawer.hidden) {
        closeDrawer();
      }
    });
  }

  function initEditRecordsModal() {
    var modal = document.getElementById('ghca-acd-edit-modal');
    if (!modal) return;

    var dialog = modal.querySelector('.ghca-acd__edit-modal-dialog');
    var closeBtn = modal.querySelector('.ghca-acd__edit-modal-close');
    var body = document.getElementById('ghca-acd-edit-modal-body');
    var titleEl = document.getElementById('ghca-acd-edit-modal-title');
    var loadingHtml = body.innerHTML;
    var currentUserId = null;
    var controller = null;
    var release = null;

    function openModal(userId, name) {
      currentUserId = userId;
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('ghca-acd--edit-open');
      body.innerHTML = loadingHtml;
      body.setAttribute('aria-busy', 'true');
      if (titleEl) {
        titleEl.textContent = name ? 'Edit Records: ' + name : 'Edit Records';
      }

      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

      var params = new URLSearchParams();
      params.append('action', 'ghca_acd_get_edit_records_form');
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
        body.removeAttribute('aria-busy');
        if (json && json.success && json.data && json.data.html) {
          body.innerHTML = json.data.html;
          var firstField = body.querySelector('input, select, textarea');
          if (firstField) { try { firstField.focus({ preventScroll: true }); } catch (err) { firstField.focus(); } }
        } else {
          body.innerHTML = '<p class="ghca-acd__edit-error" role="alert">' + ((json && json.data && json.data.message) || 'We couldn’t load these records.') + '</p>';
        }
      })
      .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        body.removeAttribute('aria-busy');
        body.innerHTML = '<p class="ghca-acd__edit-error" role="alert">Network error. Please check your connection and try again.</p>';
      });

      release = ghcaFocusTrap(dialog, closeBtn);
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('ghca-acd--edit-open');
      if (controller) controller.abort();
      if (release) { release(); release = null; }
    }

    function submitForm(form) {
      var saveBtn = form.querySelector('.ghca-acd__edit-btn--save');
      var prevText = saveBtn ? saveBtn.textContent : '';
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = (window.ghcaAcd && window.ghcaAcd.loading) || 'Saving…';
      }

      var params = new URLSearchParams(new FormData(form));
      params.append('action', 'ghca_acd_save_employee_records');
      params.append('nonce', window.ghcaAcd.nonce);
      params.append('user_id', currentUserId);

      fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      })
      .then(function(res) { return res.json(); })
      .then(function(json) {
        if (json && json.success) {
          var savedUserId = currentUserId;
          ghcaToast((json.data && json.data.message) || 'Records updated.', false);
          closeModal();
          if (typeof window.ghcaAcdReloadDrawer === 'function') {
            window.ghcaAcdReloadDrawer(savedUserId);
          }
        } else {
          if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
          ghcaToast((json && json.data && json.data.message) || 'Could not save records.', true);
        }
      })
      .catch(function() {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = prevText; }
        ghcaToast('Network error. Please try again.', true);
      });
    }

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-edit-records]');
      if (trigger) {
        e.preventDefault();
        openModal(trigger.getAttribute('data-ghca-edit-records'), trigger.getAttribute('data-ghca-edit-records-name'));
        return;
      }
      if (e.target.closest('[data-ghca-edit-close]')) {
        e.preventDefault();
        closeModal();
      }
    });

    modal.addEventListener('submit', function(e) {
      var form = e.target.closest('[data-ghca-edit-form]');
      if (!form) return;
      e.preventDefault();
      submitForm(form);
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
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
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

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

    return function release() {
      container.removeEventListener('keydown', onKey);
      if (prev && typeof prev.focus === 'function') {
        try { prev.focus({ preventScroll: true }); } catch (err) { prev.focus(); }
      }
    };
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
    var running = false;
    var cancelled = false;

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
    }

    function openModal() {
      cancelled = false;
      bar.classList.remove('is-error');
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      running = false;
    }

    function failState(msg) {
      bar.classList.add('is-error');
      setProgress(100, msg || t('pdfError', 'Packet generation failed. No packet was created. Please try again.'));
      running = false;
    }

    function post(params) {
      params.append('nonce', window.ghcaAcd.nonce);
      return fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function(res) { return res.json(); });
    }

    function run(userId, tracker) {
      if (running) return;
      running = true;
      openModal();
      setProgress(3, t('pdfPreparing', 'Preparing packet…'));

      var initParams = new URLSearchParams();
      initParams.append('action', 'ghca_acd_pdf_init');
      initParams.append('user_id', userId);
      initParams.append('tracker', tracker);

      post(initParams).then(function(json) {
        if (cancelled) return;
        if (!json || !json.success || !json.data || !json.data.job_id) {
          failState(json && json.data && json.data.message);
          return;
        }

        var jobId = json.data.job_id;
        var total = parseInt(json.data.total, 10) || 0;

        function mergeJob() {
          setProgress(92, t('pdfMerging', 'Merging documents…'));
          var mp = new URLSearchParams();
          mp.append('action', 'ghca_acd_pdf_merge');
          mp.append('job_id', jobId);
          post(mp).then(function(mj) {
            if (cancelled) return;
            if (mj && mj.success && mj.data && mj.data.download_url) {
              setProgress(100, t('pdfDone', 'Done! Starting download…'));
              window.location.assign(mj.data.download_url);
              window.setTimeout(closeModal, 1500);
            } else {
              failState(mj && mj.data && mj.data.message);
            }
          }).catch(function() { failState(); });
        }

        function fetchNext(i) {
          if (cancelled) return;
          if (i >= total) { mergeJob(); return; }

          setProgress(5 + Math.round((i / total) * 85), sprintf1(t('pdfFetching', 'Fetching certificate %1$s of %2$s…'), String(i + 1), String(total)));

          var fp = new URLSearchParams();
          fp.append('action', 'ghca_acd_pdf_fetch');
          fp.append('job_id', jobId);
          fp.append('index', String(i));
          post(fp).then(function(fj) {
            if (cancelled) return;
            // ABORT policy: any fetch failure ends the job; the server has
            // already deleted the manifest and temp files at this point.
            if (!fj || !fj.success) { failState(fj && fj.data && fj.data.message); return; }
            fetchNext(i + 1);
          }).catch(function() { failState(); });
        }

        if (total === 0) { mergeJob(); } else { fetchNext(0); }
      }).catch(function() { failState(); });
    }

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-pdf-packet]');
      if (trigger) {
        e.preventDefault();
        run(trigger.getAttribute('data-ghca-pdf-packet'), trigger.getAttribute('data-tracker') || 'annual');
        return;
      }
      if (e.target.closest('[data-ghca-pdf-cancel]')) {
        e.preventDefault();
        cancelled = true;
        closeModal();
      }
    });
  }

  initTabs();
  initEmployeeDrawer();
  initEditRecordsModal();
  initAnnouncements();
  initManageUsers();
  initPdfPacket();

})();
