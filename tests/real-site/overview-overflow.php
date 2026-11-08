<?php
/**
 * Tests only. Adds (or removes) Overview overflow data on a disposable site prepared by setup.php: five extra
 * synthetic groups, each with one synthetic employee, and five announcements, so Compliance by group has 8 rows and
 * Announcements has 5 notes. Synthetic data only. Refuses to run anywhere but a ghca_test* database.
 *
 *   php tests/real-site/overview-overflow.php /path/to/wp add|remove
 */
$ghca_wp_root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$ghca_mode    = (string) ( $argv[2] ?? '' );
$_SERVER['HTTP_HOST'] = 'localhost';
require $ghca_wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! in_array( $ghca_mode, array( 'add', 'remove' ), true ) ) {
	fwrite( STDERR, "refusing: not a disposable ghca_test site, or no add|remove\n" );
	exit( 1 );
}
$tracked = array_map( 'intval', (array) get_option( 'ghca_acd_tracked_groups', array() ) );
$extra   = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$title = 'Synthetic overflow group ' . $i;
	$found = get_posts( array( 'post_type' => 'groups', 'title' => $title, 'numberposts' => 1, 'post_status' => 'any' ) );
	$user  = get_user_by( 'login', 'synthetic-ovf-' . $i );
	if ( 'remove' === $ghca_mode ) {
		if ( $found ) { $tracked = array_values( array_diff( $tracked, array( (int) $found[0]->ID ) ) ); wp_delete_post( $found[0]->ID, true ); }
		if ( $user ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user->ID ); }
		continue;
	}
	$gid = $found ? (int) $found[0]->ID : (int) wp_insert_post( array( 'post_type' => 'groups', 'post_status' => 'publish', 'post_title' => $title ) );
	$uid = $user ? $user->ID : wp_insert_user( array( 'user_login' => 'synthetic-ovf-' . $i, 'user_email' => 'ovf' . $i . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => 'Overflow', 'last_name' => 'Person ' . $i, 'display_name' => 'Overflow Person ' . $i ) );
	update_user_meta( $uid, 'ghca_rs_groups', array( $gid ) );
	$extra[] = $gid;
}
foreach ( get_posts( array( 'post_type' => 'ghca-announce', 'numberposts' => -1, 'post_status' => 'any', 's' => 'Synthetic overflow note' ) ) as $a ) {
	wp_delete_post( $a->ID, true );
}
if ( 'add' === $ghca_mode ) {
	update_option( 'ghca_acd_tracked_groups', array_values( array_unique( array_merge( $tracked, $extra ) ) ) );
	for ( $i = 1; $i <= 5; $i++ ) {
		$id = wp_insert_post( array( 'post_type' => 'ghca-announce', 'post_status' => 'publish', 'post_title' => 'Synthetic overflow note ' . $i, 'post_content' => 'Synthetic announcement ' . $i . ' for the Overview list cap. ' . str_repeat( 'More text to wrap. ', $i ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - $i * 60 ) ) );
		update_post_meta( $id, 'ghca_announce_type', 'update' );
	}
} else {
	update_option( 'ghca_acd_tracked_groups', $tracked );
}
echo $ghca_mode . ": groups " . count( (array) get_option( 'ghca_acd_tracked_groups' ) ) . "\n";
