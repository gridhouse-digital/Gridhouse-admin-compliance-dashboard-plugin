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
// Archives that fail in the ways ZipArchive can: a refused entry, a refused close, a silently missing entry, wrong bytes.
class Ghca_S9_Zip_FailAdd extends ZipArchive {
	private int $n = 0;
	public function addFromString( string $name, string $content, int $flags = ZipArchive::FL_OVERWRITE ): bool { return 2 === ++$this->n ? false : parent::addFromString( $name, $content, $flags ); }
}
class Ghca_S9_Zip_FailClose extends ZipArchive {
	public function close(): bool { parent::close(); return false; }
}
class Ghca_S9_Zip_Drop extends ZipArchive {
	private int $n = 0;
	public function addFromString( string $name, string $content, int $flags = ZipArchive::FL_OVERWRITE ): bool { return 2 === ++$this->n ? true : parent::addFromString( $name, $content, $flags ); }
}
class Ghca_S9_Zip_Tamper extends ZipArchive {
	public function addFromString( string $name, string $content, int $flags = ZipArchive::FL_OVERWRITE ): bool { return parent::addFromString( $name, $content . ' ', $flags ); }
}
/** Runs $fn in $n forked processes that each open their own database connection and start at the same instant. */
function race( int $n, callable $fn ): array {
	global $wpdb;
	$start = microtime( true ) + 0.4;
	$pipes = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		$pid  = pcntl_fork();
		if ( 0 === $pid ) {
			fclose( $pair[0] );
			$wpdb->close();
			$wpdb->db_connect( false );
			wp_cache_flush();
			$wait = $start - microtime( true );
			if ( $wait > 0 ) { usleep( (int) ( $wait * 1e6 ) ); }
			$out = $fn( $i );
			fwrite( $pair[1], json_encode( $out ) );
			fclose( $pair[1] );
			posix_kill( getmypid(), SIGKILL ); // skip shutdown hooks and destructors in the child
		}
		fclose( $pair[1] );
		$pipes[ $pid ] = $pair[0];
	}
	$results = array();
	foreach ( $pipes as $pid => $pipe ) {
		$results[] = json_decode( (string) stream_get_contents( $pipe ), true );
		fclose( $pipe );
		pcntl_waitpid( $pid, $status );
	}
	$wpdb->close();
	$wpdb->db_connect( false );
	wp_cache_flush();
	return $results;
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
// Every ZIP-related file in the folder, temporary ones included.
$all_zip_files = static function () use ( $dir ): array { clearstatcache(); return array_values( array_filter( array_map( 'basename', (array) glob( $dir . '/*' ) ), static function ( $b ) { return 1 === preg_match( '/^[a-f0-9]{32}\.zip/', $b ); } ) ); };
$records = static function (): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like( GHCA_ACD_Redesign_Certs::ZIP_RECORD ) . '%', GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION ) ); };
// Change fields of a stored record directly in the database (to move it in time).
$set_job = static function ( string $token, array $fields ): void { global $wpdb; $j = GHCA_ACD_Redesign_Certs::job( $token )['job']; $wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( array_merge( $j, $fields ) ) ), array( 'option_name' => GHCA_ACD_Redesign_Certs::ZIP_RECORD . $token ) ); };
$drop = static function ( string $token ): void { global $wpdb; $j = GHCA_ACD_Redesign_Certs::job( $token ); if ( $j ) { @unlink( (string) $j['job']['path'] ); } $wpdb->delete( $wpdb->options, array( 'option_name' => GHCA_ACD_Redesign_Certs::ZIP_RECORD . $token ) ); }; // phpcs:ignore
// Setup: remove ZIP leftovers of earlier interrupted runs on this disposable site, so every count below starts at zero.
( static function () use ( $dir ): void {
	global $wpdb;
	foreach ( (array) scandir( $dir ) as $n ) { if ( preg_match( '/^[a-f0-9]{32}\.zip/', (string) $n ) ) { exec( 'chattr -i ' . escapeshellarg( $dir . '/' . $n ) . ' 2>/dev/null' ); unlink( $dir . '/' . $n ); } }
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( GHCA_ACD_Redesign_Certs::ZIP_RECORD ) . '%' ) );
	delete_option( GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION );
	wp_unschedule_hook( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK );
} )();


