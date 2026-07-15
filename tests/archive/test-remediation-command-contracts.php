<?php
require __DIR__ . '/bootstrap.php';

$actor = new GHCA_ACD_Archive_Actor( 'wp_user', '7', '7', 'test', 'archive_create', remediation_authority_context() );

/** @return array<string,mixed> */
function command_payload_without_version( string $event_type, array $overrides = array() ): array {
	$payload = remediation_payload( $event_type, $overrides );
	unset( $payload['payload_schema_version'] );
	return $payload;
}

/** @param array<int,string> $fields @return array<string,mixed> */
function command_fields( array $payload, array $fields ): array {
	$subset = array();
	foreach ( $fields as $field ) {
		$subset[ $field ] = $payload[ $field ];
	}
	return $subset;
}

$request_payload      = command_payload_without_version( 'ArchiveRequested' );
$replacement_payload  = command_payload_without_version( 'ReplacementArchiveRequested' );
$build_payload        = command_payload_without_version( 'ArchiveBuildStarted' );
$snapshot_payload     = command_payload_without_version( 'EvidenceSnapshotCaptured' );
$ledger_payload       = command_payload_without_version( 'LedgerMaterialized' );
$packet_payload       = command_payload_without_version( 'PacketMaterialized' );
$failed_payload       = command_payload_without_version( 'ArchiveFailed' );
$retry_payload        = command_payload_without_version( 'ArchiveRetryRequested' );
$cancel_payload       = command_payload_without_version( 'ArchiveCancelled' );
$reset_payload        = command_payload_without_version( 'ResetRequested' );
$defer_payload        = command_payload_without_version( 'ResetDeferred' );
$reject_payload       = command_payload_without_version( 'ResetRejected' );
$reset_cancel_payload = command_payload_without_version( 'ResetCancelled' );
$authorize_payload    = command_payload_without_version( 'ResetAuthorized' );
$expire_payload       = command_payload_without_version( 'ResetAuthorizationExpired' );
$claim_payload        = command_payload_without_version( 'ResetExecutionClaimed' );
$drift_payload        = command_payload_without_version( 'SourceDriftDetected' );
$unprotected_payload  = command_payload_without_version( 'UnprotectedResetDetected' );
$integrity_payload    = command_payload_without_version( 'IntegrityViolationDetected' );

/**
 * One closed contract per approved business command:
 * [factory method, caller intent, server facts, server-fact variant].
 * The variant changes only server-generated/server-resolved values, proving
 * a response-loss retry keeps the same client intent identity.
 *
 * @var array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>}>
 */
