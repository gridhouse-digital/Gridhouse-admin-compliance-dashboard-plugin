<?php

require __DIR__ . '/persistence-bootstrap.php';
require __DIR__ . '/persistence-fixtures.php';

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-17T12:00:00Z', 'side-records' );

/** @param array<string,mixed> $stack @param callable|null $mutate @param array<string,mixed> $opts @return array<string,mixed> */
function ghca_side_capture( array $stack, GHCA_Persist_Scenario $s, $mutate = null, array $opts = array() ): array {
	$payload = $s->payload( 'EvidenceSnapshotCaptured', array(
		'archive_id'            => $s->id( 'archive-1' ),
		'snapshot_id'           => $s->id( 'snapshot-1' ),
		'certificate_asset_ids' => array( $s->id( 'cert-1' ), $s->id( 'cert-2' ) ),
	) );
	$side = ghca_persist_snapshot_side_records( $stack, $s, $payload );
	if ( is_callable( $mutate ) ) {
		$mutate( $payload, $side );
	}
	$opts['side_records'] = $side;
	return persist_single( $stack, $s, 'RecordEvidenceSnapshot', 'capture_evidence_snapshot', $payload, $opts );
}

/** @param array<string,mixed> $payload @param array<string,mixed> $side */
function ghca_side_rehash_snapshot( array &$payload, array &$side ): void {
	$document = $side['snapshot']['snapshot_document'];
	$payload['snapshot_digest'] = GHCA_ACD_Archive_Digester::snapshot( $document );
	$payload['byte_count'] = strlen( GHCA_ACD_Archive_Canonical_JSON::encode( $document ) );
}

/** @param array<string,mixed> $payload @param array<string,mixed> $side */
function ghca_side_rehash_ledger( array &$payload, array &$side ): void {
	$digests = array();
	foreach ( $side['ledger_items'] as $item ) {
		$digests[] = GHCA_ACD_Archive_Digester::item( $item );
	}
	$payload['item_count'] = count( $side['ledger_items'] );
	$payload['manifest_digest'] = GHCA_ACD_Archive_Digester::ledger_manifest( $digests );
}

/** @param array<string,mixed> $stack @param callable|null $mutate @param array<string,mixed> $opts @return array<string,mixed> */
function ghca_side_materialize( array $stack, GHCA_Persist_Scenario $s, string $kind, $mutate = null, array $opts = array() ): array {
	$snapshot = $stack['snapshot_store']->find( $s->id( 'snapshot-1' ) );
	if ( 'ledger' === $kind ) {
		$payload = $s->payload( 'LedgerMaterialized', array(
			'archive_id' => $s->id( 'archive-1' ), 'snapshot_id' => $s->id( 'snapshot-1' ),
			'build_attempt_id' => $s->id( 'attempt-1' ), 'ledger_artifact_id' => $s->id( 'ledger-1' ),
			'snapshot_digest' => $snapshot['snapshot_digest'],
		) );
		$items = ghca_persist_ledger_documents( $s, $payload, $snapshot['snapshot_document'] );
		$digests = array();
		foreach ( $items as $item ) {
			$digests[] = GHCA_ACD_Archive_Digester::item( $item );
		}
		$payload['item_count'] = count( $items );
		$payload['manifest_digest'] = GHCA_ACD_Archive_Digester::ledger_manifest( $digests );
		$artifact_id = $payload['ledger_artifact_id'];
		$operation = 'materialize_ledger';
	} else {
		$certificate_digests = array();
		foreach ( $snapshot['snapshot_document']['source']['evidence_assets'] as $asset ) {
			$certificate_digests[] = $asset['content_digest'];
		}
		$payload = $s->payload( 'PacketMaterialized', array(
			'archive_id' => $s->id( 'archive-1' ), 'snapshot_id' => $s->id( 'snapshot-1' ),
			'build_attempt_id' => $s->id( 'attempt-1' ), 'packet_artifact_id' => $s->id( 'packet-1' ),
			'snapshot_digest' => $snapshot['snapshot_digest'], 'certificate_content_digests' => $certificate_digests,
		) );
		$items = array();
		$artifact_id = $payload['packet_artifact_id'];
		$operation = 'materialize_packet';
	}
	$side = array(
		'artifact' => ghca_persist_artifact_descriptor( $s, $kind, $artifact_id, $payload['content_digest'] ),
		'ledger_items' => $items,
	);
	if ( is_callable( $mutate ) ) {
		$mutate( $payload, $side );
	}
	$caller = array( 'archive_id' => $payload['archive_id'], 'artifact_kind' => $kind );
	$server = $payload;
	unset( $server['payload_schema_version'], $server['archive_id'] );
	$opts['side_records'] = $side;
	return ghca_persist_execute( $stack, $s, 'RecordMaterializedArtifact', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $operation, $payload ) {
		return $case->{$operation}( $payload );
	}, $opts );
}

