<?php
/**
 * The Send reminder modal in the board's markup (Modal-SendReminder). Compose, then a confirm step, then a toast.
 *
 * It is the same form as the legacy modal in GHCA_ACD_Messaging_UI::render_modal(): every data-ghca-reminder-*
 * hook the messaging script binds to is here with the same meaning, so the context, template, preview,
 * readiness, SMS-consent and send calls are the existing ones. It mounts inside the themed dashboard wrapper
 * (the legacy modal prints in wp_footer, outside it), and the footer then skips its own copy.
 *
 * Not offered, because the plugin has no matching function: bulk confirm (there is no multi-select send),
 * merge-field hints for custom messages, and the "due" chip on the recipient strip.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign_Reminder {
	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** Whether this viewer can send reminders: the legacy modal's own gate. */
	public static function available(): bool {
		return class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_send_reminders();
	}

	public static function modal_html(): string {
		if ( ! self::available() ) {
			return '';
		}
		$actor = wp_get_current_user();
		$who   = $actor && ! empty( $actor->display_name ) ? (string) $actor->display_name : __( 'this account', 'ghca-acd' );

		$h  = '<div class="gh-mod-layer" id="ghca-acd-reminder-modal" data-ghca-ui="board" hidden aria-hidden="true">';
		$h .= '<div class="gh-scrim" data-ghca-reminder-close></div>';

		// Step 1: compose.
		$h .= '<div class="gh-modal gh-mod gh-mod--compose" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-reminder-title" data-ghca-reminder-dialog>';
		$h .= '<div class="gh-modal-hd"><span class="gh-stat-ico gh-mod-ico" aria-hidden="true">' . self::icon( 'send', 'lg' ) . '</span><div class="gh-mod-hd-t"><h2 class="gh-h3" id="ghca-acd-reminder-title">' . esc_html__( 'Send training reminder', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary">' . esc_html__( 'Logged in Communications when sent.', 'ghca-acd' ) . '</p></div>';
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon" data-ghca-reminder-close data-ghca-reminder-x aria-label="' . esc_attr__( 'Close reminder form', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';

		$h .= '<div class="gh-modal-bd">';
		$h .= '<p class="gh-p gh-secondary gh-small" data-ghca-reminder-loading>' . esc_html__( 'Loading reminder options…', 'ghca-acd' ) . '</p>';
		$h .= '<form class="gh-mod-form" id="ghca-rm-form" data-ghca-reminder-form hidden novalidate>';
		$h .= '<input type="hidden" data-ghca-reminder-user-id /><input type="hidden" data-ghca-reminder-token />';

		// Recipient: name and masked destinations come from the messaging context.
		$h .= '<div class="gh-mod-who"><span class="gh-avatar gh-avatar--brand" data-ghca-reminder-avatar aria-hidden="true"></span><div class="gh-mod-who-t"><strong data-ghca-reminder-name></strong><div class="gh-small gh-muted" data-ghca-reminder-destination></div></div></div>';

		// Delivery method: radios keep the native keyboard behaviour; the segmented look is CSS.
		$h .= '<fieldset class="gh-field gh-mod-seg"><legend class="gh-label">' . esc_html__( 'Delivery method', 'ghca-acd' ) . '</legend><input type="hidden" value="email" data-ghca-reminder-channel />';
		$h .= '<div class="gh-seg gh-seg--block gh-seg--radio">';
		foreach ( array(
			array( 'email', 'mail', __( 'Email', 'ghca-acd' ) ),
			array( 'sms', 'sms', __( 'SMS', 'ghca-acd' ) ),
			array( 'email_sms', '', __( 'Email and SMS', 'ghca-acd' ) ),
		) as $opt ) {
			$h .= '<label><input type="radio" name="ghca-acd-reminder-channel" value="' . esc_attr( $opt[0] ) . '" data-ghca-reminder-channel-option /><span>' . ( '' !== $opt[1] ? self::icon( $opt[1] ) : '' ) . esc_html( $opt[2] ) . '</span></label>';
		}
		$h .= '</div></fieldset>';

		$h .= '<div class="gh-mod-grid">';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-rm-urgency">' . esc_html__( 'Urgency', 'ghca-acd' ) . ' <span class="gh-st gh-st--duesoon gh-mod-badge" data-ghca-reminder-urgency-badge data-ghca-board hidden></span></label><select id="ghca-rm-urgency" class="gh-select" data-ghca-reminder-urgency required><option value="normal">' . esc_html__( 'Normal', 'ghca-acd' ) . '</option><option value="important">' . esc_html__( 'Important', 'ghca-acd' ) . '</option><option value="urgent">' . esc_html__( 'Urgent', 'ghca-acd' ) . '</option></select></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-rm-template">' . esc_html__( 'Template', 'ghca-acd' ) . '</label><select id="ghca-rm-template" class="gh-select" data-ghca-reminder-template><option value="0">' . esc_html__( 'Custom message', 'ghca-acd' ) . '</option></select></div>';
		$h .= '</div>';

		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-rm-subject">' . esc_html__( 'Subject', 'ghca-acd' ) . '</label><input id="ghca-rm-subject" class="gh-input" type="text" maxlength="200" data-ghca-reminder-subject required /></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-rm-message">' . esc_html__( 'Message', 'ghca-acd' ) . '</label><textarea id="ghca-rm-message" class="gh-textarea" rows="3" maxlength="4000" data-ghca-reminder-message required></textarea><div class="gh-mod-count"><span class="gh-help">' . esc_html__( 'Text messages are limited to 480 characters.', 'ghca-acd' ) . '</span><span class="gh-help gh-num"><span data-ghca-reminder-count>0</span> / 4000</span></div></div>';

		// Preview: produced by the same code that builds the outgoing message, including the urgency prefix.
		$h .= '<details class="gh-mod-pv" open><summary class="gh-mod-pv-hd"><span class="gh-linkbtn-t">' . esc_html__( 'Preview', 'ghca-acd' ) . '</span> <span class="gh-small gh-muted" data-ghca-reminder-preview-channel>' . esc_html__( 'Email', 'ghca-acd' ) . '</span></summary>';
		$h .= '<div class="gh-mod-pv-bd"><p class="gh-mod-pv-s" data-ghca-reminder-preview-subject data-empty="' . esc_attr__( 'Subject appears here', 'ghca-acd' ) . '"></p><p class="gh-mod-pv-m" data-ghca-reminder-preview-message data-empty="' . esc_attr__( 'Message appears here as the employee will receive it.', 'ghca-acd' ) . '"></p>';
		$h .= '<p class="gh-small gh-muted" data-ghca-reminder-preview-sms hidden>' . esc_html__( 'SMS delivery sends the message body only, and providers may truncate long messages.', 'ghca-acd' ) . '</p></div></details>';

		$h .= '<label class="gh-check gh-mod-sms" data-ghca-sms-confirm hidden><input type="checkbox" data-ghca-sms-safe /> <span>' . esc_html__( 'I confirm this SMS contains no medical, certificate, detailed course, password, or other sensitive employee information.', 'ghca-acd' ) . '</span></label>';

		$h .= '<div class="gh-alert gh-alert--warning gh-mod-ready" data-ghca-reminder-readiness role="status"></div>';
		$h .= '<div class="gh-alert gh-alert--danger gh-mod-err" data-ghca-reminder-error role="alert" hidden></div>';

		// What the system records: the send is logged with its delivery state. Nothing is claimed about the recipient opening it.
		$h .= '<p class="gh-small gh-muted">' . esc_html__( 'SMS providers may truncate long messages. Every reminder is recorded in this employee’s Communication History with its delivery status.', 'ghca-acd' ) . '</p>';
		$h .= '</form></div>';

		$h .= '<div class="gh-modal-ft gh-mod-ft"><span class="gh-small gh-muted">' . sprintf( /* translators: %s: the acting administrator's display name. */ esc_html__( 'Sends as %s', 'ghca-acd' ), '<strong>' . esc_html( $who ) . '</strong>' ) . '</span>';
		$h .= '<div class="gh-actions"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-reminder-close>' . esc_html__( 'Cancel', 'ghca-acd' ) . '</button><button type="submit" class="gh-btn gh-btn--primary" form="ghca-rm-form" data-ghca-reminder-submit>' . esc_html__( 'Review & send', 'ghca-acd' ) . self::icon( 'arrow-right' ) . '</button></div></div>';
		$h .= '</div>';

		// Step 2: confirm. Emails and texts cannot be recalled once sent, so the send is a separate, deliberate step.
		$h .= '<div class="gh-modal gh-mod gh-mod--confirm" role="alertdialog" aria-modal="true" aria-labelledby="ghca-acd-reminder-confirm-title" data-ghca-reminder-confirm hidden>';
		$h .= '<div class="gh-modal-bd"><h2 class="gh-h3" id="ghca-acd-reminder-confirm-title" data-ghca-confirm-title></h2>';
		$h .= '<dl class="gh-kv gh-mod-kv"><dt>' . esc_html__( 'To', 'ghca-acd' ) . '</dt><dd data-ghca-confirm-to></dd><dt data-ghca-confirm-subject-dt>' . esc_html__( 'Subject', 'ghca-acd' ) . '</dt><dd data-ghca-confirm-subject></dd><dt>' . esc_html__( 'Urgency', 'ghca-acd' ) . '</dt><dd data-ghca-confirm-urgency></dd><dt>' . esc_html__( 'Logged as', 'ghca-acd' ) . '</dt><dd>' . esc_html( $who ) . '</dd></dl>';
		$h .= '<p class="gh-p gh-secondary gh-small">' . esc_html__( 'Messages can’t be recalled once sent.', 'ghca-acd' ) . '</p></div>';
		$h .= '<div class="gh-modal-ft"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-confirm-back>' . esc_html__( 'Back', 'ghca-acd' ) . '</button><button type="button" class="gh-btn gh-btn--primary" data-ghca-confirm-send>' . self::icon( 'send' ) . '<span data-ghca-confirm-send-label>' . esc_html__( 'Send reminder', 'ghca-acd' ) . '</span></button></div></div>';

		return $h . '</div>';
	}

	/**
	 * Full communication history (Modal-CommHistory board, canvas 1794092638-1fc0). Same id, field names and data
	 * hooks as the legacy modal, so the existing script, endpoint, filters, paging, Use again and Retry work as
	 * before. Totals are the ledger's own (email, SMS, failed); the board's "Logged" tile, logged calls and the
	 * Logged channel/status are not built: the plugin has no logged-contact records.
	 */
	public static function history_modal_html(): string {
		if ( ! class_exists( 'GHCA_ACD_Roles' ) || ! GHCA_ACD_Roles::user_can_view_communication_history() ) {
			return '';
		}
		$h  = '<div class="gh-mod-layer gh-ch-layer" id="ghca-acd-history-modal" data-ghca-ui="board" hidden aria-hidden="true"><div class="gh-scrim" data-ghca-history-close></div>';
		$h .= '<div class="gh-modal gh-ch-modal" role="dialog" aria-modal="true" aria-labelledby="ghca-ch-title" data-ghca-history-dialog>';
		$h .= '<div class="gh-modal-hd"><span class="gh-stat-ico gh-ch-hd-ico" aria-hidden="true">' . self::icon( 'mail' ) . '</span><div class="gh-ch-hd-t"><h2 class="gh-h3" id="ghca-ch-title">' . esc_html__( 'Communication history', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary gh-small"><span data-ghca-history-employee></span> · ' . esc_html__( 'every reminder and message sent from the dashboard', 'ghca-acd' ) . '</p></div>';
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon" data-ghca-history-close aria-label="' . esc_attr__( 'Close communication history', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';
		$h .= '<div class="gh-modal-bd">';
		$h .= '<section class="gh-grid gh-grid--kpi gh-ch-kpi" aria-label="' . esc_attr__( 'Totals', 'ghca-acd' ) . '" data-ghca-history-stats hidden>';
		foreach ( array( 'total' => __( 'All messages', 'ghca-acd' ), 'email' => __( 'Email', 'ghca-acd' ), 'sms' => __( 'SMS', 'ghca-acd' ), 'failed' => __( 'Failed', 'ghca-acd' ) ) as $key => $label ) {
			$h .= '<div class="gh-stat' . ( 'failed' === $key ? ' gh-stat--danger' : '' ) . '"><span class="gh-stat-top">' . esc_html( $label ) . '</span><span class="gh-stat-val" data-ghca-history-stat="' . esc_attr( $key ) . '">0</span></div>';
		}
		$h .= '</section>';
		$h .= '<form role="search" class="gh-ch-form" data-ghca-history-filters><div class="gh-ch-filters">';
		$h .= '<div class="gh-input-wrap">' . self::icon( 'search' ) . '<label class="gh-sr" for="ghca-ch-q">' . esc_html__( 'Search subject or message', 'ghca-acd' ) . '</label><input id="ghca-ch-q" class="gh-input" type="search" name="search" maxlength="100" placeholder="' . esc_attr__( 'Search subject or message', 'ghca-acd' ) . '" /></div>';
		$h .= '<label class="gh-sr" for="ghca-ch-c">' . esc_html__( 'Channel', 'ghca-acd' ) . '</label><select id="ghca-ch-c" class="gh-select" name="channel"><option value="">' . esc_html__( 'All channels', 'ghca-acd' ) . '</option><option value="email">' . esc_html__( 'Email', 'ghca-acd' ) . '</option><option value="sms">' . esc_html__( 'SMS', 'ghca-acd' ) . '</option></select>';
		$h .= '<label class="gh-sr" for="ghca-ch-s">' . esc_html__( 'Status', 'ghca-acd' ) . '</label><select id="ghca-ch-s" class="gh-select" name="status"><option value="">' . esc_html__( 'All statuses', 'ghca-acd' ) . '</option>';
		foreach ( array( 'queued' => __( 'Queued', 'ghca-acd' ), 'processing' => __( 'Sending', 'ghca-acd' ), 'accepted' => __( 'Accepted', 'ghca-acd' ), 'sent' => __( 'Sent', 'ghca-acd' ), 'delivered' => __( 'Delivered', 'ghca-acd' ), 'undelivered' => __( 'Undelivered', 'ghca-acd' ), 'failed' => __( 'Not sent (failed)', 'ghca-acd' ), 'suppressed' => __( 'Suppressed', 'ghca-acd' ) ) as $value => $label ) {
			$h .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		$h .= '</select></div><div class="gh-ch-dates">';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-ch-f">' . esc_html__( 'From', 'ghca-acd' ) . '</label><input id="ghca-ch-f" class="gh-input" type="date" name="date_from" /></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-ch-to">' . esc_html__( 'To', 'ghca-acd' ) . '</label><input id="ghca-ch-to" class="gh-input" type="date" name="date_to" /></div>';
		$h .= '<button type="submit" class="gh-btn gh-btn--secondary gh-btn--sm">' . esc_html__( 'Apply', 'ghca-acd' ) . '</button><button type="reset" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-history-reset>' . esc_html__( 'Clear filters', 'ghca-acd' ) . '</button></div></form>';
		$h .= '<div class="gh-ch-bar" data-ghca-history-status><span role="status" data-ghca-history-count></span><span>' . esc_html__( 'Newest first', 'ghca-acd' ) . '</span></div>';
		$h .= '<div data-ghca-history-results aria-live="polite"></div>';
		$h .= '<p class="gh-ch-note">' . self::icon( 'info' ) . esc_html__( '“Accepted by email transport” means WordPress handed the email to the mail server. It is not proof the employee received or opened it.', 'ghca-acd' ) . '</p></div>';
		$h .= '<div class="gh-modal-ft gh-ch-ft"><div class="gh-pager gh-ch-pager" data-ghca-history-pagination hidden><span data-ghca-history-page-label></span><div class="gh-pager-btns"><button type="button" data-ghca-history-page="previous" aria-label="' . esc_attr__( 'Previous page', 'ghca-acd' ) . '">' . self::icon( 'chev-left', 'sm' ) . '</button><button type="button" data-ghca-history-page="next" aria-label="' . esc_attr__( 'Next page', 'ghca-acd' ) . '">' . self::icon( 'chev-right', 'sm' ) . '</button></div></div>';
		$h .= '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-history-close>' . esc_html__( 'Close', 'ghca-acd' ) . '</button></div></div></div>';
		return $h;
	}
}
