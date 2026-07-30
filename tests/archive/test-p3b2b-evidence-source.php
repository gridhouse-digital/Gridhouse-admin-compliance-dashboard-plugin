<?php
require_once __DIR__ . '/bootstrap.php';

final class GHCA_P3B2B_Scripted_Source_DB {
	/** @var string */
	public $last_error = '';
	/** @var array<int,string> */
	public $queries = array();
	/** @var bool */
	public $closed = false;
	/** @var bool */
	public $rollback_fails = false;
	/** @var bool */
	public $close_fails = false;
	/** @var bool */
	public $rollback_throws = false;
	/** @var bool */
	public $close_throws = false;
	/** @var string|null */
	public $throw_query_containing;
	/** @var callable|null */
	public $on_rollback;
	/** @var callable|null */
	public $on_close;
	/** @var string */
	public $table_engine = 'InnoDB';
	/** @var array<int,array<string,mixed>>|null */
	public $grants;
	/** @var string */
	public $authenticated_user = 'ghca_source@%';
	/** @var string|null */
	public $fail_results_containing;
	/** @var string|null */
	public $throw_results_containing;
	/** @var array<string,array<int,array<string,mixed>>> */
	private $fixture;
	/** @var array<string,string> */
	private $tables;

	/** @param array<string,array<int,array<string,mixed>>> $fixture @param array<string,string> $tables */
	public function __construct( array $fixture, array $tables ) {
		$this->fixture = $fixture;
		$this->tables = $tables;
	}

