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
/** Checksum of everything durable the plugin could write: options (not transients) and user meta. */
function durable_state(): string {
	global $wpdb;
	$o = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' ORDER BY option_name", ARRAY_N );
	$m = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_N );
	return hash( 'sha256', wp_json_encode( array( $o, $m ) ) );
}

$admin = get_user_by( 'login', 'synthetic-admin' );
wp_set_current_user( $admin->ID );
$saved_options = array();
foreach ( array( 'ghca_acd_annual_cycle', 'ghca_acd_audit_mapping', GHCA_ACD_Training_Year::OPTION_ENABLED, GHCA_ACD_Training_Year::OPTION_ENABLED_BY, GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR ) as $name ) {
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
GHCA_ACD_Data_Provider::bust_cache();
GHCA_ACD_Data_Provider::get_employees_for_current_view(); // warm the aggregate so its transient is not counted as a dry-run write

// ---- Dry run: read-only.
$before = durable_state();
$report = GHCA_ACD_Training_Year_Review::build( GHCA_ACD_Training_Year_Review::employee_ids() );
check( durable_state() === $before, 'the dry run writes nothing (options and user meta byte-identical before and after)' );
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
check( '' === $rows[ $a->ID ]['requirement'] && '' !== $rows[ $c->ID ]['requirement'], 'requirement: not set for one employee, set for the other' );
check( $report['summary']['missing_hire'] >= 1 && $report['summary']['requirement_not_set'] >= 2 && $report['summary']['annual_changed'] >= 1 && $report['summary']['changed'] >= 2, 'summary counts include the changes and missing data (' . wp_json_encode( $report['summary'] ) . ')' );
$csv = GHCA_ACD_Training_Year_Review::csv_rows( $report );
$csv_c = array_values( array_filter( $csv, static function ( $r ) use ( $c ) { return (string) $c->ID === $r[0]; } ) )[0];
check( 'Due date (old)' === $csv[0][5] && 'MISSING' === array_values( array_filter( $csv, static function ( $r ) use ( $b ) { return (string) $b->ID === $r[0]; } ) )[0][2], 'CSV: header, and MISSING for the employee without a hire date' );
$h = fopen( 'php://memory', 'w+' ); GHCA_ACD_CSV::write_row( $h, $csv_c ); rewind( $h );
check( '=Synthetic Twelve' === $csv_c[1] && false !== strpos( (string) stream_get_contents( $h ), "'=Synthetic Twelve" ), 'CSV goes through the shared writer: a name starting with "=" is neutralised' );

// ---- The admin page renders the same report (read-only as well).
$before = durable_state();
require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button(), loaded on real admin pages
ob_start(); GHCA_ACD_Training_Year_Review::render_page(); $page = (string) ob_get_clean();
check( durable_state() === $before && false !== strpos( $page, 'data-ghca-training-year-review' ) && false !== strpos( $page, '<del>' . esc_html( $rows[ $a->ID ]['due_old'] ) . '</del> → <strong>' . esc_html( $want_due ) . '</strong>' ) && false !== strpos( $page, 'Missing a verified hire date' ), 'admin page: lists old → new due dates and missing hire dates, and writes nothing' );
check( false !== strpos( $page, 'name="reviewed"' ) && false !== strpos( $page, 'Enable training-year rules' ), 'admin page: the enable button sits under the dry run, with the review confirmation' );

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
check( ! empty( $legacy['success'] ) && false !== strpos( $legacy['data']['html'], 'Training year: ' . $record['training_year_label'] ) && false !== strpos( $legacy['data']['html'], 'Annual requirement: Not set' ), 'legacy drawer: training year and requirement next to the due date' );
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
foreach ( array( $a, $b, $c ) as $user ) { wp_delete_user( $user->ID ); }
GHCA_ACD_Data_Provider::bust_cache();

echo $failed ? "FAILED: $failed of $checks training-year real checks\n" : "PASS: $checks training-year real checks (WordPress + MariaDB; synthetic users; nothing sent)\n";
exit( $failed ? 1 : 0 );
