<?php
/**
 * Request-local Google Drive service-account reader for external evidence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Google_Drive_Provider {
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/drive/v3';
	const SCOPE     = 'https://www.googleapis.com/auth/drive.readonly';

	/** @return array<int,string>|WP_Error */
	public static function jotform_file_names( $answer ) {
		$names = array();
		$collision_keys = array();
		foreach ( GHCA_ACD_Jotform_Provider::file_candidates( $answer ) as $candidate ) {
			$path = (string) ( wp_parse_url( $candidate, PHP_URL_PATH ) ?: $candidate );
			$name = rawurldecode( basename( $path ) );
			if ( ! self::valid_raw_name( $name ) || isset( $names[ $name ] ) || isset( $collision_keys[ self::normalization_collision_key( $name ) ] ) ) {
				return new WP_Error( 'ghca_drive_metadata_invalid', __( 'The certificate metadata is invalid for Drive evidence.', 'ghca-acd' ) );
			}
			$names[ $name ] = true;
			$collision_keys[ self::normalization_collision_key( $name ) ] = true;
		}
		if ( empty( $names ) ) {
			return new WP_Error( 'ghca_drive_metadata_ambiguous', __( 'The certificate metadata is missing or ambiguous for Drive evidence.', 'ghca-acd' ) );
		}
		return array_keys( $names );
	}

	/** Administrator readiness probe: validates credentials, authentication and the configured root without disclosing secrets. */
	public static function diagnose_root( array $form ) {
		$root_id = (string) ( $form['drive_folder_id'] ?? '' );
		if ( ! self::valid_id( $root_id ) ) { return new WP_Error( 'ghca_drive_root_invalid', __( 'The configured Drive evidence folder is invalid.', 'ghca-acd' ) ); }
		$token = self::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$root = self::api_get( '/files/' . rawurlencode( $root_id ), array( 'fields' => 'id,mimeType,trashed' ), $token );
		return is_wp_error( $root ) || 'application/vnd.google-apps.folder' !== (string) ( $root['mimeType'] ?? '' ) || ! empty( $root['trashed'] ) || ! hash_equals( $root_id, (string) ( $root['id'] ?? '' ) ) ? new WP_Error( 'ghca_drive_root_unavailable', __( 'The configured Drive evidence folder is unavailable or not shared with the service account.', 'ghca-acd' ) ) : true;
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission @return array<int,array<string,mixed>>|WP_Error */
	public static function files_for_submission( array $form, array $submission ) {
		$root_id = (string) ( $form['drive_folder_id'] ?? '' );
		if ( ! self::valid_id( $root_id ) ) {
			return new WP_Error( 'ghca_drive_root_invalid', __( 'The configured Drive evidence folder is invalid.', 'ghca-acd' ) );
		}
		$reference = self::evidence_reference( $form, $submission );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$names = self::jotform_file_names( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['certificate_qid'] ?? '' ) ) );
		if ( is_wp_error( $names ) ) {
			return $names;
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$root = self::api_get( '/files/' . rawurlencode( $root_id ), array( 'fields' => 'id,mimeType,trashed' ), $token );
		$folder_name = 'Evidence-' . $reference;
		$folders = self::list_files( "'" . self::query_escape( $root_id ) . "' in parents and name = '" . self::query_escape( $folder_name ) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", 'id,name,mimeType,parents,trashed', 2, $token );
		$folder_id = ! is_wp_error( $folders ) && 1 === count( $folders ) ? (string) ( $folders[0]['id'] ?? '' ) : '';
		$drive_files = '' !== $folder_id ? self::list_files( "'" . self::query_escape( $folder_id ) . "' in parents and trashed = false", 'id,name,mimeType,size,parents,trashed', 1000, $token ) : new WP_Error( 'ghca_drive_evidence_folder_invalid', __( 'The Drive evidence folder is missing or ambiguous.', 'ghca-acd' ) );
		return self::validate_evidence_listing( $root_id, $folder_name, $names, $root, $folders, $drive_files );
	}

	/** @param array<int,string> $names @param array<string,mixed>|WP_Error $root @param array<int,array<string,mixed>>|WP_Error $folders @param array<int,array<string,mixed>>|WP_Error $drive_files @return array<int,array<string,mixed>>|WP_Error */
	public static function validate_evidence_listing( string $root_id, string $folder_name, array $names, $root, $folders, $drive_files ) {
		if ( is_wp_error( $root ) || 'application/vnd.google-apps.folder' !== (string) ( $root['mimeType'] ?? '' ) || ! empty( $root['trashed'] ) || ! hash_equals( $root_id, (string) ( $root['id'] ?? '' ) ) ) {
			return new WP_Error( 'ghca_drive_root_unavailable', __( 'The configured Drive evidence folder is unavailable.', 'ghca-acd' ) );
		}
		if ( is_wp_error( $folders ) || 1 !== count( $folders ) || ! hash_equals( $folder_name, (string) ( $folders[0]['name'] ?? '' ) ) || ! self::has_parent( $folders[0], $root_id ) ) {
			return new WP_Error( 'ghca_drive_evidence_folder_invalid', __( 'The Drive evidence folder is missing or ambiguous.', 'ghca-acd' ) );
		}
		$folder_id = (string) ( $folders[0]['id'] ?? '' );
		if ( ! self::valid_id( $folder_id ) ) {
			return new WP_Error( 'ghca_drive_evidence_folder_invalid', __( 'The Drive evidence folder is invalid.', 'ghca-acd' ) );
		}
		if ( is_wp_error( $drive_files ) || count( $drive_files ) !== count( $names ) ) {
			return new WP_Error( 'ghca_drive_file_count_invalid', __( 'Drive evidence does not exactly match the submitted certificates.', 'ghca-acd' ) );
		}
		$by_key = array();
		$expected_by_stem = array();
		foreach ( $names as $expected_name ) {
			$expected_mime = self::mime_for_extension( strtolower( pathinfo( $expected_name, PATHINFO_EXTENSION ) ) );
			$stem = self::normalization_collision_key( pathinfo( $expected_name, PATHINFO_FILENAME ) );
			if ( '' === $expected_mime || '' === $stem ) { return new WP_Error( 'ghca_drive_metadata_invalid', __( 'The certificate metadata is invalid for Drive evidence.', 'ghca-acd' ) ); }
			$expected_by_stem[ $expected_mime . '|' . $stem ][] = $expected_name;
		}
		$collision_keys = array();
		foreach ( $drive_files as $file ) {
			$name = (string) ( $file['name'] ?? '' );
			$mime = strtolower( (string) ( $file['mimeType'] ?? '' ) );
			$size = (string) ( $file['size'] ?? '' );
			$key = self::drive_collision_key( $name, $mime, $expected_by_stem );
			if ( ! self::valid_id( (string) ( $file['id'] ?? '' ) ) || '' === $key || ! preg_match( '/^[1-9][0-9]*$/', $size ) || (int) $size > GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES || ! self::has_parent( $file, $folder_id ) || isset( $by_key[ $key ] ) || isset( $collision_keys[ $key ] ) ) {
				return new WP_Error( 'ghca_drive_file_invalid', __( 'A Drive evidence file is invalid.', 'ghca-acd' ) );
			}
			$by_key[ $key ] = $file;
			$collision_keys[ $key ] = true;
		}
		$ordered = array();
		foreach ( $names as $name ) {
			$key = self::normalization_collision_key( $name );
			if ( empty( $by_key[ $key ] ) ) {
				return new WP_Error( 'ghca_drive_file_missing', __( 'A submitted certificate is missing from Drive evidence.', 'ghca-acd' ) );
			}
			$file = $by_key[ $key ];
			$file['expected_source_name'] = $name;
			$file['drive_source_name'] = (string) $file['name'];
			$ordered[] = $file;
		}
		return $ordered;
	}

	/** @param array<string,mixed> $file @return array{body:string,mime:string,name:string,bytes:int}|WP_Error */
	public static function fetch_file( array $file ) {
		$token = self::access_token();
		$id = (string) ( $file['id'] ?? '' );
		if ( is_wp_error( $token ) || ! self::valid_id( $id ) ) {
			return is_wp_error( $token ) ? $token : new WP_Error( 'ghca_drive_file_invalid', __( 'The Drive evidence file is invalid.', 'ghca-acd' ) );
		}
		$response = wp_safe_remote_get( self::API_BASE . '/files/' . rawurlencode( $id ) . '?alt=media', array( 'timeout' => 30, 'redirection' => 0, 'limit_response_size' => GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES + 1, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/pdf,image/jpeg,image/png' ) ) );
		return self::validate_file_response( $file, $response );
	}

	/** @param array<string,mixed> $file @param array|WP_Error $response @return array{body:string,mime:string,name:string,bytes:int}|WP_Error */
	public static function validate_file_response( array $file, $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ghca_drive_file_unavailable', __( 'The Drive evidence file could not be retrieved.', 'ghca-acd' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ? 'ghca_drive_redirect_denied' : 'ghca_drive_file_failed', __( 'The Drive evidence file could not be retrieved.', 'ghca-acd' ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		$mime = self::detected_mime( $body );
		$claimed = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		if ( '' === $body || strlen( $body ) > GHCA_ACD_Jotform_Provider::MAX_DOCUMENT_BYTES || '' === $mime || ( '' !== $claimed && 'application/octet-stream' !== $claimed && $claimed !== $mime ) || $mime !== strtolower( (string) ( $file['mimeType'] ?? '' ) ) ) {
			return new WP_Error( 'ghca_drive_file_type', __( 'The Drive evidence file failed validation.', 'ghca-acd' ) );
		}
		return array( 'body' => $body, 'mime' => $mime, 'name' => sanitize_file_name( (string) $file['name'] ), 'bytes' => strlen( $body ) );
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission @return string|WP_Error */
	public static function evidence_reference( array $form, array $submission ) {
		$qid = (string) ( $form['evidence_reference_qid'] ?? '' );
		if ( '' !== $qid ) {
			$value = GHCA_ACD_Jotform_Provider::answer( $submission, $qid );
			if ( ! is_scalar( $value ) ) {
				return new WP_Error( 'ghca_drive_reference_invalid', __( 'The Drive evidence reference is invalid.', 'ghca-acd' ) );
			}
			$reference = (string) $value;
			if ( '' === $reference || trim( $reference ) !== $reference || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $reference ) ) {
				return new WP_Error( 'ghca_drive_reference_invalid', __( 'The Drive evidence reference is invalid.', 'ghca-acd' ) );
			}
			return $reference;
		}
		$reference = (string) ( $submission['id'] ?? '' );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $reference ) ? $reference : new WP_Error( 'ghca_drive_reference_invalid', __( 'The Drive evidence reference is invalid.', 'ghca-acd' ) );
	}

	/** @return string|WP_Error */
	private static function access_token() {
		static $token = null;
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$credentials = self::credentials();
		if ( is_wp_error( $credentials ) || ! function_exists( 'openssl_sign' ) ) {
			return is_wp_error( $credentials ) ? $credentials : new WP_Error( 'ghca_drive_crypto_missing', __( 'OpenSSL is required for Drive authentication.', 'ghca-acd' ) );
		}
		$now = time();
		$header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::base64url( wp_json_encode( array( 'iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 300 ) ) );
		$input = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'ghca_drive_jwt_failed', __( 'Drive authentication could not be prepared.', 'ghca-acd' ) );
		}
		$response = wp_safe_remote_post( self::TOKEN_URL, array( 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ), 'body' => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . self::base64url( $signature ) ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ghca_drive_auth_failed', __( 'Drive authentication failed.', 'ghca-acd' ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['access_token'] ) || ! is_string( $body['access_token'] ) || ! preg_match( '/^[A-Za-z0-9._-]{20,4096}$/', $body['access_token'] ) ) {
			return new WP_Error( 'ghca_drive_auth_failed', __( 'Drive authentication failed.', 'ghca-acd' ) );
		}
		$token = $body['access_token'];
		return $token;
	}

	/** @return array{client_email:string,private_key:string}|WP_Error */
	private static function credentials() {
		if ( ! defined( 'GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE' ) || ! is_string( GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE ) || '' === trim( GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE ) ) {
			return new WP_Error( 'ghca_drive_credentials_missing', __( 'Google Drive credentials are not configured.', 'ghca-acd' ) );
		}
		$base = GHCA_ACD_External_Evidence_Store::base_directory();
		$path = realpath( GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE );
		if ( is_wp_error( $base ) || false === $path || ! is_file( $path ) || ! self::path_is_within( $path, (string) $base ) || ! is_readable( $path ) || filesize( $path ) > 65536 ) {
			return new WP_Error( 'ghca_drive_credentials_invalid', __( 'Google Drive credentials are unavailable.', 'ghca-acd' ) );
		}
		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $json ) || 'service_account' !== (string) ( $json['type'] ?? '' ) || ! isset( $json['client_email'], $json['private_key'] ) || ! is_string( $json['client_email'] ) || ! is_string( $json['private_key'] ) || ! preg_match( '/^[^\s@]+@[^\s@]+\.gserviceaccount\.com$/', $json['client_email'] ) || 0 !== strpos( $json['private_key'], '-----BEGIN PRIVATE KEY-----' ) ) {
			return new WP_Error( 'ghca_drive_credentials_invalid', __( 'Google Drive credentials are invalid.', 'ghca-acd' ) );
		}
		return array( 'client_email' => $json['client_email'], 'private_key' => $json['private_key'] );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function api_get( string $path, array $query, string $token ) {
		$response = wp_safe_remote_get( self::API_BASE . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), array( 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ghca_drive_api_failed', __( 'Drive evidence could not be resolved.', 'ghca-acd' ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : new WP_Error( 'ghca_drive_api_invalid', __( 'Drive evidence could not be resolved.', 'ghca-acd' ) );
	}

	/** @return array<int,array<string,mixed>>|WP_Error */
	private static function list_files( string $query, string $fields, int $page_size, string $token ) {
		$result = self::api_get( '/files', array( 'q' => $query, 'fields' => 'files(' . $fields . '),nextPageToken', 'pageSize' => $page_size, 'spaces' => 'drive' ), $token );
		if ( is_wp_error( $result ) || ! empty( $result['nextPageToken'] ) || ! is_array( $result['files'] ?? null ) ) {
			return new WP_Error( 'ghca_drive_list_invalid', __( 'Drive evidence could not be listed safely.', 'ghca-acd' ) );
		}
		return array_values( array_filter( $result['files'], 'is_array' ) );
	}

	private static function valid_id( string $value ): bool { return (bool) preg_match( '/^[A-Za-z0-9_-]{10,200}$/', $value ); }
	private static function valid_raw_name( string $name ): bool { return '' !== $name && strlen( $name ) <= 255 && ! preg_match( '/[\\\\\/\x00-\x1F\x7F]/', $name ) && '.' !== $name && '..' !== $name && (bool) preg_match( '/\.(pdf|jpe?g|png)$/i', $name ); }
	/* Expected source names retain their extension. Drive may omit it only where MIME supplies it. */
	private static function normalization_collision_key( string $name ): string { return strtolower( remove_accents( sanitize_file_name( $name ) ) ); }
	/** @param array<string,array<int,string>> $expected_by_stem */
	private static function drive_collision_key( string $name, string $mime, array $expected_by_stem ): string {
		if ( '' === $name || strlen( $name ) > 255 || preg_match( '/[\\\\\/\x00-\x1F\x7F]/', $name ) || '.' === $name || '..' === $name ) { return ''; }
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			$candidates = $expected_by_stem[ $mime . '|' . self::normalization_collision_key( $name ) ] ?? array();
			return 1 === count( $candidates ) ? self::normalization_collision_key( $candidates[0] ) : '';
		}
		if ( self::mime_for_extension( $extension ) !== $mime ) { return ''; }
		return self::normalization_collision_key( $name );
	}
	private static function mime_for_extension( string $extension ): string { return array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' )[ $extension ] ?? ''; }
	private static function query_escape( string $value ): string { return str_replace( "'", "\\'", $value ); }
	private static function base64url( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
	private static function has_parent( array $item, string $parent ): bool { return is_array( $item['parents'] ?? null ) && 1 === count( $item['parents'] ) && hash_equals( $parent, (string) $item['parents'][0] ); }
	private static function detected_mime( string $body ): string { return 0 === strpos( $body, '%PDF-' ) ? 'application/pdf' : ( 0 === strpos( $body, "\xFF\xD8\xFF" ) ? 'image/jpeg' : ( 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ? 'image/png' : '' ) ); }
	private static function path_is_within( string $path, string $root ): bool { $path = rtrim( wp_normalize_path( $path ), '/' ) . '/'; $root = rtrim( wp_normalize_path( $root ), '/' ) . '/'; return 0 === strpos( '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path, '\\' === DIRECTORY_SEPARATOR ? strtolower( $root ) : $root ); }
}
