<?php

/**
 * $wpdb implementation of the authoritative archive event store.
 *
 * Append-only: this class exposes no UPDATE or DELETE path for event rows.
 * The only permitted stream mutation is the guarded technical head advance.
 * All methods assume the caller (the unit of work) owns one open transaction
 * on the same database connection.
 */
final class GHCA_ACD_WPDB_Archive_Event_Store implements GHCA_ACD_Archive_Event_Store {
	/** @var wpdb|object */
	private $db;

	/** @param wpdb|object $db */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return wpdb|object The connection this store writes through. */
	public function database() {
		return $this->db;
	}

	public function find_stream_for_update( string $case_key_digest ) {
		$this->assert_digest( $case_key_digest, 'Case-key digest' );
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->streams_table()} WHERE case_key_digest = %s FOR UPDATE",
			$case_key_digest
		);
		try {
			// Driver-level failures (e.g. a lock wait timeout raised as a PHP
			// warning on some runtimes) map to the same stable reason as a
			// reported query error.
			$row = $this->db->get_row( $sql, ARRAY_A );
		} catch ( Throwable $error ) {
			$retryable = GHCA_ACD_Archive_Db_Format::is_retryable_transaction_error( $this->db );
			$this->db->last_error = '';
			throw new GHCA_ACD_Archive_Persistence_Exception(
				$retryable ? GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT : GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$retryable ? 'transaction_retryable_conflict' : 'stream_lookup_failed',
				'An archive persistence query failed.'
			);
		}
		$this->assert_no_database_error( 'stream_lookup_failed' );
		if ( null === $row ) {
			return null;
		}
		return $this->validate_stream_row( $row );
	}

	/** @param array<string,mixed> $identity */
	public function create_stream( array $identity, string $now_gmt ): array {
		$expected = array(
			'stream_id', 'case_key_digest', 'tenant_id', 'site_id', 'employee_user_id',
			'program_key', 'cycle_key', 'cycle_key_digest', 'cycle_start_gmt',
			'cycle_end_gmt', 'cycle_timezone', 'cycle_policy_key',
		);
		$keys = array_keys( $identity );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_stream_identity',
				'Stream identity fields do not match the v1 stream contract.'
			);
		}
		$row = array(
			'stream_id'               => $identity['stream_id'],
			'case_key_digest'         => $identity['case_key_digest'],
			'case_key_format_version' => 1,
			'tenant_id'               => $identity['tenant_id'],
			'site_id'                 => $identity['site_id'],
			'employee_user_id'        => $identity['employee_user_id'],
			'program_key'             => $identity['program_key'],
			'cycle_key'               => $identity['cycle_key'],
			'cycle_key_digest'        => $identity['cycle_key_digest'],
			'cycle_start_gmt'         => GHCA_ACD_Archive_Db_Format::utc_to_db( $identity['cycle_start_gmt'] ),
			'cycle_end_gmt'           => GHCA_ACD_Archive_Db_Format::utc_to_db( $identity['cycle_end_gmt'] ),
			'cycle_timezone'          => $identity['cycle_timezone'],
			'cycle_policy_key'        => $identity['cycle_policy_key'],
			'head_sequence'           => '0',
			'head_event_digest'       => null,
			'created_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
			'updated_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$result  = $this->db->insert( $this->streams_table(), $row, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
					'stream_creation_race',
					'Another connection created this Archive Case stream first.'
				);
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'stream_insert_failed',
				'The Archive Case stream row could not be created.'
			);
		}
		return $this->validate_stream_row( $row );
	}

	public function load_events( string $stream_id ): array {
		$this->assert_id( $stream_id, 'Stream ID' );
		$sql  = $this->db->prepare(
			"SELECT * FROM {$this->events_table()} WHERE stream_id = %s ORDER BY stream_sequence ASC",
			$stream_id
		);
		$rows = $this->db->get_results( $sql, ARRAY_A );
		$this->assert_no_database_error( 'event_load_failed' );
		$events = array();
		foreach ( (array) $rows as $row ) {
			$events[] = $this->row_to_recorded_event( $row );
		}
		try {
			GHCA_ACD_Archive_Event_Stream_Verifier::verify( $events );
		} catch ( InvalidArgumentException $error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'stream_chain_invalid',
				'The stored event stream failed sequence or hash-chain verification.'
			);
		}
		return $events;
	}

	public function append_events( array $recorded_events ): void {
		if ( array() === $recorded_events ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'empty_event_batch',
				'An event batch cannot be empty.'
			);
		}
		foreach ( $recorded_events as $event ) {
			if ( ! $event instanceof GHCA_ACD_Archive_Event || ! $event->is_recorded() ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
					'unrecorded_event',
					'Only fully recorded events can be appended.'
				);
			}
			$this->insert_event_row( $event );
		}
	}

	public function advance_stream_head( string $stream_id, string $expected_sequence, ?string $expected_digest, string $new_sequence, string $new_digest, string $now_gmt ): void {
		$this->assert_id( $stream_id, 'Stream ID' );
		GHCA_ACD_Archive_Db_Format::assert_canonical_sequence( $expected_sequence, 'Expected head sequence' );
		GHCA_ACD_Archive_Db_Format::assert_canonical_sequence( $new_sequence, 'New head sequence' );
		$this->assert_digest( $new_digest, 'New head digest' );
		if ( null !== $expected_digest ) {
			$this->assert_digest( $expected_digest, 'Expected head digest' );
		}
		if ( ! GHCA_ACD_Archive_Db_Format::sequence_greater_than( $new_sequence, $expected_sequence ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'head_not_advancing',
				'The stream head can only advance forward.'
			);
		}
		// CAST keeps the BIGINT UNSIGNED comparison exact; a bare quoted string
		// would compare through double precision and lose exactness above 2^53.
		if ( null === $expected_digest ) {
			$sql = $this->db->prepare(
				"UPDATE {$this->streams_table()} SET head_sequence = %s, head_event_digest = %s, updated_at_gmt = %s
				 WHERE stream_id = %s AND head_sequence = CAST(%s AS UNSIGNED) AND head_event_digest IS NULL",
				$new_sequence,
				$new_digest,
				GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
				$stream_id,
				$expected_sequence
			);
		} else {
			$sql = $this->db->prepare(
				"UPDATE {$this->streams_table()} SET head_sequence = %s, head_event_digest = %s, updated_at_gmt = %s
				 WHERE stream_id = %s AND head_sequence = CAST(%s AS UNSIGNED) AND head_event_digest = %s",
				$new_sequence,
				$new_digest,
				GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
				$stream_id,
				$expected_sequence,
				$expected_digest
			);
		}
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'stream_head_update_failed' );
		if ( 1 !== (int) $result ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
				'stream_head_conflict',
				'The stream head no longer matches the expected sequence and digest.'
			);
		}
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $identity */
	public function stream_identity_matches( array $row, array $identity ): bool {
		foreach ( array( 'case_key_digest', 'tenant_id', 'site_id', 'employee_user_id', 'program_key', 'cycle_key', 'cycle_key_digest', 'cycle_timezone', 'cycle_policy_key' ) as $constituent ) {
			if ( (string) $row[ $constituent ] !== (string) $identity[ $constituent ] ) {
				return false;
			}
		}
		return '1' === (string) $row['case_key_format_version']
			&& (string) $row['cycle_start_gmt'] === GHCA_ACD_Archive_Db_Format::utc_to_db( $identity['cycle_start_gmt'] )
			&& (string) $row['cycle_end_gmt'] === GHCA_ACD_Archive_Db_Format::utc_to_db( $identity['cycle_end_gmt'] );
	}

	private function insert_event_row( GHCA_ACD_Archive_Event $event ): void {
		$document = $event->recorded_document();
		$row      = array(
			'event_id'                 => $document['event_id'],
			'stream_id'                => $document['stream_id'],
			'case_key_digest'          => $document['case_key_digest'],
			'case_key_format_version'  => $document['case_key_format_version'],
			'stream_sequence'          => $document['stream_sequence'],
			'event_type'               => $document['event_type'],
			'event_schema_version'     => $document['event_schema_version'],
			'canonical_format_version' => $document['canonical_format_version'],
			'archive_id'               => $document['archive_id'],
			'build_attempt_id'         => $document['build_attempt_id'],
			'reset_operation_id'       => $document['reset_operation_id'],
			'actor_kind'               => $document['actor_kind'],
			'actor_user_id'            => $document['actor_user_id'],
			'initiating_user_id'       => $document['initiating_user_id'],
			'source_channel'           => $document['source_channel'],
			'authority_code'           => $document['authority_code'],
			'authority_context_json'   => GHCA_ACD_Archive_Canonical_JSON::encode( $document['authority_context'] ),
			'occurred_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
			'effective_at_gmt'         => null === $document['effective_at_gmt'] ? null : GHCA_ACD_Archive_Db_Format::utc_to_db( $document['effective_at_gmt'] ),
			'correlation_id'           => $document['correlation_id'],
			'causation_event_id'       => $document['causation_event_id'],
			'command_id'               => $document['command_id'],
			'upstream_operation_id'    => $document['upstream_operation_id'],
			'idempotency_scope_digest' => $document['idempotency_scope_digest'],
			'idempotency_key_digest'   => $document['idempotency_key_digest'],
			'command_digest'           => $document['command_digest'],
			'reason_code'              => $document['reason_code'],
			'reason_text'              => $document['reason_text'],
			'previous_event_digest'    => $document['previous_event_digest'],
			'event_digest'             => $document['event_digest'],
			'payload_json'             => GHCA_ACD_Archive_Canonical_JSON::encode( $document['payload'] ),
			'metadata_json'            => GHCA_ACD_Archive_Canonical_JSON::encode( $document['metadata'] ),
			'recorded_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['recorded_at_gmt'] ),
		);
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, array( 'case_key_format_version', 'event_schema_version', 'canonical_format_version' ), true ) ? '%d' : '%s';
		}
		$result = $this->db->insert( $this->events_table(), $row, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
					'event_sequence_conflict',
					'A conflicting event already occupies this stream sequence or event identity.'
				);
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'event_insert_failed',
				'The archive event row could not be inserted.'
			);
		}
	}

	/** @param array<string,mixed> $row */
	private function row_to_recorded_event( array $row ): GHCA_ACD_Archive_Event {
		$columns = array(
			'event_id', 'stream_id', 'case_key_digest', 'case_key_format_version', 'stream_sequence',
			'event_type', 'event_schema_version', 'canonical_format_version', 'archive_id',
			'build_attempt_id', 'reset_operation_id', 'actor_kind', 'actor_user_id',
			'initiating_user_id', 'source_channel', 'authority_code', 'authority_context_json',
			'occurred_at_gmt', 'effective_at_gmt', 'correlation_id', 'causation_event_id',
			'command_id', 'upstream_operation_id', 'idempotency_scope_digest',
			'idempotency_key_digest', 'command_digest', 'reason_code', 'reason_text',
			'previous_event_digest', 'event_digest', 'payload_json', 'metadata_json', 'recorded_at_gmt',
		);
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'event_row_incomplete',
					'A stored event row is missing a required column.'
				);
			}
		}
		try {
			$document = array(
				'canonical_format_version' => (int) $row['canonical_format_version'],
				'event_id'                 => (string) $row['event_id'],
				'stream_id'                => (string) $row['stream_id'],
				'case_key_digest'          => (string) $row['case_key_digest'],
				'case_key_format_version'  => (int) $row['case_key_format_version'],
				'stream_sequence'          => (string) $row['stream_sequence'],
				'event_type'               => (string) $row['event_type'],
				'event_schema_version'     => (int) $row['event_schema_version'],
				'archive_id'               => $this->nullable_string( $row['archive_id'] ),
				'build_attempt_id'         => $this->nullable_string( $row['build_attempt_id'] ),
				'reset_operation_id'       => $this->nullable_string( $row['reset_operation_id'] ),
				'actor_kind'               => (string) $row['actor_kind'],
				'actor_user_id'            => $this->nullable_string( $row['actor_user_id'] ),
				'initiating_user_id'       => $this->nullable_string( $row['initiating_user_id'] ),
				'source_channel'           => (string) $row['source_channel'],
				'authority_code'           => (string) $row['authority_code'],
				'authority_context'        => GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $row['authority_context_json'] ),
				'occurred_at_gmt'          => GHCA_ACD_Archive_Db_Format::db_to_utc( (string) $row['occurred_at_gmt'] ),
				'effective_at_gmt'         => null === $row['effective_at_gmt'] ? null : GHCA_ACD_Archive_Db_Format::db_to_utc( (string) $row['effective_at_gmt'] ),
				'correlation_id'           => (string) $row['correlation_id'],
				'causation_event_id'       => $this->nullable_string( $row['causation_event_id'] ),
				'command_id'               => $this->nullable_string( $row['command_id'] ),
				'upstream_operation_id'    => $this->nullable_string( $row['upstream_operation_id'] ),
				'idempotency_scope_digest' => $this->nullable_string( $row['idempotency_scope_digest'] ),
				'idempotency_key_digest'   => $this->nullable_string( $row['idempotency_key_digest'] ),
				'command_digest'           => $this->nullable_string( $row['command_digest'] ),
				'reason_code'              => $this->nullable_string( $row['reason_code'] ),
				'reason_text'              => $this->nullable_string( $row['reason_text'] ),
				'previous_event_digest'    => $this->nullable_string( $row['previous_event_digest'] ),
				'payload'                  => GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $row['payload_json'] ),
				'metadata'                 => GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $row['metadata_json'] ),
				'recorded_at_gmt'          => GHCA_ACD_Archive_Db_Format::db_to_utc( (string) $row['recorded_at_gmt'] ),
				'event_digest'             => (string) $row['event_digest'],
			);
			return GHCA_ACD_Archive_Event::from_recorded( $document );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			throw $error;
		} catch ( Throwable $error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'event_verification_failed',
				'A stored event row failed envelope, payload, or digest verification.'
			);
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_stream_row( array $row ): array {
		$cycle_parts = isset( $row['cycle_key'] ) && is_string( $row['cycle_key'] ) ? explode( '|', $row['cycle_key'] ) : array();
		try {
			$cycle_consistent = 7 === count( $cycle_parts )
				&& 'v1' === $cycle_parts[0]
				&& in_array( $cycle_parts[1], GHCA_ACD_Archive_Cycle::APPROVED_POLICY_KEYS, true )
				&& 1 === preg_match( '/^[1-9][0-9]*$/', $cycle_parts[2] )
				&& '[)' === $cycle_parts[6]
				&& (string) $row['cycle_start_gmt'] === GHCA_ACD_Archive_Db_Format::utc_to_db( $cycle_parts[3] )
				&& (string) $row['cycle_end_gmt'] === GHCA_ACD_Archive_Db_Format::utc_to_db( $cycle_parts[4] )
				&& (string) $row['cycle_timezone'] === $cycle_parts[5]
				&& (string) $row['cycle_policy_key'] === $cycle_parts[1] . '|' . $cycle_parts[2]
				&& hash_equals( (string) $row['cycle_key_digest'], hash( 'sha256', $row['cycle_key'] ) );
		} catch ( Throwable $error ) {
			$cycle_consistent = false;
		}
		$case_digest = isset( $row['tenant_id'], $row['site_id'], $row['employee_user_id'], $row['program_key'], $row['cycle_key'] )
			? GHCA_ACD_Archive_Digester::case_key( (string) $row['tenant_id'], (string) $row['site_id'], (string) $row['employee_user_id'], (string) $row['program_key'], (string) $row['cycle_key'] )
			: '';
		$valid = is_string( $row['stream_id'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $row['stream_id'] )
			&& is_string( $row['case_key_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $row['case_key_digest'] )
			&& '1' === (string) $row['case_key_format_version']
			&& is_string( $row['tenant_id'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $row['tenant_id'] )
			&& 1 === preg_match( '/^[1-9][0-9]*$/', (string) $row['site_id'] )
			&& 1 === preg_match( '/^[1-9][0-9]*$/', (string) $row['employee_user_id'] )
			&& is_string( $row['program_key'] ) && 1 === preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $row['program_key'] )
			&& is_string( $row['cycle_key'] ) && 0 === strpos( $row['cycle_key'], 'v1|' )
			&& is_string( $row['cycle_key_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $row['cycle_key_digest'] )
			&& $cycle_consistent
			&& hash_equals( (string) $row['case_key_digest'], $case_digest )
			&& GHCA_ACD_Archive_Db_Format::is_canonical_sequence( (string) $row['head_sequence'] )
			&& ( null === $row['head_event_digest'] || ( is_string( $row['head_event_digest'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $row['head_event_digest'] ) ) )
			&& ( ( '0' === (string) $row['head_sequence'] ) === ( null === $row['head_event_digest'] ) );
		if ( ! $valid ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'stream_row_invalid',
				'The stored stream row failed boundary validation.'
			);
		}
		$row['head_sequence'] = (string) $row['head_sequence'];
		return $row;
	}

	/** @param mixed $value */
	private function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	private function assert_digest( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_digest_argument',
				$label . ' must be 64 lowercase hexadecimal characters.'
			);
		}
	}

	private function assert_id( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $value ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_id_argument',
				$label . ' must be 32 lowercase hexadecimal characters.'
			);
		}
	}

	private function assert_no_database_error( string $reason_code ): void {
		if ( '' !== (string) $this->db->last_error ) {
			$retryable = GHCA_ACD_Archive_Db_Format::is_retryable_transaction_error( $this->db );
			throw new GHCA_ACD_Archive_Persistence_Exception(
				$retryable ? GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT : GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$retryable ? 'transaction_retryable_conflict' : $reason_code,
				'An archive persistence query failed.'
			);
		}
	}

	private function streams_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_streams';
	}

	private function events_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_events';
	}
}
