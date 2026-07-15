<?php
require __DIR__ . '/bootstrap.php';

function ari_case_to_verifying(): GHCA_ACD_Archive_Case {
	$case = new GHCA_ACD_Archive_Case();
	$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
	$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
	$case->materialize_ledger( remediation_payload( 'LedgerMaterialized' ) );
	$case->materialize_packet( remediation_payload( 'PacketMaterialized' ) );
	return $case;
}

function ari_finalized_case(): GHCA_ACD_Archive_Case {
	$case = ari_case_to_verifying();
	$case->verify_and_finalize( remediation_payload( 'ArchiveVerified' ), remediation_payload( 'ArchiveFinalized' ) );
	return $case;
}

function ari_authorized_reset(): GHCA_ACD_Archive_Case {
	$case = ari_finalized_case();
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
	$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
	return $case;
}

function ari_claimed_reset(): GHCA_ACD_Archive_Case {
	$case = ari_authorized_reset();
	$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed' ) );
	return $case;
}

/** Candidate failed by pre-capture drift; the drift condition remains OPEN. */
function ari_open_drift_candidate(): GHCA_ACD_Archive_Case {
	$case = new GHCA_ACD_Archive_Case();
	$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
	$case->detect_source_drift(
		remediation_payload( 'SourceDriftDetected', array( 'snapshot_id' => null, 'detection_point' => 'pre_capture' ) ),
		remediation_payload( 'ArchiveFailed' )
	);
	return $case;
}

/** Finalized active archive with an OPEN post-finalization drift condition. */
function ari_open_drift_finalized(): GHCA_ACD_Archive_Case {
	$case = ari_finalized_case();
	$case->detect_source_drift( remediation_payload( 'SourceDriftDetected' ) );
	return $case;
}

$case = ari_case_to_verifying();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record( 'ArchiveVerified', remediation_payload( 'ArchiveVerified' ) );
}, 'incomplete_finalization_batch', 'ATOMIC-VERIFY-STANDALONE' );

$history = ari_case_to_verifying()->uncommitted_events();
$history[] = new GHCA_ACD_Archive_Event( 'ArchiveVerified', 1, remediation_payload( 'ArchiveVerified' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
archive_expect_exception( static function () use ( $history ): void {
	GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $history );
}, 'ATOMIC-REPLAY-STANDALONE-VERIFY rejects an illegal atomic fragment', GHCA_ACD_Archive_Transition_Exception::class, 'incomplete_finalization_batch' );

$case = ari_case_to_verifying();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveFinalized', 'payload' => remediation_payload( 'ArchiveFinalized' ) ),
		array( 'type' => 'ArchiveVerified', 'payload' => remediation_payload( 'ArchiveVerified' ) ),
	) );
}, 'invalid_finalization_batch', 'ATOMIC-FINALIZATION-ORDER' );

$case = ari_case_to_verifying();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveVerified', 'payload' => remediation_payload( 'ArchiveVerified' ) ),
		array( 'type' => 'ArchiveFinalized', 'payload' => remediation_payload( 'ArchiveFinalized' ) ),
		array( 'type' => 'ArchiveFailed', 'payload' => remediation_payload( 'ArchiveFailed', array( 'phase' => 'verifying' ) ) ),
	) );
}, 'invalid_finalization_batch', 'ATOMIC-FINALIZATION-SURPLUS' );

$case = ari_case_to_verifying();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->verify_and_finalize(
		remediation_payload( 'ArchiveVerified' ),
		remediation_payload( 'ArchiveFinalized', array( 'snapshot_digest' => remediation_digest( '8' ) ) )
	);
}, 'finalization_batch_mismatch', 'ATOMIC-FINALIZATION-BINDINGS' );

