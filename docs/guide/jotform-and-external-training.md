# Jotform documents and external training review

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will cover Jotform indexing, the ownership claim, the training catalog, and the review decision. Drive folder setup is already written. Use the Drive pages for file source, folder ID, and evidence folders. Use this page later for everything else on those screens.

## Prerequisites

Coming soon.

## Steps

Coming soon. Screens that exist today:

- **Gridhouse Compliance → Jotform Documents** configures forms, runs **Test Connection**, **Sync Now**, and **Re-run Historical Backfill**.
- **Gridhouse Compliance → Training Catalog** is titled **External Training Catalog** on the page. Lessons need a training code, title, provider, description, ODP category, and credit hours.
- **Gridhouse Compliance → External Training Review** is where a pending certificate becomes approved evidence. Approval stores an encrypted copy. Until then, the Drive file is only a source.

Drive field labels, the save rules, and what **Test Connection** actually checks are in [Plugin configuration](google-drive/plugin-configuration.md).

## Verification

Coming soon.

## Troubleshooting

Coming soon for catalog, ownership, and quota issues. If the failure mentions Drive, use [Drive troubleshooting](google-drive/troubleshooting.md) instead of guessing from the green or red banner.

## Related pages

- [Guide home](README.md)
- [How Drive evidence works](google-drive/how-it-works.md)
- [Plugin configuration](google-drive/plugin-configuration.md)
- [Testing with synthetic data](google-drive/testing.md)
- [Audit packets and exports](audit-packets.md)

## Sources

`includes/jotform/class-jotform-ui.php` menu and screen labels.
