<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

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

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $out = fopen( 'php://output', 'w' );
    if ( ! $out ) {
      wp_die( esc_html__( 'Export failed.', 'ghca-acd' ) );
    }

    fputcsv(
      $out,
      array_map( array( __CLASS__, 'csv_safe' ), array(
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
      ) )
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
      fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
    }

    fclose( $out );
    exit;
  }

  private static function csv_safe( $value ): string {
    $value = (string) $value;
    if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
      $value = "'" . $value; // leading apostrophe = Excel treats as text
    }
    return $value;
  }
}
