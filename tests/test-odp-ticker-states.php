<?php
/**
 * ODP ticker states (GHCA_ODP_UI::ticker()): an empty feed keeps the ticker with "No ODP bulletins right now."; cached,
 * offline and unavailable keep their own messages; a populated feed is unchanged. Also the feed's present() step: a
 * successful (or cached-and-successful) feed with no current bulletins is the "empty" state. Synthetic data only.
 *
 *   php tests/test-odp-ticker-states.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
$failed = 0;
$count  = 0;
function check( $ok, $label ) {
	global $failed, $count;
	++$count;
	echo ( $ok ? 'ok - ' : 'FAIL - ' ) . $label . "\n";
	if ( ! $ok ) { ++$failed; }
}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url, $protocols = null ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-feed.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';

$base = array( 'library_url' => 'https://example.test/odp-bulletins/' );
$item = array( 'id' => 'synthetic-1', 'url' => 'https://example.test/odp-bulletins/synthetic-1/', 'published_at' => '2026-11-01T15:00:00Z', 'date_label' => 'Nov 1', 'date_short' => 'Nov 1', 'headline' => 'Synthetic headline' );

// Empty feed (Isoke's case): the ticker stays, with the empty message, the label and the link to all bulletins.
$h = GHCA_ODP_UI::ticker( $base + array( 'state' => 'empty', 'items' => array() ) );
check( '' !== $h && false !== strpos( $h, 'data-gh-ticker-state="empty"' ), 'empty feed: the ticker is rendered (it used to return no HTML)' );
check( false !== strpos( $h, '<div class="gh-ticker-msg" role="status">No ODP bulletins right now.</div>' ), 'empty feed: "No ODP bulletins right now." as a status message' );
check( false !== strpos( $h, 'ODP Bulletin' ) && false !== strpos( $h, 'All bulletins' ) && false === strpos( $h, 'gh-ticker-track' ) && false === strpos( $h, 'data-gh-ticker-pause' ), 'empty feed: label and "All bulletins" link, no track, no pause control, no invented items' );
check( false === strpos( $h, 'Saved copy' ), 'empty feed: not marked as a saved copy' );
// A missing state with no items is the same empty state; "fresh" with no items too.
foreach ( array( array(), array( 'state' => 'fresh' ), array( 'state' => '' ) ) as $extra ) {
	$h2 = GHCA_ODP_UI::ticker( $base + $extra + array( 'items' => array() ) );
	check( false !== strpos( $h2, 'No ODP bulletins right now.' ), 'no items with state ' . ( isset( $extra['state'] ) ? '"' . $extra['state'] . '"' : 'missing' ) . ': the empty message' );
}
// Distinct states keep their own messages.
$msgs = array(
	'unavailable' => 'ODP bulletins are unavailable right now.',
	'cached'      => 'Showing the last bulletin feed that could be verified. A newer copy could not be loaded.',
	'offline'     => 'The bulletin service did not respond. Showing the last verified feed.',
);
foreach ( $msgs as $state => $msg ) {
	$h3 = GHCA_ODP_UI::ticker( $base + array( 'state' => $state, 'items' => array() ) );
	check( false !== strpos( $h3, 'data-gh-ticker-state="' . $state . '"' ) && false !== strpos( $h3, $msg ) && false === strpos( $h3, 'No ODP bulletins right now.' ), $state . ' with no items: its own message, not the empty one' );
}
// A populated feed is unchanged: items, no status message; pause only with more than one.
$h4 = GHCA_ODP_UI::ticker( $base + array( 'state' => 'fresh', 'items' => array( $item ) ) );
check( false !== strpos( $h4, 'data-gh-ticker-state="items"' ) && false !== strpos( $h4, 'gh-ticker-track' ) && false !== strpos( $h4, 'Synthetic headline' ) && false === strpos( $h4, 'gh-ticker-msg' ) && false === strpos( $h4, 'data-gh-ticker-pause' ), 'one bulletin: shown in the track, no message, no pause' );
$h5 = GHCA_ODP_UI::ticker( $base + array( 'state' => 'fresh', 'items' => array( $item, array_merge( $item, array( 'id' => 'synthetic-2', 'headline' => 'Second synthetic headline' ) ) ) ) );
check( false !== strpos( $h5, 'data-gh-ticker-pause' ) && 2 === substr_count( $h5, 'gh-ticker-item' ), 'two bulletins: both shown, with the pause control' );
$h6 = GHCA_ODP_UI::ticker( $base + array( 'state' => 'cached', 'items' => array( $item ) ) );
check( false !== strpos( $h6, 'Saved copy' ) && false !== strpos( $h6, 'Synthetic headline' ), 'cached with items: shown and marked as a saved copy (unchanged)' );

// present(): a feed that loaded (fresh, or from cache) but has no current bulletins is "empty"; failures keep their state.
$now = strtotime( '2026-11-09T12:00:00Z' );
$p   = GHCA_ODP_Feed::present( array( 'state' => 'empty', 'stale' => false, 'error' => null, 'fetched_at' => $now, 'feed' => array( 'schema_version' => 1, 'updated_at' => '2026-11-09T00:00:00Z', 'bulletins' => array() ), 'cache_write' => null ), $now, 'admin' );
check( 'empty' === $p['state'] && array() === $p['bulletins'], 'a feed with zero bulletins presents as "empty"' );
$p2  = GHCA_ODP_Feed::present( array( 'state' => 'unavailable', 'stale' => false, 'error' => 'x', 'fetched_at' => null, 'feed' => null, 'cache_write' => null ), $now, 'admin' );
check( 'unavailable' === $p2['state'], 'an unavailable feed stays "unavailable"' );

echo ( $failed ? "FAILED: $failed of $count" : "PASS: $count ODP ticker state checks" ) . "\n";
exit( $failed ? 1 : 0 );
