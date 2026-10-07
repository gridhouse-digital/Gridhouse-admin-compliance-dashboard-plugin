<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-audit-calculator.php';

$GLOBALS['audit_test_descriptions'] = array(
	10 => array( 'meta' => '<p>LearnDash short description</p>', 'setting' => '', 'excerpt' => 'Wrong fallback' ),
	11 => array( 'meta' => '', 'setting' => '<p>Mirrored LearnDash description</p>', 'excerpt' => 'Wrong fallback' ),
	12 => array( 'meta' => '<p>' . str_repeat( 'Full description ', 30 ) . '</p><p>Second paragraph.</p>', 'setting' => '', 'excerpt' => '' ),
);
$GLOBALS['audit_test_users'] = array();
$GLOBALS['audit_test_completed'] = array();
$GLOBALS['audit_test_enrolled'] = array();
function get_post_meta( $course_id, $key, $single ) {
	return $GLOBALS['audit_test_descriptions'][ $course_id ]['meta'] ?? '';
}
function learndash_get_setting( $course_id, $key ) {
	return $GLOBALS['audit_test_descriptions'][ $course_id ]['setting'] ?? '';
}
function get_post_field( $field, $course_id ) {
	return $GLOBALS['audit_test_descriptions'][ $course_id ]['excerpt'] ?? '';
}
function get_userdata( $user_id ) {
	return $GLOBALS['audit_test_users'][ $user_id ] ?? false;
}
function get_user_meta( $user_id, $key, $single ) {
	if ( GHCA_ACD_Employment_Record::META_KEY === $key && 292 === $user_id ) {
		return array( 'date' => '2026-06-02', 'source' => 'Synthetic HR record', 'verified_by' => 90, 'verified_at' => time() );
	}
	if ( 0 === strpos( $key, 'course_completed_' ) ) {
		$course_id = (int) substr( $key, strlen( 'course_completed_' ) );
		return $GLOBALS['audit_test_completed'][ $user_id ][ $course_id ] ?? '';
	}
	return '';
}
function learndash_course_completed( $user_id, $course_id ) {
	return ! empty( $GLOBALS['audit_test_completed'][ $user_id ][ $course_id ] );
}
function learndash_user_get_enrolled_courses( $user_id ) {
	return $GLOBALS['audit_test_enrolled'][ $user_id ] ?? array();
}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_option( $key, $default = false ) {
	return $default;
}
function wp_timezone() {
	return new DateTimeZone( 'America/Toronto' );
}
function wp_date( $format, $timestamp ) {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format );
}
function get_the_title( $course_id ) {
	return 'Course ' . $course_id;
}

$fails = 0;
function audit_check( bool $condition, string $message ): void {
	global $fails;
	if ( $condition ) {
		echo "PASS: $message\n";
	} else {
		echo "FAIL: $message\n";
		$fails++;
	}
}

function audit_ts( string $date, DateTimeZone $timezone ): int {
	return ( new DateTimeImmutable( $date, $timezone ) )->getTimestamp();
}

$timezone = new DateTimeZone( 'America/Toronto' );
$user = (object) array( 'user_registered' => '2025-04-27 09:30:00' );
$hire_ts = GHCA_Audit_Calculator::registration_timestamp( $user );
$window = GHCA_Audit_Calculator::resolve_annual_window(
	$hire_ts,
	'employee_start_date',
	audit_ts( '2026-08-05 12:00:00', $timezone ),
	$timezone
);

audit_check( $window['start_date'] === '04/27/2026', 'anniversary packet uses the active 2026 period' );
audit_check( $window['end_date'] === '04/26/2027', 'displayed anniversary end date does not overlap the next period' );
audit_check( audit_ts( '2026-08-05 12:00:00', $timezone ) >= $window['start_ts'] && audit_ts( '2026-08-05 12:00:00', $timezone ) < $window['end_ts'], '2026 completions fall inside the active period' );

$calendar = GHCA_Audit_Calculator::resolve_annual_window(
	audit_ts( '2026-04-27 09:30:00', $timezone ),
	'calendar_year',
	audit_ts( '2026-08-05 12:00:00', $timezone ),
	$timezone
);
audit_check( $calendar['start_date'] === '04/27/2026' && $calendar['end_date'] === '12/31/2026', 'first calendar-year period begins on hire date' );

$leap = GHCA_Audit_Calculator::resolve_annual_window(
	audit_ts( '2024-02-29 09:30:00', $timezone ),
	'employee_start_date',
	audit_ts( '2025-08-05 12:00:00', $timezone ),
	$timezone
);
audit_check( $leap['start_date'] === '03/01/2025' && $leap['end_date'] === '02/28/2026', 'leap-day anniversary remains a continuous non-overlapping period' );

audit_check( GHCA_Audit_Calculator::annual_status( 23.5, 100, 200 ) === 'in_progress', 'incomplete active period is In Progress' );
audit_check( GHCA_Audit_Calculator::annual_status( 24, 100, 200 ) === 'compliant', '24 hours is Compliant' );
audit_check( GHCA_Audit_Calculator::annual_status( 23.5, 200, 200 ) === 'noncompliant', 'incomplete closed period is Non-Compliant' );