// ---- Global admin: a ZIP of three, privately stored, single use.
wp_set_current_user( $admin->ID );
check( GHCA_ACD_Redesign_Certs::can_use() && GHCA_ACD_Redesign_Certs::zip_available(), 'setup: the admin may download certificates; PHP zip is available here' );
$items = GHCA_ACD_Redesign_Certs::parse_items( array( $jordan->ID . ':' . $c1, $morgan->ID . ':' . $c2, $avery->ID . ':' . $c1, $jordan->ID . ':' . $c1 ) );
check( 3 === count( $items ), 'repeated selections are counted once' );
$before = $zips();
$r = GHCA_ACD_Redesign_Certs::build_zip( $items, $admin->ID, $fetch_ok );
check( $r['ok'] && 3 === $r['count'] && $zips() === $before + 1, 'a ZIP of 3 is built' );
$job  = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
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
$j2 = GHCA_ACD_Redesign_Certs::job( $r2['token'] )['job'];
$z->open( (string) $j2['path'] );
$n2 = array( $z->getNameIndex( 0 ), $z->getNameIndex( 1 ) );
$z->close();
sort( $n2 );
check( array( 'morgan-lee_fatal-five-2.pdf', 'morgan-lee_fatal-five.pdf' ) === $n2, 'same employee and course title: distinct names (' . implode( ', ', $n2 ) . ')' );
$drop( $r2['token'] );

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
check( ! is_file( $path ) && null === GHCA_ACD_Redesign_Certs::job( $r['token'] ), 'served once: file and record removed' );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 410 === $d['status'] && false !== strpos( $d['body'], 'no longer available' ), 'a second download is refused clearly' );
// Another admin cannot use someone else's ZIP.
$r3 = GHCA_ACD_Redesign_Certs::build_zip( array( array( $jordan->ID, $c1 ) ), $admin->ID, $fetch_ok );
$other = get_user_by( 'login', 'synthetic-admin2' );
if ( ! $other ) { $other = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-admin2', 'user_email' => 'admin2@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) ) ); }
wp_set_current_user( $other->ID );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r3['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
$j3 = GHCA_ACD_Redesign_Certs::job( $r3['token'] );
check( 410 === $d['status'] && is_array( $j3 ) && empty( $j3['job']['claimed_at'] ) && is_file( (string) $j3['job']['path'] ), 'another administrator cannot download someone else\'s ZIP (answered as unavailable; not claimed; the owner\'s copy stays)' );
$j3 = $j3['job'];
// Expiry.
wp_set_current_user( $admin->ID );
$set_job( $r3['token'], array( 'expires' => time() - 5 ) );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r3['token'], 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 410 === $d['status'] && ! is_file( (string) $j3['path'] ) && null === GHCA_ACD_Redesign_Certs::job( $r3['token'] ), 'an expired ZIP is refused at download (access expiry does not depend on cron), and deleted' );
// ================= Archive writing: every entry, the close and the result are checked =================
$two = array( array( $jordan->ID, $c1 ), array( $avery->ID, $c1 ) );
$no_trace = static function () use ( $all_zip_files, $records ): array { return array( count( $all_zip_files() ), $records() ); };
$base = $no_trace();
GHCA_ACD_Redesign_Certs::use_archive_factory( static function () { return new Ghca_S9_Zip_FailAdd(); } );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
check( ! $r['ok'] && false !== strpos( $r['message'], 'could not be written' ) && $no_trace() === $base, 'a failed entry write: error, no archive or temporary file left, no record (nothing to download)' );
GHCA_ACD_Redesign_Certs::use_archive_factory( static function () { return new Ghca_S9_Zip_FailClose(); } );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
check( ! $r['ok'] && false !== strpos( $r['message'], 'could not be written' ) && $no_trace() === $base, 'a failed close (the file was half-written): error, the file is deleted, no record' );
GHCA_ACD_Redesign_Certs::use_archive_factory( static function () { return new Ghca_S9_Zip_Drop(); } );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
check( ! $r['ok'] && false !== strpos( $r['message'], 'failed its check' ) && $no_trace() === $base, 'an entry silently missing from the written archive is caught by the entry-count check; deleted, no record' );
GHCA_ACD_Redesign_Certs::use_archive_factory( static function () { return new Ghca_S9_Zip_Tamper(); } );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
check( ! $r['ok'] && false !== strpos( $r['message'], 'failed its check' ) && $no_trace() === $base, 'an entry with different bytes is caught by the content check; deleted, no record' );
GHCA_ACD_Redesign_Certs::use_archive_factory( null );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
check( $r['ok'] && 2 === $r['count'], 'with the real ZipArchive again, the same selection builds and verifies' );
$drop( $r['token'] );

