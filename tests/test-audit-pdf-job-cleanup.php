<?php
// GRI-62: packet job folders are removed reliably. Synthetic data only; no WordPress, network or real records.
require __DIR__ . '/bootstrap.php';
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
$GLOBALS['transients'] = array();
$GLOBALS['force_fs_failure'] = false;
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function apply_filters( $hook, $value, ...$args ) { return 'ghca_acd_pdf_jobs_force_fs_failure' === $hook && $GLOBALS['force_fs_failure'] ? true : $value; }
function __( $t, $d = '' ) { return $t; }
class WP_Error { public function __construct( ...$a ) {} }
$log = sys_get_temp_dir() . '/ghca-cleanup-test-' . getmypid() . '.log';
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $log );
require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';

$fails = 0;
function check( bool $c, string $m ): void { global $fails; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; $c || $fails++; }
function J(): string { return 'GHCA_Audit_PDF_Jobs'; }
function make_job_dir( string $id, bool $hidden = true, int $age = 0 ): string {
	$dir = GHCA_Audit_PDF_Jobs::temp_dir( $id );
	mkdir( $dir . '/sub/deeper', 0700, true );
	file_put_contents( $dir . '/cert_0.pdf', 'x' );
	file_put_contents( $dir . '/sub/deeper/file.bin', 'x' );
	if ( $hidden ) { file_put_contents( $dir . '/.htaccess', 'Require all denied' ); file_put_contents( $dir . '/sub/.hidden', 'x' ); }
	if ( $age ) { touch( $dir, time() - $age ); }
	return $dir;
}
$id1 = str_repeat( 'a1', 16 ); $id2 = str_repeat( 'b2', 16 ); $id3 = str_repeat( 'c3', 16 ); $id4 = str_repeat( 'd4', 16 );
$old = GHCA_Audit_PDF_Jobs::TTL + 600;

