<?php
require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
	}
}
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ): bool { return $value instanceof WP_Error; } }

class GHCA_ACD_Settings {
	public static function twilio_config(): array { return array( 'account_sid' => 'AC11111111111111111111111111111111' ); }
}
class GHCA_ACD_Twilio_Provider {
	public static function webhook_auth_token(): string { return 'webhook-test-auth-token'; }
}
class WP_REST_Request {
	public function __construct( private array $params, private string $signature ) {}
	public function get_body_params(): array { return $this->params; }
	public function get_header( $name ): string { return 'x-twilio-signature' === strtolower( $name ) ? $this->signature : ''; }
}

require_once __DIR__ . '/../includes/messaging/class-twilio-webhooks.php';

$fails = 0;
function webhook_signature_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$url = 'https://agency.example/wp-json/ghca-acd/v1/twilio/status';
$params = array(
	'MessageStatus' => 'delivered',
	'AccountSid'    => 'AC11111111111111111111111111111111',
	'MessageSid'    => 'SM22222222222222222222222222222222',
);
$sorted = $params;
ksort( $sorted, SORT_STRING );
$data = $url;
foreach ( $sorted as $name => $value ) { $data .= $name . $value; }
$signature = base64_encode( hash_hmac( 'sha1', $data, 'webhook-test-auth-token', true ) );

$valid = GHCA_ACD_Twilio_Webhooks::verify( new WP_REST_Request( $params, $signature ), $url );
webhook_signature_check( true === $valid, 'valid Twilio form signature is accepted' );
$invalid = GHCA_ACD_Twilio_Webhooks::verify( new WP_REST_Request( $params, base64_encode( random_bytes( 20 ) ) ), $url );
webhook_signature_check( $invalid instanceof WP_Error && 'twilio_signature_invalid' === $invalid->get_error_code(), 'invalid signature is rejected' );
$params['AccountSid'] = 'AC33333333333333333333333333333333';
$wrong_account = GHCA_ACD_Twilio_Webhooks::verify( new WP_REST_Request( $params, $signature ), $url );
webhook_signature_check( $wrong_account instanceof WP_Error && 'twilio_account_mismatch' === $wrong_account->get_error_code(), 'cross-account callback is rejected before state mutation' );

exit( $fails ? 1 : 0 );
