<?php

/** One bounded, read-only, consistent source read over an injected wpdb-compatible connection. */
final class GHCA_ACD_WPDB_Archive_Evidence_Read_Session {
	private const DESCRIPTOR_KEYS = array(
		'base_prefix', 'blog_id', 'blog_prefix', 'capabilities_meta_key',
		'learndash_user_activity_meta_table', 'learndash_user_activity_table',
		'options_table', 'postmeta_table', 'posts_table', 'site_id',
		'source_database', 'tenant_id', 'user_roles_option_name', 'usermeta_table', 'users_table',
	);

	private const TABLE_COLUMNS = array(
		'users_table' => array( 'ID', 'user_email', 'user_registered', 'display_name' ),
		'usermeta_table' => array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' ),
		'options_table' => array( 'option_id', 'option_name', 'option_value' ),
		'posts_table' => array( 'ID', 'post_title', 'post_status', 'post_type', 'post_modified_gmt', 'post_parent' ),
		'postmeta_table' => array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
		'learndash_user_activity_table' => array(
			'activity_id', 'user_id', 'post_id', 'course_id', 'activity_type',
			'activity_status', 'activity_started', 'activity_completed', 'activity_updated',
		),
		'learndash_user_activity_meta_table' => array(
			'activity_meta_id', 'activity_id', 'activity_meta_key', 'activity_meta_value',
		),
	);

	private const TABLE_INDEXES = array(
		'users_table' => array( 'PRIMARY' => array( 'ID' ) ),
		'usermeta_table' => array(
			'PRIMARY' => array( 'umeta_id' ), 'user_id' => array( 'user_id' ), 'meta_key' => array( 'meta_key' ),
		),
		'options_table' => array( 'PRIMARY' => array( 'option_id' ), 'option_name' => array( 'option_name' ) ),
		'posts_table' => array(
			'PRIMARY' => array( 'ID' ), 'type_status_date' => array( 'post_type', 'post_status', 'post_date', 'ID' ),
		),
		'postmeta_table' => array(
			'PRIMARY' => array( 'meta_id' ), 'post_id' => array( 'post_id' ), 'meta_key' => array( 'meta_key' ),
		),
		'learndash_user_activity_table' => array(
			'PRIMARY' => array( 'activity_id' ),
			'user_id' => array( 'user_id' ),
			'post_id' => array( 'post_id' ),
			'course_id' => array( 'course_id' ),
			'activity_status' => array( 'activity_status' ),
			'activity_type' => array( 'activity_type' ),
			'activity_started' => array( 'activity_started' ),
			'activity_completed' => array( 'activity_completed' ),
			'activity_updated' => array( 'activity_updated' ),
		),
		'learndash_user_activity_meta_table' => array(
			'PRIMARY' => array( 'activity_meta_id' ),
			'activity_id' => array( 'activity_id' ),
			'activity_meta_key' => array( 'activity_meta_key' ),
		),
	);

	private const OPTION_NAMES = array(
		'blogname',
		'ghca_dashboard_brand',
		'ghca_acd_audit_mapping',
		'ghca_acd_course_lifespans',
		'ghca_acd_warning_days',
		'ghca_acd_annual_cycle',
		'ghca_new_hire_group_ids',
		'ghca_new_hire_deadline_days',
		'learndash_settings_groups_management_display',
	);

	/** @var object */
	private $db;
	/** @var array<string,mixed> */
	private $descriptor;
	/** @var array<string,mixed> */
	private $expected_connection;
	/** @var array<int,string> */
	private $archive_tables;
	/** @var callable */
	private $monotonic;
	/** @var int */
	private $query_count = 0;
	/** @var int */
	private $maximum_queries = 0;
	/** @var float */
	private $deadline = 0.0;

