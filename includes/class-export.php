<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once __DIR__ . '/class-csv-writer.php';

final class GHCA_ACD_Export {
  public static function init(): void {
    add_action( 'admin_post_ghca_acd_export_csv', array( __CLASS__, 'handle_export' ) );
    add_shortcode( 'admin_compliance_export_button', array( __CLASS__, 'render_button' ) );
  }

  public static function render_button( $atts = array() ): string {
    if ( ! GHCA_ACD_Roles::user_can_view() ) {
      return '';
    }

    $url = wp_nonce_url(
      admin_url( 'admin-post.php?action=ghca_acd_export_csv' ),
      'ghca_acd_export_csv'
    );

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--export-inline">
      <a class="ghca-acd__btn ghca-acd__btn--export" href="<?php echo esc_url( $url ); ?>">
        <?php esc_html_e( 'Download CSV Export', 'ghca-acd' ); ?>
      </a>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function handle_export(): void {
    if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      wp_die( esc_html__( 'Unauthorized.', 'ghca-acd' ) );
    }

    check_admin_referer( 'ghca_acd_export_csv' );

    $employees = GHCA_ACD_Data_Provider::get_employees_for_current_view();
    $filename  = 'compliance-export-' . wp_date( 'Y-m-d-His' ) . '.csv';

    // "Export selected" (redesigned Employees tab) posts the selected ids. Each must be an active employee in this
    // viewer's own scope; if any is not, nothing is exported, so a hidden or foreign id can never be included.
    $selected = self::selected_ids();
    if ( null !== $selected ) {
      $by_id = array();
      foreach ( $employees as $employee ) {
        $by_id[ (int) ( $employee['user_id'] ?? 0 ) ] = $employee;
      }
      if ( ! $selected || array_diff( $selected, array_keys( $by_id ) ) ) {
        wp_die( esc_html__( 'One or more selected employees are not in your current view. Nothing was exported.', 'ghca-acd' ) );
      }
      $employees = array();
      foreach ( $selected as $id ) {
        $employees[] = $by_id[ $id ];
      }
      $filename = 'compliance-export-selected-' . wp_date( 'Y-m-d-His' ) . '.csv';
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $out = fopen( 'php://output', 'w' );
    if ( ! $out ) {
      wp_die( esc_html__( 'Export failed.', 'ghca-acd' ) );
    }

    GHCA_ACD_CSV::write_row(
      $out,
      array(
        'Employee Name',
        'Email',
        'Group',
        'Required Courses',
        'Completed Courses',
        'Progress %',
        'Due Date',
        'Certificate',
        'Last Activity',
        'Status',
      )
    );

    foreach ( $employees as $employee ) {
      $row = array(
        $employee['name'],
        $employee['email'],
        $employee['group'],
        $employee['required_label'],
        $employee['completed_label'],
        $employee['progress_label'],
        $employee['due_date_label'],
        $employee['certificate_label'],
        $employee['last_activity_label'],
        $employee['status_label'],
      );
      GHCA_ACD_CSV::write_row( $out, $row );
    }

    fclose( $out );
    exit;
  }

  /**
   * Ids posted by "Export selected", or null when the request is the ordinary full export.
   *
   * @return array<int,int>|null
   */
  private static function selected_ids(): ?array {
    if ( ! isset( $_POST['ghca_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the export nonce.
      return null;
    }
    $ids = array();
    foreach ( explode( ',', (string) wp_unslash( $_POST['ghca_ids'] ) ) as $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- digits only, checked below.
      $value = trim( $value );
      if ( '' === $value ) {
        continue;
      }
      if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
        return array();
      }
      $ids[ (int) $value ] = (int) $value;
    }
    return array_values( $ids );
  }

}
