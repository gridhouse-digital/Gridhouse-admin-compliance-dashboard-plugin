<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dry run for the training-year rules (GHCA_ACD_Training_Year), and the switch that enables them.
 *
 * The report evaluates every employee on the dashboard twice, with the rules off and on, in memory only, and lists
 * every employee whose due date, dashboard status, annual result or course expiry would change, plus everyone
 * without a verified hire date or an annual requirement. Building it writes nothing. It is available as an admin
 * page (Compliance → Training year, with CSV download) and as `wp ghca-acd training-year-review`.
 */
final class GHCA_ACD_Training_Year_Review {
	const PAGE   = 'ghca-acd-training-year';
	const ACTION_CSV    = 'ghca_acd_training_year_csv';
	const ACTION_SWITCH = 'ghca_acd_training_year_switch';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'admin_post_' . self::ACTION_CSV, array( __CLASS__, 'download_csv' ) );
		add_action( 'admin_post_' . self::ACTION_SWITCH, array( __CLASS__, 'handle_switch' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'ghca-acd training-year-review', array( __CLASS__, 'cli' ) );
		}
	}

	public static function register_page(): void {
		add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'Training year rules', 'ghca-acd' ), __( 'Training year', 'ghca-acd' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * The dry run. Read-only: the rules are switched in memory for each evaluation and restored.
	 *
	 * @param array<int> $user_ids Employees to evaluate.
	 * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
	 */
	public static function build( array $user_ids ): array {
		$mappings = get_option( 'ghca_acd_audit_mapping', array() );
		$mappings = is_array( $mappings ) ? $mappings : array();
		$rows     = array();
		$summary  = array( 'employees' => 0, 'changed' => 0, 'due_changed' => 0, 'status_changed' => 0, 'annual_changed' => 0, 'expiry_changed' => 0, 'missing_hire' => 0, 'requirement_not_set' => 0 );
		foreach ( array_values( array_unique( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			if ( $user_id <= 0 ) {
				continue;
			}
			$old = GHCA_ACD_Data_Provider::employee_record_for_review( $user_id, false );
			$new = GHCA_ACD_Data_Provider::employee_record_for_review( $user_id, true );
			$old_annual = self::annual( $user_id, $mappings, false );
			$new_annual = self::annual( $user_id, $mappings, true );
			$requirement = GHCA_ACD_Training_Year::requirement( $user_id );
			$hire        = GHCA_ACD_Employment_Record::timestamp( $user_id );

			$expiry = array();
			$old_courses = array();
			foreach ( (array) ( $old['courses'] ?? array() ) as $c ) {
				$old_courses[ (int) $c['id'] ] = $c;
			}
			foreach ( (array) ( $new['courses'] ?? array() ) as $c ) {
				$before = (string) ( $old_courses[ (int) $c['id'] ]['expiration_label'] ?? '' );
				$after  = (string) ( $c['expiration_label'] ?? '' );
				if ( $before !== $after ) {
					$expiry[] = (string) $c['title'] . ': ' . ( '' !== $before ? $before : __( 'no expiry', 'ghca-acd' ) ) . ' → ' . ( '' !== $after ? $after : __( 'no expiry (follows the training year)', 'ghca-acd' ) );
				}
			}

			$row = array(
				'user_id'         => $user_id,
				'name'            => (string) ( $new['name'] ?? '' ),
				'hire_date'       => $hire > 0 ? wp_date( 'M j, Y', $hire ) : '',
				'requirement'     => $requirement['set'] ? $requirement['label'] : '',
				'training_year'   => (string) ( $new['training_year_label'] ?? '' ),
				'due_old'         => (string) ( $old['due_date_label'] ?? '' ),
				'due_new'         => (string) ( $new['due_date_label'] ?? '' ),
				'status_old'      => (string) ( $old['status_label'] ?? '' ),
				'status_new'      => (string) ( $new['status_label'] ?? '' ),
				'annual_old'      => $old_annual,
				'annual_new'      => $new_annual,
				'expiry'          => $expiry,
				'deadline_basis'  => ! empty( $new['new_hire']['active'] ) ? (string) ( $new['new_hire']['deadline_basis'] ?? '' ) : '',
			);
			$row['due_changed']    = $row['due_old'] !== $row['due_new'];
			$row['status_changed'] = $row['status_old'] !== $row['status_new'];
			$row['annual_changed'] = $old_annual !== $new_annual;
			$row['missing_hire']   = $hire <= 0;
			$row['changed']        = $row['due_changed'] || $row['status_changed'] || $row['annual_changed'] || $expiry;
			$rows[] = $row;

			++$summary['employees'];
			$summary['changed']             += $row['changed'] ? 1 : 0;
			$summary['due_changed']         += $row['due_changed'] ? 1 : 0;
			$summary['status_changed']      += $row['status_changed'] ? 1 : 0;
			$summary['annual_changed']      += $row['annual_changed'] ? 1 : 0;
			$summary['expiry_changed']      += $expiry ? 1 : 0;
			$summary['missing_hire']        += $row['missing_hire'] ? 1 : 0;
			$summary['requirement_not_set'] += $requirement['set'] ? 0 : 1;
		}
		usort( $rows, static function ( array $a, array $b ): int { return strcasecmp( $a['name'], $b['name'] ); } );
		return array( 'rows' => $rows, 'summary' => $summary );
	}

	/** "In Progress · 13 of 24 h" — the annual audit result under one rule set; '' when no window can be computed. */
	private static function annual( int $user_id, array $mappings, bool $rules ): string {
		GHCA_ACD_Training_Year::set_override( $rules );
		try {
			$data = GHCA_Audit_Calculator::calculate_employee_audit_data( array( 'user_id' => $user_id, 'name' => '' ), 'annual', $mappings );
		} finally {
			GHCA_ACD_Training_Year::set_override( null );
		}
		if ( empty( $data ) || '' === (string) ( $data['start_date'] ?? '' ) ) {
			return '';
		}
		$labels = array( 'compliant' => __( 'Compliant', 'ghca-acd' ), 'in_progress' => __( 'In progress', 'ghca-acd' ), 'noncompliant' => __( 'Non-compliant', 'ghca-acd' ), 'missing_evidence' => __( 'Missing evidence', 'ghca-acd' ), 'needs_review' => __( 'Needs review', 'ghca-acd' ) );
		return ( $labels[ $data['annual_status'] ] ?? (string) $data['annual_status'] ) . ' · ' . GHCA_Audit_Packet_Layout::hours( (float) $data['total_hrs'] ) . ' of ' . (int) ( $data['required_annual_hrs'] ?? 24 ) . ' h';
	}

	/** Everyone on the dashboard for the current user (active and inactive). */
	public static function employee_ids(): array {
		$ids = array();
		foreach ( array_merge( GHCA_ACD_Data_Provider::get_employees_for_current_view(), GHCA_ACD_Data_Provider::get_inactive_employees_for_current_view() ) as $employee ) {
			$ids[] = (int) $employee['user_id'];
		}
		return $ids;
	}

	/** @return array<int,array<int,string>> CSV rows, header first. */
	public static function csv_rows( array $report ): array {
		$out = array( array( 'User ID', 'Employee', 'Verified hire date', 'Annual requirement', 'Training year (new)', 'Due date (old)', 'Due date (new)', 'Status (old)', 'Status (new)', 'Annual result (old)', 'Annual result (new)', 'Course expiry changes', 'New-hire deadline basis (new)', 'Changes' ) );
		foreach ( $report['rows'] as $r ) {
			$out[] = array( (string) $r['user_id'], $r['name'], '' !== $r['hire_date'] ? $r['hire_date'] : 'MISSING', '' !== $r['requirement'] ? $r['requirement'] : 'Not set (24 h assumed)', $r['training_year'], $r['due_old'], $r['due_new'], $r['status_old'], $r['status_new'], $r['annual_old'], $r['annual_new'], implode( '; ', $r['expiry'] ), $r['deadline_basis'], $r['changed'] ? 'yes' : 'no' );
		}
		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghca-acd' ) );
		}
		$report  = self::build( self::employee_ids() );
		$s       = $report['summary'];
		$enabled = GHCA_ACD_Training_Year::enabled();
		$changed = get_option( GHCA_ACD_Training_Year::OPTION_ENABLED_BY, array() );
		$show    = isset( $_GET['show'] ) && 'all' === $_GET['show'] ? 'all' : 'changes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Training year rules', 'ghca-acd' ); ?></h1>
			<p><?php esc_html_e( 'With these rules on, the audit training year drives everything. Due dates become the last day of the training year, the annual hours threshold follows each employee’s requirement (24 or 12), new-hire deadlines count from the verified hire date, and course lifespans count calendar days but do not apply to courses that earn annual ODP credit.', 'ghca-acd' ); ?></p>
			<p><strong><?php echo esc_html( $enabled ? __( 'Status: enabled.', 'ghca-acd' ) : __( 'Status: off. The dashboard uses the previous rules.', 'ghca-acd' ) ); ?></strong>
			<?php if ( is_array( $changed ) && ! empty( $changed['at'] ) ) : ?>
				<?php echo esc_html( sprintf( /* translators: 1: on/off, 2: user, 3: date */ __( 'Last turned %1$s by %2$s on %3$s.', 'ghca-acd' ), ! empty( $changed['enabled'] ) ? __( 'on', 'ghca-acd' ) : __( 'off', 'ghca-acd' ), self::user_name( (int) $changed['by'] ), wp_date( 'M j, Y g:i A', (int) $changed['at'] ) ) ); ?>
			<?php endif; ?></p>

			<h2><?php esc_html_e( 'Dry run', 'ghca-acd' ); ?></h2>
			<p><?php esc_html_e( 'Every employee on the dashboard, evaluated under the previous rules and the training-year rules. Nothing is saved.', 'ghca-acd' ); ?></p>
			<ul style="list-style:disc;margin-left:20px;">
				<li><?php echo esc_html( sprintf( __( '%1$d employees; %2$d would change.', 'ghca-acd' ), $s['employees'], $s['changed'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Due date changes: %1$d. Status changes: %2$d. Annual result changes: %3$d. Course expiry changes: %4$d.', 'ghca-acd' ), $s['due_changed'], $s['status_changed'], $s['annual_changed'], $s['expiry_changed'] ) ); ?></li>
				<li><strong><?php echo esc_html( sprintf( __( 'Missing a verified hire date: %d', 'ghca-acd' ), $s['missing_hire'] ) ); ?></strong> <?php esc_html_e( '(their due date would read "Hire date missing").', 'ghca-acd' ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Annual requirement not set: %d (24 h is assumed until it is set in Edit Records).', 'ghca-acd' ), $s['requirement_not_set'] ) ); ?></li>
			</ul>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CSV ), self::ACTION_CSV ) ); ?>"><?php esc_html_e( 'Download dry run (CSV)', 'ghca-acd' ); ?></a>
				<a class="button-link" style="margin-left:12px;" href="<?php echo esc_url( add_query_arg( 'show', 'changes' === $show ? 'all' : 'changes' ) ); ?>"><?php echo esc_html( 'changes' === $show ? __( 'Show all employees', 'ghca-acd' ) : __( 'Show changes and missing data only', 'ghca-acd' ) ); ?></a>
			</p>
			<table class="widefat striped" data-ghca-training-year-review>
				<thead><tr>
					<th><?php esc_html_e( 'Employee', 'ghca-acd' ); ?></th><th><?php esc_html_e( 'Verified hire date', 'ghca-acd' ); ?></th><th><?php esc_html_e( 'Requirement', 'ghca-acd' ); ?></th>
					<th><?php esc_html_e( 'Due date (old → new)', 'ghca-acd' ); ?></th><th><?php esc_html_e( 'Status (old → new)', 'ghca-acd' ); ?></th><th><?php esc_html_e( 'Annual result (old → new)', 'ghca-acd' ); ?></th><th><?php esc_html_e( 'Course expiry', 'ghca-acd' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$printed = 0;
				foreach ( $report['rows'] as $r ) :
					if ( 'changes' === $show && ! $r['changed'] && ! $r['missing_hire'] && '' !== $r['requirement'] ) {
						continue;
					}
					++$printed;
					$pair = static function ( string $old, string $new ): string {
						return $old === $new ? esc_html( '' !== $new ? $new : '—' ) : '<del>' . esc_html( '' !== $old ? $old : '—' ) . '</del> → <strong>' . esc_html( '' !== $new ? $new : '—' ) . '</strong>';
					};
					?>
					<tr data-user-id="<?php echo esc_attr( (string) $r['user_id'] ); ?>">
						<td><?php echo esc_html( $r['name'] ); ?><?php if ( '' !== $r['training_year'] ) : ?><br><span class="description"><?php echo esc_html( sprintf( __( 'Training year %s', 'ghca-acd' ), $r['training_year'] ) ); ?></span><?php endif; ?></td>
						<td><?php echo '' !== $r['hire_date'] ? esc_html( $r['hire_date'] ) : '<strong>' . esc_html__( 'Missing', 'ghca-acd' ) . '</strong>'; ?></td>
						<td><?php echo esc_html( '' !== $r['requirement'] ? $r['requirement'] : __( 'Not set (24 h assumed)', 'ghca-acd' ) ); ?></td>
						<td><?php echo $pair( $r['due_old'], $r['due_new'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $pair( $r['status_old'], $r['status_new'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $pair( $r['annual_old'], $r['annual_new'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $r['expiry'] ? esc_html( implode( '; ', $r['expiry'] ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( 0 === $printed ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No employee would change, and none is missing a hire date or requirement.', 'ghca-acd' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html( $enabled ? __( 'Turn the rules off', 'ghca-acd' ) : __( 'Enable', 'ghca-acd' ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SWITCH ); ?>" />
				<input type="hidden" name="enable" value="<?php echo esc_attr( $enabled ? '0' : '1' ); ?>" />
				<?php wp_nonce_field( self::ACTION_SWITCH ); ?>
				<?php if ( ! $enabled ) : ?>
					<p><label><input type="checkbox" name="reviewed" value="1" /> <?php esc_html_e( 'I reviewed the dry run above (and downloaded it for our records).', 'ghca-acd' ); ?></label></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Turning the rules off restores the previous due dates, threshold and deadlines immediately. Saved requirements and hire dates are kept.', 'ghca-acd' ); ?></p>
				<?php endif; ?>
				<?php submit_button( $enabled ? __( 'Turn training-year rules off', 'ghca-acd' ) : __( 'Enable training-year rules', 'ghca-acd' ), $enabled ? 'secondary' : 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function download_csv(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION_CSV ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghca-acd' ) );
		}
		$report = self::build( self::employee_ids() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="training-year-dry-run-' . sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $report ) as $row ) {
			GHCA_ACD_CSV::write_row( $out, $row );
		}
		fclose( $out );
		exit;
	}

	private static function user_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user && '' !== (string) $user->display_name ? (string) $user->display_name : '#' . $user_id;
	}

	public static function handle_switch(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION_SWITCH ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghca-acd' ) );
		}
		$enable = isset( $_POST['enable'] ) && '1' === $_POST['enable'];
		if ( $enable && ( ! isset( $_POST['reviewed'] ) || '1' !== $_POST['reviewed'] ) ) {
			wp_die( esc_html__( 'Confirm that you reviewed the dry run before enabling the training-year rules.', 'ghca-acd' ), '', array( 'back_link' => true ) );
		}
		update_option( GHCA_ACD_Training_Year::OPTION_ENABLED, $enable ? 1 : 0 );
		update_option( GHCA_ACD_Training_Year::OPTION_ENABLED_BY, array( 'enabled' => $enable, 'by' => get_current_user_id(), 'at' => time() ), false );
		GHCA_ACD_Data_Provider::bust_cache();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Dry run of the training-year rules for this site. Read-only. Run as an administrator, e.g.
	 * `wp ghca-acd training-year-review --user=admin --format=csv > dry-run.csv`.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or csv. Default: table (changed and missing-data rows only); csv lists everyone.
	 */
	public static function cli( array $args, array $assoc ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator, e.g. --user=<admin login>.' );
		}
		$report = self::build( self::employee_ids() );
		$rows   = self::csv_rows( $report );
		if ( 'csv' === ( $assoc['format'] ?? 'table' ) ) {
			$out = fopen( 'php://output', 'w' );
			foreach ( $rows as $row ) {
				GHCA_ACD_CSV::write_row( $out, $row );
			}
			fclose( $out );
			return;
		}
		$head = array_shift( $rows );
		$list = array();
		foreach ( $rows as $row ) {
			if ( 'yes' === $row[13] || 'MISSING' === $row[2] ) {
				$list[] = array_combine( $head, $row );
			}
		}
		WP_CLI\Utils\format_items( 'table', $list, array( 'Employee', 'Verified hire date', 'Due date (old)', 'Due date (new)', 'Status (old)', 'Status (new)', 'Annual result (old)', 'Annual result (new)' ) );
		WP_CLI::log( sprintf( '%d employees; %d would change; %d missing a verified hire date; %d without an annual requirement.', $report['summary']['employees'], $report['summary']['changed'], $report['summary']['missing_hire'], $report['summary']['requirement_not_set'] ) );
	}
}
