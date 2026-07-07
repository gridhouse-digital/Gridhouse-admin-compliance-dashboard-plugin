# PDF Background Processing (Async Packet Queue) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous `admin-post.php` compliance-packet download (which 504s on 20+ certificate employees) with a three-phase AJAX flow (init → per-cert fetch → merge) driven by a frontend progress modal.

**Architecture:** A new `GHCA_Audit_PDF_Jobs` class owns job manifests (transients) and the temp/packet directories under `wp-content/uploads/`. `GHCA_Audit_PDF` is refactored into reusable pieces (lib loading, cover renderer, cert appender) and gains four `wp_ajax_*` handlers. `dashboard.js` gains a progress-modal module that drives the phases sequentially and finally navigates to a permission-checked PHP download endpoint.

**Tech Stack:** WordPress (no build step), TCPDF (bundled with LearnDash) + FPDI (bundled in `includes/lib/fpdi`), vanilla JS (ES5 style, matching `dashboard.js`), plain-PHP test scripts (`php tests/test-*.php`).

## Global Constraints

- Plugin text domain is `ghca-acd`; all user-facing strings wrapped in `__()`/`esc_html__()`.
- All AJAX endpoints verify `check_ajax_referer( 'ghca_acd_table', 'nonce' )` + `GHCA_ACD_Roles::user_can_view()` + `GHCA_ACD_User_Report::can_view_user( $target )` — same gate stack as existing endpoints.
- Job IDs are 32 hex chars from `random_bytes(16)` — never `uniqid()` (guessable).
- A job may only be touched (fetch/merge/download) by the admin user who created it.
- Jobs, temp folders and finished packets expire after 1 hour (`HOUR_IN_SECONDS`).
- Both upload directories must contain `index.html` and a deny-all `.htaccess`.
- JS follows the existing `dashboard.js` idiom: ES5 `var`/`function`, `URLSearchParams` + `fetch`, delegated `document.addEventListener('click', …)`, footer modal shells rendered by PHP.
- Indentation: PHP files in this plugin use 2-space indentation in `class-ajax-handlers.php` and tabs in `class-audit-pdf.php` — match whichever file you are editing.

## Decision Points (RESOLVED by user 2026-07-06)

1. **Fetch-failure policy: ABORT.** These are healthcare compliance audit packets — an incomplete packet is a liability. If any certificate fetch fails (HTTP error, non-PDF body, disk write failure) or any cert file is missing/unreadable at merge time: delete the job (manifest + temp files + any packet), and return an explicit `wp_send_json_error` so the HR manager sees that generation failed. No partial packet is ever produced.
2. **Packet retention: 1 hour confirmed.** GC deletes temp folders and finished packets older than `HOUR_IN_SECONDS`.

---

### Task 1: Job store — `GHCA_Audit_PDF_Jobs`

**Files:**
- Create: `includes/class-audit-pdf-jobs.php`
- Create: `tests/test-audit-pdf-jobs.php`
- Modify: `gridhouse-admin-compliance-dashboard.php:35` (add require)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (used by Tasks 3 & 6):
  - `GHCA_Audit_PDF_Jobs::create_job( int $owner_id, int $user_id, string $tracker, array $urls, string $filename ): string` — returns `$job_id`, persists manifest transient, creates temp dir.
  - `GHCA_Audit_PDF_Jobs::get_job( string $job_id, int $owner_id ): array|WP_Error` — validated manifest.
  - `GHCA_Audit_PDF_Jobs::temp_dir( string $job_id ): string` — absolute path, trailing-slash-free.
  - `GHCA_Audit_PDF_Jobs::cert_path( string $job_id, int $index ): string`
  - `GHCA_Audit_PDF_Jobs::packet_path( string $job_id ): string`
  - `GHCA_Audit_PDF_Jobs::delete_job( string $job_id ): void`
  - `GHCA_Audit_PDF_Jobs::gc(): void`
  - Pure (unit-tested): `is_valid_job_id( string ): bool`, `is_expired( int $created, int $now ): bool`, `validate_manifest( $manifest, int $owner_id, int $now ): true|string`

- [ ] **Step 1: Write the failing test**

Create `tests/test-audit-pdf-jobs.php`:

```php
<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';

$fails = 0;
function check( bool $cond, string $msg ): void {
  global $fails;
  if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
}

// --- job id format ---------------------------------------------------------
check( GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'a1', 16 ) ), '32 hex chars => valid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( 'short' ), 'short id => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'g', 32 ) ), 'non-hex => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( '../../etc/passwd' ), 'traversal => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( strtoupper( str_repeat( 'a1', 16 ) ) ), 'uppercase => invalid (we only mint lowercase)' );

// --- expiry -----------------------------------------------------------------
$now = 1000000000;
check( ! GHCA_Audit_PDF_Jobs::is_expired( $now - 3599, $now ), '59m59s old => live' );
check( GHCA_Audit_PDF_Jobs::is_expired( $now - 3601, $now ), '1h+1s old => expired' );
check( GHCA_Audit_PDF_Jobs::is_expired( 0, $now ), 'zero created => expired' );

// --- manifest validation ----------------------------------------------------
$good = array( 'owner' => 5, 'user_id' => 9, 'tracker' => 'annual', 'urls' => array( 'http://x/a' ), 'filename' => 'p.pdf', 'created' => $now - 60 );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 5, $now ) === true, 'good manifest => true' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( false, 5, $now ) === 'not_found', 'transient miss (false) => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( array(), 5, $now ) === 'not_found', 'empty array => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 6, $now ) === 'owner_mismatch', 'other admin => owner_mismatch' );
$stale = array_merge( $good, array( 'created' => $now - 7200 ) );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $stale, 5, $now ) === 'expired', 'stale => expired' );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-audit-pdf-jobs.php`
Expected: FAIL — fatal error, `Class "GHCA_Audit_PDF_Jobs" not found` (require of missing file).

