<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$test_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-branding-' . getmypid();
$logo_dir  = $test_root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '08';
mkdir( $logo_dir, 0777, true );
$logo_file = $logo_dir . DIRECTORY_SEPARATOR . 'logo.png';
file_put_contents( $logo_file, 'logo' );

$GLOBALS['ghca_branding_test_root'] = $test_root;
$GLOBALS['ghca_branding_logo_url']  = 'https://example.test/wp-content/uploads/2026/08/logo.png?version=1';

function get_option( $name, $default = array() ) {
	return array( 'logo_url' => $GLOBALS['ghca_branding_logo_url'] );
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}
function esc_url_raw( $value ) {
	return $value;
}
function sanitize_email( $value ) {
	return $value;
}
function wp_upload_dir() {
	return array(
		'baseurl' => 'http://example.test/wp-content/uploads',
		'basedir' => $GLOBALS['ghca_branding_test_root'],
		'error'   => false,
	);
}
function trailingslashit( $value ) {
	return rtrim( $value, "/\\" ) . DIRECTORY_SEPARATOR;
}
function wp_normalize_path( $value ) {
	return str_replace( '\\', '/', $value );
}

require_once __DIR__ . '/../includes/class-branding.php';

$actual = GHCA_Dashboard_Branding::get_pdf_logo_source();
$passed = realpath( $logo_file ) === $actual;
echo $passed ? "PASS: media-library logo resolves to its local file\n" : "FAIL: media-library logo was not resolved\n";

$GLOBALS['ghca_branding_logo_url'] = 'https://external.example/logo.png';
$resolved = new ReflectionProperty( GHCA_Dashboard_Branding::class, 'resolved' );
if ( PHP_VERSION_ID < 80100 ) {
	$resolved->setAccessible( true );
}
$resolved->setValue( null, null );
$external_blocked = '' === GHCA_Dashboard_Branding::get_pdf_logo_source();
echo $external_blocked ? "PASS: external PDF logo source is rejected\n" : "FAIL: external PDF logo source was accepted\n";

unlink( $logo_file );
rmdir( $logo_dir );
rmdir( dirname( $logo_dir ) );
rmdir( $test_root );

echo $passed && $external_blocked ? "\nALL PASS\n" : "\n1 FAILED\n";
exit( $passed && $external_blocked ? 0 : 1 );
