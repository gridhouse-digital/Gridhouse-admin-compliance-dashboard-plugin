<?php
require __DIR__ . '/bootstrap.php';

if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
$oltl_options = array();
$oltl_users = array();
$oltl_meta = array();
$oltl_external = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { global $oltl_options; return array_key_exists( $key, $oltl_options ) ? $oltl_options[ $key ] : $default; } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { global $oltl_users; return $oltl_users[ $id ] ?? false; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $id, $key, $single ) { global $oltl_meta; return $oltl_meta[ $id ][ $key ] ?? ''; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type, $gmt = false ) { return time(); } }
if ( ! function_exists( 'wp_timezone' ) ) { function wp_timezone() { return new DateTimeZone( 'UTC' ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); } }
if ( ! function_exists( 'wp_list_pluck' ) ) { function wp_list_pluck( $items, $key ) { return array_column( $items, $key ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $text ) { return strip_tags( $text ); } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Synthetic course'; } }
if ( ! function_exists( 'get_post_field' ) ) { function get_post_field( $field, $id ) { return ''; } }
if ( ! function_exists( 'learndash_course_completed' ) ) { function learndash_course_completed( $user_id, $course_id ) { return true; } }
class GHCA_ACD_Settings {
	const OPTION_ANNUAL_CYCLE = 'ghca_acd_annual_cycle';
	public static function get_configured_annual_cycle(): string {
		$cycle = get_option( self::OPTION_ANNUAL_CYCLE, '' );
		return in_array( $cycle, array( 'employee_start_date', 'calendar_year' ), true ) ? $cycle : '';
	}
}
class GHCA_Audit_Mapping { const OPTION_NAME = 'ghca_acd_audit_mapping'; }
class GHCA_Audit_Calculator {
	public static function completion_timestamp( $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) { return max( 0, (int) $value ); }
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) { return 0; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date->getTimestamp() : 0;
	}
	public static function resolve_annual_window( int $hire, string $cycle, int $now, DateTimeZone $timezone ): array {
		$start = 'calendar_year' === $cycle ? strtotime( '2026-01-01 UTC' ) : $hire;
		return array( 'start_ts' => $start, 'end_ts' => strtotime( '2027-01-01 UTC' ), 'start_date' => gmdate( 'm/d/Y', $start ), 'end_date' => '12/31/2026' );
	}
	public static function course_full_description( int $id ): string { return 'Documented synthetic content'; }
	public static function course_short_description( int $id ): string { return 'Documented synthetic content'; }
}
class GHCA_ACD_OLTL_Schema { public static function tables_exist(): bool { return false; } }
class GHCA_ACD_Jotform_Repository {
	public static function approved_courses( int $id ): array { global $oltl_external; return $oltl_external; }
}

require_once __DIR__ . '/../includes/class-employment-record.php';
require_once __DIR__ . '/../includes/oltl/class-oltl-readiness.php';

$fails = 0;
function oltl_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	if ( ! $condition ) { $fails++; }
}

$requirements = GHCA_ACD_OLTL_Readiness::annual_requirements();
oltl_check( 6 === count( $requirements ), 'profile contains exactly the six verified Chapter 52 recurring topics' );
oltl_check( isset( $requirements['abuse_exploitation'], $requirements['critical_incidents'], $requirements['complaint_resolution'], $requirements['department_policies'], $requirements['quality_management'], $requirements['fraud_financial_abuse'] ), 'all verified topic codes are present' );
oltl_check( 'service_plan_training' === GHCA_ACD_OLTL_Readiness::profile()['manual_requirement'], 'service-plan training remains a separate manual requirement' );
oltl_check( GHCA_ACD_OLTL_Readiness::SOURCE_CITATION === '55 Pa. Code § 52.21' && 0 === strpos( GHCA_ACD_OLTL_Readiness::SOURCE_URL, 'https://' ), 'profile retains authoritative citation and HTTPS source' );

oltl_check( ! GHCA_ACD_OLTL_Readiness::is_assigned( 10 ), 'feature is disabled and unassigned by default' );
$oltl_options[ GHCA_ACD_OLTL_Readiness::OPTION_ENABLED ] = '1';
$oltl_options[ GHCA_ACD_OLTL_Readiness::OPTION_ROLES ] = array( 'lpn' );
$oltl_users[10] = (object) array( 'roles' => array( 'lpn' ) );
$oltl_users[11] = (object) array( 'roles' => array( 'subscriber' ) );
oltl_check( GHCA_ACD_OLTL_Readiness::is_assigned( 10 ), 'explicit role assignment applies without role-name inference' );
$oltl_options[ GHCA_ACD_OLTL_Readiness::OPTION_EXCLUDED_USERS ] = array( 10 );
oltl_check( ! GHCA_ACD_OLTL_Readiness::is_assigned( 10 ), 'individual not-assigned decision overrides a role assignment' );
$oltl_options[ GHCA_ACD_OLTL_Readiness::OPTION_EXCLUDED_USERS ] = array();
$oltl_options[ GHCA_ACD_OLTL_Readiness::OPTION_USERS ] = array( 11 );
oltl_check( GHCA_ACD_OLTL_Readiness::is_assigned( 11 ), 'individual assignment applies independently of role' );
oltl_check( ! GHCA_ACD_OLTL_Readiness::is_assigned( 12 ), 'unassigned employee remains outside the profile' );

