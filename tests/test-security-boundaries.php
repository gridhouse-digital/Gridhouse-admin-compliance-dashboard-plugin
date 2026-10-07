<?php
require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		if ( 'ghca_acd_employee_roles' === $hook ) {
			$value[] = 'unsafe_employee';
			$value[] = 'unsafe_plugin_editor';
			$value[] = 'unsafe_theme_editor';
			$value[] = 'unsafe_network_manager';
			$value[] = 'unsafe_authoring';
		}
		return $value;
	}
}
if ( ! class_exists( 'GHCA_ACD_Test_Role' ) ) {
	class GHCA_ACD_Test_Role {
		public $capabilities;
		public function __construct( array $capabilities ) { $this->capabilities = $capabilities; }
		public function add_cap( $capability ) { $this->capabilities[ $capability ] = true; }
		public function remove_cap( $capability ) { unset( $this->capabilities[ $capability ] ); }
	}
}
$GLOBALS['ghca_acd_test_roles'] = array(
	'subscriber'             => new GHCA_ACD_Test_Role( array( 'read' => true, 'level_0' => true ) ),
	'caregiver'              => new GHCA_ACD_Test_Role( array( 'read' => true ) ),
	'nurse'                  => new GHCA_ACD_Test_Role( array( 'read' => true ) ),
	'lpn'                    => new GHCA_ACD_Test_Role( array( 'read' => true, 'access_training_portal' => true ) ),
	'dsp'                    => new GHCA_ACD_Test_Role( array( 'read' => true, 'upload_files' => true ) ),
	'future_employee'        => new GHCA_ACD_Test_Role( array( 'read' => true, 'complete_assigned_training' => true ) ),
	'unsafe_employee'        => new GHCA_ACD_Test_Role( array( 'read' => true, 'manage_options' => true ) ),
	'unsafe_plugin_editor'   => new GHCA_ACD_Test_Role( array( 'read' => true, 'edit_plugins' => true ) ),
	'unsafe_theme_editor'    => new GHCA_ACD_Test_Role( array( 'read' => true, 'edit_themes' => true ) ),
	'unsafe_network_manager' => new GHCA_ACD_Test_Role( array( 'read' => true, 'manage_network_users' => true ) ),
	'unsafe_authoring'       => new GHCA_ACD_Test_Role( array( 'read' => true, 'edit_others_posts' => true ) ),
	'hr_manager'             => new GHCA_ACD_Test_Role( array( 'read' => true, 'view_compliance_admin_dashboard' => true, 'list_users' => true ) ),
	'compliance_lead'        => new GHCA_ACD_Test_Role( array( 'read' => true, 'view_compliance_admin_dashboard' => true, 'list_users' => true ) ),
	'training_manager'       => new GHCA_ACD_Test_Role( array( 'read' => true, 'view_compliance_admin_dashboard' => true, 'list_users' => true ) ),
);
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $role ) {
		return isset( $GLOBALS['ghca_acd_test_roles'][ $role ] ) ? $GLOBALS['ghca_acd_test_roles'][ $role ] : null;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( 'add_role' ) ) {
	function add_role( $role, $label, $capabilities ) {
		$GLOBALS['ghca_acd_test_roles'][ $role ] = new GHCA_ACD_Test_Role( $capabilities );
		return $GLOBALS['ghca_acd_test_roles'][ $role ];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return false;
	}
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return (object) array(
			'roles' => array(
				'subscriber'      => array( 'name' => 'Subscriber' ),
				'caregiver'       => array( 'name' => 'Caregiver' ),
				'nurse'           => array( 'name' => 'Nurse' ),
				'lpn'             => array( 'name' => 'LPN' ),
				'dsp'             => array( 'name' => 'DSP' ),
				'future_employee' => array( 'name' => 'Future Employee' ),
				'editor'          => array( 'name' => 'Editor' ),
				'unsafe_employee' => array( 'name' => 'Unsafe Employee' ),
				'unsafe_plugin_editor' => array( 'name' => 'Unsafe Plugin Editor' ),
				'unsafe_theme_editor' => array( 'name' => 'Unsafe Theme Editor' ),
				'unsafe_network_manager' => array( 'name' => 'Unsafe Network Manager' ),
				'unsafe_authoring' => array( 'name' => 'Unsafe Authoring' ),
			),
		);
	}
}

require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-data-provider.php';
require_once __DIR__ . '/../includes/class-audit-pdf.php';

$fails = 0;
function check_security_boundary( bool $condition, string $message ): void {
	global $fails;
	if ( $condition ) {
		echo "PASS: $message\n";
		return;
	}
	echo "FAIL: $message\n";
	++$fails;
}

