<?php
/** Focused 1.6.3 source-routing, ownership-bind and review-placement contract checks. */

define( 'ABSPATH', __DIR__ . '/' );
function __( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return str_replace( ' ', '-', basename( (string) $value ) ); }
function remove_accents( $value ) { return (string) $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/unrelated-uploads' ); }
final class WP_Error { private $code; public function __construct( $code = '', $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['status'] ?? 0 ); }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ strtolower( $name ) ] ?? ''; }
function wp_safe_remote_post( $url, $args ) { global $drive_mock; return array( 'status' => (int) $drive_mock['token_status'], 'body' => 200 === (int) $drive_mock['token_status'] ? json_encode( array( 'access_token' => 'mock.access-token-value-1234567890' ) ) : '{}' ); }
function wp_safe_remote_get( $url, $args ) { global $drive_mock; if ( false !== strpos( $url, '?alt=media' ) ) { $drive_mock['media_requests']++; return array( 'status' => (int) $drive_mock['media_status'], 'body' => (string) $drive_mock['media_body'], 'headers' => array( 'content-type' => (string) $drive_mock['media_type'] ) ); } if ( false !== strpos( $url, '/files?' ) ) { parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); $files = false !== strpos( (string) ( $query['q'] ?? '' ), 'mimeType' ) ? $drive_mock['folders'] : $drive_mock['files']; return array( 'status' => 200, 'body' => json_encode( array( 'files' => $files ) ), 'headers' => array() ); } return array( 'status' => 200, 'body' => json_encode( $drive_mock['root'] ), 'headers' => array() ); }
final class GHCA_ACD_Jotform_Provider {
	const MAX_DOCUMENT_BYTES = 26214400;
	public static $fetches = array();
	public static function answer( array $submission, string $qid ) { return $submission['answers'][ $qid ]['answer'] ?? ''; }
	public static function file_candidates( $answer ): array { return is_array( $answer ) ? array_values( $answer ) : array( (string) $answer ); }
	public static function file_urls( $answer ): array { return self::file_candidates( $answer ); }
	public static function fetch_file( string $url ) { self::$fetches[] = $url; return array( 'body' => '%PDF-1.4', 'mime' => 'application/pdf', 'name' => basename( $url ), 'bytes' => 8 ); }
}

$root = dirname( __DIR__ );
$fails = 0;
function drive_frontend_check( bool $condition, string $message ): void { global $fails; echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $fails += $condition ? 0 : 1; }

$drive = file_get_contents( $root . '/includes/jotform/class-google-drive-provider.php' );
$store = file_get_contents( $root . '/includes/jotform/class-external-evidence-store.php' );
$sync = file_get_contents( $root . '/includes/jotform/class-jotform-sync.php' );
$ui = file_get_contents( $root . '/includes/jotform/class-jotform-ui.php' );
$shortcodes = file_get_contents( $root . '/includes/class-shortcodes.php' );
$ownership = file_get_contents( $root . '/assets/jotform-ownership.js' );
$css = file_get_contents( $root . '/assets/jotform.css' );

require_once $root . '/includes/jotform/class-google-drive-provider.php';
require_once $root . '/includes/jotform/class-external-evidence-store.php';

