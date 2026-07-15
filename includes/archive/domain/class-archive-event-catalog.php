<?php

final class GHCA_ACD_Archive_Event_Catalog {
	const PAYLOAD_SCHEMA_VERSION = 1;

	/** @return array<string,string> */
	public static function schema( string $event_type ): array {
		$schemas = self::schemas();
		if ( ! isset( $schemas[ $event_type ] ) ) {
			throw new InvalidArgumentException( 'Unknown archive event type.' );
		}
		return $schemas[ $event_type ];
	}

	/** @return array<int,string> */
	public static function event_types(): array { return array_keys( self::schemas() ); }

	/** @param array<string,mixed> $payload */
	public static function validate_payload( string $event_type, int $event_schema_version, array $payload ): bool {
		if ( 1 !== $event_schema_version ) { throw new InvalidArgumentException( 'Unknown archive event schema version.' ); }
		$schema = self::schema( $event_type );
		self::assert_exact_fields( $payload, array_keys( $schema ), 'Archive event payload' );
		foreach ( $schema as $field => $rule ) { self::validate_field( $field, $rule, $payload[ $field ] ); }
		self::validate_cross_fields( $event_type, $payload );
		GHCA_ACD_Archive_Canonical_JSON::encode( $payload );
		return true;
	}

	/**
	 * Validate the caller-controlled fragment of an event payload before server
	 * facts exist. Field and cross-field rules are shared with full validation.
	 *
	 * @param array<string,mixed> $fragment
	 */
	public static function validate_payload_fragment( string $event_type, array $fragment ): bool {
		$schema = self::schema( $event_type );
		foreach ( $fragment as $field => $value ) {
			if ( ! array_key_exists( $field, $schema ) ) {
				throw new InvalidArgumentException( 'Caller intent field is not part of the event schema.' );
			}
			self::validate_field( $field, $schema[ $field ], $value );
		}
		self::validate_cross_fields( $event_type, $fragment );
		GHCA_ACD_Archive_Canonical_JSON::encode( $fragment );
		return true;
	}