- [ ] **Step 3: Write the implementation**

Create `includes/class-audit-pdf-jobs.php` (tabs, matching `class-audit-pdf.php`):

```php
<?php
/**
 * Job store for the async compliance-packet builder.
 *
 * A "job" is one packet build for one employee: a manifest transient
 * (owner, target user, tracker type, certificate URLs) plus a per-job temp
 * folder for fetched certificates and a shared folder for finished packets.
 * Both folders live under wp-content/uploads and are blocked from direct
 * HTTP access; the finished packet is only ever streamed through the
 * permission-checked download endpoint in GHCA_Audit_PDF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_PDF_Jobs {

	const TRANSIENT_PREFIX = 'ghca_acd_pdf_job_';
	const TEMP_DIR_NAME    = 'ghca_compliance_temp';
	const PACKETS_DIR_NAME = 'ghca_compliance_packets';
	const TTL              = HOUR_IN_SECONDS;

	/* ---------------------------------------------------------------------
	 * Pure helpers (unit-tested without WordPress)
	 * ------------------------------------------------------------------- */

	/** Job ids are exactly 32 lowercase hex chars (bin2hex of 16 random bytes). */
	public static function is_valid_job_id( string $job_id ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $job_id );
	}

	public static function is_expired( int $created, int $now ): bool {
		return $created <= 0 || ( $now - $created ) > self::TTL;
	}

	/**
	 * Validates a raw manifest against the requesting admin.
	 *
	 * @param mixed $manifest Whatever get_transient() returned.
	 * @return true|string true when usable, otherwise an error code:
	 *                     'not_found' | 'owner_mismatch' | 'expired'.
	 */
	public static function validate_manifest( $manifest, int $owner_id, int $now ) {
		if ( ! is_array( $manifest ) || ! isset( $manifest['owner'] ) ) {
			return 'not_found';
		}
		if ( (int) ( $manifest['owner'] ?? 0 ) !== $owner_id ) {
			return 'owner_mismatch';
		}
		if ( self::is_expired( (int) ( $manifest['created'] ?? 0 ), $now ) ) {
			return 'expired';
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------- */

	public static function temp_base(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::TEMP_DIR_NAME;
	}

	public static function packets_base(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::PACKETS_DIR_NAME;
	}

	public static function temp_dir( string $job_id ): string {
		return self::temp_base() . '/' . $job_id;
	}

	public static function cert_path( string $job_id, int $index ): string {
		return self::temp_dir( $job_id ) . '/cert_' . $index . '.pdf';
	}

	public static function packet_path( string $job_id ): string {
		return self::packets_base() . '/' . $job_id . '_packet.pdf';
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<int,string> $urls Certificate URLs, indexed 0..n-1.
	 * @return string The new job id.
	 */
	public static function create_job( int $owner_id, int $user_id, string $tracker, array $urls, string $filename ): string {
		$job_id = bin2hex( random_bytes( 16 ) );

		self::ensure_dir( self::temp_base() );
		self::ensure_dir( self::packets_base() );
		wp_mkdir_p( self::temp_dir( $job_id ) );

		set_transient(
			self::TRANSIENT_PREFIX . $job_id,
			array(
				'owner'    => $owner_id,
				'user_id'  => $user_id,
				'tracker'  => $tracker,
				'urls'     => array_values( $urls ),
				'filename' => $filename,
				'created'  => time(),
			),
			self::TTL
		);

		return $job_id;
	}

	/** @return array|WP_Error The manifest, or a WP_Error safe to send to the client. */
	public static function get_job( string $job_id, int $owner_id ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'ghca_pdf_bad_job', __( 'Invalid job reference.', 'ghca-acd' ) );
		}

		$manifest = get_transient( self::TRANSIENT_PREFIX . $job_id );
		$verdict  = self::validate_manifest( $manifest, $owner_id, time() );
		if ( true !== $verdict ) {
			return new WP_Error( 'ghca_pdf_' . $verdict, __( 'This download job is no longer available. Please start again.', 'ghca-acd' ) );
		}

		return $manifest;
	}

	public static function delete_job( string $job_id ): void {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . $job_id );
		self::rmdir_recursive( self::temp_dir( $job_id ) );
		if ( file_exists( self::packet_path( $job_id ) ) ) {
			@unlink( self::packet_path( $job_id ) );
		}
	}

	/** Deletes temp folders and finished packets older than TTL. Cheap; runs on every init. */
	public static function gc(): void {
		$now = time();

		foreach ( (array) glob( self::temp_base() . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( self::is_expired( (int) @filemtime( $dir ), $now ) ) {
				self::rmdir_recursive( $dir );
			}
		}

		foreach ( (array) glob( self::packets_base() . '/*.pdf' ) as $file ) {
			if ( self::is_expired( (int) @filemtime( $file ), $now ) ) {
				@unlink( $file );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Filesystem plumbing
	 * ------------------------------------------------------------------- */

	/** Creates a base dir and drops deny-all protection files into it. */
	private static function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$rules  = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			@file_put_contents( $dir . '/.htaccess', $rules );
		}
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $item ) {
			is_dir( $item ) ? self::rmdir_recursive( $item ) : @unlink( $item );
		}
		@rmdir( $dir );
	}
}
```

