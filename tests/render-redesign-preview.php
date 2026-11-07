<?php
/**
 * Writes synthetic HTML previews for local screenshots. Not a WordPress screen.
 * Run: php tests/render-redesign-preview.php /tmp/ghca-preview
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url, $protocols = null ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require __DIR__ . '/../includes/class-redesign.php';

$out = $argv[1] ?? '/tmp/ghca-preview';
$preview_theme = getenv( 'GHCA_PREVIEW_THEME' ) ?: 'gridhouse';
$preview_brand = getenv( 'GHCA_PREVIEW_BRAND' ) ? GHCA_ACD_Redesign::agency_brand_style( (string) getenv( 'GHCA_PREVIEW_BRAND' ) ) : '';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}
$css = realpath( __DIR__ . '/../assets/gh-redesign.css' );
$js  = realpath( __DIR__ . '/../assets/gh-bulletins.js' );
$banner = '<p class="gh-odp-status">SYNTHETIC PREVIEW. Not live compliance data and not a bulletin from the worker.</p>';

// Twelve synthetic employees: 3 compliant, 3 expiring soon, 2 in progress, 2 overdue, 2 not started.
// GHCA_PREVIEW_ZERO_OVERDUE=1 moves the two overdue employees to in progress, to show the Overdue tile at zero.
$zero_overdue = '1' === getenv( 'GHCA_PREVIEW_ZERO_OVERDUE' );
$slugs        = array( 'completed', 'completed', 'completed', 'expiring_soon', 'expiring_soon', 'expiring_soon', 'in_progress', 'in_progress', $zero_overdue ? 'in_progress' : 'overdue', $zero_overdue ? 'in_progress' : 'overdue', 'not_started', 'not_started' );
$agg          = array(
	'employees'               => array_map( static function ( $slug ) { return array( 'status_slug' => $slug ); }, $slugs ),
	'total_employees'         => 12,
	'completed_employees'     => 3,
	'expiring_soon_employees' => 3,
	'in_progress_employees'   => $zero_overdue ? 4 : 2,
	'overdue_employees'       => $zero_overdue ? 0 : 2,
	'compliance_rate'         => 50,
	'certificates_issued'     => 8,
);
$health = GHCA_ACD_Redesign::health_from_aggregate( $agg );
$kpis   = GHCA_ACD_Redesign::kpis_from_aggregate( $agg, 6 );
$groups = GHCA_ACD_Redesign::sort_groups( array(
	array( 'label' => 'Synthetic annual', 'risk' => 'high', 'rate' => 19, 'overdue' => 1, 'employees' => 2 ),
	array( 'label' => 'Synthetic new hire', 'risk' => 'high', 'rate' => 33, 'overdue' => 1, 'employees' => 2 ),
	array( 'label' => 'Synthetic RN', 'risk' => 'low', 'rate' => 80, 'overdue' => 0, 'employees' => 2 ),
	array( 'label' => 'Synthetic LPN', 'risk' => 'medium', 'rate' => 44, 'overdue' => 0, 'employees' => 1 ),
) );
$overview = GHCA_ACD_Redesign::render_from_model( array(
	'theme' => $preview_theme,
	'brand_style' => $preview_brand,
	'scope' => 'Scope: all 4 groups',
	'as_of' => 'As of today, 1:24 PM',
	'active' => '10 active employees',
	'actions' => array(
		array( 'label' => 'Sync', 'url' => 'https://example.test/sync', 'variant' => 'secondary', 'tab' => '' ),
		array( 'label' => 'Export', 'url' => 'https://example.test/export', 'variant' => 'secondary', 'tab' => '' ),
		array( 'label' => 'Manage users', 'url' => 'https://example.test/users', 'variant' => 'secondary', 'tab' => '' ),
		array( 'label' => 'View reports', 'url' => 'https://example.test/dashboard#ghca-tab-reports', 'variant' => 'primary', 'tab' => 'ghca-tab-reports' ),
	),
	'tabs' => array(
		array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ),
		array( 'id' => 'ghca-tab-employees', 'label' => 'Employees', 'badge' => 10, 'active' => false ),
		array( 'id' => 'ghca-tab-overdue', 'label' => 'Overdue & at risk', 'badge' => 2, 'active' => false ),
		array( 'id' => 'ghca-tab-courses', 'label' => 'Courses', 'badge' => 6, 'active' => false ),
	),
	'kpis' => $kpis,
	'health' => $health,
	'groups' => $groups,
	'certificates' => array( 'issued' => 8, 'recent' => 4, 'missing' => 1 ),
	'bulletin_html' => GHCA_ODP_UI::ticker( array(
		'state' => 'fresh',
		'library_url' => 'library.html',
		'items' => array(
			array( 'id' => 'synthetic-notice', 'url' => 'bulletin.html', 'published_at' => '2026-11-01T15:00:00Z', 'date_label' => 'Nov 1', 'headline' => 'Synthetic headline for the ticker' ),
		),
	) ),
	'announcements_html' => '<section class="gh-card"><h2>Announcements</h2><p>No announcements yet.</p></section>',
	'panels' => array( 'ghca-tab-employees' => '<p>Existing employees table.</p>' ),
	'drawer_html' => GHCA_ODP_UI::drawer( array( 'library_url' => 'library.html' ) ) . GHCA_ODP_UI::payload_script( array( 'payload' => array(
		'bulletins' => array(
			'synthetic-notice' => array(
				'headline' => 'Synthetic headline for the ticker',
				'date_label' => 'Nov 1',
				'audience_label' => 'Employees and agency leads',
				'paragraphs' => array( 'First paragraph of the synthetic body.', 'Second paragraph with Tom & Jerry.' ),
				'url' => 'bulletin.html',
				'source_url' => 'https://example.test/source',
			),
		),
	) ) ),
) );
$wrap = static function ( $title, $body ) use ( $css, $js, $banner ) {
	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title><link rel="stylesheet" href="' . $css . '"></head><body class="gh-ui-body">' . $banner . $body . '<script src="' . $js . '"></script></body></html>';
};
file_put_contents( $out . '/overview.html', $wrap( 'Synthetic overview', $overview ) );

$library_body = GHCA_ODP_UI::library( array(
	'state' => 'fresh',
	'context' => 'admin',
	'kicker' => 'Agency bulletins',
	'action' => 'library.html',
	'query' => '',
	'items' => array( array(
		'id' => 'synthetic-notice',
		'headline' => 'Synthetic headline for the ticker',
		'preview' => 'Synthetic preview only. The full page uses the body.',
		'published_at' => '2026-11-01T15:00:00Z',
		'date_label' => 'Nov 1, 2026',
		'audience_label' => 'Employees and agency leads',
		'url' => 'bulletin.html',
	) ),
	'total' => 1, 'from' => 1, 'to' => 1, 'prev_url' => '', 'next_url' => '',
	'payload' => array( 'bulletins' => array() ),
) );
$empty_body = GHCA_ODP_UI::library( array(
	'state' => 'empty', 'context' => 'admin', 'kicker' => 'Agency bulletins', 'action' => 'empty.html', 'query' => '',
	'items' => array(), 'total' => 0, 'from' => 0, 'to' => 0, 'prev_url' => '', 'next_url' => '', 'payload' => array(),
) );
$single = GHCA_ODP_UI::single( array(
	'result' => 'current',
	'library_url' => 'library.html',
	'kicker' => 'ODP Bulletin',
	'audience_note' => '',
	'item' => array(
		'headline' => 'Synthetic headline for the ticker',
		'body' => "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry.",
		'published_at' => '2026-11-01T15:00:00Z',
		'date_label' => 'Nov 1, 2026',
		'audience_label' => 'Employees and agency leads',
		'url' => 'bulletin.html',
		'source_url' => 'https://example.test/source',
	),
) );
$shell = static function ( $title, $body ) use ( $css, $js, $banner, $preview_theme, $preview_brand ) {
	return GHCA_ODP_UI::document( array(
		'title' => $title,
		'lang' => 'en',
		'css' => $css,
		'js' => $js,
		'context' => 'admin',
		'theme' => $preview_theme,
	'brand_style' => $preview_brand,
		'home_url' => 'overview.html',
		'home_label' => 'Compliance dashboard',
		'context_label' => 'Agency view',
		'library_url' => 'library.html',
		'body_html' => $banner . $body,
	) );
};
file_put_contents( $out . '/library.html', $shell( 'Synthetic library', $library_body ) );
file_put_contents( $out . '/empty.html', $shell( 'Synthetic empty', $empty_body ) );
file_put_contents( $out . '/bulletin.html', $shell( 'Synthetic bulletin', $single ) );
echo "wrote previews in $out\n";

// In-theme versions (what the site serves since the theme-header correction): the same bodies inside a
// stand-in classic theme with its own header, footer, 1100px content container and typical theme CSS.
$themed_library = GHCA_ODP_UI::library( array(
	'state' => 'fresh', 'context' => 'admin', 'kicker' => 'Agency bulletins', 'action' => 'library-theme.html', 'query' => '',
	'home_url' => 'overview.html', 'home_label' => 'Compliance dashboard',
	'items' => array( array(
		'id' => 'synthetic-notice', 'headline' => 'Synthetic headline for the ticker',
		'preview' => 'Synthetic preview only. The full page uses the body.', 'published_at' => '2026-11-01T15:00:00Z',
		'date_label' => 'Nov 1, 2026', 'audience_label' => 'Employees and agency leads', 'url' => 'bulletin-theme.html',
	) ),
	'total' => 1, 'from' => 1, 'to' => 1, 'prev_url' => '', 'next_url' => '',
	'payload' => array( 'disclaimer' => GHCA_ODP_Contract::DISCLAIMER, 'order' => array( 'synthetic-notice' ), 'bulletins' => array( 'synthetic-notice' => array(
		'id' => 'synthetic-notice', 'headline' => 'Synthetic headline for the ticker', 'preview' => 'Synthetic preview only.',
		'body' => 'First paragraph of the synthetic body.', 'paragraphs' => array( 'First paragraph of the synthetic body.' ),
		'published_at' => '2026-11-01T15:00:00Z', 'date_label' => 'Nov 1, 2026', 'date_long' => 'November 1, 2026',
		'audience_label' => 'Employees and agency leads', 'url' => 'bulletin-theme.html', 'source_url' => null, 'source_host' => '',
	) ) ),
) );
$themed_single = GHCA_ODP_UI::single( array(
	'result' => 'current', 'library_url' => 'library-theme.html', 'kicker' => 'ODP Bulletin', 'audience_note' => '',
	'home_url' => 'overview.html', 'home_label' => 'Compliance dashboard', 'more' => array(),
	'item' => array(
		'headline' => 'Synthetic headline for the ticker', 'body' => "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry.",
		'paragraphs' => array( 'First paragraph of the synthetic body.', 'Second paragraph with Tom & Jerry.' ),
		'published_at' => '2026-11-01T15:00:00Z', 'date_label' => 'Nov 1, 2026', 'audience_label' => 'Employees and agency leads',
		'url' => 'bulletin-theme.html', 'source_url' => 'https://example.test/source', 'source_host' => 'example.test',
	),
) );
$hostile_theme = getenv( 'GHCA_PREVIEW_HOSTILE' ) === '1';
$theme_css     = 'body{margin:0;background:#fff;font-family:Georgia,serif;font-size:17px;line-height:1.7;color:#222}.site-header{background:#123;color:#fff;padding:18px 24px;display:flex;justify-content:space-between;align-items:center}.site-header a{color:#fff}.site-content{max-width:1100px;margin:0 auto;padding:0 20px}.site-footer{background:#222;color:#ddd;padding:24px}'
	. ( $hostile_theme ? 'a{color:#0645ad!important;text-decoration:underline!important}h1,h2,h3{font-family:Georgia,serif!important;text-transform:uppercase!important}main{padding:40px!important;background:#fffbe6}ul,ol{margin:2em;padding-left:3em}button:hover{background:#00f!important;color:#fff!important}' : '' );
$themed = static function ( $title, $body ) use ( $css, $js, $theme_css, $preview_theme, $preview_brand ) {
	$frag = GHCA_ODP_UI::themed( array( 'context' => 'admin', 'theme' => $preview_theme, 'brand_style' => $preview_brand, 'library_url' => 'library-theme.html', 'body_html' => $body ) );
	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title><style>' . $theme_css . '</style><link rel="stylesheet" href="' . $css . '"></head>'
		. '<body class="page gh-odp-page"><header class="site-header" id="site-header"><a class="site-logo" href="#">Akanso Staging</a><nav class="site-nav"><a href="#">Home</a> <a href="#">Dashboard</a></nav></header>'
		. '<div class="site-content" id="content">' . $frag . '</div><footer class="site-footer" id="site-footer">Theme footer</footer><script src="' . $js . '"></script></body></html>';
};
file_put_contents( $out . '/library-theme.html', $themed( 'ODP Bulletins – Akanso Staging', $themed_library ) );
file_put_contents( $out . '/bulletin-theme.html', $themed( 'Synthetic bulletin – Akanso Staging', $themed_single ) );
echo "wrote in-theme previews in $out\n";
