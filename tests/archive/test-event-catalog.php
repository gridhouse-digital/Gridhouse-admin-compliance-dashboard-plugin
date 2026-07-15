<?php
require __DIR__ . '/bootstrap.php';

$expected = array(
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

archive_check( GHCA_ACD_Archive_Event_Types::all() === $expected, 'event type constants exactly match the 35-event Architecture PRD catalog' );
archive_check( GHCA_ACD_Archive_Event_Catalog::event_types() === $expected, 'catalog order exactly matches the Architecture PRD catalog' );

foreach ( $expected as $event_type ) {
	$payload = archive_event_payload( $event_type );
	archive_check( GHCA_ACD_Archive_Event_Catalog::validate_payload( $event_type, 1, $payload ), $event_type . ' accepts its explicit v1 schema' );

	$missing = $payload;
	$keys = array_keys( $missing );
	unset( $missing[ $keys[ count( $keys ) - 1 ] ] );
	archive_expect_exception( static function () use ( $event_type, $missing ): void {
		GHCA_ACD_Archive_Event_Catalog::validate_payload( $event_type, 1, $missing );
	}, $event_type . ' rejects a missing field', InvalidArgumentException::class );

	$extra = $payload;
	$extra['unexpected'] = true;
	archive_expect_exception( static function () use ( $event_type, $extra ): void {
		GHCA_ACD_Archive_Event_Catalog::validate_payload( $event_type, 1, $extra );
	}, $event_type . ' rejects an unexpected field', InvalidArgumentException::class );
}

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Event_Catalog::schema( 'StatusUpdated' );
}, 'unknown/generic event types fail closed', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveRequested', 2, array() );
}, 'unknown event schema versions fail closed', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	$payload = archive_event_payload( 'ArchiveRequested' );
	$payload['payload_schema_version'] = 2;
	GHCA_ACD_Archive_Event_Catalog::validate_payload( 'ArchiveRequested', 1, $payload );
}, 'unknown payload schema versions fail closed', InvalidArgumentException::class );

archive_finish();

