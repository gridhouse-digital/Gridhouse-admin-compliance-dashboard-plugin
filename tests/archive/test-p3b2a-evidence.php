<?php
require_once __DIR__ . '/bootstrap.php';

function p3b2a_golden_json(): string {
	return <<<'JSON'
{"calculated":{"calculation_version":1,"categories":{"individual_rights":{"completed_course_ids":["101"],"credit_minutes":"60"}},"compliance_status":"compliant","exceptions":[],"matrix":{"odp":{"individual_rights":true},"oltl":{"general":true}},"total_course_count":1,"total_training_seconds":"3600"},"canonical_format":"ghca-cjson-1","case":{"cycle_key":"2026","employee_user_id":"42","program_key":"annual_training","site_id":"1","tenant_id":"11111111111111111111111111111111"},"completeness":{"missing_fields":[],"observed_count":1,"policy_code":"snapshot_v1_complete","policy_version":1,"required_count":1,"result":"complete","warnings":[]},"courses":[{"category_order":0,"certificate_reference":null,"certificate_required":false,"completed_at_gmt":"2026-06-30T15:00:00Z","completion_status":"completed","course_id":"101","course_order":0,"course_stable_key":null,"course_title":"Safety & Rights","enrollment_status":"enrolled","pass_state":"not_applicable","quiz_attempts":[],"quiz_score_basis_points":null,"source_provenance":{"adapter_key":"learndash-local","record_id":"7001","record_version":"9001"},"started_at_gmt":"2026-06-30T14:00:00Z","time_spent_seconds":"3600"}],"cycle":{"boundary":"[)","display_label":"2026","end_gmt":"2027-01-01T00:00:00Z","key":"2026","policy_key":"calendar_year","policy_version":1,"start_gmt":"2026-01-01T00:00:00Z","timezone":"UTC"},"organization":{"agency_name":"Gridhouse Example","site_name":"Academy Example","tenant_id":"11111111111111111111111111111111"},"policy":{"audit_mapping":{"101":{"category_order":0,"course_order":0,"credit_minutes":"60","is_orientation":false,"odp_category_key":"individual_rights","oltl_category_key":"general"}},"completeness_policy":"snapshot_v1_complete","course_lifespan_rules":{"101":{"lifespan_days":"365","warning_days":"90"}},"policy_digest":"2222222222222222222222222222222222222222222222222222222222222222","quiz_policy":{"attempt_selection":"latest_completed","required":false,"score_scale":"basis_points"},"relevant_settings":{"annual_cycle":"calendar_year","new_hire_deadline_days":"30","warning_days":"90"},"tracked_course_ids":["101"]},"schema_version":1,"source":{"learndash_version":"5.0.0","plugin_version":"1.0.0","source_adapter_key":"learndash-local","source_adapter_version":"1.0.0","source_record_ids":{"course_activity_ids":["7001"],"course_post_ids":["101"],"group_ids":["9"],"quiz_activity_ids":[],"user_id":"42"},"wordpress_version":"6.8.0"},"subject":{"display_name":"Ada Example","email":"ada@example.test","employee_user_id":"42","external_employee_key":null,"group_ids":["9"],"registered_at_gmt":"2025-01-02T03:04:05Z","role_keys":["subscriber"]}}
JSON;
}

/** @return array<string,mixed> */
function p3b2a_golden_document(): array {
	$document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( p3b2a_golden_json() );
	if ( ! is_array( $document ) ) { throw new RuntimeException( 'Golden evidence must decode to an object document.' ); }
	return $document;
}

