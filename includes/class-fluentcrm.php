<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_FluentCRM {
  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'handle_reminder_log' ) );
  }

  public static function is_active(): bool {
    return defined( 'FLUENTCRM' ) || class_exists( '\FluentCrm\App\Models\Subscriber' );
  }

  public static function get_contact_admin_url( string $email ): string {
    if ( ! self::is_active() || ! current_user_can( 'manage_options' ) ) {
      return '';
    }

    if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
      return '';
    }

    $subscriber = \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first();
    if ( ! $subscriber ) {
      return '';
    }

    return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $subscriber->id );
  }

  public static function log_reminder_note( int $employee_user_id, string $course_title = '' ): bool {
    if ( ! self::is_active() || ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
      return false;
    }

    $employee = get_userdata( $employee_user_id );
    if ( ! $employee ) {
      return false;
    }

    $subscriber = \FluentCrm\App\Models\Subscriber::where( 'email', $employee->user_email )->first();
    if ( ! $subscriber ) {
      return false;
    }

    $admin   = wp_get_current_user();
    $message = $course_title
      ? sprintf(
        __( 'Compliance reminder logged by %1$s for course: %2$s', 'ghca-acd' ),
        $admin->display_name,
        $course_title
      )
      : sprintf(
        __( 'Compliance reminder logged by %1$s.', 'ghca-acd' ),
        $admin->display_name
      );

    if ( class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
      \FluentCrm\App\Models\SubscriberNote::create(
        array(
          'subscriber_id' => $subscriber->id,
          'title'         => __( 'Compliance reminder', 'ghca-acd' ),
          'description'   => $message,
          'type'          => 'note',
          'created_by'    => get_current_user_id(),
        )
      );
      return true;
    }

    return false;
  }

  public static function build_reminder_url( int $employee_user_id, string $email, string $course_title = '' ): string {
    $base = add_query_arg(
      array(
        'ghca_acd_reminder' => 1,
        'employee_id'       => $employee_user_id,
        'course'            => rawurlencode( $course_title ),
      ),
      get_permalink() ?: home_url( '/compliance-admin-dashboard/' )
    );

    return wp_nonce_url( $base, 'ghca_acd_reminder_' . $employee_user_id, 'ghca_nonce' );
  }

  public static function handle_reminder_log(): void {
    if ( empty( $_GET['ghca_acd_reminder'] ) || empty( $_GET['employee_id'] ) ) {
      return;
    }

    if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }

    $employee_id = (int) wp_unslash( $_GET['employee_id'] );
    check_admin_referer( 'ghca_acd_reminder_' . $employee_id, 'ghca_nonce' );

    $course = isset( $_GET['course'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['course'] ) ) ) : '';
    self::log_reminder_note( $employee_id, $course );

    $redirect = remove_query_arg( array( 'ghca_acd_reminder', 'employee_id', 'course', 'ghca_nonce', '_wpnonce' ) );
    wp_safe_redirect( add_query_arg( 'ghca_reminder_logged', '1', $redirect ) );
    exit;
  }

  /** @return array<int,string> */
  public static function build_action_links( int $employee_user_id, string $email, string $course_title = '' ): array {
    $links = array(
      '<a href="' . esc_url( self::build_reminder_url( $employee_user_id, $email, $course_title ) ) . '">' . esc_html__( 'Log Reminder', 'ghca-acd' ) . '</a>',
      '<a href="' . esc_url( GHCA_Admin_Compliance_Dashboard::build_mailto_reminder( $email, $course_title ) ) . '">' . esc_html__( 'Email Reminder', 'ghca-acd' ) . '</a>',
    );

    $crm_url = self::get_contact_admin_url( $email );
    if ( $crm_url ) {
      $links[] = '<a href="' . esc_url( $crm_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'FluentCRM Contact', 'ghca-acd' ) . '</a>';
    }

    return $links;
  }
}