// ================= Download: failed reads are errors, never an empty 200 =================
$nonce = wp_create_nonce( 'ghca_acd_table' );
foreach ( array(
	'read returns false' => static function () { return false; },
	'short read'         => static function ( string $p ) { return substr( (string) file_get_contents( $p ), 0, 10 ); },
	'empty read'         => static function () { return ''; },
) as $label => $reader ) {
	$r  = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
	$jp = (string) GHCA_ACD_Redesign_Certs::job( $r['token'] )['job']['path'];
	$d  = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => $nonce ), 0, $reader );
	check( 500 === $d['status'] && 0 === strpos( $d['headers']['Content-Type'], 'application/json' ) && false !== strpos( $d['body'], 'could not be read' ) && 0 !== strpos( $d['body'], 'PK' ), 'download, ' . $label . ': 500 with a clear JSON message, not a 200' );
	$d2 = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => $nonce ) );
	check( ! is_file( $jp ) && null === GHCA_ACD_Redesign_Certs::job( $r['token'] ) && 410 === $d2['status'], 'download, ' . $label . ': the claimed file and record are removed; a retry says to prepare the ZIP again' );
}

// ================= Cleanup independent of later builds =================
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
$jx = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
$px = (string) $jx['path'];
$ev = wp_next_scheduled( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK, array( $r['token'] ) );
check( true === $r['cleanup_scheduled'] && (int) $ev === $jx['expires'] + GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE && false !== wp_next_scheduled( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK ) && (int) get_option( GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION ) > 0, 'building schedules this ZIP\'s own cleanup (5 minutes after access expiry), an hourly backstop and the fallback due time' );
$c = GHCA_ACD_Redesign_Certs::cleanup( '', $jx['expires'] + 1 );
check( is_file( $px ) && null !== GHCA_ACD_Redesign_Certs::job( $r['token'] ) && $c['remaining'] >= 1, 'access expiry is not deletion: just after expiry cleanup keeps the file (its deletion is due later)' );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $r['token'], 'nonce' => $nonce ), $jx['expires'] + 1 );
check( 410 === $d['status'], '…but the download is already refused' );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
$jx = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
$set_job( $r['token'], array( 'expires' => time() - GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE - 1 ) );
do_action( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK, $r['token'] ); // what WP-Cron runs when the event is due
check( ! is_file( (string) $jx['path'] ) && null === GHCA_ACD_Redesign_Certs::job( $r['token'] ), 'the scheduled cleanup event removes an expired, never-downloaded ZIP and its record, with no later build or download' );
// Abandoned files with no record: old archives and ZipArchive temporary files go; young ones and the deny files stay.
$old_zip  = $dir . '/' . str_repeat( 'c', 32 ) . '.zip';
$old_tmp  = $dir . '/' . str_repeat( 'd', 32 ) . '.zip.Ab12Cd';
$young    = $dir . '/' . str_repeat( 'e', 32 ) . '.zip';
$stranger = $dir . '/notes.txt';
foreach ( array( $old_zip, $old_tmp, $young, $stranger ) as $f ) { file_put_contents( $f, 'PK' ); }
$long_ago = time() - GHCA_ACD_Redesign_Certs::ZIP_TTL - GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE - 60;
touch( $old_zip, $long_ago ); touch( $old_tmp, $long_ago ); touch( $stranger, $long_ago );
$deny = array_values( array_filter( (array) glob( $dir . '/{.htaccess,index.php,web.config}', GLOB_BRACE ), 'is_file' ) );
// A record whose download was claimed but never finished (the request died), long ago.
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
$jc = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
$set_job( $r['token'], array( 'claimed_by' => $admin->ID, 'claimed_at' => time() - GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE - 1 ) );
GHCA_ACD_Redesign_Certs::cleanup();
clearstatcache();
check( ! is_file( $old_zip ) && ! is_file( $old_tmp ), 'abandoned files with no record (an old archive and an old ZipArchive temporary file) are deleted by age' );
check( is_file( $young ) && is_file( $stranger ) && count( $deny ) >= 1 && count( array_filter( $deny, 'is_file' ) ) === count( $deny ), 'a young file (possibly being written) is kept; the deny files and unrelated files are never touched' );
check( ! is_file( (string) $jc['path'] ) && null === GHCA_ACD_Redesign_Certs::job( $r['token'] ), 'a claimed download that never finished is cleaned up after the grace period' );
@unlink( $young ); @unlink( $stranger ); // phpcs:ignore
// Scheduling fails entirely: the request-end fallback takes over.
GHCA_ACD_Redesign_Certs::cleanup();
$block = static function ( $pre, $event ) { return GHCA_ACD_Redesign_Certs::CLEANUP_HOOK === $event->hook ? new WP_Error( 'blocked', 'test: scheduling refused' ) : $pre; };
add_filter( 'pre_schedule_event', $block, 10, 2 );
$r = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
remove_filter( 'pre_schedule_event', $block, 10 );
$jf = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
check( $r['ok'] && false === $r['cleanup_scheduled'] && false === wp_next_scheduled( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK, array( $r['token'] ) ) && (int) get_option( GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION ) === $jf['expires'] + GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE, 'when WP-Cron refuses both events the ZIP is still offered, and the fallback due time is recorded' );
GHCA_ACD_Redesign_Certs::fallback_cleanup();
check( is_file( (string) $jf['path'] ), 'the fallback does nothing before a cleanup is overdue' );
$set_job( $r['token'], array( 'expires' => time() - GHCA_ACD_Redesign_Certs::ZIP_DELETE_GRACE - GHCA_ACD_Redesign_Certs::ZIP_FALLBACK_LAG - 1 ) );
update_option( GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION, time() - GHCA_ACD_Redesign_Certs::ZIP_FALLBACK_LAG - 1, true );
GHCA_ACD_Redesign_Certs::fallback_cleanup(); // runs on 'shutdown' of any request
check( ! is_file( (string) $jf['path'] ) && null === GHCA_ACD_Redesign_Certs::job( $r['token'] ), 'once overdue, the request-end fallback deletes it without cron' );
check( false === get_option( GHCA_ACD_Redesign_Certs::CLEANUP_DUE_OPTION ) && false === wp_next_scheduled( GHCA_ACD_Redesign_Certs::CLEANUP_HOOK ) && 0 === $records() && array() === $all_zip_files(), 'with nothing left, the due time and every cleanup event are removed' );

