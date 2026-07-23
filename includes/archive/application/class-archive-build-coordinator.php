<?php

/** Fenced application coordinator over the existing aggregate and Unit of Work. */
final class GHCA_ACD_Archive_Build_Coordinator {
	const FAILURE_CODES = array(
		'archive_build_binding_invalid',
		'archive_evidence_incomplete',
		'archive_certificate_invalid',
		'archive_source_drift',
		'archive_snapshot_invalid',
		'archive_ledger_invalid',
		'archive_packet_invalid',
		'archive_verification_failed',
		'archive_immutable_conflict',
		'archive_build_attempts_exhausted',
	);

	/** @var GHCA_ACD_Archive_Event_Store */
	private $events;
	/** @var GHCA_ACD_WPDB_Archive_Snapshot_Store */
	private $snapshots;
	/** @var GHCA_ACD_WPDB_Archive_Artifact_Repository */
	private $artifacts;
	/** @var GHCA_ACD_Archive_Unit_Of_Work */
	private $uow;

	public function __construct(
		GHCA_ACD_Archive_Event_Store $events,
		GHCA_ACD_WPDB_Archive_Snapshot_Store $snapshots,
		GHCA_ACD_WPDB_Archive_Artifact_Repository $artifacts,
		GHCA_ACD_Archive_Unit_Of_Work $uow
	) {
		$this->events    = $events;
		$this->snapshots = $snapshots;
		$this->artifacts = $artifacts;
		$this->uow       = $uow;
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $prepared
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function record_ledger( array $task, array $prepared, string $outcome_key, array $fence ): array {
		$context = $this->context( $task );
		$this->assert_no_contradictory_outcome( $context['events'], $task );
		$descriptor = $prepared['artifact_descriptor'];
		$items      = $prepared['ledger_items'];
		$item_digests = array();
		foreach ( $items as $item ) {
			$item_digests[] = GHCA_ACD_Archive_Digester::item( $item );
		}
		$server = array(
			'build_attempt_id'   => $task['payload']['build_attempt_id'],
			'content_digest'     => $descriptor['content_digest'],
			'item_count'         => count( $items ),
			'ledger_artifact_id' => $task['payload']['ledger_artifact_id'],
			'manifest_digest'    => GHCA_ACD_Archive_Digester::ledger_manifest( $item_digests ),
			'snapshot_digest'    => (string) $context['snapshot']['snapshot_digest'],
			'snapshot_id'        => $task['payload']['snapshot_id'],
		);
		$command = GHCA_ACD_Archive_Command::record_materialized_artifact(
			$this->derived_id( 'RecordMaterializedArtifact', $task['task_id'] ),
			$context['scope_digest'],
			$outcome_key,
			$context['head_sequence'],
			$context['actor'],
			array( 'archive_id' => $task['payload']['archive_id'], 'artifact_kind' => 'ledger' ),
			$server
		);
		$response = $this->uow->execute( array(
			'command'              => $command,
			'case_key'             => $context['case_key'],
			'idempotency_scope'    => $context['scope'],
			'expected_head_digest' => $context['head_digest'],
			'correlation_id'       => $this->derived_id( 'RecordMaterializedArtifactCorrelation', $task['task_id'] ),
			'causation_event_id'   => $task['trigger_event_id'],
			'side_records'         => array( 'artifact' => $descriptor, 'ledger_items' => $items ),
			'task_fence'           => $fence,
		) );
		$this->assert_response( $response, 'RecordMaterializedArtifact', $task['payload']['stream_id'] );
		return $response;
	}

	/**
	 * Replay a matching authoritative decision, if one already exists.
	 *
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>|null
	 */
	public function recover_failure( array $task, string $outcome_key, array $fence ) {
		$context = $this->context( $task );
		$decision = $this->matching_decision( $context['events'], $task );
		if ( null === $decision ) {
			return null;
		}
		if ( 'materialized' === $decision['decision'] ) {
			return $decision;
		}
		return $this->fail_archive( $task, $decision['reason_code'], $outcome_key, $fence );
	}

	/**
	 * Submit/replay a closed failure after checking that materialization did not win.
	 *
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function fail_archive( array $task, string $failure_code, string $outcome_key, array $fence, bool $reject_matching_materialization = false ): array {
		if ( ! in_array( $failure_code, self::FAILURE_CODES, true ) ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$attempt = 0;
		while ( $attempt < 2 ) {
			$attempt++;
			$context = $this->context( $task );
			$decision = $this->matching_decision( $context['events'], $task );
			if ( null !== $decision && 'materialized' === $decision['decision'] && ! $reject_matching_materialization ) {
				return $decision;
			}
			if ( null !== $decision && 'failed' === $decision['decision'] ) {
				$failure_code = $decision['reason_code'];
			}
			$caller = array(
				'archive_id'             => $task['payload']['archive_id'],
				'build_attempt_id'       => $task['payload']['build_attempt_id'],
				'candidate_artifact_ids' => array(),
				'failure_code'           => $failure_code,
				'phase'                  => 'materializing',
				'retryable'              => false,
				'sealed_snapshot_id'     => $task['payload']['snapshot_id'],
			);
			$command = GHCA_ACD_Archive_Command::fail_archive(
				$this->derived_id( 'FailArchive', $task['task_id'] ),
				$context['failure_scope_digest'],
				$outcome_key,
				$context['head_sequence'],
				$context['actor'],
				$caller,
				array()
			);
			try {
				$response = $this->uow->execute( array(
					'command'              => $command,
					'case_key'             => $context['case_key'],
					'idempotency_scope'    => $context['failure_scope'],
					'expected_head_digest' => $context['head_digest'],
					'correlation_id'       => $this->derived_id( 'FailArchiveCorrelation', $task['task_id'] ),
					'causation_event_id'   => $task['trigger_event_id'],
					'task_fence'           => $fence,
				) );
				$this->assert_response( $response, 'FailArchive', $task['payload']['stream_id'] );
				return array( 'decision' => 'failed', 'reason_code' => $failure_code, 'response' => $response );
			} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
				if ( $attempt < 2 && GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT === $error->category()
					&& in_array( $error->reason_code(), array( 'expected_sequence_conflict', 'expected_head_digest_conflict' ), true ) ) {
					continue;
				}
				throw $error;
			}
		}
		throw $this->internal( 'task_outcome_commit_failed', 'The authoritative task outcome could not be committed.' );
	}

	/**
	 * @param array<int,GHCA_ACD_Archive_Event> $events
	 * @param array<string,mixed> $task
	 * @return array<string,mixed>|null
	 */
	private function matching_decision( array $events, array $task ) {
		$materialized = null;
		$failed       = null;
		foreach ( $events as $event ) {
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type()
				&& $payload['archive_id'] === $task['payload']['archive_id']
				&& $payload['build_attempt_id'] === $task['payload']['build_attempt_id']
				&& $payload['snapshot_id'] === $task['payload']['snapshot_id']
				&& $payload['ledger_artifact_id'] === $task['payload']['ledger_artifact_id'] ) {
				$materialized = $event;
			}
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type()
				&& $payload['archive_id'] === $task['payload']['archive_id']
				&& $payload['build_attempt_id'] === $task['payload']['build_attempt_id']
				&& $payload['sealed_snapshot_id'] === $task['payload']['snapshot_id'] ) {
				$failed = $event;
			}
		}
		if ( null !== $materialized && null !== $failed ) {
			throw $this->integrity( 'task_outcome_commit_failed', 'The authoritative task outcome could not be committed.' );
		}
		if ( null !== $materialized ) {
			return array( 'decision' => 'materialized' );
		}
		if ( null !== $failed ) {
			return array( 'decision' => 'failed', 'reason_code' => $failed->payload()['failure_code'] );
		}
		return null;
	}

	/** @param array<string,mixed> $events @param array<string,mixed> $task */
	private function assert_no_contradictory_outcome( array $events, array $task ): void {
		foreach ( $events as $event ) {
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type()
				&& $payload['archive_id'] === $task['payload']['archive_id']
				&& $payload['snapshot_id'] === $task['payload']['snapshot_id']
				&& $payload['build_attempt_id'] === $task['payload']['build_attempt_id']
				&& $payload['ledger_artifact_id'] !== $task['payload']['ledger_artifact_id'] ) {
				throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
			}
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type()
				&& $payload['archive_id'] === $task['payload']['archive_id']
				&& $payload['build_attempt_id'] === $task['payload']['build_attempt_id'] ) {
				throw $this->integrity( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
			}
		}
	}

	/** @param array<string,mixed> $task @return array<string,mixed> */
	private function context( array $task ): array {
		$payload = isset( $task['payload'] ) && is_array( $task['payload'] ) ? $task['payload'] : array();
		$payload = GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $task, $payload );
		$snapshot = $this->snapshots->find( $payload['snapshot_id'] );
		if ( null === $snapshot ) {
			throw $this->invalid( 'archive_snapshot_invalid', 'The archive snapshot is invalid.' );
		}
		$document = $snapshot['snapshot_document'];
		if ( (string) $snapshot['stream_id'] !== $payload['stream_id']
			|| (string) $snapshot['archive_id'] !== $payload['archive_id']
			|| (string) $snapshot['source_event_id'] !== $payload['trigger_event_id'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$cycle_document = $document['cycle'];
		$cycle = new GHCA_ACD_Archive_Cycle(
			$cycle_document['policy_key'],
			$cycle_document['policy_version'],
			$cycle_document['start_gmt'],
			$cycle_document['end_gmt'],
			$cycle_document['timezone'],
			$cycle_document['display_label']
		);
		$case_key = new GHCA_ACD_Archive_Case_Key(
			$document['case']['tenant_id'],
			$document['case']['site_id'],
			$document['case']['employee_user_id'],
			$document['case']['program_key'],
			$cycle
		);
		$events = $this->events->load_events( $payload['stream_id'] );
		if ( array() === $events ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
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
			}
		}
		if ( null === $trigger || GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED !== $trigger->type() ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$trigger_payload = $trigger->payload();
		if ( $attempt !== $payload['build_attempt_id']
			|| $trigger->stream_id() !== $payload['stream_id']
			|| $trigger_payload['archive_id'] !== $payload['archive_id']
			|| $trigger_payload['snapshot_id'] !== $payload['snapshot_id']
			|| $trigger_payload['snapshot_digest'] !== (string) $snapshot['snapshot_digest']
			|| $document['case']['archive_id'] !== $payload['archive_id']
			|| $document['case']['snapshot_id'] !== $payload['snapshot_id']
			|| $document['case']['stream_id'] !== $payload['stream_id'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$head = $events[ count( $events ) - 1 ];
		$actor = new GHCA_ACD_Archive_Actor( 'worker', null, null, 'worker', 'archive_worker', array(
			'delegated_by_user_id' => null,
			'delegation_kind'      => 'system',
			'subject_scope_digest' => $document['review']['subject_scope_digest'],
		) );
		$scope = $this->scope( $case_key, $document['case'], 'RecordMaterializedArtifact' );
		$failure_scope = $this->scope( $case_key, $document['case'], 'FailArchive' );
		return array(
			'actor'                => $actor,
			'case_key'             => $case_key,
			'events'               => $events,
			'failure_scope'        => $failure_scope,
			'failure_scope_digest' => GHCA_ACD_Archive_Digester::idempotency_scope( $failure_scope ),
			'head_digest'          => $head->event_digest(),
			'head_sequence'        => $head->stream_sequence(),
			'scope'                => $scope,
			'scope_digest'         => GHCA_ACD_Archive_Digester::idempotency_scope( $scope ),
			'snapshot'             => $snapshot,
		);
	}

	/** @param array<string,mixed> $case @return array<string,mixed> */
	private function scope( GHCA_ACD_Archive_Case_Key $case_key, array $case, string $command_type ): array {
		return array(
			'actor_or_integration_namespace'  => 'worker:archive_worker',
			'case_key_digest_or_global_scope' => $case_key->digest(),
			'command_type'                    => $command_type,
			'site_id'                         => $case['site_id'],
			'tenant_id'                       => $case['tenant_id'],
		);
	}

	/** @param array<string,mixed> $response */
	private function assert_response( array $response, string $command_type, string $stream_id ): void {
		if ( ! isset( $response['result_code'], $response['command_type'], $response['stream_id'] )
			|| 'committed' !== $response['result_code']
			|| $command_type !== $response['command_type']
			|| $stream_id !== $response['stream_id'] ) {
			throw $this->integrity( 'task_outcome_commit_failed', 'The authoritative task outcome could not be committed.' );
		}
	}

	private function derived_id( string $purpose, string $task_id ): string {
		return substr( hash( 'sha256', 'ghca-p3b1-command-id-v1|' . $purpose . '|' . $task_id ), 0, 32 );
	}

	private function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, $reason, $message );
	}

	private function integrity( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}

	private function internal( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, $reason, $message );
	}
}
