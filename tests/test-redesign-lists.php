<?php
/**
 * Redesigned Overdue, Courses and Certificates tabs (phase 2, slice 4). Runs the plugin's own provider,
 * pager, renderers, AJAX handler and messaging query against synthetic records in a stand-in WordPress
 * (tests/fixtures/redesign-lists-env.php). Checks that the rows are the legacy rows, the counts are the
 * provider's counts, every gate still applies, downloads go through the existing endpoint, nothing the
 * plugin cannot do is offered, and the legacy output is byte-for-byte what it was before this slice.
 * Not a browser render; tests/browser-redesign-lists.cjs covers that.
 */
require __DIR__ . '/fixtures/redesign-lists-env.php';
require __DIR__ . '/fixtures/legacy-lists-states.php';

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	if ( ! $ok ) {
		++$failed;
		fwrite( STDERR, "FAIL: $message\n" );
	}
}
function reset_env( array $over = array(), array $request = array(), array $seed = array() ): void {
	$GLOBALS['ghca_env'] = array_merge( array( 'logged_in' => true, 'can_view' => true, 'manage_users' => true, 'redesign' => true, 'now' => strtotime( '2026-11-01 12:00:00 UTC' ) ), $over );
	$_GET  = array();
	$_POST = $request;
	GHCA_ACD_Redesign_Lists::$now = $GLOBALS['ghca_env']['now'];
	ghca_lists_seed( $seed );
}
/** @return array<int,string> data-ghca-employee-drawer ids on table rows, in order. */
function row_ids( string $html ): array {
	preg_match_all( '#<tr[^>]*>\s*<td><div class="gh-person">.*?data-ghca-employee-drawer="(\d+)"#s', $html, $m );
	return $m[1];
}
function text( string $html ): string {
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
}

// ---- 1. Legacy output is unchanged: goldens captured before any renderer change.
$golden = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-legacy-lists.sha256.json' ), true );
$now_states = ghca_legacy_lists_states();
check( count( $golden ) === 15 && count( $now_states ) === 15, 'fifteen legacy states captured' );
foreach ( $golden as $name => $hash ) {
	check( isset( $now_states[ $name ] ) && hash( 'sha256', $now_states[ $name ] ) === $hash, "legacy $name output unchanged" );
}

// ---- 2. Overdue: the rows are the legacy rows, in the legacy order.
reset_env( array( 'can_remind' => true ) );
$filters = GHCA_ACD_Data_Provider::get_priority_filters();
$legacy  = array_map( static function ( array $r ): string { return (string) $r['user_id']; }, GHCA_ACD_Data_Provider::get_priority_rows( $filters ) );
$parts   = GHCA_ACD_Redesign_Lists::priority_parts( $filters );
check( row_ids( $parts['html'] ) === $legacy, 'overdue rows and order match the legacy priority rows' );
check( array( '201', '202', '205', '204', '203' ) === $legacy, 'fixture: three overdue then two at risk' );
check( false !== strpos( $parts['html'], '<h3 class="gh-h4" id="ghca-pri-soon-h">Due within 14 days</h3>' ), 'due-soon section titled from the at-risk setting' );
reset_env( array( 'at_risk_days' => 21 ) );
check( false !== strpos( GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() )['html'], 'Due within 21 days' ), 'window follows ghca_acd_at_risk_days' );

