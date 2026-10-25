<?php
require __DIR__ . '/bootstrap.php';

$test_role    = 'compliance_lead';
$test_options = array();

function current_user_can( string $capability ): bool {
	return false;
}
function wp_get_current_user(): object {
	global $test_role;
	return (object) array( 'roles' => array( $test_role ) );
}
function get_current_user_id(): int {
	return 296;
}
function get_option( string $name, $default = false ) {
	global $test_options;
	return $test_options[ $name ] ?? $default;
}
function apply_filters( string $name, $value ) {
	return $value;
}

final class GHCA_ACD_Settings {
	const OPTION_PERM_EDIT_RECORDS         = 'edit_records';
	const OPTION_PERM_MANAGE_ANNOUNCEMENTS = 'manage_announcements';
	const OPTION_PERM_MANAGE_USERS          = 'manage_users';
	const OPTION_PERM_UNRESTRICTED_VIEW     = 'unrestricted_view';
	const OPTION_PERM_SEND_REMINDERS        = 'send_reminders';
	const OPTION_PERM_VIEW_COMMUNICATIONS   = 'view_communications';
	const OPTION_PERM_MANAGE_TEMPLATES      = 'manage_templates';
	const OPTION_PERM_VIEW_DOCUMENTS        = 'view_documents';
}

require_once __DIR__ . '/../includes/class-roles.php';

$checks = array(
	'full dashboard access'      => GHCA_ACD_Roles::user_has_full_dashboard_access(),
	'edit training records'      => GHCA_ACD_Roles::user_can_edit_records(),
	'manage announcements'       => GHCA_ACD_Roles::user_can_manage_announcements(),
	'manage employee accounts'   => GHCA_ACD_Roles::user_can_manage_users(),
	'unrestricted employee view' => GHCA_ACD_Roles::user_has_unrestricted_view(),
	'send employee reminders'    => GHCA_ACD_Roles::user_can_send_reminders(),
	'view communication history' => GHCA_ACD_Roles::user_can_view_communication_history(),
	'view employee documents'     => GHCA_ACD_Roles::user_can_view_employee_documents(),
	'review external training'    => GHCA_ACD_Roles::user_can_review_external_training(),
);

$template_access = ! GHCA_ACD_Roles::user_can_manage_message_templates();
echo ( $template_access ? 'PASS: ' : 'FAIL: ' ) . "Compliance Lead does not inherit template management\n";
$fails = $template_access ? 0 : 1;

$settings_access = ! GHCA_ACD_Roles::user_can_manage_messaging_settings();
echo ( $settings_access ? 'PASS: ' : 'FAIL: ' ) . "Compliance Lead does not inherit WordPress messaging-settings administration\n";
$fails += $settings_access ? 0 : 1;

foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "Compliance Lead has $label\n";
	$fails += $passed ? 0 : 1;
}

$test_role = 'hr_manager';
$hr_checks = array(
	'does not inherit full dashboard access' => ! GHCA_ACD_Roles::user_has_full_dashboard_access(),
	'has automatic Manage Users access'      => GHCA_ACD_Roles::user_can_manage_users(),
	'has agency-wide employee visibility'    => GHCA_ACD_Roles::user_has_unrestricted_view(),
);
foreach ( $hr_checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "HR Manager $label\n";
	$fails += $passed ? 0 : 1;
}

exit( $fails ? 1 : 0 );
