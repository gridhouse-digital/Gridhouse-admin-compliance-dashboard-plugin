<?php
/**
 * Redesigned External training, Audit data and Reports tabs (phase 2, slice 5). Runs the plugin's own
 * renderers against synthetic people and submissions in a stand-in WordPress
 * (tests/fixtures/redesign-admin-env.php). Checks that every form posts the same fields, nonce and
 * decisions as the legacy markup, that every permission gate still applies, that the exports and report
 * links are the legacy URLs, that empty, error and denied states render, and that stored values are shown
 * as stored. Not a browser render; tests/browser-redesign-admin.cjs covers that.
 */
require __DIR__ . '/fixtures/redesign-admin-env.php';
require __DIR__ . '/../includes/class-audit-ui.php';

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
function reset_env( array $over = array(), array $get = array() ): void {
	$GLOBALS['ghca_env'] = array_merge( array( 'logged_in' => true, 'can_view' => true, 'manage_users' => true, 'redesign' => true, 'now' => strtotime( '2026-11-01 12:00:00 UTC' ), 'can_review_ext' => true, 'can_admin' => true, 'can_docs' => true ), $over );
	$_GET  = $get;
	$_POST = array();
	ghca_lists_seed();
}
/** @return array<int,string> Sorted field names inside one HTML fragment. */
function names( string $html ): array {
	preg_match_all( '/\sname="([^"]+)"/', $html, $m );
	$n = array_values( array_unique( $m[1] ) );
	sort( $n );
	return $n;
}
/** @return array<int,string> Each <form>…</form> in order. */
function forms( string $html ): array {
	preg_match_all( '#<form\b.*?</form>#s', $html, $m );
	return $m[0];
}

/* ---------------- External training ---------------- */

