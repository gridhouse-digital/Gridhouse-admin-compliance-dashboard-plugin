<?php
/**
 * WordPress email transport for employee reminders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Email_Provider {
	/** @param array<string,mixed> $delivery */
	public static function send( array $delivery ) {
		if ( ! GHCA_ACD_Settings::reminder_email_enabled() ) {
			return new WP_Error( 'email_disabled', __( 'Reminder email is disabled in settings.', 'ghca-acd' ) );
		}

		$user = get_userdata( (int) $delivery['employee_user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'recipient_not_found', __( 'The employee account no longer exists.', 'ghca-acd' ) );
		}

		$email = sanitize_email( (string) $user->user_email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'recipient_email_invalid', __( 'The employee does not have a valid email address.', 'ghca-acd' ) );
		}

		$subject = (string) $delivery['subject'];
		$message = (string) $delivery['message'];
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$alt_body_hook = null;
		if ( GHCA_ACD_Settings::reminder_html_enabled() ) {
			$rendered = GHCA_ACD_Email_Template_Renderer::render( $delivery );
			$message  = $rendered['html'];
			$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
			$alt_body = $rendered['text'];
			$alt_body_hook = static function ( $phpmailer ) use ( $alt_body ): void {
				$phpmailer->AltBody = $alt_body;
			};
			add_action( 'phpmailer_init', $alt_body_hook, PHP_INT_MAX, 1 );
		}

		$from_name  = GHCA_ACD_Settings::get_reminder_from_name();
		$from_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		}

		$reply_to = GHCA_ACD_Settings::get_reminder_reply_to();
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		try {
			$accepted = wp_mail( $email, $subject, $message, $headers );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mail_transport_exception', __( 'The email transport reported an internal error.', 'ghca-acd' ) );
		} finally {
			if ( null !== $alt_body_hook ) {
				remove_action( 'phpmailer_init', $alt_body_hook, PHP_INT_MAX );
			}
		}

		return $accepted
			? true
			: new WP_Error( 'mail_transport_rejected', __( 'WordPress did not accept the reminder for email transport.', 'ghca-acd' ) );
	}
}
