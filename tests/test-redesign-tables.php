<?php
/**
 * Redesigned Employees and Inactive tabs. Runs the plugin's own provider, pager and renderer against
 * synthetic rows in a stand-in WordPress (tests/fixtures/redesign-tables-env.php). It checks markup,
 * hooks the scripts depend on, escaping, permissions and the AJAX contract. It is not a browser
 * render; tests/browser-redesign-tables.cjs covers that.
 */
require __DIR__ . '/fixtures/redesign-tables-env.php';

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
function reset_env( array $over = array(), int $count = 10 ): void {
	$GLOBALS['ghca_env'] = array_merge( array( 'logged_in' => true, 'can_view' => true, 'manage_users' => true, 'redesign' => true, 'now' => strtotime( '2026-11-01 12:00:00 UTC' ) ), $over );
	$_GET  = array();
	$_POST = array();
	ghca_env_seed( $count );
}
function rows_in( string $html ): int {
	return substr_count( $html, '<tr class="is-risk">' ) + substr_count( $html, '<tr>' ) - 1; // minus the header row
}

// ---- Status tones: every slug the provider can emit, new-hire variants included.
$tones = array(
	'completed' => 'completed', 'new_hire_completed' => 'completed', 'in_progress' => 'progress', 'not_started' => 'notstarted',
	'overdue' => 'overdue', 'new_hire_overdue' => 'overdue', 'expired' => 'overdue', 'expiring_soon' => 'duesoon',
	'new_hire_in_progress' => 'duesoon', 'new_hire_not_started' => 'duesoon', 'something_new' => 'na',
);
foreach ( $tones as $slug => $tone ) {
	check( GHCA_ACD_Redesign_Tables::status_tone( $slug ) === $tone, "status tone for $slug is $tone" );
}

// ---- Due note: derived only from the due date already shown.
$now = strtotime( '2026-11-01 12:00:00 UTC' );
$d   = static function ( int $days ) use ( $now ): int { return $now + $days * 86400; };
$n   = GHCA_ACD_Redesign_Tables::due_note( $d( -7 ), 'overdue', $now );
check( $n && '7 days late' === $n['text'] && 'danger' === $n['tone'], 'overdue 7 days says 7 days late' );
$n = GHCA_ACD_Redesign_Tables::due_note( $d( -1 ), 'expired', $now );
check( $n && '1 day late' === $n['text'], 'singular day' );
$n = GHCA_ACD_Redesign_Tables::due_note( $d( 3 ), 'overdue', $now );
check( $n && 'past due' === $n['text'] && 'danger' === $n['tone'], 'overdue status with a future date never says "in 3 days"' );
$n = GHCA_ACD_Redesign_Tables::due_note( $now, 'in_progress', $now );
check( $n && 'due today' === $n['text'], 'due today' );
$n = GHCA_ACD_Redesign_Tables::due_note( $d( 9 ), 'not_started', $now );
check( $n && 'in 9 days' === $n['text'] && 'warning' === $n['tone'], 'due soon is a warning' );
$n = GHCA_ACD_Redesign_Tables::due_note( $d( 42 ), 'in_progress', $now );
check( $n && 'in 42 days' === $n['text'] && 'muted' === $n['tone'], 'far due date is muted' );
check( null === GHCA_ACD_Redesign_Tables::due_note( $d( 300 ), 'completed', $now ), 'completed employees get no note' );
check( null === GHCA_ACD_Redesign_Tables::due_note( 0, 'overdue', $now ), 'no date, no note' );
check( null === GHCA_ACD_Redesign_Tables::due_note( $d( -4 ), 'in_progress', $now ), 'a past date on a non-overdue status says nothing rather than guess' );

// ---- Page window.
check( GHCA_ACD_Redesign_Tables::page_window( 1, 1 ) === array( 1 ), 'one page' );
check( GHCA_ACD_Redesign_Tables::page_window( 1, 5 ) === array( 1, 2, 3, 0, 5 ), 'window at the start' );
check( GHCA_ACD_Redesign_Tables::page_window( 5, 9 ) === array( 1, 0, 3, 4, 5, 6, 7, 0, 9 ), 'window in the middle' );
check( GHCA_ACD_Redesign_Tables::page_window( 9, 9 ) === array( 1, 0, 7, 8, 9 ), 'window at the end' );

