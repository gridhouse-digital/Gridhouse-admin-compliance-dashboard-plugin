'use strict';
/**
 * Browser checks for the ODP bulletin pages through the real page path. Every request to the stand-in site
 * (https://staging.test/odp-bulletins/…) runs tests/odp-page-runner.php, i.e. the plugin's own render_screen():
 * routing, login redirect, audience, feed read and the in-theme wrapper. The theme is a stand-in classic theme
 * (own header with a shadow, own footer, transparent 48px content padding, blue-grey body). Plugin assets are
 * served from the repository. Synthetic: not WordPress, not BuddyBoss, not the Akansoh site; no network.
 *
 *   node tests/browser-odp-pages.cjs [screenshot-dir]
 */
const assert = require('node:assert/strict');
const { execFileSync, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const shots = process.argv[2] || '';
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const PLUGIN = '/wp-content/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/';

function site(scenario) {
  return async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith(PLUGIN)) {
      const file = path.join(root, url.pathname.slice(PLUGIN.length));
      if (file.startsWith(root) && fs.existsSync(file)) {
        return route.fulfill({ status: 200, contentType: MIME[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
      }
      return route.fulfill({ status: 404, body: '' });
    }
    if (url.pathname.startsWith('/wp-login.php')) {
      return route.fulfill({ status: 200, contentType: 'text/html', body: '<!DOCTYPE html><title>Log in</title><h1 id="login">Log in</h1>' });
    }
    const get = Object.fromEntries(url.searchParams);
    const run = spawnSync('php', [path.join(__dirname, 'odp-page-runner.php'), JSON.stringify({ ...scenario, uri: url.pathname + url.search, get })]);
    const err = run.stderr.toString();
    const rec = JSON.parse(err.slice(err.indexOf('@@RECORD@@') + 10).trim());
    if (rec.redirect) {
      // WordPress's auth_redirect() would answer 302 to the login page; Playwright cannot follow a redirect from an
      // intercepted response, so the stand-in site shows what was requested instead. The plugin's part (calling
      // auth_redirect() before reading or printing anything) is asserted in tests/test-odp-pages.php.
      return route.fulfill({ status: 200, contentType: 'text/html', body: '<!DOCTYPE html><title>Redirect</title><p id="login-redirect">' + rec.redirect.replace(/[<&]/g, '') + '</p>' + run.stdout });
    }
    return route.fulfill({ status: rec.status || 200, contentType: 'text/html', body: run.stdout });
  };
}

async function open(browser, width, scenario, pathName) {
  const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: shots ? 2 : 1 });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await context.route('https://staging.test/**', site(scenario));
  await page.goto('https://staging.test' + pathName);
  await page.evaluate(() => document.fonts.ready);
  return { context, page, errors };
}

const gutter = (page) => page.evaluate(() => {
  // Body colour where the theme padding is transparent: just under the header and just above the footer.
  const at = (y) => { let el = document.elementFromPoint(6, y); while (el && getComputedStyle(el).backgroundColor === 'rgba(0, 0, 0, 0)') { el = el.parentElement; } return el ? getComputedStyle(el).backgroundColor : null; };
  const h = document.querySelector('#masthead').getBoundingClientRect().bottom;
  return at(h + 20);
});

