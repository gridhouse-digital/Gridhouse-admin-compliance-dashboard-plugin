# Testing with synthetic data

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Use a disposable WordPress user and a file you created. Do not use a real employee or a real certificate for the first pass.

## Purpose

Prove that one `Evidence-*` folder can be indexed, opened, preflighted, and approved on a single agency site. Then revoke it so it does not sit in the compliance record.

## Prerequisites

- [Plugin configuration](plugin-configuration.md) saved for this form, indexing still **off**, **Test Connection** already `connection ready`.
- `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` set (at least 32 characters). Approval refuses to run without it.
- An active Training Catalog lesson whose title matches the test submission, or whose training code matches. Title matching ignores case and punctuation. More than one matching lesson quarantines the row as ambiguous.
- A logged-in test employee, so the ownership helper can attach a signed claim. A Drive file with no Jotform submission is invisible. The plugin does not scan Drive for new files.
- HTTPS admin access.

## Steps

1. Create a small PDF, JPEG, or PNG that is obviously fake. Keep the name simple, such as `synthetic-certificate.pdf`. It must be under 25 MiB, and under 8 MiB if you want approval to store the PDF as-is.
2. Submit the real form once, as the test employee, from the site page that embeds it. That is what issues the ownership claim. Note the submission id if **Evidence reference QID** is blank. The folder must be `Evidence-` plus that id.
3. Wait until the folder filler has created that folder and uploaded only `synthetic-certificate.pdf`. Open it in Drive and confirm there is no second file.
4. On **Jotform Documents**, click **Test Connection** again. You still expect `connection ready`. This does not see the new folder.
5. Check **Enable Jotform document indexing and external training**, click **Save Jotform Configuration**, then **Sync Now**.
6. Open **Gridhouse Compliance → External Training Review**. Find the pending row.
   - The warning should read **Ready for review.**
   - **Expected evidence** should read `Evidence-` plus the reference, followed by the file name, **Preview**, and **Download**.
7. Click **Preview**. The file should open. This is the first request that downloads bytes from Drive.
8. Click **PDF preflight**. The banner `pdf preflight passed` means the file can be normalized. Any failure on this button, including a missing Drive folder, is rewritten to `replacement required reexport pdf` and is styled as a success banner. Read [Drive troubleshooting](troubleshooting.md) before you re-export a file that may simply be missing.
9. On that disposable row only: choose the catalog lesson, enter the test employee id, confirm the date, check **Employee confirmed**, and click **Approve**. Optional: leave **Also applies to orientation for this employee** unchecked for this test.
10. Confirm the banner `training approved` and that the row is under **Active approved evidence**.
11. Revoke it. Enter a reason such as `Synthetic Drive test` and click **Revoke**. The banner should be `training revoked`.

If the warning is an ownership or catalog message, fix that before you treat the result as a Drive failure. Drive path problems usually appear on preview and approval, not as the indexing warning. Indexing warnings that are Drive-specific are only the reference and the certificate file names.

Turn indexing back off if this site should not keep syncing while you fix the filler.

## Verification

| Check | Pass |
| --- | --- |
| Test Connection | Banner `connection ready` |
| Sync Now | Banner starts with `sync new` and the pending row exists |
| Review warning | **Ready for review.** |
| Expected evidence | `Evidence-<reference>` and the synthetic file name |
| Preview | File opens, no **Document unavailable** page |
| PDF preflight | Banner `pdf preflight passed` |
| Approve | Banner `training approved` |
| Revoke | Banner `training revoked` |

**Sync Now** within 60 seconds of another sync shows `sync rate limited` and does not run.

## Troubleshooting

- Row missing after sync: indexing was still off, the form row was dropped on save, or the submission id was not returned by Jotform. Drive being empty does not skip the row.
- **Configured evidence reference is invalid or unavailable. Re-sync before review.** The indexed reference was blank. The QID answer failed the pattern, or it was not a single value.
- **Evidence reference metadata is inconsistent. Re-sync before review.** The indexed files do not all carry the same reference.
- Preview says **Evidence source unavailable** and shows a **Request ID**. That page does not include the Drive code. There is no event viewer in wp-admin. Click **Approve** on this disposable row to print the code, or use [Drive troubleshooting](troubleshooting.md).
- Do not approve a second copy to “see if it works” against a real employee.

## Related pages

- [Guide home](../README.md)
- [How Drive evidence works](how-it-works.md)
- [Filling the folders](folder-filling.md)
- [Plugin configuration](plugin-configuration.md)
- [Drive troubleshooting](troubleshooting.md)
- [Jotform documents and external training review](../jotform-and-external-training.md)

## Sources

- `GHCA_ACD_Jotform_UI` review markup, `handle_test_connection`, `handle_sync_now`, and `handle_training_action`
- `GHCA_ACD_Jotform_Sync::index_submission()`
- `GHCA_ACD_Jotform_Repository::catalog_match_result()`
