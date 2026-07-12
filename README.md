# Gridhouse Admin Compliance Dashboard

**Version:** 1.2.0
**Author:** Gridhouse Digital
**Text Domain:** `ghca-acd`
**Requires:** WordPress 6.0+, PHP 7.4+, LearnDash 4.0+

---

## What is this plugin?

The **Gridhouse Admin Compliance Dashboard** is a WordPress plugin that gives HR managers, compliance officers, and team leaders a clean, frontend interface to monitor employee training.

Instead of forcing administrators to navigate the complex WordPress backend to manually pull LearnDash reports, this plugin provides a suite of shortcodes that generate a beautiful, unified dashboard on the frontend of your website (designed for use with Elementor).

## What does it do?
- **Frontend Command Center**: Renders data visualization KPI cards, an interactive employee roster table, and priority action alerts.
- **Tracks Healthcare Compliance**: Actively monitors required compliance courses (like HIPAA, Bloodborne Pathogens) for all staff members across the organization.
- **Manages Training Lifecycles**: Gives brand new employees a configurable grace period to complete their onboarding training. For existing employees, it tracks their annual renewals (e.g., highlighting an employee in yellow 30 days before their HIPAA training expires).
- **Highlights At-Risk Employees**: Managers can instantly see a list of "Overdue" or "Expiring Soon" employees, allowing them to download a CSV report or log notes directly to the employee's FluentCRM profile without leaving the page.

---

## Key Features

- **Centralized Command Center**: View all employee training metrics in one unified dashboard.
- **Dynamic KPI Tracking**: Monitor total employees, compliant staff, employees currently in onboarding, and overdue personnel at a glance.
- **Rolling Course Expirations**: Robust compliance logic evaluates "Compliant," "Expiring Soon," and "Expired" states based on configurable course lifespans (e.g., 365-day validity with a 30-day warning window).
- **New Hire Onboarding Tracking**: Tracks employees inside their configurable onboarding window and automatically escalates them to "Overdue" if they miss the deadline.
- **Advanced Filtering**: Filter the employee roster table by Compliance Status, LearnDash Group, Job Role, and Search Query.
- **Deep Integrations**: Seamlessly works with LearnDash for progress/certificates, FluentCRM for logging compliance notes/reminders, and BuddyBoss for profile navigation.
- **One-Click CSV Export**: Easily download compliance reports for external auditing.
- **Dashboard Branding**: Customize the dashboard with your organization's name, logo, colors, and support email.
- **Granular User Permissions**: Assign specific dashboard overrides (Edit Records, Manage Announcements, Unrestricted View) to individual users, independent of their WordPress role.

---

## Compliance Rules Engine

The plugin uses a sophisticated rules engine to determine compliance status:

1. **New Hire Onboarding**: When a user registers, they are placed in a configurable onboarding window (default: 30 days). If they do not complete all required courses within that window, they become **New Hire Overdue**.
2. **Rolling Expirations**: Completed courses are tracked against a configurable lifespan (e.g., 365 days).
   - **Compliant (🟢)**: All courses are completed and valid.
   - **Expiring Soon (🟡)**: A completed course is entering its warning window (e.g., expires within 90 days).
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

> **Note on Scoping:** If the user is a **Group Leader**, the dashboard automatically scopes the data. They will only see statistics and roster entries for employees within the LearnDash groups they manage. Users with the **Unrestricted View** permission override this behavior and see all employees company-wide.

---

## Granular User Permissions

Managed from **Settings → Compliance Permissions** in wp-admin. Each field accepts a comma-separated list of WordPress User IDs.

| Permission | What it controls |
|---|---|
| **Edit Training Records** | Allows the user to manually alter course completion dates and timers via the Edit Records form. Without this, dashboard viewers can only read data. |
| **Manage Announcements** | Allows the user to create, edit, and delete global compliance dashboard announcements. |
| **Unrestricted View** | Allows the user to see all employees company-wide, bypassing LearnDash group scoping constraints. |

