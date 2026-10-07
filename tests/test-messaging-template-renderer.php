<?php
require __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $code;
		private string $message;
		public function __construct( string $code, string $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}

require_once __DIR__ . '/../includes/messaging/class-message-template-renderer.php';

$fails = 0;
function messaging_renderer_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$rendered = GHCA_ACD_Message_Template_Renderer::render(
	'Hello [employee_first_name], visit [portal_url] for [agency_name].',
	array(
		'employee_first_name' => 'Ada',
		'portal_url'          => 'https://academy.example/my-courses/',
		'agency_name'         => 'Gridhouse Digital',
	)
);
messaging_renderer_check( 'Hello Ada, visit https://academy.example/my-courses/ for Gridhouse Digital.' === $rendered, 'allowlisted placeholders render as plain values' );

$unknown = GHCA_ACD_Message_Template_Renderer::render( 'Hello [password_reset_token]', array() );
messaging_renderer_check( is_wp_error( $unknown ) && 'ghca_message_unknown_placeholder' === $unknown->get_error_code(), 'unknown placeholders fail closed' );

messaging_renderer_check( 'URGENT: Complete training' === GHCA_ACD_Message_Template_Renderer::apply_urgency_prefix( 'Complete training', 'urgent' ), 'urgent prefix is applied' );
messaging_renderer_check( 'URGENT: Complete training' === GHCA_ACD_Message_Template_Renderer::apply_urgency_prefix( 'URGENT: Complete training', 'urgent' ), 'urgent prefix is not duplicated' );
messaging_renderer_check( 'normal' === GHCA_ACD_Message_Template_Renderer::normalize_urgency( 'emergency' ), 'unknown urgency normalizes to normal' );
messaging_renderer_check( true === GHCA_ACD_Message_Template_Renderer::validate_lengths( 'Reminder', 'Please complete your training.' ), 'valid subject and body pass' );
messaging_renderer_check( is_wp_error( GHCA_ACD_Message_Template_Renderer::validate_lengths( '', 'Body' ) ), 'blank subject fails' );
messaging_renderer_check( is_wp_error( GHCA_ACD_Message_Template_Renderer::validate_lengths( 'Subject', '' ) ), 'blank body fails' );
messaging_renderer_check( is_wp_error( GHCA_ACD_Message_Template_Renderer::validate_lengths( str_repeat( 's', 201 ), 'Body' ) ), 'oversized subject fails' );
messaging_renderer_check( is_wp_error( GHCA_ACD_Message_Template_Renderer::validate_lengths( 'Subject', str_repeat( 'm', 4001 ) ) ), 'oversized body fails' );
$svc    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/messaging/class-messaging-service.php' );
$schema = (string) file_get_contents( dirname( __DIR__ ) . '/includes/messaging/class-messaging-schema.php' );
$js     = (string) file_get_contents( dirname( __DIR__ ) . '/assets/dashboard.js' );

/* -------------------------------------------------------------------------
 * Template picker: nothing is hidden without a stated reason
 * ---------------------------------------------------------------------- */
/* A template that fails to render must be reported, not silently dropped. */
messaging_renderer_check( ! preg_match( '/if \( is_wp_error\( \$subject \) \|\| is_wp_error\( \$message \) \) \{\s*continue;/', $svc ) && false !== strpos( $svc, '\'reason\'   => $unavailable' ), 'templates that fail to render are reported with a reason instead of dropped' );
/* The shipped defaults must cover Email and Email + SMS, or they vanish from
 * the picker the moment either of those methods is selected. */
messaging_renderer_check( false !== strpos( $schema, "'allowed_channels'   => 'email,email_sms'" ) && false === strpos( $schema, "'allowed_channels'   => 'email'," ), 'seeded default templates are allowed for Email and Email + SMS' );
/* Existing installs are repaired by a versioned migration, not left broken. */
messaging_renderer_check( false !== strpos( $schema, 'const VERSION        = 3' ) && false !== strpos( $schema, 'function repair_default_template_channels' ) && false !== strpos( $schema, 'self::repair_default_template_channels();' ), 'a schema migration widens the channels on already-seeded installs' );
/* The repair must not touch templates an admin has edited or restricted. */
messaging_renderer_check( false !== strpos( $schema, "WHERE allowed_channels = %s AND subject_template = %s" ) && false !== strpos( $schema, '$wpdb->prepare' ), 'the repair only matches untouched defaults and uses a prepared statement' );
/* The picker lists unusable templates as disabled options carrying the reason. */
messaging_renderer_check( false !== strpos( $js, 'option.disabled = true;' ) && false !== strpos( $js, "not enabled for this template" ) && false !== strpos( $js, 'function channelLabel(' ), 'the picker explains why a template is unavailable rather than hiding it' );
/* Auto-select and retained selection must both skip disabled entries. */
messaging_renderer_check( false !== strpos( $js, 'if (!templateInput.options[i].disabled) { firstUsable = i; break; }' ) && false !== strpos( $js, 'if (keep && !keep.disabled) {' ), 'template selection never lands on a disabled option' );

exit( $fails ? 1 : 0 );
