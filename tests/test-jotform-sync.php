<?php
/** Executable synchronization-state and signed-ownership regression tests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

final class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

$options = array();
$transients = array();
$sync_db_locks = array();
$test_now = '2026-08-26 12:00:00';
$users = array(
	1 => (object) array( 'ID' => 1, 'user_email' => 'employee@example.test', 'display_name' => 'Employee One' ),
	2 => (object) array( 'ID' => 2, 'user_email' => 'victim@example.test', 'display_name' => 'Employee Two' ),
);

function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value ) { global $options; $options[ $key ] = $value; return true; }
function delete_option( $key ) { global $options; unset( $options[ $key ] ); }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); }
function add_action() {}
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() { return 1; }
function current_time() { global $test_now; return $test_now; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_file_name( $value ) { return basename( str_replace( '\\', '/', (string) $value ) ); }
function sanitize_mime_type( $value ) { return strtolower( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_userdata( $id ) { global $users; return $users[ (int) $id ] ?? false; }
function wp_salt() { return 'test-only-stable-wordpress-auth-salt'; }
function home_url() { return 'https://agency.example.test/'; }

final class Sync_Test_WPDB {
	public function prepare( string $query, string $lock_key ) { return array( $query, $lock_key ); }
	public function get_var( $prepared ) {
		global $sync_db_locks;
		$query = (string) $prepared[0];
		$lock_key = (string) $prepared[1];
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			if ( isset( $sync_db_locks[ $lock_key ] ) ) { return 0; }
			$sync_db_locks[ $lock_key ] = true;
			return 1;
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			unset( $sync_db_locks[ $lock_key ] );
			return 1;
		}
		return null;
	}
}
$wpdb = new Sync_Test_WPDB();

final class GHCA_ACD_Jotform_Provider {
	const OPTION_QUOTA_LEFT = 'quota_left';
	public static $dataset = array();
	public static $paused = false;
	public static $calls = array();
	public static $before_call = null;
	public static function quota_paused(): bool { return self::$paused; }
	public static function get_form_submissions( string $form_id, string $after_id, int $limit, string $updated_after = '', string $updated_before = '' ) {
		self::$calls[] = array( $form_id, $after_id, $limit, $updated_after, $updated_before );
		if ( is_callable( self::$before_call ) ) { call_user_func( self::$before_call, count( self::$calls ) ); }
		$rows = array_values( self::$dataset );
		$rows = array_values( array_filter( $rows, static function ( $row ) use ( $after_id, $updated_after, $updated_before ) {
			if ( '' !== $after_id && sync_compare_id( (string) $row['id'], $after_id ) <= 0 ) { return false; }
			$updated = strtotime( $row['updated_at'] );
			if ( '' !== $updated_after && $updated <= strtotime( $updated_after ) ) { return false; }
			if ( '' !== $updated_before && $updated >= strtotime( $updated_before ) ) { return false; }
			return true;
		} ) );
		usort( $rows, static function ( $a, $b ) { return sync_compare_id( (string) $a['id'], (string) $b['id'] ); } );
		return array( 'content' => array_slice( $rows, 0, $limit ) );
	}
	public static function answer( array $submission, string $question_id ) { return $submission['answers'][ $question_id ] ?? ''; }
	public static function file_candidates( $value ): array { return is_array( $value ) ? array_values( $value ) : array(); }
	public static function file_urls( $value ): array { return array_values( array_filter( self::file_candidates( $value ), static function ( $url ) { return 0 === strpos( $url, 'https://www.jotform.com/uploads/' ); } ) ); }
}

final class GHCA_ACD_Google_Drive_Provider {
	public static function jotform_file_names( $value ): array {
		return array_map( static function ( string $url ): string { return sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ); }, GHCA_ACD_Jotform_Provider::file_candidates( $value ) );
	}
	public static function evidence_reference( array $form, array $submission ) {
		$reference = trim( (string) GHCA_ACD_Jotform_Provider::answer( $submission, (string) ( $form['evidence_reference_qid'] ?? '' ) ) );
		return preg_match( '/^FT-[0-9]{6}$/', $reference ) ? $reference : new WP_Error( 'ghca_drive_reference_invalid' );
	}
}

final class GHCA_ACD_Jotform_Repository {
	const OPTION_SYNC_STATE = 'sync_state';
	const OPTION_LAST_ERROR = 'last_error';
	const OPTION_LAST_SYNC = 'last_sync';
	public static $documents = array();
	public static $candidates = array();
	public static $fail_submission_id = '';
	public static function enabled(): bool { return true; }
	public static function forms(): array { return array( array( 'form_id' => '123456', 'label' => 'External Training', 'user_id_qid' => '1', 'ownership_claim_qid' => '8', 'course_title_qid' => '2', 'completion_date_qid' => '3', 'certificate_qid' => '4', 'training_code_qid' => '5', 'employee_email_qid' => '6', 'employee_name_qid' => '7' ) ); }
	public static function catalog_match_result( string $code, string $title ): array { return array( 'status' => 'matched', 'item' => array( 'id' => 9 ) ); }
	public static function probable_duplicate_exists(): bool { return false; }
	public static function submission_fingerprints( string $form_id, string $submission_id ): array { return array_values( array_map( static function ( $row ): string { return (string) $row['source_fingerprint']; }, array_filter( self::$candidates, static function ( $row ) use ( $form_id, $submission_id ): bool { return $row['form_id'] === $form_id && $row['submission_id'] === $submission_id; } ) ) ); }
	public static function enrich_identity_warning_detail( string $form_id, string $submission_id, string $fingerprint, string $detail ): bool { foreach ( self::$candidates as &$row ) { if ( $row['form_id'] === $form_id && $row['submission_id'] === $submission_id && $row['source_fingerprint'] === $fingerprint && 'pending' === ( $row['status'] ?? 'pending' ) && 'identity_mismatch' === ( $row['warning_code'] ?? '' ) && empty( $row['warning_detail'] ) ) { $row['warning_detail'] = $detail; unset( $row ); return true; } } unset( $row ); return false; }
	public static function persist_indexed_revision( array $document_rows, array $candidate ): bool {
		if ( '' !== self::$fail_submission_id && hash_equals( self::$fail_submission_id, (string) $candidate['submission_id'] ) ) { return false; }
		foreach ( $document_rows as $row ) {
			$found = false;
			foreach ( self::$documents as $index => $existing ) {
				if ( $existing['form_id'] === $row['form_id'] && $existing['submission_id'] === $row['submission_id'] && $existing['source_fingerprint'] === $row['source_fingerprint'] && $existing['question_id'] === $row['question_id'] && $existing['file_index'] === $row['file_index'] ) {
					$reviewed_owner = (int) $existing['employee_user_id'];
					self::$documents[ $index ] = array_merge( $existing, $row );
					if ( $reviewed_owner > 0 ) { self::$documents[ $index ]['employee_user_id'] = $reviewed_owner; }
					$found = true;
					break;
				}
			}
			if ( ! $found ) { self::$documents[] = $row; }
		}
		foreach ( self::$candidates as $existing ) {
			if ( $existing['form_id'] === $candidate['form_id'] && $existing['submission_id'] === $candidate['submission_id'] && $existing['source_fingerprint'] === $candidate['source_fingerprint'] ) { return true; }
		}
		self::$candidates[] = $candidate;
		return true;
	}
	public static function normalize_title( string $value ): string { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', $value ) ) ); }
	public static function sanitize_date( string $value ): ?string { $date = DateTime::createFromFormat( '!Y-m-d', $value ); return $date && $date->format( 'Y-m-d' ) === $value ? $value : null; }
}

require_once dirname( __DIR__ ) . '/includes/jotform/class-jotform-sync.php';

$failures = 0;
function sync_check( $condition, $message ) { global $failures; echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; if ( ! $condition ) { $failures++; } }
function sync_compare_id( string $left, string $right ): int { return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) : ( strlen( $left ) < strlen( $right ) ? -1 : 1 ); }

function submission_row( int $index, string $updated = '', int $user_id = 1, bool $signed = true ): array {
	$id = (string) ( 100000 + $index );
	$created = '2026-01-01 09:05:00';
	$claim = $signed ? GHCA_ACD_Jotform_Sync::create_ownership_claim( $user_id, '123456', strtotime( '2026-01-01 14:00:00 UTC' ) ) : '';
	return array(
		'id' => $id,
		'created_at' => $created,
		'updated_at' => $updated ?: sprintf( '2026-02-%02d 10:00:00', ( $index % 28 ) + 1 ),
		'answers' => array( '1' => (string) $user_id, '2' => 'Fatal Five Lesson ' . $index, '3' => '2026-02-15', '4' => array( 'https://www.jotform.com/uploads/certificate-' . $index . '.pdf' ), '5' => 'fatal-five-' . $index, '6' => 'employee@example.test', '7' => 'Employee One', '8' => $claim ),
	);
}

for ( $i = 0; $i < 120; $i++ ) { GHCA_ACD_Jotform_Provider::$dataset[ (string) ( 100000 + $i ) ] = submission_row( $i ); }
$first = GHCA_ACD_Jotform_Sync::run( false );
$state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() );
sync_check( ! is_wp_error( $first ) && 100 === $first['processed'] && 2 === $first['pages'], 'cron backfill is bounded to two 50-record pages' );
sync_check( 100 === $first['metrics']['new'] && 0 === $first['metrics']['updated'] && 0 === $first['metrics']['unchanged'] && 0 === $first['metrics']['quarantined'] && 0 === $first['metrics']['failed'], 'first indexed revisions are classified as new without double counting' );
sync_check( '100099' === $state['123456']['after_id'] && 'backfill' === $state['123456']['mode'] && ! isset( $state['123456']['offset'] ), 'backfill persists an immutable submission-ID keyset instead of an offset' );

$second = GHCA_ACD_Jotform_Sync::run( false );
$state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() );
sync_check( 20 === $second['processed'] && 'incremental' === $state['123456']['mode'] && 1 === $state['123456']['backfill_complete'], 'later cron completes backfill and begins an overlapped incremental window' );
sync_check( 120 === count( GHCA_ACD_Jotform_Repository::$documents ) && 120 === count( GHCA_ACD_Jotform_Repository::$candidates ), 'valid signed submissions retain normal automatic employee linking' );

$test_now = '2026-08-26 12:05:00';
$changed = submission_row( 5, '2026-08-26 08:01:00' );
$changed['answers']['2'] = 'Fatal Five Lesson 5 Revised';
GHCA_ACD_Jotform_Provider::$dataset['100005'] = $changed;
$third = GHCA_ACD_Jotform_Sync::run( false );
sync_check( 1 === $third['processed'] && 121 === count( GHCA_ACD_Jotform_Repository::$candidates ), 'a changed source submission creates a new indexed pending revision' );
sync_check( 1 === $third['metrics']['updated'] && 1 === $third['metrics']['read'], 'a new fingerprint for an existing submission is classified as updated' );
sync_check( GHCA_ACD_Jotform_Repository::$candidates[5]['source_fingerprint'] !== GHCA_ACD_Jotform_Repository::$candidates[120]['source_fingerprint'], 'changed source content receives a distinct deterministic fingerprint' );

$multi = submission_row( 999, '2026-08-26 08:02:00' );
$multi['answers']['4'][] = 'https://www.jotform.com/uploads/certificate-999-b.png';
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $multi );
$last_two = array_slice( GHCA_ACD_Jotform_Repository::$documents, -2 );
sync_check( 2 === count( $last_two ) && 0 === $last_two[0]['file_index'] && 1 === $last_two[1]['file_index'], 'multiple certificates retain their upload order' );

$unsigned = submission_row( 998, '2026-08-26 08:03:00', 1, false );
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $unsigned );
$unsigned_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
$unsigned_document = end( GHCA_ACD_Jotform_Repository::$documents );
sync_check( 0 === $unsigned_candidate['employee_user_id'] && 0 === $unsigned_document['employee_user_id'] && 'ownership_claim_missing' === $unsigned_candidate['warning_code'], 'unsigned submissions are quarantined instead of appearing under the asserted employee' );
$unsigned_candidate_index = array_key_last( GHCA_ACD_Jotform_Repository::$candidates );
$unsigned_document_index = array_key_last( GHCA_ACD_Jotform_Repository::$documents );
GHCA_ACD_Jotform_Repository::$candidates[ $unsigned_candidate_index ]['employee_user_id'] = 1;
GHCA_ACD_Jotform_Repository::$documents[ $unsigned_document_index ]['employee_user_id'] = 1;
$before_unsigned_retry_candidates = count( GHCA_ACD_Jotform_Repository::$candidates );
$before_unsigned_retry_documents = count( GHCA_ACD_Jotform_Repository::$documents );
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $unsigned );
sync_check( 1 === GHCA_ACD_Jotform_Repository::$documents[ $unsigned_document_index ]['employee_user_id'] && $before_unsigned_retry_candidates === count( GHCA_ACD_Jotform_Repository::$candidates ) && $before_unsigned_retry_documents === count( GHCA_ACD_Jotform_Repository::$documents ), 'retrying an identical unsigned revision preserves its reviewed employee binding and remains idempotent' );

$tampered = submission_row( 997, '2026-08-26 08:04:00', 1, true );
$tampered['answers']['1'] = '2';
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $tampered );
$tampered_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
$tampered_document = end( GHCA_ACD_Jotform_Repository::$documents );
sync_check( 0 === $tampered_candidate['employee_user_id'] && 0 === $tampered_document['employee_user_id'] && 'ownership_claim_invalid' === $tampered_candidate['warning_code'], 'a claim copied to a different user_id cannot create a cross-employee association' );

$wrong_form = submission_row( 995, '2026-08-26 08:04:10', 1, false );
$wrong_form['answers']['8'] = GHCA_ACD_Jotform_Sync::create_ownership_claim( 1, '654321', strtotime( '2026-01-01 14:00:00 UTC' ) );
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $wrong_form );
$wrong_form_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
sync_check( 0 === $wrong_form_candidate['employee_user_id'] && 'ownership_claim_invalid' === $wrong_form_candidate['warning_code'], 'a claim issued for another allowed-looking form cannot be replayed here' );

$expired = submission_row( 994, '2026-08-26 08:04:20', 1, false );
$expired['answers']['8'] = GHCA_ACD_Jotform_Sync::create_ownership_claim( 1, '123456', strtotime( '2025-12-30 09:00:00 UTC' ) );
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $expired );
$expired_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
sync_check( 0 === $expired_candidate['employee_user_id'] && 'ownership_claim_invalid' === $expired_candidate['warning_code'], 'a claim that expired before submission creation is quarantined' );

$missing_user = submission_row( 996, '2026-08-26 08:04:30', 1, false );
$missing_user['answers']['1'] = '';
GHCA_ACD_Jotform_Sync::index_submission( GHCA_ACD_Jotform_Repository::forms()[0], $missing_user );
$missing_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
sync_check( 0 === $missing_candidate['employee_user_id'] && 'employee_unresolved' === $missing_candidate['warning_code'], 'email never replaces a missing exact positive user_id' );

GHCA_ACD_Jotform_Repository::$documents = array();
GHCA_ACD_Jotform_Repository::$candidates = array();
GHCA_ACD_Jotform_Provider::$dataset = array();
GHCA_ACD_Jotform_Provider::$calls = array();
$options[ GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE ] = array( '123456' => array( 'mode' => 'incremental', 'cursor' => '2026-08-26 08:09:59', 'after_id' => '', 'backfill_complete' => 1, 'indexer_version' => GHCA_ACD_Jotform_Sync::INDEXER_VERSION ) );
$test_now = '2026-08-26 12:20:00';
for ( $i = 0; $i < 120; $i++ ) {
	$row = submission_row( 100000 + $i, '2026-08-26 08:10:00' );
	GHCA_ACD_Jotform_Provider::$dataset[ $row['id'] ] = $row;
}
GHCA_ACD_Jotform_Provider::$before_call = static function ( int $call ): void {
	if ( 2 === $call ) {
		GHCA_ACD_Jotform_Provider::$dataset['200000']['updated_at'] = '2026-08-26 08:30:00';
	}
};
$window_one = GHCA_ACD_Jotform_Sync::run( false );
$window_two = GHCA_ACD_Jotform_Sync::run( false );
$seen = array_unique( array_column( GHCA_ACD_Jotform_Repository::$candidates, 'submission_id' ) );
sync_check( 100 === $window_one['processed'] && 20 === $window_two['processed'] && 120 === count( $seen ), 'mutation between incremental pages does not shift the ID keyset or skip another submission' );
$test_now = '2026-08-26 12:35:00';
GHCA_ACD_Jotform_Provider::$before_call = null;
$next_window = GHCA_ACD_Jotform_Sync::run( false );
$seen = array_unique( array_column( GHCA_ACD_Jotform_Repository::$candidates, 'submission_id' ) );
$moved_revisions = array_values( array_filter( GHCA_ACD_Jotform_Repository::$candidates, static function ( $row ) { return '200000' === $row['submission_id']; } ) );
sync_check( 1 === $next_window['processed'] && 120 === count( $seen ) && 2 === count( $moved_revisions ), 'the row moved beyond the frozen window is reconciled as a changed revision in the next window' );

GHCA_ACD_Jotform_Repository::$documents = array();
GHCA_ACD_Jotform_Repository::$candidates = array();
GHCA_ACD_Jotform_Provider::$dataset = array();
GHCA_ACD_Jotform_Provider::$calls = array();
$options[ GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE ] = array();
$test_now = '2026-08-26 13:00:00';
$failing_row = submission_row( 777, '2026-08-26 08:40:00' );
GHCA_ACD_Jotform_Provider::$dataset[ $failing_row['id'] ] = $failing_row;
GHCA_ACD_Jotform_Repository::$fail_submission_id = (string) $failing_row['id'];
$failed_index = GHCA_ACD_Jotform_Sync::run( false );
$failed_state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() );
sync_check( is_wp_error( $failed_index ) && 'ghca_jotform_index_failed' === $failed_index->get_error_code(), 'a database persistence failure aborts synchronization explicitly' );
sync_check( empty( $failed_state['123456']['after_id'] ) && 0 === count( GHCA_ACD_Jotform_Repository::$documents ) && 0 === count( GHCA_ACD_Jotform_Repository::$candidates ), 'a failed revision leaves no orphan document and does not advance the cursor' );
GHCA_ACD_Jotform_Repository::$fail_submission_id = '';
$retried_index = GHCA_ACD_Jotform_Sync::run( false );
sync_check( ! is_wp_error( $retried_index ) && 1 === $retried_index['processed'] && 1 === count( GHCA_ACD_Jotform_Repository::$documents ) && 1 === count( GHCA_ACD_Jotform_Repository::$candidates ), 'the same revision is indexed successfully on retry' );
sync_check( empty( $sync_db_locks ), 'the database advisory lock is released after both failed and successful persistence outcomes' );

$drive_form = GHCA_ACD_Jotform_Repository::forms()[0];
$drive_form['file_source'] = 'google_drive';
$drive_form['evidence_reference_qid'] = '9';
$drive_form['drive_folder_id'] = 'root-folder';
$drive_submission = submission_row( 893, '2026-08-26 08:45:00' );
$drive_submission['answers']['9'] = 'FT-000001';
GHCA_ACD_Jotform_Sync::index_submission( $drive_form, $drive_submission );
$first_drive_fingerprint = (string) end( GHCA_ACD_Jotform_Repository::$candidates )['source_fingerprint'];
$mutated_drive_submission = $drive_submission;
$mutated_drive_submission['answers']['9'] = 'FT-000002';
GHCA_ACD_Jotform_Sync::index_submission( $drive_form, $mutated_drive_submission );
$mutated_drive_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
$mutated_drive_document = end( GHCA_ACD_Jotform_Repository::$documents );
sync_check( $first_drive_fingerprint !== (string) $mutated_drive_candidate['source_fingerprint'] && 'FT-000002' === (string) $mutated_drive_document['evidence_reference'], 'a resolved Drive evidence-reference mutation produces a distinct locally bound source revision' );
$invalid_drive_submission = submission_row( 894, '2026-08-26 08:46:00' );
$invalid_drive_submission['answers']['9'] = '../unsafe';
GHCA_ACD_Jotform_Sync::index_submission( $drive_form, $invalid_drive_submission );
$invalid_drive_candidate = end( GHCA_ACD_Jotform_Repository::$candidates );
$invalid_drive_document = end( GHCA_ACD_Jotform_Repository::$documents );
sync_check( 'ghca_drive_reference_invalid' === (string) $invalid_drive_candidate['warning_code'] && '' === (string) $invalid_drive_document['evidence_reference'], 'an invalid configured Drive evidence reference is quarantined without a silent submission-ID fallback' );

$calls_before_pause = count( GHCA_ACD_Jotform_Provider::$calls );
GHCA_ACD_Jotform_Provider::$paused = true;
$paused = GHCA_ACD_Jotform_Sync::run( false );
sync_check( is_wp_error( $paused ) && 'ghca_jotform_quota_paused' === $paused->get_error_code() && $calls_before_pause === count( GHCA_ACD_Jotform_Provider::$calls ), 'quota floor pauses synchronization before another API call' );

GHCA_ACD_Jotform_Provider::$paused = false;
$calls_before_busy = count( GHCA_ACD_Jotform_Provider::$calls );
$sync_db_locks[ GHCA_ACD_Jotform_Sync::LOCK_KEY ] = true;
$busy = GHCA_ACD_Jotform_Sync::run( false );
sync_check( is_wp_error( $busy ) && 'ghca_jotform_sync_busy' === $busy->get_error_code() && $calls_before_busy === count( GHCA_ACD_Jotform_Provider::$calls ), 'a held database advisory lock fails closed before any remote fetch or persistence' );
unset( $sync_db_locks[ GHCA_ACD_Jotform_Sync::LOCK_KEY ] );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit( 0 === $failures ? 0 : 1 );
