<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Settings {
  const OPTION_AT_RISK_DAYS = 'ghca_acd_at_risk_days';
  const OPTION_CACHE_TTL    = 'ghca_acd_cache_ttl';
  const OPTION_PERM_EDIT_RECORDS = 'ghca_acd_permission_edit_records';
  const OPTION_PERM_MANAGE_ANNOUNCEMENTS = 'ghca_acd_permission_manage_announcements';
  const OPTION_PERM_UNRESTRICTED_VIEW = 'ghca_acd_permission_unrestricted_view';
  const OPTION_PERM_MANAGE_USERS = 'ghca_acd_permission_manage_users';

  public static function init(): void {
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
    add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Dashboard_Branding::OPTION, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_LIFESPANS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_WARNING_DAYS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . self::OPTION_PERM_EDIT_RECORDS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . self::OPTION_PERM_MANAGE_ANNOUNCEMENTS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . self::OPTION_PERM_UNRESTRICTED_VIEW, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . self::OPTION_PERM_MANAGE_USERS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_filter( 'ghca_admin_support_email', array( 'GHCA_Dashboard_Branding', 'get_support_email' ) );
    add_filter( 'ghca_employee_support_email', array( 'GHCA_Dashboard_Branding', 'get_support_email' ) );
  }

  public static function bust_dashboard_cache(): void {
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_ghca_acd_agg_' ) . '%';
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $like,
        $wpdb->esc_like( '_transient_timeout_ghca_acd_agg_' ) . '%'
      )
    );
  }

  public static function register_settings(): void {
    register_setting(
      'ghca_acd_settings',
      self::OPTION_AT_RISK_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 120, $value ) );
        },
        'default'           => 30,
      )
    );

    register_setting(
      'ghca_acd_settings',
      self::OPTION_CACHE_TTL,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 0, min( 3600, $value ) );
        },
        'default'           => 300,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_LIFESPANS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( 'GHCA_Course_Lifespans', 'sanitize_lifespan_map' ),
        'default'           => array(),
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Course_Lifespans::OPTION_WARNING_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 365, $value ) );
        },
        'default'           => GHCA_Course_Lifespans::DEFAULT_WARNING_DAYS,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( __CLASS__, 'sanitize_group_ids' ),
        'default'           => array(),
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS,
      array(
        'type'              => 'integer',
        'sanitize_callback' => static function ( $value ): int {
          $value = (int) $value;
          return max( 7, min( 120, $value ) );
        },
        'default'           => GHCA_Compliance_Program::DEFAULT_DEADLINE_DAYS,
      )
    );

    register_setting(
      'ghca_acd_settings',
      GHCA_Dashboard_Branding::OPTION,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( 'GHCA_Dashboard_Branding', 'sanitize' ),
        'default'           => GHCA_Dashboard_Branding::defaults(),
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_EDIT_RECORDS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_MANAGE_ANNOUNCEMENTS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_UNRESTRICTED_VIEW,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );

    register_setting(
      'ghca_acd_permissions',
      self::OPTION_PERM_MANAGE_USERS,
      array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
      )
    );
  }

  /** @param mixed $value @return array<int> */
  public static function sanitize_group_ids( $value ): array {
    if ( ! is_array( $value ) ) {
      return array();
    }

    return array_values( array_unique( array_map( 'intval', $value ) ) );
  }

  public static function register_page(): void {
    add_options_page(
      __( 'Admin Compliance Dashboard', 'ghca-acd' ),
      __( 'Compliance Admin', 'ghca-acd' ),
      'manage_options',
      'ghca-acd-settings',
      array( __CLASS__, 'render_page' )
    );

    add_options_page(
      __( 'Compliance Permissions', 'ghca-acd' ),
      __( 'Compliance Permissions', 'ghca-acd' ),
      'manage_options',
      'ghca-acd-permissions',
      array( __CLASS__, 'render_permissions_page' )
    );
  }

  public static function get_at_risk_days(): int {
    return (int) get_option( self::OPTION_AT_RISK_DAYS, 30 );
  }

  public static function get_cache_ttl(): int {
    return (int) get_option( self::OPTION_CACHE_TTL, 300 );
  }

  /** @return array<int,\WP_Post> */
  private static function get_learndash_groups(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'groups',
        'post_status'            => 'publish',
        'posts_per_page'         => 200,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );

    return is_array( $posts ) ? $posts : array();
  }

  /** @return array<int,\WP_Post> */
  private static function get_published_courses(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'sfwd-courses',
        'post_status'            => 'publish',
        'posts_per_page'         => 500,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );
    return is_array( $posts ) ? $posts : array();
  }

  public static function render_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $at_risk         = self::get_at_risk_days();
    $cache           = self::get_cache_ttl();
    $new_hire_groups = GHCA_Compliance_Program::get_new_hire_group_ids();
    $new_hire_days   = GHCA_Compliance_Program::get_deadline_days();
    $groups          = self::get_learndash_groups();
    $brand           = GHCA_Dashboard_Branding::get();
    $brand_defaults  = GHCA_Dashboard_Branding::defaults();
    $brand_saved     = get_option( GHCA_Dashboard_Branding::OPTION, array() );
    $accent_raw      = is_array( $brand_saved ) ? (string) ( $brand_saved['accent'] ?? '' ) : '';
    $option_name     = GHCA_Dashboard_Branding::OPTION;
    $lifespan_map    = GHCA_Course_Lifespans::get_lifespan_map();
    $warning_days    = GHCA_Course_Lifespans::get_warning_days();
    $all_courses     = self::get_published_courses();
    $lifespan_opt    = GHCA_Course_Lifespans::OPTION_LIFESPANS;
    $warning_opt     = GHCA_Course_Lifespans::OPTION_WARNING_DAYS;
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Admin Compliance Dashboard', 'ghca-acd' ); ?></h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_acd_settings' ); ?>
        <h2><?php esc_html_e( 'New Hire Compliance', 'ghca-acd' ); ?></h2>
        <p><?php esc_html_e( 'Employees in the selected new hire groups must complete all group courses within the deadline. Overdue new hires are flagged on both dashboards.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e( 'New Hire Groups', 'ghca-acd' ); ?></th>
            <td>
              <?php if ( empty( $groups ) ) : ?>
                <p><?php esc_html_e( 'No LearnDash groups found.', 'ghca-acd' ); ?></p>
              <?php else : ?>
                <fieldset>
                  <?php foreach ( $groups as $group ) : ?>
                    <label style="display:block;margin:0 0 8px;">
                      <input
                        type="checkbox"
                        name="<?php echo esc_attr( GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS ); ?>[]"
                        value="<?php echo esc_attr( (string) $group->ID ); ?>"
                        <?php checked( in_array( (int) $group->ID, $new_hire_groups, true ) ); ?>
                      />
                      <?php echo esc_html( $group->post_title ); ?>
                      <code>#<?php echo esc_html( (string) $group->ID ); ?></code>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_new_hire_deadline_days"><?php esc_html_e( 'New hire completion window (days)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="number" min="7" max="120" id="ghca_new_hire_deadline_days" name="<?php echo esc_attr( GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS ); ?>" value="<?php echo esc_attr( (string) $new_hire_days ); ?>" class="small-text" />
              <p class="description"><?php esc_html_e( 'Default: 30 days from new hire group enrollment.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
        </table>

        <h2><?php esc_html_e( 'Dashboard Branding', 'ghca-acd' ); ?></h2>
        <p><?php esc_html_e( 'Shared across the employee and admin compliance dashboards. Semantic alert colors (overdue, warning, success) stay fixed for accessibility.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_brand_primary"><?php esc_html_e( 'Primary color', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_primary_picker" value="<?php echo esc_attr( $brand['primary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_primary" name="<?php echo esc_attr( $option_name ); ?>[primary]" value="<?php echo esc_attr( $brand['primary'] ); ?>" placeholder="<?php echo esc_attr( $brand_defaults['primary'] ); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_secondary"><?php esc_html_e( 'Secondary color', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_secondary_picker" value="<?php echo esc_attr( $brand['secondary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_secondary" name="<?php echo esc_attr( $option_name ); ?>[secondary]" value="<?php echo esc_attr( $brand['secondary'] ); ?>" placeholder="<?php echo esc_attr( $brand_defaults['secondary'] ); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_accent"><?php esc_html_e( 'Accent color (optional)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="color" id="ghca_brand_accent_picker" value="<?php echo esc_attr( $accent_raw !== '' ? $accent_raw : $brand['primary'] ); ?>" />
              <input type="text" class="regular-text code" id="ghca_brand_accent" name="<?php echo esc_attr( $option_name ); ?>[accent]" value="<?php echo esc_attr( $accent_raw ); ?>" placeholder="<?php esc_attr_e( 'Uses primary if empty', 'ghca-acd' ); ?>" />
              <p class="description"><?php esc_html_e( 'Quick link and KPI icons use the primary theme color.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_org_name"><?php esc_html_e( 'Organization name', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_brand_org_name" name="<?php echo esc_attr( $option_name ); ?>[org_name]" value="<?php echo esc_attr( $brand['org_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_logo_url"><?php esc_html_e( 'Logo URL', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="url" class="large-text code" id="ghca_brand_logo_url" name="<?php echo esc_attr( $option_name ); ?>[logo_url]" value="<?php echo esc_attr( $brand['logo_url'] ); ?>" placeholder="https://..." />
              <?php if ( $brand['logo_url'] ) : ?>
                <p><img src="<?php echo esc_url( $brand['logo_url'] ); ?>" alt="" style="max-height:48px;width:auto;margin-top:8px;" /></p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_brand_support_email"><?php esc_html_e( 'Support email', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="email" class="regular-text" id="ghca_brand_support_email" name="<?php echo esc_attr( $option_name ); ?>[support_email]" value="<?php echo esc_attr( $brand['support_email'] ); ?>" />
            </td>
          </tr>
        </table>
        <div style="display:flex;gap:12px;margin:0 0 24px;">
          <span style="display:inline-block;padding:12px 18px;border-radius:8px;background:<?php echo esc_attr( $brand['primary'] ); ?>;color:#fff;font-weight:600;"><?php esc_html_e( 'Primary preview', 'ghca-acd' ); ?></span>
          <span style="display:inline-block;padding:12px 18px;border-radius:8px;background:<?php echo esc_attr( $brand['secondary'] ); ?>;color:#fff;font-weight:600;"><?php esc_html_e( 'Secondary preview', 'ghca-acd' ); ?></span>
        </div>
        <script>
          (function () {
            var pairs = [
              ['ghca_brand_primary_picker', 'ghca_brand_primary'],
              ['ghca_brand_secondary_picker', 'ghca_brand_secondary'],
              ['ghca_brand_accent_picker', 'ghca_brand_accent']
            ];
            pairs.forEach(function (pair) {
              var picker = document.getElementById(pair[0]);
              var input = document.getElementById(pair[1]);
              if (!picker || !input) return;
              picker.addEventListener('input', function () { input.value = picker.value; });
              input.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(input.value)) picker.value = input.value;
              });
            });
          })();
        </script>

        <h2><?php esc_html_e( 'Dashboard Performance', 'ghca-acd' ); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_acd_at_risk_days"><?php esc_html_e( 'At-risk window (days)', 'ghca-acd' ); ?></label></th>
            <td><input type="number" min="7" max="120" id="ghca_acd_at_risk_days" name="<?php echo esc_attr( self::OPTION_AT_RISK_DAYS ); ?>" value="<?php echo esc_attr( (string) $at_risk ); ?>" class="small-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_acd_cache_ttl"><?php esc_html_e( 'Aggregate cache TTL (seconds)', 'ghca-acd' ); ?></label></th>
            <td><input type="number" min="0" max="3600" id="ghca_acd_cache_ttl" name="<?php echo esc_attr( self::OPTION_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) $cache ); ?>" class="small-text" /></td>
          </tr>
        </table>
        <h2><?php esc_html_e( 'Rolling Expirations & Traffic Light', 'ghca-acd' ); ?></h2>
        <p><?php esc_html_e( 'Define how long each course stays valid after completion. A completed course turns yellow inside the warning window and red once it passes its lifespan. Courses with no lifespan never expire.', 'ghca-acd' ); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_acd_warning_days"><?php esc_html_e( 'Warning window (days)', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="number" min="7" max="365" id="ghca_acd_warning_days" name="<?php echo esc_attr( $warning_opt ); ?>" value="<?php echo esc_attr( (string) $warning_days ); ?>" class="small-text" />
              <p class="description"><?php esc_html_e( 'How many days before expiry a course is flagged "Expiring Soon". Default: 90.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Course lifespans', 'ghca-acd' ); ?></th>
            <td>
              <?php if ( empty( $all_courses ) ) : ?>
                <p><?php esc_html_e( 'No published LearnDash courses found.', 'ghca-acd' ); ?></p>
              <?php else : ?>
                <div id="ghca-lifespan-rows">
                  <?php foreach ( $lifespan_map as $cid => $days ) : ?>
                    <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                      <select class="ghca-lifespan-course">
                        <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                        <?php foreach ( $all_courses as $course ) : ?>
                          <option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( (int) $cid, (int) $course->ID ); ?>><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                        <?php endforeach; ?>
                      </select>
                      <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" value="<?php echo esc_attr( (string) $days ); ?>" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                      <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="ghca-lifespan-add"><?php esc_html_e( '+ Add course lifespan', 'ghca-acd' ); ?></button></p>
                <p class="description"><?php esc_html_e( 'Example: CPR = 730 days, HIPAA = 365 days. Rows with no course or 0 days are ignored on save.', 'ghca-acd' ); ?></p>

                <template id="ghca-lifespan-template">
                  <div class="ghca-lifespan-row" style="margin:0 0 8px;display:flex;gap:8px;align-items:center;">
                    <select class="ghca-lifespan-course">
                      <option value="0"><?php esc_html_e( '— Select course —', 'ghca-acd' ); ?></option>
                      <?php foreach ( $all_courses as $course ) : ?>
                        <option value="<?php echo esc_attr( (string) $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?> (#<?php echo esc_html( (string) $course->ID ); ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <input type="number" min="1" max="3650" class="ghca-lifespan-days small-text" placeholder="<?php esc_attr_e( 'days', 'ghca-acd' ); ?>" />
                    <button type="button" class="button ghca-lifespan-remove"><?php esc_html_e( 'Remove', 'ghca-acd' ); ?></button>
                  </div>
                </template>

                <input type="hidden" id="ghca-lifespan-name-base" value="<?php echo esc_attr( $lifespan_opt ); ?>" />
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <script>
          (function () {
            var rows = document.getElementById('ghca-lifespan-rows');
            if (!rows) return;
            var base = document.getElementById('ghca-lifespan-name-base').value;
            var tpl = document.getElementById('ghca-lifespan-template');
            var addBtn = document.getElementById('ghca-lifespan-add');
            var form = rows.closest('form');

            function wire(row) {
              row.querySelector('.ghca-lifespan-remove').addEventListener('click', function () { row.remove(); });
            }
            Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), wire);

            addBtn.addEventListener('click', function () {
              var clone = tpl.content.firstElementChild.cloneNode(true);
              rows.appendChild(clone);
              wire(clone);
            });

            // On submit, materialize each row into name="base[courseId]" = days.
            form.addEventListener('submit', function () {
              Array.prototype.forEach.call(rows.querySelectorAll('.ghca-lifespan-row'), function (row) {
                var cid = parseInt(row.querySelector('.ghca-lifespan-course').value, 10);
                var days = parseInt(row.querySelector('.ghca-lifespan-days').value, 10);
                if (!cid || !days || days < 1) return;
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = base + '[' + cid + ']';
                hidden.value = days;
                form.appendChild(hidden);
              });
            });
          })();
        </script>

        <?php submit_button(); ?>
      </form>
      <p><?php esc_html_e( 'Dashboard URL:', 'ghca-acd' ); ?> <code><?php echo esc_html( GHCA_ACD_Nav::get_dashboard_url() ); ?></code></p>
    </div>
    <?php
  }

  public static function render_permissions_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $edit_records  = get_option( self::OPTION_PERM_EDIT_RECORDS, '' );
    $manage_ann    = get_option( self::OPTION_PERM_MANAGE_ANNOUNCEMENTS, '' );
    $unrestricted  = get_option( self::OPTION_PERM_UNRESTRICTED_VIEW, '' );
    $manage_users  = get_option( self::OPTION_PERM_MANAGE_USERS, '' );
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Compliance Permissions', 'ghca-acd' ); ?></h1>
      <p><?php esc_html_e( 'Enter a comma-separated list of User IDs to grant specific dashboard overrides. These apply on top of standard role limits.', 'ghca-acd' ); ?></p>
      
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_acd_permissions' ); ?>
        
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_perm_edit_records"><?php esc_html_e( 'Edit Training Records', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_edit_records" name="<?php echo esc_attr( self::OPTION_PERM_EDIT_RECORDS ); ?>" value="<?php echo esc_attr( (string) $edit_records ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to manually alter course completion dates and timers.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_manage_ann"><?php esc_html_e( 'Manage Announcements', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_manage_ann" name="<?php echo esc_attr( self::OPTION_PERM_MANAGE_ANNOUNCEMENTS ); ?>" value="<?php echo esc_attr( (string) $manage_ann ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to create, edit, or delete global compliance dashboard announcements.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_unrestricted"><?php esc_html_e( 'Unrestricted View', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_unrestricted" name="<?php echo esc_attr( self::OPTION_PERM_UNRESTRICTED_VIEW ); ?>" value="<?php echo esc_attr( (string) $unrestricted ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to see all employees company-wide, overriding LearnDash group constraints.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_perm_manage_users"><?php esc_html_e( 'Manage Users', 'ghca-acd' ); ?></label></th>
            <td>
              <input type="text" class="regular-text" id="ghca_perm_manage_users" name="<?php echo esc_attr( self::OPTION_PERM_MANAGE_USERS ); ?>" value="<?php echo esc_attr( (string) $manage_users ); ?>" placeholder="e.g. 5, 12, 18" />
              <p class="description"><?php esc_html_e( 'User IDs allowed to add and edit employees from the frontend User Management panel.', 'ghca-acd' ); ?></p>
            </td>
          </tr>
        </table>

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }
}

