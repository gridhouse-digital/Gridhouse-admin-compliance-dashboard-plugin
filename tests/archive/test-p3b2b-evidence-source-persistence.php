<?php
require_once __DIR__ . '/persistence-bootstrap.php';

/** @return array<string,string> */
function p3b2bp_environment(): array {
	$values = array();
	foreach ( array(
		'GHCA_TEST_SOURCE_DB_NAME',
		'GHCA_TEST_SOURCE_DB_USER',
		'GHCA_TEST_SOURCE_DB_PASSWORD',
	) as $name ) {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			throw new RuntimeException( 'Missing required disposable source-test environment.' );
		}
		$values[ $name ] = $value;
	}
	$archive = getenv( 'GHCA_TEST_DB_NAME' );
	if ( 1 !== preg_match( '/^ghca_acd_archive_test_[A-Za-z0-9_]+_source$/D', $values['GHCA_TEST_SOURCE_DB_NAME'] )
		|| $archive === $values['GHCA_TEST_SOURCE_DB_NAME']
		|| 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $values['GHCA_TEST_SOURCE_DB_USER'] )
		|| 32 !== strlen( $values['GHCA_TEST_SOURCE_DB_PASSWORD'] )
		|| 'true' !== strtolower( (string) getenv( 'GHCA_TEST_DESTRUCTIVE_OPT_IN' ) ) ) {
		throw new RuntimeException( 'Disposable source-test safety contract is invalid.' );
	}
	return $values;
}

function p3b2bp_identifier( string $value ): string {
	if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value ) ) {
		throw new RuntimeException( 'Unsafe disposable identifier.' );
	}
	return '`' . $value . '`';
}

/** @param wpdb $db */
function p3b2bp_query( $db, string $sql ): void {
	$db->last_error = '';
	$result = $db->query( $sql );
	if ( false === $result || '' !== $db->last_error ) {
		throw new RuntimeException( 'Disposable source setup failed.' );
	}
}