	/**
	 * @param object $db wpdb-compatible isolated source connection.
	 * @param array<string,mixed> $descriptor
	 * @param array<string,mixed> $expected_connection
	 * @param array<int,string> $archive_tables
	 */
	public function __construct(
		$db,
		array $descriptor,
		array $expected_connection,
		array $archive_tables = array(),
		?callable $monotonic = null
	) {
		$this->assert_exact_keys( $descriptor, self::DESCRIPTOR_KEYS );
		$this->assert_exact_keys( $expected_connection, array( 'archive_connection_id', 'current_user' ) );
		if ( ! is_object( $db )
			|| ! is_int( $descriptor['blog_id'] ) || $descriptor['blog_id'] < 1
			|| ! is_string( $descriptor['site_id'] )
			|| ! is_string( $descriptor['tenant_id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $descriptor['tenant_id'] )
			|| ! is_string( $expected_connection['current_user'] ) || '' === $expected_connection['current_user']
			|| ! is_int( $expected_connection['archive_connection_id'] ) || $expected_connection['archive_connection_id'] < 1 ) {
			$this->unsupported();
		}
		if ( (string) $descriptor['blog_id'] !== $descriptor['site_id'] ) {
			$this->binding_invalid();
		}
		foreach ( array( 'source_database', 'users_table', 'usermeta_table', 'options_table', 'posts_table', 'postmeta_table', 'learndash_user_activity_table', 'learndash_user_activity_meta_table' ) as $key ) {
			$this->assert_identifier( $descriptor[ $key ], 64 );
		}
		foreach ( array( 'base_prefix', 'blog_prefix' ) as $key ) {
			$this->assert_identifier( $descriptor[ $key ], 32 );
		}
		foreach ( array( 'capabilities_meta_key', 'user_roles_option_name' ) as $key ) {
			$this->assert_identifier( $descriptor[ $key ], 64 );
		}
		$expected_blog_prefix = 1 === $descriptor['blog_id']
			? $descriptor['base_prefix']
			: $descriptor['base_prefix'] . $descriptor['blog_id'] . '_';
		if ( $expected_blog_prefix !== $descriptor['blog_prefix']
			|| $descriptor['capabilities_meta_key'] !== $descriptor['blog_prefix'] . 'capabilities'
			|| $descriptor['user_roles_option_name'] !== $descriptor['blog_prefix'] . 'user_roles'
			|| 0 !== strpos( $descriptor['users_table'], $descriptor['base_prefix'] )
			|| 0 !== strpos( $descriptor['usermeta_table'], $descriptor['base_prefix'] ) ) {
			$this->binding_invalid();
		}
		foreach ( array( 'options_table', 'posts_table', 'postmeta_table', 'learndash_user_activity_table', 'learndash_user_activity_meta_table' ) as $key ) {
			if ( 0 !== strpos( $descriptor[ $key ], $descriptor['blog_prefix'] ) ) {
				$this->binding_invalid();
			}
		}
		$physical = array();
		foreach ( self::TABLE_COLUMNS as $key => $_columns ) {
			$physical[] = $descriptor[ $key ];
		}
		if ( count( $physical ) !== count( array_unique( $physical ) ) ) {
			$this->unsupported();
		}
		foreach ( $archive_tables as $archive_table ) {
			$this->assert_identifier( $archive_table, 64 );
			if ( in_array( $archive_table, $physical, true ) ) {
				$this->unsupported();
			}
		}
		$this->db = $db;
		$this->descriptor = GHCA_ACD_Archive_Canonical_JSON::detach( $descriptor );
		$this->expected_connection = GHCA_ACD_Archive_Canonical_JSON::detach( $expected_connection );
		$this->archive_tables = array_values( $archive_tables );
		$this->monotonic = $monotonic ?: static function (): float {
			return hrtime( true ) / 1000000;
		};
	}

	/**
	 * @param array<string,mixed> $identity
	 * @param array<string,int> $limits
	 * @return array<string,mixed>
	 */
	public function read( array $identity, array $limits, callable $checkpoint ): array {
		if ( ! isset( $identity['case_key'] ) || ! is_array( $identity['case_key'] )
			|| ! isset( $identity['case_key']['tenant_id'], $identity['case_key']['site_id_decimal'], $identity['case_key']['employee_user_id_decimal'] )
			|| $identity['case_key']['tenant_id'] !== $this->descriptor['tenant_id']
			|| $identity['case_key']['site_id_decimal'] !== $this->descriptor['site_id']
			|| ! isset( $limits['maximum_queries'], $limits['maximum_rows'], $limits['maximum_transaction_milliseconds'] )
			|| ! is_int( $limits['maximum_queries'] ) || $limits['maximum_queries'] < 1 || $limits['maximum_queries'] > 32
			|| ! is_int( $limits['maximum_rows'] ) || $limits['maximum_rows'] < 1 || $limits['maximum_rows'] > 10000
			|| ! is_int( $limits['maximum_transaction_milliseconds'] ) || $limits['maximum_transaction_milliseconds'] < 1
			|| $limits['maximum_transaction_milliseconds'] > 2000 ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
				'archive_build_binding_invalid',
				'authoritative_load'
			);
		}

		$this->query_count = 0;
		$this->maximum_queries = $limits['maximum_queries'];
		$this->deadline = $this->now() + $limits['maximum_transaction_milliseconds'];
		$transaction = false;
		$pending = null;
		$raw = null;

		try {
			$checkpoint();
			$this->preflight( $checkpoint );
			$this->statement( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $checkpoint, 'transaction_start' );
			$this->statement( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', $checkpoint, 'transaction_start', $transaction );
			$raw = $this->read_rows( $identity, $limits['maximum_rows'], $checkpoint );
			$checkpoint();
			$this->elapsed();
		} catch ( Throwable $error ) {
			$pending = $error;
		}

		$rollback_failed = false;
		if ( $transaction ) {
			try {
				$this->query_count++;
				$result = $this->db->query( 'ROLLBACK' );
				$rollback_failed = false === $result || ! empty( $this->db->last_error );
			} catch ( Throwable $error ) {
				$rollback_failed = true;
			}
		}
		$close_failed = false;
		try {
			$close_failed = ! method_exists( $this->db, 'close' ) || false === $this->db->close();
		} catch ( Throwable $error ) {
			$close_failed = true;
		}
		if ( $rollback_failed ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
				'archive_source_transaction_failed',
				'transaction_rollback'
			);
		}
		if ( $close_failed ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
				'archive_source_transaction_failed',
				'connection_close'
			);
		}
		if ( null !== $pending ) {
			throw $pending;
		}
		$this->elapsed();
		if ( ! is_array( $raw ) ) {
			$this->query_failure();
		}
		return $raw;
	}

