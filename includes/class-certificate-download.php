<?php
/**
 * Authenticated certificate download for the employee drawer (GRI-94).
 *
 * The browser used to fetch the LearnDash certificate link itself and save
 * whatever came back as a .pdf. LearnDash can answer 200 text/html (an access
 * message, or a login page), which was then saved as a "PDF". This endpoint takes
 * only user_id + course_id (any quiz_id is rejected), derives the LearnDash link on the server,
 * fetches it with the viewer's own session, and streams the result only when it
 * really is a PDF. It never accepts a URL from the request.
 *
 * @package GHCA_Admin_Compliance_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Certificate_Download {

	const ACTION    = 'ghca_acd_certificate_download';
	const NONCE     = 'ghca_acd_table';
	const MAX_BYTES = 10485760;

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
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
