<?php
/** Behavioural test: administrator manual entry stores, verifies and materializes exactly like Jotform evidence. */
require __DIR__ . '/bootstrap.php';

$manual_test_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-manual-evidence-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $manual_test_dir, 0700, true );
define( 'GHCA_ACD_PRIVATE_DIR', $manual_test_dir );
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
function wp_normalize_path( $value ) { return str_replace( chr( 92 ), "/", $value ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $value ) ); }

require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';
require_once __DIR__ . '/../includes/jotform/class-external-evidence-store.php';

$fails = 0;
function manual_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$plain = "%PDF-1.4\n% manual entry test certificate\n";
$fingerprint = GHCA_ACD_External_Evidence_Store::manual_fingerprint( 41, $plain );
manual_check( 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) && $fingerprint === GHCA_ACD_External_Evidence_Store::manual_fingerprint( 41, $plain ) && $fingerprint !== GHCA_ACD_External_Evidence_Store::manual_fingerprint( 42, $plain ), 'manual fingerprint is deterministic and bound to the employee' );

$training = array( 'id' => 91, 'form_id' => 'manual', 'employee_user_id' => 41, 'submission_id' => str_repeat( 'c', 32 ), 'source_fingerprint' => $fingerprint );
$manifest = GHCA_ACD_External_Evidence_Store::store_manual_training( $training, $plain, 'Scanned certificate.pdf' );
manual_check( is_array( $manifest ) && 1 === count( $manifest ) && 1 === preg_match( '#^external-evidence/[a-f0-9]{16}/41/91/[a-f0-9]{64}-c{32}-0\.ghca$#', (string) $manifest[0]['storage_path'] ) && hash( 'sha256', $plain ) === $manifest[0]['sha256'] && strlen( $plain ) === (int) $manifest[0]['bytes'] && 'application/pdf' === $manifest[0]['media_type'] && 'Scanned-certificate.pdf' === $manifest[0]['file_name'], 'manual certificate is encrypted into the standard external-evidence path with a capture-shaped manifest' );

manual_check( true === GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $manifest ), 'stored manual manifest re-verifies without writing plaintext' );

$tampered = $manifest; $tampered[0]['sha256'] = str_repeat( '0', 64 );
$bad = GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $tampered );
manual_check( is_wp_error( $bad ) && 'ghca_evidence_digest_failed' === $bad->get_error_code(), 'tampered manifest digest fails closed' );

$short = $manifest; $short[0]['bytes'] = strlen( $plain ) - 1;
$short_result = GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $short );
manual_check( is_wp_error( $short_result ) && 'ghca_evidence_digest_failed' === $short_result->get_error_code(), 'byte-count drift fails closed' );

$other = $training; $other['employee_user_id'] = 42;
$wrong = GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $other, $manifest );
manual_check( is_wp_error( $wrong ) && 'ghca_evidence_digest_failed' === $wrong->get_error_code(), 'manual evidence ciphertext is bound to the entered employee and training id' );

$destination = $manual_test_dir . DIRECTORY_SEPARATOR . 'materialized.pdf';
$materialized = GHCA_ACD_External_Evidence_Store::materialize( $training, $manifest[0], $destination );
manual_check( ! is_wp_error( $materialized ) && file_get_contents( $destination ) === $plain, 'packet materialization reads manual evidence exactly like Jotform evidence' );

$jotform_row = $training; $jotform_row['form_id'] = '123456';
$refused = GHCA_ACD_External_Evidence_Store::store_manual_training( $jotform_row, $plain, 'x.pdf' );
manual_check( is_wp_error( $refused ) && 'ghca_manual_training_invalid' === $refused->get_error_code(), 'only reserved manual rows can receive an administrator-stored certificate' );

$not_pdf = GHCA_ACD_External_Evidence_Store::store_manual_training( $training, 'not a pdf', 'x.pdf' );
manual_check( is_wp_error( $not_pdf ) && 'ghca_manual_training_invalid' === $not_pdf->get_error_code(), 'un-normalized bytes are refused before encryption' );

$empty = GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, array() );
manual_check( is_wp_error( $empty ) && 'ghca_evidence_missing' === $empty->get_error_code(), 'a manual row without a stored manifest cannot pass verification' );

GHCA_ACD_External_Evidence_Store::remove_files( array_column( $manifest, 'storage_path' ) );
manual_check( ! is_file( wp_normalize_path( $manual_test_dir . '/' . $manifest[0]['storage_path'] ) ), 'rejecting a manual entry can remove its stored certificate through the shared cleanup path' );
$missing = GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $manifest );
manual_check( is_wp_error( $missing ) && 'ghca_evidence_file_missing' === $missing->get_error_code(), 'verification reports a removed certificate as missing' );

@unlink( $destination );
$dir = dirname( wp_normalize_path( $manual_test_dir . '/' . $manifest[0]['storage_path'] ) );
while ( 0 === strpos( $dir, wp_normalize_path( $manual_test_dir ) . '/' ) ) { @rmdir( $dir ); $dir = dirname( $dir ); }
@rmdir( $manual_test_dir );

echo 0 === $fails ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit( 0 === $fails ? 0 : 1 );
