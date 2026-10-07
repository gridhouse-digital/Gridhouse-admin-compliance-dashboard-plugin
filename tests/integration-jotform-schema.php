<?php
/** Disposable MySQL integration check for the additive Jotform schema. */

$search = __DIR__;
$wp_root = '';
for ( $i = 0; $i < 8; $i++ ) {
	$search = dirname( $search );
	if ( is_file( $search . '/wp-load.php' ) ) { $wp_root = $search; break; }
}
if ( '' === $wp_root || ! extension_loaded( 'mysqli' ) || ! is_file( $wp_root . '/wp-includes/class-wpdb.php' ) ) {
	echo "SKIP: WordPress wpdb or mysqli is unavailable\n";
	exit( 0 );
}

define( 'ABSPATH', $wp_root . '/' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'MINUTE_IN_SECONDS', 60 );

$schema_options = array();
$dbdelta_block_column = '';
function absint( $value ) { return abs( (int) $value ); }
function has_filter() { return false; }
function add_filter() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function is_multisite() { return false; }
function is_wp_error() { return false; }
function mbstring_binary_safe_encoding() {}
function reset_mbstring_encoding() {}
function get_option( $key, $default = false ) { global $schema_options; return array_key_exists( $key, $schema_options ) ? $schema_options[ $key ] : $default; }
function update_option( $key, $value ) { global $schema_options; $schema_options[ $key ] = $value; return true; }
function current_time( $type, $gmt = false ) { return '2026-08-26 12:00:00'; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return basename( str_replace( '\\', '/', (string) $value ) ); }
function sanitize_mime_type( $value ) { return strtolower( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_userdata( $id = 0 ) { return 1 === (int) $id ? (object) array( 'ID' => 1 ) : false; }
function wp_cache_flush() { return true; }
function dbDelta( $statement ) {
	global $wpdb, $dbdelta_block_column;
	$result = $wpdb->query( preg_replace( '/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $statement ) );
	if ( ! preg_match( '/^CREATE TABLE\s+([^\s(]+)/i', $statement, $match ) ) { return $result; }
	$table = $match[1];
	$columns = array(
		'evidence_reference' => "varchar(128) NOT NULL DEFAULT ''",
		'warning_detail' => 'varchar(255) NULL',
		'review_reason' => 'text NULL',
		'oltl_requirements' => 'text NULL',
	);
	foreach ( $columns as $column => $definition ) {
		if ( false === strpos( $statement, $column ) || $dbdelta_block_column === $column ) { continue; }
		$exists = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );
		if ( ! is_array( $exists ) ) { $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); }
	}
	return $result;
}
function wp_load_translations_early() {}
function wp_die( $message = '' ) { throw new RuntimeException( strip_tags( (string) $message ) ); }
require_once $wp_root . '/wp-includes/class-wpdb.php';

$port = (int) ( getenv( 'GHCA_TEST_MYSQL_PORT' ) ?: 3306 );
mysqli_report( MYSQLI_REPORT_OFF );
$server = @new mysqli( '127.0.0.1', 'root', '', '', $port );
if ( $server->connect_errno ) {
	echo "SKIP: disposable local MySQL is unavailable\n";
	exit( 0 );
}

$database = 'ghca_jotform_test_' . getmypid() . '_' . bin2hex( random_bytes( 3 ) );
if ( ! $server->query( "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
	echo "SKIP: disposable database could not be created\n";
	exit( 0 );
}

$wpdb = new wpdb( 'root', '', $database, '127.0.0.1:' . $port );
$wpdb->set_prefix( 'wp_' );
$wpdb->query( "CREATE TABLE {$wpdb->options} (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,option_name varchar(191) NOT NULL,option_value longtext NOT NULL,autoload varchar(20) NOT NULL DEFAULT 'no',PRIMARY KEY(option_id),UNIQUE KEY option_name(option_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
require_once __DIR__ . '/../includes/jotform/class-jotform-schema.php';
require_once __DIR__ . '/../includes/jotform/class-jotform-repository.php';

$fails = 0;
function jotform_schema_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

try {
	jotform_schema_check( GHCA_ACD_Jotform_Schema::install(), 'Jotform schema installs through WordPress dbDelta' );
	jotform_schema_check( GHCA_ACD_Jotform_Schema::tables_exist(), 'all five Jotform and external-evidence tables are verified' );
	$engines = $wpdb->get_col( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$database}' AND TABLE_NAME LIKE 'wp_ghca_%'" );
	jotform_schema_check( 5 === count( $engines ) && array() === array_values( array_filter( $engines, static function ( $engine ) { return 'innodb' !== strtolower( (string) $engine ); } ) ), 'all workflow and evidence tables use transactional InnoDB storage' );

	$now = '2026-08-26 12:00:00';
	$training = GHCA_ACD_Jotform_Schema::training_table();
	$documents = GHCA_ACD_Jotform_Schema::documents_table();
	$evidence = GHCA_ACD_Jotform_Schema::evidence_table();
	$catalog = GHCA_ACD_Jotform_Schema::catalog_table();
	$rows = array(
		array( 1, 'approved', '111111', str_repeat( 'a', 64 ) ),
		array( 2, 'pending', '222222', str_repeat( 'b', 64 ) ),
		array( 3, 'rejected', '333333', str_repeat( 'c', 64 ) ),
	);
	foreach ( $rows as $index => $row ) {
		$wpdb->insert( $training, array( 'employee_user_id' => $row[0], 'form_id' => '123456', 'submission_id' => $row[2], 'source_fingerprint' => $row[3], 'catalog_id' => 9, 'proposed_code' => 'lesson', 'proposed_title' => 'Lesson', 'proposed_completion_date' => '2026-08-20', 'status' => $row[1], 'warning_code' => null, 'created_at' => $now, 'updated_at' => $now ) );
		$training_id = (int) $wpdb->insert_id;
		$wpdb->insert( $documents, array( 'employee_user_id' => $row[0], 'form_id' => '123456', 'submission_id' => $row[2], 'source_fingerprint' => $row[3], 'question_id' => '4', 'file_index' => 0, 'file_name' => 'certificate.pdf', 'media_type' => 'application/pdf', 'source_created_at' => $now, 'source_updated_at' => $now, 'availability' => 'available', 'created_at' => $now, 'updated_at' => $now ) );
		if ( 'pending' !== $row[1] ) {
			$wpdb->insert( $evidence, array( 'training_id' => $training_id, 'revision_no' => 1, 'status' => 'approved', 'catalog_snapshot' => '{}', 'evidence_manifest' => '[]', 'snapshot_digest' => hash( 'sha256', "{}\n[]" ), 'approved_by' => 99, 'approved_at' => $now ) );
			$wpdb->update( $training, array( 'approved_revision_id' => (int) $wpdb->insert_id ), array( 'id' => $training_id ) );
		}
	}

	$wpdb->query( "ALTER TABLE {$documents} DROP COLUMN evidence_reference" );
	$wpdb->query( "ALTER TABLE {$training} DROP COLUMN warning_detail, DROP COLUMN review_reason, DROP COLUMN entered_by, DROP COLUMN entry_reason, DROP COLUMN manual_manifest" );
	$wpdb->query( "ALTER TABLE {$catalog} DROP COLUMN oltl_requirements" );
	update_option( GHCA_ACD_Jotform_Schema::OPTION_VERSION, 1, false );
	wp_cache_flush();
	jotform_schema_check( GHCA_ACD_Jotform_Schema::install() && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$documents} LIKE 'evidence_reference'", ARRAY_A ) ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$training} LIKE 'warning_detail'", ARRAY_A ) ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$training} LIKE 'review_reason'", ARRAY_A ) ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$training} LIKE 'manual_manifest'", ARRAY_A ) ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$training} LIKE 'entered_by'", ARRAY_A ) ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$catalog} LIKE 'oltl_requirements'", ARRAY_A ) ), 'actual legacy five-table schema upgrades every required column' );
	jotform_schema_check( 6 === (int) get_option( GHCA_ACD_Jotform_Schema::OPTION_VERSION, 0 ), 'schema version advances only after verified v6 columns exist' );
	$wpdb->query( "ALTER TABLE {$catalog} DROP COLUMN oltl_requirements" );
	update_option( GHCA_ACD_Jotform_Schema::OPTION_VERSION, 4, false );
	$dbdelta_block_column = 'oltl_requirements';
	jotform_schema_check( ! GHCA_ACD_Jotform_Schema::install() && 4 === (int) get_option( GHCA_ACD_Jotform_Schema::OPTION_VERSION, 0 ), 'partial v6 column migration failure blocks version advancement' );
	$dbdelta_block_column = '';
	jotform_schema_check( GHCA_ACD_Jotform_Schema::install() && 6 === (int) get_option( GHCA_ACD_Jotform_Schema::OPTION_VERSION, 0 ) && is_array( $wpdb->get_row( "SHOW COLUMNS FROM {$catalog} LIKE 'oltl_requirements'", ARRAY_A ) ), 'restored v6 column migration recovers and then advances version' );
	$training_rows = $wpdb->get_results( "SELECT employee_user_id,status,warning_code FROM {$training} ORDER BY id", ARRAY_A );
	jotform_schema_check( 1 === (int) $training_rows[0]['employee_user_id'] && 0 === (int) $training_rows[1]['employee_user_id'] && 0 === (int) $training_rows[2]['employee_user_id'], 'approved ownership is retained while pending and rejected legacy ownership is quarantined' );
	jotform_schema_check( 'ownership_claim_required' === $training_rows[1]['warning_code'] && 'ownership_claim_required' === $training_rows[2]['warning_code'], 'quarantined legacy workflow rows require explicit ownership review' );
	$document_owners = array_map( 'intval', $wpdb->get_col( "SELECT employee_user_id FROM {$documents} ORDER BY id" ) );
	jotform_schema_check( array( 1, 0, 0 ) === $document_owners, 'ordinary document links survive only when backed by a previously reviewed lifecycle' );
	jotform_schema_check( 1 === (int) get_option( GHCA_ACD_Jotform_Schema::OPTION_LEGACY_REVIEW, 0 ), 'inconsistent rejected training with approved evidence is counted for owner review' );
	jotform_schema_check( GHCA_ACD_Jotform_Schema::install(), 'schema reinstall remains idempotent' );

	$current_count_before = GHCA_ACD_Jotform_Repository::document_count( 1 );
	$current_revision_rows = array(
		array( str_repeat( '1', 64 ), '2026-08-27 10:00:00', 'old-file.pdf', '1', 0 ),
		array( str_repeat( '2', 64 ), '2026-08-28 10:00:00', 'current-file-a.pdf', '2', 0 ),
		array( str_repeat( '2', 64 ), '2026-08-28 10:00:00', 'current-file-b.pdf', '3', 1 ),
	);
	foreach ( $current_revision_rows as $row ) {
		$wpdb->insert( $documents, array( 'employee_user_id' => 1, 'form_id' => 'CURRENT-REVISION', 'submission_id' => 'CURRENT-SUBMISSION', 'source_fingerprint' => $row[0], 'question_id' => $row[3], 'file_index' => $row[4], 'form_title' => 'Current revision test', 'field_label' => 'Certificate', 'file_name' => $row[2], 'media_type' => 'application/pdf', 'source_created_at' => $row[1], 'source_updated_at' => $row[1], 'availability' => 'available', 'created_at' => $now, 'updated_at' => $now ) );
	}
	foreach ( array( str_repeat( '1', 64 ), str_repeat( '2', 64 ) ) as $fingerprint ) {
		$wpdb->insert( $training, array( 'employee_user_id' => 1, 'form_id' => 'CURRENT-REVISION', 'submission_id' => 'CURRENT-SUBMISSION', 'source_fingerprint' => $fingerprint, 'catalog_id' => 9, 'proposed_code' => 'current-revision', 'proposed_title' => 'Current revision test', 'proposed_completion_date' => '2026-08-20', 'status' => 'pending', 'warning_code' => null, 'warning_detail' => null, 'created_at' => $now, 'updated_at' => $now ) );
	}
	$current_search = GHCA_ACD_Jotform_Repository::search_documents_for_employee( 1, 'current-file', 1, 0 );
	$current_page_two = GHCA_ACD_Jotform_Repository::search_documents_for_employee( 1, 'current-file', 1, 1 );
	$old_search = GHCA_ACD_Jotform_Repository::search_documents_for_employee( 1, 'old-file', 10, 0 );
	$current_names = array_map( static function ( array $row ): string { return (string) $row['file_name']; }, array_filter( GHCA_ACD_Jotform_Repository::documents_for_employee( 1, 100 ), static function ( array $row ): bool { return 'CURRENT-REVISION' === (string) $row['form_id']; } ) );
	sort( $current_names );
	jotform_schema_check( array( 'current-file-a.pdf', 'current-file-b.pdf' ) === $current_names && $current_count_before + 2 === GHCA_ACD_Jotform_Repository::document_count( 1 ) && 2 === (int) $current_search['total'] && 2 === (int) $current_page_two['total'] && 0 === (int) $old_search['total'] && (int) $current_search['items'][0]['id'] !== (int) $current_page_two['items'][0]['id'], 'employee document list, count, search and pagination retain every current-revision file while excluding the older revision' );

	$zero_before = GHCA_ACD_Jotform_Repository::document_count( 1 );
	$zero_old = array( 'employee_user_id' => 1, 'form_id' => 'ZERO-REVISION', 'submission_id' => 'ZERO-SUBMISSION', 'source_fingerprint' => str_repeat( '3', 64 ), 'question_id' => '4', 'file_index' => 0, 'form_title' => 'Zero revision test', 'field_label' => 'Certificate', 'file_name' => 'removed-certificate.pdf', 'media_type' => 'application/pdf', 'source_created_at' => '2026-08-27 10:00:00', 'source_updated_at' => '2026-08-27 10:00:00', 'availability' => 'available' );
	$zero_old_candidate = array( 'employee_user_id' => 1, 'form_id' => 'ZERO-REVISION', 'submission_id' => 'ZERO-SUBMISSION', 'source_fingerprint' => str_repeat( '3', 64 ), 'catalog_id' => 9, 'proposed_code' => 'zero-revision', 'proposed_title' => 'Zero revision test', 'proposed_completion_date' => '2026-08-20', 'warning_code' => '', 'warning_detail' => '' );
	$zero_new_candidate = $zero_old_candidate;
	$zero_new_candidate['employee_user_id'] = 0;
	$zero_new_candidate['source_fingerprint'] = str_repeat( '4', 64 );
	$zero_new_candidate['warning_code'] = 'ownership_claim_missing';
	$zero_old_persisted = GHCA_ACD_Jotform_Repository::persist_indexed_revision( array( $zero_old ), $zero_old_candidate );
	$zero_old_training = $wpdb->get_row( "SELECT * FROM {$training} WHERE form_id='ZERO-REVISION' AND submission_id='ZERO-SUBMISSION' AND source_fingerprint='" . str_repeat( '3', 64 ) . "'", ARRAY_A );
	$zero_new_persisted = GHCA_ACD_Jotform_Repository::persist_indexed_revision( array(), $zero_new_candidate );
	$zero_visible = array_values( array_filter( GHCA_ACD_Jotform_Repository::documents_for_employee( 1, 100 ), static function ( array $row ): bool { return 'ZERO-REVISION' === (string) $row['form_id']; } ) );
	$zero_search = GHCA_ACD_Jotform_Repository::search_documents_for_employee( 1, 'removed-certificate', 10, 0 );
	$zero_historical = is_array( $zero_old_training ) ? GHCA_ACD_Jotform_Repository::documents_for_training( $zero_old_training ) : array();
	jotform_schema_check( $zero_old_persisted && $zero_new_persisted && is_array( $zero_old_training ) && $zero_before === GHCA_ACD_Jotform_Repository::document_count( 1 ) && array() === $zero_visible && 0 === (int) $zero_search['total'] && 1 === count( $zero_historical ) && 'removed-certificate.pdf' === (string) $zero_historical[0]['file_name'], 'newer zero-document source revision hides employee metadata while revision-exact historical training review retains the old file' );

	$document = array( 'employee_user_id' => 0, 'form_id' => '654321', 'submission_id' => '900001', 'source_fingerprint' => str_repeat( 'd', 64 ), 'evidence_reference' => 'FT-000001', 'question_id' => '4', 'file_index' => 0, 'form_title' => 'External Training', 'field_label' => 'Certificate', 'file_name' => 'certificate.pdf', 'media_type' => 'application/pdf', 'file_size' => 100, 'source_created_at' => $now, 'source_updated_at' => $now, 'availability' => 'available' );
	$candidate = array( 'employee_user_id' => 0, 'form_id' => '654321', 'submission_id' => '900001', 'source_fingerprint' => str_repeat( 'd', 64 ), 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Fatal Five Lesson', 'proposed_completion_date' => '2026-08-20', 'warning_code' => 'ownership_claim_missing', 'warning_detail' => '' );
	jotform_schema_check( GHCA_ACD_Jotform_Repository::persist_indexed_revision( array( $document ), $candidate ), 'real repository persists document and training in one transaction' );
	$persisted_training = $wpdb->get_row( "SELECT catalog_id,proposed_title,status,created_at,updated_at FROM {$training} WHERE form_id='654321' AND submission_id='900001'", ARRAY_A );
	$persisted_document = $wpdb->get_row( "SELECT evidence_reference FROM {$documents} WHERE form_id='654321' AND submission_id='900001'", ARRAY_A );
	jotform_schema_check( is_array( $persisted_training ) && 9 === (int) $persisted_training['catalog_id'] && 'Fatal Five Lesson' === $persisted_training['proposed_title'] && 'pending' === $persisted_training['status'] && $now === $persisted_training['created_at'] && $now === $persisted_training['updated_at'] && is_array( $persisted_document ) && 'FT-000001' === $persisted_document['evidence_reference'], 'real persistence preserves typed training fields, timestamps and document evidence reference' );
	jotform_schema_check( GHCA_ACD_Jotform_Repository::persist_indexed_revision( array( $document ), $candidate ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$training} WHERE form_id='654321' AND submission_id='900001'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$documents} WHERE form_id='654321' AND submission_id='900001'" ), 'identical repository replay is idempotent' );
	$wpdb->query( "CREATE TRIGGER ghca_test_document_update_fail BEFORE UPDATE ON {$documents} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled document upsert failure'" );
	jotform_schema_check( ! GHCA_ACD_Jotform_Repository::persist_indexed_revision( array( $document ), $candidate ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$training} WHERE form_id='654321' AND submission_id='900001'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$documents} WHERE form_id='654321' AND submission_id='900001'" ), 'duplicate document upsert failure is observable and rolls back without creating a new revision' );
	$wpdb->query( 'DROP TRIGGER ghca_test_document_update_fail' );
	$wpdb->query( "CREATE TRIGGER ghca_test_training_fail BEFORE INSERT ON {$training} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled failure'" );
	$failed_document = $document; $failed_document['submission_id'] = '900002'; $failed_document['source_fingerprint'] = str_repeat( 'e', 64 );
	$failed_candidate = $candidate; $failed_candidate['submission_id'] = '900002'; $failed_candidate['source_fingerprint'] = str_repeat( 'e', 64 );
	jotform_schema_check( ! GHCA_ACD_Jotform_Repository::persist_indexed_revision( array( $failed_document ), $failed_candidate ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$documents} WHERE form_id='654321' AND submission_id='900002'" ), 'controlled training persistence failure rolls back the inserted document without orphaning it' );
	$wpdb->query( 'DROP TRIGGER ghca_test_training_fail' );
	$wpdb->insert( $training, array( 'employee_user_id' => 0, 'form_id' => '654321', 'submission_id' => '900003', 'source_fingerprint' => str_repeat( 'f', 64 ), 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Old unsigned training', 'proposed_completion_date' => '2026-08-20', 'status' => 'pending', 'warning_code' => 'ownership_claim_missing', 'warning_detail' => '', 'created_at' => $now, 'updated_at' => $now ) );
	$unsigned_id = (int) $wpdb->insert_id;
	$events = GHCA_ACD_Jotform_Schema::events_table();
	$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" );
	$wpdb->query( "CREATE TRIGGER ghca_test_event_fail BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled event failure'" );
	$rejection_reason = 'Retain for signed resubmission.';
	jotform_schema_check( ! GHCA_ACD_Jotform_Repository::reject_unsigned_pending_with_event( $unsigned_id, $rejection_reason, 99, hash( 'sha256', $rejection_reason ) ) && 'pending' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $unsigned_id ) ) && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(review_reason,'') FROM {$training} WHERE id=%d", $unsigned_id ) ) && $event_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ), 'bulk rejection event failure rolls back the record state and leaves no unaudited rejection' );
	$wpdb->query( 'DROP TRIGGER ghca_test_event_fail' );
	$wpdb->insert( $training, array( 'employee_user_id' => 1, 'form_id' => '654321', 'submission_id' => '900001', 'source_fingerprint' => str_repeat( 'a', 64 ), 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Prior approved training', 'proposed_completion_date' => '2026-08-19', 'status' => 'approved', 'warning_code' => null, 'warning_detail' => null, 'created_at' => $now, 'updated_at' => $now ) );
	$prior_training_id = (int) $wpdb->insert_id;
	$wpdb->insert( $evidence, array( 'training_id' => $prior_training_id, 'revision_no' => 1, 'status' => 'approved', 'catalog_snapshot' => '{}', 'evidence_manifest' => '[]', 'snapshot_digest' => hash( 'sha256', "{}\n[]" ), 'approved_by' => 99, 'approved_at' => $now ) );
	$prior_revision_id = (int) $wpdb->insert_id;
	$wpdb->update( $training, array( 'approved_revision_id' => $prior_revision_id ), array( 'id' => $prior_training_id ) );
	$pending_id = (int) $wpdb->get_var( "SELECT id FROM {$training} WHERE form_id='654321' AND submission_id='900001' AND source_fingerprint='" . str_repeat( 'd', 64 ) . "'" );
	$wpdb->query( "CREATE TRIGGER ghca_test_approval_event_fail BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled approval event failure'" );
	$approval_snapshot = array( 'catalog_id' => 9, 'completion_date' => '2026-08-20' );
	jotform_schema_check( 0 === GHCA_ACD_Jotform_Repository::approve( $pending_id, $approval_snapshot, array(), 99, 1, '2026-08-20' ) && 'pending' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $pending_id ) ) && 'ownership_claim_missing' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT warning_code FROM {$training} WHERE id=%d", $pending_id ) ) && 0 === (int) $wpdb->get_var( "SELECT employee_user_id FROM {$documents} WHERE form_id='654321' AND submission_id='900001' AND source_fingerprint='" . str_repeat( 'd', 64 ) . "'" ) && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $prior_training_id ) ) && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$evidence} WHERE id=%d", $prior_revision_id ) ), 'approval and supersession event failure rolls back lifecycle state and retains the pending quarantine' );
	$wpdb->insert( $training, array( 'employee_user_id' => 0, 'form_id' => '654321', 'submission_id' => '900004', 'source_fingerprint' => str_repeat( '9', 64 ), 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Ordinary rejection', 'proposed_completion_date' => '2026-08-20', 'status' => 'pending', 'warning_code' => 'ownership_claim_missing', 'warning_detail' => '', 'created_at' => $now, 'updated_at' => $now ) );
	$ordinary_reject_id = (int) $wpdb->insert_id;
	jotform_schema_check( ! GHCA_ACD_Jotform_Repository::reject_pending_training( $ordinary_reject_id, 99 ) && 'pending' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $ordinary_reject_id ) ) && ! GHCA_ACD_Jotform_Repository::revoke_evidence( $prior_training_id, 99, 'Controlled event failure' ) && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $prior_training_id ) ) && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$evidence} WHERE id=%d", $prior_revision_id ) ), 'ordinary rejection and revocation roll back when their mandatory lifecycle event cannot persist' );
	$wpdb->query( 'DROP TRIGGER ghca_test_approval_event_fail' );
	$corrected_date = '2026-08-25';
	$corrected_snapshot = array( 'catalog_id' => 9, 'title' => 'Corrected approval date', 'completion_date' => $corrected_date );
	$approved_revision_id = GHCA_ACD_Jotform_Repository::approve( $pending_id, $corrected_snapshot, array(), 99, 1, $corrected_date );
	$approved_snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT catalog_snapshot FROM {$evidence} WHERE id=%d", $approved_revision_id ) ), true );
	$annual_rows = array_values( array_filter( GHCA_ACD_Jotform_Repository::approved_courses( 1 ), static function ( array $row ) use ( $pending_id ): bool { return $pending_id === (int) $row['training_id']; } ) );
	jotform_schema_check( $approved_revision_id > 0 && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $pending_id ) ) && $corrected_date === (string) $wpdb->get_var( $wpdb->prepare( "SELECT proposed_completion_date FROM {$training} WHERE id=%d", $pending_id ) ) && is_array( $approved_snapshot ) && $corrected_date === (string) ( $approved_snapshot['completion_date'] ?? '' ) && 1 === count( $annual_rows ) && $corrected_date === (string) $annual_rows[0]['proposed_completion_date'] && 'superseded' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$training} WHERE id=%d", $prior_training_id ) ) && 'superseded' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$evidence} WHERE id=%d", $prior_revision_id ) ), 'locked approved siblings are superseded with matching events and corrected snapshot, training and annual date values' );
	$lock_form = '765432'; $lock_submission = 'LOCK-READ-COMMITTED';
	$lock_ids = array();
	foreach ( array( str_repeat( '1', 64 ), str_repeat( '2', 64 ) ) as $fingerprint ) {
		$wpdb->insert( $training, array( 'employee_user_id' => 0, 'form_id' => $lock_form, 'submission_id' => $lock_submission, 'source_fingerprint' => $fingerprint, 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Concurrent approval', 'proposed_completion_date' => '2026-08-20', 'status' => 'pending', 'warning_code' => 'ownership_claim_missing', 'warning_detail' => '', 'created_at' => $now, 'updated_at' => $now ) );
		$lock_ids[] = (int) $wpdb->insert_id;
	}
	$lock_a = new mysqli( '127.0.0.1', 'root', '', $database, $port );
	$lock_b = new mysqli( '127.0.0.1', 'root', '', $database, $port );
	$lock_blocked = false; $lock_fresh = false;
	try {
		$lock_a->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' ); $lock_b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		$lock_a->query( 'START TRANSACTION' );
		$locked_a = $lock_a->query( "SELECT id,status FROM {$training} WHERE form_id='{$lock_form}' AND submission_id='{$lock_submission}' ORDER BY id FOR UPDATE" );
		$lock_b->query( 'START TRANSACTION' );
		$lock_b->query( "SELECT id,status FROM {$training} WHERE form_id='{$lock_form}' AND submission_id='{$lock_submission}' ORDER BY id FOR UPDATE", MYSQLI_ASYNC );
		usleep( 100000 ); $read = array( $lock_b ); $error = array(); $reject = array(); $lock_blocked = 0 === mysqli_poll( $read, $error, $reject, 0, 0 );
		$lock_a->query( "UPDATE {$training} SET status='approved' WHERE id=" . (int) $lock_ids[0] ); $lock_a->query( 'COMMIT' );
		$read = array( $lock_b ); $error = array(); $reject = array();
		if ( 1 === mysqli_poll( $read, $error, $reject, 5, 0 ) && ( $locked_b = $lock_b->reap_async_query() ) ) { $rows_b = $locked_b->fetch_all( MYSQLI_ASSOC ); $lock_fresh = 2 === count( $rows_b ) && 'approved' === (string) $rows_b[0]['status'] && 'pending' === (string) $rows_b[1]['status']; }
		$lock_b->query( 'ROLLBACK' );
	} finally {
		$lock_a->query( 'ROLLBACK' ); $lock_a->close(); $lock_b->close();
	}
	$wpdb->update( $training, array( 'status' => 'pending' ), array( 'id' => (int) $lock_ids[0], 'status' => 'approved' ) );
	$lock_snapshot_a = array( 'catalog_id' => 9, 'completion_date' => '2026-08-21' ); $lock_snapshot_b = array( 'catalog_id' => 9, 'completion_date' => '2026-08-22' );
	$lock_revision_a = GHCA_ACD_Jotform_Repository::approve( (int) $lock_ids[0], $lock_snapshot_a, array(), 99, 1, '2026-08-21' );
	$lock_revision_b = GHCA_ACD_Jotform_Repository::approve( (int) $lock_ids[1], $lock_snapshot_b, array(), 99, 1, '2026-08-22' );
	$lock_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE training_id IN (" . (int) $lock_ids[0] . ',' . (int) $lock_ids[1] . ") AND event_type IN ('training_approved','training_superseded')" );
	jotform_schema_check( $lock_blocked && $lock_fresh && $lock_revision_a > 0 && $lock_revision_b > 0 && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$training} WHERE form_id='{$lock_form}' AND submission_id='{$lock_submission}' AND status='approved'" ) && 'superseded' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$evidence} WHERE id=%d", $lock_revision_a ) ) && 'approved' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$evidence} WHERE id=%d", $lock_revision_b ) ) && 3 === $lock_events, 'two READ COMMITTED connections serialize all-revision locking and production competing approvals retain one approved revision with matching evidence and events' );
	$sync_lock_name = 'ghca_acd_jotform_sync_' . substr( md5( $database ), 0, 20 );
	$sync_form = '765432'; $sync_submission = 'SYNC-OLDER-BLOCKED';
	$sync_old_fingerprint = str_repeat( '7', 64 ); $sync_new_fingerprint = str_repeat( '8', 64 );
	$wpdb->insert( $training, array( 'employee_user_id' => 1, 'form_id' => $sync_form, 'submission_id' => $sync_submission, 'source_fingerprint' => $sync_old_fingerprint, 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Older fetched source', 'proposed_completion_date' => '2026-08-20', 'status' => 'pending', 'warning_code' => null, 'created_at' => $now, 'updated_at' => $now ) );
	$wpdb->insert( $documents, array( 'employee_user_id' => 1, 'form_id' => $sync_form, 'submission_id' => $sync_submission, 'source_fingerprint' => $sync_old_fingerprint, 'question_id' => '4', 'file_index' => 0, 'file_name' => 'older-source.pdf', 'media_type' => 'application/pdf', 'source_created_at' => $now, 'source_updated_at' => $now, 'availability' => 'available', 'created_at' => $now, 'updated_at' => $now ) );
	$sync_a = new mysqli( '127.0.0.1', 'root', '', $database, $port );
	$sync_b = new mysqli( '127.0.0.1', 'root', '', $database, $port );
	$sync_acquired = false; $sync_old_blocked = false; $sync_current_only = false;
	try {
		$sync_acquired = 1 === (int) $sync_a->query( "SELECT GET_LOCK('{$sync_lock_name}', 0)" )->fetch_row()[0];
		$sync_old_blocked = 0 === (int) $sync_b->query( "SELECT GET_LOCK('{$sync_lock_name}', 0)" )->fetch_row()[0];
		if ( $sync_acquired && $sync_old_blocked ) {
			$wpdb->insert( $training, array( 'employee_user_id' => 1, 'form_id' => $sync_form, 'submission_id' => $sync_submission, 'source_fingerprint' => $sync_new_fingerprint, 'catalog_id' => 9, 'proposed_code' => 'fatal-five', 'proposed_title' => 'Newer fetched source', 'proposed_completion_date' => '2026-08-21', 'status' => 'pending', 'warning_code' => null, 'created_at' => $now, 'updated_at' => $now ) );
			$sync_visible = array_values( array_filter( GHCA_ACD_Jotform_Repository::documents_for_employee( 1, 200, 0 ), static function ( array $row ) use ( $sync_form, $sync_submission ): bool { return $sync_form === (string) $row['form_id'] && $sync_submission === (string) $row['submission_id']; } ) );
			$sync_current_only = 0 === count( $sync_visible ) && $sync_new_fingerprint === (string) $wpdb->get_var( "SELECT source_fingerprint FROM {$training} WHERE form_id='{$sync_form}' AND submission_id='{$sync_submission}' ORDER BY id DESC LIMIT 1" );
		}
	} finally {
		$sync_a->query( "SELECT RELEASE_LOCK('{$sync_lock_name}')" ); $sync_b->query( "SELECT RELEASE_LOCK('{$sync_lock_name}')" ); $sync_a->close(); $sync_b->close();
	}
	jotform_schema_check( $sync_acquired && $sync_old_blocked && $sync_current_only, 'two MySQL connections block an older sync before fetch or persistence while the newer zero-file revision remains the only employee-visible current state' );
	$grant_token = str_repeat( 'a', 48 );
	jotform_schema_check( GHCA_ACD_Jotform_Repository::issue_document_grant( $grant_token, array( 'actor' => 99, 'employee' => 1, 'document' => 1, 'access' => 'preview' ) ) && is_array( GHCA_ACD_Jotform_Repository::consume_document_grant( $grant_token ) ) && null === GHCA_ACD_Jotform_Repository::consume_document_grant( $grant_token ), 'database compare-and-delete document grant permits one consumer and rejects replay' );
} finally {
	if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
		try {
			$wpdb->query( 'ROLLBACK' );
		} catch ( Throwable $e ) {
			// Keep disposal progressing after a deliberate failure-injection assertion.
		}
		try {
			$wpdb->close();
		} catch ( Throwable $e ) {
			// The separate server connection still attempts best-effort disposable cleanup.
		}
	}
	$server->query( "DROP DATABASE IF EXISTS `{$database}`" );
	$server->close();
}

exit( $fails ? 1 : 0 );
