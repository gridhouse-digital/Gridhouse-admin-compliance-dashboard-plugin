<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';

final class GHCA_P3B2A_Fake_Evidence_Source implements GHCA_ACD_Archive_Evidence_Source {
	/** @var array<string,mixed> */
	private $document;
	/** @var Throwable|null */
	private $failure;
	/** @var int */
	public $calls = 0;

	/** @param array<string,mixed> $document */
	public function __construct( array $document, ?Throwable $failure = null ) {
		$this->document = $document;
		$this->failure = $failure;
	}

	public function read_consistent_evidence( array $capture_identity, array $limits, callable $checkpoint ): array {
		$this->calls++;
		if ( null !== $this->failure ) { throw $this->failure; }
		return GHCA_ACD_Archive_Canonical_JSON::detach( $this->document );
	}
}

final class GHCA_P3B2A_Sequence_Evidence_Source implements GHCA_ACD_Archive_Evidence_Source {
	/** @var array<int,mixed> */
	private $outcomes;
	/** @var int */
	public $calls = 0;

	/** @param array<int,mixed> $outcomes */
	public function __construct( array $outcomes ) { $this->outcomes = $outcomes; }

	public function read_consistent_evidence( array $capture_identity, array $limits, callable $checkpoint ): array {
		$outcome = $this->outcomes[ $this->calls ] ?? end( $this->outcomes );
		$this->calls++;
		if ( $outcome instanceof Throwable ) { throw $outcome; }
		return GHCA_ACD_Archive_Canonical_JSON::detach( $outcome );
	}
}

/** @param mixed $value */
function p3b2ap_value_count( $value ): int {
	if ( $value instanceof GHCA_ACD_Archive_Empty_Object ) { return 1; }
	if ( $value instanceof GHCA_ACD_Archive_Canonical_Object ) {
		$count = 1;
		foreach ( $value->members() as $member ) { $count += p3b2ap_value_count( $member[1] ); }
		return $count;
	}
	if ( ! is_array( $value ) ) { return 1; }
	$count = 1;
	foreach ( $value as $item ) { $count += p3b2ap_value_count( $item ); }
	return $count;
}

/** @return array<int,string> */
function p3b2ap_sized_machine_keys( int $count, int $total_string_bytes ): array {
	if ( $count < 1 || $total_string_bytes < 7 * $count || $total_string_bytes > 191 * $count ) {
		throw new InvalidArgumentException( 'The test key budget is invalid.' );
	}
	$base = intdiv( $total_string_bytes, $count );
	$extra = $total_string_bytes % $count;
	$keys = array();
	for ( $index = 0; $index < $count; $index++ ) {
		$length = $base + ( $index < $extra ? 1 : 0 );
		$prefix = 'w' . str_pad( (string) $index, 5, '0', STR_PAD_LEFT ) . '_';
		$keys[] = $prefix . str_repeat( 'a', $length - strlen( $prefix ) );
	}
	return $keys;
}

/** @return array<string,mixed> */
function p3b2ap_document( GHCA_Persist_Scenario $scenario, string $policy_digest, bool $certificate = false ): array {
	$cycle = remediation_cycle();
	$audit = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '101', array(
			'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60',
			'is_orientation' => false, 'odp_category_key' => 'annual', 'oltl_category_key' => 'general',
		) ),
	) );
	$lifespans = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
	) );
	return array(
		'calculated' => array(
			'calculation_version' => 1,
			'categories' => array( 'annual' => array( 'completed_course_ids' => array( '101' ), 'credit_minutes' => '60' ) ),
			'compliance_status' => 'compliant',
			'exceptions' => array(),
			'matrix' => array( 'odp' => array( 'annual' => true ), 'oltl' => array( 'general' => true ) ),
			'total_course_count' => 1,
			'total_training_seconds' => '3600',
		),
		'canonical_format' => 'ghca-cjson-1',
		'case' => array(
			'cycle_key' => $scenario->case_canonical['cycle_key'],
			'employee_user_id' => $scenario->case_canonical['employee_user_id_decimal'],
			'program_key' => $scenario->program,
			'site_id' => $scenario->case_canonical['site_id_decimal'],
			'tenant_id' => $scenario->case_canonical['tenant_id'],
		),
		'completeness' => array(
			'missing_fields' => array(), 'observed_count' => 1, 'policy_code' => 'snapshot_v1_complete',
			'policy_version' => 1, 'required_count' => 1, 'result' => 'complete', 'warnings' => array(),
		),
		'courses' => array( array(
			'category_order' => 0,
			'certificate_reference' => $certificate ? array( 'certificate_post_id' => '44', 'source_record_version' => '1' ) : null,
			'certificate_required' => $certificate,
			'completed_at_gmt' => '2026-07-13T15:00:00Z',
			'completion_status' => 'completed',
			'course_id' => '101',
			'course_order' => 0,
			'course_stable_key' => 'course-101',
			'course_title' => 'Fixture Course 101',
			'enrollment_status' => 'enrolled',
			'pass_state' => 'passed',
			'quiz_attempts' => array( array(
				'attempt_ordinal' => 0, 'attempted_at_gmt' => '2026-07-13T14:30:00Z',
				'passed' => true, 'score_basis_points' => 9000,
			) ),
			'quiz_score_basis_points' => 9000,
			'source_provenance' => array( 'adapter_key' => 'learndash-local', 'record_id' => '7001', 'record_version' => '1' ),
			'started_at_gmt' => '2026-07-13T14:00:00Z',
			'time_spent_seconds' => '3600',
		) ),
		'cycle' => $cycle,
		'organization' => array(
			'agency_name' => 'Fixture Agency', 'site_name' => 'Fixture Site',
			'tenant_id' => $scenario->case_canonical['tenant_id'],
		),
		'policy' => array(
			'audit_mapping' => $audit,
			'completeness_policy' => 'snapshot_v1_complete',
			'course_lifespan_rules' => $lifespans,
			'policy_digest' => $policy_digest,
			'quiz_policy' => array( 'attempt_selection' => 'latest_completed', 'required' => true, 'score_scale' => 'basis_points' ),
			'relevant_settings' => array( 'annual_cycle' => 'calendar_year', 'new_hire_deadline_days' => '30', 'warning_days' => '90' ),
			'tracked_course_ids' => array( '101' ),
		),
		'schema_version' => 1,
		'source' => array(
			'learndash_version' => '5.0.0', 'plugin_version' => '1.0.0',
			'source_adapter_key' => 'learndash-local', 'source_adapter_version' => '1.0.0',
			'source_record_ids' => array(
				'course_activity_ids' => array( '7001' ), 'course_post_ids' => array( '101' ),
				'group_ids' => array( '9' ), 'quiz_activity_ids' => array( '8001' ),
				'user_id' => $scenario->case_canonical['employee_user_id_decimal'],
			),
			'wordpress_version' => '6.8.0',
		),
		'subject' => array(
			'display_name' => 'Fixture Employee', 'email' => 'fixture@example.test',
			'employee_user_id' => $scenario->case_canonical['employee_user_id_decimal'],
			'external_employee_key' => null, 'group_ids' => array( '9' ),
			'registered_at_gmt' => '2025-01-02T03:04:05Z', 'role_keys' => array( 'subscriber' ),
		),
	);
}

