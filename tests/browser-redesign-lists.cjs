'use strict';
/**
 * Browser checks for the redesigned Overdue, Courses and Certificates tabs (phase 2, slice 4), against
 * synthetic employees. Needs Chromium and Playwright (not part of the default test run). The page and
 * every AJAX answer come from the plugin's own PHP: tests/render-lists-preview.php renders the wrapper and
 * runs the real filter-table handler. The certificate endpoint is answered with a canned response, so
 * nothing is fetched from LearnDash. Not WordPress, not LearnDash, not live data.
 *
 *   node tests/browser-redesign-lists.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const preview = path.join(__dirname, 'render-lists-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }

const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.html': 'text/html' };
const HOST = 'https://example.test';

function build(dir, env) {
  fs.mkdirSync(dir, { recursive: true });
  execFileSync('php', [preview, 'page', dir, env.theme || 'gridhouse', env.hostile ? 'hostile' : ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: HOST + '/assets', GHCA_PREVIEW_PERMS: env.perms || '' } });
  return path.join(dir, 'lists.html');
}

// One routed origin: the page, the plugin's assets, the real AJAX handler and a canned certificate endpoint.
function origin(file, state) {
  return async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      state.ajax.push(params);
      const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params)], { env: { ...process.env, GHCA_PREVIEW_PERMS: state.perms } }).toString();
      return route.fulfill({ status: 200, contentType: 'application/json', body: out });
    }
    if (url.pathname === '/wp-admin/admin-ajax.php' && url.searchParams.get('action') === 'ghca_acd_certificate_download') {
      state.downloads.push(Object.fromEntries(url.searchParams));
      if (state.certReply === 'html') {
        return route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Please log in</html>' });
      }
      return route.fulfill({ status: 200, contentType: 'application/pdf', body: Buffer.from('%PDF-1.4\n% synthetic\n') });
    }
    if (url.pathname === '/lists.html') {
      return route.fulfill({ status: 200, contentType: 'text/html', body: fs.readFileSync(file) });
    }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) {
        return route.fulfill({ status: 200, contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) });
      }
    }
    return route.fulfill({ status: 404, body: 'not found' });
  };
}

async function open(browser, file, viewport, tab, perms) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, acceptDownloads: true });
  const page = await context.newPage();
  const errors = [];
  const state = { ajax: [], downloads: [], certReply: 'pdf', perms: perms || '' };
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  await page.route(HOST + '/**', origin(file, state));
  await page.goto(HOST + '/lists.html');
  await page.click('[data-ghca-tab-target="' + tab + '"]');
  await page.waitForSelector('#' + tab + '.is-active');
  return { context, page, errors, state };
}

const settle = (page) => page.waitForFunction(() => !document.querySelector('.gh-tmount.is-loading'));
const ids = (page, sel) => page.$$eval(sel, (els) => els.map((el) => el.getAttribute('data-ghca-employee-drawer')));

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (msg) => { n += 1; process.stdout.write('ok - ' + msg + '\n'); };
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-lists-'));
  const ALL = 'remind,docs,comms,admin';
  const clean = build(path.join(tmp, 'clean'), { perms: ALL });

  // ---------------- Overdue, 1440
  {
    const { context, page, errors, state } = await open(browser, clean, { width: 1440, height: 900 }, 'ghca-tab-overdue', ALL);
    const fonts = await page.evaluate(async () => { await document.fonts.ready; return [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family.replace(/"/g, '')); });
    assert.ok(fonts.length > 0 && fonts.every((f) => /Wix Madefor/.test(f)), 'only Wix Madefor loaded: ' + fonts.join(','));
    ok('fonts: Wix Madefor only');

    const tables = await page.locator('#ghca-tab-overdue .gh-tbl').count();
    assert.equal(tables, 2);
    assert.equal(await page.locator('#ghca-tab-overdue .gh-cards').first().isVisible(), false);
    ok('desktop: overdue and due-soon tables, cards hidden');
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['201', '202', '205', '204', '203']);
    ok('rows in the legacy priority order');

    // The overdue table's first cell carries the board's risk edge.
    const edge = await page.$eval('#ghca-tab-overdue tbody tr.is-risk td:first-child', (td) => getComputedStyle(td).boxShadow);
    assert.match(edge, /inset/);
    ok('risk edge on overdue rows');

    // Row menu: opens, is not clipped by the table, closes on Escape with focus back on its button.
    const summary = page.locator('#ghca-tab-overdue tbody tr').first().locator('.gh-row-more > summary');
    await summary.click();
    const menu = page.locator('#ghca-tab-overdue tbody tr').first().locator('.gh-row-menu');
    assert.ok(await menu.isVisible());
    const visible = await menu.evaluate((m) => { const r = m.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.bottom - 6); return m.contains(hit); });
    assert.ok(visible, 'menu bottom is on top of the page, not clipped');
    const items = await menu.locator('.gh-menu-i').allInnerTexts();
    assert.ok(items.some((t) => /Email reminder/.test(t)) && items.some((t) => /Contact employee/.test(t)));
    assert.ok(!items.some((t) => /Log a reminder|Open CRM/.test(t)), 'FluentCRM is not active in the preview');
    ok('row menu shows only actions that exist');
    await page.keyboard.press('Escape');
    assert.equal(await menu.isVisible(), false);
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.tagName), 'SUMMARY');
    ok('Escape closes the row menu and returns focus');

    // Remind buttons carry the slice-3 hook.
    assert.equal(await page.locator('#ghca-tab-overdue .gh-tbl [data-ghca-reminder]').count(), 5);
    assert.equal(await page.locator('#ghca-tab-overdue .gh-tbl [data-ghca-reminder]').first().getAttribute('data-ghca-reminder-name'), 'Daniel Reyes');
    ok('Remind on every row, with the reminder modal hook');
    assert.equal(await page.locator('#ghca-tab-overdue th', { hasText: 'Last reminder' }).count(), 0);
    ok('no Last reminder column when the messaging tables are not there');

    // Sort select writes the existing hidden fields; the request carries no new field.
    await page.selectOption('#ghca-filters-priority-s', 'name:desc');
    await settle(page);
    const last = state.ajax[state.ajax.length - 1];
    assert.equal(last.ghca_orderby, 'name');
    assert.equal(last.ghca_order, 'desc');
    assert.equal(last.ghca_ui, 'board');
    assert.ok(!Object.keys(last).some((k) => /sort/.test(k)), 'no sort field of its own');
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['205', '202', '201', '203', '204']);
    ok('Sort select: Name Z–A through ghca_orderby / ghca_order');

    await page.selectOption('#ghca-filters-priority-t', 'at_risk');
    await settle(page);
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['203', '204']); // still sorted Name Z–A
    assert.equal(await page.locator('#ghca-tab-overdue [data-ghca-chips] .gh-fchip').count(), 1);
    ok('Show: Due soon only, with a chip');

    await page.fill('#ghca-filters-priority-q', 'ellis');
    await page.waitForTimeout(150);
    const before = state.ajax.length;
    await page.waitForTimeout(400);
    await settle(page);
    assert.equal(state.ajax.length, before + 1, 'one request after the 300 ms pause');
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['204']);
    ok('search debounces and filters');

    await page.click('#ghca-tab-overdue [data-ghca-chips] [data-ghca-filter-clear="all"]');
    await settle(page);
    assert.equal(await page.inputValue('#ghca-filters-priority-q'), '');
    assert.equal(await page.inputValue('#ghca-filters-priority-t'), '');
    assert.equal(await page.inputValue('#ghca-filters-priority-s'), 'name:desc');
    ok('Clear all clears filters and keeps the sort');

    // Overdue only, alone and combined with a group.
    await page.selectOption('#ghca-filters-priority-t', 'overdue');
    await settle(page);
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['205', '202', '201']);
    assert.equal(await page.locator('#ghca-tab-overdue #ghca-pri-soon-h').count(), 0);
    assert.equal(state.ajax[state.ajax.length - 1].ghca_pri_type, 'overdue');
    ok('Show: Overdue only lists the three overdue employees and drops the due-soon section');
    await page.selectOption('#ghca-filters-priority-g', '12');
    await settle(page);
    assert.deepEqual(await ids(page, '#ghca-tab-overdue .gh-tbl tbody .gh-person-n'), ['202']);
    assert.equal(await page.locator('#ghca-tab-overdue [data-ghca-chips] .gh-fchip').count(), 2);
    ok('Overdue only + group combine (two chips)');
    await page.fill('#ghca-filters-priority-q', 'reyes');
    await page.waitForTimeout(450);
    await settle(page);
    assert.match(await page.locator('#ghca-tab-overdue .gh-tmount').innerText(), /No one matches these filters/);
    assert.equal(await page.locator('#ghca-tab-overdue .gh-tbl').count(), 0);
    ok('three filters that exclude everyone: filtered-empty state');
    await page.click('#ghca-tab-overdue .gh-tmount [data-ghca-filter-clear="all"]');
    await settle(page);
    assert.equal(await page.inputValue('#ghca-filters-priority-q'), '');
    assert.equal(await page.inputValue('#ghca-filters-priority-g'), '');
    assert.equal(await page.inputValue('#ghca-filters-priority-t'), '');
    assert.equal(await page.locator('#ghca-tab-overdue .gh-tbl tbody tr').count(), 5);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-filters-priority-q');
    ok('Clear filters in the empty state resets every filter, restores all rows and returns focus to search');

    assert.deepEqual(errors, []);
    ok('no script errors on Overdue');
    if (shots) { await page.screenshot({ path: path.join(shots, 'overdue-1440.png'), fullPage: true }); }
    await context.close();
  }

  // ---------------- Courses, 1440
  {
    const { context, page, errors, state } = await open(browser, clean, { width: 1440, height: 900 }, 'ghca-tab-courses', ALL);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 10);
    const badge = await page.locator('[data-ghca-tab-target="ghca-tab-courses"] .gh-count').innerText();
    assert.ok((await page.locator('#ghca-tab-courses .gh-toolbar').innerText()).includes(badge + ' tracked courses'));
    ok('headline course count equals the tab badge');
    const rateList = () => page.$$eval('#ghca-tab-courses .gh-tbl .gh-rate strong', (els) => els.map((e) => parseInt(e.textContent, 10)));
    let rates = await rateList();
    assert.equal(await page.inputValue('#ghca-filters-courses-s'), 'rate_asc');
    assert.deepEqual(rates, [...rates].sort((a, b) => a - b));
    assert.equal(await page.getAttribute('#ghca-tab-courses th.c-rate', 'aria-sort'), 'ascending');
    ok('default: Lowest completion first, as on the board');
    await page.selectOption('#ghca-filters-courses-s', 'name');
    await settle(page);
    assert.equal(state.ajax[state.ajax.length - 1].ghca_crs_sort, 'name');
    const titles = await page.$$eval('#ghca-tab-courses .gh-tbl tbody td:first-child strong', (els) => els.map((e) => e.textContent));
    assert.deepEqual(titles, [...titles].sort((a, b) => a.localeCompare(b)));
    assert.equal(await page.getAttribute('#ghca-tab-courses th.c-rate', 'aria-sort'), null);
    ok('Course A–Z gives the legacy order');
    await page.selectOption('#ghca-filters-courses-s', 'rate_asc');
    await settle(page);

    // Each course filter, then combined.
    await page.selectOption('#ghca-filters-courses-g', '13');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 3);
    ok('group filter');
    await page.selectOption('#ghca-filters-courses-g', '');
    await settle(page);
    await page.selectOption('#ghca-filters-courses-c', 'no');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 2);
    ok('certificate filter');
    await page.click('#ghca-tab-courses [data-ghca-chips] [data-ghca-filter-clear="ghca_crs_cert"]');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 10);
    assert.equal(await page.inputValue('#ghca-filters-courses-s'), 'rate_asc');
    ok('chip removes its filter; sort stays');
    await page.fill('#ghca-filters-courses-q', 'fatal');
    await page.waitForTimeout(450);
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 2);
    ok('search filter');
    await page.selectOption('#ghca-filters-courses-g', '12');
    await settle(page);
    await page.selectOption('#ghca-filters-courses-c', 'yes');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 1);
    assert.equal(await page.locator('#ghca-tab-courses [data-ghca-chips] .gh-fchip').count(), 3);
    rates = await rateList();
    assert.deepEqual(rates, [50]);
    ok('search + group + certificate combine to one row (Fatal Five, Group 12, 50%)');
    await page.selectOption('#ghca-filters-courses-c', 'no');
    await settle(page);
    assert.match(await page.locator('#ghca-tab-courses .gh-tmount').innerText(), /No courses match these filters/);
    ok('combined filters that match nothing: filtered-empty state');
    await page.click('#ghca-tab-courses .gh-tmount [data-ghca-filter-clear="all"]');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 10);
    assert.equal(await page.inputValue('#ghca-filters-courses-q'), '');
    assert.equal(await page.inputValue('#ghca-filters-courses-g'), '');
    assert.equal(await page.inputValue('#ghca-filters-courses-c'), '');
    assert.equal(await page.inputValue('#ghca-filters-courses-s'), 'rate_asc');
    ok('Clear filters resets search, group and certificate, keeps the sort');
    await page.selectOption('#ghca-tab-courses [data-ghca-per-page]', '10');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-courses .gh-tbl tbody tr').count(), 10);
    assert.equal(state.ajax[state.ajax.length - 1].ghca_crs_per, '10');
    ok('per page');
    assert.deepEqual(errors, []);
    ok('no script errors on Courses');
    if (shots) { await page.screenshot({ path: path.join(shots, 'courses-1440.png'), fullPage: true }); }
    await context.close();
  }

  // ---------------- Certificates, 1440
  {
    const { context, page, errors, state } = await open(browser, clean, { width: 1440, height: 900 }, 'ghca-tab-certificates', ALL);
    const alertTop = await page.locator('#ghca-tab-certificates .gh-alert--danger').evaluate((e) => e.getBoundingClientRect().top);
    const listTop = await page.locator('#ghca-cert-list').evaluate((e) => e.getBoundingClientRect().top);
    assert.ok(alertTop < listTop);
    ok('missing alert above the list');

    await page.click('#ghca-tab-certificates .gh-alert [data-ghca-set-value="missing"]');
    await settle(page);
    assert.equal(state.ajax[state.ajax.length - 1].ghca_cert_view, 'missing');
    assert.equal(await page.getAttribute('#ghca-cert-list [data-ghca-set-value="missing"]', 'aria-pressed'), 'true');
    assert.equal(await page.getAttribute('#ghca-cert-list [data-ghca-set-value="issued"]', 'aria-pressed'), 'false');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-ghca-set-value')), 'missing');
    assert.match(await page.locator('#ghca-tab-certificates .gh-tbl tbody').innerText(), /Maya Okafor[\s\S]*No certificate link/);
    ok('Show missing switches the list and moves focus to the Missing toggle');
    const pressedBg = await page.$eval('#ghca-cert-list [aria-pressed="true"]', (b) => getComputedStyle(b).backgroundColor);
    const idleBg = await page.$eval('#ghca-cert-list [aria-pressed="false"]', (b) => getComputedStyle(b).backgroundColor);
    assert.notEqual(pressedBg, idleBg);
    ok('pressed toggle styled as the selected sub-tab');

    await page.click('#ghca-cert-list [data-ghca-set-value="issued"]');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-certificates .gh-tbl tbody tr').count(), 5);
    ok('back to Issued');

    // The only sort is the date column, as on the board.
    assert.equal(await page.locator('#ghca-tab-certificates th[data-ghca-sort]').count(), 1);
    await page.click('#ghca-tab-certificates th[data-ghca-sort="completed_ts"] button');
    await settle(page);
    const dates = await page.$$eval('#ghca-tab-certificates .gh-tbl tbody td:first-child', (els) => els.map((e) => Date.parse(e.textContent)));
    assert.deepEqual(dates, [...dates].sort((a, b) => a - b));
    assert.equal(state.ajax[state.ajax.length - 1].ghca_cert_order, 'asc');
    ok('date header toggles to oldest first; no other certificate sort');

    // Combined filters, empty state and reset.
    await page.selectOption('#ghca-filters-certificates-c', '504');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-certificates .gh-tbl tbody tr').count(), 2);
    await page.selectOption('#ghca-filters-certificates-d', '30');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-certificates .gh-tbl tbody tr').count(), 1);
    ok('course + date filters combine');
    await page.fill('#ghca-filters-certificates-q', 'reyes');
    await page.waitForTimeout(450);
    await settle(page);
    assert.match(await page.locator('#ghca-tab-certificates .gh-tmount').innerText(), /No certificates match these filters/);
    ok('three filters that match nothing: filtered-empty state');
    await page.click('#ghca-tab-certificates .gh-tmount [data-ghca-filter-clear="all"]');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-certificates .gh-tbl tbody tr').count(), 5);
    assert.equal(await page.inputValue('#ghca-filters-certificates-c'), '');
    assert.equal(await page.inputValue('#ghca-filters-certificates-d'), '');
    assert.equal(await page.inputValue('#ghca-filters-certificates-q'), '');
    assert.equal(await page.getAttribute('#ghca-cert-list [data-ghca-set-value="issued"]', 'aria-pressed'), 'true');
    ok('Clear filters resets every filter and keeps the Issued view');

    // Download: through the existing endpoint, ids only; only a PDF is saved.
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('#ghca-tab-certificates .gh-tbl [data-ghca-cert-pdf]').first().click(),
    ]);
    const req = state.downloads[state.downloads.length - 1];
    assert.equal(req.action, 'ghca_acd_certificate_download');
    assert.ok(req.user_id && req.course_id && !('quiz_id' in req) && !Object.values(req).some((v) => /certificates\//.test(v)));
    assert.match(download.suggestedFilename(), /\.pdf$/);
    ok('PDF downloads through ghca_acd_certificate_download with ids only');
    state.certReply = 'html';
    await page.locator('#ghca-tab-certificates .gh-tbl [data-ghca-cert-pdf]').nth(1).click();
    await page.waitForSelector('.gh-toast');
    assert.match(await page.locator('.gh-toast').last().innerText(), /could not be downloaded\. Nothing was saved/);
    ok('a non-PDF answer saves nothing and says so in an error toast');

    await page.selectOption('#ghca-filters-certificates-d', '30');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-certificates .gh-tbl tbody tr').count(), 3);
    ok('date filter');
    assert.deepEqual(errors, []);
    ok('no script errors on Certificates');
    if (shots) { await page.screenshot({ path: path.join(shots, 'certificates-1440.png'), fullPage: true }); }
    await context.close();
  }

  // ---------------- Without the document permission, no download control.
  {
    const file = build(path.join(tmp, 'nodocs'), { perms: 'remind' });
    const { context, page } = await open(browser, file, { width: 1440, height: 900 }, 'ghca-tab-certificates', 'remind');
    assert.equal(await page.locator('[data-ghca-cert-pdf]').count(), 0);
    ok('no PDF button without the employee-documents permission');
    await page.click('[data-ghca-tab-target="ghca-tab-overdue"]');
    assert.equal(await page.locator('#ghca-tab-overdue [data-ghca-reminder]').count(), 10);
    await context.close();
    const quiet = build(path.join(tmp, 'noremind'), { perms: 'none' });
    const o = await open(browser, quiet, { width: 1440, height: 900 }, 'ghca-tab-overdue', 'none');
    assert.equal(await o.page.locator('[data-ghca-reminder]').count(), 0);
    ok('no Remind without the send-reminders permission');
    await o.context.close();
  }

  // ---------------- Phone, 390
  for (const tab of ['ghca-tab-overdue', 'ghca-tab-courses', 'ghca-tab-certificates']) {
    const { context, page, errors } = await open(browser, clean, { width: 390, height: 844 }, tab, ALL);
    assert.equal(await page.locator('#' + tab + ' .gh-tbl').first().isVisible(), false);
    assert.ok(await page.locator('#' + tab + ' .gh-cards').first().isVisible());
    const width = await page.evaluate(() => document.documentElement.scrollWidth);
    assert.ok(width <= 390, tab + ' scrollWidth ' + width);
    const small = await page.$$eval('#' + tab + ' .gh-cards .gh-btn', (els) => els.filter((e) => e.getBoundingClientRect().height < 40).length);
    assert.equal(small, 0);
    assert.deepEqual(errors, []);
    ok(tab + ' at 390: cards, no sideways scroll, buttons at least 40 px');
    if (shots) {
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
      const p = await phone.newPage();
      await p.route(HOST + '/**', origin(clean, { ajax: [], downloads: [], perms: ALL }));
      await p.goto(HOST + '/lists.html');
      await p.click('[data-ghca-tab-target="' + tab + '"]');
      await p.screenshot({ path: path.join(shots, tab.replace('ghca-tab-', '') + '-390.png'), fullPage: true });
      await phone.close();
    }
    await context.close();
  }

  // ---------------- Phone: the dashboard's own tab bar scrolls sideways and keyboard selection keeps the tab in view.
  {
    const { context, page, errors } = await open(browser, clean, { width: 390, height: 844 }, 'ghca-tab-overview', ALL);
    const bar = await page.evaluate(() => { const t = document.querySelector('[data-ghca-tablist-main]'); return { ox: getComputedStyle(t).overflowX, sw: t.scrollWidth, cw: t.clientWidth }; });
    assert.ok(['auto', 'scroll'].includes(bar.ox));
    ok('phone: main tab bar is a horizontal scroller (' + bar.sw + ' of ' + bar.cw + ' px)');
    const inView = () => page.evaluate(() => { const t = document.querySelector('[data-ghca-tablist-main]').getBoundingClientRect(); const b = document.activeElement.getBoundingClientRect(); return b.left >= t.left - 1 && b.right <= t.right + 1; });
    await page.focus('[data-ghca-tab-target="ghca-tab-overview"]');
    await page.keyboard.press('End');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-ghca-tab-target')), 'ghca-tab-certificates');
    assert.ok(await page.locator('#ghca-tab-certificates').isVisible());
    assert.ok(await inView(), 'Certificates tab fully in view');
    ok('phone: End opens Certificates and scrolls its tab fully into view');
    for (const target of ['ghca-tab-courses', 'ghca-tab-overdue', 'ghca-tab-overview']) {
      await page.keyboard.press('ArrowLeft');
      assert.equal(await page.evaluate(() => document.activeElement.getAttribute('data-ghca-tab-target')), target);
      assert.ok(await inView(), target + ' in view');
    }
    ok('phone: ArrowLeft walks back through every tab, each in view');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    assert.deepEqual(errors, []);
    ok('phone: no sideways page scroll, no script errors');
    await context.close();
  }

  // ---------------- Hostile theme: the same computed styles as on a clean page.
  {
    const hostile = build(path.join(tmp, 'hostile'), { perms: ALL, hostile: true });
    const read = async (file) => {
      const out = {};
      for (const tab of ['ghca-tab-overdue', 'ghca-tab-courses', 'ghca-tab-certificates']) {
        const { context, page } = await open(browser, file, { width: 1440, height: 900 }, tab, ALL);
        out[tab] = await page.evaluate((t) => {
          const pick = (sel) => { const el = document.querySelector('#' + t + ' ' + sel); if (!el) { return null; } const c = getComputedStyle(el); return [c.fontFamily, c.fontSize, c.fontWeight, c.lineHeight, c.letterSpacing, c.textTransform, c.color, c.backgroundColor, c.borderTopWidth, c.borderBottomWidth, c.borderBottomStyle, c.borderBottomColor, c.textDecorationLine, c.paddingLeft, c.marginTop, c.height].join('|'); };
          return ['.gh-toolbar .gh-h3', '.gh-label', '.gh-input', '.gh-select', '.gh-table th', '.gh-table td', '.gh-person-n', '.gh-st', '.gh-btn--sm', '.gh-owed', '.gh-owed li', '.gh-row-more > summary', '.gh-sect > .gh-h4', '.gh-badge', '.gh-rate strong', '.gh-stat-val', '.gh-subtab', '.gh-tl li', '.gh-alert-t', '.gh-pager-btns button'].map(pick);
        }, tab);
        await context.close();
      }
      return out;
    };
    const a = await read(clean);
    const b = await read(hostile);
    for (const tab of Object.keys(a)) {
      a[tab].forEach((v, i) => { if (v !== null) { assert.equal(b[tab][i], v, tab + ' component ' + i + ' differs under the hostile theme'); } });
    }
    ok('hostile theme: every measured component unchanged on all three tabs');
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' redesigned list browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
