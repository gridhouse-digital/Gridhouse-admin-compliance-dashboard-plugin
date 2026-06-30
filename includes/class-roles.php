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
}
