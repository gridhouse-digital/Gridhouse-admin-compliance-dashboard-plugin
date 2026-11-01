<?php
// GRI-94: plugin certificate download endpoint. Synthetic stubs only; no WordPress, LearnDash or network.
require __DIR__ . '/bootstrap.php';

$S = array( 'logged_in' => true, 'nonce_ok' => true, 'can_view' => true, 'can_docs' => true, 'can_user' => true, 'completed' => true, 'link' => 'https://example.test/certificates/c/?course_id=7&user=9&cert-nonce=abc', 'fetched' => array(), 'remote' => null );
function is_user_logged_in() { return $GLOBALS['S']['logged_in']; }
function wp_verify_nonce( $nonce, $action ) { return $GLOBALS['S']['nonce_ok'] && 'good-nonce' === $nonce && 'ghca_acd_table' === $action; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function get_the_title( $id ) { return 'Recognizing & Reporting: Incidents/2026'; }
function get_post_type( $id ) { return 'sfwd-courses'; }
function learndash_get_post_type_slug( $t ) { return 'course' === $t ? 'sfwd-courses' : 'sfwd-quiz'; }
function learndash_course_completed( $u, $c ) { return $GLOBALS['S']['completed']; }
function learndash_get_course_certificate_link( $c, $u ) { return $GLOBALS['S']['link']; }
function get_user_meta( $u, $k, $s ) { return ''; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'good-nonce'; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_safe_remote_get( $url, $args = array() ) { $GLOBALS['S']['fetched'][] = $url; return $GLOBALS['S']['remote']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
class WP_Error {}
class WP_Http_Cookie { public function __construct( $a ) {} }
class GHCA_ACD_Roles {
	public static function user_can_view(): bool { return $GLOBALS['S']['can_view']; }
	public static function user_can_view_employee_documents(): bool { return $GLOBALS['S']['can_docs']; }
}
class GHCA_ACD_User_Report { public static function can_view_user( int $id ): bool { return $GLOBALS['S']['can_user'] && 9 === $id; } }
require_once __DIR__ . '/../includes/class-data-provider.php';
require_once __DIR__ . '/../includes/class-certificate-download.php';

$fails = 0;
function dl_check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }
function pdf_remote(): array { return array( 'code' => 200, 'headers' => array( 'content-type' => 'application/pdf' ), 'body' => "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n" ); }
function reset_state(): void { $GLOBALS['S'] = array( 'logged_in' => true, 'nonce_ok' => true, 'can_view' => true, 'can_docs' => true, 'can_user' => true, 'completed' => true, 'link' => 'https://example.test/certificates/c/?course_id=7&user=9&cert-nonce=abc', 'fetched' => array(), 'remote' => pdf_remote() ); }
$req = array( 'nonce' => 'good-nonce', 'user_id' => '9', 'course_id' => '7' );
function run( array $req ): array { return GHCA_ACD_Certificate_Download::process( $req ); }
function denied_without_fetch( array $r ): bool { return 'text/plain; charset=utf-8' === $r['headers']['Content-Type'] && 0 === strpos( $r['body'], 'Certificate could not' ) && false === strpos( $r['body'], '%PDF' ) && array() === $GLOBALS['S']['fetched']; }

reset_state(); $GLOBALS['S']['logged_in'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'unauthenticated request is denied (403), nothing fetched' );

reset_state(); $r = run( array( 'user_id' => '9', 'course_id' => '7' ) );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'missing nonce is denied' );
reset_state(); $r = run( array( 'nonce' => 'bad-nonce' ) + $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'bad nonce is denied' );

reset_state(); $GLOBALS['S']['can_view'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'non-admin (no dashboard access) is denied' );
reset_state(); $GLOBALS['S']['can_docs'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'viewer without the employee-documents permission is denied' );
reset_state(); $GLOBALS['S']['can_user'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'employee outside the viewer scope is denied' );
reset_state(); $r = run( array( 'user_id' => '10', 'course_id' => '7' ) + $req );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'a different, out-of-scope user_id is denied' );

reset_state(); $r = run( array( 'user_id' => '0' ) + $req );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'invalid user id is rejected' );
reset_state(); $r = run( array( 'nonce' => 'good-nonce', 'user_id' => '9' ) );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'course_id or quiz_id is required' );
reset_state(); $r = run( $req + array( 'quiz_id' => '5' ) );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'course_id and quiz_id together are rejected' );

