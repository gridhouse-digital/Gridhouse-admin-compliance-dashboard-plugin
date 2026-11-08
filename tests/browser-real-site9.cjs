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
// Optional: the disposable site's WordPress folder, used to give one synthetic group a long name for the wrapping
// checks (restored afterwards). Without it those checks are skipped and say so.
const WPROOT = process.env.GHCA_REAL_WP || '';
const { execFileSync } = require('node:child_process');
const LONG_GROUP = 'Direct Support Professionals — Residential Overnight Shift, Lancaster County Community Homes (New Hire Orientation Cohort)';
// Renames the first tracked synthetic group; returns its previous name.
const renameGroup = (title) => execFileSync('php', ['-r', 'define("WP_USE_THEMES", false); $_SERVER["HTTP_HOST"]="localhost"; require getenv("WPR") . "/wp-load.php"; if (!defined("GHCA_DISPOSABLE_TEST_SITE")) { exit(1); } $g = (array) get_option("ghca_acd_tracked_groups"); $id = (int) reset($g); $old = get_the_title($id); wp_update_post(array("ID" => $id, "post_title" => getenv("TITLE"))); echo $old;'], { env: { ...process.env, WPR: WPROOT, TITLE: title } }).toString();
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
// The reference board's Overview grid: minmax(280px, 320px) minmax(0, 1fr) minmax(300px, 360px).
const refCols = (c) => c.length === 3 && c[0].w >= 280 && c[0].w <= 320 && c[2].w >= 300 && c[2].w <= 360 && c[1].w > c[2].w;
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

  // ---------- Overview: list caps (6 groups, 3 announcements), fixed headings, themed scrollbars; KPI height ----------
  if (WPROOT) {
    const fixture = (mode) => execFileSync('php', [path.join(__dirname, 'real-site', 'overview-overflow.php'), WPROOT, mode]).toString();
    fixture('add');
    try {
      const { ctx, page, errors } = await login(browser, { width: 1440, height: 900 });
      await dash(page);
      for (const w of [1920, 1440, 1280, 1100, 834, 390]) {
        await page.setViewportSize({ width: w, height: 900 });
        await page.waitForTimeout(150);
        const r = await page.evaluate(() => {
          const out = {};
          for (const [key, sel] of [['groups', '.gh-grp > li'], ['ann', '.gh-ann']]) {
            const body = document.querySelector(key === 'groups' ? '.gh-ovp [data-ghca-ovp-items=".gh-grp li"]' : '.gh-ovp [data-ghca-announce-list]');
            const card = body.closest('.gh-card');
            const hd = card.querySelector('.gh-card-hd');
            const items = Array.from(body.querySelectorAll(sel));
            const b = body.getBoundingClientRect();
            const shown = () => items.filter((i) => { const x = i.getBoundingClientRect(); return x.top >= b.top - 1 && x.bottom <= b.bottom + 1; }).length;
            const first = shown();
            const hdTop = hd.getBoundingClientRect().top;
            body.scrollTop = body.scrollHeight;
            const last = items[items.length - 1].getBoundingClientRect();
            const lastIn = last.bottom <= body.getBoundingClientRect().bottom + 1 && last.top >= body.getBoundingClientRect().top - 1;
            const hdSame = Math.abs(hd.getBoundingClientRect().top - hdTop) < 1 && hd.getBoundingClientRect().bottom <= body.getBoundingClientRect().top + 1;
            body.scrollTop = 0;
            const cs = getComputedStyle(body);
            out[key] = { n: items.length, shown: first, scrolls: body.scrollHeight > body.clientHeight + 1, lastIn, hdSame, overflowY: cs.overflowY, sbColor: cs.scrollbarColor, sbWidth: cs.scrollbarWidth, tab: body.getAttribute('tabindex'), role: body.getAttribute('role'), label: body.getAttribute('aria-label') };
          }
          const kpi = document.querySelector('.gh-kpi--overview > .gh-stat');
          out.kpi = { h: Math.round(kpi.getBoundingClientRect().height), pt: getComputedStyle(kpi).paddingTop };
          out.page = document.documentElement.scrollWidth > window.innerWidth;
          return out;
        });
        for (const [key, n, cap, name] of [['groups', 8, 6, 'group rows'], ['ann', 5, 3, 'announcements']]) {
          const x = r[key];
          assert.ok(x.n >= n, key + ' items ' + x.n);
          assert.equal(x.shown, cap, key + ' ' + JSON.stringify(x));
          assert.ok(x.scrolls && x.lastIn && x.hdSame && x.overflowY === 'auto', key + ' ' + JSON.stringify(x));
          assert.ok(x.sbWidth === 'thin' && /color-mix|rgba|\d%/.test(x.sbColor) && x.sbColor !== 'auto', key + ' ' + JSON.stringify(x));
          assert.ok(x.tab === '0' && x.role === 'region' && /scrollable list/.test(x.label), key + ' ' + JSON.stringify(x));
        }
        assert.equal(r.page, false);
        ok(THEME + ' ' + w + ': ' + r.groups.n + ' groups and ' + r.ann.n + ' announcements: exactly 6 group rows and 3 announcements visible; only the list body scrolls (to the last item), the card headings stay fixed; thin semi-transparent theme-coloured scrollbars; keyboard-scrollable region; no sideways page scroll');
        if (w >= 1241) {
          const c = await cols(page);
          assert.ok(refCols(c), JSON.stringify(c)); ok(THEME + ' ' + w + ': columns ' + c.map((x) => x.w).join(' / ') + ' px: the reference proportions');
        }
        if (w >= 720) { assert.equal(r.kpi.pt, '22px'); } else { assert.equal(r.kpi.pt, '12px'); }
        ok(THEME + ' ' + w + ': KPI tiles ' + r.kpi.h + ' px tall (' + (w >= 720 ? 'taller on desktop and tablet' : 'phone keeps its compact tiles') + ')');
        if (shots && [1440, 834, 390].includes(w)) {
          await page.locator('.gh-ovp').screenshot({ path: path.join(shots, 'real9-overview-caps-' + w + '.png') });
          if (w === 1440) { await page.locator('.gh-kpi--overview').screenshot({ path: path.join(shots, 'real9-kpi-1440.png') }); }
        }
      }
      // The ⋯ menu of the third (last visible) announcement is not cut off by the scrolling list.
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.waitForTimeout(150);
      const btn = page.locator('.gh-ovp [data-ghca-announce-list] .gh-ann').nth(2).locator('[data-ghca-ann-menu-btn]');
      await btn.click();
      const m = await page.evaluate(() => { const menu = Array.from(document.querySelectorAll('[data-ghca-ann-menu]')).find((x) => !x.hidden); const b = menu.closest('.gh-ovp-scroll').getBoundingClientRect(); const r = menu.getBoundingClientRect(); return { inside: r.top >= b.top - 1 && r.bottom <= b.bottom + 1, up: menu.classList.contains('gh-ann-menu--up') }; });
      assert.ok(m.inside, JSON.stringify(m)); ok(THEME + ': the ⋯ menu of the last visible announcement opens ' + (m.up ? 'upward' : 'downward') + ' and is fully visible inside the scrolling list');
      await page.keyboard.press('Escape');
      assert.equal(errors.length, 0, errors.join(' | '));
      await ctx.close();
    } finally {
      fixture('remove');
    }
    // With short lists nothing is capped.
    const { ctx, page } = await login(browser, { width: 1440, height: 900 });
    await dash(page);
    const plain = await page.evaluate(() => Array.from(document.querySelectorAll('.gh-ovp [data-ghca-ovp-cap]')).map((b) => ({ s: b.classList.contains('gh-ovp-scroll'), h: b.style.maxHeight, t: b.getAttribute('tabindex') })));
    assert.ok(plain.length === 2 && plain.every((x) => !x.s && !x.h && x.t === null), JSON.stringify(plain)); ok(THEME + ': with 3 groups and few announcements the lists are not capped and not scroll regions');
    await ctx.close();
  } else {
    process.stdout.write('skip - Overview list caps: set GHCA_REAL_WP to the disposable site\'s WordPress folder\n');
  }

  // ---------- Overview: a long group name wraps instead of being cut off ----------
  if (WPROOT) {
    const before = renameGroup(LONG_GROUP);
    try {
      const { ctx, page, errors } = await login(browser, { width: 1440, height: 900 });
      await dash(page);
      for (const [w, layout] of [[1920, 'three'], [1440, 'three'], [1280, 'three'], [1100, 'two'], [834, 'one'], [390, 'one']]) {
        await page.setViewportSize({ width: w, height: 900 });
        const g = await page.evaluate((name) => {
          const a = Array.from(document.querySelectorAll('.gh-ovp .gh-grp-n a')).find((x) => x.textContent.trim() === name);
          if (!a) { return null; }
          const card = a.closest('.gh-card').getBoundingClientRect();
          const r = a.getBoundingClientRect();
          const cs = getComputedStyle(a);
          const li = a.closest('li');
          const parts = Array.from(li.children).map((c) => c.getBoundingClientRect());
          return { lines: Math.round(r.height / parseFloat(cs.lineHeight)), clipped: a.scrollWidth > a.clientWidth + 1, ellipsis: cs.textOverflow, nowrap: cs.whiteSpace, inside: parts.every((p) => p.left >= card.left - 1 && p.right <= card.right + 1), pct: li.querySelector('.gh-grp-pct').getBoundingClientRect().width > 0, page: document.documentElement.scrollWidth > window.innerWidth };
        }, LONG_GROUP);
        assert.ok(g, 'the long group name is on the Overview');
        assert.equal(g.clipped, false); assert.notEqual(g.ellipsis, 'ellipsis'); assert.notEqual(g.nowrap, 'nowrap');
        assert.ok(g.inside && g.pct && !g.page, JSON.stringify(g));
        assert.ok(g.lines >= 2, JSON.stringify(g));
        ok(THEME + ' ' + w + ': the long group name is shown in full, wrapped on ' + g.lines + ' line' + (g.lines === 1 ? '' : 's') + ' (no ellipsis); bar, % and status stay inside the card; no sideways scrolling');
        if (layout === 'three') {
          const c = await cols(page);
          assert.ok(refCols(c), JSON.stringify(c));
          ok(THEME + ' ' + w + ': columns ' + c.map((x) => x.w).join(' / ') + ' px with the long name: the reference proportions hold');
        }
        if (shots && [1440, 1280, 834, 390].includes(w)) { await page.locator('.gh-ovp .gh-card', { has: page.locator('#gh-groups-h') }).screenshot({ path: path.join(shots, 'real9-overview-longgroup-' + w + '.png') }); }
      }
      assert.equal(errors.length, 0, errors.join(' | '));
      await ctx.close();
    } finally {
      renameGroup(before);
    }
  } else {
    process.stdout.write('skip - long group name checks: set GHCA_REAL_WP to the disposable site\'s WordPress folder\n');
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
      assert.ok(refCols(c), JSON.stringify(c));
      assert.ok(c[0].y === c[1].y && c[1].y === c[2].y);
      ok(THEME + ' ' + w + ': Overview columns ' + c.map((x) => x.w).join(' / ') + ' px: the reference proportions (left 280–320, Compliance by group flexible, Announcements 300–360)');
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
    // The Certificates tab itself is a general entry point: clicking it clears the employee filter.
    const filterFor = async () => {
      await page.click('#ghca-tab-reports-tab');
      await page.click('.gh-urpt-acts a[data-ghca-cert-user]');
      await page.waitForFunction(() => /Employee: Jordan Ellis/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    };
    await filterFor();
    await page.click('#ghca-tab-overview-tab');
    await page.click('#ghca-tab-certificates-tab');
    await page.waitForSelector('#ghca-tab-certificates.is-active');
    await page.waitForFunction(() => !/Employee:/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    assert.equal(await page.inputValue('[data-ghca-cert-user-field]'), '');
    ok(THEME + ': clicking the Certificates tab shows the full list again (employee filter cleared)');
    // Clicking it again while it is already showing an employee's certificates also clears it.
    await filterFor();
    await page.click('#ghca-tab-certificates-tab');
    await page.waitForFunction(() => !/Employee:/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    ok(THEME + ': clicking the Certificates tab while it is filtered clears the filter too');
    // Keyboard: arrowing onto the Certificates tab behaves like clicking it.
    await filterFor();
    const prev = await page.evaluate(() => { const b = document.querySelector('#ghca-tab-certificates-tab'); const all = Array.from(b.closest('[role="tablist"]').querySelectorAll('[role="tab"]')); return all[all.indexOf(b) - 1].id; });
    await page.click('#' + prev);
    await page.focus('#' + prev);
    await page.keyboard.press('ArrowRight');
    await page.waitForSelector('#ghca-tab-certificates.is-active');
    await page.waitForFunction(() => !/Employee:/.test(document.querySelector('[data-ghca-chips="certificates"]').innerText));
    assert.equal(await page.inputValue('[data-ghca-cert-user-field]'), ''); ok(THEME + ': choosing the Certificates tab with the arrow keys clears the filter as well');
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
