'use strict';
/**
 * Browser checks for the redesigned employee drawer, against a synthetic employee. Needs Chromium and
 * Playwright (not part of the default test run). The page and the drawer markup come from the plugin's own
 * PHP (tests/render-drawer-preview.php runs the real drawer handler). Saving a review is answered by a
 * canned response: nothing is written and nothing is sent. Not WordPress, not LearnDash, not live data.
 *
 *   node tests/browser-redesign-drawer.cjs [screenshot-dir]
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

function build(dir, opts) {
  fs.mkdirSync(dir, { recursive: true });
  execFileSync('php', [preview, 'page', dir, opts.theme || 'gridhouse', opts.hostile ? 'hostile' : ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_ENV: JSON.stringify(opts.env || {}) } });
  return path.join(dir, 'drawer.html');
}

function origin(file, state) {
  return async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      state.calls.push(params.action);
      if (params.action === 'ghca_acd_get_employee_drawer') {
        if (state.fail) { return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Employee not found.' } }) }); }
        delete params.action;
        const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), JSON.stringify(state.env || {})]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_mark_reviewed') {
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { line: 'Reviewed on Nov 1, 2026 by Synthetic Admin', badge: 'Reviewed Nov 1', message: 'Marked as reviewed.' } }) });
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'not in this test' } }) });
    }
    if (url.pathname === '/drawer.html') { return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) { return route.fulfill({ contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) }); }
    }
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>stub</p>' });
  };
}

async function open(browser, file, viewport, state) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  await page.route('https://preview.test/**', origin(file, state));
  await page.goto('https://preview.test/drawer.html#ghca-tab-employees');
  await page.waitForSelector('#open-drawer');
  return { context, page, errors };
}

const drawerOpen = (page) => page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drawer-hd');
const metrics = (page) => page.evaluate(() => {
  const m = (sel) => { const e = document.querySelector(sel); if (!e) { return 'missing'; } const c = getComputedStyle(e); return [c.fontFamily, c.fontSize, c.fontWeight, c.lineHeight, c.letterSpacing, c.textTransform, c.color, c.backgroundColor, c.paddingLeft, c.borderTopLeftRadius, Math.round(e.getBoundingClientRect().height)].join('|'); };
  return { name: m('#ghca-acd-employee-drawer-name'), tab: m('.gh-drh-tabs .gh-tab[aria-selected="true"]'), card: m('.gh-dr-sum'), h4: m('.gh-dr-sum .gh-h4'), alert: m('.gh-dr-attn'), fold: m('.gh-fold-hd'), row: m('.gh-dr-row-t'), chip: m('.gh-drawer-bd .gh-st'), btn: m('.gh-drawer-ft .gh-btn--primary'), more: m('.gh-drawer-ft summary') };
});

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  let baseline = null;
  const ok = (msg) => { n += 1; process.stdout.write('ok - ' + msg + '\n'); };
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-drawer-'));

  // ---------- Desktop ----------
  {
    const state = { calls: [] };
    const file = build(path.join(dir, 'a'), {});
    const { context, page, errors } = await open(browser, file, { width: 1440, height: 900 }, state);
    assert.equal(await page.locator('#ghca-acd-employee-drawer').count(), 1); ok('one drawer on the page');
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isHidden(), true); ok('the drawer starts closed');
    assert.equal(await page.locator('.ghca-acd__drawer--employee').count(), 0); ok('no second, legacy drawer in the footer');
    await page.click('#open-drawer');
    await drawerOpen(page);
    assert.equal(page.url().includes('user-report'), false); ok('opening the drawer does not navigate to the report page');
    const dialogLabel = await page.locator('.gh-drawer--emp').getAttribute('aria-labelledby');
    assert.match(dialogLabel, /ghca-acd-employee-drawer-name/); ok('the dialog is labelled by the employee name');
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.closest('[data-ghca-drawer-dialog]') !== null), true); ok('focus moved into the dialog');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Close employee panel'); ok('focus starts on the close button');
    assert.equal(await page.locator('.gh-drawer-hd #ghca-acd-employee-drawer-name').innerText(), 'Jordan Ellis'); ok('the header shows the employee');
    assert.equal(await page.locator('#ghca-acd-employee-drawer-body').getAttribute('aria-busy'), null); ok('the loading state is cleared');
    baseline = await metrics(page);

    // Geometry: a 600px drawer pinned to the right, a scrim over the page.
    const g = await page.evaluate(() => { const d = document.querySelector('.gh-drawer--emp').getBoundingClientRect(); return { w: Math.round(d.width), right: Math.round(window.innerWidth - d.right), top: Math.round(d.top), bottom: Math.round(window.innerHeight - d.bottom) }; });
    assert.deepEqual(g, { w: 600, right: 0, top: 0, bottom: 0 }); ok('desktop: a 600px drawer pinned to the right edge, full height');
    const x = await page.evaluate(() => { const d = document.querySelector('.gh-drawer--emp').getBoundingClientRect(); const b = document.querySelector('.gh-drawer-x').getBoundingClientRect(); return { top: Math.round(b.top - d.top), right: Math.round(d.right - b.right) }; });
    assert.deepEqual(x, { top: 20, right: 24 }); ok('the close button sits at the board position');

    // Fonts.
    const fam = await page.evaluate(() => ({ h: getComputedStyle(document.querySelector('#ghca-acd-employee-drawer-name')).fontFamily, t: getComputedStyle(document.querySelector('.gh-drh-tabs .gh-tab')).fontFamily, c: getComputedStyle(document.querySelector('.gh-dr-sum .gh-h4')).fontFamily, r: getComputedStyle(document.querySelector('.gh-dr-row-t')).fontFamily }));
    assert.match(fam.h, /Wix Madefor Display/); assert.match(fam.t, /Wix Madefor Text/); assert.match(fam.c, /Wix Madefor Display/); assert.match(fam.r, /Wix Madefor Text/); ok('headings use Wix Madefor Display, everything else Wix Madefor Text');
    const cdp = await context.newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const doc = await cdp.send('DOM.getDocument', { depth: -1 });
    for (const sel of ['#ghca-acd-employee-drawer-name', '.gh-drh-meta span', '.gh-drh-tabs .gh-tab', '.gh-dr-sum .gh-h4', '.gh-dr-due', '.gh-dr-attn .gh-alert-t', '.gh-fold-t', '.gh-dr-row-t', '.gh-drawer-bd .gh-st', '.gh-drawer-ft .gh-btn--primary']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((f) => /Wix Madefor/.test(f.familyName)), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    ok('the font the renderer used is Wix Madefor on every drawer element checked');

    // Tabs.
    assert.equal(await page.locator('[data-ghca-tabpanel="training"]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-tabpanel="administration"]').isVisible(), false); ok('only Training is visible at first');
    await page.click('#ghca-acd-drawer-tab-administration');
    assert.equal(await page.locator('[data-ghca-tabpanel="administration"]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-tabpanel="training"]').isVisible(), false);
    assert.equal(await page.getAttribute('#ghca-acd-drawer-tab-administration', 'aria-selected'), 'true'); ok('selecting a tab swaps the panel and aria-selected');
    await page.focus('#ghca-acd-drawer-tab-administration');
    await page.keyboard.press('ArrowLeft');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-acd-drawer-tab-communications'); ok('arrow keys move between tabs');
    await page.keyboard.press('Home');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-acd-drawer-tab-training');
    await page.keyboard.press('End');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-acd-drawer-tab-administration'); ok('Home and End jump to the first and last tab');
    assert.equal(await page.evaluate(() => document.querySelector('.gh-drh-tabs').getAttribute('role')), 'tablist');

    // Administration.
    assert.match(await page.locator('[data-ghca-tabpanel="administration"]').innerText(), /Employment type\s+Not set/); ok('administration shows employment details');
    assert.equal(await page.locator('.gh-dl-warn').count(), 2); ok('unset employment type and unverified start are flagged');
    await page.click('[data-ghca-mark-reviewed]');
    await page.waitForFunction(() => document.querySelector('[data-ghca-review-text]').textContent.includes('Reviewed on Nov 1, 2026'));
    assert.equal(await page.locator('[data-ghca-review-badge]').isVisible(), true); ok('Mark reviewed updates the line and shows the badge through the existing script');
    assert.equal(await page.locator('[data-ghca-toasts] .gh-toast--success').count(), 1); ok('the result is a board toast');
    assert.ok(state.calls.includes('ghca_acd_mark_reviewed')); ok('it called the existing mark-reviewed action');

    // Training detail.
    await page.click('#ghca-acd-drawer-tab-training');
    assert.equal(await page.locator('.gh-fold').count(), 2);
    await page.locator('.gh-fold').first().locator('summary').click();
    assert.equal(await page.locator('.gh-fold').first().getAttribute('open'), null); ok('a course list collapses from its heading');
    await page.locator('.gh-fold').first().locator('summary').press('Enter');
    assert.notEqual(await page.locator('.gh-fold').first().getAttribute('open'), null); ok('and reopens from the keyboard');

    // More menu.
    await page.click('.gh-dr-more > summary');
    assert.equal(await page.locator('.gh-dr-menu').isVisible(), true); ok('the More menu opens');
    const menu = await page.evaluate(() => { const m = document.querySelector('.gh-dr-menu').getBoundingClientRect(); const s = document.querySelector('.gh-dr-more > summary').getBoundingClientRect(); return { above: m.bottom <= s.top + 1, inside: m.left >= 0 && m.right <= window.innerWidth }; });
    assert.deepEqual(menu, { above: true, inside: true }); ok('the menu opens above the button and stays on screen');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.gh-dr-menu').isVisible(), false);
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true); ok('Escape closes the menu first and leaves the drawer open');
    await page.click('.gh-dr-more > summary');
    await page.mouse.click(1150, 62); // on the header text: inside the drawer, outside the menu
    assert.equal(await page.locator('.gh-dr-menu').isVisible(), false); ok('a click outside closes the menu');

    // Certificate: the legacy modal opens above the drawer.
    await page.locator('[data-ghca-cert-trigger]').first().click();
    await page.waitForSelector('#ghca-acd-cert-modal:not([hidden])');
    const top = await page.evaluate(() => { const m = document.querySelector('.ghca-acd__cert-modal-dialog').getBoundingClientRect(); const el = document.elementFromPoint(m.left + m.width / 2, m.top + 40); return !!(el && el.closest('#ghca-acd-cert-modal')); });
    assert.equal(top, true); ok('the certificate modal opens above the drawer');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-cert-modal[hidden]', { state: 'attached' });
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true); ok('Escape closes the modal and not the drawer under it');

    // Focus trap and close.
    await page.focus('.gh-drawer-ft .gh-btn--primary');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.closest('[data-ghca-drawer-dialog]') !== null), true); ok('Tab from the last control stays inside the dialog');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-employee-drawer[hidden]', { state: 'attached' });
    assert.equal(await page.evaluate(() => document.activeElement.id), 'open-drawer'); ok('Escape closes the drawer and returns focus to the row that opened it');
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.mouse.click(100, 400);
    await page.waitForSelector('#ghca-acd-employee-drawer[hidden]', { state: 'attached' }); ok('a click on the scrim closes the drawer');
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('.gh-drawer-x');
    await page.waitForSelector('#ghca-acd-employee-drawer[hidden]', { state: 'attached' }); ok('the close button closes the drawer');
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('ghca-acd--drawer-open')), false); ok('page scroll lock is released');
    assert.equal(errors.length, 0, 'console errors: ' + errors.join(' | ')); ok('no console or page errors');
    if (shots) { await page.click('#open-drawer'); await drawerOpen(page); await page.screenshot({ path: path.join(shots, 'drawer-training-1440.png') }); await page.click('#ghca-acd-drawer-tab-administration'); await page.screenshot({ path: path.join(shots, 'drawer-admin-1440.png') }); }
    await context.close();
  }

  // ---------- Error and retry ----------
  {
    const state = { calls: [], fail: true };
    const file = build(path.join(dir, 'b'), {});
    const { context, page } = await open(browser, file, { width: 1440, height: 900 }, state);
    await page.click('#open-drawer');
    await page.waitForSelector('#ghca-acd-employee-drawer .gh-dr-error');
    assert.match(await page.locator('.gh-dr-error').innerText(), /Employee not found\./); ok('a failed load shows the message in the board empty state');
    assert.equal(await page.locator('.gh-dr-error').getAttribute('role'), 'alert'); ok('and announces it');
    state.fail = false;
    await page.click('[data-ghca-drawer-retry]');
    await drawerOpen(page); ok('Try again loads the employee');
    await context.close();
  }

  // ---------- Capability gates reach the browser ----------
  {
    const state = { calls: [], env: { can_docs: false, can_comms: false, can_admin: false, can_remind: false } };
    const file = build(path.join(dir, 'c'), {});
    const { context, page } = await open(browser, file, { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    assert.equal(await page.locator('.gh-drh-tabs .gh-tab').count(), 1); ok('without documents, communications and admin access the drawer has only Training');
    assert.equal(await page.locator('[data-ghca-reminder]').count(), 0); ok('and no reminder control without the capability');
    await context.close();
  }

  // ---------- Hostile theme ----------
  {
    const state = { calls: [] };
    const file = build(path.join(dir, 'd'), { hostile: true });
    const { context, page } = await open(browser, file, { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    const h = await metrics(page);
    for (const key of Object.keys(baseline)) { assert.equal(h[key], baseline[key], key + ' differs under a hostile theme: ' + baseline[key] + ' vs ' + h[key]); }
    ok('name, tab, summary card, alert, list heading, row, chip and both footer buttons measure the same under a hostile theme');
    const link = await page.evaluate(() => { const a = document.querySelector('a.gh-dr-row'); const c = getComputedStyle(a); return { color: c.color, deco: c.textDecorationLine }; });
    assert.notEqual(link.color, 'rgb(6, 69, 173)'); assert.equal(link.deco, 'none'); ok('theme link colour and underline do not reach course rows');
    await page.hover('a.gh-dr-row');
    assert.notEqual(await page.evaluate(() => getComputedStyle(document.querySelector('a.gh-dr-row')).backgroundColor), 'rgb(204, 255, 238)'); ok('theme link hover fill does not reach course rows');
    await page.hover('.gh-drawer-ft .gh-btn--primary');
    assert.notEqual(await page.evaluate(() => getComputedStyle(document.querySelector('.gh-drawer-ft .gh-btn--primary')).backgroundColor), 'rgb(0, 0, 255)'); ok('theme button hover fill does not reach Send reminder');
    assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.gh-fold-hd')).listStyleType), 'none'); ok('theme summary rules do not bring back a list marker');
    await context.close();
  }

  // ---------- Phone: a full-screen sheet ----------
  {
    const state = { calls: [] };
    const file = build(path.join(dir, 'e'), {});
    const { context, page } = await open(browser, file, { width: 390, height: 844 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    const g = await page.evaluate(() => { const d = document.querySelector('.gh-drawer--emp').getBoundingClientRect(); const x = document.querySelector('.gh-drawer-x').getBoundingClientRect(); return { w: Math.round(d.width), h: Math.round(d.height), vw: window.innerWidth, vh: window.innerHeight, xLeft: Math.round(x.left - d.left), xTop: Math.round(x.top - d.top), doc: document.documentElement.scrollWidth }; });
    assert.equal(g.w, g.vw); assert.equal(g.h, g.vh); ok('phone: the drawer fills the screen');
    assert.equal(g.xLeft, 8); assert.equal(g.xTop, 8); ok('phone: close is top-left, as on the sheet board');
    assert.ok(g.doc <= g.vw + 1); ok('phone: no sideways page scroll');
    const t = await page.evaluate(() => Array.from(document.querySelectorAll('.gh-drawer--emp .gh-tab, .gh-drawer--emp .gh-drawer-ft .gh-btn, .gh-drawer--emp .gh-drawer-x')).map((e) => Math.round(e.getBoundingClientRect().height)));
    assert.ok(t.every((h) => h >= 42), 'touch targets: ' + t.join(',')); ok('phone: tabs, footer buttons and close are at least 42px tall');
    const f = await page.evaluate(() => Array.from(document.querySelectorAll('.gh-drawer-ft .gh-btn')).map((e) => Math.round(e.getBoundingClientRect().height)));
    assert.deepEqual(f, [52, 52]); ok('phone: footer buttons are 52px, as on the board');
    assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.gh-drh-tabs')).flexWrap), 'nowrap'); ok('phone: tabs scroll instead of wrapping');
    await page.click('.gh-dr-more > summary');
    const m = await page.evaluate(() => { const r = document.querySelector('.gh-dr-menu').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; });
    assert.equal(m, true); ok('phone: the More menu stays on screen');
    await page.keyboard.press('Escape');
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-training-390.png') }); }
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' browser checks\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
