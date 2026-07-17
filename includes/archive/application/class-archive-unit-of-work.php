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
 * task insertion, guarded stream-head advance, insert-once receipt storage,
 * and commit-before-success.
 * Every failure rolls back every write and returns no successful transition.
 *
 * Snapshot/artifact/ledger side records belong to a later authorized slice.
 * Commands that require those records fail closed before appending an event.
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
	/** @var GHCA_ACD_Archive_Projector */
	private $projector;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;
	/** @var GHCA_ACD_Archive_Id_Generator */
	private $id_generator;
	/** @var bool */
	private $in_transaction = false;

	/** @param wpdb|object $db */
	public function __construct( $db, GHCA_ACD_Archive_Event_Store $event_store, GHCA_ACD_WPDB_Archive_Command_Store $command_store, GHCA_ACD_WPDB_Archive_Task_Store $task_store, GHCA_ACD_Archive_Projector $projector, GHCA_ACD_Archive_Clock $clock, GHCA_ACD_Archive_Id_Generator $id_generator ) {
		if ( ! is_callable( array( $event_store, 'database' ) )
			|| $event_store->database() !== $db
			|| $command_store->database() !== $db
			|| $task_store->database() !== $db
			|| $projector->repository()->database() !== $db ) {
			throw new LogicException( 'The unit of work requires every store to share its one database connection.' );
		}
		$this->db            = $db;
		$this->event_store   = $event_store;
		$this->command_store = $command_store;
		$this->task_store    = $task_store;
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
	 * Optional: causation_event_id, effective_at_gmt, reason_code, reason_text.
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
			$this->assert_atomic_command_supported( $command->type() );

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
			$this->event_store->append_events( $recorded );
			$this->projector->apply_new_events( $stream, $prior_events, $recorded, $now );
			$this->enqueue_tasks( $recorded, $now );

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

	private function assert_atomic_command_supported( string $command_type ): void {
		if ( in_array( $command_type, array( 'RecordEvidenceSnapshot', 'RecordMaterializedArtifact', 'VerifyAndFinalize' ), true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
				'atomic_side_records_unavailable',
				'This command requires snapshot or artifact side records from a later authorized slice.'
			);
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private function enqueue_tasks( array $events, string $now_gmt ): void {
		foreach ( $events as $event ) {
			$payload = $event->payload();
			switch ( $event->type() ) {
				case GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED:
				case GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED:
					$this->enqueue_task( 'capture_evidence', $event, array( 'archive_id' => $payload['archive_id'], 'stream_id' => $event->stream_id() ), $now_gmt, $now_gmt );
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
		}
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
			'build_attempt_id'   => $document['build_attempt_id'],
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
