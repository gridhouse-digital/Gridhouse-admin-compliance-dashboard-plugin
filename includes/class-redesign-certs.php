<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-course-title.php';

/**
 * Certificate viewer (Modal-CertViewer and Modal-CertViewer-States boards) and certificate ZIP downloads (slice 9).
 *
 * Viewer: the PDF comes from the existing authorized endpoint (GHCA_ACD_Certificate_Download: nonce, dashboard and
 * document permissions, employee scope, verified PDF only). The browser fetches it into memory and shows it from a
 * same-page blob URL in the browser's own PDF viewer. No certificate link, public URL or third-party viewer is used.
 * When the browser cannot show PDFs inline the viewer says so and offers the explicit download; it never starts a
 * download on its own.
 *
 * ZIP: the selected certificates are re-authorized on the server item by item (same checks as a single download).
 * If any item is not allowed, nothing is built and the answer does not say which records were refused. If any
 * allowed item cannot be fetched, nothing is built either (no partial archive), and the answer names those items.
 * Every entry, the close and the finished archive are checked; an incomplete archive is deleted and nothing is
 * offered. The archive is written to private storage (outside the web root, 0600) and downloaded once by its owner
 * through an authorized endpoint, which claims it atomically. Downloads are refused 15 minutes after the build;
 * files are deleted after download, and otherwise by WP-Cron, an hourly backstop or a request-end fallback (see
 * "Cleanup").
 */
final class GHCA_ACD_Redesign_Certs {

