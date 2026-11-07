# Dashboard redesign, phase 1

Opt-in overview and the ODP bulletin reader. Training math, audit records, capabilities, group scope, and integrations are unchanged.

## What shipped

- Settings checkbox `ghca_acd_ui_redesign`, default off. The previous overview remains the default.
- Settings select `ghca_acd_ui_theme`: Gridhouse (default), Akansoh, or Purple. It applies to the redesigned overview and the bulletin pages only. Saved branding colors override primary and accent only on the Gridhouse theme, and only when they differ from the built-in defaults. Status colors stay fixed.
- Quick links and the primary page action (View reports) use the agency primary color from Branding settings (`ghca_dashboard_brand`, `primary`) on every preset theme, in normal, visited, hover, active, and focus states. `GHCA_ACD_Redesign::agency_brand_style()` derives the fill, hover, on-color, link ink, and hover tint, and darkens the ink until it reaches 4.5:1 on white and on the tint. An invalid saved value falls back to the theme.
- When on, the admin overview renders the board markup (`.gh-pagehead`, `.gh-tabs`, six `.gh-stat` cells, `.gh-ticker`, `.gh-ovp` three-column grid, quick links, support strip) from the existing aggregate. It sits under the site's own WordPress, BuddyBoss, or Elementor header and menu. The board's app bar, global nav, and footer are not rendered on the overview, and nothing hides or restyles the theme header or footer.
- The plugin's standalone bulletin pages (`body.gh-ui-body`, no theme header) keep the app bar, because it is their only navigation.
- Bulletin ticker, drawer, library, and full page. See `docs/odp-bulletins.md`.

The donut percent is the existing compliance rate (completed plus expiring soon). The center reads “Compliant”. The longer definition is in the donut's accessible label only, not on screen. Certificate figures are labeled for active employees in the view. Group rows come from `GHCA_ACD_Scoping::build_group_summary_rows()`. Group chips are labels on the existing risk split (under 60 high, under 85 medium, else low) plus the row's overdue count. They are not a new compliance rule.

## Styling and isolation

The runtime CSS is the board stylesheet, scoped. See `design-references/README.md` for the build, the fonts, and the licences.

- One root wrapper, `.ghca-ui`, holds `.gh-app[data-theme]`. Nothing styles `html`, `body`, or the theme outside it, except `body.gh-ui-body` on the plugin's own bulletin pages.
- Every rule is prefixed with `:is(.ghca-ui, #ghca-ui-scope)` so it outranks theme and builder selectors (BuddyBoss, Elementor).
- Themes often set link colour, underline, and hover background with `!important`. The build marks `color`, `background`, `border-color`, and `text-decoration` `!important` on rules for interactive components and on every rule that targets an `a` element. A zero-specificity rule clears theme hover backgrounds on redesign links. See `design-references/README.md`.
- A `:where()` reset inside the wrapper clears inherited margins, borders, fonts, and text transforms.
- The overview breaks out of narrow theme content columns to the full viewport width. Filter `ghca_acd_ui_full_bleed` returns false to keep it inside the column.
- Fonts are self-hosted Wix Madefor (SIL OFL 1.1). No system UI fallback is used for the redesigned screens, and no external font or icon host is requested.
- The older tabs are wrapped in `.gh-legacy`. The board's bare-element rules skip them, so they keep the existing look and font.

`tests/test-odp-bulletins.php` fails if any top-level rule in `assets/gh-redesign.css` is outside the wrapper.

## Fidelity checklist

Reference is the offline board render. Implementation is local WordPress 7.1 with Twenty Twenty-Five and its own header, nav, and footer, the Akansoh theme, a synthetic 10-employee aggregate, and a local stylesheet that imitates hostile theme link rules (`!important` blue links, underlines, and hover fills). Viewports: 1440×900 and 390×844. Boards: overview `074e1b13`, library `69ef2284` and phone `af0232b4`, full page `cf73a5e6` and phone `52360c13`, drawer `b42047c9` and phone sheet `08b910c1`.

User overrides of the board: no app bar, nav, or footer on the overview; dividers under the health and group card headers; agency color on quick links and the primary action; no visible percent explanation; center reads “Compliant”.

