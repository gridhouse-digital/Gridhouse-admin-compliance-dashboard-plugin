<?php
/**
 * Single wp-admin home for every plugin settings screen.
 *
 * The screens previously registered themselves under Settings and Tools, which scattered
 * eight related pages across two unrelated menus. They now register as sub-pages of one
 * top-level menu. Page slugs are unchanged, so only the parent file moves: every screen
 * that used to live at options-general.php or tools.php is now under admin.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Admin_Menu {
	const SLUG = 'ghca-acd';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'tidy' ), 100 );
		/*
		 * Deliberately 'init' rather than 'admin_init'. wp-admin/admin.php loads
		 * wp-admin/menu.php, which refuses an unregistered parent/page pair, before it
		 * fires admin_init, so a legacy bookmark would die with a permission error
		 * before an admin_init handler ever ran.
		 */
		add_action( 'init', array( __CLASS__, 'redirect_legacy_url' ) );
	}

	/** Every slug this menu owns. Used by the legacy-bookmark redirect. */
	public static function pages(): array {
		return array(
			'ghca-acd-settings',
			'ghca-acd-permissions',
			'ghca-acd-messaging',
			'ghca-acd-audit-mapping',
			'ghca-acd-jotform',
			'ghca-acd-external-training',
			'ghca-acd-external-catalog',
			'ghca-acd-oltl-training',
		);
	}

	/**
	 * Admin URL for one of this menu's pages. Every caller must use this rather than
	 * building a parent file by hand, so the parent can move again without hunting URLs.
	 *
	 * @param array<string,string> $args Extra query arguments.
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The parent must not be more restrictive than its most permissive child, or that
	 * child becomes unreachable. Messaging is available to users who cannot manage
	 * options, so they still get the menu; everyone else needs the administrator
	 * capability, which keeps the menu hidden from employees entirely.
	 */
	private static function capability(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return 'manage_options';
		}
		if ( class_exists( 'GHCA_ACD_Roles' ) && ( GHCA_ACD_Roles::user_can_manage_messaging_settings() || GHCA_ACD_Roles::user_can_manage_message_templates() ) ) {
			return 'read';
		}
		return 'manage_options';
	}

	public static function register(): void {
		add_menu_page(
			__( 'Gridhouse Compliance', 'ghca-acd' ),
			__( 'Gridhouse Compliance', 'ghca-acd' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-clipboard',
			58
		);
	}

	/**
	 * Drops the duplicate sub-page WordPress creates for the parent itself. With it gone,
	 * the top-level item links to the first sub-page the current user can actually open.
	 */
	public static function tidy(): void {
		remove_submenu_page( self::SLUG, self::SLUG );
	}

	/** Only reachable if a user opens the parent slug directly. */
	public static function render(): void {
		if ( current_user_can( 'manage_options' ) && class_exists( 'GHCA_ACD_Settings' ) ) {
			GHCA_ACD_Settings::render_page();
			return;
		}
		wp_die( esc_html__( 'Choose a Gridhouse Compliance page from the menu.', 'ghca-acd' ) );
	}

	/** Sends bookmarks saved before the move to the same page in its new home. */
	public static function redirect_legacy_url(): void {
		global $pagenow;
		if ( ! is_admin() || wp_doing_ajax() || ! in_array( (string) $pagenow, array( 'options-general.php', 'tools.php' ), true ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || ! in_array( $page, self::pages(), true ) ) {
			return;
		}
		wp_safe_redirect( self::url( $page ), 301 );
		exit;
	}
}
