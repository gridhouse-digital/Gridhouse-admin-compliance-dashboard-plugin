# Design references

These notes record what was recovered from the handoff bundles. The bundles are Claude artifact pages (gzip plus base64 under `__bundler/manifest`). The bundles are not runtime assets and are not enqueued.

## Authority

| Source | Role |
| --- | --- |
| `074e1b13` | Admin suite. Overview (`AdminChrome` plus the overview page) is the visual target for this slice. Other boards are inventory, not implemented screens. |
| `60f67e24` | Employee suite. Shared architecture only. This plugin does not absorb that dashboard. |
| `aa5355e2` | Responsive layout reference. |
| `be8cca04` | Design system. Brand themes Gridhouse, Akansoh, and Purple. Status colors stay fixed. |
| `8b5a3d5e` | Developer handoff. Proposed APIs were reconciled with this plugin instead of treated as a spec to invent. |
| `1195298e` | Settings boards, including unsaved and save-failed. Existing settings forms and `assets/settings-console.js` already cover unsaved changes. The boards were not rebuilt. |
| `4fa32636` | Authoritative bulletin library, full page, drawer, and states. |
| `5cef753d` | Earlier drawer-only pack. Use it where `4fa32636` does not overlap. Where they overlap, `4fa32636` wins. |

The drawer and the full page render one plain-text body. The library uses the separate preview. Sample banners and “NEW, proposed” are not production states.

## How the runtime CSS is built

`board-source.css` is the board stylesheet exactly as the `4fa32636` library board renders it: design tokens v2.1 to v2.4.0, the three brand themes, and every component class the admin and bulletin boards use. Its `@font-face` rules pointed at Google Fonts and were removed; the fonts are self-hosted instead.

`build-gh-redesign.sh` writes `assets/gh-redesign.css` from four parts:

1. `gh-redesign.head.css`: self-hosted `@font-face` rules and a reset.
2. `board-source.css`, passed through `scope-board-css.py`.
3. `app-source.css`, the canvas app layer (`gh-app.css`, sha256 `e4a09783…`), passed through `scope-board-css.py --app`. It carries overlay and modal sizing, the toast stack, settings helpers and the table column-drop rules. `--app` drops what is prototype-only (`html`/`body`/`#root`, `.app-protobar`, `.app-doc`, `.app-new`) or owned elsewhere (`.app-tabbar`, `.gh-ehome3`, `.gh-appbar`).
4. `gh-redesign.tail.css`: the plugin adapter (full-bleed placement, fixed drawer, live states, table kit, phone rules).

`scope-board-css.py` prefixes every selector with `:is(.ghca-ui, #ghca-ui-scope)`. `:root` becomes `.gh-app`. `#ghca-ui-scope` is never rendered. It only adds id specificity, so board rules beat theme and page-builder selectors such as BuddyBoss `.entry-content a` and Elementor `.elementor-widget-container h2`. Every board rule gets the same extra weight, so the board's own cascade order is unchanged. Bare-element board rules (`a`, `button`, `:focus-visible`) skip `.gh-legacy`. Board-only presentation classes (`.gh-phone*`, `.gh-design-note`, `.gh-spec-*`, `.gh-focus-demo`, and similar) are dropped.

Themes and page builders often style links, link children, and button hovers with `!important`, which no specificity can beat. `scope-board-css.py` therefore marks `color`, `background`, `background-color`, `border-color`, and `text-decoration` `!important` on rules for the interactive components listed in `GUARD` and on every rule that targets an `a` element. The board's relative order is unchanged, because all guarded rules carry the same flag. The tail is passed through `scope-board-css.py --guard-only`, which adds the flag without rescoping. The tail also clears theme hover backgrounds on redesign links with a zero-specificity `:where()` rule, so any component rule still wins.

The guard also covers the data table (`.gh-table`: colour, background and the border family), the pager buttons, and the typography themes force on headings (`font-family`, `letter-spacing`, `text-transform`, `font-style` on `.gh-h1`–`.gh-h4`). See `docs/dashboard-redesign-phase2-slice2.md`. Slice 3 adds `.gh-mod-pv-hd` and `.gh-fold-hd` typography declarations for `summary` elements, and the reminder modal's `.gh-mod-*` and `.gh-seg--radio` adapter rules to the tail: see `docs/dashboard-redesign-phase2-slice3.md`. Slice 4 adds the Overdue, Courses and Certificates adapter rules (new class names only, no colour literals): see `docs/dashboard-redesign-phase2-slice4.md`. Slice 5 adds the External training, Audit data and Reports adapter rules (scoped, tokens only): see `docs/dashboard-redesign-phase2-slice5.md`. Slice 6 adds the Administration (app-adm) agency mapping, row selection, the selection bar and the bulk reminder dialog: see `docs/dashboard-redesign-phase2-slice6.md`. Slice 7 adds the v2.4.1 communication-history rules (`.gh-ch-*`, `i-copy`), the drawer channel sub-tabs, the row reminder action, the packet header's agency colour and the packet-ready notice and tray: see `docs/dashboard-redesign-phase2-slice7.md`. Slice 8 adds the Employees table without an inner scroll box (cards below 1024px), a wider container (up to 1600px, requested), drawer header spacing, the packet notice's explicit colours (toast tokens in the head), the inline User training report, Manage users and the announcement modal, menu and confirmation: see `docs/dashboard-redesign-phase2-slice8.md`. Slice 9 adds the report picker's end icon (`gh-i--end`), the certificate viewer (`.gh-cv*`, `i-zoom-out`, `i-fit`), the certificate selection bar (`.gh-certbar*`), the course listbox (`.gh-cbx*`), narrower Compliance by group on the Overview and a 1480px container (reduced from 1600px, requested): see `docs/dashboard-redesign-phase2-slice9.md`.

The reset sits first and uses `:where()`, so it neutralises inherited theme margins, borders, fonts, and text transforms. Any board rule wins over it. Nothing in the file targets `html`, `body`, or the page outside the wrapper. The one exception is `body.gh-ui-body`, the standalone bulletin pages this plugin renders itself.

## Fonts

The boards use Wix Madefor Display (headings) and Wix Madefor Text (body), weights 400 to 800. The rendered boards were checked with computed styles, and only those two families load. The Newsreader serif in the Akansoh theme block is overridden later in the same token file.

`assets/fonts/wix-madefor/` holds the latin and latin-ext variable woff2 files from the Google Fonts API, the official distribution of the upstream project (https://github.com/wix/wixmadefor). The latin files are byte-identical to the ones bundled inside the boards. The licence is the SIL Open Font License 1.1, copied in `assets/fonts/wix-madefor/OFL.txt`. The OFL allows bundling with software. The fonts load from the plugin directory, so there is no runtime request to an external font host.

Icons are the board's Phosphor glyphs as CSS mask data URIs (MIT licence), already inside `board-source.css`.
