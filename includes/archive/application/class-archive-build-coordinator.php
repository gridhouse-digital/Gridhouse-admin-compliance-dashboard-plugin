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
	 * Commit or replay the deterministic initial capturing attempt.
	 *
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function start_capture( array $task, string $outcome_key, array $fence ): array {
		$context = $this->capture_context( $task );
		if ( $context['build_started'] ) {
			return $context;
		}
		$scope = $this->capture_scope( $context, 'StartBuild' );
		$command = GHCA_ACD_Archive_Command::start_build(
			$this->capture_derived_id( 'command:StartBuild', $task['task_id'] ),
			GHCA_ACD_Archive_Digester::idempotency_scope( $scope ),
			$outcome_key,
			$context['head_sequence'],
			$context['actor'],
			array( 'archive_id' => $context['archive_id'] ),
			array(
				'build_attempt_id' => $context['build_attempt_id'],
				'retry_ordinal' => 0,
				'snapshot_id' => null,
				'start_phase' => 'capturing',
			)
		);
		$response = $this->uow->execute( array(
			'command' => $command,
			'case_key' => $context['case_key'],
			'idempotency_scope' => $scope,
			'expected_head_digest' => $context['head_digest'],
			'correlation_id' => $this->capture_derived_id( 'correlation:StartBuild', $task['task_id'] ),
			'causation_event_id' => $task['trigger_event_id'],
			'task_fence' => $fence,
		) );
		$this->assert_response( $response, 'StartBuild', $context['stream_id'] );
		$context = $this->capture_context( $task );
		if ( ! $context['build_started'] ) {
			throw $this->integrity( 'task_outcome_commit_failed', 'The authoritative task outcome could not be committed.' );
		}
		return $context;
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $prepared
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function record_capture( array $task, array $prepared, string $outcome_key, array $fence ): array {
		$context = $this->capture_context( $task );
		if ( ! $context['build_started'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$document = $prepared['snapshot_document'];
		$existing = $this->snapshots->find( $context['snapshot_id'] );
		if ( null !== $existing
			&& ( (string) $existing['snapshot_digest'] !== $prepared['snapshot_digest']
				|| (int) $existing['byte_count'] !== $prepared['byte_count']
				|| GHCA_ACD_Archive_Canonical_JSON::encode( $existing['snapshot_document'] )
					!== GHCA_ACD_Archive_Canonical_JSON::encode( $document ) ) ) {
			throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
		}
		if ( $document['case']['snapshot_id'] !== $context['snapshot_id']
			|| $document['case']['archive_id'] !== $context['archive_id']
			|| $document['case']['stream_id'] !== $context['stream_id'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$scope = $this->capture_scope( $context, 'RecordEvidenceSnapshot' );
		$command = GHCA_ACD_Archive_Command::record_evidence_snapshot(
			$this->capture_derived_id( 'command:RecordEvidenceSnapshot', $task['task_id'] ),
			GHCA_ACD_Archive_Digester::idempotency_scope( $scope ),
			$outcome_key,
			$context['head_sequence'],
			$context['actor'],
			array( 'archive_id' => $context['archive_id'] ),
			array(
				'byte_count' => $prepared['byte_count'],
				'captured_source_fingerprint' => $prepared['captured_source_fingerprint'],
				'certificate_asset_ids' => array(),
				'certificate_content_digests' => array(),
				'completeness_policy' => $document['policy']['completeness_policy'],
				'policy_digest' => $context['capture_identity']['policy_digest'],
				'resolved_cycle' => $context['capture_identity']['resolved_cycle'],
				'reviewed_source_fingerprint' => $context['capture_identity']['reviewed_source_fingerprint'],
				'revision_number' => $context['revision_number'],
				'snapshot_digest' => $prepared['snapshot_digest'],
				'snapshot_id' => $context['snapshot_id'],
				'snapshot_schema_version' => 1,
				'subject_scope_digest' => $context['capture_identity']['subject_scope_digest'],
			)
		);
		$response = $this->uow->execute( array(
			'command' => $command,
			'case_key' => $context['case_key'],
			'idempotency_scope' => $scope,
			'expected_head_digest' => $context['head_digest'],
			'correlation_id' => $this->capture_derived_id( 'correlation:RecordEvidenceSnapshot', $task['task_id'] ),
			'causation_event_id' => $task['trigger_event_id'],
			'side_records' => array( 'artifacts' => array(), 'snapshot' => array( 'snapshot_document' => $document ) ),
			'task_fence' => $fence,
		) );
		$this->assert_response( $response, 'RecordEvidenceSnapshot', $context['stream_id'] );
		return $response;
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function detect_capture_drift( array $task, string $observed_fingerprint, string $outcome_key, array $fence ): array {
		$context = $this->capture_context( $task );
		$scope = $this->capture_scope( $context, 'DetectSourceDrift' );
		$failure = array(
			'archive_id' => $context['archive_id'],
			'build_attempt_id' => $context['build_attempt_id'],
			'candidate_artifact_ids' => array(),
			'failure_code' => 'archive_source_drift',
			'phase' => 'capturing',
			'retryable' => false,
			'sealed_snapshot_id' => null,
		);
		$command = GHCA_ACD_Archive_Command::detect_source_drift(
			$this->capture_derived_id( 'command:DetectSourceDrift', $task['task_id'] ),
			GHCA_ACD_Archive_Digester::idempotency_scope( $scope ),
			$outcome_key,
			$context['head_sequence'],
			$context['actor'],
			array(
				'archive_id' => $context['archive_id'],
				'changed_component_codes' => array( 'source_fingerprint' ),
				'detection_point' => 'pre_capture',
				'observed_source_fingerprint' => $observed_fingerprint,
			),
			array(
				'expected_source_fingerprint' => $context['capture_identity']['reviewed_source_fingerprint'],
				'failure' => $failure,
				'incident_id' => $this->capture_derived_id( 'DriftIncident', $task['task_id'] ),
				'invalidations' => array(),
				'snapshot_id' => null,
			)
		);
		$response = $this->uow->execute( array(
			'command' => $command,
			'case_key' => $context['case_key'],
			'idempotency_scope' => $scope,
			'expected_head_digest' => $context['head_digest'],
			'correlation_id' => $this->capture_derived_id( 'correlation:DetectSourceDrift', $task['task_id'] ),
			'causation_event_id' => $task['trigger_event_id'],
			'task_fence' => $fence,
		) );
		$this->assert_response( $response, 'DetectSourceDrift', $context['stream_id'] );
		return array( 'decision' => 'failed', 'reason_code' => 'archive_source_drift', 'response' => $response );
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	public function fail_capture( array $task, string $failure_code, string $outcome_key, array $fence ): array {
		if ( ! in_array( $failure_code, self::FAILURE_CODES, true ) ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$context = $this->capture_context( $task );
		$scope = $this->capture_scope( $context, 'FailArchive' );
		$command = GHCA_ACD_Archive_Command::fail_archive(
			$this->capture_derived_id( 'command:FailArchive', $task['task_id'] ),
			GHCA_ACD_Archive_Digester::idempotency_scope( $scope ),
			$outcome_key,
			$context['head_sequence'],
			$context['actor'],
			array(
				'archive_id' => $context['archive_id'],
				'build_attempt_id' => $context['build_started'] ? $context['build_attempt_id'] : null,
				'candidate_artifact_ids' => array(),
				'failure_code' => $failure_code,
				'phase' => $context['build_started'] ? 'capturing' : 'requested',
				'retryable' => false,
				'sealed_snapshot_id' => null,
			),
			array()
		);
		$response = $this->uow->execute( array(
			'command' => $command,
			'case_key' => $context['case_key'],
			'idempotency_scope' => $scope,
			'expected_head_digest' => $context['head_digest'],
			'correlation_id' => $this->capture_derived_id( 'correlation:FailArchive', $task['task_id'] ),
			'causation_event_id' => $task['trigger_event_id'],
			'task_fence' => $fence,
		) );
		$this->assert_response( $response, 'FailArchive', $context['stream_id'] );
		return array( 'decision' => 'failed', 'reason_code' => $failure_code, 'response' => $response );
	}

	/** @param array<string,mixed> $task @return array<string,mixed>|null */
	public function recover_capture( array $task ) {
		$context = $this->capture_context( $task );
		$decision = $this->matching_capture_decision( $context, $task['task_id'] );
		if ( null === $decision || 'captured' !== $decision['decision'] ) {
			return $decision;
		}
		$snapshot = $this->snapshots->find( $context['snapshot_id'] );
		if ( null === $snapshot || (string) $snapshot['stream_id'] !== $context['stream_id']
			|| (string) $snapshot['archive_id'] !== $context['archive_id']
			|| (string) $snapshot['source_event_id'] !== $decision['event']->event_id() ) {
			throw $this->integrity( 'archive_immutable_conflict', 'The retained archive evidence conflicts with the requested outcome.' );
		}
		return array(
			'decision' => 'captured',
			'prepared' => array(
				'byte_count' => (int) $snapshot['byte_count'],
				'captured_source_fingerprint' => (string) $snapshot['captured_source_fingerprint'],
				'decision' => 'snapshot',
				'snapshot_digest' => (string) $snapshot['snapshot_digest'],
				'snapshot_document' => $snapshot['snapshot_document'],
			),
		);
	}

	/** @param array<string,mixed> $task @return array<string,mixed> */
	public function capture_context( array $task ): array {
		$payload = isset( $task['payload'] ) && is_array( $task['payload'] ) ? $task['payload'] : array();
		$payload = GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $task, $payload );
		$events = $this->events->load_events( $payload['stream_id'] );
		$request = null;
		$build_started = false;
		foreach ( $events as $event ) {
			$event_payload = $event->payload();
			if ( $event->event_id() === $payload['trigger_event_id'] ) {
				$request = $event;
			}
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED === $event->type()
				&& $event_payload['archive_id'] === $payload['archive_id']
				&& $event_payload['build_attempt_id'] === $this->capture_derived_id( 'BuildAttempt', $task['task_id'] )
				&& 'capturing' === $event_payload['start_phase'] ) {
				$build_started = true;
			}
		}
		if ( null === $request || ! in_array( $request->type(), array(
			GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED,
			GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED,
		), true ) || $request->stream_id() !== $payload['stream_id'] ) {
			throw $this->invalid( 'task_payload_invalid', 'The retained task payload is invalid.' );
		}
		$request_payload = $request->payload();
		if ( $request_payload['archive_id'] !== $payload['archive_id'] ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$case_document = $request_payload['case_key'];
		$cycle_document = $request_payload['resolved_cycle'];
		$cycle = new GHCA_ACD_Archive_Cycle(
			$cycle_document['policy_key'], $cycle_document['policy_version'], $cycle_document['start_gmt'],
			$cycle_document['end_gmt'], $cycle_document['timezone'], $cycle_document['display_label']
		);
		$case_key = new GHCA_ACD_Archive_Case_Key(
			$case_document['tenant_id'], $case_document['site_id_decimal'], $case_document['employee_user_id_decimal'],
			$case_document['program_key'], $cycle
		);
		$envelope = $request->recorded_document();
		if ( $case_key->digest() !== $envelope['case_key_digest'] || array() === $events ) {
			throw $this->invalid( 'archive_build_binding_invalid', 'The archive build bindings are invalid.' );
		}
		$head = $events[ count( $events ) - 1 ];
		$actor = new GHCA_ACD_Archive_Actor( 'worker', null, null, 'worker', 'archive_worker', array(
			'delegated_by_user_id' => null,
			'delegation_kind' => 'system',
			'subject_scope_digest' => $request_payload['subject_scope_digest'],
		) );
		$identity = array(
			'archive_id' => $payload['archive_id'],
			'case_key' => $case_document,
			'policy_digest' => $request_payload['policy_digest'],
			'resolved_cycle' => $cycle_document,
			'reviewed_source_fingerprint' => $request_payload['reviewed_source_fingerprint'],
			'revision_number' => $request_payload['revision_number'],
			'stream_id' => $payload['stream_id'],
			'subject_scope_digest' => $request_payload['subject_scope_digest'],
			'trigger_event_id' => $payload['trigger_event_id'],
		);
		return array(
			'actor' => $actor,
			'archive_id' => $payload['archive_id'],
			'build_attempt_id' => $this->capture_derived_id( 'BuildAttempt', $task['task_id'] ),
			'build_started' => $build_started,
			'capture_identity' => $identity,
			'case_key' => $case_key,
			'events' => $events,
			'head_digest' => $head->event_digest(),
			'head_sequence' => $head->stream_sequence(),
			'request_event' => $request,
			'revision_number' => $request_payload['revision_number'],
			'snapshot_id' => $this->capture_derived_id( 'Snapshot', $task['task_id'] ),
			'stream_id' => $payload['stream_id'],
		);
	}

	/** @param array<string,mixed> $context @return array<string,mixed>|null */
	private function matching_capture_decision( array $context, string $task_id ) {
		$captured = null;
		$failed = null;
		$failure_commands = array(
			$this->capture_derived_id( 'command:DetectSourceDrift', $task_id ),
			$this->capture_derived_id( 'command:FailArchive', $task_id ),
		);
		foreach ( $context['events'] as $event ) {
			$payload = $event->payload();
			if ( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED === $event->type()
				&& $payload['archive_id'] === $context['archive_id'] && $payload['snapshot_id'] === $context['snapshot_id'] ) {
				$captured = $event;
			}
			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type()
				&& $payload['archive_id'] === $context['archive_id']
				&& $payload['build_attempt_id'] === ( $context['build_started'] ? $context['build_attempt_id'] : null )
				&& in_array( $event->recorded_document()['command_id'], $failure_commands, true ) ) {
				$failed = $event;
			}
		}
		if ( null !== $captured ) { return array( 'decision' => 'captured', 'event' => $captured ); }
		if ( null !== $failed ) { return array( 'decision' => 'failed', 'reason_code' => $failed->payload()['failure_code'] ); }
		return null;
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private function capture_scope( array $context, string $command_type ): array {
		$case = $context['case_key']->canonical();
		return array(
			'actor_or_integration_namespace' => 'worker:archive_worker',
			'case_key_digest_or_global_scope' => $context['case_key']->digest(),
			'command_type' => $command_type,
			'site_id' => $case['site_id_decimal'],
			'tenant_id' => $case['tenant_id'],
		);
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

	private function capture_derived_id( string $purpose, string $task_id ): string {
		$purposes = array(
			'command:StartBuild', 'command:RecordEvidenceSnapshot', 'command:DetectSourceDrift', 'command:FailArchive',
			'correlation:StartBuild', 'correlation:RecordEvidenceSnapshot', 'correlation:DetectSourceDrift', 'correlation:FailArchive',
			'BuildAttempt', 'Snapshot', 'DriftIncident',
		);
		if ( ! in_array( $purpose, $purposes, true ) ) {
			throw new LogicException( 'Capture identity purpose is not installed.' );
		}
		return substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|' . $purpose . '|' . $task_id ), 0, 32 );
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
