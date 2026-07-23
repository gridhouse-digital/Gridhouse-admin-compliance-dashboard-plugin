<?php
require_once __DIR__ . '/persistence-bootstrap.php';
require_once __DIR__ . '/persistence-fixtures.php';

const GHCA_P3B1_FAILURE_CURSOR_KEY = 'bc219f03b1592f57441af7540c1bdcc8e92ca3ac112b562788b107dfd9e60d78';

final class GHCA_P3B1_Failure_Store implements GHCA_ACD_Archive_Artifact_Store {
	/** @var string */
	private $operation;
	/** @var string */
	private $reason;
	/** @var int */
	public $calls = 0;

	public function __construct( string $operation, string $reason ) {
		$this->operation = $operation;
		$this->reason    = $reason;
	}

	public function create_staging( array $identity ): string {
		$this->calls++;
		$this->fail_if( 'create' );
		return 'staging/' . implode( '/', array_values( $identity ) ) . '/' . str_repeat( 'a', 32 ) . '.part';
	}

	public function committed_key( array $identity, string $kind ): string {
		$this->fail_if( 'key' );
		return 'committed/' . implode( '/', array_values( $identity ) ) . '.' . ( 'ledger' === $kind ? 'json' : 'pdf' );
	}

	public function write_staging( string $staging_key, $source, string $kind ): array {
		$this->fail_if( 'write' );
		$bytes = stream_get_contents( $source );
		if ( false === $bytes ) {
			throw new RuntimeException( 'test stream read failed' );
		}
		if ( 'wrong_digest' === $this->operation ) {
			return array( 'byte_count' => strlen( $bytes ), 'content_digest' => str_repeat( '0', 64 ) );
		}
		return array( 'byte_count' => strlen( $bytes ), 'content_digest' => hash( 'sha256', $bytes ) );
	}

	public function commit( string $staging_key, string $committed_key, string $kind, int $byte_count, string $sha256 ): array {
		$this->fail_if( 'commit' );
		return array( 'committed_key' => $committed_key, 'byte_count' => $byte_count, 'content_digest' => $sha256 );
	}

	public function open_committed( string $committed_key, string $kind, int $byte_count, string $sha256 ) {
		$this->fail_if( 'open' );
		$stream = fopen( 'php://temp', 'w+b' );
		return $stream;
	}

	public function enumerate_candidates( ?int $older_than_epoch, int $limit = 1000, ?array $cursor = null ): array {
		throw new LogicException( 'Orphan enumeration is outside this test double.' );
	}

	private function fail_if( string $operation ): void {
		if ( 'wrong_digest' === $this->operation || $operation !== $this->operation ) {
			return;
		}
		if ( 'runtime' === $this->reason ) {
			throw new RuntimeException( 'unreviewed failure text' );
		}
		throw new GHCA_ACD_Archive_Artifact_Store_Exception( $this->reason, 'unreviewed artifact failure text' );
	}
}

final class GHCA_P3B1_Prepared_Handler {
	/** @var mixed */
	private $prepared;
	/** @var int */
	public $calls = 0;

	/** @param mixed $prepared */
	public function __construct( $prepared ) {
		$this->prepared = $prepared;
	}

	public function __invoke( array $task, callable $heartbeat ) {
		$this->calls++;
		return $this->prepared;
	}
}

function p3bf_root( string $seed ): string {
	$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca_p3b1_' . substr( hash( 'sha256', 'fail|' . $seed ), 0, 24 );
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create the isolated P3B1 failure root.' );
	}
	return $root;
}

function p3bf_cleanup( string $root ): void {
	$resolved = realpath( $root );
	$base = realpath( sys_get_temp_dir() );
	if ( false === $resolved || false === $base || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR . 'ghca_p3b1_' ) ) {
		throw new RuntimeException( 'P3B1 failure cleanup boundary rejected the requested root.' );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $resolved, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		if ( $entry->isLink() || $entry->isFile() ) {
			unlink( $entry->getPathname() );
		} else {
			rmdir( $entry->getPathname() );
		}
	}
	rmdir( $resolved );
}

/** @return array<string,mixed> */
function p3bf_fixture( $db, string $seed, string $now ): array {
	ghca_persist_fresh_schema( $db );
	$stack = ghca_persist_stack( $db, $now, 'p3b-failure-' . $seed );
	$scenario = new GHCA_Persist_Scenario( 'p3b_failure_' . substr( hash( 'sha256', $seed ), 0, 20 ) );
	persist_request_archive( $stack, $scenario );
	persist_start_build( $stack, $scenario );
	persist_record_snapshot( $stack, $scenario );
	$table = $db->prefix . 'ghca_acd_archive_tasks';
	$task = $db->get_row( $db->prepare(
		"SELECT * FROM {$table} WHERE stream_id = %s AND task_type = 'materialize_ledger'",
		$scenario->stream_id
	), ARRAY_A );
	$build = new GHCA_ACD_Archive_Build_Coordinator(
		$stack['event_store'], $stack['snapshot_store'], $stack['artifact_repository'], $stack['uow']
	);
	return array( 'stack' => $stack, 'scenario' => $scenario, 'task' => $task, 'build' => $build );
}

