<?php
/**
 * Real WordPress + MySQL/MariaDB check of the packet "ready" result (slice 7): the plugin's own init, merge and
 * download AJAX handlers build a real packet with TCPDF and FPDI for a synthetic employee, and the merge result's
 * page count, flagged items and expiry are compared with the stored packet and its job. The download is checked
 * for authorization, single use, expiry and replacement. Nothing is sent and no training record is changed.
 *
 * Runs only inside a disposable WordPress install whose wp-config.php defines GHCA_DISPOSABLE_TEST_SITE and whose
 * database name starts with "ghca_test", with TCPDF at the LearnDash path the plugin loads it from
 * (wp-content/plugins/sfwd-lms/includes/lib/tcpdf/tcpdf.php). Skips otherwise.
 */

$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) || ! function_exists( 'pcntl_fork' ) ) {
	echo "SKIP: needs a WordPress install and pcntl\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_Audit_PDF' ) || ! is_file( WP_PLUGIN_DIR . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php' ) ) {
	echo "SKIP: not a disposable ghca_test site with the plugin active and TCPDF at the LearnDash path\n";
	exit( 0 );
}
// LearnDash is not installed: these stand-ins give the employee no courses. WordPress, the database, the job
// store, TCPDF, FPDI, the handlers and the private packet files are real.
if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
	function learndash_user_get_enrolled_courses( $user_id = 0, $args = array(), $bypass = false ) { return array(); }
	function learndash_course_completed( $user_id, $course_id ) { return false; }
	function learndash_course_progress( $args = array() ) { return array(); }
	function learndash_course_status( $course_id, $user_id = null ) { return ''; }
	function learndash_get_course_certificate_link( $course_id, $user_id = null ) { return ''; }
	function learndash_get_groups_user_ids( $group_id ) { return array(); }
	function learndash_is_user_in_group( $user_id, $group_id ) { return false; }
	function learndash_user_get_course_progress( $user_id = 0, $course_id = 0, $type = 'summary' ) { return array(); }
	function learndash_get_users_group_ids( $user_id = 0, $bypass = false ) { return array(); }
}
add_filter( 'pre_wp_mail', '__return_false' );

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	fwrite( STDOUT, ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n" ); // STDOUT, not echo: the download handler must still be able to send headers
	if ( ! $ok ) { ++$failed; }
}

final class Ghca_Ajax_Stop extends Exception {}
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new Ghca_Ajax_Stop(); }; } );

/** Runs a plugin AJAX handler in this process and returns its decoded JSON. */
function ajax( string $method, array $request ): array {
	$_POST = $_REQUEST = $_GET = $request + array( 'nonce' => wp_create_nonce( 'ghca_acd_table' ) );
	ob_start();
	try { GHCA_Audit_PDF::$method(); } catch ( Ghca_Ajax_Stop $e ) {} // phpcs:ignore
	return (array) json_decode( (string) ob_get_clean(), true );
}
/** Runs the download handler (it streams and exits) in a forked child; returns what it wrote. */
function download( string $url ): string {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	$pid  = pcntl_fork();
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		$sock = $pair[1];
		// The child gets its own database connection; the parent's socket is kept open and never quit from here,
		// and the child ends with SIGKILL so no shutdown code touches the shared connection.
		global $wpdb;
		$GLOBALS['ghca_parent_dbh'] = $wpdb->dbh;
		$wpdb->dbh = null;
		$wpdb->db_connect( false );
		wp_cache_flush();
		$_POST = $_REQUEST = $_GET = $q;
		register_shutdown_function( static function () use ( $sock ) {
			while ( ob_get_level() > 0 ) { ob_end_flush(); }
			fflush( $sock );
			posix_kill( getmypid(), SIGKILL );
		} );
		ob_start( static function ( $buf ) use ( $sock ) { fwrite( $sock, $buf ); return ''; } );
		try { GHCA_Audit_PDF::ajax_download(); } catch ( Ghca_Ajax_Stop $e ) {} // phpcs:ignore
		exit( 0 );
	}
	fclose( $pair[1] );
	$out = (string) stream_get_contents( $pair[0] );
	fclose( $pair[0] );
	pcntl_waitpid( $pid, $status );
	wp_cache_flush(); // the child changed the database; drop this process's cached options and transients
	return $out;
}
function pdf_pages( string $path ): int {
	GHCA_Audit_PDF::load_libs();
	$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
	return (int) $pdf->setSourceFile( $path );
}

