<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-audit-packet-layout.php';

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
	private static function guard_ajax( bool $expects_job, bool $cleanup_only = false ): ?array {
		check_ajax_referer( 'ghca_acd_table', 'nonce' );

		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() || ( ! $cleanup_only && ! GHCA_ACD_Roles::user_can_view_employee_documents() ) ) {
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
		} catch ( LogicException $exception ) {
			wp_send_json_error( array( 'message' => __( 'A packet job is already starting. Please try again.', 'ghca-acd' ) ), 409 );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => __( 'Private packet storage is unavailable. Contact an administrator.', 'ghca-acd' ) ), 500 );
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

		self::serve_certificate_pdf( $user_id, $certificate_id, $course_id );
	}

	/**
	 * Renders one employee's LearnDash certificate PDF as that employee and exits.
	 * Shared by the packet broker and the single-certificate download broker; callers
	 * must already have validated their one-time token and the course/certificate pair.
	 */
	public static function serve_certificate_pdf( int $user_id, int $certificate_id, int $course_id ): void {
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
		// Attachment numbers (A-1, A-2 ...) follow the merge order below, so the cover can list and cite them.
		$attachments = GHCA_Audit_Packet_Layout::attachment_labels( (array) ( $context['audit_data']['raw_completed_courses'] ?? array() ), (array) $job['course_keys'], count( $job['urls'] ) );
		self::render_cover( $pdf, $context['audit_data'], (string) $job['tracker'], array(
			'user_id'     => (int) $job['user_id'],
			'group'       => (string) ( $context['employee_data']['group'] ?? '' ),
			'attachments' => $attachments,
		) );
		$employee_name = trim( (string) ( $context['audit_data']['first_name'] ?? '' ) . ' ' . (string) ( $context['audit_data']['last_name'] ?? '' ) );
		$label_for     = static function ( int $index ) use ( $attachments, $job, $employee_name ): array {
			return 'oltl_training' === (string) $job['tracker'] || ! isset( $attachments[ $index ] ) ? array() : array( $attachments[ $index ]['label'], $employee_name );
		};

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
				if ( ! self::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $index ), $total_pages, $label_for( (int) $index ) ) ) {
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
			if ( ! self::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $i ), $total_pages, $label_for( $i ) ) ) {
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

		// Facts about the finished packet, for the ready notice: its real page count and the items its cover lists
		// under "Evidence review required". Nothing here is estimated.
		$page_count = (int) $pdf->getNumPages();
		$flagged    = count( (array) ( $context['audit_data']['evidence_issues'] ?? array() ) );
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
			'pages'        => $page_count,
			'flagged'      => $flagged,
			'tracker'      => (string) $job['tracker'],
			// The job (and so this link) lives until its manifest expires: created + TTL, or until it is downloaded
			// once, or until the same user starts another packet. Unix seconds; absent if the manifest has no time.
			'expires'      => ! empty( $job['created'] ) ? (int) $job['created'] + GHCA_Audit_PDF_Jobs::TTL : null,
		) );
	}

	/** Cancels an owned job and promptly removes its temporary PII files. */
	public static function ajax_cancel(): void {
		$job  = self::guard_ajax( true, true );
		$lock = GHCA_Audit_PDF_Jobs::acquire_job_lock( $job['job_id'], true );
		if ( false === $lock ) {
			wp_send_json_error( array( 'message' => __( 'This packet job could not be cancelled. Please try again.', 'ghca-acd' ) ), 409 );
		}
		try {
			GHCA_Audit_PDF_Jobs::delete_job( $job['job_id'] );
		} finally {
			GHCA_Audit_PDF_Jobs::release_lock( $lock );
		}
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
		// ODP Annual and Orientation packets print on US Letter (design board "Print: Orientation audit packet");
		// the OLTL packet keeps the library default.
		$format = 'oltl_training' === (string) ( $audit_data['packet_type'] ?? '' ) ? PDF_PAGE_FORMAT : 'LETTER';
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi( PDF_PAGE_ORIENTATION, PDF_UNIT, $format, true, 'UTF-8', false );

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

	/**
	 * Cover and summary pages. Caller must AddPage() first. Annual and Orientation packets use the print layout in
	 * GHCA_Audit_Packet_Layout ($context: user_id, group, attachments); the OLTL packet keeps its own cover.
	 */
	public static function render_cover( \setasign\Fpdi\Tcpdf\Fpdi $pdf, array $audit_data, string $tracker_type, array $context = array() ): void {
		if ( 'oltl_training' !== $tracker_type ) {
			GHCA_Audit_Packet_Layout::render( $pdf, $audit_data, $tracker_type, $context );
			return;
		}
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
		self::render_oltl_cover_body( $pdf, $audit_data );
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
	public static function manual_entry_label( array $course ): string {
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
	/** $label: optional [attachment label, employee name] stamped small at the top of each appended page. */
	public static function append_certificate( \setasign\Fpdi\Tcpdf\Fpdi $pdf, string $path, int &$total_pages, array $label = array() ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		// Plugin-owned evidence integrity check. FPDI copies a single content stream without decoding it and turns
		// any undecodable stream into a blank page (FpdiTrait::importPage), so corrupt or over-limit evidence would
		// otherwise be merged silently. Verify every page's content streams here and refuse the certificate.
		if ( ! self::certificate_content_is_decodable( $path ) ) {
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
				if ( $label ) {
					GHCA_Audit_Packet_Layout::stamp_attachment( $pdf, (string) $label[0], (string) ( $label[1] ?? '' ), $page_no, (int) $page_count );
				}
			}
			$total_pages += (int) $page_count;
		} catch ( \Exception $e ) {
			// Malformed or encrypted certificate
			return false;
		}
		return true;
	}

	/**
	 * True only when every page either has no /Contents at all (an intentionally blank page) or has /Contents made
	 * exclusively of PDF streams (one, or an array of them) that decode within the F-006 decoded-byte limits.
	 * A direct `/Contents null` is treated as absent (a blank page): per PDF 32000-1 7.3.7 a null-valued dictionary
	 * entry is the same as no entry, and the parser drops it. Every other /Contents entry is rejected: a number,
	 * dictionary or nested array; a null inside a /Contents array; a reference to a null object or a dangling
	 * reference; and an empty array.
	 * Scope: page content streams only - not XObjects, fonts or other components.
	 * Uses FPDI's public reader API and calls getUnfilteredStream() directly, so decode and limit failures
	 * propagate instead of being skipped. Any problem (corrupt stream, over-limit, malformed or recursive
	 * structure, unreadable file) returns false; nothing about the file is reported to the caller.
	 */
	public static function certificate_content_is_decodable( string $path ): bool {
		try {
			\setasign\Fpdi\PdfParser\Filter\Flate::resetDecodedBudget();
			$reader = new \setasign\Fpdi\PdfReader\PdfReader( new \setasign\Fpdi\PdfParser\PdfParser( \setasign\Fpdi\PdfParser\StreamReader::createByFile( $path ) ) );
			$pages  = $reader->getPageCount();
			if ( $pages < 1 || $pages > self::MAX_CERTIFICATE_PAGES ) {
				return false;
			}
			$parser = $reader->getParser();
			for ( $number = 1; $number <= $pages; $number++ ) {
				$page_dictionary = $reader->getPage( $number )->getPageDictionary();
				if ( ! isset( $page_dictionary->value['Contents'] ) ) {
					continue; // /Contents genuinely absent: an intentionally blank page.
				}
				$contents = \setasign\Fpdi\PdfParser\Type\PdfType::resolve( $page_dictionary->value['Contents'], $parser );
				$entries  = $contents instanceof \setasign\Fpdi\PdfParser\Type\PdfArray ? $contents->value : array( $contents );
				if ( array() === $entries ) {
					return false; // present but empty: not a content stream.
				}
				foreach ( $entries as $entry ) {
					$stream = \setasign\Fpdi\PdfParser\Type\PdfType::resolve( $entry, $parser );
					if ( ! $stream instanceof \setasign\Fpdi\PdfParser\Type\PdfStream ) {
						return false; // null, number, dictionary, nested array, dangling reference ...
					}
					$stream->getUnfilteredStream(); // throws on a corrupt or over-limit stream
				}
			}
		} catch ( \Throwable $e ) {
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
