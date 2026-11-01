<?php
// GRI-94: plugin certificate download endpoint. Synthetic stubs only; no WordPress, LearnDash or network.
require __DIR__ . '/bootstrap.php';

function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
function __( $text, $domain = '' ) { return $text; }
$S = array( 'logged_in' => true, 'nonce_ok' => true, 'can_view' => true, 'can_docs' => true, 'can_user' => true, 'completed' => true, 'link' => 'https://example.test/certificates/c/?course_id=7&user=9&cert-nonce=abc', 'fetched' => array(), 'remote' => null, 'ld_admin' => true, 'ld_leader' => false, 'cert_id' => 55, 'uid' => 3, 'store' => array(), 'posted' => array(), 'render' => null, 'broker_remote' => null );
function is_user_logged_in() { return $GLOBALS['S']['logged_in']; }
function wp_verify_nonce( $nonce, $action ) { return $GLOBALS['S']['nonce_ok'] && 'good-nonce' === $nonce && 'ghca_acd_table' === $action; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function get_the_title( $id ) { return 'Recognizing & Reporting: Incidents/2026'; }
function get_post_type( $id ) { return 55 === (int) $id ? 'sfwd-certificates' : 'sfwd-courses'; }
function learndash_get_post_type_slug( $t ) { return 'certificate' === $t ? 'sfwd-certificates' : 'sfwd-courses'; }
function learndash_get_setting( $id, $key ) { return $GLOBALS['S']['cert_id']; }
function learndash_is_admin_user() { return $GLOBALS['S']['ld_admin']; }
function learndash_is_group_leader_user() { return $GLOBALS['S']['ld_leader']; }
function get_current_user_id() { return $GLOBALS['S']['uid']; }
function get_userdata( $id ) { return in_array( (int) $id, array( 9, 10 ), true ) ? (object) array( 'ID' => (int) $id ) : false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['S']['store'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['S']['store'][ $k ] ?? false; }
function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['S']['posted'][] = array( 'url' => $url, 'args' => $args );
	if ( null !== $GLOBALS['S']['broker_remote'] ) { return $GLOBALS['S']['broker_remote']; }
	// Stand-in for the internal request: no browser session, only the token body reaches the handler.
	$ok = GHCA_ACD_Certificate_Download::authorize_broker( $args['body'] ) > 0;
	return $ok ? $GLOBALS['S']['render'] : array( 'code' => 403, 'headers' => array( 'content-type' => 'text/plain; charset=utf-8' ), 'body' => 'Certificate request is unavailable.' );
}
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
require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';
require_once __DIR__ . '/../includes/class-data-provider.php';
require_once __DIR__ . '/../includes/class-certificate-download.php';

$fails = 0;
function dl_check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }
function pdf_remote(): array { return array( 'code' => 200, 'headers' => array( 'content-type' => 'application/pdf' ), 'body' => "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n" ); }
function reset_state(): void { $GLOBALS['S'] = array( 'logged_in' => true, 'nonce_ok' => true, 'can_view' => true, 'can_docs' => true, 'can_user' => true, 'completed' => true, 'link' => 'https://example.test/certificates/c/?course_id=7&user=9&cert-nonce=abc', 'fetched' => array(), 'remote' => pdf_remote(), 'ld_admin' => true, 'ld_leader' => false, 'cert_id' => 55, 'uid' => 3, 'store' => array(), 'posted' => array(), 'render' => pdf_remote(), 'broker_remote' => null ); }
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
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'course_id is required' );
reset_state(); $r = run( array( 'nonce' => 'good-nonce', 'user_id' => '9', 'quiz_id' => '5' ) );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'quiz_id alone is rejected (400), nothing fetched' );
reset_state(); $r = run( $req + array( 'quiz_id' => '5' ) );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'quiz_id together with course_id is rejected (400), nothing fetched' );
reset_state(); $r = run( $req + array( 'quiz_id' => '0' ) );
dl_check( 400 === $r['status'] && denied_without_fetch( $r ), 'a zero/empty quiz_id parameter is still rejected' );
reset_state(); $GLOBALS['S']['logged_in'] = false; $r = run( $req + array( 'quiz_id' => '5' ) );
dl_check( 403 === $r['status'] && denied_without_fetch( $r ), 'authorization is checked before quiz_id handling (unauthenticated stays 403)' );

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
dl_check( false === stripos( file_get_contents( __DIR__ . '/../includes/class-certificate-download.php' ), 'learndash_get_certificate_link' ), 'no quiz certificate path remains in the endpoint' );


