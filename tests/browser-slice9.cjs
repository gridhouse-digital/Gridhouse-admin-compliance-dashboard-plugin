'use strict';
/**
 * Slice 9 browser checks on synthetic preview pages (the plugin's own PHP; canned AJAX; nothing sent; no record
 * changed): the certificate viewer's states, preview versus download, phone layout and keyboard use; Certificates
 * tab selection across filtering and paging and the ZIP flow (mocked server answers); the course listbox; the
 * User training report picker; Title Case course names. Chromium only. Not WordPress, not staging.
 *
 *   node tests/browser-slice9.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');
const H = require('./browser-harness.cjs');

const root = path.resolve(__dirname, '..');
const listsPreview = path.join(__dirname, 'render-lists-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const HOST = 'https://example.test';
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const PDF = Buffer.concat([Buffer.from('%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n'), Buffer.alloc(2048, 32), Buffer.from('\n%%EOF\n')]);
const ZIPBYTES = Buffer.concat([Buffer.from([0x50, 0x4b, 0x03, 0x04]), Buffer.alloc(200, 0)]);

let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: path.join(shots, 's9-' + name + '.png') }); } };

async function openLists(browser, viewport, opt = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-s9-'));
  const env = { ...process.env, GHCA_PREVIEW_ASSETS: HOST + '/assets', GHCA_PREVIEW_PERMS: opt.perms || '', GHCA_PREVIEW_EXTRA_CERTS: String(opt.extra ?? 20) };
  execFileSync('php', [listsPreview, 'page', dir, 'gridhouse', opt.hostile ? 'hostile' : ''], { env });
  const file = path.join(dir, 'lists.html');
  const state = { ajax: [], downloads: 0, cert: opt.cert || 'pdf', zip: [], zipReply: 'ok', zipGets: 0 };
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: shots ? 2 : 1, acceptDownloads: true });
  if (opt.pdfViewer !== undefined) {
    await ctx.addInitScript((v) => {
      Object.defineProperty(Navigator.prototype, 'pdfViewerEnabled', { get: () => v, configurable: true });
      // SIMULATED: headless Chromium has no PDF viewer, so loading a PDF into a frame downloads it. With "a viewer"
      // switched on, the frame records what it would show instead of navigating (a real browser renders it inline).
      if (v) {
        const desc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
        Object.defineProperty(HTMLIFrameElement.prototype, 'src', { configurable: true, get() { return this.hasAttribute('data-ghca-cv-frame') ? (this.getAttribute('data-sim-src') || '') : desc.get.call(this); }, set(x) { if (this.hasAttribute('data-ghca-cv-frame')) { this.setAttribute('data-sim-src', x); } else { desc.set.call(this, x); } } });
        const rm = Element.prototype.removeAttribute;
        Element.prototype.removeAttribute = function (name) { if (name === 'src' && this.hasAttribute && this.hasAttribute('data-ghca-cv-frame')) { rm.call(this, 'data-sim-src'); } return rm.call(this, name); };
      }
    }, opt.pdfViewer);
  }
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
  await ctx.route(HOST + '/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(req.postData() || ''));
      if (params.action === 'ghca_acd_cert_zip') {
        const items = new URLSearchParams(req.postData() || '').getAll('items[]');
        state.zip.push(items);
        if (state.zipReply === 'refused') { return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: '1 selected certificate is not available to you. Nothing was downloaded; clear the selection and try again.' } }) }); }
        if (state.zipReply === 'session') { return route.fulfill({ status: 400, contentType: 'text/html', body: '-1' }); }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { download_url: HOST + '/wp-admin/admin-ajax.php?action=ghca_acd_cert_zip_download&token=' + 'a'.repeat(32) + '&nonce=test', count: items.length, bytes: 204, name: 'certificates-2026-11-08-120000-' + items.length + '.zip', expires: 0 } }) });
      }
      state.ajax.push(params);
      const out = execFileSync('php', [listsPreview, 'ajax', JSON.stringify(params)], { env }).toString();
      return route.fulfill({ contentType: 'application/json', body: out });
    }
    if (url.pathname === '/wp-admin/admin-ajax.php' && url.searchParams.get('action') === 'ghca_acd_cert_zip_download') {
      state.zipGets += 1;
      if (state.zipReply === 'gone') { return route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'This ZIP is no longer available.' } }) }); }
      return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="c.zip"' }, body: ZIPBYTES });
    }
    if (url.pathname === '/wp-admin/admin-ajax.php' && url.searchParams.get('action') === 'ghca_acd_certificate_download') {
      state.downloads += 1;
      if (state.cert === 'html') { return route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Please log in</html>' }); }
      if (state.cert === '404') { return route.fulfill({ status: 404, contentType: 'text/plain', body: 'Not found' }); }
      if (state.cert === '403') { return route.fulfill({ status: 403, contentType: 'text/plain', body: 'Forbidden' }); }
      if (state.cert === 'slow') { await new Promise((r) => setTimeout(r, 700)); }
      return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="x.pdf"' }, body: PDF });
    }
    if (url.pathname === '/lists.html') { return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) { return route.fulfill({ contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) }); }
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto(HOST + '/lists.html');
  await page.click('[data-ghca-tab-target="ghca-tab-certificates"]');
  await page.waitForSelector('#ghca-tab-certificates.is-active');
  return { ctx, page, errors, state };
}
const C = '#ghca-tab-certificates';
const settle = (page) => page.waitForFunction(() => !document.querySelector('#ghca-tab-certificates [data-ghca-table]').getAttribute('aria-busy')).then(() => page.waitForTimeout(150));
const visibleRow = (page, i) => page.locator(C + ' .gh-tbl:visible tbody tr, ' + C + ' .gh-cards:visible [role="listitem"]').nth(i);

(async () => {
  const browser = await chromium.launch();

  // ================= Certificates tab: columns, titles =================
  {
    const { ctx, page, errors } = await openLists(browser, { width: 1440, height: 900 }, { pdfViewer: true });
    const heads = await page.$$eval(C + ' .gh-tbl thead th', (t) => t.map((x) => x.textContent.trim()));
    assert.ok(heads.includes('Source')); ok('Certificates: a Source column');
    const sources = await page.$$eval(C + ' .gh-tbl tbody tr', (r) => r.map((x) => x.children[4].textContent.trim()));
    assert.ok(sources.length > 0 && sources.every((s) => s === 'LearnDash')); ok('Source shows the only real source in this list: LearnDash (' + sources.length + ' rows)');
    const titles = await page.$$eval(C + ' .gh-tbl tbody tr', (r) => r.map((x) => x.children[3].textContent.trim()));
    assert.ok(titles.every((t) => !/^[^a-z]+$/.test(t))); assert.ok(titles.some((t) => t.includes('(OAPSA, CPS Law, APS Act)')) || titles.some((t) => t.includes('HIPAA and the ODP'))); ok('course names in Title Case with acronyms kept (OAPSA, CPS, APS, HIPAA, ODP)');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ================= Viewer: ready, preview vs download, keyboard =================
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors, state } = await openLists(browser, vp, { pdfViewer: true, cert: 'slow' });
    const downloads = [];
    page.on('download', (d) => downloads.push(d));
    const trigger = page.locator(C + ' [data-ghca-cv-open][data-ghca-cv-state="issued"]:visible').first();
    await trigger.click();
    await page.waitForSelector('#ghca-acd-cert-viewer:not([hidden])');
    assert.equal(await page.getAttribute('#ghca-acd-cert-viewer', 'data-ghca-cv-current'), 'loading');
    assert.equal(await page.locator('[data-ghca-cv-pane="loading"]').isVisible(), true);
    assert.match(await page.locator('[data-ghca-cv-heading]').innerText(), /^Certificate · /);
    assert.equal(await page.locator('.gh-cv-ft [data-ghca-cv-download]').isDisabled(), true); ok(w + ': viewer opens in its loading state: header and details at once, Download waits for the file');
    await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'ready');
    const blobSrc = await page.getAttribute('[data-ghca-cv-frame]', 'data-sim-src');
    assert.match(blobSrc, /^blob:https:\/\/example\.test\/.+#page=1&zoom=100$/);
    assert.equal(await page.locator('[data-ghca-cv-frame]').isVisible(), true);
    assert.equal(downloads.length, 0); assert.equal(state.downloads, 1); ok(w + ': ready: the frame is given a same-page blob of the PDF (one authorized fetch; nothing downloaded; display itself simulated in headless Chromium)');
    const dl = await page.locator('[data-ghca-cv-dl]').innerText();
    assert.match(dl, /Employee[\s\S]*Course[\s\S]*Source\s*LearnDash[\s\S]*File\s*PDF · 1 page · \d+ KB/); ok(w + ': details: employee, course, source and the real file facts (pages, size)');
    if (w >= 720) {
      await page.click('[data-ghca-cv-zoom="in"]');
      assert.match(await page.getAttribute('[data-ghca-cv-frame]', 'data-sim-src'), /zoom=125$/); assert.equal(await page.locator('[data-ghca-cv-zoomval]').innerText(), '125%');
      await page.click('[data-ghca-cv-zoom="fit"]');
      assert.match(await page.getAttribute('[data-ghca-cv-frame]', 'data-sim-src'), /view=FitH$/); ok(w + ': zoom and fit drive the browser PDF viewer');
    }
    assert.equal(await page.evaluate(() => document.activeElement.closest('[data-ghca-cv-dialog]') !== null), true);
    for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); }
    assert.equal(await page.evaluate(() => document.activeElement.closest('[data-ghca-cv-dialog]') !== null), true); ok(w + ': focus starts inside the viewer and Tab stays inside it');
    const closeVisible = await page.evaluate(() => Array.from(document.querySelectorAll('[data-ghca-cv-dialog] [data-ghca-cv-close]')).filter((b) => b.offsetParent !== null).map((b) => { const r = b.getBoundingClientRect(); return r.top >= 0 && r.left >= 0 && r.right <= window.innerWidth && r.width >= 24; }));
    assert.ok(closeVisible.length >= 1 && closeVisible.every(Boolean)); ok(w + ': a visible close control is on screen');
    const save = page.waitForEvent('download');
    await page.locator('.gh-cv-ft [data-ghca-cv-download]').click();
    const d = await save;
    const p = await d.path();
    assert.equal(fs.readFileSync(p).subarray(0, 5).toString(), '%PDF-'); assert.match(d.suggestedFilename(), /^[a-z0-9-]+_[a-z0-9-]+\.pdf$/);
    assert.equal(state.downloads, 1); ok(w + ': Download PDF is a separate, explicit action and saves the bytes already fetched (' + d.suggestedFilename() + ')');
    if (w < 720) {
      const g = await page.evaluate(() => { const m = document.querySelector('[data-ghca-cv-dialog]').getBoundingClientRect(); const side = document.querySelector('.gh-cv-side').getBoundingClientRect(); const stage = document.querySelector('.gh-cv-stage').getBoundingClientRect(); const btns = Array.from(document.querySelectorAll('.gh-cv-ft .gh-btn')).filter((b) => b.offsetParent !== null).map((b) => Math.round(b.getBoundingClientRect().height)); return { w: Math.round(m.width), h: Math.round(m.height), below: side.top >= stage.bottom - 1, btns, over: document.documentElement.scrollWidth > window.innerWidth }; });
      assert.equal(g.w, 390); assert.ok(g.h >= 840); assert.equal(g.below, true); assert.ok(g.btns.length >= 2 && g.btns.every((h) => h >= 48)); assert.equal(g.over, false);
      ok('390: a full-screen sheet; details below the page; 48px Download and Open in new tab');
    }
    await shot(page, 'viewer-ready-' + w);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-cert-viewer[hidden]', { state: 'attached' });
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-cv-open')), true); ok(w + ': Escape closes the viewer and focus returns to the PDF button');
    const revoked = await page.evaluate((u) => fetch(u.split('#')[0]).then(() => false, () => true), blobSrc);
    assert.equal(await page.getAttribute('[data-ghca-cv-frame]', 'data-sim-src'), null); assert.equal(revoked, true); ok(w + ': closing clears the frame and revokes the in-memory PDF (its blob URL no longer loads)');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ================= Viewer: blocked, unavailable, denied, error and retry, pending =================
  {
    const { ctx, page, state } = await openLists(browser, { width: 1440, height: 900 }, { pdfViewer: false });
    const downloads = [];
    page.on('download', (d) => downloads.push(d));
    await page.locator(C + ' [data-ghca-cv-open][data-ghca-cv-state="issued"]:visible').first().click();
    await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'blocked');
    assert.match(await page.locator('[data-ghca-cv-pane="blocked"]').innerText(), /This PDF can’t be shown here[\s\S]*download it/);
    assert.equal(await page.locator('[data-ghca-cv-frame]').isHidden(), true);
    await page.waitForTimeout(400);
    assert.equal(downloads.length, 0); ok('preview not supported: the viewer explains and offers Download; nothing is downloaded on its own');
    await shot(page, 'viewer-blocked');
    const save = page.waitForEvent('download');
    await page.locator('[data-ghca-cv-pane="blocked"] [data-ghca-cv-download]').click();
    await save; ok('preview not supported: the explicit Download in the message saves the PDF');
    await page.keyboard.press('Escape');
    for (const [reply, stateName, text] of [['404', 'unavailable', /isn’t available/], ['403', 'denied', /can’t open this certificate/], ['html', 'error', /couldn’t be loaded/]]) {
      state.cert = reply;
      await page.locator(C + ' [data-ghca-cv-open][data-ghca-cv-state="issued"]:visible').first().click();
      await page.waitForFunction((s) => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === s, stateName);
      assert.match(await page.locator('[data-ghca-cv-pane="' + stateName + '"]').innerText(), text);
      assert.equal(await page.getAttribute('[data-ghca-cv-pane="' + stateName + '"]', 'role'), 'alert');
      assert.equal(await page.locator('.gh-cv-ft [data-ghca-cv-download]').isDisabled(), true); ok('server answer ' + reply + ': the "' + stateName + '" state, announced, and no Download (no file was received)');
      if (stateName === 'error') {
        await shot(page, 'viewer-error');
        state.cert = 'pdf';
        await page.click('[data-ghca-cv-retry]');
        await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'blocked');
        ok('Try again fetches again and recovers');
      }
      await page.keyboard.press('Escape');
    }
    // Pending issuance from the Missing list.
    await page.click(C + ' [data-ghca-set-value="missing"].gh-subtab');
    await settle(page);
    const pend = page.locator(C + ' [data-ghca-cv-open][data-ghca-cv-state="pending"]:visible').first();
    const before = state.downloads;
    await pend.click();
    await page.waitForFunction(() => document.querySelector('#ghca-acd-cert-viewer').getAttribute('data-ghca-cv-current') === 'pending');
    assert.match(await page.locator('[data-ghca-cv-pane="pending"]').innerText(), /Certificate not generated yet/);
    assert.equal(await page.locator('[data-ghca-cv-bar]').isHidden(), true);
    assert.equal(await page.locator('.gh-cv-ft [data-ghca-cv-download]').isHidden(), true);
    assert.equal(await page.locator('[data-ghca-cv-to-employee]').isVisible(), true);
    assert.equal(state.downloads, before); ok('pending issuance: explained, with Open employee; no preview controls, no Download, nothing fetched');
    await shot(page, 'viewer-pending');
    await page.keyboard.press('Escape');
    await ctx.close();
  }

  // ================= Selection across paging and filtering; ZIP =================
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors, state } = await openLists(browser, vp, { pdfViewer: true });
    const downloads = [];
    page.on('download', (d) => downloads.push(d));
    const bar = page.locator(C + ' [data-ghca-cert-bar]');
    assert.equal(await bar.isHidden(), true);
    const boxes = page.locator(C + (w >= 1024 ? ' .gh-tbl' : ' .gh-cards') + ' [data-ghca-cert-select]');
    await boxes.nth(0).check(); await boxes.nth(1).check();
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '2 certificates selected'); ok(w + ': ticking certificates shows the bar with a visible count');
    if (w >= 1024) {
      await page.check(C + ' [data-ghca-cert-select-page]');
      assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '15 certificates selected'); ok('1440: select all selects the 15 on this page only');
      await page.uncheck(C + ' [data-ghca-cert-select-page]');
      await boxes.nth(0).check(); await boxes.nth(1).check();
    }
    // Page 2: the two stay selected and are named as not shown.
    await page.locator(C + ' [data-ghca-page="2"]').first().click();
    await settle(page);
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '2 certificates selected');
    assert.match(await page.locator(C + ' [data-ghca-cert-hidden]').innerText(), /^2 not in the current view: .+ — .+; .+ — .+\.$/);
    assert.equal(await page.locator(C + ' [data-ghca-cert-keep]').isVisible(), true); ok(w + ': after paging the selection is kept and the bar names the 2 selected certificates not in view');
    await boxes.nth(0).check();
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '3 certificates selected');
    // ZIP with hidden selections asks first.
    await page.click(C + ' [data-ghca-cert-zip]');
    assert.equal(await page.locator(C + ' [data-ghca-cert-confirm]').isVisible(), true);
    assert.match(await page.locator(C + ' [data-ghca-cert-confirm-text]').innerText(), /2 of the 3 selected certificates are not in the current view\. Include them\?/);
    assert.equal(state.zip.length, 0); ok(w + ': Download as ZIP asks before including the 2 hidden selections; nothing is requested yet');
    const savedShown = page.waitForEvent('download');
    await page.click(C + ' [data-ghca-cert-zip-shown]');
    const zd = await savedShown;
    assert.equal(state.zip.length, 1); assert.equal(state.zip[0].length, 1); assert.match(state.zip[0][0], /^\d+:\d+$/);
    assert.equal(zd.suggestedFilename(), 'certificates-2026-11-08-120000-1.zip');
    await page.waitForFunction(() => /Saved certificates-2026-11-08-120000-1\.zip with 1 certificate\. The server copy has been deleted\./.test(document.querySelector('#ghca-tab-certificates [data-ghca-cert-msg]').innerText));
    ok(w + ': "Only the 1 shown" sends exactly that item; the ZIP downloads and the bar says it was saved and the server copy deleted');
    await page.click(C + ' [data-ghca-cert-zip]');
    const savedAll = page.waitForEvent('download');
    await page.click(C + ' [data-ghca-cert-zip-all]');
    await savedAll;
    assert.equal(state.zip[1].length, 3); ok(w + ': "Include all 3" sends all three');
    // Failures.
    state.zipReply = 'refused';
    await page.click(C + ' [data-ghca-cert-zip]');
    await page.click(C + ' [data-ghca-cert-zip-all]');
    await page.waitForFunction(() => /not available to you\. Nothing was downloaded/.test(document.querySelector('#ghca-tab-certificates [data-ghca-cert-msg]').innerText));
    assert.equal(await page.getAttribute(C + ' [data-ghca-cert-msg]', 'role'), 'alert');
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '3 certificates selected'); ok(w + ': a refused selection says so (no names), nothing downloads, the selection is kept');
    state.zipReply = 'gone';
    await page.click(C + ' [data-ghca-cert-zip]');
    await page.click(C + ' [data-ghca-cert-zip-all]');
    await page.waitForFunction(() => /no longer available\. Nothing was saved/.test(document.querySelector('#ghca-tab-certificates [data-ghca-cert-msg]').innerText));
    ok(w + ': an expired or used ZIP link is reported; nothing is saved');
    state.zipReply = 'session';
    await page.click(C + ' [data-ghca-cert-zip]');
    await page.click(C + ' [data-ghca-cert-zip-all]');
    await page.waitForFunction(() => /session has expired/.test(document.querySelector('#ghca-tab-certificates [data-ghca-cert-msg]').innerText));
    ok(w + ': an expired session is reported');
    assert.equal(downloads.length, 2); ok(w + ': only the two successful ZIPs were saved');
    await shot(page, 'cert-selection-' + w);
    // Keep only shown, then clear.
    await page.click(C + ' [data-ghca-cert-keep]');
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '1 certificate selected');
    assert.equal(await page.locator(C + ' [data-ghca-cert-hidden]').isHidden(), true); ok(w + ': "Keep only those shown" drops the hidden selections');
    await page.click(C + ' [data-ghca-cert-clear]');
    assert.equal(await page.locator(C + ' [data-ghca-cert-count]').innerText(), '0 certificates selected');
    assert.equal(await boxes.nth(0).isChecked(), false); ok(w + ': Clear selection unticks everything');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true); ok(w + ': no sideways scrolling');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ================= Course listbox =================
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors, state } = await openLists(browser, vp, { pdfViewer: true });
    const btn = page.locator(C + ' .gh-cbx-btn');
    assert.equal(await page.locator(C + ' select[data-ghca-course-select]').evaluate((s) => s.tabIndex), -1); ok(w + ': the course filter is a listbox button; the native select keeps the value out of the tab order');
    await btn.focus();
    await page.keyboard.press('Enter');
    await page.waitForSelector(C + ' .gh-cbx-pop:not([hidden])');
    assert.equal(await btn.getAttribute('aria-expanded'), 'true');
    assert.equal(await page.evaluate(() => document.activeElement.classList.contains('gh-cbx-q')), true); ok(w + ': Enter opens it and focus moves to the filter field');
    const g = await page.evaluate(() => {
      const pop = document.querySelector('#ghca-tab-certificates .gh-cbx-pop').getBoundingClientRect();
      const lis = Array.from(document.querySelectorAll('#ghca-tab-certificates .gh-cbx-list > li')).filter((li) => !li.hidden);
      const tall = lis.filter((li) => li.getBoundingClientRect().height > 30).length;
      return { left: pop.left, right: pop.right, width: pop.width, vw: document.documentElement.clientWidth, over: document.documentElement.scrollWidth > window.innerWidth, clipped: lis.filter((li) => li.scrollWidth > li.clientWidth + 1).length, tall };
    });
    assert.ok(g.left >= 0 && g.right <= g.vw && g.width <= Math.min(480, g.vw - 32) + 1, JSON.stringify(g)); assert.equal(g.over, false); assert.equal(g.clipped, 0); assert.ok(g.tall >= 1);
    ok(w + ': the menu fits the viewport (' + Math.round(g.width) + 'px), long titles wrap onto more lines, nothing is cut off or scrolls sideways');
    await shot(page, 'course-menu-' + w);
    await page.keyboard.type('hipaa');
    assert.equal(await page.locator(C + ' .gh-cbx-list > li:not([hidden])').count(), 1);
    await page.keyboard.press('Enter');
    await settle(page);
    assert.match(await btn.innerText(), /HIPAA and the ODP/);
    assert.equal(await btn.getAttribute('aria-expanded'), 'false');
    assert.equal(await page.evaluate(() => document.activeElement.classList.contains('gh-cbx-btn')), true);
    assert.ok(state.ajax.some((a) => a.ghca_cert_course && a.ghca_cert_course !== '0')); ok(w + ': typing filters, Enter selects, the list refreshes for that course, focus returns to the button');
    await page.locator(C + ' [data-ghca-chips="certificates"] [data-ghca-filter-clear="ghca_cert_course"]').click();
    await settle(page);
    assert.equal(await btn.innerText(), 'All courses'); ok(w + ': removing the course chip resets the button');
    await btn.click();
    await page.keyboard.press('Escape');
    assert.equal(await btn.getAttribute('aria-expanded'), 'false'); assert.equal(await page.evaluate(() => document.activeElement.classList.contains('gh-cbx-btn')), true); ok(w + ': Escape closes and returns focus');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ================= User training report picker =================
  const PEOPLE = { 42: ['Jordan Ellis', 'jordan.ellis@example.test', 'DSP — New Hire', {}], 43: ['Morgan Lee', 'morgan.lee@example.test', 'DSP — Annual Compliance', {}], 44: ['Avery Kim', 'avery.kim@example.test', 'LPN', {}] };
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors, state } = await H.openDrawer(browser, vp, { messaging: true, history: [], people: PEOPLE, caps: [] }, { noDrawer: true });
    await page.click('#ghca-tab-reports-tab');
    await page.click('#ghca-tab-reports [data-ghca-report-open]');
    const geo = await page.evaluate(() => {
      const wrap = document.querySelector('[data-ghca-report-picker] .gh-input-wrap');
      const icons = Array.from(wrap.querySelectorAll(':scope > .gh-i')).map((i) => i.getBoundingClientRect());
      const input = wrap.querySelector('.gh-input');
      const cs = getComputedStyle(input);
      const r = input.getBoundingClientRect();
      return { n: icons.length, gap: icons[1].left - icons[0].right, padL: parseFloat(cs.paddingLeft), padR: parseFloat(cs.paddingRight), searchRight: icons[0].right - r.left, chevLeft: r.right - icons[1].left, inside: icons.every((i) => i.left >= r.left && i.right <= r.right), pw: Math.round(r.width), vw: window.innerWidth };
    });
    assert.equal(geo.n, 2); assert.ok(geo.gap > 40, 'gap ' + geo.gap); assert.equal(geo.inside, true);
    assert.ok(geo.padL >= geo.searchRight, 'text clears the search icon'); assert.ok(geo.padR >= geo.chevLeft, 'text clears the chevron');
    ok(w + ': search icon left and chevron right, ' + Math.round(geo.gap) + 'px apart; the input text clears both');
    if (w < 720) { assert.ok(geo.pw >= w - 40); ok('390: the picker is full width'); }
    const input = page.locator('[data-ghca-report-input]');
    await input.focus();
    assert.equal(await input.getAttribute('aria-expanded'), 'true'); assert.equal(await page.locator('[data-ghca-report-list]').isVisible(), true); ok(w + ': open state on focus (listbox visible, aria-expanded)');
    await page.keyboard.type('zzz');
    assert.equal(await page.locator('[data-ghca-report-none]').isVisible(), true); ok(w + ': empty state: "No employees match."');
    await page.fill('[data-ghca-report-input]', '');
    await page.keyboard.type('a');
    await page.keyboard.press('End');
    const lastId = await page.evaluate(() => document.getElementById(document.querySelector('[data-ghca-report-input]').getAttribute('aria-activedescendant')).getAttribute('data-ghca-report-option'));
    state.reportDelay = { [lastId]: 600 };
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-ghca-report-body]').getAttribute('aria-busy') === 'true');
    assert.match(await page.locator('[data-ghca-report-status]').innerText(), /^Loading the report for /); ok(w + ': loading state (busy, announced) after End + Enter');
    await page.waitForSelector('[data-ghca-report-user="' + lastId + '"]');
    state.reportDelay = {};
    await input.focus();
    assert.equal(await page.getAttribute('[data-ghca-report-option="' + lastId + '"]', 'aria-selected'), 'true'); ok(w + ': selected state: the chosen employee is marked in the list');
    await page.keyboard.press('Escape');
    assert.equal(await input.getAttribute('aria-expanded'), 'false'); ok(w + ': Escape closes the list');
    state.session = true;
    await page.fill('[data-ghca-report-input]', 'jordan');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => /session has expired/.test(document.querySelector('[data-ghca-report-body]').innerText));
    state.session = false; ok(w + ': error state: an expired session is reported in an alert');
    await shot(page, 'report-picker-' + w);
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ================= Title Case in the drawer and Edit Records =================
  {
    const { ctx, page } = await H.openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] });
    const t = await page.locator('.gh-drawer--emp').innerText();
    assert.match(t, /Fatal Five/); assert.doesNotMatch(t, /FATAL FIVE/); ok('drawer: an all-capitals course (FATAL FIVE) reads Fatal Five');
    await H.openEdit(page);
    assert.match(await page.locator('[data-ghca-edit-form]').innerText(), /Fatal Five/); ok('Edit Records: the same course reads Fatal Five');
    await ctx.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' slice 9 browser checks (synthetic pages; mocked server answers; nothing sent; no record changed)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
