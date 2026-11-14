<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-training-year.php';
require_once __DIR__ . '/class-csv-writer.php';

/**
 * Training year page: the role map, the dry run for the training-year rules (GHCA_ACD_Training_Year), and the switch.
 *
 * - Role map: each GHCA job role → 24 h, 12 h, or Not required with its § 6100.143 exception. It can be filled while
 *   the switch is off; the dry run uses it.
 * - Dry run: every tracked employee evaluated with the rules off and on. Building it writes nothing (no option,
 *   user meta, cron, email, record or dashboard-aggregate transient). The page and the CSV read a separate dry-run
 *   cache that is written only when an administrator runs the dry run; the table is paginated.
 * - Also available as `wp ghca-acd training-year-review`.
 */
final class GHCA_ACD_Training_Year_Review {
	const PAGE          = 'ghca-acd-training-year';
	const ACTION_CSV    = 'ghca_acd_training_year_csv';
	const ACTION_SWITCH = 'ghca_acd_training_year_switch';
	const ACTION_RUN    = 'ghca_acd_training_year_run';
	const ACTION_MAP    = 'ghca_acd_training_role_map';
	const CACHE_KEY     = 'ghca_acd_ty_dryrun';  // one site-wide dry-run result; never the dashboard aggregate
	const CACHE_PREFIX  = 'ghca_acd_ty_dryrun_'; // rc.2 per-administrator keys, removed on invalidation
	const CACHE_TTL     = 12 * HOUR_IN_SECONDS;
	const CHUNK         = 25;                    // employees compared per request in the chunked run
	const PER_PAGE      = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'admin_post_' . self::ACTION_CSV, array( __CLASS__, 'download_csv' ) );
		add_action( 'admin_post_' . self::ACTION_SWITCH, array( __CLASS__, 'handle_switch' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_' . self::ACTION_MAP, array( __CLASS__, 'handle_role_map' ) );
		add_action( 'wp_ajax_ghca_acd_ty_run_start', array( __CLASS__, 'ajax_run_start' ) );
		add_action( 'wp_ajax_ghca_acd_ty_run_chunk', array( __CLASS__, 'ajax_run_chunk' ) );
		add_action( 'wp_ajax_ghca_acd_ty_run_save', array( __CLASS__, 'ajax_run_save' ) );
		// Anything that changes a dry-run result makes every administrator's cached result stale.
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'maybe_invalidate_for_meta' ), 10, 3 );
		}
		foreach ( array( GHCA_ACD_Training_Year::OPTION_ROLE_MAP, 'ghca_acd_annual_cycle', 'ghca_acd_audit_mapping', GHCA_ACD_Training_Year::OPTION_NEW_HIRE_ANCHOR ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'invalidate_cache' ) );
			add_action( 'add_option_' . $option, array( __CLASS__, 'invalidate_cache' ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'ghca-acd training-year-review', array( __CLASS__, 'cli' ) );
		}
	}

	public static function register_page(): void {
		add_submenu_page( GHCA_ACD_Admin_Menu::SLUG, __( 'Training year rules', 'ghca-acd' ), __( 'Training year', 'ghca-acd' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/** Display words for requirement()['source']. */
	public static function source_label( string $source ): string {
		$labels = array( 'override' => __( 'override', 'ghca-acd' ), 'role_map' => __( 'role map', 'ghca-acd' ), 'default' => __( 'default', 'ghca-acd' ) );
		return $labels[ $source ] ?? $source;
	}

	/**
	 * The dry run. Read-only: the rules are switched in memory for each evaluation and restored.
	 *
	 * @param array<int> $user_ids Employees to evaluate.
	 * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
	 */
	public static function build( array $user_ids ): array {
		return self::summarize( self::build_rows( $user_ids ) );
	}

	/** The rows only (no sorting, no summary). Zero-write. Used by build() and by each chunk of the chunked run. */
	public static function build_rows( array $user_ids ): array {
		$mappings = get_option( 'ghca_acd_audit_mapping', array() );
		$mappings = is_array( $mappings ) ? $mappings : array();
		$rows     = array();
		foreach ( array_values( array_unique( array_map( 'intval', $user_ids ) ) ) as $user_id ) {
			if ( $user_id <= 0 ) {
				continue;
			}
			$old         = GHCA_ACD_Data_Provider::employee_record_for_review( $user_id, false );
			$new         = GHCA_ACD_Data_Provider::employee_record_for_review( $user_id, true );
			$old_annual  = self::annual( $user_id, $mappings, false );
			$new_annual  = self::annual( $user_id, $mappings, true );
			$requirement = GHCA_ACD_Training_Year::requirement( $user_id );
			$hire        = GHCA_ACD_Employment_Record::timestamp( $user_id );

			$expiry      = array();
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
			$deadline = static function ( array $record ): string {
				return ! empty( $record['new_hire']['active'] ) ? (string) ( $record['new_hire']['deadline_label'] ?? '' ) : '';
			};

			$row = array(
				'user_id'        => $user_id,
				'name'           => (string) ( $new['name'] ?? '' ),
				'hire_date'      => $hire > 0 ? wp_date( 'M j, Y', $hire ) : '',
				'requirement'    => $requirement['label'],
				'hours'          => (int) $requirement['hours'],
				'source'         => $requirement['source'],
				'window_old'     => GHCA_ACD_Data_Provider::legacy_cycle_label( $user_id ),
				'training_year'  => (string) ( $new['training_year_label'] ?? '' ),
				'due_old'        => (string) ( $old['due_date_label'] ?? '' ),
				'due_new'        => (string) ( $new['due_date_label'] ?? '' ),
				'status_old'     => (string) ( $old['status_label'] ?? '' ),
				'status_new'     => (string) ( $new['status_label'] ?? '' ),
				'annual_old'     => $old_annual,
				'annual_new'     => $new_annual,
				'deadline_old'   => $deadline( $old ),
				'deadline_new'   => $deadline( $new ),
				'expiry'         => $expiry,
				'deadline_basis' => ! empty( $new['new_hire']['active'] ) ? (string) ( $new['new_hire']['deadline_basis'] ?? '' ) : '',
			);
			$row['due_changed']      = $row['due_old'] !== $row['due_new'];
			$row['status_changed']   = $row['status_old'] !== $row['status_new'];
			$row['annual_changed']   = $old_annual !== $new_annual;
			$row['deadline_changed'] = $row['deadline_old'] !== $row['deadline_new'];
			$row['missing_hire']     = $hire <= 0;
			$row['requirement_not_set'] = 'default' === $requirement['source'];
			$row['changed']          = $row['due_changed'] || $row['status_changed'] || $row['annual_changed'] || $row['deadline_changed'] || (bool) $expiry;
			$row['missing_data']     = $row['missing_hire'] || $row['requirement_not_set'];
			$rows[] = $row;
		}
		return $rows;
	}

	/** Sorted rows and the summary counts. */
	public static function summarize( array $rows ): array {
		$summary = array( 'employees' => 0, 'changed' => 0, 'due_changed' => 0, 'status_changed' => 0, 'annual_changed' => 0, 'deadline_changed' => 0, 'expiry_changed' => 0, 'missing_hire' => 0, 'requirement_not_set' => 0 );
		foreach ( $rows as $row ) {
			++$summary['employees'];
			foreach ( array( 'changed', 'due_changed', 'status_changed', 'annual_changed', 'deadline_changed', 'missing_hire', 'requirement_not_set' ) as $k ) {
				$summary[ $k ] += ! empty( $row[ $k ] ) ? 1 : 0;
			}
			$summary['expiry_changed'] += ! empty( $row['expiry'] ) ? 1 : 0;
		}
		usort( $rows, static function ( array $a, array $b ): int { return strcasecmp( $a['name'], $b['name'] ) ?: ( (int) $a['user_id'] <=> (int) $b['user_id'] ); } );
		return array( 'rows' => $rows, 'summary' => $summary );
	}

	/** The chunked-run loop: start → one request per chunk → verified save → reload. */
	private static function run_script(): string {
		return <<<'JS'
(function () {
  var form = document.querySelector('[data-ghca-ty-run]');
  if (!form || !window.fetch || !window.FormData) { return; }
  var out = form.querySelector('[data-ghca-ty-progress]');
  var button = form.querySelector('[type="submit"]');
  var post = function (action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', form.getAttribute('data-nonce'));
    Object.keys(data).forEach(function (k) {
      if (Array.isArray(data[k])) { data[k].forEach(function (v) { body.append(k + '[]', v); }); } else { body.append(k, data[k]); }
    });
    return fetch(form.getAttribute('data-ajax'), { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Request failed'); }
      return j.data;
    });
  };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    button.disabled = true;
    out.textContent = 'Starting…';
    post('ghca_acd_ty_run_start', {}).then(function (start) {
      var ids = start.ids, chunks = [], i = 0;
      var next = function () {
        if (i >= ids.length) {
          out.textContent = 'Saving the result…';
          return post('ghca_acd_ty_run_save', { run: start.run, ids: JSON.stringify(ids), ids_sig: start.ids_sig, chunks: JSON.stringify(chunks) });
        }
        var part = ids.slice(i, i + start.chunk);
        out.textContent = 'Compared ' + i + ' of ' + ids.length + ' employees…';
        return post('ghca_acd_ty_run_chunk', { run: start.run, ids: part }).then(function (c) { chunks.push(c); i += part.length; return next(); });
      };
      if (!ids.length) { return post('ghca_acd_ty_run_save', { run: start.run, ids: JSON.stringify(ids), ids_sig: start.ids_sig, chunks: '[]' }); }
      return next();
    }).then(function () { window.location.reload(); }).catch(function (err) {
      out.textContent = 'The dry run did not finish: ' + err.message + ' Nothing was saved.';
      button.disabled = false;
    });
  });
})();
JS;
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
		$labels   = array( 'compliant' => __( 'Compliant', 'ghca-acd' ), 'in_progress' => __( 'In progress', 'ghca-acd' ), 'noncompliant' => __( 'Non-compliant', 'ghca-acd' ), 'missing_evidence' => __( 'Missing evidence', 'ghca-acd' ), 'needs_review' => __( 'Needs review', 'ghca-acd' ) );
		$required = (int) ( $data['required_annual_hrs'] ?? 24 );
		return ( $labels[ $data['annual_status'] ] ?? (string) $data['annual_status'] ) . ' · ' . GHCA_Audit_Packet_Layout::hours( (float) $data['total_hrs'] ) . ( $required > 0 ? ' of ' . $required . ' h' : ' h (not required)' );
	}

	/** Every tracked employee visible to the current user, active and inactive. Writes nothing (no aggregate cache). */
	public static function employee_ids(): array {
		return GHCA_ACD_Data_Provider::review_employee_ids();
	}

	/** @return array<int,array<int,string>> CSV rows, header first. One row per employee. */
	public static function csv_rows( array $report ): array {
		$out = array( array( 'User ID', 'Employee', 'Verified hire date', 'Annual requirement', 'Threshold source', 'Training-year window (old)', 'Training-year window (new)', 'Due date (old)', 'Due date (new)', 'Status (old)', 'Status (new)', 'Annual result (old)', 'Annual result (new)', 'New-hire deadline (old)', 'New-hire deadline (new)', 'New-hire deadline basis (new)', 'Course expiry changes', 'Changes', 'Missing data' ) );
		foreach ( $report['rows'] as $r ) {
			$missing = array();
			if ( $r['missing_hire'] ) { $missing[] = 'Hire date missing'; }
			if ( $r['requirement_not_set'] ) { $missing[] = 'Role requirement not set'; }
			$out[] = array( (string) $r['user_id'], $r['name'], '' !== $r['hire_date'] ? $r['hire_date'] : 'MISSING', $r['requirement'], self::source_label( $r['source'] ), $r['window_old'], $r['training_year'], $r['due_old'], $r['due_new'], $r['status_old'], $r['status_new'], $r['annual_old'], $r['annual_new'], $r['deadline_old'], $r['deadline_new'], $r['deadline_basis'], implode( '; ', $r['expiry'] ), $r['changed'] ? 'yes' : 'no', implode( '; ', $missing ) );
		}
		return $out;
	}

	/** @return array{report:array,built_at:int,built_by:int}|null */
	public static function cached(): ?array {
		$c = get_transient( self::CACHE_KEY );
		return is_array( $c ) && isset( $c['report'], $c['built_at'] ) ? $c + array( 'built_by' => 0 ) : null;
	}

	/** Stores a finished run in the dry-run cache (the only write a run makes). */
	public static function store( array $report ): array {
		$entry = array( 'report' => $report, 'built_at' => time(), 'built_by' => get_current_user_id() );
		set_transient( self::CACHE_KEY, $entry, self::CACHE_TTL );
		return $entry;
	}

	/** Single-shot run (no-JavaScript fallback and tests): the comparison (zero-write), then the cache. */
	public static function run_and_cache(): array {
		return self::store( self::build( self::employee_ids() ) );
	}

	/** Removes every administrator's dry-run result: the shared one and any rc.2 per-administrator keys. */
	public static function invalidate_cache(): void {
		global $wpdb;
		delete_transient( self::CACHE_KEY );
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%' ) );
		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}

	/** User-meta hook: a per-employee override or a verified hire date changed. */
	public static function maybe_invalidate_for_meta( $meta_id, $user_id, $meta_key ): void {
		if ( in_array( $meta_key, array( GHCA_ACD_Training_Year::REQUIREMENT_META, GHCA_ACD_Employment_Record::META_KEY ), true ) ) {
			self::invalidate_cache();
		}
	}

	/* ------------------------------------------------------------ chunked run (large sites) */

	private static function ajax_guard(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::ACTION_RUN, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-acd' ) ), 403 );
		}
	}

	private static function sign( string $run, string $payload ): string {
		return wp_hash( 'ghca-ty-dryrun|' . get_current_user_id() . '|' . $run . '|' . $payload );
	}

	/** Starts a chunked run: the employee ids (signed) and the chunk size. Writes nothing. */
	public static function ajax_run_start(): void {
		self::ajax_guard();
		$ids = self::employee_ids();
		$run = wp_generate_password( 20, false );
		$json = (string) wp_json_encode( array_values( $ids ) );
		wp_send_json_success( array( 'run' => $run, 'ids' => $ids, 'ids_sig' => self::sign( $run, $json ), 'chunk' => self::CHUNK ) );
	}

	/** Compares one chunk of employees and returns the signed rows. Writes nothing. */
	public static function ajax_run_chunk(): void {
		self::ajax_guard();
		$run = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) ) : array();
		if ( '' === $run || ! $ids || count( $ids ) > self::CHUNK ) {
			wp_send_json_error( array( 'message' => __( 'Invalid dry-run chunk.', 'ghca-acd' ) ), 400 );
		}
		$ids  = array_values( array_intersect( $ids, self::employee_ids() ) );
		$rows = (string) wp_json_encode( self::build_rows( $ids ) );
		wp_send_json_success( array( 'rows' => $rows, 'sig' => self::sign( $run, $rows ) ) );
	}

	/** Verifies every chunk against the signed id list, then stores the finished report (the only write). */
	public static function ajax_run_save(): void {
		self::ajax_guard();
		$run     = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		$ids     = isset( $_POST['ids'] ) ? (string) wp_unslash( $_POST['ids'] ) : '';
		$ids_sig = isset( $_POST['ids_sig'] ) ? (string) wp_unslash( $_POST['ids_sig'] ) : '';
		$chunks  = isset( $_POST['chunks'] ) ? json_decode( (string) wp_unslash( $_POST['chunks'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by signature below.
		$result  = self::assemble( $run, $ids, $ids_sig, is_array( $chunks ) ? $chunks : array() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$entry = self::store( $result );
		wp_send_json_success( array( 'employees' => $entry['report']['summary']['employees'] ) );
	}

	/**
	 * Rebuilds the report from signed chunks. Every chunk must be signed for this run and user, and together they
	 * must cover exactly the signed employee list.
	 *
	 * @param array<int,array{rows:string,sig:string}> $chunks
	 * @return array|WP_Error
	 */
	public static function assemble( string $run, string $ids_json, string $ids_sig, array $chunks ) {
		if ( '' === $run || ! hash_equals( self::sign( $run, $ids_json ), $ids_sig ) ) {
			return new WP_Error( 'ghca_ty_run', __( 'The dry run could not be verified. Run it again.', 'ghca-acd' ) );
		}
		$want = array_map( 'intval', (array) json_decode( $ids_json, true ) );
		$rows = array();
		foreach ( $chunks as $chunk ) {
			$json = is_array( $chunk ) ? (string) ( $chunk['rows'] ?? '' ) : '';
			if ( ! hash_equals( self::sign( $run, $json ), (string) ( $chunk['sig'] ?? '' ) ) ) {
				return new WP_Error( 'ghca_ty_run', __( 'The dry run could not be verified. Run it again.', 'ghca-acd' ) );
			}
			foreach ( (array) json_decode( $json, true ) as $row ) {
				$rows[] = $row;
			}
		}
		$got = array_map( static function ( $r ) { return (int) $r['user_id']; }, $rows );
		sort( $want );
		sort( $got );
		if ( $want !== $got ) {
			return new WP_Error( 'ghca_ty_run', __( 'The dry run is incomplete. Run it again.', 'ghca-acd' ) );
		}
		return self::summarize( $rows );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghca-acd' ) );
		}
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function handle_run(): void {
		self::guard( self::ACTION_RUN );
		self::run_and_cache();
		self::back();
	}

	/** Saves the role map (manage_options, nonce). Unset roles stay unset. Clears the dry-run cache. */
	public static function handle_role_map(): void {
		self::guard( self::ACTION_MAP );
		$posted  = isset( $_POST['role_map'] ) && is_array( $_POST['role_map'] ) ? wp_unslash( $_POST['role_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by sanitize_role_map().
		$catalog = GHCA_ACD_Training_Year::role_catalog();
		$posted  = array_intersect_key( (array) $posted, $catalog );
		update_option( GHCA_ACD_Training_Year::OPTION_ROLE_MAP, GHCA_ACD_Training_Year::sanitize_role_map( $posted ), false );
		self::invalidate_cache();
		GHCA_ACD_Data_Provider::bust_cache();
		self::back();
	}

	private static function role_map_html(): string {
		$map     = GHCA_ACD_Training_Year::role_map();
		$catalog = GHCA_ACD_Training_Year::role_catalog();
		$h  = '<h2>' . esc_html__( 'Annual hours by job role', 'ghca-acd' ) . '</h2>';
		$h .= '<p><strong>' . esc_html( GHCA_ACD_Training_Year::basis_text() ) . '</strong></p>';
		$h .= '<p class="description">' . esc_html__( 'Map each role to 24 h, 12 h, or Not required with its exception. A role left unmapped counts as 24 h and is flagged “Role requirement not set”. With several roles, the highest requirement wins. A per-employee override in Edit Records takes precedence.', 'ghca-acd' ) . '</p>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ghca-role-map><input type="hidden" name="action" value="' . esc_attr( self::ACTION_MAP ) . '" />' . wp_nonce_field( self::ACTION_MAP, '_wpnonce', true, false );
		$h .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Job role', 'ghca-acd' ) . '</th><th>' . esc_html__( 'Annual requirement', 'ghca-acd' ) . '</th><th>' . esc_html__( 'Basis', 'ghca-acd' ) . '</th></tr></thead><tbody>';
		if ( ! $catalog ) {
			$h .= '<tr><td colspan="3">' . esc_html__( 'No employee roles found.', 'ghca-acd' ) . '</td></tr>';
		}
		foreach ( $catalog as $slug => $label ) {
			$value = GHCA_ACD_Training_Year::role_value( $slug, $label, $map );
			$sel   = '<select name="role_map[' . esc_attr( $slug ) . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: role */ __( 'Annual requirement for %s', 'ghca-acd' ), $label ) ) . '"><option value="">' . esc_html__( 'Not set (Role requirement not set: counts as 24 h)', 'ghca-acd' ) . '</option>';
			foreach ( GHCA_ACD_Training_Year::role_map_choices() as $key => $choice ) {
				$sel .= '<option value="' . esc_attr( $key ) . '"' . selected( $value['value'], $key, false ) . '>' . esc_html( $choice[0] ) . '</option>';
			}
			$sel  .= '</select>';
			$basis = $value['nurse_default'] ? __( 'Nurse role: 24 h is agency policy (not ODP text). Save to keep it, or change it.', 'ghca-acd' ) : ( '' === $value['value'] ? __( 'Role requirement not set', 'ghca-acd' ) : ( GHCA_ACD_Training_Year::is_nurse_role( $slug, $label ) ? __( 'Agency policy (nurse role)', 'ghca-acd' ) : __( '55 Pa. Code § 6100.143', 'ghca-acd' ) ) );
			$h    .= '<tr><td>' . esc_html( $label ) . ' <code>' . esc_html( $slug ) . '</code></td><td>' . $sel . '</td><td>' . esc_html( $basis ) . '</td></tr>';
		}
		$h .= '</tbody></table>';
		$h .= get_submit_button( __( 'Save role map', 'ghca-acd' ), 'primary', 'submit', false ) . '</form>';
		return $h;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghca-acd' ) );
		}
		$enabled = GHCA_ACD_Training_Year::enabled();
		$changed = get_option( GHCA_ACD_Training_Year::OPTION_ENABLED_BY, array() );
		$cache   = self::cached();
		$show    = isset( $_GET['show'] ) && 'all' === $_GET['show'] ? 'all' : 'changes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Training year rules', 'ghca-acd' ) . '</h1>';
		echo '<p>' . esc_html__( 'With these rules on, the audit training year drives everything. Due dates become the last day of the training year, the annual hours threshold follows each employee’s job role (24 h, 12 h or not required), new-hire deadlines count from the verified hire date, and course lifespans count calendar days but do not apply to courses that earn annual ODP credit.', 'ghca-acd' ) . '</p>';
		echo '<p><strong>' . esc_html( $enabled ? __( 'Status: enabled.', 'ghca-acd' ) : __( 'Status: off. The dashboard uses the previous rules.', 'ghca-acd' ) ) . '</strong> ';
		if ( is_array( $changed ) && ! empty( $changed['at'] ) ) {
			echo esc_html( sprintf( /* translators: 1: on/off, 2: user, 3: date */ __( 'Last turned %1$s by %2$s on %3$s.', 'ghca-acd' ), ! empty( $changed['enabled'] ) ? __( 'on', 'ghca-acd' ) : __( 'off', 'ghca-acd' ), self::user_name( (int) $changed['by'] ), wp_date( 'M j, Y g:i A', (int) $changed['at'] ) ) );
		}
		echo '</p>';
		echo self::role_map_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in role_map_html().

		echo '<h2>' . esc_html__( 'Dry run', 'ghca-acd' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every tracked employee, evaluated under the previous rules and the training-year rules. The comparison saves nothing; the result is kept in a separate dry-run cache for this page and the CSV until you run it again.', 'ghca-acd' ) . '</p>';
		// With JavaScript the run goes in chunks (CHUNK employees per request, with progress) so large sites do not time
		// out; without it the form falls back to a single request.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px;" data-ghca-ty-run data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION_RUN ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION_RUN ) . '" />';
		wp_nonce_field( self::ACTION_RUN );
		submit_button( $cache ? __( 'Run the dry run again', 'ghca-acd' ) : __( 'Run the dry run', 'ghca-acd' ), 'secondary', 'submit', false );
		echo ' <span role="status" aria-live="polite" data-ghca-ty-progress></span></form>';
		echo '<script>' . self::run_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script.
		if ( ! $cache ) {
			echo '<p>' . esc_html__( 'No dry run yet.', 'ghca-acd' ) . '</p>';
		} else {
			$report = $cache['report'];
			$s      = $report['summary'];
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CSV ), self::ACTION_CSV ) ) . '">' . esc_html__( 'Download dry run (CSV)', 'ghca-acd' ) . '</a>';
			echo '<p class="description" data-ghca-ty-run-by>' . esc_html( sprintf( /* translators: 1: user, 2: date */ __( 'Dry run by %1$s on %2$s.', 'ghca-acd' ), self::user_name( (int) $cache['built_by'] ), wp_date( 'M j, Y g:i A', (int) $cache['built_at'] ) ) ) . '</p>';
			echo '<ul style="list-style:disc;margin-left:20px;">';
			echo '<li>' . esc_html( sprintf( __( '%1$d employees; %2$d would change.', 'ghca-acd' ), $s['employees'], $s['changed'] ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( __( 'Due date changes: %1$d. Status changes: %2$d. Annual result changes: %3$d. New-hire deadline changes: %4$d. Course expiry changes: %5$d.', 'ghca-acd' ), $s['due_changed'], $s['status_changed'], $s['annual_changed'], $s['deadline_changed'], $s['expiry_changed'] ) ) . '</li>';
			echo '<li><strong>' . esc_html( sprintf( __( 'Missing a verified hire date: %d', 'ghca-acd' ), $s['missing_hire'] ) ) . '</strong> ' . esc_html__( '(their due date would read "Hire date missing").', 'ghca-acd' ) . '</li>';
			echo '<li><strong>' . esc_html( sprintf( __( 'Role requirement not set: %d', 'ghca-acd' ), $s['requirement_not_set'] ) ) . '</strong> ' . esc_html__( '(counted at 24 h until their role is mapped or an override is set).', 'ghca-acd' ) . '</li>';
			echo '</ul>';
			$rows = 'all' === $show ? $report['rows'] : array_values( array_filter( $report['rows'], static function ( $r ) { return $r['changed'] || $r['missing_data']; } ) );
			$pages = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
			$paged = min( $paged, $pages );
			echo '<p><a href="' . esc_url( add_query_arg( array( 'show' => 'changes' === $show ? 'all' : 'changes', 'paged' => 1 ) ) ) . '">' . esc_html( 'changes' === $show ? __( 'Show all employees', 'ghca-acd' ) : __( 'Show changes and missing data only', 'ghca-acd' ) ) . '</a></p>';
			$pair = static function ( string $old, string $new ): string {
				return $old === $new ? esc_html( '' !== $new ? $new : '—' ) : '<del>' . esc_html( '' !== $old ? $old : '—' ) . '</del> → <strong>' . esc_html( '' !== $new ? $new : '—' ) . '</strong>';
			};
			echo '<table class="widefat striped" data-ghca-training-year-review><thead><tr>';
			foreach ( array( __( 'Employee', 'ghca-acd' ), __( 'Verified hire date', 'ghca-acd' ), __( 'Requirement (source)', 'ghca-acd' ), __( 'Training-year window (old → new)', 'ghca-acd' ), __( 'Due date (old → new)', 'ghca-acd' ), __( 'Status (old → new)', 'ghca-acd' ), __( 'Annual result (old → new)', 'ghca-acd' ), __( 'New-hire deadline (old → new)', 'ghca-acd' ), __( 'Course expiry', 'ghca-acd' ) ) as $th ) {
				echo '<th>' . esc_html( $th ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $r ) {
				echo '<tr data-user-id="' . esc_attr( (string) $r['user_id'] ) . '"><td>' . esc_html( $r['name'] ) . '</td>';
				echo '<td>' . ( '' !== $r['hire_date'] ? esc_html( $r['hire_date'] ) : '<strong>' . esc_html__( 'Missing', 'ghca-acd' ) . '</strong>' ) . '</td>';
				echo '<td>' . ( $r['requirement_not_set'] ? '<strong>' . esc_html( $r['requirement'] ) . '</strong>' : esc_html( $r['requirement'] ) ) . ' <span class="description">(' . esc_html( self::source_label( $r['source'] ) ) . ')</span></td>';
				echo '<td>' . $pair( $r['window_old'], $r['training_year'] ) . '</td><td>' . $pair( $r['due_old'], $r['due_new'] ) . '</td><td>' . $pair( $r['status_old'], $r['status_new'] ) . '</td><td>' . $pair( $r['annual_old'], $r['annual_new'] ) . '</td><td>' . $pair( $r['deadline_old'], $r['deadline_new'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pair escapes.
				echo '<td>' . ( $r['expiry'] ? esc_html( implode( '; ', $r['expiry'] ) ) : '—' ) . '</td></tr>';
			}
			if ( ! $rows ) {
				echo '<tr><td colspan="9">' . esc_html__( 'No employee would change, and none is missing a hire date or role requirement.', 'ghca-acd' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $pages > 1 ) {
				echo '<p class="ghca-ty-pages">';
				for ( $p = 1; $p <= $pages; $p++ ) {
					echo $p === $paged ? '<strong>' . (int) $p . '</strong> ' : '<a href="' . esc_url( add_query_arg( 'paged', $p ) ) . '">' . (int) $p . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</p>';
			}
		}

		echo '<h2>' . esc_html( $enabled ? __( 'Turn the rules off', 'ghca-acd' ) : __( 'Enable', 'ghca-acd' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION_SWITCH ) . '" /><input type="hidden" name="enable" value="' . esc_attr( $enabled ? '0' : '1' ) . '" />';
		wp_nonce_field( self::ACTION_SWITCH );
		if ( ! $enabled ) {
			echo '<p><label><input type="checkbox" name="reviewed" value="1" /> ' . esc_html__( 'I reviewed the dry run above (and downloaded it for our records).', 'ghca-acd' ) . '</label></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Turning the rules off restores the previous due dates, threshold and deadlines immediately. The role map, overrides and hire dates are kept.', 'ghca-acd' ) . '</p>';
		}
		submit_button( $enabled ? __( 'Turn training-year rules off', 'ghca-acd' ) : __( 'Enable training-year rules', 'ghca-acd' ), $enabled ? 'secondary' : 'primary', 'submit', false );
		echo '</form></div>';
	}

	public static function download_csv(): void {
		self::guard( self::ACTION_CSV );
		$cache  = self::cached();
		if ( ! $cache ) {
			// Never run the comparison from a download link: only a stored "Run the dry run" result is exported.
			wp_die( esc_html__( 'Run the dry run first.', 'ghca-acd' ), esc_html__( 'Training year rules', 'ghca-acd' ), array( 'back_link' => true, 'response' => 409 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="training-year-dry-run-' . sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d', (int) $cache['built_at'] ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $cache['report'] ) as $row ) {
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
		self::guard( self::ACTION_SWITCH );
		$enable = isset( $_POST['enable'] ) && '1' === $_POST['enable'];
		if ( $enable && ( ! isset( $_POST['reviewed'] ) || '1' !== $_POST['reviewed'] ) ) {
			wp_die( esc_html__( 'Confirm that you reviewed the dry run before enabling the training-year rules.', 'ghca-acd' ), '', array( 'back_link' => true ) );
		}
		update_option( GHCA_ACD_Training_Year::OPTION_ENABLED, $enable ? 1 : 0 );
		update_option( GHCA_ACD_Training_Year::OPTION_ENABLED_BY, array( 'enabled' => $enable, 'by' => get_current_user_id(), 'at' => time() ), false );
		GHCA_ACD_Data_Provider::bust_cache();
		self::back();
	}

	/**
	 * Dry run of the training-year rules for this site. Read-only (the CLI does not use or write the page's cache).
	 * Run as an administrator, e.g. `wp ghca-acd training-year-review --user=admin --format=csv > dry-run.csv`.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or csv. Default: table (employees who change or have missing data: no verified hire date, or role
	 * requirement not set); csv lists everyone.
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
		WP_CLI\Utils\format_items( 'table', self::cli_table_rows( $report ), array( 'Employee', 'Verified hire date', 'Annual requirement', 'Threshold source', 'Due date (old)', 'Due date (new)', 'New-hire deadline (old)', 'New-hire deadline (new)', 'Annual result (old)', 'Annual result (new)', 'Missing data' ) );
		WP_CLI::log( sprintf( '%d employees; %d would change; %d missing a verified hire date; %d with role requirement not set.', $report['summary']['employees'], $report['summary']['changed'], $report['summary']['missing_hire'], $report['summary']['requirement_not_set'] ) );
	}

	/** The CLI table: the same "changes or missing data" rule as the page (unmapped roles included). */
	public static function cli_table_rows( array $report ): array {
		$rows = self::csv_rows( $report );
		$head = array_shift( $rows );
		$list = array();
		foreach ( $rows as $row ) {
			$item = array_combine( $head, $row );
			if ( 'yes' === $item['Changes'] || '' !== $item['Missing data'] ) {
				$list[] = $item;
			}
		}
		return $list;
	}
}
