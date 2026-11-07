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
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, null === $ts ? $GLOBALS['ghca_env']['now'] : $ts ); }
function current_time( $type, $gmt = 0 ) { return $GLOBALS['ghca_env']['now']; }
function get_option( $k, $d = false ) {
	if ( 'ghca_audit_mapping' === $k ) {
		return array(
			101 => array( 'credit_hours' => 5.5, 'odp_category' => 'cat_a', 'is_orientation' => 0 ),
			102 => array( 'credit_hours' => 1, 'odp_category' => '', 'is_orientation' => 0 ),
			103 => array( 'credit_hours' => 0.75, 'odp_category' => 'cat_a', 'is_orientation' => 1 ),
		);
	}
	return $d;
}
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'roles' => array( 'subscriber' ), 'user_registered' => '2026-10-14 21:11:00' ); }
function wp_roles() { return new class() { public function get_names() { return array( 'subscriber' => 'Direct Support Professional (DSP)' ); } }; }
function get_user_meta( $id, $key, $single = false ) {
	if ( 'ghca_acd_reviewed_at' === $key ) {
		return $GLOBALS['ghca_env']['reviewed_at'];
	}
	if ( 'ghca_first_service_date' === $key ) {
		return '2026-10-20';
	}
	return '';
}
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
}
final class GHCA_ACD_User_Report {
	public static function can_view_user( int $id ): bool { return $GLOBALS['ghca_env']['can_view_user']; }
}
final class GHCA_Audit_Mapping {
	const OPTION_NAME = 'ghca_audit_mapping';
	public static function get_odp_categories(): array { return array( 'cat_a' => 'Rights and abuse prevention' ); }
}
final class GHCA_Audit_Calculator {
	public static function completion_timestamp( string $v ) { return strtotime( $v . ' 00:00:00 UTC' ); }
}
/** Fragments owned by later steps; fixed text here so this test sees only the drawer's own markup. */
final class GHCA_ACD_Jotform_UI {
	public static function render_recent_documents( int $u ): string { return '<div class="stub-documents" data-user="' . $u . '">documents fragment</div>'; }
	public static function render_manual_entry_form( int $u ): string { return '<form class="stub-manual" data-user="' . $u . '">manual entry fragment</form>'; }
}
final class GHCA_ACD_Messaging_UI {
	public static function render_history_section( int $u ): string { return '<div class="stub-history" data-user="' . $u . '">history fragment</div>'; }
}
final class GHCA_ACD_OLTL_UI {
	public static function render_employee_card( int $u ): string { return $GLOBALS['ghca_env']['oltl'] ?? false ? '<section class="stub-oltl">oltl card</section>' : ''; }
}

/** Synthetic provider: only the calls the drawer makes. */
final class GHCA_ACD_Data_Provider {
	public static function get_employee_record( int $user_id ): array { return $GLOBALS['ghca_env']['employee'] ?? array(); }
	public static function is_user_suspended( int $user_id ): bool { return $GLOBALS['ghca_env']['suspended']; }
	public static function employment_type_label( int $user_id ): string { return $GLOBALS['ghca_env']['employment'] ?: 'Not set'; }
	public static function get_user_report_url( int $user_id ): string { return 'https://example.test/user-report?user_id=' . $user_id; }
	public static function get_user_full_name( int $id ): string { return 'Synthetic Admin'; }
}

require_once __DIR__ . '/../../includes/odp/class-odp-contract.php';
require_once __DIR__ . '/../../includes/odp/class-odp-ui.php';
require_once __DIR__ . '/../../includes/class-redesign.php';
require_once __DIR__ . '/../../includes/class-redesign-tables.php';
require_once __DIR__ . '/../../includes/class-redesign-drawer.php';
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
	), $over );
	$GLOBALS['ghca_env']['employee'] = ghca_env_drawer_employee();
	$_POST = array();
}
