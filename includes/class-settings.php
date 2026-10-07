<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
require_once __DIR__ . '/class-packet-course-selection.php';
require_once __DIR__ . '/class-agency-training.php';

final class GHCA_ACD_Settings {
  const OPTION_AGENCY_PROFILE = 'ghca_acd_agency_profile';
  const OPTION_AT_RISK_DAYS = 'ghca_acd_at_risk_days';
  const OPTION_CACHE_TTL    = 'ghca_acd_cache_ttl';
  const OPTION_PERM_EDIT_RECORDS = 'ghca_acd_permission_edit_records';
  const OPTION_PERM_MANAGE_ANNOUNCEMENTS = 'ghca_acd_permission_manage_announcements';
  const OPTION_PERM_UNRESTRICTED_VIEW = 'ghca_acd_permission_unrestricted_view';
  const OPTION_PERM_MANAGE_USERS = 'ghca_acd_permission_manage_users';
  const OPTION_PERM_SEND_REMINDERS = 'ghca_acd_permission_send_reminders';
  const OPTION_PERM_VIEW_COMMUNICATIONS = 'ghca_acd_permission_view_communications';
  const OPTION_PERM_MANAGE_TEMPLATES = 'ghca_acd_permission_manage_message_templates';
  const OPTION_PERM_VIEW_DOCUMENTS = 'ghca_acd_permission_view_employee_documents';
  const OPTION_ANNUAL_CYCLE = 'ghca_acd_annual_cycle';
  const OPTION_ISP_MANUAL_DELIVERY = 'ghca_acd_isp_manual_delivery';
  const OPTION_PACKET_COURSE_DETAILS = 'ghca_acd_packet_course_details';
  const OPTION_REMINDER_EMAIL_ENABLED = 'ghca_acd_reminder_email_enabled';
  const OPTION_REMINDER_FROM_NAME = 'ghca_acd_reminder_from_name';
  const OPTION_REMINDER_REPLY_TO = 'ghca_acd_reminder_reply_to';
  const OPTION_REMINDER_ALLOW_INACTIVE = 'ghca_acd_reminder_allow_inactive';
  const OPTION_REMINDER_HTML_ENABLED = 'ghca_acd_reminder_html_enabled';
  const OPTION_REMINDER_BUTTON_LABEL = 'ghca_acd_reminder_button_label';
  const OPTION_REMINDER_FOOTER_TEXT = 'ghca_acd_reminder_footer_text';
  const OPTION_SMS_ENABLED = 'ghca_acd_sms_enabled';
  const OPTION_TWILIO_ACCOUNT_MODE = 'ghca_acd_twilio_account_mode';
  const OPTION_TWILIO_ACCOUNT_SID = 'ghca_acd_twilio_account_sid';
  const OPTION_TWILIO_API_KEY_SID = 'ghca_acd_twilio_api_key_sid';
  const OPTION_TWILIO_API_SECRET = 'ghca_acd_twilio_api_secret';
  const OPTION_TWILIO_WEBHOOK_AUTH_TOKEN = 'ghca_acd_twilio_webhook_auth_token';
  const OPTION_TWILIO_MESSAGING_SERVICE_SID = 'ghca_acd_twilio_messaging_service_sid';
  const OPTION_TWILIO_SENDER_NAME = 'ghca_acd_twilio_sender_name';
  const OPTION_TWILIO_A2P_STATUS = 'ghca_acd_twilio_a2p_status';
  const OPTION_TWILIO_A2P_REFERENCE = 'ghca_acd_twilio_a2p_reference';
  const OPTION_TWILIO_ADVANCED_OPT_OUT = 'ghca_acd_twilio_advanced_opt_out';
  const OPTION_TWILIO_CONNECTION_FINGERPRINT = 'ghca_acd_twilio_connection_fingerprint';
  const OPTION_TWILIO_CONNECTION_TESTED_AT = 'ghca_acd_twilio_connection_tested_at';
  const OPTION_SMS_DISCLOSURE_VERSION = 'ghca_acd_sms_disclosure_version';
  const OPTION_SMS_PRIVACY_URL = 'ghca_acd_sms_privacy_url';
  const OPTION_SMS_TERMS_URL = 'ghca_acd_sms_terms_url';

  public static function init(): void {
    GHCA_Agency_Training::init();
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
    // Both hooks per option: WordPress fires add_option_{name} (not
    // update_option_{name}) the first time a not-yet-persisted option is saved.
    $bust_options = array(
      GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS,
      GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS,
      GHCA_Dashboard_Branding::OPTION,
      GHCA_Course_Lifespans::OPTION_LIFESPANS,
      GHCA_Course_Lifespans::OPTION_WARNING_DAYS,
      self::OPTION_PERM_EDIT_RECORDS,
      self::OPTION_PERM_MANAGE_ANNOUNCEMENTS,
      self::OPTION_PERM_UNRESTRICTED_VIEW,
      self::OPTION_PERM_MANAGE_USERS,
      self::OPTION_PERM_SEND_REMINDERS,
      self::OPTION_PERM_VIEW_COMMUNICATIONS,
      self::OPTION_PERM_MANAGE_TEMPLATES,
      self::OPTION_PERM_VIEW_DOCUMENTS,
      self::OPTION_ANNUAL_CYCLE,
      self::OPTION_ISP_MANUAL_DELIVERY,
    );
    foreach ( $bust_options as $option_name ) {
      add_action( 'update_option_' . $option_name, array( __CLASS__, 'bust_dashboard_cache' ) );
      add_action( 'add_option_' . $option_name, array( __CLASS__, 'bust_dashboard_cache' ) );
    }
    add_filter( 'ghca_admin_support_email', array( 'GHCA_Dashboard_Branding', 'get_support_email' ) );
    add_filter( 'ghca_employee_support_email', array( 'GHCA_Dashboard_Branding', 'get_support_email' ) );
  }