check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'subscriber' ), 'delegated managers may assign the employee subscriber role' );
check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'caregiver' ), 'delegated managers may assign the employee caregiver role' );
check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'nurse' ), 'delegated managers may assign the employee nurse role' );
check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'lpn' ), 'delegated managers may assign the custom LPN role' );
check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'dsp' ), 'delegated managers may assign the custom DSP role' );
check_security_boundary( GHCA_ACD_Roles::delegated_role_is_allowed( 'future_employee' ), 'future non-privileged employee roles are automatically manageable' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'unsafe_employee' ), 'filtered employee roles with privileged capabilities remain blocked' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'unsafe_plugin_editor' ), 'filtered employee roles with plugin-edit capability remain blocked' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'unsafe_theme_editor' ), 'filtered employee roles with theme-edit capability remain blocked' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'unsafe_network_manager' ), 'filtered employee roles with network-user capability remain blocked' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'unsafe_authoring' ), 'filter-added roles with unlisted authoring capabilities fail closed' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'editor' ), 'delegated managers may not assign editor' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_role_is_allowed( 'compliance_lead' ), 'delegated managers may not assign Compliance Lead' );
$employee_caps = array( 'subscriber' => true, 'read' => true, 'level_0' => true );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 7, array( 'subscriber' ), true, $employee_caps ), 'delegated managers may not edit themselves' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'subscriber' ), false, $employee_caps ), 'delegated managers may not edit out-of-scope users' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'editor' ), true, $employee_caps ), 'delegated managers may not edit privileged-role users' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'caregiver', 'editor' ), true, $employee_caps ), 'delegated managers may not edit mixed employee and privileged-role users' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'subscriber' ), true, array( 'subscriber' => true, 'read' => true, 'edit_others_posts' => true ) ), 'per-user privileged capabilities fail closed' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'subscriber' ), true, $employee_caps ), 'delegated managers may edit in-scope employees' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'caregiver' ), true, array( 'caregiver' => true, 'read' => true ) ), 'delegated managers may edit in-scope caregivers' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'nurse' ), true, array( 'nurse' => true, 'read' => true ) ), 'delegated managers may edit in-scope nurses' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'lpn' ), true, array( 'lpn' => true, 'read' => true, 'access_training_portal' => true ) ), 'delegated managers may edit custom-role LPN employees' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'dsp' ), true, array( 'dsp' => true, 'read' => true, 'upload_files' => true ) ), 'delegated managers may edit custom-role DSP employees' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'hr_manager' ), true, array( 'hr_manager' => true, 'read' => true ) ), 'HR Manager accounts remain protected' );
check_security_boundary( ! GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'compliance_lead' ), true, array( 'compliance_lead' => true, 'read' => true ) ), 'Compliance Lead accounts remain protected' );
check_security_boundary( GHCA_ACD_Roles::delegated_target_is_allowed( 7, 8, array( 'caregiver', 'nurse' ), true, array( 'caregiver' => true, 'nurse' => true, 'read' => true ) ), 'multiple safe employee role assignments remain manageable' );
check_security_boundary( ! GHCA_ACD_Roles::role_update_is_needed( 'caregiver', array( 'caregiver', 'nurse' ) ), 'profile edits preserve all existing safe roles when the submitted primary role is unchanged' );
check_security_boundary( GHCA_ACD_Roles::role_update_is_needed( 'subscriber', array( 'caregiver', 'nurse' ) ), 'selecting a genuinely different role performs an explicit role replacement' );
$manageable_roles = array_keys( GHCA_ACD_Roles::get_manageable_employee_roles() );
check_security_boundary( $manageable_roles === array( 'subscriber', 'caregiver', 'nurse', 'lpn', 'dsp', 'future_employee' ), 'delegated role dropdown exposes all current and future safe employee roles' );
GHCA_ACD_Roles::register_roles();
check_security_boundary( empty( get_role( 'hr_manager' )->capabilities['list_users'] ) && empty( get_role( 'compliance_lead' )->capabilities['list_users'] ) && empty( get_role( 'training_manager' )->capabilities['list_users'] ), 'plugin manager roles do not retain WordPress list_users' );

