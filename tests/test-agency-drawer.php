<?php
// Fictional employee only. No WordPress bootstrap, remote requests or production writes.
require __DIR__ . '/test-agency-training.php';
$hooks = array();
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks'][$hook][] = $callback; }
class ProfileErrors {
    public $messages = array();
    public function add( $code, $message ) { $this->messages[] = $message; }
}
GHCA_Agency_Training::init(); GHCA_ODP_Applicability::init();
check( ! isset( $hooks['edit_user_profile_update'], $hooks['personal_options_update'] ) && count( $hooks['profile_update'] ) === 2, 'Both writers run only after successful profile validation' );
reset_fixture(); $allowed = true; $report_nonce = $report_scope = $report_edit = true;
$fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = 1;
$fixture['options'][GHCA_Agency_Training::OPTION] = array( 'isp_hours' => '1', 'communication_hours' => '1', 'communication_enabled' => '1' );
$start = '2025-09-01'; $end = '2026-08-31';
agency_post( $start, $end, array( 'isp' => 'confirmed', 'communication' => 'confirmed' ) );
$errors = new ProfileErrors(); GHCA_Agency_Training::validate( $errors, true, (object) array( 'ID' => 900001 ) );
check( ! $errors->messages && ! GHCA_Agency_Training::history( 900001 ), 'Validation is read-only and does not self-stale' );
foreach ( $hooks['profile_update'] as $callback ) { call_user_func( $callback, 900001 ); }
check( count( GHCA_Agency_Training::history( 900001 ) ) === 1, 'Successful profile lifecycle appends exactly once after validation' );
function drawer_request( array $post ) {
    $_POST = $_REQUEST = $post;
    try { GHCA_Agency_Training::ajax(); } catch ( ReportingResponse $response ) { return $response; }
    throw new RuntimeException( 'Missing drawer response' );
}
$base = array( 'user_id' => '900001', 'mode' => 'custom', 'start' => $start, 'end' => $end );
$confirmed = drawer_request( $base );
check( $confirmed->success && strpos( $confirmed->data['html'], 'Completed in person — 1 confirmed hours') !== false, 'Load reads persisted status and hours' );
check( strpos( $confirmed->data['html'], 'reviewer #90') !== false && strpos( $confirmed->data['html'], 'Confirmation history') !== false, 'Drawer includes attribution and retained history' );
check( strpos( $confirmed->data['html'], 'ghca_isp_evidence[') === false, 'Legacy detailed editor absent from routine drawer' );
$allowed = false;
check( drawer_request( $base )->success, 'Authorized scoped compliance editor needs no wp-admin capability' );
check( is_wp_error( GHCA_Agency_Training::submission( 900001 ) ), 'Profile authorization remains admin-only' );
$allowed = true;
foreach ( array( 'report_nonce', 'report_scope', 'report_edit' ) as $guard ) {
    $GLOBALS[$guard] = false;
    check( drawer_request( $base )->status === 403, 'Load denies ' . $guard );
    check( drawer_request( $base + array( 'operation' => 'training' ) )->status === 403, 'Save denies ' . $guard );
    $GLOBALS[$guard] = true;
}
check( drawer_request( array_merge( $base, array( 'user_id' => '900002' ) ) )->status === 403, 'Other employee denied' );
check( drawer_request( array_merge( $base, array( 'end' => '2026-02-30' ) ) )->status === 400, 'Invalid date rejected' );
$other = drawer_request( array_merge( $base, array( 'end' => '2026-09-18' ) ) );
check( $other->success && strpos( $other->data['html'], 'Saved status: Not confirmed for these dates') !== false, 'Different range does not borrow status' );
agency_post( $start, $end, array( 'isp' => 'withdrawn', 'communication' => 'unchanged' ) );
$save = $_POST + $base + array( 'operation' => 'training' );
check( drawer_request( array_merge( $save, array( 'ghca_agency_training_nonce' => 'invalid' ) ) )->status === 400, 'Employee-specific save nonce required' );
check( drawer_request( array_merge( $save, array( 'end' => '2026-09-18' ) ) )->status === 409, 'Displayed and submitted dates must match' );
$withdrawn = drawer_request( $save );
check( $withdrawn->success && strpos( $withdrawn->data['html'], 'Saved status: Confirmation withdrawn') !== false, 'Save immediately reloads persisted withdrawal status' );
check( count( GHCA_Agency_Training::history( 900001 ) ) === 2, 'Withdrawal appends without deleting history' );
check( ! drawer_request( $save )->success, 'Stale revision rejected on repeat POST' );
$review = $base + array( 'operation' => 'review', 'ghca_odp_review_nonce' => 'synthetic-valid', 'ghca_odp_period' => GHCA_ODP_Applicability::period( 900001 ), 'ghca_odp_revision' => hash( 'sha256', json_encode( array() ) ), 'ghca_odp_decisions' => array( 'behavior_supports' => array( 'state' => 'applicable', 'reason' => 'Synthetic assigned duties' ), 'individual_plan' => array( 'state' => 'applicable', 'reason' => 'Synthetic services' ) ) );
check( drawer_request( $review )->success, 'Independent advanced review saves current annual decisions' );
check( count( GHCA_Agency_Training::history( 900001 ) ) === 2, 'Advanced save does not alter training confirmations' );
check( ! drawer_request( $review )->success, 'Advanced review rejects stale revision' );
$review['ghca_odp_revision'] = hash( 'sha256', json_encode( GHCA_ODP_Applicability::current( 900001, $review['ghca_odp_period'] ) ) );
$partial = $review; unset( $partial['ghca_odp_decisions']['individual_plan'] );
check( ! drawer_request( $partial )->success, 'Truncated advanced form cannot erase decisions' );
$review['ghca_isp_evidence'] = array( 'state' => 'none' );
check( ! drawer_request( $review )->success, 'Drawer cannot overwrite retained legacy evidence' );
unset( $review['ghca_isp_evidence'] );
$decisions = GHCA_ODP_Applicability::current( 900001, $review['ghca_odp_period'] );
$decisions['isp_evidence'] = array( 'state' => 'agency_managed', 'reference' => 'SYNTHETIC-REFERENCE' );
$fixture['meta'][GHCA_ODP_Applicability::META_KEY][] = array( 'period' => $review['ghca_odp_period'], 'decisions' => $decisions, 'reviewed_by' => 90, 'reviewed_at' => time() );
$review['ghca_odp_revision'] = hash( 'sha256', json_encode( $decisions ) );
$review['ghca_odp_decisions']['behavior_supports']['reason'] = 'Synthetic revised duties';
check( drawer_request( $review )->success && GHCA_ODP_Applicability::current( 900001, $review['ghca_odp_period'] )['isp_evidence'] === $decisions['isp_evidence'], 'Applicability change preserves legacy evidence verbatim' );
$review['ghca_odp_revision'] = hash( 'sha256', json_encode( GHCA_ODP_Applicability::current( 900001, $review['ghca_odp_period'] ) ) );
$review['ghca_odp_decisions']['individual_plan']['state'] = 'not_applicable';
check( ! drawer_request( $review )->success, 'Existing manual evidence cannot silently become an exemption' );
agency_post( $start, $end, array( 'isp' => 'confirmed', 'communication' => 'unchanged' ) );
$reject_meta_write = true;
check( ! drawer_request( $_POST + $base + array( 'operation' => 'training' ) )->success && count( GHCA_Agency_Training::history( 900001 ) ) === 2, 'Persistence failure reports error and retains history' );
$reject_meta_write = false;
agency_post( $start, $end, array( 'isp' => 'confirmed', 'communication' => 'unchanged' ) );
unset( $_POST['ghca_agency_training']['confirm'] );
$errors = new ProfileErrors(); GHCA_Agency_Training::validate( $errors, true, (object) array( 'ID' => 900001 ) );
check( count( $errors->messages ) === 1 && count( GHCA_Agency_Training::history( 900001 ) ) === 2, 'Invalid profile validation makes no writes' );
echo "Drawer save/authorization checks passed; $checks cumulative checks.\n";
if ( in_array( '--render', $argv, true ) ) {
    echo 'DRAWER_FIXTURES=' . json_encode( array( 'confirmed' => $confirmed->data, 'withdrawn' => $withdrawn->data, 'other' => $other->data ) );
}