drive_frontend_check( false !== strpos( $drive, "const TOKEN_URL = 'https://oauth2.googleapis.com/token'" ) && false !== strpos( $drive, "const API_BASE  = 'https://www.googleapis.com/drive/v3'" ) && false !== strpos( $drive, "const SCOPE     = 'https://www.googleapis.com/auth/drive.readonly'" ), 'Drive uses fixed HTTPS OAuth and API hosts with readonly scope' );
drive_frontend_check( false !== strpos( $drive, "'Evidence-' . \$reference" ) && false !== strpos( $drive, 'evidence_reference' ) && false !== strpos( $drive, 'count( $drive_files ) !== count( $names )' ) && false !== strpos( $drive, 'normalization_collision_key' ) && false !== strpos( $drive, 'drive_collision_key' ), 'Drive reference folders use unique canonical filename matching and fail closed' );
drive_frontend_check( 'REQ-2026_08' === GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => 'REQ-2026_08' ) ) ) ) && '700001' === GHCA_ACD_Google_Drive_Provider::evidence_reference( array(), array( 'id' => '700001' ) ) && is_wp_error( GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => '' ) ) ) ) ) && is_wp_error( GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => '../bad ref' ) ) ) ) ) && is_wp_error( GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => '<b>REQ</b>' ) ) ) ) ) && is_wp_error( GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => ' REQ' ) ) ) ) ) && is_wp_error( GHCA_ACD_Google_Drive_Provider::evidence_reference( array( 'evidence_reference_qid' => '12' ), array( 'id' => '700001', 'answers' => array( '12' => array( 'answer' => "REQ\x00" ) ) ) ) ), 'configured references accept only non-empty lossless raw values; only an unconfigured reference falls back to the immutable submission ID' );
drive_frontend_check( false !== strpos( $drive, 'GHCA_ACD_GOOGLE_DRIVE_CREDENTIALS_FILE' ) && false !== strpos( $drive, 'static $token = null' ) && false !== strpos( $drive, 'GHCA_ACD_External_Evidence_Store::base_directory()' ), 'credentials stay in the validated private directory and tokens are request-local' );
GHCA_ACD_Jotform_Provider::$fetches = array();
$single = GHCA_ACD_External_Evidence_Store::resolve_source_file( array( 'certificate_qid' => '55' ), array( 'answers' => array( '55' => array( 'answer' => array( 'https://example.test/first.pdf', 'https://example.test/second.pdf' ) ) ) ), 1 );
drive_frontend_check( is_array( $single ) && array( 'https://example.test/second.pdf' ) === GHCA_ACD_Jotform_Provider::$fetches && false !== strpos( $store, 'resolve_source_descriptors' ) && false !== strpos( $store, 'fetch_source_descriptor( $descriptor )' ) && false !== strpos( $store, 'unset( $fetched, $pdf )' ) && false !== strpos( $ui, 'GHCA_ACD_External_Evidence_Store::resolve_source_file' ), 'single-index access fetches one body while capture resolves metadata then processes one body at a time' );