/** @return array<string,mixed> */
function p3b2ap_multi_course_document( GHCA_Persist_Scenario $scenario, string $policy_digest ): array {
	$document = p3b2ap_document( $scenario, $policy_digest );
	$second = $document['courses'][0];
	$second['course_id'] = '2';
	$second['course_order'] = 1;
	$second['course_stable_key'] = 'course-2';
	$second['course_title'] = 'Fixture Course 2';
	$second['source_provenance']['record_id'] = '7002';
	$document['courses'][] = $second;
	$document['policy']['tracked_course_ids'] = array( '2', '101' );
	$document['policy']['audit_mapping'] = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array(
			'category_order' => 0, 'course_order' => 1, 'credit_minutes' => '30',
			'is_orientation' => false, 'odp_category_key' => 'annual', 'oltl_category_key' => 'general',
		) ),
		array( '101', array(
			'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60',
			'is_orientation' => false, 'odp_category_key' => 'annual', 'oltl_category_key' => 'general',
		) ),
	) );
	$document['policy']['course_lifespan_rules'] = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
		array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
	) );
	$document['source']['source_record_ids']['course_activity_ids'] = array( '7001', '7002' );
	$document['source']['source_record_ids']['course_post_ids'] = array( '2', '101' );
	$document['calculated']['categories']['annual']['completed_course_ids'] = array( '2', '101' );
	$document['calculated']['categories']['annual']['credit_minutes'] = '90';
	$document['calculated']['total_course_count'] = 2;
	$document['calculated']['total_training_seconds'] = '7200';
	$document['completeness']['observed_count'] = 2;
	$document['completeness']['required_count'] = 2;
	return $document;
}

/** @return array<string,mixed> */
function p3b2ap_fixture( $db, string $seed, string $now, bool $drift = false, bool $certificate = false, bool $multi_course = false ): array {
	ghca_persist_fresh_schema( $db );
	$stack = ghca_persist_stack( $db, $now, 'p3b2a-' . $seed );
	$scenario = new GHCA_Persist_Scenario( 'p3b2a_' . $seed );
	$policy_digest = remediation_digest( '2' );
	$document = $multi_course
		? p3b2ap_multi_course_document( $scenario, $policy_digest )
		: p3b2ap_document( $scenario, $policy_digest, $certificate );
	$fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $document );
	$request = $scenario->payload( 'ArchiveRequested', array(
		'archive_id' => $scenario->id( 'archive-1' ),
		'policy_digest' => $policy_digest,
		'reviewed_source_fingerprint' => $drift ? remediation_digest( '9' ) : $fingerprint,
	) );
	persist_single( $stack, $scenario, 'RequestArchive', 'request_archive', $request );
	$table = $db->prefix . 'ghca_acd_archive_tasks';
	$task = $db->get_row( $db->prepare(
		"SELECT * FROM {$table} WHERE stream_id = %s AND task_type = 'capture_evidence'",
		$scenario->stream_id
	), ARRAY_A );
	$source = new GHCA_P3B2A_Fake_Evidence_Source( $document );
	$handler = new GHCA_ACD_Archive_Evidence_Task_Handler(
		$source,
		new GHCA_ACD_Archive_Evidence_Result_Validator(),
		new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(),
		$stack['clock']
	);
	$build = new GHCA_ACD_Archive_Build_Coordinator(
		$stack['event_store'], $stack['snapshot_store'], $stack['artifact_repository'], $stack['uow']
	);
	return compact( 'stack', 'scenario', 'document', 'fingerprint', 'task', 'source', 'handler', 'build' );
}