	/** @param mixed ...$args */
	public function prepare( string $sql, ...$args ): string {
		$index = 0;
		return preg_replace_callback( '/%[sd]/', static function ( array $match ) use ( &$index, $args ): string {
			$value = $args[ $index++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
		}, $sql );
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $sql, string $output ): array {
		$this->queries[] = $sql;
		if ( null !== $this->throw_results_containing && false !== strpos( $sql, $this->throw_results_containing ) ) {
			throw new RuntimeException( 'redacted' );
		}
		if ( null !== $this->fail_results_containing && false !== strpos( $sql, $this->fail_results_containing ) ) {
			$this->last_error = 'redacted';
			return array();
		}
		if ( false !== strpos( $sql, 'CONNECTION_ID()' ) ) {
			return array( array(
				'connection_id' => '12',
				'authenticated_user' => $this->authenticated_user,
				'database_name' => 'ghca_acd_archive_test_unit_source',
				'session_time_zone' => '+00:00',
				'connection_charset' => 'utf8mb4',
			) );
		}
		if ( false !== strpos( $sql, 'SHOW GRANTS FOR CURRENT_USER()' ) ) {
			if ( null !== $this->grants ) {
				return $this->grants;
			}
			$grants = array( array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ) );
			foreach ( $this->tables as $table ) {
				$grants[] = array(
					'grant' => 'GRANT SELECT ON `ghca_acd_archive_test_unit_source`.`' . $table . '` TO `ghca_source`@`%`',
				);
			}
			return $grants;
		}
		if ( false !== strpos( $sql, 'information_schema.tables' ) ) {
			$rows = array();
			foreach ( $this->tables as $table ) {
				$rows[] = array( 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => $this->table_engine );
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'information_schema.columns' ) ) {
			$columns = array(
				'users_table' => array( 'ID', 'user_email', 'user_registered', 'display_name' ),
				'usermeta_table' => array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' ),
				'options_table' => array( 'option_id', 'option_name', 'option_value' ),
				'posts_table' => array( 'ID', 'post_title', 'post_status', 'post_type', 'post_modified_gmt', 'post_parent', 'post_date' ),
				'postmeta_table' => array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
				'learndash_user_activity_table' => array(
					'activity_id', 'user_id', 'post_id', 'course_id', 'activity_type',
					'activity_status', 'activity_started', 'activity_completed', 'activity_updated',
				),
				'learndash_user_activity_meta_table' => array(
					'activity_meta_id', 'activity_id', 'activity_meta_key', 'activity_meta_value',
				),
			);
			$rows = array();
			foreach ( $columns as $role => $names ) {
				foreach ( $names as $name ) {
					$rows[] = array( 'TABLE_NAME' => $this->tables[ $role ], 'COLUMN_NAME' => $name );
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'information_schema.statistics' ) ) {
			$indexes = array(
				'users_table' => array( 'PRIMARY' => array( 'ID' ) ),
				'usermeta_table' => array( 'PRIMARY' => array( 'umeta_id' ), 'user_id' => array( 'user_id' ), 'meta_key' => array( 'meta_key' ) ),
				'options_table' => array( 'PRIMARY' => array( 'option_id' ), 'option_name' => array( 'option_name' ) ),
				'posts_table' => array( 'PRIMARY' => array( 'ID' ), 'type_status_date' => array( 'post_type', 'post_status', 'post_date', 'ID' ) ),
				'postmeta_table' => array( 'PRIMARY' => array( 'meta_id' ), 'post_id' => array( 'post_id' ), 'meta_key' => array( 'meta_key' ) ),
				'learndash_user_activity_table' => array(
					'PRIMARY' => array( 'activity_id' ), 'user_id' => array( 'user_id' ), 'post_id' => array( 'post_id' ),
					'course_id' => array( 'course_id' ), 'activity_status' => array( 'activity_status' ),
					'activity_type' => array( 'activity_type' ), 'activity_started' => array( 'activity_started' ),
					'activity_completed' => array( 'activity_completed' ), 'activity_updated' => array( 'activity_updated' ),
				),
				'learndash_user_activity_meta_table' => array(
					'PRIMARY' => array( 'activity_meta_id' ), 'activity_id' => array( 'activity_id' ),
					'activity_meta_key' => array( 'activity_meta_key' ),
				),
			);
			$rows = array();
			foreach ( $indexes as $role => $named ) {
				foreach ( $named as $name => $columns ) {
					foreach ( $columns as $position => $column ) {
						$rows[] = array(
							'TABLE_NAME' => $this->tables[ $role ],
							'INDEX_NAME' => $name,
							'NON_UNIQUE' => in_array( $name, array( 'PRIMARY', 'option_name' ), true ) ? '0' : '1',
							'SEQ_IN_INDEX' => (string) ( $position + 1 ),
							'COLUMN_NAME' => $column,
							'SUB_PART' => in_array( $name, array( 'meta_key', 'activity_meta_key' ), true ) ? '191' : null,
						);
					}
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'AS post_read_options' ) ) {
			return array( array(
				'post_read_options' => (string) count( $this->fixture['options'] ?? array() ),
				'post_read_user' => (string) count( $this->fixture['user'] ?? array() ),
				'post_read_usermeta' => (string) count( $this->fixture['usermeta'] ?? array() ),
				'post_read_groups' => (string) count( $this->fixture['groups'] ?? array() ),
				'post_read_courses' => (string) count( $this->fixture['courses'] ?? array() ),
				'post_read_postmeta' => (string) count( $this->fixture['postmeta'] ?? array() ),
				'post_read_certificates' => false !== strpos( $sql, '0 AS post_read_certificates' )
					? '0' : (string) count( $this->fixture['certificates'] ?? array() ),
				'post_read_activities' => (string) count( $this->fixture['activities'] ?? array() ),
				'post_read_activity_meta' => false !== strpos( $sql, '0 AS post_read_activity_meta' )
					? '0' : (string) count( $this->fixture['activity_meta'] ?? array() ),
			) );
		}
		$family = $this->family( $sql );
		$rows = $this->fixture[ $family ] ?? array();
		if ( preg_match( '/SELECT COUNT\\(\\*\\) AS row_count/i', $sql ) ) {
			return array( array( 'row_count' => (string) count( $rows ) ) );
		}
		return $rows;
	}

	/** @return int|false */
	public function query( string $sql ) {
		$this->queries[] = $sql;
		if ( 'ROLLBACK' === $sql && is_callable( $this->on_rollback ) ) {
			call_user_func( $this->on_rollback );
		}
		if ( null !== $this->throw_query_containing && false !== strpos( $sql, $this->throw_query_containing ) ) {
			throw new RuntimeException( 'driver SQL and password must never escape' );
		}
		if ( 'ROLLBACK' === $sql && $this->rollback_throws ) {
			throw new RuntimeException( 'redacted' );
		}
		if ( 'ROLLBACK' === $sql && $this->rollback_fails ) {
			$this->last_error = 'redacted';
			return false;
		}
		return 1;
	}

	public function close(): bool {
		if ( is_callable( $this->on_close ) ) {
			call_user_func( $this->on_close );
		}
		$this->closed = true;
		if ( $this->close_throws ) {
			throw new RuntimeException( 'redacted' );
		}
		return ! $this->close_fails;
	}

	private function family( string $sql ): string {
		if ( false !== strpos( $sql, '`wp_learndash_user_activity_meta`' ) ) { return 'activity_meta'; }
		if ( false !== strpos( $sql, '`wp_learndash_user_activity`' ) ) { return 'activities'; }
		if ( false !== strpos( $sql, '`wp_options`' ) ) { return 'options'; }
		if ( false !== strpos( $sql, '`wp_usermeta`' ) ) { return 'usermeta'; }
		if ( false !== strpos( $sql, '`wp_postmeta`' ) ) { return 'postmeta'; }
		if ( false !== strpos( $sql, '`wp_users`' ) ) { return 'user'; }
		if ( false !== strpos( $sql, "`wp_posts` WHERE post_type = 'groups'" ) ) { return 'groups'; }
		if ( false !== strpos( $sql, "`wp_posts` WHERE post_type = 'sfwd-courses'" ) ) { return 'courses'; }
		if ( false !== strpos( $sql, "`wp_posts` WHERE post_type = 'sfwd-certificates'" ) ) { return 'certificates'; }
		return 'unknown';
	}
}

/** @return array<string,string> */
function p3b2b_tables(): array {
	return array(
		'users_table' => 'wp_users',
		'usermeta_table' => 'wp_usermeta',
		'options_table' => 'wp_options',
		'posts_table' => 'wp_posts',
		'postmeta_table' => 'wp_postmeta',
		'learndash_user_activity_table' => 'wp_learndash_user_activity',
		'learndash_user_activity_meta_table' => 'wp_learndash_user_activity_meta',
	);
}

/** @return array<string,mixed> */
function p3b2b_descriptor(): array {
	return array(
		'base_prefix' => 'wp_',
		'blog_id' => 1,
		'blog_prefix' => 'wp_',
		'capabilities_meta_key' => 'wp_capabilities',
		'learndash_user_activity_meta_table' => 'wp_learndash_user_activity_meta',
		'learndash_user_activity_table' => 'wp_learndash_user_activity',
		'options_table' => 'wp_options',
		'postmeta_table' => 'wp_postmeta',
		'posts_table' => 'wp_posts',
		'site_id' => '1',
		'source_database' => 'ghca_acd_archive_test_unit_source',
		'tenant_id' => str_repeat( '1', 32 ),
		'user_roles_option_name' => 'wp_user_roles',
		'usermeta_table' => 'wp_usermeta',
		'users_table' => 'wp_users',
	);
}

/** @return array<string,string> */
function p3b2b_versions(): array {
	return array(
		'wordpress_version' => '7.0.2',
		'learndash_version' => '5.1.6.1',
		'plugin_version' => '1.2.0',
	);
}

/** @return array<string,array<int,array<string,mixed>>> */
function p3b2b_fixture(): array {
	$started = (string) gmmktime( 14, 0, 0, 6, 30, 2026 );
	$completed = (string) gmmktime( 15, 0, 0, 6, 30, 2026 );
	$quiz_completed = (string) gmmktime( 14, 45, 0, 6, 30, 2026 );
	$options = array(
		'blogname' => 'Academy Example',
		'ghca_dashboard_brand' => serialize( array( 'org_name' => 'Gridhouse Example' ) ),
		'ghca_acd_audit_mapping' => serialize( array(
			101 => array(
				'odp_category' => 'individual_rights',
				'oltl_category' => 'general',
				'credit_hours' => 1.0,
				'sort_order' => 0,
				'is_orientation' => 0,
			),
		) ),
		'ghca_acd_course_lifespans' => serialize( array( 101 => 365 ) ),
		'ghca_acd_warning_days' => '90',
		'ghca_acd_annual_cycle' => 'calendar_year',
		'ghca_new_hire_group_ids' => serialize( array( 9 ) ),
		'ghca_new_hire_deadline_days' => '30',
		'learndash_settings_groups_management_display' => serialize( array( 'group_hierarchical_enabled' => 'yes' ) ),
		'wp_user_roles' => serialize( array( 'subscriber' => array( 'name' => 'Subscriber' ) ) ),
	);
	$option_rows = array();
	$option_id = 1;
	foreach ( $options as $name => $value ) {
		$option_rows[] = array( 'option_id' => (string) $option_id++, 'option_name' => $name, 'option_value' => $value );
	}
	usort( $option_rows, static function ( array $left, array $right ): int {
		return strcmp( $left['option_name'], $right['option_name'] );
	} );
	return array(
		'options' => $option_rows,
		'user' => array( array(
			'ID' => '42',
			'user_email' => 'ada@example.test',
			'user_registered' => '2025-01-02 03:04:05',
			'display_name' => 'Fallback Name',
		) ),
		'usermeta' => array(
			array( 'umeta_id' => '1', 'user_id' => '42', 'meta_key' => 'course_101_access_from', 'meta_value' => $started ),
			array( 'umeta_id' => '2', 'user_id' => '42', 'meta_key' => 'course_completed_101', 'meta_value' => $completed ),
			array( 'umeta_id' => '3', 'user_id' => '42', 'meta_key' => 'first_name', 'meta_value' => 'Ada' ),
			array( 'umeta_id' => '4', 'user_id' => '42', 'meta_key' => 'last_name', 'meta_value' => 'Example' ),
			array( 'umeta_id' => '5', 'user_id' => '42', 'meta_key' => 'learndash_group_users_9', 'meta_value' => '9' ),
			array( 'umeta_id' => '6', 'user_id' => '42', 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'subscriber' => true, 'edit_posts' => true ) ) ),
		),
		'groups' => array(
			array( 'ID' => '9', 'post_title' => 'Team', 'post_status' => 'publish', 'post_type' => 'groups', 'post_modified_gmt' => '2026-01-01 00:00:00', 'post_parent' => '0' ),
		),
		'courses' => array(
			array( 'ID' => '101', 'post_title' => 'Safety & Rights', 'post_status' => 'publish', 'post_type' => 'sfwd-courses', 'post_modified_gmt' => '2026-06-29 12:00:00', 'post_parent' => '0' ),
		),
		'postmeta' => array(
			array( 'meta_id' => '11', 'post_id' => '101', 'meta_key' => '_ld_certificate', 'meta_value' => '0' ),
			array( 'meta_id' => '12', 'post_id' => '101', 'meta_key' => '_ld_price_type', 'meta_value' => 'open' ),
			array( 'meta_id' => '13', 'post_id' => '101', 'meta_key' => 'learndash_group_enrolled_9', 'meta_value' => '101' ),
		),
		'certificates' => array(),
		'activities' => array(
			array(
				'activity_id' => '7001', 'user_id' => '42', 'post_id' => '101', 'course_id' => '101',
				'activity_type' => 'course', 'activity_status' => '1', 'activity_started' => $started,
				'activity_completed' => $completed, 'activity_updated' => $completed,
			),
			array(
				'activity_id' => '8001', 'user_id' => '42', 'post_id' => '201', 'course_id' => '101',
				'activity_type' => 'quiz', 'activity_status' => '1', 'activity_started' => $started,
				'activity_completed' => $quiz_completed, 'activity_updated' => $quiz_completed,
			),
		),
		'activity_meta' => array(
			array( 'activity_meta_id' => '21', 'activity_id' => '8001', 'activity_meta_key' => 'pass', 'activity_meta_value' => '1' ),
			array( 'activity_meta_id' => '22', 'activity_id' => '8001', 'activity_meta_key' => 'percentage', 'activity_meta_value' => '88.125' ),
		),
	);
}