function p3bf_event_count( $db, string $stream_id, string $type ): int {
	$table = $db->prefix . 'ghca_acd_archive_events';
	return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$table} WHERE stream_id = %s AND event_type = %s", $stream_id, $type ) );
}

function p3bf_authoritative_fingerprint( $db ): string {
	$dump = array();
	$tasks_table = $db->prefix . 'ghca_acd_archive_tasks';
	foreach ( ghca_persist_table_names( $db ) as $table ) {
		if ( $tasks_table === $table ) {
			continue;
		}
		$rows = $db->get_results( 'SELECT * FROM ' . ghca_persist_quote_identifier( $table ), ARRAY_A );
		if ( $db->last_error ) {
			throw new RuntimeException( 'authoritative fingerprint read failed' );
		}
		foreach ( (array) $rows as &$row ) {
			ksort( $row, SORT_STRING );
		}
		unset( $row );
		usort( $rows, static function ( $left, $right ): int {
			return strcmp( json_encode( $left ), json_encode( $right ) );
		} );
		$dump[ $table ] = $rows;
	}
	return hash( 'sha256', json_encode( $dump ) );
}

function p3bf_tree_fingerprint( string $root ): string {
	$entries = array();
	if ( ! is_dir( $root ) ) {
		return hash( 'sha256', 'missing' );
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $entry ) {
		$relative = str_replace( '\\', '/', substr( $entry->getPathname(), strlen( $root ) + 1 ) );
		if ( $entry->isLink() ) {
			$entries[] = array( $relative, 'link', readlink( $entry->getPathname() ) );
		} elseif ( $entry->isFile() ) {
			$entries[] = array( $relative, 'file', $entry->getSize(), hash_file( 'sha256', $entry->getPathname() ) );
		} else {
			$entries[] = array( $relative, 'directory' );
		}
	}
	return hash( 'sha256', json_encode( $entries ) );
}

/**
 * Replace one retained LedgerMaterialized payload value while preserving a
 * valid canonical event envelope and stream head for the targeted probe.
 *
 * @param mixed $value
 */
function p3bf_rehash_ledger_event_payload( $db, GHCA_ACD_Archive_Event_Store $events, string $stream_id, string $field, $value ): void {
	$target = null;
	foreach ( $events->load_events( $stream_id ) as $event ) {
		if ( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED === $event->type() ) {
			$target = $event;
		}
	}
	if ( null === $target ) {
		throw new RuntimeException( 'The LedgerMaterialized test event was not found.' );
	}
	$document = $target->recorded_document();
	$document['payload'][ $field ] = $value;
	unset( $document['event_digest'] );
	$digest = GHCA_ACD_Archive_Digester::event_hash( $document );
	$event_table = $db->prefix . 'ghca_acd_archive_events';
	$stream_table = $db->prefix . 'ghca_acd_archive_streams';
	ghca_persist_query( $db, $db->prepare(
		"UPDATE {$event_table} SET payload_json = %s, event_digest = %s WHERE event_id = %s AND stream_id = %s",
		GHCA_ACD_Archive_Canonical_JSON::encode( $document['payload'] ), $digest, $target->event_id(), $stream_id
	), 'rehash retained LedgerMaterialized payload fixture' );
	ghca_persist_query( $db, $db->prepare(
		"UPDATE {$stream_table} SET head_event_digest = %s WHERE stream_id = %s AND head_sequence = CAST(%s AS UNSIGNED)",
		$digest, $stream_id, $target->stream_sequence()
	), 'rebind retained stream head fixture' );
	$verified = $events->load_events( $stream_id );
	$last = end( $verified );
	if ( false === $last || GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED !== $last->type() || $last->payload()[ $field ] !== $value ) {
		throw new RuntimeException( 'The rehashed LedgerMaterialized fixture did not verify.' );
	}
}

/** @param array<string,mixed> $fixture */
function p3bf_prepare_attempt_five( $db, array $fixture, string $now ): void {
	$table = $db->prefix . 'ghca_acd_archive_tasks';
	ghca_persist_query( $db, $db->prepare(
		"UPDATE {$table} SET task_state = 'retry', attempt_count = 4, last_error_code = 'task_handler_failed', last_error_text = %s WHERE task_id = %s",
		GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'], $fixture['task']['task_id']
	), 'prepare P3B1 attempt-five failure fixture' );
}

/** @param array<string,mixed> $fixture */
function p3bf_worker( array $fixture, $handler, GHCA_ACD_Archive_Ledger_Task_Handler $validator, string $seed ): GHCA_ACD_Archive_Worker_Coordinator {
	return new GHCA_ACD_Archive_Worker_Coordinator(
		$fixture['stack']['task_store'], $fixture['stack']['clock'], new GHCA_Persist_Sequential_Ids( 'p3bf-worker-' . $seed ),
		substr( hash( 'sha256', 'p3bf-owner|' . $seed ), 0, 32 ),
		array( 'materialize_ledger' => $handler ), null, $fixture['build'], $validator
	);
}

