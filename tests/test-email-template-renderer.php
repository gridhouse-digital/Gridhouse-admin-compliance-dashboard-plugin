<?php
require __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, array $protocols = array() ): string {
		$scheme = (string) parse_url( $url, PHP_URL_SCHEME );
		return empty( $protocols ) || in_array( $scheme, $protocols, true ) ? filter_var( $url, FILTER_SANITIZE_URL ) : '';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( string $text, int $quote_style = ENT_NOQUOTES ): string { return htmlspecialchars_decode( $text, $quote_style ); }
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( string $color ): string { return preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? strtolower( $color ) : ''; }
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) { return (object) array( 'ID' => $user_id, 'display_name' => 'Ada Employee' ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key, bool $single = false ): string { return 'Ada'; }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ): bool { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
}

final class GHCA_Dashboard_Branding {
	public static string $logo = 'https://academy.example/logo.png';
	public static function get(): array { return array( 'primary' => '#176cad' ); }
	public static function get_org_name(): string { return 'Care &amp; Training'; }
	public static function get_support_email(): string { return 'support@example.test'; }
	public static function get_logo_url(): string { return self::$logo; }
}
final class GHCA_ACD_Settings {
	public static function get_reminder_button_label(): string { return 'View Training Dashboard'; }
	public static function get_reminder_footer_text(): string { return 'Employee compliance notification.'; }
}
final class GHCA_ACD_Data_Provider {
	public static function get_page_url( string $key, string $fallback ): string { return 'https://academy.example/my-courses/'; }
}

require_once __DIR__ . '/../includes/messaging/class-message-template-renderer.php';
require_once __DIR__ . '/../includes/messaging/class-email-template-renderer.php';

$fails = 0;
function email_template_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$rendered = GHCA_ACD_Email_Template_Renderer::render(
	array(
		'employee_user_id' => 9,
		'urgency'          => 'urgent',
		'message'          => "Complete training.\n<script>alert(1)</script>",
	)
);

email_template_check( false !== strpos( $rendered['html'], 'https://academy.example/logo.png' ), 'configured HTTPS agency logo is included' );
email_template_check( false !== strpos( $rendered['html'], 'Care &amp; Training' ) && false === strpos( $rendered['html'], 'Care &amp;amp; Training' ), 'encoded organization title is decoded once and safely escaped' );
email_template_check( false !== strpos( $rendered['html'], 'Urgent - Training Reminder' ), 'urgency is communicated with text as well as color' );
email_template_check( false === strpos( $rendered['html'], '<script>' ) && false !== strpos( $rendered['html'], '&lt;script&gt;' ), 'plain-text message content cannot inject active HTML' );
email_template_check( false !== strpos( $rendered['html'], 'https://academy.example/my-courses/' ) && false !== strpos( $rendered['html'], 'View Training Dashboard' ), 'fixed portal URL and configured CTA label are rendered' );
email_template_check( false !== strpos( $rendered['text'], '<script>alert(1)</script>' ) && false !== strpos( $rendered['text'], 'https://academy.example/my-courses/' ), 'plain-text alternative preserves readable content and portal URL' );
email_template_check( false === stripos( $rendered['html'], '<form' ) && false === stripos( $rendered['html'], 'javascript:' ), 'email shell contains no forms or JavaScript URLs' );

GHCA_Dashboard_Branding::$logo = 'http://academy.example/logo.png';
$without_insecure_logo = GHCA_ACD_Email_Template_Renderer::render( array( 'employee_user_id' => 9, 'urgency' => 'normal', 'message' => 'Reminder' ) );
email_template_check( false === strpos( $without_insecure_logo['html'], 'http://academy.example/logo.png' ) && false !== strpos( $without_insecure_logo['html'], 'Care &amp; Training' ), 'non-HTTPS logo is omitted without removing text branding' );

GHCA_Dashboard_Branding::$logo = 'https://user:password@academy.example/logo.png';
$without_credentials = GHCA_ACD_Email_Template_Renderer::render( array( 'employee_user_id' => 9, 'urgency' => 'urgent', 'message' => 'URGENT: Complete training.' ) );
email_template_check( false === strpos( $without_credentials['html'], 'user:password' ), 'credential-bearing logo URLs are rejected' );
email_template_check( false !== strpos( $without_credentials['html'], 'Urgent - Training Reminder' ) && false === strpos( $without_credentials['html'], 'URGENT: Complete training.' ), 'urgency banner does not duplicate a stored combined-channel urgency prefix' );

exit( $fails ? 1 : 0 );
