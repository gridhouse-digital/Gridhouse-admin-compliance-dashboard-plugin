<?php

/**
 * Immutable evidence snapshots with strict retained-row verification.
 *
 * The store owns canonicalization, the approved dark-mode limits, snapshot
 * digest verification, and exact binding to EvidenceSnapshotCaptured. It has
 * deliberately no update or delete surface.
 */
final class GHCA_ACD_WPDB_Archive_Snapshot_Store {
	const SNAPSHOT_SCHEMA_VERSION = 1;
	const SOURCE_FINGERPRINT_VERSION = 1;
	const CANONICAL_FORMAT = 'ghca-cjson-1';
	const MAX_EVIDENCE_ASSETS = 10000;

	/** @var wpdb|object */
	private $db;

	/** @param wpdb|object $db */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return wpdb|object */
	public function database() {
		return $this->db;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	public function insert( array $record, GHCA_ACD_Archive_Event $event ): array {
		if ( ! $event->is_recorded() || GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED !== $event->type() ) {
			throw $this->invalid( 'snapshot_event_binding_mismatch', 'The snapshot must be bound to its recorded capture event.' );
		}
		$row = $this->build_row( $record, $event );
		$result = $this->db->insert( $this->table(), $row, array(
			'%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%d', '%d', '%s', '%s',
		) );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				$stored = $this->find_duplicate( $row );
				if ( null !== $stored && $this->rows_equal( $row, $stored ) ) {
					return $stored;
				}
				throw $this->integrity( 'snapshot_contradictory_duplicate', 'The immutable snapshot identity already exists with different content.' );
			}
			throw $this->internal( 'snapshot_insert_failed', 'The immutable snapshot could not be inserted.' );
		}
		return $this->validate_stored_row( $row );
	}

	/** @return array<string,mixed>|null */
	public function find( string $snapshot_id ) {
		$this->assert_id( $snapshot_id, 'Snapshot ID' );
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE snapshot_id = %s", $snapshot_id ), ARRAY_A );
		$this->assert_no_database_error( 'snapshot_lookup_failed' );
		return null === $row ? null : $this->validate_stored_row( $row );
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	private function build_row( array $record, GHCA_ACD_Archive_Event $event ): array {
		$this->assert_exact_fields( $record, array( 'snapshot_document' ), 'snapshot_record_invalid' );
		if ( ! is_array( $record['snapshot_document'] ) ) {
			throw $this->invalid( 'snapshot_record_invalid', 'The snapshot record must contain one canonical document.' );
		}
		$document = $record['snapshot_document'];
		$this->preflight_document( $document );
		try {
			$json = GHCA_ACD_Archive_Canonical_JSON::encode( $document );
		} catch ( InvalidArgumentException $error ) {
			throw $this->map_canonical_error( $error );
		}
		if ( strlen( $json ) > GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES ) {
			throw $this->invalid( 'side_snapshot_bytes_exceeded', 'The canonical snapshot exceeds the approved byte ceiling.' );
		}
		$this->validate_document_schema( $document, false );

		$payload = $event->payload();
		$envelope = $event->recorded_document();
		$case = $document['case'];
		$source = $document['source'];
		$policy = $document['policy'];
		$assets = $source['evidence_assets'];
		$asset_ids = array();
		$asset_digests = array();
		foreach ( $assets as $asset ) {
			$asset_ids[] = $asset['artifact_id'];
			$asset_digests[] = $asset['content_digest'];
		}
		$digest = GHCA_ACD_Archive_Digester::snapshot( $document );
		$captured_user = null !== $envelope['initiating_user_id'] ? $envelope['initiating_user_id'] : $envelope['actor_user_id'];
		$matches = $case['stream_id'] === $event->stream_id()
			&& $case['archive_id'] === $payload['archive_id']
			&& $case['snapshot_id'] === $payload['snapshot_id']
			&& $case['revision_number'] === $payload['revision_number']
			&& $case['case_key_digest'] === $envelope['case_key_digest']
			&& $document['schema_version'] === $payload['snapshot_schema_version']
			&& self::CANONICAL_FORMAT === $document['canonical_format']
			&& $source['reviewed_source_fingerprint'] === $payload['reviewed_source_fingerprint']
			&& $source['captured_source_fingerprint'] === $payload['captured_source_fingerprint']
			&& $policy['policy_digest'] === $payload['policy_digest']
			&& $policy['completeness_policy'] === $payload['completeness_policy']
			&& $document['review']['subject_scope_digest'] === $payload['subject_scope_digest']
			&& $document['cycle'] === $payload['resolved_cycle']
			&& $asset_ids === $payload['certificate_asset_ids']
			&& $asset_digests === $payload['certificate_content_digests']
			&& hash_equals( $payload['snapshot_digest'], $digest )
			&& $payload['byte_count'] === strlen( $json );
		if ( ! $matches ) {
			throw $this->invalid( 'snapshot_identity_binding_mismatch', 'The snapshot document contradicts its capture event or Archive Case identity.' );
		}

		return array(
			'snapshot_id'                => $payload['snapshot_id'],
			'stream_id'                  => $event->stream_id(),
			'archive_id'                 => $payload['archive_id'],
			'revision_number'            => $payload['revision_number'],
			'source_event_id'            => $event->event_id(),
			'snapshot_schema_version'    => self::SNAPSHOT_SCHEMA_VERSION,
			'canonical_format_version'   => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
			'source_fingerprint_version' => self::SOURCE_FINGERPRINT_VERSION,
			'reviewed_source_fingerprint' => $source['reviewed_source_fingerprint'],
			'captured_source_fingerprint' => $source['captured_source_fingerprint'],
			'policy_digest'               => $policy['policy_digest'],
			'completeness_policy'         => $policy['completeness_policy'],
			'completeness_result'         => $document['completeness']['result'],
			'snapshot_digest'             => $digest,
			'snapshot_json'               => $json,
			'byte_count'                  => strlen( $json ),
			'item_count'                  => count( $document['courses'] ),
			'captured_by_user_id'         => $captured_user,
			'captured_at_gmt'             => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['captured_at_gmt'] ),
		);
	}

	/** @param array<string,mixed> $document */
	private function validate_document_schema( array $document, bool $retained ): void {
		$fields = array(
			'calculated', 'canonical_format', 'captured_at_gmt', 'case', 'completeness',
			'courses', 'cycle', 'organization', 'policy', 'review', 'schema_version', 'source', 'subject',
		);
		if ( ! $this->has_exact_fields( $document, $fields ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot document does not match the closed v1 schema.' );
		}
		if ( self::SNAPSHOT_SCHEMA_VERSION !== $document['schema_version'] ) {
			$this->schema_fail( $retained, 'unsupported_snapshot_schema_version', 'The snapshot schema version is not supported.' );
		}
		if ( self::CANONICAL_FORMAT !== $document['canonical_format'] ) {
			$this->schema_fail( $retained, 'unsupported_snapshot_canonical_version', 'The snapshot canonical format is not supported.' );
		}
		if ( ! $this->valid_utc( $document['captured_at_gmt'] ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot capture time is invalid.' );
		}
		if ( ! is_array( $document['courses'] ) || ! $this->is_list( $document['courses'] ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot course evidence must be an ordered list.' );
		}

		$this->validate_case_section( $document['case'], $retained );
		$this->validate_cycle_section( $document['cycle'], $retained );
		$this->validate_review_section( $document['review'], $retained );
		$this->validate_subject_section( $document['subject'], $retained );
		$this->validate_organization_section( $document['organization'], $retained );
		$this->validate_policy_section( $document['policy'], $retained );
		$this->validate_source_section( $document['source'], $retained );
		$this->validate_courses( $document['courses'], $document['cycle'], $retained );
		$this->validate_calculated_section( $document['calculated'], $retained );
		$this->validate_completeness_section( $document['completeness'], $retained );

		$case = $document['case'];
		$source = $document['source'];
		$review = $document['review'];
		$course_ids = array();
		$certificate_ids = array();
		$certificate_roles = array();
		$total_training_seconds = '0';
		foreach ( $document['courses'] as $course ) {
			$course_ids[] = $course['course_id'];
			$total_training_seconds = $this->add_unsigned_decimals( $total_training_seconds, $course['time_spent_seconds'], $retained );
			if ( $course['certificate_required'] ) {
				$certificate_ids[] = $course['certificate_artifact_id'];
				$certificate_roles[] = 'course:' . $course['course_id'];
			}
		}
		$asset_ids = array();
		$asset_roles = array();
		foreach ( $source['evidence_assets'] as $asset ) {
			$asset_ids[] = $asset['artifact_id'];
			$asset_roles[] = $asset['role_key'];
		}
		if ( $case['cycle_key'] !== $document['cycle']['key']
			|| $case['employee_user_id'] !== $document['subject']['employee_user_id']
			|| $case['tenant_id'] !== $document['organization']['tenant_id']
			|| $review['reviewed_source_fingerprint'] !== $source['reviewed_source_fingerprint']
			|| $document['policy']['tracked_course_ids'] !== $course_ids
			|| $certificate_ids !== $asset_ids
			|| $certificate_roles !== $asset_roles
			|| $document['calculated']['total_course_count'] !== count( $document['courses'] )
			|| $document['calculated']['total_training_seconds'] !== $total_training_seconds
			|| $document['completeness']['required_count'] !== count( $document['policy']['tracked_course_ids'] )
			|| $document['completeness']['observed_count'] !== count( $document['courses'] ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot sections do not share one exact evidence identity.' );
		}
	}

	/** @param mixed $section */
	private function validate_case_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'archive_id', 'case_key_digest', 'cycle_key', 'employee_user_id', 'program_key', 'revision_number',
			'site_id', 'snapshot_id', 'stream_id', 'tenant_id',
		) )
			|| ! $this->is_id( $section['archive_id'] ) || ! $this->is_id( $section['snapshot_id'] )
			|| ! $this->is_id( $section['stream_id'] ) || ! $this->is_id( $section['tenant_id'] )
			|| ! $this->is_digest( $section['case_key_digest'] )
			|| ! $this->is_unsigned_decimal( $section['site_id'] ) || '0' === $section['site_id']
			|| ! $this->is_unsigned_decimal( $section['employee_user_id'] ) || '0' === $section['employee_user_id']
			|| ! is_int( $section['revision_number'] ) || $section['revision_number'] < 1
			|| ! $this->valid_key( $section['program_key'], 64 ) || ! $this->valid_text( $section['cycle_key'], 191 ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot case identity is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_cycle_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'boundary', 'display_label', 'end_gmt', 'key', 'policy_key', 'policy_version', 'start_gmt', 'timezone',
		) ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot cycle is invalid.' );
		}
		try {
			$cycle = new GHCA_ACD_Archive_Cycle(
				$section['policy_key'], $section['policy_version'], $section['start_gmt'], $section['end_gmt'],
				$section['timezone'], $section['display_label']
			);
		} catch ( Throwable $error ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot cycle is invalid.' );
		}
		if ( $cycle->canonical() !== $section ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot cycle is not canonical.' );
		}
	}

	/** @param mixed $section */
	private function validate_review_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'actor_user_id', 'authority_code', 'initiating_user_id', 'request_event_id', 'requested_at_gmt',
			'reviewed_source_fingerprint', 'subject_scope_digest',
		) )
			|| ! $this->is_id( $section['request_event_id'] ) || ! $this->valid_utc( $section['requested_at_gmt'] )
			|| ! $this->is_nullable_unsigned_decimal( $section['actor_user_id'] )
			|| ! $this->is_nullable_unsigned_decimal( $section['initiating_user_id'] )
			|| ! $this->valid_key( $section['authority_code'], 64 )
			|| ! $this->is_digest( $section['reviewed_source_fingerprint'] )
			|| ! $this->is_digest( $section['subject_scope_digest'] ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot review evidence is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_subject_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'display_name', 'email', 'employee_user_id', 'external_employee_key', 'group_ids', 'registered_at_gmt', 'role_keys',
		) )
			|| ! $this->is_unsigned_decimal( $section['employee_user_id'] )
			|| ! $this->valid_text( $section['display_name'], 255 )
			|| ! is_string( $section['email'] ) || strlen( $section['email'] ) > 254 || false === filter_var( $section['email'], FILTER_VALIDATE_EMAIL )
			|| ! $this->valid_nullable_text( $section['external_employee_key'], 191 )
			|| ! $this->valid_nullable_utc( $section['registered_at_gmt'] )
			|| ! $this->valid_string_list( $section['role_keys'], 64, true )
			|| ! $this->valid_decimal_list( $section['group_ids'], true ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot subject evidence is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_organization_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array( 'agency_name', 'site_name', 'tenant_id' ) )
			|| ! $this->is_id( $section['tenant_id'] ) || ! $this->valid_text( $section['agency_name'], 255 )
			|| ! $this->valid_text( $section['site_name'], 255 ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot organization evidence is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_policy_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'audit_mapping', 'completeness_policy', 'course_lifespan_rules', 'policy_digest', 'quiz_policy',
			'relevant_settings', 'tracked_course_ids',
		) )
			|| ! $this->is_object_document( $section['audit_mapping'] )
			|| ! $this->is_object_document( $section['course_lifespan_rules'] )
			|| ! $this->is_object_document( $section['quiz_policy'] )
			|| ! $this->is_object_document( $section['relevant_settings'] )
			|| ! $this->valid_key( $section['completeness_policy'], 64 )
			|| ! $this->is_digest( $section['policy_digest'] )
			|| ! $this->valid_decimal_list( $section['tracked_course_ids'], false ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot policy evidence is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_source_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'captured_source_fingerprint', 'evidence_assets', 'learndash_version', 'plugin_version',
			'reviewed_source_fingerprint', 'source_adapter_key', 'source_adapter_version', 'source_fingerprint_version',
			'source_record_ids', 'wordpress_version',
		) )
			|| self::SOURCE_FINGERPRINT_VERSION !== $section['source_fingerprint_version']
			|| ! $this->is_digest( $section['reviewed_source_fingerprint'] )
			|| ! $this->is_digest( $section['captured_source_fingerprint'] )
			|| ! $this->valid_version( $section['wordpress_version'] )
			|| ! $this->valid_version( $section['learndash_version'] )
			|| ! $this->valid_version( $section['plugin_version'] )
			|| ! $this->valid_key( $section['source_adapter_key'], 64 )
			|| ! $this->valid_version( $section['source_adapter_version'] )
			|| ! $this->is_object_document( $section['source_record_ids'] )
			|| ! is_array( $section['evidence_assets'] ) || ! $this->is_list( $section['evidence_assets'] )
			|| count( $section['evidence_assets'] ) > self::MAX_EVIDENCE_ASSETS ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot source evidence is invalid.' );
		}
		$seen = array();
		foreach ( $section['evidence_assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! $this->has_exact_fields( $asset, array(
				'artifact_id', 'byte_count', 'content_digest', 'producer_key', 'producer_version', 'role_key', 'source_identifier',
			) )
				|| ! $this->is_id( $asset['artifact_id'] ) || isset( $seen[ $asset['artifact_id'] ] )
				|| ! is_int( $asset['byte_count'] ) || $asset['byte_count'] < 1
				|| ! $this->is_digest( $asset['content_digest'] )
				|| ! $this->valid_key( $asset['producer_key'], 64 ) || ! $this->valid_version( $asset['producer_version'] )
				|| ! is_string( $asset['role_key'] ) || 1 !== preg_match( '/^course:[1-9][0-9]*$/', $asset['role_key'] )
				|| ! $this->valid_text( $asset['source_identifier'], 191 ) ) {
				$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot evidence-asset manifest is invalid.' );
			}
			$seen[ $asset['artifact_id'] ] = true;
		}
	}

	/** @param array<int,mixed> $courses @param array<string,mixed> $cycle */
	private function validate_courses( array $courses, array $cycle, bool $retained ): void {
		$previous_order = null;
		foreach ( $courses as $course ) {
			if ( ! is_array( $course ) || ! $this->has_exact_fields( $course, array(
				'category_order', 'certificate_artifact_id', 'certificate_required', 'completed_at_gmt', 'completion_status',
				'course_id', 'course_order', 'course_stable_key', 'course_title', 'enrollment_status', 'pass_state',
				'quiz_attempts', 'quiz_score_basis_points', 'source_provenance', 'started_at_gmt', 'time_spent_seconds',
			) )
				|| ! is_int( $course['category_order'] ) || $course['category_order'] < 0
				|| ! is_int( $course['course_order'] ) || $course['course_order'] < 0
				|| ! $this->is_unsigned_decimal( $course['course_id'] ) || '0' === $course['course_id']
				|| ! $this->valid_nullable_text( $course['course_stable_key'], 191 )
				|| ! $this->valid_text( $course['course_title'], 255 )
				|| ! in_array( $course['enrollment_status'], array( 'enrolled', 'not_enrolled' ), true )
				|| ! in_array( $course['completion_status'], array( 'not_started', 'in_progress', 'completed' ), true )
				|| ! in_array( $course['pass_state'], array( 'passed', 'failed', 'not_applicable', 'unknown' ), true )
				|| ! $this->valid_nullable_utc( $course['started_at_gmt'] ) || ! $this->valid_nullable_utc( $course['completed_at_gmt'] )
				|| ! $this->is_unsigned_decimal( $course['time_spent_seconds'] )
				|| ! $this->valid_nullable_basis_points( $course['quiz_score_basis_points'] )
				|| ! is_bool( $course['certificate_required'] )
				|| ( $course['certificate_required'] !== $this->is_id( $course['certificate_artifact_id'] ) )
				|| ! $this->valid_source_provenance( $course['source_provenance'] )
				|| ! $this->valid_quiz_attempts( $course['quiz_attempts'] ) ) {
				$this->schema_fail( $retained, 'snapshot_schema_invalid', 'A snapshot course evidence row is invalid.' );
			}
			if ( ( null !== $course['started_at_gmt'] && ! $this->within_cycle( $course['started_at_gmt'], $cycle ) )
				|| ( null !== $course['completed_at_gmt'] && ! $this->within_cycle( $course['completed_at_gmt'], $cycle ) )
				|| ( null !== $course['started_at_gmt'] && null !== $course['completed_at_gmt'] && strcmp( $course['started_at_gmt'], $course['completed_at_gmt'] ) > 0 )
				|| ( 'completed' === $course['completion_status'] ) !== ( null !== $course['completed_at_gmt'] )
				|| ( 'not_started' === $course['completion_status'] && ( null !== $course['started_at_gmt'] || '0' !== $course['time_spent_seconds'] ) ) ) {
				$this->schema_fail( $retained, 'snapshot_schema_invalid', 'A snapshot course timeline contradicts the resolved cycle or completion state.' );
			}
			foreach ( $course['quiz_attempts'] as $attempt ) {
				if ( ! $this->within_cycle( $attempt['attempted_at_gmt'], $cycle ) ) {
					$this->schema_fail( $retained, 'snapshot_schema_invalid', 'A snapshot quiz attempt falls outside the resolved cycle.' );
				}
			}
			$order = array( $course['category_order'], $course['course_order'], $course['course_id'] );
			if ( null !== $previous_order && $this->compare_course_order( $previous_order, $order ) >= 0 ) {
				$this->schema_fail( $retained, 'snapshot_schema_invalid', 'Snapshot courses are not in canonical order.' );
			}
			$previous_order = $order;
		}
	}

	/** @param mixed $section */
	private function validate_calculated_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'calculation_version', 'categories', 'compliance_status', 'exceptions', 'matrix',
			'total_course_count', 'total_training_seconds',
		) )
			|| 1 !== $section['calculation_version']
			|| ! in_array( $section['compliance_status'], array( 'compliant', 'non_compliant', 'incomplete' ), true )
			|| ! is_int( $section['total_course_count'] ) || $section['total_course_count'] < 0
			|| ! $this->is_unsigned_decimal( $section['total_training_seconds'] )
			|| ! $this->valid_string_list( $section['exceptions'], 191, false )
			|| ! $this->is_object_document( $section['categories'] ) || ! $this->is_object_document( $section['matrix'] ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot calculated evidence is invalid.' );
		}
	}

	/** @param mixed $section */
	private function validate_completeness_section( $section, bool $retained ): void {
		if ( ! is_array( $section ) || ! $this->has_exact_fields( $section, array(
			'missing_fields', 'observed_count', 'policy_code', 'policy_version', 'required_count', 'result', 'warnings',
		) )
			|| ! $this->valid_key( $section['policy_code'], 64 ) || ! is_int( $section['policy_version'] ) || $section['policy_version'] < 1
			|| ! is_int( $section['required_count'] ) || $section['required_count'] < 0
			|| ! is_int( $section['observed_count'] ) || $section['observed_count'] < 0
			|| ! $this->valid_string_list( $section['missing_fields'], 191, false )
			|| ! $this->valid_string_list( $section['warnings'], 191, false )
			|| 'complete' !== $section['result'] || array() !== $section['missing_fields']
			|| $section['observed_count'] < $section['required_count'] ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot completeness evidence is invalid.' );
		}
	}

	/** @param mixed $value */
	private function valid_source_provenance( $value ): bool {
		return is_array( $value ) && $this->has_exact_fields( $value, array( 'adapter_key', 'record_id', 'record_version' ) )
			&& $this->valid_key( $value['adapter_key'], 64 ) && $this->valid_text( $value['record_id'], 191 )
			&& $this->valid_version( $value['record_version'] );
	}

	/** @param mixed $value */
	private function valid_quiz_attempts( $value ): bool {
		if ( ! is_array( $value ) || ! $this->is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $ordinal => $attempt ) {
			if ( ! is_array( $attempt ) || ! $this->has_exact_fields( $attempt, array( 'attempt_ordinal', 'attempted_at_gmt', 'passed', 'score_basis_points' ) )
				|| $ordinal !== $attempt['attempt_ordinal'] || ! $this->valid_utc( $attempt['attempted_at_gmt'] )
				|| ! is_bool( $attempt['passed'] ) || ! is_int( $attempt['score_basis_points'] )
				|| $attempt['score_basis_points'] < 0 || $attempt['score_basis_points'] > 10000 ) {
				return false;
			}
		}
		return true;
	}

	private function schema_fail( bool $retained, string $reason, string $message ): void {
		throw $retained ? $this->integrity( $reason, $message ) : $this->invalid( $reason, $message );
	}

	/** @param mixed $value */
	private function valid_utc( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		try {
			GHCA_ACD_Archive_Db_Format::utc_to_db( $value );
			return true;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @param mixed $value */
	private function valid_nullable_utc( $value ): bool {
		return null === $value || $this->valid_utc( $value );
	}

	/** @param mixed $value */
	private function valid_key( $value, int $maximum ): bool {
		return is_string( $value ) && strlen( $value ) <= $maximum
			&& 1 === preg_match( '/^[a-z][a-z0-9._-]*$/', $value );
	}

	/** @param mixed $value */
	private function valid_version( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 64
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $value );
	}

	/** @param mixed $value */
	private function valid_text( $value, int $maximum ): bool {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= $maximum
			&& 1 === preg_match( '//u', $value );
	}

	/** @param mixed $value */
	private function valid_nullable_text( $value, int $maximum ): bool {
		return null === $value || $this->valid_text( $value, $maximum );
	}

	/** @param mixed $value */
	private function is_object_document( $value ): bool {
		return $value instanceof GHCA_ACD_Archive_Empty_Object
			|| ( is_array( $value ) && array() !== $value && ! $this->is_list( $value ) );
	}

	/** @param mixed $value */
	private function valid_string_list( $value, int $maximum, bool $sorted ): bool {
		if ( ! is_array( $value ) || ! $this->is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! $this->valid_text( $item, $maximum ) ) {
				return false;
			}
		}
		if ( $sorted ) {
			$copy = $value;
			sort( $copy, SORT_STRING );
			return $copy === $value && count( array_unique( $value, SORT_STRING ) ) === count( $value );
		}
		return true;
	}

	/** @param mixed $value */
	private function valid_decimal_list( $value, bool $allow_empty ): bool {
		if ( ! is_array( $value ) || ! $this->is_list( $value ) || ( ! $allow_empty && array() === $value ) ) {
			return false;
		}
		$seen = array();
		foreach ( $value as $item ) {
			if ( ! $this->is_unsigned_decimal( $item ) || isset( $seen[ $item ] ) ) {
				return false;
			}
			$seen[ $item ] = true;
		}
		return true;
	}

	/** @param mixed $value */
	private function is_unsigned_decimal( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) {
			return false;
		}
		$maximum = '18446744073709551615';
		return strlen( $value ) < strlen( $maximum )
			|| ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	/** @param mixed $value */
	private function is_nullable_unsigned_decimal( $value ): bool {
		return null === $value || $this->is_unsigned_decimal( $value );
	}

	/** @param mixed $value */
	private function valid_nullable_basis_points( $value ): bool {
		return null === $value || ( is_int( $value ) && $value >= 0 && $value <= 10000 );
	}

	/** @param array<string,mixed> $cycle */
	private function within_cycle( string $value, array $cycle ): bool {
		return strcmp( $value, $cycle['start_gmt'] ) >= 0 && strcmp( $value, $cycle['end_gmt'] ) < 0;
	}

	private function add_unsigned_decimals( string $left, string $right, bool $retained ): string {
		$left_index = strlen( $left ) - 1;
		$right_index = strlen( $right ) - 1;
		$carry = 0;
		$result = '';
		while ( $left_index >= 0 || $right_index >= 0 || $carry > 0 ) {
			$sum = $carry;
			if ( $left_index >= 0 ) {
				$sum += (int) $left[ $left_index-- ];
			}
			if ( $right_index >= 0 ) {
				$sum += (int) $right[ $right_index-- ];
			}
			$result = (string) ( $sum % 10 ) . $result;
			$carry = intdiv( $sum, 10 );
		}
		if ( ! $this->is_unsigned_decimal( $result ) ) {
			$this->schema_fail( $retained, 'snapshot_schema_invalid', 'The snapshot training total exceeds the supported unsigned range.' );
		}
		return $result;
	}

	/** @param array<int,mixed> $left @param array<int,mixed> $right */
	private function compare_course_order( array $left, array $right ): int {
		if ( $left[0] !== $right[0] ) {
			return $left[0] < $right[0] ? -1 : 1;
		}
		if ( $left[1] !== $right[1] ) {
			return $left[1] < $right[1] ? -1 : 1;
		}
		if ( strlen( $left[2] ) !== strlen( $right[2] ) ) {
			return strlen( $left[2] ) < strlen( $right[2] ) ? -1 : 1;
		}
		return strcmp( $left[2], $right[2] );
	}

	/** @param mixed $value */
	private function preflight_document( $value, int $depth = 0 ): void {
		if ( $depth > GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH ) {
			throw $this->invalid( 'side_snapshot_depth_exceeded', 'The canonical snapshot exceeds the approved depth ceiling.' );
		}
		if ( is_string( $value ) && strlen( $value ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
			throw $this->invalid( 'side_snapshot_string_bytes_exceeded', 'A canonical snapshot string exceeds the approved byte ceiling.' );
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && strlen( $key ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
				throw $this->invalid( 'side_snapshot_string_bytes_exceeded', 'A canonical snapshot string exceeds the approved byte ceiling.' );
			}
			$this->preflight_document( $child, $depth + 1 );
		}
		if ( 0 === $depth && isset( $value['source']['evidence_assets'] ) && is_array( $value['source']['evidence_assets'] )
			&& count( $value['source']['evidence_assets'] ) > self::MAX_EVIDENCE_ASSETS ) {
			throw $this->invalid( 'side_evidence_asset_count_exceeded', 'The snapshot evidence-asset count exceeds the approved ceiling.' );
		}
	}

	private function map_canonical_error( InvalidArgumentException $error ): GHCA_ACD_Archive_Persistence_Exception {
		$message = $error->getMessage();
		if ( false !== strpos( $message, 'depth limit' ) ) {
			return $this->invalid( 'side_snapshot_depth_exceeded', 'The canonical snapshot exceeds the approved depth ceiling.' );
		}
		if ( false !== strpos( $message, 'string exceeds the byte limit' ) ) {
			return $this->invalid( 'side_snapshot_string_bytes_exceeded', 'A canonical snapshot string exceeds the approved byte ceiling.' );
		}
		if ( false !== strpos( $message, 'exceeds the byte limit' ) ) {
			return $this->invalid( 'side_snapshot_bytes_exceeded', 'The canonical snapshot exceeds the approved byte ceiling.' );
		}
		return $this->invalid( 'snapshot_canonical_invalid', 'The snapshot document is not valid canonical JSON.' );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_stored_row( array $row ): array {
		if ( '1' !== (string) $row['snapshot_schema_version'] ) {
			throw $this->integrity( 'unsupported_snapshot_schema_version', 'The retained snapshot schema version is not supported.' );
		}
		if ( '1' !== (string) $row['canonical_format_version'] ) {
			throw $this->integrity( 'unsupported_snapshot_canonical_version', 'The retained snapshot canonical format version is not supported.' );
		}
		try {
			$document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $row['snapshot_json'] );
		} catch ( Throwable $error ) {
			throw $this->integrity( 'snapshot_canonical_invalid', 'The retained snapshot JSON is not canonical.' );
		}
		if ( ! is_array( $document ) ) {
			throw $this->integrity( 'snapshot_canonical_invalid', 'The retained snapshot JSON is not a document.' );
		}
		$this->validate_document_schema( $document, true );
		$case = $document['case'];
		$source = $document['source'];
		$policy = $document['policy'];
		$digest = GHCA_ACD_Archive_Digester::snapshot( $document );
		$matches = $this->is_id( $row['source_event_id'] )
			&& (string) $row['snapshot_id'] === $case['snapshot_id']
			&& (string) $row['stream_id'] === $case['stream_id']
			&& (string) $row['archive_id'] === $case['archive_id']
			&& (string) $row['revision_number'] === (string) $case['revision_number']
			&& (string) $row['source_fingerprint_version'] === (string) $source['source_fingerprint_version']
			&& (string) $row['reviewed_source_fingerprint'] === $source['reviewed_source_fingerprint']
			&& (string) $row['captured_source_fingerprint'] === $source['captured_source_fingerprint']
			&& (string) $row['policy_digest'] === $policy['policy_digest']
			&& (string) $row['completeness_policy'] === $policy['completeness_policy']
			&& (string) $row['completeness_result'] === $document['completeness']['result']
			&& (string) $row['snapshot_digest'] === $digest
			&& (string) $row['byte_count'] === (string) strlen( (string) $row['snapshot_json'] )
			&& (string) $row['item_count'] === (string) count( $document['courses'] )
			&& (string) $row['captured_at_gmt'] === GHCA_ACD_Archive_Db_Format::utc_to_db( $document['captured_at_gmt'] );
		if ( ! $matches ) {
			throw $this->integrity( 'snapshot_retained_binding_mismatch', 'The retained snapshot row contradicts its canonical document or digest.' );
		}
		$row['snapshot_document'] = $document;
		return $row;
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function find_duplicate( array $row ) {
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->table()} WHERE snapshot_id = %s OR archive_id = %s OR (stream_id = %s AND revision_number = %d) LIMIT 1",
			$row['snapshot_id'], $row['archive_id'], $row['stream_id'], $row['revision_number']
		);
		$stored = $this->db->get_row( $sql, ARRAY_A );
		$this->assert_no_database_error( 'snapshot_duplicate_lookup_failed' );
		return null === $stored ? null : $this->validate_stored_row( $stored );
	}

	/** @param array<string,mixed> $expected @param array<string,mixed> $stored */
	private function rows_equal( array $expected, array $stored ): bool {
		unset( $stored['snapshot_document'] );
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || (string) $stored[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $value @param array<int,string> $fields */
	private function has_exact_fields( array $value, array $fields ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $fields, SORT_STRING );
		return $actual === $fields;
	}

	/** @param array<string,mixed> $value @param array<int,string> $fields */
	private function assert_exact_fields( array $value, array $fields, string $reason ): void {
		if ( ! $this->has_exact_fields( $value, $fields ) ) {
			throw $this->invalid( $reason, 'The snapshot side-record fields do not match the v1 contract.' );
		}
	}

	/** @param array<mixed,mixed> $value */
	private function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_item ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $value */
	private function is_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	/** @param mixed $value */
	private function is_digest( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	private function assert_id( string $value, string $label ): void {
		if ( ! $this->is_id( $value ) ) {
			throw $this->invalid( 'snapshot_id_invalid', $label . ' is invalid.' );
		}
	}

	private function assert_no_database_error( string $reason ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw $this->internal( $reason, 'The snapshot database operation failed.' );
		}
	}

	private function table(): string {
		return $this->db->prefix . 'ghca_acd_archive_snapshots';
	}

	private function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, $reason, $message );
	}

	private function integrity( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}

	private function internal( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, $reason, $message );
	}
}
