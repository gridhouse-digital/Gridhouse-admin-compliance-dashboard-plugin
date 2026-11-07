<?php
/**
 * Synthetic ODP bulletin contract tests. No network, no WordPress, no employee records.
 * Run: php tests/test-odp-bulletins.php
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
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url, $protocols = null ) {
	$url = (string) $url;
	if ( preg_match( '/^\s*javascript:/i', $url ) || preg_match( '/^\s*data:/i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

require __DIR__ . '/../includes/odp/class-odp-contract.php';
require __DIR__ . '/../includes/odp/class-odp-feed.php';
require __DIR__ . '/../includes/odp/class-odp-access.php';
require __DIR__ . '/../includes/odp/class-odp-ui.php';
require __DIR__ . '/../includes/class-redesign.php';

$now = strtotime( '2026-11-06T12:00:00Z' );

function bulletin( $overrides = array() ) {
	$item = array(
		'id'           => 'synthetic-notice',
		'headline'     => 'Synthetic headline',
		'preview'      => 'Synthetic preview only',
		'body'         => "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry.",
		'published_at' => '2026-11-01T15:00:00Z',
		'expires_at'   => '2026-12-01T15:00:00Z',
		'audience'     => 'both',
		'source_url'   => 'https://example.test/source',
	);
	return array_merge( $item, $overrides );
}
function feed( $rows, $schema = 1, $updated = '2026-11-06T12:00:00Z' ) {
	return array(
		'schema_version' => $schema,
		'updated_at'     => $updated,
		'bulletins'      => $rows,
	);
}
function transport_of( $status, $body ) {
	return static function () use ( $status, $body ) {
		return array( 'status' => $status, 'body' => $body );
	};
}

$valid = GHCA_ODP_Contract::validate( feed( array( bulletin() ) ) );
check( ! empty( $valid['ok'] ) && 1 === count( $valid['feed']['bulletins'] ), 'Valid published bulletin is kept' );
check( GHCA_ODP_Contract::FEED_URL === 'https://gridhouse-odp-bulletins.gridhouse-digital10.workers.dev', 'Feed URL is the public worker' );
check( ! GHCA_ODP_Contract::is_allowlisted( 'https://evil.example/feed' ), 'Other hosts are not allowlisted' );

$empty = GHCA_ODP_Contract::validate( feed( array() ) );
check( ! empty( $empty['ok'] ) && array() === $empty['feed']['bulletins'], 'Empty bulletin list is a valid feed' );

$unknown = GHCA_ODP_Contract::validate( feed( array(), 2 ) );
check( empty( $unknown['ok'] ) && 'unknown_schema' === $unknown['code'], 'Unknown schema is rejected' );
$string_schema = GHCA_ODP_Contract::validate( array( 'schema_version' => '1', 'updated_at' => '2026-11-06T12:00:00Z', 'bulletins' => array() ) );
check( empty( $string_schema['ok'] ) && 'unknown_schema' === $string_schema['code'], 'String schema version is not version 1' );
check( empty( GHCA_ODP_Contract::validate( array( 'bulletins' => array() ) )['ok'] ), 'Missing envelope is malformed' );
check( empty( GHCA_ODP_Contract::validate( '[]' )['ok'] ), 'Non-object payload is malformed' );

$draft = GHCA_ODP_Contract::validate( feed( array(
	bulletin(),
	bulletin( array( 'id' => 'synthetic-draft', 'status' => 'draft', 'headline' => 'Draft must not ship' ) ),
) ) );
check( ! empty( $draft['ok'] ) && 1 === count( $draft['feed']['bulletins'] ) && 'synthetic-notice' === $draft['feed']['bulletins'][0]['id'], 'Draft rows are dropped' );

$html_row = GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'headline' => 'Hello <script>' ) ) ) ) );
check( empty( $html_row['ok'] ), 'Angle brackets reject the feed' );
check( empty( GHCA_ODP_Contract::validate( feed( array( bulletin(), bulletin() ) ) )['ok'] ), 'Duplicate ids reject the feed' );
check( empty( GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'source_url' => 'javascript:alert(1)' ) ) ) ) )['ok'] ), 'javascript source URLs reject the feed' );
check( empty( GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'source_url' => 'https://user:secret@example.test/a' ) ) ) ) )['ok'] ), 'Credential URLs reject the feed' );
check( ! empty( GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'source_url' => null ) ) ) ) )['ok'] ), 'Missing source is allowed' );

$future = GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'id' => 'synthetic-future', 'published_at' => '2026-12-01T00:00:00Z', 'expires_at' => null ) ) ) ) );
check( ! empty( $future['ok'] ) && 'scheduled' === GHCA_ODP_Contract::classify( $future['feed'], 'synthetic-future', $now ), 'Future rows stay scheduled' );
check( array() === GHCA_ODP_Contract::select( $future['feed'], $now, 'admin' ), 'Future rows are hidden from the list' );

$expired = GHCA_ODP_Contract::validate( feed( array( bulletin( array( 'id' => 'synthetic-expired', 'expires_at' => '2026-11-02T00:00:00Z' ) ) ) ) );
check( 'expired' === GHCA_ODP_Contract::classify( $expired['feed'], 'synthetic-expired', $now ), 'Expired rows are classified' );
check( array() === GHCA_ODP_Contract::select( $expired['feed'], $now, 'employee' ), 'Expired rows are hidden from the list' );
check( 'unknown' === GHCA_ODP_Contract::classify( $valid['feed'], 'missing-id', $now ), 'Unknown ids stay unknown' );

$mixed = GHCA_ODP_Contract::validate( feed( array(
	bulletin( array( 'id' => 'for-employees', 'audience' => 'employee', 'headline' => 'Employee only synthetic' ) ),
	bulletin( array( 'id' => 'for-leads', 'audience' => 'agency_lead', 'headline' => 'Agency only synthetic' ) ),
	bulletin( array( 'id' => 'for-both', 'audience' => 'both', 'headline' => 'Shared synthetic' ) ),
) ) );
$admin_items = GHCA_ODP_Contract::select( $mixed['feed'], $now, 'admin' );
$employee_items = GHCA_ODP_Contract::select( $mixed['feed'], $now, 'employee' );
$admin_ids = array_column( $admin_items, 'id' );
$employee_ids = array_column( $employee_items, 'id' );
check( array( 'for-leads', 'for-both' ) === $admin_ids || ( in_array( 'for-leads', $admin_ids, true ) && in_array( 'for-both', $admin_ids, true ) && ! in_array( 'for-employees', $admin_ids, true ) ), 'Admin list omits employee-only bulletins' );
check( in_array( 'for-employees', $employee_ids, true ) && in_array( 'for-both', $employee_ids, true ) && ! in_array( 'for-leads', $employee_ids, true ), 'Employee list omits agency-only bulletins' );

$parts = GHCA_ODP_Contract::paragraphs( "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry." );
check( 2 === count( $parts ) && false !== strpos( $parts[1], 'Tom & Jerry' ), 'Body splits on blank lines and stays plain text' );
$body_html = GHCA_ODP_UI::body_html( "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry." );
check( false !== strpos( $body_html, 'Tom &amp; Jerry' ) && false === strpos( $body_html, '<script' ), 'Body HTML escapes ampersands and adds no script' );
check( '' === GHCA_ODP_UI::source_link( 'javascript:alert(1)' ), 'Renderer drops unsafe source URLs' );
check( false !== strpos( GHCA_ODP_UI::source_link( 'https://example.test/source' ), 'Read source' ), 'HTTPS source becomes an explicit link' );
$payload = GHCA_ODP_UI::payload_script( array( 'payload' => array( 'bulletins' => array( 'x' => array( 'body' => '</script><script>alert(1)</script>' ) ) ) ) );
check( false === strpos( $payload, '</script><script>' ), 'JSON payload cannot close the script tag' );

$good_body = json_encode( feed( array( bulletin() ) ) );
$fresh = GHCA_ODP_Feed::read( $now, null, transport_of( 200, $good_body ), GHCA_ODP_Contract::FEED_URL );
check( 'fresh' === $fresh['state'] && ! $fresh['stale'] && is_array( $fresh['cache_write'] ), 'A good response is fresh and stored' );
$cached_read = GHCA_ODP_Feed::read( $now + 10, $fresh['cache_write'], transport_of( 500, 'nope' ), GHCA_ODP_Contract::FEED_URL );
check( 'fresh' === $cached_read['state'] && null === $cached_read['cache_write'], 'A fresh cache is served inside 30 minutes without rewrite' );

$stale_cache = $fresh['cache_write'];
$stale_cache['fetched_at'] = $now - 1801;
$kept = GHCA_ODP_Feed::read( $now, $stale_cache, transport_of( 200, json_encode( feed( array( bulletin( array( 'headline' => 'Replacement' ) ) ), 2 ) ) ), GHCA_ODP_Contract::FEED_URL );
check( 'cached' === $kept['state'] && $kept['stale'] && 'unknown_schema' === $kept['error'] && 'Synthetic headline' === $kept['feed']['bulletins'][0]['headline'] && null === $kept['cache_write'], 'Unknown schema keeps the last good feed' );
$http_error = GHCA_ODP_Feed::read( $now, $stale_cache, transport_of( 500, 'upstream' ), GHCA_ODP_Contract::FEED_URL );
check( 'cached' === $http_error['state'] && 'http_500' === $http_error['error'] && null === $http_error['cache_write'], 'HTTP errors keep the last good feed' );
$offline = GHCA_ODP_Feed::read( $now, $stale_cache, static function () { throw new RuntimeException( 'down' ); }, GHCA_ODP_Contract::FEED_URL );
check( 'offline' === $offline['state'] && $offline['stale'] && 'Synthetic headline' === $offline['feed']['bulletins'][0]['headline'], 'Network failure is offline and keeps the last good feed' );
$down = GHCA_ODP_Feed::read( $now, null, static function () { throw new RuntimeException( 'down' ); }, GHCA_ODP_Contract::FEED_URL );
check( 'unavailable' === $down['state'] && null === $down['feed'], 'Network failure with no cache is unavailable' );
$malformed = GHCA_ODP_Feed::read( $now, $stale_cache, transport_of( 200, '{not-json' ), GHCA_ODP_Contract::FEED_URL );
check( 'cached' === $malformed['state'] && 'malformed' === $malformed['error'], 'Malformed JSON does not replace the cache' );
$huge = GHCA_ODP_Feed::read( $now, null, transport_of( 200, str_repeat( 'a', GHCA_ODP_Feed::MAX_BYTES + 1 ) ), GHCA_ODP_Contract::FEED_URL );
check( 'unavailable' === $huge['state'] && 'too_large' === $huge['error'], 'Oversized responses are not stored' );

$called = false;
$blocked = GHCA_ODP_Feed::read( $now, null, static function () use ( &$called ) { $called = true; throw new RuntimeException( 'no' ); }, 'https://sheets.googleapis.com/v4/spreadsheets/private' );
check( ! $called && 'unavailable' === $blocked['state'] && 'url_not_allowlisted' === $blocked['error'], 'Non-allowlisted URLs are not requested' );

$cleared = GHCA_ODP_Feed::read( $now, $stale_cache, transport_of( 200, json_encode( feed( array() ) ) ), GHCA_ODP_Contract::FEED_URL );
check( 'empty' === $cleared['state'] && array() === $cleared['feed']['bulletins'] && is_array( $cleared['cache_write'] ), 'A valid empty feed replaces the previous cache' );
$deferred_called = false;
$deferred = GHCA_ODP_Feed::read( $now, $stale_cache, static function () use ( &$deferred_called ) { $deferred_called = true; throw new RuntimeException( 'no' ); }, GHCA_ODP_Contract::FEED_URL, GHCA_ODP_Feed::TTL, false );
check( ! $deferred_called && 'cached' === $deferred['state'] && 'refresh_deferred' === $deferred['error'], 'A stale display does not wait on the network' );
$cold_called = false;
GHCA_ODP_Feed::read( $now, null, static function () use ( &$cold_called ) { $cold_called = true; return array( 'status' => 200, 'body' => json_encode( feed( array() ) ) ); }, GHCA_ODP_Contract::FEED_URL, GHCA_ODP_Feed::TTL, false );
check( $cold_called, 'A cold cache still fetches because there is nothing to show' );
$stale_unknown = GHCA_ODP_Contract::resolve_item( $valid['feed'], 'missing-id', $now, 'admin', true );
check( 'unconfirmed' === $stale_unknown['result'] && null === $stale_unknown['bulletin'], 'A stale unknown id is not confirmed missing' );
check( 'unknown' === GHCA_ODP_Contract::resolve_item( $valid['feed'], 'missing-id', $now, 'admin', false )['result'], 'A fresh unknown id stays unknown' );
$hidden = GHCA_ODP_Contract::resolve_item( $mixed['feed'], 'for-leads', $now, 'employee', false );
check( 'not_in_view' === $hidden['result'] && null === $hidden['bulletin'] && 'agency_lead' === $hidden['audience'], 'The other audience drops the body' );
check( 'current' === GHCA_ODP_Contract::resolve_item( $mixed['feed'], 'for-leads', $now, 'admin', false )['result'], 'The matching audience keeps the body' );

$admin_view = GHCA_ODP_Feed::present( array( 'state' => 'fresh', 'stale' => false, 'error' => null, 'fetched_at' => $now, 'feed' => $mixed['feed'], 'cache_write' => null ), $now, 'admin' );
$employee_view = GHCA_ODP_Feed::present( array( 'state' => 'fresh', 'stale' => false, 'error' => null, 'fetched_at' => $now, 'feed' => $mixed['feed'], 'cache_write' => null ), $now, 'employee' );
check( array( 'agency_lead', 'both' ) === $admin_view['audience_filter'] && false === strpos( json_encode( $admin_view['bulletins'] ), 'Employee only synthetic' ), 'Admin presentation cannot leak the employee-only bulletin' );
check( array( 'employee', 'both' ) === $employee_view['audience_filter'] && false === strpos( json_encode( $employee_view['bulletins'] ), 'Agency only synthetic' ), 'Employee presentation cannot leak the agency-only bulletin' );

$auth = GHCA_ODP_Access::authorize_list( false, true, 'admin' );
check( 401 === $auth['status'], 'Logged-out list is unauthorized' );
check( 403 === GHCA_ODP_Access::authorize_list( true, false, 'admin' )['status'], 'Employee capability cannot request the admin list' );
check( 200 === GHCA_ODP_Access::authorize_list( true, false, 'employee' )['status'], 'A logged-in employee can request the employee list' );
check( 403 === GHCA_ODP_Access::authorize_list( true, true, 'employee' )['status'], 'A dashboard account cannot switch into the employee context' );
check( '' === GHCA_ODP_Routes::match( GHCA_ODP_Routes::path_from_request( '/?ghca_odp_screen=library', '/' ) )['screen'], 'A query var on another URL is not the bulletin screen' );
check( 'synthetic-notice' === GHCA_ODP_Routes::match( GHCA_ODP_Routes::path_from_request( '/cms/odp-bulletins/synthetic-notice/?ghca_odp_screen=library', '/cms' ) )['id'], 'Subdirectory path still matches and the query is ignored' );
check( 400 === GHCA_ODP_Access::authorize_list( true, true, 'public' )['status'], 'Unknown context is rejected' );
check( 401 === GHCA_ODP_Access::authorize_item( false )['status'] && 200 === GHCA_ODP_Access::authorize_item( true )['status'], 'Single bulletin requires login only' );
check( 'library' === GHCA_ODP_Routes::match( '/odp-bulletins/' )['screen'], 'Library route matches' );
check( 'good-id' === GHCA_ODP_Routes::match( '/odp-bulletins/good-id' )['id'], 'Stable id route matches' );
check( '' === GHCA_ODP_Routes::match( '/odp-bulletins/Not_Valid' )['screen'], 'Unsafe ids do not match' );
check( '' === GHCA_ODP_Routes::match( '/odp-bulletins/../../wp-admin' )['screen'], 'Traversal does not match' );
check( '' === GHCA_ODP_Routes::match( '/employee-dashboard/odp-bulletins' )['screen'], 'Employee dashboard paths are not claimed' );
check( GHCA_ODP_Routes::owns_routes( 'ghca-acd' ) && ! GHCA_ODP_Routes::owns_routes( 'employee-plugin' ), 'Route owner stays with this plugin unless filtered' );
check( isset( GHCA_ODP_Routes::rules()['^odp-bulletins/?$'] ), 'Rewrite rules cover the library' );

$library = GHCA_ODP_UI::library( array(
	'state' => 'fresh',
	'context' => 'admin',
	'kicker' => 'Agency bulletins',
	'action' => 'https://example.test/odp-bulletins/',
	'query' => '',
	'items' => array( array(
		'id' => 'synthetic-notice',
		'headline' => 'Synthetic headline',
		'preview' => 'Synthetic preview only',
		'published_at' => '2026-11-01T15:00:00Z',
		'date_label' => 'Nov 1, 2026',
		'audience_label' => 'Employees and agency leads',
		'url' => 'https://example.test/odp-bulletins/synthetic-notice/',
	) ),
	'total' => 1,
	'from' => 1,
	'to' => 1,
	'prev_url' => '',
	'next_url' => '',
	'payload' => array( 'bulletins' => array() ),
) );
check( false !== strpos( $library, 'Synthetic preview only' ) && false === strpos( $library, 'First paragraph' ), 'Library shows preview, not the full body' );
check( false === strpos( $library, 'NEW, proposed' ) && false === strpos( $library, 'Sample bulletin' ), 'Library has no sample banner' );
$single = GHCA_ODP_UI::single( array(
	'result' => 'current',
	'library_url' => 'https://example.test/odp-bulletins/',
	'kicker' => 'ODP Bulletin',
	'audience_note' => '',
	'item' => array(
		'headline' => 'Synthetic headline',
		'body' => "First paragraph of the synthetic body.\n\nSecond paragraph with Tom & Jerry.",
		'published_at' => '2026-11-01T15:00:00Z',
		'date_label' => 'Nov 1, 2026',
		'audience_label' => 'Employees and agency leads',
		'url' => 'https://example.test/odp-bulletins/synthetic-notice/',
		'source_url' => 'https://example.test/source',
	),
) );
check( false !== strpos( $single, 'First paragraph of the synthetic body.' ) && false !== strpos( $single, GHCA_ODP_Contract::DISCLAIMER ) && false !== strpos( $single, 'Read source' ), 'Full page uses the body, disclaimer, and source link' );
$gone = GHCA_ODP_UI::single( array( 'result' => 'expired', 'library_url' => 'https://example.test/odp-bulletins/', 'kicker' => 'ODP Bulletin', 'item' => null, 'audience_note' => '' ) );
check( false !== strpos( $gone, 'no longer available' ) && false === strpos( $gone, 'First paragraph' ), 'Expired id does not render the body' );
$empty_library = GHCA_ODP_UI::library( array( 'state' => 'empty', 'context' => 'employee', 'kicker' => 'Employee bulletins', 'action' => '/odp-bulletins/', 'query' => '', 'items' => array(), 'total' => 0, 'from' => 0, 'to' => 0, 'prev_url' => '', 'next_url' => '', 'payload' => array() ) );
check( false !== strpos( $empty_library, 'No ODP bulletins right now.' ) && false === strpos( $empty_library, 'Sample' ), 'Genuine empty state has no sample content' );

$health = GHCA_ACD_Redesign::health_from_aggregate( array( 'total_employees' => 0, 'compliance_rate' => 0 ) );
check( '—' === $health['center'] && false === strpos( $health['center'], '30' ), 'Empty scope is not a fake compliance percent' );
// Ten employees, one of every status the provider can give, plus a second not_started.
$slugs = array( 'completed', 'new_hire_completed', 'expiring_soon', 'in_progress', 'new_hire_in_progress', 'new_hire_not_started', 'overdue', 'expired', 'not_started', 'not_started' );
$people = array_map( static function ( $slug ) { return array( 'status_slug' => $slug ); }, $slugs );
$populated = GHCA_ACD_Redesign::health_from_aggregate( array(
	'employees' => $people,
	'total_employees' => 10,
	'completed_employees' => 2,
	'expiring_soon_employees' => 1,
	'in_progress_employees' => 3,
	'overdue_employees' => 2,
	'compliance_rate' => 30,
) );
$labels = array_column( $populated['slices'], 'label' );
check( array( 'Compliant', 'Expiring soon', 'In progress', 'Overdue', 'Not started' ) === $labels, 'Health rows: Compliant, Expiring soon, In progress, Overdue, Not started; no Other' );
check( 2 === $populated['slices'][4]['count'] && 'not_started' === $populated['slices'][4]['key'], 'Not started counts status not_started from the employee records' );
check( 10 === array_sum( array_column( $populated['slices'], 'count' ) ), 'Rows add up to every employee in scope' );
check( 30 === $populated['rate'] && '30%' === $populated['center'], 'Compliance rate is the provider\'s, unchanged' );
check( false === strpos( implode( '|', $labels ), 'Other' ), 'The vague Other label is gone' );
$people[] = array( 'status_slug' => 'something_new' );
$unmapped = GHCA_ACD_Redesign::health_from_aggregate( array( 'employees' => $people, 'total_employees' => 11, 'completed_employees' => 2, 'expiring_soon_employees' => 1, 'in_progress_employees' => 3, 'overdue_employees' => 2, 'compliance_rate' => 27 ) );
check( 'Status not recognised' === end( $unmapped['slices'] )['label'] && 1 === end( $unmapped['slices'] )['count'] && 11 === array_sum( array_column( $unmapped['slices'], 'count' ) ), 'A genuinely unmapped status stays visible as Status not recognised' );
$none_new = GHCA_ACD_Redesign::health_from_aggregate( array( 'employees' => array( array( 'status_slug' => 'completed' ) ), 'total_employees' => 1, 'completed_employees' => 1, 'compliance_rate' => 100 ) );
check( ! in_array( 'Status not recognised', array_column( $none_new['slices'], 'label' ), true ), 'No unmapped row when every status is mapped' );
check( 'Compliant' === $populated['caption'], 'The donut centre label is exactly Compliant' );
$kpis = GHCA_ACD_Redesign::kpis_from_aggregate( array(
	'total_employees' => 4,
	'completed_employees' => 1,
	'expiring_soon_employees' => 1,
	'in_progress_employees' => 1,
	'overdue_employees' => 1,
	'compliance_rate' => 50,
	'certificates_issued' => 7,
), 6 );
check( '6' === $kpis[1]['value'] && '50%' === $kpis[5]['value'] && false !== strpos( $kpis[5]['sub'], '2 of 4 employees' ), 'KPIs use the existing rate and tracked course count' );
check( 'Includes new hires not started' === $kpis[2]['sub'], 'In progress subtitle says what it counts' );
check( 'danger' === $kpis[3]['tone'] && '1' === $kpis[3]['value'] && 'ghca-tab-overdue' === $kpis[3]['tab'], 'Overdue KPI: danger tone, real count, opens the Overdue tab' );
$zero = GHCA_ACD_Redesign::kpis_from_aggregate( array( 'total_employees' => 3, 'completed_employees' => 3, 'overdue_employees' => 0, 'compliance_rate' => 100 ), 2 );
check( 'danger' === $zero[3]['tone'] && '0' === $zero[3]['value'] && 'ghca-tab-overdue' === $zero[3]['tab'], 'Overdue KPI keeps the danger tone at zero, with the real count 0 and the same link' );
$sorted = GHCA_ACD_Redesign::sort_groups( array(
	array( 'label' => 'Low', 'risk' => 'low', 'rate' => 100, 'overdue' => 0, 'employees' => 1 ),
	array( 'label' => 'High', 'risk' => 'high', 'rate' => 10, 'overdue' => 2, 'employees' => 2 ),
) );
check( 'High' === $sorted[0]['label'] && '2 overdue' === GHCA_ACD_Redesign::group_chip( $sorted[0] )['label'], 'Groups sort by existing risk and show the overdue count' );
check( 'On track' === GHCA_ACD_Redesign::group_chip( array( 'overdue' => 0, 'rate' => 90 ) )['label'], 'Low-risk groups without overdue read On track' );
$overview = GHCA_ACD_Redesign::render_from_model( array(
	'theme' => 'gridhouse',
	'scope' => 'Scope: all 1 groups',
	'as_of' => 'As of today, 1:00 PM',
	'active' => '4 active employees',
	'actions' => array( array( 'label' => 'View reports', 'url' => 'https://example.test/dashboard#ghca-tab-reports', 'variant' => 'primary', 'tab' => 'ghca-tab-reports' ) ),
	'tabs' => array( array( 'id' => 'ghca-tab-overview', 'label' => 'Overview', 'badge' => null, 'active' => true ) ),
	'kpis' => $kpis,
	'health' => $populated,
	'groups' => array( array( 'label' => '<script>alert(1)</script>', 'risk' => 'high', 'rate' => 10, 'overdue' => 1, 'employees' => 2 ) ),
	'certificates' => array( 'issued' => 7, 'recent' => 1, 'missing' => 1 ),
	'bulletin_html' => '',
	'announcements_html' => '',
	'panels' => array(),
) );
check( false !== strpos( $overview, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false === strpos( $overview, '<script>alert(1)</script>' ), 'Group labels are escaped' );
check( false === strpos( $overview, 'NEW, proposed' ) && false === strpos( $overview, 'Pending issuance' ), 'Overview does not invent sample banners or unsupported certificate states' );
check( false !== strpos( $overview, 'Course completions<small>· last 30 days</small>' ), 'Recent completions stay labeled as completions' );
check( false !== strpos( $overview, 'Issued<small>· active employees in this view</small>' ), 'The certificate row names the active view' );
check( false !== strpos( $overview, 'class="ghca-ui' ) && false !== strpos( $overview, 'class="gh-app ghca-acd ghca-acd--v2" data-theme="gridhouse"' ), 'Overview renders inside the isolated board root' );
check( false !== strpos( $overview, 'gh-grid gh-grid--kpi gh-kpi--overview' ) && 6 === preg_match_all( '/class="gh-stat(?: gh-stat--[a-z]+)?"/', $overview ), 'Overview renders the six-figure board strip' );
check( false !== strpos( $overview, 'class="gh-ovp gh-ovp--3"' ) && false !== strpos( $overview, 'stroke-dasharray=' ), 'Overview uses the board grid and SVG donut' );
check( false === strpos( $overview, 'Issued, all time' ) && false !== strpos( $overview, 'Issued, active employees' ), 'Certificates are labeled as the active view' );
check( false === strpos( $overview, 'Training now' ) && false !== strpos( $overview, 'Includes new hires not started' ), 'In progress does not say training now, and names new hires' );
check( false !== strpos( $overview, 'aria-label="30% compliant: completed or expiring soon"' ), 'The donut keeps the rate definition as its accessible label only' );
check( false !== strpos( $overview, '<div class="gh-donut-c"><strong>30%</strong><span>Compliant</span></div>' ), 'The donut centre reads the percent and Compliant only' );
check( false === strpos( $overview, 'The percent is the existing compliance rate' ) && false === strpos( $overview, 'gh-health-note' ), 'No visible rate explanation on the health card' );
check( false === strpos( $overview, 'gh-appbar' ) && false === strpos( $overview, 'gh-nav' ) && false === strpos( $overview, '<header' ), 'The embedded overview has no app bar or site navigation of its own' );
check( false === strpos( $overview, '<footer' ) && false === strpos( $overview, 'Powered by Gridhouse Digital' ) && false !== strpos( $overview, 'gh-help-row' ), 'No plugin footer; the support strip stays' );
check( false !== strpos( $overview, 'role="tablist" data-ghca-tablist-main' ) && false !== strpos( $overview, 'data-ghca-tab-target="ghca-tab-overview"' ), 'Dashboard section tabs stay' );
check( 2 === substr_count( $overview, 'gh-card-hd gh-card-hd--rule' ) && false !== strpos( $overview, 'gh-card-hd gh-card-hd--rule"><div><h2 class="gh-h3" id="gh-health-h">' ) && false !== strpos( $overview, 'gh-card-hd gh-card-hd--rule"><div><h2 class="gh-h3" id="gh-groups-h">' ), 'Health and group cards have a header divider' );
foreach ( array( '#7a1f5c', '#e0a800', '#2aa7ff' ) as $brand ) {
	$vars = array();
	foreach ( explode( ';', GHCA_ACD_Redesign::agency_brand_style( $brand ) ) as $pair ) {
		list( $k, $v ) = explode( ':', $pair, 2 );
		$vars[ $k ] = $v;
	}
	check(
		GHCA_ACD_Redesign::contrast( $vars['--gh-agency-ink'], '#ffffff' ) >= 4.5
		&& GHCA_ACD_Redesign::contrast( $vars['--gh-agency-ink'], $vars['--gh-agency-soft'] ) >= 4.5
		&& GHCA_ACD_Redesign::contrast( $vars['--gh-agency'], $vars['--gh-agency-on'] ) >= 4.5
		&& GHCA_ACD_Redesign::contrast( $vars['--gh-agency-hover'], $vars['--gh-agency-on-hover'] ) >= 4.5,
		'Agency colour ' . $brand . ' keeps 4.5:1 in link, hover, and button states'
	);
}
check( '#7a1f5c' === explode( ':', explode( ';', GHCA_ACD_Redesign::agency_brand_style( '#7a1f5c' ) )[0] )[1], 'A dark agency primary is used as-is for the button fill' );
check( '' === GHCA_ACD_Redesign::agency_brand_style( 'blue;}x{' ), 'Invalid agency colours are ignored' );
$css_unscoped = array();
$css_depth    = 0;
$css_frames   = 0;
foreach ( file( __DIR__ . '/../assets/gh-redesign.css' ) as $css_line ) {
	$css_line = trim( $css_line );
	$was_top  = 0 === $css_depth || ( 1 === $css_depth && $css_frames > 0 );
	$css_depth += substr_count( $css_line, '{' ) - substr_count( $css_line, '}' );
	if ( 0 === strpos( $css_line, '@media' ) ) {
		$css_frames = 0;
	} elseif ( 0 === strpos( $css_line, '@' ) ) {
		$css_frames = -1;
	}
	if ( 0 === $css_depth ) {
		$css_frames = 0;
	}
	if ( '' === $css_line || 0 === strpos( $css_line, '/*' ) || 0 === strpos( $css_line, '*' ) || 0 === strpos( $css_line, '@' ) || 0 === strpos( $css_line, '}' ) || ! $was_top || false === strpos( $css_line, '{' ) ) {
		continue;
	}
	// Two page-level rules are allowed: the standalone preview body, and the body of plugin pages marked ghca-ui-page.
	if ( 0 !== strpos( $css_line, ':is(.ghca-ui, #ghca-ui-scope)' ) && 0 !== strpos( $css_line, 'body.gh-ui-body' ) && 'html body.ghca-ui-page { background-color: #F3EFEA !important; }' !== $css_line ) {
		$css_unscoped[] = $css_line;
	}
}
check( array() === $css_unscoped, 'Redesign CSS lines stay under plugin roots' . ( $css_unscoped ? ': ' . $css_unscoped[0] : '' ) );
$redesign_css = file_get_contents( __DIR__ . '/../assets/gh-redesign.css' );
check( 0 === preg_match( '/site-header|masthead|site-footer|elementor-location-(header|footer)|bb-header|#wpadminbar/i', $redesign_css ), 'Redesign CSS does not touch theme headers, footers, or the admin bar' );
check( false !== strpos( $redesign_css, '.gh-app a.gh-qlink:hover, :is(.ghca-ui, #ghca-ui-scope) .gh-app a.gh-qlink:active { background-color: var(--gh-agency-soft, var(--gh-surface-hover)) !important;' ), 'Quick link hover uses the agency colour and outranks theme !important link rules' );
check( false !== strpos( $redesign_css, '.gh-app a:where(:not(.gh-legacy, .gh-legacy *)) { color: var(--gh-text-link) !important;' ) && false !== strpos( $redesign_css, '.gh-app a:hover:where(:not(.gh-legacy, .gh-legacy *)) { color: var(--gh-color-primary-hover) !important; }' ), 'Board link colours on bare anchors outrank theme !important link rules' );
check( false !== strpos( $redesign_css, ':where(.gh-app a:not(.gh-legacy *)) { background-color: transparent !important; }' ), 'Theme link-hover backgrounds are cleared on redesign links without adding specificity' );
check( false !== strpos( $redesign_css, '.gh-app .gh-health-ft a:is(:link, :visited, :hover, :focus, :active)' ), 'The overdue link keeps its danger colour on hover, like the board inline style' );
check( 1 === preg_match( '/@media \(max-width: 600px\) \{\s*:is\(\.ghca-ui, #ghca-ui-scope\) \.gh-odp \{ font-size: 15px; \}/', $redesign_css ), 'Bulletin pages use the phone board 15px base below 600px' );
check( false !== strpos( $redesign_css, '.gh-app a.gh-btn--primary:hover, :is(.ghca-ui, #ghca-ui-scope) .gh-app a.gh-btn--primary:focus-visible' ) && false !== strpos( $redesign_css, 'var(--gh-agency-hover, var(--gh-color-primary-hover)) !important' ), 'Primary button hover and focus use the agency colour' );
$bulletin_js = file_get_contents( __DIR__ . '/../assets/gh-bulletins.js' );
check( false !== strpos( $bulletin_js, "setAttribute('inert'" ) && false !== strpos( $bulletin_js, "setAttribute('tabindex', '-1')" ), 'Animated ticker copies are inert and removed from tab order' );
$not_in_view = GHCA_ODP_UI::single( array( 'result' => 'not_in_view', 'library_url' => '/odp-bulletins/', 'kicker' => 'ODP Bulletin', 'item' => null, 'audience_label' => 'Agency leads', 'audience_note' => '' ) );
check( false !== strpos( $not_in_view, 'Agency leads' ) && false === strpos( $not_in_view, 'First paragraph' ), 'Full page withholds the other audience body' );
$unconfirmed_page = GHCA_ODP_UI::single( array( 'result' => 'unconfirmed', 'library_url' => '/odp-bulletins/', 'kicker' => 'ODP Bulletin', 'item' => null, 'audience_label' => '', 'audience_note' => '' ) );
check( false !== strpos( $unconfirmed_page, 'cannot be confirmed' ) && false === strpos( $unconfirmed_page, 'not in the current feed' ), 'Stale unknown copy does not claim the id is missing' );

$page = GHCA_ODP_Contract::paginate( $admin_items, 'shared', 1, 9 );
check( 1 === $page['total'] && 'for-both' === $page['items'][0]['id'], 'Search matches the shared synthetic bulletin' );

echo "PASS: {$passed} ODP bulletin and overview checks\n";
exit( $failed > 0 ? 1 : 0 );
