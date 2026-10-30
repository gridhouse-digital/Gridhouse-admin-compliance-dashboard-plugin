# How Drive evidence works

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Checked against the plugin code. Google Cloud, Jotform, Zapier, and Make screens are **external UI, may change**.

## Purpose

Google Drive is a read-only inbox for certificate files. Jotform remains the list of submissions. A certificate counts toward compliance only after a reviewer approves it. Approval copies the bytes into encrypted storage on the agency server.

The plugin never uploads, renames, or deletes Drive files. Something else has to put each submission in a folder named `Evidence-<reference>` before a reviewer opens or approves it.

## Prerequisites

- One WordPress site per agency. This plugin has no multi-agency switch.
- The form’s **File source** set to **Google Drive** on **Gridhouse Compliance → Jotform Documents**.
- A My Drive folder shared with that site’s service account as Viewer. Shared Drives are not supported. See [Folder structure](folder-structure.md).

## Steps

1. An employee submits the Jotform form. The submission holds the WordPress user id, the signed ownership claim, the course title, the completion date, the certificate file names, and the evidence reference when you configured one.
2. Jotform’s own Drive integration, or a Zapier/Make scenario, creates `Evidence-<reference>` directly inside the configured root and uploads only that submission’s certificate files. See [Filling the folders](folder-filling.md).
3. **Sync Now**, or the hourly job, reads Jotform and stores the metadata. Sync does not call Drive. The indexed file size is stored as `0`. Drive is not contacted, so a missing folder does not stop indexing.
4. **Test Connection** is the readiness check. It calls Jotform `/user`, then asks Drive whether each saved Google Drive form’s root id is a folder the service account can see. It does not open `Evidence-*` folders and does not download files.
5. **Preview**, **Download**, **PDF preflight**, and **Approve** are what actually read Drive. They look up one child folder, require the file count to match the submission, then download each file.
6. **Approve** checks the file, turns JPEG and PNG into a one-page PDF, and encrypts the PDF under `GHCA_ACD_PRIVATE_DIR`. That encrypted copy is what audit packets attach. Preview of the indexed document still re-reads Drive even after approval.

The lookup name is always the prefix `Evidence-` plus the reference. Do not put `Evidence-` inside the reference value. A reference of `ABC123` means a folder named `Evidence-ABC123`. A reference of `Evidence-ABC123` makes the plugin look for `Evidence-Evidence-ABC123`.

Reference rules, from `GHCA_ACD_Google_Drive_Provider::evidence_reference()`:

- If **Evidence reference QID** is filled in, that answer is the reference. An invalid answer does not fall back to the submission id.
- If **Evidence reference QID** is empty, the reference is the Jotform submission id.
- The value must match `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`. It must start with a letter or digit, may contain letters, digits, `_`, and `-`, and may be at most 128 characters. Spaces and other punctuation fail. Leading or trailing whitespace fails.

File rules, applied when someone opens or approves, not during sync:

- Accepted types are PDF, JPEG, and PNG. Extensions are `.pdf`, `.jpg`, `.jpeg`, and `.png`.
- Each file must be at least 1 byte and at most `26214400` bytes (25 MiB). That cap is `GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES`.
- The number of non-trashed children in the evidence folder must equal the number of certificate files on the submission. An extra PDF, including a Jotform submission PDF, fails the check.
- Each evidence folder and each file must have exactly one parent. A shortcut, a second parent, or a file that is not really a PDF, JPEG, or PNG is rejected.
- The Drive file name has to match the Jotform file name after WordPress `sanitize_file_name`, accent removal, and lowercasing. A missing extension is accepted only when the Drive MIME type matches exactly one expected name. Two names that collapse to the same key fail closed. The safe habit is to upload the original file name, including the extension.

After Drive accepts the bytes, approval still has to normalize them. A stored PDF must be at most 8 MiB (`8388608` bytes) and between 1 and 100 pages. A JPEG or PNG must be at most 8000 pixels on a side and at most 20 million pixels. Password-protected PDFs and PDFs with a compressed cross-reference stream are rejected because the packet builder cannot merge them.

Authentication is a service-account JWT, signed with the JSON key, for scope `https://www.googleapis.com/auth/drive.readonly`. The access token is kept in memory for that PHP request only. It is not written to the database. There is no user OAuth consent screen and no domain-wide delegation. The service account sees a file only when the file is shared with it.

## Verification

- **Test Connection** shows the banner `connection ready`. That proves the Jotform account and each configured root folder. It does not prove a submission folder.
- A synthetic submission shows a pending row whose **Expected evidence** line reads `Evidence-` plus the reference. The test procedure is [Testing with synthetic data](testing.md).
- Approving that row shows `training approved`, and the row moves to **Active approved evidence**.

## Troubleshooting

Banners print the error code with underscores replaced by spaces. They do not print the longer sentence from the code. Use [Drive troubleshooting](troubleshooting.md).

Saving the form does not run the Drive check. A bad folder id is dropped and the banner still says `settings saved`.

## Related pages

- [Guide home](../README.md)
- [Multi-agency folder structure](folder-structure.md)
- [Plugin configuration](plugin-configuration.md)
- [Filling the folders](folder-filling.md)
- [Drive security](security.md)

## Sources

- `includes/jotform/class-google-drive-provider.php`
- `includes/jotform/class-jotform-sync.php` (`index_submission` stores Drive names and does not call the Drive API)
- `includes/jotform/class-jotform-ui.php` (`handle_test_connection`, `stream_document`, `handle_training_action`)
- `includes/jotform/class-external-evidence-store.php` (`resolve_source_descriptors`, `capture`, `normalize_to_pdf`)
- [Drive API `files.get`](https://developers.google.com/drive/api/reference/rest/v3/files/get) for `supportsAllDrives`, which this plugin does not send
