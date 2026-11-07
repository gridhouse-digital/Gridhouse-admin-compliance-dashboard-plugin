<?php
/**
 * Stand-in WordPress for the slice-5 tabs (External training, Audit data, Reports). Builds on the slice-4
 * fixture and adds synthetic users, verified-employment records, audit meta and a stand-in Jotform
 * repository (pending submissions, approved evidence, catalog). The markup, field names, nonces and
 * permission gates under test are the plugin's own code; the stored rows are synthetic, not live data.
 *
 * Environment knobs ($GLOBALS['ghca_env']): can_review_ext, can_admin (edit records), can_docs,
 * ext_ready ('ok' or an error message), ext_pending ('some' or 'none'), audit_people ('some' or 'none').
 */
require_once __DIR__ . '/redesign-lists-env.php';

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public $roles = array();
		public $display_name = '';
		public $user_login = '';
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $m;
		public function __construct( $code = '', $message = '' ) { $this->m = (string) $message; }
		public function get_error_message() { return $this->m; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes ) { return $bytes >= 1048576 ? round( $bytes / 1048576, 1 ) . ' MB' : round( $bytes / 1024 ) . ' KB'; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . htmlspecialchars( (string) $name ) . '" value="nonce-' . htmlspecialchars( (string) $action ) . '" />'; }
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $t ) { return addslashes( (string) $t ); }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin( $id = 0 ) { return false; }
}

/** Synthetic people: id => name, role label, group, verified start (Y-m-d or ''), first service, worked alone, excluded. */
$GLOBALS['ghca_admin_people'] = array(
	301 => array( 'Daniel Reyes', 'Direct Support Professional (DSP)', 11, '2026-06-02', '06/09/2026', '07/20/2026', false ),
	302 => array( 'Jordan Ellis', 'Direct Support Professional (DSP)', 12, '', '', '', false ),
	303 => array( 'Maya Okafor', 'Direct Support Professional (DSP)', 12, '', '10/08/2026', '', false ),
	304 => array( 'Samuel Boateng', 'Licensed Practical Nurse (LPN)', 13, '2026-09-08', '09/10/2026', '10/01/2026', false ),
	305 => array( 'Priya Nair <RN>', 'Registered Nurse (RN)', 13, '2026-03-03', '03/05/2026', '03/20/2026', true ),
);
$GLOBALS['wp_roles'] = (object) array( 'roles' => array(
	'dsp' => array( 'name' => 'Direct Support Professional (DSP)' ),
	'lpn' => array( 'name' => 'Licensed Practical Nurse (LPN)' ),
	'rn'  => array( 'name' => 'Registered Nurse (RN)' ),
) );

function ghca_admin_people(): array {
	return 'none' === ( $GLOBALS['ghca_env']['audit_people'] ?? 'some' ) ? array() : $GLOBALS['ghca_admin_people'];
}
function learndash_get_groups_user_ids( $group_id ) {
	$ids = array();
	foreach ( ghca_admin_people() as $id => $p ) {
		if ( (int) $p[2] === (int) $group_id ) {
			$ids[] = $id;
		}
	}
	return $ids;
}
function get_userdata( $id ) {
	$id = (int) $id;
	$p  = $GLOBALS['ghca_admin_people'][ $id ] ?? null;
	if ( ! $p && 900 !== $id ) {
		return false;
	}
	$u               = new WP_User();
	$u->ID           = $id;
	$u->display_name = $p ? $p[0] : 'Synthetic Admin';
	$u->user_login   = 'user' . $id;
	$slug            = $p ? ( false !== strpos( $p[1], 'LPN' ) ? 'lpn' : ( false !== strpos( $p[1], 'RN' ) ? 'rn' : 'dsp' ) ) : 'administrator';
	$u->roles        = array( $slug );
	return $u;
}
function get_user_meta( $id, $key, $single = false ) {
	$p = $GLOBALS['ghca_admin_people'][ (int) $id ] ?? null;
	if ( ! $p ) {
		return '';
	}
	switch ( $key ) {
		case 'ghca_acd_verified_employment':
			return '' === $p[3] ? '' : array( 'date' => $p[3], 'source' => 'Offer letter (synthetic)', 'verified_by' => 900, 'verified_at' => '2026-10-01 10:00:00' );
		case 'ghca_first_service_date':
			return $p[4];
		case 'ghca_worked_alone_date':
			return $p[5];
		case 'ghca_audit_exclude':
			return $p[6] ? '1' : '0';
	}
	return '';
}

/** Stand-in for the External Evidence store's readiness check. */
final class GHCA_ACD_External_Evidence_Store {
	public static function readiness() {
		$r = $GLOBALS['ghca_env']['ext_ready'] ?? 'ok';
		return 'ok' === $r ? true : new WP_Error( 'not_ready', $r );
	}
}

