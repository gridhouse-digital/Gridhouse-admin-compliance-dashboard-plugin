<?php
/**
 * Append-only SMS consent ledger keyed to the employee's current E.164 number.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_SMS_Consent_Repository {
	const STATES  = array( 'opted_in', 'opted_out', 're_opted_in' );
	const SOURCES = array( 'web_form', 'paper_form', 'twilio_start', 'twilio_stop', 'administrative_correction' );

	/** @return array<string,mixed>|null */
	public static function current( int $employee_user_id, string $phone ): ?array {
		global $wpdb;
		$hash = GHCA_ACD_SMS_Phone::hash( $phone );
		if ( $employee_user_id <= 0 || '' === $hash ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . GHCA_ACD_Messaging_Schema::consent_events_table() . ' WHERE employee_user_id = %d AND phone_hash = %s ORDER BY event_at DESC, id DESC LIMIT 1',
				$employee_user_id,
				$hash
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function has_current_consent( int $employee_user_id, string $phone ): bool {
		$current = self::current( $employee_user_id, $phone );
		return is_array( $current ) && in_array( (string) $current['state'], array( 'opted_in', 're_opted_in' ), true );
	}

	/** @return int|WP_Error */
	public static function record( array $event ) {
		global $wpdb;
		$employee_id = isset( $event['employee_user_id'] ) ? (int) $event['employee_user_id'] : 0;
		$phone       = GHCA_ACD_SMS_Phone::normalize( (string) ( $event['phone'] ?? '' ) );
		$state       = sanitize_key( (string) ( $event['state'] ?? '' ) );
		$source      = sanitize_key( (string) ( $event['source'] ?? '' ) );
		$event_key   = strtolower( trim( (string) ( $event['event_key'] ?? '' ) ) );
		if ( $employee_id <= 0 || ! get_userdata( $employee_id ) || '' === $phone || ! in_array( $state, self::STATES, true ) || ! in_array( $source, self::SOURCES, true ) ) {
			return new WP_Error( 'ghca_sms_consent_invalid', __( 'The SMS consent event is invalid.', 'ghca-acd' ) );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $event_key ) ) {
			$event_key = hash_hmac( 'sha256', wp_generate_uuid4() . '|' . $employee_id . '|' . $state, wp_salt( 'nonce' ) );
		}

		$event_at = (string) ( $event['event_at'] ?? current_time( 'mysql', true ) );
		if ( false === strtotime( $event_at . ' UTC' ) ) {
			$event_at = current_time( 'mysql', true );
		}
		$inserted = $wpdb->insert(
			GHCA_ACD_Messaging_Schema::consent_events_table(),
			array(
				'employee_user_id'  => $employee_id,
				'phone_hash'         => GHCA_ACD_SMS_Phone::hash( $phone ),
				'masked_phone'       => GHCA_ACD_SMS_Phone::mask( $phone ),
				'state'              => $state,
				'source'             => $source,
				'disclosure_version' => substr( sanitize_text_field( (string) ( $event['disclosure_version'] ?? '' ) ), 0, 64 ),
				'evidence_reference' => substr( sanitize_text_field( (string) ( $event['evidence_reference'] ?? '' ) ), 0, 191 ),
				'actor_user_id'      => (int) ( $event['actor_user_id'] ?? 0 ),
				'provider_ref'       => substr( sanitize_text_field( (string) ( $event['provider_ref'] ?? '' ) ), 0, 191 ),
				'event_key'          => $event_key,
				'event_at'           => $event_at,
				'received_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false !== $inserted ) {
			return (int) $wpdb->insert_id;
		}
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . GHCA_ACD_Messaging_Schema::consent_events_table() . ' WHERE event_key = %s', $event_key ) );
		return $existing > 0 ? $existing : new WP_Error( 'ghca_sms_consent_store_failed', __( 'The SMS consent event could not be stored.', 'ghca-acd' ) );
	}

	/** @return array<int,int>|WP_Error */
	public static function employees_for_phone( string $phone ) {
		global $wpdb;
		$hash = GHCA_ACD_SMS_Phone::hash( $phone );
		if ( '' === $hash ) {
			return new WP_Error( 'ghca_sms_phone_invalid', __( 'The inbound SMS phone number is invalid.', 'ghca-acd' ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT employee_user_id FROM ' . GHCA_ACD_Messaging_Schema::consent_events_table() . ' WHERE phone_hash = %s', $hash ) ) ), static function ( int $id ): bool { return $id > 0; } ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'ghca_sms_phone_ambiguous', __( 'The inbound phone number does not identify exactly one employee.', 'ghca-acd' ) );
		}
		return $ids;
	}

	/** @return int|WP_Error */
	public static function employee_for_phone( string $phone ) {
		$ids = self::employees_for_phone( $phone );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		return 1 === count( $ids ) ? $ids[0] : new WP_Error( 'ghca_sms_phone_ambiguous', __( 'The inbound phone number does not identify exactly one employee.', 'ghca-acd' ) );
	}
}
