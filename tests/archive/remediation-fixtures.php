<?php

/** Independent Slice 1A remediation fixtures. Never derive these from production schemas. */

function remediation_id( string $character ): string {
	return str_repeat( $character, 32 );
}

function remediation_digest( string $character ): string {
	return str_repeat( $character, 64 );
}

/** @return array<string,mixed> */
function remediation_cycle(): array {
	return array(
		'boundary'       => '[)',
		'display_label'  => '2026',
		'end_gmt'        => '2027-01-01T05:00:00Z',
		'key'            => 'v1|calendar_year|1|2026-01-01T05:00:00Z|2027-01-01T05:00:00Z|America/Toronto|[)',
		'policy_key'     => 'calendar_year',
		'policy_version' => 1,
		'start_gmt'      => '2026-01-01T05:00:00Z',
		'timezone'       => 'America/Toronto',
	);
}

/** @return array<string,string> */
function remediation_case_key(): array {
	return array(
		'cycle_key'                => remediation_cycle()['key'],
		'employee_user_id_decimal' => '42',
		'program_key'              => 'annual',
		'site_id_decimal'          => '1',
		'tenant_id'                => '0123456789abcdef0123456789abcdef',
	);
}

function remediation_case_digest(): string {
	return GHCA_ACD_Archive_Digester::case_key(
		'0123456789abcdef0123456789abcdef',
		'1',
		'42',
		'annual',
		remediation_cycle()['key']
	);
}

/** @return array<string,mixed> */
function remediation_scope(): array {
	return array(
		'course_ids'               => array( '10', '20' ),
		'cycle_key'                => remediation_cycle()['key'],
		'employee_user_id_decimal' => '42',
		'program_key'              => 'annual',
	);
}

function remediation_scope_digest(): string {
	return GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', remediation_scope() );
}

