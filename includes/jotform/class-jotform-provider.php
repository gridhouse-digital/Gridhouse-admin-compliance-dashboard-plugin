<?php
/**
 * Read-only Jotform API adapter. The shared key remains in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class GHCA_ACD_Jotform_Provider {
	const OPTION_REGION          = 'ghca_acd_jotform_region';
	const OPTION_QUOTA_LEFT      = 'ghca_acd_jotform_quota_left';
	const OPTION_QUOTA_UPDATED   = 'ghca_acd_jotform_quota_updated_at';
	const QUOTA_FLOOR            = 100;
	const MAX_DOCUMENT_BYTES     = 26214400;
	const MAX_REDIRECTS          = 2;

	/** @return string|WP_Error */
	public static function api_key() {
		if ( ! defined( 'JOTFORM_API_KEY' ) || ! is_string( JOTFORM_API_KEY ) ) {
			return new WP_Error( 'ghca_jotform_key_missing', __( 'JOTFORM_API_KEY is not configured in wp-config.php.', 'ghca-acd' ) );
		}
		$key = trim( JOTFORM_API_KEY );
		if ( strlen( $key ) < 20 || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
			return new WP_Error( 'ghca_jotform_key_invalid', __( 'The configured Jotform API key is invalid.', 'ghca-acd' ) );
		}
		return $key;
	}

	public static function region(): string {
		$region = (string) get_option( self::OPTION_REGION, 'standard' );
		return 'eu' === $region ? 'eu' : 'standard';
	}

	public static function api_base(): string {
		return 'eu' === self::region() ? 'https://eu-api.jotform.com' : 'https://api.jotform.com';
	}

	public static function quota_paused(): bool {
		$left = get_option( self::OPTION_QUOTA_LEFT, null );
		$updated = (int) get_option( self::OPTION_QUOTA_UPDATED, 0 );
		if ( $updated > 0 && gmdate( 'Y-m-d', $updated ) !== gmdate( 'Y-m-d' ) ) {
			delete_option( self::OPTION_QUOTA_LEFT );
			return false;
		}
		return null !== $left && (int) $left <= self::QUOTA_FLOOR;
	}

	/** @return array|WP_Error */
	public static function test_connection() {
		$result = self::request_json( '/user' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$content = is_array( $result['content'] ?? null ) ? $result['content'] : array();
		return array(
			'username'   => sanitize_text_field( (string) ( $content['username'] ?? $content['name'] ?? '' ) ),
			'region'     => self::region(),
			'quota_left' => isset( $result['limit-left'] ) ? (int) $result['limit-left'] : null,
		);
	}

	/** @return array|WP_Error */
	public static function get_form_submissions( string $form_id, string $after_id, int $limit, string $updated_after = '', string $updated_before = '' ) {
		if ( ! self::valid_numeric_id( $form_id ) ) {
			return new WP_Error( 'ghca_jotform_form_invalid', __( 'The configured Jotform form ID is invalid.', 'ghca-acd' ) );
		}
		if ( ( '' !== $after_id && ! self::valid_numeric_id( $after_id ) ) || ! self::valid_api_datetime( $updated_after ) || ! self::valid_api_datetime( $updated_before ) ) {
			return new WP_Error( 'ghca_jotform_cursor_invalid', __( 'The stored Jotform synchronization cursor is invalid.', 'ghca-acd' ) );
		}
		$query = array(
			'offset'  => 0,
			'limit'   => max( 1, min( 100, $limit ) ),
			'orderby' => 'id',
			'direction' => 'ASC',
		);
		$filter = array();
		if ( '' !== $after_id ) {
			$filter['id:gt'] = $after_id;
		}
		if ( '' !== $updated_after ) {
			$filter['updated_at:gt'] = $updated_after;
		}
		if ( '' !== $updated_before ) {
			$filter['updated_at:lt'] = $updated_before;
		}
		if ( ! empty( $filter ) ) {
			$query['filter'] = wp_json_encode( $filter );
		}
		return self::request_json( '/form/' . rawurlencode( $form_id ) . '/submissions', $query );
	}

	/** @return array|WP_Error */
	public static function get_submission( string $submission_id ) {
		if ( ! self::valid_numeric_id( $submission_id ) ) {
			return new WP_Error( 'ghca_jotform_submission_invalid', __( 'The Jotform submission reference is invalid.', 'ghca-acd' ) );
		}
		return self::request_json( '/submission/' . rawurlencode( $submission_id ) );
	}

	/** @return array|WP_Error */
	private static function request_json( string $path, array $query = array() ) {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! preg_match( '#^/(?:user|form/[0-9]+/submissions|submission/[0-9]+)$#', $path ) ) {
			return new WP_Error( 'ghca_jotform_path_denied', __( 'The Jotform request was denied.', 'ghca-acd' ) );
		}
		$url = add_query_arg( $query, self::api_base() . $path );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'APIKEY' => $key,
					'Accept' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ghca_jotform_unavailable', __( 'Jotform is currently unavailable.', 'ghca-acd' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		self::update_quota_from_response( $response );
		if ( is_array( $body ) && isset( $body['limit-left'] ) ) {
			update_option( self::OPTION_QUOTA_LEFT, max( 0, (int) $body['limit-left'] ), false );
			update_option( self::OPTION_QUOTA_UPDATED, time(), false );
		}
		if ( 200 !== $status || ! is_array( $body ) || 200 !== (int) ( $body['responseCode'] ?? $status ) ) {
			$code = in_array( $status, array( 401, 403 ), true ) ? 'ghca_jotform_auth_failed' : ( 429 === $status ? 'ghca_jotform_quota_exhausted' : 'ghca_jotform_api_failed' );
			return new WP_Error( $code, __( 'The Jotform request failed. Review the connection and API allowance.', 'ghca-acd' ) );
		}
		return $body;
	}

	/** @param array<string,mixed> $submission @return mixed */
	public static function answer( array $submission, string $question_id ) {
		$answers = is_array( $submission['answers'] ?? null ) ? $submission['answers'] : array();
		if ( isset( $answers[ $question_id ] ) && is_array( $answers[ $question_id ] ) ) {
			return $answers[ $question_id ]['answer'] ?? '';
		}
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			if ( $question_id === (string) ( $answer['name'] ?? '' ) ) {
				return $answer['answer'] ?? '';
			}
		}
		return '';
	}

	/** @param mixed $value @return array<int,string> */
	public static function file_urls( $value ): array {
		$values = self::file_candidates( $value );
		$urls = array();
		foreach ( $values as $candidate ) {
			if ( self::file_url_is_allowed( $candidate ) ) {
				$urls[] = $candidate;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @param mixed $value @return array<int,string> */
	public static function file_candidates( $value ): array {
		$values = array();
		if ( is_array( $value ) ) {
			array_walk_recursive( $value, static function ( $item ) use ( &$values ): void { if ( is_scalar( $item ) ) { $values[] = (string) $item; } } );
		} elseif ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return self::file_candidates( $decoded );
			}
			preg_match_all( '#https://[^\s,]+#i', $value, $matches );
			$values = ! empty( $matches[0] ) ? $matches[0] : array( $value );
		}
		return array_values( array_unique( array_filter( array_map( static function ( string $candidate ): string { return html_entity_decode( trim( $candidate ), ENT_QUOTES, 'UTF-8' ); }, $values ) ) ) );
	}

	public static function file_url_is_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$allowed_hosts = array( 'www.jotform.com', 'eu.jotform.com' );
		if ( ! in_array( $host, $allowed_hosts, true ) || 0 !== strpos( (string) ( $parts['path'] ?? '' ), '/uploads/' ) ) {
			return false;
		}
		return self::host_resolves_publicly( $host );
	}

	/** @return array|WP_Error {body:string,mime:string,name:string,bytes:int} */
	public static function fetch_file( string $url ) {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$current = $url;
		for ( $redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++ ) {
			if ( ! self::file_url_is_allowed( $current ) ) {
				return new WP_Error( 'ghca_jotform_file_url_denied', __( 'The Jotform file location was rejected.', 'ghca-acd' ) );
			}
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => 30,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_DOCUMENT_BYTES + 1,
					'headers'             => array( 'APIKEY' => $key, 'Accept' => 'application/pdf,image/jpeg,image/png' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ghca_jotform_file_unavailable', __( 'The Jotform file could not be retrieved.', 'ghca-acd' ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			self::update_quota_from_response( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && $redirect < self::MAX_REDIRECTS ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location || 0 !== strpos( $location, 'https://' ) ) {
					return new WP_Error( 'ghca_jotform_file_redirect_denied', __( 'The Jotform file redirect was rejected.', 'ghca-acd' ) );
				}
				$current = $location;
				continue;
			}
			if ( 200 !== $status ) {
				return new WP_Error( 'ghca_jotform_file_failed', __( 'The Jotform file could not be retrieved.', 'ghca-acd' ) );
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === $body || strlen( $body ) > self::MAX_DOCUMENT_BYTES ) {
				return new WP_Error( 'ghca_jotform_file_size', __( 'The Jotform file is empty or exceeds the allowed size.', 'ghca-acd' ) );
			}
			$claimed_mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
			$detected_mime = self::safe_file_mime( $body );
			if ( '' === $detected_mime || ( '' !== $claimed_mime && 'application/octet-stream' !== $claimed_mime && $claimed_mime !== $detected_mime ) ) {
				return new WP_Error( 'ghca_jotform_file_type', __( 'The Jotform file type was rejected.', 'ghca-acd' ) );
			}
			$path = (string) ( wp_parse_url( $current, PHP_URL_PATH ) ?? '' );
			return array(
				'body'  => $body,
				'mime'  => $detected_mime,
				'name'  => sanitize_file_name( rawurldecode( basename( $path ) ) ),
				'bytes' => strlen( $body ),
			);
		}
		return new WP_Error( 'ghca_jotform_file_redirects', __( 'The Jotform file used too many redirects.', 'ghca-acd' ) );
	}

	private static function valid_numeric_id( string $id ): bool {
		return (bool) preg_match( '/^[0-9]{6,32}$/', $id );
	}

	private static function valid_api_datetime( string $value ): bool {
		if ( '' === $value ) {
			return true;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $date instanceof DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private static function safe_file_mime( string $body ): string {
		if ( 0 === strpos( $body, '%PDF-' ) ) { return 'application/pdf'; }
		if ( 0 === strpos( $body, "\xFF\xD8\xFF" ) ) { return 'image/jpeg'; }
		if ( 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ) { return 'image/png'; }
		return '';
	}

	public static function host_resolves_publicly( string $host ): bool {
		$addresses = gethostbynamel( $host );
		if ( ! is_array( $addresses ) || empty( $addresses ) ) {
			return false;
		}
		foreach ( $addresses as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array|WP_Error $response */
	private static function update_quota_from_response( $response ): void {
		if ( is_wp_error( $response ) ) {
			return;
		}
		foreach ( array( 'x-ratelimit-remaining', 'ratelimit-remaining', 'limit-left' ) as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );
			if ( is_scalar( $value ) && preg_match( '/^[0-9]+$/', trim( (string) $value ) ) ) {
				update_option( self::OPTION_QUOTA_LEFT, max( 0, (int) $value ), false );
				update_option( self::OPTION_QUOTA_UPDATED, time(), false );
				return;
			}
		}
	}
}