$admin = get_user_by( 'login', 'synthetic-admin' );
$emp   = get_user_by( 'login', 'synthetic-employee' );
if ( ! $emp ) {
	$id  = wp_insert_user( array( 'user_login' => 'synthetic-employee', 'user_email' => 'synthetic.employee@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => 'Synthetic' ) );
	$emp = get_user_by( 'id', $id );
}
wp_set_current_user( $admin->ID );
fwrite( STDERR, '# WordPress ' . get_bloginfo( 'version' ) . ', ' . $GLOBALS['wpdb']->db_server_info() . "\n" ); // stderr: keeps headers unsent

foreach ( array( 'annual', 'orientation' ) as $tracker ) {
	$init = ajax( 'ajax_init_job', array( 'user_id' => $emp->ID, 'tracker' => $tracker ) );
	check( ! empty( $init['success'] ) && ! empty( $init['data']['job_id'] ), "$tracker: job started (" . ( $init['data']['message'] ?? 'ok' ) . ')' );
	if ( empty( $init['success'] ) ) { continue; }
	$job_id = $init['data']['job_id'];
	$t0     = time();
	$merge  = ajax( 'ajax_merge', array( 'job_id' => $job_id ) );
	$d      = $merge['data'] ?? array();
	check( ! empty( $merge['success'] ) && ! empty( $d['download_url'] ), "$tracker: merged (" . ( $d['message'] ?? 'ok' ) . ')' );
	if ( empty( $merge['success'] ) ) { continue; }
	$path    = GHCA_Audit_PDF_Jobs::packet_path( $job_id );
	$job     = get_transient( GHCA_Audit_PDF_Jobs::TRANSIENT_PREFIX . $job_id );
	$audit   = GHCA_Audit_PDF::resolve_audit_context( $emp->ID, $tracker );
	$issues  = count( (array) ( $audit['audit_data']['evidence_issues'] ?? array() ) );
	check( is_file( $path ) && pdf_pages( $path ) === $d['pages'] && $d['pages'] > 0, "$tracker: reported pages ({$d['pages']}) equal the stored packet's page count" );
	check( $issues === $d['flagged'], "$tracker: reported flagged items ({$d['flagged']}) equal the packet's evidence-review list" );
	check( (int) $job['created'] + GHCA_Audit_PDF_Jobs::TTL === $d['expires'] && $d['expires'] >= $t0 + 3500, "$tracker: reported expiry is the job's created time plus its TTL (" . gmdate( 'H:i:s', (int) $d['expires'] ) . ' UTC)' );
	check( $tracker === $d['tracker'], "$tracker: packet type reported" );
	$pdir = wp_normalize_path( dirname( $path ) );
	$web  = wp_normalize_path( ABSPATH );
	check( 0 !== strpos( $pdir, $web ) || is_file( $pdir . '/.htaccess' ), "$tracker: packet stored outside the web root, or in a directory with deny rules ($pdir)" );
	check( false === strpos( (string) $d['download_url'], content_url() ) && false !== strpos( (string) $d['download_url'], 'action=ghca_acd_pdf_download' ), "$tracker: the link is the authorized admin-ajax endpoint, not a public file URL" );
	check( 0600 === ( fileperms( $path ) & 0777 ) || 0640 === ( fileperms( $path ) & 0777 ), "$tracker: the packet file is private (" . decoct( fileperms( $path ) & 0777 ) . ')' );

	// Another user without document access cannot use the link.
	$other = get_user_by( 'login', 'synthetic-viewer' );
	if ( ! $other ) { $oid = wp_insert_user( array( 'user_login' => 'synthetic-viewer', 'user_email' => 'viewer@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) ); $other = get_user_by( 'id', $oid ); }
	wp_set_current_user( $other->ID );
	$denied = download( add_query_arg( 'nonce', wp_create_nonce( 'ghca_acd_table' ), $d['download_url'] ) );
	wp_set_current_user( $admin->ID );
	check( 0 !== strpos( $denied, '%PDF-' ) && false !== strpos( $denied, 'Permission denied' ), "$tracker: another user without document access is refused; no PDF bytes" );

	$first = download( $d['download_url'] );
	check( 0 === strpos( $first, '%PDF-' ) && strlen( $first ) > 500, "$tracker: the authorized link returns the PDF (" . strlen( $first ) . ' bytes)' );
	clearstatcache();
	check( ! is_file( $path ), "$tracker: the private copy is deleted after the download" );
	$second = download( $d['download_url'] );
	check( 0 !== strpos( $second, '%PDF-' ) && false !== strpos( $second, 'no longer available' ), "$tracker: the link is single-use; a second download is refused with a clear message (" . substr( wp_strip_all_tags( $second ), 0, 90 ) . ')' );
}

// Expiry: a job past its TTL cannot be downloaded.
$init = ajax( 'ajax_init_job', array( 'user_id' => $emp->ID, 'tracker' => 'annual' ) );
$job_id = $init['data']['job_id'];
$merge  = ajax( 'ajax_merge', array( 'job_id' => $job_id ) );
$key    = GHCA_Audit_PDF_Jobs::TRANSIENT_PREFIX . $job_id;
$job    = get_transient( $key );
$job['created'] = time() - GHCA_Audit_PDF_Jobs::TTL - 5;
set_transient( $key, $job, 60 );
$expired = download( $merge['data']['download_url'] );
check( 0 !== strpos( $expired, '%PDF-' ) && false !== strpos( $expired, 'no longer available' ), 'an expired link is refused; no PDF bytes' );

// Replacement: starting a second packet removes the first (one packet per person at a time).
$a = ajax( 'ajax_init_job', array( 'user_id' => $emp->ID, 'tracker' => 'annual' ) );
$am = ajax( 'ajax_merge', array( 'job_id' => $a['data']['job_id'] ) );
$b = ajax( 'ajax_init_job', array( 'user_id' => $emp->ID, 'tracker' => 'orientation' ) );
$bm = ajax( 'ajax_merge', array( 'job_id' => $b['data']['job_id'] ) );
check( false !== strpos( download( $am['data']['download_url'] ), 'no longer available' ) && 0 === strpos( download( $bm['data']['download_url'] ), '%PDF-' ), 'a newer packet replaces the earlier one: the earlier link is refused, the newer one works' );

// Repeated merge of the same job is refused (the job lock and the "already merged" guard).
$c  = ajax( 'ajax_init_job', array( 'user_id' => $emp->ID, 'tracker' => 'annual' ) );
ajax( 'ajax_merge', array( 'job_id' => $c['data']['job_id'] ) );
$again = ajax( 'ajax_merge', array( 'job_id' => $c['data']['job_id'] ) );
check( empty( $again['success'] ) && false !== strpos( (string) ( $again['data']['message'] ?? '' ), 'already merged' ), 'merging the same job twice is refused' );
GHCA_Audit_PDF_Jobs::delete_job( $c['data']['job_id'] );

echo $failed ? "FAILED: $failed of $checks real packet checks\n" : "PASS: $checks real packet checks (WordPress + MariaDB + TCPDF/FPDI; synthetic employee; nothing sent)\n";
exit( $failed ? 1 : 0 );
