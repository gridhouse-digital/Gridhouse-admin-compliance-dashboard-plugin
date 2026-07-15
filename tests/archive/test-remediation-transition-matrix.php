<?php
require __DIR__ . '/bootstrap.php';

function matrix_requested_case(): GHCA_ACD_Archive_Case {
	$case = new GHCA_ACD_Archive_Case();
	$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
	return $case;
}

function matrix_capturing_case(): GHCA_ACD_Archive_Case {
	$case = matrix_requested_case();
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
	return $case;
}

function matrix_materializing_case(): GHCA_ACD_Archive_Case {
	$case = matrix_capturing_case();
	$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
	return $case;
}

function matrix_verifying_case(): GHCA_ACD_Archive_Case {
	$case = matrix_materializing_case();
	$case->materialize_ledger( remediation_payload( 'LedgerMaterialized' ) );
	$case->materialize_packet( remediation_payload( 'PacketMaterialized' ) );
	return $case;
}

function matrix_failed_before_snapshot(): GHCA_ACD_Archive_Case {
	$case = matrix_capturing_case();
	$case->fail_archive( remediation_payload( 'ArchiveFailed' ) );
	return $case;
}

function matrix_finalized_case(): GHCA_ACD_Archive_Case {
	$case = matrix_verifying_case();
	$case->verify_and_finalize( remediation_payload( 'ArchiveVerified' ), remediation_payload( 'ArchiveFinalized' ) );
	return $case;
}

$case = matrix_failed_before_snapshot();
$case->request_retry( remediation_payload( 'ArchiveRetryRequested' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->start_build( remediation_payload( 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( '7' ), 'retry_ordinal' => 0 ) ) );
}, 'retry_ordinal_mismatch', 'TRANSITION-RETRY-ORDINAL' );

$case = matrix_failed_before_snapshot();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_retry( remediation_payload( 'ArchiveRetryRequested', array( 'resume_phase' => 'materializing' ) ) );
}, 'invalid_retry_phase', 'TRANSITION-RETRY-BEFORE-SNAPSHOT' );

$case = matrix_materializing_case();
$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'phase' => 'materializing', 'sealed_snapshot_id' => remediation_id( 'c' ) ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_retry( remediation_payload( 'ArchiveRetryRequested', array( 'sealed_snapshot_id' => remediation_id( 'c' ), 'resume_phase' => 'capturing' ) ) );
}, 'invalid_retry_phase', 'TRANSITION-RETRY-SEALED-SNAPSHOT' );

$case = matrix_capturing_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'phase' => 'verifying' ) ) );
}, 'failure_phase_mismatch', 'TRANSITION-FAILURE-PHASE' );

// PRD 6.2: REQUESTED -> FAILED before any build attempt, then explicit retry.
$case = matrix_requested_case();
$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'build_attempt_id' => null, 'phase' => 'requested' ) ) );
archive_check( 'FAILED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-REQUESTED-FAILED a revision may fail before any build attempt' );
$case->request_retry( remediation_payload( 'ArchiveRetryRequested', array( 'prior_build_attempt_id' => null ) ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( '7' ), 'retry_ordinal' => 0 ) ) );
archive_check( 'CAPTURING' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-REQUESTED-FAILED-RETRY a pre-attempt failure retries into capture' );

$case = matrix_requested_case();
archive_expect_exception( static function () use ( $case ): void {
	$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'build_attempt_id' => null, 'phase' => 'capturing' ) ) );
}, 'TRANSITION-REQUESTED-FAILED-SCHEMA a null attempt is valid only for the requested phase', InvalidArgumentException::class );

// PRD 6.2: MATERIALIZING -> CANCELLED and VERIFYING -> CANCELLED.
$case = matrix_materializing_case();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled' ) );
archive_check( 'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-MATERIALIZING-CANCELLED cancellation wins during materialization' );

$case = matrix_verifying_case();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled' ) );
archive_check( 'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-VERIFYING-CANCELLED cancellation wins during verification' );

