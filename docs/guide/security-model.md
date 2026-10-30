# Security model

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will be the plugin-wide security model: capabilities, signed ownership claims, encrypted evidence, packet jobs, and messaging secrets. The Drive slice is written now.

## Prerequisites

Coming soon.

## Steps

Coming soon. Until that page exists, use [Drive security](google-drive/security.md) for:

- where the service-account JSON may live
- why each agency gets its own service account
- why the share is Viewer on one folder
- how to rotate a key without pasting it into chat or git

Approved certificate bytes use `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` and AES-256-GCM under `GHCA_ACD_PRIVATE_DIR`. That store is separate from the Drive credential file, even though both must sit in the private directory.

## Verification

Coming soon.

## Troubleshooting

Coming soon. Secret and private-directory failures that surface while testing Drive are in [Drive troubleshooting](google-drive/troubleshooting.md).

## Related pages

- [Guide home](README.md)
- [Drive security](google-drive/security.md)
- [Installation and wp-config constants](installation.md)
- [Roles and permissions](roles-and-permissions.md)

## Sources

`includes/jotform/class-external-evidence-store.php` and `includes/jotform/class-google-drive-provider.php`.
