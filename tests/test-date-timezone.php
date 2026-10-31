<?php
// Site-time-zone handling for completion evidence. Synthetic fixtures only; no WordPress, network or real records.
require __DIR__ . '/test-orientation-external.php';

function tz_set( string $zone ): void { $GLOBALS['site_tz'] = $zone; }
function tz_ts( string $local, string $zone = null ): int { return ( new DateTimeImmutable( $local, $zone ? new DateTimeZone( $zone ) : wp_timezone() ) )->getTimestamp(); }
function orientation_for( $raw, string $hire = '2025-10-17' ): array {
	global $fixture, $mappings;
	reset_fixture();
	$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ) );
	$fixture['meta']['ghca_acd_verified_employment']['date'] = $hire;
	$fixture['completed'][900001] = true;
	$fixture['meta']['course_completed_900001'] = $raw;
	// The other four required areas are complete early so the case under test decides the date.
	foreach ( array( 900011 => 'abuse_prevention', 900012 => 'individual_rights', 900013 => 'reporting_incidents', 900014 => 'job_related' ) as $id => $area ) {
		$mappings[ $id ] = array( 'odp_category' => $area, 'credit_hours' => 1, 'is_orientation' => 1 );
		$fixture['completed'][ $id ] = true;
		$fixture['meta'][ 'course_completed_' . $id ] = '2025-10-18';
	}
	return calculate( 'orientation' );
}

tz_set( 'America/Toronto' );
// --- Blocker 1: LearnDash completion parsing ---------------------------------------------------
$cases = array(
	// [label, raw value, expected cover date, expected within-30]
	array( 'date-only day 30', '2025-11-16', '11/16/2025', 'Yes' ),
	array( 'date-only day 31', '2025-11-17', '11/17/2025', 'No' ),
	array( 'US date-only day 30', '11/16/2025', '11/16/2025', 'Yes' ),
	array( 'US date-only day 31', '11/17/2025', '11/17/2025', 'No' ),
	array( 'offset-free datetime 23:30 local day 30', '2025-11-16 23:30:00', '11/16/2025', 'Yes' ),
	array( 'offset-free datetime 00:30 local day 31', '2025-11-17 00:30:00', '11/17/2025', 'No' ),
	array( 'explicit offset = 23:30 local day 30', '2025-11-17T04:30:00+00:00', '11/16/2025', 'Yes' ),
	array( 'explicit offset = 00:30 local day 31', '2025-11-17T05:30:00+00:00', '11/17/2025', 'No' ),
	array( 'explicit non-UTC offset kept (-05:00 = 23:30 local)', '2025-11-16T23:30:00-05:00', '11/16/2025', 'Yes' ),
	array( 'unix int 23:30 local day 30', tz_ts( '2025-11-16 23:30:00' ), '11/16/2025', 'Yes' ),
	array( 'unix string 00:30 local day 31', (string) tz_ts( '2025-11-17 00:30:00' ), '11/17/2025', 'No' ),
);
foreach ( $cases as $case ) {
	$data = orientation_for( $case[1] );
	check( $case[2] === $data['completion_date'] && $case[3] === $data['completed_within_30'], 'Toronto: ' . $case[0] . ' -> ' . $case[2] . ' / ' . $case[3] );
}
check( tz_ts( '2025-11-16', 'America/Toronto' ) === GHCA_Audit_Calculator::completion_timestamp( '2025-11-16' ), 'date-only parses as site-zone midnight, not UTC midnight' );
check( 1763353800 === GHCA_Audit_Calculator::completion_timestamp( '2025-11-16T23:30:00-05:00' ) && 1763353800 === GHCA_Audit_Calculator::completion_timestamp( 1763353800 ), 'explicit offsets and timestamps are preserved as recorded' );
check( 0 === GHCA_Audit_Calculator::completion_timestamp( 'tomorrow' ) && 0 === GHCA_Audit_Calculator::completion_timestamp( '2025-02-30' ), 'relative and impossible dates still rejected' );

// Same inputs, a zone ahead of UTC: results follow the site zone, nothing is hardcoded.
tz_set( 'Pacific/Auckland' );
foreach ( array( array( '2025-11-16', '11/16/2025', 'Yes' ), array( '2025-11-17', '11/17/2025', 'No' ), array( '2025-11-16 00:30:00', '11/16/2025', 'Yes' ) ) as $case ) {
	$data = orientation_for( $case[0] );
	check( $case[1] === $data['completion_date'] && $case[2] === $data['completed_within_30'], 'Auckland: ' . $case[0] . ' -> ' . $case[1] . ' / ' . $case[2] );
}

