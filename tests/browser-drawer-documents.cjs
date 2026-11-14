'use strict';
/**
 * Drawer Documents tab: PDF previews open in the redesigned certificate viewer (synthetic drawer preview: the plugin's
 * own PHP with fixture data; canned grant endpoints; nothing sent; no record changed). Runs full Chromium in its new
 * headless mode, which has the built-in PDF viewer, so the documents are really rendered.
 *
 * The grant endpoints are mocked but behave like the real ones in what matters here: every grant is single use and
 * is minted for one access ('preview' or 'download'); a download is served as an attachment.
 *
 *   node tests/browser-drawer-documents.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const preview = path.join(__dirname, 'render-drawer-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

// A synthetic landscape page with a red border on its edges.
function makePdf(w, h, label) {
  const content = `1 0 0 RG 12 w 6 6 ${w - 12} ${h - 12} re S\nBT /F1 30 Tf 40 ${h / 2} Td (${label}) Tj ET`;
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>`, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
  let out = '%PDF-1.4\n'; const offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer << /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}
const DOC = makePdf(792, 612, 'SYNTHETIC EMPLOYEE DOCUMENT');

const RECORDS = [
  { kind: 'jotform', id: 7, name: 'Onboarding form.pdf', context: 'Onboarding', media_type: 'application/pdf', timestamp: 1792490400, status: 'Awaiting review', status_key: 'pending', viewable: true },
  { kind: 'manual', id: 12, name: 'Outside CPR card.pdf', context: 'CPR / First Aid', media_type: 'application/pdf', timestamp: 1793354400, status: 'Approved', status_key: 'approved', viewable: true },
  { kind: 'manual', id: 9, name: 'Red Cross card.jpg', context: 'CPR / First Aid', media_type: 'image/jpeg', timestamp: 1793354400, status: 'Approved', status_key: 'approved', viewable: true },
];

function build(dir) {
  fs.mkdirSync(dir, { recursive: true });
  const env = { jotform: true, messaging: true, history: [], hist_total: 0, records: RECORDS };
  execFileSync('php', [preview, 'page', dir, 'gridhouse', ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_ENV: JSON.stringify(env) } });
  return { file: path.join(dir, 'drawer.html'), env };
}

function origin(file, state) {
  return async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(req.postData() || ''));
      if (params.action === 'ghca_acd_get_employee_drawer') {
        delete params.action;
        const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), JSON.stringify(state.env)]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_jotform_document_token' || params.action === 'ghca_acd_jotform_manual_token') {
        const access = params.access === 'download' ? 'download' : 'preview';
        state.grants.push({ action: params.action, access, document: params.document_id || '', training: params.training_id || '' });
        if (state.refuseGrant) { return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Not allowed.' } }) }); }
        const token = 't' + state.grants.length;
        state.tokens[token] = { access, used: false };
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { url: 'https://preview.test/doc/' + token } }) });
      }
      if (params.action === 'ghca_acd_jotform_documents') {
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { page: 1, pages: 1, total: 3, items: [
          { kind: 'jotform', id: 7, name: 'Onboarding form.pdf', form: 'Onboarding "intake" <form>', date: 'Oct 20, 2026', status: 'Awaiting review', type: 'PDF', viewable: true },
          { kind: 'manual', id: 12, name: 'Outside CPR card.pdf', form: 'CPR / First Aid', date: 'Oct 30, 2026', status: 'Approved', type: 'PDF', viewable: true },
          { kind: 'manual', id: 9, name: 'Red Cross card.jpg', form: 'CPR / First Aid', date: 'Oct 30, 2026', status: 'Approved', type: 'JPEG', viewable: true },
        ] } }) });
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'not in this test' } }) });
    }
    if (url.pathname.startsWith('/doc/')) {
      const token = url.pathname.slice(5);
      const g = state.tokens[token];
      state.served.push({ token, access: g ? g.access : '?', reused: !!(g && g.used) });
      // Single use, like the real grants: a second request with the same grant is refused.
      if (!g || g.used) { return route.fulfill({ status: 410, contentType: 'text/html', body: '<p>This link has expired.</p>' }); }
      g.used = true;
      if (state.failNextPreview && g.access === 'preview') { state.failNextPreview = false; return route.fulfill({ status: 500, contentType: 'text/html', body: '<p>Evidence source unavailable.</p>' }); }
      return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': (g.access === 'download' ? 'attachment' : 'inline') + '; filename="document.pdf"', 'Content-Security-Policy': 'sandbox' }, body: DOC });
    }
    if (url.pathname === '/drawer.html') { return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) { return route.fulfill({ contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) }); }
    }
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>stub</p>' });
  };
}

// The page's red border lines in a screenshot of the viewer frame.
async function extent(page) {
  const png = (await page.locator('[data-ghca-cv-frame]').screenshot()).toString('base64');
  return page.evaluate((b64) => new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
      const g = c.getContext('2d'); g.drawImage(img, 0, 0);
      const d = g.getImageData(0, 0, c.width, c.height).data;
      const W = c.width, H = c.height, col = new Array(W).fill(0), row = new Array(H).fill(0);
      for (let y = 0; y < H; y += 1) { for (let x = 0; x < W; x += 1) { const i = (y * W + x) * 4; if (d[i] > 200 && d[i + 1] < 70 && d[i + 2] < 70) { col[x] += 1; row[y] += 1; } } }
      const cols = col.map((v, x) => (v >= H * 0.5 ? x : -1)).filter((x) => x >= 0);
      const rows = row.map((v, y) => (v >= W * 0.5 ? y : -1)).filter((y) => y >= 0);
      resolve({ w: W, h: H, x0: cols.length ? cols[0] : -1, x1: cols.length ? cols[cols.length - 1] : -1, y0: rows.length ? rows[0] : -1, y1: rows.length ? rows[rows.length - 1] : -1 });
    };
    img.src = 'data:image/png;base64,' + b64;
  }), png);
}
const whole = (e) => e.x0 >= 0 && e.x0 <= e.w * 0.04 && e.x1 >= e.w * 0.96 && e.y0 >= 0 && e.y0 <= e.h * 0.06 && e.y1 >= e.h * 0.94;
async function rendered(page) { let e; for (let i = 0; i < 40; i += 1) { await page.waitForTimeout(250); e = await extent(page); if (whole(e)) { return e; } } return e; }
const current = (page) => page.getAttribute('#ghca-acd-cert-viewer', 'data-ghca-cv-current');
const ready = (page) => page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'ready');

async function open(browser, built, viewport) {
  const state = { env: built.env, grants: [], tokens: {}, served: [] };
  const context = await browser.newContext({ viewport, acceptDownloads: true });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
  await page.route('https://preview.test/**', origin(built.file, state));
  await page.goto('https://preview.test/drawer.html#ghca-tab-employees');
  await page.waitForSelector('#open-drawer');
  // Record any use of the legacy certificate modal, which non-PDF files (and the legacy dashboard) still use.
  await page.evaluate(() => { window.__legacy = []; const orig = window.ghcaAcdOpenCertificateModal; window.ghcaAcdOpenCertificateModal = function (u, t, d, f) { window.__legacy.push({ u, t, d }); return orig.apply(this, arguments); }; });
  await page.click('#open-drawer');
  await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drawer-hd');
  await page.click('#ghca-acd-drawer-tab-documents');
  await page.waitForSelector('[data-ghca-documents-section] .gh-file');
  return { context, page, errors, state };
}

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-docs-'));
  const built = build(dir);
  const browser = await chromium.launch({ channel: 'chromium' });

  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { context, page, errors, state } = await open(browser, built, vp);
    // ---- Jotform PDF from the drawer row.
    const btn = page.locator('[data-ghca-document-open="7"]');
    assert.equal(await btn.getAttribute('data-ghca-doc-pdf'), '1');
    await btn.click();
    await ready(page);
    assert.deepEqual(state.grants, [{ action: 'ghca_acd_jotform_document_token', access: 'preview', document: '7', training: '' }]);
    assert.deepEqual(state.served.map((s) => s.access), ['preview']);
    assert.equal(await page.evaluate(() => window.__legacy.length), 0);
    ok(w + ': Preview on a drawer PDF opens the redesigned viewer (not the legacy modal) with exactly one preview grant, used once');
    assert.equal(await page.locator('[data-ghca-cv-heading]').innerText(), 'Document · Onboarding form.pdf');
    assert.equal((await page.locator('[data-ghca-cv-chip]').innerText()).trim(), 'Awaiting review');
    const dl = await page.locator('[data-ghca-cv-dl]').innerText();
    assert.match(dl, /Employee\s*Jordan Ellis[\s\S]*Form\s*Onboarding[\s\S]*Date\s*\S[\s\S]*Review\s*Awaiting review[\s\S]*Source\s*Jotform submission[\s\S]*File\s*PDF · 1 page/);
    assert.equal((await page.locator('[data-ghca-cv-side-h]').innerText()).trim(), 'Document details');
    assert.match(await page.locator('[data-ghca-cv-note]').innerText(), /separate, recorded download/);
    ok(w + ': the viewer shows the document\'s facts (employee, form, date, review status, source, file) under "Document details", with a note that Download is a separate, recorded download');
    const e = await rendered(page);
    assert.ok(whole(e), JSON.stringify(e)); ok(w + ': the PDF is rendered by the browser\'s PDF viewer, whole page visible in Fit to width');
    if (shots) { await page.screenshot({ path: path.join(shots, 'docs-viewer-' + w + '.png') }); }
    // ---- Download: a fresh download grant, served as an attachment; the preview bytes are not reused.
    const dlWait = page.waitForEvent('download');
    await page.locator('.gh-cv-ft [data-ghca-cv-download]').click();
    const d = await dlWait;
    const bytes = fs.readFileSync(await d.path());
    assert.equal(bytes.subarray(0, 5).toString(), '%PDF-');
    assert.deepEqual(state.grants.map((g) => g.access), ['preview', 'download']);
    assert.deepEqual(state.served.map((s) => s.access + (s.reused ? ':reused' : '')), ['preview', 'download']);
    ok(w + ': Download asks for its own download grant and saves the file the server sends as an attachment (preview and download stay separate)');
    // ---- Close: focus back on the Preview button.
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-cert-viewer[hidden]', { state: 'attached' });
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-ghca-document-open')), '7');
    ok(w + ': Escape closes the viewer and focus returns to the Preview button');
    // ---- Outside certificate (manual entry) PDF.
    await page.locator('[data-ghca-manual-open="12"]').click();
    await ready(page);
    const last = state.grants[state.grants.length - 1];
    assert.deepEqual(last, { action: 'ghca_acd_jotform_manual_token', access: 'preview', document: '', training: '12' });
    assert.match(await page.locator('[data-ghca-cv-dl]').innerText(), /Source\s*Outside certificate \(manual entry\)/);
    assert.equal((await page.locator('[data-ghca-cv-chip]').innerText()).trim(), 'Approved');
    ok(w + ': an outside-certificate PDF opens in the same viewer through its own manual-entry preview grant');
    await page.keyboard.press('Escape');
    assert.equal(errors.length, 0, errors.join(' | '));
    await context.close();
  }

  // ---- Non-PDF files keep their existing preview; a failed grant or file shows the viewer's error with Try again.
  {
    const { context, page, errors, state } = await open(browser, built, { width: 1440, height: 900 });
    await page.locator('[data-ghca-manual-open="9"]').click();
    await page.waitForFunction(() => window.__legacy.length === 1);
    assert.equal(await page.getAttribute('#ghca-acd-cert-viewer', 'hidden'), '');
    assert.deepEqual(state.grants.map((g) => g.access).sort(), ['download', 'preview']);
    ok('a JPEG keeps its existing preview (legacy modal with its preview and download grants); the redesigned viewer stays closed');
    await page.evaluate(() => { const x = document.querySelector('[data-ghca-cert-close]'); if (x) { x.click(); } });
    state.grants.length = 0;
    state.failNextPreview = true;
    await page.locator('[data-ghca-document-open="7"]').click();
    await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'error');
    await page.locator('[data-ghca-cv-retry]').click();
    await ready(page);
    assert.deepEqual(state.grants.map((g) => g.access), ['preview', 'preview']);
    assert.equal(state.served.filter((s) => s.reused).length, 0);
    ok('the document cannot be read: the viewer shows its error state; Try again asks for a fresh preview grant (a used grant is never reused) and shows it');
    await page.keyboard.press('Escape');
    state.refuseGrant = true;
    await page.locator('[data-ghca-document-open="7"]').click();
    await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'error');
    assert.equal(await page.locator('[data-ghca-cv-frame]').isVisible(), false);
    ok('the server refuses the grant: the viewer shows the error state and nothing is displayed');
    await page.keyboard.press('Escape');
    state.refuseGrant = false;
    assert.equal(errors.length, 0, errors.join(' | '));
    await context.close();
  }

  // ---- "View all documents": PDF previews open in the viewer above the list; Download in the list is unchanged.
  {
    const { context, page, errors, state } = await open(browser, built, { width: 1440, height: 900 });
    await page.click('[data-ghca-documents-all]');
    await page.waitForSelector('#ghca-jotform-modal:not([hidden]) [data-ghca-document-open="7"][data-action="preview"]');
    const pv = page.locator('#ghca-jotform-modal [data-ghca-document-open="7"][data-action="preview"]');
    assert.equal(await pv.getAttribute('data-ghca-doc-context'), 'Onboarding "intake" <form>');
    ok('all documents: list PDFs carry their facts, safely escaped (quotes and angle brackets stay text)');
    await pv.click();
    await ready(page);
    assert.equal(await page.locator('[data-ghca-cv-heading]').innerText(), 'Document · Onboarding form.pdf');
    assert.match(await page.locator('[data-ghca-cv-dl]').innerText(), /Employee\s*Jordan Ellis/);
    const top = await page.evaluate(() => { const r = document.querySelector('[data-ghca-cv-dialog]').getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + 20); const z = (x) => { const out = []; for (let e = x; e && e.nodeType === 1; e = e.parentElement) { const c = getComputedStyle(e); if (c.zIndex !== 'auto' || c.position !== 'static') { out.push((e.id || e.className.toString().slice(0, 30)) + ':' + c.position + ':' + c.zIndex); } } return out.join(' < '); }; return { on: !!(el && el.closest('[data-ghca-cv-dialog]')), el: el ? (el.id || el.className.toString()) : null, elz: el ? z(el) : '', cvz: z(document.querySelector('[data-ghca-cv-dialog]')) }; });
    assert.equal(top.on, true, JSON.stringify(top)); ok('all documents: Preview on a PDF opens the redesigned viewer above the list');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-cert-viewer[hidden]', { state: 'attached' });
    assert.equal(await page.locator('#ghca-jotform-modal').isVisible(), true);
    ok('all documents: Escape closes only the viewer; the list stays open');
    state.grants.length = 0;
    const dlWait = page.waitForEvent('download');
    await page.locator('#ghca-jotform-modal [data-ghca-document-open="7"][data-action="download"]').click();
    await dlWait;
    assert.deepEqual(state.grants.map((g) => g.access), ['download']);
    ok('all documents: the list\'s own Download still mints a download grant and downloads directly (unchanged)');
    assert.equal(errors.length, 0, errors.join(' | '));
    await context.close();
  }

  // ---- Without the redesigned viewer (as on the legacy dashboard) the previous preview is used.
  {
    const { context, page, errors } = await open(browser, built, { width: 1440, height: 900 });
    await page.evaluate(() => { document.querySelector('#ghca-acd-cert-viewer').remove(); window.ghcaAcdCertViewer = undefined; });
    await page.locator('[data-ghca-document-open="7"]').click();
    await page.waitForFunction(() => window.__legacy.length === 1);
    ok('without the redesigned viewer, a PDF preview falls back to the previous certificate modal');
    assert.equal(errors.length, 0, errors.join(' | '));
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' drawer document viewer checks (synthetic drawer; mocked single-use grants; real Chromium PDF viewer; nothing sent)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
