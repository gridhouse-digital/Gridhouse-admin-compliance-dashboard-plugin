<?php
/**
 * Real WordPress + MySQL/MariaDB checks of slice 9's server paths: certificate ZIP building (per-item
 * re-authorization with a real scoped group leader, no partial archives, distinct file names, private 0600 storage
 * outside the web root), the single-use owner-bound ZIP download, expiry and cleanup, request validation, the
 * Certificates employee filter and the existing authorized certificate endpoint. PDF bytes are synthetic: LearnDash
 * is replaced by test stand-ins, so certificate fetching is injected; nothing is sent, no training record changes.
 *
 * Runs only on a disposable site (GHCA_DISPOSABLE_TEST_SITE, database ghca_test*) prepared by tests/real-site/setup.php.
 */
$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	echo "SKIP: needs a WordPress install\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_ACD_Redesign_Certs' ) || ! function_exists( 'ghca_rs_groups' ) ) {
	echo "SKIP: not a prepared disposable ghca_test site\n";
	exit( 0 );
}
add_filter( 'pre_wp_mail', '__return_false' );

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	fwrite( STDOUT, ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n" );
	if ( ! $ok ) { ++$failed; }
}
final class Ghca_S9_Stop extends Exception {}
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new Ghca_S9_Stop(); }; } );
function ajax( callable $handler, array $request ): array {
	$_POST = $_REQUEST = $request + array( 'nonce' => wp_create_nonce( 'ghca_acd_table' ) );
	ob_start();
	try { $handler(); } catch ( Ghca_S9_Stop $e ) {} // phpcs:ignore
	return (array) json_decode( (string) ob_get_clean(), true );
}
$pdf = static function ( string $tag ): string { return "%PDF-1.4\n% synthetic " . $tag . "\n1 0 obj << /Type /Page >> endobj\n%%EOF\n"; };

$admin  = get_user_by( 'login', 'synthetic-admin' );
$viewer = get_user_by( 'login', 'synthetic-viewer' );
if ( ! $viewer ) { $viewer = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-viewer', 'user_email' => 'viewer@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) ) ); }
$jordan = get_user_by( 'login', 'synthetic-jordan' );  // group A (DSP — New Hire)
$morgan = get_user_by( 'login', 'synthetic-morgan' );  // group B
$avery  = get_user_by( 'login', 'synthetic-avery' );   // group C
$groups = get_posts( array( 'post_type' => 'groups', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
// Two synthetic "courses" (pages; only their titles are used here), one with the same title to force a name clash.
$course = static function ( string $title ): int {
	$p = get_posts( array( 'post_type' => 'page', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1 ) );
	return $p ? (int) $p[0]->ID : (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => $title ) );
};
$c1 = $course( 'CPR/FIRST AID AND AED CERTIFICATION' );
$c2 = $course( 'FATAL FIVE' );
$fetch_ok = static function ( int $u, int $c ) use ( $pdf ): array { return array( 'status' => 200, 'body' => $pdf( $u . ':' . $c ) ); };
$dir = GHCA_Audit_PDF_Jobs::private_subdir( 'cert_zips' );
$zips = static function () use ( $dir ): int { return count( (array) glob( $dir . '/*.zip' ) ); };

// ---- Global admin: a ZIP of three, privately stored, single use.
wp_set_current_user( $admin->ID );
check( GHCA_ACD_Redesign_Certs::can_use() && GHCA_ACD_Redesign_Certs::zip_available(), 'setup: the admin may download certificates; PHP zip is available here' );
$items = GHCA_ACD_Redesign_Certs::parse_items( array( $jordan->ID . ':' . $c1, $morgan->ID . ':' . $c2, $avery->ID . ':' . $c1, $jordan->ID . ':' . $c1 ) );
check( 3 === count( $items ), 'repeated selections are counted once' );
$before = $zips();
$r = GHCA_ACD_Redesign_Certs::build_zip( $items, $admin->ID, $fetch_ok );
check( $r['ok'] && 3 === $r['count'] && $zips() === $before + 1, 'a ZIP of 3 is built' );
$job  = get_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r['token'] );
$path = (string) $job['path'];
clearstatcache();
check( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( ABSPATH ) ) && 0600 === ( fileperms( $path ) & 0777 ) && 0700 === ( fileperms( dirname( $path ) ) & 0777 ), 'stored outside the web root, file 0600, folder 0700 (' . dirname( $path ) . ')' );
$z = new ZipArchive();
$z->open( $path );
$names = array();
for ( $i = 0; $i < $z->numFiles; $i++ ) { $names[] = $z->getNameIndex( $i ); }
sort( $names );
check( array( 'avery-kim_cpr-first-aid-and-aed-certification.pdf', 'jordan-ellis_cpr-first-aid-and-aed-certification.pdf', 'morgan-lee_fatal-five.pdf' ) === $names, 'readable, distinct file names: employee and course in Title Case (' . implode( ', ', $names ) . ')' );
check( 0 === strpos( (string) $z->getFromName( 'jordan-ellis_cpr-first-aid-and-aed-certification.pdf' ), '%PDF-' ) && false !== strpos( (string) $z->getFromName( 'jordan-ellis_cpr-first-aid-and-aed-certification.pdf' ), $jordan->ID . ':' . $c1 ), 'each file holds that employee\'s own certificate bytes' );
$z->close();
check( 1 === preg_match( '/^certificates-\d{4}-\d{2}-\d{2}-\d{6}-3\.zip$/', (string) $r['name'] ), 'the archive name is dated and counts its files (' . $r['name'] . ')' );
// Two certificates with the same employee and course title get distinct names.
$c3 = $course( 'Fatal Five' );
$r2 = GHCA_ACD_Redesign_Certs::build_zip( array( array( $morgan->ID, $c2 ), array( $morgan->ID, $c3 ) ), $admin->ID, $fetch_ok );
$j2 = get_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r2['token'] );
$z->open( (string) $j2['path'] );
$n2 = array( $z->getNameIndex( 0 ), $z->getNameIndex( 1 ) );
$z->close();
sort( $n2 );
check( array( 'morgan-lee_fatal-five-2.pdf', 'morgan-lee_fatal-five.pdf' ) === $n2, 'same employee and course title: distinct names (' . implode( ', ', $n2 ) . ')' );
@unlink( (string) $j2['path'] ); delete_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r2['token'] ); // phpcs:ignore

