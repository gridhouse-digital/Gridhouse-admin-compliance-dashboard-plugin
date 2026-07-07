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
require_once __DIR__ . '/includes/class-ajax-handlers.php';
require_once __DIR__ . '/includes/class-announcements.php';
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
require_once __DIR__ . '/includes/class-audit-pdf-jobs.php';
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
    GHCA_ACD_AJAX::init();
    GHCA_ACD_Announcements::init();
    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );
    add_action( 'init', array( __CLASS__, 'register_roles_on_activation' ), 20 );
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

  public static function page_uses_dashboard( WP_Post $post ): bool {
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
}

GHCA_Admin_Compliance_Dashboard::init();

register_activation_hook( __FILE__, static function (): void {
  GHCA_ACD_Roles::register_roles();
} );