// ================= Deletion failures: nothing counts as removed until confirmed; retry state is kept =================
// Real refusals: the immutable flag makes the filesystem refuse unlink even for root, and database statements are
// pointed at a table that does not exist, so MariaDB itself returns the error.
$C       = 'GHCA_ACD_Redesign_Certs';
$lock    = static function ( string $f, bool $on ): bool { exec( 'chattr ' . ( $on ? '+i' : '-i' ) . ' ' . escapeshellarg( $f ) . ' 2>&1', $o, $rc ); return 0 === $rc; };
$state   = static function () use ( $C ): array { return array( 'due' => (int) get_option( $C::CLEANUP_DUE_OPTION, 0 ), 'backstop' => wp_next_scheduled( $C::CLEANUP_HOOK ) ); };
$cleared = static function () use ( $C ): bool { return false === get_option( $C::CLEANUP_DUE_OPTION ) && false === wp_next_scheduled( $C::CLEANUP_HOOK ); };
$retry_kept = static function () use ( $state, $C ): bool { $s = $state(); return $s['due'] > time() && $s['due'] <= time() + $C::ZIP_RETRY_DELAY + 5 && false !== $s['backstop']; };
$break = static function ( string $verb, string $needle ) { global $wpdb; return static function ( $q ) use ( $verb, $needle, $wpdb ) { return 0 === stripos( ltrim( (string) $q ), $verb ) && false !== strpos( (string) $q, $needle ) ? str_replace( $wpdb->options, 'ghca_no_such_table', (string) $q ) : $q; }; };
global $wpdb;
$C::cleanup();
check( $cleared(), 'setup: nothing left and no cleanup state' );