$contract_fixtures = array(
	'RequestArchive' => array( 'request_archive',
		command_fields( $request_payload, array( 'case_key', 'request_kind' ) ),
		command_fields( $request_payload, array( 'archive_id', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'subject_scope_digest' ) ),
		array( 'archive_id' => remediation_id( '9' ), 'reviewed_source_fingerprint' => remediation_digest( '9' ) ) ),
	'RequestReplacementArchive' => array( 'request_replacement_archive',
		command_fields( $replacement_payload, array( 'case_key', 'revoked_predecessor_archive_id' ) ),
		command_fields( $replacement_payload, array( 'archive_id', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'subject_scope_digest' ) ),
		array( 'archive_id' => remediation_id( '9' ) ) ),
	'StartBuild' => array( 'start_build',
		command_fields( $build_payload, array( 'archive_id' ) ),
		command_fields( $build_payload, array( 'build_attempt_id', 'retry_ordinal', 'snapshot_id', 'start_phase' ) ),
		array( 'build_attempt_id' => remediation_id( '9' ) ) ),
	'RecordEvidenceSnapshot' => array( 'record_evidence_snapshot',
		command_fields( $snapshot_payload, array( 'archive_id' ) ),
		command_fields( $snapshot_payload, array( 'byte_count', 'captured_source_fingerprint', 'certificate_asset_ids', 'certificate_content_digests', 'completeness_policy', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'snapshot_digest', 'snapshot_id', 'snapshot_schema_version', 'subject_scope_digest' ) ),
		array( 'snapshot_id' => remediation_id( '9' ) ) ),
	'RecordMaterializedArtifact' => array( 'record_materialized_artifact',
		array( 'archive_id' => $ledger_payload['archive_id'], 'artifact_kind' => 'ledger' ),
		command_fields( $ledger_payload, array( 'build_attempt_id', 'content_digest', 'item_count', 'ledger_artifact_id', 'manifest_digest', 'snapshot_digest', 'snapshot_id' ) ),
		array( 'ledger_artifact_id' => remediation_id( '9' ) ) ),
	'VerifyAndFinalize' => array( 'verify_and_finalize',
		array( 'archive_id' => remediation_id( 'a' ) ),
		array( 'finalized' => remediation_payload( 'ArchiveFinalized' ), 'verified' => remediation_payload( 'ArchiveVerified' ) ),
		array( 'verified' => remediation_payload( 'ArchiveVerified', array( 'checks_digest' => remediation_digest( '9' ) ) ) ) ),
	'FailArchive' => array( 'fail_archive', $failed_payload, array(), array() ),
	'RetryArchive' => array( 'retry_archive',
		command_fields( $retry_payload, array( 'archive_id' ) ),
		command_fields( $retry_payload, array( 'new_build_attempt_id', 'prior_build_attempt_id', 'resume_phase', 'sealed_snapshot_id' ) ),
		array( 'new_build_attempt_id' => remediation_id( '9' ) ) ),
	'CancelArchive' => array( 'cancel_archive',
		command_fields( $cancel_payload, array( 'archive_id', 'cancellation_reason' ) ),
		command_fields( $cancel_payload, array( 'build_attempt_id', 'retained_candidate_disposition_code' ) ),
		array( 'build_attempt_id' => remediation_id( '9' ) ) ),
	'RequestCorrection' => array( 'request_correction',
		array( 'reason_code' => 'evidence_correction', 'target_archive_id' => remediation_id( 'a' ) ),
		array(
			'affected_scope_digest'   => remediation_scope_digest(),
			'correction_operation_id' => remediation_id( '9' ),
			'invalidations'           => array( array( 'authorization_id' => remediation_id( '6' ), 'reason_code' => 'correction_started', 'reset_operation_id' => remediation_id( 'f' ) ) ),
			'target_snapshot_id'      => remediation_id( 'c' ),
		),
		array( 'correction_operation_id' => remediation_id( '8' ) ) ),
	'RequestReset' => array( 'request_reset',
		command_fields( $reset_payload, array( 'bound_archive_id', 'consent_mode', 'request_valid_until_gmt', 'scope' ) ),
		command_fields( $reset_payload, array( 'reset_operation_id', 'scope_digest', 'snapshot_id' ) ),
		array( 'reset_operation_id' => remediation_id( '9' ) ) ),
	'DeferReset' => array( 'defer_reset',
		command_fields( $defer_payload, array( 'reset_operation_id' ) ),
		command_fields( $defer_payload, array( 'condition_code', 'consent_expires_at_gmt', 'reevaluation_deadline_gmt' ) ),
		array( 'condition_code' => 'policy_hold' ) ),
	'RejectReset' => array( 'reject_reset',
		command_fields( $reject_payload, array( 'reset_operation_id' ) ),
		command_fields( $reject_payload, array( 'rejection_code', 'safe_explanation' ) ),
		array( 'rejection_code' => 'archive_not_finalized' ) ),
	'CancelReset' => array( 'cancel_reset',
		command_fields( $reset_cancel_payload, array( 'cancellation_reason', 'reset_operation_id' ) ),
		command_fields( $reset_cancel_payload, array( 'authorization_id' ) ),
		array( 'authorization_id' => remediation_id( '6' ) ) ),
	'AuthorizeReset' => array( 'authorize_reset',
		command_fields( $authorize_payload, array( 'reset_operation_id' ) ),
		command_fields( $authorize_payload, array( 'archive_id', 'authorization_id', 'expires_at_gmt', 'gateway_key', 'issued_at_gmt', 'scope_digest', 'snapshot_id', 'source_fingerprint' ) ),
		array( 'authorization_id' => remediation_id( '9' ) ) ),
	'ExpireResetAuthorization' => array( 'expire_reset_authorization',
		command_fields( $expire_payload, array( 'reset_operation_id' ) ),
		command_fields( $expire_payload, array( 'authorization_id', 'expiry_policy_code', 'observed_at_gmt', 'scheduled_expires_at_gmt' ) ),
		array( 'observed_at_gmt' => '2026-07-13T12:56:00Z' ) ),
	'ClaimResetExecution' => array( 'claim_reset_execution',
		command_fields( $claim_payload, array( 'reset_operation_id' ) ),
		command_fields( $claim_payload, array( 'authorization_id', 'claimed_at_gmt', 'gateway_key', 'scope_digest', 'source_fingerprint', 'upstream_operation_id' ) ),
		array( 'upstream_operation_id' => 'ld-reset:tenant-1/op-0002' ) ),
	'CompleteReset' => array( 'complete_reset', command_payload_without_version( 'ResetCompleted' ), array(), array() ),
	'RecordResetFailedSafe' => array( 'record_reset_failed_safe', command_payload_without_version( 'ResetFailedSafe' ), array(), array() ),
	'RecordResetOutcomeUncertain' => array( 'record_reset_outcome_uncertain', command_payload_without_version( 'ResetOutcomeBecameUncertain' ), array(), array() ),
	'ReconcileResetAsCompleted' => array( 'reconcile_reset_as_completed', command_payload_without_version( 'ResetReconciledAsCompleted' ), array(), array() ),
	'ReconcileResetAsNoChange' => array( 'reconcile_reset_as_no_change', command_payload_without_version( 'ResetReconciledAsNoChange' ), array(), array() ),
	'RequireResetRemediation' => array( 'require_reset_remediation',
		command_fields( command_payload_without_version( 'ResetRemediationRequired' ), array( 'affected_scope_digest', 'evidence_digest', 'reset_operation_id', 'upstream_operation_id' ) ),
		array( 'remediation_case_id' => remediation_id( '8' ) ),
		array( 'remediation_case_id' => remediation_id( '9' ) ) ),
	'RecordResetRemediatedRestored' => array( 'record_reset_remediated_restored', command_payload_without_version( 'ResetRemediatedRestored' ), array(), array() ),
	'DetectSourceDrift' => array( 'detect_source_drift',
		command_fields( $drift_payload, array( 'archive_id', 'changed_component_codes', 'detection_point', 'observed_source_fingerprint' ) ),
		array_merge( command_fields( $drift_payload, array( 'expected_source_fingerprint', 'incident_id', 'snapshot_id' ) ), array( 'failure' => null, 'invalidations' => array() ) ),
		array( 'incident_id' => remediation_id( '9' ) ) ),
	'ResolveSourceDriftRestored' => array( 'resolve_source_drift_restored', command_payload_without_version( 'SourceDriftResolved' ), array(), array() ),
	'RebaseSourceDriftRecovery' => array( 'rebase_source_drift_recovery',
		array( 'incident_id' => remediation_id( '7' ) ),
		array(
			'cancellation' => remediation_payload( 'ArchiveCancelled' ),
			'correction'   => null,
			'request'      => remediation_payload( 'ArchiveRequested', array( 'archive_id' => remediation_id( '0' ), 'revision_number' => 2, 'reviewed_source_fingerprint' => remediation_digest( '9' ) ) ),
			'request_type' => 'initial',
			'resolved'     => remediation_payload( 'SourceDriftResolved', array( 'resolution_kind' => 'replacement_rebased', 'verified_source_fingerprint' => remediation_digest( '9' ), 'resolution_reference_id' => remediation_id( '0' ) ) ),
			'revocation'   => null,
		),
		array( 'cancellation' => remediation_payload( 'ArchiveCancelled', array( 'cancellation_reason' => 'drift_rebase' ) ) ) ),
	'DetectUnprotectedReset' => array( 'detect_unprotected_reset',
		command_fields( $unprotected_payload, array( 'detector_key', 'observed_source_fingerprint', 'probe_version', 'scope' ) ),
		array_merge( command_fields( $unprotected_payload, array( 'before_source_fingerprint', 'incident_id' ) ), array( 'invalidations' => array() ) ),
		array( 'incident_id' => remediation_id( '9' ) ) ),
	'DismissUnprotectedReset' => array( 'dismiss_unprotected_reset', command_payload_without_version( 'UnprotectedResetDismissed' ), array(), array() ),
	'ConfirmUnprotectedReset' => array( 'confirm_unprotected_reset', command_payload_without_version( 'UnprotectedResetConfirmed' ), array(), array() ),
	'DetectIntegrityViolation' => array( 'detect_integrity_violation',
		command_fields( $integrity_payload, array( 'containment_code', 'expected_digest', 'invariant_code', 'observed_digest', 'target_id', 'target_kind', 'verifier_version' ) ),
		array( 'incident_id' => $integrity_payload['incident_id'], 'invalidations' => array() ),
		array( 'incident_id' => remediation_id( '9' ) ) ),
	'RecordIntegrityDisposition' => array( 'record_integrity_disposition', command_payload_without_version( 'IntegrityIncidentDispositionRecorded' ), array(), array() ),
);