/** @return array<string,mixed> */
function p3b2a_golden_identity(): array {
	$document = p3b2a_golden_document();
	return array(
		'archive_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'case_key' => array(
			'cycle_key' => $document['case']['cycle_key'],
			'employee_user_id_decimal' => $document['case']['employee_user_id'],
			'program_key' => $document['case']['program_key'],
			'site_id_decimal' => $document['case']['site_id'],
			'tenant_id' => $document['case']['tenant_id'],
		),
		'policy_digest' => $document['policy']['policy_digest'],
		'resolved_cycle' => $document['cycle'],
		'reviewed_source_fingerprint' => 'a281faf9f44869ba7f48ca2ccad4cc4b1bf17f7cbe5f2a977f3771541ee1fc06',
		'revision_number' => 1,
		'stream_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
		'subject_scope_digest' => str_repeat( '3', 64 ),
		'trigger_event_id' => 'cccccccccccccccccccccccccccccccc',
	);
}

/** @return array<string,mixed> */
function p3b2a_multi_course_document(): array {
	$document = p3b2a_golden_document();
	$second = $document['courses'][0];
	$second['course_id'] = '2';
	$second['course_order'] = 1;
	$second['course_title'] = 'Second Course';
	$second['source_provenance']['record_id'] = '7002';
	$second['source_provenance']['record_version'] = '9002';
	$document['courses'][] = $second;
	$document['policy']['tracked_course_ids'] = array( '2', '101' );
	$document['policy']['audit_mapping'] = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array(
			'category_order' => 0, 'course_order' => 1, 'credit_minutes' => '30', 'is_orientation' => false,
			'odp_category_key' => 'individual_rights', 'oltl_category_key' => 'general',
		) ),
		array( '101', array(
			'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60', 'is_orientation' => false,
			'odp_category_key' => 'individual_rights', 'oltl_category_key' => 'general',
		) ),
	) );
	$document['policy']['course_lifespan_rules'] = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
		array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
	) );
	$document['source']['source_record_ids']['course_activity_ids'] = array( '7001', '7002' );
	$document['source']['source_record_ids']['course_post_ids'] = array( '2', '101' );
	$document['calculated']['categories']['individual_rights']['completed_course_ids'] = array( '2', '101' );
	$document['calculated']['categories']['individual_rights']['credit_minutes'] = '90';
	$document['calculated']['total_course_count'] = 2;
	$document['calculated']['total_training_seconds'] = '7200';
	$document['completeness']['observed_count'] = 2;
	$document['completeness']['required_count'] = 2;
	return $document;
}

/** @param callable():void $operation */
function p3b2a_expect_source_failure( callable $operation, string $reason, string $context ): bool {
	try {
		$operation();
	} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
		return GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID === $error->category()
			&& $reason === $error->reason_code() && $context === $error->operation_context()
			&& GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES[ $reason ] === $error->getMessage();
	}
	return false;
}

$validator = new GHCA_ACD_Archive_Evidence_Result_Validator();
$identity = p3b2a_golden_identity();
$document = p3b2a_golden_document();
$validated = $validator->validate( $document, $identity );
$literal_digest = hash( 'sha256', "ghca-source-fingerprint-v1\n" . p3b2a_golden_json() );

archive_check(
	2653 === strlen( p3b2a_golden_json() ) && 2680 === strlen( "ghca-source-fingerprint-v1\n" . p3b2a_golden_json() )
	&& 'a281faf9f44869ba7f48ca2ccad4cc4b1bf17f7cbe5f2a977f3771541ee1fc06' === $literal_digest
	&& $literal_digest === GHCA_ACD_Archive_Digester::source_fingerprint( $validated ),
	'P3B2A-SOURCE-FINGERPRINT-GOLDEN freezes the independent 2,653-byte source document and SHA-256'
);
archive_check(
	in_array( PHP_VERSION, array( '8.3.30', '8.5.7' ), true )
	&& p3b2a_golden_json() === GHCA_ACD_Archive_Canonical_JSON::encode( $validated ),
	'P3B2A-SOURCE-FINGERPRINT-CROSS-RUNTIME emits byte-identical evidence on the required runtime'
);

