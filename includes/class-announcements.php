<?php
/**
 * Announcements manager for the compliance dashboard.
 *
 * Owns the ghca-announce custom post type, the announcements panel and its
 * add/edit modal, the announcement AJAX endpoints, and the (currently
 * disabled) BuddyBoss/BuddyPress notification bridge. Extracted from
 * GHCA_Admin_Compliance_Dashboard (Phase 6 core file refactor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Announcements {

  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'register_announce_cpt' ), 4 );
    add_action( 'wp_footer', array( __CLASS__, 'render_announcement_modal' ) );
    add_action( 'wp_ajax_ghca_acd_save_announcement', array( __CLASS__, 'ajax_save_announcement' ) );
    add_action( 'wp_ajax_ghca_acd_delete_announcement', array( __CLASS__, 'ajax_delete_announcement' ) );
    add_action( 'wp_ajax_ghca_acd_get_announcements', array( __CLASS__, 'ajax_get_announcements' ) );
    // add_action( 'ghca_acd_new_announcement_published', array( __CLASS__, 'process_buddyboss_notifications' ), 10, 3 );
    // add_filter( 'bp_notifications_get_notifications_for_user', array( __CLASS__, 'format_buddypress_notifications' ), 10, 5 );
  }

  /** Announcement type slugs supported by the dashboard. */
  private static function announcement_types(): array {
    return array( 'alert', 'reminder', 'update', 'system' );
  }

  /** Whether the current user may create/edit/delete announcements. */
  public static function can_manage_announcements(): bool {
    return GHCA_ACD_Roles::user_can_manage_announcements();
  }

  /** Registers the private CPT that stores admin-authored announcements. */
  public static function register_announce_cpt(): void {
    register_post_type(
      'ghca-announce',
      array(
        'label'           => __( 'Compliance Announcements', 'ghca-acd' ),
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'has_archive'     => false,
        'rewrite'         => false,
        'query_var'       => false,
        'supports'        => array( 'title', 'editor' ),
        'capabilities'    => array(
          'create_posts'           => 'do_not_allow',
          'edit_post'              => 'do_not_allow',
          'read_post'              => 'do_not_allow',
          'delete_post'            => 'do_not_allow',
          'edit_posts'             => 'do_not_allow',
          'edit_others_posts'      => 'do_not_allow',
          'publish_posts'          => 'do_not_allow',
          'read_private_posts'     => 'do_not_allow',
          'delete_posts'           => 'do_not_allow',
          'delete_private_posts'   => 'do_not_allow',
          'delete_published_posts' => 'do_not_allow',
          'delete_others_posts'    => 'do_not_allow',
          'edit_private_posts'     => 'do_not_allow',
          'edit_published_posts'   => 'do_not_allow',
        ),
        'map_meta_cap'    => false,
      )
    );

    register_post_meta(
      'ghca-announce',
      'ghca_announce_type',
      array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => array( __CLASS__, 'sanitize_announcement_type' ),
        'auth_callback'     => array( __CLASS__, 'can_manage_announcements' ),
      )
    );

    register_post_meta(
      'ghca-announce',
      'ghca_announce_url',
      array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'esc_url_raw',
        'auth_callback'     => array( __CLASS__, 'can_manage_announcements' ),
      )
    );
  }

  /** Normalises a submitted announcement type to a known slug. */
  public static function sanitize_announcement_type( $value ): string {
    $value = is_string( $value ) ? sanitize_key( $value ) : '';
    return in_array( $value, self::announcement_types(), true ) ? $value : 'update';
  }

  /** Who an announcement is for (slice 8, Modal-Announcement "Who sees it"). */
  public static function audience_options(): array {
    return array(
      'all'    => __( 'Employees and admins', 'ghca-acd' ),
      'admins' => __( 'Admins only', 'ghca-acd' ),
      'group'  => __( 'One group…', 'ghca-acd' ),
    );
  }

  public static function sanitize_audience( $value ): string {
    $value = is_string( $value ) ? sanitize_key( $value ) : '';
    return isset( self::audience_options()[ $value ] ) ? $value : 'all';
  }

  /** Whether the signed-in viewer may see a group-limited announcement: unscoped viewers see all; scoped ones their groups. */
  private static function viewer_sees_group( int $group_id ): bool {
    if ( $group_id <= 0 ) {
      return false;
    }
    if ( ! class_exists( 'GHCA_ACD_Scoping' ) || ! GHCA_ACD_Scoping::is_scoped_user() ) {
      return true;
    }
    return in_array( $group_id, array_map( 'intval', GHCA_ACD_Scoping::get_visible_group_ids() ), true );
  }

  /**
   * Whether the signed-in manager may change or delete this stored announcement. A group announcement belongs to
   * its group: a scoped manager may touch it only when that group is in their view, whatever audience they ask for.
   * Unscoped managers (global admins) may touch every announcement. Checked on every edit and delete, against the
   * stored values, before anything is written.
   */
  public static function can_touch_existing( int $announce_id ): bool {
    if ( $announce_id <= 0 || 'ghca-announce' !== get_post_type( $announce_id ) ) {
      return false;
    }
    $stored = self::sanitize_audience( (string) get_post_meta( $announce_id, 'ghca_announce_audience', true ) ?: 'all' );
    if ( 'group' !== $stored ) {
      return true;
    }
    return self::viewer_sees_group( (int) get_post_meta( $announce_id, 'ghca_announce_group', true ) );
  }

  private static function audience_label( string $audience, int $group_id ): string {
    if ( 'group' === $audience ) {
      /* translators: %s: LearnDash group name */
      return sprintf( __( 'Group: %s', 'ghca-acd' ), $group_id > 0 ? (string) get_the_title( $group_id ) : __( 'unknown', 'ghca-acd' ) );
    }
    return self::audience_options()[ $audience ] ?? '';
  }

  /** Type → icon glyph + badge label map for announcements. */
  private static function announcement_type_meta(): array {
    return array(
      'alert'    => array( 'icon' => 'alert', 'label' => __( 'Alert', 'ghca-acd' ) ),
      'reminder' => array( 'icon' => 'bell', 'label' => __( 'Reminder', 'ghca-acd' ) ),
      'update'   => array( 'icon' => 'megaphone', 'label' => __( 'Update', 'ghca-acd' ) ),
      'system'   => array( 'icon' => 'sync', 'label' => __( 'System', 'ghca-acd' ) ),
    );
  }

  public static function render_announcements( $atts = array() ): string {
    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      return '';
    }

    $can_manage = self::can_manage_announcements();

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--announcements">
      <div class="ghca-acd__panel ghca-acd__panel--flush">
        <div class="ghca-acd__section-head ghca-acd__section-head--bordered ghca-acd__announcement-head">
          <h2><?php esc_html_e( 'Announcements', 'ghca-acd' ); ?></h2>
          <?php if ( $can_manage ) : ?>
            <button type="button" class="ghca-acd__announcement-add" data-ghca-announce-add>
              <span aria-hidden="true">+</span> <?php esc_html_e( 'Add note', 'ghca-acd' ); ?>
            </button>
          <?php endif; ?>
        </div>
        <div class="ghca-acd__alert-list" id="ghca-acd-announcement-list" data-ghca-announce-list>
          <?php echo self::render_announcement_list_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** Announcements card for the redesigned overview (.gh-ann rows). */
  public static function render_announcements_v2(): string {
    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      return '';
    }
    $html  = '<div class="gh-card gh-ovp-ann" aria-labelledby="gh-ann-h"><div class="gh-card-hd gh-card-hd--plain"><div><h2 class="gh-h3" id="gh-ann-h">' . esc_html__( 'Announcements', 'ghca-acd' ) . '</h2>';
    $html .= '<span class="gh-small gh-muted">' . esc_html__( 'Notes for admins and staff', 'ghca-acd' ) . '</span></div>';
    if ( self::can_manage_announcements() ) {
      $html .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--sm" data-ghca-announce-add><span class="gh-i gh-i--sm i-plus" aria-hidden="true"></span>' . esc_html__( 'Add note', 'ghca-acd' ) . '</button>';
    }
    $html .= '</div><div class="gh-ovp-bd" id="ghca-acd-announcement-list" data-ghca-announce-list data-ghca-announce-format="v2" data-ghca-ovp-cap="3" data-ghca-ovp-items=".gh-ann" data-ghca-ovp-label="' . esc_attr__( 'Announcements, scrollable list', 'ghca-acd' ) . '">';
    $html .= self::render_announcement_list_html( 'v2' );
    return $html . '</div></div>';
  }

  /** Format requested by an AJAX re-render: v2 for the redesigned card, legacy otherwise. */
  private static function requested_format(): string {
    $format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
    return 'v2' === $format ? 'v2' : '';
  }

  private static function render_announcement_rows_v2( array $items ): string {
    if ( empty( $items ) ) {
      return '<p class="gh-ovp-empty">' . esc_html__( 'No announcements yet.', 'ghca-acd' ) . '</p>';
    }
    $type_meta = self::announcement_type_meta();
    $icons     = array( 'alert' => 'alert', 'reminder' => 'bell', 'update' => 'megaphone', 'system' => 'refresh' );
    $html      = '';
    foreach ( $items as $item ) {
      $type     = isset( $item['type'], $type_meta[ $item['type'] ] ) ? (string) $item['type'] : 'update';
      $editable = ! empty( $item['editable'] ) && (int) ( $item['id'] ?? 0 ) > 0;
      $html    .= '<div class="gh-ann"';
      if ( $editable ) {
        $html .= ' data-announce-id="' . esc_attr( (string) (int) $item['id'] ) . '" data-announce-title="' . esc_attr( (string) $item['label'] ) . '" data-announce-body="' . esc_attr( (string) ( $item['body'] ?? '' ) ) . '" data-announce-type="' . esc_attr( $type ) . '" data-announce-url="' . esc_attr( (string) ( $item['url'] ?? '' ) ) . '" data-announce-audience="' . esc_attr( (string) ( $item['audience'] ?? 'all' ) ) . '" data-announce-group="' . esc_attr( (string) (int) ( $item['audience_group'] ?? 0 ) ) . '"';
      }
      $html .= '><span class="gh-ann-ico gh-ann-ico--' . esc_attr( $type ) . '"><span class="gh-i i-' . esc_attr( $icons[ $type ] ) . '" aria-hidden="true"></span></span>';
      $html .= '<div class="gh-ann-main"><span class="gh-ann-t">' . esc_html( (string) $item['label'] ) . '</span>';
      if ( ! empty( $item['body'] ) ) {
        $html .= '<p class="gh-ann-m">' . esc_html( (string) $item['body'] ) . '</p>';
      }
      $html .= '<div class="gh-ann-meta"><span class="gh-badge gh-badge--neutral">' . esc_html( $type_meta[ $type ]['label'] ) . '</span>';
      if ( ! empty( $item['audience'] ) && 'all' !== $item['audience'] && ! empty( $item['audience_label'] ) ) {
        $html .= '<span class="gh-badge gh-badge--outline">' . esc_html( (string) $item['audience_label'] ) . '</span>';
      }
      if ( ! empty( $item['meta'] ) ) {
        $html .= '<span>' . esc_html( (string) $item['meta'] ) . '</span>';
      }
      if ( ! empty( $item['url'] ) ) {
        $html .= '<a class="gh-ann-link" href="' . esc_url( (string) $item['url'] ) . '">' . esc_html__( 'Take action', 'ghca-acd' ) . '<span class="gh-i gh-i--sm i-arrow-right" aria-hidden="true"></span></a>';
      }
      $html .= '</div></div>';
      if ( $editable ) {
        // Edit / delete from the ⋯ menu (Modal-Announcement board).
        $id    = (int) $item['id'];
        $menu  = 'gh-ann-menu-' . $id;
        $html .= '<span class="gh-ann-acts" data-ghca-ann-menu-wrap>';
        $html .= '<button type="button" class="gh-btn gh-btn--secondary gh-btn--icon gh-btn--sm" aria-haspopup="menu" aria-expanded="false" aria-controls="' . esc_attr( $menu ) . '" data-ghca-ann-menu-btn aria-label="' . esc_attr( sprintf( /* translators: %s: announcement title */ __( 'Options for %s', 'ghca-acd' ), (string) $item['label'] ) ) . '"><span class="gh-i i-more" aria-hidden="true"></span></button>';
        $html .= '<span class="gh-menu gh-ann-menu" role="menu" id="' . esc_attr( $menu ) . '" hidden data-ghca-ann-menu>';
        $html .= '<button type="button" class="gh-menu-i" role="menuitem" tabindex="-1" data-ghca-announce-edit="' . esc_attr( (string) $id ) . '"><span class="gh-i i-edit" aria-hidden="true"></span>' . esc_html__( 'Edit', 'ghca-acd' ) . '</button>';
        $html .= '<button type="button" class="gh-menu-i gh-menu-i--danger" role="menuitem" tabindex="-1" data-ghca-announce-delete="' . esc_attr( (string) $id ) . '"><span class="gh-i i-trash" aria-hidden="true"></span>' . esc_html__( 'Delete…', 'ghca-acd' ) . '</button>';
        $html .= '</span></span>';
      }
      $html .= '</div>';
    }
    return $html;
  }

  /** Renders just the announcement rows (reused by the AJAX re-render handler). */
  public static function render_announcement_list_html( string $format = '' ): string {
    $items     = self::get_announcement_items();
    if ( 'v2' === $format ) {
      return self::render_announcement_rows_v2( $items );
    }
    $type_meta = self::announcement_type_meta();

    ob_start();

    if ( empty( $items ) ) {
      echo '<p class="ghca-acd__announcement-empty">' . esc_html__( 'No announcements yet.', 'ghca-acd' ) . '</p>';
      return (string) ob_get_clean();
    }

    foreach ( $items as $item ) :
      $type     = isset( $item['type'], $type_meta[ $item['type'] ] ) ? (string) $item['type'] : 'update';
      $meta     = $type_meta[ $type ];
      $editable = ! empty( $item['editable'] ) && (int) ( $item['id'] ?? 0 ) > 0;
      ?>
      <div class="ghca-acd__announcement-item<?php echo $editable ? ' is-editable' : ''; ?>"
        <?php if ( $editable ) : ?>
        data-announce-id="<?php echo esc_attr( (string) (int) $item['id'] ); ?>"
        data-announce-title="<?php echo esc_attr( (string) $item['label'] ); ?>"
        data-announce-body="<?php echo esc_attr( (string) ( $item['body'] ?? '' ) ); ?>"
        data-announce-type="<?php echo esc_attr( $type ); ?>"
        data-announce-url="<?php echo esc_attr( (string) ( $item['url'] ?? '' ) ); ?>"
        <?php endif; ?>>
        <span class="ghca-acd__announcement-icon ghca-acd__announcement-icon--<?php echo esc_attr( $type ); ?>" aria-hidden="true"><?php echo GHCA_UI_Icons::render( $meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <div class="ghca-acd__announcement-content">
          <h4>
            <?php echo esc_html( $item['label'] ); ?>
            <span class="ghca-acd__alert-badge ghca-acd__alert-badge--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $meta['label'] ); ?></span>
          </h4>
          <?php if ( ! empty( $item['body'] ) ) : ?>
            <p><?php echo esc_html( $item['body'] ); ?></p>
          <?php endif; ?>
          <?php if ( ! empty( $item['url'] ) ) : ?>
            <p><a href="<?php echo esc_url( $item['url'] ); ?>" class="ghca-acd__alert-link"><?php esc_html_e( 'Take action', 'ghca-acd' ); ?> &rarr;</a></p>
          <?php endif; ?>
        </div>
        <?php if ( $editable ) : ?>
          <div class="ghca-acd__announcement-actions">
            <button type="button" class="ghca-acd__announcement-action" data-ghca-announce-edit="<?php echo esc_attr( (string) (int) $item['id'] ); ?>" aria-label="<?php esc_attr_e( 'Edit announcement', 'ghca-acd' ); ?>"><?php echo GHCA_UI_Icons::render( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
            <button type="button" class="ghca-acd__announcement-action ghca-acd__announcement-action--danger" data-ghca-announce-delete="<?php echo esc_attr( (string) (int) $item['id'] ); ?>" aria-label="<?php esc_attr_e( 'Delete announcement', 'ghca-acd' ); ?>"><?php echo GHCA_UI_Icons::render( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
          </div>
        <?php endif; ?>
      </div>
    <?php
    endforeach;

    return (string) ob_get_clean();
  }

  /**
   * Board modal for adding and editing announcements, and the delete confirmation (Modal-Announcement), rendered
   * inside the redesigned dashboard's themed wrapper. Same fields and AJAX actions as the legacy footer modal,
   * plus "Who sees it". The legacy footer modal is skipped on a page that renders this one.
   */
  public static function board_modal_html(): string {
    if ( ! is_user_logged_in() || ! self::can_manage_announcements() ) {
      return '';
    }
    $icon  = static function ( string $n, string $size = '' ): string { return GHCA_ODP_UI::icon( $n, $size ); };
    $types = array( 'alert' => array( 'alert', __( 'Alert', 'ghca-acd' ) ), 'reminder' => array( 'bell', __( 'Reminder', 'ghca-acd' ) ), 'update' => array( 'megaphone', __( 'Update', 'ghca-acd' ) ) );
    $groups = array();
    foreach ( class_exists( 'GHCA_ACD_Scoping' ) ? GHCA_ACD_Scoping::get_visible_group_ids() : array() as $gid ) {
      $groups[ (int) $gid ] = (string) get_the_title( (int) $gid );
    }
    $h  = '<div class="gh-mod-layer gh-an-layer" id="ghca-acd-announce-modal" data-ghca-ui="board" hidden aria-hidden="true"><div class="gh-scrim" data-ghca-announce-close></div>';
    $h .= '<div class="gh-modal gh-an-modal" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-announce-modal-title" data-ghca-announce-dialog>';
    $h .= '<div class="gh-modal-hd"><div class="gh-an-hd-t"><h2 class="gh-h3" id="ghca-acd-announce-modal-title">' . esc_html__( 'Add announcement', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary gh-small">' . esc_html__( 'Shows on dashboards until you remove it.', 'ghca-acd' ) . '</p></div>';
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon" data-ghca-announce-close aria-label="' . esc_attr__( 'Close', 'ghca-acd' ) . '">' . $icon( 'x' ) . '</button></div>';
    $h .= '<form data-ghca-announce-form novalidate><div class="gh-modal-bd gh-an-bd"><input type="hidden" name="announce_id" value="0" />';
    $h .= '<p class="gh-alert gh-alert--neutral gh-an-draft" role="status" hidden data-ghca-announce-draft-note>' . esc_html__( 'Your unpublished text from earlier is back. Check it, then publish.', 'ghca-acd' ) . '</p>';
    $h .= '<div class="gh-field"><label class="gh-label" for="ghca-an-title">' . esc_html__( 'Title', 'ghca-acd' ) . ' <span class="gh-req" aria-hidden="true">*</span></label><input id="ghca-an-title" class="gh-input" type="text" name="title" maxlength="160" required aria-describedby="ghca-an-title-err" /><span class="gh-err" id="ghca-an-title-err" hidden data-ghca-announce-title-err>' . $icon( 'alert', 'sm' ) . esc_html__( 'Enter a title.', 'ghca-acd' ) . '</span></div>';
    $h .= '<div class="gh-field"><label class="gh-label" for="ghca-an-body">' . esc_html__( 'Message', 'ghca-acd' ) . '</label><textarea id="ghca-an-body" class="gh-textarea" name="body" maxlength="600" rows="3"></textarea></div>';
    $h .= '<div class="gh-field"><span class="gh-label" id="ghca-an-type-l">' . esc_html__( 'Type', 'ghca-acd' ) . '</span><input type="hidden" name="type" value="update" /><div class="gh-seg gh-seg--block" role="group" aria-labelledby="ghca-an-type-l" data-ghca-announce-types>';
    foreach ( $types as $slug => $t ) {
      $h .= '<button type="button" aria-pressed="' . ( 'update' === $slug ? 'true' : 'false' ) . '" data-ghca-announce-type="' . esc_attr( $slug ) . '">' . $icon( $t[0] ) . esc_html( $t[1] ) . '</button>';
    }
    $h .= '</div></div><div class="gh-an-2"><div class="gh-field"><label class="gh-label" for="ghca-an-aud">' . esc_html__( 'Who sees it', 'ghca-acd' ) . '</label><select id="ghca-an-aud" class="gh-select" name="audience" aria-describedby="ghca-an-aud-help">';
    foreach ( self::audience_options() as $value => $label ) {
      if ( 'group' === $value && ! $groups ) {
        continue;
      }
      $h .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
    }
    $h .= '</select><span class="gh-help" id="ghca-an-aud-help">' . esc_html__( 'Saved with the announcement. This dashboard is for admins; employee dashboards will use this setting.', 'ghca-acd' ) . '</span></div>';
    $h .= '<div class="gh-field"><label class="gh-label" for="ghca-an-url">' . esc_html__( 'Action link (optional)', 'ghca-acd' ) . '</label><input id="ghca-an-url" class="gh-input" type="url" name="url" placeholder="https://" aria-describedby="ghca-an-url-err" /><span class="gh-err" id="ghca-an-url-err" hidden data-ghca-announce-url-err>' . $icon( 'alert', 'sm' ) . esc_html__( 'Enter a full link starting with https://, or leave it empty.', 'ghca-acd' ) . '</span></div></div>';
    if ( $groups ) {
      $h .= '<div class="gh-field" hidden data-ghca-announce-group-field><label class="gh-label" for="ghca-an-group">' . esc_html__( 'Group', 'ghca-acd' ) . ' <span class="gh-req" aria-hidden="true">*</span></label><select id="ghca-an-group" class="gh-select" name="audience_group"><option value="">' . esc_html__( 'Choose a group', 'ghca-acd' ) . '</option>';
      foreach ( $groups as $gid => $gname ) {
        $h .= '<option value="' . esc_attr( (string) $gid ) . '">' . esc_html( $gname ) . '</option>';
      }
      $h .= '</select></div>';
    }
    $h .= '</div><div class="gh-modal-ft"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-announce-close>' . esc_html__( 'Cancel', 'ghca-acd' ) . '</button><button type="submit" class="gh-btn gh-btn--primary" data-ghca-announce-save>' . esc_html__( 'Publish', 'ghca-acd' ) . '</button></div></form></div></div>';
    // Delete confirmation.
    $h .= '<div class="gh-mod-layer gh-an-layer" id="ghca-acd-announce-confirm" data-ghca-ui="board" hidden aria-hidden="true"><div class="gh-scrim" data-ghca-announce-keep></div>';
    $h .= '<div class="gh-modal gh-an-confirm" role="alertdialog" aria-modal="true" aria-labelledby="ghca-an-del-t" aria-describedby="ghca-an-del-d" data-ghca-announce-confirm-dialog><div class="gh-modal-bd gh-an-confirm-bd">';
    $h .= '<span class="gh-ann-ico gh-ann-ico--alert gh-an-confirm-ico">' . $icon( 'trash', 'lg' ) . '</span><h2 class="gh-h3" id="ghca-an-del-t">' . esc_html__( 'Delete this announcement?', 'ghca-acd' ) . '</h2>';
    $h .= '<p class="gh-p gh-secondary" id="ghca-an-del-d"><span data-ghca-announce-confirm-title></span> ' . esc_html__( 'disappears from the dashboard. This can’t be undone.', 'ghca-acd' ) . '</p></div>';
    $h .= '<div class="gh-modal-ft"><button type="button" class="gh-btn gh-btn--secondary" data-ghca-announce-keep>' . esc_html__( 'Keep it', 'ghca-acd' ) . '</button><button type="button" class="gh-btn gh-btn--danger" data-ghca-announce-confirm-delete>' . esc_html__( 'Delete announcement', 'ghca-acd' ) . '</button></div></div></div>';
    return $h;
  }

  /** Footer modal shell for adding/editing announcements. */
  public static function render_announcement_modal(): void {
    if ( ! is_singular() ) {
      return;
    }
    // The redesigned dashboard renders its own board modal inside the themed wrapper.
    if ( class_exists( 'GHCA_ACD_Redesign' ) && GHCA_ACD_Redesign::announcements_rendered() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) || ! is_user_logged_in() || ! self::can_manage_announcements() ) {
      return;
    }
    $type_meta = self::announcement_type_meta();
    ?>
    <div class="ghca-acd__edit-modal" id="ghca-acd-announce-modal" hidden aria-hidden="true">
      <div class="ghca-acd__edit-modal-backdrop" data-ghca-announce-close></div>
      <div class="ghca-acd__edit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-announce-modal-title">
        <div class="ghca-acd__edit-modal-header">
          <h2 id="ghca-acd-announce-modal-title"><?php esc_html_e( 'Add Announcement', 'ghca-acd' ); ?></h2>
          <button type="button" class="ghca-acd__edit-modal-close" data-ghca-announce-close aria-label="<?php esc_attr_e( 'Close', 'ghca-acd' ); ?>">&times;</button>
        </div>
        <div class="ghca-acd__edit-modal-body">
          <form class="ghca-acd__edit-form" data-ghca-announce-form>
            <input type="hidden" name="announce_id" value="0" />
            <div class="ghca-acd__edit-section">
              <label class="ghca-acd__edit-field">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Title', 'ghca-acd' ); ?></span>
                <input type="text" name="title" class="ghca-acd__edit-input" maxlength="160" required />
              </label>
              <label class="ghca-acd__edit-field" style="margin-top:14px;">
                <span class="ghca-acd__edit-label"><?php esc_html_e( 'Message', 'ghca-acd' ); ?></span>
                <textarea name="body" class="ghca-acd__edit-input ghca-acd__edit-textarea" rows="3" maxlength="600"></textarea>
              </label>
              <div class="ghca-acd__edit-course-grid" style="margin-top:14px;">
                <label class="ghca-acd__edit-field">
                  <span class="ghca-acd__edit-label"><?php esc_html_e( 'Type', 'ghca-acd' ); ?></span>
                  <select name="type" class="ghca-acd__edit-input">
                    <?php foreach ( $type_meta as $slug => $meta ) : ?>
                      <option value="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === 'update' ? ' selected' : ''; ?>><?php echo esc_html( $meta['label'] ); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="ghca-acd__edit-field">
                  <span class="ghca-acd__edit-label"><?php esc_html_e( 'Action link (optional)', 'ghca-acd' ); ?></span>
                  <input type="url" name="url" class="ghca-acd__edit-input" placeholder="https://" />
                </label>
              </div>
            </div>
            <div class="ghca-acd__edit-form-footer">
              <button type="button" class="ghca-acd__edit-btn ghca-acd__edit-btn--ghost" data-ghca-announce-close><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
              <button type="submit" class="ghca-acd__edit-btn ghca-acd__edit-btn--save"><?php esc_html_e( 'Publish', 'ghca-acd' ); ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  /** Creates or updates an admin announcement, then returns the refreshed list. */
  public static function ajax_save_announcement(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_manage_announcements() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $announce_id = isset( $_POST['announce_id'] ) ? (int) $_POST['announce_id'] : 0;
    // An edit is checked against the stored announcement first, before any other validation or write: an
    // out-of-scope group announcement cannot be edited or moved to any audience (including "everyone"), and
    // out-of-scope ids answer exactly like unknown ids.
    if ( $announce_id > 0 && ! self::can_touch_existing( $announce_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
    }
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
    $body        = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
    $type        = isset( $_POST['type'] ) ? self::sanitize_announcement_type( wp_unslash( $_POST['type'] ) ) : 'update';
    $url         = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    // Audience (slice 8). Absent from the legacy form: an existing announcement keeps its stored value.
    $has_audience = isset( $_POST['audience'] );
    $audience     = $has_audience ? self::sanitize_audience( wp_unslash( $_POST['audience'] ) ) : 'all';
    $group_id     = 'group' === $audience && isset( $_POST['audience_group'] ) ? (int) $_POST['audience_group'] : 0;

    if ( $title === '' ) {
      wp_send_json_error( array( 'message' => __( 'A title is required.', 'ghca-acd' ) ) );
    }
    if ( 'group' === $audience ) {
      $allowed = class_exists( 'GHCA_ACD_Scoping' ) ? array_map( 'intval', GHCA_ACD_Scoping::get_visible_group_ids() ) : array();
      if ( $group_id <= 0 || ! in_array( $group_id, $allowed, true ) ) {
        wp_send_json_error( array( 'message' => __( 'Choose one of your groups for this announcement.', 'ghca-acd' ) ) );
      }
    }

    $postarr = array(
      'post_type'    => 'ghca-announce',
      'post_status'  => 'publish',
      'post_title'   => $title,
      'post_content' => $body,
    );

    if ( $announce_id > 0 ) {
      $postarr['ID'] = $announce_id;
      $result        = wp_update_post( $postarr, true );
    } else {
      $result = wp_insert_post( $postarr, true );
    }

    if ( is_wp_error( $result ) || (int) $result <= 0 ) {
      wp_send_json_error( array( 'message' => __( 'Could not save the announcement.', 'ghca-acd' ) ) );
    }

    $post_id = (int) $result;
    update_post_meta( $post_id, 'ghca_announce_type', $type );
    if ( $url !== '' ) {
      update_post_meta( $post_id, 'ghca_announce_url', $url );
    } else {
      delete_post_meta( $post_id, 'ghca_announce_url' );
    }
    if ( $has_audience || $announce_id === 0 ) {
      update_post_meta( $post_id, 'ghca_announce_audience', $audience );
      if ( 'group' === $audience ) {
        update_post_meta( $post_id, 'ghca_announce_group', $group_id );
      } else {
        delete_post_meta( $post_id, 'ghca_announce_group' );
      }
    }

    // if ( $announce_id === 0 ) {
    //   do_action( 'ghca_acd_new_announcement_published', $post_id, $title, $body );
    // }

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html( self::requested_format() ),
      'message' => $announce_id > 0 ? __( 'Announcement updated.', 'ghca-acd' ) : __( 'Announcement published.', 'ghca-acd' ),
      'audience' => self::audience_label( $audience, $group_id ),
    ) );
  }

  public static function process_buddyboss_notifications( $post_id, $title, $body ) {
    if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
      return;
    }

    $employee_roles = apply_filters( 'ghca_acd_employee_roles', array( 'caregiver', 'nurse' ) );

    $users = get_users( array(
      'role__in' => $employee_roles,
      'fields'   => 'ID',
    ) );

    foreach ( $users as $user_id ) {
      bp_notifications_add_notification( array(
        'user_id'           => $user_id,
        'item_id'           => $post_id,
        'secondary_item_id' => get_current_user_id(),
        'component_name'    => 'custom',
        'component_action'  => 'new_announcement',
        'date_notified'     => bp_core_current_time(),
        'is_new'            => 1,
      ) );
    }
  }

  public static function format_buddypress_notifications( $action, $item_id, $secondary_item_id, $total_items, $format = 'string' ) {
    if ( 'new_announcement' === $action ) {
      $title = get_the_title( $item_id );
      $link  = site_url( '/employee-dashboard/' );

      $text = sprintf( __( 'New Compliance Announcement: %s', 'ghca-acd' ), $title );

      if ( 'string' === $format ) {
        return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
      } else {
        return array(
          'text' => $text,
          'link' => $link
        );
      }
    }
    return $action;
  }

  /** Deletes an admin announcement, then returns the refreshed list. */
  public static function ajax_delete_announcement(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! self::can_manage_announcements() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    $announce_id = isset( $_POST['announce_id'] ) ? (int) $_POST['announce_id'] : 0;
    // Unknown, not an announcement, or a group announcement outside this manager's groups: the same answer.
    if ( ! self::can_touch_existing( $announce_id ) ) {
      wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
    }

    wp_delete_post( $announce_id, true );

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html( self::requested_format() ),
      'message' => __( 'Announcement deleted.', 'ghca-acd' ),
    ) );
  }

  /** Returns the current announcement list HTML (used to refresh after changes). */
  public static function ajax_get_announcements(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    wp_send_json_success( array( 'html' => self::render_announcement_list_html( self::requested_format() ) ) );
  }

  /**
   * Builds the announcement list shown in the panel.
   *
   * Admin-authored announcements (the ghca-announce CPT) come first and are
   * editable; the live, auto-generated system items (overdue count, last data
   * refresh) follow and are read-only.
   *
   * @return array<int,array{id:int,label:string,body:string,type:string,url:string,editable:bool}>
   */
  private static function get_announcement_items(): array {
    $can_manage = self::can_manage_announcements();
    $items      = array();

    $posts = get_posts(
      array(
        'post_type'              => 'ghca-announce',
        'post_status'            => 'publish',
        'posts_per_page'         => 10,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
      )
    );

    foreach ( $posts as $post ) {
      $audience = self::sanitize_audience( (string) get_post_meta( $post->ID, 'ghca_announce_audience', true ) ?: 'all' );
      $group_id = (int) get_post_meta( $post->ID, 'ghca_announce_group', true );
      // A group announcement is shown to admins whose view includes that group (unscoped admins see all).
      if ( 'group' === $audience && ! self::viewer_sees_group( $group_id ) ) {
        continue;
      }
      $items[] = array(
        'audience'       => $audience,
        'audience_group' => $group_id,
        'audience_label' => self::audience_label( $audience, $group_id ),
        'id'       => (int) $post->ID,
        'label'    => get_the_title( $post ),
        'body'     => trim( wp_strip_all_tags( (string) $post->post_content ) ),
        'type'     => self::sanitize_announcement_type( get_post_meta( $post->ID, 'ghca_announce_type', true ) ),
        'url'      => (string) get_post_meta( $post->ID, 'ghca_announce_url', true ),
        'meta'     => self::post_meta_line( $post ),
        'editable' => $can_manage,
      );
    }

    foreach ( self::get_auto_announcement_items() as $auto ) {
      $items[] = array_merge(
        array( 'id' => 0, 'body' => '', 'url' => '', 'meta' => __( 'Automatic', 'ghca-acd' ), 'editable' => false ),
        $auto
      );
    }

    /** @var array<int,array<string,mixed>> $items */
    return apply_filters( 'ghca_admin_announcements_items', $items );
  }

  private static function post_meta_line( $post ): string {
    $parts  = array( (string) get_the_date( 'M j', $post ) );
    $author = get_the_author_meta( 'display_name', (int) $post->post_author );
    if ( is_string( $author ) && '' !== $author ) {
      $parts[] = $author;
    }
    return implode( ' · ', array_filter( $parts ) );
  }

  /** Live, read-only announcement items derived from current compliance data. */
  private static function get_auto_announcement_items(): array {
    $data         = GHCA_ACD_Data_Provider::get_aggregate();
    $overdue      = (int) ( $data['overdue_employees'] ?? 0 );
    $sync_time    = wp_date( 'g:i A' );
    $overdue_page = GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-overdue-employees';

    $items = array();

    if ( $overdue > 0 ) {
      $items[] = array(
        'label' => sprintf(
          /* translators: %d: number of overdue employees */
          _n( '%d employee is overdue', '%d employees are overdue', $overdue, 'ghca-acd' ),
          $overdue
        ),
        'body'  => __( 'Review the Overdue list and send reminders before the end of the week.', 'ghca-acd' ),
        'type'  => 'alert',
        'url'   => $overdue_page,
      );
    }

    $items[] = array(
      'label' => __( 'Training data refreshed', 'ghca-acd' ),
      'body'  => sprintf(
        /* translators: %s: time of day */
        __( 'LearnDash progress was last loaded at %s. Data refreshes automatically.', 'ghca-acd' ),
        $sync_time
      ),
      'type'  => 'system',
    );

    return $items;
  }
}
