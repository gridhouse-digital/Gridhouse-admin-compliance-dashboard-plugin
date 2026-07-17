<?php

/**
 * Synchronous projection coordinator (Technical Design Section 13.1).
 *
 * Runs inside the unit-of-work transaction. It locks every projector head in
 * fixed projector-key order, enforces the exact-next sequence/digest cursor
 * for every event (including semantic no-ops), rejects cursor gaps and
 * conflicting duplicates, treats an identical already-applied event as a safe
 * idempotent replay, and applies the case, revision, and reset projectors so
 * that heads, entity rows, events, and the stream head commit or roll back
 * together. Projectors never emit lifecycle events.
 */
final class GHCA_ACD_Archive_Projector {
	/** Fixed lock/advance order; identical to the table's projector-key sort order. */
	const PROJECTOR_KEYS = array(
		GHCA_ACD_Archive_Case_Projector::PROJECTOR_KEY,
		GHCA_ACD_Archive_Reset_Projector::PROJECTOR_KEY,
		GHCA_ACD_Archive_Revision_Projector::PROJECTOR_KEY,
	);

	/** @var GHCA_ACD_WPDB_Archive_Projection_Repository */
	private $repository;
	/** @var GHCA_ACD_Archive_Case_Projector */
	private $case_projector;
	/** @var GHCA_ACD_Archive_Revision_Projector */
	private $revision_projector;
	/** @var GHCA_ACD_Archive_Reset_Projector */
	private $reset_projector;

	public function __construct( GHCA_ACD_WPDB_Archive_Projection_Repository $repository ) {
		$this->repository         = $repository;
		$this->case_projector     = new GHCA_ACD_Archive_Case_Projector();
		$this->revision_projector = new GHCA_ACD_Archive_Revision_Projector( $repository );
		$this->reset_projector    = new GHCA_ACD_Archive_Reset_Projector( $repository );
	}

	/** @return GHCA_ACD_WPDB_Archive_Projection_Repository */
	public function repository(): GHCA_ACD_WPDB_Archive_Projection_Repository {
		return $this->repository;
	}

	/**
	 * First-stream projection initialization (technical sequence-zero rows).
	 *
	 * @param array<string,mixed> $stream_row
	 */
	public function initialize_stream( array $stream_row, string $now_gmt ): void {
		$this->repository->initialize_stream_projections( $stream_row, self::PROJECTOR_KEYS, $now_gmt );
	}

	/**
	 * Apply an ordered batch of newly recorded events synchronously.
	 *
	 * @param array<string,mixed>               $stream_row   Stream row before the head advance.
	 * @param array<int,GHCA_ACD_Archive_Event> $prior_events Complete verified stream before the batch.
	 * @param array<int,GHCA_ACD_Archive_Event> $new_events   Newly recorded events, in order.
	 */
	public function apply_new_events( array $stream_row, array $prior_events, array $new_events, string $now_gmt ): void {
		if ( array() === $new_events ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'empty_projection_batch',
				'A projection batch cannot be empty.'
			);
		}
		$stream_id     = (string) $stream_row['stream_id'];
		$head_sequence = (string) $stream_row['head_sequence'];
		$head_digest   = null === $stream_row['head_event_digest'] ? null : (string) $stream_row['head_event_digest'];
		$this->assert_prior_alignment( $prior_events, $head_sequence, $head_digest );

