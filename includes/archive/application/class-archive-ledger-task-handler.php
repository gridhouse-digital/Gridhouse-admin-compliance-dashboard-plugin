<?php

/** Snapshot-only materialize_ledger v1 handler with private immutable storage. */
final class GHCA_ACD_Archive_Ledger_Task_Handler {
	/** @var GHCA_ACD_Archive_Event_Store */
	private $events;
	/** @var GHCA_ACD_WPDB_Archive_Snapshot_Store */
	private $snapshots;
	/** @var GHCA_ACD_WPDB_Archive_Artifact_Repository */
	private $artifacts;
	/** @var GHCA_ACD_Archive_Artifact_Store */
	private $store;
	/** @var GHCA_ACD_Archive_Ledger_Materializer */
	private $materializer;

	public function __construct(
		GHCA_ACD_Archive_Event_Store $events,
		GHCA_ACD_WPDB_Archive_Snapshot_Store $snapshots,
		GHCA_ACD_WPDB_Archive_Artifact_Repository $artifacts,
		GHCA_ACD_Archive_Artifact_Store $store,
		GHCA_ACD_Archive_Ledger_Materializer $materializer
	) {
		$this->events       = $events;
		$this->snapshots    = $snapshots;
		$this->artifacts    = $artifacts;
		$this->store        = $store;
		$this->materializer = $materializer;
	}

