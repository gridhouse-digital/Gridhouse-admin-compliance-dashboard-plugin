'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
let source = fs.readFileSync(path.join(root, 'assets', 'dashboard.js'), 'utf8');
source = source.replace(/\}\)\(\);\s*$/, 'window.__ghcaTest = { focusTrap: ghcaFocusTrap, retargetDrawerFocus: ghcaRetargetDrawerFocus, initPdfPacket: initPdfPacket, closeDrawerMoreMenus: closeDrawerMoreMenus };\n})();');

function classList() {
  const values = new Set();
  return {
    add: (...names) => names.forEach((name) => values.add(name)),
    remove: (...names) => names.forEach((name) => values.delete(name)),
    toggle: (name, force) => force ? values.add(name) : values.delete(name),
    contains: (name) => values.has(name)
  };
}

const listeners = {};
const document = {
  activeElement: null,
  documentElement: { classList: classList() },
  body: { appendChild() {} },
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll() { return []; },
  addEventListener(type, handler) { (listeners[type] ||= []).push(handler); },
  createElement() { return { className: '', classList: classList(), setAttribute() {}, remove() {}, textContent: '' }; }
};
const window = {
  ghcaAcd: { ajaxUrl: '/ajax', nonce: 'nonce' },
  location: { hash: '', assign() { window.downloaded = true; } },
  history: { replaceState() {} },
  addEventListener() {}
};
window.window = window;

const context = vm.createContext({
  window,
  document,
  console,
  URL,
  URLSearchParams,
  AbortController,
  FormData: class FormData {},
  setTimeout,
  clearTimeout,
  fetch: () => Promise.reject(new Error('unexpected fetch'))
});
vm.runInContext(source, context, { filename: 'dashboard.js' });

// The shared stack must drive the one composed page-lock class.
const layer = {};
window.ghcaAcdOverlay.open(layer, null, () => {});
assert(document.documentElement.classList.contains('ghca-acd--overlay-open'));
window.ghcaAcdOverlay.close(layer);
assert(!document.documentElement.classList.contains('ghca-acd--overlay-open'));
const css = fs.readFileSync(path.join(root, 'assets', 'dashboard.css'), 'utf8');
const messagingUi = fs.readFileSync(path.join(root, 'includes', 'messaging', 'class-messaging-ui.php'), 'utf8');
assert(css.includes('html.ghca-acd--overlay-open { overflow: hidden; }'));
assert(css.includes('1.6.6 connected popup parity'));
assert(css.includes('1.6.6 V2 popup source-of-truth alignment'));
assert(css.includes('.ghca-acd__pdf-modal-header'));
assert(css.includes('.ghca-acd__history-filters'));
assert(css.includes('.ghca-acd__overlay--edit .ghca-acd__edit-section'));
assert(css.includes('.ghca-acd__v2-form-grid'));
assert(css.includes('.ghca-acd__history-tools'));
assert(messagingUi.includes('ghca-acd__v2-recipient-avatar'));
assert(messagingUi.includes('ghca-acd__history-tools ghca-acd__v2-modal-tools'));
assert(messagingUi.includes('ghca-acd__history-pagination ghca-acd__v2-modal-footer'));
assert(messagingUi.includes('ghca-acd__v2-pager-actions'));
assert(messagingUi.includes('data-ghca-reminder-channel-option'));
assert(messagingUi.includes('ghca-acd__reminder-channel-options'));
assert(css.includes('.ghca-acd__sms-confirm[hidden]'));
assert(css.includes('width: 18px; min-width: 18px; height: 18px; min-height: 18px'));
const ajaxHandlers = fs.readFileSync(path.join(root, 'includes', 'class-ajax-handlers.php'), 'utf8');
assert(ajaxHandlers.includes('ghca-acd__pdf-modal-header'));
assert(ajaxHandlers.includes('ghca-acd__pdf-modal-body'));
assert(ajaxHandlers.includes('ghca-acd__packet-symbol'));
assert(ajaxHandlers.includes('data-ghca-pdf-step="merge"'));
assert(source.includes("'ghca-acd__packet-step' + (pct < 5 ? ' is-active'"));
const documentJs = fs.readFileSync(path.join(root, 'assets', 'jotform.js'), 'utf8');
const documentCss = fs.readFileSync(path.join(root, 'assets', 'jotform.css'), 'utf8');
assert(documentJs.includes('ghca-jotform-modal__body'));
assert(documentJs.includes('ghca-jotform-modal__intro'));
assert(documentJs.includes('All employee documents'));
assert(documentJs.includes('data-ghca-doc-search-form'));
assert(documentJs.includes('<input type="text" placeholder="Search documents"'));
assert(documentJs.includes('ghca-jotform-list__file'));
assert(documentJs.includes('ghca-jotform-modal__result-line'));
assert(documentCss.includes('1.6.6 employee document popup parity'));
assert(documentCss.includes('1.6.6 V2 document-popup source-of-truth alignment'));
assert(documentCss.includes('input::-webkit-search-decoration'));
assert(documentCss.includes('padding: 8px 10px !important'));
assert(documentCss.includes('background-image: none !important'));
assert(documentCss.includes('grid-template-columns: 38px minmax(0, 1fr) auto auto'));
assert(documentCss.includes('overflow-x: hidden'));
assert(documentCss.includes('footer .ghca-jotform-list__actions'));

