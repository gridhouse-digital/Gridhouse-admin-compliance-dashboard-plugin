'use strict';
/**
 * Browser checks for slice 7: the Employees paper-plane action, the drawer Communications tab and the full history
 * modal, the packet-generator header colour, and the packet-ready notice. Pages and markup are the plugin's own PHP
 * (tests/render-tables-preview.php, tests/render-drawer-preview.php). AJAX answers are canned or come from the
 * plugin's drawer functions over synthetic rows: nothing is queued or sent, no packet is built here (the real
 * packet build is tests/integration-packet-ready.php). Synthetic employees. Not WordPress, not staging.
 *
 *   node tests/browser-slice7.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const tablesPreview = path.join(__dirname, 'render-tables-preview.php');
const drawerPreview = path.join(__dirname, 'render-drawer-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const BRAND = '#6A2C91'; // deliberately not Akansoh green
const PDF = Buffer.concat([Buffer.from('%PDF-1.4\n% synthetic packet\n'), Buffer.alloc(800, 32), Buffer.from('\n%%EOF\n')]);

function assets(url, route) {
  const target = path.join(root, url.pathname);
  if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) {
    return route.fulfill({ status: 200, contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) });
  }
  return route.fulfill({ status: 404, body: '' });
}
const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
function context(state, name) {
  return { success: true, data: { employee_id: Number(state.ctxUser), employee_name: name, idempotency_token: 'tok-' + state.ctxUser, masked_email: 'p•••••@example.test', masked_phone: '', email_ready: true, sms_ready: false, email_reason: '', sms_reason: 'No mobile number on file.',
    templates: [{ id: 7, name: 'Course due soon', subject: 'Your course is due', message: 'Hi, please start your course.', urgency: 'normal', channels: ['email'], reason: '' }] } };
}

// ---------------------------------------------------------------- Employees table
async function openTables(browser, viewport, perms) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-s7t-'));
  const penv = { ...process.env, GHCA_PREVIEW_BULK: '1', GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_COUNT: '6', GHCA_PREVIEW_PERMS: perms || '', GHCA_PREVIEW_BRAND: BRAND };
  execFileSync('php', [tablesPreview, 'page', dir, 'gridhouse', ''], { env: penv });
  const file = path.join(dir, 'tables.html');
  const state = { calls: [], sends: [] };
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: shots ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await ctx.route('https://preview.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      state.calls.push(params.action);
      if (params.action === 'ghca_acd_get_reminder_context') {
        state.ctxUser = params.user_id;
        return json(route, context(state, state.names[params.user_id] || 'Unknown'));
      }
      if (params.action === 'ghca_acd_send_reminder') { state.sends.push(params); return json(route, { success: true, data: { message: 'Reminder queued (synthetic; nothing sent).' } }); }
      const out = execFileSync('php', [tablesPreview, 'ajax', JSON.stringify(params), '6'], { env: penv }).toString();
      return route.fulfill({ status: 200, contentType: 'application/json', body: out });
    }
    if (url.pathname === '/tables.html') { return route.fulfill({ status: 200, contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) { return assets(url, route); }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto('https://preview.test/tables.html#ghca-tab-employees');
  await page.evaluate(() => document.fonts.ready);
  state.names = await page.evaluate(() => Object.fromEntries(Array.from(document.querySelectorAll('#ghca-tab-employees .gh-row-remind')).map((b) => [b.getAttribute('data-ghca-reminder'), b.getAttribute('data-ghca-reminder-name')])));
  return { ctx, page, errors, state };
}

// ---------------------------------------------------------------- Drawer
function history(n) {
  const rows = [];
  for (let i = 0; i < n; i++) {
    const id = 100 + i;
    const day = String(28 - (i % 27)).padStart(2, '0');
    const sms = i % 3 === 1;
    rows.push({ communication_id: id, delivery_id: 1000 + i, channel: sms ? 'sms' : 'email', delivery_state: i === 1 ? 'failed' : (sms ? 'delivered' : 'accepted'), actor_user_id: 3, urgency: i === 0 ? 'urgent' : 'normal', created_at: '2026-10-' + day + ' 09:' + String(i % 60).padStart(2, '0') + ':00', subject: sms ? '' : 'Reminder ' + id + ': your course is due', message: 'Hi Jordan,\nmessage ' + id + ' body text.', last_error_code: i === 1 ? 'no_phone' : '', last_error_summary: i === 1 ? 'No mobile number on file.' : '', attempt_count: 1, provider_ref: '', template_id: i === 0 ? 7 : 0, source: i === 2 ? 'bulk' : 'employee_drawer' });
  }
  return rows;
}

async function openDrawer(browser, viewport, env, st = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-s7d-'));
  execFileSync('php', [drawerPreview, 'page', dir, 'gridhouse', ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_ENV: JSON.stringify(env), GHCA_PREVIEW_BRAND: st.brand || BRAND } });
  const file = path.join(dir, 'drawer.html');
  const state = Object.assign({ calls: [], hist: [], inits: 0, merges: 0, downloads: 0, pdfServed: 0 }, st);
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: shots ? 2 : 1, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
  await ctx.route('https://preview.test/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.pathname === '/ajax' && req.method() === 'GET') {
      // The packet download: the private, authorized endpoint streams once, then the job is gone (as on the server).
      state.downloads += 1;
      if (url.searchParams.get('action') !== 'ghca_acd_pdf_download') { return route.fulfill({ status: 400, body: '0' }); }
      if (state.offline) { return route.abort('internetdisconnected'); }
      const job = url.searchParams.get('job_id');
      if (state.gone.has(job)) { return route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'This download job is no longer available. Please start again.' } }) }); }
      state.gone.add(job); state.pdfServed += 1;
      return route.fulfill({ status: 200, headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="' + job + '.pdf"' }, body: PDF });
    }
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(req.postData() || ''));
      state.calls.push(params.action);
      const env2 = env;
      if (params.action === 'ghca_acd_get_employee_drawer') {
        delete params.action;
        return route.fulfill({ contentType: 'application/json', body: execFileSync('php', [drawerPreview, 'ajax', JSON.stringify(params), JSON.stringify(env2)]).toString() });
      }
      if (params.action === 'ghca_acd_get_edit_records_form') {
        delete params.action; delete params.nonce;
        return route.fulfill({ contentType: 'application/json', body: execFileSync('php', [drawerPreview, 'edit', JSON.stringify(params), JSON.stringify(env2)]).toString() });
      }
      if (params.action === 'ghca_acd_get_communication_history') {
        state.hist.push(params);
        if (state.histFail) { return json(route, { success: false, data: { message: 'Synthetic history failure.' } }); }
        return route.fulfill({ contentType: 'application/json', body: execFileSync('php', [drawerPreview, 'history', JSON.stringify(params), JSON.stringify(env2)]).toString() });
      }
      if (params.action === 'ghca_acd_get_communication_draft') { state.draft = params; return json(route, { success: true, data: { userId: 42, subject: 'Reminder ' + params.communication_id + ': your course is due', message: 'Hi Jordan, reused.', urgency: 'normal', channel: 'email' } }); }
      if (params.action === 'ghca_acd_retry_reminder') { state.retried = params.delivery_id; return json(route, { success: true, data: { message: 'The failed delivery was queued for another attempt.' } }); }
      if (params.action === 'ghca_acd_get_reminder_context') { state.ctxUser = params.user_id; return json(route, context(state, 'Jordan Ellis')); }
      if (params.action === 'ghca_acd_send_reminder') { state.sends = (state.sends || 0) + 1; return json(route, { success: true, data: { message: 'Synthetic; nothing sent.' } }); }
      if (params.action === 'ghca_acd_agency_training') { return json(route, { success: true, data: { html: '<p>Synthetic.</p>', message: 'ok' } }); }
      if (params.action === 'ghca_acd_pdf_init') {
        state.inits += 1; state.lastInit = params;
        if (state.initDelay) { await new Promise((r) => setTimeout(r, state.initDelay)); }
        return json(route, { success: true, data: { job_id: 'job' + state.inits, total: 0 } });
      }
      if (params.action === 'ghca_acd_pdf_merge') {
        state.merges += 1;
        if (state.mergeFail) { return json(route, { success: false, data: { message: 'Packet generation failed: a stored certificate could not be read. No packet was created.' } }); }
        const d = { message: 'Packet ready.', download_url: 'https://preview.test/ajax?action=ghca_acd_pdf_download&job_id=' + params.job_id + '&nonce=test' };
        if (state.details !== false) { Object.assign(d, { pages: 7, flagged: 2, tracker: state.trackerEcho || undefined, expires: Math.floor(Date.now() / 1000) + (state.ttl || 3600) }); }
        return json(route, { success: true, data: d });
      }
      if (params.action === 'ghca_acd_pdf_cancel') { return json(route, { success: true, data: {} }); }
      return json(route, { success: false, data: { message: 'not in this test' } });
    }
    if (url.pathname === '/drawer.html') { return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) { return assets(url, route); }
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>stub</p>' });
  });
  state.gone = new Set();
  await page.goto('https://preview.test/drawer.html#ghca-tab-employees');
  await page.waitForSelector('#open-drawer');
  await page.click('#open-drawer');
  await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drawer-hd');
  return { ctx, page, errors, state };
}

async function openEdit(page) {
  await page.click('#ghca-acd-drawer-tab-administration');
  await page.click('[data-ghca-edit-records]');
  await page.waitForSelector('[data-ghca-edit-form]');
}
const luminance = (rgb) => { const c = rgb.match(/\d+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const contrast = (a, b) => { const x = luminance(a); const y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // ================= 2. Employees: paper-plane per row =================
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }]) {
    const { ctx, page, errors, state } = await openTables(browser, vp);
    const w = vp.width;
    const rows = await page.locator('#ghca-tab-employees table tbody tr').count();
    const planes = page.locator('#ghca-tab-employees table .gh-row-remind');
    assert.ok(rows > 0 && (await planes.count()) > 0); ok(w + ': Employees rows carry a paper-plane message action');
    const first = planes.first();
    const id = await first.getAttribute('data-ghca-reminder');
    const name = await first.getAttribute('data-ghca-reminder-name');
    assert.equal(await first.getAttribute('aria-label'), 'Send reminder to ' + name); ok(w + ': the action is labelled with the employee name (' + name + ')');
    assert.equal(await first.evaluate((b) => b.tagName), 'BUTTON');
    assert.equal(await first.locator('.i-send, .gh-i').count() > 0, true); ok(w + ': it is a button with an icon');
    const size = await first.evaluate((b) => { const r = b.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
    assert.ok(size[0] >= (w < 1000 ? 44 : 24) && size[1] >= (w < 1000 ? 44 : 24), 'target ' + size); ok(w + ': target size ' + size.join('x') + (w < 1000 ? ' (44px minimum on touch widths)' : ' (WCAG 2.5.8 minimum 24px)'));
    // Keyboard: focus it and press Enter.
    await first.focus();
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(state.ctxUser, id); ok(w + ': Enter opens the existing composer for that row\'s employee (context requested for user ' + id + ')');
    assert.equal(await page.locator('#ghca-acd-reminder-modal [data-ghca-reminder-name]').innerText(), name); ok(w + ': the composer names the right recipient');
    assert.equal(state.calls.filter((a) => a === 'ghca_acd_get_employee_drawer').length, 0); ok(w + ': the row action does not open the drawer');
    await page.selectOption('[data-ghca-reminder-template]', '7');
    await page.click('[data-ghca-reminder-submit]');
    assert.equal(await page.locator('[data-ghca-reminder-confirm]').isVisible(), true);
    assert.match(await page.locator('[data-ghca-reminder-confirm]').innerText(), new RegExp(name)); ok(w + ': Review & send shows a confirm step naming the recipient');
    assert.equal(state.sends.length, 0); ok(w + ': nothing is sent before confirming');
    await page.click('[data-ghca-reminder-confirm-send], [data-ghca-confirm-send]');
    await page.waitForFunction(() => document.querySelector('#ghca-acd-reminder-modal').hidden || document.querySelector('[data-ghca-toasts] .gh-toast'));
    assert.equal(state.sends.length, 1); assert.equal(state.sends[0].user_id || state.sends[0].employee_id, id); ok(w + ': confirming posts one request for that employee only (canned answer; nothing queued or sent)');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-employees-' + w + '.png') }); }
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }
  {
    const { ctx, page } = await openTables(browser, { width: 390, height: 844 });
    const cards = page.locator('#ghca-tab-employees .gh-mcard [data-ghca-reminder]');
    assert.ok(await cards.count() > 0); ok('390: phone cards carry a Remind action');
    const b = cards.first();
    assert.match(await b.getAttribute('aria-label'), /^Send reminder to /);
    const h = await b.evaluate((x) => Math.round(x.getBoundingClientRect().height));
    assert.ok(h >= 44, 'height ' + h); ok('390: it is labelled and at least 44px tall');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true); ok('390: no sideways overflow');
    await b.click();
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    ok('390: tapping it opens the composer');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-employees-390.png') }); }
    await ctx.close();
  }
  {
    const { ctx, page } = await openTables(browser, { width: 1440, height: 900 }, 'noremind');
    assert.equal(await page.locator('.gh-row-remind, .gh-mcard [data-ghca-reminder]').count(), 0); ok('without reminder permission the row action is not rendered');
    await ctx.close();
  }

  // ================= 1. Communications tab and full history =================
  const env = { jotform: true, messaging: true, history: history(25) };
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors, state } = await openDrawer(browser, vp, env);
    await page.click('#ghca-acd-drawer-tab-communications');
    const subs = page.locator('[data-ghca-history-channel]');
    assert.equal(await subs.count(), 3);
    assert.match(await subs.nth(0).innerText(), /All\s+25/); ok(w + ': sub-tabs All (25) / Email / SMS');
    assert.equal(await subs.nth(0).getAttribute('aria-pressed'), 'true');
    assert.equal(await page.locator('[data-ghca-history-list] .gh-tl > li').count(), 3); ok(w + ': the drawer shows the latest three messages');
    assert.match(await page.locator('[data-ghca-history-list]').innerText(), /Showing latest 3/); ok(w + ': footer says how many are shown and offers the full history');
    assert.match(await page.locator('[data-ghca-history-list]').innerText(), /Template: Course due soon/); ok(w + ': a message sent from a template names it');
    // Show/Hide message.
    const det = page.locator('[data-ghca-history-list] details[data-ghca-msg-toggle]').first();
    const sum = det.locator('summary');
    const before = await sum.innerText();
    const wasOpen = await det.evaluate((e) => e.open);
    await sum.click();
    await page.waitForFunction((b) => { const s = document.querySelector('[data-ghca-history-list] details[data-ghca-msg-toggle] summary'); return s && s.innerText !== b; }, before);
    const after = await sum.innerText();
    assert.equal(await det.evaluate((e) => e.open), !wasOpen);
    assert.notEqual(before, after); ok(w + ': Show/Hide message toggles and its label follows (' + before.trim() + ' → ' + after.trim() + ')');
    // Email / SMS.
    await subs.nth(2).click();
    await page.waitForFunction(() => document.querySelector('[data-ghca-history-channel="sms"]').getAttribute('aria-pressed') === 'true' && !document.querySelector('[data-ghca-history-list]').getAttribute('aria-busy'));
    await page.waitForFunction(() => /SMS/.test(document.querySelector('[data-ghca-history-list]').innerText));
    const last = state.hist[state.hist.length - 1];
    assert.equal(last.view, 'drawer'); assert.equal(last.channel, 'sms'); assert.equal(last.ghca_ui, 'board'); ok(w + ': SMS asks the server for this employee\'s SMS only (view=drawer, channel=sms)');
    const chans = await page.$$eval('[data-ghca-history-list] .gh-tl > li', (els) => els.map((e) => e.innerText));
    assert.ok(chans.length > 0 && chans.every((t) => /SMS/.test(t) && !/Email/.test(t)), JSON.stringify(chans)); ok(w + ': the list shows SMS messages only');
    assert.match(await page.locator('[data-ghca-history-drawer-status]').innerText(), /SMS/i); ok(w + ': the change is announced to screen readers');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-comms-' + w + '.png') }); }
    await subs.nth(1).click();
    await page.waitForFunction(() => document.querySelector('[data-ghca-history-channel="email"]').getAttribute('aria-pressed') === 'true');
    // A failure is reported, not hidden.
    state.histFail = true;
    await subs.nth(0).click();
    await page.waitForFunction(() => /Synthetic history failure|could not/i.test(document.querySelector('[data-ghca-history-drawer-status]').innerText + document.querySelector('[data-ghca-history-list]').innerText));
    assert.match(await page.locator('[data-ghca-history-list] [role="alert"]').innerText(), /Synthetic history failure\. Select the tab again to retry\./); ok(w + ': a failed history load says so, as an alert, and how to retry');
    state.histFail = false;
    const calls = state.hist.length;
    await subs.nth(0).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-history-list] .gh-tl > li').length === 3);
    assert.equal(state.hist.length, calls + 1); ok(w + ': selecting the same tab again retries');
    await page.waitForFunction(() => document.querySelector('[data-ghca-history-channel=""]').getAttribute('aria-pressed') === 'true' && document.querySelectorAll('[data-ghca-history-list] .gh-tl > li').length === 3);

    // Full history modal.
    await page.click('[data-ghca-history-open]');
    await page.waitForSelector('#ghca-acd-history-modal:not([hidden]) .gh-ch-list');
    assert.equal(await page.locator('#ghca-acd-history-modal').getAttribute('data-ghca-ui'), 'board'); ok(w + ': View all opens the board history modal');
    assert.equal(await page.locator('.ghca-acd__history-modal-dialog').count(), 0); ok(w + ': the legacy history modal is not on the page');
    assert.equal(await page.locator('.gh-ch-list > li').count(), 20); ok(w + ': 20 messages per page');
    assert.match(await page.locator('[data-ghca-history-page-label]').innerText(), /1.*2/); ok(w + ': pager reads page 1 of 2');
    assert.equal(await page.locator('[data-ghca-history-stat="total"]').innerText(), '25');
    assert.equal(await page.locator('[data-ghca-history-stat="failed"]').innerText(), '1'); ok(w + ': totals tiles show the recorded totals');
    await page.click('[data-ghca-history-page="next"]');
    await page.waitForFunction(() => document.querySelectorAll('.gh-ch-list > li').length === 5);
    ok(w + ': Next shows the remaining 5');
    await page.selectOption('#ghca-ch-s', 'failed');
    await page.waitForFunction(() => document.querySelectorAll('.gh-ch-list > li').length === 1);
    assert.equal(state.hist[state.hist.length - 1].status, 'failed'); ok(w + ': the status filter asks the server and shows the one failed message');
    const tog = page.locator('[data-ghca-ch-toggle]').first();
    assert.equal(await tog.getAttribute('aria-expanded'), 'false');
    await tog.click();
    assert.equal(await tog.getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('#' + (await tog.getAttribute('aria-controls'))).isVisible(), true); ok(w + ': View full message expands the controlled region and sets aria-expanded');
    await page.click('#ghca-acd-history-modal [data-ghca-reminder-retry]');
    await page.waitForFunction(() => /another attempt/.test((document.querySelector('[data-ghca-toasts]') || document.body).innerText));
    assert.equal(state.retried, '1001'); ok(w + ': Retry posts the failed delivery id to the existing retry action');
    await page.click('[data-ghca-history-reset]');
    await page.waitForFunction(() => document.querySelectorAll('.gh-ch-list > li').length === 20);
    ok(w + ': Clear filters returns to all messages');
    await page.fill('#ghca-ch-q', 'Reminder 105');
    await page.press('#ghca-ch-q', 'Enter');
    await page.waitForFunction(() => document.querySelectorAll('.gh-ch-list > li').length === 1);
    ok(w + ': search filters by subject or message');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-history-' + w + '.png') }); }
    assert.equal(await page.evaluate(() => { const d = document.querySelector('[data-ghca-history-dialog]').getBoundingClientRect(); return d.right <= window.innerWidth + 1 && d.left >= -1 && document.documentElement.scrollWidth <= window.innerWidth; }), true); ok(w + ': the modal fits the screen');
    await page.click('[data-ghca-history-reset]');
    await page.waitForFunction(() => document.querySelectorAll('.gh-ch-list > li').length === 20);
    assert.equal(await page.locator('[data-ghca-history-reuse="102"]').count(), 0);
    assert.equal(await page.locator('[data-ghca-history-reuse]').count(), 19); ok(w + ': Use again is offered for drawer reminders only, not for the bulk message');
    await page.click('[data-ghca-history-reuse="105"]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(state.draft.communication_id, '105');
    assert.equal(await page.inputValue('[data-ghca-reminder-subject]'), 'Reminder 105: your course is due'); ok(w + ': Use again loads the draft into the existing composer');
    assert.equal(await page.locator('#ghca-acd-history-modal').isHidden(), true); ok(w + ': and closes the history modal');
    assert.equal(state.sends || 0, 0); ok(w + ': nothing is sent');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }
  {
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, { jotform: true, messaging: true, history: [] , hist_total: 0 });
    await page.click('#ghca-acd-drawer-tab-communications');
    assert.match(await page.locator('[data-ghca-history-list]').innerText(), /No reminders have been recorded/); ok('an employee with no messages gets the empty state');
    await page.click('[data-ghca-history-channel="email"]');
    await page.waitForFunction(() => /No emails have been recorded/.test(document.querySelector('[data-ghca-history-list]').innerText));
    ok('and the Email sub-tab has its own empty state');
    await ctx.close();
  }
  {
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, { jotform: true, messaging: true, history: history(4), can_comms: false });
    assert.equal(await page.locator('#ghca-acd-history-modal').count(), 0); ok('without history permission the history modal is not rendered');
    assert.equal(await page.locator('[data-ghca-history-open]').count(), 0); ok('and nothing offers to open it');
    await ctx.close();
  }

  // ================= 3. Packet header colour =================
  for (const [brand, label] of [[BRAND, 'purple'], ['#F2C94C', 'light yellow']]) {
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] }, { brand });
    await openEdit(page);
    const c = await page.evaluate(() => {
      const hd = document.querySelector('.gh-pk .gh-pk-hd');
      const card = document.querySelector('.gh-pk');
      const bd = document.querySelector('.gh-pk .gh-card-bd');
      const cs = (e) => getComputedStyle(e);
      const root = cs(document.querySelector('.ghca-ui'));
      return { hd: cs(hd).backgroundColor, card: cs(card).backgroundColor, bd: cs(bd).backgroundColor, agency: root.getPropertyValue('--gh-agency').trim(),
        eyebrow: cs(hd.querySelector('.gh-eyebrow')).color, h4: cs(hd.querySelector('.gh-h4')).color, small: cs(hd.querySelector('.gh-small')).color, icon: cs(hd.querySelector('.gh-i')).color,
        text: hd.innerText, opacity: ['.gh-eyebrow', '.gh-h4', '.gh-small', '.gh-i'].map((q) => cs(hd.querySelector(q)).opacity),
        iconRight: hd.querySelector('.gh-i').getBoundingClientRect().top < hd.querySelector('.gh-h4').getBoundingClientRect().bottom && hd.querySelector('.gh-i').getBoundingClientRect().left > hd.querySelector('.gh-h4').getBoundingClientRect().right };
    });
    const hex = (rgb) => '#' + rgb.match(/\d+/g).slice(0, 3).map((v) => Number(v).toString(16).padStart(2, '0')).join('').toUpperCase();
    assert.equal(hex(c.hd), brand.toUpperCase()); ok('packet header (' + label + ' agency ' + brand + '): background is the agency main colour, from the token');
    assert.notEqual(c.bd, c.hd); assert.notEqual(c.card, c.hd); ok('packet header (' + label + '): only the header is coloured, not the card');
    for (const k of ['eyebrow', 'h4', 'small', 'icon']) { const r = contrast(c[k], c.hd); assert.ok(r >= 4.5, k + ' contrast ' + r.toFixed(2)); }
    ok('packet header (' + label + '): eyebrow, title, description and icon contrast ≥ 4.5:1 (' + ['eyebrow', 'h4', 'small', 'icon'].map((k) => contrast(c[k], c.hd).toFixed(1)).join(', ') + ')');
    assert.ok(c.opacity.every((o) => o === '1'), 'opacity ' + c.opacity); ok('packet header (' + label + '): no opacity on the header text, so the measured contrast is what is seen');
    assert.equal(c.iconRight, true); ok('packet header (' + label + '): the icon sits to the right of the title, as on the board');
    assert.match(c.text, /Packet generator[\s\S]*Compliance packet[\s\S]*Compiles the compliance summary/i); ok('packet header (' + label + '): the header is the one with the generator copy');
    if (shots) { await page.locator('.gh-pk').screenshot({ path: path.join(shots, 's7-packet-header-' + label.replace(' ', '-') + '.png') }); }
    await ctx.close();
  }

  // ================= 4. Packet-ready notice =================
  {
    const { ctx, page, errors, state } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] });
    const downloads = [];
    page.on('download', (d) => downloads.push(d));
    const navs = [];
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs.push(f.url()); });
    await openEdit(page);
    // An unsaved Edit Records change.
    const field = page.locator('[data-ghca-edit-form] input[type="text"], [data-ghca-edit-form] input[type="date"]').first();
    const fname = await field.getAttribute('name');
    const unsaved = (await field.getAttribute('type')) === 'date' ? '2026-10-15' : 'Unsaved synthetic note';
    await field.fill(unsaved);
    await field.dispatchEvent('input');
    const dirtyBefore = await page.locator('[data-ghca-edit-dirty]').innerText();
    // Repeated clicks start one packet.
    state.initDelay = 400;
    await page.evaluate(() => { const b = document.querySelector('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]'); b.click(); b.click(); b.click(); });
    assert.equal(await page.locator('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="orientation"]').getAttribute('aria-disabled'), 'true'); ok('packet: while one builds, every packet button is marked busy');
    await page.waitForSelector('[data-ghca-packet-slot] [data-ghca-packet-notice]');
    state.initDelay = 0;
    assert.equal(state.inits, 1); assert.equal(state.merges, 1); ok('packet: three rapid clicks start one generation');
    assert.equal(downloads.length, 0); assert.equal(state.downloads, 0); ok('packet: generating does not download anything');
    assert.equal(navs.length, 0); assert.equal(page.url(), 'https://preview.test/drawer.html#ghca-tab-employees'); ok('packet: and does not navigate');
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-edit-form]').isVisible(), true); ok('packet: the drawer and Edit Records stay open');
    assert.equal(await page.locator('[data-ghca-edit-form] [name="' + fname + '"]').inputValue(), unsaved);
    assert.equal(await page.locator('[data-ghca-edit-dirty]').innerText(), dirtyBefore); ok('packet: the unsaved Edit Records change is kept (' + fname + ')');
    assert.equal(await page.locator('#ghca-acd-pdf-modal').isHidden(), true); ok('packet: the progress dialog closes');
    const note = page.locator('[data-ghca-packet-notice="annual"]');
    assert.equal(await note.getAttribute('role'), 'status'); ok('packet: the notice is a status message for assistive technology');
    assert.match(await note.innerText(), /Annual packet ready/);
    assert.match(await note.innerText(), /7 pages · 2 items flagged for evidence review/); ok('packet: it shows the page count and flagged items the merge returned');
    assert.match(await note.innerText(), /link expires \d{1,2}:\d{2}/); ok('packet: and when the link expires');
    const link = note.locator('[data-ghca-packet-download]');
    assert.equal(await link.innerText(), 'Download PDF');
    assert.equal(await link.getAttribute('aria-label'), 'Download Annual packet (PDF)');
    assert.match(await link.getAttribute('href'), /^https:\/\/preview\.test\/ajax\?action=ghca_acd_pdf_download&job_id=job1/); ok('packet: an explicit, labelled Download PDF link to the authorized endpoint');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-packet-ready-1440.png') }); }
    // Dismiss keeps the link.
    await note.locator('[data-ghca-packet-dismiss]').click();
    assert.match(await note.getAttribute('class'), /is-compact/);
    assert.equal(await link.isVisible(), true);
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-packet-download')), true); ok('packet: dismissing collapses the notice, keeps the link and moves focus to it');
    // Keyboard download.
    const dl = page.waitForEvent('download');
    await link.focus();
    await page.keyboard.press('Enter');
    const d = await dl;
    const saved = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-s7dl-')), d.suggestedFilename());
    await d.saveAs(saved);
    assert.equal(d.suggestedFilename(), 'job1.pdf');
    assert.equal(fs.readFileSync(saved).subarray(0, 5).toString(), '%PDF-'); ok('packet: Download PDF (keyboard) saves the PDF the endpoint returned');
    await page.waitForFunction(() => /private copy on the server has been deleted/.test(document.querySelector('[data-ghca-packet-notice="annual"]').innerText));
    assert.equal(await note.locator('[data-ghca-packet-download]').count(), 0); ok('packet: after download the notice says the server copy is gone (single use)');
    // Orientation; and a newer packet replaces an older notice.
    state.trackerEcho = 'orientation';
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="orientation"]');
    await page.waitForSelector('[data-ghca-packet-notice="orientation"]');
    assert.match(await page.locator('[data-ghca-packet-notice="orientation"]').innerText(), /Orientation packet ready/); ok('packet: Orientation produces its own notice');
    assert.equal(state.lastInit.tracker, 'orientation');
    state.trackerEcho = 'annual';
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-packet-notice="annual"]').length === 2);
    const orient = page.locator('[data-ghca-packet-notice="orientation"]');
    assert.match(await orient.innerText(), /Replaced by the packet you started next/);
    assert.equal(await orient.locator('[data-ghca-packet-download]').count(), 0); ok('packet: starting another packet marks the earlier link replaced (one packet per person on the server)');
    // A link the server no longer has.
    const latest = page.locator('[data-ghca-packet-notice="annual"]').last();
    state.gone.add('job3');
    await latest.locator('[data-ghca-packet-download]').click();
    await page.waitForFunction(() => { const l = document.querySelectorAll('[data-ghca-packet-notice="annual"]'); return /no longer available/.test(l[l.length - 1].innerText); });
    assert.equal(downloads.length, 1); ok('packet: an expired or used link is reported in the notice; nothing is saved');
    assert.equal(await latest.getAttribute('role'), 'alert');
    // Leaving Edit Records moves a live notice to the page tray.
    state.trackerEcho = 'orientation';
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="orientation"]');
    await page.waitForFunction(() => document.querySelectorAll('[data-ghca-packet-notice="orientation"]').length === 2);
    await page.click('[data-ghca-subpage-back]');
    await page.waitForFunction(() => !!document.querySelector('[data-ghca-packet-tray] [data-ghca-packet-download]'));
    assert.equal(await page.locator('[data-ghca-packet-tray] [data-ghca-packet-download]').isVisible(), true); ok('packet: leaving Edit Records moves the live notice to the page tray, link intact');
    await page.click('.gh-drawer-x');
    assert.equal(await page.locator('[data-ghca-packet-tray] [data-ghca-packet-download]').isVisible(), true); ok('packet: and it stays available after the drawer closes');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-packet-tray-1440.png') }); }
    // Network failure keeps the link.
    state.offline = true;
    await page.click('[data-ghca-packet-tray] [data-ghca-packet-download]');
    await page.waitForFunction(() => /Could not reach the server/.test(document.querySelector('[data-ghca-packet-tray]').innerText));
    assert.equal(await page.locator('[data-ghca-packet-tray] [data-ghca-packet-download]').innerText(), 'Download PDF'); ok('packet: a network error keeps the link and says nothing was saved');
    state.offline = false;
    const dl2 = page.waitForEvent('download');
    await page.click('[data-ghca-packet-tray] [data-ghca-packet-download]');
    await dl2; ok('packet: and it works when the network is back');
    assert.equal(errors.length, 0, errors.join(' | ')); ok('packet: no console or page errors');
    await ctx.close();
  }
  {
    // No details supplied: none invented.
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] }, { details: false });
    await openEdit(page);
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForSelector('[data-ghca-packet-notice]');
    const t = await page.locator('[data-ghca-packet-notice]').innerText();
    assert.doesNotMatch(t, /page|flagged|expires/i); assert.match(t, /Annual packet ready[\s\S]*Download PDF/); ok('packet: without result details the notice shows none (no placeholders)');
    await ctx.close();
  }
  {
    // Expiry while waiting; and time-out.
    const { ctx, page, state } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] }, { ttl: 2 });
    await openEdit(page);
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForSelector('[data-ghca-packet-notice]');
    await page.waitForFunction(() => /download link has expired/.test(document.querySelector('[data-ghca-packet-notice]').innerText), null, { timeout: 5000 });
    assert.equal(await page.locator('[data-ghca-packet-download]').count(), 0);
    assert.equal(state.downloads, 0); ok('packet: when the expiry the merge reported passes, the notice says so and removes the link');
    state.ttl = 3600;
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForSelector('[data-ghca-packet-notice] [data-ghca-packet-download]');
    await page.waitForFunction(() => { const l = document.querySelectorAll('[data-ghca-packet-notice]'); return l[l.length - 1].classList.contains('is-compact'); }, null, { timeout: 15000 });
    assert.equal(await page.locator('[data-ghca-packet-download]').isVisible(), true); ok('packet: after 12 s the notice collapses but the link stays');
    await ctx.close();
  }
  {
    // Generation failure.
    const { ctx, page, state } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] }, { mergeFail: true });
    await openEdit(page);
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForFunction(() => /No packet was created/.test(document.querySelector('[data-ghca-pdf-label]').innerText));
    assert.equal(await page.locator('[data-ghca-packet-notice]').count(), 0); ok('packet: a failed generation says so and shows no ready notice or link');
    assert.equal(await page.locator('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]').getAttribute('aria-disabled'), null); ok('packet: and the buttons are usable again');
    assert.equal(state.downloads, 0);
    await ctx.close();
  }
  {
    // From the drawer footer's menu (not Edit Records): the notice goes to the page tray.
    const { ctx, page, state } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] });
    await page.evaluate(() => document.querySelector('.gh-drawer-ft .gh-menu-i[data-ghca-pdf-packet][data-tracker="orientation"]').click());
    await page.waitForSelector('[data-ghca-packet-tray] [data-ghca-packet-notice] [data-ghca-packet-download]');
    assert.equal(state.lastInit.tracker, 'orientation');
    assert.equal(await page.locator('#ghca-acd-employee-drawer').isVisible(), true);
    assert.equal(state.downloads, 0); ok('packet: from the drawer menu, the notice appears in the page tray, the drawer stays open, nothing downloads');
    const top = await page.evaluate(() => { const r = document.querySelector('[data-ghca-packet-tray] [data-ghca-packet-download]').getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!(el && el.closest('[data-ghca-packet-tray]')); });
    assert.equal(top, true); ok('packet: the tray sits above the drawer and its link can be clicked');
    await ctx.close();
  }
  for (const vp of [{ width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const { ctx, page } = await openDrawer(browser, vp, { messaging: true, history: [] });
    await openEdit(page);
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForSelector('[data-ghca-packet-notice]');
    const g = await page.evaluate(() => { const r = document.querySelector('[data-ghca-packet-notice]').getBoundingClientRect(); const a = document.querySelector('[data-ghca-packet-download]').getBoundingClientRect(); return { fits: r.left >= 0 && r.right <= window.innerWidth + 1, over: document.documentElement.scrollWidth > window.innerWidth, h: Math.round(a.height) }; });
    assert.equal(g.fits, true); assert.equal(g.over, false); ok(vp.width + ': the ready notice fits without sideways scrolling');
    if (shots) { await page.screenshot({ path: path.join(shots, 's7-packet-ready-' + vp.width + '.png') }); }
    await ctx.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' slice 7 browser checks (synthetic; nothing sent; packet bytes canned)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