reset_env( array( 'can_remind' => true ) );
$html = GHCA_ACD_Redesign_Lists::render_overdue();
check( false !== strpos( $html, '3 employees overdue on 6 courses · 2 more due within 14 days' ), 'headline counts overdue people, the courses they owe, and due-soon people' );
check( false !== strpos( $html, '<strong class="gh-late">7</strong>' ) && false !== strpos( $html, 'due Oct 25' ), 'Daniel: 7 days late, due Oct 25' );
check( false !== strpos( $html, '<strong class="gh-late">3</strong>' ), 'Maya: 3 days late' );
check( 1 === preg_match( '#Tomás Alvarez.*?<td class="c-num"><span class="gh-muted" title="Not past the due date#s', $html ), 'expired with a future due date: no invented day count' );
check( false !== strpos( $html, 'Foundations of Person-Centered Thinking <span class="gh-muted">(expired)</span>' ), 'an expired course is listed as owed and marked expired' );
check( false !== strpos( $html, 'Documentation Essentials &lt;Intro&gt;' ) && false === strpos( $html, '<Intro>' ), 'course titles are escaped' );
check( false !== strpos( $html, '<strong class="gh-soon">9</strong>' ) && false !== strpos( $html, '<strong class="gh-soon">13</strong>' ), 'days left for due-soon employees' );
check( false !== strpos( $html, '2 courses not complete' ) && false !== strpos( $html, 'Next: Recognizing and Reporting Incidents' ), 'courses due and the next course' );
check( 10 === substr_count( $html, 'data-ghca-reminder="' ), 'Remind on every row and every card (5 + 5)' );
check( 3 === substr_count( $html, 'gh-btn--primary gh-btn--sm gh-btn--lnk" data-ghca-reminder=' ) && 3 === substr_count( $html, 'gh-btn--primary gh-btn--lnk" data-ghca-reminder=' ), 'Remind is primary on the 3 overdue rows (small) and their cards (full size), secondary on due soon' );
preg_match_all( '#<div class="gh-mcard-act">(.*?)</div>#s', $html, $acts );
check( 5 === count( $acts[1] ) && ! preg_grep( '/gh-btn--sm/', $acts[1] ), 'phone card buttons are full size (44px touch targets)' );
check( false === stripos( $html, 'Remind all' ), 'no bulk "Remind all": there is no bulk send' );
check( false === strpos( $html, 'Last reminder' ), 'no Last reminder column without the communication-history permission' );
check( false === strpos( $html, 'Last contact' ), 'no Last contact column: contact logs are not read' );
check( false !== strpos( $html, 'href="' . esc_url( GHCA_ACD_Shortcodes::build_mailto_reminder( 'daniel.reyes@example.test' ) ) . '"' ), 'Email reminder uses the legacy prefilled mailto' );
check( false === strpos( $html, 'Log a reminder' ), 'Log a reminder hidden while FluentCRM is not active' );
check( false === strpos( $html, 'Open CRM contact' ), 'CRM link hidden when there is no contact URL' );
check( false !== strpos( $html, 'data-ghca-filter-form data-ghca-ui="board" data-ghca-debounce="300" data-ghca-table="priority"' ), 'filter form keeps the priority table hook and asks for board markup' );
foreach ( array( 'ghca_pri_page', 'ghca_pri_search', 'ghca_pri_group', 'ghca_pri_type', 'ghca_orderby', 'ghca_order' ) as $field ) {
	check( false !== strpos( $html, 'name="' . $field . '"' ), "priority field $field kept" );
}
check( false !== strpos( $html, 'name="ghca_pri_per"' ), 'per page lives in the pager under the legacy name' );
check( 1 === preg_match( '#<select id="ghca-filters-priority-s" class="gh-select" data-ghca-sort-select>#', $html ), 'Sort select has no name: only the existing hidden sort fields are sent' );
check( false !== strpos( $html, 'data-ghca-chips="priority"' ) && false !== strpos( $html, 'data-ghca-table-status="priority"' ) && false !== strpos( $html, 'data-ghca-table-id="priority"' ), 'chip, status and mount hosts' );

reset_env( array( 'can_remind' => false ) );
$html = GHCA_ACD_Redesign_Lists::render_overdue();
check( false === strpos( $html, 'data-ghca-reminder=' ), 'no Remind without user_can_send_reminders' );
check( 20 === substr_count( $html, 'data-ghca-employee-drawer=' ), 'name and View open the drawer on every row and card' );

// Sorting goes through the provider's own sort keys.
reset_env( array(), array( 'ghca_orderby' => 'name', 'ghca_order' => 'desc' ) );
$filters = GHCA_ACD_Data_Provider::get_priority_filters();
$legacy  = array_map( static function ( array $r ): string { return (string) $r['user_id']; }, GHCA_ACD_Data_Provider::get_priority_rows( $filters ) );
$parts   = GHCA_ACD_Redesign_Lists::priority_parts( $filters );
$ids     = row_ids( $parts['html'] );
$over    = array_values( array_filter( $legacy, static function ( string $id ) { return in_array( $id, array( '201', '202', '205' ), true ); } ) );
$soon    = array_values( array_filter( $legacy, static function ( string $id ) { return in_array( $id, array( '203', '204' ), true ); } ) );
check( $ids === array_merge( $over, $soon ), 'sorted rows keep the provider order within each section' );
check( 1 === preg_match( '#<option value="name:desc" selected="selected">#', GHCA_ACD_Redesign_Lists::render_overdue() ), 'Sort select shows the active sort' );
reset_env( array(), array( 'ghca_orderby' => 'due_timestamp', 'ghca_order' => 'asc' ) );
check( 1 === preg_match( '#<option value="" selected="selected">Most urgent first#', GHCA_ACD_Redesign_Lists::render_overdue() ), 'a sort the select does not offer falls back to the default label' );

