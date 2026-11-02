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

    $users = GHCA_ACD_Data_Provider::get_employee_user_ids();
    
    // Get visible groups for the dropdown
    $visible_groups = GHCA_ACD_Scoping::get_visible_group_ids();
    $group_options = array();
    foreach ( $visible_groups as $gid ) {
      $group_options[ $gid ] = get_the_title( $gid );
    }

    // Delegated managers may create and edit employee accounts only.
    $all_roles = wp_roles()->roles;
    $editable_roles = GHCA_ACD_Roles::get_manageable_employee_roles();

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
        <div class="ghca-acd__table-wrap" tabindex="0" role="group" aria-label="Manage users table">
          <table class="ghca-acd__table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Name', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Email', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Phone', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Role', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Group', 'ghca-acd' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'ghca-acd' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $users ) ) : ?>
                <tr>
                  <td colspan="6" class="ghca-acd__table-empty"><?php esc_html_e( 'No employees found.', 'ghca-acd' ); ?></td>
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
                  
                  // Get user's primary role
                  $user_role_slug = ! empty( $user->roles ) ? $user->roles[0] : '';
                  $user_role_name = $user_role_slug && isset( $all_roles[ $user_role_slug ] ) ? $all_roles[ $user_role_slug ]['name'] : $user_role_slug;
                  $can_edit_user = current_user_can( 'manage_options' ) || GHCA_ACD_Roles::delegated_target_is_allowed(
                    get_current_user_id(),
                    (int) $user_id,
                    (array) $user->roles,
                    GHCA_ACD_Roles::delegated_target_is_in_scope( (int) $user_id, $users ),
                    (array) $user->allcaps
                  );
                  ?>
                  <tr>
                    <td><?php echo esc_html( $user->first_name . ' ' . $user->last_name ); ?></td>
                    <td><?php echo esc_html( $user->user_email ); ?></td>
                    <td><?php echo esc_html( (string) $phone ); ?></td>
                    <td><?php echo esc_html( translate_user_role( $user_role_name ) ); ?></td>
                    <td><?php echo esc_html( implode( ', ', $user_group_names ) ); ?></td>
                    <td>
                      <?php if ( $can_edit_user ) : ?>
                        <button type="button" class="ghca-acd__btn ghca-acd__btn--secondary ghca-acd-btn-edit-user"
                        data-user_id="<?php echo esc_attr( (string) $user_id ); ?>"
                        data-first_name="<?php echo esc_attr( $user->first_name ); ?>"
                        data-last_name="<?php echo esc_attr( $user->last_name ); ?>"
                        data-email="<?php echo esc_attr( $user->user_email ); ?>"
                        data-phone="<?php echo esc_attr( (string) $phone ); ?>"
                        data-employment_type="<?php echo esc_attr( GHCA_ACD_Data_Provider::employment_type( (int) $user_id ) ); ?>"
                        data-role="<?php echo esc_attr( $user_role_slug ); ?>"
                        data-groups="<?php echo esc_attr( wp_json_encode( $user_groups ) ); ?>">
                          <?php esc_html_e( 'Edit', 'ghca-acd' ); ?>
                        </button>
                      <?php endif; ?>
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
                <label for="ghca-acd-employment-type"><?php esc_html_e( 'Employment Type', 'ghca-acd' ); ?></label>
                <select id="ghca-acd-employment-type" name="employment_type">
                  <option value=""><?php esc_html_e( 'Not set', 'ghca-acd' ); ?></option>
                  <?php foreach ( GHCA_ACD_Data_Provider::employment_types() as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="ghca-acd-form-group">
                <label for="ghca-acd-role"><?php esc_html_e( 'Role', 'ghca-acd' ); ?> *</label>
                <select id="ghca-acd-role" name="role" required>
                  <option value="" selected disabled><?php esc_html_e( 'Select role', 'ghca-acd' ); ?></option>
                  <?php foreach ( $editable_roles as $role_slug => $role_details ) : ?>
                    <option value="<?php echo esc_attr( $role_slug ); ?>">
                      <?php echo esc_html( translate_user_role( $role_details['name'] ) ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="ghca-acd-form-group ghca-acd-form-group--full">
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
    <?php
    return (string) ob_get_clean();
  }
}
