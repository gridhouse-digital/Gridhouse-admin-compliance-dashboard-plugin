<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin presentation only. Existing page callbacks and form handlers remain authoritative. */
final class GHCA_Settings_Console {
    public static function init(): void {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
        add_action( 'in_admin_header', array( __CLASS__, 'header' ) );
    }

    public static function groups(): array {
        return array(
            'Agency setup' => array( 'ghca-acd-agency-profile' => 'Agency Profile', 'ghca-acd-settings#ghca-branding' => 'Branding' ),
            'Training & evidence' => array( 'ghca-acd-settings' => 'Training settings', 'ghca-acd-settings#ghca-packet-selection' => 'Packet courses', 'ghca-acd-audit-mapping' => 'Audit Mapping', 'ghca-acd-external-catalog' => 'Training Catalog' ),
            'Access & communications' => array( 'ghca-acd-permissions' => 'Permissions', 'ghca-acd-messaging' => 'Messaging', 'ghca-acd-jotform' => 'Document integrations' ),
            'System & support' => array( 'ghca-acd-settings#ghca-performance' => 'Performance', 'ghca-acd-oltl-training' => 'OLTL Readiness' ),
            'Evidence workflow' => array( 'ghca-acd-external-training' => 'External Training Review' ),
        );
    }

    public static function page(): string {
        $raw = $_GET['page'] ?? '';
        if ( ! is_admin() || ! is_string( $raw ) ) { return ''; }
        $page = sanitize_key( wp_unslash( $raw ) );
        foreach ( self::groups() as $links ) {
            foreach ( $links as $key => $label ) {
                if ( explode( '#', $key )[0] !== $page && ! ( 'ghca-acd' === $page && 'ghca-acd-settings' === $key ) ) { continue; }
                if ( 'ghca-acd-messaging' === $page ) {
                    return ( GHCA_ACD_Roles::user_can_manage_messaging_settings() || GHCA_ACD_Roles::user_can_manage_message_templates() ) ? $page : '';
                }
                return current_user_can( 'manage_options' ) ? $page : '';
            }
        }
        return '';
    }

    public static function body_class( string $classes ): string {
        return self::page() ? $classes . ' ghca-settings-console' : $classes;
    }

    public static function enqueue(): void {
        if ( ! self::page() ) { return; }
        $root = dirname( __DIR__ );
        $url = plugin_dir_url( $root . '/gridhouse-admin-compliance-dashboard.php' );
        wp_enqueue_style( 'ghca-settings-console', $url . 'assets/settings-console.css', array(), GHCA_Admin_Compliance_Dashboard::VERSION . '.' . filemtime( $root . '/assets/settings-console.css' ) );
        wp_enqueue_script( 'ghca-settings-console', $url . 'assets/settings-console.js', array(), GHCA_Admin_Compliance_Dashboard::VERSION . '.' . filemtime( $root . '/assets/settings-console.js' ), true );
        global $_wp_admin_css_colors;
        $scheme = get_user_option( 'admin_color' ) ?: 'fresh';
        $accent = sanitize_hex_color( $_wp_admin_css_colors[$scheme]->colors[2] ?? '#2271b1' ) ?: '#2271b1';
        wp_add_inline_style( 'ghca-settings-console', '.ghca-settings-console{--ghca-console-accent:' . $accent . ';}' );
    }

    public static function header(): void {
        $page = self::page();
        if ( ! $page ) { return; }
        global $submenu;
        $allowed = array();
        foreach ( $submenu[GHCA_ACD_Admin_Menu::SLUG] ?? array() as $item ) {
            if ( current_user_can( $item[1] ) ) { $allowed[$item[2]] = true; }
        }
        echo '<header class="ghca-console-header"><div class="ghca-console-context"><div><p class="ghca-console-eyebrow">' . esc_html__( 'Gridhouse · Agency configuration', 'ghca-acd' ) . '</p><strong>' . esc_html( GHCA_Dashboard_Branding::get_org_name() ) . '</strong></div><span>' . esc_html__( 'Setup & technical support', 'ghca-acd' ) . '</span></div><nav aria-label="' . esc_attr__( 'Gridhouse configuration', 'ghca-acd' ) . '">';
        foreach ( self::groups() as $label => $links ) {
            $html = '';
            foreach ( $links as $key => $title ) {
                $parts = explode( '#', $key );
                if ( ! isset( $allowed[$parts[0]] ) ) { continue; }
                if ( 'ghca-acd-messaging' === $parts[0] && ! GHCA_ACD_Roles::user_can_manage_messaging_settings() && ! GHCA_ACD_Roles::user_can_manage_message_templates() ) { continue; }
                $url = GHCA_ACD_Admin_Menu::url( $parts[0] ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
                $current = $parts[0] === $page && ! isset( $parts[1] );
                $html .= '<a href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a>';
            }
            if ( $html ) { echo '<div class="ghca-console-group"><span>' . esc_html( $label ) . '</span>' . $html . '</div>'; }
        }
        echo '</nav></header>';
    }
}
