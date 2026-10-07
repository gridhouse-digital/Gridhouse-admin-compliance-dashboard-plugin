<?php
require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ): bool { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'standalone-test-salt-' . $scheme; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); } }
function rest_url( $path ) { return 'https://agency.example/wp-json/' . $path; }
function get_option( $name, $default = '' ) { return $default; }
class WP_REST_Response {
	public function __construct( public $data, public int $status ) {}
}

class GHCA_ACD_Settings {
	const OPTION_SMS_DISCLOSURE_VERSION = 'test_disclosure_version';
	public static function twilio_config(): array { return array( 'account_sid' => 'AC11111111111111111111111111111111', 'messaging_service_sid' => 'MG44444444444444444444444444444444' ); }
}
class GHCA_ACD_Twilio_Provider {
	public static function webhook_auth_token(): string { return 'webhook-test-auth-token'; }
}
class WP_REST_Request {
	public function __construct( private array $params, private string $signature ) {}
	public function get_body_params(): array { return $this->params; }
	public function get_header( $name ): string { return 'x-twilio-signature' === strtolower( $name ) ? $this->signature : ''; }
}
class GHCA_ACD_Messaging_Repository {
	public static array $claims = array();
	public static function claim_webhook_event( $key, $type, $sid, $state ): bool {
		if ( isset( self::$claims[ $key ] ) ) { return false; }
		self::$claims[ $key ] = true;
		return true;
	}
	public static function release_webhook_event( $key ): void { unset( self::$claims[ $key ] ); }
}
class GHCA_ACD_Messaging_Schema {
	public static function consent_events_table(): string { return 'test_consent_events'; }
}
class Test_Consent_DB {
	public array $employee_ids = array();
	public array $events = array();
	public int $insert_id = 0;
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_col( $prepared ): array { return $this->employee_ids; }
	public function insert( $table, $event, $formats ) {
		if ( isset( $this->events[ $event['event_key'] ] ) ) { return false; }
		$this->insert_id = count( $this->events ) + 1;
		$this->events[ $event['event_key'] ] = array( 'id' => $this->insert_id, 'event' => $event );
		return 1;
	}
	public function get_var( $prepared ) { return $this->events[ $prepared[1][0] ]['id'] ?? null; }
}
$wpdb = new Test_Consent_DB();
function get_userdata( $id ) { return $id > 0 ? (object) array( 'ID' => $id ) : false; }
function wp_generate_uuid4() { return 'test-generated-uuid'; }
function current_time( $type, $gmt ) { return '2026-10-06 12:00:00'; }

require_once __DIR__ . '/../includes/messaging/class-twilio-webhooks.php';
require_once __DIR__ . '/../includes/messaging/class-sms-phone.php';
require_once __DIR__ . '/../includes/messaging/class-sms-consent-repository.php';

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

$inbound_url = GHCA_ACD_Twilio_Webhooks::inbound_url();
$inbound = array(
	'AccountSid' => 'AC11111111111111111111111111111111',
	'MessagingServiceSid' => 'MG44444444444444444444444444444444',
	'MessageSid' => 'SM55555555555555555555555555555555',
	'From' => '+14165551234',
	'OptOutType' => 'STOP',
);
function signed_inbound_request( array $params, string $url ): WP_REST_Request {
	$sorted = $params;
	ksort( $sorted, SORT_STRING );
	$data = $url;
	foreach ( $sorted as $name => $value ) { $data .= $name . $value; }
	return new WP_REST_Request( $params, base64_encode( hash_hmac( 'sha1', $data, 'webhook-test-auth-token', true ) ) );
}
$wpdb->employee_ids = array( 10, 20, 10 );
$stop = GHCA_ACD_Twilio_Webhooks::handle_inbound( signed_inbound_request( $inbound, $inbound_url ) );
webhook_signature_check( $stop instanceof WP_REST_Response && 200 === $stop->status && 2 === count( $wpdb->events ), 'STOP records opt-out for each distinct employee sharing the phone' );
$ids = array_column( array_column( $wpdb->events, 'event' ), 'employee_user_id' );
sort( $ids );
webhook_signature_check( array( 10, 20 ) === $ids, 'STOP events identify both employees' );
$repeat = GHCA_ACD_Twilio_Webhooks::handle_inbound( signed_inbound_request( $inbound, $inbound_url ) );
webhook_signature_check( $repeat instanceof WP_REST_Response && 2 === count( $wpdb->events ), 'replayed STOP does not create more consent events' );
$inbound['MessageSid'] = 'SM66666666666666666666666666666666';
$inbound['OptOutType'] = 'START';
$ambiguous = GHCA_ACD_Twilio_Webhooks::handle_inbound( signed_inbound_request( $inbound, $inbound_url ) );
webhook_signature_check( $ambiguous instanceof WP_Error && 'ghca_sms_phone_ambiguous' === $ambiguous->get_error_code() && 2 === count( $wpdb->events ), 'START rejects a shared phone without changing consent' );
$wpdb->employee_ids = array( 10 );
$started = GHCA_ACD_Twilio_Webhooks::handle_inbound( signed_inbound_request( $inbound, $inbound_url ) );
webhook_signature_check( $started instanceof WP_REST_Response && 3 === count( $wpdb->events ), 'START records consent for exactly one matched employee' );

exit( $fails ? 1 : 0 );