Note: the test bootstrap does not define `HOUR_IN_SECONDS` or `WP_Error`. Add to `tests/bootstrap.php` (append after the `DAY_IN_SECONDS` line):

```php
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
```

(`WP_Error` is only referenced inside methods the test never calls, so no shim is needed.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-audit-pdf-jobs.php`
Expected: `ALL PASS`, exit 0. Also re-run `php tests/test-course-lifespans.php` — still `ALL PASS`.

- [ ] **Step 5: Register the class file**

In `gridhouse-admin-compliance-dashboard.php`, after line 35 (`require_once __DIR__ . '/includes/class-audit-pdf.php';`) add:

```php
require_once __DIR__ . '/includes/class-audit-pdf-jobs.php';
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-audit-pdf-jobs.php tests/test-audit-pdf-jobs.php tests/bootstrap.php gridhouse-admin-compliance-dashboard.php
git commit -m "feat: add job store for async PDF packet builder"
```

---

### Task 2: Refactor `GHCA_Audit_PDF` into reusable pieces (no behavior change)

**Files:**
- Modify: `includes/class-audit-pdf.php`

**Interfaces:**
- Consumes: nothing new.
- Produces (used by Task 3):
  - `GHCA_Audit_PDF::load_libs(): true|WP_Error`
  - `GHCA_Audit_PDF::create_document( array $audit_data ): \setasign\Fpdi\Tcpdf\Fpdi`
  - `GHCA_Audit_PDF::render_cover( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $audit_data, string $tracker_type ): void`
  - `GHCA_Audit_PDF::append_certificate( \setasign\Fpdi\Tcpdf\Fpdi $pdf, string $path ): bool`
  - `GHCA_Audit_PDF::collect_certificate_urls( array $audit_data, int $user_id ): array` — flat, 0-indexed URL list.
  - `GHCA_Audit_PDF::build_filename( array $audit_data ): string`
  - `GHCA_Audit_PDF::resolve_audit_context( int $user_id, string $tracker_type ): array|WP_Error` — returns `array{ audit_data: array, employee_data: array }`.

The synchronous `admin_post_ghca_acd_download_packet` route keeps working after this task (it is deleted in Task 6); it now composes the extracted pieces.

- [ ] **Step 1: Extract lib loading**

Replace lines 23–39 of `handle_download()` (the TCPDF/FPDI loading with `wp_die`) with `self::load_libs()` + `is_wp_error` check, and add:

```php
	/** Loads TCPDF (from LearnDash) and the bundled FPDI. */
	public static function load_libs() {
		if ( ! class_exists( 'TCPDF' ) ) {
			$tcpdf_path = WP_PLUGIN_DIR . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php';
			if ( file_exists( $tcpdf_path ) ) {
				require_once $tcpdf_path;
			} else {
				return new WP_Error( 'ghca_pdf_no_tcpdf', __( 'LearnDash TCPDF library not found.', 'ghca-acd' ) );
			}
		}

		$fpdi_autoload = __DIR__ . '/lib/fpdi/autoload.php';
		if ( file_exists( $fpdi_autoload ) ) {
			require_once $fpdi_autoload;
		} else {
			return new WP_Error( 'ghca_pdf_no_fpdi', __( 'FPDI library not found in plugin.', 'ghca-acd' ) );
		}

		return true;
	}
```

- [ ] **Step 2: Extract audit context resolution**

Move lines 41–68 of `handle_download()` (employee lookup, fallback userdata, mapping load, `calculate_employee_audit_data`, empty check) into:

```php
	/**
	 * Resolves the employee record + audit data for one packet build.
	 *
	 * @return array{audit_data: array, employee_data: array}|WP_Error
	 */
	public static function resolve_audit_context( int $user_id, string $tracker_type ) {
		$employees     = GHCA_ACD_Data_Provider::get_employees_for_current_view();
		$employee_data = null;
		foreach ( $employees as $emp ) {
			if ( (int) $emp['user_id'] === $user_id ) {
				$employee_data = $emp;
				break;
			}
		}

		if ( ! $employee_data ) {
			$user_info     = get_userdata( $user_id );
			$employee_data = array(
				'user_id' => $user_id,
				'name'    => $user_info ? $user_info->display_name : 'Unknown',
				'email'   => $user_info ? $user_info->user_email : '',
				'group'   => '',
			);
		}

		$mappings   = get_option( 'ghca_acd_audit_mapping', array() );
		$audit_data = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee_data, $tracker_type, $mappings );

		if ( empty( $audit_data ) ) {
			return new WP_Error( 'ghca_pdf_excluded', __( 'Employee is excluded from audits or has an ignored role.', 'ghca-acd' ) );
		}

		return array( 'audit_data' => $audit_data, 'employee_data' => $employee_data );
	}
