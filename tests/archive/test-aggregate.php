<?php
require __DIR__ . '/bootstrap.php';

function archive_id( string $char ): string { return str_repeat( $char, 32 ); }
function archive_digest( string $char ): string { return str_repeat( $char, 64 ); }

/** @return array<string,mixed> */
function event_payload( string $type, array $overrides = array() ): array {
	return archive_event_payload( $type, $overrides );
}

function request_archive( GHCA_ACD_Archive_Case $case, string $archive_id, int $revision = 1, ?string $predecessor = null ): void {
	if ( null === $predecessor ) {
		$case->request_archive( event_payload( 'ArchiveRequested', array(
			'archive_id' => $archive_id, 'revision_number' => $revision,
			'reviewed_source_fingerprint' => archive_digest( '1' ),
		) ) );
		return;
	}
	$case->request_replacement_archive( event_payload( 'ReplacementArchiveRequested', array(
		'archive_id' => $archive_id, 'revision_number' => $revision,
		'revoked_predecessor_archive_id' => $predecessor,
		'reviewed_source_fingerprint' => archive_digest( '1' ),
	) ) );
}

/** @return array<string,mixed> */
function finalize_archive( GHCA_ACD_Archive_Case $case, string $archive_id, string $attempt_id, string $snapshot_id, string $ledger_id, string $packet_id, ?string $predecessor = null ): array {
	$revision_number = $case->state()['revisions'][ $archive_id ]['revision_number'];
	$snapshot_digest = archive_digest( '2' );
	$ledger_digest   = archive_digest( '3' );
	$packet_digest   = archive_digest( '4' );
	$case->start_build( event_payload( 'ArchiveBuildStarted', array(
		'archive_id' => $archive_id, 'build_attempt_id' => $attempt_id, 'start_phase' => 'capturing', 'snapshot_id' => null,
	) ) );
	$case->capture_evidence_snapshot( event_payload( 'EvidenceSnapshotCaptured', array(
		'archive_id' => $archive_id, 'revision_number' => $revision_number, 'snapshot_id' => $snapshot_id, 'snapshot_digest' => $snapshot_digest,
		'reviewed_source_fingerprint' => archive_digest( '1' ), 'captured_source_fingerprint' => archive_digest( '1' ),
	) ) );
	$case->materialize_ledger( event_payload( 'LedgerMaterialized', array(
		'archive_id' => $archive_id, 'snapshot_id' => $snapshot_id, 'snapshot_digest' => $snapshot_digest,
		'build_attempt_id' => $attempt_id, 'ledger_artifact_id' => $ledger_id, 'content_digest' => $ledger_digest,
	) ) );
	$case->materialize_packet( event_payload( 'PacketMaterialized', array(
		'archive_id' => $archive_id, 'snapshot_id' => $snapshot_id, 'snapshot_digest' => $snapshot_digest,
		'build_attempt_id' => $attempt_id, 'packet_artifact_id' => $packet_id, 'content_digest' => $packet_digest,
	) ) );
	$events = $case->verify_and_finalize(
		 event_payload( 'ArchiveVerified', array(
			'archive_id' => $archive_id, 'revision_number' => $revision_number, 'snapshot_id' => $snapshot_id, 'snapshot_digest' => $snapshot_digest,
			'ledger_artifact_id' => $ledger_id, 'ledger_content_digest' => $ledger_digest,
			'packet_artifact_id' => $packet_id, 'packet_content_digest' => $packet_digest,
			'source_fingerprint' => archive_digest( '1' ), 'expected_predecessor_archive_id' => $predecessor,
		) ),
		event_payload( 'ArchiveFinalized', array(
			'archive_id' => $archive_id, 'revision_number' => $revision_number, 'snapshot_id' => $snapshot_id, 'snapshot_digest' => $snapshot_digest,
			'ledger_artifact_id' => $ledger_id, 'ledger_content_digest' => $ledger_digest,
			'packet_artifact_id' => $packet_id, 'packet_content_digest' => $packet_digest,
			'expected_predecessor_archive_id' => $predecessor,
		) )
	);
	return array( 'snapshot_digest' => $snapshot_digest, 'ledger_digest' => $ledger_digest, 'packet_digest' => $packet_digest, 'events' => $events );
}

function finalized_case(): GHCA_ACD_Archive_Case {
	$case = new GHCA_ACD_Archive_Case();
	request_archive( $case, archive_id( 'a' ) );
	finalize_archive( $case, archive_id( 'a' ), archive_id( 'b' ), archive_id( 'c' ), archive_id( 'd' ), archive_id( 'e' ) );
	return $case;
}

