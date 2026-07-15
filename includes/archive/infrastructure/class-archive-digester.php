<?php

final class GHCA_ACD_Archive_Digester {
	const CASE_KEY_PREFIX          = 'ghca-case-key-v1';
	const IDEMPOTENCY_PREFIX       = 'ghca-idempotency-v1';
	const COMMAND_PREFIX           = 'ghca-command-v1';
	const CLIENT_INTENT_PREFIX     = 'ghca-client-intent-v1';
	const SOURCE_FINGERPRINT_PREFIX = 'ghca-source-fingerprint-v1';
	const SNAPSHOT_PREFIX          = 'ghca-snapshot-v1';
	const ITEM_PREFIX              = 'ghca-item-v1';
	const EVENT_PREFIX             = 'ghca-event-hash-v1';

	/** @return array<int,string> */
	public static function event_hash_fields(): array {
		return array(
			'canonical_format_version', 'event_id', 'stream_id', 'case_key_digest',
			'case_key_format_version', 'stream_sequence', 'event_type', 'event_schema_version',
			'archive_id', 'build_attempt_id', 'reset_operation_id', 'actor_kind',
			'actor_user_id', 'initiating_user_id', 'source_channel', 'authority_code',
			'authority_context', 'occurred_at_gmt', 'effective_at_gmt', 'correlation_id',
			'causation_event_id', 'command_id', 'upstream_operation_id',
			'idempotency_scope_digest', 'idempotency_key_digest', 'command_digest',
			'reason_code', 'reason_text', 'previous_event_digest', 'payload', 'metadata',
			'recorded_at_gmt',
		);
	}

	public static function case_key( string $tenant_id, string $site_id, string $employee_user_id, string $program_key, string $cycle_key ): string {
		return self::digest_document( self::CASE_KEY_PREFIX, array(
			'tenant_id'                => $tenant_id,
			'site_id_decimal'          => $site_id,
			'employee_user_id_decimal' => $employee_user_id,
			'program_key'              => $program_key,
			'cycle_key'                => $cycle_key,
		) );
	}

	public static function idempotency( string $scope_digest, string $key_digest ): string {
		self::assert_digest( $scope_digest, 'Idempotency scope digest' );
		self::assert_digest( $key_digest, 'Idempotency key digest' );
		return self::digest_document( self::IDEMPOTENCY_PREFIX, array(
			'idempotency_scope_digest' => $scope_digest,
			'idempotency_key_digest'   => $key_digest,
		) );
	}

	/** @param array<string,mixed> $document */
	public static function command( array $document ): string {
		return self::digest_document( self::COMMAND_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function client_intent( array $document ): string {
		return self::digest_document( self::CLIENT_INTENT_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function source_fingerprint( array $document ): string {
		return self::digest_document( self::SOURCE_FINGERPRINT_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function snapshot( array $document ): string {
		return self::digest_document( self::SNAPSHOT_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function item( array $document ): string {
		return self::digest_document( self::ITEM_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function event_hash( array $document ): string {
		self::assert_exact_fields( $document, self::event_hash_fields(), 'Event hash document' );
		return self::digest_document( self::EVENT_PREFIX, $document );
	}

	/** @param array<string,mixed> $document */
	public static function verify_event_hash( array $document, string $expected ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return false;
		}
		try {
			return hash_equals( $expected, self::event_hash( $document ) );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @param mixed $document */
	public static function digest_document( string $prefix, $document ): string {
		if ( 1 !== preg_match( '/^ghca-[a-z0-9-]+-v1$/', $prefix ) ) {
			throw new InvalidArgumentException( 'Digest domain prefix is invalid.' );
		}
		return hash( 'sha256', $prefix . "\n" . GHCA_ACD_Archive_Canonical_JSON::encode( $document ) );
	}

	private static function assert_digest( string $digest, string $label ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
			throw new InvalidArgumentException( $label . ' must be 64 lowercase hexadecimal characters.' );
		}
	}

	/** @param array<string,mixed> $document @param array<int,string> $fields */
	private static function assert_exact_fields( array $document, array $fields, string $label ): void {
		$actual   = array_keys( $document );
		$expected = $fields;
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( $label . ' fields do not match ghca-event-hash-v1.' );
		}
	}
}
