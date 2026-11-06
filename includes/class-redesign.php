<?php
/**
 * Opt-in admin overview. Legacy shortcode output stays in place until
 * ghca_acd_ui_redesign is saved on. Counts come from the existing aggregate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Redesign {
	/** @return array<int,string> */
	public static function themes(): array {
		return array( 'gridhouse', 'akansoh', 'purple' );
	}

	public static function theme_slug(): string {
		$theme = function_exists( 'apply_filters' ) ? apply_filters( 'ghca_acd_ui_theme', 'gridhouse' ) : 'gridhouse';
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
		$other = $total - array_sum( array_column( $slices, 'count' ) );
		if ( $other > 0 ) {
			$slices[] = array( 'key' => 'other', 'label' => __( 'Other', 'ghca-acd' ), 'count' => $other, 'tone' => 'neutral' );
		}
		$expiring = (int) ( $aggregate['expiring_soon_employees'] ?? 0 );
		if ( $total < 1 ) {
			$caption = __( 'No employees in scope', 'ghca-acd' );
		} elseif ( $expiring > 0 ) {
			$caption = __( 'completed and expiring soon', 'ghca-acd' );
		} else {
			$caption = __( 'compliant', 'ghca-acd' );
		}
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
			array( 'label' => __( 'In progress', 'ghca-acd' ), 'value' => (string) (int) ( $aggregate['in_progress_employees'] ?? 0 ), 'sub' => __( 'Includes new hires who have not started', 'ghca-acd' ), 'tone' => '', 'tab' => '' ),
			array( 'label' => __( 'Overdue', 'ghca-acd' ), 'value' => (string) $overdue, 'sub' => __( 'Employees · view list', 'ghca-acd' ), 'tone' => $overdue > 0 ? 'danger' : '', 'tab' => 'ghca-tab-overdue' ),
			array( 'label' => __( 'Certificates', 'ghca-acd' ), 'value' => (string) (int) ( $aggregate['certificates_issued'] ?? 0 ), 'sub' => __( 'Issued for active employees in this view', 'ghca-acd' ), 'tone' => '', 'tab' => 'ghca-tab-certificates' ),
			array(
				'label' => __( 'Completion', 'ghca-acd' ),
				'value' => $total > 0 ? $rate . '%' : '—',
				'sub'   => $total > 0 ? sprintf( /* translators: 1: compliant employees, 2: employees in view */ __( '%1$d of %2$d employees', 'ghca-acd' ), $counted, $total ) : __( 'No employees in scope', 'ghca-acd' ),
				'tone'  => '',
				'tab'   => '',
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
		$html = GHCA_ACD_Shortcodes::render_login_gate( $atts ) . GHCA_ACD_Shortcodes::render_flash_notices( $atts );
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
			'brand_style'   => self::custom_brand_style(),
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
			'announcements_html' => GHCA_ACD_Announcements::render_announcements( $atts ),
			'quick_links_html'   => GHCA_ACD_Shortcodes::render_quick_links( $atts ),
			'support_html'       => GHCA_ACD_Shortcodes::render_support( $atts ),
			'panels'             => self::secondary_panels( $atts, $data ),
		);
		return $html . self::render_from_model( $model );
	}

	/** @param array<string,mixed> $model */
	public static function render_from_model( array $model ): string {
		$theme = esc_attr( (string) ( $model['theme'] ?? 'gridhouse' ) );
		$style = (string) ( $model['brand_style'] ?? '' );
		$html  = '<div class="ghca-acd ghca-acd--v2" data-gh-theme="' . $theme . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		$html .= '<header class="gh-ov-header"><div><p class="gh-ov-kicker">' . esc_html__( 'Administrative Compliance Center', 'ghca-acd' ) . '</p>';
		$html .= '<h1>' . esc_html__( 'Compliance dashboard', 'ghca-acd' ) . '</h1>';
		$html .= '<p class="gh-ov-meta"><span>' . esc_html( (string) $model['scope'] ) . '</span><span>' . esc_html( (string) $model['as_of'] ) . '</span><span>' . esc_html( (string) $model['active'] ) . '</span></p>';
		if ( ! empty( $model['scope_note'] ) ) {
			$html .= '<p class="gh-ov-scope-note">' . esc_html( (string) $model['scope_note'] ) . '</p>';
		}
		$html .= '</div><div class="gh-ov-actions">';
		foreach ( (array) ( $model['actions'] ?? array() ) as $action ) {
			if ( empty( $action['url'] ) ) {
				continue;
			}
			$html .= '<a class="gh-btn gh-btn--' . esc_attr( (string) ( $action['variant'] ?? 'secondary' ) ) . '" href="' . esc_url( (string) $action['url'] ) . '"';
			$html .= ! empty( $action['tab'] ) ? ' data-ghca-tab-jump="' . esc_attr( (string) $action['tab'] ) . '"' : '';
			$html .= '>' . esc_html( (string) $action['label'] ) . '</a>';
		}
		$html .= '</div></header>';
		$html .= '<div class="ghca-acd__tabs" role="tablist" aria-label="' . esc_attr__( 'Compliance dashboard sections', 'ghca-acd' ) . '">';
		foreach ( (array) ( $model['tabs'] ?? array() ) as $tab ) {
			$selected = ! empty( $tab['active'] );
			$html    .= '<button type="button" role="tab" id="' . esc_attr( (string) $tab['id'] ) . '-tab" class="ghca-acd__tab-btn' . ( $selected ? ' is-active' : '' ) . '" data-ghca-tab-target="' . esc_attr( (string) $tab['id'] ) . '" aria-controls="' . esc_attr( (string) $tab['id'] ) . '" aria-selected="' . ( $selected ? 'true' : 'false' ) . '" tabindex="' . ( $selected ? '0' : '-1' ) . '">';
			$html    .= esc_html( (string) $tab['label'] );
			if ( isset( $tab['badge'] ) && null !== $tab['badge'] ) {
				$html .= ' <span class="ghca-acd__tab-badge">' . esc_html( (string) $tab['badge'] ) . '</span>';
			}
			$html .= '</button>';
		}
		$html .= '</div>';
		$html .= self::kpi_html( (array) ( $model['kpis'] ?? array() ) );
		$html .= '<div id="ghca-tab-overview" class="ghca-acd__tab-content is-active" role="tabpanel" aria-labelledby="ghca-tab-overview-tab" tabindex="0">';
		$html .= (string) ( $model['bulletin_html'] ?? '' );
		$html .= '<div class="gh-ov-grid">';
		$html .= self::health_html( (array) ( $model['health'] ?? array() ) );
		$html .= self::groups_html( (array) ( $model['groups'] ?? array() ) );
		$html .= '<div class="gh-ov-side">' . self::certificates_html( (array) ( $model['certificates'] ?? array() ) ) . (string) ( $model['announcements_html'] ?? '' ) . '</div>';
		$html .= '</div>';
		$html .= (string) ( $model['quick_links_html'] ?? '' );
		$html .= (string) ( $model['support_html'] ?? '' );
		$html .= '</div>';
		foreach ( (array) ( $model['panels'] ?? array() ) as $id => $panel ) {
			$html .= '<div id="' . esc_attr( (string) $id ) . '" class="ghca-acd__tab-content" role="tabpanel" aria-labelledby="' . esc_attr( (string) $id ) . '-tab" tabindex="0">';
			$html .= $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- existing dashboard renderers escape their own markup.
			$html .= '</div>';
		}
		$html .= (string) ( $model['drawer_html'] ?? '' );
		return $html . '</div>';
	}

	/** @param array<int,array<string,string>> $kpis */
	private static function kpi_html( array $kpis ): string {
		$html = '<div class="gh-kpis">';
		foreach ( $kpis as $kpi ) {
			$tone = (string) ( $kpi['tone'] ?? '' );
			$tab  = (string) ( $kpi['tab'] ?? '' );
			$html .= '<div class="gh-kpi' . ( '' !== $tone ? ' gh-kpi--' . esc_attr( $tone ) : '' ) . '">';
			$html .= '<span class="gh-kpi__label">' . esc_html( (string) $kpi['label'] ) . '</span>';
			$html .= '<strong>' . esc_html( (string) $kpi['value'] ) . '</strong>';
			if ( '' !== $tab ) {
				$html .= '<a href="#' . esc_attr( $tab ) . '" data-ghca-tab-jump="' . esc_attr( $tab ) . '">' . esc_html( (string) $kpi['sub'] ) . '</a>';
			} else {
				$html .= '<span>' . esc_html( (string) $kpi['sub'] ) . '</span>';
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/** @param array<string,mixed> $health */
	private static function health_html( array $health ): string {
		$slices = isset( $health['slices'] ) && is_array( $health['slices'] ) ? $health['slices'] : array();
		$total  = (int) ( $health['total'] ?? 0 );
		$html   = '<section class="gh-card" aria-labelledby="gh-ov-health-title"><header class="gh-card__head"><h2 id="gh-ov-health-title">' . esc_html__( 'Compliance health', 'ghca-acd' ) . '</h2>';
		$html  .= '<p>' . esc_html__( 'The percent is the existing compliance rate: completed plus expiring soon. The colors show those groups separately.', 'ghca-acd' ) . '</p></header>';
		$html  .= '<div class="gh-health"><div class="gh-donut" style="' . esc_attr( self::donut_style( $slices, $total ) ) . '" aria-hidden="true"><span><strong>' . esc_html( (string) ( $health['center'] ?? '—' ) ) . '</strong><small>' . esc_html( (string) ( $health['caption'] ?? '' ) ) . '</small></span></div>';
		$html  .= '<ul class="gh-legend">';
		foreach ( $slices as $slice ) {
			$html .= '<li><i class="gh-dot gh-dot--' . esc_attr( (string) $slice['tone'] ) . '" aria-hidden="true"></i><span>' . esc_html( (string) $slice['label'] ) . '</span><strong>' . esc_html( (string) (int) $slice['count'] ) . '</strong></li>';
		}
		$html .= '</ul></div>';
		$html .= '<p class="gh-card__foot">' . esc_html( sprintf( /* translators: %d: employees in scope */ __( '%d employees in scope', 'ghca-acd' ), $total ) );
		$overdue = 0;
		foreach ( $slices as $slice ) {
			if ( 'overdue' === ( $slice['key'] ?? '' ) ) {
				$overdue = (int) $slice['count'];
			}
		}
		if ( $overdue > 0 ) {
			$html .= ' <a href="#ghca-tab-overdue" data-ghca-tab-jump="ghca-tab-overdue">' . esc_html( sprintf( /* translators: %d: overdue employees */ __( '%d overdue', 'ghca-acd' ), $overdue ) ) . '</a>';
		}
		return $html . '</p></section>';
	}

	/** @param array<int,array<string,mixed>> $groups */
	private static function groups_html( array $groups ): string {
		$html = '<section class="gh-card" aria-labelledby="gh-ov-groups-title"><header class="gh-card__head"><div><h2 id="gh-ov-groups-title">' . esc_html__( 'Compliance by group', 'ghca-acd' ) . '</h2><p>' . esc_html__( 'Sorted by risk · completion of required courses', 'ghca-acd' ) . '</p></div>';
		$html .= '<a href="#ghca-tab-courses" data-ghca-tab-jump="ghca-tab-courses">' . esc_html__( 'Course detail', 'ghca-acd' ) . '</a></header>';
		if ( ! $groups ) {
			return $html . '<p class="gh-ov-empty">' . esc_html__( 'No groups in this view.', 'ghca-acd' ) . '</p></section>';
		}
		$html .= '<ul class="gh-groups">';
		foreach ( $groups as $row ) {
			$chip  = self::group_chip( $row );
			$rate  = (int) $row['rate'];
			$tone  = 'high' === ( $row['risk'] ?? '' ) ? 'danger' : ( 'medium' === ( $row['risk'] ?? '' ) ? 'warning' : 'success' );
			$html .= '<li><div class="gh-group__top"><strong>' . esc_html( (string) $row['label'] ) . '</strong><span class="gh-chip gh-chip--' . esc_attr( $chip['tone'] ) . '">' . esc_html( $chip['label'] ) . '</span></div>';
			$html .= '<p>' . esc_html( sprintf( /* translators: 1: employees, 2: overdue */ __( '%1$d employees · %2$d overdue', 'ghca-acd' ), (int) $row['employees'], (int) $row['overdue'] ) ) . '</p>';
			$html .= '<div class="gh-bar" role="img" aria-label="' . esc_attr( $rate . '%' ) . '"><span class="gh-bar__fill gh-bar__fill--' . esc_attr( $tone ) . '" style="width:' . esc_attr( (string) max( 0, min( 100, $rate ) ) ) . '%"></span></div>';
			$html .= '<b>' . esc_html( (string) $rate ) . '%</b></li>';
		}
		return $html . '</ul></section>';
	}

	/** @param array<string,int> $certs */
	private static function certificates_html( array $certs ): string {
		$rows = array(
			__( 'Issued for active employees in this view', 'ghca-acd' ) => (int) ( $certs['issued'] ?? 0 ),
			__( 'Course completions · last 30 days', 'ghca-acd' ) => (int) ( $certs['recent'] ?? 0 ),
			__( 'Completed, no certificate link', 'ghca-acd' ) => (int) ( $certs['missing'] ?? 0 ),
		);
		$html = '<section class="gh-card gh-card--certs" aria-labelledby="gh-ov-certs-title"><header class="gh-card__head"><h2 id="gh-ov-certs-title">' . esc_html__( 'Certificates & records', 'ghca-acd' ) . '</h2>';
		$html .= '<a href="#ghca-tab-certificates" data-ghca-tab-jump="ghca-tab-certificates">' . esc_html__( 'View all', 'ghca-acd' ) . '</a></header><dl>';
		foreach ( $rows as $label => $value ) {
			$html .= '<div><dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		return $html . '</dl></section>';
	}

	/** @param array<int,array<string,mixed>> $slices */
	private static function donut_style( array $slices, int $total ): string {
		if ( $total < 1 ) {
			return 'background:var(--gh-neutral-100)';
		}
		$colors = array(
			'success' => 'var(--gh-status-success-solid)',
			'warning' => 'var(--gh-status-warning-solid)',
			'info'    => 'var(--gh-status-info-solid)',
			'danger'  => 'var(--gh-status-danger-solid)',
			'neutral' => 'var(--gh-status-neutral-solid)',
		);
		$stops  = array();
		$cursor = 0.0;
		foreach ( $slices as $slice ) {
			$count = (int) ( $slice['count'] ?? 0 );
			if ( $count < 1 ) {
				continue;
			}
			$end     = $cursor + ( $count / $total * 100 );
			$color   = $colors[ (string) ( $slice['tone'] ?? 'neutral' ) ] ?? $colors['neutral'];
			$stops[] = $color . ' ' . round( $cursor, 2 ) . '% ' . round( $end, 2 ) . '%';
			$cursor  = $end;
		}
		if ( ! $stops ) {
			return 'background:var(--gh-neutral-100)';
		}
		return 'background:conic-gradient(' . implode( ',', $stops ) . ')';
	}

	/** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
	private static function tabs( array $data ): array {
		$tabs = array(
			array( 'id' => 'ghca-tab-overview', 'label' => __( 'Overview', 'ghca-acd' ), 'badge' => null, 'active' => true ),
			array( 'id' => 'ghca-tab-employees', 'label' => __( 'Employees', 'ghca-acd' ), 'badge' => (int) ( $data['total_employees'] ?? 0 ), 'active' => false ),
		);
		if ( GHCA_ACD_Roles::user_can_manage_users() ) {
			$tabs[] = array( 'id' => 'ghca-tab-inactive-employees', 'label' => __( 'Inactive', 'ghca-acd' ), 'badge' => (int) ( $data['total_inactive_employees'] ?? 0 ), 'active' => false );
		}
		$overdue = (int) ( $data['overdue_employees'] ?? 0 );
		$tabs[] = array( 'id' => 'ghca-tab-overdue', 'label' => __( 'Overdue & at risk', 'ghca-acd' ), 'badge' => $overdue > 0 ? $overdue : null, 'active' => false );
		if ( GHCA_ACD_Roles::user_can_review_external_training() ) {
			$tabs[] = array( 'id' => 'ghca-tab-external-training', 'label' => __( 'External training', 'ghca-acd' ), 'badge' => null, 'active' => false );
		}
		$tabs[] = array( 'id' => 'ghca-tab-audit', 'label' => __( 'Audit data', 'ghca-acd' ), 'badge' => null, 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-courses', 'label' => __( 'Courses', 'ghca-acd' ), 'badge' => GHCA_ACD_Data_Provider::count_unique_tracked_courses( $data['employees'] ?? array() ), 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-certificates', 'label' => __( 'Certificates', 'ghca-acd' ), 'badge' => null, 'active' => false );
		$tabs[] = array( 'id' => 'ghca-tab-reports', 'label' => __( 'Reports', 'ghca-acd' ), 'badge' => null, 'active' => false );
		return $tabs;
	}

	/** @param array<string,mixed> $atts @param array<string,mixed> $data @return array<string,string> */
	private static function secondary_panels( $atts, array $data ): array {
		$panels = array(
			'ghca-tab-employees'    => GHCA_ACD_Shortcodes::render_employee_table( $atts ),
			'ghca-tab-courses'      => GHCA_ACD_Shortcodes::render_course_overview( $atts ),
			'ghca-tab-certificates' => GHCA_ACD_Shortcodes::render_certificate_tracking( $atts ),
			'ghca-tab-overdue'      => GHCA_ACD_Shortcodes::render_overdue_employees( $atts ),
			'ghca-tab-reports'      => GHCA_ACD_Shortcodes::render_reports( $atts ),
			'ghca-tab-audit'        => GHCA_ACD_Audit_UI::render(),
		);
		if ( GHCA_ACD_Roles::user_can_manage_users() ) {
			$panels['ghca-tab-inactive-employees'] = GHCA_ACD_Shortcodes::render_employee_table( $atts, 'inactive' );
		}
		if ( GHCA_ACD_Roles::user_can_review_external_training() && class_exists( 'GHCA_ACD_Jotform_UI' ) ) {
			$panels['ghca-tab-external-training'] = GHCA_ACD_Jotform_UI::render_frontend_review();
		}
		unset( $data );
		return $panels;
	}

	/** @return array<int,array<string,string>> */
	private static function header_actions(): array {
		$page = function_exists( 'get_permalink' ) ? ( get_permalink() ?: home_url( '/compliance-admin-dashboard/' ) ) : '';
		$manage = '';
		if ( class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_manage_users() && class_exists( 'GHCA_ACD_Data_Provider' ) ) {
			$manage = GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' );
		} elseif ( function_exists( 'current_user_can' ) && current_user_can( 'edit_users' ) ) {
			$manage = admin_url( 'users.php' );
		} elseif ( class_exists( 'GHCA_ACD_Data_Provider' ) ) {
			$manage = GHCA_ACD_Data_Provider::get_page_url( 'group-management', '/group-management/' );
		}
		return array(
			array(
				'label'   => __( 'Sync', 'ghca-acd' ),
				'url'     => function_exists( 'wp_nonce_url' ) ? wp_nonce_url( add_query_arg( 'ghca_acd_sync', '1', $page ), 'ghca_acd_sync', 'ghca_nonce' ) : $page,
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'label'   => __( 'Export', 'ghca-acd' ),
				'url'     => function_exists( 'wp_nonce_url' ) ? wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' ) : '',
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'label'   => __( 'Manage users', 'ghca-acd' ),
				'url'     => $manage,
				'variant' => 'secondary',
				'tab'     => '',
			),
			array(
				'label'   => __( 'View reports', 'ghca-acd' ),
				'url'     => $page . '#ghca-tab-reports',
				'variant' => 'primary',
				'tab'     => 'ghca-tab-reports',
			),
		);
	}

	public static function custom_brand_style(): string {
		if ( ! class_exists( 'GHCA_Dashboard_Branding' ) || ! function_exists( 'get_option' ) ) {
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