/** @return array<string,mixed> */
function reset_requested_payload(): array {
	return event_payload( 'ResetRequested', array(
		'reset_operation_id' => archive_id( 'f' ), 'bound_archive_id' => archive_id( 'a' ),
		'snapshot_id' => archive_id( 'c' ), 'scope_digest' => remediation_scope_digest(),
	) );
}

/** @return array<string,mixed> */
function reset_authorized_payload(): array {
	return event_payload( 'ResetAuthorized', array(
		'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ),
		'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ),
		'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => archive_digest( '1' ),
	) );
}

function authorized_reset_case(): GHCA_ACD_Archive_Case {
	$case = finalized_case();
	$case->request_reset( reset_requested_payload() );
	$case->authorize_reset( reset_authorized_payload() );
	return $case;
}

function claimed_reset_case(): GHCA_ACD_Archive_Case {
	$case = authorized_reset_case();
	$case->claim_reset_execution( event_payload( 'ResetExecutionClaimed', array(
		'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ),
		'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => archive_digest( '1' ),
	) ) );
	return $case;
}

// AC-01 / INV-03..08: complete archive and reset path.
$case = finalized_case();
$state = $case->state();
archive_check( 'FINALIZED' === $state['revisions'][ archive_id( 'a' ) ]['build_state'], 'AC-01 ArchiveFinalized reaches FINALIZED' );
archive_check( 'ACTIVE' === $state['revisions'][ archive_id( 'a' ) ]['validity_state'], 'INV-02 finalized revision is the sole ACTIVE revision' );
archive_check( archive_id( 'a' ) === $state['active_archive_id'], 'ArchiveFinalized establishes active archive identity' );

$history = $case->uncommitted_events();
$replayed = GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $history );
archive_check( $replayed->state() === $case->state(), 'AC-13 deterministic replay reproduces identical aggregate state' );

$bad = new GHCA_ACD_Archive_Case();
request_archive( $bad, archive_id( 'a' ) );
archive_expect_transition_rejection( $bad, static function () use ( $bad ): void {
	$bad->record( 'ArchiveFinalized', event_payload( 'ArchiveFinalized', array( 'archive_id' => archive_id( 'a' ) ) ) );
}, 'incomplete_finalization_batch', 'INV-05 finalization before capture/materialization/verification is prohibited' );

// AC-08: too-early reset may be deferred or rejected, never authorized.
$early = new GHCA_ACD_Archive_Case();
request_archive( $early, archive_id( 'a' ) );
$early_reset = reset_requested_payload();
$early_reset['snapshot_id'] = null;
$early->request_reset( $early_reset );
$early->defer_reset( event_payload( 'ResetDeferred', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'DEFERRED' === $early->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-08 early reset can be explicitly DEFERRED' );
archive_expect_transition_rejection( $early, static function () use ( $early ): void {
	$early->authorize_reset( reset_authorized_payload() );
}, 'reset_not_eligible', 'AC-08 deferred reset cannot authorize before finalized active snapshot' );

$rejected = new GHCA_ACD_Archive_Case();
request_archive( $rejected, archive_id( 'a' ) );
$rejected->request_reset( $early_reset );
$rejected->reject_reset( event_payload( 'ResetRejected', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'REJECTED' === $rejected->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-08 early reset can be terminally REJECTED' );

// Failure, retry, and cancellation transitions.
$failed = new GHCA_ACD_Archive_Case();
request_archive( $failed, archive_id( 'a' ) );
$failed->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
$failed->fail_archive( event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'sealed_snapshot_id' => null ) ) );
$failed->request_retry( event_payload( 'ArchiveRetryRequested', array( 'archive_id' => archive_id( 'a' ), 'prior_build_attempt_id' => archive_id( 'b' ), 'new_build_attempt_id' => archive_id( '7' ), 'resume_phase' => 'capturing', 'sealed_snapshot_id' => null ) ) );
$failed->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ), 'start_phase' => 'capturing', 'retry_ordinal' => 1, 'snapshot_id' => null ) ) );
$failed->cancel_archive( event_payload( 'ArchiveCancelled', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ) ) ) );
archive_check( 'CANCELLED' === $failed->state()['revisions'][ archive_id( 'a' ) ]['build_state'], 'FAILED can retry explicitly and a non-finalized retry can cancel' );

