<?php
/**
 * View layer for the compliance dashboard.
 *
 * Registers all Elementor/frontend shortcodes and renders every dashboard
 * panel, table, and action-chip HTML fragment. Extracted from
 * GHCA_Admin_Compliance_Dashboard (Phase 6 core file refactor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Shortcodes {

  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
  }

  public static function register_shortcodes(): void {
    $map = array(
      'admin_compliance_login_gate'         => array( __CLASS__, 'render_login_gate' ),
      'admin_compliance_header'             => array( __CLASS__, 'render_header' ),
      'admin_compliance_kpis'               => array( __CLASS__, 'render_kpis' ),
      'admin_overdue_employees'             => array( __CLASS__, 'render_overdue_employees' ),
      'admin_course_completion_overview'    => array( __CLASS__, 'render_course_overview' ),
      'admin_employee_compliance_table'     => array( __CLASS__, 'render_employee_table' ),
      'admin_certificate_tracking'          => array( __CLASS__, 'render_certificate_tracking' ),
      // Announcements render in the core class until the Announcements manager exists.
      'admin_compliance_announcements'      => array( 'GHCA_Admin_Compliance_Dashboard', 'render_announcements' ),
      'admin_compliance_quick_links'        => array( __CLASS__, 'render_quick_links' ),
      'admin_compliance_support'            => array( __CLASS__, 'render_support' ),
      'admin_compliance_dashboard'          => array( __CLASS__, 'render_full_dashboard' ),
      'admin_compliance_user_report'        => array( __CLASS__, 'render_user_report' ),
    );

    foreach ( $map as $tag => $callback ) {
      add_shortcode( $tag, $callback );
    }
  }

  public static function can_view_dashboard(): bool {
    return is_user_logged_in() && GHCA_ACD_Roles::user_can_view();
  }

  public static function render_full_dashboard( $atts = array() ): string {
    $html = self::render_login_gate( $atts )
      . self::render_flash_notices( $atts )
      . GHCA_ACD_Scoping::render_scope_banner( $atts )
      . self::render_header( $atts )
      . self::render_kpis( $atts );

    if ( ! self::can_view_dashboard() ) {
        return $html;
    }

    $data          = GHCA_ACD_Data_Provider::get_aggregate();
    $emp_count     = (int) ( $data['total_employees'] ?? 0 );
    $overdue_count = (int) ( $data['overdue_employees'] ?? 0 );
    $course_count  = GHCA_ACD_Data_Provider::count_unique_tracked_courses( $data['employees'] ?? array() );

    $html .= '<div class="ghca-acd ghca-acd--tabs-shell">';
    $html .= '<div class="ghca-acd__tabs" role="tablist" aria-label="' . esc_attr__( 'Compliance dashboard sections', 'ghca-acd' ) . '">
      <button type="button" class="ghca-acd__tab-btn is-active" data-ghca-tab-target="ghca-tab-overview">' . esc_html__( 'Overview', 'ghca-acd' ) . '</button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-employees">' . esc_html__( 'Employees', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($emp_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-courses">' . esc_html__( 'Courses', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($course_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-certificates">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-overdue">' . esc_html__( 'Overdue', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($overdue_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-reports">' . esc_html__( 'Reports', 'ghca-acd' ) . '</button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-audit">' . esc_html__( 'Audit Data', 'ghca-acd' ) . '</button>
    </div>';

    // Overview Tab
    $html .= '<div id="ghca-tab-overview" class="ghca-acd__tab-content is-active">';
    $html .= GHCA_ACD_Scoping::render_group_summary( $atts );
    $html .= '<div class="ghca-acd__split-grid">';
    $html .= self::render_certificate_tracking( $atts );
    $html .= GHCA_Admin_Compliance_Dashboard::render_announcements( $atts );
    $html .= '</div>';
    $html .= self::render_quick_links( $atts );
    $html .= self::render_support( $atts );
    $html .= '</div>';

    // Employees Tab
    $html .= '<div id="ghca-tab-employees" class="ghca-acd__tab-content">';
    $html .= self::render_employee_table( $atts );
    $html .= '</div>';

    // Courses Tab
    $html .= '<div id="ghca-tab-courses" class="ghca-acd__tab-content">';
    $html .= self::render_course_overview( $atts );
    $html .= '</div>';

    // Certificates Tab
    $html .= '<div id="ghca-tab-certificates" class="ghca-acd__tab-content">';
    $html .= self::render_certificate_tracking( $atts );
    $html .= '</div>';

    // Overdue Tab
    $html .= '<div id="ghca-tab-overdue" class="ghca-acd__tab-content">';
    $html .= self::render_overdue_employees( $atts );
    $html .= '</div>';

    // Reports Tab
    $html .= '<div id="ghca-tab-reports" class="ghca-acd__tab-content">';
    $html .= self::render_reports( $atts );
    $html .= '</div>';

    // Audit Tab
    $html .= '<div id="ghca-tab-audit" class="ghca-acd__tab-content">';
    $html .= GHCA_ACD_Audit_UI::render();
    $html .= '</div>';
    $html .= '</div>';

    return $html;
  }

  public static function render_flash_notices( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    if ( empty( $_GET['ghca_reminder_logged'] ) && empty( $_GET['ghca_acd_synced'] ) ) {
      return '';
    }

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--flash">
      <?php if ( ! empty( $_GET['ghca_acd_synced'] ) ) : ?>
        <div class="ghca-acd__flash ghca-acd__flash--success" role="status">
          <?php esc_html_e( 'Dashboard data refreshed.', 'ghca-acd' ); ?>
        </div>
      <?php endif; ?>
      <?php if ( ! empty( $_GET['ghca_reminder_logged'] ) ) : ?>
        <div class="ghca-acd__flash ghca-acd__flash--success" role="status">
          <?php esc_html_e( 'Compliance reminder logged successfully.', 'ghca-acd' ); ?>
          <?php if ( GHCA_ACD_FluentCRM::is_active() ) : ?>
            <?php esc_html_e( 'A note was added to the FluentCRM contact when available.', 'ghca-acd' ); ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_login_gate( $atts = array() ): string {
    if ( self::can_view_dashboard() ) {
      return '';
    }

    ob_start();

    if ( ! is_user_logged_in() ) {
      $login_url = wp_login_url( get_permalink() ?: home_url( '/compliance-admin-dashboard/' ) );
      ?>
      <div class="ghca-acd ghca-acd--gate">
        <div class="ghca-acd__card ghca-acd__card--center">
          <h2><?php esc_html_e( 'Compliance Admin Dashboard', 'ghca-acd' ); ?></h2>
          <p><?php esc_html_e( 'Please log in with an authorized HR or compliance account to view employee training records.', 'ghca-acd' ); ?></p>
          <a class="ghca-acd__btn ghca-acd__btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'ghca-acd' ); ?></a>
        </div>
      </div>
      <?php
      return (string) ob_get_clean();
    }

    ?>
    <div class="ghca-acd ghca-acd--gate">
      <div class="ghca-acd__card ghca-acd__card--center ghca-acd__card--denied">
        <h2><?php esc_html_e( 'Access Denied', 'ghca-acd' ); ?></h2>
        <p><?php esc_html_e( 'Your account does not have permission to view the compliance admin dashboard. Contact your administrator if you believe this is an error.', 'ghca-acd' ); ?></p>
        <a class="ghca-acd__btn ghca-acd__btn--secondary" href="<?php echo esc_url( home_url( '/employee-dashboard/' ) ); ?>"><?php esc_html_e( 'Go to Employee Dashboard', 'ghca-acd' ); ?></a>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_header( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $header = self::get_header_layout();
    $logo   = GHCA_Dashboard_Branding::get_logo_url();
    $org    = GHCA_Dashboard_Branding::has_header_brand() ? GHCA_Dashboard_Branding::get_header_org_name() : '';

    $data          = GHCA_ACD_Data_Provider::get_aggregate();
    $total_users   = (int) ( $data['total_employees'] ?? 0 );
    $active_groups = count( GHCA_ACD_Data_Provider::get_group_options() );
    $sync_label    = sprintf(
      /* translators: 1: date word (Today), 2: time */
      __( '%1$s, %2$s', 'ghca-acd' ),
      __( 'Today', 'ghca-acd' ),
      wp_date( 'g:i A' )
    );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--header">
      <div class="ghca-acd__banner">
        <div class="ghca-acd__banner-copy">
          <?php if ( $logo || $org ) : ?>
            <div class="ghca-acd__brand">
              <?php if ( $logo ) : ?>
                <img class="ghca-acd__brand-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $org ); ?>" />
              <?php endif; ?>
              <?php if ( $org ) : ?>
                <span class="ghca-acd__brand-name"><?php echo esc_html( $org ); ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <p class="ghca-acd__eyebrow"><?php esc_html_e( 'Administrative Compliance Center', 'ghca-acd' ); ?></p>
          <h1><?php esc_html_e( 'Compliance Dashboard', 'ghca-acd' ); ?></h1>
          <p class="ghca-acd__banner-lead"><?php esc_html_e( 'Monitor employee training, compliance records, certificates, and onboarding status across your organization.', 'ghca-acd' ); ?></p>
          <div class="ghca-acd__banner-meta">
            <span class="ghca-acd__banner-meta-item">
              <span class="ghca-acd__banner-meta-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'time' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <?php esc_html_e( 'Last sync', 'ghca-acd' ); ?> <strong><?php echo esc_html( $sync_label ); ?></strong>
            </span>
            <span class="ghca-acd__banner-meta-item">
              <span class="ghca-acd__banner-meta-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <strong><?php echo esc_html( (string) $total_users ); ?></strong> <?php esc_html_e( 'total users', 'ghca-acd' ); ?>
            </span>
            <span class="ghca-acd__banner-meta-item">
              <span class="ghca-acd__banner-meta-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'groups' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <strong><?php echo esc_html( (string) $active_groups ); ?></strong> <?php esc_html_e( 'active groups', 'ghca-acd' ); ?>
            </span>
          </div>
        </div>
        <div class="ghca-acd__header-actions">
          <div class="ghca-acd__header-buttons">
            <?php foreach ( $header['buttons'] as $button ) : ?>
              <a
                class="ghca-acd__btn ghca-acd__btn--header ghca-acd__btn--header-<?php echo esc_attr( $button['variant'] ); ?>"
                href="<?php echo esc_url( $button['url'] ); ?>"
                <?php echo ! empty( $button['tab_jump'] ) ? ' data-ghca-tab-jump="' . esc_attr( (string) $button['tab_jump'] ) . '"' : ''; ?>
              >
                <span class="ghca-acd__btn-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( $button['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span><?php echo esc_html( $button['label'] ); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_kpis( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data = GHCA_ACD_Data_Provider::get_aggregate();
    $kpis = array(
      array(
        'label' => __( 'Total Employees', 'ghca-acd' ),
        'value' => $data['total_employees'] ?? 0,
        'icon'  => 'users',
        'sub'   => __( 'Active users in groups', 'ghca-acd' ),
      ),
      array(
        'label' => __( 'Required Courses', 'ghca-acd' ),
        'value' => count( $data['employees'][0]['courses'] ?? array() ),
        'icon'  => 'book',
        'sub'   => __( 'Mandatory this cycle', 'ghca-acd' ),
      ),
      array(
        'label' => __( 'In Progress', 'ghca-acd' ),
        'value' => $data['in_progress_employees'] ?? 0,
        'icon'  => 'time',
        'sub'   => __( 'Currently training', 'ghca-acd' ),
      ),
      array(
        'label'    => __( 'Overdue', 'ghca-acd' ),
        'value'    => $data['overdue_employees'] ?? 0,
        'icon'     => 'alert',
        'sub'      => __( 'Past due date', 'ghca-acd' ),
        'expiring' => $data['expiring_soon_employees'] ?? 0,
      ),
      array(
        'label' => __( 'Certificates Issued', 'ghca-acd' ),
        'value' => $data['certificates_issued'] ?? 0,
        'icon'  => 'award',
        'sub'   => __( 'All time', 'ghca-acd' ),
      ),
      array(
        'label' => __( 'Completion Rate', 'ghca-acd' ),
        'value' => ( $data['compliance_rate'] ?? 0 ) . '%',
        'icon'  => 'chart',
        'sub'   => __( 'Across all groups', 'ghca-acd' ),
      ),
    );
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--kpis">
      <div class="ghca-acd__grid ghca-acd__grid--6">
        <?php foreach ( $kpis as $kpi ) : ?>
          <div class="ghca-acd__card ghca-acd__stat-card<?php echo ! empty( $kpi['alert'] ) ? ' ghca-acd__card--alert' : ''; ?>">
            <div class="ghca-acd__stat-header-flex">
              <span class="ghca-acd__stat-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( (string) $kpi['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            </div>
            <strong class="ghca-acd__value<?php echo ! empty( $kpi['warning'] ) ? ' ghca-acd__value--warning' : ''; ?>"><?php echo esc_html( (string) $kpi['value'] ); ?></strong>
            <span class="ghca-acd__label"><?php echo esc_html( $kpi['label'] ); ?></span>
            <?php if ( ! empty( $kpi['sub'] ) ) : ?>
              <span class="ghca-acd__stat-sub"><?php echo esc_html( $kpi['sub'] ); ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $kpi['expiring'] ) ) : ?>
              <span class="ghca-acd__stat-sub ghca-acd__stat-sub--warning"><?php echo esc_html( sprintf( /* translators: %d: number of employees with a course expiring soon */ __( '+ %d expiring soon', 'ghca-acd' ), (int) $kpi['expiring'] ) ); ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_user_report( $atts = array() ): string {
    return GHCA_ACD_User_Report::render( $atts );
  }

  public static function render_overdue_employees( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $filters = GHCA_ACD_Data_Provider::get_priority_filters();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--priority" id="ghca-overdue-employees">
      <div class="ghca-acd__panel">
        <h2><?php esc_html_e( 'Overdue / At-Risk Employees', 'ghca-acd' ); ?></h2>
        <p class="ghca-acd__panel-intro"><?php esc_html_e( 'Employees who need attention first — overdue annual training, overdue new hire onboarding, or approaching deadlines.', 'ghca-acd' ); ?></p>
        <form class="ghca-acd__filters ghca-acd__filters--toolbar" method="get" data-ghca-filter-form data-ghca-table="priority">
          <input type="hidden" name="ghca_pri_page" value="<?php echo esc_attr( (string) $filters['page'] ); ?>" data-ghca-page-input />
          <input type="hidden" name="ghca_orderby" value="<?php echo esc_attr( (string) ( $filters['orderby'] ?? '' ) ); ?>" />
          <input type="hidden" name="ghca_order" value="<?php echo esc_attr( (string) ( $filters['order'] ?? 'asc' ) ); ?>" />
          <?php
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_pri_group', $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Priority', 'ghca-acd' ); ?></span>
            <select name="ghca_pri_type">
              <option value=""><?php esc_html_e( 'All priorities', 'ghca-acd' ); ?></option>
              <option value="overdue" <?php selected( $filters['priority'], 'overdue' ); ?>><?php esc_html_e( 'Overdue', 'ghca-acd' ); ?></option>
              <option value="at_risk" <?php selected( $filters['priority'], 'at_risk' ); ?>><?php esc_html_e( 'At risk', 'ghca-acd' ); ?></option>
            </select>
          </label>
          <?php
          echo GHCA_ACD_Table_UI::render_search_field( 'ghca_pri_search', $filters['search'], __( 'Search', 'ghca-acd' ), __( 'Name or email…', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_per_page_select( 'ghca_pri_per', $filters['per_page'], __( 'Per page', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_filter_actions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
        </form>
        <div class="ghca-acd__table-mount" data-ghca-table data-ghca-table-id="priority" aria-live="polite">
          <?php echo self::get_priority_table_html( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_course_overview( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $filters = GHCA_ACD_Data_Provider::get_course_filters();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--courses">
      <div class="ghca-acd__panel">
        <h2><?php esc_html_e( 'Course Completion Overview', 'ghca-acd' ); ?></h2>
        <form class="ghca-acd__filters ghca-acd__filters--toolbar" method="get" data-ghca-filter-form data-ghca-table="courses">
          <input type="hidden" name="ghca_crs_page" value="<?php echo esc_attr( (string) $filters['page'] ); ?>" data-ghca-page-input />
          <?php
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_crs_group', $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Certificate', 'ghca-acd' ); ?></span>
            <select name="ghca_crs_cert">
              <option value=""><?php esc_html_e( 'All courses', 'ghca-acd' ); ?></option>
              <option value="yes" <?php selected( $filters['certificate'], 'yes' ); ?>><?php esc_html_e( 'Certificate required', 'ghca-acd' ); ?></option>
              <option value="no" <?php selected( $filters['certificate'], 'no' ); ?>><?php esc_html_e( 'No certificate', 'ghca-acd' ); ?></option>
            </select>
          </label>
          <?php
          echo GHCA_ACD_Table_UI::render_search_field( 'ghca_crs_search', $filters['search'], __( 'Search', 'ghca-acd' ), __( 'Course name…', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_per_page_select( 'ghca_crs_per', $filters['per_page'], __( 'Per page', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_filter_actions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
        </form>
        <div class="ghca-acd__table-mount" data-ghca-table data-ghca-table-id="courses" aria-live="polite">
          <?php echo self::get_course_table_html( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_employee_table( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $filters = GHCA_ACD_Data_Provider::get_employee_filters();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--employees">
      <div class="ghca-acd__panel">
        <h2><?php esc_html_e( 'Employee Compliance Table', 'ghca-acd' ); ?></h2>
        <form class="ghca-acd__filters ghca-acd__filters--toolbar" method="get" data-ghca-filter-form data-ghca-table="employees">
          <input type="hidden" name="ghca_emp_page" value="<?php echo esc_attr( (string) $filters['page'] ); ?>" data-ghca-page-input />
          <input type="hidden" name="ghca_orderby" value="<?php echo esc_attr( (string) ( $filters['orderby'] ?? '' ) ); ?>" />
          <input type="hidden" name="ghca_order" value="<?php echo esc_attr( (string) ( $filters['order'] ?? 'asc' ) ); ?>" />
          <?php
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_group', $filters['group'], GHCA_ACD_Data_Provider::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Course', 'ghca-acd' ); ?></span>
            <select name="ghca_course">
              <option value=""><?php esc_html_e( 'All courses', 'ghca-acd' ); ?></option>
              <?php foreach ( GHCA_ACD_Data_Provider::get_course_options() as $cid => $label ) : ?>
                <option value="<?php echo esc_attr( (string) $cid ); ?>" <?php selected( $filters['course'], (string) $cid ); ?>><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Status', 'ghca-acd' ); ?></span>
            <select name="ghca_status">
              <option value=""><?php esc_html_e( 'All statuses', 'ghca-acd' ); ?></option>
              <?php foreach ( GHCA_ACD_Data_Provider::get_status_options() as $slug => $label ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="ghca-acd__filter-field ghca-acd__filter-check">
            <span><?php esc_html_e( 'Overdue', 'ghca-acd' ); ?></span>
            <span class="ghca-acd__filter-check-row">
              <input type="checkbox" name="ghca_overdue" value="1" <?php checked( $filters['overdue_only'] ); ?> />
              <span><?php esc_html_e( 'Overdue only', 'ghca-acd' ); ?></span>
            </span>
          </label>
          <?php
          echo GHCA_ACD_Table_UI::render_search_field( 'ghca_emp_search', $filters['search'], __( 'Search', 'ghca-acd' ), __( 'Name, email, or group…', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_per_page_select( 'ghca_emp_per', $filters['per_page'], __( 'Per page', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          echo GHCA_ACD_Table_UI::render_filter_actions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
        </form>
        <div class="ghca-acd__table-mount" data-ghca-table data-ghca-table-id="employees" aria-live="polite">
          <?php echo self::get_employee_table_html( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters */
  public static function get_employee_table_html( array $filters ): string {
    $all_rows = GHCA_ACD_Data_Provider::get_employee_table_rows( $filters );
    $paged    = GHCA_ACD_Table_UI::paginate( $all_rows, (int) $filters['page'], (int) $filters['per_page'] );
    $rows     = $paged['rows'];
    ob_start();
    ?>
    <div class="ghca-acd__table-wrap">
      <table class="ghca-acd__table ghca-acd__table--employees">
        <thead>
          <tr>
            <?php
            $orderby = $filters['orderby'] ?? '';
            $order   = $filters['order'] ?? 'asc';
            echo GHCA_ACD_Table_UI::render_sortable_header( 'name', __( 'Employee', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo GHCA_ACD_Table_UI::render_sortable_header( 'group', __( 'Group', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <th class="ghca-acd__text-center" title="<?php esc_attr_e( 'Completed / Required Courses', 'ghca-acd' ); ?>"><?php esc_html_e( 'Course Progress', 'ghca-acd' ); ?></th>
            <?php
            echo GHCA_ACD_Table_UI::render_sortable_header( 'progress_pct', __( 'Progress', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo GHCA_ACD_Table_UI::render_sortable_header( 'due_timestamp', __( 'Due Date', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <th><?php esc_html_e( 'Certificate', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Last Activity', 'ghca-acd' ); ?></th>
            <?php
            echo GHCA_ACD_Table_UI::render_sortable_header( 'status_slug', __( 'Status', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <th><?php esc_html_e( 'Action', 'ghca-acd' ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="9" class="ghca-acd__table-empty">
              <div class="ghca-acd__empty-state">
                <span class="ghca-acd__empty-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <p><?php esc_html_e( 'No employees match the current filters.', 'ghca-acd' ); ?></p>
                <button type="button" class="ghca-acd__btn ghca-acd__btn--ghost" data-ghca-filter-reset><?php esc_html_e( 'Clear filters', 'ghca-acd' ); ?></button>
              </div>
            </td></tr>
          <?php else : ?>
            <?php foreach ( $rows as $row ) : ?>
              <tr>
                <td>
                  <div class="ghca-acd__employee">
                    <span class="ghca-acd__avatar" style="<?php echo esc_attr( GHCA_ACD_Data_Provider::get_avatar_style( (int) $row['user_id'] ) ); ?>" aria-hidden="true"><?php echo esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( (string) $row['name'] ) ); ?></span>
                    <span class="ghca-acd__employee-id">
                      <span class="ghca-acd__employee-name"><?php echo esc_html( $row['name'] ); ?></span>
                      <a class="ghca-acd__employee-email" href="<?php echo esc_url( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
                    </span>
                  </div>
                </td>
                <td><?php echo esc_html( $row['group'] ); ?></td>
                <td class="ghca-acd__text-center"><?php echo esc_html( $row['completed_label'] ); ?></td>
                <td>
                  <div class="ghca-acd__progress">
                    <span class="ghca-acd__progress-track">
                      <span class="ghca-acd__progress-bar <?php echo esc_attr( GHCA_ACD_Data_Provider::get_progress_class( (int) $row['progress_pct'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['progress_pct'] ); ?>%"></span>
                    </span>
                    <span><?php echo esc_html( $row['progress_label'] ); ?></span>
                  </div>
                </td>
                <td><?php echo esc_html( $row['due_date_label'] ); ?></td>
                <td><?php echo esc_html( $row['certificate_label'] ); ?></td>
                <td><?php echo esc_html( $row['last_activity_label'] ); ?></td>
                <td><span class="ghca-acd__status ghca-acd__status--<?php echo esc_attr( $row['status_slug'] ); ?>"><?php echo esc_html( $row['status_label'] ); ?></span></td>
                <td class="ghca-acd__actions"><button type="button" class="ghca-acd__row-action" data-ghca-employee-drawer="<?php echo esc_attr( (string) $row['user_id'] ); ?>"><?php esc_html_e( 'View details', 'ghca-acd' ); ?></button></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    echo GHCA_ACD_Table_UI::render_pagination( $paged, 'ghca_emp_page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters */
  public static function get_priority_table_html( array $filters ): string {
    $all_rows = GHCA_ACD_Data_Provider::get_priority_rows( $filters );
    $paged    = GHCA_ACD_Table_UI::paginate( $all_rows, (int) $filters['page'], (int) $filters['per_page'] );
    $rows     = $paged['rows'];
    ob_start();

    if ( empty( $all_rows ) ) {
      ?>
      <div class="ghca-acd__empty-state">
        <span class="ghca-acd__empty-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <p class="ghca-acd__success"><?php esc_html_e( 'No overdue or at-risk employees match the current filters.', 'ghca-acd' ); ?></p>
        <button type="button" class="ghca-acd__btn ghca-acd__btn--ghost" data-ghca-filter-reset><?php esc_html_e( 'Clear filters', 'ghca-acd' ); ?></button>
      </div>
      <?php
      return (string) ob_get_clean();
    }
    ?>
    <div class="ghca-acd__table-wrap">
      <table class="ghca-acd__table ghca-acd__table--priority">
        <thead>
          <tr>
            <?php
            $orderby = $filters['orderby'] ?? '';
            $order   = $filters['order'] ?? 'asc';
            echo GHCA_ACD_Table_UI::render_sortable_header( 'name', __( 'Employee', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo GHCA_ACD_Table_UI::render_sortable_header( 'group', __( 'Group', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo GHCA_ACD_Table_UI::render_sortable_header( 'progress_pct', __( 'Progress', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <th><?php esc_html_e( 'Next Course', 'ghca-acd' ); ?></th>
            <?php
            echo GHCA_ACD_Table_UI::render_sortable_header( 'due_timestamp', __( 'Due Date', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo GHCA_ACD_Table_UI::render_sortable_header( 'status_slug', __( 'Status', 'ghca-acd' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <th><?php esc_html_e( 'Last Activity', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Action', 'ghca-acd' ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $rows as $row ) : ?>
            <tr class="<?php echo esc_attr( 'ghca-acd__row--' . $row['priority'] ); ?>">
              <td>
                <div class="ghca-acd__employee">
                  <span class="ghca-acd__avatar" style="<?php echo esc_attr( GHCA_ACD_Data_Provider::get_avatar_style( (int) $row['user_id'] ) ); ?>" aria-hidden="true"><?php echo esc_html( GHCA_ACD_Data_Provider::get_avatar_initials( (string) $row['name'] ) ); ?></span>
                  <span class="ghca-acd__employee-id">
                    <span class="ghca-acd__employee-name"><?php echo esc_html( $row['name'] ); ?></span>
                    <a class="ghca-acd__employee-email" href="<?php echo esc_url( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
                  </span>
                </div>
              </td>
              <td><?php echo esc_html( $row['group'] ); ?></td>
              <td>
                <div class="ghca-acd__progress">
                  <span class="ghca-acd__progress-track">
                    <span class="ghca-acd__progress-bar <?php echo esc_attr( GHCA_ACD_Data_Provider::get_progress_class( (int) $row['progress_pct'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['progress_pct'] ); ?>%"></span>
                  </span>
                  <span><?php echo esc_html( $row['progress_label'] ); ?></span>
                </div>
              </td>
              <td><?php echo esc_html( $row['next_course_label'] ); ?></td>
              <td><?php echo esc_html( $row['due_date_label'] ); ?></td>
              <td><span class="ghca-acd__status ghca-acd__status--<?php echo esc_attr( $row['status_slug'] ); ?>"><?php echo esc_html( $row['status_label'] ); ?></span></td>
              <td><?php echo esc_html( $row['last_activity_label'] ); ?></td>
              <td class="ghca-acd__actions"><?php echo wp_kses_post( $row['actions_html'] ); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    echo GHCA_ACD_Table_UI::render_pagination( $paged, 'ghca_pri_page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $filters */
  public static function get_course_table_html( array $filters ): string {
    $all_rows = GHCA_ACD_Data_Provider::get_course_overview_rows( $filters );
    $paged    = GHCA_ACD_Table_UI::paginate( $all_rows, (int) $filters['page'], (int) $filters['per_page'] );
    $rows     = $paged['rows'];
    ob_start();
    ?>
    <div class="ghca-acd__table-wrap">
      <table class="ghca-acd__table ghca-acd__table--courses">
        <thead>
          <tr>
            <th><?php esc_html_e( 'Course', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Assigned Group', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Required Users', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Completed', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'In Progress', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Not Started', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Completion Rate', 'ghca-acd' ); ?></th>
            <th><?php esc_html_e( 'Certificate', 'ghca-acd' ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="8" class="ghca-acd__table-empty">
              <div class="ghca-acd__empty-state">
                <span class="ghca-acd__empty-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <p><?php esc_html_e( 'No courses match the current filters.', 'ghca-acd' ); ?></p>
                <button type="button" class="ghca-acd__btn ghca-acd__btn--ghost" data-ghca-filter-reset><?php esc_html_e( 'Clear filters', 'ghca-acd' ); ?></button>
              </div>
            </td></tr>
          <?php else : ?>
            <?php foreach ( $rows as $row ) : ?>
              <tr>
                <td><?php echo esc_html( $row['course'] ); ?></td>
                <td><?php echo esc_html( $row['group'] ); ?></td>
                <td><?php echo esc_html( (string) $row['required'] ); ?></td>
                <td><?php echo esc_html( (string) $row['completed'] ); ?></td>
                <td><?php echo esc_html( (string) $row['in_progress'] ); ?></td>
                <td><?php echo esc_html( (string) $row['not_started'] ); ?></td>
                <td>
                  <div class="ghca-acd__progress">
                    <span class="ghca-acd__progress-track">
                      <span class="ghca-acd__progress-bar <?php echo esc_attr( GHCA_ACD_Data_Provider::get_progress_class( (int) $row['rate'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['rate'] ); ?>%"></span>
                    </span>
                    <span><?php echo esc_html( $row['rate_label'] ); ?></span>
                  </div>
                </td>
                <td><?php echo esc_html( $row['certificate_label'] ); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    echo GHCA_ACD_Table_UI::render_pagination( $paged, 'ghca_crs_page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return (string) ob_get_clean();
  }

  public static function render_certificate_tracking( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data     = GHCA_ACD_Data_Provider::get_aggregate();
    $cert_url = GHCA_ACD_Data_Provider::get_page_url( 'cert-download', '/cert-download/' );

    $cert_stats = array(
      array(
        'value' => (int) ( $data['certificates_available'] ?? 0 ),
        'label' => __( 'Issued', 'ghca-acd' ),
        'tone'  => 'success',
      ),
      array(
        'value' => (int) ( $data['eligible_for_certificate'] ?? 0 ),
        'label' => __( 'Pending issuance', 'ghca-acd' ),
        'tone'  => 'warning',
      ),
      array(
        'value' => (int) ( $data['certificates_missing'] ?? 0 ),
        'label' => __( 'Missing', 'ghca-acd' ),
        'tone'  => 'danger',
      ),
      array(
        'value' => (int) ( $data['recently_completed'] ?? 0 ),
        'label' => __( 'Completed (30 days)', 'ghca-acd' ),
        'tone'  => 'info',
      ),
    );
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--certificates">
      <div class="ghca-acd__panel">
        <div class="ghca-acd__section-head">
          <h2><?php esc_html_e( 'Certificates & Records', 'ghca-acd' ); ?></h2>
          <a class="ghca-acd__link-btn" href="<?php echo esc_url( $cert_url ); ?>"><?php esc_html_e( 'View all', 'ghca-acd' ); ?> &rarr;</a>
        </div>
        <div class="ghca-acd__cert-summary">
          <?php foreach ( $cert_stats as $stat ) : ?>
            <div class="ghca-acd__cert-stat ghca-acd__cert-stat--<?php echo esc_attr( $stat['tone'] ); ?>">
              <div class="ghca-acd__cert-stat-value"><?php echo esc_html( (string) $stat['value'] ); ?></div>
              <div class="ghca-acd__cert-stat-label"><?php echo esc_html( $stat['label'] ); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ( ! empty( $data['recent_completions'] ) ) : ?>
          <div class="ghca-acd__recent-head"><?php esc_html_e( 'Recent completions', 'ghca-acd' ); ?></div>
          <div class="ghca-acd__recent-list">
            <?php
            foreach ( array_slice( $data['recent_completions'], 0, 5 ) as $item ) :
              $ts         = (int) ( $item['ts'] ?? 0 );
              $time_label = $ts > 0
                ? sprintf( /* translators: %s: human-readable time difference */ __( '%s ago', 'ghca-acd' ), human_time_diff( $ts, time() ) )
                : __( 'Recently', 'ghca-acd' );
              ?>
              <div class="ghca-acd__recent-item">
                <div class="ghca-acd__recent-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <div class="ghca-acd__recent-info">
                  <p class="ghca-acd__recent-name"><?php echo esc_html( (string) ( $item['name'] ?? '' ) ); ?></p>
                  <p class="ghca-acd__recent-course"><?php echo esc_html( (string) ( $item['course'] ?? '' ) ); ?></p>
                </div>
                <span class="ghca-acd__recent-time"><?php echo esc_html( $time_label ); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_quick_links( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $links = self::get_quick_links();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--links">
      <div class="ghca-acd__panel">
        <h2><?php esc_html_e( 'Quick Admin Links', 'ghca-acd' ); ?></h2>
        <div class="ghca-acd__quick-links-grid">
          <?php foreach ( $links as $link ) : ?>
            <a class="ghca-acd__quick-link-card" href="<?php echo esc_url( $link['url'] ); ?>">
              <?php if ( ! empty( $link['icon'] ) ) : ?>
                <span class="ghca-acd__quick-link-icon"><?php echo GHCA_UI_Icons::render( (string) $link['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <?php endif; ?>
              <strong class="ghca-acd__quick-link-title"><?php echo esc_html( $link['label'] ); ?></strong>
              <?php if ( ! empty( $link['subtitle'] ) ) : ?>
                <span class="ghca-acd__quick-link-desc"><?php echo esc_html( $link['subtitle'] ); ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_support( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $email = GHCA_Dashboard_Branding::get_support_email();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--support">
      <div class="ghca-acd__support-banner">
        <div class="ghca-acd__support-banner-content">
          <div class="ghca-acd__support-icon">
            <?php echo GHCA_UI_Icons::render('mail'); ?>
          </div>
          <div class="ghca-acd__support-text">
            <h3><?php esc_html_e( 'Need help with compliance records or training access?', 'ghca-acd' ); ?></h3>
            <p><?php esc_html_e( 'Before contacting support, confirm the employee is enrolled in the correct LearnDash group and assigned the required courses.', 'ghca-acd' ); ?></p>
          </div>
        </div>
        <div class="ghca-acd__support-actions">
          <a class="ghca-acd__btn ghca-acd__btn--secondary" href="<?php echo esc_url( 'mailto:' . $email ); ?>">
             <?php esc_html_e('Contact Support', 'ghca-acd'); ?>
          </a>
          <a class="ghca-acd__btn ghca-acd__btn--secondary" href="#">
             <?php esc_html_e('View Admin Guide', 'ghca-acd'); ?>
          </a>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_reports( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $export_url  = wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' );
    $user_report = GHCA_ACD_Data_Provider::get_page_url( 'user-report', '/user-report/' );
    $cert_url    = GHCA_ACD_Data_Provider::get_page_url( 'cert-download', '/cert-download/' );
    $manage_url  = GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' );

    $reports = array(
      array(
        'title'  => __( 'Compliance Export', 'ghca-acd' ),
        'desc'   => __( 'Full employee compliance records with status, progress and due dates as a CSV file.', 'ghca-acd' ),
        'icon'   => 'download',
        'action' => __( 'Download CSV', 'ghca-acd' ),
        'url'    => $export_url,
      ),
      array(
        'title'  => __( 'User Training Report', 'ghca-acd' ),
        'desc'   => __( 'Per-employee training detail, quiz status and certificate history.', 'ghca-acd' ),
        'icon'   => 'file-report',
        'action' => __( 'Open report', 'ghca-acd' ),
        'url'    => $user_report,
      ),
      array(
        'title'  => __( 'Certificate Records', 'ghca-acd' ),
        'desc'   => __( 'Issued credentials and downloadable certificates across all groups.', 'ghca-acd' ),
        'icon'   => 'certificate',
        'action' => __( 'View certificates', 'ghca-acd' ),
        'url'    => $cert_url,
      ),
      array(
        'title'  => __( 'Manage Users', 'ghca-acd' ),
        'desc'   => __( 'Add, remove, or modify user enrollment and group assignments.', 'ghca-acd' ),
        'icon'   => 'users',
        'action' => __( 'Manage', 'ghca-acd' ),
        'url'    => $manage_url,
      ),
    );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--reports">
      <div class="ghca-acd__section-head">
        <h2><?php esc_html_e( 'Reports & Exports', 'ghca-acd' ); ?></h2>
      </div>
      <div class="ghca-acd__reports-grid">
        <?php foreach ( $reports as $report ) : ?>
          <div class="ghca-acd__report-card">
            <span class="ghca-acd__report-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( (string) $report['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <div class="ghca-acd__report-title"><?php echo esc_html( $report['title'] ); ?></div>
            <div class="ghca-acd__report-desc"><?php echo esc_html( $report['desc'] ); ?></div>
            <div class="ghca-acd__report-actions">
              <a class="ghca-acd__btn ghca-acd__btn--primary" href="<?php echo esc_url( $report['url'] ); ?>"><?php echo esc_html( $report['action'] ); ?></a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @return array{buttons:array<int,array{label:string,url:string,icon:string,variant:string,tab_jump?:string}>} */
  private static function get_header_layout(): array {
    $page_url = get_permalink() ?: home_url( '/compliance-admin-dashboard/' );

    return array(
      'buttons' => array(
        array(
          'label'    => __( 'View Reports', 'ghca-acd' ),
          'url'      => $page_url . '#ghca-tab-reports',
          'icon'     => 'line-chart',
          'variant'  => 'primary',
          'tab_jump' => 'ghca-tab-reports',
        ),
        array(
          'label'   => __( 'Export', 'ghca-acd' ),
          'url'     => wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_export_csv' ), 'ghca_acd_export_csv' ),
          'icon'    => 'export',
          'variant' => 'secondary',
        ),
        array(
          'label'   => __( 'Sync', 'ghca-acd' ),
          'url'     => wp_nonce_url( add_query_arg( 'ghca_acd_sync', '1', $page_url ), 'ghca_acd_sync', 'ghca_nonce' ),
          'icon'    => 'sync',
          'variant' => 'secondary',
        ),
        array(
          'label'   => __( 'Manage Users', 'ghca-acd' ),
          'url'     => GHCA_ACD_Roles::user_can_manage_users() ? GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' ) : ( current_user_can( 'edit_users' ) ? admin_url( 'users.php' ) : GHCA_ACD_Data_Provider::get_page_url( 'group-management', '/group-management/' ) ),
          'icon'    => 'user-plus',
          'variant' => 'secondary',
        ),
      ),
    );
  }

  /** @return array<int,array{label:string,url:string,icon?:string,subtitle?:string}> */
  private static function get_quick_links(): array {
    $links = array(
      array(
        'label'    => __( 'Courses', 'ghca-acd' ),
        'subtitle' => __( 'Manage course catalog', 'ghca-acd' ),
        'icon'     => 'courses',
        'url'      => current_user_can( 'manage_options' ) ? admin_url( 'edit.php?post_type=sfwd-courses' ) : GHCA_ACD_Data_Provider::get_page_url( 'my-courses', '/my-courses/' ),
      ),
      array(
        'label'    => __( 'Users', 'ghca-acd' ),
        'subtitle' => __( 'Employee accounts', 'ghca-acd' ),
        'icon'     => 'users',
        'url'      => GHCA_ACD_Roles::user_can_manage_users() ? GHCA_ACD_Data_Provider::get_page_url( 'manage-users', '/manage-users/' ) : ( current_user_can( 'edit_users' ) ? admin_url( 'users.php' ) : GHCA_ACD_Data_Provider::get_page_url( 'group-management', '/group-management/' ) ),
      ),
      array(
        'label'    => __( 'Reports', 'ghca-acd' ),
        'subtitle' => __( 'Training analytics', 'ghca-acd' ),
        'icon'     => 'reports',
        'url'      => GHCA_ACD_Data_Provider::get_page_url( 'reports', '/reports/' ),
      ),
      array(
        'label'    => __( 'Certificates', 'ghca-acd' ),
        'subtitle' => __( 'Issued credentials', 'ghca-acd' ),
        'icon'     => 'certificate',
        'url'      => GHCA_ACD_Data_Provider::get_page_url( 'cert-download', '/cert-download/' ),
      ),
      array(
        'label'    => __( 'Employee Dashboard', 'ghca-acd' ),
        'subtitle' => __( 'Learner home view', 'ghca-acd' ),
        'icon'     => 'dashboard',
        'url'      => GHCA_ACD_Data_Provider::get_page_url( 'employee-dashboard', '/employee-dashboard/' ),
      ),
      array(
        'label'    => __( 'Support', 'ghca-acd' ),
        'subtitle' => __( 'Get technical help', 'ghca-acd' ),
        'icon'     => 'support',
        'url'      => 'mailto:' . GHCA_Dashboard_Branding::get_support_email(),
      ),
    );

    return apply_filters( 'ghca_admin_quick_links', $links );
  }

  public static function build_certificate_link_html( string $url, string $course_title = '' ): string {
    if ( $url === '' ) {
      return '';
    }

    return sprintf(
      '<a href="%1$s" class="ghca-acd__cert-trigger" data-ghca-cert-url="%2$s" data-ghca-cert-title="%3$s">%4$s</a>',
      esc_url( $url ),
      esc_attr( $url ),
      esc_attr( $course_title ),
      esc_html__( 'Certificate', 'ghca-acd' )
    );
  }

  public static function build_user_report_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( GHCA_ACD_Data_Provider::get_page_url( 'cert-download', '/cert-download/' ) ) . '">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</a>',
    );
    $links = array_merge( $links, GHCA_ACD_FluentCRM::build_action_links( $user_id, $email ) );
    $links[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html__( 'Contact Employee', 'ghca-acd' ) . '</a>';

    if ( current_user_can( 'edit_users' ) ) {
      $links[] = '<a href="' . esc_url( get_edit_user_link( $user_id ) ?: admin_url( 'users.php' ) ) . '">' . esc_html__( 'User Profile', 'ghca-acd' ) . '</a>';
    }

    return '<div class="ghca-acd__action-chips">' . implode( '', $links ) . '</div>';
  }

  public static function build_employee_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $user_id ) ) . '">' . esc_html__( 'View Training', 'ghca-acd' ) . '</a>',
      '<a href="' . esc_url( GHCA_ACD_Data_Provider::get_page_url( 'cert-download', '/cert-download/' ) ) . '">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</a>',
    );
    $links = array_merge( $links, GHCA_ACD_FluentCRM::build_action_links( $user_id, $email ) );

    if ( current_user_can( 'edit_users' ) ) {
      $links[] = '<a href="' . esc_url( get_edit_user_link( $user_id ) ?: admin_url( 'users.php' ) ) . '">' . esc_html__( 'User Profile', 'ghca-acd' ) . '</a>';
    }

    return '<div class="ghca-acd__action-chips">' . implode( '', $links ) . '</div>';
  }

  public static function build_priority_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( GHCA_ACD_Data_Provider::get_user_report_url( $user_id ) ) . '">' . esc_html__( 'View User', 'ghca-acd' ) . '</a>',
    );
    $links = array_merge( $links, GHCA_ACD_FluentCRM::build_action_links( $user_id, $email ) );
    $links[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html__( 'Contact Employee', 'ghca-acd' ) . '</a>';
    return '<div class="ghca-acd__action-chips">' . implode( '', $links ) . '</div>';
  }

  public static function build_mailto_reminder( string $email, string $course_title = '' ): string {
    $subject = rawurlencode( __( 'Compliance training reminder', 'ghca-acd' ) );
    $body    = $course_title
      ? rawurlencode( sprintf( __( 'Please complete your required training: %s', 'ghca-acd' ), $course_title ) )
      : rawurlencode( __( 'Please complete your required compliance training as soon as possible.', 'ghca-acd' ) );
    return 'mailto:' . rawurlencode( $email ) . '?subject=' . $subject . '&body=' . $body;
  }
}