foreach ( $contract_fixtures as $command_type => $fixture ) {
	list( $factory, $caller, $server, $server_variant ) = $fixture;
	$first = call_user_func(
		array( 'GHCA_ACD_Archive_Command', $factory ),
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $caller, $server
	);
	archive_check( $command_type === $first->type() && 64 === strlen( $first->digest() ), 'B-CMD-' . $command_type . ' closed factory accepts its exact caller/server contract' );

	// Receipt-first recognition uses only caller intent — no server facts are
	// constructed to decide that a response-loss retry matches.
	$first_intent = GHCA_ACD_Archive_Client_Intent::prepare( $command_type, remediation_digest( 'b' ), remediation_digest( 'c' ), $caller );
	$retry_intent = GHCA_ACD_Archive_Client_Intent::prepare( $command_type, remediation_digest( 'b' ), remediation_digest( 'c' ), $caller );
	archive_check( $first_intent->recognizes_response_loss_retry( $retry_intent ), 'B-RETRY-' . $command_type . ' response-loss retry is recognized from caller intent alone, before any server fact is resolved' );
	$retry = call_user_func(
		array( 'GHCA_ACD_Archive_Command', $factory ),
		remediation_id( '2' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '7', $actor, $caller, array_replace( $server, $server_variant )
	);
	archive_check( $first_intent->client_intent_digest() === $retry->client_intent()->client_intent_digest(), 'B-RETRY-' . $command_type . ' the prepared client intent matches the full retry command client intent' );
	archive_check( $first->digest() !== $retry->digest(), 'B-RETRY-' . $command_type . ' a newly accepted retry envelope has a distinct full command digest' );
	if ( array() !== $server_variant ) {
		$server_fact_only = call_user_func(
			array( 'GHCA_ACD_Archive_Command', $factory ),
			remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $caller, array_replace( $server, $server_variant )
		);
		archive_check(
			$first->client_intent_digest() === $server_fact_only->client_intent_digest()
				&& $first->digest() !== $server_fact_only->digest(),
			'B-SERVER-FACTS-' . $command_type . ' changing only resolved server facts changes the full command digest'
		);
	}

	$bad_caller = $caller;
	$bad_caller['unexpected'] = true;
	archive_expect_exception( static function () use ( $factory, $actor, $bad_caller, $server ): void {
		call_user_func(
			array( 'GHCA_ACD_Archive_Command', $factory ),
			remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $bad_caller, $server
		);
	}, 'B-CMD-' . $command_type . ' rejects arbitrary caller payload fields', InvalidArgumentException::class );

	if ( array() !== $server ) {
		$moved_caller = $caller;
		$moved_server = $server;
		$server_keys  = array_keys( $moved_server );
		$moved_field  = $server_keys[0];
		$moved_caller[ $moved_field ] = $moved_server[ $moved_field ];
		unset( $moved_server[ $moved_field ] );
		archive_expect_exception( static function () use ( $factory, $actor, $moved_caller, $moved_server ): void {
			call_user_func(
				array( 'GHCA_ACD_Archive_Command', $factory ),
				remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $moved_caller, $moved_server
			);
		}, 'B-SPLIT-' . $command_type . ' server-resolved facts cannot masquerade as caller intent', InvalidArgumentException::class );
	}
}

// Every public factory must preserve the original sequence input type so the
// shared validator, not PHP scalar coercion, rejects non-string values.
foreach ( $contract_fixtures as $command_type => $fixture ) {
	list( $factory, $caller, $server ) = $fixture;
	archive_expect_exception( static function () use ( $factory, $caller, $server, $actor ): void {
		call_user_func(
			array( 'GHCA_ACD_Archive_Command', $factory ),
			remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), 0, $actor, $caller, $server
		);
	}, 'T7-SEQ-NON-STRING-' . $command_type . ' rejects an integer sequence without coercion', InvalidArgumentException::class );
}