// --- Blocker 2: approved external certificates are site-zone calendar dates ---------------------
function external_orientation( string $date ): array {
	global $fixture, $mappings;
	reset_fixture();
	$mappings = array();
	$fixture['meta']['ghca_acd_verified_employment']['date'] = '2025-10-17';
	$fixture['external'] = array( array( 'revision_id' => 900030, 'training_id' => 900031, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => $date, 'category' => 'person_centered', 'credit_hours' => 2, 'title' => 'Synthetic external', 'applies_to_orientation' => true ) ) );
	foreach ( array( 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'job_related' ) as $i => $area ) {
		$fixture['external'][] = array( 'revision_id' => 900040 + $i, 'training_id' => 900050 + $i, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => '2025-10-18', 'category' => $area, 'credit_hours' => 1, 'title' => 'Synthetic ' . $area, 'applies_to_orientation' => true ) );
	}
	return calculate( 'orientation' );
}
foreach ( array( 'America/Toronto', 'Pacific/Auckland' ) as $zone ) {
	tz_set( $zone );
	$d30 = external_orientation( '2025-11-16' );
	$d31 = external_orientation( '2025-11-17' );
	check( '11/16/2025' === $d30['completion_date'] && 'Yes' === $d30['completed_within_30'], $zone . ': external orientation certificate on day 30 keeps its date and is Yes' );
	check( '11/17/2025' === $d31['completion_date'] && 'No' === $d31['completed_within_30'], $zone . ': external orientation certificate on day 31 is No' );
	check( '11/16/2025' === wp_date( 'm/d/Y', array_values( array_filter( $d30['raw_completed_courses'], static fn( $c ) => 'external:900030' === $c['course_key'] ) )[0]['date'] ), $zone . ': certificate row displays the entered calendar date' );
}

// Annual window boundaries, evaluated in the site zone.
function external_annual( string $date, string $hire ): bool {
	global $fixture, $mappings;
	reset_fixture();
	$mappings = array();
	$fixture['meta']['ghca_acd_verified_employment']['date'] = $hire;
	$fixture['external'] = array( array( 'revision_id' => 900040, 'training_id' => 900041, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => $date, 'category' => 'general', 'credit_hours' => 3, 'title' => 'Synthetic boundary course' ) ) );
	return in_array( 'external:900040', array_column( calculate( 'annual' )['raw_completed_courses'], 'course_key' ), true );
}
foreach ( array( 'America/Toronto', 'Pacific/Auckland' ) as $zone ) {
	tz_set( $zone );
	$hire = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-100 days' );
	$hire_ymd = $hire->format( 'Y-m-d' );
	$window = GHCA_Audit_Calculator::resolve_annual_window( tz_ts( $hire_ymd . ' 00:00:00' ), 'employee_start_date', time(), wp_timezone() );
	$first = DateTimeImmutable::createFromFormat( '!m/d/Y', $window['start_date'], wp_timezone() )->format( 'Y-m-d' );
	$last  = DateTimeImmutable::createFromFormat( '!m/d/Y', $window['end_date'], wp_timezone() )->format( 'Y-m-d' );
	$before = DateTimeImmutable::createFromFormat( '!Y-m-d', $first, wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' );
	$after  = DateTimeImmutable::createFromFormat( '!Y-m-d', $last, wp_timezone() )->modify( '+1 day' )->format( 'Y-m-d' );
	check( external_annual( $first, $hire_ymd ), $zone . ': external certificate on the first day of the period counts' );
	check( ! external_annual( $before, $hire_ymd ), $zone . ': external certificate the day before the period is excluded' );
	check( external_annual( $last, $hire_ymd ), $zone . ': external certificate on the last day of the period counts' );
	check( ! external_annual( $after, $hire_ymd ), $zone . ': external certificate the day after the period is excluded' );
}

// --- Other date-only readers share the site-zone rule --------------------------------------------
tz_set( 'Pacific/Auckland' );
check( tz_ts( '2025-11-16 12:00:00' ) === GHCA_Audit_Calculator::site_date_timestamp( '2025-11-16', 12 ) && 0 === GHCA_Audit_Calculator::site_date_timestamp( '2025-02-30', 12 ), 'site_date_timestamp reads calendar dates in the site zone and rejects impossible ones' );
echo "Date time-zone checks passed; $checks cumulative checks.\n";
