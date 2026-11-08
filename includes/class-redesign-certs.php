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
 * The archive is written to private storage (outside the web root, 0600), downloaded once by its owner through an
 * authorized endpoint, and deleted after download or after 15 minutes.
 */
final class GHCA_ACD_Redesign_Certs {

	const ZIP_ACTION      = 'ghca_acd_cert_zip';
	const ZIP_DL_ACTION   = 'ghca_acd_cert_zip_download';
	const ZIP_TRANSIENT   = 'ghca_acd_cert_zip_';
	const ZIP_TTL         = 900;
	const ZIP_MAX_ITEMS   = 20;
	const ZIP_MAX_BYTES   = 104857600;

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ZIP_ACTION, array( __CLASS__, 'ajax_zip' ) );
		add_action( 'wp_ajax_' . self::ZIP_DL_ACTION, array( __CLASS__, 'ajax_zip_download' ) );
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
	 * @param array<int,array{0:int,1:int}> $items
	 * @param callable|null                 $fetch  fn(int $user, int $course): array{status:int,body:string}
	 * @return array{ok:bool,message:string,token?:string,count?:int,bytes?:int,name?:string,expires?:int,failed?:array<int,string>}
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
			// Employee, course and completion date; distinct even when two names collide.
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
		// 3. Write the archive privately.
		try {
			$dir = '' !== $dir ? $dir : GHCA_Audit_PDF_Jobs::private_subdir( 'cert_zips' );
		} catch ( RuntimeException $e ) {
			return array( 'ok' => false, 'message' => __( 'Private storage for ZIP files is unavailable on this server. Nothing was downloaded.', 'ghca-acd' ) );
		}
		self::sweep( $dir, $now );
		$token = bin2hex( random_bytes( 16 ) );
		$path  = $dir . '/' . $token . '.zip';
		$zip   = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
			return array( 'ok' => false, 'message' => __( 'The ZIP file could not be created. Nothing was downloaded.', 'ghca-acd' ) );
		}
		foreach ( $files as $fname => $bytes ) {
			$zip->addFromString( $fname, $bytes );
		}
		if ( true !== $zip->close() || ! is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'ok' => false, 'message' => __( 'The ZIP file could not be written. Nothing was downloaded.', 'ghca-acd' ) );
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		clearstatcache( true, $path );
		$name = 'certificates-' . gmdate( 'Y-m-d-His', $now ) . '-' . count( $files ) . '.zip';
		set_transient( self::ZIP_TRANSIENT . $token, array( 'owner' => $owner, 'path' => $path, 'name' => $name, 'created' => $now, 'count' => count( $files ) ), self::ZIP_TTL );
		return array( 'ok' => true, 'message' => '', 'token' => $token, 'count' => count( $files ), 'bytes' => (int) filesize( $path ), 'name' => $name, 'expires' => $now + self::ZIP_TTL, 'files' => array_keys( $files ) );
	}

	/** The stored course title as plain text (no "Private:" prefix, no HTML entities). */
	private static function course_title( int $course_id ): string {
		$t = function_exists( 'get_post_field' ) ? (string) get_post_field( 'post_title', $course_id, 'raw' ) : '';
		return '' !== $t ? wp_specialchars_decode( $t, ENT_QUOTES ) : (string) $course_id;
	}

	/** Remove archives older than the TTL (abandoned ones), whatever their transient says. */
	public static function sweep( string $dir, int $now ): int {
		$n = 0;
		foreach ( (array) glob( $dir . '/*.zip' ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $now - self::ZIP_TTL ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$n;
			}
		}
		return $n;
	}

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

	/**
	 * Decide the ZIP download response. Single use: the file and its record are removed once served.
	 *
	 * @param array<string,mixed> $request
	 * @return array{status:int,headers:array<string,string>,body:string,path?:string}
	 */
	public static function zip_download( array $request, int $now = 0 ): array {
		$now   = $now > 0 ? $now : time();
		$gone  = array( 'status' => 410, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'body' => wp_json_encode( array( 'success' => false, 'data' => array( 'message' => __( 'This ZIP is no longer available. It is deleted after one download or after 15 minutes. Select the certificates and prepare it again.', 'ghca-acd' ) ) ) ) );
		$nonce = isset( $request['nonce'] ) ? (string) $request['nonce'] : '';
		if ( ! is_user_logged_in() || '' === $nonce || ! wp_verify_nonce( $nonce, 'ghca_acd_table' ) || ! self::can_use() ) {
			return array( 'status' => 403, 'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ), 'body' => wp_json_encode( array( 'success' => false, 'data' => array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) ) ) );
		}
		$token = isset( $request['token'] ) ? (string) $request['token'] : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return $gone;
		}
		$job = get_transient( self::ZIP_TRANSIENT . $token );
		if ( ! is_array( $job ) || (int) $job['owner'] !== get_current_user_id() ) {
			return $gone;
		}
		$path = (string) $job['path'];
		if ( (int) $job['created'] + self::ZIP_TTL < $now || ! is_file( $path ) || basename( $path ) !== $token . '.zip' ) {
			delete_transient( self::ZIP_TRANSIENT . $token );
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $gone;
		}
		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		delete_transient( self::ZIP_TRANSIENT . $token );
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