	/**
	 * Return the effective subject-scope digest carried by a validated payload.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function effective_subject_scope_digest( array $payload ): ?string {
		$digests = array();
		foreach ( array( 'subject_scope_digest', 'scope_digest', 'affected_scope_digest' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$digests[] = $payload[ $field ];
			}
		}
		if ( array_key_exists( 'scope', $payload ) ) {
			$digests[] = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $payload['scope'] );
		}
		$digests = array_values( array_unique( $digests ) );
		if ( count( $digests ) > 1 ) {
			throw new InvalidArgumentException( 'Payload carries contradictory subject scopes.' );
		}
		return isset( $digests[0] ) ? $digests[0] : null;
	}

	/** @return array<string,array<string,string>> */
	private static function schemas(): array {
		static $schemas = null;
		if ( null !== $schemas ) { return $schemas; }
		$e = 'GHCA_ACD_Archive_Event_Types';
		$schemas = array(
			$e::ARCHIVE_REQUESTED => self::make( 'archive_id request_kind reviewed_source_fingerprint policy_digest subject_scope_digest', 'revision_number', '', '', '', 'case_key resolved_cycle' ),
			$e::ARCHIVE_BUILD_STARTED => self::make( 'archive_id build_attempt_id start_phase', 'retry_ordinal', '', '', 'snapshot_id' ),
			$e::EVIDENCE_SNAPSHOT_CAPTURED => self::make( 'archive_id snapshot_id snapshot_digest reviewed_source_fingerprint captured_source_fingerprint completeness_policy policy_digest subject_scope_digest', 'revision_number snapshot_schema_version byte_count', '', 'certificate_asset_ids certificate_content_digests', '', 'resolved_cycle' ),
			$e::LEDGER_MATERIALIZED => self::make( 'archive_id snapshot_id snapshot_digest build_attempt_id ledger_artifact_id content_digest manifest_digest', 'item_count' ),
			$e::PACKET_MATERIALIZED => self::make( 'archive_id snapshot_id snapshot_digest build_attempt_id packet_artifact_id content_digest', '', '', 'certificate_content_digests' ),
			$e::ARCHIVE_VERIFIED => self::make( 'archive_id snapshot_id snapshot_digest ledger_artifact_id ledger_content_digest packet_artifact_id packet_content_digest source_fingerprint checks_digest verified_at_gmt active_identity_digest', 'revision_number verification_policy_version', '', '', 'expected_predecessor_archive_id' ),
			$e::ARCHIVE_FINALIZED => self::make( 'archive_id snapshot_id snapshot_digest ledger_artifact_id ledger_content_digest packet_artifact_id packet_content_digest active_identity_digest finalized_at_gmt', 'revision_number', '', '', 'expected_predecessor_archive_id' ),
			$e::ARCHIVE_FAILED => self::make( 'archive_id phase failure_code', '', 'retryable', 'candidate_artifact_ids', 'build_attempt_id sealed_snapshot_id' ),
			$e::ARCHIVE_RETRY_REQUESTED => self::make( 'archive_id new_build_attempt_id resume_phase', '', '', '', 'prior_build_attempt_id sealed_snapshot_id' ),
			$e::ARCHIVE_CANCELLED => self::make( 'archive_id cancellation_reason retained_candidate_disposition_code', '', '', '', 'build_attempt_id' ),
			$e::CORRECTION_REQUESTED => self::make( 'target_archive_id target_snapshot_id correction_operation_id reason_code affected_scope_digest' ),
			$e::ARCHIVE_REVOKED => self::make( 'target_archive_id correction_operation_id revocation_reason_code', '', '', 'invalidated_reset_operation_ids' ),
			$e::REPLACEMENT_ARCHIVE_REQUESTED => self::make( 'archive_id revoked_predecessor_archive_id reviewed_source_fingerprint policy_digest subject_scope_digest', 'revision_number', '', '', '', 'case_key resolved_cycle' ),
			$e::RESET_REQUESTED => self::make( 'reset_operation_id bound_archive_id scope_digest consent_mode', '', '', '', 'snapshot_id request_valid_until_gmt', 'scope' ),
			$e::RESET_DEFERRED => self::make( 'reset_operation_id condition_code reevaluation_deadline_gmt consent_expires_at_gmt' ),
			$e::RESET_REJECTED => self::make( 'reset_operation_id rejection_code safe_explanation' ),
			$e::RESET_CANCELLED => self::make( 'reset_operation_id cancellation_reason', '', '', '', 'authorization_id' ),
			$e::RESET_AUTHORIZED => self::make( 'reset_operation_id authorization_id archive_id snapshot_id scope_digest gateway_key issued_at_gmt expires_at_gmt source_fingerprint' ),
			$e::RESET_AUTHORIZATION_EXPIRED => self::make( 'reset_operation_id authorization_id scheduled_expires_at_gmt observed_at_gmt expiry_policy_code' ),
			$e::RESET_OPERATION_INVALIDATED => self::make( 'reset_operation_id invalidating_reference_id reason_code', '', '', '', 'authorization_id' ),
			$e::RESET_EXECUTION_CLAIMED => self::make( 'reset_operation_id authorization_id gateway_key upstream_operation_id scope_digest source_fingerprint claimed_at_gmt' ),
			$e::RESET_COMPLETED => self::make( 'reset_operation_id upstream_operation_id post_source_fingerprint affected_records_digest verification_evidence_digest', 'affected_record_count' ),
			$e::RESET_FAILED_SAFE => self::make( 'reset_operation_id upstream_operation_id unchanged_source_fingerprint no_change_proof_digest probe_version' ),
			$e::RESET_OUTCOME_BECAME_UNCERTAIN => self::make( 'reset_operation_id upstream_operation_id last_known_phase failure_code last_observation_digest' ),
			$e::RESET_RECONCILED_AS_COMPLETED => self::make( 'reset_operation_id upstream_operation_id proof_digest probe_version post_source_fingerprint evidence_digest' ),
			$e::RESET_RECONCILED_AS_NO_CHANGE => self::make( 'reset_operation_id upstream_operation_id no_change_proof_digest probe_version source_fingerprint' ),
			$e::RESET_REMEDIATION_REQUIRED => self::make( 'reset_operation_id upstream_operation_id affected_scope_digest remediation_case_id evidence_digest' ),
			$e::RESET_REMEDIATED_RESTORED => self::make( 'reset_operation_id upstream_operation_id remediation_case_id restored_source_fingerprint restoration_proof_digest partial_effect_reference_id' ),
			$e::SOURCE_DRIFT_DETECTED => self::make( 'incident_id archive_id expected_source_fingerprint observed_source_fingerprint detection_point', '', '', 'changed_component_codes', 'snapshot_id' ),
			$e::SOURCE_DRIFT_RESOLVED => self::make( 'incident_id resolution_kind verified_source_fingerprint resolution_reference_id' ),
			$e::UNPROTECTED_RESET_DETECTED => self::make( 'incident_id before_source_fingerprint observed_source_fingerprint detector_key probe_version', '', '', '', '', 'scope' ),
			$e::UNPROTECTED_RESET_DISMISSED => self::make( 'incident_id no_reset_proof_digest verified_source_fingerprint' ),
			$e::UNPROTECTED_RESET_CONFIRMED => self::make( 'incident_id affected_scope_digest evidence_digest remediation_requirement' ),
			$e::INTEGRITY_VIOLATION_DETECTED => self::make( 'incident_id target_kind target_id verifier_version containment_code', '', '', '', 'expected_digest observed_digest invariant_code' ),
			$e::INTEGRITY_INCIDENT_DISPOSITION_RECORDED => self::make( 'incident_id disposition_code reason_code reviewer_authority_code', '', '', 'evidence_reference_ids remaining_restrictions' ),
		);
		return $schemas;
	}