// ---------------------------------------------------------------------------------------------
// Compliance Lead path: LearnDash would refuse her own cookie, so the plugin brokers the PDF.
// ---------------------------------------------------------------------------------------------
function lead_state(): void { reset_state(); $GLOBALS['S']['ld_admin'] = false; $GLOBALS['S']['uid'] = 3; }
function no_broker_activity(): bool { return array() === $GLOBALS['S']['posted'] && array() === $GLOBALS['S']['store']; }

lead_state(); $r = run( $req );
$post = $GLOBALS['S']['posted'][0]['args']['body'] ?? array();
dl_check( 200 === $r['status'] && 'application/pdf' === $r['headers']['Content-Type'] && 0 === strpos( $r['body'], '%PDF-' ), 'authorized Compliance Lead download succeeds through the broker' );
dl_check( 1 === count( $GLOBALS['S']['posted'] ) && array() === $GLOBALS['S']['fetched'], 'exactly one internal broker request; the LearnDash link is not fetched with her cookie' );
$opts = $GLOBALS['S']['posted'][0]['args'];
dl_check( ! isset( $opts['cookies'] ) && 0 === $opts['redirection'] && true === $opts['sslverify'] && GHCA_ACD_Certificate_Download::MAX_BYTES + 1 === $opts['limit_response_size'], 'internal request carries no browser cookie, no redirects, TLS verified, size-capped' );
dl_check( 'https://example.test/wp-admin/admin-ajax.php' === $GLOBALS['S']['posted'][0]['url'] && array( 'action', 'token', 'requester_id', 'user_id', 'course_id' ) === array_keys( $post ), 'internal request goes to same-origin admin-ajax with ids and token only (no certificate URL)' );
dl_check( 1 === preg_match( '/^[a-f0-9]{64}$/', $post['token'] ?? '' ) && 3 === (int) $post['requester_id'] && 9 === (int) $post['user_id'] && 7 === (int) $post['course_id'], 'token is 256-bit hex and the request is bound to requester 3, employee 9, course 7' );
$rec = reset( $GLOBALS['S']['store'] );
dl_check( 1 === count( $GLOBALS['S']['store'] ) && hash( 'sha256', $post['token'] ) === $rec['hash'] && false === strpos( serialize( $GLOBALS['S']['store'] ), $post['token'] ), 'only the SHA-256 of the token is stored, never the token itself' );
dl_check( true === $rec['used'] && 55 === $rec['certificate_id'] && 3 === $rec['requester'] && 9 === $rec['user_id'] && 7 === $rec['course_id'], 'record is bound to requester, employee, course and certificate and is marked used after the claim' );
dl_check( false === strpos( $r['body'], $post['token'] ) && false === strpos( serialize( $r['headers'] ), $post['token'] ), 'the token never appears in the response' );
$token_used = $post['token'];
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $post ), 'replaying the same token is denied' );

lead_state(); $GLOBALS['S']['can_user'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && no_broker_activity() && denied_without_fetch( $r ), 'cross-employee scope is denied: no token issued, no broker request' );
lead_state(); $r = run( array( 'user_id' => '10' ) + $req );
dl_check( 403 === $r['status'] && no_broker_activity(), 'a different out-of-scope employee is denied before any token exists' );
lead_state(); $GLOBALS['S']['can_view'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && no_broker_activity() && denied_without_fetch( $r ), 'a DSP (no dashboard access) is denied, no token issued' );
lead_state(); $GLOBALS['S']['can_docs'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && no_broker_activity(), 'a viewer without the documents permission is denied, no token issued' );
lead_state(); $GLOBALS['S']['logged_in'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && no_broker_activity(), 'unauthenticated request is denied, no token issued' );
lead_state(); $r = run( array( 'nonce' => 'bad' ) + $req );
dl_check( 403 === $r['status'] && no_broker_activity(), 'bad nonce is denied, no token issued' );

