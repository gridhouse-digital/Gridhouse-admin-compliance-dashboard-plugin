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
	'can_remind'     => true,
	'email_on'       => true,
	'actor'          => 900,
	'state_file'     => (string) getenv( 'GHCA_BULK_STATE' ),
	'communications' => array(), // id => row, with a 'deliveries' descriptor list (what the service asked for)
	'deliveries'     => array(), // id => delivery row: state, attempt_count, last_error_code, ...
	'cron'           => array(), // pending single events: list of [hook, args, ts]
	'scheduled'      => array(), // every job ever created by wp_schedule_single_event (delivery ids)
	'locks'          => array(), // option-table locks: name => time
	'schedule_fail'  => array(), // employee id => remaining scheduling failures (-1: always)
	'actor_count'    => 0,
	'next_id'        => 1,
);
const GHCA_BULK_PERSIST = array( 'communications', 'deliveries', 'cron', 'scheduled', 'locks', 'schedule_fail', 'actor_count', 'next_id' );
function ghca_bulk_load(): void {
	$f = $GLOBALS['ghca_bulk']['state_file'];
	if ( '' !== $f && is_file( $f ) ) {
		$saved = json_decode( (string) file_get_contents( $f ), true );
		if ( is_array( $saved ) ) {
			foreach ( GHCA_BULK_PERSIST as $k ) {
				$GLOBALS['ghca_bulk'][ $k ] = $saved[ $k ] ?? $GLOBALS['ghca_bulk'][ $k ];
			}
		}
	}
}
function ghca_bulk_save(): void {
	$f = $GLOBALS['ghca_bulk']['state_file'];
	if ( '' !== $f ) {
		$out = array();
		foreach ( GHCA_BULK_PERSIST as $k ) {
			$out[ $k ] = $GLOBALS['ghca_bulk'][ $k ];
		}
		file_put_contents( $f, json_encode( $out ) );
	}
}
function ghca_bulk_reset(): void {
	$GLOBALS['ghca_bulk'] = array_merge( $GLOBALS['ghca_bulk'], array( 'communications' => array(), 'deliveries' => array(), 'cron' => array(), 'scheduled' => array(), 'locks' => array(), 'schedule_fail' => array(), 'actor_count' => 0, 'next_id' => 1, 'can_remind' => true, 'email_on' => true ) );
}
/** Communication rows as a list, in creation order (as Communication History lists them per employee). */
function ghca_bulk_comms(): array {
	return array_values( $GLOBALS['ghca_bulk']['communications'] );
}
/** The delivery row for an employee's only bulk email in this store. */
function ghca_bulk_delivery_for( int $employee ): ?array {
	foreach ( $GLOBALS['ghca_bulk']['deliveries'] as $d ) {
		if ( (int) $d['employee_user_id'] === $employee ) { return $d; }
	}
	return null;
}
/** WordPress cron removes a single event when it runs it; tests call this when they simulate the worker having run. */
function ghca_bulk_job_ran( int $delivery_id ): void {
	$GLOBALS['ghca_bulk']['cron'] = array_values( array_filter( $GLOBALS['ghca_bulk']['cron'], static function ( $e ) use ( $delivery_id ) { return array( $delivery_id ) !== $e[1]; } ) );
}
function ghca_bulk_jobs_for( int $delivery_id ): int {
	return count( array_filter( $GLOBALS['ghca_bulk']['cron'], static function ( $e ) use ( $delivery_id ) { return 'ghca_acd_process_message_delivery' === $e[0] && array( $delivery_id ) === $e[1]; } ) );
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
// Employee whose background job cannot be scheduled: GHCA_BULK_FAIL_ID (default 306), GHCA_BULK_FAIL_TIMES times (-1: always).
$GLOBALS['ghca_bulk']['schedule_fail'] += array( (int) ( getenv( 'GHCA_BULK_FAIL_ID' ) ?: 306 ) => (int) ( getenv( 'GHCA_BULK_FAIL_TIMES' ) ?: -1 ) );

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_message() { return $this->m; }
	public function get_error_code() { return $this->c; }
	public function get_error_data() { return $this->d; }
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

/**
 * In-memory repository with the real lifecycle: separate communication and delivery rows, and the same
 * compare-and-set transitions as GHCA_ACD_Messaging_Repository (requeue_failed: failed -> queued; mark_schedule_failed:
 * queued -> failed; claim_delivery: queued -> processing), plus the option-table scheduling lock.
 */
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
		foreach ( $GLOBALS['ghca_bulk']['communications'] as $c ) {
			if ( $c['idempotency_key'] === $key ) {
				return array( 'communication_id' => $c['communication_id'], 'state' => $c['state'], 'delivery_id' => min( $c['delivery_ids'] ) );
			}
		}
		return null;
	}
	public static function recent_actor_count( int $actor, int $seconds ): int { return (int) $GLOBALS['ghca_bulk']['actor_count']; }
	public static function employee_has_recent_message( int $employee, int $seconds ): bool { return false; }
	public static function mask_email( string $email ): string { $p = explode( '@', $email ); return substr( $p[0], 0, 1 ) . '***@' . ( $p[1] ?? '' ); }
	public static function create_communication( array $communication, array $deliveries ) {
		$existing = self::get_by_idempotency_key( (string) $communication['idempotency_key'] );
		if ( $existing ) { $existing['duplicate'] = true; return $existing; }
		$cid = $GLOBALS['ghca_bulk']['next_id']++;
		$ids = array();
		foreach ( $deliveries as $i => $d ) {
			$did = $cid * 10 + $i;
			$GLOBALS['ghca_bulk']['deliveries'][ $did ] = array( 'id' => $did, 'communication_id' => $cid, 'employee_user_id' => (int) $communication['employee_user_id'], 'channel' => $d['channel'], 'state' => 'queued', 'attempt_count' => 0, 'last_error_code' => null, 'last_error_summary' => null, 'provider_ref' => null );
			$ids[] = $did;
		}
		$GLOBALS['ghca_bulk']['communications'][ $cid ] = $communication + array( 'communication_id' => $cid, 'state' => 'queued', 'delivery_ids' => $ids, 'deliveries' => $deliveries );
		++$GLOBALS['ghca_bulk']['actor_count'];
		return array( 'communication_id' => $cid, 'delivery_id' => $ids[0], 'delivery_ids' => $ids, 'state' => 'queued', 'duplicate' => false );
	}
	public static function get_delivery( int $id ): ?array {
		$d = $GLOBALS['ghca_bulk']['deliveries'][ $id ] ?? null;
		if ( ! $d ) { return null; }
		$c = $GLOBALS['ghca_bulk']['communications'][ $d['communication_id'] ];
		return $d + array( 'actor_user_id' => $c['actor_user_id'], 'subject' => $c['subject'], 'message' => $c['message'], 'communication_state' => $c['state'] );
	}
	private static function set( int $id, array $values ): void {
		$GLOBALS['ghca_bulk']['deliveries'][ $id ] = array_merge( $GLOBALS['ghca_bulk']['deliveries'][ $id ], $values );
		$cid = $GLOBALS['ghca_bulk']['deliveries'][ $id ]['communication_id'];
		$GLOBALS['ghca_bulk']['communications'][ $cid ]['state'] = in_array( $values['state'] ?? '', array( 'accepted', 'sent', 'delivered' ), true ) ? 'sent' : ( $values['state'] ?? $GLOBALS['ghca_bulk']['communications'][ $cid ]['state'] );
	}
	public static function mark_schedule_failed( int $id ): bool {
		if ( 'queued' !== ( $GLOBALS['ghca_bulk']['deliveries'][ $id ]['state'] ?? '' ) ) { return false; }
		self::set( $id, array( 'state' => 'failed', 'last_error_code' => 'queue_schedule_failed', 'last_error_summary' => 'The background email job could not be scheduled.' ) );
		return true;
	}
	public static function requeue_failed( int $id ): bool {
		$d = $GLOBALS['ghca_bulk']['deliveries'][ $id ] ?? null;
		if ( ! $d || 'failed' !== $d['state'] || $d['attempt_count'] >= self::MAX_DELIVERY_ATTEMPTS ) { return false; }
		self::set( $id, array( 'state' => 'queued', 'last_error_code' => null, 'last_error_summary' => null ) );
		return true;
	}
	/** The worker's claim, for tests that move a delivery on: queued -> processing. */
	public static function claim_delivery( int $id ): ?array {
		$d = $GLOBALS['ghca_bulk']['deliveries'][ $id ] ?? null;
		if ( ! $d || 'queued' !== $d['state'] || $d['attempt_count'] >= self::MAX_DELIVERY_ATTEMPTS ) { return null; }
		self::set( $id, array( 'state' => 'processing', 'attempt_count' => $d['attempt_count'] + 1 ) );
		return self::get_delivery( $id );
	}
	/** Test helper: force a state as the worker or provider would have left it. */
	public static function force( int $id, array $values ): void { self::set( $id, $values ); }
	public static function acquire_schedule_lock( int $id, int $ttl = 60 ): bool {
		$name = 'ghca_acd_sched_lock_' . $id;
		$held = $GLOBALS['ghca_bulk']['locks'][ $name ] ?? null;
		if ( null !== $held && $held > time() - $ttl ) { return false; }
		$GLOBALS['ghca_bulk']['locks'][ $name ] = time();
		return true;
	}
	public static function release_schedule_lock( int $id ): void { unset( $GLOBALS['ghca_bulk']['locks'][ 'ghca_acd_sched_lock_' . $id ] ); }
}

