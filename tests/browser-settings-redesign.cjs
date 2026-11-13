'use strict';
/**
 * Redesigned settings screen (boards "WP admin settings") on the disposable site, in Chromium: the shell, grouped
 * section list and current section, one section of the main Settings page at a time, the narrow-screen section
 * picker, the unsaved-changes bar, a real save ("Settings saved"), a rejected save ("Settings not saved"), other
 * plugin pages inside the shell, and the previous console when the redesign switch is off. Synthetic admin only;
 * every setting it changes is put back.
 *
 *   GHCA_REAL_SITE=http://127.0.0.1:8099 GHCA_REAL_WP=/path/to/wp node tests/browser-settings-redesign.cjs [shots]
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const SITE = process.env.GHCA_REAL_SITE || '';
const WPROOT = process.env.GHCA_REAL_WP || '';
const shots = process.argv[2] || '';
if (!SITE || !WPROOT) { process.stdout.write('SKIP: set GHCA_REAL_SITE and GHCA_REAL_WP to a disposable site\n'); process.exit(0); }
if (shots) { fs.mkdirSync(shots, { recursive: true }); }
let n = 0;
const ok = (m) => { n += 1; process.stdout.write('ok - ' + m + '\n'); };
const php = (code) => execFileSync('php', ['-r', 'define("WP_USE_THEMES", false); $_SERVER["HTTP_HOST"]="localhost"; require getenv("WPR") . "/wp-load.php"; if (!defined("GHCA_DISPOSABLE_TEST_SITE")) { exit(1); } ' + code], { env: { ...process.env, WPR: WPROOT } }).toString();
const getOpt = (name) => php('echo wp_json_encode(get_option("' + name + '", null));');
const setOpt = (name, json) => php('$v = json_decode(\'' + json.replace(/'/g, "\\'") + '\', true); null === $v ? delete_option("' + name + '") : update_option("' + name + '", $v);');

(async () => {
  const saved = { redesign: getOpt('ghca_acd_ui_redesign'), days: getOpt('ghca_new_hire_deadline_days'), cycle: getOpt('ghca_acd_annual_cycle') };
  setOpt('ghca_acd_ui_redesign', '1');
  const browser = await chromium.launch();
  try {
    for (const vp of [{ width: 1440, height: 1000 }, { width: 834, height: 1100 }, { width: 390, height: 844 }]) {
      const ctx = await browser.newContext({ viewport: vp });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto(SITE + '/wp-login.php');
      await page.fill('#user_login', 'synthetic-admin');
      await page.fill('#user_pass', 'synthetic-pass');
      await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
      const w = vp.width;

      await page.goto(SITE + '/wp-admin/admin.php?page=ghca-acd-settings&section=training');
      await page.waitForSelector('#ghca-set-shell.is-ready');
      const s = await page.evaluate(() => {
        const sh = document.getElementById('ghca-set-shell');
        const vis = (el) => !!el && el.getBoundingClientRect().height > 0 && getComputedStyle(el).display !== 'none' && !el.closest('[hidden]');
        return {
          h1: sh.querySelector('.gh-h1').textContent.trim(),
          title: sh.querySelector('.ghca-set-title').textContent.trim(),
          groups: Array.from(sh.querySelectorAll('.ghca-set-nav-g > .gh-eyebrow')).map((e) => e.textContent.trim()),
          current: (sh.querySelector('.ghca-set-nav-i[aria-current="page"]') || {}).textContent,
          navVisible: vis(sh.querySelector('.ghca-set-nav')),
          pickVisible: vis(sh.querySelector('.ghca-set-pick')),
          moved: !!sh.querySelector('[data-ghca-set-body] > .wrap form[action="options.php"]'),
          training: vis(document.getElementById('ghca-training')),
          branding: vis(document.getElementById('ghca-branding')),
          lifespans: vis(document.getElementById('ghca-lifespans')),
          save: vis(sh.querySelector('[data-ghca-set-body] form .submit')),
          oldConsole: !!document.querySelector('.ghca-console-header'),
          dirty: vis(sh.querySelector('[data-ghca-set-dirty]')),
          hscroll: document.documentElement.scrollWidth > window.innerWidth + 1,
          card: getComputedStyle(document.getElementById('ghca-training')).borderRadius,
        };
      });
      assert.equal(s.h1, 'Settings'); assert.equal(s.title, 'Training rules'); assert.equal(s.current.trim(), 'Training rules');
      assert.deepEqual(s.groups, ['Compliance rules', 'Agency', 'Messaging', 'Integrations', 'System']);
      assert.equal(s.moved, true); assert.equal(s.training, true); assert.equal(s.branding, false); assert.equal(s.lifespans, false); assert.equal(s.save, true);
      assert.equal(s.oldConsole, false); assert.equal(s.dirty, false); assert.equal(s.hscroll, false); assert.notEqual(s.card, '0px');
      assert.equal(s.navVisible, w >= 1024); assert.equal(s.pickVisible, w < 1024);
      ok(w + ': Training rules section in the board shell (Settings, five groups, current section marked, card styling); only its block shown with its Save; ' + (w >= 1024 ? 'section list' : 'section picker') + '; no sideways scroll; old console gone');
      if (shots) { await page.screenshot({ path: path.join(shots, 'settings-training-' + w + '.png'), fullPage: true }); }

      if (w === 1440) {
        // Unsaved changes, then a real save of one field (put back afterwards).
        await page.fill('#ghca_new_hire_deadline_days', '31');
        await page.waitForSelector('[data-ghca-set-dirty]:not([hidden])');
        ok('editing a field shows the unsaved-changes bar ("Unsaved changes in Training rules")');
        assert.match(await page.textContent('[data-ghca-set-dirty]'), /Unsaved changes in Training rules/);
        await Promise.all([page.waitForNavigation(), page.click('[data-ghca-set-save]')]);
        await page.waitForSelector('#ghca-set-shell.is-ready');
        assert.equal(await page.textContent('[data-ghca-set-result="saved"] .gh-alert-t'), 'Settings saved');
        assert.equal(Number(JSON.parse(getOpt('ghca_new_hire_deadline_days'))), 31);
        assert.equal(await page.textContent('.ghca-set-title'), 'Training rules');
        ok('Save in the bar submits the existing form: "Settings saved", the value is stored, and the same section is shown again');
        // A rejected value: the annual cycle sanitizer refuses it and keeps the stored policy.
        await page.evaluate(() => { const sel = document.getElementById('ghca_acd_annual_cycle'); const o = document.createElement('option'); o.value = 'bogus'; sel.appendChild(o); sel.value = 'bogus'; sel.dispatchEvent(new Event('change', { bubbles: true })); });
        await Promise.all([page.waitForNavigation(), page.click('[data-ghca-set-save]')]);
        await page.waitForSelector('#ghca-set-shell.is-ready');
        assert.equal(await page.textContent('[data-ghca-set-result="error"] .gh-alert-t'), 'Settings not saved');
        assert.match(await page.textContent('[data-ghca-set-result="error"]'), /Select an annual training cycle/);
        assert.equal(getOpt('ghca_acd_annual_cycle'), saved.cycle);
        ok('a rejected value shows "Settings not saved" with the reason, and the stored policy is unchanged');
        if (shots) { await page.screenshot({ path: path.join(shots, 'settings-error-1440.png') }); }
        // Discard reloads without saving.
        await page.fill('#ghca_new_hire_deadline_days', '45');
        await Promise.all([page.waitForNavigation(), page.click('[data-ghca-set-discard]')]);
        assert.equal(Number(JSON.parse(getOpt('ghca_new_hire_deadline_days'))), 31);
        ok('Discard reloads the section without saving');
      }

      for (const [section, id, title] of [['branding', 'ghca-branding', 'Branding'], ['validity', 'ghca-lifespans', 'Course validity & packets'], ['performance', 'ghca-performance', 'Performance'], ['people', 'ghca-custom-options', 'Employment types & roles']]) {
        await page.goto(SITE + '/wp-admin/admin.php?page=ghca-acd-settings&section=' + section);
        await page.waitForSelector('#ghca-set-shell.is-ready');
        const r = await page.evaluate((target) => {
          const vis = (el) => !!el && el.getBoundingClientRect().height > 0 && !el.closest('[hidden]');
          const shown = Array.from(document.querySelectorAll('.ghca-settings-section, #ghca-packet-selection')).filter(vis).map((e) => e.id);
          return { shown, title: document.querySelector('.ghca-set-title').textContent, hscroll: document.documentElement.scrollWidth > window.innerWidth + 1, target: vis(document.getElementById(target)) };
        }, id);
        assert.equal(r.title, title); assert.equal(r.target, true); assert.equal(r.hscroll, false);
        assert.ok(r.shown.every((x) => ['ghca-branding', 'ghca-lifespans', 'ghca-packet-selection', 'ghca-performance', 'ghca-custom-options'].includes(x) && (x === id || (section === 'validity' && x === 'ghca-packet-selection'))), section + ' shows ' + r.shown.join(','));
        if (shots && w === 1440) { await page.screenshot({ path: path.join(shots, 'settings-' + section + '-1440.png'), fullPage: true }); }
      }
      ok(w + ': Branding, Course validity & packets (with packet courses), Performance and Employment types & roles each show only their own blocks');

      for (const [slug, title] of [['ghca-acd-permissions', 'Permissions'], ['ghca-acd-audit-mapping', 'Audit mapping'], ['ghca-acd-agency-profile', 'Agency profile'], ['ghca-acd-training-year', 'Training year (2.0)']]) {
        await page.goto(SITE + '/wp-admin/admin.php?page=' + slug);
        await page.waitForSelector('#ghca-set-shell.is-ready');
        const r = await page.evaluate(() => ({ title: document.querySelector('.ghca-set-title').textContent, cur: (document.querySelector('.ghca-set-nav-i[aria-current="page"]') || {}).textContent, moved: !!document.querySelector('[data-ghca-set-body] > .wrap'), hscroll: document.documentElement.scrollWidth > window.innerWidth + 1 }));
        assert.equal(r.title, title); assert.equal((r.cur || '').trim(), title); assert.equal(r.moved, true); assert.equal(r.hscroll, false);
        if (shots && w === 1440 && slug === 'ghca-acd-permissions') { await page.screenshot({ path: path.join(shots, 'settings-permissions-1440.png'), fullPage: true }); }
      }
      ok(w + ': Permissions, Audit mapping, Agency profile and Training year open inside the shell with their section marked; no sideways scroll');

      if (w === 390) {
        await page.goto(SITE + '/wp-admin/admin.php?page=ghca-acd-settings&section=training');
        await page.waitForSelector('#ghca-set-shell.is-ready');
        const target = await page.evaluate(() => Array.from(document.querySelectorAll('[data-ghca-set-pick] option')).find((o) => /Performance/.test(o.textContent)).value);
        await Promise.all([page.waitForNavigation(), page.selectOption('[data-ghca-set-pick]', target)]);
        assert.equal(await page.textContent('.ghca-set-title'), 'Performance');
        ok('390: the section picker opens the chosen section');
      }
      assert.equal(errors.length, 0, errors.join(' | '));
      await ctx.close();
    }

    // Redesign off: the previous console, no shell.
    setOpt('ghca_acd_ui_redesign', '0');
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await ctx.newPage();
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', 'synthetic-admin');
    await page.fill('#user_pass', 'synthetic-pass');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(SITE + '/wp-admin/admin.php?page=ghca-acd-settings');
    const off = await page.evaluate(() => ({ shell: !!document.getElementById('ghca-set-shell'), console: !!document.querySelector('.ghca-console-header'), css: !!document.querySelector('link[href*="settings-redesign.css"]') }));
    assert.deepEqual(off, { shell: false, console: true, css: false });
    ok('redesign switch off: the previous settings console, no shell and no redesign stylesheet');
    await ctx.close();
  } finally {
    setOpt('ghca_acd_ui_redesign', saved.redesign);
    setOpt('ghca_new_hire_deadline_days', saved.days);
    setOpt('ghca_acd_annual_cycle', saved.cycle);
    await browser.close();
  }
  process.stdout.write('PASS: ' + n + ' settings redesign browser checks (disposable site, Chromium; settings restored)\n');
})().catch((e) => { process.stderr.write(String(e && e.stack || e) + '\n'); process.exit(1); });
