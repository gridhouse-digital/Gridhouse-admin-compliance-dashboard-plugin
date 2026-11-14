<?php
/**
 * Prepares a disposable WordPress site (GHCA_DISPOSABLE_TEST_SITE, database ghca_test*) for the real-theme browser
 * checks: site URL, redesign on, synthetic groups, synthetic employees, the dashboard pages, a known password for
 * the synthetic admin. Synthetic data only. Refuses to run anywhere else.
 *
 *   php tests/real-site/setup.php /path/to/wp http://127.0.0.1:8099 [theme]
 */
$ghca_wp_root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$ghca_url = (string) ( $argv[2] ?? 'http://127.0.0.1:8099' );
$_SERVER['HTTP_HOST'] = 'localhost';
require $ghca_wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) ) {
	fwrite( STDERR, "refusing: not a disposable ghca_test site\n" );
	exit( 1 );
}
update_option( 'siteurl', $ghca_url );
update_option( 'home', $ghca_url );
update_option( 'ghca_acd_ui_redesign', 1 );
if ( ! empty( $argv[3] ) ) {
	switch_theme( (string) $argv[3] );
}
$groups = array();
foreach ( array( 'DSP — New Hire', 'DSP — Annual Compliance', 'LPN — Annual Compliance' ) as $title ) {
	$found = get_posts( array( 'post_type' => 'groups', 'title' => $title, 'numberposts' => 1, 'post_status' => 'any' ) );
	$groups[] = $found ? (int) $found[0]->ID : (int) wp_insert_post( array( 'post_type' => 'groups', 'post_status' => 'publish', 'post_title' => $title ) );
}
$admin = get_user_by( 'login', 'synthetic-admin' );
wp_set_password( 'synthetic-pass', $admin->ID );
$people = array( array( 'jordan', 'Jordan', 'Ellis', 0 ), array( 'morgan', 'Morgan', 'Lee', 1 ), array( 'avery', 'Avery', 'Kim', 2 ), array( 'riley', 'Riley', 'Shaw', 1 ) );
foreach ( $people as $p ) {
	$u = get_user_by( 'login', 'synthetic-' . $p[0] );
	$id = $u ? $u->ID : wp_insert_user( array( 'user_login' => 'synthetic-' . $p[0], 'user_email' => $p[0] . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => $p[1], 'last_name' => $p[2], 'display_name' => $p[1] . ' ' . $p[2] ) );
	update_user_meta( $id, 'ghca_rs_groups', array( $groups[ $p[3] ] ) );
}
update_option( 'ghca_acd_tracked_groups', $groups );
$pages = array( 'compliance-dashboard' => '[admin_compliance_dashboard]', 'manage-users' => '[admin_compliance_manage_users]', 'user-report' => '[admin_compliance_user_report]', 'cert-download' => 'Certificates' );
foreach ( $pages as $slug => $content ) {
	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => ucwords( str_replace( '-', ' ', $slug ) ), 'post_content' => $content ) );
	}
}
foreach ( get_posts( array( 'post_type' => 'ghca-announce', 'numberposts' => -1, 'post_status' => 'any' ) ) as $a ) {
	wp_delete_post( $a->ID, true );
}
echo "ready: $ghca_url (theme " . get_stylesheet() . ', groups ' . implode( ',', $groups ) . ")\n";
