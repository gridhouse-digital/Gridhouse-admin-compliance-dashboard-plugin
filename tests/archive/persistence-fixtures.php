<?php
/**
 * Persistence-test scenarios. Each scenario is one Archive Case (unique
 * program key) with internally consistent identifiers, scope digests, and
 * actor authority, driven through the scoped Unit of Work or the explicit
 * later-slice component harness with the kernel's closed command dispatcher.
 */

final class GHCA_Persist_Scenario {
	/** @var string */
	public $program;
	/** @var GHCA_ACD_Archive_Case_Key */
	public $case_key;
	/** @var array<string,string> */
	public $case_canonical;
	/** @var array<string,mixed> */
	public $scope;
	/** @var string */
	public $scope_digest;
	/** @var GHCA_ACD_Archive_Actor */
	public $actor;
	/** @var string */
	public $head_sequence = '0';
	/** @var string|null */
	public $head_digest = null;
	/** @var string|null */
	public $stream_id = null;
	/** @var int */
	private $command_counter = 0;

	public function __construct( string $program ) {
		$cycle_data     = remediation_cycle();
		$cycle          = new GHCA_ACD_Archive_Cycle( $cycle_data['policy_key'], $cycle_data['policy_version'], $cycle_data['start_gmt'], $cycle_data['end_gmt'], $cycle_data['timezone'], $cycle_data['display_label'] );
		$this->case_key = new GHCA_ACD_Archive_Case_Key( '0123456789abcdef0123456789abcdef', '1', '42', $program, $cycle );
		$this->case_canonical = $this->case_key->canonical();
		$scope                = remediation_scope();
		$scope['program_key'] = $program;
		$this->scope          = $scope;
		$this->scope_digest   = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $scope );
		$this->actor          = new GHCA_ACD_Archive_Actor( 'wp_user', '7', null, 'ajax', 'ghca_archive_admin', array(
			'delegated_by_user_id' => null,
			'delegation_kind'      => 'none',
			'subject_scope_digest' => $this->scope_digest,
		) );
		$this->program = $program;
	}

	/** Deterministic scenario-unique 32-hex identifier. */
	public function id( string $slot ): string {
		return substr( hash( 'sha256', 'persist-scn|' . $this->program . '|' . $slot ), 0, 32 );
	}

	/** @param array<string,mixed> $overrides @return array<string,mixed> */
	public function payload( string $event_type, array $overrides = array() ): array {
		$payload = remediation_payload( $event_type );
		if ( array_key_exists( 'case_key', $payload ) ) {
			$payload['case_key'] = $this->case_canonical;
		}
		if ( array_key_exists( 'scope', $payload ) ) {
			$payload['scope'] = $this->scope;
		}
		foreach ( array( 'scope_digest', 'subject_scope_digest', 'affected_scope_digest' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$payload[ $field ] = $this->scope_digest;
			}
		}
		// The (gateway_key, upstream_operation_id) pair is globally unique in
		// the reset projection; scope it per scenario.
		if ( array_key_exists( 'upstream_operation_id', $payload ) ) {
			$payload['upstream_operation_id'] = 'ld-reset:' . $this->program . '/op-0001';
		}
		foreach ( $overrides as $field => $value ) {
			$payload[ $field ] = $value;
		}
		return $payload;
	}

	public function next_idempotency_key(): string {
		$this->command_counter++;
		return 'op-' . $this->program . '-' . $this->command_counter;
	}

	/** @param array<string,mixed> $response */
	public function record_response( array $response ): void {
		$this->head_sequence = (string) $response['last_stream_sequence'];
		$this->head_digest   = (string) $response['head_event_digest'];
		$this->stream_id     = (string) $response['stream_id'];
	}
}

function ghca_persist_snake( string $command_type ): string {
	return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $command_type ) );
}

