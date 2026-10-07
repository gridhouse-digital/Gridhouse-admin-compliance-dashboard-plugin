<?php
/**
 * Administrator configuration, review workflow and scoped document access UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Jotform_UI {
	const NONCE = 'ghca_acd_jotform_admin';
	const TOKEN_PREFIX = 'ghca_acd_jotform_access_';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 12 );
		foreach ( array( 'save_settings', 'test_connection', 'sync_now', 'rerun_backfill', 'save_catalog', 'training_action', 'bulk_reject_unsigned' ) as $action ) {
			add_action( 'admin_post_ghca_acd_jotform_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_ghca_acd_jotform_documents', array( __CLASS__, 'ajax_documents' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_document_token', array( __CLASS__, 'ajax_document_token' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_document_access', array( __CLASS__, 'stream_document' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_ownership_claim', array( __CLASS__, 'ajax_ownership_claim' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_manual_upload', array( __CLASS__, 'ajax_manual_upload' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_manual_token', array( __CLASS__, 'ajax_manual_evidence_token' ) );
		add_action( 'wp_ajax_ghca_acd_jotform_manual_access', array( __CLASS__, 'stream_manual_evidence' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_ownership_bridge' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
	}

	public static function admin_menu(): void {
		add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'Jotform Documents', 'ghca-acd' ), __( 'Jotform Documents', 'ghca-acd' ), 'manage_options', 'ghca-acd-jotform', array( __CLASS__, 'render_settings' ) );
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'External Training Review', 'ghca-acd' ), __( 'External Training Review', 'ghca-acd' ), 'manage_options', 'ghca-acd-external-training', array( __CLASS__, 'render_review' ) );
		}
		add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'External Training Catalog', 'ghca-acd' ), __( 'Training Catalog', 'ghca-acd' ), 'manage_options', 'ghca-acd-external-catalog', array( __CLASS__, 'render_catalog' ) );
	}

	public static function enqueue(): void {
		if ( is_admin() && ( ! isset( $_GET['page'] ) || 'ghca-acd-external-training' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) ) {
			return;
		}
		/*
		 * The admin guard above never fires on the front end, so these assets
		 * previously loaded on EVERY front-end page for any logged-in user
		 * holding the capability. Mirror the dashboard's own gate so they load
		 * only where the shortcode actually renders. The drawer's Documents tab
		 * is fetched by AJAX from a dashboard page, so it stays supplied.
		 *
		 * Note: enqueue_ownership_bridge() below is deliberately NOT gated --
		 * it bridges Jotform submissions on arbitrary pages.
		 */
		if ( ! is_admin() ) {
			if ( ! is_singular() ) {
				return;
			}
			$post = get_post();
			if ( ! $post || ! GHCA_Admin_Compliance_Dashboard::page_uses_dashboard( $post ) ) {
				return;
			}
		}
		if ( ! is_user_logged_in() || ( ! GHCA_ACD_Roles::user_can_view_employee_documents() && ! GHCA_ACD_Roles::user_can_review_external_training() ) ) {
			return;
		}
		$plugin_file = dirname( dirname( __DIR__ ) ) . '/gridhouse-admin-compliance-dashboard.php';
		$asset_dir = dirname( dirname( __DIR__ ) ) . '/assets/';
		wp_enqueue_style( 'ghca-acd-jotform', plugin_dir_url( $plugin_file ) . 'assets/jotform.css', array(), (string) filemtime( $asset_dir . 'jotform.css' ) );
		wp_enqueue_script( 'ghca-acd-jotform', plugin_dir_url( $plugin_file ) . 'assets/jotform.js', array(), (string) filemtime( $asset_dir . 'jotform.js' ), true );
		wp_localize_script( 'ghca-acd-jotform', 'ghcaAcdJotform', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ghca_acd_jotform_documents' ), 'empty' => __( 'No indexed documents were found.', 'ghca-acd' ), 'error' => __( 'Documents could not be loaded.', 'ghca-acd' ) ) );
	}

	public static function enqueue_ownership_bridge(): void {
		if ( ! is_user_logged_in() || ! GHCA_ACD_Jotform_Repository::enabled() ) {
			return;
		}
		$plugin_file = dirname( dirname( __DIR__ ) ) . '/gridhouse-admin-compliance-dashboard.php';
		wp_enqueue_script( 'ghca-acd-jotform-ownership', plugin_dir_url( $plugin_file ) . 'assets/jotform-ownership.js', array(), GHCA_Admin_Compliance_Dashboard::VERSION, true );
		wp_localize_script( 'ghca-acd-jotform-ownership', 'ghcaAcdJotformOwnershipConfig', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ghca_acd_jotform_ownership' ), 'formIds' => array_values( array_map( static function ( array $form ): string { return (string) $form['form_id']; }, GHCA_ACD_Jotform_Repository::forms() ) ) ) );
	}

	public static function render_recent_documents( int $employee_id ): string {
		if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_view_employee_documents() || ! self::employee_in_scope( $employee_id ) ) {
			return '';
		}
		$records = self::employee_record_rows( $employee_id );
		$documents = array_slice( $records, 0, 3 );
		$training = GHCA_ACD_Jotform_Repository::training_for_employee( $employee_id, 3 );
		$total = GHCA_ACD_Jotform_Repository::employee_record_count( $employee_id );
		$employee = get_userdata( $employee_id );
		$employee_name = $employee ? (string) $employee->display_name : '';
		ob_start();
		?>
		<section class="ghca-acd__jotform-documents" data-ghca-documents-section="<?php echo esc_attr( (string) $employee_id ); ?>">
			<header class="ghca-acd__jotform-drawer-head"><div><span aria-hidden="true">▱</span><h5><?php esc_html_e( 'Indexed Records', 'ghca-acd' ); ?></h5><strong><?php echo esc_html( sprintf( _n( '%d item', '%d items', $total, 'ghca-acd' ), $total ) ); ?></strong></div></header>
			<?php if ( empty( $documents ) ) : ?>
				<p class="ghca-acd__jotform-empty"><?php esc_html_e( 'No employee documents found.', 'ghca-acd' ); ?></p>
			<?php else : ?>
				<ul class="ghca-acd__jotform-recent">
					<?php foreach ( $documents as $document ) : ?>
						<?php echo self::record_row_markup( $document ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="ghca-acd__jotform-foot">
				<?php if ( ! empty( $training ) ) : ?>
					<p class="ghca-acd__jotform-status"><span><?php esc_html_e( 'External training', 'ghca-acd' ); ?></span> <?php echo esc_html( self::external_training_summary_label( $employee_id ) ); ?></p>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<button type="button" class="ghca-acd__jotform-view-all" data-ghca-documents-all="<?php echo esc_attr( (string) $employee_id ); ?>" data-ghca-documents-name="<?php echo esc_attr( $employee_name ); ?>"><?php esc_html_e( 'View all documents', 'ghca-acd' ); ?> <span aria-hidden="true">→</span></button>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Administrator manual entry: upload a certificate obtained outside Jotform for one
	 * employee. The file is stored encrypted immediately, but the record lands in External
	 * Training Review as pending; approval there is the only path that makes it count.
	 */
	public static function render_manual_entry_form( int $employee_id ): string {
		if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_review_external_training() || $employee_id <= 0 || ! self::employee_in_scope( $employee_id ) || ! GHCA_ACD_Jotform_Schema::tables_exist() ) {
			return '';
		}
		$ready   = GHCA_ACD_External_Evidence_Store::readiness();
		$catalog = GHCA_ACD_Jotform_Repository::catalog( true );
		$entries = GHCA_ACD_Jotform_Repository::manual_training_for_employee( $employee_id, 20 );
		ob_start();
		?>
		<section class="ghca-acd__manual-entry" data-ghca-manual-entry="<?php echo esc_attr( (string) $employee_id ); ?>">
			<h5><?php esc_html_e( 'Add external certificate (manual entry)', 'ghca-acd' ); ?></h5>
			<p class="ghca-acd__manual-entry-help"><?php esc_html_e( 'For certificates received outside Jotform (email, paper scan, shared drive). The file is stored encrypted and must still be approved in External Training Review before it counts toward annual compliance.', 'ghca-acd' ); ?></p>
			<?php if ( is_wp_error( $ready ) ) : ?>
				<p class="ghca-acd__manual-entry-status"><?php echo esc_html( $ready->get_error_message() ); ?></p>
			<?php elseif ( empty( $catalog ) ) : ?>
				<p class="ghca-acd__manual-entry-status"><?php esc_html_e( 'No active catalog lessons exist. Add lessons under Tools → External Training Catalog first.', 'ghca-acd' ); ?></p>
			<?php else : ?>
			<form class="ghca-acd__manual-entry-form" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ghca_acd_jotform_manual_upload" /><input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ghca_acd_table' ) ); ?>" /><input type="hidden" name="employee_id" value="<?php echo (int) $employee_id; ?>" />
				<label><?php esc_html_e( 'Catalog lesson', 'ghca-acd' ); ?><select required name="catalog_id"><option value=""><?php esc_html_e( 'Select lesson', 'ghca-acd' ); ?></option><?php foreach ( $catalog as $item ) : ?><option value="<?php echo (int) $item['id']; ?>"><?php echo esc_html( $item['title'] . ' (' . $item['credit_hours'] . ' hrs)' ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Completion date', 'ghca-acd' ); ?><input required type="date" name="completion_date" /></label>
				<label><?php esc_html_e( 'Reason for manual entry (where the certificate came from)', 'ghca-acd' ); ?><textarea required maxlength="1000" name="entry_reason"></textarea></label>
				<label><?php esc_html_e( 'Certificate file (PDF, JPEG or PNG)', 'ghca-acd' ); ?><input required type="file" name="certificate_file" accept="application/pdf,image/jpeg,image/png" /></label>
				<p class="ghca-acd__manual-entry-status" aria-live="polite"></p>
				<button type="submit" class="ghca-acd__drawer-action ghca-acd__drawer-action--primary"><?php esc_html_e( 'Save for review', 'ghca-acd' ); ?></button>
			</form>
			<?php endif; ?>
			<?php if ( ! empty( $entries ) ) : ?>
				<p class="ghca-acd__manual-entry-help"><?php echo esc_html( sprintf( _n( '%d manually entered certificate is listed under Indexed Records above.', '%d manually entered certificates are listed under Indexed Records above.', count( $entries ), 'ghca-acd' ), count( $entries ) ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function ajax_manual_upload(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$employee_id = isset( $_POST['employee_id'] ) ? absint( $_POST['employee_id'] ) : 0;
		$actor_id    = get_current_user_id();
		if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_review_external_training() || $employee_id <= 0 || ! get_userdata( $employee_id ) || ! self::employee_in_scope( $employee_id ) ) {
			GHCA_ACD_Jotform_Repository::event( 'training_manual_entry', $actor_id, $employee_id, array(), 'denied', 'access_denied' );
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ghca-acd' ) ), 403 );
		}
		$catalog_id = isset( $_POST['catalog_id'] ) ? absint( $_POST['catalog_id'] ) : 0;
		$catalog    = $catalog_id > 0 ? GHCA_ACD_Jotform_Repository::catalog_item( $catalog_id ) : null;
		$date       = isset( $_POST['completion_date'] ) ? GHCA_ACD_Jotform_Repository::sanitize_date( sanitize_text_field( wp_unslash( $_POST['completion_date'] ) ) ) : null;
		$reason     = isset( $_POST['entry_reason'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['entry_reason'] ) ), 0, 1000 ) : '';
		if ( ! $catalog || empty( $catalog['active'] ) || (float) $catalog['credit_hours'] <= 0 || '' === (string) $catalog['category'] ) {
			wp_send_json_error( array( 'message' => __( 'Select an active catalog lesson that has credit hours and a category.', 'ghca-acd' ) ), 400 );
		}
		if ( ! $date || strtotime( $date . ' 12:00:00 UTC' ) > time() + DAY_IN_SECONDS ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid completion date that is not in the future.', 'ghca-acd' ) ), 400 );
		}
		if ( '' === trim( $reason ) ) {
			wp_send_json_error( array( 'message' => __( 'Explain where the certificate came from before saving it.', 'ghca-acd' ) ), 400 );
		}
		if ( ! isset( $_FILES['certificate_file'] ) || ! is_array( $_FILES['certificate_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Attach a PDF, JPEG or PNG certificate.', 'ghca-acd' ) ), 400 );
		}
		$prepared = GHCA_ACD_External_Evidence_Store::prepare_manual_upload( $_FILES['certificate_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $prepared ) ) {
			GHCA_ACD_Jotform_Repository::event( 'training_manual_entry', $actor_id, $employee_id, array(), 'failed', $prepared->get_error_code() );
			wp_send_json_error( array( 'message' => $prepared->get_error_message() ), 400 );
		}
		$fingerprint = GHCA_ACD_External_Evidence_Store::manual_fingerprint( $employee_id, (string) $prepared['pdf'] );
		if ( GHCA_ACD_Jotform_Repository::manual_fingerprint_exists( $employee_id, $fingerprint ) ) {
			wp_send_json_error( array( 'message' => __( 'This exact certificate file is already pending or approved for this employee.', 'ghca-acd' ) ), 409 );
		}
		$capture_id     = bin2hex( random_bytes( 16 ) );
		$warning_detail = GHCA_ACD_Jotform_Repository::probable_duplicate_exists( $employee_id, $catalog_id, $date, $capture_id ) ? __( 'Possible duplicate: this employee already has a record for the same lesson and completion date.', 'ghca-acd' ) : '';
		$training_id    = GHCA_ACD_Jotform_Repository::insert_manual_training( $employee_id, $catalog, $date, $fingerprint, $capture_id, $reason, $actor_id, $warning_detail );
		$training       = $training_id > 0 ? GHCA_ACD_Jotform_Repository::training( $training_id ) : null;
		if ( ! $training ) {
			GHCA_ACD_Jotform_Repository::event( 'training_manual_entry', $actor_id, $employee_id, array(), 'failed', 'training_insert_failed' );
			wp_send_json_error( array( 'message' => __( 'The manual entry could not be recorded. Retry later.', 'ghca-acd' ) ), 500 );
		}
		$manifest = GHCA_ACD_External_Evidence_Store::store_manual_training( $training, (string) $prepared['pdf'], (string) $prepared['name'] );
		unset( $prepared );
		if ( is_wp_error( $manifest ) ) {
			GHCA_ACD_Jotform_Repository::delete_unfinished_manual_training( $training_id );
			GHCA_ACD_Jotform_Repository::event( 'training_manual_entry', $actor_id, $employee_id, array( 'training_id' => $training_id ), 'failed', $manifest->get_error_code() );
			wp_send_json_error( array( 'message' => $manifest->get_error_message() ), 500 );
		}
		if ( ! GHCA_ACD_Jotform_Repository::attach_manual_manifest( $training_id, $manifest ) || ! GHCA_ACD_Jotform_Repository::event( 'training_manual_entry', $actor_id, $employee_id, array( 'training_id' => $training_id ) ) ) {
			/* Fail closed: without the manifest and its audit event the row must not exist. */
			GHCA_ACD_External_Evidence_Store::remove_files( array_column( $manifest, 'storage_path' ) );
			GHCA_ACD_Jotform_Repository::delete_unfinished_manual_training( $training_id );
			wp_send_json_error( array( 'message' => __( 'The manual entry could not be finalized or audited. Nothing was saved; retry later.', 'ghca-acd' ) ), 500 );
		}
		wp_send_json_success( array( 'html' => self::render_manual_entry_form( $employee_id ) ) );
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this connection.', 'ghca-acd' ) );
		}
		$forms = GHCA_ACD_Jotform_Repository::forms();
		$forms[] = array();
		$state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() );
		$metrics = get_option( 'ghca_acd_jotform_last_metrics', array() );
		self::notice();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Jotform Documents', 'ghca-acd' ); ?></h1>
		<p><?php esc_html_e( 'The shared JOTFORM_API_KEY is read only from wp-config.php and is never saved here. The feature remains disabled until you enable it.', 'ghca-acd' ); ?></p>
		<p><strong><?php esc_html_e( 'Compatibility:', 'ghca-acd' ); ?></strong> <?php esc_html_e( 'Standard and EU API regions are supported. Forms that require an interactive Jotform login can keep Jotform as their signed metadata source and select a private Google Drive evidence folder for certificate bytes.', 'ghca-acd' ); ?></p>
		<p><strong><?php esc_html_e( 'Employee ownership:', 'ghca-acd' ); ?></strong> <?php esc_html_e( 'Automatic employee linking requires a Jotform hidden field mapped as the ownership-claim QID. The course embed must request a signed claim through the authenticated ghcaAcdJotformOwnership browser helper and submit both the returned userId and claim. Missing or invalid claims are quarantined for manual reviewer binding.', 'ghca-acd' ); ?></p>
		<table class="widefat striped"><tbody>
		<tr><th><?php esc_html_e( 'API region', 'ghca-acd' ); ?></th><td><?php echo esc_html( GHCA_ACD_Jotform_Provider::region() ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Last sync', 'ghca-acd' ); ?></th><td><?php echo esc_html( (string) get_option( GHCA_ACD_Jotform_Repository::OPTION_LAST_SYNC, __( 'Never', 'ghca-acd' ) ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Remaining allowance', 'ghca-acd' ); ?></th><td><?php echo esc_html( (string) get_option( GHCA_ACD_Jotform_Provider::OPTION_QUOTA_LEFT, __( 'Unknown', 'ghca-acd' ) ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Sync state', 'ghca-acd' ); ?></th><td><?php echo GHCA_ACD_Jotform_Provider::quota_paused() ? esc_html__( 'Paused at quota safety floor', 'ghca-acd' ) : esc_html__( 'Available', 'ghca-acd' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Last sync metrics', 'ghca-acd' ); ?></th><td><?php echo esc_html( sprintf( 'New: %d; updated: %d; unchanged: %d; quarantined: %d; failed: %d; read: %d; pages: %d', (int) ( $metrics['new'] ?? 0 ), (int) ( $metrics['updated'] ?? 0 ), (int) ( $metrics['unchanged'] ?? 0 ), (int) ( $metrics['quarantined'] ?? 0 ), (int) ( $metrics['failed'] ?? 0 ), (int) ( $metrics['read'] ?? 0 ), (int) ( $metrics['pages'] ?? 0 ) ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Backfill progress', 'ghca-acd' ); ?></th><td><code><?php echo esc_html( wp_json_encode( $state ) ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Legacy workflow review', 'ghca-acd' ); ?></th><td><?php echo esc_html( (string) get_option( GHCA_ACD_Jotform_Schema::OPTION_LEGACY_REVIEW, 0 ) ); ?> <?php esc_html_e( 'potentially inconsistent retained rows', 'ghca-acd' ); ?></td></tr>
		</tbody></table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ghca_acd_jotform_save_settings" /><?php wp_nonce_field( self::NONCE ); ?>
		<p><label><input type="checkbox" name="enabled" value="1" <?php checked( (bool) get_option( GHCA_ACD_Jotform_Repository::OPTION_ENABLED, 0 ) ); ?> /> <?php esc_html_e( 'Enable Jotform document indexing and external training', 'ghca-acd' ); ?></label></p>
		<p><label><?php esc_html_e( 'Region', 'ghca-acd' ); ?> <select name="region"><option value="standard" <?php selected( GHCA_ACD_Jotform_Provider::region(), 'standard' ); ?>>Standard</option><option value="eu" <?php selected( GHCA_ACD_Jotform_Provider::region(), 'eu' ); ?>>EU</option></select></label></p>
		<h2><?php esc_html_e( 'Allowed forms and question mappings', 'ghca-acd' ); ?></h2>
		<table class="widefat striped"><thead><tr><th>Label</th><th>Form ID</th><th>user_id QID</th><th>Ownership claim QID</th><th>Course title QID</th><th>Completion date QID</th><th>Certificate QID</th><th>Training code QID</th><th>Email QID</th><th>Name QID</th><th>File source</th><th>Evidence reference QID</th><th>Drive folder ID</th></tr></thead><tbody>
		<?php foreach ( $forms as $i => $form ) : ?><tr>
		<?php foreach ( array( 'label', 'form_id', 'user_id_qid', 'ownership_claim_qid', 'course_title_qid', 'completion_date_qid', 'certificate_qid', 'training_code_qid', 'employee_email_qid', 'employee_name_qid' ) as $field ) : ?><td><input class="small-text" type="text" name="forms[<?php echo (int) $i; ?>][<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( (string) ( $form[ $field ] ?? '' ) ); ?>" /></td><?php endforeach; ?>
		<td><select name="forms[<?php echo (int) $i; ?>][file_source]"><option value="jotform" <?php selected( (string) ( $form['file_source'] ?? 'jotform' ), 'jotform' ); ?>>Jotform</option><option value="google_drive" <?php selected( (string) ( $form['file_source'] ?? '' ), 'google_drive' ); ?>>Google Drive</option></select></td><td><input class="small-text" type="text" name="forms[<?php echo (int) $i; ?>][evidence_reference_qid]" value="<?php echo esc_attr( (string) ( $form['evidence_reference_qid'] ?? '' ) ); ?>" /></td><td><input class="regular-text" type="text" name="forms[<?php echo (int) $i; ?>][drive_folder_id]" value="<?php echo esc_attr( (string) ( $form['drive_folder_id'] ?? '' ) ); ?>" /></td>
		</tr><?php endforeach; ?>
		</tbody></table><?php submit_button( __( 'Save Jotform Configuration', 'ghca-acd' ) ); ?></form>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_jotform_test_connection' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Test Connection', 'ghca-acd' ); ?></a> <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_jotform_sync_now' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Sync Now', 'ghca-acd' ); ?></a> <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ghca_acd_jotform_rerun_backfill' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Re-run Historical Backfill', 'ghca-acd' ); ?></a></p></div>
		<?php
	}

	public static function handle_save_settings(): void {
		self::require_admin();
		$old_forms = GHCA_ACD_Jotform_Repository::forms();
		$old_hashes = array(); foreach ( $old_forms as $old_form ) { $old_hashes[ (string) $old_form['form_id'] ] = hash( 'sha256', wp_json_encode( $old_form ) ); }
		$raw_forms = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? wp_unslash( $_POST['forms'] ) : array();
		$forms = array();
		foreach ( $raw_forms as $raw ) {
			if ( ! is_array( $raw ) || ! preg_match( '/^[0-9]{6,32}$/', (string) ( $raw['form_id'] ?? '' ) ) ) {
				continue;
			}
			$form = array();
			foreach ( array( 'form_id', 'user_id_qid', 'ownership_claim_qid', 'course_title_qid', 'completion_date_qid', 'certificate_qid', 'training_code_qid', 'employee_email_qid', 'employee_name_qid', 'evidence_reference_qid' ) as $field ) {
				$value = trim( sanitize_text_field( (string) ( $raw[ $field ] ?? '' ) ) );
				$form[ $field ] = 'form_id' === $field ? ( preg_match( '/^[0-9]{6,32}$/', $value ) ? $value : '' ) : ( preg_match( '/^[0-9]{0,10}$/', $value ) ? $value : '' );
			}
			$form['label'] = substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 191 );
			$form['file_source'] = isset( $raw['file_source'] ) && 'google_drive' === $raw['file_source'] ? 'google_drive' : 'jotform';
			$form['drive_folder_id'] = trim( sanitize_text_field( (string) ( $raw['drive_folder_id'] ?? '' ) ) );
			if ( 'google_drive' === $form['file_source'] && ! preg_match( '/^[A-Za-z0-9_-]{10,200}$/', $form['drive_folder_id'] ) ) {
				continue;
			}
			if ( 'jotform' === $form['file_source'] ) {
				$form['drive_folder_id'] = '';
			}
			if ( '' !== $form['user_id_qid'] && '' !== $form['course_title_qid'] && '' !== $form['completion_date_qid'] && '' !== $form['certificate_qid'] ) {
				$forms[ $form['form_id'] ] = $form;
			}
		}
		update_option( GHCA_ACD_Jotform_Repository::OPTION_FORMS, array_values( $forms ), false );
		$enabled = empty( $_POST['enabled'] ) ? 0 : 1;
		update_option( GHCA_ACD_Jotform_Repository::OPTION_ENABLED, $enabled, false );
		update_option( GHCA_ACD_Jotform_Provider::OPTION_REGION, isset( $_POST['region'] ) && 'eu' === $_POST['region'] ? 'eu' : 'standard', false );
		$state = get_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, array() ); $state = is_array( $state ) ? $state : array(); $next_state = array();
		foreach ( $forms as $form_id => $form ) { if ( isset( $old_hashes[ $form_id ], $state[ $form_id ] ) && hash_equals( $old_hashes[ $form_id ], hash( 'sha256', wp_json_encode( $form ) ) ) ) { $next_state[ $form_id ] = $state[ $form_id ]; } }
		update_option( GHCA_ACD_Jotform_Repository::OPTION_SYNC_STATE, $next_state, false );
		if ( $enabled ) { GHCA_ACD_Jotform_Sync::ensure_schedule(); } else { GHCA_ACD_Jotform_Sync::clear_schedule(); }
		self::redirect( 'settings_saved' );
	}

	public static function handle_test_connection(): void {
		self::require_admin();
		$result = GHCA_ACD_Jotform_Provider::test_connection();
		if ( is_wp_error( $result ) ) { self::redirect( 'error_' . sanitize_key( $result->get_error_code() ) ); }
		foreach ( GHCA_ACD_Jotform_Repository::forms() as $form ) {
			if ( 'google_drive' === (string) ( $form['file_source'] ?? '' ) ) {
				$drive = GHCA_ACD_Google_Drive_Provider::diagnose_root( $form );
				if ( is_wp_error( $drive ) ) { self::redirect( 'error_' . sanitize_key( $drive->get_error_code() ) ); }
			}
		}
		self::redirect( 'connection_ready' );
	}

	public static function handle_sync_now(): void {
		self::require_admin();
		$last = (int) get_transient( 'ghca_acd_jotform_manual_sync' );
		if ( $last ) {
			self::redirect( 'sync_rate_limited' );
		}
		set_transient( 'ghca_acd_jotform_manual_sync', time(), 60 );
		$result = GHCA_ACD_Jotform_Sync::run( true );
		self::redirect( is_wp_error( $result ) ? 'error_' . sanitize_key( $result->get_error_code() ) : 'sync_new_' . (int) $result['metrics']['new'] . '_updated_' . (int) $result['metrics']['updated'] . '_unchanged_' . (int) $result['metrics']['unchanged'] . '_quarantined_' . (int) $result['metrics']['quarantined'] . '_failed_' . (int) $result['metrics']['failed'] );
	}

	public static function handle_rerun_backfill(): void {
		self::require_admin();
		if ( get_transient( 'ghca_acd_jotform_backfill_reset' ) ) { self::redirect( 'backfill_rate_limited' ); }
		if ( ! GHCA_ACD_Jotform_Repository::event( 'historical_backfill_reset', get_current_user_id(), 0 ) ) { self::redirect( 'backfill_audit_failed' ); }
		set_transient( 'ghca_acd_jotform_backfill_reset', 1, 5 * MINUTE_IN_SECONDS );
		GHCA_ACD_Jotform_Sync::reset_historical_backfill();
		self::redirect( 'backfill_reset' );
	}

	public static function render_catalog(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'ghca-acd' ) ); }
		$edit_id = isset( $_GET['edit_catalog'] ) ? absint( $_GET['edit_catalog'] ) : 0;
		$editing = $edit_id > 0 ? GHCA_ACD_Jotform_Repository::catalog_item( $edit_id ) : null;
		$editing = is_array( $editing ) ? $editing : array();
		$categories = GHCA_Audit_Mapping::get_odp_categories();
		$oltl_requirements = GHCA_ACD_OLTL_Readiness::annual_requirements();
		$selected_oltl = GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( json_decode( (string) ( $editing['oltl_requirements'] ?? '[]' ), true ) );
		self::notice();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'External Training Catalog', 'ghca-acd' ); ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ghca_acd_jotform_save_catalog" /><input type="hidden" name="id" value="<?php echo (int) $edit_id; ?>" /><?php wp_nonce_field( self::NONCE ); ?>
		<table class="form-table"><tr><th>Stable training code</th><td><input required name="training_code" value="<?php echo esc_attr( (string) ( $editing['training_code'] ?? '' ) ); ?>" /></td></tr><tr><th>Parent course</th><td><input name="parent_title" value="<?php echo esc_attr( (string) ( $editing['parent_title'] ?? '' ) ); ?>" /></td></tr><tr><th>Lesson title</th><td><input required name="title" value="<?php echo esc_attr( (string) ( $editing['title'] ?? '' ) ); ?>" /></td></tr><tr><th>Existing-title aliases</th><td><textarea name="aliases" placeholder="One alias per line"><?php echo esc_textarea( (string) ( $editing['aliases'] ?? '' ) ); ?></textarea></td></tr><tr><th>Provider</th><td><input required name="provider" value="<?php echo esc_attr( (string) ( $editing['provider'] ?? '' ) ); ?>" /></td></tr><tr><th>Full description</th><td><textarea required name="description" rows="5" class="large-text"><?php echo esc_textarea( (string) ( $editing['description'] ?? '' ) ); ?></textarea></td></tr><tr><th>ODP category</th><td><select required name="category"><option value="">Select category</option><?php foreach ( $categories as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $editing['category'] ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr><tr><th>OLTL Chapter 52 topics</th><td><fieldset><?php foreach ( $oltl_requirements as $key => $label ) : ?><label style="display:block"><input type="checkbox" name="oltl_requirements[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_oltl, true ) ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset></td></tr><tr><th>Credit hours</th><td><input required type="number" min="0.01" max="100" step="0.01" name="credit_hours" value="<?php echo esc_attr( (string) ( $editing['credit_hours'] ?? '' ) ); ?>" /></td></tr><tr><th>Active</th><td><label><input type="checkbox" name="active" value="1" <?php checked( ! $editing || ! empty( $editing['active'] ) ); ?> /> Active</label></td></tr></table><?php submit_button( $edit_id ? __( 'Update Catalog Lesson', 'ghca-acd' ) : __( 'Add Catalog Lesson', 'ghca-acd' ) ); ?></form>
		<h2><?php esc_html_e( 'Catalog lessons', 'ghca-acd' ); ?></h2><table class="widefat striped"><thead><tr><th>Code</th><th>Parent</th><th>Lesson</th><th>Provider</th><th>ODP Category</th><th>OLTL Topics</th><th>Hours</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ( GHCA_ACD_Jotform_Repository::catalog() as $item ) : $item_oltl = GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( json_decode( (string) ( $item['oltl_requirements'] ?? '[]' ), true ) ); ?><tr><td><code><?php echo esc_html( $item['training_code'] ); ?></code></td><td><?php echo esc_html( $item['parent_title'] ); ?></td><td><?php echo esc_html( $item['title'] ); ?></td><td><?php echo esc_html( $item['provider'] ); ?></td><td><?php echo esc_html( $item['category'] ); ?></td><td><?php echo esc_html( implode( ', ', array_map( static function ( string $code ) use ( $oltl_requirements ): string { return (string) ( $oltl_requirements[ $code ] ?? $code ); }, $item_oltl ) ) ); ?></td><td><?php echo esc_html( $item['credit_hours'] ); ?></td><td><?php echo $item['active'] ? esc_html__( 'Active', 'ghca-acd' ) : esc_html__( 'Inactive', 'ghca-acd' ); ?></td><td><a href="<?php echo esc_url( GHCA_ACD_Admin_Menu::url( 'ghca-acd-external-catalog', array( 'edit_catalog' => (string) (int) $item['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'ghca-acd' ); ?></a></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
	}

	public static function handle_save_catalog(): void {
		self::require_admin();
		$id = GHCA_ACD_Jotform_Repository::save_catalog_item( wp_unslash( $_POST ), get_current_user_id() );
		self::redirect( $id > 0 ? 'catalog_saved' : 'catalog_failed', 'ghca-acd-external-catalog' );
	}

	public static function render_review(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'ghca-acd' ) ); }
		echo self::review_markup( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_frontend_review(): string {
		return GHCA_ACD_Roles::user_can_review_external_training() ? self::review_markup( true ) : '';
	}

	private static function review_markup( bool $frontend ): string {
		$catalog = GHCA_ACD_Jotform_Repository::catalog( true );
		$ready = GHCA_ACD_External_Evidence_Store::readiness();
		$page = isset( $_GET['review_page'] ) ? max( 1, absint( $_GET['review_page'] ) ) : 1;
		$filter = isset( $_GET['review_filter'] ) && 'unsigned' === sanitize_key( wp_unslash( $_GET['review_filter'] ) ) ? 'unsigned' : 'all';
		$pending = GHCA_ACD_Jotform_Repository::pending_training_page( 25, ( $page - 1 ) * 25, $filter );
		$return_url = $frontend ? add_query_arg( 'ghca_tab', 'external-training', get_permalink() ?: home_url( '/' ) ) : '';
		$review_base = $frontend ? $return_url : GHCA_ACD_Admin_Menu::url( 'ghca-acd-external-training' );
		ob_start();
		?>
		<div class="<?php echo esc_attr( $frontend ? 'ghca-acd__external-review' : 'wrap' ); ?>"><h1><?php esc_html_e( 'External Training Review', 'ghca-acd' ); ?></h1><p><?php esc_html_e( 'Approval creates an immutable encrypted certificate copy. External training is annual-only unless explicitly approved as applicable to orientation.', 'ghca-acd' ); ?></p>
		<?php self::notice( $frontend ); ?>
		<?php if ( is_wp_error( $ready ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $ready->get_error_message() ); ?></p></div><?php endif; ?>
		<p><a href="<?php echo esc_url( add_query_arg( array( 'review_filter' => 'all', 'review_page' => 1 ), $review_base ) ); ?>">All pending</a> | <a href="<?php echo esc_url( add_query_arg( array( 'review_filter' => 'unsigned', 'review_page' => 1 ), $review_base ) ); ?>">Unsigned only</a></p>
		<?php if ( ! $frontend ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ghca_acd_jotform_bulk_reject_unsigned" /><?php wp_nonce_field( self::NONCE ); ?><input required maxlength="1000" name="reason" placeholder="Required reason for unsigned pending records" /><label>At least <input required type="number" min="1" max="3650" name="age_days" value="7" /> days old</label><button class="button" name="limit" value="25"><?php esc_html_e( 'Reject up to 25 unsigned pending records', 'ghca-acd' ); ?></button></form><?php endif; ?>
		<table class="widefat striped"><thead><tr><th>Employee</th><th>Submitted lesson</th><th>Date</th><th>Warning</th><th>Decision</th></tr></thead><tbody>
		<?php foreach ( $pending['items'] as $row ) : $user = get_userdata( (int) $row['employee_user_id'] ); $certificates = GHCA_ACD_Jotform_Repository::documents_for_training( $row ); $references = array_values( array_unique( array_filter( array_map( static function ( array $document ): string { return (string) ( $document['evidence_reference'] ?? '' ); }, $certificates ) ) ) ); $form = GHCA_ACD_Jotform_Repository::form( (string) $row['form_id'] ); ?>
		<tr><td><?php echo esc_html( $user ? $user->display_name . ' (#' . $user->ID . ')' : __( 'Unresolved', 'ghca-acd' ) ); ?></td><td><?php echo esc_html( $row['proposed_title'] ); ?></td><td><?php echo esc_html( (string) $row['proposed_completion_date'] ); ?></td><td><?php echo esc_html( self::warning_label( (string) $row['warning_code'] ) ); ?><?php if ( ! empty( $row['warning_detail'] ) ) : ?><br /><small><?php echo esc_html( (string) $row['warning_detail'] ); ?></small><?php endif; ?></td><td>
		<?php $manual_manifest = GHCA_ACD_Jotform_Repository::manual_manifest( $row ); if ( GHCA_ACD_Jotform_Repository::is_manual( $row ) ) : $entered_by = get_userdata( (int) ( $row['entered_by'] ?? 0 ) ); ?><div class="ghca-acd__review-certificates ghca-acd__review-certificates--manual"><strong><?php esc_html_e( 'Manual entry:', 'ghca-acd' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Entered by %1$s on %2$s', 'ghca-acd' ), $entered_by ? (string) $entered_by->display_name : '#' . (int) ( $row['entered_by'] ?? 0 ), (string) $row['created_at'] ) ); ?></span><span><?php esc_html_e( 'Reason:', 'ghca-acd' ); ?> <?php echo esc_html( (string) ( $row['entry_reason'] ?? '' ) ); ?></span><?php if ( $manual_manifest ) : foreach ( $manual_manifest as $manual_item ) : ?><span><?php echo esc_html( (string) ( $manual_item['file_name'] ?? '' ) ); ?> (<?php echo esc_html( size_format( (int) ( $manual_item['bytes'] ?? 0 ) ) ); ?>, SHA-256 <?php echo esc_html( substr( (string) ( $manual_item['sha256'] ?? '' ), 0, 12 ) ); ?>...)</span><?php endforeach; else : ?><span><strong><?php esc_html_e( 'Stored certificate manifest is missing. Reject this entry and upload it again.', 'ghca-acd' ); ?></strong></span><?php endif; ?></div><?php elseif ( $certificates && 1 === count( $references ) ) : ?><div class="ghca-acd__review-certificates"><strong><?php esc_html_e( 'Expected evidence:', 'ghca-acd' ); ?></strong><span><?php echo esc_html( 'Evidence-' . (string) $references[0] ); ?></span><?php foreach ( $certificates as $certificate ) : ?><span><?php echo esc_html( (string) $certificate['file_name'] ); ?> <button type="button" class="button button-small" data-ghca-document-open="<?php echo (int) $certificate['id']; ?>" data-training-id="<?php echo (int) $row['id']; ?>" data-action="preview"><?php esc_html_e( 'Preview', 'ghca-acd' ); ?></button> <button type="button" class="button button-small" data-ghca-document-open="<?php echo (int) $certificate['id']; ?>" data-training-id="<?php echo (int) $row['id']; ?>" data-action="download"><?php esc_html_e( 'Download', 'ghca-acd' ); ?></button></span><?php endforeach; ?></div><?php elseif ( $certificates && 0 === count( $references ) ) : ?><p><strong><?php esc_html_e( 'Configured evidence reference is invalid or unavailable. Re-sync before review.', 'ghca-acd' ); ?></strong></p><?php elseif ( $certificates ) : ?><p><strong><?php esc_html_e( 'Evidence reference metadata is inconsistent. Re-sync before review.', 'ghca-acd' ); ?></strong></p><?php else : ?><p><strong><?php esc_html_e( 'No indexed certificate is available.', 'ghca-acd' ); ?></strong></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ghca_acd_jotform_training_action" /><input type="hidden" name="training_id" value="<?php echo (int) $row['id']; ?>" /><?php if ( $frontend ) : ?><input type="hidden" name="frontend_review" value="1" /><input type="hidden" name="return_url" value="<?php echo esc_url( $return_url ); ?>" /><?php endif; ?><?php wp_nonce_field( self::NONCE ); ?>
		<?php $catalog_match = GHCA_ACD_Jotform_Repository::catalog_match_result( (string) $row['proposed_code'], (string) $row['proposed_title'] ); $matched_catalog_id = 'matched' === (string) $catalog_match['status'] ? (int) ( $catalog_match['item']['id'] ?? 0 ) : 0; ?><select name="catalog_id" required data-ghca-catalog-select data-match-catalog-id="<?php echo $matched_catalog_id; ?>"><option value="">Select lesson</option><?php foreach ( $catalog as $item ) : ?><option value="<?php echo (int) $item['id']; ?>" <?php selected( (int) $row['catalog_id'], (int) $item['id'] ); ?>><?php echo esc_html( $item['title'] . ' (' . $item['credit_hours'] . ' hrs)' ); ?></option><?php endforeach; ?></select>
<label><input type="checkbox" name="applies_to_orientation" value="1" /> <?php esc_html_e( 'Also applies to orientation for this employee', 'ghca-acd' ); ?></label>
		<p hidden role="alert" data-ghca-catalog-warning><?php esc_html_e( 'Selected catalog lesson does not match the submitted title/code or configured alias. Provide an override reason before approval.', 'ghca-acd' ); ?></p><label><?php esc_html_e( 'Employee ID', 'ghca-acd' ); ?> <input type="number" min="1" name="employee_id" value="<?php echo (int) $row['employee_user_id']; ?>" required /></label><input type="date" name="completion_date" value="<?php echo esc_attr( (string) $row['proposed_completion_date'] ); ?>" required /><label>Mapping override reason <input maxlength="1000" name="override_reason" data-ghca-override-reason placeholder="Required when selected lesson differs" /></label><label><input type="checkbox" name="employee_confirmed" value="1" required /> Employee confirmed</label><button class="button" name="decision" value="preflight" formnovalidate>PDF preflight</button><button class="button button-primary" name="decision" value="approve" <?php disabled( is_wp_error( $ready ) ); ?>>Approve</button><button class="button" name="decision" value="reject" formnovalidate>Reject</button></form>
		</td></tr><?php endforeach; ?></tbody></table>
		<?php if ( $pending['total'] > 25 ) : ?><p><?php echo esc_html( sprintf( 'Page %d of %d', $page, (int) ceil( $pending['total'] / 25 ) ) ); ?> <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'review_filter' => $filter, 'review_page' => $page - 1 ), $review_base ) ); ?>">Previous</a><?php endif; ?> <?php if ( $page * 25 < $pending['total'] ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'review_filter' => $filter, 'review_page' => $page + 1 ), $review_base ) ); ?>">Next</a><?php endif; ?></p><?php endif; ?>
		<h2><?php esc_html_e( 'Active approved evidence', 'ghca-acd' ); ?></h2><table class="widefat striped"><thead><tr><th>Employee</th><th>Lesson</th><th>Completed</th><th>Approved</th><th>Revoke</th></tr></thead><tbody>
		<?php foreach ( GHCA_ACD_Jotform_Repository::active_approved_training() as $row ) : $user = get_userdata( (int) $row['employee_user_id'] ); $snapshot = json_decode( (string) $row['catalog_snapshot'], true ); ?><tr><td><?php echo esc_html( $user ? $user->display_name : '#' . $row['employee_user_id'] ); ?></td><td><?php echo esc_html( (string) ( $snapshot['title'] ?? $row['proposed_title'] ) ); ?></td><td><?php echo esc_html( $row['proposed_completion_date'] ); ?></td><td><?php echo esc_html( $row['approved_at'] ); ?></td><td><?php echo self::orientation_review_form( $row, $snapshot, $frontend, $return_url ); ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ghca_acd_jotform_training_action" /><input type="hidden" name="training_id" value="<?php echo (int) $row['id']; ?>" /><?php if ( $frontend ) : ?><input type="hidden" name="frontend_review" value="1" /><input type="hidden" name="return_url" value="<?php echo esc_url( $return_url ); ?>" /><?php endif; ?><?php wp_nonce_field( self::NONCE ); ?><input required maxlength="1000" name="reason" placeholder="Required revocation reason" /><button class="button" name="decision" value="revoke"><?php esc_html_e( 'Revoke', 'ghca-acd' ); ?></button></form></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
		return (string) ob_get_clean();
	}

	private static function orientation_review_form( array $row, array $snapshot, bool $frontend, string $return_url ): string {
		ob_start(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ghca_acd_jotform_training_action" />
			<input type="hidden" name="training_id" value="<?php echo (int) $row['id']; ?>" />
			<input type="hidden" name="revision_id" value="<?php echo (int) $row['approved_revision_id']; ?>" />
			<?php if ( $frontend ) : ?><input type="hidden" name="frontend_review" value="1" /><input type="hidden" name="return_url" value="<?php echo esc_url( $return_url ); ?>" /><?php endif; ?>
			<?php wp_nonce_field( self::NONCE ); ?>
			<label><input type="checkbox" name="applies_to_orientation" value="1" <?php checked( true === ( $snapshot['applies_to_orientation'] ?? false ) ); ?> /> <?php esc_html_e( 'Applies to orientation for this employee', 'ghca-acd' ); ?></label>
			<label><?php esc_html_e( 'Orientation review reason', 'ghca-acd' ); ?> <input name="orientation_reason" maxlength="1000" required /></label>
			<button class="button" name="decision" value="orientation"><?php esc_html_e( 'Save orientation applicability', 'ghca-acd' ); ?></button>
		</form>
		<?php return (string) ob_get_clean();
	}

	public static function handle_training_action(): void {
		if ( ! self::secure_transport() || ! GHCA_ACD_Roles::user_can_review_external_training() ) { wp_die( esc_html__( 'HTTPS and External Training Review access are required.', 'ghca-acd' ) ); }
		check_admin_referer( self::NONCE );
		$id = isset( $_POST['training_id'] ) ? absint( $_POST['training_id'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$training = GHCA_ACD_Jotform_Repository::training( $id );
		if ( ! $training ) { self::review_redirect( 'training_missing' ); }
		$orientation_value = $_POST['applies_to_orientation'] ?? '0';
		if ( ! in_array( $orientation_value, array( '0', '1' ), true ) ) { self::review_redirect( 'approval_blocked' ); }
		if ( 'orientation' === $decision ) {
			$reason = is_string( $_POST['orientation_reason'] ?? null ) ? sanitize_textarea_field( wp_unslash( $_POST['orientation_reason'] ) ) : '';
			$revision_id = is_scalar( $_POST['revision_id'] ?? null ) ? absint( $_POST['revision_id'] ) : 0;
			$ok = GHCA_ACD_Jotform_Repository::set_orientation_applicability( $id, $revision_id, '1' === $orientation_value, get_current_user_id(), $reason );
			self::review_redirect( $ok ? 'training_approved' : 'approval_failed' );
		}
		if ( 'revoke' === $decision ) {
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
			$ok = GHCA_ACD_Jotform_Repository::revoke_evidence( $id, get_current_user_id(), $reason );
			self::review_redirect( $ok ? 'training_revoked' : 'revocation_failed' );
		}
		if ( 'reject' === $decision ) {
			$manual_manifest = GHCA_ACD_Jotform_Repository::manual_manifest( $training );
			$ok = GHCA_ACD_Jotform_Repository::reject_pending_training( $id, get_current_user_id() );
			/* A rejected manual entry was never evidence; its encrypted copy has no further purpose. */
			if ( $ok && $manual_manifest ) { GHCA_ACD_External_Evidence_Store::remove_files( array_column( $manual_manifest, 'storage_path' ) ); }
			self::review_redirect( $ok ? 'training_rejected' : 'rejection_failed' );
		}
		if ( 'preflight' === $decision ) {
			$form = GHCA_ACD_Jotform_Repository::form( (string) $training['form_id'] );
			if ( GHCA_ACD_Jotform_Repository::is_manual( $training ) ) {
				$manual_manifest = GHCA_ACD_Jotform_Repository::manual_manifest( $training );
				$preflight = $manual_manifest ? GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $manual_manifest ) : new WP_Error( 'ghca_evidence_missing', __( 'No stored certificate is attached to this manual entry.', 'ghca-acd' ) );
			} else {
				$preflight = $form ? GHCA_ACD_External_Evidence_Store::preflight( $training, $form ) : new WP_Error( 'ghca_evidence_form_missing', __( 'The configured form is unavailable.', 'ghca-acd' ) );
			}
			$event = GHCA_ACD_Jotform_Repository::event( 'training_pdf_preflight', get_current_user_id(), (int) $training['employee_user_id'], array( 'training_id' => $id ), is_wp_error( $preflight ) ? 'failed' : 'success', is_wp_error( $preflight ) ? $preflight->get_error_code() : '' );
			self::review_redirect( ! $event ? 'preflight_audit_failed' : ( is_wp_error( $preflight ) ? 'replacement_required_reexport_pdf' : 'pdf_preflight_passed' ) );
		}
		if ( 'approve' !== $decision ) { self::review_redirect( 'approval_blocked' ); }
		$employee_id = isset( $_POST['employee_id'] ) ? absint( $_POST['employee_id'] ) : 0;
		$catalog_id = isset( $_POST['catalog_id'] ) ? absint( $_POST['catalog_id'] ) : 0;
		$date = isset( $_POST['completion_date'] ) ? ( GHCA_ACD_Jotform_Repository::sanitize_date( sanitize_text_field( wp_unslash( $_POST['completion_date'] ) ) ) ?: '' ) : '';
		$catalog = GHCA_ACD_Jotform_Repository::catalog_item( $catalog_id );
		$form = GHCA_ACD_Jotform_Repository::form( (string) $training['form_id'] );
		$match = GHCA_ACD_Jotform_Repository::catalog_match_result( (string) $training['proposed_code'], (string) $training['proposed_title'] );
		$override_reason = isset( $_POST['override_reason'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['override_reason'] ) ), 0, 1000 ) : '';
		$mapping_matches = 'matched' === (string) $match['status'] && (int) ( $match['item']['id'] ?? 0 ) === $catalog_id;
		$is_manual = GHCA_ACD_Jotform_Repository::is_manual( $training );
		if ( empty( $_POST['employee_confirmed'] ) || $employee_id <= 0 || ! get_userdata( $employee_id ) || ! $catalog || empty( $catalog['active'] ) || ( ! $form && ! $is_manual ) || (float) $catalog['credit_hours'] <= 0 || '' === (string) $catalog['category'] || ( ! $mapping_matches && '' === trim( $override_reason ) ) || ! GHCA_ACD_Jotform_Repository::confirm_pending_training( $id, $employee_id, $catalog_id, $date ) ) { self::review_redirect( 'approval_blocked' ); }
		$training = GHCA_ACD_Jotform_Repository::training( $id );
		$capture_training = $training;
		$capture_training['employee_user_id'] = $employee_id;
		$capture_training['catalog_id'] = $catalog_id;
		$capture_training['proposed_completion_date'] = $date;
		if ( $is_manual ) {
			/* Manual bytes are already local: re-verify the stored copy instead of fetching Jotform. */
			$manifest = GHCA_ACD_Jotform_Repository::manual_manifest( $training );
			$verified = $manifest ? GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $manifest ) : new WP_Error( 'ghca_evidence_missing', __( 'No stored certificate is attached to this manual entry.', 'ghca-acd' ) );
			if ( is_wp_error( $verified ) ) { $manifest = $verified; }
		} else {
			$manifest = GHCA_ACD_External_Evidence_Store::capture( $capture_training, $form );
		}
		if ( is_wp_error( $manifest ) ) { GHCA_ACD_Jotform_Repository::event( 'training_approval', get_current_user_id(), (int) $training['employee_user_id'], array( 'training_id' => $id ), 'failed', $manifest->get_error_code() ); self::review_redirect( 'error_' . sanitize_key( $manifest->get_error_code() ) ); }
		$snapshot = array( 'catalog_id' => $catalog_id, 'training_code' => (string) $catalog['training_code'], 'parent_title' => (string) $catalog['parent_title'], 'title' => (string) $catalog['title'], 'provider' => (string) $catalog['provider'], 'description' => wp_strip_all_tags( (string) $catalog['description'] ), 'category' => (string) $catalog['category'], 'oltl_requirements' => GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( json_decode( (string) ( $catalog['oltl_requirements'] ?? '[]' ), true ) ), 'credit_hours' => (float) $catalog['credit_hours'], 'completion_date' => $date, 'catalog_override_reason' => $mapping_matches ? '' : $override_reason, 'entry_method' => $is_manual ? 'manual' : 'jotform', 'entered_by' => $is_manual ? (int) ( $training['entered_by'] ?? 0 ) : 0, 'entry_reason' => $is_manual ? (string) ( $training['entry_reason'] ?? '' ) : '' );
		$snapshot['applies_to_orientation'] = '1' === $orientation_value;
		$revision = GHCA_ACD_Jotform_Repository::approve( $id, $snapshot, $manifest, get_current_user_id(), $employee_id, $date, $is_manual ? 'manual_entry' : ( $mapping_matches ? '' : 'catalog_override' ) );
		/* A manual entry's stored file is its pending evidence, so it survives a failed approval for retry. */
		if ( $revision <= 0 ) { if ( ! $is_manual ) { GHCA_ACD_External_Evidence_Store::remove_files( array_column( $manifest, 'storage_path' ) ); } self::review_redirect( 'approval_failed' ); }
		self::review_redirect( 'training_approved' );
	}

	public static function handle_bulk_reject_unsigned(): void {
		self::require_admin();
		$reason = isset( $_POST['reason'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ), 0, 1000 ) : '';
		$age_days = isset( $_POST['age_days'] ) ? max( 1, min( 3650, absint( $_POST['age_days'] ) ) ) : 7;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
		$reason_digest = hash( 'sha256', $reason );
		$ids = GHCA_ACD_Jotform_Repository::reject_unsigned_pending( isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 25, $reason, $cutoff, get_current_user_id(), $reason_digest );
		self::redirect( $ids ? 'unsigned_rejected_' . count( $ids ) : 'unsigned_rejection_failed', 'ghca-acd-external-training' );
	}

	/**
	 * Merges indexed Jotform documents and manual entries into one display list.
	 *
	 * Manual entries are deliberately absent from the Jotform documents table so they cannot
	 * affect synchronization or its scoped queries. They are joined here, at the display layer,
	 * so the drawer shows an employee's complete record set.
	 *
	 * @return array<int,array<string,mixed>> Newest first.
	 */
	public static function employee_record_rows( int $employee_id, string $search = '' ): array {
		$rows = array();

		foreach ( GHCA_ACD_Jotform_Repository::all_documents_for_employee( $employee_id, $search ) as $document ) {
			$status = sanitize_key( (string) ( $document['review_status'] ?? '' ) );
			$rows[] = array(
				'kind'       => 'jotform',
				'id'         => (int) $document['id'],
				'name'       => (string) $document['file_name'],
				'context'    => (string) ( $document['form_title'] ?? '' ),
				'media_type' => (string) ( $document['media_type'] ?? '' ),
				'timestamp'  => strtotime( (string) $document['source_created_at'] ) ?: 0,
				'status'     => '' === $status ? __( 'General document', 'ghca-acd' ) : self::review_status_label( $status ),
				'status_key' => $status,
				'viewable'   => true,
			);
		}

		$needle = strtolower( trim( $search ) );
		foreach ( GHCA_ACD_Jotform_Repository::manual_training_for_employee( $employee_id, 50 ) as $training ) {
			$manifest = GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $training );
			$item     = is_array( $manifest ) ? $manifest[0] : null;
			$status   = (string) $training['status'];
			$name     = null !== $item ? (string) ( $item['file_name'] ?? '' ) : __( 'Certificate removed', 'ghca-acd' );
			$context  = (string) $training['proposed_title'];
			if ( '' !== $needle && false === strpos( strtolower( $name . ' ' . $context ), $needle ) ) {
				continue;
			}
			$rows[] = array(
				'kind'       => 'manual',
				'id'         => (int) $training['id'],
				'name'       => $name,
				'context'    => $context,
				'media_type' => 'application/pdf',
				'timestamp'  => strtotime( (string) $training['proposed_completion_date'] . ' 12:00:00 UTC' ) ?: 0,
				'status'     => self::review_status_label( $status ),
				'status_key' => $status,
				'viewable'   => null !== $item && in_array( $status, array( 'pending', 'approved' ), true ),
			);
		}

		usort( $rows, static function ( array $a, array $b ): int {
			$order = (int) $b['timestamp'] <=> (int) $a['timestamp'];
			return 0 !== $order ? $order : strcasecmp( (string) $a['name'], (string) $b['name'] );
		} );
		return $rows;
	}

	/** Markup for one merged record row. Manual rows carry their own open action. */
	private static function record_row_markup( array $row ): string {
		$attribute = 'manual' === $row['kind'] ? 'data-ghca-manual-open' : 'data-ghca-document-open';
		$label     = 'application/pdf' === (string) $row['media_type'] ? 'PDF' : 'FILE';
		$date      = $row['timestamp'] > 0 ? mysql2date( get_option( 'date_format' ), gmdate( 'Y-m-d H:i:s', (int) $row['timestamp'] ), false ) : '';
		ob_start();
		?>
		<li><span class="ghca-acd__jotform-file-icon" aria-hidden="true"><?php echo esc_html( $label ); ?></span><span class="ghca-acd__jotform-file-copy"><strong><?php echo esc_html( (string) $row['name'] ); ?></strong><small><?php echo esc_html( (string) $row['context'] ); ?> <i aria-hidden="true">•</i> <?php echo esc_html( $date ); ?><?php if ( 'manual' === $row['kind'] ) : ?> <i aria-hidden="true">•</i> <?php esc_html_e( 'Manual entry', 'ghca-acd' ); ?><?php endif; ?></small></span><span class="ghca-acd__jotform-indexed<?php echo '' === (string) ( $row['status_key'] ?? '' ) ? '' : ' ghca-acd__jotform-indexed--' . esc_attr( sanitize_key( (string) $row['status_key'] ) ); ?>"><i aria-hidden="true"></i><?php echo esc_html( (string) $row['status'] ); ?></span><span class="ghca-acd__jotform-actions"><?php if ( ! empty( $row['viewable'] ) ) : ?><button type="button" <?php echo esc_attr( $attribute ); ?>="<?php echo (int) $row['id']; ?>" data-action="preview"><?php esc_html_e( 'Preview', 'ghca-acd' ); ?></button><?php endif; ?></span></li>
		<?php
		return (string) ob_get_clean();
	}

	/** Shared review-status wording for both indexed Jotform documents and manual entries. */
	private static function review_status_label( string $status ): string {
		$labels = array(
			'pending'    => __( 'Awaiting review', 'ghca-acd' ),
			'approved'   => __( 'Approved', 'ghca-acd' ),
			'rejected'   => __( 'Rejected', 'ghca-acd' ),
			'superseded' => __( 'Superseded', 'ghca-acd' ),
			'revoked'    => __( 'Revoked', 'ghca-acd' ),
		);
		return $labels[ $status ] ?? ucfirst( $status );
	}

	/** Counts every external-training state so one recently touched row cannot misrepresent the rest. */
	private static function external_training_summary_label( int $employee_id ): string {
		$counts = GHCA_ACD_Jotform_Repository::external_training_summary( $employee_id );
		$parts  = array();
		foreach ( array( 'approved', 'pending', 'rejected' ) as $status ) {
			if ( $counts[ $status ] > 0 ) {
				$parts[] = sprintf( '%d %s', $counts[ $status ], strtolower( self::review_status_label( $status ) ) );
			}
		}
		if ( $counts['other'] > 0 ) {
			$parts[] = sprintf( '%d other', $counts['other'] );
		}
		return empty( $parts ) ? __( 'none recorded', 'ghca-acd' ) : implode( ', ', $parts );
	}

	/**
	 * Resolves a manual training row the current user may open, with its stored manifest.
	 *
	 * @return array{training:array<string,mixed>,manifest:array<int,array<string,mixed>>}|null
	 */
	private static function accessible_manual_evidence( int $training_id ) {
		if ( ! self::secure_transport() || $training_id <= 0 ) {
			return null;
		}
		$training = GHCA_ACD_Jotform_Repository::training( $training_id );
		if ( ! $training || ! GHCA_ACD_Jotform_Repository::is_manual( $training ) ) {
			return null;
		}
		$employee_id = (int) $training['employee_user_id'];
		/* Reviewers may open any in-scope entry; document-grant holders get the same scope rule. */
		$permitted = ( GHCA_ACD_Roles::user_can_review_external_training() || GHCA_ACD_Roles::user_can_view_employee_documents() ) && $employee_id > 0 && self::employee_in_scope( $employee_id );
		if ( ! $permitted ) {
			return null;
		}
		$manifest = GHCA_ACD_Jotform_Repository::manual_evidence_manifest( $training );
		if ( ! is_array( $manifest ) || ! in_array( (string) $training['status'], array( 'pending', 'approved' ), true ) ) {
			return null;
		}
		return array( 'training' => $training, 'manifest' => $manifest );
	}

	public static function ajax_manual_evidence_token(): void {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );
		$training_id = isset( $_POST['training_id'] ) ? absint( $_POST['training_id'] ) : 0;
		$index       = isset( $_POST['file_index'] ) ? absint( $_POST['file_index'] ) : 0;
		$access      = isset( $_POST['access'] ) && 'download' === $_POST['access'] ? 'download' : 'preview';
		$resolved    = self::accessible_manual_evidence( $training_id );
		if ( ! $resolved || ! isset( $resolved['manifest'][ $index ] ) ) {
			GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . $access, get_current_user_id(), 0, array( 'training_id' => $training_id ), 'denied' );
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ghca-acd' ) ), 403 );
		}
		$employee_id = (int) $resolved['training']['employee_user_id'];
		$token = bin2hex( random_bytes( 24 ) );
		if ( ! GHCA_ACD_Jotform_Repository::issue_document_grant( $token, array( 'actor' => get_current_user_id(), 'employee' => $employee_id, 'document' => 0, 'training' => $training_id, 'file_index' => $index, 'access' => $access ) ) ) {
			wp_send_json_error( array( 'message' => __( 'A document access grant could not be created. Retry later.', 'ghca-acd' ) ), 503 );
		}
		if ( ! GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . $access . '_grant', get_current_user_id(), $employee_id, array( 'training_id' => $training_id ) ) ) {
			GHCA_ACD_Jotform_Repository::consume_document_grant( $token );
			wp_send_json_error( array( 'message' => __( 'Document audit logging is unavailable. Retry later.', 'ghca-acd' ) ), 503 );
		}
		wp_send_json_success( array( 'url' => add_query_arg( array( 'action' => 'ghca_acd_jotform_manual_access', 'token' => $token ), admin_url( 'admin-ajax.php' ) ) ) );
	}

	/** Streams one decrypted manual certificate. Plaintext is never written to disk. */
	public static function stream_manual_evidence(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$grant = preg_match( '/^[a-f0-9]{48}$/', $token ) ? GHCA_ACD_Jotform_Repository::consume_document_grant( $token ) : null;
		if ( ! is_user_logged_in() || ! is_array( $grant ) || empty( $grant['training'] ) || (int) $grant['actor'] !== get_current_user_id() ) {
			GHCA_ACD_Jotform_Repository::event( 'manual_evidence_access', get_current_user_id(), 0, array(), 'denied' );
			status_header( 403 );
			exit;
		}
		/* Re-authorize after consuming the grant: capability or scope may have changed since it was issued. */
		$resolved = self::accessible_manual_evidence( (int) $grant['training'] );
		$index    = (int) ( $grant['file_index'] ?? 0 );
		if ( ! $resolved || ! isset( $resolved['manifest'][ $index ] ) || (int) $resolved['training']['employee_user_id'] !== (int) $grant['employee'] ) {
			GHCA_ACD_Jotform_Repository::event( 'manual_evidence_access', get_current_user_id(), (int) $grant['employee'], array( 'training_id' => (int) $grant['training'] ), 'denied' );
			status_header( 403 );
			exit;
		}
		$item  = $resolved['manifest'][ $index ];
		$bytes = GHCA_ACD_External_Evidence_Store::read_stored_evidence( $resolved['training'], $item );
		if ( is_wp_error( $bytes ) ) {
			$request_id = GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'training_id' => (int) $grant['training'] ), 'failed', $bytes->get_error_code() );
			self::document_error_page( 'Stored certificate is missing, changed or unreadable. Contact an administrator.', is_string( $request_id ) ? $request_id : '' );
		}
		if ( ! GHCA_ACD_Jotform_Repository::event( 'manual_evidence_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'training_id' => (int) $grant['training'] ) ) ) {
			self::document_error_page( 'Document audit logging is unavailable. Retry the request; contact an administrator if it persists.', '' );
		}
		$name = str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( (string) ( $item['file_name'] ?? 'certificate.pdf' ) ) );
		if ( '' === $name || '.pdf' !== strtolower( substr( $name, -4 ) ) ) {
			$name = 'certificate-' . (int) $grant['training'] . '.pdf';
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: sandbox' );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Content-Disposition: ' . ( 'download' === $grant['access'] ? 'attachment' : 'inline' ) . '; filename="' . $name . '"' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function ajax_documents(): void {
		check_ajax_referer( 'ghca_acd_jotform_documents', 'nonce' );
		$employee_id = isset( $_POST['employee_id'] ) ? absint( $_POST['employee_id'] ) : 0;
		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$search = isset( $_POST['search'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['search'] ) ), 0, 100 ) : '';
		if ( ! self::can_access_employee( $employee_id ) ) { GHCA_ACD_Jotform_Repository::event( 'document_list', get_current_user_id(), $employee_id, array(), 'denied' ); wp_send_json_error( array( 'message' => __( 'Access denied.', 'ghca-acd' ) ), 403 ); }
		$per_page = 10;
		$records = self::employee_record_rows( $employee_id, $search );
		$total = count( $records );
		$items = array_map( static function ( array $row ): array {
			$mime = strtolower( (string) ( $row['media_type'] ?? '' ) );
			$type = 'application/pdf' === $mime ? 'PDF' : ( 'image/jpeg' === $mime ? 'JPEG' : ( 'image/png' === $mime ? 'PNG' : __( 'Document', 'ghca-acd' ) ) );
			return array(
				'id'       => (int) $row['id'],
				'kind'     => (string) $row['kind'],
				'name'     => (string) $row['name'],
				'form'     => (string) $row['context'],
				'date'     => $row['timestamp'] > 0 ? mysql2date( get_option( 'date_format' ), gmdate( 'Y-m-d H:i:s', (int) $row['timestamp'] ), false ) : '',
				'type'     => $type,
				'status'   => (string) $row['status'],
				'viewable' => ! empty( $row['viewable'] ),
			);
		}, array_slice( $records, ( $page - 1 ) * $per_page, $per_page ) );
		if ( ! GHCA_ACD_Jotform_Repository::event( 'document_list', get_current_user_id(), $employee_id ) ) { wp_send_json_error( array( 'message' => __( 'Document audit logging is unavailable. Retry later.', 'ghca-acd' ) ), 503 ); }
		wp_send_json_success( array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) ) );
	}

	public static function ajax_ownership_claim(): void {
		check_ajax_referer( 'ghca_acd_jotform_ownership', 'nonce' );
		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$form = GHCA_ACD_Jotform_Repository::enabled() ? GHCA_ACD_Jotform_Repository::form( $form_id ) : null;
		$user_id = get_current_user_id();
		if ( ! self::secure_transport() || ! is_user_logged_in() || ! $form || empty( $form['ownership_claim_qid'] ) || $user_id <= 0 || ! get_userdata( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'A signed Jotform ownership claim is not available.', 'ghca-acd' ) ), 403 );
		}
		$claim = GHCA_ACD_Jotform_Sync::create_ownership_claim( $user_id, $form_id );
		if ( '' === $claim ) {
			wp_send_json_error( array( 'message' => __( 'The signed Jotform ownership claim could not be created.', 'ghca-acd' ) ), 500 );
		}
		$user = get_userdata( $user_id );
		wp_send_json_success( array( 'formId' => $form_id, 'userId' => $user_id, 'displayName' => $user ? (string) $user->display_name : '', 'claim' => $claim, 'expiresIn' => GHCA_ACD_Jotform_Sync::CLAIM_TTL ) );
	}

	public static function ajax_document_token(): void {
		check_ajax_referer( 'ghca_acd_jotform_documents', 'nonce' );
		$document_id = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;
		$access = isset( $_POST['access'] ) && 'download' === $_POST['access'] ? 'download' : 'preview';
		$training_id = isset( $_POST['training_id'] ) ? absint( $_POST['training_id'] ) : 0;
		$document = GHCA_ACD_Jotform_Repository::document( $document_id );
		$training = $training_id > 0 ? GHCA_ACD_Jotform_Repository::training( $training_id ) : null;
		$review_access = self::secure_transport() && $document && self::training_document_matches( $training, $document ) && GHCA_ACD_Roles::user_can_review_external_training();
		if ( ! $document || ( ! $review_access && ! self::can_access_employee( (int) $document['employee_user_id'] ) ) ) { GHCA_ACD_Jotform_Repository::event( 'document_' . $access, get_current_user_id(), $document ? (int) $document['employee_user_id'] : 0, array( 'document_id' => $document_id ), 'denied' ); wp_send_json_error( array( 'message' => __( 'Access denied.', 'ghca-acd' ) ), 403 ); }
		$token = bin2hex( random_bytes( 24 ) );
		if ( ! GHCA_ACD_Jotform_Repository::issue_document_grant( $token, array( 'actor' => get_current_user_id(), 'employee' => (int) $document['employee_user_id'], 'document' => $document_id, 'training' => $review_access ? $training_id : 0, 'access' => $access ) ) ) { wp_send_json_error( array( 'message' => __( 'A document access grant could not be created. Retry later.', 'ghca-acd' ) ), 503 ); }
		if ( ! GHCA_ACD_Jotform_Repository::event( 'document_' . $access . '_grant', get_current_user_id(), (int) $document['employee_user_id'], array( 'document_id' => $document_id, 'training_id' => $review_access ? $training_id : 0 ) ) ) { GHCA_ACD_Jotform_Repository::consume_document_grant( $token ); wp_send_json_error( array( 'message' => __( 'Document audit logging is unavailable. Retry later.', 'ghca-acd' ) ), 503 ); }
		$url = add_query_arg( array( 'action' => 'ghca_acd_jotform_document_access', 'token' => $token ), admin_url( 'admin-ajax.php' ) );
		wp_send_json_success( array( 'url' => $url ) );
	}

	public static function stream_document(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$grant = preg_match( '/^[a-f0-9]{48}$/', $token ) ? GHCA_ACD_Jotform_Repository::consume_document_grant( $token ) : null;
		if ( ! is_user_logged_in() || ! is_array( $grant ) || (int) $grant['actor'] !== get_current_user_id() ) { GHCA_ACD_Jotform_Repository::event( 'document_access', get_current_user_id(), is_array( $grant ) ? (int) $grant['employee'] : 0, array(), 'denied' ); status_header( 403 ); exit; }
		$document = GHCA_ACD_Jotform_Repository::document( (int) $grant['document'] );
		$training = ! empty( $grant['training'] ) ? GHCA_ACD_Jotform_Repository::training( (int) $grant['training'] ) : null;
		$review_access = self::secure_transport() && $document && self::training_document_matches( $training, $document ) && GHCA_ACD_Roles::user_can_review_external_training();
		if ( ! $document || ( ! $review_access && ! self::can_access_employee( (int) $grant['employee'] ) ) ) { GHCA_ACD_Jotform_Repository::event( 'document_access', get_current_user_id(), (int) $grant['employee'], array(), 'denied' ); status_header( 403 ); exit; }
		$form = $document ? GHCA_ACD_Jotform_Repository::form( (string) $document['form_id'] ) : null;
		if ( ! $document || ! $form || (int) $document['employee_user_id'] !== (int) $grant['employee'] ) { status_header( 404 ); exit; }
		$response = GHCA_ACD_Jotform_Provider::get_submission( (string) $document['submission_id'] );
		$submission = ! is_wp_error( $response ) && is_array( $response['content'] ?? null ) ? $response['content'] : array();
		$current_fingerprint = GHCA_ACD_Jotform_Sync::submission_fingerprint( $form, $submission );
		if ( ! hash_equals( (string) $document['source_fingerprint'], $current_fingerprint ) ) {
			$request_id = GHCA_ACD_Jotform_Repository::event( 'document_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'document_id' => (int) $document['id'], 'training_id' => $training ? (int) $training['id'] : 0 ), 'denied', 'source_revision_changed' );
			self::document_error_page( 'The source revision changed. Return to employee details or external training review and open the current document.', is_string( $request_id ) ? $request_id : '', 409 );
		}
		$current_ownership = GHCA_ACD_Jotform_Sync::verified_ownership( $form, $submission );
		$current_employee_id = (int) $current_ownership['user_id'];
		$manual_binding = 0 === $current_employee_id && GHCA_ACD_Jotform_Repository::document_has_reviewed_employee_binding( $document, (int) $document['employee_user_id'] );
		if ( $current_employee_id !== (int) $document['employee_user_id'] && ! $manual_binding ) {
			$request_id = GHCA_ACD_Jotform_Repository::event( 'document_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'document_id' => (int) $document['id'] ), 'denied', 'source_owner_changed' );
			self::document_error_page( 'The verified owner changed. Return to employee details or external training review and open the current document.', is_string( $request_id ) ? $request_id : '', 409 );
		}
		$file = GHCA_ACD_External_Evidence_Store::resolve_source_file( $form, $submission, (int) $document['file_index'] );
		if ( is_wp_error( $file ) ) { $request_id = GHCA_ACD_Jotform_Repository::event( 'document_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'document_id' => (int) $document['id'] ), 'failed', $file->get_error_code() ); self::document_error_page( 'Evidence source unavailable. Retry the request; contact an administrator if it persists.', is_string( $request_id ) ? $request_id : '' ); }
		$name = sanitize_file_name( (string) $document['file_name'] );
		$name = str_replace( array( '"', "\r", "\n" ), '', $name );
		if ( ! GHCA_ACD_Jotform_Repository::event( 'document_' . $grant['access'], get_current_user_id(), (int) $grant['employee'], array( 'document_id' => (int) $document['id'] ) ) ) { self::document_error_page( 'Document audit logging is unavailable. Retry the request; contact an administrator if it persists.', '' ); }
		nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Security-Policy: sandbox' ); header( 'Content-Type: ' . (string) $file['mime'] ); header( 'Content-Length: ' . strlen( (string) $file['body'] ) ); header( 'Content-Disposition: ' . ( 'download' === $grant['access'] ? 'attachment' : 'inline' ) . '; filename="' . $name . '"' );
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function employee_in_scope( int $employee_id ): bool {
		return class_exists( 'GHCA_ACD_User_Report' ) && GHCA_ACD_User_Report::can_view_user( $employee_id );
	}

	private static function can_access_employee( int $employee_id ): bool { return self::secure_transport() && $employee_id > 0 && GHCA_ACD_Roles::user_can_view_employee_documents() && self::employee_in_scope( $employee_id ); }

	/** @param array<string,mixed>|null $training @param array<string,mixed> $document */
	private static function training_document_matches( ?array $training, array $document ): bool {
		return is_array( $training )
			&& 'pending' === (string) ( $training['status'] ?? '' )
			&& hash_equals( (string) ( $training['form_id'] ?? '' ), (string) ( $document['form_id'] ?? '' ) )
			&& hash_equals( (string) ( $training['submission_id'] ?? '' ), (string) ( $document['submission_id'] ?? '' ) )
			&& hash_equals( (string) ( $training['source_fingerprint'] ?? '' ), (string) ( $document['source_fingerprint'] ?? '' ) );
	}

	private static function secure_transport(): bool { return function_exists( 'is_ssl' ) && is_ssl(); }

	private static function document_error_page( string $message, string $request_id, int $status = 502 ): void {
		status_header( 409 === $status ? 409 : 502 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Document unavailable</title></head><body><h1>Document unavailable</h1><p>' . esc_html( $message ) . '</p>' . ( '' !== $request_id ? '<p>Request ID: ' . esc_html( $request_id ) . '</p>' : '<p>Audit logging is unavailable; contact an administrator.</p>' ) . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function require_admin(): void { if ( ! self::secure_transport() || ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'HTTPS and administrator access are required.', 'ghca-acd' ) ); } check_admin_referer( self::NONCE ); }

	private static function review_redirect( string $notice ): void {
		if ( isset( $_POST['frontend_review'], $_POST['return_url'] ) && '1' === (string) $_POST['frontend_review'] ) {
			$target = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ) ), '' );
			$args = is_string( $target ) ? wp_parse_args( (string) wp_parse_url( $target, PHP_URL_QUERY ) ) : array();
			if ( $target && 'external-training' === (string) ( $args['ghca_tab'] ?? '' ) ) {
				wp_safe_redirect( add_query_arg( 'ghca_review_notice', $notice, $target ) . '#ghca-tab-external-training' );
				exit;
			}
		}
		self::redirect( $notice, 'ghca-acd-external-training' );
	}

	private static function redirect( string $notice, string $page = 'ghca-acd-jotform' ): void { wp_safe_redirect( GHCA_ACD_Admin_Menu::url( $page, array( 'ghca_notice' => $notice ) ) ); exit; }

	private static function warning_label( string $code ): string {
		$labels = array( 'ownership_claim_missing' => __( 'Signed ownership claim missing; reviewer binding required.', 'ghca-acd' ), 'ownership_claim_invalid' => __( 'Signed ownership claim is invalid or expired.', 'ghca-acd' ), 'employee_unresolved' => __( 'Signed submitter is not a current employee.', 'ghca-acd' ), 'identity_mismatch' => __( 'Submitted identity differs from the linked employee.', 'ghca-acd' ), 'course_unmapped' => __( 'No configured catalog title or alias matches this submission.', 'ghca-acd' ), 'course_ambiguous' => __( 'More than one catalog record matches this submission.', 'ghca-acd' ), 'certificate_missing' => __( 'Certificate metadata is missing.', 'ghca-acd' ), 'ghca_drive_credentials_missing' => __( 'Drive credential constant is missing.', 'ghca-acd' ), 'ghca_drive_credentials_invalid' => __( 'Drive credential file is unreadable or invalid.', 'ghca-acd' ), 'ghca_drive_auth_failed' => __( 'Drive service-account authentication failed.', 'ghca-acd' ), 'ghca_drive_root_unavailable' => __( 'Configured Drive root is inaccessible or not shared.', 'ghca-acd' ), 'ghca_drive_evidence_folder_invalid' => __( 'Expected Drive evidence folder is missing or ambiguous.', 'ghca-acd' ), 'ghca_drive_file_missing' => __( 'Expected Drive evidence file is missing.', 'ghca-acd' ), 'ghca_drive_file_count_invalid' => __( 'Drive evidence has missing or extra files.', 'ghca-acd' ), 'ghca_drive_file_invalid' => __( 'Drive evidence filename, MIME, size, parent or ambiguity check failed.', 'ghca-acd' ), 'ghca_evidence_pdf_invalid' => __( 'PDF cannot be merged safely; re-export or Print to PDF.', 'ghca-acd' ), 'ghca_evidence_mime_invalid' => __( 'Certificate file type does not match its declared type.', 'ghca-acd' ) );
		$labels = array_merge( array( 'completion_date_invalid' => __( 'Completion date is missing or invalid.', 'ghca-acd' ), 'malformed_file' => __( 'Certificate metadata contains an invalid source file.', 'ghca-acd' ), 'probable_duplicate' => __( 'Possible duplicate employee, lesson and completion date.', 'ghca-acd' ), 'ghca_drive_metadata_invalid' => __( 'Drive certificate metadata is invalid.', 'ghca-acd' ), 'ghca_drive_metadata_ambiguous' => __( 'Drive certificate metadata is missing or ambiguous.', 'ghca-acd' ) ), $labels );
		$labels['ownership_claim_required'] = __( 'Legacy unsigned ownership requires explicit review.', 'ghca-acd' );
		$labels['manual_entry'] = __( 'Entered manually by an administrator; certificate stored locally pending review.', 'ghca-acd' );
		return $labels[ $code ] ?? ( '' === $code ? __( 'Ready for review.', 'ghca-acd' ) : __( 'Submission needs review.', 'ghca-acd' ) );
	}

	private static function notice( bool $frontend = false ): void { $key = $frontend ? 'ghca_review_notice' : 'ghca_notice'; if ( empty( $_GET[ $key ] ) ) { return; } $notice = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); ?><div class="notice <?php echo 0 === strpos( $notice, 'error_' ) || false !== strpos( $notice, 'failed' ) || false !== strpos( $notice, 'blocked' ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( str_replace( '_', ' ', $notice ) ); ?></p></div><?php }
}
