'use strict';
/**
 * Page body background on theme-integrated plugin pages, read from rendered pixels. Pages come from
 * tests/render-page-background-preview.php: a stand-in classic theme (own body colour #fafbfd, a header with a
 * shadow, transparent 48px #content padding above and below) around the plugin's own markup. Synthetic: not
 * WordPress and not the Akansoh theme; no Additional CSS override is present.
 *
 *   node tests/browser-page-background.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-bg-'));
execFileSync('php', [path.join(__dirname, 'render-page-background-preview.php'), dir]);

const WARM = [243, 239, 234];   // #F3EFEA
const LEGACY = [236, 239, 243]; // #eceff3, dashboard.css body.ghca-acd-page
const THEME = [250, 251, 253];  // #fafbfd, the stand-in theme's body

(async () => {
  const browser = await chromium.launch();
  const decoder = await (await browser.newContext()).newPage();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

  // Rendered colour at points of a full-page screenshot.
  async function pixels(png, points) {
    return decoder.evaluate(async ({ b64, points }) => {
      const img = new Image();
      img.src = 'data:image/png;base64,' + b64;
      await img.decode();
      const c = document.createElement('canvas');
      c.width = img.width; c.height = img.height;
      const g = c.getContext('2d');
      g.drawImage(img, 0, 0);
      return points.map(([x, y]) => Array.from(g.getImageData(x, y, 1, 1).data.slice(0, 3)));
    }, { b64: png.toString('base64'), points });
  }

  async function measure(file, width) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto('file://' + path.join(dir, file));
    await page.evaluate(() => document.fonts.ready);
    const geo = await page.evaluate(() => {
      const hd = document.querySelector('#site-header').getBoundingClientRect();
      const ft = document.querySelector('#site-footer').getBoundingClientRect();
      const main = document.querySelector('#main').getBoundingClientRect();
      const cs = getComputedStyle(document.querySelector('#content'));
      return {
        headerBottom: Math.round(hd.bottom + window.scrollY), footerTop: Math.round(ft.top + window.scrollY),
        mainLeft: Math.round(main.left), mainRight: Math.round(main.right), mainMid: Math.round(main.top + window.scrollY + Math.min(200, main.height / 2)),
        padTop: cs.paddingTop, padBottom: cs.paddingBottom, contentBg: cs.backgroundColor,
        headerShadow: getComputedStyle(document.querySelector('#site-header')).boxShadow,
        bodyBg: getComputedStyle(document.body).backgroundColor,
        vw: document.documentElement.clientWidth, sw: document.documentElement.scrollWidth,
      };
    });
    const png = await page.screenshot({ fullPage: true });
    if (shots) { fs.writeFileSync(path.join(shots, file.replace('.html', '') + '-' + width + '.png'), png); }
    const cx = Math.round(geo.vw / 2);
    const pts = {
      topLeft: [6, geo.headerBottom + 30], topCentre: [cx, geo.headerBottom + 30], topRight: [geo.vw - 6, geo.headerBottom + 30],
      bottomLeft: [6, geo.footerTop - 12], bottomCentre: [cx, geo.footerTop - 12], bottomRight: [geo.vw - 6, geo.footerTop - 12],
    };
    const names = Object.keys(pts);
    const colours = await pixels(png, names.map((k) => pts[k]));
    const probes = file === 'on.html' ? await page.evaluate(() => ['#probe-card', '#probe-input', '#probe-btn', '.gh-card'].map((s) => getComputedStyle(document.querySelector(s)).backgroundColor)) : null;
    await ctx.close();
    return { geo, colours: Object.fromEntries(names.map((k, i) => [k, colours[i]])), probes };
  }

  const expectAll = (r, rgb, label) => {
    for (const [where, c] of Object.entries(r.colours)) { assert.deepEqual(c, rgb, label + ' ' + where + ' is ' + c); }
  };

  for (const width of [1440, 390]) {
    const on = await measure('on.html', width);
    expectAll(on, WARM, 'redesign on @' + width);
    ok('redesign on @' + width + ': top and bottom gutters (left, centre, right) render #F3EFEA');
    assert.equal(on.geo.bodyBg, 'rgb(243, 239, 234)');
    ok('redesign on @' + width + ': body background #F3EFEA without any Additional CSS or page-id rule');
    assert.equal(on.geo.padTop, '48px'); assert.equal(on.geo.padBottom, '48px'); assert.equal(on.geo.contentBg, 'rgba(0, 0, 0, 0)');
    ok('redesign on @' + width + ': theme #content padding (48px top and bottom) and transparency untouched');
    assert.ok(on.geo.sw <= on.geo.vw + 1);
    ok('redesign on @' + width + ': no sideways scroll');
    assert.equal(on.probes[0], 'rgb(255, 255, 255)'); assert.equal(on.probes[3], 'rgb(255, 255, 255)');
    assert.notEqual(on.probes[1], 'rgb(243, 239, 234)'); assert.notEqual(on.probes[2], 'rgb(243, 239, 234)');
    ok('redesign on @' + width + ': cards stay white; inputs and buttons keep their own colours');

    const off = await measure('off.html', width);
    expectAll(off, LEGACY, 'redesign off @' + width);
    assert.equal(off.geo.bodyBg, 'rgb(236, 239, 243)');
    ok('redesign off @' + width + ': legacy #eceff3 body and gutters, unchanged');
    assert.equal(off.geo.headerShadow, on.geo.headerShadow);
    assert.match(on.geo.headerShadow, /rgba\(18, 43, 70, 0\.12\)/);
    ok('@' + width + ': the theme header shadow is the same in both states');

    const unrelated = await measure('unrelated.html', width);
    expectAll(unrelated, THEME, 'unrelated @' + width);
    ok('unrelated page @' + width + ': theme body colour, even with both plugin stylesheets loaded');

    const bulletin = await measure('bulletin.html', width);
    expectAll(bulletin, WARM, 'bulletin @' + width);
    ok('bulletin page in theme @' + width + ': gutters render #F3EFEA');
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' page background browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
