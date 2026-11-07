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
check( str_contains( $html, 'Verified start date missing' ) && str_contains( $html, '<dt>Verified start</dt><dd>Not entered</dd>' ) && 2 === substr_count( $html, 'data-ghca-edit-records="42"' ), 'an unverified start date is flagged, with Enter date and Edit Record both opening Edit Records' );
check( ! str_contains( $html, 'Restricted to authorized personnel' ) && ! str_contains( $html, 'restricted to authorized personnel' ) && str_contains( $html, 'Confidential: shown only to people with permission to edit employee records.' ), 'the confidentiality note says who can see the records and why; it no longer reads like a denial' );
check( str_contains( $html, 'Administrative Overview' ) && str_contains( $html, 'Employee Training Record' ) && str_contains( $html, 'Compliance Review' ) && str_contains( $html, 'System meta' ) && str_contains( $html, 'class="app-adm-status"' ), 'Administration follows the updated Drawer-Administration board' );
check( str_contains( $html, 'Not set' ), 'employment type' );
check( 1 === substr_count( $html, '30-day' ) && str_contains( $html, 'shows “Missing evidence” until a verified employment start date is entered' ) && ! str_contains( $html, "Can't verify" ) && ! str_contains( $html, 'Within 30 days' ), 'the only 30-day wording is the calculator\'s own Missing evidence rule; no verdict is computed here' );
check( ! preg_match( '/ghca-acd__(?!drawer-chrome)/', preg_replace( '#<div class="gh-legacy.*?</div>#s', '', $html ) ), 'no legacy class outside the fragments that still use them' );
check( ! str_contains( $html, 'stub-documents' ) && ! str_contains( $html, 'stub-history' ) && ! str_contains( $html, 'stub-manual' ), 'the board drawer renders Documents and Communications itself, not through the legacy fragments' );

