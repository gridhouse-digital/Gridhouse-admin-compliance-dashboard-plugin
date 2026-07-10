<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_Audit_Export {
	public static function init(): void {
		add_action( 'admin_post_ghca_acd_audit_export_csv', array( __CLASS__, 'handle_export' ) );
		add_shortcode( 'admin_audit_export_buttons', array( __CLASS__, 'render_buttons' ) );
	}

	public static function render_buttons( $atts = array() ): string {
		if ( ! GHCA_ACD_Roles::user_can_view() ) {
			return '';
		}

		$url_annual = wp_nonce_url(
			admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=annual' ),
			'ghca_acd_audit_export_csv'
		);

		$url_orientation = wp_nonce_url(
			admin_url( 'admin-post.php?action=ghca_acd_audit_export_csv&tracker=orientation' ),
			'ghca_acd_audit_export_csv'
		);

		ob_start();
		?>
		<div class="ghca-acd ghca-acd--export-inline" style="display: flex; gap: 10px; margin-top: 10px;">
			<a class="ghca-acd__btn ghca-acd__btn--export" href="<?php echo esc_url( $url_annual ); ?>">
				<?php esc_html_e( 'Download ODP Annual Tracker (CSV)', 'ghca-acd' ); ?>
			</a>
			<a class="ghca-acd__btn ghca-acd__btn--export" href="<?php echo esc_url( $url_orientation ); ?>">
				<?php esc_html_e( 'Download ODP Orientation Tracker (CSV)', 'ghca-acd' ); ?>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function handle_export(): void {
		if ( ! is_user_logged_in() || ! GHCA_ACD_Roles::user_can_view() ) {
			wp_die( esc_html__( 'Unauthorized.', 'ghca-acd' ) );
		}

		check_admin_referer( 'ghca_acd_audit_export_csv' );

		$tracker_type = ( isset( $_GET['tracker'] ) && 'orientation' === $_GET['tracker'] ) ? 'orientation' : 'annual';
		$employees    = GHCA_ACD_Data_Provider::get_employees_for_current_view();
		$filename     = 'odp-' . $tracker_type . '-tracker-' . wp_date( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Export failed.', 'ghca-acd' ) );
		}

		$mappings = get_option( 'ghca_acd_audit_mapping', array() );

		if ( 'annual' === $tracker_type ) {
			self::generate_annual_csv( $out, $employees, $mappings );
		} else {
			self::generate_orientation_csv( $out, $employees, $mappings );
		}

		fclose( $out );
		exit;
	}

	private static function csv_safe( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value; // leading apostrophe = Excel treats as text
		}
		return $value;
	}

	private static function generate_annual_csv( $out, array $employees, array $mappings ): void {
		$headers = array(
				'Staff Last Name',
				'Staff First Name',
				'Role',
				'Date of Hire (DOH)',
				'Start',
				'End',
				'Person-Centered Practices',
				'Prevention of Abuse',
				'Individual Rights',
				'Reporting Incidents',
				'Behavior Supports',
				'Implementation of Individual Plan',
				'Total 6100 Annual training hours',
				'Additional training hours',
				'Total training hours'
		);
		fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $headers ) );

		foreach ( $employees as $employee ) {
			$data = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'annual', $mappings );
			if ( empty( $data ) ) {
				continue;
			}
			$row = array(
				$data['last_name'],
				$data['first_name'],
				$data['role'],
				$data['doh'],
				$data['start_date'],
				$data['end_date'],
				$data['person_centered'],
				$data['abuse'],
				$data['rights'],
				$data['incidents'],
				$data['behavior'],
				$data['isp'],
				$data['total_annual_hrs'],
				$data['additional_hrs'],
				$data['total_hrs']
			);
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
		}
	}

	private static function generate_orientation_csv( $out, array $employees, array $mappings ): void {
		$headers = array(
				'Staff Last Name',
				'Staff First Name',
				'Role',
				'Date of Hire (DOH)',
				'Date of first time staff provided service',
				'Date of first time staff worked alone',
				'Person-Centered Practices',
				'Prevention of Abuse',
				'Individual Rights',
				'Reporting Incidents',
				'Job-related knowledge',
				'Orientation Completion Date',
				'Orientation completed within 30 days of hire?'
		);
		fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $headers ) );

		foreach ( $employees as $employee ) {
			$data = GHCA_Audit_Calculator::calculate_employee_audit_data( $employee, 'orientation', $mappings );
			if ( empty( $data ) ) {
				continue;
			}
			$row = array(
				$data['last_name'],
				$data['first_name'],
				$data['role'],
				$data['doh'],
				$data['first_service_date'],
				$data['worked_alone_date'],
				$data['person_centered'],
				$data['abuse'],
				$data['rights'],
				$data['incidents'],
				$data['job_related'],
				$data['completion_date'],
				$data['completed_within_30']
			);
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
		}
	}

	// Methods extracted to GHCA_Audit_Calculator.
}