/** @return array<string,array<int,array<string,mixed>>> */
function p3b2b_certificate_fixture(): array {
	$fixture = p3b2b_fixture();
	$fixture['postmeta'][0]['meta_value'] = '301';
	$fixture['certificates'][] = array(
		'ID' => '301',
		'post_title' => 'Certificate Template',
		'post_status' => 'publish',
		'post_type' => 'sfwd-certificates',
		'post_modified_gmt' => '2026-06-28 12:00:00',
		'post_parent' => '0',
	);
	return $fixture;
}

/** @param array<string,array<int,array<string,mixed>>> $fixture @param mixed $value */
function p3b2b_set_option( array &$fixture, string $name, $value ): void {
	foreach ( $fixture['options'] as &$row ) {
		if ( $name === $row['option_name'] ) {
			$row['option_value'] = $value;
			return;
		}
	}
	throw new RuntimeException( 'Missing fixture option.' );
}

/** @return array<string,array<int,array<string,mixed>>> */
function p3b2b_multi_course_fixture(): array {
	$fixture = p3b2b_fixture();
	$started = $fixture['usermeta'][0]['meta_value'];
	$completed = $fixture['usermeta'][1]['meta_value'];
	p3b2b_set_option( $fixture, 'ghca_acd_audit_mapping', serialize( array(
		101 => array( 'odp_category' => 'individual_rights', 'oltl_category' => 'general', 'credit_hours' => 1.0, 'sort_order' => 0, 'is_orientation' => 0 ),
		2 => array( 'odp_category' => 'individual_rights', 'oltl_category' => 'general', 'credit_hours' => 0.5, 'sort_order' => 1, 'is_orientation' => 0 ),
	) ) );
	p3b2b_set_option( $fixture, 'ghca_acd_course_lifespans', serialize( array( 101 => 365, 2 => 365 ) ) );
	$fixture['courses'][] = array(
		'ID' => '2', 'post_title' => 'Second Course', 'post_status' => 'publish', 'post_type' => 'sfwd-courses',
		'post_modified_gmt' => '2026-06-29 11:00:00', 'post_parent' => '0',
	);
	$fixture['usermeta'][] = array( 'umeta_id' => '7', 'user_id' => '42', 'meta_key' => 'course_2_access_from', 'meta_value' => $started );
	$fixture['usermeta'][] = array( 'umeta_id' => '8', 'user_id' => '42', 'meta_key' => 'course_completed_2', 'meta_value' => $completed );
	$fixture['postmeta'][] = array( 'meta_id' => '14', 'post_id' => '2', 'meta_key' => '_ld_certificate', 'meta_value' => '0' );
	$fixture['postmeta'][] = array( 'meta_id' => '15', 'post_id' => '2', 'meta_key' => '_ld_price_type', 'meta_value' => 'open' );
	$fixture['activities'][] = array(
		'activity_id' => '7002', 'user_id' => '42', 'post_id' => '2', 'course_id' => '2',
		'activity_type' => 'course', 'activity_status' => '1', 'activity_started' => $started,
		'activity_completed' => $completed, 'activity_updated' => $completed,
	);
	return $fixture;
}

/** @return array<string,mixed> */
function p3b2b_identity(): array {
	$audit = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '101', array(
			'category_order' => 0,
			'course_order' => 0,
			'credit_minutes' => '60',
			'is_orientation' => false,
			'odp_category_key' => 'individual_rights',
			'oltl_category_key' => 'general',
		) ),
	) );
	$lifespans = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
	) );
	$policy = array(
		'audit_mapping' => $audit,
		'completeness_policy' => 'snapshot_v1_complete',
		'course_lifespan_rules' => $lifespans,
		'quiz_policy' => array( 'attempt_selection' => 'latest_completed', 'required' => false, 'score_scale' => 'basis_points' ),
		'relevant_settings' => array( 'annual_cycle' => 'calendar_year', 'new_hire_deadline_days' => '30', 'warning_days' => '90' ),
		'tracked_course_ids' => array( '101' ),
	);
	return array(
		'archive_id' => str_repeat( 'a', 32 ),
		'case_key' => array(
			'cycle_key' => '2026',
			'employee_user_id_decimal' => '42',
			'program_key' => 'annual_training',
			'site_id_decimal' => '1',
			'tenant_id' => str_repeat( '1', 32 ),
		),
		'policy_digest' => hash( 'sha256', "ghca-archive-policy-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode( $policy ) ),
		'resolved_cycle' => array(
			'boundary' => '[)',
			'display_label' => '2026',
			'end_gmt' => '2027-01-01T00:00:00Z',
			'key' => '2026',
			'policy_key' => 'calendar_year',
			'policy_version' => 1,
			'start_gmt' => '2026-01-01T00:00:00Z',
			'timezone' => 'UTC',
		),
		'reviewed_source_fingerprint' => str_repeat( '2', 64 ),
		'revision_number' => 1,
		'stream_id' => str_repeat( 'b', 32 ),
		'subject_scope_digest' => str_repeat( '3', 64 ),
		'trigger_event_id' => str_repeat( 'c', 32 ),
	);
}

/** @return array<string,mixed> */
function p3b2b_multi_course_identity(): array {
	$identity = p3b2b_identity();
	$audit = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array(
			'category_order' => 0, 'course_order' => 1, 'credit_minutes' => '30', 'is_orientation' => false,
			'odp_category_key' => 'individual_rights', 'oltl_category_key' => 'general',
		) ),
		array( '101', array(
			'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60', 'is_orientation' => false,
			'odp_category_key' => 'individual_rights', 'oltl_category_key' => 'general',
		) ),
	) );
	$lifespans = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '2', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
		array( '101', array( 'lifespan_days' => '365', 'warning_days' => '90' ) ),
	) );
	$policy = array(
		'audit_mapping' => $audit,
		'completeness_policy' => 'snapshot_v1_complete',
		'course_lifespan_rules' => $lifespans,
		'quiz_policy' => array( 'attempt_selection' => 'latest_completed', 'required' => false, 'score_scale' => 'basis_points' ),
		'relevant_settings' => array( 'annual_cycle' => 'calendar_year', 'new_hire_deadline_days' => '30', 'warning_days' => '90' ),
		'tracked_course_ids' => array( '2', '101' ),
	);
	$identity['policy_digest'] = hash( 'sha256', "ghca-archive-policy-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode( $policy ) );
	return $identity;
}

function p3b2b_session( GHCA_P3B2B_Scripted_Source_DB $db, ?callable $monotonic = null, string $current_user = 'ghca_source@%' ): GHCA_ACD_WPDB_Archive_Evidence_Read_Session {
	return new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
		$db,
		p3b2b_descriptor(),
		array( 'archive_connection_id' => 99, 'current_user' => $current_user ),
		array( 'wp_ghca_acd_archive_events' ),
		$monotonic
	);
}

/** @return array{0:array<string,mixed>,1:GHCA_P3B2B_Scripted_Source_DB} */
function p3b2b_run_fixture( array $fixture, ?array $identity = null ): array {
	$db = new GHCA_P3B2B_Scripted_Source_DB( $fixture, p3b2b_tables() );
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$document = $source->read_consistent_evidence(
		$identity ?? p3b2b_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
		static function (): void {}
	);
	return array( $document, $db );
}

