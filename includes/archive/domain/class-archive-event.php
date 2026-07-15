<?php

final class GHCA_ACD_Archive_Event {
	/** @var string */ private $type;
	/** @var int */ private $schema_version;
	/** @var array<string,mixed> */ private $payload;
	/** @var array<string,mixed> */ private $metadata;
	/** @var array<string,mixed>|null */ private $recording_context = null;
	/** @var string|null */ private $event_digest = null;

	/** @param array<string,mixed> $payload @param array<string,mixed> $metadata */
	public function __construct( string $type, int $schema_version, array $payload, array $metadata = array() ) {
		GHCA_ACD_Archive_Event_Catalog::validate_payload( $type, $schema_version, $payload );
		self::validate_metadata( $metadata );
		$this->type           = $type;
		$this->schema_version = $schema_version;
		$this->payload        = GHCA_ACD_Archive_Canonical_JSON::detach( $payload );
		$this->metadata       = GHCA_ACD_Archive_Canonical_JSON::detach( $metadata );
	}

	public function type(): string { return $this->type; }
	public function schema_version(): int { return $this->schema_version; }
	public function is_recorded(): bool { return null !== $this->recording_context; }
	/** @return array<string,mixed> */ public function payload(): array { return GHCA_ACD_Archive_Canonical_JSON::detach( $this->payload ); }
	/** @return array<string,mixed> */ public function metadata(): array { return GHCA_ACD_Archive_Canonical_JSON::detach( $this->metadata ); }

	/** @param array<string,mixed> $context */
	public function with_recording_context( array $context ): self {
		if ( $this->is_recorded() ) {
			throw new LogicException( 'Recorded events cannot be assigned a second recording context.' );
		}
		self::validate_recording_context( $context, $this->type, $this->payload );
		$recorded = clone $this;
		$recorded->recording_context = GHCA_ACD_Archive_Canonical_JSON::detach( $context );
		$recorded->event_digest = GHCA_ACD_Archive_Digester::event_hash( $recorded->hash_document() );
		return $recorded;
	}