$case = ari_finalized_case();
$correction = remediation_payload( 'CorrectionRequested' );
$revocation = remediation_payload( 'ArchiveRevoked', array( 'invalidated_reset_operation_ids' => array( remediation_id( 'f' ) ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $correction, $revocation ): void {
	$case->correct( array(), $correction, $revocation );
}, 'correction_invalidation_mismatch', 'ATOMIC-CORRECTION-INVALIDATION-LIST' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct(
		array( remediation_payload( 'ResetOperationInvalidated', array( 'invalidating_reference_id' => remediation_id( '8' ) ) ) ),
		remediation_payload( 'CorrectionRequested' ),
		remediation_payload( 'ArchiveRevoked', array( 'invalidated_reset_operation_ids' => array( remediation_id( 'f' ) ) ) )
	);
}, 'correction_invalidation_mismatch', 'ATOMIC-CORRECTION-INVALIDATION-REFERENCE' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveRevoked', 'payload' => remediation_payload( 'ArchiveRevoked' ) ),
		array( 'type' => 'CorrectionRequested', 'payload' => remediation_payload( 'CorrectionRequested' ) ),
	) );
}, 'invalid_correction_batch', 'ATOMIC-CORRECTION-ORDER' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ResetRequested', 'payload' => remediation_payload( 'ResetRequested' ) ),
		array( 'type' => 'SourceDriftDetected', 'payload' => remediation_payload( 'SourceDriftDetected' ) ),
	) );
}, 'invalid_drift_batch', 'ATOMIC-TRIAL-BATCH-RESET-THEN-INCIDENT is not an approved decision shape' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift( remediation_payload( 'SourceDriftDetected', array( 'snapshot_id' => null, 'detection_point' => 'pre_capture' ) ) );
}, 'incomplete_drift_batch', 'ATOMIC-PREFINAL-DRIFT-FAILURE' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveFailed', 'payload' => remediation_payload( 'ArchiveFailed' ) ),
		array( 'type' => 'SourceDriftDetected', 'payload' => remediation_payload( 'SourceDriftDetected', array( 'snapshot_id' => null, 'detection_point' => 'pre_capture' ) ) ),
	) );
}, 'invalid_drift_batch', 'ATOMIC-PREFINAL-DRIFT-ORDER' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift(
		remediation_payload( 'SourceDriftDetected', array( 'snapshot_id' => null, 'detection_point' => 'pre_capture' ) ),
		remediation_payload( 'ArchiveFailed', array( 'build_attempt_id' => remediation_id( '8' ) ) )
	);
}, 'drift_failure_mismatch', 'ATOMIC-PREFINAL-DRIFT-BINDINGS' );

// Closed-grammar negatives: arbitrary batching, claim+outcome, standalone
// invalidation, extra/misordered events, and replayed malformed batches.
$case = ari_case_to_verifying();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveBuildStarted', 'payload' => remediation_payload( 'ArchiveBuildStarted' ) ),
		array( 'type' => 'LedgerMaterialized', 'payload' => remediation_payload( 'LedgerMaterialized' ) ),
	) );
}, 'unapproved_decision_shape', 'GRAMMAR-ARBITRARY-BATCH arbitrary event batching fails closed' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'ResetExecutionClaimed', 'payload' => remediation_payload( 'ResetExecutionClaimed' ) ),
		array( 'type' => 'ResetCompleted', 'payload' => remediation_payload( 'ResetCompleted' ) ),
	) );
}, 'unapproved_decision_shape', 'GRAMMAR-CLAIM-WITH-OUTCOME the claim must commit before any reset outcome can exist' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record( 'ResetOperationInvalidated', remediation_payload( 'ResetOperationInvalidated' ) );
}, 'unapproved_decision_shape', 'GRAMMAR-STANDALONE-INVALIDATION invalidation cannot commit outside its intervening decision' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'CorrectionRequested', 'payload' => remediation_payload( 'CorrectionRequested' ) ),
		array( 'type' => 'ArchiveRevoked', 'payload' => remediation_payload( 'ArchiveRevoked' ) ),
		array( 'type' => 'ResetRequested', 'payload' => remediation_payload( 'ResetRequested' ) ),
	) );
}, 'invalid_correction_batch', 'GRAMMAR-EXTRA-EVENT a correction decision cannot carry surplus events' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_batch( array(
		array( 'type' => 'UnprotectedResetDetected', 'payload' => remediation_payload( 'UnprotectedResetDetected' ) ),
		array( 'type' => 'IntegrityViolationDetected', 'payload' => remediation_payload( 'IntegrityViolationDetected' ) ),
	) );
}, 'invalid_incident_batch', 'GRAMMAR-INCIDENT-EXTRA two incident detections cannot share one decision' );

$malformed_history = ari_authorized_reset()->uncommitted_events();
$malformed_history[] = new GHCA_ACD_Archive_Event( 'ResetExecutionClaimed', 1, remediation_payload( 'ResetExecutionClaimed' ), array( 'decision_index' => 0, 'decision_size' => 2 ) );
$malformed_history[] = new GHCA_ACD_Archive_Event( 'ResetCompleted', 1, remediation_payload( 'ResetCompleted' ), array( 'decision_index' => 1, 'decision_size' => 2 ) );
archive_expect_exception( static function () use ( $malformed_history ): void {
	GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $malformed_history );
}, 'ATOMIC-REPLAY-MALFORMED-BATCH replayed claim+outcome decision fails identically', GHCA_ACD_Archive_Transition_Exception::class, 'unapproved_decision_shape' );

