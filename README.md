# Gridhouse Admin Compliance Dashboard

## What is this plugin?

The **Gridhouse Admin Compliance Dashboard** is a WordPress plugin that gives HR managers, compliance officers, and team leaders a clean, frontend interface to monitor employee training. 

Instead of forcing administrators to navigate the complex WordPress backend to manually pull LearnDash reports, this plugin provides a suite of shortcodes that generate a beautiful, unified dashboard on the frontend of your website (designed for use with Elementor). 

## What does it do?
- **Frontend Command Center**: It renders data visualization KPI cards, an interactive employee roster table, and priority action alerts.
- **Tracks Healthcare Compliance**: It actively monitors required compliance courses (like HIPAA, Bloodborne Pathogens) for all staff members across the organization.
- **Manages Training Lifecycles**: It gives brand new employees a 90-day "grace period" to complete their onboarding training. For existing employees, it tracks their annual renewals (e.g., highlighting an employee in yellow 30 days before their HIPAA training expires).
- **Highlights At-Risk Employees**: Managers can instantly see a list of "Overdue" or "Expiring Soon" employees, allowing them to download a CSV report or log notes directly to the employee's FluentCRM profile without leaving the page.

---

## Key Features

- **Centralized Command Center**: View all employee training metrics in one unified dashboard.
- **Dynamic KPI Tracking**: Monitor total employees, compliant staff, employees currently in onboarding, and overdue personnel at a glance.
- **Rolling Course Expirations**: Robust compliance logic evaluates "Compliant," "Expiring Soon," and "Expired" states based on configurable course lifespans (e.g., 365-day validity with a 30-day warning window).
- **New Hire Onboarding Tracking**: Tracks employees inside their 90-day onboarding window and automatically escalates them to "Overdue" if they miss the deadline.
- **Advanced Filtering**: Filter the employee roster table by Compliance Status, LearnDash Group, Job Role, and Search Query.
- **Deep Integrations**: Seamlessly works with LearnDash for progress/certificates, FluentCRM for logging compliance notes/reminders, and BuddyBoss for profile navigation.
- **One-Click CSV Export**: Easily download compliance reports for external auditing.

---

## Compliance Rules Engine

The plugin uses a sophisticated rules engine to determine compliance status:

1. **New Hire Onboarding**: When a user registers, they are placed in a 90-day onboarding window. If they do not complete all required courses within 90 days, they become **New Hire Overdue**.
2. **Rolling Expirations**: Completed courses are tracked against a configurable lifespan (e.g., 365 days).
   - **Compliant (🟢)**: All courses are completed and valid.
   - **Expiring Soon (🟡)**: A completed course is entering its warning window (e.g., expires within 30 days).
   - **Expired (🔴)**: A completed course has passed its lifespan date and must be retaken.
3. **In Progress**: The user has started or completed some courses, but is not fully compliant yet.
4. **Not Started**: The user has not started any courses.

---

## Roles & Access

Access to the dashboard is tightly controlled via a custom capability: `view_compliance_admin_dashboard`.

**Auto-registered Custom Roles:**
- `hr_manager`
- `compliance_lead`
- `training_manager`

**Additional Allowed Roles:**
- `administrator`
- `editor`
- `group_leader`
- `ld_instructor`

> **Note on Scoping:** If the user is a **Group Leader**, the dashboard automatically scopes the data. They will only see statistics and roster entries for employees within the LearnDash groups they manage.

---

## Shortcode Reference

The dashboard is built entirely on shortcodes, allowing you to design the layout precisely as you want in Elementor.

| Shortcode | Purpose |
|---|---|
| `[admin_compliance_dashboard]` | Outputs the full predefined dashboard layout. |
| `[admin_compliance_login_gate]` | Access gate (prompts login or denies access based on role). |
| `[admin_compliance_scope_banner]` | Displays the current data scope (e.g., showing which groups a Group Leader is viewing). |
| `[admin_compliance_header]` | The command header with the title and export button. |
| `[admin_compliance_kpis]` | The 4-column KPI metric cards. |
| `[admin_compliance_group_summary]` | Group comparison progress bars. |
| `[admin_overdue_employees]` | Priority table highlighting employees needing immediate attention. |
| `[admin_course_completion_overview]` | Module-by-module course completion statistics. |
| `[admin_employee_compliance_table]` | The primary, filterable employee compliance roster. |
| `[admin_certificate_tracking]` | Quick certificate download metrics. |
| `[admin_compliance_export_button]` | A standalone CSV download button. |
| `[admin_compliance_announcements]` | Static admin notes and announcements panel. |
| `[admin_compliance_quick_links]` | Actionable quick links. |
| `[admin_compliance_support]` | Support contact box. |

---

## Integrations

- **LearnDash**: Core engine for groups, courses, enrollment, progress calculations, and certificates.
- **FluentCRM**: Clicking the "Log Note" action in the employee table opens a modal to instantly log a note on the user's FluentCRM profile.
- **BuddyBoss**: Injects the admin dashboard tab directly into the BuddyBoss profile navigation for seamless user experience.
- **Elementor**: All shortcodes are tested and optimized for Elementor rendering.

---

## Developer API & Filters

Developers can easily extend or modify the dashboard's behavior using the provided WordPress filters:

- `ghca_compliance_group_ids` — Define the specific LearnDash Group IDs that are tracked for compliance.
- `ghca_compliance_employee_roles` — Define which WordPress user roles are considered "Employees" (tracked on the dashboard).
- `ghca_admin_dashboard_roles` — Modify the list of roles allowed to view the dashboard.
- `ghca_course_lifespans` — Modify the rolling expiration dates and warning windows for specific LearnDash Course IDs.
- `ghca_admin_quick_links` — Modify the Quick Link cards.
- `ghca_admin_announcements_items` — Modify the Admin announcements panel.
- `ghca_admin_support_email` — Change the support email address.

## Export API

The plugin provides a secure, nonce-protected endpoint for CSV generation:
`admin-post.php?action=ghca_acd_export_csv`

This generates a full roster report including First Name, Last Name, Email, Status, Onboarding State, Roles, Groups, and Lifespan Data.