/** @param array<string,mixed> $fixture */
function p3b2ap_worker( array $fixture, string $seed ): GHCA_ACD_Archive_Worker_Coordinator {
	return new GHCA_ACD_Archive_Worker_Coordinator(
		$fixture['stack']['task_store'],
		$fixture['stack']['clock'],
		new GHCA_Persist_Sequential_Ids( 'p3b2a-worker-' . $seed ),
		substr( hash( 'sha256', 'p3b2a-owner-' . $seed ), 0, 32 ),
		array( 'capture_evidence' => $fixture['handler'] ),
		null,
		$fixture['build']
	);
}

function p3b2ap_event_count( $db, string $stream_id, string $type ): int {
	$table = $db->prefix . 'ghca_acd_archive_events';
	return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$table} WHERE stream_id = %s AND event_type = %s", $stream_id, $type ) );
}

// Successful fake-backed capture uses the existing fenced UoW and immutable snapshot store.
$fixture = p3b2ap_fixture( $wpdb, 'success', '2026-07-26T15:00:00Z' );
$result = p3b2ap_worker( $fixture, 'success' )->run_once();
$task_after = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
$snapshot_id = substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|Snapshot|' . $fixture['task']['task_id'] ), 0, 32 );
$snapshot = $fixture['stack']['snapshot_store']->find( $snapshot_id );
archive_check(
	'completed' === $result['status'] && 'completed' === $task_after['task_state'] && 1 === $fixture['source']->calls
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveBuildStarted' )
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-SNAPSHOT-BINDING-EXACT commits one fenced build and one immutable snapshot before task completion'
);
archive_check(
	null !== $snapshot && $snapshot_id === $snapshot['snapshot_id']
	&& $fixture['fingerprint'] === $snapshot['captured_source_fingerprint']
	&& $fixture['fingerprint'] === $snapshot['reviewed_source_fingerprint']
	&& GHCA_ACD_Archive_Canonical_JSON::encode( $snapshot['snapshot_document'] ) === $snapshot['snapshot_json']
	&& GHCA_ACD_Archive_Digester::snapshot( $snapshot['snapshot_document'] ) === $snapshot['snapshot_digest'],
	'P3B2A-E10A-SNAPSHOT-DIGEST-JSON-UNCHANGED retains exact request, digest, and canonical JSON bytes'
);
archive_check(
	$snapshot['snapshot_document']['policy']['audit_mapping'] instanceof GHCA_ACD_Archive_Canonical_Object
	&& $snapshot['snapshot_document']['policy']['course_lifespan_rules'] instanceof GHCA_ACD_Archive_Canonical_Object
	&& false !== strpos( $snapshot['snapshot_json'], '"audit_mapping":{"101":' )
	&& false !== strpos( $snapshot['snapshot_json'], '"course_lifespan_rules":{"101":' ),
	'P3B2A-E10A-NUMERIC-COURSE-KEY-ROUNDTRIP preserves decimal key 101 through insert and database reload'
);
archive_check(
	'Fixture Employee' === $snapshot['snapshot_document']['subject']['display_name']
	&& 'fixture@example.test' === $snapshot['snapshot_document']['subject']['email']
	&& false === strpos( $fixture['task']['payload_json'], 'Fixture Employee' )
	&& false === strpos( $fixture['task']['payload_json'], 'fixture@example.test' )
	&& null === $task_after['last_error_text'],
	'P3B2A-PII-PLACEMENT-CLOSED keeps approved names and email in the snapshot but out of tasks and operational errors'
);
$object_predicate = new ReflectionMethod( GHCA_ACD_WPDB_Archive_Snapshot_Store::class, 'is_object_document' );
archive_check(
	true === $object_predicate->invoke( $fixture['stack']['snapshot_store'], $snapshot['snapshot_document']['policy']['audit_mapping'] )
	&& false === $object_predicate->invoke( $fixture['stack']['snapshot_store'], new stdClass() ),
	'P3B2A-E10A-ARBITRARY-PHP-OBJECT-REJECTED accepts only canonical object representations'
);

$multi_fixture = p3b2ap_fixture( $wpdb, 'multi_order', '2026-07-26T15:30:00Z', false, false, true );
$multi_result = p3b2ap_worker( $multi_fixture, 'multi-order' )->run_once();
$multi_snapshot_id = substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|Snapshot|' . $multi_fixture['task']['task_id'] ), 0, 32 );
$multi_snapshot = $multi_fixture['stack']['snapshot_store']->find( $multi_snapshot_id );
archive_check(
	'completed' === $multi_result['status']
		&& array( '2', '101' ) === $multi_snapshot['snapshot_document']['policy']['tracked_course_ids']
		&& array( '101', '2' ) === array_column( $multi_snapshot['snapshot_document']['courses'], 'course_id' )
		&& GHCA_ACD_Archive_Canonical_JSON::encode( $multi_snapshot['snapshot_document'] ) === $multi_snapshot['snapshot_json']
		&& GHCA_ACD_Archive_Digester::snapshot( $multi_snapshot['snapshot_document'] ) === $multi_snapshot['snapshot_digest'],
	'P3B2A-NUMERIC-MEMBERSHIP-SET-PERSISTS-WITH-DISPLAY-ORDER preserves immutable bytes through database reload'
);

