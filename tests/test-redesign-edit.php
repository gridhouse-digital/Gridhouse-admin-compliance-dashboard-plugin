<?php
/**
 * Edit Records sub-page, legacy and redesigned. Runs the plugin's real wp_ajax_ghca_acd_get_edit_records_form
 * handler against synthetic records in the drawer's stand-in WordPress.
 *
 * The legacy form must not change: golden-legacy-edit-form.sha256.json holds the SHA-256 of its HTML in seven
 * states, captured before the handler was split into a data model and two renderers. The redesigned form must
 * submit exactly the same field names, so the save handler receives the same input from either.
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
function edit_env( array $over = array() ): void {
	ghca_env_reset( $over );
	$GLOBALS['ghca_env']['employee'] = ghca_env_drawer_employee() + array( 'orientation_label' => '1 of 1 complete' );
}
function edit_form( array $post = array() ): array {
	$_POST = array_merge( array( 'user_id' => '42', 'nonce' => 'n' ), $post );
	try {
		GHCA_ACD_AJAX::ajax_get_edit_records_form();
	} catch ( Ghca_Env_Json $e ) {
		return $e->payload;
	}
	return array();
}
function names( string $html ): array {
	preg_match_all( '/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"/', $html, $m );
	$n = $m[1];
	sort( $n );
	return $n;
}

// ---- The legacy form is unchanged.
$golden = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-legacy-edit-form.sha256.json' ), true );
$cases  = array(
	'default' => array(), 'suspended' => array( 'suspended' => true ),
	'verified' => array( 'verified' => array( 'date' => '2026-10-14', 'source' => 'Offer letter "HR" <file>', 'verified_by' => 3, 'verified_at' => 1761900000 ), 'employment_key' => 'part_time', 'first_service' => '10/20/2026', 'worked_alone' => '11/02/2026' ),
	'removed-type' => array( 'employment_key' => 'gone_type' ),
);
foreach ( $cases as $name => $over ) {
	edit_env( $over );
	check( hash( 'sha256', edit_form()['data']['html'] ?? '' ) === $golden[ $name ], "legacy edit form is byte-identical in state '$name'" );
}
edit_env();
$e = ghca_env_drawer_employee(); $e['courses'] = array(); $GLOBALS['ghca_env']['employee'] = $e;
check( hash( 'sha256', edit_form()['data']['html'] ?? '' ) === $golden['no-courses'], 'legacy edit form is byte-identical with no courses' );
edit_env( array( 'can_admin' => false ) );
check( hash( 'sha256', json_encode( edit_form() ) ) === $golden['no-cap'] || json_encode( edit_form() ) === '{"success":false,"data":{"message":"Permission denied."}}', 'permission refusal is unchanged' );
edit_env( array( 'can_view_user' => false ) );
check( str_contains( json_encode( edit_form() ), 'Invalid employee or permission denied.' ), 'scope refusal is unchanged' );

// ---- Without ghca_ui, or with the redesign off, the legacy form is returned.
edit_env();
check( str_contains( edit_form()['data']['html'], 'ghca-acd__edit-form' ), 'no ghca_ui means the legacy form' );
edit_env( array( 'redesign' => false ) );
check( str_contains( edit_form( array( 'ghca_ui' => 'board' ) )['data']['html'], 'ghca-acd__edit-form' ), 'with the redesign off a board request still gets the legacy form' );

// ---- Gates come before the markup choice.
edit_env( array( 'bad_nonce' => true ) );
check( false === edit_form( array( 'ghca_ui' => 'board' ) )['success'], 'a bad nonce is refused' );
edit_env( array( 'can_admin' => false ) );
$r = edit_form( array( 'ghca_ui' => 'board' ) );
check( false === $r['success'] && ! isset( $r['data']['html'] ), 'without the edit-records capability a board request gets no markup' );
edit_env( array( 'can_view_user' => false ) );
$r = edit_form( array( 'ghca_ui' => 'board' ) );
check( false === $r['success'] && ! isset( $r['data']['html'] ), 'an employee out of scope gets no markup' );
$ajax = (string) file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
$fn   = substr( $ajax, (int) strpos( $ajax, 'function ajax_get_edit_records_form' ), 1500 );
check( strpos( $fn, 'check_ajax_referer' ) < strpos( $fn, 'user_can_edit_records' ) && strpos( $fn, 'can_view_user' ) < strpos( $fn, 'board_requested' ), 'nonce, capability and scope come before the markup choice' );

// ---- The redesigned form.
$with = array( 'verified' => array( 'date' => '2026-10-14', 'source' => 'Offer letter', 'verified_by' => 3, 'verified_at' => 1761900000 ), 'employment_key' => 'part_time', 'first_service' => '10/20/2026', 'worked_alone' => '11/02/2026' );
edit_env( $with );
$legacy = edit_form()['data']['html'];
edit_env( $with );
$board = edit_form( array( 'ghca_ui' => 'board' ) )['data']['html'];

check( names( $board ) === names( $legacy ), 'the redesigned form submits exactly the same field names as the legacy form' );
$legacy_n = names( $legacy );
check( in_array( 'course[101][mark_complete]', $legacy_n, true ) && in_array( 'employment_type', $legacy_n, true ) && in_array( 'registration_date', $legacy_n, true ), 'and those include the course, employment and registration fields' );
check( str_contains( $board, 'data-ghca-edit-form data-user-id="42"' ), 'the form keeps the hook the save script binds to' );
check( str_contains( $board, 'data-ghca-edit-close' ) && str_contains( $board, 'type="submit" class="gh-btn gh-btn--primary" data-ghca-edit-save' ), 'cancel and save keep their hooks' );
check( str_contains( $board, 'data-ghca-edit-dirty role="status" aria-live="polite">No unsaved changes' ), 'the unsaved-changes line starts at none and is announced' );
check( ! str_contains( $board, 'ghca-acd__' ), 'no legacy class in the redesigned form' );
check( str_contains( $board, '<option value="part_time" selected="selected">Part-time</option>' ) && str_contains( $board, '<option value="">Not set</option>' ), 'employment type options with the saved one selected' );
check( str_contains( $board, 'Per &lt;diem&gt;' ), 'option labels are escaped' );
check( (bool) preg_match( '/id="ghca-edit-role" class="gh-input is-readonly" value="Direct Support Professional \(DSP\)" readonly \/>/', $board ) && ! preg_match( '/id="ghca-edit-(role|group)"[^>]*name=/', $board ), 'role and group are read-only and unnamed, so nothing is submitted for them' );
check( str_contains( $board, 'gh-st gh-st--lg gh-st--active gh-dr-chip">Active' ) && str_contains( $board, 'gh-st--progress gh-dr-chip">1 of 1 complete' ), 'employment status and orientation completion' );
check( str_contains( $board, 'type="datetime-local" name="registration_date" value="2026-10-14T21:11"' ), 'registration stays editable, as in the legacy form' );
check( str_contains( $board, 'name="employment_date" value="2026-10-14"' ) && str_contains( $board, 'name="employment_source" maxlength="500" value="Offer letter"' ), 'verified start and source' );
check( str_contains( $board, 'name="employment_verified" value="1"' ) && str_contains( $board, 'I checked this employment date against its source.' ), 'the verification checkbox' );
check( str_contains( $board, 'name="first_service_date" value="2026-10-20"' ) && str_contains( $board, 'name="worked_alone_date" value="2026-11-02"' ), 'first service and worked-alone dates are converted for the date inputs' );
check( 5 === substr_count( $board, 'data-course-id="' ), 'one box per course' );
check( str_contains( $board, 'name="course[103][mark_complete]" value="1" checked="checked"' ) && str_contains( $board, 'name="course[101][mark_complete]" value="1" />' ), 'mark-complete reflects each course' );
check( (bool) preg_match( '#name="course\[103\]\[completed\]" value="2026-10-29T12:00"#', $board ), 'completion date in the site-local datetime format' );
check( str_contains( $board, 'name="course[103][minutes]" value="0"' ), 'time spent' );
check( str_contains( $board, 'gh-st gh-st--completed">Completed' ) && str_contains( $board, 'gh-st gh-st--notstarted">Incomplete' ), 'course state chips' );
check( 1 === substr_count( $board, 'Uncheck and save to mark incomplete.' ), 'the course help appears once, not once per course' );
check( str_contains( $board, '<code>synthetic-admin</code>' ) && str_contains( $board, 'written to the audit log against your account' ), 'the audit statement names the acting account' );
check( ! str_contains( $board, 'immutable' ) && ! str_contains( $board, 'tamper' ), 'and claims nothing beyond what the plugin does' );
$ids = array();
preg_match_all( '/\bid="([^"]+)"/', $board, $idm );
check( count( $idm[1] ) === count( array_unique( $idm[1] ) ), 'every id in the form is unique' );
preg_match_all( '/\bfor="([^"]+)"/', $board, $form );
check( empty( array_diff( $form[1], $idm[1] ) ), 'every label points at a control that exists' );

// no courses, suspended, hostile values
edit_env();
$e = ghca_env_drawer_employee(); $e['courses'] = array(); $GLOBALS['ghca_env']['employee'] = $e + array( 'orientation_label' => '' );
$h = edit_form( array( 'ghca_ui' => 'board' ) )['data']['html'];
check( str_contains( $h, 'No assigned courses.' ) && ! str_contains( $h, 'data-course-id' ) && ! str_contains( $h, 'Uncheck and save' ), 'no courses' );
edit_env( array( 'suspended' => true ) );
check( str_contains( edit_form( array( 'ghca_ui' => 'board' ) )['data']['html'], 'gh-st--suspended gh-dr-chip">Inactive' ), 'suspended employee' );
edit_env( array( 'verified' => array( 'date' => '2026-10-14', 'source' => '"><script>alert(1)</script>', 'verified_by' => 3, 'verified_at' => 1761900000 ) ) );
$e = ghca_env_drawer_employee(); $e['courses'][0]['title'] = '<img src=x onerror=1>'; $e['group'] = '"><b>x</b>'; $GLOBALS['ghca_env']['employee'] = $e + array( 'orientation_label' => '' );
$h = edit_form( array( 'ghca_ui' => 'board' ) )['data']['html'];
check( ! str_contains( $h, '<script>' ) && ! str_contains( $h, '<img src=x' ) && ! str_contains( $h, '<b>x</b>' ), 'source, course title and group are escaped' );

// ---- The sub-page shell, from the drawer.
edit_env();
$_POST = array( 'user_id' => '42', 'nonce' => 'n', 'ghca_ui' => 'board' );
try { GHCA_ACD_AJAX::ajax_get_employee_drawer(); } catch ( Ghca_Env_Json $e ) { $drawer = $e->payload['data']['html']; }
check( str_contains( $drawer, 'data-ghca-admin-view="subpage" hidden' ), 'the sub-page is inside the Administration panel and hidden' );
foreach ( array( 'data-ghca-subpage-back', 'data-ghca-subpage-head tabindex="-1"', 'id="ghca-acd-edit-subpage-body" aria-busy="false"', 'data-ghca-agency="42"', 'data-ghca-agency-reload', 'data-ghca-agency-status role="status"', 'data-ghca-agency-body', 'data-ghca-audit-period', 'data-ghca-audit-mode', 'data-ghca-audit-dates hidden', 'data-ghca-audit-start required disabled', 'data-ghca-audit-end required disabled', 'data-ghca-packet-mirror data-ghca-board hidden', 'data-ghca-packet-pct', 'data-ghca-packet-track', 'data-ghca-packet-bar', 'data-ghca-packet-label' ) as $hook ) {
	check( str_contains( $drawer, $hook ), "sub-page hook: $hook" );
}
check( 3 === substr_count( $drawer, 'data-ghca-packet-step="' ), 'three packet steps' );
check( 3 === preg_match_all( '/data-ghca-pdf-packet="42" data-tracker="(?:orientation|annual)"/', $drawer ) - 0 || 4 === preg_match_all( '/data-ghca-pdf-packet="42" data-tracker="(?:orientation|annual)"/', $drawer ), 'packet buttons are in the sub-page and in the More menu' );
check( 2 === substr_count( substr( $drawer, (int) strpos( $drawer, 'gh-pk-run' ), 900 ), 'data-ghca-pdf-packet="42"' ), 'the sub-page offers Orientation and Annual' );
check( ! str_contains( substr( $drawer, (int) strpos( $drawer, 'gh-pk-run' ), 900 ), 'oltl_training' ), 'and no OLTL packet unless OLTL applies' );
edit_env( array( 'oltl' => true ) );
$_POST = array( 'user_id' => '42', 'nonce' => 'n', 'ghca_ui' => 'board' );
try { GHCA_ACD_AJAX::ajax_get_employee_drawer(); } catch ( Ghca_Env_Json $e ) { $drawer = $e->payload['data']['html']; }
check( str_contains( substr( $drawer, (int) strpos( $drawer, 'gh-pk-run' ), 1400 ), 'data-tracker="oltl_training"' ), 'OLTL packet appears with OLTL' );
edit_env( array( 'can_docs' => false ) );
$_POST = array( 'user_id' => '42', 'nonce' => 'n', 'ghca_ui' => 'board' );
try { GHCA_ACD_AJAX::ajax_get_employee_drawer(); } catch ( Ghca_Env_Json $e ) { $drawer = $e->payload['data']['html']; }
check( str_contains( $drawer, 'Packet access requires View Employee Documents permission.' ) && ! str_contains( $drawer, 'data-ghca-pdf-packet' ), 'without document access the sub-page says so and offers no packet' );

// ---- Script hooks the new markup relies on.
$js = (string) file_get_contents( __DIR__ . '/../assets/dashboard.js' );
check( str_contains( $js, "form.querySelector('.ghca-acd__edit-btn--save, [data-ghca-edit-save]')" ) && str_contains( $js, "params.append('ghca_ui', 'board')" ), 'the save script finds the new button and the sub-page asks for board markup' );
$agency = (string) file_get_contents( __DIR__ . '/../assets/agency-training.js' );
check( str_contains( $agency, '[data-ghca-tabpanel="administration"]' ), 'the agency-training script finds the administration panel in the redesigned drawer' );

if ( $failed ) {
	fwrite( STDERR, "$failed of $checks checks failed\n" );
	exit( 1 );
}
echo "PASS: $checks edit records checks\n";