// ---- Active tab: structure and hooks the scripts need.
reset_env();
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( str_contains( $html, 'data-ghca-filter-form' ) && str_contains( $html, 'data-ghca-table="employees"' ), 'filter form keeps the existing hooks' );
check( str_contains( $html, 'data-ghca-table data-ghca-table-id="employees"' ), 'table mount keeps the existing hooks' );
check( str_contains( $html, 'data-ghca-ui="board"' ) && str_contains( $html, 'data-ghca-debounce="300"' ), 'form asks for board markup and the 300 ms debounce' );
foreach ( array( 'ghca_emp_page', 'ghca_orderby', 'ghca_order', 'ghca_emp_search', 'ghca_group', 'ghca_course', 'ghca_status', 'ghca_overdue', 'ghca_emp_per' ) as $name ) {
	check( str_contains( $html, 'name="' . $name . '"' ), "field $name is present" );
}
check( str_contains( $html, 'data-ghca-page-input' ), 'page input hook' );
check( str_contains( $html, 'form="ghca-filters-employees"' ) && str_contains( $html, 'data-ghca-per-page' ), 'per-page select is tied to the filter form' );
check( str_contains( $html, 'data-ghca-chips="employees"' ) && str_contains( $html, 'data-ghca-table-status="employees"' ), 'chip row and status line hooks' );
foreach ( array( 'name', 'group', 'progress_pct', 'due_timestamp', 'status_slug' ) as $key ) {
	check( str_contains( $html, 'data-ghca-sort="' . $key . '"' ), "sortable column $key keeps its key" );
}
check( 5 === substr_count( $html, 'data-ghca-sort="' ), 'only the five columns the provider can sort are sortable' );
check( ! str_contains( $html, 'ghca-acd__' ), 'no legacy dashboard class in board markup' );
check( str_contains( $html, 'gh-table-wrap' ) && str_contains( $html, 'class="gh-pager"' ) && str_contains( $html, 'gh-cards' ), 'board table, pager and phone cards' );
check( 10 === rows_in( $html ), 'ten synthetic employees give ten rows' );
check( str_contains( $html, '1–10 of 10' ), 'pager summary' );
check( str_contains( $html, 'Showing 1–10 of 10' ), 'chip row summary' );
check( 40 === substr_count( $html, 'data-ghca-employee-drawer=' ), 'each employee opens the existing drawer from the name and View button, in the table and in the phone card' );
check( str_contains( $html, 'gh-st--overdue' ) && str_contains( $html, 'gh-st--completed' ) && str_contains( $html, 'gh-st--duesoon' ), 'board status chips' );
check( str_contains( $html, 'All assigned courses complete' ), 'the plugin keeps its own status wording' );
check( str_contains( $html, 'is-risk' ) && str_contains( $html, 'gh-bar--danger' ) && str_contains( $html, 'gh-bar--success' ), 'risk edge, danger bar, success bar' );
check( str_contains( $html, 'role="progressbar"' ) && str_contains( $html, 'aria-valuenow="50"' ), 'progress bars expose their value' );
check( str_contains( $html, 'Export all (CSV)' ) && str_contains( $html, 'action=ghca_acd_export_csv' ), 'export keeps the existing handler and says it exports everyone' );
check( ! str_contains( $html, 'Export view' ), 'no claim that the export follows the filters' );
check( ! str_contains( $html, 'type="submit"' ), 'no hidden submit button as an invisible tab stop' );
check( ! str_contains( $html, 'gh-bulkbar' ) && ! str_contains( $html, 'type="checkbox" aria-label="Select' ), 'no bulk bar or row selection: the plugin has neither' );
check( ! str_contains( $html, 'Sorted by urgency' ), 'no claim about a default sort the plugin does not apply' );

