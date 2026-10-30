# Audit packets and exports

**Status: coming soon.**

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**.

## Purpose

This page will explain annual, orientation, custom-period, and OLTL packets, plus CSV export. It is not written yet.

## Prerequisites

Coming soon.

## Steps

Coming soon. One boundary is already settled and matters for Drive:

- Preview and download of an indexed Jotform certificate read the current source (Drive, when that form’s file source is Google Drive).
- Approval copies the certificate into the private directory as an encrypted PDF.
- Packet generation uses that encrypted copy, not a fresh Drive download.

If a Drive file disappears after approval, opening the indexed document can fail while a packet can still attach the stored copy. The packet procedure itself is coming soon.

## Verification

Coming soon.

## Troubleshooting

Coming soon. A Drive failure during **PDF preflight** or **Approve** is mapped in [Drive troubleshooting](google-drive/troubleshooting.md).

## Related pages

- [Guide home](README.md)
- [How Drive evidence works](google-drive/how-it-works.md)
- [Jotform documents and external training review](jotform-and-external-training.md)
- [Security model](security-model.md)

## Sources

`GHCA_ACD_Jotform_UI::stream_document()`, `GHCA_ACD_External_Evidence_Store::capture()`, and `GHCA_ACD_External_Evidence_Store::materialize()`.
