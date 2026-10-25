<?php
// Synthetic, no WordPress bootstrap or external calls. Reuse the existing PDF/CSV fixture.
require __DIR__ . '/test-audit-date-evidence.php';
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function get_current_user_id() { return 90; }
function add_settings_error( ...$args ) { $GLOBALS['mapping_errors'][] = $args; }
function current_user_can( $cap, ...$args ) { return $GLOBALS['allowed']; }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'synthetic-valid' && in_array( $action, array( 'ghca_odp_review_900001', 'ghca_agency_training_900001' ), true ); }
function add_user_meta( $id, $key, $value ) { if ( ! empty( $GLOBALS['reject_meta_write'] ) ) { return false; } $GLOBALS['fixture']['meta'][$key][] = $value; return 1; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function selected( $a, $b, $echo = true ) { $value = $a == $b ? 'selected="selected"' : ''; if ( $echo ) { echo $value; } return $value; }
function checked( $a, $b = true, $echo = true ) { $value = $a == $b ? 'checked="checked"' : ''; if ( $echo ) { echo $value; } return $value; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="synthetic-valid">'; }
function settings_fields( $group ) { echo '<input name="option_page" value="' . esc_attr( $group ) . '">'; }
function settings_errors( $group ) {}
function submit_button() { echo '<button>Save Changes</button>'; }
function get_edit_post_link( $id ) { return '/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_posts( $query ) { return array( (object) array( 'ID' => 900001, 'post_title' => 'Synthetic Course' ) ); }
class GHCA_ACD_OLTL_Readiness {
	public static function annual_requirements() { return array(); }
	public static function sanitize_requirement_codes( $codes ) { return $codes; }
}

reset_fixture();
$allowed = true;
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'odp_categories' => array( 'individual_plan', 'individual_plan', 'invalid', array() ), 'credit_hours' => 2, 'is_orientation' => 1 ) );
$fixture['meta']['course_completed_900001'] = time() - DAY_IN_SECONDS;
$data = calculate();
check( 2 == $data['total_hrs'] && count( $data['raw_completed_courses'] ) === 1, 'Multi-topic evidence earns hours and certificate once' );
check( 'Completed' === $data['category_statuses']['individual_plan'] && 0 == $data['requirement_hours']['individual_plan']['achieved'], 'Additional category gets coverage without duplicate hours' );
check( array( 'person_centered', 'individual_plan' ) === $data['raw_completed_courses'][0]['categories'], 'Coverage is allowlisted and deduplicated' );
check( 'Needs review' === $data['category_statuses']['abuse_prevention'], 'Unmapped topic is review, not failure' );
$mappings[900002] = array( 'odp_category' => 'individual_rights', 'credit_hours' => 0 );
check( 'Missing training' === calculate()['category_statuses']['individual_rights'], 'Mapped incomplete topic is missing even at zero configured hours' );
$fixture['meta']['course_completed_900002'] = time() - DAY_IN_SECONDS;
check( 'Completed' === calculate()['category_statuses']['individual_rights'], 'Zero-hour completion still covers topic' );
$fixture['meta']['course_completed_900001'] = 'invalid';
check( 'Needs review' === calculate()['category_statuses']['individual_plan'], 'Invalid dates do not acquire secondary coverage' );
$fixture['meta']['course_completed_900001'] = time() - 400 * DAY_IN_SECONDS;
check( 0 == calculate()['total_hrs'] && 'Completed' !== calculate()['category_statuses']['individual_plan'], 'Out-of-window course earns no current coverage' );

$legacy = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 2, 'sort_order' => 0 ) );
$fixture['options'][GHCA_Audit_Mapping::OPTION_NAME] = $legacy;
$request = $legacy;
$request[900001]['odp_categories'] = array( 'individual_plan' );
check( $legacy === GHCA_Audit_Mapping::sanitize_mapping( $request ), 'Missing content reference preserves previous course mapping' );
$request[900001]['odp_mapping_reference'] = 'Synthetic syllabus sections 1 and 2';
$saved = GHCA_Audit_Mapping::sanitize_mapping( $request );
check( count( $saved[900001]['odp_categories'] ) === 2 && $saved[900001]['odp_reviewed_by'] === 90, 'Reviewed mapping saves coverage and actor' );
check( 0 === GHCA_Audit_Mapping::sanitize_mapping( $saved )[900001]['is_orientation'], 'Repeated Settings API sanitization preserves non-orientation courses' );
$_POST = array( 'option_page' => 'ghca_acd_audit_settings' );
check( $legacy === GHCA_Audit_Mapping::sanitize_mapping( $request ), 'Truncated form preserves every existing mapping' );
$_POST = array();
$allowed = false;
check( $legacy === GHCA_Audit_Mapping::sanitize_mapping( $request ), 'Unauthorized mapping mutation is rejected' );
$allowed = true;
check( array( 'person_centered' ) === GHCA_Audit_Mapping::coverage_categories( $legacy[900001] ), 'Legacy single-category mappings remain valid' );