	/**
	 * @param array<string,mixed> $task
	 * @return array<string,mixed>
	 */
	public function __invoke( array $task, callable $heartbeat ): array {
		$context  = $this->load_authoritative( $task );
		$expected = $this->materializer->materialize( $task, $context['snapshot'] );
		$case     = $context['snapshot']['snapshot_document']['case'];
		$identity = array(
			'tenant_id'  => $case['tenant_id'],
			'stream_id'  => $task['payload']['stream_id'],
			'archive_id' => $task['payload']['archive_id'],
			'artifact_id' => $task['payload']['ledger_artifact_id'],
		);
		$committed_key = $this->store->committed_key( $identity, 'ledger' );
		if ( $committed_key !== $expected['artifact_descriptor']['storage_key'] ) {
			throw $this->invalid( 'archive_ledger_invalid', 'The archive ledger is invalid.' );
		}

		$heartbeat();
		$staging_key = $this->store->create_staging( $identity );
		$source = fopen( 'php://temp/maxmemory:' . GHCA_ACD_Archive_Ledger_Materializer::MAX_LEDGER_BYTES, 'w+b' );
		if ( false === $source ) {
			throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'artifact_write_failed', 'The ledger input stream could not be created.' );
		}
		try {
			if ( ! $this->write_all( $source, $expected['ledger_bytes'] ) || 0 !== fseek( $source, 0, SEEK_SET ) ) {
				throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'artifact_write_failed', 'The ledger input stream could not be prepared.' );
			}
			$written = $this->store->write_staging( $staging_key, $source, 'ledger' );
		} finally {
			fclose( $source );
		}
		if ( ! is_array( $written )
			|| (int) $written['byte_count'] !== $expected['artifact_descriptor']['byte_count']
			|| (string) $written['content_digest'] !== $expected['artifact_descriptor']['content_digest'] ) {
			throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'artifact_digest_mismatch', 'The staged ledger digest is invalid.' );
		}
		$heartbeat();
		$committed = $this->store->commit(
			$staging_key,
			$committed_key,
			'ledger',
			$expected['artifact_descriptor']['byte_count'],
			$expected['artifact_descriptor']['content_digest']
		);
		if ( ! is_array( $committed )
			|| (string) $committed['committed_key'] !== $committed_key
			|| (int) $committed['byte_count'] !== $expected['artifact_descriptor']['byte_count']
			|| (string) $committed['content_digest'] !== $expected['artifact_descriptor']['content_digest'] ) {
			throw new GHCA_ACD_Archive_Artifact_Store_Exception( 'artifact_immutable_mismatch', 'The committed ledger identity is invalid.' );
		}
		$heartbeat();
		$this->assert_committed_object( $expected['artifact_descriptor'] );
		return array(
			'artifact_descriptor' => $expected['artifact_descriptor'],
			'ledger_items'        => $expected['ledger_items'],
		);
	}

	/**
	 * Validate every handler-controlled field before the Build Coordinator/UoW.
	 *
	 * @param array<string,mixed> $task
	 * @param mixed $prepared
	 * @return array<string,mixed>
	 */
	public function validate_prepared_result( array $task, $prepared ): array {
		if ( ! is_array( $prepared )
			|| array_keys( $prepared ) !== array( 'artifact_descriptor', 'ledger_items' )
			|| ! is_array( $prepared['artifact_descriptor'] )
			|| ! is_array( $prepared['ledger_items'] ) ) {
			throw new UnexpectedValueException( 'The prepared ledger result is invalid.' );
		}
		$context  = $this->load_authoritative( $task );
		$expected = $this->materializer->materialize( $task, $context['snapshot'] );
		if ( ! $this->prepared_matches_expected( $prepared, $expected ) ) {
			throw new UnexpectedValueException( 'The prepared ledger result is invalid.' );
		}
		$this->assert_committed_object( $expected['artifact_descriptor'] );
		return $prepared;
	}

	/**
	 * Recover a matching authoritative materialization without invoking storage writes.
	 *
	 * @param array<string,mixed> $task
	 * @return array<string,mixed>|null
	 */
	public function recover_authoritative_prepared( array $task ) {
		$context  = $this->load_authoritative( $task );
		$expected = $this->materializer->materialize( $task, $context['snapshot'] );
		$event    = null;
		foreach ( $context['events'] as $candidate ) {
			if ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED !== $candidate->type() ) {
				continue;
			}
			$payload = $candidate->payload();
			if ( $payload['archive_id'] === $task['payload']['archive_id']
				&& $payload['build_attempt_id'] === $task['payload']['build_attempt_id'] ) {
				if ( null !== $event ) {
					throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
				}
				$event = $candidate;
			}
		}
		if ( null === $event ) {
			return null;
		}
		$expected_payload = array(
			'archive_id'             => $task['payload']['archive_id'],
			'build_attempt_id'       => $task['payload']['build_attempt_id'],
			'content_digest'         => $expected['artifact_descriptor']['content_digest'],
			'item_count'             => count( $expected['ledger_items'] ),
			'ledger_artifact_id'     => $task['payload']['ledger_artifact_id'],
			'manifest_digest'        => $expected['ledger_document']['manifest_digest'],
			'payload_schema_version' => 1,
			'snapshot_digest'        => (string) $context['snapshot']['snapshot_digest'],
			'snapshot_id'            => $task['payload']['snapshot_id'],
		);
		if ( $event->stream_id() !== $task['payload']['stream_id'] || $event->payload() !== $expected_payload ) {
			throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
		}

		try {
			$stored = $this->artifacts->find_descriptor( $task['payload']['ledger_artifact_id'], array(
				'archive_id'       => $task['payload']['archive_id'],
				'artifact_kind'    => 'ledger',
				'build_attempt_id' => $task['payload']['build_attempt_id'],
				'content_digest'   => $expected['artifact_descriptor']['content_digest'],
				'snapshot_digest'  => (string) $context['snapshot']['snapshot_digest'],
				'snapshot_id'      => $task['payload']['snapshot_id'],
				'stream_id'        => $task['payload']['stream_id'],
			) );
			if ( null === $stored ) {
				throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
			}
			$stored_items = $this->artifacts->load_ledger_items( $task['payload']['ledger_artifact_id'] );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category() ) {
				throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
			}
			throw $error;
		}
		$items = array();
		foreach ( $stored_items as $row ) {
			$items[] = $row['item_document'];
		}
		$prepared = array(
			'artifact_descriptor' => $this->descriptor_from_row( $stored ),
			'ledger_items'        => $items,
		);
		if ( ! $this->prepared_matches_expected( $prepared, $expected ) ) {
			throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
		}
		try {
			$this->assert_committed_object( $expected['artifact_descriptor'] );
		} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
			if ( in_array( $error->reason_code(), array( 'artifact_size_mismatch', 'artifact_digest_mismatch', 'artifact_media_invalid', 'artifact_symlink_rejected', 'artifact_immutable_mismatch' ), true ) ) {
				throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
			}
			throw $error;
		}
		return $prepared;
	}

	/**
	 * @param array<string,mixed> $task
	 * @return array<string,mixed>
	 */
	public function load_authoritative( array $task ): array {
		$payload = isset( $task['payload'] ) && is_array( $task['payload'] ) ? $task['payload'] : array();
		$payload = GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $task, $payload );
		$events  = $this->events->load_events( $payload['stream_id'] );
		$trigger = null;
		$attempt = null;
		foreach ( $events as $event ) {
			$event_payload = $event->payload();
			if ( in_array( $event->type(), array( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED, GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED ), true )
				&& isset( $event_payload['archive_id'] ) && $event_payload['archive_id'] === $payload['archive_id'] ) {
				$attempt = GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $event->type()
					? $event_payload['new_build_attempt_id']
					: $event_payload['build_attempt_id'];
			}
			if ( $event->event_id() === $payload['trigger_event_id'] ) {
				$trigger = $event;
				break;
			}
		}
		if ( null === $trigger || GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED !== $trigger->type() ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$trigger_payload = $trigger->payload();
		if ( $attempt !== $payload['build_attempt_id']
			|| $trigger->stream_id() !== $payload['stream_id']
			|| $trigger_payload['archive_id'] !== $payload['archive_id']
			|| $trigger_payload['snapshot_id'] !== $payload['snapshot_id'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$snapshot = $this->snapshots->find( $payload['snapshot_id'] );
		if ( null === $snapshot ) {
			throw $this->invalid( 'archive_snapshot_invalid', 'The archive snapshot is invalid.' );
		}
		if ( (string) $snapshot['source_event_id'] !== $payload['trigger_event_id']
			|| (string) $snapshot['stream_id'] !== $payload['stream_id']
			|| (string) $snapshot['archive_id'] !== $payload['archive_id']
			|| (string) $snapshot['snapshot_digest'] !== $trigger_payload['snapshot_digest'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		return array( 'events' => $events, 'snapshot' => $snapshot, 'trigger' => $trigger );
	}

	/** @param array<string,mixed> $descriptor */
	private function assert_committed_object( array $descriptor ): void {
		$handle = $this->store->open_committed(
			$descriptor['storage_key'],
			'ledger',
			$descriptor['byte_count'],
			$descriptor['content_digest']
		);
		fclose( $handle );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function descriptor_from_row( array $row ): array {
		return array(
			'artifact_id'              => (string) $row['artifact_id'],
			'artifact_kind'            => (string) $row['artifact_kind'],
			'artifact_schema_version'  => (int) $row['artifact_schema_version'],
			'byte_count'               => (int) $row['byte_count'],
			'content_digest'           => (string) $row['content_digest'],
			'content_digest_algorithm' => (string) $row['content_digest_algorithm'],
			'filename'                 => (string) $row['filename'],
			'media_type'               => (string) $row['media_type'],
			'producer_key'             => (string) $row['producer_key'],
			'producer_version'         => (string) $row['producer_version'],
			'role_key'                 => (string) $row['role_key'],
			'storage_adapter'          => (string) $row['storage_adapter'],
			'storage_key'              => (string) $row['storage_key'],
		);
	}

	/** @param resource $stream */
	private function write_all( $stream, string $bytes ): bool {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			$written = fwrite( $stream, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/** @param array<string,mixed> $prepared @param array<string,mixed> $expected */
	private function prepared_matches_expected( array $prepared, array $expected ): bool {
		$descriptor = $prepared['artifact_descriptor'];
		$expected_descriptor = $expected['artifact_descriptor'];
		if ( array_keys( $descriptor ) !== array_keys( $expected_descriptor ) ) {
			return false;
		}
		foreach ( $expected_descriptor as $field => $value ) {
			if ( ! array_key_exists( $field, $descriptor ) || $descriptor[ $field ] !== $value ) {
				return false;
			}
		}
		$items = $prepared['ledger_items'];
		$expected_items = $expected['ledger_items'];
		if ( count( $items ) !== count( $expected_items ) || count( $items ) > GHCA_ACD_Archive_Ledger_Materializer::MAX_LEDGER_ITEMS ) {
			return false;
		}
		foreach ( $expected_items as $ordinal => $expected_item ) {
			if ( ! isset( $items[ $ordinal ] ) || ! is_array( $items[ $ordinal ] )
				|| array_keys( $items[ $ordinal ] ) !== array_keys( $expected_item ) ) {
				return false;
			}
			foreach ( $expected_item as $field => $value ) {
				if ( ! array_key_exists( $field, $items[ $ordinal ] ) || $items[ $ordinal ][ $field ] !== $value ) {
					return false;
				}
			}
		}
		return true;
	}

	private function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, $reason, $message );
	}

	private function integrity( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}
}
