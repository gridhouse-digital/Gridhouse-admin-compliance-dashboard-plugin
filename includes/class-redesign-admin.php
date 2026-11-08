<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redesigned Reports and Audit data tabs (Admin-Reports and Admin-AuditData boards). Rendered only inside
 * the redesign; the legacy renderers (GHCA_ACD_Shortcodes::render_reports(), GHCA_ACD_Audit_UI::render())
 * are untouched and still serve the dashboard when the redesign is off.
 *
 * Nothing here computes a new figure. Reports shows the same four destinations with counts the dashboard
 * aggregate already holds. Audit data shows and saves the same three per-employee fields through the same
 * AJAX action, nonce and capability checks; the CSV exports and packet buttons use the same URLs and data
 * attributes as before.
 */
final class GHCA_ACD_Redesign_Admin {

	/** The legacy audit tab's MM/DD/YYYY shape, checked before sending; the server check is unchanged. */
	const MDY_PATTERN = '(0[1-9]|1[0-2])/(0[1-9]|[12][0-9]|3[01])/[0-9]{4}';

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	private static function alert( string $tone, string $icon, string $title, string $body, string $role = '' ): string {
		$h = '<div class="gh-alert gh-alert--' . esc_attr( $tone ) . '"' . ( '' !== $role ? ' role="' . esc_attr( $role ) . '"' : '' ) . '>' . self::icon( $icon ) . '<div class="gh-alert-body">';
		if ( '' !== $title ) {
			$h .= '<span class="gh-alert-t">' . esc_html( $title ) . '</span>';
		}
		return $h . ( '' !== $body ? '<p>' . esc_html( $body ) . '</p>' : '' ) . '</div></div>';
	}

	private static function empty_state( string $icon, string $title, string $text ): string {
		return '<div class="gh-card gh-empty"><span class="gh-empty-ico">' . self::icon( $icon, 'xl' ) . '</span><strong class="gh-h4">' . esc_html( $title ) . '</strong><span class="gh-secondary">' . esc_html( $text ) . '</span></div>';
	}

	/** Shown in place of a tab's content when the signed-in user may not see it. */
	public static function denied(): string {
		return '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'Not available', 'ghca-acd' ) . '</h2></div></div>' . self::alert( 'neutral', 'lock', __( 'You do not have access to this section.', 'ghca-acd' ), __( 'Ask an administrator if you need it.', 'ghca-acd' ), 'status' );
	}

	/* ---------------------------------------------------------------------
	 * Reports
	 * ------------------------------------------------------------------- */

	/**
	 * The four report destinations the legacy tab offers, with the same URLs. Meta lines use counts the
	 * dashboard aggregate already holds; nothing is computed for this tab.
	 *
	 * @param array<string,mixed> $aggregate GHCA_ACD_Data_Provider::get_aggregate().
	 * @return array<int,array<string,string>>
	 */
	public static function report_cards( array $aggregate ): array {
		$total    = (int) ( $aggregate['total_employees'] ?? 0 );
		$issued   = (int) ( $aggregate['certificates_issued'] ?? 0 );
		$missing  = (int) ( $aggregate['certificates_missing'] ?? 0 );
		$inactive = (int) ( $aggregate['total_inactive_employees'] ?? 0 );
		/* translators: %d: employees */
		$people = sprintf( _n( '%d employee in your view', '%d employees in your view', $total, 'ghca-acd' ), $total );
		/* translators: 1: certificates issued, 2: certificates missing */
		$certs = sprintf( __( '%1$d issued · %2$d missing', 'ghca-acd' ), $issued, $missing );
		/* translators: 1: active employees, 2: inactive employees */
		$users = GHCA_ACD_Roles::user_can_manage_users() ? sprintf( __( '%1$d active · %2$d inactive', 'ghca-acd' ), $total, $inactive ) : $people;

		return array(
			array(
				'key'   => 'export',
				'icon'  => 'download',
				'title' => __( 'Compliance export', 'ghca-acd' ),
				'desc'  => __( 'Every employee’s course status, progress and due dates in one CSV file.', 'ghca-acd' ),
				'meta'  => $people,
				'cta'   => __( 'Download CSV', 'ghca-acd' ),
				'cicon' => 'download',
				'btn'   => 'gh-btn--primary',
				'url'   => wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' ),
			),
			array(
				'key'   => 'user-report',
				'icon'  => 'file',
				'title' => __( 'User training report', 'ghca-acd' ),
				'desc'  => __( 'Per-employee training detail, quiz status and certificate history.', 'ghca-acd' ),
				'meta'  => __( 'Opens per person', 'ghca-acd' ),
				'cta'   => __( 'Open report', 'ghca-acd' ),
				'cicon' => 'arrow-right',
				'btn'   => 'gh-btn--secondary',
				'url'   => GHCA_ACD_Data_Provider::get_page_url( 'user-report', '/user-report/' ),
			),
			array(
				'key'   => 'certificates',
				'icon'  => 'award',
				'title' => __( 'Certificate records', 'ghca-acd' ),
				'desc'  => __( 'Issued credentials and downloadable certificates across your groups.', 'ghca-acd' ),
				'meta'  => $certs,
				'cta'   => __( 'View certificates', 'ghca-acd' ),
				'cicon' => 'arrow-right',
				'btn'   => 'gh-btn--secondary',
				// Slice 9: the dashboard's own Certificates tab, never /cert-download/.
				'url'   => GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-tab-certificates',
				'jump'  => 'ghca-tab-certificates',
			),
			array(
				'key'   => 'users',
				'icon'  => 'users',
				'title' => __( 'Manage users', 'ghca-acd' ),
				'desc'  => __( 'Add, remove or change user enrolment and group assignments.', 'ghca-acd' ),
				'meta'  => $users,
				'cta'   => __( 'Manage', 'ghca-acd' ),
				'cicon' => 'arrow-right',
				'btn'   => 'gh-btn--secondary',
				'url'   => GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' ),
			),
		);
	}