reset_state(); $GLOBALS['S']['completed'] = false; $r = run( $req );
dl_check( 404 === $r['status'] && denied_without_fetch( $r ), 'incomplete course: 404, no PDF' );
reset_state(); $GLOBALS['S']['link'] = ''; $r = run( $req );
dl_check( 404 === $r['status'] && denied_without_fetch( $r ), 'no certificate: 404, no PDF' );

reset_state(); $GLOBALS['S']['remote'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => 'Access to certificate page is disallowed.' ); $r = run( $req );
dl_check( 404 === $r['status'] && 'text/plain; charset=utf-8' === $r['headers']['Content-Type'] && false === strpos( $r['body'], 'Access to certificate' ), '200 text/html from LearnDash is never streamed as a PDF' );
reset_state(); $GLOBALS['S']['remote'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'application/pdf' ), 'body' => '<!DOCTYPE html><html>dashboard</html>' ); $r = run( $req );
dl_check( 404 === $r['status'] && 'text/plain; charset=utf-8' === $r['headers']['Content-Type'], 'HTML body behind a PDF header is refused' );
reset_state(); $GLOBALS['S']['remote'] = array( 'code' => 403, 'headers' => array( 'content-type' => 'application/pdf' ), 'body' => "%PDF-1.7\n" ); $r = run( $req );
dl_check( 404 === $r['status'], 'non-200 LearnDash answer is refused' );
reset_state(); $GLOBALS['S']['remote'] = new WP_Error(); $r = run( $req );
dl_check( 502 === $r['status'] && 'text/plain; charset=utf-8' === $r['headers']['Content-Type'], 'transport error yields 502 and no PDF' );

reset_state(); $r = run( $req + array( 'url' => 'https://evil.example/steal', 'cert_url' => 'http://169.254.169.254/' ) );
dl_check( 200 === $r['status'] && array( $GLOBALS['S']['link'] ) === $GLOBALS['S']['fetched'], 'request-supplied URLs are ignored; only the server-derived LearnDash link is fetched' );
reset_state(); $GLOBALS['S']['link'] = 'https://evil.example/certificates/c/'; $r = run( $req );
dl_check( 404 === $r['status'] && array() === $GLOBALS['S']['fetched'], 'a link on another host is never fetched (same-origin only)' );

reset_state(); $r = run( $req );
dl_check( 200 === $r['status'] && 'application/pdf' === $r['headers']['Content-Type'], 'valid admin gets 200 application/pdf' );
dl_check( 1 === preg_match( '/^attachment; filename="[A-Za-z0-9._-]+\.pdf"$/', $r['headers']['Content-Disposition'] ), 'Content-Disposition is an attachment with a safe .pdf filename' );
dl_check( 'attachment; filename="Recognizing-Reporting-Incidents-2026.pdf"' === $r['headers']['Content-Disposition'], 'filename comes from the course title, sanitized' );
dl_check( 0 === strpos( $r['body'], '%PDF-' ) && (string) strlen( $r['body'] ) === $r['headers']['Content-Length'] && 'nosniff' === $r['headers']['X-Content-Type-Options'], 'body is the PDF with matching length and nosniff' );

dl_check( 'etc-passwd.pdf' === GHCA_ACD_Certificate_Download::safe_filename( '../../etc/passwd/..' ), 'path traversal characters never survive in the filename' );
dl_check( 'certificate.pdf' === GHCA_ACD_Certificate_Download::safe_filename( "\"\r\n" ), 'empty/hostile titles fall back to certificate.pdf' );
dl_check( false === GHCA_ACD_Certificate_Download::is_pdf_response( 200, 'application/pdf', '%PDF-' ) && false === GHCA_ACD_Certificate_Download::is_pdf_response( 200, 'application/pdf', '%PDF-1.7' . str_repeat( 'a', GHCA_ACD_Certificate_Download::MAX_BYTES ) ), 'empty and oversized PDFs are refused' );

$url = GHCA_ACD_Certificate_Download::download_url( 9, 7 );
dl_check( false !== strpos( $url, 'action=ghca_acd_certificate_download' ) && false !== strpos( $url, 'user_id=9' ) && false !== strpos( $url, 'course_id=7' ) && false === strpos( $url, 'certificates' ), 'drawer URL carries ids and a nonce, never a certificate link' );
$handlers = file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
dl_check( false !== strpos( $handlers, 'data-ghca-cert-download-url="<?php echo esc_url( GHCA_ACD_Certificate_Download::download_url(' ), 'drawer certificate button points Download at the plugin endpoint' );
dl_check( false !== strpos( $handlers, 'GHCA_ACD_Certificate_Download::init();' ), 'endpoint is registered on init' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
