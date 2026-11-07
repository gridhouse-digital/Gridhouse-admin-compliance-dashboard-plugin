"""Scope the decoded design-board CSS (gh-tokens.css + gh-components.css,
v2.4.0) to the plugin root so it cannot leak into or be overridden by the
host theme. Usage: python3 scope-board-css.py [--guard-only] board.css > scoped.css
"""
import re
import sys

P = ':is(.ghca-ui, #ghca-ui-scope)'
LEGACY = ':where(:not(.gh-legacy, .gh-legacy *))'
DROP = ('.gh-phone', '.gh-spec-', '.gh-focus-demo', '.gh-design-note', '.gh-ghost',
        '.gh-bul--sheet', '.gh-bul--panel', '.gh-print', '.gh-live')
BARE = {'.gh-app a', '.gh-app a:hover', '.gh-app button', '.gh-app :focus-visible'}
# Interactive board components. Themes and page builders style links, link
# children, and button hovers with !important, which plain board rules cannot
# beat, so these properties are marked !important on every rule for them and
# on every rule that targets an `a` element.
GUARD = re.compile(r'\.gh-(qlink|btn|stat|certl|ticker-item|ticker-all|ann|health-ft|tab|grp-n|bul-all|bul-x|bul-step|crumbs|bl-h|bl-read|bp-src|copy|nav|logo|skip|health-od)(?![a-z0-9_])')
GUARD_A = re.compile(r'(?:^|[\s>+~(,])a(?=[.:\[\s,)>]|$)')
GUARD_PROPS = {'color', 'background', 'background-color', 'border-color',
               'text-decoration', 'text-decoration-line', 'text-decoration-color'}


def guard(decl):
    parts = []
    for d in split_top(decl, ';'):
        d = d.strip()
        if not d:
            continue
        name = d.split(':', 1)[0].strip().lower()
        if name in GUARD_PROPS and '!important' not in d:
            d += ' !important'
        parts.append(d)
    return '; '.join(parts) + ';'


def split_top(s, sep):
    out, depth, quote, cur = [], 0, None, ''
    for ch in s:
        if quote:
            cur += ch
            if ch == quote:
                quote = None
            continue
        if ch in '"\'':
            quote = ch
        elif ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == sep and depth == 0:
            out.append(cur)
            cur = ''
            continue
        cur += ch
    out.append(cur)
    return out


def scope_selector(sel):
    sel = sel.strip()
    if any(d in sel for d in DROP):
        return None
    if sel == ':root':
        return P + ' .gh-app'
    if sel.startswith('[data-theme'):
        return P + ' ' + sel
    if sel in BARE:
        return P + ' ' + sel + LEGACY
    return P + ' ' + sel


def blocks(css):
    """Yield (prelude, body) for each top-level block, comments stripped."""
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    i, n = 0, len(css)
    while i < n:
        j = css.find('{', i)
        if j < 0:
            return
        prelude = css[i:j].strip()
        depth, k, quote = 1, j + 1, None
        while k < n and depth:
            ch = css[k]
            if quote:
                if ch == quote:
                    quote = None
            elif ch in '"\'':
                quote = ch
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
            k += 1
        yield prelude, css[j + 1:k - 1]
        i = k


def emit(css, indent='', rescope=True):
    out = []
    for prelude, body in blocks(css):
        if prelude.startswith('@keyframes'):
            out.append(indent + prelude + ' {' + ' '.join(body.split()) + '}')
            continue
        if prelude.startswith('@media'):
            inner = emit(body, indent + '  ', rescope)
            if inner:
                out.append(indent + prelude + ' {')
                out.extend(inner)
                out.append(indent + '}')
            continue
        sels = [scope_selector(s) if rescope else s.strip() for s in split_top(prelude, ',')]
        sels = [s for s in sels if s]
        decl = ' '.join(body.split())
        if not sels or not decl:
            continue
        if GUARD.search(prelude) or GUARD_A.search(prelude):
            decl = guard(decl)
        out.append(indent + ', '.join(sels) + ' { ' + decl + ' }')
    return out


if __name__ == '__main__':
    # --guard-only: input is already scoped (the plugin adapter); only add the guard.
    rescope = sys.argv[1] != '--guard-only'
    sys.stdout.write('\n'.join(emit(open(sys.argv[-1]).read(), '', rescope)) + '\n')