// B-IDEMP: caller-intent identity semantics for the primary request command.
$request_fixture = $contract_fixtures['RequestArchive'];
$first = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $request_fixture[1], $request_fixture[2]
);
$retry_server = array_replace( $request_fixture[2], array( 'archive_id' => remediation_id( '9' ), 'reviewed_source_fingerprint' => remediation_digest( '9' ) ) );
$response_loss_retry = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '2' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '7', $actor, $request_fixture[1], $retry_server
);
archive_check( $first->client_intent_digest() === $response_loss_retry->client_intent_digest(), 'B-IDEMP-01 response-loss retry recognizes unchanged caller intent despite new server-generated IDs' );
$first_request_intent = GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), $request_fixture[1] );
$retry_request_intent = GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), $request_fixture[1] );
archive_check( $first_request_intent->recognizes_response_loss_retry( $retry_request_intent ), 'B-IDEMP-01B a matching duplicate intent is recognizable from dedupe identity and caller intent without recomputing server facts' );
$command_id_only = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '2' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $request_fixture[1], $request_fixture[2]
);
$sequence_only = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '7', $actor, $request_fixture[1], $request_fixture[2]
);
archive_check( $first->digest() !== $command_id_only->digest(), 'B-IDEMP-02A-COMMAND-ID changing only command ID changes the full command digest' );
archive_check( $first->digest() !== $sequence_only->digest(), 'B-IDEMP-02B-EXPECTED-SEQUENCE changing only expected sequence changes the full command digest' );

