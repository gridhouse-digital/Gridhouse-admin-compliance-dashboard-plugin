<?php
/** Synthetic Settings API and rendered-HTML checks; no site bootstrap or writes. */
define( 'ABSPATH', __DIR__ . '/' );
$options = array();
$errors = array();
$allowed = true;
$checks = 0;
function __( $text, $domain = '' ) { return $text; }
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['allowed']; }
function get_current_user_id() { return 90; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function sanitize_textarea_field( $text ) { return trim( strip_tags( $text ) ); }
function add_settings_error( ...$args ) { $GLOBALS['errors'][] = $args; }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return esc_attr( $text ); }
function esc_textarea( $text ) { return esc_attr( $text ); }
function esc_url( $text ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function settings_errors( $name ) {}
function settings_fields( $name ) { echo '<input name="option_page" value="' . esc_attr( $name ) . '"><input name="_wpnonce" value="synthetic">'; }
function submit_button( $text ) { echo '<button>' . esc_html( $text ) . '</button>'; }
require_once __DIR__ . '/../includes/class-settings.php';
function profile_check( $ok, $label ) {
  ++$GLOBALS['checks'];
  if ( ! $ok ) { throw new RuntimeException( $label ); }
}
$base = array_fill_keys( array( 'programs', 'services', 'provider_model', 'staff_duties', 'employment_date_source', 'training_policy_reference', 'agency_requirements', 'effective_date' ), '' );
profile_check( array() === GHCA_ACD_Settings::get_agency_profile(), 'no implied agency profile' );
foreach ( array( 'Standard provider', 'Agency with choice', 'Supports coordination organization', 'Other / unresolved' ) as $model ) {
  foreach ( array( 'calendar_year', 'employee_start_date' ) as $cycle ) {
    $options[GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE] = $cycle;
    $input = array_merge( $base, array( 'programs' => 'ODP', 'provider_model' => $model, 'effective_date' => '2024-02-29', 'status' => 'approved', 'updated_by' => 1 ) );
    $saved = GHCA_ACD_Settings::sanitize_agency_profile( $input );
    profile_check( $model === $saved['provider_model'] && 'needs_review' === $saved['status'] && 90 === $saved['updated_by'], 'context not approval; server attribution' );
    profile_check( $cycle === GHCA_ACD_Settings::get_annual_cycle(), 'existing cycle preserved' );
    $options[GHCA_ACD_Settings::OPTION_AGENCY_PROFILE] = $saved;
    profile_check( $saved === GHCA_ACD_Settings::sanitize_agency_profile( $saved ), 'repeat sanitizer is idempotent' );
  }
}
$previous = $options[GHCA_ACD_Settings::OPTION_AGENCY_PROFILE];
foreach ( array( '2025-02-29', 'tomorrow', '2024-13-01', "2024-01-01\0", '0000-00-00' ) as $date ) {
  profile_check( $previous === GHCA_ACD_Settings::sanitize_agency_profile( array_merge( $base, array( 'effective_date' => $date ) ) ), 'invalid date preserves prior profile' );
}
foreach ( array( 'invalid', array(), array_merge( $base, array( 'services' => array() ) ), array_merge( $base, array( 'services' => str_repeat( 'a', 4001 ) ) ) ) as $bad ) {
  profile_check( $previous === GHCA_ACD_Settings::sanitize_agency_profile( $bad ), 'malformed or oversized submission preserves prior profile' );
}
profile_check( $previous === GHCA_ACD_Settings::sanitize_agency_profile( null ), 'omitted option preserved' );
$allowed = false;
profile_check( $previous === GHCA_ACD_Settings::sanitize_agency_profile( $base ), 'unauthorized change rejected' );
ob_start(); GHCA_ACD_Settings::render_agency_profile(); $html = ob_get_clean();
profile_check( '' === $html, 'unauthorized render denied' );
$allowed = true;
$clean = GHCA_ACD_Settings::sanitize_agency_profile( array_merge( $base, array( 'services' => '<b>Synthetic</b>' ) ) );
profile_check( 'Synthetic' === $clean['services'] && '' === $clean['effective_date'], 'plain text and unknown date supported' );
$options[GHCA_ACD_Settings::OPTION_AGENCY_PROFILE]['services'] = '</textarea><script>unsafe()</script>';
ob_start(); GHCA_ACD_Settings::render_agency_profile(); $html = ob_get_clean();
profile_check( false === strpos( $html, '<script>unsafe()' ) && false !== strpos( $html, '&lt;script&gt;' ), 'stored text escaped' );
foreach ( array( 'Agency Profile', 'name="_wpnonce"', 'action="options.php"', 'Needs review', 'ODP packets require a verified employment date', '[staff_duties]', '[agency_requirements]' ) as $expected ) {
  profile_check( false !== strpos( $html, $expected ), 'render contract: ' . $expected );
}
unset( $options[GHCA_ACD_Settings::OPTION_ANNUAL_CYCLE] );
ob_start(); GHCA_ACD_Settings::render_agency_profile(); $html = ob_get_clean();
profile_check( false !== strpos( $html, '<strong>Not configured</strong>' ), 'missing policy has no implied anniversary label' );
echo "PASS: $checks agency-profile checks (synthetic platform stubs and HTML, not browser verification)\n";
