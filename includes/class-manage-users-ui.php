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

    // Add/Edit pickers list ACTIVE values only (built-in + current custom). A removed
    // custom Employment Type / role is offered solely when editing the employee who
    // still holds it, via that row's data-*_label attributes (see initManageUsers).
    $type_options = GHCA_ACD_Data_Provider::employment_types();
    $manageable_roles = $editable_roles;
    $editable_roles = GHCA_ACD_Roles::selectable_employee_roles( $editable_roles );

    // Slice 8: the ManageUsers board when the redesign is on. Same data, permissions, form fields and action.
    if ( class_exists( 'GHCA_ACD_Settings' ) && GHCA_ACD_Settings::ui_redesign_enabled() && class_exists( 'GHCA_ACD_Redesign' ) ) {
      return self::render_board( $users, $group_options, $all_roles, $editable_roles, $manageable_roles, $type_options );
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
                  // Only this employee's own removed values are exposed to their Edit button.
                  $row_type       = GHCA_ACD_Data_Provider::employment_type( (int) $user_id );
                  $row_type_label = '' !== $row_type && ! isset( $type_options[ $row_type ] ) ? GHCA_ACD_Data_Provider::employment_type_label( (int) $user_id ) : '';
                  $row_role_label = '' !== $user_role_slug && isset( $manageable_roles[ $user_role_slug ] ) && ! isset( $editable_roles[ $user_role_slug ] ) ? translate_user_role( $user_role_name ) : '';
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
                        data-employment_type="<?php echo esc_attr( $row_type ); ?>"
                        <?php if ( $row_type_label ) : ?>data-employment_type_label="<?php echo esc_attr( $row_type_label ); ?>"<?php endif; ?>
                        data-role="<?php echo esc_attr( $user_role_slug ); ?>"
                        <?php if ( $row_role_label ) : ?>data-role_label="<?php echo esc_attr( $row_role_label ); ?>"<?php endif; ?>
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
                  <?php foreach ( $type_options as $value => $label ) : ?>
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
  private static function icon( string $name, string $size = '' ): string {
    return GHCA_ODP_UI::icon( $name, $size );
  }

  /** One row's display and edit data, computed exactly as the legacy table computes it. */
  private static function row_data( int $user_id, array $users, array $all_roles, array $editable_roles, array $manageable_roles, array $type_options ): ?array {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
      return null;
    }
    $phone = get_user_meta( $user_id, 'billing_phone', true );
    if ( empty( $phone ) ) {
      $phone = get_user_meta( $user_id, 'phone', true );
    }
    if ( empty( $phone ) && function_exists( 'xprofile_get_field_data' ) ) {
      $phone = xprofile_get_field_data( 'Phone', $user_id );
      if ( empty( $phone ) ) {
        $phone = xprofile_get_field_data( 'Phone Number', $user_id );
      }
    }
    $groups = function_exists( 'learndash_get_users_group_ids' ) ? array_values( array_filter( array_map( 'intval', (array) learndash_get_users_group_ids( $user_id ) ), static function ( int $g ): bool { return $g > 0; } ) ) : array();
    $role   = ! empty( $user->roles ) ? (string) $user->roles[0] : '';
    $rname  = $role && isset( $all_roles[ $role ] ) ? (string) $all_roles[ $role ]['name'] : $role;
    $type   = GHCA_ACD_Data_Provider::employment_type( $user_id );
    return array(
      'id'         => $user_id,
      'first'      => (string) $user->first_name,
      'last'       => (string) $user->last_name,
      'name'       => trim( $user->first_name . ' ' . $user->last_name ) ?: (string) $user->display_name,
      'email'      => (string) $user->user_email,
      'phone'      => wp_strip_all_tags( (string) $phone ),
      'role'       => $role,
      'role_name'  => '' !== $rname ? translate_user_role( $rname ) : '',
      'role_label' => '' !== $role && isset( $manageable_roles[ $role ] ) && ! isset( $editable_roles[ $role ] ) ? translate_user_role( $rname ) : '',
      'type'       => $type,
      'type_label' => '' !== $type && ! isset( $type_options[ $type ] ) ? GHCA_ACD_Data_Provider::employment_type_label( $user_id ) : '',
      'groups'     => $groups,
      'group_names'=> array_values( array_filter( array_map( 'get_the_title', $groups ) ) ),
      'inactive'   => GHCA_ACD_Data_Provider::is_user_suspended( $user_id ),
      'can_edit'   => current_user_can( 'manage_options' ) || GHCA_ACD_Roles::delegated_target_is_allowed( get_current_user_id(), $user_id, (array) $user->roles, GHCA_ACD_Roles::delegated_target_is_in_scope( $user_id, $users ), (array) $user->allcaps ),
    );
  }

  /**
   * ManageUsers board: search, Active/Inactive, the people table (cards below 1024px) and the Add/Edit drawer.
   * The form keeps the legacy ids, field names, nonce and action, so the existing handler and its checks apply.
   */
  private static function render_board( array $users, array $group_options, array $all_roles, array $editable_roles, array $manageable_roles, array $type_options ): string {
    $rows = array();
    foreach ( $users as $user_id ) {
      $row = self::row_data( (int) $user_id, $users, $all_roles, $editable_roles, $manageable_roles, $type_options );
      if ( $row ) {
        $rows[] = $row;
      }
    }
    usort( $rows, static function ( array $a, array $b ): int { return strcasecmp( $a['name'], $b['name'] ); } );
    $active   = count( array_filter( $rows, static function ( $r ) { return ! $r['inactive']; } ) );
    $inactive = count( $rows ) - $active;

    $h  = '<div class="ghca-ui ghca-ui--bleed"><div class="gh-app ghca-acd ghca-acd--v2 gh-mu" data-theme="' . esc_attr( GHCA_ACD_Redesign::theme_slug() ) . '"' . ( '' !== GHCA_ACD_Redesign::brand_style() ? ' style="' . esc_attr( GHCA_ACD_Redesign::brand_style() ) . '"' : '' ) . '>';
    $h .= '<div class="gh-chrome"><div class="gh-container"><div class="gh-pagehead"><div class="gh-pagehead-main"><a class="gh-btn gh-btn--ghost gh-btn--sm gh-mu-back" href="' . esc_url( GHCA_ACD_Data_Provider::get_admin_dashboard_url() . '#ghca-tab-reports' ) . '">' . self::icon( 'arrow-left', 'sm' ) . esc_html__( 'Back to reports', 'ghca-acd' ) . '</a>';
    $h .= '<span class="gh-eyebrow">' . esc_html__( 'Compliance center', 'ghca-acd' ) . '</span><h1 class="gh-h1">' . esc_html__( 'Manage users', 'ghca-acd' ) . '</h1></div></div></div></div>';
    $h .= '<main class="gh-container gh-main gh-mu-main">';
    $h .= '<div class="gh-toolbar gh-mu-bar"><div class="gh-input-wrap gh-mu-search">' . self::icon( 'search' ) . '<label class="gh-sr" for="ghca-mu-q">' . esc_html__( 'Search name or email', 'ghca-acd' ) . '</label><input id="ghca-mu-q" class="gh-input" type="search" placeholder="' . esc_attr__( 'Search name or email', 'ghca-acd' ) . '" data-ghca-mu-search /></div>';
    $h .= '<div class="gh-actions"><div class="gh-subtabs" role="group" aria-label="' . esc_attr__( 'Account status', 'ghca-acd' ) . '">';
    $h .= '<button type="button" class="gh-subtab" aria-pressed="true" data-ghca-mu-status="active">' . esc_html__( 'Active', 'ghca-acd' ) . ' <span class="gh-count">' . esc_html( (string) $active ) . '</span></button>';
    $h .= '<button type="button" class="gh-subtab" aria-pressed="false" data-ghca-mu-status="inactive">' . esc_html__( 'Inactive', 'ghca-acd' ) . ' <span class="gh-count">' . esc_html( (string) $inactive ) . '</span></button></div>';
    $h .= '<button type="button" class="gh-btn gh-btn--primary" id="ghca-acd-btn-add-user">' . self::icon( 'user-plus' ) . esc_html__( 'Add employee', 'ghca-acd' ) . '</button></div></div>';
    $h .= '<p class="gh-sr" role="status" aria-live="polite" data-ghca-mu-count></p>';

    if ( empty( $rows ) ) {
      $h .= '<div class="gh-card gh-empty"><span class="gh-empty-ico">' . self::icon( 'users', 'xl' ) . '</span><strong class="gh-h4">' . esc_html__( 'No employees found.', 'ghca-acd' ) . '</strong></div>';
    } else {
      $h .= '<div class="gh-table-wrap gh-mu-tbl"><table class="gh-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Email', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Role', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Groups', 'ghca-acd' ) . '</th><th scope="col">' . esc_html__( 'Status', 'ghca-acd' ) . '</th><th scope="col" class="c-act"><span class="gh-sr">' . esc_html__( 'Edit', 'ghca-acd' ) . '</span></th></tr></thead><tbody>';
      $cards = '';
      foreach ( $rows as $r ) {
        $attrs  = ' data-ghca-mu-row data-status="' . ( $r['inactive'] ? 'inactive' : 'active' ) . '" data-search="' . esc_attr( strtolower( $r['name'] . ' ' . $r['email'] ) ) . '"' . ( $r['inactive'] ? ' hidden' : '' );
        $groups = $r['group_names'] ? implode( '', array_map( static function ( $g ) { return '<span class="gh-badge gh-badge--outline">' . esc_html( $g ) . '</span>'; }, $r['group_names'] ) ) : '<span class="gh-muted">—</span>';
        $chip   = $r['inactive'] ? '<span class="gh-st gh-st--suspended">' . esc_html__( 'Inactive', 'ghca-acd' ) . '</span>' : '<span class="gh-st gh-st--active">' . esc_html__( 'Active', 'ghca-acd' ) . '</span>';
        $edit   = $r['can_edit'] ? self::edit_button( $r ) : '';
        $person = '<div class="gh-person"><span class="gh-avatar" aria-hidden="true">' . esc_html( GHCA_ACD_Redesign::initials( $r['name'], 2 ) ) . '</span><strong>' . esc_html( $r['name'] ) . '</strong></div>';
        $h     .= '<tr' . $attrs . '><td>' . $person . '</td><td class="gh-secondary">' . esc_html( $r['email'] ) . ( '' !== $r['phone'] ? '<span class="gh-small gh-muted gh-mu-phone">' . esc_html( $r['phone'] ) . '</span>' : '' ) . '</td><td>' . esc_html( $r['role_name'] ) . '</td><td><div class="gh-mu-groups">' . $groups . '</div></td><td>' . $chip . '</td><td class="c-act">' . $edit . '</td></tr>';
        $cards .= '<div role="listitem" class="gh-card gh-mcard gh-mu-card"' . $attrs . '><div class="gh-mcard-top">' . $person . $chip . '</div><dl class="gh-kv"><div><dt>' . esc_html__( 'Email', 'ghca-acd' ) . '</dt><dd>' . esc_html( $r['email'] ) . '</dd></div>' . ( '' !== $r['phone'] ? '<div><dt>' . esc_html__( 'Phone', 'ghca-acd' ) . '</dt><dd>' . esc_html( $r['phone'] ) . '</dd></div>' : '' ) . '<div><dt>' . esc_html__( 'Role', 'ghca-acd' ) . '</dt><dd>' . esc_html( $r['role_name'] ) . '</dd></div><div><dt>' . esc_html__( 'Groups', 'ghca-acd' ) . '</dt><dd><div class="gh-mu-groups">' . $groups . '</div></dd></div></dl>' . ( '' !== $edit ? '<div class="gh-mcard-act">' . $edit . '</div>' : '' ) . '</div>';
      }
      $h .= '</tbody></table></div><div class="gh-cards gh-mu-cards" role="list" aria-label="' . esc_attr__( 'Employees', 'ghca-acd' ) . '">' . $cards . '</div>';
      $h .= '<div class="gh-card gh-empty gh-mu-none" hidden data-ghca-mu-none><strong class="gh-h4">' . esc_html__( 'No employees match.', 'ghca-acd' ) . '</strong><span class="gh-secondary">' . esc_html__( 'Try a different name or email.', 'ghca-acd' ) . '</span></div>';
    }
    $h .= '</main>';
    $h .= self::drawer_html( $group_options, $editable_roles, $type_options );
    $h .= GHCA_ACD_Redesign::toasts_html( array() );
    return $h . '</div></div>';
  }

  private static function edit_button( array $r ): string {
    $a  = ' data-user_id="' . esc_attr( (string) $r['id'] ) . '" data-first_name="' . esc_attr( $r['first'] ) . '" data-last_name="' . esc_attr( $r['last'] ) . '" data-email="' . esc_attr( $r['email'] ) . '" data-phone="' . esc_attr( $r['phone'] ) . '" data-employment_type="' . esc_attr( $r['type'] ) . '" data-role="' . esc_attr( $r['role'] ) . '" data-groups="' . esc_attr( (string) wp_json_encode( $r['groups'] ) ) . '"';
    $a .= '' !== $r['type_label'] ? ' data-employment_type_label="' . esc_attr( $r['type_label'] ) . '"' : '';
    $a .= '' !== $r['role_label'] ? ' data-role_label="' . esc_attr( $r['role_label'] ) . '"' : '';
    /* translators: %s: employee name */
    return '<button type="button" class="gh-btn gh-btn--ghost gh-btn--sm ghca-acd-btn-edit-user"' . $a . ' aria-label="' . esc_attr( sprintf( __( 'Edit %s', 'ghca-acd' ), $r['name'] ) ) . '">' . self::icon( 'edit', 'sm' ) . esc_html__( 'Edit', 'ghca-acd' ) . '</button>';
  }

  /** The Add/Edit drawer (board). Field names, ids and the nonce are the legacy form's. */
  private static function drawer_html( array $group_options, array $editable_roles, array $type_options ): string {
    $f  = static function ( string $id, string $label, string $control, bool $req = false, string $help = '' ): string {
      return '<div class="gh-field"><label class="gh-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $req ? ' <span class="gh-req" aria-hidden="true">*</span>' : '' ) . '</label>' . $control . ( '' !== $help ? '<span class="gh-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</span>' : '' ) . '</div>';
    };
    $h  = '<div class="gh-dr-layer gh-mu-layer" id="ghca-acd-user-modal" data-ghca-ui="board" hidden aria-hidden="true"><div class="gh-scrim" data-ghca-mu-close></div>';
    $h .= '<aside class="gh-drawer gh-mu-drawer" role="dialog" aria-modal="true" aria-labelledby="ghca-acd-user-modal-title" tabindex="-1" data-ghca-mu-dialog>';
    $h .= '<div class="gh-drawer-hd gh-mu-hd"><div class="gh-mu-hd-t"><h2 class="gh-h3" id="ghca-acd-user-modal-title">' . esc_html__( 'Add employee', 'ghca-acd' ) . '</h2><p class="gh-p gh-secondary gh-small" data-ghca-mu-sub>' . esc_html__( 'Creates a WordPress account and enrols them in LearnDash groups.', 'ghca-acd' ) . '</p></div>';
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon ghca-acd-modal__close" data-ghca-mu-close aria-label="' . esc_attr__( 'Close', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';
    $h .= '<form id="ghca-acd-user-form" class="gh-mu-form" novalidate><div class="gh-drawer-bd gh-mu-bd">';
    $h .= '<input type="hidden" name="action" value="ghca_acd_save_employee" /><input type="hidden" id="ghca-acd-user-id" name="user_id" value="" />' . wp_nonce_field( 'ghca_save_employee', 'ghca_nonce', true, false );
    $h .= '<div class="gh-mu-2">' . $f( 'ghca-acd-first-name', __( 'First name', 'ghca-acd' ), '<input id="ghca-acd-first-name" class="gh-input" type="text" name="first_name" required autocomplete="off" />', true ) . $f( 'ghca-acd-last-name', __( 'Last name', 'ghca-acd' ), '<input id="ghca-acd-last-name" class="gh-input" type="text" name="last_name" required autocomplete="off" />', true ) . '</div>';
    $h .= '<div class="gh-field"><label class="gh-label" for="ghca-acd-email">' . esc_html__( 'Email', 'ghca-acd' ) . ' <span class="gh-req" aria-hidden="true">*</span></label><input id="ghca-acd-email" class="gh-input" type="email" name="email" required autocomplete="off" aria-describedby="ghca-acd-email-err" /><span class="gh-err" id="ghca-acd-email-err" hidden data-ghca-mu-email-err>' . self::icon( 'alert', 'sm' ) . esc_html__( 'Enter a full email address, like name@example.org.', 'ghca-acd' ) . '</span></div>';
    $h .= $f( 'ghca-acd-phone', __( 'Mobile phone', 'ghca-acd' ), '<input id="ghca-acd-phone" class="gh-input" type="tel" name="phone" autocomplete="off" aria-describedby="ghca-acd-phone-help" />', false, __( 'Needed for SMS reminders.', 'ghca-acd' ) );
    $roles = '<select id="ghca-acd-role" class="gh-select" name="role" required aria-describedby="ghca-acd-role-help"><option value="" selected disabled>' . esc_html__( 'Choose a role', 'ghca-acd' ) . '</option>';
    foreach ( $editable_roles as $slug => $details ) {
      $roles .= '<option value="' . esc_attr( (string) $slug ) . '">' . esc_html( translate_user_role( (string) $details['name'] ) ) . '</option>';
    }
    $roles .= '</select>';
    $types = '<select id="ghca-acd-employment-type" class="gh-select" name="employment_type"><option value="">' . esc_html__( 'Not set', 'ghca-acd' ) . '</option>';
    foreach ( $type_options as $value => $label ) {
      $types .= '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( (string) $label ) . '</option>';
    }
    $types .= '</select>';
    $h .= '<div class="gh-mu-2">' . $f( 'ghca-acd-role', __( 'Role', 'ghca-acd' ), $roles, true, __( 'No default — admin access is never pre-selected.', 'ghca-acd' ) ) . $f( 'ghca-acd-employment-type', __( 'Employment type', 'ghca-acd' ), $types ) . '</div>';
    $h .= '<fieldset class="gh-mu-groups-fs"><legend class="gh-label">' . esc_html__( 'LearnDash groups', 'ghca-acd' ) . '</legend>';
    if ( count( $group_options ) > 6 ) {
      $h .= '<div class="gh-input-wrap">' . self::icon( 'search' ) . '<input class="gh-input" type="search" placeholder="' . esc_attr__( 'Filter groups', 'ghca-acd' ) . '" aria-label="' . esc_attr__( 'Filter groups', 'ghca-acd' ) . '" data-ghca-mu-group-filter /></div>';
    }
    $h .= '<div class="gh-mu-glist">';
    foreach ( $group_options as $gid => $gname ) {
      $courses = function_exists( 'learndash_group_enrolled_courses' ) ? count( (array) learndash_group_enrolled_courses( (int) $gid ) ) : null;
      $h      .= '<label class="gh-check gh-mu-g" data-name="' . esc_attr( strtolower( (string) $gname ) ) . '"><input type="checkbox" name="groups[]" value="' . esc_attr( (string) $gid ) . '" /><span class="gh-mu-gn">' . esc_html( (string) $gname ) . '</span>' . ( null !== $courses ? '<span class="gh-small gh-muted">' . esc_html( sprintf( /* translators: %d: courses */ _n( '%d course', '%d courses', $courses, 'ghca-acd' ), $courses ) ) . '</span>' : '' ) . '</label>';
    }
    if ( empty( $group_options ) ) {
      $h .= '<p class="gh-small gh-muted">' . esc_html__( 'No groups are available to you.', 'ghca-acd' ) . '</p>';
    }
    $h .= '</div></fieldset></div>';
    $h .= '<div class="gh-drawer-ft gh-mu-ft"><button type="button" class="gh-btn gh-btn--secondary ghca-acd-modal__cancel" data-ghca-mu-close>' . esc_html__( 'Cancel', 'ghca-acd' ) . '</button><button type="submit" class="gh-btn gh-btn--primary" id="ghca-acd-user-submit">' . self::icon( 'user-plus' ) . '<span class="ghca-acd-btn-text">' . esc_html__( 'Add employee', 'ghca-acd' ) . '</span></button></div>';
    return $h . '</form></aside></div>';
  }
}