/** @param callable():void $operation */
function p3b2b_failure( callable $operation, string $category, string $reason, string $context ): bool {
	try {
		$operation();
	} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
		return $category === $error->category() && $reason === $error->reason_code()
			&& $context === $error->operation_context()
			&& GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES[ $reason ] === $error->getMessage();
	}
	return false;
}

$versions = p3b2b_versions();
$constructor_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $constructor_db ), $versions );
archive_check(
	$source instanceof GHCA_ACD_Archive_Evidence_Source && array() === $constructor_db->queries,
	'P3B2B-SOURCE-VERSION-DESCRIPTOR-EXACT-THREE-FIELD-ACCEPTED accepts only the frozen version tuple without a query'
);

$bad_versions = array(
	array( 'wordpress_version' => '7.0.2', 'learndash_version' => '5.1.6.1' ),
	p3b2b_versions() + array( 'extra' => '1' ),
	array_merge( p3b2b_versions(), array( 'wordpress_version' => '7.0.2 ' ) ),
	array_merge( p3b2b_versions(), array( 'learndash_version' => '5.1.6.2' ) ),
);
$version_rejected = true;
foreach ( $bad_versions as $bad_version ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$version_rejected = $version_rejected && p3b2b_failure(
		static function () use ( $db, $bad_version ): void {
			new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), $bad_version );
		},
		'operational_blocked',
		'archive_source_schema_unsupported',
		'source_preflight'
	) && array() === $db->queries;
}
archive_check(
	$version_rejected,
	'P3B2B-SOURCE-VERSION-DESCRIPTOR-MISSING-EXTRA-MALFORMED-OR-SPOOFED-REJECTED-BEFORE-QUERY fails closed at construction'
);

$descriptor_rejected = true;
foreach ( array(
	array( 'posts_table', 'wp_posts;DROP' ),
	array( 'source_database', 'source.database' ),
) as $mutation ) {
	$descriptor = p3b2b_descriptor();
	$descriptor[ $mutation[0] ] = $mutation[1];
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$descriptor_rejected = $descriptor_rejected && p3b2b_failure(
		static function () use ( $db, $descriptor ): void {
			new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
				$db,
				$descriptor,
				array( 'archive_connection_id' => 99, 'current_user' => 'ghca_source@%' )
			);
		},
		'operational_blocked',
		'archive_source_schema_unsupported',
		'source_preflight'
	) && array() === $db->queries;
}
archive_check(
	$descriptor_rejected,
	'P3B2B-INVALID-IDENTIFIER-AND-CROSS-SCHEMA-RETAIN-SOURCE-PREFLIGHT blocks unsafe names before SQL'
);

$site_blog_descriptor = p3b2b_descriptor();
$site_blog_descriptor['site_id'] = '2';
$site_blog_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
archive_check(
	p3b2b_failure(
		static function () use ( $site_blog_db, $site_blog_descriptor ): void {
			new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
				$site_blog_db,
				$site_blog_descriptor,
				array( 'archive_connection_id' => 99, 'current_user' => 'ghca_source@%' )
			);
		},
		'invalid',
		'archive_build_binding_invalid',
		'authoritative_load'
	) && array() === $site_blog_db->queries,
	'P3B2B-SITE-BLOG-MISMATCH-IS-AUTHORITATIVE-BINDING-INVALID rejects contradictory tenant routing'
);

$mixed_prefixes_invalid = true;
foreach ( array(
	array( 'base_prefix', 'base_' ),
	array( 'blog_prefix', 'wp_2_' ),
	array( 'posts_table', 'other_posts' ),
) as $mutation ) {
	$descriptor = p3b2b_descriptor();
	$descriptor[ $mutation[0] ] = $mutation[1];
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$mixed_prefixes_invalid = $mixed_prefixes_invalid && p3b2b_failure(
		static function () use ( $db, $descriptor ): void {
			new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
				$db,
				$descriptor,
				array( 'archive_connection_id' => 99, 'current_user' => 'ghca_source@%' )
			);
		},
		'invalid',
		'archive_build_binding_invalid',
		'authoritative_load'
	) && array() === $db->queries;
}
archive_check(
	$mixed_prefixes_invalid,
	'P3B2B-MIXED-BASE-BLOG-AND-TABLE-PREFIXES-ARE-AUTHORITATIVE-BINDING-INVALID rejects contradictory physical bindings'
);

$unsupported_schema_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$unsupported_schema_db->table_engine = 'MyISAM';
$unsupported_schema_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $unsupported_schema_db ),
	p3b2b_versions()
);
archive_check(
	p3b2b_failure( static function () use ( $unsupported_schema_source ): void {
		$unsupported_schema_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'operational_blocked', 'archive_source_schema_unsupported', 'source_preflight' )
		&& $unsupported_schema_db->closed
		&& ! in_array( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', $unsupported_schema_db->queries, true ),
	'P3B2B-UNSUPPORTED-PHYSICAL-SCHEMA-RETAINS-SOURCE-PREFLIGHT rejects unsupported engines'
);

$reuse_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$reuse_session = new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
	$reuse_db,
	p3b2b_descriptor(),
	array( 'archive_connection_id' => 12, 'current_user' => 'ghca_source@%' )
);
$reuse_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( $reuse_session, p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $reuse_source ): void {
		$reuse_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'operational_blocked', 'archive_source_schema_unsupported', 'source_preflight' ) && $reuse_db->closed,
	'P3B2B-ARCHIVE-CONNECTION-REUSE-REJECTED fails closed before the evidence transaction'
);

$versions['plugin_version'] = 'spoofed-after-construction';
$reflection = new ReflectionProperty( $source, 'versions' );
archive_check(
	'1.2.0' === $reflection->getValue( $source )['plugin_version'],
	'P3B2B-SOURCE-VERSION-DESCRIPTOR-DETACHED-FROM-CALLER-MUTATION retains an immutable detached tuple'
);

