<?php
/**
 * Real WordPress + MySQL/MariaDB checks of slice 8's server paths: the inline User training report's permission
 * boundaries, and announcement audiences (validation, storage, legacy saves keeping the stored audience, group
 * filtering). The plugin's own AJAX handlers run in this process. Synthetic users; nothing is sent.
 *
 * Runs only on a disposable site (GHCA_DISPOSABLE_TEST_SITE, database ghca_test*) prepared by tests/real-site/setup.php
 * (its LearnDash stand-ins must be installed as an mu-plugin). Skips otherwise.
 */
$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	echo "SKIP: needs a WordPress install\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_ACD_Redesign_Report' ) || ! function_exists( 'ghca_rs_groups' ) ) {
	echo "SKIP: not a prepared disposable ghca_test site\n";
	exit( 0 );
}
add_filter( 'pre_wp_mail', '__return_false' );

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	fwrite( STDOUT, ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n" );
	if ( ! $ok ) { ++$failed; }
}
final class Ghca_S8_Stop extends Exception {}
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function () { throw new Ghca_S8_Stop(); }; } );
function ajax( callable $handler, array $request ): array {
	$_POST = $_REQUEST = $request + array( 'nonce' => wp_create_nonce( 'ghca_acd_table' ) );
	ob_start();
	try { $handler(); } catch ( Ghca_S8_Stop $e ) {} // phpcs:ignore
	return (array) json_decode( (string) ob_get_clean(), true );
}

$admin  = get_user_by( 'login', 'synthetic-admin' );
$viewer = get_user_by( 'login', 'synthetic-viewer' );
if ( ! $viewer ) { $viewer = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-viewer', 'user_email' => 'viewer@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) ) ); }
$jordan = get_user_by( 'login', 'synthetic-jordan' );
$groups = get_posts( array( 'post_type' => 'groups', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );

// ---- Inline report.
wp_set_current_user( $admin->ID );
$r = ajax( array( 'GHCA_ACD_Redesign_Report', 'ajax_report' ), array( 'user_id' => $jordan->ID ) );
check( ! empty( $r['success'] ) && (int) $r['data']['user_id'] === $jordan->ID && false !== strpos( $r['data']['html'], 'Jordan Ellis' ), 'admin: the report for the chosen employee, bound to that user id' );
check( false === strpos( wp_strip_all_tags( $r['data']['html'] ), $jordan->user_email ) && false !== strpos( $r['data']['html'], 'j***@example.test' ), 'admin: the report shows the email masked (the full address is only inside the existing Contact employee mailto link)' );
$r = ajax( array( 'GHCA_ACD_Redesign_Report', 'ajax_report' ), array( 'user_id' => 999999 ) );
check( empty( $r['success'] ) && false !== strpos( (string) $r['data']['message'], 'permission' ), 'admin: an unknown user id is refused' );
$people = wp_list_pluck( GHCA_ACD_Redesign_Report::picker_people(), 'id' );
check( in_array( $jordan->ID, $people, true ) && ! in_array( $admin->ID, $people, true ), 'admin: the picker offers employees, not the admin account' );
$_POST = $_REQUEST = array( 'user_id' => $jordan->ID, 'nonce' => 'bad' );
ob_start(); $stopped = false;
try { GHCA_ACD_Redesign_Report::ajax_report(); } catch ( Ghca_S8_Stop $e ) { $stopped = true; } // phpcs:ignore
$out = (string) ob_get_clean();
check( $stopped && false === strpos( $out, 'Jordan' ), 'a bad nonce is refused before any report is built' );

wp_set_current_user( $viewer->ID );
$r = ajax( array( 'GHCA_ACD_Redesign_Report', 'ajax_report' ), array( 'user_id' => $jordan->ID ) );
check( empty( $r['success'] ) && false === strpos( wp_json_encode( $r ), 'Jordan' ), 'a subscriber without dashboard access gets no report' );
check( '' === GHCA_ACD_Redesign_Report::view_html(), 'a subscriber gets no report view' );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'title' => 'Should not exist', 'audience' => 'all' ) );
check( empty( $r['success'] ) && ! get_posts( array( 'post_type' => 'ghca-announce', 'title' => 'Should not exist', 'post_status' => 'any' ) ), 'a subscriber cannot publish an announcement' );

