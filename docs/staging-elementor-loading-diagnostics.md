# Staging: Elementor editor stuck on "Loading" — diagnostics plan

Status: **open, cause unknown.** Nothing here has been run on staging. This document has two parts: a code review of what GHCA does on the requests Elementor's editor makes, and a proposed, ordered set of controlled diagnostics. It does not assume GHCA caused the problem; the review found nothing that would block the editor outright.

No plugin should be disabled and no setting changed until the read-only steps (1–3) are done and the owner approves the next step.

## 1. What GHCA does on the editor's requests (code review, commit at the top of `BUILD.txt`)

The editor (`wp-admin/post.php?post=N&action=elementor`) loads a preview iframe of the page (`?elementor-preview=N`, a front-end request). It then talks to the server through `admin-ajax.php?action=elementor_ajax` and the REST API.

| GHCA code | Runs on | Effect on the editor | Risk |
| --- | --- | --- | --- |
| Dashboard CSS/JS (`dashboard.css`, `dashboard.js`, `gh-redesign.css`, `gh-bulletins.js`, `agency-training.js`) | Front end, only on pages whose content or `_elementor_data` contains a GHCA shortcode (`page_uses_dashboard()`) | Loads inside the preview iframe **only for the dashboard pages**. Not on the editor shell. | Low for other pages. On the dashboard page a script error in the iframe is possible but not seen in synthetic runs. |
| Dashboard shortcode render | The preview of a page that holds the shortcode | The preview iframe builds the whole compliance aggregate (LearnDash data for every employee in scope), unless the GHCA cache holds it. | **Medium, dashboard pages only.** A slow or failing render keeps the preview from loading. Check whether the editor hangs only on the dashboard page. |
| Footer modals (drawer, certificate, reminder, PDF progress, announcements) | Front end, same dashboard-page gate, logged-in users with GHCA view rights | Extra markup in the preview of the dashboard page only. | Low. |
| `jotform-ownership.js` | **Every front-end page** for a logged-in user when Jotform integration is enabled (deliberately ungated; see `GHCA_ACD_Jotform_UI::enqueue_ownership_bridge()`) | Runs inside the preview iframe of *any* page. It sets a `MutationObserver` on the whole document and checks each added node for Jotform iframes. It does not wrap `fetch`, XHR or `postMessage`. | Low to medium. It is the only GHCA script present on non-dashboard previews, so it is the first GHCA item to rule in or out. |
| `init` hooks (schema version checks, cron schedule checks, sync and reminder-log handlers, rewrite rules) | Every request | Option reads and `wp_next_scheduled()`; the handlers act only on their own query arguments. The ODP rewrite flush runs once per version and has the `notoptions` loop fix (`5fce1ae`). | Low. A repeating flush would show as slow requests on every page, not only the editor. |
| ODP routes (`template_redirect`, priority 1) | Requests to `/odp-bulletins/…` only | None on editor URLs. | None expected. |
| Legacy settings redirect (`admin_init`) | `options-general.php` / `tools.php` with a GHCA page slug only | None on `post.php`. | None. |
| Admin scripts (settings console, audit mapping, Jotform review) | Only on their own GHCA admin pages | None on the editor screen. | None. |
| Stray output before headers (BOM, text after `?>`) | — | Checked every runtime PHP file: none. Stray output would break Elementor's JSON responses. | None found. |

Things that commonly cause this symptom and are **not** GHCA: JS optimisation or caching plugins (minify, combine, defer, delay JS, Cloudflare Rocket Loader), a security plugin or ModSecurity blocking `admin-ajax.php` POSTs, an `http`/`https` or site-URL mismatch, PHP memory or time limits, a theme or BuddyBoss conflict, or a recent Elementor, LearnDash or WordPress update.

## 2. Proposed controlled diagnostics, in order

Steps 1–3 change nothing. Each later step names what it changes and how to undo it, and needs the owner's approval first. Record every result with the time, user and page.

1. **Scope the symptom (read only).** Note the WordPress, PHP, Elementor (and Pro), LearnDash, BuddyBoss and GHCA versions, from Elementor → System Info. Does the editor hang on every page, or only on pages that contain the GHCA dashboard shortcode? For administrators only, or for every editor? Did it start when GHCA was installed (Slice 3, `a890189`) or after something else changed? Check the staging change log.
2. **Browser evidence (read only).** Open the editor with DevTools, with "Preserve log" on, for both the editor and the preview iframe contexts:
   - Console errors in each context, with the file that threw.
   - Network:
     - the preview iframe request (status, time, final URL after any redirect);
     - every `admin-ajax.php?action=elementor_ajax` and `/wp-json/` request (status and body — a body starting with HTML or a PHP notice instead of JSON is the usual cause);
     - any request that never completes.
   - Note whether any GHCA asset (`/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/assets/…`) loads on a non-dashboard page; on such pages only `jotform-ownership.js` should.
3. **Server evidence (read only).** Read the existing PHP error log and the web server and WAF/security logs for the minutes of a failed load. Look for fatals, memory exhaustion, timeouts, and blocked `admin-ajax` POSTs.
4. **Per-session isolation, no site-wide change (approval needed).** Use Elementor → Tools → **Safe Mode** for the testing user only: it loads the editor with other plugins and the theme disabled for that session. If the editor loads in Safe Mode, the cause is a plugin or the theme. If it still hangs, it is Elementor, the server or the configuration. To undo, turn Safe Mode off. An alternative is the Health Check & Troubleshooting plugin's troubleshooting mode, which is also per session but means installing a plugin.
5. **Bisect inside the isolated session (approval needed).** Within Safe Mode or troubleshooting mode, re-enable plugins one at a time for that session only. Start with optimisation and caching, security, then BuddyBoss, LearnDash and GHCA. The first plugin that brings the hang back is the suspect. No site-wide deactivation is needed.
6. **If GHCA is the suspect (approval needed), narrow it without deactivating it site-wide:**
   - Dashboard pages only: test the editor on a copy of the page without the GHCA shortcode. If that copy loads, measure the shortcode render time in the preview: Query Monitor (an install, so approval) or the PHP slow log.
   - All pages: test with the Jotform integration switched off in GHCA settings (`ghca_acd_jotform_enabled`). This is a setting change, so approval is needed, and it is undone by switching it back. This removes `jotform-ownership.js`, the only GHCA script on non-dashboard previews. While it is off, Jotform document indexing and external-training review are paused too, so agree a short window.
   - Test with the redesign switch (`ghca_acd_ui_redesign`) off, also a setting change. This separates Slices 1–4 from the legacy dashboard.
7. **Only then** consider a site-wide change such as deactivating a plugin, during an agreed window, with the owner's approval and a recorded way back.

## 3. What would point at GHCA

- A console error from a GHCA file in the preview iframe, at the point the editor stops.
- An `elementor_ajax` or REST response that contains GHCA output, or a fatal error in a GHCA file in the PHP log.
- The editor loads in Safe Mode, and enabling GHCA alone in that session brings the hang back.
- The hang happens only on pages with the GHCA shortcode, and the preview request is slow or fails there.

If any of these is seen, record the evidence and the GHCA commit. A fix would go in its own change, with its own package and test.

## 4. What has and has not been verified

| | Done | Not done |
| --- | --- | --- |
| Synthetic (this repository) | Code review above. Every runtime PHP file checked for output before headers. Browser runs of the dashboard pages in Chromium on the plugin's own synthetic preview, with no script errors. | No WordPress, Elementor, LearnDash, BuddyBoss or Jotform installed here. The editor itself has not been run. |
| Staging | Nothing. No access was used, and no plugin, setting or file was changed. | Every step in section 2. |
