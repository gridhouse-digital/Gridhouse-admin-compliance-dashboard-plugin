'use strict';
/**
 * Browser checks for the post-audit corrections, in the Akansoh theme with an agency brand colour (#2f5d3a):
 * Compliance health "Not started", the Overdue KPI tile (always the light-red danger tile, real count, opens the
 * Overdue tab), the phone Overview values from the M-Admin-Overview board, 44px minimum controls at tablet widths
 * (720–1023px) without shrinking anything, agency-brand menu links with hover and keyboard focus, and the bulletin
 * disclaimer spacing. Pages come from the plugin's own preview scripts on synthetic data: not WordPress, not the
 * Akansoh site.
 *
 *   node tests/browser-corrections.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const BRAND = '#2f5d3a';
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-corr-'));
const env = { ...process.env, GHCA_PREVIEW_ASSETS: 'https://p.test/assets', GHCA_PREVIEW_THEME: 'akansoh', GHCA_PREVIEW_BRAND: BRAND, GHCA_PREVIEW_ENV: '{}' };
const dirs = { main: path.join(tmp, 'main'), zero: path.join(tmp, 'zero') };
execFileSync('php', [path.join(__dirname, 'render-redesign-preview.php'), dirs.main], { env });
execFileSync('php', [path.join(__dirname, 'render-redesign-preview.php'), dirs.zero], { env: { ...env, GHCA_PREVIEW_ZERO_OVERDUE: '1' } });
execFileSync('php', [path.join(__dirname, 'render-tables-preview.php'), 'page', dirs.main, 'akansoh'], { env });
execFileSync('php', [path.join(__dirname, 'render-lists-preview.php'), 'page', dirs.main, 'akansoh'], { env });
execFileSync('php', [path.join(__dirname, 'render-drawer-preview.php'), 'page', dirs.main, 'akansoh', ''], { env });
for (const d of Object.values(dirs)) {
  for (const f of fs.readdirSync(d).filter((x) => x.endsWith('.html'))) {
    const p = path.join(d, f);
    fs.writeFileSync(p, fs.readFileSync(p, 'utf8').split(root + '/assets').join('https://p.test/assets').replace(/https:\/\/(preview|example)\.test\/ajax/g, 'https://p.test/ajax'));
  }
}

async function route(r) {
  const u = new URL(r.request().url());
  if (u.pathname.startsWith('/assets/')) { const t = path.join(root, u.pathname); if (fs.existsSync(t)) { return r.fulfill({ body: fs.readFileSync(t), contentType: MIME[path.extname(t)] || 'application/octet-stream' }); } }
  const m = u.pathname.match(/^\/(main|zero)\/(.+\.html)$/);
  if (m) { return r.fulfill({ body: fs.readFileSync(path.join(dirs[m[1]], m[2])), contentType: 'text/html' }); }
  if (u.pathname === '/ajax') {
    const body = r.request().postData() || ''; const params = Object.fromEntries(new URLSearchParams(body));
    if (!params.action) { const mm = body.match(/name="action"\r\n\r\n([^\r]+)/); if (mm) { params.action = mm[1]; } }
    if (params.action === 'ghca_acd_get_employee_drawer') { delete params.action; return r.fulfill({ contentType: 'application/json', body: execFileSync('php', [path.join(__dirname, 'render-drawer-preview.php'), 'ajax', JSON.stringify(params), '{}']).toString() }); }
    if (params.action === 'ghca_acd_filter_table') { const t = /employees/.test(params.ghca_table) ? 'tables' : 'lists'; return r.fulfill({ contentType: 'application/json', body: execFileSync('php', [path.join(__dirname, 'render-' + t + '-preview.php'), 'ajax', JSON.stringify(params)]).toString() }); }
    return r.fulfill({ contentType: 'application/json', body: '{"success":false,"data":{}}' });
  }
  return r.fulfill({ status: 404, body: '' });
}
async function open(browser, width, file, prep) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 } });
  await ctx.route('https://p.test/**', route);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto('https://p.test/' + file);
  await page.evaluate(() => document.fonts.ready);
  if (prep) { await prep(page); }
  return { ctx, page, errors };
}
const lum = (rgb) => { const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // ---- Compliance health and the Overdue tile, desktop, with and without overdue employees.
  const tile = (page) => page.evaluate(() => {
    const a = [...document.querySelectorAll('.gh-kpi--overview a.gh-stat')].find((e) => /Overdue/.test(e.textContent));
    const c = (el) => getComputedStyle(el);
    return { cls: a.className, href: a.getAttribute('href'), jump: a.getAttribute('data-ghca-tab-jump'), value: a.querySelector('.gh-stat-val').textContent, bg: c(a).backgroundColor, label: c(a.querySelector('.gh-stat-top')).color, num: c(a.querySelector('.gh-stat-val')).color, icon: c(a.querySelector('.gh-stat-ico')).color };
  });
  const main = await open(browser, 1440, 'main/overview.html');
  const legend = await main.page.$$eval('.gh-legend li', (lis) => lis.map((li) => [li.childNodes[1].textContent.trim(), Number(li.querySelector('strong').textContent)]));
  assert.deepEqual(legend.map((x) => x[0]), ['Compliant', 'Expiring soon', 'In progress', 'Overdue', 'Not started']);
  assert.equal(legend.reduce((s, x) => s + x[1], 0), 12);
  assert.match(await main.page.locator('.gh-health-ft').innerText(), /12 employees in scope/);
  assert.equal(await main.page.locator('.gh-donut-c strong').innerText(), '50%');
  ok('health legend: Compliant, Expiring soon, In progress, Overdue, Not started; rows add up to the 12 in scope; rate unchanged (50%)');
  const t2 = await tile(main.page);
  const zero = await open(browser, 1440, 'zero/overview.html');
  const t0 = await tile(zero.page);
  assert.equal(t2.value, '2'); assert.equal(t0.value, '0');
  for (const t of [t2, t0]) {
    assert.match(t.cls, /gh-stat--danger/);
    assert.equal(t.href, '#ghca-tab-overdue'); assert.equal(t.jump, 'ghca-tab-overdue');
  }
  assert.deepEqual([t0.bg, t0.label, t0.num, t0.icon], [t2.bg, t2.label, t2.num, t2.icon]);
  assert.notEqual(t0.bg, 'rgba(0, 0, 0, 0)');
  const danger = await main.page.evaluate(() => { const s = getComputedStyle(document.querySelector('.gh-app')); return { bg: s.getPropertyValue('--gh-status-danger-bg').trim(), fg: s.getPropertyValue('--gh-status-danger-fg').trim() }; });
  const tok = await main.page.evaluate((d) => { const p = document.createElement('i'); p.style.color = d.fg; p.style.backgroundColor = d.bg; document.querySelector('.gh-app').appendChild(p); const c = getComputedStyle(p); return { fg: c.color, bg: c.backgroundColor }; }, danger);
  assert.equal(t0.num, tok.fg); assert.equal(t0.label, tok.fg); assert.equal(t0.icon, tok.fg);
  ok('Overdue tile: danger tokens (light-red surface; red label, number and icon) at 2 and at 0; real count; links to the Overdue tab');
  assert.ok(contrast(t0.num, t0.bg === 'rgba(0, 0, 0, 0)' ? 'rgb(255, 255, 255)' : t0.bg) >= 4.5);
  ok('Overdue tile: number contrast ≥ 4.5:1 on its surface');
  if (shots) { await zero.page.locator('.gh-kpi--overview').screenshot({ path: path.join(shots, 'kpi-zero-overdue-1440.png') }); }
  await main.ctx.close(); await zero.ctx.close();

  // Clicking the tile opens the Overdue tab (dashboard script on the lists preview).
  {
    const { ctx, page, errors } = await open(browser, 1440, 'main/lists.html');
    await page.locator('.gh-kpi--overview a.gh-stat--danger').click();
    assert.equal(await page.getAttribute('[data-ghca-tab-target="ghca-tab-overdue"]', 'aria-selected'), 'true');
    assert.ok(await page.locator('#ghca-tab-overdue').isVisible());
    assert.deepEqual(errors, []);
    ok('Overdue tile click opens the Overdue tab');
    await ctx.close();
  }

  // ---- Tab renamed "Overdue & at risk": accessible name, desktop and phone, same routing.
  for (const w of [1440, 390]) {
    const { ctx, page } = await open(browser, w, 'main/lists.html');
    const tabEl = page.getByRole('tab', { name: /^Overdue & at risk/ });
    assert.equal(await tabEl.count(), 1);
    assert.equal(await page.getByRole('tab', { name: /^Overdue$|^Overdue \d/ }).count(), 0);
    await tabEl.click();
    assert.equal(await tabEl.getAttribute('aria-selected'), 'true');
    assert.equal(await tabEl.getAttribute('aria-controls'), 'ghca-tab-overdue');
    assert.ok(await page.locator('#ghca-tab-overdue').isVisible());
    assert.equal(await page.locator('#ghca-tab-overdue').getAttribute('aria-labelledby'), 'ghca-tab-overdue-tab');
    ok('@' + w + ': tab "Overdue & at risk" (accessible name) selects and shows the same Overdue panel');
    await ctx.close();
  }

  // ---- Phone Overview (M-Admin-Overview values).
  {
    const { ctx, page } = await open(browser, 390, 'main/overview.html');
    const v = await page.evaluate(() => {
      const c = (s) => getComputedStyle(document.querySelector(s));
      return { base: c('.gh-ov').fontSize, cols: c('.gh-kpi--overview').gridTemplateColumns.split(' ').length, val: c('.gh-kpi--overview .gh-stat-val').fontSize + '/' + c('.gh-kpi--overview .gh-stat-val').lineHeight, tile: c('.gh-kpi--overview .gh-stat').padding, plain: c('.gh-ovp .gh-card-hd--plain').padding, rule: c('.gh-ovp .gh-card-hd--rule').paddingTop + ' ' + c('.gh-ovp .gh-card-hd--rule').borderBottomWidth, h3: c('.gh-ovp .gh-h3').letterSpacing, sw: document.documentElement.scrollWidth };
    });
    assert.deepEqual(v, { base: '15px', cols: 2, val: '24px/30px', tile: '12px 14px 14px', plain: '14px 20px 2px', rule: '14px 1px', h3: '-0.192px', sw: 390 });
    ok('phone Overview: 15px base, two KPI columns, 24/30 values, 12/14/14 tiles, plain header 14/20/2, divided header keeps its divider, -0.012em titles, no sideways scroll');
    if (shots) { await page.screenshot({ path: path.join(shots, 'overview-390.png'), fullPage: true }); }
    await ctx.close();
    const desk = await open(browser, 1440, 'main/overview.html');
    const d = await desk.page.evaluate(() => ({ base: getComputedStyle(document.querySelector('.gh-ov')).fontSize, val: getComputedStyle(document.querySelector('.gh-kpi--overview .gh-stat-val')).fontSize }));
    assert.deepEqual(d, { base: '14px', val: '28px' });
    ok('desktop Overview unchanged by the phone rules');
    await desk.ctx.close();
  }

  // ---- Tablet (720–1023): 44px minimum on every control; nothing shrinks; desktop and phone unchanged.
  const heights = (page, scope) => page.evaluate((scope) => [...document.querySelectorAll(scope.split(',').map((s) => s.trim() + ' :is(.gh-btn, .gh-input, .gh-select, .gh-tab, .gh-subtab, .gh-pager-btns button, .gh-pager-btns a)').join(', '))].filter((e) => e.getBoundingClientRect().width > 0).map((e) => ({ c: e.className.split(' ').slice(0, 2).join('.'), h: Math.round(e.getBoundingClientRect().height) })), scope);
  for (const [file, tabId] of [['main/tables.html', 'ghca-tab-employees'], ['main/lists.html', 'ghca-tab-overdue'], ['main/lists.html', 'ghca-tab-courses'], ['main/lists.html', 'ghca-tab-certificates']]) {
    const sizes = {};
    for (const w of [720, 834, 1023, 1024, 1440]) {
      const { ctx, page } = await open(browser, w, file, async (p) => { await p.click('[data-ghca-tab-target="' + tabId + '"]'); });
      sizes[w] = await heights(page, '#' + tabId + ', .gh-tabs');
      await ctx.close();
    }
    for (const w of [720, 834, 1023]) {
      const small = sizes[w].filter((x) => x.h < 44);
      assert.equal(small.length, 0, tabId + ' @' + w + ': ' + JSON.stringify(small.slice(0, 3)));
      sizes[w].forEach((x, i) => { if (sizes[1440][i] && sizes[1440][i].c === x.c) { assert.ok(x.h >= sizes[1440][i].h, 'shrunk ' + x.c); } });
    }
    assert.ok(sizes[1024].some((x) => x.h < 44), tabId + ': desktop sizes kept from 1024px');
    ok(tabId + ': every control ≥ 44px at 720, 834 and 1023px (' + sizes[834].length + ' controls), none smaller than on desktop; desktop sizes from 1024px');
  }
  {
    const { ctx, page } = await open(browser, 834, 'main/drawer.html', async (p) => { await p.click('[data-ghca-tab-target="ghca-tab-employees"]'); await p.click('#open-drawer'); await p.waitForSelector('.gh-drawer--emp .gh-drh-tabs', { state: 'visible' }); });
    const small = (await heights(page, '.gh-drawer--emp')).filter((x) => x.h < 44);
    assert.deepEqual(small, []);
    ok('employee drawer @834: every control ≥ 44px');
    await ctx.close();
  }

  // ---- Menu links in the agency brand, with hover and keyboard focus (drawer More menu and Overdue row menu).
  const menuCheck = async (page, summarySel, label) => {
    await page.click(summarySel);
    const link = page.locator(summarySel.replace(/ > summary$/, '') + ' a.gh-menu-i').first();
    const before = await link.evaluate((e) => ({ color: getComputedStyle(e).color, bg: getComputedStyle(e).backgroundColor }));
    const ink = await page.evaluate(() => { const i = document.createElement('i'); i.style.color = getComputedStyle(document.querySelector('.gh-app')).getPropertyValue('--gh-agency-ink'); document.querySelector('.gh-app').appendChild(i); return getComputedStyle(i).color; });
    assert.equal(before.color, ink);
    assert.equal(ink, 'rgb(47, 93, 58)');
    assert.notEqual(before.color, 'rgb(60, 88, 116)');
    await link.hover();
    const hover = await link.evaluate((e) => ({ color: getComputedStyle(e).color, bg: getComputedStyle(e).backgroundColor, deco: getComputedStyle(e).textDecorationLine }));
    assert.equal(hover.color, ink); assert.notEqual(hover.bg, 'rgba(0, 0, 0, 0)'); assert.equal(hover.deco, 'underline');
    assert.ok(contrast(hover.color, hover.bg) >= 4.5, 'hover contrast ' + contrast(hover.color, hover.bg));
    assert.ok(contrast(before.color, 'rgb(255, 255, 255)') >= 4.5);
    await page.mouse.move(0, 0);
    await link.focus();
    await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');
    const focus = await link.evaluate((e) => ({ is: document.activeElement === e, ow: getComputedStyle(e).outlineWidth, os: getComputedStyle(e).outlineStyle, oc: getComputedStyle(e).outlineColor, bg: getComputedStyle(e).backgroundColor }));
    assert.ok(focus.is); assert.equal(focus.ow, '2px'); assert.equal(focus.os, 'solid'); assert.equal(focus.oc, ink); assert.notEqual(focus.bg, 'rgba(0, 0, 0, 0)');
    assert.ok(contrast(focus.oc, 'rgb(255, 255, 255)') >= 3);
    ok(label + ': agency ink (not fixed blue), AA on white and on the hover tint; hover tint + underline; keyboard focus ring 2px in the agency ink');
  };
  {
    const { ctx, page } = await open(browser, 1440, 'main/drawer.html', async (p) => { await p.click('[data-ghca-tab-target="ghca-tab-employees"]'); await p.click('#open-drawer'); await p.waitForSelector('.gh-drawer--emp .gh-dr-more', { state: 'visible' }); });
    await menuCheck(page, '.gh-drawer--emp .gh-dr-more > summary', 'drawer More menu link');
    const btn = page.locator('.gh-drawer--emp .gh-dr-more button.gh-menu-i').first();
    if (await btn.count()) {
      assert.equal(await btn.evaluate((e) => getComputedStyle(e).color), await page.evaluate(() => getComputedStyle(document.querySelector('.gh-drawer--emp')).color));
      ok('drawer More menu buttons keep the text colour');
    }
    if (shots) { await page.screenshot({ path: path.join(shots, 'drawer-menu-1440.png') }); }
    await ctx.close();
  }
  {
    const { ctx, page } = await open(browser, 1440, 'main/lists.html', async (p) => { await p.click('[data-ghca-tab-target="ghca-tab-overdue"]'); });
    await menuCheck(page, '#ghca-tab-overdue tbody tr:first-child .gh-row-more > summary', 'Overdue row menu link');
    await ctx.close();
  }

  // ---- Bulletin disclaimer spacing (ODP-Page board: the paragraph's own 1em below it).
  {
    const { ctx, page } = await open(browser, 1440, 'main/bulletin.html');
    const m = await page.evaluate(() => { const c = getComputedStyle(document.querySelector('.gh-bp-note')); return [c.marginBottom, c.fontSize]; });
    assert.equal(m[0], m[1] === '13.5px' ? '13.5px' : m[1]);
    ok('bulletin disclaimer keeps 1em (' + m[0] + ') below it');
    await ctx.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' correction browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
