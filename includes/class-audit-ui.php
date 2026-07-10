<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Audit_UI {
	public static function init(): void {
		add_action( 'wp_ajax_ghca_acd_save_audit_dates', array( __CLASS__, 'ajax_save_audit_dates' ) );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			return '<p>' . esc_html__( 'Unauthorized.', 'ghca-acd' ) . '</p>';
		}

		$url_annual = wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=annual' ), 'ghca_acd_audit_export_csv' );
		$url_orient = wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=orientation' ), 'ghca_acd_audit_export_csv' );
		
		$employees = GHCA_ACD_Data_Provider::get_employee_user_ids();
		
		ob_start();
		?>
		<div class="ghca-acd ghca-acd--audit">
			<div class="ghca-acd__section-head" style="margin-bottom: 24px;">
				<h2><?php esc_html_e( 'Audit Preparation & Data Entry', 'ghca-acd' ); ?></h2>
				<p><?php esc_html_e( 'Enter the real-world milestone dates for your employees so they are automatically included when you generate your audit CSVs.', 'ghca-acd' ); ?></p>
			</div>
			
			<div style="display: flex; gap: 15px; margin-bottom: 30px;">
				<a class="ghca-acd__btn ghca-acd__btn--primary" href="<?php echo esc_url( $url_annual ); ?>">
					<?php echo GHCA_UI_Icons::render( 'download' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Download ODP Annual CSV', 'ghca-acd' ); ?>
				</a>
				<a class="ghca-acd__btn ghca-acd__btn--primary" href="<?php echo esc_url( $url_orient ); ?>">
					<?php echo GHCA_UI_Icons::render( 'download' ); // phpcs:ignore ?>
					<?php esc_html_e( 'Download ODP Orientation CSV', 'ghca-acd' ); ?>
				</a>
			</div>
			
			<div class="ghca-acd__table-wrap">
				<table class="ghca-acd__table ghca-acd__table--audit">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Employee Name', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'Role', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'Date of Hire', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'First Service Date (MM/DD/YYYY)', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'Worked Alone Date (MM/DD/YYYY)', 'ghca-acd' ); ?></th>
							<th><?php esc_html_e( 'Exclude?', 'ghca-acd' ); ?></th>
							<th style="width: 80px;"><?php esc_html_e( 'Packet', 'ghca-acd' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Actions', 'ghca-acd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $employees ) ) : ?>
							<tr>
								<td colspan="8" class="ghca-acd__table-empty"><?php esc_html_e( 'No employees found.', 'ghca-acd' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $employees as $user_id ) : 
								$user = get_userdata( $user_id );
								if ( ! $user ) continue;

								$role = 'Staff';
								if ( ! empty( $user->roles ) ) {
									global $wp_roles;
									$role_slug = $user->roles[0];
									$role = isset( $wp_roles->roles[ $role_slug ] ) ? $wp_roles->roles[ $role_slug ]['name'] : $role_slug;
								}
								
								$first_service = get_user_meta( $user_id, 'ghca_first_service_date', true );
								$worked_alone  = get_user_meta( $user_id, 'ghca_worked_alone_date', true );
								$exclude_user  = get_user_meta( $user_id, 'ghca_audit_exclude', true );

								$doh_ts = GHCA_Compliance_Program::get_enrollment_timestamp( $user_id, GHCA_Compliance_Program::get_user_new_hire_group_ids( $user_id ) );
								if ( ! $doh_ts && $user ) {
									$doh_ts = strtotime( $user->user_registered );
								}
								$doh = $doh_ts ? gmdate( 'm/d/Y', $doh_ts ) : '';
								?>
								<tr data-user-id="<?php echo esc_attr( $user_id ); ?>">
									<td><?php echo esc_html( GHCA_ACD_Data_Provider::get_user_full_name( $user_id, $user ) ); ?></td>
									<td><?php echo esc_html( $role ); ?></td>
									<td style="color: #64748b; font-size: 13px;"><?php echo esc_html( $doh ); ?></td>
									<td>
										<input type="text" class="ghca-acd__input ghca-audit-first-service" placeholder="MM/DD/YYYY" value="<?php echo esc_attr( $first_service ); ?>" style="width:140px;" />
									</td>
									<td>
										<input type="text" class="ghca-acd__input ghca-audit-worked-alone" placeholder="MM/DD/YYYY" value="<?php echo esc_attr( $worked_alone ); ?>" style="width:140px;" />
									</td>
									<td>
										<label style="display:flex; align-items:center; cursor:pointer;">
											<input type="checkbox" class="ghca-audit-exclude" value="1" <?php checked( $exclude_user, '1' ); ?> style="margin-right:5px;"/>
											<span style="font-size:12px;"><?php esc_html_e( 'Skip', 'ghca-acd' ); ?></span>
										</label>
									</td>
									<td style="white-space: nowrap;">
										<a class="ghca-acd__btn ghca-acd__btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_download_packet&tracker=orientation&user_id=' . $user_id ), 'ghca_acd_download_packet' ) ); ?>" title="<?php esc_attr_e( 'Download Orientation Packet', 'ghca-acd' ); ?>" style="padding: 4px 8px; margin-right: 4px;">Ori.</a>
										<a class="ghca-acd__btn ghca-acd__btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_download_packet&tracker=annual&user_id=' . $user_id ), 'ghca_acd_download_packet' ) ); ?>" title="<?php esc_attr_e( 'Download Annual Packet', 'ghca-acd' ); ?>" style="padding: 4px 8px;">Ann.</a>
									</td>
									<td>
										<button type="button" class="ghca-acd__btn ghca-acd__btn--sm ghca-audit-save-btn">
											<?php esc_html_e( 'Save', 'ghca-acd' ); ?>
										</button>
										<span class="ghca-audit-save-status" style="display:none; color: green; font-size: 12px; margin-left: 5px;"><?php esc_html_e( 'Saved', 'ghca-acd' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const saveButtons = document.querySelectorAll('.ghca-audit-save-btn');
			saveButtons.forEach(btn => {
				btn.addEventListener('click', function() {
					const row = this.closest('tr');
					const userId = row.getAttribute('data-user-id');
					const firstService = row.querySelector('.ghca-audit-first-service').value;
					const workedAlone = row.querySelector('.ghca-audit-worked-alone').value;
					const excludeUser = row.querySelector('.ghca-audit-exclude').checked ? '1' : '0';
					const statusSpan = row.querySelector('.ghca-audit-save-status');
					
					this.disabled = true;
					this.textContent = '...';
					
					const data = new FormData();
					data.append('action', 'ghca_acd_save_audit_dates');
					data.append('ghca_nonce', '<?php echo esc_js( wp_create_nonce( "ghca_acd_audit_save" ) ); ?>');
					data.append('user_id', userId);
					data.append('first_service', firstService);
					data.append('worked_alone', workedAlone);
					data.append('exclude_user', excludeUser);
					
					fetch( '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', {
						method: 'POST',
						body: data
					})
					.then(res => res.json())
					.then(res => {
						this.disabled = false;
						this.textContent = 'Save';
						if ( res.success ) {
							statusSpan.style.display = 'inline-block';
							setTimeout(() => { statusSpan.style.display = 'none'; }, 2000);
						} else {
							alert( res.data || 'Error saving.' );
						}
					})
					.catch(err => {
						this.disabled = false;
						this.textContent = 'Save';
						alert( 'Network error.' );
					});
				});
			});
		});
		</script>
		<?php
		return (string) ob_get_clean();
	}

	public static function ajax_save_audit_dates(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Unauthorized.', 'ghca-acd' ) );
		}
		if ( ! check_ajax_referer( 'ghca_acd_audit_save', 'ghca_nonce', false ) ) {
			wp_send_json_error( __( 'Session expired.', 'ghca-acd' ) );
		}
		
		// Writing audit records is an edit, not a view. Gate on edit capability.
		if ( ! GHCA_ACD_Roles::user_can_edit_records() ) {
			wp_send_json_error( __( 'Permission denied.', 'ghca-acd' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		// Confirm the target is inside the caller's visible scope (mirrors every other handler).
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( __( 'Invalid employee or permission denied.', 'ghca-acd' ) );
		}

		// Validate the date shape instead of storing arbitrary strings.
		$first_service = self::sanitize_mdy( $_POST['first_service'] ?? '' );
		$worked_alone  = self::sanitize_mdy( $_POST['worked_alone'] ?? '' );
		$exclude_user  = ! empty( $_POST['exclude_user'] ) ? '1' : '0';

		update_user_meta( $user_id, 'ghca_first_service_date', $first_service );
		update_user_meta( $user_id, 'ghca_worked_alone_date', $worked_alone );
		update_user_meta( $user_id, 'ghca_audit_exclude', $exclude_user );
		
		wp_send_json_success();
	}

	/** Accepts only MM/DD/YYYY (or empty); rejects anything else. */
	private static function sanitize_mdy( $raw ): string {
		$raw = sanitize_text_field( wp_unslash( (string) $raw ) );
		if ( $raw === '' ) {
			return '';
		}
		$d = DateTime::createFromFormat( 'm/d/Y', $raw );
		return ( $d && $d->format( 'm/d/Y' ) === $raw ) ? $raw : '';
	}
}