reset_env();
$board  = GHCA_ACD_Jotform_UI::render_review_board();
$legacy = GHCA_ACD_Jotform_UI::render_frontend_review();
$bf     = forms( $board );
$lf     = forms( $legacy );
check( 3 === substr_count( $board, 'class="gh-card gh-ext-card"' ), 'one card per pending submission' );
check( count( $bf ) === count( $lf ) && 7 === count( $bf ), 'same number of forms as the legacy review (3 decisions, 2 orientation, 2 revoke): ' . count( $bf ) . ' vs ' . count( $lf ) );
foreach ( $lf as $i => $form ) {
	check( names( $form ) === names( $bf[ $i ] ), "form $i posts the same field names as legacy: " . implode( ',', names( $bf[ $i ] ) ) . ' vs ' . implode( ',', names( $form ) ) );
	preg_match_all( '/name="decision" value="([a-z]+)"/', $form, $ld );
	preg_match_all( '/name="decision" value="([a-z]+)"/', $bf[ $i ], $bd );
	sort( $ld[1] );
	sort( $bd[1] );
	check( $ld[1] === $bd[1], "form $i offers the same decisions: " . implode( ',', $bd[1] ) );
	check( str_contains( $bf[ $i ], 'action="https://example.test/wp-admin/admin-post.php"' ) && str_contains( $bf[ $i ], 'name="action" value="ghca_acd_jotform_training_action"' ) && str_contains( $bf[ $i ], 'value="nonce-ghca_acd_jotform_admin"' ), "form $i posts to the same handler with the same nonce" );
	check( str_contains( $bf[ $i ], 'name="frontend_review" value="1"' ) && str_contains( $bf[ $i ], 'ghca_tab=external-training' ), "form $i returns to the dashboard tab" );
}
check( 3 === substr_count( $board, 'name="completion_date" value="' ) && str_contains( $board, 'type="date" name="completion_date" value="2026-10-20" required' ), 'completion date: native date field, stored Y-m-d value, still required' );
check( 3 === substr_count( $board, 'name="employee_confirmed" value="1" required' ) && 3 === substr_count( $board, 'name="catalog_id" required data-ghca-catalog-select data-match-catalog-id=' ), 'confirmation and lesson still required; override hook kept for jotform.js' );
check( str_contains( $board, 'data-match-catalog-id="72"' ) && str_contains( $board, 'data-match-catalog-id="0"' ), 'matched lesson id from the existing catalog match' );
check( 3 === substr_count( $board, 'data-ghca-override-reason' ) && 3 === substr_count( $board, 'hidden role="alert" data-ghca-catalog-warning' ), 'override reason and mismatch warning hooks kept' );
check( 3 === substr_count( $board, 'value="reject" formnovalidate' ) && 3 === substr_count( $board, 'value="preflight" formnovalidate' ), 'reject and preflight skip validation, as before' );
check( 6 === substr_count( $board, 'data-ghca-document-open="9' ) && 3 === substr_count( $board, 'data-action="preview"' ) && 3 === substr_count( $board, 'data-action="download"' ), 'evidence preview and download use the existing document broker hooks (3 files)' );
check( str_contains( $board, 'First aid card &lt;scan&gt;' ) && ! str_contains( $board, '<scan>' ) && str_contains( $board, 'Priya Nair &lt;RN&gt;' ), 'submitted text and names are escaped' );
check( str_contains( $board, 'Signed ownership claim missing; reviewer binding required.' ) && str_contains( $board, 'Signed claim not found.' ), 'warning label and detail shown' );
check( str_contains( $board, 'med-admin.pdf · 340 KB · SHA-256 abababababab…' ) && str_contains( $board, 'Paper certificate from previous employer.' ), 'manual entry: manifest and reason shown, no open button' );
check( str_contains( $board, 'Not matched to an employee' ) && str_contains( $board, 'name="employee_id" value="0" required' ), 'unresolved submitter shown and still needs an employee id' );
check( ! str_contains( $board, 'disabled="disabled">' . GHCA_ODP_UI::icon( 'check' ) ) && ! str_contains( $board, 'Approvals are paused' ), 'approve enabled when the evidence store is ready' );
check( str_contains( $board, 'aria-current="page" aria-selected="true">All pending <span class="gh-count gh-count--warning">3</span>' ), 'filter shows the pending count' );
check( str_contains( $board, 'Also orientation' ) && 2 === substr_count( $board, 'gh-st--approved' ), 'approved table: status and orientation flag from the stored snapshot' );
check( 2 === substr_count( $board, 'name="revision_id" value="4' ) && 2 === substr_count( $board, 'name="orientation_reason" maxlength="1000" required' ) && 2 === substr_count( $board, 'required maxlength="1000" name="reason"' ), 'orientation and revoke forms keep their required reasons and revision ids' );
check( ! str_contains( $board, 'bulk_reject_unsigned' ) && ! str_contains( $legacy, 'bulk_reject_unsigned' ), 'bulk reject stays on the admin screen only, as before' );

reset_env( array(), array( 'review_filter' => 'unsigned' ) );
$u = GHCA_ACD_Jotform_UI::render_review_board();
check( 1 === substr_count( $u, 'gh-ext-card' ) && str_contains( $u, 'Unsigned only <span class="gh-count gh-count--warning">1</span>' ), 'unsigned filter: only unsigned submissions' );

reset_env( array( 'ext_ready' => 'Encryption key is not configured.' ) );
$r = GHCA_ACD_Jotform_UI::render_review_board();
check( str_contains( $r, 'Approvals are paused' ) && str_contains( $r, 'Encryption key is not configured.' ) && 3 === substr_count( $r, 'value="approve" disabled="disabled"' ), 'evidence store not ready: alert, approve disabled (as legacy)' );

reset_env( array( 'ext_pending' => 'none' ) );
$e = GHCA_ACD_Jotform_UI::render_review_board();
check( str_contains( $e, 'Nothing waiting for review' ) && str_contains( $e, 'No approved outside training yet.' ) && ! str_contains( $e, '<form' ), 'empty state: no submissions, no approved evidence' );

