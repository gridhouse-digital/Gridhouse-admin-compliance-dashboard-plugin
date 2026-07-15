<?php
require __DIR__ . '/bootstrap.php';

$request = new GHCA_ACD_Archive_Event(
	'ArchiveRequested',
	1,
	remediation_payload( 'ArchiveRequested' ),
	array( 'decision_index' => 0, 'decision_size' => 1 )
);
archive_check( false === $request->is_recorded(), 'A-ENV-01 new domain event is explicitly uncommitted' );

$recorded_request = $request->with_recording_context(
	remediation_recording_context( remediation_id( '4' ), '1', null )
);
archive_check( true === $recorded_request->is_recorded(), 'A-ENV-02 recording context returns a distinct recorded event' );
archive_check( false === $request->is_recorded(), 'A-ENV-03 assigning context does not mutate the uncommitted event' );

$started = new GHCA_ACD_Archive_Event(
	'ArchiveBuildStarted',
	1,
	remediation_payload( 'ArchiveBuildStarted' ),
	array( 'decision_index' => 0, 'decision_size' => 1 )
);
$recorded_started = $started->with_recording_context(
	remediation_recording_context(
		remediation_id( '5' ),
		'2',
		$recorded_request->event_digest(),
		array(
			'build_attempt_id' => remediation_id( 'b' ),
			'causation_event_id' => remediation_id( '4' ),
			'occurred_at_gmt' => '2026-07-13T12:00:01Z',
			'recorded_at_gmt' => '2026-07-13T12:00:01Z',
		)
	)
);

$stream = array( $recorded_request, $recorded_started );
archive_check( GHCA_ACD_Archive_Event_Stream_Verifier::verify( $stream ), 'A-STREAM-01 two-event authoritative stream verifies' );
// A-GOLDEN-01/02 refrozen for T1: the archive subject_scope_digest and the actor
// authority scope it binds to are now the exact authorized reset-scope digest, so
// the recorded ArchiveRequested payload and every event's authority context changed.
archive_check( $recorded_request->event_digest() === '8484a6b5038aaef23a5e44b23e023f6f4881027ae5821e4d66dc2add59dc75b7', 'A-GOLDEN-01 first recorded event digest is frozen' );
archive_check( $recorded_started->event_digest() === 'f56d0a53f0740d0985d9dec0518cf8c720c58a2903448eb93815a2a75039999f', 'A-GOLDEN-02 predecessor-chained second event digest is frozen' );

