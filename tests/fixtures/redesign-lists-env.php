<?php
/**
 * Stand-in WordPress for the slice-4 tabs (Overdue, Courses, Certificates). Builds on the slice-2
 * fixture and seeds employees whose courses carry the fields the real provider gives them:
 * completion, certificate links, completion time and the rolling-lifespan expiry. The filtering,
 * counting, paging and markup under test are the plugin's own code. Synthetic data, not a live site.
 */
require_once __DIR__ . '/redesign-tables-env.php';
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}

// The legacy renderers these tabs replace print through a few more WordPress helpers.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) { return (string) $html; }
}

/** One course in the shape GHCA_ACD_Data_Provider::get_user_courses() produces. */
function ghca_lists_course( int $id, string $title, string $status, bool $cert_configured, bool $issued, int $completed_days_ago = 0, string $state = 'incomplete', int $expires_in_days = 0 ): array {
	$now       = $GLOBALS['ghca_env']['now'];
	$completed = 'completed' === $status;
	$done_ts   = $completed ? $now - $completed_days_ago * 86400 : 0;
	$exp_ts    = $completed && $expires_in_days ? $now + $expires_in_days * 86400 : 0;
	return array(
		'id'                 => $id,
		'title'              => $title,
		'status'             => $status,
		'completed'          => $completed,
		'completed_ts'       => $done_ts,
		'has_certificate'    => $cert_configured,
		'certificate_url'    => $completed && $issued ? 'https://example.test/certificates/' . $id . '/?user=' . $id : '',
		'completed_recently' => $completed && $completed_days_ago <= 30,
		'last_activity_ts'   => $done_ts,
		'expiration_ts'      => $exp_ts,
		'expiration_label'   => $exp_ts ? gmdate( 'M j, Y', $exp_ts ) : '',
		'compliance_state'   => $completed ? $state : 'incomplete',
	);
}

/**
 * Seeds the provider. Two overdue employees (one a new hire), two due within the at-risk window, one
 * expired, one complete, plus certificates in every state the Certificates tab shows.
 *
 * The provider classifies priority against the real clock, not the test clock, so the seed is built to
 * classify the same way on any day after it was written: overdue due dates are already past, and the two
 * at-risk employees carry the new-hire at-risk flag, which the provider checks before any date.
 */
