// Rendered-style audit: each design board (expanded with its own data, board CSS only) against the plugin's
// synthetic render of the same screen at the same width, compared element by element (same tag and same gh-
// classes) on computed styles. Synthetic: the plugin side is the repository's preview scripts, not WordPress.
//
//   BOARDS_DIR=<folder with the canvas's project/*.dc.html> OUT_DIR=<output folder> node tools/design-audit/audit.cjs
//   (SHOTS=1 also writes board and plugin screenshots per screen.) Results: $OUT_DIR/results.json
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '../..'); const A = process.env.OUT_DIR || path.join(require('os').tmpdir(), 'ghca-design-audit'); const B = process.env.BOARDS_DIR;
if (!B) { console.error('Set BOARDS_DIR to the canvas project folder (…/project with *.dc.html).'); process.exit(2); }
fs.mkdirSync(A, { recursive: true });
const MIME = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2' };
const faces = fs.readFileSync(ROOT + '/design-references/gh-redesign.head.css', 'utf8').split('\n').filter((l) => l.startsWith('@font-face')).join('\n').replace(/url\('fonts\//g, "url('https://p.test/assets/fonts/");
const boardCss = fs.readFileSync(ROOT + '/design-references/board-source.css', 'utf8');
function boardPage(name) {
  const body = execFileSync('node', [path.join(__dirname, 'expand.cjs'), B, name, 'gridhouse']).toString();
  return '<!doctype html><html><head><meta charset="utf-8"><style>' + faces + '\n' + boardCss + '\nbody{margin:0}</style></head><body>' + body + '</body></html>';
}
// Plugin pages, generated once.
const P = path.join(A, 'plugin'); fs.mkdirSync(P, { recursive: true });
const env = { ...process.env, GHCA_PREVIEW_ASSETS: 'https://p.test/assets' };
execFileSync('php', [ROOT + '/tests/render-redesign-preview.php', P], { env });
execFileSync('php', [ROOT + '/tests/render-tables-preview.php', 'page', P], { env });
execFileSync('php', [ROOT + '/tests/render-lists-preview.php', 'page', P], { env });
execFileSync('php', [ROOT + '/tests/render-drawer-preview.php', 'page', P, 'gridhouse', ''], { env: { ...env, GHCA_PREVIEW_ENV: '{}' } });
for (const f of fs.readdirSync(P).filter((f) => f.endsWith('.html'))) { // phase-1 previews write absolute local paths
  fs.writeFileSync(path.join(P, f), fs.readFileSync(path.join(P, f), 'utf8').split(ROOT + '/assets').join('https://p.test/assets').replace(/https:\/\/(preview|example)\.test\/ajax/g, 'https://p.test/ajax'));
}
async function route(r) {
  const u = new URL(r.request().url());
  if (u.pathname.startsWith('/assets/')) { const t = path.join(ROOT, u.pathname); if (fs.existsSync(t)) return r.fulfill({ body: fs.readFileSync(t), contentType: MIME[path.extname(t)] || 'application/octet-stream' }); }
  if (u.pathname.startsWith('/board/')) return r.fulfill({ body: boardPage(u.pathname.slice(7)), contentType: 'text/html' });
  if (u.pathname.startsWith('/plugin/')) return r.fulfill({ body: fs.readFileSync(path.join(P, u.pathname.slice(8))), contentType: 'text/html' });
  if (u.pathname === '/ajax') {
    const body = r.request().postData() || ''; const params = Object.fromEntries(new URLSearchParams(body));
    if (!params.action) { const m = body.match(/name="action"\r\n\r\n([^\r]+)/); if (m) params.action = m[1]; }
    if (params.action === 'ghca_acd_get_employee_drawer') { delete params.action; return r.fulfill({ contentType: 'application/json', body: execFileSync('php', [ROOT + '/tests/render-drawer-preview.php', 'ajax', JSON.stringify(params), '{}']).toString() }); }
    if (params.action === 'ghca_acd_get_edit_records_form') { delete params.action; delete params.nonce; return r.fulfill({ contentType: 'application/json', body: execFileSync('php', [ROOT + '/tests/render-drawer-preview.php', 'edit', JSON.stringify(params), '{}']).toString() }); }
    if (params.action === 'ghca_acd_get_reminder_context') return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { employee_id: 42, employee_name: 'Jordan Ellis', idempotency_token: 't', masked_email: 'j•••@example.org', masked_phone: '•••• 4471', email_ready: true, sms_ready: true, templates: [{ id: 7, name: 'Course due soon', subject: 'Your course is due', message: 'Hi Jordan.', urgency: 'normal', channels: ['email', 'sms', 'email_sms'], reason: '' }] } }) });
    if (params.action === 'ghca_acd_filter_table') { const t = params.ghca_table === 'employees' || params.ghca_table === 'inactive_employees' ? 'tables' : 'lists'; return r.fulfill({ contentType: 'application/json', body: execFileSync('php', [ROOT + '/tests/render-' + t + '-preview.php', 'ajax', JSON.stringify(params)]).toString() }); }
    return r.fulfill({ contentType: 'application/json', body: '{"success":false,"data":{}}' });
  }
  return r.fulfill({ status: 404, body: '' });
}
const PROPS = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginBottom', 'borderTopWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderRightWidth', 'borderTopColor', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'columnGap', 'rowGap', 'color', 'backgroundColor', 'boxShadow', 'width'];
const CONTROL = /gh-btn|gh-input|gh-select|gh-tab\b|gh-st\b|gh-avatar|pager-btns|gh-fchip|gh-count|gh-subtab|gh-check|gh-badge/;
async function measure(page, scope, sels) {
  return page.evaluate(({ scope, sels, PROPS, CONTROL }) => {
    const root = scope ? document.querySelector(scope) : document;
    const out = {};
    for (const key of sels) {
      const [tag, ...want] = key.split('.');
      const el = root && [...root.querySelectorAll(tag)].find((e) => { const r = e.getBoundingClientRect(); if (r.width === 0 || r.height === 0) return false;
        const cls = [...e.classList].filter((c) => /^gh-/.test(c) && !/^gh-i(--|$)/.test(c) && !/^gh-(app|phone|design|spec|ui--)/.test(c)).sort(); return cls.join('.') === want.join('.'); });
      if (!el) { out[key] = null; continue; }
      const c = getComputedStyle(el); const o = {};
      for (const p of PROPS) { if (p !== 'width') o[p] = c[p]; }
      if (new RegExp(CONTROL).test(key)) { o.height = Math.round(el.getBoundingClientRect().height) + 'px'; }
      out[key] = o;
    }
    return out;
  }, { scope, sels, PROPS, CONTROL: CONTROL.source });
}
module.exports = { route, boardPage, measure };
if (require.main === module) {
  (async () => {
    const SCREENS = require(path.join(__dirname, 'screens.cjs'));
    const browser = await chromium.launch(); const results = [];
    for (const s of SCREENS) {
      const ctx = await browser.newContext({ viewport: { width: s.width, height: 1000 } });
      await ctx.route('https://p.test/**', route);
      const bp = await ctx.newPage(); await bp.goto('https://p.test/board/' + s.board); await bp.evaluate(() => document.fonts.ready);
      if (s.boardPrep) await s.boardPrep(bp);
      const pp = await ctx.newPage(); await pp.goto('https://p.test/plugin/' + s.plugin); await pp.evaluate(() => document.fonts.ready);
      if (s.prep) await s.prep(pp);
      await pp.evaluate(() => document.fonts.ready);
      const classes = (page, scope) => page.evaluate((scope) => {
        const root = scope ? document.querySelector(scope) : document; const set = new Set(); if (!root) return [];
        root.querySelectorAll('[class]').forEach((e) => { const r = e.getBoundingClientRect(); if (r.width === 0 || r.height === 0) return;
          const cls = [...e.classList].filter((c) => /^gh-/.test(c) && !/^gh-i(--|$)/.test(c) && !/^gh-(app|phone|design|spec|ui--)/.test(c)).sort();
          if (cls.length) set.add(e.tagName.toLowerCase() + '.' + cls.join('.')); });
        return [...set]; }, scope);
      const ca = await classes(bp, s.boardScope || ''), cb = await classes(pp, s.scope || '');
      s.sels = ca.filter((c) => cb.includes(c)).sort();
      s.onlyBoard = ca.filter((c) => !cb.includes(c)).sort(); s.onlyPlugin = cb.filter((c) => !ca.includes(c)).sort();
      results.push({ screen: s.name, sel: '*', status: 'classes', onlyBoard: s.onlyBoard, onlyPlugin: s.onlyPlugin, shared: s.sels.length });
      const a = await measure(bp, s.boardScope || '', s.sels), b = await measure(pp, s.scope || '', s.sels);
      for (const sel of s.sels) {
        if (!a[sel] || !b[sel]) { results.push({ screen: s.name, sel, status: !a[sel] && !b[sel] ? 'absent-both' : (!a[sel] ? 'absent-board' : 'absent-plugin') }); continue; }
        const diff = Object.keys(a[sel]).filter((k) => a[sel][k] !== b[sel][k]).map((k) => ({ prop: k, board: a[sel][k], plugin: b[sel][k] }));
        results.push({ screen: s.name, sel, status: diff.length ? 'diff' : 'same', diff });
      }
      if (process.env.SHOTS) { await bp.screenshot({ path: path.join(A, 'shot-' + s.name + '-board.png') }); await pp.screenshot({ path: path.join(A, 'shot-' + s.name + '-plugin.png') }); }
      await ctx.close();
    }
    await browser.close();
    fs.writeFileSync(path.join(A, 'results.json'), JSON.stringify(results, null, 1));
    const n = (st) => results.filter((r) => r.status === st).length;
    console.log('compared', n('same') + n('diff'), 'same', n('same'), 'diff', n('diff'), 'absent-board', n('absent-board'), 'absent-plugin', n('absent-plugin'), 'absent-both', n('absent-both'));
  })().catch((e) => { console.error(e); process.exit(1); });
}
