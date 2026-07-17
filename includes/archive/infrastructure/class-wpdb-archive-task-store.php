<?php

/**
 * Durable archive-task persistence with insert-once dedupe and lease fencing.
 * This component schedules and fences database work only; it registers no
 * worker, hook, cron event, or external I/O.
 */
final class GHCA_ACD_WPDB_Archive_Task_Store {
	const TASK_SCHEMA_VERSION = 1;
	const MAX_ATTEMPTS        = 5;
	const TASK_TYPES = array(
		'capture_evidence', 'materialize_ledger', 'materialize_packet',
		'verify_and_finalize', 'expire_reset_authorization', 'execute_reset',
		'reconcile_reset', 'verify_integrity', 'reconcile_orphan_artifacts',
		'rebuild_projection',
	);

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

	/** @param array<string,mixed> $task */
	public function enqueue( array $task ): void {
		$expected = array(
			'task_id', 'trigger_kind', 'trigger_event_id', 'trigger_command_id',
			'stream_id', 'archive_id', 'build_attempt_id', 'reset_operation_id',
			'task_type', 'task_schema_version', 'dedupe_digest', 'payload_json',
			'task_state', 'attempt_count', 'max_attempts', 'available_at_gmt',
			'lease_owner', 'lease_token', 'lease_until_gmt', 'last_error_code',
			'last_error_text', 'created_at_gmt', 'updated_at_gmt', 'completed_at_gmt',
		);
		$keys = array_keys( $task );
		$sorted = $expected;
		sort( $keys, SORT_STRING );
		sort( $sorted, SORT_STRING );
		if ( $keys !== $sorted
			|| ! $this->is_id( $task['task_id'] )
			|| 'event' !== $task['trigger_kind']
			|| ! $this->is_id( $task['trigger_event_id'] )
			|| null !== $task['trigger_command_id']
			|| ! $this->is_id( $task['stream_id'] )
			|| ! in_array( $task['task_type'], self::TASK_TYPES, true )
			|| self::TASK_SCHEMA_VERSION !== $task['task_schema_version']
			|| ! $this->is_digest( $task['dedupe_digest'] )
			|| 'pending' !== $task['task_state']
			|| 0 !== $task['attempt_count']
			|| self::MAX_ATTEMPTS !== $task['max_attempts']
			|| null !== $task['lease_owner'] || null !== $task['lease_token'] || null !== $task['lease_until_gmt']
			|| null !== $task['last_error_code'] || null !== $task['last_error_text'] || null !== $task['completed_at_gmt'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_row',
				'Task fields do not match the v1 durable-task contract.'
			);
		}
		try {
			$payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $task['payload_json'] );
		} catch ( Throwable $error ) {
			$payload = null;
		}
		if ( ! is_array( $payload ) || GHCA_ACD_Archive_Canonical_JSON::encode( $payload ) !== $task['payload_json'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_payload',
				'Task payload JSON must be canonical.'
			);
		}
		$formats = array();
		foreach ( $expected as $column ) {
			$formats[] = in_array( $column, array( 'task_schema_version', 'attempt_count', 'max_attempts' ), true ) ? '%d' : '%s';
		}
		$result = $this->db->insert( $this->tasks_table(), $task, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'task_dedupe_conflict',
					'A durable task with this identity already exists.'
				);
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'task_insert_failed',
				'The durable archive task could not be inserted.'
			);
		}
	}

	/** @return array<string,mixed>|null */
	public function find( string $task_id ) {
		$this->assert_id( $task_id );
		$sql = $this->db->prepare( "SELECT * FROM {$this->tasks_table()} WHERE task_id = %s", $task_id );
		$row = $this->db->get_row( $sql, ARRAY_A );
		$this->assert_no_database_error( 'task_lookup_failed' );
		return null === $row ? null : $this->validate_loaded_row( $row );
	}

	/**
	 * Claim or reclaim one known task using a single compare-and-set update.
	 * An expired lease may be replaced; a live lease cannot be stolen.
	 */
	public function claim( string $task_id, string $lease_owner, string $lease_token, string $now_gmt, string $lease_until_gmt ): bool {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$now_db   = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$until_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $lease_until_gmt );
		if ( strcmp( $now_db, $until_db ) >= 0 ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_lease_window',
				'Task lease expiry must be after claim time.'
			);
		}
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()}
			 SET task_state = 'leased', attempt_count = attempt_count + 1,
			     lease_owner = %s, lease_token = %s, lease_until_gmt = %s, updated_at_gmt = %s
			 WHERE task_id = %s AND available_at_gmt <= %s AND attempt_count < max_attempts
			   AND (task_state = 'pending' OR (task_state = 'leased' AND lease_until_gmt <= %s))",
			$lease_owner, $lease_token, $until_db, $now_db, $task_id, $now_db, $now_db
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'task_claim_failed' );
		return 1 === (int) $result;
	}

	public function heartbeat( string $task_id, string $lease_owner, string $lease_token, string $now_gmt, string $lease_until_gmt ): void {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$now_db   = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$until_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $lease_until_gmt );
		if ( strcmp( $now_db, $until_db ) >= 0 ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_lease_window',
				'Task lease expiry must be after heartbeat time.'
			);
		}
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()} SET lease_until_gmt = %s, updated_at_gmt = %s
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s AND lease_token = %s
			   AND lease_until_gmt > %s AND lease_until_gmt < %s",
			$until_db, $now_db, $task_id, $lease_owner, $lease_token, $now_db, $until_db
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'task_heartbeat_update_failed' );
		$this->assert_fenced_update( $result, 'task_heartbeat_fence_failed' );
	}

	public function complete( string $task_id, string $lease_owner, string $lease_token, string $now_gmt ): void {
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$this->lease_guarded_update( $task_id, $lease_owner, $lease_token, $now_db, array(
			'task_state'       => 'completed',
			'lease_owner'      => null,
			'lease_token'      => null,
			'lease_until_gmt'  => null,
			'updated_at_gmt'   => $now_db,
			'completed_at_gmt' => $now_db,
		), 'task_completion_fence_failed' );
	}

	public function fail_and_reschedule( string $task_id, string $lease_owner, string $lease_token, string $available_at_gmt, string $error_code, string $error_text, string $now_gmt ): void {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $error_code ) || strlen( $error_text ) > 2000 ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_failure',
				'Task failure information is invalid.'
			);
		}
		$available_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $available_at_gmt );
		$now_db       = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()}
			 SET task_state = CASE WHEN attempt_count >= max_attempts THEN 'dead' ELSE 'pending' END,
			     available_at_gmt = %s, lease_owner = NULL, lease_token = NULL,
			     lease_until_gmt = NULL, last_error_code = %s, last_error_text = %s,
			     updated_at_gmt = %s
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s AND lease_token = %s
			   AND lease_until_gmt > %s",
			$available_db, $error_code, $error_text, $now_db, $task_id, $lease_owner, $lease_token, $now_db
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'task_failure_update_failed' );
		$this->assert_fenced_update( $result, 'task_failure_fence_failed' );
	}

	/** @param array<string,mixed> $columns */
	private function lease_guarded_update( string $task_id, string $lease_owner, string $lease_token, string $now_db, array $columns, string $reason ): void {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$assignments = array();
		$values = array();
		foreach ( $columns as $column => $value ) {
			if ( null === $value ) {
				$assignments[] = $column . ' = NULL';
			} else {
				$assignments[] = $column . ' = %s';
				$values[] = $value;
			}
		}
		$values[] = $task_id;
		$values[] = $lease_owner;
		$values[] = $lease_token;
		$values[] = $now_db;
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()} SET " . implode( ', ', $assignments ) . " WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s AND lease_token = %s AND lease_until_gmt > %s",
			$values
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'task_lease_update_failed' );
		$this->assert_fenced_update( $result, $reason );
	}

	/** @param mixed $result */
	private function assert_fenced_update( $result, string $reason ): void {
		if ( 1 !== (int) $result ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				$reason,
				'The exact live task lease no longer owns this task.'
			);
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_loaded_row( array $row ): array {
		if ( ! array_key_exists( 'task_schema_version', $row ) ) {
			$this->invalid_stored_row();
		}
		if ( (string) self::TASK_SCHEMA_VERSION !== (string) $row['task_schema_version'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'unsupported_task_schema_version',
				'The retained task schema version is not supported.'
			);
		}
		$payload = null;
		try {
			if ( is_string( $row['payload_json'] ) ) {
				$payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $row['payload_json'] );
			}
		} catch ( Throwable $error ) {
			$payload = null;
		}
		if ( ! is_array( $payload ) || GHCA_ACD_Archive_Canonical_JSON::encode( $payload ) !== $row['payload_json'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'invalid_stored_task_payload',
				'The retained task payload is not canonical.'
			);
		}

		$states  = array( 'pending', 'leased', 'completed', 'dead' );
		$attempt = isset( $row['attempt_count'] ) ? (string) $row['attempt_count'] : '';
		$maximum = isset( $row['max_attempts'] ) ? (string) $row['max_attempts'] : '';
		$nullable_ids = array( 'archive_id', 'build_attempt_id', 'reset_operation_id' );
		$valid_nullable_ids = true;
		foreach ( $nullable_ids as $field ) {
			$valid_nullable_ids = $valid_nullable_ids && array_key_exists( $field, $row ) && ( null === $row[ $field ] || $this->is_id( $row[ $field ] ) );
		}
		$valid_payload_binding = isset( $payload['canonical_format_version'], $payload['task_schema_version'], $payload['task_type'], $payload['trigger_event_id'], $payload['stream_id'] )
			&& GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION === $payload['canonical_format_version']
			&& self::TASK_SCHEMA_VERSION === $payload['task_schema_version']
			&& $row['task_type'] === $payload['task_type']
			&& $row['trigger_event_id'] === $payload['trigger_event_id']
			&& $row['stream_id'] === $payload['stream_id'];
		if ( ! $this->is_id( $row['task_id'] )
			|| 'event' !== $row['trigger_kind']
			|| ! $this->is_id( $row['trigger_event_id'] )
			|| null !== $row['trigger_command_id']
			|| ! $this->is_id( $row['stream_id'] )
			|| ! $valid_nullable_ids
			|| ! in_array( $row['task_type'], self::TASK_TYPES, true )
			|| ! in_array( $row['task_state'], $states, true )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $attempt )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $maximum )
			|| (string) self::MAX_ATTEMPTS !== $maximum
			|| (int) $attempt > self::MAX_ATTEMPTS
			|| ! $valid_payload_binding ) {
			$this->invalid_stored_row();
		}
		$this->assert_stored_task_state( $row, (int) $attempt );
		$this->assert_stored_task_datetimes( $row );

		$expected_dedupe = GHCA_ACD_Archive_Digester::task_dedupe( array(
			'payload'          => $payload,
			'task_type'        => $row['task_type'],
			'trigger_event_id' => $row['trigger_event_id'],
		) );
		if ( ! $this->is_digest( $row['dedupe_digest'] ) || ! hash_equals( $expected_dedupe, $row['dedupe_digest'] ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'stored_task_dedupe_mismatch',
				'The retained task dedupe identity does not match its canonical payload.'
			);
		}
		return $row;
	}

	/** @param array<string,mixed> $row */
	private function assert_stored_task_state( array $row, int $attempt ): void {
		$leased = 'leased' === $row['task_state'];
		$lease_fields_valid = $this->is_id( $row['lease_owner'] ) && $this->is_id( $row['lease_token'] ) && is_string( $row['lease_until_gmt'] );
		$lease_fields_clear = null === $row['lease_owner'] && null === $row['lease_token'] && null === $row['lease_until_gmt'];
		$completed = 'completed' === $row['task_state'];
		$error_clear = null === $row['last_error_code'] && null === $row['last_error_text'];
		$error_valid = is_string( $row['last_error_code'] ) && 1 === preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $row['last_error_code'] )
			&& is_string( $row['last_error_text'] ) && strlen( $row['last_error_text'] ) <= 2000;
		if ( ( $leased && ! $lease_fields_valid )
			|| ( ! $leased && ! $lease_fields_clear )
			|| ( $completed !== ( null !== $row['completed_at_gmt'] ) )
			|| ( 'pending' === $row['task_state'] && $attempt >= self::MAX_ATTEMPTS )
			|| ( 'dead' === $row['task_state'] && $attempt !== self::MAX_ATTEMPTS )
			|| ( in_array( $row['task_state'], array( 'leased', 'completed' ), true ) && $attempt < 1 )
			|| ( ! $error_clear && ! $error_valid ) ) {
			$this->invalid_stored_row();
		}
	}

	/** @param array<string,mixed> $row */
	private function assert_stored_task_datetimes( array $row ): void {
		try {
			foreach ( array( 'available_at_gmt', 'created_at_gmt', 'updated_at_gmt' ) as $field ) {
				GHCA_ACD_Archive_Db_Format::db_to_utc( $row[ $field ] );
			}
			foreach ( array( 'lease_until_gmt', 'completed_at_gmt' ) as $field ) {
				if ( null !== $row[ $field ] ) {
					GHCA_ACD_Archive_Db_Format::db_to_utc( $row[ $field ] );
				}
			}
		} catch ( Throwable $error ) {
			$this->invalid_stored_row();
		}
	}

	private function invalid_stored_row(): void {
		throw new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
			'invalid_stored_task_row',
			'The retained task row violates the v1 persistence contract.'
		);
	}

	/** @param mixed $value */
	private function is_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	/** @param mixed $value */
	private function is_digest( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	private function assert_id( string $value ): void {
		if ( ! $this->is_id( $value ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_id',
				'Task identifiers must be 32 lowercase hexadecimal characters.'
			);
		}
	}

	private function assert_no_database_error( string $reason ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$reason,
				'A durable task query failed.'
			);
		}
	}

	private function tasks_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_tasks';
	}
}