> WordPress administrators (`manage_options` capability) automatically have all three permissions. These settings exist to grant specific overrides to non-admin staff without promoting their WordPress role.

---

## Settings Pages

The plugin registers two separate pages under **Settings** in wp-admin:

### Settings → Compliance Admin
General dashboard configuration:
- **New Hire Compliance** — Select which LearnDash groups are new hire groups and set the completion window (days).
- **Dashboard Branding** — Customize primary/secondary/accent colors, organization name, logo URL, and support email.
- **Dashboard Performance** — Configure the at-risk window (days) and aggregate cache TTL (seconds).
- **Rolling Expirations & Traffic Light** — Set per-course lifespans (e.g., CPR = 730 days) and the warning window before expiry.

### Settings → Compliance Permissions
Per-user permission overrides (see [Granular User Permissions](#granular-user-permissions) above).

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
| `[admin_compliance_announcements]` | Admin-authored announcements panel (create/edit/delete controlled by the Manage Announcements permission). |
| `[admin_compliance_quick_links]` | Actionable quick links. |
| `[admin_compliance_support]` | Support contact box. |
| `[admin_compliance_user_report]` | Individual employee detail view with course-by-course breakdown and Edit Records form. |

---

## Integrations

- **LearnDash**: Core engine for groups, courses, enrollment, progress calculations, and certificates.
- **FluentCRM**: Clicking the "Log Note" action in the employee table opens a modal to instantly log a note on the user's FluentCRM profile.
- **BuddyBoss**: Injects the admin dashboard tab directly into the BuddyBoss profile navigation for seamless user experience.
- **Elementor**: All shortcodes are tested and optimized for Elementor rendering.

---

## Developer API & Filters

Developers can easily extend or modify the dashboard's behavior using the provided WordPress filters:

| Filter | Purpose |
|---|---|
| `ghca_compliance_group_ids` | Define the specific LearnDash Group IDs that are tracked for compliance. |
| `ghca_compliance_employee_roles` | Define which WordPress user roles are considered "Employees" (tracked on the dashboard). |
| `ghca_admin_dashboard_roles` | Modify the list of roles allowed to view the dashboard. |
| `ghca_course_lifespans` | Modify the rolling expiration dates and warning windows for specific LearnDash Course IDs. |
| `ghca_admin_quick_links` | Modify the Quick Link cards. |
| `ghca_admin_announcements_items` | Modify the Admin announcements panel. |
| `ghca_admin_support_email` | Change the support email address. |
| `ghca_employee_support_email` | Change the employee-facing support email address. |
| `ghca_user_report_back_label` | Customize the "Back to Dashboard" link text on user report pages. |

---

## Export API

The plugin provides a secure, nonce-protected endpoint for CSV generation:

```text
admin-post.php?action=ghca_acd_export_csv
```

This generates a full roster report including First Name, Last Name, Email, Status, Onboarding State, Roles, Groups, and Lifespan Data.

---

## Changelog

### 1.1.0
- **Added:** Granular User Permissions system (Edit Training Records, Manage Announcements, Unrestricted View) managed via a dedicated **Settings → Compliance Permissions** page.
- **Added:** Rolling course expirations with configurable per-course lifespans and a traffic-light status system (🟢 🟡 🔴).
- **Added:** Dashboard Branding settings (primary/secondary/accent colors, organization name, logo, support email).
- **Added:** Individual user report shortcode (`[admin_compliance_user_report]`).
- **Fixed:** Dashboard viewers could previously modify training records without explicit edit permission (privilege escalation).
- **Fixed:** Announcement management was accessible to all dashboard viewers instead of only authorized users.
- **Fixed:** Non-admin users with unrestricted view now see consistent data across KPI cards, scope banners, and roster tables.

### 1.0.0
- Initial release with KPI dashboard, employee roster, CSV export, FluentCRM integration, and BuddyBoss navigation.
