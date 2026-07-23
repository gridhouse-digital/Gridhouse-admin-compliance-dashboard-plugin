<?php
require_once __DIR__ . '/bootstrap.php';

$payload = array(
	'archive_id' => str_repeat( '3', 32 ),
	'build_attempt_id' => str_repeat( '5', 32 ),
	'canonical_format_version' => 'ghca-cjson-1',
	'ledger_artifact_id' => str_repeat( '6', 32 ),
	'snapshot_id' => str_repeat( '4', 32 ),
	'stream_id' => str_repeat( '2', 32 ),
	'task_schema_version' => 1,
	'task_type' => 'materialize_ledger',
	'trigger_event_id' => str_repeat( '9', 32 ),
);
$task = array(
	'archive_id' => $payload['archive_id'],
	'build_attempt_id' => $payload['build_attempt_id'],
	'payload' => $payload,
	'reset_operation_id' => null,
	'stream_id' => $payload['stream_id'],
	'task_id' => str_repeat( 'a', 32 ),
	'task_schema_version' => 1,
	'task_type' => 'materialize_ledger',
	'trigger_event_id' => $payload['trigger_event_id'],
);
$snapshot_document = array(
	'case' => array(
		'archive_id' => $payload['archive_id'],
		'cycle_key' => '2026',
		'program_key' => 'annual_training',
		'snapshot_id' => $payload['snapshot_id'],
		'site_id' => '1',
		'stream_id' => $payload['stream_id'],
		'tenant_id' => str_repeat( '1', 32 ),
	),
	'courses' => array(
		array(
			'certificate_artifact_id' => str_repeat( '8', 32 ),
			'completed_at_gmt' => '2026-02-03T04:05:06Z',
			'completion_status' => 'completed',
			'course_id' => '42',
			'course_stable_key' => null,
			'course_title' => 'Safe & Ready',
			'quiz_score_basis_points' => 9875,
			'started_at_gmt' => '2026-01-02T03:04:05Z',
			'time_spent_seconds' => '3600',
		),
		array(
			'certificate_artifact_id' => null,
			'completed_at_gmt' => null,
			'completion_status' => 'in_progress',
			'course_id' => '9007199254740993',
			'course_stable_key' => 'course-9007199254740993',
			'course_title' => 'Prevention "A"',
			'quiz_score_basis_points' => null,
			'started_at_gmt' => '2026-03-04T05:06:07Z',
			'time_spent_seconds' => '0',
		),
	),
	'cycle' => array( 'key' => '2026' ),
	'subject' => array( 'employee_user_id' => '18446744073709551615' ),
);
$snapshot = array(
	'archive_id' => $payload['archive_id'],
	'snapshot_digest' => str_repeat( '7', 64 ),
	'snapshot_document' => $snapshot_document,
	'snapshot_id' => $payload['snapshot_id'],
	'stream_id' => $payload['stream_id'],
);

$expected_bytes = <<<'JSON'
{"archive_id":"33333333333333333333333333333333","build_attempt_id":"55555555555555555555555555555555","canonical_format":"ghca-cjson-1","item_count":2,"item_digests":["99f9e9253959df303166e0123a5bfc07b18aed53f0d1db4440b0ca857247ddc3","3f8c25d42c28c18acdca5cdb18f4a2a63dd475fa82e4184c01d2a5580dde22b1"],"items":[{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":"88888888888888888888888888888888","completed_at_gmt":"2026-02-03T04:05:06Z","completion_status":"completed","course_id":"42","course_stable_key":null,"course_title":"Safe & Ready","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":0,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":9875,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-01-02T03:04:05Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"3600"},{"archive_id":"33333333333333333333333333333333","certificate_artifact_id":null,"completed_at_gmt":null,"completion_status":"in_progress","course_id":"9007199254740993","course_stable_key":"course-9007199254740993","course_title":"Prevention \"A\"","cycle_key":"2026","employee_user_id":"18446744073709551615","item_ordinal":1,"item_schema_version":1,"ledger_artifact_id":"66666666666666666666666666666666","program_key":"annual_training","quiz_score_basis_points":null,"snapshot_id":"44444444444444444444444444444444","started_at_gmt":"2026-03-04T05:06:07Z","stream_id":"22222222222222222222222222222222","time_spent_seconds":"0"}],"ledger_artifact_id":"66666666666666666666666666666666","manifest_digest":"a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45","schema_version":1,"snapshot_digest":"7777777777777777777777777777777777777777777777777777777777777777","snapshot_id":"44444444444444444444444444444444","stream_id":"22222222222222222222222222222222"}
JSON;
$expected_descriptor = <<<'JSON'
{"artifact_id":"66666666666666666666666666666666","artifact_kind":"ledger","artifact_schema_version":1,"byte_count":1928,"content_digest":"3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e","content_digest_algorithm":"sha256","filename":"archive-ledger.json","media_type":"application/json","producer_key":"ghca_archive_ledger_materializer","producer_version":"1.0.0","role_key":"ledger","storage_adapter":"private_local","storage_key":"committed/11111111111111111111111111111111/22222222222222222222222222222222/33333333333333333333333333333333/66666666666666666666666666666666.json"}
JSON;
$expected_event = <<<'JSON'
{"archive_id":"33333333333333333333333333333333","build_attempt_id":"55555555555555555555555555555555","content_digest":"3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e","item_count":2,"ledger_artifact_id":"66666666666666666666666666666666","manifest_digest":"a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45","snapshot_digest":"7777777777777777777777777777777777777777777777777777777777777777","snapshot_id":"44444444444444444444444444444444"}
JSON;

