<?php
/** Synthetic tests: namespaced built-in roles never adopt a pre-existing role. No WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array(); $GLOBALS['reg'] = array(); $GLOBALS['users'] = array();
class T_Role { public $capabilities; function __construct( $c ) { $this->capabilities = $c; } function add_cap( $c ) { $this->capabilities[ $c ] = true; } function remove_cap( $c ) { unset( $this->capabilities[ $c ] ); } }
class T_User { public $roles; function __construct( $r ) { $this->roles = $r; } function add_role( $r ) { $this->roles[] = $r; } function remove_role( $r ) { $this->roles = array_values( array_diff( $this->roles, array( $r ) ) ); } }
function __( $t, $d = '' ) { return $t; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_role( $s ) { return $GLOBALS['reg'][ $s ] ?? null; }
function add_role( $s, $l, $c = array() ) { $GLOBALS['reg'][ $s ] = new T_Role( $c ); return $GLOBALS['reg'][ $s ]; }
function remove_role( $s ) { unset( $GLOBALS['reg'][ $s ] ); }
function get_users( $a ) { return array_values( array_filter( $GLOBALS['users'], static function ( $u ) use ( $a ) { return in_array( $a['role'], $u->roles, true ); } ) ); }
final class GHCA_ACD_Settings { const OPTION_PERM_EDIT_RECORDS='a'; const OPTION_PERM_MANAGE_ANNOUNCEMENTS='b'; const OPTION_PERM_MANAGE_USERS='c'; const OPTION_PERM_UNRESTRICTED_VIEW='d'; const OPTION_PERM_SEND_REMINDERS='e'; const OPTION_PERM_VIEW_COMMUNICATIONS='f'; const OPTION_PERM_MANAGE_TEMPLATES='g'; const OPTION_PERM_VIEW_DOCUMENTS='h'; }
$n = 0;
function ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require __DIR__ . '/../includes/class-data-provider.php';
require __DIR__ . '/../includes/class-roles.php';

ok( array( 'ghca_clinical_supervisor', 'ghca_administrative_support_desk', 'ghca_director_of_operations' ) === array_keys( GHCA_ACD_Roles::builtin_employee_roles() ), 'built-in role slugs are namespaced' );

// Another plugin / site already has the NEW slug with elevated capabilities, and the old slugs too.
$GLOBALS['reg']['ghca_clinical_supervisor'] = new T_Role( array( 'read' => true, 'edit_users' => true, 'manage_options' => true ) );
$GLOBALS['reg']['clinical_supervisor']      = new T_Role( array( 'read' => true, 'edit_others_posts' => true ) );
$GLOBALS['users']['u1'] = new T_User( array( 'clinical_supervisor' ) );
GHCA_ACD_Roles::register_roles();
ok( array( 'read' => true, 'edit_users' => true, 'manage_options' => true ) === $GLOBALS['reg']['ghca_clinical_supervisor']->capabilities, 'pre-existing role capabilities are never modified' );
ok( ! GHCA_ACD_Roles::builtin_role_is_plugin_owned( 'ghca_clinical_supervisor' ), 'conflicting role is not owned by the plugin' );
ok( ! in_array( 'ghca_clinical_supervisor', GHCA_ACD_Roles::owned_roles(), true ), 'conflicting role is not recorded as plugin-owned' );
ok( ! isset( GHCA_ACD_Roles::active_builtin_employee_roles()['ghca_clinical_supervisor'] ), 'Settings does not list the conflicting role as a built-in' );
ok( isset( GHCA_ACD_Roles::active_builtin_employee_roles()['ghca_director_of_operations'] ) && 'read' === array_key_first( $GLOBALS['reg']['ghca_director_of_operations']->capabilities ), 'non-conflicting built-ins are created read-only and listed' );
$picker = GHCA_ACD_Roles::selectable_employee_roles( array( 'subscriber' => array(), 'ghca_clinical_supervisor' => array(), 'ghca_director_of_operations' => array() ), array( 'ghca_clinical_supervisor' ) );
ok( ! isset( $picker['ghca_clinical_supervisor'] ) && isset( $picker['ghca_director_of_operations'] ), 'picker never offers the conflicting role, even if "kept"' );
ok( isset( $GLOBALS['reg']['clinical_supervisor'] ) && array( 'clinical_supervisor' ) === $GLOBALS['users']['u1']->roles, 'legacy-slug role with elevated caps is left untouched, users not moved' );

// An independently created read-only role with a legacy-style slug is never touched: no user moves, no deletion.
$GLOBALS['opts'] = array(); $GLOBALS['reg'] = array();
$GLOBALS['reg']['clinical_supervisor'] = new T_Role( array( 'read' => true ) );
$GLOBALS['users'] = array( 'a' => new T_User( array( 'clinical_supervisor', 'nurse' ) ), 'b' => new T_User( array( 'subscriber' ) ) );
GHCA_ACD_Roles::register_roles();
GHCA_ACD_Roles::register_roles(); // repeated loads / activation
ok( isset( $GLOBALS['reg']['clinical_supervisor'] ) && array( 'read' => true ) === $GLOBALS['reg']['clinical_supervisor']->capabilities, 'independent read-only role is not deleted or altered' );
ok( array( 'clinical_supervisor', 'nurse' ) === $GLOBALS['users']['a']->roles && array( 'subscriber' ) === $GLOBALS['users']['b']->roles, 'its users keep their roles (nothing migrated)' );
ok( GHCA_ACD_Roles::builtin_role_is_plugin_owned( 'ghca_clinical_supervisor' ) && ! in_array( 'clinical_supervisor', GHCA_ACD_Roles::owned_roles(), true ), 'namespaced built-in is created and owned; the independent role is not claimed' );
$src = file_get_contents( __DIR__ . '/../includes/class-roles.php' );
ok( false === strpos( $src, 'migrate_legacy' ) && false === strpos( $src, 'remove_role' ), 'no automatic legacy migration or role deletion remains in the plugin' );

// Settings/AJAX wiring.
$ajax = file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
$set  = file_get_contents( __DIR__ . '/../includes/class-settings.php' );
ok( false !== strpos( $ajax, 'builtin_role_is_plugin_owned( $role )' ), 'AJAX rejects assigning a non-owned reserved built-in slug' );
ok( false !== strpos( $set, 'active_builtin_employee_roles()' ), 'Settings lists only plugin-owned built-in roles' );
echo "PASS: $n built-in role namespacing checks\n";