$conflicting_intent = $request_fixture[1];
$conflicting_intent['case_key'] = array_replace( remediation_case_key(), array( 'program_key' => 'orientation' ) );
$conflicting_cycle_key = $conflicting_intent['case_key']['cycle_key'];
$conflict = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '3' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $conflicting_intent, $request_fixture[2]
);
$conflict_intent = GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), $conflicting_intent );
archive_check( $first->client_intent_digest() !== $conflict->client_intent_digest(), 'B-IDEMP-03 conflicting intent under one dedupe identity is distinguishable' );
archive_check( ! $first_request_intent->recognizes_response_loss_retry( $conflict_intent ), 'B-IDEMP-03B conflicting intent is not accepted as a response-loss retry' );

// Isolate server-fact binding from command identity and concurrency fields.
$server_fact_only_variant = array_replace( $request_fixture[2], array( 'archive_id' => remediation_id( '9' ) ) );
$server_fact_only_command = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $request_fixture[1], $server_fact_only_variant
);
archive_check(
	$first->client_intent_digest() === $server_fact_only_command->client_intent_digest()
		&& $first->digest() !== $server_fact_only_command->digest(),
	'B-IDEMP-02C-SERVER-FACTS changing only an accepted server fact changes the full command digest but not client intent'
);

// T3: the pure client-intent contract is preparable and canonicalizable before
// any server fact exists, and its digest matches the accepted command's.
$prepared = GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), $request_fixture[1] );
archive_check( 'RequestArchive' === $prepared->type() && 64 === strlen( $prepared->client_intent_digest() ) && 64 === strlen( $prepared->dedupe_digest() ), 'B-CLIENT-INTENT-PREPARE valid caller intent is prepared with a dedupe identity and client-intent digest before any server fact' );
archive_check( $prepared->client_intent_digest() === $first->client_intent_digest(), 'B-CLIENT-INTENT-MATCHES-COMMAND the prepared client-intent digest equals the accepted command client-intent digest' );
archive_expect_exception( static function () use ( $request_fixture ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), array_merge( $request_fixture[1], array( 'server_leak' => true ) ) );
}, 'B-CLIENT-INTENT-MALFORMED malformed caller intent fails before receipt lookup', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $request_fixture ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $request_fixture[1], array( 'case_key' => 'not-an-object' ) ) );
}, 'B-CLIENT-INTENT-CASE-KEY malformed nested case identity fails before receipt lookup', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $request_fixture ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $request_fixture[1], array( 'request_kind' => 'invented' ) ) );
}, 'B-CLIENT-INTENT-ENUM an unapproved caller enum fails before receipt lookup', InvalidArgumentException::class );
$reset_caller = $contract_fixtures['RequestReset'][1];
archive_expect_exception( static function () use ( $reset_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestReset', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $reset_caller, array( 'scope' => array( 'junk' => true ) ) ) );
}, 'B-CLIENT-INTENT-RESET-SCOPE malformed reset scope fails before receipt lookup', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $reset_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestReset', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $reset_caller, array( 'request_valid_until_gmt' => null ) ) );
}, 'B-CLIENT-INTENT-RESET-CONSENT-WINDOW bounded consent without a window fails before receipt lookup', InvalidArgumentException::class );
$materialization_caller = $contract_fixtures['RecordMaterializedArtifact'][1];
archive_expect_exception( static function () use ( $materialization_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RecordMaterializedArtifact', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $materialization_caller, array( 'artifact_kind' => 'thumbnail' ) ) );
}, 'B-CLIENT-INTENT-ARTIFACT-KIND an unapproved materialization kind fails before receipt lookup', InvalidArgumentException::class );
$failure_caller = $contract_fixtures['FailArchive'][1];
archive_expect_exception( static function () use ( $failure_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'FailArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $failure_caller, array( 'phase' => 'requested' ) ) );
}, 'B-CLIENT-INTENT-FAILURE-PHASE-ATTEMPT contradictory failure phase and attempt fail before receipt lookup', InvalidArgumentException::class );
$drift_caller = $contract_fixtures['DetectSourceDrift'][1];
archive_expect_exception( static function () use ( $drift_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'DetectSourceDrift', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $drift_caller, array( 'detection_point' => 'invented' ) ) );
}, 'B-CLIENT-INTENT-DRIFT-DETECTION-POINT an unapproved detection point fails before receipt lookup', InvalidArgumentException::class );
$restoration_caller = $contract_fixtures['ResolveSourceDriftRestored'][1];
archive_expect_exception( static function () use ( $restoration_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'ResolveSourceDriftRestored', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $restoration_caller, array( 'resolution_kind' => 'replacement_rebased' ) ) );
}, 'B-CLIENT-INTENT-RESTORATION-KIND rebase cannot masquerade as caller-only restoration before receipt lookup', InvalidArgumentException::class );
$integrity_caller = $contract_fixtures['DetectIntegrityViolation'][1];
archive_expect_exception( static function () use ( $integrity_caller ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'DetectIntegrityViolation', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $integrity_caller, array( 'invariant_code' => 'stream_gap' ) ) );
}, 'B-CLIENT-INTENT-INTEGRITY-EXCLUSIVITY contradictory integrity evidence fails before receipt lookup', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $request_fixture ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), array_replace( $request_fixture[1], array( 'request_kind' => str_repeat( 'x', 4097 ) ) ) );
}, 'B-CLIENT-INTENT-PAYLOAD-BOUND oversized caller content fails before receipt lookup', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $request_fixture ): void {
	GHCA_ACD_Archive_Client_Intent::prepare( 'InventLifecycleTruth', remediation_digest( 'b' ), remediation_digest( 'c' ), $request_fixture[1] );
}, 'B-CLIENT-INTENT-UNKNOWN-TYPE an unknown command type cannot prepare a client intent', InvalidArgumentException::class );