// Download: owner only, nonce, single use.
wp_set_current_user( $viewer->ID );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 403 === $d['status'] && is_file( $path ), 'a user without document access is refused; the file stays for its owner' );
wp_set_current_user( $admin->ID );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => 'bad' ) );
check( 403 === $d['status'] && is_file( $path ), 'a bad nonce is refused' );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => 'not-a-token', 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 410 === $d['status'], 'a malformed token is answered as unavailable' );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 200 === $d['status'] && 'application/zip' === $d['headers']['Content-Type'] && 0 === strpos( $d['body'], 'PK' ) && false !== strpos( $d['headers']['Content-Disposition'], $r['name'] ), 'the owner downloads it (application/zip, attachment, its name)' );
check( ! is_file( $path ) && false === get_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r['token'] ), 'served once: file and record removed' );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 410 === $d['status'] && false !== strpos( $d['body'], 'no longer available' ), 'a second download is refused clearly' );
// Another admin cannot use someone else's ZIP.
$r3 = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ) ), $admin->ID, $fetch_ok );
$other = get_user_by( 'login', 'synthetic-admin2' );
if ( ! $other ) { $other = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-admin2', 'user_email' => 'admin2@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) ) ); }
wp_set_current_user( $other->ID );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r3['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
$j3 = get_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r3['token'] );
check( 410 === $d['status'] && is_array( $j3 ) && is_file( (string) $j3['path'] ), 'another administrator cannot download someone else\'s ZIP (answered as unavailable; the owner\'s copy stays)' );
// Expiry.
wp_set_current_user( $admin->ID );
$j3['created'] = time() - GHCA_ACD_Redesign_Certs::ZIP_TTL - 5;
set_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r3['token'], $j3, 60 );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r3['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 410 === $d['status'] && ! is_file( (string) $j3['path'] ), 'an expired ZIP is refused and deleted' );
// Sweep of abandoned files.
$old = $dir . '/' . str_repeat( 'b', 32 ) . '.zip';
file_put_contents( $old, 'PK' );
touch( $old, time() - GHCA_ACD_Redesign_Certs::ZIP_TTL - 60 );
check( GHCA_ACD_Redesign_Certs::sweep( $dir, time() ) >= 1 && ! is_file( $old ), 'abandoned ZIPs older than 15 minutes are swept when the next ZIP is built' );

// ---- Failures: no partial archive.
$before = $zips();
$r = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ), array( $morgan->ID, $c2 ) ), $admin->ID, static function ( int $u, int $c ) use ( $pdf, $morgan ): array { return $u === $morgan->ID ? array( 'status' => 404, 'body' => '' ) : array( 'status' => 200, 'body' => $pdf( 'x' ) ); } );
check( ! $r['ok'] && $zips() === $before && array( 'Morgan Lee — Fatal Five' ) === $r['failed'] && false !== strpos( $r['message'], 'Nothing was downloaded' ), 'one certificate cannot be fetched: no archive at all, and the message names it' );
$r = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ) ), $admin->ID, static function () { return array( 'status' => 200, 'body' => '<html>login</html>' ); } );
check( ! $r['ok'] && $zips() === $before, 'an answer that is not a PDF is a failure, not a file in the archive' );
$many = array();
for ( $i = 1; $i <= GHCA_ACD_Redesign_Certs::ZIP_MAX_ITEMS + 1; $i++ ) { $many[] = array( $jordan->ID, $i ); }
$r = GHCA_ACD_Redesign_Certs::build_zip( $many, $admin->ID, $fetch_ok );
check( ! $r['ok'] && false !== strpos( $r['message'], 'up to 20' ) && $zips() === $before, 'more than 20 certificates in one ZIP is refused before any fetch' );
check( null === GHCA_ACD_Redesign_Certs::parse_items( array( '1:2', 'x' ) ) && null === GHCA_ACD_Redesign_Certs::parse_items( '1:2' ) && null === GHCA_ACD_Redesign_Certs::parse_items( array( '0:5' ) ), 'malformed selections are rejected whole' );

