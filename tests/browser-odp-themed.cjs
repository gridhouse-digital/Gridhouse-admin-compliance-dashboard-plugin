'use strict';
/**
 * Browser checks for the ODP bulletin library and page inside a WordPress theme (theme-header correction).
 * Pages come from tests/render-redesign-preview.php: the plugin's own GHCA_ODP_UI markup inside a stand-in
 * classic theme (own header, footer, 1100px container). Synthetic: not WordPress, not the staging theme.
 *
 *   node tests/browser-odp-themed.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const build = (dir, hostile) => { execFileSync('php', [path.join(__dirname, 'render-redesign-preview.php'), dir], { env: { ...process.env, GHCA_PREVIEW_HOSTILE: hostile ? '1' : '' } }); return dir; };

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-odp-'));
  const clean = build(path.join(tmp, 'clean'), false);
  const hostile = build(path.join(tmp, 'hostile'), true);

  const chromeStyle = (page) => page.evaluate(() => ['#site-header', '#site-footer', '.site-logo', '.site-nav a'].map((s) => { const c = getComputedStyle(document.querySelector(s)); return [c.fontFamily, c.fontSize, c.color, c.backgroundColor, c.padding, c.height].join('|'); }));

  for (const [w, h] of [[1440, 900], [390, 844]]) {
    for (const file of ['library-theme.html', 'bulletin-theme.html']) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: shots ? 2 : 1 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto('file://' + path.join(clean, file));
      await page.evaluate(() => document.fonts.ready);
      const label = file + ' @' + w;

      const m = await page.evaluate(() => {
        const app = document.querySelector('.gh-odp-host .gh-app');
        const r = app.getBoundingClientRect();
        return {
          headers: document.querySelectorAll('.gh-appbar, .gh-logo, .gh-nav').length,
          site: document.querySelectorAll('#site-header').length,
          bg: getComputedStyle(app).backgroundColor,
          left: Math.round(r.left), width: Math.round(r.width), vw: document.documentElement.clientWidth,
          scroll: document.documentElement.scrollWidth,
          below: document.querySelector('#site-header').getBoundingClientRect().bottom <= r.top + 1,
          above: r.bottom <= document.querySelector('#site-footer').getBoundingClientRect().top + 1,
          h1: getComputedStyle(document.querySelector('.gh-odp-main h1, .gh-odp-main .gh-h1, .gh-odp-main .gh-h2')).fontFamily,
          themeFont: getComputedStyle(document.querySelector('#site-header')).fontFamily,
          crumb: !!document.querySelector('.gh-odp-main a[href="overview.html"]'),
          mains: document.querySelectorAll('main').length,
        };
      });
      assert.equal(m.headers, 0, label + ': no plugin app bar, logo or nav');
      assert.equal(m.site, 1);
      ok(label + ': only the theme header');
      assert.equal(m.bg, 'rgb(243, 239, 234)');
      assert.equal(m.left, 0);
      assert.equal(m.width, m.vw);
      ok(label + ': dashboard background #F3EFEA, full width out of the theme container');
      assert.ok(m.scroll <= m.vw + 1, label + ' scrollWidth ' + m.scroll);
      ok(label + ': no sideways scroll');
      assert.ok(m.below && m.above);
      ok(label + ': content between the theme header and footer');
      assert.match(m.h1, /Wix Madefor/);
      assert.match(m.themeFont, /Georgia/);
      ok(label + ': content in Wix Madefor; the theme keeps its own font');
      assert.ok(m.crumb);
      assert.equal(m.mains, 1);
      ok(label + ': breadcrumb back to the dashboard; one main landmark');

      // The plugin stylesheet does not restyle the theme's header or footer.
      const withCss = await chromeStyle(page);
      await page.evaluate(() => document.querySelector('link[rel="stylesheet"]').remove());
      assert.deepEqual(await chromeStyle(page), withCss);
      ok(label + ': theme header and footer unchanged by the plugin stylesheet');
      await page.reload();
      await page.evaluate(() => document.fonts.ready);

      if (file === 'library-theme.html') {
        const opener = page.locator('[data-gh-open-bulletin]').first();
        await opener.click();
        await page.waitForSelector('#gh-odp-drawer:not([hidden])');
        assert.match(await page.locator('#gh-odp-drawer-title').innerText(), /Synthetic headline/);
        const z = await page.evaluate(() => { const r = document.querySelector('#gh-odp-drawer .gh-drawer').getBoundingClientRect(); return { top: Math.round(r.top), right: Math.round(window.innerWidth - r.right) }; });
        assert.equal(z.top, 0);
        ok(label + ': bulletin drawer opens over the themed page, pinned to the viewport');
        await page.keyboard.press('Escape');
        await page.waitForSelector('#gh-odp-drawer', { state: 'hidden' });
        ok(label + ': Escape closes the drawer');
      }
      assert.deepEqual(errors, []);
      if (shots) { await page.screenshot({ path: path.join(shots, file.replace('.html', '') + '-' + w + '.png'), fullPage: true }); }
      await ctx.close();
    }
  }

  // Hostile theme rules (forced link colour, heading case, main padding/background, list margins).
  {
    const read = async (dir) => {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const page = await ctx.newPage();
      await page.goto('file://' + path.join(dir, 'bulletin-theme.html'));
      await page.evaluate(() => document.fonts.ready);
      const v = await page.evaluate(() => ['.gh-odp-main', '.gh-odp-main .gh-h1, .gh-odp-main h1', '.gh-odp-main a', '.gh-odp-main p', '.gh-odp-host .gh-app'].map((s) => { const el = document.querySelector(s); const c = getComputedStyle(el); return [c.fontFamily, c.fontSize, c.textTransform, c.color, c.backgroundColor, c.textDecorationLine, c.paddingTop, c.paddingLeft, c.marginTop].join('|'); }));
      await ctx.close();
      return v;
    };
    const a = await read(clean);
    const b = await read(hostile);
    a.forEach((v, i) => assert.equal(b[i], v, 'component ' + i + ' differs under the hostile theme'));
    ok('hostile theme: bulletin page main, heading, link, paragraph and surface unchanged');
    const readLib = async (dir) => {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const page = await ctx.newPage();
      await page.goto('file://' + path.join(dir, 'library-theme.html'));
      await page.evaluate(() => document.fonts.ready);
      const v = await page.evaluate(() => ['.gh-odp-main', '.gh-odp-hd h1', '.gh-bl', '.gh-bl > li', '.gh-bl-h', '.gh-bl-h a', '.gh-crumbs a', '.gh-odp-main input', '.gh-bl-read'].map((s) => { const el = document.querySelector(s); if (!el) { return s + ':absent'; } const c = getComputedStyle(el); const r = el.getBoundingClientRect(); return [s, c.fontFamily, c.fontSize, c.textTransform, c.color, c.backgroundColor, c.textDecorationLine, c.padding, c.margin, c.listStyleType, Math.round(r.width), Math.round(r.height)].join('|'); }));
      await ctx.close();
      return v;
    };
    const la = await readLib(clean);
    const lb = await readLib(hostile);
    la.forEach((v, i) => assert.equal(lb[i], v, 'library component differs under the hostile theme'));
    ok('hostile theme: library title, list, cards, links, search and buttons unchanged');
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' themed ODP browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
