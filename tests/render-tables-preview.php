<?php
/**
 * Local preview and AJAX fixture for the redesigned Employees and Inactive tabs. Synthetic employees,
 * not live data, and not a WordPress screen. The page is the plugin's own wrapper and renderer; the
 * AJAX mode runs the plugin's real filter-table handler.
 *
 *   php tests/render-tables-preview.php page /tmp/ghca-tables [theme] [hostile]
 *   php tests/render-tables-preview.php ajax '{"ghca_table":"employees","ghca_ui":"board"}' [count]
 */
require __DIR__ . '/fixtures/redesign-tables-env.php';
require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require __DIR__ . '/../includes/class-redesign.php';

$mode = $argv[1] ?? 'page';

if ( 'ajax' === $mode ) {
	ghca_env_seed( (int) ( $argv[3] ?? 10 ) );
	$post = json_decode( $argv[2] ?? '{}', true ) ?: array();
	$r    = ghca_env_ajax( $post );
	echo json_encode( $r['payload'] );
	exit( 0 );
}

$out     = $argv[2] ?? '/tmp/ghca-tables';
$theme   = $argv[3] ?? 'gridhouse';
$hostile = ( $argv[4] ?? '' ) === 'hostile';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}
$count = (int) ( getenv( 'GHCA_PREVIEW_COUNT' ) ?: 10 );
ghca_env_seed( $count );

// Assets load from the plugin folder by default; the browser test serves them from a routed https origin instead.
$assets = getenv( 'GHCA_PREVIEW_ASSETS' ) ?: realpath( __DIR__ . '/../assets' );
$css    = $assets . '/gh-redesign.css';
$js     = $assets . '/dashboard.js';

$aggregate = array( 'total_employees' => $count, 'completed_employees' => 3, 'expiring_soon_employees' => 3, 'in_progress_employees' => 2, 'overdue_employees' => 2, 'compliance_rate' => 60, 'certificates_issued' => 8 );
$model     = array(
	'theme'        => $theme,
	'brand_style'  => getenv( 'GHCA_PREVIEW_BRAND' ) ? GHCA_ACD_Redesign::agency_brand_style( (string) getenv( 'GHCA_PREVIEW_BRAND' ) ) : '',
	'scope'        => 'Scope: all 3 groups',
	'as_of'        => 'As of today, 1:24 PM',
	'active'       => $count . ' active employees',
	'actions'      => array(
		array( 'key' => 'sync', 'label' => 'Sync', 'url' => 'https://example.test/sync', 'variant' => 'secondary', 'tab' => '' ),
		array( 'key' => 'export', 'label' => 'Export', 'url' => 'https://example.test/export', 'variant' => 'secondary', 'tab' => '' ),
		array( 'key' => 'reports', 'label' => 'View reports', 'url' => '#ghca-tab-reports', 'variant' => 'primary', 'tab' => 'ghca-tab-reports' ),
	),
	'tabs'         => array(
		array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ),
		array( 'id' => 'ghca-tab-employees', 'label' => 'Employees', 'badge' => $count, 'active' => false ),
		array( 'id' => 'ghca-tab-overdue', 'label' => 'Overdue', 'badge' => 2, 'tone' => 'danger', 'active' => false ),
		array( 'id' => 'ghca-tab-inactive-employees', 'label' => 'Inactive', 'badge' => 2, 'active' => false ),
	),
	'kpis'         => GHCA_ACD_Redesign::kpis_from_aggregate( $aggregate, 6 ),
	'health'       => GHCA_ACD_Redesign::health_from_aggregate( $aggregate ),
	'groups'       => array(),
	'certificates' => array( 'issued' => 8, 'recent' => 4, 'missing' => 1 ),
	'panels'       => array(
		'ghca-tab-employees'          => GHCA_ACD_Redesign_Tables::render_employees(),
		'ghca-tab-overdue'            => '<p>Existing overdue table.</p>',
		'ghca-tab-inactive-employees' => GHCA_ACD_Redesign_Tables::render_employees( array(), 'inactive' ),
	),
	'board_panels' => array( 'ghca-tab-employees', 'ghca-tab-inactive-employees' ),
	'flash'        => 'flash' === getenv( 'GHCA_PREVIEW_FLASH' ) ? array(
		array( 'tone' => 'success', 'title' => 'Dashboard data refreshed.', 'text' => '' ),
		array( 'tone' => 'success', 'title' => 'Compliance reminder logged successfully.', 'text' => 'A note was added to the FluentCRM contact when available.' ),
	) : array(),
);
$page = GHCA_ACD_Redesign::render_from_model( $model );
// wp_footer prints the employee drawer shell in WordPress; the dashboard script binds to it. The drawer itself is not part of this slice.
ob_start();
GHCA_ACD_AJAX::render_employee_drawer_modal();
$page .= ob_get_clean();

// A stand-in for the theme: a serif body, and the kind of !important link, button, heading and table rules
// that WordPress themes and page builders ship. The redesign has to hold its own against these.
// dashboard.css hides inactive tab panels in WordPress; the preview loads only the redesign sheet, so say so here.
$tabs_css  = '.ghca-acd__tab-content{display:none}.ghca-acd__tab-content.is-active{display:block}';
$theme_css = $tabs_css . ( $hostile
	? 'body{font-family:Georgia,serif;font-size:18px;line-height:1.7;color:#333;margin:0}a{color:#0645ad!important;text-decoration:underline!important}a:hover{background:#cfe!important}button:hover,input[type=submit]:hover{background:#00f!important;color:#fff!important}h1,h2,h3{font-family:Georgia,serif!important;text-transform:uppercase!important;letter-spacing:.1em}table{border-collapse:collapse}th,td{border:2px solid #f0f!important}select,input[type=search],input[type=text]{border:3px solid #f0f;border-radius:0;height:60px;padding:14px;font-family:Georgia,serif;font-size:20px}label{text-transform:uppercase;font-weight:400;display:inline}'
	: 'body{font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#333;margin:0}' );

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Synthetic tables preview</title>'
	. '<link rel="stylesheet" href="' . $assets . '/dashboard.css"><link rel="stylesheet" href="' . $css . '"><style>' . $theme_css . '</style></head><body class="ghca-acd-page">'
	. '<p style="margin:0;padding:6px 16px;background:#222;color:#fff;font:600 12px/1.2 sans-serif">SYNTHETIC PREVIEW. Not live data.</p>' . $page
	. '<script>window.ghcaAcd={ajaxUrl:"https://preview.test/ajax",nonce:"test",tableError:"Could not update the table.",tableNetworkError:"Could not reach the server."};</script>'
	. '<script src="' . $js . '"></script></body></html>';
file_put_contents( $out . '/tables.html', $html );
echo "wrote $out/tables.html\n";