$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
$checkpoint_count = 0;
$document = $source->read_consistent_evidence(
	p3b2b_identity(),
	array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
	static function () use ( &$checkpoint_count ): void { $checkpoint_count++; }
);
$bytes = GHCA_ACD_Archive_Canonical_JSON::encode( $document );
$fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $document );
$golden_bytes = <<<'JSON'
{"calculated":{"calculation_version":1,"categories":{"individual_rights":{"completed_course_ids":["101"],"credit_minutes":"60"}},"compliance_status":"compliant","exceptions":[],"matrix":{"odp":{"individual_rights":true},"oltl":{"general":true}},"total_course_count":1,"total_training_seconds":"0"},"canonical_format":"ghca-cjson-1","case":{"cycle_key":"2026","employee_user_id":"42","program_key":"annual_training","site_id":"1","tenant_id":"11111111111111111111111111111111"},"completeness":{"missing_fields":[],"observed_count":1,"policy_code":"snapshot_v1_complete","policy_version":1,"required_count":1,"result":"complete","warnings":[]},"courses":[{"category_order":0,"certificate_reference":null,"certificate_required":false,"completed_at_gmt":"2026-06-30T15:00:00Z","completion_status":"completed","course_id":"101","course_order":0,"course_stable_key":null,"course_title":"Safety & Rights","enrollment_status":"enrolled","pass_state":"not_applicable","quiz_attempts":[{"attempt_ordinal":0,"attempted_at_gmt":"2026-06-30T14:45:00Z","passed":true,"score_basis_points":8813}],"quiz_score_basis_points":8813,"source_provenance":{"adapter_key":"learndash-local","record_id":"7001","record_version":"e956c221c569b5b9e9d568233e8538f0e52027cce6a9ff514054287c40f18a55"},"started_at_gmt":"2026-06-30T14:00:00Z","time_spent_seconds":"0"}],"cycle":{"boundary":"[)","display_label":"2026","end_gmt":"2027-01-01T00:00:00Z","key":"2026","policy_key":"calendar_year","policy_version":1,"start_gmt":"2026-01-01T00:00:00Z","timezone":"UTC"},"organization":{"agency_name":"Gridhouse Example","site_name":"Academy Example","tenant_id":"11111111111111111111111111111111"},"policy":{"audit_mapping":{"101":{"category_order":0,"course_order":0,"credit_minutes":"60","is_orientation":false,"odp_category_key":"individual_rights","oltl_category_key":"general"}},"completeness_policy":"snapshot_v1_complete","course_lifespan_rules":{"101":{"lifespan_days":"365","warning_days":"90"}},"policy_digest":"32feb72d8f727c267777c33eb874df832d6ccdbc0ffe8f546dfd29be16eb5c52","quiz_policy":{"attempt_selection":"latest_completed","required":false,"score_scale":"basis_points"},"relevant_settings":{"annual_cycle":"calendar_year","new_hire_deadline_days":"30","warning_days":"90"},"tracked_course_ids":["101"]},"schema_version":1,"source":{"learndash_version":"5.1.6.1","plugin_version":"1.2.0","source_adapter_key":"learndash-local","source_adapter_version":"1.0.0","source_record_ids":{"course_activity_ids":["7001"],"course_post_ids":["101"],"group_ids":["9"],"quiz_activity_ids":["8001"],"user_id":"42"},"wordpress_version":"7.0.2"},"subject":{"display_name":"Ada Example","email":"ada@example.test","employee_user_id":"42","external_employee_key":null,"group_ids":["9"],"registered_at_gmt":"2025-01-02T03:04:05Z","role_keys":["subscriber"]}}
JSON;
archive_check(
	8813 === $document['courses'][0]['quiz_score_basis_points']
		&& true === $document['courses'][0]['quiz_attempts'][0]['passed']
		&& 0 === $document['courses'][0]['quiz_attempts'][0]['attempt_ordinal'],
	'P3B2B-QUIZ-ORDER-PASS-AND-DECIMAL-SCORE uses decimal half-up basis-point conversion'
);
archive_check(
	'completed' === $document['courses'][0]['completion_status']
		&& 'not_applicable' === $document['courses'][0]['pass_state']
		&& '0' === $document['courses'][0]['time_spent_seconds']
		&& 'compliant' === $document['calculated']['compliance_status'],
	'P3B2B-COMPLETE-E07-CONSTRUCTION-PASSES-ACCEPTED-VALIDATOR maps completion and time-independent calculation v1'
);
archive_check(
	array( 'subscriber' ) === $document['subject']['role_keys']
		&& array( '9' ) === $document['subject']['group_ids']
		&& 'Ada Example' === $document['subject']['display_name'],
	'P3B2B-USER-ROLE-GROUP-MAPPING intersects registered roles and preserves direct groups without login fallback'
);
archive_check(
	'7.0.2' === $document['source']['wordpress_version']
		&& '5.1.6.1' === $document['source']['learndash_version']
		&& '1.2.0' === $document['source']['plugin_version']
		&& 'Gridhouse Example' === $document['organization']['agency_name'],
	'P3B2B-ORGANIZATION-FALLBACK-AND-SOURCE-VERSION-INJECTION uses only closed source values'
);
archive_check(
	$db->closed && in_array( 'ROLLBACK', $db->queries, true ) && count( $db->queries ) <= 32 && $checkpoint_count > count( $db->queries ),
	'P3B2B-SUCCESS-ROLLS-BACK-CLOSES-AND-CHECKPOINTS keeps the read bounded and fenced'
);
$ordered_plan = true;
foreach ( array( 'wp_options', 'wp_usermeta', 'wp_posts', 'wp_postmeta', 'wp_learndash_user_activity' ) as $table ) {
	$count_index = null;
	$read_index = null;
	foreach ( $db->queries as $index => $sql ) {
		if ( false === strpos( $sql, '`' . $table . '`' ) ) { continue; }
		if ( false !== strpos( $sql, 'COUNT(*)' ) && null === $count_index ) { $count_index = $index; }
		if ( false !== strpos( $sql, ' ORDER BY ' ) && null === $read_index ) { $read_index = $index; }
	}
	$ordered_plan = $ordered_plan && null !== $count_index && null !== $read_index && $count_index < $read_index;
}
archive_check(
	$ordered_plan
		&& 0 === preg_match( '/\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE)\b/i', implode( "\n", $db->queries ) ),
	'P3B2B-QUERY-PLAN-COUNT-FIRST-ORDERED-AND-READ-ONLY proves the fixed bounded statement plan'
);
$post_read_queries = array_values( array_filter( $db->queries, static function ( string $sql ): bool {
	return false !== strpos( $sql, 'AS post_read_options' );
} ) );
archive_check(
	1 === count( $post_read_queries ) && false !== strpos( $post_read_queries[0], 'AS post_read_activity_meta' ),
	'P3B2B-COMBINED-POST-READ-COUNT-CHECK-RUNS-ONCE proves all selected families are re-counted inside the snapshot'
);
archive_check(
	1 === preg_match( '/^[a-f0-9]{64}$/D', $document['courses'][0]['source_provenance']['record_version'] )
		&& array( '7001' ) === $document['source']['source_record_ids']['course_activity_ids']
		&& array( '8001' ) === $document['source']['source_record_ids']['quiz_activity_ids'],
	'P3B2B-SOURCE-RECORD-ID-SETS-AND-VERSIONS-DETERMINISTIC freezes selected physical identities'
);

$multi_identity = p3b2b_multi_course_identity();
list( $multi_document ) = p3b2b_run_fixture( p3b2b_multi_course_fixture(), $multi_identity );
$multi_validated = ( new GHCA_ACD_Archive_Evidence_Result_Validator() )->validate( $multi_document, $multi_identity );
archive_check(
	array( '2', '101' ) === $multi_validated['policy']['tracked_course_ids']
		&& array( '101', '2' ) === array_column( $multi_validated['courses'], 'course_id' ),
	'P3B2B-NUMERIC-MEMBERSHIP-AND-DISPLAY-ORDER-INDEPENDENT retains numeric policy order and category/course display order'
);

$certificate_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_certificate_fixture(), p3b2b_tables() );
$certificate_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $certificate_db ), p3b2b_versions() );
$certificate_document = $certificate_source->read_consistent_evidence(
	p3b2b_identity(),
	array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
	static function (): void {}
);
$certificate_reference = $certificate_document['courses'][0]['certificate_reference'];
archive_check(
	true === $certificate_document['courses'][0]['certificate_required']
		&& array( 'certificate_post_id', 'source_record_version' ) === array_keys( $certificate_reference )
		&& '301' === $certificate_reference['certificate_post_id']
		&& 'bc05b608aadd4be66dbe017876d6334d260e097a30c42723097cd452fbd52958' === $certificate_reference['source_record_version'],
	'P3B2B-CERTIFICATE-REFERENCE-INDEPENDENT-GOLDEN retains exactly the approved two-key reference'
);

archive_check(
	2818 === strlen( $golden_bytes )
		&& $golden_bytes === $bytes
		&& 'db8c27a6fc1ea09eebcbdea1c129c099edc88d94e681ae6eb3cb82a3c5c7b1b8' === hash( 'sha256', $golden_bytes )
		&& '54f5333c6f9a03b5115135d8f1e46905a417e4f513edbac822e38214e315509c' === $fingerprint
		&& '32feb72d8f727c267777c33eb874df832d6ccdbc0ffe8f546dfd29be16eb5c52' === $document['policy']['policy_digest']
		&& 'e956c221c569b5b9e9d568233e8538f0e52027cce6a9ff514054287c40f18a55' === $document['courses'][0]['source_provenance']['record_version']
		&& in_array( PHP_VERSION, array( '8.3.30', '8.5.7' ), true ),
	'P3B2B-FULL-E07-E08-POLICY-AND-COURSE-RECORD-INDEPENDENT-GOLDENS match literal bytes and fixed digests'
);

