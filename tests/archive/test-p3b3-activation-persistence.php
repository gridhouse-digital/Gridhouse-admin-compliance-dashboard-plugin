<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';
define( 'GHCA_P3B2B_PERSISTENCE_LIBRARY_ONLY', true );
require_once __DIR__ . '/test-p3b2b-evidence-source-persistence.php';

final class GHCA_P3B3A_Review_Source implements GHCA_ACD_Archive_Evidence_Source {
	/** @var array<string,mixed> */ private $document;
	/** @var int */ public $review_calls = 0;
	/** @var bool */ public $capture_called = false;
	public function __construct( array $document ) { $this->document = $document; }
	public function read_consistent_evidence( array $capture_identity, array $limits, callable $checkpoint ): array { $this->capture_called = true; return $this->document; }
	public function read_consistent_review_evidence( array $review_identity, array $limits, callable $checkpoint ): array {
		$this->review_calls++; $checkpoint(); return GHCA_ACD_Archive_Canonical_JSON::detach( $this->document );
	}
	public function preflight( array $limits, callable $checkpoint ): void { $checkpoint(); }
}

/** @return array<string,mixed> */
function p3b3ap_document( GHCA_Persist_Scenario $scenario, string $policy_digest ): array {
	$cycle = remediation_cycle();
	$audit = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '101', array(
		'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60', 'is_orientation' => false,
		'odp_category_key' => 'annual', 'oltl_category_key' => 'general',
	) ) ) );
	$lifespans = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ) ) );
	return array(
		'calculated' => array(
			'calculation_version' => 1, 'categories' => array( 'annual' => array( 'completed_course_ids' => array( '101' ), 'credit_minutes' => '60' ) ),
			'compliance_status' => 'compliant', 'exceptions' => array(), 'matrix' => array( 'odp' => array( 'annual' => true ), 'oltl' => array( 'general' => true ) ),
			'total_course_count' => 1, 'total_training_seconds' => '3600',
		),
		'canonical_format' => 'ghca-cjson-1',
		'case' => array(
			'cycle_key' => $scenario->case_canonical['cycle_key'], 'employee_user_id' => $scenario->case_canonical['employee_user_id_decimal'],
			'program_key' => $scenario->program, 'site_id' => $scenario->case_canonical['site_id_decimal'], 'tenant_id' => $scenario->case_canonical['tenant_id'],
		),
		'completeness' => array( 'missing_fields' => array(), 'observed_count' => 1, 'policy_code' => 'snapshot_v1_complete', 'policy_version' => 1, 'required_count' => 1, 'result' => 'complete', 'warnings' => array() ),
		'courses' => array( array(
			'category_order' => 0, 'certificate_reference' => null, 'certificate_required' => false,
			'completed_at_gmt' => '2026-07-13T15:00:00Z', 'completion_status' => 'completed', 'course_id' => '101',
			'course_order' => 0, 'course_stable_key' => 'course-101', 'course_title' => 'Fixture Course 101',
			'enrollment_status' => 'enrolled', 'pass_state' => 'passed',
			'quiz_attempts' => array( array( 'attempt_ordinal' => 0, 'attempted_at_gmt' => '2026-07-13T14:30:00Z', 'passed' => true, 'score_basis_points' => 9000 ) ),
			'quiz_score_basis_points' => 9000, 'source_provenance' => array( 'adapter_key' => 'learndash-local', 'record_id' => '7001', 'record_version' => '1' ),
			'started_at_gmt' => '2026-07-13T14:00:00Z', 'time_spent_seconds' => '3600',
		) ),
		'cycle' => $cycle,
		'organization' => array( 'agency_name' => 'Fixture Agency', 'site_name' => 'Fixture Site', 'tenant_id' => $scenario->case_canonical['tenant_id'] ),
		'policy' => array(
			'audit_mapping' => $audit, 'completeness_policy' => 'snapshot_v1_complete', 'course_lifespan_rules' => $lifespans,
			'policy_digest' => $policy_digest, 'quiz_policy' => array( 'attempt_selection' => 'latest_completed', 'required' => true, 'score_scale' => 'basis_points' ),
			'relevant_settings' => array( 'annual_cycle' => 'calendar_year', 'new_hire_deadline_days' => '30', 'warning_days' => '90' ), 'tracked_course_ids' => array( '101' ),
		),
		'schema_version' => 1,
		'source' => array(
			'learndash_version' => '5.1.6.1', 'plugin_version' => '1.2.0', 'source_adapter_key' => 'learndash-local', 'source_adapter_version' => '1.0.0',
			'source_record_ids' => array( 'course_activity_ids' => array( '7001' ), 'course_post_ids' => array( '101' ), 'group_ids' => array( '9' ), 'quiz_activity_ids' => array( '8001' ), 'user_id' => $scenario->case_canonical['employee_user_id_decimal'] ),
			'wordpress_version' => '7.0.2',
		),
		'subject' => array(
			'display_name' => 'Fixture Employee', 'email' => 'fixture@example.test', 'employee_user_id' => $scenario->case_canonical['employee_user_id_decimal'],
			'external_employee_key' => null, 'group_ids' => array( '9' ), 'registered_at_gmt' => '2025-01-02T03:04:05Z', 'role_keys' => array( 'subscriber' ),
		),
	);
}

