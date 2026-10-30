# Multi-agency folder structure

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Checked against the plugin code. Google Drive’s sharing screens are **external UI, may change**.

## Purpose

Gridhouse keeps one private My Drive tree for all four agencies, and each agency site is pointed at only its own evidence folder. The plugin does not know this tree. It stores one root folder id per form and looks for `Evidence-<reference>` as a direct child of that id.

## Prerequisites

- A Google account Gridhouse controls. The tree lives in that account’s My Drive, not in a Shared Drive.
- One service account per agency, documented in [Google Cloud service account](google-cloud-setup.md).
- The decision to build the parent folders with Zapier or Make. Jotform’s native integration is a flatter alternative and is compared in [Filling the folders](folder-filling.md).

## Steps

Create this tree in My Drive. Names below the top folder can match the agency, but the plugin never reads those names. It reads ids and the `Evidence-*` child name.

```text
Gridhouse Managed Evidence
└── Akansoh Health Services
    └── External Training Evidence          ← this folder's id goes on the Akansoh site
        ├── Evidence-ABC123
        │   └── certificate.pdf
        └── Evidence-ABC124
            └── training-certificate.png
```

Repeat the agency and **External Training Evidence** pair for:

| Agency | WordPress site | Folder id is entered on |
| --- | --- | --- |
| Akansoh Health Services | `compliance.akansohealth.com` | That site’s Jotform Documents screen |
| Isoke Developmental Services | `compliance.isokedevelops.com` | That site’s Jotform Documents screen |
| Prolific Care Group | `onboard.prolificcaregroup.com` | That site’s Jotform Documents screen |
| Lafia Healthcare | `onboard-lafiahealthcare.cloud` | That site’s Jotform Documents screen |

Rules the plugin enforces on whatever folder id you paste:

1. Paste the id of **External Training Evidence**, the folder that directly contains `Evidence-*`. Pasting **Gridhouse Managed Evidence**, the agency folder, or an `Evidence-*` folder fails the lookup.
2. The id is the characters after `/folders/` in the browser URL. It must match `^[A-Za-z0-9_-]{10,200}$`. Do not paste the whole URL.
3. `Evidence-<reference>` is a direct child. Files sit directly inside it, not in another nested folder.
4. That child has exactly one parent, and each file has exactly one parent. A shortcut or a file that also lives in another folder is rejected.
5. The child contains only that submission’s certificate files. Same count as the Jotform upload field. PDF, JPEG, or PNG, each at most 25 MiB.
6. General access on the tree stays **Restricted**. Share only that agency’s **External Training Evidence** folder with that agency’s service account, as **Viewer**. Do not share **Gridhouse Managed Evidence** with an agency service account. The API scope can read every file shared with the account, and the plugin will not stop a mis-share. Details are in [Drive security](security.md).

`Evidence-ABC123` above is a shape example. `ABC123` has to be the real reference for that submission. It is not a folder id.

Shared Drives are unsupported. Listing uses `spaces=drive` and never sends `supportsAllDrives`, `includeItemsFromAllDrives`, or a shared-drive corpus. A Shared Drive root fails **Test Connection** as an unavailable folder. Keep the tree in My Drive. ([About shared drives](https://developers.google.com/workspace/drive/api/guides/about-shareddrives), external UI and API behavior.)

The phrase “shared drive” on the manual-entry form means a certificate that arrived by email, paper, or a person’s shared folder. That path uploads from the employee drawer and does not use this Google Drive integration.

## Verification

- The folder you open in the browser is **External Training Evidence**, and its URL contains `/folders/` plus the id you will paste.
- That folder’s children are only `Evidence-*` folders, after a test upload.
- The agency service account is a Viewer on that folder and is not a member of the parent folders.
- **Test Connection** on that agency’s site returns `connection ready`.

## Troubleshooting

If Test Connection says the folder is unavailable, the usual causes are the wrong id, a Shared Drive, the folder in the trash, or the Viewer share missing. See [Drive troubleshooting](troubleshooting.md).

## Related pages

- [Guide home](../README.md)
- [How Drive evidence works](how-it-works.md)
- [Google Cloud service account](google-cloud-setup.md)
- [Filling the folders](folder-filling.md)
- [Drive security](security.md)

## Sources

- `GHCA_ACD_Google_Drive_Provider::files_for_submission()`, `validate_evidence_listing()`, `has_parent()`, and `list_files()` (`spaces=drive`, no shared-drive flags)
- [Share files from Google Drive](https://support.google.com/drive/answer/7166529) (external UI, may change)
- [About shared drives](https://developers.google.com/workspace/drive/api/guides/about-shareddrives)
