<?php
// Synthetic fixtures only. No WordPress, external requests or employee records.
require __DIR__ . '/test-manual-isp-summary.php';
reset_fixture();
$employee = array( 'user_id' => 900001, 'name' => 'Synthetic Employee' );
$period = array( 'start' => '2025-09-01', 'end' => '2026-08-31' );
$mappings = array();
$fixture['meta']['ghca_acd_verified_employment']['date'] = '2025-08-23';
foreach ( array( '2025-08-31 23:59:59', '2025-09-01 00:00:00', '2026-07-15 12:00:00', '2026-08-31 23:59:59', '2026-09-01 00:00:00' ) as $i => $date ) {
    $id = 900001 + $i;
    $mappings[$id] = array( 'odp_category' => 'person_centered', 'credit_hours' => 2 );
    $fixture['meta']['course_completed_' . $id] = strtotime( $date );
}
$before = $fixture;
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'annual', $mappings, $period );
check( 6 == $report['total_hrs'], 'July and both inclusive boundaries count, adjacent dates do not' );
check( array( 900002, 900003, 900004 ) === array_column( $report['raw_completed_courses'], 'course_id' ), 'Certificate inputs use precisely the selected period' );
check( $fixture === $before, 'Reporting never changes records or settings' );
check( 'reporting_only' === $report['annual_status'] && $period === $report['audit_period'], 'Reporting cannot assert annual compliance' );
check( '09/01/2025' === $report['start_date'] && '08/31/2026' === $report['end_date'], 'Displayed period is inclusive' );
check( 'Recorded in period' === $report['category_statuses']['person_centered'], 'Topic reports evidence, not compliance' );
check( 'No completion recorded in period' === $report['category_statuses']['individual_plan'], 'Missing records are disclosed without invented compliance' );
$html = summary_html( $report );
check( false !== strpos( $html, 'Audit Reporting Period' ) && false !== strpos( $html, '09/01/2025' ), 'Cover identifies selected range' );
check( false === strpos( $html, 'Overall Annual Status:' ) && false === strpos( $html, '/ 24 Required' ), 'Arbitrary range does not get annual verdict or threshold' );
check( false === strpos( $html, 'not a frozen historical archive' ) && false === strpos( $html, 'Selected reporting dates only.' ) && false === strpos( $html, 'Employee applicability decisions from the current annual cycle' ), 'Client-requested explanatory paragraph omitted; reporting semantics unchanged' );
check( false === strpos( $html, 'Topic coverage reflects mapped evidence' ), 'Custom report omits requested topic-coverage paragraph' );
$legacy = calculate();
foreach ( array( $report, $legacy ) as $hours_data ) {
    foreach ( array( array( 6.5, 2.25, 8.75 ), array( 0, 0, 0 ) ) as $totals ) {
        $hours_data['total_annual_hrs'] = $totals[0];
        $hours_data['additional_hrs'] = $totals[1];
        $hours_data['total_hrs'] = $totals[2];
        $hours_html = summary_html( $hours_data );
        foreach ( array( '6100 topic training hours:', 'Additional training hours:', 'Total recorded training hours:' ) as $index => $label ) {
            check( 1 === preg_match( '/<strong>' . preg_quote( $label, '/' ) . '<\/strong><\/td>\s*<td[^>]*>(?:<strong>)?' . preg_quote( (string) $totals[$index], '/' ) . '(?:<\/strong>)?<\/td>/', $hours_html ), 'Both period modes show exact label and recorded hours: ' . $label );
        }
        check( false === strpos( $hours_html, '/ 24 Required' ), 'Recorded total is displayed without a threshold suffix' );
    }
}
check( false !== strpos( summary_html( $legacy ), 'Overall Annual Status:' ), 'Default-cycle annual assessment remains unchanged' );
check( false === strpos( summary_html( $legacy, 'orientation' ), '6100 topic training hours:' ), 'Orientation keeps its own summary' );
check( empty( $legacy['audit_period'] ) && 'reporting_only' !== $legacy['annual_status'], 'Default annual calculation remains separate' );
foreach ( array( array(), array( 'start' => '2025-09-01' ), array( 'start' => array(), 'end' => '2026-08-31' ), array( 'start' => '2026-02-30', 'end' => '2026-08-31' ), array( 'start' => '2026-09-01', 'end' => '2026-08-31' ), array( 'start' => 'today', 'end' => '2026-08-31' ), array( 'start' => "2025-09-01\0", 'end' => '2026-08-31' ) ) as $bad ) {
    try { GHCA_Audit_Calculator::reporting_window( $bad ); check( false, 'Invalid range accepted' ); }
    catch ( InvalidArgumentException $e ) { check( true, 'Invalid range rejected' ); }
}
try { GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'orientation', $mappings, $period ); check( false, 'Orientation accepted period' ); }
catch ( InvalidArgumentException $e ) { check( true, 'Orientation rejects period' ); }
$day = GHCA_Audit_Calculator::reporting_window( array( 'start' => '2024-02-29', 'end' => '2024-02-29' ) );
check( DAY_IN_SECONDS === $day['end_ts'] - $day['start_ts'], 'One-day leap-date range valid' );
unset( $fixture['meta']['ghca_acd_verified_employment'], $fixture['options']['ghca_acd_annual_cycle'] );
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'annual', $mappings, $period );
check( 6 == $report['total_hrs'] && empty( $report['evidence_issues'] ), 'Explicit range does not invent or need a hire date or policy' );
$fixture['meta']['course_completed_900003'] = 'invalid';
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'annual', $mappings, $period );
check( 4 == $report['total_hrs'] && count( $report['evidence_issues'] ) === 1, 'Invalid completion evidence still excluded and disclosed' );
$fixture['external'] = array( array( 'revision_id' => 900010, 'training_id' => 900011, 'snapshot_digest' => 'synthetic', 'manifest' => array(), 'snapshot' => array( 'completion_date' => '2026-08-31', 'category' => 'general', 'credit_hours' => 3, 'title' => 'Synthetic external course' ) ) );
$report = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'annual', $mappings, $period );
check( 7 == $report['total_hrs'] && in_array( 'external:900010', array_column( $report['raw_completed_courses'], 'course_key' ), true ), 'Approved local external snapshot counts on last day' );
echo "Reporting-period checks passed; $checks cumulative checks.\n";

