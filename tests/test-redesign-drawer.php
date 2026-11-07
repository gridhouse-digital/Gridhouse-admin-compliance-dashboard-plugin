<?php
/**
 * Employee drawer, legacy and redesigned. Runs the plugin's real wp_ajax_ghca_acd_get_employee_drawer
 * handler against synthetic records in a stand-in WordPress (tests/fixtures/redesign-drawer-env.php).
 *
 * The legacy drawer must not change: golden-legacy-drawer.sha256.json holds the SHA-256 of its HTML in
 * ten states, captured before the handler was split into a data model and two renderers.
 */
require __DIR__ . '/fixtures/redesign-drawer-env.php';

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
function board( array $env = array(), ?array $employee = null ): string {
	ghca_env_reset( $env );
	if ( null !== $employee ) {
		$GLOBALS['ghca_env']['employee'] = $employee;
	}
	$r = ghca_env_drawer( array( 'ghca_ui' => 'board' ) );
	return $r['payload']['data']['html'] ?? 'ERROR ' . json_encode( $r );
}

// ---- The legacy drawer is unchanged.
$golden = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-legacy-drawer.sha256.json' ), true );
$cases  = array(
	'full' => array(), 'no-docs' => array( 'can_docs' => false ), 'no-comms' => array( 'can_comms' => false ), 'no-admin' => array( 'can_admin' => false ),
	'no-remind' => array( 'can_remind' => false ), 'suspended' => array( 'suspended' => true, 'employment' => 'Part-time' ),
	'reviewed' => array( 'reviewed_at' => strtotime( '2026-10-30 15:00:00 UTC' ) ), 'oltl' => array( 'oltl' => true ),
);
foreach ( $cases as $name => $env ) {
	ghca_env_reset( $env );
	$html = ghca_env_drawer()['payload']['data']['html'] ?? '';
	check( hash( 'sha256', $html ) === $golden[ $name ], "legacy drawer is byte-identical in state '$name'" );
}
ghca_env_reset();
$e = ghca_env_drawer_employee(); $e['courses'] = array(); $GLOBALS['ghca_env']['employee'] = $e;
check( hash( 'sha256', ghca_env_drawer()['payload']['data']['html'] ?? '' ) === $golden['no-courses'], "legacy drawer is byte-identical with no courses" );
ghca_env_reset();
$e = ghca_env_drawer_employee(); $e['courses'] = array_slice( $e['courses'], 3 ); $GLOBALS['ghca_env']['employee'] = $e;
check( hash( 'sha256', ghca_env_drawer()['payload']['data']['html'] ?? '' ) === $golden['all-complete'], "legacy drawer is byte-identical with every course complete" );

// A request that does not ask for board markup, or the redesign off, keeps the legacy drawer.
ghca_env_reset();
check( str_contains( ghca_env_drawer()['payload']['data']['html'], 'ghca-acd__drawer-chrome' ), 'no ghca_ui means the legacy drawer' );
ghca_env_reset( array( 'redesign' => false ) );
check( str_contains( ghca_env_drawer( array( 'ghca_ui' => 'board' ) )['payload']['data']['html'], 'ghca-acd__drawer-chrome' ), 'with the redesign off a board request still gets the legacy drawer' );

// ---- Gates run before the markup choice.
ghca_env_reset( array( 'bad_nonce' => true ) );
check( false === ghca_env_drawer( array( 'ghca_ui' => 'board' ) )['payload']['success'], 'a bad nonce is refused' );
ghca_env_reset( array( 'can_view' => false ) );
check( false === ghca_env_drawer( array( 'ghca_ui' => 'board' ) )['payload']['success'], 'an account without dashboard access is refused' );
ghca_env_reset( array( 'can_view_user' => false ) );
$r = ghca_env_drawer( array( 'ghca_ui' => 'board' ) );
check( false === $r['payload']['success'] && ! isset( $r['payload']['data']['html'] ), 'an employee outside the viewer\'s scope is refused, with no markup' );
$ajax = (string) file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
$fn   = substr( $ajax, (int) strpos( $ajax, 'function ajax_get_employee_drawer' ), 2000 );
check( strpos( $fn, 'check_ajax_referer' ) < strpos( $fn, 'user_can_view()' ) && strpos( $fn, 'can_view_user' ) < strpos( $fn, 'board_requested' ), 'nonce, capability and employee scope come before the markup choice' );

