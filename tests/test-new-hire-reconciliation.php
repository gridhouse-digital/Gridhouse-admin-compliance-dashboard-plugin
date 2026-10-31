<?php
/**
 * Dashboard / orientation-packet reconciliation for new hires.
 * Standalone: no WordPress, database or network. Run: php tests/test-new-hire-reconciliation.php
 */
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-audit-calculator.php';
require_once __DIR__ . '/../includes/class-compliance-program.php';

$T = array(
	'options' => array( 'ghca_new_hire_group_ids' => array( 9 ) ),
	'group_courses' => array( 9 => array( 135, 2180, 2905 ) ), // 2905 = "Fatal Five", deliberately NOT mapped.
	'tz' => 'America/Toronto', 'hire' => array(), 'worked_alone' => array(), 'registered' => '2026-06-02 09:00:00',
	'members' => array( 73 => array( 9 ) ),
	'done' => array(), // [user][course] => raw completion meta
	'flags' => array(), // [user][course] => bool completed per LearnDash
);
function ts( string $d ): int { return ( new DateTimeImmutable( $d, wp_timezone() ) )->getTimestamp(); }
function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function apply_filters( $hook, $v ) {
	if ( 'ghca_new_hire_group_ids' === $hook ) { return GHCA_Compliance_Program::filter_new_hire_group_ids( $v ); }
	return $v;
}
function learndash_is_user_in_group( $u, $g ) { return in_array( $g, $GLOBALS['T']['members'][ $u ] ?? array(), true ); }
function learndash_group_enrolled_courses( $g ) { return $GLOBALS['T']['group_courses'][ $g ] ?? array(); }
// Courses 3101-3103 cover the other required orientation areas; they are not in the new-hire group and are always done the day after hire.
function extra_area_course( $c ): bool { return in_array( (int) $c, array( 3101, 3102, 3103 ), true ); }
function learndash_course_completed( $u, $c ) { return extra_area_course( $c ) || ! empty( $GLOBALS['T']['flags'][ $u ][ $c ] ); }
function learndash_course_status( $c, $u, $x ) { return empty( $GLOBALS['T']['flags'][ $u ][ $c ] ) ? 'not_started' : 'completed'; }
function learndash_course_progress( $a ) { return array( 'percentage' => 0 ); }
function learndash_user_get_enrolled_courses( $u ) { return array( 135, 2180, 2905 ); }
function get_the_title( $id ) { return 2905 === $id ? 'Fatal Five' : ( 'Course ' . $id ); }
function get_permalink( $id ) { return 'https://example.test/c/' . $id; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_post_meta( $id, $k, $s ) { return ''; }
function get_post_field( $f, $id ) { return ''; }
function get_userdata( $u ) { return (object) array( 'user_registered' => $GLOBALS['T']['registered'], 'roles' => array( 'subscriber' ) ); }
function get_user_meta( $u, $k, $s ) {
	if ( GHCA_ACD_Employment_Record::META_KEY === $k ) {
		return array( 'date' => $GLOBALS['T']['hire'][ $u ] ?? '2026-06-02', 'source' => 'Synthetic HR record', 'verified_by' => 90, 'verified_at' => time() );
	}
	if ( 'ghca_worked_alone_date' === $k ) { return $GLOBALS['T']['worked_alone'][ $u ] ?? ''; }
	if ( 0 === strpos( $k, 'course_completed_' ) && extra_area_course( substr( $k, 17 ) ) ) { return ( new DateTimeImmutable( ( $GLOBALS['T']['hire'][ $u ] ?? '2026-06-02' ) . ' 12:00:00 +1 day', wp_timezone() ) )->format( 'Y-m-d H:i:s' ); }
	if ( 0 === strpos( $k, 'course_completed_' ) ) { return $GLOBALS['T']['done'][ $u ][ (int) substr( $k, 17 ) ] ?? ''; }
	return '';
}
function wp_timezone() { return new DateTimeZone( $GLOBALS['T']['tz'] ); }
function wp_date( $f, $t ) { return ( new DateTimeImmutable( '@' . $t ) )->setTimezone( wp_timezone() )->format( $f ); }
function __( $t, $d = '' ) { return $t; }
$GLOBALS['wp_roles'] = (object) array( 'roles' => array( 'subscriber' => array( 'name' => 'Employee' ) ) );

$fails = 0;
function check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }

$mappings = array(
	135  => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ),
	2180 => array( 'odp_category' => 'abuse_prevention', 'credit_hours' => 1, 'is_orientation' => 1 ),
	3101 => array( 'odp_category' => 'individual_rights', 'credit_hours' => 1, 'is_orientation' => 1 ),
	3102 => array( 'odp_category' => 'reporting_incidents', 'credit_hours' => 1, 'is_orientation' => 1 ),
	3103 => array( 'odp_category' => 'job_related', 'credit_hours' => 1, 'is_orientation' => 1 ),
);
function packet( int $u = 73 ): array {
	global $mappings;
	GHCA_Compliance_Program::reset_runtime_cache();
	return GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => $u, 'name' => 'Jane Q Employee' ), 'orientation', $mappings );
}
function complete( int $c, string $date, int $u = 73 ): void { $GLOBALS['T']['done'][ $u ][ $c ] = ts( $date ); $GLOBALS['T']['flags'][ $u ][ $c ] = true; }

// --- 1. Unmapped, incomplete group course (the user-73 case) -----------------------------
complete( 135, '2026-06-10 12:00:00' );
complete( 2180, '2026-06-12 12:00:00' );
$d = packet();
GHCA_Compliance_Program::reset_runtime_cache();
$dash = GHCA_Compliance_Program::get_user_status( 73 );
check( ! $dash['complete'], 'dashboard shows the employee incomplete' );
check( '' === $d['completion_date'], 'incomplete: no overall completed orientation date' );
check( 'Yes' !== $d['completed_within_30'] && 'No' === $d['completed_within_30'], 'incomplete: no "within 30 days: Yes"' );
check( 'Yes' !== $d['worked_alone_compliant'] && 'Not complete' === $d['worked_alone_compliant'], 'incomplete: no "before working alone: Yes"' );
check( array( 2905 ) === array_column( $d['new_hire_program']['outstanding'], 'course_id' ), 'packet names the outstanding program course explicitly' );
check( array( 2905 ) === array_column( $d['new_hire_program']['unmapped_required'], 'course_id' ), 'packet reports the required course is not ODP-mapped' );
check( 'Jane' === $d['first_name'] && 'Q Employee' === $d['last_name'], 'full employee name preserved' );
check( ! isset( $d['category_statuses']['person_centered'] ) || 'Completed' === $d['category_statuses']['person_centered'], 'mapped ODP category stays Completed' );
check( 'Completed' === $d['category_statuses']['abuse_prevention'], 'unrelated unfinished course does not mark ODP row incomplete' );
check( ! in_array( 'Missing training', array( $d['category_statuses']['person_centered'], $d['category_statuses']['abuse_prevention'] ), true ) && 0 === count( array_diff( array_keys( $d['category_statuses'] ), array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports', 'individual_plan', 'job_related', 'general', 'odp_annual_training' ) ) ), 'the unmapped course is never placed in an ODP category row' );

// Worked-alone date recorded does not rescue an incomplete program.
// --- 2. Unmapped, completed group course --------------------------------------------------
complete( 2905, '2026-06-20 12:00:00' );
$d = packet();
check( GHCA_Compliance_Program::get_user_status( 73 )['complete'], 'dashboard now complete' );
check( '06/20/2026' === $d['completion_date'], 'complete: date is the LAST required completion (unmapped course), not last mapped (06/12)' );
check( 'Yes' === $d['completed_within_30'], 'complete: within 30 days of verified hire (06/02 -> 06/20)' );
check( array() === $d['new_hire_program']['outstanding'] && $d['new_hire_program']['complete'], 'complete: nothing outstanding' );
// Last required completion past day 30 flips timing even though mapped courses were on time.
complete( 2905, '2026-07-15 12:00:00' );
$d = packet();
check( '07/15/2026' === $d['completion_date'] && 'No' === $d['completed_within_30'], 'late unmapped completion makes the 30-day result No' );

// --- 3. Missing completion dates ------------------------------------------------------------
$GLOBALS['T']['done'][73][2905] = '';
$GLOBALS['T']['flags'][73][2905] = true; // marked complete, no date
$d = packet();
check( '' === $d['completion_date'], 'missing date: no overall date' );
check( 'Missing evidence' === $d['completed_within_30'] && 'Missing evidence' === $d['worked_alone_compliant'], 'missing date: timing not certified' );
check( array( 2905 ) === array_column( $d['new_hire_program']['missing_dates'], 'course_id' ), 'missing date: course reported' );
$GLOBALS['T']['done'][73][2905] = 'next friday'; // relative strings are not evidence
$d = packet();
check( 'Missing evidence' === $d['completed_within_30'], 'relative date string is not accepted as a completion date' );

// --- 4. Course added to the group after mapping ----------------------------------------------
complete( 2905, '2026-06-20 12:00:00' );
check( 'Yes' === packet()['completed_within_30'], 'baseline complete before the course is added' );
$GLOBALS['T']['group_courses'][9][] = 3001; // new course, never mapped
$d = packet();
check( '' === $d['completion_date'] && 'No' === $d['completed_within_30'] && 'Not complete' === $d['worked_alone_compliant'], 'newly added group course reopens the overall result' );
check( array( 3001 ) === array_column( $d['new_hire_program']['outstanding'], 'course_id' ), 'newly added course reported as outstanding' );
check( 'Completed' === $d['category_statuses']['person_centered'], 'ODP rows untouched by the new course' );

// --- 5. Agency-specific requirement is configured by group membership in LearnDash ----------
$GLOBALS['T']['group_courses'][9] = array( 135, 2180 ); // group without 2905
$GLOBALS['T']['flags'][73][2905] = false;
$GLOBALS['T']['done'][73][2905] = '';
check( 'Yes' === packet()['completed_within_30'], 'group without the course: complete on group courses alone' );
$GLOBALS['T']['group_courses'][9] = array( 135, 2180, 2905 ); // agency adds the course to its group
$d = packet();
check( ! GHCA_Compliance_Program::get_user_status( 73 )['complete'] && 'No' === $d['completed_within_30'] && '' === $d['completion_date'], 'adding the course to the group makes dashboard and packet incomplete' );
check( array( 2905 ) === array_column( $d['new_hire_program']['outstanding'], 'course_id' ) && 'Completed' === $d['category_statuses']['abuse_prevention'], 'outstanding group course reported; ODP rows untouched' );
check( false === strpos( file_get_contents( __DIR__ . '/../includes/class-audit-calculator.php' ) . file_get_contents( __DIR__ . '/../includes/class-compliance-program.php' ), '2905' ), 'no course ID is hardcoded in plugin code' );
complete( 2905, '2026-06-20 12:00:00' );
$GLOBALS['T']['group_courses'][9][] = 3001; // restore the fixture used below

// --- 6. No active new-hire group --------------------------------------------------------------
$GLOBALS['T']['members'][74] = array();
$d = packet( 74 );
check( false === $d['new_hire_program']['active'], 'no group: program not active' );
check( '' === $d['completion_date'] && 'No' === $d['completed_within_30'], 'no group: no mapped completions, nothing claimed' );
complete( 135, '2026-06-10 12:00:00', 74 );
complete( 2180, '2026-06-12 12:00:00', 74 );
$d = packet( 74 );
check( '06/12/2026' === $d['completion_date'] && 'Yes' === $d['completed_within_30'], 'no group: legacy mapped-course behaviour is unchanged' );
$GLOBALS['T']['options']['ghca_new_hire_group_ids'] = array();
check( false === packet()['new_hire_program']['active'], 'unconfigured program is not active' );

// --- 7. Site time zone, not UTC, decides calendar dates and day counts ----------------------
$GLOBALS['T']['options']['ghca_new_hire_group_ids'] = array( 9 );
$GLOBALS['T']['group_courses'][9] = array( 135, 2180, 2905 );
function tz_case( string $zone, string $hire, string $last_done ): array {
	$GLOBALS['T']['tz'] = $zone;
	$GLOBALS['T']['hire'][69] = $hire;
	complete( 135, $hire . ' 10:00:00', 69 );
	complete( 2180, $hire . ' 11:00:00', 69 );
	complete( 2905, $last_done, 69 );
	$GLOBALS['T']['members'][69] = array( 9 );
	return packet( 69 );
}
// Toronto (behind UTC): 23:30 local on day 30 is already the next day in UTC.
$last = '2025-11-16 23:30:00';
check( gmdate( 'm/d/Y', ts( $last ) ) === '11/17/2025', 'fixture: 23:30 Toronto on 11/16 is 11/17 in UTC' );
$d = tz_case( 'America/Toronto', '2025-10-17', $last );
check( '11/16/2025' === $d['completion_date'], 'completion at 23:30 local keeps the local date (11/16), not the UTC date' );
check( 'Yes' === $d['completed_within_30'], 'day 30 (hire 10/17, completed 11/16 23:30 local) is Yes' );
$d = tz_case( 'America/Toronto', '2025-10-17', '2025-11-17 00:10:00' );
check( '11/17/2025' === $d['completion_date'] && 'No' === $d['completed_within_30'], 'day 31 is No' );
// Auckland (ahead of UTC): 00:30 local on day 30 is still the previous day in UTC.
$d = tz_case( 'Pacific/Auckland', '2025-10-17', '2025-11-16 00:30:00' );
check( gmdate( 'm/d/Y', ts( '2025-11-16 00:30:00' ) ) === '11/15/2025', 'fixture: 00:30 Auckland on 11/16 is 11/15 in UTC' );
check( '11/16/2025' === $d['completion_date'] && 'Yes' === $d['completed_within_30'], 'ahead-of-UTC zone: local date and day-30 Yes' );
$d = tz_case( 'Pacific/Auckland', '2025-10-17', '2025-11-17 00:30:00' );
check( '11/17/2025' === $d['completion_date'] && 'No' === $d['completed_within_30'], 'ahead-of-UTC zone: day 31 is No' );
// Before working alone compares local dates.
$GLOBALS['T']['worked_alone'][69] = '2025-11-16';
$d = tz_case( 'America/Toronto', '2025-10-17', $last );
check( 'Yes' === $d['worked_alone_compliant'], 'working alone on the local completion date is not before completion' );
$GLOBALS['T']['worked_alone'][69] = '2025-11-15';
check( 'No (Violation)' === tz_case( 'America/Toronto', '2025-10-17', $last )['worked_alone_compliant'], 'working alone the local day before completion is a violation' );
$GLOBALS['T']['worked_alone'][69] = '';
// Dashboard deadline: calendar days in site zone across the DST end (2025-11-02, Toronto).
$GLOBALS['T']['tz'] = 'America/Toronto';
$GLOBALS['T']['registered'] = '2025-10-30 12:00:00';
GHCA_Compliance_Program::reset_runtime_cache();
$st = GHCA_Compliance_Program::get_user_status( 73 );
check( $st['deadline_ts'] === ts( '2025-11-29 08:00:00' ), 'program deadline keeps local time-of-day across DST (30 calendar days)' );
check( '2025-11-16' === GHCA_Audit_Calculator::local_date( ts( '2025-11-16 23:30:00' ) ), 'local_date helper returns the site-zone date' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