archive_check( 1 === $first->canonical()['canonical_format_version'], 'B-CANON-01 the accepted command document carries canonical_format_version' );

archive_check( $first->client_intent_digest() === 'cca88d4cc96c40e81a623408d237bd4fc4bb52b46402dda9b5f037168ebc0e46', 'B-GOLDEN-01 real RequestArchive client-intent vector is frozen' );
archive_check( $first->digest() === '361dd1a6b3ec8e02756ae2b7ea8b85d5605b043301038520d33ebe300a123f92', 'B-GOLDEN-02 real accepted RequestArchive command vector is frozen' );

archive_expect_exception( static function () use ( $actor ): void {
	GHCA_ACD_Archive_Command::from_parts(
		remediation_id( '1' ), 'InventLifecycleTruth', remediation_digest( 'b' ), remediation_digest( 'c' ), '0',
		$actor, array( 'anything' => true ), array()
	);
}, 'B-CMD-01 arbitrary regex-shaped command type is rejected', InvalidArgumentException::class );
archive_expect_exception( static function () use ( $actor, $request_fixture ): void {
	GHCA_ACD_Archive_Command::from_parts(
		remediation_id( '1' ), 'RequestArchive', remediation_digest( 'b' ), remediation_digest( 'c' ), 0,
		$actor, $request_fixture[1], $request_fixture[2]
	);
}, 'T7-SEQ-FROM-PARTS-NON-STRING generic construction rejects an integer sequence without coercion', InvalidArgumentException::class );