		$heads = $this->repository->lock_projector_heads( $stream_id, self::PROJECTOR_KEYS );
		foreach ( self::PROJECTOR_KEYS as $projector_key ) {
			$head = $heads[ $projector_key ];
			if ( ! GHCA_ACD_Archive_Db_Format::sequences_equal( (string) $head['projected_sequence'], $head_sequence )
				|| ( null === $head['projected_event_digest'] ) !== ( null === $head_digest )
				|| ( null !== $head_digest && (string) $head['projected_event_digest'] !== $head_digest ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projector_head_stale',
					'A projector head does not match the authoritative stream head.'
				);
			}
		}
		$case_row = $this->repository->find_case_state_for_update( $stream_id );
		if ( null === $case_row ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'case_state_stale',
				'The case projection row is missing.'
			);
		}
		foreach ( array( 'stream_id', 'tenant_id', 'site_id', 'employee_user_id', 'program_key', 'cycle_key', 'cycle_key_digest', 'cycle_start_gmt', 'cycle_end_gmt', 'cycle_timezone' ) as $identity_field ) {
			if ( (string) $case_row[ $identity_field ] !== (string) $stream_row[ $identity_field ] ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'case_state_identity_mismatch',
					'The case projection row contradicts the immutable stream identity.'
				);
			}
		}
		if ( ! GHCA_ACD_Archive_Db_Format::sequences_equal( (string) $case_row['projected_sequence'], $head_sequence ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'case_state_stale',
				'The case projection row does not match the stream head.'
			);
		}
		$this->assert_entity_identity_baseline( $prior_events, $stream_id );

		$cursor_sequence   = $head_sequence;
		$cursor_digest     = $head_digest;
		$applied_stream    = array_values( $prior_events );
		$case_baseline     = $case_row;
		$last_failure_code = null === $case_row['last_failure_code'] ? null : (string) $case_row['last_failure_code'];
		$decision_buffer   = array();

		foreach ( $new_events as $event ) {
			if ( ! $event instanceof GHCA_ACD_Archive_Event || ! $event->is_recorded() || ! $event->verify_digest() ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
					'unrecorded_projection_event',
					'Projection requires intact recorded events.'
				);
			}
			$event_document = $event->recorded_document();
			if ( $event->stream_id() !== $stream_id || (string) $event_document['case_key_digest'] !== (string) $stream_row['case_key_digest'] ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projection_event_identity_mismatch',
					'The projected event is not bound to the target stream identity.'
				);
			}
			$incoming = $event->stream_sequence();
			if ( ! GHCA_ACD_Archive_Db_Format::sequence_greater_than( $incoming, $cursor_sequence ) ) {
				// The cursor already covers this sequence: an identical event is a
				// safe idempotent replay; anything else is a conflicting duplicate.
				$known = $this->event_at_sequence( $applied_stream, $incoming );
				if ( null !== $known && hash_equals( $known->event_digest(), $event->event_digest() ) ) {
					continue;
				}
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projector_conflicting_duplicate',
					'A conflicting event was delivered at an already-projected sequence.'
				);
			}
			$expected_next = GHCA_ACD_Archive_Db_Format::increment_sequence( $cursor_sequence );
			if ( ! GHCA_ACD_Archive_Db_Format::sequences_equal( $incoming, $expected_next ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projector_gap',
					'The event sequence is not the exact next projector cursor position.'
				);
			}
			if ( $event->previous_event_digest() !== $cursor_digest ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'projector_chain_mismatch',
					'The event predecessor digest does not continue the projected chain.'
				);
			}

			$this->reset_projector->apply( $event, $now_gmt );
			$this->revision_projector->apply( $event, $now_gmt );
			foreach ( self::PROJECTOR_KEYS as $projector_key ) {
				$this->repository->advance_projector_head( $projector_key, $stream_id, $cursor_sequence, $incoming, $event->event_digest(), $now_gmt );
			}

			if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type() ) {
				$payload           = $event->payload();
				$last_failure_code = (string) $payload['failure_code'];
			}

			$applied_stream[]  = $event;
			$cursor_sequence   = $incoming;
			$cursor_digest     = $event->event_digest();
			$decision_buffer[] = $event;

			$metadata = $event->metadata();
			if ( $metadata['decision_index'] + 1 === $metadata['decision_size'] ) {
				if ( count( $decision_buffer ) !== $metadata['decision_size'] ) {
					throw new GHCA_ACD_Archive_Persistence_Exception(
						GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
						'incomplete_decision',
						'A projected decision does not contain its complete ordered event batch.'
					);
				}
				$case_baseline   = $this->project_case_decision( $stream_id, $applied_stream, $decision_buffer, $case_baseline, $last_failure_code, $now_gmt );
				$decision_buffer = array();
			}
		}
		if ( array() !== $decision_buffer ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'incomplete_decision',
				'The projection batch ended inside an atomic decision.'
			);
		}
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $prior_events */
	private function assert_entity_identity_baseline( array $prior_events, string $stream_id ): void {
		$seen_revisions = array();
		$seen_resets = array();
		$seen_authorizations = array();
		foreach ( $prior_events as $event ) {
			$payload = $event->payload();
			if ( in_array( $event->type(), array( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED ), true )
				&& ! isset( $seen_revisions[ $payload['archive_id'] ] ) ) {
				$seen_revisions[ $payload['archive_id'] ] = true;
				$row = $this->repository->find_revision_for_update( $payload['archive_id'] );
				$predecessor = GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED === $event->type() ? $payload['revoked_predecessor_archive_id'] : null;
				if ( null === $row || (string) $row['stream_id'] !== $stream_id
					|| (string) $row['archive_id'] !== (string) $payload['archive_id']
					|| (string) $row['revision_number'] !== (string) $payload['revision_number']
					|| ( null === $row['supersedes_archive_id'] ? null : (string) $row['supersedes_archive_id'] ) !== $predecessor ) {
					throw new GHCA_ACD_Archive_Persistence_Exception(
						GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
						'revision_identity_mismatch',
						'A revision projection row contradicts its authoritative creation event.'
					);
				}
			}
			if ( GHCA_ACD_Archive_Event_Types::RESET_REQUESTED === $event->type() && ! isset( $seen_resets[ $payload['reset_operation_id'] ] ) ) {
				$seen_resets[ $payload['reset_operation_id'] ] = true;
				$row = $this->repository->find_reset_for_update( $payload['reset_operation_id'] );
				if ( null === $row || (string) $row['stream_id'] !== $stream_id
					|| (string) $row['archive_id'] !== (string) $payload['bound_archive_id']
					|| (string) $row['snapshot_id'] !== (string) $payload['snapshot_id']
					|| (string) $row['scope_digest'] !== (string) $payload['scope_digest']
					|| (string) $row['scope_json'] !== GHCA_ACD_Archive_Canonical_JSON::encode( $payload['scope'] ) ) {
					throw new GHCA_ACD_Archive_Persistence_Exception(
						GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
						'reset_identity_mismatch',
						'A reset projection row contradicts its authoritative request event.'
					);
				}
			}
			if ( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED === $event->type() && ! isset( $seen_authorizations[ $payload['authorization_id'] ] ) ) {
				$seen_authorizations[ $payload['authorization_id'] ] = true;
				$row = $this->repository->find_authorization_for_update( $payload['authorization_id'] );
				if ( null === $row || (string) $row['stream_id'] !== $stream_id
					|| (string) $row['reset_operation_id'] !== (string) $payload['reset_operation_id']
					|| (string) $row['archive_id'] !== (string) $payload['archive_id']
					|| (string) $row['snapshot_id'] !== (string) $payload['snapshot_id']
					|| (string) $row['scope_digest'] !== (string) $payload['scope_digest'] ) {
					throw new GHCA_ACD_Archive_Persistence_Exception(
						GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
						'authorization_identity_mismatch',
						'A reset authorization row contradicts its authoritative authorization event.'
					);
				}
			}
		}
	}

	/**
	 * Envelope attribution rule shared by projectors: the human principal is
	 * the initiating user when recorded, otherwise the authenticated wp_user
	 * actor, otherwise null (system/worker/integration actors).
	 *
	 * @param array<string,mixed> $document
	 */
	public static function human_user_id( array $document ): ?string {
		if ( null !== $document['initiating_user_id'] ) {
			return (string) $document['initiating_user_id'];
		}
		if ( 'wp_user' === $document['actor_kind'] && null !== $document['actor_user_id'] ) {
			return (string) $document['actor_user_id'];
		}
		return null;
	}

	/**
	 * Shared entity-row ordering rule: a greater targeting sequence applies
	 * (returns null), an identical sequence/digest replay is a safe no-op
	 * (returns false), and a lower or conflicting input fails closed.
	 *
	 * @return bool|null
	 */
	public static function entity_replay_disposition( string $row_sequence, string $row_digest, GHCA_ACD_Archive_Event $event, string $conflict_reason ) {
		$incoming = $event->stream_sequence();
		if ( GHCA_ACD_Archive_Db_Format::sequences_equal( $incoming, $row_sequence ) ) {
			if ( hash_equals( $row_digest, $event->event_digest() ) ) {
				return false;
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				$conflict_reason,
				'A conflicting event targets an already-applied entity sequence.'
			);
		}
		if ( GHCA_ACD_Archive_Db_Format::sequence_greater_than( $incoming, $row_sequence ) ) {
			return null;
		}
		throw new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
			$conflict_reason,
			'An event targets an entity row below its last applied sequence.'
		);
	}

	/**
	 * Fold the complete applied stream through the pure aggregate and write
	 * the case row for one completed decision.
	 *
	 * @param array<int,GHCA_ACD_Archive_Event> $applied_stream
	 * @param array<int,GHCA_ACD_Archive_Event> $decision_events
	 * @param array<string,mixed>               $case_baseline
	 * @return array<string,mixed> The updated case baseline row.
	 */
	private function project_case_decision( string $stream_id, array $applied_stream, array $decision_events, array $case_baseline, ?string $last_failure_code, string $now_gmt ): array {
		try {
			$aggregate = GHCA_ACD_Archive_Case::rehydrate( $applied_stream );
		} catch ( Throwable $error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'projection_replay_failed',
				'The authoritative stream no longer replays into a valid aggregate.'
			);
		}
		$columns    = $this->case_projector->derive_columns( $aggregate->state(), $last_failure_code );
		$changed    = false;
		foreach ( GHCA_ACD_Archive_Case_Projector::business_columns() as $column ) {
			if ( $this->normalize( $case_baseline[ $column ] ) !== $this->normalize( $columns[ $column ] ) ) {
				$changed = true;
				break;
			}
		}
		$last_event = $decision_events[ count( $decision_events ) - 1 ];
		$document   = $last_event->recorded_document();
		$columns['projected_sequence']     = $last_event->stream_sequence();
		$columns['projected_event_digest'] = $last_event->event_digest();
		$columns['updated_at_gmt']         = GHCA_ACD_Archive_Db_Format::utc_to_db( $now_gmt );
		$columns['state_changed_at_gmt']   = $changed
			? GHCA_ACD_Archive_Db_Format::utc_to_db( $document['occurred_at_gmt'] )
			: (string) $case_baseline['state_changed_at_gmt'];
		$this->repository->update_case_state( $stream_id, $columns, (string) $case_baseline['projected_sequence'] );
		foreach ( $columns as $column => $value ) {
			$case_baseline[ $column ] = $value;
		}
		return $case_baseline;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $stream */
	private function event_at_sequence( array $stream, string $sequence ): ?GHCA_ACD_Archive_Event {
		foreach ( $stream as $event ) {
			if ( GHCA_ACD_Archive_Db_Format::sequences_equal( $event->stream_sequence(), $sequence ) ) {
				return $event;
			}
		}
		return null;
	}

	/** @param mixed $value */
	private function normalize( $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/** @param array<int,GHCA_ACD_Archive_Event> $prior_events */
	private function assert_prior_alignment( array $prior_events, string $head_sequence, ?string $head_digest ): void {
		$count = count( $prior_events );
		if ( ! GHCA_ACD_Archive_Db_Format::sequences_equal( $head_sequence, (string) $count ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'stream_head_mismatch',
				'The loaded stream length does not match the stream head sequence.'
			);
		}
		if ( 0 === $count ) {
			if ( null !== $head_digest ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'stream_head_mismatch',
					'An empty stream cannot carry a head digest.'
				);
			}
			return;
		}
		$last = $prior_events[ $count - 1 ];
		if ( null === $head_digest || ! hash_equals( $last->event_digest(), $head_digest ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'stream_head_mismatch',
				'The stream head digest does not match the last stored event.'
			);
		}
	}
}
