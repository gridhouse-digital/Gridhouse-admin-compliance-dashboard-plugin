# Server and wp-config

Operator guide for Gridhouse Admin Compliance Dashboard **1.7.3-rc.1**. Checked against the plugin code.

## Purpose

Put the service-account JSON on the agency server, outside the web root, and tell WordPress the path. The plugin reads the path from a constant. It never accepts the JSON in wp-admin.

## Prerequisites

- The JSON key from [Google Cloud service account](google-cloud-setup.md), still only in a private local copy.
- Shell or SFTP access to that agency’s host. One site, one file.
- `GHCA_ACD_PRIVATE_DIR` planned or already defined. Approved certificates use the same directory.

## Steps

1. Create a directory outside the WordPress install and outside `wp-content/uploads`. A generic layout is a `private` directory next to the WordPress directory, for example `/var/private/ghca-acd`. That path is an example, not a real host.
2. Define the directory in `wp-config.php` above the “stop editing” line:

```php
define( 'GHCA_ACD_PRIVATE_DIR', '/var/private/ghca-acd' );
```

The directory must already exist. The PHP user must be able to write to it, because approved evidence is stored there. `realpath` must not land inside the WordPress root or the uploads directory. On Windows, also set `GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED` to boolean `true` only after the ACL is owner-only. Until then, evidence storage refuses to run.

3. Upload the JSON into that directory, for example `/var/private/ghca-acd/akansoh-drive-reader.json`. Do not put it in the plugin folder, `wp-content/uploads`, or the Media Library.
4. Point the constant at the file:

```php
define( 'GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE', '/var/private/ghca-acd/akansoh-drive-reader.json' );
```

The value is a path. Never paste the JSON body into `wp-config.php`.

5. Restrict the file to the account that runs PHP. Delete the temporary copy you used for the upload.
6. Confirm the file passes the checks in code before you expect **Test Connection** to succeed:
   - The constant is a non-empty string.
   - `realpath` of the file is an existing, readable file inside `GHCA_ACD_PRIVATE_DIR`. A symlink that resolves outside that directory fails.
   - The file is at most 65536 bytes (64 KiB).
   - The JSON is `type` `service_account`, with a `client_email` on a `.gserviceaccount.com` domain and a `private_key` beginning with `-----BEGIN PRIVATE KEY-----`.

`GHCA_ACD_EVIDENCE_ENCRYPTION_KEY` is a different secret, at least 32 characters, and it has to be set before anyone can approve. It is not part of the JSON file. The install page lists it; the full key-management procedure is still coming soon.

The plugin loads this file when it needs a token. It signs a JWT (`RS256`, five-minute expiry) and posts it to `https://oauth2.googleapis.com/token`. OpenSSL (`openssl_sign`) has to be installed. The resulting access token stays in a static variable for that one PHP request.

## Verification

- From the server, the JSON path is readable by PHP and is not under the web root. Do not print the file in a ticket.
- After the Jotform form is saved with **File source** **Google Drive**, **Test Connection** returns `connection ready`. Saving alone does not read the JSON.
- A wrong path produces `error ghca drive credentials invalid` or `error ghca drive credentials missing`, with no path echoed in the banner.

## Troubleshooting

| Banner text | Meaning |
| --- | --- |
| `error ghca drive credentials missing` | The constant is missing or blank. |
| `error ghca drive credentials invalid` | The file is missing, unreadable, outside the private directory, larger than 64 KiB, or not a service-account JSON key. The banner uses one code for all of those. |
| `error ghca drive crypto missing` | PHP OpenSSL cannot sign the JWT. |
| `HTTPS and administrator access are required.` | **Test Connection** and **Save** die unless WordPress `is_ssl()` is true. Fix the site URL or the proxy HTTPS header. This is a `wp_die` page, not a banner. |

The full code map is [Drive troubleshooting](troubleshooting.md).

## Related pages

- [Guide home](../README.md)
- [Installation and wp-config constants](../installation.md)
- [Google Cloud service account](google-cloud-setup.md)
- [Plugin configuration](plugin-configuration.md)
- [Drive security](security.md)

## Sources

- `GHCA_ACD_Google_Drive_Provider::credentials()` and `access_token()`
- `GHCA_ACD_External_Evidence_Store::base_directory()`
- `GHCA_ACD_Jotform_UI::require_admin()`
