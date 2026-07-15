<?php
require __DIR__ . '/bootstrap.php';

$expected_events = array(
	'ArchiveRequested', 'ArchiveBuildStarted', 'EvidenceSnapshotCaptured', 'LedgerMaterialized',
	'PacketMaterialized', 'ArchiveVerified', 'ArchiveFinalized', 'ArchiveFailed',
	'ArchiveRetryRequested', 'ArchiveCancelled', 'CorrectionRequested', 'ArchiveRevoked',
	'ReplacementArchiveRequested', 'ResetRequested', 'ResetDeferred', 'ResetRejected',
	'ResetCancelled', 'ResetAuthorized', 'ResetAuthorizationExpired', 'ResetOperationInvalidated',
	'ResetExecutionClaimed', 'ResetCompleted', 'ResetFailedSafe', 'ResetOutcomeBecameUncertain',
	'ResetReconciledAsCompleted', 'ResetReconciledAsNoChange', 'ResetRemediationRequired',
	'ResetRemediatedRestored', 'SourceDriftDetected', 'SourceDriftResolved',
	'UnprotectedResetDetected', 'UnprotectedResetDismissed', 'UnprotectedResetConfirmed',
	'IntegrityViolationDetected', 'IntegrityIncidentDispositionRecorded',
);
archive_check( GHCA_ACD_Archive_Event_Types::all() === $expected_events, 'D-CATALOG-01 closed catalog contains exactly the approved 35 events' );

foreach ( $expected_events as $event_type ) {
	$accepted = false;
	try {
		$accepted = GHCA_ACD_Archive_Event_Catalog::validate_payload( $event_type, 1, remediation_payload( $event_type ) );
	} catch ( Throwable $error ) {
		$accepted = false;
	}
	archive_check( $accepted, 'D-SCHEMA-' . $event_type . ' accepts its independent exact v1 fixture' );
}

$missing_case_field = remediation_payload( 'ArchiveRequested' );
unset( $missing_case_field['case_key']['tenant_id'] );
archive_expect_exception( static function () use ( $missing_case_field ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveRequested', 1, $missing_case_field );
}, 'D-NESTED-01 case_key rejects a missing constituent', InvalidArgumentException::class );

$extra_case_field = remediation_payload( 'ArchiveRequested' );
$extra_case_field['case_key']['display_name'] = 'mutable';
archive_expect_exception( static function () use ( $extra_case_field ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveRequested', 1, $extra_case_field );
}, 'D-NESTED-02 case_key rejects an unexpected constituent', InvalidArgumentException::class );

$bad_cycle_key = remediation_payload( 'ArchiveRequested' );
$bad_cycle_key['resolved_cycle']['key'] = 'v1|forged';
archive_expect_exception( static function () use ( $bad_cycle_key ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveRequested', 1, $bad_cycle_key );
}, 'D-NESTED-03 resolved_cycle key must match its exact policy/bounds/timezone facts', InvalidArgumentException::class );

$bad_scope = remediation_payload( 'ResetRequested' );
$bad_scope['scope_digest'] = remediation_digest( '9' );
archive_expect_exception( static function () use ( $bad_scope ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetRequested', 1, $bad_scope );
}, 'D-NESTED-04 reset scope digest must match the exact bounded scope document', InvalidArgumentException::class );

$bad_scope_order = remediation_payload( 'ResetRequested' );
$bad_scope_order['scope']['course_ids'] = array( '20', '10' );
archive_expect_exception( static function () use ( $bad_scope_order ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetRequested', 1, $bad_scope_order );
}, 'D-NESTED-05 reset scope course identities require canonical unique order', InvalidArgumentException::class );

$external_operation = remediation_payload( 'ResetExecutionClaimed' );
archive_check(
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetExecutionClaimed', 1, $external_operation ),
	'D-UPSTREAM-01 approved external gateway operation identifiers are not forced into internal 32-hex format'
);

$invalid_timestamp = remediation_payload( 'ArchiveVerified' );
$invalid_timestamp['verified_at_gmt'] = '2026-02-30T12:00:00Z';
archive_expect_exception( static function () use ( $invalid_timestamp ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveVerified', 1, $invalid_timestamp );
}, 'D-TIME-01 UTC timestamps must be real calendar timestamps', InvalidArgumentException::class );

$invalid_consent_timestamp = remediation_payload( 'ResetRequested', array( 'request_valid_until_gmt' => '2026-02-30T12:00:00Z' ) );
archive_expect_exception( static function () use ( $invalid_consent_timestamp ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetRequested', 1, $invalid_consent_timestamp );
}, 'D-TIME-02 reset consent validity is a real UTC calendar timestamp', InvalidArgumentException::class );

$invalid_phase = remediation_payload( 'ArchiveBuildStarted' );
$invalid_phase['start_phase'] = 'invented';
archive_expect_exception( static function () use ( $invalid_phase ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveBuildStarted', 1, $invalid_phase );
}, 'D-ENUM-01 event-specific transition enums fail closed', InvalidArgumentException::class );

$invalid_resolution = remediation_payload( 'SourceDriftResolved' );
$invalid_resolution['resolution_kind'] = 'ignore';
archive_expect_exception( static function () use ( $invalid_resolution ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'SourceDriftResolved', 1, $invalid_resolution );
}, 'D-ENUM-02 source drift resolution path is closed', InvalidArgumentException::class );

$bad_manifest = remediation_payload( 'EvidenceSnapshotCaptured' );
array_pop( $bad_manifest['certificate_content_digests'] );
archive_expect_exception( static function () use ( $bad_manifest ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'EvidenceSnapshotCaptured', 1, $bad_manifest );
}, 'D-CERT-01 certificate asset and digest manifests require matching cardinality', InvalidArgumentException::class );

$too_many_records = remediation_payload( 'ResetCompleted' );
$too_many_records['affected_record_count'] = 10001;
archive_expect_exception( static function () use ( $too_many_records ): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ResetCompleted', 1, $too_many_records );
}, 'D-INT-01 event integer bounds are field-specific', InvalidArgumentException::class );

archive_finish();
