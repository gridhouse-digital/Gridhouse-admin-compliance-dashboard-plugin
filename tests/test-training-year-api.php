<?php
/**
 * 2.0.0-rc.2: the role map (55 Pa. Code § 6100.143), threshold resolution and source, and the stable employee API
 * on GHCA_ACD_Training_Year (enabled, due, window, requirement, required_hours, course). Synthetic fixtures only;
 * no WordPress, database or employee plugin.   php tests/test-training-year-api.php
 */
namespace {
	// Minimal job-role catalogue for requirement(): the fixture user (stubbed get_userdata) holds 'synthetic_staff'.
	final class GHCA_ACD_Roles {
		public static function active_builtin_employee_roles(): array { return array( 'ghca_clinical_supervisor' => 'Clinical Supervisor' ); }
		public static function builtin_employee_roles(): array { return self::active_builtin_employee_roles(); }
		public static function custom_roles(): array { return array( 'synthetic_staff' => 'Synthetic Staff', 'ghca_custom_lpn' => 'LPN' ); }
		public static function dashboard_roles(): array { return array( 'administrator', 'compliance_lead' ); }
	}
}
namespace {
require __DIR__ . '/test-audit-date-evidence.php'; // stubs, fixture, check(), calculate()
require_once dirname( __DIR__ ) . '/includes/class-course-lifespans.php';
$base = $checks;
$TY   = 'GHCA_ACD_Training_Year';
$catalog = array( 'dsp_role' => 'DSP', 'admin_role' => 'Office Administrator', 'driver' => 'Driver', 'volunteer' => 'Volunteer', 'ghca_custom_rn' => 'RN', 'house_nurse' => 'Staff Nurse' );

// ---- Resolution rules (test: unmapped role, multi-role, nurse policy, exceptions, override).
$r = GHCA_ACD_Training_Year::resolve( array( 'driver' ), $catalog, array() );
check( 24 === $r['hours'] && false === $r['set'] && 'default' === $r['source'] && 'Role requirement not set' === $r['label'], 'unmapped role → 24 h, set=false, "Role requirement not set", source default' );
check( 'default' === GHCA_ACD_Training_Year::resolve( array( 'subscriber' ), $catalog, array() )['source'], 'no GHCA job role → default (24 h, flagged)' );
$map = array( 'dsp_role' => '24', 'admin_role' => '12', 'volunteer' => 'none_volunteer' );
$r = GHCA_ACD_Training_Year::resolve( array( 'admin_role', 'dsp_role' ), $catalog, $map );
check( 24 === $r['hours'] && 'role_map' === $r['source'] && true === $r['set'] && 'role:dsp_role' === $r['key'], 'multi-role: 12 h and 24 h roles → 24 h (highest wins), source role_map' );
$r = GHCA_ACD_Training_Year::resolve( array( 'volunteer', 'admin_role' ), $catalog, $map );
check( 12 === $r['hours'] && 'role_map' === $r['source'], 'multi-role: not required and 12 h → 12 h' );
$r = GHCA_ACD_Training_Year::resolve( array( 'volunteer' ), $catalog, $map );
check( 0 === $r['hours'] && 'role_map' === $r['source'] && false !== strpos( $r['label'], 'never works alone' ), 'not required only with its exception (volunteer who never works alone): 0 h' );
$r = GHCA_ACD_Training_Year::resolve( array( 'admin_role', 'driver' ), $catalog, $map );
check( 24 === $r['hours'] && 'default' === $r['source'] && 'Role requirement not set' === $r['label'], 'a 12 h role plus an unmapped role never becomes 12 h silently: 24 h, flagged, default' );
check( 24 === GHCA_ACD_Training_Year::resolve( array( 'dsp_role', 'driver' ), $catalog, $map )['hours'] && 'role_map' === GHCA_ACD_Training_Year::resolve( array( 'dsp_role', 'driver' ), $catalog, $map )['source'], 'a 24 h role plus an unmapped role: 24 h from the role map (nothing higher possible)' );
$r = GHCA_ACD_Training_Year::resolve( array( 'ghca_custom_rn' ), $catalog, array() );
check( 24 === $r['hours'] && false === $r['set'] && 'default' === $r['source'] && false !== strpos( $r['label'], 'agency policy' ), 'unsaved nurse default (RN): 24 h as agency policy, set=false (not a saved mapping)' );
$r = GHCA_ACD_Training_Year::resolve( array( 'ghca_custom_rn' ), $catalog, array( 'ghca_custom_rn' => '24' ) );
check( 24 === $r['hours'] && true === $r['set'] && 'role_map' === $r['source'], 'nurse role after an administrator saves 24 h: set=true, source role_map' );
$r = GHCA_ACD_Training_Year::resolve( array( 'ghca_custom_rn', 'dsp_role' ), $catalog, array( 'dsp_role' => '24' ) );
check( 24 === $r['hours'] && true === $r['set'] && 'role:dsp_role' === $r['key'], 'unsaved nurse default plus a saved 24 h role: the saved mapping wins the tie (set=true)' );
// Core WordPress and forum roles never count, even if a catalog or a leftover map lists them.
$with_core = $catalog + array( 'subscriber' => 'Subscriber', 'bbp_participant' => 'Participant', 'author' => 'Author' );
$r = GHCA_ACD_Training_Year::resolve( array( 'admin_role', 'subscriber', 'bbp_participant' ), $with_core, array( 'admin_role' => '12', 'subscriber' => '24' ) );
check( 12 === $r['hours'] && 'role_map' === $r['source'] && true === $r['set'], 'a 12 h job role plus subscriber and a forum role: stays 12 h, source role_map, set=true (core/forum roles ignored, leftover subscriber entry ignored)' );
foreach ( array( 'subscriber', 'administrator', 'editor', 'author', 'contributor', 'bbp_keymaster', 'bbp_moderator', 'bbp_participant', 'bbp_spectator', 'bbp_blocked', 'member' ) as $core ) {
	check( GHCA_ACD_Training_Year::is_excluded_role( $core ) && array() === GHCA_ACD_Training_Year::sanitize_role_map( array( $core => '12' ) ), 'excluded role ' . $core . ': never in the map' );
}
check( 24 === GHCA_ACD_Training_Year::resolve( array( 'house_nurse' ), $catalog, array() )['hours'], 'nurse role by title (Staff Nurse) → 24 h agency policy' );
check( 12 === GHCA_ACD_Training_Year::resolve( array( 'ghca_custom_rn' ), $catalog, array( 'ghca_custom_rn' => '12' ) )['hours'], 'nurse mapping is editable: an administrator can set 12 h' );
$r = GHCA_ACD_Training_Year::resolve( array( 'dsp_role' ), $catalog, $map, 'management' );
check( 12 === $r['hours'] && 'override' === $r['source'] && 'management' === $r['key'], 'per-employee override wins over the role map; source override' );
check( 'role_map' === GHCA_ACD_Training_Year::resolve( array( 'dsp_role' ), $catalog, $map, 'bogus' )['source'], 'an unknown override value is ignored' );
check( array( 'dsp_role' => '24', 'x' => '12' ) === GHCA_ACD_Training_Year::sanitize_role_map( array( 'dsp_role' => '24', 'x' => '12', 'y' => 'none', 'z' => '0', 'Bad Slug' => '24', 'w' => 24 ) ), 'role map accepts only 24, 12 and the named not-required exceptions' );
foreach ( GHCA_ACD_Training_Year::role_map_choices() as $key => $choice ) {
	check( 0 !== $choice[1] || false !== strpos( $choice[0], 'Not required — ' ) && ( false !== strpos( $choice[0], '(b)(1)' ) || false !== strpos( $choice[0], '(b)(2)' ) || false !== strpos( $choice[0], 'never works alone' ) ), 'role map choice ' . $key . ': ' . ( $choice[1] ? $choice[1] . ' h' : 'not required with its exception' ) );
}
check( 'Per 55 Pa. Code §6100.143; nurse mapping is agency policy.' === GHCA_ACD_Training_Year::basis_text(), 'basis text' );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-training-year.php' ) . file_get_contents( dirname( __DIR__ ) . '/includes/class-training-year-review.php' ) . file_get_contents( dirname( __DIR__ ) . '/includes/class-redesign-edit.php' ) . file_get_contents( dirname( __DIR__ ) . '/includes/class-ajax-handlers.php' );
check( false === stripos( $src, 'support professional' ) && false !== stripos( $src, 'direct service professional' ), 'wording: "direct service professional", never "direct support professional"' );
check( false === strpos( $src, '24 h assumed' ), 'rc.1 wording "Not set — 24 h assumed" / "Not set (24 h assumed)" is gone' );
check( 'compliant' === GHCA_Audit_Calculator::annual_status( 0, time(), time() + 9, 0 ), 'annual_status(): a not-required employee (0 h) is not held to an hours threshold' );

// ---- Employee API parity: the API returns what the calculator and lifespan policy compute.
$GLOBALS['site_tz'] = 'America/New_York';
$tz = new DateTimeZone( 'America/New_York' );
reset_fixture();
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$fixture['meta']['ghca_acd_verified_employment'] = array( 'date' => '2025-10-20', 'source' => 'Synthetic HR reference', 'verified_by' => 90, 'verified_at' => 1 );
$fixture['options']['ghca_acd_training_role_map'] = array( 'synthetic_staff' => '12' );
$fixture['options']['ghca_acd_audit_mapping'] = array( 900001 => array( 'odp_category' => 'person_centered' ) );
$fixture['options'][ GHCA_Course_Lifespans::OPTION_LIFESPANS ] = array( 900002 => 30 );
$fixture['options'][ GHCA_Course_Lifespans::OPTION_WARNING_DAYS ] = 14;
$now = ( new DateTimeImmutable( '2026-11-13 10:00', $tz ) )->getTimestamp();
$doh = GHCA_ACD_Employment_Record::timestamp( 900001 );
$calc = GHCA_Audit_Calculator::resolve_annual_window( $doh, 'employee_start_date', $now, $tz );

GHCA_ACD_Training_Year::set_override( null );
$fixture['options'][ GHCA_ACD_Training_Year::OPTION_ENABLED ] = 0;
check( false === GHCA_ACD_Training_Year::enabled(), 'enabled(): false when ghca_acd_training_year_rules is off (default)' );
$fixture['options'][ GHCA_ACD_Training_Year::OPTION_ENABLED ] = '1';
check( true === GHCA_ACD_Training_Year::enabled(), 'enabled(): true when ghca_acd_training_year_rules is on' );
GHCA_ACD_Training_Year::set_override( false );
check( false === GHCA_ACD_Training_Year::enabled(), 'enabled(): honours set_override()' );
GHCA_ACD_Training_Year::set_override( true );

$w = GHCA_ACD_Training_Year::window( 900001, $now );
check( array( 'start_ts', 'end_ts', 'start_date', 'end_date', 'missing', 'label' ) === array_keys( $w ), 'window(): keys start_ts, end_ts, start_date, end_date, missing, label' );
check( $calc['start_ts'] === $w['start_ts'] && $calc['end_ts'] === $w['end_ts'] && $calc['start_date'] === $w['start_date'] && $calc['end_date'] === $w['end_date'] && '' === $w['missing'], 'window(): same start/end as GHCA_Audit_Calculator::resolve_annual_window() (' . $w['start_date'] . ' – ' . $w['end_date'] . ')' );
check( 'Oct 20, 2026 – Oct 19, 2027' === $w['label'], 'window(): label "M j, Y – M j, Y"' );
$d = GHCA_ACD_Training_Year::due( 900001, $now );
check( array( 'due_timestamp', 'due_date_label', 'training_year_label' ) === array_keys( $d ), 'due(): keys due_timestamp, due_date_label, training_year_label' );
check( $calc['end_ts'] - 1 === $d['due_timestamp'] && 'October 19, 2027' === $d['due_date_label'] && $w['label'] === $d['training_year_label'], 'due(): last second of the last training-year day (window end − 1), "October 19, 2027"' );

$q = GHCA_ACD_Training_Year::requirement( 900001 );
check( array( 'key', 'label', 'hours', 'set', 'source' ) === array_keys( $q ), 'requirement(): keys key, label, hours, set, source' );
check( $q === GHCA_ACD_Training_Year::resolve( array( 'synthetic_staff' ), GHCA_ACD_Training_Year::role_catalog(), array( 'synthetic_staff' => '12' ) ) && 12 === $q['hours'] && 'role_map' === $q['source'], 'requirement(): same hours and source as the resolver (12 h, role_map)' );
check( 12 === GHCA_ACD_Training_Year::required_hours( 900001 ) && 12 === calculate()['required_annual_hrs'], 'required_hours() and the calculator use the same resolved threshold (12 h)' );
GHCA_ACD_Training_Year::set_override( false );
check( 24 === GHCA_ACD_Training_Year::required_hours( 900001 ) && 24 === calculate()['required_annual_hrs'] && '' === calculate()['required_annual_label'], 'switch off: required_hours() and the calculator stay at 24 h, with no requirement label (identical to before)' );
GHCA_ACD_Training_Year::set_override( true );
$fixture['meta'][ GHCA_ACD_Training_Year::REQUIREMENT_META ] = 'dsp';
check( 'override' === GHCA_ACD_Training_Year::requirement( 900001 )['source'] && 24 === GHCA_ACD_Training_Year::required_hours( 900001 ), 'requirement(): an override is reported with source override' );
unset( $fixture['meta'][ GHCA_ACD_Training_Year::REQUIREMENT_META ] );
$fixture['options']['ghca_acd_training_role_map'] = array();
$q = GHCA_ACD_Training_Year::requirement( 900001 );
check( 24 === $q['hours'] && false === $q['set'] && 'default' === $q['source'] && 'Role requirement not set' === $q['label'], 'requirement(): unmapped role → 24 h, "Role requirement not set", source default' );
$fixture['options']['ghca_acd_training_role_map'] = array( 'synthetic_staff' => '12' );

// course(): annual course follows the training year; others use calendar-day lifespans.
$c = GHCA_ACD_Training_Year::course( 900001, 900001, true, $now - DAY_IN_SECONDS, $now );
check( array( 'expiration_ts', 'expiration_label', 'compliance_state', 'lifespan_days', 'follows_training_year' ) === array_keys( $c ), 'course(): keys expiration_ts, expiration_label, compliance_state, lifespan_days, follows_training_year' );
check( $d['due_timestamp'] === $c['expiration_ts'] && 'Oct 19, 2027' === $c['expiration_label'] && 0 === $c['lifespan_days'] && true === $c['follows_training_year'] && 'current' === $c['compliance_state'], 'course(), annual course: expiration = due()["due_timestamp"], label Oct 19, 2027, follows the training year, current' );
check( 'incomplete' === GHCA_ACD_Training_Year::course( 900001, 900001, false, 0, $now )['compliance_state'] && $d['due_timestamp'] === GHCA_ACD_Training_Year::course( 900001, 900001, false, 0, $now )['expiration_ts'], 'course(), annual course not completed: incomplete, still carrying the training-year due date' );
$late = ( new DateTimeImmutable( '2027-10-10 10:00', $tz ) )->getTimestamp();
check( 'current' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $late - DAY_IN_SECONDS, $late )['compliance_state'], 'course(), annual course completed in the current year: current even inside the warning window (never expiring_soon)' );
check( 'expiring_soon' === GHCA_ACD_Training_Year::course( 900001, 900001, false, 0, $late )['compliance_state'], 'course(), annual course not completed: expiring_soon inside the warning window' );
// rc.3: a completion counts only inside the current window (start inclusive, end exclusive).
$start = $w['start_ts'];
$three = GHCA_ACD_Training_Year::course( 900001, 900001, true, $start - 3 * 365 * DAY_IN_SECONDS, $now );
check( 'incomplete' === $three['compliance_state'] && $d['due_timestamp'] === $three['expiration_ts'] && 'Oct 19, 2027' === $three['expiration_label'], 'course(), annual course completed three years ago: incomplete, carrying this year\'s due date' );
check( 'incomplete' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $start - DAY_IN_SECONDS, $now )['compliance_state'], 'course(), annual course completed the day before the current window: incomplete' );
check( 'incomplete' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $start - 3 * 365 * DAY_IN_SECONDS, $late )['compliance_state'], 'course(), completed outside the year, inside the warning window: incomplete (not expiring_soon, which is only for a course never completed)' );
check( 'current' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $start, $now )['compliance_state'], 'course(), completed at 00:00 on the anniversary (window start): counts in the current year → current' );
check( 'incomplete' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $start - 1, $now )['compliance_state'], 'course(), completed one second before the anniversary: previous year → incomplete' );
check( 'incomplete' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $w['end_ts'], $now )['compliance_state'], 'course(), a completion stamped at the window end (exclusive) does not count in this year' );
check( 'current' === GHCA_ACD_Training_Year::course( 900001, 900001, true, $now - DAY_IN_SECONDS, $now )['compliance_state'], 'course(), completed inside the current window: current' );
$done = ( new DateTimeImmutable( '2026-11-01 12:00', $tz ) )->getTimestamp();
$c = GHCA_ACD_Training_Year::course( 900001, 900002, true, $done, $now );
$e = GHCA_Course_Lifespans::evaluate( true, $done, 30, 14, $now, $tz );
check( $e['expiration_ts'] === $c['expiration_ts'] && $e['state'] === $c['compliance_state'] && 30 === $c['lifespan_days'] && false === $c['follows_training_year'] && '2026-12-01 12:00' === ( new DateTimeImmutable( '@' . $c['expiration_ts'] ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ), 'course(), lifespan course: same calendar-day expiry and state as the lifespan policy (Dec 1, 2026 12:00, across the DST change)' );
// rc.3: completed annual course with no training year is never current; it shows why.
$saved_emp = $fixture['meta']['ghca_acd_verified_employment'];
unset( $fixture['meta']['ghca_acd_verified_employment'] );
$m = GHCA_ACD_Training_Year::course( 900001, 900001, true, $now - DAY_IN_SECONDS, $now );
check( 0 === $m['expiration_ts'] && 'Hire date missing' === $m['expiration_label'] && ! in_array( $m['compliance_state'], array( 'current', 'expiring_soon' ), true ) && true === $m['follows_training_year'], 'course(), completed annual course with no hire date: expiration_ts 0, label "Hire date missing", not current' );
$fixture['meta']['ghca_acd_verified_employment'] = $saved_emp;
$fixture['options']['ghca_acd_annual_cycle'] = '';
$m = GHCA_ACD_Training_Year::course( 900001, 900001, true, $now - DAY_IN_SECONDS, $now );
check( 0 === $m['expiration_ts'] && 'Training year not configured' === $m['expiration_label'] && 'current' !== $m['compliance_state'], 'course(), training year not configured: label "Training year not configured", not current' );
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$c = GHCA_ACD_Training_Year::course( 900001, 900003, true, $done, $now );
check( 0 === $c['expiration_ts'] && '' === $c['expiration_label'] && 'current' === $c['compliance_state'] && false === $c['follows_training_year'], 'course(), no lifespan and not annual: does not expire' );
GHCA_ACD_Training_Year::set_override( false );
check( 3 === ( new ReflectionMethod( 'GHCA_Course_Lifespans', 'decorate' ) )->getNumberOfParameters(), 'decorate() still takes exactly three parameters' );
// rc.3: the admin dashboard badge source (GHCA_Course_Lifespans::for_user) equals course() with the switch on,
// and decorate() with it off (unchanged output).
foreach ( array( 900001 => 'annual course', 900002 => 'lifespan course' ) as $cid => $kind ) {
	GHCA_ACD_Training_Year::set_override( true );
	$api  = GHCA_ACD_Training_Year::course( 900001, $cid, true, $done );
	$dash = GHCA_Course_Lifespans::for_user( 900001, $cid, true, $done );
	check( $api === $dash, 'dashboard parity, switch on, ' . $kind . ': same expiry, label, state, lifespan and follows_training_year as course() (' . $dash['compliance_state'] . ', ' . ( $dash['expiration_label'] ?: 'no expiry' ) . ')' );
	GHCA_ACD_Training_Year::set_override( false );
	check( GHCA_Course_Lifespans::decorate( $cid, true, $done ) === GHCA_Course_Lifespans::for_user( 900001, $cid, true, $done ), 'dashboard parity, switch off, ' . $kind . ': exactly decorate() as before' );
}
GHCA_ACD_Training_Year::set_override( null );

// rc.3 (c): a Settings save without the start-date field keeps the stored value.
$fixture['options'][ GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR ] = 'hire_or_first_service';
check( 'hire_or_first_service' === GHCA_ACD_Training_Year::sanitize_anchor( null ), 'sanitize_anchor(null) (field absent from POST) keeps the stored hire_or_first_service' );
check( 'hire' === GHCA_ACD_Training_Year::sanitize_anchor( 'hire' ) && 'hire' === GHCA_ACD_Training_Year::sanitize_anchor( 'junk' ) && 'hire_or_first_service' === GHCA_ACD_Training_Year::sanitize_anchor( 'hire_or_first_service' ), 'sanitize_anchor() still validates posted values' );
unset( $fixture['options'][ GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR ] );
check( 'hire' === GHCA_ACD_Training_Year::sanitize_anchor( null ), 'sanitize_anchor(null) with nothing stored: hire' );

GHCA_ACD_Training_Year::set_override( null );

echo 'PASS: ' . ( $checks - $base ) . " training-year role map and employee API checks (synthetic; no WordPress)\n";
}
