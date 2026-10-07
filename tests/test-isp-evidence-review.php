<?php
// Synthetic only: no agency forms, WordPress database or remote requests.
require __DIR__ . '/test-beta5-topic-coverage.php';
reset_fixture();
$allowed = true;
$mappings = array();
$period = GHCA_ODP_Applicability::period( 900001 );
function isp_post( array $record ): void {
	global $period;
	$_POST = array(
		'ghca_odp_review_nonce' => 'synthetic-valid', 'ghca_odp_period' => $period,
		'ghca_odp_revision' => hash( 'sha256', json_encode( GHCA_ODP_Applicability::current( 900001, $period ) ) ),
		'ghca_odp_decisions' => array( 'individual_plan' => array( 'state' => 'applicable', 'reason' => 'Synthetic HCBS service duties' ) ),
		'ghca_isp_evidence' => $record,
	);
}
$managed = array( 'state' => 'managed' );
isp_post( $managed ); GHCA_ODP_Applicability::save( 900001 );
check( 'External evidence — agency review required' === calculate()['category_statuses']['individual_plan'], 'Agency management is neither a failure nor completion nor exemption' );
$pending = array( 'state' => 'submitted', 'evidence_reference' => 'SYN-EVID-001', 'scope_reference' => 'SYN-REGISTER-v1' );
isp_post( $pending ); GHCA_ODP_Applicability::save( 900001 );
check( 'Evidence submitted — pending review' === calculate()['category_statuses']['individual_plan'], 'Submission does not grant coverage or hours' );
check( 0 == calculate()['total_hrs'] && array() === calculate()['raw_completed_courses'], 'Agency reference creates no course, certificate or credit' );
$verified = $pending;
$verified['state'] = 'verified';
$verified['training_date'] = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$verified['duration_minutes'] = '30';
$verified['training_source'] = 'Synthetic trainer reference TR-001';
$verified['training_content'] = 'Synthetic implementation activities and responsibilities';
$verified['review_note'] = 'Synthetic evidence covers employee and full register scope';
$verified['confirm_review'] = '1';
$verified['verified_by'] = 99999;
$verified['verified_at'] = 1;
isp_post( $verified ); GHCA_ODP_Applicability::save( 900001 );
$current = GHCA_ODP_Applicability::current( 900001, $period )['isp_evidence'];
check( 90 === $current['verified_by'] && $current['verified_at'] > 1, 'Reviewer attribution is server-owned, not supplied by client' );
$data = calculate();
check( 'Completed — manually verified' === $data['category_statuses']['individual_plan'] && 0 == $data['total_hrs'], 'Verified topic coverage earns no automatic hours' );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 3, 'Managed, submitted and verified revisions retained' );
unset( $verified['confirm_review'] );
isp_post( $verified ); GHCA_ODP_Applicability::save( 900001 );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 3, 'Unchanged verified review survives unrelated profile save without new approval or duplicate revision' );

foreach ( array( 'attestation', 'date', 'future', 'outside_period', 'duration', 'signature_only', 'scope', 'url', 'state', 'nonce', 'permission', 'stale_revision', 'stale_period', 'not_applicable' ) as $failure ) {
	$candidate = $verified; $candidate['review_note'] = 'Changed synthetic review'; $candidate['confirm_review'] = '1';
	if ( $failure === 'attestation' ) { unset( $candidate['confirm_review'] ); }
	if ( $failure === 'date' ) { $candidate['training_date'] = '2026-02-30'; }
	if ( $failure === 'future' ) { $candidate['training_date'] = gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ); }
	if ( $failure === 'outside_period' ) { $candidate['training_date'] = '2000-01-01'; }
	if ( $failure === 'duration' ) { $candidate['duration_minutes'] = '0'; }
	if ( $failure === 'signature_only' ) { $candidate['training_content'] = ''; }
	if ( $failure === 'scope' ) { $candidate['scope_reference'] = ''; }
	if ( $failure === 'url' ) { $candidate['evidence_reference'] = 'https://public.example/form'; }
	if ( $failure === 'state' ) { $candidate['state'] = array(); }
	isp_post( $candidate );
	if ( $failure === 'nonce' ) { $_POST['ghca_odp_review_nonce'] = 'wrong'; }
	if ( $failure === 'permission' ) { $allowed = false; }
	if ( $failure === 'stale_revision' ) { $_POST['ghca_odp_revision'] = 'stale'; }
	if ( $failure === 'stale_period' ) { $_POST['ghca_odp_period'] = 'stale'; }
	if ( $failure === 'not_applicable' ) { $_POST['ghca_odp_decisions']['individual_plan']['state'] = 'not_applicable'; }
	$rejected = false;
	try { GHCA_ODP_Applicability::save( 900001 ); } catch ( RuntimeException $error ) { $rejected = true; }
	check( $rejected && count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 3, 'Reject ' . $failure . ' without overwriting evidence' );
	$allowed = true;
}

// All other topics can be course-covered; ISP requires the chosen agency review.
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'odp_categories' => array( 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports', 'individual_plan' ), 'credit_hours' => 24 ) );
$fixture['meta']['course_completed_900001'] = time() - DAY_IN_SECONDS;
check( 'compliant' === calculate()['annual_status'] && 24 == calculate()['total_hrs'], 'Manual verification participates in the topic matrix without duplicating course hours' );
$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
GHCA_Audit_PDF::render_cover( $pdf, calculate(), 'annual' );
check( strpos( $pdf->html, 'Completed — manually verified' ) !== false && strpos( $pdf->html, 'no attachment or automatic hours' ) !== false, 'PDF distinguishes manual coverage from attached evidence and hours' );
$stream = fopen( 'php://memory', 'w+' );
$method = new ReflectionMethod( 'GHCA_Audit_Export', 'generate_annual_csv' );
$method->setAccessible( true );
$method->invoke( null, $stream, array( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ) ), $mappings );
rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream );
check( strpos( $csv, 'Completed — manually verified' ) !== false && strpos( $csv, 'SYN-REGISTER-v1' ) === false, 'CSV carries manual status without publishing secure scope identifiers' );
ob_start(); GHCA_ODP_Applicability::render( (object) array( 'ID' => 900001 ) ); $html = ob_get_clean();
check( strpos( $html, 'ghca_isp_evidence[scope_reference]' ) !== false && strpos( $html, 'Verified by user #90' ) !== false, 'Profile form renders manual review and attribution' );
isp_post( $pending ); GHCA_ODP_Applicability::save( 900001 );
check( 'needs_review' === calculate()['annual_status'] && 'Evidence submitted — pending review' === calculate()['category_statuses']['individual_plan'], 'Returning evidence for review blocks course-based green status in agency-managed mode' );
check( count( $fixture['meta'][GHCA_ODP_Applicability::META_KEY] ) === 4 && $fixture['meta'][GHCA_ODP_Applicability::META_KEY][2]['decisions']['isp_evidence']['state'] === 'verified', 'Returning for review retains prior verified revision' );
check( array() === GHCA_ODP_Applicability::current( 900001, 'another period' ), 'No automatic manual-verification rollover' );
isp_post( array( 'state' => 'none' ) ); GHCA_ODP_Applicability::save( 900001 );
check( 'Completed' === calculate()['category_statuses']['individual_plan'], 'Explicit course mode restores existing course evaluation without deleting history' );
echo "ISP evidence tests passed; $checks total checks including prior suites.\n";
