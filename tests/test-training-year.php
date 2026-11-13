<?php
/**
 * Training-year rules (2.0): synthetic fixtures only, no WordPress, database or real records.
 *   php tests/test-training-year.php
 * Covers a Feb 29 hire, a completion on the anniversary date, a calendar-year employee hired mid-year, a 12-hour
 * role, the due date and its missing-data labels, calendar-day lifespans, annual courses without lifespans, and
 * the new-hire anchor.
 */
require __DIR__ . '/test-audit-date-evidence.php'; // stubs, fixture, check(), calculate(); runs its own checks first
require_once dirname( __DIR__ ) . '/includes/class-course-lifespans.php';
$base_checks = $checks;

$local = static function ( string $when, string $tz = 'UTC' ): int { return ( new DateTimeImmutable( $when, new DateTimeZone( $tz ) ) )->getTimestamp(); };
$ymd   = static function ( int $ts, string $tz = 'UTC' ): string { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d H:i:s' ); };
$hire  = static function ( string $date ): void { global $fixture; $fixture['meta']['ghca_acd_verified_employment'] = array( 'date' => $date, 'source' => 'Synthetic HR reference', 'verified_by' => 90, 'verified_at' => time() ); };

// ---- 1. Feb 29 hire, anniversary cycle (site time zone New York).
$GLOBALS['site_tz'] = 'America/New_York';
$tz  = new DateTimeZone( 'America/New_York' );
$doh = $local( '2024-02-29 00:00:00', 'America/New_York' );
$w   = GHCA_Audit_Calculator::resolve_annual_window( $doh, 'employee_start_date', $local( '2027-06-01 12:00', 'America/New_York' ), $tz );
check( '03/01/2027' === $w['start_date'] && '02/28/2028' === $w['end_date'] && '2028-02-29 00:00:00' === $ymd( $w['end_ts'], 'America/New_York' ), 'Feb 29 hire, non-leap year: the year runs Mar 1 2027 – Feb 28 2028 (end exclusive at Feb 29 2028)' );
$w2 = GHCA_Audit_Calculator::resolve_annual_window( $doh, 'employee_start_date', $local( '2028-02-29 10:00', 'America/New_York' ), $tz );
check( '02/29/2028' === $w2['start_date'] && '02/28/2029' === $w2['end_date'] && $w2['start_ts'] === $w['end_ts'], 'Feb 29 hire, leap year: the next year starts Feb 29 2028, exactly where the last ended (no gap, no overlap)' );
$w3 = GHCA_Audit_Calculator::resolve_annual_window( $doh, 'employee_start_date', $local( '2028-02-28 23:59', 'America/New_York' ), $tz );
check( $w3['start_ts'] === $w['start_ts'], 'Feb 29 hire: Feb 28 2028 still belongs to the Mar 1 2027 year' );
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$hire( '2024-02-29' );
GHCA_ACD_Training_Year::set_override( true );
$due = GHCA_ACD_Training_Year::due( 900001, $local( '2027-06-01 12:00', 'America/New_York' ) );
check( 'February 28, 2028' === $due['due_date_label'] && '2028-02-28 23:59:59' === $ymd( $due['due_timestamp'], 'America/New_York' ), 'Feb 29 hire: due date is the last day of the training year (Feb 28 2028, to its last second)' );
check( 'Mar 1, 2027 – Feb 28, 2028' === $due['training_year_label'], 'Feb 29 hire: training-year label for the drawer' );

// ---- 2. Completion on the anniversary date counts toward the new year, not the old one.
$GLOBALS['site_tz'] = 'UTC';
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$today       = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
$anniversary = $today->modify( '-30 days' );                        // this year's anniversary, 30 days ago
$hire( $anniversary->modify( '-1 year' )->format( 'Y-m-d' ) );
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 3 ), 900002 => array( 'odp_category' => 'abuse_prevention', 'credit_hours' => 2 ) );
$fixture['meta']['course_completed_900001'] = $anniversary->getTimestamp();       // 00:00:00 on the anniversary
$fixture['meta']['course_completed_900002'] = $anniversary->getTimestamp() - 1;   // 23:59:59 the day before
$data = calculate();
check( $anniversary->format( 'm/d/Y' ) === $data['start_date'] && $anniversary->modify( '+1 year -1 day' )->format( 'm/d/Y' ) === $data['end_date'], 'anniversary window: starts on the anniversary, shown ending the day before the next one' );
check( 3 == $data['total_annual_hrs'] && array( 900001 ) === array_column( $data['raw_completed_courses'], 'course_id' ), 'a completion at 00:00 on the anniversary counts in the new year; one a second earlier belongs to the previous year' );

