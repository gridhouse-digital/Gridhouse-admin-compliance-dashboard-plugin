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
      const body = route.request().postData() || '';
      const params = Object.fromEntries(new URLSearchParams(body));
      // The upload is multipart, so its action name is read from the body.
      if (!params.action) { const m = body.match(/name="action"\r\n\r\n([^\r]+)/); if (m) { params.action = m[1]; } }
      state.calls.push(params.action);
      if (params.action === 'ghca_acd_get_employee_drawer') {
        if (state.fail) { return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Employee not found.' } }) }); }
        delete params.action;
        const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), JSON.stringify(state.env || {})]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_jotform_manual_upload') {
        // Nothing is stored. The request body proves the form asked for board markup.
        state.upload = route.request().postData() || '';
        const out = execFileSync('php', [preview, 'card', JSON.stringify(state.env || {})]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_get_reminder_context') {
        state.contexts = (state.contexts || 0) + 1;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: Object.assign({
          employee_id: 42, employee_name: 'Jordan Ellis', idempotency_token: 'tok-' + state.contexts, masked_email: 'j•••••@example.org', masked_phone: '•••• 4471', email_ready: true, sms_ready: true, email_reason: '', sms_reason: '',
          templates: [
            { id: 7, name: 'Course due soon', subject: 'Your Fatal Five course is due', message: 'Hi Jordan, please start with Fatal Five.', urgency: 'normal', channels: ['email', 'sms', 'email_sms'], reason: '' },
            { id: 8, name: 'Overdue - action needed', subject: 'Action needed', message: 'Hi Jordan, you have overdue courses.', urgency: 'urgent', channels: ['email'], reason: '' },
          ],
        }, state.context || {}) }) });
      }
      if (params.action === 'ghca_acd_send_reminder') {
        // Canned. Nothing is queued, so nothing can be sent. The posted fields are kept for the assertions.
        state.sent = params; state.sends = (state.sends || 0) + 1;
        if (state.sendFail) { return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'The mail server refused the message.' } }) }); }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { message: 'Reminder queued for Jordan Ellis.' } }) });
      }
      if (params.action === 'ghca_acd_get_edit_records_form') {
        state.editRequest = params;
        delete params.action; delete params.nonce;
        const out = execFileSync('php', [preview, 'edit', JSON.stringify(params), JSON.stringify(state.env || {})]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_save_employee_records') {
        // Canned. Nothing is written; the request is kept to compare field names with the legacy form.
        state.saved = params;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { message: 'Records updated.' } }) });
      }
      if (params.action === 'ghca_acd_agency_training') {
        state.agency = params;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html: '<p class="gh-small">Synthetic agency confirmation list.</p>', message: 'Saved records loaded.' } }) });
      }
      if (params.action === 'ghca_acd_retry_reminder') {
        state.retried = params.delivery_id;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { message: 'The delivery was queued for retry.' } }) });
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


  // ---------- Documents and Communications ----------
  {
    const history = [
      { communication_id: 5, delivery_id: 321, channel: 'email', delivery_state: 'accepted', actor_user_id: 3, urgency: 'normal', created_at: '2026-10-27 16:42:00', subject: 'Your Fatal Five course is due', message: 'Hi Jordan,\nstart with Fatal Five.', last_error_code: '', last_error_summary: '', attempt_count: 1, provider_ref: '' },
      { communication_id: 5, delivery_id: 322, channel: 'sms', delivery_state: 'failed', actor_user_id: 3, urgency: 'normal', created_at: '2026-10-27 16:42:00', subject: 'Your Fatal Five course is due', message: 'Hi Jordan,\nstart with Fatal Five.', last_error_code: 'no_phone', last_error_summary: 'No mobile number on file.', attempt_count: 1, provider_ref: '' },
      { communication_id: 4, delivery_id: 300, channel: 'sms', delivery_state: 'delivered', actor_user_id: 0, urgency: 'high', created_at: '2026-10-20 09:00:00', subject: '', message: 'Welcome.', last_error_code: '', last_error_summary: '', attempt_count: 1, provider_ref: 'SM1' },
    ];
    const state = { calls: [], env: { jotform: true, messaging: true, history, hist_total: 4 } };
    const file = build(path.join(dir, 'f'), { env: state.env });
    const { context, page, errors } = await open(browser, file, { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    assert.match(await page.locator('#ghca-acd-drawer-tab-documents').innerText(), /Documents\s+5/); ok('the Documents tab shows the record count');
    assert.match(await page.locator('#ghca-acd-drawer-tab-communications').innerText(), /Communications\s+4/); ok('the Communications tab shows the communication count');

    await page.click('#ghca-acd-drawer-tab-documents');
    assert.equal(await page.locator('.gh-file').count(), 3); ok('documents: three records');
    const f = await page.evaluate(() => { const r = document.querySelector('.gh-file').getBoundingClientRect(); const ico = document.querySelector('.gh-file-ico').getBoundingClientRect(); return { ico: Math.round(ico.width) + 'x' + Math.round(ico.height), fits: r.right <= document.querySelector('.gh-drawer-bd').getBoundingClientRect().right }; });
    assert.deepEqual(f, { ico: '38x44', fits: true }); ok('documents: file rows use the board icon size and fit the drawer');
    assert.equal(await page.locator('[data-ghca-document-open]').count(), 1);
    assert.equal(await page.locator('[data-ghca-manual-open]').count(), 1); ok('documents: preview buttons keep the hooks the documents script binds to');

    // Upload: the form posts through the existing script and the card is replaced in place by a board card.
    const form = page.locator('[data-ghca-manual-form]');
    await form.locator('select[name="catalog_id"]').selectOption('11');
    await form.locator('input[name="completion_date"]').fill('2026-10-30');
    await form.locator('textarea[name="entry_reason"]').fill('Red Cross card brought to the office');
    await form.locator('input[type="file"]').setInputFiles({ name: 'card.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 synthetic') });
    await form.locator('button[type="submit"]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-toasts] .gh-toast--success').length > 0);
    assert.match(state.upload, /name="ghca_ui"\r\n\r\nboard/); ok('upload: the form asks for board markup');
    assert.match(state.upload, /name="employee_id"\r\n\r\n42/); assert.match(state.upload, /name="action"\r\n\r\nghca_acd_jotform_manual_upload/); ok('upload: it posts to the existing action for this employee');
    assert.equal(await page.locator('[data-ghca-manual-entry] [data-ghca-manual-form]').count(), 1);
    assert.equal(await page.locator('[data-ghca-manual-entry] .ghca-acd__manual-entry-form').count(), 0); ok('upload: the replacement card is the board card, not the legacy one');
    assert.equal(await page.locator('[data-ghca-manual-form] input[name="completion_date"]').inputValue(), ''); ok('upload: the form is clean for the next certificate');

    // Required fields block an empty submit without any request.
    state.upload = '';
    await page.locator('[data-ghca-manual-form] button[type="submit"]').click();
    assert.equal(state.upload, ''); ok('upload: an empty form is stopped by the browser before any request');

    // Communications.
    await page.click('#ghca-acd-drawer-tab-communications');
    assert.equal(await page.locator('.gh-tl > li').count(), 2); ok('communications: one entry per message');
    assert.equal(await page.locator('.gh-tl-dot--danger').count(), 1); ok('communications: the failed one is marked');
    assert.equal(await page.locator('.gh-dr-comm details[open]').count(), 2); ok('communications: messages are readable without a click');
    assert.match(await page.locator('.gh-dr-comm').first().innerText(), /Email \+ SMS/); ok('communications: combined channels read as one');
    // Retry's click handler lives with the reminder and history modals, which the next step moves to board markup;
    // its end-to-end check is added there. Here: the button carries the hook the handler binds to.
    assert.equal(await page.locator('[data-ghca-reminder-retry="322"]').count(), 1); ok('communications: Retry is offered for the failed SMS and carries the delivery id');
    assert.equal(await page.locator('.ghca-acd__history-item').count(), 0); ok('communications: no legacy history markup anywhere');

    // Fonts on the new elements.
    await page.click('#ghca-acd-drawer-tab-documents');
    const cdp = await context.newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const doc = await cdp.send('DOM.getDocument', { depth: -1 });
    for (const sel of ['[data-ghca-documents-section] .gh-h4', '.gh-file-n', '.gh-file .gh-small', '.gh-file .gh-st', '[data-ghca-manual-form] .gh-label', '[data-ghca-manual-form] .gh-alert-body', '[data-ghca-manual-form] .gh-btn--primary']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((x) => /Wix Madefor/.test(x.familyName)), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    ok('documents: the rendered font is Wix Madefor on every element checked');
    await page.click('#ghca-acd-drawer-tab-communications');
    for (const sel of ['.gh-dr-comm-t', '.gh-dr-comm .gh-small', '.gh-dr-comm .gh-st', '.gh-dr-comm-sum', '.gh-dr-comm-msg']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((x) => /Wix Madefor/.test(x.familyName)), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    ok('communications: the rendered font is Wix Madefor on every element checked');
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-comms-1440.png') }); await page.click('#ghca-acd-drawer-tab-documents'); await page.screenshot({ path: path.join(shots, 'drawer-docs-1440.png') }); }
    assert.equal(errors.length, 0, 'console errors: ' + errors.join(' | ')); ok('no console or page errors');
    await context.close();
  }

  // ---------- Hostile theme on Documents and Communications ----------
  {
    const history = [{ communication_id: 5, delivery_id: 322, channel: 'sms', delivery_state: 'failed', actor_user_id: 3, urgency: 'normal', created_at: '2026-10-27 16:42:00', subject: 'Due', message: 'Hi.', last_error_code: 'no_phone', last_error_summary: 'No mobile number.', attempt_count: 1, provider_ref: '' }];
    const env = { jotform: true, messaging: true, history, hist_total: 1 };
    const grab = async (opts) => {
      const state = { calls: [], env };
      const { context, page } = await open(browser, build(path.join(dir, opts.dir), { env, hostile: opts.hostile }), { width: 1440, height: 900 }, state);
      await page.click('#open-drawer'); await drawerOpen(page);
      const m = async (sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) { return 'missing'; } const c = getComputedStyle(e); return [c.fontFamily, c.fontSize, c.fontWeight, c.lineHeight, c.textTransform, c.color, c.backgroundColor, c.paddingLeft, c.borderTopWidth, c.listStyleType, Math.round(e.getBoundingClientRect().height)].join('|'); }, sel);
      await page.click('#ghca-acd-drawer-tab-documents');
      const docs = { h4: await m('[data-ghca-documents-section] .gh-h4'), name: await m('.gh-file-n'), file: await m('.gh-file'), label: await m('[data-ghca-manual-form] .gh-label'), select: await m('[data-ghca-manual-form] .gh-select'), area: await m('[data-ghca-manual-form] .gh-textarea'), save: await m('[data-ghca-manual-form] .gh-btn--primary'), all: await m('[data-ghca-documents-all]') };
      await page.click('#ghca-acd-drawer-tab-communications');
      const comms = { t: await m('.gh-dr-comm-t'), sum: await m('.gh-dr-comm-sum'), msg: await m('.gh-dr-comm-msg'), retry: await m('.gh-dr-retry'), link: await m('.gh-linkbtn'), card: await m('.gh-dr-comm') };
      await context.close();
      return { docs, comms };
    };
    const clean = await grab({ dir: 'g1', hostile: false });
    const hostile = await grab({ dir: 'g2', hostile: true });
    const drift = [];
    for (const group of Object.keys(clean)) { for (const key of Object.keys(clean[group])) { if (clean[group][key] !== hostile[group][key]) { drift.push(group + '.' + key + ': ' + clean[group][key] + '  vs  ' + hostile[group][key]); } } }
    assert.deepEqual(drift, []); ok('documents and communications measure the same under a hostile theme');
  }

  // ---------- Phone: Documents and Communications ----------
  {
    const history = [{ communication_id: 5, delivery_id: 321, channel: 'email', delivery_state: 'accepted', actor_user_id: 3, urgency: 'normal', created_at: '2026-10-27 16:42:00', subject: 'A subject that is long enough to wrap onto a second line on a phone', message: 'Hi Jordan, start with Fatal Five.', last_error_code: '', last_error_summary: '', attempt_count: 1, provider_ref: '' }];
    const env = { jotform: true, messaging: true, history, hist_total: 1 };
    const state = { calls: [], env };
    const { context, page } = await open(browser, build(path.join(dir, 'h'), { env }), { width: 390, height: 844 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('#ghca-acd-drawer-tab-documents');
    let m = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: window.innerWidth, over: Array.from(document.querySelectorAll('[data-ghca-tabpanel="documents"] *')).filter((e) => e.getBoundingClientRect().right > document.querySelector('.gh-drawer--emp').getBoundingClientRect().right + 1).length }));
    assert.ok(m.doc <= m.win + 1); assert.equal(m.over, 0); ok('phone: Documents has no sideways overflow');
    const inputs = await page.evaluate(() => Array.from(document.querySelectorAll('[data-ghca-manual-form] .gh-select, [data-ghca-manual-form] .gh-input, [data-ghca-manual-form] .gh-btn')).map((e) => Math.round(e.getBoundingClientRect().height)));
    assert.ok(inputs.every((h) => h >= 42), 'form controls: ' + inputs.join(',')); ok('phone: form controls are at least 42px tall');
    await page.click('#ghca-acd-drawer-tab-communications');
    m = await page.evaluate(() => ({ over: Array.from(document.querySelectorAll('[data-ghca-tabpanel="communications"] *')).filter((e) => e.getBoundingClientRect().right > document.querySelector('.gh-drawer--emp').getBoundingClientRect().right + 1).length }));
    assert.equal(m.over, 0); ok('phone: Communications has no sideways overflow');
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-comms-390.png') }); }
    await context.close();
  }

  // ---------- Edit Records sub-page ----------
  {
    const state = { calls: [], env: { jotform: true } };
    const file = build(path.join(dir, 'i'), { env: state.env });
    const { context, page, errors } = await open(browser, file, { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('#ghca-acd-drawer-tab-administration');
    const before = await page.evaluate(() => Math.round(document.querySelector('.gh-drawer--emp').getBoundingClientRect().width));
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    assert.equal(state.editRequest.ghca_ui, 'board'); ok('edit: the form is requested as board markup');
    const wide = await page.evaluate(() => Math.round(document.querySelector('.gh-drawer--emp').getBoundingClientRect().width));
    assert.equal(before, 600); assert.equal(wide, 920); ok('edit: the drawer widens from 600px to 920px for the sub-page');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-subpage-head')), true); ok('edit: focus moves to the sub-page heading');
    assert.equal(await page.locator('[data-ghca-admin-view="overview"]').isVisible(), false); ok('edit: the overview is hidden behind the sub-page');
    assert.equal(await page.locator('.ghca-acd__edit-form').count(), 0); ok('edit: no legacy form markup');

    // Role, group and status are read-only and unnamed, so they cannot be posted.
    const names = await page.evaluate(() => Array.from(document.querySelectorAll('[data-ghca-edit-form] [name]')).map((e) => e.name));
    assert.ok(names.includes('employment_type') && names.includes('registration_date') && names.some((x) => /^course\[\d+\]\[completed\]$/.test(x)), names.join(',')); ok('edit: the form carries the same field names as the legacy form');
    assert.equal(names.includes('role') || names.includes('group') || names.includes('status'), false); ok('edit: role, group and status are not submitted');

    // Dirty counter.
    assert.match(await page.locator('[data-ghca-edit-dirty]').innerText(), /No unsaved changes/);
    await page.selectOption('[name="employment_type"]', 'full_time');
    assert.match(await page.locator('[data-ghca-edit-dirty]').innerText(), /Unsaved changes: 1 field$/); ok('edit: changing a field shows "Unsaved changes: 1 field"');
    const firstDone = page.locator('input[name$="[mark_complete]"]').first();
    await firstDone.check();
    assert.match(await page.locator('[data-ghca-edit-dirty]').innerText(), /Unsaved changes: 2 fields$/); ok('edit: a second change makes it plural');
    await page.selectOption('[name="employment_type"]', '');
    await firstDone.uncheck();
    assert.match(await page.locator('[data-ghca-edit-dirty]').innerText(), /No unsaved changes/); ok('edit: undoing the changes clears the counter');

    // Save posts through the existing script, with the existing field names.
    await page.selectOption('[name="employment_type"]', 'full_time');
    await page.click('[data-ghca-edit-save]');
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-toasts] .gh-toast--success').length > 0);
    assert.equal(state.saved.employment_type, 'full_time'); assert.equal(state.saved.user_id, '42'); ok('edit: Save posts the form to the existing action for this employee');
    assert.ok(Object.keys(state.saved).some((k) => /^course\[\d+\]\[mark_complete\]$/.test(k))); ok('edit: course completion flags are posted as 0 or 1 by the existing script');
    assert.equal(await page.locator('[data-ghca-edit-form]').count(), 0); ok('edit: after saving the sub-page closes');
    assert.equal(await page.evaluate(() => Math.round(document.querySelector('.gh-drawer--emp').getBoundingClientRect().width)), 600); ok('edit: and the drawer returns to 600px');

    // Back and Escape.
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    await page.click('[data-ghca-subpage-back]');
    assert.equal(await page.locator('[data-ghca-admin-view="overview"]').isVisible(), true);
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-edit-records')), true); ok('edit: Back returns to the overview and refocuses Edit records');
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-ghca-admin-view="overview"]').isVisible(), true);
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true); ok('edit: Escape goes back one level and leaves the drawer open');
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    await page.click('[data-ghca-edit-close]');
    assert.equal(await page.locator('[data-ghca-admin-view="overview"]').isVisible(), true); ok('edit: Cancel closes the sub-page without saving');

    // Packet period.
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    assert.equal(await page.locator('[data-ghca-audit-dates]').isVisible(), false);
    await page.selectOption('[data-ghca-audit-mode]', 'custom');
    assert.equal(await page.locator('[data-ghca-audit-dates]').isVisible(), true); ok('packet: choosing a custom period shows the date fields');
    assert.equal(await page.locator('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet]').count(), 2); ok('packet: Orientation and Annual keep the generator hook (OLTL is only offered where the OLTL feature applies)');
    assert.equal(await page.locator('[data-ghca-packet-mirror]').isVisible(), false); ok('packet: the progress mirror is hidden until a packet is generated');

    // Agency training body loads through the existing script.
    await page.waitForFunction(() => document.querySelector('[data-ghca-agency-status]').textContent.includes('Choose valid start and end dates'));
    ok('agency: a custom period without dates asks for them instead of loading');
    await page.selectOption('[data-ghca-audit-mode]', 'cycle');
    await page.click('[data-ghca-agency-reload]');
    await page.waitForFunction(() => document.querySelector('[data-ghca-agency-body]').textContent.includes('Synthetic agency confirmation list'));
    assert.equal(state.agency.operation, 'load'); assert.equal(state.agency.user_id, '42'); ok('agency: the existing script loads saved confirmations for this employee');

    // Fonts.
    const cdp = await context.newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const doc = await cdp.send('DOM.getDocument', { depth: -1 });
    for (const sel of ['[data-ghca-subpage-head]', '[data-ghca-edit-form] .gh-h4', '[data-ghca-edit-form] .gh-label', '[data-ghca-edit-form] .gh-input', '[data-ghca-edit-dirty]', '[data-ghca-edit-save]', '[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet]']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((x) => /Wix Madefor/.test(x.familyName)), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    ok('edit: the rendered font is Wix Madefor on every element checked');
    // On a short screen the form overflows; the Save bar then stays in view while the form scrolls under it.
    await page.setViewportSize({ width: 1440, height: 520 });
    await page.evaluate(() => { document.querySelector('.gh-drawer-bd').scrollTop = 0; });
    const foot = await page.evaluate(() => { const b = document.querySelector('.gh-drawer-bd').getBoundingClientRect(); const r = document.querySelector('.gh-dr-editfoot').getBoundingClientRect(); return { pos: getComputedStyle(document.querySelector('.gh-dr-editfoot')).position, gap: Math.round(b.bottom - r.bottom), pad: parseFloat(getComputedStyle(document.querySelector('.gh-drawer-bd')).paddingBottom), scrolls: document.querySelector('.gh-drawer-bd').scrollHeight > document.querySelector('.gh-drawer-bd').clientHeight }; });
    assert.equal(foot.pos, 'sticky'); assert.equal(foot.scrolls, true); assert.equal(foot.gap, Math.round(foot.pad)); ok('edit: on a short screen the Save bar stays pinned at the bottom of the drawer body (inside its padding) while the form scrolls');
    await page.setViewportSize({ width: 1440, height: 900 });
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-edit-1440.png') }); }
    assert.equal(errors.length, 0, 'console errors: ' + errors.join(' | ')); ok('no console or page errors');
    await context.close();
  }

  // ---------- Edit Records: hostile theme and phone ----------
  {
    const grab = async (opts) => {
      const state = { calls: [], env: {} };
      const { context, page } = await open(browser, build(path.join(dir, opts.dir), { hostile: opts.hostile }), { width: 1440, height: 900 }, state);
      await page.click('#open-drawer'); await drawerOpen(page);
      await page.click('#ghca-acd-drawer-tab-administration');
      await page.click('[data-ghca-edit-records]');
      await page.waitForSelector('[data-ghca-edit-form]');
      const m = (sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) { return 'missing'; } const c = getComputedStyle(e); return [c.fontFamily, c.fontSize, c.fontWeight, c.lineHeight, c.textTransform, c.color, c.backgroundColor, c.paddingLeft, c.borderTopWidth, Math.round(e.getBoundingClientRect().height)].join('|'); }, sel);
      const out = {};
      for (const [k, sel] of Object.entries({ head: '[data-ghca-subpage-head]', h4: '[data-ghca-edit-form] .gh-h4', label: '[data-ghca-edit-form] .gh-label', input: '[data-ghca-edit-form] .gh-input', select: '[data-ghca-edit-form] .gh-select', check: '[data-ghca-edit-form] .gh-check', save: '[data-ghca-edit-save]', pk: '[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet]', back: '[data-ghca-subpage-back]' })) { out[k] = await m(sel); }
      await context.close();
      return out;
    };
    const clean = await grab({ dir: 'j1', hostile: false });
    const hostile = await grab({ dir: 'j2', hostile: true });
    const drift = Object.keys(clean).filter((k) => clean[k] !== hostile[k]).map((k) => k + ': ' + clean[k] + '  vs  ' + hostile[k]);
    assert.deepEqual(drift, []); ok('edit: heading, card title, label, input, select, checkbox label, buttons and back link measure the same under a hostile theme');

    const state = { calls: [], env: {} };
    const { context, page } = await open(browser, build(path.join(dir, 'k'), {}), { width: 390, height: 844 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('#ghca-acd-drawer-tab-administration');
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    const g = await page.evaluate(() => { const d = document.querySelector('.gh-drawer--emp').getBoundingClientRect(); return { w: Math.round(d.width), vw: window.innerWidth, doc: document.documentElement.scrollWidth, over: Array.from(document.querySelectorAll('[data-ghca-admin-view="subpage"] *')).filter((e) => e.getBoundingClientRect().right > d.right + 1).length, ctl: Array.from(document.querySelectorAll('[data-ghca-edit-form] .gh-input, [data-ghca-edit-form] .gh-select, [data-ghca-edit-save], [data-ghca-pdf-packet]')).filter((e) => e.offsetParent !== null).map((e) => Math.round(e.getBoundingClientRect().height)) }; });
    assert.equal(g.w, g.vw); assert.ok(g.doc <= g.vw + 1); assert.equal(g.over, 0); ok('phone: the sub-page fills the screen with no sideways overflow');
    assert.ok(g.ctl.every((h) => h >= 42), 'controls: ' + g.ctl.join(',')); ok('phone: inputs, selects and buttons are at least 42px tall');
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-edit-390.png') }); }
    await context.close();
  }

  // ---------- Send reminder modal ----------
  {
    const state = { calls: [], env: { messaging: true } };
    const file = build(path.join(dir, 'l'), { env: state.env });
    const { context, page, errors } = await open(browser, file, { width: 1440, height: 900 }, state);
    assert.equal(await page.locator('#ghca-acd-reminder-modal').count(), 1); ok('reminder: one modal on the page, inside the themed wrapper');
    assert.equal(await page.evaluate(() => !!document.querySelector('#ghca-acd-reminder-modal').closest('.ghca-ui, #ghca-ui-scope')), true);
    assert.equal(await page.locator('#ghca-acd-reminder-modal').isHidden(), true); ok('reminder: it starts closed');
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(await page.locator('#ghca-acd-reminder-modal [data-ghca-reminder-name]').innerText(), 'Jordan Ellis');
    assert.match(await page.locator('[data-ghca-reminder-destination]').innerText(), /j•••••@example\.org/); ok('reminder: the recipient strip shows the name and the masked destinations from the messaging context');
    assert.equal(await page.locator('[data-ghca-reminder-avatar]').innerText(), 'JE'); ok('reminder: the avatar carries the initials');
    const top = await page.evaluate(() => { const m = document.querySelector('[data-ghca-reminder-dialog]').getBoundingClientRect(); const el = document.elementFromPoint(m.left + m.width / 2, m.top + 30); return !!(el && el.closest('#ghca-acd-reminder-modal')); });
    assert.equal(top, true); ok('reminder: the modal opens above the drawer');
    const g = await page.evaluate(() => { const m = document.querySelector('[data-ghca-reminder-dialog]').getBoundingClientRect(); return { w: Math.round(m.width), cx: Math.round(m.left + m.width / 2), cy: Math.round(m.top + m.height / 2) }; });
    assert.equal(g.w, 560); assert.equal(g.cx, 720); ok('reminder: a 560px dialog centred on the screen');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-reminder-subject')), true); ok('reminder: focus lands on the subject');
    assert.equal(await page.locator('[data-ghca-reminder-channel-option]:checked').getAttribute('value'), 'email'); ok('reminder: Email is the starting delivery method');

    // Validation stays inline and sends nothing.
    await page.click('[data-ghca-reminder-submit]');
    assert.match(await page.locator('[data-ghca-reminder-error]').innerText(), /Enter both a subject and a message/);
    assert.equal(await page.locator('[data-ghca-reminder-confirm]').isVisible(), false); ok('reminder: an empty form is stopped inline, before any confirm');

    // Template fills the form; the preview and the urgency badge follow.
    await page.selectOption('[data-ghca-reminder-template]', '8');
    assert.equal(await page.inputValue('[data-ghca-reminder-subject]'), 'Action needed');
    assert.equal(await page.locator('[data-ghca-reminder-urgency]').inputValue(), 'urgent'); ok('reminder: choosing a template fills subject, message and urgency');
    assert.match(await page.locator('[data-ghca-reminder-preview-subject]').innerText(), /^URGENT: Action needed$/); ok('reminder: the preview shows the subject as it will be sent, with the urgency prefix');
    assert.equal(await page.locator('[data-ghca-reminder-urgency-badge]').isVisible(), true);
    assert.match(await page.getAttribute('[data-ghca-reminder-urgency-badge]', 'class'), /gh-st--overdue/); ok('reminder: an urgent message is flagged with the board overdue chip');
    assert.match(await page.locator('[data-ghca-reminder-count]').innerText(), /^\d+$/);

    // Step 2: confirm.
    await page.selectOption('[data-ghca-reminder-template]', '7');
    await page.fill('[data-ghca-reminder-message]', 'Hi Jordan, please start with Fatal Five.');
    await page.click('[data-ghca-reminder-submit]');
    assert.equal(await page.locator('[data-ghca-reminder-confirm]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-reminder-dialog]').isVisible(), false); ok('reminder: Review & send opens a confirm step and hides the form');
    assert.equal(state.sends || 0, 0); ok('reminder: nothing is sent by reviewing');
    assert.match(await page.locator('[data-ghca-confirm-title]').innerText(), /^Send this email to Jordan Ellis\?$/);
    assert.equal(await page.locator('[data-ghca-confirm-to]').innerText(), 'j•••••@example.org');
    assert.equal(await page.locator('[data-ghca-confirm-subject]').innerText(), 'Your Fatal Five course is due');
    assert.equal(await page.locator('[data-ghca-confirm-urgency]').innerText(), 'Normal'); ok('reminder: the confirm step states who, how, the subject and the urgency');
    assert.equal(await page.getAttribute('[data-ghca-reminder-confirm]', 'role'), 'alertdialog'); ok('reminder: the confirm step is an alert dialog');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-confirm-back')), true); ok('reminder: focus starts on Back, the safe choice');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-ghca-reminder-dialog]').isVisible(), true);
    assert.equal(await page.inputValue('[data-ghca-reminder-subject]'), 'Your Fatal Five course is due'); ok('reminder: Escape steps back to the form with everything still typed');
    await page.click('[data-ghca-reminder-submit]');
    await page.click('[data-ghca-confirm-back]');
    assert.equal(await page.locator('[data-ghca-reminder-dialog]').isVisible(), true); ok('reminder: Back returns to the form');

    // A refused send: the error is a toast above the modal, and the text is kept.
    state.sendFail = true;
    await page.click('[data-ghca-reminder-submit]');
    await page.click('[data-ghca-confirm-send]');
    await page.waitForSelector('[data-ghca-toasts] .gh-toast--error');
    assert.match(await page.locator('[data-ghca-toasts] .gh-toast--error').innerText(), /The mail server refused the message\./); ok('reminder: a refused send shows the reason in an error toast');
    assert.equal(await page.locator('[data-ghca-reminder-dialog]').isVisible(), true);
    assert.equal(await page.inputValue('[data-ghca-reminder-message]'), 'Hi Jordan, please start with Fatal Five.'); ok('reminder: and keeps the form, so nothing is retyped');
    const toastTop = await page.evaluate(() => { const t = document.querySelector('[data-ghca-toasts] .gh-toast--error').getBoundingClientRect(); const el = document.elementFromPoint(t.left + t.width / 2, t.top + t.height / 2); return !!(el && el.closest('[data-ghca-toasts]')); });
    assert.equal(toastTop, true); ok('reminder: the toast is above the modal and scrim');
    assert.equal(await page.locator('[data-ghca-reminder-submit]').isDisabled(), false); ok('reminder: the form can be sent again');

    // A successful send.
    state.sendFail = false;
    const reloadsBefore = state.calls.filter((c) => c === 'ghca_acd_get_employee_drawer').length;
    await page.click('[data-ghca-reminder-submit]');
    await page.click('[data-ghca-confirm-send]');
    await page.waitForSelector('#ghca-acd-reminder-modal[hidden]', { state: 'attached' });
    assert.equal(state.sent.channel, 'email'); assert.equal(state.sent.user_id, '42'); assert.equal(state.sent.template_id, '7');
    assert.equal(state.sent.subject, 'Your Fatal Five course is due'); assert.equal(state.sent.urgency, 'normal'); assert.match(state.sent.idempotency_token, /^tok-/); ok('reminder: the send posts the existing action with channel, template, subject, urgency and the one-time token');
    assert.equal(state.sent.sms_safe_confirmed, undefined); ok('reminder: no SMS consent is claimed for an email-only send');
    await page.waitForFunction(() => document.querySelector('[data-ghca-toasts] .gh-toast--success'));
    assert.match(await page.locator('[data-ghca-toasts] .gh-toast--success').innerText(), /Reminder queued for Jordan Ellis\./); ok('reminder: success is a toast, shown only after the server accepted it');
    await page.waitForFunction((n) => true, reloadsBefore);
    assert.ok(state.calls.filter((c) => c === 'ghca_acd_get_employee_drawer').length > reloadsBefore); ok('reminder: the drawer reloads so the new message appears in Communications');
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true); ok('reminder: the drawer stays open underneath');

    // The toast sits bottom-right, over the drawer footer, as on the board. Dismissing it clears the way.
    await page.locator('[data-ghca-toasts] .gh-toast-x').first().click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-toasts] .gh-toast').length === 0);
    // Escape closes the modal and not the drawer; a fresh open starts on the form.
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    await page.fill('[data-ghca-reminder-subject]', 'Hello'); await page.fill('[data-ghca-reminder-message]', 'Hello');
    await page.click('[data-ghca-reminder-submit]');
    await page.keyboard.press('Escape'); // back to the form
    await page.keyboard.press('Escape'); // close
    await page.waitForSelector('#ghca-acd-reminder-modal[hidden]', { state: 'attached' });
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true); ok('reminder: Escape twice goes back, then closes the modal and leaves the drawer');
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(await page.locator('[data-ghca-reminder-dialog]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-reminder-confirm]').isVisible(), false); ok('reminder: reopening always starts on the form');
    // Tab stays inside the modal.
    for (let i = 0; i < 14; i++) { await page.keyboard.press('Tab'); }
    assert.equal(await page.evaluate(() => !!document.activeElement.closest('#ghca-acd-reminder-modal')), true); ok('reminder: Tab stays inside the modal');
    await page.click('.gh-scrim[data-ghca-reminder-close]', { position: { x: 20, y: 20 } });
    await page.waitForSelector('#ghca-acd-reminder-modal[hidden]', { state: 'attached' }); ok('reminder: a click on the scrim closes it');

    // SMS: the consent box is required, the subject is not used, and the confirm step says so.
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    await page.locator('[data-ghca-reminder-channel-option][value="sms"]').check();
    assert.equal(await page.locator('[data-ghca-sms-confirm]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-reminder-subject]').isDisabled(), true); ok('reminder: SMS shows the consent box and does not use the subject line');
    await page.fill('[data-ghca-reminder-message]', 'Please start Fatal Five.');
    await page.click('[data-ghca-reminder-submit]');
    assert.match(await page.locator('[data-ghca-reminder-error]').innerText(), /Confirm that the SMS contains no sensitive information/);
    assert.equal(await page.locator('[data-ghca-reminder-confirm]').isVisible(), false); ok('reminder: an SMS cannot be reviewed until the no-sensitive-information box is ticked');
    await page.check('[data-ghca-sms-safe]');
    await page.click('[data-ghca-reminder-submit]');
    assert.match(await page.locator('[data-ghca-confirm-title]').innerText(), /^Send this text to Jordan Ellis\?$/);
    assert.equal(await page.locator('[data-ghca-confirm-to]').innerText(), '•••• 4471');
    assert.equal(await page.locator('[data-ghca-confirm-subject]').isVisible(), false); ok('reminder: the SMS confirm step shows the phone, not a subject');
    await page.click('[data-ghca-confirm-send]');
    await page.waitForSelector('#ghca-acd-reminder-modal[hidden]', { state: 'attached' });
    assert.equal(state.sent.channel, 'sms'); assert.equal(state.sent.sms_safe_confirmed, '1'); ok('reminder: the SMS send carries the consent confirmation to the existing action');

    // Fonts.
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    await page.selectOption('[data-ghca-reminder-template]', '7');
    const cdp = await context.newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const doc = await cdp.send('DOM.getDocument', { depth: -1 });
    for (const sel of ['#ghca-acd-reminder-title', '#ghca-acd-reminder-modal [data-ghca-reminder-name]', '.gh-mod-form .gh-label', '.gh-mod-form .gh-input', '.gh-mod-form .gh-select', '.gh-seg--radio label > span', '[data-ghca-reminder-preview-subject]', '.gh-mod-ft .gh-small', '[data-ghca-reminder-submit]']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((x) => /Wix Madefor/.test(x.familyName)), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    assert.match(await page.evaluate(() => getComputedStyle(document.querySelector('.gh-mod-form .gh-textarea')).fontFamily), /Wix Madefor Text/); // its text sits in the browser's own shadow tree, which the renderer check cannot reach
    ok('reminder: the rendered font is Wix Madefor on every element checked');
    if (shots) { await page.screenshot({ path: path.join(shots, 'reminder-compose-1440.png') }); await page.click('[data-ghca-reminder-submit]').catch(() => {}); }
    assert.equal(errors.length, 0, 'console errors: ' + errors.join(' | ')); ok('no console or page errors');
    await context.close();
  }

  // ---------- Send reminder: a channel that is not ready ----------
  {
    const state = { calls: [], env: {}, context: { email_ready: false, email_reason: 'Email sending is switched off for this site.', sms_ready: false, sms_reason: 'No mobile number on file.' } };
    const { context, page } = await open(browser, build(path.join(dir, 'm'), {}), { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(await page.locator('[data-ghca-reminder-readiness]').isVisible(), true);
    assert.match(await page.locator('[data-ghca-reminder-readiness]').innerText(), /Email sending is switched off/); ok('reminder: when a channel is not ready the reason is shown');
    assert.equal(await page.locator('[data-ghca-reminder-submit]').isDisabled(), true); ok('reminder: and the form cannot be sent');
    await context.close();
  }

  // ---------- Retry a failed delivery ----------
  {
    const history = [{ communication_id: 5, delivery_id: 322, channel: 'sms', delivery_state: 'failed', actor_user_id: 3, urgency: 'normal', created_at: '2026-10-27 16:42:00', subject: 'Due', message: 'Hi.', last_error_code: 'no_phone', last_error_summary: 'No mobile number.', attempt_count: 1, provider_ref: '' }];
    const env = { jotform: true, messaging: true, history, hist_total: 1 };
    const state = { calls: [], env };
    const { context, page } = await open(browser, build(path.join(dir, 'n'), { env }), { width: 1440, height: 900 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('#ghca-acd-drawer-tab-communications');
    const reloads = state.calls.filter((c) => c === 'ghca_acd_get_employee_drawer').length;
    await page.click('[data-ghca-reminder-retry="322"]');
    await page.waitForFunction(() => document.querySelector('[data-ghca-toasts] .gh-toast--success'));
    assert.equal(state.retried, '322'); ok('retry: Retry posts the existing action with this delivery id');
    assert.match(await page.locator('[data-ghca-toasts] .gh-toast--success').innerText(), /queued for retry/); ok('retry: the result is a toast');
    assert.ok(state.calls.filter((c) => c === 'ghca_acd_get_employee_drawer').length > reloads); ok('retry: the drawer reloads to show the new state');
    await context.close();
  }

  // ---------- Send reminder: hostile theme and phone ----------
  {
    const grab = async (opts) => {
      const state = { calls: [], env: {} };
      const { context, page } = await open(browser, build(path.join(dir, opts.dir), { hostile: opts.hostile }), { width: 1440, height: 900 }, state);
      await page.click('#open-drawer'); await drawerOpen(page);
      await page.click('.gh-drawer-ft [data-ghca-reminder]');
      await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
      await page.selectOption('[data-ghca-reminder-template]', '8');
      const m = (sel) => page.evaluate((x) => { const e = document.querySelector(x); if (!e) { return 'missing'; } const c = getComputedStyle(e); return [c.fontFamily, c.fontSize, c.fontWeight, c.lineHeight, c.textTransform, c.color, c.backgroundColor, c.paddingLeft, c.borderTopWidth, c.listStyleType, Math.round(e.getBoundingClientRect().height)].join('|'); }, sel);
      const out = {};
      for (const [k, sel] of Object.entries({ title: '#ghca-acd-reminder-title', who: '.gh-mod-who', label: '.gh-mod-form .gh-label', input: '.gh-mod-form .gh-input', select: '.gh-mod-form .gh-select', area: '.gh-mod-form .gh-textarea', seg: '.gh-seg--radio', segOn: '.gh-seg--radio input:checked + span', pv: '.gh-mod-pv-hd', pvs: '.gh-mod-pv-s', badge: '[data-ghca-reminder-urgency-badge]', send: '[data-ghca-reminder-submit]', cancel: '.gh-mod-ft .gh-btn--secondary', x: '[data-ghca-reminder-x]' })) { out[k] = await m(sel); }
      await context.close();
      return out;
    };
    const clean = await grab({ dir: 'o1', hostile: false });
    const hostile = await grab({ dir: 'o2', hostile: true });
    const drift = Object.keys(clean).filter((k) => clean[k] !== hostile[k]).map((k) => k + ': ' + clean[k] + '  vs  ' + hostile[k]);
    assert.deepEqual(drift, []); ok('reminder: title, recipient, labels, controls, segmented control, preview, badge and buttons measure the same under a hostile theme');

    const state = { calls: [], env: {} };
    const { context, page } = await open(browser, build(path.join(dir, 'p'), {}), { width: 390, height: 844 }, state);
    await page.click('#open-drawer'); await drawerOpen(page);
    await page.click('.gh-drawer-ft [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    const g = await page.evaluate(() => { const d = document.querySelector('[data-ghca-reminder-dialog]').getBoundingClientRect(); return { w: Math.round(d.width), vw: window.innerWidth, bottom: Math.round(window.innerHeight - d.bottom), h: Math.round(d.height), vh: window.innerHeight, doc: document.documentElement.scrollWidth, over: Array.from(document.querySelectorAll('#ghca-acd-reminder-modal [data-ghca-reminder-dialog] *')).filter((e) => e.offsetParent !== null && e.getBoundingClientRect().right > d.right + 1).length, ctl: Array.from(document.querySelectorAll('#ghca-acd-reminder-modal .gh-input, #ghca-acd-reminder-modal .gh-select, #ghca-acd-reminder-modal .gh-mod-ft .gh-btn')).filter((e) => e.offsetParent !== null).map((e) => Math.round(e.getBoundingClientRect().height)), cols: getComputedStyle(document.querySelector('.gh-mod-grid')).gridTemplateColumns.split(' ').length }; });
    assert.equal(g.w, g.vw); assert.equal(g.bottom, 0); assert.ok(g.h <= g.vh * 0.92 + 1); ok('phone: the modal is a bottom sheet, full width and no taller than 92% of the screen');
    assert.equal(g.over, 0); assert.ok(g.doc <= g.vw + 1); ok('phone: no sideways overflow');
    assert.equal(g.cols, 1); ok('phone: urgency and template stack');
    assert.ok(g.ctl.every((h) => h >= 42), 'controls: ' + g.ctl.join(',')); ok('phone: inputs, selects and buttons are at least 42px tall');
    if (shots) { await page.screenshot({ path: path.join(shots, 'reminder-compose-390.png') }); }
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' browser checks\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
