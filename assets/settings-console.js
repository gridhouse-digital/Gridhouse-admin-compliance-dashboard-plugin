/* Native forms remain responsible for validation, nonce handling and persistence. */
(function () {
  'use strict';
  if (!document.body.classList.contains('ghca-settings-console')) return;
  function revealSection(id, focus) {
    var section = document.getElementById(id);
    if (!section) return;
    if (section.classList.contains('ghca-settings-section')) section.open = true;
    section.scrollIntoView({block: 'start'});
    if (focus) {
      var target = section.querySelector('summary, h2') || section;
      if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
      target.focus({preventScroll: true});
    }
    document.querySelectorAll('.ghca-console-header nav a').forEach(function (link) {
      var url = new URL(link.href, window.location.href);
      if (url.pathname !== window.location.pathname || url.search !== window.location.search) return;
      var current = (url.hash.slice(1) || 'ghca-training') === id;
      if (current) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }
  function revealHash() { if (window.location.hash) revealSection(window.location.hash.slice(1), false); }
  revealHash();
  window.addEventListener('hashchange', revealHash);
  document.querySelectorAll('.ghca-console-header nav a').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      var url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.search !== window.location.search) return;
      var id = url.hash.slice(1) || 'ghca-training';
      if (!document.getElementById(id)) return;
      event.preventDefault();
      // Handle repeated clicks too: closing a section does not change its URL hash.
      if (window.location.hash !== '#' + id) window.history.pushState(null, '', '#' + id);
      revealSection(id, true);
    });
  });
  // Native validation must be able to focus a field inside a collapsed section.
  document.addEventListener('invalid', function (event) {
    var section = event.target.closest('.ghca-settings-section');
    if (section) section.open = true;
  }, true);
  var dirty = new Set();
  var submitting = false;
  document.querySelectorAll('#wpbody-content > .wrap form').forEach(function (form) {
    // Only configuration forms; operational reviews and connection tests keep their own UX.
    if (!form.querySelector('input[name="option_page"]')) return;
    var submit = form.querySelector('.submit');
    if (!submit) return;
    var status = document.createElement('span');
    status.className = 'ghca-form-status';
    status.setAttribute('role', 'status');
    status.textContent = 'No unsaved changes in this form';
    submit.appendChild(status);
    form.addEventListener('input', function () { dirty.add(form); status.textContent = 'Unsaved changes in this form'; });
    form.addEventListener('change', function () { dirty.add(form); status.textContent = 'Unsaved changes in this form'; });
    form.addEventListener('submit', function (event) {
      var otherForms = Array.from(dirty).some(function (item) { return item !== form; });
      if (otherForms && !window.confirm('Other forms on this page have unsaved changes. Saving this form reloads the page and discards those changes. Continue?')) {
        event.preventDefault(); return;
      }
      submitting = true;
    });
  });
  window.addEventListener('beforeunload', function (event) {
    if (!submitting && dirty.size) { event.preventDefault(); event.returnValue = ''; }
  });
})();