reset_env( array(), array( 'ghca_review_notice' => 'training_approved' ) );
check( str_contains( GHCA_ACD_Jotform_UI::render_review_board(), 'role="status"><span class="gh-i i-check-circle" aria-hidden="true"></span><div class="gh-alert-body"><p>Training approved</p>' ), 'success flash after a decision' );
reset_env( array(), array( 'ghca_review_notice' => 'approval_failed' ) );
check( str_contains( GHCA_ACD_Jotform_UI::render_review_board(), 'gh-alert--danger" role="alert"' ), 'failure flash is an alert' );

reset_env( array( 'can_review_ext' => false ) );
check( '' === GHCA_ACD_Jotform_UI::render_review_board(), 'no review permission: nothing rendered (tab is not offered either)' );

/* ---------------- Audit data ---------------- */

reset_env();
$a  = GHCA_ACD_Redesign_Admin::render_audit();
$la = GHCA_ACD_Audit_UI::render();
preg_match_all( '/data-ghca-audit-row="(\d+)"/', $a, $ids );
preg_match_all( '/data-user-id="(\d+)"/', $la, $lids );
check( $ids[1] === $lids[1] && 5 === count( $ids[1] ), 'same employees in the same order as the legacy audit tab' );
check( str_contains( $a, 'action=ghca_acd_audit_export_csv&amp;tracker=annual&amp;_wpnonce=test' ) && str_contains( $a, 'action=ghca_acd_audit_export_csv&amp;tracker=orientation&amp;_wpnonce=test' ), 'both CSV exports use the legacy URLs and nonce' );
check( str_contains( $a, 'data-ghca-audit-nonce="nonce"' ) && 5 === substr_count( $a, 'data-ghca-audit-save' ), 'save carries the audit nonce, one Save per row' );
check( str_contains( $a, 'id="ghca-aud-first_service-301"' ) && str_contains( $a, 'value="06/09/2026"' ) && str_contains( $a, 'value="07/20/2026"' ), 'stored MM/DD/YYYY values shown as stored' );
check( 10 === substr_count( $a, 'pattern="(0[1-9]|1[0-2])/(0[1-9]|[12][0-9]|3[01])/[0-9]{4}"' ) && 10 === substr_count( $a, 'placeholder="MM/DD/YYYY"' ), 'date fields keep the MM/DD/YYYY text format (not a native picker)' );
check( 1 === substr_count( $a, 'data-ghca-audit-field="exclude" checked' ), 'exclusion shown as stored' );
check( 2 === substr_count( $a, '>Not verified</span>' ) && str_contains( $a, '06/02/2026' ), 'verified start from the employment record; missing ones flagged' );
check( 10 === substr_count( $a, 'data-ghca-pdf-packet=' ) && str_contains( $a, 'data-tracker="orientation"' ) && str_contains( $a, 'data-tracker="annual"' ), 'packet buttons keep the existing packet hooks' );
check( str_contains( $a, 'Priya Nair &lt;RN&gt;' ) && ! str_contains( $a, 'Nair <RN>' ), 'names escaped' );
check( str_contains( $a, 'for="ghca-aud-worked_alone-302">Worked-alone date for Jordan Ellis</label>' ), 'every date field has its own label' );

reset_env( array( 'can_admin' => false ) );
$ro = GHCA_ACD_Redesign_Admin::render_audit();
check( ! str_contains( $ro, '<input' ) && ! str_contains( $ro, 'data-ghca-audit-nonce' ) && ! str_contains( $ro, 'data-ghca-audit-save' ) && str_contains( $ro, 'Changing them needs permission to edit employee records.' ) && str_contains( $ro, '06/09/2026' ), 'no edit permission: read-only values, no save, explained' );
reset_env( array( 'can_docs' => false ) );
check( ! str_contains( GHCA_ACD_Redesign_Admin::render_audit(), 'data-ghca-pdf-packet' ), 'no document permission: no packet buttons (as legacy)' );
reset_env( array( 'audit_people' => 'none' ) );
check( str_contains( GHCA_ACD_Redesign_Admin::render_audit(), 'No employees in your view' ), 'empty state' );
reset_env( array( 'can_view' => false ) );
check( str_contains( GHCA_ACD_Redesign_Admin::render_audit(), 'You do not have access to this section.' ), 'not allowed to view: denied state, no data' );