// A popup may retarget restoration before its drawer launcher is replaced.
let restored = '';
const detached = { focus() { restored = 'detached'; } };
const stable = { focus() { restored = 'stable'; }, closest() { return null; } };
const initial = { offsetWidth: 1, offsetHeight: 1, focus() { document.activeElement = initial; } };
const container = {
  addEventListener() {},
  removeEventListener() {},
  querySelectorAll() { return [initial]; },
  contains() { return true; }
};
document.activeElement = detached;
const release = window.__ghcaTest.focusTrap(container, initial);
release.setRestoreTarget(stable);
release();
assert.strictEqual(restored, 'stable');
let retargeted = null;
document.querySelector = () => stable;
window.__ghcaTest.retargetDrawerFocus({ setRestoreTarget(target) { retargeted = target; } });
assert.strictEqual(retargeted, stable);
// 3 = the definition plus the reminder and history call sites. The edit
// records modal was replaced by an in-drawer sub-page in the Administration
// tab, so it no longer restores focus from an overlay.
assert.strictEqual((source.match(/ghcaRetargetDrawerFocus\(/g) || []).length, 3);
document.querySelector = () => null;

// Native details menus close after an outside click or after selecting an action.
let removedOpen = 0;
const menuAction = {};
const moreMenu = {
  contains(target) { return target === menuAction; },
  removeAttribute(name) { if (name === 'open') removedOpen++; }
};
document.querySelectorAll = (selector) => selector === '.ghca-acd__drawer-more[open]' ? [moreMenu] : [];
window.__ghcaTest.closeDrawerMoreMenus({ closest() { return null; } });
assert.strictEqual(removedOpen, 1);
window.__ghcaTest.closeDrawerMoreMenus(menuAction);
assert.strictEqual(removedOpen, 1);
menuAction.closest = (selector) => selector === '.ghca-acd__drawer-more-menu button' ? menuAction : null;
window.__ghcaTest.closeDrawerMoreMenus(menuAction);
assert.strictEqual(removedOpen, 2);
document.querySelectorAll = () => [];

// Cancelling and immediately restarting must not let the old initialization
// response take over the new run.
const bar = { style: {}, classList: classList() };
const track = { setAttribute() {} };
const label = { textContent: '' };
const cancel = { offsetWidth: 1, offsetHeight: 1, focus() { document.activeElement = cancel; } };
const dialog = {
  addEventListener() {},
  removeEventListener() {},
  querySelectorAll() { return [cancel]; },
  contains() { return true; }
};
const modal = {
  hidden: true,
  setAttribute() {},
  querySelector(selector) {
    return ({
      '[data-ghca-pdf-bar]': bar,
      '[data-ghca-pdf-track]': track,
      '[data-ghca-pdf-label]': label,
      '.ghca-acd__pdf-modal-dialog': dialog,
      '[data-ghca-pdf-cancel]': cancel
    })[selector] || null;
  }
};
document.getElementById = (id) => id === 'ghca-acd-pdf-modal' ? modal : null;

const requests = [];
const initResolvers = [];
context.fetch = (url, options) => {
  const body = String(options.body);
  requests.push(body);
  if (body.includes('action=ghca_acd_pdf_init')) {
    return new Promise((resolve) => { initResolvers.push(resolve); });
  }
  return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
};
window.__ghcaTest.initPdfPacket();

const trigger = {
  getAttribute(name) { return name === 'data-ghca-pdf-packet' ? '47' : (name === 'data-tracker' ? 'annual' : ''); },
  closest() { return null; }
};
const click = {
  target: { closest(selector) { return selector === '[data-ghca-pdf-packet]' ? trigger : null; } },
  preventDefault() {}
};
listeners.click.forEach((handler) => handler(click));
assert.strictEqual(modal.hidden, false);
assert.strictEqual(initResolvers.length, 1);
window.ghcaAcdOverlay.top().close();
assert.strictEqual(modal.hidden, true);

listeners.click.forEach((handler) => handler(click));
assert.strictEqual(modal.hidden, false);
assert.strictEqual(initResolvers.length, 2);

initResolvers[0]({ json: () => Promise.resolve({ success: true, data: { job_id: 'job-old', total: 0 } }) });
setTimeout(() => {
  assert(requests.some((body) => body.includes('action=ghca_acd_pdf_cancel') && body.includes('job_id=job-old')));
  assert(!requests.some((body) => body.includes('action=ghca_acd_pdf_merge') && body.includes('job_id=job-old')));
  assert.strictEqual(modal.hidden, false);
  assert.strictEqual(window.downloaded, undefined);

  initResolvers[1]({ json: () => Promise.resolve({ success: true, data: { job_id: 'job-new', total: 0 } }) });
  setTimeout(() => {
    assert(requests.some((body) => body.includes('action=ghca_acd_pdf_merge') && body.includes('job_id=job-new')));
    assert(!requests.some((body) => body.includes('action=ghca_acd_pdf_merge') && body.includes('job_id=job-old')));
    window.ghcaAcdOverlay.top().close();
    console.log('ALL PASS');
  }, 0);
}, 0);
