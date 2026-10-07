<?php
/**
 * Controller layer for the compliance dashboard.
 *
 * Owns all wp_ajax_* endpoints, the wp_footer modal shells they drive
 * (certificate preview, employee drawer, edit records), and non-AJAX form
 * processing (sync request). Extracted from GHCA_Admin_Compliance_Dashboard
 * (Phase 6 core file refactor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-employment-record.php';

final class GHCA_ACD_AJAX {

  public static function init(): void {
    add_action( 'wp_ajax_ghca_acd_filter_table', array( __CLASS__, 'ajax_filter_table' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_certificate_modal' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_employee_drawer_modal' ) );
    add_action( 'wp_ajax_ghca_acd_get_employee_drawer', array( __CLASS__, 'ajax_get_employee_drawer' ) );
    add_action( 'wp_ajax_ghca_acd_mark_reviewed', array( __CLASS__, 'ajax_mark_reviewed' ) );
    // Edit Records is now a sub-page inside the drawer's Administration tab,
    // so the standalone modal shell is no longer rendered.
    add_action( 'wp_footer', array( __CLASS__, 'render_pdf_progress_modal' ) );
    add_action( 'wp_ajax_ghca_acd_get_edit_records_form', array( __CLASS__, 'ajax_get_edit_records_form' ) );
    add_action( 'wp_ajax_ghca_acd_save_employee_records', array( __CLASS__, 'ajax_save_employee_records' ) );
    add_action( 'wp_ajax_ghca_acd_save_employee', array( __CLASS__, 'ajax_save_employee' ) );
    add_action( 'init', array( __CLASS__, 'handle_sync_request' ), 15 );
  }

  public static function render_certificate_modal(): void {
    if ( ! is_singular() ) {
      return;
    }

    $post = get_post();
    if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__cert-modal ghca-acd__overlay ghca-acd__overlay--certificate" id="ghca-acd-cert-modal" hidden aria-hidden="true">
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
    if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__drawer ghca-acd__drawer--employee ghca-acd__overlay ghca-acd__overlay--drawer" id="ghca-acd-employee-drawer" hidden aria-hidden="true">
      <div class="ghca-acd__drawer-backdrop" data-ghca-drawer-close></div>
      <div class="ghca-acd__drawer-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-employee-drawer-title">
        <div class="ghca-acd__drawer-header">
          <span class="ghca-acd__drawer-header-label ghca-acd__sr-only" id="ghca-acd-employee-drawer-title"><?php esc_html_e( 'Employee details', 'ghca-acd' ); ?></span>
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

    $employee_name = (string) ( $employee['name'] ?? '' );
    $employee_email = (string) ( $employee['email'] ?? '' );
    $group_label = (string) ( $employee['group'] ?? '' );

    // Build initials for the identity block without changing the source record.
    $name_parts = preg_split( '/\s+/', trim( $employee_name ) );
    $initials   = '';
    foreach ( (array) $name_parts as $part ) {
      if ( '' === $part ) {
        continue;
      }
      $initials .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
      if ( strlen( $initials ) >= 2 ) {
        break;
      }
    }
    $initials = '' !== $initials ? $initials : '?';

    $status_slug  = (string) ( $employee['status_slug'] ?? 'not_started' );
    $status_label = (string) ( $employee['status_label'] ?? '' );
    if ( '' === $status_label ) {
      $status_label = __( 'Needs attention', 'ghca-acd' );
    }

    // Keep the existing calculated values; this view only groups them differently.
    $progress_pct    = (int) ( $employee['progress_pct'] ?? 0 );
    $completed_count = 0;
    $total_courses   = count( $employee['courses'] ?? array() );
    $cert_count      = 0;
    foreach ( $employee['courses'] ?? array() as $course ) {
      if ( ! empty( $course['completed'] ) ) {
        $completed_count++;
      }
      if ( ! empty( $course['certificate_url'] ) ) {
        $cert_count++;
      }
    }
    $due_date_label = (string) ( $employee['due_date_label'] ?? '' );
    $due_date_label = '' !== $due_date_label ? $due_date_label : '—';

    $pct_class = 'danger';
    if ( $progress_pct >= 80 ) {
      $pct_class = 'success';
    } elseif ( $progress_pct >= 40 ) {
      $pct_class = 'warning';
    }

    $status_labels = array(
      'completed'     => __( 'Compliant', 'ghca-acd' ),
      'expiring_soon' => __( 'Expiring soon', 'ghca-acd' ),
      'expired'       => __( 'Expired', 'ghca-acd' ),
      'in_progress'   => __( 'In progress', 'ghca-acd' ),
      'overdue'       => __( 'Overdue', 'ghca-acd' ),
      'not_started'   => __( 'Not started', 'ghca-acd' ),
    );
    $audit_mapping  = class_exists( 'GHCA_Audit_Mapping' ) ? (array) get_option( GHCA_Audit_Mapping::OPTION_NAME, array() ) : array();
    $category_labels = class_exists( 'GHCA_Audit_Mapping' ) ? GHCA_Audit_Mapping::get_odp_categories() : array();
    $format_course_title = static function ( string $title ): string {
      return self::display_course_title( $title );
    };
    $priority = array( 'overdue' => 0, 'new_hire_overdue' => 0, 'expired' => 1, 'expiring_soon' => 2, 'in_progress' => 3, 'new_hire_in_progress' => 3, 'not_started' => 4, 'new_hire_not_started' => 4 );
    $attention_courses = array();
    $completed_courses = array();
    foreach ( $employee['courses'] ?? array() as $course ) {
      $cstate = (string) ( $course['compliance_state'] ?? '' );
      if ( 'expired' === $cstate ) {
        $course_status = 'expired';
      } elseif ( 'expiring_soon' === $cstate ) {
        $course_status = 'expiring_soon';
      } else {
        $course_status = ! empty( $course['completed'] ) ? 'completed' : (string) ( $course['status'] ?? 'not_started' );
      }
      if ( '' === $course_status ) {
        $course_status = 'not_started';
      }
      $course_pct = (int) ( $course['progress'] ?? 0 );
      $bar_class  = 'info';
      if ( 'completed' === $course_status ) {
        $bar_class = 'success';
      } elseif ( in_array( $course_status, array( 'expired', 'overdue', 'new_hire_overdue' ), true ) ) {
        $bar_class = 'danger';
      } elseif ( 'expiring_soon' === $course_status ) {
        $bar_class = 'warning';
      } elseif ( 'new_hire_in_progress' === $course_status ) {
        $bar_class = 'info';
      } elseif ( 0 === $course_pct ) {
        $bar_class = 'danger';
      }
      $row = array(
        'course' => $course,
        'status' => $course_status,
        'label'  => $status_labels[ $course_status ] ?? (string) ( $course['status_label'] ?? ucfirst( str_replace( '_', ' ', $course_status ) ) ),
        'pct'    => $course_pct,
        'bar'    => $bar_class,
        'hours'  => (float) ( $audit_mapping[ (int) ( $course['id'] ?? 0 ) ]['credit_hours'] ?? 0 ),
        'category' => (string) ( $category_labels[ (string) ( $audit_mapping[ (int) ( $course['id'] ?? 0 ) ]['odp_category'] ?? '' ) ] ?? '' ),
        'display_title' => $format_course_title( (string) ( $course['title'] ?? '' ) ),
        'order'  => count( $attention_courses ) + count( $completed_courses ),
      );
      if ( 'completed' === $course_status ) {
        $completed_courses[] = $row;
      } else {
        $attention_courses[] = $row;
      }
    }
    usort( $attention_courses, static function ( array $left, array $right ) use ( $priority ): int {
      $left_priority  = $priority[ $left['status'] ] ?? 99;
      $right_priority = $priority[ $right['status'] ] ?? 99;
      if ( $left_priority === $right_priority ) {
        return $left['order'] <=> $right['order'];
      }
      return $left_priority <=> $right_priority;
    } );

    // Communication data is queried only after both the permission and schema gates.
    $can_communications      = GHCA_ACD_Roles::user_can_view_communication_history();
    $history_schema_ready    = false;
    $last_reminder_label     = '';
    $communication_count     = 0;
    if ( $can_communications && class_exists( 'GHCA_ACD_Messaging_Schema' ) && class_exists( 'GHCA_ACD_Messaging_Repository' ) ) {
      $history_schema_ready = (int) get_option( GHCA_ACD_Messaging_Schema::OPTION_VERSION, 0 ) >= (int) GHCA_ACD_Messaging_Schema::VERSION && GHCA_ACD_Messaging_Schema::tables_exist();
      if ( $history_schema_ready ) {
        $communication_count = GHCA_ACD_Messaging_Repository::history_count( $user_id );
        $last_rows = GHCA_ACD_Messaging_Repository::history_for_employee( $user_id, 1, 1 );
        if ( is_array( $last_rows ) && ! empty( $last_rows[0] ) ) {
          $created_at = (string) ( $last_rows[0]['created_at'] ?? '' );
          $created_ts = $created_at ? strtotime( $created_at . ' UTC' ) : 0;
          if ( $created_ts > 0 ) {
            $last_reminder_label = wp_date( 'M j, Y g:i a', $created_ts );
          }
        }
      }
    }

    $can_documents = GHCA_ACD_Roles::user_can_view_employee_documents();
    $can_admin     = GHCA_ACD_Roles::user_can_edit_records();
    $document_count = 0;
    if ( $can_documents && class_exists( 'GHCA_ACD_Jotform_Schema' ) && class_exists( 'GHCA_ACD_Jotform_Repository' ) && GHCA_ACD_Jotform_Schema::tables_exist() ) {
      $document_count = GHCA_ACD_Jotform_Repository::employee_record_count( $user_id );
    }
    $documents_html = $can_documents ? (string) GHCA_ACD_Jotform_UI::render_recent_documents( $user_id ) : '';
    $manual_html    = $can_documents && class_exists( 'GHCA_ACD_Jotform_UI' ) && method_exists( 'GHCA_ACD_Jotform_UI', 'render_manual_entry_form' ) ? (string) GHCA_ACD_Jotform_UI::render_manual_entry_form( $user_id ) : '';
    $history_html   = $can_communications ? (string) GHCA_ACD_Messaging_UI::render_history_section( $user_id ) : '';
	$oltl_html      = class_exists( 'GHCA_ACD_OLTL_UI' ) ? GHCA_ACD_OLTL_UI::render_employee_card( $user_id ) : '';

    $report_url = GHCA_ACD_Data_Provider::get_user_report_url( $user_id );
    $review     = self::get_review_status( $user_id );
    $user       = get_userdata( $user_id );
    $role_label = '';
    if ( $user && ! empty( $user->roles ) && function_exists( 'wp_roles' ) ) {
      $role_keys = (array) $user->roles;
      $role_key  = (string) reset( $role_keys );
      $role_map = wp_roles()->get_names();
      $role_label = (string) ( $role_map[ $role_key ] ?? $role_key );
    }
    $is_suspended      = method_exists( 'GHCA_ACD_Data_Provider', 'is_user_suspended' ) && GHCA_ACD_Data_Provider::is_user_suspended( $user_id );
    $employment_label  = $is_suspended ? __( 'Inactive', 'ghca-acd' ) : __( 'Active', 'ghca-acd' );
    $employment_label .= ' · ' . GHCA_ACD_Data_Provider::employment_type_label( $user_id );
    $employment_class  = $is_suspended ? 'inactive' : 'active';
    $attention_class   = empty( $employee['courses'] ) ? ' is-empty' : ( empty( $attention_courses ) ? ' is-ok' : '' );
    $remaining_count   = count( $attention_courses );
    $due_timestamp     = (int) ( $employee['due_timestamp'] ?? 0 );
    /*
     * Next Due carries the full month/day/year. A bare "Jul 23" is ambiguous
     * across compliance cycles, and this value sits next to overdue counts, so
     * the year has to be explicit. Uses the site date format like the rest of
     * the drawer so it matches the due dates shown on each course row.
     */
    $due_date_short    = $due_timestamp > 0 ? wp_date( get_option( 'date_format', 'M j, Y' ), $due_timestamp ) : $due_date_label;
    $first_attention   = ! empty( $attention_courses ) ? $attention_courses[0] : null;
    $next_due_course   = is_array( $first_attention ) ? (string) $first_attention['display_title'] : '';
    $first_due_label   = is_array( $first_attention ) && ! empty( $first_attention['course']['expiration_label'] ) ? (string) $first_attention['course']['expiration_label'] : $due_date_label;
    $first_due_ts      = is_array( $first_attention ) && ! empty( $first_attention['course']['expiration_ts'] ) ? (int) $first_attention['course']['expiration_ts'] : $due_timestamp;
    $due_context       = '';
    if ( $first_due_ts > 0 ) {
      $days_remaining = (int) ceil( ( $first_due_ts - current_time( 'timestamp', true ) ) / DAY_IN_SECONDS );
      $due_context = $days_remaining >= 0
        ? sprintf( _n( '%d day remaining', '%d days remaining', $days_remaining, 'ghca-acd' ), $days_remaining )
        : sprintf( _n( '%d day overdue', '%d days overdue', abs( $days_remaining ), 'ghca-acd' ), abs( $days_remaining ) );
    }

    $format_record_date = static function ( string $value ): string {
      $timestamp = '' !== trim( $value ) ? strtotime( $value ) : 0;
      return $timestamp ? wp_date( get_option( 'date_format', 'M j, Y' ), $timestamp ) : __( 'Not recorded', 'ghca-acd' );
    };
    $registration_label = $user && ! empty( $user->user_registered ) ? $format_record_date( (string) $user->user_registered ) : __( 'Not recorded', 'ghca-acd' );
    $first_service_label = $format_record_date( (string) get_user_meta( $user_id, 'ghca_first_service_date', true ) );
    $orientation_total = 0;
    $orientation_complete = 0;
    foreach ( $employee['courses'] ?? array() as $course ) {
      if ( empty( $audit_mapping[ (int) ( $course['id'] ?? 0 ) ]['is_orientation'] ) ) {
        continue;
      }
      $orientation_total++;
      $orientation_complete += ! empty( $course['completed'] ) ? 1 : 0;
    }
    $orientation_label = $orientation_total > 0 ? sprintf( __( '%1$d of %2$d complete', 'ghca-acd' ), $orientation_complete, $orientation_total ) : __( 'Not configured', 'ghca-acd' );

    ob_start();
    ?>
    <div class="ghca-acd__drawer-chrome">
      <section class="ghca-acd__drawer-overview" aria-label="<?php esc_attr_e( 'Employee overview', 'ghca-acd' ); ?>">
        <div class="ghca-acd__drawer-profile">
          <div class="ghca-acd__drawer-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></div>
          <div class="ghca-acd__drawer-identity">
            <div class="ghca-acd__drawer-name-row">
              <h3><?php echo esc_html( $employee_name ); ?></h3>
              <div class="ghca-acd__drawer-badges">
                <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--<?php echo esc_attr( sanitize_html_class( $status_slug ) ); ?>"><?php echo esc_html( $status_label ); ?></span>
                <span class="ghca-acd__drawer-badge ghca-acd__drawer-badge--reviewed" data-ghca-review-badge<?php echo $review['reviewed'] ? '' : ' hidden'; ?>><?php echo esc_html( $review['badge'] ); ?></span>
              </div>
            </div>
            <div class="ghca-acd__drawer-meta" aria-label="<?php esc_attr_e( 'Employee role and group', 'ghca-acd' ); ?>">
              <?php if ( $role_label ) : ?><span class="ghca-acd__drawer-meta-item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6V4h6v2m-9 2h12v11H6V8Zm0 4h12M10 8v3m4-3v3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg><?php echo esc_html( $role_label ); ?></span><?php endif; ?>
              <?php if ( $group_label ) : ?><span class="ghca-acd__drawer-meta-item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2 2.2 3-.3.7 2.9 2.6 1.5-1.2 2.7 1.2 2.7-2.6 1.5-.7 2.9-3-.3L12 21l-2-2.2-3 .3-.7-2.9-2.6-1.5L4.9 12 3.7 9.3l2.6-1.5L7 4.9l3 .3L12 3Z" fill="none" stroke="currentColor" stroke-width="1.5"></path><path d="m9.5 12 1.7 1.7 3.5-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg><?php echo esc_html( $group_label ); ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      </section>
      <nav class="ghca-acd__drawer-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Employee records', 'ghca-acd' ); ?>" data-ghca-tablist>
        <button type="button" role="tab" id="ghca-acd-drawer-tab-training" aria-selected="true" aria-controls="ghca-acd-drawer-panel-training" tabindex="0" data-ghca-tab="training"><?php echo esc_html( sprintf( __( 'Training (%d)', 'ghca-acd' ), $total_courses ) ); ?></button>
        <?php if ( $can_documents ) : ?><button type="button" role="tab" id="ghca-acd-drawer-tab-documents" aria-selected="false" aria-controls="ghca-acd-drawer-panel-documents" tabindex="-1" data-ghca-tab="documents"><?php echo esc_html( sprintf( __( 'Documents (%d)', 'ghca-acd' ), $document_count ) ); ?></button><?php endif; ?>
        <?php if ( $can_communications ) : ?><button type="button" role="tab" id="ghca-acd-drawer-tab-communications" aria-selected="false" aria-controls="ghca-acd-drawer-panel-communications" tabindex="-1" data-ghca-tab="communications"><?php echo esc_html( sprintf( __( 'Communications (%d)', 'ghca-acd' ), $communication_count ) ); ?></button><?php endif; ?>
        <?php if ( $can_admin ) : ?><button type="button" role="tab" id="ghca-acd-drawer-tab-administration" aria-selected="false" aria-controls="ghca-acd-drawer-panel-administration" tabindex="-1" data-ghca-tab="administration"><?php esc_html_e( 'Administration', 'ghca-acd' ); ?></button><?php endif; ?>
      </nav>
    </div>

    <div class="ghca-acd__drawer-panels">
      <section class="ghca-acd__drawer-panel ghca-acd__drawer-panel--training" role="tabpanel" id="ghca-acd-drawer-panel-training" aria-labelledby="ghca-acd-drawer-tab-training" data-ghca-tabpanel="training">
        <section class="ghca-acd__drawer-compliance" aria-label="<?php esc_attr_e( 'Overall compliance summary', 'ghca-acd' ); ?>">
          <div class="ghca-acd__drawer-compliance-ring ghca-acd__drawer-compliance-ring--<?php echo esc_attr( $pct_class ); ?>" style="--ghca-progress:<?php echo esc_attr( (string) min( 100, max( 0, $progress_pct ) ) ); ?>"><strong><?php echo esc_html( (string) $progress_pct ); ?></strong><span>%</span></div>
          <div class="ghca-acd__drawer-compliance-copy">
            <h4><?php esc_html_e( 'Overall Compliance', 'ghca-acd' ); ?></h4>
            <div class="ghca-acd__drawer-compliance-stats">
              <span><?php echo self::drawer_icon( 'task_alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( sprintf( __( '%1$d / %2$d Courses', 'ghca-acd' ), $completed_count, $total_courses ) ); ?></span>
              <span><?php echo self::drawer_icon( 'workspace_premium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( sprintf( _n( '%d Certificate', '%d Certificates', $cert_count, 'ghca-acd' ), $cert_count ) ); ?></span>
              <span class="ghca-acd__drawer-compliance-due"><?php echo self::drawer_icon( 'event' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( sprintf( __( 'Next Due: %s', 'ghca-acd' ), $due_date_short ) ); ?></span>
            </div>
          </div>
        </section>

        <?php echo $oltl_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <section class="ghca-acd__drawer-attention<?php echo esc_attr( $attention_class ); ?>" aria-labelledby="ghca-acd-drawer-attention-title">
          <div class="ghca-acd__drawer-attention-copy">
            <div class="ghca-acd__drawer-attention-heading"><span aria-hidden="true"><?php echo self::drawer_icon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><h4 id="ghca-acd-drawer-attention-title"><?php echo esc_html( empty( $attention_courses ) ? __( 'Training is current', 'ghca-acd' ) : __( 'Needs Attention', 'ghca-acd' ) ); ?></h4></div>
            <?php if ( empty( $employee['courses'] ) ) : ?>
              <p class="ghca-acd__drawer-empty"><?php esc_html_e( 'No courses assigned.', 'ghca-acd' ); ?></p>
            <?php elseif ( empty( $attention_courses ) ) : ?>
              <p class="ghca-acd__drawer-attention-ok"><?php esc_html_e( 'All assigned training is current.', 'ghca-acd' ); ?></p>
            <?php else : ?>
              <h5><?php echo esc_html( (string) $first_attention['display_title'] ); ?></h5>
              <p class="ghca-acd__drawer-attention-lead"><?php echo esc_html( '' !== $due_context ? sprintf( __( 'Due %1$s (%2$s)', 'ghca-acd' ), $first_due_label, $due_context ) : sprintf( __( 'Due %s', 'ghca-acd' ), $first_due_label ) ); ?></p>
              <div class="ghca-acd__drawer-attention-progress"><span><i style="width:<?php echo esc_attr( (string) min( 100, max( 0, (int) $first_attention['pct'] ) ) ); ?>%"></i></span><strong><?php echo esc_html( (string) $first_attention['pct'] . '%' ); ?></strong></div>
            <?php endif; ?>
          </div>
          <?php if ( ! empty( $attention_courses ) ) : ?><div class="ghca-acd__drawer-attention-actions">
            <?php if ( ! empty( $first_attention['course']['url'] ) ) : ?><a href="<?php echo esc_url( (string) $first_attention['course']['url'] ); ?>"><?php esc_html_e( 'Review requirement', 'ghca-acd' ); ?></a><?php endif; ?>
            <?php if ( GHCA_ACD_Roles::user_can_send_reminders() ) : ?><button type="button" data-ghca-reminder="<?php echo esc_attr( (string) $user_id ); ?>" data-ghca-reminder-name="<?php echo esc_attr( $employee_name ); ?>"><?php echo self::drawer_icon( 'notifications' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Send reminder', 'ghca-acd' ); ?></button><?php endif; ?>
          </div><?php endif; ?>
        </section>

        <details class="ghca-acd__drawer-disclosure">
          <summary><span class="ghca-acd__drawer-disclosure-label"><span class="ghca-acd__drawer-group-dot ghca-acd__drawer-group-dot--warning" aria-hidden="true"></span><?php echo esc_html( sprintf( __( 'Needs Attention (%d)', 'ghca-acd' ), count( $attention_courses ) ) ); ?></span><span class="ghca-acd__drawer-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="m8 10 4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span></summary>
          <div class="ghca-acd__drawer-course-list">
            <?php if ( empty( $attention_courses ) ) : ?><p class="ghca-acd__drawer-empty"><?php esc_html_e( 'No training needs attention.', 'ghca-acd' ); ?></p><?php endif; ?>
            <?php foreach ( $attention_courses as $row ) : $course = $row['course']; $course_title = (string) $row['display_title']; ?>
              <article class="ghca-acd__drawer-course ghca-acd__drawer-course--attention" data-course-status="<?php echo esc_attr( $row['status'] ); ?>">
                <span class="ghca-acd__drawer-course-icon" aria-hidden="true"><?php echo self::drawer_icon( 'priority_high' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <div class="ghca-acd__drawer-course-main"><h5 class="ghca-acd__drawer-course-name"><?php echo esc_html( $course_title ); ?></h5><div class="ghca-acd__drawer-course-meta"><?php if ( $row['hours'] > 0 ) : ?><span><?php echo self::drawer_icon( 'schedule' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( sprintf( _n( '%s hr', '%s hrs', $row['hours'], 'ghca-acd' ), rtrim( rtrim( number_format( $row['hours'], 2, '.', '' ), '0' ), '.' ) ) ); ?></span><?php endif; ?><span class="ghca-acd__drawer-course-due"><?php echo esc_html( sprintf( __( 'Due %s', 'ghca-acd' ), ! empty( $course['expiration_label'] ) ? (string) $course['expiration_label'] : $due_date_label ) ); ?></span></div></div>
                <div class="ghca-acd__drawer-course-progress-inline"><span><i class="ghca-acd__drawer-course-bar-fill--<?php echo esc_attr( $row['bar'] ); ?>" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $row['pct'] ) ) ); ?>%"></i></span><strong><?php echo esc_html( $row['pct'] . '%' ); ?></strong></div>
                <div class="ghca-acd__drawer-course-footer"><?php if ( ! empty( $course['url'] ) ) : ?><a class="ghca-acd__drawer-review-link" href="<?php echo esc_url( (string) $course['url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Review %s', 'ghca-acd' ), $course_title ) ); ?>"><?php echo self::drawer_icon( 'chevron_right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a><?php endif; ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        </details>
        <details class="ghca-acd__drawer-disclosure">
          <summary><span class="ghca-acd__drawer-disclosure-label"><span class="ghca-acd__drawer-group-dot ghca-acd__drawer-group-dot--success" aria-hidden="true"></span><?php echo esc_html( sprintf( __( 'Completed (%d)', 'ghca-acd' ), count( $completed_courses ) ) ); ?></span><span class="ghca-acd__drawer-chevron" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="m8 10 4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span></summary>
          <div class="ghca-acd__drawer-course-list">
            <?php if ( empty( $completed_courses ) ) : ?><p class="ghca-acd__drawer-empty"><?php esc_html_e( 'No completed training records.', 'ghca-acd' ); ?></p><?php endif; ?>
            <?php foreach ( $completed_courses as $row ) : $course = $row['course']; $course_title = (string) $row['display_title']; ?>
              <article class="ghca-acd__drawer-course ghca-acd__drawer-course--completed" data-course-status="completed">
                <span class="ghca-acd__drawer-course-icon" aria-hidden="true"><?php echo self::drawer_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <div class="ghca-acd__drawer-course-main"><h5 class="ghca-acd__drawer-course-name"><?php echo esc_html( $course_title ); ?></h5><div class="ghca-acd__drawer-course-meta"><?php if ( $row['hours'] > 0 ) : ?><span><?php echo self::drawer_icon( 'schedule' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( sprintf( _n( '%s hr', '%s hrs', $row['hours'], 'ghca-acd' ), rtrim( rtrim( number_format( $row['hours'], 2, '.', '' ), '0' ), '.' ) ) ); ?></span><?php endif; ?><?php if ( ! empty( $course['completed_ts'] ) ) : ?><span class="ghca-acd__drawer-course-date"><?php echo esc_html( wp_date( get_option( 'date_format', 'M j, Y' ), (int) $course['completed_ts'] ) ); ?></span><?php endif; ?></div></div>
                <div class="ghca-acd__drawer-course-footer"><?php if ( ! empty( $course['certificate_url'] ) ) : ?><button type="button" class="ghca-acd__drawer-review-link ghca-acd__cert-trigger" data-ghca-cert-url="<?php echo esc_attr( (string) $course['certificate_url'] ); ?>" data-ghca-cert-title="<?php echo esc_attr( $course_title ); ?>"><?php echo self::drawer_icon( 'workspace_premium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Certificate', 'ghca-acd' ); ?></button><?php else : ?><span class="ghca-acd__drawer-course-muted"><?php esc_html_e( 'No certificate', 'ghca-acd' ); ?></span><?php endif; ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        </details>
      </section>

      <?php if ( $can_documents ) : ?><section class="ghca-acd__drawer-panel ghca-acd__drawer-panel--documents" role="tabpanel" id="ghca-acd-drawer-panel-documents" aria-labelledby="ghca-acd-drawer-tab-documents" data-ghca-tabpanel="documents" hidden><header class="ghca-acd__drawer-panel-heading"><h4><?php esc_html_e( 'Documents', 'ghca-acd' ); ?></h4><p><?php esc_html_e( 'Manage and review employee compliance records.', 'ghca-acd' ); ?></p></header><?php if ( $documents_html ) : ?><?php echo $documents_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php else : ?><p class="ghca-acd__drawer-empty"><?php esc_html_e( 'No employee documents found.', 'ghca-acd' ); ?></p><?php endif; ?><?php echo $manual_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section><?php endif; ?>
      <?php if ( $can_communications ) : ?><section class="ghca-acd__drawer-panel ghca-acd__drawer-panel--communications" role="tabpanel" id="ghca-acd-drawer-panel-communications" aria-labelledby="ghca-acd-drawer-tab-communications" data-ghca-tabpanel="communications" hidden><?php if ( $history_html ) : ?><?php echo $history_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php else : ?><p class="ghca-acd__drawer-empty"><?php esc_html_e( 'Communication history is not available.', 'ghca-acd' ); ?></p><?php endif; ?></section><?php endif; ?>
      <?php if ( $can_admin ) : ?><section class="ghca-acd__drawer-panel ghca-acd__drawer-panel--administration" role="tabpanel" id="ghca-acd-drawer-panel-administration" aria-labelledby="ghca-acd-drawer-tab-administration" data-ghca-tabpanel="administration" hidden>
        <div class="ghca-acd__drawer-admin-view" data-ghca-admin-view="overview">
        <header class="ghca-acd__drawer-admin-hero"><div><h4><?php esc_html_e( 'Administrative Overview', 'ghca-acd' ); ?></h4><p><?php esc_html_e( 'Core employee records and employment status details. This information is restricted to authorized personnel.', 'ghca-acd' ); ?></p></div><span aria-hidden="true"><?php echo self::drawer_icon( 'shield_person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></header>
        <div class="ghca-acd__drawer-admin-grid">
          <div class="ghca-acd__drawer-admin-main">
            <section class="ghca-acd__drawer-admin-card"><div class="ghca-acd__drawer-admin-card-head"><div><h5><?php esc_html_e( 'Employee Training Record', 'ghca-acd' ); ?></h5><p><?php esc_html_e( 'Foundational dates and orientation metrics.', 'ghca-acd' ); ?></p></div><button type="button" data-ghca-edit-records="<?php echo esc_attr( (string) $user_id ); ?>" data-ghca-edit-records-name="<?php echo esc_attr( $employee_name ); ?>"><?php echo self::drawer_icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Edit Record', 'ghca-acd' ); ?></button></div><dl class="ghca-acd__drawer-admin-facts"><div><dt><?php esc_html_e( 'Registration Date', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $registration_label ); ?></dd></div><div><dt><?php esc_html_e( 'First Service Date', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $first_service_label ); ?></dd></div><div><dt><?php esc_html_e( 'Orientation', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $orientation_label ); ?></dd></div></dl></section>
            <section class="ghca-acd__drawer-admin-card"><div class="ghca-acd__drawer-admin-card-head"><div><h5><?php esc_html_e( 'Compliance Review', 'ghca-acd' ); ?></h5><p class="ghca-acd__drawer-review<?php echo $review['reviewed'] ? '' : ' is-empty'; ?>" data-ghca-review-status><span data-ghca-review-text><?php echo esc_html( $review['line'] ); ?></span></p></div><button type="button" class="ghca-acd__drawer-admin-review" data-ghca-mark-reviewed="<?php echo esc_attr( (string) $user_id ); ?>"><?php echo self::drawer_icon( 'task_alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Mark Reviewed', 'ghca-acd' ); ?></button></div></section>
          </div>
          <div class="ghca-acd__drawer-admin-side">
            <aside class="ghca-acd__drawer-employment-card"><span><?php esc_html_e( 'Employment Status', 'ghca-acd' ); ?></span><strong><?php echo esc_html( $employment_label ); ?></strong><p><?php echo esc_html( $is_suspended ? __( 'Employee is currently inactive and is not authorized for active service.', 'ghca-acd' ) : __( 'Employee is currently in good standing and authorized for active service.', 'ghca-acd' ) ); ?></p><dl><div><dt><?php esc_html_e( 'Role', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $role_label ?: '—' ); ?></dd></div><div><dt><?php esc_html_e( 'Group', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $group_label ?: '—' ); ?></dd></div></dl></aside>
            <section class="ghca-acd__drawer-system-meta" aria-labelledby="ghca-acd-system-meta-title"><h5 id="ghca-acd-system-meta-title"><?php esc_html_e( 'System Meta', 'ghca-acd' ); ?></h5><dl><div><dt><?php esc_html_e( 'Last Review', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( (string) $review['line'] ); ?></dd></div><div><dt><?php esc_html_e( 'Last Reminder', 'ghca-acd' ); ?></dt><dd><?php echo esc_html( $last_reminder_label ?: __( 'None recorded', 'ghca-acd' ) ); ?></dd></div></dl></section>
          </div>
        </div>
        </div>
        <?php
        /*
         * Edit Records & Packet Generator sub-page. Lives inside the
         * Administration panel rather than in an overlay, so the drawer keeps a
         * single navigation context. The form body is fetched on demand by
         * dashboard.js from the existing ghca_acd_get_edit_records_form action.
         */
        ?>
        <div class="ghca-acd__drawer-subpage" data-ghca-admin-view="subpage" hidden>
          <nav class="ghca-acd__subpage-bar" aria-label="<?php esc_attr_e( 'Sub-page navigation', 'ghca-acd' ); ?>">
            <button type="button" class="ghca-acd__subpage-back" data-ghca-subpage-back>
              <?php echo self::drawer_icon( 'chevron_right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <span><?php esc_html_e( 'Back to Administration', 'ghca-acd' ); ?></span>
            </button>
            <ol class="ghca-acd__subpage-crumbs">
              <li><?php esc_html_e( 'Administration', 'ghca-acd' ); ?></li>
              <li aria-current="page"><?php esc_html_e( 'Edit Records &amp; Packet Generator', 'ghca-acd' ); ?></li>
            </ol>
          </nav>

          <div class="ghca-acd__subpage-head">
            <h4><?php esc_html_e( 'Edit Employee Compliance Records &amp; Packet Generator', 'ghca-acd' ); ?></h4>
            <p><?php echo esc_html( sprintf( __( 'Update foundational dates, course records, and compile the archival packet for %s.', 'ghca-acd' ), $employee_name ) ); ?></p>
          </div>

          <div class="ghca-acd__subpage-grid">
            <div class="ghca-acd__subpage-main">
              <div id="ghca-acd-edit-subpage-body" aria-busy="false">
              <p class="ghca-acd__drawer-empty"><?php esc_html_e( 'Loading records…', 'ghca-acd' ); ?></p>
              </div>
              <section class="ghca-acd__agency-training" data-ghca-agency="<?php echo esc_attr( (string) $user_id ); ?>" aria-label="Agency-delivered training">
                <p>Confirm in-person training for the Annual packet period selected in Packet Generator. Save separately from course records. Orientation is unchanged.</p>
                <button type="button" class="ghca-acd__edit-btn" data-ghca-agency-reload>Load / refresh saved confirmations</button>
                <p data-ghca-agency-status role="status" aria-live="polite"></p>
                <div data-ghca-agency-body></div>
              </section>
            </div>

            <aside class="ghca-acd__subpage-side">
              <section class="ghca-acd__packet-panel" aria-labelledby="ghca-acd-packet-title">
                <div class="ghca-acd__packet-panel-head">
                  <div>
                    <span class="ghca-acd__packet-eyebrow"><?php esc_html_e( 'Packet Generator', 'ghca-acd' ); ?></span>
                    <h5 id="ghca-acd-packet-title"><?php esc_html_e( 'Compliance Packet', 'ghca-acd' ); ?></h5>
                    <p><?php esc_html_e( 'Compiles the compliance summary and stored certificates into a single archival PDF.', 'ghca-acd' ); ?></p>
                  </div>
                </div>

                <fieldset class="ghca-acd__audit-period" data-ghca-audit-period>
                  <legend><?php esc_html_e( 'Annual packet period', 'ghca-acd' ); ?></legend>
                  <label><?php esc_html_e( 'Period to report', 'ghca-acd' ); ?>
                    <select data-ghca-audit-mode>
                      <option value="cycle"><?php esc_html_e( 'Current annual training cycle', 'ghca-acd' ); ?></option>
                      <option value="custom"><?php esc_html_e( 'Choose audit reporting dates', 'ghca-acd' ); ?></option>
                    </select>
                  </label>
                  <div data-ghca-audit-dates hidden>
                    <label><?php esc_html_e( 'Audit start date', 'ghca-acd' ); ?><input type="date" data-ghca-audit-start required disabled></label>
                    <label><?php esc_html_e( 'Audit end date (inclusive)', 'ghca-acd' ); ?><input type="date" data-ghca-audit-end required disabled></label>
                    <p><?php esc_html_e( 'Reports evidence within these dates. Does not change the saved annual cycle or assess annual compliance. Applies to the Annual button only.', 'ghca-acd' ); ?></p>
                  </div>
                </fieldset>
                <div class="ghca-acd__packet-triggers">
                  <button type="button" class="ghca-acd__packet-run" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="orientation"><?php esc_html_e( 'Orientation', 'ghca-acd' ); ?></button>
                  <button type="button" class="ghca-acd__packet-run ghca-acd__packet-run--primary" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="annual"><?php esc_html_e( 'Annual', 'ghca-acd' ); ?></button>
                  <?php if ( '' !== $oltl_html ) : ?>
                    <button type="button" class="ghca-acd__packet-run" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="oltl_training"><?php esc_html_e( 'OLTL Training', 'ghca-acd' ); ?></button>
                  <?php endif; ?>
                </div>

                <?php /* Mirrors the live job. Idle until a packet run starts. */ ?>
                <div class="ghca-acd__packet-live" data-ghca-packet-mirror hidden>
                  <div class="ghca-acd__packet-metric">
                    <span class="ghca-acd__packet-metric-label"><?php esc_html_e( 'Compilation status', 'ghca-acd' ); ?></span>
                    <span class="ghca-acd__packet-metric-value"><strong data-ghca-packet-pct>0</strong><span>%</span></span>
                  </div>
                  <div class="ghca-acd__packet-track" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Packet compilation progress', 'ghca-acd' ); ?>" data-ghca-packet-track>
                    <span class="ghca-acd__packet-bar" data-ghca-packet-bar></span>
                  </div>
                  <p class="ghca-acd__packet-status" data-ghca-packet-label aria-live="polite"></p>

                  <ol class="ghca-acd__packet-steps">
                    <li class="ghca-acd__packet-mirror-step" data-ghca-packet-step="summary">
                      <span class="ghca-acd__packet-step-dot" aria-hidden="true"></span>
                      <div>
                        <strong><?php esc_html_e( 'Compliance summary', 'ghca-acd' ); ?></strong>
                        <span><?php esc_html_e( 'Builds the training and competency summary page.', 'ghca-acd' ); ?></span>
                      </div>
                    </li>
                    <li class="ghca-acd__packet-mirror-step" data-ghca-packet-step="certificates">
                      <span class="ghca-acd__packet-step-dot" aria-hidden="true"></span>
                      <div>
                        <strong><?php esc_html_e( 'Certificate retrieval', 'ghca-acd' ); ?></strong>
                        <span><?php esc_html_e( 'Collects stored certificates and approved external evidence.', 'ghca-acd' ); ?></span>
                      </div>
                    </li>
                    <li class="ghca-acd__packet-mirror-step" data-ghca-packet-step="merge">
                      <span class="ghca-acd__packet-step-dot" aria-hidden="true"></span>
                      <div>
                        <strong><?php esc_html_e( 'Merge &amp; verify', 'ghca-acd' ); ?></strong>
                        <span><?php esc_html_e( 'Merges the PDF and re-checks evidence integrity before download.', 'ghca-acd' ); ?></span>
                      </div>
                    </li>
                  </ol>
                </div>

                <?php /* Accurate to GHCA_ACD_Audit_Pdf_Jobs: files live in a private
                         dir and gc() removes anything older than TTL (1 hour). */ ?>
                <p class="ghca-acd__packet-note"><?php esc_html_e( 'Generated packets are held in private storage and cleared automatically about an hour after they are built.', 'ghca-acd' ); ?></p>
              </section>
            </aside>
          </div>
        </div>
      </section><?php endif; ?>
    </div>

    <footer class="ghca-acd__drawer-actions" aria-label="<?php esc_attr_e( 'Employee actions', 'ghca-acd' ); ?>">
      <div class="ghca-acd__drawer-actions-row">
        <details class="ghca-acd__drawer-more">
          <summary class="ghca-acd__drawer-action ghca-acd__drawer-action--neutral"><?php echo self::drawer_icon( 'more_horiz' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'More', 'ghca-acd' ); ?></summary>
          <div class="ghca-acd__drawer-more-menu">
            <a href="<?php echo esc_url( $report_url ); ?>"><?php esc_html_e( 'Compliance Report', 'ghca-acd' ); ?></a>
            <button type="button" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="orientation"><?php esc_html_e( 'Orientation Packet', 'ghca-acd' ); ?></button>
            <button type="button" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="annual"><?php esc_html_e( 'Annual Packet', 'ghca-acd' ); ?></button>
            <?php if ( '' !== $oltl_html ) : ?><button type="button" data-ghca-pdf-packet="<?php echo esc_attr( (string) $user_id ); ?>" data-tracker="oltl_training"><?php esc_html_e( 'OLTL Training Packet', 'ghca-acd' ); ?></button><?php endif; ?>
          </div>
        </details>
        <?php if ( GHCA_ACD_Roles::user_can_send_reminders() ) : ?><button type="button" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary" data-ghca-reminder="<?php echo esc_attr( (string) $user_id ); ?>" data-ghca-reminder-name="<?php echo esc_attr( $employee_name ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 8-16 8 3-8-3-8Zm3 8h13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg><?php esc_html_e( 'Send Reminder', 'ghca-acd' ); ?></button><?php endif; ?>
      </div>
    </footer>
    <?php
    $html = (string) ob_get_clean();
    wp_send_json_success( array( 'html' => $html, 'name' => $employee_name ) );
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

    if ( ! GHCA_ACD_Roles::user_can_edit_records() ) {
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

  /** Progress modal for the async packet builder (driven by initPdfPacket in dashboard.js). */
  public static function render_pdf_progress_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }
    ?>
    <div class="ghca-acd__pdf-modal ghca-acd__overlay ghca-acd__overlay--packet" id="ghca-acd-pdf-modal" hidden aria-hidden="true">
      <div class="ghca-acd__pdf-modal-backdrop"></div>
      <div class="ghca-acd__pdf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-pdf-modal-title">
        <div class="ghca-acd__pdf-modal-header">
          <h2 id="ghca-acd-pdf-modal-title"><?php esc_html_e( 'Building Compliance Packet', 'ghca-acd' ); ?></h2>
          <p class="ghca-acd__modal-subtitle"><?php esc_html_e( 'Protected employee compliance record', 'ghca-acd' ); ?></p>
        </div>
        <div class="ghca-acd__pdf-modal-body">
          <div class="ghca-acd__packet-symbol" aria-hidden="true">&#8595;</div>
          <div class="ghca-acd__packet-copy">
            <h3><?php esc_html_e( 'Preparing compliance evidence', 'ghca-acd' ); ?></h3>
            <p class="ghca-acd__pdf-modal-status" data-ghca-pdf-label aria-live="polite"><?php esc_html_e( 'Keep this window open while the protected packet is assembled.', 'ghca-acd' ); ?></p>
          </div>
          <div class="ghca-acd__pdf-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-ghca-pdf-track>
            <div class="ghca-acd__pdf-progress-fill" data-ghca-pdf-bar></div>
          </div>
          <div class="ghca-acd__packet-steps" aria-hidden="true">
            <div class="ghca-acd__packet-step is-active" data-ghca-pdf-step="summary"><?php esc_html_e( 'Compliance summary created', 'ghca-acd' ); ?></div>
            <div class="ghca-acd__packet-step" data-ghca-pdf-step="certificates"><?php esc_html_e( 'Retrieving certificates', 'ghca-acd' ); ?></div>
            <div class="ghca-acd__packet-step" data-ghca-pdf-step="merge"><?php esc_html_e( 'Merging and validating packet', 'ghca-acd' ); ?></div>
          </div>
        </div>
        <div class="ghca-acd__pdf-modal-footer">
          <button type="button" class="ghca-acd__cert-btn ghca-acd__cert-btn--close" data-ghca-pdf-cancel><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
        </div>
      </div>
    </div>
    <?php
  }

  /**
   * Display-only readability pass for stored course titles.
   *
   * A title that is entirely uppercase is shown in title case; mixed-case titles and the stored
   * source records are never altered.
   */
  /**
   * Inline icon set for the employee drawer.
   *
   * The Stitch handoff specifies Material Symbols Outlined. Rather than load a
   * Google Fonts icon font into a healthcare dashboard (an external request on
   * every dashboard view), these are equivalent outlined glyphs drawn on the
   * same 24px grid with a uniform 1.8 stroke, matching the handoff's icon
   * selection, size and weight. Sizes are set in CSS, not here.
   *
   * @param string $name Icon key matching the handoff's Material Symbols name.
   */
  private static function drawer_icon( string $name ): string {
    $paths = array(
      // header + meta
      'medical_services' => '<path d="M9 6V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6M4 8h16v10.5A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5Zm8 3v5m-2.5-2.5h5"/>',
      'verified'         => '<path d="m12 3 2.1 2.3 3.1-.3.7 3 2.7 1.6-1.3 2.9 1.3 2.9-2.7 1.6-.7 3-3.1-.3L12 21l-2.1-2.3-3.1.3-.7-3L3.4 14.4l1.3-2.9-1.3-2.9L6.1 7l.7-3 3.1.3Z"/><path d="m9.4 12 1.9 1.9 3.6-4"/>',
      // summary stats
      'task_alt'         => '<path d="M20.5 11.2V12a8.5 8.5 0 1 1-5-7.8"/><path d="M9 11.5 12 14.5 21 5.5"/>',
      'workspace_premium'=> '<path d="M12 15.5a6.25 6.25 0 1 0 0-12.5 6.25 6.25 0 0 0 0 12.5Z"/><path d="m8.3 14.1-1.1 7.4L12 18.9l4.8 2.6-1.1-7.4"/>',
      'event'            => '<path d="M4.5 5.5h15A1.5 1.5 0 0 1 21 7v12.5A1.5 1.5 0 0 1 19.5 21h-15A1.5 1.5 0 0 1 3 19.5V7a1.5 1.5 0 0 1 1.5-1.5Z"/><path d="M16 3.5v4M8 3.5v4M3 10.5h18"/>',
      // states
      'warning'          => '<path d="M12.9 4.2 21.4 18a1 1 0 0 1-.9 1.5H3.5a1 1 0 0 1-.9-1.5l8.5-13.8a1 1 0 0 1 1.8 0Z"/><path d="M12 9.5v4M12 17h.01"/>',
      'priority_high'    => '<path d="M12 5v9"/><path d="M12 18.5h.01"/>',
      'check'            => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
      'schedule'         => '<path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path d="M12 7v5.2l3.4 2"/>',
      'notifications'    => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.5 7.5-2.5 7.5h17S18 14.5 18 8.5Z"/><path d="M13.7 19.5a2 2 0 0 1-3.4 0"/>',
      // actions
      'chevron_right'    => '<path d="m9.5 5.5 6.5 6.5-6.5 6.5"/>',
      'more_horiz'       => '<path d="M5.5 12h.01M12 12h.01M18.5 12h.01"/>',
      'edit'             => '<path d="M17 3.2a2.4 2.4 0 0 1 3.4 3.4L7.6 19.4 3 21l1.6-4.6Z"/><path d="m15 5.5 3.5 3.5"/>',
      'shield_person'    => '<path d="M20 12.5c0 5.2-3.7 7.8-7.7 9.2a1 1 0 0 1-.6 0C7.7 20.3 4 17.7 4 12.5V6.2a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.1 1.1 0 0 1 1.5 0C14.5 4 17 5.2 19 5.2a1 1 0 0 1 1 1Z"/><path d="M12 11.8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="M8.6 17.2a3.6 3.6 0 0 1 6.8 0"/>',
    );
    if ( ! isset( $paths[ $name ] ) ) {
      return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
  }

  private static function display_course_title( string $title ): string {
    $title = trim( $title );
    if ( '' !== $title && preg_match( '/\p{Lu}/u', $title ) && ! preg_match( '/\p{Ll}/u', $title ) ) {
      return function_exists( 'mb_convert_case' ) ? mb_convert_case( $title, MB_CASE_TITLE, 'UTF-8' ) : ucwords( strtolower( $title ) );
    }
    return $title;
  }

  /** Builds the editable form for one user (registration date + per-course rows). */
  public static function ajax_get_edit_records_form(): void {
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

    $employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );

    // user_registered is stored as GMT; convert to a site-local value for the picker.
    $registered_local = '';
    if ( ! empty( $user->user_registered ) ) {
      $reg_ts           = (int) get_date_from_gmt( $user->user_registered, 'U' );
      $registered_local = wp_date( 'Y-m-d\TH:i', $reg_ts );
    }

    ob_start();
    ?>
    <?php
    /*
     * Read-only context for section 1. These values are DERIVED (WP role, group
     * assignment, suspension state) and are not writable through this endpoint,
     * so they render as static fields rather than inputs. Shipping editable
     * controls for them would silently discard an administrator's input.
     */
    $edit_user       = get_userdata( $user_id );
    $edit_role_label = '';
    if ( $edit_user && ! empty( $edit_user->roles ) && function_exists( 'wp_roles' ) ) {
      $edit_role_keys  = (array) $edit_user->roles;
      $edit_role_key   = (string) reset( $edit_role_keys );
      $edit_role_map   = wp_roles()->get_names();
      $edit_role_label = (string) ( $edit_role_map[ $edit_role_key ] ?? $edit_role_key );
    }
    $edit_group      = (string) ( $employee['group'] ?? '' );
    $edit_suspended  = method_exists( 'GHCA_ACD_Data_Provider', 'is_user_suspended' ) && GHCA_ACD_Data_Provider::is_user_suspended( $user_id );
    $edit_status     = $edit_suspended ? __( 'Inactive', 'ghca-acd' ) : __( 'Active', 'ghca-acd' );
    $edit_orient     = (string) ( $employee['orientation_label'] ?? '' );

    /*
     * First-service and worked-alone are the same user meta the Audit Data tab
     * edits, surfaced here so all compliance milestones live in one place.
     * Stored as MM/DD/YYYY; converted for the date inputs and back on save.
     */
    $edit_first_raw  = (string) get_user_meta( $user_id, 'ghca_first_service_date', true );
    $edit_alone_raw  = (string) get_user_meta( $user_id, 'ghca_worked_alone_date', true );
    $to_input        = static function ( string $mdy ): string {
      if ( '' === $mdy ) {
        return '';
      }
      $d = DateTime::createFromFormat( 'm/d/Y', $mdy );
      return $d ? $d->format( 'Y-m-d' ) : '';
    };
    $edit_first_val  = $to_input( $edit_first_raw );
    $edit_alone_val  = $to_input( $edit_alone_raw );
    $employment_record = GHCA_ACD_Employment_Record::get( $user_id );
    ?>
    <form class="ghca-acd__edit-form ghca-acd__v2-edit-form" data-ghca-edit-form data-user-id="<?php echo esc_attr( (string) $user_id ); ?>">

      <section class="ghca-acd__edit-section ghca-acd__edit-card">
        <div class="ghca-acd__edit-card-head">
          <span class="ghca-acd__edit-card-title"><?php esc_html_e( '1. Employment &amp; Role', 'ghca-acd' ); ?></span>
        </div>
        <div class="ghca-acd__edit-grid ghca-acd__edit-grid--3">
          <div class="ghca-acd__edit-field">
            <label class="ghca-acd__edit-label" for="ghca-edit-employment-type"><?php esc_html_e( 'Employment Type', 'ghca-acd' ); ?></label>
            <select class="ghca-acd__edit-input" id="ghca-edit-employment-type" name="employment_type">
              <option value=""><?php esc_html_e( 'Not set', 'ghca-acd' ); ?></option>
              <?php foreach ( GHCA_ACD_Data_Provider::employment_types() as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( GHCA_ACD_Data_Provider::employment_type( $user_id ), $value ); ?>><?php echo esc_html( $label ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Primary Role', 'ghca-acd' ); ?></span>
            <p class="ghca-acd__edit-static"><?php echo esc_html( $edit_role_label ?: '—' ); ?></p>
          </div>
          <div class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Group', 'ghca-acd' ); ?></span>
            <p class="ghca-acd__edit-static"><?php echo esc_html( $edit_group ?: '—' ); ?></p>
          </div>
          <div class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Employment Status', 'ghca-acd' ); ?></span>
            <p class="ghca-acd__edit-static"><span class="ghca-acd__edit-dot ghca-acd__edit-dot--<?php echo esc_attr( $edit_suspended ? 'inactive' : 'active' ); ?>" aria-hidden="true"></span><?php echo esc_html( $edit_status ); ?></p>
          </div>
        </div>
        <p class="ghca-acd__edit-hint"><?php esc_html_e( 'Role and group come from the user account and group assignment. Employment status follows the account’s active state.', 'ghca-acd' ); ?></p>
      </section>

      <section class="ghca-acd__edit-section ghca-acd__edit-card">
        <div class="ghca-acd__edit-card-head">
          <span class="ghca-acd__edit-card-title"><?php esc_html_e( '2. Compliance Milestones &amp; Dates', 'ghca-acd' ); ?></span>
        </div>
        <div class="ghca-acd__edit-grid ghca-acd__edit-grid--2">
          <label class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Account Registration Date', 'ghca-acd' ); ?></span>
            <input type="datetime-local" name="registration_date" value="<?php echo esc_attr( $registered_local ); ?>" class="ghca-acd__edit-input" />
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Account creation only; not the verified employment start date.', 'ghca-acd' ); ?></span>
          </label>
          <label class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Verified Employment Start Date', 'ghca-acd' ); ?></span>
            <input type="date" name="employment_date" value="<?php echo esc_attr( $employment_record['date'] ?? '' ); ?>" class="ghca-acd__edit-input" />
          </label>
          <label class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Employment-date source reference', 'ghca-acd' ); ?></span>
            <input type="text" name="employment_source" maxlength="500" value="<?php echo esc_attr( $employment_record['source'] ?? '' ); ?>" class="ghca-acd__edit-input" />
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Reference the reviewed HR record; do not paste private document contents.', 'ghca-acd' ); ?></span>
          </label>
          <label class="ghca-acd__edit-field">
            <span><input type="checkbox" name="employment_verified" value="1" /> <?php esc_html_e( 'I checked this employment date against its source.', 'ghca-acd' ); ?></span>
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Required for a new or changed date/source. The verifier and time are recorded automatically. Blank fields do not erase a verified date.', 'ghca-acd' ); ?></span>
          </label>
          <label class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'First Service Date', 'ghca-acd' ); ?></span>
            <input type="date" name="first_service_date" value="<?php echo esc_attr( $edit_first_val ); ?>" class="ghca-acd__edit-input" />
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Also editable from the Audit Data tab; used in the audit packet.', 'ghca-acd' ); ?></span>
          </label>
          <label class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Worked Alone Authorized', 'ghca-acd' ); ?></span>
            <input type="date" name="worked_alone_date" value="<?php echo esc_attr( $edit_alone_val ); ?>" class="ghca-acd__edit-input" />
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Must fall on or after orientation completion, or the audit records a violation.', 'ghca-acd' ); ?></span>
          </label>
          <div class="ghca-acd__edit-field">
            <span class="ghca-acd__edit-label"><?php esc_html_e( 'Orientation Completion', 'ghca-acd' ); ?></span>
            <p class="ghca-acd__edit-static"><?php echo esc_html( $edit_orient ?: '—' ); ?></p>
            <span class="ghca-acd__edit-hint"><?php esc_html_e( 'Derived from the orientation course records below.', 'ghca-acd' ); ?></span>
          </div>
        </div>
      </section>

      <section class="ghca-acd__edit-section ghca-acd__edit-card">
        <div class="ghca-acd__edit-card-head">
          <span class="ghca-acd__edit-card-title"><?php esc_html_e( '3. Course Records', 'ghca-acd' ); ?></span>
          <span class="ghca-acd__edit-card-tag ghca-acd__edit-card-tag--sync"><?php esc_html_e( 'Syncs to LearnDash', 'ghca-acd' ); ?></span>
        </div>
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
              <span class="ghca-acd__edit-course-name"><?php echo esc_html( self::display_course_title( (string) $course['title'] ) ); ?></span>
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
            <p class="ghca-acd__edit-hint"><?php esc_html_e( 'Uncheck and save to mark incomplete. If all lessons are complete, the last lesson is reopened. Previous completion data is retained in correction history; external training is unchanged.', 'ghca-acd' ); ?></p>
          </div>
        <?php endforeach; endif; ?>
      </section>

      <?php
      /*
       * Audit notice. Wording is limited to what the plugin actually does:
       * record edits are written to the audit log under the acting account, and
       * packet evidence is integrity-checked with SHA-256 by the evidence store
       * and manifest verification. No claim is made about an immutable ledger.
       */
      $edit_actor = wp_get_current_user();
      ?>
      <aside class="ghca-acd__edit-ledger">
        <span class="ghca-acd__edit-ledger-icon" aria-hidden="true"><?php echo self::drawer_icon( 'shield_person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <div class="ghca-acd__edit-ledger-body">
          <div class="ghca-acd__edit-ledger-head">
            <span class="ghca-acd__edit-ledger-title"><?php esc_html_e( 'Audit logging', 'ghca-acd' ); ?></span>
            <span class="ghca-acd__edit-ledger-tag"><?php esc_html_e( 'SHA-256 evidence integrity', 'ghca-acd' ); ?></span>
          </div>
          <p class="ghca-acd__edit-ledger-copy">
            <?php
            printf(
              /* translators: %s: the acting administrator's username. */
              esc_html__( 'Record changes are written to the audit log against your account (%s). Packet evidence is verified with SHA-256 when it is stored and again when it is compiled.', 'ghca-acd' ),
              '<code>' . esc_html( $edit_actor && $edit_actor->user_login ? $edit_actor->user_login : __( 'unknown', 'ghca-acd' ) ) . '</code>'
            );
            ?>
          </p>
        </div>
      </aside>

      <div class="ghca-acd__edit-form-footer ghca-acd__v2-modal-footer">
        <button type="button" class="ghca-acd__edit-btn ghca-acd__edit-btn--ghost" data-ghca-edit-close><?php esc_html_e( 'Cancel &amp; Return', 'ghca-acd' ); ?></button>
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

    $employment_type = self::requested_employment_type();
    if ( is_wp_error( $employment_type ) ) {
      wp_send_json_error( array( 'message' => $employment_type->get_error_message() ) );
    }
    $employment_saved = GHCA_ACD_Employment_Record::save_from_input( $user_id, wp_unslash( $_POST ) );
    if ( is_wp_error( $employment_saved ) ) {
      wp_send_json_error( array( 'message' => $employment_saved->get_error_message() ) );
    }
    if ( $employment_saved ) {
      GHCA_ACD_Data_Provider::bust_cache();
      $changes[] = __( 'Verified employment date', 'ghca-acd' );
    }
    $type_saved = self::save_employment_type( $user_id, $employment_type );
    if ( is_wp_error( $type_saved ) ) {
      wp_send_json_error( array( 'message' => ( $employment_saved ? __( 'Employment date saved. ', 'ghca-acd' ) : '' ) . $type_saved->get_error_message() ) );
    }
    if ( $type_saved ) {
      $changes[] = __( 'Employment Type', 'ghca-acd' );
    }

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
    /*
     * First-service and worked-alone are the same user meta the Audit Data tab
     * writes. They arrive here as YYYY-MM-DD from the date inputs and are stored
     * in the MM/DD/YYYY shape the audit calculator and packet already expect.
     * ghca_audit_exclude is deliberately NOT written: only the Audit Data tab
     * owns that flag, and rewriting it here would silently clear it.
     */
    foreach ( array( 'first_service_date' => 'ghca_first_service_date', 'worked_alone_date' => 'ghca_worked_alone_date' ) as $field => $meta_key ) {
      if ( ! isset( $_POST[ $field ] ) ) {
        continue;
      }
      $raw = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
      if ( '' === $raw ) {
        update_user_meta( $user_id, $meta_key, '' );
        continue;
      }
      $parsed = DateTime::createFromFormat( 'Y-m-d', $raw );
      if ( $parsed && $parsed->format( 'Y-m-d' ) === $raw ) {
        update_user_meta( $user_id, $meta_key, $parsed->format( 'm/d/Y' ) );
      }
    }

    $course_input = ( isset( $_POST['course'] ) && is_array( $_POST['course'] ) ) ? wp_unslash( $_POST['course'] ) : array();
    $enrolled     = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );

    foreach ( $course_input as $cid => $row ) {
      $cid = (int) $cid;
      // Ignore unknown or forged course IDs the user is not actually enrolled in.
      if ( $cid <= 0 || ! in_array( $cid, $enrolled, true ) || ! is_array( $row ) ) {
        continue;
      }

      if ( array_key_exists( 'mark_complete', $row ) && ! in_array( $row['mark_complete'], array( '0', '1' ), true ) ) {
        $errors[] = get_the_title( $cid ) . ': ' . __( 'Invalid completion selection. Reload and try again.', 'ghca-acd' );
        continue;
      }
      $completion_raw = isset( $row['completed'] ) ? sanitize_text_field( (string) $row['completed'] ) : '';
      // Explicit zero only: omitted/truncated checkbox fields never revoke completion.
      if ( isset( $row['mark_complete'] ) && '0' === $row['mark_complete'] ) {
        $result = self::apply_course_incomplete( $user_id, $cid );
        if ( is_wp_error( $result ) ) {
          $errors[] = get_the_title( $cid ) . ': ' . $result->get_error_message();
        } elseif ( $result ) {
          $changes[] = get_the_title( $cid ) . ' (incomplete)';
        }
        continue;
      }
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
    // Back-dated completions intentionally skip LearnDash hooks (see
    // apply_course_completion), so the employee dashboard must be busted here.
    if ( class_exists( 'GHCA_Employee_Compliance_Dashboard' ) && method_exists( 'GHCA_Employee_Compliance_Dashboard', 'bust_cache' ) ) {
      GHCA_Employee_Compliance_Dashboard::bust_cache( $user_id );
    }

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

  /** Preserve a correction snapshot before changing this course's active completion. */
  private static function apply_course_incomplete( int $user_id, int $course_id ) {
    foreach ( array( 'learndash_user_set_course_progress', 'learndash_get_user_activity', 'learndash_update_user_activity', 'learndash_course_get_steps_by_type' ) as $function ) {
      if ( ! function_exists( $function ) ) {
        return new WP_Error( 'ghca_incomplete_unavailable', __( 'LearnDash completion editing is unavailable. No completion was changed.', 'ghca-acd' ) );
      }
    }
    $all_progress = get_user_meta( $user_id, '_sfwd-course_progress', true );
    $progress = is_array( $all_progress[ $course_id ] ?? null ) ? $all_progress[ $course_id ] : array();
    $completed = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
    $args = array( 'user_id' => $user_id, 'course_id' => $course_id, 'post_id' => $course_id, 'activity_type' => 'course' );
    $activity = learndash_get_user_activity( $args );
    if ( ! $completed && empty( $activity->activity_status ) && 'completed' !== ( $progress['status'] ?? '' ) ) { return false; }

    $lessons = array_map( 'intval', (array) learndash_course_get_steps_by_type( $course_id, 'sfwd-lessons' ) );
    $reopen = 0;
    if ( $lessons ) {
      $all_lessons_complete = true;
      foreach ( $lessons as $lesson ) {
        if ( empty( $progress['lessons'][ $lesson ] ) ) { $all_lessons_complete = false; break; }
      }
      if ( $all_lessons_complete ) { $reopen = (int) end( $lessons ); }
    } elseif ( function_exists( 'learndash_has_global_quizzes' ) && learndash_has_global_quizzes( $course_id ) && ( ! function_exists( 'learndash_is_all_global_quizzes_complete' ) || learndash_is_all_global_quizzes_complete( $user_id, $course_id ) ) ) {
      return new WP_Error( 'ghca_quiz_review', __( 'This quiz-only course would automatically complete again. Review its quiz completion in LearnDash first; quiz attempts were not deleted.', 'ghca-acd' ) );
    }
    $lesson_args = array_merge( $args, array( 'post_id' => $reopen, 'activity_type' => 'lesson' ) );
    $history = array( 'course_id' => $course_id, 'actor_id' => get_current_user_id(), 'at' => time(), 'action' => 'mark_incomplete', 'completion_date' => $completed, 'progress' => $progress, 'activity' => $activity ? (array) $activity : array(), 'reopened_lesson' => $reopen, 'lesson_activity' => $reopen ? (array) learndash_get_user_activity( $lesson_args ) : array() );
    if ( ! add_user_meta( $user_id, 'ghca_acd_course_correction_history', $history ) ) {
      return new WP_Error( 'ghca_history_failed', __( 'Could not preserve correction history. No completion was changed.', 'ghca-acd' ) );
    }
    if ( $reopen ) {
      $progress['lessons'][ $reopen ] = 0;
      learndash_update_user_activity( array_merge( $lesson_args, array( 'activity_status' => false, 'activity_completed' => 0, 'activity_updated' => time(), 'activity_action' => 'update' ) ) );
      delete_transient( "learndash_step_completed_{$reopen}_{$course_id}_{$user_id}" );
    }
    $progress['completed'] = max( 0, (int) ( $progress['completed'] ?? 0 ) - ( $reopen ? 1 : 0 ) );
    $progress['status'] = 'in_progress';
    delete_user_meta( $user_id, 'course_completed_' . $course_id );
    learndash_user_set_course_progress( $user_id, $course_id, $progress );
    learndash_update_user_activity( array_merge( $args, array( 'activity_status' => false, 'activity_completed' => 0, 'activity_updated' => time(), 'activity_action' => 'update' ) ) );
    delete_transient( 'learndash_course_completed_' . $course_id . '_' . $user_id );
    $saved = get_user_meta( $user_id, '_sfwd-course_progress', true );
    $saved_activity = learndash_get_user_activity( $args );
    $saved_lesson = $reopen ? learndash_get_user_activity( $lesson_args ) : null;
    if ( get_user_meta( $user_id, 'course_completed_' . $course_id, true ) || 'in_progress' !== ( $saved[ $course_id ]['status'] ?? '' ) || ! $saved_activity || ! empty( $saved_activity->activity_status ) || ! empty( $saved_activity->activity_completed ) || ( $reopen && ( ! empty( $saved[ $course_id ]['lessons'][ $reopen ] ) || ! $saved_lesson || ! empty( $saved_lesson->activity_status ) ) ) ) {
      return new WP_Error( 'ghca_incomplete_failed', __( 'Completion correction could not be fully saved. Previous values are retained in correction history; reload and review before generating a packet.', 'ghca-acd' ) );
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
      case 'inactive_employees':
        if ( ! GHCA_ACD_Roles::user_can_manage_users() ) {
          wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'ghca-acd' ) ), 403 );
        }
        $filters = GHCA_ACD_Data_Provider::get_employee_filters( 'inactive' );
        $html    = GHCA_ACD_Shortcodes::get_employee_table_html( $filters, 'inactive' );
        break;
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

  public static function ajax_save_employee(): void {
    check_ajax_referer( 'ghca_save_employee', 'ghca_nonce' );

    if ( ! GHCA_ACD_Roles::user_can_manage_users() ) {
      wp_send_json_error( __( 'Permission denied.', 'ghca-acd' ) );
    }

    $employment_type = self::requested_employment_type();
    if ( is_wp_error( $employment_type ) ) {
      wp_send_json_error( $employment_type->get_error_message() );
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
    if ( empty( $groups ) && ! GHCA_ACD_Roles::user_has_full_dashboard_access() ) {
      wp_send_json_error( __( 'Select at least one group for this employee.', 'ghca-acd' ) );
    }

    $userdata = array(
      'user_email'   => $email,
      'first_name'   => $first_name,
      'last_name'    => $last_name,
      'display_name' => trim( $first_name . ' ' . $last_name ),
    );

    $manageable_roles = GHCA_ACD_Roles::get_manageable_employee_roles();
    if ( ! array_key_exists( $role, $manageable_roles ) ) {
      wp_send_json_error( __( 'You do not have permission to assign this role.', 'ghca-acd' ) );
    }
    $userdata['role'] = $role;

    if ( $user_id > 0 ) {
      $userdata['ID'] = $user_id;
      $existing_user = get_userdata( $user_id );
      if ( ! $existing_user ) {
        wp_send_json_error( __( 'Employee account not found.', 'ghca-acd' ) );
      }

      if ( ! GHCA_ACD_Roles::role_update_is_needed( $role, (array) $existing_user->roles ) ) {
        unset( $userdata['role'] );
      }

      if ( ! current_user_can( 'manage_options' ) ) {
        if ( ! GHCA_ACD_Roles::delegated_target_is_allowed( get_current_user_id(), $user_id, (array) $existing_user->roles, GHCA_ACD_Roles::delegated_target_is_in_scope( $user_id ), (array) $existing_user->allcaps ) ) {
          wp_send_json_error( __( 'You do not have permission to modify this employee.', 'ghca-acd' ) );
        }
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

    $type_saved = self::save_employment_type( (int) $saved_user_id, $employment_type );
    if ( is_wp_error( $type_saved ) ) {
      wp_send_json_error( __( 'Account saved, but Employment Type could not be saved. Reload Manage Users before retrying.', 'ghca-acd' ) );
    }

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
    if ( class_exists( 'GHCA_Employee_Compliance_Dashboard' ) && method_exists( 'GHCA_Employee_Compliance_Dashboard', 'bust_cache' ) ) {
      GHCA_Employee_Compliance_Dashboard::bust_cache( (int) $saved_user_id );
    }
    wp_send_json_success( __( 'Employee saved successfully.', 'ghca-acd' ) );
  }

  /** Missing fields preserve existing data; reject malformed values before any writes. */
  private static function requested_employment_type() {
    if ( ! array_key_exists( 'employment_type', $_POST ) ) {
      return null;
    }
    $type = $_POST['employment_type'];
    if ( ! is_string( $type ) || ( '' !== $type && ! isset( GHCA_ACD_Data_Provider::employment_types()[ $type ] ) ) ) {
      return new WP_Error( 'invalid_employment_type', __( 'Invalid Employment Type.', 'ghca-acd' ) );
    }
    return $type;
  }

  /** Called only after the endpoint's nonce, capability and target checks. */
  private static function save_employment_type( int $user_id, ?string $type ) {
    if ( null === $type || $type === GHCA_ACD_Data_Provider::employment_type( $user_id ) ) {
      return false;
    }
    // Keep the value and last-change attribution together; this is not an archive history.
    $saved = update_user_meta( $user_id, 'ghca_acd_employment_type', array(
      'type' => $type,
      'updated_by' => get_current_user_id(),
      'updated_at' => time(),
    ) );
    return $saved ? true : new WP_Error( 'employment_type_save_failed', __( 'Employment Type could not be saved. Please retry.', 'ghca-acd' ) );
  }
}
