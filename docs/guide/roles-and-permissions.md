# Roles and permissions

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will describe who can see the dashboard, edit records, send reminders, and review external training.

## Prerequisites

Coming soon.

## Steps

Coming soon. These facts are already in the code and will be expanded here:

- Custom roles registered by the plugin are HR Manager (`hr_manager`), Compliance Lead (`compliance_lead`), and Training Manager (`training_manager`).
- wp-admin screens under **Gridhouse Compliance** require `manage_options`, except **Messaging**, which opens for users allowed to manage messaging settings or templates.
- External training review and approval require full dashboard access: a WordPress administrator, or the Compliance Lead role.
- The wp-admin review screen is **Gridhouse Compliance → External Training Review**. Compliance Leads use the front-end review.
- Extra per-user overrides are edited on **Gridhouse Compliance → Permissions**.

## Verification

Coming soon.

## Troubleshooting

Coming soon.

## Related pages

- [Guide home](README.md)
- [Overview and architecture](overview.md)
- [Jotform documents and external training review](jotform-and-external-training.md)
- [Employees and groups](employees-and-groups.md)

## Sources

`includes/class-roles.php` and the menu registration listed in [Overview and architecture](overview.md).
