<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row selection, the selection bar and the bulk reminder dialog on the redesigned Employees tab (Admin-Employees
 * board: row checkboxes, select-all, gh-bulkbar). Markup only; the server work is GHCA_ACD_Bulk_Messaging and the
 * existing CSV export, and every recipient and every exported row is checked there.
 *
 * Selection is explicit. "Select all" selects the rows on the current page and nothing else. A selection survives
 * paging and filtering, and the bar always says how many selected employees are not on screen and names them.
 */
final class GHCA_ACD_Redesign_Bulk {

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** Anyone who can see the Employees tab can select rows and export them; sending is checked separately. */
	public static function can_select(): bool {
		return GHCA_ACD_Shortcodes::can_view_dashboard();
	}

	public static function can_message(): bool {
		return class_exists( 'GHCA_ACD_Bulk_Messaging' ) && GHCA_ACD_Bulk_Messaging::available();
	}

	public static function head_cell(): string {
		return '<th scope="col" class="c-check"><input type="checkbox" class="gh-cb" data-ghca-select-page aria-label="' . esc_attr__( 'Select all employees on this page', 'ghca-acd' ) . '" /></th>';
	}

	public static function row_cell( int $id, string $name ): string {
		/* translators: %s: employee name */
		return '<td class="c-check"><input type="checkbox" class="gh-cb" data-ghca-select value="' . esc_attr( (string) $id ) . '" data-name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'ghca-acd' ), $name ) ) . '" /></td>';
	}

	public static function card_check( int $id, string $name ): string {
		/* translators: %s: employee name */
		return '<label class="gh-mcard-sel"><input type="checkbox" class="gh-cb" data-ghca-select value="' . esc_attr( (string) $id ) . '" data-name="' . esc_attr( $name ) . '" /><span class="gh-sr">' . esc_html( sprintf( __( 'Select %s', 'ghca-acd' ), $name ) ) . '</span></label>';
	}

