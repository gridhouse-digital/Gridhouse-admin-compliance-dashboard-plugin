# Staging builds

One place for what each staging site runs. Update it whenever a build is installed, downloaded for review, or replaced. Package details (size, SHA-256, manifest) are in each build's `BUILD.txt` and the slice document named there.

## Akansoh staging

| Build | Status | Date | Notes |
| --- | --- | --- | --- |
| `1.7.3-rc.8-51d8679` | **Installed and checked** | November 7 | Slices 1–4 with the bulletin, background and design corrections. `docs/dashboard-redesign-phase2-slice4.md`. |
| `1.7.3-rc.8-d93f640` | Previously installed; replaced by `51d8679` | before November 7 | First Slices 1–4 build. |
| `1.7.3-rc.8-a890189` | Previously installed | earlier | Slice 3. |
| `1.7.3-rc.8-ce36016` | Prepared, not installed | | Slice 5. |
| `1.7.3-rc.8-63c3287` | Downloaded and integrity-verified, **not installed** | | Slice 6. Superseded; do not install. |
| `1.7.3-rc.8-ef5d03d` | Prepared, not installed | | Slice 6 with the bulk-retry fix. Superseded by `826d342`. |
| `1.7.3-rc.8-826d342` | Prepared, not installed | | Slice 6, bulk-retry fix and concurrency hardening. Superseded by the Slice 7 build. |
| Slice 7 (commit recorded below) | Prepared, not installed | | Communications and history, row reminders, packet header, packet-ready notice. `docs/dashboard-redesign-phase2-slice7.md`. |
| `0f5d4e0`, `b2da862`, `c6bc947`, `680d12e` | Prepared, not installed | | Interim builds, superseded. |

Outstanding staging checks from earlier builds remain open; staging is not cleared. The blocked bulletin feed on staging is expected and the outbound guard is unchanged.
