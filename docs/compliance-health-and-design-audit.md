# Compliance health "Other", and a rendered-style audit of Slices 1–4

Findings and proposals only. **Nothing here has been implemented.** Each proposal waits for approval. Branch `gridhouse-overview-odp-bulletins-af45`; plugin code as of `c6bc947`.

## 1. What feeds "Other" in Compliance health

**Where it comes from.** `GHCA_ACD_Redesign::health_from_aggregate()` (`includes/class-redesign.php`) builds four named slices from the aggregate:

| Slice | Aggregate count | Employee statuses counted (`GHCA_ACD_Data_Provider::get_aggregate()`) |
| --- | --- | --- |
| Compliant | `completed_employees` | `completed`, `new_hire_completed` |
| Expiring soon | `expiring_soon_employees` | `expiring_soon` |
| In progress | `in_progress_employees` | `in_progress`, `new_hire_in_progress`, `new_hire_not_started` |
| Overdue | `overdue_employees` | `overdue`, `new_hire_overdue`, `expired` |

"Other" is not a category of its own. It is `total_employees` minus the four counts above.

**Which employees land in it.** The provider can give an employee ten status values (`build_employee_record()`, and `GHCA_Compliance_Program::get_user_status()` for new hires). The counting loop handles nine of them. The one it skips is **`not_started`**, so today "Other" is exactly the employees whose status is **Not Started**:

- Annual-cycle employees (not in new-hire tracking) who have neither started nor completed any assigned course, and whose cycle due date has not passed. Once it passes they become Overdue.
- Employees with **no enrolled courses at all**: with zero courses the record falls through to `not_started`.

These are the same people the Employees tab shows under Status = "Not Started" (its filter matches `not_started` only). On staging, "Other 4" are therefore four Not Started employees.

**Two related inconsistencies found:**
- The **In progress** KPI tile says "Includes not started", but it only includes *new-hire* not-started employees (`new_hire_not_started`), not annual `not_started`. The donut has the same split, which is why "Other" exists.
- The design's Compliance health card (Admin-Overview board) has four segments: Compliant, Due soon, In progress and Overdue. It has no place for Not Started, because its sample data has none.

**Proposed mapping** (for approval). It is presentation only: no compliance rule, status or percentage changes.