/** @return array<string,mixed> */
function ghca_persist_scope_document( GHCA_Persist_Scenario $s, string $command_type ): array {
	return array(
		'actor_or_integration_namespace'  => 'wp_user:7',
		'case_key_digest_or_global_scope' => $s->case_key->digest(),
		'command_type'                    => $command_type,
		'site_id'                         => $s->case_canonical['site_id_decimal'],
		'tenant_id'                       => $s->case_canonical['tenant_id'],
	);
}

/**
 * Build one command and execute it through the unit of work.
 *
 * @param array<string,mixed> $stack
 * @param array<string,mixed> $caller
 * @param array<string,mixed> $server
 * @param array<string,mixed> $opts
 * @return array<string,mixed>
 */
function ghca_persist_execute( array $stack, GHCA_Persist_Scenario $s, string $command_type, array $caller, array $server, callable $decision, array $opts = array() ) {
	$idempotency_key = isset( $opts['idempotency_key'] ) ? $opts['idempotency_key'] : $s->next_idempotency_key();
	$scope_document  = ghca_persist_scope_document( $s, $command_type );
	$scope_digest    = GHCA_ACD_Archive_Digester::idempotency_scope( $scope_document );
	$key_digest      = hash( 'sha256', $idempotency_key );
	$command_id      = isset( $opts['command_id'] ) ? $opts['command_id'] : $s->id( 'cmd-' . $idempotency_key );
	$expected        = array_key_exists( 'expected_sequence', $opts ) ? $opts['expected_sequence'] : $s->head_sequence;
	$command         = call_user_func(
		array( 'GHCA_ACD_Archive_Command', ghca_persist_snake( $command_type ) ),
		$command_id,
		$scope_digest,
		$key_digest,
		$expected,
		isset( $opts['actor'] ) ? $opts['actor'] : $s->actor,
		$caller,
		$server
	);
	if ( ! empty( $opts['component_harness'] ) ) {
		$response = ghca_persist_apply_component_transaction( $stack, $s, $command, $opts );
		if ( empty( $opts['no_track'] ) ) {
			$s->record_response( $response );
		}
		return $response;
	}
	$request = array(
		'command'              => $command,
		'case_key'             => $s->case_key,
		'idempotency_scope'    => $scope_document,
		'expected_head_digest' => array_key_exists( 'expected_head_digest', $opts ) ? $opts['expected_head_digest'] : $s->head_digest,
		'correlation_id'       => $s->id( 'corr-' . $idempotency_key ),
	);
	foreach ( array( 'causation_event_id', 'effective_at_gmt', 'reason_code', 'reason_text' ) as $optional ) {
		if ( array_key_exists( $optional, $opts ) ) {
			$request[ $optional ] = $opts[ $optional ];
		}
	}
	$response = $stack['uow']->execute( $request );
	if ( empty( $opts['no_track'] ) ) {
		$s->record_response( $response );
	}
	return $response;
}

/**
 * Test-only event-store/projector transaction for later-slice commands whose
 * production Unit of Work correctly refuses to append without side records.
 * It exists only to exercise the already-delegated generic store/projectors;
 * it creates no receipt or task and is never loaded by production runtime.
 *
 * @param array<string,mixed> $stack
 * @param array<string,mixed> $opts
 * @return array<string,mixed>
 */
