# Rolling Expirations & Traffic Light System — Design (Phase 1: Backend)

- **Plugin:** `gridhouse-admin-compliance-dashboard`
- **Version target:** 1.1.0
- **Date:** 2026-06-29
- **Scope:** Phase 1 only — admin/backend aggregation logic and settings. Phase 2 (frontend/employee-facing) is a separate spec and is **not** built until Phase 1 is built and tested.

## 1. Problem

The dashboard currently expresses compliance as a single per-employee status
(`completed` / `overdue` / `in_progress` / `not_started`) and a single global
"Compliance Due Date". Healthcare compliance is really **per-course and
rolling**: CPR lapses after 730 days, HIPAA after 365, etc. A course that was
completed can silently *expire*, and the employee must re-certify. We need a
**traffic light** model (🟢 current / 🟡 expiring soon / 🔴 expired) computed
per course and rolled up to the employee, plus an admin UI to configure the
rules.

## 2. Confirmed decisions

1. **Lifespans add a renewal/expiration layer.** A configured course expires at
   `completed_ts + lifespan_days`. A completed course with **no** configured
   lifespan is `current` forever (complete-once). The per-course lifespan
   replaces the old global *365-day renewal cycle* concept.
2. **The existing completion deadline stays for incompletes.** A never-completed
   required course past its due date still flips the employee to **Overdue**
   (existing `get_compliance_cycle()` / new-hire onboarding logic is unchanged).
   So an employee can go Red via *either* an expired completed course *or* an
   overdue incomplete course.
3. **Yellow employees stay "Compliant."** "Expiring Soon" gets a dedicated KPI
   card and a Status-filter option; expiring-soon employees still count toward
   the completion rate.
4. **Settings list offers all published LearnDash courses.** Admin adds a
   lifespan to whichever courses they choose.
5. **Expiration is a computed overlay, not a LearnDash write-back.** Nothing
   marks the LearnDash course incomplete; the dashboard derives state at read
   time. (The only writes in this plugin remain the existing Edit Records flow.)

## 3. Architecture

### 3.1 New class — `GHCA_Course_Lifespans` (`includes/class-course-lifespans.php`)

Isolated policy unit, mirroring `GHCA_Compliance_Program`. Responsibilities:

- **Option accessors**
  - `get_lifespan_map(): array<int,int>` — `{course_id: days}` from
    `ghca_acd_course_lifespans`.
  - `get_lifespan_days(int $course_id): int` — 0 if unconfigured.
  - `get_warning_days(): int` — from `ghca_acd_warning_days`, default 90.
- **Pure evaluation (the policy core — owner-implemented)**
  - `evaluate(bool $completed, int $completed_ts, int $lifespan_days, int $warning_days, int $now): array`
    returns:
    ```php
    [
      'state'         => 'current'|'expiring_soon'|'expired'|'incomplete',
      'expiration_ts' => int,   // 0 when not applicable
    ]
    ```
    Rules: not completed → `incomplete` (no expiration). Completed with
    `lifespan_days <= 0` → `current` (never expires). Completed with a lifespan:
    `expiration_ts = completed_ts + lifespan_days * DAY_IN_SECONDS`; `now >=
    expiration_ts` → `expired`; within `warning_days` before it →
    `expiring_soon`; else `current`.
  - `rollup(array $course_states): string` — worst-wins across a user's required
    courses → `expired` > `expiring_soon` > `current`/`incomplete`.

`evaluate()` and `rollup()` are pure (no WP calls) → unit-testable and the
natural learning-mode contribution points (same spirit as the existing
`validate_course_edit()` stub).

### 3.2 Settings (`includes/class-settings.php`)

- Register two options under the existing `ghca_acd_settings` group:
  - `ghca_acd_course_lifespans` (array) — sanitized to `{int course_id: int
    days}`, days clamped to 1–3650; rows with 0/blank days or unknown course IDs
    dropped.
  - `ghca_acd_warning_days` (integer) — default 90, clamped 7–365.
- Add a **"Rolling Expirations & Traffic Light"** section to `render_page()`:
  - **Course Lifespans:** JS-driven repeater. Each row = a `<select>` of all
    published LearnDash courses + a days `<input>`. Add/remove rows client-side;
    submitted as `ghca_acd_course_lifespans[<course_id>] = <days>`. Pre-populated
    from saved map.
  - **Warning Window:** number input (days), default 90.
- Add `update_option_ghca_acd_course_lifespans` and
  `update_option_ghca_acd_warning_days` to the `bust_dashboard_cache` hooks so
  edits invalidate the aggregate immediately.

### 3.3 Main plugin (`gridhouse-admin-compliance-dashboard.php`)

- **`VERSION` → `1.1.0`** (also invalidates all stale 1.0.0 caches via the key).
- **`get_cache_key()`** appends `wp_date('Ymd')` (site timezone) so the
  per-user aggregate transient self-invalidates at local midnight. This is the
  spec's core constraint: a 🟡→🔴 flip overnight needs **no** DB write to take
  effect.
- **`get_user_courses()`** — attach per course: `lifespan_days`,
  `expiration_ts`, `expiration_label`, `compliance_state`. Built by calling
  `GHCA_Course_Lifespans::evaluate()` with the course's `completed`/`completed_ts`.
  (Mirror the same additions in `GHCA_Compliance_Program::get_user_courses()` so
  new-hire course cards carry state too.)
