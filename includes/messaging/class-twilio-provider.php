<?php
/**
 * Twilio Programmable Messaging adapter using fixed REST endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Twilio_Provider {
	const API_HOST       = 'https://api.twilio.com';
	const MESSAGING_HOST = 'https://messaging.twilio.com';

	/** @return true|WP_Error */
	public static function configuration_ready( bool $require_connection_test = true ) {
		if ( ! GHCA_ACD_Settings::sms_enabled() ) {
			return new WP_Error( 'sms_disabled', __( 'SMS reminders are disabled in settings.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_Messaging_Secret_Store::available() ) {
			return new WP_Error( 'twilio_encryption_key_missing', __( 'The host Twilio encryption key is not configured.', 'ghca-acd' ) );
		}
		$config = GHCA_ACD_Settings::twilio_config();
		if ( '' === $config['account_sid'] || '' === $config['api_key_sid'] || '' === $config['messaging_service_sid'] ) {
			return new WP_Error( 'twilio_identifiers_missing', __( 'The Twilio Account SID, restricted API Key SID, and Messaging Service SID are required.', 'ghca-acd' ) );
		}
		$api_secret = GHCA_ACD_Messaging_Secret_Store::decrypt( (string) $config['api_secret_encrypted'] );
		if ( is_wp_error( $api_secret ) ) {
			return $api_secret;
		}
		$webhook_token = GHCA_ACD_Messaging_Secret_Store::decrypt( (string) $config['webhook_token_encrypted'] );
		if ( is_wp_error( $webhook_token ) ) {
			return new WP_Error( 'twilio_webhook_token_missing', __( 'A securely stored agency/subaccount Auth Token is required to validate Twilio webhooks.', 'ghca-acd' ) );
		}
		if ( ! in_array( $config['a2p_status'], array( 'active', 'not_applicable' ), true ) ) {
			return new WP_Error( 'twilio_campaign_not_ready', __( 'The agency messaging registration has not been attested as active.', 'ghca-acd' ) );
		}
		if ( empty( $config['advanced_opt_out'] ) ) {
			return new WP_Error( 'twilio_opt_out_not_ready', __( 'Twilio Advanced Opt-Out must be enabled and attested for this Messaging Service.', 'ghca-acd' ) );
		}
		if ( $require_connection_test && ! GHCA_ACD_Settings::twilio_connection_is_current() ) {
			return new WP_Error( 'twilio_connection_not_tested', __( 'Run a successful Twilio connection test after saving the current configuration.', 'ghca-acd' ) );
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function test_connection() {
		$ready = self::configuration_ready( false );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$config = GHCA_ACD_Settings::twilio_config();
		$result = self::request( 'GET', self::MESSAGING_HOST . '/v1/Services/' . rawurlencode( (string) $config['messaging_service_sid'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['sid'], $result['account_sid'] ) || ! hash_equals( (string) $config['messaging_service_sid'], (string) $result['sid'] ) || ! hash_equals( (string) $config['account_sid'], (string) $result['account_sid'] ) ) {
			return new WP_Error( 'twilio_connection_identity_mismatch', __( 'Twilio returned a Messaging Service that does not match this agency account.', 'ghca-acd' ) );
		}
		update_option( GHCA_ACD_Settings::OPTION_TWILIO_CONNECTION_FINGERPRINT, GHCA_ACD_Settings::twilio_config_fingerprint(), false );
		update_option( GHCA_ACD_Settings::OPTION_TWILIO_CONNECTION_TESTED_AT, time(), false );
		return true;
	}

	/** @param array<string,mixed> $delivery @return array<string,string>|WP_Error */
	public static function send( array $delivery ) {
		$ready = self::configuration_ready( true );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$employee_id = (int) ( $delivery['employee_user_id'] ?? 0 );
		if ( GHCA_ACD_Data_Provider::is_user_suspended( $employee_id ) && ! GHCA_ACD_Settings::allow_inactive_reminders() ) {
			return new WP_Error( 'sms_inactive_blocked', __( 'Reminders to inactive employees are disabled in settings.', 'ghca-acd' ) );
		}
		$phone       = GHCA_ACD_SMS_Phone::for_user( $employee_id );
		if ( '' === $phone ) {
			return new WP_Error( 'sms_phone_invalid', __( 'The employee does not have a valid E.164 mobile number.', 'ghca-acd' ) );
		}
		if ( empty( $delivery['destination_hash'] ) || ! hash_equals( (string) $delivery['destination_hash'], GHCA_ACD_SMS_Phone::hash( $phone ) ) ) {
			return new WP_Error( 'sms_destination_changed', __( 'The employee phone changed after this SMS was queued. Create a new reminder after recording consent for the current number.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_SMS_Consent_Repository::has_current_consent( $employee_id, $phone ) ) {
			return new WP_Error( 'sms_consent_missing', __( 'Current SMS consent is missing or the employee has opted out.', 'ghca-acd' ) );
		}

		$message = trim( (string) ( $delivery['message'] ?? '' ) );
		if ( '' === $message || self::length( $message ) > GHCA_ACD_Messaging_Service::SMS_MESSAGE_MAX ) {
			return new WP_Error( 'sms_message_invalid', __( 'The SMS message is empty or exceeds the allowed length.', 'ghca-acd' ) );
		}
		return self::send_to_phone( $phone, $message );
	}

	/** @return array<string,string>|WP_Error */
	public static function send_test( string $phone ) {
		$ready = self::configuration_ready( true );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$phone = GHCA_ACD_SMS_Phone::normalize( $phone );
		if ( '' === $phone ) {
			return new WP_Error( 'sms_test_phone_invalid', __( 'Enter the test phone in E.164 format, for example +14165551234.', 'ghca-acd' ) );
		}
		return self::send_to_phone( $phone, __( 'Gridhouse Digital: Twilio SMS configuration test. Reply STOP to opt out.', 'ghca-acd' ) );
	}

	/** @return string|WP_Error */
	public static function webhook_auth_token() {
		$config = GHCA_ACD_Settings::twilio_config();
		return GHCA_ACD_Messaging_Secret_Store::decrypt( (string) $config['webhook_token_encrypted'] );
	}

	/** @return array<string,string>|WP_Error */
	private static function send_to_phone( string $phone, string $message ) {
		$config = GHCA_ACD_Settings::twilio_config();
		$result = self::request(
			'POST',
			self::API_HOST . '/2010-04-01/Accounts/' . rawurlencode( (string) $config['account_sid'] ) . '/Messages.json',
			array(
				'To'                  => $phone,
				'MessagingServiceSid' => (string) $config['messaging_service_sid'],
				'Body'                => $message,
				'StatusCallback'      => GHCA_ACD_Twilio_Webhooks::status_url(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$sid = sanitize_text_field( (string) ( $result['sid'] ?? '' ) );
		if ( ! preg_match( '/^SM[a-f0-9]{32}$/i', $sid ) ) {
			return new WP_Error( 'twilio_response_invalid', __( 'Twilio accepted the request but returned an invalid message reference.', 'ghca-acd' ) );
		}
		return array( 'provider_ref' => $sid, 'status' => sanitize_key( (string) ( $result['status'] ?? 'accepted' ) ) );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function request( string $method, string $url, array $body = array() ) {
		$config = GHCA_ACD_Settings::twilio_config();
		$secret = GHCA_ACD_Messaging_Secret_Store::decrypt( (string) $config['api_secret_encrypted'] );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array( 'Authorization' => 'Basic ' . base64_encode( (string) $config['api_key_sid'] . ':' . $secret ) ),
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'twilio_transport_error', __( 'The Twilio service could not be reached.', 'ghca-acd' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && ! is_array( $data ) ) {
			return new WP_Error( 'twilio_response_invalid', __( 'Twilio accepted the request but returned an invalid response. Reconcile the provider log before sending another message.', 'ghca-acd' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $data ) && isset( $data['code'] ) ? 'twilio_' . sanitize_key( (string) $data['code'] ) : 'twilio_http_' . $status;
			return new WP_Error( $code, __( 'Twilio rejected the request. Review the safe error code in communication history.', 'ghca-acd' ) );
		}
		return $data;
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