// (a) Failed unlink of an expired ZIP's file.
$r  = $C::build_zip( $two, $admin->ID, $fetch_ok );
$tk = $r['token'];
$jp = (string) $C::job( $tk )['job']['path'];
$set_job( $tk, array( 'expires' => time() - $C::ZIP_DELETE_GRACE - 1 ) );
wp_unschedule_hook( $C::CLEANUP_HOOK ); // so the test sees cleanup itself put the backstop back
check( $lock( $jp, true ), 'setup: the filesystem refuses to delete the archive (immutable flag)' );
$c = $C::cleanup();
clearstatcache();
check( false === $c['ok'] && $c['failed'] >= 1 && 0 === $c['files'] && 0 === $c['records'] && is_file( $jp ) && null !== $C::job( $tk ), 'failed unlink: not reported as removed (0 files, 0 records); the file and its record are kept so the next run finds it' );
check( $retry_kept(), 'failed unlink: the due marker is kept for a retry within 5 minutes and the hourly backstop is scheduled again' );
update_option( $C::CLEANUP_DUE_OPTION, time() - $C::ZIP_FALLBACK_LAG - 1, true );
$C::fallback_cleanup();
clearstatcache();
check( is_file( $jp ) && $retry_kept(), 'a fallback retry that fails again keeps the retry state again (nothing is cleared)' );
$lock( $jp, false );
$c = $C::cleanup();
clearstatcache();
check( true === $c['ok'] && 1 === $c['files'] && 1 === $c['records'] && 0 === $c['failed'] && ! is_file( $jp ) && null === $C::job( $tk ), 'once the filesystem allows it, the next run removes the file and record and counts both' );
check( $cleared(), '…and only that confirmed run clears the due marker and the backstop' );

// (b) Failed unlink of an abandoned file with no record.
$orph = $dir . '/' . str_repeat( 'f', 32 ) . '.zip';
file_put_contents( $orph, 'PK' );
touch( $orph, time() - $C::ZIP_TTL - $C::ZIP_DELETE_GRACE - 60 );
$lock( $orph, true );
$c = $C::cleanup();
clearstatcache();
check( false === $c['ok'] && 0 === $c['files'] && is_file( $orph ) && $retry_kept(), 'failed unlink of an abandoned file: not counted, file kept, retry state kept' );
$lock( $orph, false );
$c = $C::cleanup();
clearstatcache();
check( $c['ok'] && 1 === $c['files'] && ! is_file( $orph ) && $cleared(), 'abandoned file: removed and counted on the next run; state cleared' );

// (c) Failed database deletion of an expired ZIP's record.
$r  = $C::build_zip( $two, $admin->ID, $fetch_ok );
$tk = $r['token'];
$jp = (string) $C::job( $tk )['job']['path'];
$set_job( $tk, array( 'expires' => time() - $C::ZIP_DELETE_GRACE - 1 ) );
wp_unschedule_hook( $C::CLEANUP_HOOK );
$f = $break( 'DELETE', $C::ZIP_RECORD . $tk );
add_filter( 'query', $f );
$quiet = $wpdb->suppress_errors( true );
$c = $C::cleanup();
$wpdb->suppress_errors( $quiet );
remove_filter( 'query', $f );
clearstatcache();
check( false === $c['ok'] && 1 === $c['files'] && 0 === $c['records'] && ! is_file( $jp ) && null !== $C::job( $tk ), 'failed record deletion (database error): the file, confirmed gone, is counted; the record is not, and it stays' );
check( $retry_kept(), 'failed record deletion: retry due and backstop kept' );
$c = $C::cleanup();
check( $c['ok'] && 0 === $c['files'] && 1 === $c['records'] && null === $C::job( $tk ) && $cleared(), 'next run: the file is already gone (counts as success), the record is deleted; state cleared' );