// Reset terminal branches and no-second-destructive-reset invariant.
$completed = claimed_reset_case();
$completed->complete_reset( event_payload( 'ResetCompleted', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'COMPLETED' === $completed->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'ResetCompleted applies after a durable claim' );
archive_expect_transition_rejection( $completed, static function () use ( $completed ): void {
	$completed->request_reset( reset_requested_payload() );
}, 'reset_request_blocked', 'AC-27 no reset operation may follow COMPLETED' );

$safe = claimed_reset_case();
$safe->record_reset_failed_safe( event_payload( 'ResetFailedSafe', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'FAILED_SAFE' === $safe->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'ResetFailedSafe records conclusive no-change' );

$uncertain_complete = claimed_reset_case();
$uncertain_complete->record_reset_outcome_uncertain( event_payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
$uncertain_complete->reconcile_reset_as_completed( event_payload( 'ResetReconciledAsCompleted', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'COMPLETED' === $uncertain_complete->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-10 uncertain reset can reconcile as completed' );

$uncertain_safe = claimed_reset_case();
$uncertain_safe->record_reset_outcome_uncertain( event_payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
$uncertain_safe->reconcile_reset_as_no_change( event_payload( 'ResetReconciledAsNoChange', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'FAILED_SAFE' === $uncertain_safe->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-10 uncertain reset can reconcile as no change' );

$remediated = claimed_reset_case();
$remediated->record_reset_outcome_uncertain( event_payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
$remediated->require_reset_remediation( event_payload( 'ResetRemediationRequired', array( 'reset_operation_id' => archive_id( 'f' ), 'remediation_case_id' => archive_id( '8' ) ) ) );
$remediated->record_reset_remediated_restored( event_payload( 'ResetRemediatedRestored', array( 'reset_operation_id' => archive_id( 'f' ), 'remediation_case_id' => archive_id( '8' ) ) ) );
archive_check( 'REMEDIATED_RESTORED' === $remediated->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'INV-27 partial reset restoration is not mislabeled FAILED_SAFE' );
archive_expect_transition_rejection( $remediated, static function () use ( $remediated ): void {
	$remediated->request_reset( reset_requested_payload() );
}, 'reset_request_blocked', 'AC-27 no reset operation may follow REMEDIATED_RESTORED' );

$expired = authorized_reset_case();
$expired->expire_reset_authorization( event_payload( 'ResetAuthorizationExpired', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ) ) ) );
archive_check( 'EXPIRED' === $expired->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'unused reset authorization can expire only by event' );

$cancelled_reset = finalized_case();
$cancelled_reset->request_reset( reset_requested_payload() );
$cancelled_reset->cancel_reset( event_payload( 'ResetCancelled', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => null ) ) );
archive_check( 'CANCELLED' === $cancelled_reset->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'pending reset can be explicitly cancelled' );

// AC-11/12/22: correction invalidates pre-claim reset, revokes, and replaces atomically.
$correction = authorized_reset_case();
$batch = $correction->correct(
	array(
		event_payload( 'ResetOperationInvalidated', array(
			'reset_operation_id' => archive_id( 'f' ),
			'authorization_id'   => archive_id( '6' ),
		) ),
	),
	event_payload( 'CorrectionRequested', array(
		'target_archive_id'       => archive_id( 'a' ),
		'target_snapshot_id'      => archive_id( 'c' ),
		'correction_operation_id' => archive_id( '9' ),
	) ),
	event_payload( 'ArchiveRevoked', array(
		'target_archive_id'              => archive_id( 'a' ),
		'correction_operation_id'        => archive_id( '9' ),
		'invalidated_reset_operation_ids' => array( archive_id( 'f' ) ),
	) )
);
archive_check( 3 === count( $batch ), 'INV-22 reset invalidation plus correction/revocation emits one complete batch' );
archive_check( 'REVOKED' === $correction->state()['revisions'][ archive_id( 'a' ) ]['validity_state'], 'correction irreversibly revokes active archive' );
archive_check( 'INVALIDATED' === $correction->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'correction invalidates unused authorization' );

request_archive( $correction, archive_id( '0' ), 2, archive_id( 'a' ) );
finalize_archive( $correction, archive_id( '0' ), archive_id( '1' ), archive_id( '2' ), archive_id( '3' ), archive_id( '4' ), archive_id( 'a' ) );
archive_check( 'SUPERSEDED' === $correction->state()['revisions'][ archive_id( 'a' ) ]['validity_state'], 'AC-12 replacement finalization supersedes named revoked predecessor' );
archive_check( archive_id( '0' ) === $correction->state()['active_archive_id'], 'AC-23 replacement becomes sole active revision atomically' );

// INV-12: lineage stays acyclic — a superseded ancestor can never re-enter the chain.
$correction->correct(
	array(),
	event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( '0' ), 'target_snapshot_id' => archive_id( '2' ), 'correction_operation_id' => archive_id( '5' ) ) ),
	event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( '0' ), 'correction_operation_id' => archive_id( '5' ) ) )
);
archive_expect_transition_rejection( $correction, static function () use ( $correction ): void {
	$correction->request_replacement_archive( event_payload( 'ReplacementArchiveRequested', array(
		'archive_id' => archive_id( '8' ), 'revision_number' => 3,
		'revoked_predecessor_archive_id' => archive_id( 'a' ),
		'reviewed_source_fingerprint' => archive_digest( '1' ),
	) ) );
}, 'invalid_predecessor', 'INV-12 lineage cycle rejected: a superseded ancestor cannot become a replacement predecessor again' );

$claim_wins = authorized_reset_case();
$claim_wins->claim_reset_execution( event_payload( 'ResetExecutionClaimed', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ), 'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => archive_digest( '1' ) ) ) );
archive_expect_transition_rejection( $claim_wins, static function () use ( $claim_wins ): void {
	$claim_wins->correct( array(), event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( 'a' ), 'target_snapshot_id' => archive_id( 'c' ), 'correction_operation_id' => archive_id( '9' ) ) ), event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ) ) ) );
}, 'correction_blocked', 'AC-22 winning reset claim blocks ordinary correction' );

