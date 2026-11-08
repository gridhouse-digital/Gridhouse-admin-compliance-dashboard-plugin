<?php
/**
 * Theme-integrated pages for the page-background check. A stand-in classic theme (its own body colour, a header
 * with a shadow, transparent #content padding above and below, a footer) hosts:
 *   on.html        the redesigned dashboard; body classes from the plugin's own add_body_class(), redesign on
 *   off.html       the legacy dashboard; body classes from add_body_class(), redesign off; gh-redesign.css not loaded
 *   unrelated.html an ordinary page with both plugin stylesheets loaded anyway (worst case)
 *   bulletin.html  an ODP bulletin page inside the theme, with the body classes the bulletin route adds
 * The dashboard markup is the plugin's own (GHCA_ACD_Redesign and the legacy GHCA_ACD_Shortcodes renderers) on
 * synthetic records. Not WordPress, not the Akansoh theme.
 *
 *   php tests/render-page-background-preview.php <dir>
 */
require __DIR__ . '/fixtures/redesign-lists-env.php';
require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require_once __DIR__ . '/../includes/class-course-title.php';
require __DIR__ . '/../includes/class-redesign.php';

$out = $argv[1] ?? '/tmp/ghca-bg';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}
$assets = realpath( __DIR__ . '/../assets' );
$GLOBALS['ghca_env'] += array( 'can_remind' => true, 'can_docs' => true );
ghca_lists_seed();
GHCA_ACD_Redesign_Lists::$now = $GLOBALS['ghca_env']['now'];

/**
 * The body classes the plugin's own GHCA_Admin_Compliance_Dashboard::add_body_class() returns. That method needs
 * the plugin entry file, which this fixture does not load; tests/test-page-background.php calls it for real and
 * asserts exactly these two lists, so the preview uses them.
 */
$body_on  = 'page page-id-2716 ghca-acd-page ghca-ui-page';
$body_off = 'page page-id-2716 ghca-acd-page';

$theme_css = '*,*::before,*::after{box-sizing:border-box}body{margin:0;background:#fafbfd;font-family:Georgia,serif;color:#222}'
	. 'body.bb-buddypanel{background-color:#fafbfd}'
	. '.site-header{position:relative;z-index:2;background:#fff;height:72px;display:flex;align-items:center;padding:0 24px;box-shadow:0 2px 12px rgba(18,43,70,.12)}'
	. '#content.site-content{padding:48px 24px;background:transparent}'
	. '.site-footer{background:#1d2733;color:#ddd;padding:24px}';

$page = static function ( string $body_class, string $content, bool $redesign_css ) use ( $assets, $theme_css ): string {
	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Background check</title>'
		. '<style>' . $theme_css . '</style>'
		. '<link rel="stylesheet" href="' . $assets . '/dashboard.css">'
		. ( $redesign_css ? '<link rel="stylesheet" href="' . $assets . '/gh-redesign.css">' : '' )
		. '<style>.ghca-acd__tab-content{display:none}.ghca-acd__tab-content.is-active{display:block}</style>'
		. '</head><body class="' . $body_class . ' bb-buddypanel"><header class="site-header" id="site-header">Akansoh Health Services</header>'
		. '<div id="content" class="site-content"><div id="primary" class="content-area"><main id="main" class="site-main">' . $content . '</main></div></div>'
		. '<footer class="site-footer" id="site-footer">Theme footer</footer></body></html>';
};

$agg   = GHCA_ACD_Data_Provider::get_aggregate();
$model = array(
	'theme'        => 'akansoh',
	'scope'        => 'Scope: all 8 groups',
	'as_of'        => 'As of today, 10:07 AM',
	'active'       => '6 active employees',
	'actions'      => array(),
	'tabs'         => array( array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ) ),
	'kpis'         => GHCA_ACD_Redesign::kpis_from_aggregate( array( 'total_employees' => 6, 'completed_employees' => 1, 'overdue_employees' => 3, 'compliance_rate' => 17, 'certificates_issued' => (int) $agg['certificates_issued'] ), 7 ),
	'health'       => GHCA_ACD_Redesign::health_from_aggregate( array( 'total_employees' => 6, 'completed_employees' => 1, 'overdue_employees' => 3 ) ),
	'groups'       => array(),
	'certificates' => array( 'issued' => (int) $agg['certificates_issued'], 'recent' => (int) $agg['recently_completed'], 'missing' => (int) $agg['certificates_missing'] ),
	'panels'       => array(),
	'board_panels' => array(),
	'flash'        => array(),
);
file_put_contents( $out . '/on.html', $page( $body_on, GHCA_ACD_Redesign::render_from_model( $model ) . '<div class="ghca-ui"><div class="gh-app" data-theme="akansoh"><div class="gh-card" id="probe-card" style="padding:16px"><input class="gh-input" id="probe-input" value="x"> <button class="gh-btn gh-btn--primary" id="probe-btn" type="button">Primary</button></div></div></div>', true ) );

$GLOBALS['ghca_env']['redesign'] = false;
$legacy = GHCA_ACD_Shortcodes::render_overdue_employees() . GHCA_ACD_Shortcodes::render_course_overview();
file_put_contents( $out . '/off.html', $page( $body_off, $legacy, false ) );

file_put_contents( $out . '/unrelated.html', $page( 'page page-id-99', '<article style="padding:24px;background:#fff">An ordinary page.</article>', true ) );

$frag = GHCA_ODP_UI::themed( array( 'context' => 'admin', 'theme' => 'akansoh', 'library_url' => '#', 'body_html' => '<section class="gh-odp-col"><h1 class="gh-bp-h">Synthetic bulletin</h1><p>Body.</p></section>' ) );
file_put_contents( $out . '/bulletin.html', $page( 'gh-odp-page ghca-ui-page', $frag, true ) );
echo "wrote background previews in $out\n";
