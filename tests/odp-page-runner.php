<?php
/**
 * Runs the real ODP bulletin page path — GHCA_ODP_Bulletins::render_screen(): route match, login redirect,
 * audience context, feed read, page model and the in-theme wrapper — in a stand-in WordPress with a stand-in
 * theme, for one scenario per process (the page ends with exit). Prints the page on stdout and a JSON record of
 * what WordPress was asked to do (status, redirect, enqueues, query flags) on stderr after a marker line.
 *
 * No network: wp_remote_get answers as the staging outbound guard does (blocked) unless the scenario seeds a
 * cached feed, and even then the cache is fresh so nothing is fetched. Synthetic; not WordPress.
 *
 *   php tests/odp-page-runner.php '{"uri":"/odp-bulletins/","logged_in":true,"can_view":true,"feed":"fresh"}'
 */
define( 'ABSPATH', __DIR__ . '/' );
$sc = json_decode( $argv[1] ?? '{}', true ) ?: array();
$sc += array( 'uri' => '/odp-bulletins/', 'logged_in' => true, 'can_view' => true, 'feed' => 'fresh', 'block_theme' => false, 'query' => 'home', 'redesign' => 1 );
$GLOBALS['rec'] = array( 'status' => null, 'redirect' => null, 'styles' => array(), 'scripts' => array(), 'remote' => 0, 'header_flags' => null, 'filters' => array() );
register_shutdown_function( static function () {
	fwrite( STDERR, "\n@@RECORD@@" . json_encode( $GLOBALS['rec'] ) . "\n" );
} );

