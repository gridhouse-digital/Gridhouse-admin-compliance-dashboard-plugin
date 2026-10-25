<?php
// Synthetic records only. Reuses the packet calculation and certificate selection harness.
require __DIR__ . '/test-orientation-external.php';
reset_fixture();
$mappings = array();
check( isset( GHCA_Audit_Mapping::get_odp_categories()['odp_annual_training'] ), 'Catalog recognizes the persisted category' );
foreach ( array( 1, 1.5, 1, 1, 1 ) as $i => $hours ) {
    $fixture['external'][] = array( 'revision_id' => 910000 + $i, 'training_id' => 920000 + $i, 'snapshot_digest' => 'synthetic', 'manifest' => array( array( 'file_index' => 0, 'file_name' => 'synthetic.pdf' ) ), 'snapshot' => array( 'completion_date' => gmdate( 'Y-m-d' ), 'category' => 'odp_annual_training', 'credit_hours' => $hours, 'title' => 'Synthetic training ' . $i, 'applies_to_orientation' => true ) );
}
$period = array( 'start' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d' ) );
$before = $fixture;
foreach ( array( calculate( 'orientation' ), calculate(), GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ), 'annual', $mappings, $period ) ) as $data ) {
    check( count( $data['raw_completed_courses'] ) === 5, 'All five courses reach packet details' );
    check( 5.5 == $data['additional_hrs'] && 5.5 == $data['total_hrs'] && 0 == $data['total_annual_hrs'], 'Broad category contributes additional hours once only' );
    check( 'Completed' !== $data['category_statuses']['person_centered'], 'Broad category does not fulfill a required topic' );
    $ids = $broker = $keys = $refs = array();
    $urls = GHCA_Audit_PDF::collect_certificate_urls( $data, 900001, $ids, $broker, $keys, $refs );
    check( count( $urls ) === 5 && count( $refs ) === 5 && count( array_unique( $urls ) ) === 5, 'All five certificate references reach packet pipeline exactly once' );
}
check( $before === $fixture, 'Calculation does not change existing approval or evidence' );
unset( $fixture['external'][0]['snapshot']['applies_to_orientation'] );
check( count( calculate( 'orientation' )['raw_completed_courses'] ) === 4, 'Orientation opt-in still enforced' );
$fixture['external'][0]['snapshot']['completion_date'] = '2000-01-01';
check( count( calculate()['raw_completed_courses'] ) === 4, 'Annual window still enforced' );
$fixture['external'][1]['snapshot']['category'] = 'unknown_category';
check( count( calculate()['raw_completed_courses'] ) === 3, 'Unknown categories remain excluded' );
echo "External annual category checks passed; $checks cumulative checks.\n";
