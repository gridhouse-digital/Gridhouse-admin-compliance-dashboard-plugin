# CodeRabbit scan 08f5c889 — reconciliation

> **Historical assessment at `8385131`.** The findings table and the per-finding evidence below are a point-in-time record of the code at that commit and are intentionally not rewritten. For what has changed since, see **Current disposition** at the end.

- Scan: `08f5c889`, generated Oct 24 8:42 PM against an older commit.
- Reconciled against: **`8385131`** (`main` after PR #5, "fix(GRI-62)").
- Method: each finding was traced to the guard (or vulnerable lines) in the code at `8385131`. A passing test alone was not accepted as closure. Line numbers are as of `8385131`.
- Synthetic fixtures only. No real employee data.

| ID | Severity | Verdict at 8385131 | Test |
|---|---|---|---|
| F-001 | medium (IDOR) | **Fixed** | partial (see below) |
| F-002 | medium (CWE-295) | **Fixed** | partial (origin allow-list only) |
| F-003 | low | **Fixed** | `tests/test-security-boundaries.php` |
| F-004 | low (CSV injection) | **Still present** | none |
| F-005 | low (DoS) | **Still present** | none |
| F-006 | low (DoS) | **Fixed** | `tests/test-fpdi-flate-budget.php` |

---

## F-001 — `ajax_save_employee` writes without a target-specific scope check

- **Commit:** 8385131
- **Code path:** `includes/class-ajax-handlers.php` : `GHCA_ACD_Ajax_Handlers::ajax_save_employee` : 1334–1402 (guard 1395–1400, write 1402); helpers `includes/class-roles.php` : `delegated_target_is_allowed` 273–285, `delegated_target_is_in_scope` 287–294; `includes/class-user-report.php` : `can_view_user` 166–187.
- **Verdict:** Fixed.
- **Evidence:** the existing-user branch runs a target-specific check before `wp_update_user`, and every later write (phone meta, xprofile, group membership, employment type) comes after it:
  ```php
  if ( ! current_user_can( 'manage_options' ) ) {
    if ( ! GHCA_ACD_Roles::delegated_target_is_allowed( get_current_user_id(), $user_id, (array) $existing_user->roles, GHCA_ACD_Roles::delegated_target_is_in_scope( $user_id ), (array) $existing_user->allcaps ) ) {
      wp_send_json_error( __( 'You do not have permission to modify this employee.', 'ghca-acd' ) );
    }
  }
  $result = wp_update_user( $userdata );
  ```
  `delegated_target_is_allowed` fails closed for self-edit, out-of-scope targets, empty roles, protected/privileged roles and privileged per-user capabilities. `delegated_target_is_in_scope` requires the target to be in the viewer's `get_employee_user_ids()` (built from the viewer's own authorized groups) **and** either an agency employee manager (`user_has_full_dashboard_access()` / `hr_manager`) or `can_view_user()` (target's primary group must be in `GHCA_ACD_Scoping::get_visible_group_ids()`). The nonce, `user_can_manage_users()`, role-assignability and group-assignability checks (lines 1335–1370) are retained. Group assignment keeps groups the actor cannot manage (`$unmanageable_groups`).
  The sibling endpoint `ajax_save_employee_records` (920–930) gates on `can_view_user( $user_id )`.
- **Test:** `tests/test-security-boundaries.php` lines 109–116 (pure `delegated_target_is_allowed` cases: self-edit, out-of-scope, privileged and mixed roles, per-user caps, in-scope allow) and 139 (source assertion on `delegated_target_is_in_scope`); `tests/test-employment-type.php` "authorization gate prevents writes" (handler makes no account/meta write when the scope gate fails). Note: that handler test stubs the scope helpers, so the real scope derivation is covered only by the source assertion and the pure tests; **no test builds a real out-of-scope group-leader fixture end to end.**

## F-002 — `ajax_fetch_cert` with `sslverify=false` while forwarding cookies

- **Commit:** 8385131
- **Code path:** `includes/class-audit-pdf.php` : `ajax_fetch_cert` 134–208 (URL gate 172–177); `request_certificate` 1059–1083; `get_certificate_request_cookies` 1040–1057; `includes/class-data-provider.php` : `sanitize_certificate_url` 1091–1121.
- **Verdict:** Fixed.
- **Evidence:**
  ```php
  $options = array( 'timeout' => 25, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => self::MAX_CERTIFICATE_BYTES + 1 );
  ...
  $options['cookies'] = self::get_certificate_request_cookies();
  return wp_safe_remote_get( $url, $options );
  ```
  TLS is verified; redirects are disabled; `wp_safe_remote_get` is used; the URL must pass `sanitize_certificate_url` (same scheme, host and port as `home_url()`, no credentials) or the job is aborted; only `LOGGED_IN_COOKIE` is forwarded, scoped to the site host and `COOKIEPATH`. A transport error yields an empty body, which fails `certificate_response_is_allowed` and aborts the whole job (no partial packet). For non-admin viewers certificates go through the one-time token broker, which sends no cookies. No `sslverify => false` remains anywhere in `includes/`.
- **Test:** `tests/test-security-boundaries.php` 148–151 (same-origin accepted; cross-origin, `javascript:` and credential URLs rejected) and 153–155 (response size/type/aggregate limits). **None** asserts `sslverify`, the cookie allow-list or the redirect setting.

## F-003 — global onboarding groups added after the viewer's authorized groups

- **Commit:** 8385131
- **Code path:** `includes/class-data-provider.php` : `get_tracked_group_ids` 73–80; `merge_tracked_group_ids` 86–94; consumer `get_employee_user_ids` 114+ (roster, aggregate and CSV all derive from it via `get_employees_for_current_view`).
- **Verdict:** Fixed.
- **Evidence:**
  ```php
  public static function merge_tracked_group_ids( array $visible_group_ids, array $new_hire_group_ids, bool $unrestricted ): array {
    $visible = array_values( array_unique( array_map( 'intval', $visible_group_ids ) ) );
    if ( ! $unrestricted ) {
      return $visible;
    }
  ```
  New-hire groups are appended only when the viewer is already unrestricted (`manage_options`, `edit_users` or `user_has_unrestricted_view()`), who may see every group anyway. Scoped viewers keep only their authorized groups.
- **Test:** `tests/test-security-boundaries.php` 142–145 ("scoped viewers do not inherit global new-hire groups"; "unrestricted viewers retain global new-hire groups").
- **Residual note (availability, not disclosure):** `get_user_primary_group_id` prefers the new-hire group for people still in onboarding, so a scoped leader's `can_view_user` can be false for a member whose primary group resolves to the new-hire group. That hides records rather than exposing them.

## F-004 — `fputcsv` default escape character breaks field boundaries

- **Commit:** 8385131
- **Code path:** `includes/class-export.php` : `handle_export` : 52 and 81; `includes/class-audit-export.php` : `generate_annual_csv` 100 and 125, `generate_orientation_csv` 146 and 169. `csv_safe` (class-export.php ~90, class-audit-export.php 73–79) only prefixes `' ` when the first character is one of `= + - @ \t \r`.
- **Verdict:** Still present.
- **Evidence:** all six calls use the default escape (`\`), e.g. `fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );`. Reproduced on PHP 8.3.6 with the synthetic row `['Doe\"', ',=1+1', 'tail']`:
  ```
  default escape: "Doe\"",",=1+1",tail
    parsed (RFC 4180, escape '') = ["Doe\\\",","=1+1\"","tail"]   -> BROKEN round trip
  escape '':      "Doe\""",",=1+1",tail
    parsed                       = ["Doe\\\"",",=1+1","tail"]     -> OK
  ```
  A backslash immediately before a quote leaves the quote undoubled, so a strict reader merges/shifts fields; the second field's value is cut from `,=1+1` to `=1+1"`, i.e. field boundaries (and where the formula neutraliser applies) are attacker-influenced via names or other free text. Also deprecated as a default in PHP 8.4.
- **Test:** none.

## F-005 — bundled FPDI: cyclic `/Prev` in the cross-reference chain

- **Commit:** 8385131
- **Code path:** `includes/lib/fpdi/PdfParser/CrossReference/CrossReference.php` : `__construct` : 57–86 (loop 65, `Prev` follow 82–84). Reached from `setSourceFile` in `includes/class-audit-pdf.php` : `append_certificate` ~918 and `includes/jotform/class-external-evidence-store.php` : `normalize_to_pdf` ~397.
- **Verdict:** Still present.
- **Evidence:**
  ```php
  while ($offset != false) { ...
      $reader = $this->readXref($offset + $this->fileHeaderOffset);
      ...
      $this->readers[] = $reader;
      if (isset($trailer->value['Prev'])) { $offset = $trailer->value['Prev']->value; } else { $offset = false; }
  }
  ```
  No visited-offset set and no chain-length cap. Reproduced with a ~400-byte synthetic PDF whose trailer `/Prev` equals its own `startxref` offset: with `memory_limit=128M` the parser dies with a non-catchable fatal ("Allowed memory size … exhausted", Tokenizer.php:65) after ~2.4 s. Bundled code was not modified at `8385131`. (Historical wording: the options and approval referred to here have since been resolved; see Current disposition.)
- **Test:** none (existing FPDI tests: `tests/test-fpdi-flate-budget.php`, `tests/test-fpdi-library-complete.php`).

## F-006 — bundled FPDI: no expanded-output or aggregate decoded-byte limit

- **Commit:** 8385131
- **Code path:** `includes/lib/fpdi/PdfParser/Filter/Flate.php` : `decode` 47–95 (`MAX_DECODED_BYTES` line 18), `resetDecodedBudget` 20; `includes/lib/fpdi/PdfParser/Filter/DecodedStreamBudget.php` : `consume` 15–25 (`MAX_BYTES` line 7); also called from `AsciiHex.php:31`, `Ascii85.php:100`, `Lzw.php:98,101,107`; budget reset per certificate at `includes/class-audit-pdf.php` ~917 and `includes/jotform/class-external-evidence-store.php` ~396. `Page::getContentStream` reads through `getUnfilteredStream`, i.e. through these filters.
- **Verdict:** Fixed.
- **Evidence:** per-stream cap (`gzuncompress($data, self::MAX_DECODED_BYTES + 1)` and a length check, 32 MiB) plus an aggregate budget:
  ```php
  self::$bytes += \strlen($data);
  if (self::$bytes > self::MAX_BYTES) { throw new FlateException('Decoded certificate content exceeds the configured safety limit.', ...); }
  ```
  Every decoder (Flate, AsciiHex, Ascii85, Lzw) routes through it. Application limits also apply: 8 MiB per certificate, 100 pages, 40 certificates (`class-audit-pdf.php` 7–10).
- **Test:** `tests/test-fpdi-flate-budget.php` (two 17 MiB decodes exceed the aggregate budget).
- **Residual note:** the limits bound memory; there is no wall-clock bound on a parse (not what F-006 reported).

---

## Current disposition

Status after the assessment above. The verdicts and evidence in the sections above are the historical record at `8385131` and are unchanged.

- **F-004 (CSV field boundaries): addressed by merged PR #7**, merge commit `04bcfbafef93db8954c28dc87c35f22d84d0b768`. Every CSV export now goes through one shared writer that neutralizes formula prefixes per original field and writes with an explicit empty escape character; the test parses the output with a strict RFC 4180 reader.
- **F-005 (bundled FPDI cyclic `/Prev`): addressed by merged PR #8**, merge commit `e7925839d24fc88ebc2b82972b0b73da616c1347`. The bundled FPDI was upgraded from 2.6.0 to 2.6.9 (the F-006 decoded-byte limits were re-applied on top), and a plugin-owned check now verifies every page's content streams before a certificate is merged. That check covers page content streams only; XObjects and other components, approval-time checks, and truncated Flate streams are tracked separately as known gaps.
- **F-001, F-002, F-003, F-006:** no change since `8385131` (Fixed, as recorded above).
- **Test limitations that remain:** F-001 coverage is partial (the handler test stubs the scope helpers; there is no end-to-end out-of-scope group-leader fixture), and F-002 coverage is partial (only the same-origin allow-list and response limits are tested; `sslverify`, the cookie allow-list and the redirect setting are not asserted).

## Follow-up

Request a **fresh CodeRabbit scan against the final build** (one that includes PR #7 and PR #8), so the report reflects current code rather than the older commit scanned as `08f5c889`.