$correction_wins = authorized_reset_case();
$correction_wins->correct( array( event_payload( 'ResetOperationInvalidated', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ) ) ) ), event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( 'a' ), 'target_snapshot_id' => archive_id( 'c' ), 'correction_operation_id' => archive_id( '9' ) ) ), event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ), 'invalidated_reset_operation_ids' => array( archive_id( 'f' ) ) ) ) );
archive_expect_transition_rejection( $correction_wins, static function () use ( $correction_wins ): void {
	$correction_wins->claim_reset_execution( event_payload( 'ResetExecutionClaimed', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ), 'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => archive_digest( '1' ) ) ) );
}, 'reset_claim_blocked', 'AC-22 winning correction invalidates and blocks reset claim' );

// Drift and incident events are replayable independent dimensions.
$drift = new GHCA_ACD_Archive_Case();
request_archive( $drift, archive_id( 'a' ) );
$drift->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
$drift_events = $drift->detect_source_drift(
	event_payload( 'SourceDriftDetected', array( 'incident_id' => archive_id( '7' ), 'archive_id' => archive_id( 'a' ), 'snapshot_id' => null, 'detection_point' => 'pre_capture' ) ),
	event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'sealed_snapshot_id' => null ) )
);
archive_check( 2 === count( $drift_events ) && 'OPEN' === $drift->state()['source_drift_state'], 'INV-19 pre-capture source drift atomically fails candidate and opens incident' );
$drift->resolve_source_drift_restored( event_payload( 'SourceDriftResolved', array( 'incident_id' => archive_id( '7' ) ) ) );
archive_check( 'RESOLVED' === $drift->state()['source_drift_state'], 'SourceDriftResolved restoration is explicit and replayable' );

$unprotected = finalized_case();
$unprotected->detect_unprotected_reset( event_payload( 'UnprotectedResetDetected', array( 'incident_id' => archive_id( '7' ) ) ) );
$unprotected->dismiss_unprotected_reset( event_payload( 'UnprotectedResetDismissed', array( 'incident_id' => archive_id( '7' ) ) ) );
archive_check( 'DISMISSED_NO_RESET' === $unprotected->state()['unprotected_reset_state'], 'unprotected-reset incident can be dismissed only by event' );

$confirmed = finalized_case();
$confirmed->detect_unprotected_reset( event_payload( 'UnprotectedResetDetected', array( 'incident_id' => archive_id( '7' ) ) ) );
$confirmed->confirm_unprotected_reset( event_payload( 'UnprotectedResetConfirmed', array( 'incident_id' => archive_id( '7' ) ) ) );
archive_check( 'CONFIRMED_RESET' === $confirmed->state()['unprotected_reset_state'], 'confirmed out-of-band reset remains explicit and fail-closed' );
archive_expect_transition_rejection( $confirmed, static function () use ( $confirmed ): void {
	$confirmed->request_reset( reset_requested_payload() );
}, 'incident_blocked', 'AC-27 no reset operation may follow a confirmed out-of-band reset' );

$integrity = finalized_case();
$integrity->detect_integrity_violation( event_payload( 'IntegrityViolationDetected', array( 'incident_id' => archive_id( '7' ), 'target_id' => archive_id( 'a' ) ) ) );
$integrity->record_integrity_disposition( event_payload( 'IntegrityIncidentDispositionRecorded', array( 'incident_id' => archive_id( '7' ), 'disposition_code' => 'false_positive' ) ) );
archive_check( 'DISPOSITION_RECORDED' === $integrity->state()['integrity_state'], 'integrity incident disposition is replayable and history-preserving' );

