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
        'capability_type' => 'post',
        'map_meta_cap'    => true,
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

  /** Renders just the announcement rows (reused by the AJAX re-render handler). */
  public static function render_announcement_list_html(): string {
    $items     = self::get_announcement_items();
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

  /** Footer modal shell for adding/editing announcements. */
  public static function render_announcement_modal(): void {
    if ( ! is_singular() ) {
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
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
    $body        = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
    $type        = isset( $_POST['type'] ) ? self::sanitize_announcement_type( wp_unslash( $_POST['type'] ) ) : 'update';
    $url         = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

    if ( $title === '' ) {
      wp_send_json_error( array( 'message' => __( 'A title is required.', 'ghca-acd' ) ) );
    }

    $postarr = array(
      'post_type'    => 'ghca-announce',
      'post_status'  => 'publish',
      'post_title'   => $title,
      'post_content' => $body,
    );

    if ( $announce_id > 0 ) {
      if ( get_post_type( $announce_id ) !== 'ghca-announce' ) {
        wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
      }
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

    // if ( $announce_id === 0 ) {
    //   do_action( 'ghca_acd_new_announcement_published', $post_id, $title, $body );
    // }

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html(),
      'message' => $announce_id > 0 ? __( 'Announcement updated.', 'ghca-acd' ) : __( 'Announcement published.', 'ghca-acd' ),
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
    if ( $announce_id <= 0 || get_post_type( $announce_id ) !== 'ghca-announce' ) {
      wp_send_json_error( array( 'message' => __( 'Announcement not found.', 'ghca-acd' ) ) );
    }

    wp_delete_post( $announce_id, true );

    wp_send_json_success( array(
      'html'    => self::render_announcement_list_html(),
      'message' => __( 'Announcement deleted.', 'ghca-acd' ),
    ) );
  }

  /** Returns the current announcement list HTML (used to refresh after changes). */
  public static function ajax_get_announcements(): void {
    check_ajax_referer( 'ghca_acd_table', 'nonce' );

    if ( ! GHCA_ACD_Shortcodes::can_view_dashboard() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ) );
    }

    wp_send_json_success( array( 'html' => self::render_announcement_list_html() ) );
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
      $items[] = array(
        'id'       => (int) $post->ID,
        'label'    => get_the_title( $post ),
        'body'     => trim( wp_strip_all_tags( (string) $post->post_content ) ),
        'type'     => self::sanitize_announcement_type( get_post_meta( $post->ID, 'ghca_announce_type', true ) ),
        'url'      => (string) get_post_meta( $post->ID, 'ghca_announce_url', true ),
        'editable' => $can_manage,
      );
    }

    foreach ( self::get_auto_announcement_items() as $auto ) {
      $items[] = array_merge(
        array( 'id' => 0, 'body' => '', 'url' => '', 'editable' => false ),
        $auto
      );
    }

    /** @var array<int,array<string,mixed>> $items */
    return apply_filters( 'ghca_admin_announcements_items', $items );
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
