<?php
/**
 * Job store for the async compliance-packet builder.
 *
 * A "job" is one packet build for one employee: a manifest transient
 * (owner, target user, tracker type, certificate URLs) plus a per-job temp
 * folder for fetched certificates and a shared folder for finished packets.
	 * Both folders live under the operating-system temp directory, outside the
	 * WordPress document root; the finished packet is only ever streamed through the
 * permission-checked download endpoint in GHCA_Audit_PDF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_PDF_Jobs {

	const TRANSIENT_PREFIX = 'ghca_acd_pdf_job_';
	const OWNER_TRANSIENT_PREFIX = 'ghca_acd_pdf_owner_job_';
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

	/** Validates the one-time server-to-server certificate broker request. */
	public static function validate_broker_request( $manifest, int $index, string $token, int $now ) {
		if ( ! is_array( $manifest ) || self::is_expired( (int) ( $manifest['created'] ?? 0 ), $now ) ) {
			return 'not_found';
		}
		if (
			$index < 0
			|| ! isset( $manifest['urls'][ $index ], $manifest['course_ids'][ $index ] )
			|| (int) $manifest['course_ids'][ $index ] <= 0
			|| empty( $manifest['brokerable'][ $index ] )
		) {
			return 'invalid_index';
		}
		$expected = (string) ( $manifest['broker_tokens'][ $index ] ?? '' );
		if (
			! preg_match( '/^[a-f0-9]{64}$/', $token )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $expected )
			|| ! hash_equals( $expected, $token )
		) {
			return 'token_mismatch';
		}
		if ( ! empty( $manifest['broker_used'][ $index ] ) ) {
			return 'replayed';
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------- */

	public static function temp_base(): string {
		return self::private_base() . '/' . self::TEMP_DIR_NAME;
	}

	public static function packets_base(): string {
		return self::private_base() . '/' . self::PACKETS_DIR_NAME;
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
	public static function create_job( int $owner_id, int $user_id, string $tracker, array $urls, string $filename, array $course_ids = array(), array $brokerable = array(), array $course_keys = array(), array $evidence_refs = array(), array $report_snapshot = array() ): string {
		$lock = self::acquire_owner_lock( $owner_id );
		if ( false === $lock ) {
			throw new LogicException( 'A packet job is already starting. Please try again.' );
		}

		try {
			$previous_job_id = get_transient( self::OWNER_TRANSIENT_PREFIX . $owner_id );
			if ( is_string( $previous_job_id ) && self::is_valid_job_id( $previous_job_id ) ) {
				$previous_lock = self::acquire_job_lock( $previous_job_id );
				if ( false === $previous_lock ) {
					throw new LogicException( 'The active packet job is still processing.' );
				}
				self::delete_job( $previous_job_id );
				self::release_lock( $previous_lock );
			}

			$job_id = bin2hex( random_bytes( 16 ) );

			self::ensure_dir( self::temp_base() );
			self::ensure_dir( self::packets_base() );
			self::create_job_dir( $job_id );

			$course_ids = array_slice(
				array_pad( array_map( 'intval', $course_ids ), count( $urls ), 0 ),
				0,
				count( $urls )
			);
			$brokerable = array_slice(
				array_pad( array_map( 'boolval', $brokerable ), count( $urls ), false ),
				0,
				count( $urls )
			);
			$course_keys = array_slice( array_pad( array_map( 'strval', $course_keys ), count( $urls ), '' ), 0, count( $urls ) );
			$evidence_refs = array_slice( array_pad( $evidence_refs, count( $urls ), null ), 0, count( $urls ) );
			$broker_tokens = array_map(
				static function ( bool $can_broker ): string {
					return $can_broker ? bin2hex( random_bytes( 32 ) ) : '';
				},
				$brokerable
			);

			set_transient(
				self::TRANSIENT_PREFIX . $job_id,
				array(
					'owner'        => $owner_id,
					'user_id'      => $user_id,
					'tracker'      => $tracker,
					'urls'         => array_values( $urls ),
					'course_ids'   => $course_ids,
					'brokerable'   => $brokerable,
					'broker_tokens' => $broker_tokens,
					'broker_used'  => array(),
					'course_keys'  => $course_keys,
					'evidence_refs'=> $evidence_refs,
					'report_snapshot' => $report_snapshot,
					'filename'     => $filename,
					'created'      => time(),
				),
				self::TTL
			);
			set_transient( self::OWNER_TRANSIENT_PREFIX . $owner_id, $job_id, self::TTL );

			return $job_id;
		} finally {
			self::release_lock( $lock );
		}
	}

	/** @return array|WP_Error The manifest, or a WP_Error safe to send to the client. */
	public static function get_job( string $job_id, int $owner_id ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'ghca_pdf_bad_job', __( 'Invalid job reference.', 'ghca-acd' ) );
		}

		$manifest = get_transient( self::TRANSIENT_PREFIX . $job_id );
		$verdict  = self::validate_manifest( $manifest, $owner_id, time() );
		if ( true === $verdict && get_transient( self::OWNER_TRANSIENT_PREFIX . $owner_id ) !== $job_id ) {
			$verdict = 'not_found';
		}
		if ( true !== $verdict ) {
			return new WP_Error( 'ghca_pdf_' . $verdict, __( 'This download job is no longer available. Please start again.', 'ghca-acd' ) );
		}

		return $manifest;
	}

	/** Claims a certificate broker token once and returns its job manifest. */
	public static function claim_broker_job( string $job_id, int $index, string $token ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'ghca_pdf_broker_denied', __( 'Certificate request is unavailable.', 'ghca-acd' ) );
		}

		$lock = self::acquire_lock( self::lock_path( 'broker_' . $job_id . '_' . max( 0, $index ) ), false );
		if ( false === $lock ) {
			return new WP_Error( 'ghca_pdf_broker_busy', __( 'Certificate request is already being processed.', 'ghca-acd' ) );
		}

		try {
			$manifest = get_transient( self::TRANSIENT_PREFIX . $job_id );
			if (
				is_array( $manifest )
				&& isset( $manifest['owner'] )
				&& get_transient( self::OWNER_TRANSIENT_PREFIX . (int) $manifest['owner'] ) !== $job_id
			) {
				$manifest = false;
			}
			$verdict  = self::validate_broker_request( $manifest, $index, $token, time() );
			if ( true !== $verdict ) {
				return new WP_Error( 'ghca_pdf_broker_denied', __( 'Certificate request is unavailable.', 'ghca-acd' ) );
			}

			$manifest['broker_used'][ $index ] = true;
			$remaining = max( 1, self::TTL - ( time() - (int) $manifest['created'] ) );
			if ( ! set_transient( self::TRANSIENT_PREFIX . $job_id, $manifest, $remaining ) ) {
				return new WP_Error( 'ghca_pdf_broker_denied', __( 'Certificate request is unavailable.', 'ghca-acd' ) );
			}
			return $manifest;
		} finally {
			self::release_lock( $lock );
		}
	}

	/** @return bool True when the job record, folder and packet are all gone. */
	public static function delete_job( string $job_id ): bool {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			self::log_failure( 'invalid_job_id', $job_id );
			return false;
		}
		$manifest = get_transient( self::TRANSIENT_PREFIX . $job_id );
		delete_transient( self::TRANSIENT_PREFIX . $job_id );
		if ( is_array( $manifest ) && isset( $manifest['owner'] ) ) {
			$owner_key = self::OWNER_TRANSIENT_PREFIX . (int) $manifest['owner'];
			if ( get_transient( $owner_key ) === $job_id ) {
				delete_transient( $owner_key );
			}
		}
		$ok = self::rmdir_recursive( self::temp_dir( $job_id ) );
		$packet = self::packet_path( $job_id );
		if ( file_exists( $packet ) && ! self::remove_file( $packet ) ) {
			self::log_failure( 'packet_unlink_failed', $job_id );
			$ok = false;
		}
		return $ok;
	}

	/**
	 * Deletes orphaned temp folders, finished packets and stale lock files older than TTL.
	 * Cheap; runs on every packet start and on wp_scheduled_delete.
	 *
	 * Only touches folders whose name is a valid job id and that have no live job
	 * record, owner pointer or held lock. Owner locks are never expired here.
	 */
	public static function gc(): void {
		$now = time();
		try {
			$temp_base = self::temp_base();
			$packets_base = self::packets_base();
		} catch ( RuntimeException $exception ) {
			return;
		}

		$temp_entries = self::list_entries( $temp_base );
		$packet_entries = self::list_entries( $packets_base );
		if ( null === $temp_entries || null === $packet_entries ) {
			self::log_failure( 'scandir_failed', 'storage_base' );
		}

		foreach ( (array) $temp_entries as $name ) {
			$dir = $temp_base . '/' . $name;
			if ( ! self::is_valid_job_id( $name ) || is_link( $dir ) || ! is_dir( $dir ) ) {
				continue;
			}
			if ( self::job_is_live( $name ) ) {
				continue;
			}
			$mtime = self::mtime( $dir, $name );
			if ( false === $mtime || ! self::is_expired( $mtime, $now ) ) {
				continue;
			}
			$lock = self::acquire_job_lock( $name );
			if ( false === $lock ) {
				continue; // A request is still working on it.
			}
			if ( self::rmdir_recursive( $dir ) && is_file( self::lock_path( 'job_' . $name ) ) && ! self::remove_file( self::lock_path( 'job_' . $name ) ) ) {
				self::log_failure( 'lock_unlink_failed', $name );
			}
			self::release_lock( $lock );
		}

		foreach ( (array) $packet_entries as $name ) {
			$file = $packets_base . '/' . $name;
			if ( is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}
			if ( preg_match( '/^([a-f0-9]{32})_packet\.pdf$/D', $name, $m ) ) {
				$mtime = self::mtime( $file, $m[1] );
				if ( false !== $mtime && self::is_expired( $mtime, $now ) && ! self::remove_file( $file ) ) {
					self::log_failure( 'packet_unlink_failed', $m[1] );
				}
			} elseif ( preg_match( '/^(?:job_([a-f0-9]{32})|broker_([a-f0-9]{32})_[0-9]+)\.lock$/D', $name, $m ) ) {
				// Owner locks (owner_*.lock) are deliberately never matched.
				$job_id = '' !== $m[1] ? $m[1] : ( $m[2] ?? '' );
				if ( self::job_is_live( $job_id ) || is_dir( self::temp_dir( $job_id ) ) ) {
					continue;
				}
				$mtime = self::mtime( $file, $job_id );
				if ( false === $mtime || ! self::is_expired( $mtime, $now ) ) {
					continue;
				}
				$handle = self::quietly( static function () use ( $file ) {
					return fopen( $file, 'c+b' );
				} );
				if ( false === $handle ) {
					self::log_failure( 'lock_open_failed', $job_id );
					continue;
				}
				if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
					if ( ! self::remove_file( $file ) ) {
						self::log_failure( 'lock_unlink_failed', $job_id );
					}
				}
				self::release_lock( $handle );
			}
		}
	}

	/** A job is live while its manifest transient exists (the owner pointer shares its TTL). */
	private static function job_is_live( string $job_id ): bool {
		$manifest = get_transient( self::TRANSIENT_PREFIX . $job_id );
		return false !== $manifest && null !== $manifest;
	}

	/** @return resource|false */
	public static function acquire_job_lock( string $job_id, bool $blocking = false ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return false;
		}
		return self::acquire_lock( self::lock_path( 'job_' . $job_id ), $blocking );
	}

	/** @param resource $lock */
	public static function release_lock( $lock ): void {
		if ( is_resource( $lock ) ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	public static function secure_file( string $path ): bool {
		if ( ! is_file( $path ) || ! @chmod( $path, 0600 ) ) {
			return false;
		}
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return true;
		}
		clearstatcache( true, $path );
		return 0600 === ( fileperms( $path ) & 0777 );
	}

	/* ---------------------------------------------------------------------
	 * Filesystem plumbing
	 * ------------------------------------------------------------------- */

	/** Creates a base dir and drops deny-all protection files into it. */
	private static function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! @chmod( $dir, 0700 ) ) {
			throw new RuntimeException( 'Private packet storage is unavailable.' );
		}
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			clearstatcache( true, $dir );
			if ( 0700 !== ( fileperms( $dir ) & 0777 ) ) {
				throw new RuntimeException( 'Private packet storage permissions are unsafe.' );
			}
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

	private static function private_base(): string {
		if ( defined( 'GHCA_ACD_PRIVATE_DIR' ) && is_string( GHCA_ACD_PRIVATE_DIR ) && '' !== trim( GHCA_ACD_PRIVATE_DIR ) ) {
			$configured = realpath( GHCA_ACD_PRIVATE_DIR );
			if ( false === $configured ) {
				throw new RuntimeException( 'GHCA_ACD_PRIVATE_DIR must already exist.' );
			}
			$configured = rtrim( wp_normalize_path( $configured ), '/' );
			$blocked    = array( realpath( ABSPATH ) );
			$uploads    = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
			if ( ! empty( $uploads['basedir'] ) ) {
				$blocked[] = realpath( $uploads['basedir'] );
			}
			foreach ( $blocked as $root ) {
				if ( false !== $root && self::path_is_within( $configured, wp_normalize_path( $root ) ) ) {
					throw new RuntimeException( 'GHCA_ACD_PRIVATE_DIR must be outside WordPress and uploads.' );
				}
			}
			if ( '\\' === DIRECTORY_SEPARATOR && ( ! defined( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED' ) || true !== GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED ) ) {
				throw new RuntimeException( 'Set GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED only after applying an owner-only Windows ACL.' );
			}
			return $configured;
		}
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			throw new RuntimeException( 'Define GHCA_ACD_PRIVATE_DIR as an ACL-protected directory outside the web root.' );
		}
		$site_key = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
		return rtrim( wp_normalize_path( sys_get_temp_dir() ), '/' ) . '/ghca-compliance-' . $site_key;
	}

	private static function path_is_within( string $path, string $root ): bool {
		$path = rtrim( wp_normalize_path( $path ), '/' ) . '/';
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path, $root );
	}

	private static function lock_path( string $name ): string {
		return self::packets_base() . '/' . $name . '.lock';
	}

	/** @return resource|false */
	private static function acquire_owner_lock( int $owner_id ) {
		return self::acquire_lock( self::lock_path( 'owner_' . max( 0, $owner_id ) ), true );
	}

	/** @return resource|false */
	private static function acquire_lock( string $path, bool $blocking ) {
		self::ensure_dir( dirname( $path ) );
		$lock = @fopen( $path, 'c+b' );
		if ( false === $lock || ! flock( $lock, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return false;
		}
		return $lock;
	}

	/** Creates one private per-job folder. Protection comes from the already-verified parent. */
	private static function create_job_dir( string $job_id ): void {
		$base = self::temp_base();
		// Fail closed: never create a job folder unless the parent's deny-all files exist.
		if ( ! is_file( $base . '/.htaccess' ) || ! is_file( $base . '/index.html' ) ) {
			throw new RuntimeException( 'Private packet storage protection is missing.' );
		}
		$dir = self::temp_dir( $job_id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! @chmod( $dir, 0700 ) ) {
			throw new RuntimeException( 'Private packet storage is unavailable.' );
		}
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			clearstatcache( true, $dir );
			if ( 0700 !== ( fileperms( $dir ) & 0777 ) ) {
				throw new RuntimeException( 'Private packet storage permissions are unsafe.' );
			}
		}
	}

	/**
	 * Runs one filesystem call with its expected E_WARNING contained, so PHP never prints or
	 * logs a message that embeds a private-storage path. Callers must check the return value
	 * and report failure through log_failure(), which carries only a code and a short hash.
	 * Other error levels still reach the normal handler; the previous handler is restored.
	 *
	 * @return mixed
	 */
	private static function quietly( callable $operation ) {
		set_error_handler(
			static function ( int $errno ): bool {
				return E_WARNING === $errno;
			}
		);
		try {
			return $operation();
		} finally {
			restore_error_handler();
		}
	}

	/** @return array<int,string>|null Entry names excluding . and .. (hidden files included); null if unreadable. */
	private static function list_entries( string $dir ): ?array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$entries = self::quietly( static function () use ( $dir ) {
			return scandir( $dir );
		} );
		if ( ! is_array( $entries ) ) {
			return null;
		}
		return array_values( array_diff( $entries, array( '.', '..' ) ) );
	}

	/** @return int|false */
	private static function mtime( string $path, string $job_id ) {
		clearstatcache( true, $path );
		$mtime = self::quietly( static function () use ( $path ) {
			return filemtime( $path );
		} );
		if ( false === $mtime ) {
			self::log_failure( 'mtime_unreadable', $job_id );
		}
		return $mtime;
	}

	/** Unlinks a file or symlink (never following it). */
	private static function remove_file( string $path ): bool {
		if ( self::forced_failure( 'unlink' ) ) {
			return false;
		}
		return true === self::quietly( static function () use ( $path ) {
			return unlink( $path );
		} );
	}

	private static function remove_dir( string $path ): bool {
		if ( self::forced_failure( 'rmdir' ) ) {
			return false;
		}
		return true === self::quietly( static function () use ( $path ) {
			return rmdir( $path );
		} );
	}

	/** Test seam: lets tests simulate an unremovable path without needing OS permissions. */
	private static function forced_failure( string $operation ): bool {
		return function_exists( 'apply_filters' ) && true === apply_filters( 'ghca_acd_pdf_jobs_force_fs_failure', false, $operation );
	}

	/**
	 * Removes one per-job temp folder and everything in it, hidden files included.
	 * Refuses anything that is not a direct child of the temp base named by a valid job id.
	 */
	private static function rmdir_recursive( string $dir ): bool {
		$job_id = basename( $dir );
		if ( ! self::is_valid_job_id( $job_id ) ) {
			self::log_failure( 'rmdir_refused', $job_id );
			return false;
		}
		try {
			$base = self::temp_base();
		} catch ( RuntimeException $exception ) {
			self::log_failure( 'rmdir_refused', $job_id );
			return false;
		}
		if ( wp_normalize_path( dirname( $dir ) ) !== wp_normalize_path( $base ) ) {
			self::log_failure( 'rmdir_refused', $job_id );
			return false;
		}
		if ( ! is_link( $dir ) && ! file_exists( $dir ) ) {
			return true;
		}
		if ( ! is_link( $dir ) ) {
			$real_base = realpath( $base );
			$real_dir  = realpath( $dir );
			if ( false === $real_base || false === $real_dir || wp_normalize_path( dirname( $real_dir ) ) !== wp_normalize_path( $real_base ) ) {
				self::log_failure( 'rmdir_refused', $job_id );
				return false;
			}
		}
		if ( ! self::remove_tree( $dir ) ) {
			self::log_failure( 'rmdir_failed', $job_id );
			return false;
		}
		return true;
	}

	private static function remove_tree( string $path ): bool {
		if ( is_link( $path ) ) {
			// Remove the link itself; a directory symlink on Windows needs rmdir.
			return self::remove_file( $path ) || ( is_dir( $path ) && self::remove_dir( $path ) );
		}
		if ( ! is_dir( $path ) ) {
			return self::remove_file( $path );
		}
		$entries = self::list_entries( $path );
		if ( null === $entries ) {
			return false;
		}
		$ok = true;
		foreach ( $entries as $name ) {
			$ok = self::remove_tree( $path . '/' . $name ) && $ok;
		}
		return $ok && self::remove_dir( $path );
	}

	/** Internal log line: error code and a short job-id hash only; never paths or user data. */
	private static function log_failure( string $code, string $job_id ): void {
		$hash = substr( hash( 'sha256', $job_id ), 0, 10 );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'ghca_acd_pdf_jobs_failure', $code, $hash );
		}
		error_log( 'ghca_acd packet jobs: ' . $code . ' job=' . $hash );
	}
}
