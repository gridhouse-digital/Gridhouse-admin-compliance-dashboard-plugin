<?php
/** Run actual Edit Records handler with synthetic platform stubs from the type suite. */
require __DIR__ . '/test-employment-type.php';
$start_checks = $checks;
$record = array( 'employment_date' => '2024-02-29', 'employment_source' => 'Synthetic HR reference', 'employment_verified' => '1' );
verify_type( submit_type( 'ajax_save_employee_records', $record ), 'verified date saved through real handler' );
$saved = GHCA_ACD_Employment_Record::get( 11 );
verify_type( '2024-02-29' === $saved['date'] && 90 === $saved['verified_by'] && $saved['verified_at'] > 0, 'date source and server attribution' );
verify_type( strtotime( '2024-02-29 UTC' ) === GHCA_ACD_Employment_Record::timestamp( 11 ), 'date-only timestamp' );
foreach ( array( '2025-02-29', 'today', "2024-02-29\0", array(), '2024-02-30', '2099-01-01' ) as $invalid ) {
  verify_type( ! submit_type( 'ajax_save_employee_records', array_merge( $record, array( 'employment_date' => $invalid ) ) ), 'bad/future date rejected' );
  verify_type( $saved === GHCA_ACD_Employment_Record::get( 11 ), 'previous date preserved' );
}
foreach ( array( 'nonce_ok', 'permission_ok', 'scope_ok' ) as $gate ) {
  $GLOBALS[$gate] = false;
  verify_type( ! submit_type( 'ajax_save_employee_records', array_merge( $record, array( 'employment_date' => '2024-03-01' ) ) ) && $saved === GHCA_ACD_Employment_Record::get( 11 ), 'existing authorization prevents date write' );
  $GLOBALS[$gate] = true;
}
verify_type( ! submit_type( 'ajax_save_employee_records', array_merge( $record, array( 'employment_verified' => '', 'employment_date' => '2024-03-01' ) ) ), 'changed date requires verification' );
verify_type( submit_type( 'ajax_save_employee_records', array_merge( $record, array( 'employment_verified' => '' ) ) ) && $saved === GHCA_ACD_Employment_Record::get( 11 ), 'unchanged date preserves verification' );
verify_type( ! submit_type( 'ajax_save_employee_records', array_merge( $record, array( 'employment_source' => '' ) ) ), 'source required' );
verify_type( ! submit_type( 'ajax_save_employee_records', array( 'employment_date' => '', 'employment_source' => '' ) ) && $saved === GHCA_ACD_Employment_Record::get( 11 ), 'blank cannot erase verified record' );
verify_type( 0 === GHCA_ACD_Employment_Record::timestamp( 12 ), 'no registration fallback' );
verify_type( true === $meta[11]['suspended'] && 123 === $meta[11]['course_completed_99'], 'training/access preserved' );
echo 'PASS: ' . ( $checks - $start_checks ) . " additional verified employment handler checks\n";
