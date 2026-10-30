# Drive troubleshooting

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Every code below is from `includes/jotform/class-google-drive-provider.php`, the Jotform Documents screen, or the approval path that runs immediately after a Drive download. Sentences that live only in the PHP source are marked that way. The banner does not print them.

## Purpose

Map each Drive failure to the text you actually see and to a fix that does not involve guessing from a green banner.

## Prerequisites

- Administrator on the agency site, HTTPS.
- The form row still present after save. A dropped row never reaches these codes.

## Steps

### What the screen prints

**Banners** on Jotform Documents and External Training Review replace `_` with a space. `error_ghca_drive_root_unavailable` is shown as `error ghca drive root unavailable`. The long `WP_Error` sentence is not shown.

A banner is red only when the notice starts with `error_` or contains `failed` or `blocked`. Anything else is green, including some failures.

| Action | What you see on failure |
| --- | --- |
| **Test Connection** | Red `error ` plus the code. Stops at the first Drive form that fails. Jotform is checked first. |
| **Save Jotform Configuration** | Always green `settings saved`, even when a Drive row was dropped. The check is the regex only. Save does not call Drive. |
| **Approve** | Red `error ` plus the code returned while copying the file. |
| **PDF preflight** | Any failure, Drive or PDF, becomes green `replacement required reexport pdf`. The real code is written on the `training_pdf_preflight` audit event and is not printed. Do not re-export until you know the folder was missing. |
| **Preview** or **Download** | An HTML page titled **Document unavailable**. Drive failures use the paragraph **Evidence source unavailable. Retry the request; contact an administrator if it persists.** HTTP 502. **Request ID** is a 32-character hex `request_id`. The Drive code is not on the page. |

**Review Warning column** uses a fixed sentence for some codes. Indexing only stores a Drive code for a bad reference or bad certificate file names. Folder, auth, and download codes show up later, on Test Connection, preview, or approval, not in that column.

| Indexed warning code | Warning column |
| --- | --- |
| `ghca_drive_metadata_invalid` | Drive certificate metadata is invalid. |
| `ghca_drive_metadata_ambiguous` | Drive certificate metadata is missing or ambiguous. |
| `ghca_drive_reference_invalid` | Submission needs review. There is no specific sentence for this code. The decision cell adds **Configured evidence reference is invalid or unavailable. Re-sync before review.** when the stored reference is blank. |
| *(empty)* | Ready for review. |
| Any other Drive code | Not stored at index time. |

Other decision-cell text that is not an error code:

- **Expected evidence:** plus `Evidence-<reference>`, then **Preview** and **Download**. This is the healthy pending row.
- **Evidence reference metadata is inconsistent. Re-sync before review.** The indexed files do not share one reference.
- **No indexed certificate is available.** The submission produced no file rows.

`warning_label()` defines these sentences, but indexing never stores the codes, so the Warning column does not show them:

| Code | Sentence in `warning_label()`, not shown for a Drive index |
| --- | --- |
| `ghca_drive_credentials_missing` | Drive credential constant is missing. |
| `ghca_drive_credentials_invalid` | Drive credential file is unreadable or invalid. |
| `ghca_drive_auth_failed` | Drive service-account authentication failed. |
| `ghca_drive_root_unavailable` | Configured Drive root is inaccessible or not shared. |
| `ghca_drive_evidence_folder_invalid` | Expected Drive evidence folder is missing or ambiguous. |
| `ghca_drive_file_missing` | Expected Drive evidence file is missing. |
| `ghca_drive_file_count_invalid` | Drive evidence has missing or extra files. |
| `ghca_drive_file_invalid` | Drive evidence filename, MIME, size, parent or ambiguity check failed. |
| `ghca_evidence_mime_invalid` | Certificate file type does not match its declared type. |
| `ghca_evidence_pdf_invalid` | PDF cannot be merged safely; re-export or Print to PDF. |

There is no admin screen for the evidence event log. Preview stores `error_code` on `{prefix}ghca_acd_evidence_events` (`event_type` `document_preview` or `document_download`, matched by `request_id`). Preflight stores `training_pdf_preflight` and does not show a request id. On a disposable test, **Approve** prints the same failure as a banner, which is the faster check.

### Test Connection before Drive is involved

These are red banners from the same button. Fix them before reading a Drive code.

