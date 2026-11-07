<?php
/**
 * Bulk reminders from the redesigned Employees tab: one personalised email per selected employee, through the
 * existing GHCA_ACD_Messaging_Service. Nothing here sends mail itself and nothing bypasses the service:
 *
 * - every recipient is checked server-side by the service (send permission, agency scope, email readiness,
 *   inactive policy, rate limits), on context, preview and send;
 * - each recipient gets their own message, rendered with their own placeholders and logged in their own
 *   Communication History, so no recipient ever sees another;
 * - one batch token is used as the idempotency token for every recipient; the service keys it with the actor and
 *   the employee, so a repeated or retried batch returns the existing message instead of creating another;
 * - email only. SMS stays available for single reminders where it is configured; it is not offered here.
 *
 * The recipient list is exactly the ids the browser sends, capped at MAX_RECIPIENTS. Nobody is added.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Bulk_Messaging {
	/** Below the service's 30-per-10-minutes actor limit, so one batch can always be queued in full. */
	const MAX_RECIPIENTS = 25;

	public static function init(): void {
		add_action( 'wp_ajax_ghca_acd_bulk_message_context', array( __CLASS__, 'ajax_context' ) );
		add_action( 'wp_ajax_ghca_acd_bulk_message_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_ghca_acd_bulk_message_send', array( __CLASS__, 'ajax_send' ) );
	}

	/** Whether bulk reminders can be offered at all: the single-reminder permission and email reminders enabled. */
	public static function available(): bool {
		return GHCA_ACD_Roles::user_can_send_reminders() && GHCA_ACD_Settings::reminder_email_enabled();
	}

	/**
	 * Unique positive ids from the request, in the order given.
	 *
	 * @return array<int,int>|WP_Error
	 */
	public static function ids_from_request( $raw ) {
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids = array();
		foreach ( $raw as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
				return new WP_Error( 'ghca_bulk_ids_invalid', __( 'The selection is invalid. Clear it and select the employees again.', 'ghca-acd' ) );
			}
			$ids[ (int) $value ] = (int) $value;
		}
		if ( ! $ids ) {
			return new WP_Error( 'ghca_bulk_ids_empty', __( 'Select at least one employee.', 'ghca-acd' ) );
		}
		if ( count( $ids ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: maximum recipients */
			return new WP_Error( 'ghca_bulk_too_many', sprintf( __( 'Send to at most %d employees at a time.', 'ghca-acd' ), self::MAX_RECIPIENTS ) );
		}
		return array_values( $ids );
	}

	/** @return array<int,int> */
	private static function guard(): array {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		if ( ! GHCA_ACD_Roles::user_can_send_reminders() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send reminders.', 'ghca-acd' ) ), 403 );
		}
		if ( ! GHCA_ACD_Settings::reminder_email_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Email reminders are disabled in settings.', 'ghca-acd' ) ) );
		}
		$ids = self::ids_from_request( wp_unslash( $_POST['ids'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated digit by digit above.
		if ( is_wp_error( $ids ) ) {
			wp_send_json_error( array( 'message' => $ids->get_error_message() ) );
		}
		return $ids;
	}

	/**
	 * Recipients as the service sees them. An employee the viewer may not see is reported by id only, so the
	 * response never reveals a name or address outside the viewer's scope.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function recipients( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$context = GHCA_ACD_Messaging_Service::get_context( $id );
			if ( is_wp_error( $context ) ) {
				$out[] = array( 'id' => $id, 'name' => '', 'destination' => '', 'ready' => false, 'reason' => $context->get_error_message() );
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'name'        => (string) $context['employee_name'],
				'destination' => (string) $context['masked_email'],
				'ready'       => (bool) $context['email_ready'],
				'reason'      => (string) $context['email_reason'],
			);
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> Active templates approved for email, unrendered: placeholders fill per recipient. */
	public static function email_templates(): array {
		$out = array();
		foreach ( GHCA_ACD_Messaging_Repository::get_active_templates() as $template ) {
			$channels = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $template['allowed_channels'] ) ) ) );
			if ( ! in_array( 'email', $channels, true ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $template['id'],
				'name'    => (string) $template['name'],
				'subject' => (string) $template['subject_template'],
				'message' => (string) $template['message_template'],
				'urgency' => GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) $template['default_urgency'] ),
			);
		}
		return $out;
	}

	public static function ajax_context(): void {
		$ids = self::guard();
		wp_send_json_success(
			array(
				'recipients' => self::recipients( $ids ),
				'templates'  => self::email_templates(),
				'token'      => wp_generate_uuid4(),
				'max'        => self::MAX_RECIPIENTS,
			)
		);
	}

	public static function ajax_preview(): void {
		$ids     = self::guard();
		$subject = (string) wp_unslash( $_POST['subject'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the service.
		$message = (string) wp_unslash( $_POST['message'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the service.
		$urgency = (string) wp_unslash( $_POST['urgency'] ?? 'normal' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalised by the service.
		$rows    = array();
		foreach ( self::recipients( $ids ) as $recipient ) {
			$row = $recipient;
			if ( $recipient['ready'] ) {
				$preview = GHCA_ACD_Messaging_Service::preview_email( (int) $recipient['id'], $subject, $message, $urgency );
				if ( is_wp_error( $preview ) ) {
					$row['ready']  = false;
					$row['reason'] = $preview->get_error_message();
				} else {
					$row['subject'] = $preview['subject'];
					$row['message'] = $preview['message'];
				}
			}
			$rows[] = $row;
		}
		wp_send_json_success( array( 'recipients' => $rows ) );
	}

	/** Statuses a recipient can end in, and the ones a person may retry from the dialog. */
	const STATUSES  = array( 'queued', 'recovered', 'pending', 'processing', 'sent', 'busy', 'unscheduled', 'failed', 'delivery_failed' );
	const RETRYABLE = array( 'unscheduled', 'failed', 'busy' );

	/**
	 * One email per id through the service. A new message is queued with queue(). When the batch token already has
	 * a record for that person (a resubmission, a retry, or a response that never arrived), the existing record is
	 * read and, only if it was saved but never scheduled, recovered through recover_delivery(). No second message
	 * is ever created and a sent, sending or attempted message is never sent again.
	 *
	 * Result statuses:
	 *   queued          new message saved and its job scheduled
	 *   recovered       existing message that had no job; now scheduled
	 *   pending         existing message already queued with a job waiting
	 *   processing      existing message a worker is sending now
	 *   sent            existing message already accepted or delivered
	 *   busy            another request was scheduling it at that moment
	 *   unscheduled     message saved, but no job could be scheduled; nothing sent
	 *   failed          nothing saved: refused before saving (readiness, scope, rate limit)
	 *   delivery_failed the worker tried and failed, or the outcome is unknown; review in Communication History
	 *
	 * @param array<int,int>      $ids
	 * @param array<string,mixed> $request subject, message, urgency, template_id, token.
	 * @return array<int,array<string,mixed>>
	 */
	public static function send( array $ids, array $request ): array {
		$results = array();
		foreach ( $ids as $id ) {
			$queued = GHCA_ACD_Messaging_Service::queue(
				array(
					'employee_user_id'  => $id,
					'channel'           => 'email',
					'subject'           => (string) ( $request['subject'] ?? '' ),
					'message'           => (string) ( $request['message'] ?? '' ),
					'urgency'           => (string) ( $request['urgency'] ?? 'normal' ),
					'template_id'       => (int) ( $request['template_id'] ?? 0 ),
					'idempotency_token' => (string) ( $request['token'] ?? '' ),
				)
			);
			if ( is_wp_error( $queued ) ) {
				if ( 'ghca_message_schedule_failed' === $queued->get_error_code() ) {
					$results[] = array( 'id' => $id, 'status' => 'unscheduled', 'message' => __( 'Saved, but the background job could not be scheduled. Nothing was sent. Retry schedules this same message.', 'ghca-acd' ) );
				} else {
					$results[] = array( 'id' => $id, 'status' => 'failed', 'message' => $queued->get_error_message() );
				}
				continue;
			}
			if ( empty( $queued['duplicate'] ) ) {
				$results[] = array( 'id' => $id, 'status' => 'queued', 'message' => __( 'Queued. Delivery status appears in Communication History.', 'ghca-acd' ) );
				continue;
			}
			// A record for this person already exists from this batch: report or recover that record.
			$recovered = GHCA_ACD_Messaging_Service::recover_delivery( (int) ( $queued['delivery_id'] ?? 0 ) );
			if ( is_wp_error( $recovered ) ) {
				// Readiness errors concern a record this viewer may see; anything else (permission, scope) is a plain refusal.
				$saved     = in_array( $recovered->get_error_code(), array( 'ghca_message_email_not_ready', 'ghca_message_inactive_blocked' ), true );
				$results[] = array( 'id' => $id, 'status' => $saved ? 'unscheduled' : 'failed', 'message' => $recovered->get_error_message() );
				continue;
			}
			$status    = in_array( $recovered['status'], self::STATUSES, true ) ? $recovered['status'] : 'delivery_failed';
			$results[] = array( 'id' => $id, 'status' => $status, 'message' => (string) $recovered['message'] );
		}
		return $results;
	}

	/** @param array<int,array<string,mixed>> $results @return array<string,int> */
	public static function counts( array $results ): array {
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $results as $result ) {
			++$counts[ $result['status'] ];
		}
		return $counts;
	}

	public static function ajax_send(): void {
		$ids   = self::guard();
		$token = strtolower( trim( (string) wp_unslash( $_POST['token'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- shape-checked below and by the service.
		if ( ! preg_match( '/^[a-f0-9-]{32,64}$/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'The message form expired. Close it and start again.', 'ghca-acd' ) ) );
		}
		if ( empty( $_POST['reviewed'] ) || '1' !== (string) $_POST['reviewed'] ) {
			wp_send_json_error( array( 'message' => __( 'Review the recipients and the message before sending.', 'ghca-acd' ) ) );
		}
		$results = self::send(
			$ids,
			array(
				'subject'     => (string) wp_unslash( $_POST['subject'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the service.
				'message'     => (string) wp_unslash( $_POST['message'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the service.
				'urgency'     => (string) wp_unslash( $_POST['urgency'] ?? 'normal' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalised by the service.
				'template_id' => (int) ( $_POST['template_id'] ?? 0 ),
				'token'       => $token,
			)
		);
		wp_send_json_success( array( 'results' => $results, 'counts' => self::counts( $results ) ) );
	}
}
