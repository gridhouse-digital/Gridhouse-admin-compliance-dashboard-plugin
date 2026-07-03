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
      if ( get_role( $slug ) ) {
        continue;
      }

      add_role(
        $slug,
        $label,
        array(
          'read'                   => true,
          self::CAP                => true,
          'edit_users'             => false,
          'list_users'             => true,
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
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_EDIT_RECORDS );
  }

  public static function user_can_manage_announcements(): bool {
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }
    return self::user_can_view() && self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_MANAGE_ANNOUNCEMENTS );
  }

  public static function user_has_unrestricted_view(): bool {
    if ( current_user_can( 'manage_options' ) ) {
      return true;
    }
    return self::user_in_setting_list( GHCA_ACD_Settings::OPTION_PERM_UNRESTRICTED_VIEW );
  }
}