// Filters, chips and empty states.
reset_env( array(), array( 'ghca_pri_type' => 'at_risk' ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( row_ids( $parts['html'] ) === array( '204', '203' ), 'Due soon only' );
check( false === strpos( $parts['html'], 'No one is overdue' ), 'no "no one is overdue" banner when overdue rows are filtered away on purpose' );
check( false !== strpos( $parts['chips'], 'Due soon only' ) && false !== strpos( $parts['chips'], 'data-ghca-filter-clear="ghca_pri_type"' ), 'priority chip clears its field' );
reset_env( array(), array( 'ghca_pri_group' => '11', 'ghca_pri_search' => 'reyes' ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( row_ids( $parts['html'] ) === array( '201' ), 'group and search together' );
check( false !== strpos( $parts['chips'], 'data-ghca-filter-clear="all"' ), 'Clear all from two filters' );
check( 'Showing 1–1 of 1' === $parts['summary'], 'summary line' );
reset_env( array(), array( 'ghca_pri_search' => 'nobody' ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( false !== strpos( $parts['html'], 'No one matches these filters' ) && false !== strpos( $parts['html'], 'data-ghca-filter-clear="all"' ), 'filtered-empty offers Clear filters' );
$only_soon = array( array( 301, 'Ada Soon', 11, 'not_started', 'Not Started', 5, array( ghca_lists_course( 501, 'Recognizing and Reporting Incidents', 'not_started', true, false ) ), array( 'at_risk' => true ) ) );
reset_env( array(), array(), array( 'people' => $only_soon ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( false !== strpos( $parts['html'], 'No one is overdue' ) && row_ids( $parts['html'] ) === array( '301' ), 'only due-soon: the all-clear banner, then the due-soon table' );
reset_env( array(), array(), array( 'people' => array( array( 302, 'Ben Done', 11, 'completed', 'All assigned courses complete', 90, array( ghca_lists_course( 504, 'Fatal Five', 'completed', true, true, 3, 'compliant', 300 ) ), array() ) ) ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( false !== strpos( $parts['html'], 'gh-empty--ok' ) && false === strpos( $parts['html'], 'Clear filters' ), 'nobody overdue or due soon: all-clear, nothing to clear' );
check( 'No one in your view is overdue or due soon.' === GHCA_ACD_Redesign_Lists::priority_headline(), 'all-clear headline' );

// More than three owed courses.
$many = array();
for ( $i = 0; $i < 5; $i++ ) {
	$many[] = ghca_lists_course( 600 + $i, 'Course ' . $i, 'not_started', true, false );
}
reset_env( array(), array(), array( 'people' => array( array( 303, 'Cy Many', 11, 'overdue', 'Overdue', -2, $many, array() ) ) ) );
$parts = GHCA_ACD_Redesign_Lists::priority_parts( GHCA_ACD_Data_Provider::get_priority_filters() );
check( 3 === substr_count( $parts['html'], '<li>Course ' ) && false !== strpos( $parts['html'], '+ 2 more' ), 'three courses listed, the rest counted' );

// Last reminder: real repository query against a stand-in $wpdb, behind the permission and the schema check.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
require_once __DIR__ . '/../includes/messaging/class-messaging-schema.php';
require_once __DIR__ . '/../includes/messaging/class-messaging-repository.php';
final class Ghca_Lists_Wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public $rows    = array();
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback( '/%[ds]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		// SHOW TABLES LIKE: every messaging table exists.
		return preg_match( "/LIKE '([^']+)'/", $sql, $m ) ? str_replace( '\\', '', $m[1] ) : null;
	}
	public function get_results( $sql, $mode = null ) {
		$this->queries[] = $sql;
		return $this->rows;
	}
}
final class GHCA_ACD_Messaging_UI {
	public static function delivery_label( string $state, string $channel = 'email' ): string { return ucfirst( $state ); }
}
$GLOBALS['wpdb']       = new Ghca_Lists_Wpdb();
$GLOBALS['wpdb']->rows = array(
	array( 'employee_user_id' => '201', 'created_at' => '2026-10-25 14:00:00', 'channel' => 'email', 'state' => 'delivered' ),
	array( 'employee_user_id' => '201', 'created_at' => '2026-10-25 14:00:00', 'channel' => 'sms', 'state' => 'failed' ),
	array( 'employee_user_id' => '204', 'created_at' => '2026-10-27 09:00:00', 'channel' => 'email', 'state' => 'queued' ),
);
$latest = GHCA_ACD_Messaging_Repository::latest_for_employees( array( 201, '204', -3, 0, 201, 'x' ) );
$sql    = (string) end( $GLOBALS['wpdb']->queries );
check( false !== strpos( $sql, 'WHERE employee_user_id IN (201,204,3) GROUP BY employee_user_id' ), 'ids are cast, de-duplicated and bound as placeholders' );
check( false !== strpos( $sql, 'MAX(id)' ) && false !== strpos( $sql, 'wp_ghca_acd_communication_deliveries' ), 'latest communication per employee, joined to its deliveries' );
check( 0 === preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ), 'read only' );
check( 2 === count( $latest[201]['deliveries'] ) && 'sms' === $latest[201]['deliveries'][1]['channel'] && 'queued' === $latest[204]['deliveries'][0]['state'], 'grouped per employee' );
$before = count( $GLOBALS['wpdb']->queries );
check( array() === GHCA_ACD_Messaging_Repository::latest_for_employees( array( 0, -0 ) ) && count( $GLOBALS['wpdb']->queries ) === $before, 'no ids, no query' );

reset_env( array( 'can_comms' => true ) );
$before = count( $GLOBALS['wpdb']->queries );
$html   = GHCA_ACD_Redesign_Lists::render_overdue();
check( false === strpos( $html, 'Last reminder' ) && count( $GLOBALS['wpdb']->queries ) === $before, 'schema not at the current version: no column and no query' );
reset_env( array( 'can_comms' => true, 'options' => array( GHCA_ACD_Messaging_Schema::OPTION_VERSION => GHCA_ACD_Messaging_Schema::VERSION ) ) );
$html = GHCA_ACD_Redesign_Lists::render_overdue();
check( 2 === substr_count( $html, '<th scope="col">Last reminder</th>' ), 'Last reminder column in both tables with permission and schema' );
check( false !== strpos( $html, 'Oct 25 · email + SMS' ) && false !== strpos( $html, 'gh-st--sent gh-st--xs">Delivered' ), 'channels and the first delivery state' );
check( false !== strpos( $html, 'Oct 27 · email' ) && false !== strpos( $html, 'gh-st--progress gh-st--xs">Queued' ), 'queued shows as in progress' );
check( substr_count( $html, '<span class="gh-muted">Never</span>' ) >= 3, 'Never for people with no message' );
reset_env( array( 'can_comms' => false, 'options' => array( GHCA_ACD_Messaging_Schema::OPTION_VERSION => GHCA_ACD_Messaging_Schema::VERSION ) ) );
$before = count( $GLOBALS['wpdb']->queries );
GHCA_ACD_Redesign_Lists::render_overdue();
check( count( $GLOBALS['wpdb']->queries ) === $before, 'without permission the messaging tables are never queried' );

// ---- 3. Courses: counts are the provider's.
reset_env();
$filters = GHCA_ACD_Data_Provider::get_course_filters();
$legacy  = GHCA_ACD_Data_Provider::get_course_overview_rows( $filters );
$parts   = GHCA_ACD_Redesign_Lists::course_parts( $filters, 'name' );
preg_match_all( '#<tr><td><strong>(.*?)</strong></td><td><span class="gh-badge gh-badge--outline">(.*?)</span></td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td>#', $parts['html'], $m, PREG_SET_ORDER );
$seen = array_map( static function ( array $x ): string { return html_entity_decode( $x[1] ) . '|' . $x[2] . '|' . $x[3] . '|' . $x[4] . '|' . $x[5] . '|' . $x[6]; }, $m );
$want = array_map( static function ( array $r ): string { return $r['course'] . '|' . $r['group'] . '|' . $r['required'] . '|' . $r['completed'] . '|' . $r['in_progress'] . '|' . $r['not_started']; }, array_slice( $legacy, 0, 15 ) );
check( $seen === $want && count( $seen ) === 10, 'Course A–Z: rows, order and every count match the legacy course table' );
// The default order is the board's (lowest completion first): the same rows and counts, only re-ordered.
check( 'rate_asc' === GHCA_ACD_Redesign_Lists::course_sort(), 'no sort in the request means Lowest completion first' );
$default = GHCA_ACD_Redesign_Lists::course_parts( $filters, GHCA_ACD_Redesign_Lists::course_sort() );
preg_match_all( '#<tr><td><strong>(.*?)</strong></td><td><span class="gh-badge gh-badge--outline">(.*?)</span></td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td><td class="c-num">(\d+)</td>#', $default['html'], $m, PREG_SET_ORDER );
$seen_default = array_map( static function ( array $x ): string { return html_entity_decode( $x[1] ) . '|' . $x[2] . '|' . $x[3] . '|' . $x[4] . '|' . $x[5] . '|' . $x[6]; }, $m );
$a = $seen_default;
$b = $want;
sort( $a );
sort( $b );
check( $a === $b, 'default order: exactly the legacy rows and counts' );
preg_match_all( '#<strong class="gh-num">(\d+)%</strong>#', $default['html'], $m );
$r = array_map( 'intval', array_slice( $m[1], 0, 10 ) );
$rs = $r;
sort( $rs );
check( $r === $rs && false !== strpos( $default['html'], 'class="c-rate" aria-sort="ascending"' ), 'default order is ascending completion, and the column says so' );
check( 1 === preg_match( '#<select id="ghca-filters-courses-s" class="gh-select" name="ghca_crs_sort" data-ghca-not-filter><option value="rate_asc" selected="selected">Lowest completion first#', GHCA_ACD_Redesign_Lists::render_courses() ), 'Sort select shows Lowest completion first by default' );
foreach ( $legacy as $r ) {
	check( (int) $r['required'] === (int) $r['completed'] + (int) $r['in_progress'] + (int) $r['not_started'], 'counts add up for ' . $r['course'] . ' / ' . $r['group'] );
}
$html    = GHCA_ACD_Redesign_Lists::render_courses();
$tracked = GHCA_ACD_Data_Provider::count_unique_tracked_courses( GHCA_ACD_Data_Provider::get_aggregate()['employees'] );
check( 7 === $tracked && false !== strpos( $html, $tracked . ' tracked courses across 10 assignments' ), 'headline uses count_unique_tracked_courses(), the Courses tab badge figure' );
check( 8 === substr_count( $parts['html'], 'Issues certificate</span></td>' ) && 2 === substr_count( $parts['html'], 'No certificate</span></td>' ), 'certificate flag per row' );
check( false !== strpos( $parts['html'], 'gh-bar gh-bar--success' ) && false !== strpos( $parts['html'], 'gh-bar gh-bar--danger' ), 'bar tones: complete and low' );
check( false === stripos( $html, 'Export' ) && false === strpos( $html, '>Report<' ), 'no course export or per-course report: neither exists' );
check( false === strpos( $html, 'lessons' ) && false === strpos( $html, ' hrs' ), 'no course length or lesson count: the plugin has none' );
foreach ( array( 'ghca_crs_page', 'ghca_crs_search', 'ghca_crs_group', 'ghca_crs_cert', 'ghca_crs_sort' ) as $field ) {
	check( false !== strpos( $html, 'name="' . $field . '"' ), "course field $field" );
}
check( false !== strpos( $html, 'data-ghca-table="courses"' ) && false !== strpos( $html, 'data-ghca-table-id="courses"' ), 'courses hooks' );

reset_env( array(), array( 'ghca_crs_sort' => 'rate_asc' ) );
$sort  = GHCA_ACD_Redesign_Lists::course_sort();
$parts = GHCA_ACD_Redesign_Lists::course_parts( GHCA_ACD_Data_Provider::get_course_filters(), $sort );
preg_match_all( '#<strong class="gh-num">(\d+)%</strong>#', $parts['html'], $m );
$rates = array_map( 'intval', array_slice( $m[1], 0, 10 ) );
$sorted = $rates;
sort( $sorted );
check( 'rate_asc' === $sort && $rates === $sorted, 'Lowest completion first' );
check( false !== strpos( $parts['html'], 'aria-sort="ascending"' ), 'sorted column says so' );
reset_env( array(), array( 'ghca_crs_sort' => 'rate_desc' ) );
$parts = GHCA_ACD_Redesign_Lists::course_parts( GHCA_ACD_Data_Provider::get_course_filters(), GHCA_ACD_Redesign_Lists::course_sort() );
preg_match_all( '#<strong class="gh-num">(\d+)%</strong>#', $parts['html'], $m );
check( 100 === (int) $m[1][0], 'Highest completion first' );
reset_env( array(), array( 'ghca_crs_sort' => 'evil' ) );
check( 'rate_asc' === GHCA_ACD_Redesign_Lists::course_sort(), 'unknown sort falls back to the default' );
reset_env( array(), array( 'ghca_crs_cert' => 'no', 'ghca_crs_search' => 'trauma' ) );
$parts = GHCA_ACD_Redesign_Lists::course_parts( GHCA_ACD_Data_Provider::get_course_filters() );
check( 2 === substr_count( $parts['html'], '<tr><td><strong>Trauma-Informed Care' ) && false !== strpos( $parts['chips'], 'No certificate' ), 'certificate and search filters' );
reset_env( array(), array( 'ghca_crs_search' => 'zzz' ) );
$parts = GHCA_ACD_Redesign_Lists::course_parts( GHCA_ACD_Data_Provider::get_course_filters() );
check( false !== strpos( $parts['html'], 'No courses match these filters' ), 'filtered-empty' );
reset_env( array(), array(), array( 'people' => array() ) );
$parts = GHCA_ACD_Redesign_Lists::course_parts( GHCA_ACD_Data_Provider::get_course_filters() );
check( false !== strpos( $parts['html'], 'No tracked courses in your view' ) && false === strpos( $parts['html'], 'Clear filters' ), 'nothing tracked' );

// ---- 4. Certificates: counts are the provider's; downloads use the existing endpoint.
reset_env( array( 'can_docs' => true ) );
$agg    = GHCA_ACD_Data_Provider::get_aggregate();
$rows   = GHCA_ACD_Redesign_Lists::certificate_rows();
$counts = GHCA_ACD_Redesign_Lists::certificate_counts( $rows );
check( $counts['issued'] === (int) $agg['certificates_issued'] && 5 === $counts['issued'], 'Issued equals the provider\'s certificates_issued' );
check( $counts['missing'] === (int) $agg['certificates_missing'] && 1 === $counts['missing'], 'Missing equals the provider\'s certificates_missing' );
check( 1 === $counts['expiring'], 'one expiring soon' );
$html = GHCA_ACD_Redesign_Lists::render_certificates();
check( 1 === preg_match( '#<span class="gh-stat-top">Issued.*?<span class="gh-stat-val">5</span>#s', $html ), 'Issued tile' );
check( 1 === preg_match( '#gh-stat gh-stat--danger.*?Missing.*?<span class="gh-stat-val">1</span>#s', $html ), 'Missing tile in danger tone' );
check( 1 === preg_match( '#Completed · 30 days.*?<span class="gh-stat-val">4</span>#s', $html ) && false !== strpos( $html, 'since Oct 2, 2026' ), 'recent completions tile from recently_completed' );
check( false === stripos( $html, 'Pending issuance' ), 'no "Pending issuance" tile: it is the same number as Missing' );
check( false !== strpos( $html, '1 completed course has no certificate link' ) && false !== strpos( $html, 'data-ghca-set-value="missing" data-ghca-set-form="ghca-filters-certificates"' ), 'missing alert jumps to the Missing list' );
check( strpos( $html, 'gh-alert--danger' ) < strpos( $html, 'id="ghca-cert-list"' ), 'missing renders above the list' );
check( false === stripos( $html, 'ZIP' ) && false === strpos( $html, 'type="checkbox"' ), 'no ZIP download or row selection: neither exists' );
check( false === strpos( $html, 'Outside (approved)' ) && false === strpos( $html, '>Source<' ), 'no Source filter or column: outside certificates are not in this list' );
check( false === strpos( $html, 'example.test/certificates/' ), 'certificate links never reach the page' );
check( 10 === substr_count( $html, 'data-ghca-cert-pdf="https://example.test/wp-admin/admin-ajax.php?action=ghca_acd_certificate_download&amp;user_id=' ), 'every issued row (table and card) downloads through the existing endpoint' );
check( 0 === substr_count( $html, 'quiz_id' ), 'ids only, never a quiz id' );
check( 4 === substr_count( $html, 'data-ghca-set-field="ghca_cert_view"' ) && false !== strpos( $html, 'aria-pressed="true">Issued <span class="gh-count">5</span>' ), 'three view toggles, Issued pressed' );
check( 1 === preg_match( '#Recent completions.*?Priya Nair.*?2 days ago#s', $html ), 'recent completions' );
check( strpos( $html, 'Oct 30, 2026' ) < strpos( $html, 'Sep 27, 2025' ), 'newest completion first by default' );
check( false !== strpos( $html, 'Expires soon' ) && false !== strpos( $html, 'gh-st gh-st--overdue">Expired' ), 'expiring and expired marked' );

reset_env( array( 'can_docs' => false ) );
$html = GHCA_ACD_Redesign_Lists::render_certificates();
check( false === strpos( $html, 'data-ghca-cert-pdf' ), 'no download without user_can_view_employee_documents (the endpoint\'s own gate)' );

reset_env( array( 'can_docs' => true ), array( 'ghca_cert_view' => 'missing' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( false !== strpos( $parts['html'], 'Orientation Overview' ) && false !== strpos( $parts['html'], 'No certificate link' ) && false === strpos( $parts['html'], 'data-ghca-cert-pdf' ), 'Missing: who and which course, no download' );
check( false !== strpos( $parts['html'], 'data-ghca-employee-drawer="202"' ), 'Missing rows open the employee drawer' );
reset_env( array( 'can_docs' => true ), array( 'ghca_cert_view' => 'expiring' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( 'Showing 1–1 of 1' === $parts['summary'] && false !== strpos( $parts['html'], 'Jordan Ellis' ), 'Expiring' );
reset_env( array(), array( 'ghca_cert_course' => '504', 'ghca_cert_when' => '30' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( 'Showing 1–1 of 1' === $parts['summary'] && false !== strpos( $parts['chips'], 'Course: Fatal Five' ) && false !== strpos( $parts['chips'], 'last 30 days' ), 'course and date filters (Daniel\'s Fatal Five is 40 days old)' );
reset_env( array(), array( 'ghca_cert_search' => 'tomás' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( 'Showing 1–2 of 2' === $parts['summary'], 'search by employee' );
reset_env( array(), array( 'ghca_cert_orderby' => 'name' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( strpos( $parts['html'], 'Priya Nair' ) < strpos( $parts['html'], 'Daniel Reyes' ), 'a request to sort by employee is ignored: newest first stays' );
check( 1 === substr_count( $parts['html'], 'data-ghca-sort=' ) && false !== strpos( $parts['html'], 'data-ghca-sort="completed_ts"' ), 'the only certificate sort is the date column, as on the board' );
reset_env( array(), array( 'ghca_cert_order' => 'asc' ) );
$parts = GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() );
check( strpos( $parts['html'], 'Sep 27, 2025' ) < strpos( $parts['html'], 'Oct 30, 2026' ) && false !== strpos( $parts['html'], 'data-ghca-sort="completed_ts" data-ghca-sort-order="desc" aria-sort="ascending"' ), 'oldest first by the date header' );
reset_env( array(), array( 'ghca_cert_view' => 'all", "x', 'ghca_cert_orderby' => 'user_pass', 'ghca_cert_when' => '9999' ) );
$f = GHCA_ACD_Redesign_Lists::certificate_filters();
check( 'issued' === $f['view'] && 'completed_ts' === $f['orderby'] && '' === $f['when'], 'unknown view, sort and date values fall back' );
reset_env( array(), array( 'ghca_cert_search' => 'zzz' ) );
check( false !== strpos( GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() )['html'], 'No certificates match these filters' ), 'filtered-empty' );
reset_env( array(), array( 'ghca_cert_view' => 'missing' ), array( 'people' => array( array( 304, 'Di Clear', 11, 'completed', 'All assigned courses complete', 90, array( ghca_lists_course( 504, 'Fatal Five', 'completed', true, true, 3 ) ), array() ) ) ) );
check( false !== strpos( GHCA_ACD_Redesign_Lists::certificate_parts( GHCA_ACD_Redesign_Lists::certificate_filters() )['html'], 'Nothing is missing' ), 'Missing view all clear' );
check( false === strpos( GHCA_ACD_Redesign_Lists::render_certificates(), 'gh-alert--danger' ), 'no alert when nothing is missing' );

// Agency isolation: only the provider's active, in-scope employees appear.
reset_env();
$ref = new ReflectionProperty( 'GHCA_ACD_Data_Provider', 'aggregate' );
$ref->setAccessible( true );
$agg = $ref->getValue();
$agg['inactive_employees'] = array( array( 'user_id' => 999, 'name' => 'Suspended Sam', 'group' => 'Other', 'group_id' => 99, 'courses' => array( ghca_lists_course( 504, 'Fatal Five', 'completed', true, true, 1 ) ) ) );
$ref->setValue( null, $agg );
check( false === strpos( GHCA_ACD_Redesign_Lists::render_certificates(), 'Suspended Sam' ) && false === strpos( GHCA_ACD_Redesign_Lists::render_overdue(), 'Suspended Sam' ), 'inactive employees never appear' );

// ---- 5. Gates and the AJAX contract.
foreach ( array( 'render_overdue', 'render_courses', 'render_certificates' ) as $fn ) {
	reset_env( array( 'can_view' => false ) );
	check( '' === GHCA_ACD_Redesign_Lists::$fn(), "$fn renders nothing without dashboard access" );
}
foreach ( array( 'priority', 'courses', 'certificates' ) as $table ) {
	reset_env( array( 'can_view' => false ) );
	$r = ghca_env_ajax( array( 'ghca_table' => $table, 'ghca_ui' => 'board' ) );
	check( 403 === $r['status'] && false === $r['payload']['success'], "$table refresh refused without dashboard access" );
	reset_env( array( 'bad_nonce' => true ) );
	$r = ghca_env_ajax( array( 'ghca_table' => $table, 'ghca_ui' => 'board' ) );
	check( 403 === $r['status'], "$table refresh refused with a bad nonce" );
	reset_env();
	$r = ghca_env_ajax( array( 'ghca_table' => $table, 'ghca_ui' => 'board' ) );
	check( 200 === $r['status'] && true === $r['payload']['success'] && isset( $r['payload']['data']['html'], $r['payload']['data']['chips'], $r['payload']['data']['summary'] ), "$table board refresh returns html, chips and summary" );
}
reset_env( array( 'redesign' => false ) );
$r = ghca_env_ajax( array( 'ghca_table' => 'priority', 'ghca_ui' => 'board' ) );
check( isset( $r['payload']['data']['html'] ) && ! isset( $r['payload']['data']['chips'] ) && false !== strpos( $r['payload']['data']['html'], 'ghca-acd__table--priority' ), 'redesign off: priority refresh is the legacy table even if board is asked for' );
$r = ghca_env_ajax( array( 'ghca_table' => 'courses', 'ghca_ui' => 'board' ) );
check( false !== strpos( $r['payload']['data']['html'], 'ghca-acd__table--courses' ), 'redesign off: legacy courses table' );
$r = ghca_env_ajax( array( 'ghca_table' => 'certificates', 'ghca_ui' => 'board' ) );
check( 400 === $r['status'] && false === $r['payload']['success'], 'redesign off: the certificates table does not exist' );
reset_env();
$r = ghca_env_ajax( array( 'ghca_table' => 'priority' ) );
check( false !== strpos( $r['payload']['data']['html'], 'ghca-acd__table--priority' ), 'redesign on, no board flag: legacy markup' );

// FluentCRM active: the reminder log appears only for people who may edit records.
define( 'FLUENTCRM', '1' );
reset_env( array( 'can_admin' => false ) );
check( false === strpos( GHCA_ACD_Redesign_Lists::render_overdue(), 'Log a reminder' ), 'FluentCRM active, no edit-records permission: no reminder log' );
reset_env( array( 'can_admin' => true ) );
$html = GHCA_ACD_Redesign_Lists::render_overdue();
check( 3 === substr_count( $html, 'Log a reminder' ) && false !== strpos( $html, 'ghca_acd_reminder=1' ), 'reminder log uses the existing nonce-protected FluentCRM link' );
reset_env( array( 'can_admin' => true, 'can_view_user' => false ) );
check( false === strpos( GHCA_ACD_Redesign_Lists::render_overdue(), 'Log a reminder' ), 'no reminder log for an employee the viewer cannot see' );

// ---- 6. Day counts use the site calendar.
GHCA_ACD_Redesign_Lists::$now = strtotime( '2026-11-01 23:30:00 UTC' );
check( 1 === GHCA_ACD_Redesign_Lists::days_from_today( strtotime( '2026-11-02 00:10:00 UTC' ) ), 'tomorrow is one day, whatever the hour' );
check( -7 === GHCA_ACD_Redesign_Lists::days_from_today( strtotime( '2026-10-25 08:00:00 UTC' ) ), 'seven days ago' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed of $checks redesigned list checks\n" );
	exit( 1 );
}
echo "PASS: $checks redesigned list checks\n";
