<?php
/** OLTL Chapter 52 configuration and scoped employee review UI. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_OLTL_UI {
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 13 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_ghca_acd_oltl_manual_review', array( __CLASS__, 'ajax_manual_review' ) );
	}

	public static function register_page(): void {
		add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'OLTL Training Readiness', 'ghca-acd' ), __( 'OLTL Readiness', 'ghca-acd' ), 'manage_options', 'ghca-acd-oltl-training', array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings(): void {
		register_setting( 'ghca_acd_oltl_settings', GHCA_ACD_OLTL_Readiness::OPTION_ENABLED, array( 'type' => 'string', 'sanitize_callback' => static function ( $value ): string { return empty( $value ) ? '0' : '1'; }, 'default' => '0' ) );
		register_setting( 'ghca_acd_oltl_settings', GHCA_ACD_OLTL_Readiness::OPTION_ROLES, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ), 'default' => array() ) );
		register_setting( 'ghca_acd_oltl_settings', GHCA_ACD_OLTL_Readiness::OPTION_USERS, array( 'type' => 'array', 'sanitize_callback' => array( 'GHCA_ACD_OLTL_Readiness', 'sanitize_ids' ), 'default' => array() ) );
		register_setting( 'ghca_acd_oltl_settings', GHCA_ACD_OLTL_Readiness::OPTION_EXCLUDED_USERS, array( 'type' => 'array', 'sanitize_callback' => array( 'GHCA_ACD_OLTL_Readiness', 'sanitize_ids' ), 'default' => array() ) );
	}

	/** @param mixed $value @return array<int,string> */
	public static function sanitize_roles( $value ): array {
		$known = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
		return array_values( array_intersect( $known, array_unique( array_map( 'sanitize_key', is_array( $value ) ? $value : array() ) ) ) );
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ghca-acd' ) );
		}
		$selected_roles = self::sanitize_roles( get_option( GHCA_ACD_OLTL_Readiness::OPTION_ROLES, array() ) );
		$assigned = GHCA_ACD_OLTL_Readiness::sanitize_ids( get_option( GHCA_ACD_OLTL_Readiness::OPTION_USERS, array() ) );
		$excluded = GHCA_ACD_OLTL_Readiness::sanitize_ids( get_option( GHCA_ACD_OLTL_Readiness::OPTION_EXCLUDED_USERS, array() ) );
		$users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$role_names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OLTL Chapter 52 Training Readiness', 'ghca-acd' ); ?></h1>
			<p><?php esc_html_e( 'Evidence-readiness workflow only. It does not determine legal compliance and does not change ODP calculations or packets.', 'ghca-acd' ); ?></p>
			<p><strong><?php echo esc_html( GHCA_ACD_OLTL_Readiness::SOURCE_CITATION ); ?></strong> — <a href="<?php echo esc_url( GHCA_ACD_OLTL_Readiness::SOURCE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'authoritative source', 'ghca-acd' ); ?></a> (<?php echo esc_html( sprintf( __( 'verified %s', 'ghca-acd' ), GHCA_ACD_OLTL_Readiness::SOURCE_VERIFIED ) ); ?>)</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'ghca_acd_oltl_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Feature', 'ghca-acd' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_OLTL_Readiness::OPTION_ENABLED ); ?>" value="1" <?php checked( GHCA_ACD_OLTL_Readiness::enabled() ); ?> /> <?php esc_html_e( 'Enable OLTL Chapter 52 training readiness', 'ghca-acd' ); ?></label><p class="description"><?php esc_html_e( 'Disabled by default. The existing annual-cycle setting is used only as the agency reporting cycle, not as a statutory OLTL deadline.', 'ghca-acd' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Assigned roles', 'ghca-acd' ); ?></th><td><fieldset><?php foreach ( $role_names as $slug => $label ) : ?><label style="display:block"><input type="checkbox" name="<?php echo esc_attr( GHCA_ACD_OLTL_Readiness::OPTION_ROLES ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_roles, true ) ); ?> /> <?php echo esc_html( translate_user_role( $label ) ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Roles are applied only when explicitly selected here; no role name is treated as OLTL by inference.', 'ghca-acd' ); ?></p></td></tr>
					<tr><th scope="row"><label for="ghca-oltl-users"><?php esc_html_e( 'Individual assigned', 'ghca-acd' ); ?></label></th><td><?php self::render_user_select( 'ghca-oltl-users', GHCA_ACD_OLTL_Readiness::OPTION_USERS, $users, $assigned ); ?><p class="description"><?php esc_html_e( 'An individual assignment overrides role assignment.', 'ghca-acd' ); ?></p></td></tr>
					<tr><th scope="row"><label for="ghca-oltl-excluded-users"><?php esc_html_e( 'Individual not assigned', 'ghca-acd' ); ?></label></th><td><?php self::render_user_select( 'ghca-oltl-excluded-users', GHCA_ACD_OLTL_Readiness::OPTION_EXCLUDED_USERS, $users, $excluded ); ?><p class="description"><?php esc_html_e( 'Not assigned takes precedence over every role and individual assignment.', 'ghca-acd' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** @param array<int,object> $users @param array<int,int> $selected */
	private static function render_user_select( string $id, string $name, array $users, array $selected ): void {
		?><select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[]" multiple size="8" class="regular-text"><?php foreach ( $users as $user ) : ?><option value="<?php echo (int) $user->ID; ?>" <?php selected( in_array( (int) $user->ID, $selected, true ) ); ?>><?php echo esc_html( $user->display_name . ' — ' . $user->user_email . ' (#' . $user->ID . ')' ); ?></option><?php endforeach; ?></select><?php
	}

	public static function render_employee_card( int $user_id ): string {
		if ( ! GHCA_ACD_Roles::user_can_manage_oltl_training() || ! GHCA_ACD_User_Report::can_view_user( $user_id ) || ! GHCA_ACD_OLTL_Readiness::is_assigned( $user_id ) ) {
			return '';
		}
		$data = GHCA_ACD_OLTL_Readiness::evaluate( $user_id );
		if ( is_wp_error( $data ) ) {
			return '';
		}
		if ( ! GHCA_ACD_OLTL_Readiness::event( 'readiness_viewed', get_current_user_id(), $user_id ) ) {
			return '<section class="ghca-acd__oltl-card"><p>' . esc_html__( 'OLTL training readiness is temporarily unavailable because its access event could not be recorded.', 'ghca-acd' ) . '</p></section>';
		}
		$labels = array(
			'satisfied' => __( 'Satisfied', 'ghca-acd' ), 'due' => __( 'Due', 'ghca-acd' ), 'expired' => __( 'Expired', 'ghca-acd' ),
			'missing_evidence' => __( 'Missing Evidence', 'ghca-acd' ), 'manual_review' => __( 'Manual Review', 'ghca-acd' ), 'not_applicable' => __( 'Not Applicable', 'ghca-acd' ),
			'evidence_ready' => __( 'Evidence Ready', 'ghca-acd' ), 'action_needed' => __( 'Action Needed', 'ghca-acd' ), 'manual_review_required' => __( 'Manual Review Required', 'ghca-acd' ),
		);
		$manual = $data['manual_review'];
		ob_start();
		?>
		<section class="ghca-acd__oltl-card" data-ghca-oltl-card aria-labelledby="ghca-acd-oltl-title-<?php echo (int) $user_id; ?>">
			<header class="ghca-acd__oltl-header"><div><h4 id="ghca-acd-oltl-title-<?php echo (int) $user_id; ?>"><?php esc_html_e( 'OLTL Training Readiness', 'ghca-acd' ); ?></h4><p><?php echo esc_html( sprintf( __( 'Agency reporting window: %1$s–%2$s', 'ghca-acd' ), $data['start_date'] ?: __( 'Unavailable', 'ghca-acd' ), $data['end_date'] ?: __( 'Unavailable', 'ghca-acd' ) ) ); ?></p></div><span class="ghca-acd__oltl-status ghca-acd__oltl-status--<?php echo esc_attr( $data['overall_status'] ); ?>"><?php echo esc_html( $labels[ $data['overall_status'] ] ); ?></span></header>
			<div class="ghca-acd__oltl-matrix" role="list">
				<?php foreach ( $data['requirements'] as $requirement ) : ?><div class="ghca-acd__oltl-row" role="listitem"><div><strong><?php echo esc_html( $requirement['label'] ); ?></strong><?php if ( $requirement['source'] || $requirement['date'] ) : ?><small><?php echo esc_html( trim( $requirement['source'] . ( $requirement['date'] ? ' · ' . $requirement['date'] : '' ) ) ); ?></small><?php endif; ?><?php if ( $requirement['note'] ) : ?><small><?php echo esc_html( $requirement['note'] ); ?></small><?php endif; ?></div><span class="ghca-acd__oltl-status ghca-acd__oltl-status--<?php echo esc_attr( $requirement['status'] ); ?>"><?php echo esc_html( $labels[ $requirement['status'] ] ); ?></span></div><?php endforeach; ?>
			</div>
			<div class="ghca-acd__oltl-manual">
				<h5><?php esc_html_e( 'Participant/service-plan training review', 'ghca-acd' ); ?></h5>
				<form class="ghca-acd__oltl-manual-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ghca_acd_oltl_manual_review" /><input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ghca_acd_table' ) ); ?>" /><input type="hidden" name="employee_id" value="<?php echo (int) $user_id; ?>" /><input type="hidden" name="operation" value="review" />
					<label><?php esc_html_e( 'Service/training description', 'ghca-acd' ); ?><textarea required maxlength="4000" name="service_description"></textarea></label>
					<div class="ghca-acd__oltl-fields"><label><?php esc_html_e( 'Training date', 'ghca-acd' ); ?><input required type="date" name="training_date" /></label><label><?php esc_html_e( 'Decision', 'ghca-acd' ); ?><select required name="decision"><option value="satisfied"><?php esc_html_e( 'Satisfied', 'ghca-acd' ); ?></option><option value="not_applicable"><?php esc_html_e( 'Not Applicable', 'ghca-acd' ); ?></option></select></label></div>
					<label><?php esc_html_e( 'Rationale', 'ghca-acd' ); ?><textarea required maxlength="4000" name="rationale"></textarea></label>
					<label><?php esc_html_e( 'Evidence reference', 'ghca-acd' ); ?><input required maxlength="191" name="evidence_reference" /></label>
					<label><?php esc_html_e( 'Supporting file (optional PDF, JPEG or PNG)', 'ghca-acd' ); ?><input type="file" name="evidence_file" accept="application/pdf,image/jpeg,image/png" /></label>
					<p class="ghca-acd__oltl-form-status" aria-live="polite"></p><button type="submit" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary"><?php esc_html_e( 'Save Manual Review', 'ghca-acd' ); ?></button>
				</form>
				<?php if ( $manual && 'revoked' !== $manual['decision'] ) : ?><form class="ghca-acd__oltl-manual-form ghca-acd__oltl-revoke-form"><input type="hidden" name="action" value="ghca_acd_oltl_manual_review" /><input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ghca_acd_table' ) ); ?>" /><input type="hidden" name="employee_id" value="<?php echo (int) $user_id; ?>" /><input type="hidden" name="operation" value="revoke" /><label><?php esc_html_e( 'Revocation reason', 'ghca-acd' ); ?><input required maxlength="1000" name="reason" /></label><button type="submit" class="ghca-acd__drawer-action ghca-acd__drawer-action--secondary"><?php esc_html_e( 'Revoke Current Review', 'ghca-acd' ); ?></button></form><?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function ajax_manual_review(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$user_id = isset( $_POST['employee_id'] ) ? absint( $_POST['employee_id'] ) : 0;
		$actor_id = get_current_user_id();
		if ( ! is_ssl() || ! GHCA_ACD_Roles::user_can_manage_oltl_training() || ! GHCA_ACD_User_Report::can_view_user( $user_id ) || ! GHCA_ACD_OLTL_Readiness::is_assigned( $user_id ) ) {
			GHCA_ACD_OLTL_Readiness::event( 'manual_review_access', $actor_id, $user_id, 0, 'denied', 'access_denied' );
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ghca-acd' ) ), 403 );
		}
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'revoke' === $operation ) {
			$result = GHCA_ACD_OLTL_Readiness::revoke_manual_review( $user_id, substr( sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ), 0, 1000 ), $actor_id );
		} else {
			$manifest = null;
			$capture_id = bin2hex( random_bytes( 16 ) );
			if ( isset( $_FILES['evidence_file'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['evidence_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				$manifest = GHCA_ACD_External_Evidence_Store::capture_manual_upload( $_FILES['evidence_file'], $user_id, $capture_id );
				if ( is_wp_error( $manifest ) ) {
					GHCA_ACD_OLTL_Readiness::event( 'manual_evidence_upload', $actor_id, $user_id, 0, 'failed', $manifest->get_error_code() );
					wp_send_json_error( array( 'message' => $manifest->get_error_message() ), 400 );
				}
			}
			$result = GHCA_ACD_OLTL_Readiness::record_manual_review(
				$user_id,
				sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ),
				substr( sanitize_textarea_field( wp_unslash( $_POST['service_description'] ?? '' ) ), 0, 4000 ),
				sanitize_text_field( wp_unslash( $_POST['training_date'] ?? '' ) ),
				substr( sanitize_textarea_field( wp_unslash( $_POST['rationale'] ?? '' ) ), 0, 4000 ),
				substr( sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) ), 0, 191 ),
				is_array( $manifest ) ? $manifest : null,
				$actor_id
			);
			if ( is_wp_error( $result ) && is_array( $manifest ) ) {
				GHCA_ACD_External_Evidence_Store::remove_manual_file( (string) ( $manifest['storage_path'] ?? '' ) );
			}
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'html' => self::render_employee_card( $user_id ) ) );
	}
}