	/** The selection bar (board: gh-bulkbar), hidden until something is selected. */
	public static function bar_html(): string {
		if ( ! self::can_select() ) {
			return '';
		}
		$max = class_exists( 'GHCA_ACD_Bulk_Messaging' ) ? GHCA_ACD_Bulk_Messaging::MAX_RECIPIENTS : 25;
		$h   = '<div class="gh-bulkbar" role="region" aria-label="' . esc_attr__( 'Selected employees', 'ghca-acd' ) . '" data-ghca-bulkbar data-max="' . esc_attr( (string) $max ) . '" hidden>';
		$h  .= '<strong data-ghca-bulk-count aria-live="polite"></strong><span class="gh-small gh-bulk-names" data-ghca-bulk-names></span>';
		$h  .= '<span class="gh-small gh-bulk-hidden" data-ghca-bulk-hidden hidden></span><span class="gh-bulk-sp"></span>';
		if ( self::can_message() ) {
			$h .= '<button type="button" class="gh-btn gh-btn--on-brand gh-btn--sm is-primary" data-ghca-bulk-send>' . self::icon( 'send', 'sm' ) . '<span data-ghca-bulk-send-label>' . esc_html__( 'Send reminder', 'ghca-acd' ) . '</span></button>';
		}
		$h .= '<form method="post" class="gh-bulk-export" action="' . esc_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ) ) . '" data-ghca-bulk-export>';
		$h .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'ghca_acd_export_csv' ) ) . '" /><input type="hidden" name="ghca_ids" value="" data-ghca-bulk-ids />';
		$h .= '<button type="submit" class="gh-btn gh-btn--on-brand gh-btn--sm">' . self::icon( 'download', 'sm' ) . esc_html__( 'Export selected', 'ghca-acd' ) . '</button></form>';
		$h .= '<button type="button" class="gh-btn gh-btn--on-brand gh-btn--sm" data-ghca-bulk-visible hidden>' . esc_html__( 'Keep only those shown', 'ghca-acd' ) . '</button>';
		$h .= '<button type="button" class="gh-btn gh-btn--on-brand gh-btn--sm gh-btn--icon" data-ghca-bulk-clear aria-label="' . esc_attr__( 'Clear selection', 'ghca-acd' ) . '">' . self::icon( 'x', 'sm' ) . '</button>';
		return $h . '</div>';
	}

	/** Compose, review and results: three steps of one dialog, built from the reminder modal's components. */
	public static function modal_html(): string {
		if ( ! self::can_message() ) {
			return '';
		}
		$actor = wp_get_current_user();
		$who   = $actor && ! empty( $actor->display_name ) ? (string) $actor->display_name : __( 'this account', 'ghca-acd' );

		$h  = '<div class="gh-mod-layer gh-bulk-layer" id="ghca-acd-bulk-modal" data-ghca-ui="board" hidden aria-hidden="true">';
		$h .= '<div class="gh-scrim" data-ghca-bulk-close></div>';
		$h .= '<div class="gh-modal gh-mod gh-mod--compose gh-bulk-mod" role="dialog" aria-modal="true" aria-labelledby="ghca-bulk-title" data-ghca-bulk-dialog>';
		$h .= '<div class="gh-modal-hd"><span class="gh-stat-ico gh-mod-ico" aria-hidden="true">' . self::icon( 'send', 'lg' ) . '</span><div class="gh-mod-hd-t"><h2 class="gh-h3" id="ghca-bulk-title" data-ghca-bulk-title tabindex="-1">' . esc_html__( 'Send training reminder', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary">' . esc_html__( 'Each person gets their own email. Nobody sees the other recipients.', 'ghca-acd' ) . '</p></div>';
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon" data-ghca-bulk-close aria-label="' . esc_attr__( 'Close', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';

		$h .= '<div class="gh-modal-bd">';
		$h .= '<p class="gh-p gh-secondary gh-small" data-ghca-bulk-loading role="status">' . esc_html__( 'Checking recipients…', 'ghca-acd' ) . '</p>';
		$h .= '<div class="gh-alert gh-alert--danger" data-ghca-bulk-error role="alert" hidden></div>';

		// Step 1: compose.
		$h .= '<form class="gh-mod-form" id="ghca-bulk-form" data-ghca-bulk-step="compose" hidden novalidate>';
		$h .= '<div class="gh-bulk-who" data-ghca-bulk-who></div>';
		$h .= '<div class="gh-field"><span class="gh-label">' . esc_html__( 'Delivery method', 'ghca-acd' ) . '</span><p class="gh-p gh-bulk-chan">' . self::icon( 'mail' ) . '<strong>' . esc_html__( 'Email', 'ghca-acd' ) . '</strong> <span class="gh-small gh-muted">' . esc_html__( 'SMS is not available for group reminders.', 'ghca-acd' ) . '</span></p></div>';
		$h .= '<div class="gh-mod-grid"><div class="gh-field"><label class="gh-label" for="ghca-bulk-urgency">' . esc_html__( 'Urgency', 'ghca-acd' ) . '</label><select id="ghca-bulk-urgency" class="gh-select" data-ghca-bulk-urgency><option value="normal">' . esc_html__( 'Normal', 'ghca-acd' ) . '</option><option value="important">' . esc_html__( 'Important', 'ghca-acd' ) . '</option><option value="urgent">' . esc_html__( 'Urgent', 'ghca-acd' ) . '</option></select></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-bulk-template">' . esc_html__( 'Template', 'ghca-acd' ) . '</label><select id="ghca-bulk-template" class="gh-select" data-ghca-bulk-template><option value="0">' . esc_html__( 'Custom message', 'ghca-acd' ) . '</option></select></div></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-bulk-subject">' . esc_html__( 'Subject', 'ghca-acd' ) . ' <span class="gh-req" aria-hidden="true">*</span></label><input id="ghca-bulk-subject" class="gh-input" type="text" maxlength="200" required data-ghca-bulk-subject /></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-bulk-message">' . esc_html__( 'Message', 'ghca-acd' ) . ' <span class="gh-req" aria-hidden="true">*</span></label><textarea id="ghca-bulk-message" class="gh-textarea" rows="5" maxlength="4000" required data-ghca-bulk-message aria-describedby="ghca-bulk-ph"></textarea>';
		$h .= '<span class="gh-help" id="ghca-bulk-ph">' . esc_html__( 'Placeholders such as [employee_first_name] and [due_date] are filled in separately for each person. You will see each message before anything is sent.', 'ghca-acd' ) . '</span></div>';
		$h .= '</form>';

		// Step 2: review. Exact recipients and exact messages, then an explicit confirmation.
		$h .= '<div class="gh-bulk-review" data-ghca-bulk-step="review" hidden>';
		$h .= '<div class="gh-table-wrap"><table class="gh-table gh-table--bulk"><caption class="gh-sr">' . esc_html__( 'Recipients', 'ghca-acd' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Employee', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Email', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Will this be sent?', 'ghca-acd' ) . '</th></tr></thead><tbody data-ghca-bulk-rows></tbody></table></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="ghca-bulk-pv-who">' . esc_html__( 'Preview the message for', 'ghca-acd' ) . '</label><select id="ghca-bulk-pv-who" class="gh-select" data-ghca-bulk-pv-who></select></div>';
		$h .= '<div class="gh-mod-pv-bd gh-bulk-pv" aria-live="polite"><p class="gh-mod-pv-s" data-ghca-bulk-pv-subject></p><p class="gh-mod-pv-m" data-ghca-bulk-pv-message></p></div>';
		$h .= '<label class="gh-check"><input type="checkbox" data-ghca-bulk-confirm /><span>' . esc_html__( 'I have checked the recipients and the message. Each person listed as “Will be sent” gets one email.', 'ghca-acd' ) . '</span></label>';
		$h .= '</div>';

		// Step 3: results, per recipient, as the service reported them.
		$h .= '<div class="gh-bulk-result" data-ghca-bulk-step="result" hidden><div class="gh-alert" data-ghca-bulk-summary role="status"></div>';
		$h .= '<ul class="gh-bulk-list" data-ghca-bulk-results></ul>';
		$h .= '<p class="gh-small gh-muted">' . esc_html__( 'Queued emails are sent in the background. Each one, with its delivery status, is recorded in that employee’s Communication History.', 'ghca-acd' ) . '</p></div>';
		$h .= '</div>';

		$h .= '<div class="gh-modal-ft gh-mod-ft"><span class="gh-small gh-muted">' . sprintf( /* translators: %s: the acting administrator's display name. */ esc_html__( 'Sends as %s', 'ghca-acd' ), '<strong>' . esc_html( $who ) . '</strong>' ) . '</span><div class="gh-actions">';
		$h .= '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-bulk-back hidden>' . esc_html__( 'Back', 'ghca-acd' ) . '</button>';
		$h .= '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-bulk-close data-ghca-bulk-cancel>' . esc_html__( 'Cancel', 'ghca-acd' ) . '</button>';
		$h .= '<button type="submit" form="ghca-bulk-form" class="gh-btn gh-btn--primary" data-ghca-bulk-next disabled>' . esc_html__( 'Review recipients and message', 'ghca-acd' ) . self::icon( 'arrow-right' ) . '</button>';
		$h .= '<button type="button" class="gh-btn gh-btn--primary" data-ghca-bulk-go hidden disabled>' . self::icon( 'send' ) . '<span data-ghca-bulk-go-label></span></button>';
		$h .= '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-bulk-retry hidden></button>';
		$h .= '</div></div></div></div>';
		return $h;
	}
}