	const ZIP_ACTION      = 'ghca_acd_cert_zip';
	const ZIP_DL_ACTION   = 'ghca_acd_cert_zip_download';
	/** Prefix of each ZIP's own options row (not autoloaded); see "ZIP records". */
	const ZIP_RECORD         = 'ghca_acd_cert_zip_';
	/** Access expiry: how long a ZIP can be downloaded after it is built. */
	const ZIP_TTL            = 900;
	/** Physical deletion happens this long after access expiry (or after a claim that never finished). */
	const ZIP_DELETE_GRACE   = 300;
	/** The request-end fallback cleans up once a cleanup is this overdue (cron late, disabled or not scheduled). */
	const ZIP_FALLBACK_LAG   = 600;
	const ZIP_MAX_ITEMS      = 20;
	const ZIP_MAX_BYTES      = 104857600;
	const CLEANUP_HOOK       = 'ghca_acd_cert_zip_cleanup';
	/** Autoloaded int: when the next cleanup is due (absent when no ZIP exists). */
	const CLEANUP_DUE_OPTION = 'ghca_acd_certzip_cleanup_due';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ZIP_ACTION, array( __CLASS__, 'ajax_zip' ) );
		add_action( 'wp_ajax_' . self::ZIP_DL_ACTION, array( __CLASS__, 'ajax_zip_download' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cron_cleanup' ) );
		add_action( 'shutdown', array( __CLASS__, 'fallback_cleanup' ) );
	}

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** Who may preview, download and ZIP certificates: exactly who may use the single download endpoint. */
	public static function can_use(): bool {
		return is_user_logged_in() && GHCA_ACD_Roles::user_can_view() && GHCA_ACD_Roles::user_can_view_employee_documents();
	}

	/** Whether this server can build ZIP files (PHP zip extension). */
	public static function zip_available(): bool {
		return class_exists( 'ZipArchive' );
	}

	/* ---------------------------------------------------------------------
	 * Viewer
	 * ------------------------------------------------------------------- */

	public static function viewer_html(): string {
		if ( ! self::can_use() ) {
			return '';
		}
		$btn = static function ( string $attr, string $icon, string $label ): string {
			return '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" ' . $attr . ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . GHCA_ODP_UI::icon( $icon ) . '</button>';
		};
		$h  = '<div class="gh-mod-layer gh-cv-layer" id="ghca-acd-cert-viewer" data-ghca-ui="board" hidden aria-hidden="true" data-ghca-cv><div class="gh-scrim" data-ghca-cv-close></div>';
		$h .= '<div class="gh-modal gh-cv" role="dialog" aria-modal="true" aria-labelledby="ghca-cv-t" aria-describedby="ghca-cv-sub" data-ghca-cv-dialog>';
		$h .= '<div class="gh-modal-hd"><button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-cv-x-phone" data-ghca-cv-close aria-label="' . esc_attr__( 'Close certificate', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button><span class="gh-stat-ico gh-cv-ico" aria-hidden="true">' . self::icon( 'award' ) . '</span>';
		$h .= '<div class="gh-cv-hd-t"><h2 class="gh-h3" id="ghca-cv-t" data-ghca-cv-heading>' . esc_html__( 'Certificate', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary" id="ghca-cv-sub" data-ghca-cv-sub></p></div>';
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-cv-x" data-ghca-cv-close aria-label="' . esc_attr__( 'Close certificate', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';
		$h .= '<div class="gh-modal-bd"><div class="gh-cv-grid"><div class="gh-cv-view">';
		$h .= '<div class="gh-cv-bar" role="toolbar" aria-label="' . esc_attr__( 'Certificate preview controls', 'ghca-acd' ) . '" data-ghca-cv-bar>';
		$h .= $btn( 'data-ghca-cv-page="prev"', 'chev-left', __( 'Previous page', 'ghca-acd' ) ) . '<span class="gh-num" data-ghca-cv-pages aria-live="polite">1 / 1</span>' . $btn( 'data-ghca-cv-page="next"', 'chev-right', __( 'Next page', 'ghca-acd' ) );
		$h .= '<span class="gh-sep" aria-hidden="true"></span>' . $btn( 'data-ghca-cv-zoom="out"', 'zoom-out', __( 'Zoom out', 'ghca-acd' ) ) . '<span class="gh-cv-zoom" role="status" data-ghca-cv-zoomval>100%</span>' . $btn( 'data-ghca-cv-zoom="in"', 'plus', __( 'Zoom in', 'ghca-acd' ) ) . $btn( 'data-ghca-cv-zoom="fit"', 'fit', __( 'Fit to width', 'ghca-acd' ) );
		$h .= '<span class="gh-cv-grow" aria-hidden="true"></span>' . $btn( 'data-ghca-cv-print', 'printer', __( 'Print certificate', 'ghca-acd' ) );
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-cv-newtab>' . self::icon( 'external', 'sm' ) . esc_html__( 'Open in new tab', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html__( '(opens the PDF in a new tab)', 'ghca-acd' ) . '</span></button></div>';
		$h .= '<div class="gh-cv-stage" data-ghca-cv-stage>';
		// Loading: header, details and actions show at once; only the page area waits.
		$h .= '<div class="gh-cv-page gh-cv-loading" aria-busy="true" data-ghca-cv-pane="loading"><span class="gh-bul-skel" style="width:40%;height:18px"></span><span class="gh-bul-skel" style="width:60%;height:26px"></span><span class="gh-bul-skel" style="width:30%"></span><span class="gh-bul-skel" style="width:50%;height:20px"></span><span class="gh-sr" role="status">' . esc_html__( 'Loading certificate…', 'ghca-acd' ) . '</span></div>';
		$h .= '<iframe class="gh-cv-frame" title="' . esc_attr__( 'Certificate PDF', 'ghca-acd' ) . '" hidden data-ghca-cv-pane="ready" data-ghca-cv-frame></iframe>';
		$h .= self::msg( 'blocked', 'file', '', __( 'This PDF can’t be shown here', 'ghca-acd' ), __( 'Your browser does not show PDFs inside the page. The certificate is fine — download it to open it on your device.', 'ghca-acd' ), '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-cv-download>' . self::icon( 'download' ) . esc_html__( 'Download PDF', 'ghca-acd' ) . '</button>' );
		$h .= self::msg( 'pending', 'clock', 'warning', __( 'Certificate not generated yet', 'ghca-acd' ), __( 'The course is marked complete, but LearnDash hasn’t created the certificate. Re-save the completion in Edit records, or check the course’s certificate setting.', 'ghca-acd' ), '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-cv-to-employee>' . self::icon( 'user' ) . esc_html__( 'Open employee', 'ghca-acd' ) . '</button>' );
		$h .= self::msg( 'unavailable', 'file-x', 'danger', __( 'This certificate isn’t available', 'ghca-acd' ), __( 'LearnDash did not return a certificate PDF for this completion. Nothing was downloaded.', 'ghca-acd' ), '' );
		$h .= self::msg( 'denied', 'lock', 'danger', __( 'You can’t open this certificate', 'ghca-acd' ), __( 'Your account doesn’t have access to this employee’s documents, or your session has expired. Reload the page and try again.', 'ghca-acd' ), '' );
		$h .= self::msg( 'error', 'alert', 'danger', __( 'The certificate couldn’t be loaded', 'ghca-acd' ), __( 'The connection failed or the server answered with something other than a PDF. Nothing was downloaded.', 'ghca-acd' ), '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-cv-retry>' . self::icon( 'refresh' ) . esc_html__( 'Try again', 'ghca-acd' ) . '</button>' );
		$h .= '</div></div>';
		$h .= '<aside class="gh-cv-side" aria-label="' . esc_attr__( 'Certificate details', 'ghca-acd' ) . '"><div class="gh-cv-side-hd"><h3>' . esc_html__( 'Certificate details', 'ghca-acd' ) . '</h3><span class="gh-st" data-ghca-cv-chip></span></div>';
		$h .= '<dl class="gh-cv-dl" data-ghca-cv-dl></dl>';
		$h .= '<p class="gh-small gh-muted gh-cv-note">' . self::icon( 'info', 'sm' ) . esc_html__( 'Download saves the original PDF exactly as it was issued.', 'ghca-acd' ) . '</p></aside>';
		$h .= '</div></div>';
		$h .= '<div class="gh-modal-ft gh-cv-ft"><p class="gh-small gh-cv-ft-msg" role="status" aria-live="polite" data-ghca-cv-msg></p><button type="button" class="gh-btn gh-btn--secondary gh-cv-close-btn" data-ghca-cv-close>' . esc_html__( 'Close', 'ghca-acd' ) . '</button>';
		$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-cv-newtab-ft" data-ghca-cv-newtab>' . self::icon( 'external' ) . esc_html__( 'Open in new tab', 'ghca-acd' ) . '</button>';
		$h .= '<button type="button" class="gh-btn gh-btn--primary" data-ghca-cv-download>' . self::icon( 'download' ) . esc_html__( 'Download PDF', 'ghca-acd' ) . '</button></div>';
		return $h . '</div></div>';
	}

	private static function msg( string $state, string $icon, string $tone, string $title, string $text, string $action ): string {
		return '<div class="gh-cv-msg" hidden data-ghca-cv-pane="' . esc_attr( $state ) . '" role="' . ( 'error' === $state || 'denied' === $state || 'unavailable' === $state ? 'alert' : 'status' ) . '"><span class="gh-cv-msg-ico' . ( '' !== $tone ? ' gh-cv-msg-ico--' . esc_attr( $tone ) : '' ) . '" aria-hidden="true">' . self::icon( $icon, 'lg' ) . '</span><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p>' . $action . '</div>';
	}

	/**
	 * Data attributes a viewer trigger carries. Display values only; the PDF itself is always re-authorized.
	 *
	 * @param array<string,string> $d
	 */
	public static function trigger_attrs( int $user_id, int $course_id, array $d ): string {
		$a = ' data-ghca-cv-open data-ghca-cv-user="' . esc_attr( (string) $user_id ) . '" data-ghca-cv-course="' . esc_attr( (string) $course_id ) . '"';
		if ( 'pending' !== ( $d['state'] ?? 'issued' ) ) {
			$a .= ' data-ghca-cv-url="' . esc_url( GHCA_ACD_Certificate_Download::download_url( $user_id, $course_id ) ) . '"';
		}
		foreach ( array( 'state', 'title', 'employee', 'group', 'completed', 'expires', 'source', 'file' ) as $k ) {
			if ( isset( $d[ $k ] ) && '' !== (string) $d[ $k ] ) {
				$a .= ' data-ghca-cv-' . $k . '="' . esc_attr( (string) $d[ $k ] ) . '"';
			}
		}
		return $a;
	}

	/** A readable, distinct file name part: lower case letters, digits and hyphens. */
	public static function slug( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		$s = strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', $s ), '-' ) );
		return '' !== $s ? substr( $s, 0, 60 ) : 'certificate';
	}

	/* ---------------------------------------------------------------------
	 * ZIP
	 * ------------------------------------------------------------------- */

	/**
	 * Parse "user:course" items. Returns null when the list itself is malformed.
	 *
	 * @param mixed $raw
	 * @return array<int,array{0:int,1:int}>|null
	 */
	public static function parse_items( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) || ! preg_match( '/^(\d{1,10}):(\d{1,10})$/', $item, $m ) ) {
				return null;
			}
			$key = (int) $m[1] . ':' . (int) $m[2];
			if ( (int) $m[1] <= 0 || (int) $m[2] <= 0 ) {
				return null;
			}
			$out[ $key ] = array( (int) $m[1], (int) $m[2] );
		}
		return array_values( $out );
	}

	/**
	 * Build a private ZIP for the owner. Pure enough to test: fetch and storage are injectable.
	 *
	 * Every entry write, the close and the finished archive are checked: the archive is reopened, its entry count
	 * must match and every entry must hold exactly the bytes that were added. Anything else deletes the incomplete
	 * archive and returns an error; no record is stored, so nothing can be downloaded from it.
	 *
	 * @param array<int,array{0:int,1:int}> $items
	 * @param callable|null                 $fetch  fn(int $user, int $course): array{status:int,body:string}
	 * @return array{ok:bool,message:string,token?:string,count?:int,bytes?:int,name?:string,expires?:int,failed?:array<int,string>,files?:array<int,string>,cleanup_scheduled?:bool}
	 */
	public static function build_zip( array $items, int $owner, ?callable $fetch = null, string $dir = '', int $now = 0 ): array {
		$now = $now > 0 ? $now : time();
		if ( ! self::zip_available() ) {
			return array( 'ok' => false, 'message' => __( 'ZIP downloads aren’t available on this server (the PHP zip extension is missing). Download the certificates one at a time.', 'ghca-acd' ) );
		}
		if ( ! $items ) {
			return array( 'ok' => false, 'message' => __( 'Select at least one issued certificate.', 'ghca-acd' ) );
		}
		if ( count( $items ) > self::ZIP_MAX_ITEMS ) {
			/* translators: %d: maximum certificates per ZIP */
			return array( 'ok' => false, 'message' => sprintf( __( 'Select up to %d certificates per ZIP. Nothing was downloaded.', 'ghca-acd' ), self::ZIP_MAX_ITEMS ) );
		}
		// 1. Authorization for every item, before anything is fetched. A refusal does not say which item.
		$refused = 0;
		foreach ( $items as $it ) {
			if ( ! GHCA_ACD_User_Report::can_view_user( (int) $it[0] ) ) {
				++$refused;
			}
		}
		if ( $refused > 0 ) {
			return array( 'ok' => false, 'message' => sprintf( /* translators: %d: refused certificates */ _n( '%d selected certificate is not available to you. Nothing was downloaded; clear the selection and try again.', '%d selected certificates are not available to you. Nothing was downloaded; clear the selection and try again.', $refused, 'ghca-acd' ), $refused ) );
		}
		// 2. Fetch every PDF the same way as a single download. Any failure: no archive, and say which.
		$fetch  = $fetch ?: array( 'GHCA_ACD_Certificate_Download', 'fetch_pdf' );
		$files  = array();
		$failed = array();
		$total  = 0;
		$names  = array();
		foreach ( $items as $it ) {
			list( $uid, $cid ) = $it;
			$label  = GHCA_ACD_Data_Provider::get_user_full_name( $uid ) . ' — ' . GHCA_ACD_Course_Title::format( self::course_title( $cid ) );
			$result = call_user_func( $fetch, $uid, $cid );
			if ( 200 !== (int) ( $result['status'] ?? 0 ) || 0 !== strpos( (string) ( $result['body'] ?? '' ), '%PDF-' ) ) {
				$failed[] = $label;
				continue;
			}
			$total += strlen( (string) $result['body'] );
			if ( $total > self::ZIP_MAX_BYTES ) {
				return array( 'ok' => false, 'message' => __( 'These certificates are too large for one ZIP. Nothing was downloaded; select fewer.', 'ghca-acd' ) );
			}
			// Employee and course; distinct even when two names collide.
			$base = self::slug( GHCA_ACD_Data_Provider::get_user_full_name( $uid ) ) . '_' . self::slug( GHCA_ACD_Course_Title::format( self::course_title( $cid ) ) );
			$name = $base;
			for ( $n = 2; isset( $names[ $name ] ); $n++ ) {
				$name = $base . '-' . $n;
			}
			$names[ $name ] = true;
			$files[ $name . '.pdf' ] = (string) $result['body'];
		}
		if ( $failed ) {
			return array( 'ok' => false, 'failed' => $failed, 'message' => sprintf( /* translators: %s: list of certificates */ _n( 'This certificate could not be fetched: %s. Nothing was downloaded; download it on its own or try again.', 'These certificates could not be fetched: %s. Nothing was downloaded; download them on their own or try again.', count( $failed ), 'ghca-acd' ), implode( '; ', $failed ) ) );
		}
		// 3. Write the archive privately, checking every step.
		try {
			$dir = '' !== $dir ? $dir : self::zip_dir();
		} catch ( RuntimeException $e ) {
			return array( 'ok' => false, 'message' => __( 'Private storage for ZIP files is unavailable on this server. Nothing was downloaded.', 'ghca-acd' ) );
		}
		self::cleanup( $dir, $now );
		$token   = bin2hex( random_bytes( 16 ) );
		$path    = $dir . '/' . $token . '.zip';
		$written = __( 'The ZIP file could not be written. Nothing was downloaded; try again.', 'ghca-acd' );
		$zip     = self::new_archive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
			self::discard( $path );
			return array( 'ok' => false, 'message' => __( 'The ZIP file could not be created. Nothing was downloaded.', 'ghca-acd' ) );
		}
		foreach ( $files as $fname => $bytes ) {
			if ( true !== $zip->addFromString( $fname, $bytes ) ) {
				self::abandon( $zip );
				self::discard( $path );
				return array( 'ok' => false, 'message' => $written );
			}
		}
		if ( true !== $zip->close() ) {
			self::discard( $path );
			return array( 'ok' => false, 'message' => $written );
		}
		if ( ! self::verify_archive( $path, $files ) ) {
			self::discard( $path );
			return array( 'ok' => false, 'message' => __( 'The ZIP file failed its check after writing (an entry was missing or different). Nothing was downloaded; try again.', 'ghca-acd' ) );
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		clearstatcache( true, $path );
		$name = 'certificates-' . gmdate( 'Y-m-d-His', $now ) . '-' . count( $files ) . '.zip';
		$job  = array( 'owner' => $owner, 'path' => $path, 'name' => $name, 'created' => $now, 'expires' => $now + self::ZIP_TTL, 'count' => count( $files ), 'bytes' => (int) filesize( $path ) );
		if ( ! self::insert_job( $token, $job ) ) {
			self::discard( $path );
			return array( 'ok' => false, 'message' => __( 'The ZIP file could not be registered for download. Nothing was downloaded; try again.', 'ghca-acd' ) );
		}
		$scheduled = self::schedule_cleanup( $token, $job['expires'] + self::ZIP_DELETE_GRACE );
		return array( 'ok' => true, 'message' => '', 'token' => $token, 'count' => count( $files ), 'bytes' => $job['bytes'], 'name' => $name, 'expires' => $job['expires'], 'files' => array_keys( $files ), 'cleanup_scheduled' => $scheduled );
	}

	/** The private folder that holds ZIP files (0700, deny rules; outside the web root). */
	public static function zip_dir(): string {
		return GHCA_Audit_PDF_Jobs::private_subdir( 'cert_zips' );
	}

	/**
	 * Tests only: replace how archives are created (to simulate failed writes and closes). Pass null to restore.
	 *
	 * @internal
	 */
	public static function use_archive_factory( ?callable $factory ): void {
		self::$archive_factory = $factory;
	}

	/** @var callable|null */
	private static $archive_factory = null;

	private static function new_archive(): ZipArchive {
		if ( null !== self::$archive_factory ) {
			$zip = call_user_func( self::$archive_factory );
			if ( $zip instanceof ZipArchive ) {
				return $zip;
			}
		}
		return new ZipArchive();
	}

	/** Closes an archive whose writing failed without saving it. */
	private static function abandon( ZipArchive $zip ): void {
		try {
			$zip->unchangeAll();
			@$zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the file is removed next.
		}
	}

	/** Deletes an archive and any temporary file ZipArchive left beside it. */
	private static function discard( string $path ): void {
		foreach ( array_merge( array( $path ), (array) glob( $path . '.*' ) ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		clearstatcache();
	}

	/**
	 * Reopens the finished archive with consistency checks and compares it with what was meant to be in it.
	 *
	 * @param array<string,string> $files
	 */
	private static function verify_archive( string $path, array $files ): bool {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || filesize( $path ) <= 0 ) {
			return false;
		}
		$check = new ZipArchive();
		if ( true !== $check->open( $path, ZipArchive::CHECKCONS ) ) {
			return false;
		}
		$ok = $check->numFiles === count( $files );
		foreach ( $files as $fname => $bytes ) {
			if ( ! $ok ) {
				break;
			}
			$ok = $check->getFromName( $fname ) === $bytes;
		}
		$check->close();
		return $ok;
	}

	/** The stored course title as plain text (no "Private:" prefix, no HTML entities). */
	private static function course_title( int $course_id ): string {
		$t = function_exists( 'get_post_field' ) ? (string) get_post_field( 'post_title', $course_id, 'raw' ) : '';
		return '' !== $t ? wp_specialchars_decode( $t, ENT_QUOTES ) : (string) $course_id;
	}

	/* ---------------------------------------------------------------------
	 * ZIP records
	 *
	 * Each ZIP has one row in the options table (not autoloaded), read and written with $wpdb directly. A transient
	 * is not used: with a persistent object cache it never reaches the database, and neither it nor the object cache
	 * offers an atomic "take it if nobody else has". The download claims the row with a compare-and-swap UPDATE, so
	 * of any number of simultaneous requests exactly one is served.
	 * ------------------------------------------------------------------- */

	private static function record_key( string $token ): string {
		return self::ZIP_RECORD . $token;
	}

	/**
	 * The stored record for a token, with its raw value (for the compare-and-swap). Null when there is none.
	 *
	 * @return array{job:array<string,mixed>,raw:string}|null
	 */
	public static function job( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::record_key( $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$job = json_decode( $raw, true );
		return is_array( $job ) && isset( $job['owner'], $job['path'], $job['expires'] ) ? array( 'job' => $job, 'raw' => $raw ) : null;
	}

	/** @param array<string,mixed> $job */
	private static function insert_job( string $token, array $job ): bool {
		global $wpdb;
		return 1 === $wpdb->insert( $wpdb->options, array( 'option_name' => self::record_key( $token ), 'option_value' => (string) wp_json_encode( $job ), 'autoload' => 'no' ), array( '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function delete_job( string $token ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::record_key( $token ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomically claims an unclaimed record for $user. Returns 1 when this request won, 0 when another request
	 * changed or removed it first, and -1 when the database could not answer.
	 */
	private static function claim_job( string $token, string $raw, array $job, int $user, int $now ): int {
		global $wpdb;
		$claimed = $job + array( 'claimed_by' => $user, 'claimed_at' => $now );
		$n       = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) wp_json_encode( $claimed ), self::record_key( $token ), $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $n ) {
			return -1;
		}
		return 1 === (int) $n ? 1 : 0;
	}

	/** Whether $path is this token's archive inside the ZIP folder (never follow a record anywhere else). */
	private static function path_ok( string $path, string $token, string $dir ): bool {
		return basename( $path ) === $token . '.zip' && wp_normalize_path( dirname( $path ) ) === wp_normalize_path( $dir );
	}

	/* ---------------------------------------------------------------------
	 * Cleanup
	 *
	 * Access expiry and physical deletion are separate. A ZIP can be downloaded for ZIP_TTL (15 minutes) after it is
	 * built; after that the download is refused whatever is on disk. Files and records are physically deleted:
	 *   - straight after a successful download;
	 *   - by a single WP-Cron event per ZIP, due ZIP_DELETE_GRACE (5 minutes) after its access expiry;
	 *   - by an hourly WP-Cron backstop, scheduled while any ZIP exists and removed when none is left;
	 *   - by a fallback that runs at the end of any request once a cleanup is overdue by ZIP_FALLBACK_LAG, which
	 *     covers failed scheduling, DISABLE_WP_CRON without a system cron, and quiet sites;
	 *   - before every new ZIP is built, and when an expired ZIP is requested.
	 * WP-Cron only runs when someone loads a page (or a system cron calls wp-cron.php), so the scheduled times are
	 * "no earlier than", not exact; on a site with no traffic deletion waits for the next request. Access expiry
	 * does not depend on cron.
	 * ------------------------------------------------------------------- */

	/** Schedules this ZIP's cleanup; records when one is due so the fallback can take over. */
	private static function schedule_cleanup( string $token, int $when ): bool {
		$due = (int) get_option( self::CLEANUP_DUE_OPTION, 0 );
		update_option( self::CLEANUP_DUE_OPTION, $due > 0 ? min( $due, $when ) : $when, true );
		$single = function_exists( 'wp_schedule_single_event' ) ? wp_schedule_single_event( $when, self::CLEANUP_HOOK, array( $token ), true ) : false;
		$single = true === $single;
		$backup = (bool) wp_next_scheduled( self::CLEANUP_HOOK );
		if ( ! $backup ) {
			$backup = true === wp_schedule_event( $when, 'hourly', self::CLEANUP_HOOK, array(), true );
		}
		if ( ! $single && ! $backup ) {
			// Both failed: the request-end fallback will clean up; say so for the site's error log.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'GHCA: could not schedule certificate ZIP cleanup; the request-end fallback will remove it.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $single || $backup;
	}

	/**
	 * Physically removes expired, claimed-and-abandoned and orphaned ZIP files and records. Safe to run any time:
	 * a ZIP that may still be downloaded is never touched.
	 *
	 * @return array{files:int,records:int,remaining:int}
	 */
	public static function cleanup( string $dir = '', int $now = 0 ): array {
		global $wpdb;
		$now = $now > 0 ? $now : time();
		$out = array( 'files' => 0, 'records' => 0, 'remaining' => 0 );
		try {
			$dir = '' !== $dir ? $dir : self::zip_dir();
		} catch ( RuntimeException $e ) {
			return $out;
		}
		$next = 0;
		// Records.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::ZIP_RECORD ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$token = substr( (string) $row->option_name, strlen( self::ZIP_RECORD ) );
			$job   = json_decode( (string) $row->option_value, true );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
				continue;
			}
			$delete_at = is_array( $job ) ? (int) ( $job['expires'] ?? 0 ) + self::ZIP_DELETE_GRACE : 0;
			if ( is_array( $job ) && ! empty( $job['claimed_at'] ) ) {
				$delete_at = min( $delete_at, (int) $job['claimed_at'] + self::ZIP_DELETE_GRACE );
			}
			if ( $delete_at > $now ) {
				++$out['remaining'];
				$next = $next > 0 ? min( $next, $delete_at ) : $delete_at;
				continue;
			}
			$path = is_array( $job ) ? (string) ( $job['path'] ?? '' ) : '';
			if ( '' !== $path && self::path_ok( $path, $token, $dir ) && is_file( $path ) ) {
				self::discard( $path );
				++$out['files'];
			}
			self::delete_job( $token );
			++$out['records'];
		}
		// Files on disk with no live record (abandoned builds, temporary files, records lost): by age only, so a
		// ZIP being written right now is never removed.
		$cutoff = $now - self::ZIP_TTL - self::ZIP_DELETE_GRACE;
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			$base = basename( (string) $file );
			if ( ! preg_match( '/^[a-f0-9]{32}\.zip(\.[A-Za-z0-9]+)?$/', $base ) || ! is_file( $file ) ) {
				continue; // Never touch the folder's deny files or anything else.
			}
			$mtime = (int) filemtime( $file );
			if ( $mtime < $cutoff ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$out['files'];
			} else {
				++$out['remaining'];
				$at   = $mtime + self::ZIP_TTL + self::ZIP_DELETE_GRACE;
				$next = $next > 0 ? min( $next, $at ) : $at;
			}
		}
		clearstatcache();
		if ( $next > 0 ) {
			update_option( self::CLEANUP_DUE_OPTION, $next, true );
		} else {
			delete_option( self::CLEANUP_DUE_OPTION );
			wp_unschedule_hook( self::CLEANUP_HOOK );
		}
		return $out;
	}

	/** WP-Cron callback (the per-ZIP event passes its token; the backstop passes nothing). */
	public static function cron_cleanup(): void {
		self::cleanup();
	}

	/** Request-end fallback: runs only when a cleanup is overdue (cron late, disabled or never scheduled). */
	public static function fallback_cleanup(): void {
		$due = (int) get_option( self::CLEANUP_DUE_OPTION, 0 );
		if ( $due > 0 && time() >= $due + self::ZIP_FALLBACK_LAG ) {
			self::cleanup();
		}
	}

	/** Plugin deactivation: nothing can be downloaded any more, so remove every ZIP and record now. */
	public static function deactivate(): void {
		global $wpdb;
		wp_unschedule_hook( self::CLEANUP_HOOK );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::ZIP_RECORD ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( self::CLEANUP_DUE_OPTION );
		try {
			foreach ( (array) glob( self::zip_dir() . '/*' ) as $file ) {
				if ( preg_match( '/^[a-f0-9]{32}\.zip(\.[A-Za-z0-9]+)?$/', basename( (string) $file ) ) && is_file( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- no folder, nothing to remove.
		}
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	public static function zip_download_url( string $token ): string {
		return add_query_arg( array( 'action' => self::ZIP_DL_ACTION, 'token' => $token, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ), admin_url( 'admin-ajax.php' ) );
	}

	public static function ajax_zip(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		if ( ! self::can_use() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to download certificates.', 'ghca-acd' ) ), 403 );
		}
		$items = self::parse_items( isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by parse_items().
		if ( null === $items ) {
			wp_send_json_error( array( 'message' => __( 'The selection could not be read. Nothing was downloaded.', 'ghca-acd' ) ), 400 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$r = self::build_zip( $items, get_current_user_id() );
		if ( ! $r['ok'] ) {
			wp_send_json_error( array( 'message' => $r['message'], 'failed' => $r['failed'] ?? array() ) );
		}
		wp_send_json_success( array(
			'download_url' => self::zip_download_url( (string) $r['token'] ),
			'count'        => $r['count'],
			'bytes'        => $r['bytes'],
			'name'         => $r['name'],
			'expires'      => $r['expires'],
		) );
	}

	/** @return array{status:int,headers:array<string,string>,body:string} */
	private static function json_answer( int $status, string $message ): array {
		return array( 'status' => $status, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff' ), 'body' => (string) wp_json_encode( array( 'success' => false, 'data' => array( 'message' => $message ) ) ) );
	}

	/**
	 * Decide the ZIP download response. Owner only and single use: the record is claimed atomically before the file
	 * is read, so concurrent requests cannot both be served, and a request by anyone else never consumes it. A file
	 * that cannot be read completely is an error (500), never an empty or partial 200.
	 *
	 * @param array<string,mixed> $request
	 * @param callable|null       $read  Tests only: fn(string $path): string|false (default file_get_contents).
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function zip_download( array $request, int $now = 0, ?callable $read = null ): array {
		$now   = $now > 0 ? $now : time();
		$gone  = self::json_answer( 410, __( 'This ZIP is no longer available. It is deleted after one download or after 15 minutes. Select the certificates and prepare it again.', 'ghca-acd' ) );
		$nonce = isset( $request['nonce'] ) ? (string) $request['nonce'] : '';
		if ( ! is_user_logged_in() || '' === $nonce || ! wp_verify_nonce( $nonce, 'ghca_acd_table' ) || ! self::can_use() ) {
			return self::json_answer( 403, __( 'Permission denied.', 'ghca-acd' ) );
		}
		$token = isset( $request['token'] ) ? (string) $request['token'] : '';
		$rec   = self::job( $token );
		$user  = get_current_user_id();
		// Someone else's ZIP looks exactly like a missing one, and is left alone for its owner.
		if ( null === $rec || (int) $rec['job']['owner'] !== $user || ! empty( $rec['job']['claimed_at'] ) ) {
			return $gone;
		}
		$job = $rec['job'];
		try {
			$dir = self::zip_dir();
		} catch ( RuntimeException $e ) {
			return self::json_answer( 500, __( 'Private storage for ZIP files is unavailable on this server. Nothing was downloaded.', 'ghca-acd' ) );
		}
		$path = (string) $job['path'];
		// Access expiry is checked here, whatever cron has or has not done.
		if ( (int) $job['expires'] < $now || ! self::path_ok( $path, $token, $dir ) || ! is_file( $path ) ) {
			if ( 1 === self::claim_job( $token, $rec['raw'], $job, $user, $now ) ) {
				if ( self::path_ok( $path, $token, $dir ) ) {
					self::discard( $path );
				}
				self::delete_job( $token );
			}
			return $gone;
		}
		$claim = self::claim_job( $token, $rec['raw'], $job, $user, $now );
		if ( -1 === $claim ) {
			return self::json_answer( 503, __( 'The ZIP could not be prepared for download just now. Try the download again.', 'ghca-acd' ) );
		}
		if ( 1 !== $claim ) {
			return $gone; // Another request claimed it first.
		}
		// This request alone now owns the file.
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : -1;
		$body = call_user_func( $read ?: 'file_get_contents', $path );
		self::discard( $path );
		self::delete_job( $token );
		if ( ! is_string( $body ) || $size <= 0 || strlen( $body ) !== $size || 0 !== strpos( $body, 'PK' ) ) {
			return self::json_answer( 500, __( 'The ZIP file could not be read on the server. Nothing was downloaded; select the certificates and prepare it again.', 'ghca-acd' ) );
		}
		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => 'application/zip',
				'Content-Disposition'    => 'attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $job['name'] ) . '"',
				'Content-Length'         => (string) strlen( $body ),
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $body,
		);
	}

	public static function ajax_zip_download(): void {
		$r = self::zip_download( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in zip_download().
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( $r['status'] );
		foreach ( $r['headers'] as $k => $v ) {
			header( $k . ': ' . $v );
		}
		echo $r['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ZIP bytes or a fixed JSON message.
		exit;
	}
}
