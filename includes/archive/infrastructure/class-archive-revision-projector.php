<?php

/**
 * Synchronous revision-history projector (archive_revision_state).
 *
 * Event treatment (row change vs semantic no-op; the projector head always
 * advances for every event via the coordinator):
 *
 * - ArchiveRequested / ReplacementArchiveRequested: creates the revision row.
 * - ArchiveBuildStarted: build state and current attempt change.
 * - EvidenceSnapshotCaptured: snapshot binding and MATERIALIZING state.
 * - LedgerMaterialized / PacketMaterialized: layer references; VERIFYING when
 *   both layers exist.
 * - ArchiveVerified: semantic no-op (verification is bound into finalization).
 * - ArchiveFinalized: FINALIZED+ACTIVE; a named predecessor row atomically
 *   becomes SUPERSEDED with explicit lineage.
 * - ArchiveFailed: FAILED with phase/code and sanitized envelope reason text.
 * - ArchiveRetryRequested: semantic no-op (a pending retry is not a row fact
 *   until its ArchiveBuildStarted commits).
 * - ArchiveCancelled: CANCELLED.
 * - CorrectionRequested: semantic no-op for revision rows.
 * - ArchiveRevoked: REVOKED with revocation attribution.
 * - Every reset, drift, unprotected-reset, and integrity event: semantic no-op.
 */
final class GHCA_ACD_Archive_Revision_Projector {
	const PROJECTOR_KEY = 'revision_state';

	const BUILD_STATES    = array( 'REQUESTED', 'CAPTURING', 'MATERIALIZING', 'VERIFYING', 'FINALIZED', 'FAILED', 'CANCELLED' );
	const VALIDITY_STATES = array( 'NOT_APPLICABLE', 'ACTIVE', 'REVOKED', 'SUPERSEDED' );

	/** @var GHCA_ACD_WPDB_Archive_Projection_Repository */
	private $repository;

	public function __construct( GHCA_ACD_WPDB_Archive_Projection_Repository $repository ) {
		$this->repository = $repository;
	}