$case = ari_finalized_case();
$case->detect_source_drift( remediation_payload( 'SourceDriftDetected' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
}, 'incident_blocked', 'INCIDENT-BLOCKS-RESET-REQUEST' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed', array( 'gateway_key' => 'other_gateway_v1' ) ) );
}, 'reset_gateway_mismatch', 'RESET-CLAIM-GATEWAY' );

$authorized_state = ari_authorized_reset()->state()['resets'][ remediation_id( 'f' ) ];
archive_check( remediation_scope() === $authorized_state['scope'], 'RESET-STATE-SCOPE retains the exact bounded reset scope' );
archive_check( 'bounded_reevaluation' === $authorized_state['consent_mode'], 'RESET-STATE-CONSENT retains the consent mode' );
archive_check( '2026-07-13T13:00:00Z' === $authorized_state['request_valid_until_gmt'], 'RESET-STATE-CONSENT-WINDOW retains request validity' );
archive_check( 'learndash_supported_v1' === $authorized_state['gateway_key'], 'RESET-STATE-GATEWAY retains the authorized gateway' );
archive_check( '2026-07-13T12:35:00Z' === $authorized_state['issued_at_gmt'] && '2026-07-13T12:55:00Z' === $authorized_state['expires_at_gmt'], 'RESET-STATE-AUTH-WINDOW retains issue and expiry facts' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->cancel_reset( remediation_payload( 'ResetCancelled', array( 'authorization_id' => null ) ) );
}, 'authorization_mismatch', 'RESET-CANCEL-PRESERVES-AUTHORIZATION' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct(
		array( remediation_payload( 'ResetOperationInvalidated', array( 'authorization_id' => null ) ) ),
		remediation_payload( 'CorrectionRequested' ),
		remediation_payload( 'ArchiveRevoked', array( 'invalidated_reset_operation_ids' => array( remediation_id( 'f' ) ) ) )
	);
}, 'authorization_mismatch', 'RESET-INVALIDATION-PRESERVES-AUTHORIZATION' );

$case = ari_claimed_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->complete_reset( remediation_payload( 'ResetCompleted', array( 'upstream_operation_id' => 'ld-reset:tenant-1/op-other' ) ) );
}, 'upstream_operation_mismatch', 'RESET-OUTCOME-UPSTREAM' );

$case = ari_claimed_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_reset_failed_safe( remediation_payload( 'ResetFailedSafe', array( 'unchanged_source_fingerprint' => remediation_digest( '8' ) ) ) );
}, 'source_fingerprint_mismatch', 'RESET-FAILED-SAFE-SOURCE' );

$case = ari_claimed_reset();
$case->record_reset_outcome_uncertain( remediation_payload( 'ResetOutcomeBecameUncertain' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->reconcile_reset_as_no_change( remediation_payload( 'ResetReconciledAsNoChange', array( 'source_fingerprint' => remediation_digest( '8' ) ) ) );
}, 'source_fingerprint_mismatch', 'RESET-RECONCILE-NO-CHANGE-SOURCE' );

$case = ari_claimed_reset();
$case->record_reset_outcome_uncertain( remediation_payload( 'ResetOutcomeBecameUncertain' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->require_reset_remediation( remediation_payload( 'ResetRemediationRequired', array( 'affected_scope_digest' => remediation_digest( '8' ) ) ) );
}, 'reset_scope_mismatch', 'RESET-REMEDIATION-SCOPE' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->expire_reset_authorization( remediation_payload( 'ResetAuthorizationExpired', array( 'scheduled_expires_at_gmt' => '2026-07-13T12:54:00Z' ) ) );
}, 'authorization_expiry_mismatch', 'RESET-EXPLICIT-EXPIRY' );

$case = ari_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->authorize_reset( remediation_payload( 'ResetAuthorized', array( 'expires_at_gmt' => '2026-07-13T13:05:00Z' ) ) );
}, 'consent_window_mismatch', 'RESET-CONSENT-WINDOW' );

// Reset and incident scopes bind to the immutable Archive Case.
$cross_scopes = array(
	'BIND-RESET-SCOPE-EMPLOYEE cross-employee reset scope is rejected' => array( 'employee_user_id_decimal' => '43' ),
	'BIND-RESET-SCOPE-PROGRAM cross-program reset scope is rejected'   => array( 'program_key' => 'orientation' ),
	'BIND-RESET-SCOPE-CYCLE cross-cycle reset scope is rejected'       => array( 'cycle_key' => 'v1|calendar_year|1|2025-01-01T05:00:00Z|2026-01-01T05:00:00Z|America/Toronto|[)' ),
);
foreach ( $cross_scopes as $scope_message => $scope_override ) {
	$case = ari_finalized_case();
	$foreign_scope = array_replace( remediation_scope(), $scope_override );
	archive_expect_transition_rejection( $case, static function () use ( $case, $foreign_scope ): void {
		$case->request_reset( remediation_payload( 'ResetRequested', array(
			'scope'        => $foreign_scope,
			'scope_digest' => GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $foreign_scope ),
		) ) );
	}, 'reset_scope_case_mismatch', $scope_message );
}

