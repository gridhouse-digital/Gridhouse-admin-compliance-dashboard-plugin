<?php
// Synthetic approved snapshots only; no remote requests or real employee data.
require __DIR__ . '/test-audit-reporting-period.php';
reset_fixture();
$mappings = array();
$fixture['external'] = array( array( 'revision_id' => 900020, 'training_id' => 900021, 'snapshot_digest' => 'synthetic', 'manifest' => array( array( 'file_index' => 0, 'file_name' => 'synthetic-jpg-normalized.pdf' ), array( 'file_index' => 1, 'file_name' => 'synthetic.pdf' ) ), 'snapshot' => array( 'completion_date' => '2020-01-02', 'category' => 'person_centered', 'credit_hours' => 2.5, 'title' => 'Synthetic external orientation' ) ) );
check( empty( calculate( 'orientation' )['raw_completed_courses'] ), 'Existing approved evidence defaults annual-only' );
foreach ( array( false, '1', 1, 'true', array( true ) ) as $invalid ) {
    $fixture['external'][0]['snapshot']['applies_to_orientation'] = $invalid;
    check( empty( calculate( 'orientation' )['raw_completed_courses'] ), 'Only boolean true in approved snapshot opts in' );
}
$fixture['external'][0]['snapshot']['applies_to_orientation'] = true;
$orientation = calculate( 'orientation' );
check( 2.5 == $orientation['total_hrs'] && 1 === count( $orientation['raw_completed_courses'] ), 'Marked training included outside annual window' );
check( 'Completed' === $orientation['category_statuses']['person_centered'], 'Marked positive-hour training contributes mapped topic coverage' );
check( '' === $orientation['completion_date'] && 'Yes' !== $orientation['completed_within_30'], 'One completed area with four unmapped areas is not an orientation completion' );
$single_external = $fixture['external'];
foreach ( array( 'abuse_prevention' => '2020-01-01', 'individual_rights' => '2020-01-02', 'reporting_incidents' => '2019-12-30', 'job_related' => '2020-01-01' ) as $area => $area_date ) {
    $fixture['external'][] = array( 'revision_id' => 900030 + count( $fixture['external'] ), 'training_id' => 900040, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => $area_date, 'category' => $area, 'credit_hours' => 1, 'title' => 'Synthetic ' . $area, 'applies_to_orientation' => true ) );
}
check( '01/02/2020' === calculate( 'orientation' )['completion_date'], 'Orientation timing uses the latest external completion date once every required area is evidenced' );
$fixture['external'] = $single_external;
check( 0 == calculate()['total_hrs'], 'Annual date filtering unchanged' );
$ids = $broker = $keys = $refs = array();
$urls = GHCA_Audit_PDF::collect_certificate_urls( $orientation, 900001, $ids, $broker, $keys, $refs );
check( array( 'ghca-evidence:900020:0', 'ghca-evidence:900020:1' ) === $urls && 2 === count( $refs ), 'Both normalized image and PDF references reach existing packet fetch/merge flow' );
check( array( false, false ) === $broker, 'External evidence uses local verified storage, not LearnDash broker' );
check( false !== strpos( summary_html( $orientation, 'orientation' ), 'Orientation ODP Requirements Matrix' ), 'Orientation retains its own cover' );
$fixture['external'][0]['snapshot']['credit_hours'] = 0;
$orientation = calculate( 'orientation' );
check( 1 === count( $orientation['raw_completed_courses'] ) && 0 == $orientation['total_hrs'], 'Zero-hour supporting evidence retained without invented hours' );
check( 'Completed' !== $orientation['category_statuses']['person_centered'] && '' === $orientation['completion_date'], 'Attachment alone does not prove topic completion or timing' );
$fixture['external'][0]['snapshot']['completion_date'] = 'invalid';
$orientation = calculate( 'orientation' );
check( empty( $orientation['raw_completed_courses'] ) && ! empty( $orientation['evidence_issues'] ), 'Invalid dated evidence excluded and disclosed' );
// Four required areas completed in time, one area (job_related) with no mapped course: Needs review blocks completion.
$fixture['external'] = array();
foreach ( array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents' ) as $i => $area ) {
    $fixture['external'][] = array( 'revision_id' => 900060 + $i, 'training_id' => 900070 + $i, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ), 'category' => $area, 'credit_hours' => 1, 'title' => 'Synthetic ' . $area, 'applies_to_orientation' => true ) );
}
$unmapped = calculate( 'orientation' );
check( 'Needs review' === $unmapped['category_statuses']['job_related'] && 'Completed' === $unmapped['category_statuses']['reporting_incidents'], 'Four areas completed, one unmapped area stays Needs review' );
check( '' === $unmapped['completion_date'] && 'Yes' !== $unmapped['completed_within_30'] && 'Not complete' === $unmapped['worked_alone_compliant'], 'Calculator: an unmapped area blocks the completion date and the 30-day Yes' );
$cover = summary_html( $unmapped, 'orientation' );
check( 1 === preg_match( '#Orientation Completion Date:</strong></td>\s*<td[^>]*>\s*</td>#', $cover ), 'Rendered cover (TCPDF HTML): orientation completion date is blank' );
check( 1 === preg_match( '#Completed within 30 days of verified date of hire\?</strong></td>\s*<td[^>]*>No</td>#', $cover ) && false !== strpos( $cover, 'Needs review' ), 'Rendered cover (TCPDF HTML): 30-day answer is not Yes and the area shows Needs review' );
$fixture['external'] = array();
check( empty( calculate( 'orientation' )['raw_completed_courses'] ), 'No approved entries means no external certificates' );
echo "Orientation external checks passed; $checks cumulative checks.\n";
