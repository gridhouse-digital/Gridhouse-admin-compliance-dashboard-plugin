# Gridhouse Compliance operator guide

Operator documentation for the **Gridhouse Admin Compliance Dashboard** (GHCA ACD) **1.7.3-rc.1**, run by Gridhouse Digital for Akansoh Health Services, Isoke Developmental Services, Prolific Care Group, and Lafia Healthcare.

Each agency has its own WordPress site. The plugin on that site does not know about the other agencies. This guide is the shared operating manual. It is separate from the release notes in the plugin [`README.md`](../../README.md) and from the internal handoff and plan notes in [`docs/`](../).

Checked against plugin 1.7.3-rc.1. Pages marked **Coming soon** are reserved so later topics keep this layout. Google Cloud, Jotform, Zapier, and Make click-paths are **external UI, may change**.

## How to read a page

Every page uses the same sections:

1. Purpose
2. Prerequisites
3. Steps
4. Verification
5. Troubleshooting
6. Related pages

## Contents

### Written

Google Drive is the incoming store for external-training certificates. The plugin only reads it. A file is not approved evidence until someone approves it in External Training Review.

| Page | What it covers |
| --- | --- |
| [How Drive evidence works](google-drive/how-it-works.md) | Who writes files, who reads them, and when a certificate counts |
| [Multi-agency folder structure](google-drive/folder-structure.md) | The nested My Drive tree and which folder ID the plugin stores |
| [Google Cloud service account](google-drive/google-cloud-setup.md) | Project, Drive API, Viewer share, JSON key |
| [Server and wp-config](google-drive/server-setup.md) | Private directory and `GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE` |
| [Plugin configuration](google-drive/plugin-configuration.md) | Jotform Documents fields, save rules, Test Connection |
| [Filling the folders](google-drive/folder-filling.md) | Jotform’s Drive integration, or Zapier/Make for the nested tree |
| [Testing with synthetic data](google-drive/testing.md) | A disposable submission, then preview, preflight, and approval |
| [Drive troubleshooting](google-drive/troubleshooting.md) | Every Drive error code, what the screen prints, and the fix |
| [Drive security](google-drive/security.md) | Secrets, Viewer-only, one account per agency, key rotation |

Read them in that order the first time.

### Coming soon

| Page | What it will cover |
| --- | --- |
| [Overview and architecture](overview.md) | Coming soon. Menu map is already listed there. |
| [Installation and wp-config constants](installation.md) | Coming soon. Constant names are listed; Drive setup is already written. |
| [Roles and permissions](roles-and-permissions.md) | Coming soon. |
| [Employees and groups](employees-and-groups.md) | Coming soon. Manage Users, new hire, annual cycle. |
| [Jotform documents and external training review](jotform-and-external-training.md) | Coming soon. Drive-specific setup is in the pages above. |
| [Audit packets and exports](audit-packets.md) | Coming soon. |
| [Messaging](messaging.md) | Coming soon. Email and Twilio. |
| [Security model](security-model.md) | Coming soon. Drive secret handling is already written. |
| [Troubleshooting](troubleshooting.md) | Coming soon. Drive errors are already mapped. |
| [Release and deploy](release-and-deploy.md) | Coming soon. |

## Sites this guide is for

These hostnames are the agency sites Gridhouse operates. They are not configured inside the plugin.

| Agency | Site |
| --- | --- |
| Akansoh Health Services | `compliance.akansohealth.com` |
| Isoke Developmental Services | `compliance.isokedevelops.com` |
| Prolific Care Group | `onboard.prolificcaregroup.com` |
| Lafia Healthcare | `onboard-lafiahealthcare.cloud` |

Repeat the Drive setup once per site. Do not copy one site’s service-account JSON or folder ID onto another.

## Sources

- Plugin 1.7.3-rc.1, especially `includes/jotform/class-google-drive-provider.php`, `includes/jotform/class-jotform-ui.php`, `includes/jotform/class-jotform-sync.php`, `includes/jotform/class-external-evidence-store.php`
- [Google Drive API](https://developers.google.com/workspace/drive/api/guides/about-sdk)
- [Jotform Google Drive integration](https://www.jotform.com/help/192-how-to-integrate-your-form-with-google-drive/)