	/** @param array<string,mixed> $document */
	public static function from_recorded( array $document ): self {
		$expected = GHCA_ACD_Archive_Digester::event_hash_fields();
		$expected[] = 'event_digest';
		self::assert_exact_fields( $document, $expected, 'Recorded event document' );
		if ( ! is_string( $document['event_type'] ) || ! is_int( $document['event_schema_version'] ) || ! is_array( $document['payload'] ) || ! is_array( $document['metadata'] ) ) {
			throw new InvalidArgumentException( 'Recorded event semantics are invalid.' );
		}
		$event = new self( $document['event_type'], $document['event_schema_version'], $document['payload'], $document['metadata'] );
		$context = $document;
		unset( $context['event_type'], $context['event_schema_version'], $context['payload'], $context['metadata'], $context['event_digest'] );
		$recorded = $event->with_recording_context( $context );
		if ( ! is_string( $document['event_digest'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $document['event_digest'] ) || ! hash_equals( $document['event_digest'], $recorded->event_digest() ) ) {
			throw new InvalidArgumentException( 'Recorded event digest verification failed.' );
		}
		return $recorded;
	}

	public function event_digest(): string {
		if ( null === $this->event_digest ) {
			throw new LogicException( 'Uncommitted events do not have an event digest.' );
		}
		return $this->event_digest;
	}

	public function event_id(): string {
		$this->assert_recorded();
		return $this->recording_context['event_id'];
	}

	public function stream_id(): string {
		$this->assert_recorded();
		return $this->recording_context['stream_id'];
	}

	public function case_key_digest(): string {
		$this->assert_recorded();
		return $this->recording_context['case_key_digest'];
	}

	public function stream_sequence(): string {
		$this->assert_recorded();
		return $this->recording_context['stream_sequence'];
	}

	public function previous_event_digest(): ?string {
		$this->assert_recorded();
		return $this->recording_context['previous_event_digest'];
	}

	public function verify_digest(): bool {
		return $this->is_recorded() && GHCA_ACD_Archive_Digester::verify_event_hash( $this->hash_document(), $this->event_digest );
	}

	/** @return array<string,mixed> */
	public function recorded_document(): array {
		$this->assert_recorded();
		$document = $this->hash_document();
		$document['event_digest'] = $this->event_digest;
		return GHCA_ACD_Archive_Canonical_JSON::detach( $document );
	}

	/** @return array<string,mixed> */
	public function canonical(): array {
		return array(
			'event_schema_version' => $this->schema_version,
			'event_type'           => $this->type,
			'metadata'             => GHCA_ACD_Archive_Canonical_JSON::detach( $this->metadata ),
			'payload'              => GHCA_ACD_Archive_Canonical_JSON::detach( $this->payload ),
		);
	}

	/** @return array<string,mixed> */
	private function hash_document(): array {
		$this->assert_recorded();
		$document = $this->recording_context;
		$document['event_type'] = $this->type;
		$document['event_schema_version'] = $this->schema_version;
		$document['payload'] = GHCA_ACD_Archive_Canonical_JSON::detach( $this->payload );
		$document['metadata'] = GHCA_ACD_Archive_Canonical_JSON::detach( $this->metadata );
		return $document;
	}

	private function assert_recorded(): void {
		if ( ! $this->is_recorded() ) {
			throw new LogicException( 'Event has not been assigned authoritative recording context.' );
		}
	}

	/** @param array<string,mixed> $metadata */
	private static function validate_metadata( array $metadata ): void {
		self::assert_exact_fields( $metadata, array( 'decision_index', 'decision_size' ), 'Event metadata' );
		if ( ! is_int( $metadata['decision_index'] ) || ! is_int( $metadata['decision_size'] ) || $metadata['decision_index'] < 0 || $metadata['decision_size'] < 1 || $metadata['decision_index'] >= $metadata['decision_size'] ) {
			throw new InvalidArgumentException( 'Event decision metadata is invalid.' );
		}
	}

	/** @param array<string,mixed> $context @param array<string,mixed> $payload */
	private static function validate_recording_context( array $context, string $event_type, array $payload ): void {
		$expected = GHCA_ACD_Archive_Digester::event_hash_fields();
		foreach ( array( 'event_type', 'event_schema_version', 'payload', 'metadata' ) as $owned ) {
			$key = array_search( $owned, $expected, true );
			if ( false !== $key ) { unset( $expected[ $key ] ); }
		}
		self::assert_exact_fields( $context, array_values( $expected ), 'Event recording context' );
		if ( 1 !== $context['canonical_format_version'] || 1 !== $context['case_key_format_version'] ) {
			throw new InvalidArgumentException( 'Unsupported event or case-key format version.' );
		}
		self::assert_id( $context['event_id'], false, 'Event ID' );
		self::assert_id( $context['stream_id'], false, 'Stream ID' );
		self::assert_digest( $context['case_key_digest'], false, 'Case-key digest' );
		self::assert_positive_decimal( $context['stream_sequence'], 'Stream sequence' );
		self::assert_id( $context['archive_id'], true, 'Archive ID' );
		self::assert_id( $context['build_attempt_id'], true, 'Build-attempt ID' );
		self::assert_id( $context['reset_operation_id'], true, 'Reset-operation ID' );
		new GHCA_ACD_Archive_Actor(
			$context['actor_kind'], $context['actor_user_id'], $context['initiating_user_id'],
			$context['source_channel'], $context['authority_code'], $context['authority_context']
		);
		self::assert_utc( $context['occurred_at_gmt'], false, 'Occurred time' );
		self::assert_utc( $context['effective_at_gmt'], true, 'Effective time' );
		self::assert_id( $context['correlation_id'], false, 'Correlation ID' );
		self::assert_id( $context['causation_event_id'], true, 'Causation event ID' );
		self::assert_id( $context['command_id'], true, 'Command ID' );
		self::assert_upstream_id( $context['upstream_operation_id'] );
		self::assert_digest( $context['idempotency_scope_digest'], true, 'Idempotency scope digest' );
		self::assert_digest( $context['idempotency_key_digest'], true, 'Idempotency key digest' );
		self::assert_digest( $context['command_digest'], true, 'Command digest' );
		self::assert_machine_or_null( $context['reason_code'], 64, 'Reason code' );
		self::assert_text_or_null( $context['reason_text'], 4096, 'Reason text' );
		self::assert_digest( $context['previous_event_digest'], true, 'Previous event digest' );
		self::assert_utc( $context['recorded_at_gmt'], false, 'Recorded time' );
		if ( '1' === $context['stream_sequence'] && null !== $context['previous_event_digest'] ) {
			throw new InvalidArgumentException( 'Stream sequence one requires a null predecessor digest.' );
		}
		if ( '1' !== $context['stream_sequence'] && null === $context['previous_event_digest'] ) {
			throw new InvalidArgumentException( 'Later stream events require a predecessor digest.' );
		}
		if ( in_array( $event_type, GHCA_ACD_Archive_Event_Types::command_originated(), true ) ) {
			$command_values = array( $context['command_id'], $context['idempotency_scope_digest'], $context['idempotency_key_digest'], $context['command_digest'] );
			foreach ( $command_values as $value ) {
				if ( null === $value ) {
					throw new InvalidArgumentException( 'Command-originated events require complete command identity: command ID, idempotency scope/key digests, and command digest.' );
				}
			}
		}
		self::assert_payload_envelope_bindings( $event_type, $payload, $context );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context */
	private static function assert_payload_envelope_bindings( string $event_type, array $payload, array $context ): void {
		$archive_id = null;
		foreach ( array( 'archive_id', 'target_archive_id', 'bound_archive_id' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) { $archive_id = $payload[ $field ]; break; }
		}
		$build_attempt_id = array_key_exists( 'build_attempt_id', $payload ) ? $payload['build_attempt_id'] : null;
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $event_type ) { $build_attempt_id = $payload['new_build_attempt_id']; }
		$reset_operation_id = array_key_exists( 'reset_operation_id', $payload ) ? $payload['reset_operation_id'] : null;
		$upstream_operation_id = array_key_exists( 'upstream_operation_id', $payload ) ? $payload['upstream_operation_id'] : null;
		if ( $context['archive_id'] !== $archive_id || $context['build_attempt_id'] !== $build_attempt_id || $context['reset_operation_id'] !== $reset_operation_id || $context['upstream_operation_id'] !== $upstream_operation_id ) {
			throw new InvalidArgumentException( 'Event envelope identifiers contradict the event payload.' );
		}
		if ( in_array( $event_type, array( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED ), true ) ) {
			$key = $payload['case_key'];
			$digest = GHCA_ACD_Archive_Digester::case_key( $key['tenant_id'], $key['site_id_decimal'], $key['employee_user_id_decimal'], $key['program_key'], $key['cycle_key'] );
			if ( $context['case_key_digest'] !== $digest ) {
				throw new InvalidArgumentException( 'Event case-key digest contradicts the payload identity.' );
			}
		}
		$subject_scope_digest = GHCA_ACD_Archive_Event_Catalog::effective_subject_scope_digest( $payload );
		if ( null !== $subject_scope_digest
			&& $context['authority_context']['subject_scope_digest'] !== $subject_scope_digest ) {
			throw new InvalidArgumentException( 'Actor authority subject scope contradicts the requested subject scope.' );
		}
	}

	/** @param mixed $value */
	private static function assert_id( $value, bool $nullable, string $label ): void {
		if ( $nullable && null === $value ) { return; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
	}

	/** @param mixed $value */
	private static function assert_digest( $value, bool $nullable, string $label ): void {
		if ( $nullable && null === $value ) { return; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
	}

	/** @param mixed $value */
	private static function assert_utc( $value, bool $nullable, string $label ): void {
		if ( $nullable && null === $value ) { return; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) { throw new InvalidArgumentException( $label . ' is not a real UTC timestamp.' ); }
	}

	/** @param mixed $value */
	private static function assert_upstream_id( $value ): void {
		if ( null === $value ) { return; }
		if ( ! is_string( $value ) || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/', $value ) ) { throw new InvalidArgumentException( 'Upstream operation ID is invalid.' ); }
	}

	/** @param mixed $value */
	private static function assert_machine_or_null( $value, int $max, string $label ): void {
		if ( null === $value ) { return; }
		if ( ! is_string( $value ) || strlen( $value ) > $max || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
	}

	/** @param mixed $value */
	private static function assert_text_or_null( $value, int $max, string $label ): void {
		if ( null === $value ) { return; }
		if ( ! is_string( $value ) || strlen( $value ) > $max || 1 !== preg_match( '//u', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
	}

	/** @param mixed $value */
	private static function assert_positive_decimal( $value, string $label ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) { throw new InvalidArgumentException( $label . ' is invalid.' ); }
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) { throw new InvalidArgumentException( $label . ' exceeds the BIGINT UNSIGNED range.' ); }
	}

	/** @param array<string,mixed> $actual @param array<int,string> $expected */
	private static function assert_exact_fields( array $actual, array $expected, string $label ): void {
		$keys = array_keys( $actual );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) { throw new InvalidArgumentException( $label . ' fields do not match the v1 contract.' ); }
	}
}
