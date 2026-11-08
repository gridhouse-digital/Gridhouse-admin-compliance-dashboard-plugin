<?php
/**
 * Local preview and AJAX fixture for the redesigned Overdue, Courses and Certificates tabs. Synthetic
 * employees, not live data, and not a WordPress screen. The page is the plugin's own wrapper and
 * renderers; the AJAX mode runs the plugin's real filter-table handler.
 *
 *   php tests/render-lists-preview.php page /tmp/ghca-lists [theme] [hostile]
 *   php tests/render-lists-preview.php ajax '{"ghca_table":"priority","ghca_ui":"board"}'
 *
 * Permissions come from GHCA_PREVIEW_PERMS, a comma list of remind, docs, comms, admin (default: all four; "none" for none).
 */
require __DIR__ . '/fixtures/redesign-lists-env.php';
require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require_once __DIR__ . '/../includes/class-course-title.php';
require __DIR__ . '/../includes/class-redesign.php';

$perms = array_filter( explode( ',', (string) ( getenv( 'GHCA_PREVIEW_PERMS' ) ?: 'remind,docs,comms,admin' ) ) );
$GLOBALS['ghca_env'] += array(
	'can_remind' => in_array( 'remind', $perms, true ),
	'can_docs'   => in_array( 'docs', $perms, true ),
	'can_comms'  => in_array( 'comms', $perms, true ),
	'can_admin'  => in_array( 'admin', $perms, true ),
);
GHCA_ACD_Redesign_Lists::$now = $GLOBALS['ghca_env']['now'];

$mode = $argv[1] ?? 'page';
if ( 'ajax' === $mode ) {
	ghca_lists_seed();
	$post = json_decode( $argv[2] ?? '{}', true ) ?: array();
	$r    = ghca_env_ajax( $post );
	echo json_encode( $r['payload'] );
	exit( 0 );
}

$out     = $argv[2] ?? '/tmp/ghca-lists';
$theme   = $argv[3] ?? 'gridhouse';
$hostile = ( $argv[4] ?? '' ) === 'hostile';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}
ghca_lists_seed();
$agg = GHCA_ACD_Data_Provider::get_aggregate();

$assets = getenv( 'GHCA_PREVIEW_ASSETS' ) ?: realpath( __DIR__ . '/../assets' );
$model  = array(
	'theme'        => $theme,
	'brand_style'  => getenv( 'GHCA_PREVIEW_BRAND' ) ? GHCA_ACD_Redesign::agency_brand_style( (string) getenv( 'GHCA_PREVIEW_BRAND' ) ) : '',
	'scope'        => 'Scope: all 3 groups',
	'as_of'        => 'As of today, 1:24 PM',
	'active'       => count( $agg['employees'] ) . ' active employees',
	'actions'      => array(),
	'tabs'         => array(
		array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ),
		array( 'id' => 'ghca-tab-overdue', 'label' => 'Overdue & at risk', 'badge' => 3, 'tone' => 'danger', 'active' => false ),
		array( 'id' => 'ghca-tab-courses', 'label' => 'Courses', 'badge' => GHCA_ACD_Data_Provider::count_unique_tracked_courses( $agg['employees'] ), 'active' => false ),
		array( 'id' => 'ghca-tab-certificates', 'label' => 'Certificates', 'badge' => null, 'active' => false ),
	),
	'kpis'         => GHCA_ACD_Redesign::kpis_from_aggregate( array( 'total_employees' => 6, 'completed_employees' => 1, 'overdue_employees' => 3, 'compliance_rate' => 17, 'certificates_issued' => (int) $agg['certificates_issued'] ), 7 ),
	'health'       => GHCA_ACD_Redesign::health_from_aggregate( array( 'total_employees' => 6, 'completed_employees' => 1, 'overdue_employees' => 3 ) ),
	'groups'       => array(),
	'certificates' => array( 'issued' => (int) $agg['certificates_issued'], 'recent' => (int) $agg['recently_completed'], 'missing' => (int) $agg['certificates_missing'] ),
	'panels'       => array(
		'ghca-tab-overdue'      => GHCA_ACD_Redesign_Lists::render_overdue(),
		'ghca-tab-courses'      => GHCA_ACD_Redesign_Lists::render_courses(),
		'ghca-tab-certificates' => GHCA_ACD_Redesign_Lists::render_certificates(),
	),
	'board_panels' => array( 'ghca-tab-overdue', 'ghca-tab-courses', 'ghca-tab-certificates' ),
	'flash'        => array(),
);
$page = GHCA_ACD_Redesign::render_from_model( $model );

// The same stand-in theme as the slice-2 and slice-3 previews.
$tabs_css  = '.ghca-acd__tab-content{display:none}.ghca-acd__tab-content.is-active{display:block}';
$theme_css = $tabs_css . ( $hostile
	? 'body{font-family:Georgia,serif;font-size:18px;line-height:1.7;color:#333;margin:0}a{color:#0645ad!important;text-decoration:underline!important}a:hover{background:#cfe!important}button:hover,input[type=submit]:hover{background:#00f!important;color:#fff!important}h1,h2,h3{font-family:Georgia,serif!important;text-transform:uppercase!important;letter-spacing:.1em}table{border-collapse:collapse}th,td{border:2px solid #f0f!important}select,input[type=search],input[type=text]{border:3px solid #f0f;border-radius:0;height:60px;padding:14px;font-family:Georgia,serif;font-size:20px}label{text-transform:uppercase;font-weight:400;display:inline}summary{font-size:22px;list-style:square}ul,ol{margin:2em;padding-left:3em}li{margin-bottom:1em}'
	: 'body{font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#333;margin:0}' );

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Synthetic lists preview</title>'
	. '<link rel="stylesheet" href="' . $assets . '/dashboard.css"><link rel="stylesheet" href="' . $assets . '/gh-redesign.css"><style>' . $theme_css . '</style></head><body class="ghca-acd-page">'
	. '<p style="margin:0;padding:6px 16px;background:#222;color:#fff;font:600 12px/1.2 sans-serif">SYNTHETIC PREVIEW. Not live data.</p>' . $page
	. '<script>window.ghcaAcd={ajaxUrl:"https://example.test/ajax",nonce:"test",tableError:"Could not update the table.",tableNetworkError:"Could not reach the server.",certDownloadError:"The certificate could not be downloaded. Nothing was saved."};</script>'
	. '<script src="' . $assets . '/dashboard.js"></script></body></html>';
file_put_contents( $out . '/lists.html', $html );
echo "wrote $out/lists.html\n";
