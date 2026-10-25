<?php
/**
 * Bounded background worker for reminder deliveries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_Worker {
	const HOOK = 'ghca_acd_process_message_delivery';
	const MAINTENANCE_HOOK = 'ghca_acd_recover_message_leases';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );
		add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'maintain' ) );
		add_action( 'init', array( __CLASS__, 'ensure_maintenance_schedule' ), 20 );
	}

	public static function ensure_maintenance_schedule(): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::MAINTENANCE_HOOK );
		}
	}

	public static function maintain(): void {
		if ( ! self::schema_ready() ) {
			return;
		}

		GHCA_ACD_Messaging_Repository::recover_expired_leases();
		foreach ( GHCA_ACD_Messaging_Repository::queued_delivery_ids() as $delivery_id ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $delivery_id ) ) ) {
				self::schedule( $delivery_id );
			}
		}
	}

	public static function clear_schedules(): void {
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function schedule( int $delivery_id ): bool {
		if ( $delivery_id <= 0 ) {
			return false;
		}

		$result = wp_schedule_single_event( time() + 1, self::HOOK, array( $delivery_id ) );
		return ! is_wp_error( $result ) && false !== $result;
	}

	public static function process( int $delivery_id ): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		$delivery = GHCA_ACD_Messaging_Repository::claim_delivery( $delivery_id );
		if ( ! $delivery ) {
			return;
		}

		$lease_token = (string) $delivery['lease_token'];
		$channel  = (string) $delivery['channel'];
		$provider = (string) $delivery['provider'];
		if ( ! in_array( $channel . ':' . $provider, array( 'email:wordpress_mail', 'sms:twilio' ), true ) ) {
			GHCA_ACD_Messaging_Repository::mark_failed(
				$delivery_id,
				$lease_token,
				'unsupported_provider',
				__( 'The queued delivery provider is not supported.', 'ghca-acd' )
			);
			return;
		}

		$result = 'sms' === $channel ? GHCA_ACD_Twilio_Provider::send( $delivery ) : GHCA_ACD_Email_Provider::send( $delivery );
		if ( is_wp_error( $result ) ) {
			GHCA_ACD_Messaging_Repository::mark_failed(
				$delivery_id,
				$lease_token,
				$result->get_error_code(),
				$result->get_error_message()
			);
			return;
		}

		if ( 'sms' === $channel ) {
			if ( ! GHCA_ACD_Messaging_Repository::mark_provider_accepted( $delivery_id, $lease_token, (string) $result['provider_ref'] ) ) {
				GHCA_ACD_Messaging_Repository::mark_failed(
					$delivery_id,
					$lease_token,
					'sms_outcome_unknown',
					__( 'Twilio accepted the SMS, but its provider reference could not be saved. Reconcile the message in Twilio before sending another reminder.', 'ghca-acd' )
				);
			}
		} else {
			GHCA_ACD_Messaging_Repository::mark_accepted( $delivery_id, $lease_token );
		}
	}

	private static function schema_ready(): bool {
		return (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) >= GHCA_ACD_Messaging_Schema::VERSION
			&& GHCA_ACD_Messaging_Schema::tables_exist();
	}
}