// ---- Board markup.
$html = board();
check( str_contains( $html, 'id="ghca-acd-employee-drawer-name"' ) && str_contains( $html, '>Jordan Ellis</h2>' ), 'header names the employee and labels the dialog' );
check( str_contains( $html, 'gh-st gh-st--overdue">Overdue' ), 'status chip uses the board tone and the plugin wording' );
check( str_contains( $html, 'data-ghca-review-badge hidden' ), 'review badge is present and hidden until reviewed' );
check( str_contains( $html, 'Direct Support Professional (DSP)' ) && str_contains( $html, 'DSP — New Hire' ), 'role and group in the header' );
foreach ( array( 'training', 'documents', 'communications', 'administration' ) as $tab ) {
	check( str_contains( $html, 'data-ghca-tab="' . $tab . '"' ) && str_contains( $html, 'data-ghca-tabpanel="' . $tab . '"' ), "tab and panel for $tab keep their hooks" );
}
check( 1 === substr_count( $html, 'data-ghca-tablist' ), 'one tablist' );
check( str_contains( $html, 'aria-selected="true" aria-controls="ghca-acd-drawer-panel-training" tabindex="0"' ) && 3 === substr_count( $html, 'aria-selected="false"' ), 'training is selected, the rest are not' );
check( 4 === substr_count( $html, 'data-ghca-tabpanel=' ), 'four panels' );
check( 3 === preg_match_all( '/data-ghca-tabpanel="(?:documents|communications|administration)" hidden>/', $html ), 'every panel but Training starts hidden' );
check( str_contains( $html, 'Training <span class="gh-count">5</span>' ), 'training count is the number of courses' );
check( str_contains( $html, '--p: 40;' ), 'ring carries the existing progress percent' );
check( str_contains( $html, '3 of 5 courses' ) && str_contains( $html, '2 certificates' ), 'course fraction and certificate count' );
check( str_contains( $html, 'Next due Nov 14, 2026 · past due' ) && str_contains( $html, 'gh-due--danger' ), 'next due: an overdue status never reads "in N days"' );
check( str_contains( $html, 'gh-alert gh-alert--warning gh-dr-attn' ) && str_contains( $html, '>Individual Rights</span>' ), 'the alert leads with the first course in the existing priority order (expiring soon before not started), in the warning tone' );
check( str_contains( $html, 'Due Nov 25, 2026 (13 days remaining)' ), 'due text uses the existing due context' );
$e = ghca_env_drawer_employee(); $e['courses'] = array_slice( $e['courses'], 0, 2 );
check( str_contains( board( array(), $e ), 'gh-alert gh-alert--warning gh-dr-attn' ) && str_contains( board( array(), $e ), '>Documentation Essentials</span>' ), 'with no expiring course the alert leads with the in-progress course, as the legacy priority order has it' );
$e['courses'][0]['status'] = 'overdue';
check( str_contains( board( array(), $e ), 'gh-alert gh-alert--danger gh-dr-attn' ), 'an overdue first course is the danger tone' );
check( str_contains( $html, '<details class="gh-card gh-fold" open>' ), 'to-do and completed lists are native disclosures' );
check( str_contains( $html, 'To do <span class="gh-count">3</span>' ) && str_contains( $html, 'Completed <span class="gh-count">2</span>' ), 'list counts match the model' );
check( str_contains( $html, '5.5 hrs' ) && str_contains( $html, '55% complete' ), 'hours and progress carry through' );
check( str_contains( $html, 'gh-st gh-st--duesoon">Expiring soon' ), 'an expiring course is a due-soon chip' );
check( 1 === substr_count( $html, 'data-ghca-cert-trigger data-ghca-cert-url="https://example.test/cert/103"' ) && str_contains( $html, 'data-ghca-cert-download-url="https://example.test/wp-admin/admin-ajax.php?' ), 'completed course with a certificate has the trigger the certificate script binds to' );
check( str_contains( $html, 'No certificate' ), 'completed course without a certificate says so' );
check( str_contains( $html, 'href="https://example.test/course/101"' ), 'a course with a URL is a link' );
check( str_contains( $html, 'data-ghca-edit-records="42"' ) && str_contains( $html, 'data-ghca-mark-reviewed="42"' ) && str_contains( $html, 'data-ghca-review-text' ), 'administration keeps the edit and review hooks' );
check( str_contains( $html, 'data-ghca-admin-view="overview"' ) && str_contains( $html, 'data-ghca-admin-view="subpage" hidden' ), 'administration has the overview and a hidden sub-page' );
check( 2 === substr_count( $html, 'data-ghca-reminder="42"' ) && 2 === substr_count( $html, 'data-ghca-reminder-name="Jordan Ellis"' ), 'the alert and the footer each carry the reminder hook the messaging script binds to' );
check( str_contains( $html, 'data-ghca-pdf-packet="42" data-tracker="orientation"' ) && str_contains( $html, 'data-tracker="annual"' ) && ! str_contains( $html, 'data-tracker="oltl_training"' ), 'packet items match the legacy menu' );
check( str_contains( $html, 'data-ghca-more' ) && str_contains( $html, 'href="https://example.test/user-report?user_id=42"' ), 'More menu and report link' );
check( str_contains( $html, 'Not verified' ) && str_contains( $html, 'gh-dl-warn' ), 'an unverified start date is shown as needing attention' );
check( str_contains( $html, 'Not set' ), 'employment type' );
check( ! str_contains( $html, '30-day' ) && ! str_contains( $html, "Can't verify" ), 'no verdict the plugin does not compute here' );
check( ! preg_match( '/ghca-acd__(?!drawer-chrome)/', preg_replace( '#<div class="gh-legacy.*?</div>#s', '', $html ) ), 'no legacy class outside the fragments that still use them' );
check( str_contains( $html, 'stub-documents' ) && str_contains( $html, 'stub-history' ), 'the Documents and Communications fragments are present' );

