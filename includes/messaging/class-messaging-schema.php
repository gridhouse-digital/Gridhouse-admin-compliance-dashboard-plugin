<?php
/**
 * Additive schema for operational employee reminder communications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_Schema {
	const VERSION        = 3;
	const OPTION_VERSION = 'ghca_acd_messaging_db_version';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) >= self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$communications  = self::communications_table();
		$deliveries      = self::deliveries_table();
		$templates       = self::templates_table();
		$consent_events  = self::consent_events_table();
		$webhook_events  = self::webhook_events_table();

		$sql = array(
			"CREATE TABLE {$communications} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				employee_user_id bigint(20) unsigned NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				source varchar(32) NOT NULL DEFAULT 'employee_drawer',
				template_id bigint(20) unsigned NULL,
				template_version int(10) unsigned NULL,
				urgency varchar(16) NOT NULL DEFAULT 'normal',
				subject text NULL,
				message longtext NOT NULL,
				requested_channels varchar(32) NOT NULL DEFAULT 'email',
				idempotency_key char(64) NOT NULL,
				state varchar(32) NOT NULL DEFAULT 'queued',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY employee_created (employee_user_id,created_at),
				KEY actor_created (actor_user_id,created_at),
				KEY state_updated (state,updated_at)
			) {$charset_collate};",
			"CREATE TABLE {$deliveries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				communication_id bigint(20) unsigned NOT NULL,
				channel varchar(16) NOT NULL,
				masked_destination varchar(255) NOT NULL,
				destination_hash char(64) NULL,
				provider varchar(32) NOT NULL,
				provider_ref varchar(191) NULL,
				state varchar(32) NOT NULL DEFAULT 'queued',
				attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error_code varchar(64) NULL,
				last_error_summary text NULL,
				queued_at datetime NOT NULL,
				attempted_at datetime NULL,
				accepted_at datetime NULL,
				delivered_at datetime NULL,
				failed_at datetime NULL,
				updated_at datetime NOT NULL,
				lease_token char(64) NULL,
				lease_expires_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY communication_channel (communication_id,channel),
				UNIQUE KEY provider_ref (provider_ref),
				KEY state_queue (state,queued_at),
				KEY lease_expiry (lease_expires_at)
			) {$charset_collate};",
			"CREATE TABLE {$templates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(120) NOT NULL,
				description text NULL,
				allowed_channels varchar(32) NOT NULL DEFAULT 'email',
				default_urgency varchar(16) NOT NULL DEFAULT 'normal',
				subject_template text NULL,
				message_template longtext NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				active tinyint(1) unsigned NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY active_name (active,name)
			) {$charset_collate};",
			"CREATE TABLE {$consent_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				employee_user_id bigint(20) unsigned NOT NULL,
				phone_hash char(64) NOT NULL,
				masked_phone varchar(32) NOT NULL,
				state varchar(24) NOT NULL,
				source varchar(32) NOT NULL,
				disclosure_version varchar(64) NULL,
				evidence_reference varchar(191) NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider_ref varchar(191) NULL,
				event_key char(64) NOT NULL,
				event_at datetime NOT NULL,
				received_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY employee_phone_event (employee_user_id,phone_hash,event_at),
				KEY phone_event (phone_hash,event_at)
			) {$charset_collate};",
			"CREATE TABLE {$webhook_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_key char(64) NOT NULL,
				event_type varchar(24) NOT NULL,
				provider_ref varchar(191) NULL,
				provider_state varchar(32) NULL,
				processed_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY provider_event (provider_ref,event_type)
			) {$charset_collate};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		if ( ! self::tables_exist() ) {
			return false;
		}

		self::seed_templates();
		self::repair_default_template_channels();
		update_option( self::OPTION_VERSION, self::VERSION, false );
		return true;
	}

	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::communications_table(), self::deliveries_table(), self::templates_table(), self::consent_events_table(), self::webhook_events_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	public static function communications_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_communications';
	}

	public static function deliveries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_communication_deliveries';
	}

	public static function templates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_message_templates';
	}

	public static function consent_events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_sms_consent_events';
	}

	public static function webhook_events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_twilio_webhook_events';
	}

	/**
	 * Widens the shipped default templates from 'email' to 'email,email_sms'.
	 *
	 * Installs seeded before schema v3 stored plain 'email', which hid those
	 * templates whenever SMS or Email + SMS was selected. Only rows that still
	 * carry BOTH the seeded subject and the old channel value are touched, so a
	 * template an admin has edited or deliberately restricted is left alone.
	 */
	private static function repair_default_template_channels(): void {
		global $wpdb;

		$table    = self::templates_table();
		$subjects = array(
			'Training compliance reminder',
			'Upcoming training deadline',
			'Overdue training action required',
		);

		foreach ( $subjects as $subject ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"UPDATE {$table} SET allowed_channels = %s WHERE allowed_channels = %s AND subject_template = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'email,email_sms',
					'email',
					$subject
				)
			);
		}
	}

	private static function seed_templates(): void {
		global $wpdb;

		$table = self::templates_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$now       = current_time( 'mysql', true );
		$templates = array(
			array(
				'name'        => __( 'General training reminder', 'ghca-acd' ),
				'description' => __( 'A general reminder to review assigned training.', 'ghca-acd' ),
				'urgency'     => 'normal',
				'subject'     => __( 'Training compliance reminder', 'ghca-acd' ),
				'message'     => __( 'Hello [employee_first_name],\n\nYou have assigned training that requires your attention. Please sign in to [portal_url] to review your requirements.\n\nThank you,\n[agency_name]', 'ghca-acd' ),
			),
			array(
				'name'        => __( 'Upcoming training deadline', 'ghca-acd' ),
				'description' => __( 'A reminder that an assigned training deadline is approaching.', 'ghca-acd' ),
				'urgency'     => 'important',
				'subject'     => __( 'Upcoming training deadline', 'ghca-acd' ),
				'message'     => __( 'Hello [employee_first_name],\n\nYour assigned training deadline is [due_date]. Please sign in to [portal_url] to review and complete your requirements.\n\nThank you,\n[agency_name]', 'ghca-acd' ),
			),
			array(
				'name'        => __( 'Overdue training', 'ghca-acd' ),
				'description' => __( 'An urgent reminder for overdue training requirements.', 'ghca-acd' ),
				'urgency'     => 'urgent',
				'subject'     => __( 'Overdue training action required', 'ghca-acd' ),
				'message'     => __( 'Hello [employee_first_name],\n\nYour training record shows that one or more requirements need immediate attention. Please sign in to [portal_url] or contact your compliance manager.\n\nThank you,\n[agency_name]', 'ghca-acd' ),
			),
		);

		foreach ( 0 === $count ? $templates : array() as $template ) {
			$wpdb->insert(
				$table,
				array(
					'name'               => $template['name'],
					'description'        => $template['description'],
					/*
					 * Email and Email + SMS. Seeding plain 'email' made every default
					 * template disappear from the picker as soon as SMS or Email + SMS
					 * was selected, because the picker matches the exact channel key.
					 * SMS-only stays excluded: these bodies exceed the SMS length cap.
					 */
					'allowed_channels'   => 'email,email_sms',
					'default_urgency'    => $template['urgency'],
					'subject_template'   => $template['subject'],
					'message_template'   => str_replace( '\\n', "\n", $template['message'] ),
					'version'            => 1,
					'active'             => 1,
					'created_by'         => 0,
					'updated_by'         => 0,
					'created_at'         => $now,
					'updated_at'         => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		$sms_name = __( 'SMS training reminder', 'ghca-acd' );
		$exists   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE name = %s", $sms_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $exists ) {
			$wpdb->insert(
				$table,
				array(
					'name'             => $sms_name,
					'description'      => __( 'A brief, minimum-necessary SMS reminder with no course or personnel details.', 'ghca-acd' ),
					'allowed_channels' => 'sms,email_sms',
					'default_urgency'  => 'normal',
					'subject_template' => __( 'Training compliance reminder', 'ghca-acd' ),
					'message_template' => __( '[agency_name]: You have an outstanding training requirement. Please sign in to [portal_url] or contact your compliance manager. Reply STOP to opt out.', 'ghca-acd' ),
					'version'          => 1,
					'active'           => 1,
					'created_by'       => 0,
					'updated_by'       => 0,
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}
}
