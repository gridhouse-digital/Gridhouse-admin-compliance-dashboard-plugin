<?php

/**
 * Synchronous reset-operation projector (archive_reset_state) plus the
 * event-derived single-use authorization enforcement rows
 * (archive_reset_authorizations).
 *
 * Event treatment (row change vs semantic no-op; the projector head always
 * advances for every event via the coordinator):
 *
 * - ResetRequested: creates the reset-operation row.
 * - ResetDeferred: DEFERRED with the recorded reevaluation condition/window.
 * - ResetRejected: terminal REJECTED with the stable rejection outcome.
 * - ResetCancelled: terminal CANCELLED; an issued authorization closes.
 * - ResetAuthorized: AUTHORIZED and inserts the issued enforcement row.
 * - ResetAuthorizationExpired: terminal EXPIRED; enforcement issued->expired.
 * - ResetOperationInvalidated: terminal INVALIDATED; an issued enforcement
 *   row closes as invalidated.
 * - ResetExecutionClaimed: CLAIMED; enforcement atomically issued->consumed.
 * - ResetCompleted / ResetFailedSafe / ResetOutcomeBecameUncertain: outcome
 *   states with stable outcome codes.
 * - ResetReconciledAsCompleted / ResetReconciledAsNoChange /
 *   ResetRemediationRequired / ResetRemediatedRestored: reconciliation
 *   states with stable reconciliation codes.
 * - Every archive, correction, drift, unprotected-reset, and integrity event:
 *   semantic no-op for reset rows.
 */
final class GHCA_ACD_Archive_Reset_Projector {
	const PROJECTOR_KEY = 'reset_state';

	const RESET_STATES = array(
		'REQUESTED', 'DEFERRED', 'REJECTED', 'CANCELLED', 'AUTHORIZED', 'EXPIRED',
		'INVALIDATED', 'CLAIMED', 'COMPLETED', 'FAILED_SAFE', 'OUTCOME_UNKNOWN',
		'REMEDIATION_REQUIRED', 'REMEDIATED_RESTORED',
	);
	const AUTH_STATES = array( 'issued', 'consumed', 'expired', 'invalidated', 'cancelled' );

	/** @var GHCA_ACD_WPDB_Archive_Projection_Repository */
	private $repository;

	public function __construct( GHCA_ACD_WPDB_Archive_Projection_Repository $repository ) {
		$this->repository = $repository;
	}

