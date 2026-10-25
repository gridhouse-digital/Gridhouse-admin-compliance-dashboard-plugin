<?php
// No site data or connections. Actual calculator and captured PDF HTML, synthetic records.
require __DIR__ . '/test-external-annual-category.php';
reset_fixture(); $allowed = true; $mappings = array();
$value = array( 'complete' => '1', 'annual' => array( '', 'learndash:900001', 'external:900050', 'learndash:900001' ), 'orientation' => array( '', 'external:900050' ) );
$saved = GHCA_Packet_Course_Selection::sanitize( $value );
check( 2 === count( $saved['annual'] ) && 1 === count( $saved['orientation'] ), 'Separate selections, blank sentinel and duplicate removal' );
check( $saved === GHCA_Packet_Course_Selection::sanitize( $saved ), 'First-save double sanitization preserves validated choices' );
$fixture['options'][GHCA_Packet_Course_Selection::OPTION] = $saved;
foreach ( array( null, array(), array( 'complete' => '1', 'annual' => array( 'unknown:1' ), 'orientation' => array() ), array( 'complete' => '1', 'annual' => array( array() ), 'orientation' => array() ), array( 'complete' => '1', 'annual' => array_fill( 0, 32, '' ), 'orientation' => array() ) ) as $bad ) {
    check( $saved === GHCA_Packet_Course_Selection::sanitize( $bad ), 'Invalid/truncated/oversized input preserves selections' );
}
$allowed = false;
check( $saved === GHCA_Packet_Course_Selection::sanitize( $value ), 'Unauthorized save preserves selection' ); $allowed = true;
$mappings[900001] = array( 'odp_category' => 'person_centered', 'credit_hours' => 2, 'is_orientation' => 1 );
$fixture['meta']['course_completed_900001'] = time();
$fixture['external'] = array( array( 'training_id' => 900051, 'revision_id' => 900052, 'snapshot_digest' => 'synthetic', 'manifest' => array( array( 'file_index' => 0 ) ), 'snapshot' => array( 'catalog_id' => 900050, 'title' => 'Synthetic external', 'category' => 'odp_annual_training', 'credit_hours' => 1.5, 'completion_date' => gmdate( 'Y-m-d' ), 'applies_to_orientation' => true ) ) );
$annual = calculate();
check( 3.5 == $annual['total_hrs'] && 2 === count( $annual['agency_selected_courses'] ), 'Selection summarizes without adding credit' );
check( 2 == $annual['agency_selected_courses'][0]['hours'] && 1.5 == $annual['agency_selected_courses'][1]['hours'], 'LearnDash and external catalog matched independently' );
$html = summary_html( $annual );
check( strpos( $html, 'Agency Mandated Training/compliance' ) > strpos( $html, 'Implementation of Individual Plan' ), 'Selected rows follow ODP topics under requested heading' );
check( false === strpos( $html, 'hours already counted in totals' ) && false === strpos( $html, 'Evidence included' ), 'Requested presentation text removed without changing credit' );
check( false === strpos( $html, 'LearnDash · ' ) && false === strpos( $html, 'External · ' ), 'Packet titles omit administrator source prefixes' );
$orientation = calculate( 'orientation' );
check( 1 === count( $orientation['agency_selected_courses'] ) && 1.5 == $orientation['agency_selected_courses'][0]['hours'], 'Orientation selection remains separate' );
$fixture['external'][0]['snapshot']['applies_to_orientation'] = false;
check( ! calculate( 'orientation' )['agency_selected_courses'][0]['included'], 'Selection cannot bypass orientation approval' );
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => 900001, 'name' => 'Synthetic' ), 'annual', $mappings, array( 'start' => '2000-01-01', 'end' => '2000-01-02' ) );
check( 0 == $report['total_hrs'] && ! $report['agency_selected_courses'][0]['included'] && ! $report['agency_selected_courses'][1]['included'], 'Selected custom period excludes later evidence' );
check( 'reporting_only' === $report['annual_status'] && false === strpos( summary_html( $report ), 'Selected reporting dates only.' ), 'Paragraph removal does not turn report into compliance assessment' );
$fixture['options'][GHCA_Packet_Course_Selection::OPTION]['annual']['learndash:900001'] = '<script>synthetic</script>';
check( false === strpos( summary_html( calculate() ), '<script>synthetic</script>' ), 'Selected label escaped' );
$fixture['options'][GHCA_Packet_Course_Selection::OPTION] = array();
check( array() === calculate()['agency_selected_courses'] && false === strpos( summary_html( calculate() ), 'Agency Mandated Training/compliance' ), 'No selections preserves original matrix' );
echo "Packet course selection passed; $checks cumulative checks.\n";
