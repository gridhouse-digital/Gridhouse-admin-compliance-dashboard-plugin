<?php
/**
 * Plugin Name: Gridhouse Admin Compliance Dashboard
 * Description: HR/compliance administrative dashboard shortcodes for LearnDash + Elementor.
 * Version: 1.1.0
 * Author: Gridhouse Digital
 * Author URI: https://gridhouse.digital
 * Text Domain: ghca-acd
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once __DIR__ . '/includes/class-branding.php';
require_once __DIR__ . '/includes/class-ui-icons.php';
require_once __DIR__ . '/includes/class-compliance-program.php';
require_once __DIR__ . '/includes/class-course-lifespans.php';
require_once __DIR__ . '/includes/class-roles.php';
require_once __DIR__ . '/includes/class-scoping.php';
require_once __DIR__ . '/includes/class-export.php';
require_once __DIR__ . '/includes/class-fluentcrm.php';
require_once __DIR__ . '/includes/class-nav.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-user-report.php';
require_once __DIR__ . '/includes/class-table-ui.php';

final class GHCA_Admin_Compliance_Dashboard {
  const VERSION         = '1.1.0';
  const OPTION_DUE_DATE = 'ghca_compliance_due_date';
  const CYCLE_DAYS      = 365;
  const NOTICE_DAYS     = 90;
  const AT_RISK_DAYS    = 30;

  /** Allowed column keys for client-supplied orderby to prevent arbitrary array key access. */
  const SORTABLE_COLUMNS = array(
    'name',
    'group',
    'progress_pct',
    'due_timestamp',
    'status_slug',
  );

  /** @var array<string,mixed>|null */
  private static $aggregate = null;

  public static function init(): void {
    GHCA_ACD_Roles::init();
    GHCA_ACD_Scoping::init();
    GHCA_ACD_Export::init();
    GHCA_ACD_FluentCRM::init();
    GHCA_ACD_Nav::init();
    GHCA_ACD_Settings::init();
    GHCA_Compliance_Program::init();
    GHCA_ACD_User_Report::init();
    add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
    add_action( 'init', array( __CLASS__, 'register_announce_cpt' ), 4 );
    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );
    add_action( 'init', array( __CLASS__, 'register_roles_on_activation' ), 20 );
    add_action( 'wp_ajax_ghca_acd_filter_table', array( __CLASS__, 'ajax_filter_table' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_certificate_modal' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_employee_drawer_modal' ) );
    add_action( 'wp_ajax_ghca_acd_get_employee_drawer', array( __CLASS__, 'ajax_get_employee_drawer' ) );
    add_action( 'wp_ajax_ghca_acd_mark_reviewed', array( __CLASS__, 'ajax_mark_reviewed' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_edit_records_modal' ) );
    add_action( 'wp_ajax_ghca_acd_get_edit_records_form', array( __CLASS__, 'ajax_get_edit_records_form' ) );
    add_action( 'wp_ajax_ghca_acd_save_employee_records', array( __CLASS__, 'ajax_save_employee_records' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_announcement_modal' ) );
    add_action( 'wp_ajax_ghca_acd_save_announcement', array( __CLASS__, 'ajax_save_announcement' ) );
    add_action( 'wp_ajax_ghca_acd_delete_announcement', array( __CLASS__, 'ajax_delete_announcement' ) );
    add_action( 'wp_ajax_ghca_acd_get_announcements', array( __CLASS__, 'ajax_get_announcements' ) );
    add_action( 'init', array( __CLASS__, 'handle_sync_request' ), 15 );
    // add_action( 'ghca_acd_new_announcement_published', array( __CLASS__, 'process_buddyboss_notifications' ), 10, 3 );
    // add_filter( 'bp_notifications_get_notifications_for_user', array( __CLASS__, 'format_buddypress_notifications' ), 10, 5 );
  }

  /** @param array<int,string> $classes */
  public static function add_body_class( array $classes ): array {
    if ( ! is_singular() ) {
      return $classes;
    }

    $post = get_post();
    if ( $post && self::page_uses_dashboard( $post ) ) {
      $classes[] = 'ghca-acd-page';
    }

    return $classes;
  }

  public static function register_roles_on_activation(): void {
    GHCA_ACD_Roles::register_roles();
  }

  public static function register_shortcodes(): void {
    $map = array(
      'admin_compliance_login_gate'         => 'render_login_gate',
      'admin_compliance_header'             => 'render_header',
      'admin_compliance_kpis'               => 'render_kpis',
      'admin_overdue_employees'             => 'render_overdue_employees',
      'admin_course_completion_overview'    => 'render_course_overview',
      'admin_employee_compliance_table'     => 'render_employee_table',
      'admin_certificate_tracking'          => 'render_certificate_tracking',
      'admin_compliance_announcements'      => 'render_announcements',
      'admin_compliance_quick_links'        => 'render_quick_links',
      'admin_compliance_support'            => 'render_support',
      'admin_compliance_dashboard'          => 'render_full_dashboard',
      'admin_compliance_user_report'        => 'render_user_report',
    );

    foreach ( $map as $tag => $method ) {
      add_shortcode( $tag, array( __CLASS__, $method ) );
    }
  }

  public static function enqueue_assets(): void {
    if ( ! is_singular() ) {
      return;
    }

    $post = get_post();
    if ( ! $post || ! self::page_uses_dashboard( $post ) ) {
      return;
    }

    wp_enqueue_style(
      'ghca-acd-fonts',
      'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
      array(),
      null
    );

    wp_enqueue_style(
      'ghca-admin-dashboard',
      plugin_dir_url( __FILE__ ) . 'assets/dashboard.css',
      array( 'ghca-acd-fonts' ),
      self::VERSION
    );
    wp_add_inline_style( 'ghca-admin-dashboard', GHCA_Dashboard_Branding::get_inline_css() );

    wp_enqueue_script(
      'ghca-admin-dashboard',
      plugin_dir_url( __FILE__ ) . 'assets/dashboard.js',
      array(),
      self::VERSION,
      true
    );

    wp_localize_script(
      'ghca-admin-dashboard',
      'ghcaAcd',
      array(
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'ghca_acd_table' ),
        'loading'          => __( 'Updating…', 'ghca-acd' ),
        'certModalTitle'   => __( 'Certificate', 'ghca-acd' ),
        'certDownload'     => __( 'Download', 'ghca-acd' ),
        'certClose'        => __( 'Close', 'ghca-acd' ),
        'certLoading'      => __( 'Loading certificate…', 'ghca-acd' ),
      )
    );
  }

  public static function render_certificate_modal(): void {
    if ( ! is_singular() ) {
      return;
    }

    $post = get_post();
    if ( ! $post || ! self::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__cert-modal" id="ghca-acd-cert-modal" hidden aria-hidden="true">
      <div class="ghca-acd__cert-modal-backdrop" data-ghca-cert-close></div>
      <div class="ghca-acd__cert-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-cert-modal-title">
        <div class="ghca-acd__cert-modal-header">
          <h2 id="ghca-acd-cert-modal-title"><?php esc_html_e( 'Certificate', 'ghca-acd' ); ?></h2>
          <button type="button" class="ghca-acd__cert-modal-close" data-ghca-cert-close aria-label="<?php esc_attr_e( 'Close certificate preview', 'ghca-acd' ); ?>">&times;</button>
        </div>
        <div class="ghca-acd__cert-modal-body">
          <p class="ghca-acd__cert-modal-loading"><?php esc_html_e( 'Loading certificate…', 'ghca-acd' ); ?></p>
          <iframe class="ghca-acd__cert-frame" title="<?php esc_attr_e( 'Certificate preview', 'ghca-acd' ); ?>" hidden></iframe>
        </div>
        <div class="ghca-acd__cert-modal-footer">
          <button type="button" class="ghca-acd__cert-btn ghca-acd__cert-btn--download" data-ghca-cert-download><?php esc_html_e( 'Download', 'ghca-acd' ); ?></button>
          <button type="button" class="ghca-acd__cert-btn ghca-acd__cert-btn--close" data-ghca-cert-close><?php esc_html_e( 'Close', 'ghca-acd' ); ?></button>
        </div>
      </div>
    </div>
    <?php
  }

  public static function render_employee_drawer_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! self::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__drawer ghca-acd__drawer--employee" id="ghca-acd-employee-drawer" hidden aria-hidden="true">
      <div class="ghca-acd__drawer-backdrop" data-ghca-drawer-close></div>
      <div class="ghca-acd__drawer-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-employee-drawer-title">
        <div class="ghca-acd__drawer-header">
          <span class="ghca-acd__drawer-header-label" id="ghca-acd-employee-drawer-title"><?php esc_html_e( 'Employee Detail', 'ghca-acd' ); ?></span>
          <button type="button" class="ghca-acd__drawer-close" data-ghca-drawer-close aria-label="<?php esc_attr_e( 'Close drawer', 'ghca-acd' ); ?>">&times;</button>
        </div>
        <div class="ghca-acd__drawer-body" id="ghca-acd-employee-drawer-body">
          <div class="ghca-acd__drawer-loading"><?php esc_html_e( 'Loading employee data…', 'ghca-acd' ); ?></div>
        </div>
      </div>
    </div>
    <?php
  }

  public static function ajax_get_employee_drawer(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Roles::user_can_view() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
    }

    $employee = self::get_employee_record( $user_id );
    if ( empty( $employee['user_id'] ) ) {
      wp_send_json_error( array( 'message' => __( 'Employee not found.', 'ghca-acd' ) ) );
    }

    // Build initials for avatar
    $name_parts = explode( ' ', $employee['name'] );
    $initials   = '';
    foreach ( $name_parts as $part ) {
      $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
      if ( strlen( $initials ) >= 2 ) break;
    }

    // Determine status class
    $status_slug  = $employee['status_slug'] ?? 'not_started';
    $status_label = $employee['status_label'] ?? '';
    $group_label  = $employee['group'] ?? '';

    // Certificate summary
    $cert_count  = 0;
    $cert_total  = count( $employee['courses'] ?? array() );
    foreach ( $employee['courses'] ?? array() as $course ) {
      if ( ! empty( $course['certificate_url'] ) ) {
        $cert_count++;
      }
    }
    $cert_status_label = $cert_count > 0 ? sprintf( 'Cert: %d/%d', $cert_count, $cert_total ) : 'Cert: Missing';
    $cert_badge_class  = $cert_count > 0 ? ( $cert_count >= $cert_total ? 'cert-issued' : 'cert-pending' ) : 'cert-missing';

    // KPI values
    $progress_pct   = $employee['progress_pct'] ?? 0;
    $completed_count = 0;
    $total_courses   = count( $employee['courses'] ?? array() );
    foreach ( $employee['courses'] ?? array() as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        $completed_count++;
      }
    }
    $due_date_label = $employee['due_date_label'] ?? '—';

    // KPI color classes
    $pct_class = 'danger';
    if ( $progress_pct >= 80 ) {
      $pct_class = 'success';
    } elseif ( $progress_pct >= 40 ) {
      $pct_class = 'warning';
    }

    // Report URL
    $report_url = self::get_user_report_url( $user_id );

    // Review status (admin "Mark Reviewed")
    $review = self::get_review_status( $user_id );

    ob_start();
    ?>
    <!-- Profile Section -->
    <div class="ghca-acd__drawer-profile">
      <div class="ghca-acd__drawer-avatar"><?php echo esc_html( $initials ); ?></div>
      <h3><?php echo esc_html( $employee['name'] ); ?></h3>
      <p class="ghca-acd__drawer-email"><?php echo esc_html( $employee['email'] ); ?></p>
      <div class="ghca-acd__drawer-badges">
        <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--<?php echo esc_attr( $status_slug ); ?>"><?php echo esc_html( $status_label ); ?></span>
        <?php if ( $group_label ) : ?>
          <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--group"><?php echo esc_html( $group_label ); ?></span>
        <?php endif; ?>
        <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--<?php echo esc_attr( $cert_badge_class ); ?>"><?php echo esc_html( $cert_status_label ); ?></span>
        <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--reviewed" data-ghca-review-badge<?php echo $review['reviewed'] ? '' : ' hidden'; ?>><?php echo esc_html( $review['badge'] ); ?></span>
      </div>
    </div>

    <!-- KPI Row -->
    <div class="ghca-acd__drawer-kpis">
      <div class="ghca-acd__drawer-kpi">
        <div class="ghca-acd__drawer-kpi-value ghca-acd__drawer-kpi-value--<?php echo esc_attr( $pct_class ); ?>"><?php echo esc_html( $progress_pct . '%' ); ?></div>
        <div class="ghca-acd__drawer-kpi-label"><?php esc_html_e( 'Overall', 'ghca-acd' ); ?></div>
      </div>
      <div class="ghca-acd__drawer-kpi">
        <div class="ghca-acd__drawer-kpi-value"><?php echo esc_html( $completed_count . '/' . $total_courses ); ?></div>
        <div class="ghca-acd__drawer-kpi-label"><?php esc_html_e( 'Courses Done', 'ghca-acd' ); ?></div>
      </div>
      <div class="ghca-acd__drawer-kpi">
        <div class="ghca-acd__drawer-kpi-value ghca-acd__drawer-kpi-value--<?php echo esc_attr( in_array( $status_slug, array( 'overdue', 'new_hire_overdue' ), true ) ? 'danger' : '' ); ?>"><?php echo esc_html( $due_date_label ); ?></div>
        <div class="ghca-acd__drawer-kpi-label"><?php esc_html_e( 'Due Date', 'ghca-acd' ); ?></div>
      </div>
    </div>

    <!-- Course Cards -->
    <div class="ghca-acd__drawer-courses">
      <div class="ghca-acd__drawer-section-title"><?php esc_html_e( 'Required Courses & Quiz Status', 'ghca-acd' ); ?></div>
      <?php if ( empty( $employee['courses'] ) ) : ?>
        <p style="color: var(--ent-muted); font-size: 13px;"><?php esc_html_e( 'No required courses assigned.', 'ghca-acd' ); ?></p>
      <?php else : ?>
        <div class="ghca-acd__drawer-courses-grid">
        <?php foreach ( $employee['courses'] as $course ) :
          $cstate = (string) ( $course['compliance_state'] ?? '' );
          if ( 'expired' === $cstate ) {
            $course_status = 'expired';
          } elseif ( 'expiring_soon' === $cstate ) {
            $course_status = 'expiring_soon';
          } else {
            $course_status = ! empty( $course['completed'] ) ? 'completed' : (string) ( $course['status'] ?? 'not_started' );
          }
          $course_pct    = (int) ( $course['progress'] ?? 0 );
          $bar_class     = 'info';
          if ( $course_status === 'completed' ) { $bar_class = 'success'; }
          elseif ( $course_status === 'expired' )       { $bar_class = 'danger'; }
          elseif ( $course_status === 'expiring_soon' ) { $bar_class = 'warning'; }
          elseif ( $course_status === 'overdue' )       { $bar_class = 'danger'; }
          elseif ( $course_pct > 0 )                    { $bar_class = 'info'; }
          else                                          { $bar_class = 'danger'; }

          $status_labels = array(
            'completed'     => __( 'Compliant', 'ghca-acd' ),
            'expiring_soon' => __( 'Expiring Soon', 'ghca-acd' ),
            'expired'       => __( 'Expired', 'ghca-acd' ),
            'in_progress'   => __( 'In Progress', 'ghca-acd' ),
            'overdue'       => __( 'Overdue', 'ghca-acd' ),
            'not_started'   => __( 'Not Started', 'ghca-acd' ),
          );
          $display_status = $status_labels[ $course_status ] ?? $course['status_label'] ?? ucfirst( str_replace( '_', ' ', $course_status ) );
        ?>
          <div class="ghca-acd__drawer-course">
            <div class="ghca-acd__drawer-course-header">
              <span class="ghca-acd__drawer-course-name"><?php echo esc_html( $course['title'] ); ?></span>
              <span class="ghca-acd__drawer-course-status ghca-acd__drawer-course-status--<?php echo esc_attr( $course_status ); ?>"><?php echo esc_html( $display_status ); ?></span>
            </div>
            <div class="ghca-acd__drawer-course-progress">
              <div class="ghca-acd__drawer-course-bar">
                <div class="ghca-acd__drawer-course-bar-fill ghca-acd__drawer-course-bar-fill--<?php echo esc_attr( $bar_class ); ?>" style="width: <?php echo esc_attr( (string) $course_pct ); ?>%"></div>
              </div>
              <span class="ghca-acd__drawer-course-pct"><?php echo esc_html( $course_pct . '%' ); ?></span>
            </div>
            <div class="ghca-acd__drawer-course-quiz"><?php esc_html_e( 'Quiz:', 'ghca-acd' ); ?> <?php echo esc_html( ! empty( $course['completed'] ) ? __( 'Passed', 'ghca-acd' ) : '—' ); ?></div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Action Buttons -->
    <div class="ghca-acd__drawer-actions">
      <a href="<?php echo esc_url( $report_url ); ?>" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary ghca-acd__drawer-action--full"><?php esc_html_e( 'Open Compliance Report', 'ghca-acd' ); ?></a>
      <div class="ghca-acd__drawer-actions-row">
        <a href="<?php echo esc_url( $report_url ); ?>" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary"><?php esc_html_e( 'View Certificates', 'ghca-acd' ); ?></a>
        <a href="<?php echo esc_url( 'mailto:' . $employee['email'] . '?subject=' . rawurlencode( __( 'Compliance Reminder', 'ghca-acd' ) ) ); ?>" class="ghca-acd__drawer-action ghca-acd__drawer-action--danger"><?php esc_html_e( 'Send Reminder', 'ghca-acd' ); ?></a>
      </div>
      <button type="button" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary ghca-acd__drawer-action--full" data-ghca-edit-records="<?php echo esc_attr( (string) $user_id ); ?>" data-ghca-edit-records-name="<?php echo esc_attr( $employee['name'] ); ?>"><?php esc_html_e( 'Edit Records', 'ghca-acd' ); ?></button>
      <button type="button" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary ghca-acd__drawer-action--full" data-ghca-mark-reviewed="<?php echo esc_attr( (string) $user_id ); ?>"><?php esc_html_e( 'Mark Reviewed', 'ghca-acd' ); ?></button>
      <p class="ghca-acd__drawer-review<?php echo $review['reviewed'] ? '' : ' is-empty'; ?>" data-ghca-review-status>
        <span class="ghca-acd__drawer-review-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <span data-ghca-review-text><?php echo esc_html( $review['line'] ); ?></span>
      </p>
    </div>
    <?php
    $html = (string) ob_get_clean();
    wp_send_json_success( array( 'html' => $html, 'name' => $employee['name'] ) );
  }

  /**
   * Reads the admin review state for a user.
   *
   * @return array{reviewed:bool,badge:string,line:string}
   */
  private static function get_review_status( int $user_id ): array {
    $ts = (int) get_user_meta( $user_id, 'ghca_acd_reviewed_at', true );
    if ( $ts <= 0 ) {
      return array( 'reviewed' => false, 'badge' => '', 'line' => __( 'Not reviewed yet', 'ghca-acd' ) );
    }

    $by_id = (int) get_user_meta( $user_id, 'ghca_acd_reviewed_by', true );
    $by    = $by_id > 0 ? self::get_user_full_name( $by_id ) : __( 'an admin', 'ghca-acd' );
    $date  = wp_date( (string) get_option( 'date_format', 'M j, Y' ), $ts );

    return array(
      'reviewed' => true,
      'badge'    => sprintf( /* translators: %s: short date */ __( 'Reviewed %s', 'ghca-acd' ), wp_date( 'M j', $ts ) ),
      'line'     => sprintf( /* translators: 1: date, 2: reviewer name */ __( 'Reviewed on %1$s by %2$s', 'ghca-acd' ), $date, $by ),
    );
  }

  /** Persists an admin "reviewed" marker for an employee. */
  public static function ajax_mark_reviewed(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Roles::user_can_view() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
    }

    update_user_meta( $user_id, 'ghca_acd_reviewed_at', time() );
    update_user_meta( $user_id, 'ghca_acd_reviewed_by', get_current_user_id() );

    $review = self::get_review_status( $user_id );

    wp_send_json_success( array(
      'badge'   => $review['badge'],
      'line'    => $review['line'],
      'message' => __( 'Marked as reviewed.', 'ghca-acd' ),
    ) );
  }

  /* ---------------------------------------------------------------------
   * Edit Records (manual admin override of registration + course records)
   * ------------------------------------------------------------------- */

  /** Empty modal shell, rendered in wp_footer like the certificate modal. */
  public static function render_edit_records_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! self::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__edit-modal" id="ghca-acd-edit-modal" hidden aria-hidden="true">
      <div class="ghca-acd__edit-modal-backdrop" data-ghca-edit-close></div>
      <div class="ghca-acd__edit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-edit-modal-title">
        <div class="ghca-acd__edit-modal-header">
          <h2 id="ghca-acd-edit-modal-title"><?php esc_html_e( 'Edit Records', 'ghca-acd' ); ?></h2>
          <button type="button" class="ghca-acd__edit-modal-close" data-ghca-edit-close aria-label="<?php esc_attr_e( 'Close edit records', 'ghca-acd' ); ?>">&times;</button>
        </div>
        <div class="ghca-acd__edit-modal-body" id="ghca-acd-edit-modal-body">
          <p class="ghca-acd__edit-loading"><?php esc_html_e( 'Loading records…', 'ghca-acd' ); ?></p>
        </div>
      </div>
    </div>
    <?php
  }

  /** Builds the editable form for one user (registration date + per-course rows). */
  public static function ajax_get_edit_records_form(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Roles::user_can_view() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
      wp_send_json_error( array( 'message' => __( 'Employee not found.', 'ghca-acd' ) ) );
    }

    $employee = self::get_employee_record( $user_id );

    // user_registered is stored as GMT; convert to a site-local value for the picker.
    $registered_local = '';
    if ( ! empty( $user->user_registered ) ) {
      $reg_ts           = (int) get_date_from_gmt( $user->user_registered, 'U' );
      $registered_local = wp_date( 'Y-m-d\TH:i', $reg_ts );
    }

    ob_start();
    ?>
    <form class="ghca-acd__edit-form" data-ghca-edit-form data-user-id="<?php echo esc_attr( (string) $user_id ); ?>">
      <div class="ghca-acd__edit-section">
        <label class="ghca-acd__edit-field">
          <span class="ghca-acd__edit-label"><?php esc_html_e( 'Registration Date', 'ghca-acd' ); ?></span>
          <input type="datetime-local" name="registration_date" value="<?php echo esc_attr( $registered_local ); ?>" class="ghca-acd__edit-input" />
        </label>
        <p class="ghca-acd__edit-hint"><?php esc_html_e( 'Overrides the WordPress account creation date used by transcripts and certificates.', 'ghca-acd' ); ?></p>
      </div>

      <div class="ghca-acd__edit-section">
        <div class="ghca-acd__edit-section-title"><?php esc_html_e( 'Course Completion & Time Spent', 'ghca-acd' ); ?></div>
        <?php if ( empty( $employee['courses'] ) ) : ?>
          <p class="ghca-acd__edit-hint"><?php esc_html_e( 'No assigned courses.', 'ghca-acd' ); ?></p>
        <?php else : foreach ( $employee['courses'] as $course ) :
          $cid          = (int) $course['id'];
          $completed    = ! empty( $course['completed'] );
          $completed_ts = (int) ( $course['completed_ts'] ?? 0 );
          $started_ts   = self::get_course_activity_started( $user_id, $cid );

          $completed_local = $completed_ts > 0 ? wp_date( 'Y-m-d\TH:i', $completed_ts ) : '';
          $minutes         = 0;
          if ( $completed_ts > 0 && $started_ts > 0 && $completed_ts > $started_ts ) {
            $minutes = (int) round( ( $completed_ts - $started_ts ) / 60 );
          }
        ?>
          <div class="ghca-acd__edit-course" data-course-id="<?php echo esc_attr( (string) $cid ); ?>">
            <div class="ghca-acd__edit-course-head">
              <span class="ghca-acd__edit-course-name"><?php echo esc_html( $course['title'] ); ?></span>
              <span class="ghca-acd__edit-course-state ghca-acd__edit-course-state--<?php echo $completed ? 'done' : 'pending'; ?>"><?php echo esc_html( $completed ? __( 'Completed', 'ghca-acd' ) : __( 'Incomplete', 'ghca-acd' ) ); ?></span>
            </div>
            <div class="ghca-acd__edit-course-grid">
              <label class="ghca-acd__edit-field">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Completion Date', 'ghca-acd' ); ?></span>
                <input type="datetime-local" name="course[<?php echo esc_attr( (string) $cid ); ?>][completed]" value="<?php echo esc_attr( $completed_local ); ?>" class="ghca-acd__edit-input" />
              </label>
              <label class="ghca-acd__edit-field">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Time Spent (min)', 'ghca-acd' ); ?></span>
                <input type="number" min="0" step="1" inputmode="numeric" name="course[<?php echo esc_attr( (string) $cid ); ?>][minutes]" value="<?php echo esc_attr( (string) $minutes ); ?>" class="ghca-acd__edit-input" />
              </label>
            </div>
            <label class="ghca-acd__edit-check">
              <input type="checkbox" name="course[<?php echo esc_attr( (string) $cid ); ?>][mark_complete]" value="1" <?php checked( $completed ); ?> />
              <span><?php esc_html_e( 'Mark this course complete', 'ghca-acd' ); ?></span>
            </label>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="ghca-acd__edit-form-footer">
        <button type="button" class="ghca-acd__edit-btn ghca-acd__edit-btn--ghost" data-ghca-edit-close><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
        <button type="submit" class="ghca-acd__edit-btn ghca-acd__edit-btn--save"><?php esc_html_e( 'Save Changes', 'ghca-acd' ); ?></button>
      </div>
    </form>
    <?php
    wp_send_json_success( array( 'html' => (string) ob_get_clean(), 'name' => $employee['name'] ) );
  }

  /** Processes the submitted overrides and syncs them into LearnDash. */
  public static function ajax_save_employee_records(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Roles::user_can_edit_records() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ) );
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
      wp_send_json_error( array( 'message' => __( 'Employee not found.', 'ghca-acd' ) ) );
    }

    $now     = time();
    $changes = array();
    $errors  = array();

    // --- Registration date ---------------------------------------------------
    $registered_ts = (int) get_date_from_gmt( $user->user_registered, 'U' );
    $reg_raw       = isset( $_POST['registration_date'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_date'] ) ) : '';
    if ( $reg_raw !== '' ) {
      $reg_ts = self::parse_local_datetime( $reg_raw );
      if ( $reg_ts <= 0 ) {
        $errors[] = __( 'Registration date is invalid.', 'ghca-acd' );
      } elseif ( $reg_ts !== $registered_ts ) {
        self::update_user_registered( $user_id, $reg_ts );
        $registered_ts = $reg_ts;
        $changes[]     = __( 'Registration date', 'ghca-acd' );
      }
    }

    // --- Courses -------------------------------------------------------------
    $course_input = ( isset( $_POST['course'] ) && is_array( $_POST['course'] ) ) ? wp_unslash( $_POST['course'] ) : array();
    $enrolled     = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );

    foreach ( $course_input as $cid => $row ) {
      $cid = (int) $cid;
      // Ignore unknown or forged course IDs the user is not actually enrolled in.
      if ( $cid <= 0 || ! in_array( $cid, $enrolled, true ) || ! is_array( $row ) ) {
        continue;
      }

      $completion_raw = isset( $row['completed'] ) ? sanitize_text_field( (string) $row['completed'] ) : '';
      $minutes        = isset( $row['minutes'] ) ? max( 0, (int) $row['minutes'] ) : 0;
      $mark_complete  = ! empty( $row['mark_complete'] );
      $already        = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $cid ) : false;
      $completion_ts  = $completion_raw !== '' ? self::parse_local_datetime( $completion_raw ) : 0;

      // ---- Compliance policy (see validate_course_edit) --------------------
      $decision = self::validate_course_edit( array(
        'completion_ts'    => $completion_ts,
        'minutes'          => $minutes,
        'mark_complete'    => $mark_complete,
        'already_complete' => $already,
        'registered_ts'    => $registered_ts,
        'now'              => $now,
      ) );

      if ( null === $decision ) {
        continue; // Skip this row silently (e.g. nothing entered).
      }
      if ( is_wp_error( $decision ) ) {
        $errors[] = get_the_title( $cid ) . ': ' . $decision->get_error_message();
        continue;
      }

      // Approved: back-calculate the start instant and persist the window.
      $started_ts = max( 0, $completion_ts - ( $minutes * 60 ) );
      self::apply_course_completion( $user_id, $cid, $started_ts, $completion_ts, ( $mark_complete && ! $already ) );
      $changes[] = get_the_title( $cid );
    }

    self::bust_cache();

    if ( ! empty( $errors ) && empty( $changes ) ) {
      wp_send_json_error( array( 'message' => implode( ' ', $errors ) ) );
    }

    $message = empty( $changes )
      ? __( 'No changes were applied.', 'ghca-acd' )
      : sprintf( __( 'Updated: %s', 'ghca-acd' ), implode( ', ', array_slice( $changes, 0, 8 ) ) );

    if ( ! empty( $errors ) ) {
      $message .= ' ' . __( 'Skipped:', 'ghca-acd' ) . ' ' . implode( ' ', $errors );
    }

    wp_send_json_success( array( 'message' => $message ) );
  }

  /**
   * Decide whether one submitted course edit should be applied.
   *
   * This is the compliance-policy gate for the Edit Records tool. It runs once
   * per course row, BEFORE any LearnDash data is written, so it is the safe
   * place to encode the rules your auditors care about. The plumbing around it
   * (timestamp maths, LearnDash sync, cache busting) is already handled — this
   * function only decides go / skip / reject.
   *
   * @param array{
   *   completion_ts: int,    // Submitted completion date as a UTC unix timestamp (0 if the field was left blank).
   *   minutes: int,          // Submitted "time spent" in minutes (already floored to >= 0).
   *   mark_complete: bool,   // Whether the admin ticked "Mark this course complete".
   *   already_complete: bool,// LearnDash's CURRENT completion state for this course.
   *   registered_ts: int,    // The user's (possibly just-updated) registration timestamp.
   *   now: int               // Current UTC time.
   * } $ctx
   *
   * @return true|null|WP_Error
   *   - return true                       => apply this edit.
   *   - return null                       => skip this row silently (no error shown).
   *   - return new WP_Error('code', 'msg')=> skip AND surface 'msg' to the admin.
   *
   * TODO(you): Implement the policy. Decisions to make for your healthcare-
   * compliance context — there is no single right answer, which is exactly why
   * this is yours to own:
   *   1. If $ctx['completion_ts'] is 0 (admin left the date blank), should we
   *      skip silently (return null)? (Almost certainly yes.)
   *   2. Forcing a NEW completion requires $ctx['mark_complete']. If a course is
   *      not already_complete and the box is unticked, do you skip it, or treat
   *      a typed date as enough? (Your chosen UX = the checkbox gates it.)
   *   3. Reject completion dates in the future ($completion_ts > $now)? Auditors
   *      usually dislike future-dated training.
   *   4. Reject completion dates before the user existed
   *      ($completion_ts < $registered_ts)? A course completed before signup is
   *      typically impossible — but you may have legacy/imported accounts where
   *      it is legitimate. Your call.
   *   5. Any sane bound on $minutes (e.g. reject absurd values)?
   * Return WP_Error for rule violations so the admin sees WHY a row was skipped.
   */
  private static function validate_course_edit( array $ctx ) {
    if ( $ctx['completion_ts'] <= 0 ) {
        return null; // Skip silently if no date was entered
    }

    if ( ! $ctx['already_complete'] && ! $ctx['mark_complete'] ) {
        return null; // Forcing a completion requires the explicit checkbox
    }

    if ( $ctx['completion_ts'] > $ctx['now'] ) {
        return new WP_Error(
            'ghca_future_date',
            __( 'Completion date cannot be in the future.', 'ghca-acd' )
        );
    }

    // Allow completion before registration (e.g. legacy/imported records or offline training)
    // but prevent absurdly large 'minutes' inputs (e.g. > 1 week).
    if ( $ctx['minutes'] > 10080 ) {
        return new WP_Error(
            'ghca_absurd_time',
            __( 'Time spent cannot exceed 1 week (10,080 minutes).', 'ghca-acd' )
        );
    }

    return true;
  }

  /** Reads the current "started" timestamp from the course activity row (for prefilling minutes). */
  private static function get_course_activity_started( int $user_id, int $course_id ): int {
    if ( ! function_exists( 'learndash_get_user_activity' ) ) {
      return 0;
    }
    $activity = learndash_get_user_activity( array(
      'user_id'       => $user_id,
      'course_id'     => $course_id,
      'post_id'       => $course_id,
      'activity_type' => 'course',
    ) );
    if ( is_object( $activity ) && ! empty( $activity->activity_started ) ) {
      return (int) $activity->activity_started;
    }
    return 0;
  }

  /** Parses an HTML datetime-local string (site timezone) into a UTC unix timestamp. */
  private static function parse_local_datetime( string $value ): int {
    $value = trim( $value );
    if ( $value === '' ) {
      return 0;
    }
    $dt = date_create_immutable_from_format( 'Y-m-d\TH:i', $value, wp_timezone() );
    if ( ! $dt ) {
      $dt = date_create_immutable_from_format( 'Y-m-d\TH:i:s', $value, wp_timezone() );
    }
    return $dt ? $dt->getTimestamp() : 0;
  }

  /** Overwrites the core user_registered column (wp_update_user ignores it on update). */
  private static function update_user_registered( int $user_id, int $ts ): void {
    global $wpdb;
    $wpdb->update(
      $wpdb->users,
      array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $ts ) ),
      array( 'ID' => $user_id ),
      array( '%s' ),
      array( '%d' )
    );
    clean_user_cache( $user_id );
  }

  /**
   * Writes a back-dated completion to every LearnDash source of truth so the
   * Certificate Builder, transcripts and dashboard all agree:
   *   1. course progress meta (_sfwd-course_progress) — what learndash_course_status() reads
   *   2. the user_activity row (started/completed timestamps)
   *   3. the course_completed_{id} user meta — what the Certificate Builder reads
   * Completion hooks are intentionally NOT fired, so back-dating does not trigger
   * "course completed" notification emails to the learner.
   */
  private static function apply_course_completion( int $user_id, int $course_id, int $started_ts, int $completed_ts, bool $force_complete ): void {
    // 1. For a brand-new completion, force the progress array to 100% / completed.
    if ( $force_complete && function_exists( 'learndash_user_set_course_progress' ) && function_exists( 'learndash_course_get_steps_by_type' ) ) {
      $progress = function_exists( 'learndash_user_get_course_progress' ) ? learndash_user_get_course_progress( $user_id, $course_id, 'legacy' ) : array();
      if ( ! is_array( $progress ) ) {
        $progress = array();
      }

      $progress['lessons'] = array();
      foreach ( (array) learndash_course_get_steps_by_type( $course_id, 'sfwd-lessons' ) as $lesson_id ) {
        $progress['lessons'][ (int) $lesson_id ] = 1;
      }

      $progress['topics'] = array();
      foreach ( (array) learndash_course_get_steps_by_type( $course_id, 'sfwd-topic' ) as $topic_id ) {
        $parent_id = (int) learndash_course_get_single_parent_step( $course_id, $topic_id );
        $progress['topics'][ $parent_id ][ (int) $topic_id ] = 1;
      }

      $total                  = (int) learndash_get_course_steps_count( $course_id );
      $progress['total']      = $total;
      $progress['completed']  = $total;
      $progress['status']     = 'completed';
      learndash_user_set_course_progress( $user_id, $course_id, $progress );
    }

    // 2. Overwrite the course activity row with the back-dated window.
    if ( function_exists( 'learndash_update_user_activity' ) ) {
      learndash_update_user_activity( array(
        'user_id'            => $user_id,
        'course_id'          => $course_id,
        'post_id'            => $course_id,
        'activity_type'      => 'course',
        'activity_status'    => true,
        'activity_started'   => $started_ts,
        'activity_completed' => $completed_ts,
        'activity_updated'   => $completed_ts,
        'activity_action'    => 'update',
      ) );
    }

    // 3. Sync the meta key the Certificate Builder relies on.
    update_user_meta( $user_id, 'course_completed_' . $course_id, $completed_ts );

    // 4. Sync Uncanny Toolkit time spent so it shows on certificates and reports.
    $time_spent_seconds = max( 0, $completed_ts - $started_ts );
    $time_formatted     = sprintf( '%02d:%02d:%02d', ( $time_spent_seconds / 3600 ), ( $time_spent_seconds / 60 % 60 ), $time_spent_seconds % 60 );
    update_user_meta( $user_id, 'course_timer_completed_' . $course_id, $time_formatted );
    // Also save the generic timer meta just in case Uncanny re-calculates the sum (this one expects raw seconds).
    update_user_meta( $user_id, 'uo_timer_' . $course_id . '_' . $course_id, $time_spent_seconds );

    // 5. Clear LearnDash's per-user completion cache so reports re-read fresh data.
    delete_transient( 'learndash_course_completed_' . $course_id . '_' . $user_id );
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

  private static function page_uses_dashboard( WP_Post $post ): bool {
    $tags = array(
      'admin_compliance_login_gate',
      'admin_compliance_header',
      'admin_compliance_kpis',
      'admin_overdue_employees',
      'admin_course_completion_overview',
      'admin_employee_compliance_table',
      'admin_certificate_tracking',
      'admin_compliance_announcements',
      'admin_compliance_quick_links',
      'admin_compliance_support',
      'admin_compliance_dashboard',
      'admin_compliance_user_report',
    );

    foreach ( $tags as $tag ) {
      if ( has_shortcode( $post->post_content, $tag ) ) {
        return true;
      }
    }

    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
    return is_string( $elementor_data ) && strpos( $elementor_data, 'admin_compliance' ) !== false;
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

    $data          = self::get_aggregate();
    $emp_count     = (int) ( $data['total_employees'] ?? 0 );
    $overdue_count = (int) ( $data['overdue_employees'] ?? 0 );
    $course_count  = self::count_unique_tracked_courses( $data['employees'] ?? array() );

    $html .= '<div class="ghca-acd ghca-acd--tabs-shell">';
    $html .= '<div class="ghca-acd__tabs" role="tablist" aria-label="' . esc_attr__( 'Compliance dashboard sections', 'ghca-acd' ) . '">
      <button type="button" class="ghca-acd__tab-btn is-active" data-ghca-tab-target="ghca-tab-overview">' . esc_html__( 'Overview', 'ghca-acd' ) . '</button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-employees">' . esc_html__( 'Employees', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($emp_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-courses">' . esc_html__( 'Courses', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($course_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-certificates">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-overdue">' . esc_html__( 'Overdue', 'ghca-acd' ) . ' <span class="ghca-acd__tab-badge">' . esc_html($overdue_count) . '</span></button>
      <button type="button" class="ghca-acd__tab-btn" data-ghca-tab-target="ghca-tab-reports">' . esc_html__( 'Reports', 'ghca-acd' ) . '</button>
    </div>';

    // Overview Tab
    $html .= '<div id="ghca-tab-overview" class="ghca-acd__tab-content is-active">';
    $html .= GHCA_ACD_Scoping::render_group_summary( $atts );
    $html .= '<div class="ghca-acd__split-grid">';
    $html .= self::render_certificate_tracking( $atts );
    $html .= self::render_announcements( $atts );
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
    $html .= '</div>';

    return $html;
  }

  public static function handle_sync_request(): void {
    if ( empty( $_GET['ghca_acd_sync'] ) ) {
      return;
    }

    if ( ! is_user_logged_in() || ! self::can_view_dashboard() ) {
      return;
    }

    check_admin_referer( 'ghca_acd_sync', 'ghca_nonce' );

    self::bust_cache();

    $redirect = remove_query_arg( array( 'ghca_acd_sync', 'ghca_nonce', '_wpnonce' ) );
    wp_safe_redirect( add_query_arg( 'ghca_acd_synced', '1', $redirect ) );
    exit;
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
    
    $data          = self::get_aggregate();
    $total_users   = (int) ( $data['total_employees'] ?? 0 );
    $active_groups = count( self::get_group_options() );
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

    $data = self::get_aggregate();
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

    $filters = self::get_priority_filters();
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
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_pri_group', $filters['group'], self::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

    $filters = self::get_course_filters();
    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--courses">
      <div class="ghca-acd__panel">
        <h2><?php esc_html_e( 'Course Completion Overview', 'ghca-acd' ); ?></h2>
        <form class="ghca-acd__filters ghca-acd__filters--toolbar" method="get" data-ghca-filter-form data-ghca-table="courses">
          <input type="hidden" name="ghca_crs_page" value="<?php echo esc_attr( (string) $filters['page'] ); ?>" data-ghca-page-input />
          <?php
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_crs_group', $filters['group'], self::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

    $filters = self::get_employee_filters();
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
          echo GHCA_ACD_Table_UI::render_group_select( 'ghca_group', $filters['group'], self::get_group_options(), __( 'Group', 'ghca-acd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          ?>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Course', 'ghca-acd' ); ?></span>
            <select name="ghca_course">
              <option value=""><?php esc_html_e( 'All courses', 'ghca-acd' ); ?></option>
              <?php foreach ( self::get_course_options() as $cid => $label ) : ?>
                <option value="<?php echo esc_attr( (string) $cid ); ?>" <?php selected( $filters['course'], (string) $cid ); ?>><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="ghca-acd__filter-field">
            <span><?php esc_html_e( 'Status', 'ghca-acd' ); ?></span>
            <select name="ghca_status">
              <option value=""><?php esc_html_e( 'All statuses', 'ghca-acd' ); ?></option>
              <?php foreach ( self::get_status_options() as $slug => $label ) : ?>
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
    $all_rows = self::get_employee_table_rows( $filters );
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
                    <span class="ghca-acd__avatar" style="<?php echo esc_attr( self::get_avatar_style( (int) $row['user_id'] ) ); ?>" aria-hidden="true"><?php echo esc_html( self::get_avatar_initials( (string) $row['name'] ) ); ?></span>
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
                      <span class="ghca-acd__progress-bar <?php echo esc_attr( self::get_progress_class( (int) $row['progress_pct'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['progress_pct'] ); ?>%"></span>
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
    $all_rows = self::get_priority_rows( $filters );
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
                  <span class="ghca-acd__avatar" style="<?php echo esc_attr( self::get_avatar_style( (int) $row['user_id'] ) ); ?>" aria-hidden="true"><?php echo esc_html( self::get_avatar_initials( (string) $row['name'] ) ); ?></span>
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
                    <span class="ghca-acd__progress-bar <?php echo esc_attr( self::get_progress_class( (int) $row['progress_pct'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['progress_pct'] ); ?>%"></span>
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
    $all_rows = self::get_course_overview_rows( $filters );
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
                      <span class="ghca-acd__progress-bar <?php echo esc_attr( self::get_progress_class( (int) $row['rate'] ) ); ?>" style="width: <?php echo esc_attr( (string) $row['rate'] ); ?>%"></span>
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

  public static function ajax_filter_table(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'ghca-acd' ) ), 403 );
    }

    $table = isset( $_POST['ghca_table'] ) ? sanitize_key( wp_unslash( (string) $_POST['ghca_table'] ) ) : 'employees';

    switch ( $table ) {
      case 'priority':
        $filters = self::get_priority_filters();
        $html    = self::get_priority_table_html( $filters );
        break;
      case 'courses':
        $filters = self::get_course_filters();
        $html    = self::get_course_table_html( $filters );
        break;
      default:
        $filters = self::get_employee_filters();
        $html    = self::get_employee_table_html( $filters );
        break;
    }

    wp_send_json_success( array( 'html' => $html ) );
  }

  public static function render_certificate_tracking( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data     = self::get_aggregate();
    $cert_url = self::get_page_url( 'cert-download', '/cert-download/' );

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

  /** Announcement type slugs supported by the dashboard. */
  private static function announcement_types(): array {
    return array( 'alert', 'reminder', 'update', 'system' );
  }

  /** Whether the current user may create/edit/delete announcements. */
  public static function can_manage_announcements(): bool {
    return GHCA_ACD_Roles::user_can_manage_announcements();
  }

  /** Registers the private CPT that stores admin-authored announcements. */
  public static function register_announce_cpt(): void {
    register_post_type(
      'ghca-announce',
      array(
        'label'           => __( 'Compliance Announcements', 'ghca-acd' ),
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'has_archive'     => false,
        'rewrite'         => false,
        'query_var'       => false,
        'supports'        => array( 'title', 'editor' ),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
      )
    );

    register_post_meta(
      'ghca-announce',
      'ghca_announce_type',
      array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => array( __CLASS__, 'sanitize_announcement_type' ),
        'auth_callback'     => array( __CLASS__, 'can_manage_announcements' ),
      )
    );

    register_post_meta(
      'ghca-announce',
      'ghca_announce_url',
      array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'esc_url_raw',
        'auth_callback'     => array( __CLASS__, 'can_manage_announcements' ),
      )
    );
  }

  /** Normalises a submitted announcement type to a known slug. */
  public static function sanitize_announcement_type( $value ): string {
    $value = is_string( $value ) ? sanitize_key( $value ) : '';
    return in_array( $value, self::announcement_types(), true ) ? $value : 'update';
  }

  /** Type → icon glyph + badge label map for announcements. */
  private static function announcement_type_meta(): array {
    return array(
      'alert'    => array( 'icon' => 'alert', 'label' => __( 'Alert', 'ghca-acd' ) ),
      'reminder' => array( 'icon' => 'bell', 'label' => __( 'Reminder', 'ghca-acd' ) ),
      'update'   => array( 'icon' => 'megaphone', 'label' => __( 'Update', 'ghca-acd' ) ),
      'system'   => array( 'icon' => 'sync', 'label' => __( 'System', 'ghca-acd' ) ),
    );
  }

  public static function render_announcements( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $can_manage = self::can_manage_announcements();

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--announcements">
      <div class="ghca-acd__panel ghca-acd__panel--flush">
        <div class="ghca-acd__section-head ghca-acd__section-head--bordered ghca-acd__announcement-head">
          <h2><?php esc_html_e( 'Announcements', 'ghca-acd' ); ?></h2>
          <?php if ( $can_manage ) : ?>
            <button type="button" class="ghca-acd__announcement-add" data-ghca-announce-add>
              <span aria-hidden="true">+</span> <?php esc_html_e( 'Add note', 'ghca-acd' ); ?>
            </button>
          <?php endif; ?>
        </div>
        <div class="ghca-acd__alert-list" id="ghca-acd-announcement-list" data-ghca-announce-list>
          <?php echo self::render_announcement_list_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** Renders just the announcement rows (reused by the AJAX re-render handler). */
  public static function render_announcement_list_html(): string {
    $items     = self::get_announcement_items();
    $type_meta = self::announcement_type_meta();

    ob_start();

    if ( empty( $items ) ) {
      echo '<p class="ghca-acd__announcement-empty">' . esc_html__( 'No announcements yet.', 'ghca-acd' ) . '</p>';
      return (string) ob_get_clean();
    }

    foreach ( $items as $item ) :
      $type     = isset( $item['type'], $type_meta[ $item['type'] ] ) ? (string) $item['type'] : 'update';
      $meta     = $type_meta[ $type ];
      $editable = ! empty( $item['editable'] ) && (int) ( $item['id'] ?? 0 ) > 0;
      ?>
      <div class="ghca-acd__announcement-item<?php echo $editable ? ' is-editable' : ''; ?>"
        <?php if ( $editable ) : ?>
        data-announce-id="<?php echo esc_attr( (string) (int) $item['id'] ); ?>"
        data-announce-title="<?php echo esc_attr( (string) $item['label'] ); ?>"
        data-announce-body="<?php echo esc_attr( (string) ( $item['body'] ?? '' ) ); ?>"
        data-announce-type="<?php echo esc_attr( $type ); ?>"
        data-announce-url="<?php echo esc_attr( (string) ( $item['url'] ?? '' ) ); ?>"
        <?php endif; ?>>
        <span class="ghca-acd__announcement-icon ghca-acd__announcement-icon--<?php echo esc_attr( $type ); ?>" aria-hidden="true"><?php echo GHCA_UI_Icons::render( $meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <div class="ghca-acd__announcement-content">
          <h4>
            <?php echo esc_html( $item['label'] ); ?>
            <span class="ghca-acd__alert-badge ghca-acd__alert-badge--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $meta['label'] ); ?></span>
          </h4>
          <?php if ( ! empty( $item['body'] ) ) : ?>
            <p><?php echo esc_html( $item['body'] ); ?></p>
          <?php endif; ?>
          <?php if ( ! empty( $item['url'] ) ) : ?>
            <p><a href="<?php echo esc_url( $item['url'] ); ?>" class="ghca-acd__alert-link"><?php esc_html_e( 'Take action', 'ghca-acd' ); ?> &rarr;</a></p>
          <?php endif; ?>
        </div>
        <?php if ( $editable ) : ?>
          <div class="ghca-acd__announcement-actions">
            <button type="button" class="ghca-acd__announcement-action" data-ghca-announce-edit="<?php echo esc_attr( (string) (int) $item['id'] ); ?>" aria-label="<?php esc_attr_e( 'Edit announcement', 'ghca-acd' ); ?>"><?php echo GHCA_UI_Icons::render( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
            <button type="button" class="ghca-acd__announcement-action ghca-acd__announcement-action--danger" data-ghca-announce-delete="<?php echo esc_attr( (string) (int) $item['id'] ); ?>" aria-label="<?php esc_attr_e( 'Delete announcement', 'ghca-acd' ); ?>"><?php echo GHCA_UI_Icons::render( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
          </div>
        <?php endif; ?>
      </div>
    <?php
    endforeach;

    return (string) ob_get_clean();
  }

  /** Footer modal shell for adding/editing announcements. */
  public static function render_announcement_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! self::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! self::can_manage_announcements() ) {
      return;
    }
    $type_meta = self::announcement_type_meta();
    ?>
    <div class="ghca-acd__edit-modal" id="ghca-acd-announce-modal" hidden aria-hidden="true">
      <div class="ghca-acd__edit-modal-backdrop" data-ghca-announce-close></div>
      <div class="ghca-acd__edit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-announce-modal-title">
        <div class="ghca-acd__edit-modal-header">
          <h2 id="ghca-acd-announce-modal-title"><?php esc_html_e( 'Add Announcement', 'ghca-acd' ); ?></h2>
          <button type="button" class="ghca-acd__edit-modal-close" data-ghca-announce-close aria-label="<?php esc_attr_e( 'Close', 'ghca-acd' ); ?>">&times;</button>
        </div>
        <div class="ghca-acd__edit-modal-body">
          <form class="ghca-acd__edit-form" data-ghca-announce-form>
            <input type="hidden" name="announce_id" value="0" />
            <div class="ghca-acd__edit-section">
              <label class="ghca-acd__edit-field">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Title', 'ghca-acd' ); ?></span>
                <input type="text" name="title" class="ghca-acd__edit-input" maxlength="160" required />
              </label>
              <label class="ghca-acd__edit-field" style="margin-top:14px;">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Message', 'ghca-acd' ); ?></span>
                <textarea name="body" class="ghca-acd__edit-input ghca-acd__edit-textarea" rows="3" maxlength="600"></textarea>
              </label>
              <div class="ghca-acd__edit-course-grid" style="margin-top:14px;">
                <label class="ghca-acd__edit-field">
                  <span class="ghca-acd__edit-label"><?php esc_html_e( 'Type', 'ghca-acd' ); ?></span>
                  <select name="type" class="ghca-acd__edit-input">
                    <?php foreach ( $type_meta as $slug => $meta ) : ?>
                      <option value="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === 'update' ? ' selected' : ''; ?>><?php echo esc_html( $meta['label'] ); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="ghca-acd__edit-field">
                  <span class="ghca-acd__edit-label"><?php esc_html_e( 'Action link (optional)', 'ghca-acd' ); ?></span>
                  <input type="url" name="url" class="ghca-acd__edit-input" placeholder="https://" />
                </label>
              </div>
            </div>
            <div class="ghca-acd__edit-form-footer">
              <button type="button" class="ghca-acd__edit-btn ghca-acd__edit-btn--ghost" data-ghca-announce-close><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
              <button type="submit" class="ghca-acd__edit-btn ghca-acd__edit-btn--save"><?php esc_html_e( 'Publish', 'ghca-acd' ); ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  /** Creates or updates an admin announcement, then returns the refreshed list. */
  public static function ajax_save_announcement(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_manage_announcements() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $announce_id = isset( $_POST['announce_id'] ) ? (int) $_POST['announce_id'] : 0;
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
    $body        = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
    $type        = isset( $_POST['type'] ) ? self::sanitize_announcement_type( wp_unslash( $_POST['type'] ) ) : 'update';
    $url         = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

    if ( $title === '' ) {
      wp_send_json_error( array( 'message' => __( 'A title is required.', 'ghca-acd' ) ) );
    }

    $postarr = array(
      'post_type'    => 'ghca-announce',
      'post_status'  => 'publish',
      'post_title'   => $title,
      'post_content' => $body,
    );

    if ( $announce_id > 0 ) {
      if ( get_post_type( $announce_id ) !== 'ghca-announce' ) {
        wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
      }
      $postarr['ID'] = $announce_id;
      $result        = wp_update_post( $postarr, true );
    } else {
      $result = wp_insert_post( $postarr, true );
    }

    if ( is_wp_error( $result ) || (int) $result <= 0 ) {
      wp_send_json_error( array( 'message' => __( 'Could not save the announcement.', 'ghca-acd' ) ) );
    }

    $post_id = (int) $result;
    update_post_meta( $post_id, 'ghca_announce_type', $type );
    if ( $url !== '' ) {
      update_post_meta( $post_id, 'ghca_announce_url', $url );
    } else {
      delete_post_meta( $post_id, 'ghca_announce_url' );
    }

    // if ( $announce_id === 0 ) {
    //   do_action( 'ghca_acd_new_announcement_published', $post_id, $title, $body );
    // }

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html(),
      'message' => $announce_id > 0 ? __( 'Announcement updated.', 'ghca-acd' ) : __( 'Announcement published.', 'ghca-acd' ),
    ) );
  }

  public static function process_buddyboss_notifications( $post_id, $title, $body ) {
    if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
      return;
    }

    $employee_roles = apply_filters( 'ghca_acd_employee_roles', array( 'caregiver', 'nurse' ) );
    
    $users = get_users( array(
      'role__in' => $employee_roles,
      'fields'   => 'ID',
    ) );

    foreach ( $users as $user_id ) {
      bp_notifications_add_notification( array(
        'user_id'           => $user_id,
        'item_id'           => $post_id,
        'secondary_item_id' => get_current_user_id(),
        'component_name'    => 'custom',
        'component_action'  => 'new_announcement',
        'date_notified'     => bp_core_current_time(),
        'is_new'            => 1,
      ) );
    }
  }

  public static function format_buddypress_notifications( $action, $item_id, $secondary_item_id, $total_items, $format = 'string' ) {
    if ( 'new_announcement' === $action ) {
      $title = get_the_title( $item_id );
      $link  = site_url( '/employee-dashboard/' );
      
      $text = sprintf( __( 'New Compliance Announcement: %s', 'ghca-acd' ), $title );
      
      if ( 'string' === $format ) {
        return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
      } else {
        return array(
          'text' => $text,
          'link' => $link
        );
      }
    }
    return $action;
  }

  /** Deletes an admin announcement, then returns the refreshed list. */
  public static function ajax_delete_announcement(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_manage_announcements() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $announce_id = isset( $_POST['announce_id'] ) ? (int) $_POST['announce_id'] : 0;
    if ( $announce_id <= 0 || get_post_type( $announce_id ) !== 'ghca-announce' ) {
      wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
    }

    wp_delete_post( $announce_id, true );

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html(),
      'message' => __( 'Announcement deleted.', 'ghca-acd' ),
    ) );
  }

  /** Returns the current announcement list HTML (used to refresh after changes). */
  public static function ajax_get_announcements(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    wp_send_json_success( array( 'html' => self::render_announcement_list_html() ) );
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
    $user_report = self::get_page_url( 'user-report', '/user-report/' );
    $cert_url    = self::get_page_url( 'cert-download', '/cert-download/' );

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

  private static function can_view_dashboard(): bool {
    return is_user_logged_in() && GHCA_ACD_Roles::user_can_view();
  }

  /** @return array<int,array<string,mixed>> */
  public static function get_employees_for_current_view(): array {
    return self::get_aggregate()['employees'];
  }

  /** @return array<int> */
  private static function get_compliance_group_ids(): array {
    return GHCA_ACD_Scoping::get_visible_group_ids();
  }

  /** @return array<int> */
  private static function get_tracked_group_ids(): array {
    return array_values(
      array_unique(
        array_merge(
          self::get_compliance_group_ids(),
          GHCA_Compliance_Program::get_new_hire_group_ids()
        )
      )
    );
  }

  private static function get_at_risk_days(): int {
    return GHCA_ACD_Settings::get_at_risk_days();
  }

  private static function get_cache_key(): string {
    // Date stamp (site timezone) makes the aggregate self-invalidate at local
    // midnight, so a course flipping 🟡→🔴 overnight recomputes with no DB write.
    return 'ghca_acd_agg_' . self::VERSION . '_' . get_current_user_id() . '_' . wp_date( 'Ymd' );
  }

  private static function bust_cache(): void {
    delete_transient( self::get_cache_key() );
    self::$aggregate = null;
  }

  /** @return array<int> */
  private static function get_employee_user_ids(): array {
    $ids = array();

    foreach ( self::get_tracked_group_ids() as $group_id ) {
      if ( ! function_exists( 'learndash_get_groups_user_ids' ) ) {
        continue;
      }
      $group_users = learndash_get_groups_user_ids( $group_id );
      if ( ! is_array( $group_users ) ) {
        continue;
      }
      foreach ( $group_users as $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
          continue;
        }
        $ids[] = $uid;
      }
    }

    // Also include any WP users not enrolled in any tracked group, if allowed.
    if ( GHCA_ACD_Roles::user_has_unrestricted_view() ) {
      $all_wp_users = get_users( array(
        'fields'  => 'ID',
        'orderby' => 'registered',
        'order'   => 'ASC',
      ) );
      foreach ( $all_wp_users as $wp_uid ) {
        $wp_uid = (int) $wp_uid;
        if ( $wp_uid <= 0 || in_array( $wp_uid, $ids, true ) ) {
          continue;
        }
        $user = get_userdata( $wp_uid );
        if ( ! $user ) {
          continue;
        }
        // Skip super admins on multisite or users with no real role.
        if ( empty( $user->roles ) || is_super_admin( $wp_uid ) ) {
          continue;
        }
        $ids[] = $wp_uid;
      }
    }

    return array_values( array_unique( $ids ) );
  }

  /** @return array<string,mixed> */
  private static function get_aggregate(): array {
    if ( null !== self::$aggregate ) {
      return self::$aggregate;
    }

    $ttl = GHCA_ACD_Settings::get_cache_ttl();
    if ( $ttl > 0 ) {
      $cached = get_transient( self::get_cache_key() );
      if ( is_array( $cached ) ) {
        self::$aggregate = $cached;
        return self::$aggregate;
      }
    }

    $employees         = self::build_employee_records();
    $total             = count( $employees );
    $completed         = 0;
    $in_progress       = 0;
    $overdue           = 0;
    $certificates      = 0;
    $cert_available    = 0;
    $cert_missing      = 0;
    $eligible          = 0;
    $recent_completed  = 0;
    $recent_items      = array();
    $next_due_ts       = PHP_INT_MAX;
    $expiring          = 0;

    foreach ( $employees as $employee ) {
      if ( 'completed' === $employee['status_slug'] || 'new_hire_completed' === $employee['status_slug'] ) {
        ++$completed;
      } elseif ( 'expiring_soon' === $employee['status_slug'] ) {
        ++$expiring;
      } elseif ( in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true ) ) {
        ++$overdue;
      } elseif ( in_array( $employee['status_slug'], array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true ) ) {
        ++$in_progress;
      }

      if ( ! empty( $employee['certificate_url'] ) ) {
        ++$certificates;
      }

      foreach ( $employee['courses'] as $course ) {
        if ( ! empty( $course['has_certificate'] ) ) {
          ++$cert_available;
          if ( ! empty( $course['completed'] ) && empty( $course['certificate_url'] ) ) {
            ++$cert_missing;
            ++$eligible;
          }
        }
        if ( ! empty( $course['completed'] ) && ! empty( $course['completed_recently'] ) ) {
          ++$recent_completed;
          $recent_items[] = array(
            'name'   => $employee['name'],
            'course' => $course['title'],
            'ts'     => (int) ( $course['last_activity_ts'] ?? 0 ),
          );
        }
      }

      if ( ! empty( $employee['due_timestamp'] ) && $employee['due_timestamp'] < $next_due_ts && 'completed' !== $employee['status_slug'] ) {
        $next_due_ts = (int) $employee['due_timestamp'];
      }
    }

    // Yellow (expiring soon) employees are still compliant for now.
    $rate = $total > 0 ? (int) round( ( ( $completed + $expiring ) / $total ) * 100 ) : 0;

    usort(
      $recent_items,
      static function ( array $a, array $b ): int {
        return (int) $b['ts'] <=> (int) $a['ts'];
      }
    );

    self::$aggregate = array(
      'employees'                  => $employees,
      'total_employees'            => $total,
      'compliance_rate'            => $rate,
      'compliance_rate_label'        => $total ? $rate . '% compliant' : __( 'No employees assigned', 'ghca-acd' ),
      'completed_employees'          => $completed,
      'completed_employees_label'    => sprintf( _n( '%d completed', '%d completed', $completed, 'ghca-acd' ), $completed ),
      'in_progress_employees'      => $in_progress,
      'in_progress_employees_label'  => sprintf( _n( '%d in progress', '%d in progress', $in_progress, 'ghca-acd' ), $in_progress ),
      'overdue_employees'            => $overdue,
      'overdue_employees_label'      => sprintf( _n( '%d overdue', '%d overdue', $overdue, 'ghca-acd' ), $overdue ),
      'expiring_soon_employees'      => $expiring,
      'expiring_soon_employees_label' => sprintf( _n( '%d expiring soon', '%d expiring soon', $expiring, 'ghca-acd' ), $expiring ),
      'certificates_issued'          => $certificates,
      'certificates_issued_label'    => sprintf( _n( '%d certificate', '%d certificates', $certificates, 'ghca-acd' ), $certificates ),
      'upcoming_due_date_label'      => PHP_INT_MAX === $next_due_ts ? self::format_due_date( get_option( self::OPTION_DUE_DATE, '2026-07-31' ) ) : wp_date( 'F j, Y', $next_due_ts ),
      'certificates_available'       => $cert_available,
      'certificates_missing'         => $cert_missing,
      'recently_completed'           => $recent_completed,
      'eligible_for_certificate'     => $eligible,
      'recent_completions'           => array_slice( $recent_items, 0, 8 ),
    );

    if ( $ttl > 0 ) {
      set_transient( self::get_cache_key(), self::$aggregate, $ttl );
    }

    return self::$aggregate;
  }

  /** @return array<int,array<string,mixed>> */
  private static function build_employee_records(): array {
    if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
      return array();
    }

    $records = array();
    foreach ( self::get_employee_user_ids() as $user_id ) {
      $records[] = self::build_employee_record( $user_id );
    }

    usort(
      $records,
      static function ( array $a, array $b ): int {
        return strcasecmp( $a['name'], $b['name'] );
      }
    );

    return $records;
  }

  /** @return array<string,mixed> */
  private static function build_employee_record( int $user_id ): array {
    $user     = get_userdata( $user_id );
    $new_hire = GHCA_Compliance_Program::get_user_status( $user_id );

    if ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) ) {
      $courses         = $new_hire['courses'];
      $completed_count   = (int) $new_hire['completed_count'];
      $total             = (int) $new_hire['total_courses'];
      $all_complete      = ! empty( $new_hire['complete'] );
      $due_ts            = (int) $new_hire['deadline_ts'];
      $due_date_label    = (string) $new_hire['deadline_label'];
      $status_slug       = (string) $new_hire['status_slug'];
      $status_label      = (string) $new_hire['status_label'];
      $started_count     = 0;

      foreach ( $courses as $course ) {
        if ( empty( $course['completed'] ) && 'in_progress' === $course['status'] ) {
          ++$started_count;
        }
      }

      $certificate_url = '';
      foreach ( $courses as $course ) {
        if ( ! empty( $course['certificate_url'] ) ) {
          $certificate_url = $course['certificate_url'];
          break;
        }
      }

      $progress_pct = $total > 0 ? (int) round( ( $completed_count / $total ) * 100 ) : 0;

      // Rolling expiration overrides onboarding. A previously-completed required
      // course that has lapsed makes the employee Overdue even mid-onboarding
      // (precedence: expired > new-hire status). Evaluate the FULL required course
      // set, not just new-hire group courses, so a lapsed cert outside the
      // onboarding group is still caught. Expiring-soon does NOT override here —
      // an incomplete new hire is not yet compliant.
      $expiry_states = array();
      foreach ( self::get_user_courses( $user_id ) as $c ) {
        $expiry_states[] = (string) ( $c['compliance_state'] ?? 'incomplete' );
      }
      $expiry_state = GHCA_Course_Lifespans::rollup( $expiry_states );
      if ( 'expired' === $expiry_state ) {
        $status_slug  = 'expired';
        $status_label = __( 'Expired', 'ghca-acd' );
      }

      return array(
        'user_id'              => $user_id,
        'name'                 => self::get_user_full_name( $user_id, $user ),
        'email'                => $user ? $user->user_email : '',
        'group'                => (string) $new_hire['group_label'],
        'group_id'             => (int) $new_hire['group_id'],
        'courses'              => $courses,
        'total_courses'        => $total,
        'completed_count'      => $completed_count,
        'progress_pct'         => $progress_pct,
        'progress_label'       => $progress_pct . '%',
        'required_label'       => $total ? (string) $total : '0',
        'completed_label'      => $total ? sprintf( '%1$d / %2$d', $completed_count, $total ) : '0',
        'due_timestamp'        => $due_ts,
        'due_date_label'       => $due_date_label,
        'last_activity_label'  => self::get_last_activity_label( $user_id, $courses ),
        'status_slug'          => $status_slug,
        'status_label'         => $status_label,
        'expiry_state'         => $expiry_state,
        'new_hire'             => $new_hire,
        'certificate_url'      => $certificate_url,
        'certificate_label'    => $certificate_url ? __( 'Available', 'ghca-acd' ) : ( $all_complete ? __( 'Check certificates', 'ghca-acd' ) : __( 'Pending', 'ghca-acd' ) ),
        'actions_html'         => self::build_employee_actions_html( $user_id, $user ? $user->user_email : '' ),
      );
    }

    $courses = self::get_user_courses( $user_id );
    $cycle   = self::get_compliance_cycle( $user_id );

    $completed_count = 0;
    $started_count   = 0;
    $certificate_url = '';

    foreach ( $courses as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        ++$completed_count;
        if ( ! empty( $course['certificate_url'] ) ) {
          $certificate_url = $course['certificate_url'];
        }
      } elseif ( 'in_progress' === $course['status'] ) {
        ++$started_count;
      }
    }

    $total        = count( $courses );
    $all_complete = $total > 0 && $completed_count === $total;
    $due_ts       = (int) ( $cycle['due_timestamp'] ?? 0 );
    $is_overdue   = ! $all_complete && $due_ts > 0 && time() > $due_ts;

    // Roll up per-course traffic-light state (completed courses only react here).
    $course_states = array();
    foreach ( $courses as $c ) {
      $course_states[] = (string) ( $c['compliance_state'] ?? 'incomplete' );
    }
    $expiry_state = GHCA_Course_Lifespans::rollup( $course_states );

    if ( 'expired' === $expiry_state ) {
      // Finished but past its rolling lifespan → 🔴 Expired. Distinct row label
      // from incomplete "Overdue", but both roll into the Overdue KPI bucket.
      $status_slug  = 'expired';
      $status_label = __( 'Expired', 'ghca-acd' );
    } elseif ( $all_complete ) {
      if ( 'expiring_soon' === $expiry_state ) {
        $status_slug  = 'expiring_soon';
        $status_label = __( 'Expiring Soon', 'ghca-acd' );
      } else {
        $status_slug  = 'completed';
        $status_label = __( 'Completed', 'ghca-acd' );
      }
    } elseif ( $is_overdue ) {
      $status_slug  = 'overdue';
      $status_label = __( 'Overdue', 'ghca-acd' );
    } elseif ( $started_count > 0 || $completed_count > 0 ) {
      $status_slug  = 'in_progress';
      $status_label = __( 'In Progress', 'ghca-acd' );
    } else {
      $status_slug  = 'not_started';
      $status_label = __( 'Not Started', 'ghca-acd' );
    }

    $progress_pct = $total > 0 ? (int) round( ( $completed_count / $total ) * 100 ) : 0;

    return array(
      'user_id'              => $user_id,
      'name'                 => self::get_user_full_name( $user_id, $user ),
      'email'                => $user ? $user->user_email : '',
      'group'                => self::get_user_group_label( $user_id ),
      'group_id'             => self::get_user_primary_group_id( $user_id ),
      'courses'              => $courses,
      'total_courses'        => $total,
      'completed_count'      => $completed_count,
      'progress_pct'         => $progress_pct,
      'progress_label'       => $progress_pct . '%',
      'required_label'       => $total ? (string) $total : '0',
      'completed_label'      => $total ? sprintf( '%1$d / %2$d', $completed_count, $total ) : '0',
      'due_timestamp'        => $due_ts,
      'due_date_label'       => $cycle['due_date_label'] ?: self::format_due_date( get_option( self::OPTION_DUE_DATE, '2026-07-31' ) ),
      'last_activity_label'  => self::get_last_activity_label( $user_id, $courses ),
      'status_slug'          => $status_slug,
      'status_label'         => $status_label,
      'expiry_state'         => $expiry_state,
      'new_hire'             => $new_hire,
      'certificate_url'      => $certificate_url,
      'certificate_label'    => $certificate_url ? __( 'Available', 'ghca-acd' ) : ( $all_complete ? __( 'Check certificates', 'ghca-acd' ) : __( 'Pending', 'ghca-acd' ) ),
      'actions_html'         => self::build_employee_actions_html( $user_id, $user ? $user->user_email : '' ),
    );
  }

  /** @return array<int,array<string,mixed>> */
  private static function get_user_courses( int $user_id ): array {
    $course_ids = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );
    $items      = array();

    foreach ( array_values( array_unique( array_filter( $course_ids ) ) ) as $course_id ) {
      $title     = self::normalize_course_title(
        html_entity_decode( (string) get_the_title( $course_id ), ENT_QUOTES, 'UTF-8' )
      );
      $status    = function_exists( 'learndash_course_status' ) ? (string) learndash_course_status( $course_id, $user_id, true ) : 'not_started';
      $progress  = function_exists( 'learndash_course_progress' ) ? (array) learndash_course_progress(
        array(
          'user_id'   => $user_id,
          'course_id' => $course_id,
          'array'     => true,
        )
      ) : array();
      $percent   = isset( $progress['percentage'] ) ? (int) $progress['percentage'] : 0;
      $completed = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $course_id ) : false;
      if ( $completed ) {
        $percent = 100;
      }
      $cert      = self::get_certificate_url( $user_id, $course_id );
      $completed_ts = self::get_course_completed_timestamp( $user_id, $course_id );

      $items[] = array(
        'id'                 => $course_id,
        'title'              => $title,
        'status'             => $completed ? 'completed' : $status,
        'status_label'       => self::course_status_label( $completed ? 'completed' : $status ),
        'completed'          => $completed,
        'completed_ts'       => $completed_ts, // Added for Edit Records
        'progress'           => $percent,
        'progress_label'     => $percent . '%',
        'url'                => get_permalink( $course_id ) ?: home_url( '/my-courses/' ),
        'has_certificate'    => self::course_has_certificate( $course_id ),
        'certificate_url'    => $cert,
        'completed_recently' => $completed && $completed_ts > ( time() - ( 30 * DAY_IN_SECONDS ) ),
        'last_activity_ts'   => self::get_course_last_activity_timestamp( $user_id, $course_id, $completed_ts ),
      ) + GHCA_Course_Lifespans::decorate( $course_id, $completed, $completed_ts );
    }

    return $items;
  }

  /** @return array<string,mixed> */
  public static function get_employee_record( int $user_id ): array {
    return self::build_employee_record( $user_id );
  }

  public static function format_activity_label( int $timestamp ): string {
    return self::format_timestamp_label( $timestamp );
  }

  public static function get_user_report_url( int $user_id ): string {
    $slug = apply_filters( 'ghca_user_report_page_slug', 'user-report' );
    return add_query_arg( 'user_id', $user_id, self::get_page_url( $slug, '/user-report/' ) );
  }

  public static function get_admin_dashboard_url(): string {
    $slug = apply_filters( 'ghca_admin_dashboard_page_slug', 'compliance-admin-dashboard' );
    return self::get_page_url( $slug, '/compliance-admin-dashboard/' );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  private static function get_priority_rows( array $filters = array() ): array {
    $rows = array();

    foreach ( self::get_aggregate()['employees'] as $employee ) {
      if ( ! empty( $employee['completed_count'] ) && (int) $employee['completed_count'] === (int) $employee['total_courses'] && (int) $employee['total_courses'] > 0 && 'expired' !== $employee['status_slug'] ) {
        continue;
      }

      $priority = self::get_employee_priority( $employee );
      if ( ! in_array( $priority, array( 'overdue', 'at_risk' ), true ) ) {
        continue;
      }

      $rows[] = array(
        'priority'            => $priority,
        'user_id'             => (int) $employee['user_id'],
        'group_id'            => (int) $employee['group_id'],
        'name'                => $employee['name'],
        'email'               => $employee['email'],
        'group'               => $employee['group'],
        'progress_label'      => $employee['progress_label'],
        'progress_pct'        => $employee['progress_pct'],
        'next_course_label'   => self::get_next_course_label( $employee['courses'] ),
        'due_date_label'      => $employee['due_date_label'],
        'status_slug'         => $employee['status_slug'],
        'status_label'        => $employee['status_label'],
        'last_activity_label' => $employee['last_activity_label'],
        'actions_html'        => self::build_priority_actions_html( (int) $employee['user_id'], $employee['email'] ),
      );
    }

    $orderby = $filters['orderby'] ?? '';
    $order   = $filters['order'] ?? 'asc';

    if ( $orderby && in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
      usort(
        $rows,
        static function ( array $a, array $b ) use ( $orderby, $order ): int {
          $val_a = $a[ $orderby ] ?? '';
          $val_b = $b[ $orderby ] ?? '';
          
          if ( $val_a === $val_b ) {
            return 0;
          }
          
          $cmp = 0;
          if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
             $cmp = (float) $val_a < (float) $val_b ? -1 : 1;
          } else {
             $cmp = strcasecmp( (string) $val_a, (string) $val_b );
          }
          
          return $order === 'desc' ? -$cmp : $cmp;
        }
      );
    } else {
      usort(
        $rows,
        static function ( array $a, array $b ): int {
          if ( $a['priority'] !== $b['priority'] ) {
            return 'overdue' === $a['priority'] ? -1 : 1;
          }
          return strcasecmp( $a['name'], $b['name'] );
        }
      );
    }

    if ( ! empty( $filters['group'] ) ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( ! empty( $filters['priority'] ) && in_array( $filters['priority'], array( 'overdue', 'at_risk' ), true ) ) {
      $priority_filter = (string) $filters['priority'];
      $rows            = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $priority_filter ): bool {
            return $row['priority'] === $priority_filter;
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['name'], $row['email'], $row['group'] ) );
          }
        )
      );
    }

    return $rows;
  }

  /** @param array<string,mixed> $employee */
  private static function get_employee_priority( array $employee ): string {
    if ( ! empty( $employee['new_hire']['overdue'] ) ) {
      return 'overdue';
    }
    if ( ! empty( $employee['new_hire']['at_risk'] ) ) {
      return 'at_risk';
    }

    $due_ts = (int) ( $employee['due_timestamp'] ?? 0 );
    if ( $due_ts > 0 && time() > $due_ts ) {
      return 'overdue';
    }
    if ( $due_ts > 0 && $due_ts <= ( time() + ( self::get_at_risk_days() * DAY_IN_SECONDS ) ) ) {
      return 'at_risk';
    }
    if ( in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true ) ) {
      return 'overdue';
    }
    return '';
  }

  /** @param array<int,array<string,mixed>> $courses */
  private static function get_next_course_label( array $courses ): string {
    $in_progress = null;
    $not_started = null;

    foreach ( $courses as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        continue;
      }
      if ( 'in_progress' === $course['status'] && null === $in_progress ) {
        $in_progress = $course;
      } elseif ( null === $not_started ) {
        $not_started = $course;
      }
    }

    $next = $in_progress ?: $not_started;
    return $next ? (string) $next['title'] : __( 'None pending', 'ghca-acd' );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  private static function get_course_overview_rows( array $filters = array() ): array {
    $rows = array();
    $map  = array();

    foreach ( self::get_aggregate()['employees'] as $employee ) {
      foreach ( $employee['courses'] as $course ) {
        $key = $course['id'] . ':' . $employee['group_id'];
        if ( ! isset( $map[ $key ] ) ) {
          $map[ $key ] = array(
            'course'      => $course['title'],
            'group'       => $employee['group'],
            'group_id'    => (int) $employee['group_id'],
            'required'    => 0,
            'completed'   => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'certificate' => ! empty( $course['has_certificate'] ),
          );
        }
        ++$map[ $key ]['required'];
        if ( ! empty( $course['completed'] ) ) {
          ++$map[ $key ]['completed'];
        } elseif ( 'in_progress' === $course['status'] ) {
          ++$map[ $key ]['in_progress'];
        } else {
          ++$map[ $key ]['not_started'];
        }
      }
    }

    foreach ( $map as $row ) {
      $rate                     = $row['required'] > 0 ? (int) round( ( $row['completed'] / $row['required'] ) * 100 ) : 0;
      $row['rate']              = $rate;
      $row['rate_label']        = $rate . '%';
      $row['certificate_label'] = $row['certificate'] ? __( 'Yes', 'ghca-acd' ) : __( 'No', 'ghca-acd' );
      $rows[]                   = $row;
    }

    usort(
      $rows,
      static function ( array $a, array $b ): int {
        return strcasecmp( $a['course'], $b['course'] );
      }
    );

    if ( ! empty( $filters['group'] ) ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( ! empty( $filters['certificate'] ) ) {
      $want_cert = 'yes' === $filters['certificate'];
      $rows      = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $want_cert ): bool {
            return (bool) $row['certificate'] === $want_cert;
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['course'], $row['group'] ) );
          }
        )
      );
    }

    return $rows;
  }

  private static function get_request_value( string $key, string $default = '' ): string {
    if ( isset( $_POST[ $key ] ) ) {
      return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
    }
    if ( isset( $_GET[ $key ] ) ) {
      return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
    }
    return $default;
  }

  private static function get_request_flag( string $key ): bool {
    if ( isset( $_POST[ $key ] ) ) {
      return ! empty( $_POST[ $key ] );
    }
    if ( isset( $_GET[ $key ] ) ) {
      return ! empty( $_GET[ $key ] );
    }
    return false;
  }

  /** @return array<string,mixed> */
  private static function get_employee_filters(): array {
    return array(
      'group'        => self::get_request_value( 'ghca_group' ),
      'course'       => self::get_request_value( 'ghca_course' ),
      'status'       => self::get_request_value( 'ghca_status' ),
      'overdue_only' => self::get_request_flag( 'ghca_overdue' ),
      'search'       => self::get_request_value( 'ghca_emp_search' ),
      'page'         => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_emp_page', '1' ) ),
      'per_page'     => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_emp_per', '15' ) ),
      'orderby'      => self::sanitize_orderby( self::get_request_value( 'ghca_orderby' ) ),
      'order'        => in_array( self::get_request_value( 'ghca_order', 'asc' ), array( 'asc', 'desc' ), true ) ? self::get_request_value( 'ghca_order', 'asc' ) : 'asc',
    );
  }

  /** @return array<string,mixed> */
  private static function get_priority_filters(): array {
    $priority = self::get_request_value( 'ghca_pri_type' );
    if ( ! in_array( $priority, array( '', 'overdue', 'at_risk' ), true ) ) {
      $priority = '';
    }

    return array(
      'group'    => self::get_request_value( 'ghca_pri_group' ),
      'priority' => $priority,
      'search'   => self::get_request_value( 'ghca_pri_search' ),
      'page'     => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_pri_page', '1' ) ),
      'per_page' => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_pri_per', '15' ) ),
      'orderby'  => self::sanitize_orderby( self::get_request_value( 'ghca_orderby' ) ),
      'order'    => in_array( self::get_request_value( 'ghca_order', 'asc' ), array( 'asc', 'desc' ), true ) ? self::get_request_value( 'ghca_order', 'asc' ) : 'asc',
    );
  }

  /** @return array<string,mixed> */
  private static function get_course_filters(): array {
    $certificate = self::get_request_value( 'ghca_crs_cert' );
    if ( ! in_array( $certificate, array( '', 'yes', 'no' ), true ) ) {
      $certificate = '';
    }

    return array(
      'group'       => self::get_request_value( 'ghca_crs_group' ),
      'search'      => self::get_request_value( 'ghca_crs_search' ),
      'certificate' => $certificate,
      'page'        => GHCA_ACD_Table_UI::normalize_page( (int) self::get_request_value( 'ghca_crs_page', '1' ) ),
      'per_page'    => GHCA_ACD_Table_UI::normalize_per_page( (int) self::get_request_value( 'ghca_crs_per', '15' ) ),
    );
  }

  /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
  private static function get_employee_table_rows( array $filters ): array {
    $rows = self::get_aggregate()['employees'];

    if ( $filters['group'] !== '' ) {
      $group_id = (int) $filters['group'];
      $rows     = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $group_id ): bool {
            return (int) $row['group_id'] === $group_id;
          }
        )
      );
    }

    if ( $filters['status'] !== '' ) {
      $rows = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $filters ): bool {
            $status = $row['status_slug'];
            if ( $filters['status'] === 'completed' ) {
              return in_array( $status, array( 'completed', 'new_hire_completed' ), true );
            }
            if ( $filters['status'] === 'expiring_soon' ) {
              return $status === 'expiring_soon';
            }
            if ( $filters['status'] === 'expired' ) {
              return $status === 'expired';
            }
            if ( $filters['status'] === 'overdue' ) {
              // "Everyone lapsed": never-finished overdue + expired re-certs.
              return in_array( $status, array( 'overdue', 'new_hire_overdue', 'expired' ), true );
            }
            if ( $filters['status'] === 'in_progress' ) {
              return in_array( $status, array( 'in_progress', 'new_hire_in_progress', 'new_hire_not_started' ), true );
            }
            if ( $filters['status'] === 'not_started' ) {
              return $status === 'not_started';
            }
            return $status === $filters['status'];
          }
        )
      );
    }

    if ( $filters['course'] !== '' ) {
      $course_id = (int) $filters['course'];
      $rows      = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $course_id ): bool {
            foreach ( $row['courses'] as $course ) {
              if ( (int) $course['id'] === $course_id ) {
                return true;
              }
            }
            return false;
          }
        )
      );
    }

    if ( $filters['overdue_only'] ) {
      $rows = array_values(
        array_filter(
          $rows,
          static function ( array $row ): bool {
            return in_array( $row['status_slug'], array( 'overdue', 'new_hire_overdue', 'expired' ), true );
          }
        )
      );
    }

    if ( ! empty( $filters['search'] ) ) {
      $search = (string) $filters['search'];
      $rows   = array_values(
        array_filter(
          $rows,
          static function ( array $row ) use ( $search ): bool {
            return GHCA_ACD_Table_UI::matches_search( $search, array( $row['name'], $row['email'], $row['group'] ) );
          }
        )
      );
    }

    $orderby = $filters['orderby'] ?? '';
    $order   = $filters['order'] ?? 'asc';

    if ( $orderby && in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
      usort(
        $rows,
        static function ( array $a, array $b ) use ( $orderby, $order ): int {
          $val_a = $a[ $orderby ] ?? '';
          $val_b = $b[ $orderby ] ?? '';
          
          if ( $val_a === $val_b ) {
            return 0;
          }
          
          $cmp = 0;
          if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
             $cmp = (float) $val_a < (float) $val_b ? -1 : 1;
          } else {
             $cmp = strcasecmp( (string) $val_a, (string) $val_b );
          }
          
          return $order === 'desc' ? -$cmp : $cmp;
        }
      );
    }

    return $rows;
  }

  /** @return array<int,string> */
  private static function get_group_options(): array {
    $options = array();
    foreach ( self::get_tracked_group_ids() as $group_id ) {
      $options[ $group_id ] = get_the_title( $group_id );
    }
    return $options;
  }

  /** @return array<int,string> */
  private static function get_course_options(): array {
    $options = array();
    foreach ( self::get_aggregate()['employees'] as $employee ) {
      foreach ( $employee['courses'] as $course ) {
        $options[ (int) $course['id'] ] = $course['title'];
      }
    }
    asort( $options );
    return $options;
  }

  /** @return array<string,string> */
  private static function get_status_options(): array {
    return array(
      'completed'     => __( 'Completed', 'ghca-acd' ),
      'expiring_soon' => __( 'Expiring Soon', 'ghca-acd' ),
      'in_progress'   => __( 'In Progress', 'ghca-acd' ),
      'not_started'   => __( 'Not Started', 'ghca-acd' ),
      'overdue'       => __( 'Overdue (incl. expired)', 'ghca-acd' ),
      'expired'       => __( 'Expired', 'ghca-acd' ),
    );
  }

  /** @param array<int,array<string,mixed>> $employees */
  private static function count_unique_tracked_courses( array $employees ): int {
    $course_ids = array();
    foreach ( $employees as $employee ) {
      foreach ( $employee['courses'] ?? array() as $course ) {
        $course_ids[ (int) ( $course['id'] ?? 0 ) ] = true;
      }
    }
    unset( $course_ids[0] );
    return count( $course_ids );
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
          'url'     => current_user_can( 'edit_users' ) ? admin_url( 'users.php' ) : self::get_page_url( 'group-management', '/group-management/' ),
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
        'url'      => current_user_can( 'manage_options' ) ? admin_url( 'edit.php?post_type=sfwd-courses' ) : self::get_page_url( 'my-courses', '/my-courses/' ),
      ),
      array(
        'label'    => __( 'Users', 'ghca-acd' ),
        'subtitle' => __( 'Employee accounts', 'ghca-acd' ),
        'icon'     => 'users',
        'url'      => current_user_can( 'edit_users' ) ? admin_url( 'users.php' ) : self::get_page_url( 'group-management', '/group-management/' ),
      ),
      array(
        'label'    => __( 'Reports', 'ghca-acd' ),
        'subtitle' => __( 'Training analytics', 'ghca-acd' ),
        'icon'     => 'reports',
        'url'      => self::get_page_url( 'reports', '/reports/' ),
      ),
      array(
        'label'    => __( 'Certificates', 'ghca-acd' ),
        'subtitle' => __( 'Issued credentials', 'ghca-acd' ),
        'icon'     => 'certificate',
        'url'      => self::get_page_url( 'cert-download', '/cert-download/' ),
      ),
      array(
        'label'    => __( 'Employee Dashboard', 'ghca-acd' ),
        'subtitle' => __( 'Learner home view', 'ghca-acd' ),
        'icon'     => 'dashboard',
        'url'      => self::get_page_url( 'employee-dashboard', '/employee-dashboard/' ),
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

  /**
   * Builds the announcement list shown in the panel.
   *
   * Admin-authored announcements (the ghca-announce CPT) come first and are
   * editable; the live, auto-generated system items (overdue count, last data
   * refresh) follow and are read-only.
   *
   * @return array<int,array{id:int,label:string,body:string,type:string,url:string,editable:bool}>
   */
  private static function get_announcement_items(): array {
    $can_manage = self::can_manage_announcements();
    $items      = array();

    $posts = get_posts(
      array(
        'post_type'              => 'ghca-announce',
        'post_status'            => 'publish',
        'posts_per_page'         => 10,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
      )
    );

    foreach ( $posts as $post ) {
      $items[] = array(
        'id'       => (int) $post->ID,
        'label'    => get_the_title( $post ),
        'body'     => trim( wp_strip_all_tags( (string) $post->post_content ) ),
        'type'     => self::sanitize_announcement_type( get_post_meta( $post->ID, 'ghca_announce_type', true ) ),
        'url'      => (string) get_post_meta( $post->ID, 'ghca_announce_url', true ),
        'editable' => $can_manage,
      );
    }

    foreach ( self::get_auto_announcement_items() as $auto ) {
      $items[] = array_merge(
        array( 'id' => 0, 'body' => '', 'url' => '', 'editable' => false ),
        $auto
      );
    }

    /** @var array<int,array<string,mixed>> $items */
    return apply_filters( 'ghca_admin_announcements_items', $items );
  }

  /** Live, read-only announcement items derived from current compliance data. */
  private static function get_auto_announcement_items(): array {
    $data         = self::get_aggregate();
    $overdue      = (int) ( $data['overdue_employees'] ?? 0 );
    $sync_time    = wp_date( 'g:i A' );
    $overdue_page = self::get_admin_dashboard_url() . '#ghca-overdue-employees';

    $items = array();

    if ( $overdue > 0 ) {
      $items[] = array(
        'label' => sprintf(
          /* translators: %d: number of overdue employees */
          _n( '%d employee is overdue', '%d employees are overdue', $overdue, 'ghca-acd' ),
          $overdue
        ),
        'body'  => __( 'Review the Overdue list and send reminders before the end of the week.', 'ghca-acd' ),
        'type'  => 'alert',
        'url'   => $overdue_page,
      );
    }

    $items[] = array(
      'label' => __( 'Training data refreshed', 'ghca-acd' ),
      'body'  => sprintf(
        /* translators: %s: time of day */
        __( 'LearnDash progress was last loaded at %s. Data refreshes automatically.', 'ghca-acd' ),
        $sync_time
      ),
      'type'  => 'system',
    );

    return $items;
  }

  private static function get_user_full_name( int $user_id, ?WP_User $user = null ): string {
    if ( null === $user ) {
      $user = get_userdata( $user_id );
    }
    if ( ! $user ) {
      return __( 'Unknown user', 'ghca-acd' );
    }

    $first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
    $last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
    $full  = trim( $first . ' ' . $last );

    if ( '' !== $full ) {
      return $full;
    }

    if ( '' !== trim( (string) $user->display_name ) ) {
      return $user->display_name;
    }

    return $user->user_login;
  }

  private static function get_user_group_label( int $user_id ): string {
    $group_id = self::get_user_primary_group_id( $user_id );
    return $group_id ? (string) get_the_title( $group_id ) : __( 'Unassigned', 'ghca-acd' );
  }

  private static function get_user_primary_group_id( int $user_id ): int {
    if ( GHCA_Compliance_Program::user_requires_new_hire_tracking( $user_id ) ) {
      $status = GHCA_Compliance_Program::get_user_status( $user_id );
      if ( ! empty( $status['group_id'] ) ) {
        return (int) $status['group_id'];
      }
    }

    foreach ( self::get_compliance_group_ids() as $group_id ) {
      if ( function_exists( 'learndash_is_user_in_group' ) && learndash_is_user_in_group( $user_id, $group_id ) ) {
        return (int) $group_id;
      }
    }
    return 0;
  }

  private static function course_has_certificate( int $course_id ): bool {
    return ! empty( get_post_meta( $course_id, '_ld_certificate', true ) );
  }

  private static function get_certificate_url( int $user_id, int $course_id ): string {
    if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
      $link = learndash_get_course_certificate_link( $course_id, $user_id );
      if ( is_string( $link ) && $link !== '' ) {
        return $link;
      }
    }

    $uo = get_user_meta( $user_id, '_uo-course-cert-' . $course_id, true );
    if ( is_array( $uo ) && ! empty( $uo ) ) {
      $first = reset( $uo );
      if ( is_string( $first ) && $first !== '' ) {
        return $first;
      }
    }

    return '';
  }

  private static function get_course_completed_timestamp( int $user_id, int $course_id ): int {
    $activity = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
    return is_numeric( $activity ) ? (int) $activity : 0;
  }

  private static function get_course_last_activity_timestamp( int $user_id, int $course_id, int $completed_ts = 0 ): int {
    if ( $completed_ts > 0 ) {
      return $completed_ts;
    }

    if ( function_exists( 'learndash_user_get_course_progress' ) ) {
      $progress = learndash_user_get_course_progress( $user_id, $course_id );
      if ( is_array( $progress ) && ! empty( $progress['last_activity'] ) && is_numeric( $progress['last_activity'] ) ) {
        return (int) $progress['last_activity'];
      }
    }

    return 0;
  }

  /** @param array<int,array<string,mixed>> $courses */
  private static function get_last_activity_label( int $user_id, array $courses ): string {
    $latest = 0;
    foreach ( $courses as $course ) {
      $ts = (int) ( $course['last_activity_ts'] ?? 0 );
      if ( $ts > $latest ) {
        $latest = $ts;
      }
    }
    return self::format_timestamp_label( $latest );
  }

  private static function format_timestamp_label( int $timestamp ): string {
    if ( $timestamp <= 0 ) {
      return __( 'No activity', 'ghca-acd' );
    }
    return wp_date( 'M j, Y', $timestamp );
  }

  private static function normalize_course_title( string $title ): string {
    return mb_strtoupper( trim( $title ), 'UTF-8' );
  }

  private static function course_status_label( string $status ): string {
    $map = array(
      'not_started' => __( 'Not Started', 'ghca-acd' ),
      'in_progress' => __( 'In Progress', 'ghca-acd' ),
      'completed'   => __( 'Completed', 'ghca-acd' ),
      'overdue'     => __( 'Overdue', 'ghca-acd' ),
    );
    return $map[ $status ] ?? __( 'Unknown', 'ghca-acd' );
  }

  private static function format_due_date( string $raw ): string {
    $timestamp = strtotime( $raw );
    if ( ! $timestamp ) {
      return $raw;
    }
    return wp_date( 'F j, Y', $timestamp );
  }

  /** @return array<string,mixed> */
  private static function get_compliance_cycle( int $user_id ): array {
    $start = self::get_platform_start_timestamp( $user_id );
    if ( ! $start ) {
      return array(
        'due_timestamp'  => strtotime( get_option( self::OPTION_DUE_DATE, '2026-07-31' ) ) ?: 0,
        'due_date_label' => self::format_due_date( get_option( self::OPTION_DUE_DATE, '2026-07-31' ) ),
      );
    }

    $now           = time();
    $days_on       = (int) floor( max( 0, $now - $start ) / DAY_IN_SECONDS );
    $cycle_number  = (int) floor( $days_on / self::CYCLE_DAYS ) + 1;
    $due_timestamp = $start + ( $cycle_number * self::CYCLE_DAYS * DAY_IN_SECONDS );

    return array(
      'due_timestamp'  => $due_timestamp,
      'due_date_label' => wp_date( 'F j, Y', $due_timestamp ),
    );
  }

  private static function get_platform_start_timestamp( int $user_id ): int {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
      return 0;
    }

    $candidates = array( strtotime( $user->user_registered ) );
    foreach ( self::get_compliance_group_ids() as $group_id ) {
      foreach ( array(
        'group_' . $group_id . '_access_from',
        'learndash_group_' . $group_id . '_enrolled_at',
      ) as $meta_key ) {
        $value = get_user_meta( $user_id, $meta_key, true );
        if ( is_numeric( $value ) && (int) $value > 0 ) {
          $candidates[] = (int) $value;
        }
      }
    }

    $candidates = array_values( array_filter( $candidates ) );
    return empty( $candidates ) ? 0 : (int) min( $candidates );
  }

  public static function build_user_report_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( self::get_page_url( 'cert-download', '/cert-download/' ) ) . '">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</a>',
    );
    $links = array_merge( $links, GHCA_ACD_FluentCRM::build_action_links( $user_id, $email ) );
    $links[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html__( 'Contact Employee', 'ghca-acd' ) . '</a>';

    if ( current_user_can( 'edit_users' ) ) {
      $links[] = '<a href="' . esc_url( get_edit_user_link( $user_id ) ?: admin_url( 'users.php' ) ) . '">' . esc_html__( 'User Profile', 'ghca-acd' ) . '</a>';
    }

    return '<div class="ghca-acd__action-chips">' . implode( '', $links ) . '</div>';
  }

  private static function build_employee_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( self::get_user_report_url( $user_id ) ) . '">' . esc_html__( 'View Training', 'ghca-acd' ) . '</a>',
      '<a href="' . esc_url( self::get_page_url( 'cert-download', '/cert-download/' ) ) . '">' . esc_html__( 'Certificates', 'ghca-acd' ) . '</a>',
    );
    $links = array_merge( $links, GHCA_ACD_FluentCRM::build_action_links( $user_id, $email ) );

    if ( current_user_can( 'edit_users' ) ) {
      $links[] = '<a href="' . esc_url( get_edit_user_link( $user_id ) ?: admin_url( 'users.php' ) ) . '">' . esc_html__( 'User Profile', 'ghca-acd' ) . '</a>';
    }

    return '<div class="ghca-acd__action-chips">' . implode( '', $links ) . '</div>';
  }

  private static function build_priority_actions_html( int $user_id, string $email ): string {
    $links = array(
      '<a href="' . esc_url( self::get_user_report_url( $user_id ) ) . '">' . esc_html__( 'View User', 'ghca-acd' ) . '</a>',
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

  /** @var array<string,string> */
  private static $page_url_cache = array();

  private static function get_page_url( string $slug, string $fallback ): string {
    if ( isset( self::$page_url_cache[ $slug ] ) ) {
      return self::$page_url_cache[ $slug ];
    }

    $page = get_page_by_path( $slug );
    $url  = $page ? get_permalink( $page ) : home_url( $fallback );

    self::$page_url_cache[ $slug ] = $url;
    return $url;
  }

  private static function sanitize_orderby( string $value ): string {
    return in_array( $value, self::SORTABLE_COLUMNS, true ) ? $value : '';
  }

  /** Progress-bar colour modifier based on completion percent. */
  public static function get_progress_class( int $pct ): string {
    if ( $pct >= 80 ) {
      return 'ghca-acd__progress-bar--success';
    }
    if ( $pct >= 50 ) {
      return 'ghca-acd__progress-bar--warning';
    }
    return 'ghca-acd__progress-bar--danger';
  }

  /** Two-letter initials for an employee avatar. */
  public static function get_avatar_initials( string $name ): string {
    $parts    = preg_split( '/\s+/', trim( $name ) ) ?: array();
    $initials = '';
    foreach ( $parts as $part ) {
      if ( $part === '' ) {
        continue;
      }
      $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
      if ( mb_strlen( $initials ) >= 2 ) {
        break;
      }
    }
    return $initials !== '' ? $initials : '–';
  }

  /** Deterministic, calm avatar tint derived from the user id. */
  public static function get_avatar_style( int $user_id ): string {
    $palette = array(
      array( '#eef6fc', '#176cad' ),
      array( '#ecfdf3', '#067647' ),
      array( '#fffaeb', '#b54708' ),
      array( '#f4f0ff', '#6938ef' ),
      array( '#fef3f2', '#b42318' ),
      array( '#eefcfb', '#0e7090' ),
    );
    list( $bg, $fg ) = $palette[ $user_id % count( $palette ) ];
    return sprintf( 'background:%s;color:%s;', $bg, $fg );
  }
}

GHCA_Admin_Compliance_Dashboard::init();

register_activation_hook( __FILE__, static function (): void {
  GHCA_ACD_Roles::register_roles();
} );