// (d) Failed reading of the records: never treated as "nothing left".
$r  = $C::build_zip( $two, $admin->ID, $fetch_ok );
$tk = $r['token'];
$jp = (string) $C::job( $tk )['job']['path'];
$f = $break( 'SELECT option_name, option_value', 'option_name LIKE' ); // the LIKE pattern is escaped, so match the clause
add_filter( 'query', $f );
$quiet = $wpdb->suppress_errors( true );
$c = $C::cleanup( '', time() + 7200 ); // far enough ahead that the ZIP would otherwise be deleted
$wpdb->suppress_errors( $quiet );
remove_filter( 'query', $f );
clearstatcache();
check( false === $c['ok'] && is_file( $jp ) && null !== $C::job( $tk ) && false !== get_option( $C::CLEANUP_DUE_OPTION ) && false !== wp_next_scheduled( $C::CLEANUP_HOOK ), 'records cannot be read (database error): the run fails, deletes nothing and keeps the due marker and backstop' );

// (e) Download: a failed job lookup is a retryable 503, not "expired" or "missing", and consumes nothing.
$f = $break( 'SELECT option_value', $C::ZIP_RECORD . $tk );
add_filter( 'query', $f );
$quiet = $wpdb->suppress_errors( true );
$d = $C::zip_download( array( 'token' => $tk, 'nonce' => $nonce ) );
$wpdb->suppress_errors( $quiet );
remove_filter( 'query', $f );
$jl = $C::job( $tk );
check( 503 === $d['status'] && false !== strpos( $d['body'], 'Try the download again' ) && false === strpos( $d['body'], 'no longer available' ), 'download with a failed job lookup: 503 "try again", not the expired/missing 410' );
check( null !== $jl && empty( $jl['job']['claimed_at'] ) && is_file( $jp ), '…and the ZIP is untouched (not claimed, file kept)' );
$d = $C::zip_download( array( 'token' => $tk, 'nonce' => $nonce ) );
check( 200 === $d['status'] && 0 === strpos( $d['body'], 'PK' ), 'retrying once the database answers downloads it' );
$d = $C::zip_download( array( 'token' => str_repeat( 'a', 32 ), 'nonce' => $nonce ) );
check( 410 === $d['status'], 'a token with no record is still the 410 "no longer available"' );
$C::cleanup();

// (f) Download served, but the file deletion afterwards is refused.
$r  = $C::build_zip( $two, $admin->ID, $fetch_ok );
$tk = $r['token'];
$jp = (string) $C::job( $tk )['job']['path'];
wp_unschedule_hook( $C::CLEANUP_HOOK );
delete_option( $C::CLEANUP_DUE_OPTION );
$want = sha1( (string) file_get_contents( $jp ) );
$lock( $jp, true );
$d  = $C::zip_download( array( 'token' => $tk, 'nonce' => $nonce ) );
$jl = $C::job( $tk );
clearstatcache();
check( 200 === $d['status'] && sha1( $d['body'] ) === $want, 'the owner still gets the complete archive' );
check( is_file( $jp ) && null !== $jl && ! empty( $jl['job']['claimed_at'] ), 'the undeletable file keeps its claimed record (not removed, not reported removed)' );
$s = $state();
check( $s['due'] > 0 && false !== $s['backstop'], 'a retry is due and the backstop scheduled' );
check( 410 === $C::zip_download( array( 'token' => $tk, 'nonce' => $nonce ) )['status'], 'the claimed ZIP cannot be downloaded a second time' );
$lock( $jp, false );
$c = $C::cleanup( '', time() + $C::ZIP_DELETE_GRACE + 2 );
clearstatcache();
check( $c['ok'] && 1 === $c['files'] && 1 === $c['records'] && ! is_file( $jp ) && null === $C::job( $tk ) && $cleared(), 'after the grace period cleanup removes it, counts it and clears the state' );

