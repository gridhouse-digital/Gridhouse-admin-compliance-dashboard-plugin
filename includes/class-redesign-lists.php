<?php
/**
 * Board markup for the Overdue, Courses and Certificates tabs of the opt-in redesign (phase 2, slice 4).
 *
 * Presentation only. Rows, filters, paging, group scope and permissions come from the same
 * GHCA_ACD_Data_Provider, GHCA_ACD_Table_UI and GHCA_ACD_Roles calls the legacy tabs use, and the existing
 * field names and data-ghca-* hooks are kept so assets/dashboard.js drives both versions. Nothing here writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign_Lists {
	/** Test clock for the day counts. Null in production, where the real time is used. */
	public static $now = null;

	const PRIORITY_FORM     = 'ghca-filters-priority';
	const COURSES_FORM      = 'ghca-filters-courses';
	const CERTIFICATES_FORM = 'ghca-filters-certificates';

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ACD_Redesign_Tables::icon( $name, $size );
	}

	/** Whole days from today to the given time, both read as dates in the site time zone. Negative is past. */
	public static function days_from_today( int $ts ): int {
		$today = (int) strtotime( wp_date( 'Y-m-d', self::now() ) . ' 00:00:00 UTC' );
		$day   = (int) strtotime( wp_date( 'Y-m-d', $ts ) . ' 00:00:00 UTC' );
		return (int) round( ( $day - $today ) / 86400 );
	}

	private static function request_key( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification -- display filters only; the table nonce is checked by the AJAX caller.
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_key( wp_unslash( (string) $_POST[ $key ] ) );
		}
		if ( isset( $_GET[ $key ] ) ) {
			return sanitize_key( wp_unslash( (string) $_GET[ $key ] ) );
		}
		// phpcs:enable
		return '';
	}

	private static function request_text( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification -- display filters only; the table nonce is checked by the AJAX caller.
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		}
		if ( isset( $_GET[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
		}
		// phpcs:enable
		return '';
	}

	/** Active employees in this view, keyed by user id. The provider has already applied group scope. */
	private static function employees_by_id(): array {
		$map = array();
		foreach ( (array) ( GHCA_ACD_Data_Provider::get_aggregate()['employees'] ?? array() ) as $employee ) {
			$map[ (int) $employee['user_id'] ] = $employee;
		}
		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Shared pieces
	 * ------------------------------------------------------------------- */

	/**
	 * Summary line and one chip per active filter, as on the Employees tab.
	 *
	 * @param array<int,array{label:string,clear:string,remove:string}> $chips
	 */
	private static function chips_html( string $summary, array $chips ): string {
		$h = '<span class="gh-small gh-muted">' . esc_html( $summary ) . ' · ' . esc_html__( 'filters apply as you type', 'ghca-acd' ) . '</span>';
		foreach ( $chips as $chip ) {
			$h .= '<span class="gh-fchip">' . esc_html( $chip['label'] ) . '<button type="button" data-ghca-filter-clear="' . esc_attr( $chip['clear'] ) . '" aria-label="' . esc_attr( $chip['remove'] ) . '">' . self::icon( 'x', 'sm' ) . '</button></span>';
		}
		if ( count( $chips ) > 1 ) {
			$h .= '<button class="gh-btn gh-btn--ghost gh-btn--sm" type="button" data-ghca-filter-clear="all">' . esc_html__( 'Clear all', 'ghca-acd' ) . '</button>';
		}
		return $h;
	}

	/** @param array{total:int,from:int,to:int} $meta */
	private static function showing( array $meta, string $none ): string {
		if ( $meta['total'] <= 0 ) {
			return $none;
		}
		/* translators: 1: first row number, 2: last row number, 3: total rows */
		return sprintf( __( 'Showing %1$d–%2$d of %3$d', 'ghca-acd' ), $meta['from'], $meta['to'], $meta['total'] );
	}

	private static function empty_html( string $icon, string $title, string $text, bool $clear, bool $success = false ): string {
		$h = '<div class="gh-card gh-empty' . ( $success ? ' gh-empty--ok' : '' ) . '"><span class="gh-empty-ico">' . self::icon( $icon, 'xl' ) . '</span><strong class="gh-h4">' . esc_html( $title ) . '</strong>';
		if ( '' !== $text ) {
			$h .= '<span class="gh-secondary">' . esc_html( $text ) . '</span>';
		}
		if ( $clear ) {
			$h .= '<button class="gh-btn gh-btn--secondary" type="button" data-ghca-filter-clear="all">' . esc_html__( 'Clear filters', 'ghca-acd' ) . '</button>';
		}
		return $h . '</div>';
	}

	private static function search_field( string $form_id, string $name, string $value, string $placeholder, bool $sr_label = false, string $label = '' ): string {
		$label = '' !== $label ? $label : __( 'Search', 'ghca-acd' );
		return '<div class="gh-field gh-field--search"><label class="gh-label' . ( $sr_label ? ' gh-sr' : '' ) . '" for="' . esc_attr( $form_id ) . '-q">' . esc_html( $label ) . '</label><div class="gh-input-wrap">' . self::icon( 'search' ) . '<input id="' . esc_attr( $form_id ) . '-q" class="gh-input" type="search" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" /></div></div>';
	}

	/**
	 * A sort <select> with no name of its own: the script copies its value into the form's hidden sort
	 * fields, so the request carries exactly the fields the legacy table already sends.
	 *
	 * @param array<string,string> $options value => label, value '' is the default order.
	 */
	private static function sort_select( string $id, string $current, array $options ): string {
		$h = '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Sort', 'ghca-acd' ) . '</label><select id="' . esc_attr( $id ) . '" class="gh-select" data-ghca-sort-select>';
		foreach ( $options as $value => $label ) {
			$h .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h . '</select></div>';
	}

	/**
	 * Narrow screens: a row as a card. Hidden above 720px; the table is hidden below it.
	 *
	 * @param array<int,array{0:string,1:string}> $kv  Label, already-escaped value HTML.
	 */
	private static function card_html( string $title_html, string $sub, string $chip_html, array $kv, string $actions_html, bool $risk ): string {
		$h = '<article class="gh-mcard' . ( $risk ? ' is-risk' : '' ) . '"><div class="gh-mcard-top"><div class="gh-mcard-id">' . $title_html;
		if ( '' !== $sub ) {
			$h .= '<div class="gh-person-s">' . esc_html( $sub ) . '</div>';
		}
		$h .= '</div>' . $chip_html . '</div>';
		if ( $kv ) {
			$h .= '<dl class="gh-kv">';
			foreach ( $kv as $pair ) {
				$h .= '<dt>' . esc_html( $pair[0] ) . '</dt><dd>' . $pair[1] . '</dd>';
			}
			$h .= '</dl>';
		}
		if ( '' !== $actions_html ) {
			$h .= '<div class="gh-mcard-act">' . $actions_html . '</div>';
		}
		return $h . '</article>';
	}

	private static function person_html( int $id, string $name, string $sub ): string {
		$report = GHCA_ACD_Data_Provider::get_user_report_url( $id );
		return '<div class="gh-person"><span class="gh-avatar" aria-hidden="true">' . esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( $name ) ) . '</span><div><a class="gh-person-n" href="' . esc_url( $report ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</a><span class="gh-person-s">' . esc_html( $sub ) . '</span></div></div>';
	}

	/** $small: the table's 32px button. Cards on phones take the full-size button, a 44px touch target. */
	private static function view_link( int $id, string $name, bool $small = true ): string {
		return '<a class="gh-btn gh-btn--secondary' . ( $small ? ' gh-btn--sm' : '' ) . '" href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $id ) ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'View %s', 'ghca-acd' ), $name ) ) . '">' . esc_html__( 'View', 'ghca-acd' ) . '</a>';
	}

	/** Opens the redesigned Send reminder modal (slice 3). Only offered to people who can send reminders. */
	private static function remind_button( int $id, string $name, bool $primary, bool $small = true ): string {
		return '<button type="button" class="gh-btn gh-btn--' . ( $primary ? 'primary' : 'secondary' ) . ( $small ? ' gh-btn--sm' : '' ) . ' gh-btn--lnk" data-ghca-reminder="' . esc_attr( (string) $id ) . '" data-ghca-reminder-name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'Send a reminder to %s', 'ghca-acd' ), $name ) ) . '">' . self::icon( 'send', 'sm' ) . esc_html__( 'Remind', 'ghca-acd' ) . '</button>';
	}

	/* ---------------------------------------------------------------------
	 * Overdue
	 * ------------------------------------------------------------------- */

	/** @return array<string,string> value "orderby:order" => label. '' keeps the legacy order (overdue first, then name). */
	public static function priority_sort_options(): array {
		return array(
			''                 => __( 'Most urgent first', 'ghca-acd' ),
			'name:asc'         => __( 'Name A–Z', 'ghca-acd' ),
			'name:desc'        => __( 'Name Z–A', 'ghca-acd' ),
			'progress_pct:asc' => __( 'Lowest progress first', 'ghca-acd' ),
			'group:asc'        => __( 'Group A–Z', 'ghca-acd' ),
		);
	}

	/** @param array<string,mixed> $filters */
	private static function priority_sort_value( array $filters ): string {
		$value = (string) ( $filters['orderby'] ?? '' ) . ':' . (string) ( $filters['order'] ?? 'asc' );
		return isset( self::priority_sort_options()[ $value ] ) ? $value : '';
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array{label:string,clear:string,remove:string}>
	 */
	public static function priority_chips( array $filters ): array {
		$chips = array();
		if ( '' !== (string) $filters['group'] ) {
			$groups  = GHCA_ACD_Data_Provider::get_group_options();
			$chips[] = array( 'label' => sprintf( /* translators: %s: group name */ __( 'Group: %s', 'ghca-acd' ), (string) ( $groups[ (int) $filters['group'] ] ?? $filters['group'] ) ), 'clear' => 'ghca_pri_group', 'remove' => __( 'Remove group filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['priority'] ) {
			$label   = 'overdue' === $filters['priority'] ? __( 'Overdue only', 'ghca-acd' ) : __( 'Due soon only', 'ghca-acd' );
			$chips[] = array( 'label' => $label, 'clear' => 'ghca_pri_type', 'remove' => __( 'Remove priority filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['search'] ) {
			$chips[] = array( 'label' => sprintf( /* translators: %s: search words */ __( 'Search: %s', 'ghca-acd' ), (string) $filters['search'] ), 'clear' => 'ghca_pri_search', 'remove' => __( 'Remove search', 'ghca-acd' ) );
		}
		return $chips;
	}

	/**
	 * Courses an employee still owes: not complete, or complete but past the rolling lifespan.
	 *
	 * @param array<string,mixed> $employee
	 * @return array<int,array{title:string,expired:bool}>
	 */
	public static function owed_courses( array $employee ): array {
		$out = array();
		foreach ( (array) ( $employee['courses'] ?? array() ) as $course ) {
			$expired = ! empty( $course['completed'] ) && 'expired' === (string) ( $course['compliance_state'] ?? '' );
			if ( empty( $course['completed'] ) || $expired ) {
				$out[] = array( 'title' => (string) ( $course['title'] ?? '' ), 'expired' => $expired );
			}
		}
		return $out;
	}

	/**
	 * Latest communication per employee, or null when the viewer may not see communication history or the
	 * messaging tables are not ready. Null hides the column rather than showing "Never" for someone we
	 * cannot read.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function last_reminders( array $ids ): ?array {
		if ( ! GHCA_ACD_Roles::user_can_view_communication_history() || ! class_exists( 'GHCA_ACD_Messaging_Schema' ) || ! class_exists( 'GHCA_ACD_Messaging_Repository' ) ) {
			return null;
		}
		if ( (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) < (int) GHCA_ACD_Messaging_Schema::VERSION || ! GHCA_ACD_Messaging_Schema::tables_exist() ) {
			return null;
		}
		return GHCA_ACD_Messaging_Repository::latest_for_employees( $ids );
	}

	/** @param array<string,mixed>|null $entry */
	private static function last_reminder_html( ?array $entry ): string {
		if ( ! $entry ) {
			return '<span class="gh-muted">' . esc_html__( 'Never', 'ghca-acd' ) . '</span>';
		}
		$ts       = strtotime( (string) $entry['created_at'] . ' UTC' );
		$channels = array();
		foreach ( (array) $entry['deliveries'] as $delivery ) {
			$channels[ $delivery['channel'] ] = 'sms' === $delivery['channel'] ? 'SMS' : __( 'email', 'ghca-acd' );
		}
		$h     = esc_html( ( $ts ? wp_date( 'M j', $ts ) : (string) $entry['created_at'] ) . ' · ' . implode( ' + ', array_values( $channels ) ) );
		$first = (array) ( $entry['deliveries'][0] ?? array() );
		if ( ! empty( $first['state'] ) && class_exists( 'GHCA_ACD_Messaging_UI' ) ) {
			$state = (string) $first['state'];
			$tone  = in_array( $state, array( 'failed', 'undelivered', 'suppressed' ), true ) ? 'failed' : ( in_array( $state, array( 'queued', 'processing' ), true ) ? 'progress' : 'sent' );
			$h    .= '<div class="gh-small"><span class="gh-st gh-st--' . esc_attr( $tone ) . ' gh-st--xs">' . esc_html( GHCA_ACD_Messaging_UI::delivery_label( $state, (string) ( $first['channel'] ?? 'email' ) ) ) . '</span></div>';
		}
		return $h;
	}

	/**
	 * The More menu on an overdue row. Every entry is an existing action: the FluentCRM reminder log (only
	 * where FluentCRM is active and the viewer may edit records), the prefilled reminder email, the CRM
	 * contact link (only where one exists) and a plain email.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function more_menu_html( array $row ): string {
		$id    = (int) $row['user_id'];
		$email = (string) $row['email'];
		$name  = (string) $row['name'];
		$items = '';
		if ( GHCA_ACD_FluentCRM::is_active() && GHCA_ACD_Roles::user_can_edit_records() && GHCA_ACD_User_Report::can_view_user( $id ) ) {
			$items .= '<a class="gh-menu-i" href="' . esc_url( GHCA_ACD_FluentCRM::build_reminder_url( $id, $email ) ) . '">' . self::icon( 'clipboard' ) . '<span>' . esc_html__( 'Log a reminder', 'ghca-acd' ) . '<small>' . esc_html__( 'Adds a note to the FluentCRM contact', 'ghca-acd' ) . '</small></span></a>';
		}
		if ( '' !== $email ) {
			$items .= '<a class="gh-menu-i" href="' . esc_url( GHCA_ACD_Shortcodes::build_mailto_reminder( $email ) ) . '">' . self::icon( 'mail' ) . '<span>' . esc_html__( 'Email reminder', 'ghca-acd' ) . '<small>' . esc_html__( 'Opens your email app', 'ghca-acd' ) . '</small></span></a>';
		}
		$crm = '' !== $email ? GHCA_ACD_FluentCRM::get_contact_admin_url( $email ) : '';
		if ( '' !== $crm ) {
			$items .= '<a class="gh-menu-i" href="' . esc_url( $crm ) . '" target="_blank" rel="noopener">' . self::icon( 'user' ) . '<span>' . esc_html__( 'Open CRM contact', 'ghca-acd' ) . '<small>' . esc_html__( 'FluentCRM · opens new tab', 'ghca-acd' ) . '</small></span></a>';
		}
		if ( '' !== $email ) {
			$items .= '<a class="gh-menu-i" href="' . esc_url( 'mailto:' . $email ) . '">' . self::icon( 'phone' ) . '<span>' . esc_html__( 'Contact employee', 'ghca-acd' ) . '<small>' . esc_html( $email ) . '</small></span></a>';
		}
		if ( '' === $items ) {
			return '';
		}
		return '<details class="gh-dr-more gh-row-more" data-ghca-more><summary class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--icon" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'More actions for %s', 'ghca-acd' ), $name ) ) . '">' . self::icon( 'more' ) . '</summary><div class="gh-menu gh-row-menu" role="group" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'More actions for %s', 'ghca-acd' ), $name ) ) . '">' . $items . '</div></details>';
	}

	/**
	 * @param array<string,mixed> $row       Priority row.
	 * @param array<string,mixed> $employee  The same employee's record.
	 * @param array<int,array<string,mixed>>|null $last
	 * @return array{row:string,card:string}
	 */
	private static function overdue_row( array $row, array $employee, ?array $last, bool $can_remind ): array {
		$id     = (int) $row['user_id'];
		$name   = (string) $row['name'];
		$owed   = self::owed_courses( $employee );
		$due_ts = (int) ( $employee['due_timestamp'] ?? 0 );
		$days   = $due_ts > 0 ? -self::days_from_today( $due_ts ) : 0;
		$tone   = GHCA_ACD_Redesign_Tables::status_tone( (string) $row['status_slug'] );
		$chip   = '<span class="gh-st gh-st--' . esc_attr( $tone ) . '">' . esc_html( (string) $row['status_label'] ) . '</span>';

		$list = '';
		if ( $owed ) {
			$list = '<ul class="gh-owed">';
			foreach ( array_slice( $owed, 0, 3 ) as $course ) {
				$list .= '<li>' . esc_html( $course['title'] ) . ( $course['expired'] ? ' <span class="gh-muted">' . esc_html__( '(expired)', 'ghca-acd' ) . '</span>' : '' ) . '</li>';
			}
			if ( count( $owed ) > 3 ) {
				/* translators: %d: number of further courses */
				$list .= '<li class="gh-muted">' . esc_html( sprintf( __( '+ %d more', 'ghca-acd' ), count( $owed ) - 3 ) ) . '</li>';
			}
			$list .= '</ul>';
		} else {
			$list = '<span class="gh-muted">' . esc_html( (string) $row['next_course_label'] ) . '</span>';
		}

		$due_label = $due_ts > 0 ? sprintf( /* translators: %s: short date */ __( 'due %s', 'ghca-acd' ), wp_date( 'M j', $due_ts ) ) : '';
		$late      = $days > 0
			? '<strong class="gh-late">' . esc_html( (string) $days ) . '</strong>' . ( '' !== $due_label ? '<div class="gh-small gh-muted">' . esc_html( $due_label ) . '</div>' : '' )
			: '<span class="gh-muted" title="' . esc_attr__( 'Not past the due date: a completed course has passed its renewal date.', 'ghca-acd' ) . '">—</span>' . ( '' !== $due_label ? '<div class="gh-small gh-muted">' . esc_html( $due_label ) . '</div>' : '' );

		$actions = self::view_link( $id, $name ) . ( $can_remind ? ' ' . self::remind_button( $id, $name, true ) : '' ) . ' ' . self::more_menu_html( $row );

		$h  = '<tr class="is-risk"><td>' . self::person_html( $id, $name, (string) $row['group'] ) . '</td>';
		$h .= '<td>' . $list . '</td><td class="c-num">' . $late . '</td>';
		if ( null !== $last ) {
			$h .= '<td class="gh-num">' . self::last_reminder_html( $last[ $id ] ?? null ) . '</td>';
		}
		$h .= '<td>' . $chip . '</td><td class="c-act"><div class="gh-row-act">' . $actions . '</div></td></tr>';

		$kv = array(
			array( __( 'Days late', 'ghca-acd' ), $days > 0 ? esc_html( sprintf( /* translators: 1: days late, 2: "due Oct 24" */ __( '%1$d · %2$s', 'ghca-acd' ), $days, $due_label ) ) : esc_html( '' !== $due_label ? $due_label : '—' ) ),
			array( __( 'Overdue courses', 'ghca-acd' ), esc_html( (string) count( $owed ) ) ),
		);
		if ( null !== $last ) {
			$kv[] = array( __( 'Last reminder', 'ghca-acd' ), self::last_reminder_html( $last[ $id ] ?? null ) );
		}
		$card_actions = self::view_link( $id, $name, false ) . ( $can_remind ? self::remind_button( $id, $name, true, false ) : '' );
		$card         = self::card_html( '<a class="gh-person-n" href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $id ) ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</a>', (string) $row['group'], $chip, $kv, $card_actions, true );
		return array( 'row' => $h, 'card' => $card );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $employee
	 * @param array<int,array<string,mixed>>|null $last
	 * @return array{row:string,card:string}
	 */
	private static function due_soon_row( array $row, array $employee, ?array $last, bool $can_remind ): array {
		$id     = (int) $row['user_id'];
		$name   = (string) $row['name'];
		$owed   = self::owed_courses( $employee );
		$due_ts = (int) ( $employee['due_timestamp'] ?? 0 );
		$days   = $due_ts > 0 ? self::days_from_today( $due_ts ) : null;
		$pct    = max( 0, min( 100, (int) $row['progress_pct'] ) );
		$chip   = '<span class="gh-st gh-st--' . esc_attr( GHCA_ACD_Redesign_Tables::status_tone( (string) $row['status_slug'] ) ) . '">' . esc_html( (string) $row['status_label'] ) . '</span>';
		/* translators: %d: number of courses not yet complete */
		$count  = sprintf( _n( '%d course not complete', '%d courses not complete', count( $owed ), 'ghca-acd' ), count( $owed ) );
		$left   = null === $days ? '—' : ( 0 === $days ? __( 'Today', 'ghca-acd' ) : (string) max( 0, $days ) );

		$h  = '<tr><td>' . self::person_html( $id, $name, (string) $row['group'] ) . '</td>';
		$h .= '<td class="gh-owed-sum">' . esc_html( $count ) . '<div class="gh-small gh-muted">' . esc_html( sprintf( /* translators: %s: course title */ __( 'Next: %s', 'ghca-acd' ), (string) $row['next_course_label'] ) ) . '</div></td>';
		$h .= '<td class="c-num"><strong class="gh-soon">' . esc_html( $left ) . '</strong>' . ( $due_ts > 0 ? '<div class="gh-small gh-muted">' . esc_html( sprintf( /* translators: %s: short date */ __( 'due %s', 'ghca-acd' ), wp_date( 'M j', $due_ts ) ) ) . '</div>' : '' ) . '</td>';
		$h .= '<td class="gh-prog-cell"><div class="gh-bar" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Compliance progress', 'ghca-acd' ) . '"><span style="width: ' . esc_attr( (string) $pct ) . '%;"></span></div><span class="gh-small gh-muted">' . esc_html( (string) ( $employee['completed_label'] ?? $row['progress_label'] ) ) . '</span></td>';
		if ( null !== $last ) {
			$h .= '<td class="gh-num">' . self::last_reminder_html( $last[ $id ] ?? null ) . '</td>';
		}
		$h .= '<td>' . $chip . '</td><td class="c-act"><div class="gh-row-act">' . self::view_link( $id, $name ) . ( $can_remind ? ' ' . self::remind_button( $id, $name, false ) : '' ) . '</div></td></tr>';

		$kv = array(
			array( __( 'Days left', 'ghca-acd' ), esc_html( $left ) ),
			array( __( 'Courses due', 'ghca-acd' ), esc_html( $count ) ),
		);
		if ( null !== $last ) {
			$kv[] = array( __( 'Last reminder', 'ghca-acd' ), self::last_reminder_html( $last[ $id ] ?? null ) );
		}
		$card = self::card_html( '<a class="gh-person-n" href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $id ) ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</a>', (string) $row['group'], $chip, $kv, self::view_link( $id, $name, false ) . ( $can_remind ? self::remind_button( $id, $name, false, false ) : '' ), false );
		return array( 'row' => $h, 'card' => $card );
	}

	/**
	 * Counts over every row that matches the filters, not just this page.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,array<string,mixed>> $employees
	 * @return array{overdue:int,at_risk:int,owed:int}
	 */
	public static function priority_counts( array $rows, array $employees ): array {
		$out = array( 'overdue' => 0, 'at_risk' => 0, 'owed' => 0 );
		foreach ( $rows as $row ) {
			if ( 'overdue' === $row['priority'] ) {
				++$out['overdue'];
				$out['owed'] += count( self::owed_courses( (array) ( $employees[ (int) $row['user_id'] ] ?? array() ) ) );
			} else {
				++$out['at_risk'];
			}
		}
		return $out;
	}

	/**
	 * Mount content, chips and summary for one request. Shared by the first render and the AJAX refresh.
	 *
	 * @param array<string,mixed> $filters  From GHCA_ACD_Data_Provider::get_priority_filters().
	 * @return array{html:string,chips:string,summary:string}
	 */
	public static function priority_parts( array $filters ): array {
		$all       = GHCA_ACD_Data_Provider::get_priority_rows( $filters );
		$paged     = GHCA_ACD_Table_UI::paginate( $all, (int) $filters['page'], (int) $filters['per_page'] );
		$employees = self::employees_by_id();
		$counts    = self::priority_counts( $all, $employees );
		$chips     = self::priority_chips( $filters );
		$summary   = self::showing( $paged, __( 'No one to show', 'ghca-acd' ) );
		$window    = (int) GHCA_ACD_Settings::get_at_risk_days();

		if ( empty( $all ) ) {
			$html = $chips
				? self::empty_html( 'search', __( 'No one matches these filters', 'ghca-acd' ), __( 'Try different filters, or clear them to see everyone who needs attention.', 'ghca-acd' ), true )
				: self::empty_html( 'check-circle', __( 'No one is overdue', 'ghca-acd' ), sprintf( /* translators: %d: days in the at-risk window */ __( 'Everyone with a due date is on track, and no one is due within %d days.', 'ghca-acd' ), $window ), false, true );
			return array( 'html' => $html, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
		}

		$ids = array();
		foreach ( $paged['rows'] as $row ) {
			$ids[] = (int) $row['user_id'];
		}
		$last       = self::last_reminders( $ids );
		$can_remind = GHCA_ACD_Roles::user_can_send_reminders();

		$over = array();
		$soon = array();
		foreach ( $paged['rows'] as $row ) {
			$employee = (array) ( $employees[ (int) $row['user_id'] ] ?? array() );
			if ( 'overdue' === $row['priority'] ) {
				$over[] = self::overdue_row( $row, $employee, $last, $can_remind );
			} else {
				$soon[] = self::due_soon_row( $row, $employee, $last, $can_remind );
			}
		}

		$h = '';
		if ( 0 === $counts['overdue'] && 'at_risk' !== (string) $filters['priority'] ) {
			$h .= self::empty_html( 'check-circle', __( 'No one is overdue', 'ghca-acd' ), __( 'Everyone with a due date is on track. Due-soon items still show below.', 'ghca-acd' ), false, true );
		}
		if ( $over ) {
			$h .= '<section class="gh-sect" aria-label="' . esc_attr__( 'Overdue', 'ghca-acd' ) . '"><div class="gh-table-wrap gh-table-wrap--menu gh-tbl" role="group" aria-label="' . esc_attr__( 'Overdue employees table', 'ghca-acd' ) . '"><table class="gh-table"><thead><tr>';
			$h .= '<th scope="col">' . esc_html__( 'Employee', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Overdue courses', 'ghca-acd' ) . '</th><th scope="col" class="c-num">' . esc_html__( 'Days late', 'ghca-acd' ) . '</th>';
			$h .= null !== $last ? '<th scope="col">' . esc_html__( 'Last reminder', 'ghca-acd' ) . '</th>' : '';
			$h .= '<th scope="col">' . esc_html__( 'Status', 'ghca-acd' ) . '</th><th scope="col" class="c-act">' . esc_html__( 'Actions', 'ghca-acd' ) . '</th></tr></thead><tbody>';
			$h .= implode( '', array_column( $over, 'row' ) ) . '</tbody></table></div>';
			$h .= '<div class="gh-cards" role="list" aria-label="' . esc_attr__( 'Overdue employees', 'ghca-acd' ) . '">';
			foreach ( $over as $item ) {
				$h .= '<div role="listitem">' . $item['card'] . '</div>';
			}
			$h .= '</div></section>';
		}
		if ( $soon ) {
			/* translators: %d: days in the at-risk window */
			$title = sprintf( __( 'Due within %d days', 'ghca-acd' ), $window );
			$h    .= '<section class="gh-sect" aria-labelledby="ghca-pri-soon-h"><h3 class="gh-h4" id="ghca-pri-soon-h">' . esc_html( $title ) . '</h3>';
			$h    .= '<div class="gh-table-wrap gh-tbl" tabindex="0" role="group" aria-label="' . esc_attr( $title ) . '"><table class="gh-table"><thead><tr>';
			$h    .= '<th scope="col">' . esc_html__( 'Employee', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Courses due', 'ghca-acd' ) . '</th><th scope="col" class="c-num">' . esc_html__( 'Days left', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Progress', 'ghca-acd' ) . '</th>';
			$h    .= null !== $last ? '<th scope="col">' . esc_html__( 'Last reminder', 'ghca-acd' ) . '</th>' : '';
			$h    .= '<th scope="col">' . esc_html__( 'Status', 'ghca-acd' ) . '</th><th scope="col" class="c-act">' . esc_html__( 'Actions', 'ghca-acd' ) . '</th></tr></thead><tbody>';
			$h    .= implode( '', array_column( $soon, 'row' ) ) . '</tbody></table></div>';
			$h    .= '<div class="gh-cards" role="list" aria-label="' . esc_attr( $title ) . '">';
			foreach ( $soon as $item ) {
				$h .= '<div role="listitem">' . $item['card'] . '</div>';
			}
			$h .= '</div></section>';
		}
		$h .= GHCA_ACD_Redesign_Tables::pager_html( $paged, 'ghca_pri_page', 'ghca_pri_per', self::PRIORITY_FORM );

		return array( 'html' => $h, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
	}

	/** The toolbar line, from every row in view (no filters), so it describes the whole tab. */
	public static function priority_headline(): string {
		$all    = GHCA_ACD_Data_Provider::get_priority_rows( array() );
		$counts = self::priority_counts( $all, self::employees_by_id() );
		if ( 0 === $counts['overdue'] && 0 === $counts['at_risk'] ) {
			return __( 'No one in your view is overdue or due soon.', 'ghca-acd' );
		}
		$parts = array();
		/* translators: 1: overdue employees, 2: courses they owe */
		$parts[] = sprintf( _n( '%1$d employee overdue on %2$d courses', '%1$d employees overdue on %2$d courses', $counts['overdue'], 'ghca-acd' ), $counts['overdue'], $counts['owed'] );
		/* translators: 1: employees due soon, 2: days in the at-risk window */
		$parts[] = sprintf( _n( '%1$d more due within %2$d days', '%1$d more due within %2$d days', $counts['at_risk'], 'ghca-acd' ), $counts['at_risk'], (int) GHCA_ACD_Settings::get_at_risk_days() );
		return implode( ' · ', $parts );
	}

	/** @param array<string,mixed> $atts */
	public static function render_overdue( $atts = array() ): string {
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
			return '';
		}
		unset( $atts );
		$filters = GHCA_ACD_Data_Provider::get_priority_filters();
		$parts   = self::priority_parts( $filters );
		$form    = self::PRIORITY_FORM;

		$h  = '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'Overdue & at risk', 'ghca-acd' ) . '</h2><span class="gh-small gh-muted">' . esc_html( self::priority_headline() ) . '</span></div></div>';
		$h .= '<div class="gh-card gh-tcard"><form class="gh-filters" id="' . esc_attr( $form ) . '" method="get" role="search" aria-label="' . esc_attr__( 'Filter overdue and at-risk employees', 'ghca-acd' ) . '" data-ghca-filter-form data-ghca-ui="board" data-ghca-debounce="300" data-ghca-table="priority">';
		$h .= '<input type="hidden" name="ghca_pri_page" value="' . esc_attr( (string) $filters['page'] ) . '" data-ghca-page-input />';
		$h .= '<input type="hidden" name="ghca_orderby" value="' . esc_attr( (string) ( $filters['orderby'] ?? '' ) ) . '" />';
		$h .= '<input type="hidden" name="ghca_order" value="' . esc_attr( (string) ( $filters['order'] ?? 'asc' ) ) . '" />';
		$h .= self::search_field( $form, 'ghca_pri_search', (string) $filters['search'], __( 'Name, email or group', 'ghca-acd' ) );
		$h .= GHCA_ACD_Redesign_Tables::select_field( $form . '-g', __( 'Group', 'ghca-acd' ), 'ghca_pri_group', (string) $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'All groups', 'ghca-acd' ) );
		$h .= GHCA_ACD_Redesign_Tables::select_field( $form . '-t', __( 'Show', 'ghca-acd' ), 'ghca_pri_type', (string) $filters['priority'], array( 'overdue' => __( 'Overdue only', 'ghca-acd' ), 'at_risk' => __( 'Due soon only', 'ghca-acd' ) ), __( 'Overdue and due soon', 'ghca-acd' ) );
		$h .= self::sort_select( $form . '-s', self::priority_sort_value( $filters ), self::priority_sort_options() );
		$h .= '</form><div class="gh-chiprow" data-ghca-chips="priority">' . $parts['chips'] . '</div></div>';
		$h .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-table-status="priority"></p>';
		$h .= '<div class="gh-tmount" data-ghca-table data-ghca-table-id="priority">' . $parts['html'] . '</div>';
		return $h;
	}

	/* ---------------------------------------------------------------------
	 * Courses
	 * ------------------------------------------------------------------- */

	/** @return array<string,string> */
	public static function course_sort_options(): array {
		return array(
			''          => __( 'Course A–Z', 'ghca-acd' ),
			'rate_asc'  => __( 'Lowest completion first', 'ghca-acd' ),
			'rate_desc' => __( 'Highest completion first', 'ghca-acd' ),
		);
	}

	public static function course_sort(): string {
		$sort = self::request_key( 'ghca_crs_sort' );
		return isset( self::course_sort_options()[ $sort ] ) ? $sort : '';
	}

	/**
	 * The provider's rows, re-ordered for the Sort control. Ties keep course then group order, so the
	 * order is the same on every request.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_course_rows( array $rows, string $sort ): array {
		if ( '' === $sort ) {
			return $rows;
		}
		$desc = 'rate_desc' === $sort;
		usort( $rows, static function ( array $a, array $b ) use ( $desc ): int {
			$cmp = (int) $a['rate'] <=> (int) $b['rate'];
			if ( 0 !== $cmp ) {
				return $desc ? -$cmp : $cmp;
			}
			$cmp = strcasecmp( (string) $a['course'], (string) $b['course'] );
			return 0 !== $cmp ? $cmp : strcasecmp( (string) $a['group'], (string) $b['group'] );
		} );
		return $rows;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array{label:string,clear:string,remove:string}>
	 */
	public static function course_chips( array $filters ): array {
		$chips = array();
		if ( '' !== (string) $filters['group'] ) {
			$groups  = GHCA_ACD_Data_Provider::get_group_options();
			$chips[] = array( 'label' => sprintf( /* translators: %s: group name */ __( 'Group: %s', 'ghca-acd' ), (string) ( $groups[ (int) $filters['group'] ] ?? $filters['group'] ) ), 'clear' => 'ghca_crs_group', 'remove' => __( 'Remove group filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['certificate'] ) {
			$chips[] = array( 'label' => 'yes' === $filters['certificate'] ? __( 'Issues a certificate', 'ghca-acd' ) : __( 'No certificate', 'ghca-acd' ), 'clear' => 'ghca_crs_cert', 'remove' => __( 'Remove certificate filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['search'] ) {
			$chips[] = array( 'label' => sprintf( /* translators: %s: search words */ __( 'Search: %s', 'ghca-acd' ), (string) $filters['search'] ), 'clear' => 'ghca_crs_search', 'remove' => __( 'Remove search', 'ghca-acd' ) );
		}
		return $chips;
	}

	/** @param array<string,mixed> $row */
	private static function course_bar_class( int $pct ): string {
		return 100 === $pct ? 'gh-bar gh-bar--success' : ( $pct < 30 ? 'gh-bar gh-bar--danger' : 'gh-bar' );
	}

	/**
	 * @param array<string,mixed> $filters  From GHCA_ACD_Data_Provider::get_course_filters().
	 * @return array{html:string,chips:string,summary:string}
	 */
	public static function course_parts( array $filters, string $sort = '' ): array {
		$all     = self::sort_course_rows( GHCA_ACD_Data_Provider::get_course_overview_rows( $filters ), $sort );
		$paged   = GHCA_ACD_Table_UI::paginate( $all, (int) $filters['page'], (int) $filters['per_page'] );
		$chips   = self::course_chips( $filters );
		$summary = self::showing( $paged, __( 'No course assignments to show', 'ghca-acd' ) );

		if ( empty( $all ) ) {
			$html = $chips
				? self::empty_html( 'search', __( 'No courses match these filters', 'ghca-acd' ), __( 'Try different filters, or clear them to see every course in your view.', 'ghca-acd' ), true )
				: self::empty_html( 'grad', __( 'No tracked courses in your view', 'ghca-acd' ), __( 'Courses appear here once employees in your groups are enrolled.', 'ghca-acd' ), false );
			return array( 'html' => $html, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
		}

		$h  = '<div class="gh-table-wrap gh-tbl" tabindex="0" role="group" aria-label="' . esc_attr__( 'Course completion table', 'ghca-acd' ) . '"><table class="gh-table gh-table--courses"><thead><tr>';
		$h .= '<th scope="col" class="c-course">' . esc_html__( 'Course', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Assigned group', 'ghca-acd' ) . '</th>';
		$h .= '<th scope="col" class="c-num">' . esc_html__( 'Required', 'ghca-acd' ) . '</th><th scope="col" class="c-num">' . esc_html__( 'Done', 'ghca-acd' ) . '</th><th scope="col" class="c-num">' . esc_html__( 'In progress', 'ghca-acd' ) . '</th><th scope="col" class="c-num">' . esc_html__( 'Not started', 'ghca-acd' ) . '</th>';
		$h .= '<th scope="col" class="c-rate"' . ( '' !== $sort ? ' aria-sort="' . ( 'rate_desc' === $sort ? 'descending' : 'ascending' ) . '"' : '' ) . '>' . esc_html__( 'Completion', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Certificate', 'ghca-acd' ) . '</th></tr></thead><tbody>';
		$cards = '';
		foreach ( $paged['rows'] as $row ) {
			$pct   = max( 0, min( 100, (int) $row['rate'] ) );
			$cert  = ! empty( $row['certificate'] );
			$certh = '<span class="gh-small gh-cert-flag">' . self::icon( $cert ? 'award' : 'minus-circle', 'sm' ) . esc_html( $cert ? __( 'Issues certificate', 'ghca-acd' ) : __( 'No certificate', 'ghca-acd' ) ) . '</span>';
			$bar   = '<div class="gh-rate"><div class="' . esc_attr( self::course_bar_class( $pct ) ) . '" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Course completion rate', 'ghca-acd' ) . '"><span style="width: ' . esc_attr( (string) $pct ) . '%;"></span></div><strong class="gh-num">' . esc_html( (string) $row['rate_label'] ) . '</strong></div>';
			$h    .= '<tr><td><strong>' . esc_html( (string) $row['course'] ) . '</strong></td><td><span class="gh-badge gh-badge--outline">' . esc_html( (string) $row['group'] ) . '</span></td>';
			$h    .= '<td class="c-num">' . esc_html( (string) (int) $row['required'] ) . '</td><td class="c-num">' . esc_html( (string) (int) $row['completed'] ) . '</td><td class="c-num">' . esc_html( (string) (int) $row['in_progress'] ) . '</td><td class="c-num">' . esc_html( (string) (int) $row['not_started'] ) . '</td>';
			$h    .= '<td>' . $bar . '</td><td>' . $certh . '</td></tr>';
			$cards .= '<div role="listitem">' . self::card_html(
				'<strong class="gh-mcard-t">' . esc_html( (string) $row['course'] ) . '</strong>',
				(string) $row['group'],
				'',
				array(
					array( __( 'Completion', 'ghca-acd' ), $bar ),
					/* translators: 1: done, 2: in progress, 3: not started, 4: required */
					array( __( 'People', 'ghca-acd' ), esc_html( sprintf( __( '%1$d done · %2$d in progress · %3$d not started, of %4$d', 'ghca-acd' ), (int) $row['completed'], (int) $row['in_progress'], (int) $row['not_started'], (int) $row['required'] ) ) ),
					array( __( 'Certificate', 'ghca-acd' ), $certh ),
				),
				'',
				false
			) . '</div>';
		}
		$h .= '</tbody></table></div><div class="gh-cards" role="list" aria-label="' . esc_attr__( 'Courses', 'ghca-acd' ) . '">' . $cards . '</div>';
		$h .= GHCA_ACD_Redesign_Tables::pager_html( $paged, 'ghca_crs_page', 'ghca_crs_per', self::COURSES_FORM );
		return array( 'html' => $h, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
	}

	/** @param array<string,mixed> $atts */
	public static function render_courses( $atts = array() ): string {
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
			return '';
		}
		unset( $atts );
		$filters = GHCA_ACD_Data_Provider::get_course_filters();
		$sort    = self::course_sort();
		$parts   = self::course_parts( $filters, $sort );
		$form    = self::COURSES_FORM;
		// The same count as the Courses tab badge and the overview's Tracked courses figure.
		$courses     = GHCA_ACD_Data_Provider::count_unique_tracked_courses( GHCA_ACD_Data_Provider::get_aggregate()['employees'] ?? array() );
		$assignments = count( GHCA_ACD_Data_Provider::get_course_overview_rows( array() ) );
		/* translators: 1: tracked courses, 2: course and group assignments */
		$headline = sprintf( __( 'One row per course and group. %1$s across %2$s. Counts are people.', 'ghca-acd' ), sprintf( _n( '%d tracked course', '%d tracked courses', $courses, 'ghca-acd' ), $courses ), sprintf( _n( '%d assignment', '%d assignments', $assignments, 'ghca-acd' ), $assignments ) );

		$h  = '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'Course completion', 'ghca-acd' ) . '</h2><span class="gh-small gh-muted">' . esc_html( $headline ) . '</span></div></div>';
		$h .= '<div class="gh-card gh-tcard"><form class="gh-filters" id="' . esc_attr( $form ) . '" method="get" role="search" aria-label="' . esc_attr__( 'Filter courses', 'ghca-acd' ) . '" data-ghca-filter-form data-ghca-ui="board" data-ghca-debounce="300" data-ghca-table="courses">';
		$h .= '<input type="hidden" name="ghca_crs_page" value="' . esc_attr( (string) $filters['page'] ) . '" data-ghca-page-input />';
		$h .= self::search_field( $form, 'ghca_crs_search', (string) $filters['search'], __( 'Course or group name', 'ghca-acd' ) );
		$h .= GHCA_ACD_Redesign_Tables::select_field( $form . '-g', __( 'Group', 'ghca-acd' ), 'ghca_crs_group', (string) $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'All groups', 'ghca-acd' ) );
		$h .= GHCA_ACD_Redesign_Tables::select_field( $form . '-c', __( 'Certificate', 'ghca-acd' ), 'ghca_crs_cert', (string) $filters['certificate'], array( 'yes' => __( 'Issues a certificate', 'ghca-acd' ), 'no' => __( 'No certificate', 'ghca-acd' ) ), __( 'Any', 'ghca-acd' ) );
		// A named control here: the value travels as ghca_crs_sort. The legacy table never reads it.
		$h .= '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $form ) . '-s">' . esc_html__( 'Sort', 'ghca-acd' ) . '</label><select id="' . esc_attr( $form ) . '-s" class="gh-select" name="ghca_crs_sort" data-ghca-not-filter>';
		foreach ( self::course_sort_options() as $value => $label ) {
			$h .= '<option value="' . esc_attr( $value ) . '"' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$h .= '</select></div></form><div class="gh-chiprow" data-ghca-chips="courses">' . $parts['chips'] . '</div></div>';
		$h .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-table-status="courses"></p>';
		$h .= '<div class="gh-tmount" data-ghca-table data-ghca-table-id="courses">' . $parts['html'] . '</div>';
		return $h;
	}

	/* ---------------------------------------------------------------------
	 * Certificates
	 * ------------------------------------------------------------------- */

	/**
	 * One row per employee and course with a certificate configured or issued, built from the same course
	 * records GHCA_ACD_Data_Provider::summarize_certificates() counts. Issued: completed with a certificate
	 * link. Missing: completed, certificate configured, no link yet.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function certificate_rows(): array {
		$rows = array();
		foreach ( self::employees_by_id() as $employee ) {
			foreach ( (array) ( $employee['courses'] ?? array() ) as $course ) {
				if ( empty( $course['completed'] ) ) {
					continue;
				}
				$issued = ! empty( $course['certificate_url'] );
				if ( ! $issued && empty( $course['has_certificate'] ) ) {
					continue;
				}
				$rows[] = array(
					'kind'             => $issued ? 'issued' : 'missing',
					'state'            => (string) ( $course['compliance_state'] ?? '' ),
					'user_id'          => (int) $employee['user_id'],
					'name'             => (string) $employee['name'],
					'email'            => (string) ( $employee['email'] ?? '' ),
					'group'            => (string) ( $employee['group'] ?? '' ),
					'course_id'        => (int) ( $course['id'] ?? 0 ),
					'course'           => (string) ( $course['title'] ?? '' ),
					'completed_ts'     => (int) ( $course['completed_ts'] ?? 0 ),
					'expiration_ts'    => (int) ( $course['expiration_ts'] ?? 0 ),
					'expiration_label' => (string) ( $course['expiration_label'] ?? '' ),
				);
			}
		}
		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{issued:int,expiring:int,missing:int}
	 */
	public static function certificate_counts( array $rows ): array {
		$out = array( 'issued' => 0, 'expiring' => 0, 'missing' => 0 );
		foreach ( $rows as $row ) {
			if ( 'missing' === $row['kind'] ) {
				++$out['missing'];
				continue;
			}
			++$out['issued'];
			if ( 'expiring_soon' === $row['state'] ) {
				++$out['expiring'];
			}
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public static function certificate_filters(): array {
		$view = self::request_key( 'ghca_cert_view' );
		$when = self::request_key( 'ghca_cert_when' );
		$by   = self::request_key( 'ghca_cert_orderby' );
		$ord  = self::request_key( 'ghca_cert_order' );
		return array(
			'view'     => in_array( $view, array( 'issued', 'expiring', 'missing' ), true ) ? $view : 'issued',
			'course'   => (string) absint( self::request_key( 'ghca_cert_course' ) ?: '0' ),
			'search'   => self::request_text( 'ghca_cert_search' ),
			'when'     => '30' === $when ? '30' : '',
			'orderby'  => in_array( $by, array( 'completed_ts', 'name', 'course' ), true ) ? $by : 'completed_ts',
			'order'    => in_array( $ord, array( 'asc', 'desc' ), true ) ? $ord : ( in_array( $by, array( 'name', 'course' ), true ) ? 'asc' : 'desc' ),
			'page'     => GHCA_ACD_Table_UI::normalize_page( (int) ( self::request_key( 'ghca_cert_page' ) ?: '1' ) ),
			'per_page' => GHCA_ACD_Table_UI::normalize_per_page( (int) ( self::request_key( 'ghca_cert_per' ) ?: '15' ) ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_certificate_rows( array $rows, array $filters ): array {
		$view   = (string) $filters['view'];
		$course = (int) $filters['course'];
		$search = (string) $filters['search'];
		$since  = '30' === $filters['when'] ? self::now() - 30 * DAY_IN_SECONDS : 0;
		$rows   = array_values( array_filter( $rows, static function ( array $row ) use ( $view, $course, $search, $since ): bool {
			if ( 'missing' === $view ? 'missing' !== $row['kind'] : 'issued' !== $row['kind'] ) {
				return false;
			}
			if ( 'expiring' === $view && 'expiring_soon' !== $row['state'] ) {
				return false;
			}
			if ( $course > 0 && (int) $row['course_id'] !== $course ) {
				return false;
			}
			if ( $since > 0 && (int) $row['completed_ts'] < $since ) {
				return false;
			}
			return '' === $search || GHCA_ACD_Table_UI::matches_search( $search, array( $row['name'], $row['email'], $row['group'], $row['course'] ) );
		} ) );

		$by   = (string) $filters['orderby'];
		$desc = 'desc' === $filters['order'];
		usort( $rows, static function ( array $a, array $b ) use ( $by, $desc ): int {
			$cmp = 'completed_ts' === $by ? (int) $a['completed_ts'] <=> (int) $b['completed_ts'] : strcasecmp( (string) $a[ $by ], (string) $b[ $by ] );
			if ( 0 === $cmp ) {
				$cmp = strcasecmp( $a['name'] . "\0" . $a['course'], $b['name'] . "\0" . $b['course'] );
				return $cmp;
			}
			return $desc ? -$cmp : $cmp;
		} );
		return $rows;
	}

	/** @return array<int,string> course id => title, for courses that appear on this tab. */
	private static function certificate_course_options( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['course_id'] ] = (string) $row['course'];
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array{label:string,clear:string,remove:string}>
	 */
	public static function certificate_chips( array $filters, array $rows ): array {
		$chips = array();
		if ( (int) $filters['course'] > 0 ) {
			$courses = self::certificate_course_options( $rows );
			$chips[] = array( 'label' => sprintf( /* translators: %s: course title */ __( 'Course: %s', 'ghca-acd' ), (string) ( $courses[ (int) $filters['course'] ] ?? $filters['course'] ) ), 'clear' => 'ghca_cert_course', 'remove' => __( 'Remove course filter', 'ghca-acd' ) );
		}
		if ( '30' === $filters['when'] ) {
			$chips[] = array( 'label' => __( 'Completed in the last 30 days', 'ghca-acd' ), 'clear' => 'ghca_cert_when', 'remove' => __( 'Remove date filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['search'] ) {
			$chips[] = array( 'label' => sprintf( /* translators: %s: search words */ __( 'Search: %s', 'ghca-acd' ), (string) $filters['search'] ), 'clear' => 'ghca_cert_search', 'remove' => __( 'Remove search', 'ghca-acd' ) );
		}
		return $chips;
	}

	/** @param array<string,mixed> $row */
	private static function expires_html( array $row ): string {
		if ( 'missing' === $row['kind'] ) {
			return '<span class="gh-muted">—</span>';
		}
		if ( '' === $row['expiration_label'] ) {
			return '<span class="gh-muted" title="' . esc_attr__( 'No renewal period is set for this course.', 'ghca-acd' ) . '">' . esc_html__( 'No renewal set', 'ghca-acd' ) . '</span>';
		}
		$h = esc_html( (string) $row['expiration_label'] );
		if ( 'expiring_soon' === $row['state'] ) {
			$h .= '<div class="gh-cert-exp"><span class="gh-st gh-st--duesoon">' . esc_html__( 'Expires soon', 'ghca-acd' ) . '</span></div>';
		} elseif ( 'expired' === $row['state'] ) {
			$h .= '<div class="gh-cert-exp"><span class="gh-st gh-st--overdue">' . esc_html__( 'Expired', 'ghca-acd' ) . '</span></div>';
		}
		return $h;
	}

	/**
	 * Download goes through the existing certificate endpoint (and its broker), with ids only. Offered only to
	 * people who can view employee documents, which is what that endpoint itself requires.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function certificate_action_html( array $row, bool $can_docs, bool $small = true ): string {
		if ( 'missing' === $row['kind'] ) {
			return self::view_link( (int) $row['user_id'], (string) $row['name'], $small );
		}
		if ( ! $can_docs ) {
			return '';
		}
		$url = GHCA_ACD_Certificate_Download::download_url( (int) $row['user_id'], (int) $row['course_id'] );
		/* translators: 1: course title, 2: employee name */
		$label = sprintf( __( 'Download PDF, %1$s, %2$s', 'ghca-acd' ), (string) $row['course'], (string) $row['name'] );
		// A <button> on the board too, so it keeps the button cascade (no gh-btn--lnk).
		return '<button type="button" class="gh-btn gh-btn--secondary' . ( $small ? ' gh-btn--sm' : '' ) . '" data-ghca-cert-pdf="' . esc_url( $url ) . '" data-ghca-cert-title="' . esc_attr( (string) $row['course'] ) . '" aria-label="' . esc_attr( $label ) . '">' . self::icon( 'download', 'sm' ) . esc_html__( 'PDF', 'ghca-acd' ) . '</button>';
	}

	/**
	 * @param array<string,mixed> $filters  From certificate_filters().
	 * @return array{html:string,chips:string,summary:string}
	 */
	public static function certificate_parts( array $filters ): array {
		$every    = self::certificate_rows();
		$all      = self::filter_certificate_rows( $every, $filters );
		$paged    = GHCA_ACD_Table_UI::paginate( $all, (int) $filters['page'], (int) $filters['per_page'] );
		$chips    = self::certificate_chips( $filters, $every );
		$summary  = self::showing( $paged, __( 'No certificates to show', 'ghca-acd' ) );
		$can_docs = GHCA_ACD_Roles::user_can_view_employee_documents();
		$view     = (string) $filters['view'];

		if ( empty( $all ) ) {
			if ( $chips ) {
				$html = self::empty_html( 'search', __( 'No certificates match these filters', 'ghca-acd' ), __( 'Try different filters, or clear them.', 'ghca-acd' ), true );
			} elseif ( 'missing' === $view ) {
				$html = self::empty_html( 'check-circle', __( 'Nothing is missing', 'ghca-acd' ), __( 'Every completed course that issues a certificate has one.', 'ghca-acd' ), false, true );
			} elseif ( 'expiring' === $view ) {
				$html = self::empty_html( 'check-circle', __( 'Nothing is expiring soon', 'ghca-acd' ), '', false, true );
			} else {
				$html = self::empty_html( 'award', __( 'No certificates issued yet', 'ghca-acd' ), __( 'Certificates appear here when employees in your view complete a course that issues one.', 'ghca-acd' ), false );
			}
			return array( 'html' => $html, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
		}

		$by    = (string) $filters['orderby'];
		$order = (string) $filters['order'];
		$h     = '<div class="gh-table-wrap gh-table-wrap--flat gh-tbl" tabindex="0" role="group" aria-label="' . esc_attr__( 'Certificates table', 'ghca-acd' ) . '"><table class="gh-table"><thead><tr>';
		$h    .= GHCA_ACD_Redesign_Tables::sort_th( 'completed_ts', __( 'Completed', 'ghca-acd' ), $by, $order );
		$h    .= GHCA_ACD_Redesign_Tables::sort_th( 'name', __( 'Employee', 'ghca-acd' ), $by, $order );
		$h    .= GHCA_ACD_Redesign_Tables::sort_th( 'course', __( 'Course', 'ghca-acd' ), $by, $order );
		$h    .= '<th scope="col">' . esc_html( 'missing' === $view ? __( 'Certificate', 'ghca-acd' ) : __( 'Expires', 'ghca-acd' ) ) . '</th><th scope="col" class="c-act"><span class="gh-sr">' . esc_html__( 'Actions', 'ghca-acd' ) . '</span></th></tr></thead><tbody>';
		$cards = '';
		foreach ( $paged['rows'] as $row ) {
			$done    = (int) $row['completed_ts'] > 0 ? wp_date( 'M j, Y', (int) $row['completed_ts'] ) : '—';
			$fourth  = 'missing' === $row['kind'] ? '<span class="gh-st gh-st--missing">' . esc_html__( 'No certificate link', 'ghca-acd' ) . '</span>' : self::expires_html( $row );
			$action  = self::certificate_action_html( $row, $can_docs );
			$h      .= '<tr' . ( 'missing' === $row['kind'] ? ' class="is-risk"' : '' ) . '><td class="gh-num gh-nowrap">' . esc_html( $done ) . '</td>';
			$h      .= '<td><strong>' . esc_html( (string) $row['name'] ) . '</strong><div class="gh-small gh-muted">' . esc_html( (string) $row['group'] ) . '</div></td>';
			$h      .= '<td>' . esc_html( (string) $row['course'] ) . '</td><td class="gh-num">' . $fourth . '</td><td class="c-act">' . $action . '</td></tr>';
			$cards  .= '<div role="listitem">' . self::card_html(
				'<strong class="gh-mcard-t">' . esc_html( (string) $row['course'] ) . '</strong>',
				(string) $row['name'],
				'missing' === $row['kind'] ? '<span class="gh-st gh-st--missing">' . esc_html__( 'Missing', 'ghca-acd' ) . '</span>' : '',
				array(
					array( __( 'Completed', 'ghca-acd' ), esc_html( $done ) ),
					array( 'missing' === $view ? __( 'Certificate', 'ghca-acd' ) : __( 'Expires', 'ghca-acd' ), 'missing' === $row['kind'] ? esc_html__( 'No certificate link', 'ghca-acd' ) : self::expires_html( $row ) ),
				),
				self::certificate_action_html( $row, $can_docs, false ),
				'missing' === $row['kind']
			) . '</div>';
		}
		$h .= '</tbody></table></div><div class="gh-cards gh-cards--pad" role="list" aria-label="' . esc_attr__( 'Certificates', 'ghca-acd' ) . '">' . $cards . '</div>';
		$h .= GHCA_ACD_Redesign_Tables::pager_html( $paged, 'ghca_cert_page', 'ghca_cert_per', self::CERTIFICATES_FORM );
		return array( 'html' => $h, 'chips' => self::chips_html( $summary, $chips ), 'summary' => $summary );
	}

	/**
	 * @param array<string,mixed> $aggregate
	 * @param array{issued:int,expiring:int,missing:int} $counts
	 */
	private static function certificate_stats_html( array $aggregate, array $counts ): string {
		$issued  = (int) ( $aggregate['certificates_issued'] ?? $counts['issued'] );
		$missing = (int) ( $aggregate['certificates_missing'] ?? $counts['missing'] );
		$recent  = (int) ( $aggregate['recently_completed'] ?? 0 );
		$warn    = class_exists( 'GHCA_Course_Lifespans' ) ? (int) GHCA_Course_Lifespans::get_warning_days() : 0;
		$since   = wp_date( 'M j, Y', self::now() - 30 * DAY_IN_SECONDS );

		$h  = '<div class="gh-grid gh-grid--kpi" role="list" aria-label="' . esc_attr__( 'Certificate totals', 'ghca-acd' ) . '">';
		$h .= '<div class="gh-stat" role="listitem"><span class="gh-stat-top">' . esc_html__( 'Issued', 'ghca-acd' ) . '<span class="gh-st gh-st--completed">' . esc_html__( 'Completed', 'ghca-acd' ) . '</span></span><span class="gh-stat-val">' . esc_html( (string) $issued ) . '</span><span class="gh-stat-sub">' . esc_html__( 'Active employees in this view', 'ghca-acd' ) . '</span></div>';
		$h .= '<div class="gh-stat' . ( $counts['expiring'] > 0 ? ' gh-stat--warning' : ' gh-stat--zero' ) . '" role="listitem"><span class="gh-stat-top">' . esc_html__( 'Expiring soon', 'ghca-acd' ) . '<span class="gh-st ' . ( $counts['expiring'] > 0 ? 'gh-st--duesoon">' . esc_html__( 'Due soon', 'ghca-acd' ) : 'gh-st--na">' . esc_html__( 'None', 'ghca-acd' ) ) . '</span></span><span class="gh-stat-val">' . esc_html( (string) $counts['expiring'] ) . '</span><span class="gh-stat-sub">' . esc_html( $warn > 0 ? sprintf( /* translators: %d: renewal warning window in days */ __( 'Renewal due within %d days', 'ghca-acd' ), $warn ) : __( 'Renewal due soon', 'ghca-acd' ) ) . '</span></div>';
		$h .= '<div class="gh-stat' . ( $missing > 0 ? ' gh-stat--danger' : ' gh-stat--zero' ) . '" role="listitem"><span class="gh-stat-top">' . esc_html__( 'Missing', 'ghca-acd' ) . '<span class="gh-st ' . ( $missing > 0 ? 'gh-st--missing">' . esc_html__( 'Missing', 'ghca-acd' ) : 'gh-st--na">' . esc_html__( 'None', 'ghca-acd' ) ) . '</span></span><span class="gh-stat-val">' . esc_html( (string) $missing ) . '</span><span class="gh-stat-sub">' . esc_html__( 'Completed, no certificate link', 'ghca-acd' ) . '</span></div>';
		$h .= '<div class="gh-stat" role="listitem"><span class="gh-stat-top">' . esc_html__( 'Completed · 30 days', 'ghca-acd' ) . '<span class="gh-stat-ico">' . self::icon( 'calendar' ) . '</span></span><span class="gh-stat-val">' . esc_html( (string) $recent ) . '</span><span class="gh-stat-sub">' . esc_html( sprintf( /* translators: %s: date */ __( 'Course completions since %s', 'ghca-acd' ), $since ) ) . '</span></div>';
		return $h . '</div>';
	}

	/** @param array<string,mixed> $aggregate */
	private static function recent_html( array $aggregate ): string {
		$items = array_slice( (array) ( $aggregate['recent_completions'] ?? array() ), 0, 5 );
		$h     = '<div class="gh-card gh-cert-recent" aria-labelledby="ghca-cert-recent-h"><div class="gh-card-hd"><h3 class="gh-h4" id="ghca-cert-recent-h">' . esc_html__( 'Recent completions', 'ghca-acd' ) . '</h3></div><div class="gh-card-bd">';
		if ( ! $items ) {
			return $h . '<p class="gh-secondary">' . esc_html__( 'No course completions in the last 30 days.', 'ghca-acd' ) . '</p></div></div>';
		}
		$h .= '<ol class="gh-tl">';
		foreach ( $items as $item ) {
			$ts   = (int) ( $item['ts'] ?? 0 );
			$when = $ts > 0 ? sprintf( /* translators: %s: human-readable time difference */ __( '%s ago', 'ghca-acd' ), human_time_diff( $ts, self::now() ) ) : __( 'Recently', 'ghca-acd' );
			$h   .= '<li><span class="gh-tl-dot gh-tl-dot--success">' . self::icon( 'check', 'sm' ) . '</span><strong>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</strong><div class="gh-small gh-secondary">' . esc_html( (string) ( $item['course'] ?? '' ) ) . '</div><div class="gh-small gh-muted">' . esc_html( $when ) . '</div></li>';
		}
		return $h . '</ol></div></div>';
	}

	/** @param array<string,mixed> $atts */
	public static function render_certificates( $atts = array() ): string {
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
			return '';
		}
		unset( $atts );
		$aggregate = GHCA_ACD_Data_Provider::get_aggregate();
		$filters   = self::certificate_filters();
		$every     = self::certificate_rows();
		$counts    = self::certificate_counts( $every );
		$parts     = self::certificate_parts( $filters );
		$form      = self::CERTIFICATES_FORM;

		$h  = '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'Certificates & records', 'ghca-acd' ) . '</h2><span class="gh-small gh-muted">' . esc_html__( 'Certificates issued through LearnDash to active employees in your view.', 'ghca-acd' ) . '</span></div></div>';
		$h .= self::certificate_stats_html( $aggregate, $counts );
		$missing = (int) ( $aggregate['certificates_missing'] ?? $counts['missing'] );
		if ( $missing > 0 ) {
			/* translators: %d: completed courses without a certificate link */
			$title = sprintf( _n( '%d completed course has no certificate link', '%d completed courses have no certificate link', $missing, 'ghca-acd' ), $missing );
			$h    .= '<div class="gh-alert gh-alert--danger" role="note">' . self::icon( 'file-x' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html( $title ) . '</span><p>' . esc_html__( 'The course issues a certificate, but LearnDash has not produced one for these completions. The Missing list shows who and which course.', 'ghca-acd' ) . '</p></div>';
			// A link on the board; it filters this page, so it is a button styled as the board's link (gh-btn--lnk).
			$h    .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--lnk" data-ghca-set-field="ghca_cert_view" data-ghca-set-value="missing" data-ghca-set-form="' . esc_attr( $form ) . '">' . esc_html__( 'Show missing', 'ghca-acd' ) . '</button></div>';
		}

		$tabs = array(
			'issued'   => array( __( 'Issued', 'ghca-acd' ), $counts['issued'], '' ),
			'expiring' => array( __( 'Expiring', 'ghca-acd' ), $counts['expiring'], '' ),
			'missing'  => array( __( 'Missing', 'ghca-acd' ), $counts['missing'], $counts['missing'] > 0 ? ' gh-count--danger' : '' ),
		);
		$h .= '<div class="gh-cert-split"><div class="gh-card gh-cert-list" id="ghca-cert-list">';
		$h .= '<form class="gh-cert-form" id="' . esc_attr( $form ) . '" method="get" role="search" aria-label="' . esc_attr__( 'Filter certificates', 'ghca-acd' ) . '" data-ghca-filter-form data-ghca-ui="board" data-ghca-debounce="300" data-ghca-table="certificates">';
		$h .= '<div class="gh-card-hd"><h3 class="gh-h4">' . esc_html__( 'All certificates', 'ghca-acd' ) . '</h3><div class="gh-subtabs" role="group" aria-label="' . esc_attr__( 'Which certificates', 'ghca-acd' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$h .= '<button type="button" class="gh-subtab" data-ghca-set-field="ghca_cert_view" data-ghca-set-value="' . esc_attr( $key ) . '" aria-pressed="' . ( $key === $filters['view'] ? 'true' : 'false' ) . '">' . esc_html( $tab[0] ) . ' <span class="gh-count' . esc_attr( $tab[2] ) . '">' . esc_html( (string) $tab[1] ) . '</span></button>';
		}
		$h .= '</div></div><div class="gh-cert-filters"><div class="gh-filters">';
		$h .= '<input type="hidden" name="ghca_cert_view" value="' . esc_attr( (string) $filters['view'] ) . '" data-ghca-not-filter />';
		$h .= '<input type="hidden" name="ghca_cert_page" value="' . esc_attr( (string) $filters['page'] ) . '" data-ghca-page-input />';
		$h .= '<input type="hidden" name="ghca_cert_orderby" value="' . esc_attr( (string) $filters['orderby'] ) . '" />';
		$h .= '<input type="hidden" name="ghca_cert_order" value="' . esc_attr( (string) $filters['order'] ) . '" />';
		$h .= self::search_field( $form, 'ghca_cert_search', (string) $filters['search'], __( 'Employee or course', 'ghca-acd' ), true, __( 'Search certificates', 'ghca-acd' ) );
		$h .= str_replace( 'class="gh-label"', 'class="gh-label gh-sr"', GHCA_ACD_Redesign_Tables::select_field( $form . '-c', __( 'Course', 'ghca-acd' ), 'ghca_cert_course', (int) $filters['course'] > 0 ? (string) $filters['course'] : '', self::certificate_course_options( $every ), __( 'All courses', 'ghca-acd' ) ) );
		$h .= str_replace( 'class="gh-label"', 'class="gh-label gh-sr"', GHCA_ACD_Redesign_Tables::select_field( $form . '-d', __( 'Completed', 'ghca-acd' ), 'ghca_cert_when', (string) $filters['when'], array( '30' => __( 'Last 30 days', 'ghca-acd' ) ), __( 'Any date', 'ghca-acd' ) ) );
		$h .= '</div><div class="gh-chiprow" data-ghca-chips="certificates">' . $parts['chips'] . '</div></div></form>';
		$h .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-table-status="certificates"></p>';
		$h .= '<div class="gh-tmount gh-tmount--card" data-ghca-table data-ghca-table-id="certificates">' . $parts['html'] . '</div>';
		$h .= '</div>' . self::recent_html( $aggregate ) . '</div>';
		return $h;
	}
}
