<?php
// Synthetic only; no real employee or certificate fixtures.
require __DIR__ . '/test-isp-manual-delivery.php';
reset_fixture();
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'odp_categories' => array( 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports' ), 'credit_hours' => 24 ) );
$fixture['meta']['course_completed_900001'] = time() - DAY_IN_SECONDS;
$fixture['options'][GHCA_ACD_Settings::OPTION_ISP_MANUAL_DELIVERY] = 1;
$data = calculate();
function summary_html( array $data, string $tracker = 'annual' ): string {
    $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
    GHCA_Audit_PDF::render_cover( $pdf, $data, $tracker );
    return $pdf->html;
}
$label = 'Recorded hours and other required topics met';
$html = summary_html( $data );
check( false !== strpos( $html, $label ) && false === strpos( $html, 'Needs Review — Topic Coverage Unresolved' ), 'ISP-only gap gets accurate split-scope summary' );
check( 'needs_review' === $data['annual_status'] && 24 == $data['total_hrs'], 'Presentation does not invent compliance or hours' );
check( false !== strpos( $html, '<strong>ISP training delivery:</strong> Supporting records are maintained by the agency and provided separately for audit.</p>' ) && false === strpos( $html, '>Compliant<' ), 'Short agency-records note does not assert compliance' );
foreach ( array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports' ) as $category ) {
    foreach ( array( 'Needs review', 'Missing training' ) as $status ) {
        $gap = $data; $gap['category_statuses'][$category] = $status;
        check( false === strpos( summary_html( $gap ), $label ), 'Other topic gap retains warning: ' . $category . ' ' . $status );
    }
}
foreach ( array( 'in_progress', 'noncompliant', 'missing_evidence', 'compliant' ) as $status ) {
    $case = $data; $case['annual_status'] = $status;
    check( false === strpos( summary_html( $case ), $label ), 'Other overall states stay unchanged' );
}
$case = $data; $case['total_hrs'] = 23.5;
check( false === strpos( summary_html( $case ), $label ), 'Insufficient hours never get met summary' );
$case = $data; $case['evidence_issues'] = array( 'Synthetic invalid completion date' );
check( false === strpos( summary_html( $case ), $label ), 'Evidence issue is not hidden' );
$case = $data; unset( $case['category_statuses']['individual_rights'] );
check( false === strpos( summary_html( $case ), $label ), 'Missing topic status fails closed' );
$case = $data; $case['isp_manual_delivery'] = false;
check( false === strpos( summary_html( $case ), $label ), 'Agency must opt in' );
check( false === strpos( summary_html( $data, 'orientation' ), $label ), 'Orientation unaffected' );
$case = $data; $case['category_statuses']['behavior_supports'] = 'Not applicable';
check( false !== strpos( summary_html( $case ), $label ), 'Existing reviewed conditional exemption respected' );
echo "Manual ISP summary passed; $checks cumulative checks.\n";
