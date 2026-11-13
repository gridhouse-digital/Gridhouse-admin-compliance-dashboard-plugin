<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redesigned settings screen (design boards "WP admin settings": WP-Settings-*.dc.html), behind the redesign switch.
 *
 * Presentation only, like GHCA_Settings_Console which it replaces while the redesign is on. Every plugin admin page
 * gets the board's shell: eyebrow, "Settings", the grouped section list (Compliance rules, Agency, Messaging,
 * Integrations, System), a section picker on narrow screens, and the design-system cards and fields. The main
 * Settings page shows one section at a time (Training rules, Course validity & packets, Branding, Employment types
 * & roles, Performance). An unsaved-changes bar and the save result (saved / not saved) follow the TrainingDirty
 * and TrainingError boards. Every form, field name, nonce, capability check and save handler is the existing one.
 * With the redesign off, nothing here runs.
 */
final class GHCA_ACD_Redesign_Settings {
	const MAIN = 'ghca-acd-settings';

	public static function init(): void {
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'in_admin_header', array( __CLASS__, 'shell' ), 5 );
	}

	/** The current plugin admin page when the redesign applies to it, '' otherwise. */
	public static function page(): string {
		if ( ! is_admin() || ! class_exists( 'GHCA_ACD_Settings' ) || ! GHCA_ACD_Settings::ui_redesign_enabled() ) {
			return '';
		}
		$raw  = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = 'ghca-acd' === $raw ? self::MAIN : $raw;
		foreach ( self::sections() as $items ) {
			foreach ( $items as $item ) {
				if ( $item['page'] === $page && self::can( $item ) ) {
					return $page;
				}
			}
		}
		return '';
	}

	/**
	 * Board groups and sections. 'anchors': the existing blocks on the main Settings page that make up the section.
	 *
	 * @return array<string,array<string,array{label:string,page:string,anchors:array<int,string>}>>
	 */
	public static function sections(): array {
		$s = static function ( string $label, string $page, array $anchors = array() ): array {
			return array( 'label' => $label, 'page' => $page, 'anchors' => $anchors );
		};
		return array(
			__( 'Compliance rules', 'ghca-acd' ) => array(
				'training'      => $s( __( 'Training rules', 'ghca-acd' ), self::MAIN, array( 'ghca-training' ) ),
				'training-year' => $s( __( 'Training year (2.0)', 'ghca-acd' ), 'ghca-acd-training-year' ),
				'validity'      => $s( __( 'Course validity & packets', 'ghca-acd' ), self::MAIN, array( 'ghca-lifespans', 'ghca-packet-selection' ) ),
				'audit'         => $s( __( 'Audit mapping', 'ghca-acd' ), 'ghca-acd-audit-mapping' ),
				'oltl'          => $s( __( 'OLTL training', 'ghca-acd' ), 'ghca-acd-oltl-training' ),
			),
			__( 'Agency', 'ghca-acd' ) => array(
				'branding'    => $s( __( 'Branding', 'ghca-acd' ), self::MAIN, array( 'ghca-branding' ) ),
				'profile'     => $s( __( 'Agency profile', 'ghca-acd' ), 'ghca-acd-agency-profile' ),
				'people'      => $s( __( 'Employment types & roles', 'ghca-acd' ), self::MAIN, array( 'ghca-custom-options' ) ),
				'permissions' => $s( __( 'Permissions', 'ghca-acd' ), 'ghca-acd-permissions' ),
			),
			__( 'Messaging', 'ghca-acd' ) => array(
				'messaging' => $s( __( 'Email, SMS & templates', 'ghca-acd' ), 'ghca-acd-messaging' ),
			),
			__( 'Integrations', 'ghca-acd' ) => array(
				'jotform'  => $s( __( 'Jotform & evidence', 'ghca-acd' ), 'ghca-acd-jotform' ),
				'catalog'  => $s( __( 'External training catalog', 'ghca-acd' ), 'ghca-acd-external-catalog' ),
				'external' => $s( __( 'External training review', 'ghca-acd' ), 'ghca-acd-external-training' ),
			),
			__( 'System', 'ghca-acd' ) => array(
				'performance' => $s( __( 'Performance', 'ghca-acd' ), self::MAIN, array( 'ghca-performance' ) ),
			),
		);
	}

	/** Same access rule as the page itself: its menu capability, and the messaging roles for Messaging. */
	private static function can( array $item ): bool {
		if ( 'ghca-acd-messaging' === $item['page'] ) {
			return GHCA_ACD_Roles::user_can_manage_messaging_settings() || GHCA_ACD_Roles::user_can_manage_message_templates();
		}
		global $submenu;
		foreach ( (array) ( $submenu[ GHCA_ACD_Admin_Menu::SLUG ] ?? array() ) as $entry ) {
			if ( $entry[2] === $item['page'] ) {
				return current_user_can( $entry[1] );
			}
		}
		return current_user_can( 'manage_options' );
	}

	/** The section being shown: ?section= on the main Settings page, otherwise the section that owns the page. */
	public static function current(): string {
		$page = self::page();
		$want = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$first = '';
		foreach ( self::sections() as $items ) {
			foreach ( $items as $key => $item ) {
				if ( $item['page'] !== $page ) {
					continue;
				}
				if ( '' === $first ) {
					$first = $key;
				}
				if ( $key === $want ) {
					return $key;
				}
			}
		}
		return $first;
	}

	public static function url( string $key, array $item ): string {
		$url = GHCA_ACD_Admin_Menu::url( $item['page'] );
		return self::MAIN === $item['page'] ? add_query_arg( 'section', $key, $url ) : $url;
	}

	public static function body_class( string $classes ): string {
		return self::page() ? $classes . ' ghca-set-rd' : $classes;
	}

	public static function enqueue(): void {
		if ( ! self::page() ) {
			return;
		}
		$root    = dirname( __DIR__ );
		$url     = plugin_dir_url( $root . '/gridhouse-admin-compliance-dashboard.php' );
		$version = GHCA_Admin_Compliance_Dashboard::VERSION;
		wp_enqueue_style( 'ghca-redesign', $url . 'assets/gh-redesign.css', array(), $version . '-' . (string) @filemtime( $root . '/assets/gh-redesign.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_style( 'ghca-settings-redesign', $url . 'assets/settings-redesign.css', array( 'ghca-redesign' ), $version . '-' . (string) @filemtime( $root . '/assets/settings-redesign.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_script( 'ghca-settings-redesign', $url . 'assets/settings-redesign.js', array(), $version . '-' . (string) @filemtime( $root . '/assets/settings-redesign.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** The shell: printed before the page body; the page's own .wrap is moved into it by settings-redesign.js. */
	public static function shell(): void {
		$page = self::page();
		if ( ! $page ) {
			return;
		}
		$current = self::current();
		$label   = '';
		$anchors = array();
		$nav     = '';
		$options = '';
		foreach ( self::sections() as $group => $items ) {
			$links = '';
			foreach ( $items as $key => $item ) {
				if ( ! self::can( $item ) ) {
					continue;
				}
				$on = $key === $current;
				if ( $on ) {
					$label   = $item['label'];
					$anchors = $item['anchors'];
				}
				$href     = self::url( $key, $item );
				$links   .= '<a class="gh-menu-i ghca-set-nav-i" href="' . esc_url( $href ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '><span>' . esc_html( $item['label'] ) . '</span></a>';
				$options .= '<option value="' . esc_url( $href ) . '"' . selected( $on, true, false ) . '>' . esc_html( $group . ' — ' . $item['label'] ) . '</option>';
			}
			if ( '' !== $links ) {
				$nav .= '<div class="ghca-set-nav-g"><span class="gh-eyebrow">' . esc_html( $group ) . '</span>' . $links . '</div>';
			}
		}

		// Sections that share this page's form, so an invalid field in a hidden one can be shown (settings-redesign.js).
		$groups = array();
		foreach ( self::sections() as $items ) {
			foreach ( $items as $key => $item ) {
				if ( $item['page'] === $page && $item['anchors'] && self::can( $item ) ) {
					$groups[ $key ] = array( 'label' => $item['label'], 'anchors' => $item['anchors'], 'url' => self::url( $key, $item ) );
				}
			}
		}
		$h  = '<div class="ghca-ui ghca-set-shell" id="ghca-set-shell" data-ghca-set-section="' . esc_attr( $current ) . '" data-ghca-set-anchors="' . esc_attr( implode( ' ', $anchors ) ) . '" data-ghca-set-label="' . esc_attr( $label ) . '" data-ghca-set-groups="' . esc_attr( (string) wp_json_encode( $groups ) ) . '">';
		$h .= '<div class="gh-app" data-theme="' . esc_attr( GHCA_ACD_Settings::ui_theme() ) . '">';
		$h .= '<div class="ghca-set-head"><span class="gh-eyebrow">' . esc_html__( 'Gridhouse Compliance · GHCA ACD', 'ghca-acd' ) . '</span><h1 class="gh-h1">' . esc_html__( 'Settings', 'ghca-acd' ) . '</h1>';
		$h .= '<p class="gh-p gh-secondary gh-small">' . esc_html( sprintf( /* translators: %s: agency name */ __( 'Agency configuration for %s.', 'ghca-acd' ), wp_specialchars_decode( GHCA_Dashboard_Branding::get_org_name(), ENT_QUOTES ) ) ) . '</p></div>';
		$h .= '<div class="ghca-set-split"><nav class="ghca-set-nav" aria-label="' . esc_attr__( 'Settings sections', 'ghca-acd' ) . '">' . $nav . '</nav>';
		$h .= '<div class="ghca-set-main" data-ghca-set-main>';
		$h .= '<div class="gh-field ghca-set-pick"><label class="gh-label" for="ghca-set-pick">' . esc_html__( 'Section', 'ghca-acd' ) . '</label><select id="ghca-set-pick" class="gh-select" data-ghca-set-pick>' . $options . '</select></div>';
		$h .= self::notices_html();
		$h .= '<div class="gh-alert gh-alert--danger" role="alert" data-ghca-set-invalid hidden><div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Settings not saved', 'ghca-acd' ) . '</span><p data-ghca-set-invalid-msg></p></div></div>';
		$h .= '<h2 class="gh-h2 ghca-set-title">' . esc_html( $label ) . '</h2>';
		$h .= '<div data-ghca-set-body></div>';
		$h .= '</div></div>';
		// Unsaved-changes bar (board "State · unsaved changes"); shown by settings-redesign.js on the first edit.
		$h .= '<div class="ghca-set-dirty" data-ghca-set-dirty role="region" aria-label="' . esc_attr__( 'Unsaved changes', 'ghca-acd' ) . '" hidden><span class="gh-strong">' . esc_html( sprintf( /* translators: %s: section */ __( 'Unsaved changes in %s', 'ghca-acd' ), $label ) ) . '</span><span class="gh-actions"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-set-discard>' . esc_html__( 'Discard', 'ghca-acd' ) . '</button><button type="button" class="gh-btn gh-btn--primary" data-ghca-set-save>' . esc_html__( 'Save changes', 'ghca-acd' ) . '</button></span></div>';
		$h .= '</div></div>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	/** The result of the last options.php save on this page (board "State · save failed"). */
	private static function notices_html(): string {
		if ( empty( $_GET['settings-updated'] ) || ! function_exists( 'get_settings_errors' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$errors = array();
		foreach ( (array) get_settings_errors() as $e ) {
			if ( 'error' === ( $e['type'] ?? '' ) ) {
				$errors[] = (string) $e['message'];
			}
		}
		if ( $errors ) {
			$items = '';
			foreach ( $errors as $message ) {
				$items .= '<li>' . esc_html( wp_strip_all_tags( $message ) ) . '</li>';
			}
			return '<div class="gh-alert gh-alert--danger" role="alert" data-ghca-set-result="error"><div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Settings not saved', 'ghca-acd' ) . '</span><ul>' . $items . '</ul></div></div>';
		}
		return '<div class="gh-alert gh-alert--success" role="status" data-ghca-set-result="saved"><div class="gh-alert-body"><span class="gh-alert-t">' . esc_html__( 'Settings saved', 'ghca-acd' ) . '</span></div></div>';
	}
}
