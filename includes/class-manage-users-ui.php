<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Manage_Users_UI {
  public static function init(): void {
    add_shortcode( 'admin_compliance_manage_users', array( __CLASS__, 'render_shortcode' ) );
  }

  public static function render_shortcode( $atts = array() ): string {
    if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_manage_users() ) {
      return '<div class="ghca-acd__card ghca-acd__card--denied"><p>' . esc_html__( 'You do not have permission to manage users.', 'ghca-acd' ) . '</p></div>';
    }

    $users = GHCA_Admin_Compliance_Dashboard::get_employee_user_ids();
    
    // Get visible groups for the dropdown
    $visible_groups = GHCA_ACD_Scoping::get_visible_group_ids();
    $group_options = array();
    foreach ( $visible_groups as $gid ) {
      $group_options[ $gid ] = get_the_title( $gid );
    }

    ob_start();
    ?>
    <div class="ghca-acd ghca-acd--manage-users">
      <div class="ghca-acd__header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
        <h2><?php esc_html_e( 'Manage Users', 'ghca-acd' ); ?></h2>
        <button type="button" class="ghca-acd__btn ghca-acd__btn--primary" id="ghca-acd-btn-add-user">
          <?php echo GHCA_UI_Icons::render( 'user-plus' ); ?>
          <?php esc_html_e( 'Add Employee', 'ghca-acd' ); ?>
        </button>
      </div>

      <div class="ghca-acd__panel">
        <div class="ghca-acd__table-wrap">
          <table class="ghca-acd__table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Name', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Email', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Phone', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Group', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'ghca-acd' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $users ) ) : ?>
                <tr>
                  <td colspan="5" class="ghca-acd__table-empty"><?php esc_html_e( 'No employees found.', 'ghca-acd' ); ?></td>
                </tr>
              <?php else : ?>
                <?php foreach ( $users as $user_id ) : 
                  $user = get_userdata( $user_id );
                  if ( ! $user ) continue;
                  $phone = get_user_meta( $user_id, 'billing_phone', true );
                  if ( empty( $phone ) ) {
                    $phone = get_user_meta( $user_id, 'phone', true );
                  }
                  
                  // For buddyboss fallback display if wp meta is empty
                  if ( empty( $phone ) && function_exists( 'xprofile_get_field_data' ) ) {
                    $phone = xprofile_get_field_data( 'Phone', $user_id );
                    if ( empty( $phone ) ) {
                      $phone = xprofile_get_field_data( 'Phone Number', $user_id );
                    }
                  }
                  $phone = wp_strip_all_tags( (string) $phone );
                  
                  $user_groups = learndash_get_users_group_ids( $user_id );
                  $user_group_names = array();
                  foreach ( $user_groups as $g ) {
                    $user_group_names[] = get_the_title( $g );
                  }
                  ?>
                  <tr>
                    <td><?php echo esc_html( $user->first_name . ' ' . $user->last_name ); ?></td>
                    <td><?php echo esc_html( $user->user_email ); ?></td>
                    <td><?php echo esc_html( (string) $phone ); ?></td>
                    <td><?php echo esc_html( implode( ', ', $user_group_names ) ); ?></td>
                    <td>
                      <button type="button" class="ghca-acd__btn ghca-acd__btn--secondary ghca-acd-btn-edit-user"
                        data-user_id="<?php echo esc_attr( (string) $user_id ); ?>"
                        data-first_name="<?php echo esc_attr( $user->first_name ); ?>"
                        data-last_name="<?php echo esc_attr( $user->last_name ); ?>"
                        data-email="<?php echo esc_attr( $user->user_email ); ?>"
                        data-phone="<?php echo esc_attr( (string) $phone ); ?>"
                        data-groups="<?php echo esc_attr( json_encode( $user_groups ) ); ?>">
                        <?php esc_html_e( 'Edit', 'ghca-acd' ); ?>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal -->
      <div id="ghca-acd-user-modal" class="ghca-acd-modal" style="display:none;">
        <div class="ghca-acd-modal__overlay"></div>
        <div class="ghca-acd-modal__content">
          <div class="ghca-acd-modal__header">
            <h3 id="ghca-acd-user-modal-title"><?php esc_html_e( 'Add Employee', 'ghca-acd' ); ?></h3>
            <button type="button" class="ghca-acd-modal__close">&times;</button>
          </div>
          <div class="ghca-acd-modal__body">
            <form id="ghca-acd-user-form">
              <input type="hidden" name="action" value="ghca_acd_save_employee" />
              <input type="hidden" id="ghca-acd-user-id" name="user_id" value="" />
              <?php wp_nonce_field( 'ghca_save_employee', 'ghca_nonce' ); ?>
              
              <div class="ghca-acd-form-group">
                <label for="ghca-acd-first-name"><?php esc_html_e( 'First Name', 'ghca-acd' ); ?> *</label>
                <input type="text" id="ghca-acd-first-name" name="first_name" required />
              </div>
              <div class="ghca-acd-form-group">
                <label for="ghca-acd-last-name"><?php esc_html_e( 'Last Name', 'ghca-acd' ); ?> *</label>
                <input type="text" id="ghca-acd-last-name" name="last_name" required />
              </div>
              <div class="ghca-acd-form-group">
                <label for="ghca-acd-email"><?php esc_html_e( 'Email Address', 'ghca-acd' ); ?> *</label>
                <input type="email" id="ghca-acd-email" name="email" required />
              </div>
              <div class="ghca-acd-form-group">
                <label for="ghca-acd-phone"><?php esc_html_e( 'Phone Number', 'ghca-acd' ); ?></label>
                <input type="text" id="ghca-acd-phone" name="phone" />
              </div>
              
              <div class="ghca-acd-form-group">
                <label><?php esc_html_e( 'LearnDash Groups', 'ghca-acd' ); ?></label>
                <div class="ghca-acd-checkbox-list">
                  <?php foreach ( $group_options as $gid => $gname ) : ?>
                    <label>
                      <input type="checkbox" name="groups[]" value="<?php echo esc_attr( (string) $gid ); ?>" />
                      <?php echo esc_html( $gname ); ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="ghca-acd-form-actions">
                <button type="button" class="ghca-acd__btn ghca-acd__btn--secondary ghca-acd-modal__cancel"><?php esc_html_e( 'Cancel', 'ghca-acd' ); ?></button>
                <button type="submit" class="ghca-acd__btn ghca-acd__btn--primary" id="ghca-acd-user-submit">
                  <span class="ghca-acd-btn-text"><?php esc_html_e( 'Save Employee', 'ghca-acd' ); ?></span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <style>
      .ghca-acd-modal {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .ghca-acd-modal__overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
      }
      .ghca-acd-modal__content {
        position: relative;
        background: #fff;
        border-radius: 8px;
        width: 100%;
        max-width: 500px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      }
      .ghca-acd-modal__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 24px;
        border-bottom: 1px solid #e2e8f0;
      }
      .ghca-acd-modal__header h3 { margin: 0; font-size: 18px; }
      .ghca-acd-modal__close {
        background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b;
      }
      .ghca-acd-modal__body { padding: 24px; }
      .ghca-acd-form-group { margin-bottom: 16px; }
      .ghca-acd-form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
      .ghca-acd-form-group input[type="text"],
      .ghca-acd-form-group input[type="email"] {
        width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;
      }
      .ghca-acd-checkbox-list {
        max-height: 150px; overflow-y: auto; border: 1px solid #cbd5e1; padding: 12px; border-radius: 4px;
      }
      .ghca-acd-checkbox-list label { display: block; font-weight: normal; margin-bottom: 8px; }
      .ghca-acd-checkbox-list label:last-child { margin-bottom: 0; }
      .ghca-acd-form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
    </style>
    <?php
    return (string) ob_get_clean();
  }
}