/* ---------------- Reports ---------------- */

reset_env();
$rp = GHCA_ACD_Redesign_Admin::render_reports();
$lr = GHCA_ACD_Shortcodes::render_reports();
preg_match_all( '/href="([^"]+)"/', $rp, $bh );
preg_match_all( '/href="([^"]+)"/', $lr, $lh );
// Slice 9: Certificate records opens the dashboard's own Certificates tab instead of /cert-download/; the other
// three destinations are the legacy tab's, in the same order.
$legacy_cert = array_values( array_filter( $lh[1], static function ( $u ) { return false !== strpos( $u, 'cert-download' ); } ) );
$expected    = array_map( static function ( $u ) { return false !== strpos( $u, 'cert-download' ) ? GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-tab-certificates' : $u; }, $lh[1] );
check( 4 === count( $bh[1] ) && 1 === count( $legacy_cert ) && $bh[1] === $expected, 'the same four destinations in the same order; Certificate records now opens the dashboard Certificates tab (not /cert-download/)' . ( $bh[1] === $expected ? '' : ' ' . json_encode( array( $bh[1], $expected ) ) ) );
check( false === strpos( $rp, 'cert-download' ) && false !== strpos( $rp, 'data-ghca-tab-jump="ghca-tab-certificates" data-ghca-cert-user=""' ), 'View certificates is a general entry: it jumps to the tab and clears any employee filter' );
check( str_contains( $rp, '6 employees in your view' ) && str_contains( $rp, '5 issued · 1 missing' ) && str_contains( $rp, '6 active · 0 inactive' ), 'meta lines are aggregate counts the dashboard already shows' );
check( ! preg_match( '/Last run|expire|Avg quiz|Tin Can|xAPI/', $rp ), 'no invented report types or metrics' );
check( 4 === substr_count( $rp, '<span class="gh-sr">: ' ), 'each button names its report for screen readers' );
reset_env( array( 'manage_users' => false ) );
$rm = GHCA_ACD_Redesign_Admin::render_reports();
check( ! str_contains( $rm, 'inactive' ) && 4 === substr_count( $rm, 'gh-rpt-card' ), 'inactive count only for people who can manage users; the card itself stays (as legacy)' );
reset_env( array( 'can_view' => false ) );
check( str_contains( GHCA_ACD_Redesign_Admin::render_reports(), 'You do not have access to this section.' ) && '' === GHCA_ACD_Shortcodes::render_reports(), 'not allowed: denied state (legacy printed nothing)' );

/* ---------------- Scoping of the stylesheet additions ---------------- */

$tail = file_get_contents( __DIR__ . '/../design-references/gh-redesign.tail.css' );
$slice = substr( $tail, strpos( $tail, '/* Slice 5:' ) );
$plain = preg_replace( '#/\*.*?\*/#s', '', str_replace( ':is(.ghca-ui, #ghca-ui-scope)', 'SCOPE', $slice ) );
preg_match_all( '/([^{}]+)\{/', $plain, $sel );
$bad = array();
foreach ( $sel[1] as $s ) {
	if ( str_starts_with( trim( $s ), '@media' ) ) {
		continue;
	}
	foreach ( explode( ',', $s ) as $part ) {
		if ( ! str_starts_with( trim( $part ), 'SCOPE ' ) ) {
			$bad[] = trim( $part );
		}
	}
}
check( ! $bad, 'every slice-5 rule is scoped to the redesign: ' . implode( ' | ', $bad ) );
check( ! preg_match( '/#[0-9a-fA-F]{3,8}\b|rgb\(/', $slice ), 'slice-5 rules use tokens, no colour literals' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed of $checks redesigned admin-tab checks\n" );
	exit( 1 );
}
echo "PASS: $checks redesigned admin-tab checks\n";