// Crash after snapshot command commit but before task completion replays without another source call.
$fixture = p3b2ap_fixture( $wpdb, 'response_loss', '2026-07-26T16:00:00Z' );
$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
	GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
), 'prepare response-loss attempt-five task' );
$owner = substr( hash( 'sha256', 'p3b2a-response-owner' ), 0, 32 );
$token = substr( hash( 'sha256', 'p3b2a-response-token' ), 0, 32 );
$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-26T16:00:00Z', array( 'capture_evidence' ) );
$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
	$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-26T16:00:00Z' )
);
$key = GHCA_ACD_Archive_Digester::task_outcome( array( 'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1 ) );
$fence = array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token );
$context = $fixture['build']->start_capture( $claimed, $key, $fence );
$prepared = $fixture['handler']->prepare( $claimed, $context, static function (): void {} );
$prepared = $fixture['handler']->validate_prepared_result( $context, $prepared );
$first_response = $fixture['build']->record_capture( $claimed, $prepared, $key, $fence );
$contradictory = $prepared;
$contradictory['snapshot_document']['courses'][0]['course_title'] = 'Contradictory Course';
$contradictory['snapshot_digest'] = GHCA_ACD_Archive_Digester::snapshot( $contradictory['snapshot_document'] );
$contradictory['byte_count'] = strlen( GHCA_ACD_Archive_Canonical_JSON::encode( $contradictory['snapshot_document'] ) );
$contradiction_blocked = false;
try {
	$fixture['build']->record_capture( $claimed, $contradictory, $key, $fence );
} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
	$contradiction_blocked = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
		&& 'archive_immutable_conflict' === $error->reason_code();
}
archive_check(
	$contradiction_blocked
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-SNAPSHOT-CONTRADICTION-BLOCKED rejects contradictory retained bytes without replacement or duplicate'
);
$fixture['stack']['clock']->set( '2026-07-26T16:02:00Z' );
$replay_source = new GHCA_P3B2A_Fake_Evidence_Source( $fixture['document'], new GHCA_ACD_Archive_Evidence_Source_Exception(
	GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
	'archive_source_schema_unsupported',
	'source_preflight'
) );
$fixture['source'] = $replay_source;
$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
	$replay_source, new GHCA_ACD_Archive_Evidence_Result_Validator(), new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
);
$replayed = p3b2ap_worker( $fixture, 'response-replay' )->run_once();
archive_check(
	'completed' === $replayed['status'] && $first_response === $replayed['response'] && 0 === $replay_source->calls
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B2A-ATTEMPT5-CRASH-AFTER-SNAPSHOT-COMMIT replays the stored response before repeating source work'
);
archive_check(
	'completed' === $fixture['stack']['task_store']->find( $claimed['task_id'] )['task_state']
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-E10A-DUPLICATE-REPLAY-IDEMPOTENT completes the retained task without a duplicate event or snapshot'
);
$ledger_owner = substr( hash( 'sha256', 'p3b2a-later-ledger-owner' ), 0, 32 );
$ledger_token = substr( hash( 'sha256', 'p3b2a-later-ledger-token' ), 0, 32 );
$ledger_task = $fixture['stack']['task_store']->claim_available(
	$ledger_owner, $ledger_token, '2026-07-26T16:02:00Z', array( 'materialize_ledger' )
);
$ledger_task = $fixture['stack']['task_store']->validate_claimed_v1(
	$fixture['stack']['task_store']->load_claimed(
		$ledger_task['task_id'], $ledger_owner, $ledger_token, '2026-07-26T16:02:00Z'
	)
);
$fixture['build']->fail_archive(
	$ledger_task,
	'archive_ledger_invalid',
	hash( 'sha256', 'p3b2a-later-ledger-failure' ),
	array( 'task_id' => $ledger_task['task_id'], 'lease_owner' => $ledger_owner, 'lease_token' => $ledger_token )
);
$capture_after_later_failure = $fixture['build']->recover_capture( $claimed );
archive_check(
	'captured' === $capture_after_later_failure['decision']
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' )
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B2A-CAPTURE-SUCCESS-WINS-LATER-PHASE-FAILURE replays capture success despite a later materialization failure'
);
archive_check(
	0 === $replay_source->calls && 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-RESPONSE-LOSS-REPLAY returns authoritative success without duplicate work'
);
archive_check(
	GHCA_ACD_Archive_Canonical_JSON::encode( $prepared['snapshot_document'] ) === GHCA_ACD_Archive_Canonical_JSON::encode(
		$fixture['stack']['snapshot_store']->find(
			substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|Snapshot|' . $claimed['task_id'] ), 0, 32 )
		)['snapshot_document']
	),
	'P3B2A-SNAPSHOT-REPLAY-BYTE-IDENTICAL preserves audit mappings, lifespan rules, and exact committed bytes'
);

// Fingerprint mismatch emits the exact existing atomic drift/failure decision and no snapshot.
$fixture = p3b2ap_fixture( $wpdb, 'drift', '2026-07-26T17:00:00Z', true );
$drift = p3b2ap_worker( $fixture, 'drift' )->run_once();
$snapshot_id = substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|Snapshot|' . $fixture['task']['task_id'] ), 0, 32 );
archive_check(
	'dead' === $drift['status'] && 'archive_source_drift' === $drift['reason_code']
	&& null === $fixture['stack']['snapshot_store']->find( $snapshot_id )
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'SourceDriftDetected' )
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B2A-SOURCE-DRIFT-BEFORE-CAPTURE records exact drift atomically with zero snapshot residue'
);

