<?php
/** Synthetic handler tests: no WordPress bootstrap, database, network or messaging. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
  private $message;
  public function __construct( $code, $message ) { $this->message = $message; }
  public function get_error_message() { return $this->message; }
}
class Employment_Response extends Exception {
  public $success;
  public function __construct( $success ) { $this->success = $success; }
}
function __( $text, $domain = '' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_send_json_error( $data ) { throw new Employment_Response( false ); }
function wp_send_json_success( $data ) { throw new Employment_Response( true ); }
function check_ajax_referer( $action, $field ) { if ( ! $GLOBALS['nonce_ok'] ) { wp_send_json_error( '' ); } }
function get_current_user_id() { return 90; }
function current_user_can( $cap ) { return false; }
function get_user_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) {
  if ( $GLOBALS['fail_write'] && 'ghca_acd_employment_type' === $key ) { return false; }
  $GLOBALS['meta'][ $id ][ $key ] = $value;
  return true;
}
function get_userdata( $id ) { return $id === 11 || $id === 12 ? (object) array( 'roles' => array( 'subscriber' ), 'allcaps' => array(), 'user_registered' => '2025-01-01 00:00:00' ) : false; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function sanitize_email( $value ) { return $value; }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_update_user( $data ) { ++$GLOBALS['account_writes']; return $data['ID']; }
function wp_insert_user( $data ) { ++$GLOBALS['account_writes']; return 12; }
function wp_generate_password( ...$args ) { return 'synthetic-not-used'; }
function get_date_from_gmt( $value, $format ) { return strtotime( $value . ' UTC' ); }
function learndash_user_get_enrolled_courses( $id ) { return $GLOBALS['enrolled_fixture'] ?? array(); }
function wp_date( $format ) { return gmdate( $format ); }
function get_option( $key, $default ) { return $default; }
function delete_transient( $key ) {}
class GHCA_Admin_Compliance_Dashboard { const VERSION = 'test'; }
class GHCA_Compliance_Program { public static function reset_runtime_cache() {} }
class GHCA_ACD_Settings { public static function bust_dashboard_cache() {} }
class GHCA_ACD_Roles {
  public static function user_can_manage_users() { return $GLOBALS['permission_ok']; }
  public static function user_can_edit_records() { return $GLOBALS['permission_ok']; }
  public static function user_has_full_dashboard_access() { return false; }
  public static function get_manageable_employee_roles() { return array( 'subscriber' => array() ); }
  public static function role_update_is_needed( ...$args ) { return false; }
  public static function delegated_target_is_allowed( ...$args ) { return $GLOBALS['scope_ok']; }
  public static function delegated_target_is_in_scope( $id ) { return $GLOBALS['scope_ok']; }
}
class GHCA_ACD_Scoping { public static function get_visible_group_ids() { return array( 7 ); } }
class GHCA_ACD_User_Report { public static function can_view_user( $id ) { return $GLOBALS['scope_ok']; } }
require_once __DIR__ . '/../includes/class-data-provider.php';
require_once __DIR__ . '/../includes/class-ajax-handlers.php';
$meta = array( 11 => array( 'suspended' => true, 'course_completed_99' => 123 ) );
$nonce_ok = $permission_ok = $scope_ok = true;
$fail_write = false;
$account_writes = 0;
$checks = 0;
function verify_type( $condition, $message ) {
  ++$GLOBALS['checks'];
  if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function submit_type( $handler, $extra ) {
  $_POST = array_merge( array( 'user_id' => 11, 'first_name' => 'Synthetic', 'last_name' => 'Staff', 'email' => 'synthetic@example.invalid', 'groups' => array( 7 ) ), $extra );
  try { GHCA_ACD_AJAX::$handler(); } catch ( Employment_Response $response ) { return $response->success; }
  throw new RuntimeException( 'Handler did not return JSON' );
}
verify_type( 'Not set' === GHCA_ACD_Data_Provider::employment_type_label( 11 ), 'legacy unset state' );
foreach ( array( 'ajax_save_employee', 'ajax_save_employee_records' ) as $handler ) {
  foreach ( GHCA_ACD_Data_Provider::employment_types() as $type => $label ) {
    verify_type( submit_type( $handler, array( 'employment_type' => $type ) ), 'valid handler save' );
    verify_type( $type === GHCA_ACD_Data_Provider::employment_type( 11 ) && $label === GHCA_ACD_Data_Provider::employment_type_label( 11 ), 'shared read and badge label' );
    verify_type( 90 === $meta[11]['ghca_acd_employment_type']['updated_by'] && abs( time() - $meta[11]['ghca_acd_employment_type']['updated_at'] ) < 3, 'attributed save' );
  }
  $before = $meta[11]['ghca_acd_employment_type'];
  verify_type( submit_type( $handler, array() ) && $before === $meta[11]['ghca_acd_employment_type'], 'omitted field preserved' );
  verify_type( submit_type( $handler, array( 'employment_type' => 'contractor' ) ) && $before === $meta[11]['ghca_acd_employment_type'], 'unchanged value preserves attribution' );
  foreach ( array( 'invalid', 'FULL_TIME', ' full_time', array( 'full_time' ), null ) as $bad ) {
    $snapshot = $meta;
    $writes = $account_writes;
    verify_type( ! submit_type( $handler, array( 'employment_type' => $bad ) ) && $snapshot === $meta && $writes === $account_writes, 'invalid input rejected before any write' );
  }
  foreach ( array( 'nonce_ok', 'permission_ok', 'scope_ok' ) as $gate ) {
    $GLOBALS[$gate] = false;
    $snapshot = $meta;
    $writes = $account_writes;
    verify_type( ! submit_type( $handler, array( 'employment_type' => 'full_time' ) ) && $snapshot === $meta && $writes === $account_writes, 'authorization gate prevents writes' );
    $GLOBALS[$gate] = true;
  }
  $fail_write = true;
  verify_type( ! submit_type( $handler, array( 'employment_type' => 'full_time' ) ) && $before === $meta[11]['ghca_acd_employment_type'], 'storage failure reported' );
  $fail_write = false;
  verify_type( submit_type( $handler, array( 'employment_type' => '' ) ) && 'Not set' === GHCA_ACD_Data_Provider::employment_type_label( 11 ), 'explicit clear' );
}
verify_type( submit_type( 'ajax_save_employee', array( 'user_id' => 0, 'employment_type' => 'part_time' ) ) && 'part_time' === GHCA_ACD_Data_Provider::employment_type( 12 ), 'new employee type' );
verify_type( true === $meta[11]['suspended'] && 123 === $meta[11]['course_completed_99'], 'account state and training untouched' );
echo "PASS: $checks synthetic employment-type checks\n";