// A-GOLDEN-03 v2: the prior vector froze an invalid contract (all command
// fields null on a command-originated event) and was deliberately replaced.
$edge_event = new GHCA_ACD_Archive_Event(
	'ArchiveRequested', 1, remediation_payload( 'ArchiveRequested', array( 'revision_number' => PHP_INT_MAX ) ),
	array( 'decision_index' => 0, 'decision_size' => 1 )
);
$edge_recorded = $edge_event->with_recording_context( remediation_recording_context(
	remediation_id( '8' ), '18446744073709551615', $recorded_started->event_digest(), array(
		'actor_user_id' => '18446744073709551615',
		'initiating_user_id' => '9223372036854775808',
		'reason_code' => 'edge_vector',
		'reason_text' => "composed \u{00E9}; decomposed e\u{0301}; line\n\t\"quoted\"",
	)
) );
archive_check( $edge_recorded->event_digest() === 'b0d7527f48b0e1046e987bd372d7223d98040a27bb73e320daa18f4d486e2d1f', 'A-GOLDEN-03 boundary/Unicode/escaping event vector with complete command identity is frozen' );
archive_expect_exception( static function () use ( $recorded_started ): void {
	$event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, remediation_payload( 'ArchiveRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
	$event->with_recording_context( remediation_recording_context( remediation_id( '8' ), '18446744073709551616', $recorded_started->event_digest() ) );
}, 'A-SEQ-OVERFLOW stream sequence above BIGINT UNSIGNED is rejected', InvalidArgumentException::class );

$provenance_null_fields = array(
	'A-CMD-01 ArchiveRequested cannot record with all command fields null' => array( 'command_id' => null, 'idempotency_scope_digest' => null, 'idempotency_key_digest' => null, 'command_digest' => null ),
	'A-CMD-02 ArchiveRequested cannot record with a partially null command identity' => array( 'command_digest' => null ),
);
foreach ( $provenance_null_fields as $provenance_message => $provenance_overrides ) {
	archive_expect_exception( static function () use ( $provenance_overrides ): void {
		$event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, remediation_payload( 'ArchiveRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
		$event->with_recording_context( remediation_recording_context( remediation_id( '8' ), '1', null, $provenance_overrides ) );
	}, $provenance_message, InvalidArgumentException::class );
}
archive_expect_exception( static function (): void {
	$event = new GHCA_ACD_Archive_Event( 'ReplacementArchiveRequested', 1, remediation_payload( 'ReplacementArchiveRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
	$event->with_recording_context( remediation_recording_context( remediation_id( '8' ), '1', null, array(
		'archive_id' => remediation_id( '0' ),
		'command_id' => null, 'idempotency_scope_digest' => null, 'idempotency_key_digest' => null, 'command_digest' => null,
	) ) );
}, 'A-CMD-03 ReplacementArchiveRequested cannot record with all command fields null', InvalidArgumentException::class );
archive_expect_exception( static function (): void {
	$event = new GHCA_ACD_Archive_Event( 'ResetRequested', 1, remediation_payload( 'ResetRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
	$event->with_recording_context( remediation_recording_context( remediation_id( '8' ), '1', null, array(
		'reset_operation_id' => remediation_id( 'f' ),
		'command_id' => null, 'idempotency_scope_digest' => null, 'idempotency_key_digest' => null, 'command_digest' => null,
	) ) );
}, 'A-CMD-04 every classified command-originated event requires complete command identity', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	$event = new GHCA_ACD_Archive_Event( 'ArchiveRequested', 1, remediation_payload( 'ArchiveRequested' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
	$authority = remediation_authority_context();
	$authority['subject_scope_digest'] = remediation_digest( '9' );
	$event->with_recording_context( remediation_recording_context( remediation_id( '8' ), '1', null, array( 'authority_context' => $authority ) ) );
}, 'A-AUTH-01 actor authority subject scope must match the requested subject scope', InvalidArgumentException::class );

$foreign_event_authority = remediation_authority_context();
$foreign_event_authority['subject_scope_digest'] = remediation_digest( '9' );
$authority_bound_events = array(
	'ResetRequested' => array( 'reset_operation_id' => remediation_id( 'f' ) ),
	'ResetAuthorized' => array( 'reset_operation_id' => remediation_id( 'f' ) ),
	'ResetExecutionClaimed' => array(
		'archive_id'           => null,
		'reset_operation_id'   => remediation_id( 'f' ),
		'upstream_operation_id'=> 'ld-reset:tenant-1/op-0001',
	),
	'CorrectionRequested' => array(),
	'ResetRemediationRequired' => array(
		'archive_id'            => null,
		'reset_operation_id'    => remediation_id( 'f' ),
		'upstream_operation_id' => 'ld-reset:tenant-1/op-0001',
	),
	'UnprotectedResetDetected' => array( 'archive_id' => null ),
	'UnprotectedResetConfirmed' => array( 'archive_id' => null ),
);
foreach ( $authority_bound_events as $authority_event_type => $authority_context_overrides ) {
	$matching_authority_event = new GHCA_ACD_Archive_Event( $authority_event_type, 1, remediation_payload( $authority_event_type ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
	$matching_authority_recorded = $matching_authority_event->with_recording_context( remediation_recording_context(
		remediation_id( '7' ),
		'1',
		null,
		$authority_context_overrides
	) );
	archive_check( $matching_authority_recorded->verify_digest(), 'A-AUTH-' . $authority_event_type . '-CONTROL matching authority and envelope bindings record successfully' );
	archive_expect_exception( static function () use ( $authority_event_type, $authority_context_overrides, $foreign_event_authority ): void {
		$event = new GHCA_ACD_Archive_Event( $authority_event_type, 1, remediation_payload( $authority_event_type ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
		$event->with_recording_context( remediation_recording_context(
			remediation_id( '8' ),
			'1',
			null,
			array_replace( $authority_context_overrides, array( 'authority_context' => $foreign_event_authority ) )
		) );
	}, 'A-AUTH-' . $authority_event_type . ' effective subject scope must match recorded actor authority', InvalidArgumentException::class );
}

$replayed = GHCA_ACD_Archive_Case::rehydrate( $stream );
archive_check( 2 === $replayed->state()['sequence'], 'A-STREAM-02 authoritative replay applies the verified stream only after full validation' );

/** @param callable(array<int,GHCA_ACD_Archive_Event>):array<int,GHCA_ACD_Archive_Event> $tamper */
function remediation_expect_stream_rejection( array $stream, callable $tamper, string $message ): void {
	archive_expect_exception(
		static function () use ( $stream, $tamper ): void {
			GHCA_ACD_Archive_Case::rehydrate( $tamper( $stream ) );
		},
		$message,
		InvalidArgumentException::class
	);
}

remediation_expect_stream_rejection( $stream, static function ( array $events ): array { return array( $events[1] ); }, 'A-STREAM-03 sequence must begin at one' );
remediation_expect_stream_rejection( $stream, static function ( array $events ): array { return array( $events[0], $events[0] ); }, 'A-STREAM-04 duplicate sequence/event is rejected' );
remediation_expect_stream_rejection( $stream, static function ( array $events ): array { return array( $events[1], $events[0] ); }, 'A-STREAM-05 reordered stream is rejected' );

$valid_discontinuities = array(
	'A-STREAM-06 gap is rejected after digest recomputation' => remediation_recording_context( remediation_id( '5' ), '3', $recorded_request->event_digest(), array( 'build_attempt_id' => remediation_id( 'b' ), 'causation_event_id' => remediation_id( '4' ) ) ),
	'A-STREAM-07 wrong stream is rejected after digest recomputation' => remediation_recording_context( remediation_id( '5' ), '2', $recorded_request->event_digest(), array( 'stream_id' => remediation_id( '9' ), 'build_attempt_id' => remediation_id( 'b' ), 'causation_event_id' => remediation_id( '4' ) ) ),
	'A-STREAM-08 wrong case is rejected after digest recomputation' => remediation_recording_context( remediation_id( '5' ), '2', $recorded_request->event_digest(), array( 'case_key_digest' => remediation_digest( '9' ), 'build_attempt_id' => remediation_id( 'b' ), 'causation_event_id' => remediation_id( '4' ) ) ),
	'A-STREAM-09 predecessor mismatch is rejected after digest recomputation' => remediation_recording_context( remediation_id( '5' ), '2', remediation_digest( '9' ), array( 'build_attempt_id' => remediation_id( 'b' ), 'causation_event_id' => remediation_id( '4' ) ) ),
);
foreach ( $valid_discontinuities as $message => $context ) {
	$variant = $started->with_recording_context( $context );
	remediation_expect_stream_rejection( array( $recorded_request, $variant ), static function ( array $events ): array { return $events; }, $message );
}

$tamper_fields = array(
	'wrong stream' => array( 1, 'stream_id', remediation_id( '9' ) ),
	'wrong case' => array( 1, 'case_key_digest', remediation_digest( '9' ) ),
	'wrong predecessor' => array( 1, 'previous_event_digest', remediation_digest( '9' ) ),
	'envelope tamper' => array( 0, 'correlation_id', remediation_id( '9' ) ),
	'actor tamper' => array( 0, 'actor_user_id', '8' ),
	'metadata tamper' => array( 0, 'metadata', array( 'decision_index' => 0, 'decision_size' => 2 ) ),
	'payload tamper' => array( 0, 'payload', array_replace( remediation_payload( 'ArchiveRequested' ), array( 'revision_number' => 2 ) ) ),
	'event type tamper' => array( 0, 'event_type', 'UnknownArchiveEvent' ),
	'event schema tamper' => array( 0, 'event_schema_version', 2 ),
	'event digest tamper' => array( 0, 'event_digest', remediation_digest( '9' ) ),
);
foreach ( $tamper_fields as $label => $change ) {
	archive_expect_exception( static function () use ( $stream, $change ): void {
		$documents = array( $stream[0]->recorded_document(), $stream[1]->recorded_document() );
		$documents[ $change[0] ][ $change[1] ] = $change[2];
		GHCA_ACD_Archive_Event::from_recorded( $documents[ $change[0] ] );
	}, 'A-TAMPER ' . $label . ' is rejected before replay', InvalidArgumentException::class );
}

$duplicate_id_event = new GHCA_ACD_Archive_Event( 'EvidenceSnapshotCaptured', 1, remediation_payload( 'EvidenceSnapshotCaptured' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
$duplicate_id_recorded = $duplicate_id_event->with_recording_context(
	remediation_recording_context( remediation_id( '4' ), '3', $recorded_started->event_digest(), array( 'causation_event_id' => remediation_id( '5' ) ) )
);
archive_check( $duplicate_id_recorded->verify_digest(), 'A-STREAM-11 duplicate-ID event carries an individually valid recomputed digest' );
archive_expect_exception( static function () use ( $recorded_request, $recorded_started, $duplicate_id_recorded ): void {
	GHCA_ACD_Archive_Event_Stream_Verifier::verify( array( $recorded_request, $recorded_started, $duplicate_id_recorded ) );
}, 'A-STREAM-12 stream verification rejects a duplicate event_id even with valid sequence and recomputed hashes', InvalidArgumentException::class );

// Positive control: a complete recorded lifecycle, including one multi-event
// decision with consistent provenance, replays to FINALIZED + ACTIVE.
$chain_steps = array(
	array( remediation_id( '4' ), 'ArchiveRequested', array(), 0, 1 ),
	array( remediation_id( '5' ), 'ArchiveBuildStarted', array( 'build_attempt_id' => remediation_id( 'b' ) ), 0, 1 ),
	array( remediation_id( '6' ), 'EvidenceSnapshotCaptured', array(), 0, 1 ),
	array( remediation_id( '7' ), 'LedgerMaterialized', array( 'build_attempt_id' => remediation_id( 'b' ) ), 0, 1 ),
	array( remediation_id( '8' ), 'PacketMaterialized', array( 'build_attempt_id' => remediation_id( 'b' ) ), 0, 1 ),
	array( remediation_id( '9' ), 'ArchiveVerified', array(), 0, 2 ),
	array( remediation_id( '0' ), 'ArchiveFinalized', array(), 1, 2 ),
);
$full_chain = array();
$chain_previous = null;
$chain_sequence = 1;
foreach ( $chain_steps as $step ) {
	$chain_event = new GHCA_ACD_Archive_Event( $step[1], 1, remediation_payload( $step[1] ), array( 'decision_index' => $step[3], 'decision_size' => $step[4] ) );
	$chain_context = remediation_recording_context( $step[0], (string) $chain_sequence, $chain_previous, array_replace( array(
		'causation_event_id' => 1 === $chain_sequence ? null : remediation_id( '4' ),
	), $step[2] ) );
	$chain_recorded = $chain_event->with_recording_context( $chain_context );
	$full_chain[] = $chain_recorded;
	$chain_previous = $chain_recorded->event_digest();
	$chain_sequence++;
}
$full_replayed = GHCA_ACD_Archive_Case::rehydrate( $full_chain );
archive_check(
	'FINALIZED' === $full_replayed->state()['revisions'][ remediation_id( 'a' ) ]['build_state'] && 'ACTIVE' === $full_replayed->state()['revisions'][ remediation_id( 'a' ) ]['validity_state'],
	'A-STREAM-13 a complete recorded lifecycle with a consistent multi-event decision replays to FINALIZED + ACTIVE'
);

$mixed_chain = array_slice( $full_chain, 0, 5 );
$mixed_verified = ( new GHCA_ACD_Archive_Event( 'ArchiveVerified', 1, remediation_payload( 'ArchiveVerified' ), array( 'decision_index' => 0, 'decision_size' => 2 ) ) )->with_recording_context(
	remediation_recording_context( remediation_id( '9' ), '6', $mixed_chain[4]->event_digest(), array( 'causation_event_id' => remediation_id( '4' ) ) )
);
$mixed_finalized = ( new GHCA_ACD_Archive_Event( 'ArchiveFinalized', 1, remediation_payload( 'ArchiveFinalized' ), array( 'decision_index' => 1, 'decision_size' => 2 ) ) )->with_recording_context(
	remediation_recording_context( remediation_id( '0' ), '7', $mixed_verified->event_digest(), array( 'causation_event_id' => remediation_id( '4' ), 'command_id' => remediation_id( '2' ) ) )
);
$mixed_chain[] = $mixed_verified;
$mixed_chain[] = $mixed_finalized;
archive_expect_exception( static function () use ( $mixed_chain ): void {
	GHCA_ACD_Archive_Case::rehydrate( $mixed_chain );
}, 'A-BATCH-PROVENANCE a multi-event decision with mixed command provenance fails authoritative replay', InvalidArgumentException::class );

$standalone_verified = new GHCA_ACD_Archive_Event( 'ArchiveVerified', 1, remediation_payload( 'ArchiveVerified' ), array( 'decision_index' => 0, 'decision_size' => 1 ) );
$recorded_standalone_verified = $standalone_verified->with_recording_context(
	remediation_recording_context( remediation_id( '6' ), '2', $recorded_request->event_digest(), array( 'archive_id' => remediation_id( 'a' ), 'causation_event_id' => remediation_id( '4' ) ) )
);
archive_expect_exception( static function () use ( $recorded_request, $recorded_standalone_verified ): void {
	GHCA_ACD_Archive_Case::rehydrate( array( $recorded_request, $recorded_standalone_verified ) );
}, 'A-STREAM-10 authoritative replay rejects a validly hashed standalone atomic fragment', GHCA_ACD_Archive_Transition_Exception::class, 'incomplete_finalization_batch' );

archive_finish();
