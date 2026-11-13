<?php
/**
 * Real WordPress + MySQL/MariaDB checks of the training-year rules (2.0): the dry run is read-only and lists the
 * right changes (due date, annual result, missing hire date, missing requirement), the switch needs the dry-run
 * confirmation, the annual requirement saves through Edit Records, and both drawers show the training year once the
 * rules are on. Synthetic users only; every option and user it touches is restored or removed. Nothing is sent.
 *
 * Runs only on a disposable site (GHCA_DISPOSABLE_TEST_SITE, database ghca_test*) prepared by tests/real-site/setup.php.
 */
$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
	echo "SKIP: needs a WordPress install\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_ACD_Training_Year_Review' ) || ! function_exists( 'ghca_rs_groups' ) ) {
	echo "SKIP: not a prepared disposable ghca_test site\n";
	exit( 0 );
}
add_filter( 'pre_wp_mail', '__return_false' );
$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	fwrite( STDOUT, ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n" );
	if ( ! $ok ) { ++$failed; }
}
final class Ghca_TY_Stop extends Exception {}
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () { return static function ( $message = '' ) { throw new Ghca_TY_Stop( is_string( $message ) ? $message : '' ); }; } );
add_filter( 'wp_die_handler', static function () { return static function ( $message ) { throw new Ghca_TY_Stop( is_string( $message ) ? $message : 'die' ); }; } );
add_filter( 'wp_redirect', static function ( $location ) { throw new Ghca_TY_Stop( 'redirect:' . $location ); } );
function ajax( callable $handler, array $request ): array {
	$_POST = $_REQUEST = $request + array( 'nonce' => wp_create_nonce( 'ghca_acd_table' ) );
	ob_start();
	try { $handler(); } catch ( Ghca_TY_Stop $e ) {} // phpcs:ignore
	return (array) json_decode( (string) ob_get_clean(), true );
}
/** Everything the plugin could write: every option row (transients included, so the dashboard aggregate counts) and user meta. */
function durable_state(): string {
	global $wpdb;
	$o = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_N );
	$m = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_N );
	return hash( 'sha256', wp_json_encode( array( $o, $m ) ) );
}
/** Option names added or changed between two snapshots. */
function option_names(): array {
	global $wpdb;
	return $wpdb->get_results( "SELECT option_name, MD5(option_value) FROM {$wpdb->options}", OBJECT_K );
}