$foreign_authority = new GHCA_ACD_Archive_Actor( 'wp_user', '7', '7', 'test', 'archive_create', array_replace( remediation_authority_context(), array( 'subject_scope_digest' => remediation_digest( '9' ) ) ) );
archive_expect_exception( static function () use ( $foreign_authority, $request_fixture ): void {
	GHCA_ACD_Archive_Command::request_archive(
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $foreign_authority, $request_fixture[1], $request_fixture[2]
	);
}, 'B-AUTH-01 actor authority subject scope must match the requested subject scope', InvalidArgumentException::class );

$authority_bound_commands = array(
	'RequestCorrection', 'RequestReset', 'AuthorizeReset', 'ClaimResetExecution',
	'RequireResetRemediation', 'DetectUnprotectedReset', 'ConfirmUnprotectedReset',
);
foreach ( $authority_bound_commands as $authority_command_type ) {
	list( $authority_factory, $authority_caller, $authority_server ) = $contract_fixtures[ $authority_command_type ];
	archive_expect_exception( static function () use ( $authority_factory, $authority_caller, $authority_server, $foreign_authority ): void {
		call_user_func(
			array( 'GHCA_ACD_Archive_Command', $authority_factory ),
			remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $foreign_authority, $authority_caller, $authority_server
		);
	}, 'B-AUTH-' . $authority_command_type . ' effective subject scope must match actor authority', InvalidArgumentException::class );
}