// Named coverage for remaining transition-focused acceptance scenarios.
$duplicate = new GHCA_ACD_Archive_Case();
request_archive( $duplicate, archive_id( 'a' ) );
$duplicate_before = $duplicate->state();
archive_expect_transition_rejection( $duplicate, static function () use ( $duplicate ): void {
	request_archive( $duplicate, archive_id( '0' ), 2 );
}, 'active_build_exists', 'AC-02/03 a second archive request cannot create a concurrent active build' );
archive_check( $duplicate_before === $duplicate->state(), 'AC-02 rejected duplicate request leaves aggregate unchanged' );

$immutable_key = new GHCA_ACD_Archive_Case();
request_archive( $immutable_key, archive_id( 'a' ) );
$immutable_key->cancel_archive( event_payload( 'ArchiveCancelled', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => null ) ) );
archive_expect_transition_rejection( $immutable_key, static function () use ( $immutable_key ): void {
	$changed_key = remediation_case_key();
	$changed_key['tenant_id'] = archive_id( '9' );
	$payload = event_payload( 'ArchiveRequested', array( 'archive_id' => archive_id( '0' ), 'revision_number' => 2, 'case_key' => $changed_key ) );
	$immutable_key->request_archive( $payload );
}, 'case_key_mismatch', 'INV-01 Archive Case key cannot change after the first event' );

$resume = new GHCA_ACD_Archive_Case();
request_archive( $resume, archive_id( 'a' ) );
$resume->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
$resume->capture_evidence_snapshot( event_payload( 'EvidenceSnapshotCaptured', array(
	'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
	'reviewed_source_fingerprint' => archive_digest( '1' ), 'captured_source_fingerprint' => archive_digest( '1' ),
) ) );
$resume->fail_archive( event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'phase' => 'materializing', 'sealed_snapshot_id' => archive_id( 'c' ) ) ) );
$resume->request_retry( event_payload( 'ArchiveRetryRequested', array( 'archive_id' => archive_id( 'a' ), 'prior_build_attempt_id' => archive_id( 'b' ), 'new_build_attempt_id' => archive_id( '7' ), 'resume_phase' => 'materializing', 'sealed_snapshot_id' => archive_id( 'c' ) ) ) );
$resume->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ), 'start_phase' => 'materializing', 'retry_ordinal' => 1, 'snapshot_id' => archive_id( 'c' ) ) ) );
archive_check( archive_id( 'c' ) === $resume->state()['revisions'][ archive_id( 'a' ) ]['snapshot_id'], 'AC-05 retry after capture reuses the exact sealed snapshot' );
$resume->materialize_ledger( event_payload( 'LedgerMaterialized', array( 'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ), 'build_attempt_id' => archive_id( '7' ), 'ledger_artifact_id' => archive_id( 'd' ), 'content_digest' => archive_digest( '3' ) ) ) );
$resume->materialize_packet( event_payload( 'PacketMaterialized', array( 'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ), 'build_attempt_id' => archive_id( '7' ), 'packet_artifact_id' => archive_id( 'e' ), 'content_digest' => archive_digest( '4' ) ) ) );
$verifying_history = $resume->uncommitted_events();
$resume->fail_archive( event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ), 'phase' => 'verifying', 'sealed_snapshot_id' => archive_id( 'c' ) ) ) );
archive_check( 'FAILED' === $resume->state()['revisions'][ archive_id( 'a' ) ]['build_state'], 'AC-06 verification-phase failure leaves a non-official FAILED revision' );

// AC-07: the cancellation/finalization race begins from VERIFYING.
$cancel_wins = GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $verifying_history );
archive_check( 'VERIFYING' === $cancel_wins->state()['revisions'][ archive_id( 'a' ) ]['build_state'], 'AC-07 race precondition: the candidate revision is VERIFYING' );
$cancel_wins->cancel_archive( event_payload( 'ArchiveCancelled', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ) ) ) );
archive_check( false === $cancel_wins->state()['edit_locked'], 'AC-24 cancellation releases the build lock when no independent block remains' );
archive_expect_transition_rejection( $cancel_wins, static function () use ( $cancel_wins ): void {
	$cancel_wins->verify_and_finalize(
		event_payload( 'ArchiveVerified', array(
			'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
			'ledger_artifact_id' => archive_id( 'd' ), 'ledger_content_digest' => archive_digest( '3' ),
			'packet_artifact_id' => archive_id( 'e' ), 'packet_content_digest' => archive_digest( '4' ), 'source_fingerprint' => archive_digest( '1' ),
		) ),
		event_payload( 'ArchiveFinalized', array(
			'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
			'ledger_artifact_id' => archive_id( 'd' ), 'ledger_content_digest' => archive_digest( '3' ),
			'packet_artifact_id' => archive_id( 'e' ), 'packet_content_digest' => archive_digest( '4' ),
		) )
	);
}, 'verification_blocked', 'AC-07 a winning VERIFYING-stage cancellation prevents later finalization' );
$final_wins = finalized_case();
archive_expect_transition_rejection( $final_wins, static function () use ( $final_wins ): void {
	$final_wins->cancel_archive( event_payload( 'ArchiveCancelled', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ) ) ) );
}, 'invalid_cancellation', 'AC-07 finalization winner prevents cancellation' );