$admin = get_user_by( 'login', 'synthetic-admin' );
wp_set_current_user( $admin->ID );
$saved_options = array();
foreach ( array( 'ghca_acd_annual_cycle', 'ghca_acd_audit_mapping', GHCA_ACD_Training_Year::OPTION_ENABLED, GHCA_ACD_Training_Year::OPTION_ENABLED_BY, GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR, GHCA_ACD_Training_Year::OPTION_ROLE_MAP ) as $name ) {
	$saved_options[ $name ] = get_option( $name, null );
}
// A tracked group the existing synthetic employee is in, so the new people appear on the dashboard.
$employee_group = (array) ghca_rs_groups( (int) get_user_by( 'login', 'synthetic-employee' )->ID );
$make = static function ( string $login, string $first, string $last ) use ( $employee_group ): WP_User {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$user = get_user_by( 'id', wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => $first, 'last_name' => $last, 'display_name' => $first . ' ' . $last ) ) );
	}
	update_user_meta( $user->ID, 'ghca_rs_groups', $employee_group );
	return $user;
};
$a = $make( 'synthetic-ty-anniversary', 'Synthetic', 'Anniversary' );
$b = $make( 'synthetic-ty-nohire', 'Synthetic', 'Nohire' );
$c = $make( 'synthetic-ty-twelve', '=Synthetic', 'Twelve' ); // leading "=" also checks CSV formula neutralising
$today = new DateTimeImmutable( 'today', wp_timezone() );
$a_hire = $today->modify( '-200 days' )->modify( '-1 year' );
$c_hire = $today->modify( '-100 days' );
update_user_meta( $a->ID, GHCA_ACD_Employment_Record::META_KEY, array( 'date' => $a_hire->format( 'Y-m-d' ), 'source' => 'Synthetic HR reference', 'verified_by' => $admin->ID, 'verified_at' => time() ) );
update_user_meta( $c->ID, GHCA_ACD_Employment_Record::META_KEY, array( 'date' => $c_hire->format( 'Y-m-d' ), 'source' => 'Synthetic HR reference', 'verified_by' => $admin->ID, 'verified_at' => time() ) );
update_user_meta( $c->ID, GHCA_ACD_Training_Year::REQUIREMENT_META, 'management' );
update_option( 'ghca_acd_annual_cycle', 'employee_start_date' );
update_option( 'ghca_acd_audit_mapping', array( 990001 => array( 'odp_category' => 'person_centered', 'odp_categories' => array( 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports', 'individual_plan' ), 'credit_hours' => 13 ) ) );
update_user_meta( $c->ID, 'course_completed_990001', time() - DAY_IN_SECONDS );
delete_option( GHCA_ACD_Training_Year::OPTION_ENABLED );
// Job roles for the role map (synthetic; removed at the end).
add_role( 'synthetic_ty_office', 'Synthetic Office Administrator', array( 'read' => true ) );
add_role( 'synthetic_ty_dsp', 'Synthetic Direct Service Professional', array( 'read' => true ) );
add_role( 'synthetic_ty_lpn', 'Synthetic LPN', array( 'read' => true ) );
$d = $make( 'synthetic-ty-multi', 'Synthetic', 'Multi' );
$d->set_role( 'synthetic_ty_office' ); $d->add_role( 'synthetic_ty_dsp' );
$e = $make( 'synthetic-ty-office', 'Synthetic', 'Office' );
$e->set_role( 'synthetic_ty_office' );
$f = $make( 'synthetic-ty-nurse', 'Synthetic', 'Nurse' );
$f->set_role( 'synthetic_ty_lpn' );
delete_option( GHCA_ACD_Training_Year::OPTION_ROLE_MAP );
GHCA_ACD_Data_Provider::bust_cache();
GHCA_ACD_Training_Year_Review::cached() && delete_transient( 'ghca_acd_ty_dryrun_' . $admin->ID );

// ---- Role map: settable while the switch is off; administrators only; only known values.
require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button(), loaded on real admin pages
$post_map = static function ( array $map ): string {
	$_POST = $_REQUEST = array( 'action' => GHCA_ACD_Training_Year_Review::ACTION_MAP, '_wpnonce' => wp_create_nonce( GHCA_ACD_Training_Year_Review::ACTION_MAP ), 'role_map' => $map );
	ob_start();
	try { GHCA_ACD_Training_Year_Review::handle_role_map(); } catch ( Ghca_TY_Stop $x ) { ob_end_clean(); return $x->getMessage(); }
	return (string) ob_get_clean();
};
$out = $post_map( array( 'synthetic_ty_office' => '12', 'synthetic_ty_dsp' => '24', 'subscriber' => 'none', 'not_a_role' => '24' ) );
check( 0 === strpos( $out, 'redirect:' ) && array( 'synthetic_ty_office' => '12', 'synthetic_ty_dsp' => '24' ) === get_option( GHCA_ACD_Training_Year::OPTION_ROLE_MAP ) && ! GHCA_ACD_Training_Year::enabled(), 'role map saves 12 h and 24 h while the switch is off; "none" without an exception and unknown roles are dropped' );
check( 'role_map' === GHCA_ACD_Training_Year::requirement( $d->ID )['source'] && 24 === GHCA_ACD_Training_Year::requirement( $d->ID )['hours'], 'real user with a 12 h role and a 24 h role: 24 h (highest wins), source role_map' );
check( 12 === GHCA_ACD_Training_Year::requirement( $e->ID )['hours'] && 'role_map' === GHCA_ACD_Training_Year::requirement( $e->ID )['source'], 'real user with a 12 h role: 12 h from the role map' );
check( 24 === GHCA_ACD_Training_Year::requirement( $f->ID )['hours'] && 'role_map' === GHCA_ACD_Training_Year::requirement( $f->ID )['source'] && false !== strpos( GHCA_ACD_Training_Year::requirement( $f->ID )['label'], 'agency policy' ) && ! isset( get_option( GHCA_ACD_Training_Year::OPTION_ROLE_MAP )['synthetic_ty_lpn'] ), 'nurse role (LPN): 24 h as agency policy, not auto-saved into the role map' );
$q = GHCA_ACD_Training_Year::requirement( $a->ID );
check( 24 === $q['hours'] && false === $q['set'] && 'default' === $q['source'] && 'Role requirement not set' === $q['label'], 'unmapped role (subscriber): 24 h, "Role requirement not set", source default' );
wp_set_current_user( get_user_by( 'login', 'synthetic-viewer' ) ? get_user_by( 'login', 'synthetic-viewer' )->ID : 0 );
$out = $post_map( array( 'synthetic_ty_office' => '24' ) );
wp_set_current_user( $admin->ID );
check( '12' === get_option( GHCA_ACD_Training_Year::OPTION_ROLE_MAP )['synthetic_ty_office'], 'a non-administrator cannot change the role map' );
GHCA_ACD_Data_Provider::bust_cache();
delete_transient( 'ghca_acd_ty_dryrun_' . $admin->ID );

// ---- Dry run: read-only.
$before = durable_state();
$report = GHCA_ACD_Training_Year_Review::build( GHCA_ACD_Training_Year_Review::employee_ids() );
check( durable_state() === $before, 'the dry run writes nothing: every option row (including the dashboard aggregate transient) and all user meta byte-identical before and after' );
check( false === get_transient( 'ghca_acd_ty_dryrun_' . $admin->ID ), 'the comparison itself does not fill the dry-run cache either' );
check( false === GHCA_ACD_Training_Year::enabled(), 'the dry run leaves the rules off' );
$rows = array();
foreach ( $report['rows'] as $row ) { $rows[ $row['user_id'] ] = $row; }
check( isset( $rows[ $a->ID ], $rows[ $b->ID ], $rows[ $c->ID ] ), 'every dashboard employee is in the report, including the three synthetic ones (' . count( $rows ) . ' rows)' );

$next_anniversary = $a_hire->modify( '+2 years' ); // hire was 1 year + 200 days ago, so the current year ends at the 2nd anniversary
$want_due = $next_anniversary->modify( '-1 day' )->format( 'F j, Y' );
check( $want_due === $rows[ $a->ID ]['due_new'] && $rows[ $a->ID ]['due_changed'] && '' !== $rows[ $a->ID ]['due_old'], 'anniversary employee: due date becomes the day before the next anniversary (' . $rows[ $a->ID ]['due_old'] . ' → ' . $want_due . ')' );
check( $a_hire->modify( '+1 year' )->format( 'M j, Y' ) . ' – ' . $want_due === wp_date( 'M j, Y', strtotime( $a_hire->modify( '+1 year' )->format( 'Y-m-d' ) ) ) . ' – ' . $want_due && false !== strpos( $rows[ $a->ID ]['training_year'], $a_hire->modify( '+1 year' )->format( 'M j, Y' ) ), 'anniversary employee: the training year starts on the last anniversary (' . $rows[ $a->ID ]['training_year'] . ')' );
check( $rows[ $b->ID ]['missing_hire'] && 'Hire date missing' === $rows[ $b->ID ]['due_new'] && '' === $rows[ $b->ID ]['hire_date'], 'no verified hire date: listed as missing; the new due date reads "Hire date missing"' );
check( 'In progress · 13 of 24 h' === $rows[ $c->ID ]['annual_old'] && 'Compliant · 13 of 12 h' === $rows[ $c->ID ]['annual_new'] && $rows[ $c->ID ]['annual_changed'], '12-hour role: annual result changes from in progress (13 of 24) to compliant (13 of 12)' );
check( 'default' === $rows[ $a->ID ]['source'] && $rows[ $a->ID ]['requirement_not_set'] && 'Role requirement not set' === $rows[ $a->ID ]['requirement'] && 'override' === $rows[ $c->ID ]['source'] && 'role_map' === $rows[ $d->ID ]['source'], 'dry run: threshold source per employee (default, override, role map)' );
check( '' !== $rows[ $a->ID ]['window_old'] && $rows[ $a->ID ]['window_old'] !== $rows[ $a->ID ]['training_year'], 'dry run: old (365-day block) and new (audit) training-year windows (' . $rows[ $a->ID ]['window_old'] . ' → ' . $rows[ $a->ID ]['training_year'] . ')' );
check( array_key_exists( 'deadline_old', $rows[ $a->ID ] ) && array_key_exists( 'deadline_new', $rows[ $a->ID ] ), 'dry run: old and new new-hire deadline fields on every row' );
check( $report['summary']['missing_hire'] >= 1 && $report['summary']['requirement_not_set'] >= 2 && $report['summary']['annual_changed'] >= 1 && $report['summary']['changed'] >= 2, 'summary counts include the changes and missing data (' . wp_json_encode( $report['summary'] ) . ')' );
$csv = GHCA_ACD_Training_Year_Review::csv_rows( $report );
$csv_c = array_values( array_filter( $csv, static function ( $r ) use ( $c ) { return (string) $c->ID === $r[0]; } ) )[0];
check( array( 'Threshold source', 'Training-year window (old)', 'Training-year window (new)' ) === array_slice( $csv[0], 4, 3 ) && in_array( 'New-hire deadline (old)', $csv[0], true ) && in_array( 'New-hire deadline (new)', $csv[0], true ) && 'override' === $csv_c[4], 'CSV: threshold source, old and new windows, old and new new-hire deadlines' );
check( count( $csv ) - 1 === count( $report['rows'] ), 'CSV: one row per employee (everyone)' );
$cli_ids = array_map( static function ( $r ) { return (int) $r['User ID']; }, GHCA_ACD_Training_Year_Review::cli_table_rows( $report ) );
$need    = array_map( static function ( $r ) { return (int) $r['user_id']; }, array_filter( $report['rows'], static function ( $r ) { return $r['changed'] || $r['missing_hire'] || $r['requirement_not_set']; } ) );
check( ! array_diff( $need, $cli_ids ) && in_array( $a->ID, $cli_ids, true ), 'command-line table includes everyone who changes or has missing data, including role requirement not set' );
check( 'Due date (old)' === $csv[0][7] && 'MISSING' === array_values( array_filter( $csv, static function ( $r ) use ( $b ) { return (string) $b->ID === $r[0]; } ) )[0][2], 'CSV: header, and MISSING for the employee without a hire date' );
$h = fopen( 'php://memory', 'w+' ); GHCA_ACD_CSV::write_row( $h, $csv_c ); rewind( $h );
check( '=Synthetic Twelve' === $csv_c[1] && false !== strpos( (string) stream_get_contents( $h ), "'=Synthetic Twelve" ), 'CSV goes through the shared writer: a name starting with "=" is neutralised' );

// ---- The page shows the role map and the cached dry run; loading it never recomputes or writes.
$before = durable_state();
ob_start(); GHCA_ACD_Training_Year_Review::render_page(); $page = (string) ob_get_clean();
check( durable_state() === $before && false !== strpos( $page, 'No dry run yet.' ) && false === strpos( $page, 'data-ghca-training-year-review' ), 'page without a dry run: nothing computed, nothing written' );
check( false !== strpos( $page, 'Per 55 Pa. Code §6100.143; nurse mapping is agency policy.' ) && false !== strpos( $page, 'name="role_map[synthetic_ty_office]"' ) && false !== strpos( $page, 'building-owner exception' ) && false !== strpos( $page, 'Nurse role: 24 h is agency policy' ), 'page: role map table with the basis text, exceptions next to "Not required", and the nurse agency-policy note' );
$o1 = option_names();
$_POST = $_REQUEST = array( 'action' => GHCA_ACD_Training_Year_Review::ACTION_RUN, '_wpnonce' => wp_create_nonce( GHCA_ACD_Training_Year_Review::ACTION_RUN ) );
ob_start(); try { GHCA_ACD_Training_Year_Review::handle_run(); } catch ( Ghca_TY_Stop $x ) {} ob_end_clean(); // phpcs:ignore
$o2 = option_names();
$written = array();
foreach ( $o2 as $name => $row ) { if ( ! isset( $o1[ $name ] ) || $o1[ $name ]->{'MD5(option_value)'} !== $row->{'MD5(option_value)'} ) { $written[] = $name; } }
sort( $written );
check( array( '_transient_ghca_acd_ty_dryrun_' . $admin->ID, '_transient_timeout_ghca_acd_ty_dryrun_' . $admin->ID ) === $written, 'running the dry run writes only its own cache (' . implode( ', ', $written ) . '), never the dashboard aggregate' );
$cache = GHCA_ACD_Training_Year_Review::cached();
$cache['report']['rows'][0]['name'] = 'Sentinel From Cache';
set_transient( 'ghca_acd_ty_dryrun_' . $admin->ID, $cache, 600 );
$before = durable_state();
ob_start(); GHCA_ACD_Training_Year_Review::render_page(); $page = (string) ob_get_clean();
check( durable_state() === $before && false !== strpos( $page, 'Sentinel From Cache' ), 'page load reads the cached dry run (no recomputation, nothing written)' );
$_GET['show'] = 'all';
ob_start(); GHCA_ACD_Training_Year_Review::render_page(); $page = (string) ob_get_clean();
unset( $_GET['show'] );
check( false !== strpos( $page, 'Requirement (source)' ) && false !== strpos( $page, 'Training-year window (old → new)' ) && false !== strpos( $page, 'New-hire deadline (old → new)' ) && false !== strpos( $page, 'Role requirement not set: ' ), 'page: source, old → new windows and new-hire deadlines, and the role-requirement count' );
check( false !== strpos( $page, 'name="reviewed"' ) && false !== strpos( $page, 'Enable training-year rules' ), 'page: the enable button sits under the dry run, with the review confirmation' );
GHCA_ACD_Training_Year_Review::run_and_cache();

// ---- Switch off: everything else looks and computes exactly as before rc.1.
$reg_due = wp_date( 'F j, Y', strtotime( $a->user_registered . ' UTC' ) + 365 * DAY_IN_SECONDS );
$off = GHCA_ACD_Data_Provider::get_employee_record( $a->ID );
check( $reg_due === $off['due_date_label'] && '' === $off['training_year_label'] && '' === $off['annual_requirement_label'], 'switch off: due date is the previous 365-day block from registration (' . $reg_due . '); no training-year fields' );
check( 24 === GHCA_ACD_Training_Year::required_hours( $e->ID ) && 'in_progress' === GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => $c->ID, 'name' => '' ), 'annual', get_option( 'ghca_acd_audit_mapping' ) )['annual_status'], 'switch off: threshold stays 24 h for everyone, even with a 12 h role mapped' );
require_once ABSPATH . 'wp-admin/includes/plugin.php'; // settings_fields()
ob_start(); GHCA_ACD_Settings::render_page(); $settings = (string) ob_get_clean();
check( false === strpos( $settings, 'ghca_acd_new_hire_anchor' ) && false === strpos( $settings, 'Recommended: no lifespan' ) && false === strpos( $settings, 'ghca-lifespan-annual' ) && false !== strpos( $settings, 'Default: 30 days from new hire group enrollment.' ), 'switch off: Settings shows no new-hire start-date setting and no training-year lifespan notes' );
$legacy_off = ajax( array( 'GHCA_ACD_AJAX', 'ajax_get_employee_drawer' ), array( 'user_id' => $a->ID ) );
check( false === strpos( $legacy_off['data']['html'], 'Training year' ) && false === strpos( $legacy_off['data']['html'], 'Annual requirement' ), 'switch off: the drawer shows no training-year lines' );

// ---- Switch: needs the dry-run confirmation; manage_options only.
$switch = static function ( array $post ): string {
	$_POST = $_REQUEST = $post + array( 'action' => GHCA_ACD_Training_Year_Review::ACTION_SWITCH, '_wpnonce' => wp_create_nonce( GHCA_ACD_Training_Year_Review::ACTION_SWITCH ) );
	ob_start();
	try { GHCA_ACD_Training_Year_Review::handle_switch(); } catch ( Ghca_TY_Stop $e ) { ob_end_clean(); return $e->getMessage(); }
	return (string) ob_get_clean();
};
$out = $switch( array( 'enable' => '1' ) );
check( false !== strpos( $out, 'reviewed the dry run' ) && ! GHCA_ACD_Training_Year::enabled(), 'enabling without confirming the dry run is refused' );
$viewer = get_user_by( 'login', 'synthetic-viewer' ) ?: get_user_by( 'id', wp_insert_user( array( 'user_login' => 'synthetic-viewer', 'user_email' => 'viewer@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) ) );
wp_set_current_user( $viewer->ID );
$out = $switch( array( 'enable' => '1', 'reviewed' => '1' ) );
wp_set_current_user( $admin->ID );
check( ! GHCA_ACD_Training_Year::enabled() && false !== stripos( $out, 'denied' ), 'a non-administrator cannot enable the rules' );
$_REQUEST['_wpnonce'] = 'bad';
$_POST = $_REQUEST = array( 'action' => GHCA_ACD_Training_Year_Review::ACTION_SWITCH, 'enable' => '1', 'reviewed' => '1', '_wpnonce' => 'bad' );
ob_start(); try { GHCA_ACD_Training_Year_Review::handle_switch(); } catch ( Ghca_TY_Stop $e ) {} ob_end_clean(); // phpcs:ignore
check( ! GHCA_ACD_Training_Year::enabled(), 'a bad nonce cannot enable the rules' );
$out = $switch( array( 'enable' => '1', 'reviewed' => '1' ) );
$by  = get_option( GHCA_ACD_Training_Year::OPTION_ENABLED_BY );
check( GHCA_ACD_Training_Year::enabled() && 0 === strpos( $out, 'redirect:' ) && (int) $by['by'] === $admin->ID && ! empty( $by['enabled'] ), 'with the confirmation the rules are enabled, and who/when is recorded' );

// ---- Enabled: dashboard records and both drawers.
$record = GHCA_ACD_Data_Provider::get_employee_record( $a->ID );
check( $want_due === $record['due_date_label'] && '' !== $record['training_year_label'], 'dashboard record: training-year due date and window' );
check( 'Hire date missing' === GHCA_ACD_Data_Provider::get_employee_record( $b->ID )['due_date_label'], 'dashboard record: "Hire date missing", no fallback date' );
$legacy = ajax( array( 'GHCA_ACD_AJAX', 'ajax_get_employee_drawer' ), array( 'user_id' => $a->ID ) );
check( ! empty( $legacy['success'] ) && false !== strpos( $legacy['data']['html'], 'Training year: ' . $record['training_year_label'] ) && false !== strpos( $legacy['data']['html'], 'Annual requirement: Role requirement not set' ), 'legacy drawer: training year and requirement next to the due date' );
$_GET['ghca_ui'] = 'board';
$board = ajax( array( 'GHCA_ACD_AJAX', 'ajax_get_employee_drawer' ), array( 'user_id' => $a->ID, 'ghca_ui' => 'board' ) );
unset( $_GET['ghca_ui'] );
check( ! empty( $board['success'] ) && false !== strpos( $board['data']['html'], 'data-ghca-training-year' ) && false !== strpos( $board['data']['html'], esc_html( 'Training year ' . $record['training_year_label'] ) ), 'redesigned drawer: training year next to the due date' );

// ---- Requirement through Edit Records.
$r = ajax( array( 'GHCA_ACD_AJAX', 'ajax_save_employee_records' ), array( 'user_id' => $a->ID, 'training_requirement' => 'dsp_supervisor' ) );
check( ! empty( $r['success'] ) && 'dsp_supervisor' === get_user_meta( $a->ID, GHCA_ACD_Training_Year::REQUIREMENT_META, true ), 'Edit Records saves the annual requirement (' . wp_json_encode( $r['data'] ?? array() ) . ')' );
$r = ajax( array( 'GHCA_ACD_AJAX', 'ajax_save_employee_records' ), array( 'user_id' => $a->ID, 'training_requirement' => 'not_a_role' ) );
check( empty( $r['success'] ) && 'dsp_supervisor' === get_user_meta( $a->ID, GHCA_ACD_Training_Year::REQUIREMENT_META, true ), 'an unknown requirement is refused and nothing changes' );
wp_set_current_user( $viewer->ID );
$r = ajax( array( 'GHCA_ACD_AJAX', 'ajax_save_employee_records' ), array( 'user_id' => $a->ID, 'training_requirement' => 'management' ) );
wp_set_current_user( $admin->ID );
check( empty( $r['success'] ) && 'dsp_supervisor' === get_user_meta( $a->ID, GHCA_ACD_Training_Year::REQUIREMENT_META, true ), 'a user without Edit Records cannot change the requirement' );

// ---- Off again: previous behaviour.
$out = $switch( array( 'enable' => '0' ) );
check( ! GHCA_ACD_Training_Year::enabled() && $rows[ $a->ID ]['due_old'] === GHCA_ACD_Data_Provider::get_employee_record( $a->ID )['due_date_label'] && '' === GHCA_ACD_Data_Provider::get_employee_record( $a->ID )['training_year_label'], 'turning the rules off restores the previous due date at once' );

// ---- Clean up.
foreach ( $saved_options as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $a, $b, $c, $d, $e, $f ) as $user ) { wp_delete_user( $user->ID ); }
foreach ( array( 'synthetic_ty_office', 'synthetic_ty_dsp', 'synthetic_ty_lpn' ) as $role ) { remove_role( $role ); }
delete_transient( 'ghca_acd_ty_dryrun_' . $admin->ID );
GHCA_ACD_Data_Provider::bust_cache();

echo $failed ? "FAILED: $failed of $checks training-year real checks\n" : "PASS: $checks training-year real checks (WordPress + MariaDB; synthetic users; nothing sent)\n";
exit( $failed ? 1 : 0 );
