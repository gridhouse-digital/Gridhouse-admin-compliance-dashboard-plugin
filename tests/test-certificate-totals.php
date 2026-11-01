<?php
// GRI-95: Certificates & Records totals and the tracked-employee rule. Synthetic; no WordPress or LearnDash.
require __DIR__ . '/bootstrap.php';

$S = array( 'users' => array(), 'meta' => array(), 'filters' => array() );
function __( $t, $d = '' ) { return $t; }
function apply_filters( $hook, $value, ...$args ) {
	$f = $GLOBALS['S']['filters'][ $hook ] ?? null;
	return $f ? $f( $value, ...$args ) : $value;
}
function get_userdata( $id ) { return $GLOBALS['S']['users'][ (int) $id ] ?? false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['S']['meta'][ (int) $id ][ $key ] ?? ''; }
function current_user_can( $c ) { return false; }
function wp_get_current_user() { return (object) array( 'roles' => array() ); }
final class GHCA_ACD_Settings {
	const OPTION_PERM_EDIT_RECORDS = 'a'; const OPTION_PERM_MANAGE_ANNOUNCEMENTS = 'b'; const OPTION_PERM_MANAGE_USERS = 'c';
	const OPTION_PERM_UNRESTRICTED_VIEW = 'd'; const OPTION_PERM_SEND_REMINDERS = 'e'; const OPTION_PERM_VIEW_COMMUNICATIONS = 'f';
	const OPTION_PERM_MANAGE_TEMPLATES = 'g'; const OPTION_PERM_VIEW_DOCUMENTS = 'h';
}
require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-data-provider.php';

$fails = 0;
function tc_check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }
function user( int $id, array $roles ): void { $GLOBALS['S']['users'][ $id ] = (object) array( 'ID' => $id, 'roles' => $roles ); }
function course( bool $has_cert, bool $completed, string $url = '' ): array { return array( 'has_certificate' => $has_cert, 'completed' => $completed, 'certificate_url' => $url ); }

// --- Totals are per employee+course pair ---------------------------------------------------
$cert = 'https://example.test/certificates/c/?course_id=1';
$assigned_only = array( array( 'courses' => array( course( true, false ), course( true, false ), course( true, false ) ) ) );
$t = GHCA_ACD_Data_Provider::summarize_certificates( $assigned_only );
tc_check( 0 === $t['issued'] && 3 === $t['assigned'], 'assigned-but-not-completed courses are never counted as issued (0 issued, 3 assigned)' );

$one = array( array( 'courses' => array( course( true, true, $cert ), course( true, false ), course( false, true ) ) ) );
$t = GHCA_ACD_Data_Provider::summarize_certificates( $one );
tc_check( 1 === $t['issued'] && 2 === $t['assigned'] && 0 === $t['missing'], 'a completed course with a certificate is counted once (1 issued of 2 assigned); a completed course without a certificate course is not' );

$missing = array( array( 'courses' => array( course( true, true, '' ) ) ) );
$t = GHCA_ACD_Data_Provider::summarize_certificates( $missing );
tc_check( 0 === $t['issued'] && 1 === $t['missing'] && 1 === $t['eligible'], 'a completed course whose certificate link is missing is Missing/Pending, not Issued' );

$two_staff = array(
	array( 'courses' => array( course( true, true, $cert ), course( true, true, $cert ) ) ),
	array( 'courses' => array( course( true, true, $cert ) ) ),
);
tc_check( 3 === GHCA_ACD_Data_Provider::summarize_certificates( $two_staff )['issued'], 'issued counts every employee+course pair, not one per employee' );

// The live-site shape: 75 certificate courses assigned across staff, one certificate issued.
$live = array( array( 'courses' => array_merge( array( course( true, true, $cert ) ), array_fill( 0, 74, course( true, false ) ) ) ) );
$t = GHCA_ACD_Data_Provider::summarize_certificates( $live );
tc_check( 1 === $t['issued'] && 75 === $t['assigned'], 'live shape: 75 assigned, 1 issued' );

