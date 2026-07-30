<?php

/** Non-configurable installed-code version attestation for the evidence source. */
final class GHCA_ACD_Archive_Code_Version_Attestor {
	const WORDPRESS_VERSION = '7.0.2';
	const LEARNDASH_VERSION = '5.1.6.1';
	const PLUGIN_VERSION    = '1.2.0';

	/** @return array<string,string> */
	public function attest(): array {
		$plugin_root = dirname( __DIR__, 3 );
		$plugins_root = dirname( $plugin_root );
		$content_root = dirname( $plugins_root );
		if ( 'plugins' !== basename( $plugins_root ) || 'wp-content' !== basename( $content_root ) ) {
			$this->fail();
		}
		$wordpress_root = dirname( $content_root );
		foreach ( array( $plugin_root, $plugins_root, $content_root, $wordpress_root ) as $root ) {
			if ( false === realpath( $root ) || is_link( $root ) ) {
				$this->fail();
			}
		}

		$wp_file = $wordpress_root . '/wp-includes/version.php';
		$ld_file = $plugins_root . '/sfwd-lms/sfwd_lms.php';
		$plugin_file = $plugin_root . '/gridhouse-admin-compliance-dashboard.php';
		$wp_bytes = $this->read_bounded( $wp_file, 65536, $wordpress_root );
		$ld_bytes = $this->read_bounded( $ld_file, 262144, $plugins_root );
		$plugin_bytes = $this->read_bounded( $plugin_file, 262144, $plugin_root );

		if ( self::WORDPRESS_VERSION !== $this->wordpress_version( $wp_bytes )
			|| self::LEARNDASH_VERSION !== $this->header_version( $ld_bytes )
			|| self::PLUGIN_VERSION !== $this->header_version( $plugin_bytes )
			|| ! defined( 'LEARNDASH_VERSION' ) || self::LEARNDASH_VERSION !== LEARNDASH_VERSION
			|| ! class_exists( 'GHCA_Admin_Compliance_Dashboard', false )
			|| self::PLUGIN_VERSION !== GHCA_Admin_Compliance_Dashboard::VERSION ) {
			$this->fail();
		}

		return array(
			'wordpress_version' => self::WORDPRESS_VERSION,
			'learndash_version' => self::LEARNDASH_VERSION,
			'plugin_version'    => self::PLUGIN_VERSION,
		);
	}

	private function read_bounded( string $path, int $maximum, string $root ): string {
		$real_root = realpath( $root );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path || is_link( $path ) || ! is_file( $real_path )
			|| ! $this->contained( $real_path, $real_root ) ) {
			$this->fail();
		}
		$bytes = file_get_contents( $real_path, false, null, 0, $maximum + 1 );
		if ( false === $bytes || strlen( $bytes ) > $maximum ) {
			$this->fail();
		}
		return $bytes;
	}

	private function contained( string $path, string $root ): bool {
		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path . '/', $root . '/' );
	}

	private function header_version( string $bytes ): string {
		$count = preg_match_all( '/^[ \t]*\\*?[ \t]*Version:[ \t]*([^\r\n]+)\r?$/mi', $bytes, $matches );
		if ( 1 !== $count || trim( $matches[1][0] ) !== $matches[1][0] ) {
			$this->fail();
		}
		return $matches[1][0];
	}

	private function wordpress_version( string $bytes ): string {
		try {
			$tokens = token_get_all( $bytes, TOKEN_PARSE );
		} catch ( Throwable $error ) {
			$this->fail();
		}
		$positions = array();
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_VARIABLE === $token[0] && '$wp_version' === $token[1] ) {
				$positions[] = $index;
			}
		}
		if ( 1 !== count( $positions ) ) {
			$this->fail();
		}
		$meaningful = array();
		for ( $index = $positions[0] + 1, $count = count( $tokens ); $index < $count; $index++ ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$meaningful[] = $token;
			if ( ';' === $token ) {
				break;
			}
		}
		if ( 3 !== count( $meaningful ) || '=' !== $meaningful[0] || ';' !== $meaningful[2]
			|| ! is_array( $meaningful[1] ) || T_CONSTANT_ENCAPSED_STRING !== $meaningful[1][0]
			|| ! in_array( $meaningful[1][1], array( "'" . self::WORDPRESS_VERSION . "'", '"' . self::WORDPRESS_VERSION . '"' ), true ) ) {
			$this->fail();
		}
		return self::WORDPRESS_VERSION;
	}

	private function fail(): void {
		throw new UnexpectedValueException( 'archive_runtime_attestation_failed' );
	}
}