// PRD 6.2: FAILED -> VERIFYING retry of complete candidates.
$case = matrix_verifying_case();
$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'phase' => 'verifying', 'sealed_snapshot_id' => remediation_id( 'c' ) ) ) );
$case->request_retry( remediation_payload( 'ArchiveRetryRequested', array( 'resume_phase' => 'verifying', 'sealed_snapshot_id' => remediation_id( 'c' ) ) ) );
$case->start_build( remediation_payload( 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( '7' ), 'start_phase' => 'verifying', 'retry_ordinal' => 1, 'snapshot_id' => remediation_id( 'c' ) ) ) );
archive_check( 'VERIFYING' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-FAILED-VERIFYING verification retry of complete candidates is permitted' );

// PRD 6.2: FAILED -> CANCELLED.
$case = matrix_failed_before_snapshot();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled' ) );
archive_check( 'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'TRANSITION-FAILED-CANCELLED a failed candidate can be abandoned' );

$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested', array( 'consent_mode' => 'single_use' ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->defer_reset( remediation_payload( 'ResetDeferred' ) );
}, 'consent_mode_mismatch', 'TRANSITION-DEFER-REQUIRES-BOUNDED-CONSENT' );

$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->defer_reset( remediation_payload( 'ResetDeferred', array( 'consent_expires_at_gmt' => '2026-07-13T12:59:59Z' ) ) );
}, 'consent_window_mismatch', 'TRANSITION-DEFER-CONSENT-WINDOW' );

// PRD 6.4: DEFERRED -> AUTHORIZED / REJECTED / CANCELLED / INVALIDATED.
function matrix_deferred_case(): GHCA_ACD_Archive_Case {
	$case = matrix_finalized_case();
	$case->request_reset( remediation_payload( 'ResetRequested' ) );
	$case->defer_reset( remediation_payload( 'ResetDeferred' ) );
	return $case;
}

$case = matrix_deferred_case();
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
archive_check( 'AUTHORIZED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-DEFERRED-AUTHORIZED a deferred reset may authorize while consent remains valid' );

$case = matrix_deferred_case();
$case->reject_reset( remediation_payload( 'ResetRejected' ) );
archive_check( 'REJECTED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-DEFERRED-REJECTED a deferred reset may be terminally rejected' );

$case = matrix_deferred_case();
$case->cancel_reset( remediation_payload( 'ResetCancelled' ) );
archive_check( 'CANCELLED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-DEFERRED-CANCELLED a deferred reset may be withdrawn' );

$case = matrix_deferred_case();
$case->correct(
	array( remediation_payload( 'ResetOperationInvalidated', array( 'authorization_id' => null ) ) ),
	remediation_payload( 'CorrectionRequested' ),
	remediation_payload( 'ArchiveRevoked', array( 'invalidated_reset_operation_ids' => array( remediation_id( 'f' ) ) ) )
);
archive_check( 'INVALIDATED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-DEFERRED-INVALIDATED an intervening correction atomically invalidates a deferred reset' );

// PRD 6.4: AUTHORIZED -> CANCELLED withdraws the unused authorization.
$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
$case->cancel_reset( remediation_payload( 'ResetCancelled', array( 'authorization_id' => remediation_id( '6' ) ) ) );
archive_check( 'CANCELLED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-AUTHORIZED-CANCELLED an unused authorization can be explicitly withdrawn' );

// AC-19/INV-21 boundary: a claim exactly at or after expiry can never reach CLAIMED.
$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed', array( 'claimed_at_gmt' => '2026-07-13T12:54:59Z' ) ) );
archive_check( 'CLAIMED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'TRANSITION-CLAIM-BEFORE-EXPIRY a claim immediately before expiry succeeds' );

$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed', array( 'claimed_at_gmt' => '2026-07-13T12:55:00Z' ) ) );
}, 'authorization_expired', 'TRANSITION-CLAIM-AT-EXPIRY' );

$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested' ) );
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed', array( 'claimed_at_gmt' => '2026-07-13T12:56:00Z' ) ) );
}, 'authorization_expired', 'TRANSITION-CLAIM-AFTER-EXPIRY' );