	/** @return array<string,string> */
	private static function make( string $strings, string $ints = '', string $bools = '', string $lists = '', string $nullable = '', string $objects = '' ): array {
		$schema = array( 'payload_schema_version' => 'int' );
		foreach ( self::words( $strings ) as $field ) { $schema[ $field ] = 'string'; }
		foreach ( self::words( $ints ) as $field ) { $schema[ $field ] = 'int'; }
		foreach ( self::words( $bools ) as $field ) { $schema[ $field ] = 'bool'; }
		foreach ( self::words( $lists ) as $field ) { $schema[ $field ] = 'string_list'; }
		foreach ( self::words( $nullable ) as $field ) { $schema[ $field ] = 'nullable_string'; }
		foreach ( self::words( $objects ) as $field ) {
			$schema[ $field ] = 'case_key' === $field ? 'case_key' : ( 'resolved_cycle' === $field ? 'resolved_cycle' : 'reset_scope' );
		}
		return $schema;
	}

	/** @return array<int,string> */
	private static function words( string $value ): array {
		$value = trim( $value );
		return '' === $value ? array() : preg_split( '/\s+/', $value );
	}

	/** @param mixed $value */
	private static function validate_field( string $field, string $rule, $value ): void {
		if ( 'int' === $rule ) { self::validate_integer( $field, $value ); return; }
		if ( 'bool' === $rule ) {
			if ( ! is_bool( $value ) ) { throw new InvalidArgumentException( $field . ' must be boolean.' ); }
			return;
		}
		if ( 'nullable_string' === $rule && null === $value ) { return; }
		if ( 'string' === $rule || 'nullable_string' === $rule ) { self::validate_string( $field, $value ); return; }
		if ( 'string_list' === $rule ) { self::validate_string_list( $field, $value ); return; }
		if ( 'case_key' === $rule ) { self::validate_case_key( $value ); return; }
		if ( 'resolved_cycle' === $rule ) { self::validate_cycle( $value ); return; }
		if ( 'reset_scope' === $rule ) { self::validate_reset_scope( $value ); return; }
		throw new LogicException( 'Unknown event payload field rule.' );
	}

	/** @param mixed $value */
	private static function validate_integer( string $field, $value ): void {
		if ( ! is_int( $value ) || $value < 0 ) { throw new InvalidArgumentException( $field . ' must be a supported non-negative integer.' ); }
		if ( 'payload_schema_version' === $field || 'snapshot_schema_version' === $field || 'verification_policy_version' === $field ) {
			if ( 1 !== $value ) { throw new InvalidArgumentException( $field . ' version is unsupported.' ); }
			return;
		}
		if ( 'revision_number' === $field && $value < 1 ) { throw new InvalidArgumentException( 'Revision number must be positive.' ); }
		if ( 'byte_count' === $field && ( $value < 1 || $value > GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES ) ) { throw new InvalidArgumentException( 'Snapshot byte count is outside v1 bounds.' ); }
		if ( in_array( $field, array( 'item_count', 'affected_record_count', 'retry_ordinal' ), true ) && $value > GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES ) { throw new InvalidArgumentException( $field . ' exceeds the v1 bound.' ); }
	}