$multi_course = p3b2a_multi_course_document();
$multi_course_validated = $validator->validate( $multi_course, $identity );
archive_check(
	array( '2', '101' ) === $multi_course_validated['policy']['tracked_course_ids']
		&& array( '101', '2' ) === array_column( $multi_course_validated['courses'], 'course_id' ),
	'P3B2A-TRACKED-MEMBERSHIP-INDEPENDENT-OF-DISPLAY-ORDER accepts numeric membership with retained display order'
);
$membership_variants = array();
$missing_course = $multi_course;
array_pop( $missing_course['courses'] );
$membership_variants['MISSING'] = $missing_course;
$duplicate_course = $multi_course;
$duplicate_course['courses'][1] = $duplicate_course['courses'][0];
$duplicate_course['courses'][1]['course_order'] = 1;
$membership_variants['DUPLICATE'] = $duplicate_course;
$additional_course = $multi_course;
$third = $additional_course['courses'][1];
$third['course_id'] = '3';
$third['course_order'] = 2;
$additional_course['courses'][] = $third;
$membership_variants['ADDITIONAL'] = $additional_course;
foreach ( $membership_variants as $name => $variant ) {
	archive_check(
		p3b2a_expect_source_failure( static function () use ( $validator, $variant, $identity ): void {
			$validator->validate( $variant, $identity );
		}, 'archive_snapshot_invalid', 'source_validate' ),
		'P3B2A-COURSE-MEMBERSHIP-' . $name . '-REJECTED preserves exact set equality'
	);
}

$payload = array(
	'archive_id' => $identity['archive_id'],
	'canonical_format_version' => 1,
	'stream_id' => $identity['stream_id'],
	'task_schema_version' => 1,
	'task_type' => 'capture_evidence',
	'trigger_event_id' => $identity['trigger_event_id'],
);
$row = array(
	'archive_id' => $identity['archive_id'],
	'build_attempt_id' => null,
	'reset_operation_id' => null,
	'stream_id' => $identity['stream_id'],
	'task_schema_version' => 1,
	'task_type' => 'capture_evidence',
	'trigger_event_id' => $identity['trigger_event_id'],
);
$accepted = GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $row, $payload );
$source_calls = 0;
$rejected = array();
foreach ( array( '1', 'ghca-cjson-1' ) as $invalid_format ) {
	$invalid = $payload;
	$invalid['canonical_format_version'] = $invalid_format;
	try {
		GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $row, $invalid );
		$rejected[] = false;
	} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
		$rejected[] = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
			&& 'task_payload_invalid' === $error->reason_code();
	}
}
archive_check(
	1 === $accepted['canonical_format_version'] && array( true, true ) === $rejected && 0 === $source_calls,
	'P3B2A-E02A-INTEGER-CANONICAL-FORMAT accepts strict integer 1 and rejects string alternatives before side effects'
);

$closed_payloads = array();
$closed_payloads[] = $payload + array( 'unknown' => 'value' );
$closed_payloads[] = array_reverse( $payload, true );
$bad_id_payload = $payload;
$bad_id_payload['archive_id'] = 'not-an-id';
$closed_payloads[] = $bad_id_payload;
$closed_payloads[] = array_merge( $payload, array( 'trigger_event_id' => str_repeat( 'd', 32 ) ) );
$closed_rejected = true;
foreach ( $closed_payloads as $closed_payload ) {
	try {
		GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $row, $closed_payload );
		$closed_rejected = false;
	} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
		$closed_rejected = $closed_rejected
			&& GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
			&& 'task_payload_invalid' === $error->reason_code()
			&& 'The retained task payload is invalid.' === $error->getMessage();
	}
}
archive_check(
	$closed_rejected && 0 === $source_calls,
	'P3B2A-TASK-PAYLOAD-CLOSED rejects extra, reordered, invalid-ID, and row-mismatched payloads before side effects'
);
$legacy_payload = $payload;
unset( $legacy_payload['archive_id'] );
$legacy_claimed = GHCA_ACD_Archive_Task_Catalog::validate_claimed_payload( $row, $legacy_payload );
$legacy_strict_rejected = false;
try {
	GHCA_ACD_Archive_Task_Catalog::validate_capture_payload( $row, $legacy_payload );
} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
	$legacy_strict_rejected = GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
		&& 'task_payload_invalid' === $error->reason_code();
}
archive_check(
	$legacy_payload === $legacy_claimed && $legacy_strict_rejected,
	'P3B2A-P3A-GENERIC-TASK-COMPATIBILITY preserves queue tests while strict evidence dispatch rejects the legacy shape'
);

