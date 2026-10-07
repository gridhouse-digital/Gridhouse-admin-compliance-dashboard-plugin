<?php
/**
 * Disposable MySQL integration check for the additive messaging schema.
 * Skips cleanly when a local root/no-password MySQL test connection is absent.
 */

$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) || ! extension_loaded( 'mysqli' ) ) {
	echo "SKIP: WordPress wpdb or mysqli is unavailable\n";
	exit( 0 );
}

define( 'WP_INSTALLING', true );
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once $wp_root . '/wp-load.php';

mysqli_report( MYSQLI_REPORT_OFF );
$server = @new mysqli( '127.0.0.1', 'root', '' );
if ( $server->connect_errno ) {
	echo "SKIP: disposable local MySQL is unavailable\n";
	exit( 0 );
}

$database = 'ghca_msg_test_' . getmypid() . '_' . bin2hex( random_bytes( 3 ) );
if ( ! $server->query( "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
	echo "SKIP: disposable database could not be created\n";
	exit( 0 );
}

$wpdb = new wpdb( 'root', '', $database, '127.0.0.1' );
$wpdb->set_prefix( 'wp_' );
$wpdb->query(
	"CREATE TABLE wp_options (
		option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		option_name varchar(191) NOT NULL DEFAULT '',
		option_value longtext NOT NULL,
		autoload varchar(20) NOT NULL DEFAULT 'yes',
		PRIMARY KEY (option_id),
		UNIQUE KEY option_name (option_name),
		KEY autoload (autoload)
	) {$wpdb->get_charset_collate()}"
);
wp_cache_flush();
require_once __DIR__ . '/../includes/messaging/class-messaging-schema.php';
require_once __DIR__ . '/../includes/messaging/class-messaging-repository.php';

$fails = 0;
function schema_integration_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

