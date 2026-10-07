<?php
/**
 * Signed Twilio status and Advanced Opt-Out webhook endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Twilio_Webhooks {
	const REST_NAMESPACE = 'ghca-acd/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/twilio/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_status' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::REST_NAMESPACE, '/twilio/inbound', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_inbound' ), 'permission_callback' => '__return_true' ) );
	}

	public static function status_url(): string {
		return rest_url( self::REST_NAMESPACE . '/twilio/status' );
	}

	public static function inbound_url(): string {
		return rest_url( self::REST_NAMESPACE . '/twilio/inbound' );
	}

	public static function handle_status( WP_REST_Request $request ) {
		$verified = self::verify( $request, self::status_url() );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$params = $request->get_body_params();
		$sid    = sanitize_text_field( (string) ( $params['MessageSid'] ?? '' ) );
		$status = sanitize_key( (string) ( $params['MessageStatus'] ?? '' ) );
		$error  = sanitize_key( (string) ( $params['ErrorCode'] ?? '' ) );
		if ( ! preg_match( '/^SM[a-f0-9]{32}$/i', $sid ) || '' === $status ) {
			return new WP_Error( 'twilio_status_payload_invalid', __( 'Invalid Twilio status payload.', 'ghca-acd' ), array( 'status' => 400 ) );
		}
		$key = self::event_key( 'status', $sid, $status . '|' . $error );
		if ( ! GHCA_ACD_Messaging_Repository::claim_webhook_event( $key, 'status', $sid, $status ) ) {
			return new WP_REST_Response( null, 200 );
		}
		$result = GHCA_ACD_Messaging_Repository::apply_provider_status( $sid, $status, $error );
		if ( is_wp_error( $result ) ) {
			GHCA_ACD_Messaging_Repository::release_webhook_event( $key );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( null, 200 );
	}

	public static function handle_inbound( WP_REST_Request $request ) {
		$verified = self::verify( $request, self::inbound_url() );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$params   = $request->get_body_params();
		$config   = GHCA_ACD_Settings::twilio_config();
		$service  = sanitize_text_field( (string) ( $params['MessagingServiceSid'] ?? '' ) );
		$sid      = sanitize_text_field( (string) ( $params['MessageSid'] ?? '' ) );
		$opt_type = strtoupper( sanitize_key( (string) ( $params['OptOutType'] ?? '' ) ) );
		$phone    = GHCA_ACD_SMS_Phone::normalize( (string) ( $params['From'] ?? '' ) );
		if ( ! hash_equals( (string) $config['messaging_service_sid'], $service ) || ! preg_match( '/^SM[a-f0-9]{32}$/i', $sid ) || ! in_array( $opt_type, array( 'STOP', 'START', 'HELP' ), true ) || '' === $phone ) {
			return new WP_Error( 'twilio_inbound_payload_invalid', __( 'Invalid Twilio inbound payload.', 'ghca-acd' ), array( 'status' => 400 ) );
		}
		$key = self::event_key( 'inbound', $sid, $opt_type );
		if ( ! GHCA_ACD_Messaging_Repository::claim_webhook_event( $key, 'inbound', $sid, strtolower( $opt_type ) ) ) {
			return new WP_REST_Response( null, 200 );
		}
		if ( 'HELP' !== $opt_type ) {
			$employee_ids = 'STOP' === $opt_type
				? GHCA_ACD_SMS_Consent_Repository::employees_for_phone( $phone )
				: GHCA_ACD_SMS_Consent_Repository::employee_for_phone( $phone );
			if ( is_wp_error( $employee_ids ) ) {
				GHCA_ACD_Messaging_Repository::release_webhook_event( $key );
				return new WP_Error( $employee_ids->get_error_code(), $employee_ids->get_error_message(), array( 'status' => 400 ) );
			}
			foreach ( (array) $employee_ids as $employee_id ) {
				$result = GHCA_ACD_SMS_Consent_Repository::record(
				array(
					'employee_user_id' => $employee_id,
					'phone'            => $phone,
					'state'            => 'STOP' === $opt_type ? 'opted_out' : 're_opted_in',
					'source'           => 'STOP' === $opt_type ? 'twilio_stop' : 'twilio_start',
					'disclosure_version'=> (string) get_option( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION, '1' ),
					'actor_user_id'    => 0,
					'provider_ref'     => $sid,
					'event_key'        => self::event_key( 'consent', $sid, $opt_type . '|' . $employee_id ),
				)
			);
				if ( is_wp_error( $result ) ) {
					GHCA_ACD_Messaging_Repository::release_webhook_event( $key );
					return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
				}
			}
		}
		return new WP_REST_Response( null, 200 );
	}

	/** @return true|WP_Error */
	public static function verify( WP_REST_Request $request, string $canonical_url ) {
		$config = GHCA_ACD_Settings::twilio_config();
		$params = $request->get_body_params();
		if ( ! is_array( $params ) || ! isset( $params['AccountSid'] ) || ! hash_equals( (string) $config['account_sid'], (string) $params['AccountSid'] ) ) {
			return new WP_Error( 'twilio_account_mismatch', __( 'Twilio account verification failed.', 'ghca-acd' ), array( 'status' => 403 ) );
		}
		$signature = trim( (string) $request->get_header( 'x-twilio-signature' ) );
		$token     = GHCA_ACD_Twilio_Provider::webhook_auth_token();
		if ( '' === $signature || is_wp_error( $token ) ) {
			return new WP_Error( 'twilio_signature_missing', __( 'Twilio signature verification is unavailable.', 'ghca-acd' ), array( 'status' => 403 ) );
		}
		ksort( $params, SORT_STRING );
		$data = $canonical_url;
		foreach ( $params as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				return new WP_Error( 'twilio_signature_payload_invalid', __( 'Twilio signature payload is invalid.', 'ghca-acd' ), array( 'status' => 400 ) );
			}
			$data .= (string) $name . (string) $value;
		}
		$expected = base64_encode( hash_hmac( 'sha1', $data, $token, true ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'twilio_signature_invalid', __( 'Twilio signature verification failed.', 'ghca-acd' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function event_key( string $type, string $reference, string $state ): string {
		return hash_hmac( 'sha256', $type . '|' . $reference . '|' . $state, wp_salt( 'nonce' ) );
	}
}