// Required certificates fail closed before snapshot UoW submission.
$fixture = p3b2ap_fixture( $wpdb, 'certificate', '2026-07-26T18:00:00Z', false, true );
$certificate = p3b2ap_worker( $fixture, 'certificate' )->run_once();
$snapshot_id = substr( hash( 'sha256', 'ghca-p3b2a-capture-id-v1|Snapshot|' . $fixture['task']['task_id'] ), 0, 32 );
archive_check(
	'dead' === $certificate['status'] && 'archive_certificate_invalid' === $certificate['reason_code']
	&& null === $fixture['stack']['snapshot_store']->find( $snapshot_id )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-CERTIFICATE-ACQUISITION-DEFERRED fails closed before snapshot submission with no artifact work'
);

// Attempt-five operational-blocked failure dead-letters operationally without a lifecycle fact.
$fixture = p3b2ap_fixture( $wpdb, 'operational', '2026-07-26T19:00:00Z' );
$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
	GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
), 'prepare operational attempt-five task' );
$failure_source = new GHCA_P3B2A_Fake_Evidence_Source( $fixture['document'], new GHCA_ACD_Archive_Evidence_Source_Exception(
	GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
	'archive_source_schema_unsupported',
	'source_preflight'
) );
$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
	$failure_source, new GHCA_ACD_Archive_Evidence_Result_Validator(), new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
);
$operational = p3b2ap_worker( $fixture, 'operational' )->run_once();
archive_check(
	'dead' === $operational['status'] && 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-OPERATIONAL-FAILURES-NO-LIFECYCLE dead-letters unsupported source schema without inventing ArchiveFailed'
);

// Prepared-result validation and the independent snapshot limits run before any UoW submission.
$fixture = p3b2ap_fixture( $wpdb, 'prepared_limits', '2026-07-26T19:15:00Z' );
$fixture['task']['payload'] = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $fixture['task']['payload_json'] );
$context = $fixture['build']->capture_context( $fixture['task'] );
$commands_table = $wpdb->prefix . 'ghca_acd_archive_commands';
$snapshots_table = $wpdb->prefix . 'ghca_acd_archive_snapshots';
$commands_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commands_table}" );
$snapshots_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshots_table}" );
$invalid_prepared = false;
try {
	$fixture['handler']->validate_prepared_result( $context, array(
		'byte_count' => 1,
		'captured_source_fingerprint' => $fixture['fingerprint'],
		'decision' => 'snapshot',
		'snapshot_digest' => str_repeat( '0', 64 ),
		'snapshot_document' => array(),
	) );
} catch ( UnexpectedValueException $error ) {
	$invalid_prepared = 'The prepared evidence result is invalid.' === $error->getMessage();
}
archive_check(
	$invalid_prepared && $commands_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commands_table}" )
	&& $snapshots_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshots_table}" )
	&& 0 === $fixture['source']->calls,
	'P3B2A-INVALID-PREPARED-BEFORE-UOW rejects the exact invalid result with zero source, command, or snapshot residue'
);

$validator = new GHCA_ACD_Archive_Evidence_Result_Validator();
$preparer = new GHCA_ACD_Archive_Evidence_Snapshot_Preparer();
$base_document = $validator->validate( $fixture['document'], $context['capture_identity'] );
$snapshot_value_document = GHCA_ACD_Archive_Canonical_JSON::detach( $base_document );
$snapshot_value_document['completeness']['warnings'] = p3b2ap_sized_machine_keys(
	GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES - p3b2ap_value_count( $base_document ),
	7 * ( GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES - p3b2ap_value_count( $base_document ) )
);
$snapshot_value_document = $validator->validate( $snapshot_value_document, $context['capture_identity'] );
$snapshot_value_rejected = false;
try {
	$preparer->prepare(
		$snapshot_value_document,
		$context,
		GHCA_ACD_Archive_Digester::source_fingerprint( $snapshot_value_document ),
		'2026-07-26T19:15:00Z'
	);
} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
	$snapshot_value_rejected = GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID === $error->category()
		&& 'archive_evidence_incomplete' === $error->reason_code()
		&& 'snapshot_byte_limit' === $error->operation_context()
		&& GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES['archive_evidence_incomplete'] === $error->getMessage();
}
archive_check(
	GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES === p3b2ap_value_count( $snapshot_value_document )
	&& $snapshot_value_rejected
	&& $commands_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commands_table}" )
	&& $snapshots_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshots_table}" ),
	'P3B2A-SNAPSHOT-VALUE-LIMIT-INDEPENDENT rejects snapshot value 10,001 without sharing the accepted E07 counter'
);

