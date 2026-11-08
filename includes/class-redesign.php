<?php
/**
 * Opt-in admin overview. Legacy shortcode output stays in place until
 * ghca_acd_ui_redesign is saved on. Counts come from the existing aggregate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign {
	/** True once the redesigned dashboard has printed its wrapper, so wp_footer does not print a second drawer. */
	private static $rendered = false;

	/** Whether the board announcement modal was rendered on this page (the legacy footer modal then skips itself). */
	private static $announce_board = false;

	public static function announcements_rendered(): bool {
		return self::$announce_board;
	}

	public static function dashboard_rendered(): bool {
		return self::$rendered;
	}

	/** @return array<int,string> */
	public static function themes(): array {
		return array( 'gridhouse', 'akansoh', 'purple' );
	}

	public static function theme_slug(): string {
		$saved = class_exists( 'GHCA_ACD_Settings' ) && function_exists( 'get_option' ) ? GHCA_ACD_Settings::ui_theme() : 'gridhouse';
		$theme = function_exists( 'apply_filters' ) ? apply_filters( 'ghca_acd_ui_theme', $saved ) : $saved;
		return in_array( $theme, self::themes(), true ) ? (string) $theme : 'gridhouse';
	}

	/**
	 * @param array<string,mixed> $aggregate
	 * @return array{total:int,rate:int,center:string,caption:string,slices:array<int,array<string,mixed>>}
	 */
	public static function health_from_aggregate( array $aggregate ): array {
		$total  = (int) ( $aggregate['total_employees'] ?? 0 );
		$slices = array(
			array( 'key' => 'compliant', 'label' => __( 'Compliant', 'ghca-acd' ), 'count' => (int) ( $aggregate['completed_employees'] ?? 0 ), 'tone' => 'success' ),
			array( 'key' => 'expiring', 'label' => __( 'Expiring soon', 'ghca-acd' ), 'count' => (int) ( $aggregate['expiring_soon_employees'] ?? 0 ), 'tone' => 'warning' ),
			array( 'key' => 'progress', 'label' => __( 'In progress', 'ghca-acd' ), 'count' => (int) ( $aggregate['in_progress_employees'] ?? 0 ), 'tone' => 'info' ),
			array( 'key' => 'overdue', 'label' => __( 'Overdue', 'ghca-acd' ), 'count' => (int) ( $aggregate['overdue_employees'] ?? 0 ), 'tone' => 'danger' ),
		);
		// Not started: the employees the four counts above leave out, counted from the same records and the same
		// status the Employees tab's "Not Started" filter matches. Not part of the compliance rate.
		$not_started = 0;
		foreach ( (array) ( $aggregate['employees'] ?? array() ) as $employee ) {
			if ( 'not_started' === (string) ( $employee['status_slug'] ?? '' ) ) {
				++$not_started;
			}
		}
		$slices[] = array( 'key' => 'not_started', 'label' => __( 'Not started', 'ghca-acd' ), 'count' => $not_started, 'tone' => 'neutral' );
		// Anyone left over has a status none of the rows maps. None does today; if one ever appears they stay visible
		// and counted, under a label that says so, so the rows always add up to the employees in scope.
		$unmapped = $total - array_sum( array_column( $slices, 'count' ) );
		if ( $unmapped > 0 ) {
			$slices[] = array( 'key' => 'unmapped', 'label' => __( 'Status not recognised', 'ghca-acd' ), 'count' => $unmapped, 'tone' => 'muted' );
		}
		$caption = $total < 1 ? __( 'No employees in scope', 'ghca-acd' ) : __( 'Compliant', 'ghca-acd' );
		return array(
			'total'   => $total,
			'rate'    => (int) ( $aggregate['compliance_rate'] ?? 0 ),
			'center'  => $total > 0 ? (int) ( $aggregate['compliance_rate'] ?? 0 ) . '%' : '—',
			'caption' => $caption,
			'slices'  => $slices,
		);
	}

	/**
	 * @param array<string,mixed> $aggregate
	 * @return array<int,array<string,string>>
	 */
	public static function kpis_from_aggregate( array $aggregate, int $tracked_courses ): array {
		$total     = (int) ( $aggregate['total_employees'] ?? 0 );
		$counted   = (int) ( $aggregate['completed_employees'] ?? 0 ) + (int) ( $aggregate['expiring_soon_employees'] ?? 0 );
		$overdue   = (int) ( $aggregate['overdue_employees'] ?? 0 );
		$rate      = (int) ( $aggregate['compliance_rate'] ?? 0 );
		return array(
			array( 'label' => __( 'Employees', 'ghca-acd' ), 'value' => (string) $total, 'sub' => __( 'Active in this view', 'ghca-acd' ), 'tone' => '', 'tab' => 'ghca-tab-employees' ),
			array( 'label' => __( 'Tracked courses', 'ghca-acd' ), 'value' => (string) $tracked_courses, 'sub' => __( 'In this view', 'ghca-acd' ), 'tone' => '', 'tab' => 'ghca-tab-courses' ),
			array( 'label' => __( 'In progress', 'ghca-acd' ), 'value' => (string) (int) ( $aggregate['in_progress_employees'] ?? 0 ), 'sub' => __( 'Includes new hires not started', 'ghca-acd' ), 'tone' => '', 'tab' => 'ghca-tab-employees' ),
			array( 'label' => __( 'Overdue', 'ghca-acd' ), 'value' => (string) $overdue, 'sub' => __( 'Employees · view list', 'ghca-acd' ), 'tone' => 'danger', 'tab' => 'ghca-tab-overdue' ), // Always the board's light-red Overdue tile, whatever the count.
			array( 'label' => __( 'Certificates', 'ghca-acd' ), 'value' => (string) (int) ( $aggregate['certificates_issued'] ?? 0 ), 'sub' => __( 'Issued, active employees', 'ghca-acd' ), 'tone' => '', 'tab' => 'ghca-tab-certificates' ),
			array(
				'label' => __( 'Completion', 'ghca-acd' ),
				'value' => $total > 0 ? $rate . '%' : '—',
				'sub'   => $total > 0 ? sprintf( /* translators: 1: compliant employees, 2: employees in view */ __( '%1$d of %2$d employees', 'ghca-acd' ), $counted, $total ) : __( 'No employees in scope', 'ghca-acd' ),
				'tone'  => '',
				'tab'   => 'ghca-tab-reports',
			),
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	public static function sort_groups( array $rows ): array {
		$rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $rank ): int {
				$left  = $rank[ (string) ( $a['risk'] ?? 'low' ) ] ?? 3;
				$right = $rank[ (string) ( $b['risk'] ?? 'low' ) ] ?? 3;
				if ( $left !== $right ) {
					return $left <=> $right;
				}
				if ( (int) $b['overdue'] !== (int) $a['overdue'] ) {
					return (int) $b['overdue'] <=> (int) $a['overdue'];
				}
				return (int) $a['rate'] <=> (int) $b['rate'];
			}
		);
		return $rows;
	}

	/** @param array<string,mixed> $row @return array{label:string,tone:string} */
	public static function group_chip( array $row ): array {
		$overdue = (int) ( $row['overdue'] ?? 0 );
		$rate    = (int) ( $row['rate'] ?? 0 );
		if ( $overdue > 0 ) {
			return array(
				'label' => sprintf( /* translators: %d: overdue employees in the group */ __( '%d overdue', 'ghca-acd' ), $overdue ),
				'tone'  => 'danger',
			);
		}
		if ( $rate >= 100 ) {
			return array( 'label' => __( 'Complete', 'ghca-acd' ), 'tone' => 'success' );
		}
		if ( $rate >= 85 ) {
			return array( 'label' => __( 'On track', 'ghca-acd' ), 'tone' => 'success' );
		}
		return array( 'label' => __( 'At risk', 'ghca-acd' ), 'tone' => 'warning' );
	}

	/** @param array<string,mixed> $atts */
	public static function render_full_dashboard( $atts = array() ): string {
		$html = GHCA_ACD_Shortcodes::render_login_gate( $atts );
		if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
			return $html;
		}

		$data    = GHCA_ACD_Data_Provider::get_aggregate();
		$courses = GHCA_ACD_Data_Provider::count_unique_tracked_courses( $data['employees'] ?? array() );
		$groups  = self::sort_groups( GHCA_ACD_Scoping::build_group_summary_rows() );
		$total   = (int) ( $data['total_employees'] ?? 0 );
		$visible = class_exists( 'GHCA_ACD_Scoping' ) ? count( GHCA_ACD_Scoping::get_visible_group_ids() ) : count( $groups );
		$scoped  = class_exists( 'GHCA_ACD_Scoping' ) && GHCA_ACD_Scoping::is_scoped_user();
		if ( $visible < 1 ) {
			$scope = __( 'Scope: no assigned groups', 'ghca-acd' );
		} elseif ( $scoped ) {
			$scope = sprintf( /* translators: %d: assigned LearnDash groups */ __( 'Scope: %d assigned groups', 'ghca-acd' ), $visible );
		} else {
			$scope = sprintf( /* translators: %d: visible LearnDash groups */ __( 'Scope: all %d groups', 'ghca-acd' ), $visible );
		}
		$time  = function_exists( 'wp_date' ) ? wp_date( 'g:i A' ) : gmdate( 'g:i A' );
		$as_of = ! empty( $_GET['ghca_acd_synced'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag set by the sync redirect.
			? sprintf( /* translators: %s: local time */ __( 'Synced today, %s', 'ghca-acd' ), $time )
			: sprintf( /* translators: %s: local time */ __( 'As of today, %s', 'ghca-acd' ), $time );

		$bulletins = class_exists( 'GHCA_ODP_Bulletins' ) ? GHCA_ODP_Bulletins::dashboard_fragment( 'admin' ) : array( 'ticker' => '', 'drawer' => '', 'payload' => '' );
		$model     = array(
			'theme'         => self::theme_slug(),
			'brand_style'   => self::brand_style(),
			'scope'         => $scope,
			'scope_note'    => $scoped ? __( 'Group leader view — only your LearnDash groups are shown.', 'ghca-acd' ) : '',
			'as_of'         => $as_of,
			'active'        => sprintf( /* translators: %d: active employees in this view */ __( '%d active employees', 'ghca-acd' ), $total ),
			'actions'       => self::header_actions(),
			'tabs'          => self::tabs( $data ),
			'kpis'          => self::kpis_from_aggregate( $data, $courses ),
			'health'        => self::health_from_aggregate( $data ),
			'groups'        => $groups,
			'certificates'  => array(
				'issued'  => (int) ( $data['certificates_issued'] ?? 0 ),
				'recent'  => (int) ( $data['recently_completed'] ?? 0 ),
				'missing' => (int) ( $data['certificates_missing'] ?? 0 ),
			),
			'bulletin_html' => (string) ( $bulletins['ticker'] ?? '' ),
			'drawer_html'   => (string) ( $bulletins['drawer'] ?? '' ) . (string) ( $bulletins['payload'] ?? '' ),
			'announcements_html' => GHCA_ACD_Announcements::render_announcements_v2(),
			'quick_links'        => self::quick_links(),
			'support_email'      => class_exists( 'GHCA_Dashboard_Branding' ) ? GHCA_Dashboard_Branding::get_support_email() : '',
			'panels'             => self::secondary_panels( $atts, $data ),
			'board_panels'       => array( 'ghca-tab-employees', 'ghca-tab-inactive-employees', 'ghca-tab-overdue', 'ghca-tab-courses', 'ghca-tab-certificates', 'ghca-tab-external-training', 'ghca-tab-audit', 'ghca-tab-reports' ),
			'employee_drawer'    => true,
			'flash'              => GHCA_ACD_Shortcodes::flash_items(),
		);
		return $html . self::render_from_model( $model );
	}

	/**
	 * App bar data shared by the overview and the bulletin screens.
	 *
	 * @return array<string,mixed>
	 */
	public static function chrome( string $context, string $current ): array {
		$org = '';
		if ( class_exists( 'GHCA_Dashboard_Branding' ) ) {
			$org = GHCA_Dashboard_Branding::get_header_org_name();
			if ( '' === $org ) {
				$org = GHCA_Dashboard_Branding::get_org_name();
			}
		}
		if ( '' === $org && function_exists( 'get_bloginfo' ) ) {
			$org = (string) get_bloginfo( 'name' );
		}
		$admin = 'admin' === $context;
		$dash  = class_exists( 'GHCA_ACD_Data_Provider' ) ? GHCA_ACD_Data_Provider::get_admin_dashboard_url() : home_url( '/' );
		$nav   = array();
		if ( $admin ) {
			$on_dash = 'compliance' === $current;
			$nav[]   = array( 'label' => __( 'Compliance', 'ghca-acd' ), 'icon' => 'shield', 'url' => $dash, 'current' => $on_dash ? 'page' : 'true', 'tab' => $on_dash ? 'ghca-tab-overview' : '' );
			$nav[]   = array( 'label' => __( 'Courses', 'ghca-acd' ), 'icon' => 'grad', 'url' => $dash . '#ghca-tab-courses', 'current' => '', 'tab' => $on_dash ? 'ghca-tab-courses' : '' );
			$nav[]   = array( 'label' => __( 'Reports', 'ghca-acd' ), 'icon' => 'chart', 'url' => $dash . '#ghca-tab-reports', 'current' => '', 'tab' => $on_dash ? 'ghca-tab-reports' : '' );
			$users   = self::manage_users_url();
			if ( '' !== $users ) {
				$nav[] = array( 'label' => __( 'Users', 'ghca-acd' ), 'icon' => 'users', 'url' => $users, 'current' => '', 'tab' => '' );
			}
		}
		$employee = self::existing_page_url( 'employee-dashboard' );
		if ( '' !== $employee ) {
			$nav[] = array( 'label' => $admin ? __( 'Employee view', 'ghca-acd' ) : __( 'My dashboard', 'ghca-acd' ), 'icon' => 'home', 'url' => $employee, 'current' => $admin ? '' : 'true', 'tab' => '' );
		}
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$name = $user && ! empty( $user->display_name ) ? (string) $user->display_name : '';
		return array(
			'org'       => $org,
			'mono'      => self::initials( $org, 1 ),
			'logo_url'  => class_exists( 'GHCA_Dashboard_Branding' ) ? GHCA_Dashboard_Branding::get_logo_url() : '',
			'sub'       => $admin ? __( 'Compliance admin', 'ghca-acd' ) : __( 'Training', 'ghca-acd' ),
			'home_url'  => $admin ? $dash : ( '' !== $employee ? $employee : home_url( '/' ) ),
			'nav'       => $nav,
			'initials'  => self::initials( $name, 2 ),
			/* translators: %s: display name */
			'signed_in' => '' !== $name ? sprintf( __( 'Signed in as %s', 'ghca-acd' ), $name ) : '',
		);
	}

	public static function initials( string $name, int $max ): string {
		$letters = '';
		foreach ( preg_split( '/\s+/u', trim( $name ) ) ?: array() as $word ) {
			if ( '' === $word || mb_strlen( $letters ) >= $max ) {
				continue;
			}
			$letters .= mb_strtoupper( mb_substr( $word, 0, 1 ) );
		}
		return $letters;
	}

	private static function existing_page_url( string $slug ): string {
		if ( ! function_exists( 'get_page_by_path' ) || ! get_page_by_path( $slug ) ) {
			return '';
		}
		return GHCA_ACD_Data_Provider::get_page_url( $slug, '/' . $slug . '/' );
	}

	private static function manage_users_url(): string {
		if ( class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_manage_users() && class_exists( 'GHCA_ACD_Data_Provider' ) ) {
			return GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' );
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_users' ) ) {
			return admin_url( 'users.php' );
		}
		return '';
	}

	/** @return array<int,array<string,string>> */
	private static function quick_links(): array {
		$icons = array( 'courses' => 'book', 'users' => 'users', 'reports' => 'chart', 'certificate' => 'award', 'dashboard' => 'home', 'support' => 'lifebuoy' );
		$links = array();
		foreach ( GHCA_ACD_Shortcodes::get_quick_links() as $link ) {
			if ( empty( $link['url'] ) ) {
				continue;
			}
			$links[] = array(
				'label'    => (string) $link['label'],
				'subtitle' => (string) ( $link['subtitle'] ?? '' ),
				'url'      => (string) $link['url'],
				'icon'     => $icons[ (string) ( $link['icon'] ?? '' ) ] ?? 'link',
			);
		}
		return $links;
	}

	private static function icon( string $name, string $size = '' ): string {
		return GHCA_ODP_UI::icon( $name, $size );
	}

	/** @param array<string,mixed> $model */
	public static function render_from_model( array $model ): string {
		$theme = esc_attr( (string) ( $model['theme'] ?? 'gridhouse' ) );
		$style = (string) ( $model['brand_style'] ?? '' );
		$bleed = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'ghca_acd_ui_full_bleed', true ) : true;
		$html  = '<div class="ghca-ui' . ( $bleed ? ' ghca-ui--bleed' : '' ) . '">';
		$html .= '<div class="gh-app ghca-acd ghca-acd--v2" data-theme="' . $theme . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		$html .= '<div class="gh-chrome"><div class="gh-container">' . self::pagehead_html( $model ) . self::tabs_html( (array) ( $model['tabs'] ?? array() ) ) . '</div></div>';
		$html .= '<main class="gh-container gh-main">';
		$html .= '<div id="ghca-tab-overview" class="gh-ov ghca-acd__tab-content is-active" role="tabpanel" aria-labelledby="ghca-tab-overview-tab" tabindex="0">';
		$html .= self::kpi_html( (array) ( $model['kpis'] ?? array() ) );
		$html .= (string) ( $model['bulletin_html'] ?? '' );
		$html .= '<section class="gh-ovp gh-ovp--3" aria-label="' . esc_attr__( 'Compliance summary', 'ghca-acd' ) . '">';
		$html .= '<div class="gh-ovp-col">' . self::health_html( (array) ( $model['health'] ?? array() ) ) . self::certificates_html( (array) ( $model['certificates'] ?? array() ) ) . '</div>';
		$html .= self::groups_html( (array) ( $model['groups'] ?? array() ) );
		$html .= (string) ( $model['announcements_html'] ?? '' );
		$html .= '</section>';
		$html .= self::quick_links_html( (array) ( $model['quick_links'] ?? array() ) );
		$html .= self::support_html( (string) ( $model['support_email'] ?? '' ) );
		$html .= '</div>';
		$board = (array) ( $model['board_panels'] ?? array() );
		foreach ( (array) ( $model['panels'] ?? array() ) as $id => $panel ) {
			$html .= '<div id="' . esc_attr( (string) $id ) . '" class="ghca-acd__tab-content ' . ( in_array( (string) $id, $board, true ) ? 'gh-tp' : 'gh-legacy' ) . '" role="tabpanel" aria-labelledby="' . esc_attr( (string) $id ) . '-tab" tabindex="0">';
			$html .= $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- existing dashboard renderers escape their own markup.
			$html .= '</div>';
		}
		$html .= '</main>';
		$html .= (string) ( $model['drawer_html'] ?? '' );
		if ( ! empty( $model['employee_drawer'] ) ) {
			$html           .= GHCA_ACD_Redesign_Drawer::shell_html();
			$html           .= GHCA_ACD_Redesign_Reminder::modal_html();
			$html           .= GHCA_ACD_Redesign_Reminder::history_modal_html();
			$html           .= class_exists( 'GHCA_ACD_Redesign_Bulk' ) ? GHCA_ACD_Redesign_Bulk::modal_html() : '';
			self::$rendered = true;
		}
		if ( ! empty( $model['announcements_html'] ) && class_exists( 'GHCA_ACD_Announcements' ) ) {
			$modal = GHCA_ACD_Announcements::board_modal_html();
			if ( '' !== $modal ) {
				$html                .= $modal;
				self::$announce_board = true;
			}
		}
		$html .= self::toasts_html( (array) ( $model['flash'] ?? array() ) );
		// Packet-ready notices that are not inside an open Edit Records card. Filled by the packet script; kept until
		// the packet is downloaded, expires or is replaced, so dismissing a notice never loses its link.
		$html .= '<div class="gh-pkr-tray" data-ghca-packet-tray></div>';
		return $html . '</div></div>';
	}

	/**
	 * Toast stack. It is always present so scripts have a place to put toasts inside the themed wrapper.
	 * Server-side notices (sync finished, reminder logged) arrive as toasts too; without scripts they stay put.
	 *
	 * @param array<int,array{tone:string,title:string,text:string}> $flash
	 */
	public static function toasts_html( array $flash ): string {
		$html = '<div class="app-toasts" data-ghca-toasts>';
		foreach ( $flash as $item ) {
			$error = 'error' === ( $item['tone'] ?? '' );
			$html .= '<div class="gh-toast gh-toast--' . ( $error ? 'error' : 'success' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '">' . self::icon( $error ? 'x-circle' : 'check-circle' );
			$html .= '<div><div class="gh-toast-t">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</div>' . ( ! empty( $item['text'] ) ? '<p>' . esc_html( (string) $item['text'] ) . '</p>' : '' ) . '</div>';
			$html .= '<button class="gh-toast-x" type="button" aria-label="' . esc_attr__( 'Dismiss', 'ghca-acd' ) . '">' . self::icon( 'x', 'sm' ) . '</button></div>';
		}
		return $html . '</div>';
	}

	/** @param array<string,mixed> $model */
	private static function pagehead_html( array $model ): string {
		$html  = '<div class="gh-pagehead"><div class="gh-pagehead-main"><span class="gh-eyebrow">' . esc_html__( 'Compliance center', 'ghca-acd' ) . '</span>';
		$html .= '<h1 class="gh-h1">' . esc_html__( 'Compliance dashboard', 'ghca-acd' ) . '</h1>';
		$html .= '<div class="gh-meta"><span class="gh-btn gh-btn--secondary gh-btn--sm gh-btn--native gh-btn--static">' . self::icon( 'filter', 'sm' ) . esc_html( (string) ( $model['scope'] ?? '' ) ) . '</span>';
		$html .= '<span>' . self::icon( 'clock', 'sm' ) . esc_html( (string) ( $model['as_of'] ?? '' ) ) . '</span>';
		$html .= '<span>' . self::icon( 'users', 'sm' ) . esc_html( (string) ( $model['active'] ?? '' ) ) . '</span></div>';
		if ( ! empty( $model['scope_note'] ) ) {
			$html .= '<p class="gh-small gh-muted gh-scope-note">' . esc_html( (string) $model['scope_note'] ) . '</p>';
		}
		$html .= '</div><div class="gh-actions">';
		$icons = array( 'sync' => 'refresh', 'export' => 'download', 'users' => 'user-plus', 'reports' => 'chart' );
		foreach ( (array) ( $model['actions'] ?? array() ) as $action ) {
			if ( empty( $action['url'] ) ) {
				continue;
			}
			$key   = (string) ( $action['key'] ?? '' );
			$icon  = $icons[ $key ] ?? '';
			$html .= '<a class="gh-btn gh-btn--' . esc_attr( (string) ( $action['variant'] ?? 'secondary' ) ) . ( in_array( $key, array( 'sync', 'export' ), true ) ? ' gh-btn--native' : '' ) . '" href="' . esc_url( (string) $action['url'] ) . '"';
			$html .= ! empty( $action['tab'] ) ? ' data-ghca-tab-jump="' . esc_attr( (string) $action['tab'] ) . '"' : '';
			$html .= '>' . ( '' !== $icon ? self::icon( $icon ) : '' ) . esc_html( (string) $action['label'] ) . '</a>';
		}
		return $html . '</div></div>';
	}

	/** @param array<int,array<string,mixed>> $tabs */
	private static function tabs_html( array $tabs ): string {
		$html = '<div class="gh-tabs" role="tablist" data-ghca-tablist-main aria-label="' . esc_attr__( 'Compliance sections', 'ghca-acd' ) . '">';
		foreach ( $tabs as $tab ) {
			$selected = ! empty( $tab['active'] );
			$html    .= '<button type="button" role="tab" id="' . esc_attr( (string) $tab['id'] ) . '-tab" class="gh-tab" data-ghca-tab-target="' . esc_attr( (string) $tab['id'] ) . '" aria-controls="' . esc_attr( (string) $tab['id'] ) . '" aria-selected="' . ( $selected ? 'true' : 'false' ) . '" tabindex="' . ( $selected ? '0' : '-1' ) . '">';
			$html    .= esc_html( (string) $tab['label'] );
			if ( isset( $tab['badge'] ) && null !== $tab['badge'] ) {
				$tone  = (string) ( $tab['tone'] ?? '' );
				$html .= '<span class="gh-count' . ( '' !== $tone ? ' gh-count--' . esc_attr( $tone ) : '' ) . '">' . esc_html( (string) $tab['badge'] ) . '</span>';
			}
			$html .= '</button>';
		}
		return $html . '</div>';
	}

	/** @param array<int,array<string,string>> $kpis */
	private static function kpi_html( array $kpis ): string {
		$icons = array( 'users', 'book', 'progress', 'alert', 'award', 'chart' );
		$html  = '<section class="gh-grid gh-grid--kpi gh-kpi--overview" aria-label="' . esc_attr__( 'Key figures', 'ghca-acd' ) . '">';
		foreach ( array_values( $kpis ) as $i => $kpi ) {
			$tone  = (string) ( $kpi['tone'] ?? '' );
			$tab   = (string) ( $kpi['tab'] ?? '' );
			$class = 'gh-stat' . ( '' !== $tone ? ' gh-stat--' . esc_attr( $tone ) : '' );
			$inner = '<span class="gh-stat-top">' . esc_html( (string) $kpi['label'] ) . '<span class="gh-stat-ico">' . self::icon( $icons[ $i ] ?? 'chart' ) . '</span></span>';
			$inner .= '<span class="gh-stat-val">' . esc_html( (string) $kpi['value'] ) . '</span><span class="gh-stat-sub">' . esc_html( (string) $kpi['sub'] ) . '</span>';
			$html .= '' !== $tab
				? '<a class="' . $class . '" href="#' . esc_attr( $tab ) . '" data-ghca-tab-jump="' . esc_attr( $tab ) . '">' . $inner . '</a>'
				: '<div class="' . $class . '">' . $inner . '</div>';
		}
		return $html . '</section>';
	}

	/** @param array<string,mixed> $health */
	private static function health_html( array $health ): string {
		$slices = isset( $health['slices'] ) && is_array( $health['slices'] ) ? $health['slices'] : array();
		$total  = (int) ( $health['total'] ?? 0 );
		$aria   = $total > 0
			? sprintf( /* translators: %s: percent */ __( '%s compliant: completed or expiring soon', 'ghca-acd' ), (string) ( $health['center'] ?? '' ) )
			: __( 'No employees in scope', 'ghca-acd' );
		$html   = '<div class="gh-card" aria-labelledby="gh-health-h"><div class="gh-card-hd gh-card-hd--rule"><div><h2 class="gh-h3" id="gh-health-h">' . esc_html__( 'Compliance health', 'ghca-acd' ) . '</h2>';
		$html  .= '<span class="gh-small gh-muted">' . esc_html__( 'Where every employee in this view stands', 'ghca-acd' ) . '</span></div></div>';
		$html  .= '<div class="gh-ovp-bd"><div class="gh-health"><div class="gh-donut">' . self::donut_svg( $slices, $total, $aria );
		$html  .= '<div class="gh-donut-c"><strong>' . esc_html( (string) ( $health['center'] ?? '—' ) ) . '</strong><span>' . esc_html( (string) ( $health['caption'] ?? '' ) ) . '</span></div></div>';
		$html  .= '<ul class="gh-legend">';
		$colors = self::tone_colors();
		foreach ( $slices as $slice ) {
			$color = $colors[ (string) $slice['tone'] ] ?? $colors['neutral'];
			$html .= '<li><i style="background:' . esc_attr( $color ) . '"></i>' . esc_html( (string) $slice['label'] ) . '<strong>' . esc_html( (string) (int) $slice['count'] ) . '</strong></li>';
		}
		$html .= '</ul></div>';
		$html .= '<div class="gh-health-ft"><span>' . esc_html( sprintf( /* translators: %d: employees in scope */ __( '%d employees in scope', 'ghca-acd' ), $total ) ) . '</span>';
		$overdue = 0;
		foreach ( $slices as $slice ) {
			if ( 'overdue' === ( $slice['key'] ?? '' ) ) {
				$overdue = (int) $slice['count'];
			}
		}
		if ( $overdue > 0 ) {
			$html .= '<a class="gh-health-od" href="#ghca-tab-overdue" data-ghca-tab-jump="ghca-tab-overdue">' . esc_html( sprintf( /* translators: %d: overdue employees */ __( '%d overdue', 'ghca-acd' ), $overdue ) ) . ' →</a>';
		}
		return $html . '</div></div></div>';
	}

	/** @return array<string,string> */
	private static function tone_colors(): array {
		return array(
			'success' => 'var(--gh-status-success-solid)',
			'warning' => 'var(--gh-status-warning-solid)',
			'info'    => 'var(--gh-status-info-solid)',
			'danger'  => 'var(--gh-status-danger-solid)',
			'neutral' => 'var(--gh-status-neutral-solid)',
			'muted'   => 'var(--gh-border-strong)',
		);
	}

	/** @param array<int,array<string,mixed>> $slices */
	private static function donut_svg( array $slices, int $total, string $label ): string {
		$circ   = 2 * M_PI * 47;
		$colors = self::tone_colors();
		$svg    = '<svg width="112" height="112" viewBox="0 0 112 112" role="img" aria-label="' . esc_attr( $label ) . '">';
		$svg   .= '<circle cx="56" cy="56" r="47" fill="none" stroke="var(--gh-border)" stroke-width="12"></circle>';
		$acc    = 0.0;
		foreach ( $total > 0 ? $slices : array() as $slice ) {
			$count = (int) ( $slice['count'] ?? 0 );
			if ( $count < 1 ) {
				continue;
			}
			$len   = $circ * $count / $total;
			$color = $colors[ (string) ( $slice['tone'] ?? 'neutral' ) ] ?? $colors['neutral'];
			$svg  .= '<circle cx="56" cy="56" r="47" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="12" stroke-dasharray="' . esc_attr( number_format( $len, 1, '.', '' ) . ' ' . number_format( $circ, 1, '.', '' ) ) . '" stroke-dashoffset="' . esc_attr( number_format( -$acc, 1, '.', '' ) ) . '" transform="rotate(-90 56 56)"></circle>';
			$acc  += $len;
		}
		return $svg . '</svg>';
	}

	/** @param array<int,array<string,mixed>> $groups */
	private static function groups_html( array $groups ): string {
		$html  = '<div class="gh-card" aria-labelledby="gh-groups-h"><div class="gh-card-hd gh-card-hd--rule"><div><h2 class="gh-h3" id="gh-groups-h">' . esc_html__( 'Compliance by group', 'ghca-acd' ) . '</h2>';
		$html .= '<span class="gh-small gh-muted">' . esc_html__( 'Sorted by risk · completion of required courses', 'ghca-acd' ) . '</span></div>';
		$html .= '<a class="gh-btn gh-btn--ghost gh-btn--sm" href="#ghca-tab-courses" data-ghca-tab-jump="ghca-tab-courses">' . esc_html__( 'Course detail', 'ghca-acd' ) . self::icon( 'arrow-right', 'sm' ) . '</a></div><div class="gh-ovp-bd">';
		if ( ! $groups ) {
			return $html . '<p class="gh-ovp-empty">' . esc_html__( 'No groups in this view.', 'ghca-acd' ) . '</p></div></div>';
		}
		$status = array( 'danger' => 'overdue', 'warning' => 'duesoon', 'success' => 'progress' );
		$html  .= '<ul class="gh-grp">';
		foreach ( $groups as $row ) {
			$chip      = self::group_chip( $row );
			$rate      = max( 0, min( 100, (int) $row['rate'] ) );
			$overdue   = (int) $row['overdue'];
			$employees = (int) $row['employees'];
			$st        = $rate >= 100 && $overdue < 1 ? 'completed' : ( $status[ $chip['tone'] ] ?? 'progress' );
			$bar       = $overdue > 0 ? ' gh-bar--danger' : ( $rate >= 100 ? ' gh-bar--success' : '' );
			$meta      = 1 === $employees
				? __( '1 employee', 'ghca-acd' )
				: sprintf( /* translators: %d: employees */ __( '%d employees', 'ghca-acd' ), $employees );
			if ( $overdue > 0 ) {
				$meta .= ' · ' . sprintf( /* translators: %d: overdue employees */ __( '%d overdue', 'ghca-acd' ), $overdue );
			}
			$html .= '<li><div class="gh-grp-n"><a href="#ghca-tab-employees" data-ghca-tab-jump="ghca-tab-employees"><span>' . esc_html( (string) $row['label'] ) . '</span></a><span>' . esc_html( $meta ) . '</span></div>';
			$html .= '<div class="gh-bar' . $bar . '" role="img" aria-label="' . esc_attr( $rate . '%' ) . '"><span style="width:' . esc_attr( (string) $rate ) . '%"></span></div>';
			$html .= '<span class="gh-grp-pct">' . esc_html( (string) $rate ) . '%</span>';
			$html .= '<span class="gh-grp-st"><span class="gh-st gh-st--' . esc_attr( $st ) . '">' . esc_html( $chip['label'] ) . '</span></span></li>';
		}
		return $html . '</ul></div></div>';
	}

	/** @param array<string,int> $certs */
	private static function certificates_html( array $certs ): string {
		$missing = (int) ( $certs['missing'] ?? 0 );
		$rows    = array(
			array( __( 'Issued', 'ghca-acd' ), __( '· active employees in this view', 'ghca-acd' ), (int) ( $certs['issued'] ?? 0 ), '' ),
			array( __( 'Course completions', 'ghca-acd' ), __( '· last 30 days', 'ghca-acd' ), (int) ( $certs['recent'] ?? 0 ), '' ),
			array( __( 'Missing', 'ghca-acd' ), __( '· completed, no certificate link', 'ghca-acd' ), $missing, $missing > 0 ? 'is-danger' : 'is-zero' ),
		);
		$html = '<div class="gh-card" aria-labelledby="gh-certs-h"><div class="gh-card-hd gh-card-hd--plain"><h2 class="gh-h3" id="gh-certs-h">' . esc_html__( 'Certificates & records', 'ghca-acd' ) . '</h2>';
		$html .= '<a class="gh-btn gh-btn--ghost gh-btn--sm" href="#ghca-tab-certificates" data-ghca-tab-jump="ghca-tab-certificates">' . esc_html__( 'View all', 'ghca-acd' ) . self::icon( 'arrow-right', 'sm' ) . '</a></div>';
		$html .= '<div class="gh-ovp-bd"><ul class="gh-certl">';
		foreach ( $rows as $row ) {
			$html .= '<li><a href="#ghca-tab-certificates" data-ghca-tab-jump="ghca-tab-certificates"' . ( '' !== $row[3] ? ' class="' . esc_attr( $row[3] ) . '"' : '' ) . '>' . esc_html( $row[0] ) . '<small>' . esc_html( $row[1] ) . '</small><strong>' . esc_html( (string) $row[2] ) . '</strong></a></li>';
		}
		return $html . '</ul></div></div>';
	}

	/** @param array<int,array<string,string>> $links */
	private static function quick_links_html( array $links ): string {
		if ( ! $links ) {
			return '';
		}
		$html = '<section aria-labelledby="gh-ql-h"><div class="gh-card"><div class="gh-card-hd gh-card-hd--plain"><h2 class="gh-h3" id="gh-ql-h">' . esc_html__( 'Quick links', 'ghca-acd' ) . '</h2></div><div class="gh-card-bd gh-qlinks gh-qlinks--row">';
		foreach ( $links as $link ) {
			$html .= '<a class="gh-qlink" href="' . esc_url( $link['url'] ) . '">' . self::icon( $link['icon'] ) . '<span><strong><span>' . esc_html( $link['label'] ) . '</span></strong>';
			$html .= '' !== $link['subtitle'] ? '<span>' . esc_html( $link['subtitle'] ) . '</span>' : '';
			$html .= '</span></a>';
		}
		return $html . '</div></div></section>';
	}

	private static function support_html( string $email ): string {
		$html  = '<section class="gh-card gh-card--sunken gh-help-row" aria-labelledby="gh-help-h"><span class="gh-stat-ico gh-help-ico">' . self::icon( 'lifebuoy', 'lg' ) . '</span>';
		$html .= '<div class="gh-help-txt"><h2 class="gh-h4" id="gh-help-h">' . esc_html__( 'Need help with records or course access?', 'ghca-acd' ) . '</h2>';
		$html .= '<p class="gh-p gh-secondary">' . esc_html__( 'First check the employee is in the right LearnDash group with the required courses assigned.', 'ghca-acd' ) . '</p></div>';
		if ( '' !== $email && ( ! function_exists( 'is_email' ) || is_email( $email ) ) ) {
			$html .= '<a class="gh-btn gh-btn--secondary" href="' . esc_url( 'mailto:' . $email ) . '">' . self::icon( 'mail' ) . esc_html__( 'Contact support', 'ghca-acd' ) . '</a>';
		}
		return $html . '</section>';
	}

	/** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
	private static function tabs( array $data ): array {
		$overdue = (int) ( $data['overdue_employees'] ?? 0 );
		$tabs    = array(
			array( 'id' => 'ghca-tab-overview', 'label' => __( 'Overview', 'ghca-acd' ), 'badge' => null, 'active' => true ),
			array( 'id' => 'ghca-tab-employees', 'label' => __( 'Employees', 'ghca-acd' ), 'badge' => (int) ( $data['total_employees'] ?? 0 ), 'active' => false ),
			array( 'id' => 'ghca-tab-overdue', 'label' => __( 'Overdue & at risk', 'ghca-acd' ), 'badge' => $overdue > 0 ? $overdue : null, 'tone' => 'danger', 'active' => false ),
		);
		if ( GHCA_ACD_Roles::user_can_review_external_training() ) {
			$tabs[] = array( 'id' => 'ghca-tab-external-training', 'label' => __( 'External training', 'ghca-acd' ), 'badge' => null, 'active' => false );
		}
		$tabs[] = array( 'id' => 'ghca-tab-audit', 'label' => __( 'Audit data', 'ghca-acd' ), 'badge' => null, 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-courses', 'label' => __( 'Courses', 'ghca-acd' ), 'badge' => GHCA_ACD_Data_Provider::count_unique_tracked_courses( $data['employees'] ?? array() ), 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-certificates', 'label' => __( 'Certificates', 'ghca-acd' ), 'badge' => null, 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-reports', 'label' => __( 'Reports', 'ghca-acd' ), 'badge' => null, 'active' => false );
		if ( GHCA_ACD_Roles::user_can_manage_users() ) {
			$tabs[] = array( 'id' => 'ghca-tab-inactive-employees', 'label' => __( 'Inactive', 'ghca-acd' ), 'badge' => (int) ( $data['total_inactive_employees'] ?? 0 ), 'active' => false );
		}
		return $tabs;
	}

	/** @param array<string,mixed> $atts @param array<string,mixed> $data @return array<string,string> */
	private static function secondary_panels( $atts, array $data ): array {
		$panels = array(
			'ghca-tab-employees'    => GHCA_ACD_Redesign_Tables::render_employees( $atts ),
			'ghca-tab-courses'      => GHCA_ACD_Redesign_Lists::render_courses( $atts ),
			'ghca-tab-certificates' => GHCA_ACD_Redesign_Lists::render_certificates( $atts ),
			'ghca-tab-overdue'      => GHCA_ACD_Redesign_Lists::render_overdue( $atts ),
			'ghca-tab-reports'      => GHCA_ACD_Redesign_Admin::render_reports( $atts ),
			'ghca-tab-audit'        => GHCA_ACD_Redesign_Admin::render_audit(),
		);
		if ( GHCA_ACD_Roles::user_can_manage_users() ) {
			$panels['ghca-tab-inactive-employees'] = GHCA_ACD_Redesign_Tables::render_employees( $atts, 'inactive' );
		}
		if ( GHCA_ACD_Roles::user_can_review_external_training() && class_exists( 'GHCA_ACD_Jotform_UI' ) ) {
			$panels['ghca-tab-external-training'] = GHCA_ACD_Jotform_UI::render_review_board();
		}
		unset( $data );
		return $panels;
	}

	/** @return array<int,array<string,string>> */
	private static function header_actions(): array {
		$page = function_exists( 'get_permalink' ) ? ( get_permalink() ?: home_url( '/compliance-admin-dashboard/' ) ) : '';
		$manage = self::manage_users_url();
		if ( '' === $manage && class_exists( 'GHCA_ACD_Data_Provider' ) ) {
			$manage = GHCA_ACD_Data_Provider::get_page_url( 'group-management', '/group-management/' );
		}
		return array(
			array(
				'key'     => 'sync',
				'label'   => __( 'Sync', 'ghca-acd' ),
				'url'     => function_exists( 'wp_nonce_url' ) ? wp_nonce_url( add_query_arg( 'ghca_acd_sync', '1', $page ), 'ghca_acd_sync', 'ghca_nonce' ) : $page,
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'key'     => 'export',
				'label'   => __( 'Export', 'ghca-acd' ),
				'url'     => function_exists( 'wp_nonce_url' ) ? wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' ) : '',
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'key'     => 'users',
				'label'   => __( 'Manage users', 'ghca-acd' ),
				'url'     => $manage,
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'key'     => 'reports',
				'label'   => __( 'View reports', 'ghca-acd' ),
				'url'     => $page . '#ghca-tab-reports',
				'variant' => 'primary',
				'tab'     => 'ghca-tab-reports',
			),
		);
	}

	public static function brand_style(): string {
		return implode( ';', array_filter( array( self::custom_brand_style(), self::agency_brand_style() ) ) );
	}

	/**
	 * Agency primary from the plugin's Branding settings, for quick links and
	 * primary buttons on every preset theme. Text colours are chosen to keep
	 * WCAG AA (4.5:1) on white, on the hover tint, and on the button fill.
	 */
	public static function agency_brand_style( string $primary = '' ): string {
		if ( '' === $primary && class_exists( 'GHCA_Dashboard_Branding' ) ) {
			$primary = (string) ( GHCA_Dashboard_Branding::get()['primary'] ?? '' );
		}
		$primary = strtolower( trim( $primary ) );
		if ( ! preg_match( '/^#[0-9a-f]{6}$/', $primary ) ) {
			return '';
		}
		$soft = self::mix_hex( $primary, '#ffffff', 0.92 );
		$ink  = $primary;
		for ( $i = 0; $i < 20 && self::contrast( $ink, $soft ) < 4.5; $i++ ) {
			$ink = self::mix_hex( $ink, '#000000', 0.08 );
		}
		$fill  = self::contrast( $primary, self::text_on( $primary ) ) >= 4.5 ? $primary : $ink;
		$hover = self::mix_hex( $fill, '#000000', 0.18 );
		$parts = array(
			'--gh-agency:' . $fill,
			'--gh-agency-hover:' . $hover,
			'--gh-agency-on:' . self::text_on( $fill ),
			'--gh-agency-on-hover:' . self::text_on( $hover ),
			'--gh-agency-ink:' . $ink,
			'--gh-agency-soft:' . $soft,
		);
		return implode( ';', $parts );
	}

	private static function text_on( string $hex ): string {
		return self::contrast( $hex, '#ffffff' ) >= self::contrast( $hex, '#111827' ) ? '#ffffff' : '#111827';
	}

	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	private static function luminance( string $hex ): float {
		$sum = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = hexdec( substr( $hex, 1 + 2 * $i, 2 ) ) / 255;
			$sum += $weight * ( $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4 );
		}
		return $sum;
	}

	private static function mix_hex( string $hex, string $with, float $weight ): string {
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$a    = hexdec( substr( $hex, 1 + 2 * $i, 2 ) );
			$b    = hexdec( substr( $with, 1 + 2 * $i, 2 ) );
			$out .= sprintf( '%02x', (int) round( $a * ( 1 - $weight ) + $b * $weight ) );
		}
		return $out;
	}

	public static function custom_brand_style(): string {
		if ( 'gridhouse' !== self::theme_slug() || ! class_exists( 'GHCA_Dashboard_Branding' ) || ! function_exists( 'get_option' ) ) {
			return '';
		}
		$saved = get_option( GHCA_Dashboard_Branding::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return '';
		}
		$defaults = GHCA_Dashboard_Branding::defaults();
		$parts    = array();
		$primary  = self::saved_hex( $saved, 'primary', (string) $defaults['primary'] );
		$accent   = self::saved_hex( $saved, 'secondary', (string) $defaults['secondary'] );
		if ( '' !== $primary ) {
			$parts[] = '--gh-color-primary:' . $primary;
			$parts[] = '--gh-brand-primary:' . $primary;
		}
		if ( '' !== $accent ) {
			$parts[] = '--gh-color-accent:' . $accent;
			$parts[] = '--gh-brand-accent:' . $accent;
		}
		return implode( ';', $parts );
	}

	/** @param array<string,mixed> $saved */
	private static function saved_hex( array $saved, string $key, string $default ): string {
		$value = isset( $saved[ $key ] ) ? strtolower( trim( (string) $saved[ $key ] ) ) : '';
		if ( ! preg_match( '/^#[0-9a-f]{6}$/', $value ) || $value === strtolower( $default ) ) {
			return '';
		}
		return $value;
	}
}
