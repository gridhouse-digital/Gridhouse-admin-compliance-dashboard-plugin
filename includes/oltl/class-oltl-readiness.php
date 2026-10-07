<?php
/** OLTL Chapter 52 training-evidence assignment, evaluation and manual reviews. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_OLTL_Readiness {
	const OPTION_ENABLED        = 'ghca_acd_oltl_enabled';
	const OPTION_ROLES          = 'ghca_acd_oltl_roles';
	const OPTION_USERS          = 'ghca_acd_oltl_users';
	const OPTION_EXCLUDED_USERS = 'ghca_acd_oltl_excluded_users';
	const SOURCE_URL            = 'https://www.pacodeandbulletin.gov/secure/pacode/data/055/chapter52/s52.21.html';
	const SOURCE_CITATION       = '55 Pa. Code § 52.21';
	const SOURCE_VERIFIED       = '2026-09-02';
	const PROFILE_VERSION       = '1.0.0';

	/** @return array<string,mixed> */
	public static function profile(): array {
		return array(
			'slug' => 'oltl_chapter_52',
			'name' => 'OLTL Chapter 52',
			'version' => self::PROFILE_VERSION,
			'source_citation' => self::SOURCE_CITATION,
			'source_url' => self::SOURCE_URL,
			'source_verified' => self::SOURCE_VERIFIED,
			'annual_requirements' => self::annual_requirements(),
			'manual_requirement' => 'service_plan_training',
		);
	}

	/** @return array<string,string> */
	public static function annual_requirements(): array {
		return array(
			'abuse_exploitation'      => __( 'Prevention of abuse and exploitation', 'ghca-acd' ),
			'critical_incidents'       => __( 'Reporting critical incidents', 'ghca-acd' ),
			'complaint_resolution'     => __( 'Participant complaint resolution', 'ghca-acd' ),
			'department_policies'      => __( 'Department-issued policies and procedures', 'ghca-acd' ),
			'quality_management'       => __( 'Provider quality management plan', 'ghca-acd' ),
			'fraud_financial_abuse'    => __( 'Fraud and financial abuse prevention', 'ghca-acd' ),
		);
	}

	/** @return array<string,string> */
	public static function all_requirements(): array {
		return self::annual_requirements() + array(
			'service_plan_training' => __( 'Participant/service-plan-specific training', 'ghca-acd' ),
		);
	}

	/** @param mixed $value @return array<int,string> */
	public static function sanitize_requirement_codes( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array_values( array_intersect( array_keys( self::annual_requirements() ), array_unique( array_map( 'sanitize_key', $value ) ) ) );
	}

	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	public static function is_assigned( int $user_id ): bool {
		if ( ! self::enabled() || $user_id <= 0 ) {
			return false;
		}
		$excluded = self::sanitize_ids( get_option( self::OPTION_EXCLUDED_USERS, array() ) );
		if ( in_array( $user_id, $excluded, true ) ) {
			return false;
		}
		if ( in_array( $user_id, self::sanitize_ids( get_option( self::OPTION_USERS, array() ) ), true ) ) {
			return true;
		}
		$user  = get_userdata( $user_id );
		$roles = is_array( get_option( self::OPTION_ROLES, array() ) ) ? array_map( 'sanitize_key', get_option( self::OPTION_ROLES, array() ) ) : array();
		return $user && (bool) array_intersect( $roles, (array) $user->roles );
	}

	/** @param mixed $value @return array<int,int> */
	public static function sanitize_ids( $value ): array {
		$value = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function evaluate( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::is_assigned( $user_id ) ) {
			return new WP_Error( 'ghca_oltl_not_assigned', __( 'OLTL Chapter 52 training readiness is not assigned to this employee.', 'ghca-acd' ) );
		}

		$now_ts = (int) current_time( 'timestamp', true );
		$hire_ts = GHCA_Audit_Calculator::registration_timestamp( $user );
		$cycle = GHCA_ACD_Settings::get_annual_cycle();
		$window = $hire_ts > 0 ? GHCA_Audit_Calculator::resolve_annual_window( $hire_ts, $cycle, $now_ts, wp_timezone() ) : array(
			'start_ts' => 0,
			'end_ts' => 0,
			'start_date' => '',
			'end_date' => '',
		);

		$requirements = array();
		foreach ( self::annual_requirements() as $code => $label ) {
			$requirements[ $code ] = self::requirement_row( $code, $label );
		}
		$courses = array();
		if ( $hire_ts > 0 ) {
			self::apply_internal_evidence( $user_id, $window, $requirements, $courses );
			self::apply_external_evidence( $user_id, $window, $requirements, $courses );
		} else {
			foreach ( $requirements as &$requirement ) {
				$requirement['status'] = 'manual_review';
				$requirement['note'] = __( 'Employee start date is unavailable; the reporting window cannot be determined.', 'ghca-acd' );
			}
			unset( $requirement );
		}

		$manual = self::latest_manual_review( $user_id );
		$manual_manifest_valid = self::manual_manifest_is_valid( $manual );
		$manual_status = self::manual_review_status( $manual, $window, $manual_manifest_valid );
		$requirements['service_plan_training'] = array(
			'code' => 'service_plan_training',
			'label' => self::all_requirements()['service_plan_training'],
			'status' => $manual_status,
			'source' => $manual && 'revoked' !== $manual['decision'] ? __( 'Protected manual review', 'ghca-acd' ) : '',
			'date' => $manual && 'revoked' !== $manual['decision'] ? (string) $manual['training_date'] : '',
			'note' => $manual && 'revoked' !== $manual['decision'] ? (string) $manual['service_description'] : __( 'Applicability and participant/service-plan training require human confirmation.', 'ghca-acd' ),
		);

		$statuses = wp_list_pluck( $requirements, 'status' );
		$overall = array_intersect( array( 'due', 'expired', 'missing_evidence' ), $statuses ) ? 'action_needed' : ( in_array( 'manual_review', $statuses, true ) ? 'manual_review_required' : 'evidence_ready' );
		usort( $courses, static function ( array $a, array $b ): int {
			$date = (int) $a['date'] <=> (int) $b['date'];
			return 0 !== $date ? $date : strcasecmp( (string) $a['title'], (string) $b['title'] );
		} );

		return array(
			'packet_type' => 'oltl_training',
			'first_name' => (string) $user->first_name,
			'last_name' => (string) $user->last_name,
			'role' => implode( ', ', (array) $user->roles ),
			'doh' => $hire_ts > 0 ? wp_date( 'm/d/Y', $hire_ts ) : '',
			'start_date' => (string) $window['start_date'],
			'end_date' => (string) $window['end_date'],
			'annual_cycle' => $cycle,
			'overall_status' => $overall,
			'requirements' => $requirements,
			'manual_review' => $manual,
			'manual_reference_redacted' => $manual && 'revoked' !== $manual['decision'] ? self::redact_reference( (string) $manual['evidence_reference'] ) : '',
			'raw_completed_courses' => $courses,
			'source_citation' => self::SOURCE_CITATION,
			'source_url' => self::SOURCE_URL,
			'source_verified' => self::SOURCE_VERIFIED,
			'profile_version' => self::PROFILE_VERSION,
		);
	}

	/** @return array<string,mixed> */
	private static function requirement_row( string $code, string $label ): array {
		return array( 'code' => $code, 'label' => $label, 'status' => 'due', 'source' => '', 'date' => '', 'note' => '' );
	}

	/** @param array<string,mixed> $window @param array<string,array<string,mixed>> $requirements @param array<int,array<string,mixed>> $courses */
	private static function apply_internal_evidence( int $user_id, array $window, array &$requirements, array &$courses ): void {
		$mappings = get_option( GHCA_Audit_Mapping::OPTION_NAME, array() );
		foreach ( is_array( $mappings ) ? $mappings : array() as $course_id => $mapping ) {
			$codes = self::sanitize_requirement_codes( $mapping['oltl_requirements'] ?? array() );
			if ( ! $codes ) {
				continue;
			}
			$course_id = absint( $course_id );
			$completed = function_exists( 'learndash_course_completed' ) && learndash_course_completed( $user_id, $course_id );
			$raw_date = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
			if ( $completed && empty( $raw_date ) && function_exists( 'learndash_user_get_course_completed_date' ) ) {
				$raw_date = learndash_user_get_course_completed_date( $user_id, $course_id );
			}
			$timestamp = is_numeric( $raw_date ) ? (int) $raw_date : ( $raw_date ? (int) strtotime( (string) $raw_date ) : 0 );
			$claimed = $completed || $timestamp > 0;
			if ( ! $claimed ) {
				continue;
			}
			$description = self::documented_course_content( $course_id );
			$status = self::evidence_status( $timestamp, $description, true, $window );
			$title = (string) get_the_title( $course_id );
			foreach ( $codes as $code ) {
				self::apply_requirement_status( $requirements[ $code ], $status, __( 'Platform completion', 'ghca-acd' ), $timestamp, $title );
			}
			if ( 'satisfied' === $status ) {
				$courses[] = array(
					'framework' => 'oltl', 'course_id' => $course_id, 'course_key' => 'learndash:' . $course_id, 'source' => 'learndash',
					'date' => $timestamp, 'title' => $title, 'description' => GHCA_Audit_Calculator::course_short_description( $course_id ),
					'full_description' => $description, 'oltl_requirements' => $codes, 'category' => $codes[0], 'credit_hours' => 0,
				);
			}
		}
	}

	private static function documented_course_content( int $course_id ): string {
		$description = GHCA_Audit_Calculator::course_full_description( $course_id );
		if ( '' !== $description ) {
			return $description;
		}
		$content = (string) get_post_field( 'post_content', $course_id );
		if ( function_exists( 'strip_shortcodes' ) ) {
			$content = strip_shortcodes( $content );
		}
		return trim( wp_strip_all_tags( $content ) );
	}

	/** @param array<string,mixed> $window @param array<string,array<string,mixed>> $requirements @param array<int,array<string,mixed>> $courses */
	private static function apply_external_evidence( int $user_id, array $window, array &$requirements, array &$courses ): void {
		if ( ! class_exists( 'GHCA_ACD_Jotform_Repository' ) ) {
			return;
		}
		foreach ( GHCA_ACD_Jotform_Repository::approved_courses( $user_id ) as $external ) {
			$snapshot = is_array( $external['snapshot'] ?? null ) ? $external['snapshot'] : array();
			$codes = self::sanitize_requirement_codes( $snapshot['oltl_requirements'] ?? array() );
			if ( ! $codes ) {
				continue;
			}
			$date = (string) ( $snapshot['completion_date'] ?? $external['proposed_completion_date'] ?? '' );
			$timestamp = $date ? (int) strtotime( $date . ' 12:00:00 UTC' ) : 0;
			$description = trim( wp_strip_all_tags( (string) ( $snapshot['description'] ?? '' ) ) );
			$has_manifest = ! empty( $external['manifest'] ) && is_array( $external['manifest'] );
			$status = self::evidence_status( $timestamp, $description, $has_manifest, $window );
			$title = (string) ( $snapshot['title'] ?? '' );
			foreach ( $codes as $code ) {
				self::apply_requirement_status( $requirements[ $code ], $status, __( 'Approved external training', 'ghca-acd' ), $timestamp, $title );
			}
			if ( 'satisfied' === $status ) {
				$courses[] = array(
					'framework' => 'oltl', 'course_id' => 0, 'course_key' => 'external:' . (int) $external['revision_id'], 'source' => 'external',
					'training_id' => (int) $external['training_id'], 'evidence_revision_id' => (int) $external['revision_id'],
					'evidence_snapshot_digest' => (string) $external['snapshot_digest'], 'evidence_manifest' => $external['manifest'],
					'date' => $timestamp, 'title' => $title, 'provider' => (string) ( $snapshot['provider'] ?? '' ),
					'description' => $description, 'full_description' => $description, 'oltl_requirements' => $codes, 'category' => $codes[0], 'credit_hours' => 0,
				);
			}
		}
	}

	/** @param array<string,mixed> $window */
	public static function evidence_status( int $timestamp, string $description, bool $supporting_evidence, array $window ): string {
		if ( $timestamp <= 0 || '' === trim( $description ) || ! $supporting_evidence ) {
			return 'missing_evidence';
		}
		if ( $timestamp < (int) $window['start_ts'] ) {
			return 'expired';
		}
		return $timestamp < (int) $window['end_ts'] ? 'satisfied' : 'missing_evidence';
	}

	/** @param array<string,mixed>|null $manual @param array<string,mixed> $window */
	public static function manual_review_status( ?array $manual, array $window, bool $manifest_valid = true ): string {
		if ( ! $manual || 'revoked' === (string) ( $manual['decision'] ?? '' ) ) {
			return 'manual_review';
		}
		if ( ! in_array( (string) $manual['decision'], array( 'satisfied', 'not_applicable' ), true ) ) {
			return 'missing_evidence';
		}
		$required = array( 'service_description', 'training_date', 'rationale', 'evidence_reference' );
		foreach ( $required as $field ) {
			if ( '' === trim( (string) ( $manual[ $field ] ?? '' ) ) ) {
				return 'missing_evidence';
			}
		}
		$date = (string) $manual['training_date'];
		$timestamp = self::valid_date( $date ) ? (int) strtotime( $date . ' 12:00:00 UTC' ) : 0;
		if ( ! $manifest_valid || $timestamp <= 0 || $timestamp >= (int) $window['end_ts'] ) {
			return 'missing_evidence';
		}
		if ( $timestamp < (int) $window['start_ts'] ) {
			return 'expired';
		}
		return 'not_applicable' === (string) $manual['decision'] ? 'not_applicable' : 'satisfied';
	}

	/** @param array<string,mixed> $row */
	private static function apply_requirement_status( array &$row, string $status, string $source, int $timestamp, string $title ): void {
		$priority = array( 'due' => 0, 'expired' => 1, 'missing_evidence' => 2, 'satisfied' => 3 );
		if ( ( $priority[ $status ] ?? 0 ) < ( $priority[ $row['status'] ] ?? 0 ) ) {
			return;
		}
		$row['status'] = $status;
		$row['source'] = $source;
		$row['date'] = $timestamp > 0 ? wp_date( 'm/d/Y', $timestamp ) : '';
		$row['note'] = $title;
	}

	/** @return array<string,mixed>|null */
	public static function latest_manual_review( int $user_id ): ?array {
		global $wpdb;
		if ( ! GHCA_ACD_OLTL_Schema::tables_exist() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GHCA_ACD_OLTL_Schema::reviews_table() . ' WHERE employee_user_id=%d ORDER BY revision_no DESC LIMIT 1', $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed>|null $manifest @return int|WP_Error */
	public static function record_manual_review( int $user_id, string $decision, string $description, string $date, string $rationale, string $reference, ?array $manifest, int $actor_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$actor_id = absint( $actor_id );
		$decision = sanitize_key( $decision );
		$description = substr( sanitize_textarea_field( $description ), 0, 4000 );
		$date = sanitize_text_field( $date );
		$rationale = substr( sanitize_textarea_field( $rationale ), 0, 4000 );
		$reference = substr( sanitize_text_field( $reference ), 0, 191 );
		if ( $user_id <= 0 || $actor_id <= 0 || ! in_array( $decision, array( 'satisfied', 'not_applicable' ), true ) || ! self::valid_date( $date ) || '' === $description || '' === $rationale || '' === $reference ) {
			return new WP_Error( 'ghca_oltl_manual_invalid', __( 'Complete every required manual-review field.', 'ghca-acd' ) );
		}
		$latest = self::latest_manual_review( $user_id );
		$manifest_json = $manifest ? wp_json_encode( $manifest ) : null;
		$digest = $manifest_json ? hash( 'sha256', $manifest_json ) : '';
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ghca_oltl_manual_store_failed', __( 'The manual review could not be stored.', 'ghca-acd' ) );
		}
		$inserted = $wpdb->insert( GHCA_ACD_OLTL_Schema::reviews_table(), array(
			'employee_user_id' => $user_id, 'revision_no' => $latest ? (int) $latest['revision_no'] + 1 : 1, 'decision' => $decision,
			'service_description' => $description, 'training_date' => $date, 'rationale' => $rationale, 'evidence_reference' => $reference,
			'evidence_manifest' => $manifest_json, 'evidence_digest' => $digest, 'supersedes_id' => $latest ? (int) $latest['id'] : null,
			'actor_user_id' => $actor_id, 'created_at' => current_time( 'mysql', true ),
		) );
		if ( 1 !== (int) $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_manual_store_failed', __( 'The manual review could not be stored.', 'ghca-acd' ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! self::event( 'manual_review_created', $actor_id, $user_id, $id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_manual_event_failed', __( 'The manual review audit event could not be stored.', 'ghca-acd' ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_manual_store_failed', __( 'The manual review could not be stored.', 'ghca-acd' ) );
		}
		return $id;
	}

	/** @return int|WP_Error */
	public static function revoke_manual_review( int $user_id, string $reason, int $actor_id ) {
		$latest = self::latest_manual_review( $user_id );
		if ( ! $latest || 'revoked' === $latest['decision'] || '' === trim( $reason ) ) {
			return new WP_Error( 'ghca_oltl_revoke_invalid', __( 'A current manual review and revocation reason are required.', 'ghca-acd' ) );
		}
		return self::record_revocation( $latest, $reason, $actor_id );
	}

	/** @param array<string,mixed> $latest @return int|WP_Error */
	private static function record_revocation( array $latest, string $reason, int $actor_id ) {
		global $wpdb;
		$reason = substr( sanitize_textarea_field( $reason ), 0, 1000 );
		$actor_id = absint( $actor_id );
		if ( '' === trim( $reason ) || $actor_id <= 0 ) {
			return new WP_Error( 'ghca_oltl_revoke_invalid', __( 'A current manual review and revocation reason are required.', 'ghca-acd' ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ghca_oltl_revoke_failed', __( 'The manual review could not be revoked.', 'ghca-acd' ) );
		}
		$inserted = $wpdb->insert( GHCA_ACD_OLTL_Schema::reviews_table(), array(
			'employee_user_id' => (int) $latest['employee_user_id'], 'revision_no' => (int) $latest['revision_no'] + 1, 'decision' => 'revoked',
			'service_description' => (string) $latest['service_description'], 'training_date' => (string) $latest['training_date'],
			'rationale' => $reason, 'evidence_reference' => (string) $latest['evidence_reference'], 'evidence_manifest' => null,
			'evidence_digest' => '', 'supersedes_id' => (int) $latest['id'], 'actor_user_id' => $actor_id, 'created_at' => current_time( 'mysql', true ),
		) );
		if ( 1 !== (int) $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_revoke_failed', __( 'The manual review could not be revoked.', 'ghca-acd' ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! self::event( 'manual_review_revoked', $actor_id, (int) $latest['employee_user_id'], $id ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_revoke_event_failed', __( 'The revocation audit event could not be stored.', 'ghca-acd' ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ghca_oltl_revoke_failed', __( 'The manual review could not be revoked.', 'ghca-acd' ) );
		}
		return $id;
	}

	public static function event( string $type, int $actor_id, int $employee_id, int $review_id = 0, string $outcome = 'success', string $error = '' ): bool {
		global $wpdb;
		$request_id = bin2hex( random_bytes( 16 ) );
		$event_at = current_time( 'mysql', true );
		return 1 === (int) $wpdb->insert( GHCA_ACD_OLTL_Schema::events_table(), array(
			'event_key' => hash( 'sha256', $type . '|' . $actor_id . '|' . $employee_id . '|' . $review_id . '|' . $event_at . '|' . $request_id ),
			'event_type' => substr( sanitize_key( $type ), 0, 40 ), 'actor_user_id' => $actor_id, 'employee_user_id' => $employee_id,
			'manual_review_id' => $review_id ?: null, 'outcome' => in_array( $outcome, array( 'success', 'denied', 'failed' ), true ) ? $outcome : 'failed',
			'error_code' => $error ? substr( sanitize_key( $error ), 0, 64 ) : null, 'request_id' => $request_id, 'event_at' => $event_at,
		) );
	}

	public static function redact_reference( string $reference ): string {
		$reference = trim( $reference );
		return '' === $reference ? '' : __( 'Reference ending ', 'ghca-acd' ) . '•••' . substr( hash( 'sha256', $reference ), -6 );
	}

	private static function valid_date( string $date ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/** @param array<string,mixed>|null $manual */
	private static function manual_manifest_is_valid( ?array $manual ): bool {
		if ( ! $manual || 'revoked' === (string) $manual['decision'] || empty( $manual['evidence_manifest'] ) ) {
			return true;
		}
		$json = (string) $manual['evidence_manifest'];
		$item = json_decode( $json, true );
		return is_array( $item ) && preg_match( '/^[a-f0-9]{64}$/', (string) $manual['evidence_digest'] ) && hash_equals( (string) $manual['evidence_digest'], hash( 'sha256', $json ) ) && GHCA_ACD_External_Evidence_Store::manual_manifest_is_valid( $item );
	}
}
