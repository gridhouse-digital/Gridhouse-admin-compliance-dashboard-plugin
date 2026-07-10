<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_Mapping {
	const OPTION_NAME = 'ghca_acd_audit_mapping';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function register_page(): void {
		add_options_page(
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
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $course_id => $data ) {
			$sanitized[ intval( $course_id ) ] = array(
				'odp_category'   => sanitize_text_field( $data['odp_category'] ?? '' ),
				'oltl_category'  => sanitize_text_field( $data['oltl_category'] ?? '' ),
				'credit_hours'   => floatval( $data['credit_hours'] ?? 0 ),
				'sort_order'     => intval( $data['sort_order'] ?? 0 ),
				'is_orientation' => isset( $data['is_orientation'] ) ? 1 : 0,
			);
		}

		uasort( $sanitized, function( $a, $b ) {
			return $a['sort_order'] <=> $b['sort_order'];
		} );

		return $sanitized;
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
		$oltl_cats    = self::get_oltl_categories();

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
			<p><?php esc_html_e( 'Map your existing LearnDash courses to specific state compliance frameworks (ODP & OLTL). Drag and drop courses to set their display order in the generated Compliance Packet.', 'ghca-acd' ); ?></p>
			
			<form action="options.php" method="post">
				<?php settings_fields( 'ghca_acd_audit_settings' ); ?>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 40px;"></th>
							<th><?php esc_html_e( 'Course Name', 'ghca-acd' ); ?></th>
							<th style="width: 100px; text-align: center;"><?php esc_html_e( 'Orientation?', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'ODP QA&I Category', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'OLTL Caregiver Category', 'ghca-acd' ); ?></th>
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
								$oltl_val = $c_data['oltl_category'] ?? '';
								$credits  = $c_data['credit_hours'] ?? 0;
								$is_orient= ! empty( $c_data['is_orientation'] );
								$order    = $index;
							?>
							<tr class="ghca-mapping-row">
								<td class="ghca-drag-handle" title="Drag to reorder">
									<span class="dashicons dashicons-menu"></span>
									<input type="hidden" class="ghca-sort-order" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][sort_order]" value="<?php echo esc_attr( $order ); ?>" />
								</td>
								<td><strong><?php echo esc_html( $course->post_title ); ?></strong></td>
								<td style="text-align: center;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][is_orientation]" value="1" <?php checked( $is_orient, true ); ?> />
								</td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][odp_category]" style="max-width: 100%;">
										<?php foreach ( $odp_cats as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $odp_val, $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][oltl_category]" style="max-width: 100%;">
										<?php foreach ( $oltl_cats as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $oltl_val, $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<input type="number" step="0.25" min="0" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $course->ID ); ?>][credit_hours]" value="<?php echo esc_attr( $credits ); ?>" style="width: 80px;" />
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