$small_prepared = $preparer->prepare( $base_document, $context, $fixture['fingerprint'], '2026-07-26T19:15:00Z' );
$target_bytes = GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES + 1;
$snapshot_base_bytes = strlen( GHCA_ACD_Archive_Canonical_JSON::encode( $small_prepared['snapshot_document'] ) );
$byte_delta = $target_bytes - $snapshot_base_bytes;
$warning_count = (int) ceil( ( $byte_delta + 1 ) / 194 );
$warning_string_bytes = $byte_delta - ( 3 * $warning_count - 1 );
$byte_warnings = p3b2ap_sized_machine_keys( $warning_count, $warning_string_bytes );
$byte_document = GHCA_ACD_Archive_Canonical_JSON::detach( $base_document );
$byte_document['completeness']['warnings'] = $byte_warnings;
$byte_document = $validator->validate( $byte_document, $context['capture_identity'] );
$projected_snapshot = GHCA_ACD_Archive_Canonical_JSON::detach( $small_prepared['snapshot_document'] );
$projected_snapshot['completeness']['warnings'] = $byte_warnings;
$projected_bytes = strlen( GHCA_ACD_Archive_Canonical_JSON::encode_bounded(
	$projected_snapshot,
	GHCA_ACD_Archive_Canonical_JSON::MAX_BOUNDED_BYTES
) );
$snapshot_byte_rejected = false;
try {
	$preparer->prepare(
		$byte_document,
		$context,
		GHCA_ACD_Archive_Digester::source_fingerprint( $byte_document ),
		'2026-07-26T19:15:00Z'
	);
} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
	$snapshot_byte_rejected = GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID === $error->category()
		&& 'archive_evidence_incomplete' === $error->reason_code()
		&& 'snapshot_byte_limit' === $error->operation_context()
		&& GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES['archive_evidence_incomplete'] === $error->getMessage();
}
archive_check(
	$target_bytes === $projected_bytes
	&& strlen( GHCA_ACD_Archive_Canonical_JSON::encode( $byte_document ) ) <= GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES
	&& $snapshot_byte_rejected
	&& $commands_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$commands_table}" )
	&& $snapshots_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$snapshots_table}" ),
	'P3B2A-BYTE-LIMIT-NO-TRUNCATION rejects the 1,048,577th snapshot byte with zero command or snapshot residue'
);

// Every operational or unknown attempt-five failure remains operational only.
$operational_cases = array(
	'rollback' => new GHCA_ACD_Archive_Evidence_Source_Exception(
		GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
		'archive_source_transaction_failed',
		'transaction_rollback'
	),
	'close' => new GHCA_ACD_Archive_Evidence_Source_Exception(
		GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
		'archive_source_transaction_failed',
		'connection_close'
	),
	'unsupported' => new GHCA_ACD_Archive_Evidence_Source_Exception(
		GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
		'archive_source_schema_unsupported',
		'source_preflight'
	),
	'unclassified' => new GHCA_ACD_Archive_Evidence_Source_Exception(
		GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
		'task_handler_failed',
		'source_query'
	),
	'unknown' => new RuntimeException( 'secret unknown source failure' ),
);
$operational_cases_passed = true;
foreach ( $operational_cases as $case_name => $failure ) {
	$fixture = p3b2ap_fixture( $wpdb, 'operational_' . $case_name, '2026-07-26T19:20:00Z' );
	$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
	ghca_persist_query( $wpdb, $wpdb->prepare(
		"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
		GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
	), 'prepare operational attempt-five case' );
	$source = new GHCA_P3B2A_Fake_Evidence_Source( $fixture['document'], $failure );
	$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
		$source, new GHCA_ACD_Archive_Evidence_Result_Validator(), new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
	);
	$result = p3b2ap_worker( $fixture, 'operational-' . $case_name )->run_once();
	$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
	$case_passed = 'dead' === $result['status'] && 'task_attempts_exhausted' === $result['reason_code']
		&& 'dead' === $row['task_state'] && 'task_attempts_exhausted' === $row['last_error_code']
		&& GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_attempts_exhausted'] === $row['last_error_text']
		&& false === strpos( $row['last_error_text'], 'secret' )
		&& 1 === $source->calls
		&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
		&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' );
	$operational_cases_passed = $operational_cases_passed && $case_passed;
	archive_check(
		$case_passed,
		'P3B2A-OPERATIONAL-' . strtoupper( $case_name ) . '-NO-LIFECYCLE dead-letters with one sanitized operational code'
	);
}
archive_check(
	$operational_cases_passed,
	'P3B2A-OPERATIONAL-FAILURES-NO-LIFECYCLE covers attempt-five rollback, close, unsupported, unclassified, and unknown failures'
);

// Attempt five performs one deterministic retryable recovery before exhaustion.
$fixture = p3b2ap_fixture( $wpdb, 'attempt5_recovery', '2026-07-26T19:30:00Z' );
$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
	GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
), 'prepare recoverable attempt-five task' );
$retryable = new GHCA_ACD_Archive_Evidence_Source_Exception(
	GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
	'archive_source_query_failed',
	'source_query'
);
$recovering_source = new GHCA_P3B2A_Sequence_Evidence_Source( array( $retryable, $fixture['document'] ) );
$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
	$recovering_source, new GHCA_ACD_Archive_Evidence_Result_Validator(), new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
);
$recovered = p3b2ap_worker( $fixture, 'attempt5-recovery' )->run_once();
archive_check(
	'completed' === $recovered['status'] && 2 === $recovering_source->calls
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B2A-ATTEMPT5-DETERMINISTIC-RECOVERY completes from the validated second read without inventing ArchiveFailed'
);

$transition_cases = array(
	'OPERATIONAL' => new GHCA_ACD_Archive_Evidence_Source_Exception(
		GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
		'archive_source_schema_unsupported',
		'source_preflight'
	),
	'UNKNOWN' => new RuntimeException( 'secret second recovery failure' ),
);
foreach ( $transition_cases as $transition_name => $second_failure ) {
	$fixture = p3b2ap_fixture( $wpdb, 'attempt5_transition_' . strtolower( $transition_name ), '2026-07-26T19:35:00Z' );
	$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
	ghca_persist_query( $wpdb, $wpdb->prepare(
		"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
		GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
	), 'prepare attempt-five reclassification task' );
	$transition_source = new GHCA_P3B2A_Sequence_Evidence_Source( array(
		new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
			'archive_source_query_failed',
			'source_query'
		),
		$second_failure,
	) );
	$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
		$transition_source, new GHCA_ACD_Archive_Evidence_Result_Validator(),
		new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
	);
	$transition = p3b2ap_worker( $fixture, 'attempt5-transition-' . strtolower( $transition_name ) )->run_once();
	$transition_row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
	archive_check(
		'dead' === $transition['status'] && 'task_attempts_exhausted' === $transition['reason_code']
		&& 'dead' === $transition_row['task_state'] && 2 === $transition_source->calls
		&& false === strpos( $transition_row['last_error_text'], 'secret' )
		&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
		&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
		'P3B2A-ATTEMPT5-RETRYABLE-TO-' . $transition_name . '-NO-LIFECYCLE reclassifies the final failure before disposition'
	);
}

