<?php
require __DIR__ . '/bootstrap.php';
if ( '\\' === DIRECTORY_SEPARATOR && ! defined( 'GHCA_ACD_PRIVATE_DIR' ) ) {
	$test_private_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-private-test-' . getmypid();
	if ( ! is_dir( $test_private_dir ) ) { mkdir( $test_private_dir, 0777, true ); }
	define( 'GHCA_ACD_PRIVATE_DIR', $test_private_dir );
	define( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED', true );
}
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';

$fails = 0;
function check( bool $cond, string $msg ): void {
  global $fails;
  if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
}

// --- job id format ---------------------------------------------------------
check( GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'a1', 16 ) ), '32 hex chars => valid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( 'short' ), 'short id => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'g', 32 ) ), 'non-hex => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( '../../etc/passwd' ), 'traversal => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( strtoupper( str_repeat( 'a1', 16 ) ) ), 'uppercase => invalid (we only mint lowercase)' );

// --- expiry -----------------------------------------------------------------
$now = 1000000000;
check( ! GHCA_Audit_PDF_Jobs::is_expired( $now - 3599, $now ), '59m59s old => live' );
check( GHCA_Audit_PDF_Jobs::is_expired( $now - 3601, $now ), '1h+1s old => expired' );
check( GHCA_Audit_PDF_Jobs::is_expired( 0, $now ), 'zero created => expired' );

// --- manifest validation ----------------------------------------------------
$good = array( 'owner' => 5, 'user_id' => 9, 'tracker' => 'annual', 'urls' => array( 'http://x/a' ), 'filename' => 'p.pdf', 'created' => $now - 60 );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 5, $now ) === true, 'good manifest => true' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( false, 5, $now ) === 'not_found', 'transient miss (false) => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( array(), 5, $now ) === 'not_found', 'empty array => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 6, $now ) === 'owner_mismatch', 'other admin => owner_mismatch' );
$stale = array_merge( $good, array( 'created' => $now - 7200 ) );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $stale, 5, $now ) === 'expired', 'stale => expired' );

// Zero-certificate jobs are legitimate (cover sheet only) — must validate.
$empty_urls = array_merge( $good, array( 'urls' => array() ) );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $empty_urls, 5, $now ) === true, 'zero certs => still valid job' );

// The internal certificate broker is one-time and bound to a brokerable course.
$token  = str_repeat( 'ab', 32 );
$broker = array_merge( $good, array(
	'course_ids'   => array( 44 ),
	'brokerable'   => array( true ),
	'broker_tokens' => array( $token ),
	'broker_used'  => array(),
) );
check( GHCA_Audit_PDF_Jobs::validate_broker_request( $broker, 0, $token, $now ) === true, 'valid certificate broker request is accepted' );
check( GHCA_Audit_PDF_Jobs::validate_broker_request( $broker, 0, str_repeat( 'cd', 32 ), $now ) === 'token_mismatch', 'wrong certificate broker token is rejected' );
$broker['broker_used'][0] = true;
check( GHCA_Audit_PDF_Jobs::validate_broker_request( $broker, 0, $token, $now ) === 'replayed', 'certificate broker token cannot be replayed' );
$broker['broker_used'][0] = false;
$broker['brokerable'][0]  = false;
check( GHCA_Audit_PDF_Jobs::validate_broker_request( $broker, 0, $token, $now ) === 'invalid_index', 'non-LearnDash certificate source cannot use the broker' );

// Packet PII stays outside the WordPress document root.
$private_base = wp_normalize_path( dirname( GHCA_Audit_PDF_Jobs::temp_base() ) );
check( 0 !== strpos( $private_base . '/', rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/' ), 'packet storage is outside the WordPress document root' );

// Only one request can own a job phase lock at a time.
$lock_job_id = str_repeat( 'ab', 16 );
$first_lock  = GHCA_Audit_PDF_Jobs::acquire_job_lock( $lock_job_id );
$second_lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $lock_job_id );
check( is_resource( $first_lock ), 'first packet phase acquires its lock' );
check( false === $second_lock, 'concurrent packet phase is rejected' );
GHCA_Audit_PDF_Jobs::release_lock( $first_lock );
$third_lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $lock_job_id );
check( is_resource( $third_lock ), 'packet phase lock is reusable after release' );
GHCA_Audit_PDF_Jobs::release_lock( $third_lock );

// Age-based cleanup must not unlink an owner lock while a packet starts.
$owner_lock_path = GHCA_Audit_PDF_Jobs::packets_base() . '/owner_987654.lock';
$owner_lock = fopen( $owner_lock_path, 'c+b' );
check( is_resource( $owner_lock ) && flock( $owner_lock, LOCK_EX | LOCK_NB ), 'owner lock is held for cleanup test' );
touch( $owner_lock_path, time() - GHCA_Audit_PDF_Jobs::TTL - 60 );
GHCA_Audit_PDF_Jobs::gc();
check( is_file( $owner_lock_path ), 'cleanup preserves aged owner lock file' );
GHCA_Audit_PDF_Jobs::release_lock( $owner_lock );

$private_file = GHCA_Audit_PDF_Jobs::packets_base() . '/permissions-test.pdf';
file_put_contents( $private_file, 'test' );
check( GHCA_Audit_PDF_Jobs::secure_file( $private_file ), 'packet file permissions are restricted' );
unlink( $private_file );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