/** @param array<string,mixed> $stack */
function ghca_side_to_snapshot( array $stack, GHCA_Persist_Scenario $s ): void {
	persist_request_archive( $stack, $s );
	persist_start_build( $stack, $s );
	ghca_side_capture( $stack, $s );
}

/** @param array<string,mixed> $stack */
function ghca_side_to_verifying( array $stack, GHCA_Persist_Scenario $s ): void {
	ghca_side_to_snapshot( $stack, $s );
	ghca_side_materialize( $stack, $s, 'ledger' );
	ghca_side_materialize( $stack, $s, 'packet' );
}

/** Build finalization server facts from the immutable event stream, not mutable side rows. */
function ghca_side_finalize_from_events( array $stack, GHCA_Persist_Scenario $s, array $opts = array() ): array {
	$snapshot_event = null;
	$ledger_event = null;
	$packet_event = null;
	foreach ( $stack['event_store']->load_events( (string) $s->stream_id ) as $event ) {
		if ( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED === $event->type() ) {
			$snapshot_event = $event->payload();
		} elseif ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type() ) {
			$ledger_event = $event->payload();
		} elseif ( GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED === $event->type() ) {
			$packet_event = $event->payload();
		}
	}
	$binding = array(
		'archive_id' => $snapshot_event['archive_id'], 'revision_number' => $snapshot_event['revision_number'],
		'snapshot_id' => $snapshot_event['snapshot_id'], 'snapshot_digest' => $snapshot_event['snapshot_digest'],
		'ledger_artifact_id' => $ledger_event['ledger_artifact_id'], 'ledger_content_digest' => $ledger_event['content_digest'],
		'packet_artifact_id' => $packet_event['packet_artifact_id'], 'packet_content_digest' => $packet_event['content_digest'],
		'expected_predecessor_archive_id' => null, 'active_identity_digest' => $s->case_key->digest(),
	);
	$verified = $s->payload( 'ArchiveVerified', $binding );
	$verified['source_fingerprint'] = $snapshot_event['captured_source_fingerprint'];
	$finalized = $s->payload( 'ArchiveFinalized', $binding );
	$caller = array( 'archive_id' => $binding['archive_id'] );
	$server = array( 'verified' => $verified, 'finalized' => $finalized );
	return ghca_persist_execute( $stack, $s, 'VerifyAndFinalize', $caller, $server, static function ( GHCA_ACD_Archive_Case $case ) use ( $verified, $finalized ) {
		return $case->verify_and_finalize( $verified, $finalized );
	}, $opts );
}