$fixture = p3b2ap_fixture( $wpdb, 'attempt5_exhausted', '2026-07-26T19:40:00Z' );
$table = $wpdb->prefix . 'ghca_acd_archive_tasks';
ghca_persist_query( $wpdb, $wpdb->prepare(
	"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
	GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
), 'prepare exhausted attempt-five task' );
$failed_source = new GHCA_P3B2A_Fake_Evidence_Source( $fixture['document'], $retryable );
$fixture['handler'] = new GHCA_ACD_Archive_Evidence_Task_Handler(
	$failed_source, new GHCA_ACD_Archive_Evidence_Result_Validator(), new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(), $fixture['stack']['clock']
);
$exhausted = p3b2ap_worker( $fixture, 'attempt5-exhausted' )->run_once();
$failure_code = null;
foreach ( $fixture['stack']['event_store']->load_events( $fixture['scenario']->stream_id ) as $event ) {
	if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type() ) { $failure_code = $event->payload()['failure_code']; }
}
archive_check(
	'dead' === $exhausted['status'] && 'task_attempts_exhausted' === $exhausted['reason_code']
	&& 2 === $failed_source->calls && 'archive_build_attempts_exhausted' === $failure_code
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-ATTEMPT5-EXHAUSTED-NO-OUTCOME commits attempts exhausted exactly once only after final recovery fails'
);

// A committed StartBuild response loss reloads authoritative state and continues once.
$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$fixture = p3b2ap_fixture( $proxy, 'start_response_loss', '2026-07-26T19:50:00Z' );
$commit_number = 0;
$proxy->add_hook( 'query', 'COMMIT', static function () use ( $wpdb, &$commit_number ): void {
	$commit_number++;
	if ( 3 !== $commit_number ) { return; }
	if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Injected commit failed.' ); }
	throw new RuntimeException( 'Injected response loss after StartBuild commit.' );
}, 3 );
$start_replayed = p3b2ap_worker( $fixture, 'start-response-loss' )->run_once();
$proxy->clear_hooks();
archive_check(
	'completed' === $start_replayed['status'] && 1 === $fixture['source']->calls
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveBuildStarted' )
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-STARTBUILD-RESPONSE-LOSS-REPLAY reloads committed start state and captures without duplication'
);

// A matching winner committed during the stale command window is authoritative.
$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$fixture = p3b2ap_fixture( $proxy, 'stream_conflict', '2026-07-26T20:00:00Z' );
$owner = substr( hash( 'sha256', 'p3b2a-stream-owner' ), 0, 32 );
$token = substr( hash( 'sha256', 'p3b2a-stream-token' ), 0, 32 );
$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-26T20:00:00Z', array( 'capture_evidence' ) );
$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
	$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-26T20:00:00Z' )
);
$key = GHCA_ACD_Archive_Digester::task_outcome( array(
	'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
) );
$fence = array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token );
$context = $fixture['build']->start_capture( $claimed, $key, $fence );
$prepared = $fixture['handler']->validate_prepared_result(
	$context,
	$fixture['handler']->prepare( $claimed, $context, static function (): void {} )
);
$winner_stack = ghca_persist_stack( $wpdb, '2026-07-26T20:00:00Z', 'p3b2a-stream-winner' );
$winner = new GHCA_ACD_Archive_Build_Coordinator(
	$winner_stack['event_store'], $winner_stack['snapshot_store'], $winner_stack['artifact_repository'], $winner_stack['uow']
);
$winner_key = hash( 'sha256', 'p3b2a-stream-conflict-winner' );
$proxy->add_hook( 'query', 'START TRANSACTION', static function () use ( $winner, $claimed, $prepared, $winner_key, $fence ): void {
	$winner->record_capture( $claimed, $prepared, $winner_key, $fence );
} );
$stream_conflict = false;
try {
	$fixture['build']->record_capture( $claimed, $prepared, $key, $fence );
} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
	$stream_conflict = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_STREAM_CONFLICT === $error->category()
		&& 'expected_sequence_conflict' === $error->reason_code();
}
$proxy->clear_hooks();
$stream_decision = $fixture['build']->recover_capture( $claimed );
$fixture['stack']['task_store']->complete( $claimed['task_id'], $owner, $token, '2026-07-26T20:00:01Z' );
archive_check(
	$stream_conflict && 'captured' === $stream_decision['decision']
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B2A-STREAM-CONFLICT-RELOAD-MATCHING-SUCCESS reloads the one matching authoritative snapshot'
);

