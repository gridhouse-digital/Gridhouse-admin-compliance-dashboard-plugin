// Expands a design-canvas board (.dc.html) into static HTML using the board's own renderVals() data.
// Handles <sc-for list as>, <sc-if value>, {{expr}}, and <dc-import name> (recursively, props from attributes).
const fs = require('fs'); const path = require('path');
const DIR = process.argv[2];
function evalIn(expr, scope) {
  try { return Function('scope', 'with (scope) { return (' + expr + '); }')(scope); } catch (e) { return ''; }
}
function interp(s, scope) { return s.replace(/\{\{([\s\S]*?)\}\}/g, (_, e) => { const v = evalIn(e.trim(), scope); return v == null ? '' : String(v); }); }
function matchBlock(html, tag, start) {
  // returns index after the matching </tag> for an opening tag at start
  const re = new RegExp('<' + tag + '\\b|</' + tag + '>', 'g'); re.lastIndex = start + 1; let depth = 1, m;
  while ((m = re.exec(html))) { if (m[0][1] === '/') { if (--depth === 0) return { inner: html.indexOf('>', start) + 1, end: m.index, after: re.lastIndex }; } else depth++; }
  throw new Error('unclosed ' + tag);
}
function attrs(open) { const o = {}; open.replace(/([\w-]+)="([^"]*)"/g, (_, k, v) => { o[k] = v; }); return o; }
function expand(html, scope) {
  let out = '', i = 0;
  const re = /<(sc-for|sc-if|dc-import)\b[^>]*>/g; let m;
  while ((m = re.exec(html))) {
    if (m.index < i) continue;
    out += interp(html.slice(i, m.index), scope);
    const tag = m[1]; const a = attrs(m[0]);
    if (tag === 'dc-import') {
      const selfClose = /\/>$/.test(m[0]) || html.startsWith('</dc-import>', m.index + m[0].length);
      const props = {}; for (const [k, v] of Object.entries(a)) props[k.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = interp(v, scope);
      out += renderBoard(a.name, props, true);
      i = m.index + m[0].length; if (html.startsWith('</dc-import>', i)) i += 12;
      re.lastIndex = i; continue;
    }
    const b = matchBlock(html, tag, m.index); const inner = html.slice(b.inner, b.end);
    if (tag === 'sc-for') {
      const list = evalIn(a.list.replace(/^\{\{|\}\}$/g, ''), scope) || [];
      for (const item of list) out += expand(inner, Object.assign(Object.create(scope), { [a.as]: item }));
    } else {
      const v = evalIn(a.value.replace(/^\{\{|\}\}$/g, ''), scope);
      if (v) out += expand(inner, scope);
    }
    i = b.after; re.lastIndex = i;
  }
  return out + interp(html.slice(i), scope);
}
function renderBoard(name, props, embedded) {
  const src = fs.readFileSync(path.join(DIR, name + '.dc.html'), 'utf8');
  const body = src.slice(src.indexOf('<x-dc>') + 6, src.indexOf('</x-dc>')).replace(/<helmet>[\s\S]*?<\/helmet>/, '');
  const code = (src.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/) || [])[1] || '';
  class DCLogic { constructor(p) { this.props = p; this.state = {}; } }
  let vals = {};
  try { const C = Function('DCLogic', code + '\nreturn Component;')(DCLogic); const c = new C(Object.assign({ theme: 'gridhouse' }, props)); vals = c.renderVals ? c.renderVals() : {}; } catch (e) { process.stderr.write(name + ': ' + e.message + '\n'); }
  return expand(body, Object.assign({}, props, vals));
}
process.stdout.write(renderBoard(process.argv[3], { theme: process.argv[4] || 'gridhouse' }, false));