list( $replayed_document ) = p3b2b_run_fixture( p3b2b_fixture() );
archive_check(
	$bytes === GHCA_ACD_Archive_Canonical_JSON::encode( $replayed_document )
		&& $fingerprint === GHCA_ACD_Archive_Digester::source_fingerprint( $replayed_document ),
	'P3B2B-REVIEW-CAPTURE-E07-AND-E08-BYTES-IDENTICAL reuses one concrete normalization and digest path'
);

$unused_fixture = p3b2b_fixture();
foreach ( $unused_fixture['options'] as &$unused_option ) {
	if ( 'blogname' === $unused_option['option_name'] ) { $unused_option['option_value'] = 'Other Academy'; }
}
unset( $unused_option );
list( $unused_document ) = p3b2b_run_fixture( $unused_fixture );
$used_fixture = p3b2b_fixture();
$used_fixture['courses'][0]['post_title'] = 'Changed Course Title';
list( $used_document ) = p3b2b_run_fixture( $used_fixture );
archive_check(
	$document['courses'][0]['source_provenance']['record_version'] === $unused_document['courses'][0]['source_provenance']['record_version']
		&& $document['courses'][0]['source_provenance']['record_version'] !== $used_document['courses'][0]['source_provenance']['record_version'],
	'P3B2B-UNUSED-SOURCE-VALUE-STABLE-USED-SOURCE-VALUE-ALTERS-RECORD-VERSION enforces the closed projection'
);

$prohibited_fixture = p3b2b_fixture();
foreach ( $prohibited_fixture['options'] as &$prohibited_option ) {
	if ( 'ghca_dashboard_brand' === $prohibited_option['option_name'] ) {
		$prohibited_option['option_value'] = serialize( array( 'org_name' => 'Gridhouse Example', 'unknown' => 'value' ) );
	}
}
unset( $prohibited_option );
$prohibited_db = new GHCA_P3B2B_Scripted_Source_DB( $prohibited_fixture, p3b2b_tables() );
$prohibited_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $prohibited_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $prohibited_source ): void {
		$prohibited_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_evidence_prohibited', 'source_validate' )
		&& $prohibited_db->closed && in_array( 'ROLLBACK', $prohibited_db->queries, true ),
	'P3B2B-UNKNOWN-SERIALIZED-KEY-REJECTED after rollback and close with no partial E07 result'
);

$duplicate_activity = p3b2b_fixture();
$duplicate_activity['activities'][] = $duplicate_activity['activities'][0];
$duplicate_db = new GHCA_P3B2B_Scripted_Source_DB( $duplicate_activity, p3b2b_tables() );
$duplicate_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $duplicate_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $duplicate_source ): void {
		$duplicate_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_snapshot_invalid', 'source_validate' ),
	'P3B2B-DUPLICATE-OR-CONTRADICTORY-COURSE-ACTIVITY-REJECTED uses the exact invalid tuple'
);

$cycle_fixture = p3b2b_fixture();
$cycle_end = (string) gmmktime( 0, 0, 0, 1, 1, 2027 );
$cycle_fixture['activities'][0]['activity_completed'] = $cycle_end;
$cycle_fixture['activities'][0]['activity_updated'] = $cycle_end;
$cycle_fixture['usermeta'][1]['meta_value'] = $cycle_end;
$cycle_db = new GHCA_P3B2B_Scripted_Source_DB( $cycle_fixture, p3b2b_tables() );
$cycle_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $cycle_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $cycle_source ): void {
		$cycle_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_snapshot_invalid', 'source_validate' ),
	'P3B2B-CYCLE-HALF-OPEN-END-AND-PRE-CYCLE-CARRY-FORWARD-REJECTED admits no clamped timestamp'
);

$group_cycle = p3b2b_fixture();
$group_cycle['groups'][0]['post_parent'] = '9';
$group_db = new GHCA_P3B2B_Scripted_Source_DB( $group_cycle, p3b2b_tables() );
$group_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $group_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $group_source ): void {
		$group_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_evidence_incomplete', 'normalize_limit' ),
	'P3B2B-GROUP-CYCLE-DEPTH-AND-COUNT-REJECTED keeps hierarchy expansion bounded'
);

$limit_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$limit_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $limit_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $limit_source ): void {
		$limit_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 5, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_evidence_incomplete', 'pre_query_limit' )
		&& $limit_db->closed && in_array( 'ROLLBACK', $limit_db->queries, true ),
	'P3B2B-COUNT-FIRST-AND-LIMIT-CEILING-PLUS-ONE rejects before an oversized family is selected'
);

$course_limit_fixture = p3b2b_fixture();
$course_limit_mapping = array();
for ( $course_id = 1; $course_id <= 10; $course_id++ ) {
	$course_limit_mapping[ $course_id ] = array(
		'odp_category' => 'general', 'oltl_category' => 'general', 'credit_hours' => 1,
		'sort_order' => $course_id, 'is_orientation' => 0,
	);
}
p3b2b_set_option( $course_limit_fixture, 'ghca_acd_audit_mapping', serialize( $course_limit_mapping ) );
$course_limit_db = new GHCA_P3B2B_Scripted_Source_DB( $course_limit_fixture, p3b2b_tables() );
$course_limit_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $course_limit_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $course_limit_source ): void {
		$course_limit_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 9, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_evidence_incomplete', 'pre_query_limit' )
		&& 0 === count( array_filter( $course_limit_db->queries, static function ( string $sql ): bool {
			return false !== strpos( $sql, '`wp_users`' );
		} ) ),
	'P3B2B-COURSE-ID-CEILING-BEFORE-SQL-PLACEHOLDERS rejects oversized tracked membership before the first course-dependent query'
);

$serialized_cases = array();
$recursive = array();
$recursive['self'] =& $recursive;
$serialized_cases['P3B2B-SERIALIZED-REFERENCE-AND-RECURSION-REJECTED'] = array( serialize( $recursive ), 'archive_evidence_prohibited', 'source_validate' );
$deep = 'leaf';
for ( $depth = 0; $depth < 33; $depth++ ) { $deep = array( $deep ); }
$serialized_cases['P3B2B-SERIALIZED-DEPTH-33-REJECTED'] = array( serialize( $deep ), 'archive_evidence_incomplete', 'normalize_limit' );
$serialized_cases['P3B2B-SERIALIZED-10001ST-VALUE-REJECTED'] = array( serialize( array_fill( 0, 10000, 'x' ) ), 'archive_evidence_incomplete', 'normalize_limit' );
$serialized_cases['P3B2B-SERIALIZED-OVERSIZED-STRING-REJECTED'] = array(
	serialize( array( 'org_name' => str_repeat( 'x', GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES + 1 ) ) ),
	'archive_evidence_incomplete',
	'normalize_limit',
);
foreach ( $serialized_cases as $name => $case ) {
	$fixture = p3b2b_fixture();
	p3b2b_set_option( $fixture, 'ghca_dashboard_brand', $case[0] );
	$db = new GHCA_P3B2B_Scripted_Source_DB( $fixture, p3b2b_tables() );
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	archive_check(
		p3b2b_failure( static function () use ( $source ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		}, 'invalid', $case[1], $case[2] )
			&& $db->closed && in_array( 'ROLLBACK', $db->queries, true )
			&& 0 === count( array_filter( $db->queries, static function ( string $sql ): bool {
				return false !== strpos( $sql, '`wp_users`' );
			} ) ),
		$name . ' applies canonical bounds before follow-up evidence queries'
	);
}