function ghca_lists_seed( array $over = array() ): void {
	$now = $GLOBALS['ghca_env']['now'];
	$c   = 'ghca_lists_course';

	$people = array(
		// id, name, group, slug, label, due offset days, courses, new hire flags
		array( 201, 'Daniel Reyes', 11, 'overdue', 'Overdue', -7, array(
			$c( 501, 'Recognizing and Reporting Incidents', 'not_started', true, false ),
			$c( 502, 'Individual Rights', 'in_progress', true, false ),
			$c( 503, 'Trauma-Informed Care', 'not_started', false, false ),
			$c( 504, 'Fatal Five', 'completed', true, true, 40, 'compliant', 300 ),
		), array() ),
		array( 202, 'Maya Okafor', 12, 'new_hire_overdue', 'New hire: overdue', -3, array(
			$c( 504, 'Fatal Five', 'in_progress', true, false ),
			$c( 505, 'Documentation Essentials <Intro>', 'not_started', true, false ),
			$c( 506, 'Orientation Overview', 'completed', true, false, 5, 'compliant' ),
		), array( 'overdue' => true ) ),
		array( 203, 'Marcus Hill', 11, 'not_started', 'Not Started', 9, array(
			$c( 501, 'Recognizing and Reporting Incidents', 'not_started', true, false ),
			$c( 502, 'Individual Rights', 'not_started', true, false ),
		), array( 'at_risk' => true ) ),
		array( 204, 'Jordan Ellis', 12, 'new_hire_in_progress', 'New hire: in progress', 13, array(
			$c( 504, 'Fatal Five', 'completed', true, true, 12, 'expiring_soon', 20 ),
			$c( 505, 'Documentation Essentials <Intro>', 'in_progress', true, false ),
		), array( 'at_risk' => true ) ),
		array( 205, 'Tomás Alvarez', 13, 'expired', 'Expired', 40, array(
			$c( 507, 'Foundations of Person-Centered Thinking', 'completed', true, true, 400, 'expired', -35 ),
			$c( 501, 'Recognizing and Reporting Incidents', 'completed', true, true, 20, 'compliant', 345 ),
		), array() ),
		array( 206, 'Priya Nair', 13, 'completed', 'All assigned courses complete', 200, array(
			$c( 507, 'Foundations of Person-Centered Thinking', 'completed', true, true, 2, 'compliant', 363 ),
			$c( 503, 'Trauma-Informed Care', 'completed', false, false, 50, 'compliant' ),
		), array() ),
	);
	$people = $over['people'] ?? $people;
	// Slice 9: GHCA_PREVIEW_EXTRA_CERTS=N adds N synthetic people with one issued certificate each, on long
	// all-capitals course titles, for paging, Title Case and course-menu wrapping checks.
	$extra = (int) getenv( 'GHCA_PREVIEW_EXTRA_CERTS' );
	$long  = array( 'PREVENTION, DETECTION & REPORTING OF ABUSE (OAPSA, CPS LAW, APS ACT) FOR DIRECT SUPPORT PROFESSIONALS', 'HIPAA AND THE ODP: WHAT EVERY DSP NEEDS TO KNOW ABOUT PRIVACY, RECORDS AND INCIDENT DOCUMENTATION', 'CPR/FIRST AID AND AED CERTIFICATION' );
	for ( $i = 0; $i < $extra; $i++ ) {
		$people[] = array( 300 + $i, 'Synthetic Person ' . ( $i + 1 ), 11 + ( $i % 3 ), 'completed', 'All assigned courses complete', 200, array(
			$c( 600 + ( $i % 3 ), $long[ $i % 3 ], 'completed', true, true, 3 + $i, 'compliant', 300 ),
		), array() );
	}

	$active = array();
	foreach ( $people as $p ) {
		list( $id, $name, $group, $slug, $label, $due_days, $courses, $nh ) = $p;
		$done  = count( array_filter( $courses, static function ( array $x ): bool { return $x['completed']; } ) );
		$total = count( $courses );
		$due   = $now + $due_days * 86400;
		$pct   = $total ? (int) round( $done / $total * 100 ) : 0;
		$active[] = array(
			'user_id'             => $id,
			'name'                => $name,
			'email'               => strtolower( str_replace( ' ', '.', $name ) ) . '@example.test',
			'group'               => 'DSP — Group ' . $group,
			'group_id'            => $group,
			'courses'             => $courses,
			'total_courses'       => $total,
			'completed_count'     => $done,
			'completed_label'     => $total ? $done . ' / ' . $total : '0',
			'progress_pct'        => $pct,
			'progress_label'      => $pct . '%',
			'due_timestamp'       => $due,
			'due_date_label'      => gmdate( 'F j, Y', $due ),
			'last_activity_label' => 'Oct 2' . ( $id % 10 ) . ', 2026',
			'status_slug'         => $slug,
			'status_label'        => $label,
			'new_hire'            => $nh,
			'certificate_label'   => 'Pending',
		);
	}

	$aggregate = array( 'employees' => $active, 'inactive_employees' => array() );
	$certs     = GHCA_ACD_Data_Provider::summarize_certificates( $active );
	$recent    = GHCA_ACD_Data_Provider::summarize_recent_completions( $active );
	usort( $recent['items'], static function ( array $a, array $b ): int { return (int) $b['ts'] <=> (int) $a['ts']; } );
	$aggregate += array(
		'total_employees'          => count( $active ),
		'certificates_issued'      => $certs['issued'],
		'certificates_missing'     => $certs['missing'],
		'eligible_for_certificate' => $certs['eligible'],
		'recently_completed'       => $recent['count'],
		'recent_completions'       => array_slice( $recent['items'], 0, 8 ),
	);
	$ref = new ReflectionProperty( 'GHCA_ACD_Data_Provider', 'aggregate' );
	$ref->setAccessible( true );
	$ref->setValue( null, $aggregate );
}