/**
 * Fake WordPress cron behind the plugin's real worker. Mocked delivery: jobs are recorded, never run, and no mail
 * function exists. Like core, an identical single event already pending is refused (returns false). Scheduling for an
 * employee listed in schedule_fail fails that many times (-1: always).
 */
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$delivery = (int) ( $args[0] ?? 0 );
	$employee = (int) ( $GLOBALS['ghca_bulk']['deliveries'][ $delivery ]['employee_user_id'] ?? 0 );
	$left     = $GLOBALS['ghca_bulk']['schedule_fail'][ $employee ] ?? 0;
	if ( 0 !== $left ) {
		if ( $left > 0 ) { $GLOBALS['ghca_bulk']['schedule_fail'][ $employee ] = $left - 1; }
		return false;
	}
	if ( false !== wp_next_scheduled( $hook, $args ) ) {
		return false;
	}
	$GLOBALS['ghca_bulk']['cron'][]      = array( $hook, $args, $ts );
	$GLOBALS['ghca_bulk']['scheduled'][] = $delivery;
	return true;
}
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['ghca_bulk']['cron'] as $e ) {
		if ( $e[0] === $hook && $e[1] === $args ) { return $e[2]; }
	}
	return false;
}
require_once __DIR__ . '/../../includes/messaging/class-messaging-worker.php';

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