| Banner | Cause | Fix |
| --- | --- | --- |
| `error ghca jotform key missing` | `JOTFORM_API_KEY` is not defined as a string. | Define it in `wp-config.php`. The screen never saves a key. |
| `error ghca jotform key invalid` | The key is shorter than 20 characters, longer than 191, or not only letters, digits, `_`, and `-`. | Replace the constant with the agency’s API key. Do not paste the key into a ticket. |
| `error ghca jotform auth failed` | Jotform returned 401 or 403 for `/user`. | Check the key and that **Region** is **Standard** or **EU** for that account. |
| `error ghca jotform quota exhausted` | Jotform returned 429. | Wait for the allowance to reset. The plugin also pauses sync at 100 calls left. |
| `error ghca jotform unavailable` | The HTTP call to Jotform failed before a status code. | Retry. Confirm the server can reach `api.jotform.com` or `eu-api.jotform.com`. |
| `error ghca jotform api failed` | Any other non-success response. | Retry, then check the key, region, and allowance. The banner does not include Jotform’s body. |

`HTTPS and administrator access are required.` is a `wp_die` page, not a banner. WordPress `is_ssl()` was false or the user is not an administrator. Fix HTTPS detection before clicking the button again.

### Drive codes you can see

The **Shown as** column is the banner text. Preview still hides the code behind **Evidence source unavailable**.

