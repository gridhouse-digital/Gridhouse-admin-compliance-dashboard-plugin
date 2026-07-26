<?php

/** Closed task contracts installed by the dark-mode P3B1 ledger slice. */
final class GHCA_ACD_Archive_Task_Catalog {
	const CAPTURE_TASK_TYPE = 'capture_evidence';
	const CAPTURE_PAYLOAD_MAX_BYTES = 384;
	const CAPTURE_PAYLOAD_FIELDS = array(
		'archive_id',
		'canonical_format_version',
		'stream_id',
		'task_schema_version',
		'task_type',
		'trigger_event_id',
	);
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
		return array( self::CAPTURE_TASK_TYPE, self::LEDGER_TASK_TYPE );
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
		if ( self::CAPTURE_TASK_TYPE === $row['task_type'] ) {
			if ( self::is_deferred_capture_retry_payload( $row, $payload )
				|| self::is_legacy_capture_payload( $row, $payload ) ) {
				return $payload;
			}
			return self::validate_capture_payload( $row, $payload );
		}
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
	public static function validate_capture_payload( array $row, array $payload ): array {
		try {
			$canonical = GHCA_ACD_Archive_Canonical_JSON::encode( $payload );
		} catch ( Throwable $error ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		if ( array_keys( $payload ) !== self::CAPTURE_PAYLOAD_FIELDS
			|| strlen( $canonical ) > self::CAPTURE_PAYLOAD_MAX_BYTES
			|| 1 !== $payload['canonical_format_version']
			|| 1 !== $payload['task_schema_version']
			|| self::CAPTURE_TASK_TYPE !== $payload['task_type'] ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		foreach ( array( 'archive_id', 'stream_id', 'trigger_event_id' ) as $field ) {
			if ( ! self::is_id( $payload[ $field ] ) ) {
				throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
			}
		}
		foreach ( array(
			'archive_id' => 'archive_id',
			'stream_id' => 'stream_id',
			'task_schema_version' => 'task_schema_version',
			'task_type' => 'task_type',
			'trigger_event_id' => 'trigger_event_id',
		) as $payload_field => $row_field ) {
			if ( ! array_key_exists( $row_field, $row ) || (string) $payload[ $payload_field ] !== (string) $row[ $row_field ] ) {
				throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
			}
		}
		if ( ! array_key_exists( 'build_attempt_id', $row ) || null !== $row['build_attempt_id']
			|| ! array_key_exists( 'reset_operation_id', $row ) || null !== $row['reset_operation_id'] ) {
			throw self::invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		return $payload;
	}

	/**
	 * Preserve the already-approved RetryArchive task producer while D16
	 * remains deferred. The installed capture coordinator rejects this exact
	 * retained shape before source access or an authoritative command.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $payload
	 */
	private static function is_deferred_capture_retry_payload( array $row, array $payload ): bool {
		$fields = array(
			'archive_id',
			'build_attempt_id',
			'canonical_format_version',
			'snapshot_id',
			'stream_id',
			'task_schema_version',
			'task_type',
			'trigger_event_id',
		);
		if ( array_keys( $payload ) !== $fields || 1 !== $payload['canonical_format_version']
			|| 1 !== $payload['task_schema_version'] || self::CAPTURE_TASK_TYPE !== $payload['task_type'] ) {
			return false;
		}
		foreach ( array( 'archive_id', 'build_attempt_id', 'stream_id', 'trigger_event_id' ) as $field ) {
			if ( ! self::is_id( $payload[ $field ] ) ) { return false; }
		}
		if ( null !== $payload['snapshot_id'] && ! self::is_id( $payload['snapshot_id'] ) ) { return false; }
		foreach ( array(
			'archive_id', 'build_attempt_id', 'stream_id', 'task_schema_version', 'task_type', 'trigger_event_id',
		) as $field ) {
			if ( ! array_key_exists( $field, $row ) || (string) $row[ $field ] !== (string) $payload[ $field ] ) {
				return false;
			}
		}
		return array_key_exists( 'reset_operation_id', $row ) && null === $row['reset_operation_id'];
	}

	/**
	 * Preserve the P3A generic dark-worker fixture contract. The installed
	 * evidence coordinator still applies validate_capture_payload() before
	 * source access or an authoritative command.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $payload
	 */
	private static function is_legacy_capture_payload( array $row, array $payload ): bool {
		if ( array_keys( $payload ) !== array(
			'canonical_format_version',
			'stream_id',
			'task_schema_version',
			'task_type',
			'trigger_event_id',
		) || 1 !== $payload['canonical_format_version'] || 1 !== $payload['task_schema_version']
			|| self::CAPTURE_TASK_TYPE !== $payload['task_type']
			|| ! self::is_id( $payload['stream_id'] ) || ! self::is_id( $payload['trigger_event_id'] ) ) {
			return false;
		}
		foreach ( array( 'stream_id', 'task_schema_version', 'task_type', 'trigger_event_id' ) as $field ) {
			if ( ! array_key_exists( $field, $row ) || (string) $row[ $field ] !== (string) $payload[ $field ] ) {
				return false;
			}
		}
		return array_key_exists( 'build_attempt_id', $row ) && null === $row['build_attempt_id']
			&& array_key_exists( 'reset_operation_id', $row ) && null === $row['reset_operation_id'];
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
