'use strict';
/**
 * Slice 9 checks on the disposable real WordPress site with a real theme (not the staging theme; LearnDash replaced
 * by test stand-ins, so there are no real certificates): container width, Overview column proportions and
 * stacking, long announcement wrapping, Certificates routing and the employee filter, and the report picker.
 * Synthetic people only; nothing is sent. An announcement created here is deleted again.
 *
 *   GHCA_REAL_SITE=http://127.0.0.1:8099 GHCA_REAL_THEME="Twenty Twenty-Four" node tests/browser-real-site9.cjs [shots]
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const SITE = process.env.GHCA_REAL_SITE || '';
const THEME = process.env.GHCA_REAL_THEME || 'the active theme';
const shots = process.argv[2] || '';
if (!SITE) { process.stdout.write('SKIP: set GHCA_REAL_SITE to a disposable site\n'); process.exit(0); }
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

async function login(browser, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: shots ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
  await page.goto(SITE + '/wp-login.php');
  await page.fill('#user_login', 'synthetic-admin');
  await page.fill('#user_pass', 'synthetic-pass');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return { ctx, page, errors };
}
const dash = (page, q = '', hash = '') => page.goto(SITE + '/?pagename=compliance-dashboard' + q + hash).then(() => page.waitForSelector('.ghca-ui .gh-tabs'));
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: path.join(shots, 'real9-' + name + '.png'), fullPage: false }); } };
const cols = (page) => page.evaluate(() => {
  const grid = document.querySelector('.gh-ovp--3');
  return Array.from(grid.children).map((c) => { const r = c.getBoundingClientRect(); return { w: Math.round(r.width), x: Math.round(r.left), y: Math.round(r.top) }; });
});

(async () => {
  const browser = await chromium.launch();

  // ---------- Container width ----------
  {
    const { ctx, page } = await login(browser, { width: 1920, height: 1000 });
    await dash(page);
    const w = await page.evaluate(() => Math.round(document.querySelector('.ghca-ui .gh-main').getBoundingClientRect().width));
    assert.ok(w <= 1480 && w > 1320, 'container ' + w); ok(THEME + ' 1920: the dashboard is ' + w + 'px wide (was up to 1600px; the board is 1320px)');
    await ctx.close();
  }

  // ---------- Overview columns, long announcement ----------
  // Long, but within the 160-character title limit; the message carries the very long unbroken text.
  const LONG = 'Synthetic: Annual audit window opens Monday — finish every assigned course and upload outside certificates before the auditors arrive';
  {
    const { ctx, page, errors } = await login(browser, { width: 1440, height: 900 });
    await dash(page);
    await page.click('[data-ghca-announce-add]');
    await page.fill('#ghca-an-title', LONG);
    await page.fill('#ghca-an-body', 'Antidisestablishmentarianism-style-very-long-unbroken-words-should-still-wrap-inside-the-card-without-pushing-it-wider. ' + 'More text. '.repeat(10));
    await page.click('[data-ghca-announce-save]');
    await page.waitForSelector('[data-announce-title="' + LONG + '"]');
    for (const w of [1440, 1280]) {
      await page.setViewportSize({ width: w, height: 900 });
      const c = await cols(page);
      assert.equal(c.length, 3);
      assert.ok(c[0].w >= 280 && c[0].w <= 320, 'left ' + c[0].w);
      assert.ok(c[1].w < c[2].w, 'middle ' + c[1].w + ' vs announcements ' + c[2].w);
      assert.ok(c[1].w >= 360, 'middle min ' + c[1].w);
      assert.ok(c[0].y === c[1].y && c[1].y === c[2].y);
      ok(THEME + ' ' + w + ': Overview columns ' + c.map((x) => x.w).join(' / ') + ' px: left unchanged (280–320), Compliance by group narrower than Announcements');
      const wrap = await page.evaluate((t) => { const card = document.querySelector('[data-announce-title="' + t + '"]').closest('.gh-card'); const cr = card.getBoundingClientRect(); return { over: Array.from(card.querySelectorAll('*')).filter((e) => e.getBoundingClientRect().right > cr.right + 1).length, page: document.documentElement.scrollWidth > window.innerWidth }; }, LONG);
      assert.equal(wrap.over, 0); assert.equal(wrap.page, false); ok(THEME + ' ' + w + ': a long announcement wraps inside its card; nothing overflows');
      const acts = await page.evaluate((t) => { const b = document.querySelector('[data-announce-title="' + t + '"] [data-ghca-ann-menu-btn]').getBoundingClientRect(); return b.width >= 24 && b.right <= window.innerWidth; }, LONG);
      assert.equal(acts, true); ok(THEME + ' ' + w + ': its ⋯ control stays visible and usable');
      await shot(page, 'overview-' + w);
    }
    for (const [w, layout] of [[1100, 'two'], [834, 'one'], [390, 'one']]) {
      await page.setViewportSize({ width: w, height: 900 });
      const c = await cols(page);
      if (layout === 'two') {
        assert.ok(c[0].y === c[1].y && c[2].y > c[0].y && Math.abs(c[2].x - c[0].x) <= 1); ok(THEME + ' ' + w + ': two columns, Announcements full width below (board stacking)');
      } else {
        assert.ok(c[1].y > c[0].y && c[2].y > c[1].y && c.every((x) => Math.abs(x.x - c[0].x) <= 1)); ok(THEME + ' ' + w + ': one column, stacked');
      }
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true); ok(THEME + ' ' + w + ': no sideways scrolling');
      if (w === 390) { await shot(page, 'overview-390'); }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    // Remove the synthetic announcement again.
    await page.click('[data-announce-title="' + LONG + '"] [data-ghca-ann-menu-btn]');
    await page.click('[data-announce-title="' + LONG + '"] [data-ghca-announce-delete]');
    await page.click('[data-ghca-announce-confirm-delete]');
    await page.waitForFunction((t) => !document.querySelector('[data-announce-title="' + t + '"]'), LONG);
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ---------- Certificates routing and the employee filter ----------
  {
    const { ctx, page, errors } = await login(browser, { width: 1440, height: 900 });
    const navs = [];
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) { navs.push(f.url()); } });
    await dash(page);
    navs.length = 0;
    await page.click('#ghca-tab-reports-tab');
    const card = page.locator('#ghca-tab-reports .gh-rpt-card', { hasText: 'Certificate records' }).locator('a.gh-btn');
    assert.doesNotMatch(await card.getAttribute('href'), /cert-download/);
    await card.click();
    await page.waitForSelector('#ghca-tab-certificates.is-active');
    assert.ok(navs.every((u) => !/cert-download/.test(u))); assert.match(page.url(), /#ghca-tab-certificates$/);
    ok(THEME + ': Reports → View certificates opens the dashboard Certificates tab (no /cert-download/)');
    // Employee-specific: the report's Certificates (N).
    await page.click('#ghca-tab-reports-tab');
    await page.click('#ghca-tab-reports [data-ghca-report-open]');
    await page.fill('[data-ghca-report-input]', 'jordan');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user]');
    const jordan = await page.getAttribute('[data-ghca-report-user]', 'data-ghca-report-user');
    await page.click('.gh-urpt-acts a[data-ghca-cert-user]');
    await page.waitForSelector('#ghca-tab-certificates.is-active');
    await page.waitForFunction(() => /Employee: Jordan Ellis/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    assert.equal(await page.inputValue('[data-ghca-cert-user-field]'), jordan);
    assert.ok(navs.every((u) => !/cert-download|user-report/.test(u))); ok(THEME + ': the report\'s Certificates action opens the same tab filtered to that employee (chip "Employee: Jordan Ellis")');
    await shot(page, 'certs-employee');
    // A general entry point clears it.
    await page.click('#ghca-tab-overview-tab');
    await page.click('.gh-ovp a[data-ghca-tab-jump="ghca-tab-certificates"]');
    await page.waitForSelector('#ghca-tab-certificates.is-active');
    await page.waitForFunction(() => !/Employee:/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    assert.equal(await page.inputValue('[data-ghca-cert-user-field]'), ''); ok(THEME + ': the Overview "View all" entry shows the full list again (no stale employee filter)');
    // The chip removes the filter too.
    await page.click('#ghca-tab-reports-tab');
    await page.click('.gh-urpt-acts a[data-ghca-cert-user]');
    await page.waitForFunction(() => /Employee: Jordan Ellis/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    await page.click('[data-ghca-chips="certificates"] [data-ghca-filter-clear="ghca_cert_user"]');
    await page.waitForFunction(() => !/Employee:/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    assert.equal(await page.inputValue('[data-ghca-cert-user-field]'), ''); ok(THEME + ': removing the employee chip clears the filter');
    // A direct link (no script involved) keeps the employee filter on first load.
    await dash(page, '&ghca_cert_user=' + jordan, '#ghca-tab-certificates');
    assert.match(await page.locator('[data-ghca-chips="certificates"]').innerText(), /Employee: Jordan Ellis/); ok(THEME + ': a direct employee link renders the tab already filtered');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ---------- Report picker in the real theme ----------
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    const { ctx, page } = await login(browser, vp);
    await dash(page);
    await page.click('#ghca-tab-reports-tab');
    await page.click('#ghca-tab-reports [data-ghca-report-open]');
    const g = await page.evaluate(() => {
      const wrap = document.querySelector('[data-ghca-report-picker] .gh-input-wrap');
      const ic = Array.from(wrap.querySelectorAll(':scope > .gh-i')).map((i) => i.getBoundingClientRect());
      const inp = wrap.querySelector('input').getBoundingClientRect();
      return { gap: Math.round(ic[1].left - ic[0].right), inside: ic.every((i) => i.left >= inp.left && i.right <= inp.right) };
    });
    assert.ok(g.gap > 40 && g.inside); ok(THEME + ' ' + vp.width + ': report picker icons are separate (' + g.gap + 'px apart) and inside the field');
    await shot(page, 'picker-' + vp.width);
    await ctx.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' slice 9 real-site checks (' + THEME + ', WordPress + MariaDB; synthetic people; nothing sent)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