| Code | Shown as | When | Cause | Fix |
| --- | --- | --- | --- | --- |
| `ghca_drive_credentials_missing` | `error ghca drive credentials missing` | Test Connection, preview, preflight, approval | `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` is missing, not a string, or blank. | Define the path constant. See [Server and wp-config](server-setup.md). |
| `ghca_drive_credentials_invalid` | `error ghca drive credentials invalid` | Same | One code for two source sentences: **Google Drive credentials are unavailable** (missing file, not readable, outside `GHCA_ACD_PRIVATE_DIR`, or larger than 65536 bytes) and **Google Drive credentials are invalid** (not `type` `service_account`, bad email, or private key that does not start with `-----BEGIN PRIVATE KEY-----`). | Replace the file inside the private directory with the JSON key. Do not widen permissions to the web root. |
| `ghca_drive_crypto_missing` | `error ghca drive crypto missing` | Same | `openssl_sign` is missing. Source sentence: **OpenSSL is required for Drive authentication.** | Install the PHP OpenSSL extension and retry. |
| `ghca_drive_jwt_failed` | `error ghca drive jwt failed` | Same | The private key would not sign the JWT. Source sentence: **Drive authentication could not be prepared.** | The key is corrupt or not an RSA key the server can load. Create a new JSON key and replace the file. |
| `ghca_drive_auth_failed` | `error ghca drive auth failed` | Same | Token URL was not HTTP 200, or the body had no access token matching `^[A-Za-z0-9._-]{20,4096}$`. Source sentence: **Drive authentication failed.** | Delete extra keys if the JSON was edited. Enable the Drive API on that project. Clock skew on the server also breaks the five-minute JWT. |
| `ghca_drive_root_invalid` | `error ghca drive root invalid` | Test Connection or a later read | Folder id fails `^[A-Za-z0-9_-]{10,200}$`. Source sentence: **The configured Drive evidence folder is invalid.** | Save normally rejects this and drops the row. If you see the code, the stored option was edited. Paste the id only, 10 to 200 characters, letters, digits, `_`, `-`. |
| `ghca_drive_root_unavailable` | `error ghca drive root unavailable` | Test Connection (`diagnose_root`) or a later read | Not a folder, trashed, returned id does not match, or the files.get call failed. Diagnose sentence: **The configured Drive evidence folder is unavailable or not shared with the service account.** The later read uses a shorter sentence without the share clause. The code is the same. | Confirm the id is **External Training Evidence** in My Drive, the folder is not trashed, and the service account is Viewer. Shared Drives fail here because the client never sends `supportsAllDrives`. |
| `ghca_drive_reference_invalid` | Warning column: **Submission needs review.** Decision cell: **Configured evidence reference is invalid or unavailable. Re-sync before review.** Approval banner: `error ghca drive reference invalid` | Index, then approval | The evidence-reference answer is not a scalar, is empty, has surrounding whitespace, or fails `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`. If the QID is set, the submission id is not used as a fallback. Source sentence: **The Drive evidence reference is invalid.** | Clear **Evidence reference QID** to use the numeric submission id, or change the answer to letters, digits, `_`, and `-`, at most 128 characters, no `Evidence-` prefix. Re-sync. |
| `ghca_drive_metadata_invalid` | Warning: **Drive certificate metadata is invalid.** | Index | A certificate name fails the file-name rule or collides with another name. The rule: 1 to 255 characters, no slash or control character, not `.` or `..`, and an ending of `.pdf`, `.jpg`, `.jpeg`, or `.png`. Duplicates and names that match after `sanitize_file_name` plus lowercasing also fail. | Rename the Jotform upload to a unique `name.pdf`, `name.jpg`, `name.jpeg`, or `name.png`. Re-sync. |
| `ghca_drive_metadata_ambiguous` | Warning: **Drive certificate metadata is missing or ambiguous.** | Index | The upload field produced no file name. | Confirm **Certificate QID** and that the submission actually has a file. Re-sync. |
| `ghca_drive_evidence_folder_invalid` | `error ghca drive evidence folder invalid` on approval. Preview hides it. | Preview, preflight, approval | No child named exactly `Evidence-<reference>`, more than one, the name does not match byte for byte, the folder’s only parent is not the configured root, the folder id is not a valid id, or the folder list call failed. Usual source sentence: **The Drive evidence folder is missing or ambiguous.** A bad folder id uses **The Drive evidence folder is invalid.** | Create one My Drive folder with that exact name directly under the configured root. Delete duplicates and shortcuts. Do not put `Evidence-` in the reference and also in the folder prefix. |
| `ghca_drive_file_count_invalid` | `error ghca drive file count invalid` | Preview, preflight, approval | The number of non-trashed children is not the number of certificate names. This same code is used when the file list call fails or Drive returns another page (`nextPageToken`, including more than 1000 children). Source sentence: **Drive evidence does not exactly match the submitted certificates.** | Leave only that submission’s certificate files in the folder. Remove the Jotform submission PDF and any extra image. A listing outage looks the same; retry once before deleting files. |
| `ghca_drive_file_missing` | `error ghca drive file missing` | Preview, preflight, approval | A submitted name has no Drive file with the same canonical key. Source sentence: **A submitted certificate is missing from Drive evidence.** | Upload the original file name, including the extension. `First Cert.pdf` and `first-cert.pdf` collapse to one key. An extensionless Drive file is accepted only when its MIME type matches exactly one expected name. |
| `ghca_drive_file_invalid` | `error ghca drive file invalid` | Preview, preflight, approval | Bad file id, name/MIME clash, extensionless name that matches zero or two expectations, size not a positive integer, size over 25 MiB (`26214400` bytes), not exactly one parent, or two files with the same canonical key. A zero-byte file fails here. Source sentences: **A Drive evidence file is invalid** or, if the id is bad at download time, **The Drive evidence file is invalid.** | One real PDF, JPEG, or PNG per submitted name, under 25 MiB, not a Google Doc, not a shortcut, not in a second folder. |
| `ghca_drive_file_unavailable` | `error ghca drive file unavailable` | Download after the listing succeeded | The media HTTP call failed before a status. Source sentence: **The Drive evidence file could not be retrieved.** | Retry. Confirm the server can reach `https://www.googleapis.com`. |
| `ghca_drive_redirect_denied` | `error ghca drive redirect denied` | Download | Drive returned 301, 302, 303, 307, or 308. Redirects are not followed. Same source sentence as the row above. | Do not point the folder at a file that only exists as a redirect or shortcut. Upload the bytes into the evidence folder. |
| `ghca_drive_file_failed` | `error ghca drive file failed` | Download | Any other non-200, including 404 after a file was deleted between list and download. Same source sentence. | Put the file back, or remove the matching Jotform name and re-sync if the certificate was withdrawn. |
| `ghca_drive_file_type` | `error ghca drive file type` | Download | Empty body, body over 25 MiB, magic bytes are not PDF (`%PDF-`), JPEG (`FF D8 FF`), or PNG, `Content-Type` disagrees with those bytes (other than blank or `application/octet-stream`), or the bytes disagree with the metadata MIME type. Source sentence: **The Drive evidence file failed validation.** | Export a real PDF, JPEG, or PNG. Renaming a `.docx` to `.pdf` fails here. |

### Codes that are never shown

`ghca_drive_api_failed`, `ghca_drive_api_invalid`, and `ghca_drive_list_invalid` are returned inside the client and then replaced.

- A root `files.get` failure becomes `ghca_drive_root_unavailable`.
- A folder-list failure becomes `ghca_drive_evidence_folder_invalid`.
- A file-list failure becomes `ghca_drive_file_count_invalid`.

If you are reading an audit event, expect the replaced code, not these three.

### After Drive has already returned the file

Approval still normalizes and encrypts. **PDF preflight** collapses every one of these into green `replacement required reexport pdf`. **Approve** shows `error ` plus the code. The longer PDF sentences in the source (compressed cross-reference, password protection) are not printed. When the code is `ghca_evidence_pdf_invalid`, open the certificate, use Print to PDF or Save as PDF, upload that copy, and retry.