lead_state(); $GLOBALS['S']['cert_id'] = 0; $GLOBALS['S']['link'] = ''; $r = run( $req );
dl_check( 404 === $r['status'] && no_broker_activity() && false === strpos( $r['body'], '%PDF' ), 'no certificate at all: 404, no token, no broker request' );
// Stored (Uncanny) certificate PDF: no LearnDash certificate post, so no broker; validated direct fetch.
lead_state(); $GLOBALS['S']['cert_id'] = 0; $GLOBALS['S']['link'] = 'https://example.test/wp-content/uploads/uo/cert-7-9.pdf'; $r = run( $req );
dl_check( 200 === $r['status'] && 'application/pdf' === $r['headers']['Content-Type'] && array( 'https://example.test/wp-content/uploads/uo/cert-7-9.pdf' ) === $GLOBALS['S']['fetched'] && no_broker_activity(), 'stored certificate PDF without a LearnDash certificate post downloads via the direct fetch, no broker' );
lead_state(); $GLOBALS['S']['cert_id'] = 0; $GLOBALS['S']['link'] = 'https://example.test/wp-content/uploads/uo/cert-7-9.pdf'; $GLOBALS['S']['remote'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<html>login</html>' ); $r = run( $req );
dl_check( 404 === $r['status'] && false === strpos( $r['body'], '<html' ) && no_broker_activity(), 'a stored-PDF link that answers HTML is still refused' );
lead_state(); $GLOBALS['S']['cert_id'] = 0; $GLOBALS['S']['link'] = 'https://example.test/wp-content/uploads/uo/cert-7-9.pdf'; $GLOBALS['S']['can_user'] = false; $r = run( $req );
dl_check( 403 === $r['status'] && array() === $GLOBALS['S']['fetched'], 'the stored-PDF path is still behind the scope check' );
lead_state(); $GLOBALS['S']['completed'] = false; $r = run( $req );
dl_check( 404 === $r['status'] && no_broker_activity(), 'incomplete course: 404, no token, no broker request' );

lead_state(); $GLOBALS['S']['render'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ), 'body' => 'Access to certificate page is disallowed.' ); $r = run( $req );
dl_check( 404 === $r['status'] && 'text/plain; charset=utf-8' === $r['headers']['Content-Type'] && false === strpos( $r['body'], 'Access to certificate' ), 'an HTML answer from the broker is refused, never returned as a PDF' );
lead_state(); $GLOBALS['S']['broker_remote'] = array( 'code' => 200, 'headers' => array( 'content-type' => 'application/pdf' ), 'body' => '<!DOCTYPE html><html>dashboard</html>' ); $r = run( $req );
dl_check( 404 === $r['status'] && 'text/plain; charset=utf-8' === $r['headers']['Content-Type'], 'HTML behind an application/pdf header is refused' );
lead_state(); $GLOBALS['S']['broker_remote'] = new WP_Error(); $r = run( $req );
dl_check( 502 === $r['status'] && false === strpos( $r['body'], '%PDF' ), 'broker transport error: 502, no PDF' );
lead_state(); $GLOBALS['S']['broker_remote'] = array( 'code' => 403, 'headers' => array( 'content-type' => 'text/plain; charset=utf-8' ), 'body' => 'Certificate request is unavailable.' ); $r = run( $req );
dl_check( 404 === $r['status'] && 'Certificate could not be downloaded.' === $r['body'], 'a refused broker request yields the generic error and no private details' );

lead_state(); $r = run( $req + array( 'quiz_id' => '5' ) );
dl_check( 400 === $r['status'] && no_broker_activity(), 'quiz_id stays rejected (400) on the broker path, no token issued' );

