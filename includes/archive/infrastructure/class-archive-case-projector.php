<?php

/**
 * Synchronous case-state projector (archive_case_state).
 *
 * The case row is derived exclusively from validated events: the coordinator
 * folds the authoritative stream through the pure GHCA_ACD_Archive_Case
 * aggregate (the single source of state-machine truth — nothing here
 * re-implements transitions) and this class maps the fold result to the
 * projection columns. Every event advances the case projector cursor; an
 * event whose fold leaves every business column unchanged is a semantic
 * no-op that still advances the head.
 *
 * The derived edit_locked/reset_eligible booleans and their reason codes are
 * projections only; they never authorize an authoritative or destructive
 * decision by themselves.
 */
final class GHCA_ACD_Archive_Case_Projector {
	const PROJECTOR_KEY = 'case_state';

	const SOURCE_DRIFT_STATES      = array( 'NONE', 'OPEN', 'RESOLVED' );
	const UNPROTECTED_RESET_STATES = array( 'NONE', 'OPEN', 'DISMISSED_NO_RESET', 'CONFIRMED_RESET' );
	const INTEGRITY_STATES         = array( 'NONE', 'OPEN', 'DISPOSITION_RECORDED' );

	/**
	 * Business columns compared to decide whether case state changed.
	 *
	 * @return array<int,string>
	 */
	public static function business_columns(): array {
		return array(
			'current_archive_id', 'active_archive_id', 'correction_target_archive_id',
			'build_state', 'validity_state', 'reset_state',
			'source_drift_state', 'source_drift_incident_id',
			'unprotected_reset_state', 'unprotected_reset_incident_id',
			'integrity_state', 'integrity_incident_id',
			'edit_locked', 'reset_eligible', 'edit_lock_reason', 'reset_block_reason',
			'last_failure_code',
		);
	}

	/**
	 * Map an aggregate fold result to the case-state business columns.
	 *
	 * @param array<string,mixed> $aggregate_state
	 * @return array<string,mixed>
	 */
	public function derive_columns( array $aggregate_state, ?string $last_failure_code ): array {
		$reference = $this->reference_revision( $aggregate_state );
		$build     = null;
		$validity  = null;
		if ( null !== $reference ) {
			$build    = $this->approved( $reference['build_state'], GHCA_ACD_Archive_Revision_Projector::BUILD_STATES, 'illegal_build_state' );
			$validity = $this->approved( $reference['validity_state'], GHCA_ACD_Archive_Revision_Projector::VALIDITY_STATES, 'illegal_validity_state' );
		}
		$reset_state = 'NONE';
		if ( null !== $aggregate_state['active_reset_operation_id'] ) {
			$active_reset = $aggregate_state['resets'][ $aggregate_state['active_reset_operation_id'] ];
			$reset_state  = $this->approved( $active_reset['reset_state'], GHCA_ACD_Archive_Reset_Projector::RESET_STATES, 'illegal_reset_state' );
		}
		$edit_locked    = $aggregate_state['edit_locked'] ? 1 : 0;
		$reset_eligible = $aggregate_state['reset_eligible'] ? 1 : 0;
		return array(
			'current_archive_id'            => $aggregate_state['current_archive_id'],
			'active_archive_id'             => $aggregate_state['active_archive_id'],
			'correction_target_archive_id'  => $aggregate_state['correction_target_archive_id'],
			'build_state'                   => $build,
			'validity_state'                => $validity,
			'reset_state'                   => $reset_state,
			'source_drift_state'            => $this->approved( $aggregate_state['source_drift_state'], self::SOURCE_DRIFT_STATES, 'illegal_source_drift_state' ),
			'source_drift_incident_id'      => $aggregate_state['source_drift_incident_id'],
			'unprotected_reset_state'       => $this->approved( $aggregate_state['unprotected_reset_state'], self::UNPROTECTED_RESET_STATES, 'illegal_unprotected_reset_state' ),
			'unprotected_reset_incident_id' => $aggregate_state['unprotected_reset_incident_id'],
			'integrity_state'               => $this->approved( $aggregate_state['integrity_state'], self::INTEGRITY_STATES, 'illegal_integrity_state' ),
			'integrity_incident_id'         => $aggregate_state['integrity_incident_id'],
			'edit_locked'                   => $edit_locked,
			'reset_eligible'                => $reset_eligible,
			'edit_lock_reason'              => 1 === $edit_locked ? $this->edit_lock_reason( $aggregate_state ) : null,
			'reset_block_reason'            => 1 === $reset_eligible ? null : $this->reset_block_reason( $aggregate_state ),
			'last_failure_code'             => $last_failure_code,
		);
	}

