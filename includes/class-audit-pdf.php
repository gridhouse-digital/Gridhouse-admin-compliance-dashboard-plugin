<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_PDF {
	public static function init(): void {
		add_action( 'admin_post_ghca_acd_download_packet', array( __CLASS__, 'handle_download' ) );
	}

	public static function handle_download(): void {
		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			wp_die( esc_html__( 'Unauthorized.', 'ghca-acd' ) );
		}

		check_admin_referer( 'ghca_acd_download_packet' );

		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		if ( $user_id <= 0 || ! GHCA_ACD_User_Report::can_view_user( $user_id ) ) {
			wp_die( esc_html__( 'Invalid employee or permission denied.', 'ghca-acd' ) );
		}

		// Ensure TCPDF is loaded (LearnDash bundles it)
		if ( ! class_exists( 'TCPDF' ) ) {
			$tcpdf_path = WP_PLUGIN_DIR . '/sfwd-lms/includes/lib/tcpdf/tcpdf.php';
			if ( file_exists( $tcpdf_path ) ) {
				require_once $tcpdf_path;
			} else {
				wp_die( esc_html__( 'LearnDash TCPDF library not found.', 'ghca-acd' ) );
			}
		}

		// Load our bundled FPDI
		$fpdi_autoload = __DIR__ . '/lib/fpdi/autoload.php';
		if ( file_exists( $fpdi_autoload ) ) {
			require_once $fpdi_autoload;
		} else {
			wp_die( esc_html__( 'FPDI library not found in plugin.', 'ghca-acd' ) );
		}

		// Grab Employee Data & Config
		$employees = GHCA_Admin_Compliance_Dashboard::get_employees_for_current_view();
		$employee_data = null;
		foreach ( $employees as $emp ) {
			if ( (int) $emp['user_id'] === $user_id ) {
				$employee_data = $emp;
				break;
			}
		}

		if ( ! $employee_data ) {
			// Fallback if they were somehow excluded from current view but valid
			$user_info = get_userdata( $user_id );
			$employee_data = array(
				'user_id' => $user_id,
				'name'    => $user_info ? $user_info->display_name : 'Unknown',
				'email'   => $user_info ? $user_info->user_email : '',
				'group'   => '',
			);
		}

		$mappings = get_option( 'ghca_acd_audit_mapping', array() );
		$tracker_type = isset( $_GET['tracker'] ) && $_GET['tracker'] === 'orientation' ? 'orientation' : 'annual';
		$audit_data = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee_data, $tracker_type, $mappings );

		if ( empty( $audit_data ) ) {
			wp_die( esc_html__( 'Employee is excluded from audits or has an ignored role.', 'ghca-acd' ) );
		}

		self::generate_packet( $audit_data, $employee_data, $tracker_type );
	}

	private static function generate_packet( array $audit_data, array $employee_data, string $tracker_type ): void {
		// Initialize FPDI (which extends TCPDF)
		$pdf = new \setasign\Fpdi\Tcpdf\Fpdi( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );

		// Set Document Info
		$pdf->SetCreator( 'Gridhouse Compliance Dashboard' );
		$pdf->SetAuthor( 'Gridhouse Healthcare Academy' );
		$pdf->SetTitle( 'Compliance Audit Packet - ' . $audit_data['first_name'] . ' ' . $audit_data['last_name'] );

		// Remove default header/footer
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetAutoPageBreak( true, 15 );

		// ---------------------------------------------------------
		// PAGE 1: COVER PAGE (Compliance Matrix)
		// ---------------------------------------------------------
		$pdf->AddPage();
		
		// Agency Name
		$agency_name = get_bloginfo( 'name' );
		if ( ! empty( $agency_name ) ) {
			$pdf->SetFont( 'helvetica', 'B', 18 );
			$pdf->Cell( 0, 10, $agency_name, 0, 1, 'C' );
			$pdf->Ln( 2 );
		}

		$pdf->SetFont( 'helvetica', 'B', 14 );
		$pdf->Cell( 0, 10, 'Compliance Audit Packet', 0, 1, 'C' );
		
		$pdf->SetFont( 'helvetica', 'B', 12 );
		$pdf->Cell( 0, 10, 'Employee: ' . $audit_data['first_name'] . ' ' . $audit_data['last_name'], 0, 1, 'C' );
		
		$pdf->Ln( 5 );

		// Details block
		$pdf->SetFont( 'helvetica', '', 10 );
		$html = '
		<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
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

		if ( 'annual' === $tracker_type ) {
			$cycle_name = ( $audit_data['annual_cycle'] === 'calendar_year' ) ? 'Calendar Year' : 'Employee Anniversary';
			$html .= '<h3>Annual ODP Requirements Matrix</h3>';
			$html .= '<p><strong>Annual Cycle Rule:</strong> ' . esc_html( $cycle_name ) . '<br>';
			$html .= '<strong>Window Reviewed:</strong> ' . esc_html( $audit_data['start_date'] ) . ' to ' . esc_html( $audit_data['end_date'] ) . '</p>';
			
			if ( 'not_due' === $audit_data['annual_status'] ) {
				$html .= '<div style="background-color: #fcf8e3; border: 1px solid #faebcc; padding: 10px; color: #8a6d3b;">
					<strong>Notice:</strong> This employee has not yet completed their first full 12-month annual training cycle. Annual training is <strong>Not Due</strong>. Progress is shown below for reference only.
				</div><br>';
			} else {
				$status_color = ( 'compliant' === $audit_data['annual_status'] ) ? '#dff0d8' : '#f2dede';
				$status_text  = ( 'compliant' === $audit_data['annual_status'] ) ? 'Compliant' : 'Non-Compliant';
				$html .= '<div style="background-color: ' . $status_color . '; padding: 10px; border: 1px solid #ccc;">
					<strong>Overall Annual Status:</strong> ' . $status_text . '
				</div><br>';
			}
		} else {
			$html .= '<h3>Orientation ODP Requirements Matrix</h3>';
		}

		$html .= '<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
			<tr style="background-color: #f1f1f1;">
				<th width="70%"><strong>Requirement Area</strong></th>
				<th width="30%"><strong>Met/Completed</strong></th>
			</tr>
			<tr>
				<td>Person-Centered Practices</td>
				<td>' . esc_html( $audit_data['person_centered'] ) . '</td>
			</tr>
			<tr>
				<td>Prevention of Abuse</td>
				<td>' . esc_html( $audit_data['abuse'] ) . '</td>
			</tr>
			<tr>
				<td>Individual Rights</td>
				<td>' . esc_html( $audit_data['rights'] ) . '</td>
			</tr>
			<tr>
				<td>Reporting Incidents</td>
				<td>' . esc_html( $audit_data['incidents'] ) . '</td>
			</tr>';
			
		if ( 'annual' === $tracker_type ) {
			$html .= '
			<tr>
				<td>Behavior Supports</td>
				<td>' . esc_html( $audit_data['behavior'] ) . '</td>
			</tr>
			<tr>
				<td>Implementation of Individual Plan (ISP)</td>
				<td>' . esc_html( $audit_data['isp'] ) . '</td>
			</tr>';
		} else {
			$html .= '
			<tr>
				<td>Job-related Knowledge</td>
				<td>' . esc_html( $audit_data['job_related'] ) . '</td>
			</tr>';
		}
		
		$html .= '</table><br><br>';

		if ( 'annual' === $tracker_type ) {
			$req_color = ( $audit_data['total_hrs'] >= 24 ) ? 'green' : 'red';
			$html .= '<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
				<tr>
					<td width="70%"><strong>Total 6100 Annual Training Hours:</strong></td>
					<td width="30%">' . esc_html( $audit_data['total_annual_hrs'] ) . '</td>
				</tr>
				<tr>
					<td><strong>Additional Training Hours:</strong></td>
					<td>' . esc_html( $audit_data['additional_hrs'] ) . '</td>
				</tr>
				<tr style="background-color: #f9f9f9;">
					<td><strong>Total Training Hours:</strong></td>
					<td><strong style="color: ' . $req_color . ';">' . esc_html( $audit_data['total_hrs'] ) . ' / 24 Required</strong></td>
				</tr>
			</table>';
		} else {
			$worked_color = ( strpos( $audit_data['worked_alone_compliant'], 'Violation' ) !== false ) ? 'red' : 'green';
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

		$pdf->writeHTML( $html, true, false, true, false, '' );


		// ---------------------------------------------------------
		// PAGES 2+: CERTIFICATES
		// ---------------------------------------------------------
		// For certificates, we need to fetch them via HTTP because they are generated dynamically
		// We pass our admin session cookies so LearnDash allows us to see them.
		
		$cookies = array();
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( array( 'name' => $name, 'value' => $value ) );
		}

		// Fallback to get_certificate_url if not injected via raw_completed_courses. But we have it.
		$raw_courses = $audit_data['raw_completed_courses'] ?? array();
		
		foreach ( $raw_courses as $course ) {
			// In some setups, get_certificate_url is public in GHCA_Compliance_Program, let's verify. 
			// Wait, in class-compliance-program.php, get_certificate_url is 'private'!
			// Let's reflection-invoke it or just copy the logic. 
			// I'll copy the logic since it's just 10 lines.
			$cert_url = self::get_certificate_url( $employee_data['user_id'], $course['course_id'] );
			
			if ( empty( $cert_url ) ) {
				continue;
			}
			
			$response = wp_remote_get( $cert_url, array(
				'timeout'     => 15,
				'cookies'     => $cookies,
				'sslverify'   => false, 
			) );

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$pdf_content = wp_remote_retrieve_body( $response );
				
				// Ensure it is actually a PDF by checking signature
				if ( strpos( $pdf_content, '%PDF-' ) === 0 ) {
					// FPDI can only parse files or streams. Since we have a string in memory,
					// we write to a temp file.
					$temp_file = wp_tempnam( 'ghca_cert_' );
					if ( $temp_file ) {
						file_put_contents( $temp_file, $pdf_content );
						
						try {
							$page_count = $pdf->setSourceFile( $temp_file );
							for ( $page_no = 1; $page_no <= $page_count; $page_no++ ) {
								$template_id = $pdf->importPage( $page_no );
								$size = $pdf->getTemplateSize( $template_id );
								
								$orientation = $size['width'] > $size['height'] ? 'L' : 'P';
								$pdf->AddPage( $orientation, array( $size['width'], $size['height'] ) );
								$pdf->useTemplate( $template_id, 0, 0, $size['width'], $size['height'] );
							}
						} catch ( \Exception $e ) {
							// Skip this certificate if it's malformed or encrypted
						}
						
						@unlink( $temp_file );
					}
				}
			}
		}

		// Output PDF
		$filename = 'Audit_Packet_' . sanitize_title( $audit_data['first_name'] . '_' . $audit_data['last_name'] ) . '_' . wp_date('Y-m-d') . '.pdf';
		
		// Clean the output buffer to avoid corrupting the PDF
		if ( ob_get_length() ) {
			ob_clean();
		}
		
		$pdf->Output( $filename, 'D' );
		exit;
	}

	private static function get_certificate_url( int $user_id, int $course_id ): string {
		if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
			$link = learndash_get_course_certificate_link( $course_id, $user_id );
			if ( is_string( $link ) && $link !== '' ) {
				return $link;
			}
		}

		$uo = get_user_meta( $user_id, '_uo-course-cert-' . $course_id, true );
		if ( is_array( $uo ) && ! empty( $uo ) ) {
			$first = reset( $uo );
			if ( is_string( $first ) && $first !== '' ) {
				return $first;
			}
		}

		return '';
	}
}
