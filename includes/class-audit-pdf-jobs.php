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
		if ( (int) $manifest['owner'] !== $owner_id ) {
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