$description = GHCA_Audit_Calculator::short_description( '<p>Course&nbsp;summary &amp; objectives</p>' );
audit_check( $description === 'Course summary & objectives', 'course description is normalized to plain text' );
$long_description = GHCA_Audit_Calculator::short_description( str_repeat( 'a', 305 ) );
audit_check( strlen( $long_description ) === 300 && substr( $long_description, -3 ) === '...', 'course description is limited to 300 characters' );

audit_check( GHCA_Audit_Calculator::course_short_description( 10 ) === 'LearnDash short description', 'LearnDash Course Grid short description is the primary source' );
audit_check( GHCA_Audit_Calculator::course_short_description( 11 ) === 'Mirrored LearnDash description', 'LearnDash mirrored setting is the fallback source' );
$full_course_description = GHCA_Audit_Calculator::course_full_description( 12 );
audit_check( strlen( $full_course_description ) > 300 && substr( $full_course_description, -17 ) === 'Second paragraph.', 'course detail page description is unshortened and preserves paragraph separation' );

$mappings = array(
	1 => array( 'odp_category' => 'person_centered', 'credit_hours' => 3, 'is_orientation' => 1 ),
	2 => array( 'odp_category' => 'person_centered', 'credit_hours' => 2, 'is_orientation' => 0 ),
	3 => array( 'odp_category' => 'individual_rights', 'credit_hours' => 4, 'is_orientation' => 1 ),
);
$annual_required = GHCA_Audit_Calculator::required_hours( $mappings, 'annual' );
$orientation_required = GHCA_Audit_Calculator::required_hours( $mappings, 'orientation' );
audit_check( $annual_required['person_centered'] === 5.0 && $annual_required['individual_rights'] === 4.0, 'annual required hours sum mapped course credits by requirement' );
audit_check( $orientation_required['person_centered'] === 3.0 && $orientation_required['individual_rights'] === 4.0, 'orientation required hours include only orientation mappings' );

$GLOBALS['audit_test_users'][292] = (object) array(
	'user_registered' => '2026-06-02 09:00:00',
	'roles'           => array( 'subscriber' ),
);
$GLOBALS['audit_test_enrolled'][292] = array( 2180 );
$GLOBALS['audit_test_completed'][292][2180] = audit_ts( '2026-06-18 12:00:00', $timezone );
$GLOBALS['wp_roles'] = (object) array(
	'roles' => array( 'subscriber' => array( 'name' => 'Employee' ) ),
);
$orientation_audit = GHCA_Audit_Calculator::calculate_employee_audit_data(
	array( 'user_id' => 292, 'name' => 'Synthetic Employee' ),
	'orientation',
	array(
		135  => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
		2180 => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
	)
);
audit_check( $orientation_audit['completion_date'] === '06/18/2026', 'orientation completion date uses the employee completed course' );
audit_check( $orientation_audit['completed_within_30'] === 'Yes', 'unassigned orientation courses do not fail the 30-day check' );
$GLOBALS['audit_test_enrolled'][292] = array( 135, 2180 );
$incomplete_orientation_audit = GHCA_Audit_Calculator::calculate_employee_audit_data(
	array( 'user_id' => 292, 'name' => 'Synthetic Employee' ),
	'orientation',
	array(
		135  => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
		2180 => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
	)
);
audit_check( $incomplete_orientation_audit['completed_within_30'] === 'No', 'incomplete assigned orientation courses still fail the 30-day check' );

/*
 * 55 Pa. Code 6100.142 sets orientation content areas and timing, not an hours floor.
 * The old matrix printed a "Required" figure that was only the sum of whatever courses
 * the agency had mapped into each area, so enlarging the catalog made every employee
 * look less compliant against a threshold that does not exist in the regulation.
 */
$pdf_source = file_get_contents( __DIR__ . '/../includes/class-audit-pdf.php' );
audit_check(
	false !== strpos( $pdf_source, "'Hours Counted Here' : 'Time Spent'" ),
	'the matrix separates counted hours from topic coverage without implying statutory per-topic hours'
);
audit_check(
	false !== strpos( $pdf_source, "<th width=\"20%\"><strong>Completed On</strong></th>" ),
	'the orientation matrix shows when each required area was completed'
);
audit_check(
	false !== strpos( $pdf_source, 'not a regulatory threshold' ),
	'the orientation matrix states that recorded time is informational'
);
audit_check(
	false !== strpos( $pdf_source, "return \$achieved > 0 ? \$format_hours( \$achieved ) . ' hrs' : '0 hrs';" ),
	'orientation hour cells never render a required figure alongside the achieved value'
);

$calculator_source = file_get_contents( __DIR__ . '/../includes/class-audit-calculator.php' );
audit_check(
	false !== strpos( $calculator_source, "'date' => (int) \$metrics['person_centered']['date']" )
		&& false !== strpos( $calculator_source, "'date' => (int) \$metrics['job_related']['date']" ),
	'per-area completion dates reach the packet for every requirement area'
);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
