# Drive security

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Checked against the plugin code. Google Cloud key screens are **external UI, may change**.

## Purpose

Keep a leaked agency site from reading another agency’s certificates, and keep the service-account key out of git, chat, and the Media Library.

## Prerequisites

- The folder tree in [Folder structure](folder-structure.md).
- A separate Google Cloud service account per agency, from [Google Cloud service account](google-cloud-setup.md).

## Steps

### Secrets

The JSON key is a private key. The plugin will not accept it in a form field. `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` is a filesystem path.

- Store the file only under `GHCA_ACD_PRIVATE_DIR`, which must resolve outside WordPress and outside `wp-content/uploads`.
- Do not commit it, email it, paste it into Slack or a ticket, upload it to the Media Library, or put it in the plugin directory.
- The file must be at most 64 KiB. The parser reads `client_email` and `private_key` and ignores the idea of displaying them. wp-admin never shows the email or the key.
- A symlink that `realpath` resolves outside the private directory is rejected.
- The access token lives in a static variable for the current PHP request. It is not stored in `wp_options`. The JWT lifetime requested is 300 seconds. Google’s access token can still be valid longer than that on Google’s side; the plugin does not reuse it on the next request.
- `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` encrypts approved PDFs (AES-256-GCM). It is a different secret from the JSON key. Rotating one does not rotate the other. Losing the evidence key makes stored certificates unreadable. Losing the Drive JSON only blocks new reads from Drive.

### Viewer only, one account per agency

Create four service accounts, one per site:

| Site | Share Viewer on |
| --- | --- |
| `compliance.akansohealth.com` | Akansoh **External Training Evidence** only |
| `compliance.isokedevelops.com` | Isoke **External Training Evidence** only |
| `onboard.prolificcaregroup.com` | Prolific **External Training Evidence** only |
| `onboard-lafiahealthcare.cloud` | Lafia **External Training Evidence** only |

The plugin asks for scope `https://www.googleapis.com/auth/drive.readonly`. That is “read every file this account can see,” not “read this folder id only.” The folder share is the real boundary. Sharing **Gridhouse Managed Evidence** with an agency account lets that key read every agency’s children, even though the plugin is pointed at one id.

Give the share the Viewer role. The plugin never writes. A higher Drive role does not make the integration work better.

Do not grant the service account project IAM roles. The code does not check IAM, and it does not need it.

Do not turn on domain-wide delegation for this integration. The JWT has no `sub` claim, so delegation would not be used, and enabling it widens the key.

Leave general access **Restricted**. ([Share files from Google Drive](https://support.google.com/drive/answer/7166529), external UI, may change.)

### Key rotation

1. In Google Cloud, create a new JSON key on the same service account. [Create and delete service account keys](https://cloud.google.com/iam/docs/keys-create-delete).
2. Replace the file on the server at the same path, or upload it under a new name and change `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` to that path. The new file still has to sit inside `GHCA_ACD_PRIVATE_DIR`.
3. Click **Test Connection**. Expect `connection ready`.
4. Delete the old key in Google Cloud. In-flight PHP requests may finish with the token they already minted. The next request reads the file again.
5. Delete every temporary copy of both JSON files.

If the key leaks, delete it in Google Cloud before you do anything else. Then share nothing new with the old email’s replacement until Test Connection passes. A leaked key reads whatever that service account can see. It cannot read another agency’s folder unless you shared that folder with it.

Rotation does not re-encrypt approved certificates. Those use `GHCA_ACD_EVIDENCE_ENCRYPTION_KEY`.

## Verification

- Each site’s JSON path is different, and each file is outside the web root.
- Each service account is Viewer on one **External Training Evidence** folder and is absent from the other three.
- Parent folder **Gridhouse Managed Evidence** has no agency service account on it.
- After rotation, **Test Connection** is `connection ready` and the old key is gone from the Cloud console.
- `git status` does not list a `.json` key. This repository should never contain one.

## Troubleshooting

`error ghca drive credentials invalid` after a rotation usually means the new file is outside the private directory, larger than 64 KiB, or not a service-account key. The banner will not tell you which. See [Drive troubleshooting](troubleshooting.md).

## Related pages

- [Guide home](../README.md)
- [Security model](../security-model.md)
- [Google Cloud service account](google-cloud-setup.md)
- [Server and wp-config](server-setup.md)
- [Folder structure](folder-structure.md)

## Sources

- `GHCA_ACD_Google_Drive_Provider::credentials()`, `access_token()`, and `SCOPE`
- `GHCA_ACD_External_Evidence_Store::key()` and `base_directory()`
- [Create and delete service account keys](https://cloud.google.com/iam/docs/keys-create-delete)
- [Share files from Google Drive](https://support.google.com/drive/answer/7166529)