$case = ari_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
$broadened_scope = remediation_scope();
$broadened_scope['course_ids'][] = '30';
$broadened_digest = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $broadened_scope );
archive_expect_transition_rejection( $case, static function () use ( $case, $broadened_digest ): void {
	$case->authorize_reset( remediation_payload( 'ResetAuthorized', array( 'scope_digest' => $broadened_digest ) ) );
}, 'reset_not_eligible', 'BIND-RESET-SCOPE-COURSES an authorization covering unauthorized courses is rejected' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case, $broadened_digest ): void {
	$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed', array( 'scope_digest' => $broadened_digest ) ) );
}, 'reset_claim_blocked', 'BIND-RESET-CLAIM-COURSES a claim broadening the course scope is rejected' );

$case = ari_finalized_case();
$foreign_incident_scope = array_replace( remediation_scope(), array( 'employee_user_id_decimal' => '43' ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $foreign_incident_scope ): void {
	$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $foreign_incident_scope ) ) );
}, 'incident_scope_case_mismatch', 'BIND-INCIDENT-SCOPE unprotected-reset detection scope binds to the immutable case' );

// T1: an initial ResetRequested must bind its exact course scope to the finalized
// archive/snapshot subject-scope evidence. An internally consistent caller scope
// and digest whose courses differ from the authorized set fails closed.
$t1_reset_course_variants = array(
	'T1-RESET-COURSE-BROADENED an initially broadened reset course scope is rejected'         => array( '10', '20', '30' ),
	'T1-RESET-COURSE-NARROWED an initially narrowed reset course scope is rejected'           => array( '10' ),
	'T1-RESET-COURSE-SUBSTITUTED an initially substituted reset course scope is rejected'     => array( '10', '99' ),
);
foreach ( $t1_reset_course_variants as $t1_message => $t1_courses ) {
	$case = ari_finalized_case();
	$t1_scope = array_replace( remediation_scope(), array( 'course_ids' => $t1_courses ) );
	archive_expect_transition_rejection( $case, static function () use ( $case, $t1_scope ): void {
		$case->request_reset( remediation_payload( 'ResetRequested', array(
			'scope'        => $t1_scope,
			'scope_digest' => GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $t1_scope ),
		) ) );
	}, 'reset_scope_evidence_mismatch', $t1_message );
}

// The exact authorized course scope is accepted for evaluation.
$case = ari_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
archive_check( remediation_scope() === $case->state()['resets'][ remediation_id( 'f' ) ]['scope'], 'T1-RESET-COURSE-EXACT the exact authorized course scope is accepted' );

// T1: the equivalent binding applies to unprotected-reset incident scopes — an
// unrelated course in the incident scope is rejected even when subject/program/cycle match.
$case = ari_finalized_case();
$t1_incident_scope = array_replace( remediation_scope(), array( 'course_ids' => array( '10', '99' ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $t1_incident_scope ): void {
	$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $t1_incident_scope ) ) );
}, 'incident_scope_evidence_mismatch', 'T1-INCIDENT-COURSE an unprotected-reset scope containing an unrelated course is rejected' );

// The same evidence binding applies while the retained revision is still a
// candidate. active_archive_id is null in this phase, but current_archive_id
// already carries the reviewed subject-scope evidence.
$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $t1_incident_scope ): void {
	$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $t1_incident_scope ) ) );
}, 'incident_scope_evidence_mismatch', 'T1-INCIDENT-COURSE-PREFINALIZATION a candidate revision rejects an unrelated incident course scope' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected' ) );
archive_check( 'OPEN' === $case->state()['unprotected_reset_state'], 'T1-INCIDENT-COURSE-PREFINALIZATION-EXACT a candidate revision accepts its exact incident course scope' );

$case = ari_finalized_case();
$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $t1_incident_scope ): void {
	$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $t1_incident_scope ) ) );
}, 'incident_scope_evidence_mismatch', 'T1-INCIDENT-COURSE-CORRECTION-TARGET a revoked correction target rejects an unrelated incident course scope' );