$rebase_fixture = $contract_fixtures['RebaseSourceDriftRecovery'];
$rebase_matching_correction_server = array_replace( $rebase_fixture[2], array(
	'cancellation' => null,
	'correction'   => remediation_payload( 'CorrectionRequested' ),
	'request'      => remediation_payload( 'ReplacementArchiveRequested', array( 'archive_id' => remediation_id( '0' ), 'revision_number' => 2, 'reviewed_source_fingerprint' => remediation_digest( '9' ) ) ),
	'request_type' => 'replacement',
	'revocation'   => remediation_payload( 'ArchiveRevoked' ),
) );
$rebase_matching_correction = GHCA_ACD_Archive_Command::rebase_source_drift_recovery(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $rebase_fixture[1], $rebase_matching_correction_server
);
archive_check( 'RebaseSourceDriftRecovery' === $rebase_matching_correction->type(), 'B-AUTH-RebaseSourceDriftRecovery-CORRECTION-CONTROL matching nested correction and replacement scopes are accepted' );
$rebase_correction_server = $rebase_matching_correction_server;
$rebase_correction_server['correction'] = array_replace( $rebase_correction_server['correction'], array( 'affected_scope_digest' => remediation_digest( '9' ) ) );
archive_expect_exception( static function () use ( $actor, $rebase_fixture, $rebase_correction_server ): void {
	GHCA_ACD_Archive_Command::rebase_source_drift_recovery(
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $rebase_fixture[1], $rebase_correction_server
	);
}, 'B-AUTH-RebaseSourceDriftRecovery-CORRECTION nested correction scope must match actor authority and replacement scope', InvalidArgumentException::class );
$rebase_request_server = $rebase_matching_correction_server;
$rebase_request_server['request'] = array_replace( $rebase_request_server['request'], array( 'subject_scope_digest' => remediation_digest( '9' ) ) );
archive_expect_exception( static function () use ( $actor, $rebase_fixture, $rebase_request_server ): void {
	GHCA_ACD_Archive_Command::rebase_source_drift_recovery(
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $rebase_fixture[1], $rebase_request_server
	);
}, 'B-AUTH-RebaseSourceDriftRecovery-REQUEST nested replacement request scope must match actor authority and correction scope', InvalidArgumentException::class );

$packet_fixture_server = command_fields( $packet_payload, array( 'build_attempt_id', 'certificate_content_digests', 'content_digest', 'packet_artifact_id', 'snapshot_digest', 'snapshot_id' ) );
$packet_command = GHCA_ACD_Archive_Command::record_materialized_artifact(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor,
	array( 'archive_id' => $packet_payload['archive_id'], 'artifact_kind' => 'packet' ), $packet_fixture_server
);
archive_check( 'RecordMaterializedArtifact' === $packet_command->type(), 'B-CMD-RecordMaterializedArtifact packet variant accepts its exact server contract' );
archive_expect_exception( static function () use ( $actor, $packet_fixture_server, $packet_payload ): void {
	GHCA_ACD_Archive_Command::record_materialized_artifact(
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor,
		array( 'archive_id' => $packet_payload['archive_id'], 'artifact_kind' => 'thumbnail' ), $packet_fixture_server
	);
}, 'B-CMD-RecordMaterializedArtifact rejects unapproved artifact kinds', InvalidArgumentException::class );

// T4: a real command with no server facts (FailArchive) must encode its
// object-valued server_facts as {}, never as an empty list [].
$fail_command = GHCA_ACD_Archive_Command::fail_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor,
	$failed_payload, array()
);
$fail_canonical = GHCA_ACD_Archive_Canonical_JSON::encode( $fail_command->canonical() );
archive_check( false !== strpos( $fail_canonical, '"server_facts":{}' ), 'B-EMPTY-SERVER-FACTS a command with no server facts encodes server_facts as {} not []' );
archive_check( false === strpos( $fail_canonical, '"server_facts":[]' ), 'B-EMPTY-SERVER-FACTS-NOT-LIST empty server facts never encode as an empty list' );

archive_expect_exception( static function () use ( $actor ): void {
	GHCA_ACD_Archive_Command::resolve_source_drift_restored(
		remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor,
		command_payload_without_version( 'SourceDriftResolved', array( 'resolution_kind' => 'replacement_rebased', 'verified_source_fingerprint' => remediation_digest( '9' ) ) ), array()
	);
}, 'B-CMD-DRIFT-KIND a rebase resolution cannot use the restoration command', InvalidArgumentException::class );

archive_finish();