function ghca_persist_apply_component_transaction( array $stack, GHCA_Persist_Scenario $s, GHCA_ACD_Archive_Command $command, array $opts = array() ) {
	$db = $stack['db'];
	if ( false === $db->query( 'START TRANSACTION' ) || '' !== (string) $db->last_error ) {
		throw new RuntimeException( 'component transaction could not start' );
	}
	try {
		$stream = $stack['event_store']->find_stream_for_update( $s->case_key->digest() );
		if ( null === $stream ) {
			throw new RuntimeException( 'component transaction requires an existing stream' );
		}
		$prior = $stack['event_store']->load_events( (string) $stream['stream_id'] );
		$case  = GHCA_ACD_Archive_Case::rehydrate( $prior );
		$events = $command->decide( $case );
		$document = $command->canonical();
		$actor = $document['actor'];
		$sequence = (string) $stream['head_sequence'];
		$chain = null === $stream['head_event_digest'] ? null : (string) $stream['head_event_digest'];
		$recorded = array();
		$now = $stack['clock']->now_gmt();
		$correlation_id = $s->id( 'component-correlation-' . $document['command_id'] );
		foreach ( $events as $event ) {
			$sequence = GHCA_ACD_Archive_Db_Format::increment_sequence( $sequence );
			$payload = $event->payload();
			$archive_id = null;
			foreach ( array( 'archive_id', 'target_archive_id', 'bound_archive_id' ) as $field ) {
				if ( array_key_exists( $field, $payload ) ) {
					$archive_id = $payload[ $field ];
					break;
				}
			}
			$build_attempt_id = GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED === $event->type()
				? $payload['new_build_attempt_id']
				: ( array_key_exists( 'build_attempt_id', $payload ) ? $payload['build_attempt_id'] : null );
			$recorded_event = $event->with_recording_context( array(
				'canonical_format_version' => 1,
				'event_id'                 => $stack['ids']->generate(),
				'stream_id'                => (string) $stream['stream_id'],
				'case_key_digest'          => (string) $stream['case_key_digest'],
				'case_key_format_version'  => 1,
				'stream_sequence'          => $sequence,
				'archive_id'               => $archive_id,
				'build_attempt_id'         => $build_attempt_id,
				'reset_operation_id'       => array_key_exists( 'reset_operation_id', $payload ) ? $payload['reset_operation_id'] : null,
				'actor_kind'               => $actor['actor_kind'],
				'actor_user_id'            => $actor['actor_user_id'],
				'initiating_user_id'       => $actor['initiating_user_id'],
				'source_channel'           => $actor['source_channel'],
				'authority_code'           => $actor['authority_code'],
				'authority_context'        => $actor['authority_context'],
				'occurred_at_gmt'          => $now,
				'effective_at_gmt'         => null,
				'correlation_id'           => $correlation_id,
				'causation_event_id'       => null,
				'command_id'               => $document['command_id'],
				'upstream_operation_id'    => array_key_exists( 'upstream_operation_id', $payload ) ? $payload['upstream_operation_id'] : null,
				'idempotency_scope_digest' => $document['idempotency_scope_digest'],
				'idempotency_key_digest'   => $document['idempotency_key_digest'],
				'command_digest'           => $command->digest(),
				'reason_code'              => null,
				'reason_text'              => null,
				'previous_event_digest'    => $chain,
				'recorded_at_gmt'          => $now,
			) );
			$recorded[] = $recorded_event;
			$chain = $recorded_event->event_digest();
		}
		$stack['event_store']->append_events( $recorded );
		$stack['projector']->apply_new_events( $stream, $prior, $recorded, $now );
		$last = $recorded[ count( $recorded ) - 1 ];
		$stack['event_store']->advance_stream_head(
			(string) $stream['stream_id'],
			(string) $stream['head_sequence'],
			null === $stream['head_event_digest'] ? null : (string) $stream['head_event_digest'],
			$last->stream_sequence(),
			$last->event_digest(),
			$now
		);
		if ( false === $db->query( 'COMMIT' ) || '' !== (string) $db->last_error ) {
			throw new RuntimeException( 'component transaction commit failed' );
		}
		$first = $recorded[0];
		return array(
			'case_key_digest'         => (string) $stream['case_key_digest'],
			'command_id'              => $document['command_id'],
			'command_type'            => $command->type(),
			'first_event_id'          => $first->event_id(),
			'first_stream_sequence'   => $first->stream_sequence(),
			'head_event_digest'       => $last->event_digest(),
			'last_event_id'           => $last->event_id(),
			'last_stream_sequence'    => $last->stream_sequence(),
			'response_schema_version' => 1,
			'result_code'             => 'component_committed',
			'stream_id'               => (string) $stream['stream_id'],
		);
	} catch ( Throwable $error ) {
		$db->query( 'ROLLBACK' );
		throw $error;
	}
}