// ---- Capability gates are the model's, unchanged.
$h = board( array( 'can_docs' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="documents"' ) && ! str_contains( $h, 'stub-documents' ) && ! str_contains( $h, 'data-ghca-pdf-packet' ), 'no Documents tab, fragment or packet items without document access' );
$h = board( array( 'can_comms' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="communications"' ) && ! str_contains( $h, 'stub-history' ), 'no Communications without history access' );
$h = board( array( 'can_admin' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="administration"' ) && ! str_contains( $h, 'data-ghca-edit-records' ) && ! str_contains( $h, 'data-ghca-mark-reviewed' ), 'no Administration without edit-records access' );
$h = board( array( 'can_remind' => false ) );
check( ! str_contains( $h, 'data-ghca-reminder' ), 'no reminder control without the send-reminders capability' );
$h = board( array( 'suspended' => true ) );
check( str_contains( $h, 'gh-st gh-st--suspended">Inactive' ) && str_contains( $h, 'not authorized for active service' ), 'suspended employee' );
$h = board( array( 'reviewed_at' => strtotime( '2026-10-30 15:00:00 UTC' ) ) );
check( str_contains( $h, 'Reviewed Oct 30</span>' ) && ! str_contains( $h, 'data-ghca-review-badge hidden' ), 'reviewed badge shows once reviewed' );
check( str_contains( $h, 'Reviewed on Oct 30, 2026 by an admin' ) && str_contains( $h, 'last reminder None recorded' ), 'review line and last reminder' );
$h = board( array( 'oltl' => true ) );
check( str_contains( $h, 'stub-oltl' ) && str_contains( $h, 'data-tracker="oltl_training"' ), 'OLTL card and packet appear together' );

// ---- Edge employees.
$e = ghca_env_drawer_employee(); $e['courses'] = array();
$h = board( array(), $e );
check( str_contains( $h, 'No courses assigned' ) && str_contains( $h, 'Training <span class="gh-count">0</span>' ), 'no courses' );
$e = ghca_env_drawer_employee(); $e['courses'] = array_slice( $e['courses'], 3 ); $e['status_slug'] = 'completed'; $e['status_label'] = 'All assigned courses complete'; $e['progress_pct'] = 100;
$h = board( array(), $e );
check( str_contains( $h, 'gh-alert--success' ) && str_contains( $h, 'Training is current' ) && str_contains( $h, '--ring: var(--gh-status-success-solid)' ), 'all complete: success alert and a green ring' );
check( str_contains( $h, 'To do <span class="gh-count">0</span>' ) && str_contains( $h, 'No training needs attention.' ), 'empty to-do list says so' );
$e = ghca_env_drawer_employee(); $e['courses'][2]['url'] = '';
check( ! str_contains( board( array(), $e ), 'Review requirement' ), 'no review link when the leading course has no URL' );

// ---- Escaping.
$e = ghca_env_drawer_employee();
$e['name'] = '<script>alert(1)</script> "Q"'; $e['group'] = '"><img src=x onerror=1>'; $e['courses'][0]['title'] = '<b onmouseover=1>Fatal</b>'; $e['courses'][0]['url'] = 'javascript:alert(1)';
$h = board( array(), $e );
check( ! str_contains( $h, '<script>' ) && ! str_contains( $h, '<img src=x' ) && ! str_contains( $h, '<b onmouseover' ), 'names, groups and course titles are escaped' );
check( ! str_contains( $h, 'href="javascript:' ), 'a javascript: course link is not emitted as written' );

// ---- Shell.
$shell = GHCA_ACD_Redesign_Drawer::shell_html();
check( str_contains( $shell, 'id="ghca-acd-employee-drawer"' ) && str_contains( $shell, 'id="ghca-acd-employee-drawer-body"' ) && str_contains( $shell, 'data-ghca-ui="board"' ), 'shell keeps the ids the script looks for' );
check( str_contains( $shell, 'role="dialog" aria-modal="true"' ) && str_contains( $shell, 'data-ghca-drawer-dialog' ) && str_contains( $shell, 'aria-label="Close employee panel"' ), 'dialog semantics and a labelled close button' );
check( str_contains( $shell, ' hidden aria-hidden="true"' ), 'closed until opened' );
check( 2 === substr_count( $shell, 'data-ghca-drawer-close' ), 'scrim and close button both close it' );
check( str_contains( $shell, 'role="status"' ), 'loading state is announced' );

// ---- One drawer per page.
ob_start();
GHCA_ACD_AJAX::render_employee_drawer_modal();
$legacy_shell = ob_get_clean();
check( str_contains( $legacy_shell, 'id="ghca-acd-employee-drawer"' ) && str_contains( $legacy_shell, 'ghca-acd__drawer-dialog' ), 'without the redesigned dashboard the legacy footer shell is printed as before' );
$flag = new ReflectionProperty( 'GHCA_ACD_Redesign', 'rendered' );
$flag->setAccessible( true );
$flag->setValue( null, true );
ob_start();
GHCA_ACD_AJAX::render_employee_drawer_modal();
check( '' === ob_get_clean(), 'the legacy footer drawer is not printed once the redesigned dashboard has its own' );
$flag->setValue( null, false );

$src = (string) file_get_contents( __DIR__ . '/../includes/class-redesign.php' );
check( str_contains( $src, "'employee_drawer'" ) && str_contains( $src, 'GHCA_ACD_Redesign_Drawer::shell_html()' ), 'the dashboard prints the shell inside the themed wrapper' );

if ( $failed ) {
	fwrite( STDERR, "$failed of $checks checks failed\n" );
	exit( 1 );
}
echo "PASS: $checks employee drawer checks\n";
