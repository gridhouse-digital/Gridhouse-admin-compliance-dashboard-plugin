# Filling the folders

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. The plugin does not talk to Jotform’s Drive integration, Zapier, or Make. It only reads the folder you configured. Product screens named below are **external UI, may change**.

## Purpose

Get each submission’s certificate files into `Evidence-<reference>` before a reviewer previews or approves. Gridhouse uses the nested four-agency tree, so the working tool is Zapier or Make. Jotform’s native Google Drive integration is the simpler option when a single flat folder is enough and its folder names match the contract.

## Prerequisites

- [Folder structure](folder-structure.md) created in My Drive.
- [Plugin configuration](plugin-configuration.md) saved, indexing still off.
- The same reference the plugin will compute. If **Evidence reference QID** is empty, that is the numeric Jotform submission id, and the folder must be named `Evidence-` plus that id. If the QID is set, the folder must use that answer, not the submission id.

## Steps

### What both options must produce

The plugin’s contract, independent of which product creates the files:

- Root in the plugin is the agency **External Training Evidence** folder.
- One direct child named exactly `Evidence-<reference>`.
- The reference matches `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$` and does not already include the `Evidence-` prefix.
- Exactly one folder with that name.
- Only the certificate files from the mapped upload field, as direct children. No submission PDF, no extra image, no nested folder.
- PDF, JPEG, or PNG, each 1 byte through 25 MiB, original file name kept, including the extension.
- Exactly one parent on the folder and on each file. No shortcuts.
- My Drive, general access Restricted, upload finished before preview, preflight, or approval.

Sync does not wait for Drive. Indexing can succeed while the folder is still empty. Preview and approval then fail. Finishing the upload before the first sync is the safer habit, but a late upload can still be approved without another sync if the Jotform metadata did not change.

### Native Jotform integration

Use this when one flat folder is acceptable and you can make Jotform name the subfolder `Evidence-<reference>`.

**External UI, may change.** [How to integrate your form with Google Drive](https://www.jotform.com/help/192-how-to-integrate-your-form-with-google-drive/). The clicks in the author’s draft were **Form → Settings → Integrations → Google Drive**, connect an agency-controlled Google account, name the folder, turn on subfolders for submissions and uploaded files, leave submission PDFs off, and set the subfolder name to `Evidence-` plus the same reference the plugin uses. Confirm those labels in Jotform’s current UI before you rely on them.

If the native integration cannot name the folder exactly `Evidence-<reference>`, or it adds a submission PDF, or it nests another directory, do not use it. The plugin will reject the result. It will not rename anything.

The native integration also does not build the **Gridhouse Managed Evidence / agency / External Training Evidence** parents. Point it at a folder that is already the plugin’s root, or use the automation below.

### Zapier or Make (the layout Gridhouse uses)

One scenario per agency. Do not use one Zap for all four sites.

**External UI, may change.** The scenario has to do the following. Zapier and Make will name the steps differently:

1. Trigger on a new submission for that agency’s form.
2. Find or create the three static parents: **Gridhouse Managed Evidence**, the agency folder, and **External Training Evidence**. Create them once and reuse the ids. Do not create a new parent per submission.
3. Build the reference with the same rule as the plugin. Prefer the submission id when **Evidence reference QID** is blank. That id is immutable and already matches the pattern.
4. Create `Evidence-<reference>` inside **External Training Evidence** only when that exact name is missing. If a folder with that name already exists, do not create a second one. Two matches make the plugin report the folder as ambiguous.
5. Upload only the certificate file or files from the mapped upload field, directly into that new folder, with the original file name. Do not upload Jotform’s submission PDF.

Run the scenario to completion before anyone clicks **Preview** or **Approve**.

### Before you enable live sync

Try the failures on a synthetic submission, not a real employee:

- No `Evidence-*` folder yet.
- Two folders with the same `Evidence-*` name.
- An extra PDF sitting next to the certificate.
- A file that arrives after you already clicked **Approve**.

Expect those to fail closed. The codes are in [Drive troubleshooting](troubleshooting.md). The happy path is [Testing with synthetic data](testing.md).

## Verification

- The child folder name is exactly `Evidence-` plus the reference, and it is a direct child of the folder id saved in WordPress.
- That folder’s file count equals the upload field, and the names match.
- The agency service account can see the folder as Viewer.
- A synthetic **Preview** opens the file. **Test Connection** staying green is not this check.

## Troubleshooting

An extra submission PDF is the usual native-integration failure. It shows up as `error ghca drive file count invalid` on approval, or as **Evidence source unavailable** on preview. Remove the extra file. Do not add it to the Jotform upload field to “make the counts match.”

## Related pages

- [Guide home](../README.md)
- [How Drive evidence works](how-it-works.md)
- [Folder structure](folder-structure.md)
- [Testing with synthetic data](testing.md)
- [Drive troubleshooting](troubleshooting.md)

## Sources

- Folder and file rules in `GHCA_ACD_Google_Drive_Provider::validate_evidence_listing()` and `evidence_reference()`
- [Jotform: integrate with Google Drive](https://www.jotform.com/help/192-how-to-integrate-your-form-with-google-drive/) (external UI, may change)
- Zapier and Make have no plugin code. Their step names will drift.
