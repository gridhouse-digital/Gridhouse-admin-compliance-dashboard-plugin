<?php
/**
 * Local preview and AJAX fixture for the redesigned employee drawer. Synthetic employee, not live data,
 * and not a WordPress screen. The page is the plugin's own wrapper and shell; the AJAX mode runs the
 * plugin's real drawer handler.
 *
 *   php tests/render-drawer-preview.php page /tmp/ghca-drawer [theme] [hostile]
 *   php tests/render-drawer-preview.php ajax '{"user_id":"42","ghca_ui":"board"}' ['{"can_docs":false}']
 */
require __DIR__ . '/fixtures/redesign-drawer-env.php';

$mode = $argv[1] ?? 'page';

if ( 'ajax' === $mode ) {
	ghca_env_reset( array_merge( array( 'base' => 'https://preview.test' ), json_decode( $argv[3] ?? '{}', true ) ?: array() ) );
	$r = ghca_env_drawer( json_decode( $argv[2] ?? '{}', true ) ?: array() );
	echo json_encode( $r['payload'] );
	exit( 0 );
}

$out     = $argv[2] ?? '/tmp/ghca-drawer';
$theme   = $argv[3] ?? 'gridhouse';
$hostile = ( $argv[4] ?? '' ) === 'hostile';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}
ghca_env_reset( json_decode( getenv( 'GHCA_PREVIEW_ENV' ) ?: '{}', true ) ?: array() );

$assets = getenv( 'GHCA_PREVIEW_ASSETS' ) ?: realpath( __DIR__ . '/../assets' );
$agg    = array( 'total_employees' => 10, 'completed_employees' => 3, 'expiring_soon_employees' => 3, 'in_progress_employees' => 2, 'overdue_employees' => 2, 'compliance_rate' => 60, 'certificates_issued' => 8 );
$panel  = '<div class="gh-card gh-tcard"><p>Synthetic employee list.</p><a class="gh-btn gh-btn--secondary" id="open-drawer" href="https://example.test/user-report?user_id=42" data-ghca-employee-drawer="42">Open Jordan Ellis</a></div>';
$page   = GHCA_ACD_Redesign::render_from_model( array(
	'theme'           => $theme,
	'scope'           => 'Scope: all 3 groups',
	'as_of'           => 'As of today, 1:24 PM',
	'active'          => '10 active employees',
	'actions'         => array(),
	'tabs'            => array(
		array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ),
		array( 'id' => 'ghca-tab-employees', 'label' => 'Employees', 'badge' => 10, 'active' => false ),
	),
	'kpis'            => GHCA_ACD_Redesign::kpis_from_aggregate( $agg, 6 ),
	'health'          => GHCA_ACD_Redesign::health_from_aggregate( $agg ),
	'groups'          => array(),
	'certificates'    => array( 'issued' => 8, 'recent' => 4, 'missing' => 1 ),
	'panels'          => array( 'ghca-tab-employees' => $panel ),
	'board_panels'    => array( 'ghca-tab-employees' ),
	'employee_drawer' => true,
	'flash'           => array(),
) );
// wp_footer prints the certificate modal in WordPress; it stays the legacy modal in this step.
ob_start();
GHCA_ACD_AJAX::render_certificate_modal();
$page .= ob_get_clean();

$tabs_css  = '.ghca-acd__tab-content{display:none}.ghca-acd__tab-content.is-active{display:block}html.ghca-acd--overlay-open,html.ghca-acd--drawer-open{overflow:hidden}';
// WordPress loads the legacy stylesheet first: gh-redesign.css depends on it. Load it here in the same order.
$legacy_css = '<link rel="stylesheet" href="' . $assets . '/dashboard.css">';
$theme_css  = $tabs_css . ( $hostile
	? 'body{font-family:Georgia,serif;font-size:18px;line-height:1.7;color:#333;margin:0}a{color:#0645ad!important;text-decoration:underline!important}a:hover{background:#cfe!important}button:hover{background:#00f!important;color:#fff!important}h1,h2,h3,h4{font-family:Georgia,serif!important;text-transform:uppercase!important;letter-spacing:.1em}table,dl,dt,dd{margin:20px}th,td{border:2px solid #f0f!important}label{text-transform:uppercase}summary{font-size:30px;list-style:square}'
	: 'body{font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#333;margin:0}' );

file_put_contents( $out . '/drawer.html', '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Synthetic drawer preview</title>'
	. $legacy_css . '<link rel="stylesheet" href="' . $assets . '/gh-redesign.css"><style>' . $theme_css . '</style></head><body class="ghca-acd-page">'
	. '<p style="margin:0;padding:6px 16px;background:#222;color:#fff;font:600 12px/1.2 sans-serif">SYNTHETIC PREVIEW. Not live data.</p>' . $page
	. '<script>window.ghcaAcd={ajaxUrl:"https://preview.test/ajax",nonce:"test",loading:"Loading…"};</script><script src="' . $assets . '/dashboard.js"></script></body></html>' );
echo "wrote $out/drawer.html\n";
