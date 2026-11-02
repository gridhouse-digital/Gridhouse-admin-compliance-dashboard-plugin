<?php
/** Synthetic tests: built-in + custom Employment Types / Roles, settings handler, Issued card text. No WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array(); $GLOBALS['wp_roles_reg'] = array(); $GLOBALS['meta'] = array();
function __( $t, $d = '' ) { return $t; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_role( $s ) { return $GLOBALS['wp_roles_reg'][ $s ] ?? null; }
function add_role( $s, $l, $c = array() ) { $GLOBALS['wp_roles_reg'][ $s ] = (object) array( 'name' => $l, 'caps' => $c ); return $GLOBALS['wp_roles_reg'][ $s ]; }
function get_user_meta( $id, $k, $single = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
final class GHCA_ACD_Settings { const OPTION_PERM_EDIT_RECORDS='a'; const OPTION_PERM_MANAGE_ANNOUNCEMENTS='b'; const OPTION_PERM_MANAGE_USERS='c'; const OPTION_PERM_UNRESTRICTED_VIEW='d'; const OPTION_PERM_SEND_REMINDERS='e'; const OPTION_PERM_VIEW_COMMUNICATIONS='f'; const OPTION_PERM_MANAGE_TEMPLATES='g'; const OPTION_PERM_VIEW_DOCUMENTS='h'; }
$n = 0;
function ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require __DIR__ . '/../includes/class-data-provider.php';
require __DIR__ . '/../includes/class-roles.php';

// Source-level extraction of the settings handler (class-settings.php pulls in WP-only dependencies).
$src = file_get_contents( __DIR__ . '/../includes/class-settings.php' );
preg_match( '/public static function apply_custom_option_change.*?\n  }\n\n  public static function handle_custom_option_post/s', $src, $m );
ok( ! empty( $m[0] ), 'handler source found' );
eval( 'final class Opt_Handler { ' . preg_replace( '/\n  public static function handle_custom_option_post$/', '', $m[0] ) . ' }' );

// Built-ins.
$types = GHCA_ACD_Data_Provider::employment_types();
ok( 'Per Diem' === ( $types['per_diem'] ?? '' ), 'Per Diem is a built-in Employment Type' );
ok( array( 'full_time', 'part_time', 'per_diem', 'contractor' ) === array_keys( $types ), 'built-in order, no extras by default' );
$roles = GHCA_ACD_Roles::builtin_employee_roles();
ok( array( 'Clinical Supervisor', 'Administrative Support Desk', 'Director of Operations' ) === array_values( $roles ), 'three built-in roles' );

// Add custom type; dedupe vs built-in (case-insensitive) and vs itself.
ok( Opt_Handler::apply_custom_option_change( 'employment_type', 'add', 'Seasonal' )['ok'], 'add custom type' );
ok( ! Opt_Handler::apply_custom_option_change( 'employment_type', 'add', 'per diem' )['ok'], 'duplicate of built-in rejected' );
ok( ! Opt_Handler::apply_custom_option_change( 'employment_type', 'add', 'SEASONAL' )['ok'], 'duplicate of custom rejected' );
ok( ! Opt_Handler::apply_custom_option_change( 'employment_type', 'add', '   ' )['ok'], 'blank rejected' );
ok( ! Opt_Handler::apply_custom_option_change( 'employment_type', 'add', str_repeat( 'x', 61 ) )['ok'], 'over-long rejected' );
ok( 'Seasonal' === GHCA_ACD_Data_Provider::employment_types()['seasonal'], 'custom merged into form list' );
ok( 5 === count( GHCA_ACD_Data_Provider::employment_types() ), 'merged list is deduped' );
Opt_Handler::apply_custom_option_change( 'employment_type', 'add', '<b>Evil</b>' );
ok( isset( GHCA_ACD_Data_Provider::custom_employment_types()['evil'] ), 'markup stripped' );
$GLOBALS['opts'][ GHCA_ACD_Data_Provider::OPTION_CUSTOM_EMPLOYMENT_TYPES ]['bad'] = array( 'x' ); // tampered option
ok( ! isset( GHCA_ACD_Data_Provider::custom_employment_types()['bad'] ), 'tampered option entries ignored on read' );

// Built-ins cannot be removed.
ok( ! Opt_Handler::apply_custom_option_change( 'employment_type', 'remove', 'per_diem' )['ok'] && isset( GHCA_ACD_Data_Provider::employment_types()['per_diem'] ), 'built-in type not removable' );
ok( ! Opt_Handler::apply_custom_option_change( 'role', 'remove', 'clinical_supervisor' )['ok'], 'built-in role not removable' );
ok( ! Opt_Handler::apply_custom_option_change( 'bogus', 'add', 'x' )['ok'] && ! Opt_Handler::apply_custom_option_change( 'role', 'drop', 'x' )['ok'], 'unknown kind/op rejected' );

// Removed custom type: employee keeps value, label still resolves, edit form still lists it.
$GLOBALS['meta'][5]['ghca_acd_employment_type'] = array( 'type' => 'seasonal', 'label' => 'Seasonal' );
ok( Opt_Handler::apply_custom_option_change( 'employment_type', 'remove', 'seasonal' )['ok'], 'custom type removed' );
ok( ! isset( GHCA_ACD_Data_Provider::employment_types()['seasonal'] ), 'removed type gone from new-employee list' );
ok( 'seasonal' === GHCA_ACD_Data_Provider::employment_type( 5 ) && 'Seasonal' === GHCA_ACD_Data_Provider::employment_type_label( 5 ), 'employee keeps stored value and label' );
ok( isset( GHCA_ACD_Data_Provider::employment_type_options( 'seasonal' )['seasonal'] ), 'edit form still shows removed value' );
$GLOBALS['meta'][6]['ghca_acd_employment_type'] = array( 'type' => 'old_thing' );
ok( 'Old Thing' === GHCA_ACD_Data_Provider::employment_type_label( 6 ), 'label falls back to humanised slug' );

// Roles: custom add registers a namespaced WP role, remove retires it but keeps it registered.
ok( Opt_Handler::apply_custom_option_change( 'role', 'add', 'Night Nurse' )['ok'], 'add custom role' );
ok( isset( GHCA_ACD_Roles::custom_roles()['ghca_custom_night_nurse'] ) && get_role( 'ghca_custom_night_nurse' ), 'custom role stored + registered' );
ok( ! Opt_Handler::apply_custom_option_change( 'role', 'add', 'clinical supervisor' )['ok'], 'role dup of built-in rejected' );
ok( ! GHCA_ACD_Roles::role_is_retired( 'ghca_custom_night_nurse' ), 'active role not retired' );
ok( Opt_Handler::apply_custom_option_change( 'role', 'remove', 'ghca_custom_night_nurse' )['ok'], 'custom role removed' );
ok( GHCA_ACD_Roles::role_is_retired( 'ghca_custom_night_nurse' ) && get_role( 'ghca_custom_night_nurse' ), 'removed role retired but still registered for holders' );
$all = array( 'subscriber' => array(), 'ghca_custom_night_nurse' => array(), 'clinical_supervisor' => array() );
ok( ! isset( GHCA_ACD_Roles::selectable_employee_roles( $all, array() )['ghca_custom_night_nurse'] ), 'retired role hidden for new employees' );
ok( isset( GHCA_ACD_Roles::selectable_employee_roles( $all, array( 'ghca_custom_night_nurse' ) )['ghca_custom_night_nurse'] ), 'retired role shown when an employee holds it' );
ok( isset( GHCA_ACD_Roles::selectable_employee_roles( $all, array() )['clinical_supervisor'] ), 'built-in role never hidden' );
ok( Opt_Handler::apply_custom_option_change( 'role', 'add', 'Night Nurse' )['ok'] && ! GHCA_ACD_Roles::role_is_retired( 'ghca_custom_night_nurse' ), 're-adding un-retires' );

// Security wiring (source-level): nonce + capability before any write.
ok( preg_match( '/function handle_custom_option_post\(\).*?manage_options.*?check_admin_referer\( self::CUSTOM_OPTION_NONCE \).*?apply_custom_option_change/s', $src ) === 1, 'handler checks capability and nonce first' );
ok( false !== strpos( $src, "'admin_post_ghca_acd_custom_option'" ), 'handler registered on admin_post only (no nopriv)' );
ok( false === strpos( $src, 'admin_post_nopriv_ghca_acd_custom_option' ), 'no unauthenticated route' );

// Issued card text.
$sc = file_get_contents( __DIR__ . '/../includes/class-shortcodes.php' );
ok( false !== strpos( $sc, "'of %d certificate courses assigned'" ) && 1 === preg_match( "/'label' => __\( 'Issued', 'ghca-acd' \),\s*'sub'\s*=> sprintf\(.*?'of %d certificate courses assigned'.*?certificates_assigned/s", $sc ), 'Issued card subtext is exactly "of X certificate courses assigned"' );
echo "PASS: $n custom option checks\n";
