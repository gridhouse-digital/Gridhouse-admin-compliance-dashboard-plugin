<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_PDF {
	const MAX_CERTIFICATES          = 40;
	const MAX_CERTIFICATE_BYTES     = 8388608;
	const MAX_TOTAL_CERTIFICATE_BYTES = 33554432;
	const MAX_CERTIFICATE_PAGES     = 100;

	public static function init(): void {
		add_action( 'wp_ajax_ghca_acd_pdf_init', array( __CLASS__, 'ajax_init_job' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_fetch', array( __CLASS__, 'ajax_fetch_cert' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_merge', array( __CLASS__, 'ajax_merge' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_download', array( __CLASS__, 'ajax_download' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_ghca_acd_pdf_certificate_broker', array( __CLASS__, 'ajax_certificate_broker' ) );
		add_action( 'wp_ajax_nopriv_ghca_acd_pdf_certificate_broker', array( __CLASS__, 'ajax_certificate_broker' ) );
		add_action( 'wp_scheduled_delete', array( 'GHCA_Audit_PDF_Jobs', 'gc' ) );
	}

	/* ---------------------------------------------------------------------
	 * Async packet builder (init -> fetch x N -> merge -> download)
	 * ------------------------------------------------------------------- */

	/**
	 * Common gate for all packet AJAX endpoints: nonce, role, and (when a
	 * job id is supplied) manifest ownership. Sends a JSON error and exits
	 * on failure; returns the manifest (or null when no job id expected).
	 */
	private static function guard_ajax( bool $expects_job ): ?array {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );

		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ), 403 );
		}

		if ( ! $expects_job ) {
			return null;
		}

		$job_id = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
		$job    = GHCA_Audit_PDF_Jobs::get_job( $job_id, get_current_user_id() );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 404 );
		}

		// Re-check scope on every phase in case group visibility changed mid-job.
		if ( ! GHCA_ACD_User_Report::can_view_user( (int) $job['user_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ), 403 );
		}
		if ( 'oltl_training' === (string) ( $job['tracker'] ?? '' ) && ( ! GHCA_ACD_Roles::user_can_manage_oltl_training() || ! GHCA_ACD_OLTL_Readiness::is_assigned( (int) $job['user_id'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'OLTL training packet access is unavailable.', 'ghca-acd' ) ), 403 );
		}

		$job['job_id'] = $job_id;
		return $job;
	}

	/** Phase 1: build the manifest and return job_id + certificate count. */
	public static function ajax_init_job(): void {
		self::guard_ajax( false );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid employee or permission denied.', 'ghca-acd' ) ), 403 );
		}

		$requested_tracker = isset( $_POST['tracker'] ) ? sanitize_key( wp_unslash( $_POST['tracker'] ) ) : 'annual';
		$tracker = in_array( $requested_tracker, array( 'orientation', 'annual', 'oltl_training' ), true ) ? $requested_tracker : 'annual';
		if ( 'oltl_training' === $tracker && ( ! GHCA_ACD_Roles::user_can_manage_oltl_training() || ! GHCA_ACD_OLTL_Readiness::is_assigned( $user_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'OLTL training packet access is unavailable.', 'ghca-acd' ) ), 403 );
		}

		$audit_period = array();
		if ( isset( $_POST['audit_start'] ) || isset( $_POST['audit_end'] ) ) {
			$audit_period = array( 'start' => $_POST['audit_start'] ?? null, 'end' => $_POST['audit_end'] ?? null );
			try {
				GHCA_Audit_Calculator::reporting_window( $audit_period );
				if ( 'annual' !== $tracker ) {
					throw new InvalidArgumentException( 'Select Annual to generate an audit reporting-period packet.' );
				}
			} catch ( InvalidArgumentException $exception ) {
				wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
			}
		}
		$context = self::resolve_audit_context( $user_id, $tracker, $audit_period );
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		}

		GHCA_Audit_PDF_Jobs::gc();

		$course_ids = array();
		$brokerable = array();
		$course_keys = array();
		$evidence_refs = array();
		$urls       = self::collect_certificate_urls( $context['audit_data'], $user_id, $course_ids, $brokerable, $course_keys, $evidence_refs );
		if ( is_wp_error( $urls ) ) {
			wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
		}
		try {
			$job_id = GHCA_Audit_PDF_Jobs::create_job(
				get_current_user_id(),
				$user_id,
				$tracker,
				$urls,
				self::build_filename( $context['audit_data'] ),
				$course_ids,
				$brokerable,
				$course_keys,
				$evidence_refs,
				$audit_period ? $context['audit_data'] : array()
			);
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => __( 'A packet job is already starting. Please try again.', 'ghca-acd' ) ), 409 );
		}
		if ( 'oltl_training' === $tracker && ! GHCA_ACD_OLTL_Readiness::event( 'packet_started', get_current_user_id(), $user_id ) ) {
			GHCA_Audit_PDF_Jobs::delete_job( $job_id );
			wp_send_json_error( array( 'message' => __( 'The OLTL packet audit event could not be recorded.', 'ghca-acd' ) ) );
		}

		wp_send_json_success( array(
			'job_id'   => $job_id,
			'total'    => count( $urls ),
			'employee' => $context['employee_data']['name'] ?? '',
		) );
	}

	/** Phase 2: fetch ONE certificate to the job's temp folder. */
	public static function ajax_fetch_cert(): void {
		$job = self::guard_ajax( true );

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		if ( $index < 0 || $index >= count( $job['urls'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid certificate index.', 'ghca-acd' ) ), 400 );
		}

		$lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $job['job_id'] );
		if ( false === $lock ) {
			wp_send_json_error( array( 'message' => __( 'This packet job is already processing another request.', 'ghca-acd' ) ), 409 );
		}
		$current_job = GHCA_Audit_PDF_Jobs::get_job( $job['job_id'], get_current_user_id() );
		if ( is_wp_error( $current_job ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			wp_send_json_error( array( 'message' => $current_job->get_error_message() ), 404 );
		}

		$certificate_path = GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $index );
		if ( is_file( $certificate_path ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			wp_send_json_error( array( 'message' => __( 'This certificate was already processed.', 'ghca-acd' ) ), 409 );
		}

		$evidence_ref = is_array( $job['evidence_refs'][ $index ] ?? null ) ? $job['evidence_refs'][ $index ] : null;
		if ( $evidence_ref ) {
			$materialized = self::materialize_external_evidence( $job, $index, $evidence_ref, $certificate_path );
			$existing_bytes = self::get_job_certificate_bytes( $job['job_id'], count( $job['urls'] ), $index );
			$materialized_bytes = is_file( $certificate_path ) ? (int) filesize( $certificate_path ) : 0;
			if ( is_wp_error( $materialized ) || $materialized_bytes <= 5 || $materialized_bytes > self::MAX_CERTIFICATE_BYTES || ( $existing_bytes + $materialized_bytes ) > self::MAX_TOTAL_CERTIFICATE_BYTES ) {
				GHCA_Audit_PDF_Jobs::release_lock( $lock );
				GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
				wp_send_json_error( array( 'message' => __( 'Approved external evidence was missing, changed or corrupt. Packet generation was aborted — no partial packet was created.', 'ghca-acd' ) ) );
			}
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			wp_send_json_success( array( 'index' => $index ) );
		}

		$url = GHCA_ACD_Data_Provider::sanitize_certificate_url( (string) $job['urls'][ $index ] );
		if ( '' === $url ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => __( 'The certificate URL is not a trusted site URL. Packet generation was aborted.', 'ghca-acd' ) ), 400 );
		}

		$response = self::request_certificate( $job, $index, $url );

		$pdf_content = ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 )
			? wp_remote_retrieve_body( $response )
			: '';

		$saved          = false;
		$existing_bytes = self::get_job_certificate_bytes( $job['job_id'], count( $job['urls'] ), $index );
		if ( self::certificate_response_is_allowed( $pdf_content, $existing_bytes ) ) {
			$saved = (bool) file_put_contents( $certificate_path, $pdf_content );
			$saved = $saved && GHCA_Audit_PDF_Jobs::secure_file( $certificate_path );
		}

		if ( ! $saved ) {
			// ABORT policy: a compliance packet must never be produced with a
			// certificate silently missing. Kill the whole job and tell the admin.
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d: 1-based certificate number */
					__( 'Certificate %d could not be retrieved. Packet generation was aborted — no partial packet was created. Please try again.', 'ghca-acd' ),
					$index + 1
				),
			) );
		}

		GHCA_Audit_PDF_Jobs::release_lock( $lock );
		wp_send_json_success( array( 'index' => $index ) );
	}

	/** Packet-only endpoint that renders one target user's LearnDash certificate. */
	public static function ajax_certificate_broker(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::deny_certificate_broker();
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$index  = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$job    = GHCA_Audit_PDF_Jobs::claim_broker_job( $job_id, $index, $token );
		if ( is_wp_error( $job ) ) {
			self::deny_certificate_broker();
		}

		$user_id   = (int) ( $job['user_id'] ?? 0 );
		$course_id = (int) ( $job['course_ids'][ $index ] ?? 0 );
		if (
			$user_id <= 0
			|| $course_id <= 0
			|| ! get_userdata( $user_id )
			|| ! function_exists( 'learndash_course_status' )
			|| ! function_exists( 'learndash_get_setting' )
			|| ! function_exists( 'learndash_get_post_type_slug' )
			|| 'completed' !== learndash_course_status( $course_id, $user_id, true )
		) {
			self::deny_certificate_broker();
		}

		$certificate_id = (int) learndash_get_setting( $course_id, 'certificate' );
		if (
			$certificate_id <= 0
			|| learndash_get_post_type_slug( 'course' ) !== get_post_type( $course_id )
			|| learndash_get_post_type_slug( 'certificate' ) !== get_post_type( $certificate_id )
		) {
			self::deny_certificate_broker();
		}

		wp_set_current_user( $user_id );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		$args = array(
			'cert_id' => $certificate_id,
			'user_id' => $user_id,
			'post_id' => $course_id,
		);
		$certificate_post = get_post( $certificate_id );
		$blocks            = $certificate_post ? parse_blocks( $certificate_post->post_content ) : array();
		$builder_class      = '\\LearnDash_Certificate_Builder\\Component\\PDF';
		if (
			isset( $blocks[0]['blockName'] )
			&& 'learndash/ld-certificate-builder' === $blocks[0]['blockName']
			&& class_exists( $builder_class )
		) {
			( new $builder_class() )->serve( $blocks, $certificate_id, $course_id );
			exit;
		}

		if ( has_action( 'learndash_tcpdf_init' ) ) {
			do_action( 'learndash_tcpdf_init', $args );
			exit;
		}

		if ( ! function_exists( 'learndash_certificate_post_shortcode' ) && function_exists( 'learndash_certificate_display' ) ) {
			$source    = ( new ReflectionFunction( 'learndash_certificate_display' ) )->getFileName();
			$converter = $source ? dirname( $source ) . '/ld-convert-post-pdf.php' : '';
			if ( $converter && is_file( $converter ) ) {
				require_once $converter;
			}
		}
		if ( function_exists( 'learndash_certificate_post_shortcode' ) ) {
			learndash_certificate_post_shortcode( $args );
			exit;
		}

		self::deny_certificate_broker();
	}

	/** Phase 3: merge cover sheet + local certificates into the final packet. */
	public static function ajax_merge(): void {
		$job = self::guard_ajax( true );
		$lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $job['job_id'] );
		if ( false === $lock ) {
			wp_send_json_error( array( 'message' => __( 'This packet job is already processing another request.', 'ghca-acd' ) ), 409 );
		}
		$current_job = GHCA_Audit_PDF_Jobs::get_job( $job['job_id'], get_current_user_id() );
		if ( is_wp_error( $current_job ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			wp_send_json_error( array( 'message' => $current_job->get_error_message() ), 404 );
		}
		if ( is_file( GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] ) ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			wp_send_json_error( array( 'message' => __( 'This packet was already merged.', 'ghca-acd' ) ), 409 );
		}

		$libs = self::load_libs();
		if ( is_wp_error( $libs ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => $libs->get_error_message() ) );
		}

		$snapshot = $job['report_snapshot'] ?? array();
		$context = self::resolve_audit_context( (int) $job['user_id'], (string) $job['tracker'], $snapshot['audit_period'] ?? array() );
		if ( is_wp_error( $context ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		}
		if ( $snapshot && $snapshot !== $context['audit_data'] ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => __( 'Training records or reporting settings changed while building this packet. Please start again.', 'ghca-acd' ) ), 409 );
		}
		if ( ! self::external_evidence_refs_are_current( $job, $context['audit_data'] ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => __( 'Approved external evidence changed before merge. Packet generation was aborted — no partial packet was created.', 'ghca-acd' ) ) );
		}

		$pdf = self::create_document( $context['audit_data'] );
		$pdf->AddPage();
		self::render_cover( $pdf, $context['audit_data'], (string) $job['tracker'] );

		$total               = count( $job['urls'] );
		$total_pages         = $pdf->getPage();
		$appended            = array();
		$certificate_indexes = array();
		foreach ( (array) $job['course_keys'] as $index => $course_key ) {
			$certificate_indexes[ (string) $course_key ][] = (int) $index;
		}

		foreach ( (array) ( $context['audit_data']['raw_completed_courses'] ?? array() ) as $course ) {
			if ( 'oltl_training' === (string) $job['tracker'] || GHCA_ACD_Settings::include_packet_course_details() || 'external' === (string) ( $course['source'] ?? '' ) ) {
				self::render_course_detail_page( $pdf, $course );
				$total_pages = $pdf->getPage();
				if ( $total_pages > self::MAX_CERTIFICATE_PAGES ) {
					GHCA_Audit_PDF_Jobs::release_lock( $lock );
					GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
					wp_send_json_error( array( 'message' => __( 'This packet contains too many pages to process safely.', 'ghca-acd' ) ) );
				}
			}

			$course_key = (string) ( $course['course_key'] ?? ( 'learndash:' . (int) ( $course['course_id'] ?? 0 ) ) );
			if ( empty( $certificate_indexes[ $course_key ] ) ) {
				continue;
			}
			while ( ! empty( $certificate_indexes[ $course_key ] ) ) {
				$index = array_shift( $certificate_indexes[ $course_key ] );
				if ( ! self::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $index ), $total_pages ) ) {
					GHCA_Audit_PDF_Jobs::release_lock( $lock );
					GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
					wp_send_json_error( array( 'message' => __( 'A certificate was missing or unreadable at merge time. Packet generation was aborted - no partial packet was created.', 'ghca-acd' ) ) );
				}
				$appended[ $index ] = true;
			}
		}

		for ( $i = 0; $i < $total; $i++ ) {
			if ( isset( $appended[ $i ] ) ) {
				continue;
			}
			if ( ! self::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $i ), $total_pages ) ) {
				// ABORT policy (defense in depth): every fetched cert must merge.
				GHCA_Audit_PDF_Jobs::release_lock( $lock );
				GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %d: 1-based certificate number */
						__( 'Certificate %d was missing or unreadable at merge time. Packet generation was aborted — no partial packet was created.', 'ghca-acd' ),
						$i + 1
					),
				) );
			}
		}

		try {
			$pdf->Output( GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] ), 'F' );
			if ( ! GHCA_Audit_PDF_Jobs::secure_file( GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] ) ) ) {
				throw new RuntimeException( 'Private packet permissions could not be applied.' );
			}
		} catch ( Throwable $exception ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => __( 'Packet generation failed. No packet was created. Please try again.', 'ghca-acd' ) ) );
		}
		if ( 'oltl_training' === (string) $job['tracker'] && ! GHCA_ACD_OLTL_Readiness::event( 'packet_completed', get_current_user_id(), (int) $job['user_id'] ) ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
			wp_send_json_error( array( 'message' => __( 'The OLTL packet audit event could not be recorded; no packet was retained.', 'ghca-acd' ) ) );
		}

		GHCA_Audit_PDF_Jobs::release_lock( $lock );

		wp_send_json_success( array(
			'download_url' => add_query_arg(
				array(
					'action' => 'ghca_acd_pdf_download',
					'job_id' => $job['job_id'],
					'nonce'  => wp_create_nonce( 'ghca_acd_table' ),
				),
				admin_url( 'admin-ajax.php' )
			),
			'total'        => $total,
		) );
	}

	/** Cancels an owned job and promptly removes its temporary PII files. */
	public static function ajax_cancel(): void {
		$job  = self::guard_ajax( true );
		$lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $job['job_id'], true );
		if ( false !== $lock ) {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
		}
		GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
		wp_send_json_success();
	}

	/** Phase 4: stream the finished packet (files are never web-readable directly). */
	public static function ajax_download(): void {
		$job = self::guard_ajax( true );

		$path = GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This packet has expired. Please generate it again.', 'ghca-acd' ) );
		}
		if ( 'oltl_training' === (string) $job['tracker'] && ! GHCA_ACD_OLTL_Readiness::event( 'packet_downloaded', get_current_user_id(), (int) $job['user_id'] ) ) {
			wp_die( esc_html__( 'The OLTL packet access event could not be recorded.', 'ghca-acd' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $job['filename'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		if ( ob_get_length() ) {
			ob_clean();
		}

		readfile( $path );
		GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
		exit;
	}

	/** Loads TCPDF (from LearnDash) and the bundled FPDI. */
	public static function load_libs() {
		if ( ! class_exists( 'TCPDF' ) ) {
			$tcpdf_path = WP_PLUGIN_DIR . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php';
			if ( file_exists( $tcpdf_path ) ) {
				require_once $tcpdf_path;
			} else {
				return new WP_Error( 'ghca_pdf_no_tcpdf', __( 'LearnDash TCPDF library not found.', 'ghca-acd' ) );
			}
		}

		$fpdi_autoload = __DIR__ . '/lib/fpdi/autoload.php';
		if ( file_exists( $fpdi_autoload ) ) {
			require_once $fpdi_autoload;
		} else {
			return new WP_Error( 'ghca_pdf_no_fpdi', __( 'FPDI library not found in plugin.', 'ghca-acd' ) );
		}

		return true;
	}

	/**
	 * Resolves the employee record + audit data for one packet build.
	 *
	 * @return array{audit_data: array, employee_data: array}|WP_Error
	 */
	public static function resolve_audit_context( int $user_id, string $tracker_type, array $audit_period = array() ) {
		$employees     = GHCA_ACD_Data_Provider::get_employees_for_current_view();
		$employee_data = null;
		foreach ( $employees as $emp ) {
			if ( (int) $emp['user_id'] === $user_id ) {
				$employee_data = $emp;
				break;
			}
		}

		if ( ! $employee_data ) {
			// Fallback if they were somehow excluded from current view but valid
			$user_info     = get_userdata( $user_id );
			$employee_data = array(
				'user_id' => $user_id,
				'name'    => $user_info ? $user_info->display_name : 'Unknown',
				'email'   => $user_info ? $user_info->user_email : '',
				'group'   => '',
			);
		}

		if ( 'oltl_training' === $tracker_type ) {
			$audit_data = GHCA_ACD_OLTL_Readiness::evaluate( $user_id );
			if ( is_wp_error( $audit_data ) ) {
				return $audit_data;
			}
		} else {
			$mappings   = get_option( 'ghca_acd_audit_mapping', array() );
			$audit_data = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee_data, $tracker_type, $mappings, $audit_period );
		}

		if ( empty( $audit_data ) ) {
			return new WP_Error( 'ghca_pdf_excluded', __( 'Employee is excluded from audits or has an ignored role.', 'ghca-acd' ) );
		}

		return array( 'audit_data' => $audit_data, 'employee_data' => $employee_data );
	}

	/** Document setup: page format, metadata, margins. */
	public static function create_document( array $audit_data ): \setasign\Fpdi\Tcpdf\Fpdi {
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );

		// Set Document Info
		$pdf->SetCreator( 'Gridhouse Compliance Dashboard' );
		$pdf->SetAuthor( 'Gridhouse Healthcare Academy' );
		$pdf->SetTitle( ( 'oltl_training' === (string) ( $audit_data['packet_type'] ?? '' ) ? 'OLTL Training Readiness Packet - ' : 'Compliance Audit Packet - ' ) . $audit_data['first_name'] . ' ' . $audit_data['last_name'] );

		// Remove default header/footer
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetAutoPageBreak( true, 15 );

		return $pdf;
	}

	/** Cover page (Compliance Matrix). Caller must AddPage() first. */
	public static function render_cover( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $audit_data, string $tracker_type ): void {
		$logo_source = GHCA_Dashboard_Branding::get_pdf_logo_source();
		if ( $logo_source ) {
			try {
				$pdf->Image( $logo_source, null, null, 0, 15, '', '', 'N', false, 300, 'C' );
				$pdf->Ln( 2 );
			} catch ( \Throwable $e ) {
				// A missing logo must not prevent generation of the compliance packet.
			}
		}

		// Agency Name
		$agency_name = wp_specialchars_decode( GHCA_Dashboard_Branding::get_org_name(), ENT_QUOTES );
		if ( ! empty( $agency_name ) ) {
			$pdf->SetFont( 'helvetica', 'B', 18 );
			$pdf->Cell( 0, 10, $agency_name, 0, 1, 'C' );
			$pdf->Ln( 2 );
		}
		if ( 'oltl_training' === $tracker_type ) {
			self::render_oltl_cover_body( $pdf, $audit_data );
			return;
		}

		$pdf->SetFont( 'helvetica', 'B', 14 );
		$pdf->Cell( 0, 10, 'Compliance Audit Packet', 0, 1, 'C' );

		$pdf->Ln( 5 );

		// Details block
		$pdf->SetFont( 'helvetica', '', 10 );
		$html = '
		<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
			<tr>
				<td width="30%"><strong>Employee:</strong></td>
				<td width="70%">' . esc_html( $audit_data['first_name'] . ' ' . $audit_data['last_name'] ) . '</td>
			</tr>
			<tr>
				<td width="30%"><strong>Role:</strong></td>
				<td width="70%">' . esc_html( $audit_data['role'] ) . '</td>
			</tr>
			<tr>
				<td><strong>Date of Hire (DOH):</strong></td>
				<td>' . esc_html( $audit_data['doh'] ) . '</td>
			</tr>
			<tr>
				<td><strong>First Service Date:</strong></td>
				<td>' . esc_html( $audit_data['first_service_date'] ) . '</td>
			</tr>
			<tr>
				<td><strong>Worked Alone Date:</strong></td>
				<td>' . esc_html( $audit_data['worked_alone_date'] ) . '</td>
			</tr>
		</table>
		<br><br>';

		$reporting = ! empty( $audit_data['audit_period'] );
		if ( $reporting ) {
			$html .= '<h3>Audit Reporting Period — Training Evidence</h3><p><strong>Period reviewed (inclusive):</strong> ' . esc_html( $audit_data['start_date'] ) . ' to ' . esc_html( $audit_data['end_date'] ) . '</p>';
		} elseif ( 'annual' === $tracker_type ) {
			$cycle_name = GHCA_ACD_Settings::annual_cycle_label( (string) ( $audit_data['annual_cycle'] ?? '' ) );
			$html .= '<h3>Annual ODP Requirements Matrix</h3>';
			$html .= '<p><strong>Annual Cycle Rule:</strong> ' . esc_html( $cycle_name ) . '<br>';
			$html .= '<strong>Window Reviewed:</strong> ' . esc_html( $audit_data['start_date'] ) . ' to ' . esc_html( $audit_data['end_date'] ) . '</p>';

			$status_styles = array(
				'compliant'    => array( '#dff0d8', 'Compliant' ),
				'in_progress'  => array( '#fcf8e3', 'In Progress' ),
				'noncompliant' => array( '#f2dede', 'Non-Compliant' ),
				'missing_evidence' => array( '#fcf8e3', 'Missing Evidence — Review Required' ),
				'needs_review' => array( '#fcf8e3', 'Needs Review — Topic Coverage Unresolved' ),
			);
			$status_style = $status_styles[ $audit_data['annual_status'] ] ?? $status_styles['in_progress'];
			$status_heading = 'Overall Annual Status:';
			if ( 'needs_review' === $audit_data['annual_status'] && ! empty( $audit_data['isp_manual_delivery'] ) && empty( $audit_data['evidence_issues'] ) && (float) $audit_data['total_hrs'] >= 24 ) {
				$other_topics = array_intersect_key( $audit_data['category_statuses'] ?? array(), array_fill_keys( array( 'person_centered', 'abuse_prevention', 'individual_rights', 'reporting_incidents', 'behavior_supports' ), true ) );
				if ( count( $other_topics ) === 5 && ! array_diff( $other_topics, array( 'Completed', 'Not applicable' ) ) ) {
					// Report the split scope, without turning unverified ISP evidence into compliance.
					$status_heading = 'Annual Training Summary:';
					$status_style[1] = 'Recorded hours and other required topics met — ISP handled manually / in person';
				}
			}
			$html .= '<div style="background-color: ' . $status_style[0] . '; padding: 10px; border: 1px solid #ccc;">
				<strong>' . $status_heading . '</strong> ' . $status_style[1] . '
			</div><br>';
		} else {
			$html .= '<h3>Orientation ODP Requirements Matrix</h3>';
		}

		if ( ! empty( $audit_data['evidence_issues'] ) ) {
			$html .= '<p><strong>Evidence review required:</strong> Records with missing or invalid completion dates are excluded from credited hours and included certificates.</p><ul>';
			foreach ( $audit_data['evidence_issues'] as $issue ) {
				$html .= '<li>' . esc_html( $issue ) . '</li>';
			}
			$html .= '</ul>';
		}

		$requirement_hours = $audit_data['requirement_hours'] ?? array();
		$is_annual_matrix = 'annual' === $tracker_type;
		$format_hours = static function ( float $hours ): string {
			return rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' );
		};
		$hours_cell = static function ( string $category ) use ( $requirement_hours, $is_annual_matrix, $format_hours ): string {
			$achieved = (float) ( $requirement_hours[ $category ]['achieved'] ?? 0 );
			if ( $is_annual_matrix ) {
				return $format_hours( $achieved ) . ' hrs';
			}
			// Orientation: time spent is informational, so no target is implied.
			return $achieved > 0 ? $format_hours( $achieved ) . ' hrs' : '0 hrs';
		};
		$date_cell = static function ( string $category ) use ( $requirement_hours ): string {
			$date = (int) ( $requirement_hours[ $category ]['date'] ?? 0 );
			return $date > 0 ? wp_date( 'm/d/Y', $date ) : '-';
		};
		$matrix_row = static function ( string $label, string $met, string $category ) use ( $hours_cell, $date_cell, $is_annual_matrix, $audit_data ): string {
			$met = GHCA_Audit_Calculator::category_display( $audit_data, $category );
			if ( $is_annual_matrix && 'individual_plan' === $category && ! empty( $audit_data['agency_training']['isp'] ) && ( $audit_data['category_statuses']['individual_plan'] ?? '' ) === 'Completed — manually verified' ) {
				$met = 'Completed in person — agency confirmed';
			} elseif ( $is_annual_matrix && 'individual_plan' === $category && ! empty( $audit_data['isp_manual_delivery'] ) ) {
				$met = 'Handled manually / in person';
			} elseif ( $is_annual_matrix && 'individual_plan' === $category && ! empty( $audit_data['external_isp_evidence'] ) ) {
				$met = $audit_data['category_statuses'][ $category ];
			}
			$cells = '<td>' . esc_html( $label ) . '</td><td>' . esc_html( $met ) . '</td>';
			if ( ! $is_annual_matrix ) {
				$cells .= '<td>' . esc_html( $date_cell( $category ) ) . '</td>';
			}
			return '<tr>' . $cells . '<td>' . esc_html( $hours_cell( $category ) ) . '</td></tr>';
		};

		$html .= '<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
			<tr style="background-color: #f1f1f1;">
				<th width="' . ( $is_annual_matrix ? '50' : '40' ) . '%"><strong>Requirement Area</strong></th>
				<th width="' . ( $is_annual_matrix ? '20' : '15' ) . '%"><strong>Topic Status</strong></th>'
				. ( $is_annual_matrix ? '' : '<th width="20%"><strong>Completed On</strong></th>' ) .
				'<th width="' . ( $is_annual_matrix ? '30' : '25' ) . '%"><strong>' . ( $is_annual_matrix ? 'Hours Counted Here' : 'Time Spent' ) . '</strong></th>
			</tr>'
			. $matrix_row( 'Person-Centered Practices', $audit_data['person_centered'], 'person_centered' )
			. $matrix_row( 'Prevention of Abuse', $audit_data['abuse'], 'abuse_prevention' )
			. $matrix_row( 'Individual Rights', $audit_data['rights'], 'individual_rights' )
			. $matrix_row( 'Reporting Incidents', $audit_data['incidents'], 'reporting_incidents' );

		if ( $is_annual_matrix ) {
			$html .= $matrix_row( 'Behavior Supports', $audit_data['behavior'], 'behavior_supports' )
				. $matrix_row( 'Implementation of Individual Plan (ISP)', $audit_data['isp'], 'individual_plan' );
		} else {
			$html .= $matrix_row( 'Job-related Knowledge', $audit_data['job_related'], 'job_related' );
		}

		if ( ! empty( $audit_data['agency_selected_courses'] ) || ! empty( $audit_data['communication_manual_delivery'] ) ) {
			$html .= '<tr style="background-color:#f1f1f1"><td colspan="' . ( $is_annual_matrix ? '3' : '4' ) . '"><strong>Agency Mandated Training/compliance</strong></td></tr>';
			if ( ! empty( $audit_data['communication_manual_delivery'] ) ) {
				$confirmed = $audit_data['agency_training']['communication'] ?? array();
				$html .= '<tr><td>Communication Profile Training</td><td>' . ( $confirmed ? 'Completed in person — agency confirmed' : 'Handled by agency — not yet confirmed' ) . '</td><td>' . esc_html( $format_hours( (float) ( $confirmed['hours'] ?? 0 ) ) ) . ' hrs</td></tr>';
			}
			foreach ( $audit_data['agency_selected_courses'] as $course ) {
				$html .= '<tr><td>' . esc_html( $course['title'] ) . '</td><td>' . ( $course['included'] ? '' : 'No included evidence' ) . '</td>';
				if ( ! $is_annual_matrix ) { $html .= '<td>' . ( $course['date'] ? esc_html( wp_date( 'm/d/Y', $course['date'] ) ) : '-' ) . '</td>'; }
				$html .= '<td>' . esc_html( $format_hours( (float) $course['hours'] ) ) . ' hrs</td></tr>';
			}
		}
		$html .= '</table>';
		if ( $is_annual_matrix && ( ! empty( $audit_data['isp_manual_delivery'] ) || ! empty( $audit_data['agency_training']['isp'] ) ) ) {
			$html .= '<p><strong>ISP training delivery:</strong> Supporting records are maintained by the agency and provided separately for audit.</p>';
		} elseif ( $is_annual_matrix && ! empty( $audit_data['external_isp_evidence'] ) ) {
			$html .= '<p><strong>ISP evidence to be supplied separately — agency review required</strong><br>' . esc_html( $audit_data['category_notes']['individual_plan'] ) . '</p>';
		}
		$html .= $reporting
			? ''
			: '<p style="font-size:9px">Topic status reflects mapped evidence, not independent verification of course content. Each course credits hours to its primary category only; additional covered topics may show Completed with zero hours counted here. Unmapped topics need review, not an automatic failure. Conditional applicability is reviewed by an administrator for this employee and period.</p>';
		if ( ! $is_annual_matrix ) {
			$html .= '<p style="font-size: 9px; color: #555;">Orientation is assessed on completion of each required content area and on the timing rules below. Time spent is recorded for reference and is not a regulatory threshold.</p>';
		}
		$html .= '<br><br>';

		if ( 'annual' === $tracker_type ) {
			$html .= '<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
				<tr>
					<td width="70%"><strong>6100 topic training hours:</strong></td>
					<td width="30%">' . esc_html( $audit_data['total_annual_hrs'] ) . '</td>
				</tr>
				<tr>
					<td><strong>Additional training hours:</strong></td>
					<td>' . esc_html( $audit_data['additional_hrs'] ) . '</td>
				</tr>
				<tr style="background-color: #f9f9f9;">
					<td><strong>Total recorded training hours:</strong></td>
					<td><strong>' . esc_html( $audit_data['total_hrs'] ) . '</strong></td>
				</tr>
			</table>';
		} else {
			$worked_color = ( strpos( $audit_data['worked_alone_compliant'], 'Violation' ) !== false ) ? 'red' : 'green';
			if ( 'Missing evidence' === $audit_data['worked_alone_compliant'] ) {
				$worked_color = '#8a6d3b';
			}
			$html .= '<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
				<tr>
					<td width="70%"><strong>Orientation Completion Date:</strong></td>
					<td width="30%">' . esc_html( $audit_data['completion_date'] ) . '</td>
				</tr>
				<tr>
					<td><strong>Completed within 30 days of hire?</strong></td>
					<td>' . esc_html( $audit_data['completed_within_30'] ) . '</td>
				</tr>
				<tr>
					<td><strong>Completed BEFORE working alone?</strong></td>
					<td><strong style="color: ' . $worked_color . ';">' . esc_html( $audit_data['worked_alone_compliant'] ) . '</strong></td>
				</tr>
			</table>';
		}

		if ( GHCA_ACD_Settings::include_packet_course_details() && ! empty( $audit_data['raw_completed_courses'] ) ) {
			$pdf->writeHTML( $html, true, false, true, false, '' );
			$pdf->AddPage();
			$html = '<h3>Included Course Details</h3>
			<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
				<thead>
				<tr style="background-color: #f1f1f1;">
					<th width="25%"><strong>Course</strong></th>
					<th width="45%"><strong>Course Details</strong></th>
					<th width="16%"><strong>Completed</strong></th>
					<th width="14%"><strong>Credit Hrs Achieved</strong></th>
				</tr>
				</thead>
				<tbody>';
			foreach ( $audit_data['raw_completed_courses'] as $course ) {
				$description = ! empty( $course['description'] ) ? esc_html( $course['description'] ) : '<span style="color: #777;">Not provided</span>';
				$hours = rtrim( rtrim( number_format( (float) $course['credit_hours'], 2, '.', '' ), '0' ), '.' );
				$html .= '<tr nobr="true">
					<td width="25%"><strong>' . esc_html( $course['title'] ) . '</strong></td>
					<td width="45%">' . $description . '</td>
					<td width="16%">' . esc_html( wp_date( 'm/d/Y', (int) $course['date'] ) ) . '</td>
					<td width="14%">' . esc_html( $hours ) . '</td>
				</tr>';
			}
			$html .= '</tbody></table>';
		}

		$pdf->writeHTML( $html, true, false, true, false, '' );
	}

	private static function render_oltl_cover_body( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $audit_data ): void {
		$status_labels = array(
			'satisfied' => 'Satisfied', 'due' => 'Due', 'expired' => 'Expired', 'missing_evidence' => 'Missing Evidence',
			'manual_review' => 'Manual Review', 'not_applicable' => 'Not Applicable', 'evidence_ready' => 'Evidence Ready',
			'action_needed' => 'Action Needed', 'manual_review_required' => 'Manual Review Required',
		);
		$cycle_name = GHCA_ACD_Settings::annual_cycle_label( (string) ( $audit_data['annual_cycle'] ?? '' ) );
		$html = '<h2 style="text-align:center;">OLTL Chapter 52 Training Readiness Packet</h2>
		<p style="text-align:center;"><strong>' . esc_html( $status_labels[ $audit_data['overall_status'] ] ?? 'Manual Review Required' ) . '</strong></p>
		<table border="1" cellpadding="5" style="width:100%;border-collapse:collapse;">
			<tr><td width="28%"><strong>Employee</strong></td><td width="72%">' . esc_html( trim( $audit_data['first_name'] . ' ' . $audit_data['last_name'] ) ) . '</td></tr>
			<tr><td><strong>Role</strong></td><td>' . esc_html( (string) $audit_data['role'] ) . '</td></tr>
			<tr><td><strong>Date of Hire</strong></td><td>' . esc_html( (string) $audit_data['doh'] ) . '</td></tr>
			<tr><td><strong>Agency Reporting Cycle</strong></td><td>' . esc_html( $cycle_name . ': ' . $audit_data['start_date'] . ' to ' . $audit_data['end_date'] ) . '</td></tr>
			<tr><td><strong>Generated</strong></td><td>' . esc_html( wp_date( 'm/d/Y' ) ) . '</td></tr>
			<tr><td><strong>Regulatory Source</strong></td><td><a href="' . esc_url( (string) $audit_data['source_url'] ) . '">' . esc_html( (string) $audit_data['source_citation'] ) . '</a> (source verified ' . esc_html( (string) $audit_data['source_verified'] ) . ')</td></tr>
		</table><br><br>
		<h3>Requirement and Evidence Status</h3>
		<table border="1" cellpadding="5" style="width:100%;border-collapse:collapse;">
			<tr style="background-color:#f1f1f1;"><th width="43%"><strong>Requirement</strong></th><th width="20%"><strong>Status</strong></th><th width="37%"><strong>Evidence</strong></th></tr>';
		foreach ( (array) $audit_data['requirements'] as $requirement ) {
			$evidence = trim( (string) ( $requirement['source'] ?? '' ) . ( ! empty( $requirement['date'] ) ? ' — ' . $requirement['date'] : '' ) );
			if ( '' === $evidence ) {
				$evidence = (string) ( $requirement['note'] ?? 'No current-cycle evidence recorded' );
			}
			$html .= '<tr nobr="true"><td>' . esc_html( (string) $requirement['label'] ) . '</td><td>' . esc_html( $status_labels[ $requirement['status'] ] ?? ucwords( str_replace( '_', ' ', (string) $requirement['status'] ) ) ) . '</td><td>' . esc_html( $evidence ) . '</td></tr>';
		}
		$html .= '</table><br><br><h3>Included Training Records</h3>';
		if ( empty( $audit_data['raw_completed_courses'] ) ) {
			$html .= '<p>No current-cycle training records satisfy a mapped Chapter 52 topic.</p>';
		} else {
			$labels = GHCA_ACD_OLTL_Readiness::annual_requirements();
			$html .= '<table border="1" cellpadding="5" style="width:100%;border-collapse:collapse;"><tr style="background-color:#f1f1f1;"><th width="34%"><strong>Training</strong></th><th width="22%"><strong>Source</strong></th><th width="16%"><strong>Completed</strong></th><th width="28%"><strong>Chapter 52 Topics</strong></th></tr>';
			foreach ( $audit_data['raw_completed_courses'] as $course ) {
				$topics = array_map( static function ( string $code ) use ( $labels ): string { return (string) ( $labels[ $code ] ?? $code ); }, GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( $course['oltl_requirements'] ?? array() ) );
				$source = 'external' === (string) ( $course['source'] ?? '' ) ? ( 'manual' === (string) ( $course['entry_method'] ?? '' ) ? 'Approved external training (manual entry)' : 'Approved external training' ) : 'Platform completion';
				$html .= '<tr nobr="true"><td><strong>' . esc_html( (string) $course['title'] ) . '</strong>' . ( ! empty( $course['provider'] ) ? '<br>' . esc_html( (string) $course['provider'] ) : '' ) . '</td><td>' . esc_html( $source ) . '</td><td>' . esc_html( wp_date( 'm/d/Y', (int) $course['date'] ) ) . '</td><td>' . esc_html( implode( '; ', $topics ) ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		if ( ! empty( $audit_data['manual_reference_redacted'] ) ) {
			$html .= '<br><p><strong>Manual evidence reference:</strong> ' . esc_html( (string) $audit_data['manual_reference_redacted'] ) . '</p>';
		}
		$html .= '<br><div style="border:1px solid #d9d9d9;background-color:#f7f7f7;padding:10px;"><strong>Limitations:</strong> This packet summarizes documented training evidence against the common employee-training topics in ' . esc_html( (string) $audit_data['source_citation'] ) . '. It does not determine legal compliance or noncompliance, does not establish program- or payer-specific requirements, and uses the agency reporting cycle as an operational review window rather than a statutory OLTL deadline. Participant-specific supporting document contents are intentionally excluded.</div>';
		$pdf->SetFont( 'helvetica', '', 9.5 );
		$pdf->writeHTML( $html, true, false, true, false, '' );
	}

	/** Audit-facing description of an administrator manual entry, so packets distinguish it from signed Jotform submissions. */
	private static function manual_entry_label( array $course ): string {
		$user   = get_userdata( (int) ( $course['entered_by'] ?? 0 ) );
		$name   = $user ? (string) $user->display_name : ( '#' . (int) ( $course['entered_by'] ?? 0 ) );
		$reason = trim( (string) ( $course['entry_reason'] ?? '' ) );
		return 'Manual administrator entry by ' . $name . ( '' !== $reason ? '. Reason: ' . $reason : '' ) . '. Approved through External Training Review.';
	}

	/** Adds the full, unshortened LearnDash course description before its certificate. */
	public static function render_course_detail_page( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $course ): void {
		$hours = rtrim( rtrim( number_format( (float) ( $course['credit_hours'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
		$categories = class_exists( 'GHCA_Audit_Mapping' ) ? GHCA_Audit_Mapping::get_odp_categories() : array();
		$category = (string) ( $course['category'] ?? '' );
		if ( 'oltl' === (string) ( $course['framework'] ?? '' ) ) {
			$requirement_labels = GHCA_ACD_OLTL_Readiness::annual_requirements();
			$mapped = array_map( static function ( string $code ) use ( $requirement_labels ): string { return (string) ( $requirement_labels[ $code ] ?? $code ); }, GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( $course['oltl_requirements'] ?? array() ) );
			$category_label = $mapped ? implode( '; ', $mapped ) : 'Not provided';
		} else {
			$covered = (array) ( $course['categories'] ?? array( $category ) );
			$category_label = implode( '; ', array_map( static function ( $key ) use ( $categories ): string { return $categories[ $key ] ?? (string) $key; }, $covered ) );
			$category_label = $category_label ?: 'Not provided';
		}
		$description = trim( (string) ( $course['full_description'] ?? $course['description'] ?? '' ) );
		if ( '' === $description ) {
			$description = 'Description missing — review the source course content. Category mapping alone does not verify content coverage.';
		}

		$pdf->AddPage( 'P', 'A4' );
		$pdf->SetFont( 'helvetica', '', 10 );
		$html = '<h2 style="text-align: center;">Course Details</h2>
		<table border="1" cellpadding="7" style="width: 100%; border-collapse: collapse;">
			<tr>
				<td width="25%" style="background-color: #f1f1f1;"><strong>Course Name</strong></td>
				<td width="75%"><strong>' . esc_html( (string) ( $course['title'] ?? '' ) ) . '</strong></td>
			</tr>
			' . ( ! empty( $course['provider'] ) ? '<tr><td style="background-color: #f1f1f1;"><strong>Training Provider</strong></td><td>' . esc_html( (string) $course['provider'] ) . '</td></tr>' : '' ) . '
			' . ( 'manual' === (string) ( $course['entry_method'] ?? '' ) ? '<tr><td style="background-color: #f1f1f1;"><strong>Evidence Entry</strong></td><td>' . esc_html( self::manual_entry_label( $course ) ) . '</td></tr>' : '' ) . '
			<tr>
				<td style="background-color: #f1f1f1;"><strong>Completion Date</strong></td>
				<td>' . esc_html( wp_date( 'm/d/Y', (int) ( $course['date'] ?? 0 ) ) ) . '</td>
			</tr>
			' . ( 'oltl' === (string) ( $course['framework'] ?? '' ) ? '' : '<tr>
				<td style="background-color: #f1f1f1;"><strong>Credit Hours</strong></td>
				<td>' . esc_html( $hours ) . '</td>
			</tr>' ) . '
			<tr>
				<td style="background-color: #f1f1f1;"><strong>' . ( 'oltl' === (string) ( $course['framework'] ?? '' ) ? 'Chapter 52 Topics' : 'Training Category' ) . '</strong></td>
				<td>' . esc_html( $category_label ) . '</td>
			</tr>
		</table>
		<br><br>
		<h3>' . ( 'oltl' === (string) ( $course['framework'] ?? '' ) ? 'Training Content Documentation' : 'Short Description' ) . '</h3>
		<div style="border: 1px solid #ddd; padding: 12px; line-height: 1.5;">' . nl2br( esc_html( $description ) ) . '</div>';
		$pdf->writeHTML( $html, true, false, true, false, '' );
	}

	/**
	 * Imports every page of one local certificate PDF into the master document.
	 *
	 * @return bool false if the file is missing/malformed (caller decides policy).
	 */
	public static function append_certificate( \setasign\Fpdi\Tcpdf\Fpdi $pdf, string $path, int &$total_pages ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		try {
			\setasign\Fpdi\PdfParser\Filter\Flate::resetDecodedBudget();
			$page_count = $pdf->setSourceFile( $path );
			if ( ! self::can_append_certificate_pages( $total_pages, (int) $page_count ) ) {
				return false;
			}
			for ( $page_no = 1; $page_no <= $page_count; $page_no++ ) {
				$template_id = $pdf->importPage( $page_no );
				$size        = $pdf->getTemplateSize( $template_id );

				$orientation = $size['width'] > $size['height'] ? 'L' : 'P';
				$pdf->AddPage( $orientation, array( $size['width'], $size['height'] ) );
				$pdf->useTemplate( $template_id, 0, 0, $size['width'], $size['height'] );
			}
			$total_pages += (int) $page_count;
		} catch ( \Exception $e ) {
			// Malformed or encrypted certificate
			return false;
		}
		return true;
	}

	/** Flat 0-indexed certificate URL list for one employee's packet. */
	public static function collect_certificate_urls( array $audit_data, int $user_id, &$course_ids = null, &$brokerable = null, &$course_keys = null, &$evidence_refs = null ) {
		$urls               = array();
		$matched_course_ids = array();
		$matched_brokerable = array();
		$matched_course_keys = array();
		$matched_evidence_refs = array();
		foreach ( ( $audit_data['raw_completed_courses'] ?? array() ) as $course ) {
			if ( 'external' === (string) ( $course['source'] ?? '' ) ) {
				foreach ( (array) ( $course['evidence_manifest'] ?? array() ) as $item ) {
					if ( count( $urls ) >= self::MAX_CERTIFICATES ) {
						return new WP_Error( 'ghca_pdf_too_many_certificates', __( 'This packet contains too many certificates to process safely. Please contact an administrator.', 'ghca-acd' ) );
					}
					$urls[] = 'ghca-evidence:' . (int) $course['evidence_revision_id'] . ':' . (int) ( $item['file_index'] ?? 0 );
					$matched_course_ids[] = 0;
					$matched_brokerable[] = false;
					$matched_course_keys[] = (string) $course['course_key'];
					$matched_evidence_refs[] = array( 'training_id' => (int) $course['training_id'], 'revision_id' => (int) $course['evidence_revision_id'], 'snapshot_digest' => (string) $course['evidence_snapshot_digest'], 'item' => $item );
				}
				continue;
			}
			$can_broker = false;
			$course_id  = (int) $course['course_id'];
			$cert_url   = self::get_certificate_url( $user_id, $course_id, $can_broker );
			if ( '' !== $cert_url && ! in_array( $cert_url, $urls, true ) ) {
				if ( count( $urls ) >= self::MAX_CERTIFICATES ) {
					return new WP_Error( 'ghca_pdf_too_many_certificates', __( 'This packet contains too many certificates to process safely. Please contact an administrator.', 'ghca-acd' ) );
				}
				$urls[] = $cert_url;
				$matched_course_ids[] = $course_id;
				$matched_brokerable[] = $can_broker;
				$matched_course_keys[] = (string) ( $course['course_key'] ?? ( 'learndash:' . $course_id ) );
				$matched_evidence_refs[] = null;
			}
		}
		$course_ids = $matched_course_ids;
		$brokerable = $matched_brokerable;
		$course_keys = $matched_course_keys;
		$evidence_refs = $matched_evidence_refs;
		return array_values( $urls );
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $ref @return string|WP_Error */
	private static function materialize_external_evidence( array $job, int $index, array $ref, string $destination ) {
		$revision = GHCA_ACD_Jotform_Repository::evidence_revision( (int) ( $ref['revision_id'] ?? 0 ) );
		$training = GHCA_ACD_Jotform_Repository::training( (int) ( $ref['training_id'] ?? 0 ) );
		if ( ! $revision || ! $training || ! self::revision_digest_is_valid( $revision ) || 'approved' !== $revision['status'] || 'approved' !== $training['status'] || (int) $training['employee_user_id'] !== (int) $job['user_id'] || (int) $training['approved_revision_id'] !== (int) $revision['id'] || ! hash_equals( (string) $ref['snapshot_digest'], (string) $revision['snapshot_digest'] ) ) {
			return new WP_Error( 'ghca_evidence_binding_failed', __( 'Approved evidence no longer matches this packet job.', 'ghca-acd' ) );
		}
		$manifest = json_decode( (string) $revision['evidence_manifest'], true );
		$item = is_array( $ref['item'] ?? null ) ? $ref['item'] : array();
		$manifest_index = (int) ( $item['file_index'] ?? -1 );
		if ( ! is_array( $manifest ) || ! isset( $manifest[ $manifest_index ] ) || ! hash_equals( hash( 'sha256', wp_json_encode( $manifest[ $manifest_index ] ) ), hash( 'sha256', wp_json_encode( $item ) ) ) ) {
			return new WP_Error( 'ghca_evidence_manifest_failed', __( 'Approved evidence manifest changed.', 'ghca-acd' ) );
		}
		return GHCA_ACD_External_Evidence_Store::materialize( $training, $item, $destination );
	}

	/** @param array<string,mixed> $job */
	private static function external_evidence_refs_are_current( array $job, array $audit_data ): bool {
		$job_revision_ids = array();
		foreach ( (array) ( $job['evidence_refs'] ?? array() ) as $ref ) {
			if ( ! is_array( $ref ) ) {
				continue;
			}
			$revision = GHCA_ACD_Jotform_Repository::evidence_revision( (int) ( $ref['revision_id'] ?? 0 ) );
			$training = GHCA_ACD_Jotform_Repository::training( (int) ( $ref['training_id'] ?? 0 ) );
			if ( ! $revision || ! $training || ! self::revision_digest_is_valid( $revision ) || 'approved' !== $revision['status'] || 'approved' !== $training['status'] || (int) $training['employee_user_id'] !== (int) $job['user_id'] || (int) $training['approved_revision_id'] !== (int) $revision['id'] || ! hash_equals( (string) $ref['snapshot_digest'], (string) $revision['snapshot_digest'] ) ) {
				return false;
			}
			$job_revision_ids[ (int) $revision['id'] ] = true;
		}
		$current_revision_ids = array();
		foreach ( (array) ( $audit_data['raw_completed_courses'] ?? array() ) as $course ) {
			if ( 'external' === (string) ( $course['source'] ?? '' ) ) {
				$current_revision_ids[ (int) ( $course['evidence_revision_id'] ?? 0 ) ] = true;
			}
		}
		ksort( $job_revision_ids ); ksort( $current_revision_ids );
		return array_keys( $job_revision_ids ) === array_keys( $current_revision_ids );
	}

	/** @param array<string,mixed> $revision */
	private static function revision_digest_is_valid( array $revision ): bool {
		$expected = hash( 'sha256', (string) ( $revision['catalog_snapshot'] ?? '' ) . "\n" . (string) ( $revision['evidence_manifest'] ?? '' ) );
		return preg_match( '/^[a-f0-9]{64}$/', (string) ( $revision['snapshot_digest'] ?? '' ) ) && hash_equals( (string) $revision['snapshot_digest'], $expected );
	}

	public static function certificate_response_is_allowed( string $pdf_content, int $existing_bytes ): bool {
		$bytes = strlen( $pdf_content );
		return $bytes > 5
			&& $bytes <= self::MAX_CERTIFICATE_BYTES
			&& $existing_bytes >= 0
			&& ( $existing_bytes + $bytes ) <= self::MAX_TOTAL_CERTIFICATE_BYTES
			&& 0 === strpos( $pdf_content, '%PDF-' );
	}

	public static function can_append_certificate_pages( int $current_pages, int $next_pages ): bool {
		return $current_pages >= 0 && $next_pages > 0 && ( $current_pages + $next_pages ) <= self::MAX_CERTIFICATE_PAGES;
	}

	/** @return array<int,WP_Http_Cookie> */
	private static function get_certificate_request_cookies(): array {
		$allowed_names = defined( 'LOGGED_IN_COOKIE' ) ? array( constant( 'LOGGED_IN_COOKIE' ) ) : array();

		$cookies = array();
		$host    = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
		foreach ( array_unique( $allowed_names ) as $name ) {
			if ( isset( $_COOKIE[ $name ] ) ) {
				$cookies[] = new \WP_Http_Cookie( array(
					'name'   => $name,
					'value'  => wp_unslash( $_COOKIE[ $name ] ),
					'domain' => $host,
					'path'   => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				) );
			}
		}

		return $cookies;
	}

	private static function request_certificate( array $job, int $index, string $url ) {
		$options = array(
			'timeout'             => 25,
			'sslverify'           => true,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_CERTIFICATE_BYTES + 1,
		);

		if ( self::should_use_certificate_broker( $job, $index ) ) {
			$broker_url = GHCA_ACD_Data_Provider::sanitize_certificate_url( admin_url( 'admin-ajax.php' ) );
			if ( '' === $broker_url ) {
				return new WP_Error( 'ghca_pdf_broker_url', __( 'The certificate service URL is unavailable.', 'ghca-acd' ) );
			}
			$options['body'] = array(
				'action' => 'ghca_acd_pdf_certificate_broker',
				'job_id' => (string) $job['job_id'],
				'index'  => $index,
				'token'  => (string) ( $job['broker_tokens'][ $index ] ?? '' ),
			);
			return wp_safe_remote_post( $broker_url, $options );
		}

		$options['cookies'] = self::get_certificate_request_cookies();
		return wp_safe_remote_get( $url, $options );
	}

	private static function should_use_certificate_broker( array $job, int $index ): bool {
		if (
			(int) ( $job['user_id'] ?? 0 ) === get_current_user_id()
			|| empty( $job['brokerable'][ $index ] )
			|| (int) ( $job['course_ids'][ $index ] ?? 0 ) <= 0
		) {
			return false;
		}
		if ( function_exists( 'learndash_is_admin_user' ) && learndash_is_admin_user() ) {
			return false;
		}
		if ( function_exists( 'learndash_is_group_leader_user' ) && learndash_is_group_leader_user() ) {
			return false;
		}
		return true;
	}

	private static function deny_certificate_broker(): void {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Certificate request is unavailable.', 'ghca-acd' );
		exit;
	}

	private static function get_job_certificate_bytes( string $job_id, int $certificate_count, int $exclude_index = -1 ): int {
		$total = 0;
		for ( $index = 0; $index < $certificate_count; $index++ ) {
			if ( $index === $exclude_index ) {
				continue;
			}
			$path = GHCA_Audit_PDF_Jobs::cert_path( $job_id, $index );
			if ( is_file( $path ) ) {
				$total += max( 0, (int) filesize( $path ) );
			}
		}
		return $total;
	}

	public static function build_filename( array $audit_data ): string {
		if ( ! empty( $audit_data['audit_period'] ) ) {
			return 'Audit_Report_' . sanitize_title( $audit_data['first_name'] . '_' . $audit_data['last_name'] ) . '_' . $audit_data['audit_period']['start'] . '_to_' . $audit_data['audit_period']['end'] . '.pdf';
		}
		$prefix = 'oltl_training' === (string) ( $audit_data['packet_type'] ?? '' ) ? 'OLTL_Training_Readiness_Packet_' : 'Audit_Packet_';
		return $prefix . sanitize_title( $audit_data['first_name'] . '_' . $audit_data['last_name'] ) . '_' . wp_date( 'Y-m-d' ) . '.pdf';
	}

	private static function get_certificate_url( int $user_id, int $course_id, bool &$brokerable = false ): string {
		$brokerable = false;
		if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
			$link = learndash_get_course_certificate_link( $course_id, $user_id );
			if ( is_string( $link ) && $link !== '' ) {
				$safe_link = GHCA_ACD_Data_Provider::sanitize_certificate_url( $link );
				if ( '' !== $safe_link ) {
					$brokerable = true;
					return $safe_link;
				}
			}
		}

		$uo = get_user_meta( $user_id, '_uo-course-cert-' . $course_id, true );
		if ( is_array( $uo ) && ! empty( $uo ) ) {
			$first = reset( $uo );
			if ( is_string( $first ) && $first !== '' ) {
				return GHCA_ACD_Data_Provider::sanitize_certificate_url( $first );
			}
		}

		return '';
	}
}
