'use strict';
/**
 * Browser reproduction of the Administration access report (slice 6). The drawer and Edit Records responses come
 * from the plugin's real handlers (tests/render-drawer-preview.php) for a permitted administrator and a denied
 * user. Covers reopening, switching to an employee the server refuses, a slow Edit Records load that finishes after
 * the drawer moved on, and an expired session. Synthetic; not WordPress, not staging.
 *
 *   node tests/browser-drawer-access.cjs [screenshot-dir]
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

async function open(browser, width, env, st = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-access-'));
  execFileSync('php', [preview, 'page', dir, 'gridhouse', ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_ENV: JSON.stringify(env) } });
  const file = path.join(dir, 'drawer.html');
  const state = Object.assign({ calls: [] }, st);
  const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: shots ? 2 : 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.route('https://preview.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      state.calls.push(params.action + ':' + params.user_id);
      if (state.session) { return route.fulfill({ status: 400, contentType: 'text/html', body: '-1' }); }
      // Employee 43 is outside this viewer's scope on the server.
      const e = Object.assign({}, env, params.user_id === '43' ? { can_view_user: false } : {});
      if (params.action === 'ghca_acd_get_employee_drawer') {
        const action = params.action; delete params.action;
        const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), JSON.stringify(e)]).toString();
        params.action = action;
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_get_edit_records_form') {
        if (state.slowEdit) { await new Promise((r) => setTimeout(r, state.slowEdit)); }
        delete params.action; delete params.nonce;
        const out = execFileSync('php', [preview, 'edit', JSON.stringify(params), JSON.stringify(e)]).toString();
        return route.fulfill({ contentType: 'application/json', body: out });
      }
      if (params.action === 'ghca_acd_agency_training') {
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { html: '<p>Synthetic.</p>', message: 'ok' } }) });
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'not in this test' } }) });
    }
    if (url.pathname === '/drawer.html') { return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) { return route.fulfill({ contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) }); }
    }
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<p>stub</p>' });
  });
  await page.goto('https://preview.test/drawer.html#ghca-tab-employees');
  await page.waitForSelector('#open-drawer');
  // A second opener for an employee the server will refuse.
  await page.evaluate(() => { const a = document.querySelector('#open-drawer'); const b = a.cloneNode(true); b.id = 'open-43'; b.setAttribute('data-ghca-employee-drawer', '43'); b.textContent = 'Open employee 43'; a.after(b); });
  return { context, page, errors, state };
}
const drawerOpen = (page, sel) => page.click(sel).then(() => page.waitForSelector('.gh-drawer--emp .gh-drh-tabs, .gh-drawer--emp .gh-dr-error', { state: 'visible' }));
const close = (page) => page.click('.gh-drawer-x');
const bodyText = (page) => page.locator('#ghca-acd-employee-drawer-body').innerText();

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // ---- Permitted administrator.
  for (const width of [1440, 834, 390]) {
    const { context, page, errors, state } = await open(browser, width, { can_admin: true });
    await drawerOpen(page, '#open-drawer');
    await page.click('#ghca-acd-drawer-tab-administration');
    const t = await bodyText(page);
    assert.match(t, /Administrative Overview/);
    assert.match(t, /Confidential: shown only to people with permission to edit employee records\./);
    assert.doesNotMatch(t, /Restricted to authorized personnel|restricted to authorized personnel/);
    assert.match(t, /Employee Training Record[\s\S]*Compliance Review[\s\S]*Employment status[\s\S]*System meta/i);
    const w = await page.locator('.gh-drawer--emp').evaluate((e) => Math.round(e.getBoundingClientRect().width));
    assert.equal(w, Math.min(860, width));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    if (shots) { await page.screenshot({ path: path.join(shots, 'administration-' + width + '.png'), fullPage: false }); }
    ok('@' + width + ': administrator sees Administration with its data and the clarified confidentiality note; drawer ' + w + 'px; no sideways scroll');

    if (width === 1440) {
      // Reopen: the same content, the same tab.
      const first = await bodyText(page);
      await close(page);
      await drawerOpen(page, '#open-drawer');
      assert.equal(await page.getAttribute('#ghca-acd-drawer-tab-administration', 'aria-selected'), 'true');
      assert.equal(await bodyText(page), first);
      ok('reopening the same employee shows the same Administration content');

      // Switch to an employee the server refuses: nothing of the previous employee remains.
      await close(page);
      await drawerOpen(page, '#open-43');
      const refused = await bodyText(page);
      assert.match(refused, /Invalid employee or permission denied\./);
      assert.doesNotMatch(refused, /Administrative Overview|Jordan Ellis|Verified start/);
      assert.equal(await page.locator('.gh-drawer--emp').evaluate((e) => e.classList.contains('is-admin')), false);
      ok('switching to an out-of-scope employee shows the server\'s refusal; none of the previous employee\'s records stay on screen');
      await page.click('[data-ghca-drawer-retry]');
      await page.waitForSelector('.gh-dr-error', { state: 'visible' });
      await close(page);
      await drawerOpen(page, '#open-drawer');
      assert.match(await bodyText(page), /Administrative Overview/);
      ok('back to the permitted employee: Administration again, unchanged');

      // A slow Edit Records load that completes after the drawer moved on.
      state.slowEdit = 900;
      await page.click('.app-adm-card [data-ghca-edit-records]');
      await page.waitForSelector('[data-ghca-admin-view="subpage"]:not([hidden])');
      await page.keyboard.press('Escape');
      await page.keyboard.press('Escape');
      await drawerOpen(page, '#open-43');
      await page.waitForTimeout(1300);
      assert.equal(await page.locator('[data-ghca-edit-form]').count(), 0);
      assert.doesNotMatch(await bodyText(page), /Jordan Ellis/);
      assert.equal(await page.locator('.gh-drawer--emp').evaluate((e) => e.classList.contains('is-subpage')), false);
      ok('a slow Edit Records load for one employee never lands in the next drawer (cancelled when the drawer reopens)');
      state.slowEdit = 0;
    }
    assert.deepEqual(errors, []);
    await context.close();
  }

  // ---- Denied user: no Administration tab, no records, on open and reopen.
  {
    const { context, page, errors } = await open(browser, 1440, { can_admin: false });
    for (let i = 0; i < 2; i++) {
      await drawerOpen(page, '#open-drawer');
      assert.equal(await page.locator('#ghca-acd-drawer-tab-administration').count(), 0);
      const t = await bodyText(page);
      assert.doesNotMatch(t, /Administrative Overview|Verified start|Employment type|Registration date|restricted to authorized personnel/i);
      await close(page);
    }
    const forced = await page.evaluate(() => fetch(window.ghcaAcd.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ghca_acd_get_edit_records_form&nonce=test&user_id=42&ghca_ui=board' }).then((r) => r.json()));
    assert.equal(forced.success, false);
    assert.equal(forced.data.message, 'Permission denied.');
    ok('denied user: no Administration tab or records on open and reopen; a forced Edit Records request is refused by the server');
    assert.deepEqual(errors, []);
    await context.close();
  }

  // ---- Expired session.
  {
    const { context, page } = await open(browser, 1440, { can_admin: true }, { session: true });
    await page.click('#open-drawer');
    await page.waitForSelector('.gh-dr-error', { state: 'visible' });
    assert.match(await bodyText(page), /Your session has expired or this page is out of date\. Reload the page and try again\./);
    assert.doesNotMatch(await bodyText(page), /Network error|Administrative Overview/);
    ok('an expired nonce or session says so, instead of "Network error"; no records shown');
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' drawer access browser checks (synthetic)\n');
})().catch((e) => { console.error(e); process.exit(1); });
