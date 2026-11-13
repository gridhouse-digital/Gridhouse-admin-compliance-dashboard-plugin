<?php
/**
 * Real TCPDF/FPDI check of the Annual and Orientation packet print layout (design board "Print: Orientation audit
 * packet"). Builds both packets for a synthetic employee from synthetic audit data, appends a synthetic certificate
 * the way the merge does, writes the PDF to a temporary file and reads its text back with pdftotext.
 *
 * Runs only inside a disposable WordPress install (GHCA_DISPOSABLE_TEST_SITE, database "ghca_test*") with TCPDF at
 * the LearnDash path and pdftotext/pdfinfo on PATH. Skips otherwise. Nothing is sent; no real record is read.
 *
 *   php tests/integration-packet-layout.php [png-dir]   (png-dir: also render each page to PNG for review)
 */
$wp_root = dirname( __DIR__, 4 );
$png_dir = (string) ( $argv[1] ?? '' );
if ( ! is_file( $wp_root . '/wp-load.php' ) || '' === trim( (string) shell_exec( 'command -v pdftotext pdfinfo 2>/dev/null' ) ) ) {
	echo "SKIP: needs a WordPress install and pdftotext/pdfinfo\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_Audit_PDF' ) || ! is_file( WP_PLUGIN_DIR . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php' ) ) {
	echo "SKIP: not a disposable ghca_test site with the plugin active and TCPDF at the LearnDash path\n";
	exit( 0 );
}
$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	echo ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n";
	if ( ! $ok ) { ++$failed; }
}
GHCA_Audit_PDF::load_libs();
require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam

