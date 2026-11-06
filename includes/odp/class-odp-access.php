<?php
/**
 * Login and route decisions for bulletin pages.
 * Audience is not an access-control boundary; these checks are the page gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_Access {
	/**
	 * @return array{ok:bool,status:int,code:string}
	 */
	public static function authorize_list( bool $logged_in, bool $can_view_dashboard, string $context ): array {
		if ( ! $logged_in ) {
			return array( 'ok' => false, 'status' => 401, 'code' => 'auth' );
		}
		if ( 'admin' !== $context && 'employee' !== $context ) {
			return array( 'ok' => false, 'status' => 400, 'code' => 'context' );
		}
		if ( $context !== self::context_for( $can_view_dashboard ) ) {
			return array( 'ok' => false, 'status' => 403, 'code' => 'forbidden' );
		}
		return array( 'ok' => true, 'status' => 200, 'code' => 'ok' );
	}

	/** @return array{ok:bool,status:int,code:string} */
	public static function authorize_item( bool $logged_in ): array {
		if ( ! $logged_in ) {
			return array( 'ok' => false, 'status' => 401, 'code' => 'auth' );
		}
		return array( 'ok' => true, 'status' => 200, 'code' => 'ok' );
	}

	public static function context_for( bool $can_view_dashboard ): string {
		return $can_view_dashboard ? 'admin' : 'employee';
	}
}

final class GHCA_ODP_Routes {
	/** @return array<string,string> */
	public static function rules(): array {
		return array(
			'^odp-bulletins/?$' => 'index.php?ghca_odp_screen=library',
			'^odp-bulletins/([a-z0-9]+(?:-[a-z0-9]+)*)/?$' => 'index.php?ghca_odp_screen=single&ghca_odp_id=$matches[1]',
		);
	}

	/** @return array{screen:string,id:string} */
	public static function match( string $path ): array {
		$trimmed = trim( $path );
		if ( '' === $trimmed ) {
			return array( 'screen' => '', 'id' => '' );
		}
		$path = '/' . trim( $trimmed, '/' );
		if ( '/odp-bulletins' === $path ) {
			return array( 'screen' => 'library', 'id' => '' );
		}
		if ( preg_match( '#^/odp-bulletins/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $path, $matches ) ) {
			return array( 'screen' => 'single', 'id' => $matches[1] );
		}
		return array( 'screen' => '', 'id' => '' );
	}

	public static function owns_routes( $owner ): bool {
		return 'ghca-acd' === $owner;
	}

	/**
	 * Path only. Query strings are ignored so a public query var cannot take over another URL.
	 */
	public static function path_from_request( string $request_uri, string $home_path = '/' ): string {
		$path = parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		$home = '/' . trim( $home_path, '/' );
		if ( '/' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}
		return $path;
	}
}
