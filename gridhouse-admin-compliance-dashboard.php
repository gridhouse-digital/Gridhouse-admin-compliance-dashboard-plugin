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
require_once __DIR__ . '/includes/class-data-provider.php';
require_once __DIR__ . '/includes/class-shortcodes.php';
require_once __DIR__ . '/includes/class-export.php';
require_once __DIR__ . '/includes/class-fluentcrm.php';
require_once __DIR__ . '/includes/class-nav.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-user-report.php';
require_once __DIR__ . '/includes/class-table-ui.php';
require_once __DIR__ . '/includes/class-manage-users-ui.php';
require_once __DIR__ . '/includes/class-audit-mapping.php';
require_once __DIR__ . '/includes/class-audit-calculator.php';
require_once __DIR__ . '/includes/class-audit-export.php';
require_once __DIR__ . '/includes/class-audit-pdf.php';
require_once __DIR__ . '/includes/class-audit-ui.php';

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

  public static function init(): void {
    GHCA_ACD_Roles::init();
    GHCA_ACD_Scoping::init();
    GHCA_ACD_Export::init();
    GHCA_ACD_FluentCRM::init();
    GHCA_ACD_Nav::init();
    GHCA_ACD_Settings::init();
    GHCA_Audit_Mapping::init();
    GHCA_Audit_Export::init();
    GHCA_Audit_PDF::init();
    GHCA_ACD_Audit_UI::init();
    GHCA_Compliance_Program::init();
    GHCA_ACD_User_Report::init();
    GHCA_ACD_Manage_Users_UI::init();
    GHCA_ACD_Shortcodes::init();
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
    add_action( 'wp_ajax_ghca_acd_save_employee', array( __CLASS__, 'ajax_save_employee' ) );
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

    $employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );
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
    $report_url = GHCA_ACD_Data_Provider::get_user_report_url( $user_id );

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
      <div class="ghca-acd__drawer-actions-row">
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_download_packet&tracker=orientation&user_id=' . $user_id ), 'ghca_acd_download_packet' ) ); ?>" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary" style="background-color: var(--ent-dark);"><?php esc_html_e( 'Orientation Packet', 'ghca-acd' ); ?></a>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_download_packet&tracker=annual&user_id=' . $user_id ), 'ghca_acd_download_packet' ) ); ?>" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary" style="background-color: var(--ent-dark);"><?php esc_html_e( 'Annual Packet', 'ghca-acd' ); ?></a>
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
    $by    = $by_id > 0 ? GHCA_ACD_Data_Provider::get_user_full_name( $by_id ) : __( 'an admin', 'ghca-acd' );
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

    $employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );

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

    GHCA_ACD_Data_Provider::bust_cache();

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
      'admin_compliance_manage_users',
    );

    foreach ( $tags as $tag ) {
      if ( has_shortcode( $post->post_content, $tag ) ) {
        return true;
      }
    }

    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
    return is_string( $elementor_data ) && strpos( $elementor_data, 'admin_compliance' ) !== false;
  }

  public static function handle_sync_request(): void {
    if ( empty( $_GET['ghca_acd_sync'] ) ) {
      return;
    }

    if ( ! is_user_logged_in() || ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      return;
    }

    check_admin_referer( 'ghca_acd_sync', 'ghca_nonce' );

    GHCA_ACD_Data_Provider::bust_cache();

    $redirect = remove_query_arg( array( 'ghca_acd_sync', 'ghca_nonce', '_wpnonce' ) );
    wp_safe_redirect( add_query_arg( 'ghca_acd_synced', '1', $redirect ) );
    exit;
  }

  public static function ajax_filter_table(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'ghca-acd' ) ), 403 );
    }

    $table = isset( $_POST['ghca_table'] ) ? sanitize_key( wp_unslash( (string) $_POST['ghca_table'] ) ) : 'employees';

    switch ( $table ) {
      case 'priority':
        $filters = GHCA_ACD_Data_Provider::get_priority_filters();
        $html    = GHCA_ACD_Shortcodes::get_priority_table_html( $filters );
        break;
      case 'courses':
        $filters = GHCA_ACD_Data_Provider::get_course_filters();
        $html    = GHCA_ACD_Shortcodes::get_course_table_html( $filters );
        break;
      default:
        $filters = GHCA_ACD_Data_Provider::get_employee_filters();
        $html    = GHCA_ACD_Shortcodes::get_employee_table_html( $filters );
        break;
    }

    wp_send_json_success( array( 'html' => $html ) );
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
    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
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

    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    wp_send_json_success( array( 'html' => self::render_announcement_list_html() ) );
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
    $data         = GHCA_ACD_Data_Provider::get_aggregate();
    $overdue      = (int) ( $data['overdue_employees'] ?? 0 );
    $sync_time    = wp_date( 'g:i A' );
    $overdue_page = GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-overdue-employees';

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

  public static function ajax_save_employee(): void {
    check_ajax_referer( 'ghca_save_employee', 'ghca_nonce' );

    if ( ! GHCA_ACD_Roles::user_can_manage_users() ) {
      wp_send_json_error( __( 'Permission denied.', 'ghca-acd' ) );
    }

    $user_id    = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $role       = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'subscriber';
    $groups     = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'intval', wp_unslash( $_POST['groups'] ) ) : array();

    if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
      wp_send_json_error( __( 'Please fill in all required fields.', 'ghca-acd' ) );
    }

    if ( ! is_email( $email ) ) {
      wp_send_json_error( __( 'Invalid email address.', 'ghca-acd' ) );
    }

    $visible_groups = GHCA_ACD_Scoping::get_visible_group_ids();
    foreach ( $groups as $gid ) {
      if ( ! in_array( $gid, $visible_groups, true ) ) {
        wp_send_json_error( __( 'You do not have permission to assign this group.', 'ghca-acd' ) );
      }
    }

    $userdata = array(
      'user_email'   => $email,
      'first_name'   => $first_name,
      'last_name'    => $last_name,
      'display_name' => trim( $first_name . ' ' . $last_name ),
    );

    // Validate role against editable roles
    $all_roles = wp_roles()->roles;
    $editable_roles = apply_filters( 'editable_roles', $all_roles );
    if ( ! current_user_can( 'manage_options' ) ) {
      unset( $editable_roles['administrator'] );
    }
    
    if ( array_key_exists( $role, $editable_roles ) ) {
      $userdata['role'] = $role;
    }

    if ( $user_id > 0 ) {
      $userdata['ID'] = $user_id;
      
      // Prevent editing administrators if current user is not one
      $existing_user = get_userdata( $user_id );
      if ( $existing_user && in_array( 'administrator', (array) $existing_user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You cannot modify an administrator account.', 'ghca-acd' ) );
      }
      
      $result = wp_update_user( $userdata );
    } else {
      $userdata['user_login'] = $email;
      $userdata['user_pass']  = wp_generate_password( 20, true, true );
      if ( ! isset( $userdata['role'] ) ) {
        $userdata['role'] = 'subscriber';
      }
      $result = wp_insert_user( $userdata );
    }

    if ( is_wp_error( $result ) ) {
      wp_send_json_error( $result->get_error_message() );
    }

    $saved_user_id = $user_id > 0 ? $user_id : $result;

    update_user_meta( $saved_user_id, 'billing_phone', $phone );
    update_user_meta( $saved_user_id, 'phone', $phone );
    if ( function_exists( 'xprofile_set_field_data' ) ) {
      xprofile_set_field_data( 'Phone', $saved_user_id, $phone );
      xprofile_set_field_data( 'Phone Number', $saved_user_id, $phone );
    }

    if ( function_exists( 'learndash_set_users_group_ids' ) ) {
      $existing_groups = function_exists( 'learndash_get_users_group_ids' ) ? learndash_get_users_group_ids( $saved_user_id ) : array();
      $unmanageable_groups = array_diff( $existing_groups, $visible_groups );
      $final_groups = array_unique( array_merge( $unmanageable_groups, $groups ) );
      learndash_set_users_group_ids( $saved_user_id, $final_groups );
    }

    GHCA_ACD_Settings::bust_dashboard_cache();
    wp_send_json_success( __( 'Employee saved successfully.', 'ghca-acd' ) );
  }
}

GHCA_Admin_Compliance_Dashboard::init();

register_activation_hook( __FILE__, static function (): void {
  GHCA_ACD_Roles::register_roles();
} );
