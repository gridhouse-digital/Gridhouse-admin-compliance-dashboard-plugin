<?php
/**
 * Page body background on plugin pages. Loads the real plugin entry file in a stand-in WordPress and calls
 * the real body_class callbacks: the dashboard page gets ghca-ui-page only while the redesign is on; other
 * pages never get it; the bulletin pages always do. Then checks the stylesheets: the warm rule lives only in
 * gh-redesign.css (enqueued only with the redesign), targets only that class, and the legacy colour is kept.
 * Synthetic: no WordPress, no theme. Run: php tests/test-page-background.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$failed = 0;
$passed = 0;
function check( $ok, $label ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $label\n" );
		$GLOBALS['failed']++;
		return;
	}
	$GLOBALS['passed']++;
}
$GLOBALS['pb'] = array( 'redesign' => 1, 'singular' => true, 'content' => '[admin_compliance_dashboard]', 'meta' => '', 'filters' => array() );
function add_action( ...$a ) { return true; }
function add_filter( $tag, $cb = null, ...$rest ) { $GLOBALS['pb']['filters'][ $tag ][] = $cb; return true; }
function register_activation_hook( ...$a ) { return true; }
function add_shortcode( ...$a ) { return true; }
function register_deactivation_hook( ...$a ) { return true; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://staging.test/wp-content/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/'; }
function plugins_url( $p, $f ) { return plugin_dir_url( $f ) . $p; }
function get_option( $k, $d = false ) { return 'ghca_acd_ui_redesign' === $k ? $GLOBALS['pb']['redesign'] : $d; }
function apply_filters( $tag, $v ) { return $v; }
function is_singular() { return $GLOBALS['pb']['singular']; }
class WP_Post { public $ID = 7; public $post_content = ''; }
function get_post() { $p = new WP_Post(); $p->post_content = $GLOBALS['pb']['content']; return $p; }
function has_shortcode( $content, $tag ) { return false !== strpos( $content, '[' . $tag ); }
function get_post_meta( $id, $key, $single = false ) { return '_elementor_data' === $key ? $GLOBALS['pb']['meta'] : ''; }

require __DIR__ . '/../gridhouse-admin-compliance-dashboard.php';

$classes = static function ( array $state ): array {
	$GLOBALS['pb'] = array_merge( $GLOBALS['pb'], $state );
	return GHCA_Admin_Compliance_Dashboard::add_body_class( array( 'page', 'page-id-2716' ) );
};

// ---- 1. Dashboard page, redesign on and off.
$on = $classes( array( 'redesign' => 1, 'content' => '[admin_compliance_dashboard]', 'meta' => '' ) );
check( in_array( 'ghca-acd-page', $on, true ) && in_array( 'ghca-ui-page', $on, true ), 'redesign on: ghca-acd-page and ghca-ui-page' );
$off = $classes( array( 'redesign' => 0 ) );
check( in_array( 'ghca-acd-page', $off, true ) && ! in_array( 'ghca-ui-page', $off, true ), 'redesign off: ghca-acd-page only (legacy body colour kept)' );
$el = $classes( array( 'redesign' => 1, 'content' => '', 'meta' => '[{"widgetType":"shortcode","settings":{"shortcode":"[admin_compliance_dashboard]"}}]' ) );
check( in_array( 'ghca-ui-page', $el, true ), 'an Elementor-built dashboard page is detected the same way' );
check( array( 'page', 'page-id-2716', 'ghca-acd-page', 'ghca-ui-page' ) === $on, 'existing classes are kept; two are added' );

// ---- 2. Pages that are not plugin pages never get the class.
$other = $classes( array( 'redesign' => 1, 'content' => 'An ordinary page', 'meta' => '' ) );
check( ! in_array( 'ghca-ui-page', $other, true ) && ! in_array( 'ghca-acd-page', $other, true ), 'ordinary page: untouched' );
$archive = $classes( array( 'redesign' => 1, 'singular' => false, 'content' => '[admin_compliance_dashboard]' ) );
check( array( 'page', 'page-id-2716' ) === $archive, 'non-singular views (archives, home): untouched' );

// ---- 3. Bulletin pages (always the redesigned UI).
$src = (string) file_get_contents( __DIR__ . '/../includes/odp/class-odp-bulletins.php' );
check( false !== strpos( $src, "\$classes[] = 'gh-odp-page';" ) && false !== strpos( $src, "\$classes[] = 'ghca-ui-page';" ), 'bulletin pages add ghca-ui-page in their body_class filter' );

// ---- 4. Stylesheets.
$redesign = (string) file_get_contents( __DIR__ . '/../assets/gh-redesign.css' );
$legacy   = (string) file_get_contents( __DIR__ . '/../assets/dashboard.css' );
check( 1 === substr_count( $redesign, 'html body.ghca-ui-page { background-color: #F3EFEA !important; }' ), 'warm body rule present once in gh-redesign.css' );
check( 1 === preg_match_all( '/ghca-ui-page/', preg_replace( '#/\*.*?\*/#s', '', $redesign ) ), 'it is the only rule that targets ghca-ui-page' );
check( false === strpos( $redesign, '2716' ) && false === strpos( $legacy, '2716' ) && false === strpos( $src, '2716' ), 'no hard-coded page id anywhere' );
check( 1 === preg_match( '/body\.ghca-acd-page\s*\{\s*background-color:\s*#eceff3;\s*\}/', $legacy ), 'dashboard.css keeps the legacy body colour unchanged' );
check( false === strpos( $legacy, 'ghca-ui-page' ), 'dashboard.css does not know about the redesign class' );
$main = (string) file_get_contents( __DIR__ . '/../gridhouse-admin-compliance-dashboard.php' );
$enq  = substr( $main, (int) strpos( $main, 'public static function enqueue_assets' ), 4000 );
check( 1 === preg_match( "/ui_redesign_enabled\(\) \) \{\s*wp_enqueue_style\( 'ghca-redesign'/", $enq ), 'gh-redesign.css is enqueued only when the redesign is on' );
check( false !== strpos( $enq, "'ghca-redesign', plugin_dir_url( __FILE__ ) . 'assets/gh-redesign.css', array( 'ghca-admin-dashboard' )" ), 'gh-redesign.css loads after dashboard.css (dependency)' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed, passed $passed\n" );
	exit( 1 );
}
echo "PASS: $passed page background checks\n";