// Failure-command response loss is replayed from retained history on the next run.
$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
$fixture = p3b2ap_fixture( $proxy, 'failure_response_loss', '2026-07-26T20:10:00Z', false, true );
$owner = substr( hash( 'sha256', 'p3b2a-failure-owner' ), 0, 32 );
$token = substr( hash( 'sha256', 'p3b2a-failure-token' ), 0, 32 );
$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-26T20:10:00Z', array( 'capture_evidence' ) );
$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
	$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-26T20:10:00Z' )
);
$key = GHCA_ACD_Archive_Digester::task_outcome( array(
	'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
) );
$fence = array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token );
$fixture['build']->start_capture( $claimed, $key, $fence );
$fixture['stack']['clock']->set( '2026-07-26T20:12:00Z' );
$commit_number = 0;
$proxy->add_hook( 'query', 'COMMIT', static function () use ( $wpdb, &$commit_number ): void {
	$commit_number++;
	if ( 2 !== $commit_number ) { return; }
	if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Injected commit failed.' ); }
	throw new RuntimeException( 'Injected response loss after failure commit.' );
}, 2 );
$lost_failure = p3b2ap_worker( $fixture, 'failure-response-loss-1' )->run_once();
$proxy->clear_hooks();
$fixture['stack']['clock']->set( '2026-07-26T20:30:00Z' );
$replayed_failure = p3b2ap_worker( $fixture, 'failure-response-loss-2' )->run_once();
archive_check(
	'retry' === $lost_failure['status'] && 'dead' === $replayed_failure['status']
	&& 'archive_certificate_invalid' === $replayed_failure['reason_code'] && 1 === $fixture['source']->calls
	&& 1 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
	&& 0 === p3b2ap_event_count( $wpdb, $fixture['scenario']->stream_id, 'EvidenceSnapshotCaptured' ),
	'P3B2A-FAILURE-RESPONSE-LOSS-REPLAY replays the one retained failure without another source call or event'
);

// Both approved request event types bind; replacement uses the same closed capture identity.
ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-26T20:00:00Z', 'p3b2a-replacement' );
$scenario = new GHCA_Persist_Scenario( 'p3b2a_replacement' );
persist_build_finalized( $stack, $scenario );
persist_request_correction( $stack, $scenario );
$replacement_document = p3b2ap_document( $scenario, remediation_digest( '2' ) );
$replacement_fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $replacement_document );
$replacement_payload = $scenario->payload( 'ReplacementArchiveRequested', array(
	'archive_id' => $scenario->id( 'archive-2' ),
	'policy_digest' => remediation_digest( '2' ),
	'revision_number' => 2,
	'reviewed_source_fingerprint' => $replacement_fingerprint,
	'revoked_predecessor_archive_id' => $scenario->id( 'archive-1' ),
) );
$replacement_response = persist_single( $stack, $scenario, 'RequestReplacementArchive', 'request_replacement_archive', $replacement_payload );
$replacement_task = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ghca_acd_archive_tasks WHERE trigger_event_id = %s AND task_type = 'capture_evidence'",
	$replacement_response['last_event_id']
), ARRAY_A );
$replacement_task['payload'] = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $replacement_task['payload_json'] );
$replacement_build = new GHCA_ACD_Archive_Build_Coordinator(
	$stack['event_store'], $stack['snapshot_store'], $stack['artifact_repository'], $stack['uow']
);
$replacement_context = $replacement_build->capture_context( $replacement_task );
archive_check(
	GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED === $replacement_context['request_event']->type()
	&& 2 === $replacement_context['revision_number']
	&& $replacement_fingerprint === $replacement_context['capture_identity']['reviewed_source_fingerprint'],
	'P3B2A-TASK-TRIGGER-EXACT accepts the approved replacement request with authoritative bindings'
);

// Retry-triggered capture retains its historical payload but is rejected before source or command work.
ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-07-26T21:00:00Z', 'p3b2a-retry-deferred' );
$scenario = new GHCA_Persist_Scenario( 'p3b2a_retry_deferred' );
persist_request_archive( $stack, $scenario );
persist_start_build( $stack, $scenario );
$failure_payload = $scenario->payload( 'ArchiveFailed', array(
	'archive_id' => $scenario->id( 'archive-1' ),
	'build_attempt_id' => $scenario->id( 'attempt-1' ),
	'candidate_artifact_ids' => array(),
	'failure_code' => 'archive_evidence_incomplete',
	'phase' => 'capturing',
	'retryable' => true,
	'sealed_snapshot_id' => null,
) );
persist_single( $stack, $scenario, 'FailArchive', 'fail_archive', $failure_payload );
$retry_payload = $scenario->payload( 'ArchiveRetryRequested', array(
	'archive_id' => $scenario->id( 'archive-1' ),
	'new_build_attempt_id' => $scenario->id( 'attempt-2' ),
	'prior_build_attempt_id' => $scenario->id( 'attempt-1' ),
	'resume_phase' => 'capturing',
	'sealed_snapshot_id' => null,
) );
$retry_response = persist_single( $stack, $scenario, 'RetryArchive', 'request_retry', $retry_payload );
$retry_task = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ghca_acd_archive_tasks WHERE trigger_event_id = %s AND task_type = 'capture_evidence'",
	$retry_response['last_event_id']
), ARRAY_A );
$retry_document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $retry_task['payload_json'] );
$commands_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_commands" );
$source_calls = 0;
$retry_rejected = false;
try {
	GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $retry_task, $retry_document );
} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
	$retry_rejected = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
		&& 'task_payload_invalid' === $error->reason_code();
}
archive_check(
	$retry_rejected && 0 === $source_calls
	&& $commands_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_commands" ),
	'P3B2A-LIFECYCLE-RETRY-DEFERRED rejects ArchiveRetryRequested capture before source, handler, or UoW work'
);

archive_finish();
