# Installation and wp-config constants

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will be the install and upgrade procedure. The constant names below exist in this version. Only the Drive credential path is fully documented today.

## Prerequisites

Coming soon.

## Steps

Coming soon, except for the Drive credential file. Do that in [Server and wp-config](google-drive/server-setup.md).

These constants are read from PHP configuration, not from wp-admin fields. Define them in `wp-config.php` above the “stop editing” line, or in a host file that loads before the plugin. Do not put secret values in the database or in git.

| Constant | Role in 1.7.3-rc.1 | Documented |
| --- | --- | --- |
| `GHCA_ACD_PRIVATE_DIR` | Existing directory outside WordPress and the uploads folder. Approved evidence and the Drive JSON must live under it. | [Server and wp-config](google-drive/server-setup.md) |
| `GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED` | Windows only. Must be boolean `true` after the directory ACL is owner-only. | [Server and wp-config](google-drive/server-setup.md) |
| `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` | Full path of one service-account JSON file inside the private directory. | [Server and wp-config](google-drive/server-setup.md) |
| `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` | Stable secret, at least 32 characters, used to encrypt approved certificates. | Coming soon. Required before approval. |
| `JOTFORM_API_KEY` | Jotform API key. The Jotform Documents screen says it is read only from `wp-config.php`. | Coming soon. Shape checks are in [Drive troubleshooting](google-drive/troubleshooting.md). |
| `GHCA_ACD_TWILIO_ENCRYPTION_KEY` | Host secret, at least 32 characters, for Twilio credentials. | Coming soon. See [Messaging](messaging.md). |

Placeholder only:

```php
define( 'GHCA_ACD_PRIVATE_DIR', '/var/private/ghca-acd' );
define( 'GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE', '/var/private/ghca-acd/agency-drive-reader.json' );
define( 'GHCA_ACD_EVIDENCE_ENCRYPTION_KEY', 'REPLACE_WITH_AT_LEAST_32_RANDOM_CHARACTERS' );
define( 'JOTFORM_API_KEY', 'REPLACE_WITH_JOTFORM_API_KEY' );
```

Use a different private directory, JSON file, and secrets on each agency site.

## Verification

Coming soon.

## Troubleshooting

Coming soon for install failures that are not Drive. Private-directory and credential errors are in [Drive troubleshooting](google-drive/troubleshooting.md).

## Related pages

- [Guide home](README.md)
- [Overview and architecture](overview.md)
- [Server and wp-config](google-drive/server-setup.md)
- [Security model](security-model.md)

## Sources

`GHCA_ACD_External_Evidence_Store::base_directory()`, `GHCA_ACD_External_Evidence_Store` key loading, `GHCA_ACD_Google_Drive_Provider` credential loading, `GHCA_ACD_Jotform_Provider::api_key()`, and `GHCA_ACD_Messaging_Secret_Store`.