// ---- Announcement audiences.
wp_set_current_user( $admin->ID );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => 0, 'title' => 'S8 group note', 'type' => 'reminder', 'audience' => 'group', 'audience_group' => 0 ) );
check( empty( $r['success'] ) && false !== strpos( (string) $r['data']['message'], 'Choose one of your groups' ), 'a group audience without a group is refused' );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => 0, 'title' => 'S8 group note', 'type' => 'reminder', 'audience' => 'group', 'audience_group' => 987654 ) );
check( empty( $r['success'] ), 'a group outside the viewer\'s groups is refused' );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => 0, 'title' => 'S8 group note', 'type' => 'reminder', 'audience' => 'group', 'audience_group' => $groups[0]->ID, 'format' => 'v2' ) );
$post = get_posts( array( 'post_type' => 'ghca-announce', 'title' => 'S8 group note', 'post_status' => 'any' ) )[0] ?? null;
check( ! empty( $r['success'] ) && $post && 'group' === get_post_meta( $post->ID, 'ghca_announce_audience', true ) && (int) get_post_meta( $post->ID, 'ghca_announce_group', true ) === $groups[0]->ID, 'a group audience is stored with its group' );
check( false !== strpos( (string) $r['data']['html'], 'Group: ' . $groups[0]->post_title ) && false !== strpos( (string) $r['data']['html'], 'data-ghca-ann-menu-btn' ), 'the refreshed list shows the audience and the ⋯ menu' );
// A legacy save (no audience field) keeps the stored audience.
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => $post->ID, 'title' => 'S8 group note, edited', 'type' => 'update' ) );
check( ! empty( $r['success'] ) && 'group' === get_post_meta( $post->ID, 'ghca_announce_audience', true ), 'a save from the legacy form keeps the stored audience' );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => 0, 'title' => 'S8 legacy new', 'type' => 'update' ) );
$legacy = get_posts( array( 'post_type' => 'ghca-announce', 'title' => 'S8 legacy new', 'post_status' => 'any' ) )[0] ?? null;
check( $legacy && 'all' === get_post_meta( $legacy->ID, 'ghca_announce_audience', true ), 'a new announcement from the legacy form is for employees and admins' );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_save_announcement' ), array( 'announce_id' => $post->ID, 'title' => 'S8 group note, edited', 'type' => 'update', 'audience' => 'admins' ) );
check( 'admins' === get_post_meta( $post->ID, 'ghca_announce_audience', true ) && '' === get_post_meta( $post->ID, 'ghca_announce_group', true ), 'changing to Admins only drops the stored group' );
// Delete works and is permission-checked.
wp_set_current_user( $viewer->ID );
$r = ajax( array( 'GHCA_ACD_Announcements', 'ajax_delete_announcement' ), array( 'announce_id' => $post->ID ) );
check( empty( $r['success'] ) && get_post( $post->ID ), 'a subscriber cannot delete an announcement' );
wp_set_current_user( $admin->ID );
foreach ( array( $post->ID, $legacy ? $legacy->ID : 0 ) as $id ) {
	if ( $id ) { ajax( array( 'GHCA_ACD_Announcements', 'ajax_delete_announcement' ), array( 'announce_id' => $id ) ); }
}
check( ! get_post( $post->ID ), 'an admin deletes it' );

// ---- Manage users: the board page keeps the per-row permission and the form contract.
$html = GHCA_ACD_Manage_Users_UI::render_shortcode();
check( false !== strpos( $html, 'id="ghca-acd-user-form"' ) && false !== strpos( $html, 'name="ghca_nonce"' ) && false !== strpos( $html, 'value="ghca_acd_save_employee"' ), 'Manage users (board) posts the same form, nonce and action as before' );
check( false === strpos( $html, 'Send welcome email' ), 'no welcome-email switch is shown (the handler sends none)' );
wp_set_current_user( $viewer->ID );
check( false !== strpos( GHCA_ACD_Manage_Users_UI::render_shortcode(), 'You do not have permission to manage users.' ), 'a subscriber gets the existing refusal on Manage users' );

fwrite( STDOUT, $failed ? "FAILED: $failed of $checks slice 8 real checks\n" : "PASS: $checks slice 8 real checks (WordPress + MariaDB; synthetic users; nothing sent)\n" );
exit( $failed ? 1 : 0 );
