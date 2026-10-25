<?php
require __DIR__ . '/bootstrap.php';

$can_view_documents = false;
function check_ajax_referer( $action, $field ) {}
function is_user_logged_in() { return true; }
function __( $message, $domain = '' ) { return $message; }
function wp_send_json_error( $data, $status = 200 ) { throw new RuntimeException( $status . ':' . $data['message'] ); }
function wp_send_json_success() { throw new RuntimeException( 'SUCCESS' ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function get_current_user_id() { return 7; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	public function get_error_message() { return 'Job not found.'; }
}
class GHCA_ACD_Roles {
	public static function user_can_view() { return true; }
	public static function user_can_view_employee_documents() { return $GLOBALS['can_view_documents']; }
}
class GHCA_ACD_User_Report {
	public static function can_view_user( $id ) { return true; }
}
class GHCA_Audit_PDF_Jobs {
	public static function get_job( $id, $owner ) { return empty( $GLOBALS['job_exists'] ) ? new WP_Error() : array( 'user_id' => 99, 'tracker' => 'annual' ); }
	public static function acquire_job_lock( $id, $blocking = false ) { return fopen( 'php://temp', 'w+' ); }
	public static function release_lock( $lock ) { fclose( $lock ); }
	public static function delete_job( $id ) { $GLOBALS['job_deleted'] = true; }
}

require_once __DIR__ . '/../includes/class-audit-pdf.php';

function packet_permission_response( callable $request ): string {
	try {
		$request();
	} catch ( RuntimeException $error ) {
		return $error->getMessage();
	}
	return 'NO RESPONSE';
}

$_POST['user_id'] = 0;
$_REQUEST['job_id'] = 'synthetic';
$denied_init = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_init_job' ) );
$denied_fetch = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_fetch_cert' ) );
$denied_merge = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_merge' ) );
$denied_download = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_download' ) );
$denied_cancel = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_cancel' ) );
$can_view_documents = true;
$allowed_init = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_init_job' ) );
$allowed_download = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_download' ) );
$can_view_documents = false;
$job_exists = true;
$cancel_owned_job = packet_permission_response( array( 'GHCA_Audit_PDF', 'ajax_cancel' ) );

$checks = array(
	'packet creation denied without document permission' => '403:Permission denied.' === $denied_init,
	'certificate fetch denied without document permission' => '403:Permission denied.' === $denied_fetch,
	'packet merge denied without document permission' => '403:Permission denied.' === $denied_merge,
	'packet download denied without document permission' => '403:Permission denied.' === $denied_download,
	'owned-job cancellation stays reachable after document permission revocation' => '404:Job not found.' === $denied_cancel,
	'owned-job cancellation removes temporary files after permission revocation' => 'SUCCESS' === $cancel_owned_job && ! empty( $job_deleted ),
	'authorized packet creation reaches employee validation' => '403:Invalid employee or permission denied.' === $allowed_init,
	'authorized packet download reaches job validation' => '404:Job not found.' === $allowed_download,
);
foreach ( $checks as $label => $pass ) {
	echo ( $pass ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