// ---- 3. Calendar-year employee hired mid-year.
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = 'calendar_year';
$hire( '2026-06-15' );
$w = GHCA_Audit_Calculator::resolve_annual_window( $local( '2026-06-15' ), 'calendar_year', $local( '2026-11-13 09:00' ), new DateTimeZone( 'UTC' ) );
check( '06/15/2026' === $w['start_date'] && '12/31/2026' === $w['end_date'], 'calendar year, hired mid-year: the first year runs from the hire date to Dec 31' );
$due = GHCA_ACD_Training_Year::due( 900001, $local( '2026-11-13 09:00' ) );
check( 'December 31, 2026' === $due['due_date_label'] && '2026-12-31 23:59:59' === $ymd( $due['due_timestamp'] ) && 'Jun 15, 2026 – Dec 31, 2026' === $due['training_year_label'], 'calendar year: due Dec 31, window Jun 15 – Dec 31' );
$due = GHCA_ACD_Training_Year::due( 900001, $local( '2027-01-01 00:00' ) );
check( 'December 31, 2027' === $due['due_date_label'] && 'Jan 1, 2027 – Dec 31, 2027' === $due['training_year_label'], 'calendar year: the next year is the full Jan 1 – Dec 31' );

// ---- 4. Missing data: no fallback date.
reset_fixture();
unset( $fixture['meta']['ghca_acd_verified_employment'] );
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$due = GHCA_ACD_Training_Year::due( 900001 );
check( 0 === $due['due_timestamp'] && 'Hire date missing' === $due['due_date_label'] && '' === $due['training_year_label'], 'no verified hire date: "Hire date missing", no timestamp, no fallback date' );
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = '';
check( 'Training year not configured' === GHCA_ACD_Training_Year::due( 900001 )['due_date_label'], 'no annual cycle configured: "Training year not configured"' );

