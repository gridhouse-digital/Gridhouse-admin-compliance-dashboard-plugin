'use strict';
/**
 * Shared synthetic browser harness (slices 7 and 8): the plugin's own preview pages behind one routed origin, with
 * canned AJAX answers. Nothing is queued or sent; packet bytes are canned. Not WordPress, not staging.
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
async function openTables(browser, viewport, perms, hostile) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-s7t-'));
  const penv = { ...process.env, GHCA_PREVIEW_BULK: '1', GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_COUNT: '6', GHCA_PREVIEW_PERMS: perms || '', GHCA_PREVIEW_BRAND: BRAND };
  execFileSync('php', [tablesPreview, 'page', dir, 'gridhouse', hostile ? 'hostile' : ''], { env: penv });
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
  execFileSync('php', [drawerPreview, 'page', dir, st.theme || 'gridhouse', st.hostile ? 'hostile' : ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_ENV: JSON.stringify(env), GHCA_PREVIEW_BRAND: st.brand || BRAND } });
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
      if (params.action === 'ghca_acd_user_report') {
        state.reports = (state.reports || []).concat([params.user_id]);
        if (state.reportDelay && state.reportDelay[params.user_id]) { await new Promise((r) => setTimeout(r, state.reportDelay[params.user_id])); }
        if (state.session) { return route.fulfill({ status: 400, contentType: 'text/html', body: '-1' }); }
        return route.fulfill({ contentType: 'application/json', body: execFileSync('php', [drawerPreview, 'report', JSON.stringify(params), JSON.stringify(env2)]).toString() });
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
  if (!st.noDrawer) {
    await page.click('#open-drawer');
    await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drawer-hd');
  }
  return { ctx, page, errors, state };
}

async function openEdit(page) {
  await page.click('#ghca-acd-drawer-tab-administration');
  await page.click('[data-ghca-edit-records]');
  await page.waitForSelector('[data-ghca-edit-form]');
}
const luminance = (rgb) => { const c = rgb.match(/\d+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const contrast = (a, b) => { const x = luminance(a); const y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

/**
 * Visible contrast of every text, link, icon and control inside `root`, as painted: colour with every ancestor's
 * opacity, over the composited background of its ancestors. Icons (.gh-i) are masks painted in background-color.
 */
const NOTICE_CONTRAST = (root) => {
  const parse = (c) => { const m = (c.match(/[\d.]+/g) || []).map(Number); return { r: m[0] || 0, g: m[1] || 0, b: m[2] || 0, a: m.length > 3 ? m[3] : 1 }; };
  const over = (top, base) => ({ r: top.r * top.a + base.r * (1 - top.a), g: top.g * top.a + base.g * (1 - top.a), b: top.b * top.a + base.b * (1 - top.a) });
  const bg = (el) => { const layers = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) { layers.push(c); if (c.a >= 1) { break; } } } let base = { r: 255, g: 255, b: 255 }; for (let i = layers.length - 1; i >= 0; i--) { base = over(layers[i], base); } return base; };
  const opacity = (el) => { let o = 1; for (let e = el; e; e = e.parentElement) { o *= parseFloat(getComputedStyle(e).opacity); } return o; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const x = lum(a); const y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const out = [];
  const els = [root, ...root.querySelectorAll('*')].filter((e) => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden');
  for (const e of els) {
    const icon = e.classList.contains('gh-i');
    const text = Array.from(e.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim());
    if (!icon && !text) { continue; }
    const back = bg(icon ? e.parentElement : e);
    const c = parse(icon ? getComputedStyle(e).backgroundColor : getComputedStyle(e).color);
    c.a *= opacity(e);
    out.push({ what: (icon ? 'icon ' : '') + (e.getAttribute('data-ghca-packet-download') !== null ? 'link' : (e.closest('[data-ghca-packet-dismiss]') ? 'close' : (e.className || e.tagName))), text: (e.textContent || '').trim().slice(0, 40), ratio: Math.round(ratio(over(c, back), back) * 10) / 10 });
  }
  return out;
};

module.exports = { NOTICE_CONTRAST, assert, execFileSync, fs, os, path, chromium, root, tablesPreview, drawerPreview, shots, MIME, BRAND, PDF, assets, json, context, openTables, history, openDrawer, openEdit, luminance, contrast };
