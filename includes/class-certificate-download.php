<?php
/**
 * Authenticated certificate download for the employee drawer (GRI-94).
 *
 * The browser used to fetch the LearnDash certificate link itself and save
 * whatever came back as a .pdf. LearnDash can answer 200 text/html (an access
 * message, or a login page), which was then saved as a "PDF". This endpoint takes
 * only user_id + course_id (any quiz_id is rejected) and never a URL.
 *
 * LearnDash alone decides who may view another user's certificate (administrators
 * and group leaders). Dashboard roles such as Compliance Lead pass the plugin's own
 * permission checks but not LearnDash's, so after the plugin has authorized the
 * request it renders the PDF through a one-time internal broker, the same pattern
 * the packet builder uses: a short-lived single-use token bound to the requester,
 * employee, course and certificate; a separate internal request that carries no
 * browser cookie; LearnDash renders as the employee. No role or capability is
 * granted. LearnDash administrators and group leaders keep the direct fetch.
 * Only a verified PDF is ever returned.
 *
 * @package GHCA_Admin_Compliance_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Certificate_Download {

	const ACTION            = 'ghca_acd_certificate_download';
	const BROKER_ACTION     = 'ghca_acd_certificate_download_broker';
	const NONCE             = 'ghca_acd_table';
	const MAX_BYTES         = 10485760;
	const TOKEN_TTL         = 60;
	const TOKEN_TRANSIENT   = 'ghca_acd_cert_dl_';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		// The internal request carries a token, not a browser session.
		add_action( 'wp_ajax_' . self::BROKER_ACTION, array( __CLASS__, 'broker' ) );
		add_action( 'wp_ajax_nopriv_' . self::BROKER_ACTION, array( __CLASS__, 'broker' ) );
	}

	/** Same-origin URL the drawer button points at. Carries ids only, never a certificate link. */
	public static function download_url( int $user_id, int $course_id ): string {
		return add_query_arg(
			array(
				'action'    => self::ACTION,
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'nonce'     => wp_create_nonce( self::NONCE ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function handle(): void {
		$result = self::process( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in process().
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( $result['status'] );
		foreach ( $result['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes or a fixed plain-text message.
		exit;
	}

	/**
	 * Decide the response. Pure of output so it can be tested.
	 *
	 * @param array<string,mixed> $request Raw query parameters.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function process( array $request ): array {
		$nonce = isset( $request['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $request['nonce'] ) ) : '';
		if ( ! is_user_logged_in() || '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return self::error( 403 );
		}
		if ( ! GHCA_ACD_Roles::user_can_view() || ! GHCA_ACD_Roles::user_can_view_employee_documents() ) {
			return self::error( 403 );
		}

		$user_id   = isset( $request['user_id'] ) ? absint( $request['user_id'] ) : 0;
		$course_id = isset( $request['course_id'] ) ? absint( $request['course_id'] ) : 0;
		if ( $user_id <= 0 || $course_id <= 0 || array_key_exists( 'quiz_id', $request ) ) {
			return self::error( 400 );
		}
		if ( ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			return self::error( 403 );
		}

		if ( self::should_use_broker() ) {
			$certificate_id = self::certificate_id( $user_id, $course_id );
			if ( $certificate_id <= 0 ) {
				return self::error( 404 );
			}
			$response = self::request_via_broker( get_current_user_id(), $user_id, $course_id, $certificate_id );
		} else {
			$link = self::course_link( $user_id, $course_id );
			if ( '' === $link ) {
				return self::error( 404 );
			}
			$response = wp_safe_remote_get(
				$link,
				array(
					'timeout'             => 25,
					'sslverify'           => true,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES + 1,
					'cookies'             => self::session_cookies(),
				)
			);
		}
		if ( is_wp_error( $response ) ) {
			return self::error( 502 );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( ! self::is_pdf_response( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'content-type' ), $body ) ) {
			return self::error( 404 );
		}

		$title = get_the_title( $course_id );
		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => 'application/pdf',
				'Content-Disposition'    => 'attachment; filename="' . self::safe_filename( (string) $title ) . '"',
				'Content-Length'         => (string) strlen( $body ),
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $body,
		);
	}

	/** True only for a 200 application/pdf response whose body starts with %PDF- and fits the size cap. */
	public static function is_pdf_response( int $code, string $content_type, string $body ): bool {
		$bytes = strlen( $body );
		return 200 === $code
			&& 1 === preg_match( '#^application/pdf(?:\s*;|\s*$)#i', trim( $content_type ) )
			&& $bytes > 5
			&& $bytes <= self::MAX_BYTES
			&& 0 === strpos( $body, '%PDF-' );
	}

	public static function safe_filename( string $title ): string {
		$name = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', $title ), '-._' );
		$name = substr( $name, 0, 80 );
		return ( '' !== $name ? $name : 'certificate' ) . '.pdf';
	}

	private static function course_link( int $user_id, int $course_id ): string {
		if ( function_exists( 'learndash_get_post_type_slug' ) && learndash_get_post_type_slug( 'course' ) !== get_post_type( $course_id ) ) {
			return '';
		}
		if ( ! function_exists( 'learndash_course_completed' ) || ! learndash_course_completed( $user_id, $course_id ) ) {
			return '';
		}
		return GHCA_ACD_Data_Provider::get_certificate_url( $user_id, $course_id );
	}

	/** LearnDash administrators and group leaders can already view the certificate with their own session. */
	private static function should_use_broker(): bool {
		if ( function_exists( 'learndash_is_admin_user' ) && learndash_is_admin_user() ) {
			return false;
		}
		if ( function_exists( 'learndash_is_group_leader_user' ) && learndash_is_group_leader_user() ) {
			return false;
		}
		return true;
	}

	/** The LearnDash certificate post for a completed course, or 0. */
	private static function certificate_id( int $user_id, int $course_id ): int {
		if (
			! function_exists( 'learndash_course_completed' )
			|| ! function_exists( 'learndash_get_setting' )
			|| ! function_exists( 'learndash_get_post_type_slug' )
			|| learndash_get_post_type_slug( 'course' ) !== get_post_type( $course_id )
			|| ! learndash_course_completed( $user_id, $course_id )
		) {
			return 0;
		}
		$certificate_id = (int) learndash_get_setting( $course_id, 'certificate' );
		if ( $certificate_id <= 0 || learndash_get_post_type_slug( 'certificate' ) !== get_post_type( $certificate_id ) ) {
			return 0;
		}
		return $certificate_id;
	}

	/* ---------------------------------------------------------------------
	 * One-time broker token
	 * ------------------------------------------------------------------- */

	/** Pure: is this stored record usable by exactly this token and these ids right now? */
	public static function validate_token_record( $record, string $token, int $requester_id, int $user_id, int $course_id, int $now ): string {
		if ( ! is_array( $record ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return 'not_found';
		}
		$created = (int) ( $record['created'] ?? 0 );
		if ( $created <= 0 || $now < $created || ( $now - $created ) > self::TOKEN_TTL ) {
			return 'expired';
		}
		if ( ! hash_equals( (string) ( $record['hash'] ?? '' ), hash( 'sha256', $token ) ) ) {
			return 'token_mismatch';
		}
		if (
			$requester_id <= 0 || $user_id <= 0 || $course_id <= 0
			|| (int) ( $record['requester'] ?? 0 ) !== $requester_id
			|| (int) ( $record['user_id'] ?? 0 ) !== $user_id
			|| (int) ( $record['course_id'] ?? 0 ) !== $course_id
			|| (int) ( $record['certificate_id'] ?? 0 ) <= 0
		) {
			return 'binding_mismatch';
		}
		if ( ! empty( $record['used'] ) ) {
			return 'replayed';
		}
		return 'ok';
	}

	/**
	 * Mint a 256-bit single-use token bound to requester, employee, course and
	 * certificate. Only the SHA-256 of the token is stored (transient, 60 seconds).
	 */
	public static function issue_token( int $requester_id, int $user_id, int $course_id, int $certificate_id, int $now = 0 ): string {
		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::TOKEN_TRANSIENT . substr( hash( 'sha256', $token ), 0, 40 ),
			array(
				'hash'           => hash( 'sha256', $token ),
				'requester'      => $requester_id,
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'certificate_id' => $certificate_id,
				'created'        => $now > 0 ? $now : time(),
				'used'           => false,
			),
			self::TOKEN_TTL
		);
		return $token;
	}

	/**
	 * Claim a token exactly once under a lock. Returns the certificate id to render,
	 * or 0 for any denial (expired, replayed, mismatched, unknown).
	 */
	public static function claim_token( string $token, int $requester_id, int $user_id, int $course_id, int $now = 0 ): int {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return 0;
		}
		$now  = $now > 0 ? $now : time();
		$hash = hash( 'sha256', $token );
		$key  = self::TOKEN_TRANSIENT . substr( $hash, 0, 40 );
		$lock = GHCA_Audit_PDF_Jobs::acquire_certificate_token_lock( substr( $hash, 0, 32 ) );
		if ( false === $lock ) {
			return 0;
		}
		try {
			$record  = get_transient( $key );
			$verdict = self::validate_token_record( $record, $token, $requester_id, $user_id, $course_id, $now );
			if ( 'ok' !== $verdict ) {
				return 0;
			}
			$record['used'] = true;
			$remaining      = max( 1, self::TOKEN_TTL - ( $now - (int) $record['created'] ) );
			if ( ! set_transient( $key, $record, $remaining ) ) {
				return 0;
			}
			return (int) $record['certificate_id'];
		} finally {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
		}
	}

	/** Internal request: same-origin admin-ajax, no cookies, no redirects, capped size. */
	private static function request_via_broker( int $requester_id, int $user_id, int $course_id, int $certificate_id ) {
		$broker_url = GHCA_ACD_Data_Provider::sanitize_certificate_url( admin_url( 'admin-ajax.php' ) );
		if ( '' === $broker_url ) {
			return new WP_Error( 'ghca_cert_broker_url', 'unavailable' );
		}
		$token = self::issue_token( $requester_id, $user_id, $course_id, $certificate_id );
		return wp_safe_remote_post(
			$broker_url,
			array(
				'timeout'             => 25,
				'sslverify'           => true,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'body'                => array(
					'action'       => self::BROKER_ACTION,
					'token'        => $token,
					'requester_id' => $requester_id,
					'user_id'      => $user_id,
					'course_id'    => $course_id,
				),
			)
		);
	}

	/**
	 * Pure-ish authorization for the internal request (testable): the token claim and
	 * a re-check of the course and certificate. Returns the certificate id or 0.
	 *
	 * @param array<string,mixed> $post
	 */
	public static function authorize_broker( array $post, int $now = 0 ): int {
		$token        = isset( $post['token'] ) ? sanitize_text_field( wp_unslash( (string) $post['token'] ) ) : '';
		$requester_id = isset( $post['requester_id'] ) ? absint( $post['requester_id'] ) : 0;
		$user_id      = isset( $post['user_id'] ) ? absint( $post['user_id'] ) : 0;
		$course_id    = isset( $post['course_id'] ) ? absint( $post['course_id'] ) : 0;
		if ( $user_id <= 0 || $course_id <= 0 || ! get_userdata( $user_id ) ) {
			return 0;
		}
		$claimed = self::claim_token( $token, $requester_id, $user_id, $course_id, $now );
		if ( $claimed <= 0 ) {
			return 0;
		}
		// The token proves the plugin authorized this exact request; LearnDash state is rechecked anyway.
		return self::certificate_id( $user_id, $course_id ) === $claimed ? $claimed : 0;
	}

	/** Internal broker endpoint: renders one employee's certificate through LearnDash. POST + token only. */
	public static function broker(): void {
		$certificate_id = 0;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$certificate_id = self::authorize_broker( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- one-time token, verified in claim_token().
		}
		if ( $certificate_id <= 0 ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Certificate request is unavailable.';
			exit;
		}
		GHCA_Audit_PDF::serve_certificate_pdf( absint( $_POST['user_id'] ), $certificate_id, absint( $_POST['course_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/** @return array<int,WP_Http_Cookie> The viewer's own login cookie only. */
	private static function session_cookies(): array {
		$cookies = array();
		if ( defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$cookies[] = new WP_Http_Cookie(
				array(
					'name'   => LOGGED_IN_COOKIE,
					'value'  => wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ),
					'domain' => (string) parse_url( home_url( '/' ), PHP_URL_HOST ),
					'path'   => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				)
			);
		}
		return $cookies;
	}

	/** @return array{status:int,headers:array<string,string>,body:string} */
	private static function error( int $status ): array {
		return array(
			'status'  => $status,
			'headers' => array( 'Content-Type' => 'text/plain; charset=utf-8' ),
			'body'    => 'Certificate could not be downloaded.',
		);
	}
}