$allowed = true;
$period = GHCA_ODP_Applicability::period( 900001 );
$_POST = array( 'ghca_odp_review_nonce' => 'synthetic-valid', 'ghca_odp_period' => $period, 'ghca_odp_decisions' => array( 'individual_plan' => array( 'state' => 'not_applicable', 'reason' => 'Synthetic duties exclude HCBS/base-funded services' ) ) );
GHCA_ODP_Applicability::save( 900001 );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 1, 'Review appends actor/time/period record' );
GHCA_ODP_Applicability::save( 900001 );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 1, 'Unchanged review is not duplicated' );
$data = calculate();
check( 'Not applicable' === $data['category_statuses']['individual_plan'] && strpos( GHCA_Audit_Calculator::category_display( $data, 'individual_plan' ), 'Synthetic duties' ) !== false, 'N/A includes recorded reason' );
check( array() === GHCA_ODP_Applicability::current( 900001, 'different period' ), 'Reviews cannot carry into another period' );
$_POST['ghca_odp_decisions']['individual_plan'] = array( 'state' => 'applicable', 'reason' => 'Synthetic duties now include services' );
GHCA_ODP_Applicability::save( 900001 );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 2 && 'Missing training' === calculate()['category_statuses']['individual_plan'], 'Revised decision preserves prior review and applies current decision' );
foreach ( array( 'capability', 'nonce', 'period', 'reason', 'state' ) as $failure ) {
	$backup = $_POST;
	if ( $failure === 'capability' ) { $allowed = false; }
	if ( $failure === 'nonce' ) { $_POST['ghca_odp_review_nonce'] = 'invalid'; }
	if ( $failure === 'period' ) { $_POST['ghca_odp_period'] = 'stale'; }
	if ( $failure === 'reason' ) { $_POST['ghca_odp_decisions']['individual_plan']['reason'] = ''; }
	if ( $failure === 'state' ) { $_POST['ghca_odp_decisions']['individual_plan']['state'] = 'anything'; }
	$rejected = false;
	try { GHCA_ODP_Applicability::save( 900001 ); } catch ( RuntimeException $error ) { $rejected = true; }
	check( $rejected && count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 2, 'Reject ' . $failure . ' without writes' );
	$_POST = $backup; $allowed = true;
}

$fixture['meta']['course_completed_900001'] = time() - DAY_IN_SECONDS;
$mappings[900001]['credit_hours'] = 24;
$data = calculate();
check( 'needs_review' === $data['annual_status'], '24 hours alone cannot certify unresolved core topics' );
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
GHCA_Audit_PDF::render_cover( $pdf, $data, 'annual' );
check( strpos( $pdf->html, 'Hours Counted Here' ) !== false && strpos( $pdf->html, 'Needs review' ) !== false && strpos( $pdf->html, 'Credit Hrs Required / Achieved' ) === false, 'PDF renders unambiguous topic states and unique hours' );
GHCA_Audit_PDF::render_course_detail_page( $pdf, $data['raw_completed_courses'][0] );
check( strpos( $pdf->html, 'Implementation of the individual plan' ) !== false, 'Single evidence detail page lists additional coverage' );
ob_start(); GHCA_Audit_Mapping::render_page(); $mapping_html = ob_get_clean();
check( strpos( $mapping_html, 'Additional topic coverage' ) !== false && strpos( $mapping_html, 'odp_mapping_reference' ) !== false && strpos( $mapping_html, 'Description missing' ) !== false, 'Mapping form renders coverage, reference and source-description warning' );
ob_start(); GHCA_ODP_Applicability::render( (object) array( 'ID' => 900001 ) ); $profile_html = ob_get_clean();
check( strpos( $profile_html, 'ghca_odp_review_nonce' ) !== false && strpos( $profile_html, 'ghca_odp_period' ) !== false && strpos( $profile_html, 'Synthetic duties now include services' ) !== false, 'Profile form renders saved decision, reason, nonce and period' );
$allowed = false;
ob_start(); GHCA_ODP_Applicability::render( (object) array( 'ID' => 900001 ) ); GHCA_Audit_Mapping::render_page(); $denied_html = ob_get_clean();
check( '' === $denied_html, 'Unauthorized users cannot render either editor' );
echo "Beta 5 coverage/applicability checks passed; $checks total checks including baseline.\n";
