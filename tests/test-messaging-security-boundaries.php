<?php
require __DIR__ . '/bootstrap.php';

$fails = 0;
function messaging_security_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

$root       = dirname( __DIR__ );
$main       = file_get_contents( $root . '/gridhouse-admin-compliance-dashboard.php' );
$drawer     = file_get_contents( $root . '/includes/class-ajax-handlers.php' );
$roles      = file_get_contents( $root . '/includes/class-roles.php' );
$settings   = file_get_contents( $root . '/includes/class-settings.php' );
$schema     = file_get_contents( $root . '/includes/messaging/class-messaging-schema.php' );
$repository = file_get_contents( $root . '/includes/messaging/class-messaging-repository.php' );
$service    = file_get_contents( $root . '/includes/messaging/class-messaging-service.php' );
$provider   = file_get_contents( $root . '/includes/messaging/class-email-provider.php' );
$worker     = file_get_contents( $root . '/includes/messaging/class-messaging-worker.php' );
$ui         = file_get_contents( $root . '/includes/messaging/class-messaging-ui.php' );
$twilio     = file_get_contents( $root . '/includes/messaging/class-twilio-provider.php' );
$webhooks   = file_get_contents( $root . '/includes/messaging/class-twilio-webhooks.php' );
$secrets    = file_get_contents( $root . '/includes/messaging/class-messaging-secret-store.php' );
$consent    = file_get_contents( $root . '/includes/messaging/class-sms-consent-repository.php' );

