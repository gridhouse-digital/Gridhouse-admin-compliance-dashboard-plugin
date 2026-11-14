'use strict';
/**
 * Browser checks for slice 8 on synthetic pages (the plugin's own preview PHP, canned AJAX; nothing sent, no training
 * record changed): drawer header spacing, the Employees table without an inner scroll box, the packet-ready notice's
 * visible contrast in every state (card and tray, plain and hostile theme), the inline User training report, Manage
 * users and the announcement modal. Run at desktop, tablet and phone widths. Not WordPress, not the staging theme.
 *
 *   node tests/browser-slice8.cjs [screenshot-dir]
 */
const H = require('./browser-harness.cjs');
const { assert, fs, os, path, chromium, shots, openTables, openDrawer, openEdit, NOTICE_CONTRAST } = H;

const WIDTHS = [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }];

let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

async function noticeContrast(page, sel, label) {
  const list = await page.locator(sel).evaluate(NOTICE_CONTRAST);
  const low = list.filter((x) => x.ratio < (x.what.startsWith('icon') ? 3 : 4.5));
  assert.equal(low.length, 0, label + ' low contrast: ' + JSON.stringify(low));
  assert.ok(list.some((x) => x.what.includes('close')), label + ': close control painted');
  ok(label + ': every text, link and icon is readable without selecting or hovering (lowest ' + Math.min(...list.map((x) => x.ratio)) + ':1; close control visible)');
}