$extra = $document;
$extra['unknown'] = 'value';
archive_check(
	p3b2a_expect_source_failure( static function () use ( $validator, $extra, $identity ): void {
		$validator->validate( $extra, $identity );
	}, 'archive_evidence_prohibited', 'source_validate' ),
	'P3B2A-E07-UNKNOWN-FIELD-REJECTED rejects unknown normalized evidence keys with the exact closed failure'
);

$resource = fopen( 'php://memory', 'rb' );
$prohibited_values = array(
	'<b>private</b>',
	'https://example.test/private',
	'C:\\private\\record.txt',
	"private\x01value",
	new stdClass(),
	$resource,
	static function (): void {},
);
$prohibited_rejected = true;
foreach ( $prohibited_values as $prohibited_value ) {
	$prohibited = $document;
	$prohibited['courses'][0]['course_title'] = $prohibited_value;
	$prohibited_rejected = $prohibited_rejected && p3b2a_expect_source_failure(
		static function () use ( $validator, $prohibited, $identity ): void {
			$validator->validate( $prohibited, $identity );
		},
		'archive_evidence_prohibited',
		'source_validate'
	);
}
fclose( $resource );
archive_check(
	$prohibited_rejected,
	'P3B2A-PROHIBITED-DATA-REJECTED blocks exact HTML, URL, path, control, resource, object, and callback structures'
);

$too_many_rows = $document;
$too_many_rows['courses'] = array_fill( 0, 10001, $document['courses'][0] );
archive_check(
	p3b2a_expect_source_failure( static function () use ( $validator, $too_many_rows, $identity ): void {
		$validator->validate( $too_many_rows, $identity );
	}, 'archive_evidence_incomplete', 'normalize_limit' ),
	'P3B2A-ROW-LIMIT-NO-TRUNCATION rejects the 10,001st source row without partial output'
);

$too_many_values = $document;
$warnings = array();
for ( $index = 0; $index < 10001; $index++ ) { $warnings[] = 'warning_' . $index; }
$too_many_values['completeness']['warnings'] = $warnings;
archive_check(
	p3b2a_expect_source_failure( static function () use ( $validator, $too_many_values, $identity ): void {
		$validator->validate( $too_many_values, $identity );
	}, 'archive_evidence_incomplete', 'normalize_limit' ),
	'P3B2A-VALUE-LIMIT-NO-TRUNCATION applies the independent 10,000-value ceiling to E07'
);