// The approved mapping is closed over reason plus category/operation context.
$permanent = static function ( string $code ): array {
	return array( 'kind' => 'permanent', 'task_code' => $code, 'lifecycle_code' => $code );
};
$retryable = static function ( string $code ): array {
	return array( 'kind' => 'retryable', 'task_code' => $code, 'lifecycle_code' => null );
};
$blocked = static function ( string $code ): array {
	return array( 'kind' => 'blocked', 'task_code' => $code, 'lifecycle_code' => null );
};
$repository_binding_reasons = array( 'artifact_binding_invalid', 'artifact_identity_invalid', 'ledger_binding_invalid', 'ledger_snapshot_binding_mismatch' );
$repository_ledger_reasons = array(
	'artifact_descriptor_invalid', 'artifact_digest_invalid', 'artifact_role_type_invalid', 'unsupported_artifact_schema_version',
	'artifact_storage_key_invalid', 'artifact_filename_invalid', 'side_ledger_item_count_exceeded', 'ledger_item_count_mismatch',
	'ledger_item_schema_invalid', 'ledger_duplicate', 'ledger_gap', 'unsupported_ledger_item_schema_version',
	'ledger_item_canonical_invalid', 'ledger_manifest_digest_mismatch',
);
$classification_groups = array(
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'handler', 'reasons' => array( 'archive_build_binding_invalid', 'archive_snapshot_invalid', 'archive_ledger_invalid' ), 'expected_by_reason' => true ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'recovery', 'reasons' => array( 'archive_build_binding_invalid', 'archive_snapshot_invalid', 'archive_ledger_invalid' ), 'expected_by_reason' => true ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'outcome', 'reasons' => array( 'archive_build_binding_invalid', 'archive_snapshot_invalid', 'archive_ledger_invalid' ), 'expected_by_reason' => true ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => array( 'archive_immutable_conflict' ), 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'handler', 'reasons' => array( 'unsupported_snapshot_schema_version', 'unsupported_snapshot_canonical_version', 'snapshot_canonical_invalid', 'snapshot_schema_invalid', 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'recovery', 'reasons' => array( 'unsupported_snapshot_schema_version', 'unsupported_snapshot_canonical_version', 'snapshot_canonical_invalid', 'snapshot_schema_invalid', 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'outcome', 'reasons' => array( 'unsupported_snapshot_schema_version', 'unsupported_snapshot_canonical_version', 'snapshot_canonical_invalid', 'snapshot_schema_invalid', 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'handler', 'reasons' => array( 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'recovery', 'reasons' => array( 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => array( 'snapshot_retained_binding_mismatch' ), 'expected' => $permanent( 'archive_snapshot_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, 'context' => 'handler', 'reasons' => array( 'snapshot_lookup_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, 'context' => 'recovery', 'reasons' => array( 'snapshot_lookup_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, 'context' => 'outcome', 'reasons' => array( 'snapshot_lookup_failed' ), 'expected' => $retryable( 'task_outcome_commit_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'outcome', 'reasons' => $repository_binding_reasons, 'expected' => $permanent( 'archive_build_binding_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => $repository_binding_reasons, 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, 'context' => 'outcome', 'reasons' => $repository_ledger_reasons, 'expected' => $permanent( 'archive_ledger_invalid' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => $repository_ledger_reasons, 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => array( 'artifact_contradictory_duplicate', 'artifact_retained_binding_mismatch', 'artifact_authoritative_binding_mismatch', 'ledger_item_digest_mismatch' ), 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, 'context' => 'recovery', 'reasons' => array( 'artifact_insert_failed', 'artifact_lookup_failed', 'artifact_duplicate_lookup_failed', 'ledger_item_insert_failed', 'ledger_item_load_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, 'context' => 'outcome', 'reasons' => array( 'artifact_insert_failed', 'artifact_lookup_failed', 'artifact_duplicate_lookup_failed', 'ledger_item_insert_failed', 'ledger_item_load_failed' ), 'expected' => $retryable( 'task_outcome_commit_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_key_invalid', 'artifact_path_escape' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_size_exceeded', 'artifact_media_invalid', 'artifact_size_mismatch', 'artifact_digest_mismatch' ), 'expected' => $permanent( 'archive_ledger_invalid' ) ),
	array( 'source' => 'artifact', 'context' => 'authoritative_open', 'reasons' => array( 'artifact_size_mismatch', 'artifact_digest_mismatch' ), 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_commit_collision', 'artifact_immutable_mismatch' ), 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_symlink_rejected' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'authoritative_open', 'reasons' => array( 'artifact_symlink_rejected' ), 'expected' => $permanent( 'archive_immutable_conflict' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_open_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'authoritative_open', 'reasons' => array( 'artifact_open_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_directory_create_failed', 'artifact_write_failed', 'artifact_staging_collision', 'artifact_commit_failed' ), 'expected' => $retryable( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'artifact_atomic_commit_unsupported', 'artifact_permissions_unsafe' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'handler', 'reasons' => array( 'unmapped_store_reason' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'artifact', 'context' => 'outcome', 'reasons' => array( 'unmapped_store_reason' ), 'expected' => $blocked( 'task_outcome_commit_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'handler', 'reasons' => array( 'unmapped_repository_reason' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'persistence', 'category' => GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, 'context' => 'outcome', 'reasons' => array( 'unmapped_repository_reason' ), 'expected' => $blocked( 'task_outcome_commit_failed' ) ),
	array( 'source' => 'throwable', 'context' => 'handler', 'reasons' => array( 'runtime' ), 'expected' => $blocked( 'task_handler_failed' ) ),
	array( 'source' => 'throwable', 'context' => 'outcome', 'reasons' => array( 'runtime' ), 'expected' => $blocked( 'task_outcome_commit_failed' ) ),
);
$worker_without_constructor = ( new ReflectionClass( GHCA_ACD_Archive_Worker_Coordinator::class ) )->newInstanceWithoutConstructor();
$classify = new ReflectionMethod( GHCA_ACD_Archive_Worker_Coordinator::class, 'classify_ledger_failure' );
$classification_failures = array();
$classification_count = 0;
foreach ( $classification_groups as $group ) {
	foreach ( $group['reasons'] as $reason ) {
		if ( 'artifact' === $group['source'] ) {
			$error = new GHCA_ACD_Archive_Artifact_Store_Exception( $reason, 'redacted' );
		} elseif ( 'persistence' === $group['source'] ) {
			$error = new GHCA_ACD_Archive_Persistence_Exception( $group['category'], $reason, 'redacted' );
		} else {
			$error = new RuntimeException( 'redacted' );
		}
		$expected = ! empty( $group['expected_by_reason'] ) ? $permanent( $reason ) : $group['expected'];
		if ( $expected !== $classify->invoke( $worker_without_constructor, $error, $group['context'] ) ) {
			$classification_failures[] = $group['source'] . ':' . $reason . ':' . $group['context'];
		}
		$classification_count++;
	}
}
archive_check(
	107 === $classification_count && array() === $classification_failures,
	'P3B1-FAILURE-MAPPING-CLOSED enumerates all 107 approved source/category/context cases and blocks unmapped failures'
);
$wrong_category = $classify->invoke(
	$worker_without_constructor,
	new GHCA_ACD_Archive_Persistence_Exception(
		GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
		'archive_immutable_conflict',
		'unreviewed wrong-category failure text'
	),
	'handler'
);
archive_check(
	$blocked( 'task_handler_failed' ) === $wrong_category,
	'P3B1-FAILURE-MAPPING-WRONG-CATEGORY-BLOCKED rejects a high-level reason from an unapproved persistence category'
);
$wrong_source = $classify->invoke(
	$worker_without_constructor,
	new GHCA_ACD_Archive_Artifact_Store_Exception( 'archive_ledger_invalid', 'unreviewed wrong-source failure text' ),
	'handler'
);
archive_check(
	$blocked( 'task_handler_failed' ) === $wrong_source,
	'P3B1-FAILURE-MAPPING-WRONG-SOURCE-BLOCKED rejects a high-level reason from the artifact-store source'
);
$wrong_context = $classify->invoke(
	$worker_without_constructor,
	new GHCA_ACD_Archive_Persistence_Exception(
		GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
		'archive_immutable_conflict',
		'unreviewed wrong-context failure text'
	),
	'recovery'
);
archive_check(
	$blocked( 'task_handler_failed' ) === $wrong_context,
	'P3B1-FAILURE-MAPPING-WRONG-CONTEXT-BLOCKED rejects lifecycle mapping during retained-outcome recovery'
);

foreach ( array(
	'WRONG-CATEGORY-BLOCKED' => static function (): Throwable {
		return new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
			'archive_immutable_conflict',
			'unreviewed wrong-category integration secret'
		);
	},
	'WRONG-SOURCE-BLOCKED' => static function (): Throwable {
		return new GHCA_ACD_Archive_Artifact_Store_Exception(
			'archive_ledger_invalid',
			'unreviewed wrong-source integration secret'
		);
	},
) as $case_name => $failure_factory ) {
	$root = p3bf_root( 'mapping-' . strtolower( $case_name ) );
	try {
		$fixture = p3bf_fixture( $wpdb, 'mapping_' . strtolower( str_replace( '-', '_', $case_name ) ), '2026-07-24T17:00:00Z' );
		p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T17:00:00Z' );
		$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
		$validator = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
			$private, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$failure = $failure_factory();
		$raw_text = $failure->getMessage();
		$handler_calls = 0;
		$throwing_handler = static function () use ( $failure, &$handler_calls ) {
			$handler_calls++;
			throw $failure;
		};
		$db_before = p3bf_authoritative_fingerprint( $wpdb );
		$tree_before = p3bf_tree_fingerprint( $root );
		$commands_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
		$result = p3bf_worker( $fixture, $throwing_handler, $validator, 'mapping-' . strtolower( $case_name ) )->run_once();
		$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
		archive_check(
			'dead' === $result['status'] && 'task_handler_failed' === $result['reason_code']
			&& 'task_handler_failed' === $row['last_error_code']
			&& GHCA_ACD_Archive_Worker_Coordinator::FAILURE_MESSAGES['task_handler_failed'] === $row['last_error_text']
			&& false === strpos( json_encode( $row ), $raw_text ) && 1 === $handler_calls
			&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
			&& $commands_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) )
			&& $db_before === p3bf_authoritative_fingerprint( $wpdb )
			&& $tree_before === p3bf_tree_fingerprint( $root ),
			'P3B1-FAILURE-MAPPING-' . $case_name . '-NO-AUTHORITATIVE-RESIDUE sanitizes task disposition with zero lifecycle/UoW/filesystem residue'
		);
	} finally {
		p3bf_cleanup( $root );
	}
}

// Every malformed prepared-result shape is rejected before a Build Coordinator/UoW call.
$prepared_cases = array( 'missing', 'extra', 'nested', 'free_form', 'cyclic', 'oversized', 'mismatched' );
foreach ( $prepared_cases as $case ) {
	$root = p3bf_root( 'prepared-' . $case );
	try {
		$fixture = p3bf_fixture( $wpdb, 'prepared_' . $case, '2026-07-24T18:00:00Z' );
		$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
		$validator = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
			$private, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$task = $fixture['stack']['task_store']->validate_claimed_v1( $fixture['task'] );
		$valid = $validator( $task, static function (): void {} );
		switch ( $case ) {
			case 'missing':
				$invalid = array( 'artifact_descriptor' => $valid['artifact_descriptor'] );
				break;
			case 'extra':
				$invalid = $valid;
				$invalid['extra'] = 'forbidden';
				break;
			case 'nested':
				$invalid = $valid;
				$invalid['artifact_descriptor']['byte_count'] = array( 'nested' => 1 );
				break;
			case 'free_form':
				$invalid = $valid;
				$invalid['artifact_descriptor']['filename'] = 'employee@example.test';
				break;
			case 'cyclic':
				$invalid = array();
				$invalid['artifact_descriptor'] =& $invalid;
				$invalid['ledger_items'] = array();
				break;
			case 'oversized':
				$invalid = $valid;
				$invalid['ledger_items'] = array_fill( 0, GHCA_ACD_Archive_Ledger_Materializer::MAX_LEDGER_ITEMS + 1, null );
				break;
			default:
				$invalid = $valid;
				$invalid['artifact_descriptor']['content_digest'] = str_repeat( '0', 64 );
		}
		$fake = new GHCA_P3B1_Prepared_Handler( $invalid );
		$before_events = p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' );
		$before_commands = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
		$result = p3bf_worker( $fixture, $fake, $validator, 'prepared-' . $case )->run_once();
		$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
		archive_check(
			'dead' === $result['status'] && 'task_prepared_result_invalid' === $result['reason_code']
			&& 'task_prepared_result_invalid' === $row['last_error_code'] && 1 === $fake->calls
			&& $before_events === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
			&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
			&& $before_commands === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) ),
			'P3B1-PREPARED-RESULT-INVALID-BEFORE-UOW-' . strtoupper( $case )
		);
	} finally {
		p3bf_cleanup( $root );
	}
}

// A canonical, digest-valid retained event must still rebind exactly to the deterministic ledger.
foreach ( array(
	'EVENT-ITEM-COUNT-MISMATCH' => array( 'field' => 'item_count', 'value' => static function ( array $prepared ) {
		return count( $prepared['ledger_items'] ) + 1;
	} ),
	'EVENT-MANIFEST-MISMATCH' => array( 'field' => 'manifest_digest', 'value' => static function ( array $prepared ) {
		return str_repeat( 'f', 64 );
	} ),
	'EVENT-ARTIFACT-BINDING-MISMATCH' => array( 'field' => 'ledger_artifact_id', 'value' => static function ( array $prepared ) {
		return str_repeat( 'e', 32 );
	} ),
) as $case_name => $tamper ) {
	$root = p3bf_root( 'recovery-' . strtolower( $case_name ) );
	try {
		$fixture = p3bf_fixture( $wpdb, 'recovery_' . strtolower( str_replace( '-', '_', $case_name ) ), '2026-07-24T18:30:00Z' );
		$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
		$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
			$private, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$owner = substr( hash( 'sha256', 'recovery-owner|' . $case_name ), 0, 32 );
		$token = substr( hash( 'sha256', 'recovery-token|' . $case_name ), 0, 32 );
		$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-24T18:30:00Z', array( 'materialize_ledger' ) );
		$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
			$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T18:30:00Z' )
		);
		$prepared = $handler( $claimed, static function (): void {} );
		$key = GHCA_ACD_Archive_Digester::task_outcome( array(
			'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
		) );
		$fixture['build']->record_ledger(
			$claimed,
			$prepared,
			$key,
			array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token )
		);
		p3bf_rehash_ledger_event_payload(
			$wpdb,
			$fixture['stack']['event_store'],
			$fixture['scenario']->stream_id,
			$tamper['field'],
			$tamper['value']( $prepared )
		);
		$db_before = ghca_persist_db_fingerprint( $wpdb );
		$tree_before = p3bf_tree_fingerprint( $root );
		$commands_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
		$caught = null;
		$recovered = null;
		try {
			$recovered = $handler->recover_authoritative_prepared( $claimed );
		} catch ( Throwable $error ) {
			$caught = $error;
		}
		$row = $fixture['stack']['task_store']->find( $claimed['task_id'] );
		archive_check(
			null === $recovered && null !== $caught
			&& GHCA_ACD_Archive_Persistence_Exception::class === get_class( $caught )
			&& GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $caught->category()
			&& 'archive_immutable_conflict' === $caught->reason_code()
			&& 'leased' === $row['task_state']
			&& 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
			&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
			&& $commands_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) )
			&& $db_before === ghca_persist_db_fingerprint( $wpdb )
			&& $tree_before === p3bf_tree_fingerprint( $root ),
			'P3B1-RECOVERY-' . $case_name . ' rejects a structurally valid contradictory event with exact immutable-integrity failure and zero recovery residue'
		);
	} finally {
		p3bf_cleanup( $root );
	}
}

// A still-failing retryable operation on attempt five records exhaustion exactly once.
$fixture = p3bf_fixture( $wpdb, 'attempt5_exhausted', '2026-07-24T19:00:00Z' );
p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T19:00:00Z' );
$failing_store = new GHCA_P3B1_Failure_Store( 'create', 'artifact_open_failed' );
$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
	$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
	$failing_store, new GHCA_ACD_Archive_Ledger_Materializer()
);
$result = p3bf_worker( $fixture, $handler, $handler, 'attempt5-exhausted' )->run_once();
$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
$events = $fixture['stack']['event_store']->load_events( $fixture['scenario']->stream_id );
$failure_code = null;
foreach ( $events as $event ) {
	if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type() ) {
		$failure_code = $event->payload()['failure_code'];
	}
}
archive_check(
	'dead' === $result['status'] && 'task_attempts_exhausted' === $result['reason_code']
	&& 'task_attempts_exhausted' === $row['last_error_code'] && 2 === $failing_store->calls
	&& 'archive_build_attempts_exhausted' === $failure_code
	&& 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	'P3B1-ATTEMPT5-EXHAUSTED-NO-PRIOR-OUTCOME commits exhaustion only after final deterministic recovery fails'
);

// Unknown and containment/symlink operational failures never invent lifecycle facts.
foreach ( array(
	'UNKNOWN-FAILURE-NO-LIFECYCLE-INVENTION' => array( 'create', 'runtime' ),
	'DERIVED-PATH-ESCAPE-NO-LIFECYCLE'       => array( 'key', 'artifact_path_escape' ),
	'STAGING-SYMLINK-NO-LIFECYCLE'           => array( 'create', 'artifact_symlink_rejected' ),
) as $case_name => $failure ) {
	$fixture = p3bf_fixture( $wpdb, strtolower( str_replace( '-', '_', $case_name ) ), '2026-07-24T20:00:00Z' );
	p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T20:00:00Z' );
	$store = new GHCA_P3B1_Failure_Store( $failure[0], $failure[1] );
	$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
		$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
		$store, new GHCA_ACD_Archive_Ledger_Materializer()
	);
	$result = p3bf_worker( $fixture, $handler, $handler, strtolower( $case_name ) )->run_once();
	$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
	archive_check(
		'dead' === $result['status'] && 'task_handler_failed' === $result['reason_code']
		&& 'task_handler_failed' === $row['last_error_code']
		&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
		'P3B1-' . $case_name
	);
}

// Newly prepared byte mismatch is a permanent ledger failure, never exhaustion.
$fixture = p3bf_fixture( $wpdb, 'ledger_invalid_preserved', '2026-07-24T21:00:00Z' );
p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T21:00:00Z' );
$store = new GHCA_P3B1_Failure_Store( 'wrong_digest', 'artifact_digest_mismatch' );
$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
	$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
	$store, new GHCA_ACD_Archive_Ledger_Materializer()
);
$result = p3bf_worker( $fixture, $handler, $handler, 'ledger-invalid' )->run_once();
$events = $fixture['stack']['event_store']->load_events( $fixture['scenario']->stream_id );
$failure_code = null;
foreach ( $events as $event ) {
	if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type() ) {
		$failure_code = $event->payload()['failure_code'];
	}
}
archive_check(
	'dead' === $result['status'] && 'archive_ledger_invalid' === $result['reason_code']
	&& 'archive_ledger_invalid' === $failure_code,
	'P3B1-ATTEMPT5-LEDGER-INVALID-PRESERVED never converts deterministic invalid bytes to exhaustion'
);

