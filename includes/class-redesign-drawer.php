<?php
/**
 * Board markup for the employee drawer of the opt-in redesign.
 *
 * Presentation only. Every value comes from GHCA_ACD_AJAX::drawer_model(), the same data the legacy
 * drawer shows, and the same data-ghca-* hooks are kept so assets/dashboard.js drives both versions.
 * Capability gates (documents, communications, administration, reminders) are the model's own flags.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign_Drawer {
	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** Hours as the legacy drawer words them: "5.5 hrs", "1 hr". */
	private static function hours_label( float $hours ): string {
		/* translators: %s: number of hours */
		return sprintf( _n( '%s hr', '%s hrs', $hours, 'ghca-acd' ), rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * The drawer host. It sits inside the themed wrapper so tokens, theme and agency colour apply, and keeps
	 * the ids the scripts look for. The body is empty until an employee is opened.
	 */
	public static function shell_html(): string {
		$skel = static function ( string $w, string $h, string $r = '8px' ): string {
			return '<span class="gh-skel" style="display:block;width:' . esc_attr( $w ) . ';height:' . esc_attr( $h ) . ';border-radius:' . esc_attr( $r ) . ';"></span>';
		};
		$loading  = '<div class="gh-drawer-hd" aria-hidden="true"><div class="gh-drh-top">' . $skel( '52px', '52px', '50%' ) . '<div class="gh-drh-id" style="display:flex;flex-direction:column;gap:8px;">' . $skel( '180px', '22px' ) . $skel( '240px', '14px' ) . '</div></div><div style="height:56px;"></div></div>';
		$loading .= '<div class="gh-drawer-bd" aria-hidden="true">' . $skel( '100%', '120px', '18px' ) . $skel( '100%', '84px', '10px' ) . $skel( '100%', '200px', '18px' ) . '</div>';
		$loading .= '<p class="gh-sr" role="status">' . esc_html__( 'Loading employee data…', 'ghca-acd' ) . '</p>';

		$h  = '<div class="gh-dr-layer" id="ghca-acd-employee-drawer" data-ghca-ui="board" hidden aria-hidden="true">';
		$h .= '<div class="gh-scrim" data-ghca-drawer-close></div>';
		$h .= '<aside class="gh-drawer gh-drawer--emp" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-employee-drawer-name ghca-acd-employee-drawer-title" data-ghca-drawer-dialog tabindex="-1">';
		$h .= '<span class="gh-sr" id="ghca-acd-employee-drawer-title">' . esc_html__( 'Employee details', 'ghca-acd' ) . '</span>';
		$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-drawer-x" data-ghca-drawer-close aria-label="' . esc_attr__( 'Close employee panel', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button>';
		$h .= '<div id="ghca-acd-employee-drawer-body" class="gh-dr-body">' . $loading . '</div>';
		return $h . '</aside></div>';
	}

	/**
	 * Header, panels and footer for one employee.
	 *
	 * @param array<string,mixed> $m Result of GHCA_ACD_AJAX::drawer_model().
	 */
	public static function render( array $m ): string {
		return self::header_html( $m ) . '<div class="gh-drawer-bd">' . self::panels_html( $m ) . '</div>' . self::footer_html( $m );
	}

	/** @param array<string,mixed> $m */
	private static function header_html( array $m ): string {
		$tone = GHCA_ACD_Redesign_Tables::status_tone( (string) $m['status_slug'] );
		$h    = '<header class="gh-drawer-hd"><div class="gh-drh-top"><span class="gh-avatar gh-avatar--lg gh-avatar--brand" aria-hidden="true">' . esc_html( (string) $m['initials'] ) . '</span><div class="gh-drh-id"><div class="gh-drh-name">';
		$h   .= '<h2 class="gh-h3" id="ghca-acd-employee-drawer-name">' . esc_html( (string) $m['employee_name'] ) . '</h2>';
		$h   .= '<span class="gh-st gh-st--' . esc_attr( $tone ) . '">' . esc_html( (string) $m['status_label'] ) . '</span>';
		$h   .= '<span class="gh-badge gh-badge--brand" data-ghca-review-badge' . ( $m['review']['reviewed'] ? '' : ' hidden' ) . '>' . esc_html( (string) $m['review']['badge'] ) . '</span></div>';
		$h   .= '<div class="gh-meta gh-drh-meta">';
		if ( '' !== (string) $m['role_label'] ) {
			$h .= '<span>' . esc_html( (string) $m['role_label'] ) . '</span>';
		}
		if ( '' !== (string) $m['group_label'] ) {
			$h .= '<span>' . esc_html( (string) $m['group_label'] ) . '</span>';
		}
		$h .= '</div></div></div>';

		$tabs = array( array( 'training', __( 'Training', 'ghca-acd' ), (int) $m['total_courses'] ) );
		if ( $m['can_documents'] ) {
			$tabs[] = array( 'documents', __( 'Documents', 'ghca-acd' ), (int) $m['document_count'] );
		}
		if ( $m['can_communications'] ) {
			$tabs[] = array( 'communications', __( 'Communications', 'ghca-acd' ), (int) $m['communication_count'] );
		}
		if ( $m['can_admin'] ) {
			$tabs[] = array( 'administration', __( 'Administration', 'ghca-acd' ), null );
		}
		$h .= '<nav class="gh-tabs gh-drh-tabs" role="tablist" aria-label="' . esc_attr__( 'Employee records', 'ghca-acd' ) . '" data-ghca-tablist>';
		foreach ( $tabs as $i => $tab ) {
			$h .= '<button type="button" class="gh-tab" role="tab" id="ghca-acd-drawer-tab-' . esc_attr( $tab[0] ) . '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '" aria-controls="ghca-acd-drawer-panel-' . esc_attr( $tab[0] ) . '" tabindex="' . ( 0 === $i ? '0' : '-1' ) . '" data-ghca-tab="' . esc_attr( $tab[0] ) . '">' . esc_html( $tab[1] );
			if ( null !== $tab[2] ) {
				$h .= ' <span class="gh-count">' . esc_html( (string) $tab[2] ) . '</span>';
			}
			$h .= '</button>';
		}
		return $h . '</nav></header>';
	}

	/** @param array<string,mixed> $m */
	private static function panels_html( array $m ): string {
		$h = self::panel( 'training', self::training_html( $m ), false );
		if ( $m['can_documents'] ) {
			$h .= self::panel( 'documents', self::documents_html( $m ), true );
		}
		if ( $m['can_communications'] ) {
			$h .= self::panel( 'communications', self::communications_html( $m ), true );
		}
		if ( $m['can_admin'] ) {
			$h .= self::panel( 'administration', self::administration_html( $m ), true );
		}
		return $h;
	}

	private static function panel( string $key, string $inner, bool $hidden ): string {
		return '<section class="gh-dr-panel" role="tabpanel" id="ghca-acd-drawer-panel-' . esc_attr( $key ) . '" aria-labelledby="ghca-acd-drawer-tab-' . esc_attr( $key ) . '" data-ghca-tabpanel="' . esc_attr( $key ) . '"' . ( $hidden ? ' hidden' : '' ) . '>' . $inner . '</section>';
	}

	/** @param array<string,mixed> $m */
	private static function training_html( array $m ): string {
		$pct      = max( 0, min( 100, (int) $m['progress_pct'] ) );
		// Only the value is inline; the ring's size is the board's (84px, 9px) in CSS, and smaller on phones.
		$ring     = '--p: ' . $pct . ';' . ( 100 === $pct ? ' --ring: var(--gh-status-success-solid);' : '' );
		$due_ts   = (int) $m['due_timestamp'];
		$note     = GHCA_ACD_Redesign_Tables::due_note( $due_ts, (string) $m['status_slug'] );
		$due_tone = $note ? $note['tone'] : 'muted';

		$h  = '<div class="gh-card gh-dr-sum"><div class="gh-ring" style="' . esc_attr( $ring ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: percent */ __( '%d%% overall compliance', 'ghca-acd' ), $pct ) ) . '"><span class="gh-ring-v">' . esc_html( $pct . '%' ) . '</span></div>';
		$h .= '<div class="gh-dr-sum-t"><h3 class="gh-h4">' . esc_html__( 'Overall compliance', 'ghca-acd' ) . '</h3><div class="gh-meta gh-dr-sum-meta">';
		$h .= '<span>' . self::icon( 'check-circle', 'sm' ) . esc_html( sprintf( /* translators: 1: completed, 2: total */ __( '%1$d of %2$d courses', 'ghca-acd' ), (int) $m['completed_count'], (int) $m['total_courses'] ) ) . '</span>';
		$h .= '<span>' . self::icon( 'award', 'sm' ) . esc_html( sprintf( /* translators: %d: certificates */ _n( '%d certificate', '%d certificates', (int) $m['cert_count'], 'ghca-acd' ), (int) $m['cert_count'] ) ) . '</span></div>';
		$h .= '<span class="gh-dr-due gh-due--' . esc_attr( $due_tone ) . '">' . self::icon( 'calendar', 'sm' ) . esc_html( sprintf( /* translators: %s: date */ __( 'Next due %s', 'ghca-acd' ), (string) $m['due_date_short'] ) . ( $note ? ' · ' . $note['text'] : '' ) ) . '</span></div></div>';

		if ( '' !== (string) $m['oltl_html'] ) {
			// The OLTL card has no board; it keeps its existing markup.
			$h .= '<div class="gh-legacy gh-dr-frag">' . $m['oltl_html'] . '</div>';
		}

		$h .= self::attention_html( $m );
		$h .= self::course_fold( __( 'To do', 'ghca-acd' ), (array) $m['attention_courses'], false, $m, __( 'No training needs attention.', 'ghca-acd' ) );
		$h .= self::course_fold( __( 'Completed', 'ghca-acd' ), (array) $m['completed_courses'], true, $m, __( 'No completed training records.', 'ghca-acd' ) );
		return $h;
	}

	/** @param array<string,mixed> $m */
	private static function attention_html( array $m ): string {
		$user_id = (int) $m['user_id'];
		if ( empty( $m['employee']['courses'] ) ) {
			return '<div class="gh-alert gh-alert--neutral" role="status">' . self::icon( 'info' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'No courses assigned', 'ghca-acd' ) . '</span></div></div>';
		}
		if ( empty( $m['attention_courses'] ) ) {
			return '<div class="gh-alert gh-alert--success" role="status">' . self::icon( 'check-circle' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Training is current', 'ghca-acd' ) . '</span><p>' . esc_html__( 'All assigned training is current.', 'ghca-acd' ) . '</p></div></div>';
		}
		$first  = $m['first_attention'];
		$danger = in_array( (string) $first['status'], array( 'overdue', 'new_hire_overdue', 'expired' ), true );
		$due    = '' !== (string) $m['due_context']
			? sprintf( /* translators: 1: due date, 2: days remaining or overdue */ __( 'Due %1$s (%2$s)', 'ghca-acd' ), (string) $m['first_due_label'], (string) $m['due_context'] )
			: sprintf( /* translators: %s: due date */ __( 'Due %s', 'ghca-acd' ), (string) $m['first_due_label'] );
		$hours = (float) $first['hours'] > 0 ? ' · ' . self::hours_label( (float) $first['hours'] ) : '';

		$h  = '<div class="gh-alert gh-alert--' . ( $danger ? 'danger' : 'warning' ) . ' gh-dr-attn" role="status">' . self::icon( 'clock', 'lg' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html( (string) $first['display_title'] ) . '</span>';
		$h .= '<p>' . esc_html( $due . ' · ' . sprintf( /* translators: %d: percent */ __( '%d%% complete', 'ghca-acd' ), (int) $first['pct'] ) . $hours ) . '</p></div><div class="gh-dr-attn-act">';
		if ( ! empty( $first['course']['url'] ) ) {
			$h .= '<a class="gh-btn gh-btn--ghost gh-btn--sm" href="' . esc_url( (string) $first['course']['url'] ) . '">' . esc_html__( 'Review requirement', 'ghca-acd' ) . '</a>';
		}
		if ( $m['can_remind'] ) {
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--lnk" data-ghca-reminder="' . esc_attr( (string) $user_id ) . '" data-ghca-reminder-name="' . esc_attr( (string) $m['employee_name'] ) . '">' . self::icon( 'send', 'sm' ) . esc_html__( 'Remind', 'ghca-acd' ) . '</button>';
		}
		return $h . '</div></div>';
	}

	/**
	 * One collapsible list of courses. A native <details>, so it works without scripts and from the keyboard.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $m
	 */
	private static function course_fold( string $title, array $rows, bool $completed, array $m, string $empty ): string {
		$h  = '<details class="gh-card gh-fold" open><summary class="gh-fold-hd"><span class="gh-fold-t">' . esc_html( $title ) . ' <span class="gh-count">' . esc_html( (string) count( $rows ) ) . '</span></span>' . self::icon( 'chev-up' ) . '</summary>';
		if ( empty( $rows ) ) {
			return $h . '<p class="gh-fold-empty gh-small gh-muted">' . esc_html( $empty ) . '</p></details>';
		}
		foreach ( $rows as $row ) {
			$course = (array) $row['course'];
			$tone   = GHCA_ACD_Redesign_Tables::status_tone( (string) $row['status'] );
			$sub    = array();
			if ( (float) $row['hours'] > 0 ) {
				$sub[] = self::hours_label( (float) $row['hours'] );
			}
			if ( $completed ) {
				if ( ! empty( $course['completed_ts'] ) ) {
					$sub[] = sprintf( /* translators: %s: date */ __( 'Completed %s', 'ghca-acd' ), wp_date( get_option( 'date_format', 'M j, Y' ), (int) $course['completed_ts'] ) );
				}
			} else {
				if ( (int) $row['pct'] > 0 ) {
					$sub[] = sprintf( /* translators: %d: percent */ __( '%d%% complete', 'ghca-acd' ), (int) $row['pct'] );
				}
				$sub[] = sprintf( /* translators: %s: due date */ __( 'Due %s', 'ghca-acd' ), ! empty( $course['expiration_label'] ) ? (string) $course['expiration_label'] : (string) $m['due_date_label'] );
			}
			$main = '<div class="gh-dr-row-main"><div class="gh-dr-row-t">' . esc_html( (string) $row['display_title'] ) . '</div><div class="gh-small gh-muted">' . esc_html( implode( ' · ', $sub ) ) . '</div></div>';
			$chip = '<span class="gh-st gh-st--' . esc_attr( $tone ) . '">' . esc_html( $completed ? __( 'Completed', 'ghca-acd' ) : (string) $row['label'] ) . '</span>';
			if ( $completed ) {
				$act = '';
				if ( ! empty( $course['certificate_url'] ) ) {
					$act = '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-cert-trigger data-ghca-cert-url="' . esc_attr( (string) $course['certificate_url'] ) . '" data-ghca-cert-download-url="' . esc_url( GHCA_ACD_Certificate_Download::download_url( (int) $m['user_id'], (int) ( $course['id'] ?? 0 ) ) ) . '" data-ghca-cert-title="' . esc_attr( (string) $row['display_title'] ) . '">' . self::icon( 'download', 'sm' ) . esc_html__( 'Certificate', 'ghca-acd' ) . '</button>';
				} else {
					$act = '<span class="gh-small gh-muted">' . esc_html__( 'No certificate', 'ghca-acd' ) . '</span>';
				}
				$h .= '<div class="gh-dr-row">' . $main . $chip . $act . '</div>';
				continue;
			}
			if ( ! empty( $course['url'] ) ) {
				$h .= '<a class="gh-dr-row gh-dr-row--link" href="' . esc_url( (string) $course['url'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: course title */ __( 'Review %s', 'ghca-acd' ), (string) $row['display_title'] ) ) . '">' . $main . $chip . self::icon( 'chev-right' ) . '</a>';
			} else {
				$h .= '<div class="gh-dr-row">' . $main . $chip . '</div>';
			}
		}
		return $h . '</details>';
	}

	/**
	 * Documents tab data. The gate is the legacy renderer's own, so nothing shows that did not before.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function documents_data( int $user_id ): ?array {
		if ( ! GHCA_ACD_Jotform_UI::can_show_documents( $user_id ) ) {
			return null;
		}
		$employee = get_userdata( $user_id );
		$training = GHCA_ACD_Jotform_Repository::training_for_employee( $user_id, 3 );
		return array(
			'user_id'          => $user_id,
			'name'             => $employee ? (string) $employee->display_name : '',
			'rows'             => array_slice( GHCA_ACD_Jotform_UI::employee_record_rows( $user_id ), 0, 3 ),
			'total'            => (int) GHCA_ACD_Jotform_Repository::employee_record_count( $user_id ),
			'training_summary' => ! empty( $training ) ? GHCA_ACD_Jotform_UI::external_training_summary( $user_id ) : '',
		);
	}

	/**
	 * Outside-certificate form data. Same gate as the legacy form; the upload itself is unchanged.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manual_entry_data( int $user_id ): ?array {
		if ( ! GHCA_ACD_Jotform_UI::can_show_manual_entry( $user_id ) ) {
			return null;
		}
		$ready = GHCA_ACD_External_Evidence_Store::readiness();
		$error = is_wp_error( $ready ) ? $ready->get_error_message() : '';
		return array(
			'user_id' => $user_id,
			'error'   => $error,
			'catalog' => '' === $error ? (array) GHCA_ACD_Jotform_Repository::catalog( true ) : array(),
			'entries' => count( (array) GHCA_ACD_Jotform_Repository::manual_training_for_employee( $user_id, 20 ) ),
			'nonce'   => wp_create_nonce( 'ghca_acd_table' ),
		);
	}

	/** The card on its own, for the upload response that replaces it in place. */
	public static function manual_entry_html( int $user_id ): string {
		$data = self::manual_entry_data( $user_id );
		return $data ? self::manual_card_html( $data ) : '';
	}

	/**
	 * The three most recent communications, grouped as the legacy drawer groups them (one per message, with
	 * every delivery under it). Retry is offered only where the legacy rule offers it.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Latest messages for the drawer's Communications tab. $channel narrows to email or sms with the existing
	 * history filter; anything else is all channels.
	 */
	public static function history_data( int $user_id, string $channel = '', int $limit = 3 ): array {
		$filters = in_array( $channel, array( 'email', 'sms' ), true ) ? array( 'channel' => $channel ) : array();
		$rows    = (array) GHCA_ACD_Messaging_Repository::history_for_employee( $user_id, 1, max( 1, min( 20, $limit ) ), $filters );
		return array(
			'user_id'    => $user_id,
			'channel'    => isset( $filters['channel'] ) ? $filters['channel'] : '',
			'total'      => (int) GHCA_ACD_Messaging_Repository::history_count( $user_id, $filters ),
			'all_total'  => (int) GHCA_ACD_Messaging_Repository::history_count( $user_id ),
			'items'      => self::group_history_rows( $rows ),
			'can_remind' => (bool) GHCA_ACD_Roles::user_can_send_reminders(),
		);
	}

	/** Template names by id for this request (the message may name a template that was later deactivated). */
	private static $template_names = array();

	private static function template_name( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		if ( ! array_key_exists( $id, self::$template_names ) ) {
			$t = GHCA_ACD_Messaging_Repository::get_template( $id, false );
			self::$template_names[ $id ] = $t ? (string) $t['name'] : '';
		}
		return self::$template_names[ $id ];
	}

	/**
	 * History rows (one per delivery) grouped into messages, for the drawer and the full-history modal.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function group_history_rows( array $rows ): array {
		$group = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['communication_id'];
			if ( ! isset( $group[ $id ] ) ) {
				$actor      = get_userdata( (int) $row['actor_user_id'] );
				$created_ts = strtotime( (string) $row['created_at'] . ' UTC' );
				$template = self::template_name( (int) ( $row['template_id'] ?? 0 ) );
				$group[ $id ] = array(
					'id'         => $id,
					'actor'      => $actor ? (string) $actor->display_name : __( 'Former administrator', 'ghca-acd' ),
					'urgency'    => GHCA_ACD_Message_Template_Renderer::normalize_urgency( (string) $row['urgency'] ),
					'created_ts' => (int) $created_ts,
					'created'    => $created_ts ? wp_date( 'M j, Y g:i a', $created_ts ) : (string) $row['created_at'],
					'subject'    => '' !== trim( (string) $row['subject'] ) ? (string) $row['subject'] : __( 'SMS reminder', 'ghca-acd' ),
					'message'    => (string) $row['message'],
					'template'   => $template,
					'reusable'   => 'employee_drawer' === (string) ( $row['source'] ?? '' ) && GHCA_ACD_Roles::user_can_send_reminders(),
					'deliveries' => array(),
				);
			}
			$state   = sanitize_key( (string) $row['delivery_state'] );
			$channel = sanitize_key( (string) $row['channel'] );
			$group[ $id ]['deliveries'][] = array(
				'id'          => (int) $row['delivery_id'],
				'channel'     => $channel,
				'state'       => $state,
				'destination' => (string) ( $row['masked_destination'] ?? '' ),
				'label'       => GHCA_ACD_Messaging_UI::delivery_label( $state, $channel ),
				'error'       => 'failed' === $state ? (string) ( $row['last_error_summary'] ?? '' ) : '',
				'retry'       => self::can_retry( $row, $state, $channel ),
			);
		}
		return array_values( $group );
	}

	/**
	 * The legacy retry rule: only a failed delivery, never an SMS whose outcome is uncertain or that already has a
	 * provider reference, never past the attempt limit, and only for someone who can send reminders.
	 *
	 * @param array<string,mixed> $delivery
	 */
	public static function can_retry( array $delivery, string $state, string $channel ): bool {
		$uncertain = 'sms' === $channel && in_array( (string) ( $delivery['last_error_code'] ?? '' ), array( 'twilio_transport_error', 'twilio_response_invalid', 'sms_outcome_unknown' ), true );
		return 'failed' === $state && ! $uncertain && ( 'sms' !== $channel || empty( $delivery['provider_ref'] ) ) && (int) ( $delivery['attempt_count'] ?? 0 ) < GHCA_ACD_Messaging_Repository::MAX_DELIVERY_ATTEMPTS && GHCA_ACD_Roles::user_can_send_reminders();
	}

	/** Review state of an indexed document or manual entry, as a board chip tone. */
	private static function review_tone( string $key ): string {
		switch ( $key ) {
			case 'approved':
				return 'approved';
			case 'pending':
				return 'review';
			case 'rejected':
			case 'revoked':
				return 'rejected';
		}
		return 'na';
	}

	/** @param array<string,mixed> $m */
	private static function documents_html( array $m ): string {
		$d = $m['documents_data'] ?? null;
		if ( ! is_array( $d ) ) {
			$h = '<section class="gh-card"><div class="gh-empty gh-dr-empty"><span class="gh-empty-ico">' . self::icon( 'folder', 'xl' ) . '</span><strong>' . esc_html__( 'No employee documents found.', 'ghca-acd' ) . '</strong></div></section>';
		} else {
			$id  = (int) $d['user_id'];
			$h   = '<section class="gh-card" data-ghca-documents-section="' . esc_attr( (string) $id ) . '"><div class="gh-card-hd"><div><h3 class="gh-h4">' . esc_html__( 'Records on file', 'ghca-acd' ) . '</h3><span class="gh-small gh-muted">' . esc_html( sprintf( /* translators: %d: number of records */ _n( '%d item', '%d items', (int) $d['total'], 'ghca-acd' ), (int) $d['total'] ) ) . '</span></div>';
			$h  .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--sm gh-btn--lnk" data-ghca-documents-all="' . esc_attr( (string) $id ) . '" data-ghca-documents-name="' . esc_attr( (string) $d['name'] ) . '">' . esc_html__( 'View all documents', 'ghca-acd' ) . self::icon( 'arrow-right', 'sm' ) . '</button></div>';
			if ( empty( $d['rows'] ) ) {
				$h .= '<div class="gh-empty gh-dr-empty"><span class="gh-empty-ico">' . self::icon( 'folder', 'xl' ) . '</span><strong>' . esc_html__( 'No employee documents found.', 'ghca-acd' ) . '</strong></div>';
			} else {
				$h .= '<ul class="gh-files">';
				foreach ( $d['rows'] as $row ) {
					$h .= self::file_row_html( (array) $row );
				}
				$h .= '</ul>';
			}
			if ( '' !== (string) $d['training_summary'] ) {
				$h .= '<p class="gh-small gh-muted gh-dr-foot"><strong>' . esc_html__( 'External training', 'ghca-acd' ) . '</strong> ' . esc_html( (string) $d['training_summary'] ) . '</p>';
			}
			$h .= '</section>';
		}
		$manual = $m['manual_data'] ?? null;
		return $h . ( is_array( $manual ) ? self::manual_card_html( $manual ) : '' );
	}

	/** @param array<string,mixed> $row A row of GHCA_ACD_Jotform_UI::employee_record_rows(). */
	private static function file_row_html( array $row ): string {
		$manual = 'manual' === (string) $row['kind'];
		$attr   = $manual ? 'data-ghca-manual-open' : 'data-ghca-document-open';
		$label  = 'application/pdf' === (string) $row['media_type'] ? 'PDF' : 'FILE';
		$date   = (int) $row['timestamp'] > 0 ? mysql2date( get_option( 'date_format' ), gmdate( 'Y-m-d H:i:s', (int) $row['timestamp'] ), false ) : '';
		$sub    = array_filter( array( (string) $row['context'], $date, $manual ? __( 'Manual entry', 'ghca-acd' ) : '' ), static function ( $v ): bool {
			return '' !== $v;
		} );
		// The scripts read the title from the <strong> inside this <li>.
		$h  = '<li class="gh-file"><span class="gh-file-ico" aria-hidden="true">' . esc_html( $label ) . '</span><div class="gh-file-main"><strong class="gh-file-n">' . esc_html( (string) $row['name'] ) . '</strong><div class="gh-small gh-muted">' . esc_html( implode( ' · ', $sub ) ) . '</div></div>';
		$h .= '<span class="gh-st gh-st--' . esc_attr( self::review_tone( (string) ( $row['status_key'] ?? '' ) ) ) . '">' . esc_html( (string) $row['status'] ) . '</span>';
		if ( ! empty( $row['viewable'] ) ) {
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" ' . $attr . '="' . (int) $row['id'] . '" data-action="preview">' . esc_html__( 'Preview', 'ghca-acd' ) . '</button>';
		}
		return $h . '</li>';
	}

	/** @param array<string,mixed> $d Result of manual_entry_data(). */
	private static function manual_card_html( array $d ): string {
		$id = (int) $d['user_id'];
		$h  = '<section class="gh-card" data-ghca-manual-entry="' . esc_attr( (string) $id ) . '"><div class="gh-card-hd"><div><h3 class="gh-h4">' . esc_html__( 'Add an outside certificate', 'ghca-acd' ) . '</h3>';
		$h .= '<span class="gh-small gh-muted">' . esc_html__( 'For certificates received outside Jotform (email, paper scan, shared drive).', 'ghca-acd' ) . '</span></div></div>';
		if ( '' !== (string) $d['error'] ) {
			return $h . '<div class="gh-card-bd"><div class="gh-alert gh-alert--warning" role="status">' . self::icon( 'alert' ) . '<div class="gh-alert-body">' . esc_html( (string) $d['error'] ) . '</div></div></div></section>';
		}
		if ( empty( $d['catalog'] ) ) {
			return $h . '<div class="gh-card-bd"><div class="gh-alert gh-alert--neutral" role="status">' . self::icon( 'info' ) . '<div class="gh-alert-body">' . esc_html__( 'No active catalog lessons exist. Add lessons under Gridhouse Compliance → Training Catalog first.', 'ghca-acd' ) . '</div></div></div></section>';
		}
		$f  = 'ghca-manual-' . $id;
		$h .= '<form data-ghca-manual-form enctype="multipart/form-data"><div class="gh-card-bd gh-dr-form">';
		$h .= '<input type="hidden" name="action" value="ghca_acd_jotform_manual_upload" /><input type="hidden" name="nonce" value="' . esc_attr( (string) $d['nonce'] ) . '" /><input type="hidden" name="employee_id" value="' . $id . '" /><input type="hidden" name="ghca_ui" value="board" />';
		$h .= '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $f ) . '-lesson">' . esc_html__( 'Counts toward', 'ghca-acd' ) . ' <span class="gh-req">*</span></label><select id="' . esc_attr( $f ) . '-lesson" class="gh-select" required name="catalog_id"><option value="">' . esc_html__( 'Select lesson', 'ghca-acd' ) . '</option>';
		foreach ( $d['catalog'] as $item ) {
			$h .= '<option value="' . (int) $item['id'] . '">' . esc_html( $item['title'] . ' (' . $item['credit_hours'] . ' hrs)' ) . '</option>';
		}
		$h .= '</select></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $f ) . '-date">' . esc_html__( 'Completion date', 'ghca-acd' ) . ' <span class="gh-req">*</span></label><input id="' . esc_attr( $f ) . '-date" class="gh-input" required type="date" name="completion_date" /></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $f ) . '-why">' . esc_html__( 'Where did it come from?', 'ghca-acd' ) . ' <span class="gh-req">*</span></label><textarea id="' . esc_attr( $f ) . '-why" class="gh-textarea" required maxlength="1000" name="entry_reason"></textarea><span class="gh-help">' . esc_html__( 'The reason for the manual entry, e.g. where the certificate came from.', 'ghca-acd' ) . '</span></div>';
		$h .= '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $f ) . '-file">' . esc_html__( 'Certificate file', 'ghca-acd' ) . ' <span class="gh-req">*</span></label><div class="gh-drop">' . self::icon( 'upload', 'lg' ) . '<div class="gh-drop-main"><input id="' . esc_attr( $f ) . '-file" class="gh-drop-input" required type="file" name="certificate_file" accept="application/pdf,image/jpeg,image/png" /><div class="gh-small gh-muted">' . esc_html__( 'PDF, JPEG or PNG · stored encrypted', 'ghca-acd' ) . '</div></div></div></div>';
		$h .= '<div class="gh-alert gh-alert--info gh-alert--tight">' . self::icon( 'eye' ) . '<div class="gh-alert-body">' . esc_html__( 'It stays pending until it is approved in External Training Review. Only then does it count toward annual compliance.', 'ghca-acd' ) . '</div></div>';
		$h .= '<p class="gh-small gh-dr-status" data-ghca-manual-status aria-live="polite"></p>';
		if ( (int) $d['entries'] > 0 ) {
			$h .= '<p class="gh-small gh-muted">' . esc_html( sprintf( /* translators: %d: number of certificates */ _n( '%d manually entered certificate is listed under Records on file above.', '%d manually entered certificates are listed under Records on file above.', (int) $d['entries'], 'ghca-acd' ), (int) $d['entries'] ) ) . '</p>';
		}
		return $h . '</div><div class="gh-card-ft"><button type="submit" class="gh-btn gh-btn--primary">' . esc_html__( 'Save for review', 'ghca-acd' ) . '</button></div></form></section>';
	}

	/**
	 * Communications tab (Drawer-Communications board, canvas 1794092638-1fc0): channel sub-tabs, the latest three
	 * messages as a timeline, and a link to the full history. The board's "Logged" sub-tab and logged calls are not
	 * built: the plugin records sent messages only, and has no logged-contact records.
	 *
	 * @param array<string,mixed> $m
	 */
	private static function communications_html( array $m ): string {
		$d = $m['history_data'] ?? null;
		if ( ! is_array( $d ) ) {
			return '<div class="gh-card"><div class="gh-empty gh-dr-empty"><span class="gh-empty-ico">' . self::icon( 'mail', 'xl' ) . '</span><strong>' . esc_html__( 'Communication history is not available.', 'ghca-acd' ) . '</strong></div></div>';
		}
		$id = (int) $d['user_id'];
		$h  = '<div class="gh-dr-hist" data-ghca-history-user="' . esc_attr( (string) $id ) . '"><div class="gh-toolbar gh-dr-hist-bar">';
		$h .= '<div class="gh-subtabs" role="group" aria-label="' . esc_attr__( 'Show messages by channel', 'ghca-acd' ) . '">';
		foreach ( array( '' => __( 'All', 'ghca-acd' ), 'email' => __( 'Email', 'ghca-acd' ), 'sms' => __( 'SMS', 'ghca-acd' ) ) as $key => $label ) {
			$h .= '<button type="button" class="gh-subtab" data-ghca-history-channel="' . esc_attr( $key ) . '" aria-pressed="' . ( '' === $key ? 'true' : 'false' ) . '">' . esc_html( $label );
			if ( '' === $key ) {
				$h .= ' <span class="gh-count">' . esc_html( (string) (int) $d['all_total'] ) . '</span>';
			}
			$h .= '</button>';
		}
		$h .= '</div>';
		if ( $d['can_remind'] ) {
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--lnk" data-ghca-reminder="' . esc_attr( (string) $id ) . '" data-ghca-reminder-name="' . esc_attr( (string) $m['employee_name'] ) . '">' . self::icon( 'plus', 'sm' ) . esc_html__( 'New reminder', 'ghca-acd' ) . '</button>';
		}
		$h .= '</div><p class="gh-sr" role="status" aria-live="polite" data-ghca-history-drawer-status></p>';
		$h .= '<div data-ghca-history-list>' . self::history_timeline_html( $d ) . '</div></div>';
		return $h;
	}

	/** The drawer timeline and its footer, for the first render and for the channel sub-tabs. @param array<string,mixed> $d */
	public static function history_timeline_html( array $d ): string {
		$id    = (int) $d['user_id'];
		$total = (int) $d['total'];
		if ( empty( $d['items'] ) ) {
			$text = 'email' === $d['channel'] ? __( 'No emails have been recorded for this employee.', 'ghca-acd' ) : ( 'sms' === $d['channel'] ? __( 'No SMS messages have been recorded for this employee.', 'ghca-acd' ) : __( 'No reminders have been recorded for this employee.', 'ghca-acd' ) );
			return '<div class="gh-card"><div class="gh-empty gh-dr-empty"><span class="gh-empty-ico">' . self::icon( 'mail', 'xl' ) . '</span><strong>' . esc_html( $text ) . '</strong></div></div>';
		}
		$h = '<ol class="gh-tl">';
		foreach ( $d['items'] as $item ) {
			$h .= self::history_item_html( (array) $item );
		}
		$h .= '</ol>';
		/* translators: %d: number of messages shown */
		$h .= '<p class="gh-small gh-muted gh-dr-foot">' . esc_html( sprintf( __( 'Showing latest %d', 'ghca-acd' ), min( count( $d['items'] ), $total ) ) ) . ' · <button type="button" class="gh-linkbtn" data-ghca-history-open data-user-id="' . esc_attr( (string) $id ) . '">' . esc_html__( 'View all communication history', 'ghca-acd' ) . '</button></p>';
		return $h;
	}

	/** @param array<string,mixed> $item */
	private static function history_item_html( array $item ): string {
		$channels = array();
		$failed   = false;
		foreach ( $item['deliveries'] as $delivery ) {
			$channels[ $delivery['channel'] ] = 'sms' === $delivery['channel'] ? 'SMS' : __( 'Email', 'ghca-acd' );
			$failed                           = $failed || in_array( $delivery['state'], array( 'failed', 'undelivered' ), true );
		}
		$lead = (string) ( $item['deliveries'][0]['channel'] ?? 'email' );
		$h    = '<li><span class="gh-tl-dot ' . ( $failed ? 'gh-tl-dot--danger' : 'gh-tl-dot--brand' ) . '">' . self::icon( 'sms' === $lead ? 'sms' : 'mail', 'sm' ) . '</span><div class="gh-card gh-dr-comm' . ( $failed ? ' is-failed' : '' ) . '">';
		$h   .= '<div class="gh-dr-comm-top"><span class="gh-small gh-muted"><strong>' . esc_html( implode( ' + ', array_values( $channels ) ) ) . '</strong> · <time' . ( $item['created_ts'] ? ' datetime="' . esc_attr( gmdate( 'c', (int) $item['created_ts'] ) ) . '"' : '' ) . '>' . esc_html( (string) $item['created'] ) . '</time> · ' . esc_html( sprintf( /* translators: %s: name of the administrator who sent it */ __( 'by %s', 'ghca-acd' ), (string) $item['actor'] ) ) . '</span><span class="gh-dr-comm-st">';
		foreach ( $item['deliveries'] as $delivery ) {
			$h .= '<span class="gh-st gh-st--' . esc_attr( self::delivery_tone( (string) $delivery['state'] ) ) . '">' . esc_html( (string) $delivery['label'] ) . '</span>';
		}
		$h .= '</span></div><h4 class="gh-h4 gh-dr-comm-t">' . esc_html( (string) $item['subject'] ) . '</h4>';
		$h .= '<details open data-ghca-msg-toggle><summary class="gh-dr-comm-sum" data-show="' . esc_attr__( 'Show message', 'ghca-acd' ) . '" data-hide="' . esc_attr__( 'Hide message', 'ghca-acd' ) . '">' . esc_html__( 'Hide message', 'ghca-acd' ) . '</summary><p class="gh-p gh-secondary gh-dr-comm-msg">' . nl2br( esc_html( (string) $item['message'] ) ) . '</p></details>';
		foreach ( $item['deliveries'] as $delivery ) {
			if ( '' !== (string) $delivery['error'] ) {
				$h .= '<span class="gh-small gh-due--danger">' . esc_html( (string) $delivery['error'] ) . '</span>';
			}
			if ( $delivery['retry'] ) {
				$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm gh-dr-retry" data-ghca-reminder-retry="' . esc_attr( (string) $delivery['id'] ) . '">' . esc_html( sprintf( /* translators: %s: EMAIL or SMS */ __( 'Retry %s', 'ghca-acd' ), strtoupper( (string) $delivery['channel'] ) ) ) . '</button>';
			}
		}
		$meta = sprintf( /* translators: %s: urgency level */ __( 'Urgency: %s', 'ghca-acd' ), ucfirst( (string) $item['urgency'] ) );
		if ( '' !== (string) ( $item['template'] ?? '' ) ) {
			/* translators: %s: template name */
			$meta .= ' · ' . sprintf( __( 'Template: %s', 'ghca-acd' ), (string) $item['template'] );
		}
		return $h . '<span class="gh-small gh-muted">' . esc_html( $meta ) . '</span></div></li>';
	}

	private static function delivery_tone( string $state ): string {
		return in_array( $state, array( 'failed', 'undelivered', 'suppressed' ), true ) ? 'failed' : ( in_array( $state, array( 'queued', 'processing' ), true ) ? 'progress' : 'sent' );
	}

	/**
	 * One message in the full-history modal (Modal-CommHistory board). The actions are the existing ones: Use again
	 * (data-ghca-history-reuse, the existing draft endpoint) and Retry for an eligible failed delivery
	 * (data-ghca-reminder-retry). The board's "Open Administration" for a missing phone number is not built.
	 *
	 * @param array<string,mixed> $item
	 */
	public static function history_modal_item_html( array $item ): string {
		$failed = false;
		foreach ( $item['deliveries'] as $delivery ) {
			$failed = $failed || in_array( $delivery['state'], array( 'failed', 'undelivered' ), true );
		}
		$lead = (string) ( $item['deliveries'][0]['channel'] ?? 'email' );
		$mid  = 'gh-ch-m-' . (int) $item['id'];
		$h    = '<li class="gh-ch-item' . ( $failed ? ' is-failed' : '' ) . '"><span class="gh-ch-ico' . ( $failed ? ' gh-ch-ico--danger' : '' ) . '">' . self::icon( 'sms' === $lead ? 'sms' : 'mail' ) . '</span><div class="gh-ch-main">';
		$h   .= '<div class="gh-ch-t">' . esc_html( (string) $item['subject'] ) . ( 'urgent' === $item['urgency'] ? '<span class="gh-ch-urgent">' . esc_html__( 'Urgent', 'ghca-acd' ) . '</span>' : ( 'important' === $item['urgency'] ? '<span class="gh-ch-urgent gh-ch-urgent--imp">' . esc_html__( 'Important', 'ghca-acd' ) . '</span>' : '' ) ) . '</div>';
		foreach ( $item['deliveries'] as $delivery ) {
			$h .= '<span class="gh-ch-meta">' . esc_html( ( 'sms' === $delivery['channel'] ? 'SMS' : __( 'Email', 'ghca-acd' ) ) . ( '' !== $delivery['destination'] ? ' · ' . $delivery['destination'] : '' ) ) . '</span>';
		}
		$h .= '<span class="gh-ch-meta">' . esc_html( (string) $item['created'] . ' · ' . sprintf( /* translators: %s: sender */ __( 'by %s', 'ghca-acd' ), (string) $item['actor'] ) ) . ( '' !== (string) $item['template'] ? esc_html( ' · ' . sprintf( /* translators: %s: template name */ __( 'Template: %s', 'ghca-acd' ), (string) $item['template'] ) ) : '' ) . '</span>';
		foreach ( $item['deliveries'] as $delivery ) {
			if ( '' !== (string) $delivery['error'] ) {
				$h .= '<p class="gh-ch-err">' . esc_html( (string) $delivery['error'] ) . '</p>';
			}
		}
		$h .= '<p class="gh-ch-msg" id="' . esc_attr( $mid ) . '" hidden>' . esc_html( (string) $item['message'] ) . '</p>';
		$h .= '<div class="gh-ch-acts"><button type="button" class="gh-linkbtn gh-ch-toggle" aria-expanded="false" aria-controls="' . esc_attr( $mid ) . '" data-ghca-ch-toggle data-show="' . esc_attr__( 'View full message', 'ghca-acd' ) . '" data-hide="' . esc_attr__( 'Hide message', 'ghca-acd' ) . '"><span>' . esc_html__( 'View full message', 'ghca-acd' ) . '</span>' . self::icon( 'chev-down', 'sm' ) . '</button>';
		if ( $item['reusable'] ) {
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-history-reuse="' . esc_attr( (string) $item['id'] ) . '">' . self::icon( 'copy', 'sm' ) . esc_html__( 'Use again', 'ghca-acd' ) . '</button>';
		}
		foreach ( $item['deliveries'] as $delivery ) {
			if ( $delivery['retry'] ) {
				$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-reminder-retry="' . esc_attr( (string) $delivery['id'] ) . '">' . self::icon( 'refresh', 'sm' ) . esc_html( sprintf( /* translators: %s: EMAIL or SMS */ __( 'Retry %s', 'ghca-acd' ), strtoupper( (string) $delivery['channel'] ) ) ) . '</button>';
			}
		}
		$h .= '</div></div><div class="gh-ch-side">';
		foreach ( $item['deliveries'] as $delivery ) {
			$h .= '<span class="gh-st gh-st--' . esc_attr( self::delivery_tone( (string) $delivery['state'] ) ) . '">' . esc_html( (string) $delivery['label'] ) . '</span>';
		}
		return $h . '</div></li>';
	}

	/**
	 * Administration tab, following the Drawer-Administration board (canvas version 1794077292-4607): overview hero,
	 * a warning when the verified start is missing, the training-record and compliance-review cards, and a status
	 * column with system meta. Only shown when user_can_edit_records() passed (see header_html()); every value is
	 * the drawer model's, and every action keeps its existing hook and endpoint.
	 *
	 * The hero's last sentence differs from the board ("This information is restricted to authorized personnel."):
	 * shown next to the data, that read like a denial. The replacement says who can see it and why.
	 *
	 * @param array<string,mixed> $m
	 */
	private static function administration_html( array $m ): string {
		$user_id   = (int) $m['user_id'];
		$suspended = (bool) $m['is_suspended'];
		$unset     = (string) $m['employment_type_label'] === __( 'Not set', 'ghca-acd' );
		$vs_ts     = (int) $m['verified_start_ts'];
		$name      = (string) $m['employee_name'];
		$edit_attr = ' data-ghca-edit-records="' . esc_attr( (string) $user_id ) . '" data-ghca-edit-records-name="' . esc_attr( $name ) . '"';
		$review    = (string) $m['review']['line'];

		$h  = '<div class="gh-dr-admin app-adm" data-ghca-admin-view="overview">';
		$h .= '<header class="app-adm-hero"><div><h3>' . esc_html__( 'Administrative Overview', 'ghca-acd' ) . '</h3><p>' . esc_html__( 'Core employee records and employment status details. Confidential: shown only to people with permission to edit employee records.', 'ghca-acd' ) . '</p></div><span aria-hidden="true">' . self::icon( 'shield', 'xl' ) . '</span></header>';

		if ( $vs_ts <= 0 ) {
			$h .= '<div class="gh-alert gh-alert--warning" role="status">' . self::icon( 'alert' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Verified start date missing', 'ghca-acd' ) . '</span><p>' . esc_html__( 'The 30-day orientation result shows “Missing evidence” until a verified employment start date is entered. The registration date is never used.', 'ghca-acd' ) . '</p></div>';
			$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm"' . $edit_attr . '>' . esc_html__( 'Enter date', 'ghca-acd' ) . '</button></div>';
		}

		$h .= '<div class="app-adm-grid"><div class="app-adm-main">';
		$h .= '<section class="app-adm-card" aria-labelledby="ghca-adm-rec"><div class="app-adm-card-hd"><div><h4 id="ghca-adm-rec">' . esc_html__( 'Employee Training Record', 'ghca-acd' ) . '</h4><p>' . esc_html__( 'Foundational dates and orientation metrics.', 'ghca-acd' ) . '</p></div>';
		$h .= '<button type="button" class="app-adm-btn"' . $edit_attr . '>' . self::icon( 'edit', 'sm' ) . esc_html__( 'Edit Record', 'ghca-acd' ) . '</button></div>';
		$h .= '<dl class="app-adm-facts"><div><dt>' . esc_html__( 'Registration date', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $m['registration_label'] ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'First service date', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $m['first_service_label'] ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Orientation', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $m['orientation_label'] ) . '</dd></div></dl></section>';

		$h .= '<section class="app-adm-card app-adm-card--brand" aria-labelledby="ghca-adm-rev"><div class="app-adm-card-hd"><div><h4 id="ghca-adm-rev">' . esc_html__( 'Compliance Review', 'ghca-acd' ) . '</h4><p data-ghca-review-status' . ( $m['review']['reviewed'] ? '' : ' class="is-empty"' ) . '><span data-ghca-review-text>' . esc_html( $review ) . '</span></p></div>';
		$h .= '<button type="button" class="app-adm-btn app-adm-btn--primary" data-ghca-mark-reviewed="' . esc_attr( (string) $user_id ) . '">' . self::icon( 'check-circle', 'sm' ) . esc_html__( 'Mark Reviewed', 'ghca-acd' ) . '</button></div></section>';
		$h .= '</div>';

		$h .= '<aside class="app-adm-side"><section class="app-adm-status" aria-label="' . esc_attr__( 'Employment status', 'ghca-acd' ) . '"><span>' . esc_html__( 'Employment status', 'ghca-acd' ) . '</span>';
		$h .= '<strong><span>' . esc_html( $suspended ? __( 'Inactive', 'ghca-acd' ) : __( 'Active', 'ghca-acd' ) ) . ' ·</span> <span' . ( $unset ? ' class="is-unset"' : '' ) . '>' . esc_html( (string) $m['employment_type_label'] ) . '</span></strong>';
		$h .= '<p>' . esc_html( $suspended ? __( 'Employee is currently inactive and is not authorized for active service.', 'ghca-acd' ) : __( 'Employee is currently in good standing and authorized for active service.', 'ghca-acd' ) ) . '</p>';
		$h .= '<dl><div><dt>' . esc_html__( 'Role', 'ghca-acd' ) . '</dt><dd>' . esc_html( '' !== (string) $m['role_label'] ? (string) $m['role_label'] : '—' ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Group', 'ghca-acd' ) . '</dt><dd>' . esc_html( '' !== (string) $m['group_label'] ? (string) $m['group_label'] : '—' ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Verified start', 'ghca-acd' ) . '</dt><dd>' . esc_html( $vs_ts > 0 ? wp_date( get_option( 'date_format', 'M j, Y' ), $vs_ts ) : __( 'Not entered', 'ghca-acd' ) ) . '</dd></div></dl></section>';

		$h .= '<section class="app-adm-meta" aria-labelledby="ghca-adm-meta"><h4 id="ghca-adm-meta">' . esc_html__( 'System meta', 'ghca-acd' ) . '</h4><dl>';
		$h .= '<div><dt>' . esc_html__( 'Last Review', 'ghca-acd' ) . '</dt><dd data-ghca-review-text>' . esc_html( $review ) . '</dd></div>';
		// The last reminder is communication history: shown only to people allowed to see it, as before.
		if ( ! empty( $m['can_communications'] ) ) {
			$h .= '<div><dt>' . esc_html__( 'Last Reminder', 'ghca-acd' ) . '</dt><dd>' . esc_html( '' !== (string) $m['last_reminder_label'] ? (string) $m['last_reminder_label'] : __( 'None recorded', 'ghca-acd' ) ) . '</dd></div>';
		}
		$h .= '<div><dt>' . esc_html__( 'Training status', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $m['status_label'] ) . '</dd></div></dl></section>';
		$h .= '</aside></div>';

		$h .= '</div><div class="gh-dr-subpage" data-ghca-admin-view="subpage" hidden>' . self::subpage_html( $m ) . '</div>';
		return $h;
	}

	/** @param array<string,mixed> $m */
	private static function subpage_html( array $m ): string {
		return GHCA_ACD_Redesign_Edit::subpage_html( $m );
	}

	/** @param array<string,mixed> $m */
	private static function footer_html( array $m ): string {
		$user_id = (int) $m['user_id'];
		$h       = '<footer class="gh-drawer-ft" aria-label="' . esc_attr__( 'Employee actions', 'ghca-acd' ) . '"><details class="gh-dr-more" data-ghca-more><summary class="gh-btn gh-btn--secondary gh-btn--lnk" aria-haspopup="true">' . self::icon( 'more' ) . esc_html__( 'More', 'ghca-acd' ) . '</summary>';
		$h      .= '<div class="gh-menu gh-dr-menu" role="group" aria-label="' . esc_attr__( 'More actions', 'ghca-acd' ) . '"><div class="gh-menu-lbl">' . esc_html__( 'Report', 'ghca-acd' ) . '</div>';
		$h      .= '<a class="gh-menu-i" href="' . esc_url( (string) $m['report_url'] ) . '">' . self::icon( 'file' ) . '<span>' . esc_html__( 'Compliance report', 'ghca-acd' ) . '</span></a>';
		if ( $m['can_documents'] ) {
			$h .= '<div class="gh-menu-sep"></div><div class="gh-menu-lbl">' . esc_html__( 'Audit packets (PDF)', 'ghca-acd' ) . '</div>';
			$packets = array( 'orientation' => __( 'Orientation packet', 'ghca-acd' ), 'annual' => __( 'Annual packet', 'ghca-acd' ) );
			if ( '' !== (string) $m['oltl_html'] ) {
				$packets['oltl_training'] = __( 'OLTL training packet', 'ghca-acd' );
			}
			foreach ( $packets as $tracker => $label ) {
				$h .= '<button type="button" class="gh-menu-i" data-ghca-pdf-packet="' . esc_attr( (string) $user_id ) . '" data-tracker="' . esc_attr( $tracker ) . '">' . self::icon( 'archive' ) . '<span>' . esc_html( $label ) . '</span></button>';
			}
		}
		$h .= '</div></details>';
		if ( $m['can_remind'] ) {
			$h .= '<button type="button" class="gh-btn gh-btn--primary gh-btn--lnk" data-ghca-reminder="' . esc_attr( (string) $user_id ) . '" data-ghca-reminder-name="' . esc_attr( (string) $m['employee_name'] ) . '">' . self::icon( 'send' ) . esc_html__( 'Send reminder', 'ghca-acd' ) . '</button>';
		}
		return $h . '</footer>';
	}
}