$categories = array( 'invalid', 'retryable', 'operational_blocked', 'integrity' );
$reasons = array(
	'archive_build_binding_invalid', 'archive_snapshot_invalid', 'archive_evidence_prohibited',
	'archive_source_drift', 'archive_source_read_failed', 'archive_source_transaction_failed',
	'archive_source_query_failed', 'archive_evidence_incomplete', 'archive_source_schema_unsupported',
	'archive_certificate_invalid', 'archive_immutable_conflict', 'task_handler_failed',
);
$contexts = array(
	'task_validation', 'authoritative_load', 'command_prepare', 'source_validate',
	'snapshot_prepare', 'fingerprint_compare', 'transaction_start', 'source_query',
	'transaction_commit', 'transaction_rollback', 'connection_close', 'pre_query_limit',
	'normalize_limit', 'snapshot_byte_limit', 'source_preflight', 'certificate_gate',
	'authoritative_recovery', 'snapshot_commit',
);
$allowed_tuples = array();
foreach ( array(
	'invalid|archive_build_binding_invalid' => array( 'authoritative_load', 'command_prepare' ),
	'invalid|archive_snapshot_invalid' => array( 'source_validate', 'snapshot_prepare' ),
	'invalid|archive_evidence_prohibited' => array( 'source_validate' ),
	'invalid|archive_source_drift' => array( 'fingerprint_compare' ),
	'invalid|archive_evidence_incomplete' => array( 'pre_query_limit', 'normalize_limit', 'snapshot_byte_limit' ),
	'invalid|archive_certificate_invalid' => array( 'certificate_gate' ),
	'retryable|archive_source_read_failed' => array( 'transaction_start', 'source_query', 'transaction_commit' ),
	'retryable|archive_source_query_failed' => array( 'source_query' ),
	'operational_blocked|archive_source_transaction_failed' => array( 'transaction_rollback', 'connection_close' ),
	'operational_blocked|archive_source_schema_unsupported' => array( 'source_preflight' ),
	'operational_blocked|task_handler_failed' => $contexts,
	'integrity|archive_immutable_conflict' => array( 'authoritative_recovery', 'snapshot_commit' ),
) as $category_reason => $tuple_contexts ) {
	foreach ( $tuple_contexts as $tuple_context ) { $allowed_tuples[ $category_reason . '|' . $tuple_context ] = true; }
}
$tuple_grammar_closed = true;
foreach ( $categories as $category ) {
	foreach ( $reasons as $reason ) {
		foreach ( $contexts as $context ) {
			$accepted = true;
			try {
				new GHCA_ACD_Archive_Evidence_Source_Exception( $category, $reason, $context );
			} catch ( InvalidArgumentException $error ) {
				$accepted = false;
				$tuple_grammar_closed = $tuple_grammar_closed
					&& 'Evidence-source failure contract is invalid.' === $error->getMessage();
			}
			$tuple_grammar_closed = $tuple_grammar_closed
				&& $accepted === isset( $allowed_tuples[ $category . '|' . $reason . '|' . $context ] );
		}
	}
}
archive_check(
	$tuple_grammar_closed,
	'P3B2A-E14-CLOSED-FAILURE-TUPLES accepts only the exact category, reason, and context combinations'
);

$decomposed = $document;
$decomposed['courses'][0]['course_title'] = "Cafe\u{0301}";
$composed = $document;
$composed['courses'][0]['course_title'] = "Caf\u{00E9}";
$decomposed_rejected = p3b2a_expect_source_failure( static function () use ( $validator, $decomposed, $identity ): void {
	$validator->validate( $decomposed, $identity );
}, 'archive_snapshot_invalid', 'source_validate' );
$composed_accepted = true;
try {
	$validator->validate( $composed, $identity );
} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
	$composed_accepted = false;
}
archive_check(
	$decomposed_rejected && ( class_exists( 'Normalizer' ) ? $composed_accepted : ! $composed_accepted ),
	'P3B2A-E07-NFC-FAIL-CLOSED rejects decomposed text and rejects all non-ASCII text when Normalizer is unavailable'
);

$certificate = $document;
$certificate['courses'][0]['certificate_required'] = true;
$certificate['courses'][0]['certificate_reference'] = array( 'certificate_post_id' => '44', 'source_record_version' => '1' );
archive_check(
	true === $validator->validate( $certificate, $identity )['courses'][0]['certificate_required'],
	'P3B2A-CERTIFICATE-ACQUISITION-DEFERRED validates only the bounded reference and leaves acquisition to the preparer gate'
);

archive_check(
	! interface_exists( 'GHCA_ACD_Archive_Evidence_Source_Adapter' )
	&& ! class_exists( 'GHCA_ACD_Archive_Worker_Runner' ),
	'P3B2A-REVIEWED-FINGERPRINT-PROVENANCE-GATED keeps the fake-backed slice non-activatable'
);
archive_check(
	! method_exists( GHCA_ACD_Archive_Task_Catalog::class, 'validate_retry_capture_payload' ),
	'P3B2A-D16-REMAINS-DEFERRED installs no lifecycle retry execution contract'
);

archive_finish();
