# Messaging

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will cover employee email reminders and optional Twilio SMS from **Gridhouse Compliance → Messaging**.

## Prerequisites

Coming soon.

## Steps

Coming soon. SMS stays off until `GHCA_ACD_TWILIO_ENCRYPTION_KEY` is defined outside the database as at least 32 characters. That key is not the evidence encryption key and not the Drive JSON.

Email and SMS do not upload certificates to Drive and do not approve external training.

## Verification

Coming soon.

## Troubleshooting

Coming soon.

## Related pages

- [Guide home](README.md)
- [Installation and wp-config constants](installation.md)
- [Roles and permissions](roles-and-permissions.md)
- [Security model](security-model.md)

## Sources

`includes/messaging/class-messaging-secret-store.php` and the Messaging submenu in `includes/class-settings.php`. Release-note behavior in the plugin README is not repeated here.