```

`handle_download()` becomes: permission checks (unchanged, lines 12–21) → `load_libs()` → `resolve_audit_context()` → `generate_packet()` — with `wp_die( $result->get_error_message() )` on any `WP_Error`.

- [ ] **Step 3: Split `generate_packet()`**

Break the current private `generate_packet()` (lines 73–304) into four public pieces. **Move code verbatim** — the cover-sheet HTML (current lines 88–229) must not change by a single byte.

```php
	/** Document setup — current lines 75–86 move here unchanged. */
	public static function create_document( array $audit_data ): \setasign\Fpdi\Tcpdf\Fpdi {
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );
		$pdf->SetCreator( 'Gridhouse Compliance Dashboard' );
		$pdf->SetAuthor( 'Gridhouse Healthcare Academy' );
		$pdf->SetTitle( 'Compliance Audit Packet - ' . $audit_data['first_name'] . ' ' . $audit_data['last_name'] );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetAutoPageBreak( true, 15 );
		return $pdf;
	}

	/** Cover page — current lines 91–229 ($pdf->AddPage() through writeHTML) move here unchanged. */
	public static function render_cover( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $audit_data, string $tracker_type ): void {
		// ... moved verbatim ...
	}

	/**
	 * Imports every page of one local certificate PDF into the master document.
	 * This is the file-based core of current lines 275–287.
	 *
	 * @return bool false if the file is missing/malformed (caller counts skips).
	 */
	public static function append_certificate( \setasign\Fpdi\Tcpdf\Fpdi $pdf, string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		try {
			$page_count = $pdf->setSourceFile( $path );
			for ( $page_no = 1; $page_no <= $page_count; $page_no++ ) {
				$template_id = $pdf->importPage( $page_no );
				$size        = $pdf->getTemplateSize( $template_id );
				$orientation = $size['width'] > $size['height'] ? 'L' : 'P';
				$pdf->AddPage( $orientation, array( $size['width'], $size['height'] ) );
				$pdf->useTemplate( $template_id, 0, 0, $size['width'], $size['height'] );
			}
		} catch ( \Exception $e ) {
			return false;
		}
		return true;
	}

	/** Flat 0-indexed URL list for the job manifest. */
	public static function collect_certificate_urls( array $audit_data, int $user_id ): array {
		$urls = array();
		foreach ( ( $audit_data['raw_completed_courses'] ?? array() ) as $course ) {
			$cert_url = self::get_certificate_url( $user_id, (int) $course['course_id'] );
			if ( '' !== $cert_url ) {
				$urls[] = $cert_url;
			}
		}
		return $urls;
	}

	/** Current line 295 extracted. */
	public static function build_filename( array $audit_data ): string {
		return 'Audit_Packet_' . sanitize_title( $audit_data['first_name'] . '_' . $audit_data['last_name'] ) . '_' . wp_date( 'Y-m-d' ) . '.pdf';
	}
```

Rewrite `generate_packet()` (still private, still the sync path for now) to compose them — it fetches each URL with the existing cookie-forwarding `wp_remote_get`, writes to `wp_tempnam`, and calls `append_certificate()`:

```php
	private static function generate_packet( array $audit_data, array $employee_data, string $tracker_type ): void {
		$pdf = self::create_document( $audit_data );
		$pdf->AddPage();
		self::render_cover( $pdf, $audit_data, $tracker_type );

		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( array( 'name' => $name, 'value' => $value ) );
		}

		foreach ( self::collect_certificate_urls( $audit_data, (int) $employee_data['user_id'] ) as $cert_url ) {
			$response = wp_remote_get( $cert_url, array(
				'timeout'   => 15,
				'cookies'   => $cookies,
				'sslverify' => false,
			) );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}

			$pdf_content = wp_remote_retrieve_body( $response );
			if ( strpos( $pdf_content, '%PDF-' ) !== 0 ) {
				continue;
			}

			$temp_file = wp_tempnam( 'ghca_cert_' );
			if ( $temp_file ) {
				file_put_contents( $temp_file, $pdf_content );
				self::append_certificate( $pdf, $temp_file );
				@unlink( $temp_file );
			}
		}

		$filename = self::build_filename( $audit_data );

		if ( ob_get_length() ) {
			ob_clean();
		}

		$pdf->Output( $filename, 'D' );
		exit;
	}
