# Dashboard redesign, phase 1

Opt-in overview and the ODP bulletin reader. Training math, audit records, capabilities, group scope, settings values, and integrations are unchanged.

## What shipped

- Scoped tokens in `assets/gh-redesign.css` and a reusable copy in `design-references/tokens.css`.
- Settings checkbox `ghca_acd_ui_redesign`, default off. The previous overview remains the default.
- When on, the admin overview uses the existing aggregate: employee counts, compliance rate `(completed + expiring soon) / employees`, tracked course count, certificate issued/missing figures, 30-day course completions, and group rows from `GHCA_ACD_Scoping::build_group_summary_rows()`.
- Header actions are the existing Sync, Export, Manage users, and View reports targets.
- Other tabs still render the current tables, audit UI, and external-training review. They are not redesigned.
- Bulletin ticker, drawer, library, and full page. See `docs/odp-bulletins.md`.

Group chips are labels on the existing risk split (under 60 high, under 85 medium, else low) plus the overdue count already on the row. They are not a new compliance rule. An employee who fits none of the four aggregate buckets is shown as Other so the donut still adds up.

## Settings that were not added

The settings mock has controls this plugin does not store. They were not invented:

- No bulletin composer, audience publisher, or sheet connection.
- No theme picker. `ghca_acd_ui_theme` accepts `gridhouse`, `akansoh`, or `purple`. Saved branding colors override primary and accent only when they differ from the built-in defaults. Status colors stay fixed.
- No separate “pending issuance” count. The overview shows issued certificates, course completions in the existing 30-day window, and completed courses that have a certificate configured but no link.
- No assignment-level completion fraction. The completion card uses the existing employee compliance rate.
- Unsaved changes and the sticky save bar already exist in `assets/settings-console.js`. The 16 visual settings boards were not rebuilt.

## Screens still on the legacy UI

Frontend tabs inside the opt-in shell: Employees, Inactive, Overdue & at risk (same priority table), External training, Audit data, Courses, Certificates, Reports.

Also unchanged: employee drawer, certificate modal, announcement modal, reminder and PDF flows, user report, manage users, SMS consent, wp-admin permissions, messaging, audit mapping, Jotform, catalog, and OLTL screens.

Agency announcements stay separate from ODP bulletins.

## Employee plugin

Do not merge it into this plugin. Contract: `docs/odp-bulletins.md`. This workspace has no second repository. The employee dashboard is still the `employee-dashboard` page and the `.ghca-ecd` brand hook.