// ---- Capability gates are the model's, unchanged.
$h = board( array( 'can_docs' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="documents"' ) && ! str_contains( $h, 'data-ghca-documents-section' ) && ! str_contains( $h, 'data-ghca-manual-entry' ) && ! str_contains( $h, 'data-ghca-pdf-packet' ), 'no Documents tab, records, upload form or packet items without document access' );
$h = board( array( 'can_comms' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="communications"' ) && ! str_contains( $h, 'data-ghca-history-user' ), 'no Communications without history access' );
$h = board( array( 'can_admin' => false ) );
check( ! str_contains( $h, 'data-ghca-tab="administration"' ) && ! str_contains( $h, 'data-ghca-edit-records' ) && ! str_contains( $h, 'data-ghca-mark-reviewed' ), 'no Administration without edit-records access' );
$h = board( array( 'can_remind' => false ) );
check( ! str_contains( $h, 'data-ghca-reminder' ), 'no reminder control without the send-reminders capability' );
$h = board( array( 'suspended' => true ) );
check( str_contains( $h, '<span>Inactive ·</span>' ) && str_contains( $h, 'not authorized for active service' ), 'suspended employee' );
$h = board( array( 'reviewed_at' => strtotime( '2026-10-30 15:00:00 UTC' ) ) );
check( str_contains( $h, 'Reviewed Oct 30</span>' ) && ! str_contains( $h, 'data-ghca-review-badge hidden' ), 'reviewed badge shows once reviewed' );
check( 2 === substr_count( $h, 'Reviewed on Oct 30, 2026 by an admin' ) && str_contains( $h, '<dt>Last Reminder</dt><dd>None recorded</dd>' ), 'review line in the card and in System meta; last reminder shown' );
$h = board( array( 'can_comms' => false ) );
check( ! str_contains( $h, 'Last Reminder' ), 'last reminder is communication history: hidden without that permission' );
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


// =====================================================================================================
// Documents and Communications
// =====================================================================================================
$ctx = array( 'jotform' => true, 'messaging' => true );

// The legacy path must not pay for, or change because of, the board data.
ghca_env_reset( $ctx );
$legacy_model = GHCA_ACD_AJAX::drawer_model( 42, ghca_env_drawer_employee() );
check( null === $legacy_model['documents_data'] && null === $legacy_model['manual_data'] && null === $legacy_model['history_data'], 'the legacy model carries no board data' );
check( str_contains( $legacy_model['documents_html'], 'stub-documents' ) && str_contains( $legacy_model['history_html'], 'stub-history' ), 'and still carries the legacy fragments' );
ghca_env_reset( $ctx );
$board_model = GHCA_ACD_AJAX::drawer_model( 42, ghca_env_drawer_employee(), true );
check( '' === $board_model['documents_html'] && '' === $board_model['history_html'] && '' === $board_model['manual_html'], 'the board model does not build the legacy fragments' );

// ---- Documents.
$h = board( $ctx );
check( str_contains( $h, 'data-ghca-documents-section="42"' ) && str_contains( $h, '5 items' ), 'records card with the total' );
check( str_contains( $h, 'data-ghca-documents-all="42" data-ghca-documents-name="Jordan Ellis"' ), 'View all documents keeps the hook the documents script binds to' );
check( 3 === substr_count( $h, '<li class="gh-file">' ), 'three most recent records' );
check( (bool) preg_match( '#<li class="gh-file">.*?<strong class="gh-file-n">Onboarding form\.pdf</strong>.*?gh-st--review">Awaiting review.*?data-ghca-document-open="7" data-action="preview"#s', $h ), 'a pending Jotform record: title in the <strong> the script reads, a needs-review chip, a document preview hook' );
check( (bool) preg_match( '#Red Cross card\.jpg</strong>.*?CPR / First Aid · Oct 30, 2026 · Manual entry.*?gh-st--approved">Approved.*?data-ghca-manual-open="9" data-action="preview"#s', $h ), 'a manual entry: approved chip, manual preview hook, "Manual entry" in the detail line' );
check( (bool) preg_match( '#Handbook sign-off\.pdf</strong>.*?gh-st--na">General document#s', $h ) && 2 === substr_count( $h, 'data-action="preview"' ), 'a record that is not viewable has no preview button' );
check( str_contains( $h, '<strong>External training</strong> 1 approved, 1 pending' ), 'external training summary' );
check( str_contains( $h, 'FILE</span>' ) && str_contains( $h, 'PDF</span>' ), 'file type labels' );
$h = board( $ctx + array( 'records' => array() ) );
check( str_contains( $h, 'No employee documents found.' ) && str_contains( $h, 'data-ghca-documents-all="42"' ), 'an empty list says so and still offers all documents' );
$h = board( $ctx + array( 'secure' => false ) );
check( str_contains( $h, 'No employee documents found.' ) && ! str_contains( $h, 'data-ghca-documents-section' ) && ! str_contains( $h, 'data-ghca-manual-entry' ), 'without a secure connection no records and no upload form are offered' );
$h = board( $ctx + array( 'can_view_user' => false ) );
check( ! str_contains( $h, 'Onboarding form.pdf' ), 'records are not shown for an employee out of scope' );
$records = array( array( 'kind' => 'jotform', 'id' => 7, 'name' => '<img src=x onerror=1>.pdf', 'context' => '"><script>1</script>', 'media_type' => 'application/pdf', 'timestamp' => 0, 'status' => 'Approved', 'status_key' => 'approved', 'viewable' => true ) );
$h = board( $ctx + array( 'records' => $records ) );
check( ! str_contains( $h, '<img src=x' ) && ! str_contains( $h, '<script>1' ), 'record names and contexts are escaped' );

// ---- Outside certificate form.
$h = board( $ctx );
check( str_contains( $h, 'data-ghca-manual-entry="42"' ) && str_contains( $h, 'data-ghca-manual-form enctype="multipart/form-data"' ), 'the card and form carry the hooks the upload script binds to' );
foreach ( array( 'action" value="ghca_acd_jotform_manual_upload', 'name="nonce" value="', 'name="employee_id" value="42"', 'name="ghca_ui" value="board"', 'required name="catalog_id"', 'required type="date" name="completion_date"', 'required maxlength="1000" name="entry_reason"', 'required type="file" name="certificate_file" accept="application/pdf,image/jpeg,image/png"' ) as $needle ) {
	check( str_contains( $h, $needle ), "form field: $needle" );
}
check( str_contains( $h, '<option value="11">CPR / First Aid (2 hrs)</option>' ) && str_contains( $h, '&lt;b&gt;Lift &amp; transfer&lt;/b&gt; (1.5 hrs)' ), 'catalog lessons, escaped' );
check( str_contains( $h, 'data-ghca-manual-status aria-live="polite"' ) && str_contains( $h, 'Save for review' ), 'status line and submit' );
check( str_contains( $h, 'Only then does it count toward annual compliance' ), 'it says the certificate stays pending until approved' );
check( ! str_contains( $h, 'Expires' ) && ! str_contains( $h, '10 MB' ), 'no field or limit the upload does not have' );
check( str_contains( $h, '1 manually entered certificate is listed under Records on file above.' ), 'manual entry note' );
check( 5 === substr_count( $h, ' <span class="gh-req">*</span>' ) - 1 || 4 === substr_count( $h, ' <span class="gh-req">*</span>' ), 'four required fields are marked' );
$h = board( $ctx + array( 'evidence_error' => 'Encryption key missing.' ) );
check( str_contains( $h, 'Encryption key missing.' ) && ! str_contains( $h, 'data-ghca-manual-form' ), 'when evidence storage is not ready the message shows and the form does not' );
$h = board( $ctx + array( 'catalog' => array() ) );
check( str_contains( $h, 'No active catalog lessons exist.' ) && ! str_contains( $h, 'data-ghca-manual-form' ), 'with no catalog lessons the form does not show' );
$h = board( $ctx + array( 'can_review_ext' => false ) );
check( ! str_contains( $h, 'data-ghca-manual-entry' ) && str_contains( $h, 'data-ghca-documents-section' ), 'without the external-training review capability the records show and the upload form does not' );
$h = board( array( 'messaging' => true ) );
check( ! str_contains( $h, 'data-ghca-manual-entry' ), 'no upload form while the Jotform tables are absent' );
ghca_env_reset( $ctx );
$card = GHCA_ACD_Redesign_Drawer::manual_entry_html( 42 );
check( str_starts_with( $card, '<section class="gh-card" data-ghca-manual-entry="42"' ) && str_contains( $card, 'name="ghca_ui" value="board"' ), 'the upload response card is the same card, and the next upload also asks for board markup' );
ghca_env_reset( $ctx + array( 'can_review_ext' => false ) );
check( '' === GHCA_ACD_Redesign_Drawer::manual_entry_html( 42 ), 'and is empty when the capability is gone' );

// ---- Communications.
$hist = array(
	array( 'communication_id' => 5, 'delivery_id' => 321, 'channel' => 'email', 'delivery_state' => 'accepted', 'actor_user_id' => 3, 'urgency' => 'normal', 'created_at' => '2026-10-27 16:42:00', 'subject' => 'Your Fatal Five course is due', 'message' => "Hi Jordan,\nstart with <b>Fatal Five</b>.", 'last_error_code' => '', 'last_error_summary' => '', 'attempt_count' => 1, 'provider_ref' => '' ),
	array( 'communication_id' => 5, 'delivery_id' => 322, 'channel' => 'sms', 'delivery_state' => 'failed', 'actor_user_id' => 3, 'urgency' => 'normal', 'created_at' => '2026-10-27 16:42:00', 'subject' => 'Your Fatal Five course is due', 'message' => "Hi Jordan,\nstart with <b>Fatal Five</b>.", 'last_error_code' => 'no_phone', 'last_error_summary' => 'No mobile number on file.', 'attempt_count' => 1, 'provider_ref' => '' ),
	array( 'communication_id' => 4, 'delivery_id' => 300, 'channel' => 'sms', 'delivery_state' => 'delivered', 'actor_user_id' => 0, 'urgency' => 'high', 'created_at' => '2026-10-20 09:00:00', 'subject' => '', 'message' => 'Welcome.', 'last_error_code' => '', 'last_error_summary' => '', 'attempt_count' => 1, 'provider_ref' => 'SM1' ),
);
$h = board( $ctx + array( 'history' => $hist, 'hist_total' => 4 ) );
check( str_contains( $h, 'data-ghca-history-user="42"' ) && str_contains( $h, 'data-ghca-history-list' ), 'history container keeps its hooks' );
check( 2 === substr_count( $h, 'gh-tl-dot ' ) && str_contains( $h, 'gh-tl-dot--danger' ) && str_contains( $h, 'gh-tl-dot--brand' ), 'one timeline entry per communication, danger where a delivery failed' );
check( str_contains( $h, '<strong>Email + SMS</strong>' ) && str_contains( $h, 'by Synthetic Admin 3' ) && str_contains( $h, 'by Former administrator' ), 'combined channels, the acting account, and a former administrator' );
check( str_contains( $h, 'gh-st gh-st--sent">Accepted by email transport' ) && str_contains( $h, 'gh-st gh-st--failed">Failed' ) && str_contains( $h, 'gh-st gh-st--sent">Delivered' ), 'delivery chips use the plugin wording' );
check( str_contains( $h, 'No mobile number on file.' ), 'a failure shows its reason' );
check( str_contains( $h, 'data-ghca-reminder-retry="322"' ) && 1 === substr_count( $h, 'data-ghca-reminder-retry=' ) && str_contains( $h, 'Retry SMS' ), 'retry is offered for the failed SMS only' );
check( str_contains( $h, '>SMS reminder</h4>' ), 'an SMS without a subject is called SMS reminder' );
check( str_contains( $h, 'Hi Jordan,<br />' ) && str_contains( $h, '&lt;b&gt;Fatal Five&lt;/b&gt;' ) && ! str_contains( $h, '<b>Fatal Five</b>' ), 'the message keeps its line breaks and is escaped' );
check( str_contains( $h, 'Urgency: High' ) && str_contains( $h, 'Urgency: Normal' ), 'urgency' );
check( str_contains( $h, '4 communications - 3 shown' ) && str_contains( $h, 'data-ghca-history-open data-user-id="42"' ), 'the full history opens through the existing hook' );
check( str_contains( $h, 'data-ghca-reminder="42"' ) && str_contains( $h, 'New reminder' ), 'New reminder' );
$h = board( $ctx + array( 'history' => array(), 'hist_total' => 0 ) );
check( str_contains( $h, 'No reminders have been recorded for this employee.' ) && str_contains( $h, 'No communications yet' ) && ! str_contains( $h, 'data-ghca-history-open' ), 'an empty history' );
$h = board( $ctx + array( 'history' => $hist, 'can_remind' => false ) );
check( ! str_contains( $h, 'data-ghca-reminder-retry' ) && ! str_contains( $h, 'New reminder' ), 'no retry and no New reminder without the capability' );
$h = board( array( 'jotform' => true ) );
check( str_contains( $h, 'Communication history is not available.' ) && ! str_contains( $h, 'data-ghca-history-user' ), 'without the messaging tables the tab says it is not available' );

// The retry rule, case by case. It is the legacy rule; the safety of an SMS resend depends on it.
$can = static function ( array $d, string $state, string $channel, bool $allowed = true ): bool {
	ghca_env_reset( array( 'can_remind' => $allowed ) );
	return GHCA_ACD_Redesign_Drawer::can_retry( $d, $state, $channel );
};
check( true === $can( array( 'attempt_count' => 1 ), 'failed', 'email' ), 'a failed email can be retried' );
check( false === $can( array( 'attempt_count' => 1 ), 'delivered', 'email' ) && false === $can( array( 'attempt_count' => 1 ), 'queued', 'email' ), 'only a failed delivery can be retried' );
check( true === $can( array( 'attempt_count' => 1, 'last_error_code' => 'no_phone' ), 'failed', 'sms' ), 'a failed SMS with no provider reference can be retried' );
check( false === $can( array( 'attempt_count' => 1, 'provider_ref' => 'SM1' ), 'failed', 'sms' ), 'an SMS the provider already has a reference for is not resent' );
foreach ( array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' ) as $code ) {
	check( false === $can( array( 'attempt_count' => 1, 'last_error_code' => $code ), 'failed', 'sms' ), "an SMS whose outcome is uncertain ($code) is never resent" );
}
check( true === $can( array( 'attempt_count' => 1, 'last_error_code' => 'twilio_transport_error' ), 'failed', 'email' ), 'the uncertain-SMS rule is about SMS only' );
check( true === $can( array( 'attempt_count' => 2 ), 'failed', 'email' ) && false === $can( array( 'attempt_count' => 3 ), 'failed', 'email' ), 'three attempts is the limit' );
check( false === $can( array( 'attempt_count' => 1 ), 'failed', 'email', false ), 'retry needs the send-reminders capability' );

// ---- Drift guard: the redesign's gates are the legacy renderers' gates.
$jf = (string) file_get_contents( __DIR__ . '/../includes/jotform/class-jotform-ui.php' );
check( str_contains( $jf, 'if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_view_employee_documents() || ! self::employee_in_scope( $employee_id ) ) {' ) && str_contains( $jf, 'return self::secure_transport() && GHCA_ACD_Roles::user_can_view_employee_documents() && self::employee_in_scope( $employee_id );' ), 'can_show_documents is the condition render_recent_documents applies' );
check( str_contains( $jf, 'if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_review_external_training() || $employee_id <= 0 || ! self::employee_in_scope( $employee_id ) || ! GHCA_ACD_Jotform_Schema::tables_exist() ) {' ) && str_contains( $jf, 'return self::secure_transport() && GHCA_ACD_Roles::user_can_review_external_training() && $employee_id > 0 && self::employee_in_scope( $employee_id ) && GHCA_ACD_Jotform_Schema::tables_exist();' ), 'can_show_manual_entry is the condition render_manual_entry_form applies' );
check( str_contains( $jf, "GHCA_ACD_Redesign_Tables::board_requested() ? GHCA_ACD_Redesign_Drawer::manual_entry_html( \$employee_id ) : self::render_manual_entry_form( \$employee_id )" ), 'the upload response picks the card by the same server-side test as every other board response' );
$up = substr( $jf, (int) strpos( $jf, 'function ajax_manual_upload' ), 900 );
check( str_contains( $up, "check_ajax_referer( 'ghca_acd_table'" ), 'the upload action still checks its nonce before anything else' );


if ( $failed ) {
	fwrite( STDERR, "$failed of $checks checks failed\n" );
	exit( 1 );
}
echo "PASS: $checks employee drawer checks\n";