- **`build_employee_record()`** — after base status is computed, roll up the
  course states and adjust:
  - any required course `expired` (finished, past its rolling lifespan) →
    `status_slug = 'expired'`, label `Expired` (🔴). Distinct from incomplete
    `overdue` so auditors see a lapsed re-cert vs. a never-started course at a
    glance — but both roll into the same Overdue KPI bucket and the Overdue
    filter.
  - else if `all_complete` and any `expiring_soon` → `status_slug =
    'expiring_soon'`, label `Expiring Soon`.
  - Precedence: 🔴 expired > new-hire overdue > incomplete-overdue > 🟡 expiring
    soon > in_progress > 🟢 completed > not_started.
  - Add `expiry_state` to the record for downstream use.
- **`get_aggregate()`** — add `$expiring` counter for `expiring_soon`; expose
  `expiring_soon_employees`; completion-rate numerator = `completed +
  expiring`. The Overdue bucket counts `overdue`, `new_hire_overdue`, **and**
  `expired`.
- **`render_kpis()`** — add an **"Expiring Soon"** card
  (`expiring_soon_employees`, icon `time` or `alert`, sub "Within warning
  window"). The existing **Overdue** card's count now includes expired
  employees.
- **`get_status_options()`** — add `'expiring_soon' => 'Expiring Soon'` and
  `'expired' => 'Expired'` (a distinct filter option, since auditors care).
  The existing `'overdue'` option stays and returns the *combined* lapsed
  cohort.
- **Status filter** (the `$filters['status']` block):
  - `expiring_soon` branch → `$status === 'expiring_soon'`.
  - `expired` branch → `$status === 'expired'`.
  - `overdue` branch broadened to return `overdue`, `new_hire_overdue`, **and**
    `expired` (one click = "everyone lapsed").
- **Status rendering** — table status pills and the employee drawer course
  cards render `expiring_soon`/`expired`/`overdue` with yellow/red treatment
  (CSS classes `ghca-acd__status--expiring_soon`, `--expired`; course pill
  `--expiring`/`--expired`). At the **course-row** level the same distinction
  applies: a finished-but-lapsed course pill reads "Expired" 🔴, a
  never-finished-past-due course pill reads "Overdue".

## 4. Data flow

```
LearnDash completion (completed, completed_ts)
        │
        ▼
GHCA_Course_Lifespans::evaluate()  ──►  per-course compliance_state (🟢/🟡/🔴)
        │
        ▼
build_employee_record(): rollup ──► adjusted status_slug + expiry_state
        │
        ▼
get_aggregate(): bucket counts + KPI numbers  (cached per user per day)
        │
        ├─► render_kpis()  (adds Expiring Soon card)
        ├─► employee table + status filter (adds Expiring Soon option)
        └─► drawer / course cards (traffic-light coloring)
```

## 5. Error handling & edge cases

- Course with a lifespan but `completed_ts == 0` (completed flag set, no
  timestamp): treat as `current` (cannot compute expiry without an anchor);
  do not force Red. Logged-only behavior, no fatal.
- Lifespan map referencing a deleted/unpublished course ID: ignored at read
  time (no entry in user's enrolled courses → never evaluated); settings UI
  drops unknown IDs on save.
- `warning_days` ≥ `lifespan_days` for some course: course is `expiring_soon`
  immediately on completion until expiry — acceptable and intended.
- Empty configuration (no lifespans set): behavior is identical to 1.0.0 plus
  the daily cache key — a safe no-op upgrade.

## 6. Testing (Phase 1 acceptance)

Pure-function unit checks for `evaluate()` / `rollup()` (green/yellow/red
boundaries, complete-once, incomplete). Manual/integration checks:

1. Configure CPR = 730d, HIPAA = 365d, warning = 90d.
2. Employee 100% complete with HIPAA completed 300 days ago → 🟡 Expiring Soon;
   appears in the Expiring Soon KPI and filter; still counted compliant.
3. Same employee at 366 days → 🔴; **row label reads "Expired"** (not
   "Overdue"); overall status drops out of Compliant into the Overdue KPI
   **without any DB write** (simulate by moving the clock / completion date).
4. A never-started required course past its due date → **row label "Overdue"**;
   both it and the expired employee appear under the "Overdue" filter, while the
   "Expired" filter returns only the lapsed-re-cert cohort.
5. Status dropdown "Expiring Soon" returns exactly the yellow cohort.
6. A completed course with `completed_ts == 0` stays 🟢 (no false Red).
7. Confirm the aggregate transient key changes across a date boundary.

## 7. Out of scope (Phase 1)

- Employee-facing traffic-light UI / notifications (Phase 2).
- Automated reminder emails on expiry.
- Writing expiration back into LearnDash (re-enrollment).

## 8. Files touched

- **New:** `includes/class-course-lifespans.php`
- `gridhouse-admin-compliance-dashboard.php` (require, VERSION, cache key,
  `get_user_courses`, `build_employee_record`, `get_aggregate`, `render_kpis`,
  `get_status_options`, status filter, status rendering)
- `includes/class-settings.php` (two options, settings section, bust hooks)
- `includes/class-compliance-program.php` (per-course state on new-hire courses)
- `assets/dashboard.css` (yellow/red status + course-pill styling)