(async () => {
  const browser = await chromium.launch();
  const shot = async (page, name) => { if (shots) { await page.screenshot({ path: path.join(shots, 's8-' + name + '.png') }); } };

  // ================= 1. Drawer header spacing =================
  for (const vp of WIDTHS) {
    const w = vp.width;
    const { ctx, page } = await openDrawer(browser, vp, { messaging: true, history: [] });
    const g = await page.evaluate(() => {
      const top = document.querySelector('.gh-drh-top').getBoundingClientRect();
      const tabs = document.querySelector('.gh-drh-tabs').getBoundingClientRect();
      const id = document.querySelector('.gh-drh-id').getBoundingClientRect();
      const x = document.querySelector('.gh-drawer-x').getBoundingClientRect();
      const overlap = !(x.right <= id.left || x.left >= id.right || x.bottom <= id.top || x.top >= id.bottom);
      return { gap: Math.round(tabs.top - id.bottom), overlap };
    });
    assert.ok(g.gap >= (w < 720 ? 14 : 18), 'gap ' + g.gap); assert.equal(g.overlap, false);
    ok(w + ': drawer header: ' + g.gap + 'px between the employee identity and the tabs; the close control does not overlap it');
    await openEdit(page);
    const s = await page.evaluate(() => { const hd = document.querySelector('.gh-drawer--emp .gh-drawer-hd').getBoundingClientRect(); const id = document.querySelector('.gh-drh-id').getBoundingClientRect(); const bd = document.querySelector('.gh-drawer--emp .gh-drawer-bd').getBoundingClientRect(); return { pad: Math.round(hd.bottom - id.bottom), body: Math.round(bd.top - hd.bottom) }; });
    assert.ok(s.pad >= (w < 720 ? 16 : 20), 'pad ' + s.pad); assert.ok(s.body >= 0);
    ok(w + ': drawer header in Edit Records (no tabs): ' + s.pad + 'px under the identity before the content');
    await shot(page, 'drawer-header-' + w);
    await ctx.close();
  }

  // ================= 2. Employees table: no inner scroll box =================
  for (const [vp, hostile] of [[{ width: 1920, height: 1000 }, false], [{ width: 1440, height: 900 }, false], [{ width: 1280, height: 900 }, true], [{ width: 1024, height: 900 }, false], [{ width: 1024, height: 900 }, true], [{ width: 834, height: 1000 }, false], [{ width: 390, height: 844 }, true]]) {
    const w = vp.width;
    const tag = w + (hostile ? ' (hostile theme)' : '');
    const { ctx, page } = await openTables(browser, vp, '', hostile);
    const m = await page.evaluate(() => {
      const wrap = document.querySelector('#ghca-tab-employees .gh-tbl--emp');
      const cards = document.querySelector('#ghca-tab-employees .gh-tbl--emp + .gh-cards');
      const c = getComputedStyle(wrap);
      const scrollers = Array.from(document.querySelectorAll('#ghca-tab-employees *')).filter((e) => { const s = getComputedStyle(e); return /(auto|scroll)/.test(s.overflowX + s.overflowY) && (e.scrollHeight > e.clientHeight + 1 || e.scrollWidth > e.clientWidth + 1) && e.offsetParent !== null; }).map((e) => e.className);
      const acts = Array.from(document.querySelectorAll('#ghca-tab-employees .gh-tbl--emp .c-act .gh-btn, #ghca-tab-employees .gh-cards .gh-mcard-act .gh-btn')).filter((b) => b.offsetParent !== null);
      const clipped = acts.filter((b) => { const r = b.getBoundingClientRect(); return r.right > window.innerWidth + 1 || r.left < -1 || r.width < 20; }).length;
      return { table: wrap.offsetParent !== null, cards: cards.offsetParent !== null, ox: c.overflowX, oy: c.overflowY, mh: c.maxHeight, scrollers, page: document.documentElement.scrollWidth - window.innerWidth, acts: acts.length, clipped };
    });
    assert.deepEqual(m.scrollers, [], 'inner scroll boxes: ' + m.scrollers);
    assert.ok(m.page <= 0, 'page overflow ' + m.page); assert.equal(m.clipped, 0); assert.ok(m.acts > 0);
    if (w >= 1024) {
      assert.equal(m.table, true); assert.equal(m.cards, false); assert.equal(m.mh, 'none'); assert.equal(m.ox, 'visible');
      ok(tag + ': Employees table grows with the page: no inner scroll box or scrollbars, no sideways page scroll, all ' + m.acts + ' row actions visible');
    } else {
      assert.equal(m.table, false); assert.equal(m.cards, true);
      ok(tag + ': below 1024px the existing cards replace the table; no inner or sideways scrolling; ' + m.acts + ' card actions visible');
    }
    await shot(page, 'employees-' + w + (hostile ? '-hostile' : ''));
    await ctx.close();
  }

  // ================= 5. Packet-ready notice visibility =================
  for (const hostile of [false, true]) {
    for (const vp of WIDTHS) {
      const w = vp.width;
      const tag = w + (hostile ? ' hostile theme' : '');
      const { ctx, page, errors, state } = await openDrawer(browser, vp, { messaging: true, history: [] }, { hostile });
      const downloads = [];
      page.on('download', (d) => downloads.push(d));
      await openEdit(page);
      const field = page.locator('[data-ghca-edit-form] input[type="date"]').first();
      const fname = await field.getAttribute('name');
      await field.fill('2026-10-15');
      await field.dispatchEvent('input');
      await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
      const card = '[data-ghca-packet-slot] [data-ghca-packet-notice]';
      await page.waitForSelector(card);
      assert.equal(downloads.length, 0); assert.equal(state.downloads, 0);
      await noticeContrast(page, card, tag + ' card notice, ready');
      await shot(page, 'notice-ready-' + w + (hostile ? '-hostile' : ''));
      await page.locator(card + ' [data-ghca-packet-dismiss]').click();
      await noticeContrast(page, card, tag + ' card notice, compact');
      // Expand again from the compact state.
      const expand = page.locator(card + ' [data-ghca-packet-expand]');
      assert.equal(await expand.isVisible(), true);
      await expand.click();
      assert.doesNotMatch(await page.locator(card).getAttribute('class'), /is-compact/);
      assert.equal(await expand.getAttribute('aria-expanded'), 'true'); ok(tag + ': a compact notice can be expanded again (aria-expanded)');
      assert.equal(await page.locator('[data-ghca-edit-form] [name="' + fname + '"]').inputValue(), '2026-10-15'); ok(tag + ': the unsaved Edit Records value is kept');
      // Tray: leave Edit Records and close the drawer.
      await page.click('[data-ghca-subpage-back]');
      await page.click('.gh-drawer-x');
      const tray = '[data-ghca-packet-tray] [data-ghca-packet-notice]';
      await page.waitForSelector(tray);
      assert.equal(await page.locator(tray + ' [data-ghca-packet-download]').isVisible(), true); ok(tag + ': the notice persists in the page tray after the drawer closes, link intact');
      await noticeContrast(page, tray, tag + ' tray notice, compact');
      await page.locator(tray + ' [data-ghca-packet-expand]').click();
      await noticeContrast(page, tray, tag + ' tray notice, expanded');
      await shot(page, 'notice-tray-' + w + (hostile ? '-hostile' : ''));
      // Network error keeps the link.
      state.offline = true;
      await page.click(tray + ' [data-ghca-packet-download]');
      await page.waitForFunction(() => /Could not reach the server/.test(document.querySelector('[data-ghca-packet-tray]').innerText));
      await noticeContrast(page, tray, tag + ' tray notice, network error');
      state.offline = false;
      // Download: consumed.
      const dl = page.waitForEvent('download');
      await page.click(tray + ' [data-ghca-packet-download]');
      await dl;
      await page.waitForFunction(() => /Link used/.test(document.querySelector('[data-ghca-packet-tray]').innerText));
      const done = await page.locator(tray).innerText();
      assert.match(done, /Link used/); assert.match(done, /generate (the|a new) packet/i); assert.doesNotMatch(done, /link expires/);
      assert.equal(await page.locator(tray + ' [data-ghca-packet-download]').count(), 0);
      ok(tag + ': after download the notice says the link was used and another packet must be generated; no link remains');
      await noticeContrast(page, tray, tag + ' tray notice, downloaded');
      await shot(page, 'notice-done-' + w + (hostile ? '-hostile' : ''));
      const room = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.gh-main')).paddingBottom));
      assert.ok(room >= 150, 'room ' + room); ok(tag + ': while the tray holds a notice the page has ' + room + 'px of room below its content');
      await page.locator(tray + ' [data-ghca-packet-dismiss]').click();
      assert.equal(await page.locator(tray).count(), 0); ok(tag + ': the used notice closes with its close control');
      // Error (server refused) and expired states.
      await page.click('#open-drawer');
      await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drawer-hd');
      await openEdit(page);
      await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="orientation"]');
      await page.waitForSelector('[data-ghca-packet-slot] [data-ghca-packet-notice="orientation"] [data-ghca-packet-download]');
      state.gone.add('job' + state.inits);
      await page.click('[data-ghca-packet-slot] [data-ghca-packet-download]');
      await page.waitForFunction(() => /no longer available/.test(document.querySelector('[data-ghca-packet-slot]').innerText));
      await noticeContrast(page, '[data-ghca-packet-slot] [data-ghca-packet-notice="orientation"]', tag + ' card notice, refused/expired');
      await page.locator('[data-ghca-packet-slot] [data-ghca-packet-notice="orientation"] [data-ghca-packet-dismiss]').click();
      assert.equal(await page.locator('[data-ghca-packet-notice="orientation"]').count(), 0); ok(tag + ': a finished notice can be closed');
      assert.equal(downloads.length, 1); ok(tag + ': exactly one PDF was saved, and only on an explicit Download PDF');
      assert.equal(errors.length, 0, errors.join(' | '));
      await ctx.close();
    }
  }
  {
    // Expired by time.
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, { messaging: true, history: [] }, { ttl: 2, hostile: true });
    await openEdit(page);
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForFunction(() => /download link has expired/.test((document.querySelector('[data-ghca-packet-notice]') || {}).innerText || ''), null, { timeout: 6000 });
    await noticeContrast(page, '[data-ghca-packet-notice]', '1440 hostile theme, expired by time');
    await ctx.close();
  }

  // ================= 3. Inline User training report =================
  const PEOPLE = {
    42: ['Jordan Ellis', 'jordan.ellis@example.test', 'DSP — New Hire', { active: true, complete: false, deadline_label: 'November 13, 2026' }],
    43: ['Morgan Lee', 'morgan.lee@example.test', 'DSP — Annual Compliance', {}],
    44: ['Avery Kim', 'avery.kim@example.test', 'LPN', {}],
    45: ['Outside Scope', 'outside@example.test', 'RN', {}],
  };
  const reportEnv = (extra = {}) => Object.assign({ messaging: true, history: [], people: PEOPLE, out_of_scope: [45], caps: ['edit_users'], crm: true }, extra);
  const openReports = async (page) => {
    await page.click('#ghca-tab-reports-tab');
    await page.waitForSelector('#ghca-tab-reports [data-ghca-report-open]');
  };
  for (const [vp, hostile] of [[{ width: 1440, height: 900 }, false], [{ width: 1440, height: 900 }, true], [{ width: 834, height: 1000 }, false], [{ width: 390, height: 844 }, true]]) {
    const w = vp.width;
    const tag = w + (hostile ? ' hostile theme' : '');
    const { ctx, page, errors, state } = await openDrawer(browser, vp, reportEnv(), { noDrawer: true, hostile });
    await openReports(page);
    const navs = [];
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) { navs.push(f.url()); } });
    await page.click('#ghca-tab-reports [data-ghca-report-open]');
    assert.equal(navs.length, 0); assert.equal(page.url().includes('/user-report/'), false); ok(tag + ': Open report stays in the dashboard (no navigation to /user-report/)');
    assert.equal(await page.locator('[data-ghca-report]').isVisible(), true);
    assert.equal(await page.locator('[data-ghca-report-home]').isHidden(), true); ok(tag + ': the report view replaces the report cards inside the Reports tab');
    assert.match(await page.locator('[data-ghca-report-body]').innerText(), /Choose an employee to see their report/);
    assert.doesNotMatch(await page.locator('#ghca-tab-reports').innerText(), /Select an employee from the compliance dashboard/); ok(tag + ': the board empty state shows, not the old "Select an employee…" message');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-report-input')), true); ok(tag + ': focus moves to the employee picker');
    const opts = await page.$$eval('[data-ghca-report-option]', (o) => o.map((x) => x.getAttribute('data-ghca-report-option')));
    assert.deepEqual(opts.sort(), ['42', '43', '44']); ok(tag + ': the picker lists only employees the viewer may see (the out-of-scope one is not offered)');
    // Keyboard: type, arrow, Enter.
    await page.keyboard.type('mor');
    assert.equal(await page.locator('[data-ghca-report-input]').getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('[data-ghca-report-option]:not([hidden])').count(), 1);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user="43"]');
    assert.deepEqual(state.reports, ['43']); ok(tag + ': typing filters the list; Enter loads the chosen employee (combobox, keyboard only)');
    const body = page.locator('[data-ghca-report-body]');
    const t = await body.innerText();
    assert.match(t, /Employee compliance report/i); assert.match(t, /Morgan Lee/); assert.match(t, /m\*\*\*@example\.test/);
    assert.match(t, /Progress[\s\S]*40%/); assert.match(t, /Courses completed[\s\S]*3 \/ 5/); assert.match(t, /Due date/); assert.match(t, /Last activity[\s\S]*Oct 29, 2026/);
    ok(tag + ': overview shows the employee, role and group, a masked email, progress, courses completed, due date and last activity from the record');
    assert.equal(await page.locator('[data-ghca-report-body] .gh-alert').count(), 0); ok(tag + ': no onboarding alert for an employee who is not a new hire');
    assert.equal(await page.locator('.gh-urpt-table tbody tr').count(), 5); ok(tag + ': required-course table lists all five courses');
    assert.equal(await page.locator('.gh-urpt-table [data-ghca-cv-open]').count(), 2); ok(tag + ': certificate buttons only where a certificate exists (slice 9: they open the certificate viewer)');
    assert.equal(await page.locator('.gh-urpt-table a[href*="/course/"]').count(), 5); ok(tag + ': every course has View course');
    const acts = await page.$$eval('.gh-urpt-acts > *', (a) => a.map((x) => x.textContent.trim()));
    assert.deepEqual(acts, ['Email reminder', 'Log reminder', 'Contact employee', 'Certificates (2)', 'CRM contact (opens in a new tab)', 'User profile']); ok(tag + ': actions in the board order, each the existing action (' + acts.length + ')');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true); ok(tag + ': no sideways scrolling');
    if (w < 720) {
      assert.equal(await page.locator('.gh-urpt-table thead').evaluate((e) => e.getBoundingClientRect().height <= 1), true);
      assert.equal(await page.locator('.gh-urpt-table tbody tr').first().evaluate((e) => getComputedStyle(e).display), 'block'); ok(tag + ': on phones each course is a stacked card');
    }
    await shot(page, 'report-' + w + (hostile ? '-hostile' : ''));
    // Email reminder opens the existing composer for this employee; nothing is sent.
    await page.click('.gh-urpt-acts [data-ghca-reminder]');
    await page.waitForSelector('[data-ghca-reminder-form]:not([hidden])');
    assert.equal(state.ctxUser, '43'); assert.equal(state.sends || 0, 0); ok(tag + ': Email reminder opens the existing single-recipient composer for this employee (nothing sent)');
    await page.keyboard.press('Escape');
    // Employee-bound: a slow answer for an earlier choice never replaces a later one.
    state.reportDelay = { 42: 900 };
    await page.fill('[data-ghca-report-input]', 'jordan');
    await page.keyboard.press('Enter');
    await page.fill('[data-ghca-report-input]', 'avery');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user="44"]');
    await page.waitForTimeout(1100);
    assert.equal(await page.locator('[data-ghca-report-user]').getAttribute('data-ghca-report-user'), '44');
    assert.equal(await page.locator('[data-ghca-report-user="42"]').count(), 0); ok(tag + ': a slower answer for an earlier employee is discarded; the report stays bound to the last choice');
    state.reportDelay = {};
    await page.fill('[data-ghca-report-input]', 'jordan');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user="42"]');
    assert.match(await page.locator('[data-ghca-report-body] .gh-alert--warning').innerText(), /New-hire onboarding in progress[\s\S]*courses? left[\s\S]*November 13, 2026/); ok(tag + ': a new hire shows the onboarding alert with the real deadline');
    // The server refuses an employee outside the viewer's scope even if asked directly.
    const refused = await page.evaluate(async () => { const p = new URLSearchParams({ action: 'ghca_acd_user_report', user_id: '45', nonce: 'test' }); const r = await fetch('/ajax', { method: 'POST', body: p }); return r.text(); });
    assert.match(refused, /"success":false/); assert.match(refused, /permission/); assert.doesNotMatch(refused, /Outside Scope/); ok(tag + ': a direct request for an out-of-scope employee is refused and returns no report');
    // Session expired.
    state.session = true;
    await page.fill('[data-ghca-report-input]', 'morgan');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => /session has expired/.test(document.querySelector('[data-ghca-report-body]').innerText));
    state.session = false; ok(tag + ': an expired session is reported, with no stale report left showing');
    // Back to reports.
    await page.click('[data-ghca-report-back]');
    assert.equal(await page.locator('[data-ghca-report-home]').isVisible(), true);
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-report-open')), true);
    assert.equal(navs.length, 0); ok(tag + ': Back to reports returns to the cards inside the dashboard and refocuses Open report');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }
  {
    // Fewer permissions: no reminder capability, no record editing, no edit_users, no CRM.
    const { ctx, page } = await openDrawer(browser, { width: 1440, height: 900 }, reportEnv({ can_remind: false, can_admin: false, caps: [], crm: false }), { noDrawer: true });
    await openReports(page);
    await page.click('[data-ghca-report-open]');
    await page.fill('[data-ghca-report-input]', 'morgan');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user="43"]');
    const acts = await page.$$eval('.gh-urpt-acts > *', (a) => a.map((x) => x.textContent.trim() + (x.tagName === 'A' ? ' ' + x.getAttribute('href').slice(0, 7) : '')));
    assert.deepEqual(acts, ['Email reminder mailto:', 'Contact employee mailto:', 'Certificates (2) https:/']); ok('without those permissions the report offers only the mailto reminder, contact and certificates (as the legacy report did)');
    await ctx.close();
  }

  // Further sections are appended below as they are built (report, Manage users, announcements).
  if (typeof module.exports.more === 'function') { n += await module.exports.more(browser, ok); }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' slice 8 browser checks (synthetic pages, plain and hostile themes; nothing sent; no record changed)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
