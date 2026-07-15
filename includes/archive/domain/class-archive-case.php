<?php

/** Pure deterministic Archive Case aggregate. No clock, WordPress, database, or I/O. */
final class GHCA_ACD_Archive_Case {
	/** @var array<string,mixed> */
	private $state;
	/** @var int */
	private $sequence = 0;
	/** @var array<int,GHCA_ACD_Archive_Event> */
	private $uncommitted = array();

	public function __construct() {
		$this->state = array(
			'case_key'                     => null,
			'case_key_digest'              => null,
			'max_revision_number'           => 0,
			'revisions'                    => array(),
			'current_archive_id'            => null,
			'active_archive_id'             => null,
			'correction_target_archive_id'  => null,
			'pending_correction_id'         => null,
			'resets'                       => array(),
			'active_reset_operation_id'     => null,
			'destructive_reset_seen'        => false,
			'source_drift_state'            => 'NONE',
			'source_drift_incident_id'      => null,
			'source_drift_archive_id'       => null,
			'source_drift_expected_fingerprint' => null,
			'unprotected_reset_state'       => 'NONE',
			'unprotected_reset_incident_id' => null,
			'unprotected_reset_before_fingerprint' => null,
			'unprotected_reset_scope_digest' => null,
			'integrity_state'               => 'NONE',
			'integrity_incident_id'         => null,
			'integrity_disposition'         => null,
			'integrity_remaining_restrictions' => array(),
			'integrity_compromise_confirmed' => false,
		);
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	public static function rehydrate( array $events ): self {
		GHCA_ACD_Archive_Event_Stream_Verifier::verify( $events );
		return self::replay_semantic_decisions( $events, true );
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	public static function rehydrate_uncommitted_for_testing( array $events ): self {
		if ( ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) || ! GHCA_ACD_ARCHIVE_TESTING ) {
			throw new LogicException( 'Uncommitted replay is test-only.' );
		}
		foreach ( $events as $event ) {
			if ( ! $event instanceof GHCA_ACD_Archive_Event || $event->is_recorded() ) {
				throw new InvalidArgumentException( 'Test replay requires uncommitted archive events.' );
			}
		}
		return self::replay_semantic_decisions( $events, false );
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	private static function replay_semantic_decisions( array $events, bool $recorded ): self {
		$case = new self();
		$offset = 0;
		$total = count( $events );
		while ( $offset < $total ) {
			$first = $events[ $offset ];
			if ( ! $first instanceof GHCA_ACD_Archive_Event || $first->is_recorded() !== $recorded ) { throw new InvalidArgumentException( 'Replay event kind is invalid.' ); }
			$metadata = $first->metadata();
			$size = $metadata['decision_size'];
			if ( 0 !== $metadata['decision_index'] || $offset + $size > $total ) { throw new InvalidArgumentException( 'Replay decision metadata is discontinuous.' ); }
			$specs = array();
			$decision_provenance = null;
			for ( $index = 0; $index < $size; $index++ ) {
				$event = $events[ $offset + $index ];
				$event_metadata = $event->metadata();
				if ( $event_metadata['decision_size'] !== $size || $event_metadata['decision_index'] !== $index ) { throw new InvalidArgumentException( 'Replay decision metadata is discontinuous.' ); }
				$specs[] = array( 'type' => $event->type(), 'payload' => $event->payload() );
				if ( $recorded && $size > 1 ) {
					$document = $event->recorded_document();
					$subset   = array();
					foreach ( array( 'command_id', 'idempotency_scope_digest', 'idempotency_key_digest', 'command_digest', 'correlation_id', 'actor_kind', 'actor_user_id', 'initiating_user_id', 'source_channel', 'authority_code', 'authority_context' ) as $field ) {
						$subset[ $field ] = $document[ $field ];
					}
					if ( null === $decision_provenance ) {
						$decision_provenance = $subset;
					} elseif ( $decision_provenance !== $subset ) {
						throw new InvalidArgumentException( 'A multi-event decision must share one command identity, actor/authority, and correlation.' );
					}
				}
			}
			$case->validate_batch_contract( $specs );
			foreach ( array_slice( $events, $offset, $size ) as $event ) {
				$case->apply( $event );
				$case->sequence++;
			}
			$offset += $size;
		}
		return $case;
	}

	/*
	 * Test-only generic recording. Production callers must use the named
	 * domain operations below; this remains only for deliberately malformed
	 * replay/schema fixtures.
	 */

	/** @param array<string,mixed> $payload @param array<string,mixed> $metadata */
	public function record( string $event_type, array $payload, array $metadata = array() ): GHCA_ACD_Archive_Event {
		$this->assert_test_recording_api();
		$events = $this->record_batch( array( array( 'type' => $event_type, 'payload' => $payload, 'metadata' => $metadata ) ) );
		return $events[0];
	}

	/**
	 * @param array<int,array{type:string,payload:array<string,mixed>,metadata?:array<string,mixed>}> $specs
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	public function record_batch( array $specs ): array {
		$this->assert_test_recording_api();
		return $this->append_batch( $specs );
	}

	/*
	 * Named production domain operations. One operation per approved business
	 * decision (Technical Design Section 6.2): a decision producing multiple
	 * events is one operation emitting one atomic ordered batch.
	 */

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function request_archive( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function request_replacement_archive( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function start_build( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function capture_evidence_snapshot( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function materialize_ledger( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function materialize_packet( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED, $payload );
	}

	/** @param array<string,mixed> $verified @param array<string,mixed> $finalized @return array<int,GHCA_ACD_Archive_Event> */
	public function verify_and_finalize( array $verified, array $finalized ): array {
		return $this->append_batch( array(
			array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED, 'payload' => $verified ),
			array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED, 'payload' => $finalized ),
		) );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function fail_archive( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function request_retry( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function cancel_archive( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED, $payload );
	}

	/**
	 * One correction decision: pre-claim invalidations, correction entry, and
	 * revocation commit atomically. They are never separate commands.
	 *
	 * @param array<int,array<string,mixed>> $invalidations
	 * @param array<string,mixed> $correction
	 * @param array<string,mixed> $revocation
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	public function correct( array $invalidations, array $correction, array $revocation ): array {
		$specs = array();
		foreach ( $invalidations as $payload ) {
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED, 'payload' => $payload );
		}
		$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, 'payload' => $correction );
		$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, 'payload' => $revocation );
		return $this->append_batch( $specs );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function request_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_REQUESTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function defer_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_DEFERRED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function reject_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_REJECTED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function cancel_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_CANCELLED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function authorize_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function expire_reset_authorization( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function claim_reset_execution( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_EXECUTION_CLAIMED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function complete_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_COMPLETED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function record_reset_failed_safe( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_FAILED_SAFE, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function record_reset_outcome_uncertain( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function reconcile_reset_as_completed( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_COMPLETED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function reconcile_reset_as_no_change( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_NO_CHANGE, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function require_reset_remediation( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_REMEDIATION_REQUIRED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function record_reset_remediated_restored( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::RESET_REMEDIATED_RESTORED, $payload );
	}

	/** @param array<string,mixed> $drift @param array<string,mixed>|null $failure @param array<int,array<string,mixed>> $invalidations @return array<int,GHCA_ACD_Archive_Event> */
	public function detect_source_drift( array $drift, ?array $failure = null, array $invalidations = array() ): array {
		$specs = array();
		foreach ( $invalidations as $payload ) {
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED, 'payload' => $payload );
		}
		$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED, 'payload' => $drift );
		if ( null !== $failure ) {
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED, 'payload' => $failure );
		}
		return $this->append_batch( $specs );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function resolve_source_drift_restored( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED, $payload );
	}

	/**
	 * Replacement-rebase drift recovery: one serialized decision containing
	 * the resolution, the required candidate cancellation or active-archive
	 * correction/revocation, and the newly reviewed archive/replacement
	 * request. Standalone replacement_rebased resolution is prohibited.
	 *
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $request
	 * @param array<string,mixed>|null $cancellation
	 * @param array<string,mixed>|null $correction
	 * @param array<string,mixed>|null $revocation
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	public function resolve_source_drift_rebased( array $resolved, array $request, ?array $cancellation = null, ?array $correction = null, ?array $revocation = null ): array {
		$specs = array( array( 'type' => GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED, 'payload' => $resolved ) );
		if ( null !== $correction || null !== $revocation ) {
			if ( null === $correction || null === $revocation ) {
				$this->reject( 'invalid_drift_recovery', 'Rebase correction and revocation must be provided together.' );
			}
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, 'payload' => $correction );
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, 'payload' => $revocation );
		}
		if ( null !== $cancellation ) {
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED, 'payload' => $cancellation );
		}
		$request_type = array_key_exists( 'revoked_predecessor_archive_id', $request )
			? GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED
			: GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED;
		$specs[] = array( 'type' => $request_type, 'payload' => $request );
		return $this->append_batch( $specs );
	}

	/** @param array<string,mixed> $detected @param array<int,array<string,mixed>> $invalidations @return array<int,GHCA_ACD_Archive_Event> */
	public function detect_unprotected_reset( array $detected, array $invalidations = array() ): array {
		return $this->append_incident_decision( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED, $detected, $invalidations );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function dismiss_unprotected_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DISMISSED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function confirm_unprotected_reset( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_CONFIRMED, $payload );
	}

	/** @param array<string,mixed> $detected @param array<int,array<string,mixed>> $invalidations @return array<int,GHCA_ACD_Archive_Event> */
	public function detect_integrity_violation( array $detected, array $invalidations = array() ): array {
		return $this->append_incident_decision( GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED, $detected, $invalidations );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	public function record_integrity_disposition( array $payload ): array {
		return $this->append_single( GHCA_ACD_Archive_Event_Types::INTEGRITY_INCIDENT_DISPOSITION_RECORDED, $payload );
	}

	/** @param array<string,mixed> $payload @return array<int,GHCA_ACD_Archive_Event> */
	private function append_single( string $type, array $payload ): array {
		return $this->append_batch( array( array( 'type' => $type, 'payload' => $payload ) ) );
	}

	/** @param array<string,mixed> $detected @param array<int,array<string,mixed>> $invalidations @return array<int,GHCA_ACD_Archive_Event> */
	private function append_incident_decision( string $type, array $detected, array $invalidations ): array {
		$specs = array();
		foreach ( $invalidations as $payload ) {
			$specs[] = array( 'type' => GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED, 'payload' => $payload );
		}
		$specs[] = array( 'type' => $type, 'payload' => $detected );
		return $this->append_batch( $specs );
	}

	/**
	 * @param array<int,array{type:string,payload:array<string,mixed>,metadata?:array<string,mixed>}> $specs
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	private function append_batch( array $specs ): array {
		if ( empty( $specs ) ) {
			throw new InvalidArgumentException( 'An event batch cannot be empty.' );
		}
		foreach ( $specs as $spec ) {
			if ( ! isset( $spec['type'], $spec['payload'] ) || ! is_string( $spec['type'] ) || ! is_array( $spec['payload'] ) ) {
				throw new InvalidArgumentException( 'Event batch specification is invalid.' );
			}
		}
		$this->validate_batch_contract( $specs );
		$trial  = clone $this;
		$events = array();
		$decision_size = count( $specs );
		foreach ( $specs as $index => $spec ) {
			$metadata = array( 'decision_index' => $index, 'decision_size' => $decision_size );
			$event = new GHCA_ACD_Archive_Event( $spec['type'], 1, $spec['payload'], $metadata );
			$trial->apply( $event );
			$trial->sequence++;
			$events[] = $event;
		}
		$this->state       = $trial->state;
		$this->sequence    = $trial->sequence;
		$this->uncommitted = array_merge( $this->uncommitted, $events );
		return $events;
	}

	/** @return array<int,GHCA_ACD_Archive_Event> */
	public function uncommitted_events(): array { return $this->uncommitted; }
	public function clear_uncommitted(): void { $this->uncommitted = array(); }

	/** @return array<string,mixed> */
	public function state(): array {
		$state = $this->state;
		ksort( $state['revisions'], SORT_STRING );
		ksort( $state['resets'], SORT_STRING );
		$state['sequence']       = $this->sequence;
		$state['edit_locked']    = $this->derive_edit_locked();
		$state['reset_eligible'] = null !== $state['active_archive_id'] && null === $state['active_reset_operation_id'] && ! $state['destructive_reset_seen'] && ! $this->has_open_block();
		unset( $state['pending_correction_id'] );
		return $state;
	}

	/*
	 * Closed atomic decision grammar. Every decision must match exactly one
	 * approved shape; every unlisted combination fails closed. The same
	 * validation runs for live appends and authoritative replay.
	 */

	/** @param array<int,array<string,mixed>> $specs */
	private function validate_batch_contract( array $specs ): void {
		$types = array();
		foreach ( $specs as $spec ) {
			$types[] = isset( $spec['type'] ) ? $spec['type'] : '';
		}
		if ( 1 === count( $specs ) ) {
			$this->validate_single_decision( $types[0], $specs[0]['payload'] );
			return;
		}
		if ( in_array( GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED, $types, true ) || in_array( GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED, $types, true ) ) {
			$this->validate_finalization_decision( $specs, $types );
			return;
		}
		if ( in_array( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED, $types, true ) ) {
			$this->validate_drift_detection_decision( $specs, $types );
			return;
		}
		if ( in_array( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED, $types, true ) ) {
			$this->validate_drift_rebase_decision( $specs, $types );
			return;
		}
		if ( in_array( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED, $types, true ) || in_array( GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED, $types, true ) ) {
			$this->validate_incident_decision( $specs, $types );
			return;
		}
		if ( in_array( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, $types, true ) || in_array( GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, $types, true ) ) {
			$this->validate_correction_decision( $specs, $types );
			return;
		}
		$this->reject( 'unapproved_decision_shape', 'The multi-event decision does not match any approved atomic decision shape.' );
	}

	/** @param array<string,mixed> $payload */
	private function validate_single_decision( string $type, array $payload ): void {
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED === $type || GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED === $type ) {
			$this->reject( 'incomplete_finalization_batch', 'Verification and finalization require exactly one ordered two-event decision.' );
		}
		if ( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED === $type || GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED === $type ) {
			$this->reject( 'invalid_correction_batch', 'Correction requires invalidations followed by exactly one correction and one revocation.' );
		}
		if ( GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED === $type ) {
			$this->reject( 'unapproved_decision_shape', 'Reset invalidation commits only inside its intervening correction, drift, or incident decision.' );
		}
		if ( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED === $type ) {
			$this->assert_drift_detection_context( $payload );
			if ( $this->drift_target_is_pre_finalization( $payload['archive_id'] ) ) {
				$this->reject( 'incomplete_drift_batch', 'Pre-finalization drift must also fail the candidate in the same batch.' );
			}
			$this->assert_incident_invalidations( array(), $payload['incident_id'] );
		}
		if ( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED === $type && 'replacement_rebased' === $payload['resolution_kind'] ) {
			$this->reject( 'drift_rebase_requires_new_request', 'Replacement rebase must accept the newly reviewed fingerprint through a request in the same decision.' );
		}
		if ( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED === $type || GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED === $type ) {
			$this->assert_incident_invalidations( array(), $payload['incident_id'] );
		}
	}

	/** @param array<int,array<string,mixed>> $specs @param array<int,string> $types */
	private function validate_finalization_decision( array $specs, array $types ): void {
		if ( 2 !== count( $types ) || GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED !== $types[0] || GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED !== $types[1] ) {
			$this->reject( 'invalid_finalization_batch', 'Verification and finalization require exactly one ordered two-event decision.' );
		}
		$binding_fields = array( 'archive_id', 'revision_number', 'snapshot_id', 'snapshot_digest', 'ledger_artifact_id', 'ledger_content_digest', 'packet_artifact_id', 'packet_content_digest', 'active_identity_digest', 'expected_predecessor_archive_id' );
		foreach ( $binding_fields as $field ) {
			if ( $specs[0]['payload'][ $field ] !== $specs[1]['payload'][ $field ] ) { $this->reject( 'finalization_batch_mismatch', 'Verification and finalization bindings differ.' ); }
		}
	}

	/** @param array<int,array<string,mixed>> $specs @param array<int,string> $types */
	private function validate_correction_decision( array $specs, array $types ): void {
		$correction_index = array_search( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, $types, true );
		$revocation_index = array_search( GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, $types, true );
		if ( false === $correction_index || false === $revocation_index || $revocation_index !== $correction_index + 1 || $revocation_index !== count( $types ) - 1 ) {
			$this->reject( 'invalid_correction_batch', 'Correction requires invalidations followed by exactly one correction and one revocation.' );
		}
		for ( $i = 0; $i < $correction_index; $i++ ) {
			if ( GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED !== $types[ $i ] ) { $this->reject( 'invalid_correction_batch', 'Correction batch contains an unrelated event.' ); }
		}
		$this->assert_correction_pairing( array_slice( $specs, 0, $correction_index ), $specs[ $correction_index ]['payload'], $specs[ $revocation_index ]['payload'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $invalidation_specs
	 * @param array<string,mixed> $correction_payload
	 * @param array<string,mixed> $revocation_payload
	 */
	private function assert_correction_pairing( array $invalidation_specs, array $correction_payload, array $revocation_payload ): void {
		$invalidated_ids = array();
		foreach ( $invalidation_specs as $spec ) {
			$invalidated_ids[] = $spec['payload']['reset_operation_id'];
		}
		if ( $invalidated_ids !== $revocation_payload['invalidated_reset_operation_ids'] ) {
			$this->reject( 'correction_invalidation_mismatch', 'Revocation invalidation identities do not match the decision events.' );
		}
		if ( $correction_payload['target_archive_id'] !== $revocation_payload['target_archive_id'] || $correction_payload['correction_operation_id'] !== $revocation_payload['correction_operation_id'] || $correction_payload['reason_code'] !== $revocation_payload['revocation_reason_code'] ) {
			$this->reject( 'correction_batch_mismatch', 'Correction and revocation identities/reasons differ.' );
		}
		foreach ( $invalidation_specs as $spec ) {
			if ( $spec['payload']['invalidating_reference_id'] !== $correction_payload['correction_operation_id'] ) { $this->reject( 'correction_invalidation_mismatch', 'Reset invalidation does not reference the correction operation.' ); }
		}
		$required = $this->required_preclaim_invalidations();
		if ( $required !== $invalidated_ids ) {
			$this->reject( 'active_reset_requires_invalidation', 'Correction must invalidate every applicable pre-claim reset in the same decision.' );
		}
	}

	/** @param array<int,array<string,mixed>> $specs @param array<int,string> $types */
	private function validate_drift_detection_decision( array $specs, array $types ): void {
		$drift_indexes = array_keys( $types, GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED, true );
		if ( 1 !== count( $drift_indexes ) ) { $this->reject( 'invalid_drift_batch', 'A source-drift decision requires exactly one detection event.' ); }
		$drift_index = $drift_indexes[0];
		$drift = $specs[ $drift_index ]['payload'];
		$this->assert_drift_detection_context( $drift );
		if ( $this->drift_target_is_pre_finalization( $drift['archive_id'] ) ) {
			if ( ! in_array( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED, $types, true ) ) { $this->reject( 'incomplete_drift_batch', 'Pre-finalization drift must also fail the candidate in the same batch.' ); }
			if ( $drift_index !== count( $types ) - 2 || GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED !== $types[ count( $types ) - 1 ] ) { $this->reject( 'invalid_drift_batch', 'Pre-finalization drift and failure order/cardinality is invalid.' ); }
			$failure  = $specs[ count( $specs ) - 1 ]['payload'];
			$revision = $this->state['revisions'][ $drift['archive_id'] ];
			if ( $failure['archive_id'] !== $drift['archive_id'] || $failure['build_attempt_id'] !== $revision['build_attempt_id'] || $failure['sealed_snapshot_id'] !== $revision['snapshot_id'] ) { $this->reject( 'drift_failure_mismatch', 'Source drift failure does not match the active candidate/attempt/snapshot.' ); }
		} elseif ( $drift_index !== count( $types ) - 1 ) {
			$this->reject( 'invalid_drift_batch', 'Post-finalization drift detection must be the terminal event in its decision.' );
		}
		for ( $i = 0; $i < $drift_index; $i++ ) {
			if ( GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED !== $types[ $i ] ) { $this->reject( 'invalid_drift_batch', 'Source drift decision contains an unrelated event.' ); }
		}
		$this->assert_incident_invalidations( array_slice( $specs, 0, $drift_index ), $drift['incident_id'] );
	}

	/** @param array<int,array<string,mixed>> $specs @param array<int,string> $types */
	private function validate_incident_decision( array $specs, array $types ): void {
		$last_index = count( $types ) - 1;
		$last_type  = $types[ $last_index ];
		if ( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED !== $last_type && GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED !== $last_type ) {
			$this->reject( 'invalid_incident_batch', 'An incident decision must end with exactly one detection event.' );
		}
		for ( $i = 0; $i < $last_index; $i++ ) {
			if ( GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED !== $types[ $i ] ) { $this->reject( 'invalid_incident_batch', 'Incident decision contains an unrelated event.' ); }
		}
		$this->assert_incident_invalidations( array_slice( $specs, 0, $last_index ), $specs[ $last_index ]['payload']['incident_id'] );
	}

	/** @param array<int,array<string,mixed>> $specs @param array<int,string> $types */
	private function validate_drift_rebase_decision( array $specs, array $types ): void {
		if ( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED !== $types[0] ) {
			$this->reject( 'invalid_drift_recovery', 'Rebase recovery must begin with the drift resolution.' );
		}
		$resolved = $specs[0]['payload'];
		if ( 'replacement_rebased' !== $resolved['resolution_kind'] ) {
			$this->reject( 'invalid_drift_recovery', 'Only replacement rebase may combine resolution with recovery events.' );
		}
		if ( 'OPEN' !== $this->state['source_drift_state'] ) {
			$this->reject( 'drift_not_open', 'Source drift incident does not match.' );
		}
		$last_index = count( $types ) - 1;
		$last_type  = $types[ $last_index ];
		if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED !== $last_type && GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED !== $last_type ) {
			$this->reject( 'drift_rebase_requires_new_request', 'Replacement rebase must accept the newly reviewed fingerprint through a request in the same decision.' );
		}
		$request = $specs[ $last_index ]['payload'];
		$middle  = array_slice( $types, 1, $last_index - 1 );
		$drifted_id = $this->state['source_drift_archive_id'];
		$drifted    = isset( $this->state['revisions'][ $drifted_id ] ) ? $this->state['revisions'][ $drifted_id ] : null;
		$required_middle = array();
		if ( null !== $drifted && in_array( $drifted['build_state'], array( 'REQUESTED', 'CAPTURING', 'MATERIALIZING', 'VERIFYING', 'FAILED' ), true ) ) {
			$required_middle = array( GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED );
		} elseif ( null !== $drifted && 'FINALIZED' === $drifted['build_state'] && 'ACTIVE' === $drifted['validity_state'] ) {
			$required_middle = array( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED );
		}
		if ( $middle !== $required_middle ) {
			$this->reject( 'invalid_drift_recovery', 'Rebase recovery is missing or adds candidate cancellation or correction/revocation events.' );
		}
		if ( array( GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED ) === $required_middle && $specs[1]['payload']['archive_id'] !== $drifted_id ) {
			$this->reject( 'invalid_drift_recovery', 'Rebase cancellation must abandon the drift-affected candidate.' );
		}
		if ( 2 === count( $required_middle ) ) {
			$this->assert_correction_pairing( array(), $specs[1]['payload'], $specs[2]['payload'] );
			if ( $specs[1]['payload']['target_archive_id'] !== $drifted_id ) {
				$this->reject( 'invalid_drift_recovery', 'Rebase correction must target the drift-affected active archive.' );
			}
			if ( GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED !== $last_type || $request['revoked_predecessor_archive_id'] !== $drifted_id ) {
				$this->reject( 'invalid_drift_recovery', 'Rebase after revocation requires a replacement naming the drift-affected predecessor.' );
			}
		}
		if ( $request['reviewed_source_fingerprint'] !== $resolved['verified_source_fingerprint'] ) {
			$this->reject( 'drift_recovery_fingerprint_mismatch', 'The rebased request must carry the newly verified reviewed fingerprint.' );
		}
		if ( $resolved['resolution_reference_id'] !== $request['archive_id'] ) {
			$this->reject( 'drift_recovery_reference_mismatch', 'Drift resolution must reference the replacement request it authorizes.' );
		}
	}

	/** @return array<int,string> */
	private function required_preclaim_invalidations(): array {
		$required = array();
		if ( null !== $this->state['active_reset_operation_id'] ) {
			$active_reset = $this->state['resets'][ $this->state['active_reset_operation_id'] ];
			if ( in_array( $active_reset['reset_state'], array( 'REQUESTED', 'DEFERRED', 'AUTHORIZED' ), true ) ) {
				$required[] = $this->state['active_reset_operation_id'];
			}
		}
		return $required;
	}

	/** @param array<int,array<string,mixed>> $invalidation_specs */
	private function assert_incident_invalidations( array $invalidation_specs, string $incident_id ): void {
		$actual = array();
		foreach ( $invalidation_specs as $spec ) {
			$actual[] = $spec['payload']['reset_operation_id'];
			if ( $spec['payload']['invalidating_reference_id'] !== $incident_id ) {
				$this->reject( 'incident_invalidation_mismatch', 'Reset invalidation does not reference the committed incident.' );
			}
		}
		if ( $this->required_preclaim_invalidations() !== $actual ) {
			$this->reject( 'active_reset_requires_invalidation', 'Opening a blocking incident must invalidate every applicable pre-claim reset in the same decision.' );
		}
	}

	private function drift_target_is_pre_finalization( string $archive_id ): bool {
		return isset( $this->state['revisions'][ $archive_id ] ) && 'FINALIZED' !== $this->state['revisions'][ $archive_id ]['build_state'];
	}

	/** @param array<string,mixed> $drift */
	private function assert_drift_detection_context( array $drift ): void {
		$archive_id = $drift['archive_id'];
		if ( ! isset( $this->state['revisions'][ $archive_id ] ) ) { $this->reject( 'revision_not_found', 'Archive revision does not exist.' ); }
		$revision  = $this->state['revisions'][ $archive_id ];
		$finalized = 'FINALIZED' === $revision['build_state'];
		$sealed    = null !== $revision['snapshot_id'];
		$active_reset_state = null;
		if ( null !== $this->state['active_reset_operation_id'] ) {
			$active_reset_state = $this->state['resets'][ $this->state['active_reset_operation_id'] ]['reset_state'];
		}
		$point = $drift['detection_point'];
		$valid = false;
		if ( 'pre_capture' === $point ) {
			$valid = ! $finalized && ! $sealed;
		} elseif ( 'pre_finalization' === $point ) {
			$valid = ! $finalized && $sealed;
		} elseif ( 'post_finalization' === $point ) {
			$valid = $finalized;
		} elseif ( 'pre_authorization' === $point ) {
			$valid = $finalized && in_array( $active_reset_state, array( 'REQUESTED', 'DEFERRED' ), true );
		} elseif ( 'pre_claim' === $point ) {
			$valid = $finalized && 'AUTHORIZED' === $active_reset_state;
		}
		if ( ! $valid ) {
			$this->reject( 'detection_point_mismatch', 'Source drift detection point contradicts the actual lifecycle state.' );
		}
	}

	private function apply( GHCA_ACD_Archive_Event $event ): void {
		$type = $event->type();
		$p    = $event->payload();
		switch ( $type ) {
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED:
				$this->apply_archive_requested( $p, null );
				break;
			case GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED:
				$this->apply_archive_requested( $p, $p['revoked_predecessor_archive_id'] );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED:
				$this->apply_build_started( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED:
				$this->apply_snapshot_captured( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED:
				$this->apply_materialized( $p, 'ledger' );
				break;
			case GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED:
				$this->apply_materialized( $p, 'packet' );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED:
				$this->apply_verified( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED:
				$this->apply_finalized( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED:
				$this->apply_archive_failed( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED:
				$this->apply_retry_requested( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED:
				$this->apply_archive_cancelled( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED:
				$this->apply_correction_requested( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED:
				$this->apply_archive_revoked( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_REQUESTED:
				$this->apply_reset_requested( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_DEFERRED:
				$this->apply_reset_deferred( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_REJECTED:
				$this->transition_reset( $p, array( 'REQUESTED', 'DEFERRED' ), 'REJECTED', true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_CANCELLED:
				$this->transition_reset( $p, array( 'REQUESTED', 'DEFERRED', 'AUTHORIZED' ), 'CANCELLED', true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED:
				$this->apply_reset_authorized( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED:
				$this->transition_reset_authorization( $p, 'EXPIRED' );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED:
				$this->apply_reset_invalidated( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_EXECUTION_CLAIMED:
				$this->apply_reset_claimed( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_COMPLETED:
				$this->transition_reset( $p, array( 'CLAIMED' ), 'COMPLETED', true, true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_FAILED_SAFE:
				$this->transition_reset( $p, array( 'CLAIMED' ), 'FAILED_SAFE', true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN:
				$this->transition_reset( $p, array( 'CLAIMED' ), 'OUTCOME_UNKNOWN', false );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_COMPLETED:
				$this->transition_reset( $p, array( 'OUTCOME_UNKNOWN', 'REMEDIATION_REQUIRED' ), 'COMPLETED', true, true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_NO_CHANGE:
				$this->transition_reset( $p, array( 'OUTCOME_UNKNOWN' ), 'FAILED_SAFE', true );
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_REMEDIATION_REQUIRED:
				$remediation_op =& $this->reset( $p['reset_operation_id'] );
				if ( $p['affected_scope_digest'] !== $remediation_op['scope_digest'] ) { $this->reject( 'reset_scope_mismatch', 'Reset remediation scope differs from the claimed operation.' ); }
				$this->transition_reset( $p, array( 'OUTCOME_UNKNOWN' ), 'REMEDIATION_REQUIRED', false );
				$this->state['resets'][ $p['reset_operation_id'] ]['remediation_case_id'] = $p['remediation_case_id'];
				break;
			case GHCA_ACD_Archive_Event_Types::RESET_REMEDIATED_RESTORED:
				$this->assert_reset_remediation( $p );
				$this->transition_reset( $p, array( 'REMEDIATION_REQUIRED' ), 'REMEDIATED_RESTORED', true, true );
				break;
			case GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED:
				$drift_revision =& $this->revision( $p['archive_id'] );
				$expected_fingerprint = null !== $drift_revision['captured_source_fingerprint'] ? $drift_revision['captured_source_fingerprint'] : $drift_revision['reviewed_source_fingerprint'];
				if ( $p['expected_source_fingerprint'] !== $expected_fingerprint ) { $this->reject( 'drift_expected_fingerprint_mismatch', 'Drift detection expected fingerprint contradicts the retained review/snapshot.' ); }
				if ( null !== $drift_revision['snapshot_id'] && $p['snapshot_id'] !== $drift_revision['snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Drift detection snapshot differs from the sealed snapshot.' ); }
				$this->open_incident( 'source_drift', $p['incident_id'] );
				$this->state['source_drift_archive_id'] = $p['archive_id'];
				$this->state['source_drift_expected_fingerprint'] = $p['expected_source_fingerprint'];
				break;
			case GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED:
				$this->resolve_source_drift( $p );
				break;
			case GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED:
				$unprotected_scope_digest = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $p['scope'] );
				$this->assert_incident_scope_matches_case( $p['scope'], $unprotected_scope_digest );
				$this->open_incident( 'unprotected_reset', $p['incident_id'] );
				$this->state['unprotected_reset_before_fingerprint'] = $p['before_source_fingerprint'];
				$this->state['unprotected_reset_scope_digest'] = $unprotected_scope_digest;
				break;
			case GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DISMISSED:
				$this->resolve_unprotected_reset( $p, false );
				break;
			case GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_CONFIRMED:
				$this->resolve_unprotected_reset( $p, true );
				break;
			case GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED:
				$this->open_incident( 'integrity', $p['incident_id'] );
				break;
			case GHCA_ACD_Archive_Event_Types::INTEGRITY_INCIDENT_DISPOSITION_RECORDED:
				$this->resolve_integrity( $p );
				break;
			default:
				$this->reject( 'unknown_event', 'Unknown archive event.' );
		}
	}

	/** @param array<string,mixed> $p */
	private function apply_archive_requested( array $p, ?string $predecessor ): void {
		$this->assert_can_start_revision();
		$id = $p['archive_id'];
		if ( isset( $this->state['revisions'][ $id ] ) ) { $this->reject( 'duplicate_revision', 'Archive revision already exists.' ); }
		if ( $p['revision_number'] <= $this->state['max_revision_number'] ) { $this->reject( 'revision_number_conflict', 'Archive revision numbers must increase monotonically.' ); }
		if ( null === $predecessor ) {
			if ( null !== $this->state['correction_target_archive_id'] ) { $this->reject( 'replacement_required', 'A revoked archive requires an explicit replacement.' ); }
			if ( null === $this->state['case_key'] ) {
				$this->state['case_key']        = GHCA_ACD_Archive_Canonical_JSON::detach( $p['case_key'] );
				$this->state['case_key_digest'] = GHCA_ACD_Archive_Digester::case_key(
					$p['case_key']['tenant_id'],
					$p['case_key']['site_id_decimal'],
					$p['case_key']['employee_user_id_decimal'],
					$p['case_key']['program_key'],
					$p['case_key']['cycle_key']
				);
			} elseif ( GHCA_ACD_Archive_Canonical_JSON::encode( $this->state['case_key'] ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $p['case_key'] ) ) {
				$this->reject( 'case_key_mismatch', 'An Archive Case key is immutable after the first event.' );
			}
		} else {
			if ( GHCA_ACD_Archive_Canonical_JSON::encode( $this->state['case_key'] ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $p['case_key'] ) ) {
				$this->reject( 'case_key_mismatch', 'A replacement must retain the immutable Archive Case key.' );
			}
			$target = $this->state['correction_target_archive_id'];
			if ( $predecessor !== $target || ! isset( $this->state['revisions'][ $predecessor ] ) || 'REVOKED' !== $this->state['revisions'][ $predecessor ]['validity_state'] ) {
				$this->reject( 'invalid_predecessor', 'Replacement predecessor is not the revoked correction target.' );
			}
		}
		$this->state['revisions'][ $id ] = array(
			'revision_number'            => $p['revision_number'],
			'resolved_cycle'             => GHCA_ACD_Archive_Canonical_JSON::detach( $p['resolved_cycle'] ),
			'policy_digest'              => $p['policy_digest'],
			'subject_scope_digest'       => $p['subject_scope_digest'],
			'build_state'                => 'REQUESTED',
			'validity_state'             => 'NOT_APPLICABLE',
			'predecessor_archive_id'     => $predecessor,
			'build_attempt_id'           => null,
			'pending_attempt_id'         => null,
			'pending_resume_phase'       => null,
			'retry_ordinal'              => -1,
			'snapshot_id'                => null,
			'snapshot_digest'            => null,
			'reviewed_source_fingerprint'=> $p['reviewed_source_fingerprint'],
			'captured_source_fingerprint'=> null,
			'certificate_asset_ids'      => array(),
			'certificate_content_digests'=> array(),
			'ledger_artifact_id'         => null,
			'ledger_content_digest'      => null,
			'packet_artifact_id'         => null,
			'packet_content_digest'      => null,
			'verified'                   => false,
		);
		$this->state['current_archive_id'] = $id;
		$this->state['max_revision_number'] = $p['revision_number'];
	}

	private function assert_can_start_revision(): void {
		if ( null !== $this->state['current_archive_id'] ) { $this->reject( 'active_build_exists', 'A current archive revision already exists.' ); }
		if ( null !== $this->state['active_archive_id'] ) { $this->reject( 'active_archive_exists', 'An active archive already exists.' ); }
		if ( $this->state['destructive_reset_seen'] ) { $this->reject( 'reset_already_occurred', 'A destructive reset already occurred.' ); }
		if ( $this->has_open_block() ) { $this->reject( 'incident_blocked', 'An open incident blocks archive work.' ); }
	}

	private function assert_no_open_drift(): void {
		if ( 'OPEN' === $this->state['source_drift_state'] ) {
			$this->reject( 'source_drift_blocked', 'Open source drift blocks build progress until the approved recovery decision commits.' );
		}
	}

	/** @param array<string,mixed> $p */
	private function apply_build_started( array $p ): void {
		$this->assert_no_open_drift();
		$r =& $this->revision( $p['archive_id'] );
		if ( ! in_array( $r['build_state'], array( 'REQUESTED', 'FAILED' ), true ) ) { $this->reject( 'invalid_build_start', 'Build cannot start from this state.' ); }
		if ( 'FAILED' === $r['build_state'] && null === $r['pending_attempt_id'] ) { $this->reject( 'retry_required', 'A failed revision requires ArchiveRetryRequested before it can restart.' ); }
		$phase = strtoupper( $p['start_phase'] );
		if ( ! in_array( $phase, array( 'CAPTURING', 'MATERIALIZING', 'VERIFYING' ), true ) ) { $this->reject( 'invalid_resume_phase', 'Build phase is invalid.' ); }
		if ( 'REQUESTED' === $r['build_state'] && 'CAPTURING' !== $phase ) { $this->reject( 'invalid_resume_phase', 'A new revision must begin with capture.' ); }
		if ( 'REQUESTED' === $r['build_state'] && 0 !== $p['retry_ordinal'] ) { $this->reject( 'retry_ordinal_mismatch', 'Initial build attempt ordinal must be zero.' ); }
		if ( 'CAPTURING' === $phase && null !== $r['snapshot_id'] ) { $this->reject( 'snapshot_rewrite', 'A sealed snapshot cannot be recaptured.' ); }
		if ( 'MATERIALIZING' === $phase && null === $r['snapshot_id'] ) { $this->reject( 'snapshot_required', 'Materialization requires a sealed snapshot.' ); }
		if ( 'VERIFYING' === $phase && ( null === $r['ledger_artifact_id'] || null === $r['packet_artifact_id'] ) ) { $this->reject( 'artifacts_required', 'Verification requires both candidate layers.' ); }
		if ( null !== $r['pending_attempt_id'] && $r['pending_attempt_id'] !== $p['build_attempt_id'] ) { $this->reject( 'attempt_mismatch', 'Retry attempt identity does not match.' ); }
		if ( null !== $r['pending_attempt_id'] && ( strtoupper( $r['pending_resume_phase'] ) !== $phase || $p['retry_ordinal'] !== $r['retry_ordinal'] + 1 || $p['snapshot_id'] !== $r['snapshot_id'] ) ) { $this->reject( 'retry_ordinal_mismatch', 'Retry phase, ordinal, or snapshot binding does not match the accepted retry.' ); }
		$r['build_attempt_id']   = $p['build_attempt_id'];
		$r['pending_attempt_id'] = null;
		$r['pending_resume_phase'] = null;
		$r['retry_ordinal']      = $p['retry_ordinal'];
		$r['build_state']        = $phase;
	}

	/** @param array<string,mixed> $p */
	private function apply_snapshot_captured( array $p ): void {
		$this->assert_no_open_drift();
		$r =& $this->revision( $p['archive_id'] );
		if ( 'CAPTURING' !== $r['build_state'] || null !== $r['snapshot_id'] ) { $this->reject( 'invalid_snapshot_transition', 'Snapshot can be captured once during CAPTURING.' ); }
		if ( $p['revision_number'] !== $r['revision_number'] ) { $this->reject( 'snapshot_revision_mismatch', 'Snapshot revision does not match the requested revision.' ); }
		if ( $p['policy_digest'] !== $r['policy_digest'] ) { $this->reject( 'snapshot_policy_mismatch', 'Snapshot policy differs from the reviewed request.' ); }
		if ( $p['subject_scope_digest'] !== $r['subject_scope_digest'] ) { $this->reject( 'snapshot_scope_mismatch', 'Snapshot subject scope differs from the reviewed request.' ); }
		if ( GHCA_ACD_Archive_Canonical_JSON::encode( $p['resolved_cycle'] ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $r['resolved_cycle'] ) ) { $this->reject( 'snapshot_cycle_mismatch', 'Snapshot cycle differs from the resolved request cycle.' ); }
		if ( $p['reviewed_source_fingerprint'] !== $r['reviewed_source_fingerprint'] || $p['captured_source_fingerprint'] !== $r['reviewed_source_fingerprint'] ) {
			$this->reject( 'source_drift', 'Captured evidence does not match the reviewed fingerprint.' );
		}
		$r['snapshot_id']                 = $p['snapshot_id'];
		$r['snapshot_digest']             = $p['snapshot_digest'];
		$r['captured_source_fingerprint'] = $p['captured_source_fingerprint'];
		$r['certificate_asset_ids']       = GHCA_ACD_Archive_Canonical_JSON::detach( $p['certificate_asset_ids'] );
		$r['certificate_content_digests'] = GHCA_ACD_Archive_Canonical_JSON::detach( $p['certificate_content_digests'] );
		$r['build_state']                 = 'MATERIALIZING';
	}

	/** @param array<string,mixed> $p */
	private function apply_materialized( array $p, string $kind ): void {
		$this->assert_no_open_drift();
		$r =& $this->revision( $p['archive_id'] );
		if ( 'MATERIALIZING' !== $r['build_state'] || $p['snapshot_id'] !== $r['snapshot_id'] || $p['snapshot_digest'] !== $r['snapshot_digest'] || $p['build_attempt_id'] !== $r['build_attempt_id'] ) {
			$this->reject( 'invalid_materialization', 'Materialization does not match the active revision/snapshot/attempt.' );
		}
		if ( 'ledger' === $kind ) {
			if ( null !== $r['ledger_artifact_id'] ) { $this->reject( 'duplicate_ledger', 'Ledger is already materialized.' ); }
			$r['ledger_artifact_id']    = $p['ledger_artifact_id'];
			$r['ledger_content_digest'] = $p['content_digest'];
		} else {
			if ( null !== $r['packet_artifact_id'] ) { $this->reject( 'duplicate_packet', 'Packet is already materialized.' ); }
			if ( $p['certificate_content_digests'] !== $r['certificate_content_digests'] ) { $this->reject( 'certificate_manifest_mismatch', 'Packet certificate digests do not match the sealed snapshot manifest.' ); }
			$r['packet_artifact_id']    = $p['packet_artifact_id'];
			$r['packet_content_digest'] = $p['content_digest'];
		}
		if ( null !== $r['ledger_artifact_id'] && null !== $r['packet_artifact_id'] ) { $r['build_state'] = 'VERIFYING'; }
	}

	/** @param array<string,mixed> $p */
	private function apply_verified( array $p ): void {
		$r =& $this->revision( $p['archive_id'] );
		if ( 'VERIFYING' !== $r['build_state'] || $this->has_open_block() ) { $this->reject( 'verification_blocked', 'Revision is not eligible for verification.' ); }
		$this->assert_revision_evidence( $r, $p );
		$this->assert_revision_binding( $r, $p );
		if ( $p['source_fingerprint'] !== $r['captured_source_fingerprint'] ) { $this->reject( 'source_drift', 'Verification fingerprint differs from the snapshot.' ); }
		$r['verified'] = true;
	}

	/** @param array<string,mixed> $p */
	private function apply_finalized( array $p ): void {
		$r =& $this->revision( $p['archive_id'] );
		if ( 'VERIFYING' !== $r['build_state'] || ! $r['verified'] || null !== $this->state['active_archive_id'] || $this->has_open_block() ) {
			$this->reject( 'finalization_blocked', 'Revision is not eligible for finalization.' );
		}
		$this->assert_revision_evidence( $r, $p );
		$this->assert_revision_binding( $r, $p );
		$predecessor = $r['predecessor_archive_id'];
		if ( $p['expected_predecessor_archive_id'] !== $predecessor ) { $this->reject( 'predecessor_mismatch', 'Finalization predecessor does not match.' ); }
		if ( null !== $predecessor ) {
			$old =& $this->revision( $predecessor );
			if ( 'REVOKED' !== $old['validity_state'] ) { $this->reject( 'predecessor_not_revoked', 'Replacement predecessor must remain revoked.' ); }
			$old['validity_state'] = 'SUPERSEDED';
			$this->state['correction_target_archive_id'] = null;
		}
		$r['build_state']    = 'FINALIZED';
		$r['validity_state'] = 'ACTIVE';
		$this->state['active_archive_id']  = $p['archive_id'];
		$this->state['current_archive_id'] = $p['archive_id'];
	}

	/** @param array<string,mixed> $revision @param array<string,mixed> $p */
	private function assert_revision_evidence( array $revision, array $p ): void {
		$checks = array(
			'snapshot_id'           => 'snapshot_id',
			'snapshot_digest'       => 'snapshot_digest',
			'ledger_artifact_id'    => 'ledger_artifact_id',
			'ledger_content_digest' => 'ledger_content_digest',
			'packet_artifact_id'    => 'packet_artifact_id',
			'packet_content_digest' => 'packet_content_digest',
		);
		foreach ( $checks as $payload_key => $state_key ) {
			if ( $p[ $payload_key ] !== $revision[ $state_key ] ) { $this->reject( 'evidence_mismatch', 'Archive evidence identity/digest mismatch.' ); }
		}
	}

	/** @param array<string,mixed> $revision @param array<string,mixed> $p */
	private function assert_revision_binding( array $revision, array $p ): void {
		if ( $p['revision_number'] !== $revision['revision_number'] ) { $this->reject( 'revision_binding_mismatch', 'Archive revision binding does not match.' ); }
		if ( $p['active_identity_digest'] !== $this->state['case_key_digest'] ) { $this->reject( 'active_identity_mismatch', 'Archive active identity does not match the immutable case identity.' ); }
		if ( $p['expected_predecessor_archive_id'] !== $revision['predecessor_archive_id'] ) { $this->reject( 'predecessor_mismatch', 'Archive predecessor binding does not match.' ); }
	}

	/** @param array<string,mixed> $p */
	private function apply_archive_failed( array $p ): void {
		$r =& $this->revision( $p['archive_id'] );
		if ( ! in_array( $r['build_state'], array( 'REQUESTED', 'CAPTURING', 'MATERIALIZING', 'VERIFYING' ), true ) ) { $this->reject( 'invalid_failure', 'Archive cannot fail from this state.' ); }
		if ( $p['build_attempt_id'] !== $r['build_attempt_id'] ) { $this->reject( 'attempt_mismatch', 'Failure attempt does not match.' ); }
		$expected_phase = strtolower( $r['build_state'] );
		$phase_matches = $p['phase'] === $expected_phase || ( 'VERIFYING' === $r['build_state'] && 'finalizing' === $p['phase'] );
		if ( ! $phase_matches ) { $this->reject( 'failure_phase_mismatch', 'Failure phase differs from the active build phase.' ); }
		if ( null !== $r['snapshot_id'] && $p['sealed_snapshot_id'] !== $r['snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Failure after capture must preserve the sealed snapshot identity.' ); }
		if ( null === $r['snapshot_id'] && null !== $p['sealed_snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Failure cannot invent a sealed snapshot.' ); }
		$r['build_state'] = 'FAILED';
	}

	/** @param array<string,mixed> $p */
	private function apply_retry_requested( array $p ): void {
		$this->assert_no_open_drift();
		$r =& $this->revision( $p['archive_id'] );
		if ( 'FAILED' !== $r['build_state'] || $p['prior_build_attempt_id'] !== $r['build_attempt_id'] || $p['new_build_attempt_id'] === $r['build_attempt_id'] ) { $this->reject( 'invalid_retry', 'Archive retry identity/state is invalid.' ); }
		if ( null !== $r['pending_attempt_id'] ) { $this->reject( 'retry_already_pending', 'Only one retry attempt may be pending.' ); }
		if ( $p['sealed_snapshot_id'] !== $r['snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Retry must preserve the sealed snapshot identity.' ); }
		if ( null === $r['snapshot_id'] && 'capturing' !== $p['resume_phase'] ) { $this->reject( 'invalid_retry_phase', 'Retry before snapshot capture must resume capture.' ); }
		if ( null !== $r['snapshot_id'] && 'capturing' === $p['resume_phase'] ) { $this->reject( 'invalid_retry_phase', 'Retry cannot recapture a sealed snapshot.' ); }
		if ( 'verifying' === $p['resume_phase'] && ( null === $r['ledger_artifact_id'] || null === $r['packet_artifact_id'] ) ) { $this->reject( 'invalid_retry_phase', 'Verification retry requires both candidate artifacts.' ); }
		$r['pending_attempt_id'] = $p['new_build_attempt_id'];
		$r['pending_resume_phase'] = $p['resume_phase'];
	}

	/** @param array<string,mixed> $p */
	private function apply_archive_cancelled( array $p ): void {
		$r =& $this->revision( $p['archive_id'] );
		if ( ! in_array( $r['build_state'], array( 'REQUESTED', 'CAPTURING', 'MATERIALIZING', 'VERIFYING', 'FAILED' ), true ) ) { $this->reject( 'invalid_cancellation', 'Finalized/cancelled archive cannot be cancelled.' ); }
		// While source drift is open on this candidate, its cancellation is only
		// valid inside the approved rebase-recovery decision (where the resolution
		// is applied first). A separately committed cancellation would let a later
		// rebase omit the cancellation required by the same-serialized-decision rule.
		if ( 'OPEN' === $this->state['source_drift_state'] && $this->state['source_drift_archive_id'] === $p['archive_id'] ) {
			$this->reject( 'source_drift_blocked', 'The drift-affected candidate can be cancelled only inside the approved rebase-recovery decision while drift is open.' );
		}
		// A requested revision has no build attempt: the immutable cancellation event
		// must state that truth with a null attempt and can never invent one. Once an
		// attempt exists it must match exactly.
		if ( $p['build_attempt_id'] !== $r['build_attempt_id'] ) { $this->reject( 'attempt_mismatch', 'Cancellation attempt identity must match the revision build attempt, or be null before any attempt exists.' ); }
		$r['build_state'] = 'CANCELLED';
		if ( $this->state['current_archive_id'] === $p['archive_id'] ) { $this->state['current_archive_id'] = null; }
	}

	/** @param array<string,mixed> $p */
	private function apply_correction_requested( array $p ): void {
		if ( $this->state['destructive_reset_seen'] ) { $this->reject( 'post_reset_correction_forbidden', 'Ordinary correction cannot represent the post-reset preserved-evidence amendment workflow.' ); }
		if ( $this->has_open_block() || null !== $this->state['active_reset_operation_id'] ) { $this->reject( 'correction_blocked', 'Reset or incident state blocks correction.' ); }
		if ( $this->state['active_archive_id'] !== $p['target_archive_id'] ) { $this->reject( 'active_archive_mismatch', 'Correction target is not active.' ); }
		$r =& $this->revision( $p['target_archive_id'] );
		if ( 'FINALIZED' !== $r['build_state'] || 'ACTIVE' !== $r['validity_state'] || $p['target_snapshot_id'] !== $r['snapshot_id'] ) { $this->reject( 'invalid_correction_target', 'Correction target is not an active finalized snapshot.' ); }
		if ( $p['affected_scope_digest'] !== $r['subject_scope_digest'] ) { $this->reject( 'correction_scope_mismatch', 'Correction affected scope differs from the target archive subject scope.' ); }
		$this->state['pending_correction_id'] = $p['correction_operation_id'];
	}

	/** @param array<string,mixed> $p */
	private function apply_archive_revoked( array $p ): void {
		if ( $this->state['pending_correction_id'] !== $p['correction_operation_id'] || $this->state['active_archive_id'] !== $p['target_archive_id'] ) { $this->reject( 'invalid_revocation', 'Revocation is not paired with the active correction.' ); }
		$r =& $this->revision( $p['target_archive_id'] );
		$r['validity_state'] = 'REVOKED';
		$this->state['active_archive_id']            = null;
		$this->state['current_archive_id']           = null;
		$this->state['correction_target_archive_id'] = $p['target_archive_id'];
		$this->state['pending_correction_id']        = null;
	}

	/** @param array<string,mixed> $p */
	private function apply_reset_requested( array $p ): void {
		$id = $p['reset_operation_id'];
		if ( $this->has_open_block() ) { $this->reject( 'incident_blocked', 'An open incident blocks a reset request.' ); }
		if ( isset( $this->state['resets'][ $id ] ) || null !== $this->state['active_reset_operation_id'] || $this->state['destructive_reset_seen'] || 'CONFIRMED_RESET' === $this->state['unprotected_reset_state'] ) {
			$this->reject( 'reset_request_blocked', 'Another or prior destructive reset blocks this request.' );
		}
		$r =& $this->revision( $p['bound_archive_id'] );
		if ( null === $r['snapshot_id'] && null !== $p['snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Early reset request cannot invent a snapshot.' ); }
		if ( null !== $r['snapshot_id'] && $p['snapshot_id'] !== $r['snapshot_id'] ) { $this->reject( 'snapshot_mismatch', 'Reset request snapshot does not match.' ); }
		$this->assert_reset_scope_matches_case( $p['scope'], $p['scope_digest'], $r['subject_scope_digest'] );
		$this->state['resets'][ $id ] = array(
			'reset_state'     => 'REQUESTED',
			'archive_id'      => $p['bound_archive_id'],
			'snapshot_id'     => $p['snapshot_id'],
			'scope_digest'    => $p['scope_digest'],
			'scope'           => GHCA_ACD_Archive_Canonical_JSON::detach( $p['scope'] ),
			'consent_mode'    => $p['consent_mode'],
			'request_valid_until_gmt' => $p['request_valid_until_gmt'],
			'authorization_id'=> null,
			'gateway_key'     => null,
			'issued_at_gmt'   => null,
			'expires_at_gmt'  => null,
			'source_fingerprint' => null,
			'upstream_operation_id' => null,
			'remediation_case_id' => null,
		);
		$this->state['active_reset_operation_id'] = $id;
	}

	/**
	 * The destructive scope must target the immutable Archive Case exactly:
	 * cross-employee, cross-program, and cross-cycle scopes fail closed, and the
	 * exact course set must equal the course scope authorized by the bound
	 * archive/snapshot subject-scope evidence. An internally consistent
	 * caller-provided scope and digest are not sufficient.
	 *
	 * @param array<string,mixed> $scope
	 */
	private function assert_reset_scope_matches_case( array $scope, string $scope_digest, string $authorized_scope_digest ): void {
		$this->assert_scope_targets_case( $scope, 'reset_scope_case_mismatch', 'Reset scope must target the immutable Archive Case subject, program, and cycle.' );
		if ( $scope_digest !== $authorized_scope_digest ) {
			$this->reject( 'reset_scope_evidence_mismatch', 'Reset course scope must exactly match the course scope authorized by the archive/snapshot subject-scope evidence.' );
		}
	}

	/**
	 * @param array<string,mixed> $scope
	 */
	private function assert_incident_scope_matches_case( array $scope, string $scope_digest ): void {
		$this->assert_scope_targets_case( $scope, 'incident_scope_case_mismatch', 'Incident scope must target the immutable Archive Case subject, program, and cycle.' );
		$evidence_archive_id = $this->state['active_archive_id'];
		if ( null === $evidence_archive_id ) { $evidence_archive_id = $this->state['current_archive_id']; }
		if ( null === $evidence_archive_id ) { $evidence_archive_id = $this->state['correction_target_archive_id']; }
		if ( null === $evidence_archive_id ) {
			// Cancellation releases the current-build lock but does not erase the
			// retained immutable candidate. Use the newest retained revision so a
			// post-cancellation incident cannot silently broaden or substitute the
			// course set merely because every lifecycle pointer is null.
			$latest_revision_number = 0;
			foreach ( $this->state['revisions'] as $archive_id => $revision ) {
				if ( $revision['revision_number'] > $latest_revision_number ) {
					$latest_revision_number = $revision['revision_number'];
					$evidence_archive_id = $archive_id;
				}
			}
		}
		if ( null === $evidence_archive_id ) {
			$this->reject( 'incident_scope_evidence_unavailable', 'Incident course scope cannot be accepted without retained archive subject-scope evidence.' );
		}
		if ( $scope_digest !== $this->state['revisions'][ $evidence_archive_id ]['subject_scope_digest'] ) {
			$this->reject( 'incident_scope_evidence_mismatch', 'Incident course scope must exactly match the retained archive subject-scope evidence.' );
		}
	}

	/** @param array<string,mixed> $scope */
	private function assert_scope_targets_case( array $scope, string $reason_code, string $message ): void {
		$case_key = $this->state['case_key'];
		if ( null === $case_key
			|| $scope['employee_user_id_decimal'] !== $case_key['employee_user_id_decimal']
			|| $scope['program_key'] !== $case_key['program_key']
			|| $scope['cycle_key'] !== $case_key['cycle_key'] ) {
			$this->reject( $reason_code, $message );
		}
	}

	/** @param array<string,mixed> $p */
	private function apply_reset_deferred( array $p ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( 'REQUESTED' !== $op['reset_state'] ) { $this->reject( 'invalid_reset_transition', 'Reset deferral is invalid.' ); }
		if ( 'bounded_reevaluation' !== $op['consent_mode'] || null === $op['request_valid_until_gmt'] ) { $this->reject( 'consent_mode_mismatch', 'Only bounded reevaluation consent permits deferral.' ); }
		if ( $p['consent_expires_at_gmt'] !== $op['request_valid_until_gmt'] || strcmp( $p['reevaluation_deadline_gmt'], $op['request_valid_until_gmt'] ) > 0 ) { $this->reject( 'consent_window_mismatch', 'Deferral differs from the request consent window.' ); }
		$op['reset_state'] = 'DEFERRED';
	}

	/** @param array<string,mixed> $p */
	private function apply_reset_authorized( array $p ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( ! in_array( $op['reset_state'], array( 'REQUESTED', 'DEFERRED' ), true ) || $this->has_open_block() ) { $this->reject( 'reset_not_eligible', 'Reset request is not eligible for authorization.' ); }
		$r =& $this->revision( $p['archive_id'] );
		if ( $this->state['active_archive_id'] !== $p['archive_id'] || 'FINALIZED' !== $r['build_state'] || 'ACTIVE' !== $r['validity_state'] || $op['archive_id'] !== $p['archive_id'] || $p['snapshot_id'] !== $r['snapshot_id'] || $p['scope_digest'] !== $op['scope_digest'] || $p['source_fingerprint'] !== $r['captured_source_fingerprint'] ) {
			$this->reject( 'reset_not_eligible', 'Reset authorization does not exactly match the active finalized archive.' );
		}
		if ( strcmp( $p['issued_at_gmt'], $p['expires_at_gmt'] ) > 0 ) { $this->reject( 'consent_window_mismatch', 'Authorization expiry cannot precede its issue time.' ); }
		if ( null !== $op['request_valid_until_gmt'] && strcmp( $p['expires_at_gmt'], $op['request_valid_until_gmt'] ) > 0 ) { $this->reject( 'consent_window_mismatch', 'Authorization exceeds the explicit consent validity window.' ); }
		$op['snapshot_id']      = $p['snapshot_id'];
		$op['authorization_id'] = $p['authorization_id'];
		$op['gateway_key']      = $p['gateway_key'];
		$op['issued_at_gmt']    = $p['issued_at_gmt'];
		$op['expires_at_gmt']   = $p['expires_at_gmt'];
		$op['source_fingerprint'] = $p['source_fingerprint'];
		$op['reset_state']      = 'AUTHORIZED';
	}

	/** @param array<string,mixed> $p */
	private function transition_reset_authorization( array $p, string $next ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( 'AUTHORIZED' !== $op['reset_state'] || $op['authorization_id'] !== $p['authorization_id'] ) { $this->reject( 'authorization_mismatch', 'Reset authorization is not active.' ); }
		if ( $p['scheduled_expires_at_gmt'] !== $op['expires_at_gmt'] || strcmp( $p['observed_at_gmt'], $op['expires_at_gmt'] ) < 0 ) { $this->reject( 'authorization_expiry_mismatch', 'Expiry event does not prove the assigned authorization expiry.' ); }
		$op['reset_state'] = $next;
		$this->state['active_reset_operation_id'] = null;
	}

	/** @param array<string,mixed> $p */
	private function apply_reset_invalidated( array $p ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( ! in_array( $op['reset_state'], array( 'REQUESTED', 'DEFERRED', 'AUTHORIZED' ), true ) ) { $this->reject( 'invalid_invalidation', 'Only pre-claim reset work can be invalidated.' ); }
		if ( $op['authorization_id'] !== $p['authorization_id'] ) { $this->reject( 'authorization_mismatch', 'Invalidation authorization does not match.' ); }
		$op['reset_state'] = 'INVALIDATED';
		$this->state['active_reset_operation_id'] = null;
	}

	/** @param array<string,mixed> $p */
	private function apply_reset_claimed( array $p ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		$r  =& $this->revision( $op['archive_id'] );
		if ( 'AUTHORIZED' !== $op['reset_state'] || $this->has_open_block() || $this->state['active_archive_id'] !== $op['archive_id'] || 'ACTIVE' !== $r['validity_state'] || $op['authorization_id'] !== $p['authorization_id'] || $op['scope_digest'] !== $p['scope_digest'] || $r['captured_source_fingerprint'] !== $p['source_fingerprint'] ) {
			$this->reject( 'reset_claim_blocked', 'Reset claim does not exactly match the active authorization and archive.' );
		}
		if ( $p['gateway_key'] !== $op['gateway_key'] ) { $this->reject( 'reset_gateway_mismatch', 'Reset claim gateway differs from the authorization.' ); }
		if ( strcmp( $p['claimed_at_gmt'], $op['issued_at_gmt'] ) < 0 || strcmp( $p['claimed_at_gmt'], $op['expires_at_gmt'] ) >= 0 ) { $this->reject( 'authorization_expired', 'Reset claim must commit strictly before the explicit authorization expiry.' ); }
		$op['upstream_operation_id'] = $p['upstream_operation_id'];
		$op['reset_state'] = 'CLAIMED';
	}

	/** @param array<string,mixed> $p @param array<int,string> $from */
	private function transition_reset( array $p, array $from, string $next, bool $terminal, bool $destructive = false ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( ! in_array( $op['reset_state'], $from, true ) ) { $this->reject( 'invalid_reset_transition', 'Reset transition is invalid.' ); }
		if ( isset( $p['upstream_operation_id'] ) && $p['upstream_operation_id'] !== $op['upstream_operation_id'] ) { $this->reject( 'upstream_operation_mismatch', 'Reset outcome does not match the claimed upstream operation.' ); }
		if ( array_key_exists( 'authorization_id', $p ) && $p['authorization_id'] !== $op['authorization_id'] ) { $this->reject( 'authorization_mismatch', 'Reset transition authorization does not match.' ); }
		if ( isset( $p['unchanged_source_fingerprint'] ) && $p['unchanged_source_fingerprint'] !== $op['source_fingerprint'] ) { $this->reject( 'source_fingerprint_mismatch', 'No-change outcome does not preserve the authorized source fingerprint.' ); }
		if ( isset( $p['source_fingerprint'] ) && $p['source_fingerprint'] !== $op['source_fingerprint'] ) { $this->reject( 'source_fingerprint_mismatch', 'Reconciled no-change outcome does not preserve the authorized source fingerprint.' ); }
		$op['reset_state'] = $next;
		if ( $terminal ) { $this->state['active_reset_operation_id'] = null; }
		if ( $destructive ) { $this->state['destructive_reset_seen'] = true; }
	}

	/** @param array<string,mixed> $p */
	private function assert_reset_remediation( array $p ): void {
		$op =& $this->reset( $p['reset_operation_id'] );
		if ( $op['remediation_case_id'] !== $p['remediation_case_id'] || $op['upstream_operation_id'] !== $p['upstream_operation_id'] ) { $this->reject( 'remediation_mismatch', 'Reset remediation identity does not match.' ); }
		if ( $p['restored_source_fingerprint'] !== $op['source_fingerprint'] ) { $this->reject( 'source_fingerprint_mismatch', 'Restored source fingerprint differs from the authorized baseline.' ); }
	}

	private function open_incident( string $kind, string $incident_id ): void {
		$state_key = $kind . '_state';
		$id_key    = $kind . '_incident_id';
		if ( 'NONE' !== $this->state[ $state_key ] && ! in_array( $this->state[ $state_key ], array( 'RESOLVED', 'DISMISSED_NO_RESET', 'DISPOSITION_RECORDED' ), true ) ) { $this->reject( 'incident_already_open', 'An incident is already open.' ); }
		$this->state[ $state_key ] = 'OPEN';
		$this->state[ $id_key ]    = $incident_id;
		if ( 'integrity' === $kind ) {
			// A newly opened incident is undisposed, but an earlier confirmed
			// compromise and any still-effective restrictions from prior incidents
			// are irreversible and must survive the new incident.
			$this->state['integrity_disposition'] = null;
		}
	}

	/** @param array<string,mixed> $p */
	private function resolve_source_drift( array $p ): void {
		if ( 'OPEN' !== $this->state['source_drift_state'] || $p['incident_id'] !== $this->state['source_drift_incident_id'] ) { $this->reject( 'drift_not_open', 'Source drift incident does not match.' ); }
		if ( 'restored' === $p['resolution_kind'] && $p['verified_source_fingerprint'] !== $this->state['source_drift_expected_fingerprint'] ) { $this->reject( 'drift_resolution_unproven', 'Restoration does not prove the required source fingerprint.' ); }
		if ( 'replacement_rebased' === $p['resolution_kind'] ) {
			if ( $p['verified_source_fingerprint'] === $this->state['source_drift_expected_fingerprint'] ) { $this->reject( 'drift_resolution_unproven', 'Replacement path must establish an explicitly new reviewed fingerprint.' ); }
		}
		$this->state['source_drift_state'] = 'RESOLVED';
	}

	/** @param array<string,mixed> $p */
	private function resolve_unprotected_reset( array $p, bool $confirmed ): void {
		if ( 'OPEN' !== $this->state['unprotected_reset_state'] || $p['incident_id'] !== $this->state['unprotected_reset_incident_id'] ) { $this->reject( 'incident_not_open', 'Unprotected reset incident does not match.' ); }
		if ( ! $confirmed && $p['verified_source_fingerprint'] !== $this->state['unprotected_reset_before_fingerprint'] ) { $this->reject( 'unprotected_reset_resolution_unproven', 'Dismissal does not prove restoration to the required fingerprint.' ); }
		if ( $confirmed && $p['affected_scope_digest'] !== $this->state['unprotected_reset_scope_digest'] ) { $this->reject( 'reset_scope_mismatch', 'Confirmed unprotected reset scope differs from the detected scope.' ); }
		$this->state['unprotected_reset_state'] = $confirmed ? 'CONFIRMED_RESET' : 'DISMISSED_NO_RESET';
		if ( $confirmed ) { $this->state['destructive_reset_seen'] = true; }
	}

	/** @param array<string,mixed> $p */
	private function resolve_integrity( array $p ): void {
		if ( 'OPEN' !== $this->state['integrity_state'] || $p['incident_id'] !== $this->state['integrity_incident_id'] ) { $this->reject( 'incident_not_open', 'Integrity incident does not match.' ); }
		$this->state['integrity_state']       = 'DISPOSITION_RECORDED';
		$this->state['integrity_disposition'] = $p['disposition_code'];
		// A confirmed compromise is disclosed and fail-closed forever (PRD Section
		// 5). Restrictions accumulate: a later incident's clean disposition can
		// never erase an earlier incident's unresolved restriction.
		if ( 'confirmed_compromise' === $p['disposition_code'] ) {
			$this->state['integrity_compromise_confirmed'] = true;
		}
		foreach ( $p['remaining_restrictions'] as $restriction ) {
			if ( ! in_array( $restriction, $this->state['integrity_remaining_restrictions'], true ) ) {
				$this->state['integrity_remaining_restrictions'][] = $restriction;
			}
		}
	}

	/** @return array<string,mixed> */
	private function &revision( string $archive_id ): array {
		if ( ! isset( $this->state['revisions'][ $archive_id ] ) ) { $this->reject( 'revision_not_found', 'Archive revision does not exist.' ); }
		return $this->state['revisions'][ $archive_id ];
	}

	/** @return array<string,mixed> */
	private function &reset( string $reset_operation_id ): array {
		if ( ! isset( $this->state['resets'][ $reset_operation_id ] ) ) { $this->reject( 'reset_not_found', 'Reset operation does not exist.' ); }
		return $this->state['resets'][ $reset_operation_id ];
	}

	private function has_open_block_for_integrity(): bool {
		// A confirmed compromise and any still-effective restriction are
		// irreversible operational blocks that persist across later incidents;
		// only a clean disposition of every restriction removes the block.
		if ( $this->state['integrity_compromise_confirmed'] ) { return true; }
		return array() !== $this->state['integrity_remaining_restrictions'];
	}

	private function has_open_block(): bool {
		if ( 'OPEN' === $this->state['source_drift_state'] ) { return true; }
		if ( in_array( $this->state['unprotected_reset_state'], array( 'OPEN', 'CONFIRMED_RESET' ), true ) ) { return true; }
		if ( 'OPEN' === $this->state['integrity_state'] ) { return true; }
		if ( $this->has_open_block_for_integrity() ) { return true; }
		return false;
	}

	private function derive_edit_locked(): bool {
		if ( $this->has_open_block() || null !== $this->state['active_archive_id'] || null !== $this->state['active_reset_operation_id'] || null !== $this->state['correction_target_archive_id'] ) { return true; }
		if ( null !== $this->state['current_archive_id'] ) {
			$state = $this->state['revisions'][ $this->state['current_archive_id'] ]['build_state'];
			return ! in_array( $state, array( 'CANCELLED' ), true );
		}
		return false;
	}

	private function assert_test_recording_api(): void {
		if ( ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) || ! GHCA_ACD_ARCHIVE_TESTING ) {
			throw new LogicException( 'Generic event recording is test-only; use a named domain operation.' );
		}
	}

	private function reject( string $code, string $message ): void {
		throw new GHCA_ACD_Archive_Transition_Exception( $code, $message );
	}
}
