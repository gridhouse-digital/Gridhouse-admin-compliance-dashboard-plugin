<?php
/**
 * Tests only. Sets the ODP feed cache on a disposable site prepared by setup.php:
 *   empty  - a verified feed with zero bulletins, fetched now (Isoke's state: the cached feed has no bulletins);
 *   clear  - no cache (with outbound requests blocked on the test machine, the feed is then unavailable).
 * No bulletins are added and nothing is published; the feed endpoint is untouched. Refuses anything but ghca_test*.
 *
 *   php tests/real-site/odp-feed-cache.php /path/to/wp empty|clear
 */
$ghca_wp_root = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$ghca_mode    = (string) ( $argv[2] ?? '' );
$_SERVER['HTTP_HOST'] = 'localhost';
require $ghca_wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! in_array( $ghca_mode, array( 'empty', 'clear' ), true ) ) {
	fwrite( STDERR, "refusing: not a disposable ghca_test site, or no empty|clear\n" );
	exit( 1 );
}
if ( 'clear' === $ghca_mode ) {
	delete_option( GHCA_ODP_Bulletins::CACHE_OPTION );
} else {
	update_option( GHCA_ODP_Bulletins::CACHE_OPTION, array( 'fetched_at' => time(), 'feed' => array( 'schema_version' => 1, 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'bulletins' => array() ) ), false );
}
$state = GHCA_ODP_Bulletins::snapshot( 'admin' )['state'];
echo $ghca_mode . ': state ' . $state . "\n";
