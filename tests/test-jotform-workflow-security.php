<?php
/** Executable workflow-state regression tests for external training review. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $value ) { return $value; }
function current_time() { return '2026-08-26 12:00:00'; }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return basename( (string) $value ); }
function sanitize_mime_type( $value ) { return (string) $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_userdata( $id ) { return in_array( (int) $id, array( 1, 2 ), true ) ? (object) array( 'ID' => (int) $id ) : false; }

final class GHCA_ACD_Jotform_Schema {
	public static function training_table(): string { return 'training'; }
	public static function documents_table(): string { return 'documents'; }
	public static function evidence_table(): string { return 'evidence'; }
	public static function events_table(): string { return 'events'; }
}

final class Workflow_WPDB {
	public $insert_id = 0;
	public $training = array(
		7 => array( 'id' => 7, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => 0, 'form_id' => '123456', 'submission_id' => '700001', 'source_fingerprint' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ),
		8 => array( 'id' => 8, 'status' => 'approved', 'approved_revision_id' => 55, 'employee_user_id' => 1, 'form_id' => '123456', 'submission_id' => '800001', 'source_fingerprint' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ),
	);
	public $evidence = array( 55 => array( 'id' => 55, 'status' => 'approved' ) );
	public $documents = array( array( 'employee_user_id' => 0, 'form_id' => '123456', 'submission_id' => '700001', 'source_fingerprint' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ) );
	public $transactions = array();
	public $fail_query = '';
	public $fail_event = false;
	public $throw_event = false;
	public $throw_rollback = false;
	public $throw_grant = false;
	public $events = array();
	public $options = 'options';
	public $option_rows = array();
	private $snapshot = null;

	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_row( $prepared, $format = null ) {
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'FROM evidence' ) ) {
			return $this->evidence[ (int) $prepared['args'][0] ] ?? null;
		}
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'training' ) ) {
			$id = (int) $prepared['args'][0];
			return $this->training[ $id ] ?? null;
		}
		return null;
	}
	public function get_results( $prepared, $format = null ) {
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'FOR UPDATE' ) ) {
			$rows = array();
			foreach ( $this->training as $row ) { if ( $row['form_id'] === $prepared['args'][0] && $row['submission_id'] === $prepared['args'][1] ) { $rows[] = $row; } }
			return $rows;
		}
		return array();
	}
	public function get_var( $prepared ) {
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'MAX(revision_no)' ) ) { return 0; }
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'SELECT option_value' ) ) { if ( $this->throw_grant ) { throw new RuntimeException( 'Simulated grant read exception.' ); } return $this->option_rows[ $prepared['args'][0] ] ?? null; }
		return null;
	}
	public function insert( $table, $data ) {
		if ( 'evidence' === $table ) { $id = 100 + count( $this->evidence ); $this->evidence[ $id ] = array_merge( array( 'id' => $id ), $data ); $this->insert_id = $id; return 1; }
		if ( 'events' === $table ) { if ( $this->throw_event ) { throw new RuntimeException( 'Simulated event persistence exception.' ); } if ( $this->fail_event ) { return false; } $this->events[] = $data; $this->insert_id = count( $this->events ); return 1; }
		if ( 'options' === $table ) { if ( $this->throw_grant ) { throw new RuntimeException( 'Simulated grant write exception.' ); } if ( isset( $this->option_rows[ $data['option_name'] ] ) ) { return false; } $this->option_rows[ $data['option_name'] ] = $data['option_value']; return 1; }
		return false;
	}
	public function update( $table, $data, $where ) {
		if ( 'training' === $table ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->training[ $id ] ) ) { return 0; }
			$row = $this->training[ $id ];
			foreach ( $where as $key => $value ) {
				if ( 'id' === $key ) { continue; }
				if ( ( $row[ $key ] ?? null ) !== $value ) { return 0; }
			}
			$this->training[ $id ] = array_merge( $row, $data );
			return 1;
		}
		if ( 'evidence' === $table ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->evidence[ $id ] ) || ( isset( $where['status'] ) && $this->evidence[ $id ]['status'] !== $where['status'] ) ) { return 0; }
			$this->evidence[ $id ] = array_merge( $this->evidence[ $id ], $data );
			return 1;
		}
		if ( 'documents' === $table ) {
			$changed = 0;
			foreach ( $this->documents as &$row ) {
				$match = true;
				foreach ( $where as $key => $value ) { if ( ( $row[ $key ] ?? null ) !== $value ) { $match = false; break; } }
				if ( $match ) { $row = array_merge( $row, $data ); $changed++; }
			}
			unset( $row );
			return $changed;
		}
		return false;
	}
	public function query( $query ) {
		$this->transactions[] = $query;
		if ( is_array( $query ) && false !== strpos( $query['query'], 'DELETE FROM options' ) ) { $name = $query['args'][0]; $value = $query['args'][1]; if ( ! isset( $this->option_rows[ $name ] ) || $this->option_rows[ $name ] !== $value ) { return 0; } unset( $this->option_rows[ $name ] ); return 1; }
		if ( 'START TRANSACTION' === $query ) { $this->snapshot = serialize( array( $this->training, $this->evidence, $this->documents, $this->events, $this->option_rows ) ); }
		if ( 'ROLLBACK' === $query && null !== $this->snapshot ) { list( $this->training, $this->evidence, $this->documents, $this->events, $this->option_rows ) = unserialize( $this->snapshot ); if ( $this->throw_rollback ) { throw new RuntimeException( 'Simulated rollback exception.' ); } }
		if ( 'COMMIT' === $query ) { $this->snapshot = null; }
		return $this->fail_query === $query ? false : true;
	}
}

$wpdb = new Workflow_WPDB();
require_once dirname( __DIR__ ) . '/includes/jotform/class-jotform-repository.php';

$failures = 0;
function workflow_check( $condition, $message ) { global $failures; echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; if ( ! $condition ) { $failures++; } }

$rejected = GHCA_ACD_Jotform_Repository::reject_pending_training( 7, 99 );
workflow_check( $rejected && 'rejected' === $wpdb->training[7]['status'], 'a pending training record can be rejected' );

$approved_reject = GHCA_ACD_Jotform_Repository::reject_pending_training( 8, 99 );
workflow_check( ! $approved_reject && 'approved' === $wpdb->training[8]['status'] && 'approved' === $wpdb->evidence[55]['status'], 'an approved record cannot bypass revocation through the reject transition' );

$no_reason = GHCA_ACD_Jotform_Repository::revoke_evidence( 8, 99, '   ' );
workflow_check( ! $no_reason && 'approved' === $wpdb->training[8]['status'], 'approved evidence cannot be revoked without a reason' );

$revoked = GHCA_ACD_Jotform_Repository::revoke_evidence( 8, 99, 'Superseded certificate' );
workflow_check( $revoked && 'revoked' === $wpdb->training[8]['status'] && 'revoked' === $wpdb->evidence[55]['status'], 'reasoned revocation changes training and evidence state together' );

$bound = GHCA_ACD_Jotform_Repository::confirm_pending_training( 7, 1, 9, '2026-08-20' );
workflow_check( ! $bound, 'a rejected legacy record cannot be rebound as pending evidence' );

$wpdb->training[9] = array( 'id' => 9, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => 0, 'form_id' => '123456', 'submission_id' => '700001', 'source_fingerprint' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
$bound = GHCA_ACD_Jotform_Repository::confirm_pending_training( 9, 1, 9, '2026-08-20' );
workflow_check( $bound && 0 === $wpdb->training[9]['employee_user_id'] && 0 === $wpdb->documents[0]['employee_user_id'], 'manual approval validation keeps a quarantined row and document invisible until evidence approval commits' );

$wpdb->training[10] = array( 'id' => 10, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => 0, 'form_id' => '123456', 'submission_id' => '1000001', 'source_fingerprint' => 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc' );
$wpdb->fail_event = true;
$event_denied = GHCA_ACD_Jotform_Repository::reject_pending_training( 10, 99 );
workflow_check( ! $event_denied && 'pending' === $wpdb->training[10]['status'], 'ordinary rejection rolls back when its mandatory append-only event cannot persist' );
$wpdb->fail_event = false;

$wpdb->throw_event = true;
$event_exception = GHCA_ACD_Jotform_Repository::event( 'event_boundary_test', 99, 1 );
workflow_check( false === $event_exception, 'event persistence exceptions are observable as a fail-closed false result' );
$wpdb->throw_event = false;

$wpdb->training[11] = array( 'id' => 11, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => 0, 'form_id' => '123456', 'submission_id' => '1000002', 'source_fingerprint' => str_repeat( 'd', 64 ) );
$wpdb->throw_event = true;
$wpdb->throw_rollback = true;
$rollback_exception = GHCA_ACD_Jotform_Repository::reject_pending_training( 11, 99 );
workflow_check( false === $rollback_exception, 'a rollback exception remains contained after a failed lifecycle event' );
$wpdb->throw_event = false;
$wpdb->throw_rollback = false;

$token = str_repeat( 'a', 48 );
$grant = array( 'actor' => 99, 'employee' => 1, 'document' => 7, 'access' => 'preview' );
$issued = GHCA_ACD_Jotform_Repository::issue_document_grant( $token, $grant );
$consumed = GHCA_ACD_Jotform_Repository::consume_document_grant( $token );
$replayed = GHCA_ACD_Jotform_Repository::consume_document_grant( $token );
workflow_check( $issued && is_array( $consumed ) && 7 === (int) $consumed['document'] && null === $replayed, 'atomic compare-and-delete grant consumption permits one request and fails closed on replay' );

$wpdb->throw_grant = true;
workflow_check( ! GHCA_ACD_Jotform_Repository::issue_document_grant( str_repeat( 'b', 48 ), $grant ) && null === GHCA_ACD_Jotform_Repository::consume_document_grant( str_repeat( 'b', 48 ) ), 'grant database throwables are contained and fail closed' );
$wpdb->throw_grant = false;

$manual_manifest_json = json_encode( array( array( 'file_index' => 0, 'file_name' => 'scan.pdf', 'media_type' => 'application/pdf', 'bytes' => 10, 'sha256' => str_repeat( '1', 64 ), 'storage_path' => 'external-evidence/x' ) ) );
$wpdb->training[12] = array( 'id' => 12, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => 1, 'form_id' => 'manual', 'submission_id' => str_repeat( 'e', 32 ), 'source_fingerprint' => str_repeat( 'f', 64 ), 'proposed_completion_date' => '2026-08-20', 'manual_manifest' => $manual_manifest_json, 'entered_by' => 99, 'entry_reason' => 'Emailed scan' );
workflow_check( GHCA_ACD_Jotform_Repository::is_manual( $wpdb->training[12] ) && ! GHCA_ACD_Jotform_Repository::is_manual( $wpdb->training[9] ), 'the reserved manual form id identifies administrator entries and nothing else' );
$decoded_manifest = GHCA_ACD_Jotform_Repository::manual_manifest( $wpdb->training[12] );
workflow_check( is_array( $decoded_manifest ) && 1 === count( $decoded_manifest ) && 'external-evidence/x' === $decoded_manifest[0]['storage_path'] && null === GHCA_ACD_Jotform_Repository::manual_manifest( $wpdb->training[9] ), 'manual rows expose their validated stored manifest and Jotform rows expose none' );
$wpdb->training[13] = $wpdb->training[12]; $wpdb->training[13]['id'] = 13; $wpdb->training[13]['manual_manifest'] = '[{"file_index":0}]';
workflow_check( null === GHCA_ACD_Jotform_Repository::manual_manifest( $wpdb->training[13] ), 'a manifest missing digest or storage fields is treated as absent' );
workflow_check( ! GHCA_ACD_Jotform_Repository::confirm_pending_training( 12, 2, 9, '2026-08-20' ) && GHCA_ACD_Jotform_Repository::confirm_pending_training( 12, 1, 9, '2026-08-20' ), 'a manual entry cannot be rebound to a different existing employee' );
$manual_revision = GHCA_ACD_Jotform_Repository::approve( 12, array( 'catalog_id' => 9, 'completion_date' => '2026-08-20', 'entry_method' => 'manual', 'entered_by' => 99, 'entry_reason' => 'Emailed scan' ), $decoded_manifest, 99, 1, '2026-08-20', 'manual_entry' );
workflow_check( $manual_revision > 0 && 'approved' === $wpdb->training[12]['status'] && $manual_revision === (int) $wpdb->training[12]['approved_revision_id'] && 'approved' === $wpdb->evidence[ $manual_revision ]['status'] && null === $wpdb->training[12]['warning_code'], 'a manual entry approves through the shared immutable-revision transaction' );
$manual_events = array_filter( $wpdb->events, static function ( array $event ): bool { return 'training_approved' === $event['event_type'] && 12 === (int) $event['training_id'] && 'manual_entry' === $event['error_code']; } );
workflow_check( 1 === count( $manual_events ), 'manual approval is audited with a distinguishing event marker' );
$wpdb->training[12]['status'] = 'pending';
workflow_check( 0 === GHCA_ACD_Jotform_Repository::approve( 12, array( 'catalog_id' => 9, 'completion_date' => '2026-08-20' ), $decoded_manifest, 99, 2, '2026-08-20' ), 'a manual entry cannot be approved for a different employee than it was entered for' );

/* The employee drawer reads manual evidence through manual_evidence_manifest(), because
   manual entries never appear in the Jotform documents table. */