/** Return a recorded event with the same semantics but a contradictory source-event identity. */
function ghca_side_alternate_recording( GHCA_ACD_Archive_Event $event, string $event_id ): GHCA_ACD_Archive_Event {
	$document = $event->recorded_document();
	$context = $document;
	unset( $context['event_type'], $context['event_schema_version'], $context['payload'], $context['metadata'], $context['event_digest'] );
	$context['event_id'] = $event_id;
	return ( new GHCA_ACD_Archive_Event( $event->type(), 1, $event->payload(), $event->metadata() ) )->with_recording_context( $context );
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function ghca_side_descriptor_from_row( array $row ): array {
	return array(
		'artifact_id' => $row['artifact_id'], 'artifact_kind' => $row['artifact_kind'],
		'artifact_schema_version' => (int) $row['artifact_schema_version'], 'byte_count' => (int) $row['byte_count'],
		'content_digest' => $row['content_digest'], 'content_digest_algorithm' => $row['content_digest_algorithm'],
		'filename' => $row['filename'], 'media_type' => $row['media_type'], 'producer_key' => $row['producer_key'],
		'producer_version' => $row['producer_version'], 'role_key' => $row['role_key'],
		'storage_adapter' => $row['storage_adapter'], 'storage_key' => $row['storage_key'],
	);
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function ghca_side_binding_from_row( array $row ): array {
	return array(
		'archive_id' => $row['archive_id'], 'build_attempt_id' => $row['build_attempt_id'],
		'created_at_gmt' => GHCA_ACD_Archive_Db_Format::db_to_utc( $row['created_at_gmt'] ),
		'snapshot_digest' => $row['snapshot_digest'], 'snapshot_id' => $row['snapshot_id'], 'stream_id' => $row['stream_id'],
	);
}

// Snapshot insertion and retained-row verification.
$sSnapshot = new GHCA_Persist_Scenario( 'side-snapshot' );
persist_request_archive( $stack, $sSnapshot );
persist_start_build( $stack, $sSnapshot );
$snapshot_pre_sequence = $sSnapshot->head_sequence;
$snapshot_pre_digest = $sSnapshot->head_digest;
$snapshot_command_id = $sSnapshot->id( 'snapshot-command' );
$snapshot_response = ghca_side_capture( $stack, $sSnapshot, null, array(
	'idempotency_key' => 'side-snapshot-capture', 'command_id' => $snapshot_command_id,
) );
$snapshot = $stack['snapshot_store']->find( $sSnapshot->id( 'snapshot-1' ) );
$snapshot_events = $stack['event_store']->load_events( (string) $sSnapshot->stream_id );
$snapshot_event = $snapshot_events[ count( $snapshot_events ) - 1 ];
$snapshot_artifact_count = (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_artifacts' ) . ' WHERE snapshot_id = %s',
	$sSnapshot->id( 'snapshot-1' )
) );
archive_check( null !== $snapshot && 2 === $snapshot_artifact_count && $snapshot['source_event_id'] === $snapshot_event->event_id(), 'SIDE-SNAPSHOT-INSERT-ATOMIC snapshot, certificate descriptors, and source event commit together' );

$replay_payload = $sSnapshot->payload( 'EvidenceSnapshotCaptured', array(
	'archive_id' => $sSnapshot->id( 'archive-1' ), 'snapshot_id' => $sSnapshot->id( 'snapshot-1' ),
	'certificate_asset_ids' => array( $sSnapshot->id( 'cert-1' ), $sSnapshot->id( 'cert-2' ) ),
) );
ghca_persist_snapshot_side_records( $stack, $sSnapshot, $replay_payload );
$replay_fingerprint = ghca_persist_db_fingerprint( $wpdb );
$replay_response = persist_single( $stack, $sSnapshot, 'RecordEvidenceSnapshot', 'capture_evidence_snapshot', $replay_payload, array(
	'idempotency_key' => 'side-snapshot-capture', 'command_id' => $snapshot_command_id,
	'expected_sequence' => $snapshot_pre_sequence, 'expected_head_digest' => $snapshot_pre_digest, 'no_track' => true,
) );
archive_check( $snapshot_response === $replay_response && $replay_fingerprint === ghca_persist_db_fingerprint( $wpdb ), 'SIDE-RECEIPT-FIRST-REPLAY returns the stored snapshot response without requiring or rewriting side records' );

$alternate_event = ghca_side_alternate_recording( $snapshot_event, $sSnapshot->id( 'alternate-source-event' ) );
persist_expect_failure( $wpdb, static function () use ( $stack, $snapshot, $alternate_event ) {
	return $stack['snapshot_store']->insert( array( 'snapshot_document' => $snapshot['snapshot_document'] ), $alternate_event );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_contradictory_duplicate', 'SIDE-SNAPSHOT-CONTRADICTORY-DUPLICATE' );

$snapshot_table = $wpdb->prefix . 'ghca_acd_archive_snapshots';
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_schema_version = 99 WHERE snapshot_id = %s", $snapshot['snapshot_id'] ), 'tamper snapshot schema' );
persist_expect_failure( $wpdb, static function () use ( $stack, $snapshot ) {
	$stack['snapshot_store']->find( $snapshot['snapshot_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'unsupported_snapshot_schema_version', 'SIDE-SNAPSHOT-UNKNOWN-SCHEMA' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_schema_version = 1 WHERE snapshot_id = %s", $snapshot['snapshot_id'] ), 'restore snapshot schema' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_json = CONCAT(snapshot_json, ' ') WHERE snapshot_id = %s", $snapshot['snapshot_id'] ), 'tamper snapshot canonical bytes' );
persist_expect_failure( $wpdb, static function () use ( $stack, $snapshot ) {
	$stack['snapshot_store']->find( $snapshot['snapshot_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_canonical_invalid', 'SIDE-SNAPSHOT-CANONICAL-TAMPER' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_json = %s WHERE snapshot_id = %s", $snapshot['snapshot_json'], $snapshot['snapshot_id'] ), 'restore snapshot canonical bytes' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_digest = %s WHERE snapshot_id = %s", str_repeat( 'f', 64 ), $snapshot['snapshot_id'] ), 'tamper snapshot digest' );
persist_expect_failure( $wpdb, static function () use ( $stack, $snapshot ) {
	$stack['snapshot_store']->find( $snapshot['snapshot_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_retained_binding_mismatch', 'SIDE-SNAPSHOT-DIGEST-TAMPER' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET snapshot_digest = %s WHERE snapshot_id = %s", $snapshot['snapshot_digest'], $snapshot['snapshot_id'] ), 'restore snapshot digest' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET archive_id = %s WHERE snapshot_id = %s", $sSnapshot->id( 'wrong-archive' ), $snapshot['snapshot_id'] ), 'tamper snapshot archive identity' );
persist_expect_failure( $wpdb, static function () use ( $stack, $snapshot ) {
	$stack['snapshot_store']->find( $snapshot['snapshot_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_retained_binding_mismatch', 'SIDE-SNAPSHOT-IDENTITY-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET archive_id = %s WHERE snapshot_id = %s", $snapshot['archive_id'], $snapshot['snapshot_id'] ), 'restore snapshot archive identity' );

$snapshot_schema_cases = array(
	'SIDE-SNAPSHOT-V1-TOP-LEVEL' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['canonical_format'] );
		$side['snapshot']['snapshot_document']['canonical_format_version'] = 1;
	},
	'SIDE-SNAPSHOT-V1-REVIEW' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['review']['authority_code'] );
	},
	'SIDE-SNAPSHOT-V1-SUBJECT' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['subject']['registered_at_gmt'] );
	},
	'SIDE-SNAPSHOT-V1-ORGANIZATION' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['organization']['public_url'] = 'https://example.test';
	},
	'SIDE-SNAPSHOT-V1-POLICY' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['policy']['course_lifespan_rules'] );
	},
	'SIDE-SNAPSHOT-V1-SOURCE-ASSET' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['source']['evidence_assets'][0]['role_key'] = 'course:999';
	},
	'SIDE-SNAPSHOT-V1-COURSE' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['courses'][0]['source_provenance'] );
	},
	'SIDE-SNAPSHOT-V1-CALCULATED' => static function ( array &$payload, array &$side ): void {
		unset( $side['snapshot']['snapshot_document']['calculated']['total_training_seconds'] );
	},
	'SIDE-SNAPSHOT-V1-COMPLETENESS' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['completeness']['missing_fields'] = array( 'courses.101.completed_at_gmt' );
	},
	'SIDE-SNAPSHOT-V1-COURSE-ORDER' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['courses'] = array_reverse( $side['snapshot']['snapshot_document']['courses'] );
	},
	'SIDE-SNAPSHOT-V1-CYCLE-BOUNDARY' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['courses'][0]['completed_at_gmt'] = $side['snapshot']['snapshot_document']['cycle']['end_gmt'];
	},
	'SIDE-SNAPSHOT-V1-COMPLETION-STATE' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['courses'][0]['completed_at_gmt'] = null;
	},
	'SIDE-SNAPSHOT-V1-TRAINING-TOTAL' => static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['calculated']['total_training_seconds'] = '1';
	},
);
foreach ( $snapshot_schema_cases as $label => $mutate ) {
	$scenario = new GHCA_Persist_Scenario( strtolower( str_replace( '_', '-', $label ) ) );
	persist_request_archive( $stack, $scenario );
	persist_start_build( $stack, $scenario );
	persist_expect_failure( $wpdb, static function () use ( $stack, $scenario, $mutate ) {
		return ghca_side_capture( $stack, $scenario, $mutate, array( 'no_track' => true ) );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_schema_invalid', $label );
}

$sReviewBinding = new GHCA_Persist_Scenario( 'side-snapshot-review-binding' );
persist_request_archive( $stack, $sReviewBinding );
persist_start_build( $stack, $sReviewBinding );
persist_expect_failure( $wpdb, static function () use ( $stack, $sReviewBinding ) {
	return ghca_side_capture( $stack, $sReviewBinding, static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['review']['request_event_id'] = str_repeat( 'f', 32 );
		ghca_side_rehash_snapshot( $payload, $side );
	}, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'snapshot_review_binding_mismatch', 'SIDE-SNAPSHOT-V1-REQUEST-BINDING' );

// Approved deterministic limits, each through the complete Unit of Work.
$limit_cases = array(
	'SIDE-SNAPSHOT-LIMIT-BYTES' => array( 'side-limit-bytes', 'side_snapshot_bytes_exceeded', static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['calculated']['padding'] = array_fill( 0, 4, str_repeat( 'b', GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) );
	} ),
	'SIDE-SNAPSHOT-LIMIT-DEPTH' => array( 'side-limit-depth', 'side_snapshot_depth_exceeded', static function ( array &$payload, array &$side ): void {
		$value = 'leaf';
		for ( $i = 0; $i < 34; $i++ ) {
			$value = array( 'nested' => $value );
		}
		$side['snapshot']['snapshot_document']['calculated']['deep'] = $value;
	} ),
	'SIDE-SNAPSHOT-LIMIT-STRING-BYTES' => array( 'side-limit-string', 'side_snapshot_string_bytes_exceeded', static function ( array &$payload, array &$side ): void {
		$side['snapshot']['snapshot_document']['calculated']['oversized'] = str_repeat( 's', GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES + 1 );
	} ),
	'SIDE-SNAPSHOT-LIMIT-ASSETS' => array( 'side-limit-assets', 'side_evidence_asset_count_exceeded', static function ( array &$payload, array &$side ): void {
		$asset = $side['snapshot']['snapshot_document']['source']['evidence_assets'][0];
		$side['snapshot']['snapshot_document']['source']['evidence_assets'] = array_fill( 0, GHCA_ACD_WPDB_Archive_Snapshot_Store::MAX_EVIDENCE_ASSETS + 1, $asset );
	} ),
);
foreach ( $limit_cases as $label => $case ) {
	$scenario = new GHCA_Persist_Scenario( $case[0] );
	persist_request_archive( $stack, $scenario );
	persist_start_build( $stack, $scenario );
	persist_expect_failure( $wpdb, static function () use ( $stack, $scenario, $case ) {
		return ghca_side_capture( $stack, $scenario, $case[2], array( 'no_track' => true ) );
	}, 'GHCA_ACD_Archive_Persistence_Exception', $case[1], $label );
}

$sItemLimit = new GHCA_Persist_Scenario( 'side-limit-items' );
ghca_side_to_snapshot( $stack, $sItemLimit );
persist_expect_failure( $wpdb, static function () use ( $stack, $sItemLimit ) {
	return ghca_side_materialize( $stack, $sItemLimit, 'ledger', static function ( array &$payload, array &$side ): void {
		$side['ledger_items'] = array_fill( 0, GHCA_ACD_WPDB_Archive_Artifact_Repository::MAX_LEDGER_ITEMS + 1, array() );
	}, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'side_ledger_item_count_exceeded', 'SIDE-SNAPSHOT-LIMIT-ITEMS' );

$ledger_snapshot_cases = array(
	'SIDE-LEDGER-SNAPSHOT-EMPLOYEE' => static function ( array &$item ): void { $item['employee_user_id'] = '99'; },
	'SIDE-LEDGER-SNAPSHOT-PROGRAM' => static function ( array &$item ): void { $item['program_key'] = 'different'; },
	'SIDE-LEDGER-SNAPSHOT-CYCLE' => static function ( array &$item ): void { $item['cycle_key'] = 'different-cycle'; },
	'SIDE-LEDGER-SNAPSHOT-COURSE' => static function ( array &$item ): void { $item['course_id'] = '999'; },
	'SIDE-LEDGER-SNAPSHOT-CERTIFICATE' => static function ( array &$item ): void { $item['certificate_artifact_id'] = str_repeat( 'e', 32 ); },
	'SIDE-LEDGER-SNAPSHOT-EVIDENCE' => static function ( array &$item ): void { $item['time_spent_seconds'] = '7200'; },
);
foreach ( $ledger_snapshot_cases as $label => $mutate_item ) {
	$scenario = new GHCA_Persist_Scenario( strtolower( str_replace( '_', '-', $label ) ) );
	ghca_side_to_snapshot( $stack, $scenario );
	persist_expect_failure( $wpdb, static function () use ( $stack, $scenario, $mutate_item ) {
		return ghca_side_materialize( $stack, $scenario, 'ledger', static function ( array &$payload, array &$side ) use ( $mutate_item ): void {
			$mutate_item( $side['ledger_items'][0] );
			ghca_side_rehash_ledger( $payload, $side );
		}, array( 'no_track' => true ) );
	}, 'GHCA_ACD_Archive_Persistence_Exception', 'ledger_snapshot_evidence_mismatch', $label );
}

// Artifact and ledger repository behavior on a complete materialized case.
$sMaterial = new GHCA_Persist_Scenario( 'side-materialized' );
ghca_side_to_verifying( $stack, $sMaterial );
$ledger = $stack['artifact_repository']->find_descriptor( $sMaterial->id( 'ledger-1' ) );
$packet = $stack['artifact_repository']->find_descriptor( $sMaterial->id( 'packet-1' ) );
$ledger_items = $stack['artifact_repository']->load_ledger_items( $ledger['artifact_id'] );
archive_check( null !== $ledger && null !== $packet && 2 === count( $ledger_items ), 'SIDE-ARTIFACT-INSERT-ATOMIC ledger and packet descriptors commit with the materialization events' );
archive_check( '0' === (string) $ledger_items[0]['item_ordinal'] && '1' === (string) $ledger_items[1]['item_ordinal'], 'SIDE-LEDGER-ORDERED-INSERT ledger items load in exact contiguous canonical order' );
$material_task_types = $wpdb->get_col( $wpdb->prepare(
	'SELECT task_type FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_tasks' ) . ' WHERE stream_id = %s ORDER BY task_type',
	(string) $sMaterial->stream_id
) );
archive_check( array( 'capture_evidence', 'materialize_ledger', 'materialize_packet', 'verify_and_finalize' ) === $material_task_types, 'SIDE-TASK-SEQUENCE snapshot creates both materializers and only the second materialization creates verification' );

$duplicate_descriptor = ghca_side_descriptor_from_row( $ledger );
$duplicate_descriptor['artifact_id'] = $sMaterial->id( 'ledger-contradictory' );
persist_expect_failure( $wpdb, static function () use ( $stack, $duplicate_descriptor, $ledger ) {
	return $stack['artifact_repository']->insert_descriptor( $duplicate_descriptor, ghca_side_binding_from_row( $ledger ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'artifact_contradictory_duplicate', 'SIDE-ARTIFACT-CONTRADICTORY-DUPLICATE' );

$artifact_table = $wpdb->prefix . 'ghca_acd_archive_artifacts';
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET snapshot_id = %s WHERE artifact_id = %s", $sMaterial->id( 'wrong-snapshot' ), $packet['artifact_id'] ), 'tamper artifact snapshot binding' );
persist_expect_failure( $wpdb, static function () use ( $stack, $packet ) {
	$stack['artifact_repository']->find_descriptor( $packet['artifact_id'], array( 'snapshot_id' => $packet['snapshot_id'] ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'artifact_authoritative_binding_mismatch', 'SIDE-ARTIFACT-SNAPSHOT-BINDING' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET snapshot_id = %s WHERE artifact_id = %s", $packet['snapshot_id'], $packet['artifact_id'] ), 'restore artifact snapshot binding' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET content_digest = %s WHERE artifact_id = %s", str_repeat( 'e', 64 ), $packet['artifact_id'] ), 'tamper artifact content digest' );
persist_expect_failure( $wpdb, static function () use ( $stack, $packet ) {
	$stack['artifact_repository']->find_descriptor( $packet['artifact_id'], array( 'content_digest' => $packet['content_digest'] ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'artifact_authoritative_binding_mismatch', 'SIDE-ARTIFACT-DIGEST-TAMPER' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET content_digest = %s WHERE artifact_id = %s", $packet['content_digest'], $packet['artifact_id'] ), 'restore artifact content digest' );

ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET artifact_schema_version = 99 WHERE artifact_id = %s", $packet['artifact_id'] ), 'tamper artifact schema version' );
persist_expect_failure( $wpdb, static function () use ( $stack, $packet ) {
	$stack['artifact_repository']->find_descriptor( $packet['artifact_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'unsupported_artifact_schema_version', 'SIDE-ARTIFACT-UNKNOWN-SCHEMA' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET artifact_schema_version = 1 WHERE artifact_id = %s", $packet['artifact_id'] ), 'restore artifact schema version' );

$bad_storage = ghca_side_descriptor_from_row( $packet );
$bad_storage['artifact_id'] = $sMaterial->id( 'bad-storage' );
$bad_storage['storage_key'] = 'C:\\public\\artifact.pdf';
$bad_storage['role_key'] = 'packet';
persist_expect_failure( $wpdb, static function () use ( $stack, $bad_storage, $packet ) {
	return $stack['artifact_repository']->insert_descriptor( $bad_storage, ghca_side_binding_from_row( $packet ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'artifact_storage_key_invalid', 'SIDE-ARTIFACT-STORAGE-KEY-REJECTION' );

$item_documents = array( $ledger_items[0]['item_document'], $ledger_items[1]['item_document'] );
$ledger_event = null;
foreach ( $stack['event_store']->load_events( (string) $sMaterial->stream_id ) as $event ) {
	if ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type() ) {
		$ledger_event = $event->payload();
	}
}
$ledger_binding = array(
	'archive_id' => $ledger_event['archive_id'], 'item_count' => $ledger_event['item_count'],
	'ledger_artifact_id' => $ledger_event['ledger_artifact_id'], 'manifest_digest' => $ledger_event['manifest_digest'],
	'snapshot_id' => $ledger_event['snapshot_id'], 'stream_id' => (string) $sMaterial->stream_id,
);
$gap = $item_documents;
$gap[1]['item_ordinal'] = 2;
persist_expect_failure( $wpdb, static function () use ( $stack, $gap, $ledger_binding ) {
	$stack['artifact_repository']->insert_ledger_items( $gap, $ledger_binding );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'ledger_gap', 'SIDE-LEDGER-GAP-REJECTION' );
$duplicate = $item_documents;
$duplicate[1]['item_ordinal'] = 0;
persist_expect_failure( $wpdb, static function () use ( $stack, $duplicate, $ledger_binding ) {
	$stack['artifact_repository']->insert_ledger_items( $duplicate, $ledger_binding );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'ledger_duplicate', 'SIDE-LEDGER-DUPLICATE-REJECTION' );
$wrong_snapshot = $item_documents;
$wrong_snapshot[0]['snapshot_id'] = $sMaterial->id( 'wrong-snapshot-item' );
persist_expect_failure( $wpdb, static function () use ( $stack, $wrong_snapshot, $ledger_binding ) {
	$stack['artifact_repository']->insert_ledger_items( $wrong_snapshot, $ledger_binding );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'ledger_snapshot_binding_mismatch', 'SIDE-LEDGER-SNAPSHOT-BINDING' );
$unsigned_overflow = $item_documents;
$unsigned_overflow[0]['time_spent_seconds'] = '18446744073709551616';
persist_expect_failure( $wpdb, static function () use ( $stack, $unsigned_overflow, $ledger_binding ) {
	$stack['artifact_repository']->insert_ledger_items( $unsigned_overflow, $ledger_binding );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'ledger_item_schema_invalid', 'SIDE-LEDGER-UNSIGNED-RANGE' );

$ledger_item_table = $wpdb->prefix . 'ghca_acd_archive_ledger_items';
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$ledger_item_table} SET item_schema_version = 99 WHERE ledger_artifact_id = %s AND item_ordinal = 0", $ledger['artifact_id'] ), 'tamper ledger item schema version' );
persist_expect_failure( $wpdb, static function () use ( $stack, $ledger ) {
	$stack['artifact_repository']->load_ledger_items( $ledger['artifact_id'] );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'unsupported_ledger_item_schema_version', 'SIDE-LEDGER-UNKNOWN-SCHEMA' );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$ledger_item_table} SET item_schema_version = 1 WHERE ledger_artifact_id = %s AND item_ordinal = 0", $ledger['artifact_id'] ), 'restore ledger item schema version' );

// P2 rollback points: every preceding side record must disappear with the command.
$rollback_points = array(
	'SIDE-RECORD-EVENT-ROLLBACK' => array( 'side-rb-event', 'insert', 'ghca_acd_archive_events', 'event_insert_failed' ),
	'SIDE-RECORD-PROJECTION-ROLLBACK' => array( 'side-rb-projection', 'query', 'projection_heads SET projected_sequence', 'projector_head_update_failed' ),
	'SIDE-RECORD-TASK-ROLLBACK' => array( 'side-rb-task', 'insert', 'ghca_acd_archive_tasks', 'task_insert_failed' ),
	'SIDE-RECORD-STREAM-HEAD-ROLLBACK' => array( 'side-rb-head', 'query', 'streams SET head_sequence', 'stream_head_update_failed' ),
	'SIDE-RECORD-RECEIPT-ROLLBACK' => array( 'side-rb-receipt', 'insert', 'ghca_acd_archive_commands', 'receipt_insert_failed' ),
);
$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$proxy_stack = ghca_persist_stack( $proxy, '2026-07-17T12:10:00Z', 'side-rollbacks' );
foreach ( $rollback_points as $label => $point ) {
	$scenario = new GHCA_Persist_Scenario( $point[0] );
	persist_request_archive( $proxy_stack, $scenario );
	persist_start_build( $proxy_stack, $scenario );
	$proxy->add_hook( $point[1], $point[2], 'fail' );
	persist_expect_failure( $wpdb, static function () use ( $proxy_stack, $scenario ) {
		return ghca_side_capture( $proxy_stack, $scenario, null, array( 'no_track' => true ) );
	}, 'GHCA_ACD_Archive_Persistence_Exception', $point[3], $label );
	$proxy->clear_hooks();
	ghca_side_capture( $proxy_stack, $scenario );
	archive_check( null !== $proxy_stack['snapshot_store']->find( $scenario->id( 'snapshot-1' ) ), $label . ' clean retry commits the immutable snapshot exactly once' );
}

// Finalization fail-closed proofs and exact-complete positive control.
$sMissingSnapshot = new GHCA_Persist_Scenario( 'side-final-missing-snapshot' );
ghca_side_to_verifying( $stack, $sMissingSnapshot );
ghca_persist_query( $wpdb, $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE snapshot_id = %s", $sMissingSnapshot->id( 'snapshot-1' ) ), 'test-only remove snapshot' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sMissingSnapshot ) {
	return ghca_side_finalize_from_events( $stack, $sMissingSnapshot, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'finalization_missing_snapshot', 'SIDE-FINALIZATION-MISSING-SNAPSHOT' );

$sMissingArtifact = new GHCA_Persist_Scenario( 'side-final-missing-artifact' );
ghca_side_to_verifying( $stack, $sMissingArtifact );
ghca_persist_query( $wpdb, $wpdb->prepare( "DELETE FROM {$artifact_table} WHERE artifact_id = %s", $sMissingArtifact->id( 'packet-1' ) ), 'test-only remove packet descriptor' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sMissingArtifact ) {
	return ghca_side_finalize_from_events( $stack, $sMissingArtifact, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'finalization_missing_artifact', 'SIDE-FINALIZATION-MISSING-ARTIFACT' );

$sDigestMismatch = new GHCA_Persist_Scenario( 'side-final-digest-mismatch' );
ghca_side_to_verifying( $stack, $sDigestMismatch );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET content_digest = %s WHERE artifact_id = %s", str_repeat( 'd', 64 ), $sDigestMismatch->id( 'ledger-1' ) ), 'test-only tamper finalization digest' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sDigestMismatch ) {
	return ghca_side_finalize_from_events( $stack, $sDigestMismatch, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'finalization_digest_mismatch', 'SIDE-FINALIZATION-DIGEST-MISMATCH' );

$sCausalMismatch = new GHCA_Persist_Scenario( 'side-final-causal-mismatch' );
ghca_side_to_verifying( $stack, $sCausalMismatch );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$snapshot_table} SET source_event_id = %s WHERE snapshot_id = %s", $sCausalMismatch->id( 'wrong-source-event' ), $sCausalMismatch->id( 'snapshot-1' ) ), 'test-only tamper snapshot causal event' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sCausalMismatch ) {
	return ghca_side_finalize_from_events( $stack, $sCausalMismatch, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'finalization_digest_mismatch', 'SIDE-FINALIZATION-CAUSAL-BINDING' );

$sBuildMismatch = new GHCA_Persist_Scenario( 'side-final-build-mismatch' );
ghca_side_to_verifying( $stack, $sBuildMismatch );
$wrong_attempt = $sBuildMismatch->id( 'wrong-build-attempt' );
$wrong_dedupe = GHCA_ACD_Archive_Digester::artifact_dedupe( array(
	'archive_id' => $sBuildMismatch->id( 'archive-1' ), 'build_attempt_id' => $wrong_attempt,
	'artifact_kind' => 'ledger', 'role_key' => 'ledger',
) );
ghca_persist_query( $wpdb, $wpdb->prepare( "UPDATE {$artifact_table} SET build_attempt_id = %s, dedupe_digest = %s WHERE artifact_id = %s", $wrong_attempt, $wrong_dedupe, $sBuildMismatch->id( 'ledger-1' ) ), 'test-only tamper self-consistent artifact build attempt' );
persist_expect_failure( $wpdb, static function () use ( $stack, $sBuildMismatch ) {
	return ghca_side_finalize_from_events( $stack, $sBuildMismatch, array( 'no_track' => true ) );
}, 'GHCA_ACD_Archive_Persistence_Exception', 'finalization_digest_mismatch', 'SIDE-FINALIZATION-BUILD-ATTEMPT-BINDING' );

$sComplete = new GHCA_Persist_Scenario( 'side-final-complete' );
ghca_side_to_verifying( $stack, $sComplete );
$complete_response = persist_verify_finalize( $stack, $sComplete );
archive_check( 'committed' === $complete_response['result_code'] && '7' === (string) $complete_response['last_stream_sequence'], 'SIDE-FINALIZATION-EXACT-COMPLETE exact snapshot, descriptors, manifest, ledger items, and event bindings finalize atomically' );

// Dark-mode and append-only surfaces.
$plugin_root = dirname( __DIR__, 2 );
$entrypoint = file_get_contents( $plugin_root . '/gridhouse-admin-compliance-dashboard.php' );
$production_files = array(
	$plugin_root . '/includes/archive/infrastructure/class-wpdb-archive-snapshot-store.php',
	$plugin_root . '/includes/archive/infrastructure/class-wpdb-archive-artifact-repository.php',
	$plugin_root . '/includes/archive/application/class-archive-unit-of-work.php',
);
$production_text = '';
foreach ( $production_files as $file ) {
	$production_text .= file_get_contents( $file ) . "\n";
}
archive_check( 0 === preg_match( '/archive/i', $entrypoint ) && 0 === preg_match( '/\b(?:add_action|add_filter|register_activation_hook|wp_schedule_event)\s*\(/', $production_text ), 'SIDE-NO-RUNTIME-WIRING no entrypoint reference, hook, worker, cron, or activation wiring exists' );
$snapshot_methods = get_class_methods( 'GHCA_ACD_WPDB_Archive_Snapshot_Store' );
$artifact_methods = get_class_methods( 'GHCA_ACD_WPDB_Archive_Artifact_Repository' );
archive_check(
	array() === preg_grep( '/update|delete/i', array_merge( $snapshot_methods, $artifact_methods ) )
		&& 0 === preg_match( '/["\']\s*(?:UPDATE|DELETE)\b/i', $production_text )
		&& 0 === preg_match( '/\b(?:file_put_contents|fopen|fwrite|unlink|rename|copy|curl_|wp_remote_)\s*\(/i', $production_text ),
	'SIDE-NO-UPDATE-DELETE-SURFACE repositories expose insert/read only and perform no filesystem or network operation'
);

fwrite( STDOUT, 'DB_TARGET=' . $wpdb->get_var( 'SELECT VERSION()' ) . '|' . $wpdb->get_var( 'SELECT @@version_comment' ) . "\n" );
archive_finish();