| Legend row | Counts | Colour (existing token) |
| --- | --- | --- |
| Compliant | unchanged (`completed`, `new_hire_completed`) | success |
| Expiring soon | unchanged | warning |
| In progress | unchanged (`in_progress`, `new_hire_in_progress`, `new_hire_not_started`), so it still equals the KPI tile and the Employees "In Progress" filter | info |
| Overdue | unchanged | danger |
| **Not started** (replaces "Other") | employees with status `not_started`, counted explicitly from the same employee records, so it equals the Employees "Not Started" filter | `--gh-status-neutral-solid` (the design system's not-started tone) |

- **No vague bucket.** "Other" is removed and nobody is hidden. If a status the five rows do not know ever appears (none can today), those employees are still shown, under an explicit "Status not recognised" row, so the legend always adds up to the employees in scope. A test would assert that the rows sum to `total_employees` and that the unrecognised row is absent with every current status.
- **The compliant percentage is untouched.** It stays `compliance_rate` = (Compliant + Expiring soon) ÷ employees in scope, computed by the provider.
- **KPI wording (copy only).** The In progress tile's "Includes not started" becomes **"Includes new hires not started"**, which is what it counts. The alternative is to move `new_hire_not_started` into Not started; that would make the donut disagree with the KPI tile and the Employees filter unless both changed too, so I don't recommend it.
- **Optional, needs approval: "No courses assigned".** Employees with zero enrolled courses could be split out of Not started as a separate row. It is factual (`total_courses` = 0), but it is a new grouping the plugin doesn't use elsewhere.

## 2. Rendered-style audit

### Method

`tools/design-audit/` (committed with this note):

- **Board side.** `expand.cjs` turns each design board into static HTML using the board's own `renderVals()` data. This fills the `sc-for` / `{{…}}` template rows that a plain render leaves empty, as happened in the earlier Courses and Certificates comparisons. The boards render with their own CSS (`board-source.css`) and the self-hosted fonts, in the Gridhouse theme.
- **Plugin side.** The plugin renders come from the repository's synthetic preview scripts (`render-redesign-preview.php`, `render-tables-preview.php`, `render-lists-preview.php`, `render-drawer-preview.php`), with the real drawer, edit and table handlers answering the AJAX calls.
- **Comparison.** At the board's width, `audit.cjs` lists every visible element carrying `gh-` component classes in both pages, and pairs elements that have **the same tag and exactly the same `gh-` classes**. For the first visible instance of each pair it compares computed:
  - font family, size, weight, line height, letter spacing, text transform;
  - padding, margin top and bottom;
  - border widths, top border colour, three corner radii;
  - row and column gap, colour, background, box shadow;
  - height, for controls only (buttons, inputs, selects, tabs, chips, counts, pager buttons).

  Element heights and widths elsewhere depend on content and are not compared.

Rerun:

```
BOARDS_DIR=<canvas project folder> OUT_DIR=<out> node tools/design-audit/audit.cjs
```

### Coverage and result

**343 like-for-like component comparisons; 315 identical, 28 different.**

| Screen | Board | Width | Compared | Identical |
| --- | --- | --- | --- | --- |
| Overview | Admin-Overview | 1440 | 45 | 42 |
| Overview (phone) | M-Admin-Overview | 390 | 15 | 3 |
| Employees | Admin-Employees | 1440 | 26 | 25 |
| Employees (tablet) | T-Admin-Employees | 834 | 26 | 24 |
| Employees (phone) | M-Admin-Employees | 390 | 16 | 16 |
| Inactive | Admin-Inactive | 1440 | 18 | 18 |
| Overdue | Admin-Overdue | 1440 | 13 | 13 |
| Courses | Admin-Courses | 1440 | 14 | 14 |
| Certificates | Admin-Certificates | 1440 | 36 | 35 |
| Drawer · Training | Drawer-Training | 1440 | 13 | 13 |
| Drawer · Administration | Drawer-Administration | 1440 | 18 | 16 |
| Drawer · Documents | Drawer-Documents | 1440 | 11 | 11 |
| Drawer · Communications | Drawer-Communications | 1440 | 5 | 4 |
| Edit Records | Drawer-EditRecords | 1440 | 19 | 16 |
| Send reminder (modal) | Modal-SendReminder | 1440 | 17 | 16 |
| Drawer as phone sheet | M-Admin-Sheet | 390 | 10 | 10 |
| ODP library | ODP-List-Admin | 1440 | 21 | 21 |
| ODP bulletin page | ODP-Page-Admin | 1440 | 20 | 18 |

**Font families matched on every one of the 343 pairs**: Wix Madefor Display and Text, as the boards use.

### Confirmed mismatches

| # | Screen / component | Expected (board render) | Actual (plugin render) | Proposed correction |
| --- | --- | --- | --- | --- |
| 1 | **Overview, phone (390)**, whole page | Base type 15 px (the phone frame is `.gh-app.gh-touch` at 15 px) | 14 px. Card, legend, donut, bar and the other components inherit it. | One rule: on `max-width: 719px`, set the overview panel (`.gh-ov`) to 15 px. This mirrors the existing `.gh-tp` phone rule for the table tabs. |
| 2 | Overview phone, KPI value `.gh-stat-val` | 24 px / 30 px, −0.6 px tracking | 28 / 34, −0.7 | Phone rule on `.gh-kpi--overview .gh-stat-val` with the board's values. |
| 3 | Overview phone, KPI tile `.gh-stat` | padding 12 14 14 14, two columns | 16 20 18 20 | Same phone block: tile padding to the board's values; check the grid already gives two columns at 390. |
| 4 | Overview phone, card header `.gh-card-hd--plain` | padding 14 top, 2 bottom | 16 / 12 | Phone rule with the board's padding. This applies to the plain header only; the approved dividers keep their spacing. |
| 5 | Overview phone, status chip `.gh-st` | line-height 12 px, padding 0 7 px | 16 px, 2 8 | The board's phone frame inherits a tighter chip. Phone rule for `.gh-ov .gh-st`, so it does not affect other screens. |
| 6 | Overview phone, small text `.gh-small.gh-muted` | 12 px / 16 px | 12.5 / 17 | Phone rule for `.gh-ov .gh-small`. |
| 7 | Overview phone, section heading `h2.gh-h4` | margin-top 6 px; h3 tracking −0.192 px (15 px base) | 0; −0.16 px | Follows from #1, plus the 6 px margin. |
| 8 | **Employees, tablet (834)**, View button `.gh-btn--secondary.gh-btn--sm` | 44 px tall. The tablet board is `.gh-touch`: "every control is 44 px tall". | 30 px | Apply the existing touch sizing (`.gh-touch` rules: `.gh-btn` min-height and icon width 44 px, from `--gh-touch`) from 720 px to 1023 px, not just on phones. **Decision needed:** tie it to width, as the board does, or to `(pointer: coarse)`, which suits touch laptops and leaves small desktop windows unchanged. |
| 9 | Drawer · Administration, More menu link `a.gh-menu-i` | link colour (`rgb(60,88,116)`) | text colour | Not a design token issue: on the board, link items pick up the generic link colour while button items are text-coloured. Slice 3 deliberately made all menu items text-coloured. **Decision needed:** follow the board render (links blue, buttons dark) or keep them consistent (current). |
| 10 | ODP bulletin page, disclaimer `p.gh-bp-note` | 13.5 px bottom margin (the paragraph's own margin on the board) | 0 (an adapter rule) | Remove `margin-bottom: 0` for `.gh-bp-note` only, which adds 13.5 px below the "not an official ODP publication" note. |

### Differences that are not mismatches

| Screen / component | Why |
| --- | --- |
| Overview 1440: health card body `.gh-ovp-bd` 14 px top padding, and the header dividers | Phase-1 user override: dividers under the health and group card headers (`docs/dashboard-redesign-phase1.md`). |
| Overview 1440: `.gh-stat--danger` top border colour; `div.gh-container` gap | The border width is 0 and the container is not a flex or grid box, so neither is visible. |
| Employees 1440/834: `td.gh-secondary` background | The board shows a highlighted (hovered or selected) demo row. |
| Drawer · Administration: `button.gh-menu-i` background | The board shows an item in its hover state (`is-hover`). |
| Drawer · Communications: `div.gh-card` | The board's message card is matched against the plugin's empty-state card. The plugin's message cards are `.gh-card.gh-dr-comm` and use the board's 14/16 padding and 8 px gap. |
| Edit Records: `label.gh-check`, `h2.gh-h3` margin, `.gh-drawer-bd` gap | Pairing artifacts. The verify box (`.gh-dr-verify`) measures identically to the board's (padding 10/12, radius 10, warning colours, 600). The two-column grid (`.gh-sub-grid`) uses the board's 20 px gap. The title's 10 px margin belongs to the board's header layout; the plugin puts the title in the sub-page body (noted in Slice 3). |
| Send reminder: `.gh-input` border and shadow | The board shows one field focused. |
| Certificates: KPI grid 4 px margin | An intentional adapter: it turns the tab's 16 px gap into the board's 20 px. |
| ODP bulletin page: `.gh-sr` | Screen-reader-only text, not visible. |

### Not verified

- **Themes:** only the Gridhouse theme was compared. Akansoh and Purple change brand colours only, and status tokens are fixed, but they were not measured here.
- **States:** hover, focus and active states were not audited, apart from noting the boards' demo states. The keyboard focus rings are covered by the existing browser suites.
- **Instances:** only the first visible instance of each component pair was compared, not every row or card.
- **Missing boards:** no tablet board exists for any screen except Employees; no phone boards exist for Overdue, Courses, Certificates, Edit Records or the reminder modal. Those were not compared, beyond the existing phone checks in the browser suites (cards, no sideways scroll, 44 px targets).
- **Multi-phone boards:** the phone ODP boards (`M-ODP-List`, `M-ODP-Page`) hold several phones on one artboard and were not included.
- **Real site:** everything is synthetic. The plugin side is the preview scripts, not WordPress with LearnDash, BuddyBoss or Elementor, and nothing was measured on staging. This is not a claim of pixel-perfect parity; it is 315 of 343 measured component pairs identical, with the remaining 28 explained above.
- **Legacy screens** (redesign off) are outside this audit. They are covered by the golden checksums and the existing regression tests.

### How the corrections would be made

All proposed CSS goes into `design-references/gh-redesign.tail.css`, under the existing `:is(.ghca-ui, #ghca-ui-scope)` scope and the existing phone media query, using the board's tokens (`--gh-touch`, the status and text tokens). It would then be rebuilt into `assets/gh-redesign.css`, so nothing reaches the WordPress theme or the legacy screens. After implementation the audit would be rerun, and the new values added to the browser suites.
