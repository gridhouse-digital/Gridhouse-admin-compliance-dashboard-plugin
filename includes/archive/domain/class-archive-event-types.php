<?php

final class GHCA_ACD_Archive_Event_Types {
	const ARCHIVE_REQUESTED                       = 'ArchiveRequested';
	const ARCHIVE_BUILD_STARTED                   = 'ArchiveBuildStarted';
	const EVIDENCE_SNAPSHOT_CAPTURED              = 'EvidenceSnapshotCaptured';
	const LEDGER_MATERIALIZED                     = 'LedgerMaterialized';
	const PACKET_MATERIALIZED                     = 'PacketMaterialized';
	const ARCHIVE_VERIFIED                        = 'ArchiveVerified';
	const ARCHIVE_FINALIZED                       = 'ArchiveFinalized';
	const ARCHIVE_FAILED                          = 'ArchiveFailed';
	const ARCHIVE_RETRY_REQUESTED                 = 'ArchiveRetryRequested';
	const ARCHIVE_CANCELLED                       = 'ArchiveCancelled';
	const CORRECTION_REQUESTED                    = 'CorrectionRequested';
	const ARCHIVE_REVOKED                         = 'ArchiveRevoked';
	const REPLACEMENT_ARCHIVE_REQUESTED           = 'ReplacementArchiveRequested';
	const RESET_REQUESTED                         = 'ResetRequested';
	const RESET_DEFERRED                          = 'ResetDeferred';
	const RESET_REJECTED                          = 'ResetRejected';
	const RESET_CANCELLED                         = 'ResetCancelled';
	const RESET_AUTHORIZED                        = 'ResetAuthorized';
	const RESET_AUTHORIZATION_EXPIRED             = 'ResetAuthorizationExpired';
	const RESET_OPERATION_INVALIDATED             = 'ResetOperationInvalidated';
	const RESET_EXECUTION_CLAIMED                 = 'ResetExecutionClaimed';
	const RESET_COMPLETED                         = 'ResetCompleted';
	const RESET_FAILED_SAFE                       = 'ResetFailedSafe';
	const RESET_OUTCOME_BECAME_UNCERTAIN          = 'ResetOutcomeBecameUncertain';
	const RESET_RECONCILED_AS_COMPLETED           = 'ResetReconciledAsCompleted';
	const RESET_RECONCILED_AS_NO_CHANGE           = 'ResetReconciledAsNoChange';
	const RESET_REMEDIATION_REQUIRED              = 'ResetRemediationRequired';
	const RESET_REMEDIATED_RESTORED               = 'ResetRemediatedRestored';
	const SOURCE_DRIFT_DETECTED                    = 'SourceDriftDetected';
	const SOURCE_DRIFT_RESOLVED                    = 'SourceDriftResolved';
	const UNPROTECTED_RESET_DETECTED               = 'UnprotectedResetDetected';
	const UNPROTECTED_RESET_DISMISSED              = 'UnprotectedResetDismissed';
	const UNPROTECTED_RESET_CONFIRMED              = 'UnprotectedResetConfirmed';
	const INTEGRITY_VIOLATION_DETECTED             = 'IntegrityViolationDetected';
	const INTEGRITY_INCIDENT_DISPOSITION_RECORDED  = 'IntegrityIncidentDispositionRecorded';

	/**
	 * Every approved Slice 1A event is command-originated: Technical Design
	 * Section 8.1 defines the authoritative command transaction as the only
	 * event-append path, so complete command provenance is required for all.
	 *
	 * @return array<int,string>
	 */
	public static function command_originated(): array {
		return self::all();
	}

	/** @return array<int,string> */
	public static function all(): array {
		return array(
			self::ARCHIVE_REQUESTED, self::ARCHIVE_BUILD_STARTED, self::EVIDENCE_SNAPSHOT_CAPTURED,
			self::LEDGER_MATERIALIZED, self::PACKET_MATERIALIZED, self::ARCHIVE_VERIFIED,
			self::ARCHIVE_FINALIZED, self::ARCHIVE_FAILED, self::ARCHIVE_RETRY_REQUESTED,
			self::ARCHIVE_CANCELLED, self::CORRECTION_REQUESTED, self::ARCHIVE_REVOKED,
			self::REPLACEMENT_ARCHIVE_REQUESTED, self::RESET_REQUESTED, self::RESET_DEFERRED,
			self::RESET_REJECTED, self::RESET_CANCELLED, self::RESET_AUTHORIZED,
			self::RESET_AUTHORIZATION_EXPIRED, self::RESET_OPERATION_INVALIDATED,
			self::RESET_EXECUTION_CLAIMED, self::RESET_COMPLETED, self::RESET_FAILED_SAFE,
			self::RESET_OUTCOME_BECAME_UNCERTAIN, self::RESET_RECONCILED_AS_COMPLETED,
			self::RESET_RECONCILED_AS_NO_CHANGE, self::RESET_REMEDIATION_REQUIRED,
			self::RESET_REMEDIATED_RESTORED, self::SOURCE_DRIFT_DETECTED,
			self::SOURCE_DRIFT_RESOLVED, self::UNPROTECTED_RESET_DETECTED,
			self::UNPROTECTED_RESET_DISMISSED, self::UNPROTECTED_RESET_CONFIRMED,
			self::INTEGRITY_VIOLATION_DETECTED, self::INTEGRITY_INCIDENT_DISPOSITION_RECORDED,
		);
	}
}