// Exercise the actual init/merge guards and manifest with in-memory WP transients.
class ReportingResponse extends RuntimeException {
    public $data; public $success; public $status;
    public function __construct( $success, $data, $status ) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_error( $data, $status = 200 ) { throw new ReportingResponse( false, $data, $status ); }
function wp_send_json_success( $data, $status = 200 ) { throw new ReportingResponse( true, $data, $status ); }
function check_ajax_referer( $action, $field ) { if ( ! $GLOBALS['report_nonce'] ) { wp_send_json_error( array(), 403 ); } }
function is_user_logged_in() { return true; }
function sanitize_title( $value ) { return strtolower( str_replace( ' ', '-', $value ) ); }
function get_transient( $key ) { return $GLOBALS['report_transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['report_transients'][$key] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['report_transients'][$key] ); return true; }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function learndash_get_course_certificate_link( $course, $user ) { return 'https://synthetic.invalid/certificate/' . $course; }
class GHCA_ACD_Roles {
    public static function user_can_view() { return $GLOBALS['report_role']; }
    public static function user_can_view_employee_documents() { return $GLOBALS['report_documents']; }
    public static function user_can_edit_records() { return $GLOBALS['report_edit'] ?? false; }
}
class GHCA_ACD_User_Report { public static function can_view_user( $id ) { return $GLOBALS['report_scope'] && 900001 === $id; } }
class GHCA_ACD_Data_Provider {
    public static function get_employees_for_current_view() { return array( array( 'user_id' => 900001, 'name' => 'Synthetic Employee' ) ); }
    public static function sanitize_certificate_url( $url ) { return $url; }
}
class TCPDF {}
$test_dir = sys_get_temp_dir() . '/ghca-report-test-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_dir, 0700 );
define( 'GHCA_ACD_PRIVATE_DIR', $test_dir );
define( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED', true );
define( 'HOUR_IN_SECONDS', 3600 );
require __DIR__ . '/../includes/class-audit-pdf-jobs.php';
function report_request( $post, $method = 'ajax_init_job' ) {
    $_POST = $_REQUEST = $post;
    try { GHCA_Audit_PDF::$method(); }
    catch ( ReportingResponse $response ) { return $response; }
    throw new RuntimeException( 'Expected AJAX response' );
}
$report_nonce = $report_role = $report_scope = $report_documents = true;
$post = array( 'user_id' => 900001, 'tracker' => 'annual', 'audit_start' => $period['start'], 'audit_end' => $period['end'] );
foreach ( array( 'report_nonce', 'report_role', 'report_documents', 'report_scope' ) as $guard ) {
    $GLOBALS[$guard] = false;
    check( 403 === report_request( $post )->status, 'Existing guard preserved: ' . $guard );
    $GLOBALS[$guard] = true;
}
foreach ( array( array( 'audit_start' => array( '2025-09-01' ) ), array( 'audit_end' => '' ), array( 'audit_end' => '2024-08-31' ), array( 'tracker' => 'orientation' ) ) as $invalid ) {
    check( 400 === report_request( array_merge( $post, $invalid ) )->status, 'API rejects malformed or unsupported period before job creation' );
}
reset_fixture();
$fixture['options']['ghca_acd_audit_mapping'] = $mappings;
$fixture['meta']['course_completed_900003'] = strtotime( '2026-07-15' );
$response = report_request( $post );
check( $response->success && 1 === $response->data['total'], 'API creates job with selected July certificate' );
$job_id = $response->data['job_id'];
$job = GHCA_Audit_PDF_Jobs::get_job( $job_id, 90 );
check( $period === $job['report_snapshot']['audit_period'] && array( 900003 ) === $job['course_ids'], 'Job binds server-calculated period and matching certificates' );
check( false !== strpos( $job['filename'], '2025-09-01_to_2026-08-31' ), 'Filename distinguishes reporting period' );
check( is_wp_error( GHCA_Audit_PDF_Jobs::get_job( $job_id, 91 ) ), 'Other owner cannot access reporting job' );
$current = GHCA_Audit_PDF::resolve_audit_context( 900001, 'annual', $job['report_snapshot']['audit_period'] );
check( $current['audit_data'] === $job['report_snapshot'], 'Unchanged calculation matches bound snapshot' );
$fixture['meta']['course_completed_900003'] = strtotime( '2026-09-02' );
$response = report_request( array( 'job_id' => $job_id, 'audit_start' => '2026-09-01', 'audit_end' => '2026-09-30' ), 'ajax_merge' );
check( ! $response->success && 409 === $response->status, 'Merge rejects changed records and ignores client replacement range' );
check( is_wp_error( GHCA_Audit_PDF_Jobs::get_job( $job_id, 90 ) ), 'Changed-evidence job is deleted, no partial packet' );
echo "Reporting API checks passed; $checks cumulative checks.\n";