	private function preflight( callable $checkpoint ): void {
		$connection = $this->row(
			"SELECT CONNECTION_ID() AS connection_id, CURRENT_USER() AS authenticated_user, DATABASE() AS database_name, @@session.time_zone AS session_time_zone, @@character_set_connection AS connection_charset",
			$checkpoint,
			'source_preflight'
		);
		if ( ! isset( $connection['connection_id'], $connection['authenticated_user'], $connection['database_name'], $connection['session_time_zone'], $connection['connection_charset'] )
			|| (int) $connection['connection_id'] === $this->expected_connection['archive_connection_id']
			|| $connection['authenticated_user'] !== $this->expected_connection['current_user']
			|| $connection['database_name'] !== $this->descriptor['source_database']
			|| '+00:00' !== $connection['session_time_zone']
			|| 'utf8mb4' !== strtolower( (string) $connection['connection_charset'] ) ) {
			$this->unsupported();
		}
		$grant_rows = $this->rows( 'SHOW GRANTS FOR CURRENT_USER()', $checkpoint, 'source_preflight' );
		$account = null;
		$usage_grant = false;
		$select_grant = false;
		$account_pattern = '(`(?:``|[^`])*`@`(?:``|[^`])*`)';
		$usage_pattern = '/^GRANT USAGE ON \*\.\* TO ' . $account_pattern . "(?: IDENTIFIED BY PASSWORD '\\*[A-F0-9]{40}')?$/D";
		$select_pattern = '/^GRANT SELECT ON `' . preg_quote( $this->descriptor['source_database'], '/' ) . '`\.\* TO ' . $account_pattern . '$/D';
		foreach ( $grant_rows as $grant_row ) {
			if ( 1 !== count( $grant_row ) ) {
				$this->unsupported();
			}
			$grant = (string) reset( $grant_row );
			if ( preg_match( $usage_pattern, $grant, $match ) ) {
				if ( $usage_grant || null !== $account && $account !== $match[1] ) {
					$this->unsupported();
				}
				$usage_grant = true;
				$account = $match[1];
				continue;
			}
			if ( preg_match( $select_pattern, $grant, $match ) ) {
				if ( $select_grant || null !== $account && $account !== $match[1] ) {
					$this->unsupported();
				}
				$select_grant = true;
				$account = $match[1];
				continue;
			}
			$this->unsupported();
		}
		if ( 2 !== count( $grant_rows ) || ! $usage_grant || ! $select_grant ) {
			$this->unsupported();
		}

		$tables = $this->physical_tables();
		$table_rows = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.tables WHERE table_schema = %s AND TABLE_NAME IN (' . $this->placeholders( count( $tables ) ) . ') ORDER BY TABLE_NAME',
				array_merge( array( $this->descriptor['source_database'] ), $tables )
			),
			$checkpoint,
			'source_preflight'
		);
		if ( count( $table_rows ) !== count( $tables ) ) {
			$this->unsupported();
		}
		foreach ( $table_rows as $row ) {
			if ( ! isset( $row['TABLE_NAME'], $row['TABLE_TYPE'], $row['ENGINE'] )
				|| ! in_array( $row['TABLE_NAME'], $tables, true )
				|| 'BASE TABLE' !== strtoupper( (string) $row['TABLE_TYPE'] )
				|| 'INNODB' !== strtoupper( (string) $row['ENGINE'] ) ) {
				$this->unsupported();
			}
		}

		$column_rows = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE table_schema = %s AND TABLE_NAME IN (' . $this->placeholders( count( $tables ) ) . ') ORDER BY TABLE_NAME, ORDINAL_POSITION',
				array_merge( array( $this->descriptor['source_database'] ), $tables )
			),
			$checkpoint,
			'source_preflight'
		);
		$columns = array();
		foreach ( $column_rows as $row ) {
			if ( isset( $row['TABLE_NAME'], $row['COLUMN_NAME'] ) ) {
				$columns[ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
			}
		}
		foreach ( self::TABLE_COLUMNS as $key => $required ) {
			$actual = $columns[ $this->descriptor[ $key ] ] ?? array();
			if ( array() !== array_diff( $required, $actual ) ) {
				$this->unsupported();
			}
		}

		$index_rows = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.statistics WHERE table_schema = %s AND TABLE_NAME IN (' . $this->placeholders( count( $tables ) ) . ') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
				array_merge( array( $this->descriptor['source_database'] ), $tables )
			),
			$checkpoint,
			'source_preflight'
		);
		$indexes = array();
		foreach ( $index_rows as $row ) {
			if ( ! isset( $row['TABLE_NAME'], $row['INDEX_NAME'], $row['COLUMN_NAME'] ) ) {
				continue;
			}
			$indexes[ $row['TABLE_NAME'] ][ $row['INDEX_NAME'] ]['columns'][] = $row['COLUMN_NAME'];
			$indexes[ $row['TABLE_NAME'] ][ $row['INDEX_NAME'] ]['non_unique'] = (int) $row['NON_UNIQUE'];
			$indexes[ $row['TABLE_NAME'] ][ $row['INDEX_NAME'] ]['sub_part'][] = null === $row['SUB_PART'] ? null : (int) $row['SUB_PART'];
		}
		foreach ( self::TABLE_INDEXES as $key => $required_indexes ) {
			$table = $this->descriptor[ $key ];
			foreach ( $required_indexes as $name => $required_columns ) {
				$index = $indexes[ $table ][ $name ] ?? null;
				if ( ! is_array( $index ) || $index['columns'] !== $required_columns
					|| ( in_array( $name, array( 'PRIMARY', 'option_name' ), true ) && 0 !== $index['non_unique'] ) ) {
					$this->unsupported();
				}
				if ( in_array( $name, array( 'meta_key', 'activity_meta_key' ), true )
					&& null !== $index['sub_part'][0] && $index['sub_part'][0] < 191 ) {
					$this->unsupported();
				}
			}
		}
	}

	/** @return array<string,mixed> */
	private function read_rows( array $identity, int $maximum, callable $checkpoint ): array {
		$options_names = array_merge( self::OPTION_NAMES, array( $this->descriptor['user_roles_option_name'] ) );
		$options_table = $this->quote( $this->descriptor['options_table'] );
		$options_where = 'option_name IN (' . $this->placeholders( count( $options_names ) ) . ')';
		$options = $this->bounded(
			$this->prepare( "SELECT COUNT(*) AS row_count FROM {$options_table} WHERE {$options_where}", $options_names ),
			$this->prepare(
				"SELECT option_id, option_name, option_value FROM {$options_table} WHERE {$options_where} ORDER BY option_name ASC, option_id ASC LIMIT %d",
				array_merge( $options_names, array( $maximum + 1 ) )
			),
			$maximum,
			$checkpoint
		);
		$option_map = array();
		foreach ( $options as $row ) {
			if ( isset( $row['option_name'] ) ) {
				$option_map[ $row['option_name'] ] = $row['option_value'];
			}
		}
		foreach ( $option_map as $option_value ) {
			$this->decoded( $option_value );
		}
		$course_ids = $this->unsigned_keys( $this->decoded( $option_map['ghca_acd_audit_mapping'] ?? null ) );
		if ( array() === $course_ids || count( $course_ids ) > $maximum ) {
			$this->incomplete( array() === $course_ids ? 'normalize_limit' : 'pre_query_limit' );
		}

		$user_id = $identity['case_key']['employee_user_id_decimal'];
		$users = $this->quote( $this->descriptor['users_table'] );
		$user = $this->row(
			$this->prepare(
				"SELECT ID, user_email, user_registered, display_name FROM {$users} WHERE ID = %s LIMIT 2",
				array( $user_id )
			),
			$checkpoint,
			'source_query'
		);

		$meta_keys = array( 'first_name', 'last_name', $this->descriptor['capabilities_meta_key'] );
		foreach ( $course_ids as $course_id ) {
			$meta_keys[] = 'course_' . $course_id . '_access_from';
			$meta_keys[] = 'course_completed_' . $course_id;
		}
		$usermeta = $this->quote( $this->descriptor['usermeta_table'] );
		$meta_where = 'user_id = %s AND (meta_key IN (' . $this->placeholders( count( $meta_keys ) ) . ") OR meta_key LIKE 'learndash\\\\_group\\\\_users\\\\_%')";
		$meta_args = array_merge( array( $user_id ), $meta_keys );
		$user_meta = $this->bounded(
			$this->prepare( "SELECT COUNT(*) AS row_count FROM {$usermeta} WHERE {$meta_where}", $meta_args ),
			$this->prepare(
				"SELECT umeta_id, user_id, meta_key, meta_value FROM {$usermeta} WHERE {$meta_where} ORDER BY meta_key ASC, umeta_id ASC LIMIT %d",
				array_merge( $meta_args, array( $maximum + 1 ) )
			),
			$maximum,
			$checkpoint
		);
		$direct_groups = array();
		foreach ( $user_meta as $row ) {
			if ( isset( $row['meta_key'] ) && 0 === strpos( (string) $row['meta_key'], 'learndash_group_users_' ) ) {
				if ( ! preg_match( '/^learndash_group_users_([1-9][0-9]*)$/D', $row['meta_key'], $match )
					|| ! isset( $row['meta_value'] ) || (string) $row['meta_value'] !== $match[1] ) {
					$this->incomplete( 'normalize_limit' );
				}
				$direct_groups[] = $match[1];
			}
		}

		$posts = $this->quote( $this->descriptor['posts_table'] );
		$group_rows = $this->bounded(
			"SELECT COUNT(*) AS row_count FROM {$posts} WHERE post_type = 'groups' AND post_status = 'publish'",
			$this->prepare(
				"SELECT ID, post_title, post_status, post_type, post_modified_gmt, post_parent FROM {$posts} WHERE post_type = 'groups' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
				array( $maximum + 1 )
			),
			$maximum,
			$checkpoint
		);
		$hierarchical = $this->hierarchical_enabled( $this->decoded( $option_map['learndash_settings_groups_management_display'] ?? null ) );
		$effective_groups = $this->effective_groups( $direct_groups, $group_rows, $hierarchical, $maximum );
		$configured_groups = $this->unsigned_values( $this->decoded( $option_map['ghca_new_hire_group_ids'] ?? null ) );
		$configured_groups = $this->effective_groups( $configured_groups, $group_rows, $hierarchical, $maximum );
		$grant_groups = array_values( array_unique( array_merge( $effective_groups, $configured_groups ) ) );
		sort( $grant_groups, SORT_STRING );

		$course_placeholders = $this->placeholders( count( $course_ids ) );
		$course_rows = $this->bounded(
			$this->prepare(
				"SELECT COUNT(*) AS row_count FROM {$posts} WHERE post_type = 'sfwd-courses' AND post_status = 'publish' AND ID IN ({$course_placeholders})",
				$course_ids
			),
			$this->prepare(
				"SELECT ID, post_title, post_status, post_type, post_modified_gmt, post_parent FROM {$posts} WHERE post_type = 'sfwd-courses' AND post_status = 'publish' AND ID IN ({$course_placeholders}) ORDER BY ID ASC LIMIT %d",
				array_merge( $course_ids, array( $maximum + 1 ) )
			),
			$maximum,
			$checkpoint
		);

		$postmeta = $this->quote( $this->descriptor['postmeta_table'] );
		$postmeta_keys = array( '_ld_price_type', '_ld_certificate' );
		foreach ( $grant_groups as $group_id ) {
			$postmeta_keys[] = 'learndash_group_enrolled_' . $group_id;
		}
		$postmeta_where = 'post_id IN (' . $course_placeholders . ') AND meta_key IN (' . $this->placeholders( count( $postmeta_keys ) ) . ')';
		$postmeta_args = array_merge( $course_ids, $postmeta_keys );
		$post_meta = $this->bounded(
			$this->prepare( "SELECT COUNT(*) AS row_count FROM {$postmeta} WHERE {$postmeta_where}", $postmeta_args ),
			$this->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$postmeta} WHERE {$postmeta_where} ORDER BY post_id ASC, meta_key ASC, meta_id ASC LIMIT %d",
				array_merge( $postmeta_args, array( $maximum + 1 ) )
			),
			$maximum,
			$checkpoint
		);
		$certificate_ids = array();
		foreach ( $post_meta as $row ) {
			if ( isset( $row['meta_key'], $row['meta_value'] ) && '_ld_certificate' === $row['meta_key']
				&& preg_match( '/^[1-9][0-9]*$/', (string) $row['meta_value'] ) ) {
				$certificate_ids[] = (string) $row['meta_value'];
			}
		}
		$certificate_rows = array();
		$certificate_ids = array_values( array_unique( $certificate_ids ) );
		if ( array() !== $certificate_ids ) {
			$certificate_placeholders = $this->placeholders( count( $certificate_ids ) );
			$certificate_rows = $this->bounded(
				$this->prepare(
					"SELECT COUNT(*) AS row_count FROM {$posts} WHERE post_type = 'sfwd-certificates' AND post_status = 'publish' AND ID IN ({$certificate_placeholders})",
					$certificate_ids
				),
				$this->prepare(
					"SELECT ID, post_title, post_status, post_type, post_modified_gmt, post_parent FROM {$posts} WHERE post_type = 'sfwd-certificates' AND post_status = 'publish' AND ID IN ({$certificate_placeholders}) ORDER BY ID ASC LIMIT %d",
					array_merge( $certificate_ids, array( $maximum + 1 ) )
				),
				$maximum,
				$checkpoint
			);
		}

		$activities_table = $this->quote( $this->descriptor['learndash_user_activity_table'] );
		$activity_where = 'user_id = %s AND course_id IN (' . $course_placeholders . ") AND activity_type IN ('course','quiz')";
		$activity_args = array_merge( array( $user_id ), $course_ids );
		$activities = $this->bounded(
			$this->prepare( "SELECT COUNT(*) AS row_count FROM {$activities_table} WHERE {$activity_where}", $activity_args ),
			$this->prepare(
				"SELECT activity_id, user_id, post_id, course_id, activity_type, activity_status, activity_started, activity_completed, activity_updated FROM {$activities_table} WHERE {$activity_where} ORDER BY activity_type ASC, course_id ASC, activity_completed ASC, post_id ASC, activity_id ASC LIMIT %d",
				array_merge( $activity_args, array( $maximum + 1 ) )
			),
			$maximum,
			$checkpoint
		);
		$quiz_ids = array();
		foreach ( $activities as $activity ) {
			if ( isset( $activity['activity_type'], $activity['activity_id'] ) && 'quiz' === $activity['activity_type'] ) {
				$quiz_ids[] = (string) $activity['activity_id'];
			}
		}
		$activity_meta = array();
		if ( array() !== $quiz_ids ) {
			$activity_meta_table = $this->quote( $this->descriptor['learndash_user_activity_meta_table'] );
			$quiz_placeholders = $this->placeholders( count( $quiz_ids ) );
			$activity_meta_where = "activity_id IN ({$quiz_placeholders}) AND activity_meta_key IN ('pass','percentage')";
			$activity_meta = $this->bounded(
				$this->prepare( "SELECT COUNT(*) AS row_count FROM {$activity_meta_table} WHERE {$activity_meta_where}", $quiz_ids ),
				$this->prepare(
					"SELECT activity_meta_id, activity_id, activity_meta_key, activity_meta_value FROM {$activity_meta_table} WHERE {$activity_meta_where} ORDER BY activity_id ASC, activity_meta_key ASC, activity_meta_id ASC LIMIT %d",
					array_merge( $quiz_ids, array( $maximum + 1 ) )
				),
				$maximum,
				$checkpoint
			);
		}

		$post_read_selects = array(
			"(SELECT COUNT(*) FROM {$options_table} WHERE {$options_where}) AS post_read_options",
			"(SELECT COUNT(*) FROM {$users} WHERE ID = %s) AS post_read_user",
			"(SELECT COUNT(*) FROM {$usermeta} WHERE {$meta_where}) AS post_read_usermeta",
			"(SELECT COUNT(*) FROM {$posts} WHERE post_type = 'groups' AND post_status = 'publish') AS post_read_groups",
			"(SELECT COUNT(*) FROM {$posts} WHERE post_type = 'sfwd-courses' AND post_status = 'publish' AND ID IN ({$course_placeholders})) AS post_read_courses",
			"(SELECT COUNT(*) FROM {$postmeta} WHERE {$postmeta_where}) AS post_read_postmeta",
		);
		$post_read_args = array_merge( $options_names, array( $user_id ), $meta_args, $course_ids, $postmeta_args );
		if ( array() !== $certificate_ids ) {
			$post_read_selects[] = "(SELECT COUNT(*) FROM {$posts} WHERE post_type = 'sfwd-certificates' AND post_status = 'publish' AND ID IN ({$certificate_placeholders})) AS post_read_certificates";
			$post_read_args = array_merge( $post_read_args, $certificate_ids );
		} else {
			$post_read_selects[] = '0 AS post_read_certificates';
		}
		$post_read_selects[] = "(SELECT COUNT(*) FROM {$activities_table} WHERE {$activity_where}) AS post_read_activities";
		$post_read_args = array_merge( $post_read_args, $activity_args );
		if ( array() !== $quiz_ids ) {
			$post_read_selects[] = "(SELECT COUNT(*) FROM {$activity_meta_table} WHERE {$activity_meta_where}) AS post_read_activity_meta";
			$post_read_args = array_merge( $post_read_args, $quiz_ids );
		} else {
			$post_read_selects[] = '0 AS post_read_activity_meta';
		}
		$post_read = $this->row(
			$this->prepare( 'SELECT ' . implode( ', ', $post_read_selects ), $post_read_args ),
			$checkpoint,
			'source_query'
		);
		$post_read_expected = array(
			'post_read_options' => count( $options ),
			'post_read_user' => 1,
			'post_read_usermeta' => count( $user_meta ),
			'post_read_groups' => count( $group_rows ),
			'post_read_courses' => count( $course_rows ),
			'post_read_postmeta' => count( $post_meta ),
			'post_read_certificates' => count( $certificate_rows ),
			'post_read_activities' => count( $activities ),
			'post_read_activity_meta' => count( $activity_meta ),
		);
		foreach ( $post_read_expected as $key => $expected ) {
			if ( ! isset( $post_read[ $key ] ) || ! preg_match( '/^[0-9]+$/D', (string) $post_read[ $key ] )
				|| $expected !== (int) $post_read[ $key ] ) {
				$this->incomplete( 'normalize_limit' );
			}
		}

		return array(
			'options' => $options,
			'user' => $user,
			'usermeta' => $user_meta,
			'groups' => $group_rows,
			'effective_group_ids' => $effective_groups,
			'configured_group_ids' => $configured_groups,
			'courses' => $course_rows,
			'postmeta' => $post_meta,
			'certificates' => $certificate_rows,
			'activities' => $activities,
			'activity_meta' => $activity_meta,
			'query_count' => $this->query_count + 1,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function bounded( string $count_sql, string $rows_sql, int $maximum, callable $checkpoint ): array {
		$count = $this->row( $count_sql, $checkpoint, 'source_query' );
		if ( ! isset( $count['row_count'] ) || ! preg_match( '/^[0-9]+$/', (string) $count['row_count'] )
			|| (int) $count['row_count'] > $maximum ) {
			$this->incomplete( 'pre_query_limit' );
		}
		$rows = $this->rows( $rows_sql, $checkpoint, 'source_query' );
		if ( count( $rows ) !== (int) $count['row_count'] || count( $rows ) > $maximum ) {
			$this->incomplete( 'normalize_limit' );
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	private function row( string $sql, callable $checkpoint, string $context ): array {
		$rows = $this->rows( $sql, $checkpoint, $context );
		if ( 1 !== count( $rows ) ) {
			if ( 'source_preflight' === $context ) {
				$this->unsupported();
			}
			$this->incomplete( 'normalize_limit' );
		}
		return $rows[0];
	}

	/** @return array<int,array<string,mixed>> */
	private function rows( string $sql, callable $checkpoint, string $context ): array {
		$this->before( $checkpoint );
		$output = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		try {
			$rows = $this->db->get_results( $sql, $output );
		} catch ( Throwable $error ) {
			$this->read_failure();
		}
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) {
			if ( 'source_preflight' === $context ) {
				$this->read_failure();
			}
			$this->query_failure();
		}
		$this->after( $checkpoint );
		return $rows;
	}

	private function statement( string $sql, callable $checkpoint, string $context, ?bool &$started = null ): void {
		$this->before( $checkpoint );
		if ( null !== $started ) {
			$started = true;
		}
		try {
			$result = $this->db->query( $sql );
		} catch ( Throwable $error ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
				'archive_source_read_failed',
				$context
			);
		}
		if ( false === $result || ! empty( $this->db->last_error ) ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
				'archive_source_read_failed',
				$context
			);
		}
		$this->after( $checkpoint );
	}

	private function before( callable $checkpoint ): void {
		$checkpoint();
		$this->elapsed();
		$this->query_count++;
		if ( $this->query_count > $this->maximum_queries ) {
			$this->incomplete( 'pre_query_limit' );
		}
	}

	private function after( callable $checkpoint ): void {
		$this->elapsed();
		$checkpoint();
	}

	private function elapsed(): void {
		if ( $this->now() > $this->deadline ) {
			$this->query_failure();
		}
	}

	private function now(): float {
		return (float) call_user_func( $this->monotonic );
	}

	/** @param array<int,mixed> $args */
	private function prepare( string $sql, array $args ): string {
		$prepared = call_user_func_array( array( $this->db, 'prepare' ), array_merge( array( $sql ), $args ) );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			$this->query_failure();
		}
		return $prepared;
	}

	private function placeholders( int $count ): string {
		if ( $count < 1 ) {
			$this->incomplete( 'normalize_limit' );
		}
		return implode( ',', array_fill( 0, $count, '%s' ) );
	}

	private function quote( string $identifier ): string {
		return '`' . $identifier . '`';
	}

	/** @return array<int,string> */
	private function physical_tables(): array {
		$tables = array();
		foreach ( self::TABLE_COLUMNS as $key => $_columns ) {
			$tables[] = $this->descriptor[ $key ];
		}
		sort( $tables, SORT_STRING );
		return $tables;
	}

	/** @param mixed $value @return mixed */
	private function decoded( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( ! preg_match( '/^(?:a|b|d|i|s|N|O|C|R|r):/', $value ) ) {
			return $value;
		}
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
		if ( false === $decoded && 'b:0;' !== $value ) {
			$this->incomplete( 'normalize_limit' );
		}
		$values = 0;
		$this->scalar_tree( $decoded, 0, $values );
		return $decoded;
	}

	/** @param mixed $value */
	private function scalar_tree( $value, int $depth, int &$values ): void {
		if ( $depth > GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH || ++$values > GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES ) {
			$this->incomplete( 'normalize_limit' );
		}
		if ( is_string( $value ) && strlen( $value ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
			$this->incomplete( 'normalize_limit' );
		}
		if ( is_object( $value ) || is_resource( $value ) ) {
			$this->prohibited();
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( ReflectionReference::fromArrayElement( $value, $key ) instanceof ReflectionReference ) {
				$this->prohibited();
			}
			if ( is_string( $key ) && strlen( $key ) > GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES ) {
				$this->incomplete( 'normalize_limit' );
			}
			$this->scalar_tree( $item, $depth + 1, $values );
		}
	}

	/** @param mixed $value @return array<int,string> */
	private function unsigned_keys( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( array_keys( $value ) as $key ) {
			$key = (string) $key;
			if ( preg_match( '/^[1-9][0-9]*$/', $key ) ) {
				$ids[] = $key;
			}
		}
		usort( $ids, array( $this, 'compare_decimal' ) );
		return array_values( array_unique( $ids ) );
	}

	/** @param mixed $value @return array<int,string> */
	private function unsigned_values( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $item ) {
			$item = (string) $item;
			if ( preg_match( '/^[1-9][0-9]*$/', $item ) ) {
				$ids[] = $item;
			}
		}
		usort( $ids, array( $this, 'compare_decimal' ) );
		return array_values( array_unique( $ids ) );
	}

	/** @param mixed $value */
	private function hierarchical_enabled( $value ): bool {
		return is_array( $value ) && isset( $value['group_hierarchical_enabled'] )
			&& 'yes' === $value['group_hierarchical_enabled'];
	}

	/**
	 * @param array<int,string> $direct
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<int,string>
	 */
	private function effective_groups( array $direct, array $groups, bool $hierarchical, int $maximum ): array {
		$children = array();
		$known = array();
		$parents = array();
		foreach ( $groups as $group ) {
			if ( ! isset( $group['ID'], $group['post_parent'] ) ) {
				$this->incomplete( 'normalize_limit' );
			}
			$id = (string) $group['ID'];
			$parent = (string) $group['post_parent'];
			$known[ $id ] = true;
			$parents[ $id ] = $parent;
			$children[ $parent ][] = $id;
		}
		foreach ( $direct as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				$this->incomplete( 'normalize_limit' );
			}
		}
		foreach ( $groups as $group ) {
			$parent = (string) $group['post_parent'];
			if ( '0' !== $parent && ! isset( $known[ $parent ] ) ) {
				$this->incomplete( 'normalize_limit' );
			}
			$path = array();
			$current = (string) $group['ID'];
			for ( $depth = 0; '0' !== $current; $depth++ ) {
				if ( $depth > 64 || isset( $path[ $current ] ) || ! isset( $known[ $current ] ) ) {
					$this->incomplete( 'normalize_limit' );
				}
				$path[ $current ] = true;
				$current = $parents[ $current ];
			}
		}
		$seen = array();
		$queue = array();
		foreach ( $direct as $id ) {
			$queue[] = array( $id, 0 );
		}
		while ( array() !== $queue ) {
			$current = array_shift( $queue );
			$id = $current[0];
			$depth = $current[1];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( $depth > 64 || count( $seen ) >= $maximum ) {
				$this->incomplete( 'normalize_limit' );
			}
			$seen[ $id ] = true;
			if ( $hierarchical ) {
				foreach ( $children[ $id ] ?? array() as $child ) {
					$queue[] = array( $child, $depth + 1 );
				}
			}
		}
		$ids = array_keys( $seen );
		usort( $ids, array( $this, 'compare_decimal' ) );
		return $ids;
	}

	private function compare_decimal( string $left, string $right ): int {
		$length = strlen( $left ) <=> strlen( $right );
		return 0 !== $length ? $length : strcmp( $left, $right );
	}

	/** @param array<string,mixed> $value @param array<int,string> $expected */
	private function assert_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->unsupported();
		}
	}

	/** @param mixed $value */
	private function assert_identifier( $value, int $maximum ): void {
		if ( ! is_string( $value ) || strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $value ) ) {
			$this->unsupported();
		}
	}

	private function unsupported(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_OPERATIONAL_BLOCKED,
			'archive_source_schema_unsupported',
			'source_preflight'
		);
	}

	private function binding_invalid(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_build_binding_invalid',
			'authoritative_load'
		);
	}

	private function incomplete( string $context ): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_evidence_incomplete',
			$context
		);
	}

	private function prohibited(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
			'archive_evidence_prohibited',
			'source_validate'
		);
	}

	private function read_failure(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
			'archive_source_read_failed',
			'source_query'
		);
	}

	private function query_failure(): void {
		throw new GHCA_ACD_Archive_Evidence_Source_Exception(
			GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_RETRYABLE,
			'archive_source_query_failed',
			'source_query'
		);
	}
}
