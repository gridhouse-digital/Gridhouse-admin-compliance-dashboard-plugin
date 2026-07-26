<?php

/** Maps validated normalized evidence into the existing immutable snapshot-v1. */
final class GHCA_ACD_Archive_Evidence_Snapshot_Preparer {
	/**
	 * @param array<string,mixed> $evidence
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function prepare( array $evidence, array $context, string $captured_source_fingerprint, string $captured_at_gmt ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $captured_source_fingerprint ) ) {
			$this->invalid();
		}
		try {
			GHCA_ACD_Archive_Db_Format::utc_to_db( $captured_at_gmt );
		} catch ( Throwable $error ) {
			$this->invalid();
		}
		$courses = array();
		foreach ( $evidence['courses'] as $course ) {
			if ( $course['certificate_required'] ) {
				throw new GHCA_ACD_Archive_Evidence_Source_Exception(
					GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
					'archive_certificate_invalid',
					'certificate_gate'
				);
			}
			unset( $course['certificate_reference'] );
			$course['certificate_artifact_id'] = null;
			$courses[] = $course;
		}
		$request = $context['request_event'];
		$envelope = $request->recorded_document();
		$payload = $request->payload();
		$case = $evidence['case'];
		$document = array(
			'calculated' => $evidence['calculated'],
			'canonical_format' => $evidence['canonical_format'],
			'captured_at_gmt' => $captured_at_gmt,
			'case' => array(
				'archive_id' => $context['archive_id'],
				'case_key_digest' => $context['case_key']->digest(),
				'cycle_key' => $case['cycle_key'],
				'employee_user_id' => $case['employee_user_id'],
				'program_key' => $case['program_key'],
				'revision_number' => $context['revision_number'],
				'site_id' => $case['site_id'],
				'snapshot_id' => $context['snapshot_id'],
				'stream_id' => $context['stream_id'],
				'tenant_id' => $case['tenant_id'],
			),
			'completeness' => $evidence['completeness'],
			'courses' => $courses,
			'cycle' => $evidence['cycle'],
			'organization' => $evidence['organization'],
			'policy' => $evidence['policy'],
			'review' => array(
				'actor_user_id' => $envelope['actor_user_id'],
				'authority_code' => $envelope['authority_code'],
				'initiating_user_id' => $envelope['initiating_user_id'],
				'request_event_id' => $request->event_id(),
				'requested_at_gmt' => $envelope['occurred_at_gmt'],
				'reviewed_source_fingerprint' => $payload['reviewed_source_fingerprint'],
				'subject_scope_digest' => $payload['subject_scope_digest'],
			),
			'schema_version' => $evidence['schema_version'],
			'source' => array(
				'captured_source_fingerprint' => $captured_source_fingerprint,
				'evidence_assets' => array(),
				'learndash_version' => $evidence['source']['learndash_version'],
				'plugin_version' => $evidence['source']['plugin_version'],
				'reviewed_source_fingerprint' => $payload['reviewed_source_fingerprint'],
				'source_adapter_key' => $evidence['source']['source_adapter_key'],
				'source_adapter_version' => $evidence['source']['source_adapter_version'],
				'source_fingerprint_version' => 1,
				'source_record_ids' => $evidence['source']['source_record_ids'],
				'wordpress_version' => $evidence['source']['wordpress_version'],
			),
			'subject' => $evidence['subject'],
		);
		try {
			$json = GHCA_ACD_Archive_Canonical_JSON::encode( $document );
		} catch ( InvalidArgumentException $error ) {
			$reason = false !== strpos( $error->getMessage(), 'value-count' ) || false !== strpos( $error->getMessage(), 'byte limit' )
				? 'archive_evidence_incomplete'
				: 'archive_snapshot_invalid';
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
				$reason,
				'archive_evidence_incomplete' === $reason ? 'snapshot_byte_limit' : 'snapshot_prepare'
			);
		}
		if ( strlen( $json ) > GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
				'archive_evidence_incomplete',
				'snapshot_byte_limit'
			);
		}
		return array(
			'byte_count' => strlen( $json ),
			'captured_source_fingerprint' => $captured_source_fingerprint,
			'snapshot_digest' => GHCA_ACD_Archive_Digester::snapshot( $document ),
			'snapshot_document' => GHCA_ACD_Archive_Canonical_JSON::detach( $document ),
		);
	}

	private function invalid(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_snapshot_invalid',
			'snapshot_prepare'
		);
	}
}
