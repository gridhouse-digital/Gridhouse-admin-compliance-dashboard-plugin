'use strict';
/**
 * Slice 8 checks on a real, disposable WordPress site with a real theme (not the staging theme): the dashboard,
 * Manage users and announcements are the plugin's real pages, real AJAX handlers and a real database. LearnDash is
 * replaced by tests/real-site/ghca-real-site-stubs.php (synthetic groups, no courses), mail is switched off, and the
 * packet is built by the real TCPDF/FPDI code. Synthetic people only; nothing is sent.
 *
 * Prepare the site (tests/real-site/setup.php), serve it with `php -S 127.0.0.1:8099 -t <wp>`, then:
 *   GHCA_REAL_SITE=http://127.0.0.1:8099 node tests/browser-real-site.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { NOTICE_CONTRAST } = require('./browser-harness.cjs');

const SITE = process.env.GHCA_REAL_SITE || '';
const THEME = process.env.GHCA_REAL_THEME || 'the active theme';
const shots = process.argv[2] || '';
if (!SITE) { process.stdout.write('SKIP: set GHCA_REAL_SITE to a disposable site\n'); process.exit(0); }
if (shots) { fs.mkdirSync(shots, { recursive: true }); }

let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

async function login(browser, viewport, user = 'synthetic-admin', pass = 'synthetic-pass') {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: shots ? 2 : 1, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  // External hosts (theme fonts, avatars) are unreachable from this sandbox; only same-site errors count.
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
  await page.goto(SITE + '/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return { ctx, page, errors };
}
const dash = (page, hash = '') => page.goto(SITE + '/?pagename=compliance-dashboard' + hash).then(() => page.waitForSelector('.ghca-ui .gh-tabs'));
const tab = (page, id) => page.click('#' + id + '-tab');
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: path.join(shots, 'real-' + name + '.png') }); } };

async function noticeContrast(page, sel, label) {
  const list = await page.locator(sel).evaluate(NOTICE_CONTRAST);
  const low = list.filter((x) => x.ratio < (x.what.startsWith('icon') ? 3 : 4.5));
  assert.equal(low.length, 0, label + ' low contrast: ' + JSON.stringify(low));
  assert.ok(list.some((x) => x.what.includes('close')));
  ok(label + ': readable without selecting or hovering (lowest ' + Math.min(...list.map((x) => x.ratio)) + ':1; close visible)');
}

(async () => {
  const browser = await chromium.launch();

  // ---------- Employees table and drawer header, real theme ----------
  for (const vp of [{ width: 1440, height: 900 }, { width: 1024, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors } = await login(browser, vp);
    await dash(page);
    await tab(page, 'ghca-tab-employees');
    await page.waitForSelector('#ghca-tab-employees .gh-tbl--emp', { state: 'attached' });
    const m = await page.evaluate(() => {
      const wrap = document.querySelector('#ghca-tab-employees .gh-tbl--emp');
      const scrollers = Array.from(document.querySelectorAll('#ghca-tab-employees *')).filter((e) => { const s = getComputedStyle(e); return /(auto|scroll)/.test(s.overflowX + s.overflowY) && (e.scrollHeight > e.clientHeight + 1 || e.scrollWidth > e.clientWidth + 1) && e.offsetParent !== null; }).length;
      return { table: wrap.offsetParent !== null, scrollers, over: document.documentElement.scrollWidth - window.innerWidth };
    });
    assert.equal(m.scrollers, 0); assert.ok(m.over <= 0, 'overflow ' + m.over); assert.equal(m.table, w >= 1024);
    ok(THEME + ' ' + w + ': Employees ' + (w >= 1024 ? 'table' : 'cards') + ' with no inner scroll box and no sideways page scroll');
    await page.locator('#ghca-tab-employees [data-ghca-employee-drawer]:visible').first().click();
    await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drh-tabs');
    const gap = await page.evaluate(() => Math.round(document.querySelector('.gh-drh-tabs').getBoundingClientRect().top - document.querySelector('.gh-drh-id').getBoundingClientRect().bottom));
    assert.ok(gap >= (w < 720 ? 14 : 18), 'gap ' + gap); ok(THEME + ' ' + w + ': drawer header leaves ' + gap + 'px under the employee identity');
    await shot(page, 'employees-drawer-' + w);
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ---------- Packet-ready notice with the real packet build, real theme ----------
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors } = await login(browser, vp);
    const downloads = [];
    page.on('download', (d) => downloads.push(d));
    await dash(page);
    await tab(page, 'ghca-tab-employees');
    await page.locator('#ghca-tab-employees [data-ghca-employee-drawer]:visible').first().click();
    await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden]) .gh-drh-tabs');
    await page.click('#ghca-acd-drawer-tab-administration');
    await page.click('[data-ghca-edit-records]');
    await page.waitForSelector('[data-ghca-edit-form]');
    await page.click('[data-ghca-admin-view="subpage"] [data-ghca-pdf-packet][data-tracker="annual"]');
    await page.waitForSelector('[data-ghca-packet-slot] [data-ghca-packet-notice] [data-ghca-packet-download]', { timeout: 60000 });
    assert.equal(downloads.length, 0); ok(THEME + ' ' + w + ': real packet built; nothing downloaded automatically');
    const txt = await page.locator('[data-ghca-packet-notice]').innerText();
    assert.match(txt, /Annual packet ready/); assert.match(txt, /\d+ pages?/); ok(THEME + ' ' + w + ': notice shows the real page count (' + (txt.match(/\d+ pages?/) || [''])[0] + ')');
    await noticeContrast(page, '[data-ghca-packet-slot] [data-ghca-packet-notice]', THEME + ' ' + w + ' card notice');
    await shot(page, 'notice-' + w);
    await page.click('[data-ghca-subpage-back]');
    await page.click('.gh-drawer-x');
    await page.waitForSelector('[data-ghca-packet-tray] [data-ghca-packet-download]');
    await noticeContrast(page, '[data-ghca-packet-tray] [data-ghca-packet-notice]', THEME + ' ' + w + ' tray notice');
    const href = await page.getAttribute('[data-ghca-packet-tray] [data-ghca-packet-download]', 'href');
    assert.match(href, /admin-ajax\.php\?action=ghca_acd_pdf_download/);
    const dl = page.waitForEvent('download');
    await page.click('[data-ghca-packet-tray] [data-ghca-packet-download]');
    const d = await dl;
    const file = await d.path();
    assert.equal(fs.readFileSync(file).subarray(0, 5).toString(), '%PDF-');
    await page.waitForFunction(() => /Link used/.test(document.querySelector('[data-ghca-packet-tray]').innerText));
    await noticeContrast(page, '[data-ghca-packet-tray] [data-ghca-packet-notice]', THEME + ' ' + w + ' used notice');
    ok(THEME + ' ' + w + ': explicit Download PDF saved a real PDF; the notice then says the link was used');
    // The real endpoint refuses the used link.
    const again = await page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).text(), href);
    assert.doesNotMatch(again, /^%PDF-/); assert.match(again, /no longer available/); ok(THEME + ' ' + w + ': the same link, requested again, is refused by the server (single use)');
    await shot(page, 'notice-used-' + w);
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ---------- Inline User training report, real handler ----------
  for (const vp of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors } = await login(browser, vp);
    await dash(page);
    await tab(page, 'ghca-tab-reports');
    const before = page.url();
    await page.click('#ghca-tab-reports [data-ghca-report-open]');
    assert.equal(page.url(), before); ok(THEME + ' ' + w + ': Open report stays in the Reports tab');
    await page.fill('[data-ghca-report-input]', 'jordan');
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-ghca-report-user]');
    const t = await page.locator('[data-ghca-report-body]').innerText();
    assert.match(t, /Jordan Ellis/); assert.match(t, /DSP — New Hire/); assert.match(t, /j\*\*\*@example\.test/);
    assert.match(t, /No required courses assigned/); ok(THEME + ' ' + w + ': the real report for the chosen employee (synthetic, no LearnDash courses)');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    await shot(page, 'report-' + w);
    await page.click('[data-ghca-report-back]');
    assert.equal(await page.locator('[data-ghca-report-home]').isVisible(), true); ok(THEME + ' ' + w + ': Back to reports');
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  // ---------- Announcements, real handler and database ----------
  {
    const { ctx, page, errors } = await login(browser, { width: 1440, height: 900 });
    await dash(page);
    assert.equal(await page.locator('.ghca-acd__edit-modal-dialog').count(), 0); ok(THEME + ': the legacy announcement modal is not on the page');
    const add = page.locator('[data-ghca-announce-add]');
    await add.click();
    await page.waitForSelector('#ghca-acd-announce-modal:not([hidden])');
    assert.equal(await page.locator('#ghca-acd-announce-modal-title').innerText(), 'Add announcement');
    assert.equal(await page.evaluate(() => document.activeElement.name), 'title'); ok(THEME + ': Add opens the board modal with focus on Title');
    await shot(page, 'announce-modal');
    await page.click('[data-ghca-announce-save]');
    assert.equal(await page.locator('[data-ghca-announce-title-err]').isVisible(), true); ok(THEME + ': an empty title is stopped with an inline error');
    await page.fill('#ghca-an-title', 'Synthetic: audit window');
    await page.fill('#ghca-an-body', 'Please finish annual training before the audit window.');
    await page.click('[data-ghca-announce-type="reminder"]');
    assert.equal(await page.getAttribute('[data-ghca-announce-type="reminder"]', 'aria-pressed'), 'true');
    await page.selectOption('#ghca-an-aud', 'admins');
    await page.fill('#ghca-an-url', 'not a link');
    await page.click('[data-ghca-announce-save]');
    assert.equal(await page.locator('[data-ghca-announce-url-err]').isVisible(), true); ok(THEME + ': an invalid action link is stopped with an inline error');
    await page.fill('#ghca-an-url', SITE + '/?pagename=compliance-dashboard');
    await page.click('[data-ghca-announce-save]');
    await page.waitForSelector('#ghca-acd-announce-modal[hidden]', { state: 'attached' });
    await page.waitForFunction(() => /Announcement published/.test(document.querySelector('[data-ghca-toasts]').innerText));
    const row = page.locator('[data-announce-title="Synthetic: audit window"]');
    assert.match(await row.innerText(), /Reminder[\s\S]*Admins only/); ok(THEME + ': Publish saves through the real handler; the row shows its type and audience');
    await page.reload(); await page.waitForSelector('[data-announce-title="Synthetic: audit window"]');
    ok(THEME + ': it is stored (still there after reload)');
    // ⋯ menu by keyboard.
    const menuBtn = page.locator('[data-announce-title="Synthetic: audit window"] [data-ghca-ann-menu-btn]');
    await menuBtn.focus();
    await page.keyboard.press('Enter');
    assert.equal(await menuBtn.getAttribute('aria-expanded'), 'true');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Edit');
    await page.keyboard.press('ArrowDown');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Delete…');
    await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-ghca-ann-menu-btn')), true); ok(THEME + ': the ⋯ menu works from the keyboard (Enter, arrows, Escape returns focus)');
    // Edit, with a group audience.
    await menuBtn.click();
    await page.click('[data-announce-title="Synthetic: audit window"] [data-ghca-announce-edit]');
    await page.waitForSelector('#ghca-acd-announce-modal:not([hidden])');
    assert.equal(await page.inputValue('#ghca-an-title'), 'Synthetic: audit window');
    assert.equal(await page.inputValue('#ghca-an-aud'), 'admins');
    assert.equal(await page.getAttribute('[data-ghca-announce-type="reminder"]', 'aria-pressed'), 'true'); ok(THEME + ': Edit opens the modal filled with the stored values');
    await page.selectOption('#ghca-an-aud', 'group');
    assert.equal(await page.locator('[data-ghca-announce-group-field]').isVisible(), true);
    await page.selectOption('#ghca-an-group', { label: 'DSP — New Hire' });
    await page.fill('#ghca-an-title', 'Synthetic: new-hire audit window');
    await page.click('[data-ghca-announce-save]');
    await page.waitForSelector('[data-announce-title="Synthetic: new-hire audit window"]');
    assert.match(await page.locator('[data-announce-title="Synthetic: new-hire audit window"]').innerText(), /Group: DSP — New Hire/); ok(THEME + ': a one-group audience is saved and shown');
    // Delete with the confirmation dialog.
    await page.click('[data-announce-title="Synthetic: new-hire audit window"] [data-ghca-ann-menu-btn]');
    await page.click('[data-announce-title="Synthetic: new-hire audit window"] [data-ghca-announce-delete]');
    await page.waitForSelector('#ghca-acd-announce-confirm:not([hidden])');
    assert.equal(await page.getAttribute('[data-ghca-announce-confirm-dialog]', 'role'), 'alertdialog');
    assert.match(await page.locator('#ghca-acd-announce-confirm').innerText(), /“Synthetic: new-hire audit window” disappears/);
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Keep it'); ok(THEME + ': Delete… asks first, naming the announcement; focus starts on Keep it');
    await shot(page, 'announce-confirm');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-announce-title="Synthetic: new-hire audit window"]').count(), 1); ok(THEME + ': Escape keeps it');
    await page.click('[data-announce-title="Synthetic: new-hire audit window"] [data-ghca-ann-menu-btn]');
    await page.click('[data-announce-title="Synthetic: new-hire audit window"] [data-ghca-announce-delete]');
    await page.click('[data-ghca-announce-confirm-delete]');
    await page.waitForFunction(() => !document.querySelector('[data-announce-title="Synthetic: new-hire audit window"]'));
    await page.waitForFunction(() => /Announcement deleted/.test(document.querySelector('[data-ghca-toasts]').innerText));
    ok(THEME + ': Delete announcement removes it through the real handler');
    // Session expired while publishing: the text is kept.
    await add.click();
    await page.fill('#ghca-an-title', 'Synthetic: kept after session loss');
    await page.fill('#ghca-an-body', 'Draft body.');
    await ctx.clearCookies();
    await page.click('[data-ghca-announce-save]');
    await page.waitForFunction(() => /Couldn’t publish/.test(document.querySelector('[data-ghca-toasts]').innerText));
    assert.equal(await page.locator('#ghca-acd-announce-modal').isVisible(), true);
    assert.equal(await page.inputValue('#ghca-an-title'), 'Synthetic: kept after session loss'); ok(THEME + ': when the session has expired nothing is published, the toast says so, and the text stays in the form');
    assert.equal(errors.length, 0, errors.join(' | '));
    // Sign in again in the same browser (a new tab), as a person would.
    const again = { ctx, page: await ctx.newPage() };
    await again.page.goto(SITE + '/wp-login.php');
    await again.page.fill('#user_login', 'synthetic-admin');
    await again.page.fill('#user_pass', 'synthetic-pass');
    await Promise.all([again.page.waitForNavigation(), again.page.click('#wp-submit')]);
    await dash(again.page);
    await again.page.click('[data-ghca-announce-add]');
    assert.equal(await again.page.inputValue('#ghca-an-title'), 'Synthetic: kept after session loss');
    assert.equal(await again.page.locator('[data-ghca-announce-draft-note]').isVisible(), true); ok(THEME + ': after signing in again the unpublished text is back, with a note');
    assert.equal(await again.page.locator('[data-announce-title="Synthetic: kept after session loss"]').count(), 0); ok(THEME + ': and it was never published');
    await again.page.click('[data-ghca-announce-close].gh-btn--secondary');
    await again.ctx.close();
  }
  {
    // Phone: the modal and menu.
    const { ctx, page } = await login(browser, { width: 390, height: 844 });
    await dash(page);
    await page.click('[data-ghca-announce-add]');
    const g = await page.evaluate(() => { const r = document.querySelector('[data-ghca-announce-dialog]').getBoundingClientRect(); return { fit: r.left >= 0 && r.right <= window.innerWidth + 1, over: document.documentElement.scrollWidth > window.innerWidth }; });
    assert.equal(g.fit, true); assert.equal(g.over, false); ok(THEME + ' 390: the announcement modal fits the phone');
    await shot(page, 'announce-modal-390');
    await ctx.close();
  }

  // ---------- Manage users, real handler and database ----------
  for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
    const w = vp.width;
    const { ctx, page, errors } = await login(browser, vp);
    await page.goto(SITE + '/?pagename=manage-users');
    await page.waitForSelector('.ghca-ui .gh-mu');
    assert.equal(await page.locator('.gh-mu-tbl').isVisible(), w >= 1024);
    assert.equal(await page.locator('.gh-mu-cards').isVisible(), w < 1024);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true); ok(THEME + ' ' + w + ': Manage users shows ' + (w >= 1024 ? 'the table' : 'cards') + ' with no sideways scroll');
    const txt = await page.locator('.gh-mu-main').innerText();
    assert.doesNotMatch(txt, /Manage Users\s*$/m); ok(THEME + ' ' + w + ': no employee shows the page title as a group');
    await page.fill('[data-ghca-mu-search]', 'morgan');
    assert.equal(await page.locator(w >= 1024 ? '.gh-mu-tbl [data-ghca-mu-row]:visible' : '.gh-mu-cards [data-ghca-mu-row]:visible').count(), 1); ok(THEME + ' ' + w + ': search filters to the matching person');
    await page.fill('[data-ghca-mu-search]', '');
    await page.click('[data-ghca-mu-status="inactive"]');
    assert.equal(await page.locator('[data-ghca-mu-none]').isVisible(), true); ok(THEME + ' ' + w + ': Inactive with nobody inactive says so');
    await page.click('[data-ghca-mu-status="active"]');
    // Add: validation, Escape, focus return.
    await page.click('#ghca-acd-btn-add-user');
    await page.waitForSelector('#ghca-acd-user-modal:not([hidden])');
    assert.equal(await page.evaluate(() => document.activeElement.name), 'first_name');
    await page.fill('#ghca-acd-first-name', 'Nia'); await page.fill('#ghca-acd-last-name', 'Carter'); await page.fill('#ghca-acd-email', 'nia.carter@example');
    await page.click('#ghca-acd-user-submit');
    assert.equal(await page.locator('[data-ghca-mu-email-err]').isVisible(), true); assert.equal(await page.getAttribute('#ghca-acd-email', 'aria-invalid'), 'true'); ok(THEME + ' ' + w + ': Add employee checks the email inline before anything is sent');
    const d = await page.evaluate(() => { const r = document.querySelector('[data-ghca-mu-dialog]').getBoundingClientRect(); return { w: Math.round(r.width), right: Math.round(window.innerWidth - r.right) }; });
    assert.equal(d.w, Math.min(520, w)); ok(THEME + ' ' + w + ': the drawer is ' + d.w + 'px wide');
    await shot(page, 'manage-users-add-' + w);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#ghca-acd-user-modal').isHidden(), true);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-acd-btn-add-user'); ok(THEME + ' ' + w + ': Escape closes it and returns focus');
    if (w === 1440) {
      // Edit and save a synthetic person through the real handler.
      await page.click('.gh-mu-tbl [data-search*="riley"] .ghca-acd-btn-edit-user');
      await page.waitForSelector('#ghca-acd-user-modal:not([hidden])');
      assert.equal(await page.locator('#ghca-acd-user-modal-title').innerText(), 'Edit employee');
      assert.equal(await page.inputValue('#ghca-acd-email'), 'riley@example.test');
      assert.equal(await page.isChecked('#ghca-acd-user-modal input[name="groups[]"]:checked'), true); ok(THEME + ': Edit opens with the stored name, email and groups');
      await page.fill('#ghca-acd-phone', '555-0100');
      await page.selectOption('#ghca-acd-role', 'subscriber').catch(() => {});
      await Promise.all([page.waitForNavigation({ timeout: 15000 }), page.click('#ghca-acd-user-submit')]);
      await page.waitForSelector('.gh-mu');
      assert.match(await page.locator('.gh-mu-tbl [data-search*="riley"]').innerText(), /555-0100/); ok(THEME + ': Save changes stores the phone through the existing handler (shown after reload)');
    }
    assert.equal(errors.length, 0, errors.join(' | '));
    await ctx.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' real-site checks (' + THEME + ', WordPress + MariaDB; synthetic people; nothing sent)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
