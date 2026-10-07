<?php
define( 'ABSPATH', __DIR__ );
function is_admin() { return $GLOBALS['admin']; }
function current_user_can( $cap ) { return 'read' === $cap || $GLOBALS['manage']; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', $v ); }
function wp_unslash( $v ) { return $v; }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_html__( $v, $d ) { return esc_html( $v ); }
function esc_attr__( $v, $d ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
class GHCA_ACD_Roles { public static function user_can_manage_messaging_settings() { return $GLOBALS['messaging']; } public static function user_can_manage_message_templates() { return false; } }
class GHCA_ACD_Admin_Menu { const SLUG = 'ghca-acd'; public static function url( $p ) { return '/wp-admin/admin.php?page=' . $p; } }
class GHCA_Dashboard_Branding { public static function get_org_name() { return 'Synthetic <Agency>'; } }
require __DIR__ . '/../includes/class-settings-console.php';
$admin = $manage = true; $messaging = false; $count = 0;
function console_check( $ok ) { ++$GLOBALS['count']; if ( ! $ok ) { throw new RuntimeException( 'Console assertion ' . $GLOBALS['count'] ); } }
foreach ( GHCA_Settings_Console::groups() as $links ) { foreach ( $links as $key => $label ) { $_GET['page'] = explode( '#', $key )[0]; console_check( ( 'ghca-acd-messaging' === $_GET['page'] ? '' : $_GET['page'] ) === GHCA_Settings_Console::page() ); } }
$_GET['page'] = 'ghca-acd-settings'; console_check( false !== strpos( GHCA_Settings_Console::body_class( 'existing' ), 'existing ghca-settings-console' ) );
$admin = false; console_check( '' === GHCA_Settings_Console::page() ); $admin = true;
foreach ( array( 'users', 'ghca-acd-settings-extra', array( 'ghca-acd-settings' ) ) as $bad ) { $_GET['page'] = $bad; console_check( '' === GHCA_Settings_Console::page() ); }
$manage = false; $_GET['page'] = 'ghca-acd-settings'; console_check( '' === GHCA_Settings_Console::page() );
$_GET['page'] = 'ghca-acd-messaging'; $messaging = true; console_check( 'ghca-acd-messaging' === GHCA_Settings_Console::page() );
$submenu['ghca-acd'] = array( array( 'Settings', 'manage_options', 'ghca-acd-settings' ), array( 'Messaging', 'read', 'ghca-acd-messaging' ) );
ob_start(); GHCA_Settings_Console::header(); $html = ob_get_clean();
console_check( false !== strpos( $html, 'Messaging' ) && false === strpos( $html, 'Training settings' ) );
console_check( false !== strpos( $html, 'Synthetic &lt;Agency&gt;' ) );
echo "PASS: $count console scope, navigation and escaping checks.\n";