// ---- WordPress stand-ins.
function __( $t, $d = '' ) { return $t; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u, $p = null ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $u ) { return (string) $u; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function add_action( ...$a ) { return true; }
function add_filter( $tag, $cb = null, ...$r ) { $GLOBALS['rec']['filters'][] = $tag; $GLOBALS['wpf'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $v, ...$r ) { return $v; }
function has_action( ...$a ) { return false; }
function home_url( $p = '' ) { return 'https://staging.test' . $p; }
function admin_url( $p = '' ) { return 'https://staging.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://staging.test/wp-json/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function get_option( $k, $d = false ) {
	if ( 'ghca_acd_ui_redesign' === $k ) { return $GLOBALS['sc']['redesign']; }
	return $GLOBALS['options'][ $k ] ?? $d;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_locale() { return 'en_US'; }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function date_i18n( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function get_bloginfo( $k = '', $f = '' ) { return 'charset' === $k ? 'UTF-8' : 'Akansoh Staging'; }
function is_user_logged_in() { return (bool) $GLOBALS['sc']['logged_in']; }
function auth_redirect() { $GLOBALS['rec']['redirect'] = 'wp-login.php?redirect_to=' . rawurlencode( $_SERVER['REQUEST_URI'] ); exit; }
function nocache_headers() { return true; }
function status_header( $code ) { $GLOBALS['rec']['status'] = (int) $code; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_next_scheduled( $h ) { return true; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['rec']['remote']++; return new WP_Error( 'http_request_blocked', 'Blocked by the staging outbound guard.' ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_message() { return $this->message; } }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugins_url( $p, $f ) { return 'https://staging.test/wp-content/plugins/Gridhouse-admin-compliance-dashboard-plugin-main/' . $p; }
function wp_enqueue_style( $h, $src, $deps = array(), $ver = '' ) { $GLOBALS['rec']['styles'][ $h ] = $src; }
function wp_enqueue_script( $h, $src, $deps = array(), $ver = '', $f = false ) { $GLOBALS['rec']['scripts'][ $h ] = $src; }
function wp_is_block_theme() { return (bool) $GLOBALS['sc']['block_theme']; }
function do_blocks( $b ) { return false !== strpos( $b, '"slug":"header"' ) ? '<header class="wp-block-template-part site-header">Theme header <nav>Home Dashboard</nav></header>' : '<footer class="wp-block-template-part site-footer">Theme footer</footer>'; }
function wp_head() { echo '<!--wp_head-->'; }
function wp_body_open() {}
function wp_footer() { echo '<!--wp_footer-->'; }
function get_language_attributes() { return 'lang="en-US"'; }
function get_body_class() { $c = array(); foreach ( $GLOBALS['wpf']['body_class'] ?? array() as $cb ) { $c = $cb( $c ); } return $c; }
function ghca_theme_flags() { $q = $GLOBALS['wp_query']; return array( 'is_home' => $q->is_home, 'is_404' => $q->is_404 ); }
function get_header() {
	$GLOBALS['rec']['header_flags'] = ghca_theme_flags();
	$theme_css = '*,*::before,*::after{box-sizing:border-box}body{margin:0;background:#fafbfd;font-family:Georgia,serif;color:#222}.site-header{position:relative;z-index:2;background:#fff;min-height:72px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:12px 24px;box-shadow:0 2px 12px rgba(18,43,70,.12)}.site-header a{color:#123}#content.site-content{padding:48px 24px;background:transparent}.site-footer{background:#1d2733;color:#ddd;padding:24px}';
	echo '<!DOCTYPE html><html lang="en-US"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Akansoh</title><style id="theme-css">' . $theme_css . '</style><!--wp_head-->' . ghca_print_enqueued() . '</head><body class="' . esc_attr( implode( ' ', get_body_class() ) ) . '">';
	echo '<header class="site-header" id="masthead">Akansoh Health Services <nav class="main-navigation"><a href="/">Home</a> <a href="/dashboard/">Dashboard</a></nav></header><div id="content" class="site-content">';
}
function get_footer() { echo '</div><footer class="site-footer" id="colophon">Theme footer</footer><!--wp_footer-->' . ghca_print_enqueued( true ) . '</body></html>'; }
/** What wp_head() / wp_footer() would print for the enqueued plugin assets. */
function ghca_print_enqueued( bool $footer = false ): string {
	if ( $footer ) {
		return implode( '', array_map( static function ( $src ) { return '<script src="' . esc_url( $src ) . '"></script>'; }, $GLOBALS['rec']['scripts'] ) );
	}
	return implode( '', array_map( static function ( $src ) { return '<link rel="stylesheet" href="' . esc_url( $src ) . '">'; }, $GLOBALS['rec']['styles'] ) );
}

// Main query as WordPress leaves it for a rewrite with no post: the blog home, or a 404 when rules are not flushed.
class WP_Query { public $is_home = false; public $is_404 = false; public $is_archive = false; public $is_paged = false; public $is_search = false; public $is_posts_page = false; }
$GLOBALS['wp_query']           = new WP_Query();
$GLOBALS['wp_query']->is_home = 'home' === $sc['query'];
$GLOBALS['wp_query']->is_404  = '404' === $sc['query'];

// GHCA collaborators the page uses.
final class GHCA_ACD_Roles { public static function user_can_view(): bool { return (bool) $GLOBALS['sc']['can_view']; } }
final class GHCA_ACD_Settings { public static function ui_theme(): string { return 'akansoh'; } public static function ui_redesign_enabled(): bool { return true; } }
final class GHCA_Admin_Compliance_Dashboard { const VERSION = '1.7.3-rc.8'; }
final class GHCA_ACD_Data_Provider { public static function get_admin_dashboard_url(): string { return 'https://staging.test/compliance-admin-dashboard/'; } public static function get_page_url( $s, $f ) { return 'https://staging.test' . $f; } }
final class GHCA_Dashboard_Branding { public static function get(): array { return array( 'primary' => '#2f5d3a' ); } }

require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-feed.php';
require __DIR__ . '/../includes/odp/class-odp-access.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require __DIR__ . '/../includes/class-redesign.php';
require __DIR__ . '/../includes/odp/class-odp-bulletins.php';

// ---- Feed: a fresh cached copy (nothing is fetched), or no cache (the fetch is refused, as on staging).
$now  = time();
$item = static function ( string $id, string $headline, string $audience ) use ( $now ): array {
	return array( 'id' => $id, 'headline' => $headline, 'preview' => 'Synthetic preview for ' . $headline, 'body' => "First paragraph of $headline.\n\nSecond paragraph.", 'published_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 * ( 'synthetic-employee' === $id ? 2 : 1 ) ), 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 * 30 ), 'audience' => $audience, 'source_url' => 'https://example.test/source' );
};
if ( 'fresh' === $sc['feed'] ) {
	$GLOBALS['options'][ GHCA_ODP_Bulletins::CACHE_OPTION ] = array(
		'fetched_at' => $now,
		'feed'       => array( 'schema_version' => 1, 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ), 'bulletins' => array(
			$item( 'synthetic-both', 'Synthetic bulletin for everyone', 'both' ),
			$item( 'synthetic-employee', 'Synthetic bulletin for employees', 'employee' ),
			$item( 'synthetic-agency', 'Synthetic bulletin for agency leads', 'agency_lead' ),
		) ),
	);
}

$_SERVER['REQUEST_URI'] = $sc['uri'];
$_GET = $sc['get'] ?? array();
GHCA_ODP_Bulletins::render_screen();
echo '@@NOT-A-BULLETIN-ROUTE@@';