$wrong_binding = finalized_case();
$wrong_binding->request_reset( reset_requested_payload() );
archive_expect_transition_rejection( $wrong_binding, static function () use ( $wrong_binding ): void {
	$payload = reset_authorized_payload();
	$payload['scope_digest'] = archive_digest( '6' );
	$wrong_binding->authorize_reset( $payload );
}, 'reset_not_eligible', 'AC-09 reset authorization cannot be rebound to another scope' );

$atomic = GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $verifying_history );
$atomic_before = $atomic->state();
archive_expect_transition_rejection( $atomic, static function () use ( $atomic ): void {
	$verified = event_payload( 'ArchiveVerified', array(
		'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
		'ledger_artifact_id' => archive_id( 'd' ), 'ledger_content_digest' => archive_digest( '3' ),
		'packet_artifact_id' => archive_id( 'e' ), 'packet_content_digest' => archive_digest( '4' ), 'source_fingerprint' => archive_digest( '1' ),
	) );
	$bad_final = event_payload( 'ArchiveFinalized', array(
		'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
		'ledger_artifact_id' => archive_id( 'd' ), 'ledger_content_digest' => archive_digest( '3' ),
		'packet_artifact_id' => archive_id( 'e' ), 'packet_content_digest' => archive_digest( '9' ), 'expected_predecessor_archive_id' => null,
	) );
	$atomic->verify_and_finalize( $verified, $bad_final );
}, 'finalization_batch_mismatch', 'AC-14 invalid finalization batch fails closed' );
archive_check( $atomic_before === $atomic->state(), 'AC-14 failed multi-event decision commits no partial verification event' );

$review_drift = new GHCA_ACD_Archive_Case();
request_archive( $review_drift, archive_id( 'a' ) );
$review_drift->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
archive_expect_transition_rejection( $review_drift, static function () use ( $review_drift ): void {
	$review_drift->capture_evidence_snapshot( event_payload( 'EvidenceSnapshotCaptured', array(
		'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ), 'snapshot_digest' => archive_digest( '2' ),
		'reviewed_source_fingerprint' => archive_digest( '1' ), 'captured_source_fingerprint' => archive_digest( '9' ),
	) ) );
}, 'source_drift', 'AC-17 evidence changed after review cannot be captured as approved' );

$post_drift = finalized_case();
$post_drift->detect_source_drift( event_payload( 'SourceDriftDetected', array( 'incident_id' => archive_id( '7' ), 'archive_id' => archive_id( 'a' ), 'snapshot_id' => archive_id( 'c' ) ) ) );
archive_expect_transition_rejection( $post_drift, static function () use ( $post_drift ): void {
	$post_drift->request_reset( reset_requested_payload() );
}, 'incident_blocked', 'AC-18 source drift after finalization blocks reset request' );

