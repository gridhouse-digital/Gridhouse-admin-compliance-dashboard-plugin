<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Roles {
  const CAP = 'view_compliance_admin_dashboard';

  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'register_roles' ), 5 );
    add_filter( 'ghca_admin_dashboard_roles', array( __CLASS__, 'filter_allowed_roles' ) );
  }

  public static function register_roles(): void {
    $roles = array(
      'hr_manager'       => __( 'HR Manager', 'ghca-acd' ),
      'compliance_lead'  => __( 'Compliance Lead', 'ghca-acd' ),
      'training_manager' => __( 'Training Manager', 'ghca-acd' ),
    );

    foreach ( $roles as $slug => $label ) {
      $existing_role = get_role( $slug );
      if ( $existing_role ) {
        foreach ( array( 'list_users', 'edit_users', 'promote_users', 'delete_users', 'create_users', 'remove_users' ) as $capability ) {
          $existing_role->remove_cap( $capability );
        }
        continue;
      }

      add_role(
        $slug,
        $label,
        array(
          'read'    => true,
          self::CAP => true,
        )
      );
    }

    foreach ( array( 'administrator', 'group_leader', 'editor', 'ld_instructor' ) as $role_slug ) {
      $role = get_role( $role_slug );
      if ( $role ) {
        $role->add_cap( self::CAP );
      }
    }
  }

  /** @param array<int,string> $roles */
  public static function filter_allowed_roles( array $roles ): array {
    return array_values(
      array_unique(
        array_merge(
          $roles,
          array( 'hr_manager', 'compliance_lead', 'training_manager' )
        )
      )
    );
  }

  public static function user_can_view(): bool {
    if ( current_user_can( 'manage_options' ) || current_user_can( 'edit_users' ) || current_user_can( self::CAP ) ) {
      return true;
    }

    $allowed = apply_filters(
      'ghca_admin_dashboard_roles',
      array( 'administrator', 'group_leader', 'editor', 'ld_instructor', 'hr_manager', 'compliance_lead', 'training_manager' )
    );

    return (bool) array_intersect( $allowed, (array) wp_get_current_user()->roles );
  }

  private static $setting_cache = array();

  public static function user_has_full_dashboard_access(): bool {
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }

    return in_array( 'compliance_lead', (array) wp_get_current_user()->roles, true );
  }

  /**
   * Helper to check if a specific user ID is in a comma-separated setting.
   */
  private static function user_in_setting_list( string $option_name ): bool {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
      return false;
    }

    if ( ! isset( self::$setting_cache[ $option_name ] ) ) {
      $setting = get_option( $option_name, '' );
      if ( empty( $setting ) ) {
        self::$setting_cache[ $option_name ] = array();
      } else {
        $ids = array();
        foreach ( explode( ',', $setting ) as $token ) {
          $token = trim( $token );
          if ( '' !== $token && ctype_digit( $token ) ) {
            $ids[] = (int) $token;
          }
        }
        self::$setting_cache[ $option_name ] = $ids;
      }
    }

    return in_array( $user_id, self::$setting_cache[ $option_name ], true );
  }

  public static function user_can_edit_records(): bool {
    if ( self::user_has_full_dashboard_access() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_EDIT_RECORDS );
  }

  public static function user_can_manage_announcements(): bool {
    if ( self::user_has_full_dashboard_access() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_MANAGE_ANNOUNCEMENTS );
  }

  public static function user_can_manage_users(): bool {
    if ( self::user_is_agency_employee_manager() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_MANAGE_USERS );
  }

  public static function user_can_send_reminders(): bool {
    if ( self::user_has_full_dashboard_access() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_SEND_REMINDERS );
  }

  public static function user_can_view_communication_history(): bool {
    if ( self::user_has_full_dashboard_access() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_VIEW_COMMUNICATIONS );
  }

  public static function user_can_manage_message_templates(): bool {
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_MANAGE_TEMPLATES );
  }

  public static function user_can_manage_messaging_settings(): bool {
    return current_user_can( 'manage_options' );
  }

  public static function user_can_view_employee_documents(): bool {
    if ( self::user_has_full_dashboard_access() ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_VIEW_DOCUMENTS );
  }

  public static function user_can_review_external_training(): bool {
    return self::user_has_full_dashboard_access();
  }

  public static function user_can_manage_oltl_training(): bool {
    return self::user_has_full_dashboard_access();
  }

  public static function delegated_role_is_allowed( string $role ): bool {
    if ( '' === $role || ! preg_match( '/^[a-z0-9_-]+$/D', $role ) || in_array( $role, self::get_protected_role_slugs(), true ) ) {
      return false;
    }

    if ( ! function_exists( 'get_role' ) ) {
      return false;
    }

    $role_object = get_role( $role );
    if ( ! $role_object ) {
      return false;
    }

    return self::delegated_capabilities_are_allowed( (array) $role_object->capabilities );
  }

  /** @param array<string,mixed> $capabilities */
  private static function delegated_capabilities_are_allowed( array $capabilities, array $role_assignments = array() ): bool {
    $role_assignments = array_map( 'strval', $role_assignments );
    foreach ( $capabilities as $capability => $granted ) {
      $capability = (string) $capability;
      if ( $granted && ! in_array( $capability, $role_assignments, true ) && self::delegated_capability_is_protected( $capability ) ) {
        return false;
      }
    }

    return true;
  }

  /** @return array<int,string> */
  private static function get_protected_role_slugs(): array {
    return array(
      'administrator',
      'editor',
      'author',
      'contributor',
      'group_leader',
      'ld_instructor',
      'shop_manager',
      'bbp_keymaster',
      'bbp_moderator',
      'hr_manager',
      'compliance_lead',
      'training_manager',
    );
  }

  private static function delegated_capability_is_protected( string $capability ): bool {
    if ( preg_match( '/^level_(?:[1-9]|10)$/D', $capability ) ) {
      return true;
    }

    return in_array(
      $capability,
      array(
        self::CAP,
        'manage_options',
        'list_users',
        'edit_users',
        'create_users',
        'delete_users',
        'promote_users',
        'remove_users',
        'edit_plugins',
        'activate_plugins',
        'install_plugins',
        'update_plugins',
        'delete_plugins',
        'edit_themes',
        'switch_themes',
        'install_themes',
        'update_themes',
        'delete_themes',
        'update_core',
        'manage_network',
        'manage_network_users',
        'manage_network_plugins',
        'manage_network_themes',
        'manage_network_options',
        'edit_others_posts',
        'delete_others_posts',
        'publish_posts',
        'edit_others_pages',
        'delete_others_pages',
        'publish_pages',
        'moderate_comments',
        'manage_categories',
        'unfiltered_html',
        'bp_moderate',
        'manage_woocommerce',
      ),
      true
    );
  }

  private static function user_is_agency_employee_manager(): bool {
    return self::user_has_full_dashboard_access() || in_array( 'hr_manager', (array) wp_get_current_user()->roles, true );
  }

  /** @param array<int,string> $target_roles */
  public static function delegated_target_is_allowed( int $actor_id, int $target_id, array $target_roles, bool $in_scope, array $target_capabilities ): bool {
    if ( $actor_id <= 0 || $target_id <= 0 || $actor_id === $target_id || ! $in_scope || empty( $target_roles ) ) {
      return false;
    }

    foreach ( $target_roles as $target_role ) {
      if ( ! self::delegated_role_is_allowed( (string) $target_role ) ) {
        return false;
      }
    }

    return self::delegated_capabilities_are_allowed( $target_capabilities, $target_roles );
  }

  public static function delegated_target_is_in_scope( int $target_id, ?array $employee_ids = null ): bool {
    $employee_ids = null === $employee_ids ? GHCA_ACD_Data_Provider::get_employee_user_ids() : $employee_ids;
    if ( $target_id <= 0 || ! in_array( $target_id, $employee_ids, true ) ) {
      return false;
    }

    return self::user_is_agency_employee_manager() || GHCA_ACD_User_Report::can_view_user( $target_id );
  }

  /** @param array<int,string> $existing_roles */
  public static function role_update_is_needed( string $requested_role, array $existing_roles ): bool {
    return ! in_array( $requested_role, array_map( 'strval', $existing_roles ), true );
  }

  /** @return array<string,array<string,mixed>> */
  public static function get_manageable_employee_roles(): array {
    $editable_roles = apply_filters( 'editable_roles', wp_roles()->roles );
    if ( current_user_can( 'manage_options' ) ) {
      return $editable_roles;
    }

    $manageable_roles = array();
    foreach ( $editable_roles as $role_slug => $role_details ) {
      if ( self::delegated_role_is_allowed( (string) $role_slug ) ) {
        $manageable_roles[ $role_slug ] = $role_details;
      }
    }

    return $manageable_roles;
  }

  public static function user_has_unrestricted_view(): bool {
    if ( self::user_is_agency_employee_manager() ) {
      return true;
    }
    return self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_UNRESTRICTED_VIEW );
  }
}