	/** @return bool True when an entity row changed; false for a semantic no-op. */
	public function apply( GHCA_ACD_Archive_Event $event, string $now_gmt ): bool {
		$type    = $event->type();
		$payload = $event->payload();
		switch ( $type ) {
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED:
				return $this->create_revision( $event, $payload, null, $now_gmt );
			case GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED:
				return $this->create_revision( $event, $payload, $payload['revoked_predecessor_archive_id'], $now_gmt );
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED:
				return $this->update_revision( $event, $payload['archive_id'], array(
					'build_state'              => $this->build_state( strtoupper( $payload['start_phase'] ) ),
					'current_build_attempt_id' => $payload['build_attempt_id'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED:
				return $this->update_revision( $event, $payload['archive_id'], array(
					'build_state' => $this->build_state( 'MATERIALIZING' ),
					'snapshot_id' => $payload['snapshot_id'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED:
				return $this->apply_materialized( $event, $payload['archive_id'], 'ledger_artifact_id', $payload['ledger_artifact_id'], 'packet_artifact_id', $now_gmt );
			case GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED:
				return $this->apply_materialized( $event, $payload['archive_id'], 'packet_artifact_id', $payload['packet_artifact_id'], 'ledger_artifact_id', $now_gmt );
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED:
				return $this->apply_finalized( $event, $payload, $now_gmt );
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED:
				$document = $event->recorded_document();
				return $this->update_revision( $event, $payload['archive_id'], array(
					'build_state'   => $this->build_state( 'FAILED' ),
					'failure_phase' => $payload['phase'],
					'failure_code'  => $payload['failure_code'],
					'failure_text'  => $document['reason_text'],
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED:
				return $this->update_revision( $event, $payload['archive_id'], array(
					'build_state' => $this->build_state( 'CANCELLED' ),
				), $now_gmt );
			case GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED:
				$document = $event->recorded_document();
				return $this->update_revision( $event, $payload['target_archive_id'], array(
					'validity_state'     => $this->validity_state( 'REVOKED' ),
					'revoked_by_user_id' => GHCA_ACD_Archive_Projector::human_user_id( $document ),
					'revoked_at_gmt'     => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
				), $now_gmt );
			default:
				return false;
		}
	}

	/** @param array<string,mixed> $payload */
	private function create_revision( GHCA_ACD_Archive_Event $event, array $payload, ?string $predecessor_archive_id, string $now_gmt ): bool {
		$document = $event->recorded_document();
		$existing = $this->repository->find_revision_for_update( $payload['archive_id'] );
		if ( null !== $existing ) {
			if ( (string) $existing['stream_id'] !== $event->stream_id()
				|| (string) $existing['archive_id'] !== (string) $payload['archive_id']
				|| (string) $existing['revision_number'] !== (string) $payload['revision_number']
				|| $this->nullable_string( $existing['supersedes_archive_id'] ) !== $predecessor_archive_id ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'revision_identity_mismatch',
					'A revision projection row contradicts its immutable stream or lineage identity.'
				);
			}
			if ( false === $this->replay_disposition( $existing, $event ) ) {
				return false;
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'revision_row_conflict',
				'A conflicting revision projection row already exists for this archive identity.'
			);
		}
		$this->repository->insert_revision( array(
			'archive_id'                => $payload['archive_id'],
			'stream_id'                 => $event->stream_id(),
			'revision_number'           => (string) $payload['revision_number'],
			'last_changed_sequence'     => $event->stream_sequence(),
			'last_changed_event_digest' => $event->event_digest(),
			'build_state'               => $this->build_state( 'REQUESTED' ),
			'validity_state'            => $this->validity_state( 'NOT_APPLICABLE' ),
			'snapshot_id'               => null,
			'ledger_artifact_id'        => null,
			'packet_artifact_id'        => null,
			'current_build_attempt_id'  => null,
			'supersedes_archive_id'     => $predecessor_archive_id,
			'superseded_by_archive_id'  => null,
			'failure_phase'             => null,
			'failure_code'              => null,
			'failure_text'              => null,
			'requested_by_user_id'      => GHCA_ACD_Archive_Projector::human_user_id( $document ),
			'requested_at_gmt'          => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
			'finalized_by_user_id'      => null,
			'finalized_at_gmt'          => null,
			'revoked_by_user_id'        => null,
			'revoked_at_gmt'            => null,
			'superseded_by_user_id'     => null,
			'superseded_at_gmt'         => null,
			'updated_at_gmt'            => GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt ),
		) );
		return true;
	}

	private function apply_materialized( GHCA_ACD_Archive_Event $event, string $archive_id, string $own_column, string $artifact_id, string $other_column, string $now_gmt ): bool {
		$row = $this->require_revision( $event, $archive_id );
		$replay = $this->replay_disposition( $row, $event );
		if ( null !== $replay ) {
			return $replay;
		}
		$payload = $event->payload();
		if ( (string) $row['snapshot_id'] !== (string) $payload['snapshot_id']
			|| (string) $row['current_build_attempt_id'] !== (string) $payload['build_attempt_id'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'revision_artifact_binding_mismatch',
				'A materialized artifact does not match the revision snapshot and build attempt.'
			);
		}
		$columns = array( $own_column => $artifact_id );
		if ( null !== $row[ $other_column ] ) {
			$columns['build_state'] = $this->build_state( 'VERIFYING' );
		}
		$this->write_revision( $row, $event, $columns, $now_gmt );
		return true;
	}

	/** @param array<string,mixed> $payload */
	private function apply_finalized( GHCA_ACD_Archive_Event $event, array $payload, string $now_gmt ): bool {
		$document = $event->recorded_document();
		$row = $this->require_revision( $event, $payload['archive_id'] );
		if ( (string) $row['revision_number'] !== (string) $payload['revision_number']
			|| (string) $row['snapshot_id'] !== (string) $payload['snapshot_id']
			|| (string) $row['ledger_artifact_id'] !== (string) $payload['ledger_artifact_id']
			|| (string) $row['packet_artifact_id'] !== (string) $payload['packet_artifact_id']
			|| $this->nullable_string( $row['supersedes_archive_id'] ) !== $payload['expected_predecessor_archive_id'] ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'revision_finalization_binding_mismatch',
				'Finalization does not exactly match the persisted revision bindings.'
			);
		}
		$changed  = $this->update_revision( $event, $payload['archive_id'], array(
			'build_state'          => $this->build_state( 'FINALIZED' ),
			'validity_state'       => $this->validity_state( 'ACTIVE' ),
			'finalized_by_user_id' => GHCA_ACD_Archive_Projector::human_user_id( $document ),
			'finalized_at_gmt'     => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
		), $now_gmt );
		if ( null !== $payload['expected_predecessor_archive_id'] && $changed ) {
			$this->update_revision( $event, $payload['expected_predecessor_archive_id'], array(
				'validity_state'           => $this->validity_state( 'SUPERSEDED' ),
				'superseded_by_archive_id' => $payload['archive_id'],
				'superseded_by_user_id'    => GHCA_ACD_Archive_Projector::human_user_id( $document ),
				'superseded_at_gmt'        => GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] ),
			), $now_gmt );
		}
		return $changed;
	}

	/** @param array<string,mixed> $columns */
	private function update_revision( GHCA_ACD_Archive_Event $event, string $archive_id, array $columns, string $now_gmt ): bool {
		$row    = $this->require_revision( $event, $archive_id, GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED === $event->type() && $archive_id !== $event->recorded_document()['archive_id'] );
		$replay = $this->replay_disposition( $row, $event );
		if ( null !== $replay ) {
			return $replay;
		}
		$this->write_revision( $row, $event, $columns, $now_gmt );
		return true;
	}

	/** @return array<string,mixed> */
	private function require_revision( GHCA_ACD_Archive_Event $event, string $archive_id, bool $allow_related_archive = false ): array {
		$row = $this->repository->find_revision_for_update( $archive_id );
		if ( null === $row ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'revision_row_missing',
				'A revision projection row required by this event does not exist.'
			);
		}
		$document = $event->recorded_document();
		if ( (string) $row['archive_id'] !== $archive_id
			|| (string) $row['stream_id'] !== $event->stream_id()
			|| ( ! $allow_related_archive && (string) $document['archive_id'] !== $archive_id ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'revision_identity_mismatch',
				'A revision projection row contradicts the targeting event identity.'
			);
		}
		return $row;
	}

	/** @param mixed $value */
	private function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * Entity-row ordering rule: a greater targeting sequence applies, an
	 * identical sequence/digest replay is a safe no-op, and a lower or
	 * conflicting input fails closed.
	 *
	 * @param array<string,mixed> $row
	 * @return bool|null Null when the event should apply; a bool result otherwise.
	 */
	private function replay_disposition( array $row, GHCA_ACD_Archive_Event $event ) {
		return GHCA_ACD_Archive_Projector::entity_replay_disposition(
			(string) $row['last_changed_sequence'],
			(string) $row['last_changed_event_digest'],
			$event,
			'revision_row_conflict'
		);
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $columns */
	private function write_revision( array $row, GHCA_ACD_Archive_Event $event, array $columns, string $now_gmt ): void {
		$columns['last_changed_sequence']     = $event->stream_sequence();
		$columns['last_changed_event_digest'] = $event->event_digest();
		$columns['updated_at_gmt']            = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$this->repository->update_revision( (string) $row['archive_id'], $columns, (string) $row['last_changed_sequence'] );
	}

	private function build_state( string $state ): string {
		if ( ! in_array( $state, self::BUILD_STATES, true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'illegal_build_state',
				'A derived build state code is not an approved state-machine code.'
			);
		}
		return $state;
	}

	private function validity_state( string $state ): string {
		if ( ! in_array( $state, self::VALIDITY_STATES, true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'illegal_validity_state',
				'A derived validity state code is not an approved state-machine code.'
			);
		}
		return $state;
	}
}