/**
 * Split one event payload into caller intent and server facts using the
 * command's closed caller contract.
 *
 * @param array<string,mixed> $payload
 * @return array{0:array<string,mixed>,1:array<string,mixed>}
 */
function ghca_persist_split( string $command_type, array $payload ) {
	$caller_fields = GHCA_ACD_Archive_Command::caller_contract( $command_type );
	$caller        = array();
	$server        = array();
	foreach ( $payload as $field => $value ) {
		if ( 'payload_schema_version' === $field ) {
			continue;
		}
		if ( in_array( $field, $caller_fields, true ) ) {
			$caller[ $field ] = $value;
		} else {
			$server[ $field ] = $value;
		}
	}
	return array( $caller, $server );
}

/**
 * Generic single-event command helper for the many commands whose payload is
 * exactly caller intent plus server facts.
 *
 * @param array<string,mixed> $stack
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $opts
 * @return array<string,mixed>
 */
function persist_single( array $stack, GHCA_Persist_Scenario $s, string $command_type, string $operation, array $payload, array $opts = array() ) {
	list( $caller, $server ) = ghca_persist_split( $command_type, $payload );
	return ghca_persist_execute( $stack, $s, $command_type, $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $operation, $payload ) {
		return $case->{$operation}( $payload );
	}, $opts );
}

/** @param array<string,mixed> $stack @param array<string,mixed> $opts @return array<string,mixed> */
function persist_request_archive( array $stack, GHCA_Persist_Scenario $s, array $opts = array() ) {
	$payload = $s->payload( 'ArchiveRequested', array( 'archive_id' => $s->id( 'archive-1' ) ) );
	return persist_single( $stack, $s, 'RequestArchive', 'request_archive', $payload, $opts );
}

