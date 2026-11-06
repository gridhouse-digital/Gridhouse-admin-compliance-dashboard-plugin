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

`build-gh-redesign.sh` writes `assets/gh-redesign.css` from three parts:

1. `gh-redesign.head.css`: self-hosted `@font-face` rules and a reset.
2. `board-source.css`, passed through `scope-board-css.py`.
3. `gh-redesign.tail.css`: the plugin adapter (full-bleed placement, fixed drawer, live states, phone rules).

`scope-board-css.py` prefixes every selector with `:is(.ghca-ui, #ghca-ui-scope)`. `:root` becomes `.gh-app`. `#ghca-ui-scope` is never rendered. It only adds id specificity, so board rules beat theme and page-builder selectors such as BuddyBoss `.entry-content a` and Elementor `.elementor-widget-container h2`. Every board rule gets the same extra weight, so the board's own cascade order is unchanged. Bare-element board rules (`a`, `button`, `:focus-visible`) skip `.gh-legacy`. Board-only presentation classes (`.gh-phone*`, `.gh-design-note`, `.gh-spec-*`, `.gh-focus-demo`, and similar) are dropped.

The reset sits first and uses `:where()`, so it neutralises inherited theme margins, borders, fonts, and text transforms. Any board rule wins over it. Nothing in the file targets `html`, `body`, or the page outside the wrapper. The one exception is `body.gh-ui-body`, the standalone bulletin pages this plugin renders itself.

## Fonts

The boards use Wix Madefor Display (headings) and Wix Madefor Text (body), weights 400 to 800. The rendered boards were checked with computed styles, and only those two families load. The Newsreader serif in the Akansoh theme block is overridden later in the same token file.

`assets/fonts/wix-madefor/` holds the latin and latin-ext variable woff2 files from the Google Fonts API, the official distribution of the upstream project (https://github.com/wix/wixmadefor). The latin files are byte-identical to the ones bundled inside the boards. The licence is the SIL Open Font License 1.1, copied in `assets/fonts/wix-madefor/OFL.txt`. The OFL allows bundling with software. The fonts load from the plugin directory, so there is no runtime request to an external font host.

Icons are the board's Phosphor glyphs as CSS mask data URIs (MIT licence), already inside `board-source.css`.
