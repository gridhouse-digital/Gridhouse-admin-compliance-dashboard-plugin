<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ACD_Settings {
  const OPTION_AT_RISK_DAYS = 'ghca_acd_at_risk_days';
  const OPTION_CACHE_TTL    = 'ghca_acd_cache_ttl';

  public static function init(): void {
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
    add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Dashboard_Branding::OPTION, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_LIFESPANS, array( __CLASS__, 'bust_dashboard_cache' ) );
    add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_WARNING_DAYS, array( __CLASS__, 'bust_dashboard_cache' ) );
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
        <?php submit_button(); ?>
      </form>
      <p><?php esc_html_e( 'Dashboard URL:', 'ghca-acd' ); ?> <code><?php echo esc_html( GHCA_ACD_Nav::get_dashboard_url() ); ?></code></p>
    </div>
    <?php
  }
}
