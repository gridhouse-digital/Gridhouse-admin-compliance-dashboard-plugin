# Release and deploy

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will be the release and deploy checklist for the four agency sites. It is not written yet. Version history stays in the plugin [`README.md`](../../README.md). Do not treat this stub as a release note.

## Prerequisites

Coming soon.

## Steps

Coming soon. Deploy notes that already affect Drive:

- Each site has its own `wp-config.php`, private directory, service-account JSON, and Drive folder ID.
- Upgrading the plugin does not create Drive folders and does not migrate certificate bytes.
- `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` has to stay stable across deploys. Replacing it makes existing encrypted certificates unreadable.
- The Drive JSON path can stay the same across deploys. Rotating the key is a file replacement plus a Google Cloud key delete, described in [Drive security](google-drive/security.md).

## Verification

Coming soon.

## Troubleshooting

Coming soon.

## Related pages

- [Guide home](README.md)
- [Installation and wp-config constants](installation.md)
- [Server and wp-config](google-drive/server-setup.md)
- [Drive security](google-drive/security.md)

## Sources

Constant handling in `includes/jotform/class-external-evidence-store.php` and `includes/jotform/class-google-drive-provider.php`. Release history remains in the plugin README.