$materialized = ( new GHCA_ACD_Archive_Ledger_Materializer() )->materialize( $task, $snapshot );
$item_digests = array_map( static function ( array $item ): string { return GHCA_ACD_Archive_Digester::item( $item ); }, $materialized['ledger_items'] );
$event_payload = array(
	'archive_id' => $payload['archive_id'],
	'build_attempt_id' => $payload['build_attempt_id'],
	'content_digest' => $materialized['artifact_descriptor']['content_digest'],
	'item_count' => count( $materialized['ledger_items'] ),
	'ledger_artifact_id' => $payload['ledger_artifact_id'],
	'manifest_digest' => GHCA_ACD_Archive_Digester::ledger_manifest( $item_digests ),
	'snapshot_digest' => $snapshot['snapshot_digest'],
	'snapshot_id' => $payload['snapshot_id'],
);
$runtime_name = 8 === PHP_MAJOR_VERSION && 3 === PHP_MINOR_VERSION ? 'PHP83' : ( 8 === PHP_MAJOR_VERSION && 5 === PHP_MINOR_VERSION ? 'PHP85' : 'UNSUPPORTED' );
archive_check( 'UNSUPPORTED' !== $runtime_name, 'P3B1-LEDGER-GOLDEN-RUNTIME requires exact PHP 8.3 or PHP 8.5' );
archive_check( 1928 === strlen( $expected_bytes ) && $expected_bytes === $materialized['ledger_bytes'], 'P3B1-LEDGER-DOCUMENT-V1-LITERAL-GOLDENS-' . $runtime_name . ' matches the independent 1,928-byte ledger literal' );
archive_check( array( '99f9e9253959df303166e0123a5bfc07b18aed53f0d1db4440b0ca857247ddc3', '3f8c25d42c28c18acdca5cdb18f4a2a63dd475fa82e4184c01d2a5580dde22b1' ) === $item_digests, 'P3B1-LEDGER-ITEM-DIGEST-LITERAL-GOLDENS-' . $runtime_name );
archive_check( 'a5a434976c4ebff6c13509eb0ceef371521a45105bebf95dd1f41950930f5b45' === $event_payload['manifest_digest'], 'P3B1-LEDGER-MANIFEST-LITERAL-GOLDEN-' . $runtime_name );
archive_check( '3a048a719bd3f3a9642b93f1abbe7b39388c4199ab26824c3344c040887c424e' === hash( 'sha256', $materialized['ledger_bytes'] ), 'P3B1-LEDGER-CONTENT-DIGEST-LITERAL-GOLDEN-' . $runtime_name );
archive_check( $expected_descriptor === GHCA_ACD_Archive_Canonical_JSON::encode( $materialized['artifact_descriptor'] ), 'P3B1-LEDGER-DESCRIPTOR-LITERAL-GOLDEN-' . $runtime_name );
archive_check( $expected_event === GHCA_ACD_Archive_Canonical_JSON::encode( $event_payload ), 'P3B1-LEDGER-EVENT-PAYLOAD-LITERAL-GOLDEN-' . $runtime_name );

archive_finish();