$sanitized = GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( array( 'critical_incidents', 'critical_incidents', 'infection_control', 'fraud_financial_abuse' ) );
oltl_check( $sanitized === array( 'critical_incidents', 'fraud_financial_abuse' ), 'mapping sanitizer rejects legacy and unknown categories and removes duplicates' );

$window = array( 'start_ts' => 1000, 'end_ts' => 2000 );
oltl_check( 'satisfied' === GHCA_ACD_OLTL_Readiness::evidence_status( 1500, 'Documented course content', true, $window ), 'current-cycle dated completion with content and evidence is satisfied' );
oltl_check( 'expired' === GHCA_ACD_OLTL_Readiness::evidence_status( 999, 'Documented course content', true, $window ), 'only earlier-cycle evidence is expired' );
oltl_check( 'missing_evidence' === GHCA_ACD_OLTL_Readiness::evidence_status( 0, 'Documented course content', true, $window ), 'missing completion date is missing evidence and is never replaced with now' );
oltl_check( 'missing_evidence' === GHCA_ACD_OLTL_Readiness::evidence_status( 1500, '', true, $window ), 'missing course-content documentation is missing evidence' );
oltl_check( 'missing_evidence' === GHCA_ACD_OLTL_Readiness::evidence_status( 1500, 'Documented course content', false, $window ), 'missing required external supporting evidence is missing evidence' );

$manual = array( 'decision' => 'satisfied', 'service_description' => 'Participant-specific service training', 'training_date' => '1970-01-01', 'rationale' => 'Required by the service plan', 'evidence_reference' => 'protected-reference' );
oltl_check( 'expired' === GHCA_ACD_OLTL_Readiness::manual_review_status( $manual, array( 'start_ts' => 86400, 'end_ts' => 172800 ), true ), 'manual training evidence from an earlier reporting cycle is expired' );
$manual['training_date'] = '1970-01-02';
oltl_check( 'satisfied' === GHCA_ACD_OLTL_Readiness::manual_review_status( $manual, array( 'start_ts' => 86400, 'end_ts' => 259200 ), true ), 'current-cycle manual training evidence is satisfied' );
oltl_check( 'missing_evidence' === GHCA_ACD_OLTL_Readiness::manual_review_status( $manual, array( 'start_ts' => 86400, 'end_ts' => 259200 ), false ), 'corrupt protected manual evidence is missing evidence' );
$manual['decision'] = 'not_applicable';
oltl_check( 'not_applicable' === GHCA_ACD_OLTL_Readiness::manual_review_status( $manual, array( 'start_ts' => 86400, 'end_ts' => 259200 ), true ), 'current reasoned not-applicable decision resolves the manual requirement' );

