<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-course-title.php';

/**
 * Inline User training report (Admin-UserReport board), shown inside the redesigned Reports tab instead of sending
 * people to the /user-report/ page. The report is built from the same employee record and the same permission
 * checks as the legacy report (GHCA_ACD_User_Report): dashboard access, then GHCA_ACD_User_Report::can_view_user()
 * for the chosen employee, on every request. Nothing here computes a new figure.
 *
 * The legacy page and its shortcode are unchanged; links that still point there (no-script fallbacks, bookmarks)
 * keep working.
 */
final class GHCA_ACD_Redesign_Report {

	public static function init(): void {
		add_action( 'wp_ajax_ghca_acd_user_report', array( __CLASS__, 'ajax_report' ) );
	}

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** Whether the signed-in user may open the report at all (the legacy report's own first check). */
	public static function available(): bool {
		return is_user_logged_in() && GHCA_ACD_Roles::user_can_view();
	}

	/**
	 * Employees the picker offers: the same people as the Employees tab (and Inactive for user managers), each one
	 * checked with the report's own scope check. The server checks again when a report is requested.
	 *
	 * @return array<int,array{id:int,name:string,group:string}>
	 */
	public static function picker_people(): array {
		$ids    = GHCA_ACD_Roles::user_can_manage_users() ? GHCA_ACD_Data_Provider::get_employee_user_ids() : GHCA_ACD_Data_Provider::get_active_employee_user_ids();
		$people = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $id ) ) {
				continue;
			}
			$people[] = array(
				'id'    => $id,
				'name'  => GHCA_ACD_Data_Provider::get_user_full_name( $id ),
				'group' => ( $g = GHCA_ACD_Data_Provider::get_user_primary_group_id( $id ) ) > 0 ? (string) get_the_title( $g ) : '',
			);
		}
		usort( $people, static function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return $people;
	}

	/** The picker: a combobox over the people above. Works as a plain search field until the script binds it. */
	private static function picker_html( array $people, string $id ): string {
		$list = $id . '-list';
		$h    = '<div class="gh-field gh-urpt-pick" data-ghca-report-picker><label class="gh-label gh-sr" for="' . esc_attr( $id ) . '">' . esc_html__( 'Employee', 'ghca-acd' ) . '</label>';
		$h   .= '<div class="gh-input-wrap">' . self::icon( 'search' ) . '<input id="' . esc_attr( $id ) . '" class="gh-input" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $list ) . '" autocomplete="off" placeholder="' . esc_attr__( 'Search employees', 'ghca-acd' ) . '" data-ghca-report-input />' . str_replace( 'class="gh-i ', 'class="gh-i gh-i--end ', self::icon( 'chev-down' ) ) . '</div>';
		$h   .= '<ul class="gh-menu gh-urpt-list" role="listbox" id="' . esc_attr( $list ) . '" aria-label="' . esc_attr__( 'Employees', 'ghca-acd' ) . '" hidden data-ghca-report-list>';
		foreach ( $people as $i => $p ) {
			$label = '' !== $p['group'] ? $p['name'] . ' — ' . $p['group'] : $p['name'];
			$h    .= '<li class="gh-menu-i" role="option" id="' . esc_attr( $list . '-' . $i ) . '" aria-selected="false" data-ghca-report-option="' . esc_attr( (string) $p['id'] ) . '" data-label="' . esc_attr( $label ) . '"><span class="gh-urpt-opt-n">' . esc_html( $p['name'] ) . '</span>' . ( '' !== $p['group'] ? '<span class="gh-small gh-muted">' . esc_html( $p['group'] ) . '</span>' : '' ) . '</li>';
		}
		$h .= '<li class="gh-urpt-none gh-small gh-muted" role="presentation" hidden data-ghca-report-none>' . esc_html__( 'No employees match.', 'ghca-acd' ) . '</li>';
		return $h . '</ul></div>';
	}

	/** Board empty state: "report opened without a person". */
	public static function empty_html(): string {
		return '<section class="gh-card gh-empty gh-urpt-empty"><span class="gh-empty-ico">' . self::icon( 'user', 'xl' ) . '</span><strong class="gh-h4">' . esc_html__( 'Choose an employee to see their report', 'ghca-acd' ) . '</strong><span class="gh-secondary">' . esc_html__( 'Search by name above, or open someone from the Employees tab.', 'ghca-acd' ) . '</span></section>';
	}

	/**
	 * The report view inside the Reports panel. Hidden until "Open report" is chosen.
	 */
	public static function view_html(): string {
		if ( ! self::available() ) {
			return '';
		}
		$people = self::picker_people();
		$h      = '<section class="gh-urpt" data-ghca-report hidden aria-labelledby="ghca-urpt-h">';
		$h     .= '<h2 class="gh-sr" id="ghca-urpt-h">' . esc_html__( 'User training report', 'ghca-acd' ) . '</h2>';
		$h     .= '<div class="gh-toolbar gh-urpt-bar"><button type="button" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-report-back>' . self::icon( 'arrow-left', 'sm' ) . esc_html__( 'Back to reports', 'ghca-acd' ) . '</button>';
		$h     .= self::picker_html( $people, 'ghca-urpt-pick' ) . '</div>';
		$h     .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-report-status></p>';
		$h     .= '<div class="gh-urpt-body" data-ghca-report-body>' . ( $people ? self::empty_html() : '<section class="gh-card gh-empty"><strong class="gh-h4">' . esc_html__( 'No employees in your view', 'ghca-acd' ) . '</strong></section>' ) . '</div>';
		// Print report prints only the report: the script sets this class on <html> just for the print.
		$h .= '<style media="print">html.ghca-print-report body *{visibility:hidden!important}html.ghca-print-report [data-ghca-report-body],html.ghca-print-report [data-ghca-report-body] *{visibility:visible!important}html.ghca-print-report [data-ghca-report-body]{position:absolute;left:0;top:0;width:100%}html.ghca-print-report [data-ghca-report-body] .gh-btn{display:none!important}</style>';
		return $h . '</section>';
	}

	public static function ajax_report(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		if ( ! self::available() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view compliance reports.', 'ghca-acd' ) ), 403 );
		}
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this employee report.', 'ghca-acd' ) ), 403 );
		}
		$employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );
		if ( empty( $employee['user_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Employee not found or has no compliance data.', 'ghca-acd' ) ) );
		}
		wp_send_json_success( array(
			'html'    => self::report_html( $user_id, $employee ),
			'user_id' => $user_id,
			'name'    => (string) $employee['name'],
		) );
	}

	/**
	 * The report for one employee, from the drawer's model of the same record (so statuses, counts and course
	 * grouping match the drawer exactly).
	 *
	 * @param array<string,mixed> $employee GHCA_ACD_Data_Provider::get_employee_record().
	 */
	public static function report_html( int $user_id, array $employee ): string {
		$m      = GHCA_ACD_AJAX::drawer_model( $user_id, $employee, true );
		$email  = (string) ( $employee['email'] ?? '' );
		$tone   = GHCA_ACD_Redesign_Tables::status_tone( (string) $m['status_slug'] );
		$note   = GHCA_ACD_Redesign_Tables::due_note( (int) $m['due_timestamp'], (string) $m['status_slug'] );
		$masked = class_exists( 'GHCA_ACD_Messaging_Repository' ) && '' !== $email ? GHCA_ACD_Messaging_Repository::mask_email( $email ) : '';

		$h  = '<article class="gh-urpt-r" data-ghca-report-user="' . esc_attr( (string) $user_id ) . '" aria-labelledby="ghca-urpt-name">';
		$h .= '<section class="gh-card"><div class="gh-card-bd gh-urpt-hd-bd"><div class="gh-urpt-id">';
		$h .= '<span class="gh-avatar gh-avatar--xl gh-avatar--brand" aria-hidden="true">' . esc_html( (string) $m['initials'] ) . '</span>';
		$h .= '<div class="gh-urpt-who"><span class="gh-eyebrow">' . esc_html__( 'Employee compliance report', 'ghca-acd' ) . '</span><h3 class="gh-h2" id="ghca-urpt-name">' . esc_html( (string) $m['employee_name'] ) . '</h3><div class="gh-meta gh-urpt-meta">';
		foreach ( array( (string) $m['role_label'], (string) $m['group_label'], $masked ) as $part ) {
			if ( '' !== $part ) {
				$h .= '<span>' . esc_html( $part ) . '</span>';
			}
		}
		$h .= '</div></div><span class="gh-st gh-st--lg gh-st--' . esc_attr( $tone ) . '">' . esc_html( (string) $m['status_label'] . ( $note ? ' · ' . $note['text'] : '' ) ) . '</span></div>';

		$h .= self::new_hire_alert( $employee, $m );

		$h .= '<dl class="gh-dl gh-urpt-dl">';
		$h .= '<div><dt>' . esc_html__( 'Progress', 'ghca-acd' ) . '</dt><dd class="gh-urpt-big">' . esc_html( (string) ( $employee['progress_label'] ?? ( (int) $m['progress_pct'] . '%' ) ) ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Courses completed', 'ghca-acd' ) . '</dt><dd class="gh-urpt-big">' . esc_html( sprintf( '%1$d / %2$d', (int) $m['completed_count'], (int) $m['total_courses'] ) ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Due date', 'ghca-acd' ) . '</dt><dd class="gh-urpt-mid">' . esc_html( (string) $m['due_date_short'] ) . '</dd></div>';
		$h .= '<div><dt>' . esc_html__( 'Last activity', 'ghca-acd' ) . '</dt><dd class="gh-urpt-mid">' . esc_html( (string) ( $employee['last_activity_label'] ?? '' ) ) . '</dd></div></dl>';

		$h .= '<div class="gh-urpt-acts">' . self::actions_html( $user_id, $email, $m ) . '</div></div></section>';

		$h .= self::courses_html( $user_id, $m );
		return $h . '</article>';
	}

	/** @param array<string,mixed> $employee @param array<string,mixed> $m */
	private static function new_hire_alert( array $employee, array $m ): string {
		$nh = (array) ( $employee['new_hire'] ?? array() );
		if ( empty( $nh['active'] ) || ! empty( $nh['complete'] ) ) {
			return '';
		}
		$left     = count( (array) $m['attention_courses'] );
		$deadline = (string) ( $nh['deadline_label'] ?? $m['due_date_label'] );
		if ( ! empty( $nh['overdue'] ) ) {
			return '<div class="gh-alert gh-alert--danger" role="status">' . self::icon( 'alert' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'New-hire onboarding overdue', 'ghca-acd' ) . '</span><p>' . esc_html( sprintf( /* translators: 1: courses left, 2: deadline */ _n( '%1$d course left. All assigned courses were due by %2$s.', '%1$d courses left. All assigned courses were due by %2$s.', $left, 'ghca-acd' ), $left, $deadline ) ) . '</p></div></div>';
		}
		return '<div class="gh-alert gh-alert--warning" role="status">' . self::icon( 'clock' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'New-hire onboarding in progress', 'ghca-acd' ) . '</span><p>' . esc_html( sprintf( /* translators: 1: courses left, 2: deadline */ _n( '%1$d course left. All assigned courses must be complete by %2$s.', '%1$d courses left. All assigned courses must be complete by %2$s.', $left, 'ghca-acd' ), $left, $deadline ) ) . '</p></div></div>';
	}

	/**
	 * The legacy report's actions, in the board's order and style, each behind the same check as before.
	 *
	 * @param array<string,mixed> $m
	 */
	private static function actions_html( int $user_id, string $email, array $m ): string {
		$name = (string) $m['employee_name'];
		$h    = '';
		if ( ! empty( $m['can_remind'] ) ) {
			// The existing single-recipient composer, with its review-before-send step.
			$h .= '<button type="button" class="gh-btn gh-btn--primary" data-ghca-reminder="' . esc_attr( (string) $user_id ) . '" data-ghca-reminder-name="' . esc_attr( $name ) . '">' . self::icon( 'mail' ) . esc_html__( 'Email reminder', 'ghca-acd' ) . '</button>';
		} elseif ( '' !== $email ) {
			$h .= '<a class="gh-btn gh-btn--primary" href="' . esc_url( GHCA_ACD_Shortcodes::build_mailto_reminder( $email ) ) . '">' . self::icon( 'mail' ) . esc_html__( 'Email reminder', 'ghca-acd' ) . '</a>';
		}
		if ( GHCA_ACD_Roles::user_can_edit_records() && class_exists( 'GHCA_ACD_FluentCRM' ) ) {
			$h .= '<a class="gh-btn gh-btn--secondary" href="' . esc_url( GHCA_ACD_FluentCRM::build_reminder_url( $user_id, $email ) ) . '">' . self::icon( 'clipboard' ) . esc_html__( 'Log reminder', 'ghca-acd' ) . '</a>';
		}
		if ( '' !== $email ) {
			$h .= '<a class="gh-btn gh-btn--secondary" href="' . esc_url( 'mailto:' . $email ) . '">' . self::icon( 'phone' ) . esc_html__( 'Contact employee', 'ghca-acd' ) . '</a>';
		}
		// This employee's certificates on the dashboard's Certificates tab (slice 9), with the employee filter set.
		$h .= '<a class="gh-btn gh-btn--secondary" href="' . esc_url( add_query_arg( 'ghca_cert_user', $user_id, GHCA_ACD_Data_Provider::get_admin_dashboard_url() ) . '#ghca-tab-certificates' ) . '" data-ghca-tab-jump="ghca-tab-certificates" data-ghca-cert-user="' . esc_attr( (string) $user_id ) . '">' . self::icon( 'award' ) . esc_html( sprintf( /* translators: %d: certificates */ __( 'Certificates (%d)', 'ghca-acd' ), (int) $m['cert_count'] ) ) . '</a>';
		$crm = class_exists( 'GHCA_ACD_FluentCRM' ) && '' !== $email ? GHCA_ACD_FluentCRM::get_contact_admin_url( $email ) : '';
		if ( '' !== $crm ) {
			$h .= '<a class="gh-btn gh-btn--ghost" href="' . esc_url( $crm ) . '" target="_blank" rel="noopener">' . self::icon( 'external' ) . esc_html__( 'CRM contact', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html__( '(opens in a new tab)', 'ghca-acd' ) . '</span></a>';
		}
		if ( current_user_can( 'edit_users' ) ) {
			$h .= '<a class="gh-btn gh-btn--ghost" href="' . esc_url( get_edit_user_link( $user_id ) ?: admin_url( 'users.php' ) ) . '">' . self::icon( 'user' ) . esc_html__( 'User profile', 'ghca-acd' ) . '</a>';
		}
		return $h;
	}

	/** @param array<string,mixed> $m */
	private static function courses_html( int $user_id, array $m ): string {
		$rows = array_merge( (array) $m['attention_courses'], (array) $m['completed_courses'] );
		$h    = '<section class="gh-card gh-urpt-courses" aria-labelledby="ghca-urpt-courses-h"><div class="gh-card-hd"><h3 class="gh-h3" id="ghca-urpt-courses-h">' . esc_html__( 'Required courses', 'ghca-acd' ) . '</h3>';
		$h   .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-report-print>' . self::icon( 'printer', 'sm' ) . esc_html__( 'Print report', 'ghca-acd' ) . '</button></div>';
		if ( empty( $rows ) ) {
			return $h . '<div class="gh-card-bd"><p class="gh-secondary">' . esc_html__( 'No required courses assigned.', 'ghca-acd' ) . '</p></div></section>';
		}
		$h .= '<table class="gh-table gh-urpt-table"><thead><tr><th scope="col">' . esc_html__( 'Course', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Status', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Progress', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Last activity', 'ghca-acd' ) . '</th><th scope="col" class="c-act">' . esc_html__( 'Actions', 'ghca-acd' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$course    = (array) $row['course'];
			$completed = 'completed' === (string) $row['status'];
			$pct       = max( 0, min( 100, (int) $row['pct'] ) );
			$sub       = array();
			if ( (float) $row['hours'] > 0 ) {
				/* translators: %s: hours */
				$sub[] = sprintf( _n( '%s hr', '%s hrs', (float) $row['hours'], 'ghca-acd' ), rtrim( rtrim( number_format( (float) $row['hours'], 2, '.', '' ), '0' ), '.' ) );
			}
			if ( $completed && ! empty( $course['completed_ts'] ) ) {
				/* translators: %s: date */
				$sub[] = sprintf( __( 'completed %s', 'ghca-acd' ), wp_date( get_option( 'date_format', 'M j, Y' ), (int) $course['completed_ts'] ) );
			} elseif ( ! $completed ) {
				/* translators: %s: due date */
				$sub[] = sprintf( __( 'due %s', 'ghca-acd' ), ! empty( $course['expiration_label'] ) ? (string) $course['expiration_label'] : (string) $m['due_date_label'] );
			}
			$last = GHCA_ACD_Data_Provider::format_activity_label( (int) ( $course['last_activity_ts'] ?? 0 ) );
			$h   .= '<tr><td data-label="' . esc_attr__( 'Course', 'ghca-acd' ) . '"><strong>' . esc_html( (string) $row['display_title'] ) . '</strong>' . ( $sub ? '<div class="gh-small gh-muted">' . esc_html( implode( ' · ', $sub ) ) . '</div>' : '' ) . '</td>';
			$h   .= '<td data-label="' . esc_attr__( 'Status', 'ghca-acd' ) . '"><span class="gh-st gh-st--' . esc_attr( GHCA_ACD_Redesign_Tables::status_tone( (string) $row['status'] ) ) . '">' . esc_html( (string) $row['label'] ) . '</span></td>';
			$h   .= '<td data-label="' . esc_attr__( 'Progress', 'ghca-acd' ) . '"><div class="gh-urpt-prog"><div class="gh-bar' . ( 100 === $pct ? ' gh-bar--success' : '' ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: percent */ __( '%d%% complete', 'ghca-acd' ), $pct ) ) . '"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div><span class="gh-num">' . esc_html( $pct . '%' ) . '</span></div></td>';
			$h   .= '<td class="gh-num gh-secondary" data-label="' . esc_attr__( 'Last activity', 'ghca-acd' ) . '">' . esc_html( $last ) . '</td><td class="c-act"><div class="gh-row-acts">';
			if ( ! empty( $course['url'] ) ) {
				$h .= '<a class="gh-btn gh-btn--ghost gh-btn--sm" href="' . esc_url( (string) $course['url'] ) . '">' . esc_html__( 'View course', 'ghca-acd' ) . '<span class="gh-sr">: ' . esc_html( (string) $row['display_title'] ) . '</span></a>';
			}
			if ( ! empty( $course['certificate_url'] ) ) {
				// Slice 9: the certificate viewer, as on the Certificates tab.
				$h .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm"' . GHCA_ACD_Redesign_Drawer::cert_view_attrs( $m, $row ) . '>' . self::icon( 'eye', 'sm' ) . esc_html__( 'Certificate', 'ghca-acd' ) . '<span class="gh-sr">: ' . esc_html( (string) $row['display_title'] ) . '</span></button>';
			}
			$h .= '</div></td></tr>';
		}
		return $h . '</tbody></table></section>';
	}
}