$case = ari_finalized_case();
$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected' ) );
archive_check( 'OPEN' === $case->state()['unprotected_reset_state'], 'T1-INCIDENT-COURSE-CORRECTION-TARGET-EXACT a revoked correction target accepts its exact incident course scope' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case, $t1_incident_scope ): void {
	$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $t1_incident_scope ) ) );
}, 'incident_scope_evidence_mismatch', 'T1-INCIDENT-COURSE-CANCELLED-RETAINED a cancelled candidate still rejects an unrelated incident course scope' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected' ) );
archive_check( 'OPEN' === $case->state()['unprotected_reset_state'], 'T1-INCIDENT-COURSE-CANCELLED-RETAINED-EXACT a cancelled candidate accepts its exact retained incident course scope' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
$cancelled_history = $case->uncommitted_events();
$cancelled_history[] = new GHCA_ACD_Archive_Event(
	'UnprotectedResetDetected',
	1,
	remediation_payload( 'UnprotectedResetDetected', array( 'scope' => $t1_incident_scope ) ),
	array( 'decision_index' => 0, 'decision_size' => 1 )
);
archive_expect_exception( static function () use ( $cancelled_history ): void {
	GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $cancelled_history );
}, 'T1-INCIDENT-COURSE-CANCELLED-REPLAY retained candidate scope binding fails closed during replay', GHCA_ACD_Archive_Transition_Exception::class, 'incident_scope_evidence_mismatch' );

$recorded_cancelled_history = array();
$previous_digest = null;
foreach ( array_slice( $cancelled_history, 0, 2 ) as $index => $event ) {
	$event_id = 0 === $index ? remediation_id( '4' ) : remediation_id( '5' );
	$context_overrides = 0 === $index
		? array()
		: array( 'causation_event_id' => remediation_id( '4' ), 'command_id' => remediation_id( '8' ) );
	$recorded = $event->with_recording_context( remediation_recording_context( $event_id, (string) ( $index + 1 ), $previous_digest, $context_overrides ) );
	$recorded_cancelled_history[] = $recorded;
	$previous_digest = $recorded->event_digest();
}
$foreign_authority = remediation_authority_context();
$foreign_authority['subject_scope_digest'] = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $t1_incident_scope );
$recorded_foreign_incident = $cancelled_history[2]->with_recording_context( remediation_recording_context(
	remediation_id( '6' ),
	'3',
	$previous_digest,
	array(
		'archive_id' => null,
		'authority_context' => $foreign_authority,
		'causation_event_id' => remediation_id( '5' ),
		'command_id' => remediation_id( '9' ),
	)
) );
$recorded_cancelled_history[] = $recorded_foreign_incident;
archive_expect_exception( static function () use ( $recorded_cancelled_history ): void {
	GHCA_ACD_Archive_Case::rehydrate( $recorded_cancelled_history );
}, 'T1-INCIDENT-COURSE-CANCELLED-RECORDED-REPLAY validly hashed foreign incident scope fails closed during authoritative replay', GHCA_ACD_Archive_Transition_Exception::class, 'incident_scope_evidence_mismatch' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct( array(), remediation_payload( 'CorrectionRequested', array( 'affected_scope_digest' => remediation_digest( '9' ) ) ), remediation_payload( 'ArchiveRevoked' ) );
}, 'correction_scope_mismatch', 'BIND-CORRECTION-SCOPE affected scope must match the target archive subject scope' );

$case = ari_authorized_reset();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_integrity_violation(
		remediation_payload( 'IntegrityViolationDetected' ),
		array( remediation_payload( 'ResetOperationInvalidated', array( 'invalidating_reference_id' => remediation_id( '9' ) ) ) )
	);
}, 'incident_invalidation_mismatch', 'BIND-INCIDENT-INVALIDATION-REFERENCE invalidations must reference the committed incident' );

$case = ari_authorized_reset();
$case->detect_integrity_violation(
	remediation_payload( 'IntegrityViolationDetected' ),
	array( remediation_payload( 'ResetOperationInvalidated', array( 'invalidating_reference_id' => remediation_id( '7' ) ) ) )
);
archive_check(
	'OPEN' === $case->state()['integrity_state'] && 'INVALIDATED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'],
	'INCIDENT-ATOMIC-INVALIDATION a blocking incident invalidates the pre-claim reset in one decision'
);

// Detection point must reconcile with the actual lifecycle state.
$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift( remediation_payload( 'SourceDriftDetected', array( 'detection_point' => 'pre_capture' ) ) );
}, 'detection_point_mismatch', 'DETECTION-POINT-FINALIZED a finalized archive cannot report pre-capture drift' );

$case = new GHCA_ACD_Archive_Case();
$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift(
		remediation_payload( 'SourceDriftDetected', array( 'snapshot_id' => null ) ),
		remediation_payload( 'ArchiveFailed' )
	);
}, 'detection_point_mismatch', 'DETECTION-POINT-CANDIDATE a capturing candidate cannot report post-finalization drift' );

