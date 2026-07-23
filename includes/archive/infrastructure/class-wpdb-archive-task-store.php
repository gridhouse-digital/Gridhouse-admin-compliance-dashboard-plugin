<?php

/**
 * Durable archive-task persistence and lease fencing.
 *
 * Claim lock order is task row, then commit. Worker outcome transactions use
 * task row -> stream row -> command receipt. No external work runs while a
 * task-row lock is held.
 */
final class GHCA_ACD_WPDB_Archive_Task_Store {
	const TASK_SCHEMA_VERSION = 1;
	const MAX_ATTEMPTS        = 5;
	const LEASE_SECONDS       = 120;
	const HEARTBEAT_SECONDS   = 30;
	const RETRY_DELAYS = array( 1 => 60, 2 => 300, 3 => 900, 4 => 3600 );
	const TASK_TYPES = array(
		'capture_evidence', 'materialize_ledger', 'materialize_packet',
		'verify_and_finalize', 'expire_reset_authorization', 'execute_reset',
		'reconcile_reset', 'verify_integrity', 'reconcile_orphan_artifacts',
		'rebuild_projection',
	);

	/** @var wpdb|object */
	private $db;
	/** @var bool */
	private $in_transaction = false;
	/** @var bool */
	private $transaction_unusable = false;

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
		$keys   = array_keys( $task );
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
			throw $this->internal( 'invalid_task_row', 'Task fields do not match the v1 durable-task contract.' );
		}
		try {
			$payload = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $task['payload_json'] );
		} catch ( Throwable $error ) {
			$payload = null;
		}
		if ( ! is_array( $payload ) || GHCA_ACD_Archive_Canonical_JSON::encode( $payload ) !== $task['payload_json'] ) {
			throw $this->internal( 'invalid_task_payload', 'Task payload JSON must be canonical.' );
		}
		GHCA_ACD_Archive_Task_Catalog::validate_claimed_payload( $task, $payload );
		$formats = array();
		foreach ( $expected as $column ) {
			$formats[] = in_array( $column, array( 'task_schema_version', 'attempt_count', 'max_attempts' ), true ) ? '%d' : '%s';
		}
		$result = $this->db->insert( $this->tasks_table(), $task, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw $this->integrity( 'task_dedupe_conflict', 'A durable task with this identity already exists.' );
			}
			throw $this->internal( 'task_insert_failed', 'The durable archive task could not be inserted.' );
		}
	}

	/** @return array<string,mixed>|null */
	public function find( string $task_id ) {
		$this->assert_id( $task_id );
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->tasks_table()} WHERE task_id = %s", $task_id ), ARRAY_A );
		$this->assert_no_database_error( 'task_lookup_failed', 'A durable task query failed.' );
		return null === $row ? null : $this->validate_loaded_row( $row );
	}

	/** @return array<string,mixed>|null */
	public function reclaim_expired( string $lease_owner, string $lease_token, string $now_gmt, ?array $installed_types = null ) {
		return $this->claim_selected( true, $lease_owner, $lease_token, $now_gmt, $installed_types );
	}

	/** @return array<string,mixed>|null */
	public function claim_available( string $lease_owner, string $lease_token, string $now_gmt, ?array $installed_types = null ) {
		return $this->claim_selected( false, $lease_owner, $lease_token, $now_gmt, $installed_types );
	}

	/** @return array<string,mixed> */
	public function load_claimed( string $task_id, string $lease_owner, string $lease_token, string $now_gmt ): array {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->tasks_table()}
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s
			   AND lease_token = %s AND lease_until_gmt > %s",
			$task_id, $lease_owner, $lease_token, $now_db
		);
		$row = $this->db->get_row( $sql, ARRAY_A );
		$this->assert_no_database_error( 'task_lease_lost', 'The claimed task could not be reloaded.' );
		if ( null === $row ) {
			throw $this->integrity( 'task_lease_lost', 'The exact live task lease no longer owns this task.' );
		}
		$this->validate_claim_envelope( $row );
		return $row;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function validate_claimed_v1( array $row ): array {
		if ( ! isset( $row['task_schema_version'] ) || (string) self::TASK_SCHEMA_VERSION !== (string) $row['task_schema_version'] ) {
			throw $this->integrity( 'task_schema_unsupported', 'The retained task schema version is not supported.' );
		}
		if ( ! isset( $row['task_type'] ) || ! in_array( $row['task_type'], self::TASK_TYPES, true ) ) {
			throw $this->integrity( 'task_type_unsupported', 'The retained task type is not supported.' );
		}
		$valid_identity = isset( $row['task_id'], $row['trigger_kind'], $row['trigger_event_id'], $row['stream_id'] )
			&& $this->is_id( $row['task_id'] ) && 'event' === $row['trigger_kind']
			&& $this->is_id( $row['trigger_event_id'] ) && null === $row['trigger_command_id']
			&& $this->is_id( $row['stream_id'] );
		foreach ( array( 'archive_id', 'build_attempt_id', 'reset_operation_id' ) as $field ) {
			$valid_identity = $valid_identity && array_key_exists( $field, $row ) && ( null === $row[ $field ] || $this->is_id( $row[ $field ] ) );
		}
		try {
			$payload = is_string( $row['payload_json'] ) ? GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $row['payload_json'] ) : null;
			$valid_binding = is_array( $payload )
				&& GHCA_ACD_Archive_Canonical_JSON::encode( $payload ) === $row['payload_json']
				&& isset( $payload['canonical_format_version'], $payload['task_schema_version'], $payload['task_type'], $payload['trigger_event_id'], $payload['stream_id'] )
				&& $this->canonical_format_matches_task( $row['task_type'], $payload['canonical_format_version'] )
				&& self::TASK_SCHEMA_VERSION === $payload['task_schema_version']
				&& $row['task_type'] === $payload['task_type']
				&& $row['trigger_event_id'] === $payload['trigger_event_id']
				&& $row['stream_id'] === $payload['stream_id'];
			$expected_dedupe = $valid_binding ? GHCA_ACD_Archive_Digester::task_dedupe( array(
				'payload'          => $payload,
				'task_type'        => $row['task_type'],
				'trigger_event_id' => $row['trigger_event_id'],
			) ) : '';
		} catch ( Throwable $error ) {
			$valid_binding = false;
			$payload       = null;
			$expected_dedupe = '';
		}
		if ( ! $valid_identity || ! $valid_binding || ! $this->is_digest( $row['dedupe_digest'] ) || ! hash_equals( $expected_dedupe, $row['dedupe_digest'] ) ) {
			throw $this->integrity( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		$payload = GHCA_ACD_Archive_Task_Catalog::validate_claimed_payload( $row, $payload );
		$row['payload'] = $payload;
		return $row;
	}

	public function heartbeat( string $task_id, string $lease_owner, string $lease_token, string $now_gmt, string $lease_until_gmt ): void {
		$now_epoch   = $this->utc_epoch( $now_gmt );
		$until_epoch = $this->utc_epoch( $lease_until_gmt );
		if ( $until_epoch <= $now_epoch ) {
			throw $this->internal( 'invalid_task_lease_window', 'Task lease expiry must be after heartbeat time.' );
		}
		if ( $until_epoch - $now_epoch > self::LEASE_SECONDS ) {
			throw $this->internal( 'task_lease_extension_exceeded', 'Task heartbeat lease extension exceeds the approved horizon.' );
		}
		$now_db   = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$until_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $lease_until_gmt );
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()} SET lease_until_gmt = %s, updated_at_gmt = %s
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s AND lease_token = %s
			   AND lease_until_gmt > %s AND lease_until_gmt < %s",
			$until_db, $now_db, $task_id, $lease_owner, $lease_token, $now_db, $until_db
		);
		$this->fenced_query( $sql, 'task_heartbeat_fence_failed' );
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

	public function retry( string $task_id, string $lease_owner, string $lease_token, string $error_code, string $error_text, string $now_gmt ): void {
		$this->assert_failure( $error_code, $error_text );
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()}
			 SET task_state = 'retry',
			     available_at_gmt = CASE attempt_count
			       WHEN 1 THEN DATE_ADD(%s, INTERVAL 60 SECOND)
			       WHEN 2 THEN DATE_ADD(%s, INTERVAL 300 SECOND)
			       WHEN 3 THEN DATE_ADD(%s, INTERVAL 900 SECOND)
			       WHEN 4 THEN DATE_ADD(%s, INTERVAL 3600 SECOND)
			     END,
			     lease_owner = NULL, lease_token = NULL, lease_until_gmt = NULL,
			     last_error_code = %s, last_error_text = %s, updated_at_gmt = %s,
			     completed_at_gmt = NULL
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s AND lease_token = %s
			   AND lease_until_gmt > %s AND attempt_count BETWEEN 1 AND 4",
			$now_db, $now_db, $now_db, $now_db, $error_code, $error_text, $now_db,
			$task_id, $lease_owner, $lease_token, $now_db
		);
		$this->fenced_query( $sql, 'task_retry_fence_failed' );
	}

	public function dead_letter( string $task_id, string $lease_owner, string $lease_token, string $error_code, string $error_text, string $now_gmt ): void {
		$this->assert_failure( $error_code, $error_text );
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$this->lease_guarded_update( $task_id, $lease_owner, $lease_token, $now_db, array(
			'task_state'       => 'dead',
			'lease_owner'      => null,
			'lease_token'      => null,
			'lease_until_gmt'  => null,
			'last_error_code'  => $error_code,
			'last_error_text'  => $error_text,
			'updated_at_gmt'   => $now_db,
			'completed_at_gmt' => null,
		), 'task_dead_letter_fence_failed' );
	}

	/** Lock and validate a worker task before stream/receipt locks in the UoW. @return array<string,mixed> */
	public function assert_live_lease_for_update( string $task_id, string $lease_owner, string $lease_token, string $now_gmt ): array {
		return $this->select_live_lease( $task_id, $lease_owner, $lease_token, $now_gmt, true, 'task_outcome_fence_failed' );
	}

	/** Recheck a lease outside a transaction immediately before outcome submission. @return array<string,mixed> */
	public function assert_live_lease( string $task_id, string $lease_owner, string $lease_token, string $now_gmt ): array {
		return $this->select_live_lease( $task_id, $lease_owner, $lease_token, $now_gmt, false, 'task_lease_lost' );
	}

	public static function retry_delay( int $attempt ): int {
		if ( ! isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_attempt',
				'The durable task attempt has no retry delay.'
			);
		}
		return self::RETRY_DELAYS[ $attempt ];
	}

	public static function lease_until( string $now_gmt ): string {
		GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$epoch = strtotime( $now_gmt );
		if ( false === $epoch ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_task_lease_window',
				'Task lease time is invalid.'
			);
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $epoch + self::LEASE_SECONDS );
	}

	/** @return array<string,mixed>|null */
	private function claim_selected( bool $expired, string $lease_owner, string $lease_token, string $now_gmt, ?array $installed_types = null, int $retry = 0 ) {
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$installed_types = GHCA_ACD_Archive_Task_Catalog::normalize_installed_types( null === $installed_types ? self::TASK_TYPES : $installed_types );
		$now_db   = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$until_db = GHCA_ACD_Archive_Db_Format::utc_to_db( self::lease_until( $now_gmt ) );
		$select_reason = $expired ? 'task_reclaim_select_failed' : 'task_available_select_failed';
		$type_placeholders = implode( ',', array_fill( 0, count( $installed_types ), '%s' ) );
		$this->begin();
		try {
			$where = $expired
				? "task_state = 'leased' AND lease_until_gmt <= %s"
				: "task_state IN ('pending','retry') AND available_at_gmt <= %s AND attempt_count < max_attempts";
			$select_values = array_merge( array( $now_db ), $installed_types );
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM {$this->tasks_table()} WHERE {$where} AND task_type IN ({$type_placeholders})
				 ORDER BY available_at_gmt ASC, task_row_id ASC LIMIT 1 FOR UPDATE",
				$select_values
			), ARRAY_A );
			$this->assert_no_database_error( $select_reason, 'The durable task selection query failed.' );
			if ( null === $row ) {
				$this->commit();
				return null;
			}
			$this->validate_claim_envelope( $row );
			$attempt   = (int) $row['attempt_count'];
			$exhausted = $expired && $attempt >= self::MAX_ATTEMPTS;
			$new_attempt = $exhausted ? $attempt : $attempt + 1;
			$where_update = $expired
				? "task_id = %s AND task_state = 'leased' AND lease_until_gmt <= %s"
				: "task_id = %s AND task_state IN ('pending','retry') AND available_at_gmt <= %s AND attempt_count < max_attempts";
			$update_values = array_merge(
				array( $new_attempt, $lease_owner, $lease_token, $until_db, $now_db, $row['task_id'], $now_db ),
				$installed_types
			);
			$sql = $this->db->prepare(
				"UPDATE {$this->tasks_table()}
				 SET task_state = 'leased', attempt_count = %d, lease_owner = %s,
				     lease_token = %s, lease_until_gmt = %s, updated_at_gmt = %s
				 WHERE {$where_update} AND task_type IN ({$type_placeholders})",
				$update_values
			);
			$result = $this->db->query( $sql );
			$this->assert_no_database_error( 'task_claim_update_failed', 'The durable task claim update failed.' );
			if ( 1 !== (int) $result ) {
				throw $this->integrity( 'task_claim_update_failed', 'The selected durable task could not be claimed.' );
			}
			$row['task_state']      = 'leased';
			$row['attempt_count']   = (string) $new_attempt;
			$row['lease_owner']     = $lease_owner;
			$row['lease_token']     = $lease_token;
			$row['lease_until_gmt'] = $until_db;
			$row['updated_at_gmt']  = $now_db;
			$row['exhausted']       = $exhausted;
			$this->commit();
			return $row;
		} catch ( Throwable $error ) {
			$retryable = GHCA_ACD_Archive_Db_Format::is_retryable_transaction_error( $this->db );
			$this->rollback();
			if ( $retryable && $retry < 2 ) {
				return $this->claim_selected( $expired, $lease_owner, $lease_token, $now_gmt, $installed_types, $retry + 1 );
			}
			throw $error;
		}
	}

	/** @return array<string,mixed> */
	private function select_live_lease( string $task_id, string $lease_owner, string $lease_token, string $now_gmt, bool $for_update, string $reason ): array {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->tasks_table()}
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s
			   AND lease_token = %s AND lease_until_gmt > %s" . ( $for_update ? ' FOR UPDATE' : '' ),
			$task_id, $lease_owner, $lease_token, $now_db
		);
		$row = $this->db->get_row( $sql, ARRAY_A );
		$this->assert_no_database_error( $reason, 'The exact live task lease could not be checked.' );
		if ( null === $row ) {
			throw $this->integrity( $reason, 'The exact live task lease no longer owns this task.' );
		}
		$this->validate_claim_envelope( $row );
		return $row;
	}

	/** @param array<string,mixed> $columns */
	private function lease_guarded_update( string $task_id, string $lease_owner, string $lease_token, string $now_db, array $columns, string $reason ): void {
		$this->assert_id( $task_id );
		$this->assert_id( $lease_owner );
		$this->assert_id( $lease_token );
		$assignments = array();
		$values      = array();
		foreach ( $columns as $column => $value ) {
			if ( null === $value ) {
				$assignments[] = $column . ' = NULL';
			} else {
				$assignments[] = $column . ' = %s';
				$values[]      = $value;
			}
		}
		$values[] = $task_id;
		$values[] = $lease_owner;
		$values[] = $lease_token;
		$values[] = $now_db;
		$sql = $this->db->prepare(
			"UPDATE {$this->tasks_table()} SET " . implode( ', ', $assignments ) . "
			 WHERE task_id = %s AND task_state = 'leased' AND lease_owner = %s
			   AND lease_token = %s AND lease_until_gmt > %s",
			$values
		);
		$this->fenced_query( $sql, $reason );
	}

	private function fenced_query( string $sql, string $reason ): void {
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( $reason, 'The fenced durable task mutation failed.' );
		if ( 1 !== (int) $result ) {
			throw $this->integrity( $reason, 'The exact live task lease no longer owns this task.' );
		}
	}

	/** @param array<string,mixed> $row */
	private function validate_claim_envelope( array $row ): void {
		$attempt = isset( $row['attempt_count'] ) ? (string) $row['attempt_count'] : '';
		$maximum = isset( $row['max_attempts'] ) ? (string) $row['max_attempts'] : '';
		$error_clear = array_key_exists( 'last_error_code', $row ) && null === $row['last_error_code']
			&& array_key_exists( 'last_error_text', $row ) && null === $row['last_error_text'];
		$error_valid = isset( $row['last_error_code'], $row['last_error_text'] )
			&& is_string( $row['last_error_code'] ) && 1 === preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $row['last_error_code'] )
			&& is_string( $row['last_error_text'] ) && strlen( $row['last_error_text'] ) <= 2000;
		$lease_clear = array_key_exists( 'lease_owner', $row ) && null === $row['lease_owner']
			&& array_key_exists( 'lease_token', $row ) && null === $row['lease_token']
			&& array_key_exists( 'lease_until_gmt', $row ) && null === $row['lease_until_gmt'];
		if ( ! isset( $row['task_id'], $row['task_state'], $row['available_at_gmt'], $row['created_at_gmt'], $row['updated_at_gmt'] )
			|| ! $this->is_id( $row['task_id'] )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $attempt )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $maximum )
			|| (string) self::MAX_ATTEMPTS !== $maximum
			|| (int) $attempt > self::MAX_ATTEMPTS
			|| ! in_array( $row['task_state'], array( 'pending', 'retry', 'leased' ), true )
			|| ( 'pending' === $row['task_state'] && ( 0 !== (int) $attempt || ! $lease_clear || ! $error_clear ) )
			|| ( 'retry' === $row['task_state'] && ( (int) $attempt < 1 || (int) $attempt >= self::MAX_ATTEMPTS || ! $lease_clear || ! $error_valid ) )
			|| ( 'leased' === $row['task_state'] && ( (int) $attempt < 1 || ( ! $error_clear && ! $error_valid ) ) )
			|| null !== $row['completed_at_gmt'] ) {
			$this->invalid_stored_row();
		}
		try {
			foreach ( array( 'available_at_gmt', 'created_at_gmt', 'updated_at_gmt' ) as $field ) {
				GHCA_ACD_Archive_Db_Format::db_to_utc( $row[ $field ] );
			}
			if ( 'leased' === $row['task_state'] ) {
				if ( ! $this->is_id( $row['lease_owner'] ) || ! $this->is_id( $row['lease_token'] ) || ! is_string( $row['lease_until_gmt'] ) ) {
					$this->invalid_stored_row();
				}
				GHCA_ACD_Archive_Db_Format::db_to_utc( $row['lease_until_gmt'] );
			}
		} catch ( Throwable $error ) {
			$this->invalid_stored_row();
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_loaded_row( array $row ): array {
		if ( ! array_key_exists( 'task_schema_version', $row ) ) {
			$this->invalid_stored_row();
		}
		if ( (string) self::TASK_SCHEMA_VERSION !== (string) $row['task_schema_version'] ) {
			throw $this->integrity( 'unsupported_task_schema_version', 'The retained task schema version is not supported.' );
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
			throw $this->integrity( 'invalid_stored_task_payload', 'The retained task payload is not canonical.' );
		}
		$attempt = isset( $row['attempt_count'] ) ? (string) $row['attempt_count'] : '';
		$maximum = isset( $row['max_attempts'] ) ? (string) $row['max_attempts'] : '';
		$valid_nullable_ids = true;
		foreach ( array( 'archive_id', 'build_attempt_id', 'reset_operation_id' ) as $field ) {
			$valid_nullable_ids = $valid_nullable_ids && array_key_exists( $field, $row ) && ( null === $row[ $field ] || $this->is_id( $row[ $field ] ) );
		}
		$valid_payload_binding = isset( $payload['canonical_format_version'], $payload['task_schema_version'], $payload['task_type'], $payload['trigger_event_id'], $payload['stream_id'] )
			&& $this->canonical_format_matches_task( $row['task_type'], $payload['canonical_format_version'] )
			&& self::TASK_SCHEMA_VERSION === $payload['task_schema_version']
			&& $row['task_type'] === $payload['task_type']
			&& $row['trigger_event_id'] === $payload['trigger_event_id']
			&& $row['stream_id'] === $payload['stream_id'];
		if ( ! $this->is_id( $row['task_id'] ) || 'event' !== $row['trigger_kind']
			|| ! $this->is_id( $row['trigger_event_id'] ) || null !== $row['trigger_command_id']
			|| ! $this->is_id( $row['stream_id'] ) || ! $valid_nullable_ids
			|| ! in_array( $row['task_type'], self::TASK_TYPES, true )
			|| ! in_array( $row['task_state'], array( 'pending', 'retry', 'leased', 'completed', 'dead' ), true )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $attempt )
			|| ! GHCA_ACD_Archive_Db_Format::is_canonical_sequence( $maximum )
			|| (string) self::MAX_ATTEMPTS !== $maximum || (int) $attempt > self::MAX_ATTEMPTS
			|| ! $valid_payload_binding ) {
			$this->invalid_stored_row();
		}
		GHCA_ACD_Archive_Task_Catalog::validate_claimed_payload( $row, $payload );
		$this->assert_stored_task_state( $row, (int) $attempt );
		$this->assert_stored_task_datetimes( $row );
		$expected_dedupe = GHCA_ACD_Archive_Digester::task_dedupe( array(
			'payload'          => $payload,
			'task_type'        => $row['task_type'],
			'trigger_event_id' => $row['trigger_event_id'],
		) );
		if ( ! $this->is_digest( $row['dedupe_digest'] ) || ! hash_equals( $expected_dedupe, $row['dedupe_digest'] ) ) {
			throw $this->integrity( 'stored_task_dedupe_mismatch', 'The retained task dedupe identity does not match its canonical payload.' );
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
		if ( ( $leased && ! $lease_fields_valid ) || ( ! $leased && ! $lease_fields_clear )
			|| ( $completed !== ( null !== $row['completed_at_gmt'] ) )
			|| ( 'pending' === $row['task_state'] && 0 !== $attempt )
			|| ( 'retry' === $row['task_state'] && ( $attempt < 1 || $attempt >= self::MAX_ATTEMPTS ) )
			|| ( in_array( $row['task_state'], array( 'leased', 'completed', 'dead' ), true ) && $attempt < 1 )
			|| ( in_array( $row['task_state'], array( 'pending', 'retry', 'dead' ), true ) && ( 'pending' === $row['task_state'] ? ! $error_clear : ! $error_valid ) )
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

	private function assert_failure( string $code, string $text ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $code ) || strlen( $text ) > 512 || 1 !== preg_match( '//u', $text ) ) {
			throw $this->internal( 'invalid_task_failure', 'Task failure information is invalid.' );
		}
	}

	private function utc_epoch( string $utc ): int {
		GHCA_ACD_Archive_Db_Format::utc_to_db( $utc );
		$epoch = strtotime( $utc );
		if ( false === $epoch ) {
			throw $this->internal( 'invalid_task_lease_window', 'Task lease time is invalid.' );
		}
		return $epoch;
	}

	private function begin(): void {
		if ( $this->in_transaction || $this->transaction_unusable ) {
			throw $this->internal( 'task_claim_update_failed', 'The durable task claim connection is not clean for a new transaction.' );
		}
		$result = $this->db->query( 'START TRANSACTION' );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			throw $this->internal( 'task_claim_update_failed', 'The durable task claim transaction could not start.' );
		}
		$this->in_transaction = true;
	}

	private function commit(): void {
		$result = $this->db->query( 'COMMIT' );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			$this->rollback();
			throw $this->internal( 'task_claim_update_failed', 'The durable task claim transaction could not commit.' );
		}
		$this->in_transaction = false;
	}

	private function rollback(): void {
		if ( $this->in_transaction ) {
			$result = $this->db->query( 'ROLLBACK' );
			$this->in_transaction = false;
			if ( false === $result || '' !== (string) $this->db->last_error ) {
				$this->transaction_unusable = true;
			}
		}
	}

	private function invalid_stored_row(): void {
		throw $this->integrity( 'invalid_stored_task_row', 'The retained task row violates the v1 persistence contract.' );
	}

	/** @param mixed $format */
	private function canonical_format_matches_task( string $task_type, $format ): bool {
		return GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE === $task_type
			? 'ghca-cjson-1' === $format
			: GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION === $format;
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
			throw $this->internal( 'invalid_task_id', 'Task identifiers must be 32 lowercase hexadecimal characters.' );
		}
	}

	private function assert_no_database_error( string $reason, string $message ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw $this->internal( $reason, $message );
		}
	}

	private function tasks_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_tasks';
	}

	private function internal( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, $reason, $message );
	}

	private function integrity( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}
}
