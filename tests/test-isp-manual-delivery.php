<?php
// Synthetic only: delivery wording must never change completion or hours.
require __DIR__ . '/test-beta6-external-evidence.php';
reset_fixture();
$mappings = array();
$before = calculate();
$fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = 1;
$after = calculate();
check( $after['isp_manual_delivery'] && empty( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ), 'Agency setting needs no employee review record' );
$expected = $before; $expected['isp_manual_delivery'] = true;
check( $expected === $after, 'Delivery label leaves all calculated results unchanged' );
check( false !== strpos( GHCA_Audit_Calculator::category_display( $after, 'individual_plan' ), 'Handled manually / in person' ), 'Shared PDF/CSV display uses manual delivery label' );
check( ! calculate( 'orientation' )['isp_manual_delivery'], 'Orientation unchanged' );
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
GHCA_Audit_PDF::render_cover( $pdf, $after, 'annual' );
check( false !== strpos( $pdf->html, 'Handled manually / in person' ) && false !== strpos( $pdf->html, 'Supporting records are maintained by the agency' ), 'PDF row and separate short note present' );
check( false === strpos( $pdf->html, 'ISP evidence to be supplied separately' ), 'No duplicate old long disclosure' );
$stream = fopen( 'php://memory', 'w+' );
$method->invoke( null, $stream, array( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ) ), $mappings );
rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream );
check( false !== strpos( $csv, 'Handled manually / in person' ) && false !== strpos( $csv, 'completion is not verified' ), 'CSV distinguishes delivery from verification' );
unset( $fixture['meta']['ghca_acd_verified_employment'], $fixture['options']['ghca_acd_annual_cycle'] );
check( calculate()['isp_manual_delivery'] && 'missing_evidence' === calculate()['annual_status'], 'Label does not require dates and does not hide missing-date calculation status' );
foreach ( array( 0, '0', '', array( 1 ), 'yes', true ) as $invalid ) {
    $fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = $invalid;
    check( ! calculate()['isp_manual_delivery'], 'Only explicit 1 enables label' );
}
reset_fixture();
$period = GHCA_ODP_Applicability::period( 900001 );
$fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = '1';
isp_post( array( 'state' => 'none' ) );
$_POST['ghca_odp_decisions']['individual_plan'] = array( 'state' => 'not_applicable', 'reason' => 'Synthetic reviewed scope exclusion' );
GHCA_ODP_Applicability::save( 900001 );
check( ! calculate()['isp_manual_delivery'] && 'Not applicable' === calculate()['category_statuses']['individual_plan'], 'Reviewed employee exemption remains visible' );
class ManualDeliveryRegistrationCaptured extends RuntimeException {}
function register_setting( $group, $key, $args ) {
    $GLOBALS['manual_registration'] = array( $group, $key, $args );
    throw new ManualDeliveryRegistrationCaptured();
}
try { GHCA_ACD_Settings::register_settings(); } catch ( ManualDeliveryRegistrationCaptured $e ) {}
list( $group, $key, $args ) = $GLOBALS['manual_registration'];
check( 'ghca_acd_settings' === $group && GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY === $key && 0 === $args['default'] && false === $args['show_in_rest'], 'Registered in existing protected Settings API group, default off' );
foreach ( array( 1, '1', 0, '0', null, array( 1 ), 'yes', true ) as $value ) {
    check( call_user_func( $args['sanitize_callback'], $value ) === ( in_array( $value, array( 1, '1' ), true ) ? 1 : 0 ), 'Strict checkbox sanitizer' );
}
echo "Manual delivery tests passed; $checks cumulative checks.\n";
