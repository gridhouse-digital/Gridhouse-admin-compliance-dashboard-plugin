<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Scoping {
  public static function init(): void {
    add_shortcode( 'admin_compliance_scope_banner', array( __CLASS__, 'render_scope_banner' ) );
    add_shortcode( 'admin_compliance_group_summary', array( __CLASS__, 'render_group_summary' ) );
  }

  /** @return array<int> */
  public static function get_visible_group_ids(): array {
    // Dynamically fetch all published LearnDash groups
    $all_groups = get_posts( array(
      'post_type'              => 'groups',
      'posts_per_page'         => -1,
      'fields'                 => 'ids',
      'post_status'            => 'publish',
      'no_found_rows'          => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ) );
    $all = apply_filters( 'ghca_compliance_group_ids', empty( $all_groups ) ? array() : $all_groups );

    if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_users' ) || GHCA_ACD_Roles::user_has_unrestricted_view() ) {
      return array_map( 'intval', (array) $all );
    }

    if ( in_array( 'group_leader', (array) wp_get_current_user()->roles, true ) && function_exists( 'learndash_get_administrators_group_ids' ) ) {
      $leader_groups = array_map( 'intval', (array) learndash_get_administrators_group_ids( get_current_user_id() ) );
      $visible       = array_values( array_intersect( array_map( 'intval', $all ), $leader_groups ) );
      return ! empty( $visible ) ? $visible : array();
    }

    return array_map( 'intval', (array) $all );
  }

  public static function is_scoped_user(): bool {
    return ! current_user_can( 'manage_options' )
      && ! current_user_can( 'edit_users' )
      && ! GHCA_ACD_Roles::user_has_unrestricted_view()
      && in_array( 'group_leader', (array) wp_get_current_user()->roles, true );
  }

  public static function render_scope_banner( $atts = array() ): string {
    if ( ! GHCA_ACD_Roles::user_can_view() || ! is_user_logged_in() ) {
      return '';
    }

    $groups = self::get_visible_group_ids();
    $labels = array();
    foreach ( $groups as $gid ) {
      $labels[] = get_the_title( $gid );
    }

    $scope_label = empty( $labels )
      ? __( 'No assigned groups', 'ghca-acd' )
      : implode( ', ', $labels );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--scope">
      <div class="ghca-acd__scope-banner" role="status">
        <strong><?php esc_html_e( 'Data scope:', 'ghca-acd' ); ?></strong>
        <?php echo esc_html( $scope_label ); ?>
        <?php if ( self::is_scoped_user() ) : ?>
          <span class="ghca-acd__scope-note"><?php esc_html_e( 'Group leader view — only your LearnDash groups are shown.', 'ghca-acd' ); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_group_summary( $atts = array() ): string {
    if ( ! GHCA_ACD_Roles::user_can_view() ) {
      return '';
    }

    $rows        = self::build_group_summary_rows();
    if ( empty( $rows ) ) {
      return '';
    }

    $group_count = count( $rows );
    $grid_cols   = min( 4, max( 1, $group_count ) );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--group-summary">
      <div class="ghca-acd__panel ghca-acd__panel--flush">
        <div class="ghca-acd__section-head ghca-acd__section-head--bordered">
          <div>
            <h2><?php esc_html_e( 'Compliance by Group', 'ghca-acd' ); ?></h2>
            <p><?php esc_html_e( 'Completion health across active training groups', 'ghca-acd' ); ?></p>
          </div>
          <a class="ghca-acd__link-btn" href="#ghca-tab-employees" data-ghca-tab-jump="ghca-tab-employees"><?php esc_html_e( 'View all groups', 'ghca-acd' ); ?></a>
        </div>
        <div class="ghca-acd__group-grid ghca-acd__group-grid--cols-<?php echo (int) $grid_cols; ?>">
          <?php
          foreach ( $rows as $row ) :
            $rate = (int) $row['rate'];
            if ( $rate >= 80 ) {
              $bar_tone = 'success';
            } elseif ( $rate >= 50 ) {
              $bar_tone = 'warning';
            } else {
              $bar_tone = 'danger';
            }
            ?>
            <div class="ghca-acd__group-col">
              <div class="ghca-acd__group-col-head">
                <span class="ghca-acd__group-col-name"><?php echo esc_html( $row['label'] ); ?></span>
                <span class="ghca-acd__group-card-risk ghca-acd__group-card-risk--<?php echo esc_attr( $row['risk'] ); ?>"><?php echo esc_html( $row['risk_label'] ); ?></span>
              </div>
              <div class="ghca-acd__group-col-pct">
                <span class="ghca-acd__group-card-pct"><?php echo esc_html( (string) $rate ); ?>%</span>
                <span><?php esc_html_e( 'complete', 'ghca-acd' ); ?></span>
              </div>
              <div class="ghca-acd__group-bar-track">
                <div class="ghca-acd__group-bar-fill ghca-acd__group-bar-fill--<?php echo esc_attr( $bar_tone ); ?>" style="width: <?php echo esc_attr( (string) $rate ); ?>%"></div>
              </div>
              <div class="ghca-acd__group-col-stats">
                <div>
                  <div class="ghca-acd__group-col-num"><?php echo esc_html( (string) $row['employees'] ); ?></div>
                  <div class="ghca-acd__group-col-lbl"><?php esc_html_e( 'employees', 'ghca-acd' ); ?></div>
                </div>
                <div>
                  <div class="ghca-acd__group-col-num ghca-acd__group-col-num--danger"><?php echo esc_html( (string) $row['overdue'] ); ?></div>
                  <div class="ghca-acd__group-col-lbl"><?php esc_html_e( 'overdue', 'ghca-acd' ); ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @return array<int,array<string,mixed>> */
  public static function build_group_summary_rows(): array {
    $employees = GHCA_Admin_Compliance_Dashboard::get_employees_for_current_view();
    $groups    = self::get_visible_group_ids();
    $rows      = array();

    foreach ( $groups as $group_id ) {
      $group_employees = array_values(
        array_filter(
          $employees,
          static function ( array $employee ) use ( $group_id ): bool {
            return (int) $employee['group_id'] === (int) $group_id;
          }
        )
      );

      $total     = count( $group_employees );
      $completed = count(
        array_filter(
          $group_employees,
          static function ( array $employee ): bool {
            return 'completed' === $employee['status_slug'];
          }
        )
      );
      $overdue = count(
        array_filter(
          $group_employees,
          static function ( array $employee ): bool {
            return in_array( $employee['status_slug'], array( 'overdue', 'new_hire_overdue' ), true );
          }
        )
      );
      $rate = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0;

      if ( $rate < 60 ) {
        $risk       = 'high';
        $risk_label = __( 'High risk', 'ghca-acd' );
      } elseif ( $rate < 85 ) {
        $risk       = 'medium';
        $risk_label = __( 'Medium', 'ghca-acd' );
      } else {
        $risk       = 'low';
        $risk_label = __( 'Low risk', 'ghca-acd' );
      }

      $rows[] = array(
        'label'      => get_the_title( $group_id ),
        'rate'       => $rate,
        'rate_label' => $rate . '% compliant',
        'employees'  => $total,
        'overdue'    => $overdue,
        'risk'       => $risk,
        'risk_label' => $risk_label,
      );
    }

    return $rows;
  }
}