| Area | Board | Plugin | Status |
| --- | --- | --- | --- |
| Fonts | Wix Madefor Display and Text | Same files, self-hosted | Match. See typography below. |
| Tokens and theme | Akansoh `#1B4B3B` / `#C5964E` / `#F3EFEA` | Same token file, scoped | Match |
| App bar, nav, footer | Overview chrome | Not rendered on the overview | User override. The site header and menu are used. Bulletin standalone pages keep the app bar. |
| Page head | Scope dropdown, sync time, employee count, four actions | Scope pill, “As of” time, employee count, same four actions | Same size, weight, padding, and radius. Scope and Export have no chevron, so they are narrower: there is no menu behind them. |
| Tabs | Nine tabs with count pills | Same order, real counts | Match |
| KPI strip | Six flat cells, first neutral, overdue tinted | Six cells, same tones, each a tab jump | Same box sizes. Labels follow the data: “Tracked courses”, “Completion” is the employee rate. |
| Ticker | Label, scrolling headlines, pause, all link | Same. Clones are inert. | Match |
| Compliance health | Donut, legend, overdue link | Same, with a header divider | Center “Compliant”. “Expiring soon”, not “Due soon”. Percent is 26/30px Display 700 as the board declares; the board render shows 11px because a template wrapper picks up a span rule. |
| Certificates and records | Four rows | Three rows | No “pending issuance” data, so that row is not shown. Row labels follow the data. |
| Compliance by group | Name, meta, bar, percent, chip | Same, with a header divider | Names are tab jumps. The board shows “On track” (progress tone) on every row that is not overdue or complete. The plugin keeps its existing risk split, so rows under 85% show an amber “At risk” chip. |
| Announcements | Type icon, title, body, badge, meta, actions | Same, on the existing announcement CPT | Match |
| Quick links, support | Row of six, support strip | Same | Text, icon, hover tint, and focus ring use the agency color (user override). |
| Library, full page, drawer | `4fa32636` boards | Same markup and spacing | Body is plain paragraphs: the feed has no headings or lists. The drawer source line shows the host. |
| Mobile 390 | Stacked cards, two-column KPIs, scrolling tabs, phone boards for bulletins | Same | Bulletin pages use the phone frames' 15px base and 30px logo mark. |
| Older tabs | Not in the board | Existing tables and forms | Not redesigned. Existing scope. |

### Typography

Checked in Chromium on every element with text, keyed by component class, against the board at the same viewport. Compared: family, weight, size, line height, letter spacing, transform, style, and the font the renderer actually used (`CSS.getPlatformFontsForNode`). `document.fonts` shows only Wix Madefor Display and Text (400 to 800, variable) inside the dashboard. No element falls back to a system font or needs synthetic bold.

| Screen | 1440 | 390 |
| --- | --- | --- |
| Overview | 41 of 42 | 41 of 42 |
| Library | 21 of 21 | 15 of 15 |
| Full page | 18 of 18 | 11 of 11 |
| Drawer | 8 of 8 | 8 of 8 |

The one overview difference is the donut percent noted above. The “→” after the overdue count is not in Wix Madefor and renders in a system font, as it does on the board.

Board cascade details that are followed, not the declared component rule: Sync, Export, and Scope are `<button>`s on the board, so `.gh-app button { font: inherit }` makes them 400 weight at 14/20px. Quick-link descriptions and group meta pick up descendant span rules. The phone boards set a 15px base. “Announcements” in the library aside is a plain `strong` (700).

Screenshots are the PR evidence. They are not committed.

Not verified: LearnDash, BuddyBoss, and Elementor are not installed locally, so isolation against them is by selector weight and a simulated hostile stylesheet, not by a render with those plugins. Staging was not accessed. The staging bulletin feed is blocked, so a populated bulletin list was only tested locally with synthetic items.

## Settings that were not added

The settings mock has controls this plugin does not store. They were not invented:

- No bulletin composer, audience publisher, or sheet connection.
- No separate “pending issuance” count.
- No assignment-level completion fraction. The completion card uses the existing employee compliance rate.
- Unsaved changes and the sticky save bar already exist in `assets/settings-console.js`. The 16 visual settings boards were not rebuilt.

## Screens still on the legacy UI

Phase 1 covers the overview and the ODP bulletin ticker, drawer, library, and full page. Nothing else was redesigned, on purpose.

Frontend tabs inside the opt-in shell: Employees, Inactive, Overdue & at risk, External training, Audit data, Courses, Certificates, Reports.

| Drawer or modal | State |
| --- | --- |
| ODP bulletin drawer | Redesigned |
| Employee drawer (including v2) | Existing UI |
| Current-documents drawer | Existing UI |
| Agency drawer | Existing UI |
| Certificate modal | Existing UI |
| Edit, reminder, history, and PDF modals | Existing UI |
| Jotform modal | Existing UI |
| Announcement modal | Existing UI |
| Manage users modal | Existing UI |

Also unchanged: user report, SMS consent, wp-admin permissions, messaging, audit mapping, Jotform, catalog, and OLTL screens.

Agency announcements stay separate from ODP bulletins.

## Employee plugin

Do not merge it into this plugin. Contract: `docs/odp-bulletins.md`. This workspace has no second repository. The employee dashboard is still the `employee-dashboard` page and the `.ghca-ecd` brand hook.
