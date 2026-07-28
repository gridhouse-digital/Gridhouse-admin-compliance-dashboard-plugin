<?php
define( 'GHCA_P3B2B_PERSISTENCE_LIBRARY_ONLY', true );
require_once __DIR__ . '/test-p3b2b-evidence-source-persistence.php';

/** @param wpdb $db */
function p3b2bc_process_exists( $db, int $connection_id ): bool {
	return (int) $db->get_var( $db->prepare(
		'SELECT COUNT(*) FROM information_schema.processlist WHERE ID = %d',
		$connection_id
	) ) > 0;
}

/** @param callable():void $operation */
function p3b2bc_failure( callable $operation, string $category, string $reason, string $context ): bool {
	try {
		$operation();
	} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
		return $category === $error->category() && $reason === $error->reason_code()
			&& $context === $error->operation_context()
			&& GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES[ $reason ] === $error->getMessage();
	}
	return false;
}

ghca_persist_fresh_schema( $wpdb );
$setup = null;
try {
	$setup = p3b2bp_setup( $wpdb );
	$schema = p3b2bp_identifier( $setup['database'] );
	$options = $schema . '.' . p3b2bp_identifier( $setup['tables']['options_table'] );
	$usermeta = $schema . '.' . p3b2bp_identifier( $setup['tables']['usermeta_table'] );

	p3b2bp_query( $wpdb, $wpdb->prepare(
		"UPDATE {$options} SET option_value = %s WHERE option_name = 'blogname'",
		'Committed Before Snapshot'
	) );
	$source_before = p3b2bp_source_connection( $setup );
	$before_document = p3b2bp_read( $source_before, $setup, $wpdb );
	archive_check(
		'Committed Before Snapshot' === $before_document['organization']['site_name'],
		'P3B2B-MUTATION-BEFORE-SNAPSHOT-IS-VISIBLE observes the last committed source value'
	);

	p3b2bp_query( $wpdb, $wpdb->prepare(
		"UPDATE {$options} SET option_value = %s WHERE option_name = 'blogname'",
		'Snapshot Value'
	) );
	p3b2bp_query( $wpdb, $wpdb->prepare(
		"UPDATE {$usermeta} SET meta_value = %s WHERE meta_key = 'first_name'",
		'Ada'
	) );
	$source_snapshot = p3b2bp_source_connection( $setup );
	$snapshot_connection_id = (int) $source_snapshot->get_var( 'SELECT CONNECTION_ID()' );
	$adapter = p3b2bp_adapter( $source_snapshot, $setup, $wpdb );
	$checkpoints = 0;
	$snapshot_document = $adapter->read_consistent_evidence(
		p3b2bp_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
		static function () use ( &$checkpoints, $wpdb, $options, $usermeta ): void {
			$checkpoints++;
			if ( 16 === $checkpoints ) {
				p3b2bp_query( $wpdb, $wpdb->prepare(
					"UPDATE {$options} SET option_value = %s WHERE option_name = 'blogname'",
					'Committed After Snapshot'
				) );
				p3b2bp_query( $wpdb, $wpdb->prepare(
					"UPDATE {$usermeta} SET meta_value = %s WHERE meta_key = 'first_name'",
					'Changed'
				) );
			}
		}
	);
	$latest_name = $wpdb->get_var( "SELECT option_value FROM {$options} WHERE option_name = 'blogname'" );
	archive_check(
		'Snapshot Value' === $snapshot_document['organization']['site_name']
			&& 'Ada Example' === $snapshot_document['subject']['display_name']
			&& 'Committed After Snapshot' === $latest_name,
		'P3B2B-MUTATION-AFTER-SNAPSHOT-IS-NOT-VISIBLE keeps related queries on one consistent snapshot'
	);
	archive_check(
		! p3b2bc_process_exists( $wpdb, $snapshot_connection_id ),
		'P3B2B-SUCCESS-ROLLBACK-AND-CONNECTION-CLOSE removes the completed source session'
	);

	p3b2bp_query( $wpdb, $wpdb->prepare(
		"UPDATE {$options} SET option_value = %s WHERE option_name = 'ghca_dashboard_brand'",
		'O:8:"stdClass":0:{}'
	) );
	$invalid_source = p3b2bp_source_connection( $setup );
	$invalid_connection_id = (int) $invalid_source->get_var( 'SELECT CONNECTION_ID()' );
	$invalid_adapter = p3b2bp_adapter( $invalid_source, $setup, $wpdb );
	archive_check(
		p3b2bc_failure( static function () use ( $invalid_adapter ): void {
			$invalid_adapter->read_consistent_evidence(
				p3b2bp_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		}, 'invalid', 'archive_evidence_prohibited', 'source_validate' )
			&& ! p3b2bc_process_exists( $wpdb, $invalid_connection_id ),
		'P3B2B-NORMALIZATION-FAILURE-HAPPENS-AFTER-CLEANUP rejects serialized objects after rollback and close'
	);
	p3b2bp_query( $wpdb, $wpdb->prepare(
		"UPDATE {$options} SET option_value = %s WHERE option_name = 'ghca_dashboard_brand'",
		serialize( array( 'org_name' => 'Gridhouse Example' ) )
	) );

	$clock_value = 0.0;
	$clock = static function () use ( &$clock_value ): float {
		$clock_value += 100.0;
		return $clock_value;
	};
	$timeout_source = p3b2bp_source_connection( $setup );
	$timeout_connection_id = (int) $timeout_source->get_var( 'SELECT CONNECTION_ID()' );
	$timeout_adapter = p3b2bp_adapter( $timeout_source, $setup, $wpdb, $clock );
	archive_check(
		p3b2bc_failure( static function () use ( $timeout_adapter ): void {
			$timeout_adapter->read_consistent_evidence(
				p3b2bp_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		}, 'retryable', 'archive_source_query_failed', 'source_query' )
			&& ! p3b2bc_process_exists( $wpdb, $timeout_connection_id ),
		'P3B2B-ELAPSED-BUDGET-STOPS-BETWEEN-STATEMENTS then rolls back and closes the source session'
	);

	$cancel_source = p3b2bp_source_connection( $setup );
	$cancel_connection_id = (int) $cancel_source->get_var( 'SELECT CONNECTION_ID()' );
	$cancel_adapter = p3b2bp_adapter( $cancel_source, $setup, $wpdb );
	$cancel_calls = 0;
	$fence = new RuntimeException( 'fenced' );
	$caught = null;
	try {
		$cancel_adapter->read_consistent_evidence(
			p3b2bp_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function () use ( &$cancel_calls, $fence ): void {
				if ( ++$cancel_calls === 15 ) { throw $fence; }
			}
		);
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	archive_check(
		$caught === $fence,
		'P3B2B-FENCED-HEARTBEAT-CANCELLATION-PRESERVES-ORIGINAL-FENCE rethrows the exact checkpoint failure'
	);
	archive_check(
		! p3b2bc_process_exists( $wpdb, $cancel_connection_id ),
		'P3B2B-FENCED-HEARTBEAT-CANCELLATION-ROLLS-BACK-CLOSES-AND-DISCARDS removes the cancelled source session'
	);
} finally {
	if ( is_array( $setup ) ) {
		p3b2bp_cleanup( $wpdb, $setup );
	}
}

archive_finish();