```

Delete the now-dead inline copies of the moved code. Keep `get_certificate_url()` private and unchanged.

- [ ] **Step 4: Verify — lint + manual sync download**

Run: `php -l includes/class-audit-pdf.php`
Expected: `No syntax errors detected`.

Manual check (Laragon must be running): open the dashboard, open an employee drawer, click **Annual Packet**. The synchronous download must still produce the same PDF as before the refactor (cover sheet + certificates).

- [ ] **Step 5: Commit**

```bash
git add includes/class-audit-pdf.php
git commit -m "refactor: split audit PDF generator into composable pieces"
```

---

### Task 3: The four AJAX endpoints (init / fetch / merge / download)

**Files:**
- Modify: `includes/class-audit-pdf.php` (add handlers + hook registration in `init()`)

**Interfaces:**
- Consumes: everything Task 1 and Task 2 produce (exact signatures above).
- Produces (consumed by Task 4's JS):
  - `POST action=ghca_acd_pdf_init` `{nonce, user_id, tracker}` → `{job_id, total, employee}`
  - `POST action=ghca_acd_pdf_fetch` `{nonce, job_id, index}` → `{index}` on success; on any failure the job is deleted and a JSON error with an explicit message is returned (ABORT policy)
  - `POST action=ghca_acd_pdf_merge` `{nonce, job_id}` → `{download_url, total}`; aborts + deletes the job if any cert file is missing/unreadable
  - `GET  action=ghca_acd_pdf_download` `?job_id=…&nonce=…` → streams the PDF.

- [ ] **Step 1: Register the hooks**

In `GHCA_Audit_PDF::init()` add (keep the existing `admin_post` line for now — removed in Task 6):

```php
		add_action( 'wp_ajax_ghca_acd_pdf_init', array( __CLASS__, 'ajax_init_job' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_fetch', array( __CLASS__, 'ajax_fetch_cert' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_merge', array( __CLASS__, 'ajax_merge' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_download', array( __CLASS__, 'ajax_download' ) );
```

- [ ] **Step 2: Shared guard helper**

```php
	/**
	 * Common gate for all packet AJAX endpoints: nonce, role, and (when a
	 * job id is supplied) manifest ownership. Sends a JSON error and exits
	 * on failure; returns the manifest (or null when no job id expected).
	 */
	private static function guard_ajax( bool $expects_job ): ?array {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );

		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ), 403 );
		}

		if ( ! $expects_job ) {
			return null;
		}

		$job_id = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
		$job    = GHCA_Audit_PDF_Jobs::get_job( $job_id, get_current_user_id() );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 404 );
		}

		if ( ! GHCA_ACD_User_Report::can_view_user( (int) $job['user_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ), 403 );
		}

		$job['job_id'] = $job_id;
		return $job;
	}
```

- [ ] **Step 3: Init endpoint**

```php
	public static function ajax_init_job(): void {
		self::guard_ajax( false );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ), 403 );
		}

		$tracker = ( isset( $_POST['tracker'] ) && 'orientation' === $_POST['tracker'] ) ? 'orientation' : 'annual';

		$context = self::resolve_audit_context( $user_id, $tracker );
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		}

		GHCA_Audit_PDF_Jobs::gc();

		$urls   = self::collect_certificate_urls( $context['audit_data'], $user_id );
		$job_id = GHCA_Audit_PDF_Jobs::create_job(
			get_current_user_id(),
			$user_id,
			$tracker,
			$urls,
			self::build_filename( $context['audit_data'] )
		);

		wp_send_json_success( array(
			'job_id'   => $job_id,
			'total'    => count( $urls ),
			'employee' => $context['employee_data']['name'] ?? '',
		) );
	}
```

- [ ] **Step 4: Fetch endpoint** *(Decision Point 1 lives here + in merge — ABORT on any failure)*

```php
	public static function ajax_fetch_cert(): void {
		$job = self::guard_ajax( true );

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		if ( $index < 0 || $index >= count( $job['urls'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid certificate index.', 'ghca-acd' ) ), 400 );
		}

		// Forward the admin's cookies so LearnDash serves the certificate,
		// exactly as the old synchronous path did.
		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( array( 'name' => $name, 'value' => $value ) );
		}

		$response = wp_remote_get( $job['urls'][ $index ], array(
			'timeout'   => 25,
			'cookies'   => $cookies,
			'sslverify' => false,
		) );

		$pdf_content = ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 )
			? wp_remote_retrieve_body( $response )
			: '';

		$saved = false;
		if ( strpos( $pdf_content, '%PDF-' ) === 0 ) {
			$saved = (bool) file_put_contents( GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $index ), $pdf_content );
		}

		if ( ! $saved ) {
			// ABORT policy: a compliance packet must never be produced with a
			// certificate silently missing. Kill the whole job and tell the admin.
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d: 1-based certificate number */
					__( 'Certificate %d could not be retrieved. Packet generation was aborted — no partial packet was created. Please try again.', 'ghca-acd' ),
					$index + 1
				),
			) );
		}

		wp_send_json_success( array( 'index' => $index ) );
	}
```

- [ ] **Step 5: Merge endpoint**

```php
	public static function ajax_merge(): void {
		$job = self::guard_ajax( true );

		$libs = self::load_libs();
		if ( is_wp_error( $libs ) ) {
			wp_send_json_error( array( 'message' => $libs->get_error_message() ) );
		}

		$context = self::resolve_audit_context( (int) $job['user_id'], (string) $job['tracker'] );
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		}

		$pdf = self::create_document( $context['audit_data'] );
		$pdf->AddPage();
		self::render_cover( $pdf, $context['audit_data'], (string) $job['tracker'] );

		$total = count( $job['urls'] );
		for ( $i = 0; $i < $total; $i++ ) {
			if ( ! self::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $i ) ) ) {
				// ABORT policy (defense in depth): every fetched cert must merge.
				GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %d: 1-based certificate number */
						__( 'Certificate %d was missing or unreadable at merge time. Packet generation was aborted — no partial packet was created.', 'ghca-acd' ),
						$i + 1
					),
				) );
			}
		}

		$pdf->Output( GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] ), 'F' );

		wp_send_json_success( array(
			'download_url' => add_query_arg(
				array(
					'action' => 'ghca_acd_pdf_download',
					'job_id' => $job['job_id'],
					'nonce'  => wp_create_nonce( 'ghca_acd_table' ),
				),
				admin_url( 'admin-ajax.php' )
			),
			'total'        => $total,
		) );
	}