/** @param wpdb $admin @return array<string,mixed> */
function p3b2bp_setup( $admin ): array {
	$environment = p3b2bp_environment();
	$database = $environment['GHCA_TEST_SOURCE_DB_NAME'];
	$user = $environment['GHCA_TEST_SOURCE_DB_USER'];
	$password = $environment['GHCA_TEST_SOURCE_DB_PASSWORD'];
	$schema = p3b2bp_identifier( $database );
	$user_host = $admin->prepare( '%s@%s', $user, '%' );

	p3b2bp_query( $admin, "DROP DATABASE IF EXISTS {$schema}" );
	p3b2bp_query( $admin, "DROP USER IF EXISTS {$user_host}" );
	p3b2bp_query( $admin, "CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
	p3b2bp_query( $admin, $admin->prepare( "CREATE USER {$user_host} IDENTIFIED BY %s", $password ) );
	p3b2bp_query( $admin, "GRANT SELECT ON {$schema}.* TO {$user_host}" );

	$tables = array(
		'users_table' => 'wp_users',
		'usermeta_table' => 'wp_usermeta',
		'options_table' => 'wp_options',
		'posts_table' => 'wp_posts',
		'postmeta_table' => 'wp_postmeta',
		'learndash_user_activity_table' => 'wp_learndash_user_activity',
		'learndash_user_activity_meta_table' => 'wp_learndash_user_activity_meta',
	);
	$qualified = static function ( string $table ) use ( $schema ): string {
		return $schema . '.' . p3b2bp_identifier( $table );
	};
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['users_table'] ) . ' (
		ID bigint unsigned NOT NULL,
		user_email varchar(100) NOT NULL,
		user_registered datetime NOT NULL,
		display_name varchar(250) NOT NULL,
		PRIMARY KEY (ID)
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['usermeta_table'] ) . ' (
		umeta_id bigint unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint unsigned NOT NULL,
		meta_key varchar(255) DEFAULT NULL,
		meta_value longtext,
		PRIMARY KEY (umeta_id),
		KEY user_id (user_id),
		KEY meta_key (meta_key(191))
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['options_table'] ) . ' (
		option_id bigint unsigned NOT NULL AUTO_INCREMENT,
		option_name varchar(191) NOT NULL,
		option_value longtext NOT NULL,
		PRIMARY KEY (option_id),
		UNIQUE KEY option_name (option_name)
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['posts_table'] ) . ' (
		ID bigint unsigned NOT NULL,
		post_title text NOT NULL,
		post_status varchar(20) NOT NULL,
		post_type varchar(20) NOT NULL,
		post_date datetime NOT NULL,
		post_modified_gmt datetime NOT NULL,
		post_parent bigint unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY (ID),
		KEY type_status_date (post_type,post_status,post_date,ID)
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['postmeta_table'] ) . ' (
		meta_id bigint unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint unsigned NOT NULL,
		meta_key varchar(255) DEFAULT NULL,
		meta_value longtext,
		PRIMARY KEY (meta_id),
		KEY post_id (post_id),
		KEY meta_key (meta_key(191))
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['learndash_user_activity_table'] ) . ' (
		activity_id bigint unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint unsigned NOT NULL,
		post_id bigint unsigned NOT NULL,
		course_id bigint unsigned NOT NULL,
		activity_type varchar(50) NOT NULL,
		activity_status tinyint unsigned NOT NULL,
		activity_started int unsigned NOT NULL,
		activity_completed int unsigned NOT NULL,
		activity_updated int unsigned NOT NULL,
		PRIMARY KEY (activity_id),
		KEY user_id (user_id),
		KEY post_id (post_id),
		KEY course_id (course_id),
		KEY activity_status (activity_status),
		KEY activity_type (activity_type),
		KEY activity_started (activity_started),
		KEY activity_completed (activity_completed),
		KEY activity_updated (activity_updated)
	) ENGINE=InnoDB' );
	p3b2bp_query( $admin, 'CREATE TABLE ' . $qualified( $tables['learndash_user_activity_meta_table'] ) . ' (
		activity_meta_id bigint unsigned NOT NULL AUTO_INCREMENT,
		activity_id bigint unsigned NOT NULL,
		activity_meta_key varchar(255) NOT NULL,
		activity_meta_value longtext,
		PRIMARY KEY (activity_meta_id),
		KEY activity_id (activity_id),
		KEY activity_meta_key (activity_meta_key(191))
	) ENGINE=InnoDB' );

	$started = (string) gmmktime( 14, 0, 0, 6, 30, 2026 );
	$completed = (string) gmmktime( 15, 0, 0, 6, 30, 2026 );
	$quiz_completed = (string) gmmktime( 14, 45, 0, 6, 30, 2026 );
	p3b2bp_query( $admin, $admin->prepare(
		'INSERT INTO ' . $qualified( $tables['users_table'] ) . ' (ID,user_email,user_registered,display_name) VALUES (%d,%s,%s,%s)',
		42, 'ada@example.test', '2025-01-02 03:04:05', 'Fallback Name'
	) );
	$options = array(
		'blogname' => 'Academy Example',
		'ghca_dashboard_brand' => serialize( array( 'org_name' => 'Gridhouse Example' ) ),
		'ghca_acd_audit_mapping' => serialize( array( 101 => array(
			'odp_category' => 'individual_rights', 'oltl_category' => 'general',
			'credit_hours' => 1.0, 'sort_order' => 0, 'is_orientation' => 0,
		) ) ),
		'ghca_acd_course_lifespans' => serialize( array( 101 => 365 ) ),
		'ghca_acd_warning_days' => '90',
		'ghca_acd_annual_cycle' => 'calendar_year',
		'ghca_new_hire_group_ids' => serialize( array( 9 ) ),
		'ghca_new_hire_deadline_days' => '30',
		'learndash_settings_groups_management_display' => serialize( array( 'group_hierarchical_enabled' => 'yes' ) ),
		'wp_user_roles' => serialize( array( 'subscriber' => array( 'name' => 'Subscriber' ) ) ),
	);
	foreach ( $options as $name => $value ) {
		p3b2bp_query( $admin, $admin->prepare(
			'INSERT INTO ' . $qualified( $tables['options_table'] ) . ' (option_name,option_value) VALUES (%s,%s)',
			$name, $value
		) );
	}
	$meta = array(
		array( 'course_101_access_from', $started ),
		array( 'course_completed_101', $completed ),
		array( 'first_name', 'Ada' ),
		array( 'last_name', 'Example' ),
		array( 'learndash_group_users_9', '9' ),
		array( 'wp_capabilities', serialize( array( 'subscriber' => true, 'edit_posts' => true ) ) ),
	);
	foreach ( $meta as $value ) {
		p3b2bp_query( $admin, $admin->prepare(
			'INSERT INTO ' . $qualified( $tables['usermeta_table'] ) . ' (user_id,meta_key,meta_value) VALUES (42,%s,%s)',
			$value[0], $value[1]
		) );
	}
	foreach ( array(
		array( 9, 'Team', 'groups', '2026-01-01 00:00:00' ),
		array( 101, 'Safety & Rights', 'sfwd-courses', '2026-06-29 12:00:00' ),
	) as $post ) {
		p3b2bp_query( $admin, $admin->prepare(
			'INSERT INTO ' . $qualified( $tables['posts_table'] ) . ' (ID,post_title,post_status,post_type,post_date,post_modified_gmt,post_parent) VALUES (%d,%s,%s,%s,%s,%s,0)',
			$post[0], $post[1], 'publish', $post[2], $post[3], $post[3]
		) );
	}
	foreach ( array(
		array( 11, '_ld_certificate', '0' ),
		array( 12, '_ld_price_type', 'open' ),
		array( 13, 'learndash_group_enrolled_9', '101' ),
	) as $post_meta ) {
		p3b2bp_query( $admin, $admin->prepare(
			'INSERT INTO ' . $qualified( $tables['postmeta_table'] ) . ' (meta_id,post_id,meta_key,meta_value) VALUES (%d,101,%s,%s)',
			$post_meta[0], $post_meta[1], $post_meta[2]
		) );
	}
	p3b2bp_query( $admin, $admin->prepare(
		'INSERT INTO ' . $qualified( $tables['learndash_user_activity_table'] ) . ' (activity_id,user_id,post_id,course_id,activity_type,activity_status,activity_started,activity_completed,activity_updated) VALUES
		(7001,42,101,101,%s,1,%d,%d,%d),(8001,42,201,101,%s,1,%d,%d,%d)',
		'course', $started, $completed, $completed, 'quiz', $started, $quiz_completed, $quiz_completed
	) );
	foreach ( array( array( 21, 'pass', '1' ), array( 22, 'percentage', '88.125' ) ) as $activity_meta ) {
		p3b2bp_query( $admin, $admin->prepare(
			'INSERT INTO ' . $qualified( $tables['learndash_user_activity_meta_table'] ) . ' (activity_meta_id,activity_id,activity_meta_key,activity_meta_value) VALUES (%d,8001,%s,%s)',
			$activity_meta[0], $activity_meta[1], $activity_meta[2]
		) );
	}

	return array(
		'database' => $database,
		'user' => $user,
		'password' => $password,
		'tables' => $tables,
	);
}

