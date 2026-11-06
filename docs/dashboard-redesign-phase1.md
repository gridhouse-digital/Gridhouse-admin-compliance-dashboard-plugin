# Dashboard redesign, phase 1

Opt-in overview and the ODP bulletin reader. Training math, audit records, capabilities, group scope, and integrations are unchanged.

## What shipped

- Settings checkbox `ghca_acd_ui_redesign`, default off. The previous overview remains the default.
- Settings select `ghca_acd_ui_theme`: Gridhouse (default), Akansoh, or Purple. It applies to the redesigned overview and the bulletin pages only. Saved branding colors override primary and accent only on the Gridhouse theme, and only when they differ from the built-in defaults. Status colors stay fixed.
- When on, the admin overview renders the board markup (`.gh-appbar`, `.gh-pagehead`, `.gh-tabs`, six `.gh-stat` cells, `.gh-ticker`, `.gh-ovp` three-column grid, quick links, support strip) from the existing aggregate.
- Bulletin ticker, drawer, library, and full page. See `docs/odp-bulletins.md`.

The donut percent is the existing compliance rate (completed plus expiring soon), and the card says so. Certificate figures are labeled for active employees in the view. Group rows come from `GHCA_ACD_Scoping::build_group_summary_rows()`. Group chips are labels on the existing risk split (under 60 high, under 85 medium, else low) plus the row's overdue count. They are not a new compliance rule.

## Styling and isolation

The runtime CSS is the board stylesheet, scoped. See `design-references/README.md` for the build, the fonts, and the licences.

- One root wrapper, `.ghca-ui`, holds `.gh-app[data-theme]`. Nothing styles `html`, `body`, or the theme outside it, except `body.gh-ui-body` on the plugin's own bulletin pages.
- Every rule is prefixed with `:is(.ghca-ui, #ghca-ui-scope)` so it outranks theme and builder selectors (BuddyBoss, Elementor) without `!important`.
- A `:where()` reset inside the wrapper clears inherited margins, borders, fonts, and text transforms.
- The overview breaks out of narrow theme content columns to the full viewport width. Filter `ghca_acd_ui_full_bleed` returns false to keep it inside the column.
- Fonts are self-hosted Wix Madefor (SIL OFL 1.1). No system UI fallback is used for the redesigned screens, and no external font or icon host is requested.
- The older tabs are wrapped in `.gh-legacy`. The board's bare-element rules skip them, so they keep the existing look and font.

`tests/test-odp-bulletins.php` fails if any top-level rule in `assets/gh-redesign.css` is outside the wrapper.

## Fidelity checklist

Reference is the offline board render. Implementation is local WordPress 6.x with Twenty Twenty-Five, the Akansoh theme, and a synthetic 10-employee aggregate. Viewports: 1440×900 and 390×844.

| Area | Board | Plugin | Status |
| --- | --- | --- | --- |
| Fonts | Wix Madefor Display and Text | Same files, self-hosted | Match. Computed styles show only these two families inside the wrapper. |
| Tokens and theme | Akansoh `#1B4B3B` / `#C5964E` / `#F3EFEA` | Same token file, scoped | Match |
| App bar | Logo mark, org, sub-label, nav, search, bell, avatar | Logo mark, org, sub-label, nav, avatar | Search and bell omitted. No backing feature. |
| Nav | Compliance, Courses, Reports, Users, Employee view | Same, as tab jumps and links | Users shows only with permission. Employee view shows only if the `employee-dashboard` page exists. |
| Page head | Scope dropdown, sync time, employee count, four actions | Scope pill, “As of” time, employee count, same four actions | Scope pill is static (no chevron). Export has no menu. |
| Tabs | Nine tabs with count pills | Same order, real counts | Match |
| KPI strip | Six flat cells, first neutral, overdue tinted | Six cells, same tones, each a tab jump | Labels follow the data: “Tracked courses”, “Completion” is the employee rate. |
| Ticker | Label, scrolling headlines, pause, all link | Same. Clones are inert. | Match |
| Compliance health | Donut, legend, overdue link | Same. Plus a note on what the percent means. | Center shows the existing rate, not compliant-only. “Expiring soon”, not “Due soon”. |
| Certificates and records | Four rows | Three rows | No “pending issuance” data, so that row is not shown. |
| Compliance by group | Name, meta, bar, percent, chip | Same | Names are plain text, not links. Synthetic data reads 0% to 100%. |
| Announcements | Type icon, title, body, badge, meta, actions | Same, on the existing announcement CPT | Match |
| Quick links, support, footer | Row of six, support strip, footer | Same | Match |
| Library, full page, drawer | `4fa32636` boards | Same markup and spacing | Body is plain paragraphs. The feed has no headings or lists. |
| Mobile 390 | Stacked cards, two-column KPIs, scrolling tabs | Same | Nav wraps to rows under the logo. |
| Older tabs | Not in the board | Existing tables and forms | Not redesigned. Existing scope. |

Screenshots are the PR evidence. They are not committed.

Not verified: LearnDash, BuddyBoss, and Elementor are not installed locally, so isolation against them is by selector weight, not by a render with those plugins. Staging was not accessed.

## Settings that were not added

The settings mock has controls this plugin does not store. They were not invented:

- No bulletin composer, audience publisher, or sheet connection.
- No separate “pending issuance” count.
- No assignment-level completion fraction. The completion card uses the existing employee compliance rate.
- Unsaved changes and the sticky save bar already exist in `assets/settings-console.js`. The 16 visual settings boards were not rebuilt.

## Screens still on the legacy UI

Frontend tabs inside the opt-in shell: Employees, Inactive, Overdue & at risk, External training, Audit data, Courses, Certificates, Reports.

Also unchanged: employee drawer, certificate modal, announcement modal, reminder and PDF flows, user report, manage users, SMS consent, wp-admin permissions, messaging, audit mapping, Jotform, catalog, and OLTL screens.

Agency announcements stay separate from ODP bulletins.

## Employee plugin

Do not merge it into this plugin. Contract: `docs/odp-bulletins.md`. This workspace has no second repository. The employee dashboard is still the `employee-dashboard` page and the `.ghca-ecd` brand hook.
