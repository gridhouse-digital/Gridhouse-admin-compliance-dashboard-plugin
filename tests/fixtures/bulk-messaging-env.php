<?php
/**
 * Stand-in WordPress for bulk reminders. Loads the plugin's real GHCA_ACD_Messaging_Service,
 * GHCA_ACD_Message_Template_Renderer and GHCA_ACD_Bulk_Messaging, with an in-memory messaging repository and a
 * worker that only records what it would schedule. Nothing is mailed: wp_mail() throws if anything calls it.
 * State can be persisted to a JSON file (GHCA_BULK_STATE) so separate PHP processes, as in the browser test,
 * share one "database". Synthetic employees only.
 *
 * Employees: 301 Daniel Reyes, 302 Maya Okafor (ready); 303 Jordan Ellis (no valid email); 304 Samuel Boateng
 * (outside the viewer's scope); 305 Priya Nair (inactive, inactive reminders disabled); 306 Tomás Alvarez
 * (ready, but the background job cannot be scheduled); 307 Ivy Kim <script> (ready, name needs escaping).
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['ghca_bulk'] = array(
	'can_remind'   => true,
	'email_on'     => true,
	'actor'        => 900,
	'state_file'   => (string) getenv( 'GHCA_BULK_STATE' ),
	'communications' => array(),
	'scheduled'    => array(),
	'actor_count'  => 0,
	'next_id'      => 1,
);
function ghca_bulk_load(): void {
	$f = $GLOBALS['ghca_bulk']['state_file'];
	if ( '' !== $f && is_file( $f ) ) {
		$saved = json_decode( (string) file_get_contents( $f ), true );
		if ( is_array( $saved ) ) {
			foreach ( array( 'communications', 'scheduled', 'actor_count', 'next_id' ) as $k ) {
				$GLOBALS['ghca_bulk'][ $k ] = $saved[ $k ] ?? $GLOBALS['ghca_bulk'][ $k ];
			}
		}
	}
}
function ghca_bulk_save(): void {
	$f = $GLOBALS['ghca_bulk']['state_file'];
	if ( '' !== $f ) {
		$b = $GLOBALS['ghca_bulk'];
		file_put_contents( $f, json_encode( array( 'communications' => $b['communications'], 'scheduled' => $b['scheduled'], 'actor_count' => $b['actor_count'], 'next_id' => $b['next_id'] ) ) );
	}
}
function ghca_bulk_reset(): void {
	$GLOBALS['ghca_bulk'] = array_merge( $GLOBALS['ghca_bulk'], array( 'communications' => array(), 'scheduled' => array(), 'actor_count' => 0, 'next_id' => 1, 'can_remind' => true, 'email_on' => true ) );
}

$GLOBALS['ghca_bulk_people'] = array(
	301 => array( 'Daniel Reyes', 'daniel.reyes@example.test', true, false ),
	302 => array( 'Maya Okafor', 'maya.okafor@example.test', true, false ),
	303 => array( 'Jordan Ellis', 'not-an-email', true, false ),
	304 => array( 'Samuel Boateng', 'samuel@example.test', false, false ),
	305 => array( 'Priya Nair', 'priya@example.test', true, true ),
	306 => array( 'Tomás Alvarez', 'tomas@example.test', true, false ),
	307 => array( 'Ivy Kim <script>', 'ivy@example.test', true, false ),
);

// The browser test maps the people onto the table preview's ids: JSON id => [name, email, in scope, inactive].
if ( getenv( 'GHCA_BULK_PEOPLE' ) ) {
	$GLOBALS['ghca_bulk_people'] = array();
	foreach ( (array) json_decode( (string) getenv( 'GHCA_BULK_PEOPLE' ), true ) as $pid => $row ) {
		$GLOBALS['ghca_bulk_people'][ (int) $pid ] = $row;
	}
}
$GLOBALS['ghca_bulk_fail_id'] = (int) ( getenv( 'GHCA_BULK_FAIL_ID' ) ?: 306 );

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_message() { return $this->m; }
	public function get_error_code() { return $this->c; }
}
class WP_User { public $ID = 0; public $user_email = ''; public $display_name = ''; public $roles = array( 'subscriber' ); }
final class Ghca_Bulk_Json extends Exception {
	public $payload; public $status;
	public function __construct( array $payload, int $status ) { parent::__construct( 'json' ); $this->payload = $payload; $this->status = $status; }
}
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { return trim( (string) $v ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_salt( $s = '' ) { return 'synthetic-salt'; }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-a%03x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); }
function get_current_user_id() { return $GLOBALS['ghca_bulk']['actor']; }
function get_option( $k, $d = false ) { return 'ghca_messaging_schema_version' === $k ? 99 : $d; }
function get_bloginfo( $k = '' ) { return 'Synthetic Agency'; }
function get_userdata( $id ) {
	$p = $GLOBALS['ghca_bulk_people'][ (int) $id ] ?? null;
	if ( ! $p ) { return false; }
	$u = new WP_User(); $u->ID = (int) $id; $u->user_email = $p[1]; $u->display_name = $p[0];
	return $u;
}
function get_user_meta( $id, $k, $single = false ) { if ( 'first_name' === $k ) { $p = $GLOBALS['ghca_bulk_people'][ (int) $id ] ?? null; return $p ? explode( ' ', $p[0] )[0] : ''; } return ''; }
function check_ajax_referer( $action, $field ) { if ( 'ghca_acd_table' !== $action || 'test' !== ( $_POST[ $field ] ?? '' ) ) { throw new Ghca_Bulk_Json( array( 'nonce' => false ), 403 ); } return 1; }
function wp_send_json_success( $data = null, $status = 200 ) { throw new Ghca_Bulk_Json( array( 'success' => true, 'data' => $data ), (int) $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { throw new Ghca_Bulk_Json( array( 'success' => false, 'data' => $data ), (int) $status ); }
function add_action( ...$a ) {}
function wp_mail( ...$a ) { throw new RuntimeException( 'wp_mail must never be called in tests' ); }

final class GHCA_ACD_Roles {
	public static function user_can_send_reminders(): bool { return $GLOBALS['ghca_bulk']['can_remind']; }
	public static function user_can_view_communication_history(): bool { return true; }
}
final class GHCA_ACD_Settings {
	public static function reminder_email_enabled(): bool { return $GLOBALS['ghca_bulk']['email_on']; }
	public static function allow_inactive_reminders(): bool { return false; }
}
final class GHCA_ACD_User_Report {
	public static function can_view_user( int $id ): bool { return ! empty( $GLOBALS['ghca_bulk_people'][ $id ][2] ); }
}
final class GHCA_ACD_Data_Provider {
	public static function is_user_suspended( int $id ): bool { return ! empty( $GLOBALS['ghca_bulk_people'][ $id ][3] ); }
	public static function get_employee_record( int $id ): array { $p = $GLOBALS['ghca_bulk_people'][ $id ] ?? null; return $p ? array( 'user_id' => $id, 'name' => $p[0], 'due_date_label' => 'November 30, 2026' ) : array(); }
	public static function get_page_url( string $s, string $f ): string { return 'https://example.test' . $f; }
}
final class GHCA_Dashboard_Branding { public static function get(): array { return array( 'org_name' => 'Synthetic Agency' ); } }
final class GHCA_ACD_Messaging_Schema {
	const OPTION_VERSION = 'ghca_messaging_schema_version';
	const VERSION = 1;
	public static function tables_exist(): bool { return true; }
}
final class GHCA_ACD_SMS_Phone {
	public static function for_user( int $id ): string { return ''; }
	public static function mask( string $p ): string { return ''; }
	public static function hash( string $p ): string { return ''; }
}
final class GHCA_ACD_Twilio_Provider { public static function configuration_ready( bool $x ) { return new WP_Error( 'twilio_not_configured', 'SMS is not configured.' ); } }
final class GHCA_ACD_SMS_Consent_Repository { public static function has_current_consent( int $id, string $p ): bool { return false; } }

/** In-memory repository: the methods the service and the bulk class call, with the real duplicate fence. */
final class GHCA_ACD_Messaging_Repository {
	const MAX_DELIVERY_ATTEMPTS = 3;
	public static function get_active_templates(): array {
		return array(
			array( 'id' => 1, 'name' => 'Overdue training', 'subject_template' => 'Training overdue, [employee_first_name]', 'message_template' => "Hi [employee_first_name],\nYour training was due [due_date]. Please finish it at [portal_url].\n[agency_name]", 'default_urgency' => 'important', 'allowed_channels' => 'email,sms,email_sms', 'version' => 3 ),
			array( 'id' => 2, 'name' => 'SMS only notice', 'subject_template' => '', 'message_template' => 'Check your training.', 'default_urgency' => 'normal', 'allowed_channels' => 'sms', 'version' => 1 ),
		);
	}
	public static function get_template( int $id, bool $active = true ): ?array {
		foreach ( self::get_active_templates() as $t ) { if ( (int) $t['id'] === $id ) { return $t; } }
		return null;
	}
	public static function get_by_idempotency_key( string $key ): ?array {
		foreach ( $GLOBALS['ghca_bulk']['communications'] as $c ) { if ( $c['idempotency_key'] === $key ) { return $c; } }
		return null;
	}
	public static function recent_actor_count( int $actor, int $seconds ): int { return (int) $GLOBALS['ghca_bulk']['actor_count']; }
	public static function employee_has_recent_message( int $employee, int $seconds ): bool { return false; }
	public static function mask_email( string $email ): string { $p = explode( '@', $email ); return substr( $p[0], 0, 1 ) . '***@' . ( $p[1] ?? '' ); }
	public static function create_communication( array $communication, array $deliveries ) {
		$existing = self::get_by_idempotency_key( (string) $communication['idempotency_key'] );
		if ( $existing ) { $existing['duplicate'] = true; return $existing; }
		$id = $GLOBALS['ghca_bulk']['next_id']++;
		$row = $communication + array( 'communication_id' => $id, 'delivery_id' => $id * 10, 'delivery_ids' => array( $id * 10 ), 'deliveries' => $deliveries, 'duplicate' => false );
		$GLOBALS['ghca_bulk']['communications'][] = $row;
		++$GLOBALS['ghca_bulk']['actor_count'];
		return $row;
	}
	public static function mark_schedule_failed( int $delivery_id ): void { $GLOBALS['ghca_bulk']['schedule_failed'][] = $delivery_id; }
}
/** Mocked delivery: records the job it would schedule; never delivers. Employee 306's job cannot be scheduled. */
final class GHCA_ACD_Messaging_Worker {
	public static function schedule( int $delivery_id ): bool {
		foreach ( $GLOBALS['ghca_bulk']['communications'] as $c ) {
			if ( (int) $c['delivery_id'] === $delivery_id && $GLOBALS['ghca_bulk_fail_id'] === (int) $c['employee_user_id'] ) { return false; }
		}
		$GLOBALS['ghca_bulk']['scheduled'][] = $delivery_id;
		return true;
	}
}

require_once __DIR__ . '/../../includes/messaging/class-message-template-renderer.php';
require_once __DIR__ . '/../../includes/messaging/class-messaging-service.php';
require_once __DIR__ . '/../../includes/messaging/class-bulk-messaging.php';

/** Runs one bulk AJAX action with a POST body; returns the JSON payload and status. */
function ghca_bulk_ajax( string $action, array $post ): array {
	$_POST = $post;
	try {
		if ( 'ghca_acd_bulk_message_context' === $action ) { GHCA_ACD_Bulk_Messaging::ajax_context(); }
		if ( 'ghca_acd_bulk_message_preview' === $action ) { GHCA_ACD_Bulk_Messaging::ajax_preview(); }
		if ( 'ghca_acd_bulk_message_send' === $action ) { GHCA_ACD_Bulk_Messaging::ajax_send(); }
	} catch ( Ghca_Bulk_Json $e ) {
		return array( 'status' => $e->status, 'payload' => $e->payload );
	}
	return array( 'status' => 0, 'payload' => array() );
}