/** @param wpdb $admin @param array<string,mixed> $setup */
function p3b2bp_cleanup( $admin, array $setup ): void {
	$database = p3b2bp_identifier( $setup['database'] );
	$user_host = $admin->prepare( '%s@%s', $setup['user'], '%' );
	p3b2bp_query( $admin, "DROP USER IF EXISTS {$user_host}" );
	p3b2bp_query( $admin, "DROP DATABASE IF EXISTS {$database}" );
}

/** @param array<string,mixed> $setup @return wpdb */
function p3b2bp_source_connection( array $setup ) {
	$host = getenv( 'GHCA_TEST_DB_HOST' );
	$connection = new wpdb( $setup['user'], $setup['password'], $setup['database'], $host );
	if ( ! empty( $connection->error ) ) {
		throw new RuntimeException( 'Could not connect restricted disposable source principal.' );
	}
	$connection->set_prefix( 'wp_' );
	$connection->charset = 'utf8mb4';
	$connection->collate = 'utf8mb4_unicode_ci';
	$connection->set_charset( $connection->dbh, $connection->charset, $connection->collate );
	$connection->suppress_errors( true );
	p3b2bp_query( $connection, "SET time_zone = '+00:00'" );
	return $connection;
}

/** @return array<string,mixed> */
function p3b2bp_identity(): array {
	$audit = GHCA_ACD_Archive_Canonical_Object::from_members( array(
		array( '101', array(
			'category_order' => 0, 'course_order' => 0, 'credit_minutes' => '60',
			'is_orientation' => false, 'odp_category_key' => 'individual_rights', 'oltl_category_key' => 'general',
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
			'cycle_key' => '2026', 'employee_user_id_decimal' => '42',
			'program_key' => 'annual_training', 'site_id_decimal' => '1', 'tenant_id' => str_repeat( '1', 32 ),
		),
		'policy_digest' => hash( 'sha256', "ghca-archive-policy-v1\n" . GHCA_ACD_Archive_Canonical_JSON::encode( $policy ) ),
		'resolved_cycle' => array(
			'boundary' => '[)', 'display_label' => '2026', 'end_gmt' => '2027-01-01T00:00:00Z',
			'key' => '2026', 'policy_key' => 'calendar_year', 'policy_version' => 1,
			'start_gmt' => '2026-01-01T00:00:00Z', 'timezone' => 'UTC',
		),
		'reviewed_source_fingerprint' => str_repeat( '2', 64 ),
		'revision_number' => 1,
		'stream_id' => str_repeat( 'b', 32 ),
		'subject_scope_digest' => str_repeat( '3', 64 ),
		'trigger_event_id' => str_repeat( 'c', 32 ),
	);
}

/** @param wpdb $source @param array<string,mixed> $setup @param wpdb $archive */
function p3b2bp_adapter( $source, array $setup, $archive, ?callable $monotonic = null ): GHCA_ACD_LearnDash_Archive_Evidence_Source {
	$current_user = $source->get_var( 'SELECT CURRENT_USER()' );
	$archive_connection_id = (int) $archive->get_var( 'SELECT CONNECTION_ID()' );
	$descriptor = array(
		'base_prefix' => 'wp_',
		'blog_id' => 1,
		'blog_prefix' => 'wp_',
		'capabilities_meta_key' => 'wp_capabilities',
		'learndash_user_activity_meta_table' => $setup['tables']['learndash_user_activity_meta_table'],
		'learndash_user_activity_table' => $setup['tables']['learndash_user_activity_table'],
		'options_table' => $setup['tables']['options_table'],
		'postmeta_table' => $setup['tables']['postmeta_table'],
		'posts_table' => $setup['tables']['posts_table'],
		'site_id' => '1',
		'source_database' => $setup['database'],
		'tenant_id' => str_repeat( '1', 32 ),
		'user_roles_option_name' => 'wp_user_roles',
		'usermeta_table' => $setup['tables']['usermeta_table'],
		'users_table' => $setup['tables']['users_table'],
	);
	$session = new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
		$source,
		$descriptor,
		array( 'archive_connection_id' => $archive_connection_id, 'current_user' => $current_user ),
		ghca_persist_table_names( $archive ),
		$monotonic
	);
	return new GHCA_ACD_LearnDash_Archive_Evidence_Source( $session, array(
		'wordpress_version' => '7.0.2',
		'learndash_version' => '5.1.6.1',
		'plugin_version' => '1.2.0',
	) );
}

/** @param wpdb $source @return array<string,mixed> */
function p3b2bp_read( $source, array $setup, $archive ): array {
	return p3b2bp_adapter( $source, $setup, $archive )->read_consistent_evidence(
		p3b2bp_identity(),
		array( 'maximum_queries' => 32, 'maximum_rows' => 10000, 'maximum_transaction_milliseconds' => 2000 ),
		static function (): void {}
	);
}

if ( defined( 'GHCA_P3B2B_PERSISTENCE_LIBRARY_ONLY' ) ) {
	return;
}

ghca_persist_fresh_schema( $wpdb );
$setup = null;
try {
	$setup = p3b2bp_setup( $wpdb );
	$source = p3b2bp_source_connection( $setup );
	$grants = $source->get_col( 'SHOW GRANTS FOR CURRENT_USER()' );
	$grant_text = implode( "\n", array_map( 'strtoupper', $grants ) );
	$grant_safe = false !== strpos( $grant_text, 'SELECT' )
		&& false !== strpos( $grant_text, strtoupper( $setup['database'] ) )
		&& 1 !== preg_match( '/\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRIGGER|EVENT|EXECUTE|FILE|PROCESS|SUPER|REPLICATION|GRANT OPTION)\b/', $grant_text );
	archive_check(
		$grant_safe,
		'P3B2B-RESTRICTED-SOURCE-GRANT-IS-SELECT-ONLY proves the disposable adapter principal has only schema SELECT'
	);

	$archive_events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" );
	$document = p3b2bp_read( $source, $setup, $wpdb );
	$archive_events_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ghca_acd_archive_events" );
	archive_check(
		'completed' === $document['courses'][0]['completion_status']
			&& 8813 === $document['courses'][0]['quiz_score_basis_points']
			&& 'e956c221c569b5b9e9d568233e8538f0e52027cce6a9ff514054287c40f18a55' === $document['courses'][0]['source_provenance']['record_version'],
		'P3B2B-PHYSICAL-MAPPING-MATCHES-INDEPENDENT-GOLDEN reproduces exact source bytes on the real database'
	);
	archive_check(
		$archive_events_before === $archive_events_after,
		'P3B2B-NO-SOURCE-OR-ARCHIVE-MUTATION leaves archive persistence unchanged after the read-only adapter call'
	);

	$denied = true;
	foreach ( array(
		'INSERT INTO wp_users (ID,user_email,user_registered,display_name) VALUES (99,\'x@example.test\',\'2026-01-01 00:00:00\',\'X\')',
		'UPDATE wp_users SET display_name = \'Changed\' WHERE ID = 42',
		'DELETE FROM wp_users WHERE ID = 42',
		'CREATE TABLE forbidden_write (id int)',
		'SELECT * FROM ' . p3b2bp_identifier( getenv( 'GHCA_TEST_DB_NAME' ) ) . '.' . p3b2bp_identifier( $wpdb->prefix . 'ghca_acd_archive_events' ) . ' LIMIT 1',
	) as $sql ) {
		$probe = p3b2bp_source_connection( $setup );
		$probe->last_error = '';
		$result = $probe->query( $sql );
		$denied = $denied && false === $result && '' !== $probe->last_error;
		$probe->close();
	}
	archive_check(
		$denied,
		'P3B2B-RESTRICTED-SOURCE-PRINCIPAL-CANNOT-WRITE-OR-READ-ARCHIVE proves the connection trust boundary'
	);
} finally {
	if ( is_array( $setup ) ) {
		p3b2bp_cleanup( $wpdb, $setup );
	}
}

archive_finish();