messaging_security_check( false !== strpos( $main, 'Version: 1.7.1' ) && false !== strpos( $main, "const VERSION         = '1.7.1'" ), 'branded email, scalable history and Jotform evidence use the current release version' );
messaging_security_check( false === strpos( $drawer, "'mailto:'" ) && false !== strpos( $drawer, 'data-ghca-reminder=' ), 'drawer uses the controlled reminder workflow instead of mailto' );
messaging_security_check( false !== strpos( $roles, 'user_can_send_reminders' ) && false !== strpos( $roles, 'user_can_view_communication_history' ), 'reminder sending and history use separate granular permissions' );
messaging_security_check( false !== strpos( $roles, 'user_can_manage_messaging_settings' ) && false !== strpos( $roles, "return current_user_can( 'manage_options' )" ), 'provider settings remain restricted to WordPress administrators' );
messaging_security_check( substr_count( $ui, "check_ajax_referer( 'ghca_acd_table', 'nonce' )" ) >= 4, 'all four dashboard messaging AJAX actions verify the dashboard nonce' );
messaging_security_check( false !== strpos( $service, 'GHCA_ACD_User_Report::can_view_user' ), 'service rechecks employee scope server-side' );
messaging_security_check( false !== strpos( $service, "array( 'email', 'sms', 'email_sms' )" ) && false !== strpos( $service, 'sms_readiness' ), 'SMS and combined delivery are readiness-gated server-side' );
messaging_security_check( false !== strpos( $schema, 'UNIQUE KEY idempotency_key' ) && false !== strpos( $repository, 'get_by_idempotency_key' ), 'duplicate send requests are fenced by a durable idempotency key' );
messaging_security_check( false !== strpos( $worker, 'wp_schedule_single_event' ) && false !== strpos( $repository, 'MAX_DELIVERY_ATTEMPTS = 3' ), 'delivery uses a background job with a bounded retry ceiling' );
messaging_security_check( false !== strpos( $worker, 'queued_delivery_ids' ) && false !== strpos( $worker, 'wp_next_scheduled( self::HOOK' ), 'maintenance recovers queued deliveries without duplicating scheduled jobs' );
messaging_security_check( false !== strpos( $repository, "'accepted'" ) && false !== strpos( $ui, 'Accepted by email transport' ), 'WordPress mail acceptance is not mislabeled as delivery' );
messaging_security_check( false === stripos( $provider, 'twilio' ) && false === stripos( $provider, 'auth_token' ), 'Phase 1 provider contains no Twilio or parent-account credential path' );
messaging_security_check( false !== strpos( $repository, '[redacted-email]' ) && false !== strpos( $repository, '[redacted-phone]' ), 'stored transport errors redact email and phone patterns' );
messaging_security_check( false !== strpos( $ui, 'user_can_manage_message_templates' ) && false !== strpos( $ui, "check_admin_referer( 'ghca_acd_save_template' )" ), 'template mutation is permission and nonce protected' );
messaging_security_check( false !== strpos( $settings, "get_option( self::OPTION_REMINDER_EMAIL_ENABLED, 0 )" ) && preg_match( "/OPTION_REMINDER_EMAIL_ENABLED,[\\s\\S]{0,300}'default'\\s*=>\\s*0/", $settings ), 'email reminder sending defaults to disabled after upgrade' );
messaging_security_check( false !== strpos( $service, "'sms' === \$channel" ) && substr_count( $service, 'allow_inactive_reminders()' ) >= 3, 'manual retry rechecks email or SMS readiness and employee status' );
messaging_security_check( false !== strpos( $ui, 'GHCA_ACD_Messaging_Schema::tables_exist()' ), 'template writes fail closed when the messaging schema is unavailable' );
messaging_security_check( false !== strpos( $secrets, 'aes-256-gcm' ) && false !== strpos( $secrets, 'GHCA_ACD_TWILIO_ENCRYPTION_KEY' ), 'Twilio secrets require authenticated encryption with a host key outside the database' );
messaging_security_check( false !== strpos( $twilio, "const API_HOST       = 'https://api.twilio.com'" ) && false !== strpos( $twilio, "'redirection' => 0" ), 'Twilio egress uses fixed HTTPS endpoints and disables redirects' );
messaging_security_check( false !== strpos( $webhooks, 'x-twilio-signature' ) && false !== strpos( $webhooks, "hash_hmac( 'sha1'" ) && false !== strpos( $webhooks, 'hash_equals' ), 'public Twilio callbacks require documented constant-time signature validation' );
messaging_security_check( false !== strpos( $consent, 'phone_hash' ) && false !== strpos( $service, 'has_current_consent' ), 'SMS consent is phone-specific and rechecked before queueing' );
messaging_security_check( false !== strpos( $twilio, 'destination_hash' ) && false !== strpos( $twilio, 'sms_destination_changed' ), 'worker refuses to redirect a queued SMS after an employee phone change' );
messaging_security_check( false !== strpos( $settings, "sanitize_twilio_sid( get_option( self::OPTION_TWILIO_API_KEY_SID, '' ), 'SK' )" ) && false === strpos( $settings, "OPTION_TWILIO_API_KEY_SID, '' ), 'RK'" ), 'Twilio restricted API key SIDs use the documented SK prefix' );
messaging_security_check( false !== strpos( $repository, "'sms_outcome_unknown'" ) && false !== strpos( $service, "array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' )" ) && false !== strpos( $worker, '! GHCA_ACD_Messaging_Repository::mark_provider_accepted' ) && false !== strpos( $twilio, "return new WP_Error( 'twilio_response_invalid'" ), 'ambiguous provider outcomes cannot be manually retried into duplicate SMS sends' );
messaging_security_check( false !== strpos( $repository, "array( 'id' => (int) \$row['id'], 'state' => \$current )" ) && false !== strpos( $repository, "'twilio_status_conflict'" ), 'concurrent Twilio callbacks use a compare-and-swap state update and cannot overwrite a terminal state' );
messaging_security_check( false !== strpos( $ui, 'is_employee_account( $user_id )' ) && false !== strpos( $ui, 'delegated_role_is_allowed' ), 'self-service SMS consent is restricted to the plugin employee role' );
messaging_security_check( false !== strpos( $twilio, 'GHCA_ACD_Data_Provider::is_user_suspended' ) && false !== strpos( $twilio, 'allow_inactive_reminders' ), 'SMS activity rechecks inactive-employee policy immediately before provider egress' );

exit( $fails ? 1 : 0 );
