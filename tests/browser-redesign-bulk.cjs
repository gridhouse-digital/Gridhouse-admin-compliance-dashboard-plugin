'use strict';
/**
 * Browser checks for row selection, the selection bar, Export selected and the bulk reminder dialog (slice 6).
 * The page and the table refreshes are the plugin's own (tests/render-tables-preview.php with
 * GHCA_PREVIEW_BULK=1); the bulk AJAX answers come from the plugin's real bulk class and messaging service over an
 * in-memory repository with mocked delivery (tests/bulk-ajax-runner.php). Nothing is sent. Synthetic employees.
 *
 *   node tests/browser-redesign-bulk.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const preview = path.join(__dirname, 'render-tables-preview.php');
const runner = path.join(__dirname, 'bulk-ajax-runner.php');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const NAMES = ['Alder Reyes', 'Birch Okafor', 'Cedar Hill', 'Dune Ellis', 'Elm Alvarez', 'Fern Boateng', 'Gale Brooks', 'Hazel Nair', 'Ivy Kim', 'Juniper Mensah', 'Kestrel Odum', 'Larch Pryce', 'Maple Quinn', 'Nettle Rao', 'Orchid Sato', 'Pine Tran', 'Quill Underwood', 'Rowan Vega'];

function people(count) {
  const p = {};
  for (let i = 0; i < count; i++) {
    const id = 100 + i;
    // 102 has no valid email; 104 is outside the viewer's scope on the server (the browser never decides scope).
    p[id] = [NAMES[i % NAMES.length], id === 102 ? 'no-email' : 'person' + id + '@example.test', id !== 104, false];
  }
  return p;
}

async function open(browser, width, opt = {}) {
  const count = opt.count || 20;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-bulk-'));
  const state = path.join(dir, 'state.json');
  const penv = { ...process.env, GHCA_PREVIEW_BULK: '1', GHCA_PREVIEW_ASSETS: 'https://preview.test/assets', GHCA_PREVIEW_COUNT: String(count), GHCA_PREVIEW_PERMS: opt.perms || '', GHCA_PREVIEW_BRAND: '#2F5D3A' };
  execFileSync('php', [preview, 'page', dir, 'gridhouse', opt.hostile ? 'hostile' : ''], { env: penv });
  const file = path.join(dir, 'tables.html');
  const log = { bulk: [], exports: [] };
  const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: shots ? 2 : 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await context.route('https://example.test/**', async (route) => {
    log.exports.push(Object.fromEntries(new URLSearchParams(route.request().postData() || '')));
    return route.fulfill({ status: 200, contentType: 'text/csv', body: 'synthetic' });
  });
  await context.route('https://preview.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/ajax') {
      const params = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
      if (String(params.action || '').startsWith('ghca_acd_bulk_')) {
        log.bulk.push(params);
        if (opt.delay) { await new Promise((r) => setTimeout(r, opt.delay)); }
        const out = execFileSync('php', [runner, JSON.stringify(params)], { env: { ...process.env, GHCA_BULK_STATE: state, GHCA_BULK_PEOPLE: JSON.stringify(people(count)), GHCA_BULK_FAIL_ID: '103', GHCA_BULK_FAIL_TIMES: '2' } }).toString();
        return route.fulfill({ status: 200, contentType: 'application/json', body: out });
      }
      const out = execFileSync('php', [preview, 'ajax', JSON.stringify(params), String(count)], { env: penv }).toString();
      return route.fulfill({ status: 200, contentType: 'application/json', body: out });
    }
    if (url.pathname === '/tables.html') { return route.fulfill({ status: 200, contentType: 'text/html', body: fs.readFileSync(file) }); }
    if (url.pathname.startsWith('/assets/')) {
      const target = path.join(root, url.pathname);
      if (target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) {
        return route.fulfill({ status: 200, contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) });
      }
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto('https://preview.test/tables.html#ghca-tab-employees');
  await page.evaluate(() => document.fonts.ready);
  const stateRead = () => (fs.existsSync(state) ? JSON.parse(fs.readFileSync(state, 'utf8')) : { communications: [], scheduled: [] });
  return { context, page, errors, log, stateRead };
}

const T = '#ghca-tab-employees';
const rowBox = (page, id) => page.locator(T + ' table [data-ghca-select][value="' + id + '"]');
const head = (page) => page.locator(T + ' [data-ghca-select-page]');
const bar = (page) => page.locator(T + ' [data-ghca-bulkbar]');
async function waitRefresh(page) { await page.waitForFunction(() => !document.querySelector('#ghca-tab-employees [data-ghca-table]').getAttribute('aria-busy')); await page.waitForTimeout(120); }

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // ---- Selection: explicit, page-only select all, indeterminate, survives paging and filtering.
  {
    const { context, page, errors, log } = await open(browser, 1440);
    assert.equal(await bar(page).isVisible(), false);
    await rowBox(page, 100).check();
    assert.equal(await bar(page).isVisible(), true);
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), '1 selected');
    assert.equal(await page.locator(T + ' [data-ghca-bulk-names]').innerText(), 'Alder Reyes');
    assert.equal(await page.locator(T + ' .gh-mcard [data-ghca-select][value="100"]').isChecked(), true);
    await rowBox(page, 101).check();
    assert.deepEqual(await head(page).evaluate((e) => [e.checked, e.indeterminate]), [false, true]);
    ok('a row checkbox shows the bar with count and names; the header is indeterminate when some rows on the page are selected; card checkboxes stay in step');
    await head(page).check();
    const onPage = await page.locator(T + ' table [data-ghca-select]').count();
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), onPage + ' selected');
    assert.deepEqual(await head(page).evaluate((e) => [e.checked, e.indeterminate]), [true, false]);
    assert.match(await page.locator(T + ' [data-ghca-bulk-names]').innerText(), / and \d+ more$/);
    ok('select all selects the ' + onPage + ' rows on this page only, never the other page');
    assert.equal(await page.locator(T + ' tr.is-selected').count(), onPage);
    const bg = await page.locator(T + ' tr.is-selected td').first().evaluate((e) => getComputedStyle(e).backgroundColor);
    assert.notEqual(bg, 'rgba(0, 0, 0, 0)');
    const barBg = await bar(page).evaluate((e) => getComputedStyle(e).backgroundColor);
    assert.equal(barBg, 'rgb(47, 93, 58)');
    ok('selected rows are highlighted; the bar uses the agency colour');

    await page.locator(T + ' [data-ghca-page="2"], ' + T + ' .gh-pager-btns button[aria-label="Next page"]').first().click();
    await waitRefresh(page);
    assert.deepEqual(await head(page).evaluate((e) => [e.checked, e.indeterminate]), [false, false]);
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), onPage + ' selected');
    assert.match(await page.locator(T + ' [data-ghca-bulk-hidden]').innerText(), new RegExp('^' + onPage + ' not shown with the current filters or page: '));
    assert.equal(await page.locator(T + ' [data-ghca-bulk-visible]').isVisible(), true);
    ok('after paging, the selection is kept and the bar says how many selected employees are not on screen, by name');
    const p2 = await page.locator(T + ' table [data-ghca-select]').first().getAttribute('value');
    await rowBox(page, p2).check();
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), (onPage + 1) + ' selected');
    await page.locator(T + ' [data-ghca-bulk-visible]').click();
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), '1 selected');
    assert.equal(await page.locator(T + ' [data-ghca-bulk-hidden]').isVisible(), false);
    ok('"Keep only those shown" drops every selected employee not on screen; nothing hidden stays selected');

    await page.fill(T + ' input[type="search"]', 'Ivy');
    await page.keyboard.press('Enter');
    await waitRefresh(page);
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), '1 selected');
    assert.match(await page.locator(T + ' [data-ghca-bulk-hidden]').innerText(), /^1 not shown/);
    ok('filtering keeps the selection and says the selected employee is hidden by the filter');
    await page.locator(T + ' [data-ghca-bulk-clear]').click();
    assert.equal(await bar(page).isVisible(), false);
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-ghca-select-page')), true);
    ok('clear selection hides the bar and returns focus to select all');
    assert.deepEqual(errors, []);
    assert.equal(log.bulk.length, 0);
    await context.close();
  }

  // ---- Export selected posts exactly the selected ids, with the export nonce.
  {
    const { context, page, log } = await open(browser, 1440);
    await rowBox(page, 105).check();
    await rowBox(page, 101).check();
    await Promise.all([page.waitForRequest('https://example.test/**'), page.locator(T + ' [data-ghca-bulk-export] button').click()]);
    assert.equal(log.exports.length, 1);
    assert.equal(log.exports[0].ghca_ids, '105,101');
    assert.ok(log.exports[0]._wpnonce);
    ok('Export selected posts the selected ids (105,101, in selection order) with the export nonce; the server re-checks scope');
    await context.close();
  }

  // ---- More than 25: sending is disabled and says why.
  {
    const { context, page } = await open(browser, 1440, { count: 30 });
    await head(page).check();
    await page.locator(T + ' .gh-pager-btns button[aria-label="Next page"]').click();
    await waitRefresh(page);
    await head(page).check();
    assert.equal(await page.locator(T + ' [data-ghca-bulk-count]').innerText(), '30 selected');
    assert.equal(await page.locator(T + ' [data-ghca-bulk-send]').isDisabled(), true);
    assert.equal(await page.locator(T + ' [data-ghca-bulk-send-label]').innerText(), 'Select 25 or fewer to send');
    assert.equal(await page.locator(T + ' [data-ghca-bulk-export] button').isEnabled(), true);
    ok('30 selected across two pages: Send is disabled with the 25 limit stated; Export selected stays available');
    await context.close();
  }

  // ---- Compose, review, send: keyboard, exact recipients, personalised previews, partial failure, retry, no duplicates.
  {
    const { context, page, errors, log, stateRead } = await open(browser, 1440, { delay: 300 });
    for (const id of [100, 101, 102, 103, 104]) { await rowBox(page, id).check(); }
    const send = page.locator(T + ' [data-ghca-bulk-send]');
    assert.equal(await send.innerText(), 'Send reminder to 5');
    await send.focus();
    await page.keyboard.press('Enter');
    const modal = page.locator('#ghca-acd-bulk-modal');
    await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="compose"]:not([hidden])');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-bulk-title');
    assert.match(await modal.locator('[data-ghca-bulk-who]').innerText(), /3 of 5 selected employees can receive an email/);
    assert.match(await modal.locator('[data-ghca-bulk-who]').innerText(), /Cedar Hill[\s\S]*Won’t be sent[\s\S]*valid email/);
    assert.match(await modal.locator('[data-ghca-bulk-who]').innerText(), /Employee #104[\s\S]*Invalid employee or permission denied/);
    assert.doesNotMatch(await modal.innerText(), /Elm Alvarez/);
    ok('compose: the server checked all 5; 2 cannot be sent, with reasons; the out-of-scope employee is not even named');
    assert.match(await modal.locator('.gh-bulk-chan').innerText(), /Email[\s\S]*SMS is not available for group reminders/);
    assert.equal(await modal.locator('input[type="radio"], [value="sms"]').count(), 0);
    ok('email only; SMS is stated as unavailable and cannot be chosen');

    await modal.locator('[data-ghca-bulk-template]').selectOption({ label: 'Overdue training' });
    assert.equal(await modal.locator('[data-ghca-bulk-subject]').inputValue(), 'Training overdue, [employee_first_name]');
    assert.equal(await modal.locator('[data-ghca-bulk-urgency]').inputValue(), 'important');
    await modal.locator('[data-ghca-bulk-subject]').fill('');
    await modal.locator('[data-ghca-bulk-next]').click();
    assert.match(await modal.locator('[data-ghca-bulk-error]').innerText(), /Enter a subject and a message/);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'ghca-bulk-subject');
    await page.keyboard.type('Training overdue, [employee_first_name]');
    await modal.locator('[data-ghca-bulk-next]').click();
    await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="review"]:not([hidden])');
    ok('a template fills subject, message and urgency; an empty subject is caught and focused');

    const rows = await modal.locator('[data-ghca-bulk-rows] tr').allInnerTexts();
    assert.equal(rows.length, 5);
    assert.equal(rows.filter((r) => /Will be sent/.test(r)).length, 3);
    assert.equal(rows.filter((r) => /Won’t be sent/.test(r)).length, 2);
    assert.ok(rows.every((r) => !/@example\.test/.test(r) || /\*\*\*@/.test(r)));
    await modal.locator('[data-ghca-bulk-pv-who]').selectOption({ label: 'Birch Okafor' });
    assert.match(await modal.locator('[data-ghca-bulk-pv-message]').innerText(), /^Hi Birch,/);
    assert.doesNotMatch(await modal.locator('[data-ghca-bulk-pv-message]').innerText(), /Alder/);
    assert.equal(await modal.locator('[data-ghca-bulk-pv-subject]').innerText(), 'IMPORTANT: Training overdue, Birch');
    ok('review lists all 5 recipients with masked addresses; the preview is that person\'s own message');
    const go = modal.locator('[data-ghca-bulk-go]');
    assert.equal(await go.isDisabled(), true);
    assert.equal(await go.innerText(), 'Send 3 emails');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="compose"]:not([hidden])');
    await modal.locator('[data-ghca-bulk-next]').click();
    await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="review"]:not([hidden])');
    ok('Send stays disabled until the review is confirmed; Escape on review goes back a step, not out');

    await modal.locator('[data-ghca-bulk-confirm]').check();
    assert.equal(await go.isEnabled(), true);
    const before = log.bulk.filter((p) => p.action === 'ghca_acd_bulk_message_send').length;
    await go.click();
    await go.click({ force: true }).catch(() => {});
    await page.keyboard.press('Enter');
    await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="result"]:not([hidden])');
    const sends = log.bulk.filter((p) => p.action === 'ghca_acd_bulk_message_send');
    assert.equal(sends.length - before, 1);
    assert.equal(sends[0].ids, '100,101,103');
    assert.equal(sends[0].reviewed, '1');
    ok('one send request despite repeated clicks; it carries only the 3 reviewed, ready recipients');

    // Dune Ellis (103): the message is saved, then scheduling its job fails (twice in this run).
    const summary = () => modal.locator('[data-ghca-bulk-summary]').innerText();
    assert.equal(await summary(), '2 of 3 queued. 1 saved but not scheduled (nothing sent).');
    let res = (await modal.locator('[data-ghca-bulk-results] li').allInnerTexts()).join('|');
    assert.match(res, /Alder Reyes[\s\S]*Queued/);
    assert.match(res, /Dune Ellis[\s\S]*Saved, not scheduled[\s\S]*Nothing was sent/);
    let st = stateRead();
    const comms = () => Object.values(stateRead().communications);
    const dly = (emp) => Object.values(stateRead().deliveries).filter((d) => d.employee_user_id === emp);
    const jobs = (id) => stateRead().cron.filter((e) => e[1][0] === id).length;
    assert.equal(comms().length, 3);
    assert.deepEqual(comms().map((c) => c.employee_user_id), [100, 101, 103]);
    assert.ok(comms().every((c) => c.deliveries.length === 1 && c.deliveries[0].channel === 'email'));
    assert.match(comms()[0].message, /^Hi Alder,/);
    assert.match(comms()[1].message, /^Hi Birch,/);
    const d103 = dly(103);
    assert.equal(d103.length, 1);
    assert.deepEqual([d103[0].state, d103[0].last_error_code, d103[0].attempt_count, jobs(d103[0].id)], ['failed', 'queue_schedule_failed', 0, 0]);
    assert.equal(jobs(dly(100)[0].id) + jobs(dly(101)[0].id), 2);
    ok('partial failure: 2 queued with one job each; Dune Ellis saved but not scheduled (no job, never attempted) and not counted as queued');

    const retry = modal.locator('[data-ghca-bulk-retry]');
    assert.equal(await retry.innerText(), 'Retry the 1 not queued');
    await retry.click();
    await page.waitForFunction(() => /still could not be scheduled/.test(document.querySelector('#ghca-acd-bulk-modal [data-ghca-bulk-results]').innerText));
    assert.equal(await summary(), '2 of 3 queued. 1 saved but not scheduled (nothing sent).');
    assert.deepEqual([comms().length, dly(103).length, dly(103)[0].state, jobs(dly(103)[0].id)], [3, 1, 'failed', 0]);
    ok('a retry that fails to schedule again says so; same record, still no job, still not counted as queued');

    await retry.click();
    await page.waitForFunction(() => /Queued now \(recovered\)/.test(document.querySelector('#ghca-acd-bulk-modal [data-ghca-bulk-results]').innerText));
    assert.equal(await summary(), '3 of 3 queued.');
    assert.equal(await retry.isHidden(), true);
    const after = dly(103);
    assert.deepEqual([comms().length, after.length, after[0].id, after[0].state, after[0].attempt_count, jobs(after[0].id)], [3, 1, d103[0].id, 'queued', 0, 1]);
    const retried = log.bulk.filter((p) => p.action === 'ghca_acd_bulk_message_send').pop();
    assert.equal(retried.ids, '103');
    assert.equal(retried.token, sends[0].token);
    ok('the next retry recovers the same record: one job, no new message; the total is now 3 of 3 queued and Retry disappears');

    await page.keyboard.press('Escape');
    assert.equal(await modal.isHidden(), true);
    assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-ghca-bulk-send')), true);
    ok('Escape closes the results; focus returns to the Send button');
    if (shots) {
      await page.screenshot({ path: path.join(shots, 'bulk-bar-1440.png') });
    }
    assert.deepEqual(errors, []);
    await context.close();
  }

  // ---- Without send permission: selection and export only.
  {
    const { context, page } = await open(browser, 1440, { perms: 'noremind' });
    await rowBox(page, 100).check();
    assert.equal(await page.locator(T + ' [data-ghca-bulk-send]').count(), 0);
    assert.equal(await page.locator('#ghca-acd-bulk-modal').count(), 0);
    assert.equal(await page.locator(T + ' [data-ghca-bulk-export] button').isVisible(), true);
    ok('no send-reminders permission: no Send button and no dialog; Export selected remains');
    await context.close();
  }

  // ---- Layouts.
  for (const [width, hostile] of [[1023, false], [834, false], [390, false], [390, true]]) {
    const { context, page, errors } = await open(browser, width, { hostile });
    const L = '@' + width + (hostile ? ' hostile' : '');
    if (width < 720) {
      const box = page.locator(T + ' .gh-mcard [data-ghca-select][value="100"]');
      const lab = await page.locator(T + ' .gh-mcard-sel').first().boundingBox();
      assert.ok(lab.width >= 43.5 && lab.height >= 43.5, 'card select target ' + JSON.stringify(lab));
      await box.check();
    } else {
      await rowBox(page, 100).check();
      await rowBox(page, 101).check();
    }
    assert.equal(await bar(page).isVisible(), true);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'sideways ' + L);
    const small = await page.evaluate(() => [...document.querySelectorAll('#ghca-tab-employees [data-ghca-bulkbar] .gh-btn')].filter((b) => b.offsetWidth > 0 && b.getBoundingClientRect().height < 43.5).map((b) => b.textContent.trim()));
    assert.deepEqual(small, []);
    if (shots && !hostile) { await page.screenshot({ path: path.join(shots, 'bulk-bar-' + width + '.png') }); }
    if (width === 390 && !hostile) {
      await page.locator(T + ' [data-ghca-bulk-send]').click();
      await page.waitForSelector('#ghca-acd-bulk-modal [data-ghca-bulk-step="compose"]:not([hidden])');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
      if (shots) { await page.screenshot({ path: path.join(shots, 'bulk-compose-390.png') }); }
    }
    ok(L + ': selection bar visible, no sideways scroll, bar buttons at least 44px' + (width < 720 ? ', card checkbox target 44px' : ''));
    assert.deepEqual(errors, []);
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' bulk selection and messaging browser checks (synthetic; mocked delivery)\n');
})().catch((e) => { console.error(e); process.exit(1); });
