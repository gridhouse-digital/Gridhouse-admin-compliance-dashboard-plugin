<?php

/**
 * Rebuildable projection persistence (Technical Design Sections 9.10-9.14).
 *
 * Every mutation is a guarded compare-and-set: projector heads advance only
 * from their exact expected sequence, the case row advances only from its
 * exact expected projected sequence, and entity rows change only from their
 * exact expected last-changed sequence. Zero affected rows always fails
 * closed; nothing here updates authoritative event or stream identity data.
 */
final class GHCA_ACD_WPDB_Archive_Projection_Repository {
	const PROJECTION_SCHEMA_VERSION = 1;

	/** @var wpdb|object */
	private $db;

	/** @param wpdb|object $db */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return wpdb|object The connection this repository writes through. */
	public function database() {
		return $this->db;
	}

	/**
	 * First-stream initialization: the technical sequence-zero case row plus
	 * one sequence-zero head per synchronous projector. These are technical
	 * zero-state rows, not lifecycle facts (Technical Design Section 8.2).
	 *
	 * @param array<string,mixed> $stream_row
	 * @param array<int,string>   $projector_keys
	 */
	public function initialize_stream_projections( array $stream_row, array $projector_keys, string $now_gmt ): void {
		$now_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$case   = array(
			'stream_id'                    => $stream_row['stream_id'],
			'tenant_id'                    => $stream_row['tenant_id'],
			'site_id'                      => $stream_row['site_id'],
			'employee_user_id'             => $stream_row['employee_user_id'],
			'program_key'                  => $stream_row['program_key'],
			'cycle_key'                    => $stream_row['cycle_key'],
			'cycle_key_digest'             => $stream_row['cycle_key_digest'],
			'cycle_start_gmt'              => $stream_row['cycle_start_gmt'],
			'cycle_end_gmt'                => $stream_row['cycle_end_gmt'],
			'cycle_timezone'               => $stream_row['cycle_timezone'],
			'projected_sequence'           => '0',
			'projected_event_digest'       => null,
			'projection_schema_version'    => self::PROJECTION_SCHEMA_VERSION,
			'current_archive_id'           => null,
			'active_archive_id'            => null,
			'correction_target_archive_id' => null,
			'build_state'                  => null,
			'validity_state'               => null,
			'reset_state'                  => 'NONE',
			'source_drift_state'           => 'NONE',
			'source_drift_incident_id'     => null,
			'unprotected_reset_state'      => 'NONE',
			'unprotected_reset_incident_id' => null,
			'integrity_state'              => 'NONE',
			'integrity_incident_id'        => null,
			'edit_locked'                  => 0,
			'reset_eligible'               => 0,
			'edit_lock_reason'             => null,
			'reset_block_reason'           => null,
			'last_failure_code'            => null,
			'state_changed_at_gmt'         => $stream_row['created_at_gmt'],
			'updated_at_gmt'               => $now_db,
		);
		$this->insert_row( $this->case_state_table(), $case, array( 'projection_schema_version', 'edit_locked', 'reset_eligible' ), 'case_state_insert_failed' );
		foreach ( $projector_keys as $projector_key ) {
			$this->insert_row(
				$this->heads_table(),
				array(
					'projector_key'             => $projector_key,
					'stream_id'                 => $stream_row['stream_id'],
					'projection_schema_version' => self::PROJECTION_SCHEMA_VERSION,
					'projected_sequence'        => '0',
					'projected_event_digest'    => null,
					'updated_at_gmt'            => $now_db,
				),
				array( 'projection_schema_version' ),
				'projector_head_insert_failed'
			);
		}
	}

