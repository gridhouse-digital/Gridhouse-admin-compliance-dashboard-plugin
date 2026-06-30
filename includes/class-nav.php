<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Nav {
  public static function init(): void {
    add_filter( 'wp_nav_menu_items', array( __CLASS__, 'inject_menu_items' ), 20, 2 );
    add_filter( 'bp_setup_nav', array( __CLASS__, 'register_buddyboss_nav' ), 100 );
  }

  public static function get_dashboard_url(): string {
    $page = get_page_by_path( 'compliance-admin-dashboard' );
    return $page ? get_permalink( $page ) : home_url( '/compliance-admin-dashboard/' );
  }

  /** @param string $items @param \stdClass $args */
  public static function inject_menu_items( string $items, $args ): string {
    if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return $items;
    }

    $locations = array( 'header-menu', 'buddypanel-loggedin', 'mobile-menu-logged-in', 'header-my-account' );
    $location  = is_object( $args ) && isset( $args->theme_location ) ? (string) $args->theme_location : '';

    if ( ! in_array( $location, $locations, true ) ) {
      return $items;
    }

    $label = __( 'Compliance Admin', 'ghca-acd' );
    $url   = esc_url( self::get_dashboard_url() );
    $item  = '<li class="menu-item menu-item-ghca-acd"><a href="' . $url . '">' . esc_html( $label ) . '</a></li>';

    if ( 'header-my-account' === $location ) {
      return $item . $items;
    }

    return $items . $item;
  }

  public static function register_buddyboss_nav(): void {
    if ( ! function_exists( 'bp_core_new_nav_item' ) || ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
      return;
    }

    bp_core_new_nav_item(
      array(
        'name'                    => __( 'Compliance Admin', 'ghca-acd' ),
        'slug'                    => 'compliance-admin',
        'default_subnav_slug'     => 'overview',
        'position'                => 25,
        'screen_function'         => array( __CLASS__, 'render_buddyboss_screen' ),
        'show_for_displayed_user' => false,
        'user_has_access'         => GHCA_ACD_Roles::user_can_view(),
      )
    );
  }

  public static function render_buddyboss_screen(): void {
    add_action( 'bp_template_content', array( __CLASS__, 'render_buddyboss_content' ) );
    bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
  }

  public static function render_buddyboss_content(): void {
    echo do_shortcode( '[admin_compliance_dashboard]' );
  }
}