| Code | Approve banner | Cause | Fix |
| --- | --- | --- | --- |
| `ghca_evidence_key_missing` | `error ghca evidence key missing` | `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` missing or under 32 characters. | Set a stable secret in `wp-config.php`. Do not change it after certificates are stored. |
| `ghca_evidence_private_dir_missing` | `error ghca evidence private dir missing` | `GHCA_ACD_PRIVATE_DIR` is missing. | Define it. [Server and wp-config](server-setup.md). |
| `ghca_evidence_private_dir_unavailable` | `error ghca evidence private dir unavailable` | Path does not exist, is not a directory, or is not writable. | Create it and give the PHP user write access. The banner does not reveal the path. |
| `ghca_evidence_private_dir_unsafe` | `error ghca evidence private dir unsafe` | The directory resolves inside WordPress or uploads. | Move it outside both. |
| `ghca_evidence_acl_unverified` | `error ghca evidence acl unverified` | Windows, and `GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED` is not boolean `true`. | Apply an owner-only ACL, then set the constant. |
| `ghca_evidence_crypto_missing` | `error ghca evidence crypto missing` | `openssl_encrypt` or `openssl_decrypt` is missing. Different code from the Drive JWT code. | Install PHP OpenSSL. |
| `ghca_evidence_owner_changed` | `error ghca evidence owner changed` | The signed owner no longer matches the row being approved. | Re-sync and review the new row. |
| `ghca_evidence_source_changed` | `error ghca evidence source changed` | The submission changed after indexing. | **Sync Now**, then review the new revision. |
| `ghca_evidence_size_invalid` | `error ghca evidence size invalid` | Empty or over 25 MiB at normalize time. | Replace the file. |
| `ghca_evidence_mime_invalid` | `error ghca evidence mime invalid` | Magic bytes, extension, and declared type disagree. | Upload a real PDF, JPEG, or PNG. |
| `ghca_evidence_pages_invalid` | `error ghca evidence pages invalid` | PDF has 0 pages or more than 100. | Replace with a 1 to 100 page certificate. |
| `ghca_evidence_pdf_invalid` | `error ghca evidence pdf invalid` | Parser cannot read it, including compressed cross-reference streams and encrypted PDFs. | Print to PDF and upload the new file. |
| `ghca_evidence_normalized_size` | `error ghca evidence normalized size` | The PDF that would be stored is over 8 MiB (`8388608` bytes). | Compress or re-export. 25 MiB is the download cap. 8 MiB is the stored-PDF cap. |
| `ghca_evidence_image_invalid` | `error ghca evidence image invalid` | Width or height over 8000, or more than 20 million pixels. | Export a smaller JPEG or PNG. |
| `ghca_evidence_image_failed` | `error ghca evidence image failed` | The image could not be written to a temp file for conversion. | Retry. Check PHP temp directory permissions. |
| `ghca_evidence_validation_failed` | `error ghca evidence validation failed` | The PDF could not be written to a temp file for parsing. | Retry. Check the temp directory. |
| `ghca_evidence_directory_failed` | `error ghca evidence directory failed` | The evidence subdirectory could not be created. | Fix write access on `GHCA_ACD_PRIVATE_DIR`. |
| `ghca_evidence_encrypt_failed` | `error ghca evidence encrypt failed` | AES-256-GCM encryption failed. | Confirm OpenSSL and the evidence key, then retry. |
| `ghca_evidence_write_failed` | `error ghca evidence write failed` | The encrypted file could not be stored. | Fix disk space and directory permissions. Nothing from that attempt is left approved. |

Preview of an **approved** packet file is a different path (the encrypted copy). A failure there says **Stored certificate is missing, changed or unreadable. Contact an administrator.** That page is not a Drive problem.

### Other banners on the same screens

| Banner | Color | Meaning |
| --- | --- | --- |
| `connection ready` | Green | Jotform `/user` worked and every saved Drive root passed `diagnose_root`. Subfolders were not read. |
| `settings saved` | Green | Options were written. A rejected form row is simply absent. |
| `sync rate limited` | Green | **Sync Now** was clicked again within 60 seconds. Nothing ran. |
| `sync new N updated N unchanged N quarantined N failed N` | Green | Sync finished. Quarantined can be an ownership or catalog issue, not Drive. |
| `pdf preflight passed` | Green | This file normalized. |
| `replacement required reexport pdf` | Green | Preflight failed. The color is not a success. |
| `training approved` | Green | Encrypted copy stored. |
| `training revoked` | Green | Revocation saved. |
| `error ghca jotform disabled` | Red | **Sync Now** while indexing is off. |
| `error ghca jotform quota paused` | Red | Stored allowance is 100 or fewer calls for the current UTC day. |
| `error ghca jotform sync busy` | Red | Another sync holds the database lock. |