$case = ari_authorized_reset();
$case->detect_source_drift(
	remediation_payload( 'SourceDriftDetected', array( 'detection_point' => 'pre_claim' ) ),
	null,
	array( remediation_payload( 'ResetOperationInvalidated', array( 'invalidating_reference_id' => remediation_id( '7' ) ) ) )
);
archive_check(
	'OPEN' === $case->state()['source_drift_state'] && 'INVALIDATED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'],
	'DETECTION-POINT-PRE-CLAIM drift before claim invalidates the authorized reset atomically'
);

$case = ari_finalized_case();
$case->detect_source_drift( remediation_payload( 'SourceDriftDetected' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->resolve_source_drift_restored( remediation_payload( 'SourceDriftResolved', array( 'verified_source_fingerprint' => remediation_digest( '8' ) ) ) );
}, 'drift_resolution_unproven', 'INCIDENT-RESTORATION-PROOF' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift( remediation_payload( 'SourceDriftDetected', array( 'expected_source_fingerprint' => remediation_digest( '8' ) ) ) );
}, 'drift_expected_fingerprint_mismatch', 'INCIDENT-DETECTION-EXPECTED-FINGERPRINT' );

// Source-drift recovery: restoration retries the same revision; replacement
// rebase must cancel/revoke and re-request in one serialized decision.
$case = ari_open_drift_candidate();
$case->resolve_source_drift_restored( remediation_payload( 'SourceDriftResolved' ) );
$case->request_retry( remediation_payload( 'ArchiveRetryRequested', array( 'resume_phase' => 'capturing' ) ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( '7' ), 'retry_ordinal' => 1 ) ) );
archive_check(
	'RESOLVED' === $case->state()['source_drift_state'] && 'CAPTURING' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'],
	'DRIFT-RECOVERY-RESTORED verified restoration reopens the same revision against its original fingerprint'
);

$rebase_resolved = remediation_payload( 'SourceDriftResolved', array(
	'resolution_kind' => 'replacement_rebased',
	'verified_source_fingerprint' => remediation_digest( '9' ),
	'resolution_reference_id' => remediation_id( '0' ),
) );
$rebase_request = remediation_payload( 'ArchiveRequested', array(
	'archive_id' => remediation_id( '0' ),
	'revision_number' => 2,
	'reviewed_source_fingerprint' => remediation_digest( '9' ),
) );
unset( $rebase_request['request_kind'] );
$rebase_request['request_kind'] = 'initial';

$case = ari_open_drift_candidate();
$case->resolve_source_drift_rebased( $rebase_resolved, $rebase_request, remediation_payload( 'ArchiveCancelled' ) );
$rebased_state = $case->state();
archive_check(
	'RESOLVED' === $rebased_state['source_drift_state'] && 'CANCELLED' === $rebased_state['revisions'][ remediation_id( 'a' ) ]['build_state'] && 'REQUESTED' === $rebased_state['revisions'][ remediation_id( '0' ) ]['build_state'],
	'DRIFT-RECOVERY-REBASE-CANDIDATE cancellation, resolution, and the newly reviewed request commit as one decision'
);

$case = ari_open_drift_finalized();
$rebase_replacement = remediation_payload( 'ReplacementArchiveRequested', array(
	'archive_id' => remediation_id( '0' ),
	'revision_number' => 2,
	'reviewed_source_fingerprint' => remediation_digest( '9' ),
) );
$case->resolve_source_drift_rebased(
	$rebase_resolved,
	$rebase_replacement,
	null,
	remediation_payload( 'CorrectionRequested' ),
	remediation_payload( 'ArchiveRevoked' )
);
$rebased_final_state = $case->state();
archive_check(
	'RESOLVED' === $rebased_final_state['source_drift_state'] && 'REVOKED' === $rebased_final_state['revisions'][ remediation_id( 'a' ) ]['validity_state'] && 'REQUESTED' === $rebased_final_state['revisions'][ remediation_id( '0' ) ]['build_state'],
	'DRIFT-RECOVERY-REBASE-FINALIZED post-finalization rebase revokes and replaces in one serialized decision'
);