// Occupied committed identity is a positively proven immutable conflict.
$fixture = p3bf_fixture( $wpdb, 'immutable_conflict_preserved', '2026-07-24T22:00:00Z' );
p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T22:00:00Z' );
$store = new GHCA_P3B1_Failure_Store( 'commit', 'artifact_commit_collision' );
$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
	$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
	$store, new GHCA_ACD_Archive_Ledger_Materializer()
);
$result = p3bf_worker( $fixture, $handler, $handler, 'immutable-conflict' )->run_once();
$events = $fixture['stack']['event_store']->load_events( $fixture['scenario']->stream_id );
$failure_code = null;
foreach ( $events as $event ) {
	if ( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED === $event->type() ) {
		$failure_code = $event->payload()['failure_code'];
	}
}
archive_check(
	'dead' === $result['status'] && 'archive_immutable_conflict' === $result['reason_code']
	&& 'archive_immutable_conflict' === $failure_code,
	'P3B1-ATTEMPT5-IMMUTABLE-CONFLICT-PRESERVED never converts a committed collision to exhaustion'
);

// Equal task-outcome input remains separated by command type and scope without creating success-then-failure history.
$root = p3bf_root( 'cross-command-dedupe' );
try {
	$id_counter = new ReflectionProperty( GHCA_Persist_Sequential_Ids::class, 'counter' );
	$counter_start = $id_counter->getValue();
	$fixture = p3bf_fixture( $wpdb, 'cross_command_dedupe', '2026-07-24T22:30:00Z' );
	$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
	$handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
		$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
		$private, new GHCA_ACD_Archive_Ledger_Materializer()
	);
	$owner = substr( hash( 'sha256', 'cross-command-owner' ), 0, 32 );
	$token = substr( hash( 'sha256', 'cross-command-record-token' ), 0, 32 );
	$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-24T22:30:00Z', array( 'materialize_ledger' ) );
	$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
		$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T22:30:00Z' )
	);
	$outcome_key = GHCA_ACD_Archive_Digester::task_outcome( array(
		'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
	) );
	$fixture['build']->record_ledger(
		$claimed,
		$handler( $claimed, static function (): void {} ),
		$outcome_key,
		array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token )
	);
	$command_table = $wpdb->prefix . 'ghca_acd_archive_commands';
	$record_receipt = $wpdb->get_row( $wpdb->prepare(
		"SELECT dedupe_digest, idempotency_scope_digest, idempotency_key_digest FROM {$command_table}
		 WHERE stream_id = %s AND command_type = 'RecordMaterializedArtifact'",
		$fixture['scenario']->stream_id
	), ARRAY_A );
	$record_task_id = $claimed['task_id'];

	$id_counter->setValue( null, $counter_start );
	$fixture = p3bf_fixture( $wpdb, 'cross_command_dedupe', '2026-07-24T22:30:00Z' );
	$token = substr( hash( 'sha256', 'cross-command-failure-token' ), 0, 32 );
	$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-24T22:30:00Z', array( 'materialize_ledger' ) );
	$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
		$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T22:30:00Z' )
	);
	$failure_key = GHCA_ACD_Archive_Digester::task_outcome( array(
		'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
	) );
	$fixture['build']->fail_archive(
		$claimed,
		'archive_immutable_conflict',
		$failure_key,
		array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token )
	);
	$failure_receipt = $wpdb->get_row( $wpdb->prepare(
		"SELECT dedupe_digest, idempotency_scope_digest, idempotency_key_digest FROM {$command_table}
		 WHERE stream_id = %s AND command_type = 'FailArchive'",
		$fixture['scenario']->stream_id
	), ARRAY_A );
	$cross_details = array(
		'task'       => $record_task_id === $claimed['task_id'],
		'outcome'    => $outcome_key === $failure_key,
		'record'     => is_array( $record_receipt ),
		'failure'    => is_array( $failure_receipt ),
		'key'        => is_array( $record_receipt ) && is_array( $failure_receipt ) && $record_receipt['idempotency_key_digest'] === $failure_receipt['idempotency_key_digest'],
		'scope'      => is_array( $record_receipt ) && is_array( $failure_receipt ) && $record_receipt['idempotency_scope_digest'] !== $failure_receipt['idempotency_scope_digest'],
		'dedupe'     => is_array( $record_receipt ) && is_array( $failure_receipt ) && $record_receipt['dedupe_digest'] !== $failure_receipt['dedupe_digest'],
		'no_ledger'  => 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' ),
		'one_failed' => 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
	);
	archive_check(
		! in_array( false, $cross_details, true ),
		'P3B1-CROSS-COMMAND-DEDUPE-DISTINCT proves command-type/scope separation without success-then-failure history'
	);
} finally {
	p3bf_cleanup( $root );
}

