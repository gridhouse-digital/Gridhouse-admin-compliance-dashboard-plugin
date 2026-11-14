<?php
/**
 * The ODP bulletin library and bulletin page inside the active WordPress theme. Runs the plugin's own
 * GHCA_ODP_UI::themed() and GHCA_ODP_Bulletins page wrapper against a stand-in WordPress that records
 * which theme hooks were called. Synthetic: no WordPress, no theme, no network, no feed.
 * Run: php tests/test-odp-themed.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
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

$GLOBALS['ghca_t'] = array( 'calls' => array(), 'block' => false, 'filters' => array(), 'styles' => array(), 'scripts' => array() );
function ghca_t_call( $name ) { $GLOBALS['ghca_t']['calls'][] = $name; }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u, $p = null ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function wp_is_block_theme() { return $GLOBALS['ghca_t']['block']; }
function get_header() { ghca_t_call( 'get_header' ); echo '<header class="theme-header">THEME HEADER</header><div id="content">'; }
function get_footer() { ghca_t_call( 'get_footer' ); echo '</div><footer class="theme-footer">THEME FOOTER</footer>'; }
function do_blocks( $b ) { ghca_t_call( 'do_blocks:' . ( false !== strpos( $b, '"slug":"header"' ) ? 'header' : 'footer' ) ); return false !== strpos( $b, '"slug":"header"' ) ? '<header class="wp-block-template-part">BLOCK HEADER</header>' : '<footer class="wp-block-template-part">BLOCK FOOTER</footer>'; }
function wp_head() { ghca_t_call( 'wp_head' ); echo '<!--wp_head-->'; }
function wp_footer() { ghca_t_call( 'wp_footer' ); echo '<!--wp_footer-->'; }
function wp_body_open() { ghca_t_call( 'wp_body_open' ); }
function get_language_attributes() { return 'lang="en-US"'; }
function get_bloginfo( $k = '', $f = '' ) { return 'charset' === $k ? 'UTF-8' : 'Akanso Staging'; }
function get_body_class() { $c = array( 'page' ); foreach ( $GLOBALS['ghca_t']['filters']['body_class'] ?? array() as $cb ) { $c = $cb( $c ); } return $c; }
function add_filter( $tag, $cb, $prio = 10 ) { $GLOBALS['ghca_t']['filters'][ $tag ][] = $cb; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugins_url( $p, $f ) { return 'https://staging.test/wp-content/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/' . $p; }
function wp_enqueue_style( $h, $src, $deps = array(), $ver = '' ) { $GLOBALS['ghca_t']['styles'][ $h ] = $src . '?ver=' . $ver; }
function wp_enqueue_script( $h, $src, $deps = array(), $ver = '', $footer = false ) { $GLOBALS['ghca_t']['scripts'][ $h ] = array( $src, $footer ); }

require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-feed.php';
require __DIR__ . '/../includes/odp/class-odp-access.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require __DIR__ . '/../includes/odp/class-odp-bulletins.php';

$model = array(
	'title'       => 'ODP Bulletins',
	'context'     => 'admin',
	'theme'       => 'akansoh',
	'brand_style' => '--gh-agency:#7a2e1f;',
	'library_url' => 'https://staging.test/odp-bulletins/',
	'body_html'   => '<section class="gh-odp-col"><nav class="gh-crumbs"><a href="https://staging.test/compliance-admin-dashboard/">Compliance dashboard</a></nav><h1 class="gh-h1">ODP Bulletins</h1></section>',
	'http_status' => 200,
	'chrome'      => array( 'org' => 'Akanso', 'nav' => array( array( 'url' => '#', 'label' => 'Compliance', 'icon' => 'shield' ) ) ),
);

// ---- 1. The fragment: no document, no app bar, the dashboard surface and brand.
$frag = GHCA_ODP_UI::themed( $model );
check( false === stripos( $frag, '<!DOCTYPE' ) && false === strpos( $frag, '<html' ) && false === strpos( $frag, '<body' ), 'no document of its own' );
check( false === strpos( $frag, 'gh-appbar' ) && false === strpos( $frag, 'gh-nav' ) && false === strpos( $frag, '<header' ) && false === strpos( $frag, 'gh-logo' ), 'no plugin header, logo or site navigation' );
check( false === strpos( $frag, 'gh-skip' ), 'no second skip link: the theme has its own' );
check( 0 === strpos( $frag, '<div class="ghca-ui ghca-ui--bleed gh-odp-host">' ), 'full-width wrapper, scoped like the dashboard' );
check( false !== strpos( $frag, '<div class="gh-app gh-ui--admin" data-theme="akansoh" style="--gh-agency:#7a2e1f;">' ), 'board app surface (#F3EFEA), theme and agency brand style kept' );
check( false !== strpos( $frag, '<main id="gh-odp-main" class="gh-container gh-odp-main"><section class="gh-odp-col">' ), 'content in the same main container as before' );
check( false !== strpos( $frag, 'Compliance dashboard</a>' ), 'breadcrumb back to the dashboard kept' );
check( false !== strpos( $frag, 'id="gh-odp-drawer"' ) && false !== strpos( $frag, 'href="https://staging.test/odp-bulletins/"' ), 'bulletin drawer kept, linking to the library' );
check( 1 === substr_count( $frag, '<main' ), 'one main landmark' );
$emp = GHCA_ODP_UI::themed( array( 'context' => 'employee' ) + $model );
check( false !== strpos( $emp, 'gh-ui--employee' ), 'employee context kept' );

// ---- 2. Classic theme: get_header() and get_footer() around the content.
$page = new ReflectionMethod( 'GHCA_ODP_Bulletins', 'themed_page' );
$page->setAccessible( true );
$GLOBALS['ghca_t']['calls'] = array();
$out = $page->invoke( null, $frag );
check( array( 'get_header', 'get_footer' ) === $GLOBALS['ghca_t']['calls'], 'classic theme: get_header then get_footer' );
check( 0 === strpos( $out, '<header class="theme-header">' ) && strpos( $out, $frag ) > 0 && strpos( $out, '<footer class="theme-footer">' ) > strpos( $out, $frag ), 'theme header, content, theme footer, in that order' );
check( 1 === substr_count( $out, '<header' ), 'exactly one header: the theme\'s' );

// ---- 3. Block theme: the theme's header and footer template parts in core's canvas.
$GLOBALS['ghca_t']['block'] = true;
$GLOBALS['ghca_t']['calls'] = array();
$prep = new ReflectionMethod( 'GHCA_ODP_Bulletins', 'prepare_theme_page' );
$prep->setAccessible( true );
$prep->invoke( null, $model );
$out = $page->invoke( null, $frag );
check( array( 'do_blocks:header', 'do_blocks:footer', 'wp_head', 'wp_body_open', 'wp_footer' ) === $GLOBALS['ghca_t']['calls'], 'block theme: parts rendered before wp_head, then body open and footer hooks' );
check( 0 === strpos( $out, '<!DOCTYPE html><html lang="en-US"><head><meta charset="UTF-8">' ), 'block theme: one document with the site language' );
check( false !== strpos( $out, '<div class="wp-site-blocks"><header class="wp-block-template-part">BLOCK HEADER</header>' . $frag . '<footer class="wp-block-template-part">BLOCK FOOTER</footer></div>' ), 'block theme: header part, content, footer part inside .wp-site-blocks' );
check( false !== strpos( $out, '<body class="page gh-odp-page ghca-ui-page">' ), 'body classes added for the bulletin page (gh-odp-page, and ghca-ui-page for the warm page body)' );
check( 1 === substr_count( $out, '<header' ), 'block theme: exactly one header' );

// ---- 4. Assets, title and robots.
check( isset( $GLOBALS['ghca_t']['styles']['ghca-redesign'] ) && false !== strpos( $GLOBALS['ghca_t']['styles']['ghca-redesign'], 'assets/gh-redesign.css?ver=' ), 'redesign stylesheet enqueued under the dashboard\'s handle' );
check( isset( $GLOBALS['ghca_t']['scripts']['ghca-bulletins'] ) && true === $GLOBALS['ghca_t']['scripts']['ghca-bulletins'][1], 'bulletin script enqueued in the footer under the dashboard\'s handle' );
$title = $GLOBALS['ghca_t']['filters']['pre_get_document_title'][0];
check( 'ODP Bulletins – Akanso Staging' === $title( '' ), 'document title' );
$robots = $GLOBALS['ghca_t']['filters']['wp_robots'][0];
check( array( 'noindex' => true, 'nofollow' => true ) === $robots( array() ), 'noindex, nofollow' );

// ---- 5. Unchanged around it: routing, login, audience, status, no-store (source order of render_screen and send_document).
$src    = (string) file_get_contents( __DIR__ . '/../includes/odp/class-odp-bulletins.php' );
$screen = substr( $src, (int) strpos( $src, 'public static function render_screen' ), 1600 );
check( strpos( $screen, 'GHCA_ODP_Routes::match' ) < strpos( $screen, 'route_owner()' ) && strpos( $screen, 'route_owner()' ) < strpos( $screen, 'auth_redirect()' ) && strpos( $screen, 'auth_redirect()' ) < strpos( $screen, 'trusted_context()' ) && strpos( $screen, 'trusted_context()' ) < strpos( $screen, 'send_document' ), 'route match, owner, login redirect and audience still come first' );
$send = substr( $src, (int) strpos( $src, 'private static function send_document' ), 900 );
foreach ( array( "status_header( (int) \$model['http_status'] )", "'Cache-Control: private, no-store'", "'X-Robots-Tag: noindex'", 'GHCA_ODP_UI::themed( $model )' ) as $needle ) {
	check( false !== strpos( $send, $needle ), 'send_document keeps ' . $needle );
}
check( false === strpos( $send, 'GHCA_ODP_UI::document(' ), 'the standalone document is no longer sent' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed, passed $passed\n" );
	exit( 1 );
}
echo "PASS: $passed themed ODP page checks\n";
