# Beta 13 — settings layout and new-menu correction

## Cause and fix

WordPress `in_admin_header` runs inside `#wpcontent` before `#wpbody`; `#wpbody-content` is a 100%-width float. Beta12 floated the new header but offset only the nested `.wrap`. The content therefore dropped below the header, reproducing the user's large blank area. CSS now places header and `#wpbody` in separate grid columns at their actual shared parent, with a block layout on narrow screens. WordPress global navigation and notices are preserved.

Section links previously relied only on hashchange; clicking the same hash after collapsing its section did nothing. Scoped handlers now explicitly open, scroll and focus existing same-page targets on every click, and update current-link state. Modified clicks and other admin page URLs use normal browser navigation. No permission, form persistence, training calculation or employee record changes.

## Verification

An installed Playwright runtime and existing Chromium headless executable were used without dependency installation. The temporary regression fixture renders the actual PHP-generated console menu and actual plugin CSS/JS in WordPress's observed DOM structure with fictional fields and local intercepted routes. Before: heading Y=928.08; after: Y=50.08. All 12 generated links were clicked; repeated-hash reopening, current state/focus, dirty state and 390px no-overflow checks passed. Desktop viewport 1600×1000; mobile 390×844. No page script errors. Screenshots are local temporary artifacts, not site screenshots. Default Playwright launch first failed because its expected browser revision was missing; selecting the already-installed executable succeeded.

Persistent regression checks: `tests/test-settings-navigation.cjs`, `tests/test-settings-console.cjs`, `tests/test-settings-console.php`. Live staging routes, capabilities, settings persistence and plugin-specific interference remain acceptance checks. This proves the reported layout reproduction and scoped navigation behavior, not a complete prototype port or production acceptance.

## Handoff

Release: `../packages/Gridhouse-admin-compliance-dashboard-1.7.3-beta.13-test-only.zip` (relative to plugin root), 117 entries verified against source hashes/byte counts; manifest `1.7.3-beta.13-manifest.csv`. ZIP SHA-256: `8626548C4CC79BDD51CB22D33B7037D63DF98E88916EAB6A486F3D14D96EF001`. Runtime diff versus beta12 is limited to console CSS/JS, entrypoint version, README and TESTING. Both console JavaScript tests, 21 PHP console checks, all 314 cumulative training/packet checks and entrypoint PHP syntax pass. Browser run was repeated after fixing the test response charset and passed again. Temporary screenshots: `C:/Users/oyiny/AppData/Local/Temp/ghca-console-before.png`, `ghca-console-after.png`, and `ghca-console-mobile.png`; temporary runner `ghca-console-layout-check.cjs` in the same directory. Native patch CLI was used because the file-editing tool's sandbox helper failed to initialize; no existing release ZIP was overwritten.

Replace beta12 with beta13 on staging; do not install alongside it. Follow TESTING.md. All beta12 training/packet behavior is retained. No live installation, Jotform interaction, messaging test or real record mutation was performed. Prior ZIPs retained. File manifests provide parity because this isolated workcopy has no Git metadata.
