<?php

/** Resolves the current-blog runtime authority directly from trusted code and storage. */
final class GHCA_ACD_WordPress_Archive_Runtime_Descriptor {
	const OPTION_NAMES = array(
		'ghca_acd_archive_dual_layer',
		'ghca_acd_archive_enabled',
		'ghca_acd_archive_reset_enabled',
		'ghca_acd_archive_schema_version',
		'ghca_acd_archive_tenant_id',
	);
	const ARCHIVE_TABLES = array(
		'ghca_acd_archive_streams',
		'ghca_acd_archive_events',
		'ghca_acd_archive_commands',
		'ghca_acd_archive_snapshots',
		'ghca_acd_archive_artifacts',
		'ghca_acd_archive_ledger_items',
		'ghca_acd_archive_tasks',
		'ghca_acd_archive_case_state',
		'ghca_acd_archive_revision_state',
		'ghca_acd_archive_reset_state',
		'ghca_acd_archive_reset_authorizations',
		'ghca_acd_archive_projection_heads',
		'ghca_acd_archive_checkpoints',
	);

	/** @var object */
	private $db;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;
	/** @var callable */
	private $free_space;

	/** @param object $db */
	public function __construct( $db, ?GHCA_ACD_Archive_Clock $clock = null, ?callable $free_space = null ) {
		if ( ! is_object( $db ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$this->db = $db;
		$this->clock = $clock ?: new GHCA_ACD_System_Archive_Clock();
		$this->free_space = $free_space ?: 'disk_free_space';
	}

	/** @return array<string,mixed> */
	public function resolve(): array {
		return $this->resolve_for_flags( '1' );
	}

	/** Resolve the code-enforceable activation gates while both flags remain off. */
	public function preflight(): array {
		return $this->resolve_for_flags( '0' );
	}

	/** @return array<string,mixed> */
	private function resolve_for_flags( string $expected_flag ): array {
		$mode = defined( 'GHCA_ACD_ARCHIVE_RUNTIME_MODE' ) ? GHCA_ACD_ARCHIVE_RUNTIME_MODE : null;
		if ( ! in_array( $mode, array( 'controlled_testing', 'production' ), true ) ) {
			$this->fail( 'archive_runtime_disabled' );
		}
		$site = $this->site_binding();
		$options = $this->option_rows( $site['options_table'] );
		$this->assert_flag( $options, 'ghca_acd_archive_schema_version', GHCA_ACD_Archive_Schema::CURRENT_VERSION );
		$this->assert_flag( $options, 'ghca_acd_archive_enabled', $expected_flag );
		$this->assert_flag( $options, 'ghca_acd_archive_dual_layer', $expected_flag );
		if ( isset( $options['ghca_acd_archive_reset_enabled'] ) ) {
			$this->assert_flag( $options, 'ghca_acd_archive_reset_enabled', '0' );
		}
		if ( ! isset( $options['ghca_acd_archive_tenant_id'] )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $options['ghca_acd_archive_tenant_id']['value'] )
			|| 'no' !== $options['ghca_acd_archive_tenant_id']['autoload'] ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$tenant_id = $options['ghca_acd_archive_tenant_id']['value'];
		$this->assert_stream_tenant( $site['site_id'], $tenant_id );
		$this->assert_schema();

		$archive_identity = $this->archive_identity();
		$source = $this->source_configuration( $archive_identity );
		$storage = $this->storage_configuration( $mode );
		$this->assert_authorization( $site, $mode, $storage );
		$descriptor = array(
			'base_prefix' => $site['base_prefix'],
			'blog_id' => $site['blog_id'],
			'blog_prefix' => $site['blog_prefix'],
			'capabilities_meta_key' => $site['blog_prefix'] . 'capabilities',
			'learndash_user_activity_meta_table' => $site['blog_prefix'] . 'learndash_user_activity_meta',
			'learndash_user_activity_table' => $site['blog_prefix'] . 'learndash_user_activity',
			'options_table' => $site['options_table'],
			'postmeta_table' => $site['blog_prefix'] . 'postmeta',
			'posts_table' => $site['blog_prefix'] . 'posts',
			'site_id' => $site['site_id'],
			'source_database' => $source['database'],
			'tenant_id' => $tenant_id,
			'user_roles_option_name' => $site['blog_prefix'] . 'user_roles',
			'usermeta_table' => $site['base_prefix'] . 'usermeta',
			'users_table' => $site['base_prefix'] . 'users',
		);
		foreach ( $descriptor as $key => $value ) {
			if ( in_array( $key, array( 'blog_id', 'site_id', 'tenant_id' ), true ) ) {
				continue;
			}
			$this->identifier( $value, in_array( $key, array( 'base_prefix', 'blog_prefix' ), true ) ? 32 : 64 );
		}

		return array(
			'archive_connection' => $archive_identity,
			'archive_tables' => $this->archive_tables( $site['blog_prefix'] ),
			'evidence_descriptor' => $descriptor,
			'mode' => $mode,
			'source' => $source,
			'storage' => $storage,
		);
	}

	/** @return string */
	public function provision_tenant() {
		$site = $this->site_binding();
		$this->assert_option_name_index( $site['options_table'] );
		$table = $this->quote( $site['options_table'] );
		$existing = $this->tenant_rows( $table );
		if ( 1 === count( $existing ) ) {
			return $this->tenant_value( $existing[0] );
		}
		if ( array() !== $existing || $this->stream_tenants( $site['site_id'] ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$id = bin2hex( random_bytes( 16 ) );
		$sql = $this->db->prepare(
			"INSERT INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,%s)",
			'ghca_acd_archive_tenant_id',
			$id,
			'no'
		);
		$result = $this->db->query( $sql );
		if ( false === $result && ! $this->duplicate_insert_failure() ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		if ( false !== $result && 1 !== $result ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$rows = $this->tenant_rows( $table );
		if ( 1 !== count( $rows ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$winner = $this->tenant_value( $rows[0] );
		if ( $winner !== $this->tenant_value( $this->tenant_rows( $table )[0] ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		return $winner;
	}

	private function assert_option_name_index( string $options_table ): void {
		$sql = $this->db->prepare(
			"SELECT INDEX_NAME AS index_name,NON_UNIQUE AS non_unique,SEQ_IN_INDEX AS seq_in_index,
				COLUMN_NAME AS column_name,SUB_PART AS sub_part,INDEX_TYPE AS index_type
			FROM information_schema.statistics
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
			ORDER BY SEQ_IN_INDEX",
			$options_table,
			'option_name'
		);
		$rows = $this->db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! empty( $this->db->last_error ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$row = is_object( $rows[0] ) ? get_object_vars( $rows[0] ) : $rows[0];
		if ( ! is_array( $row )
			|| array_keys( $row ) !== array( 'index_name', 'non_unique', 'seq_in_index', 'column_name', 'sub_part', 'index_type' )
			|| 'option_name' !== $row['index_name']
			|| 0 !== (int) $row['non_unique']
			|| 1 !== (int) $row['seq_in_index']
			|| 'option_name' !== $row['column_name']
			|| null !== $row['sub_part']
			|| 'BTREE' !== strtoupper( (string) $row['index_type'] ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
	}

	private function duplicate_insert_failure(): bool {
		$rows = $this->db->get_results(
			'SHOW ERRORS LIMIT 1',
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return false;
		}
		$row = is_object( $rows[0] ) ? get_object_vars( $rows[0] ) : $rows[0];
		return is_array( $row )
			&& isset( $row['Level'], $row['Code'] )
			&& 'Error' === $row['Level']
			&& 1062 === (int) $row['Code'];
	}

	/** @return array<string,mixed> */
	private function site_binding(): array {
		if ( ! function_exists( 'get_current_blog_id' ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$blog_id = get_current_blog_id();
		if ( ! is_int( $blog_id ) || $blog_id < 1
			|| ! isset( $this->db->base_prefix, $this->db->prefix )
			|| ! is_callable( array( $this->db, 'get_blog_prefix' ) ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		$base = $this->db->base_prefix;
		$prefix = $this->db->prefix;
		$derived = $this->db->get_blog_prefix( $blog_id );
		$this->identifier( $base, 32 );
		$this->identifier( $prefix, 32 );
		$this->identifier( $derived, 32 );
		if ( $prefix !== $derived ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		return array(
			'base_prefix' => $base,
			'blog_id' => $blog_id,
			'blog_prefix' => $derived,
			'options_table' => $derived . 'options',
			'site_id' => (string) $blog_id,
		);
	}

	/** @return array<string,array<string,string>> */
	private function option_rows( string $options_table ): array {
		$table = $this->quote( $options_table );
		$placeholders = implode( ',', array_fill( 0, count( self::OPTION_NAMES ), '%s' ) );
		$sql = $this->db->prepare(
			"SELECT option_name,option_value,autoload FROM {$table} WHERE option_name IN ({$placeholders}) ORDER BY BINARY option_name, option_id",
			...self::OPTION_NAMES
		);
		$rows = $this->db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) {
			$this->fail( 'archive_runtime_schema_mismatch' );
		}
		$found = array();
		foreach ( $rows as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : $row;
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'option_name', 'option_value', 'autoload' )
				|| ! in_array( $row['option_name'], self::OPTION_NAMES, true ) || isset( $found[ $row['option_name'] ] )
				|| ! is_string( $row['option_value'] ) || ! is_string( $row['autoload'] ) ) {
				$this->fail( 'archive_runtime_schema_mismatch' );
			}
			$found[ $row['option_name'] ] = array( 'value' => $row['option_value'], 'autoload' => $row['autoload'] );
		}
		return $found;
	}

	/** @param array<string,array<string,string>> $options */
	private function assert_flag( array $options, string $name, string $value ): void {
		if ( ! isset( $options[ $name ] ) || $value !== $options[ $name ]['value'] || 'no' !== $options[ $name ]['autoload'] ) {
			$this->fail( 'ghca_acd_archive_schema_version' === $name ? 'archive_runtime_schema_mismatch' : 'archive_runtime_disabled' );
		}
	}

	private function assert_stream_tenant( string $site_id, string $tenant_id ): void {
		$tenants = $this->stream_tenants( $site_id );
		if ( count( $tenants ) > 1 || ( 1 === count( $tenants ) && (string) $tenants[0] !== $tenant_id ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
	}

	/** @return array<int,mixed> */
	private function stream_tenants( string $site_id ): array {
		$table = $this->quote( $this->db->prefix . 'ghca_acd_archive_streams' );
		$sql = $this->db->prepare(
			"SELECT DISTINCT tenant_id FROM {$table} WHERE site_id = %s ORDER BY tenant_id LIMIT 2",
			$site_id
		);
		$rows = $this->db->get_col( $sql );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		return $rows;
	}

	private function assert_schema(): void {
		if ( ! is_callable( array( $this->db, 'get_charset_collate' ) ) ) {
			$this->fail( 'archive_runtime_schema_mismatch' );
		}
		$migrator = new GHCA_ACD_Archive_Migrator( $this->db );
		$schemas = GHCA_ACD_Archive_Schema::get_schema( $this->db->prefix, $this->db->get_charset_collate() );
		if ( count( $schemas ) !== 13 || ! $migrator->postflight_verify( $schemas ) ) {
			$this->fail( 'archive_runtime_schema_mismatch' );
		}
	}

	/** @return array<string,mixed> */
	private function archive_identity(): array {
		$row = $this->db->get_row(
			'SELECT CONNECTION_ID() AS connection_id,CURRENT_USER() AS authenticated_user,DATABASE() AS database_name',
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);
		$row = is_object( $row ) ? get_object_vars( $row ) : $row;
		if ( ! is_array( $row ) || ! isset( $row['connection_id'], $row['authenticated_user'], $row['database_name'] )
			|| (int) $row['connection_id'] < 1 || ! is_string( $row['authenticated_user'] ) || ! is_string( $row['database_name'] ) ) {
			$this->fail( 'archive_runtime_source_credentials_invalid' );
		}
		return array(
			'connection_id' => (int) $row['connection_id'],
			'current_user' => $row['authenticated_user'],
			'database' => $row['database_name'],
			'host' => $this->declared_property( 'dbhost' ),
		);
	}

	/** @param array<string,mixed> $archive @return array<string,string> */
	private function source_configuration( array $archive ): array {
		$names = array(
			'host' => 'GHCA_ACD_ARCHIVE_SOURCE_DB_HOST',
			'database' => 'GHCA_ACD_ARCHIVE_SOURCE_DB_NAME',
			'user' => 'GHCA_ACD_ARCHIVE_SOURCE_DB_USER',
			'password' => 'GHCA_ACD_ARCHIVE_SOURCE_DB_PASSWORD',
			'account' => 'GHCA_ACD_ARCHIVE_SOURCE_DB_ACCOUNT',
		);
		$values = array();
		foreach ( $names as $key => $constant ) {
			if ( ! defined( $constant ) || ! is_string( constant( $constant ) )
				|| '' === constant( $constant ) || strlen( constant( $constant ) ) > 512
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', constant( $constant ) ) ) {
				$this->fail( 'archive_runtime_source_credentials_invalid' );
			}
			$values[ $key ] = constant( $constant );
		}
		$this->identifier( $values['database'], 64 );
		if ( $values['host'] !== $archive['host'] || $values['database'] !== $archive['database']
			|| ! $this->valid_account( $values['account'] ) || $values['account'] === $archive['current_user'] ) {
			$this->fail( 'archive_runtime_source_credentials_invalid' );
		}
		return $values;
	}

	/** @return array<string,mixed> */
	private function storage_configuration( string $mode ): array {
		foreach ( array(
			'private_root' => 'GHCA_ACD_ARCHIVE_PRIVATE_DIR',
			'public_root' => 'GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT',
			'cursor_key' => 'GHCA_ACD_ARCHIVE_CURSOR_HMAC_KEY',
		) as $key => $constant ) {
			if ( ! defined( $constant ) || ! is_string( constant( $constant ) ) || '' === constant( $constant ) ) {
				$this->fail( 'cursor_key' === $key ? 'archive_runtime_cursor_key_invalid' : 'archive_runtime_storage_invalid' );
			}
			$values[ $key ] = constant( $constant );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $values['cursor_key'] ) ) {
			$this->fail( 'archive_runtime_cursor_key_invalid' );
		}
		$private = realpath( $values['private_root'] );
		$public = realpath( $values['public_root'] );
		$plugin_root = realpath( dirname( __DIR__, 3 ) );
		$content_root = false === $plugin_root ? false : realpath( dirname( dirname( $plugin_root ) ) );
		$wordpress_root = false === $content_root ? false : realpath( dirname( $content_root ) );
		if ( false === $private || false === $public || false === $wordpress_root
			|| is_link( $values['private_root'] ) || is_link( $values['public_root'] )
			|| ! is_dir( $private ) || ! is_readable( $private ) || ! is_writable( $private )
			|| ! $this->contained( $wordpress_root, $public )
			|| $this->overlaps( $private, $public ) ) {
			$this->fail( 'archive_runtime_storage_invalid' );
		}
		$public_roots = array( $public );
		if ( false !== $content_root && ! $this->contained( $content_root, $public ) ) {
			if ( $this->overlaps( $private, $content_root ) ) {
				$this->fail( 'archive_runtime_storage_invalid' );
			}
			$public_roots[] = $content_root;
		}
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			$permissions = fileperms( $private );
			if ( false === $permissions || 0 !== ( $permissions & 0077 ) ) {
				$this->fail( 'archive_runtime_storage_invalid' );
			}
		}
		$this->assert_storage_capacity( $mode, $private );
		return array(
			'cursor_key' => $values['cursor_key'],
			'private_root' => $private,
			'public_roots' => $public_roots,
		);
	}

	private function assert_storage_capacity( string $mode, string $private ): void {
		if ( 'controlled_testing' !== $mode ) {
			return;
		}
		$free = call_user_func( $this->free_space, $private );
		if ( ( ! is_int( $free ) && ! is_float( $free ) ) || $free < 1090519040 ) {
			$this->fail( 'archive_runtime_storage_invalid' );
		}
	}

	/** @param array<string,mixed> $site @param array<string,mixed> $storage */
	private function assert_authorization( array $site, string $mode, array $storage ): void {
		if ( ! defined( 'GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE' )
			|| ! is_string( GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE )
			|| '' === GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$declared = GHCA_ACD_ARCHIVE_ACTIVATION_AUTHORIZATION_FILE;
		$resolved = realpath( $declared );
		$plugin = realpath( dirname( __DIR__, 3 ) );
		$temp = realpath( sys_get_temp_dir() );
		if ( false === $resolved || false === $plugin || ! $this->absolute_path( $declared )
			|| $this->normalized_path( $resolved ) !== $this->normalized_path( $declared )
			|| ! is_file( $resolved ) || is_link( $declared ) || $this->has_symlink_parent( $declared )
			|| $this->overlaps( $resolved, $plugin ) || $this->overlaps( $resolved, $storage['private_root'] )
			|| ( false !== $temp && $this->contained( $resolved, $temp ) ) ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		foreach ( $storage['public_roots'] as $public_root ) {
			if ( $this->contained( $resolved, $public_root ) ) {
				$this->fail( 'archive_runtime_activation_blocked' );
			}
		}
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			$permissions = fileperms( $resolved );
			if ( false === $permissions || 0 !== ( $permissions & 0137 ) ) {
				$this->fail( 'archive_runtime_activation_blocked' );
			}
		}

		$path_stat = @lstat( $resolved );
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$bytes = '';
		try {
			$opened_stat = fstat( $handle );
			if ( ! $this->same_file_stat( $path_stat, $opened_stat ) ) {
				$this->fail( 'archive_runtime_activation_blocked' );
			}
			while ( ! feof( $handle ) && strlen( $bytes ) <= 2048 ) {
				$chunk = fread( $handle, 2049 - strlen( $bytes ) );
				if ( false === $chunk ) {
					$this->fail( 'archive_runtime_activation_blocked' );
				}
				$bytes .= $chunk;
			}
			if ( ! $this->same_file_stat( $opened_stat, @lstat( $resolved ) )
				|| is_link( $declared ) || realpath( $declared ) !== $resolved ) {
				$this->fail( 'archive_runtime_activation_blocked' );
			}
		} finally {
			fclose( $handle );
		}
		if ( '' === $bytes || strlen( $bytes ) > 2048 || 0 === strncmp( $bytes, "\xEF\xBB\xBF", 3 )
			|| 1 !== preg_match( '//u', $bytes ) ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		try {
			$document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical_bounded( $bytes, 2048 );
		} catch ( Throwable $error ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$keys = array(
			'authorization_schema_version', 'blog_id', 'change_role_id', 'end_at_gmt', 'evidence_sha256',
			'mode', 'operator_role_id', 'rollback_role_id', 'site_id', 'start_at_gmt',
		);
		if ( ! is_array( $document ) || array_keys( $document ) !== $keys
			|| 1 !== $document['authorization_schema_version']
			|| ! $this->positive_decimal( $document['blog_id'] ) || ! $this->positive_decimal( $document['site_id'] )
			|| (string) $site['blog_id'] !== $document['blog_id'] || $site['site_id'] !== $document['site_id']
			|| $mode !== $document['mode'] ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$roles = array( $document['change_role_id'], $document['operator_role_id'], $document['rollback_role_id'] );
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || 1 !== preg_match( '/^[A-Z0-9][A-Z0-9._:-]{0,63}$/D', $role ) ) {
				$this->fail( 'archive_runtime_activation_blocked' );
			}
		}
		if ( 3 !== count( array_unique( $roles ) ) || ! $this->authorization_time( $document['start_at_gmt'] )
			|| ! $this->authorization_time( $document['end_at_gmt'] ) ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s.u\\Z', $document['start_at_gmt'], new DateTimeZone( 'UTC' ) );
		$end = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s.u\\Z', $document['end_at_gmt'], new DateTimeZone( 'UTC' ) );
		$now = $this->clock->now_gmt();
		if ( false === $start || false === $end || strcmp( $document['start_at_gmt'], $now ) > 0
			|| strcmp( $now, $document['end_at_gmt'] ) >= 0 || $end->getTimestamp() <= $start->getTimestamp() ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
		$maximum = 'controlled_testing' === $mode ? 172800 : 86400;
		if ( $end->getTimestamp() - $start->getTimestamp() > $maximum
			|| ( 'controlled_testing' === $mode && null !== $document['evidence_sha256'] )
			|| ( 'production' === $mode && ( ! is_string( $document['evidence_sha256'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $document['evidence_sha256'] ) ) ) ) {
			$this->fail( 'archive_runtime_activation_blocked' );
		}
	}

	/** @return array<int,string> */
	private function archive_tables( string $prefix ): array {
		return array_map( static function ( string $suffix ) use ( $prefix ): string {
			return $prefix . $suffix;
		}, self::ARCHIVE_TABLES );
	}

	/** @return array<int,mixed> */
	private function tenant_rows( string $table ): array {
		$sql = $this->db->prepare(
			"SELECT option_name,option_value,autoload FROM {$table} WHERE option_name = %s ORDER BY option_id LIMIT 2",
			'ghca_acd_archive_tenant_id'
		);
		$rows = $this->db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		return array_map( static function ( $row ) {
			return is_object( $row ) ? get_object_vars( $row ) : $row;
		}, $rows );
	}

	/** @param array<string,mixed> $row */
	private function tenant_value( array $row ): string {
		if ( array_keys( $row ) !== array( 'option_name', 'option_value', 'autoload' )
			|| 'ghca_acd_archive_tenant_id' !== $row['option_name'] || 'no' !== $row['autoload']
			|| ! is_string( $row['option_value'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $row['option_value'] ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
		return $row['option_value'];
	}

	private function declared_property( string $name ): string {
		try {
			$property = new ReflectionProperty( $this->db, $name );
			$value = $property->getValue( $this->db );
		} catch ( Throwable $error ) {
			$this->fail( 'archive_runtime_source_credentials_invalid' );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			$this->fail( 'archive_runtime_source_credentials_invalid' );
		}
		return $value;
	}

	/** @param mixed $value */
	private function identifier( $value, int $maximum ): void {
		if ( ! is_string( $value ) || strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $value ) ) {
			$this->fail( 'archive_runtime_tenant_invalid' );
		}
	}

	private function valid_account( string $value ): bool {
		if ( strlen( $value ) < 3 || strlen( $value ) > 384 || 1 !== substr_count( $value, '@' )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return false;
		}
		list( $user, $host ) = explode( '@', $value, 2 );
		return '' !== $user && '' !== $host;
	}

	/** @param mixed $value */
	private function positive_decimal( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', $value );
	}

	/** @param mixed $value */
	private function authorization_time( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.000000Z$/D', $value ) ) {
			return false;
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i:s.u\\Z', $value, new DateTimeZone( 'UTC' ) );
		return false !== $time && $time->format( 'Y-m-d\\TH:i:s.u\\Z' ) === $value;
	}

	private function absolute_path( string $path ): bool {
		return 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/D', $path ) || 0 === strpos( $path, '/' );
	}

	private function has_symlink_parent( string $path ): bool {
		$parent = dirname( $path );
		while ( $parent !== dirname( $parent ) ) {
			if ( is_link( $parent ) ) {
				return true;
			}
			$parent = dirname( $parent );
		}
		return is_link( $parent );
	}

	/** @param array<string,mixed>|false $left @param array<string,mixed>|false $right */
	private function same_file_stat( $left, $right ): bool {
		if ( ! is_array( $left ) || ! is_array( $right ) ) {
			return false;
		}
		foreach ( array( 'dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime' ) as $field ) {
			if ( ! array_key_exists( $field, $left ) || ! array_key_exists( $field, $right ) || $left[ $field ] !== $right[ $field ] ) {
				return false;
			}
		}
		return true;
	}

	private function quote( string $identifier ): string {
		$this->identifier( $identifier, 64 );
		return '`' . $identifier . '`';
	}

	private function contained( string $path, string $root ): bool {
		$path = $this->normalized_path( $path );
		$root = rtrim( $this->normalized_path( $root ), '/' );
		return 0 === strpos( $path . '/', $root . '/' );
	}

	private function overlaps( string $left, string $right ): bool {
		return $this->contained( $left, $right ) || $this->contained( $right, $left );
	}

	private function normalized_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function fail( string $code ): void {
		throw new UnexpectedValueException( $code );
	}
}