$case = ari_open_drift_finalized();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->resolve_source_drift_restored( remediation_payload( 'SourceDriftResolved', array(
		'resolution_kind' => 'replacement_rebased',
		'verified_source_fingerprint' => remediation_digest( '9' ),
	) ) );
}, 'drift_rebase_requires_new_request', 'INCIDENT-REPLACEMENT-REBASE standalone rebase resolution is prohibited' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case, $rebase_resolved, $rebase_request ): void {
	$case->resolve_source_drift_rebased( $rebase_resolved, $rebase_request );
}, 'invalid_drift_recovery', 'DRIFT-RECOVERY-MISSING-CANCELLATION the drifted candidate must be cancelled in the recovery decision' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case, $rebase_resolved ): void {
	$case->record_batch( array(
		array( 'type' => 'SourceDriftResolved', 'payload' => $rebase_resolved ),
		array( 'type' => 'ArchiveCancelled', 'payload' => remediation_payload( 'ArchiveCancelled' ) ),
	) );
}, 'drift_rebase_requires_new_request', 'DRIFT-RECOVERY-MISSING-REQUEST fragmented recovery without the new request fails closed' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case, $rebase_resolved, $rebase_request ): void {
	$wrong_fingerprint_request = array_replace( $rebase_request, array( 'reviewed_source_fingerprint' => remediation_digest( '8' ) ) );
	$case->resolve_source_drift_rebased( $rebase_resolved, $wrong_fingerprint_request, remediation_payload( 'ArchiveCancelled' ) );
}, 'drift_recovery_fingerprint_mismatch', 'DRIFT-RECOVERY-WRONG-FINGERPRINT the request must carry the newly verified fingerprint' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case, $rebase_resolved, $rebase_request ): void {
	$wrong_reference = array_replace( $rebase_resolved, array( 'resolution_reference_id' => remediation_id( '5' ) ) );
	$case->resolve_source_drift_rebased( $wrong_reference, $rebase_request, remediation_payload( 'ArchiveCancelled' ) );
}, 'drift_recovery_reference_mismatch', 'DRIFT-RECOVERY-WRONG-REFERENCE the resolution must reference the replacement request' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case, $rebase_request ): void {
	$case->record_batch( array(
		array( 'type' => 'ArchiveCancelled', 'payload' => remediation_payload( 'ArchiveCancelled' ) ),
		array( 'type' => 'ArchiveRequested', 'payload' => $rebase_request ),
	) );
}, 'unapproved_decision_shape', 'DRIFT-RECOVERY-FRAGMENTED recovery without the resolution event fails closed' );

// T5: a standalone cancellation of the drift-affected candidate is prohibited
// while drift is open. Otherwise a later rebase batch would find the candidate
// already cancelled and omit the cancellation the PRD requires in one decision.
$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->cancel_archive( remediation_payload( 'ArchiveCancelled' ) );
}, 'source_drift_blocked', 'T5-DRIFT-BLOCKS-STANDALONE-CANCEL the drift-affected candidate cannot be cancelled outside the rebase decision' );
// The only accepted path cancels, resolves, and re-requests as one serialized
// decision (the resolution is applied first, so the in-batch cancellation is valid).
$case = ari_open_drift_candidate();
$case->resolve_source_drift_rebased( $rebase_resolved, $rebase_request, remediation_payload( 'ArchiveCancelled' ) );
archive_check(
	'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'] && 'REQUESTED' === $case->state()['revisions'][ remediation_id( '0' ) ]['build_state'],
	'T5-DRIFT-REBASE-ATOMIC the cancellation, resolution, and new reviewed request commit only as one approved decision'
);

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_retry( remediation_payload( 'ArchiveRetryRequested' ) );
}, 'source_drift_blocked', 'DRIFT-BLOCKS-RETRY retry is rejected while drift remains open' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->start_build( remediation_payload( 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( '7' ) ) ) );
}, 'source_drift_blocked', 'DRIFT-BLOCKS-BUILD-START build start is rejected while drift remains open' );

$case = ari_open_drift_candidate();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
}, 'source_drift_blocked', 'DRIFT-BLOCKS-CAPTURE capture is rejected while drift remains open' );

$case = ari_open_drift_finalized();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
}, 'correction_blocked', 'DRIFT-BLOCKS-CORRECTION standalone correction is rejected while drift remains open' );

// IntegrityIncidentDispositionRecorded.remaining_restrictions fail closed.
$case = ari_finalized_case();
$case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected' ) );
$case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded', array( 'remaining_restrictions' => array( 'manual_review_pending' ) ) ) );
archive_check( array( 'manual_review_pending' ) === $case->state()['integrity_remaining_restrictions'], 'INTEGRITY-RESTRICTIONS-PERSISTED the disposition restrictions are retained in state' );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
}, 'incident_blocked', 'INTEGRITY-RESTRICTIONS-FAIL-CLOSED an approved disposition with remaining restrictions still blocks reset' );

