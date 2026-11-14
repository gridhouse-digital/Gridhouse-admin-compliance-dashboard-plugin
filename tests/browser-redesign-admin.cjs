'use strict';
/**
 * Browser checks for the redesigned External training, Audit data and Reports tabs (phase 2, slice 5) and
 * the Courses certificate column, against synthetic people and submissions. The page is the plugin's own
 * wrapper and renderers (tests/render-admin-preview.php) with the real dashboard.js and jotform.js. The
 * audit save endpoint is answered with canned replies; review forms are checked for what the browser would
 * submit, and never submitted. Not WordPress, not LearnDash, not Jotform, not live data.
 *
 *   node tests/browser-redesign-admin.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const preview = path.join(__dirname, 'render-admin-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const HOST = 'https://example.test';
const AGENCY = 'rgb(47, 93, 58)';

function build(env) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-admin-'));
  execFileSync('php', [preview, 'page', dir, 'gridhouse', env.hostile ? 'hostile' : ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: HOST + '/assets', GHCA_PREVIEW_BRAND: '#2F5D3A', GHCA_PREVIEW_PERMS: env.perms || '', GHCA_PREVIEW_EXT: env.ext || '', GHCA_PREVIEW_AUDIT: env.audit || '', GHCA_PREVIEW_EXT_READY: env.ready || '' } });
  return path.join(dir, 'admin.html');
}

async function open(browser, width, env = {}) {
  const file = build(env);
  const state = { saves: [], reply: 'ok' };
  const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: shots ? 2 : 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await context.route(HOST + '/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const body = route.request().postData() || '';
      const fields = {};
      for (const m of body.matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)\r\n/g)) { fields[m[1]] = m[2]; }
      state.saves.push(fields);
      if (state.reply === 'network') { return route.abort(); }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(state.reply === 'ok' ? { success: true } : { success: false, data: 'Permission denied.' }) });
    }
    if (url.pathname === '/admin.html') { return route.fulfill({ status: 200, contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) {
        return route.fulfill({ status: 200, contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) });
      }
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto(HOST + '/admin.html');
  await page.evaluate(() => document.fonts.ready);
  return { context, page, errors, state };
}
const tab = (page, id) => page.click('[data-ghca-tab-target="' + id + '"]');
const noSideways = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // Layout at desktop, tablet and phone, plus a hostile theme.
  for (const [width, hostile] of [[1440, false], [1023, false], [834, false], [720, false], [390, false], [1440, true], [390, true]]) {
    const { context, page, errors } = await open(browser, width, { hostile });
    const L = '@' + width + (hostile ? ' hostile theme' : '');
    for (const id of ['ghca-tab-external-training', 'ghca-tab-audit', 'ghca-tab-reports', 'ghca-tab-courses']) {
      await tab(page, id);
      assert.ok(await noSideways(page), id + ' sideways ' + L);
      const type = await page.evaluate((sel) => { const h = document.querySelector(sel + ' h2'); const c = getComputedStyle(h); const p = getComputedStyle(document.querySelector(sel + ' .gh-small')); return [c.fontFamily.split(',')[0], c.textTransform, p.fontFamily.split(',')[0]]; }, '#' + id);
      assert.deepEqual(type, ['"Wix Madefor Display"', 'none', '"Wix Madefor Text"'], id + ' type ' + L);
      if (width < 1024) {
        const small = await page.evaluate((sel) => [...document.querySelectorAll(sel + ' :is(.gh-btn, .gh-input, .gh-select, .gh-subtab)')].filter((e) => e.getBoundingClientRect().width > 0 && !e.closest('table:not(.gh-table--audit)') && !e.closest('details:not([open])')).filter((e) => e.getBoundingClientRect().height < 43.5).map((e) => e.className + ' ' + Math.round(e.getBoundingClientRect().height)), '#' + id);
        assert.deepEqual(small, [], id + ' touch targets ' + L);
      }
      if (shots && !hostile) { await page.screenshot({ path: path.join(shots, id.replace('ghca-tab-', '') + '-' + width + '.png'), fullPage: true }); }
    }
    ok(L + ': four tabs, no sideways scroll, Wix Madefor headings and text' + (width < 1024 ? ', controls at least 44px' : ''));
    assert.deepEqual(errors, []);
    await context.close();
  }

  // Audit data: validation, keyboard, saved values, failure states.
  {
    const { context, page, errors, state } = await open(browser, 1440);
    await tab(page, 'ghca-tab-audit');
    const first = page.locator('#ghca-aud-first_service-302');
    await first.focus();
    await page.keyboard.type('13/45/2026');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-aud-worked_alone-302');
    await page.keyboard.press('Tab'); await page.keyboard.press('Space'); // exclude
    await page.keyboard.press('Tab'); await page.keyboard.press('Tab'); await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-audit-save')), true);
    await page.keyboard.press('Enter');
    const row = page.locator('[data-ghca-audit-row="302"]');
    await page.waitForFunction(() => /MM\/DD\/YYYY/.test(document.querySelector('[data-ghca-audit-row="302"] [data-ghca-audit-status]').textContent));
    assert.equal(state.saves.length, 0);
    assert.equal(await first.getAttribute('aria-invalid'), 'true');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-aud-first_service-302');
    ok('audit: an impossible date is refused before sending, marked invalid, focus returned to it; keyboard reaches every control');
    await first.fill('02/30/2026');
    await row.locator('[data-ghca-audit-save]').click();
    assert.equal(state.saves.length, 0);
    ok('audit: 02/30/2026 (not a real day) is refused too; the server would have stored it as empty');
    await first.fill('10/16/2026');
    await row.locator('[data-ghca-audit-save]').click();
    await page.waitForFunction(() => document.querySelector('[data-ghca-audit-row="302"] [data-ghca-audit-status]').textContent === 'Saved');
    assert.deepEqual(state.saves[0], { action: 'ghca_acd_save_audit_dates', ghca_nonce: 'nonce', user_id: '302', first_service: '10/16/2026', worked_alone: '', exclude_user: '1' });
    assert.equal(await first.getAttribute('aria-invalid'), 'false');
    assert.equal(await row.locator('[data-ghca-audit-save]').isEnabled(), true);
    ok('audit: save posts the legacy action, nonce and fields (MM/DD/YYYY, exclusion 1/0); "Saved" announced');
    state.reply = 'denied';
    await row.locator('[data-ghca-audit-save]').click();
    await page.waitForFunction(() => document.querySelector('[data-ghca-audit-row="302"] [data-ghca-audit-status]').textContent === 'Permission denied.');
    state.reply = 'network';
    await row.locator('[data-ghca-audit-save]').click();
    await page.waitForFunction(() => /Could not reach the server/.test(document.querySelector('[data-ghca-audit-row="302"] [data-ghca-audit-status]').textContent));
    assert.equal(await row.locator('[data-ghca-audit-save]').textContent(), 'Save dates for Jordan Ellis');
    ok('audit: server refusal and network failure are reported in the row; the button recovers');
    assert.deepEqual(errors, []);
    await context.close();
  }

  // External training: catalog override, required fields, approve gating, date field, Change disclosure.
  {
    const { context, page, errors } = await open(browser, 1440);
    await tab(page, 'ghca-tab-external-training');
    const card = page.locator('.gh-ext-card').first();
    const warn = card.locator('[data-ghca-catalog-warning]');
    assert.equal(await warn.isVisible(), true);
    await card.locator('select[name="catalog_id"]').selectOption('72');
    assert.equal(await warn.isVisible(), false);
    assert.equal(await card.locator('[data-ghca-override-reason]').evaluate((e) => e.required), false);
    await card.locator('select[name="catalog_id"]').selectOption('71');
    assert.equal(await warn.isVisible(), true);
    assert.equal(await card.locator('[data-ghca-override-reason]').evaluate((e) => e.required), true);
    ok('external: choosing the matched lesson hides the mismatch warning; another lesson shows it and requires a reason (jotform.js, unchanged)');
    const valid = await card.locator('form').evaluate((f) => f.checkValidity());
    assert.equal(valid, false);
    await card.locator('[data-ghca-override-reason]').fill('Same course, renamed.');
    await card.locator('input[name="employee_confirmed"]').check();
    assert.equal(await card.locator('form').evaluate((f) => f.checkValidity()), true);
    const date = card.locator('input[name="completion_date"]');
    assert.equal(await date.inputValue(), '2026-10-20');
    assert.equal(await date.evaluate((e) => getComputedStyle(e).accentColor), AGENCY);
    ok('external: approval needs confirmation and, on a mismatch, a reason; completion date stays ISO and takes the agency accent');
    await page.keyboard.press('Escape');
    const summary = page.locator('.gh-ext-manage > summary').first();
    await summary.focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('.gh-ext-manage').first().evaluate((d) => d.open), true);
    assert.equal(await page.locator('.gh-ext-manage').first().locator('input[name="orientation_reason"]').isVisible(), true);
    ok('external: an approved row\'s Change panel opens from the keyboard and shows the orientation and revoke forms');
    const btn = await page.evaluate(() => { const b = document.querySelector('.gh-ext-act .gh-btn--primary'); const c = getComputedStyle(b); return [c.backgroundColor, c.minHeight]; });
    assert.equal(btn[0], AGENCY);
    ok('external: Approve uses the agency brand colour');
    assert.deepEqual(errors, []);
    await context.close();
  }

  // States: evidence store not ready, nothing pending, no employees, no permissions.
  {
    const { context, page } = await open(browser, 1440, { ready: 'Encryption key is not configured.' });
    await tab(page, 'ghca-tab-external-training');
    assert.match(await page.locator('#ghca-tab-external-training .gh-alert--danger').innerText(), /Approvals are paused[\s\S]*Encryption key/);
    assert.equal(await page.locator('#ghca-tab-external-training button[value="approve"]:enabled').count(), 0);
    ok('external, store not ready: alert shown, every Approve disabled');
    await context.close();
  }
  {
    const { context, page } = await open(browser, 390, { ext: 'empty', audit: 'empty' });
    await tab(page, 'ghca-tab-external-training');
    assert.match(await page.locator('#ghca-tab-external-training').innerText(), /Nothing waiting for review[\s\S]*No approved outside training yet/);
    await tab(page, 'ghca-tab-audit');
    assert.match(await page.locator('#ghca-tab-audit .gh-empty').innerText(), /No employees in your view/);
    assert.ok(await noSideways(page));
    ok('empty states for external training and audit data (phone)');
    await context.close();
  }
  {
    const { context, page } = await open(browser, 1440, { perms: 'none' });
    assert.equal(await page.locator('[data-ghca-tab-target="ghca-tab-external-training"]').count(), 0);
    await tab(page, 'ghca-tab-audit');
    assert.equal(await page.locator('#ghca-tab-audit input, #ghca-tab-audit [data-ghca-audit-save], #ghca-tab-audit [data-ghca-pdf-packet]').count(), 0);
    assert.match(await page.locator('#ghca-tab-audit').innerText(), /needs permission to edit employee records/);
    ok('no review, edit or document permission: no External training tab; audit dates read-only, no packets');
    await context.close();
  }

  // Reports and the Courses certificate column.
  {
    const { context, page, errors } = await open(browser, 1440);
    await tab(page, 'ghca-tab-reports');
    const cards = await page.$$eval('#ghca-tab-reports .gh-rpt-card', (els) => els.map((e) => [e.querySelector('h3').textContent, e.querySelector('a').getAttribute('href'), getComputedStyle(e.querySelector('.gh-rpt-ico')).width]));
    assert.deepEqual(cards.map((c) => c[0]), ['Compliance export', 'User training report', 'Certificate records', 'Manage users']);
    assert.ok(cards.every((c) => c[2] === '40px'));
    assert.match(cards[0][1], /admin-post\.php\?action=ghca_acd_export_csv&_wpnonce=/);
    ok('reports: the four existing destinations, legacy URLs, board icon tiles');
    await tab(page, 'ghca-tab-courses');
    const head = await page.locator('#ghca-tab-courses th.c-cert').innerText();
    const cells = await page.$$eval('#ghca-tab-courses table.gh-table--courses tbody tr', (rows) => rows.map((r) => r.lastElementChild.innerText.trim()));
    assert.equal(head.trim(), 'Issues certificate');
    assert.ok(cells.length > 0 && cells.every((c) => c === 'Yes' || c === 'No'), cells.join(','));
    assert.ok(cells.includes('Yes') && cells.includes('No'));
    ok('courses: certificate column reads Yes or No under "Issues certificate" (' + cells.length + ' rows)');
    assert.deepEqual(errors, []);
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' redesigned admin-tab browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
