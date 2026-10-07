<?php
require __DIR__ . '/bootstrap.php';

$evidence_test_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-evidence-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $evidence_test_dir, 0700, true );
define( 'GHCA_ACD_PRIVATE_DIR', $evidence_test_dir );
define( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED', true );
define( 'GHCA_ACD_EVIDENCE_ENCRYPTION_KEY', 'test-only-stable-evidence-secret-32-characters-minimum' );

class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }

require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';
require_once __DIR__ . '/../includes/jotform/class-external-evidence-store.php';

$fails = 0;
function evidence_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$training = array( 'employee_user_id' => 41, 'id' => 77, 'source_fingerprint' => str_repeat( 'a', 64 ) );
$relative = 'external-evidence/' . str_repeat( 'b', 16 ) . '/41/77/' . str_repeat( 'a', 64 ) . '-' . str_repeat( 'c', 32 ) . '-0.ghca';
$aad = 'ghca-evidence-v1|41|77|' . str_repeat( 'a', 64 ) . '|0';
$plain = "%PDF-1.4\n% test approved evidence\n";
$encrypt = new ReflectionMethod( GHCA_ACD_External_Evidence_Store::class, 'encrypt_and_write' );
$written = $encrypt->invoke( null, $relative, $plain, $aad );
evidence_check( true === $written, 'approved evidence is encrypted into durable private storage' );

$manifest = array( 'file_index' => 0, 'bytes' => strlen( $plain ), 'sha256' => hash( 'sha256', $plain ), 'storage_path' => $relative );
$destination = $evidence_test_dir . DIRECTORY_SEPARATOR . 'materialized.pdf';
$result = GHCA_ACD_External_Evidence_Store::materialize( $training, $manifest, $destination );
evidence_check( ! is_wp_error( $result ) && file_get_contents( $destination ) === $plain, 'correct actor-bound evidence decrypts and revalidates its digest' );

$bad_manifest = $manifest; $bad_manifest['sha256'] = str_repeat( '0', 64 );
$bad = GHCA_ACD_External_Evidence_Store::materialize( $training, $bad_manifest, $evidence_test_dir . DIRECTORY_SEPARATOR . 'bad.pdf' );
evidence_check( is_wp_error( $bad ) && 'ghca_evidence_digest_failed' === $bad->get_error_code(), 'digest mismatch fails closed' );

$wrong_training = $training; $wrong_training['employee_user_id'] = 42;
$wrong = GHCA_ACD_External_Evidence_Store::materialize( $wrong_training, $manifest, $evidence_test_dir . DIRECTORY_SEPARATOR . 'wrong.pdf' );
evidence_check( is_wp_error( $wrong ) && 'ghca_evidence_digest_failed' === $wrong->get_error_code(), 'evidence ciphertext is bound to the approved employee and training revision context' );

@unlink( $destination );
$encrypted_path = $evidence_test_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
@unlink( $encrypted_path );
$dir = dirname( $encrypted_path );
while ( 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( $evidence_test_dir ) . '/' ) ) { @rmdir( $dir ); $dir = dirname( $dir ); }
@rmdir( $evidence_test_dir );

echo 0 === $fails ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit( 0 === $fails ? 0 : 1 );