// --- Tracked-employee rule -----------------------------------------------------------------
user( 1, array( 'administrator' ) );
user( 2, array( 'compliance_lead', 'nurse' ) );
user( 3, array( 'caregiver' ) );
user( 4, array( 'compliance_lead' ) );
user( 5, array( 'administrator', 'caregiver' ) );
user( 6, array( 'subscriber' ) );
user( 7, array() );
user( 8, array( 'hr_manager', 'group_leader' ) );
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 1 ), 'an administrator is excluded' );
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 2 ), 'a combined-role employee (compliance_lead + nurse) is still included' );
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 3 ), 'a plain employee role is included' );
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 4 ), 'a management-only compliance lead is excluded' );
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 5 ), 'an administrator who also holds an employee role is included' );
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 6 ), 'a subscriber (not a management role) is included' );
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 7 ), 'a user with no role is excluded' );
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 8 ), 'multiple management roles only: excluded' );
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 99 ), 'an unknown user id is excluded' );

// The list the dashboard builds from, and the totals computed from it, agree.
$ids      = array( 1, 2, 3, 4, 5, 6, 7 );
$kept     = array_values( array_filter( $ids, array( 'GHCA_ACD_Data_Provider', 'is_tracked_employee' ) ) );
tc_check( array( 2, 3, 5, 6 ) === $kept, 'admins and management-only accounts are removed from the id list before records (and totals) are built' );
$by_user  = array( 1 => 18, 2 => 2, 3 => 1, 5 => 0, 6 => 0 );
$records  = array();
foreach ( $ids as $id ) { $records[ $id ] = array( 'user_id' => $id, 'courses' => array_fill( 0, $by_user[ $id ] ?? 0, course( true, false ) ) ); }
$all      = GHCA_ACD_Data_Provider::summarize_certificates( $records );
$staff    = GHCA_ACD_Data_Provider::summarize_certificates( array_intersect_key( $records, array_flip( $kept ) ) );
tc_check( 21 === $all['assigned'] && 3 === $staff['assigned'], 'an administrator with 18 certificate courses inflates the total (21) unless excluded (3)' );

// --- Optional test-account exclusion: off by default, filterable -----------------------------
$S['meta'][3]['ghca_acd_test_account'] = '1';
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 3 ), 'test-account flag does nothing by default' );
$S['filters']['ghca_acd_exclude_test_accounts'] = static function () { return true; };
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 3 ), 'flagged test account is excluded once the filter enables it' );
tc_check( GHCA_ACD_Data_Provider::is_tracked_employee( 6 ), 'unflagged staff stay included when the test-account filter is on' );
unset( $S['filters']['ghca_acd_exclude_test_accounts'] );
$S['filters']['ghca_acd_is_tracked_employee'] = static function ( $tracked, $uid ) { return 6 === $uid ? false : $tracked; };
tc_check( ! GHCA_ACD_Data_Provider::is_tracked_employee( 6 ) && GHCA_ACD_Data_Provider::is_tracked_employee( 2 ), 'ghca_acd_is_tracked_employee can override per user' );
unset( $S['filters']['ghca_acd_is_tracked_employee'] );

// --- Panel and card read the same figure; cache is versioned -------------------------------
$provider   = file_get_contents( __DIR__ . '/../includes/class-data-provider.php' );
$shortcodes = file_get_contents( __DIR__ . '/../includes/class-shortcodes.php' );
tc_check( false !== strpos( $provider, "'certificates_issued'          => \$certificates," ) && false !== strpos( $provider, "\$certificates   = \$cert_totals['issued'];" ), 'aggregate certificates_issued is the per-course issued figure' );
tc_check( false !== strpos( $provider, "'certificates_assigned'        => \$cert_available," ), 'assigned figure is exposed under its own honest key' );
tc_check( 1 === preg_match( "/'value' => \(int\) \( \\\$data\['certificates_issued'\] \?\? 0 \),\s*'label' => __\( 'Issued'/", $shortcodes ), 'the Certificates & Records panel "Issued" reads certificates_issued' );
tc_check( 1 === preg_match( "/'label' => __\( 'Certificates Issued'.*?'value' => \\\$data\['certificates_issued'\]/s", $shortcodes ), 'the overview "Certificates Issued" card reads the same key' );
tc_check( false === strpos( $shortcodes, "'value' => (int) ( \$data['certificates_available']" ), 'the panel no longer shows the assigned figure as Issued' );
tc_check( false !== strpos( $shortcodes, 'certificate courses assigned' ) && false === strpos( $shortcodes, "'sub'   => __( 'All time'" ), 'assigned is labelled honestly; the misleading "All time" subtitle is gone' );
tc_check( 1 === preg_match( "/return 'ghca_acd_agg_' \. GHCA_Admin_Compliance_Dashboard::VERSION/", $provider ), 'the cached aggregate key includes the plugin version, so the rc.6 bump drops stale cached totals' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
