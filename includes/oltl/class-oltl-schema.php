<?php
/** Additive OLTL Chapter 52 manual-review and event schema. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_OLTL_Schema {
	const VERSION        = 1;
	const OPTION_VERSION = 'ghca_acd_oltl_db_version';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 3 );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	public static function install(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();
		$reviews = self::reviews_table();
		$events  = self::events_table();
		dbDelta( "CREATE TABLE {$reviews} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			employee_user_id bigint(20) unsigned NOT NULL,
			revision_no int(10) unsigned NOT NULL,
			decision varchar(24) NOT NULL,
			service_description text NOT NULL,
			training_date date NOT NULL,
			rationale text NOT NULL,
			evidence_reference varchar(191) NOT NULL,
			evidence_manifest longtext NULL,
			evidence_digest char(64) NOT NULL DEFAULT '',
			supersedes_id bigint(20) unsigned NULL,
			actor_user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY employee_revision (employee_user_id,revision_no),
			KEY employee_created (employee_user_id,created_at),
			KEY supersedes_id (supersedes_id)
		) ENGINE=InnoDB {$collate};" );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key char(64) NOT NULL,
			event_type varchar(40) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			employee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			manual_review_id bigint(20) unsigned NULL,
			outcome varchar(24) NOT NULL DEFAULT 'success',
			error_code varchar(64) NULL,
			request_id char(32) NOT NULL,
			event_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY employee_event (employee_user_id,event_at),
			KEY actor_event (actor_user_id,event_at)
		) ENGINE=InnoDB {$collate};" );

		if ( ! self::tables_exist() || ! self::ensure_transactional_tables() || ! self::required_columns_exist() ) {
			return false;
		}
		update_option( self::OPTION_VERSION, self::VERSION, false );
		return true;
	}

	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( array( self::reviews_table(), self::events_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	private static function ensure_transactional_tables(): bool {
		global $wpdb;
		foreach ( array( self::reviews_table(), self::events_table() ) as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
				if ( false === $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					return false;
				}
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
				if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Verify the physical schema before advancing the resumable migration gate. */
	private static function required_columns_exist(): bool {
		global $wpdb;
		$required = array(
			self::reviews_table() => array( 'employee_user_id', 'revision_no', 'decision', 'service_description', 'training_date', 'rationale', 'evidence_reference', 'evidence_manifest', 'evidence_digest', 'supersedes_id', 'actor_user_id', 'created_at' ),
			self::events_table()  => array( 'event_key', 'event_type', 'actor_user_id', 'employee_user_id', 'manual_review_id', 'outcome', 'error_code', 'request_id', 'event_at' ),
		);
		foreach ( $required as $table => $columns ) {
			foreach ( $columns as $column ) {
				try {
					$found = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				} catch ( Throwable $e ) {
					return false;
				}
				if ( ! is_array( $found ) || $column !== (string) ( $found['Field'] ?? '' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function reviews_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_oltl_manual_reviews';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ghca_acd_oltl_events';
	}
}
