<?php
/**
 * Authorization, validation, rendering, rate limiting, and queue orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_Service {
	const ACTOR_LIMIT_PER_TEN_MINUTES = 30;
	const EMPLOYEE_MIN_INTERVAL       = 60;
	const SMS_MESSAGE_MAX             = 480;

	/** @return array<string,mixed>|WP_Error */
	public static function get_context( int $employee_user_id ) {
		if ( ! self::schema_ready() ) {
			return new WP_Error( 'ghca_message_schema_unavailable', __( 'Messaging setup is incomplete. Ask an administrator to open Compliance Messaging settings.', 'ghca-acd' ) );
		}
		$access = self::check_employee_access( $employee_user_id, true );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$user     = $access['user'];
		$employee = $access['employee'];
		$email    = sanitize_email( (string) $user->user_email );
		$phone    = GHCA_ACD_SMS_Phone::for_user( $employee_user_id );
		$inactive = GHCA_ACD_Data_Provider::is_user_suspended( $employee_user_id );
		$ready    = GHCA_ACD_Settings::reminder_email_enabled() && is_email( $email );
		$reason   = '';

		if ( ! GHCA_ACD_Settings::reminder_email_enabled() ) {
			$reason = __( 'Email reminders are disabled in settings.', 'ghca-acd' );
		} elseif ( ! is_email( $email ) ) {
			$reason = __( 'This employee does not have a valid email address.', 'ghca-acd' );
		} elseif ( $inactive && ! GHCA_ACD_Settings::allow_inactive_reminders() ) {
			$ready  = false;
			$reason = __( 'Reminders to inactive employees are disabled in settings.', 'ghca-acd' );
		}

		$values    = self::template_values( $user, $employee );
		$templates = array();
		foreach ( GHCA_ACD_Messaging_Repository::get_active_templates() as $template ) {
			$subject = GHCA_ACD_Message_Template_Renderer::render( (string) $template['subject_template'], $values );
			$message = GHCA_ACD_Message_Template_Renderer::render( (string) $template['message_template'], $values );

			/*
			 * A template that fails to render used to be dropped silently, so an
			 * admin saw it simply missing from the dropdown with no explanation --
			 * one stray bracketed word is enough to trigger it. Report it instead:
			 * it is listed but not selectable, with the reason attached.
			 */
			$unavailable = '';
			if ( is_wp_error( $subject ) ) {
				$unavailable = $subject->get_error_message();
			} elseif ( is_wp_error( $message ) ) {
				$unavailable = $message->get_error_message();
			}

			$templates[] = array(
				'id'       => (int) $template['id'],
				'name'     => (string) $template['name'],
				'subject'  => is_wp_error( $subject ) ? '' : (string) $subject,
				'message'  => is_wp_error( $message ) ? '' : (string) $message,
				'urgency'  => GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) $template['default_urgency'] ),
				'channels' => array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $template['allowed_channels'] ) ) ) ),
				'reason'   => $unavailable,
			);
		}
		$sms_readiness = self::sms_readiness( $employee_user_id, $phone, $inactive );

		return array(
			'employee_id'       => $employee_user_id,
			'employee_name'     => (string) $employee['name'],
			'masked_email'      => GHCA_ACD_Messaging_Repository::mask_email( $email ),
			'masked_phone'      => GHCA_ACD_SMS_Phone::mask( $phone ),
			'email_ready'       => $ready,
			'email_reason'      => $reason,
			'sms_ready'         => ! is_wp_error( $sms_readiness ),
			'sms_reason'        => is_wp_error( $sms_readiness ) ? $sms_readiness->get_error_message() : '',
			'templates'         => $templates,
			'idempotency_token' => wp_generate_uuid4(),
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>|WP_Error
	 */
	public static function queue( array $request ) {
		if ( ! self::schema_ready() ) {
			return new WP_Error( 'ghca_message_schema_unavailable', __( 'Messaging setup is incomplete.', 'ghca-acd' ) );
		}
		$employee_user_id = isset( $request['employee_user_id'] ) ? (int) $request['employee_user_id'] : 0;
		$access = self::check_employee_access( $employee_user_id, true );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$channel = isset( $request['channel'] ) ? sanitize_key( (string) $request['channel'] ) : '';
		if ( ! in_array( $channel, array( 'email', 'sms', 'email_sms' ), true ) ) {
			return new WP_Error( 'ghca_message_channel_unavailable', __( 'Select Email, SMS, or Email and SMS.', 'ghca-acd' ) );
		}

		$user     = $access['user'];
		$employee = $access['employee'];
		$email    = sanitize_email( (string) $user->user_email );
		$phone    = GHCA_ACD_SMS_Phone::for_user( $employee_user_id );
		$inactive = GHCA_ACD_Data_Provider::is_user_suspended( $employee_user_id );
		$uses_email = in_array( $channel, array( 'email', 'email_sms' ), true );
		$uses_sms   = in_array( $channel, array( 'sms', 'email_sms' ), true );
		if ( $uses_email && ( ! GHCA_ACD_Settings::reminder_email_enabled() || ! is_email( $email ) ) ) {
			return new WP_Error( 'ghca_message_email_not_ready', __( 'Email is not ready for this employee.', 'ghca-acd' ) );
		}
		if ( $uses_sms ) {
			$sms_ready = self::sms_readiness( $employee_user_id, $phone, $inactive );
			if ( is_wp_error( $sms_ready ) ) {
				return $sms_ready;
			}
			if ( empty( $request['sms_safe_confirmed'] ) ) {
				return new WP_Error( 'ghca_sms_content_confirmation_required', __( 'Confirm that the SMS contains no sensitive employee, medical, certificate, or course details.', 'ghca-acd' ) );
			}
		}
		if ( $inactive && ! GHCA_ACD_Settings::allow_inactive_reminders() ) {
			return new WP_Error( 'ghca_message_inactive_blocked', __( 'Reminders to inactive employees are disabled.', 'ghca-acd' ) );
		}

		$token = isset( $request['idempotency_token'] ) ? strtolower( trim( (string) $request['idempotency_token'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{32,64}$/', $token ) ) {
			return new WP_Error( 'ghca_message_token_invalid', __( 'The reminder form expired. Please reopen it and try again.', 'ghca-acd' ) );
		}

		$actor_id       = get_current_user_id();
		$idempotency_key = hash_hmac( 'sha256', $token . '|' . $actor_id . '|' . $employee_user_id, wp_salt( 'nonce' ) );
		$existing       = GHCA_ACD_Messaging_Repository::get_by_idempotency_key( $idempotency_key );
		if ( $existing ) {
			$existing['duplicate'] = true;
			return $existing;
		}

		if ( GHCA_ACD_Messaging_Repository::recent_actor_count( $actor_id, 600 ) >= self::ACTOR_LIMIT_PER_TEN_MINUTES ) {
			return new WP_Error( 'ghca_message_actor_rate_limited', __( 'Too many reminders were requested. Please wait before sending another.', 'ghca-acd' ) );
		}
		if ( GHCA_ACD_Messaging_Repository::employee_has_recent_message( $employee_user_id, self::EMPLOYEE_MIN_INTERVAL ) ) {
			return new WP_Error( 'ghca_message_employee_rate_limited', __( 'A reminder was recently queued for this employee. Please wait before sending another.', 'ghca-acd' ) );
		}

		$subject = isset( $request['subject'] ) ? sanitize_text_field( (string) $request['subject'] ) : '';
		$message = isset( $request['message'] ) ? sanitize_textarea_field( (string) $request['message'] ) : '';
		$urgency = GHCA_ACD_Message_Template_Renderer::normalize_urgency( isset( $request['urgency'] ) ? sanitize_key( (string) $request['urgency'] ) : 'normal' );
		$values  = self::template_values( $user, $employee );
		$subject = GHCA_ACD_Message_Template_Renderer::render( $subject, $values );
		$message = GHCA_ACD_Message_Template_Renderer::render( $message, $values );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$validation = GHCA_ACD_Message_Template_Renderer::validate_lengths( $subject, $message, $uses_email );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$message_length = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $uses_sms && $message_length > self::SMS_MESSAGE_MAX ) {
			return new WP_Error( 'ghca_sms_message_too_long', sprintf( __( 'SMS reminders are limited to %d characters.', 'ghca-acd' ), self::SMS_MESSAGE_MAX ) );
		}

		$subject = $uses_email ? GHCA_ACD_Message_Template_Renderer::apply_urgency_prefix( $subject, $urgency ) : '';
		if ( $uses_sms ) {
			$message = GHCA_ACD_Message_Template_Renderer::apply_urgency_prefix( $message, $urgency );
			$message_length = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
			if ( $message_length > self::SMS_MESSAGE_MAX ) {
				return new WP_Error( 'ghca_sms_message_too_long', sprintf( __( 'The urgency prefix makes this SMS exceed the %d-character limit.', 'ghca-acd' ), self::SMS_MESSAGE_MAX ) );
			}
		}
		$template_id      = isset( $request['template_id'] ) ? (int) $request['template_id'] : 0;
		$template_version = null;
		if ( $template_id > 0 ) {
			$template = GHCA_ACD_Messaging_Repository::get_template( $template_id );
			if ( ! $template ) {
				return new WP_Error( 'ghca_message_template_invalid', __( 'The selected message template is no longer available.', 'ghca-acd' ) );
			}
			$allowed = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $template['allowed_channels'] ) ) ) );
			if ( ! in_array( $channel, $allowed, true ) ) {
				return new WP_Error( 'ghca_message_template_channel_invalid', __( 'The selected template is not approved for this delivery method.', 'ghca-acd' ) );
			}
			$template_version = (int) $template['version'];
		}

		$deliveries = array();
		if ( $uses_email ) {
			$deliveries[] = array( 'channel' => 'email', 'provider' => 'wordpress_mail', 'masked_destination' => GHCA_ACD_Messaging_Repository::mask_email( $email ) );
		}
		if ( $uses_sms ) {
			$deliveries[] = array( 'channel' => 'sms', 'provider' => 'twilio', 'masked_destination' => GHCA_ACD_SMS_Phone::mask( $phone ), 'destination_hash' => GHCA_ACD_SMS_Phone::hash( $phone ) );
		}
		$created = GHCA_ACD_Messaging_Repository::create_communication(
			array(
				'employee_user_id' => $employee_user_id,
				'actor_user_id'    => $actor_id,
				'template_id'      => $template_id ?: null,
				'template_version' => $template_version,
				'urgency'          => $urgency,
				'subject'          => $subject,
				'message'          => $message,
				'idempotency_key'  => $idempotency_key,
				'requested_channels'=> $channel,
			),
			$deliveries
		);
		if ( is_wp_error( $created ) || ! empty( $created['duplicate'] ) ) {
			return $created;
		}

		foreach ( (array) ( $created['delivery_ids'] ?? array( $created['delivery_id'] ) ) as $delivery_id ) {
			if ( ! GHCA_ACD_Messaging_Worker::schedule( (int) $delivery_id ) ) {
				GHCA_ACD_Messaging_Repository::mark_schedule_failed( (int) $delivery_id );
				return new WP_Error( 'ghca_message_schedule_failed', __( 'The reminder was saved, but one or more background delivery jobs could not be scheduled.', 'ghca-acd' ) );
			}
		}

		return $created;
	}

	public static function retry( int $delivery_id ) {
		if ( ! self::schema_ready() ) {
			return new WP_Error( 'ghca_message_schema_unavailable', __( 'Messaging setup is incomplete.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_Roles::user_can_send_reminders() ) {
			return new WP_Error( 'ghca_message_permission_denied', __( 'You do not have permission to send reminders.', 'ghca-acd' ) );
		}

		$delivery = GHCA_ACD_Messaging_Repository::get_delivery( $delivery_id );
		if ( ! $delivery ) {
			return new WP_Error( 'ghca_message_delivery_not_found', __( 'The failed delivery could not be found.', 'ghca-acd' ) );
		}

		$access = self::check_employee_access( (int) $delivery['employee_user_id'], true );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$user     = $access['user'];
		$email    = sanitize_email( (string) $user->user_email );
		$channel  = (string) $delivery['channel'];
		$inactive = GHCA_ACD_Data_Provider::is_user_suspended( (int) $delivery['employee_user_id'] );
		if ( 'email' === $channel && ( ! GHCA_ACD_Settings::reminder_email_enabled() || ! is_email( $email ) ) ) {
			return new WP_Error( 'ghca_message_email_not_ready', __( 'Email is not ready for this employee.', 'ghca-acd' ) );
		}
		if ( 'sms' === $channel ) {
			if ( ! empty( $delivery['provider_ref'] ) ) {
				return new WP_Error( 'ghca_sms_retry_requires_new_message', __( 'Twilio already accepted this SMS. Create a new reminder instead of reusing its provider reference.', 'ghca-acd' ) );
			}
			if ( in_array( (string) ( $delivery['last_error_code'] ?? '' ), array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' ), true ) ) {
				return new WP_Error( 'ghca_sms_retry_outcome_unknown', __( 'This SMS may already have reached Twilio. Reconcile its status in Twilio before deciding whether to create a new reminder.', 'ghca-acd' ) );
			}
			$sms_ready = self::sms_readiness( (int) $delivery['employee_user_id'], GHCA_ACD_SMS_Phone::for_user( (int) $delivery['employee_user_id'] ), $inactive );
			if ( is_wp_error( $sms_ready ) ) {
				return $sms_ready;
			}
		} elseif ( 'email' !== $channel ) {
			return new WP_Error( 'ghca_message_channel_unavailable', __( 'This delivery channel is unsupported.', 'ghca-acd' ) );
		}
		if ( $inactive && ! GHCA_ACD_Settings::allow_inactive_reminders() ) {
			return new WP_Error( 'ghca_message_inactive_blocked', __( 'Reminders to inactive employees are disabled.', 'ghca-acd' ) );
		}
		if ( 'failed' !== (string) $delivery['state'] || (int) $delivery['attempt_count'] >= GHCA_ACD_Messaging_Repository::MAX_DELIVERY_ATTEMPTS ) {
			return new WP_Error( 'ghca_message_retry_unavailable', __( 'This delivery is not eligible for another retry.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_Messaging_Repository::requeue_failed( $delivery_id ) ) {
			return new WP_Error( 'ghca_message_retry_conflict', __( 'The delivery state changed before it could be retried.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_Messaging_Worker::schedule( $delivery_id ) ) {
			GHCA_ACD_Messaging_Repository::mark_schedule_failed( $delivery_id );
			return new WP_Error( 'ghca_message_retry_schedule_failed', __( 'The retry could not be scheduled.', 'ghca-acd' ) );
		}

		return true;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function check_employee_access( int $employee_user_id, bool $require_send ) {
		if ( $require_send && ! GHCA_ACD_Roles::user_can_send_reminders() ) {
			return new WP_Error( 'ghca_message_permission_denied', __( 'You do not have permission to send reminders.', 'ghca-acd' ) );
		}
		if ( ! $require_send && ! GHCA_ACD_Roles::user_can_view_communication_history() && ! GHCA_ACD_Roles::user_can_send_reminders() ) {
			return new WP_Error( 'ghca_message_permission_denied', __( 'You do not have permission to view reminder information.', 'ghca-acd' ) );
		}
		if ( $employee_user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $employee_user_id ) ) {
			return new WP_Error( 'ghca_message_employee_out_of_scope', __( 'Invalid employee or permission denied.', 'ghca-acd' ) );
		}

		$user     = get_userdata( $employee_user_id );
		$employee = GHCA_ACD_Data_Provider::get_employee_record( $employee_user_id );
		if ( ! $user || empty( $employee['user_id'] ) ) {
			return new WP_Error( 'ghca_message_employee_not_found', __( 'Employee not found.', 'ghca-acd' ) );
		}

		return array( 'user' => $user, 'employee' => $employee );
	}

	/** @param WP_User $user @param array<string,mixed> $employee @return array<string,string> */
	private static function template_values( WP_User $user, array $employee ): array {
		$first_name = trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) );
		if ( '' === $first_name ) {
			$parts      = preg_split( '/\s+/', trim( (string) $employee['name'] ) );
			$first_name = is_array( $parts ) && ! empty( $parts[0] ) ? (string) $parts[0] : __( 'Employee', 'ghca-acd' );
		}
		$brand = GHCA_Dashboard_Branding::get();

		return array(
			'employee_first_name'      => $first_name,
			'agency_name'             => (string) ( $brand['org_name'] ?: get_bloginfo( 'name' ) ),
			'due_date'                => (string) ( $employee['due_date_label'] ?? __( 'your assigned deadline', 'ghca-acd' ) ),
			'portal_url'               => GHCA_ACD_Data_Provider::get_page_url( 'my-courses', '/my-courses/' ),
			'compliance_contact_number'=> '',
		);
	}

	/** @return true|WP_Error */
	private static function sms_readiness( int $employee_user_id, string $phone, bool $inactive ) {
		$provider = GHCA_ACD_Twilio_Provider::configuration_ready( true );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		if ( '' === $phone ) {
			return new WP_Error( 'sms_phone_invalid', __( 'This employee needs a mobile number in E.164 format, for example +14165551234.', 'ghca-acd' ) );
		}
		if ( ! GHCA_ACD_SMS_Consent_Repository::has_current_consent( $employee_user_id, $phone ) ) {
			return new WP_Error( 'sms_consent_missing', __( 'This employee has not provided current SMS consent for this phone number or has opted out.', 'ghca-acd' ) );
		}
		if ( $inactive && ! GHCA_ACD_Settings::allow_inactive_reminders() ) {
			return new WP_Error( 'sms_inactive_blocked', __( 'Reminders to inactive employees are disabled in settings.', 'ghca-acd' ) );
		}
		return true;
	}

	private static function schema_ready(): bool {
		return (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) >= GHCA_ACD_Messaging_Schema::VERSION
			&& GHCA_ACD_Messaging_Schema::tables_exist();
	}
}