$duration_results = array();
foreach ( array( 1999, 2000 ) as $milliseconds ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$duration_results[] = is_array( $source->read_consistent_evidence(
		p3b2b_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => $milliseconds ),
		static function (): void {}
	) ) && $db->closed;
}
$duration_rejected = true;
foreach ( array( 2001, 0, -1, '2000', null ) as $milliseconds ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$duration_rejected = $duration_rejected && p3b2b_failure(
		static function () use ( $source, $milliseconds ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => $milliseconds ),
				static function (): void {}
			);
		},
		'invalid',
		'archive_build_binding_invalid',
		'authoritative_load'
	) && array() === $db->queries;
}
archive_check(
	! in_array( false, $duration_results, true ) && $duration_rejected,
	'P3B2B-TRANSACTION-BUDGET-1999-2000-ACCEPTED-2001-AND-MALFORMED-REJECTED freezes the two-second elapsed ceiling'
);

$grant_cases = array(
	'combined' => array(
		array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ),
		array( 'grant' => 'GRANT SELECT, INSERT ON `ghca_acd_archive_test_unit_source`.* TO `ghca_source`@`%`' ),
	),
	'other_database' => array(
		array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ),
		array( 'grant' => 'GRANT SELECT ON `other_database`.* TO `ghca_source`@`%`' ),
	),
	'table_level' => array(
		array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ),
		array( 'grant' => 'GRANT SELECT ON `ghca_acd_archive_test_unit_source`.`wp_posts` TO `ghca_source`@`%`' ),
	),
	'grant_option' => array(
		array( 'grant' => 'GRANT USAGE ON *.* TO `ghca_source`@`%`' ),
		array( 'grant' => 'GRANT SELECT ON `ghca_acd_archive_test_unit_source`.* TO `ghca_source`@`%` WITH GRANT OPTION' ),
	),
);
$grants_rejected = true;
foreach ( $grant_cases as $grants ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$db->grants = $grants;
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$grants_rejected = $grants_rejected && p3b2b_failure(
		static function () use ( $source ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		},
		'operational_blocked',
		'archive_source_schema_unsupported',
		'source_preflight'
	) && $db->closed && ! in_array( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', $db->queries, true );
}
archive_check(
	$grants_rejected,
	'P3B2B-EXACT-USAGE-AND-SEVEN-TABLE-SELECT-GRANTS-ONLY rejects combined, cross-database, incomplete-table, and grant-option privileges'
);

$escaped_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$escaped_db->authenticated_user = 'ghca`source@%';
$escaped_db->grants = array( array( 'grant' => 'GRANT USAGE ON *.* TO `ghca``source`@`%`' ) );
foreach ( p3b2b_tables() as $escaped_table ) {
	$escaped_db->grants[] = array(
		'grant' => 'GRANT SELECT ON `ghca_acd_archive_test_unit_source`.`' . $escaped_table . '` TO `ghca``source`@`%`',
	);
}
$escaped_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $escaped_db, null, 'ghca`source@%' ),
	p3b2b_versions()
);
$escaped_accepted = is_array( $escaped_source->read_consistent_evidence(
	p3b2b_identity(),
	array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
	static function (): void {}
) ) && $escaped_db->closed;
$representation_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$representation_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $representation_db, null, '`ghca_source`@`%`' ),
	p3b2b_versions()
);
$representation_rejected = p3b2b_failure(
	static function () use ( $representation_source ): void {
		$representation_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	},
	'operational_blocked',
	'archive_source_schema_unsupported',
	'source_preflight'
) && $representation_db->closed;
archive_check(
	$escaped_accepted && $representation_rejected,
	'P3B3-SOURCE-ACCOUNT-UNQUOTED-CURRENT-USER-DECODED-ESCAPED-GRANT-EQUIVALENCE-AND-REPRESENTATION-MISMATCH proves normalized account authority'
);

$preflight_failures = true;
foreach ( array( 'fail_results_containing', 'throw_results_containing' ) as $property ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$db->{$property} = 'CONNECTION_ID()';
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$preflight_failures = $preflight_failures && p3b2b_failure(
		static function () use ( $source ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		},
		'retryable',
		'archive_source_read_failed',
		'source_query'
	) && $db->closed;
}
archive_check(
	$preflight_failures,
	'P3B2B-PREFLIGHT-EXECUTION-FAILURE-RETRYABLE-WHILE-STRUCTURE-UNSUPPORTED preserves the closed failure taxonomy'
);

$transaction_start_results = array();
foreach ( array(
	'set' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
	'start' => 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
) as $phase => $statement ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$db->throw_query_containing = $statement;
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$caught = null;
	try {
		$source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	$transaction_start_results[ $phase ] = array( $caught, $db );
}
$set_error = $transaction_start_results['set'][0];
$set_db = $transaction_start_results['set'][1];
archive_check(
	$set_error instanceof GHCA_ACD_Archive_Evidence_Source_Exception
		&& 'retryable' === $set_error->category()
		&& 'archive_source_read_failed' === $set_error->reason_code()
		&& 'transaction_start' === $set_error->operation_context()
		&& $set_db->closed
		&& ! in_array( 'ROLLBACK', $set_db->queries, true ),
	'P3B2B-SET-TRANSACTION-THROWING-IS-SANITIZED-RETRYABLE closes the unstarted connection'
);
$start_error = $transaction_start_results['start'][0];
$start_db = $transaction_start_results['start'][1];
archive_check(
	$start_error instanceof GHCA_ACD_Archive_Evidence_Source_Exception
		&& 'retryable' === $start_error->category()
		&& 'archive_source_read_failed' === $start_error->reason_code()
		&& 'transaction_start' === $start_error->operation_context()
		&& $start_db->closed
		&& in_array( 'ROLLBACK', $start_db->queries, true ),
	'P3B2B-START-TRANSACTION-THROWING-IS-SANITIZED-RETRYABLE rolls back the possibly started transaction and closes'
);
archive_check(
	GHCA_ACD_Archive_Evidence_Source_Exception::MESSAGES['archive_source_read_failed'] === $set_error->getMessage()
		&& $set_error->getMessage() === $start_error->getMessage()
		&& false === stripos( $set_error->getMessage(), 'driver' )
		&& false === stripos( $set_error->getMessage(), 'sql' )
		&& false === stripos( $set_error->getMessage(), 'password' ),
	'P3B2B-TRANSACTION-START-EXCEPTION-MESSAGES-ARE-CANONICAL-AND-SANITIZED exposes no driver text'
);

$missing_certificate = p3b2b_certificate_fixture();
$missing_certificate['certificates'] = array();
$malformed_assignment = p3b2b_certificate_fixture();
$malformed_assignment['postmeta'][0]['meta_value'] = 'not-an-id';
$spoofed_certificate = p3b2b_certificate_fixture();
$spoofed_certificate['certificates'][0]['post_type'] = 'post';
$inconsistent_certificate = p3b2b_certificate_fixture();
$inconsistent_certificate['certificates'][0]['ID'] = '302';
$certificate_failures = array(
	'MISSING' => $missing_certificate,
	'MALFORMED' => $malformed_assignment,
	'SPOOFED' => $spoofed_certificate,
	'INCONSISTENT' => $inconsistent_certificate,
);
foreach ( $certificate_failures as $name => $fixture ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( $fixture, p3b2b_tables() );
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	archive_check(
		p3b2b_failure(
			static function () use ( $source ): void {
				$source->read_consistent_evidence(
					p3b2b_identity(),
					array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
					static function (): void {}
				);
			},
			'invalid',
			'archive_certificate_invalid',
			'certificate_gate'
		) && $db->closed && in_array( 'ROLLBACK', $db->queries, true ),
		'P3B2B-CERTIFICATE-' . $name . '-REJECTED uses the exact certificate gate tuple'
	);
}

$bad_identity = p3b2b_identity();
$bad_identity['policy_digest'] = str_repeat( 'f', 64 );
$binding_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$binding_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $binding_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $binding_source, $bad_identity ): void {
		$binding_source->read_consistent_evidence(
			$bad_identity,
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'invalid', 'archive_build_binding_invalid', 'authoritative_load' )
		&& $binding_db->closed && in_array( 'ROLLBACK', $binding_db->queries, true ),
	'P3B2B-POLICY-DIGEST-MUST-EQUAL-AUTHORITATIVE-IDENTITY rejects after safe source cleanup'
);