	/** @param array<string,mixed> $atts */
	public static function render_reports( $atts = array() ): string {
		unset( $atts );
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
			return self::denied();
		}
		$inline = class_exists( 'GHCA_ACD_Redesign_Report' ) && GHCA_ACD_Redesign_Report::available();
		$h  = '<div class="gh-rpt-home" data-ghca-report-home>';
		$h .= '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'Reports & exports', 'ghca-acd' ) . '</h2><span class="gh-small gh-muted">' . esc_html__( 'Exports cover the employees in your current view.', 'ghca-acd' ) . '</span></div></div>';
		$h .= '<ul class="gh-grid gh-grid--4 gh-rpt" role="list">';
		foreach ( self::report_cards( GHCA_ACD_Data_Provider::get_aggregate() ) as $card ) {
			$id = 'gh-rpt-' . $card['key'];
			$h .= '<li class="gh-card gh-rpt-card" aria-labelledby="' . esc_attr( $id ) . '"><span class="gh-stat-ico gh-rpt-ico">' . self::icon( $card['icon'], 'lg' ) . '</span>';
			$h .= '<h3 class="gh-h4" id="' . esc_attr( $id ) . '">' . esc_html( $card['title'] ) . '</h3><p class="gh-p gh-secondary gh-rpt-desc">' . esc_html( $card['desc'] ) . '</p>';
			$h .= '<span class="gh-small gh-muted">' . esc_html( $card['meta'] ) . '</span>';
			// The user report opens inside this tab (slice 8); its link stays as the no-script fallback.
			$open = $inline && 'user-report' === $card['key'] ? ' data-ghca-report-open' : '';
			// A general entry point: the certificates list without an employee filter left over from earlier.
			$open .= ! empty( $card['jump'] ) ? ' data-ghca-tab-jump="' . esc_attr( (string) $card['jump'] ) . '" data-ghca-cert-user=""' : '';
			$h .= '<a class="gh-btn ' . esc_attr( $card['btn'] ) . '" href="' . esc_url( $card['url'] ) . '"' . $open . '>' . self::icon( $card['cicon'] ) . esc_html( $card['cta'] ) . '<span class="gh-sr">: ' . esc_html( $card['title'] ) . '</span></a></li>';
		}
		$h .= '</ul></div>';
		return $h . ( $inline ? GHCA_ACD_Redesign_Report::view_html() : '' );
	}

	/* ---------------------------------------------------------------------
	 * Audit data
	 * ------------------------------------------------------------------- */

	/** @return array<int,int> The same employees, in the same order, as the legacy audit tab. */
	private static function audit_employee_ids(): array {
		return GHCA_ACD_Roles::user_can_manage_users()
			? GHCA_ACD_Data_Provider::get_employee_user_ids()
			: GHCA_ACD_Data_Provider::get_active_employee_user_ids();
	}

	private static function role_label( $user ): string {
		$role = __( 'Staff', 'ghca-acd' );
		if ( ! empty( $user->roles ) ) {
			global $wp_roles;
			$slug = (string) $user->roles[0];
			$role = isset( $wp_roles->roles[ $slug ] ) ? (string) $wp_roles->roles[ $slug ]['name'] : $slug;
		}
		return $role;
	}

	/**
	 * One row of display data per employee: the stored values the legacy tab reads, unchanged.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function audit_rows(): array {
		$rows = array();
		foreach ( self::audit_employee_ids() as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			$doh_ts = GHCA_ACD_Employment_Record::timestamp( (int) $user_id );
			$rows[] = array(
				'id'            => (int) $user_id,
				'name'          => GHCA_ACD_Data_Provider::get_user_full_name( (int) $user_id, $user ),
				'role'          => self::role_label( $user ),
				'start'         => $doh_ts ? wp_date( 'm/d/Y', $doh_ts ) : '',
				'first_service' => (string) get_user_meta( (int) $user_id, 'ghca_first_service_date', true ),
				'worked_alone'  => (string) get_user_meta( (int) $user_id, 'ghca_worked_alone_date', true ),
				'exclude'       => '1' === (string) get_user_meta( (int) $user_id, 'ghca_audit_exclude', true ),
			);
		}
		return $rows;
	}

	private static function date_input( int $id, string $field, string $label, string $value, string $help_id ): string {
		$input_id = 'ghca-aud-' . $field . '-' . $id;
		return '<label class="gh-sr" for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label>'
			. '<input id="' . esc_attr( $input_id ) . '" class="gh-input gh-aud-date" type="text" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="MM/DD/YYYY" pattern="' . esc_attr( self::MDY_PATTERN ) . '" value="' . esc_attr( $value ) . '" aria-describedby="' . esc_attr( $help_id ) . '" data-ghca-audit-field="' . esc_attr( $field ) . '" />';
	}

	public static function render_audit(): string {
		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			return self::denied();
		}
		$can_edit = GHCA_ACD_Roles::user_can_edit_records();
		$can_docs = GHCA_ACD_Roles::user_can_view_employee_documents();
		$annual   = wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=annual' ), 'ghca_acd_audit_export_csv' );
		$orient   = wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=orientation' ), 'ghca_acd_audit_export_csv' );
		$rows     = self::audit_rows();

		$h  = '<div class="gh-toolbar"><div><h2 class="gh-h3">' . esc_html__( 'ODP audit data', 'ghca-acd' ) . '</h2><span class="gh-small gh-muted">' . esc_html__( 'Dates entered here are included when you download the audit CSVs.', 'ghca-acd' ) . '</span></div>';
		$h .= '<div class="gh-actions"><a class="gh-btn gh-btn--secondary" href="' . esc_url( $annual ) . '">' . self::icon( 'download' ) . esc_html__( 'ODP Annual CSV', 'ghca-acd' ) . '</a>';
		$h .= '<a class="gh-btn gh-btn--primary" href="' . esc_url( $orient ) . '">' . self::icon( 'download' ) . esc_html__( 'ODP Orientation CSV', 'ghca-acd' ) . '</a></div></div>';

		// What the CSV's existing "completed within 30 days" column means (GHCA_ACD_Audit_Calculator).
		$h .= '<div class="gh-alert gh-alert--neutral">' . self::icon( 'info' ) . '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'How the CSV’s 30-day orientation verdict is worked out', 'ghca-acd' ) . '</span><p>' . esc_html__( 'Days = orientation completion date minus the verified employment start date. The account registration date is never used. 30 days or fewer is on time. A worked-alone date must fall on or after orientation completion.', 'ghca-acd' ) . '</p></div></div>';

		if ( ! $can_edit ) {
			$h .= self::alert( 'neutral', 'lock', '', __( 'You can view these dates. Changing them needs permission to edit employee records.', 'ghca-acd' ), 'note' );
		}
		if ( ! $rows ) {
			return $h . self::empty_state( 'users', __( 'No employees in your view', 'ghca-acd' ), __( 'Employees appear here once they are in one of your groups.', 'ghca-acd' ) );
		}

		$help = 'ghca-aud-format';
		$h   .= '<p class="gh-small gh-muted" id="' . esc_attr( $help ) . '">' . esc_html__( 'Dates use MM/DD/YYYY. Leave a date empty to clear it.', 'ghca-acd' ) . '</p>';
		$h   .= '<div class="gh-table-wrap gh-aud" tabindex="0" role="group" aria-label="' . esc_attr__( 'Audit dates table', 'ghca-acd' ) . '"' . ( $can_edit ? ' data-ghca-audit-nonce="' . esc_attr( wp_create_nonce( 'ghca_acd_audit_save' ) ) . '"' : '' ) . '><table class="gh-table gh-table--audit"><thead><tr>';
		$h   .= '<th scope="col">' . esc_html__( 'Employee', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Verified start', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'First service', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Worked alone', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Leave out of CSVs', 'ghca-acd' ) . '</th>';
		if ( $can_docs ) {
			$h .= '<th scope="col">' . esc_html__( 'Packets', 'ghca-acd' ) . '</th>';
		}
		if ( $can_edit ) {
			$h .= '<th scope="col" class="c-act"><span class="gh-sr">' . esc_html__( 'Save', 'ghca-acd' ) . '</span></th>';
		}
		$h .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$name  = (string) $row['name'];
			$start = '' !== $row['start']
				? '<span class="gh-num">' . esc_html( (string) $row['start'] ) . '</span>'
				: '<span class="gh-st gh-st--review">' . esc_html__( 'Not verified', 'ghca-acd' ) . '</span>';
			$h .= '<tr data-ghca-audit-row="' . esc_attr( (string) $id ) . '">';
			$h .= '<td data-label=""><div class="gh-person"><span class="gh-avatar" aria-hidden="true">' . esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( $name ) ) . '</span><div><span class="gh-person-n">' . esc_html( $name ) . '</span><span class="gh-person-s">' . esc_html( (string) $row['role'] ) . '</span></div></div></td>';
			$h .= '<td data-label="' . esc_attr__( 'Verified start', 'ghca-acd' ) . '">' . $start . '</td>';
			if ( $can_edit ) {
				/* translators: %s: employee name */
				$h .= '<td data-label="' . esc_attr__( 'First service', 'ghca-acd' ) . '">' . self::date_input( $id, 'first_service', sprintf( __( 'First service date for %s', 'ghca-acd' ), $name ), (string) $row['first_service'], $help ) . '</td>';
				/* translators: %s: employee name */
				$h .= '<td data-label="' . esc_attr__( 'Worked alone', 'ghca-acd' ) . '">' . self::date_input( $id, 'worked_alone', sprintf( __( 'Worked-alone date for %s', 'ghca-acd' ), $name ), (string) $row['worked_alone'], $help ) . '</td>';
				/* translators: %s: employee name */
				$h .= '<td data-label="' . esc_attr__( 'Leave out of CSVs', 'ghca-acd' ) . '"><label class="gh-check"><input type="checkbox" value="1" data-ghca-audit-field="exclude"' . ( $row['exclude'] ? ' checked' : '' ) . ' /><span>' . esc_html__( 'Skip', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html( $name ) . '</span></span></label></td>';
			} else {
				$h .= '<td data-label="' . esc_attr__( 'First service', 'ghca-acd' ) . '" class="gh-num">' . esc_html( '' !== $row['first_service'] ? (string) $row['first_service'] : '—' ) . '</td>';
				$h .= '<td data-label="' . esc_attr__( 'Worked alone', 'ghca-acd' ) . '" class="gh-num">' . esc_html( '' !== $row['worked_alone'] ? (string) $row['worked_alone'] : '—' ) . '</td>';
				$h .= '<td data-label="' . esc_attr__( 'Leave out of CSVs', 'ghca-acd' ) . '">' . esc_html( $row['exclude'] ? __( 'Skipped', 'ghca-acd' ) : __( 'Included', 'ghca-acd' ) ) . '</td>';
			}
			if ( $can_docs ) {
				/* translators: %s: employee name */
				$h .= '<td data-label="' . esc_attr__( 'Packets', 'ghca-acd' ) . '"><div class="gh-aud-pk"><button type="button" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-pdf-packet="' . esc_attr( (string) $id ) . '" data-tracker="orientation">' . self::icon( 'archive', 'sm' ) . esc_html__( 'Orientation', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html( sprintf( __( 'packet for %s', 'ghca-acd' ), $name ) ) . '</span></button>';
				/* translators: %s: employee name */
				$h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-pdf-packet="' . esc_attr( (string) $id ) . '" data-tracker="annual">' . self::icon( 'archive', 'sm' ) . esc_html__( 'Annual', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html( sprintf( __( 'packet for %s', 'ghca-acd' ), $name ) ) . '</span></button></div></td>';
			}
			if ( $can_edit ) {
				/* translators: %s: employee name */
				$h .= '<td class="c-act" data-label=""><div class="gh-aud-save"><button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-audit-save>' . esc_html__( 'Save', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html( sprintf( __( 'dates for %s', 'ghca-acd' ), $name ) ) . '</span></button><span class="gh-small gh-aud-msg" role="status" aria-live="polite" data-ghca-audit-status></span></div></td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}
}