// Authoritative response-loss open failures remain operational; retained byte mismatches remain immutable conflicts.
foreach ( array(
	'AUTHORITATIVE-OPEN-FAILURE-NOT-IMMUTABLE' => 'artifact_open_failed',
	'COMMITTED-MISMATCH-IS-IMMUTABLE'         => 'artifact_digest_mismatch',
) as $case_name => $store_reason ) {
	$root = p3bf_root( 'authoritative-' . strtolower( $case_name ) );
	try {
		$fixture = p3bf_fixture( $wpdb, 'authoritative_' . strtolower( str_replace( '-', '_', $case_name ) ), '2026-07-24T23:00:00Z' );
		p3bf_prepare_attempt_five( $wpdb, $fixture, '2026-07-24T23:00:00Z' );
		$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
		$writer = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
			$private, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$owner = substr( hash( 'sha256', 'authoritative-owner|' . $case_name ), 0, 32 );
		$token = substr( hash( 'sha256', 'authoritative-token|' . $case_name ), 0, 32 );
		$claimed = $fixture['stack']['task_store']->claim_available( $owner, $token, '2026-07-24T23:00:00Z', array( 'materialize_ledger' ) );
		$claimed = $fixture['stack']['task_store']->validate_claimed_v1(
			$fixture['stack']['task_store']->load_claimed( $claimed['task_id'], $owner, $token, '2026-07-24T23:00:00Z' )
		);
		$prepared = $writer( $claimed, static function (): void {} );
		$prepared = $writer->validate_prepared_result( $claimed, $prepared );
		$key = GHCA_ACD_Archive_Digester::task_outcome( array(
			'logical_outcome' => 'completed', 'task_id' => $claimed['task_id'], 'task_schema_version' => 1,
		) );
		$fixture['build']->record_ledger(
			$claimed,
			$prepared,
			$key,
			array( 'task_id' => $claimed['task_id'], 'lease_owner' => $owner, 'lease_token' => $token )
		);
		$commands_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) );
		$fixture['stack']['clock']->set( '2026-07-24T23:02:00Z' );
		$failing = new GHCA_P3B1_Failure_Store( 'open', $store_reason );
		$reader = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
			$failing, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$result = p3bf_worker( $fixture, $reader, $reader, 'authoritative-' . strtolower( $case_name ) )->run_once();
		$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
		if ( 'artifact_open_failed' === $store_reason ) {
			archive_check(
				'dead' === $result['status'] && 'task_handler_failed' === $result['reason_code']
				&& 'task_handler_failed' === $row['last_error_code']
				&& 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
				&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
				&& $commands_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) ),
				'P3B1-' . $case_name . ' is retryable before attempt five and never invents ArchiveFailed after response loss'
			);
		} else {
			archive_check(
				'dead' === $result['status'] && 'task_handler_failed' === $result['reason_code']
				&& 'task_handler_failed' === $row['last_error_code']
				&& 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
				&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' )
				&& $commands_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ghca_persist_quote_identifier( $wpdb->prefix . 'ghca_acd_archive_commands' ) ),
				'P3B1-' . $case_name . ' blocks retained-byte contradiction without appending a lifecycle event after materialization'
			);
		}
	} finally {
		p3bf_cleanup( $root );
	}
}