// (g) Download served, file deleted, but the record deletion is refused.
$r  = $C::build_zip( $two, $admin->ID, $fetch_ok );
$tk = $r['token'];
$jp = (string) $C::job( $tk )['job']['path'];
wp_unschedule_hook( $C::CLEANUP_HOOK );
delete_option( $C::CLEANUP_DUE_OPTION );
$f = $break( 'DELETE', $C::ZIP_RECORD . $tk );
add_filter( 'query', $f );
$quiet = $wpdb->suppress_errors( true );
$d = $C::zip_download( array( 'token' => $tk, 'nonce' => $nonce ) );
$wpdb->suppress_errors( $quiet );
remove_filter( 'query', $f );
clearstatcache();
$s = $state();
check( 200 === $d['status'] && ! is_file( $jp ) && null !== $C::job( $tk ) && $s['due'] > 0 && false !== $s['backstop'], 'record deletion refused after a download: the claimed record stays and a retry is kept' );
$c = $C::cleanup( '', time() + $C::ZIP_DELETE_GRACE + 2 );
check( $c['ok'] && 1 === $c['records'] && null === $C::job( $tk ) && $cleared(), 'the retry deletes the record and clears the state' );

// ================= Concurrency: separate processes, separate database connections =================
check( function_exists( 'pcntl_fork' ), 'setup: pcntl is available for the concurrency checks' );
for ( $round = 1; $round <= 5; $round++ ) {
	wp_set_current_user( $admin->ID );
	$r    = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
	$jr   = GHCA_ACD_Redesign_Certs::job( $r['token'] )['job'];
	$want = sha1( (string) file_get_contents( (string) $jr['path'] ) );
	$tok  = $r['token'];
	$res  = race( 8, static function ( int $i ) use ( $tok, $admin, $other ): array {
		$u = $i % 2 ? $other->ID : $admin->ID; // half the requests are another administrator
		wp_set_current_user( $u );
		$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $tok, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
		return array( 'u' => $u, 'status' => $d['status'], 'sha' => 200 === $d['status'] ? sha1( $d['body'] ) : '' );
	} );
	$ok200 = array_values( array_filter( $res, static function ( $x ) { return 200 === $x['status']; } ) );
	$rest  = array_filter( $res, static function ( $x ) { return 200 !== $x['status']; } );
	$all_410 = array() === array_filter( $rest, static function ( $x ) { return 410 !== $x['status']; } );
	check( 8 === count( $res ) && 1 === count( $ok200 ) && $admin->ID === $ok200[0]['u'] && $want === $ok200[0]['sha'] && $all_410, 'round ' . $round . ': 8 simultaneous downloads (4 by the owner, 4 by another admin) → exactly one 200, by the owner, with the complete archive; the other 7 get 410' );
	clearstatcache();
	$left = GHCA_ACD_Redesign_Certs::job( $tok );
	check( null === $left && ! is_file( (string) $jr['path'] ), 'round ' . $round . ': afterwards the file and record are gone' . ( null === $left ? '' : ' [record: ' . $left['raw'] . ']' ) . ( is_file( (string) $jr['path'] ) ? ' [file still there]' : '' ) );
}
// Only another administrator racing: nobody is served, and the owner's ZIP is untouched (not claimed).
wp_set_current_user( $admin->ID );
$r   = GHCA_ACD_Redesign_Certs::build_zip( $two, $admin->ID, $fetch_ok );
$tok = $r['token'];
$res = race( 6, static function () use ( $tok, $other ): array {
	wp_set_current_user( $other->ID );
	return array( 'status' => GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $tok, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) )['status'] );
} );
$jr = GHCA_ACD_Redesign_Certs::job( $tok );
check( 6 === count( $res ) && array( 410 ) === array_values( array_unique( array_column( $res, 'status' ) ) ) && null !== $jr && empty( $jr['job']['claimed_at'] ) && is_file( (string) $jr['job']['path'] ), '6 simultaneous downloads by another administrator: all 410, the claim stays with the creator, the file stays' );
wp_set_current_user( $admin->ID );
$d = GHCA_ACD_Redesign_Certs::zip_download( array( 'token' => $tok, 'nonce' => wp_create_nonce( 'ghca_acd_table' ) ) );
check( 200 === $d['status'], '…and the owner can still download it afterwards' );


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
$drop( $r['token'] );
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
