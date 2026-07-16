<?php
/**
 * Dual-Layer Archive Event-Sourcing Schema Migrator.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) ) {
	exit;
}

require_once __DIR__ . '/class-archive-schema.php';

final class GHCA_ACD_Archive_Migrator {
	/** @var wpdb */
	private $wpdb;

	/** @var string */
	private $prefix;

	/** @var string */
	private $charset_collate;

	/** @var string|null */
	private $lock_name;

	/** @var string|null */
	private $last_error_code;

	public function __construct( $wpdb ) {
		$this->wpdb             = $wpdb;
		$this->prefix           = $wpdb->prefix;
		$this->charset_collate  = $wpdb->get_charset_collate();
		$this->lock_name        = null;
		$this->last_error_code  = null;
	}

	/**
	 * Run the migration under an atomic connection-owned database lock.
	 *
	 * @return bool
	 */
	public function migrate() {
		$this->last_error_code = null;
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		$result = false;
		try {
			$result = $this->migrate_locked();
		} finally {
			if ( ! $this->release_lock() ) {
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * Return the last stable migration/postflight failure code.
	 *
	 * @return string|null
	 */
	public function get_last_error_code() {
		return $this->last_error_code;
	}

	/**
	 * Get the installed schema version.
	 *
	 * @return string|null
	 */
	public function get_schema_version() {
		$table = $this->quote_identifier( $this->prefix . 'options' );
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1",
				GHCA_ACD_Archive_Schema::SCHEMA_VERSION_OPTION
			)
		);

		return $row ? $row->option_value : null;
	}

	/**
	 * Perform strict fixed-manifest verification against the live database.
	 *
	 * @param array<string,string> $schemas Expected CREATE statements keyed by table.
	 * @return bool
	 */
	public function postflight_verify( $schemas ) {
		$manifest = require __DIR__ . '/schema-manifest.php';
		if ( count( $manifest ) !== 13 || count( $schemas ) !== 13 ) {
			return $this->reject( 'schema_manifest_count_mismatch' );
		}

		$expected_tables = array();
		foreach ( array_keys( $manifest ) as $base_name ) {
			$expected_tables[] = $this->prefix . $base_name;
		}
		if ( array_keys( $schemas ) !== $expected_tables ) {
			return $this->reject( 'schema_manifest_order_mismatch' );
		}

		$actual_tables = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT TABLE_NAME FROM information_schema.tables
				 WHERE table_schema = DATABASE() AND table_name LIKE %s
				 ORDER BY TABLE_NAME",
				$this->wpdb->esc_like( $this->prefix . 'ghca_acd_archive_' ) . '%'
			)
		);
		$sorted_expected = $expected_tables;
		sort( $sorted_expected, SORT_STRING );
		if ( $this->wpdb->last_error || $actual_tables !== $sorted_expected ) {
			return $this->reject( 'schema_table_set_mismatch' );
		}

		$expected_table_collation = $this->expected_table_collation();
		if ( $expected_table_collation === null ) {
			return $this->reject( 'schema_expected_collation_unavailable' );
		}
		if ( ! $this->verify_no_prohibited_database_objects( $expected_tables ) ) {
			return false;
		}

		foreach ( $manifest as $base_name => $expected ) {
			$table_name = $this->prefix . $base_name;
			$table_info = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables
					 WHERE table_schema = DATABASE() AND table_name = %s",
					$table_name
				)
			);
			if ( $this->wpdb->last_error || ! $table_info ) {
				return $this->reject( 'schema_table_metadata_unavailable' );
			}
			if ( strtolower( $table_info->ENGINE ) !== 'innodb' ) {
				return $this->reject( 'schema_engine_mismatch' );
			}
			if ( strtolower( $table_info->TABLE_COLLATION ) !== $expected_table_collation ) {
				return $this->reject( 'schema_table_collation_mismatch' );
			}

			if ( ! $this->verify_columns( $table_name, $expected['columns'], $expected_table_collation ) ) {
				return false;
			}
			if ( ! $this->verify_indexes( $table_name, $expected['indexes'] ) ) {
				return false;
			}
		}

		$this->last_error_code = null;
		return true;
	}

	/** @return bool */
	private function migrate_locked() {
		if ( ! $this->disable_flags() ) {
			return $this->reject( 'schema_flags_not_safely_disabled' );
		}

		$database = $this->wpdb->get_row( 'SELECT VERSION() AS version, @@version_comment AS version_comment' );
		if ( $this->wpdb->last_error || ! $database ) {
			return $this->reject( 'schema_database_version_unavailable' );
		}
		if ( ! $this->database_is_supported( $database->version, $database->version_comment ) ) {
			return $this->reject( 'schema_database_unsupported' );
		}

		$schemas           = GHCA_ACD_Archive_Schema::get_schema( $this->prefix, $this->charset_collate );
		$installed_version = $this->get_schema_version();
		if ( $this->wpdb->last_error ) {
			return $this->reject( 'schema_version_read_failed' );
		}
		if (
			$installed_version === GHCA_ACD_Archive_Schema::CURRENT_VERSION &&
			$this->postflight_verify( $schemas )
		) {
			return true;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$previous_suppression = $this->wpdb->suppress_errors( true );
		$ddl_ok               = true;
		try {
			foreach ( $schemas as $sql ) {
				$result = dbDelta( $sql );
				if ( ! is_array( $result ) || $this->wpdb->last_error ) {
					$ddl_ok = false;
					break;
				}
			}
		} finally {
			$this->wpdb->suppress_errors( $previous_suppression );
		}

		if ( ! $ddl_ok ) {
			return $this->reject( 'schema_ddl_failed' );
		}
		if ( ! $this->postflight_verify( $schemas ) ) {
			return false;
		}
		if ( ! $this->set_schema_version( GHCA_ACD_Archive_Schema::CURRENT_VERSION ) ) {
			return $this->reject( 'schema_version_write_failed' );
		}

		return true;
	}

	/** @return bool */
	private function acquire_lock() {
		$database_name = $this->wpdb->get_var( 'SELECT DATABASE()' );
		if ( $this->wpdb->last_error || ! is_string( $database_name ) || $database_name === '' ) {
			return $this->reject( 'schema_lock_database_unavailable' );
		}

		$this->lock_name = 'ghca_acd_archive_' . substr( hash( 'sha256', $database_name . '|' . $this->prefix ), 0, 32 );
		$acquired        = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name )
		);
		if ( $this->wpdb->last_error || (string) $acquired !== '1' ) {
			$this->lock_name = null;
			return $this->reject( 'schema_lock_unavailable' );
		}

		return true;
	}

	/** @return bool */
	private function release_lock() {
		if ( $this->lock_name === null ) {
			return true;
		}

		$released        = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name )
		);
		$this->lock_name = null;
		if ( $this->wpdb->last_error || (string) $released !== '1' ) {
			return $this->reject( 'schema_lock_release_failed' );
		}

		return true;
	}

	/** @return bool */
	private function disable_flags() {
		$table = $this->quote_identifier( $this->prefix . 'options' );
		$flags = array( 'ghca_acd_archive_enabled', 'ghca_acd_archive_dual_layer' );
		$rows  = $this->wpdb->get_results(
			"SELECT option_name, option_value, autoload FROM {$table}
			 WHERE option_name IN ('ghca_acd_archive_enabled','ghca_acd_archive_dual_layer')"
		);
		if ( $this->wpdb->last_error ) {
			return false;
		}

		$found = array();
		foreach ( $rows as $row ) {
			$found[ $row->option_name ] = $row;
		}
		foreach ( $flags as $flag ) {
			if ( ! isset( $found[ $flag ] ) ) {
				continue;
			}
			if ( $found[ $flag ]->option_value !== '0' || $found[ $flag ]->autoload !== 'no' ) {
				$updated = $this->wpdb->update(
					$this->prefix . 'options',
					array( 'option_value' => '0', 'autoload' => 'no' ),
					array( 'option_name' => $flag )
				);
				if ( $updated === false || $this->wpdb->last_error ) {
					return false;
				}
			}
		}

		$values = $this->wpdb->get_results(
			"SELECT option_name, option_value FROM {$table}
			 WHERE option_name IN ('ghca_acd_archive_enabled','ghca_acd_archive_dual_layer')"
		);
		if ( $this->wpdb->last_error ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( in_array( $value->option_name, $flags, true ) && $value->option_value !== '0' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $version
	 * @return bool
	 */
	private function set_schema_version( $version ) {
		$table    = $this->prefix . 'options';
		$existing = $this->get_schema_version();
		if ( $this->wpdb->last_error ) {
			return false;
		}
		if ( $existing === $version ) {
			return true;
		}

		if ( $existing === null ) {
			$result = $this->wpdb->insert(
				$table,
				array(
					'option_name'  => GHCA_ACD_Archive_Schema::SCHEMA_VERSION_OPTION,
					'option_value' => $version,
					'autoload'     => 'no',
				)
			);
		} else {
			$result = $this->wpdb->update(
				$table,
				array( 'option_value' => $version ),
				array( 'option_name' => GHCA_ACD_Archive_Schema::SCHEMA_VERSION_OPTION )
			);
		}
		if ( $result === false || $this->wpdb->last_error ) {
			return false;
		}

		return $this->get_schema_version() === $version && ! $this->wpdb->last_error;
	}

	/**
	 * @param string $version
	 * @param string $version_comment
	 * @return bool
	 */
	private function database_is_supported( $version, $version_comment ) {
		if ( ! preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', (string) $version, $matches ) ) {
			return false;
		}

		$number      = $matches[1];
		$is_mariadb  = stripos( (string) $version, 'mariadb' ) !== false || stripos( (string) $version_comment, 'mariadb' ) !== false;
		$is_mysql    = ! $is_mariadb && stripos( (string) $version_comment, 'mysql' ) !== false;

		if ( $is_mariadb ) {
			return version_compare( $number, '10.6', '>=' );
		}
		if ( $is_mysql ) {
			return version_compare( $number, '8.0', '>=' );
		}

		return false;
	}

	/**
	 * @param string $table_name
	 * @param array<string,array<string,mixed>> $expected_columns
	 * @param string $expected_table_collation
	 * @return bool
	 */
	private function verify_columns( $table_name, $expected_columns, $expected_table_collation ) {
		$columns = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
				        CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION
				 FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s
				 ORDER BY ORDINAL_POSITION",
				$table_name
			)
		);
		if ( $this->wpdb->last_error || count( $columns ) !== count( $expected_columns ) ) {
			return $this->reject( 'schema_column_count_mismatch' );
		}

		$expected_names = array_keys( $expected_columns );
		$actual_names   = array();
		foreach ( $columns as $column ) {
			$actual_names[] = strtolower( $column->COLUMN_NAME );
		}
		if ( $actual_names !== $expected_names ) {
			return $this->reject( 'schema_column_order_mismatch' );
		}

		foreach ( $columns as $column ) {
			$name     = strtolower( $column->COLUMN_NAME );
			$expected = $expected_columns[ $name ];
			if ( $this->normalize_column_type( $column->COLUMN_TYPE ) !== $this->normalize_column_type( $expected['type'] ) ) {
				return $this->reject( 'schema_column_type_mismatch' );
			}

			$nullable = $expected['nullable'] ? 'YES' : 'NO';
			if ( strtoupper( $column->IS_NULLABLE ) !== $nullable ) {
				return $this->reject( 'schema_column_nullability_mismatch' );
			}

			$expected_default = array_key_exists( 'default', $expected ) ? $expected['default'] : null;
			$actual_default   = $this->normalize_column_default( $column->COLUMN_DEFAULT );
			if ( $actual_default !== $expected_default ) {
				return $this->reject( 'schema_column_default_mismatch' );
			}

			$auto_increment = stripos( (string) $column->EXTRA, 'auto_increment' ) !== false;
			$expected_ai    = ! empty( $expected['auto_increment'] );
			if ( $auto_increment !== $expected_ai ) {
				return $this->reject( 'schema_column_auto_increment_mismatch' );
			}
			if ( (string) $column->GENERATION_EXPRESSION !== '' || stripos( (string) $column->EXTRA, 'generated' ) !== false ) {
				return $this->reject( 'schema_generated_column_prohibited' );
			}

			if ( ! empty( $expected['ascii_bin'] ) ) {
				if ( strtolower( (string) $column->COLLATION_NAME ) !== 'ascii_bin' ) {
					return $this->reject( 'schema_column_collation_mismatch' );
				}
			} elseif ( $column->CHARACTER_SET_NAME !== null && strtolower( (string) $column->COLLATION_NAME ) !== $expected_table_collation ) {
				return $this->reject( 'schema_column_collation_mismatch' );
			}
		}

		return true;
	}

	/**
	 * @param string $table_name
	 * @param array<string,array<string,mixed>> $expected_indexes
	 * @return bool
	 */
	private function verify_indexes( $table_name, $expected_indexes ) {
		$rows = $this->wpdb->get_results( 'SHOW INDEX FROM ' . $this->quote_identifier( $table_name ) );
		if ( $this->wpdb->last_error || empty( $rows ) ) {
			return $this->reject( 'schema_index_metadata_unavailable' );
		}

		$actual_indexes = array();
		foreach ( $rows as $row ) {
			$key = $row->Key_name;
			if ( ! isset( $actual_indexes[ $key ] ) ) {
				$actual_indexes[ $key ] = array(
					'unique' => (int) $row->Non_unique === 0,
					'type'    => strtoupper( (string) $row->Index_type ),
					'usable'  => true,
					'columns' => array(),
					'prefixes'=> array(),
				);
			}
			if ( property_exists( $row, 'Visible' ) && strtoupper( (string) $row->Visible ) !== 'YES' ) {
				$actual_indexes[ $key ]['usable'] = false;
			}
			if ( property_exists( $row, 'Ignored' ) && strtoupper( (string) $row->Ignored ) === 'YES' ) {
				$actual_indexes[ $key ]['usable'] = false;
			}
			$sequence = (int) $row->Seq_in_index;
			$actual_indexes[ $key ]['columns'][ $sequence ]  = strtolower( (string) $row->Column_name );
			$actual_indexes[ $key ]['prefixes'][ $sequence ] = $row->Sub_part === null ? null : (int) $row->Sub_part;
		}

		if ( count( $actual_indexes ) !== count( $expected_indexes ) ) {
			return $this->reject( 'schema_index_set_mismatch' );
		}
		foreach ( $expected_indexes as $key => $expected ) {
			if ( ! isset( $actual_indexes[ $key ] ) ) {
				return $this->reject( 'schema_index_missing' );
			}
			$actual = $actual_indexes[ $key ];
			ksort( $actual['columns'], SORT_NUMERIC );
			ksort( $actual['prefixes'], SORT_NUMERIC );
			if ( $actual['unique'] !== $expected['unique'] ) {
				return $this->reject( 'schema_index_uniqueness_mismatch' );
			}
			if ( array_values( $actual['columns'] ) !== $expected['columns'] ) {
				return $this->reject( 'schema_index_order_mismatch' );
			}
			if ( array_filter( $actual['prefixes'], static function ( $prefix ) { return $prefix !== null; } ) ) {
				return $this->reject( 'schema_index_prefix_mismatch' );
			}
			if ( $actual['type'] !== 'BTREE' ) {
				return $this->reject( 'schema_index_type_mismatch' );
			}
			if ( ! $actual['usable'] ) {
				return $this->reject( 'schema_index_not_usable' );
			}
		}

		return true;
	}

	/**
	 * @param array<int,string> $table_names
	 * @return bool
	 */
	private function verify_no_prohibited_database_objects( $table_names ) {
		$placeholders = implode( ',', array_fill( 0, count( $table_names ), '%s' ) );
		$constraints  = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
				 WHERE CONSTRAINT_SCHEMA = DATABASE()
				   AND TABLE_NAME IN ({$placeholders})
				   AND CONSTRAINT_TYPE IN ('FOREIGN KEY','CHECK')",
				$table_names
			)
		);
		if ( $this->wpdb->last_error || (string) $constraints !== '0' ) {
			return $this->reject( 'schema_constraint_prohibited' );
		}

		$triggers = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TRIGGERS
				 WHERE TRIGGER_SCHEMA = DATABASE()
				   AND EVENT_OBJECT_TABLE IN ({$placeholders})",
				$table_names
			)
		);
		if ( $this->wpdb->last_error || (string) $triggers !== '0' ) {
			return $this->reject( 'schema_trigger_prohibited' );
		}

		return true;
	}

	/** @return string|null */
	private function expected_table_collation() {
		if ( preg_match( '/\bCOLLATE\s+([a-zA-Z0-9_]+)/', $this->charset_collate, $matches ) ) {
			return strtolower( $matches[1] );
		}

		$collation = $this->wpdb->get_var(
			'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
		);
		return $this->wpdb->last_error || ! is_string( $collation ) || $collation === '' ? null : strtolower( $collation );
	}

	/** @return string */
	private function normalize_column_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		$type = preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type );
		return preg_replace( '/\s+/', ' ', $type );
	}

	/** @return string|null */
	private function normalize_column_default( $default ) {
		if ( $default === null || strtoupper( (string) $default ) === 'NULL' ) {
			return null;
		}
		return (string) $default;
	}

	/** @return string */
	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/** @return bool */
	private function reject( $code ) {
		$this->last_error_code = $code;
		return false;
	}
}