try {
	$first = GHCA_ACD_Messaging_Schema::install();
	schema_integration_check( $first, 'messaging schema installs through WordPress dbDelta' );
	schema_integration_check( GHCA_ACD_Messaging_Schema::tables_exist(), 'all five messaging and consent tables are verified' );
	schema_integration_check( 2 === (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ), 'schema version advances only after verification' );

	$template_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::templates_table() );
	schema_integration_check( 4 === $template_count, 'three email templates and one minimum-necessary SMS template are seeded' );

	$second = GHCA_ACD_Messaging_Schema::install();
	schema_integration_check( $second, 'messaging schema reinstall is idempotent' );
	$template_count_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::templates_table() );
	schema_integration_check( 4 === $template_count_after, 'idempotent reinstall does not duplicate templates' );

	$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . GHCA_ACD_Messaging_Schema::communications_table(), ARRAY_A );
	$index_names = array_values( array_unique( array_column( $indexes, 'Key_name' ) ) );
	schema_integration_check( in_array( 'idempotency_key', $index_names, true ), 'database enforces the idempotency key' );
	$delivery_indexes = array_values( array_unique( array_column( $wpdb->get_results( 'SHOW INDEX FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table(), ARRAY_A ), 'Key_name' ) ) );
	schema_integration_check( in_array( 'provider_ref', $delivery_indexes, true ), 'database prevents duplicate Twilio provider references' );
	$consent_indexes = array_values( array_unique( array_column( $wpdb->get_results( 'SHOW INDEX FROM ' . GHCA_ACD_Messaging_Schema::consent_events_table(), ARRAY_A ), 'Key_name' ) ) );
	schema_integration_check( in_array( 'event_key', $consent_indexes, true ), 'consent callbacks are idempotent at the database boundary' );

	$communication = array(
		'employee_user_id' => 12, 'actor_user_id' => 7, 'source' => 'employee_drawer', 'template_id' => null,
		'template_version' => null, 'urgency' => 'normal', 'subject' => 'Reminder', 'message' => 'Please sign in.',
		'requested_channels' => 'email_sms', 'idempotency_key' => hash( 'sha256', 'integration-idempotency' ),
	);
	$deliveries = array(
		array( 'channel' => 'email', 'provider' => 'wordpress_mail', 'masked_destination' => 'e***@example.test' ),
		array( 'channel' => 'sms', 'provider' => 'twilio', 'masked_destination' => '+*******1234', 'destination_hash' => hash( 'sha256', '+14165551234' ) ),
	);
	$created = GHCA_ACD_Messaging_Repository::create_communication( $communication, $deliveries );
	schema_integration_check( is_array( $created ) && 2 === count( $created['delivery_ids'] ?? array() ), 'combined reminder creates two deliveries atomically' );
	$stored_delivery_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() );
	schema_integration_check( 2 === $stored_delivery_count, 'combined delivery rows persist once' );
	$duplicate = GHCA_ACD_Messaging_Repository::create_communication( $communication, $deliveries );
	schema_integration_check( is_array( $duplicate ) && ! empty( $duplicate['duplicate'] ) && 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() ), 'combined reminder idempotency prevents duplicate channel sends' );
	$sms_delivery_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE channel = %s', 'sms' ) );
	$message_sid = 'SM' . str_repeat( '2', 32 );
	$wpdb->update( GHCA_ACD_Messaging_Schema::deliveries_table(), array( 'provider_ref' => $message_sid, 'state' => 'accepted' ), array( 'id' => $sms_delivery_id ) );
	$status_result = GHCA_ACD_Messaging_Repository::apply_provider_status( $message_sid, 'delivered' );
	schema_integration_check( true === $status_result && 'delivered' === $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE id = %d', $sms_delivery_id ) ), 'Twilio delivered callback advances the SMS state' );
	GHCA_ACD_Messaging_Repository::apply_provider_status( $message_sid, 'sent' );
	schema_integration_check( 'delivered' === $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE id = %d', $sms_delivery_id ) ), 'out-of-order Twilio callback cannot regress a terminal state' );
	$history_rows = GHCA_ACD_Messaging_Repository::history_for_employee( 12, 1, 20 );
	schema_integration_check( 2 === count( $history_rows ) && 1 === GHCA_ACD_Messaging_Repository::history_count( 12 ), 'history paginates logical communications while returning both combined delivery rows' );
	$sms_history = GHCA_ACD_Messaging_Repository::history_for_employee( 12, 1, 20, array( 'channel' => 'sms', 'status' => 'delivered', 'search' => 'Reminder' ) );
	schema_integration_check( 2 === count( $sms_history ) && 1 === GHCA_ACD_Messaging_Repository::history_count( 12, array( 'channel' => 'sms', 'status' => 'delivered' ) ), 'allowlisted delivery filters select the matching communication and preserve its complete channel context' );
	$missing_history = GHCA_ACD_Messaging_Repository::history_for_employee( 99, 1, 20, array( 'search' => 'Reminder' ) );
	schema_integration_check( array() === $missing_history && 0 === GHCA_ACD_Messaging_Repository::history_count( 99 ), 'employee-bound history query does not cross user scope' );
	$draft = GHCA_ACD_Messaging_Repository::get_communication_for_employee( (int) $created['communication_id'], 12 );
	schema_integration_check( is_array( $draft ) && 'Reminder' === $draft['subject'] && null === GHCA_ACD_Messaging_Repository::get_communication_for_employee( (int) $created['communication_id'], 99 ), 'draft lookup is bound to both communication and employee identifiers' );
	$webhook_key = hash( 'sha256', 'webhook-integration' );
	schema_integration_check( GHCA_ACD_Messaging_Repository::claim_webhook_event( $webhook_key, 'status', $message_sid, 'delivered' ) && ! GHCA_ACD_Messaging_Repository::claim_webhook_event( $webhook_key, 'status', $message_sid, 'delivered' ), 'duplicate callback event is processed once' );
} finally {
	$server->query( "DROP DATABASE IF EXISTS `{$database}`" );
	$server->close();
}

exit( $fails ? 1 : 0 );
