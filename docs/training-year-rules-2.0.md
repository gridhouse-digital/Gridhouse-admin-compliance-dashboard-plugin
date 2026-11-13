# 2.0: the audit training year as the single source of truth

Version 2.0.0-rc.1. All of it ships in one release, behind one switch (`ghca_acd_training_year_rules`, off by default). With the switch off, the plugin behaves exactly as 1.7.3-rc.8 does. The only visible additions while it is off are:
- the dry-run page;
- the "Annual training requirement" field in the redesigned Edit Records form;
- the new-hire anchor setting;
- the lifespan guidance on the settings page.

## What changes when the rules are enabled

| # | Rule | Before | With training-year rules |
| --- | --- | --- | --- |
| 1 | Dashboard due date, non-new-hires | Fixed 365-day blocks from the earliest of account registration and group enrollment; fallback `ghca_compliance_due_date` (default Jul 31, 2026) | Last day of the audit training year (`resolve_annual_window()`): the day before the next hire anniversary, or Dec 31 for `calendar_year`. Comparisons use the last second of that day in the site time zone. No verified hire date: **"Hire date missing"**. No cycle configured: "Training year not configured". No fallback date. |
| 2 | Course lifespans | completion + N × 86,400 s, every course | Calendar days in the site time zone (`DateTime::modify('+N days')`), warning window likewise. No lifespan for courses mapped to an ODP category (they earn annual credit and follow the training year), so the drawer shows the training-year due date. The settings page recommends no lifespan for those courses. |
| 3 | Annual pass threshold | 24 h for everyone | Per employee, from the new "Annual training requirement" (§ 6100.143). 24 h: direct support professionals, life sharers providing HCBS or base-funded services, direct supervisors of DSPs. 12 h: management/program/administrative/fiscal; dietary/housekeeping/maintenance/ancillary; other 12-hour roles the agency confirms. Not set: 24 h is assumed (never under-requires) and the dry run lists it. |
| 4 | New-hire deadline | N days after the earliest of registration and group enrollment | N days after the verified hire date (hire day = day 0; all of day N is on time, as in the packet's 30-day check). Optional setting: count from the first service date when it is later (§ 6100.142(a): within 30 days after hire or after starting to provide a service). No hire date: "Hire date missing", never overdue. |
| 5 | Employee drawer | Next due only | Next due, plus "Training year {start} – {end}" and "Annual requirement: …" (legacy and redesigned drawers). |

The annual audit window itself (`resolve_annual_window()`, anniversary-based or calendar year, end exclusive and shown as the day before) is unchanged. Everything else now reads from it.

**Feb 29 hires.** PHP rolls Feb 29 to Mar 1 in a non-leap year. The windows run Mar 1 – Feb 28 in non-leap years and from Feb 29 in leap years. They are contiguous, with no gaps or overlaps, and the tests cover it.

## Dry run, then enable

**Compliance → Training year** (administrators only) evaluates every employee on the dashboard under both rule sets, in memory, and writes nothing. The integration test checks options and user meta byte for byte. The page shows:
- counts: employees, how many would change, due-date, status, annual-result and course-expiry changes, missing verified hire dates, missing requirements;
- a table of everyone who would change or is missing data, with old → new due date, status and annual result ("In progress · 13 of 24 h" → "Compliant · 13 of 12 h"), and course expiry changes; "Show all employees" lists everyone;
- **Download dry run (CSV)**, one row per employee, named `training-year-dry-run-{site host}-{date}.csv` (shared CSV writer, so formulas are neutralised);
- **Enable training-year rules**. It needs the "I reviewed the dry run" box, a nonce and `manage_options`. Who changed it and when is stored. **Turn training-year rules off** restores the previous behaviour immediately; saved requirements and hire dates are kept.

The same report runs from the command line, per site:

```bash
wp ghca-acd training-year-review --user=<admin login> --format=csv > training-year-dry-run.csv
```

`--format=table` (the default) lists only the employees who change or have missing data, plus the summary.

**Recommended order for each site:**
1. Install 2.0.0-rc.1 (the rules stay off).
2. Set the annual cycle if it is missing.
3. Enter missing verified hire dates and each employee's annual requirement (Edit Records).
4. Remove lifespans from courses mapped to ODP categories.
5. Run the dry run and keep the CSV.
6. Enable.

## Files and functions changed

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

- No staging or live site. The dry run has not been run on Isoke or Akansoh.
- Real LearnDash data was not used (stand-ins have no enrolments, so status changes from course data are untested on a real catalogue).
- WP-CLI is not installed in the test environment, so `wp ghca-acd training-year-review` was not run. It reuses the same `build()`/`csv_rows()` as the page.
- The § 6100.143 role list follows the regulation text and ODP's 2026 reminder as found online. The agency should confirm which of its job titles map to 24 or 12 hours.