	/**
	 * Reference revision for the case-level build/validity summary: the
	 * current candidate, else the active revision, else the correction
	 * target, else the newest retained revision.
	 *
	 * @param array<string,mixed> $aggregate_state
	 * @return array<string,mixed>|null
	 */
	private function reference_revision( array $aggregate_state ) {
		foreach ( array( 'current_archive_id', 'active_archive_id', 'correction_target_archive_id' ) as $pointer ) {
			$archive_id = $aggregate_state[ $pointer ];
			if ( null !== $archive_id && isset( $aggregate_state['revisions'][ $archive_id ] ) ) {
				return $aggregate_state['revisions'][ $archive_id ];
			}
		}
		$newest        = null;
		$newest_number = 0;
		foreach ( $aggregate_state['revisions'] as $revision ) {
			if ( $revision['revision_number'] > $newest_number ) {
				$newest_number = $revision['revision_number'];
				$newest        = $revision;
			}
		}
		return $newest;
	}

	/** @param array<string,mixed> $aggregate_state */
	private function has_integrity_block( array $aggregate_state ): bool {
		return 'OPEN' === $aggregate_state['integrity_state']
			|| true === $aggregate_state['integrity_compromise_confirmed']
			|| array() !== $aggregate_state['integrity_remaining_restrictions'];
	}

	/** @param array<string,mixed> $aggregate_state */
	private function incident_reason( array $aggregate_state ): ?string {
		if ( $this->has_integrity_block( $aggregate_state ) ) {
			return 'integrity_blocked';
		}
		if ( 'OPEN' === $aggregate_state['source_drift_state'] ) {
			return 'source_drift_open';
		}
		if ( 'OPEN' === $aggregate_state['unprotected_reset_state'] ) {
			return 'unprotected_reset_open';
		}
		if ( 'CONFIRMED_RESET' === $aggregate_state['unprotected_reset_state'] ) {
			return 'unprotected_reset_confirmed';
		}
		return null;
	}

	/** @param array<string,mixed> $aggregate_state */
	private function edit_lock_reason( array $aggregate_state ): string {
		$incident = $this->incident_reason( $aggregate_state );
		if ( null !== $incident ) {
			return $incident;
		}
		if ( null !== $aggregate_state['active_reset_operation_id'] ) {
			return 'reset_in_progress';
		}
		if ( null !== $aggregate_state['correction_target_archive_id'] ) {
			return 'correction_pending';
		}
		if ( null !== $aggregate_state['active_archive_id'] ) {
			return 'active_archive_locked';
		}
		return 'build_in_progress';
	}

	/** @param array<string,mixed> $aggregate_state */
	private function reset_block_reason( array $aggregate_state ): string {
		if ( $aggregate_state['destructive_reset_seen'] ) {
			return 'destructive_reset_recorded';
		}
		$incident = $this->incident_reason( $aggregate_state );
		if ( null !== $incident ) {
			return $incident;
		}
		if ( null !== $aggregate_state['active_reset_operation_id'] ) {
			return 'reset_in_progress';
		}
		return 'no_active_archive';
	}

	/** @param array<int,string> $approved */
	private function approved( string $state, array $approved, string $reason_code ): string {
		if ( ! in_array( $state, $approved, true ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				$reason_code,
				'A derived case state code is not an approved state-machine code.'
			);
		}
		return $state;
	}
}