```

- [ ] **Step 6: Download endpoint (streams via PHP so the packet dir stays private)**

```php
	public static function ajax_download(): void {
		$job = self::guard_ajax( true );

		$path = GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This packet has expired. Please generate it again.', 'ghca-acd' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $job['filename'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		if ( ob_get_length() ) {
			ob_clean();
		}

		readfile( $path );
		exit;
	}
```

- [ ] **Step 7: Lint + endpoint smoke test**

Run: `php -l includes/class-audit-pdf.php`
Expected: `No syntax errors detected`.

Manual smoke test with curl is impractical (needs a logged-in session); instead verify from the browser console on the dashboard page:

```js
var p = new URLSearchParams({action:'ghca_acd_pdf_init', nonce: ghcaAcd.nonce, user_id: <SOME_ID>, tracker:'annual'});
fetch(ghcaAcd.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:p}).then(r=>r.json()).then(console.log)
```

Expected: `{success: true, data: {job_id: "<32 hex>", total: <n>, employee: "…"}}`, and `wp-content/uploads/ghca_compliance_temp/<job_id>/` exists with `index.html` + `.htaccess` in the parent. Then fire one fetch and the merge the same way and confirm `{fetched: true}` / a `download_url` that downloads the packet. Confirm a direct browser hit on `wp-content/uploads/ghca_compliance_packets/<job_id>_packet.pdf` returns **403** (htaccess working).

- [ ] **Step 8: Commit**

```bash
git add includes/class-audit-pdf.php
git commit -m "feat: add init/fetch/merge/download AJAX endpoints for packet builder"
```

---

### Task 4: Frontend — progress modal, CSS, JS driver

**Files:**
- Modify: `includes/class-ajax-handlers.php` (modal shell + hook)
- Modify: `assets/dashboard.css` (progress styles)
- Modify: `assets/dashboard.js` (driver module)
- Modify: `gridhouse-admin-compliance-dashboard.php` (localized strings)

**Interfaces:**
- Consumes: the four endpoints from Task 3 (payloads exactly as specified there).
- Produces: any element with `data-ghca-pdf-packet="<user_id>" data-tracker="annual|orientation"` triggers the flow (Task 5 adds the triggers).

- [ ] **Step 1: Modal shell**

In `GHCA_ACD_AJAX::init()` add:

```php
    add_action( 'wp_footer', array( __CLASS__, 'render_pdf_progress_modal' ) );
```

Add the method (2-space indent, matching this file), after `render_edit_records_modal()`:

```php
  /** Progress modal for the async packet builder (driven by initPdfPacket in dashboard.js). */
  public static function render_pdf_progress_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__pdf-modal" id="ghca-acd-pdf-modal" hidden aria-hidden="true">
      <div class="ghca-acd__pdf-modal-backdrop"></div>
      <div class="ghca-acd__pdf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-pdf-modal-title">
        <h2 id="ghca-acd-pdf-modal-title"><?php esc_html_e( 'Building Compliance Packet', 'ghca-acd' ); ?></h2>
        <p class="ghca-acd__pdf-modal-status" data-ghca-pdf-label aria-live="polite"><?php esc_html_e( 'Preparing…', 'ghca-acd' ); ?></p>
        <div class="ghca-acd__pdf-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-ghca-pdf-track>
          <div class="ghca-acd__pdf-progress-fill" data-ghca-pdf-bar></div>
        </div>
        <div class="ghca-acd__pdf-modal-footer">
          <button type="button" class="ghca-acd__cert-btn ghca-acd__cert-btn--close" data-ghca-pdf-cancel><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
        </div>
      </div>
    </div>
    <?php
  }
