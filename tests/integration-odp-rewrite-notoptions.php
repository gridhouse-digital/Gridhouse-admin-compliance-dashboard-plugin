<?php
/**
 * Integration check against a disposable local WordPress with this plugin active.
 * Reproduces a stale 'notoptions' entry for the rewrite version key and verifies
 * the shutdown flush heals it without flushing again.
 *
 * Run: GHCA_WP_ROOT=/path/to/wordpress php tests/integration-odp-rewrite-notoptions.php
 */

$wp_root = getenv( 'GHCA_WP_ROOT' ) ?: dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	echo "SKIP: no local WordPress (set GHCA_WP_ROOT)\n";
	exit( 0 );
}
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['HTTP_HOST']      = '127.0.0.1:8088';
require_once $wp_root . '/wp-load.php';
if ( ! class_exists( 'GHCA_ODP_Bulletins' ) ) {
	echo "SKIP: plugin is not active in this WordPress\n";
	exit( 0 );
}

$failures = 0;
function ghca_check( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}

$key      = GHCA_ODP_Bulletins::REWRITE_OPTION;
$version  = GHCA_ODP_Bulletins::REWRITE_VERSION;
$original = get_option( $key, null );
$flushes  = 0;
add_action( 'generate_rewrite_rules', static function () use ( &$flushes ) {
	++$flushes;
} );

function ghca_make_notoptions_stale( string $key ): void {
	wp_cache_delete( $key, 'options' );
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	$notoptions = is_array( $notoptions ) ? $notoptions : array();
	$notoptions[ $key ] = true;
	wp_cache_set( 'notoptions', $notoptions, 'options' );
}

function ghca_in_notoptions( string $key ): bool {
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	return is_array( $notoptions ) && isset( $notoptions[ $key ] );
}

update_option( $key, $version, false );
ghca_make_notoptions_stale( $key );
ghca_check( '' === (string) get_option( $key, '' ), 'stale notoptions hides the stored version from get_option' );
ghca_check( false === update_option( $key, $version, false ) && ghca_in_notoptions( $key ), 'core update_option cannot repair it: the identical upsert changes 0 rows' );

$flushes = 0;
GHCA_ODP_Bulletins::flush_rewrites_once();
ghca_check( 0 === $flushes, 'flush_rewrites_once does not flush when the row already holds the version' );
ghca_check( ! ghca_in_notoptions( $key ) && $version === (string) get_option( $key, '' ), 'only this key leaves notoptions and the stored version is read back' );

remove_all_actions( 'shutdown' );
GHCA_ODP_Bulletins::register_routes();
ghca_check( false === has_action( 'shutdown', array( 'GHCA_ODP_Bulletins', 'flush_rewrites_once' ) ), 'no shutdown flush is registered once healed' );

delete_option( $key );
GHCA_ODP_Bulletins::register_routes();
GHCA_ODP_Bulletins::register_routes();
global $wp_filter;
ghca_check( 99 === has_action( 'shutdown', array( 'GHCA_ODP_Bulletins', 'flush_rewrites_once' ) ) && 1 === count( $wp_filter['shutdown']->callbacks[99] ?? array() ), 'a genuinely missing version registers the shutdown flush once' );
$flushes = 0;
GHCA_ODP_Bulletins::flush_rewrites_once();
ghca_check( 1 === $flushes && $version === (string) get_option( $key, '' ), 'a genuinely missing version flushes once and stores it' );
$flushes = 0;
GHCA_ODP_Bulletins::flush_rewrites_once();
ghca_check( 0 === $flushes, 'the next call does not flush again' );

if ( null === $original ) {
	delete_option( $key );
} else {
	update_option( $key, $original, false );
}
exit( $failures > 0 ? 1 : 0 );