	/**
	 * Lock every projector head for the stream in fixed projector-key order.
	 *
	 * @param array<int,string> $expected_keys
	 * @return array<string,array<string,mixed>> Rows keyed by projector key.
	 */
	public function lock_projector_heads( string $stream_id, array $expected_keys ): array {
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->heads_table()} WHERE stream_id = %s ORDER BY projector_key ASC FOR UPDATE",
			$stream_id
		);
		try {
			// Driver-level failures (e.g. a lock wait timeout raised as a PHP
			// warning on some runtimes) map to the same stable reason as a
			// reported query error.
			$rows = $this->db->get_results( $sql, ARRAY_A );
		} catch ( Throwable $error ) {
			$this->db->last_error = '';
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'projector_head_lock_failed',
				'An archive projection query failed.'
			);
		}
		$this->assert_no_database_error( 'projector_head_lock_failed' );
		$heads = array();
		foreach ( (array) $rows as $row ) {
			$heads[ (string) $row['projector_key'] ] = $row;
		}
		$found = array_keys( $heads );
		$expected = $expected_keys;
		sort( $found, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $found !== $expected ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'projector_heads_missing',
				'The stream does not have exactly the expected projector head rows.'
			);
		}
		return $heads;
	}

	public function advance_projector_head( string $projector_key, string $stream_id, string $expected_sequence, string $new_sequence, string $new_digest, string $now_gmt ): void {
		GHCA_ACD_Archive_Db_Format::assert_canonical_sequence( $expected_sequence, 'Expected projector sequence' );
		GHCA_ACD_Archive_Db_Format::assert_canonical_sequence( $new_sequence, 'New projector sequence' );
		// CAST keeps the BIGINT UNSIGNED comparison exact (no double conversion).
		$sql    = $this->db->prepare(
			"UPDATE {$this->heads_table()} SET projected_sequence = %s, projected_event_digest = %s, updated_at_gmt = %s
			 WHERE projector_key = %s AND stream_id = %s AND projected_sequence = CAST(%s AS UNSIGNED)",
			$new_sequence,
			$new_digest,
			GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
			$projector_key,
			$stream_id,
			$expected_sequence
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'projector_head_update_failed' );
		if ( 1 !== (int) $result ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'projector_head_conflict',
				'The projector head no longer matches its expected contiguous sequence.'
			);
		}
	}

	/** @return array<string,mixed>|null */
	public function find_case_state_for_update( string $stream_id ) {
		return $this->find_for_update( $this->case_state_table(), 'stream_id', $stream_id, 'case_state_lookup_failed' );
	}

	/** @param array<string,mixed> $columns */
	public function update_case_state( string $stream_id, array $columns, string $expected_sequence ): void {
		$this->guarded_update( $this->case_state_table(), 'stream_id', $stream_id, $columns, 'projected_sequence', $expected_sequence, array( 'edit_locked', 'reset_eligible' ), 'case_state_conflict', true );
	}

	/** @return array<string,mixed>|null */
	public function find_revision_for_update( string $archive_id ) {
		return $this->find_for_update( $this->revision_state_table(), 'archive_id', $archive_id, 'revision_lookup_failed' );
	}

	/** @param array<string,mixed> $row */
	public function insert_revision( array $row ): void {
		$this->insert_row( $this->revision_state_table(), $row, array(), 'revision_insert_failed' );
	}

	/** @param array<string,mixed> $columns */
	public function update_revision( string $archive_id, array $columns, string $expected_last_changed_sequence ): void {
		$this->guarded_update( $this->revision_state_table(), 'archive_id', $archive_id, $columns, 'last_changed_sequence', $expected_last_changed_sequence, array(), 'revision_row_conflict', true );
	}

	/** @return array<string,mixed>|null */
	public function find_reset_for_update( string $reset_operation_id ) {
		return $this->find_for_update( $this->reset_state_table(), 'reset_operation_id', $reset_operation_id, 'reset_lookup_failed' );
	}

	/** @param array<string,mixed> $row */
	public function insert_reset( array $row ): void {
		$this->insert_row( $this->reset_state_table(), $row, array( 'scope_schema_version' ), 'reset_insert_failed' );
	}

	/** @param array<string,mixed> $columns */
	public function update_reset( string $reset_operation_id, array $columns, string $expected_last_changed_sequence ): void {
		$this->guarded_update( $this->reset_state_table(), 'reset_operation_id', $reset_operation_id, $columns, 'last_changed_sequence', $expected_last_changed_sequence, array(), 'reset_row_conflict', true );
	}

	/** @return array<string,mixed>|null */
	public function find_authorization_for_update( string $authorization_id ) {
		return $this->find_for_update( $this->authorizations_table(), 'authorization_id', $authorization_id, 'authorization_lookup_failed' );
	}

	/** @param array<string,mixed> $row */
	public function insert_authorization( array $row ): void {
		$this->insert_row( $this->authorizations_table(), $row, array(), 'authorization_insert_failed' );
	}

	/**
	 * Single-use enforcement transition: the authorization row changes state
	 * only from the exact expected current state.
	 *
	 * @param array<string,mixed> $columns
	 */
	public function transition_authorization( string $authorization_id, string $expected_state, array $columns ): void {
		$this->guarded_update( $this->authorizations_table(), 'authorization_id', $authorization_id, $columns, 'auth_state', $expected_state, array(), 'authorization_state_conflict' );
	}

	/** @param array<string,mixed> $row @param array<int,string> $int_columns */
	private function insert_row( string $table, array $row, array $int_columns, string $failure_reason ): void {
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $int_columns, true ) ? '%d' : '%s';
		}
		$result = $this->db->insert( $table, $row, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projection_row_duplicate',
					'A projection row with this identity already exists.'
				);
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$failure_reason,
				'A projection row could not be inserted.'
			);
		}
	}

	/** @return array<string,mixed>|null */
	private function find_for_update( string $table, string $id_column, string $id, string $failure_reason ) {
		$sql = $this->db->prepare(
			"SELECT * FROM {$table} WHERE {$id_column} = %s FOR UPDATE",
			$id
		);
		try {
			$row = $this->db->get_row( $sql, ARRAY_A );
		} catch ( Throwable $error ) {
			$this->db->last_error = '';
			$row = null;
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$failure_reason,
				'A projection row lookup failed.'
			);
		}
		if ( '' !== (string) $this->db->last_error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$failure_reason,
				'A projection row lookup failed.'
			);
		}
		return null === $row ? null : $row;
	}

	/** @param array<string,mixed> $columns @param array<int,string> $int_columns */
	private function guarded_update( string $table, string $id_column, string $id, array $columns, string $guard_column, string $guard_value, array $int_columns, string $conflict_reason, bool $numeric_guard = false ): void {
		if ( array() === $columns ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'empty_projection_update',
				'A projection update requires at least one column.'
			);
		}
		$assignments = array();
		$values      = array();
		foreach ( $columns as $column => $value ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $column ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
					'invalid_projection_column',
					'A projection column name is invalid.'
				);
			}
			if ( null === $value ) {
				$assignments[] = "{$column} = NULL";
				continue;
			}
			$assignments[] = "{$column} = " . ( in_array( $column, $int_columns, true ) ? '%d' : '%s' );
			$values[]      = $value;
		}
		$values[] = $id;
		$values[] = $guard_value;
		// CAST keeps BIGINT UNSIGNED guards exact (no double conversion).
		$guard_placeholder = $numeric_guard ? 'CAST(%s AS UNSIGNED)' : '%s';
		$sql      = $this->db->prepare(
			"UPDATE {$table} SET " . implode( ', ', $assignments ) . " WHERE {$id_column} = %s AND {$guard_column} = {$guard_placeholder}",
			$values
		);
		$result = $this->db->query( $sql );
		$this->assert_no_database_error( 'projection_update_failed' );
		if ( 1 !== (int) $result ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				$conflict_reason,
				'A projection row no longer matches its expected guard value.'
			);
		}
	}

	private function assert_no_database_error( string $reason_code ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				$reason_code,
				'An archive projection query failed.'
			);
		}
	}

	private function case_state_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_case_state';
	}

	private function revision_state_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_revision_state';
	}

	private function reset_state_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_reset_state';
	}

	private function authorizations_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_reset_authorizations';
	}

	private function heads_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_projection_heads';
	}
}