```

- [ ] **Step 2: CSS**

Append to `assets/dashboard.css`:

```css
/* --- Async PDF packet progress modal ------------------------------------ */
.ghca-acd__pdf-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; }
.ghca-acd__pdf-modal[hidden] { display: none; }
.ghca-acd__pdf-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.55); }
.ghca-acd__pdf-modal-dialog { position: relative; background: var(--ent-surface, #fff); border-radius: 12px; padding: 24px 28px; width: min(420px, calc(100vw - 32px)); box-shadow: 0 24px 64px rgba(15, 23, 42, 0.35); }
.ghca-acd__pdf-modal-dialog h2 { margin: 0 0 6px; font-size: 17px; }
.ghca-acd__pdf-modal-status { margin: 0 0 14px; font-size: 13px; color: var(--ent-muted, #64748b); }
.ghca-acd__pdf-progress { height: 10px; border-radius: 999px; background: var(--ent-border, #e2e8f0); overflow: hidden; }
.ghca-acd__pdf-progress-fill { height: 100%; width: 0%; border-radius: 999px; background: var(--ent-primary, #2563eb); transition: width 0.25s ease; }
.ghca-acd__pdf-progress-fill.is-error { background: var(--ent-danger, #dc2626); }
.ghca-acd__pdf-modal-footer { margin-top: 18px; text-align: right; }
```

- [ ] **Step 3: Localized strings**

In `gridhouse-admin-compliance-dashboard.php`, extend the `wp_localize_script` array (after `'certLoading'`):

```php
        'pdfPreparing'     => __( 'Preparing packet…', 'ghca-acd' ),
        /* translators: 1: current certificate number, 2: total certificates */
        'pdfFetching'      => __( 'Fetching certificate %1$s of %2$s…', 'ghca-acd' ),
        'pdfMerging'       => __( 'Merging documents…', 'ghca-acd' ),
        'pdfDone'          => __( 'Done! Starting download…', 'ghca-acd' ),
        'pdfError'         => __( 'Packet generation failed. No packet was created. Please try again.', 'ghca-acd' ),
```

- [ ] **Step 4: JS driver**

In `assets/dashboard.js`, add this function alongside the other `initX()` modules, and call `initPdfPacket();` where the other init functions are invoked (find the block that calls `initEmployeeDrawer()` and add it there):

```js
  function initPdfPacket() {
    var modal = document.getElementById('ghca-acd-pdf-modal');
    if (!modal) return;

    var bar = modal.querySelector('[data-ghca-pdf-bar]');
    var track = modal.querySelector('[data-ghca-pdf-track]');
    var label = modal.querySelector('[data-ghca-pdf-label]');
    var running = false;
    var cancelled = false;

    function t(key, fallback) {
      return (window.ghcaAcd && window.ghcaAcd[key]) || fallback;
    }

    function sprintf1(str, a, b) {
      return str.replace('%1$s', a).replace('%2$s', b).replace('%s', a);
    }

    function setProgress(pct, text) {
      bar.style.width = pct + '%';
      track.setAttribute('aria-valuenow', String(pct));
      label.textContent = text;
    }

    function openModal() {
      cancelled = false;
      bar.classList.remove('is-error');
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      running = false;
    }

    function failState(msg) {
      bar.classList.add('is-error');
      setProgress(100, msg || t('pdfError', 'Something went wrong. Please try again.'));
      running = false;
    }

    function post(params) {
      params.append('nonce', window.ghcaAcd.nonce);
      return fetch(window.ghcaAcd.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString()
      }).then(function(res) { return res.json(); });
    }

    function run(userId, tracker) {
      if (running) return;
      running = true;
      openModal();
      setProgress(3, t('pdfPreparing', 'Preparing packet…'));

      var initParams = new URLSearchParams();
      initParams.append('action', 'ghca_acd_pdf_init');
      initParams.append('user_id', userId);
      initParams.append('tracker', tracker);

      post(initParams).then(function(json) {
        if (cancelled) return;
        if (!json || !json.success || !json.data || !json.data.job_id) {
          failState(json && json.data && json.data.message);
          return;
        }

        var jobId = json.data.job_id;
        var total = parseInt(json.data.total, 10) || 0;

        function mergeJob() {
          setProgress(92, t('pdfMerging', 'Merging documents…'));
          var mp = new URLSearchParams();
          mp.append('action', 'ghca_acd_pdf_merge');
          mp.append('job_id', jobId);
          post(mp).then(function(mj) {
            if (cancelled) return;
            if (mj && mj.success && mj.data && mj.data.download_url) {
              setProgress(100, t('pdfDone', 'Done! Starting download…'));
              window.location.assign(mj.data.download_url);
              window.setTimeout(closeModal, 1500);
            } else {
              failState(mj && mj.data && mj.data.message);
            }
          }).catch(function() { failState(); });
        }

        function fetchNext(i) {
          if (cancelled) return;
          if (i >= total) { mergeJob(); return; }

          setProgress(5 + Math.round(((i) / total) * 85), sprintf1(t('pdfFetching', 'Fetching certificate %1$s of %2$s…'), String(i + 1), String(total)));

          var fp = new URLSearchParams();
          fp.append('action', 'ghca_acd_pdf_fetch');
          fp.append('job_id', jobId);
          fp.append('index', String(i));
          post(fp).then(function(fj) {
            if (cancelled) return;
            // ABORT policy: any fetch failure ends the job; the server has
            // already deleted the manifest and temp files at this point.
            if (!fj || !fj.success) { failState(fj && fj.data && fj.data.message); return; }
            fetchNext(i + 1);
          }).catch(function() { failState(); });
        }

        if (total === 0) { mergeJob(); } else { fetchNext(0); }
      }).catch(function() { failState(); });
    }

    document.addEventListener('click', function(e) {
      var trigger = e.target.closest('[data-ghca-pdf-packet]');
      if (trigger) {
        e.preventDefault();
        run(trigger.getAttribute('data-ghca-pdf-packet'), trigger.getAttribute('data-tracker') || 'annual');
        return;
      }
      if (e.target.closest('[data-ghca-pdf-cancel]')) {
        e.preventDefault();
        cancelled = true;
        closeModal();
      }
    });
  }
```

- [ ] **Step 5: Verify wiring**

Reload the dashboard page; in the console run:

```js
document.getElementById('ghca-acd-pdf-modal') !== null
```

Expected: `true`. No console errors on load.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ajax-handlers.php assets/dashboard.css assets/dashboard.js gridhouse-admin-compliance-dashboard.php
git commit -m "feat: add progress modal and JS driver for async packet builder"
```

---

### Task 5: Swap the four download triggers to async buttons

**Files:**
- Modify: `includes/class-ajax-handlers.php:243-246` (drawer, 2 links)
- Modify: `includes/class-audit-ui.php:98-99` (audit table, 2 links)

**Interfaces:**
- Consumes: `[data-ghca-pdf-packet]` click contract from Task 4.
- Produces: nothing new.

- [ ] **Step 1: Drawer buttons**

Replace the two `<a href="…admin-post.php?action=ghca_acd_download_packet…">` links inside `.ghca-acd__drawer-actions-row` in `ajax_get_employee_drawer()` with:

```php
      <div class="ghca-acd__drawer-actions-row">
        <button type="button" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary" style="background-color: var(--ent-dark); color: #ffffff;" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="orientation"><?php esc_html_e( 'Orientation Packet', 'ghca-acd' ); ?></button>
        <button type="button" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary" style="background-color: var(--ent-dark); color: #ffffff;" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="annual"><?php esc_html_e( 'Annual Packet', 'ghca-acd' ); ?></button>
      </div>
```

(User note 2026-07-06: explicit `color: #ffffff;` added because the label text was blending into the `var(--ent-dark)` background on the old links.)

- [ ] **Step 2: Audit-UI buttons**

Replace the two links at `includes/class-audit-ui.php:98-99` with:

```php
										<button type="button" class="ghca-acd__btn ghca-acd__btn--sm" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="orientation" title="<?php esc_attr_e( 'Download Orientation Packet', 'ghca-acd' ); ?>" style="padding: 4px 8px; margin-right: 4px;">Ori.</button>
										<button type="button" class="ghca-acd__btn ghca-acd__btn--sm" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="annual" title="<?php esc_attr_e( 'Download Annual Packet', 'ghca-acd' ); ?>" style="padding: 4px 8px;">Ann.</button>
```

- [ ] **Step 3: End-to-end verification**

With Laragon running, on the dashboard:
1. Open an employee drawer → click **Annual Packet**. Expected: modal opens, progress bar advances per certificate, "Merging documents…", then the browser downloads `Audit_Packet_<name>_<date>.pdf` and the modal closes.
2. Open the downloaded PDF: cover sheet page 1, certificates after.
3. Repeat from the audit table's **Ann.** button.
4. Click **Cancel** mid-fetch: modal closes, no download, no console errors.
5. Confirm `wp-content/uploads/ghca_compliance_temp/` gets a job folder during the run.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ajax-handlers.php includes/class-audit-ui.php
git commit -m "feat: switch packet downloads to async progress-modal flow"
```

---

### Task 6: Remove the legacy synchronous path + GC verification

**Files:**
- Modify: `includes/class-audit-pdf.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing — pure deletion.

- [ ] **Step 1: Delete the sync route**

In `includes/class-audit-pdf.php` remove:
- the `add_action( 'admin_post_ghca_acd_download_packet', … )` line from `init()`;
- the entire `handle_download()` method;
- the entire private `generate_packet()` method (its cert-fetch loop is now dead code — the async fetch/merge endpoints replaced it).

Keep: `load_libs`, `resolve_audit_context`, `create_document`, `render_cover`, `append_certificate`, `collect_certificate_urls`, `build_filename`, `get_certificate_url`, and the four AJAX handlers.

- [ ] **Step 2: Confirm no dangling references**

Run: `grep -rn "ghca_acd_download_packet\|generate_packet\|handle_download" includes/ assets/ --include="*.php" --include="*.js"`
Expected: no matches (Task 5 already removed the four link builders).

- [ ] **Step 3: Lint + regression pass**

Run: `php -l includes/class-audit-pdf.php` → `No syntax errors detected`.
Run: `php tests/test-audit-pdf-jobs.php` and `php tests/test-course-lifespans.php` → `ALL PASS`.
Manual: repeat Task 5 Step 3's checks 1–2 once more.

- [ ] **Step 4: GC verification**

Backdate a leftover job folder and confirm GC reaps it (Git Bash):

```bash
mkdir -p ../../uploads/ghca_compliance_temp/deadbeefdeadbeefdeadbeefdeadbeef
touch -d "2 hours ago" ../../uploads/ghca_compliance_temp/deadbeefdeadbeefdeadbeefdeadbeef
```

Then trigger any packet build from the UI (init runs `gc()`), and confirm the `deadbeef…` folder is gone while the in-flight job's folder remains.

- [ ] **Step 5: Commit**

```bash
git add includes/class-audit-pdf.php
git commit -m "refactor: remove legacy synchronous packet download path"
```

---

## Self-Review Notes

- **Spec coverage:** init/fetch/merge phases (Tasks 3–4), progress modal (Task 4), job manifest + temp dirs (Task 1), local merge (Task 3 Step 5), protected packet dir + PHP-streamed download with permission checks (Tasks 1 & 3), 1-hour GC hooked into init (Tasks 1 & 3), endpoints registered per blueprint (hooks live in `GHCA_Audit_PDF::init()` rather than `GHCA_ACD_AJAX` because all four delegate to PDF code — the modal shell does live in `class-ajax-handlers.php`).
- **Security:** job ids unguessable (128-bit random), ownership pinned to the creating admin, `can_view_user` re-checked on every phase (defends against scope changes mid-job), nonce on all endpoints including download, path traversal blocked by strict job-id regex before any filesystem use, uploads dirs deny-all.
- **Type consistency:** `create_job` returns `string`; `get_job` returns `array|WP_Error` and the guard injects `job_id` into the returned manifest — the fetch/merge/download handlers all read `$job['job_id']`, `$job['urls']`, `$job['user_id']`, `$job['tracker']`, `$job['filename']`, all set in Task 1's manifest.
- **Failure policy:** ABORT everywhere (user decision 2026-07-06) — fetch failure and merge-time missing file both call `GHCA_Audit_PDF_Jobs::delete_job()` and return an explicit JSON error; the frontend shows the server message in the error-state progress bar. No partial packets.
