<?php
// Synthetic regression: orientation completion date and 30-day verdict require every applicable required area.
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-audit-calculator.php';

$GLOBALS['audit_test_users'] = array();
$GLOBALS['audit_test_completed'] = array();
$GLOBALS['audit_test_enrolled'] = array();
function get_post_meta( $course_id, $key, $single ) { return ''; }
function learndash_get_setting( $course_id, $key ) { return ''; }
function get_post_field( $field, $course_id ) { return ''; }
function get_userdata( $user_id ) { return $GLOBALS['audit_test_users'][ $user_id ] ?? false; }
function get_user_meta( $user_id, $key, $single ) {
	if ( GHCA_ACD_Employment_Record::META_KEY === $key && 101 === $user_id ) {
		return array( 'date' => '2026-10-01', 'source' => 'Synthetic HR record', 'verified_by' => 90, 'verified_at' => time() );
	}
	if ( 0 === strpos( $key, 'course_completed_' ) ) {
		return $GLOBALS['audit_test_completed'][ $user_id ][ (int) substr( $key, strlen( 'course_completed_' ) ) ] ?? '';
	}
	return '';
}
function learndash_course_completed( $user_id, $course_id ) { return ! empty( $GLOBALS['audit_test_completed'][ $user_id ][ $course_id ] ); }
function learndash_user_get_enrolled_courses( $user_id ) { return $GLOBALS['audit_test_enrolled'][ $user_id ] ?? array(); }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) { return $default; }
function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }
function wp_date( $format, $timestamp ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format ); }
function get_the_title( $course_id ) { return 'Course ' . $course_id; }

$fails = 0;
function gate_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": $message\n";
	if ( ! $condition ) { $fails++; }
}
function gate_ts( string $date ): int { return ( new DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp(); }

$GLOBALS['audit_test_users'][101] = (object) array( 'user_registered' => '2026-10-01 09:00:00', 'roles' => array( 'subscriber' ) );
$GLOBALS['wp_roles'] = (object) array( 'roles' => array( 'subscriber' => array( 'name' => 'Employee' ) ) );

// Five orientation areas, one course each; 1757 is a general course that is not an orientation requirement.
$mappings = array(
	201  => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
	202  => array( 'odp_category' => 'abuse_prevention', 'credit_hours' => 1, 'is_orientation' => 1 ),
	203  => array( 'odp_category' => 'individual_rights', 'credit_hours' => 1, 'is_orientation' => 1 ),
	204  => array( 'odp_category' => 'reporting_incidents', 'credit_hours' => 1, 'is_orientation' => 1 ),
	205  => array( 'odp_category' => 'job_related', 'credit_hours' => 1, 'is_orientation' => 1 ),
	1757 => array( 'odp_category' => 'general', 'credit_hours' => 1, 'is_orientation' => 0 ),
);
function gate_run( array $mappings ): array {
	return GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 101, 'name' => 'Synthetic Employee' ), 'orientation', $mappings );
}

// (a) user-101 pattern: only an unrelated course is complete, four of five areas missing.
$GLOBALS['audit_test_enrolled'][101] = array( 201, 202, 203, 204, 205, 1757 );
$GLOBALS['audit_test_completed'][101] = array( 1757 => gate_ts( '2026-10-30 12:00:00' ) );
$data = gate_run( $mappings );
gate_check( '' === $data['completion_date'], 'a: unrelated completed course supplies no orientation completion date' );
gate_check( 'Yes' !== $data['completed_within_30'], 'a: no timely "Yes" while required areas are missing' );
gate_check( 'Missing training' === $data['category_statuses']['abuse_prevention'], 'a: missing areas still show Missing training' );

