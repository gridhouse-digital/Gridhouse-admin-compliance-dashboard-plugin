<?php
// Synthetic records only; reuse existing period, authorization and PDF/CSV checks.
require __DIR__ . '/test-isp-evidence-review.php';
$before = calculate();
isp_post( array( 'state' => 'managed' ) );
$_POST['ghca_odp_decisions'] = array(); // No detail entry or applicability exemption.
GHCA_ODP_Applicability::save( 900001 );
$data = calculate();
check( $data['external_isp_evidence'] && 'External evidence — agency review required' === $data['category_statuses']['individual_plan'], 'Explicit external handling works with blank training-detail fields' );
check( 'needs_review' === $data['annual_status'], 'External handling never certifies completion despite sufficient hours and generic course coverage' );
check( $before['total_hrs'] === $data['total_hrs'] && $before['raw_completed_courses'] === $data['raw_completed_courses'], 'External handling changes no course, hours or certificate inputs' );
check( false !== strpos( $data['category_notes']['individual_plan'], 'not been verified within the platform' ) && false !== strpos( $data['category_notes']['individual_plan'], 'must supply' ), 'Disclosure does not assert receipt or verification of external records' );
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
GHCA_Audit_PDF::render_cover( $pdf, $data, 'annual' );
check( 1 === substr_count( $pdf->html, 'The agency has selected separate handling' ) && false !== strpos( $pdf->html, 'ISP evidence to be supplied separately' ), 'PDF places disclosure once outside narrow topic cell' );
check( false !== strpos( $pdf->html, 'Needs Review' ) && false !== strpos( $pdf->html, 'External evidence — agency review required' ), 'PDF preserves unresolved overall and ISP statuses' );
$stream = fopen( 'php://memory', 'w+' );
$method->invoke( null, $stream, array( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ) ), $mappings );
rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream );
check( false !== strpos( $csv, 'External evidence — agency review required' ) && false !== strpos( $csv, 'not attached to this packet' ), 'CSV carries same honest external-evidence disclosure' );
ob_start(); GHCA_ODP_Applicability::render( (object) array( 'ID' => 900001 ) ); $html = ob_get_clean();
check( false !== strpos( $html, 'Training-detail fields may remain blank' ), 'Profile explains the temporary no-detail route' );
isp_post( array( 'state' => 'none' ) ); GHCA_ODP_Applicability::save( 900001 );
check( ! calculate()['external_isp_evidence'], 'Opt-out does not leave an external disclosure on course mode' );
$mappings = array();
check( ! calculate()['external_isp_evidence'] && 'Needs review' === calculate()['category_statuses']['individual_plan'], 'Unmapped ISP is not automatically relabeled as external evidence' );
echo "Beta 6 external evidence tests passed; $checks total checks including prior suites.\n";