// Token binding, expiry and single use (employee 9, course 7, certificate 55, requester 3).
$S['store'] = array(); $T0 = 1000000;
$tok = GHCA_ACD_Certificate_Download::issue_token( 3, 9, 7, 55, $T0 );
$p = function ( array $o = array() ) use ( $tok ) { return array_merge( array( 'token' => $tok, 'requester_id' => 3, 'user_id' => 9, 'course_id' => 7 ), $o ); };
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p(), $T0 + GHCA_ACD_Certificate_Download::TOKEN_TTL + 1 ), 'an expired token is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p( array( 'requester_id' => 4 ) ), $T0 + 5 ), 'token presented for another requester is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p( array( 'user_id' => 10 ) ), $T0 + 5 ), 'token presented for another employee is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p( array( 'course_id' => 8 ) ), $T0 + 5 ), 'token presented for another course is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p( array( 'token' => str_repeat( 'a', 64 ) ) ), $T0 + 5 ), 'an unknown token is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p( array( 'token' => 'short' ) ), $T0 + 5 ), 'a malformed token is denied' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( array(), $T0 + 5 ), 'an empty request is denied' );
dl_check( 55 === GHCA_ACD_Certificate_Download::authorize_broker( $p(), $T0 + 5 ), 'the right token with the right ids is accepted once, within the TTL' );
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p(), $T0 + 6 ), 'and cannot be replayed' );
$S['store'] = array(); $tok = GHCA_ACD_Certificate_Download::issue_token( 3, 9, 7, 55, $T0 );
$S['cert_id'] = 56;
dl_check( 0 === GHCA_ACD_Certificate_Download::authorize_broker( $p(), $T0 + 5 ), 'a certificate that changed since the token was issued is denied' );
$S['cert_id'] = 55;

dl_check( 'expired' === GHCA_ACD_Certificate_Download::validate_token_record( array( 'hash' => hash( 'sha256', str_repeat( 'b', 64 ) ), 'requester' => 3, 'user_id' => 9, 'course_id' => 7, 'certificate_id' => 55, 'created' => $T0, 'used' => false ), str_repeat( 'b', 64 ), 3, 9, 7, $T0 + 61 ), 'validate_token_record: 61 seconds old is expired (TTL 60)' );
dl_check( 'not_found' === GHCA_ACD_Certificate_Download::validate_token_record( false, str_repeat( 'b', 64 ), 3, 9, 7, $T0 ), 'validate_token_record: missing record is not_found' );

// Admin and group leader keep the direct fetch; no broker involved.
reset_state(); $r = run( $req );
dl_check( 200 === $r['status'] && array( $GLOBALS['S']['link'] ) === $GLOBALS['S']['fetched'] && no_broker_activity(), 'LearnDash administrator download still works through the direct fetch, no broker' );
reset_state(); $GLOBALS['S']['ld_admin'] = false; $GLOBALS['S']['ld_leader'] = true; $r = run( $req );
dl_check( 200 === $r['status'] && array( $GLOBALS['S']['link'] ) === $GLOBALS['S']['fetched'] && no_broker_activity(), 'group leader download still works through the direct fetch, no broker' );

$src = file_get_contents( __DIR__ . '/../includes/class-certificate-download.php' );
dl_check( false !== strpos( $src, "'POST' === ( \$_SERVER['REQUEST_METHOD'] ?? '' )" ) && false !== strpos( $src, "'wp_ajax_nopriv_' . self::BROKER_ACTION" ), 'broker endpoint is POST-only and reachable without a session (token is the credential)' );
dl_check( false === strpos( $src, 'add_cap' ) && false === strpos( $src, 'set_role' ) && false === strpos( $src, 'add_role' ), 'no role or capability is granted anywhere' );
$pdfsrc = file_get_contents( __DIR__ . '/../includes/class-audit-pdf.php' );
dl_check( substr_count( $pdfsrc, 'self::serve_certificate_pdf(' ) === 1 && false !== strpos( $pdfsrc, 'public static function serve_certificate_pdf(' ), 'the packet broker and the download broker share one LearnDash render path' );

// Unusable private storage must deny cleanly (kept last: the constant affects every later lock in this process).
$S['store'] = array(); $tok = GHCA_ACD_Certificate_Download::issue_token( 3, 9, 7, 55, $T0 );
define( 'GHCA_ACD_PRIVATE_DIR', '/nonexistent/ghca-private-dir' );
$threw = false; $claimed = -1;
try { $claimed = GHCA_ACD_Certificate_Download::claim_token( $tok, 3, 9, 7, $T0 + 5 ); } catch ( Throwable $e ) { $threw = true; }
dl_check( ! $threw && 0 === $claimed, 'unusable private storage denies the claim (returns 0) instead of throwing' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