$approved_row = $wpdb->training[12];
$approved_row['status'] = 'approved';
$approved_manifest = GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $approved_row );
workflow_check( is_array( $approved_manifest ) && 'external-evidence/x' === ( $approved_manifest[0]['storage_path'] ?? '' ), 'an approved manual row serves its immutable evidence revision manifest' );

$revision_id = (int) $approved_row['approved_revision_id'];
$saved_digest = $wpdb->evidence[ $revision_id ]['snapshot_digest'];
$wpdb->evidence[ $revision_id ]['snapshot_digest'] = str_repeat( '0', 64 );
workflow_check( null === GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $approved_row ), 'a tampered approval snapshot digest yields no viewable evidence' );
$wpdb->evidence[ $revision_id ]['snapshot_digest'] = $saved_digest;

$pending_row = $wpdb->training[12];
$pending_row['status'] = 'pending';
workflow_check( is_array( GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $pending_row ) ), 'a pending manual row serves its captured manifest' );

$rejected_row = $wpdb->training[12];
$rejected_row['status'] = 'rejected';
$rejected_row['approved_revision_id'] = null;
workflow_check( null === GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $rejected_row ), 'a rejected manual row exposes nothing, because its stored certificate was deleted' );

workflow_check( null === GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $wpdb->training[9] ), 'a Jotform row is never served through the manual evidence path' );

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit( 0 === $failures ? 0 : 1 );