// Same pattern with a general course the agency mapped as orientation: still no date.
$GLOBALS['audit_test_completed'][101] = array( 1757 => gate_ts( '2026-10-30 12:00:00' ) );
$data = gate_run( array( 1757 => array( 'odp_category' => 'general', 'credit_hours' => 1, 'is_orientation' => 1 ) ) + $mappings );
gate_check( '' === $data['completion_date'] && 'Yes' !== $data['completed_within_30'], 'a: general orientation-flagged course cannot supply the date or verdict' );

// (b) every required area complete within 30 days.
$GLOBALS['audit_test_completed'][101] = array(
	201 => gate_ts( '2026-10-05 12:00:00' ), 202 => gate_ts( '2026-10-12 12:00:00' ), 203 => gate_ts( '2026-10-08 12:00:00' ),
	204 => gate_ts( '2026-10-20 12:00:00' ), 205 => gate_ts( '2026-10-15 12:00:00' ), 1757 => gate_ts( '2026-10-30 12:00:00' ),
);
$data = gate_run( $mappings );
gate_check( '10/20/2026' === $data['completion_date'], 'b: completion date is the latest required completion (not the later general course)' );
gate_check( 'Yes' === $data['completed_within_30'], 'b: all required areas complete within 30 days is "Yes"' );

// (c) all complete but the last required item is after day 30 (hire 10/01; day 31 = 11/01).
$GLOBALS['audit_test_completed'][101][204] = gate_ts( '2026-11-02 12:00:00' );
$data = gate_run( $mappings );
gate_check( '11/02/2026' === $data['completion_date'] && 'Yes' !== $data['completed_within_30'], 'c: last required item after day 30 is not "Yes"' );

// (e) four areas complete, the fifth has no mapped course: unmapped is Needs review and blocks.
$four = $mappings; unset( $four[205] );
$GLOBALS['audit_test_completed'][101] = array( 201 => gate_ts( '2026-10-05 12:00:00' ), 202 => gate_ts( '2026-10-12 12:00:00' ), 203 => gate_ts( '2026-10-08 12:00:00' ), 204 => gate_ts( '2026-10-20 12:00:00' ) );
$data = gate_run( $four );
gate_check( 'Needs review' === $data['category_statuses']['job_related'] && '' === $data['completion_date'] && 'Yes' !== $data['completed_within_30'], 'e: unmapped area stays Needs review and blocks date and "Yes"' );

// (d) a not-applicable area does not block completion; an unapplied or missing one does.
$statuses = array( 'person_centered' => 'Completed', 'abuse_prevention' => 'Completed', 'individual_rights' => 'Not applicable', 'reporting_incidents' => 'Completed', 'job_related' => 'Completed — manually verified' );
$dates = array( 'person_centered' => 100, 'abuse_prevention' => 300, 'individual_rights' => 900, 'reporting_incidents' => 200, 'job_related' => 250 );
$gate = GHCA_Audit_Calculator::orientation_gate( $statuses, $dates );
gate_check( $gate['met'] && 300 === $gate['last_ts'], 'd: not-applicable area does not block and its date is ignored' );
$statuses['abuse_prevention'] = 'Missing training';
gate_check( ! GHCA_Audit_Calculator::orientation_gate( $statuses, $dates )['met'], 'd: a Missing training area blocks completion' );
$statuses['abuse_prevention'] = 'Needs review';
gate_check( ! GHCA_Audit_Calculator::orientation_gate( $statuses, $dates )['met'], 'd: a Needs review area blocks completion' );
$statuses['abuse_prevention'] = 'Completed';
gate_check( ! GHCA_Audit_Calculator::orientation_gate( $statuses, $dates, array( array( 'title' => 'A NEW HIRE ORIENTATION', 'included' => false, 'date' => 0 ) ) )['met'], 'd: "No included evidence" agency row counts as not met' );
gate_check( 400 === GHCA_Audit_Calculator::orientation_gate( $statuses, $dates, array( array( 'title' => 'A NEW HIRE ORIENTATION', 'included' => true, 'date' => 400 ) ) )['last_ts'], 'd: included agency row date is part of the latest completion' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
