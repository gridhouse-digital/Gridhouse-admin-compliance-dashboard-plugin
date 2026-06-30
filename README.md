# Gridhouse Admin Compliance Dashboard

HR/compliance command center for LearnDash employee training oversight.

## URL

`/compliance-admin-dashboard/`

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[admin_compliance_dashboard]` | Full dashboard |
| `[admin_compliance_login_gate]` | Access gate |
| `[admin_compliance_scope_banner]` | Shows current data scope |
| `[admin_compliance_header]` | Command header |
| `[admin_compliance_kpis]` | KPI cards |
| `[admin_compliance_group_summary]` | Group comparison bars |
| `[admin_overdue_employees]` | Priority table |
| `[admin_course_completion_overview]` | Course stats |
| `[admin_employee_compliance_table]` | Filterable roster |
| `[admin_certificate_tracking]` | Certificate metrics |
| `[admin_compliance_export_button]` | CSV download button |
| `[admin_compliance_announcements]` | Admin notes |
| `[admin_compliance_quick_links]` | Quick links |
| `[admin_compliance_support]` | Support box |

## Roles & access

Custom roles (auto-registered):

- `hr_manager`
- `compliance_lead`
- `training_manager`

Capability: `view_compliance_admin_dashboard`

Also allowed: administrator, group_leader, editor, ld_instructor.

**Group leaders** only see employees in LearnDash groups they administer.

## Settings

WP Admin → Settings → Compliance Admin

- At-risk window (days)
- Aggregate cache TTL (seconds)

## Integrations

- **LearnDash** — groups, courses, progress, certificates
- **FluentCRM** — log reminder notes, link to contact profile
- **BuddyBoss** — nav injection + profile nav tab
- **Elementor** — page layout via shortcode widgets

## Export

CSV export via header action or `[admin_compliance_export_button]`.

Endpoint: `admin-post.php?action=ghca_acd_export_csv` (nonce-protected).

## Filters

- `ghca_compliance_group_ids` — compliance group IDs
- `ghca_compliance_employee_roles` — employee role slugs
- `ghca_admin_dashboard_roles` — allowed viewer roles
- `ghca_admin_quick_links` — quick link cards
- `ghca_admin_announcements_items` — admin notes
- `ghca_admin_support_email` — support email