// Interleave authoritative materialization with the first failure submission; refreshed success must win.
$root = p3bf_root( 'materialization-race' );
try {
	$fixture = p3bf_fixture( $wpdb, 'materialization_race', '2026-07-25T00:00:00Z' );
	$private = new GHCA_ACD_Private_Archive_Artifact_Store( $root, array( ABSPATH ), GHCA_P3B1_FAILURE_CURSOR_KEY );
	$validator = new GHCA_ACD_Archive_Ledger_Task_Handler(
		$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
		$private, new GHCA_ACD_Archive_Ledger_Materializer()
	);
	$task = $fixture['stack']['task_store']->validate_claimed_v1( $fixture['task'] );
	$prepared = $validator( $task, static function (): void {} );
	$outcome_key = GHCA_ACD_Archive_Digester::task_outcome( array(
		'logical_outcome' => 'completed', 'task_id' => $task['task_id'], 'task_schema_version' => 1,
	) );
	$external_db = ghca_persist_new_connection();
	$external_stack = ghca_persist_stack( $external_db, '2026-07-25T00:00:00Z', 'p3bf-race-external' );
	$external_build = new GHCA_ACD_Archive_Build_Coordinator(
		$external_stack['event_store'], $external_stack['snapshot_store'], $external_stack['artifact_repository'], $external_stack['uow']
	);
	$proxy = new GHCA_Persist_DB_Proxy( $wpdb );
	$proxy_stack = ghca_persist_stack( $proxy, '2026-07-25T00:00:00Z', 'p3bf-race-failure' );
	$fixture['build'] = new GHCA_ACD_Archive_Build_Coordinator(
		$proxy_stack['event_store'], $proxy_stack['snapshot_store'], $proxy_stack['artifact_repository'], $proxy_stack['uow']
	);
	$proxy->add_hook( 'get_row', "task_state = 'leased'", static function () use ( $external_db, $external_stack, $external_build, $prepared, $outcome_key, $task ): void {
		$table = $external_db->prefix . 'ghca_acd_archive_tasks';
		$claimed = $external_db->get_row( $external_db->prepare( "SELECT * FROM {$table} WHERE task_id = %s", $task['task_id'] ), ARRAY_A );
		$claimed = $external_stack['task_store']->validate_claimed_v1( $claimed );
		$external_build->record_ledger(
			$claimed,
			$prepared,
			$outcome_key,
			array( 'task_id' => $claimed['task_id'], 'lease_owner' => $claimed['lease_owner'], 'lease_token' => $claimed['lease_token'] )
		);
	}, 1 );
	$failing_store = new GHCA_P3B1_Failure_Store( 'commit', 'artifact_commit_collision' );
	$failing_handler = new GHCA_ACD_Archive_Ledger_Task_Handler(
		$fixture['stack']['event_store'], $fixture['stack']['snapshot_store'], $fixture['stack']['artifact_repository'],
		$failing_store, new GHCA_ACD_Archive_Ledger_Materializer()
	);
	$result = p3bf_worker( $fixture, $failing_handler, $validator, 'materialization-race' )->run_once();
	$row = $fixture['stack']['task_store']->find( $fixture['task']['task_id'] );
	archive_check(
		'completed' === $result['status'] && 'completed' === $row['task_state']
		&& 1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
		&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
		'P3B1-MATERIALIZATION-RACES-FAILURE-SUBMISSION reloads after conflict and lets authoritative materialization win'
	);
	archive_check(
		1 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'LedgerMaterialized' )
		&& 0 === p3bf_event_count( $wpdb, $fixture['scenario']->stream_id, 'ArchiveFailed' ),
		'P3B1-NO-SUCCESS-THEN-ARCHIVE-FAILED prevents stale failure intent from following matching materialization'
	);
} finally {
	p3bf_cleanup( $root );
}

archive_finish();