### Source sentences the banner does not print

These are the exact `WP_Error` sentences in `class-google-drive-provider.php`. The screen prints the code, not the sentence. Where one code has two sentences, both are real. The second one depends on which check failed.

| Code | Exact sentence in code |
| --- | --- |
| `ghca_drive_metadata_invalid` | The certificate metadata is invalid for Drive evidence. |
| `ghca_drive_metadata_ambiguous` | The certificate metadata is missing or ambiguous for Drive evidence. |
| `ghca_drive_root_invalid` | The configured Drive evidence folder is invalid. |
| `ghca_drive_root_unavailable` | The configured Drive evidence folder is unavailable or not shared with the service account. |
| `ghca_drive_root_unavailable` | The configured Drive evidence folder is unavailable. |
| `ghca_drive_evidence_folder_invalid` | The Drive evidence folder is missing or ambiguous. |
| `ghca_drive_evidence_folder_invalid` | The Drive evidence folder is invalid. |
| `ghca_drive_file_count_invalid` | Drive evidence does not exactly match the submitted certificates. |
| `ghca_drive_file_invalid` | A Drive evidence file is invalid. |
| `ghca_drive_file_invalid` | The Drive evidence file is invalid. |
| `ghca_drive_file_missing` | A submitted certificate is missing from Drive evidence. |
| `ghca_drive_file_unavailable` | The Drive evidence file could not be retrieved. |
| `ghca_drive_redirect_denied` | The Drive evidence file could not be retrieved. |
| `ghca_drive_file_failed` | The Drive evidence file could not be retrieved. |
| `ghca_drive_file_type` | The Drive evidence file failed validation. |
| `ghca_drive_reference_invalid` | The Drive evidence reference is invalid. |
| `ghca_drive_crypto_missing` | OpenSSL is required for Drive authentication. |
| `ghca_drive_jwt_failed` | Drive authentication could not be prepared. |
| `ghca_drive_auth_failed` | Drive authentication failed. |
| `ghca_drive_credentials_missing` | Google Drive credentials are not configured. |
| `ghca_drive_credentials_invalid` | Google Drive credentials are unavailable. |
| `ghca_drive_credentials_invalid` | Google Drive credentials are invalid. |
| `ghca_drive_api_failed` | Drive evidence could not be resolved. |
| `ghca_drive_api_invalid` | Drive evidence could not be resolved. |
| `ghca_drive_list_invalid` | Drive evidence could not be listed safely. |

The last three are replaced before they reach a banner. See [Codes that are never shown](#codes-that-are-never-shown).

## Verification

After a fix, repeat the smallest check that failed:

1. Dropped row or bad id: save, confirm the row remains, then **Test Connection** until `connection ready`.
2. Folder or file contents: **Preview** on the pending row, then **PDF preflight** until `pdf preflight passed`.
3. Storage: **Approve** on the disposable test only, then **Revoke**.

## Troubleshooting

If preview shows a request id and the approval banner shows a Drive code, trust the approval code. They are the same failure, and only approval prints it. The evidence event table is not shown in wp-admin.

If preflight is green and says `replacement required reexport pdf` while Test Connection is also green, the root is fine and this submission’s folder or file is not. Open the `Evidence-*` folder before you re-export the PDF.

## Related pages

- [Guide home](../README.md)
- [How Drive evidence works](how-it-works.md)
- [Plugin configuration](plugin-configuration.md)
- [Filling the folders](folder-filling.md)
- [Testing with synthetic data](testing.md)
- [Server and wp-config](server-setup.md)
- [Troubleshooting](../troubleshooting.md)

## Sources

- `includes/jotform/class-google-drive-provider.php` (every `ghca_drive_*` code)
- `includes/jotform/class-jotform-ui.php` (`notice()`, `warning_label()`, `handle_test_connection()`, `handle_training_action()`, `document_error_page()`)
- `includes/jotform/class-external-evidence-store.php` (normalize and private-directory codes)
- `includes/jotform/class-jotform-provider.php` (`MAX_DOCUMENT_BYTES`, Test Connection `/user` errors)
- `GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES` is `26214400`. `GHCA_ACD_External_Evidence_Store::MAX_NORMALIZED_BYTES` is `8388608`.
