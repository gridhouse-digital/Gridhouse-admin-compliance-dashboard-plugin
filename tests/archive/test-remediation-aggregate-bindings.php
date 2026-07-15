<?php
require __DIR__ . '/bootstrap.php';

function binding_case_to_capturing(): GHCA_ACD_Archive_Case {
	$case = new GHCA_ACD_Archive_Case();
	$case->request_archive( remediation_payload( 'ArchiveRequested' ) );
	$case->start_build( remediation_payload( 'ArchiveBuildStarted' ) );
	return $case;
}

function binding_case_to_verifying(): GHCA_ACD_Archive_Case {
	$case = binding_case_to_capturing();
	$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
	$case->materialize_ledger( remediation_payload( 'LedgerMaterialized' ) );
	$case->materialize_packet( remediation_payload( 'PacketMaterialized' ) );
	return $case;
}

$snapshot_contradictions = array(
	'BIND-SNAPSHOT-REVISION' => array( 'revision_number', 2, 'snapshot_revision_mismatch' ),
	'BIND-SNAPSHOT-POLICY'   => array( 'policy_digest', remediation_digest( '9' ), 'snapshot_policy_mismatch' ),
	'BIND-SNAPSHOT-SCOPE'    => array( 'subject_scope_digest', remediation_digest( '9' ), 'snapshot_scope_mismatch' ),
	'BIND-SNAPSHOT-REVIEWED' => array( 'reviewed_source_fingerprint', remediation_digest( '9' ), 'source_drift' ),
	'BIND-SNAPSHOT-CAPTURED' => array( 'captured_source_fingerprint', remediation_digest( '9' ), 'source_drift' ),
	'BIND-SNAPSHOT-CYCLE'    => array( 'resolved_cycle', array_replace( remediation_cycle(), array( 'display_label' => 'wrong' ) ), 'snapshot_cycle_mismatch' ),
);
foreach ( $snapshot_contradictions as $name => $spec ) {
	$case = binding_case_to_capturing();
	archive_expect_transition_rejection( $case, static function () use ( $case, $spec ): void {
		$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured', array( $spec[0] => $spec[1] ) ) );
	}, $spec[2], $name );
}

$case = binding_case_to_capturing();
$case->capture_evidence_snapshot( remediation_payload( 'EvidenceSnapshotCaptured' ) );
archive_expect_transition_rejection( $case, static function () use ( $case ): void {
	$case->materialize_packet( remediation_payload( 'PacketMaterialized', array(
		'certificate_content_digests' => array( remediation_digest( '5' ), remediation_digest( '7' ) ),
	) ) );
}, 'certificate_manifest_mismatch', 'BIND-PACKET-CERTIFICATES' );

$verification_contradictions = array(
	'BIND-VERIFY-REVISION'    => array( 'revision_number', 2, 'revision_binding_mismatch' ),
	'BIND-VERIFY-IDENTITY'    => array( 'active_identity_digest', remediation_digest( '9' ), 'active_identity_mismatch' ),
	'BIND-VERIFY-PREDECESSOR' => array( 'expected_predecessor_archive_id', remediation_id( '9' ), 'predecessor_mismatch' ),
);
foreach ( $verification_contradictions as $name => $spec ) {
	$case = binding_case_to_verifying();
	archive_expect_transition_rejection( $case, static function () use ( $case, $spec ): void {
		$case->verify_and_finalize(
			remediation_payload( 'ArchiveVerified', array( $spec[0] => $spec[1] ) ),
			remediation_payload( 'ArchiveFinalized', array( $spec[0] => $spec[1] ) )
		);
	}, $spec[2], $name );
}

$finalization_contradictions = array(
	'BIND-FINAL-REVISION' => array( 'revision_number', 2, 'finalization_batch_mismatch' ),
	'BIND-FINAL-IDENTITY' => array( 'active_identity_digest', remediation_digest( '9' ), 'finalization_batch_mismatch' ),
);
foreach ( $finalization_contradictions as $name => $spec ) {
	$case = binding_case_to_verifying();
	archive_expect_transition_rejection( $case, static function () use ( $case, $spec ): void {
		$case->verify_and_finalize(
			remediation_payload( 'ArchiveVerified' ),
			remediation_payload( 'ArchiveFinalized', array( $spec[0] => $spec[1] ) )
		);
	}, $spec[2], $name );
}

$case = binding_case_to_verifying();
$case->verify_and_finalize( remediation_payload( 'ArchiveVerified' ), remediation_payload( 'ArchiveFinalized' ) );
$state = $case->state();
$revision = $state['revisions'][ remediation_id( 'a' ) ];
archive_check( remediation_cycle() === $revision['resolved_cycle'], 'BIND-STATE-CYCLE retains the resolved cycle' );
archive_check( remediation_digest( '2' ) === $revision['policy_digest'], 'BIND-STATE-POLICY retains policy digest' );
archive_check( remediation_scope_digest() === $revision['subject_scope_digest'], 'BIND-STATE-SCOPE retains subject scope digest' );
archive_check( remediation_payload( 'EvidenceSnapshotCaptured' )['certificate_content_digests'] === $revision['certificate_content_digests'], 'BIND-STATE-CERT retains snapshot certificate evidence' );

archive_finish();