// create_job: parent protection exists before any job folder, and the job folder carries none of its own.
$job = GHCA_Audit_PDF_Jobs::create_job( 77, 5, 'orientation', array(), 'p.pdf' );
$base = GHCA_Audit_PDF_Jobs::temp_base();
check( is_file( $base . '/.htaccess' ) && is_file( $base . '/index.html' ), 'parent temp base has deny-all .htaccess and index.html' );
check( false !== strpos( file_get_contents( $base . '/.htaccess' ), 'Require all denied' ), 'parent .htaccess is deny-all' );
check( is_dir( GHCA_Audit_PDF_Jobs::temp_dir( $job ) ) && 0700 === ( fileperms( GHCA_Audit_PDF_Jobs::temp_dir( $job ) ) & 0777 ), 'job folder is created private (0700)' );
check( array() === array_values( array_diff( scandir( GHCA_Audit_PDF_Jobs::temp_dir( $job ) ), array( '.', '..' ) ) ), 'new job folder holds no per-job protection files' );
check( 0 !== strpos( wp_normalize_path( $base ) . '/', rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/' ), 'temp base is outside the WordPress root' );
// Fail closed if the parent protection is missing.
unlink( $base . '/.htaccess' );
$ref = new ReflectionMethod( J(), 'create_job_dir' ); if ( PHP_VERSION_ID < 80100 ) { $ref->setAccessible( true ); }
try { $ref->invoke( null, $id4 ); check( false, 'job creation refused without parent protection' ); } catch ( RuntimeException $e ) { check( ! is_dir( GHCA_Audit_PDF_Jobs::temp_dir( $id4 ) ), 'job creation refused without parent protection' ); }
$job2 = GHCA_Audit_PDF_Jobs::create_job( 77, 5, 'orientation', array(), 'p.pdf' ); // re-creates the parent files via ensure_dir
check( is_file( $base . '/.htaccess' ), 'parent protection restored before the next job is created' );

// Normal deletion leaves no folder, hidden files included.
$dir = make_job_dir( $id1 );
set_transient( J()::TRANSIENT_PREFIX . $id1, array( 'owner' => 1, 'created' => time() ) );
check( true === GHCA_Audit_PDF_Jobs::delete_job( $id1 ) && ! file_exists( $dir ) && ! is_link( $dir ), 'delete_job removes the folder including .htaccess and nested hidden files' );
check( false === get_transient( J()::TRANSIENT_PREFIX . $id1 ), 'delete_job drops the manifest' );
check( false === GHCA_Audit_PDF_Jobs::delete_job( '../escape' ), 'delete_job rejects an invalid job id' );

// Symlinks are unlinked, never followed.
$outside = sys_get_temp_dir() . '/ghca-outside-' . getmypid(); mkdir( $outside ); file_put_contents( $outside . '/keep.txt', 'keep' );
$dir = make_job_dir( $id1 ); symlink( $outside, $dir . '/link' ); symlink( $outside . '/keep.txt', $dir . '/filelink' );
check( true === GHCA_Audit_PDF_Jobs::delete_job( $id1 ) && ! file_exists( $dir ), 'job with symlinks is removed' );
check( is_file( $outside . '/keep.txt' ), 'symlink targets outside the job folder are untouched' );

// Guards: nothing outside the temp base / invalid names is ever removed.
$rm = new ReflectionMethod( J(), 'rmdir_recursive' ); if ( PHP_VERSION_ID < 80100 ) { $rm->setAccessible( true ); }
file_put_contents( $log, '' );
check( false === $rm->invoke( null, $outside ) && is_file( $outside . '/keep.txt' ), 'rmdir_recursive refuses a folder outside the temp base' );
$decoy = $base . '/not-a-job'; mkdir( $decoy ); file_put_contents( $decoy . '/x', 'x' );
check( false === $rm->invoke( null, $decoy ) && is_file( $decoy . '/x' ), 'rmdir_recursive refuses a folder whose name is not a valid job id' );
$nested = GHCA_Audit_PDF_Jobs::temp_dir( $id2 ) . '/' . $id3; mkdir( $nested, 0700, true );
check( false === $rm->invoke( null, $nested ) && is_dir( $nested ), 'rmdir_recursive refuses a nested path that is not a direct child of the base' );
GHCA_Audit_PDF_Jobs::delete_job( $id2 );
check( false !== strpos( file_get_contents( $log ), 'rmdir_refused' ), 'refusals are logged with an error code' );

// gc.
file_put_contents( $log, '' );
$orphan_only_htaccess = GHCA_Audit_PDF_Jobs::temp_dir( $id1 ); mkdir( $orphan_only_htaccess, 0700, true ); file_put_contents( $orphan_only_htaccess . '/.htaccess', 'Require all denied' ); touch( $orphan_only_htaccess, time() - $old );
$recent_orphan = make_job_dir( $id2, true, 600 );
$active = make_job_dir( $id3, true, $old ); set_transient( J()::TRANSIENT_PREFIX . $id3, array( 'owner' => 9, 'created' => time() ) );
$non_job = $base . '/Not_A_Job'; mkdir( $non_job ); file_put_contents( $non_job . '/f', 'x' ); touch( $non_job, time() - $old );
$evidence = dirname( $base ) . '/ghca-evidence-vault'; mkdir( $evidence ); file_put_contents( $evidence . '/record.enc', 'secret' ); touch( $evidence, time() - $old );
GHCA_Audit_PDF_Jobs::gc();
check( ! file_exists( $orphan_only_htaccess ), 'gc removes an expired orphan folder that holds only .htaccess' );
check( is_dir( $recent_orphan ) && is_file( $recent_orphan . '/.htaccess' ), 'gc keeps a recent (<1h) orphan' );
check( is_dir( $active ) && is_file( $active . '/cert_0.pdf' ), 'gc never touches an active job, even if the folder is old' );
check( is_dir( $non_job ) && is_file( $non_job . '/f' ), 'gc ignores folders that are not valid job ids' );
check( is_file( $evidence . '/record.enc' ), 'gc never touches anything outside the temp/packet folders' );
check( is_file( $base . '/.htaccess' ) && is_file( $base . '/index.html' ), 'gc keeps the parent protection files' );
check( '' === trim( (string) file_get_contents( $log ) ), 'a clean gc logs nothing' );

// gc removes expired orphans (full tree) and their locks; keeps live locks and owner locks.
$packets = GHCA_Audit_PDF_Jobs::packets_base();
$stale_job_lock = $packets . '/job_' . $id4 . '.lock'; $stale_broker = $packets . '/broker_' . $id4 . '_0.lock';
$live_job_lock = $packets . '/job_' . $id3 . '.lock'; $owner_lock = $packets . '/owner_5.lock'; $other = $packets . '/unrelated.lock';
foreach ( array( $stale_job_lock, $stale_broker, $live_job_lock, $owner_lock, $other ) as $f ) { file_put_contents( $f, '' ); touch( $f, time() - $old ); }
$full_orphan = make_job_dir( $id4, true, $old );
touch( $packets . '/job_' . $id4 . '.lock', time() - $old );
GHCA_Audit_PDF_Jobs::gc();
check( ! file_exists( $full_orphan ), 'gc removes an expired orphan with nested hidden files' );
check( ! file_exists( $stale_job_lock ) && ! file_exists( $stale_broker ), 'stale job_ and broker_ locks of a vanished job are expired' );
check( is_file( $live_job_lock ), 'lock of a live job is kept' );
check( is_file( $owner_lock ), 'owner locks are kept as designed' );
check( is_file( $other ), 'unrecognised lock files are not touched' );

// Forced removal failure is logged without paths.
file_put_contents( $log, '' );
$dir = make_job_dir( $id1 ); $GLOBALS['force_fs_failure'] = true;
check( false === GHCA_Audit_PDF_Jobs::delete_job( $id1 ), 'delete_job reports failure when removal fails' );
$GLOBALS['force_fs_failure'] = false;
$logged = (string) file_get_contents( $log );
check( 1 === preg_match( '/rmdir_failed job=[a-f0-9]{10}\b/', $logged ), 'failure is logged with an error code and a short job hash' );
check( false === strpos( $logged, $id1 ) && false === strpos( $logged, $base ) && false === strpos( $logged, sys_get_temp_dir() ) && false === strpos( $logged, 'ghca-compliance' ), 'log has no full job id, path or temp dir' );
check( true === GHCA_Audit_PDF_Jobs::delete_job( $id1 ) && ! file_exists( $dir ), 'a later attempt succeeds once the fault clears' );

// Cleanup of test artifacts.
foreach ( array( $decoy, $non_job, $evidence, $outside, $recent_orphan, $active ) as $d ) {
	if ( is_dir( $d ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $d ); }
}
foreach ( array( $stale_job_lock, $stale_broker, $live_job_lock, $owner_lock, $other, $log ) as $f ) { @unlink( $f ); }
GHCA_Audit_PDF_Jobs::delete_job( $job );
GHCA_Audit_PDF_Jobs::delete_job( $job2 );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
