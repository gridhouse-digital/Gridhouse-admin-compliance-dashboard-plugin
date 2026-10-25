<?php
require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}
class GHCA_ACD_Settings {
	const OPTION_TWILIO_API_SECRET = 'test_api_secret';
	const OPTION_TWILIO_WEBHOOK_AUTH_TOKEN = 'test_webhook_token';
}
$test_options = array();
$test_settings_errors = array();
function get_option( $name, $default = '' ) { global $test_options; return $test_options[ $name ] ?? $default; }
function add_settings_error( $setting, $code, $message, $type ) { global $test_settings_errors; $test_settings_errors[] = $code; }
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'standalone-test-salt-' . $scheme; }
}
if ( ! defined( 'GHCA_ACD_TWILIO_ENCRYPTION_KEY' ) ) {
	define( 'GHCA_ACD_TWILIO_ENCRYPTION_KEY', 'standalone-test-only-key-with-more-than-32-randomish-characters' );
}

require_once __DIR__ . '/../includes/messaging/class-messaging-secret-store.php';
require_once __DIR__ . '/../includes/messaging/class-sms-phone.php';

$fails = 0;
function twilio_primitive_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$secret    = 'RK-secret-value-that-must-not-be-plaintext';
$encrypted = GHCA_ACD_Messaging_Secret_Store::encrypt( $secret );
twilio_primitive_check( is_string( $encrypted ) && 0 === strpos( $encrypted, 'v1:' ) && false === strpos( $encrypted, $secret ), 'API secrets are stored only as authenticated ciphertext' );
$decrypted = is_string( $encrypted ) ? GHCA_ACD_Messaging_Secret_Store::decrypt( $encrypted ) : null;
twilio_primitive_check( $secret === $decrypted, 'authenticated ciphertext decrypts with the same host key' );
if ( is_string( $encrypted ) ) {
	$test_options[ GHCA_ACD_Settings::OPTION_TWILIO_API_SECRET ] = $encrypted;
	twilio_primitive_check( $encrypted === GHCA_ACD_Messaging_Secret_Store::sanitize_api_secret( $encrypted ), 'repeated API secret sanitization preserves ciphertext' );
	twilio_primitive_check( $encrypted === GHCA_ACD_Messaging_Secret_Store::sanitize_api_secret( '' ), 'blank API secret preserves stored ciphertext' );
	$test_options[ GHCA_ACD_Settings::OPTION_TWILIO_WEBHOOK_AUTH_TOKEN ] = $encrypted;
	twilio_primitive_check( $encrypted === GHCA_ACD_Messaging_Secret_Store::sanitize_webhook_token( $encrypted ), 'repeated webhook token sanitization preserves ciphertext' );
	$test_options[ GHCA_ACD_Settings::OPTION_TWILIO_API_SECRET ] = '';
	twilio_primitive_check( $encrypted === GHCA_ACD_Messaging_Secret_Store::sanitize_api_secret( $encrypted ), 'valid ciphertext remains stable when passed to sanitizer again' );
}

if ( is_string( $encrypted ) ) {
	$raw = base64_decode( substr( $encrypted, 3 ), true );
	$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
	$corrupt = 'v1:' . base64_encode( $raw );
	twilio_primitive_check( is_object( GHCA_ACD_Messaging_Secret_Store::decrypt( $corrupt ) ), 'tampered ciphertext fails authentication' );
	$test_options[ GHCA_ACD_Settings::OPTION_TWILIO_API_SECRET ] = $encrypted;
	twilio_primitive_check( $encrypted === GHCA_ACD_Messaging_Secret_Store::sanitize_api_secret( $corrupt ) && count( $test_settings_errors ) === 1, 'tampered ciphertext cannot replace the saved secret' );
}

twilio_primitive_check( '+14165551234' === GHCA_ACD_SMS_Phone::normalize( '+1 (416) 555-1234' ), 'North American phone normalizes to E.164' );
twilio_primitive_check( '' === GHCA_ACD_SMS_Phone::normalize( '416-555-1234' ), 'phone without an explicit country code fails closed' );
twilio_primitive_check( false !== strpos( GHCA_ACD_SMS_Phone::mask( '+14165551234' ), '1234' ) && false === strpos( GHCA_ACD_SMS_Phone::mask( '+14165551234' ), '416555' ), 'history masking exposes only the final four digits' );
twilio_primitive_check( 64 === strlen( GHCA_ACD_SMS_Phone::hash( '+14165551234' ) ), 'phone-specific consent uses a keyed non-reversible lookup hash' );

exit( $fails ? 1 : 0 );
