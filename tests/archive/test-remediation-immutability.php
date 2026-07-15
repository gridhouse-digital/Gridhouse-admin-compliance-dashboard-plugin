<?php
require __DIR__ . '/bootstrap.php';

$mutable_scope = remediation_digest( '3' );
$authority = remediation_authority_context();
$authority['subject_scope_digest'] =& $mutable_scope;
$actor = new GHCA_ACD_Archive_Actor( 'wp_user', '7', '7', 'test', 'archive_create', $authority );
$actor_before = GHCA_ACD_Archive_Canonical_JSON::encode( $actor->canonical() );
$mutable_scope = remediation_digest( '9' );
archive_check( $actor_before === GHCA_ACD_Archive_Canonical_JSON::encode( $actor->canonical() ), 'C-IMM-01 actor authority context is deeply detached' );

$mutable_revision = 1;
$payload = remediation_payload( 'ArchiveRequested' );
$payload['revision_number'] =& $mutable_revision;
$metadata_value = 1;
$metadata = array( 'decision_index' => 0, 'decision_size' => &$metadata_value );
$event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, $payload, $metadata );
$event_before = GHCA_ACD_Archive_Canonical_JSON::encode( $event->canonical() );
$mutable_revision = 9;
$metadata_value = 9;
archive_check( $event_before === GHCA_ACD_Archive_Canonical_JSON::encode( $event->canonical() ), 'C-IMM-02 event payload and metadata are deeply detached' );

$caller = array( 'case_key' => remediation_case_key(), 'request_kind' => 'initial' );
$server = array(
	'archive_id' => remediation_id( 'a' ),
	'policy_digest' => remediation_digest( '2' ), 'resolved_cycle' => remediation_cycle(),
	'reviewed_source_fingerprint' => remediation_digest( '1' ), 'revision_number' => 1,
	'subject_scope_digest' => remediation_digest( '3' ),
);
$command = GHCA_ACD_Archive_Command::request_archive(
	remediation_id( '1' ), remediation_digest( 'b' ), remediation_digest( 'c' ), '0', $actor, $caller, $server
);
$command_bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $command->canonical() );
$command_digest = $command->digest();
$caller['case_key']['tenant_id'] = remediation_id( '9' );
$server['policy_digest'] = remediation_digest( '9' );
archive_check( $command_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $command->canonical() ), 'C-IMM-03 command canonical content cannot change after construction' );
archive_check( $command_digest === $command->digest(), 'C-IMM-04 cached command digest cannot become stale' );

$object = new stdClass();
$object->value = 'unsupported';
archive_expect_exception( static function () use ( $object ): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( array( 'object' => $object ) );
}, 'C-IMM-05 canonical domain data rejects object references', InvalidArgumentException::class );

$course_ids = array( '20', '10' );
$scope = new GHCA_ACD_Archive_Reset_Scope( '42', 'annual', remediation_cycle()['key'], $course_ids );
$scope_bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $scope->canonical() );
$course_ids[0] = '999';
$scope_copy = $scope->canonical();
$scope_copy['course_ids'][0] = '777';
archive_check( $scope_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $scope->canonical() ), 'C-IMM-06 reset scope canonical tree is detached from caller and accessor mutation' );

$case = new GHCA_ACD_Archive_Case();
$request_payload = remediation_payload( 'ArchiveRequested' );
$case->request_archive( $request_payload );
$case_before = GHCA_ACD_Archive_Canonical_JSON::encode( $case->state() );
$request_payload['case_key']['tenant_id'] = remediation_id( '9' );
$state_copy = $case->state();
$state_copy['revisions'][ remediation_id( 'a' ) ]['policy_digest'] = remediation_digest( '9' );
archive_check( $case_before === GHCA_ACD_Archive_Canonical_JSON::encode( $case->state() ), 'C-IMM-07 aggregate state cannot change without applying an event' );

$recording = remediation_recording_context( remediation_id( '4' ), '1', null );
$recorded = $event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, remediation_payload( 'ArchiveRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
$recorded = $event->with_recording_context( $recording );
$digest_before = $recorded->event_digest();
$recording['actor_user_id'] = '999';
archive_check( $digest_before === $recorded->event_digest() && $recorded->verify_digest(), 'C-IMM-08 recorded event digest cannot become stale through recording-context mutation' );

$canonical_object = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '0', array( 'value' => 'sealed' ) ) ) );
$canonical_object_bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $canonical_object );
$canonical_object_copy = $canonical_object->members();
$canonical_object_copy[0][1]['value'] = 'mutated';
archive_check( $canonical_object_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $canonical_object ), 'C-IMM-09 explicit canonical object members are detached from accessor mutation' );

$canonical_input_value = 'sealed';
$canonical_input = array( 'value' => &$canonical_input_value );
$canonical_object = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '0', $canonical_input ) ) );
$canonical_object_bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $canonical_object );
$canonical_input_value = 'mutated';
archive_check( $canonical_object_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $canonical_object ), 'C-IMM-10 explicit canonical object members detach nested caller references' );

$canonical_object_copy = $canonical_object->members();
$canonical_accessor_value =& $canonical_object_copy[0][1]['value'];
$canonical_accessor_value = 'mutated-through-reference';
archive_check( $canonical_object_bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $canonical_object ), 'C-IMM-11 explicit canonical object members do not expose nested references through accessors' );

$canonical_cycle = array();
$canonical_cycle['self'] =& $canonical_cycle;
archive_expect_exception( static function () use ( &$canonical_cycle ): void {
	GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '0', $canonical_cycle ) ) );
}, 'C-IMM-12 explicit canonical objects reject cyclic member references without exhausting the process', InvalidArgumentException::class );

archive_finish();