(async () => {
  const browser = await chromium.launch();
  let n = 0;
  const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };
  const agency = { logged_in: true, can_view: true, feed: 'fresh' };

  for (const width of [1440, 390]) {
    const { context, page, errors } = await open(browser, width, agency, '/odp-bulletins/');
    const L = 'library @' + width;
    const one = await page.evaluate(() => ({ h: document.querySelectorAll('#masthead').length, f: document.querySelectorAll('#colophon').length, plugin: document.querySelectorAll('.gh-appbar, .gh-logo, .gh-skip').length, headers: document.querySelectorAll('body > header, .site-header').length }));
    assert.deepEqual(one, { h: 1, f: 1, plugin: 0, headers: 1 });
    ok(L + ': exactly one theme header and footer, no plugin chrome');
    assert.equal(await gutter(page), 'rgb(243, 239, 234)');
    const surface = await page.evaluate(() => getComputedStyle(document.querySelector('.gh-odp-host .gh-app')).backgroundColor);
    assert.equal(surface, 'rgb(243, 239, 234)');
    ok(L + ': #F3EFEA in the exposed theme padding and behind the content');
    const cards = await page.evaluate(() => [...document.querySelectorAll('.gh-odp-main .gh-bl, .gh-odp-main .gh-odp-about, .gh-odp-main .gh-input')].map((e) => getComputedStyle(e).backgroundColor));
    assert.ok(cards.length >= 3 && cards.every((c) => c === 'rgb(255, 255, 255)'), 'cards: ' + cards.join(','));
    ok(L + ': bulletin list card, about cards and search input stay white');
    const fonts = await page.evaluate(() => ({ h1: getComputedStyle(document.querySelector('.gh-odp-hd h1')).fontFamily, body: getComputedStyle(document.querySelector('.gh-odp-main p')).fontFamily, theme: getComputedStyle(document.querySelector('#masthead')).fontFamily }));
    assert.match(fonts.h1, /^"Wix Madefor Display"/); assert.match(fonts.body, /^"Wix Madefor Text"/); assert.match(fonts.theme, /Georgia/);
    ok(L + ': Wix Madefor in the bulletin content; the theme header keeps its own font');
    const themed = await page.evaluate(() => ['#masthead', '#masthead a', '#colophon', '#content'].map((s) => { const c = getComputedStyle(document.querySelector(s)); return [c.fontFamily, c.fontSize, c.color, c.backgroundColor, c.padding, c.boxShadow, c.textDecorationLine].join('|'); }));
    await page.evaluate(() => document.querySelectorAll('link[href*="gh-redesign.css"]').forEach((l) => l.remove()));
    const bare = await page.evaluate(() => ['#masthead', '#masthead a', '#colophon', '#content'].map((s) => { const c = getComputedStyle(document.querySelector(s)); return [c.fontFamily, c.fontSize, c.color, c.backgroundColor, c.padding, c.boxShadow, c.textDecorationLine].join('|'); }));
    assert.deepEqual(themed, bare);
    ok(L + ': the plugin stylesheet does not restyle the theme header, links, footer or content padding');
    await page.reload();
    await page.evaluate(() => document.fonts.ready);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    ok(L + ': no sideways scroll');
    if (shots) { await page.screenshot({ path: path.join(shots, 'library-' + width + '.png'), fullPage: true }); }

    // Library to detail and back, through the real route.
    await Promise.all([page.waitForURL('**/odp-bulletins/synthetic-both/'), page.click('.gh-bl-h a[href$="/odp-bulletins/synthetic-both/"]')]);
    assert.match(await page.locator('.gh-bp-h').innerText(), /Synthetic bulletin for everyone/);
    assert.equal(await page.locator('#masthead').count(), 1);
    assert.equal(await page.locator('#colophon').count(), 1);
    assert.equal(await gutter(page), 'rgb(243, 239, 234)');
    ok('detail @' + width + ': reached from the library card; one theme header and footer; #F3EFEA');
    if (shots) { await page.screenshot({ path: path.join(shots, 'detail-' + width + '.png'), fullPage: true }); }
    await Promise.all([page.waitForURL('https://staging.test/odp-bulletins/'), page.locator('.gh-odp-main a[href="https://staging.test/odp-bulletins/"]').first().click()]);
    assert.ok(await page.locator('.gh-bl').count() === 1);
    ok('detail @' + width + ': back to the library');
    assert.deepEqual(errors, []);
    ok('@' + width + ': no script errors');
    await context.close();
  }

  // Drawer from the library card.
  {
    const { context, page } = await open(browser, 1440, agency, '/odp-bulletins/');
    await page.locator('[data-gh-open-bulletin]').first().click();
    await page.waitForSelector('#gh-odp-drawer:not([hidden])');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#gh-odp-drawer', { state: 'hidden' });
    ok('library: the bulletin drawer opens and closes inside the themed page');
    await context.close();
  }

  // Employee audience.
  {
    const { context, page } = await open(browser, 1440, { logged_in: true, can_view: false, feed: 'fresh' }, '/odp-bulletins/');
    const text = await page.locator('.gh-odp-main').innerText();
    assert.match(text, /Synthetic bulletin for employees/); assert.doesNotMatch(text, /Synthetic bulletin for agency leads/);
    ok('employee: employee and shared bulletins only');
    await context.close();
  }

  // Signed out: sent to log in, no bulletin content.
  for (const p of ['/odp-bulletins/', '/odp-bulletins/synthetic-both/']) {
    const { context, page } = await open(browser, 390, { logged_in: false, can_view: false, feed: 'fresh' }, p);
    assert.match(await page.locator('#login-redirect').innerText(), new RegExp('^wp-login\\.php\\?redirect_to=' + encodeURIComponent(p).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));
    assert.equal(await page.locator('.gh-odp-main, #masthead, .gh-bl').count(), 0);
    assert.doesNotMatch(await page.content(), /Synthetic bulletin/);
    ok('signed out ' + p + ': login redirect requested (back to this URL); no bulletin content or page reached the browser');
    await context.close();
  }

  // Feed blocked by the outbound guard: unavailable state inside the theme, desktop and phone.
  for (const width of [1440, 390]) {
    const { context, page } = await open(browser, width, { logged_in: true, can_view: true, feed: 'none' }, '/odp-bulletins/');
    assert.equal(await page.locator('#masthead').count(), 1);
    assert.equal(await page.locator('#colophon').count(), 1);
    assert.match(await page.locator('.gh-odp-main').innerText(), /unavailable|can.t|could not/i);
    assert.equal(await page.locator('.gh-bl-h a').count(), 0);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    ok('blocked feed @' + width + ': unavailable state inside the theme, no bulletins, no sideways scroll');
    if (shots && width === 1440) { await page.screenshot({ path: path.join(shots, 'unavailable-1440.png'), fullPage: true }); }
    await context.close();
  }

  await browser.close();
  process.stdout.write('PASS: ' + n + ' ODP page browser checks\n');
})().catch((e) => { console.error(e); process.exit(1); });
