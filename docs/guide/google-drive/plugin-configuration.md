# Plugin configuration

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Field labels below are copied from the Jotform Documents screen.

## Purpose

Point one allowlisted Jotform form at the agency’s **External Training Evidence** folder, without turning indexing on until a synthetic test has passed.

## Prerequisites

- `JOTFORM_API_KEY` defined in `wp-config.php`. The screen states that this key is never saved in the form.
- `GHCA_ACD_PRIVATE_DIR` and `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` from [Server and wp-config](server-setup.md).
- The root folder id from [Folder structure](folder-structure.md).
- An administrator on HTTPS. Save, Test Connection, and Sync Now call `is_ssl()` and otherwise stop with **HTTPS and administrator access are required.**

## Steps

Open **Gridhouse Compliance → Jotform Documents**.

Leave **Enable Jotform document indexing and external training** unchecked until [Testing with synthetic data](testing.md) says to turn it on. While it is off, the hourly sync is not scheduled. **Test Connection** still runs.

Set **Region** to **Standard** or **EU**, matching where the form lives. Standard uses `https://api.jotform.com`. EU uses `https://eu-api.jotform.com`. The status table’s **API region** row shows the saved value.

In **Allowed forms and question mappings**, use the blank row at the bottom. The column headings are exactly:

| Column | What to enter |
| --- | --- |
| Label | A name operators will recognize. Not sent to Drive. |
| Form ID | Jotform’s numeric form id, 6 to 32 digits. |
| user_id QID | Question id of the hidden WordPress user id. Required. |
| Ownership claim QID | Question id of the signed ownership claim. You can save a row with this empty, but every submission is then quarantined. |
| Course title QID | Required. |
| Completion date QID | Required. |
| Certificate QID | The upload field. Required. Drive uses these file names; it does not download them from Jotform. |
| Training code QID | Optional. A matching catalog code maps the lesson. |
| Email QID | Optional. |
| Name QID | Optional. |
| File source | **Google Drive**. The other choice is **Jotform**, which downloads certificate bytes from Jotform instead. |
| Evidence reference QID | Optional. Digits only, up to 10. Leave blank to use the Jotform submission id as the reference. If you fill it in, that answer is the reference and an invalid answer does not fall back. |
| Drive folder ID | The **External Training Evidence** id. Pattern `^[A-Za-z0-9_-]{10,200}$`. Not a URL, not an `Evidence-*` id. |

Question ids other than the form id must be empty or 1 to 10 digits. The plugin does not look up question titles for you.

Click **Save Jotform Configuration**.

Then click **Test Connection**. It is a link styled as a button. It does two things, in order:

1. Calls Jotform `/user` with the wp-config API key.
2. For every saved row whose **File source** is **Google Drive**, calls `diagnose_root`. That loads the JSON key, signs in, and checks that the folder id is a non-trashed folder of MIME type `application/vnd.google-apps.folder` with the same id Drive returns.

Success banner: `connection ready`.

**Test Connection** does not list `Evidence-*` folders, does not compare file names, and does not download a certificate. A passing test does not prove retrieval.

**Sync Now** and **Re-run Historical Backfill** are on the same screen. Leave them until the synthetic test. **Sync Now** is limited to once per 60 seconds. Backfill reset is limited to once per 5 minutes. Enabling indexing schedules the hourly hook `ghca_acd_jotform_hourly_sync`. Disabling it removes that schedule. Sync pauses when Jotform reports 100 or fewer calls left (`QUOTA_FLOOR`).

Save does not diagnose Drive. `diagnose_root` runs only from **Test Connection**.

A row is silently omitted from the save, and the banner still says `settings saved`, when any of these are true:

- **Form ID** is not 6 to 32 digits.
- **File source** is **Google Drive** and **Drive folder ID** fails the pattern above.
- **user_id QID**, **Course title QID**, **Completion date QID**, or **Certificate QID** is empty.

After a save, look at the table. If the row vanished, it was dropped. Fix it and save again. Switching **File source** back to **Jotform** clears the stored folder id on purpose.

There is no separate “Drive settings” page and no field for the JSON contents or the service-account email.

## Verification

- The row is still in the table after save, **File source** shows **Google Drive**, and **Drive folder ID** is the evidence-root id.
- Indexing is still unchecked.
- **Test Connection** shows `connection ready`.
- The status table may show **Last sync** as **Never**. That is expected before the first sync.

## Troubleshooting

A dropped row plus `settings saved` means the folder id or a required question id was rejected. No red banner is generated for that.

`error ghca drive root unavailable` on **Test Connection** means the id was syntactically valid but Drive did not return that folder to the service account. Wrong id, missing Viewer share, trash, or a Shared Drive all land on this code.

Other banners are listed in [Drive troubleshooting](troubleshooting.md).

## Related pages

- [Guide home](../README.md)
- [How Drive evidence works](how-it-works.md)
- [Server and wp-config](server-setup.md)
- [Filling the folders](folder-filling.md)
- [Testing with synthetic data](testing.md)
- [Jotform documents and external training review](../jotform-and-external-training.md)

## Sources

- `GHCA_ACD_Jotform_UI::render_settings()`, `handle_save_settings()`, and `handle_test_connection()`
- `GHCA_ACD_Google_Drive_Provider::diagnose_root()` and `valid_id()`
- `GHCA_ACD_Jotform_Provider::api_base()` and `QUOTA_FLOOR`
