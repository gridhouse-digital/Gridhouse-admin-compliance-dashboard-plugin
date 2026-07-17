<?php

/**
 * The only archive lifecycle transaction (Technical Design Section 8.1).
 *
 * One execute() call performs, on one shared $wpdb connection: receipt-first
 * idempotency lookup, stream row lock or first-stream creation, an
 * in-transaction receipt recheck that closes concurrent first-delivery races,
 * expected-sequence and expected-head-digest enforcement, ordered
 * authoritative rehydration, one closed command-bound domain decision, consecutive
 * sequence/predecessor-digest/server-time/event-digest assignment, append-only
 * event insertion, synchronous case/revision/reset/head projection, durable
 * immutable side-record insertion, task insertion, guarded stream-head advance, insert-once receipt storage,
 * and commit-before-success.
 * Every failure rolls back every write and returns no successful transition.
 *
 * No WordPress hook, filesystem, network, or other external I/O may run
 * inside the transaction.
 */
final class GHCA_ACD_Archive_Unit_Of_Work {
	const RESPONSE_SCHEMA_VERSION = 1;
	const SCOPE_FIELDS = array(
		'actor_or_integration_namespace',
		'case_key_digest_or_global_scope',
		'command_type',
		'site_id',
		'tenant_id',
	);

	/** @var wpdb|object */
	private $db;
	/** @var GHCA_ACD_Archive_Event_Store */
	private $event_store;
	/** @var GHCA_ACD_WPDB_Archive_Command_Store */
	private $command_store;
	/** @var GHCA_ACD_WPDB_Archive_Task_Store */
	private $task_store;
	/** @var GHCA_ACD_WPDB_Archive_Snapshot_Store */
	private $snapshot_store;
	/** @var GHCA_ACD_WPDB_Archive_Artifact_Repository */
	private $artifact_repository;
	/** @var GHCA_ACD_Archive_Projector */
	private $projector;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;
	/** @var GHCA_ACD_Archive_Id_Generator */
	private $id_generator;
	/** @var bool */
	private $in_transaction = false;

	/** @param wpdb|object $db */
	public function __construct( $db, GHCA_ACD_Archive_Event_Store $event_store, GHCA_ACD_WPDB_Archive_Command_Store $command_store, GHCA_ACD_WPDB_Archive_Task_Store $task_store, GHCA_ACD_WPDB_Archive_Snapshot_Store $snapshot_store, GHCA_ACD_WPDB_Archive_Artifact_Repository $artifact_repository, GHCA_ACD_Archive_Projector $projector, GHCA_ACD_Archive_Clock $clock, GHCA_ACD_Archive_Id_Generator $id_generator ) {
		if ( ! is_callable( array( $event_store, 'database' ) )
			|| $event_store->database() !== $db
			|| $command_store->database() !== $db
			|| $task_store->database() !== $db
			|| $snapshot_store->database() !== $db
			|| $artifact_repository->database() !== $db
			|| $projector->repository()->database() !== $db ) {
			throw new LogicException( 'The unit of work requires every store to share its one database connection.' );
		}
		$this->db            = $db;
		$this->event_store   = $event_store;
		$this->command_store = $command_store;
		$this->task_store    = $task_store;
		$this->snapshot_store = $snapshot_store;
		$this->artifact_repository = $artifact_repository;
		$this->projector     = $projector;
		$this->clock         = $clock;
		$this->id_generator  = $id_generator;
	}

