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
check( '01/02/2020' === $orientation['completion_date'], 'Orientation timing uses external completion date' );
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
$fixture['external'] = array();
check( empty( calculate( 'orientation' )['raw_completed_courses'] ), 'No approved entries means no external certificates' );
echo "Orientation external checks passed; $checks cumulative checks.\n";
