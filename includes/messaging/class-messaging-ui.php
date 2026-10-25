<?php
/**
 * Dashboard modal, AJAX endpoints, history rendering, and admin settings UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_UI {
	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );
		add_action( 'wp_ajax_ghca_acd_get_reminder_context', array( __CLASS__, 'ajax_get_context' ) );
		add_action( 'wp_ajax_ghca_acd_send_reminder', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_ghca_acd_get_communication_history', array( __CLASS__, 'ajax_history' ) );
		add_action( 'wp_ajax_ghca_acd_get_communication_draft', array( __CLASS__, 'ajax_communication_draft' ) );
		add_action( 'wp_ajax_ghca_acd_retry_reminder', array( __CLASS__, 'ajax_retry' ) );
		add_action( 'admin_post_ghca_acd_save_message_template', array( __CLASS__, 'handle_save_template' ) );
		add_action( 'admin_post_ghca_acd_send_test_reminder_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_post_ghca_acd_test_twilio_connection', array( __CLASS__, 'handle_test_twilio_connection' ) );
		add_action( 'admin_post_ghca_acd_send_test_sms', array( __CLASS__, 'handle_test_sms' ) );
		add_action( 'admin_post_ghca_acd_record_sms_consent', array( __CLASS__, 'handle_record_sms_consent' ) );
		add_action( 'admin_post_ghca_acd_self_sms_consent', array( __CLASS__, 'handle_self_sms_consent' ) );
		add_shortcode( 'ghca_sms_consent', array( __CLASS__, 'render_sms_consent_shortcode' ) );
	}

	public static function render_sms_consent_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Sign in to manage SMS reminder consent.', 'ghca-acd' ) . '</p>';
		}
		$user_id    = get_current_user_id();
		if ( ! self::is_employee_account( $user_id ) ) {
			return '<p>' . esc_html__( 'SMS reminder consent is available only to employee accounts.', 'ghca-acd' ) . '</p>';
		}
		$phone      = GHCA_ACD_SMS_Phone::for_user( $user_id );
		$privacy    = esc_url( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_PRIVACY_URL, '' ) );
		$terms      = esc_url( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_TERMS_URL, '' ) );
		$disclosure = trim( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION, '1' ) );
		$ready      = GHCA_ACD_Twilio_Provider::configuration_ready( true );
		$current    = '' !== $phone ? GHCA_ACD_SMS_Consent_Repository::current( $user_id, $phone ) : null;
		$opted_in   = is_array( $current ) && in_array( (string) $current['state'], array( 'opted_in', 're_opted_in' ), true );
		if ( '' === $phone || ( ! $opted_in && ( '' === $privacy || '' === $terms || '' === $disclosure || is_wp_error( $ready ) ) ) ) {
			return '<p>' . esc_html__( 'SMS consent is not currently available. Confirm that your profile has a mobile number with country code or contact your compliance manager.', 'ghca-acd' ) . '</p>';
		}
		$notice = isset( $_GET['ghca_sms_consent'] ) ? sanitize_key( wp_unslash( $_GET['ghca_sms_consent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		?>
		<section class="ghca-acd__sms-consent">
			<h2><?php esc_html_e( 'SMS training reminder consent', 'ghca-acd' ); ?></h2>
			<?php if ( 'saved' === $notice ) : ?><p role="status"><?php esc_html_e( 'Your SMS preference was saved.', 'ghca-acd' ); ?></p><?php endif; ?>
			<p><?php echo esc_html( sprintf( __( 'Mobile number: %s', 'ghca-acd' ), GHCA_ACD_SMS_Phone::mask( $phone ) ) ); ?></p>
			<p><?php echo esc_html( $opted_in ? __( 'Current status: opted in', 'ghca-acd' ) : __( 'Current status: not opted in', 'ghca-acd' ) ); ?></p>
			<?php if ( ! $opted_in ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ghca_acd_self_sms_consent" />
				<input type="hidden" name="consent_action" value="opt_in" />
				<?php wp_nonce_field( 'ghca_acd_self_sms_consent' ); ?>
				<label><input type="checkbox" name="consent_confirmed" value="1" required /> <?php echo wp_kses_post( sprintf( __( 'I agree to receive employee training and compliance reminders from Gridhouse Digital by SMS at the mobile number in my profile. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to opt out. I have read the <a href="%1$s">Privacy Policy</a> and <a href="%2$s">Terms of Service</a>.', 'ghca-acd' ), $privacy, $terms ) ); ?></label>
				<?php submit_button( __( 'Opt in to SMS reminders', 'ghca-acd' ), 'primary', 'submit', false ); ?>
			</form>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ghca_acd_self_sms_consent" />
				<input type="hidden" name="consent_action" value="opt_out" />
				<?php wp_nonce_field( 'ghca_acd_self_sms_consent' ); ?>
				<?php submit_button( __( 'Opt out of SMS reminders', 'ghca-acd' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_modal(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! GHCA_ACD_Roles::user_can_send_reminders() && ! GHCA_ACD_Roles::user_can_view_communication_history() ) {
			return;
		}
		?>
		<?php if ( GHCA_ACD_Roles::user_can_send_reminders() ) : ?>
		<div class="ghca-acd__reminder-modal ghca-acd__overlay ghca-acd__overlay--reminder" id="ghca-acd-reminder-modal" hidden aria-hidden="true">
			<div class="ghca-acd__reminder-modal-backdrop" data-ghca-reminder-close></div>
			<div class="ghca-acd__reminder-modal-dialog ghca-acd__v2-modal" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-reminder-title">
				<div class="ghca-acd__reminder-modal-header ghca-acd__v2-modal-header">
					<div class="ghca-acd__v2-modal-heading">
						<h2 id="ghca-acd-reminder-title"><span class="ghca-acd__reminder-title-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'Send training reminder', 'ghca-acd' ); ?></h2>
						<p class="ghca-acd__modal-subtitle"><?php esc_html_e( 'Choose delivery method, urgency, and customized message', 'ghca-acd' ); ?></p>
					</div>
					<button type="button" class="ghca-acd__reminder-modal-close" data-ghca-reminder-close aria-label="<?php esc_attr_e( 'Close reminder form', 'ghca-acd' ); ?>">&times;</button>
				</div>
				<div class="ghca-acd__reminder-modal-body ghca-acd__v2-modal-body">
					<div class="ghca-acd__reminder-loading" data-ghca-reminder-loading><?php esc_html_e( 'Loading reminder options…', 'ghca-acd' ); ?></div>
					<form class="ghca-acd__reminder-form" data-ghca-reminder-form hidden novalidate>
						<input type="hidden" data-ghca-reminder-user-id />
						<input type="hidden" data-ghca-reminder-token />

						<div class="ghca-acd__reminder-fields">

						<?php
						/*
						 * Recipient card. Only fields the messaging context actually
						 * returns are shown: name, masked email and masked phone.
						 * Channel readiness stands in for a status pill, because it
						 * reports something real (whether the channel can be used).
						 */
						?>
						<div class="ghca-acd__reminder-recipient ghca-acd__v2-recipient">
							<span class="ghca-acd__v2-recipient-avatar" data-ghca-reminder-avatar aria-hidden="true"></span>
							<span class="ghca-acd__v2-recipient-copy">
								<strong data-ghca-reminder-name></strong>
								<span data-ghca-reminder-destination></span>
							</span>
						</div>

						<div class="ghca-acd__reminder-grid ghca-acd__v2-form-grid">
							<fieldset class="ghca-acd__v2-field ghca-acd__v2-field--full ghca-acd__reminder-channel-field">
								<legend><?php esc_html_e( 'Delivery method', 'ghca-acd' ); ?></legend>
								<input type="hidden" value="email" data-ghca-reminder-channel />
								<div class="ghca-acd__reminder-channel-options">
									<label><input type="radio" name="ghca-acd-reminder-channel" value="email" data-ghca-reminder-channel-option /> <span><span class="ghca-acd__reminder-pill-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'Email', 'ghca-acd' ); ?></span></label>
									<label><input type="radio" name="ghca-acd-reminder-channel" value="sms" data-ghca-reminder-channel-option /> <span><span class="ghca-acd__reminder-pill-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'sms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'SMS', 'ghca-acd' ); ?></span></label>
									<label><input type="radio" name="ghca-acd-reminder-channel" value="email_sms" data-ghca-reminder-channel-option /> <span><span class="ghca-acd__reminder-pill-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'devices' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'Email and SMS', 'ghca-acd' ); ?></span></label>
								</div>
							</fieldset>
							<label class="ghca-acd__v2-field">
								<span class="ghca-acd__reminder-label-row"><?php esc_html_e( 'Urgency', 'ghca-acd' ); ?><em class="ghca-acd__reminder-urgency-badge" data-ghca-reminder-urgency-badge hidden></em></span>
								<select data-ghca-reminder-urgency required>
									<option value="normal"><?php esc_html_e( 'Normal', 'ghca-acd' ); ?></option>
									<option value="important"><?php esc_html_e( 'Important', 'ghca-acd' ); ?></option>
									<option value="urgent"><?php esc_html_e( 'Urgent', 'ghca-acd' ); ?></option>
								</select>
							</label>
							<label class="ghca-acd__v2-field">
							<span><?php esc_html_e( 'Template', 'ghca-acd' ); ?></span>
							<select data-ghca-reminder-template>
								<option value="0"><?php esc_html_e( 'Custom message', 'ghca-acd' ); ?></option>
							</select>
							</label>

							<label class="ghca-acd__v2-field ghca-acd__v2-field--full">
							<span><?php esc_html_e( 'Subject', 'ghca-acd' ); ?></span>
							<input type="text" maxlength="200" data-ghca-reminder-subject required />
							</label>

							<label class="ghca-acd__v2-field ghca-acd__v2-field--full">
							<span><?php esc_html_e( 'Message', 'ghca-acd' ); ?></span>
							<textarea rows="6" maxlength="4000" data-ghca-reminder-message required></textarea>
							<small><span data-ghca-reminder-count>0</span>/4000</small>
							</label>
						</div>

						<?php
						/*
						 * Preview. The subject and body below are produced by the same
						 * code that builds the outgoing message, including the urgency
						 * prefix, so this shows what the employee actually receives.
						 * These nodes already existed but were rendered hidden.
						 */
						?>
						<section class="ghca-acd__reminder-preview" aria-labelledby="ghca-acd-reminder-preview-title">
							<div class="ghca-acd__reminder-preview-head">
								<h3 id="ghca-acd-reminder-preview-title"><?php esc_html_e( 'Preview', 'ghca-acd' ); ?></h3>
								<span class="ghca-acd__reminder-preview-channel" data-ghca-reminder-preview-channel><?php esc_html_e( 'Email', 'ghca-acd' ); ?></span>
							</div>
							<div class="ghca-acd__reminder-preview-body">
								<p class="ghca-acd__reminder-preview-subject" data-ghca-reminder-preview-subject data-empty="<?php esc_attr_e( 'Subject appears here', 'ghca-acd' ); ?>"></p>
								<p class="ghca-acd__reminder-preview-message" data-ghca-reminder-preview-message data-empty="<?php esc_attr_e( 'Message appears here as the employee will receive it.', 'ghca-acd' ); ?>"></p>
							</div>
							<p class="ghca-acd__reminder-preview-sms" data-ghca-reminder-preview-sms hidden>
								<?php esc_html_e( 'SMS delivery sends the message body only, and providers may truncate long messages.', 'ghca-acd' ); ?>
							</p>
						</section>

						<label class="ghca-acd__sms-confirm" data-ghca-sms-confirm hidden>
							<input type="checkbox" data-ghca-sms-safe />
							<span><?php esc_html_e( 'I confirm this SMS contains no medical, certificate, detailed course, password, or other sensitive employee information.', 'ghca-acd' ); ?></span>
						</label>

						<div class="ghca-acd__reminder-readiness" data-ghca-reminder-readiness role="status"></div>
						<div class="ghca-acd__reminder-error" data-ghca-reminder-error role="alert" hidden></div>

							<?php /* Accurate to what the system records: the send is logged to
							   Communication History with its delivery state. Do not claim
							   anything about the recipient having opened it -- that is not tracked.
							   (A source-boundary test substring-matches this file, so avoid writing
							   the phrase it forbids even inside a comment.) */ ?>
							<p class="ghca-acd__reminder-note">
								<span class="ghca-acd__reminder-note-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<span><?php esc_html_e( 'SMS providers may truncate long messages. Every reminder is recorded in this employee’s Communication History with its delivery status.', 'ghca-acd' ); ?></span>
							</p>
						</div>

						<?php $reminder_actor = wp_get_current_user(); ?>
						<div class="ghca-acd__reminder-actions ghca-acd__v2-modal-footer">
							<p class="ghca-acd__reminder-audit">
								<?php
								printf(
									/* translators: %s: the acting administrator's display name. */
									esc_html__( 'Sent and logged as %s in Communication History.', 'ghca-acd' ),
									'<strong>' . esc_html( $reminder_actor && $reminder_actor->display_name ? $reminder_actor->display_name : __( 'this account', 'ghca-acd' ) ) . '</strong>'
								);
								?>
							</p>
							<div class="ghca-acd__reminder-actions-buttons">
								<button type="button" class="ghca-acd__reminder-cancel" data-ghca-reminder-close><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
								<button type="submit" class="ghca-acd__reminder-submit" data-ghca-reminder-submit><?php esc_html_e( 'Send reminder', 'ghca-acd' ); ?></button>
							</div>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( GHCA_ACD_Roles::user_can_view_communication_history() ) : ?>
		<div class="ghca-acd__history-modal ghca-acd__overlay ghca-acd__overlay--history" id="ghca-acd-history-modal" hidden aria-hidden="true">
			<div class="ghca-acd__history-modal-backdrop" data-ghca-history-close></div>
			<div class="ghca-acd__history-modal-dialog ghca-acd__v2-modal ghca-acd__v2-modal--wide" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-history-title">
				<div class="ghca-acd__reminder-modal-header ghca-acd__v2-modal-header">
					<div class="ghca-acd__v2-modal-heading">
						<h2 id="ghca-acd-history-title"><span class="ghca-acd__reminder-title-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'megaphone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'Communication history', 'ghca-acd' ); ?></h2>
						<p class="ghca-acd__history-employee" data-ghca-history-employee></p>
					</div>
					<button type="button" class="ghca-acd__reminder-modal-close" data-ghca-history-close aria-label="<?php esc_attr_e( 'Close communication history', 'ghca-acd' ); ?>">&times;</button>
				</div>
				<div class="ghca-acd__history-modal-body ghca-acd__v2-modal-body">
					<?php /* Populated from the delivery ledger; hidden until loaded. */ ?>
					<div class="ghca-acd__history-stats" data-ghca-history-stats hidden>
						<div class="ghca-acd__history-stat">
							<span class="ghca-acd__history-stat-label"><?php esc_html_e( 'Logged', 'ghca-acd' ); ?></span>
							<span class="ghca-acd__history-stat-value" data-ghca-history-stat="total">0</span>
						</div>
						<div class="ghca-acd__history-stat">
							<span class="ghca-acd__history-stat-label"><?php esc_html_e( 'Email sent', 'ghca-acd' ); ?></span>
							<span class="ghca-acd__history-stat-value" data-ghca-history-stat="email">0</span>
						</div>
						<div class="ghca-acd__history-stat">
							<span class="ghca-acd__history-stat-label"><?php esc_html_e( 'SMS sent', 'ghca-acd' ); ?></span>
							<span class="ghca-acd__history-stat-value" data-ghca-history-stat="sms">0</span>
						</div>
						<div class="ghca-acd__history-stat ghca-acd__history-stat--alert">
							<span class="ghca-acd__history-stat-label"><?php esc_html_e( 'Failed', 'ghca-acd' ); ?></span>
							<span class="ghca-acd__history-stat-value" data-ghca-history-stat="failed">0</span>
						</div>
					</div>
					<form class="ghca-acd__history-filters" data-ghca-history-filters>
						<div class="ghca-acd__history-tools ghca-acd__v2-modal-tools">
							<label class="ghca-acd__history-search"><span class="ghca-acd__sr-only"><?php esc_html_e( 'Search', 'ghca-acd' ); ?></span><input type="search" name="search" maxlength="100" placeholder="<?php esc_attr_e( 'Search subject or message', 'ghca-acd' ); ?>" aria-label="<?php esc_attr_e( 'Search communications', 'ghca-acd' ); ?>" /></label>
							<label><span class="ghca-acd__sr-only"><?php esc_html_e( 'Channel', 'ghca-acd' ); ?></span><select name="channel" aria-label="<?php esc_attr_e( 'Filter by channel', 'ghca-acd' ); ?>"><option value=""><?php esc_html_e( 'All channels', 'ghca-acd' ); ?></option><option value="email"><?php esc_html_e( 'Email', 'ghca-acd' ); ?></option><option value="sms"><?php esc_html_e( 'SMS', 'ghca-acd' ); ?></option></select></label>
							<label><span class="ghca-acd__sr-only"><?php esc_html_e( 'Status', 'ghca-acd' ); ?></span><select name="status" aria-label="<?php esc_attr_e( 'Filter by status', 'ghca-acd' ); ?>"><option value=""><?php esc_html_e( 'All statuses', 'ghca-acd' ); ?></option><option value="queued"><?php esc_html_e( 'Queued', 'ghca-acd' ); ?></option><option value="processing"><?php esc_html_e( 'Sending', 'ghca-acd' ); ?></option><option value="accepted"><?php esc_html_e( 'Accepted', 'ghca-acd' ); ?></option><option value="sent"><?php esc_html_e( 'Sent', 'ghca-acd' ); ?></option><option value="delivered"><?php esc_html_e( 'Delivered', 'ghca-acd' ); ?></option><option value="undelivered"><?php esc_html_e( 'Undelivered', 'ghca-acd' ); ?></option><option value="failed"><?php esc_html_e( 'Failed', 'ghca-acd' ); ?></option><option value="suppressed"><?php esc_html_e( 'Suppressed', 'ghca-acd' ); ?></option></select></label>
						</div>
						<details class="ghca-acd__history-advanced">
							<summary><?php esc_html_e( 'Date range', 'ghca-acd' ); ?></summary>
							<div class="ghca-acd__history-advanced-fields">
								<label><span><?php esc_html_e( 'From', 'ghca-acd' ); ?></span><input type="date" name="date_from" /></label>
								<label><span><?php esc_html_e( 'To', 'ghca-acd' ); ?></span><input type="date" name="date_to" /></label>
								<div class="ghca-acd__history-filter-actions"><button type="submit"><?php esc_html_e( 'Apply', 'ghca-acd' ); ?></button><button type="reset" data-ghca-history-reset><?php esc_html_e( 'Clear', 'ghca-acd' ); ?></button></div>
							</div>
						</details>
					</form>
					<div class="ghca-acd__history-modal-status ghca-acd__v2-result-line" data-ghca-history-status role="status"><span data-ghca-history-count></span><span><?php esc_html_e( 'Newest first', 'ghca-acd' ); ?></span></div>
					<div class="ghca-acd__history-modal-results" data-ghca-history-results aria-live="polite"></div>
				</div>
				<div class="ghca-acd__history-pagination ghca-acd__v2-modal-footer" data-ghca-history-pagination hidden>
					<div class="ghca-acd__v2-pager">
						<span data-ghca-history-page-label></span>
						<span class="ghca-acd__v2-pager-actions"><button type="button" data-ghca-history-page="previous"><?php esc_html_e( 'Previous', 'ghca-acd' ); ?></button><button type="button" data-ghca-history-page="next"><?php esc_html_e( 'Next', 'ghca-acd' ); ?></button></span>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php
	}

	public static function ajax_get_context(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$result  = GHCA_ACD_Messaging_Service::get_context( $user_id );
		self::send_result( $result );
	}

	public static function ajax_send(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$result = GHCA_ACD_Messaging_Service::queue(
			array(
				'employee_user_id' => isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0,
				'channel'          => isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '',
				'urgency'          => isset( $_POST['urgency'] ) ? sanitize_key( wp_unslash( $_POST['urgency'] ) ) : 'normal',
				'template_id'      => isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0,
				'subject'          => isset( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '',
				'message'          => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'sms_safe_confirmed'=> ! empty( $_POST['sms_safe_confirmed'] ),
				'idempotency_token'=> isset( $_POST['idempotency_token'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_token'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			self::send_result( $result );
		}

		wp_send_json_success(
			array(
				'message'   => ! empty( $result['duplicate'] )
					? __( 'This reminder request was already received.', 'ghca-acd' )
					: __( 'The reminder was queued.', 'ghca-acd' ),
				'state'     => (string) ( $result['state'] ?? 'queued' ),
				'duplicate' => ! empty( $result['duplicate'] ),
			)
		);
	}

	public static function ajax_history(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		if ( ! GHCA_ACD_Roles::user_can_view_communication_history() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view communication history.', 'ghca-acd' ) ) );
		}
		if ( ! self::schema_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Messaging setup is incomplete.', 'ghca-acd' ) ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$page    = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
		}

		$filters = array(
			'channel'   => isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '',
			'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
			'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
			'search'    => isset( $_POST['search'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['search'] ) ), 0, 100 ) : '',
		);
		$per_page = 20;
		$total    = GHCA_ACD_Messaging_Repository::history_count( $user_id, $filters );
		$user     = get_userdata( $user_id );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$rows     = GHCA_ACD_Messaging_Repository::history_for_employee( $user_id, $page, $per_page, $filters );
		wp_send_json_success(
			array(
				'html'         => self::render_history_items( $rows, false ),
				'total'        => $total,
				'page'         => $page,
				'pages'        => $pages,
				'employeeName' => $user ? (string) $user->display_name : __( 'Employee', 'ghca-acd' ),
				/*
				 * Real recorded totals only. The schema has no read/open tracking,
				 * so no "read rate" is reported -- that would be invented evidence
				 * on an audit screen.
				 */
				'stats'        => GHCA_ACD_Messaging_Repository::history_stats( $user_id ),
			)
		);
	}

	public static function ajax_communication_draft(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		if ( ! GHCA_ACD_Roles::user_can_view_communication_history() || ! GHCA_ACD_Roles::user_can_send_reminders() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reuse reminder messages.', 'ghca-acd' ) ) );
		}
		if ( ! self::schema_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Messaging setup is incomplete.', 'ghca-acd' ) ) );
		}

		$user_id          = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$communication_id = isset( $_POST['communication_id'] ) ? (int) $_POST['communication_id'] : 0;
		if ( $user_id <= 0 || $communication_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
		}

		$row = GHCA_ACD_Messaging_Repository::get_communication_for_employee( $communication_id, $user_id );
		if ( ! $row || 'employee_drawer' !== (string) $row['source'] ) {
			wp_send_json_error( array( 'message' => __( 'This communication cannot be reused as a reminder.', 'ghca-acd' ) ) );
		}

		wp_send_json_success(
			array(
				'userId'  => $user_id,
				'subject' => self::strip_urgency_prefix( (string) $row['subject'] ),
				'message' => self::strip_urgency_prefix( (string) $row['message'] ),
				'urgency' => GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) $row['urgency'] ),
				'channel' => in_array( (string) $row['requested_channels'], array( 'email', 'sms', 'email_sms' ), true ) ? (string) $row['requested_channels'] : 'email',
			)
		);
	}

	public static function ajax_retry(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$delivery_id = isset( $_POST['delivery_id'] ) ? (int) $_POST['delivery_id'] : 0;
		$result      = GHCA_ACD_Messaging_Service::retry( $delivery_id );
		if ( is_wp_error( $result ) ) {
			self::send_result( $result );
		}
		wp_send_json_success( array( 'message' => __( 'The failed delivery was queued for another attempt.', 'ghca-acd' ) ) );
	}

	public static function render_history_section( int $employee_user_id ): string {
		if ( ! GHCA_ACD_Roles::user_can_view_communication_history() || ! self::schema_ready() ) {
			return '';
		}

		$rows  = GHCA_ACD_Messaging_Repository::history_for_employee( $employee_user_id, 1, 3 );
		$total = GHCA_ACD_Messaging_Repository::history_count( $employee_user_id );
		$employee = get_userdata( $employee_user_id );
		$employee_name = $employee ? (string) $employee->display_name : '';
		ob_start();
		?>
		<div class="ghca-acd__communication-history" data-ghca-history-user="<?php echo esc_attr( (string) $employee_user_id ); ?>">
			<div class="ghca-acd__history-section-head">
				<div><h4><?php esc_html_e( 'Communication History', 'ghca-acd' ); ?></h4><?php if ( $total > 0 ) : ?><span><?php echo esc_html( sprintf( _n( '%d communication', '%d communications', $total, 'ghca-acd' ), $total ) ); ?></span><?php endif; ?></div>
				<?php if ( GHCA_ACD_Roles::user_can_send_reminders() ) : ?><button type="button" data-ghca-reminder="<?php echo esc_attr( (string) $employee_user_id ); ?>" data-ghca-reminder-name="<?php echo esc_attr( $employee_name ); ?>">+ <?php esc_html_e( 'New Reminder', 'ghca-acd' ); ?></button><?php endif; ?>
			</div>
			<div data-ghca-history-list>
				<?php echo self::render_history_items( $rows, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $total > 0 ) : ?>
				<p class="ghca-acd__history-summary"><?php echo esc_html( sprintf( __( '%1$d communications - %2$d shown', 'ghca-acd' ), $total, min( 3, $total ) ) ); ?></p>
				<button type="button" class="ghca-acd__history-more" data-ghca-history-open data-user-id="<?php echo esc_attr( (string) $employee_user_id ); ?>"><?php esc_html_e( 'View all communication history', 'ghca-acd' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function render_history_items( array $rows, bool $compact = false ): string {
		if ( empty( $rows ) ) {
			return '<p class="ghca-acd__history-empty">' . esc_html__( 'No reminders have been recorded for this employee.', 'ghca-acd' ) . '</p>';
		}

		$communications = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['communication_id'];
			if ( ! isset( $communications[ $id ] ) ) {
				$communications[ $id ] = array( 'row' => $row, 'deliveries' => array() );
			}
			$communications[ $id ]['deliveries'][] = $row;
		}

		ob_start();
		foreach ( $communications as $communication ) {
			$row          = $communication['row'];
			$deliveries   = $communication['deliveries'];
			$actor        = get_userdata( (int) $row['actor_user_id'] );
			$actor_name   = $actor ? $actor->display_name : __( 'Former administrator', 'ghca-acd' );
			$urgency      = GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) $row['urgency'] );
			$created_ts   = strtotime( (string) $row['created_at'] . ' UTC' );
			$created      = $created_ts ? wp_date( 'M j, Y g:i a', $created_ts ) : (string) $row['created_at'];
			$message       = (string) $row['message'];
			$subject       = '' !== trim( (string) $row['subject'] ) ? (string) $row['subject'] : __( 'SMS reminder', 'ghca-acd' );
			$can_reuse     = ! $compact && 'employee_drawer' === (string) ( $row['source'] ?? '' ) && GHCA_ACD_Roles::user_can_send_reminders();
			if ( $compact ) {
				$channels = array();
				foreach ( $deliveries as $delivery ) {
					$channel = sanitize_key( (string) $delivery['channel'] );
					$channels[ $channel ] = 'sms' === $channel ? 'SMS' : __( 'Email', 'ghca-acd' );
				}
				?>
				<article class="ghca-acd__history-item ghca-acd__history-item--<?php echo esc_attr( $urgency ); ?>">
					<div class="ghca-acd__history-card-top">
						<div class="ghca-acd__history-card-meta"><span><?php echo esc_html( implode( ' + ', array_values( $channels ) ) ); ?></span><time<?php echo $created_ts ? ' datetime="' . esc_attr( gmdate( 'c', $created_ts ) ) . '"' : ''; ?>><?php echo esc_html( $created ); ?></time></div>
						<div class="ghca-acd__history-card-statuses">
						<?php foreach ( $deliveries as $delivery ) : $state = sanitize_key( (string) $delivery['delivery_state'] ); $channel = sanitize_key( (string) $delivery['channel'] ); ?>
							<span class="ghca-acd__history-status ghca-acd__history-status--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( self::delivery_status_label( $state, $channel ) ); ?></span>
						<?php endforeach; ?>
						</div>
					</div>
					<h3><?php echo esc_html( $subject ); ?></h3>
					<p class="ghca-acd__history-card-excerpt"><?php echo nl2br( esc_html( $message ) ); ?></p>
					<details class="ghca-acd__history-message"><summary><?php esc_html_e( 'View full message', 'ghca-acd' ); ?> <span aria-hidden="true">→</span></summary><p><?php echo nl2br( esc_html( $message ) ); ?></p></details>
					<?php foreach ( $deliveries as $delivery ) : $state = sanitize_key( (string) $delivery['delivery_state'] ); $channel = sanitize_key( (string) $delivery['channel'] ); $sms_uncertain = 'sms' === $channel && in_array( (string) ( $delivery['last_error_code'] ?? '' ), array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' ), true ); $can_retry = 'failed' === $state && ! $sms_uncertain && ( 'sms' !== $channel || empty( $delivery['provider_ref'] ) ) && (int) $delivery['attempt_count'] < GHCA_ACD_Messaging_Repository::MAX_DELIVERY_ATTEMPTS && GHCA_ACD_Roles::user_can_send_reminders(); ?>
						<?php if ( $can_retry ) : ?><button type="button" class="ghca-acd__history-retry" data-ghca-reminder-retry="<?php echo esc_attr( (string) $delivery['delivery_id'] ); ?>"><?php echo esc_html( sprintf( __( 'Retry %s', 'ghca-acd' ), strtoupper( $channel ) ) ); ?></button><?php endif; ?>
						<?php if ( 'failed' === $state && ! empty( $delivery['last_error_summary'] ) ) : ?><p class="ghca-acd__history-error"><?php echo esc_html( (string) $delivery['last_error_summary'] ); ?></p><?php endif; ?>
					<?php endforeach; ?>
				</article>
				<?php
				continue;
			}
			?>
			<?php $lead_channel = sanitize_key( (string) ( $deliveries[0]['channel'] ?? 'email' ) ); ?>
			<article class="ghca-acd__history-item ghca-acd__history-item--<?php echo esc_attr( $urgency ); ?>">
				<span class="ghca-acd__history-item-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'sms' === $lead_channel ? 'sms' : 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="ghca-acd__history-item-body">
				<div class="ghca-acd__history-head">
					<strong><?php echo esc_html( $subject ); ?></strong>
					<span class="ghca-acd__history-urgency"><?php echo esc_html( ucfirst( $urgency ) ); ?></span>
				</div>
				<div class="ghca-acd__history-deliveries">
				<?php foreach ( $deliveries as $delivery ) : ?>
					<?php
					$state          = sanitize_key( (string) $delivery['delivery_state'] );
					$channel        = sanitize_key( (string) $delivery['channel'] );
					$sms_uncertain  = 'sms' === $channel && in_array( (string) ( $delivery['last_error_code'] ?? '' ), array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' ), true );
					$can_retry      = 'failed' === $state && ! $sms_uncertain && ( 'sms' !== $channel || empty( $delivery['provider_ref'] ) ) && (int) $delivery['attempt_count'] < GHCA_ACD_Messaging_Repository::MAX_DELIVERY_ATTEMPTS && GHCA_ACD_Roles::user_can_send_reminders();
					$status_label   = self::delivery_status_label( $state, $channel );
					?>
					<div class="ghca-acd__history-delivery">
						<span><?php echo esc_html( strtoupper( $channel ) . ' · ' . (string) $delivery['masked_destination'] ); ?></span>
						<span class="ghca-acd__history-status ghca-acd__history-status--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $status_label ); ?></span>
						<?php if ( $can_retry ) : ?><button type="button" class="ghca-acd__history-retry" data-ghca-reminder-retry="<?php echo esc_attr( (string) $delivery['delivery_id'] ); ?>"><?php echo esc_html( sprintf( __( 'Retry %s', 'ghca-acd' ), strtoupper( $channel ) ) ); ?></button><?php endif; ?>
					</div>
					<?php if ( 'failed' === $state && ! empty( $delivery['last_error_summary'] ) ) : ?><p class="ghca-acd__history-error"><?php echo esc_html( (string) $delivery['last_error_summary'] ); ?></p><?php endif; ?>
				<?php endforeach; ?>
				</div>
				<div class="ghca-acd__history-meta">
					<span><?php echo esc_html( $created . ' · ' . $actor_name ); ?></span>
				</div>
				<details class="ghca-acd__history-message"><summary><?php esc_html_e( 'View full message', 'ghca-acd' ); ?></summary><p><?php echo nl2br( esc_html( $message ) ); ?></p></details>
				<?php if ( $can_reuse ) : ?><button type="button" class="ghca-acd__history-reuse" data-ghca-history-reuse="<?php echo esc_attr( (string) $row['communication_id'] ); ?>"><?php esc_html_e( 'Use again', 'ghca-acd' ); ?></button><?php endif; ?>
				</div>
			</article>
			<?php
		}
		return (string) ob_get_clean();
	}

	private static function delivery_status_label( string $state, string $channel = 'email' ): string {
		$labels = array(
			'queued'     => __( 'Queued', 'ghca-acd' ),
			'processing' => __( 'Sending', 'ghca-acd' ),
			'accepted'   => __( 'Accepted by email transport', 'ghca-acd' ),
			'sent'       => __( 'Sent', 'ghca-acd' ),
			'delivered'  => __( 'Delivered', 'ghca-acd' ),
			'undelivered'=> __( 'Undelivered', 'ghca-acd' ),
			'failed'     => __( 'Failed', 'ghca-acd' ),
			'suppressed' => __( 'Suppressed', 'ghca-acd' ),
		);
		if ( 'accepted' === $state && 'sms' === $channel ) {
			return __( 'Accepted by Twilio', 'ghca-acd' );
		}
		return $labels[ $state ] ?? ucfirst( str_replace( '_', ' ', $state ) );
	}

	private static function strip_urgency_prefix( string $value ): string {
		$stripped = preg_replace( '/^(?:IMPORTANT|URGENT):\s*/i', '', ltrim( $value ) );
		return is_string( $stripped ) ? $stripped : $value;
	}

	public static function render_settings_page(): void {
		$can_manage_settings  = GHCA_ACD_Roles::user_can_manage_messaging_settings();
		$can_manage_templates = GHCA_ACD_Roles::user_can_manage_message_templates();
		if ( ! $can_manage_settings && ! $can_manage_templates ) {
			return;
		}

		$schema_ready = self::schema_ready();
		$templates = $schema_ready ? GHCA_ACD_Messaging_Repository::get_all_templates() : array();
		$twilio    = GHCA_ACD_Settings::twilio_config();
		$notice    = isset( $_GET['ghca_message'] ) ? sanitize_key( wp_unslash( $_GET['ghca_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices   = array(
			'template_saved' => array( 'success', __( 'Reminder template saved.', 'ghca-acd' ) ),
			'template_error' => array( 'error', __( 'The reminder template could not be saved. Check its fields and placeholders.', 'ghca-acd' ) ),
			'test_sent'      => array( 'success', __( 'The test email was accepted by the WordPress mail transport.', 'ghca-acd' ) ),
			'test_failed'    => array( 'error', __( 'The test email was not accepted by the WordPress mail transport.', 'ghca-acd' ) ),
			'twilio_connected'=> array( 'success', __( 'The Twilio credentials and Messaging Service identity were verified.', 'ghca-acd' ) ),
			'twilio_failed'   => array( 'error', __( 'The Twilio connection test failed. Review the configuration and required restricted-key permissions.', 'ghca-acd' ) ),
			'sms_test_sent'   => array( 'success', __( 'Twilio accepted the test SMS.', 'ghca-acd' ) ),
			'sms_test_failed' => array( 'error', __( 'The test SMS failed. Verify the phone, consent-independent test destination, and Twilio configuration.', 'ghca-acd' ) ),
			'consent_saved'   => array( 'success', __( 'The phone-specific SMS consent event was recorded.', 'ghca-acd' ) ),
			'consent_failed'  => array( 'error', __( 'The SMS consent event could not be recorded. Verify the employee ID and E.164 phone.', 'ghca-acd' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Compliance Messaging', 'ghca-acd' ); ?></h1>
			<?php if ( ! $schema_ready ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Messaging tables could not be verified. Email reminders remain unavailable until the database migration succeeds.', 'ghca-acd' ); ?></p></div>
			<?php endif; ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WP-Cron is disabled. Configure the server to request wp-cron.php regularly or queued reminder emails will not run.', 'ghca-acd' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $can_manage_settings ) : ?>
			<h2><?php esc_html_e( 'Email reminders', 'ghca-acd' ); ?></h2>
			<p><?php esc_html_e( 'A successful WordPress mail response means accepted for transport, not confirmed delivery.', 'ghca-acd' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'ghca_acd_messaging' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable email reminders', 'ghca-acd' ); ?></th>
						<td><input type="hidden" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_EMAIL_ENABLED ); ?>" value="0" /><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_EMAIL_ENABLED ); ?>" value="1" <?php checked( GHCA_ACD_Settings::reminder_email_enabled() ); ?> /> <?php esc_html_e( 'Allow authorized dashboard users to queue email reminders', 'ghca-acd' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghca_reminder_from_name"><?php esc_html_e( 'Sender name', 'ghca-acd' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ghca_reminder_from_name" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_FROM_NAME ); ?>" value="<?php echo esc_attr( GHCA_ACD_Settings::get_reminder_from_name() ); ?>" maxlength="120" /><p class="description"><?php echo esc_html( sprintf( __( 'Email address: %s', 'ghca-acd' ), (string) get_option( 'admin_email', '' ) ) ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghca_reminder_reply_to"><?php esc_html_e( 'Reply-to email', 'ghca-acd' ); ?></label></th>
						<td><input type="email" class="regular-text" id="ghca_reminder_reply_to" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_REPLY_TO ); ?>" value="<?php echo esc_attr( GHCA_ACD_Settings::get_reminder_reply_to() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Branded HTML email', 'ghca-acd' ); ?></th>
						<td><input type="hidden" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_HTML_ENABLED ); ?>" value="0" /><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_HTML_ENABLED ); ?>" value="1" <?php checked( GHCA_ACD_Settings::reminder_html_enabled() ); ?> /> <?php esc_html_e( 'Use the configured agency name, logo and colors in reminder email', 'ghca-acd' ); ?></label><p class="description"><?php esc_html_e( 'The logo is pulled from Compliance Admin -> Dashboard Branding. Every HTML email also contains a plain-text alternative.', 'ghca-acd' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghca_reminder_button_label"><?php esc_html_e( 'Portal button label', 'ghca-acd' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ghca_reminder_button_label" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_BUTTON_LABEL ); ?>" value="<?php echo esc_attr( GHCA_ACD_Settings::get_reminder_button_label() ); ?>" maxlength="80" /><p class="description"><?php esc_html_e( 'The button opens the configured employee training portal page.', 'ghca-acd' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghca_reminder_footer_text"><?php esc_html_e( 'Email footer text', 'ghca-acd' ); ?></label></th>
						<td><input type="text" class="large-text" id="ghca_reminder_footer_text" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_FOOTER_TEXT ); ?>" value="<?php echo esc_attr( GHCA_ACD_Settings::get_reminder_footer_text() ); ?>" maxlength="300" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Inactive employees', 'ghca-acd' ); ?></th>
						<td><input type="hidden" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_ALLOW_INACTIVE ); ?>" value="0" /><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_REMINDER_ALLOW_INACTIVE ); ?>" value="1" <?php checked( GHCA_ACD_Settings::allow_inactive_reminders() ); ?> /> <?php esc_html_e( 'Allow reminders to suspended/inactive employee accounts', 'ghca-acd' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save email settings', 'ghca-acd' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 32px;">
				<input type="hidden" name="action" value="ghca_acd_send_test_reminder_email" />
				<?php wp_nonce_field( 'ghca_acd_test_reminder_email' ); ?>
				<?php submit_button( __( 'Send test email to me', 'ghca-acd' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'SMS reminders', 'ghca-acd' ); ?></h2>
			<?php if ( ! GHCA_ACD_Messaging_Secret_Store::available() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'SMS fails closed until GHCA_ACD_TWILIO_ENCRYPTION_KEY is defined outside the database with at least 32 random characters.', 'ghca-acd' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Use agency-owned credentials or credentials restricted to this agency’s Gridhouse-managed Twilio subaccount. Never enter the Gridhouse parent-account token.', 'ghca-acd' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'ghca_acd_sms' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Enable SMS', 'ghca-acd' ); ?></th><td><input type="hidden" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_SMS_ENABLED ); ?>" value="0" /><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_SMS_ENABLED ); ?>" value="1" <?php checked( GHCA_ACD_Settings::sms_enabled() ); ?> /> <?php esc_html_e( 'Allow SMS only after all readiness checks pass', 'ghca-acd' ); ?></label></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_mode"><?php esc_html_e( 'Account mode', 'ghca-acd' ); ?></label></th><td><select id="ghca_twilio_mode" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_ACCOUNT_MODE ); ?>"><option value="agency_owned" <?php selected( $twilio['account_mode'], 'agency_owned' ); ?>><?php esc_html_e( 'Agency-owned Twilio account', 'ghca-acd' ); ?></option><option value="gridhouse_managed_subaccount" <?php selected( $twilio['account_mode'], 'gridhouse_managed_subaccount' ); ?>><?php esc_html_e( 'Gridhouse-managed agency subaccount', 'ghca-acd' ); ?></option></select></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_account_sid"><?php esc_html_e( 'Agency/subaccount SID', 'ghca-acd' ); ?></label></th><td><input class="regular-text code" id="ghca_twilio_account_sid" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_ACCOUNT_SID ); ?>" value="<?php echo esc_attr( (string) $twilio['account_sid'] ); ?>" placeholder="AC…" autocomplete="off" /></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_key_sid"><?php esc_html_e( 'Restricted API Key SID', 'ghca-acd' ); ?></label></th><td><input class="regular-text code" id="ghca_twilio_key_sid" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_API_KEY_SID ); ?>" value="<?php echo esc_attr( (string) $twilio['api_key_sid'] ); ?>" placeholder="SK…" autocomplete="off" /><p class="description"><?php esc_html_e( 'Grant only Messaging Service read and Message create permissions.', 'ghca-acd' ); ?></p></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_api_secret"><?php esc_html_e( 'Restricted API secret', 'ghca-acd' ); ?></label></th><td><input type="password" class="regular-text" id="ghca_twilio_api_secret" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_API_SECRET ); ?>" value="" autocomplete="new-password" /><p class="description"><?php echo esc_html( '' !== $twilio['api_secret_encrypted'] ? __( 'Stored encrypted. Leave blank to keep it.', 'ghca-acd' ) : __( 'Not stored.', 'ghca-acd' ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_webhook_token"><?php esc_html_e( 'Agency/subaccount Auth Token for webhooks', 'ghca-acd' ); ?></label></th><td><input type="password" class="regular-text" id="ghca_twilio_webhook_token" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_WEBHOOK_AUTH_TOKEN ); ?>" value="" autocomplete="new-password" /><p class="description"><?php echo esc_html( '' !== $twilio['webhook_token_encrypted'] ? __( 'Stored encrypted. Leave blank to keep it. Used only to verify Twilio signatures.', 'ghca-acd' ) : __( 'Not stored. Do not use a Gridhouse parent-account token.', 'ghca-acd' ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_service_sid"><?php esc_html_e( 'Messaging Service SID', 'ghca-acd' ); ?></label></th><td><input class="regular-text code" id="ghca_twilio_service_sid" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_MESSAGING_SERVICE_SID ); ?>" value="<?php echo esc_attr( (string) $twilio['messaging_service_sid'] ); ?>" placeholder="MG…" autocomplete="off" /></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_sender_name"><?php esc_html_e( 'SMS brand name', 'ghca-acd' ); ?></label></th><td><input class="regular-text" id="ghca_twilio_sender_name" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_SENDER_NAME ); ?>" value="<?php echo esc_attr( (string) $twilio['sender_name'] ); ?>" maxlength="120" /></td></tr>
					<tr><th scope="row"><label for="ghca_twilio_a2p_status"><?php esc_html_e( 'Messaging registration', 'ghca-acd' ); ?></label></th><td><select id="ghca_twilio_a2p_status" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_A2P_STATUS ); ?>"><option value="not_ready" <?php selected( $twilio['a2p_status'], 'not_ready' ); ?>><?php esc_html_e( 'Not ready', 'ghca-acd' ); ?></option><option value="pending" <?php selected( $twilio['a2p_status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'ghca-acd' ); ?></option><option value="active" <?php selected( $twilio['a2p_status'], 'active' ); ?>><?php esc_html_e( 'Active', 'ghca-acd' ); ?></option><option value="not_applicable" <?php selected( $twilio['a2p_status'], 'not_applicable' ); ?>><?php esc_html_e( 'Not applicable to this sender/use case', 'ghca-acd' ); ?></option></select> <input class="regular-text" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_A2P_REFERENCE ); ?>" value="<?php echo esc_attr( (string) $twilio['a2p_reference'] ); ?>" placeholder="Campaign/reference" maxlength="191" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Advanced Opt-Out', 'ghca-acd' ); ?></th><td><input type="hidden" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_ADVANCED_OPT_OUT ); ?>" value="0" /><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_TWILIO_ADVANCED_OPT_OUT ); ?>" value="1" <?php checked( ! empty( $twilio['advanced_opt_out'] ) ); ?> /> <?php esc_html_e( 'I attest that Advanced Opt-Out is enabled for this Messaging Service with STOP, START/YES/UNSTOP, and HELP behavior.', 'ghca-acd' ); ?></label></td></tr>
					<tr><th scope="row"><label for="ghca_sms_disclosure"><?php esc_html_e( 'Consent disclosure version', 'ghca-acd' ); ?></label></th><td><input id="ghca_sms_disclosure" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION ); ?>" value="<?php echo esc_attr( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION, '1' ) ); ?>" maxlength="64" /> <input type="url" class="regular-text" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_SMS_PRIVACY_URL ); ?>" value="<?php echo esc_attr( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_PRIVACY_URL, '' ) ); ?>" placeholder="Privacy Policy URL" /> <input type="url" class="regular-text" name="<?php echo esc_attr( GHCA_ACD_Settings::OPTION_SMS_TERMS_URL ); ?>" value="<?php echo esc_attr( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_TERMS_URL, '' ) ); ?>" placeholder="Terms URL" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Twilio callbacks', 'ghca-acd' ); ?></th><td><p><strong><?php esc_html_e( 'Status:', 'ghca-acd' ); ?></strong> <code><?php echo esc_html( GHCA_ACD_Twilio_Webhooks::status_url() ); ?></code></p><p><strong><?php esc_html_e( 'Inbound/opt-out:', 'ghca-acd' ); ?></strong> <code><?php echo esc_html( GHCA_ACD_Twilio_Webhooks::inbound_url() ); ?></code></p><p class="description"><?php esc_html_e( 'Production callback URLs must be public HTTPS URLs. Configure the inbound URL on the Messaging Service and enable Advanced Opt-Out.', 'ghca-acd' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'Save email and SMS settings', 'ghca-acd' ) ); ?>
			</form>

			<p><?php echo esc_html( GHCA_ACD_Settings::twilio_connection_is_current() ? __( 'Connection test: current', 'ghca-acd' ) : __( 'Connection test: required after saving configuration', 'ghca-acd' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;"><input type="hidden" name="action" value="ghca_acd_test_twilio_connection" /><?php wp_nonce_field( 'ghca_acd_test_twilio_connection' ); ?><?php submit_button( __( 'Test Twilio connection', 'ghca-acd' ), 'secondary', 'submit', false ); ?></form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"><input type="hidden" name="action" value="ghca_acd_send_test_sms" /><?php wp_nonce_field( 'ghca_acd_send_test_sms' ); ?><label><?php esc_html_e( 'Test destination', 'ghca-acd' ); ?> <input name="test_phone" placeholder="+14165551234" autocomplete="off" /></label> <?php submit_button( __( 'Send test SMS', 'ghca-acd' ), 'secondary', 'submit', false ); ?></form>

			<h3><?php esc_html_e( 'Record documented employee SMS consent', 'ghca-acd' ); ?></h3>
			<p><?php esc_html_e( 'Use only after the employee has actively consented through the approved web or paper disclosure. Consent is tied to the exact E.164 phone number.', 'ghca-acd' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ghca_acd_record_sms_consent" /><?php wp_nonce_field( 'ghca_acd_record_sms_consent' ); ?><input type="number" min="1" name="employee_user_id" placeholder="Employee User ID" required /> <input name="phone" placeholder="+14165551234" required /> <select name="consent_state"><option value="opted_in"><?php esc_html_e( 'Opted in', 'ghca-acd' ); ?></option><option value="opted_out"><?php esc_html_e( 'Opted out/correction', 'ghca-acd' ); ?></option></select> <select name="consent_source"><option value="web_form"><?php esc_html_e( 'Web form', 'ghca-acd' ); ?></option><option value="paper_form"><?php esc_html_e( 'Paper form', 'ghca-acd' ); ?></option><option value="administrative_correction"><?php esc_html_e( 'Administrative correction', 'ghca-acd' ); ?></option></select> <input name="evidence_reference" maxlength="191" placeholder="Evidence reference" /> <?php submit_button( __( 'Record consent event', 'ghca-acd' ), 'secondary', 'submit', false ); ?></form>
			<?php endif; ?>

			<?php if ( $can_manage_templates ) : ?>
			<h2><?php esc_html_e( 'Reusable reminder templates', 'ghca-acd' ); ?></h2>
			<p><?php esc_html_e( 'Allowed placeholders: [employee_first_name], [agency_name], [due_date], [portal_url], [compliance_contact_number]. Templates are plain text.', 'ghca-acd' ); ?></p>
			<?php if ( $schema_ready ) : ?>
				<?php foreach ( $templates as $template ) : ?>
					<?php self::render_template_form( $template ); ?>
				<?php endforeach; ?>
				<h3><?php esc_html_e( 'Create template', 'ghca-acd' ); ?></h3>
				<?php self::render_template_form( array() ); ?>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $template */
	private static function render_template_form( array $template ): void {
		$id = isset( $template['id'] ) ? (int) $template['id'] : 0;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;border:1px solid #ccd0d4;background:#fff;padding:16px;margin:0 0 18px;">
			<input type="hidden" name="action" value="ghca_acd_save_message_template" />
			<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'ghca_acd_save_template' ); ?>
			<p><label><strong><?php esc_html_e( 'Name', 'ghca-acd' ); ?></strong><br /><input type="text" class="regular-text" name="template_name" maxlength="120" required value="<?php echo esc_attr( (string) ( $template['name'] ?? '' ) ); ?>" /></label></p>
			<p><label><strong><?php esc_html_e( 'Description', 'ghca-acd' ); ?></strong><br /><input type="text" class="large-text" name="template_description" maxlength="500" value="<?php echo esc_attr( (string) ( $template['description'] ?? '' ) ); ?>" /></label></p>
			<p><label><strong><?php esc_html_e( 'Default urgency', 'ghca-acd' ); ?></strong><br /><select name="template_urgency"><option value="normal" <?php selected( (string) ( $template['default_urgency'] ?? 'normal' ), 'normal' ); ?>><?php esc_html_e( 'Normal', 'ghca-acd' ); ?></option><option value="important" <?php selected( (string) ( $template['default_urgency'] ?? '' ), 'important' ); ?>><?php esc_html_e( 'Important', 'ghca-acd' ); ?></option><option value="urgent" <?php selected( (string) ( $template['default_urgency'] ?? '' ), 'urgent' ); ?>><?php esc_html_e( 'Urgent', 'ghca-acd' ); ?></option></select></label></p>
			<p><label><strong><?php esc_html_e( 'Allowed delivery methods', 'ghca-acd' ); ?></strong><br /><select name="template_channels"><option value="email" <?php selected( (string) ( $template['allowed_channels'] ?? 'email' ), 'email' ); ?>><?php esc_html_e( 'Email only', 'ghca-acd' ); ?></option><option value="sms" <?php selected( (string) ( $template['allowed_channels'] ?? '' ), 'sms' ); ?>><?php esc_html_e( 'SMS only', 'ghca-acd' ); ?></option><option value="sms,email_sms" <?php selected( (string) ( $template['allowed_channels'] ?? '' ), 'sms,email_sms' ); ?>><?php esc_html_e( 'SMS and Email + SMS', 'ghca-acd' ); ?></option><option value="email,email_sms" <?php selected( (string) ( $template['allowed_channels'] ?? '' ), 'email,email_sms' ); ?>><?php esc_html_e( 'Email and Email + SMS', 'ghca-acd' ); ?></option></select></label></p>
			<p><label><strong><?php esc_html_e( 'Subject', 'ghca-acd' ); ?></strong><br /><input type="text" class="large-text" name="template_subject" maxlength="200" value="<?php echo esc_attr( (string) ( $template['subject_template'] ?? '' ) ); ?>" /></label></p>
			<p><label><strong><?php esc_html_e( 'Message', 'ghca-acd' ); ?></strong><br /><textarea class="large-text" rows="7" name="template_message" maxlength="4000" required><?php echo esc_textarea( (string) ( $template['message_template'] ?? '' ) ); ?></textarea></label></p>
			<input type="hidden" name="template_active" value="0" /><p><label><input type="checkbox" name="template_active" value="1" <?php checked( ! isset( $template['active'] ) || ! empty( $template['active'] ) ); ?> /> <?php esc_html_e( 'Active', 'ghca-acd' ); ?></label></p>
			<?php submit_button( $id > 0 ? __( 'Update template', 'ghca-acd' ) : __( 'Create template', 'ghca-acd' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function handle_save_template(): void {
		if ( ! GHCA_ACD_Roles::user_can_manage_message_templates() ) {
			wp_die( esc_html__( 'You do not have permission to manage reminder templates.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ghca_acd_save_template' );
		if ( (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) < GHCA_ACD_Messaging_Schema::VERSION || ! GHCA_ACD_Messaging_Schema::tables_exist() ) {
			self::redirect_settings( 'template_error' );
		}

		$name        = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$description = isset( $_POST['template_description'] ) ? sanitize_text_field( wp_unslash( $_POST['template_description'] ) ) : '';
		$urgency     = GHCA_ACD_Message_Template_Renderer::normalize_urgency( isset( $_POST['template_urgency'] ) ? sanitize_key( wp_unslash( $_POST['template_urgency'] ) ) : 'normal' );
		$subject     = isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '';
		$message     = isset( $_POST['template_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template_message'] ) ) : '';
		$channels    = isset( $_POST['template_channels'] ) ? sanitize_text_field( wp_unslash( $_POST['template_channels'] ) ) : 'email';
		$allowed_channel_sets = array( 'email', 'sms', 'sms,email_sms', 'email,email_sms' );
		$subject_required = false !== strpos( $channels, 'email' );
		$validation  = GHCA_ACD_Message_Template_Renderer::validate_lengths( $subject, $message, $subject_required );
		$render_test = GHCA_ACD_Message_Template_Renderer::render( $subject . "\n" . $message, array() );

		$result = null;
		if ( '' === $name || ! in_array( $channels, $allowed_channel_sets, true ) || is_wp_error( $validation ) || is_wp_error( $render_test ) || ( false !== strpos( $channels, 'sms' ) && ( function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message ) ) > GHCA_ACD_Messaging_Service::SMS_MESSAGE_MAX ) ) {
			$result = new WP_Error( 'ghca_template_invalid', __( 'The template fields are invalid.', 'ghca-acd' ) );
		} else {
			$result = GHCA_ACD_Messaging_Repository::save_template(
				array(
					'id'                => isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0,
					'name'              => $name,
					'description'       => $description,
					'default_urgency'   => $urgency,
					'allowed_channels'  => $channels,
					'subject_template'  => $subject,
					'message_template'  => $message,
					'active'            => ! empty( $_POST['template_active'] ),
					'actor_user_id'     => get_current_user_id(),
				)
			);
		}

		self::redirect_settings( is_wp_error( $result ) ? 'template_error' : 'template_saved' );
	}

	public static function handle_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test reminder email.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ghca_acd_test_reminder_email' );
		$result = GHCA_ACD_Email_Provider::send(
			array(
				'employee_user_id' => get_current_user_id(),
				'subject'          => __( 'Gridhouse Digital reminder email test', 'ghca-acd' ),
				'message'          => __( 'This is a test of the compliance reminder email transport. No employee communication record was created.', 'ghca-acd' ),
			)
		);
		self::redirect_settings( is_wp_error( $result ) ? 'test_failed' : 'test_sent' );
	}

	public static function handle_test_twilio_connection(): void {
		if ( ! GHCA_ACD_Roles::user_can_manage_messaging_settings() ) {
			wp_die( esc_html__( 'You do not have permission to test Twilio.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ghca_acd_test_twilio_connection' );
		$result = GHCA_ACD_Twilio_Provider::test_connection();
		self::redirect_settings( is_wp_error( $result ) ? 'twilio_failed' : 'twilio_connected' );
	}

	public static function handle_test_sms(): void {
		if ( ! GHCA_ACD_Roles::user_can_manage_messaging_settings() ) {
			wp_die( esc_html__( 'You do not have permission to test SMS.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ghca_acd_send_test_sms' );
		$phone  = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';
		$result = GHCA_ACD_Twilio_Provider::send_test( $phone );
		self::redirect_settings( is_wp_error( $result ) ? 'sms_test_failed' : 'sms_test_sent' );
	}

	public static function handle_record_sms_consent(): void {
		if ( ! GHCA_ACD_Roles::user_can_manage_messaging_settings() ) {
			wp_die( esc_html__( 'You do not have permission to record SMS consent.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ghca_acd_record_sms_consent' );
		$employee_id = isset( $_POST['employee_user_id'] ) ? (int) $_POST['employee_user_id'] : 0;
		$phone       = isset( $_POST['phone'] ) ? GHCA_ACD_SMS_Phone::normalize( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
		$state       = isset( $_POST['consent_state'] ) ? sanitize_key( wp_unslash( $_POST['consent_state'] ) ) : '';
		$source      = isset( $_POST['consent_source'] ) ? sanitize_key( wp_unslash( $_POST['consent_source'] ) ) : '';
		$evidence    = isset( $_POST['evidence_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ) ) : '';
		$disclosure  = trim( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION, '1' ) );
		$privacy     = (string) get_option( GHCA_ACD_Settings::OPTION_SMS_PRIVACY_URL, '' );
		$terms       = (string) get_option( GHCA_ACD_Settings::OPTION_SMS_TERMS_URL, '' );
		$result      = null;
		if ( $employee_id <= 0 || ! self::is_employee_account( $employee_id ) || '' === $phone || ! hash_equals( GHCA_ACD_SMS_Phone::for_user( $employee_id ), $phone ) || ! in_array( $state, array( 'opted_in', 'opted_out' ), true ) || ! in_array( $source, array( 'web_form', 'paper_form', 'administrative_correction' ), true ) || ( 'opted_in' === $state && ( '' === $disclosure || '' === $privacy || '' === $terms || ( 'paper_form' === $source && '' === $evidence ) ) ) ) {
			$result = new WP_Error( 'ghca_sms_consent_invalid', __( 'The consent event does not satisfy the current disclosure and phone requirements.', 'ghca-acd' ) );
		} else {
			$result = GHCA_ACD_SMS_Consent_Repository::record(
				array(
					'employee_user_id'  => $employee_id,
					'phone'             => $phone,
					'state'             => $state,
					'source'            => $source,
					'disclosure_version'=> $disclosure,
					'evidence_reference'=> $evidence,
					'actor_user_id'     => get_current_user_id(),
				)
			);
		}
		if ( ! is_wp_error( $result ) && 'opted_in' === $state ) {
			self::queue_consent_confirmation( $employee_id, $phone );
		}
		self::redirect_settings( is_wp_error( $result ) ? 'consent_failed' : 'consent_saved' );
	}

	public static function handle_self_sms_consent(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Sign in to manage SMS consent.', 'ghca-acd' ), '', array( 'response' => 401 ) );
		}
		check_admin_referer( 'ghca_acd_self_sms_consent' );
		$user_id = get_current_user_id();
		if ( ! self::is_employee_account( $user_id ) ) {
			wp_die( esc_html__( 'SMS reminder consent is available only to employee accounts.', 'ghca-acd' ), '', array( 'response' => 403 ) );
		}
		$phone   = GHCA_ACD_SMS_Phone::for_user( $user_id );
		$action  = isset( $_POST['consent_action'] ) ? sanitize_key( wp_unslash( $_POST['consent_action'] ) ) : '';
		$privacy = (string) get_option( GHCA_ACD_Settings::OPTION_SMS_PRIVACY_URL, '' );
		$terms   = (string) get_option( GHCA_ACD_Settings::OPTION_SMS_TERMS_URL, '' );
		$version = trim( (string) get_option( GHCA_ACD_Settings::OPTION_SMS_DISCLOSURE_VERSION, '1' ) );
		if ( '' === $phone || ! in_array( $action, array( 'opt_in', 'opt_out' ), true ) || ( 'opt_in' === $action && ( empty( $_POST['consent_confirmed'] ) || '' === $privacy || '' === $terms || '' === $version || is_wp_error( GHCA_ACD_Twilio_Provider::configuration_ready( true ) ) ) ) ) {
			wp_die( esc_html__( 'SMS consent is not currently available.', 'ghca-acd' ), '', array( 'response' => 400 ) );
		}
		$result = GHCA_ACD_SMS_Consent_Repository::record(
			array(
				'employee_user_id'  => $user_id,
				'phone'             => $phone,
				'state'             => 'opt_in' === $action ? 'opted_in' : 'opted_out',
				'source'            => 'web_form',
				'disclosure_version'=> $version,
				'evidence_reference'=> 'self-service:' . gmdate( 'Y-m-d' ),
				'actor_user_id'     => $user_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html__( 'Your SMS preference could not be saved.', 'ghca-acd' ), '', array( 'response' => 500 ) );
		}
		if ( 'opt_in' === $action ) {
			self::queue_consent_confirmation( $user_id, $phone );
		}
		$referer = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'ghca_sms_consent', 'saved', $referer ? $referer : home_url( '/' ) ) );
		exit;
	}

	private static function queue_consent_confirmation( int $employee_user_id, string $phone ): bool {
		if ( is_wp_error( GHCA_ACD_Twilio_Provider::configuration_ready( true ) ) ) {
			return false;
		}
		if ( GHCA_ACD_Messaging_Repository::employee_has_recent_message( $employee_user_id, 60 ) ) {
			return false;
		}
		$config  = GHCA_ACD_Settings::twilio_config();
		$brand   = trim( (string) $config['sender_name'] );
		$message = sprintf( __( '%s: You have opted in to receive employee training and compliance reminders. Message frequency varies. Message and data rates may apply. Reply HELP for help or STOP to opt out.', 'ghca-acd' ), '' !== $brand ? $brand : 'Gridhouse Digital' );
		$key     = hash_hmac( 'sha256', 'consent-confirmation|' . $employee_user_id . '|' . wp_generate_uuid4(), wp_salt( 'nonce' ) );
		$created = GHCA_ACD_Messaging_Repository::create_communication(
			array(
				'employee_user_id'  => $employee_user_id,
				'actor_user_id'     => $employee_user_id,
				'source'            => 'sms_consent',
				'template_id'       => null,
				'template_version'  => null,
				'urgency'           => 'normal',
				'subject'           => '',
				'message'           => $message,
				'requested_channels'=> 'sms',
				'idempotency_key'   => $key,
			),
			array( array( 'channel' => 'sms', 'provider' => 'twilio', 'masked_destination' => GHCA_ACD_SMS_Phone::mask( $phone ), 'destination_hash' => GHCA_ACD_SMS_Phone::hash( $phone ) ) )
		);
		if ( is_wp_error( $created ) ) {
			return false;
		}
		if ( ! GHCA_ACD_Messaging_Worker::schedule( (int) $created['delivery_id'] ) ) {
			GHCA_ACD_Messaging_Repository::mark_schedule_failed( (int) $created['delivery_id'] );
			return false;
		}
		return true;
	}

	private static function redirect_settings( string $message ): void {
		wp_safe_redirect( GHCA_ACD_Admin_Menu::url( 'ghca-acd-messaging', array( 'ghca_message' => $message ) ) );
		exit;
	}

	private static function schema_ready(): bool {
		return (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) >= GHCA_ACD_Messaging_Schema::VERSION
			&& GHCA_ACD_Messaging_Schema::tables_exist();
	}

	private static function is_employee_account( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( GHCA_ACD_Roles::delegated_role_is_allowed( (string) $role ) ) {
				return true;
			}
		}
		return false;
	}

	private static function send_result( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success( $result );
	}
}
