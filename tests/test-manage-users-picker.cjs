'use strict';
// Browser regression: a removed custom Employment Type / role must never leak into the
// shared Add/Edit pickers. Two employees; only Holder carries the removed values.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const js = fs.readFileSync(path.join(root, 'assets', 'dashboard.js'), 'utf8');
const html = `<!doctype html><body>
<button id="ghca-acd-btn-add-user">Add</button>
<button class="ghca-acd-btn-edit-user" id="edit-holder" data-user_id="1" data-first_name="H" data-last_name="O" data-email="h@x.test" data-phone="" data-employment_type="synth_float_rc7" data-employment_type_label="Synth Float RC7" data-role="ghca_custom_night_nurse" data-role_label="Night Nurse" data-groups="[]">Edit</button>
<button class="ghca-acd-btn-edit-user" id="edit-other" data-user_id="2" data-first_name="O" data-last_name="T" data-email="o@x.test" data-phone="" data-employment_type="per_diem" data-role="subscriber" data-groups="[]">Edit</button>
<div id="ghca-acd-user-modal" style="display:none"><h3 id="ghca-acd-user-modal-title"></h3><button class="ghca-acd-modal__close"></button>
<form id="ghca-acd-user-form"><input type="hidden" id="ghca-acd-user-id" name="user_id"><input name="first_name"><input name="last_name"><input name="email"><input name="phone">
<select id="ghca-acd-employment-type" name="employment_type"><option value="">Not set</option><option value="full_time">Full-Time</option><option value="per_diem">Per Diem</option></select>
<select id="ghca-acd-role" name="role" required><option value="" selected disabled>Select role</option><option value="subscriber">Subscriber</option></select>
<button type="button" class="ghca-acd-modal__cancel">Cancel</button><button type="submit" id="ghca-acd-user-submit"><span class="ghca-acd-btn-text">Save</span></button></form></div></body>`;

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.setContent(html);
  await page.evaluate(() => { window.ghcaAcd = { ajaxUrl: '/ajax', nonce: 'n' }; });
  await page.addScriptTag({ content: js });
  const opts = (id) => page.$$eval('#' + id + ' option', (o) => o.map((x) => x.value));
  const has = async (id, v) => (await opts(id)).includes(v);

  // Add picker: active values only.
  await page.click('#ghca-acd-btn-add-user');
  assert(!(await has('ghca-acd-employment-type', 'synth_float_rc7')), 'Add must not offer the removed type');
  assert(!(await has('ghca-acd-role', 'ghca_custom_night_nurse')), 'Add must not offer the removed role');
  await page.click('.ghca-acd-modal__cancel');

  // Holder edit: sees (and has selected) their own removed values, with original casing.
  await page.click('#edit-holder');
  assert(await has('ghca-acd-employment-type', 'synth_float_rc7') && await has('ghca-acd-role', 'ghca_custom_night_nurse'), 'holder Edit shows own removed values');
  assert.strictEqual(await page.$eval('#ghca-acd-employment-type', (s) => s.value), 'synth_float_rc7');
  assert.strictEqual(await page.$eval('#ghca-acd-employment-type', (s) => s.selectedOptions[0].textContent), 'Synth Float RC7');
  assert.strictEqual(await page.$eval('#ghca-acd-role', (s) => s.value), 'ghca_custom_night_nurse');
  await page.click('.ghca-acd-modal__cancel');

  // Other employee's edit (opened AFTER the holder's): must not show them.
  await page.click('#edit-other');
  assert(!(await has('ghca-acd-employment-type', 'synth_float_rc7')), 'other employee Edit must not offer the removed type');
  assert(!(await has('ghca-acd-role', 'ghca_custom_night_nurse')), 'other employee Edit must not offer the removed role');
  assert.strictEqual(await page.$eval('#ghca-acd-employment-type', (s) => s.value), 'per_diem');
  await page.click('.ghca-acd-modal__cancel');

  // Add after a holder edit (no cancel in between) is clean too.
  await page.click('#edit-holder');
  await page.click('#ghca-acd-btn-add-user');
  assert(!(await has('ghca-acd-employment-type', 'synth_float_rc7')) && !(await has('ghca-acd-role', 'ghca_custom_night_nurse')), 'Add after holder Edit is clean');

  assert.deepStrictEqual(errors, [], 'no page errors: ' + errors.join('; '));
  await browser.close();
  console.log('PASS: manage-users picker keeps removed values scoped to their holder (browser)');
})().catch((e) => { console.error(e); process.exit(1); });
