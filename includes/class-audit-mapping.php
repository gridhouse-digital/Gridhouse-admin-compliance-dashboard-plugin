<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_Mapping {
	const OPTION_NAME = 'ghca_acd_audit_mapping';

	public static function init(): void {
		GHCA_ODP_Applicability::init();
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function register_page(): void {
		add_submenu_page(
			GHCA_ACD_Admin_Menu::SLUG,
			__( 'Audit Mapping', 'ghca-acd' ),
			__( 'Audit Mapping', 'ghca-acd' ),
			'manage_options',
			'ghca-acd-audit-mapping',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'ghca_acd_audit_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mapping' ),
				'default'           => array(),
			)
		);
	}

	public static function enqueue_scripts( $hook ): void {
		if ( strpos( $hook, 'ghca-acd-audit-mapping' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'ghca-acd-audit-mapping-js',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/audit-mapping.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/audit-mapping.js' ),
			true
		);

		wp_add_inline_style( 'common', '
			.ghca-drag-handle { cursor: move; color: #999; text-align: center; }
			.ghca-sortable-placeholder { background: #f0f0f1; height: 50px; border: 1px dashed #ccc; }
		' );
	}

	/**
	 * @param mixed $value
	 * @return array
	 */
	public static function sanitize_mapping( $value ): array {
		$existing = get_option( self::OPTION_NAME, array() );
		$existing = is_array( $existing ) ? $existing : array();
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $value ) ) {
			return $existing;
		}
		if ( ( $_POST['option_page'] ?? '' ) === 'ghca_acd_audit_settings' && ( $_POST['ghca_mapping_complete'] ?? '' ) !== '1' ) {
			add_settings_error( self::OPTION_NAME, 'incomplete_mapping', __( 'The mapping form was incomplete (possibly the server input limit). No mappings were changed.', 'ghca-acd' ) );
			return $existing;
		}

		// Unsubmitted courses retain their mappings; clearing uses an explicit empty selection.
		$sanitized = $existing;
		foreach ( $value as $course_id => $data ) {
			$course_id = intval( $course_id );
			if ( $course_id <= 0 || ! is_array( $data ) ) { continue; }
			$primary = is_string( $data['odp_category'] ?? null ) ? $data['odp_category'] : '';
			$primary = array_key_exists( $primary, self::get_odp_categories() ) ? $primary : '';
			$coverage = self::coverage_categories( array( 'odp_category' => $primary, 'odp_categories' => $data['odp_categories'] ?? array() ) );
			$reference = substr( sanitize_text_field( is_string( $data['odp_mapping_reference'] ?? null ) ? $data['odp_mapping_reference'] : '' ), 0, 500 );
			if ( count( $coverage ) > 1 && '' === $reference ) {
				add_settings_error( self::OPTION_NAME, 'coverage_reference', __( 'Additional ODP coverage requires a course-content reference. The previous mapping for this course was retained.', 'ghca-acd' ) );
				if ( isset( $existing[ $course_id ] ) ) { $sanitized[ $course_id ] = $existing[ $course_id ]; }
				continue;
			}
			$sanitized[ intval( $course_id ) ] = array(
				'odp_category'   => $primary,
				'odp_categories' => $coverage,
				'odp_mapping_reference' => $reference,
				'odp_reviewed_by' => get_current_user_id(),
				'odp_reviewed_at' => time(),
				'oltl_category'  => sanitize_text_field( $existing[ $course_id ]['oltl_category'] ?? '' ),
				'oltl_requirements' => class_exists( 'GHCA_ACD_OLTL_Readiness' ) ? GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( $data['oltl_requirements'] ?? array() ) : array(),
				'credit_hours'   => is_numeric( $data['credit_hours'] ?? null ) && is_finite( (float) $data['credit_hours'] ) ? max( 0, (float) $data['credit_hours'] ) : 0,
				'sort_order'     => intval( $data['sort_order'] ?? 0 ),
				'is_orientation' => ! empty( $data['is_orientation'] ) ? 1 : 0,
			);
		}

		uasort( $sanitized, function( $a, $b ) {
			return ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 );
		} );

		return $sanitized;
	}

	/** Primary category owns the hours; other supported categories receive coverage only. */
	public static function coverage_categories( array $config ): array {
		$primary = $config['odp_category'] ?? '';
		$allowed = self::get_odp_categories();
		if ( ! is_string( $primary ) || '' === $primary || ! isset( $allowed[ $primary ] ) ) { return array(); }
		$categories = array( $primary );
		foreach ( (array) ( $config['odp_categories'] ?? array() ) as $category ) {
			if ( is_string( $category ) && '' !== $category && isset( $allowed[ $category ] ) ) { $categories[] = $category; }
		}
		return array_values( array_unique( $categories ) );
	}

	public static function get_odp_categories(): array {
		return array(
			''                                     => '-- Select ODP Category --',
			'person_centered'                      => 'Application of person-centered practices, community integration, individual choice',
			'abuse_prevention'                     => 'Prevention, detection & reporting of abuse, suspected abuse',
			'individual_rights'                    => 'Individual rights',
			'reporting_incidents'                  => 'Recognizing and reporting incidents',
			'behavior_supports'                    => 'Safe and appropriate use of behavior supports',
			'individual_plan'                      => 'Implementation of the individual plan',
			'job_related'                          => 'Job-related knowledge',
			'general'                              => 'General / Elective',
			'odp_annual_training'                  => 'ODP Annual Training',
		);
	}

	public static function get_oltl_categories(): array {
		return array(
			''                                     => '-- Select OLTL Category --',
			'infection_control'                    => 'Infection Control & Universal Precautions',
			'first_aid'                            => 'First Aid & Emergencies',
			'ethics_boundaries'                    => 'Ethics & Professional Boundaries',
			'adls_personal_care'                   => 'ADLs & Personal Care',
			'general'                              => 'General / Elective',
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mapping_data = get_option( self::OPTION_NAME, array() );
		$odp_cats     = self::get_odp_categories();
		$oltl_requirements = class_exists( 'GHCA_ACD_OLTL_Readiness' ) ? GHCA_ACD_OLTL_Readiness::annual_requirements() : array();

		// Fetch all LearnDash courses
		$courses = get_posts( array(
			'post_type'      => 'sfwd-courses',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		// Merge saved order with existing courses
		$ordered_courses = array();
		$unsaved_courses = array();

		foreach ( $courses as $course ) {
			if ( isset( $mapping_data[ $course->ID ] ) ) {
				$course->ghca_sort_order = $mapping_data[ $course->ID ]['sort_order'];
				$ordered_courses[] = $course;
			} else {
				$unsaved_courses[] = $course;
			}
		}

		usort( $ordered_courses, function( $a, $b ) {
			return $a->ghca_sort_order <=> $b->ghca_sort_order;
		} );

		$display_courses = array_merge( $ordered_courses, $unsaved_courses );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Multi-Framework Audit Mapping', 'ghca-acd' ); ?></h1>
			<?php settings_errors( self::OPTION_NAME ); ?>
			<p><?php esc_html_e( 'Map platform courses to ODP and verified OLTL Chapter 52 training requirements. OLTL mappings are explicit and do not alter ODP calculations.', 'ghca-acd' ); ?></p>
			<p><?php esc_html_e( 'The primary ODP category receives the course hours once. Additional categories record topic coverage only. Review the actual course content, not just its title. Mapping is not individual-plan training evidence. Edit descriptions in the linked LearnDash course; no course content is changed here.', 'ghca-acd' ); ?></p>
			
			<form action="options.php" method="post">
				<?php settings_fields( 'ghca_acd_audit_settings' ); ?>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 40px;"></th>
							<th><?php esc_html_e( 'Course Name', 'ghca-acd' ); ?></th>
							<th style="width: 100px; text-align: center;"><?php esc_html_e( 'Orientation?', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'ODP QA&I Category', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'OLTL Chapter 52 Topics', 'ghca-acd' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Credit Hrs', 'ghca-acd' ); ?></th>
						</tr>
					</thead>
					<tbody id="ghca-audit-mapping-tbody">
						<?php if ( empty( $display_courses ) ) : ?>
							<tr>
								<td colspan="6"><?php esc_html_e( 'No LearnDash courses found.', 'ghca-acd' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $display_courses as $index => $course ) : 
								$c_data = $mapping_data[ $course->ID ] ?? array();
								$odp_val  = $c_data['odp_category'] ?? '';
								$oltl_values = GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( $c_data['oltl_requirements'] ?? array() );
								$credits  = $c_data['credit_hours'] ?? 0;
								$is_orient= ! empty( $c_data['is_orientation'] );
								$order    = $index;
							?>
							<tr class="ghca-mapping-row">
								<td class="ghca-drag-handle" title="Drag to reorder">
									<span class="dashicons dashicons-menu"></span>
									<input type="hidden" class="ghca-sort-order" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][sort_order]" value="<?php echo esc_attr( $order ); ?>" />
								</td>
								<td><strong><?php echo esc_html( $course->post_title ); ?></strong><br>
									<a href="<?php echo esc_url( get_edit_post_link( $course->ID ) ); ?>"><?php esc_html_e( 'Review course content / edit source description', 'ghca-acd' ); ?></a>
									<p><?php echo esc_html( GHCA_Audit_Calculator::course_short_description( (int) $course->ID ) ?: 'Description missing — review the LearnDash source.' ); ?></p>
								</td>
								<td style="text-align: center;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][is_orientation]" value="1" <?php checked( $is_orient, true ); ?> />
								</td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][odp_category]" style="max-width: 100%;">
										<?php foreach ( $odp_cats as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $odp_val, $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<details><summary><?php esc_html_e( 'Additional topic coverage', 'ghca-acd' ); ?></summary>
									<?php foreach ( $odp_cats as $key => $label ) : if ( '' === $key ) { continue; } ?>
									<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][odp_categories][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) ( $c_data['odp_categories'] ?? array() ), true ) ); ?> /><?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
									<label><?php esc_html_e( 'Course-content reference supporting coverage', 'ghca-acd' ); ?><input type="text" maxlength="500" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][odp_mapping_reference]" value="<?php echo esc_attr( $c_data['odp_mapping_reference'] ?? '' ); ?>" /></label>
									</details>
								</td>
								<td>
									<fieldset><?php foreach ( $oltl_requirements as $key => $label ) : ?><label style="display:block;margin-bottom:4px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][oltl_requirements][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $oltl_values, true ) ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
								</td>
								<td>
									<input type="number" step="0.25" min="0" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][credit_hours]" value="<?php echo esc_attr( $credits ); ?>" style="width: 80px;" />
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<input type="hidden" name="ghca_mapping_complete" value="1" />
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
