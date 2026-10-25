<?php
// A certificate whose content stream is NOT over the F-006 size limits but cannot be decoded (corrupt Flate data)
// must stop the packet: sanitized error, no partial packet, job folder removed, no paths or employee data in the
// message. Drives the real GHCA_Audit_PDF::ajax_merge with synthetic data only (no Jotform, no real records).
// Needs TCPDF (LearnDash bundles it, so it is not vendored): GHCA_TEST_TCPDF_PATH=/path/to/tcpdf.php php tests/test-fpdi-corrupt-stream-abort.php
// Without it this prints SKIP and exits 0. The same decisions run in the default suite, without TCPDF, in
// tests/test-fpdi-evidence-integrity.php.
$tcpdf = getenv( 'GHCA_TEST_TCPDF_PATH' );
if ( ! $tcpdf || ! is_file( $tcpdf ) ) { echo "SKIP: set GHCA_TEST_TCPDF_PATH to a tcpdf.php to run the corrupt-stream packet abort check\n"; exit( 0 ); }

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
date_default_timezone_set( 'UTC' );
require __DIR__ . '/fpdi-fixtures.php';

// load_libs() expects LearnDash's bundled TCPDF under WP_PLUGIN_DIR; point it at the supplied copy.
$plugin_dir = sys_get_temp_dir() . '/ghca-corrupt-plugins-' . getmypid();
mkdir( $plugin_dir . '/sfwd-lms/includes/lib/tcpdf', 0700, true );
file_put_contents( $plugin_dir . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php', '<?php require ' . var_export( $tcpdf, true ) . ';' );
define( 'WP_PLUGIN_DIR', $plugin_dir );
$private_dir = sys_get_temp_dir() . '/ghca-corrupt-private-' . getmypid();
mkdir( $private_dir, 0700 );
define( 'GHCA_ACD_PRIVATE_DIR', $private_dir );
define( 'GHCA_ACD_PRIVATE_DIR_ACL_VERIFIED', true );

$fixture = array( 'meta' => array(), 'completed' => array(), 'options' => array(), 'transients' => array() );
class AbortResponse extends RuntimeException { public $success; public $data; public $status; public function __construct( $s, $d, $st ) { $this->success = $s; $this->data = $d; $this->status = $st; } }
function wp_send_json_error( $data = null, $status = 200 ) { throw new AbortResponse( false, $data, $status ); }
function wp_send_json_success( $data = null, $status = 200 ) { throw new AbortResponse( true, $data, $status ); }
function check_ajax_referer( $a, $b ) {}
function is_user_logged_in() { return true; }
function get_current_user_id() { return 90; }
function sanitize_text_field( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', $v ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
function get_transient( $k ) { return $GLOBALS['fixture']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['fixture']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['fixture']['transients'][ $k ] ); return true; }
function __( $v, $d = '' ) { return $v; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $p = '' ) { return 'https://example.invalid/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce'; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $v, $d = '' ) { return esc_html( $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { private $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function get_userdata( $id ) { return (object) array( 'user_registered' => '2025-01-01 00:00:00', 'roles' => array( 'synthetic_staff' ) ); }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['fixture']['meta'][ $key ] ?? ''; }
function learndash_course_completed( $id, $course ) { return $GLOBALS['fixture']['completed'][ $course ] ?? false; }
function learndash_user_get_course_completed_date( $id, $course ) { return 0; }
function learndash_user_get_enrolled_courses( $id ) { return array( 900001 ); }
function apply_filters( $n, $v ) { return $v; }
function get_option( $n, $d = false ) { return $GLOBALS['fixture']['options'][ $n ] ?? $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function get_post_meta( $id, $k, $s ) { return ''; }
function get_post_field( $f, $id ) { return ''; }
function get_the_title( $id ) { return 'Synthetic Course ' . $id; }
function wp_specialchars_decode( $v, $f ) { return htmlspecialchars_decode( $v, $f ); }
$wp_roles = (object) array( 'roles' => array( 'synthetic_staff' => array( 'name' => 'Synthetic Staff' ) ) );
class GHCA_Dashboard_Branding { public static function get_pdf_logo_source() { return ''; } public static function get_org_name() { return 'Synthetic Agency'; } }
class GHCA_ACD_Jotform_Repository { public static function approved_courses( $id ) { return array(); } public static function catalog( $a = false ) { return array(); } }
class GHCA_ACD_Roles { public static function user_can_view() { return true; } public static function user_can_view_employee_documents() { return true; } }
class GHCA_ACD_User_Report { public static function can_view_user( $id ) { return 900001 === $id; } }
class GHCA_ACD_Data_Provider { public static function get_employees_for_current_view() { return array( array( 'user_id' => 900001, 'name' => 'Corrupt Stream Employee' ) ); } public static function sanitize_certificate_url( $u ) { return $u; } }
require __DIR__ . '/../includes/class-audit-calculator.php';
require __DIR__ . '/../includes/class-audit-pdf-jobs.php';
require __DIR__ . '/../includes/class-audit-pdf.php';

$fails = 0;
function ca_check( bool $ok, string $label ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $fails++; }

$fixture['meta']['ghca_acd_verified_employment'] = array( 'date' => '2025-01-01', 'source' => 'Synthetic HR reference', 'verified_by' => 90, 'verified_at' => time() );
$fixture['options']['ghca_acd_annual_cycle'] = 'employee_start_date';
$fixture['options']['ghca_acd_audit_mapping'] = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ) );
$fixture['completed'][900001] = true;
$fixture['meta']['course_completed_900001'] = strtotime( '2025-01-20 12:00:00 UTC' );

$garbage = "\x78\x9c" . str_repeat( "\xde\xad\xbe\xef", 64 );   // far below every size limit, but not decodable
$big     = gzcompress( str_repeat( "\0", 33 * 1024 * 1024 ), 9 ); // small on disk, 33 MiB decoded (> 32 MiB F-006 cap)
$valid   = gzcompress( 'BT /F1 12 Tf 10 10 Td (Synthetic) Tj ET', 9 );
$stub    = '<< /Length 3 >>' . "\nstream\nq Q\nendstream";
$single  = static function ( string $stream_object ): string { $o = base_objects(); $o[4] = $stream_object; return build_pdf( $o ); };
ca_check( strlen( $single( flate_stream_object( $garbage ) ) ) < 4096, 'corrupt fixture is far below every size limit' );

function merge_with_certificate( string $pdf_bytes ): AbortResponse {
	$job_id = GHCA_Audit_PDF_Jobs::create_job( 90, 900001, 'orientation', array( 'https://example.invalid/synthetic-certificate' ), 'packet.pdf', array( 900001 ), array( false ), array( 'learndash:900001' ), array( null ) );
	file_put_contents( GHCA_Audit_PDF_Jobs::cert_path( $job_id, 0 ), $pdf_bytes );
	$GLOBALS['last_job_id'] = $job_id;
	$_POST = $_REQUEST = array( 'job_id' => $job_id );
	try { GHCA_Audit_PDF::ajax_merge(); } catch ( AbortResponse $r ) { return $r; }
	throw new RuntimeException( 'ajax_merge sent no response' );
}

// Controls: valid certificates still merge, so every abort below is caused by the stream and nothing else.
foreach ( array( 'single Flate content stream' => $single( flate_stream_object( $valid ) ), 'two content streams' => build_two_stream_pdf( flate_stream_object( $valid ), $stub ), 'plain content stream' => build_pdf( base_objects() ) ) as $label => $pdf ) {
	$ok = merge_with_certificate( $pdf );
	ca_check( $ok->success && ! empty( $ok->data['download_url'] ), "control: valid certificate ($label) merges into a packet" );
	GHCA_Audit_PDF_Jobs::delete_job( $GLOBALS['last_job_id'] );
}

$bad = array(
	'one corrupt stream'                  => $single( flate_stream_object( $garbage ) ),
	'one over-limit stream'               => $single( flate_stream_object( $big ) ),
	'two streams, one corrupt'            => build_two_stream_pdf( flate_stream_object( $garbage ), $stub ),
	'two streams, second one corrupt'     => build_two_stream_pdf( $stub, flate_stream_object( $garbage ) ),
	'two streams, one over-limit'         => build_two_stream_pdf( flate_stream_object( $big ), $stub ),
	'two streams, second one over-limit'  => build_two_stream_pdf( $stub, flate_stream_object( $big ) ),
);
foreach ( $bad as $label => $pdf ) {
	$response = merge_with_certificate( $pdf );
	$job_id   = $GLOBALS['last_job_id'];
	$message  = (string) ( $response->data['message'] ?? '' );
	ca_check( ! $response->success, "$label: the whole packet job is aborted" );
	ca_check( 1 === preg_match( '/^(?:A certificate|Certificate 1) was missing or unreadable at merge time\. Packet generation was aborted .{1,3} no partial packet was created\.$/u', $message ), "$label: the error is the fixed, sanitized message" );
	ca_check( ! is_file( GHCA_Audit_PDF_Jobs::packet_path( $job_id ) ), "$label: no packet file is left on disk (nothing to download)" );
	ca_check( ! is_dir( GHCA_Audit_PDF_Jobs::temp_dir( $job_id ) ) && ! file_exists( GHCA_Audit_PDF_Jobs::cert_path( $job_id, 0 ) ), "$label: the job folder and certificate are removed" );
	ca_check( false === get_transient( GHCA_Audit_PDF_Jobs::TRANSIENT_PREFIX . $job_id ), "$label: the job record is removed" );
	$stray = array_filter( array_diff( scandir( dirname( GHCA_Audit_PDF_Jobs::packet_path( $job_id ) ) ), array( '.', '..' ) ), static function ( $n ) { return '.pdf' === substr( $n, -4 ); } );
	ca_check( array() === $stray, "$label: no packet PDF of any kind remains in the packet store" );
	$haystack = $message . json_encode( $response->data );
	$needles  = array( $private_dir, sys_get_temp_dir(), $plugin_dir, $job_id, 'ghca_compliance', 'Corrupt', 'Stream Employee', '900001', 'example.invalid', 'Flate', 'FPDI', 'setasign', '.pdf' );
	$leaks    = array_filter( $needles, static function ( $n ) use ( $haystack ) { return '' !== $n && false !== stripos( $haystack, $n ); } );
	ca_check( array() === $leaks, "$label: the error contains no path, job id, employee name/id, URL or library detail" . ( $leaks ? ': ' . implode( ', ', $leaks ) : '' ) );
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
