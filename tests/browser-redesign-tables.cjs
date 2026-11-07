'use strict';
/**
 * Browser checks for the redesigned Employees and Inactive tabs, against synthetic employees.
 * Needs Chromium and Playwright (not part of the default test run). The page and every AJAX answer come
 * from the plugin's own PHP: tests/render-tables-preview.php renders the wrapper and runs the real
 * filter-table handler. Not WordPress, not LearnDash, not live data.
 *
 *   node tests/browser-redesign-tables.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const preview = path.join(__dirname, 'render-tables-preview.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }

const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.html': 'text/html' };

function build(dir, env) {
  fs.mkdirSync(dir, { recursive: true });
  execFileSync('php', [preview, 'page', dir, env.theme || 'gridhouse', env.hostile ? 'hostile' : ''], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_COUNT: String(env.count || 10), GHCA_PREVIEW_FLASH: env.flash ? 'flash' : '' } });
  return path.join(dir, 'tables.html');
}

// One routed https origin: the page, the plugin's assets (fonts included), and the real AJAX handler.
function origin(file, count) {
  return async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), String(count)]).toString();
      return route.fulfill({ status: 200, contentType: 'application/json', body: out });
    }
    if (url.pathname === '/tables.html') {
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

async function open(browser, url, viewport, count, hash) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  await page.route('https://preview.test/**', origin(url, count));
  await page.goto('https://preview.test/tables.html' + (hash || '#ghca-tab-employees'));
  await page.waitForSelector('.ghca-acd__tab-content.is-active .gh-cards', { state: 'attached' });
  return { context, page, errors };
}

// Computed metrics of a few controls, read the same way on the clean page and on the hostile-theme page.
const metrics = (page) => page.evaluate(() => {
  const m = (sel) => { const c = getComputedStyle(document.querySelector(sel)); return [c.height, c.borderTopWidth, c.borderTopLeftRadius, c.fontSize, c.fontFamily, c.paddingLeft, c.textTransform, c.fontWeight, c.letterSpacing].join('|'); };
  return {
    search: m('#ghca-filters-employees-q'), select: m('#ghca-filters-employees-g'), label: m('#ghca-tab-employees .gh-filters .gh-label'),
    th: m('#ghca-tab-employees .gh-table th button'), td: m('#ghca-tab-employees .gh-table tbody td'), title: m('#ghca-tab-employees .gh-toolbar .gh-h3'),
    view: m('#ghca-tab-employees .gh-btn--secondary.gh-btn--sm'), chip: m('#ghca-tab-employees .gh-st'),
  };
});
const rows = (page) => page.locator('#ghca-tab-employees .gh-table tbody tr').count();
const settle = (page) => page.waitForFunction(() => !document.querySelector('.gh-tmount.is-loading'));

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (msg) => { n += 1; process.stdout.write('ok - ' + msg + '\n'); };
  let baseline = null;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-tables-'));

  // ---------- Desktop, 10 employees ----------
  {
    const url = build(path.join(dir, 'a'), { count: 10, flash: true });
    const { context, page, errors } = await open(browser, url, { width: 1440, height: 900 }, 10);
    assert.equal(await rows(page), 10); ok('ten rows render');
    baseline = await metrics(page);
    assert.equal(await page.locator('#ghca-tab-employees .gh-cards').isVisible(), false); ok('phone cards are hidden on desktop');
    assert.equal(await page.locator('#ghca-tab-employees .gh-table-wrap').isVisible(), true); ok('table is visible on desktop');

    // Fonts: the families that actually render.
    const fam = await page.evaluate(() => {
      const pick = (sel) => { const el = document.querySelector(sel); return el ? getComputedStyle(el).fontFamily : null; };
      return { title: pick('#ghca-tab-employees .gh-toolbar .gh-h3'), th: pick('#ghca-tab-employees .gh-table th button'), name: pick('#ghca-tab-employees .gh-person-n'), chip: pick('#ghca-tab-employees .gh-st'), btn: pick('#ghca-tab-employees .gh-btn') };
    });
    assert.match(fam.title, /Wix Madefor Display/); assert.match(fam.th, /Wix Madefor Text/); assert.match(fam.name, /Wix Madefor Text/); assert.match(fam.chip, /Wix Madefor Text/); assert.match(fam.btn, /Wix Madefor Text/);
    ok('headings use Wix Madefor Display, body and controls use Wix Madefor Text');
    const cdp = await context.newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const doc = await cdp.send('DOM.getDocument', { depth: -1 });
    for (const sel of ['#ghca-tab-employees .gh-toolbar .gh-h3', '#ghca-tab-employees .gh-table th button', '#ghca-tab-employees .gh-person-n', '#ghca-tab-employees .gh-person-s', '#ghca-tab-employees .gh-st', '#ghca-tab-employees .gh-btn--secondary', '#ghca-tab-employees .gh-label', '#ghca-tab-employees .gh-pager']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      assert.ok(fonts.length > 0 && fonts.every((f) => /Wix Madefor/.test(f.familyName) && !f.isCustomFont === false), 'rendered font for ' + sel + ': ' + JSON.stringify(fonts));
    }
    ok('the font the renderer used is Wix Madefor, from the plugin, on every table element checked');

    // Server notices become toasts; success clears itself.
    assert.equal(await page.locator('[data-ghca-toasts] .gh-toast').count(), 2); ok('server notices render as board toasts');
    await page.locator('[data-ghca-toasts] .gh-toast-x').first().click();
    assert.equal(await page.locator('[data-ghca-toasts] .gh-toast').count(), 1); ok('a toast can be dismissed');

    // Filters apply through the real handler, and the chips and live region follow.
    await page.selectOption('#ghca-filters-employees-s', 'overdue');
    await settle(page);
    assert.equal(await rows(page), 3); ok('status filter refreshes the rows');
    assert.match(await page.locator('[data-ghca-chips="employees"]').innerText(), /Status: Overdue \(incl\. expired\)/); ok('a chip appears for the filter');
    assert.match(await page.locator('[data-ghca-chips="employees"]').innerText(), /Showing 1–3 of 3/); ok('the summary follows the rows');
    assert.match(await page.locator('[data-ghca-table-status="employees"]').innerText(), /Showing 1–3 of 3/); ok('the live region announces the result');
    await page.locator('[data-ghca-chips="employees"] [data-ghca-filter-clear]').first().click();
    await settle(page);
    assert.equal(await rows(page), 10); ok('removing the chip clears the filter');
    assert.equal(await page.inputValue('#ghca-filters-employees-s'), ''); ok('the select is cleared too');

    // Debounced search.
    await page.fill('#ghca-filters-employees-q', 'Alder');
    await page.waitForTimeout(150);
    assert.equal(await rows(page), 10); ok('search does not fire before the 300 ms debounce');
    await settle(page); await page.waitForFunction(() => document.querySelectorAll('#ghca-tab-employees .gh-table tbody tr').length === 1);
    ok('search narrows after the debounce');
    await page.focus('#ghca-filters-employees-q');
    await page.keyboard.press('Control+A'); await page.keyboard.press('Delete');
    await page.waitForFunction(() => document.querySelectorAll('#ghca-tab-employees .gh-table tbody tr').length === 10);
    ok('clearing the search restores everyone');

    // Combination, Clear all, filtered-empty state.
    await page.selectOption('#ghca-filters-employees-g', '11');
    await settle(page);
    await page.check('input[name="ghca_overdue"]');
    await settle(page);
    assert.ok(await page.locator('[data-ghca-chips="employees"] [data-ghca-filter-clear="all"]').count() === 1); ok('Clear all appears with two filters');
    await page.fill('#ghca-filters-employees-q', 'zzz-nobody');
    await page.waitForSelector('#ghca-tab-employees .gh-empty');
    assert.match(await page.locator('#ghca-tab-employees .gh-empty').innerText(), /No employees match these filters/); ok('filtered-empty state');
    await page.locator('#ghca-tab-employees .gh-empty [data-ghca-filter-clear="all"]').click();
    await page.waitForSelector('#ghca-tab-employees .gh-table');
    assert.equal(await rows(page), 10); ok('Clear filters in the empty state works');
    assert.equal(await page.isChecked('input[name="ghca_overdue"]'), false); assert.equal(await page.inputValue('#ghca-filters-employees-q'), ''); ok('every control is cleared');

    // Sorting keeps focus on the header button.
    await page.locator('#ghca-tab-employees th[data-ghca-sort="name"] button').click();
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-employees th[data-ghca-sort="name"]').getAttribute('aria-sort'), 'ascending'); ok('sort sets aria-sort');
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.closest('th') && document.activeElement.closest('th').getAttribute('data-ghca-sort')), 'name'); ok('focus returns to the sorted header');
    const firstAsc = await page.locator('#ghca-tab-employees .gh-person-n').first().innerText();
    await page.locator('#ghca-tab-employees th[data-ghca-sort="name"] button').click();
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-employees th[data-ghca-sort="name"]').getAttribute('aria-sort'), 'descending');
    assert.notEqual(await page.locator('#ghca-tab-employees .gh-person-n').first().innerText(), firstAsc); ok('second click reverses the order');

    // Keyboard: one logical tab order, a visible focus ring on every stop, Enter and Space activate.
    await page.focus('#ghca-filters-employees-q');
    const stops = [];
    for (let i = 0; i < 16; i += 1) {
      const d = await page.evaluate(() => {
        const e = document.activeElement; const c = getComputedStyle(e);
        const ring = (c.outlineStyle !== 'none' && parseFloat(c.outlineWidth) > 0) || (c.boxShadow && c.boxShadow !== 'none');
        return { tag: e.tagName, id: e.id, sort: e.closest('th') ? e.closest('th').getAttribute('data-ghca-sort') : '', drawer: e.getAttribute('data-ghca-employee-drawer'), ring: !!ring, hidden: e.getBoundingClientRect().width === 0 };
      });
      stops.push(d);
      await page.keyboard.press('Tab');
    }
    assert.deepEqual(stops.slice(0, 5).map((s) => s.id || s.tag), ['ghca-filters-employees-q', 'ghca-filters-employees-g', 'ghca-filters-employees-c', 'ghca-filters-employees-s', 'INPUT']); ok('keyboard: search, group, course, status, overdue in reading order');
    assert.ok(stops.some((s) => s.sort === 'name') && stops.some((s) => s.sort === 'status_slug'), 'sortable headers are tab stops'); ok('keyboard: sortable headers are reachable');
    assert.ok(stops.every((s) => !s.hidden), 'no invisible tab stops'); ok('keyboard: no invisible tab stop');
    assert.ok(stops.every((s) => s.ring), 'every stop shows a focus ring: ' + JSON.stringify(stops.filter((s) => !s.ring))); ok('keyboard: every stop shows a focus ring');
    await page.focus('#ghca-tab-employees th[data-ghca-sort="group"] button');
    await page.keyboard.press('Enter'); await settle(page);
    assert.equal(await page.locator('#ghca-tab-employees th[data-ghca-sort="group"]').getAttribute('aria-sort'), 'ascending'); ok('keyboard: Enter on a header sorts');
    await page.focus('input[name="ghca_overdue"]'); await page.keyboard.press('Space'); await settle(page);
    assert.equal(await rows(page), 3); ok('keyboard: Space on Overdue only filters');
    await page.focus('input[name="ghca_overdue"]'); await page.keyboard.press('Space'); await settle(page);
    assert.equal(await rows(page), 10);
    await page.focus('#ghca-tab-employees .gh-btn--secondary.gh-btn--sm');
    const before = page.url(); await page.keyboard.press('Enter');
    await page.waitForSelector('#ghca-acd-employee-drawer:not([hidden])');
    assert.equal(page.url().split('#')[0], before.split('#')[0], 'View does not navigate away when the drawer script handles it'); ok('keyboard: Enter on View opens the existing drawer instead of loading the report page');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-employee-drawer[hidden]', { state: 'attached' });
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-ghca-employee-drawer') !== null), true); ok('keyboard: Escape closes the drawer and focus returns to View');

    // Errors and toasts.
    await page.evaluate(() => { window.ghcaAcdToast('Something failed. Nothing was saved. Try again.', true); });
    assert.equal(await page.locator('[data-ghca-toasts] .gh-toast--error[role="alert"]').count(), 1); ok('error toast uses role=alert');
    await page.evaluate(() => { for (let i = 0; i < 5; i++) { window.ghcaAcdToast('Saved ' + i, false); } });
    assert.ok(await page.locator('[data-ghca-toasts] .gh-toast').count() <= 3); ok('at most three toasts are stacked');
    assert.equal(errors.length, 0, 'console errors: ' + errors.join(' | ')); ok('no console or page errors');
    if (shots) { await page.screenshot({ path: path.join(shots, 'employees-1440.png'), fullPage: true }); }
    await context.close();
  }

  // ---------- Desktop, 18 employees: paging ----------
  {
    const url = build(path.join(dir, 'b'), { count: 18 });
    const { context, page } = await open(browser, url, { width: 1440, height: 900 }, 18);
    await page.selectOption('#ghca-filters-employees-per', '10');
    await settle(page);
    assert.equal(await rows(page), 10);
    assert.match(await page.locator('#ghca-tab-employees .gh-pager').innerText(), /1–10 of 18/); ok('per-page select lives in the pager and applies');
    assert.equal(await page.locator('#ghca-tab-employees .gh-pager-btns [aria-label="Previous page"]').isDisabled(), true); ok('previous is disabled on page one');
    await page.locator('#ghca-tab-employees .gh-pager-btns [aria-label="Page 2"]').click();
    await settle(page);
    assert.equal(await rows(page), 8);
    assert.match(await page.locator('#ghca-tab-employees .gh-pager').innerText(), /11–18 of 18/); ok('page two shows the rest');
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label')), 'Page 2'); ok('focus stays on the page button after the refresh');
    assert.equal(await page.locator('#ghca-tab-employees .gh-pager-btns [aria-label="Page 2"]').getAttribute('aria-current'), 'page'); ok('current page is marked');
    await page.selectOption('#ghca-filters-employees-per', '25');
    await settle(page);
    assert.equal(await rows(page), 18); ok('changing the page size returns to the first page');
    await context.close();
  }

  // ---------- Hostile theme ----------
  {
    const url = build(path.join(dir, 'c'), { count: 10, hostile: true });
    const { context, page } = await open(browser, url, { width: 1440, height: 900 }, 10);
    const s = await page.evaluate(() => {
      const cs = (sel) => getComputedStyle(document.querySelector(sel));
      const name = cs('#ghca-tab-employees .gh-person-n');
      const td = cs('#ghca-tab-employees .gh-table td');
      return { nameColor: name.color, nameDeco: name.textDecorationLine, tdBorder: td.borderLeftWidth };
    });
    assert.notEqual(s.nameColor, 'rgb(6, 69, 173)'); assert.equal(s.nameDeco, 'none'); ok('theme link colour and underline do not reach employee names');
    assert.equal(s.tdBorder, '0px'); ok('theme table borders do not reach cells');
    const hostile = await metrics(page);
    for (const key of Object.keys(baseline)) {
      assert.equal(hostile[key], baseline[key], key + ' differs under a hostile theme: ' + baseline[key] + ' vs ' + hostile[key]);
    }
    ok('search, select, label, table header and cell, title, View button and status chip measure the same under a hostile theme');
    await page.hover('#ghca-tab-employees .gh-btn--secondary');
    const hov = await page.evaluate(() => getComputedStyle(document.querySelector('#ghca-tab-employees .gh-btn--secondary')).backgroundColor);
    assert.notEqual(hov, 'rgb(0, 0, 255)'); ok('theme button hover fill does not reach View');
    await page.hover('#ghca-tab-employees .gh-person-n');
    const nameHover = await page.evaluate(() => getComputedStyle(document.querySelector('#ghca-tab-employees .gh-person-n')).backgroundColor);
    assert.notEqual(nameHover, 'rgb(204, 255, 238)'); ok('theme link hover fill does not reach names');
    await context.close();
  }

  // ---------- Tablet: the table scrolls inside its box ----------
  {
    const url = build(path.join(dir, 'd'), { count: 10 });
    const { context, page } = await open(browser, url, { width: 834, height: 1194 }, 10);
    assert.equal(await page.locator('#ghca-tab-employees .gh-table-wrap').isVisible(), true);
    const m = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: window.innerWidth, wrapScroll: document.querySelector('#ghca-tab-employees .gh-table-wrap').scrollWidth > document.querySelector('#ghca-tab-employees .gh-table-wrap').clientWidth }));
    assert.ok(m.doc <= m.win + 1, 'page does not scroll sideways at 834'); ok('tablet: the page does not scroll sideways');
    if (shots) { await page.screenshot({ path: path.join(shots, 'employees-834.png'), fullPage: true }); }
    await context.close();
  }

  // ---------- Phone: cards ----------
  {
    const url = build(path.join(dir, 'e'), { count: 10 });
    const { context, page } = await open(browser, url, { width: 390, height: 844 }, 10);
    assert.equal(await page.locator('#ghca-tab-employees .gh-table-wrap').isVisible(), false); ok('phone: the table is hidden');
    assert.equal(await page.locator('#ghca-tab-employees .gh-mcard').count(), 10); ok('phone: ten employee cards');
    const m = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: window.innerWidth }));
    assert.ok(m.doc <= m.win + 1, 'no sideways page scroll at 390'); ok('phone: no sideways page scroll');
    const tap = await page.evaluate(() => Array.from(document.querySelectorAll('#ghca-tab-employees .gh-mcard .gh-btn, #ghca-tab-employees .gh-filters .gh-select, #ghca-tab-employees .gh-filters .gh-input')).map((e) => e.getBoundingClientRect().height));
    assert.ok(tap.every((h) => h >= 43.5), 'touch targets are at least 44px: ' + tap.join(',')); ok('phone: controls are at least 44px tall');
    await page.selectOption('#ghca-filters-employees-s', 'overdue');
    await settle(page);
    assert.equal(await page.locator('#ghca-tab-employees .gh-mcard').count(), 3); ok('phone: filters refresh the cards');
    if (shots) { await page.screenshot({ path: path.join(shots, 'employees-390.png'), fullPage: true }); }
    await context.close();
  }

  // ---------- Inactive tab ----------
  {
    const url = build(path.join(dir, 'f'), { count: 10 });
    const { context, page } = await open(browser, url, { width: 1440, height: 900 }, 10, '#ghca-tab-inactive-employees');
    await page.waitForSelector('#ghca-tab-inactive-employees.is-active .gh-table');
    assert.equal(await page.locator('#ghca-tab-inactive-employees .gh-table tbody tr').count(), 2); ok('inactive: two rows');
    await page.selectOption('#ghca-filters-inactive_employees-s', 'overdue');
    await page.waitForFunction(() => document.querySelectorAll('#ghca-tab-inactive-employees .gh-table tbody tr').length === 1);
    ok('inactive: filters go through the inactive handler');
    if (shots) { await page.screenshot({ path: path.join(shots, 'inactive-1440.png'), fullPage: true }); }
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' browser checks\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