$claimed_crash = claimed_reset_case();
$claimed_replay = GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $claimed_crash->uncommitted_events() );
archive_check( 'CLAIMED' === $claimed_replay->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-20 worker absence does not infer a reset outcome after claim' );

$partial = claimed_reset_case();
$partial->record_reset_outcome_uncertain( event_payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
$partial->require_reset_remediation( event_payload( 'ResetRemediationRequired', array( 'reset_operation_id' => archive_id( 'f' ), 'remediation_case_id' => archive_id( '8' ) ) ) );
archive_check( 'REMEDIATION_REQUIRED' === $partial->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'AC-21 partial reset remains blocked in REMEDIATION_REQUIRED' );

$attempt_race = new GHCA_ACD_Archive_Case();
request_archive( $attempt_race, archive_id( 'a' ) );
$attempt_race->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
archive_expect_transition_rejection( $attempt_race, static function () use ( $attempt_race ): void {
	$attempt_race->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
}, 'invalid_build_start', 'AC-28 a second concurrent build attempt cannot advance the same revision' );

$replacement_failed = authorized_reset_case();
$replacement_failed->correct(
	array(
		event_payload( 'ResetOperationInvalidated', array(
			'reset_operation_id' => archive_id( 'f' ),
			'authorization_id'   => archive_id( '6' ),
		) ),
	),
	event_payload( 'CorrectionRequested', array(
		'target_archive_id'       => archive_id( 'a' ),
		'target_snapshot_id'      => archive_id( 'c' ),
		'correction_operation_id' => archive_id( '9' ),
	) ),
	event_payload( 'ArchiveRevoked', array(
		'target_archive_id'              => archive_id( 'a' ),
		'correction_operation_id'        => archive_id( '9' ),
		'invalidated_reset_operation_ids' => array( archive_id( 'f' ) ),
	) )
);
request_archive( $replacement_failed, archive_id( '0' ), 2, archive_id( 'a' ) );
$replacement_failed->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( '0' ), 'build_attempt_id' => archive_id( '1' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
$replacement_failed->fail_archive( event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( '0' ), 'build_attempt_id' => archive_id( '1' ), 'sealed_snapshot_id' => null ) ) );
archive_check( null === $replacement_failed->state()['active_archive_id'] && 'REVOKED' === $replacement_failed->state()['revisions'][ archive_id( 'a' ) ]['validity_state'], 'AC-11 failed replacement leaves predecessor preserved/revoked and no active archive' );

$remediation_completed = claimed_reset_case();
$remediation_completed->record_reset_outcome_uncertain( event_payload( 'ResetOutcomeBecameUncertain', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
$remediation_completed->require_reset_remediation( event_payload( 'ResetRemediationRequired', array( 'reset_operation_id' => archive_id( 'f' ), 'remediation_case_id' => archive_id( '8' ) ) ) );
$remediation_completed->reconcile_reset_as_completed( event_payload( 'ResetReconciledAsCompleted', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
archive_check( 'COMPLETED' === $remediation_completed->state()['resets'][ archive_id( 'f' ) ]['reset_state'], 'reset remediation may end as verified COMPLETED' );

$incident_during_authorization = authorized_reset_case();
archive_expect_transition_rejection( $incident_during_authorization, static function () use ( $incident_during_authorization ): void {
	$incident_during_authorization->detect_integrity_violation( event_payload( 'IntegrityViolationDetected', array( 'incident_id' => archive_id( '7' ), 'target_id' => archive_id( 'a' ) ) ) );
}, 'active_reset_requires_invalidation', 'INV-15 blocking incident cannot open while authorization exists without atomic reset invalidation' );

$failed_without_retry = new GHCA_ACD_Archive_Case();
request_archive( $failed_without_retry, archive_id( 'a' ) );
$failed_without_retry->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
$failed_without_retry->fail_archive( event_payload( 'ArchiveFailed', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'sealed_snapshot_id' => null ) ) );
$finalized_no_retry = finalized_case();
$standalone_revocation = finalized_case();
$claimed_twice = claimed_reset_case();
$claimed_expiry = claimed_reset_case();
$claimed_early_reconcile = claimed_reset_case();
$no_drift_incident = finalized_case();
$illegal_transitions = array(
	array( $failed_without_retry, 'retry_required', 'failed revision cannot restart without an explicit retry request', static function () use ( $failed_without_retry ): void {
		$failed_without_retry->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( '7' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
	} ),
	array( $finalized_no_retry, 'invalid_retry', 'finalized revision cannot retry', static function () use ( $finalized_no_retry ): void {
		$finalized_no_retry->request_retry( event_payload( 'ArchiveRetryRequested', array( 'archive_id' => archive_id( 'a' ), 'prior_build_attempt_id' => archive_id( 'b' ), 'new_build_attempt_id' => archive_id( '7' ), 'sealed_snapshot_id' => archive_id( 'c' ), 'resume_phase' => 'materializing' ) ) );
	} ),
	array( $standalone_revocation, 'invalid_correction_batch', 'revocation cannot occur without correction batch', static function () use ( $standalone_revocation ): void {
		$standalone_revocation->record( 'ArchiveRevoked', event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ) ) ) );
	} ),
	array( $claimed_twice, 'reset_claim_blocked', 'authorization cannot be consumed twice', static function () use ( $claimed_twice ): void {
		$claimed_twice->claim_reset_execution( event_payload( 'ResetExecutionClaimed', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ), 'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => archive_digest( '1' ) ) ) );
	} ),
	array( $claimed_expiry, 'authorization_mismatch', 'claimed reset cannot expire', static function () use ( $claimed_expiry ): void {
		$claimed_expiry->expire_reset_authorization( event_payload( 'ResetAuthorizationExpired', array( 'reset_operation_id' => archive_id( 'f' ), 'authorization_id' => archive_id( '6' ) ) ) );
	} ),
	array( $claimed_early_reconcile, 'invalid_reset_transition', 'claimed reset cannot reconcile before uncertainty event', static function () use ( $claimed_early_reconcile ): void {
		$claimed_early_reconcile->reconcile_reset_as_completed( event_payload( 'ResetReconciledAsCompleted', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
	} ),
	array( $no_drift_incident, 'drift_not_open', 'source drift cannot resolve without an open incident', static function () use ( $no_drift_incident ): void {
		$no_drift_incident->resolve_source_drift_restored( event_payload( 'SourceDriftResolved', array( 'incident_id' => archive_id( '7' ) ) ) );
	} ),
);
foreach ( $illegal_transitions as $transition ) {
	archive_expect_transition_rejection( $transition[0], $transition[3], $transition[1], 'table-driven prohibited transition: ' . $transition[2] );
}

// T8: additional named exact rejection tests. Each proves the exact exception
// class, exact stable reason code, unchanged aggregate state, and unchanged
// uncommitted-event count via archive_expect_transition_rejection().

// A reset authorization cannot bind to a revoked revision: after the bound
// archive is revoked there is no active revision to authorize against.
$revoked_auth = finalized_case();
$revoked_auth->correct(
	array(),
	event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( 'a' ), 'target_snapshot_id' => archive_id( 'c' ), 'correction_operation_id' => archive_id( '9' ) ) ),
	event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ) ) )
);
$revoked_auth->request_reset( reset_requested_payload() );
archive_expect_transition_rejection( $revoked_auth, static function () use ( $revoked_auth ): void {
	$revoked_auth->authorize_reset( reset_authorized_payload() );
}, 'reset_not_eligible', 'T8-AUTHORIZE-AGAINST-REVOKED reset authorization against a revoked revision is rejected' );

