<?php

/** Closed task contracts installed by the dark-mode P3B1 ledger slice. */
final class GHCA_ACD_Archive_Task_Catalog {
	const LEDGER_TASK_TYPE = 'materialize_ledger';
	const LEDGER_PAYLOAD_MAX_BYTES = 512;
	const LEDGER_PAYLOAD_FIELDS = array(
		'archive_id',
		'build_attempt_id',
		'canonical_format_version',
		'ledger_artifact_id',
		'snapshot_id',
		'stream_id',
		'task_schema_version',
		'task_type',
		'trigger_event_id',
	);

	/** @return array<int,string> */
	public static function installed_types(): array {
		return array( self::LEDGER_TASK_TYPE );
	}

	/**
	 * Validate, deduplicate, and sort the types installed in one coordinator.
	 *
	 * @param array<int,mixed> $types
	 * @return array<int,string>
	 */
	public static function normalize_installed_types( array $types ): array {
		if ( array() === $types || array_keys( $types ) !== range( 0, count( $types ) - 1 ) ) {
			throw self::invalid( 'task_type_unsupported', 'The installed task-type allowlist is invalid.' );
		}
		$normalized = array();
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) || ! in_array( $type, GHCA_ACD_WPDB_Archive_Task_Store::TASK_TYPES, true ) ) {
				throw self::invalid( 'task_type_unsupported', 'The installed task-type allowlist is invalid.' );
			}
			$normalized[ $type ] = true;
		}
		$normalized = array_keys( $normalized );
		sort( $normalized, SORT_STRING );
		return $normalized;
	}

	/**
	 * Apply the exact task-specific v1 contract without changing deferred types.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function validate_claimed_payload( array $row, array $payload ): array {
		if ( self::LEDGER_TASK_TYPE !== $row['task_type'] ) {
			return $payload;
		}
		return self::validate_ledger_payload( $row, $payload );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function validate_ledger_payload( array $row, array $payload ): array {
		try {
			$canonical = GHCA_ACD_Archive_Canonical_JSON::encode( $payload );
		} catch ( Throwable $error ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		if ( array_keys( $payload ) !== self::LEDGER_PAYLOAD_FIELDS
			|| strlen( $canonical ) > self::LEDGER_PAYLOAD_MAX_BYTES
			|| 'ghca-cjson-1' !== $payload['canonical_format_version']
			|| 1 !== $payload['task_schema_version']
			|| self::LEDGER_TASK_TYPE !== $payload['task_type'] ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		foreach ( array( 'archive_id', 'build_attempt_id', 'ledger_artifact_id', 'snapshot_id', 'stream_id', 'trigger_event_id' ) as $field ) {
			if ( ! self::is_id( $payload[ $field ] ) ) {
				throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
			}
		}
		$bindings = array(
			'archive_id'          => 'archive_id',
			'build_attempt_id'    => 'build_attempt_id',
			'stream_id'           => 'stream_id',
			'task_schema_version' => 'task_schema_version',
			'task_type'           => 'task_type',
			'trigger_event_id'    => 'trigger_event_id',
		);
		foreach ( $bindings as $payload_field => $row_field ) {
			if ( ! array_key_exists( $row_field, $row ) || (string) $payload[ $payload_field ] !== (string) $row[ $row_field ] ) {
				throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
			}
		}
		if ( ! array_key_exists( 'reset_operation_id', $row ) || null !== $row['reset_operation_id'] ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		return $payload;
	}

	/** @param mixed $value */
	private static function is_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	private static function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
			$reason,
			$message
		);
	}
}