ghca_persist_fresh_schema( $wpdb );
$stack = ghca_persist_stack( $wpdb, '2026-08-03T12:00:00Z', 'p3b3-activation-intake' );
$scenario = new GHCA_Persist_Scenario( 'p3b3_activation_intake' );
$policy_digest = hash( 'sha256', 'p3b3-activation-policy' );
$document = p3b3ap_document( $scenario, $policy_digest );
$source = new GHCA_P3B3A_Review_Source( $document );
$ids = new GHCA_Persist_Sequential_Ids( 'p3b3-activation-review' );
$intake = new GHCA_ACD_Archive_Review_Intake( $source, $stack['uow'], $ids );
$checkpoints = 0;
$request = array( 'actor' => $scenario->actor, 'case_key' => $scenario->case_key, 'idempotency_key' => 'review-initial-1' );
$response = $intake->execute( $request, static function () use ( &$checkpoints ): void { $checkpoints++; } );
$events = $stack['event_store']->load_events( $scenario->stream_id ?: $response['stream_id'] );
$payload = $events[0]->payload();
$expected_fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $document );
archive_check(
	1 === count( $events ) && GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED === $events[0]->type()
		&& $expected_fingerprint === $payload['reviewed_source_fingerprint'] && $policy_digest === $payload['policy_digest']
		&& 1 === $source->review_calls && ! $source->capture_called && $checkpoints >= 8,
	'ACTIVATION-REVIEW-INTAKE-SERVER-FACTS-AND-CHECKPOINTS commits one reviewed request from the E07/E08 authority path'
);

$tasks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_tasks" );
$replayed = $intake->execute( $request, static function (): void {} );
archive_check(
	$response === $replayed && 1 === count( $stack['event_store']->load_events( $response['stream_id'] ) )
		&& $tasks === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_tasks" ),
	'ACTIVATION-REVIEW-INTAKE-RECEIPT-REPLAY preserves one event and one durable task after response loss'
);

$before_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" );
$before_commands = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_commands" );
$invalid = false;
try {
	$intake->execute( array_merge( $request, array( 'reviewed_source_fingerprint' => str_repeat( '0', 64 ) ) ), static function (): void {} );
} catch ( InvalidArgumentException $error ) { $invalid = 'Archive review request is invalid.' === $error->getMessage(); }
archive_check(
	$invalid && $before_events === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" )
		&& $before_commands === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_commands" ),
	'ACTIVATION-REVIEW-CALLER-FINGERPRINT-REJECTED rejects authoritative caller fields with zero persistence residue'
);

$fence = new RuntimeException( 'exact-review-fence' ); $seen = null;
try { $intake->execute( array( 'actor' => $scenario->actor, 'case_key' => $scenario->case_key, 'idempotency_key' => 'review-fenced-2' ), static function () use ( $fence ): void { throw $fence; } ); }
catch ( Throwable $error ) { $seen = $error; }
archive_check(
	$seen === $fence && $before_events === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" ),
	'ACTIVATION-REVIEW-FENCE-PRESERVED preserves the exact cancellation throwable and creates no lifecycle event'
);