	/**
	 * Execute one authoritative command decision.
	 *
	 * Required request keys:
	 * - command:              GHCA_ACD_Archive_Command
	 * - case_key:             GHCA_ACD_Archive_Case_Key
	 * - idempotency_scope:    canonical ghca-idempotency scope v1 document
	 * - expected_head_digest: 64-hex digest, or null only at expected sequence 0
	 * - correlation_id:       32-hex correlation identifier
	 * Optional: side_records, causation_event_id, effective_at_gmt,
	 * reason_code, reason_text.
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,mixed> The stable committed response document.
	 */
	public function execute( array $request ): array {
		$request = $this->validate_request( $request );
		$command = $request['command'];
		$intent  = $command->client_intent();
		$dedupe  = $intent->dedupe_digest();

		$attempts = 0;
		while ( true ) {
			$attempts++;
			$receipt = $this->command_store->find_receipt( $dedupe );
			if ( null !== $receipt ) {
				return $this->command_store->match_receipt( $receipt, $intent, $request['idempotency_scope'] );
			}
			try {
				return $this->run_transaction( $request, $intent, $dedupe );
			} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
				if ( in_array( $error->reason_code(), array( 'stream_creation_race', 'transaction_retryable_conflict' ), true ) && $attempts < 3 ) {
					continue;
				}
				if ( 'receipt_insert_race' === $error->reason_code() ) {
					$receipt = $this->command_store->find_receipt( $dedupe );
					if ( null !== $receipt ) {
						return $this->command_store->match_receipt( $receipt, $intent, $request['idempotency_scope'] );
					}
				}
				throw $error;
			}
		}
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	private function run_transaction( array $request, GHCA_ACD_Archive_Client_Intent $intent, string $dedupe_digest ): array {
		$command  = $request['command'];
		$case_key = $request['case_key'];
		$this->begin();
		try {
			$now    = $this->clock->now_gmt();
			$digest = $case_key->digest();
			$stream = $this->event_store->find_stream_for_update( $digest );
			if ( null === $stream ) {
				$stream = $this->event_store->create_stream( $this->stream_identity( $case_key ), $now );
				$this->projector->initialize_stream( $stream, $now );
			} elseif ( ! $this->event_store->stream_identity_matches( $stream, $this->stream_identity( $case_key ) ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'case_key_collision',
					'The stored stream constituents do not exactly match the requested case key.'
				);
			}

			$receipt = $this->command_store->find_receipt( $dedupe_digest );
			if ( null !== $receipt ) {
				$response = $this->command_store->match_receipt( $receipt, $intent, $request['idempotency_scope'] );
				$this->rollback();
				return $response;
			}
			$head_sequence = (string) $stream['head_sequence'];
			$head_digest   = null === $stream['head_event_digest'] ? null : (string) $stream['head_event_digest'];
			if ( ! GHCA_ACD_Archive_Db_Format::sequences_equal( $command->expected_sequence(), $head_sequence ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
					'expected_sequence_conflict',
					'The command expected stream sequence no longer matches the stream head.'
				);
			}
			$expected_digest = $request['expected_head_digest'];
			if ( ( null === $expected_digest ) !== ( null === $head_digest )
				|| ( null !== $expected_digest && ! hash_equals( $head_digest, $expected_digest ) ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
					'expected_head_digest_conflict',
					'The command expected head digest no longer matches the stream head.'
				);
			}

			$prior_events = $this->event_store->load_events( (string) $stream['stream_id'] );
			$aggregate    = $this->rehydrate( $prior_events );
			$events       = $command->decide( $aggregate );
			$this->assert_decision_batch( $aggregate, $events );

			$recorded = $this->record_batch( $events, $stream, $command, $request, $now );
			$this->persist_or_verify_side_records( $request, $stream, $prior_events, $recorded, $now );
			$this->event_store->append_events( $recorded );
			$this->projector->apply_new_events( $stream, $prior_events, $recorded, $now );
			$this->enqueue_tasks( $recorded, $prior_events, $now );

			$last = $recorded[ count( $recorded ) - 1 ];
			$this->event_store->advance_stream_head(
				(string) $stream['stream_id'],
				$head_sequence,
				$head_digest,
				$last->stream_sequence(),
				$last->event_digest(),
				$now
			);

			$response = $this->build_response( $command, $stream, $recorded );
			$this->command_store->insert_receipt( $this->build_receipt( $command, $stream, $recorded, $request, $response, $dedupe_digest, $now ) );
			$this->commit();
			return $response;
		} catch ( Throwable $error ) {
			$retryable_database_conflict = GHCA_ACD_Archive_Db_Format::is_retryable_transaction_error( $this->db );
			$this->rollback();
			if ( $retryable_database_conflict ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT,
					'transaction_retryable_conflict',
					'The transaction was selected as a retryable database concurrency victim.'
				);
			}
			throw $this->map_failure( $error );
		}
	}

	/**
	 * @param array<int,GHCA_ACD_Archive_Event> $events
	 * @param array<string,mixed> $stream
	 * @param array<string,mixed> $request
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	private function record_batch( array $events, array $stream, GHCA_ACD_Archive_Command $command, array $request, string $now ): array {
		$command_document = $command->canonical();
		$actor            = $command_document['actor'];
		$sequence         = (string) $stream['head_sequence'];
		$chain_digest     = null === $stream['head_event_digest'] ? null : (string) $stream['head_event_digest'];
		$recorded         = array();
		foreach ( $events as $event ) {
			$sequence = GHCA_ACD_Archive_Db_Format::increment_sequence( $sequence );
			$payload  = $event->payload();
			try {
				$recorded_event = $event->with_recording_context( array(
					'canonical_format_version' => 1,
					'event_id'                 => $this->id_generator->generate(),
					'stream_id'                => (string) $stream['stream_id'],
					'case_key_digest'          => (string) $stream['case_key_digest'],
					'case_key_format_version'  => 1,
					'stream_sequence'          => $sequence,
					'archive_id'               => $this->payload_archive_id( $payload ),
					'build_attempt_id'         => $this->payload_build_attempt_id( $event->type(), $payload ),
					'reset_operation_id'       => array_key_exists( 'reset_operation_id', $payload ) ? $payload['reset_operation_id'] : null,
					'actor_kind'               => $actor['actor_kind'],
					'actor_user_id'            => $actor['actor_user_id'],
					'initiating_user_id'       => $actor['initiating_user_id'],
					'source_channel'           => $actor['source_channel'],
					'authority_code'           => $actor['authority_code'],
					'authority_context'        => $actor['authority_context'],
					'occurred_at_gmt'          => $now,
					'effective_at_gmt'         => $request['effective_at_gmt'],
					'correlation_id'           => $request['correlation_id'],
					'causation_event_id'       => $request['causation_event_id'],
					'command_id'               => $command_document['command_id'],
					'upstream_operation_id'    => array_key_exists( 'upstream_operation_id', $payload ) ? $payload['upstream_operation_id'] : null,
					'idempotency_scope_digest' => $command_document['idempotency_scope_digest'],
					'idempotency_key_digest'   => $command_document['idempotency_key_digest'],
					'command_digest'           => $command->digest(),
					'reason_code'              => $request['reason_code'],
					'reason_text'              => $request['reason_text'],
					'previous_event_digest'    => $chain_digest,
					'recorded_at_gmt'          => $now,
				) );
			} catch ( InvalidArgumentException $error ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
					'event_recording_rejected',
					'The decision events could not be bound to a valid authoritative envelope.'
				);
			}
			$recorded[]   = $recorded_event;
			$chain_digest = $recorded_event->event_digest();
		}
		return $recorded;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $prior_events */
	private function rehydrate( array $prior_events ): GHCA_ACD_Archive_Case {
		try {
			return GHCA_ACD_Archive_Case::rehydrate( $prior_events );
		} catch ( Throwable $error ) {
			// Any replay failure of already-committed events — including an
			// illegal stored transition — is an integrity failure of the
			// authoritative record, never a caller-facing domain rejection.
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'rehydration_failed',
				'The authoritative stream could not be rehydrated.'
			);
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event>|mixed $events */
	private function assert_decision_batch( GHCA_ACD_Archive_Case $aggregate, $events ): void {
		if ( ! is_array( $events ) || array() === $events || $events !== $aggregate->uncommitted_events() ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_decision_result',
				'The decision must return exactly the complete uncommitted event batch it produced.'
			);
		}
		foreach ( $events as $event ) {
			if ( ! $event instanceof GHCA_ACD_Archive_Event || $event->is_recorded() ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
					'invalid_decision_result',
					'The decision must return uncommitted archive events only.'
				);
			}
		}
	}

	/**
	 * Persist the side records required by the accepted command, or verify the
	 * complete immutable set before finalization. This runs after authoritative
	 * event IDs are assigned but before any event is appended.
	 *
	 * @param array<string,mixed> $request
	 * @param array<string,mixed> $stream
	 * @param array<int,GHCA_ACD_Archive_Event> $prior_events
	 * @param array<int,GHCA_ACD_Archive_Event> $recorded
	 */
	private function persist_or_verify_side_records( array $request, array $stream, array $prior_events, array $recorded, string $now ): void {
		$type = $request['command']->type();
		$side = $request['side_records'];
		if ( 'RecordEvidenceSnapshot' === $type ) {
			$this->persist_snapshot_side_records( $side, $stream, $prior_events, $recorded[0], $now );
			return;
		}
		if ( 'RecordMaterializedArtifact' === $type ) {
			$this->persist_materialization_side_records( $side, $recorded[0], $now );
			return;
		}
		if ( 'VerifyAndFinalize' === $type ) {
			if ( null !== $side ) {
				throw $this->invalid_side_record( 'unexpected_side_records', 'Finalization verifies retained side records and accepts no replacement side-record payload.' );
			}
			$this->assert_finalization_side_records( $stream, $prior_events, $recorded );
			return;
		}
		if ( null !== $side ) {
			throw $this->invalid_side_record( 'unexpected_side_records', 'This command does not accept side records.' );
		}
	}

	/** @param mixed $side @param array<string,mixed> $stream @param array<int,GHCA_ACD_Archive_Event> $prior_events */
	private function persist_snapshot_side_records( $side, array $stream, array $prior_events, GHCA_ACD_Archive_Event $event, string $now ): void {
		if ( ! is_array( $side ) || ! $this->has_exact_fields( $side, array( 'artifacts', 'snapshot' ) )
			|| ! is_array( $side['snapshot'] ) || ! is_array( $side['artifacts'] ) || ! $this->is_list( $side['artifacts'] ) ) {
			throw $this->invalid_side_record( 'side_records_required', 'Snapshot capture requires one snapshot document and its ordered certificate descriptors.' );
		}
		if ( count( $side['artifacts'] ) > GHCA_ACD_WPDB_Archive_Snapshot_Store::MAX_EVIDENCE_ASSETS ) {
			throw $this->invalid_side_record( 'side_evidence_asset_count_exceeded', 'The snapshot evidence-asset count exceeds the approved ceiling.' );
		}
		$payload = $event->payload();
		if ( count( $side['artifacts'] ) !== count( $payload['certificate_asset_ids'] ) ) {
			throw $this->invalid_side_record( 'snapshot_certificate_manifest_mismatch', 'The certificate descriptors do not match the snapshot event manifest.' );
		}
		$attempt_id = $this->current_build_attempt( $prior_events, $payload['archive_id'] );
		if ( null === $attempt_id ) {
			throw $this->invalid_side_record( 'snapshot_build_attempt_missing', 'The snapshot has no authoritative build-attempt binding.' );
		}
		$snapshot = $this->snapshot_store->insert( $side['snapshot'], $event );
		$document = $snapshot['snapshot_document'];
		$request_event = $this->find_prior_archive_request( $prior_events, $payload['archive_id'] );
		if ( null === $request_event ) {
			throw $this->invalid_side_record( 'snapshot_review_binding_mismatch', 'The snapshot has no authoritative reviewed request.' );
		}
		$request_payload = $request_event->payload();
		$request_envelope = $request_event->recorded_document();
		$review = $document['review'];
		if ( $review['request_event_id'] !== $request_event->event_id()
			|| $review['requested_at_gmt'] !== $request_envelope['occurred_at_gmt']
			|| $review['actor_user_id'] !== $request_envelope['actor_user_id']
			|| $review['initiating_user_id'] !== $request_envelope['initiating_user_id']
			|| $review['authority_code'] !== $request_envelope['authority_code']
			|| $review['reviewed_source_fingerprint'] !== $request_payload['reviewed_source_fingerprint']
			|| $review['subject_scope_digest'] !== $request_payload['subject_scope_digest']
			|| $document['cycle'] !== $request_payload['resolved_cycle']
			|| $document['policy']['policy_digest'] !== $request_payload['policy_digest'] ) {
			throw $this->invalid_side_record( 'snapshot_review_binding_mismatch', 'The snapshot review evidence contradicts its authoritative request.' );
		}
		foreach ( $side['artifacts'] as $index => $descriptor ) {
			$asset = $document['source']['evidence_assets'][ $index ];
			if ( ! is_array( $descriptor ) || ! isset(
				$descriptor['artifact_id'], $descriptor['artifact_kind'], $descriptor['content_digest'], $descriptor['role_key'],
				$descriptor['byte_count'], $descriptor['producer_key'], $descriptor['producer_version']
			)
				|| 'certificate' !== $descriptor['artifact_kind']
				|| $descriptor['artifact_id'] !== $payload['certificate_asset_ids'][ $index ]
				|| $descriptor['content_digest'] !== $payload['certificate_content_digests'][ $index ]
				|| $descriptor['artifact_id'] !== $asset['artifact_id'] || $descriptor['content_digest'] !== $asset['content_digest']
				|| $descriptor['role_key'] !== $asset['role_key'] || $descriptor['byte_count'] !== $asset['byte_count']
				|| $descriptor['producer_key'] !== $asset['producer_key'] || $descriptor['producer_version'] !== $asset['producer_version'] ) {
				throw $this->invalid_side_record( 'snapshot_certificate_manifest_mismatch', 'The certificate descriptors do not match the snapshot event manifest.' );
			}
			$this->artifact_repository->insert_descriptor( $descriptor, array(
				'archive_id'       => $payload['archive_id'],
				'build_attempt_id' => $attempt_id,
				'created_at_gmt'   => $now,
				'snapshot_digest'  => $payload['snapshot_digest'],
				'snapshot_id'      => $payload['snapshot_id'],
				'stream_id'        => (string) $stream['stream_id'],
			) );
		}
	}

	/** @param mixed $side */
	private function persist_materialization_side_records( $side, GHCA_ACD_Archive_Event $event, string $now ): void {
		if ( ! is_array( $side ) || ! $this->has_exact_fields( $side, array( 'artifact', 'ledger_items' ) )
			|| ! is_array( $side['artifact'] ) || ! is_array( $side['ledger_items'] ) || ! $this->is_list( $side['ledger_items'] ) ) {
			throw $this->invalid_side_record( 'side_records_required', 'Materialization requires one artifact descriptor and an ordered ledger-item list.' );
		}
		$payload = $event->payload();
		$is_ledger = GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type();
		$artifact_id_field = $is_ledger ? 'ledger_artifact_id' : 'packet_artifact_id';
		$kind = $is_ledger ? 'ledger' : 'packet';
		$descriptor = $side['artifact'];
		if ( ! isset( $descriptor['artifact_id'], $descriptor['artifact_kind'], $descriptor['content_digest'] )
			|| $descriptor['artifact_id'] !== $payload[ $artifact_id_field ]
			|| $descriptor['artifact_kind'] !== $kind
			|| $descriptor['content_digest'] !== $payload['content_digest'] ) {
			throw $this->invalid_side_record( 'artifact_event_binding_mismatch', 'The artifact descriptor contradicts its materialization event.' );
		}
		if ( ! $is_ledger && array() !== $side['ledger_items'] ) {
			throw $this->invalid_side_record( 'packet_ledger_items_forbidden', 'A packet artifact cannot carry ledger-item side records.' );
		}
		$binding = array(
			'archive_id'       => $payload['archive_id'],
			'build_attempt_id' => $payload['build_attempt_id'],
			'created_at_gmt'   => $now,
			'snapshot_digest'  => $payload['snapshot_digest'],
			'snapshot_id'      => $payload['snapshot_id'],
			'stream_id'        => $event->stream_id(),
		);
		$snapshot = $this->snapshot_store->find( $payload['snapshot_id'] );
		if ( null === $snapshot ) {
			throw $this->invalid_side_record( 'artifact_snapshot_missing', 'Materialization requires the exact retained snapshot.' );
		}
		if ( (string) $snapshot['stream_id'] !== $event->stream_id()
			|| (string) $snapshot['archive_id'] !== $payload['archive_id']
			|| (string) $snapshot['snapshot_digest'] !== $payload['snapshot_digest'] ) {
			throw $this->invalid_side_record( 'artifact_snapshot_binding_mismatch', 'The materialized artifact contradicts the retained snapshot.' );
		}
		$asset_digests = array();
		foreach ( $snapshot['snapshot_document']['source']['evidence_assets'] as $asset ) {
			$asset_digests[] = $asset['content_digest'];
		}
		if ( ! $is_ledger && $payload['certificate_content_digests'] !== $asset_digests ) {
			throw $this->invalid_side_record( 'artifact_snapshot_binding_mismatch', 'The packet certificate manifest contradicts the retained snapshot.' );
		}
		if ( $is_ledger ) {
			if ( count( $side['ledger_items'] ) > GHCA_ACD_WPDB_Archive_Artifact_Repository::MAX_LEDGER_ITEMS ) {
				throw $this->invalid_side_record( 'side_ledger_item_count_exceeded', 'The ledger item count exceeds the approved ceiling.' );
			}
			$this->assert_ledger_matches_snapshot( $side['ledger_items'], $snapshot['snapshot_document'], false );
		}
		$this->artifact_repository->insert_descriptor( $descriptor, $binding );
		if ( $is_ledger ) {
			$this->artifact_repository->insert_ledger_items( $side['ledger_items'], array(
				'archive_id'         => $payload['archive_id'],
				'item_count'         => $payload['item_count'],
				'ledger_artifact_id' => $payload['ledger_artifact_id'],
				'manifest_digest'    => $payload['manifest_digest'],
				'snapshot_id'        => $payload['snapshot_id'],
				'stream_id'          => $event->stream_id(),
			) );
		}
	}

	/** @param array<string,mixed> $stream @param array<int,GHCA_ACD_Archive_Event> $prior_events @param array<int,GHCA_ACD_Archive_Event> $recorded */
	private function assert_finalization_side_records( array $stream, array $prior_events, array $recorded ): void {
		$verified = $recorded[0]->payload();
		$finalized = $recorded[1]->payload();
		$snapshot = null;
		$snapshot_event = null;
		$ledger = null;
		$packet = null;
		try {
			$snapshot = $this->snapshot_store->find( $verified['snapshot_id'] );
			if ( null === $snapshot ) {
				throw $this->integrity_side_record( 'finalization_missing_snapshot', 'Finalization requires the exact retained evidence snapshot.' );
			}
			$snapshot_event = $this->find_prior_snapshot_capture( $prior_events, $verified['snapshot_id'] );
			if ( null === $snapshot_event ) {
				throw $this->integrity_side_record( 'finalization_missing_snapshot', 'Finalization requires the authoritative snapshot-capture event.' );
			}
			$ledger = $this->artifact_repository->find_descriptor( $verified['ledger_artifact_id'], array(
				'archive_id' => $verified['archive_id'], 'artifact_kind' => 'ledger',
				'content_digest' => $verified['ledger_content_digest'], 'snapshot_digest' => $verified['snapshot_digest'],
				'snapshot_id' => $verified['snapshot_id'], 'stream_id' => (string) $stream['stream_id'],
			) );
			$packet = $this->artifact_repository->find_descriptor( $verified['packet_artifact_id'], array(
				'archive_id' => $verified['archive_id'], 'artifact_kind' => 'packet',
				'content_digest' => $verified['packet_content_digest'], 'snapshot_digest' => $verified['snapshot_digest'],
				'snapshot_id' => $verified['snapshot_id'], 'stream_id' => (string) $stream['stream_id'],
			) );
			if ( null === $ledger || null === $packet ) {
				throw $this->integrity_side_record( 'finalization_missing_artifact', 'Finalization requires the exact retained ledger and packet descriptors.' );
			}
			$this->assert_finalization_bindings( $stream, $prior_events, $snapshot, $snapshot_event, $ledger, $packet, $verified, $finalized );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( in_array( $error->reason_code(), array( 'finalization_missing_snapshot', 'finalization_missing_artifact' ), true ) ) {
				throw $error;
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category() ) {
				throw $this->integrity_side_record( 'finalization_digest_mismatch', 'The retained side records failed exact finalization verification.' );
			}
			throw $error;
		}
	}

	/** @param array<string,mixed> $stream @param array<int,GHCA_ACD_Archive_Event> $prior_events @param array<string,mixed> $snapshot @param array<string,mixed> $ledger @param array<string,mixed> $packet @param array<string,mixed> $verified @param array<string,mixed> $finalized */
	private function assert_finalization_bindings( array $stream, array $prior_events, array $snapshot, GHCA_ACD_Archive_Event $snapshot_event, array $ledger, array $packet, array $verified, array $finalized ): void {
		$document = $snapshot['snapshot_document'];
		$capture_event = $snapshot_event->payload();
		$ledger_event = $this->find_prior_materialization( $prior_events, GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED, $verified['ledger_artifact_id'] );
		$packet_event = $this->find_prior_materialization( $prior_events, GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED, $verified['packet_artifact_id'] );
		if ( null === $ledger_event || null === $packet_event ) {
			throw $this->integrity_side_record( 'finalization_missing_artifact', 'Finalization requires authoritative materialization events for both artifacts.' );
		}
		$items = $this->artifact_repository->load_ledger_items( $verified['ledger_artifact_id'] );
		$this->assert_ledger_matches_snapshot( $items, $document, true );
		$item_digests = array();
		foreach ( $items as $item ) {
			$item_digests[] = $item['item_digest'];
		}
		$asset_ids = array();
		$asset_digests = array();
		$certificate_build_attempt = $this->build_attempt_at_event( $prior_events, $snapshot_event );
		if ( null === $certificate_build_attempt ) {
			throw $this->integrity_side_record( 'finalization_digest_mismatch', 'The snapshot has no authoritative build-attempt context.' );
		}
		foreach ( $document['source']['evidence_assets'] as $asset ) {
			$asset_ids[] = $asset['artifact_id'];
			$asset_digests[] = $asset['content_digest'];
			$certificate = $this->artifact_repository->find_descriptor( $asset['artifact_id'], array(
				'archive_id' => $verified['archive_id'], 'artifact_kind' => 'certificate',
				'build_attempt_id' => $certificate_build_attempt,
				'content_digest' => $asset['content_digest'], 'snapshot_digest' => $verified['snapshot_digest'],
				'snapshot_id' => $verified['snapshot_id'], 'stream_id' => (string) $stream['stream_id'],
			) );
			if ( null === $certificate ) {
				throw $this->integrity_side_record( 'finalization_missing_artifact', 'Finalization requires every retained certificate descriptor.' );
			}
		}
		$finalized_fields = array(
			'archive_id', 'revision_number', 'snapshot_id', 'snapshot_digest', 'ledger_artifact_id',
			'ledger_content_digest', 'packet_artifact_id', 'packet_content_digest', 'expected_predecessor_archive_id',
		);
		foreach ( $finalized_fields as $field ) {
			if ( $finalized[ $field ] !== $verified[ $field ] ) {
				throw $this->integrity_side_record( 'finalization_digest_mismatch', 'The verified and finalized event bindings are not exact.' );
			}
		}
		$matches = (string) $snapshot['stream_id'] === (string) $stream['stream_id']
			&& (string) $snapshot['source_event_id'] === $snapshot_event->event_id()
			&& $snapshot_event->stream_id() === (string) $stream['stream_id']
			&& (string) $snapshot['archive_id'] === $verified['archive_id']
			&& (string) $snapshot['revision_number'] === (string) $verified['revision_number']
			&& (string) $snapshot['snapshot_digest'] === $verified['snapshot_digest']
			&& (string) $snapshot['captured_source_fingerprint'] === $verified['source_fingerprint']
			&& $capture_event['archive_id'] === $verified['archive_id']
			&& $capture_event['snapshot_id'] === $verified['snapshot_id']
			&& $capture_event['revision_number'] === $verified['revision_number']
			&& $capture_event['snapshot_schema_version'] === (int) $snapshot['snapshot_schema_version']
			&& $capture_event['snapshot_digest'] === $verified['snapshot_digest']
			&& $capture_event['byte_count'] === (int) $snapshot['byte_count']
			&& $capture_event['reviewed_source_fingerprint'] === (string) $snapshot['reviewed_source_fingerprint']
			&& $capture_event['captured_source_fingerprint'] === (string) $snapshot['captured_source_fingerprint']
			&& $capture_event['policy_digest'] === (string) $snapshot['policy_digest']
			&& $capture_event['completeness_policy'] === (string) $snapshot['completeness_policy']
			&& $capture_event['subject_scope_digest'] === $document['review']['subject_scope_digest']
			&& $capture_event['resolved_cycle'] === $document['cycle']
			&& $capture_event['certificate_asset_ids'] === $asset_ids
			&& $capture_event['certificate_content_digests'] === $asset_digests
			&& (string) $stream['case_key_digest'] === $verified['active_identity_digest']
			&& (string) $ledger['build_attempt_id'] === (string) $packet['build_attempt_id']
			&& (string) $ledger['build_attempt_id'] === $ledger_event['build_attempt_id']
			&& (string) $packet['build_attempt_id'] === $packet_event['build_attempt_id']
			&& $ledger_event['archive_id'] === $verified['archive_id']
			&& $packet_event['archive_id'] === $verified['archive_id']
			&& $ledger_event['snapshot_id'] === $verified['snapshot_id']
			&& $packet_event['snapshot_id'] === $verified['snapshot_id']
			&& $ledger_event['snapshot_digest'] === $verified['snapshot_digest']
			&& $packet_event['snapshot_digest'] === $verified['snapshot_digest']
			&& $ledger_event['content_digest'] === $verified['ledger_content_digest']
			&& $packet_event['content_digest'] === $verified['packet_content_digest']
			&& $ledger_event['item_count'] === count( $items )
			&& hash_equals( $ledger_event['manifest_digest'], GHCA_ACD_Archive_Digester::ledger_manifest( $item_digests ) )
			&& $packet_event['certificate_content_digests'] === $asset_digests;
		if ( ! $matches ) {
			throw $this->integrity_side_record( 'finalization_digest_mismatch', 'The retained side records do not exactly match the finalization evidence.' );
		}
	}

	/** @param array<int,array<string,mixed>> $items @param array<string,mixed> $snapshot */
	private function assert_ledger_matches_snapshot( array $items, array $snapshot, bool $retained ): void {
		$courses = $snapshot['courses'];
		if ( count( $items ) !== count( $courses ) ) {
			throw $retained
				? $this->integrity_side_record( 'finalization_ledger_snapshot_mismatch', 'The retained ledger does not match the sealed snapshot.' )
				: $this->invalid_side_record( 'ledger_snapshot_evidence_mismatch', 'The ledger does not match the sealed snapshot.' );
		}
		foreach ( $courses as $ordinal => $course ) {
			$item = $items[ $ordinal ];
			if ( isset( $item['item_document'] ) && is_array( $item['item_document'] ) ) {
				$item = $item['item_document'];
			}
			$expected = array(
				'archive_id'              => $snapshot['case']['archive_id'],
				'certificate_artifact_id' => $course['certificate_artifact_id'],
				'completed_at_gmt'        => $course['completed_at_gmt'],
				'completion_status'       => $course['completion_status'],
				'course_id'               => $course['course_id'],
				'course_stable_key'       => $course['course_stable_key'],
				'course_title'            => $course['course_title'],
				'employee_user_id'        => $snapshot['subject']['employee_user_id'],
				'item_ordinal'            => $ordinal,
				'program_key'             => $snapshot['case']['program_key'],
				'quiz_score_basis_points' => $course['quiz_score_basis_points'],
				'snapshot_id'             => $snapshot['case']['snapshot_id'],
				'started_at_gmt'          => $course['started_at_gmt'],
				'stream_id'               => $snapshot['case']['stream_id'],
				'time_spent_seconds'      => $course['time_spent_seconds'],
				'cycle_key'               => $snapshot['case']['cycle_key'],
			);
			$matches = true;
			foreach ( $expected as $field => $value ) {
				if ( ! array_key_exists( $field, $item ) || $item[ $field ] !== $value ) {
					$matches = false;
					break;
				}
			}
			if ( ! $matches ) {
				throw $retained
					? $this->integrity_side_record( 'finalization_ledger_snapshot_mismatch', 'The retained ledger does not match the sealed snapshot.' )
					: $this->invalid_side_record( 'ledger_snapshot_evidence_mismatch', 'The ledger does not match the sealed snapshot.' );
			}
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private function find_prior_snapshot_capture( array $events, string $snapshot_id ): ?GHCA_ACD_Archive_Event {
		foreach ( array_reverse( $events ) as $event ) {
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED === $event->type() && $payload['snapshot_id'] === $snapshot_id ) {
				return $event;
			}
		}
		return null;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private function find_prior_archive_request( array $events, string $archive_id ): ?GHCA_ACD_Archive_Event {
		foreach ( array_reverse( $events ) as $event ) {
			$payload = $event->payload();
			if ( in_array( $event->type(), array(
				GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED,
				GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED,
			), true ) && $payload['archive_id'] === $archive_id ) {
				return $event;
			}
		}
		return null;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private function build_attempt_at_event( array $events, GHCA_ACD_Archive_Event $target ): ?string {
		$attempt_id = null;
		foreach ( $events as $event ) {
			if ( $event->event_id() === $target->event_id() ) {
				return $attempt_id;
			}
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED === $event->type() && $payload['archive_id'] === $target->payload()['archive_id'] ) {
				$attempt_id = $payload['build_attempt_id'];
			}
		}
		return null;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events @return array<string,mixed>|null */
	private function find_prior_materialization( array $events, string $type, string $artifact_id ) {
		$field = GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $type ? 'ledger_artifact_id' : 'packet_artifact_id';
		foreach ( array_reverse( $events ) as $event ) {
			$payload = $event->payload();
			if ( $event->type() === $type && $payload[ $field ] === $artifact_id ) {
				return $payload;
			}
		}
		return null;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private function current_build_attempt( array $events, string $archive_id ): ?string {
		foreach ( array_reverse( $events ) as $event ) {
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED === $event->type() && $payload['archive_id'] === $archive_id ) {
				return $payload['build_attempt_id'];
			}
		}
		return null;
	}

	/** @param array<string,mixed> $value @param array<int,string> $fields */
	private function has_exact_fields( array $value, array $fields ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $fields, SORT_STRING );
		return $actual === $fields;
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

	private function invalid_side_record( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, $reason, $message );
	}

	private function integrity_side_record( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}

	/**
	 * @param array<string,mixed> $stream
	 * @param array<int,GHCA_ACD_Archive_Event> $recorded
	 * @return array<string,mixed>
	 */
	private function build_response( GHCA_ACD_Archive_Command $command, array $stream, array $recorded ): array {
		$document = $command->canonical();
		$first    = $recorded[0];
		$last     = $recorded[ count( $recorded ) - 1 ];
		return GHCA_ACD_Archive_Canonical_JSON::detach( array(
			'case_key_digest'         => (string) $stream['case_key_digest'],
			'command_id'              => $document['command_id'],
			'command_type'            => $command->type(),
			'first_event_id'          => $first->event_id(),
			'first_stream_sequence'   => $first->stream_sequence(),
			'head_event_digest'       => $last->event_digest(),
			'last_event_id'           => $last->event_id(),
			'last_stream_sequence'    => $last->stream_sequence(),
			'response_schema_version' => self::RESPONSE_SCHEMA_VERSION,
			'result_code'             => 'committed',
			'stream_id'               => (string) $stream['stream_id'],
		) );
	}

	/**
	 * @param array<string,mixed> $stream
	 * @param array<int,GHCA_ACD_Archive_Event> $recorded
	 * @param array<string,mixed> $request
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function build_receipt( GHCA_ACD_Archive_Command $command, array $stream, array $recorded, array $request, array $response, string $dedupe_digest, string $now ): array {
		$document = $command->canonical();
		$first    = $recorded[0];
		$last     = $recorded[ count( $recorded ) - 1 ];
		return array(
			'command_id'                 => $document['command_id'],
			'stream_id'                  => (string) $stream['stream_id'],
			'command_type'               => $command->type(),
			'command_schema_version'     => 1,
			'canonical_format_version'   => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
			'idempotency_format_version' => 1,
			'dedupe_digest'              => $dedupe_digest,
			'idempotency_scope_digest'   => $document['idempotency_scope_digest'],
			'idempotency_scope_json'     => GHCA_ACD_Archive_Canonical_JSON::encode( $request['idempotency_scope'] ),
			'idempotency_key_digest'     => $document['idempotency_key_digest'],
			'client_intent_digest'       => $command->client_intent_digest(),
			'command_digest'             => $command->digest(),
			'actor_user_id'              => $document['actor']['actor_user_id'],
			'decision'                   => 'accepted',
			'result_code'                => 'committed',
			'first_stream_sequence'      => $first->stream_sequence(),
			'last_stream_sequence'       => $last->stream_sequence(),
			'first_event_id'             => $first->event_id(),
			'last_event_id'              => $last->event_id(),
			'response_schema_version'    => self::RESPONSE_SCHEMA_VERSION,
			'response_json'              => GHCA_ACD_Archive_Canonical_JSON::encode( $response ),
			'created_at_gmt'             => GHCA_ACD_Archive_Db_Format::utc_to_db( $now ),
		);
	}

	/** @return array<string,mixed> */
	private function stream_identity( GHCA_ACD_Archive_Case_Key $case_key ): array {
		$constituents = $case_key->canonical();
		$cycle        = $case_key->cycle()->canonical();
		return array(
			'stream_id'        => $this->id_generator->generate(),
			'case_key_digest'  => $case_key->digest(),
			'tenant_id'        => $constituents['tenant_id'],
			'site_id'          => $constituents['site_id_decimal'],
			'employee_user_id' => $constituents['employee_user_id_decimal'],
			'program_key'      => $constituents['program_key'],
			'cycle_key'        => $constituents['cycle_key'],
			'cycle_key_digest' => hash( 'sha256', $constituents['cycle_key'] ),
			'cycle_start_gmt'  => $cycle['start_gmt'],
			'cycle_end_gmt'    => $cycle['end_gmt'],
			'cycle_timezone'   => $cycle['timezone'],
			'cycle_policy_key' => $cycle['policy_key'] . '|' . $cycle['policy_version'],
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	private function validate_request( array $request ): array {
		$required = array( 'command', 'case_key', 'idempotency_scope', 'expected_head_digest', 'correlation_id' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $request ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
					'incomplete_request',
					'The unit-of-work request is missing a required field.'
				);
			}
		}
		if ( ! $request['command'] instanceof GHCA_ACD_Archive_Command
			|| ! $request['case_key'] instanceof GHCA_ACD_Archive_Case_Key
			|| array_key_exists( 'decision', $request ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_request',
				'The unit-of-work request fields are invalid.'
			);
		}
		$this->assert_command_case_binding( $request['command'], $request['case_key'] );
		if ( ! is_string( $request['correlation_id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $request['correlation_id'] ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_correlation',
				'The correlation identifier is invalid.'
			);
		}
		$expected_digest = $request['expected_head_digest'];
		$sequence_zero   = GHCA_ACD_Archive_Db_Format::sequences_equal( $request['command']->expected_sequence(), '0' );
		if ( $sequence_zero !== ( null === $expected_digest )
			|| ( null !== $expected_digest && ( ! is_string( $expected_digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) ) ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_expected_head_digest',
				'The expected head digest must be null exactly at expected sequence zero.'
			);
		}
		$this->validate_scope_document( $request['idempotency_scope'], $request['command'], $request['case_key'] );
		if ( ! array_key_exists( 'side_records', $request ) ) {
			$request['side_records'] = null;
		}
		foreach ( array( 'causation_event_id', 'effective_at_gmt', 'reason_code', 'reason_text' ) as $optional ) {
			if ( ! array_key_exists( $optional, $request ) ) {
				$request[ $optional ] = null;
			}
		}
		if ( null !== $request['causation_event_id']
			&& ( ! is_string( $request['causation_event_id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $request['causation_event_id'] ) ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_causation',
				'The causation event identifier is invalid.'
			);
		}
		return $request;
	}

	/** @param mixed $scope */
	private function validate_scope_document( $scope, GHCA_ACD_Archive_Command $command, GHCA_ACD_Archive_Case_Key $case_key ): void {
		if ( ! is_array( $scope ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_idempotency_scope',
				'The idempotency scope document is invalid.'
			);
		}
		$keys     = array_keys( $scope );
		$expected = self::SCOPE_FIELDS;
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_idempotency_scope',
				'The idempotency scope document does not match the v1 scope contract.'
			);
		}
		$constituents = $case_key->canonical();
		$command_doc  = $command->canonical();
		$actor        = $command_doc['actor'];
		$namespace    = $actor['actor_kind'] . ':' . ( 'wp_user' === $actor['actor_kind'] ? $actor['actor_user_id'] : $actor['authority_code'] );
		if ( $scope['command_type'] !== $command->type()
			|| $scope['tenant_id'] !== $constituents['tenant_id']
			|| (string) $scope['site_id'] !== $constituents['site_id_decimal']
			|| $scope['actor_or_integration_namespace'] !== $namespace ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'invalid_idempotency_scope',
				'The idempotency scope document contradicts the command or case identity.'
			);
		}
		if ( $scope['case_key_digest_or_global_scope'] !== $case_key->digest() ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'global_scope_unsupported',
				'This checkpoint supports case-scoped commands only; the scope must carry the exact case-key digest.'
			);
		}
		GHCA_ACD_Archive_Canonical_JSON::encode( $scope );
		$scope_digest = GHCA_ACD_Archive_Digester::idempotency_scope( $scope );
		if ( ! hash_equals( $command_doc['idempotency_scope_digest'], $scope_digest ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'idempotency_scope_digest_mismatch',
				'The command idempotency-scope digest does not match the canonical scope document.'
			);
		}
	}

	private function assert_command_case_binding( GHCA_ACD_Archive_Command $command, GHCA_ACD_Archive_Case_Key $case_key ): void {
		$expected = $case_key->canonical();
		$caller   = $command->caller_intent();
		$server   = $command->server_facts();
		$documents = array();
		if ( array_key_exists( 'case_key', $caller ) ) {
			$documents[] = $caller['case_key'];
		}
		if ( 'RebaseSourceDriftRecovery' === $command->type() && isset( $server['request']['case_key'] ) ) {
			$documents[] = $server['request']['case_key'];
		}
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || GHCA_ACD_Archive_Canonical_JSON::encode( $document ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $expected ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
					'command_case_mismatch',
					'The accepted command is not bound to the requested Archive Case.'
				);
			}
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events @param array<int,GHCA_ACD_Archive_Event> $prior_events */
	private function enqueue_tasks( array $events, array $prior_events, string $now_gmt ): void {
		$history = $prior_events;
		foreach ( $events as $event ) {
			$payload = $event->payload();
			switch ( $event->type() ) {
				case GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED:
				case GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED:
					$this->enqueue_task( 'capture_evidence', $event, array( 'archive_id' => $payload['archive_id'], 'stream_id' => $event->stream_id() ), $now_gmt, $now_gmt );
					break;
				case GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED:
					$attempt_id = $this->current_build_attempt( $history, $payload['archive_id'] );
					foreach ( array( 'materialize_ledger', 'materialize_packet' ) as $task_type ) {
						$this->enqueue_task( $task_type, $event, array(
							'archive_id'       => $payload['archive_id'],
							'build_attempt_id' => $attempt_id,
							'snapshot_id'      => $payload['snapshot_id'],
							'stream_id'        => $event->stream_id(),
						), $now_gmt, $now_gmt );
					}
					break;
				case GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED:
				case GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED:
					$counterpart = GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type()
						? GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED
						: GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED;
					if ( $this->has_matching_materialization( $history, $counterpart, $payload ) ) {
						$this->enqueue_task( 'verify_and_finalize', $event, array(
							'archive_id'       => $payload['archive_id'],
							'build_attempt_id' => $payload['build_attempt_id'],
							'snapshot_id'      => $payload['snapshot_id'],
							'stream_id'        => $event->stream_id(),
						), $now_gmt, $now_gmt );
					}
					break;
				case GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED:
					$task_types = 'capturing' === $payload['resume_phase']
						? array( 'capture_evidence' )
						: ( 'materializing' === $payload['resume_phase'] ? array( 'materialize_ledger', 'materialize_packet' ) : array( 'verify_and_finalize' ) );
					foreach ( $task_types as $task_type ) {
						$this->enqueue_task( $task_type, $event, array(
							'archive_id'       => $payload['archive_id'],
							'build_attempt_id' => $payload['new_build_attempt_id'],
							'snapshot_id'      => $payload['sealed_snapshot_id'],
							'stream_id'        => $event->stream_id(),
						), $now_gmt, $now_gmt );
					}
					break;
				case GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED:
					$reset_payload = array(
						'authorization_id'   => $payload['authorization_id'],
						'reset_operation_id' => $payload['reset_operation_id'],
						'stream_id'          => $event->stream_id(),
					);
					$this->enqueue_task( 'execute_reset', $event, $reset_payload, $now_gmt, $now_gmt );
					$this->enqueue_task( 'expire_reset_authorization', $event, $reset_payload, $now_gmt, $payload['expires_at_gmt'] );
					break;
				case GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN:
					$this->enqueue_task( 'reconcile_reset', $event, array(
						'reset_operation_id'   => $payload['reset_operation_id'],
						'stream_id'            => $event->stream_id(),
						'upstream_operation_id' => $payload['upstream_operation_id'],
					), $now_gmt, $now_gmt );
					break;
			}
			$history[] = $event;
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events @param array<string,mixed> $payload */
	private function has_matching_materialization( array $events, string $event_type, array $payload ): bool {
		foreach ( $events as $event ) {
			$prior = $event->payload();
			if ( $event->type() === $event_type
				&& $prior['archive_id'] === $payload['archive_id']
				&& $prior['snapshot_id'] === $payload['snapshot_id']
				&& $prior['build_attempt_id'] === $payload['build_attempt_id'] ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $payload */
	private function enqueue_task( string $task_type, GHCA_ACD_Archive_Event $event, array $payload, string $now_gmt, string $available_at_gmt ): void {
		$payload = array_merge( array(
			'canonical_format_version' => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
			'task_schema_version'      => GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION,
			'task_type'                => $task_type,
			'trigger_event_id'         => $event->event_id(),
		), $payload );
		$dedupe = GHCA_ACD_Archive_Digester::task_dedupe( array(
			'payload'          => $payload,
			'task_type'        => $task_type,
			'trigger_event_id' => $event->event_id(),
		) );
		$document = $event->recorded_document();
		$this->task_store->enqueue( array(
			'task_id'            => $this->id_generator->generate(),
			'trigger_kind'       => 'event',
			'trigger_event_id'   => $event->event_id(),
			'trigger_command_id' => null,
			'stream_id'          => $event->stream_id(),
			'archive_id'         => $document['archive_id'],
			'build_attempt_id'   => array_key_exists( 'build_attempt_id', $payload ) ? $payload['build_attempt_id'] : $document['build_attempt_id'],
			'reset_operation_id' => $document['reset_operation_id'],
			'task_type'          => $task_type,
			'task_schema_version' => GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION,
			'dedupe_digest'       => $dedupe,
			'payload_json'        => GHCA_ACD_Archive_Canonical_JSON::encode( $payload ),
			'task_state'          => 'pending',
			'attempt_count'       => 0,
			'max_attempts'        => GHCA_ACD_WPDB_Archive_Task_Store::MAX_ATTEMPTS,
			'available_at_gmt'    => GHCA_ACD_Archive_Db_Format::utc_to_db( $available_at_gmt ),
			'lease_owner'         => null,
			'lease_token'         => null,
			'lease_until_gmt'     => null,
			'last_error_code'     => null,
			'last_error_text'     => null,
			'created_at_gmt'      => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
			'updated_at_gmt'      => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
			'completed_at_gmt'    => null,
		) );
	}

	/** @param array<string,mixed> $payload */
	private function payload_archive_id( array $payload ): ?string {
		foreach ( array( 'archive_id', 'target_archive_id', 'bound_archive_id' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				return $payload[ $field ];
			}
		}
		return null;
	}

	/** @param array<string,mixed> $payload */
	private function payload_build_attempt_id( string $event_type, array $payload ): ?string {
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $event_type ) {
			return $payload['new_build_attempt_id'];
		}
		return array_key_exists( 'build_attempt_id', $payload ) ? $payload['build_attempt_id'] : null;
	}

	private function map_failure( Throwable $error ): Throwable {
		if ( $error instanceof GHCA_ACD_Archive_Persistence_Exception || $error instanceof GHCA_ACD_Archive_Transition_Exception ) {
			return $error;
		}
		if ( $error instanceof InvalidArgumentException ) {
			return new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'command_rejected',
				'The command was rejected before any transition committed.'
			);
		}
		return new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
			'transaction_failed',
			'The archive transaction failed and was rolled back.'
		);
	}

	private function begin(): void {
		if ( $this->in_transaction ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'nested_transaction',
				'The unit of work does not support nested transactions.'
			);
		}
		$result = $this->db->query( 'START TRANSACTION' );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'transaction_begin_failed',
				'The archive transaction could not be started.'
			);
		}
		$this->in_transaction = true;
	}

	private function commit(): void {
		$result = $this->db->query( 'COMMIT' );
		$this->in_transaction = false;
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			// Do not leave the connection inside an open transaction: attempt
			// an explicit rollback before reporting the commit failure.
			$this->db->query( 'ROLLBACK' );
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'transaction_commit_failed',
				'The archive transaction could not be committed; no success is reported.'
			);
		}
	}

	private function rollback(): void {
		if ( ! $this->in_transaction ) {
			return;
		}
		$this->in_transaction = false;
		$this->db->query( 'ROLLBACK' );
	}
}
