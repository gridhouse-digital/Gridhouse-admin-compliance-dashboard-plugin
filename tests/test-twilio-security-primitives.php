<?php
require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
	}
}
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
	$raw = base64_decode( substr( $encrypted, 3 ), true );
	$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
	$corrupt = 'v1:' . base64_encode( $raw );
	twilio_primitive_check( is_object( GHCA_ACD_Messaging_Secret_Store::decrypt( $corrupt ) ), 'tampered ciphertext fails authentication' );
}

twilio_primitive_check( '+14165551234' === GHCA_ACD_SMS_Phone::normalize( '+1 (416) 555-1234' ), 'North American phone normalizes to E.164' );
twilio_primitive_check( '' === GHCA_ACD_SMS_Phone::normalize( '416-555-1234' ), 'phone without an explicit country code fails closed' );
twilio_primitive_check( false !== strpos( GHCA_ACD_SMS_Phone::mask( '+14165551234' ), '1234' ) && false === strpos( GHCA_ACD_SMS_Phone::mask( '+14165551234' ), '416555' ), 'history masking exposes only the final four digits' );
twilio_primitive_check( 64 === strlen( GHCA_ACD_SMS_Phone::hash( '+14165551234' ) ), 'phone-specific consent uses a keyed non-reversible lookup hash' );

exit( $fails ? 1 : 0 );