// ---- A scoped group leader with document access: per-item re-authorization.
if ( ! get_role( 'group_leader' ) ) { add_role( 'group_leader', 'Group Leader', array( 'read' => true ) ); }
$leader = get_user_by( 'login', 'synthetic-leader' );
if ( ! $leader ) { $leader = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-leader', 'user_email' => 'leader@example.test', 'user_pass' => wp_generate_password(), 'role' => 'group_leader' ) ) ); }
update_user_meta( $leader->ID, 'ghca_rs_leads', array( (int) ghca_rs_groups( $jordan->ID )[0] ) );
update_option( GHCA_ACD_Settings::OPTION_PERM_VIEW_DOCUMENTS, (string) $leader->ID );
update_option( GHCA_ACD_Settings::OPTION_PERM_UNRESTRICTED_VIEW, '' );
wp_set_current_user( $leader->ID );
check( GHCA_ACD_Redesign_Certs::can_use() && GHCA_ACD_User_Report::can_view_user( $jordan->ID ) && ! GHCA_ACD_User_Report::can_view_user( $morgan->ID ), 'setup: the leader may download documents for their own group only' );
$calls = 0;
$counting = static function ( int $u, int $c ) use ( &$calls, $pdf ): array { ++$calls; return array( 'status' => 200, 'body' => $pdf( 'y' ) ); };
$before = $zips();
$r = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ), array( $morgan->ID, $c2 ) ), $leader->ID, $counting );
check( ! $r['ok'] && 0 === $calls && $zips() === $before, 'a selection with an out-of-scope certificate is refused before anything is fetched or written' );
check( false !== strpos( $r['message'], '1 selected certificate is not available to you' ) && false === strpos( $r['message'], 'Morgan' ) && false === strpos( $r['message'], 'Fatal' ), 'the refusal gives a count only: no employee or course name leaks' );
$r = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ) ), $leader->ID, $counting );
check( $r['ok'] && 1 === $calls, 'the leader can ZIP their own group\'s certificate' );
$j = get_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r['token'] );
@unlink( (string) $j['path'] ); delete_transient( GHCA_ACD_Redesign_Certs::ZIP_TRANSIENT . $r['token'] ); // phpcs:ignore
// The authorized single-certificate endpoint (preview and download) refuses the out-of-scope employee too.
$p = GHCA_ACD_Certificate_Download::process( array( 'user_id' => $morgan->ID, 'course_id' => $c2, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 403 === $p['status'] && 0 !== strpos( $p['body'], '%PDF' ), 'preview/download endpoint: an out-of-scope employee is refused (403)' );
$p = GHCA_ACD_Certificate_Download::process( array( 'user_id' => $jordan->ID, 'course_id' => $c1, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 404 === $p['status'], 'preview/download endpoint: in scope but no LearnDash certificate (stand-ins) is "unavailable" (404), never a file' );
// Certificates employee filter.
$_GET = array( 'ghca_cert_user' => (string) $morgan->ID );
check( 0 === GHCA_ACD_Redesign_Lists::certificate_filters()['user'], 'Certificates: an employee filter for someone outside the viewer\'s scope is ignored' );
$_GET = array( 'ghca_cert_user' => (string) $jordan->ID );
check( $jordan->ID === GHCA_ACD_Redesign_Lists::certificate_filters()['user'], 'Certificates: an in-scope employee filter is kept' );
$_GET = array();
update_option( GHCA_ACD_Settings::OPTION_PERM_VIEW_DOCUMENTS, '' );

// ---- AJAX entry: permissions and malformed requests.
wp_set_current_user( $viewer->ID );
$a = ajax( array( 'GHCA_ACD_Redesign_Certs', 'ajax_zip' ), array( 'items' => array( $jordan->ID . ':' . $c1 ) ) );
check( empty( $a['success'] ) && false !== strpos( (string) $a['data']['message'], 'permission' ), 'AJAX: a user without document access cannot request a ZIP' );
wp_set_current_user( $admin->ID );
$a = ajax( array( 'GHCA_ACD_Redesign_Certs', 'ajax_zip' ), array( 'items' => 'nonsense' ) );
check( empty( $a['success'] ) && false !== strpos( (string) $a['data']['message'], 'could not be read' ), 'AJAX: a malformed selection is refused' );
$a = ajax( array( 'GHCA_ACD_Redesign_Certs', 'ajax_zip' ), array( 'items' => array( $jordan->ID . ':' . $c1 ) ) );
check( empty( $a['success'] ) && false !== strpos( (string) $a['data']['message'], 'could not be fetched' ), 'AJAX with the real fetch: without a LearnDash certificate there is no ZIP, and it says which one' );

fwrite( STDOUT, $failed ? "FAILED: $failed of $checks slice 9 real checks\n" : "PASS: $checks slice 9 real checks (WordPress + MariaDB; synthetic users and PDF bytes; nothing sent)\n" );
exit( $failed ? 1 : 0 );