$adapter_setup = null;
try {
	$adapter_setup = p3b2bp_setup( $wpdb );
	$identity = p3b2bp_identity();
	$review_identity = array(
		'case_key' => $identity['case_key'],
		'resolved_cycle' => $identity['resolved_cycle'],
	);
	$limits = array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 );
	$review = p3b2bp_adapter( p3b2bp_source_connection( $adapter_setup ), $adapter_setup, $wpdb )
		->read_consistent_review_evidence( $review_identity, $limits, static function (): void {} );
	$review_bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $review );
	$review_fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $review );
	$identity['policy_digest'] = $review['policy']['policy_digest'];
	$identity['reviewed_source_fingerprint'] = $review_fingerprint;
	$capture = p3b2bp_adapter( p3b2bp_source_connection( $adapter_setup ), $adapter_setup, $wpdb )
		->read_consistent_evidence( $identity, $limits, static function (): void {} );
	archive_check(
		$review_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $capture ),
		'ACTIVATION-PARITY-E07-BYTES-EXACT proves review and capture bytes through separate real adapter sessions'
	);
	archive_check(
		$review_fingerprint === GHCA_ACD_Archive_Digester::source_fingerprint( $capture )
			&& 1 === $review['calculated']['calculation_version'],
		'ACTIVATION-PARITY-E08-DIGEST-EXACT proves the real adapter dependency and fingerprint parity'
	);
	archive_check(
		$review['source'] === $capture['source']
			&& 'learndash-local' === $review['source']['source_adapter_key']
			&& '1.0.0' === $review['source']['source_adapter_version'],
		'ACTIVATION-PARITY-DEPENDENCY-DESCRIPTORS-EXACT proves both real sessions use the frozen adapter and code-version tuple'
	);

	$source_schema = p3b2bp_identifier( $adapter_setup['database'] );
	p3b2bp_query(
		$wpdb,
		"UPDATE {$source_schema}.`wp_usermeta` SET meta_value = 'Changed' WHERE user_id = 42 AND meta_key = 'first_name'"
	);
	$events_before_drift = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" );
	$handler = new GHCA_ACD_Archive_Evidence_Task_Handler(
		p3b2bp_adapter( p3b2bp_source_connection( $adapter_setup ), $adapter_setup, $wpdb ),
		new GHCA_ACD_Archive_Evidence_Result_Validator(),
		new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(),
		new GHCA_Persist_Fixed_Clock( '2026-08-03T12:00:00Z' )
	);
	$drift = $handler->prepare( array(), array( 'capture_identity' => $identity ), static function (): void {} );
	archive_check(
		array( 'captured_source_fingerprint', 'decision' ) === array_keys( $drift )
			&& 'source_drift' === $drift['decision']
			&& ! hash_equals( $review_fingerprint, $drift['captured_source_fingerprint'] )
			&& $events_before_drift === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" ),
		'ACTIVATION-PARITY-MUTATION-DRIFT-FENCED returns only the fenced drift decision with no lifecycle residue'
	);

	$cancel_connection = p3b2bp_source_connection( $adapter_setup );
	$cancel_connection_id = (int) $cancel_connection->get_var( 'SELECT CONNECTION_ID()' );
	$cancel_adapter = p3b2bp_adapter( $cancel_connection, $adapter_setup, $wpdb );
	$cancel_fence = new RuntimeException( 'exact-review-adapter-fence' );
	$cancel_seen = null; $cancel_calls = 0;
	try {
		$cancel_adapter->read_consistent_review_evidence(
			$review_identity,
			$limits,
			static function () use ( &$cancel_calls, $cancel_fence ): void {
				if ( 15 === ++$cancel_calls ) { throw $cancel_fence; }
			}
		);
	} catch ( Throwable $error ) {
		$cancel_seen = $error;
	}
	$cancel_process_exists = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM information_schema.processlist WHERE ID = %d',
		$cancel_connection_id
	) ) > 0;
	archive_check(
		$cancel_seen === $cancel_fence && ! $cancel_process_exists
			&& $events_before_drift === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" ),
		'ACTIVATION-REAL-REVIEW-ADAPTER-CANCELLATION-CLOSES preserves the exact fence after rollback and connection cleanup'
	);
} finally {
	if ( is_array( $adapter_setup ) ) { p3b2bp_cleanup( $wpdb, $adapter_setup ); }
}

archive_finish();