$oltl_users[11] = (object) array( 'ID' => 11, 'roles' => array( 'subscriber' ), 'first_name' => 'Synthetic', 'last_name' => 'Employee', 'user_registered' => '2026-01-01 00:00:00' );
$oltl_options[ GHCA_Audit_Mapping::OPTION_NAME ] = array( 42 => array( 'oltl_requirements' => array( 'critical_incidents' ) ) );
$oltl_meta[11]['course_completed_42'] = strtotime( '2026-06-01 UTC' );
$oltl_external = array( array( 'training_id' => 91, 'revision_id' => 92, 'snapshot_digest' => 'synthetic', 'manifest' => array( 'synthetic-file' ), 'snapshot' => array( 'oltl_requirements' => array( 'abuse_exploitation' ), 'completion_date' => '2026-06-01', 'description' => 'Documented synthetic content', 'title' => 'Synthetic external course' ) ) );
$readiness = GHCA_ACD_OLTL_Readiness::evaluate( 11 );
oltl_check( '' === $readiness['doh'] && '' === $readiness['start_date'] && 'manual_review_required' === $readiness['overall_status'] && array() === $readiness['raw_completed_courses'] && 'manual_review' === $readiness['requirements']['critical_incidents']['status'], 'registration date cannot stand in for verified employment evidence' );
$oltl_meta[11][ GHCA_ACD_Employment_Record::META_KEY ] = array( 'date' => '2026-03-01', 'source' => 'Synthetic HR reference', 'verified_by' => 90, 'verified_at' => time() );
$readiness = GHCA_ACD_OLTL_Readiness::evaluate( 11 );
oltl_check( '' === $readiness['annual_cycle'] && '' === $readiness['start_date'] && array() === $readiness['raw_completed_courses'] && 'manual_review' === $readiness['requirements']['abuse_exploitation']['status'], 'missing agency cycle fails closed despite verified employment' );
$oltl_options[ GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE ] = 'invalid';
oltl_check( array() === GHCA_ACD_OLTL_Readiness::evaluate( 11 )['raw_completed_courses'], 'invalid agency cycle fails closed' );
$oltl_options[ GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE ] = 'employee_start_date';
$readiness = GHCA_ACD_OLTL_Readiness::evaluate( 11 );
oltl_check( '03/01/2026' === $readiness['doh'] && '03/01/2026' === $readiness['start_date'] && 'satisfied' === $readiness['requirements']['critical_incidents']['status'] && 'satisfied' === $readiness['requirements']['abuse_exploitation']['status'] && 2 === count( $readiness['raw_completed_courses'] ), 'verified employment and configured hire-date cycle accept documented internal and external evidence' );
$oltl_options[ GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE ] = 'calendar_year';
oltl_check( '01/01/2026' === GHCA_ACD_OLTL_Readiness::evaluate( 11 )['start_date'], 'configured calendar-year cycle remains supported' );
$oltl_options[ GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE ] = 'employee_start_date';
foreach ( array( 'today', '2026-02-30', '2026-06-01 trailing', "2026-06-01\0", '2026-6-1' ) as $invalid ) {
	$oltl_external[0]['snapshot']['completion_date'] = $invalid;
	$readiness = GHCA_ACD_OLTL_Readiness::evaluate( 11 );
	oltl_check( 'missing_evidence' === $readiness['requirements']['abuse_exploitation']['status'] && 1 === count( $readiness['raw_completed_courses'] ), 'invalid external completion date is excluded: ' . str_replace( "\0", '[NUL]', $invalid ) );
}
$oltl_external[0]['snapshot']['completion_date'] = '2026-06-01';
foreach ( array( 'today', '2026-02-30', "2026-06-01\0", '2026-06-01 trailing' ) as $invalid ) {
	$oltl_meta[11]['course_completed_42'] = $invalid;
	$readiness = GHCA_ACD_OLTL_Readiness::evaluate( 11 );
	oltl_check( 'missing_evidence' === $readiness['requirements']['critical_incidents']['status'] && 1 === count( $readiness['raw_completed_courses'] ), 'invalid internal completion date is excluded: ' . str_replace( "\0", '[NUL]', $invalid ) );
}

$readiness_source = file_get_contents( __DIR__ . '/../includes/oltl/class-oltl-readiness.php' );
$pdf_source = file_get_contents( __DIR__ . '/../includes/class-audit-pdf.php' );
$mapping_source = file_get_contents( __DIR__ . '/../includes/class-audit-mapping.php' );
$ui_source = file_get_contents( __DIR__ . '/../includes/oltl/class-oltl-ui.php' );
$roles_source = file_get_contents( __DIR__ . '/../includes/class-roles.php' );
oltl_check( false === strpos( $readiness_source, "['oltl_category']" ), 'new evaluator never reads the preserved legacy OLTL category' );
oltl_check( false !== strpos( $readiness_source, "get_post_field( 'post_content', \$course_id )" ), 'OLTL content validation falls back to documented course content without changing the shared ODP description helper' );
oltl_check( false !== strpos( $mapping_source, "'oltl_category'  => sanitize_text_field( \$existing" ), 'legacy OLTL values are preserved for rollback' );
oltl_check( false !== strpos( $pdf_source, "array( 'orientation', 'annual', 'oltl_training' )" ), 'packet tracker allowlist includes the distinct OLTL packet type' );
oltl_check( substr_count( $pdf_source, 'user_can_manage_oltl_training()' ) >= 2, 'OLTL packet permission is rechecked at init and later job phases' );
oltl_check( substr_count( $ui_source, 'GHCA_ACD_User_Report::can_view_user( $user_id )' ) >= 2, 'card rendering and manual review both reuse employee-scope authorization' );
oltl_check( false !== strpos( $ui_source, 'is_ssl()' ), 'manual-review mutation requires HTTPS' );
oltl_check( 1 === preg_match( '/function\s+user_can_manage_oltl_training\(\):\s*bool\s*\{\s*return\s+self::user_has_full_dashboard_access\(\);\s*\}/s', $roles_source ), 'OLTL access remains limited to Administrators and Compliance Leads rather than HR Managers' );
oltl_check( false !== strpos( $pdf_source, 'Participant-specific supporting document contents are intentionally excluded.' ), 'packet explicitly excludes participant-specific document bytes' );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
