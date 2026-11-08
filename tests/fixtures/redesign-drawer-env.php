<?php
/**
 * Stand-in WordPress for the employee drawer tests. It does not load the real data provider: the drawer
 * takes its employee record from GHCA_ACD_Data_Provider::get_employee_record(), which needs LearnDash, so
 * this file defines a small provider that returns synthetic records instead. The real
 * GHCA_ACD_AJAX handler is loaded and run. Not a WordPress screen; not live data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['ghca_env'] = array(
	'now'            => strtotime( '2026-11-01 12:00:00 UTC' ),
	'can_view'       => true,
	'can_docs'       => true,
	'can_comms'      => true,
	'can_admin'      => true,
	'can_remind'     => true,
	'can_view_user'  => true,
	'suspended'      => false,
	'reviewed_at'    => 0,
	'employment'     => '',
	'bad_nonce'      => false,
	'redesign'       => true,
	'jotform'        => false,
	'messaging'      => false,
	'secure'         => true,
	'can_review_ext' => true,
);

function __( $t, $d = '' ) { return $t; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $t, $d = '' ) { echo htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $t, $d = '' ) { echo htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
/** Like WordPress: a scheme outside the allowed list empties the URL. */
function esc_url( $u, $p = null ) {
	$u = (string) $u;
	if ( preg_match( '#^\s*([a-z][a-z0-9+.\-]*):#i', $u, $m ) && ! in_array( strtolower( $m[1] ), $p ?: array( 'http', 'https', 'mailto', 'tel' ), true ) ) {
		return '';
	}
	return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
}
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { private $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function mysql2date( $f, $d, $t = true ) { return gmdate( 'M j, Y', strtotime( $d . ' UTC' ) ); }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, null === $ts ? $GLOBALS['ghca_env']['now'] : $ts ); }
function current_time( $type, $gmt = 0 ) { return $GLOBALS['ghca_env']['now']; }
function get_option( $k, $d = false ) {
	if ( 'ghca_acd_messaging_schema_version' === $k && ! empty( $GLOBALS['ghca_env']['messaging'] ) ) {
		return 1;
	}
	if ( 'ghca_audit_mapping' === $k ) {
		return array(
			101 => array( 'credit_hours' => 5.5, 'odp_category' => 'cat_a', 'is_orientation' => 0 ),
			102 => array( 'credit_hours' => 1, 'odp_category' => '', 'is_orientation' => 0 ),
			103 => array( 'credit_hours' => 0.75, 'odp_category' => 'cat_a', 'is_orientation' => 1 ),
		);
	}
	return $d;
}
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['ghca_env']['caps'] ?? array(), true ); }
function get_edit_user_link( $id ) { return 'https://example.test/wp-admin/user-edit.php?user_id=' . (int) $id; }
function wp_nonce_url( $url, $action = -1 ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=n'; }
function get_the_title( $id = 0 ) { return 7 === (int) $id ? 'DSP — New Hire' : 'Group ' . (int) $id; }
function get_userdata( $id ) { if ( (int) $id <= 0 ) { return false; } return (object) array( 'ID' => $id, 'roles' => array( 'subscriber' ), 'user_registered' => '2026-10-14 21:11:00', 'display_name' => 42 === (int) $id ? 'Jordan Ellis' : 'Synthetic Admin ' . $id ); }
function wp_roles() { return new class() { public function get_names() { return array( 'subscriber' => 'Direct Support Professional (DSP)' ); } }; }
function get_user_meta( $id, $key, $single = false ) {
	if ( 'ghca_acd_reviewed_at' === $key ) {
		return $GLOBALS['ghca_env']['reviewed_at'];
	}
	if ( 'ghca_first_service_date' === $key ) {
		return $GLOBALS['ghca_env']['first_service'] ?? '2026-10-20';
	}
	if ( 'ghca_worked_alone_date' === $key ) {
		return $GLOBALS['ghca_env']['worked_alone'] ?? '';
	}
	if ( 'ghca_acd_verified_employment' === $key ) {
		return $GLOBALS['ghca_env']['verified'] ?? '';
	}
	return '';
}
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) { $t = strtotime( $s . ' UTC' ); return 'U' === $f ? (string) $t : gmdate( $f, $t ); }
function wp_get_current_user() { return (object) array( 'user_login' => 'synthetic-admin', 'roles' => array() ); }
function is_user_logged_in() { return true; }
class WP_Post {}
function is_singular() { return true; }
function get_post() { return new WP_Post(); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function add_query_arg( $k, $v = null, $url = null ) {
	if ( is_array( $k ) ) {
		return $v . ( false === strpos( (string) $v, '?' ) ? '?' : '&' ) . http_build_query( $k );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( (string) $v );
}

final class Ghca_Env_Json extends Exception {
	public $payload;
	public $status;
	public function __construct( array $payload, int $status ) {
		parent::__construct( 'json' );
		$this->payload = $payload;
		$this->status  = $status;
	}
}
function check_ajax_referer( $action, $field = '_wpnonce' ) {
	if ( $GLOBALS['ghca_env']['bad_nonce'] ) {
		throw new Ghca_Env_Json( array( 'success' => false, 'data' => 'bad nonce' ), 403 );
	}
	return 1;
}
function wp_send_json_success( $data = null, $status = 200 ) { throw new Ghca_Env_Json( array( 'success' => true, 'data' => $data ), (int) $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { throw new Ghca_Env_Json( array( 'success' => false, 'data' => $data ), (int) $status ); }

final class GHCA_Admin_Compliance_Dashboard {
	public static function page_uses_dashboard( WP_Post $post ): bool { return true; }
}
final class GHCA_ACD_Settings {
	public static function ui_redesign_enabled(): bool { return $GLOBALS['ghca_env']['redesign']; }
}
final class GHCA_ACD_Roles {
	public static function user_can_view(): bool { return $GLOBALS['ghca_env']['can_view']; }
	public static function user_can_view_employee_documents(): bool { return $GLOBALS['ghca_env']['can_docs']; }
	public static function user_can_view_communication_history(): bool { return $GLOBALS['ghca_env']['can_comms']; }
	public static function user_can_edit_records(): bool { return $GLOBALS['ghca_env']['can_admin']; }
	public static function user_can_send_reminders(): bool { return $GLOBALS['ghca_env']['can_remind']; }
	public static function user_can_review_external_training(): bool { return $GLOBALS['ghca_env']['can_review_ext']; }
	public static function user_can_manage_users(): bool { return ! empty( $GLOBALS['ghca_env']['can_manage'] ); }
	public static function user_can_manage_announcements(): bool { return ! empty( $GLOBALS['ghca_env']['can_announce'] ); }
}
final class GHCA_ACD_User_Report {
	// Out-of-scope people (slice 8 report tests) are listed in 'out_of_scope'; the check is the server's, per request.
	public static function can_view_user( int $id ): bool { return $GLOBALS['ghca_env']['can_view_user'] && ! in_array( $id, $GLOBALS['ghca_env']['out_of_scope'] ?? array(), true ); }
}
final class GHCA_ACD_Shortcodes {
	public static function can_view_dashboard(): bool { return $GLOBALS['ghca_env']['can_view']; }
	public static function build_mailto_reminder( string $email, string $course = '' ): string { return 'mailto:' . rawurlencode( $email ) . '?subject=Compliance%20training%20reminder'; }
}
final class GHCA_ACD_FluentCRM {
	public static function build_reminder_url( int $id, string $email, string $course = '' ): string { return 'https://example.test/wp-admin/admin-post.php?action=ghca_acd_log_reminder&user_id=' . $id; }
	public static function get_contact_admin_url( string $email ): string { return $GLOBALS['ghca_env']['crm'] ?? false ? 'https://example.test/wp-admin/admin.php?page=fluentcrm-admin#/subscribers/9' : ''; }
}
final class GHCA_Audit_Mapping {
	const OPTION_NAME = 'ghca_audit_mapping';
	public static function get_odp_categories(): array { return array( 'cat_a' => 'Rights and abuse prevention' ); }
}
final class GHCA_Audit_Calculator {
	public static function completion_timestamp( string $v ) { return strtotime( $v . ' 00:00:00 UTC' ); }
}
/**
 * Jotform, evidence and messaging collaborators. Tables report absent unless a test turns them on, so the legacy
 * drawer (whose golden output was captured without them) renders exactly as before. The legacy fragment renderers
 * return fixed text: this test is about the drawer's own markup.
 */
final class GHCA_ACD_Jotform_Schema {
	public static function tables_exist(): bool { return ! empty( $GLOBALS['ghca_env']['jotform'] ); }
}
final class GHCA_ACD_Jotform_Repository {
	public static function employee_record_count( int $u ): int { return $GLOBALS['ghca_env']['doc_total'] ?? 5; }
	public static function training_for_employee( int $u, int $n ): array { return $GLOBALS['ghca_env']['training'] ?? array( array( 'id' => 1 ) ); }
	public static function catalog( bool $active ): array { return $GLOBALS['ghca_env']['catalog'] ?? array( array( 'id' => 11, 'title' => 'CPR / First Aid', 'credit_hours' => '2' ), array( 'id' => 12, 'title' => '<b>Lift & transfer</b>', 'credit_hours' => '1.5' ) ); }
	public static function manual_training_for_employee( int $u, int $n ): array { return $GLOBALS['ghca_env']['manual_entries'] ?? array( array( 'id' => 9 ) ); }
}
final class GHCA_ACD_External_Evidence_Store {
	public static function readiness() { return $GLOBALS['ghca_env']['evidence_error'] ?? true ? ( ( $GLOBALS['ghca_env']['evidence_error'] ?? false ) ? new WP_Error( 'x', (string) $GLOBALS['ghca_env']['evidence_error'] ) : true ) : true; }
}
final class GHCA_ACD_Jotform_UI {
	public static function render_recent_documents( int $u ): string { return '<div class="stub-documents" data-user="' . $u . '">documents fragment</div>'; }
	public static function render_manual_entry_form( int $u ): string { return '<form class="stub-manual" data-user="' . $u . '">manual entry fragment</form>'; }
	public static function can_show_documents( int $u ): bool { return ! empty( $GLOBALS['ghca_env']['secure'] ) && GHCA_ACD_Roles::user_can_view_employee_documents() && GHCA_ACD_User_Report::can_view_user( $u ); }
	public static function can_show_manual_entry( int $u ): bool { return ! empty( $GLOBALS['ghca_env']['secure'] ) && GHCA_ACD_Roles::user_can_review_external_training() && $u > 0 && GHCA_ACD_User_Report::can_view_user( $u ) && GHCA_ACD_Jotform_Schema::tables_exist(); }
	public static function external_training_summary( int $u ): string { return '1 approved, 1 pending'; }
	public static function employee_record_rows( int $u, string $search = '' ): array {
		return $GLOBALS['ghca_env']['records'] ?? array(
			array( 'kind' => 'jotform', 'id' => 7, 'name' => 'Onboarding form.pdf', 'context' => 'Onboarding', 'media_type' => 'application/pdf', 'timestamp' => strtotime( '2026-10-20 10:00:00 UTC' ), 'status' => 'Awaiting review', 'status_key' => 'pending', 'viewable' => true ),
			array( 'kind' => 'manual', 'id' => 9, 'name' => 'Red Cross card.jpg', 'context' => 'CPR / First Aid', 'media_type' => 'image/jpeg', 'timestamp' => strtotime( '2026-10-30 10:00:00 UTC' ), 'status' => 'Approved', 'status_key' => 'approved', 'viewable' => true ),
			array( 'kind' => 'jotform', 'id' => 8, 'name' => 'Handbook sign-off.pdf', 'context' => '', 'media_type' => 'application/pdf', 'timestamp' => 0, 'status' => 'General document', 'status_key' => '', 'viewable' => false ),
		);
	}
}
final class GHCA_ACD_Messaging_Schema {
	const OPTION_VERSION = 'ghca_acd_messaging_schema_version';
	const VERSION        = 1;
	public static function tables_exist(): bool { return ! empty( $GLOBALS['ghca_env']['messaging'] ); }
}
final class GHCA_ACD_Messaging_Repository {
	const MAX_DELIVERY_ATTEMPTS = 3;
	/** Synthetic rows filtered like the real repository: by channel, status and search, then paged by message. */
	public static function history_rows( array $f = array() ): array {
		$rows = $GLOBALS['ghca_env']['history'] ?? array();
		if ( ! empty( $f['channel'] ) ) { $rows = array_values( array_filter( $rows, static function ( $r ) use ( $f ) { return $r['channel'] === $f['channel']; } ) ); }
		if ( ! empty( $f['status'] ) ) { $rows = array_values( array_filter( $rows, static function ( $r ) use ( $f ) { return $r['delivery_state'] === $f['status']; } ) ); }
		if ( ! empty( $f['search'] ) ) { $rows = array_values( array_filter( $rows, static function ( $r ) use ( $f ) { return false !== stripos( $r['subject'] . ' ' . $r['message'], $f['search'] ); } ) ); }
		return $rows;
	}
	public static function history_count( int $u, array $f = array() ): int {
		if ( isset( $GLOBALS['ghca_env']['hist_total'] ) && empty( $f ) ) { return (int) $GLOBALS['ghca_env']['hist_total']; }
		return count( array_unique( array_column( self::history_rows( $f ), 'communication_id' ) ) );
	}
	public static function history_for_employee( int $u, int $page, int $per, array $f = array() ): array {
		$rows = self::history_rows( $f );
		$ids  = array_slice( array_values( array_unique( array_column( $rows, 'communication_id' ) ) ), ( max( 1, $page ) - 1 ) * $per, $per );
		return array_values( array_filter( $rows, static function ( $r ) use ( $ids ) { return in_array( $r['communication_id'], $ids, true ); } ) );
	}
	public static function history_stats( int $u ): array {
		$rows = $GLOBALS['ghca_env']['history'] ?? array();
		return array( 'total' => count( array_unique( array_column( $rows, 'communication_id' ) ) ), 'email' => count( array_filter( $rows, static function ( $r ) { return 'email' === $r['channel']; } ) ), 'sms' => count( array_filter( $rows, static function ( $r ) { return 'sms' === $r['channel']; } ) ), 'failed' => count( array_filter( $rows, static function ( $r ) { return 'failed' === $r['delivery_state']; } ) ) );
	}
	public static function mask_email( string $email ): string { $p = explode( '@', $email, 2 ); return 2 === count( $p ) && '' !== $p[0] ? mb_substr( $p[0], 0, 1 ) . '***@' . $p[1] : '***'; }
	public static function get_template( int $id, bool $active = true ) { return 7 === $id ? array( 'id' => 7, 'name' => 'Course due soon' ) : null; }
}
final class GHCA_ACD_Message_Template_Renderer {
	public static function normalize_urgency( string $u ): string { return 'high' === $u ? 'high' : 'normal'; }
}
final class GHCA_ACD_Messaging_UI {
	public static function render_history_section( int $u ): string { return '<div class="stub-history" data-user="' . $u . '">history fragment</div>'; }
	public static function delivery_label( string $state, string $channel = 'email' ): string {
		if ( 'accepted' === $state && 'sms' === $channel ) { return 'Accepted by Twilio'; }
		$l = array( 'queued' => 'Queued', 'processing' => 'Sending', 'accepted' => 'Accepted by email transport', 'sent' => 'Sent', 'delivered' => 'Delivered', 'undelivered' => 'Undelivered', 'failed' => 'Failed', 'suppressed' => 'Suppressed' );
		return $l[ $state ] ?? ucfirst( $state );
	}
}
final class GHCA_ACD_OLTL_UI {
	public static function render_employee_card( int $u ): string { return $GLOBALS['ghca_env']['oltl'] ?? false ? '<section class="stub-oltl">oltl card</section>' : ''; }
}

/** Synthetic provider: only the calls the drawer makes. */
final class GHCA_ACD_Data_Provider {
	public static function get_employee_record( int $user_id ): array {
		// Slice 8 report: a record per synthetic person when the test names people; otherwise the drawer's employee.
		if ( isset( $GLOBALS['ghca_env']['people'][ $user_id ] ) ) {
			$p = $GLOBALS['ghca_env']['people'][ $user_id ];
			return array_merge( ghca_env_drawer_employee(), array( 'user_id' => $user_id, 'name' => $p[0], 'email' => $p[1], 'group' => $p[2], 'progress_label' => '40%', 'last_activity_label' => 'Oct 29, 2026', 'new_hire' => $p[3] ?? array() ) );
		}
		return $GLOBALS['ghca_env']['employee'] ?? array();
	}
	public static function get_page_url( string $slug, string $fallback ): string { return ( $GLOBALS['ghca_env']['base'] ?? 'https://example.test' ) . $fallback; }
	public static function get_employee_user_ids(): array { return array_keys( $GLOBALS['ghca_env']['people'] ?? array( 42 => 1 ) ); }
	public static function get_active_employee_user_ids(): array { return array_values( array_filter( self::get_employee_user_ids(), static function ( $id ) { return empty( $GLOBALS['ghca_env']['people'][ $id ][4] ); } ) ); }
	public static function get_user_primary_group_id( int $user_id ): int { return 7; }
	public static function format_activity_label( int $ts ): string { return $ts > 0 ? gmdate( 'M j, Y', $ts ) : 'No activity'; }
	public static function get_aggregate(): array { return array( 'total_employees' => count( self::get_active_employee_user_ids() ), 'certificates_issued' => 8, 'certificates_missing' => 1, 'total_inactive_employees' => 1 ); }
	public static function is_user_suspended( int $user_id ): bool { return $GLOBALS['ghca_env']['suspended']; }
	public static function employment_type_label( int $user_id ): string { return $GLOBALS['ghca_env']['employment'] ?: 'Not set'; }
	public static function get_user_report_url( int $user_id ): string { return 'https://example.test/user-report?user_id=' . $user_id; }
	public static function get_user_full_name( int $id ): string { return $GLOBALS['ghca_env']['people'][ $id ][0] ?? 'Synthetic Admin'; }
	public static function employment_type( int $user_id ): string { return $GLOBALS['ghca_env']['employment_key'] ?? ''; }
	public static function employment_type_options( string $current = '', string $label = '' ): array { return array( 'full_time' => 'Full-time', 'part_time' => 'Part-time', 'per_diem' => 'Per <diem>' ); }
}

require_once __DIR__ . '/../../includes/odp/class-odp-contract.php';
require_once __DIR__ . '/../../includes/odp/class-odp-ui.php';
require_once __DIR__ . '/../../includes/class-redesign.php';
require_once __DIR__ . '/../../includes/class-redesign-tables.php';
require_once __DIR__ . '/../../includes/class-redesign-drawer.php';
require_once __DIR__ . '/../../includes/class-redesign-edit.php';
require_once __DIR__ . '/../../includes/class-redesign-reminder.php';
require_once __DIR__ . '/../../includes/class-ajax-handlers.php';

/** A record in the shape build_employee_record() returns: one overdue, one in progress, one expiring, two complete. */
function ghca_env_drawer_employee(): array {
	$now  = $GLOBALS['ghca_env']['now'];
	$base = $GLOBALS['ghca_env']['base'] ?? 'https://example.test';
	$c    = static function ( int $id, string $title, string $status, bool $done, int $pct, array $extra = array() ) use ( $base ): array {
		return array_merge( array( 'id' => $id, 'title' => $title, 'status' => $status, 'completed' => $done, 'progress' => $pct, 'url' => $base . '/course/' . $id, 'compliance_state' => '' ), $extra );
	};
	return array(
		'user_id'        => 42,
		'name'           => 'Jordan Ellis',
		'email'          => 'jordan.ellis@example.test',
		'group'          => 'DSP — New Hire',
		'status_slug'    => 'overdue',
		'status_label'   => 'Overdue',
		'progress_pct'   => 40,
		'due_timestamp'  => $now + 13 * 86400,
		'due_date_label' => 'November 14, 2026',
		'courses'        => array(
			$c( 101, 'FATAL FIVE', 'not_started', false, 0, array( 'expiration_label' => 'Nov 14, 2026', 'expiration_ts' => $now + 13 * 86400 ) ),
			$c( 102, 'Documentation Essentials', 'in_progress', false, 55, array( 'expiration_label' => 'Nov 20, 2026', 'expiration_ts' => $now + 19 * 86400 ) ),
			$c( 104, 'Individual Rights', 'in_progress', true, 100, array( 'compliance_state' => 'expiring_soon', 'expiration_label' => 'Nov 25, 2026', 'completed_ts' => $now - 300 * 86400, 'certificate_url' => $base . '/cert/104' ) ),
			$c( 103, 'A New Hire Orientation', 'completed', true, 100, array( 'completed_ts' => $now - 3 * 86400, 'certificate_url' => $base . '/cert/103' ) ),
			$c( 105, 'Policies', 'completed', true, 100, array( 'completed_ts' => $now - 5 * 86400 ) ),
		),
	);
}

/**
 * Runs the real wp_ajax_ghca_acd_get_employee_drawer handler.
 *
 * @param array<string,string> $post
 * @return array{status:int,payload:array<string,mixed>}
 */
function ghca_env_drawer( array $post = array() ): array {
	$_POST = array_merge( array( 'user_id' => '42', 'nonce' => 'n' ), $post );
	try {
		GHCA_ACD_AJAX::ajax_get_employee_drawer();
	} catch ( Ghca_Env_Json $e ) {
		return array( 'status' => $e->status, 'payload' => $e->payload );
	}
	return array( 'status' => 0, 'payload' => array() );
}

/** Resets the stand-in to a known state, then applies overrides. */
function ghca_env_reset( array $over = array() ): void {
	$GLOBALS['ghca_env'] = array_merge( array(
		'now' => strtotime( '2026-11-01 12:00:00 UTC' ), 'can_view' => true, 'can_docs' => true, 'can_comms' => true, 'can_admin' => true, 'can_remind' => true,
		'can_view_user' => true, 'suspended' => false, 'reviewed_at' => 0, 'employment' => '', 'bad_nonce' => false, 'redesign' => true, 'oltl' => false,
		'jotform' => false, 'messaging' => false, 'secure' => true, 'can_review_ext' => true,
	), $over );
	$GLOBALS['ghca_env']['employee'] = ghca_env_drawer_employee();
	$_POST = array();
}