// Consent-mode window semantics: single_use works without a reevaluation
// window; bounded_reevaluation requires one; nullable windows never reach strcmp().
$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested', array( 'consent_mode' => 'single_use', 'request_valid_until_gmt' => null ) ) );
$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
$case->claim_reset_execution( remediation_payload( 'ResetExecutionClaimed' ) );
archive_check( 'CLAIMED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'RESET-SINGLE-USE-NULL-WINDOW single-use consent authorizes and claims within the bounded authorization expiry alone' );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetRequested', 1, remediation_payload( 'ResetRequested', array( 'request_valid_until_gmt' => null ) ) );
}, 'RESET-BOUNDED-REQUIRES-WINDOW bounded reevaluation consent cannot omit its validity window', InvalidArgumentException::class );

$case = matrix_finalized_case();
$case->request_reset( remediation_payload( 'ResetRequested', array( 'consent_mode' => 'single_use', 'request_valid_until_gmt' => null ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->defer_reset( remediation_payload( 'ResetDeferred' ) );
}, 'consent_mode_mismatch', 'RESET-NULL-WINDOW-DEFER a null consent window can never satisfy deferral comparisons' );

// Prohibited edges out of terminal build states and phase-order violations.
$case = matrix_requested_case();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
}, 'invalid_build_start', 'TRANSITION-CANCELLED-BUILD-START' );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'build_attempt_id' => null, 'phase' => 'requested' ) ) );
}, 'invalid_failure', 'TRANSITION-CANCELLED-FAILED' );

$case = matrix_finalized_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
}, 'invalid_build_start', 'TRANSITION-FINALIZED-BUILD-START' );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->fail_archive( remediation_payload( 'ArchiveFailed', array( 'phase' => 'verifying', 'sealed_snapshot_id' => remediation_id( 'c' ) ) ) );
}, 'invalid_failure', 'TRANSITION-FINALIZED-FAILED' );

$case = matrix_requested_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
}, 'invalid_snapshot_transition', 'TRANSITION-CAPTURE-BEFORE-START' );

$case = matrix_capturing_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->materialize_ledger( remediation_payload( 'LedgerMaterialized' ) );
}, 'invalid_materialization', 'TRANSITION-MATERIALIZE-BEFORE-SNAPSHOT' );

$terminal_builds = array( 'FINALIZED' => matrix_finalized_case() );
$cancelled = matrix_requested_case();
$cancelled->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
$terminal_builds['CANCELLED'] = $cancelled;
foreach ( $terminal_builds as $state => $case ) {
	archive_expect_transition_rejection( $case, static function () use ( $case ): void {
		$case->request_retry( remediation_payload( 'ArchiveRetryRequested' ) );
	}, 'invalid_retry', 'TRANSITION-TERMINAL-BUILD-' . $state );
}

$case = matrix_finalized_case();
$case->correct( array(), remediation_payload( 'CorrectionRequested' ), remediation_payload( 'ArchiveRevoked' ) );
$case->request_replacement_archive( remediation_payload( 'ReplacementArchiveRequested' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->request_replacement_archive( remediation_payload( 'ReplacementArchiveRequested', array( 'archive_id' => remediation_id( '8' ), 'revision_number' => 3 ) ) );
}, 'active_build_exists', 'TRANSITION-SUPERSESSION-FORK' );