  public static function bust_dashboard_cache(): void {
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_ghca_acd_agg_' ) . '%';
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $like,
        $wpdb->esc_like( '_transient_timeout_ghca_acd_agg_' ) . '%'
      )
    );
  }

  public static function register_settings(): void {
    register_setting( 'ghca_acd_settings', self::OPTION_ISP_MANUAL_DELIVERY, array(
      'type' => 'integer',
      'sanitize_callback' => static function ( $value ): int { return in_array( $value, array( 1, '1' ), true ) ? 1 : 0; },
      'default' => 0,
      'show_in_rest' => false,
    ) );
    GHCA_Packet_Course_Selection::register();
    register_setting( 'ghca_acd_settings', GHCA_Agency_Training::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'GHCA_Agency_Training', 'sanitize_settings' ), 'default' => array(), 'show_in_rest' => false ) );
    register_setting( 'ghca_acd_agency_profile', self::OPTION_AGENCY_PROFILE, array(
      'type' => 'array',
      'sanitize_callback' => array( __CLASS__, 'sanitize_agency_profile' ),
      'default' => array(),
      'show_in_rest' => false,
    ) );
    register_setting(
      'ghca_acd_settings',
      self::OPTION_AT_RISK_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 120, $value ) );
        },
        'default'           => 30,
      )
    );

    register_setting(
      'ghca_acd_settings',
      self::OPTION_CACHE_TTL,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 0, min( 3600, $value ) );
        },
        'default'           => 300,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_LIFESPANS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( 'GHCA_Course_Lifespans', 'sanitize_lifespan_map' ),
        'default'           => array(),
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_WARNING_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 365, $value ) );
        },
        'default'           => GHCA_Course_Lifespans::DEFAULT_WARNING_DAYS,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( __CLASS__, 'sanitize_group_ids' ),
        'default'           => array(),
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 120, $value ) );
        },
        'default'           => GHCA_Compliance_Program::DEFAULT_DEADLINE_DAYS,
      )
    );

    register_setting(
      'ghca_acd_settings',
      self::OPTION_ANNUAL_CYCLE,
      array(
        'type'              => 'string',
        'sanitize_callback' => array( __CLASS__, 'sanitize_annual_cycle' ),
        // A policy default makes WordPress skip persisting a matching first save.
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_settings',
      self::OPTION_PACKET_COURSE_DETAILS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          return empty( $value ) ? 0 : 1;
        },
        'default'           => 1,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Dashboard_Branding::OPTION,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( 'GHCA_Dashboard_Branding', 'sanitize' ),
        'default'           => GHCA_Dashboard_Branding::defaults(),
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_EDIT_RECORDS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_MANAGE_ANNOUNCEMENTS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_UNRESTRICTED_VIEW,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_MANAGE_USERS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_SEND_REMINDERS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_VIEW_COMMUNICATIONS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_MANAGE_TEMPLATES,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_VIEW_DOCUMENTS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
		'ghca_acd_messaging',
      self::OPTION_REMINDER_EMAIL_ENABLED,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          return empty( $value ) ? 0 : 1;
        },
        'default'           => 0,
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_FROM_NAME,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'Gridhouse Digital',
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_REPLY_TO,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_ALLOW_INACTIVE,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          return empty( $value ) ? 0 : 1;
        },
        'default'           => 0,
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_HTML_ENABLED,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          return empty( $value ) ? 0 : 1;
        },
        'default'           => 1,
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_BUTTON_LABEL,
      array(
        'type'              => 'string',
        'sanitize_callback' => static function ( $value ): string {
          return substr( sanitize_text_field( (string) $value ), 0, 80 );
        },
        'default'           => 'View Training Dashboard',
      )
    );

    register_setting(
      'ghca_acd_messaging',
      self::OPTION_REMINDER_FOOTER_TEXT,
      array(
        'type'              => 'string',
        'sanitize_callback' => static function ( $value ): string {
          return substr( sanitize_text_field( (string) $value ), 0, 300 );
        },
        'default'           => 'This is an employee training and compliance notification.',
      )
    );

    $boolean_options = array( self::OPTION_SMS_ENABLED, self::OPTION_TWILIO_ADVANCED_OPT_OUT );
    foreach ( $boolean_options as $option_name ) {
      register_setting(
        'ghca_acd_sms',
        $option_name,
        array(
          'type'              => 'integer',
          'sanitize_callback' => static function ( $value ): int { return empty( $value ) ? 0 : 1; },
          'default'           => 0,
        )
      );
    }

    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_ACCOUNT_MODE, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_twilio_account_mode' ), 'default' => 'agency_owned' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_ACCOUNT_SID, array( 'type' => 'string', 'sanitize_callback' => static function ( $value ): string { return self::sanitize_twilio_sid( $value, 'AC' ); }, 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_API_KEY_SID, array( 'type' => 'string', 'sanitize_callback' => static function ( $value ): string { return self::sanitize_twilio_sid( $value, 'SK' ); }, 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_MESSAGING_SERVICE_SID, array( 'type' => 'string', 'sanitize_callback' => static function ( $value ): string { return self::sanitize_twilio_sid( $value, 'MG' ); }, 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_API_SECRET, array( 'type' => 'string', 'sanitize_callback' => array( 'GHCA_ACD_Messaging_Secret_Store', 'sanitize_api_secret' ), 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_WEBHOOK_AUTH_TOKEN, array( 'type' => 'string', 'sanitize_callback' => array( 'GHCA_ACD_Messaging_Secret_Store', 'sanitize_webhook_token' ), 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_SENDER_NAME, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Gridhouse Digital' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_A2P_STATUS, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_twilio_a2p_status' ), 'default' => 'not_ready' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_TWILIO_A2P_REFERENCE, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_SMS_DISCLOSURE_VERSION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '1' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_SMS_PRIVACY_URL, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
    register_setting( 'ghca_acd_sms', self::OPTION_SMS_TERMS_URL, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
  }

  /** @param mixed $value */
  public static function sanitize_twilio_sid( $value, string $prefix ): string {
    $value = trim( (string) $value );
    return preg_match( '/^' . preg_quote( $prefix, '/' ) . '[a-f0-9]{32}$/i', $value ) ? $value : '';
  }

  /** @param mixed $value */
  public static function sanitize_twilio_account_mode( $value ): string {
    return in_array( $value, array( 'gridhouse_managed_subaccount', 'agency_owned' ), true ) ? (string) $value : 'agency_owned';
  }

  /** @param mixed $value */
  public static function sanitize_twilio_a2p_status( $value ): string {
    return in_array( $value, array( 'not_ready', 'pending', 'active', 'not_applicable' ), true ) ? (string) $value : 'not_ready';
  }

  /** @param mixed $value @return array<int> */
  public static function sanitize_group_ids( $value ): array {
    if ( ! is_array( $value ) ) {
      return array();
    }

    return array_values( array_unique( array_map( 'intval', $value ) ) );
  }

  public static function register_page(): void {
    add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'Agency Profile', 'ghca-acd' ), __( 'Agency Profile', 'ghca-acd' ), 'manage_options', 'ghca-acd-agency-profile', array( __CLASS__, 'render_agency_profile' ) );
    add_submenu_page(
      GHCA_ACD_Admin_Menu::SLUG,
      __( 'Admin Compliance Dashboard', 'ghca-acd' ),
      __( 'Settings', 'ghca-acd' ),
      'manage_options',
      'ghca-acd-settings',
      array( __CLASS__, 'render_page' )
    );

    add_submenu_page(
      GHCA_ACD_Admin_Menu::SLUG,
      __( 'Compliance Permissions', 'ghca-acd' ),
      __( 'Permissions', 'ghca-acd' ),
      'manage_options',
      'ghca-acd-permissions',
      array( __CLASS__, 'render_permissions_page' )
    );

    if ( GHCA_ACD_Roles::user_can_manage_messaging_settings() || GHCA_ACD_Roles::user_can_manage_message_templates() ) {
      add_submenu_page(
        GHCA_ACD_Admin_Menu::SLUG,
        __( 'Compliance Messaging', 'ghca-acd' ),
        __( 'Messaging', 'ghca-acd' ),
        'read',
        'ghca-acd-messaging',
        array( 'GHCA_ACD_Messaging_UI', 'render_settings_page' )
      );
    }
  }

  public static function get_at_risk_days(): int {
    return (int) get_option( self::OPTION_AT_RISK_DAYS, 30 );
  }

  /** Onboarding context only; these descriptions never select regulatory rules. */
  private static function agency_profile_fields(): array {
    return array(
      'programs' => __( 'Programs and jurisdictions', 'ghca-acd' ),
      'services' => __( 'Services provided', 'ghca-acd' ),
      'provider_model' => __( 'Provider model(s)', 'ghca-acd' ),
      'staff_duties' => __( 'Job titles and staff duties', 'ghca-acd' ),
      'employment_date_source' => __( 'Verified employment-date source', 'ghca-acd' ),
      'training_policy_reference' => __( 'Training-year policy reference and applicability', 'ghca-acd' ),
      'agency_requirements' => __( 'Agency-added requirements (not regulation)', 'ghca-acd' ),
    );
  }

  public static function get_agency_profile(): array {
    $profile = get_option( self::OPTION_AGENCY_PROFILE, array() );
    return is_array( $profile ) ? $profile : array();
  }

  /** Preserve the entire previous profile on invalid or incomplete submissions. */
  public static function sanitize_agency_profile( $input ): array {
    $previous = self::get_agency_profile();
    if ( ! current_user_can( 'manage_options' ) ) {
      add_settings_error( self::OPTION_AGENCY_PROFILE, 'agency_profile_denied', __( 'Permission denied. Agency profile was not changed.', 'ghca-acd' ) );
      return $previous;
    }
    // options.php may supply null for an omitted option; do not erase saved context.
    if ( null === $input ) {
      return $previous;
    }
    $fields = array_merge( array_keys( self::agency_profile_fields() ), array( 'effective_date' ) );
    $profile = array();
    foreach ( $fields as $key ) {
      if ( ! is_array( $input ) || ! array_key_exists( $key, $input ) || ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 4000 ) {
        add_settings_error( self::OPTION_AGENCY_PROFILE, 'agency_profile_invalid', __( 'Agency profile was not saved. Submit all fields as text, no more than 4,000 bytes per field.', 'ghca-acd' ) );
        return $previous;
      }
      $profile[ $key ] = 'effective_date' === $key ? $input[ $key ] : sanitize_textarea_field( $input[ $key ] );
    }
    if ( '' !== $profile['effective_date'] ) {
      $date = preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $profile['effective_date'] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $profile['effective_date'], new DateTimeZone( 'UTC' ) ) : false;
      if ( ! $date || $date->format( 'Y-m-d' ) !== $profile['effective_date'] ) {
        add_settings_error( self::OPTION_AGENCY_PROFILE, 'agency_profile_date', __( 'Agency profile was not saved. Use a valid effective date (YYYY-MM-DD).', 'ghca-acd' ) );
        return $previous;
      }
    }
    // A saved description is not reviewed applicability or permission to activate rules.
    $profile['status'] = 'needs_review';
    if ( array_intersect_key( $previous, $profile ) === $profile ) {
      return $previous;
    }
    $profile['updated_by'] = get_current_user_id();
    $profile['updated_at'] = time();
    return $profile;
  }

  public static function render_agency_profile(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }
    $profile = self::get_agency_profile();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Agency Profile', 'ghca-acd' ); ?></h1>
      <p><?php esc_html_e( 'Needs review — agency context only. Saving this profile does not activate training rules or verify employee records.', 'ghca-acd' ); ?></p>
      <details class="ghca-settings-help"><summary><?php esc_html_e( 'How to complete this profile', 'ghca-acd' ); ?></summary>
      <p><?php esc_html_e( 'Describe the agency, not individual staff or participants. Leave unknown values blank. For multiple programs or provider models, identify which services and job duties belong to each. Job titles are not WordPress access roles.', 'ghca-acd' ); ?></p>
      </details>
      <?php settings_errors( self::OPTION_AGENCY_PROFILE ); ?>
      <p><?php esc_html_e( 'Existing packet cycle:', 'ghca-acd' ); ?> <strong><?php echo esc_html( self::annual_cycle_label( self::get_configured_annual_cycle() ) ); ?></strong>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ghca-acd-settings' ) ); ?>"><?php esc_html_e( 'Manage existing cycle setting', 'ghca-acd' ); ?></a>
      </p>
      <details class="ghca-settings-help"><summary><?php esc_html_e( 'Employment dates and annual-cycle guidance', 'ghca-acd' ); ?></summary><p><?php esc_html_e( 'ODP packets require a verified employment date entered in Administration → Edit Records and an explicitly saved cycle. Select and save the agency policy in Settings if the cycle is Not configured. This descriptive profile does not verify either input; dashboard and OLTL date reconciliation remain pending.', 'ghca-acd' ); ?></p></details>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_acd_agency_profile' ); ?>
        <table class="form-table" role="presentation">
          <?php foreach ( self::agency_profile_fields() as $key => $label ) : ?>
            <tr>
              <th scope="row"><label for="ghca-agency-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
              <td><textarea class="large-text" rows="3" maxlength="4000" id="ghca-agency-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_AGENCY_PROFILE . '[' . $key . ']' ); ?>"><?php echo esc_textarea( is_string( $profile[ $key ] ?? null ) ? $profile[ $key ] : '' ); ?></textarea></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <th scope="row"><label for="ghca-agency-effective-date"><?php esc_html_e( 'Policy effective date (if known)', 'ghca-acd' ); ?></label></th>
            <td><input type="date" id="ghca-agency-effective-date" name="<?php echo esc_attr( self::OPTION_AGENCY_PROFILE ); ?>[effective_date]" value="<?php echo esc_attr( is_string( $profile['effective_date'] ?? null ) ? $profile['effective_date'] : '' ); ?>" /></td>
          </tr>
        </table>
        <?php submit_button( __( 'Save profile for review', 'ghca-acd' ) ); ?>
      </form>
    </div>
    <?php
  }

  public static function get_cache_ttl(): int {
    return (int) get_option( self::OPTION_CACHE_TTL, 300 );
  }

  public static function get_annual_cycle(): string {
    $val = get_option( self::OPTION_ANNUAL_CYCLE, 'employee_start_date' );
    return in_array( $val, array( 'employee_start_date', 'calendar_year' ), true ) ? (string) $val : 'employee_start_date';
  }

  /** Explicit saved input only; legacy display defaults are not agency policy evidence. */
  public static function get_configured_annual_cycle(): string {
    $value = get_option( self::OPTION_ANNUAL_CYCLE, '' );
    return in_array( $value, array( 'employee_start_date', 'calendar_year' ), true ) ? $value : '';
  }

  public static function sanitize_annual_cycle( $value ): string {
    if ( in_array( $value, array( 'employee_start_date', 'calendar_year' ), true ) ) {
      return $value;
    }
    if ( null !== $value ) {
      add_settings_error( self::OPTION_ANNUAL_CYCLE, 'invalid_annual_cycle', __( 'Select an annual training cycle. The existing policy has not been changed.', 'ghca-acd' ) );
    }
    return self::get_configured_annual_cycle();
  }

  public static function annual_cycle_label( string $cycle ): string {
    $labels = array(
      'calendar_year' => __( 'Calendar Year', 'ghca-acd' ),
      'employee_start_date' => __( 'Employee Hire Date', 'ghca-acd' ),
    );
    return $labels[ $cycle ] ?? __( 'Not configured', 'ghca-acd' );
  }

  public static function include_packet_course_details(): bool {
    return (bool) get_option( self::OPTION_PACKET_COURSE_DETAILS, 1 );
  }

  public static function reminder_email_enabled(): bool {
    return (bool) get_option( self::OPTION_REMINDER_EMAIL_ENABLED, 0 );
  }

  public static function get_reminder_from_name(): string {
    $name = sanitize_text_field( (string) get_option( self::OPTION_REMINDER_FROM_NAME, 'Gridhouse Digital' ) );
    return str_replace( array( "\r", "\n" ), '', $name );
  }

  public static function get_reminder_reply_to(): string {
    return sanitize_email( (string) get_option( self::OPTION_REMINDER_REPLY_TO, '' ) );
  }

  public static function allow_inactive_reminders(): bool {
    return (bool) get_option( self::OPTION_REMINDER_ALLOW_INACTIVE, 0 );
  }

  public static function reminder_html_enabled(): bool {
    return (bool) get_option( self::OPTION_REMINDER_HTML_ENABLED, 1 );
  }

  public static function get_reminder_button_label(): string {
    $label = substr( sanitize_text_field( (string) get_option( self::OPTION_REMINDER_BUTTON_LABEL, 'View Training Dashboard' ) ), 0, 80 );
    return '' !== $label ? $label : __( 'View Training Dashboard', 'ghca-acd' );
  }

  public static function get_reminder_footer_text(): string {
    $text = substr( sanitize_text_field( (string) get_option( self::OPTION_REMINDER_FOOTER_TEXT, 'This is an employee training and compliance notification.' ) ), 0, 300 );
    return '' !== $text ? $text : __( 'This is an employee training and compliance notification.', 'ghca-acd' );
  }

  public static function sms_enabled(): bool {
    return (bool) get_option( self::OPTION_SMS_ENABLED, 0 );
  }

  /** @return array<string,string|bool> */
  public static function twilio_config(): array {
    return array(
      'account_mode'          => self::sanitize_twilio_account_mode( get_option( self::OPTION_TWILIO_ACCOUNT_MODE, 'agency_owned' ) ),
      'account_sid'           => self::sanitize_twilio_sid( get_option( self::OPTION_TWILIO_ACCOUNT_SID, '' ), 'AC' ),
      'api_key_sid'           => self::sanitize_twilio_sid( get_option( self::OPTION_TWILIO_API_KEY_SID, '' ), 'SK' ),
      'api_secret_encrypted'  => (string) get_option( self::OPTION_TWILIO_API_SECRET, '' ),
      'webhook_token_encrypted'=> (string) get_option( self::OPTION_TWILIO_WEBHOOK_AUTH_TOKEN, '' ),
      'messaging_service_sid' => self::sanitize_twilio_sid( get_option( self::OPTION_TWILIO_MESSAGING_SERVICE_SID, '' ), 'MG' ),
      'sender_name'           => substr( sanitize_text_field( (string) get_option( self::OPTION_TWILIO_SENDER_NAME, 'Gridhouse Digital' ) ), 0, 120 ),
      'a2p_status'            => self::sanitize_twilio_a2p_status( get_option( self::OPTION_TWILIO_A2P_STATUS, 'not_ready' ) ),
      'a2p_reference'         => substr( sanitize_text_field( (string) get_option( self::OPTION_TWILIO_A2P_REFERENCE, '' ) ), 0, 191 ),
      'advanced_opt_out'      => (bool) get_option( self::OPTION_TWILIO_ADVANCED_OPT_OUT, 0 ),
    );
  }

  public static function twilio_config_fingerprint(): string {
    $config = self::twilio_config();
    return hash_hmac( 'sha256', wp_json_encode( $config ), wp_salt( 'secure_auth' ) );
  }

  public static function twilio_connection_is_current(): bool {
    $tested = (int) get_option( self::OPTION_TWILIO_CONNECTION_TESTED_AT, 0 );
    $saved  = (string) get_option( self::OPTION_TWILIO_CONNECTION_FINGERPRINT, '' );
    return $tested >= time() - ( 30 * DAY_IN_SECONDS ) && '' !== $saved && hash_equals( self::twilio_config_fingerprint(), $saved );
  }

  /** @return array<int,\WP_Post> */
  private static function get_learndash_groups(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'groups',
        'post_status'            => 'publish',
        'posts_per_page'         => 200,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );

    return is_array( $posts ) ? $posts : array();
  }

  /** @return array<int,\WP_Post> */
  private static function get_published_courses(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'sfwd-courses',
        'post_status'            => 'publish',
        'posts_per_page'         => 500,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );
    return is_array( $posts ) ? $posts : array();
  }

  public static function render_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $at_risk         = self::get_at_risk_days();
    $cache           = self::get_cache_ttl();
    $annual_cycle    = self::get_configured_annual_cycle();
    $packet_details  = self::include_packet_course_details();
    $new_hire_groups = GHCA_Compliance_Program::get_new_hire_group_ids();
    $new_hire_days   = GHCA_Compliance_Program::get_deadline_days();
    $groups          = self::get_learndash_groups();
    $brand           = GHCA_Dashboard_Branding::get();
    $brand_defaults  = GHCA_Dashboard_Branding::defaults();
    $brand_saved     = get_option( GHCA_Dashboard_Branding::OPTION, array() );
    $accent_raw      = is_array( $brand_saved ) ? (string) ( $brand_saved['accent'] ?? '' ) : '';
    $option_name     = GHCA_Dashboard_Branding::OPTION;
    $lifespan_map    = GHCA_Course_Lifespans::get_lifespan_map();
    $warning_days    = GHCA_Course_Lifespans::get_warning_days();
    $all_courses     = self::get_published_courses();
    $lifespan_opt    = GHCA_Course_Lifespans::OPTION_LIFESPANS;
    $warning_opt     = GHCA_Course_Lifespans::OPTION_WARNING_DAYS;
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Training & agency settings', 'ghca-acd' ); ?></h1>
      <p><?php esc_html_e( 'Manage the agency’s training cycle, packet presentation, branding and technical settings. Agency-selected packet courses have their own Save button below.', 'ghca-acd' ); ?></p>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_acd_settings' ); ?>
        <details class="ghca-settings-section" id="ghca-training" open><summary><?php esc_html_e( 'Training periods & agency delivery', 'ghca-acd' ); ?></summary>
        <p><?php esc_html_e( 'Configure rules for new hires and annual training cycles.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e( 'New Hire Groups', 'ghca-acd' ); ?></th>
            <td>
              <?php if ( empty( $groups ) ) : ?>
                <p><?php esc_html_e( 'No LearnDash groups found.', 'ghca-acd' ); ?></p>
              <?php else : ?>
                <fieldset>
                  <?php foreach ( $groups as $group ) : ?>
                    <label style="display:block;margin:0 0 8px;">
                      <input
                        type="checkbox"
                        name="<?php echo esc_attr( GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS ); ?>[]"
                        value="<?php echo esc_attr( (string) $group->ID ); ?>"
                        <?php checked( in_array( (int) $group->ID, $new_hire_groups, true ) ); ?>
                      />
                      <?php echo esc_html( $group->post_title ); ?>
                      <code>#<?php echo esc_html( (string) $group->ID ); ?></code>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_new_hire_deadline_days"><?php esc_html_e( 'New hire completion window (days)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="number" min="7" max="120" id="ghca_new_hire_deadline_days" name="<?php echo esc_attr( GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS ); ?>" value="<?php echo esc_attr( (string) $new_hire_days ); ?>" class="small-text" />
              <p class="description"><?php esc_html_e( 'Default: 30 days from new hire group enrollment.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_acd_annual_cycle"><?php esc_html_e( 'Annual Training Cycle', 'ghca-acd' ); ?></label></th>
            <td>
              <select name="<?php echo esc_attr( self::OPTION_ANNUAL_CYCLE ); ?>" id="ghca_acd_annual_cycle">
                <option value="" <?php selected( $annual_cycle, '' ); ?>><?php esc_html_e( 'Not configured — select a cycle', 'ghca-acd' ); ?></option>
                <option value="employee_start_date" <?php selected( $annual_cycle, 'employee_start_date' ); ?>><?php esc_html_e( 'Employee Anniversary (12 months from hire date)', 'ghca-acd' ); ?></option>
                <option value="calendar_year" <?php selected( $annual_cycle, 'calendar_year' ); ?>><?php esc_html_e( 'Calendar Year (Jan 1 - Dec 31)', 'ghca-acd' ); ?></option>
              </select>
              <p class="description"><?php esc_html_e( 'Defines the active window used by annual packets. Incomplete employees remain In Progress until the window closes.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'ISP training delivery', 'ghca-acd' ); ?></th>
            <td>
              <input type="hidden" name="<?php echo esc_attr( self::OPTION_ISP_MANUAL_DELIVERY ); ?>" value="0" />
              <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_ISP_MANUAL_DELIVERY ); ?>" value="1" <?php checked( in_array( get_option( self::OPTION_ISP_MANUAL_DELIVERY, 0 ), array( 1, '1' ), true ) ); ?> />
                <?php esc_html_e( 'ISP implementation is handled manually / in person by this agency', 'ghca-acd' ); ?>
              </label>
                <p class="description"><?php esc_html_e( 'Adds the delivery label and agency-records note. To count hours, confirm the employee and reporting period in Dashboard → Employee drawer → Administration → Edit Records. Supporting records are supplied separately.', 'ghca-acd' ); ?></p>
              </td>
            </tr>
            <?php GHCA_Agency_Training::settings_fields(); ?>
          <tr>
            <th scope="row"><?php esc_html_e( 'Compliance packet course details', 'ghca-acd' ); ?></th>
            <td>
              <input type="hidden" name="<?php echo esc_attr( self::OPTION_PACKET_COURSE_DETAILS ); ?>" value="0" />
              <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_PACKET_COURSE_DETAILS ); ?>" value="1" <?php checked( $packet_details ); ?> />
                <?php esc_html_e( 'Include course details in compliance packets', 'ghca-acd' ); ?>
              </label>
              <p class="description"><?php esc_html_e( 'Adds each included course, its LearnDash short description, completion date, and achieved credit hours to annual and orientation PDFs.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
        </table>

        </details>
        <details class="ghca-settings-section" id="ghca-branding"><summary><?php esc_html_e( 'Agency branding', 'ghca-acd' ); ?></summary>
        <p><?php esc_html_e( 'Shared across the employee and admin compliance dashboards. Semantic alert colors (overdue, warning, success) stay fixed for accessibility.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_brand_primary"><?php esc_html_e( 'Primary color', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_primary_picker" value="<?php echo esc_attr( $brand['primary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_primary" name="<?php echo esc_attr( $option_name ); ?>[primary]" value="<?php echo esc_attr( $brand['primary'] ); ?>" placeholder="<?php echo esc_attr( $brand_defaults['primary'] ); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_secondary"><?php esc_html_e( 'Secondary color', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_secondary_picker" value="<?php echo esc_attr( $brand['secondary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_secondary" name="<?php echo esc_attr( $option_name ); ?>[secondary]" value="<?php echo esc_attr( $brand['secondary'] ); ?>" placeholder="<?php echo esc_attr( $brand_defaults['secondary'] ); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_accent"><?php esc_html_e( 'Accent color (optional)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_accent_picker" value="<?php echo esc_attr( $accent_raw !== '' ? $accent_raw : $brand['primary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_accent" name="<?php echo esc_attr( $option_name ); ?>[accent]" value="<?php echo esc_attr( $accent_raw ); ?>" placeholder="<?php esc_attr_e( 'Uses primary if empty', 'ghca-acd' ); ?>" />
              <p class="description"><?php esc_html_e( 'Quick link and KPI icons use the primary theme color.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_org_name"><?php esc_html_e( 'Organization name', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_brand_org_name" name="<?php echo esc_attr( $option_name ); ?>[org_name]" value="<?php echo esc_attr( $brand['org_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
            </td>
          </tr>
          <tr>
					<th scope="row"><label for="ghca_brand_logo_url"><?php esc_html_e( 'Agency logo URL', 'ghca-acd' ); ?></label></th>
					<td>
						<input type="url" class="large-text code" id="ghca_brand_logo_url" name="<?php echo esc_attr( $option_name ); ?>[logo_url]" value="<?php echo esc_attr( $brand['logo_url'] ); ?>" placeholder="https://..." />
						<p class="description"><?php esc_html_e( 'Used in PDF audit packets and branded reminder emails. Use a public HTTPS image URL for email.', 'ghca-acd' ); ?></p>
              <?php if ( $brand['logo_url'] ) : ?>
                <p><img src="<?php echo esc_url( $brand['logo_url'] ); ?>" alt="" style="max-height:48px;width:auto;margin-top:8px;" /></p>
              <?php endif; ?>
              <p class="description"><?php esc_html_e( 'Used in generated compliance PDF packets only. It is not displayed in the dashboard header.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_support_email"><?php esc_html_e( 'Support email', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="email" class="regular-text" id="ghca_brand_support_email" name="<?php echo esc_attr( $option_name ); ?>[support_email]" value="<?php echo esc_attr( $brand['support_email'] ); ?>" />
            </td>
          </tr>
        </table>
        <div style="display:flex;gap:12px;margin:0 0 24px;">
          <span style="display:inline-block;padding:12px 18px;border-radius:8px;background:<?php echo esc_attr( $brand['primary'] ); ?>;color:#fff;font-weight:600;"><?php esc_html_e( 'Primary preview', 'ghca-acd' ); ?></span>
          <span style="display:inline-block;padding:12px 18px;border-radius:8px;background:<?php echo esc_attr( $brand['secondary'] ); ?>;color:#fff;font-weight:600;"><?php esc_html_e( 'Secondary preview', 'ghca-acd' ); ?></span>
        </div>
        <script>
          (function () {
            var pairs = [
              ['ghca_brand_primary_picker', 'ghca_brand_primary'],
              ['ghca_brand_secondary_picker', 'ghca_brand_secondary'],
              ['ghca_brand_accent_picker', 'ghca_brand_accent']
            ];
            pairs.forEach(function (pair) {
              var picker = document.getElementById(pair[0]);
              var input = document.getElementById(pair[1]);
              if (!picker || !input) return;
              picker.addEventListener('input', function () { input.value = picker.value; });
              input.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(input.value)) picker.value = input.value;
              });
            });
          })();
        </script>

        </details>
        <details class="ghca-settings-section" id="ghca-performance"><summary><?php esc_html_e( 'Performance & warning windows', 'ghca-acd' ); ?></summary>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_acd_at_risk_days"><?php esc_html_e( 'At-risk window (days)', 'ghca-acd' ); ?></label></th>
            <td><input type="number" min="7" max="120" id="ghca_acd_at_risk_days" name="<?php echo esc_attr( self::OPTION_AT_RISK_DAYS ); ?>" value="<?php echo esc_attr( (string) $at_risk ); ?>" class="small-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_acd_cache_ttl"><?php esc_html_e( 'Aggregate cache TTL (seconds)', 'ghca-acd' ); ?></label></th>
            <td><input type="number" min="0" max="3600" id="ghca_acd_cache_ttl" name="<?php echo esc_attr( self::OPTION_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) $cache ); ?>" class="small-text" /></td>
          </tr>
        </table>
        </details>
        <details class="ghca-settings-section" id="ghca-lifespans"><summary><?php esc_html_e( 'Course validity & expiration', 'ghca-acd' ); ?></summary>
        <p><?php esc_html_e( 'Define how long each course stays valid after completion. A completed course turns yellow inside the warning window and red once it passes its lifespan. Courses with no lifespan never expire.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_acd_warning_days"><?php esc_html_e( 'Warning window (days)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="number" min="7" max="365" id="ghca_acd_warning_days" name="<?php echo esc_attr( $warning_opt ); ?>" value="<?php echo esc_attr( (string) $warning_days ); ?>" class="small-text" />
              <p class="description"><?php esc_html_e( 'How many days before expiry a course is flagged "Expiring Soon". Default: 90.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Course lifespans', 'ghca-acd' ); ?></th>
            <td>
              <?php if ( empty( $all_courses ) ) : ?>
                <p><?php esc_html_e( 'No published LearnDash courses found.', 'ghca-acd' ); ?></p>
              <?php else : ?>
                <div id="ghca-lifespan-rows">
                  <?php foreach ( $lifespan_map as $cid => $days ) : ?>
                    <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                      <select class="ghca-lifespan-course">
                        <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                        <?php foreach ( $all_courses as $course ) : ?>
                          <option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( (int) $cid, (int) $course->ID ); ?>><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                        <?php endforeach; ?>
                      </select>
                      <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" value="<?php echo esc_attr( (string) $days ); ?>" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                      <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="ghca-lifespan-add"><?php esc_html_e( '+ Add course lifespan', 'ghca-acd' ); ?></button></p>
                <p class="description"><?php esc_html_e( 'Example: CPR = 730 days, HIPAA = 365 days. Rows with no course or 0 days are ignored on save.', 'ghca-acd' ); ?></p>

                <template id="ghca-lifespan-template">
                  <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                    <select class="ghca-lifespan-course">
                      <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                      <?php foreach ( $all_courses as $course ) : ?>
                        <option value="<?php echo esc_attr( (string) $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                    <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                  </div>
                </template>

                <input type="hidden" id="ghca-lifespan-name-base" value="<?php echo esc_attr( $lifespan_opt ); ?>" />
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <script>
          (function () {
            var rows = document.getElementById('ghca-lifespan-rows');
            if (!rows) return;
            var base = document.getElementById('ghca-lifespan-name-base').value;
            var tpl = document.getElementById('ghca-lifespan-template');
            var addBtn = document.getElementById('ghca-lifespan-add');
            var form = rows.closest('form');

            function wire(row) {
              row.querySelector('.ghca-lifespan-remove').addEventListener('click', function () { row.remove(); });
            }
            Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), wire);

            addBtn.addEventListener('click', function () {
              var clone = tpl.content.firstElementChild.cloneNode(true);
              rows.appendChild(clone);
              wire(clone);
            });

            // On submit, materialize each row into name="base[courseId]" = days.
            form.addEventListener('submit', function () {
              Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), function (row) {
                var cid = parseInt(row.querySelector('.ghca-lifespan-course').value, 10);
                var days = parseInt(row.querySelector('.ghca-lifespan-days').value, 10);
                if (!cid || !days || days < 1) return;
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = base + '[' + cid + ']';
                hidden.value = days;
                form.appendChild(hidden);
              });
            });
          })();
        </script>

        </details>
        <?php submit_button(); ?>
      </form>
      <p><?php esc_html_e( 'Dashboard URL:', 'ghca-acd' ); ?> <code><?php echo esc_html( GHCA_ACD_Nav::get_dashboard_url() ); ?></code></p>
      <?php GHCA_Packet_Course_Selection::render(); ?>
    </div>
    <?php
  }

  public static function render_permissions_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $edit_records  = get_option( self::OPTION_PERM_EDIT_RECORDS, '' );
    $manage_ann    = get_option( self::OPTION_PERM_MANAGE_ANNOUNCEMENTS, '' );
    $unrestricted  = get_option( self::OPTION_PERM_UNRESTRICTED_VIEW, '' );
    $manage_users  = get_option( self::OPTION_PERM_MANAGE_USERS, '' );
    $send_reminders = get_option( self::OPTION_PERM_SEND_REMINDERS, '' );
    $view_comms     = get_option( self::OPTION_PERM_VIEW_COMMUNICATIONS, '' );
    $manage_templates = get_option( self::OPTION_PERM_MANAGE_TEMPLATES, '' );
    $view_documents = get_option( self::OPTION_PERM_VIEW_DOCUMENTS, '' );
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Compliance Permissions', 'ghca-acd' ); ?></h1>
      <p><?php esc_html_e( 'Enter a comma-separated list of User IDs to grant specific dashboard overrides. These apply on top of standard role limits.', 'ghca-acd' ); ?></p>
      
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_acd_permissions' ); ?>
        
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_perm_edit_records"><?php esc_html_e( 'Edit Training Records', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_edit_records" name="<?php echo esc_attr( self::OPTION_PERM_EDIT_RECORDS ); ?>" value="<?php echo esc_attr( (string) $edit_records ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to manually alter course completion dates and timers.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_manage_ann"><?php esc_html_e( 'Manage Announcements', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_manage_ann" name="<?php echo esc_attr( self::OPTION_PERM_MANAGE_ANNOUNCEMENTS ); ?>" value="<?php echo esc_attr( (string) $manage_ann ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to create, edit, or delete global compliance dashboard announcements.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_unrestricted"><?php esc_html_e( 'Unrestricted View', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_unrestricted" name="<?php echo esc_attr( self::OPTION_PERM_UNRESTRICTED_VIEW ); ?>" value="<?php echo esc_attr( (string) $unrestricted ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to see all employees company-wide, overriding LearnDash group constraints.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_manage_users"><?php esc_html_e( 'Manage Users', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_manage_users" name="<?php echo esc_attr( self::OPTION_PERM_MANAGE_USERS ); ?>" value="<?php echo esc_attr( (string) $manage_users ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to add and edit employees from the frontend User Management panel.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_send_reminders"><?php esc_html_e( 'Send Employee Reminders', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_send_reminders" name="<?php echo esc_attr( self::OPTION_PERM_SEND_REMINDERS ); ?>" value="<?php echo esc_attr( (string) $send_reminders ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to send reminders to employees inside their existing dashboard scope.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_view_comms"><?php esc_html_e( 'View Communication History', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_view_comms" name="<?php echo esc_attr( self::OPTION_PERM_VIEW_COMMUNICATIONS ); ?>" value="<?php echo esc_attr( (string) $view_comms ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to view reminder history for employees inside their existing dashboard scope.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_manage_templates"><?php esc_html_e( 'Manage Reminder Templates', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_manage_templates" name="<?php echo esc_attr( self::OPTION_PERM_MANAGE_TEMPLATES ); ?>" value="<?php echo esc_attr( (string) $manage_templates ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to manage reusable reminder templates. WordPress administrators always retain access.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_view_documents"><?php esc_html_e( 'View Employee Documents', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_view_documents" name="<?php echo esc_attr( self::OPTION_PERM_VIEW_DOCUMENTS ); ?>" value="<?php echo esc_attr( (string) $view_documents ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to list, preview and download Jotform documents for employees inside their existing dashboard scope. Administrators and Compliance Leads always retain access.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
        </table>

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }
}
