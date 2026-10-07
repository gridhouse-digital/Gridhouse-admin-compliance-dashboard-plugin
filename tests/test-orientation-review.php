<?php
// Transactional repository behavior with synthetic in-memory WordPress dependencies.
define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $type, $utc = false ) { return '2026-09-18 12:00:00'; }
function sanitize_key( $v ) { return $v; }
class GHCA_ACD_Jotform_Schema {
    static function training_table() { return 'training'; }
    static function evidence_table() { return 'evidence'; }
    static function events_table() { return 'events'; }
}
class GHCA_ACD_External_Evidence_Store {
    static function verify_stored_manifest( $training, $manifest ) { return $GLOBALS['evidence_valid'] ? true : new WP_Error(); }
}
require __DIR__ . '/../includes/jotform/class-jotform-repository.php';
class OrientationDB {
    public $rows, $backup, $insert_id = 10, $fail = '';
    function __construct() {
        $json = json_encode( array( 'title' => 'Synthetic course', 'credit_hours' => 2 ) );
        $manifest = json_encode( array( array( 'file_index' => 0 ) ) );
        $this->rows = array( 'training' => array( 1 => array( 'id' => 1, 'status' => 'approved', 'employee_user_id' => 900001, 'approved_revision_id' => 10 ) ), 'evidence' => array( 10 => array( 'id' => 10, 'training_id' => 1, 'revision_no' => 1, 'status' => 'approved', 'catalog_snapshot' => $json, 'evidence_manifest' => $manifest, 'snapshot_digest' => hash( 'sha256', $json . "\n" . $manifest ) ) ), 'events' => array() );
    }
    function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    function get_row( $q, $format ) { return $this->rows[strpos( $q[0], 'FROM training' ) !== false ? 'training' : 'evidence'][$q[1][0]] ?? null; }
    function get_var( $q ) { return max( array_column( $this->rows['evidence'], 'revision_no' ) ); }
    function query( $sql ) {
        if ( $sql === $this->fail ) { return false; }
        if ( 'START TRANSACTION' === $sql ) { $this->backup = $this->rows; }
        if ( 'ROLLBACK' === $sql ) { $this->rows = $this->backup; }
        return 1;
    }
    function insert( $table, $row ) {
        if ( 'insert:' . $table === $this->fail ) { return false; }
        $this->insert_id++; $row['id'] = $this->insert_id; $this->rows[$table][$this->insert_id] = $row; return 1;
    }
    function update( $table, $values, $where ) {
        if ( 'update:' . $table === $this->fail ) { return false; }
        foreach ( $this->rows[$table] as &$row ) {
            if ( array_diff_assoc( $where, $row ) ) { continue; }
            $row = array_merge( $row, $values ); return 1;
        }
        return 0;
    }
}
$checks = 0;
function verify( $value, $message ) { global $checks; if ( ! $value ) { throw new RuntimeException( $message ); } $checks++; }
$evidence_valid = true; $wpdb = new OrientationDB(); $before = $wpdb->rows;
verify( GHCA_ACD_Jotform_Repository::set_orientation_applicability( 1, 10, true, 90, 'Reviewed orientation relevance' ), 'Valid review succeeds' );
$new = $wpdb->rows['evidence'][11]; $snapshot = json_decode( $new['catalog_snapshot'], true );
verify( true === $snapshot['applies_to_orientation'] && 90 === $snapshot['orientation_review']['actor_id'], 'Choice and reviewer persisted' );
verify( 2 === $snapshot['credit_hours'] && $new['evidence_manifest'] === $before['evidence'][10]['evidence_manifest'], 'Existing hours and file references preserved' );
verify( $before['evidence'][10]['catalog_snapshot'] === $wpdb->rows['evidence'][10]['catalog_snapshot'] && 'superseded' === $wpdb->rows['evidence'][10]['status'], 'Previous snapshot retained unchanged' );
verify( 11 === $wpdb->rows['training'][1]['approved_revision_id'] && 1 === count( $wpdb->rows['events'] ), 'Current revision and audit event advance together' );
verify( ! GHCA_ACD_Jotform_Repository::set_orientation_applicability( 1, 10, false, 90, 'Stale form' ), 'Stale revision rejected' );
verify( GHCA_ACD_Jotform_Repository::set_orientation_applicability( 1, 11, false, 90, 'No longer applicable' ), 'Unmark creates another revision' );
foreach ( array( 'START TRANSACTION', 'COMMIT', 'insert:evidence', 'insert:events', 'update:evidence', 'update:training' ) as $failure ) {
    $wpdb = new OrientationDB(); $wpdb->fail = $failure; $before = $wpdb->rows;
    verify( ! GHCA_ACD_Jotform_Repository::set_orientation_applicability( 1, 10, true, 90, 'Synthetic reason' ) && $before === $wpdb->rows, 'Atomic failure: ' . $failure );
}
foreach ( array( 'reason', 'actor', 'revoked', 'digest', 'file' ) as $failure ) {
    $wpdb = new OrientationDB(); $evidence_valid = 'file' !== $failure;
    if ( 'revoked' === $failure ) { $wpdb->rows['training'][1]['status'] = 'revoked'; }
    if ( 'digest' === $failure ) { $wpdb->rows['evidence'][10]['snapshot_digest'] = 'invalid'; }
    $before = $wpdb->rows;
    verify( ! GHCA_ACD_Jotform_Repository::set_orientation_applicability( 1, 10, true, 'actor' === $failure ? 0 : 90, 'reason' === $failure ? '' : 'Synthetic reason' ) && $before === $wpdb->rows, 'Invalid review blocked: ' . $failure );
}
echo "$checks orientation revision checks passed.\n";
