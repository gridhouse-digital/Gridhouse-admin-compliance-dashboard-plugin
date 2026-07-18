<?php

/** Report-only orphan classification. It has no filesystem mutation method. */
final class GHCA_ACD_Archive_Orphan_Reconciler {
	const SAFETY_WINDOW_SECONDS = 86400;
	const SCAN_LIMIT            = 1000;
	const RESULT_LIMIT          = 100;

	/** @var GHCA_ACD_Archive_Artifact_Store */
	private $store;
	/** @var GHCA_ACD_WPDB_Archive_Artifact_Repository */
	private $artifacts;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;

	public function __construct( GHCA_ACD_Archive_Artifact_Store $store, GHCA_ACD_WPDB_Archive_Artifact_Repository $artifacts, GHCA_ACD_Archive_Clock $clock ) {
		$this->store     = $store;
		$this->artifacts = $artifacts;
		$this->clock     = $clock;
	}

	/** @return array<string,mixed> */
	public function reconcile( ?array $cursor = null ): array {
		if ( null === $cursor ) {
			$now = $this->clock->now_gmt();
			GHCA_ACD_Archive_Db_Format::utc_to_db( $now );
			$epoch = strtotime( $now );
			if ( false === $epoch ) {
				throw $this->failure( 'orphan_scan_failed', 'The orphan reconciliation time is invalid.' );
			}
			$cutoff = $epoch - self::SAFETY_WINDOW_SECONDS;
		} else {
			$cutoff = null;
		}
		try {
			$scan = $this->store->enumerate_candidates( $cutoff, self::SCAN_LIMIT, $cursor );
		} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
			if ( in_array( $error->reason_code(), array( 'orphan_scan_failed', 'orphan_cursor_invalid', 'orphan_cursor_stale' ), true ) ) {
				throw $error;
			}
			throw $this->failure( 'orphan_scan_failed', 'The private artifact tree could not be enumerated safely.' );
		}

		$results = array();
		$counts  = array(
			'orphan_unreferenced'         => 0,
			'orphan_staging_unreferenced' => 0,
			'referenced_protected'        => 0,
		);
		$truncated = ! empty( $scan['truncated'] );
		foreach ( $scan['candidates'] as $candidate ) {
			if ( count( $results ) >= self::RESULT_LIMIT ) {
				$truncated = true;
				break;
			}
			try {
				$descriptor = $this->artifacts->find_descriptor( $candidate['artifact_id'] );
			} catch ( Throwable $error ) {
				throw $this->failure( 'orphan_reference_recheck_failed', 'The immutable artifact reference could not be rechecked.' );
			}
			if ( null !== $descriptor && (string) $descriptor['storage_key'] === $candidate['logical_key'] ) {
				$classification = 'referenced_protected';
			} elseif ( 'committed' === $candidate['area'] && null !== $descriptor ) {
				throw $this->failure( 'orphan_reference_binding_mismatch', 'The committed artifact key contradicts its retained descriptor.' );
			} elseif ( 'staging' === $candidate['area'] ) {
				$classification = 'orphan_staging_unreferenced';
			} else {
				$classification = 'orphan_unreferenced';
			}
			$counts[ $classification ]++;
			$results[] = array(
				'logical_key'   => $candidate['logical_key'],
				'artifact_id'   => $candidate['artifact_id'],
				'mtime'         => $candidate['mtime'],
				'classification' => $classification,
			);
		}
		return array(
			'scanned'    => $scan['scanned'],
			'candidate_count' => count( $scan['candidates'] ),
			'results'    => $results,
			'counts'     => $counts,
			'truncated'  => $truncated,
			'next_cursor' => isset( $scan['next_cursor'] ) ? $scan['next_cursor'] : null,
		);
	}

	private function failure( string $reason, string $message ): GHCA_ACD_Archive_Artifact_Store_Exception {
		return new GHCA_ACD_Archive_Artifact_Store_Exception( $reason, $message );
	}
}