/** @param array<string,mixed> $overrides @return array<string,mixed> */
function remediation_payload( string $event_type, array $overrides = array() ): array {
	$a = remediation_id( 'a' );
	$b = remediation_id( 'b' );
	$c = remediation_id( 'c' );
	$d = remediation_id( 'd' );
	$e = remediation_id( 'e' );
	$f = remediation_id( 'f' );
	$payloads = array(
		'ArchiveRequested' => array(
			'payload_schema_version' => 1, 'case_key' => remediation_case_key(), 'archive_id' => $a,
			'revision_number' => 1, 'request_kind' => 'initial', 'resolved_cycle' => remediation_cycle(),
			'reviewed_source_fingerprint' => remediation_digest( '1' ), 'policy_digest' => remediation_digest( '2' ),
			'subject_scope_digest' => remediation_scope_digest(),
		),
		'ArchiveBuildStarted' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'build_attempt_id' => $b,
			'start_phase' => 'capturing', 'retry_ordinal' => 0, 'snapshot_id' => null,
		),
		'EvidenceSnapshotCaptured' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'revision_number' => 1, 'snapshot_id' => $c,
			'snapshot_schema_version' => 1, 'snapshot_digest' => remediation_digest( '4' ), 'byte_count' => 1024,
			'reviewed_source_fingerprint' => remediation_digest( '1' ), 'captured_source_fingerprint' => remediation_digest( '1' ),
			'completeness_policy' => 'snapshot_v1_complete', 'policy_digest' => remediation_digest( '2' ),
			'subject_scope_digest' => remediation_scope_digest(), 'resolved_cycle' => remediation_cycle(),
			'certificate_asset_ids' => array( remediation_id( '4' ), remediation_id( '5' ) ),
			'certificate_content_digests' => array( remediation_digest( '5' ), remediation_digest( '6' ) ),
		),
		'LedgerMaterialized' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'snapshot_id' => $c,
			'snapshot_digest' => remediation_digest( '4' ), 'build_attempt_id' => $b,
			'ledger_artifact_id' => $d, 'content_digest' => remediation_digest( '7' ),
			'item_count' => 2, 'manifest_digest' => remediation_digest( '8' ),
		),
		'PacketMaterialized' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'snapshot_id' => $c,
			'snapshot_digest' => remediation_digest( '4' ), 'build_attempt_id' => $b,
			'packet_artifact_id' => $e, 'content_digest' => remediation_digest( '9' ),
			'certificate_content_digests' => array( remediation_digest( '5' ), remediation_digest( '6' ) ),
		),
		'ArchiveVerified' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'revision_number' => 1,
			'snapshot_id' => $c, 'snapshot_digest' => remediation_digest( '4' ),
			'verification_policy_version' => 1, 'ledger_artifact_id' => $d,
			'ledger_content_digest' => remediation_digest( '7' ), 'packet_artifact_id' => $e,
			'packet_content_digest' => remediation_digest( '9' ), 'source_fingerprint' => remediation_digest( '1' ),
			'checks_digest' => remediation_digest( 'a' ), 'verified_at_gmt' => '2026-07-13T12:30:00Z',
			'active_identity_digest' => remediation_case_digest(), 'expected_predecessor_archive_id' => null,
		),
		'ArchiveFinalized' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'revision_number' => 1,
			'snapshot_id' => $c, 'snapshot_digest' => remediation_digest( '4' ),
			'ledger_artifact_id' => $d, 'ledger_content_digest' => remediation_digest( '7' ),
			'packet_artifact_id' => $e, 'packet_content_digest' => remediation_digest( '9' ),
			'active_identity_digest' => remediation_case_digest(), 'expected_predecessor_archive_id' => null,
			'finalized_at_gmt' => '2026-07-13T12:31:00Z',
		),
		'ArchiveFailed' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'build_attempt_id' => $b,
			'phase' => 'capturing', 'failure_code' => 'source_unavailable', 'retryable' => true,
			'sealed_snapshot_id' => null, 'candidate_artifact_ids' => array(),
		),
		'ArchiveRetryRequested' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'prior_build_attempt_id' => $b,
			'new_build_attempt_id' => remediation_id( '7' ), 'resume_phase' => 'capturing', 'sealed_snapshot_id' => null,
		),
		'ArchiveCancelled' => array(
			'payload_schema_version' => 1, 'archive_id' => $a, 'build_attempt_id' => $b,
			'cancellation_reason' => 'operator_cancelled', 'retained_candidate_disposition_code' => 'retain_pending_policy',
		),
		'CorrectionRequested' => array(
			'payload_schema_version' => 1, 'target_archive_id' => $a, 'target_snapshot_id' => $c,
			'correction_operation_id' => remediation_id( '9' ), 'reason_code' => 'evidence_correction',
			'affected_scope_digest' => remediation_scope_digest(),
		),
		'ArchiveRevoked' => array(
			'payload_schema_version' => 1, 'target_archive_id' => $a,
			'correction_operation_id' => remediation_id( '9' ), 'revocation_reason_code' => 'evidence_correction',
			'invalidated_reset_operation_ids' => array(),
		),
		'ReplacementArchiveRequested' => array(
			'payload_schema_version' => 1, 'case_key' => remediation_case_key(), 'archive_id' => remediation_id( '0' ),
			'revision_number' => 2, 'revoked_predecessor_archive_id' => $a,
			'reviewed_source_fingerprint' => remediation_digest( '1' ), 'policy_digest' => remediation_digest( '2' ),
			'subject_scope_digest' => remediation_scope_digest(), 'resolved_cycle' => remediation_cycle(),
		),
		'ResetRequested' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'bound_archive_id' => $a,
			'snapshot_id' => $c, 'scope_digest' => remediation_scope_digest(), 'scope' => remediation_scope(),
			'consent_mode' => 'bounded_reevaluation', 'request_valid_until_gmt' => '2026-07-13T13:00:00Z',
		),
		'ResetDeferred' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'condition_code' => 'archive_not_finalized',
			'reevaluation_deadline_gmt' => '2026-07-13T12:45:00Z', 'consent_expires_at_gmt' => '2026-07-13T13:00:00Z',
		),
		'ResetRejected' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f,
			'rejection_code' => 'reset_not_eligible', 'safe_explanation' => 'Archive is not eligible for reset.',
		),
		'ResetCancelled' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'authorization_id' => null,
			'cancellation_reason' => 'request_withdrawn',
		),
		'ResetAuthorized' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'authorization_id' => remediation_id( '6' ),
			'archive_id' => $a, 'snapshot_id' => $c, 'scope_digest' => remediation_scope_digest(),
			'gateway_key' => 'learndash_supported_v1', 'issued_at_gmt' => '2026-07-13T12:35:00Z',
			'expires_at_gmt' => '2026-07-13T12:55:00Z', 'source_fingerprint' => remediation_digest( '1' ),
		),
		'ResetAuthorizationExpired' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'authorization_id' => remediation_id( '6' ),
			'scheduled_expires_at_gmt' => '2026-07-13T12:55:00Z', 'observed_at_gmt' => '2026-07-13T12:55:01Z',
			'expiry_policy_code' => 'hard_expiry',
		),
		'ResetOperationInvalidated' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'authorization_id' => remediation_id( '6' ),
			'invalidating_reference_id' => remediation_id( '9' ), 'reason_code' => 'correction_started',
		),
		'ResetExecutionClaimed' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'authorization_id' => remediation_id( '6' ),
			'gateway_key' => 'learndash_supported_v1', 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'scope_digest' => remediation_scope_digest(), 'source_fingerprint' => remediation_digest( '1' ),
			'claimed_at_gmt' => '2026-07-13T12:40:00Z',
		),
		'ResetCompleted' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'post_source_fingerprint' => remediation_digest( 'b' ), 'affected_record_count' => 2,
			'affected_records_digest' => remediation_digest( 'c' ), 'verification_evidence_digest' => remediation_digest( 'd' ),
		),
		'ResetFailedSafe' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'unchanged_source_fingerprint' => remediation_digest( '1' ), 'no_change_proof_digest' => remediation_digest( 'd' ),
			'probe_version' => 'learndash_probe_v1',
		),
		'ResetOutcomeBecameUncertain' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'last_known_phase' => 'gateway_invoked', 'failure_code' => 'worker_crash', 'last_observation_digest' => remediation_digest( 'd' ),
		),
		'ResetReconciledAsCompleted' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'proof_digest' => remediation_digest( 'd' ), 'probe_version' => 'learndash_probe_v1',
			'post_source_fingerprint' => remediation_digest( 'b' ), 'evidence_digest' => remediation_digest( 'e' ),
		),
		'ResetReconciledAsNoChange' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'no_change_proof_digest' => remediation_digest( 'd' ), 'probe_version' => 'learndash_probe_v1',
			'source_fingerprint' => remediation_digest( '1' ),
		),
		'ResetRemediationRequired' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'affected_scope_digest' => remediation_scope_digest(), 'remediation_case_id' => remediation_id( '8' ),
			'evidence_digest' => remediation_digest( 'e' ),
		),
		'ResetRemediatedRestored' => array(
			'payload_schema_version' => 1, 'reset_operation_id' => $f, 'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
			'remediation_case_id' => remediation_id( '8' ), 'restored_source_fingerprint' => remediation_digest( '1' ),
			'restoration_proof_digest' => remediation_digest( 'd' ), 'partial_effect_reference_id' => remediation_id( '5' ),
		),
		'SourceDriftDetected' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ), 'archive_id' => $a, 'snapshot_id' => $c,
			'expected_source_fingerprint' => remediation_digest( '1' ), 'observed_source_fingerprint' => remediation_digest( '9' ),
			'detection_point' => 'post_finalization', 'changed_component_codes' => array( 'course_progress' ),
		),
		'SourceDriftResolved' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ), 'resolution_kind' => 'restored',
			'verified_source_fingerprint' => remediation_digest( '1' ), 'resolution_reference_id' => remediation_id( '5' ),
		),
		'UnprotectedResetDetected' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ), 'scope' => remediation_scope(),
			'before_source_fingerprint' => remediation_digest( '1' ), 'observed_source_fingerprint' => remediation_digest( '9' ),
			'detector_key' => 'learndash_monitor_v1', 'probe_version' => 'learndash_probe_v1',
		),
		'UnprotectedResetDismissed' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ),
			'no_reset_proof_digest' => remediation_digest( 'd' ), 'verified_source_fingerprint' => remediation_digest( '1' ),
		),
		'UnprotectedResetConfirmed' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ),
			'affected_scope_digest' => remediation_scope_digest(), 'evidence_digest' => remediation_digest( 'e' ),
			'remediation_requirement' => 'required',
		),
		'IntegrityViolationDetected' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ), 'target_kind' => 'event',
			'target_id' => remediation_id( '4' ), 'expected_digest' => remediation_digest( '1' ),
			'observed_digest' => remediation_digest( '9' ), 'invariant_code' => null,
			'verifier_version' => 'event_verifier_v1', 'containment_code' => 'case_blocked',
		),
		'IntegrityIncidentDispositionRecorded' => array(
			'payload_schema_version' => 1, 'incident_id' => remediation_id( '7' ), 'disposition_code' => 'false_positive',
			'reason_code' => 'verified_source_bytes', 'evidence_reference_ids' => array( remediation_id( '5' ) ),
			'reviewer_authority_code' => 'archive_reconcile', 'remaining_restrictions' => array(),
		),
	);
	if ( ! isset( $payloads[ $event_type ] ) ) {
		throw new InvalidArgumentException( 'No independent remediation fixture for ' . $event_type . '.' );
	}
	return array_replace( $payloads[ $event_type ], $overrides );
}

