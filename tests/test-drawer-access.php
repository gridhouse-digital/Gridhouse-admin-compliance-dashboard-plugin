<?php
/**
 * Administration access in the employee drawer (slice 6 investigation). Runs the plugin's real drawer, edit-form,
 * mark-reviewed and save handlers in a stand-in WordPress (tests/fixtures/redesign-drawer-env.php) for a
 * permitted administrator and for denied users, in both the redesigned and the legacy markup.
 *
 * What it pins down: the Administration tab and every employment value appear exactly when
 * GHCA_ACD_Roles::user_can_edit_records() passes and the employee is in scope, on every path; the "restricted to
 * authorized personnel" wording was fixed copy, printed beside the data for people who had access, never a
 * check; and the redesigned copy now says who can see the records instead.
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
function drawer( array $env, bool $board ): array {
	ghca_env_reset( $env );
	return ghca_env_drawer( $board ? array( 'ghca_ui' => 'board' ) : array() );
}
function call( string $method, array $env, array $post = array() ): array {
	ghca_env_reset( $env );
	$_POST = array_merge( array( 'user_id' => '42', 'nonce' => 'n', 'ghca_ui' => 'board' ), $post );
	try {
		GHCA_ACD_AJAX::$method();
	} catch ( Ghca_Env_Json $e ) {
		return array( 'status' => $e->status, 'payload' => $e->payload );
	}
	return array( 'status' => 0, 'payload' => array() );
}
// Employment values the Administration tab shows; none may appear without access.
$restricted = array( 'Verified start', 'Employment type', 'First service', 'Registration', 'Account registered', 'data-ghca-edit-records', 'data-ghca-mark-reviewed', 'Administrative Overview' );

foreach ( array( true, false ) as $board ) {
	$v = $board ? 'redesigned' : 'legacy';

	// Permitted administrator: the tab and its data, every time.
	$a = drawer( array( 'can_admin' => true ), $board );
	$h = (string) ( $a['payload']['data']['html'] ?? '' );
	check( true === $a['payload']['success'] && str_contains( $h, 'data-ghca-tab="administration"' ) && str_contains( $h, 'Administrative Overview' ), "$v: an administrator gets the Administration tab with its data" );
	$again = drawer( array( 'can_admin' => true ), $board );
	check( $h === (string) $again['payload']['data']['html'], "$v: the same administrator and employee get byte-identical markup on a second request (no request-dependent variation)" );

	// Denied: no edit-records permission.
	$d = drawer( array( 'can_admin' => false ), $board );
	$dh = (string) ( $d['payload']['data']['html'] ?? '' );
	check( true === $d['payload']['success'] && ! str_contains( $dh, 'data-ghca-tab="administration"' ) && ! str_contains( $dh, 'ghca-acd-drawer-panel-administration' ), "$v: without edit-records permission there is no Administration tab or panel" );
	$leak = array_filter( $restricted, static function ( $s ) use ( $dh ) { return str_contains( $dh, $s ); } );
	check( ! $leak, "$v: no employment value or Administration action reaches a denied user: " . implode( ', ', $leak ) );
	check( ! str_contains( $dh, 'restricted to authorized personnel' ) && ! str_contains( $dh, 'Restricted to authorized personnel' ), "$v: a denied user is never shown the confidentiality wording either; denial is the tab's absence" );

	// Out of scope: refused before anything is built.
	$o = drawer( array( 'can_view_user' => false ), $board );
	check( false === $o['payload']['success'] && 'Invalid employee or permission denied.' === $o['payload']['data']['message'], "$v: an employee outside scope is refused by the server" );
}

// The two wordings, traced.
$legacy = (string) drawer( array( 'can_admin' => true ), false )['payload']['data']['html'];
check( str_contains( $legacy, 'This information is restricted to authorized personnel.' ), 'legacy drawer (used where the redesign wrapper is not rendered): its fixed "restricted to authorized personnel" sentence is unchanged and shown only with access' );
$board = (string) drawer( array( 'can_admin' => true ), true )['payload']['data']['html'];
check( ! str_contains( $board, 'Restricted to authorized personnel' ) && ! str_contains( $board, 'restricted to authorized personnel' ) && str_contains( $board, 'Confidential: shown only to people with permission to edit employee records.' ), 'redesigned drawer: the ambiguous notice is replaced by copy that says who can see the records' );

// Every Administration action applies the same two checks as the tab.
foreach ( array( 'ajax_get_edit_records_form', 'ajax_mark_reviewed', 'ajax_save_employee_records' ) as $m ) {
	$r = call( $m, array( 'can_admin' => false ) );
	check( false === $r['payload']['success'] && 'Permission denied.' === $r['payload']['data']['message'], "$m: refused without edit-records permission" );
	$r = call( $m, array( 'can_admin' => true, 'can_view_user' => false ) );
	check( false === $r['payload']['success'] && 'Invalid employee or permission denied.' === $r['payload']['data']['message'], "$m: refused for an employee outside scope, even for a permitted user" );
}
$r = call( 'ajax_get_edit_records_form', array( 'can_admin' => true ) );
check( true === $r['payload']['success'] && str_contains( (string) $r['payload']['data']['html'], 'data-user-id="42"' ), 'the edit form names the employee it was rendered for; the script saves to that id' );

// Stale nonce: refused before any check runs (the script now reports an expired session, not a network error).
ghca_env_reset( array( 'bad_nonce' => true ) );
$r = ghca_env_drawer( array( 'ghca_ui' => 'board' ) );
check( false === ( $r['payload']['success'] ?? null ) || 403 === $r['status'] || -1 === ( $r['payload'] ?? null ), 'a stale nonce is refused by the server' );

// The script side of the fix.
$js = (string) file_get_contents( __DIR__ . '/../assets/dashboard.js' );
check( str_contains( $js, "params.append('user_id', formUserId);" ) && str_contains( $js, "form.getAttribute('data-user-id')" ), 'Edit Records saves to the employee the form was rendered for' );
check( str_contains( $js, 'window.ghcaAcdEditAbort' ) && 2 <= substr_count( $js, "window.ghcaAcdEditAbort(); }" ), 'opening or closing the drawer cancels an Edit Records load still in flight' );
check( str_contains( $js, 'Your session has expired or this page is out of date.' ), 'an expired nonce or session is reported as such' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed of $checks drawer access checks\n" );
	exit( 1 );
}
echo "PASS: $checks drawer access checks\n";