	/** @param mixed $value */
	private static function validate_string( string $field, $value ): void {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) ) { throw new InvalidArgumentException( $field . ' must be bounded non-empty UTF-8.' ); }
		if ( 'upstream_operation_id' === $field ) {
			if ( strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/', $value ) ) { throw new InvalidArgumentException( 'Upstream operation ID is invalid.' ); }
			return;
		}
		if ( false !== strpos( $field, 'digest' ) || false !== strpos( $field, 'fingerprint' ) || false !== strpos( $field, 'proof' ) ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) { throw new InvalidArgumentException( $field . ' must be a SHA-256 digest.' ); }
			return;
		}
		if ( substr( $field, -3 ) === '_id' ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $value ) ) { throw new InvalidArgumentException( $field . ' must be a 32-character identifier.' ); }
			return;
		}
		if ( self::is_timestamp_field( $field ) ) { self::validate_utc( $value, $field ); return; }
		if ( 'safe_explanation' === $field ) {
			if ( strlen( $value ) > 1024 ) { throw new InvalidArgumentException( 'Safe explanation is too long.' ); }
			return;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/', $value ) ) { throw new InvalidArgumentException( $field . ' must be a bounded machine value.' ); }
	}

	/** @param mixed $value */
	private static function validate_string_list( string $field, $value ): void {
		if ( ! is_array( $value ) || ! self::is_list( $value ) || count( $value ) > GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES ) { throw new InvalidArgumentException( $field . ' must be a bounded list.' ); }
		$seen = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) { throw new InvalidArgumentException( $field . ' list item is invalid.' ); }
			if ( isset( $seen[ $item ] ) ) { throw new InvalidArgumentException( $field . ' cannot contain duplicates.' ); }
			$seen[ $item ] = true;
			if ( false !== strpos( $field, 'digest' ) ) {
				if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $item ) ) { throw new InvalidArgumentException( $field . ' items must be SHA-256 digests.' ); }
			} elseif ( false !== strpos( $field, '_ids' ) ) {
				if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $item ) ) { throw new InvalidArgumentException( $field . ' items must be internal identifiers.' ); }
			} elseif ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $item ) ) {
				throw new InvalidArgumentException( $field . ' items must be machine codes.' );
			}
		}
	}

	/** @param mixed $value */
	private static function validate_case_key( $value ): void {
		if ( ! is_array( $value ) || self::is_list( $value ) ) { throw new InvalidArgumentException( 'case_key must be an object map.' ); }
		self::assert_exact_fields( $value, array( 'cycle_key', 'employee_user_id_decimal', 'program_key', 'site_id_decimal', 'tenant_id' ), 'case_key' );
		if ( ! is_string( $value['tenant_id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $value['tenant_id'] ) ) { throw new InvalidArgumentException( 'case_key tenant is invalid.' ); }
		self::validate_positive_decimal( $value['site_id_decimal'], 'case_key site' );
		self::validate_positive_decimal( $value['employee_user_id_decimal'], 'case_key employee' );
		if ( ! is_string( $value['program_key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $value['program_key'] ) ) { throw new InvalidArgumentException( 'case_key program is invalid.' ); }
		if ( ! is_string( $value['cycle_key'] ) || strlen( $value['cycle_key'] ) > 191 || 0 !== strpos( $value['cycle_key'], 'v1|' ) ) { throw new InvalidArgumentException( 'case_key cycle is invalid.' ); }
	}

	/** @param mixed $value */
	private static function validate_cycle( $value ): void {
		if ( ! is_array( $value ) || self::is_list( $value ) ) { throw new InvalidArgumentException( 'resolved_cycle must be an object map.' ); }
		self::assert_exact_fields( $value, array( 'boundary', 'display_label', 'end_gmt', 'key', 'policy_key', 'policy_version', 'start_gmt', 'timezone' ), 'resolved_cycle' );
		if ( '[)' !== $value['boundary'] || ! is_int( $value['policy_version'] ) ) { throw new InvalidArgumentException( 'resolved_cycle boundary/version is invalid.' ); }
		$cycle = new GHCA_ACD_Archive_Cycle( $value['policy_key'], $value['policy_version'], $value['start_gmt'], $value['end_gmt'], $value['timezone'], $value['display_label'] );
		if ( GHCA_ACD_Archive_Canonical_JSON::encode( $cycle->canonical() ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $value ) ) { throw new InvalidArgumentException( 'resolved_cycle facts do not reproduce its canonical key.' ); }
	}

	/** @param mixed $value */
	private static function validate_reset_scope( $value ): void {
		if ( ! is_array( $value ) || self::is_list( $value ) ) { throw new InvalidArgumentException( 'scope must be an object map.' ); }
		self::assert_exact_fields( $value, array( 'course_ids', 'cycle_key', 'employee_user_id_decimal', 'program_key' ), 'reset scope' );
		if ( ! is_array( $value['course_ids'] ) || ! self::is_list( $value['course_ids'] ) ) { throw new InvalidArgumentException( 'Reset course IDs must be a list.' ); }
		$scope = new GHCA_ACD_Archive_Reset_Scope( $value['employee_user_id_decimal'], $value['program_key'], $value['cycle_key'], $value['course_ids'] );
		if ( GHCA_ACD_Archive_Canonical_JSON::encode( $scope->canonical() ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $value ) ) { throw new InvalidArgumentException( 'Reset scope is not in canonical unique order.' ); }
	}

	/** @param array<string,mixed> $p */
	private static function validate_cross_fields( string $type, array $p ): void {
		if ( in_array( $type, array( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED ), true )
			&& array_key_exists( 'case_key', $p ) && array_key_exists( 'resolved_cycle', $p ) ) {
			if ( $p['case_key']['cycle_key'] !== $p['resolved_cycle']['key'] ) { throw new InvalidArgumentException( 'Case and resolved-cycle identity disagree.' ); }
		}
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED === $type && array_key_exists( 'request_kind', $p ) && 'initial' !== $p['request_kind'] ) { throw new InvalidArgumentException( 'Initial archive request kind is invalid.' ); }
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED === $type && array_key_exists( 'start_phase', $p ) && ! in_array( $p['start_phase'], array( 'capturing', 'materializing', 'verifying' ), true ) ) { throw new InvalidArgumentException( 'Build start phase is invalid.' ); }
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $type && array_key_exists( 'resume_phase', $p ) && ! in_array( $p['resume_phase'], array( 'capturing', 'materializing', 'verifying' ), true ) ) { throw new InvalidArgumentException( 'Retry resume phase is invalid.' ); }
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $type && array_key_exists( 'phase', $p ) ) {
			if ( ! in_array( $p['phase'], array( 'requested', 'capturing', 'materializing', 'verifying', 'finalizing' ), true ) ) { throw new InvalidArgumentException( 'Archive failure phase is invalid.' ); }
			if ( array_key_exists( 'build_attempt_id', $p ) && ( 'requested' === $p['phase'] ) !== ( null === $p['build_attempt_id'] ) ) { throw new InvalidArgumentException( 'Only a failure before any build attempt has a null attempt identity.' ); }
		}
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $type
			&& array_key_exists( 'prior_build_attempt_id', $p ) && array_key_exists( 'resume_phase', $p ) && array_key_exists( 'sealed_snapshot_id', $p )
			&& null === $p['prior_build_attempt_id'] && ( 'capturing' !== $p['resume_phase'] || null !== $p['sealed_snapshot_id'] ) ) {
			throw new InvalidArgumentException( 'A retry without a prior build attempt must resume capture with no sealed snapshot.' );
		}
		if ( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED === $type
			&& array_key_exists( 'certificate_asset_ids', $p ) && array_key_exists( 'certificate_content_digests', $p ) ) {
			if ( count( $p['certificate_asset_ids'] ) !== count( $p['certificate_content_digests'] ) ) { throw new InvalidArgumentException( 'Certificate manifest ID/digest cardinality differs.' ); }
		}
		if ( GHCA_ACD_Archive_Event_Types::RESET_REQUESTED === $type && array_key_exists( 'consent_mode', $p ) ) {
			if ( ! in_array( $p['consent_mode'], array( 'single_use', 'bounded_reevaluation' ), true ) ) { throw new InvalidArgumentException( 'Reset consent mode is invalid.' ); }
			if ( array_key_exists( 'request_valid_until_gmt', $p ) && 'bounded_reevaluation' === $p['consent_mode'] && null === $p['request_valid_until_gmt'] ) { throw new InvalidArgumentException( 'Bounded reevaluation consent requires an explicit validity window.' ); }
		}
		if ( GHCA_ACD_Archive_Event_Types::RESET_REQUESTED === $type && array_key_exists( 'scope_digest', $p ) && array_key_exists( 'scope', $p )
			&& $p['scope_digest'] !== GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $p['scope'] ) ) { throw new InvalidArgumentException( 'Reset scope digest mismatch.' ); }
		if ( GHCA_ACD_Archive_Event_Types::RESET_DEFERRED === $type && array_key_exists( 'reevaluation_deadline_gmt', $p ) && array_key_exists( 'consent_expires_at_gmt', $p ) && strcmp( $p['reevaluation_deadline_gmt'], $p['consent_expires_at_gmt'] ) > 0 ) { throw new InvalidArgumentException( 'Reset reevaluation exceeds consent validity.' ); }
		if ( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED === $type && array_key_exists( 'issued_at_gmt', $p ) && array_key_exists( 'expires_at_gmt', $p ) && strcmp( $p['issued_at_gmt'], $p['expires_at_gmt'] ) >= 0 ) { throw new InvalidArgumentException( 'Reset authorization expiry must follow issue time.' ); }
		if ( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED === $type && array_key_exists( 'observed_at_gmt', $p ) && array_key_exists( 'scheduled_expires_at_gmt', $p ) && strcmp( $p['observed_at_gmt'], $p['scheduled_expires_at_gmt'] ) < 0 ) { throw new InvalidArgumentException( 'Reset expiry cannot be observed before scheduled expiry.' ); }
		if ( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED === $type ) {
			if ( array_key_exists( 'detection_point', $p ) && ! in_array( $p['detection_point'], array( 'pre_capture', 'pre_finalization', 'post_finalization', 'pre_authorization', 'pre_claim' ), true ) ) { throw new InvalidArgumentException( 'Source drift facts are contradictory.' ); }
			if ( array_key_exists( 'expected_source_fingerprint', $p ) && array_key_exists( 'observed_source_fingerprint', $p ) && $p['expected_source_fingerprint'] === $p['observed_source_fingerprint'] ) { throw new InvalidArgumentException( 'Source drift facts are contradictory.' ); }
		}
		if ( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED === $type && array_key_exists( 'resolution_kind', $p ) && ! in_array( $p['resolution_kind'], array( 'restored', 'replacement_rebased' ), true ) ) { throw new InvalidArgumentException( 'Source drift resolution kind is invalid.' ); }
		if ( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_CONFIRMED === $type && array_key_exists( 'remediation_requirement', $p ) && ! in_array( $p['remediation_requirement'], array( 'required', 'preserved_evidence_amendment_required' ), true ) ) { throw new InvalidArgumentException( 'Unprotected reset remediation requirement is invalid.' ); }
		if ( GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED === $type ) {
			if ( array_key_exists( 'target_kind', $p ) && ! in_array( $p['target_kind'], array( 'stream', 'event', 'snapshot', 'artifact', 'checkpoint', 'invariant' ), true ) ) { throw new InvalidArgumentException( 'Integrity target kind is invalid.' ); }
			if ( array_key_exists( 'expected_digest', $p ) && array_key_exists( 'observed_digest', $p ) && array_key_exists( 'invariant_code', $p ) ) {
				$digests = null !== $p['expected_digest'] && null !== $p['observed_digest'];
				if ( $digests === ( null !== $p['invariant_code'] ) ) { throw new InvalidArgumentException( 'Integrity violation must identify either digest mismatch or invariant, exclusively.' ); }
			}
		}
		if ( GHCA_ACD_Archive_Event_Types::INTEGRITY_INCIDENT_DISPOSITION_RECORDED === $type && array_key_exists( 'disposition_code', $p ) && ! in_array( $p['disposition_code'], array( 'false_positive', 'verified_restoration', 'confirmed_compromise' ), true ) ) { throw new InvalidArgumentException( 'Integrity disposition is invalid.' ); }
	}

	private static function is_timestamp_field( string $field ): bool {
		return false !== strpos( $field, '_at_gmt' ) || false !== strpos( $field, 'deadline_gmt' ) || false !== strpos( $field, 'expires_at_gmt' ) || false !== strpos( $field, '_until_gmt' );
	}

	private static function validate_utc( string $value, string $field ): void {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) { throw new InvalidArgumentException( $field . ' must be canonical UTC.' ); }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) { throw new InvalidArgumentException( $field . ' must be a real UTC timestamp.' ); }
	}

	/** @param mixed $value */
	private static function validate_positive_decimal( $value, string $label ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) { throw new InvalidArgumentException( $label . ' must be canonical positive decimal.' ); }
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) { throw new InvalidArgumentException( $label . ' exceeds the BIGINT UNSIGNED range.' ); }
	}

	/** @param array<string,mixed> $actual @param array<int,string> $expected */
	private static function assert_exact_fields( array $actual, array $expected, string $label ): void {
		$keys = array_keys( $actual );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) { throw new InvalidArgumentException( $label . ' fields do not match the exact v1 schema.' ); }
	}

	/** @param array<mixed,mixed> $value */
	private static function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_item ) { if ( $key !== $expected ) { return false; } $expected++; }
		return true;
	}
}
