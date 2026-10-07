<?php
// Synthetic only: actual save validation, append-only persistence, calculator and PDF HTML.
require __DIR__ . '/test-packet-course-selection.php';
reset_fixture(); $allowed = true; $mappings = array();
$fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = 1;
$fixture['options'][GHCA_Agency_Training::OPTION] = array( 'isp_hours' => '1', 'communication_hours' => '1', 'communication_enabled' => '1' );
$settings = GHCA_Agency_Training::settings();
check( 0 == calculate()['total_hrs'], 'Delivery switches alone cannot create credit' );
check( false !== strpos( summary_html( calculate() ), 'Communication Profile Training' ), 'Enabled communication row shown before confirmation' );
foreach ( array( array(), array( 'isp_hours' => '-1' ), array_merge( $settings, array( 'isp_hours' => 'NaN' ) ), array_merge( $settings, array( 'communication_hours' => '101' ) ), array_merge( $settings, array( 'isp_hours' => array() ) ) ) as $bad ) {
    check( $settings === GHCA_Agency_Training::sanitize_settings( $bad ), 'Malformed settings preserve previous defaults' );
}
check( $settings === GHCA_Agency_Training::sanitize_settings( GHCA_Agency_Training::sanitize_settings( $settings ) ), 'Settings double sanitization stable' );
$data = calculate();
$start = DateTimeImmutable::createFromFormat( '!m/d/Y', $data['start_date'] )->format( 'Y-m-d' );
$end = DateTimeImmutable::createFromFormat( '!m/d/Y', $data['end_date'] )->format( 'Y-m-d' );
function agency_post( $start, $end, $actions ) {
    $_POST = array( 'ghca_agency_training_nonce' => 'synthetic-valid', 'ghca_agency_training' => array( 'start' => $start, 'end' => $end, 'confirm' => '1', 'actions' => $actions, 'settings_revision' => GHCA_Agency_Training::settings_revision(), 'revision' => hash( 'sha256', json_encode( GHCA_Agency_Training::history( 900001 ) ) ) ) );
}
$actions = array( 'isp' => 'confirmed', 'communication' => 'confirmed' );
agency_post( $start, $end, $actions );
$good = $_POST;
foreach ( array( 'nonce', 'permission', 'confirmation', 'revision', 'defaults', 'date', 'action', 'employee' ) as $bad ) {
    $_POST = $good; $allowed = true; $id = 900001;
    if ( 'nonce' === $bad ) { $_POST['ghca_agency_training_nonce'] = 'invalid'; }
    if ( 'permission' === $bad ) { $allowed = false; }
    if ( 'confirmation' === $bad ) { unset( $_POST['ghca_agency_training']['confirm'] ); }
    if ( 'revision' === $bad ) { $_POST['ghca_agency_training']['revision'] = 'stale'; }
    if ( 'defaults' === $bad ) { $_POST['ghca_agency_training']['settings_revision'] = 'stale'; }
    if ( 'date' === $bad ) { $_POST['ghca_agency_training']['end'] = '2026-02-30'; }
    if ( 'action' === $bad ) { $_POST['ghca_agency_training']['actions']['isp'] = 'fake'; }
    if ( 'employee' === $bad ) { $id = 900002; }
    check( is_wp_error( GHCA_Agency_Training::submission( $id ) ), 'Reject ' . $bad . ' before persistence' );
}
$allowed = true; $_POST = $good;
GHCA_Agency_Training::save( 900001 );
$data = calculate();
check( 2 == $data['total_hrs'] && 1 == $data['total_annual_hrs'] && 1 == $data['additional_hrs'], 'ISP topic plus communication additional credit counted exactly once' );
check( 'Completed — manually verified' === $data['category_statuses']['individual_plan'] && empty( $data['raw_completed_courses'] ), 'Confirmation establishes ISP without inventing a course/certificate' );
check( false !== strpos( summary_html( $data ), 'Completed in person — agency confirmed' ), 'PDF renders agency confirmation' );
check( empty( calculate( 'orientation' )['agency_training'] ) && 0 == calculate( 'orientation' )['total_hrs'], 'No implicit orientation credit' );
$fixture['options'][GHCA_Agency_Training::OPTION]['isp_hours'] = '3';
agency_post( $start, $end, $actions ); GHCA_Agency_Training::save( 900001 );
check( 1 === count( GHCA_Agency_Training::history( 900001 ) ) && 2 == calculate()['total_hrs'], 'Resave/default change cannot duplicate or rewrite confirmed credit' );
$period = array( 'start' => $start, 'end' => $end );
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 900001, 'name' => 'Synthetic' ), 'annual', $mappings, $period );
check( 'reporting_only' === $report['annual_status'] && 2 == $report['total_hrs'], 'Custom report with exact dates receives same confirmations without annual verdict' );
$period['end'] = ( new DateTimeImmutable( $end ) )->modify( '+1 day' )->format( 'Y-m-d' );
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 900001, 'name' => 'Synthetic' ), 'annual', $mappings, $period );
check( 0 == $report['total_hrs'], 'Other/overlapping periods cannot borrow confirmations' );
$mappings[900001] = array( 'odp_category' => 'individual_plan', 'credit_hours' => 4 );
$fixture['meta']['course_completed_900001'] = time();
check( 5 == calculate()['total_hrs'] && 0 == calculate()['agency_training']['isp']['counted_hours'], 'Existing ISP course hours not doubled by manual confirmation' );
$mappings = array();
agency_post( $start, $end, array( 'isp' => 'withdrawn', 'communication' => 'unchanged' ) );
GHCA_Agency_Training::save( 900001 );
check( 2 === count( GHCA_Agency_Training::history( 900001 ) ) && 1 == calculate()['total_hrs'], 'Withdrawal retains history and removes only selected credit' );
agency_post( $start, $end, $actions ); GHCA_Agency_Training::save( 900001 );
check( 4 == calculate()['total_hrs'] && 3 === count( GHCA_Agency_Training::history( 900001 ) ), 'Explicit new confirmation after withdrawal uses corrected default' );
$_POST = array();
ob_start(); GHCA_Agency_Training::render( (object) array( 'ID' => 900001 ) ); $profile_html = ob_get_clean();
check( false !== strpos( $profile_html, 'ghca_agency_training_nonce' ) && false !== strpos( $profile_html, 'settings_revision' ) && false === strpos( $profile_html, '<form' ), 'Profile uses nonce and revision within native form, never nested forms' );
check( false !== strpos( $profile_html, 'Withdraw confirmation' ) && false !== strpos( $profile_html, 'reviewer #90' ), 'Profile exposes correction control and attributed history' );
$allowed = false; ob_start(); GHCA_Agency_Training::render( (object) array( 'ID' => 900001 ) ); $denied = ob_get_clean();
check( '' === $denied, 'Unauthorized profile does not expose history or confirmation controls' ); $allowed = true;
ob_start(); GHCA_Agency_Training::settings_fields(); $settings_html = ob_get_clean();
check( false !== strpos( $settings_html, '[isp_hours]' ) && false !== strpos( $settings_html, '[communication_hours]' ) && false !== strpos( $settings_html, '[communication_enabled]' ), 'Settings exposes both defaults and communication delivery switch' );
echo "Agency training passed; $checks cumulative checks.\n";
