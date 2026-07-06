<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_User_Report {
  public static function init(): void {
    // Shortcode registered via GHCA_Admin_Compliance_Dashboard::register_shortcodes().
  }

  public static function render( $atts = array() ): string {
    if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return self::wrap_message( __( 'You do not have permission to view compliance reports.', 'ghca-acd' ), 'denied' );
    }

    $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
    if ( $user_id <= 0 ) {
      return self::wrap_message(
        __( 'Select an employee from the compliance dashboard to view their report.', 'ghca-acd' ),
        'empty',
        true
      );
    }

    if ( ! self::can_view_user( $user_id ) ) {
      return self::wrap_message( __( 'You do not have permission to view this employee report.', 'ghca-acd' ), 'denied' );
    }

    $employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );
    if ( empty( $employee['user_id'] ) ) {
      return self::wrap_message( __( 'Employee not found or has no compliance data.', 'ghca-acd' ), 'empty' );
    }

    $new_hire = $employee['new_hire'] ?? array();
    $stats    = array(
      array(
        'label' => __( 'Progress', 'ghca-acd' ),
        'value' => $employee['progress_label'],
        'icon'  => 'progress',
      ),
      array(
        'label' => __( 'Completed', 'ghca-acd' ),
        'value' => $employee['completed_label'],
        'icon'  => 'status',
      ),
      array(
        'label'   => __( 'Due Date', 'ghca-acd' ),
        'value'   => $employee['due_date_label'],
        'icon'    => 'due',
        'warning' => in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue' ), true ),
      ),
      array(
        'label'   => __( 'Last Activity', 'ghca-acd' ),
        'value'   => $employee['last_activity_label'],
        'icon'    => 'compliance',
        'compact' => true,
      ),
    );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--user-report">
      <?php echo self::render_back_link(); ?>

      <?php if ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) ) : ?>
        <div class="ghca-acd__alert ghca-acd__alert--warning">
          <?php
          if ( ! empty( $new_hire['overdue'] ) ) {
            esc_html_e( 'New hire onboarding is overdue. All assigned courses must be completed within the onboarding window.', 'ghca-acd' );
          } else {
            printf(
              /* translators: %s: deadline label */
              esc_html__( 'New hire onboarding in progress — complete all assigned courses by %s.', 'ghca-acd' ),
              esc_html( (string) ( $new_hire['deadline_label'] ?? $employee['due_date_label'] ) )
            );
          }
          ?>
        </div>
      <?php endif; ?>

      <div class="ghca-acd__panel ghca-acd__panel--user-report">
        <p class="ghca-acd__eyebrow"><?php esc_html_e( 'Employee Compliance Report', 'ghca-acd' ); ?></p>

        <div class="ghca-acd__user-report-header">
          <div>
            <h1><?php echo esc_html( $employee['name'] ); ?></h1>
            <p class="ghca-acd__user-report-meta">
              <a href="<?php echo esc_url( 'mailto:' . $employee['email'] ); ?>"><?php echo esc_html( $employee['email'] ); ?></a>
              · <?php echo esc_html( $employee['group'] ); ?>
            </p>
          </div>
          <span class="ghca-acd__status ghca-acd__status--<?php echo esc_attr( $employee['status_slug'] ); ?>">
            <?php echo esc_html( $employee['status_label'] ); ?>
          </span>
        </div>

        <div class="ghca-acd__grid ghca-acd__grid--4 ghca-acd__user-report-stats">
          <?php foreach ( $stats as $stat ) : ?>
            <div class="ghca-acd__card ghca-acd__stat-card">
              <span class="ghca-acd__stat-heading">
                <span class="ghca-acd__stat-icon" aria-hidden="true"><?php echo GHCA_UI_Icons::render( (string) $stat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="ghca-acd__label"><?php echo esc_html( $stat['label'] ); ?></span>
              </span>
              <strong class="ghca-acd__value<?php echo ! empty( $stat['warning'] ) ? ' ghca-acd__value--warning' : ''; ?><?php echo ! empty( $stat['compact'] ) ? ' ghca-acd__value--compact' : ''; ?>">
                <?php echo esc_html( $stat['value'] ); ?>
              </strong>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="ghca-acd__user-report-actions">
          <?php echo wp_kses_post( GHCA_Admin_Compliance_Dashboard::build_user_report_actions_html( $user_id, $employee['email'] ) ); ?>
        </div>
      </div>

      <div class="ghca-acd__panel">
        <h2 class="ghca-acd__panel-title"><?php esc_html_e( 'Required Courses', 'ghca-acd' ); ?></h2>
        <div class="ghca-acd__table-wrap">
          <table class="ghca-acd__table ghca-acd__table--user-courses">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Course', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Status', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Progress', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Last Activity', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Action', 'ghca-acd' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $employee['courses'] ) ) : ?>
                <tr><td colspan="5" class="ghca-acd__table-empty"><?php esc_html_e( 'No required courses assigned.', 'ghca-acd' ); ?></td></tr>
              <?php else : ?>
                <?php foreach ( $employee['courses'] as $course ) : ?>
                  <?php
                  $course_status = ! empty( $course['completed'] ) ? 'completed' : (string) ( $course['status'] ?? 'not_started' );
                  ?>
                  <tr>
                    <td><?php echo esc_html( $course['title'] ); ?></td>
                    <td>
                      <span class="ghca-acd__status ghca-acd__status--<?php echo esc_attr( $course_status ); ?>">
                        <?php echo esc_html( $course['status_label'] ); ?>
                      </span>
                    </td>
                    <td><?php echo esc_html( $course['progress_label'] ); ?></td>
                    <td><?php echo esc_html( GHCA_ACD_Data_Provider::format_activity_label( (int) ( $course['last_activity_ts'] ?? 0 ) ) ); ?></td>
                    <td class="ghca-acd__actions">
                      <div class="ghca-acd__action-chips">
                        <a href="<?php echo esc_url( $course['url'] ); ?>"><?php esc_html_e( 'View Course', 'ghca-acd' ); ?></a>
                        <?php if ( ! empty( $course['certificate_url'] ) ) : ?>
                          <?php echo GHCA_Admin_Compliance_Dashboard::build_certificate_link_html( (string) $course['certificate_url'], (string) $course['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function can_view_user( int $user_id ): bool {
    if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
      return false;
    }

    if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_users' ) ) {
      return true;
    }

    $employee = GHCA_ACD_Data_Provider::get_employee_record( $user_id );
    $group_id = (int) ( $employee['group_id'] ?? 0 );
    if ( $group_id <= 0 ) {
      return false;
    }

    return in_array( $group_id, GHCA_ACD_Scoping::get_visible_group_ids(), true );
  }

  private static function render_back_link(): string {
    ob_start();
    ?>
    <p class="ghca-acd__back">
      <a href="<?php echo esc_url( GHCA_ACD_Data_Provider::get_admin_dashboard_url() ); ?>">
        <?php echo esc_html( apply_filters( 'ghca_user_report_back_label', __( '← Back to Compliance Dashboard', 'ghca-acd' ) ) ); ?>
      </a>
    </p>
    <?php
    return (string) ob_get_clean();
  }

  private static function wrap_message( string $message, string $type, bool $show_dashboard_cta = false ): string {
    $class = 'ghca-acd__card ghca-acd__card--center';
    if ( 'denied' === $type ) {
      $class .= ' ghca-acd__card--denied';
    }

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--user-report">
      <?php echo self::render_back_link(); ?>
      <div class="<?php echo esc_attr( $class ); ?>">
        <p><?php echo esc_html( $message ); ?></p>
        <?php if ( $show_dashboard_cta ) : ?>
          <p class="ghca-acd__user-report-cta">
            <a class="ghca-acd__btn" href="<?php echo esc_url( GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-overdue-employees' ); ?>">
              <?php esc_html_e( 'Go to Compliance Dashboard', 'ghca-acd' ); ?>
            </a>
          </p>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }
}
