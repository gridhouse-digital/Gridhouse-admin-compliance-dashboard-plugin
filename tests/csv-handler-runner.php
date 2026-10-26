<?php
// Child process for tests/test-csv-export-handler.php: runs the real compliance export handler
// (GHCA_ACD_Export::handle_export) with synthetic employees and lets it write the CSV to stdout. Not a standalone test.
define( 'ABSPATH', __DIR__ . '/' );
function is_user_logged_in() { return true; }
function check_admin_referer( $a ) { return true; }
function nocache_headers() {}
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? 0 ); }
function wp_die( $m = '' ) { fwrite( STDERR, 'wp_die' ); exit( 3 ); }
function __( $v, $d = '' ) { return $v; }
function esc_html__( $v, $d = '' ) { return $v; }
class GHCA_ACD_Roles { public static function user_can_view() { return true; } }
class GHCA_ACD_Data_Provider {
	public static function get_employees_for_current_view() {
		$names = json_decode( (string) getenv( 'GHCA_CSV_HANDLER_NAMES' ), true );
		$rows  = array();
		foreach ( $names as $i => $name ) {
			$rows[] = array(
				'name' => $name, 'email' => 'synthetic' . $i . '@example.invalid', 'group' => "Group\nTwo", 'required_label' => '3', 'completed_label' => '1',
				'progress_label' => '33%', 'due_date_label' => '=1+1', 'certificate_label' => "\n=2+2", 'last_activity_label' => "line1\r\nline2", 'status_label' => '@SUM(A1)',
			);
		}
		return $rows;
	}
}
require __DIR__ . '/../includes/class-export.php';
GHCA_ACD_Export::handle_export();