/** @return array<string,mixed> */
function remediation_authority_context(): array {
	return array(
		'delegated_by_user_id' => null,
		'delegation_kind'      => 'none',
		'subject_scope_digest' => remediation_scope_digest(),
	);
}

/** @return array<string,mixed> */
function remediation_recording_context( string $event_id, string $sequence, ?string $previous_digest, array $overrides = array() ): array {
	$context = array(
		'canonical_format_version' => 1,
		'event_id' => $event_id,
		'stream_id' => remediation_id( '1' ),
		'case_key_digest' => remediation_case_digest(),
		'case_key_format_version' => 1,
		'stream_sequence' => $sequence,
		'archive_id' => remediation_id( 'a' ),
		'build_attempt_id' => null,
		'reset_operation_id' => null,
		'actor_kind' => 'wp_user',
		'actor_user_id' => '7',
		'initiating_user_id' => '7',
		'source_channel' => 'test',
		'authority_code' => 'archive_create',
		'authority_context' => remediation_authority_context(),
		'occurred_at_gmt' => '2026-07-13T12:00:00Z',
		'effective_at_gmt' => null,
		'correlation_id' => remediation_id( '2' ),
		'causation_event_id' => null,
		'command_id' => remediation_id( '3' ),
		'upstream_operation_id' => null,
		'idempotency_scope_digest' => remediation_digest( 'b' ),
		'idempotency_key_digest' => remediation_digest( 'c' ),
		'command_digest' => remediation_digest( 'd' ),
		'reason_code' => null,
		'reason_text' => null,
		'previous_event_digest' => $previous_digest,
		'recorded_at_gmt' => '2026-07-13T12:00:00Z',
	);
	return array_replace( $context, $overrides );
}
