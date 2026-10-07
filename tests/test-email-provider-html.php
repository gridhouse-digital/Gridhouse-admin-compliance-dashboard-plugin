<?php
require __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $code;
		private string $message;
		public function __construct( string $code, string $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) { return (object) array( 'ID' => $user_id, 'user_email' => 'employee@example.test' ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string { return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) { return 'admin_email' === $name ? 'admin@example.test' : $default; }
}

$GLOBALS['ghca_email_hooks'] = array();
$GLOBALS['ghca_email_mail']  = array();
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ghca_email_hooks'][ $hook ][ $priority ] = $callback;
}
function remove_action( string $hook, $callback, int $priority = 10 ): void {
	if ( isset( $GLOBALS['ghca_email_hooks'][ $hook ][ $priority ] ) && $GLOBALS['ghca_email_hooks'][ $hook ][ $priority ] === $callback ) {
		unset( $GLOBALS['ghca_email_hooks'][ $hook ][ $priority ] );
	}
}
function wp_mail( string $to, string $subject, string $message, array $headers ): bool {
	$mailer = (object) array( 'AltBody' => '' );
	foreach ( (array) ( $GLOBALS['ghca_email_hooks']['phpmailer_init'] ?? array() ) as $callback ) {
		$callback( $mailer );
	}
	$GLOBALS['ghca_email_mail'] = compact( 'to', 'subject', 'message', 'headers', 'mailer' );
	return true;
}

final class GHCA_ACD_Settings {
	public static bool $html = true;
	public static function reminder_email_enabled(): bool { return true; }
	public static function reminder_html_enabled(): bool { return self::$html; }
	public static function get_reminder_from_name(): string { return 'Gridhouse Digital'; }
	public static function get_reminder_reply_to(): string { return 'support@example.test'; }
}
final class GHCA_ACD_Email_Template_Renderer {
	public static function render( array $delivery ): array { return array( 'html' => '<html><body>Branded</body></html>', 'text' => 'Plain alternative' ); }
}

require_once __DIR__ . '/../includes/messaging/class-email-provider.php';

$fails = 0;
function email_provider_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$delivery = array( 'employee_user_id' => 3, 'subject' => 'Reminder', 'message' => 'Original plain message' );
$html_result = GHCA_ACD_Email_Provider::send( $delivery );
email_provider_check( true === $html_result && in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['ghca_email_mail']['headers'], true ), 'HTML-enabled delivery uses the branded HTML content type' );
email_provider_check( 'Plain alternative' === $GLOBALS['ghca_email_mail']['mailer']->AltBody, 'one-call PHPMailer hook supplies the plain-text alternative' );
email_provider_check( empty( $GLOBALS['ghca_email_hooks']['phpmailer_init'] ), 'plain-text hook is removed immediately after wp_mail returns' );

GHCA_ACD_Settings::$html = false;
$plain_result = GHCA_ACD_Email_Provider::send( $delivery );
email_provider_check( true === $plain_result && in_array( 'Content-Type: text/plain; charset=UTF-8', $GLOBALS['ghca_email_mail']['headers'], true ), 'HTML can be disabled without changing the transport workflow' );
email_provider_check( 'Original plain message' === $GLOBALS['ghca_email_mail']['message'] && '' === $GLOBALS['ghca_email_mail']['mailer']->AltBody, 'plain mode sends only the stored text snapshot' );

exit( $fails ? 1 : 0 );