// Synthetic people only.
$admin = get_user_by( 'login', 'synthetic-admin' );
wp_set_current_user( $admin->ID );
$emp = get_user_by( 'login', 'synthetic-packet-layout' );
if ( ! $emp ) {
	$id  = wp_insert_user( array( 'user_login' => 'synthetic-packet-layout', 'user_email' => 'packet.layout@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => 'Synthetic', 'last_name' => 'Layout' ) );
	$emp = get_user_by( 'id', $id );
}
update_user_meta( $emp->ID, GHCA_ACD_Employment_Record::META_KEY, array( 'date' => '2026-09-08', 'source' => 'Synthetic HR reference', 'verified_by' => $admin->ID, 'verified_at' => strtotime( '2026-09-09 15:00:00 UTC' ) ) );

// A synthetic one-page certificate, made with TCPDF itself.
$cert_path = wp_tempnam( 'ghca-layout-cert' );
$cert = new TCPDF( 'L', 'mm', 'LETTER' );
$cert->setPrintHeader( false ); $cert->setPrintFooter( false ); $cert->AddPage();
$cert->SetFont( 'helvetica', 'B', 24 ); $cert->Cell( 0, 20, 'Synthetic certificate of completion', 0, 1, 'C' );
$cert->Output( $cert_path, 'F' );

$ts = static function ( string $date ): int { return (int) strtotime( $date . ' 12:00:00 UTC' ); };
$course = static function ( int $id, string $title, string $category, string $date, float $hours, string $source = 'learndash' ) use ( $ts ): array {
	return array( 'title' => $title, 'date' => $ts( $date ), 'credit_hours' => $hours, 'source' => $source, 'provider' => 'external' === $source ? 'Synthetic Provider' : '', 'course_key' => ( 'external' === $source ? 'external:' : 'learndash:' ) . $id, 'course_id' => $id, 'category' => $category, 'categories' => array( $category ), 'entry_method' => '', 'description' => 'Synthetic description ' . $id, 'full_description' => '' );
};
$base = array(
	'first_name' => 'Synthetic', 'last_name' => 'Layout', 'role' => 'Synthetic Direct Support Professional', 'doh' => '09/08/2026',
	'first_service_date' => '2026-10-01', 'worked_alone_date' => '2026-10-01', 'audit_period' => array(), 'annual_cycle' => 'employee_start_date',
	'category_notes' => array(), 'external_isp_evidence' => false, 'agency_training' => array(), 'communication_manual_delivery' => false, 'isp_manual_delivery' => false,
	'agency_selected_courses' => array(), 'evidence_issues' => array(), 'new_hire_program' => array( 'active' => false ),
);
$orientation = array_merge( $base, array(
	'start_date' => '', 'end_date' => '', 'worked_alone_compliant' => 'Yes', 'annual_status' => 'in_progress', 'completion_date' => '09/29/2026', 'completed_within_30' => 'Yes',
	'category_statuses' => array( 'person_centered' => 'Completed', 'abuse_prevention' => 'Completed', 'individual_rights' => 'Completed', 'reporting_incidents' => 'Completed', 'behavior_supports' => 'Needs review', 'individual_plan' => 'Needs review', 'job_related' => 'Completed' ),
	'requirement_hours' => array( 'person_centered' => array( 'achieved' => 1, 'date' => $ts( '2026-09-25' ) ), 'abuse_prevention' => array( 'achieved' => 2, 'date' => $ts( '2026-09-18' ) ), 'individual_rights' => array( 'achieved' => 2.8333, 'date' => $ts( '2026-09-29' ) ), 'reporting_incidents' => array( 'achieved' => 5.3333, 'date' => $ts( '2026-09-22' ) ), 'job_related' => array( 'achieved' => 0.8667, 'date' => $ts( '2026-09-09' ) ) ),
	'total_annual_hrs' => 12, 'additional_hrs' => 0, 'total_hrs' => 12,
	'raw_completed_courses' => array(
		$course( 900101, 'Synthetic New Hire Orientation', 'job_related', '2026-09-09', 0.8667 ),
		$course( 900102, 'Synthetic Individual Rights', 'individual_rights', '2026-09-29', 2.8333 ),
		$course( 900103, 'Synthetic Reporting Incidents', 'reporting_incidents', '2026-09-22', 5.3333 ),
		$course( 900104, 'Synthetic Abuse Prevention', 'abuse_prevention', '2026-09-18', 2 ),
		$course( 900105, 'Synthetic Person-Centered Practices', 'person_centered', '2026-09-25', 1, 'external' ),
	),
) );
$annual = array_merge( $base, array(
	'start_date' => '09/08/2026', 'end_date' => '09/07/2027', 'worked_alone_compliant' => 'N/A', 'annual_status' => 'needs_review', 'completion_date' => '', 'completed_within_30' => 'No',
	'category_statuses' => array( 'person_centered' => 'Completed', 'abuse_prevention' => 'Completed', 'individual_rights' => 'Completed', 'reporting_incidents' => 'Missing training', 'behavior_supports' => 'Not applicable', 'individual_plan' => 'Needs review', 'job_related' => 'Needs review' ),
	'category_notes' => array( 'behavior_supports' => 'Synthetic duties exclude behavior supports' ),
	'requirement_hours' => array( 'person_centered' => array( 'achieved' => 6 ), 'abuse_prevention' => array( 'achieved' => 4 ), 'individual_rights' => array( 'achieved' => 3 ) ),
	'total_annual_hrs' => 13, 'additional_hrs' => 2.5, 'total_hrs' => 15.5,
	'raw_completed_courses' => array( $course( 900201, 'Synthetic Annual Person-Centered', 'person_centered', '2026-11-01', 6 ), $course( 900202, 'Synthetic Annual Abuse Prevention', 'abuse_prevention', '2026-11-02', 4 ), $course( 900203, 'Synthetic Annual Rights', 'individual_rights', '2026-11-03', 3 ) ),
) );

foreach ( array( 'orientation' => $orientation, 'annual' => $annual ) as $tracker => $data ) {
	// Certificates as the job would hold them: index => course key (one per course, plus one unmatched).
	$keys = array();
	foreach ( array_reverse( $data['raw_completed_courses'] ) as $c ) { $keys[] = $c['course_key']; }
	$attachments = GHCA_Audit_Packet_Layout::attachment_labels( $data['raw_completed_courses'], $keys, count( $keys ) );
	check( 'A-1' === $attachments[ count( $keys ) - 1 ]['label'] && 'A-' . count( $keys ) === $attachments[0]['label'], "$tracker: attachments are numbered in merge (course) order, not fetch order" );

	$pdf = GHCA_Audit_PDF::create_document( $data );
	$pdf->AddPage();
	GHCA_Audit_PDF::render_cover( $pdf, $data, $tracker, array( 'user_id' => $emp->ID, 'group' => 'Synthetic Group', 'attachments' => $attachments ) );
	$summary_pages = (int) $pdf->getPage();
	$total = $summary_pages;
	$label = array( $attachments[ count( $keys ) - 1 ]['label'], 'Synthetic Layout' );
	check( GHCA_Audit_PDF::append_certificate( $pdf, $cert_path, $total, $label ), "$tracker: a certificate is appended with its attachment label" );
	$out = wp_tempnam( 'ghca-layout-' . $tracker );
	$pdf->Output( $out, 'F' );
	$text  = (string) shell_exec( 'pdftotext -layout ' . escapeshellarg( $out ) . ' - 2>/dev/null' );
	$info  = (string) shell_exec( 'pdfinfo -f 1 -l 1 ' . escapeshellarg( $out ) . ' 2>/dev/null' );
	$pages = preg_split( "/\f/", $text );
	$p1    = $pages[0] ?? '';
	// Reading order (not columns) for phrases that wrap inside a cell.
	$flat  = preg_replace( '/\s+/u', ' ', (string) shell_exec( 'pdftotext ' . escapeshellarg( $out ) . ' - 2>/dev/null' ) );

	check( false !== strpos( $info, '612 x 792 pts' ), "$tracker: summary pages are US Letter" );
	check( false !== strpos( $p1, 'ODP ' . $tracker . ' audit packet' ) && false !== strpos( $p1, wp_specialchars_decode( GHCA_Dashboard_Branding::get_org_name(), ENT_QUOTES ) ), "$tracker: page 1 header names the agency and the packet" );
	check( 1 === preg_match( '/Generated [A-Z][a-z]{2} \d{1,2}, \d{4}, \d{1,2}:\d{2} [AP]M/', $p1 ) && false !== strpos( $p1, 'by ' . $admin->display_name . ' · page 1 of ' . $summary_pages ), "$tracker: page 1 header shows when, by whom and page 1 of $summary_pages" );
	for ( $n = 2; $n <= $summary_pages; $n++ ) {
		check( false !== strpos( $pages[ $n - 1 ] ?? '', 'page ' . $n . ' of ' . $summary_pages ) && false !== strpos( $pages[ $n - 1 ] ?? '', 'Synthetic Layout' ), "$tracker: page $n carries the running header (employee, page $n of $summary_pages)" );
	}
	check( false !== strpos( $p1, 'EMPLOYEE' ) && false !== strpos( $p1, 'Synthetic Layout' ) && false !== strpos( $p1, 'Synthetic Direct Support Professional · Group: Synthetic Group' ), "$tracker: employee block (name, role, group)" );
	check( false === strpos( $flat, 'Employee ID' ), "$tracker: no invented employee ID" );
	check( false !== strpos( $flat, "\u{2714} Completed" ), "$tracker: statuses print as a symbol plus the word" );
	check( false !== strpos( $flat, 'Colour is not needed to read this document.' ), "$tracker: status footnote" );
	check( false !== strpos( $flat, 'Course records' ) && false !== strpos( $flat, 'Gaps found' ) && false !== strpos( $flat, 'Attached certificates' ) && false !== strpos( $flat, 'Reviewed by (name, title)' ), "$tracker: course records, gaps, attachments and signature block" );
	check( false !== strpos( $flat, '(attachment A-1)' ) && false !== strpos( $pages[ $summary_pages ] ?? '', 'Attachment A-1 · Synthetic Layout' ), "$tracker: the certificate list cites A-1 and the appended page is stamped Attachment A-1" );

	if ( 'orientation' === $tracker ) {
		check( false !== strpos( $flat, 'Verified start' ) && false !== strpos( $flat, 'Sep 8, 2026' ) && false !== strpos( $flat, 'Checked by ' . $admin->display_name . ', Sep 9, 2026' ), 'orientation: verified start with who checked it and when' );
		check( 1 === preg_match( '/Days to complete.*21 of 30/s', $p1 ) || false !== strpos( $flat, '21 of 30' ), 'orientation: 21 of 30 days (Sep 8 to Sep 29)' );
		check( false !== strpos( $flat, "\u{2714} Within 30 days" ), 'orientation: 30-day verdict badge' );
		check( false !== strpos( $flat, 'Oct 1, 2026 — on/after orientation completion' ), 'orientation: worked-alone date and its result' );
		check( false !== strpos( $flat, 'Account registered (not used for audit)' ), 'orientation: account registration shown as not used for audit' );
		check( false !== strpos( $flat, 'Synthetic Reporting Incidents · A-' ) && false !== strpos( $flat, 'Synthetic Person-Centered Practices (outside training)' ), 'orientation: area evidence lists the courses with their attachment numbers' );
		check( false !== strpos( $flat, '5 h 20 m' ) && false !== strpos( $flat, '52 min' ), 'orientation: course time printed as hours and minutes' );
		check( false !== strpos( $flat, 'None. All orientation areas complete and dates verified.' ), 'orientation: no gaps when everything is met' );
		check( false === strpos( $flat, 'Behavior Supports' ), 'orientation: annual-only areas are not listed' );
	} else {
		check( false !== strpos( $flat, 'Annual window' ) && false !== strpos( $flat, 'Sep 8, 2026 – Sep 7, 2027' ) && false !== strpos( $flat, 'Annual Cycle Rule: Employee Hire Date' ), 'annual: window and cycle rule' );
		check( false !== strpos( $flat, '15.5 of 24' ) && false !== strpos( $flat, 'Overall annual status' ) && false !== strpos( $flat, 'Needs Review — Topic Coverage Unresolved' ), 'annual: hours against 24 and the overall status' );
		check( false !== strpos( $flat, "\u{2716} Missing training" ) && false !== strpos( $flat, "\u{2298} Not applicable" ) && false !== strpos( $flat, 'Synthetic duties exclude behavior supports' ), 'annual: missing and not-applicable areas print their own symbols and the recorded reason' );
		check( false !== strpos( $flat, 'Reporting Incidents: Missing training' ) && false !== strpos( $flat, 'Implementation of Individual Plan (ISP): Needs review' ) && false !== strpos( $flat, 'Overall annual status: Needs Review' ), 'annual: gaps list every open item' );
		check( false === strpos( $flat, 'None. All annual areas' ), 'annual: no "None" claim while items are open' );
		check( false !== strpos( $flat, 'Total recorded training hours:' ) && false !== strpos( $flat, '6100 topic training hours:' ), 'annual: hours breakdown kept' );
	}
	if ( $png_dir ) {
		wp_mkdir_p( $png_dir );
		shell_exec( 'pdftoppm -r 80 -png ' . escapeshellarg( $out ) . ' ' . escapeshellarg( $png_dir . '/' . $tracker ) );
		copy( $out, $png_dir . '/' . $tracker . '.pdf' );
	}
	unlink( $out );
}
unlink( $cert_path );
delete_user_meta( $emp->ID, GHCA_ACD_Employment_Record::META_KEY );

echo $failed ? "FAILED: $failed of $checks packet layout checks\n" : "PASS: $checks packet layout checks (WordPress + TCPDF/FPDI; synthetic data; nothing sent)\n";
exit( $failed ? 1 : 0 );