// T2: a later restriction-free disposition on a DIFFERENT incident must not erase
// the earlier unresolved restriction. The restriction remains effective.
$case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected', array( 'incident_id' => remediation_id( '8' ) ) ) );
$case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded', array( 'incident_id' => remediation_id( '8' ) ) ) );
archive_check( array( 'manual_review_pending' ) === $case->state()['integrity_remaining_restrictions'], 'T2-RESTRICTION-PERSISTS a later clean disposition on another incident does not clear the earlier restriction' );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
}, 'incident_blocked', 'T2-RESTRICTION-REMAINS-EFFECTIVE the earlier restriction still fails reset closed after another incident is dismissed clean' );

// T2: legitimate PRD resolution — a single incident disposed clean with no
// remaining restriction removes the operational block and reset is eligible.
$case = ari_finalized_case();
$case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected' ) );
$case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded' ) );
$case->request_reset( remediation_payload( 'ResetRequested' ) );
archive_check( 'REQUESTED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'T2-LEGITIMATE-RESOLUTION a clean restriction-free disposition removes the block per the PRD' );

$case = ari_finalized_case();
$case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected' ) );
$case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded', array( 'disposition_code' => 'confirmed_compromise' ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
}, 'incident_blocked', 'INTEGRITY-COMPROMISE-REMAINS-BLOCKED a confirmed compromise stays fail-closed after disposition' );

// T2: a confirmed compromise cannot be laundered by a later false-positive
// disposition of a different incident; the irreversible block survives, and a
// deterministic replay reproduces the identical blocking state.
$compromise_case = ari_finalized_case();
$compromise_case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected' ) );
$compromise_case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded', array( 'disposition_code' => 'confirmed_compromise' ) ) );
$compromise_case->detect_integrity_violation( remediation_payload( 'IntegrityViolationDetected', array( 'incident_id' => remediation_id( '8' ) ) ) );
$compromise_case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded', array( 'incident_id' => remediation_id( '8' ) ) ) );
archive_check( true === $compromise_case->state()['integrity_compromise_confirmed'], 'T2-COMPROMISE-PERSISTS a confirmed compromise flag survives a later clean disposition' );
archive_expect_transition_rejection( $compromise_case, static function () use ( $compromise_case ): void {
	$compromise_case->request_reset( remediation_payload( 'ResetRequested' ) );
}, 'incident_blocked', 'T2-COMPROMISE-THEN-FALSE-POSITIVE a later false-positive cannot make reset eligible after a prior confirmed compromise' );
$compromise_replay = GHCA_ACD_Archive_Case::rehydrate_uncommitted_for_testing( $compromise_case->uncommitted_events() );
archive_check( $compromise_replay->state() === $compromise_case->state(), 'T2-REPLAY-BLOCKING deterministic replay reproduces the identical integrity blocking state' );

$case = ari_open_drift_finalized();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->detect_source_drift( remediation_payload( 'SourceDriftDetected', array( 'incident_id' => remediation_id( '8' ) ) ) );
}, 'incident_already_open', 'INCIDENT-DOUBLE-OPEN a second drift incident cannot open over an unresolved one' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->dismiss_unprotected_reset( remediation_payload( 'UnprotectedResetDismissed' ) );
}, 'incident_not_open', 'INCIDENT-RESOLUTION-REQUIRES-OPEN dismissal without an open incident fails closed' );

$case = ari_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->record_integrity_disposition( remediation_payload( 'IntegrityIncidentDispositionRecorded' ) );
}, 'incident_not_open', 'INTEGRITY-RESOLUTION-REQUIRES-OPEN disposition without an open incident fails closed' );

$case = ari_finalized_case();
$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->dismiss_unprotected_reset( remediation_payload( 'UnprotectedResetDismissed', array( 'verified_source_fingerprint' => remediation_digest( '8' ) ) ) );
}, 'unprotected_reset_resolution_unproven', 'INCIDENT-UNPROTECTED-DISMISSAL-PROOF' );

$case = ari_claimed_reset();
$case->complete_reset( remediation_payload( 'ResetCompleted' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
}, 'post_reset_correction_forbidden', 'CORRECTION-AFTER-COMPLETED-RESET' );

$case = ari_finalized_case();
$case->detect_unprotected_reset( remediation_payload( 'UnprotectedResetDetected' ) );
$case->confirm_unprotected_reset( remediation_payload( 'UnprotectedResetConfirmed' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
}, 'post_reset_correction_forbidden', 'CORRECTION-AFTER-OUT-OF-BAND-RESET' );

$case = ari_claimed_reset();
$case->record_reset_outcome_uncertain( remediation_payload( 'ResetOutcomeBecameUncertain' ) );
$case->require_reset_remediation( remediation_payload( 'ResetRemediationRequired' ) );
$case->record_reset_remediated_restored( remediation_payload( 'ResetRemediatedRestored' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
}, 'post_reset_correction_forbidden', 'CORRECTION-AFTER-REMEDIATED-RESET' );

archive_finish();
