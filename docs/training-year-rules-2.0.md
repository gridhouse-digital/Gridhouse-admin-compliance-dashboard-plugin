# 2.0: the audit training year as the single source of truth

Version 2.0.0-rc.2 (fix-up on rc.1, GRI-115 prompt v2 and the role-mapping comment). All of it ships in one release, behind one switch (`ghca_acd_training_year_rules`, off by default; the name is unchanged). With the switch off, due dates, thresholds, deadlines and every existing screen are identical to 1.7.3-rc.8. The Settings page HTML was compared byte for byte with `eeec7da` on the test site. The only addition while it is off is the **Compliance → Training year** page (role map, dry run, switch).

## What changes when the rules are enabled

| # | Rule | Before | With training-year rules |
| --- | --- | --- | --- |
| 1 | Dashboard due date, non-new-hires | Fixed 365-day blocks from the earliest of account registration and group enrollment; fallback `ghca_compliance_due_date` (default Jul 31, 2026) | Last day of the audit training year (`resolve_annual_window()`): the day before the next hire anniversary, or Dec 31 for `calendar_year`. Comparisons use the last second of that day in the site time zone. No verified hire date: **"Hire date missing"**. No cycle configured: "Training year not configured". No fallback date. |
| 2 | Course lifespans | completion + N × 86,400 s, every course | Calendar days in the site time zone (`DateTime::modify('+N days')`), warning window likewise. No lifespan for courses mapped to an ODP category (they earn annual credit and follow the training year), so the drawer shows the training-year due date. The settings page recommends no lifespan for those courses. |
| 3 | Annual pass threshold | 24 h for everyone | Resolved per employee from the **role map** (below), with a per-employee **override** in Edit Records, else 24 h flagged **"Role requirement not set"**. Used by `annual_status()`, the packet and the drawer target. |
| 4 | New-hire deadline | N days after the earliest of registration and group enrollment | N days after the verified hire date (hire day = day 0; all of day N is on time, as in the packet's 30-day check). Optional setting: count from the first service date when it is later (§ 6100.142(a): within 30 days after hire or after starting to provide a service). No hire date: "Hire date missing", never overdue. |
| 5 | Employee drawer | Next due only | Next due, plus "Training year {start} – {end}" and "Annual requirement: …" (legacy and redesigned drawers). |

The annual audit window itself (`resolve_annual_window()`, anniversary-based or calendar year, end exclusive and shown as the day before) is unchanged. Everything else now reads from it.

**Feb 29 hires.** PHP rolls Feb 29 to Mar 1 in a non-leap year. The windows run Mar 1 – Feb 28 in non-leap years and from Feb 29 in leap years. They are contiguous, with no gaps or overlaps, and the tests cover it.

## Role map (rc.2)

On the **Training year** page (fillable while the switch is off; the dry run uses it). Each GHCA job role maps to one value: the plugin's active built-in employee roles, the agency's custom roles, and the other registered non-management roles, such as LPN or RN. Basis shown in the UI: "Per 55 Pa. Code §6100.143; nurse mapping is agency policy."

| Value | Hours | Meaning |
| --- | --- | --- |
| `24` | 24 | § 6100.143(a): direct service professionals and life sharers who provide an HCBS or base-funding service to the individual; direct supervisors of direct service professionals |
| `12` | 12 | § 6100.143(b): management, program, administrative, fiscal, dietary, housekeeping, maintenance and ancillary staff; consultants and contractors who work alone with individuals; volunteers and paid/unpaid interns who work alone with individuals |
| `none_owner` | 0 | Not required: § 6100.143(b)(1) building-owner exception |
| `none_contractor` | 0 | Not required: § 6100.143(b)(2) short-term licensed contractor exception |
| `none_volunteer` | 0 | Not required: volunteer or intern who never works alone with individuals |
| (unset) | 24 | **Role requirement not set**: counts as 24 h and is flagged; never silently 12 h |

"Not required" is offered only with its exception. **Nurse roles** (slug or title matching LPN, RN, nurse or nursing) show 24 h as **agency policy, not ODP text**. That default is computed, never auto-saved, and is editable like any other role. No other role is pre-filled. The map is stored in `ghca_acd_training_role_map`; only administrators can save it, and saving clears the dry-run cache.

**Resolution** (`GHCA_ACD_Training_Year::resolve()`):
1. A valid per-employee **override** (Edit Records field, shown only with the switch on) wins. Source: `override`.
2. Otherwise, use the employee's GHCA job roles through the map. With several roles the **highest wins** (24 > 12 > not required). Source: `role_map`.
3. If no role is mapped, or an unmapped role could need more than the mapped ones: **24 h**, `set` false, label **"Role requirement not set"**. Source: `default`.

The wording is "direct service professional" throughout. The rc.1 phrases "direct support professional", "Not set — 24 h assumed" and "Not set (24 h assumed)" are gone.

## Employee API (stable contract)

The employee plugin (1.6.4, written separately) calls only these public static methods on `GHCA_ACD_Training_Year`, each guarded with `class_exists` / `method_exists`. They will not be renamed and their return keys will not change.

| Method | Returns |
| --- | --- |
| `enabled(): bool` | True when `ghca_acd_training_year_rules` is on. Honours `set_override()`. Callers must not read the option themselves. |
| `set_override( ?bool $value ): void` | Evaluate as if on/off; `null` returns to the stored switch. |
| `due( int $user_id, ?int $now = null ): array` | `due_timestamp` (int: last second of the last training-year day in the site time zone, or 0), `due_date_label` (e.g. "October 19, 2027", "Hire date missing", "Training year not configured"), `training_year_label` |
| `window( int $user_id, ?int $now = null ): array` | `start_ts`, `end_ts` (exclusive), `start_date` (m/d/Y or ''), `end_date` (inclusive last day, m/d/Y or ''), `missing` ('' / 'hire' / 'cycle'), `label` ("M j, Y – M j, Y"). From `GHCA_Audit_Calculator::resolve_annual_window()`. |
| `requirement( int $user_id ): array` | `key`, `label`, `hours` (24, 12, or 0 = not required), `set` (false when unmapped/default), `source` (`override` / `role_map` / `default`) |
| `required_hours( int $user_id ): int` | The resolved hours with the switch on; 24 while it is off |
| `course( int $user_id, int $course_id, bool $completed, int $completed_ts, ?int $now = null ): array` | `expiration_ts`, `expiration_label` (`wp_date( 'M j, Y' )` or ''), `compliance_state` (incomplete / current / expiring_soon / expired), `lifespan_days`, `follows_training_year`. See the rules below. |
| `is_annual_course( int $course_id ): bool` | True when the course is mapped to an ODP category |

Rules for `course()`:
- **Annual course** (`is_annual_course()`): it follows the training year.
  - `expiration_ts` = `due( $user_id, $now )['due_timestamp']`, `lifespan_days` 0, `follows_training_year` true.
  - The state is computed against that date and the site warning-days window.
  - An incomplete course stays `incomplete` but still carries the date.
- **Other course with a lifespan:** calendar-day expiry in the site time zone, the same policy as `decorate()` with the rules on.
- **No lifespan:** `expiration_ts` 0, does not expire.

`GHCA_Course_Lifespans::decorate()` keeps its three-parameter signature. `GHCA_Audit_PDF::append_certificate( Fpdi $pdf, string $path, int &$total_pages, array $label = array() )` is unchanged.

## Dry run, then enable

**Compliance → Training year** (administrators only) evaluates every tracked employee under both rule sets.

**The comparison writes nothing:** no option, user meta, cron, email or record, and not the dashboard aggregate transient.
- Employee ids come from `GHCA_ACD_Data_Provider::review_employee_ids()`, which bypasses `get_aggregate()`.
- The integration test compares every option row (transients included) and all user meta, byte for byte.

**Not recomputed on page load:**
- "Run the dry run" stores the result in a **separate dry-run cache** (transient `ghca_acd_ty_dryrun_{admin id}`, 12 h). That is the only write, and the test confirms it.
- The page and the CSV read that cache.
- The table is paginated, 50 rows per page.

The page shows:
- the role map;
- counts: employees, how many would change, due-date, status, annual-result, new-hire-deadline and course-expiry changes, missing verified hire dates, role requirement not set;
- a table of everyone who would change or is missing data (no hire date, or role requirement not set). Columns: requirement and **threshold source**, **old → new training-year window** (the previous 365-day block → the audit year), old → new due date, status and annual result ("In progress · 13 of 24 h" → "Compliant · 13 of 12 h"), **old → new new-hire deadline**, and course expiry changes. "Show all employees" lists everyone;
- **Download dry run (CSV)**, one row per employee, named `training-year-dry-run-{site host}-{date}.csv` (shared CSV writer, so formulas are neutralised);
- **Enable training-year rules**. It needs the "I reviewed the dry run" box, a nonce and `manage_options`. Who changed it and when is stored. **Turn training-year rules off** restores the previous behaviour immediately; saved requirements and hire dates are kept.

The same report runs from the command line, per site:

```bash
wp ghca-acd training-year-review --user=<admin login> --format=csv > training-year-dry-run.csv
```

`--format=table` (the default) lists everyone who changes or has missing data, now including role requirement not set, plus the summary. CSV lists everyone, with the same columns as the page's CSV.

**Recommended order for each site:**
1. Install 2.0.0-rc.2 (the rules stay off).
2. Set the annual cycle if it is missing.
3. Fill in the role map on the Training year page, and enter missing verified hire dates (Edit Records).
4. Remove lifespans from courses mapped to ODP categories.
5. Run the dry run and keep the CSV.
6. Enable.

## rc.2: files and functions changed (on top of rc.1 `f774878`)

| File | Change |
| --- | --- |
| `includes/class-training-year.php` | New: `OPTION_ROLE_MAP`, `role_map_choices()`, `basis_text()`, `role_catalog()`, `is_nurse_role()`, `role_map()`, `sanitize_role_map()`, `role_value()`, `resolve()`, `default_requirement()`, `course()`. Changed: `requirements()` (override list, "direct service professional" wording, not-required exceptions), `requirement()` (resolution with `source`), `save_requirement()` (compares the stored override), `window()` (keys in contract order). Unchanged: `enabled()`, `set_override()`, `due()`, `required_hours()`, `is_annual_course()`. |
| `includes/class-training-year-review.php` | Rewritten around the same class. New: `ACTION_RUN`, `ACTION_MAP`, `source_label()`, `cached()`, `run_and_cache()`, `handle_run()`, `handle_role_map()`, `role_map_html()`, `cli_table_rows()`. Changed: `build()` (source, old/new windows, old/new new-hire deadlines, missing-data flag), `employee_ids()` (no aggregate), `csv_rows()`, `render_page()` (role map, cached and paginated dry run), `download_csv()` (cache), `cli()` (missing-data rows). |
| `includes/class-data-provider.php` | New: `review_employee_ids()`, `legacy_cycle_label()` |
| `includes/class-redesign-edit.php` | `render()`: override field only with the switch on |
| `includes/class-ajax-handlers.php` | `render_legacy_edit_form()`: override wording. `edit_records_model()`: `training_requirement` is the stored override |
| `includes/class-settings.php` | `render_page()`: new-hire start-date setting and the training-year lifespan notes only with the switch on (output byte-identical to pre-rc.1 when off) |
| `includes/class-redesign-settings.php` | `shell()`: section map (`data-ghca-set-groups`) and the "Settings not saved" alert for invalid fields |
| `assets/settings-redesign.js` | On `invalid`: open the section that holds the field, show the error, focus the field |
| `includes/class-audit-packet-layout.php` | `annual_summary()`, `gaps()`: wording when annual hours are not required (0 h) |
| `gridhouse-admin-compliance-dashboard.php` | Version 2.0.0-rc.2 |

### rc.2 tests

- `tests/test-training-year-api.php` (new, 43 checks, no WordPress):
  - "unmapped role → 24 h, set=false, "Role requirement not set", source default";
  - "multi-role: 12 h and 24 h roles → 24 h (highest wins), source role_map";
  - "a 12 h role plus an unmapped role never becomes 12 h silently";
  - nurse agency policy, exceptions, override;
  - wording checks;
  - "switch off: required_hours() and the calculator stay at 24 h … (identical to before)";
  - **employee API parity**: `enabled()`, `window()`, `due()`, `requirement()`, `required_hours()` and `course()` against `resolve_annual_window()`, the resolver and `GHCA_Course_Lifespans::evaluate()` for a fixture user and courses.
- `tests/test-training-year.php` (39): the v2 cases, unchanged: Feb 29 hire, completion on the anniversary, calendar-year employee hired mid-year, 12-hour role.
- `tests/integration-training-year.php` (45, real WordPress + MariaDB):
  - "the dry run writes nothing: every option row (including the dashboard aggregate transient) and all user meta byte-identical";
  - "running the dry run writes only its own cache";
  - "page load reads the cached dry run (no recomputation, nothing written)";
  - role map save and permissions;
  - real multi-role and nurse users;
  - source, old/new window and deadline columns;
  - "command-line table includes … role requirement not set";
  - "switch off: due date is the previous 365-day block …", "switch off: threshold stays 24 h …", "switch off: Settings shows no new-hire start-date setting …", "switch off: the drawer shows no training-year lines".
- `tests/integration-packet-layout.php` (42): "append_certificate signature unchanged: Fpdi, string, int &$total_pages, optional $label" and "append_certificate by-reference packet path: $total_pages grows by the certificate's page count".
- `tests/browser-settings-redesign.cjs` (16): "an invalid value in a hidden section: the save stops visibly, the section with the field opens, the reason is shown and the field is focused".
- `tests/test-redesign-edit.php` (68): "rules off: … exactly the same field names", "rules off: neither form shows the annual-requirement override", "rules on: both forms … including training_requirement".

## Files and functions changed (rc.1)

| File | Change |
| --- | --- |
| `includes/class-training-year.php` (new) | `GHCA_ACD_Training_Year`: `enabled()`, `set_override()`, `requirements()`, `requirement()`, `required_hours()`, `save_requirement()`, `window()`, `due()`, `new_hire_anchor_rule()`, `new_hire_anchor()`, `add_days()`, `is_annual_course()`, `sanitize_anchor()` |
| `includes/class-training-year-review.php` (new) | `GHCA_ACD_Training_Year_Review`: `init()`, `register_page()`, `build()`, `annual()`, `employee_ids()`, `csv_rows()`, `render_page()`, `download_csv()`, `handle_switch()`, `cli()` |
| `includes/class-audit-calculator.php` | `annual_status()` takes the required hours (default 24). `calculate_employee_audit_data()` uses `GHCA_ACD_Training_Year::required_hours()` and returns `required_annual_hrs` and `required_annual_label`. `new_hire_program_report()` returns `deadline_basis`. |
| `includes/class-data-provider.php` | `get_compliance_cycle()` uses `GHCA_ACD_Training_Year::due()` under the rules. `build_employee_record()` adds `training_year_label`, `annual_requirement_label` and `annual_required_hours`, with no fallback due date under the rules. `get_aggregate()` has no fallback "upcoming due" date under the rules. `get_cache_key()` is keyed by the switch. New: `training_year_rules()`, `training_year_fields()`, `employee_record_for_review()`. |
| `includes/class-compliance-program.php` | `get_user_status()` cache keyed by the switch. `compute_user_status()` anchors on the verified hire date or first service date under the rules, handles a missing hire date, and reports `deadline_basis`. New: `training_year_rules()`. |
| `includes/class-course-lifespans.php` | `evaluate()` takes an optional time zone for calendar-day arithmetic. `decorate()` ignores lifespans on annual courses and uses calendar days under the rules. |
| `includes/class-audit-packet-layout.php` | `annual_summary()` shows hours "of 24/12" and the requirement. `annual_status()` (split ISP summary) and `gaps()` use the employee's threshold. `orientation_key_dates()` words the new-hire deadline basis. New: `required()`. |
| `includes/class-ajax-handlers.php` | `drawer_model()` adds the training-year fields. `render_legacy_drawer()` shows them under the rules. `edit_records_model()` adds `training_requirement`. `render_legacy_edit_form()` shows the requirement under the rules. `ajax_save_employee_records()` saves `training_requirement` (validated; Edit Records permission and employee scope as before). |
| `includes/class-redesign-drawer.php` | `training_html()` shows the training year and requirement under the rules. |
| `includes/class-redesign-edit.php` | `render()` adds the "Annual training requirement" field (always, so it can be set before enabling). |
| `includes/class-settings.php` | `register_settings()` adds `ghca_acd_new_hire_anchor`. `render_page()` adds the new-hire anchor choice and lifespan guidance (marks lifespans on annual courses). Cache busts on the new options. |
| `gridhouse-admin-compliance-dashboard.php` | Version 2.0.0-rc.1. Loads and initialises the two new classes. |
| `design-references/gh-redesign.tail.css`, `assets/gh-redesign.css` | `.gh-dr-ty` (drawer lines). |

## Tests

| Test | Checks | Covers |
| --- | --- | --- |
| `tests/test-training-year.php` (new) | 36 | Feb 29 hire: Mar 1 – Feb 28 in a non-leap year, then Feb 29 in a leap year, contiguous; due Feb 28, 2028 to the last second. A completion at 00:00 on the anniversary counts in the new year, one second earlier in the old. Calendar-year hire mid-year: Jun 15 – Dec 31, due Dec 31, then a full year. Missing hire date and missing cycle labels. 12-hour role: 13 h compliant against 12 with the rules on, in progress against 24 with them off; DSP and unset stay at 24. Each requirement's hours. Packet "of 12". Lifespans: calendar days across DST and Feb 29, warning window, annual courses without lifespan, unmapped courses keep it, and no change while the rules are off. New-hire anchor: hire date, first service date only when allowed and later, missing. `add_days()` across DST. |
| `tests/integration-training-year.php` (new) | 25 | Real WordPress + MariaDB. The dry run and the admin page write nothing. Expected due-date change for an anniversary employee. "Hire date missing". 12-hour annual result change. Summary counts. CSV (MISSING, formula neutralising). The switch refuses enabling without the dry-run confirmation, for non-admins, and with a bad nonce, and records who and when. Records and both drawers once enabled. The requirement saves through Edit Records; unknown values and users without permission are refused. Turning off restores the previous due date. |
| Updated | — | `test-redesign-edit.php`: field-name parity now allows `training_requirement` in the redesigned form while the rules are off, and requires it in both forms when they are on. The legacy drawer and edit form stay byte-identical while the rules are off (golden hashes unchanged). |

## Not verified

- rc.2: the zero-write dry run was proven with the LearnDash stand-ins. Real LearnDash's `learndash_get_groups_user_ids()` may keep its own object or transient cache; that is LearnDash's cache, not the plugin's, and was not tested here.
- rc.2: the role map was tested with synthetic roles (office administrator, direct service professional, LPN). Each agency's real role list and the mapping it chooses are not verified.

- No staging or live site. The dry run has not been run on Isoke or Akansoh.
- Real LearnDash data was not used (stand-ins have no enrolments, so status changes from course data are untested on a real catalogue).
- WP-CLI is not installed in the test environment, so `wp ghca-acd training-year-review` was not run. It reuses the same `build()`/`csv_rows()` as the page.
- The § 6100.143 role list follows the regulation text and ODP's 2026 reminder as found online. The agency should confirm which of its job titles map to 24 or 12 hours.

## Package

| | |
| --- | --- |
| File | `Gridhouse-admin-compliance-dashboard-2.0.0-rc.1-f774878.zip` (root folder `Gridhouse-admin-compliance-dashboard-plugin-main/`) |
| Commit | `f774878e0d281b9de17985d171ce50fee537a3bd` |
| Size | 770,342 bytes |
| SHA-256 | `15675524dca957c9b2e7b0495b4815f40a848fcd7aa4fd3ccb5a1c0131a9855a` |
| External BUILD.txt SHA-256 | `8d8ca2ded2a562f730a53a738aa4d6a4aa2bbeae6af952a3d06f836b223fbc12` |
| Manifest | 151 runtime files with SHA-256, inside the archive and alongside it |
| Supersedes | `eeec7da` (prepared, not installed) |

**Verification:**
- `unzip -t` clean.
- Every file matches the commit and the tested site, every hash is in the manifest, `php -l` passes, and no tests are in the package.
- Counts were measured on the committed files: integration-training-year 25, integration-packet-layout 39, integration-packet-ready 29, browser-settings-redesign 15, test-training-year 36, and 84 unit test files.

**Not installed anywhere.** Install with the rules off, run the dry run on each site, then enable. Ship as one release; no partial deploys.