// AC-08 at every specified pre-finalization build state: the reset request is
// accepted for evaluation (defer/reject) but can never authorize.
$ac08_builders = array(
	'REQUESTED'     => static function (): GHCA_ACD_Archive_Case { return matrix_requested_case(); },
	'CAPTURING'     => static function (): GHCA_ACD_Archive_Case { return matrix_capturing_case(); },
	'MATERIALIZING' => static function (): GHCA_ACD_Archive_Case { return matrix_materializing_case(); },
	'VERIFYING'     => static function (): GHCA_ACD_Archive_Case { return matrix_verifying_case(); },
	'FAILED'        => static function (): GHCA_ACD_Archive_Case { return matrix_failed_before_snapshot(); },
	'CANCELLED'     => static function (): GHCA_ACD_Archive_Case { $case = matrix_requested_case(); $case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) ); return $case; },
);
foreach ( $ac08_builders as $build_state => $builder ) {
	$case = $builder();
	$snapshot_id = $case->state()['revisions'][ remediation_id( 'a' ) ]['snapshot_id'];
	$case->request_reset( remediation_payload( 'ResetRequested', array( 'snapshot_id' => $snapshot_id ) ) );
	archive_check( 'REQUESTED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'AC-08-' . $build_state . ' a too-early reset request is accepted for evaluation only' );
	archive_expect_transition_rejection( $case, static function () use ( $case ): void {
		$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
	}, 'reset_not_eligible', 'AC-08-' . $build_state . ' reset can never authorize against a non-finalized revision' );
	$case->reject_reset( remediation_payload( 'ResetRejected' ) );
	archive_check( 'REJECTED' === $case->state()['resets'][ remediation_id( 'f' ) ]['reset_state'], 'AC-08-' . $build_state . ' the too-early request ends deferred or terminally rejected' );
}

$terminal_reset_builders = array(
	'REJECTED' => static function (): GHCA_ACD_Archive_Case { $c = matrix_finalized_case(); $c->request_reset( remediation_payload( 'ResetRequested' ) ); $c->reject_reset( remediation_payload( 'ResetRejected' ) ); return $c; },
	'CANCELLED' => static function (): GHCA_ACD_Archive_Case { $c = matrix_finalized_case(); $c->request_reset( remediation_payload( 'ResetRequested' ) ); $c->cancel_reset( remediation_payload( 'ResetCancelled' ) ); return $c; },
	'EXPIRED' => static function (): GHCA_ACD_Archive_Case { $c = matrix_finalized_case(); $c->request_reset( remediation_payload( 'ResetRequested' ) ); $c->authorize_reset( remediation_payload( 'ResetAuthorized' ) ); $c->expire_reset_authorization( remediation_payload( 'ResetAuthorizationExpired' ) ); return $c; },
	'INVALIDATED' => static function (): GHCA_ACD_Archive_Case {
		$c = matrix_finalized_case();
		$c->request_reset( remediation_payload( 'ResetRequested' ) );
		$c->correct(
			array( remediation_payload( 'ResetOperationInvalidated', array( 'authorization_id' => null ) ) ),
			remediation_payload( 'CorrectionRequested' ),
			remediation_payload( 'ArchiveRevoked', array( 'invalidated_reset_operation_ids' => array( remediation_id( 'f' ) ) ) )
		);
		return $c;
	},
);
foreach ( $terminal_reset_builders as $state => $builder ) {
	$case = $builder();
	archive_expect_transition_rejection( $case, static function () use ( $case ): void {
		$case->authorize_reset( remediation_payload( 'ResetAuthorized' ) );
	}, 'reset_not_eligible', 'TRANSITION-TERMINAL-RESET-' . $state );
}

// T6: the immutable ArchiveCancelled event states attempt truth. A requested
// revision has no build attempt, so its cancellation carries a null attempt and
// can never invent one; once an attempt exists it must match exactly.
$case = matrix_requested_case();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
archive_check( 'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'T6-CANCEL-REQUESTED-NULL-ATTEMPT a requested revision cancels with a null build attempt' );

$case = matrix_requested_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => remediation_id( 'b' ) ) ) );
}, 'attempt_mismatch', 'T6-CANCEL-REQUESTED-FABRICATED-ATTEMPT a requested cancellation cannot invent a build attempt' );

$case = matrix_capturing_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => remediation_id( '7' ) ) ) );
}, 'attempt_mismatch', 'T6-CANCEL-ATTEMPT-MISMATCH a cancellation after build start must match the exact attempt' );

$case = matrix_capturing_case();
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->cancel_archive( remediation_payload( 'ArchiveCancelled', array( 'build_attempt_id' => null ) ) );
}, 'attempt_mismatch', 'T6-CANCEL-ATTEMPT-REQUIRED once an attempt exists a null cancellation attempt is rejected' );

$case = matrix_capturing_case();
$case->cancel_archive( remediation_payload( 'ArchiveCancelled' ) );
archive_check( 'CANCELLED' === $case->state()['revisions'][ remediation_id( 'a' ) ]['build_state'], 'T6-CANCEL-ATTEMPT-EXACT a cancellation naming the exact build attempt succeeds' );

archive_finish();
