<?php
/**
 * Resumable Jotform metadata synchronization. Source document bytes remain remote.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Jotform_Sync {
	const CRON_HOOK       = 'ghca_acd_jotform_hourly_sync';
	const LOCK_KEY        = 'ghca_acd_jotform_sync_lock';
	const PAGE_SIZE       = 50;
	const CRON_PAGE_LIMIT = 2;
	const MANUAL_PAGE_LIMIT = 10;
	const CLAIM_TTL = 43200;
	const CLAIM_PURPOSE = 'external_training';
	const CURSOR_OVERLAP_SECONDS = 1;
	const INDEXER_VERSION = 3;
	/** @var array{fingerprint:string,warning:string} */
	private static $last_index_result = array( 'fingerprint' => '', 'warning' => '' );

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 25 );
	}

	public static function ensure_schedule(): void {
		if ( GHCA_ACD_Jotform_Repository::enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function cron(): void {
		self::run( false );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function run( bool $manual = false ) {
		if ( ! GHCA_ACD_Jotform_Repository::enabled() ) {
			return new WP_Error( 'ghca_jotform_disabled', __( 'Jotform synchronization is disabled.', 'ghca-acd' ) );
		}
		if ( GHCA_ACD_Jotform_Provider::quota_paused() ) {
			update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_ERROR, 'quota_paused', false );
			return new WP_Error( 'ghca_jotform_quota_paused', __( 'Synchronization is paused because 100 or fewer Jotform calls remain.', 'ghca-acd' ) );
		}
		if ( ! self::acquire_lock() ) {
			return new WP_Error( 'ghca_jotform_sync_busy', __( 'A Jotform synchronization is already running.', 'ghca-acd' ) );
		}
		try {
			$state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() );
			$state = is_array( $state ) ? $state : array();
			$processed = 0;
			$read = 0;
			$failed = 0;
			$metrics = array( 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'quarantined' => 0, 'failed' => 0 );
			$pages = 0;
			$page_limit = $manual ? self::MANUAL_PAGE_LIMIT : self::CRON_PAGE_LIMIT;
			foreach ( GHCA_ACD_Jotform_Repository::forms() as $form ) {
				if ( $pages >= $page_limit || GHCA_ACD_Jotform_Provider::quota_paused() ) {
					break;
				}
				$form_id = (string) $form['form_id'];
				$form_state = is_array( $state[ $form_id ] ?? null ) ? $state[ $form_id ] : array();
				if ( (int) ( $form_state['indexer_version'] ?? 0 ) !== self::INDEXER_VERSION ) {
					$form_state = array( 'indexer_version' => self::INDEXER_VERSION );
				}
				$mode = in_array( (string) ( $form_state['mode'] ?? 'backfill' ), array( 'backfill', 'incremental' ), true ) ? (string) ( $form_state['mode'] ?? 'backfill' ) : 'backfill';
				$after_id = isset( $form_state['after_id'] ) && preg_match( '/^[0-9]{6,32}$/', (string) $form_state['after_id'] ) ? (string) $form_state['after_id'] : '';
				unset( $form_state['offset'], $form_state['high_water'] );
				if ( 'backfill' === $mode ) {
					if ( empty( $form_state['backfill_started_at'] ) ) {
						$form_state['backfill_started_at'] = self::jotform_now();
					}
					$updated_after = '';
					$updated_before = '';
				} else {
					$updated_after = (string) ( $form_state['cursor'] ?? '' );
					if ( empty( $form_state['window_end'] ) ) {
						$form_state['window_end'] = self::jotform_now();
					}
					$updated_before = (string) $form_state['window_end'];
				}

				do {
					$previous_after_id = $after_id;
					$response = GHCA_ACD_Jotform_Provider::get_form_submissions( $form_id, $after_id, self::PAGE_SIZE, $updated_after, $updated_before );
					if ( is_wp_error( $response ) ) {
						update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_ERROR, sanitize_key( $response->get_error_code() ), false );
						return $response;
					}
					$submissions = is_array( $response['content'] ?? null ) ? $response['content'] : array();
					foreach ( $submissions as $submission ) {
						if ( is_array( $submission ) ) {
							$read++;
							$submission_id = (string) ( $submission['id'] ?? '' );
							$before = GHCA_ACD_Jotform_Repository::submission_fingerprints( $form_id, $submission_id );
							if ( ! self::index_submission( $form, $submission ) ) {
								$failed++;
								$metrics['failed']++;
								$error = new WP_Error( 'ghca_jotform_index_failed', __( 'A Jotform submission could not be indexed safely. Synchronization will retry it.', 'ghca-acd' ) );
								$metrics['read'] = $read; $metrics['indexed'] = $processed; $metrics['pages'] = $pages;
								update_option( 'ghca_acd_jotform_last_metrics', $metrics, false );
								update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_ERROR, $error->get_error_code(), false );
								return $error;
							}
							$result = self::$last_index_result;
							if ( '' !== $result['warning'] ) { $metrics['quarantined']++; }
							elseif ( empty( $before ) ) { $metrics['new']++; }
							elseif ( in_array( $result['fingerprint'], $before, true ) ) { $metrics['unchanged']++; }
							else { $metrics['updated']++; }
							$after_id = self::later_submission_id( $after_id, (string) ( $submission['id'] ?? '' ) );
							$processed++;
						}
					}
					$pages++;
					if ( count( $submissions ) === self::PAGE_SIZE ) {
						if ( '' === $after_id || $after_id === $previous_after_id ) {
							$error = new WP_Error( 'ghca_jotform_cursor_stalled', __( 'Jotform synchronization could not advance its stable cursor.', 'ghca-acd' ) );
							update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_ERROR, $error->get_error_code(), false );
							return $error;
						}
						$form_state['after_id'] = $after_id;
					} elseif ( 'backfill' === $mode ) {
						$mode = 'incremental';
						$form_state = array(
							'mode'              => 'incremental',
							'indexer_version'   => self::INDEXER_VERSION,
							'cursor'            => self::cursor_with_overlap( (string) $form_state['backfill_started_at'] ),
							'after_id'          => '',
							'backfill_complete' => 1,
						);
					} else {
						$form_state['cursor'] = self::cursor_with_overlap( $updated_before );
						$form_state['after_id'] = '';
						unset( $form_state['window_end'] );
					}
					$form_state['mode'] = $mode;
					$form_state['last_run'] = current_time( 'mysql', true );
					$state[ $form_id ] = $form_state;
					update_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, $state, false );
				} while ( count( $submissions ) === self::PAGE_SIZE && $pages < $page_limit && ! GHCA_ACD_Jotform_Provider::quota_paused() );
			}
			update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_SYNC, current_time( 'mysql', true ), false );
			update_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_ERROR, '', false );
			$metrics['read'] = $read; $metrics['indexed'] = $processed; $metrics['pages'] = $pages;
			update_option( 'ghca_acd_jotform_last_metrics', $metrics, false );
			return array( 'processed' => $processed, 'pages' => $pages, 'quota_left' => get_option( GHCA_ACD_Jotform_Provider::OPTION_QUOTA_LEFT, null ), 'state' => $state, 'metrics' => $metrics );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Acquire a connection-scoped MySQL/MariaDB advisory lock without waiting.
	 * The lock is held from remote fetch through cursor and revision persistence.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		try {
			return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_KEY ) );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** Release this request's connection-scoped advisory lock after every outcome. */
	private static function release_lock(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_KEY ) );
		} catch ( Throwable $error ) {
			// Connection closure also releases advisory locks; never mask the sync outcome.
		}
	}

	/** Reset cursor state only; indexed records, evidence and append-only events stay intact. */
	public static function reset_historical_backfill(): void {
		$state = array();
		foreach ( GHCA_ACD_Jotform_Repository::forms() as $form ) {
			$state[ (string) $form['form_id'] ] = array( 'mode' => 'backfill', 'indexer_version' => self::INDEXER_VERSION );
		}
		update_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, $state, false );
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission */
	public static function index_submission( array $form, array $submission ): bool {
		$submission_id = (string) ( $submission['id'] ?? '' );
		if ( ! preg_match( '/^[0-9]{6,32}$/', $submission_id ) ) {
			return false;
		}
		$ownership = self::verified_ownership( $form, $submission );
		$user_id = (int) $ownership['user_id'];
		$employee = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $employee ) {
			$user_id = 0;
		}
		$title = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['course_title_qid'] ?? '' ) ) );
		$code  = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['training_code_qid'] ?? '' ) ) );
		$date  = self::date_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['completion_date_qid'] ?? '' ) ) );
		$file_qid = (string) ( $form['certificate_qid'] ?? '' );
		$file_answer = GHCA_ACD_Jotform_Provider::answer( $submission, $file_qid );
		$file_candidates = GHCA_ACD_Jotform_Provider::file_candidates( $file_answer );
		$urls  = GHCA_ACD_Jotform_Provider::file_urls( $file_answer );
		$drive_names = 'google_drive' === (string) ( $form['file_source'] ?? 'jotform' ) ? GHCA_ACD_Google_Drive_Provider::jotform_file_names( $file_answer ) : array();
		$evidence_reference = 'google_drive' === (string) ( $form['file_source'] ?? '' ) ? GHCA_ACD_Google_Drive_Provider::evidence_reference( $form, $submission ) : $submission_id;
		$reference_error = is_wp_error( $evidence_reference ) ? $evidence_reference : null;
		if ( $reference_error ) { $evidence_reference = ''; }
		$source_updated = self::api_datetime( (string) ( $submission['updated_at'] ?? $submission['created_at'] ?? '' ) );
		$source_created = self::api_datetime( (string) ( $submission['created_at'] ?? $source_updated ) );
		$fingerprint = self::submission_fingerprint( $form, $submission );
		$document_rows = array();
		$file_names = 'google_drive' === (string) ( $form['file_source'] ?? 'jotform' ) && is_array( $drive_names ) ? $drive_names : array_map( static function ( string $url ): string { return sanitize_file_name( rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ); }, $urls );
		foreach ( $file_names as $index => $name ) {
			$document_rows[] = array(
				'employee_user_id' => $user_id,
				'form_id' => (string) $form['form_id'],
				'submission_id' => $submission_id,
				'source_fingerprint' => $fingerprint,
				'evidence_reference' => (string) $evidence_reference,
				'question_id' => $file_qid,
				'file_index' => $index,
				'form_title' => (string) ( $form['label'] ?? '' ),
				'field_label' => __( 'Course completion certificate', 'ghca-acd' ),
				'file_name' => $name,
				'media_type' => self::mime_from_name( $name ),
				'file_size' => 0,
				'source_created_at' => $source_created,
				'source_updated_at' => $source_updated,
			);
		}
		$match = GHCA_ACD_Jotform_Repository::catalog_match_result( $code, $title );
		$catalog = $match['item'];
		$warning = '';
		if ( '' !== (string) $ownership['warning'] ) {
			$warning = (string) $ownership['warning'];
		} elseif ( ! $catalog ) {
			$warning = 'ambiguous' === $match['status'] ? 'course_ambiguous' : 'course_unmapped';
		} elseif ( ! $date ) {
			$warning = 'completion_date_invalid';
		} elseif ( $reference_error ) {
			$warning = sanitize_key( $reference_error->get_error_code() );
		} elseif ( is_wp_error( $drive_names ) ) {
			$warning = sanitize_key( $drive_names->get_error_code() );
		} elseif ( 'google_drive' === (string) ( $form['file_source'] ?? 'jotform' ) && empty( $file_names ) ) {
			$warning = 'certificate_missing';
		} elseif ( 'jotform' === (string) ( $form['file_source'] ?? 'jotform' ) && count( $file_candidates ) !== count( $urls ) ) {
			$warning = 'malformed_file';
		} elseif ( 'jotform' === (string) ( $form['file_source'] ?? 'jotform' ) && empty( $urls ) ) {
			$warning = 'certificate_missing';
		} elseif ( self::identity_mismatch( $form, $submission, $employee ) ) {
			$warning = 'identity_mismatch';
		} elseif ( GHCA_ACD_Jotform_Repository::probable_duplicate_exists( $user_id, (int) $catalog['id'], (string) $date, $submission_id ) ) {
			$warning = 'probable_duplicate';
		}
		$candidate = array(
			'employee_user_id' => $user_id,
			'form_id' => (string) $form['form_id'],
			'submission_id' => $submission_id,
			'source_fingerprint' => $fingerprint,
			'catalog_id' => $catalog ? (int) $catalog['id'] : null,
			'proposed_code' => $code,
			'proposed_title' => $title,
			'proposed_completion_date' => $date ?: '',
			'warning_code' => $warning,
			'warning_detail' => 'identity_mismatch' === $warning ? self::identity_mismatch_detail( $form, $submission, $employee ) : '',
		);
		$ok = GHCA_ACD_Jotform_Repository::persist_indexed_revision( $document_rows, $candidate );
		if ( $ok && 'identity_mismatch' === $warning && '' !== (string) $candidate['warning_detail'] ) { GHCA_ACD_Jotform_Repository::enrich_identity_warning_detail( (string) $form['form_id'], $submission_id, $fingerprint, (string) $candidate['warning_detail'] ); }
		self::$last_index_result = array( 'fingerprint' => $fingerprint, 'warning' => $warning );
		return $ok;
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission */
	public static function submission_fingerprint( array $form, array $submission ): string {
		$submission_id = (string) ( $submission['id'] ?? '' );
		$ownership = self::verified_ownership( $form, $submission );
		$user_id = (int) $ownership['user_id'];
		$title = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['course_title_qid'] ?? '' ) ) );
		$code = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['training_code_qid'] ?? '' ) ) );
		$date = self::date_answer( GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['completion_date_qid'] ?? '' ) ) );
		$file_answer = GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['certificate_qid'] ?? '' ) );
		$urls = GHCA_ACD_Jotform_Provider::file_urls( $file_answer );
		$source_updated = self::api_datetime( (string) ( $submission['updated_at'] ?? $submission['created_at'] ?? '' ) );
		if ( 'google_drive' !== (string) ( $form['file_source'] ?? 'jotform' ) ) {
			return hash( 'sha256', wp_json_encode( array( $submission_id, $user_id, (string) $ownership['claim_digest'], $title, $code, $date, $urls, $source_updated ) ) );
		}
		$names = GHCA_ACD_Google_Drive_Provider::jotform_file_names( $file_answer );
		$reference = GHCA_ACD_Google_Drive_Provider::evidence_reference( $form, $submission );
		$reference = is_wp_error( $reference ) ? 'invalid:' . sanitize_key( $reference->get_error_code() ) : $reference;
		return hash( 'sha256', wp_json_encode( array( $submission_id, $user_id, (string) $ownership['claim_digest'], $title, $code, $date, is_array( $names ) ? $names : array(), (string) $reference, (string) ( $form['drive_folder_id'] ?? '' ), $source_updated ) ) );
	}

	public static function create_ownership_claim( int $user_id, string $form_id, ?int $issued_at = null ): string {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! preg_match( '/^[0-9]{6,32}$/', $form_id ) ) {
			return '';
		}
		$issued_at = null === $issued_at ? time() : $issued_at;
		$payload = array(
			'v'   => 1,
			'p'   => self::CLAIM_PURPOSE,
			'f'   => $form_id,
			'u'   => $user_id,
			's'   => self::site_binding(),
			'iat' => $issued_at,
			'exp' => $issued_at + self::CLAIM_TTL,
		);
		$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );
		$signature = hash_hmac( 'sha256', $encoded, self::claim_signing_key(), true );
		return $encoded . '.' . self::base64url_encode( $signature );
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission @return array{user_id:int,warning:string,claim_digest:string} */
	public static function verified_ownership( array $form, array $submission ): array {
		$user_raw = GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['user_id_qid'] ?? '' ) );
		$user_id = is_scalar( $user_raw ) && preg_match( '/^[1-9][0-9]*$/', trim( (string) $user_raw ) ) ? (int) $user_raw : 0;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return array( 'user_id' => 0, 'warning' => 'employee_unresolved', 'claim_digest' => '' );
		}
		$claim_qid = (string) ( $form['ownership_claim_qid'] ?? '' );
		$claim_raw = '' !== $claim_qid ? GHCA_ACD_Jotform_Provider::answer( $submission, $claim_qid ) : '';
		$claim = is_scalar( $claim_raw ) ? trim( (string) $claim_raw ) : '';
		if ( '' === $claim ) {
			return array( 'user_id' => 0, 'warning' => 'ownership_claim_missing', 'claim_digest' => '' );
		}
		$parts = explode( '.', $claim );
		if ( 2 !== count( $parts ) || strlen( $claim ) > 2048 ) {
			return array( 'user_id' => 0, 'warning' => 'ownership_claim_invalid', 'claim_digest' => hash( 'sha256', $claim ) );
		}
		$payload_json = self::base64url_decode( $parts[0] );
		$signature = self::base64url_decode( $parts[1] );
		$payload = false !== $payload_json ? json_decode( $payload_json, true ) : null;
		$expected_signature = hash_hmac( 'sha256', $parts[0], self::claim_signing_key(), true );
		$created_at = self::jotform_timestamp( (string) ( $submission['created_at'] ?? '' ) );
		$valid = is_array( $payload )
			&& false !== $signature
			&& hash_equals( $expected_signature, $signature )
			&& 1 === (int) ( $payload['v'] ?? 0 )
			&& self::CLAIM_PURPOSE === (string) ( $payload['p'] ?? '' )
			&& hash_equals( (string) ( $form['form_id'] ?? '' ), (string) ( $payload['f'] ?? '' ) )
			&& $user_id === (int) ( $payload['u'] ?? 0 )
			&& hash_equals( self::site_binding(), (string) ( $payload['s'] ?? '' ) )
			&& (int) ( $payload['iat'] ?? 0 ) > 0
			&& (int) ( $payload['exp'] ?? 0 ) > (int) ( $payload['iat'] ?? 0 )
			&& (int) ( $payload['exp'] ?? 0 ) - (int) ( $payload['iat'] ?? 0 ) <= self::CLAIM_TTL
			&& false !== $created_at
			&& $created_at >= (int) $payload['iat'] - 300
			&& $created_at <= (int) $payload['exp'] + 300;
		return $valid
			? array( 'user_id' => $user_id, 'warning' => '', 'claim_digest' => hash( 'sha256', $claim ) )
			: array( 'user_id' => 0, 'warning' => 'ownership_claim_invalid', 'claim_digest' => hash( 'sha256', $claim ) );
	}

	/** @param mixed $value */
	private static function scalar_answer( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
		}
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/** @param mixed $value */
	private static function date_answer( $value ): ?string {
		if ( is_array( $value ) ) {
			if ( isset( $value['year'], $value['month'], $value['day'] ) ) {
				$value = sprintf( '%04d-%02d-%02d', $value['year'], $value['month'], $value['day'] );
			} else {
				$value = implode( '-', array_map( 'strval', $value ) );
			}
		}
		return GHCA_ACD_Jotform_Repository::sanitize_date( is_scalar( $value ) ? (string) $value : '' );
	}

	private static function api_datetime( string $value ): string {
		$timestamp = self::jotform_timestamp( $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql', true );
	}

	private static function later_submission_id( string $current, string $candidate ): string {
		if ( ! preg_match( '/^[0-9]{6,32}$/', $candidate ) ) {
			return $current;
		}
		if ( '' === $current || strlen( $candidate ) > strlen( $current ) || ( strlen( $candidate ) === strlen( $current ) && strcmp( $candidate, $current ) > 0 ) ) {
			return $candidate;
		}
		return $current;
	}

	private static function cursor_with_overlap( string $cursor ): string {
		$timestamp = self::jotform_timestamp( $cursor );
		if ( ! $timestamp ) {
			return '';
		}
		return ( new DateTimeImmutable( '@' . max( 1, $timestamp - self::CURSOR_OVERLAP_SECONDS ) ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function jotform_now(): string {
		$utc = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) );
		return $utc instanceof DateTimeImmutable ? $utc->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	/** @return int|false */
	private static function jotform_timestamp( string $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'America/New_York' ) );
		return $date instanceof DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value ? $date->getTimestamp() : false;
	}

	private static function claim_signing_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . "\n" . self::site_binding(), true );
	}

	private static function site_binding(): string {
		return hash( 'sha256', rtrim( (string) home_url( '/' ), '/' ) );
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function base64url_decode( string $value ) {
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$remainder = strlen( $value ) % 4;
		if ( $remainder > 0 ) {
			$value .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function mime_from_name( string $name ): string {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' )[ $extension ] ?? 'application/octet-stream';
	}

	/** @param array<string,string> $form @param array<string,mixed> $submission @param WP_User|false $employee */
	private static function identity_mismatch( array $form, array $submission, $employee ): bool {
		if ( ! $employee ) {
			return false;
		}
		$email_qid = (string) ( $form['employee_email_qid'] ?? '' );
		if ( '' !== $email_qid ) {
			$email = strtolower( self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, $email_qid ) ) );
			if ( '' !== $email && $email !== strtolower( (string) $employee->user_email ) ) {
				return true;
			}
		}
		$name_qid = (string) ( $form['employee_name_qid'] ?? '' );
		if ( '' !== $name_qid ) {
			$name = GHCA_ACD_Jotform_Repository::normalize_title( self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, $name_qid ) ) );
			if ( '' !== $name && $name !== GHCA_ACD_Jotform_Repository::normalize_title( (string) $employee->display_name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function identity_mismatch_detail( array $form, array $submission, $employee ): string {
		$parts = array();
		$email_qid = (string) ( $form['employee_email_qid'] ?? '' );
		if ( '' !== $email_qid ) { $submitted = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, $email_qid ) ); if ( '' !== $submitted && strtolower( $submitted ) !== strtolower( (string) $employee->user_email ) ) { $parts[] = 'Email: ' . self::mask_identity( $submitted ) . ' vs ' . self::mask_identity( (string) $employee->user_email ); } }
		$name_qid = (string) ( $form['employee_name_qid'] ?? '' );
		if ( '' !== $name_qid ) { $submitted = self::scalar_answer( GHCA_ACD_Jotform_Provider::answer( $submission, $name_qid ) ); if ( '' !== $submitted && GHCA_ACD_Jotform_Repository::normalize_title( $submitted ) !== GHCA_ACD_Jotform_Repository::normalize_title( (string) $employee->display_name ) ) { $parts[] = 'Name: ' . self::mask_identity( $submitted ) . ' vs ' . self::mask_identity( (string) $employee->display_name ); } }
		return implode( '; ', $parts );
	}

	private static function mask_identity( string $value ): string { $value = trim( $value ); return '' === $value ? '' : substr( $value, 0, 1 ) . str_repeat( '*', max( 2, min( 12, strlen( $value ) - 1 ) ) ); }
}
