# Google Cloud service account

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Checked against what the plugin accepts. Every Google Cloud click-path below is **external UI, may change**. Follow the official pages if a label has moved.

## Purpose

Create a Google Cloud project and a service account that can read one agency’s evidence folder. Repeat this once per agency. Do not reuse a project or a key across Akansoh, Isoke, Prolific, and Lafia.

## Prerequisites

- A Google account Gridhouse or the agency controls.
- The agency’s **External Training Evidence** folder already created. See [Folder structure](folder-structure.md).
- Agreement that a JSON private key is allowed. The plugin has no other sign-in method. If policy forbids JSON keys, stop. Workload identity, user OAuth, and domain-wide delegation are not implemented.

## Steps

Do this in the Google Cloud console for that agency only.

1. Create a project named for the agency, for example `Akansoh Compliance Drive`. [Creating and managing projects](https://cloud.google.com/resource-manager/docs/creating-managing-projects).
2. Enable the Google Drive API. [Enable the Drive API](https://developers.google.com/workspace/drive/api/guides/enable-drive-api).
3. Create a service account, for example `akansoh-compliance-drive-reader`. Do not grant it project roles such as Owner, Editor, or Viewer. The plugin never uses project IAM. It only uses the key and the folder share. [Create service accounts](https://cloud.google.com/iam/docs/service-accounts-create).
4. Copy the service-account email. It looks like `akansoh-compliance-drive-reader@YOUR_PROJECT.iam.gserviceaccount.com`. The plugin accepts any `client_email` matching `^[^\s@]+@[^\s@]+\.gserviceaccount\.com$`. Google’s normal address includes `.iam.gserviceaccount.com`. The code does not require the `.iam.` segment, but a key you download from Google Cloud will have it.
5. In Google Drive, share that agency’s **External Training Evidence** folder with the service-account email as **Viewer**. Leave general access **Restricted**. Do not share the parent **Gridhouse Managed Evidence** folder with this account. [Share files from Google Drive](https://support.google.com/drive/answer/7166529).
6. On the service account, create a JSON key: **Keys → Add key → Create new key → JSON**. [Create and delete service account keys](https://cloud.google.com/iam/docs/keys-create-delete). The download is a secret. Do not email it, paste it into chat, commit it, drop it in the Media Library, or show it on a call. Store it only long enough to upload it to the private directory in the next page.
7. The JSON must be `type` `service_account`, with `client_email` and a `private_key` that starts with `-----BEGIN PRIVATE KEY-----`. A downloaded Google JSON key already has that shape. An OAuth client secret will not work.

There is no domain-wide delegation step. The JWT the plugin builds has `iss` set to the service-account email and has no `sub` claim, so it does not impersonate a user. Sharing the folder is what grants access.

The OAuth scope is fixed: `https://www.googleapis.com/auth/drive.readonly`. That scope can read every Drive file shared with the account. Viewer on one folder is what keeps the other agencies out.

## Verification

- The service account has no project IAM roles.
- The folder share shows the service-account email as Viewer, and the parent folders do not.
- You have a JSON file that has not been copied into the plugin directory or into git. The next page places it on the server.
- You can still find the service-account email later. The wp-admin screen never displays which account the site is using.

## Troubleshooting

A bad key or a missing share shows up on **Test Connection** only after the file is on the server and the folder id is saved. Those codes are in [Drive troubleshooting](troubleshooting.md).

If the Cloud console refuses to create a JSON key, do not invent a workaround in wp-admin. The plugin cannot authenticate without that file.

## Related pages

- [Guide home](../README.md)
- [Folder structure](folder-structure.md)
- [Server and wp-config](server-setup.md)
- [Drive security](security.md)

## Sources

- `GHCA_ACD_Google_Drive_Provider` constants `TOKEN_URL`, `API_BASE`, and `SCOPE`, and `credentials()` / `access_token()`
- [Enable the Drive API](https://developers.google.com/workspace/drive/api/guides/enable-drive-api)
- [Create service accounts](https://cloud.google.com/iam/docs/service-accounts-create)
- [Create and delete service account keys](https://cloud.google.com/iam/docs/keys-create-delete)
- [Share files from Google Drive](https://support.google.com/drive/answer/7166529)
