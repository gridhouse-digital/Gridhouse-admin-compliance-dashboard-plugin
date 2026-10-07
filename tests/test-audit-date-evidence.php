<?php
/** Synthetic regression check: php tests/test-audit-date-evidence.php
 * No WordPress bootstrap, database, network, or real learner records.
 * PDF HTML is captured, not rendered by TCPDF or tested in a browser.
 */
namespace setasign\Fpdi\Tcpdf {
	class Fpdi {
		public $html = '';
		public function writeHTML( $html, ...$args ) { $this->html .= $html; }
		public function __call( $name, $args ) {}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	date_default_timezone_set( 'UTC' );
	$fixture = array();
	$checks = 0;
	$wp_roles = (object) array( 'roles' => array( 'synthetic_staff' => array( 'name' => 'Synthetic Staff' ) ) );
	function get_userdata( $id ) { global $fixture; return (object) array( 'user_registered' => $fixture['registered'], 'roles' => array( 'synthetic_staff' ) ); }
	function get_user_meta( $id, $key, $single ) { global $fixture; return $fixture['meta'][ $key ] ?? ''; }
	function learndash_course_completed( $id, $course ) { global $fixture; return $fixture['completed'][ $course ] ?? false; }
	function learndash_user_get_course_completed_date( $id, $course ) { global $fixture; return $fixture['native'][ $course ] ?? 0; }
	function learndash_user_get_enrolled_courses( $id ) { return array( 900001, 900002 ); }
	function apply_filters( $name, $value ) { return $value; }
	function get_option( $name, $default = false ) { global $fixture; return $fixture['options'][ $name ] ?? $default; }
	function wp_timezone() { return new \DateTimeZone( 'UTC' ); }
	function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
	function get_post_meta( $id, $key, $single ) { return ''; }
	function get_post_field( $field, $id ) { return ''; }
	function get_the_title( $id ) { return 'Synthetic Course ' . $id; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function __( $value, $domain = '' ) { return $value; }
	function wp_specialchars_decode( $value, $flags ) { return htmlspecialchars_decode( $value, $flags ); }
	class GHCA_Dashboard_Branding {
		public static function get_pdf_logo_source() { return ''; }
		public static function get_org_name() { return 'Synthetic Agency'; }
	}
	class GHCA_ACD_Jotform_Repository {
		public static function approved_courses( $id ) { global $fixture; return $fixture['external']; }
		public static function catalog( $active = false ) { return array( array( 'id' => 900050, 'title' => 'Synthetic external catalog' ) ); }
	}
	require dirname( __DIR__ ) . '/includes/class-audit-calculator.php';
	require dirname( __DIR__ ) . '/includes/class-audit-export.php';
	require dirname( __DIR__ ) . '/includes/class-audit-pdf.php';
	function reset_fixture() {
		global $fixture;
		$fixture = array( 'registered' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'meta' => array(), 'completed' => array(), 'native' => array(), 'external' => array(), 'options' => array() );
		$fixture['meta']['ghca_acd_verified_employment'] = array( 'date' => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ), 'source' => 'Synthetic HR reference', 'verified_by' => 90, 'verified_at' => time() );
		$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
	}
	function check( $condition, $label ) {
		global $checks;
		if ( ! $condition ) { throw new \RuntimeException( $label ); }
		++$checks;
	}
	function calculate( $tracker = 'annual' ) {
		global $mappings;
		return \GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ), $tracker, $mappings );
	}
	$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 24, 'is_orientation' => 1 ) );
	foreach ( array( '', 'not a date', 'today', '2026-02-30', "2026-01-01\0bad", gmdate( 'Y-m-d' ) . "\0", 0, -1, array() ) as $invalid ) {
		reset_fixture();
		$fixture['completed'][900001] = true;
		$fixture['meta']['course_completed_900001'] = $invalid;
		$data = calculate();
		check( 0 == $data['total_hrs'] && array() === $data['raw_completed_courses'] && 'missing_evidence' === $data['annual_status'] && count( $data['evidence_issues'] ) === 1, 'Invalid LearnDash evidence must not acquire a date or credit' );
		$data = calculate( 'orientation' );
		check( '' === $data['completion_date'] && 'Missing evidence' === $data['completed_within_30'] && 'Missing evidence' === $data['worked_alone_compliant'], 'Unknown orientation timing must not be certified' );
	}
	reset_fixture();
	check( array() === calculate()['evidence_issues'], 'Uncompleted course is not a corrupt completion' );
	$valid = time() - DAY_IN_SECONDS;
	foreach ( array( $valid, (string) $valid, gmdate( 'Y-m-d H:i:s', $valid ), gmdate( 'Y-m-d', $valid ), gmdate( 'm/d/Y', $valid ), gmdate( 'Y-m-d\TH:i:sP', $valid ) ) as $date ) {
		$fixture['meta']['course_completed_900001'] = $date;
		$data = calculate();
		check( 24 == $data['total_hrs'] && empty( $data['evidence_issues'] ), 'Valid absolute date retains existing credit behavior even without native flag' );
	}
	reset_fixture();
	$fixture['completed'][900001] = true;
	$fixture['native'][900001] = $valid;
	check( calculate()['raw_completed_courses'][0]['date'] === $valid, 'Native LearnDash fallback retains recorded date' );
	$fixture['meta']['course_completed_900001'] = 'invalid';
	check( 0 == calculate()['total_hrs'], 'Invalid nonempty evidence is not silently replaced by native fallback' );
	reset_fixture();
	$fixture['meta']['course_completed_900001'] = strtotime( '-2 years' );
	check( 0 == calculate()['total_hrs'] && empty( calculate()['evidence_issues'] ), 'Valid old completion is outside annual cycle, not missing evidence' );
	foreach ( array( '', 'today', '2026-02-30', "2026-01-01\0" ) as $invalid ) {
		reset_fixture();
		$fixture['external'] = array( array( 'revision_id' => 900010, 'snapshot' => array( 'completion_date' => $invalid, 'category' => 'general', 'credit_hours' => 24 ) ) );
		$data = calculate();
		check( 0 == $data['total_hrs'] && empty( $data['raw_completed_courses'] ) && count( $data['evidence_issues'] ) === 1, 'External invalid date must not become noon today or roll into another month' );
		check( empty( calculate( 'orientation' )['evidence_issues'] ), 'Annual-only external evidence does not affect orientation' );
	}
	reset_fixture();
	$fixture['external'] = array( array( 'revision_id' => 900010, 'training_id' => 900011, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => gmdate( 'Y-m-d', $valid ), 'category' => 'general', 'credit_hours' => 2, 'title' => 'Synthetic external course' ) ) );
	check( 2 == calculate()['total_hrs'] && empty( calculate()['evidence_issues'] ), 'Valid external date retains snapshot credit' );
	$fixture['external'][0]['proposed_completion_date'] = $fixture['external'][0]['snapshot']['completion_date'];
	unset( $fixture['external'][0]['snapshot']['completion_date'] );
	check( 2 == calculate()['total_hrs'], 'Legacy missing snapshot key retains dated proposal fallback' );
	$fixture['external'][0]['snapshot']['completion_date'] = '';
	check( 0 == calculate()['total_hrs'], 'Explicitly empty snapshot date cannot be overwritten by proposal' );
	reset_fixture();
	$fixture['meta']['course_completed_900001'] = $valid;
	$fixture['completed'][900002] = true;
	$mappings[900002] = array( 'odp_category' => 'general', 'credit_hours' => 2, 'is_orientation' => 1 );
	$data = calculate();
	check( 24 == $data['total_hrs'] && count( $data['raw_completed_courses'] ) === 1 && 'missing_evidence' === $data['annual_status'], 'Valid credit stays visible but unresolved evidence takes review precedence' );
	foreach ( array( 'calendar_year', 'employee_start_date' ) as $cycle ) {
		$fixture['options']['ghca_acd_annual_cycle'] = $cycle;
		$data = calculate();
		check( $data['annual_cycle'] === $cycle && 'missing_evidence' === $data['annual_status'] && 24 == $data['total_hrs'], 'Date evidence integrity applies to both agency cycle settings' );
	}
	foreach ( array( 'annual' => 16, 'orientation' => 14 ) as $tracker => $columns ) {
		$stream = fopen( 'php://memory', 'w+' );
		$method = new \ReflectionMethod( 'GHCA_Audit_Export', 'generate_' . $tracker . '_csv' );
		if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
		$method->invoke( null, $stream, array( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ) ), $mappings );
		rewind( $stream );
		$header = fgetcsv( $stream, 0, ',', '"', '\\' );
		$row = fgetcsv( $stream, 0, ',', '"', '\\' );
		fclose( $stream );
		check( count( $header ) === $columns && count( $row ) === $columns && end( $header ) === 'Evidence issues' && strpos( end( $row ), '#900002' ) !== false, 'CSV appends actionable evidence issue without shifting old columns' );
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
		\GHCA_Audit_PDF::render_cover( $pdf, calculate( $tracker ), $tracker );
		check( strpos( $pdf->html, 'Evidence review required' ) !== false && strpos( $pdf->html, '#900002' ) !== false, 'PDF HTML exposes excluded evidence for both trackers' );
		if ( 'annual' === $tracker ) { check( strpos( $pdf->html, 'Missing Evidence — Review Required' ) !== false, 'PDF renders missing-evidence status explicitly' ); }
	}
	reset_fixture();
	$fixture['meta']['course_completed_900001'] = $valid;
	unset( $fixture['meta']['ghca_acd_verified_employment'] );
	$data = calculate();
	check( '' === $data['doh'] && '' === $data['start_date'] && '' === $data['end_date'] && 'missing_evidence' === $data['annual_status'] && 0 == $data['total_hrs'], 'Missing employment evidence never falls back to registration or invented annual window' );
	check( 'Missing evidence' === calculate( 'orientation' )['completed_within_30'], 'Missing hire date prevents hire-relative orientation assessment' );
	reset_fixture();
	$fixture['registered'] = '2000-01-01 00:00:00';
	check( calculate()['doh'] === gmdate( 'm/d/Y', time() - 10 * DAY_IN_SECONDS ), 'Verified date overrides unrelated registration date' );
	foreach ( array( '', 'invalid', array() ) as $cycle ) {
		$fixture['options']['ghca_acd_annual_cycle'] = $cycle;
		check( '' === calculate()['start_date'] && 'missing_evidence' === calculate()['annual_status'], 'Missing/invalid cycle never receives default anniversary assessment' );
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
		\GHCA_Audit_PDF::render_cover( $pdf, calculate(), 'annual' );
		check( strpos( $pdf->html, 'Annual Cycle Rule:</strong> Not configured' ) !== false && strpos( $pdf->html, 'Employee Hire Date' ) === false, 'Missing cycle must not be labelled as hire-date policy' );
	}
	$tz = new \DateTimeZone( 'America/Toronto' );
	$hire = ( new \DateTimeImmutable( '2024-02-29', $tz ) )->getTimestamp();
	foreach ( array( '2025-02-28' => '02/29/2024', '2025-03-01' => '03/01/2025' ) as $now => $start ) {
		$window = \GHCA_Audit_Calculator::resolve_annual_window( $hire, 'employee_start_date', ( new \DateTimeImmutable( $now, $tz ) )->getTimestamp(), $tz );
		check( $start === $window['start_date'], 'Existing leap-anniversary boundary remains stable' );
	}
	$window = \GHCA_Audit_Calculator::resolve_annual_window( $hire, 'calendar_year', ( new \DateTimeImmutable( '2026-01-01', $tz ) )->getTimestamp(), $tz );
	check( '01/01/2026' === $window['start_date'] && '12/31/2026' === $window['end_date'], 'Calendar boundary uses site timezone' );
	echo "$checks checks passed (synthetic calculator, CSV and captured PDF HTML; no site runtime).\n";
}