$fixture = static function (): array {
	$root = 'root_123456'; $folder = 'folder_123456';
	return array(
		'token_status' => 200, 'media_requests' => 0, 'media_status' => 200, 'media_body' => '%PDF-1.4 mock', 'media_type' => 'application/pdf',
		'root' => array( 'id' => $root, 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false ),
		'folders' => array( array( 'id' => $folder, 'name' => 'Evidence-700001', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => array( $root ), 'trashed' => false ) ),
		'files' => array(
			array( 'id' => 'file_000001', 'name' => 'First Cert.pdf', 'mimeType' => 'application/pdf', 'size' => '20', 'parents' => array( $folder ), 'trashed' => false ),
			array( 'id' => 'file_000002', 'name' => 'second.pdf', 'mimeType' => 'application/pdf', 'size' => '20', 'parents' => array( $folder ), 'trashed' => false ),
		),
	);
};
$form = array( 'drive_folder_id' => 'root_123456', 'certificate_qid' => '55' );
$submission = array( 'id' => '700001', 'answers' => array( '55' => array( 'answer' => array( 'https://metadata.test/uploads/First%20Cert.pdf', 'https://metadata.test/uploads/second.pdf' ) ) ) );
$drive_mock = $fixture();
$credential_failure = GHCA_ACD_Google_Drive_Provider::files_for_submission( $form, $submission );
drive_frontend_check( is_wp_error( $credential_failure ) && 'ghca_drive_credentials_missing' === $credential_failure->get_error_code(), 'missing credentials fail closed before Drive metadata use' );
$ordered = GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] );
$media = is_array( $ordered ) ? GHCA_ACD_Google_Drive_Provider::validate_file_response( $ordered[1], array( 'status' => 200, 'body' => '%PDF-1.4 mock', 'headers' => array( 'content-type' => 'application/pdf' ) ) ) : $ordered;
drive_frontend_check( is_array( $ordered ) && array( 'file_000001', 'file_000002' ) === array_column( $ordered, 'id' ) && is_array( $media ), 'mocked Drive happy path preserves exact Jotform order and validates one selected media response' );
$drive_mock = $fixture(); $drive_mock['files'][0]['name'] = 'First-Cert.pdf';
drive_frontend_check( is_array( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'safe Drive space normalization binds through one canonical key' );
$drive_mock = $fixture(); $drive_mock['files'][0]['name'] = 'First Cert';
drive_frontend_check( is_array( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'a missing Drive extension is accepted only when safe MIME supplies it' );
$jpeg_file = array( 'id' => 'file_000011', 'name' => 'Photo Certificate', 'mimeType' => 'image/jpeg', 'size' => '20', 'parents' => array( 'folder_123456' ), 'trashed' => false );
$jpeg_root = $fixture()['root']; $jpeg_folder = $fixture()['folders'];
drive_frontend_check( is_array( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'Photo Certificate.jpg' ), $jpeg_root, $jpeg_folder, array( $jpeg_file ) ) ), 'an extensionless Drive JPEG uniquely matches an expected jpg' );
drive_frontend_check( is_array( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'Photo Certificate.jpeg' ), $jpeg_root, $jpeg_folder, array( $jpeg_file ) ) ), 'an extensionless Drive JPEG uniquely matches an expected jpeg' );
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'Photo Certificate.jpg', 'Photo Certificate.jpeg' ), $jpeg_root, $jpeg_folder, array( $jpeg_file, array_merge( $jpeg_file, array( 'id' => 'file_000012' ) ) ) ) ), 'extensionless JPEG ambiguity cannot bind one Drive file to jpg and jpeg expectations' );
$drive_mock = $fixture(); $drive_mock['files'][0]['name'] = 'First Cert.jpg';
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'extension and MIME disagreement fails closed' );
$drive_mock = $fixture(); $drive_mock['files'][] = array( 'id' => 'file_000003', 'name' => 'First-Cert.pdf', 'mimeType' => 'application/pdf', 'size' => '20', 'parents' => array( 'folder_123456' ), 'trashed' => false );
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf', 'third.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'canonical Drive collisions remain ambiguous and fail closed' );
$drive_mock = $fixture(); $drive_mock['folders'][] = $drive_mock['folders'][0];
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked duplicate evidence folders fail closed' );
$drive_mock = $fixture(); $drive_mock['folders'][0]['parents'] = array( 'wrong_parent' );
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked folder parent mismatch fails closed' );
$drive_mock = $fixture(); array_pop( $drive_mock['files'] );
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked file-count mismatch fails closed' );
$drive_mock = $fixture(); $drive_mock['files'][0]['parents'] = array( 'wrong_parent' );
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked file parent mismatch fails closed' );
$drive_mock = $fixture(); $drive_mock['files'][0]['mimeType'] = 'text/plain';
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked wrong Drive MIME fails closed' );
$drive_mock = $fixture(); $drive_mock['files'][0]['size'] = '26214401';
drive_frontend_check( is_wp_error( GHCA_ACD_Google_Drive_Provider::validate_evidence_listing( 'root_123456', 'Evidence-700001', array( 'First Cert.pdf', 'second.pdf' ), $drive_mock['root'], $drive_mock['folders'], $drive_mock['files'] ) ), 'mocked oversized Drive file fails closed' );
$redirect = GHCA_ACD_Google_Drive_Provider::validate_file_response( $fixture()['files'][0], array( 'status' => 302, 'body' => '', 'headers' => array() ) );
drive_frontend_check( is_wp_error( $redirect ) && 'ghca_drive_redirect_denied' === $redirect->get_error_code(), 'mocked Drive redirect is rejected without following it' );
drive_frontend_check( false !== strpos( $sync, "'google_drive' ===" ) && false !== strpos( $sync, 'jotform_file_names' ) && false !== strpos( $ui, "'file_source'" ), 'per-form source routing defaults to Jotform and supports Google Drive settings' );
drive_frontend_check( false !== strpos( $ownership, "'user_id'" ) && false !== strpos( $ownership, "'ghca_ownership_claim'" ) && false !== strpos( $ownership, 'MutationObserver' ) && false !== strpos( $ownership, 'form.jotform.com' ) && false !== strpos( $ownership, 'getClaim' ), 'configured HTTPS Jotform iframes receive one signed ownership bind at load or insertion' );
drive_frontend_check( false !== strpos( $shortcodes, 'ghca-tab-external-training' ) && false !== strpos( $shortcodes, 'user_can_review_external_training' ) && false !== strpos( $ui, 'render_frontend_review' ) && false !== strpos( $ui, 'review_redirect' ), 'External Training Review is a capability-gated frontend dashboard tab with safe return notices' );
drive_frontend_check( false !== strpos( $css, '.ghca-acd__external-review table' ) && false !== strpos( $css, '.ghca-acd__external-review form' ) && false !== strpos( $css, '.ghca-acd__external-review .notice' ) && false !== strpos( $css, '@media(max-width:700px)' ), 'frontend review supplies scoped table, control, notice and narrow-screen usability styles' );

echo 0 === $fails ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit( 0 === $fails ? 0 : 1 );
