# Overview and architecture

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will explain how the dashboard, LearnDash, Jotform, Drive, packets, and messaging fit together. The admin menu below is already checked against the code so later pages can use the same names.

## Prerequisites

Coming soon.

## Steps

Coming soon. The plugin registers one top-level wp-admin menu, **Gridhouse Compliance**. These are the submenu labels in registration order:

| Menu label | Page title | Who can open it |
| --- | --- | --- |
| Agency Profile | Agency Profile | `manage_options` |
| Settings | Admin Compliance Dashboard | `manage_options` |
| Permissions | Compliance Permissions | `manage_options` |
| Messaging | Compliance Messaging | Users allowed to manage messaging settings or templates. Others do not get the item. |
| Audit Mapping | Audit Mapping | `manage_options` |
| Jotform Documents | Jotform Documents | `manage_options` |
| External Training Review | External Training Review | `manage_options` |
| Training Catalog | External Training Catalog | `manage_options` |
| OLTL Readiness | OLTL Training Readiness | `manage_options` |

The menu label **Training Catalog** and the page heading **External Training Catalog** are different. Use the menu label when you are clicking, and the page heading when you are checking that the right screen opened.

Compliance Leads review external training on the front-end dashboard. The wp-admin **External Training Review** item is the administrator screen.

## Verification

Coming soon.

## Troubleshooting

Coming soon. Drive errors are mapped in [Drive troubleshooting](google-drive/troubleshooting.md).

## Related pages

- [Guide home](README.md)
- [Installation and wp-config constants](installation.md)
- [How Drive evidence works](google-drive/how-it-works.md)
- [Jotform documents and external training review](jotform-and-external-training.md)

## Sources

Menu registration in `includes/class-settings.php`, `includes/class-audit-mapping.php`, `includes/jotform/class-jotform-ui.php`, and `includes/oltl/class-oltl-ui.php`.