// ---- Filtering, chips, sorting (real provider logic).
reset_env();
$_GET = array( 'ghca_status' => 'overdue' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( 3 === rows_in( $html ), 'status=overdue keeps the two overdue rows and the expired one (the provider folds expired into overdue)' );
check( str_contains( $html, 'Status: Overdue (incl. expired)' ) && str_contains( $html, 'data-ghca-filter-clear="ghca_status"' ), 'status chip and its clear hook' );
check( ! str_contains( $html, 'data-ghca-filter-clear="all"' ), 'a single filter shows no Clear all' );
check( str_contains( $html, 'selected="selected">Overdue (incl. expired)' ), 'select reflects the filter' );

reset_env();
$_GET = array( 'ghca_group' => '11', 'ghca_overdue' => '1', 'ghca_emp_search' => 'a' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( str_contains( $html, 'Group: Synthetic group 11' ) && str_contains( $html, 'Overdue only' ) && str_contains( $html, 'Search: a' ), 'chips for group, overdue and search' );
check( str_contains( $html, 'data-ghca-filter-clear="all"' ), 'Clear all appears with more than one filter' );
check( str_contains( $html, 'name="ghca_overdue" value="1" checked="checked"' ), 'overdue checkbox reflects the filter' );

reset_env();
$_GET = array( 'ghca_orderby' => 'name', 'ghca_order' => 'desc' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
$first = strpos( $html, 'class="gh-person-n"' );
check( false !== $first && str_contains( substr( $html, $first, 400 ), 'Rowan Vega' ) === false, 'descending name sort reorders rows' );
check( str_contains( $html, 'data-ghca-sort="name" data-ghca-sort-order="asc" aria-sort="descending"' ), 'sorted column reports aria-sort and flips the next order' );
check( str_contains( $html, 'data-ghca-sort="group" data-ghca-sort-order="asc" aria-sort="none"' ), 'other columns report none' );

// ---- Pagination.
reset_env( array(), 18 );
$_GET = array( 'ghca_emp_per' => '10' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( 10 === rows_in( $html ), 'per page 10 shows ten rows of eighteen' );
check( str_contains( $html, '1–10 of 18' ), 'summary for page one of two' );
check( str_contains( $html, 'aria-label="Previous page" disabled' ), 'previous is disabled on the first page' );
check( str_contains( $html, 'data-ghca-page="2" data-ghca-page-field="ghca_emp_page" aria-label="Page 2"' ), 'page buttons carry the field the script matches' );
check( str_contains( $html, 'aria-label="Page 1" aria-current="page"' ), 'current page is marked' );
check( str_contains( $html, '<option value="10" selected="selected">10</option>' ), 'per-page select shows the current size' );
$_GET = array( 'ghca_emp_per' => '10', 'ghca_emp_page' => '2' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( 8 === rows_in( $html ) && str_contains( $html, '11–18 of 18' ), 'second page has the remaining eight' );
check( str_contains( $html, 'aria-label="Next page" disabled' ), 'next is disabled on the last page' );

// ---- Empty states.
reset_env();
$_GET = array( 'ghca_emp_search' => 'no one has this name' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( str_contains( $html, 'No employees match these filters' ) && str_contains( $html, 'data-ghca-filter-clear="all"' ), 'filtered-empty offers Clear filters' );
check( ! str_contains( $html, 'class="gh-pager"' ), 'no pager when nothing matches' );
reset_env( array(), 0 );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( str_contains( $html, 'There are no active employees in your view' ) && ! str_contains( $html, 'Clear filters' ), 'all-clear empty state has nothing to clear' );

// ---- Escaping.
reset_env();
$agg = new ReflectionProperty( 'GHCA_ACD_Data_Provider', 'aggregate' );
$agg->setAccessible( true );
$data = $agg->getValue();
$data['employees'][0]['name']  = '<script>alert(1)</script> "Q"';
$data['employees'][0]['email'] = 'x"><img src=x onerror=1>@example.test';
$agg->setValue( null, $data );
$_GET = array( 'ghca_emp_search' => '"><script>x</script>' );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img src=x' ), 'names, emails and search text are escaped' );
reset_env();
$data = $agg->getValue();
$data['employees'][0]['name']  = '<script>alert(1)</script> "Q"';
$data['employees'][0]['email'] = 'x"><img src=x onerror=1>@example.test';
$agg->setValue( null, $data );
$html = GHCA_ACD_Redesign_Tables::render_employees();
check( str_contains( $html, '&lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot;' ), 'a hostile name renders as text' );
check( ! str_contains( $html, '<img src=x' ), 'a hostile email cannot break out of its attribute' );

// ---- Inactive tab.
reset_env();
$html = GHCA_ACD_Redesign_Tables::render_employees( array(), 'inactive' );
check( str_contains( $html, 'data-ghca-table="inactive_employees"' ) && str_contains( $html, 'name="ghca_inactive_search"' ) && str_contains( $html, 'name="ghca_inactive_per"' ) && str_contains( $html, 'name="ghca_inactive_page"' ), 'inactive tab uses the inactive field names' );
check( 2 === rows_in( $html ), 'two inactive employees' );
check( 2 === substr_count( $html, 'gh-st gh-st--suspended gh-person-chip' ), 'suspended chip on each inactive row, as before' );
check( str_contains( $html, 'Not counted in dashboard totals' ), 'inactive note' );
check( ! str_contains( $html, 'Export all (CSV)' ), 'the export covers active employees only, so Inactive does not offer it' );
check( ! str_contains( $html, 'Reactivate' ) && ! str_contains( $html, 'Inactive since' ) && ! str_contains( $html, 'Packets' ), 'no control or column the plugin has no data or action for' );
reset_env( array( 'manage_users' => false ) );
check( '' === GHCA_ACD_Redesign_Tables::render_employees( array(), 'inactive' ), 'inactive tab stays hidden without manage-users' );
check( '' !== GHCA_ACD_Redesign_Tables::render_employees(), 'active tab does not need manage-users' );

// ---- Permissions.
reset_env( array( 'logged_in' => false ) );
check( '' === GHCA_ACD_Redesign_Tables::render_employees(), 'logged-out visitors get nothing' );
reset_env( array( 'can_view' => false ) );
check( '' === GHCA_ACD_Redesign_Tables::render_employees(), 'accounts without dashboard access get nothing' );

// ---- AJAX contract.
reset_env();
$parts = GHCA_ACD_Redesign_Tables::employee_table_parts( GHCA_ACD_Data_Provider::get_employee_filters() );
check( isset( $parts['html'], $parts['chips'], $parts['summary'] ), 'refresh returns html, chips and summary' );
check( 'Showing 1–10 of 10' === $parts['summary'], 'summary for the live region' );
check( ! str_contains( $parts['html'], 'data-ghca-filter-form' ), 'refresh returns the table, not the filter form' );
check( str_contains( $parts['chips'], 'Showing 1–10 of 10' ), 'chips carry the summary' );

check( false === GHCA_ACD_Redesign_Tables::board_requested(), 'no board markup unless asked' );
$_POST['ghca_ui'] = 'board';
check( true === GHCA_ACD_Redesign_Tables::board_requested(), 'board markup when asked' );
$GLOBALS['ghca_env']['redesign'] = false;
check( false === GHCA_ACD_Redesign_Tables::board_requested(), 'a client cannot force board markup while the redesign is off' );
$_POST['ghca_ui'] = 'anything else';
$GLOBALS['ghca_env']['redesign'] = true;
check( false === GHCA_ACD_Redesign_Tables::board_requested(), 'unknown values mean legacy markup' );

// ---- The real handler, driven end to end.
reset_env();
$r = ghca_env_ajax( array( 'ghca_table' => 'employees', 'ghca_ui' => 'board', 'ghca_emp_per' => '10', 'ghca_status' => 'overdue' ) );
check( true === $r['payload']['success'] && isset( $r['payload']['data']['html'], $r['payload']['data']['chips'], $r['payload']['data']['summary'] ), 'board request returns html, chips and summary' );
check( str_contains( $r['payload']['data']['html'], 'gh-table' ) && ! str_contains( $r['payload']['data']['html'], 'ghca-acd__table' ), 'board request returns board markup' );
check( 'Showing 1–3 of 3' === $r['payload']['data']['summary'], 'the filter in the request is applied' );
$r = ghca_env_ajax( array( 'ghca_table' => 'employees', 'ghca_emp_per' => '10' ) );
check( true === $r['payload']['success'] && ! isset( $r['payload']['data']['chips'] ) && str_contains( $r['payload']['data']['html'], 'ghca-acd__table--employees' ), 'a request without ghca_ui gets the legacy markup, unchanged' );
$r = ghca_env_ajax( array( 'ghca_table' => 'inactive_employees', 'ghca_ui' => 'board' ) );
check( true === $r['payload']['success'] && str_contains( $r['payload']['data']['html'], 'gh-st--suspended' ), 'inactive board request' );
reset_env( array( 'manage_users' => false ) );
$r = ghca_env_ajax( array( 'ghca_table' => 'inactive_employees', 'ghca_ui' => 'board' ) );
check( false === $r['payload']['success'] && 403 === $r['status'], 'inactive refresh is refused without manage-users, board or not' );
reset_env( array( 'can_view' => false ) );
$r = ghca_env_ajax( array( 'ghca_table' => 'employees', 'ghca_ui' => 'board' ) );
check( false === $r['payload']['success'] && 403 === $r['status'], 'refresh is refused without dashboard access' );
reset_env( array( 'bad_nonce' => true ) );
$r = ghca_env_ajax( array( 'ghca_table' => 'employees', 'ghca_ui' => 'board' ) );
check( false === $r['payload']['success'], 'refresh is refused with a bad nonce' );
reset_env( array( 'redesign' => false ) );
$r = ghca_env_ajax( array( 'ghca_table' => 'employees', 'ghca_ui' => 'board' ) );
check( true === $r['payload']['success'] && str_contains( $r['payload']['data']['html'], 'ghca-acd__table--employees' ), 'with the redesign off, even a board request gets the legacy markup' );
reset_env();
$r = ghca_env_ajax( array( 'ghca_table' => 'courses', 'ghca_ui' => 'board' ) );
// Slice 4 redesigned the Courses tab, so its board refresh now answers with board parts too.
check( true === $r['payload']['success'] && isset( $r['payload']['data']['chips'] ) && ! str_contains( $r['payload']['data']['html'], 'ghca-acd__table--courses' ), 'the courses table answers a board request with board markup (slice 4)' );

// ---- Handler order: nonce and capability first, then the markup choice.
$ajax = (string) file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
$fn   = substr( $ajax, (int) strpos( $ajax, 'function ajax_filter_table' ), 2200 );
check( strpos( $fn, "check_ajax_referer( 'ghca_acd_table'" ) < strpos( $fn, 'board_requested' ), 'nonce is checked before the markup choice' );
check( strpos( $fn, 'can_view_dashboard' ) < strpos( $fn, 'board_requested' ), 'dashboard access is checked before the markup choice' );
check( strpos( $fn, 'user_can_manage_users' ) < strpos( $fn, "employee_table_parts( \$filters, 'inactive' )" ), 'the inactive board response is behind manage-users' );
check( str_contains( $fn, "'priority'" ) && str_contains( $fn, "'courses'" ) && ! preg_match( "/case 'priority':[^;]*Redesign_Tables/s", $fn ), 'priority and course tables stay on their existing markup this slice' );

// ---- Legacy tabs are untouched when the redesign is off or the shortcode is used on its own.
$short = (string) file_get_contents( __DIR__ . '/../includes/class-shortcodes.php' );
check( str_contains( $short, 'ghca-acd__table--employees' ) && str_contains( $short, 'public static function render_employee_table' ), 'legacy employee table renderer is still in place' );

// ---- Overview wiring.
$redesign = (string) file_get_contents( __DIR__ . '/../includes/class-redesign.php' );
check( str_contains( $redesign, "GHCA_ACD_Redesign_Tables::render_employees( \$atts )" ) && str_contains( $redesign, "render_employees( \$atts, 'inactive' )" ), 'the redesigned dashboard renders both tabs through the board renderer' );
check( str_contains( $redesign, "'board_panels'" ) && str_contains( $redesign, "'gh-tp' : 'gh-legacy'" ), 'board panels do not get the legacy wrapper' );
check( str_contains( $redesign, 'data-ghca-toasts' ), 'the toast stack is inside the themed wrapper' );

if ( $failed ) {
	fwrite( STDERR, "$failed of $checks checks failed\n" );
	exit( 1 );
}
echo "PASS: $checks redesigned table checks\n";