// A non-finalized candidate cannot be revoked: correction/revocation requires an
// active finalized target.
$revoke_candidate = new GHCA_ACD_Archive_Case();
request_archive( $revoke_candidate, archive_id( 'a' ) );
$revoke_candidate->start_build( event_payload( 'ArchiveBuildStarted', array( 'archive_id' => archive_id( 'a' ), 'build_attempt_id' => archive_id( 'b' ), 'start_phase' => 'capturing', 'snapshot_id' => null ) ) );
archive_expect_transition_rejection( $revoke_candidate, static function () use ( $revoke_candidate ): void {
	$revoke_candidate->correct(
		array(),
		event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( 'a' ), 'target_snapshot_id' => archive_id( 'c' ), 'correction_operation_id' => archive_id( '9' ) ) ),
		event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ) ) )
	);
}, 'active_archive_mismatch', 'T8-REVOKE-NON-FINALIZED revoking a non-finalized candidate is rejected' );

// A reset outcome cannot be recorded before the execution claim commits.
$outcome_before_claim = authorized_reset_case();
archive_expect_transition_rejection( $outcome_before_claim, static function () use ( $outcome_before_claim ): void {
	$outcome_before_claim->complete_reset( event_payload( 'ResetCompleted', array( 'reset_operation_id' => archive_id( 'f' ) ) ) );
}, 'invalid_reset_transition', 'T8-OUTCOME-BEFORE-CLAIM a reset outcome cannot be recorded before the claim' );

// A generic (initial) archive request is rejected when replacement lineage is
// required after a revocation.
$needs_replacement = finalized_case();
$needs_replacement->correct(
	array(),
	event_payload( 'CorrectionRequested', array( 'target_archive_id' => archive_id( 'a' ), 'target_snapshot_id' => archive_id( 'c' ), 'correction_operation_id' => archive_id( '9' ) ) ),
	event_payload( 'ArchiveRevoked', array( 'target_archive_id' => archive_id( 'a' ), 'correction_operation_id' => archive_id( '9' ) ) )
);
archive_expect_transition_rejection( $needs_replacement, static function () use ( $needs_replacement ): void {
	request_archive( $needs_replacement, archive_id( '0' ), 2 );
}, 'replacement_required', 'T8-GENERIC-REQUEST-REQUIRES-REPLACEMENT a generic archive request is rejected when replacement lineage is required' );

archive_finish();
