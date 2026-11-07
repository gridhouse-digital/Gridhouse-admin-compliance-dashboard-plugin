<?php
/**
 * Board markup for the Employees and Inactive tabs of the opt-in redesign.
 *
 * Presentation only. Rows, filters, sorting, paging, permissions and group scope all come from
 * the same GHCA_ACD_Data_Provider and GHCA_ACD_Table_UI calls the legacy table uses, and the
 * existing data-ghca-* hooks are kept so assets/dashboard.js drives both versions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign_Tables {
	/** Whether the AJAX table refresh should answer with board markup. The server decides; the client only asks. */
	/** Row selection (slice 6) on the active Employees table; GHCA_ACD_Redesign_Bulk decides who may select. */
	private static function selectable(): bool {
		return class_exists( 'GHCA_ACD_Redesign_Bulk' ) && GHCA_ACD_Redesign_Bulk::can_select();
	}

	public static function board_requested(): bool {
		if ( ! class_exists( 'GHCA_ACD_Settings' ) || ! GHCA_ACD_Settings::ui_redesign_enabled() ) {
			return false;
		}
		$ui = isset( $_POST['ghca_ui'] ) ? sanitize_key( wp_unslash( (string) $_POST['ghca_ui'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies the table nonce first.
		return 'board' === $ui;
	}

	/** @return array{table:string,page:string,orderby:string,order:string,group:string,course:string,status:string,overdue:string,search:string,per_page:string} */
	public static function fields( string $account_status ): array {
		if ( 'inactive' === $account_status ) {
			return array(
				'table'    => 'inactive_employees',
				'page'     => 'ghca_inactive_page',
				'orderby'  => 'ghca_inactive_orderby',
				'order'    => 'ghca_inactive_order',
				'group'    => 'ghca_inactive_group',
				'course'   => 'ghca_inactive_course',
				'status'   => 'ghca_inactive_status',
				'overdue'  => 'ghca_inactive_overdue',
				'search'   => 'ghca_inactive_search',
				'per_page' => 'ghca_inactive_per',
			);
		}
		return array(
			'table'    => 'employees',
			'page'     => 'ghca_emp_page',
			'orderby'  => 'ghca_orderby',
			'order'    => 'ghca_order',
			'group'    => 'ghca_group',
			'course'   => 'ghca_course',
			'status'   => 'ghca_status',
			'overdue'  => 'ghca_overdue',
			'search'   => 'ghca_emp_search',
			'per_page' => 'ghca_emp_per',
		);
	}

	/**
	 * Board status chip for an existing status slug. The tone follows the colour the legacy table
	 * already gives each slug, so no row changes meaning. The label stays the plugin's own wording.
	 */
	public static function status_tone( string $slug ): string {
		switch ( $slug ) {
			case 'completed':
			case 'new_hire_completed':
				return 'completed';
			case 'in_progress':
				return 'progress';
			case 'not_started':
				return 'notstarted';
			case 'overdue':
			case 'new_hire_overdue':
			case 'expired':
				return 'overdue';
			case 'expiring_soon':
			case 'new_hire_in_progress':
			case 'new_hire_not_started':
				return 'duesoon';
		}
		return 'na';
	}

	private static function is_overdue_slug( string $slug ): bool {
		return in_array( $slug, array( 'overdue', 'new_hire_overdue', 'expired' ), true );
	}

	public static function icon( string $name, string $size = '' ): string {
		return '<span class="gh-i' . ( '' !== $size ? ' gh-i--' . esc_attr( $size ) : '' ) . ' i-' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * Whole days between today and the due date, both read in the site time zone, as shown under the
	 * date. Returns null when there is nothing honest to say (no date, or the employee is complete).
	 *
	 * @return array{text:string,tone:string}|null
	 */
	public static function due_note( int $due_ts, string $slug, ?int $now = null ): ?array {
		if ( $due_ts <= 0 || in_array( $slug, array( 'completed', 'new_hire_completed' ), true ) ) {
			return null;
		}
		$now   = $now ?? time();
		$today = (int) strtotime( wp_date( 'Y-m-d', $now ) . ' 00:00:00 UTC' );
		$due   = (int) strtotime( wp_date( 'Y-m-d', $due_ts ) . ' 00:00:00 UTC' );
		$days  = (int) round( ( $due - $today ) / 86400 );

		if ( self::is_overdue_slug( $slug ) ) {
			if ( $days < 0 ) {
				/* translators: %d: whole days past the due date */
				return array( 'text' => sprintf( _n( '%d day late', '%d days late', -$days, 'ghca-acd' ), -$days ), 'tone' => 'danger' );
			}
			return array( 'text' => __( 'past due', 'ghca-acd' ), 'tone' => 'danger' );
		}
		if ( $days < 0 ) {
			return null;
		}
		if ( 0 === $days ) {
			return array( 'text' => __( 'due today', 'ghca-acd' ), 'tone' => 'warning' );
		}
		/* translators: %d: whole days until the due date */
		$text = sprintf( _n( 'in %d day', 'in %d days', $days, 'ghca-acd' ), $days );
		return array( 'text' => $text, 'tone' => $days <= 30 ? 'warning' : 'muted' );
	}

	/**
	 * Chips for the filters that are on, as { label, clear } pairs. `clear` is the form field name the
	 * client empties, so removing a chip is the same as clearing that control.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<int,array{label:string,clear:string,remove:string}>
	 */
	public static function active_filters( array $filters, string $account_status ): array {
		$f     = self::fields( $account_status );
		$chips = array();
		if ( '' !== (string) $filters['group'] ) {
			$groups  = GHCA_ACD_Data_Provider::get_group_options();
			$name    = (string) ( $groups[ (int) $filters['group'] ] ?? $filters['group'] );
			$chips[] = array( 'label' => sprintf( /* translators: %s: group name */ __( 'Group: %s', 'ghca-acd' ), $name ), 'clear' => $f['group'], 'remove' => __( 'Remove group filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['course'] ) {
			$courses = GHCA_ACD_Data_Provider::get_course_options( $account_status );
			$name    = (string) ( $courses[ (int) $filters['course'] ] ?? $filters['course'] );
			$chips[] = array( 'label' => sprintf( /* translators: %s: course title */ __( 'Course: %s', 'ghca-acd' ), $name ), 'clear' => $f['course'], 'remove' => __( 'Remove course filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['status'] ) {
			$statuses = GHCA_ACD_Data_Provider::get_status_options();
			$name     = (string) ( $statuses[ (string) $filters['status'] ] ?? $filters['status'] );
			$chips[]  = array( 'label' => sprintf( /* translators: %s: status */ __( 'Status: %s', 'ghca-acd' ), $name ), 'clear' => $f['status'], 'remove' => __( 'Remove status filter', 'ghca-acd' ) );
		}
		if ( ! empty( $filters['overdue_only'] ) ) {
			$chips[] = array( 'label' => __( 'Overdue only', 'ghca-acd' ), 'clear' => $f['overdue'], 'remove' => __( 'Remove overdue filter', 'ghca-acd' ) );
		}
		if ( '' !== (string) $filters['search'] ) {
			$chips[] = array( 'label' => sprintf( /* translators: %s: search words */ __( 'Search: %s', 'ghca-acd' ), (string) $filters['search'] ), 'clear' => $f['search'], 'remove' => __( 'Remove search', 'ghca-acd' ) );
		}
		return $chips;
	}

	/**
	 * The summary line and filter chips under the filter fields. Rendered on load and returned by the
	 * AJAX refresh, so it always describes the rows on screen.
	 *
	 * @param array<string,mixed> $filters
	 * @param array{total:int,from:int,to:int}  $meta
	 */
	public static function chips_html( array $filters, array $meta, string $account_status ): string {
		$inactive = 'inactive' === $account_status;
		$html     = '<span class="gh-small gh-muted">' . esc_html( self::summary( $meta, $inactive ) ) . ' · ' . esc_html__( 'filters apply as you type', 'ghca-acd' ) . '</span>';
		$chips    = self::active_filters( $filters, $account_status );
		foreach ( $chips as $chip ) {
			$html .= '<span class="gh-fchip">' . esc_html( $chip['label'] ) . '<button type="button" data-ghca-filter-clear="' . esc_attr( $chip['clear'] ) . '" aria-label="' . esc_attr( $chip['remove'] ) . '">' . self::icon( 'x', 'sm' ) . '</button></span>';
		}
		if ( count( $chips ) > 1 ) {
			$html .= '<button class="gh-btn gh-btn--ghost gh-btn--sm" type="button" data-ghca-filter-clear="all">' . esc_html__( 'Clear all', 'ghca-acd' ) . '</button>';
		}
		return $html;
	}

	/** @param array{total:int,from:int,to:int} $meta */
	public static function summary( array $meta, bool $inactive ): string {
		if ( $meta['total'] <= 0 ) {
			return $inactive ? __( 'No inactive employees to show', 'ghca-acd' ) : __( 'No employees to show', 'ghca-acd' );
		}
		/* translators: 1: first row number, 2: last row number, 3: total rows */
		return sprintf( __( 'Showing %1$d–%2$d of %3$d', 'ghca-acd' ), $meta['from'], $meta['to'], $meta['total'] );
	}

	public static function sort_th( string $key, string $label, string $orderby, string $order, string $help = '' ): string {
		$active = $orderby === $key;
		$next   = $active && 'asc' === $order ? 'desc' : 'asc';
		$aria   = $active ? ( 'asc' === $order ? 'ascending' : 'descending' ) : 'none';
		$icon   = $active ? ( 'asc' === $order ? 'sort-down' : 'chev-up' ) : 'sort';
		$title  = '' !== $help ? ' title="' . esc_attr( $help ) . '"' : '';
		return '<th scope="col" data-ghca-sort="' . esc_attr( $key ) . '" data-ghca-sort-order="' . esc_attr( $next ) . '" aria-sort="' . esc_attr( $aria ) . '"' . $title . '><button type="button">' . esc_html( $label ) . ' ' . self::icon( $icon, 'sm' ) . '</button></th>';
	}

	/**
	 * One table row as board markup.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function row_html( array $row, bool $inactive ): string {
		$slug   = (string) $row['status_slug'];
		$tone   = self::status_tone( $slug );
		$risk   = self::is_overdue_slug( $slug );
		$pct    = max( 0, min( 100, (int) $row['progress_pct'] ) );
		$bar    = 'gh-bar' . ( $risk ? ' gh-bar--danger' : ( 100 === $pct ? ' gh-bar--success' : '' ) );
		$id     = (int) $row['user_id'];
		$name   = (string) $row['name'];
		$report = GHCA_ACD_Data_Provider::get_user_report_url( $id );
		$note   = self::due_note( (int) ( $row['due_timestamp'] ?? 0 ), $slug );

		$h  = '<tr' . ( $risk ? ' class="is-risk"' : '' ) . ' data-ghca-row="' . esc_attr( (string) $id ) . '">';
		if ( ! $inactive && self::selectable() ) {
			$h .= GHCA_ACD_Redesign_Bulk::row_cell( $id, $name );
		}
		$h .= '<td><div class="gh-person"><span class="gh-avatar" aria-hidden="true">' . esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( $name ) ) . '</span><div>';
		$h .= '<a class="gh-person-n" href="' . esc_url( $report ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</a>';
		$h .= '<a class="gh-person-s" href="' . esc_url( 'mailto:' . $row['email'] ) . '">' . esc_html( (string) $row['email'] ) . '</a>';
		if ( $inactive ) {
			$h .= '<span class="gh-st gh-st--suspended gh-person-chip">' . esc_html__( 'Suspended', 'ghca-acd' ) . '</span>';
		}
		$h .= '</div></div></td>';
		$h .= '<td class="gh-secondary">' . esc_html( (string) $row['group'] ) . '</td>';
		$h .= '<td><div class="gh-prog"><div class="' . esc_attr( $bar ) . '" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Compliance progress', 'ghca-acd' ) . '"><span style="width: ' . esc_attr( (string) $pct ) . '%;"></span></div>';
		$h .= '<span class="gh-num gh-prog-n" title="' . esc_attr( (string) $row['progress_label'] ) . '">' . esc_html( (string) $row['completed_label'] ) . '</span></div></td>';
		$h .= '<td class="gh-num gh-nowrap">' . esc_html( (string) $row['due_date_label'] );
		if ( $note ) {
			$h .= '<div class="gh-small gh-due gh-due--' . esc_attr( $note['tone'] ) . '">' . esc_html( $note['text'] ) . '</div>';
		}
		$h .= '</td>';
		$h .= '<td class="gh-secondary">' . esc_html( (string) $row['certificate_label'] ) . '</td>';
		$h .= '<td class="gh-num gh-secondary gh-nowrap">' . esc_html( (string) $row['last_activity_label'] ) . '</td>';
		$h .= '<td><span class="gh-st gh-st--' . esc_attr( $tone ) . '">' . esc_html( (string) $row['status_label'] ) . '</span></td>';
		// A link, as on the board: it goes to the user report without scripts and opens the drawer with them.
		$h .= '<td class="c-act"><a class="gh-btn gh-btn--secondary gh-btn--sm" href="' . esc_url( $report ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'View %s', 'ghca-acd' ), $name ) ) . '">' . esc_html__( 'View', 'ghca-acd' ) . '</a></td>';
		return $h . '</tr>';
	}

	/**
	 * Narrow screens: the same rows as cards (EmployeeCard). Hidden above 720px by CSS; the table is
	 * hidden below it, so a screen reader meets one list, not two.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function card_html( array $row, bool $inactive ): string {
		$slug = (string) $row['status_slug'];
		$risk = self::is_overdue_slug( $slug );
		$pct  = max( 0, min( 100, (int) $row['progress_pct'] ) );
		$bar  = 'gh-bar gh-bar--sm' . ( $risk ? ' gh-bar--danger' : ( 100 === $pct ? ' gh-bar--success' : '' ) );
		$id   = (int) $row['user_id'];
		$name = (string) $row['name'];
		$note = self::due_note( (int) ( $row['due_timestamp'] ?? 0 ), $slug );

		$h  = '<article class="gh-mcard' . ( $risk ? ' is-risk' : '' ) . '" data-ghca-row="' . esc_attr( (string) $id ) . '"><div class="gh-mcard-top">' . ( ! $inactive && self::selectable() ? GHCA_ACD_Redesign_Bulk::card_check( $id, $name ) : '' ) . '<span class="gh-avatar" aria-hidden="true">' . esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( $name ) ) . '</span>';
		$h .= '<div class="gh-mcard-id"><a class="gh-person-n" href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $id ) ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</a>';
		$h .= '<div class="gh-person-s">' . esc_html( (string) $row['group'] ) . '</div></div>';
		$h .= '<span class="gh-st gh-st--' . esc_attr( self::status_tone( $slug ) ) . '">' . esc_html( (string) $row['status_label'] ) . '</span></div>';
		$h .= '<div class="gh-prog"><div class="' . esc_attr( $bar ) . '" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Compliance progress', 'ghca-acd' ) . '"><span style="width: ' . esc_attr( (string) $pct ) . '%;"></span></div><span class="gh-small gh-num">' . esc_html( (string) $row['completed_label'] ) . '</span></div>';
		$h .= '<dl class="gh-kv"><dt>' . esc_html__( 'Next due', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $row['due_date_label'] ) . ( $note ? ' · ' . esc_html( $note['text'] ) : '' ) . '</dd>';
		// The board's card shows two details. Certificate status stays in the table and in the drawer.
		$h .= '<dt>' . esc_html__( 'Last activity', 'ghca-acd' ) . '</dt><dd>' . esc_html( (string) $row['last_activity_label'] ) . '</dd></dl>';
		$h .= '<div class="gh-mcard-act"><a class="gh-btn gh-btn--secondary" href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $id ) ) . '" data-ghca-employee-drawer="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: employee name */ __( 'View %s', 'ghca-acd' ), $name ) ) . '">' . esc_html__( 'View', 'ghca-acd' ) . '</a></div>';
		return $h . '</article>';
	}

	/**
	 * @param array<string,mixed> $paged   Result of GHCA_ACD_Table_UI::paginate().
	 */
	public static function pager_html( array $paged, string $page_field, string $per_name, string $form_id ): string {
		if ( $paged['total'] <= 0 ) {
			return '';
		}
		$page  = (int) $paged['page'];
		$pages = (int) $paged['total_pages'];

		$h  = '<div class="gh-pager"><span>' . esc_html( sprintf( /* translators: 1: first row number, 2: last row number, 3: total rows */ __( '%1$d–%2$d of %3$d', 'ghca-acd' ), (int) $paged['from'], (int) $paged['to'], (int) $paged['total'] ) ) . '</span>';
		$h .= '<div class="gh-pager-ctl"><label for="' . esc_attr( $form_id ) . '-per" class="gh-small">' . esc_html__( 'Per page', 'ghca-acd' ) . '</label>';
		$h .= '<select id="' . esc_attr( $form_id ) . '-per" class="gh-select gh-pager-per" name="' . esc_attr( $per_name ) . '" form="' . esc_attr( $form_id ) . '" data-ghca-per-page>';
		foreach ( GHCA_ACD_Table_UI::get_per_page_options() as $value ) {
			$h .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (int) $paged['per_page'], $value, false ) . '>' . esc_html( (string) $value ) . '</option>';
		}
		$h .= '</select>';
		$h .= '<nav class="gh-pager-btns" aria-label="' . esc_attr__( 'Pagination', 'ghca-acd' ) . '">';
		$h .= self::page_btn( max( 1, $page - 1 ), $page_field, self::icon( 'chev-left', 'sm' ), __( 'Previous page', 'ghca-acd' ), $page <= 1 );
		foreach ( self::page_window( $page, $pages ) as $p ) {
			if ( 0 === $p ) {
				$h .= '<span class="gh-pager-gap" aria-hidden="true">…</span>';
				continue;
			}
			$h .= self::page_btn( $p, $page_field, esc_html( (string) $p ), sprintf( /* translators: %d: page number */ __( 'Page %d', 'ghca-acd' ), $p ), false, $p === $page );
		}
		$h .= self::page_btn( min( $pages, $page + 1 ), $page_field, self::icon( 'chev-right', 'sm' ), __( 'Next page', 'ghca-acd' ), $page >= $pages );
		return $h . '</nav></div></div>';
	}

	private static function page_btn( int $target, string $field, string $inner, string $label, bool $disabled, bool $current = false ): string {
		return '<button type="button" data-ghca-page="' . esc_attr( (string) $target ) . '" data-ghca-page-field="' . esc_attr( $field ) . '" aria-label="' . esc_attr( $label ) . '"' . ( $current ? ' aria-current="page"' : '' ) . ( $disabled ? ' disabled' : '' ) . '>' . $inner . '</button>';
	}

	/**
	 * Page numbers to show: first, last, and two either side of the current page, with 0 marking a gap.
	 *
	 * @return array<int,int>
	 */
	public static function page_window( int $page, int $pages ): array {
		$show = array_unique( array_filter( array( 1, $pages, $page - 2, $page - 1, $page, $page + 1, $page + 2 ), static function ( int $p ) use ( $pages ): bool {
			return $p >= 1 && $p <= $pages;
		} ) );
		sort( $show );
		$out  = array();
		$prev = 0;
		foreach ( $show as $p ) {
			if ( $prev && $p - $prev > 1 ) {
				$out[] = 0;
			}
			$out[] = $p;
			$prev  = $p;
		}
		return $out;
	}

	/**
	 * The mount's content: filtered-empty or all-clear state, or the table (and phone cards) with the pager.
	 *
	 * @param array<string,mixed> $filters
	 * @param array<string,mixed> $paged
	 */
	private static function body_html( array $filters, array $paged, string $account_status, string $form_id ): string {
		$inactive = 'inactive' === $account_status;
		$f        = self::fields( $account_status );
		$rows     = $paged['rows'];

		if ( empty( $rows ) ) {
			$filtered = count( self::active_filters( $filters, $account_status ) ) > 0;
			$h        = '<div class="gh-card gh-empty"><span class="gh-empty-ico">' . self::icon( $filtered ? 'search' : 'users', 'xl' ) . '</span>';
			if ( $filtered ) {
				$title = $inactive ? __( 'No inactive employees match these filters', 'ghca-acd' ) : __( 'No employees match these filters', 'ghca-acd' );
				$h    .= '<strong class="gh-h4">' . esc_html( $title ) . '</strong><span class="gh-secondary">' . esc_html__( 'Try different filters, or clear them to see everyone in your view.', 'ghca-acd' ) . '</span>';
				$h    .= '<button class="gh-btn gh-btn--secondary" type="button" data-ghca-filter-clear="all">' . esc_html__( 'Clear filters', 'ghca-acd' ) . '</button>';
			} else {
				$title = $inactive ? __( 'There are no inactive employees in your view', 'ghca-acd' ) : __( 'There are no active employees in your view', 'ghca-acd' );
				$h    .= '<strong class="gh-h4">' . esc_html( $title ) . '</strong>';
			}
			return $h . '</div>';
		}

		$orderby = (string) ( $filters['orderby'] ?? '' );
		$order   = (string) ( $filters['order'] ?? 'asc' );
		$h       = '<div class="gh-table-wrap gh-table-wrap--tall gh-tbl" tabindex="0" role="group" aria-label="' . esc_attr( $inactive ? __( 'Inactive employees table', 'ghca-acd' ) : __( 'Employees table', 'ghca-acd' ) ) . '"><table class="gh-table"><thead><tr>';
		if ( ! $inactive && self::selectable() ) {
			$h .= GHCA_ACD_Redesign_Bulk::head_cell();
		}
		$h      .= self::sort_th( 'name', __( 'Employee', 'ghca-acd' ), $orderby, $order );
		$h      .= self::sort_th( 'group', __( 'Group', 'ghca-acd' ), $orderby, $order );
		$h      .= self::sort_th( 'progress_pct', __( 'Progress', 'ghca-acd' ), $orderby, $order, __( 'Completed / required courses', 'ghca-acd' ) );
		$h      .= self::sort_th( 'due_timestamp', __( 'Next due', 'ghca-acd' ), $orderby, $order );
		$h      .= '<th scope="col">' . esc_html__( 'Certificate', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Last activity', 'ghca-acd' ) . '</th>';
		$h      .= self::sort_th( 'status_slug', __( 'Status', 'ghca-acd' ), $orderby, $order, __( 'LearnDash enrolled courses only; orientation status is on the audit packet.', 'ghca-acd' ) );
		$h      .= '<th scope="col" class="c-act"><span class="gh-sr">' . esc_html__( 'Actions', 'ghca-acd' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$h .= self::row_html( $row, $inactive );
		}
		$h .= '</tbody></table></div>';

		$h .= '<div class="gh-cards" role="list" aria-label="' . esc_attr( $inactive ? __( 'Inactive employees', 'ghca-acd' ) : __( 'Employees', 'ghca-acd' ) ) . '">';
		foreach ( $rows as $row ) {
			$h .= '<div role="listitem">' . self::card_html( $row, $inactive ) . '</div>';
		}
		$h .= '</div>';
		return $h . self::pager_html( $paged, $f['page'], $f['per_page'], $form_id );
	}

	/**
	 * Table, cards and pager for one request. Shared by the first render and the AJAX refresh.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{html:string,chips:string,summary:string}
	 */
	public static function employee_table_parts( array $filters, string $account_status = 'active' ): array {
		$inactive = 'inactive' === $account_status;
		$all_rows = GHCA_ACD_Data_Provider::get_employee_table_rows( $filters, $account_status );
		$paged    = GHCA_ACD_Table_UI::paginate( $all_rows, (int) $filters['page'], (int) $filters['per_page'] );
		$form_id  = 'ghca-filters-' . self::fields( $account_status )['table'];
		return array(
			'html'    => self::body_html( $filters, $paged, $account_status, $form_id ),
			'chips'   => self::chips_html( $filters, $paged, $account_status ),
			'summary' => self::summary( $paged, $inactive ),
		);
	}

	/**
	 * A <select> field in the filter bar.
	 *
	 * @param array<int|string,string> $options
	 */
	public static function select_field( string $id, string $label, string $name, string $current, array $options, string $all_label ): string {
		$h  = '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" class="gh-select" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all_label ) . '</option>';
		foreach ( $options as $value => $text ) {
			$h .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( (string) $text ) . '</option>';
		}
		return $h . '</select></div>';
	}

	/**
	 * The Employees or Inactive tab: toolbar, filters, table. Returns '' without dashboard access, and
	 * for Inactive without the manage-users capability, exactly as the legacy renderer does.
	 *
	 * @param array<string,mixed> $atts
	 */
	public static function render_employees( $atts = array(), string $account_status = 'active' ): string {
		$inactive = 'inactive' === $account_status;
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() || ( $inactive && ! GHCA_ACD_Roles::user_can_manage_users() ) ) {
			return '';
		}
		unset( $atts );

		$filters = GHCA_ACD_Data_Provider::get_employee_filters( $account_status );
		$f       = self::fields( $account_status );
		$parts   = self::employee_table_parts( $filters, $account_status );
		$form_id = 'ghca-filters-' . $f['table'];
		$export  = function_exists( 'wp_nonce_url' ) ? wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' ) : '';

		$h  = '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html( $inactive ? __( 'Inactive employees', 'ghca-acd' ) : __( 'Employee compliance', 'ghca-acd' ) ) . '</h2>';
		$h .= '<span class="gh-small gh-muted">' . esc_html( $inactive
			? __( 'Suspended employees are excluded from active dashboard totals but remain available here for authorized review and audit packets.', 'ghca-acd' )
			: __( 'Active employees in your view. Select a column heading to sort.', 'ghca-acd' ) ) . '</span></div>';
		if ( ! $inactive && '' !== $export ) {
			// The export handler writes every active employee in scope; it does not read these filters.
			$h .= '<div class="gh-actions"><a class="gh-btn gh-btn--secondary gh-btn--native" href="' . esc_url( $export ) . '" title="' . esc_attr__( 'Exports every active employee in your view. Filters on this page are not applied.', 'ghca-acd' ) . '">' . self::icon( 'download' ) . esc_html__( 'Export all (CSV)', 'ghca-acd' ) . '</a></div>';
		}
		$h .= '</div>';

		if ( $inactive ) {
			$h .= '<div class="gh-alert gh-alert--info" role="note">' . self::icon( 'info' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Not counted in dashboard totals', 'ghca-acd' ) . '</span><p>' . esc_html__( 'These employees are excluded from active totals, group completion and overdue counts.', 'ghca-acd' ) . '</p></div></div>';
		}

		$h .= '<div class="gh-card gh-tcard">';
		$h .= '<form class="gh-filters" id="' . esc_attr( $form_id ) . '" method="get" role="search" aria-label="' . esc_attr( $inactive ? __( 'Filter inactive employees', 'ghca-acd' ) : __( 'Filter employees', 'ghca-acd' ) ) . '" data-ghca-filter-form data-ghca-ui="board" data-ghca-debounce="300" data-ghca-table="' . esc_attr( $f['table'] ) . '">';
		$h .= '<input type="hidden" name="' . esc_attr( $f['page'] ) . '" value="' . esc_attr( (string) $filters['page'] ) . '" data-ghca-page-input />';
		$h .= '<input type="hidden" name="' . esc_attr( $f['orderby'] ) . '" value="' . esc_attr( (string) ( $filters['orderby'] ?? '' ) ) . '" />';
		$h .= '<input type="hidden" name="' . esc_attr( $f['order'] ) . '" value="' . esc_attr( (string) ( $filters['order'] ?? 'asc' ) ) . '" />';
		$h .= '<div class="gh-field gh-field--search"><label class="gh-label" for="' . esc_attr( $form_id ) . '-q">' . esc_html__( 'Search', 'ghca-acd' ) . '</label><div class="gh-input-wrap">' . self::icon( 'search' ) . '<input id="' . esc_attr( $form_id ) . '-q" class="gh-input" type="search" name="' . esc_attr( $f['search'] ) . '" value="' . esc_attr( (string) $filters['search'] ) . '" placeholder="' . esc_attr__( 'Name, email or group', 'ghca-acd' ) . '" autocomplete="off" /></div></div>';
		$h .= self::select_field( $form_id . '-g', __( 'Group', 'ghca-acd' ), $f['group'], (string) $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'All groups', 'ghca-acd' ) );
		$h .= self::select_field( $form_id . '-c', __( 'Course', 'ghca-acd' ), $f['course'], (string) $filters['course'], GHCA_ACD_Data_Provider::get_course_options( $account_status ), __( 'All courses', 'ghca-acd' ) );
		$h .= self::select_field( $form_id . '-s', __( 'Status', 'ghca-acd' ), $f['status'], (string) $filters['status'], GHCA_ACD_Data_Provider::get_status_options(), __( 'All statuses', 'ghca-acd' ) );
		$h .= '<label class="gh-check gh-check--filter"><input type="checkbox" name="' . esc_attr( $f['overdue'] ) . '" value="1"' . checked( ! empty( $filters['overdue_only'] ), true, false ) . ' /> ' . esc_html__( 'Overdue only', 'ghca-acd' ) . '</label>';
		// No submit button: the search box is the only field that blocks implicit submission, so Enter submits, and every other control applies on change.
		$h .= '</form>';
		$h .= '<div class="gh-chiprow" data-ghca-chips="' . esc_attr( $f['table'] ) . '">' . $parts['chips'] . '</div>';
		$h .= '</div>';

		$h .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-table-status="' . esc_attr( $f['table'] ) . '"></p>';
		// Outside the refreshed table, so a selection and its bar survive paging and filtering.
		if ( ! $inactive && self::selectable() ) {
			$h .= GHCA_ACD_Redesign_Bulk::bar_html();
		}
		$h .= '<div class="gh-tmount" data-ghca-table data-ghca-table-id="' . esc_attr( $f['table'] ) . '"' . ( ! $inactive && self::selectable() ? ' data-ghca-selectable' : '' ) . '>' . $parts['html'] . '</div>';
		return $h;
	}
}
