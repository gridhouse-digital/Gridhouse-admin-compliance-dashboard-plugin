/* Redesigned settings shell (redesign switch on). Presentation only: moves the page's own content into the shell,
 * shows one section of the main Settings page at a time, and offers the unsaved-changes bar. Forms, field names and
 * save handlers are untouched. Without JavaScript the page content stays where WordPress printed it. */
(function () {
  'use strict';
  var shell = document.getElementById('ghca-set-shell');
  var body = shell && shell.querySelector('[data-ghca-set-body]');
  var content = document.getElementById('wpbody-content');
  if (!shell || !body || !content) { return; }

  // Page notices (WordPress and plugin) go above the section, the page itself into the section body.
  var main = shell.querySelector('[data-ghca-set-main]');
  Array.prototype.slice.call(content.querySelectorAll(':scope > .notice, :scope > .updated, :scope > .error')).forEach(function (n) {
    main.insertBefore(n, shell.querySelector('.ghca-set-title'));
  });
  Array.prototype.slice.call(content.querySelectorAll(':scope > .wrap')).forEach(function (w) { body.appendChild(w); });
  shell.classList.add('is-ready');

  // Main Settings page: show only the blocks that make up this section.
  var anchors = (shell.getAttribute('data-ghca-set-anchors') || '').split(' ').filter(Boolean);
  if (anchors.length) {
    var all = body.querySelectorAll('.ghca-settings-section, #ghca-packet-selection');
    Array.prototype.forEach.call(all, function (el) {
      var on = anchors.indexOf(el.id) !== -1;
      el.hidden = !on;
      if (on && el.tagName === 'DETAILS') { el.open = true; }
    });
    // The settings form's own Save button belongs to sections inside it; hide it when none of them is shown.
    Array.prototype.forEach.call(body.querySelectorAll('form'), function (f) {
      var sections = f.querySelectorAll('.ghca-settings-section');
      if (!sections.length) { return; }
      var shown = Array.prototype.some.call(sections, function (s) { return !s.hidden; });
      var submit = f.querySelector(':scope > .submit');
      if (submit) { submit.hidden = !shown; }
    });
  }

  // Narrow screens: the section picker navigates.
  var pick = shell.querySelector('[data-ghca-set-pick]');
  if (pick) { pick.addEventListener('change', function () { window.location.href = pick.value; }); }

  // Unsaved-changes bar.
  var bar = shell.querySelector('[data-ghca-set-dirty]');
  var lastForm = null;
  var mark = function (e) {
    var f = e.target && e.target.form;
    if (!f || f.getAttribute('method') === 'get' || f.closest('[hidden]')) { return; }
    lastForm = f;
    if (bar) { bar.hidden = false; }
  };
  body.addEventListener('input', mark);
  body.addEventListener('change', mark);
  body.addEventListener('submit', function () { if (bar) { bar.hidden = true; } });
  if (bar) {
    bar.querySelector('[data-ghca-set-discard]').addEventListener('click', function () { window.location.reload(); });
    bar.querySelector('[data-ghca-set-save]').addEventListener('click', function () {
      if (!lastForm) { return; }
      if (typeof lastForm.requestSubmit === 'function') { lastForm.requestSubmit(); } else { lastForm.submit(); }
    });
  }
})();