/** @param array<string,mixed> $stack @param array<string,mixed> $overrides @return array<string,mixed> */
function persist_start_build( array $stack, GHCA_Persist_Scenario $s, array $overrides = array(), array $opts = array() ) {
	$payload = $s->payload( 'ArchiveBuildStarted', array_merge( array(
		'archive_id'       => $s->id( 'archive-1' ),
		'build_attempt_id' => $s->id( 'attempt-1' ),
	), $overrides ) );
	return persist_single( $stack, $s, 'StartBuild', 'start_build', $payload, $opts );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_record_snapshot( array $stack, GHCA_Persist_Scenario $s, array $overrides = array() ) {
	$payload = $s->payload( 'EvidenceSnapshotCaptured', array_merge( array(
		'archive_id'  => $s->id( 'archive-1' ),
		'snapshot_id' => $s->id( 'snapshot-1' ),
		'certificate_asset_ids'       => array( $s->id( 'cert-1' ), $s->id( 'cert-2' ) ),
	), $overrides ) );
	return persist_single( $stack, $s, 'RecordEvidenceSnapshot', 'capture_evidence_snapshot', $payload, array( 'component_harness' => true ) );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_materialize( array $stack, GHCA_Persist_Scenario $s, string $kind, array $overrides = array() ) {
	if ( 'ledger' === $kind ) {
		$payload = $s->payload( 'LedgerMaterialized', array_merge( array(
			'archive_id'         => $s->id( 'archive-1' ),
			'snapshot_id'        => $s->id( 'snapshot-1' ),
			'build_attempt_id'   => $s->id( 'attempt-1' ),
			'ledger_artifact_id' => $s->id( 'ledger-1' ),
		), $overrides ) );
		$operation = 'materialize_ledger';
	} else {
		$payload = $s->payload( 'PacketMaterialized', array_merge( array(
			'archive_id'         => $s->id( 'archive-1' ),
			'snapshot_id'        => $s->id( 'snapshot-1' ),
			'build_attempt_id'   => $s->id( 'attempt-1' ),
			'packet_artifact_id' => $s->id( 'packet-1' ),
		), $overrides ) );
		$operation = 'materialize_packet';
	}
	$caller = array( 'archive_id' => $payload['archive_id'], 'artifact_kind' => $kind );
	$server = $payload;
	unset( $server['payload_schema_version'], $server['archive_id'] );
	return ghca_persist_execute( $stack, $s, 'RecordMaterializedArtifact', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $operation, $payload ) {
		return $case->{$operation}( $payload );
	}, array( 'component_harness' => true ) );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_verify_finalize( array $stack, GHCA_Persist_Scenario $s, ?string $predecessor = null, string $slot = '1' ) {
	$binding = array(
		'archive_id'                      => $s->id( 'archive-' . $slot ),
		'snapshot_id'                     => $s->id( 'snapshot-' . $slot ),
		'ledger_artifact_id'              => $s->id( 'ledger-' . $slot ),
		'packet_artifact_id'              => $s->id( 'packet-' . $slot ),
		'expected_predecessor_archive_id' => $predecessor,
		'active_identity_digest'          => $s->case_key->digest(),
	);
	$verified  = $s->payload( 'ArchiveVerified', $binding );
	$finalized = $s->payload( 'ArchiveFinalized', $binding );
	if ( '1' !== $slot ) {
		$verified['revision_number']  = 2;
		$finalized['revision_number'] = 2;
	}
	$caller = array( 'archive_id' => $binding['archive_id'] );
	$server = array( 'verified' => $verified, 'finalized' => $finalized );
	return ghca_persist_execute( $stack, $s, 'VerifyAndFinalize', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $verified, $finalized ) {
		return $case->verify_and_finalize( $verified, $finalized );
	}, array( 'component_harness' => true ) );
}

/**
 * Complete request-to-finalization flow for one scenario.
 *
 * @param array<string,mixed> $stack
 */
function persist_build_finalized( array $stack, GHCA_Persist_Scenario $s ): void {
	persist_request_archive( $stack, $s );
	persist_start_build( $stack, $s );
	persist_record_snapshot( $stack, $s );
	persist_materialize( $stack, $s, 'ledger' );
	persist_materialize( $stack, $s, 'packet' );
	persist_verify_finalize( $stack, $s );
}

/** @param array<string,mixed> $stack @param array<string,mixed> $overrides @return array<string,mixed> */
function persist_request_reset( array $stack, GHCA_Persist_Scenario $s, string $slot = 'reset-1', array $overrides = array() ) {
	$payload = $s->payload( 'ResetRequested', array_merge( array(
		'reset_operation_id' => $s->id( $slot ),
		'bound_archive_id'   => $s->id( 'archive-1' ),
		'snapshot_id'        => $s->id( 'snapshot-1' ),
	), $overrides ) );
	return persist_single( $stack, $s, 'RequestReset', 'request_reset', $payload );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_authorize_reset( array $stack, GHCA_Persist_Scenario $s, string $slot = 'reset-1', string $auth_slot = 'auth-1' ) {
	$payload = $s->payload( 'ResetAuthorized', array(
		'reset_operation_id' => $s->id( $slot ),
		'authorization_id'   => $s->id( $auth_slot ),
		'archive_id'         => $s->id( 'archive-1' ),
		'snapshot_id'        => $s->id( 'snapshot-1' ),
	) );
	return persist_single( $stack, $s, 'AuthorizeReset', 'authorize_reset', $payload );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_claim_reset( array $stack, GHCA_Persist_Scenario $s, string $slot = 'reset-1', string $auth_slot = 'auth-1' ) {
	$payload = $s->payload( 'ResetExecutionClaimed', array(
		'reset_operation_id' => $s->id( $slot ),
		'authorization_id'   => $s->id( $auth_slot ),
	) );
	return persist_single( $stack, $s, 'ClaimResetExecution', 'claim_reset_execution', $payload );
}

/**
 * Correction decision: invalidations plus correction plus revocation in one
 * atomic three-event command.
 *
 * @param array<string,mixed> $stack
 * @param array<int,string> $invalidated_slots reset-operation slots with optional auth slots as "reset|auth".
 * @return array<string,mixed>
 */
function persist_request_correction( array $stack, GHCA_Persist_Scenario $s, array $invalidated_slots = array() ) {
	$correction = $s->payload( 'CorrectionRequested', array(
		'target_archive_id'       => $s->id( 'archive-1' ),
		'target_snapshot_id'      => $s->id( 'snapshot-1' ),
		'correction_operation_id' => $s->id( 'correction-1' ),
	) );
	$invalidation_payloads = array();
	$invalidation_entries  = array();
	$invalidated_ids       = array();
	foreach ( $invalidated_slots as $slot_pair ) {
		$parts     = explode( '|', $slot_pair );
		$reset_id  = $s->id( $parts[0] );
		$auth_id   = ( isset( $parts[1] ) && '' !== $parts[1] ) ? $s->id( $parts[1] ) : null;
		$invalidation_payloads[] = array(
			'payload_schema_version'    => 1,
			'reset_operation_id'        => $reset_id,
			'authorization_id'          => $auth_id,
			'invalidating_reference_id' => $correction['correction_operation_id'],
			'reason_code'               => 'archive_correction',
		);
		$invalidation_entries[] = array(
			'authorization_id'   => $auth_id,
			'reason_code'        => 'archive_correction',
			'reset_operation_id' => $reset_id,
		);
		$invalidated_ids[] = $reset_id;
	}
	$revocation = $s->payload( 'ArchiveRevoked', array(
		'target_archive_id'               => $s->id( 'archive-1' ),
		'correction_operation_id'         => $correction['correction_operation_id'],
		'revocation_reason_code'          => $correction['reason_code'],
		'invalidated_reset_operation_ids' => $invalidated_ids,
	) );
	$caller = array(
		'reason_code'       => $correction['reason_code'],
		'target_archive_id' => $correction['target_archive_id'],
	);
	$server = array(
		'affected_scope_digest'   => $correction['affected_scope_digest'],
		'correction_operation_id' => $correction['correction_operation_id'],
		'invalidations'           => $invalidation_entries,
		'target_snapshot_id'      => $correction['target_snapshot_id'],
	);
	return ghca_persist_execute( $stack, $s, 'RequestCorrection', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $invalidation_payloads, $correction, $revocation ) {
		return $case->correct( $invalidation_payloads, $correction, $revocation );
	} );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_request_replacement( array $stack, GHCA_Persist_Scenario $s ) {
	$payload = $s->payload( 'ReplacementArchiveRequested', array(
		'archive_id'                     => $s->id( 'archive-2' ),
		'revoked_predecessor_archive_id' => $s->id( 'archive-1' ),
	) );
	return persist_single( $stack, $s, 'RequestReplacementArchive', 'request_replacement_archive', $payload );
}

/**
 * Pre-capture source-drift detection with its mandatory candidate failure.
 *
 * @param array<string,mixed> $stack
 * @return array<string,mixed>
 */
function persist_detect_drift( array $stack, GHCA_Persist_Scenario $s ) {
	$drift = $s->payload( 'SourceDriftDetected', array(
		'incident_id'                 => $s->id( 'drift-1' ),
		'archive_id'                  => $s->id( 'archive-1' ),
		'snapshot_id'                 => null,
		'expected_source_fingerprint' => remediation_digest( '1' ),
		'observed_source_fingerprint' => remediation_digest( '9' ),
		'detection_point'             => 'pre_capture',
	) );
	$failure = $s->payload( 'ArchiveFailed', array(
		'archive_id'         => $s->id( 'archive-1' ),
		'build_attempt_id'   => $s->id( 'attempt-1' ),
		'phase'              => 'capturing',
		'failure_code'       => 'source_drift',
		'sealed_snapshot_id' => null,
	) );
	$caller = array(
		'archive_id'                  => $drift['archive_id'],
		'changed_component_codes'     => $drift['changed_component_codes'],
		'detection_point'             => $drift['detection_point'],
		'observed_source_fingerprint' => $drift['observed_source_fingerprint'],
	);
	$failure_document = $failure;
	unset( $failure_document['payload_schema_version'] );
	$server = array(
		'expected_source_fingerprint' => $drift['expected_source_fingerprint'],
		'failure'                     => $failure_document,
		'incident_id'                 => $drift['incident_id'],
		'invalidations'               => array(),
		'snapshot_id'                 => $drift['snapshot_id'],
	);
	return ghca_persist_execute( $stack, $s, 'DetectSourceDrift', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $drift, $failure ) {
		return $case->detect_source_drift( $drift, $failure, array() );
	} );
}

/**
 * Replacement-rebase drift recovery over a never-finalized candidate:
 * [SourceDriftResolved, ArchiveCancelled, ArchiveRequested].
 *
 * @param array<string,mixed> $stack
 * @return array<string,mixed>
 */
function persist_rebase_drift( array $stack, GHCA_Persist_Scenario $s ) {
	$new_fingerprint = remediation_digest( '9' );
	$request         = $s->payload( 'ArchiveRequested', array(
		'archive_id'                  => $s->id( 'archive-2' ),
		'revision_number'             => 2,
		'reviewed_source_fingerprint' => $new_fingerprint,
	) );
	$resolved = $s->payload( 'SourceDriftResolved', array(
		'incident_id'                 => $s->id( 'drift-1' ),
		'resolution_kind'             => 'replacement_rebased',
		'verified_source_fingerprint' => $new_fingerprint,
		'resolution_reference_id'     => $request['archive_id'],
	) );
	$cancellation = $s->payload( 'ArchiveCancelled', array(
		'archive_id'       => $s->id( 'archive-1' ),
		'build_attempt_id' => $s->id( 'attempt-1' ),
	) );
	$caller = array( 'incident_id' => $resolved['incident_id'] );
	$strip  = static function ( array $document ): array {
		unset( $document['payload_schema_version'] );
		return $document;
	};
	$server = array(
		'cancellation' => $strip( $cancellation ),
		'correction'   => null,
		'request'      => $strip( $request ),
		'request_type' => 'initial',
		'resolved'     => $strip( $resolved ),
		'revocation'   => null,
	);
	return ghca_persist_execute( $stack, $s, 'RebaseSourceDriftRecovery', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $resolved, $request, $cancellation ) {
		return $case->resolve_source_drift_rebased( $resolved, $request, $cancellation, null, null );
	} );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_detect_unprotected( array $stack, GHCA_Persist_Scenario $s ) {
	$detected = $s->payload( 'UnprotectedResetDetected', array(
		'incident_id' => $s->id( 'unprotected-1' ),
	) );
	$caller = array(
		'detector_key'                => $detected['detector_key'],
		'observed_source_fingerprint' => $detected['observed_source_fingerprint'],
		'probe_version'               => $detected['probe_version'],
		'scope'                       => $detected['scope'],
	);
	$server = array(
		'before_source_fingerprint' => $detected['before_source_fingerprint'],
		'incident_id'               => $detected['incident_id'],
		'invalidations'             => array(),
	);
	return ghca_persist_execute( $stack, $s, 'DetectUnprotectedReset', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $detected ) {
		return $case->detect_unprotected_reset( $detected, array() );
	} );
}

/** @param array<string,mixed> $stack @return array<string,mixed> */
function persist_detect_integrity( array $stack, GHCA_Persist_Scenario $s ) {
	$detected = $s->payload( 'IntegrityViolationDetected', array(
		'incident_id' => $s->id( 'integrity-1' ),
	) );
	$caller = $detected;
	unset( $caller['payload_schema_version'], $caller['incident_id'] );
	$server = array(
		'incident_id'   => $detected['incident_id'],
		'invalidations' => array(),
	);
	return ghca_persist_execute( $stack, $s, 'DetectIntegrityViolation', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $detected ) {
		return $case->detect_integrity_violation( $detected, array() );
	} );
}
