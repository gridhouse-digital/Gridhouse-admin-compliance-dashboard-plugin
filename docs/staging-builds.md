# Staging builds

One place for what each staging site runs. Update it whenever a build is installed, downloaded for review, or replaced. Package details (size, SHA-256, manifest) are in each build's `BUILD.txt` and the slice document named there.

## Isoke staging

| Build | Status | Date | Notes |
| --- | --- | --- | --- |
| `1.7.3-rc.8-3628587` | Prepared, not installed | | Equal Overview card heights (Announcements matches Compliance by group; whole items only; internal scrolling) and the ODP ticker kept visible for an empty feed ("No ODP bulletins right now."). `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-74a0a3d` | **Installed** (current on Isoke) | Install date not recorded here | Drawer Documents PDF previews in the redesigned viewer; Announcements card shrunk to its content (reverted in the build above, requested). Installed by the site owner; the assistant did not install it. On Isoke the cached ODP feed holds zero bulletins, so this build shows no ticker at all (fixed in the build above). |

## Akansoh staging

| Build | Status | Date | Notes |
| --- | --- | --- | --- |
| `1.7.3-rc.8-10161f1` | **Installed** (current on Akansoh staging) | Install date not recorded here | Overview with reference proportions, capped lists and taller KPI strip; same runtime files as `54bcc15`. Installed by the site owner; the assistant did not install it. It replaced `dea0da8`, the previous build recorded here. Earlier notes wrongly said it was not installed. `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-54bcc15` | Built, never delivered or installed | | Its BUILD.txt stated 53 real-site checks per theme before the run; the measured count is 49. Replaced by the build above. |
| `1.7.3-rc.8-3ce13be` | Prepared, not installed. Superseded; do not install | | Slice 9 with certificate preview Fit to width corrected (opens fitted, whole page shown, zoom and Fit work in Chromium) and long Overview group names wrapping. `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-70e1d6b` | Prepared, not installed. Superseded; do not install | | Slice 9 corrected again: deletions confirmed and never counted when they fail; due marker and backstop kept until cleanup confirms nothing is left; failed job lookup returns a retryable 503; no age-based deletion when records cannot be read. `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-84e9985` | Prepared, not installed. Superseded; do not install | | Slice 9 corrected: atomic ZIP download claim, cleanup independent of builds (cron, backstop, fallback), checked and verified archive writing, failed reads never a 200, Certificates tab clears the employee filter. `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-a67c01d` | Prepared, not installed. Superseded; do not install | | Slice 9 first build, cumulative (Slices 1–9): certificate viewer, certificate selection and ZIP, course listbox, Title Case, certificate routing, Overview columns, 1480px container. `docs/dashboard-redesign-phase2-slice9.md`. |
| `1.7.3-rc.8-dea0da8` | Previously installed; replaced by `10161f1` | November 7, 2026, approximately 10:48 PM (Toronto) | Slice 8 with the announcement group-scope check on edit and delete, and a corrected BUILD.txt. Replaced `94b612d`. Installed by the site owner; the assistant did not install it, and its staging checks are reported by the site owner. `docs/dashboard-redesign-phase2-slice8.md`. |
| `1.7.3-rc.8-1cde2ca` | Prepared, not installed | | Slice 8 first build. Superseded by `dea0da8`; its BUILD.txt understated testing and gave the wrong staging history. Do not install. |
| `1.7.3-rc.8-94b612d` | Previously installed; replaced by `dea0da8` | November 7, 2026, 8:50 PM (Toronto) | Slice 7, cumulative (Slices 1–7). Replaced `826d342`. Installed by the site owner; the assistant did not install it. Its staging checks are reported by the site owner, not by the assistant. `docs/dashboard-redesign-phase2-slice7.md`. |
| `1.7.3-rc.8-51d8679` | Previously installed and checked; superseded on staging (the build `94b612d` replaced was `826d342`) | November 7 | Slices 1–4 with the bulletin, background and design corrections. `docs/dashboard-redesign-phase2-slice4.md`. |
| `1.7.3-rc.8-d93f640` | Previously installed; replaced by `51d8679` | before November 7 | First Slices 1–4 build. |
| `1.7.3-rc.8-a890189` | Previously installed | earlier | Slice 3. |
| `1.7.3-rc.8-ce36016` | Prepared, not installed | | Slice 5. |
| `1.7.3-rc.8-63c3287` | Downloaded and integrity-verified, **not installed** | | Slice 6. Superseded; do not install. |
| `1.7.3-rc.8-ef5d03d` | Prepared, not installed | | Slice 6 with the bulk-retry fix. Superseded by `826d342`. |
| `1.7.3-rc.8-826d342` | Previously installed; replaced by `94b612d` | before November 7, 8:50 PM (Toronto); install date not recorded here | Slice 6, bulk-retry fix and concurrency hardening. Earlier notes wrongly said it was never installed. |
| `0f5d4e0`, `b2da862`, `c6bc947`, `680d12e` | Prepared, not installed | | Interim builds, superseded. |

Outstanding staging checks from earlier builds remain open; staging is not cleared. The blocked bulletin feed on staging is expected and the outbound guard is unchanged.
