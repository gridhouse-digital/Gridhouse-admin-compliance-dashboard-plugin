<?php
/**
 * Stand-in WordPress for the redesigned table tests and previews. It defines just enough functions and
 * collaborator classes to load the real GHCA_ACD_Data_Provider, GHCA_ACD_Table_UI and
 * GHCA_ACD_Redesign_Tables, then seeds the provider's aggregate with synthetic employees. The filtering,
 * sorting, paging and markup under test are the plugin's own code. Not a WordPress screen; not live data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['ghca_env'] = array(
	'logged_in'     => true,
	'can_view'      => true,
	'manage_users'  => true,
	'redesign'      => true,
	'now'           => strtotime( '2026-11-01 12:00:00 UTC' ),
);

function __( $t, $d = '' ) { return $t; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $t, $d = '' ) { echo htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $t, $d = '' ) { echo htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u, $p = null ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
function selected( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function disabled( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, null === $ts ? $GLOBALS['ghca_env']['now'] : $ts ); }
function is_user_logged_in() { return $GLOBALS['ghca_env']['logged_in']; }
function current_user_can( $cap ) { return false; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url( $u, $a = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . '_wpnonce=test'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_page_by_path( $s ) { return null; }
function get_permalink( $p = 0 ) { return ''; }
function apply_filters( $tag, $value ) { return $value; }
function add_query_arg( $k, $v = null, $url = null ) {
	if ( is_array( $k ) ) {
		return $v . ( false === strpos( (string) $v, '?' ) ? '?' : '&' ) . http_build_query( $k );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( (string) $v );
}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
// The real one measures against time(); this one against the fixed test clock, so output never drifts.
function human_time_diff( $from, $to = 0 ) { $d = (int) round( abs( $GLOBALS['ghca_env']['now'] - $from ) / 86400 ); return $d . ( 1 === $d ? ' day' : ' days' ); }
function get_the_title( $id ) { return 'Synthetic group ' . $id; }
function get_option( $k, $d = false ) { return $GLOBALS['ghca_env']['options'][ $k ] ?? $d; }
function wp_get_current_user() { return (object) array( 'roles' => array() ); }

class WP_Post {}
function is_singular() { return true; }
function get_post() { return new WP_Post(); }
final class GHCA_Admin_Compliance_Dashboard {
	const SORTABLE_COLUMNS = array( 'name', 'group', 'progress_pct', 'due_timestamp', 'status_slug' );
	public static function page_uses_dashboard( WP_Post $post ): bool { return true; }
}
final class GHCA_ACD_Settings {
	public static function ui_redesign_enabled(): bool { return $GLOBALS['ghca_env']['redesign']; }
	public static function get_at_risk_days(): int { return $GLOBALS['ghca_env']['at_risk_days'] ?? 14; }
}
final class GHCA_ACD_Roles {
	public static function user_can_view(): bool { return $GLOBALS['ghca_env']['can_view']; }
	public static function user_can_manage_users(): bool { return $GLOBALS['ghca_env']['manage_users']; }
	public static function user_has_unrestricted_view(): bool { return false; }
	// Slice 4: the list tabs read these gates. Defaults keep the slice-2 tests unchanged.
	public static function user_can_edit_records(): bool { return $GLOBALS['ghca_env']['can_admin'] ?? false; }
	public static function user_can_send_reminders(): bool { return $GLOBALS['ghca_env']['can_remind'] ?? false; }
	public static function user_can_view_communication_history(): bool { return $GLOBALS['ghca_env']['can_comms'] ?? false; }
	public static function user_can_view_employee_documents(): bool { return $GLOBALS['ghca_env']['can_docs'] ?? false; }
}
final class GHCA_ACD_User_Report {
	public static function can_view_user( int $id ): bool { return $GLOBALS['ghca_env']['can_view_user'] ?? true; }
}
final class GHCA_ACD_Scoping {
	public static function get_visible_group_ids(): array { return array( 11, 12, 13 ); }
}
final class GHCA_Compliance_Program {
	public static function get_new_hire_group_ids(): array { return array(); }
}
/** wp_send_json_* ends the request in WordPress; here it throws so a test can read what would have been sent. */
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
	if ( ! empty( $GLOBALS['ghca_env']['bad_nonce'] ) ) {
		throw new Ghca_Env_Json( array( 'success' => false, 'data' => 'bad nonce' ), 403 );
	}
	return 1;
}
function wp_send_json_success( $data = null, $status = 200 ) { throw new Ghca_Env_Json( array( 'success' => true, 'data' => $data ), (int) $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { throw new Ghca_Env_Json( array( 'success' => false, 'data' => $data ), (int) $status ); }

require_once __DIR__ . '/../../includes/class-ui-icons.php';
require_once __DIR__ . '/../../includes/class-table-ui.php';
require_once __DIR__ . '/../../includes/class-shortcodes.php';
require_once __DIR__ . '/../../includes/class-ajax-handlers.php';
require_once __DIR__ . '/../../includes/class-data-provider.php';
require_once __DIR__ . '/../../includes/class-redesign-tables.php';
require_once __DIR__ . '/../../includes/class-fluentcrm.php';
require_once __DIR__ . '/../../includes/class-certificate-download.php';
require_once __DIR__ . '/../../includes/class-redesign-lists.php';

/** Employee record in the shape build_employee_records() produces. */
function ghca_env_employee( int $id, string $name, int $group, string $slug, string $label, int $done, int $total, int $due_offset_days, string $activity ): array {
	$now = $GLOBALS['ghca_env']['now'];
	$due = $due_offset_days ? $now + $due_offset_days * 86400 : 0;
	return array(
		'user_id'             => $id,
		'name'                => $name,
		'email'               => strtolower( str_replace( ' ', '.', $name ) ) . '@example.test',
		'group'               => 'Synthetic group ' . $group,
		'group_id'            => $group,
		'courses'             => array( array( 'id' => 100 + ( $id % 3 ), 'title' => 'Synthetic course ' . ( $id % 3 ), 'status' => 'in_progress', 'completed' => false ) ),
		'completed_label'     => $total ? $done . ' / ' . $total : '0',
		'progress_pct'        => $total ? (int) round( $done / $total * 100 ) : 0,
		'progress_label'      => ( $total ? (int) round( $done / $total * 100 ) : 0 ) . '%',
		'due_timestamp'       => $due,
		'due_date_label'      => $due ? gmdate( 'F j, Y', $due ) : '—',
		'last_activity_label' => $activity,
		'status_slug'         => $slug,
		'status_label'        => $label,
		'certificate_label'   => $done === $total && $total ? 'Available' : 'Pending',
	);
}

/** Seeds GHCA_ACD_Data_Provider so its real filtering and sorting run on synthetic rows. */
function ghca_env_seed( int $active_count = 10 ): void {
	$names = array( 'Alder Reyes', 'Birch Okafor', 'Cedar Hill', 'Dune Ellis', 'Elm Alvarez', 'Fern Boateng', 'Gale Brooks', 'Hazel Nair', 'Ivy Kim', 'Juniper Mensah', 'Kestrel Odum', 'Larch Pryce', 'Maple Quinn', 'Nettle Rao', 'Orchid Sato', 'Pine Tran', 'Quill Underwood', 'Rowan Vega' );
	$mix   = array(
		array( 'overdue', 'Overdue', 3, 6, -7 ),
		array( 'overdue', 'Overdue', 2, 8, -3 ),
		array( 'expired', 'Expired', 6, 6, -2 ),
		array( 'in_progress', 'In Progress', 6, 10, 30 ),
		array( 'not_started', 'Not Started', 0, 6, 9 ),
		array( 'new_hire_in_progress', 'New hire: in progress', 1, 8, 13 ),
		array( 'expiring_soon', 'Expiring Soon', 5, 5, 20 ),
		array( 'completed', 'All assigned courses complete', 10, 10, 340 ),
		array( 'completed', 'All assigned courses complete', 9, 9, 300 ),
		array( 'in_progress', 'In Progress', 4, 9, 42 ),
	);
	$active = array();
	for ( $i = 0; $i < $active_count; $i++ ) {
		$m        = $mix[ $i % count( $mix ) ];
		$active[] = ghca_env_employee( 100 + $i, $names[ $i % count( $names ) ], 11 + ( $i % 3 ), $m[0], $m[1], $m[2], $m[3], $m[4], $i % 4 ? 'Oct ' . ( 2 + $i ) . ', 2026' : 'No activity' );
	}
	$inactive = array(
		ghca_env_employee( 900, 'Sable Martin', 11, 'overdue', 'Overdue', 5, 6, -40, 'Sep 12, 2026' ),
		ghca_env_employee( 901, 'Tansy Bello', 12, 'completed', 'All assigned courses complete', 9, 9, 200, 'Aug 15, 2026' ),
	);
	$ref = new ReflectionProperty( 'GHCA_ACD_Data_Provider', 'aggregate' );
	$ref->setAccessible( true );
	$ref->setValue( null, array( 'employees' => $active, 'inactive_employees' => $inactive ) );
}

/**
 * Runs the plugin's real wp_ajax_ghca_acd_filter_table handler with the given POST body.
 *
 * @param array<string,string> $post
 * @return array{status:int,payload:array<string,mixed>}
 */
function ghca_env_ajax( array $post ): array {
	$_POST = $post;
	$_GET  = array();
	try {
		GHCA_ACD_AJAX::ajax_filter_table();
	} catch ( Ghca_Env_Json $e ) {
		return array( 'status' => $e->status, 'payload' => $e->payload );
	}
	return array( 'status' => 0, 'payload' => array() );
}