$manage_users_source = file_get_contents( __DIR__ . '/../includes/class-manage-users-ui.php' );
$ajax_source         = file_get_contents( __DIR__ . '/../includes/class-ajax-handlers.php' );
$roles_source        = file_get_contents( __DIR__ . '/../includes/class-roles.php' );
$scope_predicate     = 'GHCA_ACD_Roles::delegated_target_is_in_scope';
check_security_boundary( false !== strpos( $manage_users_source, $scope_predicate ), 'Manage Users Edit action uses the shared employee-scope predicate' );
check_security_boundary( false !== strpos( $ajax_source, $scope_predicate ), 'Manage Users AJAX save rechecks the shared employee-scope predicate' );
check_security_boundary( false !== strpos( $manage_users_source, 'delegated_target_is_in_scope( (int) $user_id, $users )' ) && 1 === substr_count( $manage_users_source, 'get_employee_user_ids()' ), 'Manage Users reuses its employee snapshot instead of rescanning per row' );
check_security_boundary( false !== strpos( $manage_users_source, '(array) $user->allcaps' ) && false !== strpos( $ajax_source, '(array) $existing_user->allcaps' ), 'UI and AJAX both enforce effective per-user capabilities' );
check_security_boundary( false !== strpos( $ajax_source, 'empty( $groups ) && ! GHCA_ACD_Roles::user_has_full_dashboard_access()' ), 'group-scoped managers cannot create or detach an ungrouped employee' );
check_security_boundary( false !== strpos( $ajax_source, "unset( \$userdata['role'] )" ), 'AJAX omits an unchanged role so WordPress cannot collapse a multi-role account' );
check_security_boundary( false !== strpos( $roles_source, 'self::user_is_agency_employee_manager() || GHCA_ACD_User_Report::can_view_user( $target_id )' ), 'Compliance Leads and HR Managers can edit safe employee accounts agency-wide while delegated managers remain view-scoped' );
check_security_boundary( false !== strpos( $ajax_source, "update_user_meta( \$saved_user_id, 'billing_phone', \$phone )" ) && false !== strpos( $ajax_source, "update_user_meta( \$saved_user_id, 'phone', \$phone )" ), 'Manage Users continues to persist phone updates to both supported profile fields' );

$scoped_groups = GHCA_ACD_Data_Provider::merge_tracked_group_ids( array( 10, 20 ), array( 20, 30 ), false );
check_security_boundary( $scoped_groups === array( 10, 20 ), 'scoped viewers do not inherit global new-hire groups' );
$unrestricted_groups = GHCA_ACD_Data_Provider::merge_tracked_group_ids( array( 10, 20 ), array( 20, 30 ), true );
check_security_boundary( $unrestricted_groups === array( 10, 20, 30 ), 'unrestricted viewers retain global new-hire groups' );

$home = 'https://academy.example.com';
check_security_boundary( GHCA_ACD_Data_Provider::sanitize_certificate_url( 'https://academy.example.com/certificate/1', $home ) !== '', 'same-origin HTTPS certificate URL is accepted' );
check_security_boundary( GHCA_ACD_Data_Provider::sanitize_certificate_url( 'https://evil.example/certificate/1', $home ) === '', 'cross-origin certificate URL is rejected' );
check_security_boundary( GHCA_ACD_Data_Provider::sanitize_certificate_url( 'javascript:alert(1)', $home ) === '', 'javascript certificate URL is rejected' );
check_security_boundary( GHCA_ACD_Data_Provider::sanitize_certificate_url( 'https://user:pass@academy.example.com/certificate/1', $home ) === '', 'credential-bearing certificate URL is rejected' );

check_security_boundary( GHCA_Audit_PDF::certificate_response_is_allowed( '%PDF-small', 0 ), 'small PDF response is accepted' );
check_security_boundary( ! GHCA_Audit_PDF::certificate_response_is_allowed( 'not-a-pdf', 0 ), 'non-PDF response is rejected' );
check_security_boundary( ! GHCA_Audit_PDF::certificate_response_is_allowed( '%PDF-small', GHCA_Audit_PDF::MAX_TOTAL_CERTIFICATE_BYTES ), 'aggregate certificate byte limit is enforced' );
check_security_boundary( GHCA_Audit_PDF::can_append_certificate_pages( 90, 10 ), 'packet page limit allows the boundary value' );
check_security_boundary( ! GHCA_Audit_PDF::can_append_certificate_pages( 90, 11 ), 'packet page limit rejects overflow' );

$pdf_source = file_get_contents( __DIR__ . '/../includes/class-audit-pdf.php' );
$builder_renderer = strpos( $pdf_source, '$builder_class' );
$legacy_renderer  = strpos( $pdf_source, "has_action( 'learndash_tcpdf_init' )" );
check_security_boundary( false !== $builder_renderer && false !== $legacy_renderer && $builder_renderer < $legacy_renderer, 'Certificate Builder rendering is selected before the legacy TCPDF fallback' );
$course_page_renderer = strpos( $pdf_source, 'render_course_detail_page( $pdf, $course )' );
$certificate_appender = strpos( $pdf_source, 'append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path' );
check_security_boundary( false !== $course_page_renderer && false !== $certificate_appender && $course_page_renderer < $certificate_appender, 'course detail pages are rendered before their matching certificates' );
check_security_boundary( false !== strpos( $pdf_source, '<strong>Employee:</strong>' ) && false === strpos( $pdf_source, "'Employee: ' . \$audit_data" ), 'employee name is rendered inside the cover details table instead of as a separate heading' );
check_security_boundary( false !== strpos( $pdf_source, 'GHCA_ACD_Settings::annual_cycle_label' ) && false !== strpos( (string) file_get_contents( __DIR__ . '/../includes/class-settings.php' ), "'employee_start_date' => __( 'Employee Hire Date'" ), 'employee-anniversary packets use the configured Employee Hire Date label' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