	/** @return bool True when an entity row changed; false for a semantic no-op. */
	public function apply( GHCA_ACD_Archive_Event $event, string $now_gmt ): bool {
		$type    = $event->type();
		$payload = $event->payload();
		$occurred_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $event->recorded_document()['occurred_at_gmt'] );
		switch ( $type ) {
			case GHCA_ACD_Archive_Event_Types::RESET_REQUESTED:
				return $this->create_reset( $event, $payload, $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_DEFERRED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'          => $this->reset_state( 'DEFERRED' ),
					'deferred_until_gmt'   => GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['reevaluation_deadline_gmt'] ),
					'defer_condition_code' => $payload['condition_code'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_REJECTED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'   => $this->reset_state( 'REJECTED' ),
					'outcome_at_gmt' => $occurred_db,
					'outcome_code'  => $payload['rejection_code'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_CANCELLED:
				$changed = $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'      => $this->reset_state( 'CANCELLED' ),
					'cancelled_at_gmt' => $occurred_db,
				), $now_gmt );
				if ( $changed && null !== $payload['authorization_id'] ) {
					$this->close_authorization( $event, $payload['authorization_id'], 'cancelled', $occurred_db, $now_gmt );
				}
				return $changed;
			case GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED:
				return $this->apply_authorized( $event, $payload, $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED:
				$changed = $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'    => $this->reset_state( 'EXPIRED' ),
					'expired_at_gmt' => $occurred_db,
				), $now_gmt );
				if ( $changed ) {
					$this->close_authorization( $event, $payload['authorization_id'], 'expired', GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['observed_at_gmt'] ), $now_gmt );
				}
				return $changed;
			case GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED:
				$changed = $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'        => $this->reset_state( 'INVALIDATED' ),
					'invalidated_at_gmt' => $occurred_db,
				), $now_gmt );
				if ( $changed && null !== $payload['authorization_id'] ) {
					$this->close_authorization( $event, $payload['authorization_id'], 'invalidated', $occurred_db, $now_gmt );
				}
				return $changed;
			case GHCA_ACD_Archive_Event_Types::RESET_EXECUTION_CLAIMED:
				$claimed_db = GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['claimed_at_gmt'] );
				$changed    = $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'           => $this->reset_state( 'CLAIMED' ),
					'claimed_at_gmt'        => $claimed_db,
					'gateway_key'           => $payload['gateway_key'],
					'upstream_operation_id' => $payload['upstream_operation_id'],
				), $now_gmt );
				if ( $changed ) {
					$this->consume_authorization( $event, $payload['authorization_id'], $claimed_db, $now_gmt );
				}
				return $changed;
			case GHCA_ACD_Archive_Event_Types::RESET_COMPLETED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'    => $this->reset_state( 'COMPLETED' ),
					'outcome_at_gmt' => $occurred_db,
					'outcome_code'   => 'completed',
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_FAILED_SAFE:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'    => $this->reset_state( 'FAILED_SAFE' ),
					'outcome_at_gmt' => $occurred_db,
					'outcome_code'   => 'failed_safe',
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'    => $this->reset_state( 'OUTCOME_UNKNOWN' ),
					'outcome_at_gmt' => $occurred_db,
					'outcome_code'   => 'outcome_unknown',
					'failure_code'   => $payload['failure_code'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_COMPLETED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'         => $this->reset_state( 'COMPLETED' ),
					'reconciled_at_gmt'   => $occurred_db,
					'reconciliation_code' => 'reconciled_completed',
					'outcome_code'        => 'completed',
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_NO_CHANGE:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'         => $this->reset_state( 'FAILED_SAFE' ),
					'reconciled_at_gmt'   => $occurred_db,
					'reconciliation_code' => 'reconciled_no_change',
					'outcome_code'        => 'failed_safe',
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_REMEDIATION_REQUIRED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'         => $this->reset_state( 'REMEDIATION_REQUIRED' ),
					'reconciled_at_gmt'   => $occurred_db,
					'reconciliation_code' => 'remediation_required',
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::RESET_REMEDIATED_RESTORED:
				return $this->update_reset( $event, $payload['reset_operation_id'], array(
					'reset_state'         => $this->reset_state( 'REMEDIATED_RESTORED' ),
					'reconciled_at_gmt'   => $occurred_db,
					'reconciliation_code' => 'remediated_restored',
					'outcome_code'        => 'remediated_restored',
				), $now_gmt );
			default:
				return false;
		}
	}

	/** @param array<string,mixed> $payload */
	private function create_reset( GHCA_ACD_Archive_Event $event, array $payload, string $now_gmt ): bool {
		$document = $event->recorded_document();
		$existing = $this->repository->find_reset_for_update( $payload['reset_operation_id'] );
		if ( null !== $existing ) {
			if ( (string) $existing['stream_id'] !== $event->stream_id()
				|| (string) $existing['reset_operation_id'] !== (string) $payload['reset_operation_id']
				|| (string) $existing['archive_id'] !== (string) $payload['bound_archive_id']
				|| (string) $existing['snapshot_id'] !== (string) $payload['snapshot_id']
				|| (string) $existing['scope_digest'] !== (string) $payload['scope_digest']
				|| (string) $existing['scope_json'] !== GHCA_ACD_Archive_Canonical_JSON::encode( $payload['scope'] ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'reset_identity_mismatch',
					'A reset projection row contradicts its immutable archive, snapshot, or scope identity.'
				);
			}
			if ( false === $this->replay_disposition( $existing, $event ) ) {
				return false;
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'reset_row_conflict',
				'A conflicting reset projection row already exists for this operation identity.'
			);
		}
		$this->repository->insert_reset( array(
			'reset_operation_id'        => $payload['reset_operation_id'],
			'stream_id'                 => $event->stream_id(),
			'archive_id'                => $payload['bound_archive_id'],
			'snapshot_id'               => $payload['snapshot_id'],
			'authorization_id'          => null,
			'last_changed_sequence'     => $event->stream_sequence(),
			'last_changed_event_digest' => $event->event_digest(),
			'reset_state'               => $this->reset_state( 'REQUESTED' ),
			'scope_digest'              => $payload['scope_digest'],
			'scope_schema_version'      => 1,
			'scope_json'                => GHCA_ACD_Archive_Canonical_JSON::encode( $payload['scope'] ),
			'requested_by_user_id'      => GHCA_ACD_Archive_Projector::human_user_id( $document ),
			'authorized_by_user_id'     => null,
			'requested_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
			'request_valid_until_gmt'   => null === $payload['request_valid_until_gmt'] ? null : GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['request_valid_until_gmt'] ),
			'deferred_until_gmt'        => null,
			'defer_condition_code'      => null,
			'authorized_at_gmt'         => null,
			'expires_at_gmt'            => null,
			'claimed_at_gmt'            => null,
			'cancelled_at_gmt'          => null,
			'invalidated_at_gmt'        => null,
			'expired_at_gmt'            => null,
			'outcome_at_gmt'            => null,
			'reconciled_at_gmt'         => null,
			'gateway_key'               => null,
			'upstream_operation_id'     => null,
			'outcome_code'              => null,
			'reconciliation_code'       => null,
			'failure_code'              => null,
			'failure_text'              => null,
			'updated_at_gmt'            => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		) );
		return true;
	}

	/** @param array<string,mixed> $payload */
	private function apply_authorized( GHCA_ACD_Archive_Event $event, array $payload, string $now_gmt ): bool {
		$document = $event->recorded_document();
		$reset = $this->require_reset( $event, $payload['reset_operation_id'] );
		if ( (string) $reset['archive_id'] !== (string) $payload['archive_id']
			|| (string) $reset['snapshot_id'] !== (string) $payload['snapshot_id']
			|| (string) $reset['scope_digest'] !== (string) $payload['scope_digest'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'authorization_binding_mismatch',
				'A reset authorization contradicts the immutable reset archive, snapshot, or scope binding.'
			);
		}
		$changed  = $this->update_reset( $event, $payload['reset_operation_id'], array(
			'reset_state'           => $this->reset_state( 'AUTHORIZED' ),
			'authorization_id'      => $payload['authorization_id'],
			'snapshot_id'           => $payload['snapshot_id'],
			'gateway_key'           => $payload['gateway_key'],
			'authorized_at_gmt'     => GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['issued_at_gmt'] ),
			'expires_at_gmt'        => GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['expires_at_gmt'] ),
			'authorized_by_user_id' => GHCA_ACD_Archive_Projector::human_user_id( $document ),
		), $now_gmt );
		if ( ! $changed ) {
			return false;
		}
		$existing = $this->repository->find_authorization_for_update( $payload['authorization_id'] );
		if ( null !== $existing ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'authorization_duplicate',
				'A reset authorization enforcement row already exists for this identity.'
			);
		}
		$this->repository->insert_authorization( array(
			'authorization_id'   => $payload['authorization_id'],
			'reset_operation_id' => $payload['reset_operation_id'],
			'stream_id'          => $event->stream_id(),
			'archive_id'         => $payload['archive_id'],
			'snapshot_id'        => $payload['snapshot_id'],
			'scope_digest'       => $payload['scope_digest'],
			'auth_state'         => $this->auth_state( 'issued' ),
			'issued_event_id'    => $event->event_id(),
			'terminal_event_id'  => null,
			'issued_at_gmt'      => GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['issued_at_gmt'] ),
			'expires_at_gmt'     => GHCA_ACD_Archive_Db_Format::utc_to_db( $payload['expires_at_gmt'] ),
			'consumed_at_gmt'    => null,
			'closed_at_gmt'      => null,
			'updated_at_gmt'     => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		) );
		return true;
	}

	private function consume_authorization( GHCA_ACD_Archive_Event $event, string $authorization_id, string $consumed_at_db, string $now_gmt ): void {
		$this->assert_authorization_identity( $event, $authorization_id );
		$this->repository->transition_authorization( $authorization_id, $this->auth_state( 'issued' ), array(
			'auth_state'        => $this->auth_state( 'consumed' ),
			'terminal_event_id' => $event->event_id(),
			'consumed_at_gmt'   => $consumed_at_db,
			'updated_at_gmt'    => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		) );
	}

	private function close_authorization( GHCA_ACD_Archive_Event $event, string $authorization_id, string $closed_state, string $closed_at_db, string $now_gmt ): void {
		$this->assert_authorization_identity( $event, $authorization_id );
		$this->repository->transition_authorization( $authorization_id, $this->auth_state( 'issued' ), array(
			'auth_state'        => $this->auth_state( $closed_state ),
			'terminal_event_id' => $event->event_id(),
			'closed_at_gmt'     => $closed_at_db,
			'updated_at_gmt'    => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		) );
	}

	/** @param array<string,mixed> $columns */
	private function update_reset( GHCA_ACD_Archive_Event $event, string $reset_operation_id, array $columns, string $now_gmt ): bool {
		$row = $this->require_reset( $event, $reset_operation_id );
		if ( false === $this->replay_disposition( $row, $event ) ) {
			return false;
		}
		$columns['last_changed_sequence']     = $event->stream_sequence();
		$columns['last_changed_event_digest'] = $event->event_digest();
		$columns['updated_at_gmt']            = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$this->repository->update_reset( $reset_operation_id, $columns, (string) $row['last_changed_sequence'] );
		return true;
	}

	/** @return array<string,mixed> */
	private function require_reset( GHCA_ACD_Archive_Event $event, string $reset_operation_id ): array {
		$row = $this->repository->find_reset_for_update( $reset_operation_id );
		if ( null === $row ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'reset_row_missing',
				'A reset projection row required by this event does not exist.'
			);
		}
		$document = $event->recorded_document();
		if ( (string) $row['reset_operation_id'] !== $reset_operation_id
			|| (string) $row['stream_id'] !== $event->stream_id()
			|| (string) $document['reset_operation_id'] !== $reset_operation_id ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'reset_identity_mismatch',
				'A reset projection row contradicts the targeting event identity.'
			);
		}
		return $row;
	}

	private function assert_authorization_identity( GHCA_ACD_Archive_Event $event, string $authorization_id ): void {
		$authorization = $this->repository->find_authorization_for_update( $authorization_id );
		if ( null === $authorization ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'authorization_row_missing',
				'A reset authorization enforcement row required by this event does not exist.'
			);
		}
		$document = $event->recorded_document();
		$reset = $this->require_reset( $event, (string) $authorization['reset_operation_id'] );
		if ( (string) $authorization['authorization_id'] !== $authorization_id
			|| (string) $authorization['stream_id'] !== $event->stream_id()
			|| (string) $authorization['reset_operation_id'] !== (string) $document['reset_operation_id']
			|| (string) $authorization['archive_id'] !== (string) $reset['archive_id']
			|| (string) $authorization['snapshot_id'] !== (string) $reset['snapshot_id']
			|| (string) $authorization['scope_digest'] !== (string) $reset['scope_digest'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'authorization_identity_mismatch',
				'A reset authorization row contradicts the stream, reset, archive, snapshot, or scope identity.'
			);
		}
	}

	/**
	 * @param array<string,mixed> $row
	 * @return bool|null Null when the event should apply; false for identical replay.
	 */
	private function replay_disposition( array $row, GHCA_ACD_Archive_Event $event ) {
		return GHCA_ACD_Archive_Projector::entity_replay_disposition(
			(string) $row['last_changed_sequence'],
			(string) $row['last_changed_event_digest'],
			$event,
			'reset_row_conflict'
		);
	}

	private function reset_state( string $state ): string {
		if ( ! in_array( $state, self::RESET_STATES, true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'illegal_reset_state',
				'A derived reset state code is not an approved state-machine code.'
			);
		}
		return $state;
	}

	private function auth_state( string $state ): string {
		if ( ! in_array( $state, self::AUTH_STATES, true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'illegal_authorization_state',
				'A derived authorization state code is not an approved enforcement code.'
			);
		}
		return $state;
	}
}
