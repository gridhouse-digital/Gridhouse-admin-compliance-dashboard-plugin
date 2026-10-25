<?php
/** Standalone Settings API model; no WordPress bootstrap, database or network.
 * Models registered-default lookup and update_option's equal-value early return.
 * Run: php tests/test-annual-cycle-settings.php
 */
define( 'ABSPATH', __DIR__ . '/' );
class GHCA_Course_Lifespans {
    const OPTION_LIFESPANS = 'lifespans';
    const OPTION_WARNING_DAYS = 'warning';
    const DEFAULT_WARNING_DAYS = 90;
}
class GHCA_Compliance_Program {
    const OPTION_NEW_HIRE_GROUPS = 'groups';
    const OPTION_NEW_HIRE_DAYS = 'days';
    const DEFAULT_DEADLINE_DAYS = 30;
}
class CycleRegistrationCaptured extends RuntimeException {}
$registered = array();
$options = array();
$errors = array();
$checks = 0;
function __( $text, $domain = '' ) { return $text; }
function register_setting( $group, $key, $args ) {
    $GLOBALS['registered'][$key] = $args;
    if ( 'ghca_acd_annual_cycle' === $key ) { throw new CycleRegistrationCaptured(); }
}
function get_option( $key, $default = false ) {
    if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return $GLOBALS['options'][$key]; }
    // WordPress's filter_default_option respects an explicitly supplied default.
    return func_num_args() > 1 ? $default : ( $GLOBALS['registered'][$key]['default'] ?? false );
}
function add_settings_error( ...$args ) { $GLOBALS['errors'][] = $args; }
function model_save( $value ) {
    $key = GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE;
    $value = call_user_func( $GLOBALS['registered'][$key]['sanitize_callback'], $value );
    if ( $value === get_option( $key ) ) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function check_cycle( $ok, $label ) {
    if ( ! $ok ) { throw new RuntimeException( $label ); }
    ++$GLOBALS['checks'];
}
require __DIR__ . '/../includes/class-settings.php';
try { GHCA_ACD_Settings::register_settings(); } catch ( CycleRegistrationCaptured $e ) {}
$key = GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE;
check_cycle( '' === $registered[$key]['default'], 'Registration cannot imply a saved policy' );
foreach ( array( 'employee_start_date', 'calendar_year' ) as $cycle ) {
    $options = array();
    check_cycle( '' === GHCA_ACD_Settings::get_configured_annual_cycle(), 'Missing cycle remains unknown' );
    check_cycle( model_save( $cycle ), 'First explicit save must persist, including anniversary' );
    check_cycle( $cycle === $options[$key] && $cycle === GHCA_ACD_Settings::get_configured_annual_cycle(), 'Stored policy matches calculator input' );
    check_cycle( ! model_save( $cycle ), 'Repeated save is idempotent' );
    foreach ( array( null, '', 'unsupported', array(), 1 ) as $invalid ) {
        model_save( $invalid );
        check_cycle( $cycle === $options[$key], 'Invalid/omitted input must preserve saved policy' );
    }
}
check_cycle( model_save( 'employee_start_date' ), 'Existing calendar policy can be changed explicitly' );
check_cycle( model_save( 'calendar_year' ), 'Existing anniversary policy can be changed explicitly' );
$options = array();
model_save( '' );
check_cycle( '' === GHCA_ACD_Settings::get_configured_annual_cycle(), 'Blank form cannot invent a policy' );
foreach ( array( '' => 'Not configured', 'bad' => 'Not configured', 'calendar_year' => 'Calendar Year', 'employee_start_date' => 'Employee Hire Date' ) as $cycle => $label ) {
    check_cycle( $label === GHCA_ACD_Settings::annual_cycle_label( $cycle ), 'Explicit label for each state' );
}
// Prove this harness detects the previous regression.
$registered[$key]['default'] = 'employee_start_date';
check_cycle( ! model_save( 'employee_start_date' ) && ! array_key_exists( $key, $options ), 'Old registration reproduces skipped first save' );
echo "PASS: $checks annual-cycle checks (Settings API model, not live WordPress save)\n";
