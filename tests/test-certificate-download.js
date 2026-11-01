'use strict';

// GRI-94: a 200 text/html answer must never be saved as a certificate ".pdf".
// Drives the real downloadCertificate() from assets/dashboard.js with a stubbed fetch and DOM.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
let source = fs.readFileSync(path.join(root, 'assets', 'dashboard.js'), 'utf8');
source = source.replace(/\}\)\(\);\s*$/, 'window.__ghcaTest = { isPdf: ghcaCertificateResponseIsPdf, initCertificateModal: initCertificateModal };\n})();');

function classList() {
  const values = new Set();
  return { add: (...n) => n.forEach((x) => values.add(x)), remove: (...n) => n.forEach((x) => values.delete(x)), toggle() {}, contains: (n) => values.has(n) };
}
function el() {
  return { hidden: false, textContent: '', classList: classList(), attrs: {}, listeners: {},
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }, removeAttribute(k) { delete this.attrs[k]; },
    addEventListener(t, h) { this.listeners[t] = h; }, removeEventListener() {}, focus() {}, remove() {}, querySelectorAll() { return []; }, contains() { return true; }, closest() { return null; } };
}

const nodes = {
  '.ghca-acd__cert-modal-dialog': el(), '.ghca-acd__cert-frame': el(), '.ghca-acd__cert-modal-loading': el(),
  '#ghca-acd-cert-modal-title': el(), '[data-ghca-cert-download]': el(), '.ghca-acd__cert-modal-close': el(),
  '[data-ghca-cert-download-error]': el()
};
const modal = Object.assign(el(), { querySelector: (sel) => nodes[sel] || null });
const created = [];
const document = {
  activeElement: null,
  documentElement: { classList: classList() },
  body: { appendChild() {} },
  getElementById: (id) => (id === 'ghca-acd-cert-modal' ? modal : null),
  querySelector() { return null; },
  querySelectorAll() { return []; },
  addEventListener() {},
  createElement() { const a = el(); a.click = () => created.push(a.download); return a; }
};
const window = { ghcaAcd: { ajaxUrl: '/ajax', nonce: 'n' }, location: { hash: '', href: 'https://example.test/dash/', origin: 'https://example.test' }, history: { replaceState() {} }, addEventListener() {} };
window.window = window;

let objectUrls = 0;
let nextResponse = null;
const context = vm.createContext({
  window, document, console, URL: Object.assign(URL, { createObjectURL() { objectUrls++; return 'blob:x'; }, revokeObjectURL() {} }), URLSearchParams, AbortController,
  FormData: class FormData {}, Blob, Uint8Array, setTimeout, clearTimeout, fetch: () => Promise.resolve(nextResponse)
});
vm.runInContext(source, context, { filename: 'dashboard.js' });
const { isPdf, initCertificateModal } = window.__ghcaTest;

function bytes(text) { return new TextEncoder().encode(text); }
function response(status, contentType, body) {
  const data = bytes(body);
  return { ok: status >= 200 && status < 300, status, headers: { get: (h) => (h.toLowerCase() === 'content-type' ? contentType : null) }, arrayBuffer: () => Promise.resolve(data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength)) };
}
const settle = () => new Promise((resolve) => setTimeout(resolve, 10));

(async () => {
  // Decision function.
  assert.strictEqual(isPdf('application/pdf', bytes('%PDF-1.7 ...')), true);
  assert.strictEqual(isPdf('application/pdf; charset=binary', bytes('%PDF-1.4')), true);
  assert.strictEqual(isPdf('text/html; charset=UTF-8', bytes('%PDF-1.7')), false, 'wrong content type');
  assert.strictEqual(isPdf('application/pdf', bytes('<!DOCTYPE html>')), false, 'html body behind a pdf header');
  assert.strictEqual(isPdf('', bytes('%PDF-1.7')), false, 'missing content type');
  assert.strictEqual(isPdf('application/pdf', bytes('%PD')), false, 'short body');
  assert.strictEqual(isPdf('application/pdfx', bytes('%PDF-1.7')), false);

  // Real click flow.
  initCertificateModal();
  window.ghcaAcdOpenCertificateModal('https://example.test/certificates/x/?course_id=1', 'Course One', 'https://example.test/wp-admin/admin-ajax.php?action=ghca_acd_certificate_download&user_id=2&course_id=1&nonce=n');
  const click = nodes['[data-ghca-cert-download]'].listeners.click;
  const errorEl = nodes['[data-ghca-cert-download-error]'];
  assert.strictEqual(typeof click, 'function');

  nextResponse = response(200, 'text/html; charset=UTF-8', 'Access to certificate page is disallowed.');
  click(); await settle();
  assert.strictEqual(created.length, 0, '200 text/html must not trigger a download');
  assert.strictEqual(objectUrls, 0, 'no blob is created for an html answer');
  assert.strictEqual(errorEl.hidden, false);
  assert(/could not be downloaded/i.test(errorEl.textContent), 'inline error is shown');

  nextResponse = response(200, 'text/html', '<!DOCTYPE html><html>dashboard</html>');
  click(); await settle();
  assert.strictEqual(created.length, 0, 'dashboard HTML is not saved as a pdf');

  nextResponse = response(403, 'text/plain', 'Certificate could not be downloaded.');
  click(); await settle();
  assert.strictEqual(created.length, 0, 'a refusal is not saved');

  nextResponse = response(200, 'application/pdf', '%PDF-1.7\n1 0 obj');
  click(); await settle();
  assert.deepStrictEqual(created, ['course-one.pdf'], 'a real PDF is saved once, with a .pdf name');
  assert.strictEqual(errorEl.hidden, true, 'error clears on a successful download');

  console.log('ALL PASS');
})().catch((err) => { console.error(err); process.exit(1); });