$cancel_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$cancel_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $cancel_db ), p3b2b_versions() );
$cancel_calls = 0;
$fence = new RuntimeException( 'fenced' );
$caught = null;
try {
	$cancel_source->read_consistent_evidence(
		p3b2b_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
		static function () use ( &$cancel_calls, $fence ): void {
			if ( ++$cancel_calls === 15 ) { throw $fence; }
		}
	);
} catch ( Throwable $error ) {
	$caught = $error;
}
archive_check(
	$caught === $fence && $cancel_db->closed && in_array( 'ROLLBACK', $cancel_db->queries, true ),
	'P3B2B-CHECKPOINT-CANCELLATION-ROLLS-BACK-CLOSES-AND-DISCARDS rethrows the fence only after cleanup'
);

$rollback_clock = 0.0;
$deadline_rollback_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$deadline_rollback_db->on_rollback = static function () use ( &$rollback_clock ): void {
	$rollback_clock = 2001.0;
};
$deadline_rollback_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $deadline_rollback_db, static function () use ( &$rollback_clock ): float {
		return $rollback_clock;
	} ),
	p3b2b_versions()
);
archive_check(
	p3b2b_failure( static function () use ( $deadline_rollback_source ): void {
		$deadline_rollback_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'retryable', 'archive_source_query_failed', 'source_query' )
		&& $deadline_rollback_db->closed
		&& in_array( 'ROLLBACK', $deadline_rollback_db->queries, true ),
	'P3B2B-DEADLINE-CROSSED-DURING-ROLLBACK-FAILS-AFTER-CLOSE enforces the full connection lifetime'
);

$close_clock = 0.0;
$deadline_close_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$deadline_close_db->on_close = static function () use ( &$close_clock ): void {
	$close_clock = 2001.0;
};
$deadline_close_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $deadline_close_db, static function () use ( &$close_clock ): float {
		return $close_clock;
	} ),
	p3b2b_versions()
);
archive_check(
	p3b2b_failure( static function () use ( $deadline_close_source ): void {
		$deadline_close_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'retryable', 'archive_source_query_failed', 'source_query' )
		&& $deadline_close_db->closed
		&& in_array( 'ROLLBACK', $deadline_close_db->queries, true ),
	'P3B2B-DEADLINE-CROSSED-DURING-CLOSE-FAILS-AFTER-CLOSE enforces the successful-close ceiling'
);

$cleanup_deadline_priority = true;
foreach ( array(
	array( 'rollback_fails', 'on_rollback', 'transaction_rollback' ),
	array( 'close_fails', 'on_close', 'connection_close' ),
) as $case ) {
	$clock = 0.0;
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$db->{$case[0]} = true;
	$db->{$case[1]} = static function () use ( &$clock ): void {
		$clock = 2001.0;
	};
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
		p3b2b_session( $db, static function () use ( &$clock ): float {
			return $clock;
		} ),
		p3b2b_versions()
	);
	$cleanup_deadline_priority = $cleanup_deadline_priority && p3b2b_failure(
		static function () use ( $source ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		},
		'operational_blocked',
		'archive_source_transaction_failed',
		$case[2]
	) && $db->closed;
}
archive_check(
	$cleanup_deadline_priority,
	'P3B2B-ROLLBACK-THEN-CLOSE-FAILURES-RETAIN-PRIORITY-OVER-DEADLINE preserves cleanup taxonomy'
);

$fence_clock = 0.0;
$deadline_fence_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$deadline_fence_db->on_rollback = static function () use ( &$fence_clock ): void {
	$fence_clock = 2001.0;
};
$deadline_fence_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source(
	p3b2b_session( $deadline_fence_db, static function () use ( &$fence_clock ): float {
		return $fence_clock;
	} ),
	p3b2b_versions()
);
$deadline_fence = new RuntimeException( 'exact fence' );
$deadline_fence_calls = 0;
$deadline_fence_caught = null;
try {
	$deadline_fence_source->read_consistent_evidence(
		p3b2b_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
		static function () use ( &$deadline_fence_calls, $deadline_fence ): void {
			if ( ++$deadline_fence_calls === 15 ) {
				throw $deadline_fence;
			}
		}
	);
} catch ( Throwable $error ) {
	$deadline_fence_caught = $error;
}
archive_check(
	$deadline_fence_caught === $deadline_fence
		&& $deadline_fence_db->closed
		&& in_array( 'ROLLBACK', $deadline_fence_db->queries, true ),
	'P3B2B-EXACT-FENCE-THROWABLE-REMAINS-UNCHANGED-AFTER-DEADLINE-CLEANUP preserves the pending fence'
);

$rollback_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$rollback_db->rollback_fails = true;
$rollback_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $rollback_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $rollback_source ): void {
		$rollback_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'operational_blocked', 'archive_source_transaction_failed', 'transaction_rollback' ) && $rollback_db->closed,
	'P3B2B-ROLLBACK-FAILURE-DISCARDS-RESULT returns the exact cleanup tuple'
);

$close_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$close_db->close_fails = true;
$close_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $close_db ), p3b2b_versions() );
archive_check(
	p3b2b_failure( static function () use ( $close_source ): void {
		$close_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	}, 'operational_blocked', 'archive_source_transaction_failed', 'connection_close' ),
	'P3B2B-CLOSE-FAILURE-DISCARDS-RESULT returns the exact cleanup tuple'
);

$cleanup_cases = array(
	array( 'rollback_throws', 'transaction_rollback' ),
	array( 'close_throws', 'connection_close' ),
);
$cleanup_exceptions = true;
foreach ( $cleanup_cases as $case ) {
	$db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
	$db->{$case[0]} = true;
	$source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $db ), p3b2b_versions() );
	$cleanup_exceptions = $cleanup_exceptions && p3b2b_failure(
		static function () use ( $source ): void {
			$source->read_consistent_evidence(
				p3b2b_identity(),
				array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
				static function (): void {}
			);
		},
		'operational_blocked',
		'archive_source_transaction_failed',
		$case[1]
	) && $db->closed;
}
$both_cleanup_db = new GHCA_P3B2B_Scripted_Source_DB( p3b2b_fixture(), p3b2b_tables() );
$both_cleanup_db->rollback_throws = true;
$both_cleanup_db->close_throws = true;
$both_cleanup_source = new GHCA_ACD_LearnDash_Archive_Evidence_Source( p3b2b_session( $both_cleanup_db ), p3b2b_versions() );
$cleanup_exceptions = $cleanup_exceptions && p3b2b_failure(
	static function () use ( $both_cleanup_source ): void {
		$both_cleanup_source->read_consistent_evidence(
			p3b2b_identity(),
			array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
			static function (): void {}
		);
	},
	'operational_blocked',
	'archive_source_transaction_failed',
	'transaction_rollback'
) && $both_cleanup_db->closed;
archive_check(
	$cleanup_exceptions,
	'P3B2B-ROLLBACK-AND-CLOSE-EXCEPTIONS-BOTH-ATTEMPTED prioritizes rollback while always attempting close'
);

archive_check(
	! function_exists( 'get_option' ) && ! isset( $GLOBALS['wpdb'] )
		&& ! class_exists( 'GHCA_ACD_Archive_Source_Version_Attestation' ),
	'P3B2B-P3B3-NONCONFIGURABLE-CODE-VERSION-ATTESTATION-GATE remains dark and unconfigured'
);

archive_finish();
