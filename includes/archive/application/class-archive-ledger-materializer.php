<?php

/** Pure deterministic ledger-document-v1 construction from one retained snapshot. */
final class GHCA_ACD_Archive_Ledger_Materializer {
	const MAX_LEDGER_BYTES = 8388608;
	const MAX_LEDGER_ITEMS = 10000;
	const PRODUCER_KEY = 'ghca_archive_ledger_materializer';
	const PRODUCER_VERSION = '1.0.0';

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	public function materialize( array $task, array $snapshot ): array {
		$payload  = isset( $task['payload'] ) && is_array( $task['payload'] ) ? $task['payload'] : array();
		$payload  = GHCA_ACD_Archive_Task_Catalog::validate_ledger_payload( $task, $payload );
		$document = isset( $snapshot['snapshot_document'] ) && is_array( $snapshot['snapshot_document'] )
			? $snapshot['snapshot_document']
			: null;
		if ( null === $document || ! $this->snapshot_matches_task( $snapshot, $document, $payload ) ) {
			throw $this->invalid( 'archive_snapshot_invalid', 'The archive snapshot is invalid.' );
		}

		$items = array();
		foreach ( $document['courses'] as $ordinal => $course ) {
			$items[] = array(
				'archive_id'                  => $document['case']['archive_id'],
				'certificate_artifact_id'     => $course['certificate_artifact_id'],
				'completed_at_gmt'            => $course['completed_at_gmt'],
				'completion_status'           => $course['completion_status'],
				'course_id'                   => $course['course_id'],
				'course_stable_key'           => $course['course_stable_key'],
				'course_title'                => $course['course_title'],
				'cycle_key'                   => $document['case']['cycle_key'],
				'employee_user_id'            => $document['subject']['employee_user_id'],
				'item_ordinal'                => $ordinal,
				'item_schema_version'         => 1,
				'ledger_artifact_id'          => $payload['ledger_artifact_id'],
				'program_key'                 => $document['case']['program_key'],
				'quiz_score_basis_points'     => $course['quiz_score_basis_points'],
				'snapshot_id'                 => $document['case']['snapshot_id'],
				'started_at_gmt'              => $course['started_at_gmt'],
				'stream_id'                   => $document['case']['stream_id'],
				'time_spent_seconds'          => $course['time_spent_seconds'],
			);
		}
		if ( count( $items ) > self::MAX_LEDGER_ITEMS ) {
			throw $this->invalid( 'archive_ledger_invalid', 'The archive ledger is invalid.' );
		}
		$item_digests = array();
		foreach ( $items as $item ) {
			$item_digests[] = GHCA_ACD_Archive_Digester::item( $item );
		}
		$manifest_digest = GHCA_ACD_Archive_Digester::ledger_manifest( $item_digests );
		$ledger = array(
			'archive_id'              => $payload['archive_id'],
			'build_attempt_id'        => $payload['build_attempt_id'],
			'canonical_format'        => 'ghca-cjson-1',
			'item_count'              => count( $items ),
			'item_digests'            => $item_digests,
			'items'                   => $items,
			'ledger_artifact_id'      => $payload['ledger_artifact_id'],
			'manifest_digest'         => $manifest_digest,
			'schema_version'          => 1,
			'snapshot_digest'         => (string) $snapshot['snapshot_digest'],
			'snapshot_id'             => $payload['snapshot_id'],
			'stream_id'               => $payload['stream_id'],
		);
		try {
			$bytes = GHCA_ACD_Archive_Canonical_JSON::encode_bounded( $ledger, self::MAX_LEDGER_BYTES );
		} catch ( Throwable $error ) {
			throw $this->invalid( 'archive_ledger_invalid', 'The archive ledger is invalid.' );
		}
		$digest = hash( 'sha256', $bytes );
		$storage_key = 'committed/' . $document['case']['tenant_id'] . '/' . $payload['stream_id'] . '/' . $payload['archive_id'] . '/' . $payload['ledger_artifact_id'] . '.json';
		$descriptor = array(
			'artifact_id'              => $payload['ledger_artifact_id'],
			'artifact_kind'            => 'ledger',
			'artifact_schema_version'  => 1,
			'byte_count'               => strlen( $bytes ),
			'content_digest'           => $digest,
			'content_digest_algorithm' => 'sha256',
			'filename'                 => 'archive-ledger.json',
			'media_type'               => 'application/json',
			'producer_key'             => self::PRODUCER_KEY,
			'producer_version'         => self::PRODUCER_VERSION,
			'role_key'                 => 'ledger',
			'storage_adapter'          => 'private_local',
			'storage_key'              => $storage_key,
		);
		return array(
			'artifact_descriptor' => $descriptor,
			'ledger_bytes'        => $bytes,
			'ledger_document'     => $ledger,
			'ledger_items'        => $items,
		);
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $document
	 * @param array<string,mixed> $payload
	 */
	private function snapshot_matches_task( array $snapshot, array $document, array $payload ): bool {
		return isset( $snapshot['snapshot_id'], $snapshot['stream_id'], $snapshot['archive_id'], $snapshot['snapshot_digest'] )
			&& isset( $document['case'], $document['cycle'], $document['subject'], $document['courses'] )
			&& is_array( $document['case'] ) && is_array( $document['cycle'] )
			&& is_array( $document['subject'] ) && is_array( $document['courses'] )
			&& (string) $snapshot['snapshot_id'] === $payload['snapshot_id']
			&& (string) $snapshot['stream_id'] === $payload['stream_id']
			&& (string) $snapshot['archive_id'] === $payload['archive_id']
			&& isset( $document['case']['snapshot_id'], $document['case']['stream_id'], $document['case']['archive_id'], $document['case']['tenant_id'], $document['case']['cycle_key'], $document['case']['program_key'] )
			&& isset( $document['cycle']['key'], $document['subject']['employee_user_id'] )
			&& $document['case']['snapshot_id'] === $payload['snapshot_id']
			&& $document['case']['stream_id'] === $payload['stream_id']
			&& $document['case']['archive_id'] === $payload['archive_id']
			&& $document['case']['cycle_key'] === $document['cycle']['key'];
	}

	private function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND,
			$reason,
			$message
		);
	}
}