/** Stand-in for the Jotform repository: the same method names and row shapes the review reads. */
final class GHCA_ACD_Jotform_Repository {
	const MANUAL_FORM_ID = 'manual';
	public static function is_manual( array $t ): bool { return self::MANUAL_FORM_ID === (string) ( $t['form_id'] ?? '' ); }
	public static function catalog( bool $active_only = false ): array {
		return array(
			array( 'id' => 71, 'title' => 'CPR / First Aid', 'credit_hours' => '4' ),
			array( 'id' => 72, 'title' => 'Trauma-Informed Care (annual)', 'credit_hours' => '2' ),
			array( 'id' => 73, 'title' => 'Medication Administration (PA DHS)', 'credit_hours' => '6' ),
		);
	}
	public static function catalog_match_result( string $code, string $title ): array {
		foreach ( self::catalog() as $item ) {
			if ( 0 === strcasecmp( $item['title'], $title ) ) {
				return array( 'status' => 'matched', 'item' => $item );
			}
		}
		return array( 'status' => 'unmatched' );
	}
	public static function form( string $id ): ?array { return 'f1' === $id ? array( 'form_id' => 'f1', 'label' => 'Outside training upload' ) : null; }
	public static function pending_training_page( int $limit = 25, int $offset = 0, string $filter = 'all' ): array {
		if ( 'none' === ( $GLOBALS['ghca_env']['ext_pending'] ?? 'some' ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$base  = array( 'catalog_id' => null, 'proposed_code' => '', 'warning_detail' => '', 'entered_by' => 0, 'entry_reason' => '', 'manual_manifest' => '', 'submission_id' => 's', 'source_fingerprint' => 'fp' );
		$items = array(
			array( 'id' => 801, 'employee_user_id' => 304, 'form_id' => 'f1', 'proposed_title' => 'Trauma-Informed Care (annual)', 'proposed_completion_date' => '2026-10-20', 'warning_code' => '', 'created_at' => '2026-10-30 09:12:00' ) + $base,
			array( 'id' => 802, 'employee_user_id' => 0, 'form_id' => 'f1', 'proposed_title' => 'First aid card <scan>', 'proposed_completion_date' => '2024-09-03', 'warning_code' => 'ownership_claim_missing', 'warning_detail' => 'Signed claim not found.', 'created_at' => '2026-10-29 16:40:00' ) + $base,
			array( 'id' => 803, 'employee_user_id' => 303, 'form_id' => 'manual', 'proposed_title' => 'Medication Administration (PA DHS)', 'proposed_completion_date' => '2026-09-02', 'warning_code' => 'manual_entry', 'created_at' => '2026-10-28 11:00:00', 'entered_by' => 900, 'entry_reason' => 'Paper certificate from previous employer.', 'manual_manifest' => wp_json_encode_safe( array( array( 'file_index' => 0, 'file_name' => 'med-admin.pdf', 'bytes' => 348160, 'sha256' => str_repeat( 'ab', 32 ), 'storage_path' => 'x' ) ) ) ) + $base,
		);
		if ( 'unsigned' === $filter ) {
			$items = array_values( array_filter( $items, static function ( $r ) { return 0 === (int) $r['employee_user_id']; } ) );
		}
		return array( 'items' => $items, 'total' => count( $items ) );
	}
	public static function documents_for_training( array $t ): array {
		if ( 801 === (int) $t['id'] ) {
			return array( array( 'id' => 9001, 'file_name' => 'trauma-informed-care.pdf', 'evidence_reference' => 'R1' ) );
		}
		if ( 802 === (int) $t['id'] ) {
			return array( array( 'id' => 9002, 'file_name' => 'first-aid-card.jpg', 'evidence_reference' => 'R2' ), array( 'id' => 9003, 'file_name' => 'first-aid-card-back.jpg', 'evidence_reference' => 'R2' ) );
		}
		return array();
	}
	public static function manual_manifest( array $t ): ?array {
		if ( ! self::is_manual( $t ) ) {
			return null;
		}
		$m = json_decode( (string) $t['manual_manifest'], true );
		return is_array( $m ) && $m ? $m : null;
	}
	public static function active_approved_training( int $limit = 100 ): array {
		if ( 'none' === ( $GLOBALS['ghca_env']['ext_pending'] ?? 'some' ) ) {
			return array();
		}
		return array(
			array( 'id' => 701, 'employee_user_id' => 301, 'proposed_title' => 'CPR', 'proposed_completion_date' => '2026-10-22', 'approved_revision_id' => 41, 'approved_at' => '2026-10-23 10:00:00', 'catalog_snapshot' => wp_json_encode_safe( array( 'title' => 'CPR / First Aid', 'applies_to_orientation' => true ) ) ),
			array( 'id' => 702, 'employee_user_id' => 305, 'proposed_title' => 'Med admin', 'proposed_completion_date' => '2026-09-02', 'approved_revision_id' => 42, 'approved_at' => '2026-09-04 15:30:00', 'catalog_snapshot' => wp_json_encode_safe( array( 'title' => 'Medication Administration (PA DHS)' ) ) ),
		);
	}
}
function wp_json_encode_safe( $v ) { return json_encode( $v ); }

require_once __DIR__ . '/../../includes/class-employment-record.php';
require_once __DIR__ . '/../../includes/odp/class-odp-contract.php';
require_once __DIR__ . '/../../includes/odp/class-odp-ui.php';
require_once __DIR__ . '/../../includes/class-redesign-admin.php';
require_once __DIR__ . '/../../includes/jotform/class-jotform-ui.php';
