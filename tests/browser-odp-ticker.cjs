'use strict';
/**
 * ODP ticker states on the redesigned Overview (synthetic preview page: the plugin's own PHP; nothing fetched or
 * published): empty ("No ODP bulletins right now."), unavailable, cached with nothing saved, and populated (one and
 * two synthetic items), at desktop, tablet and phone widths. Chromium only.
 *
 *   node tests/browser-odp-ticker.cjs [screenshot-dir]
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
let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };

const STATES = {
  empty: { msg: 'No ODP bulletins right now.' },
  unavailable: { msg: 'ODP bulletins are unavailable right now.' },
  'cached-empty': { msg: 'Showing the last bulletin feed that could be verified. A newer copy could not be loaded.', state: 'cached' },
  one: { items: 1 },
  two: { items: 2 },
};

(async () => {
  const browser = await chromium.launch();
  for (const [mode, want] of Object.entries(STATES)) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ghca-ticker-'));
    execFileSync('php', [path.join(__dirname, 'render-redesign-preview.php'), dir], { env: { ...process.env, GHCA_PREVIEW_ASSETS: 'https://p.test/assets', GHCA_PREVIEW_TICKER: mode === 'one' ? '' : mode } });
    for (const vp of [{ width: 1440, height: 900 }, { width: 834, height: 1000 }, { width: 390, height: 844 }]) {
      const ctx = await browser.newContext({ viewport: vp });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      await ctx.route('https://p.test/**', (r) => {
        const u = new URL(r.request().url());
        if (u.pathname === '/overview.html') { return r.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(dir, 'overview.html')) }); }
        // Assets come as /assets/... or (the bulletin script) by the repository's own path.
        const rel = u.pathname.startsWith(root + '/assets/') ? u.pathname.slice(root.length) : u.pathname;
        const target = path.join(root, rel);
        if (rel.startsWith('/assets/') && target.startsWith(path.join(root, 'assets')) && fs.existsSync(target)) { return r.fulfill({ contentType: MIME[path.extname(target)] || 'application/octet-stream', body: fs.readFileSync(target) }); }
        return r.fulfill({ status: 200, contentType: 'text/html', body: '<p>stub</p>' });
      });
      await page.goto('https://p.test/overview.html');
      await page.waitForSelector('[data-gh-ticker]');
      const t = await page.evaluate(() => {
        const s = document.querySelector('[data-gh-ticker]');
        const r = s.getBoundingClientRect();
        const msg = s.querySelector('.gh-ticker-msg');
        const link = Array.from(s.querySelectorAll('a')).find((a) => /All bulletins/.test(a.textContent));
        const lr = link ? link.getBoundingClientRect() : null;
        const inside = Array.from(s.querySelectorAll('.gh-ticker-lbl, .gh-ticker-msg, .gh-ticker-ctl')).every((e) => { const x = e.getBoundingClientRect(); return x.left >= r.left - 1 && x.right <= r.right + 1; });
        return {
          visible: r.width > 0 && r.height > 0 && getComputedStyle(s).display !== 'none',
          state: s.getAttribute('data-gh-ticker-state'),
          msg: msg ? msg.textContent.trim() : null,
          msgVisible: msg ? msg.getBoundingClientRect().width > 0 && msg.scrollWidth <= msg.clientWidth + 1 : null,
          role: msg ? msg.getAttribute('role') : null,
          items: s.querySelectorAll('.gh-ticker-track > .gh-ticker-item').length,
          pause: !!s.querySelector('[data-gh-ticker-pause]'),
          link: !!(lr && lr.width > 0 && lr.right <= window.innerWidth + 1),
          inside,
          page: document.documentElement.scrollWidth > window.innerWidth,
        };
      });
      const w = vp.width;
      assert.equal(t.visible, true, mode + ' ' + w); assert.equal(t.link, true); assert.equal(t.page, false);
      if (want.msg) {
        assert.equal(t.state, want.state || mode); assert.equal(t.msg, want.msg); assert.equal(t.role, 'status'); assert.equal(t.items, 0); assert.equal(t.pause, false);
        assert.equal(t.msgVisible, true, mode + ' ' + w + ' message clipped'); assert.equal(t.inside, true);
        ok(w + ' ' + mode + ': the ticker is shown with "' + want.msg + '" (status message, fully visible), the "All bulletins" link, no items and no pause control; no sideways scrolling');
      } else {
        assert.equal(t.state, 'items'); assert.equal(t.msg, null);
        assert.ok(t.items >= want.items); assert.equal(t.pause, want.items > 1);
        ok(w + ' ' + mode + ' bulletin' + (want.items > 1 ? 's' : '') + ': shown in the ticker as before (' + (want.items > 1 ? 'with' : 'no') + ' pause control); no status message; no sideways scrolling');
      }
      if (shots && (mode === 'empty' || mode === 'unavailable')) { await page.locator('[data-gh-ticker]').screenshot({ path: path.join(shots, 'ticker-' + mode + '-' + w + '.png') }); }
      assert.equal(errors.length, 0, errors.join(' | '));
      await ctx.close();
    }
  }
  await browser.close();
  process.stdout.write('PASS: ' + n + ' ODP ticker state browser checks (synthetic Overview; nothing fetched or published)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