// ---- 5. Role-based threshold: a 12-hour role.
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'odp_categories' => array( 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports', 'individual_plan' ), 'credit_hours' => 13 ) );
$fixture['meta']['course_completed_900001'] = time() - DAY_IN_SECONDS;
$fixture['meta'][ GHCA_ACD_Training_Year::REQUIREMENT_META ] = 'management';
GHCA_ACD_Training_Year::set_override( false );
$off = calculate();
GHCA_ACD_Training_Year::set_override( true );
$on = calculate();
check( 'in_progress' === $off['annual_status'] && 24 === $off['required_annual_hrs'], 'rules off: 13 hours is in progress against 24 (unchanged)' );
check( 'compliant' === $on['annual_status'] && 12 === $on['required_annual_hrs'] && false !== strpos( $on['required_annual_label'], '12 h' ), 'rules on, management role: 13 hours meets the 12-hour requirement' );
$fixture['meta'][ GHCA_ACD_Training_Year::REQUIREMENT_META ] = 'dsp';
check( 'in_progress' === calculate()['annual_status'], 'rules on, direct support professional: 13 hours is still short of 24' );
unset( $fixture['meta'][ GHCA_ACD_Training_Year::REQUIREMENT_META ] );
$unset = calculate();
check( 'in_progress' === $unset['annual_status'] && 24 === $unset['required_annual_hrs'] && false === GHCA_ACD_Training_Year::requirement( 900001 )['set'], 'rules on, requirement not set: 24 hours is assumed' );
check( 'compliant' === GHCA_Audit_Calculator::annual_status( 12, time(), time() + 9, 12 ) && 'in_progress' === GHCA_Audit_Calculator::annual_status( 11.99, time(), time() + 9, 12 ) && 'compliant' === GHCA_Audit_Calculator::annual_status( 24, time(), time() + 9 ), 'annual_status(): the threshold is a parameter; 24 by default' );
foreach ( GHCA_ACD_Training_Year::requirements() as $key => $choice ) {
	$want = in_array( $key, array( 'dsp', 'life_sharer', 'dsp_supervisor' ), true ) ? 24 : ( 0 === strpos( $key, 'none_' ) ? 0 : 12 );
	check( $want === $choice[1] && ( 0 !== $want || false !== strpos( $choice[0], 'Not required — ' ) ), 'override ' . $key . ' is ' . $choice[1] . ' h' . ( 0 === $want ? ' and names its exception' : '' ) );
}
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
\GHCA_Audit_PDF::render_cover( $pdf, $on, 'annual' );
check( false !== strpos( $pdf->html, ' of 12' ) && false === strpos( $pdf->html, ' of 24' ), 'packet: recorded hours are shown against the employee’s 12-hour requirement' );

// ---- 6. Lifespans: calendar days in the site time zone, none for annual (mapped) courses.
$ny = new DateTimeZone( 'America/New_York' );
$done = $local( '2026-03-01 12:00', 'America/New_York' ); // DST starts Mar 8 2026
$cal = GHCA_Course_Lifespans::evaluate( true, $done, 30, 7, $done, $ny );
$sec = GHCA_Course_Lifespans::evaluate( true, $done, 30, 7, $done );
check( '2026-03-31 12:00:00' === $ymd( $cal['expiration_ts'], 'America/New_York' ) && '2026-03-31 13:00:00' === $ymd( $sec['expiration_ts'], 'America/New_York' ), 'lifespan across a DST change: calendar days keep 12:00; N × 86400 seconds drifts to 13:00' );
$leap = GHCA_Course_Lifespans::evaluate( true, $local( '2027-03-01 09:00', 'America/New_York' ), 366, 30, $done, $ny );
check( '2028-03-01 09:00:00' === $ymd( $leap['expiration_ts'], 'America/New_York' ), 'lifespan across Feb 29: 366 calendar days lands on the same clock time' );
check( 'expiring_soon' === GHCA_Course_Lifespans::evaluate( true, $done, 30, 7, $local( '2026-03-24 12:00', 'America/New_York' ), $ny )['state'] && 'current' === GHCA_Course_Lifespans::evaluate( true, $done, 30, 7, $local( '2026-03-24 11:59', 'America/New_York' ), $ny )['state'], 'calendar warning window starts exactly 7 calendar days before expiry' );
reset_fixture();
$fixture['options']['ghca_acd_audit_mapping'] = array( 900001 => array( 'odp_category' => 'person_centered' ) );
$fixture['options'][ GHCA_Course_Lifespans::OPTION_LIFESPANS ] = array( 900001 => 365, 900002 => 365 );
GHCA_ACD_Training_Year::set_override( false );
check( 365 === GHCA_Course_Lifespans::decorate( 900001, true, time() - DAY_IN_SECONDS )['lifespan_days'], 'rules off: an annual course keeps its lifespan (unchanged)' );
GHCA_ACD_Training_Year::set_override( true );
$annual_course = GHCA_Course_Lifespans::decorate( 900001, true, time() - DAY_IN_SECONDS );
check( 0 === $annual_course['lifespan_days'] && 0 === $annual_course['expiration_ts'] && '' === $annual_course['expiration_label'], 'rules on: a course mapped to an ODP annual category has no expiry (the drawer shows the training-year due date)' );
check( 365 === GHCA_Course_Lifespans::decorate( 900002, true, time() - DAY_IN_SECONDS )['lifespan_days'], 'rules on: an unmapped course keeps its lifespan' );

// ---- 7. New-hire anchor.
reset_fixture();
$hire( '2026-10-01' );
check( array( 'ts' => $local( '2026-10-01' ), 'basis' => 'hire' ) === GHCA_ACD_Training_Year::new_hire_anchor( 900001 ), 'new-hire window starts on the verified hire date' );
$fixture['meta']['ghca_first_service_date'] = '2026-10-10';
check( 'hire' === GHCA_ACD_Training_Year::new_hire_anchor( 900001 )['basis'], 'first service date is ignored unless the agency allows it' );
$fixture['options'][ GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR ] = 'hire_or_first_service';
check( array( 'ts' => $local( '2026-10-10' ), 'basis' => 'first_service' ) === GHCA_ACD_Training_Year::new_hire_anchor( 900001 ), 'allowed: a later first service date starts the window (§ 6100.142(a))' );
$fixture['meta']['ghca_first_service_date'] = '2026-09-20';
check( 'hire' === GHCA_ACD_Training_Year::new_hire_anchor( 900001 )['basis'], 'a first service date before hire never moves the window earlier' );
unset( $fixture['meta']['ghca_acd_verified_employment'] );
check( array( 'ts' => 0, 'basis' => 'missing' ) === GHCA_ACD_Training_Year::new_hire_anchor( 900001 ), 'no verified hire date: no anchor (reported as Hire date missing)' );
check( '2026-04-07 00:00:00' === $ymd( GHCA_ACD_Training_Year::add_days( $local( '2026-03-08 00:00', 'America/New_York' ), 30, $ny ), 'America/New_York' ), 'add_days(): calendar days, unaffected by DST' );

GHCA_ACD_Training_Year::set_override( null );
echo 'PASS: ' . ( $checks - $base_checks ) . " training-year checks (synthetic; no WordPress)\n";
