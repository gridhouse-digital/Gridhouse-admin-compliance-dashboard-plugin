<?php
/**
 * Additive schema for Jotform document indexing and approved external training.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Jotform_Schema {
	/**
	 * Version 6 adds administrator manual entry: form_id 'manual' is reserved (it never
	 * matches a configured Jotform form), entered_by/entry_reason record who entered the
	 * certificate and why, and manual_manifest holds the locally stored, still-pending
	 * certificate manifest until approval copies it into the immutable evidence revision.
	 */
	const VERSION        = 6;
	const OPTION_VERSION = 'ghca_acd_jotform_db_version';
	const OPTION_LEGACY_REVIEW = 'ghca_acd_jotform_legacy_review_required';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 2 );
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

		$installed_version = (int) get_option( self::OPTION_VERSION, 0 );
		$collate   = $wpdb->get_charset_collate();
		$documents = self::documents_table();
		$catalog   = self::catalog_table();
		$training  = self::training_table();
		$evidence  = self::evidence_table();
		$events    = self::events_table();

		$sql = array(
			"CREATE TABLE {$documents} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				employee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				form_id varchar(32) NOT NULL,
				submission_id varchar(32) NOT NULL,
				source_fingerprint char(64) NOT NULL,
				evidence_reference varchar(128) NOT NULL DEFAULT '',
				question_id varchar(32) NOT NULL,
				file_index smallint(5) unsigned NOT NULL DEFAULT 0,
				form_title varchar(191) NOT NULL DEFAULT '',
				field_label varchar(191) NOT NULL DEFAULT '',
				file_name varchar(255) NOT NULL DEFAULT '',
				media_type varchar(100) NOT NULL DEFAULT 'application/octet-stream',
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				source_created_at datetime NULL,
				source_updated_at datetime NULL,
				availability varchar(24) NOT NULL DEFAULT 'available',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_file (form_id,submission_id,source_fingerprint,question_id,file_index),
				KEY employee_created (employee_user_id,source_created_at),
				KEY submission_lookup (form_id,submission_id),
				KEY availability_updated (availability,updated_at)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$catalog} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				training_code varchar(100) NOT NULL,
				parent_title varchar(191) NOT NULL DEFAULT '',
				title varchar(191) NOT NULL,
				aliases text NULL,
				provider varchar(191) NOT NULL DEFAULT '',
				description longtext NULL,
				category varchar(64) NOT NULL,
				oltl_requirements text NULL,
				credit_hours decimal(8,2) unsigned NOT NULL DEFAULT 0.00,
				active tinyint(1) unsigned NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY training_code (training_code),
				KEY active_title (active,title)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$training} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				employee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				form_id varchar(32) NOT NULL,
				submission_id varchar(32) NOT NULL,
				source_fingerprint char(64) NOT NULL,
				catalog_id bigint(20) unsigned NULL,
				proposed_code varchar(100) NOT NULL DEFAULT '',
				proposed_title varchar(191) NOT NULL DEFAULT '',
				proposed_completion_date date NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				warning_code varchar(64) NULL,
				warning_detail varchar(255) NULL,
				review_reason text NULL,
				approved_revision_id bigint(20) unsigned NULL,
				entered_by bigint(20) unsigned NOT NULL DEFAULT 0,
				entry_reason text NULL,
				manual_manifest longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_revision (form_id,submission_id,source_fingerprint),
				KEY employee_status (employee_user_id,status),
				KEY status_updated (status,updated_at),
				KEY catalog_completion (catalog_id,proposed_completion_date)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$evidence} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				training_id bigint(20) unsigned NOT NULL,
				revision_no int(10) unsigned NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'approved',
				catalog_snapshot longtext NOT NULL,
				evidence_manifest longtext NOT NULL,
				snapshot_digest char(64) NOT NULL,
				approved_by bigint(20) unsigned NOT NULL,
				approved_at datetime NOT NULL,
				revoked_by bigint(20) unsigned NULL,
				revoked_at datetime NULL,
				revocation_reason text NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY training_revision (training_id,revision_no),
				KEY status_approved (status,approved_at)
			) ENGINE=InnoDB {$collate};",
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_key char(64) NOT NULL,
				event_type varchar(40) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				employee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				document_id bigint(20) unsigned NULL,
				training_id bigint(20) unsigned NULL,
				evidence_revision_id bigint(20) unsigned NULL,
				request_id char(32) NOT NULL,
				outcome varchar(24) NOT NULL DEFAULT 'success',
				error_code varchar(64) NULL,
				event_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY employee_event (employee_user_id,event_at),
				KEY actor_event (actor_user_id,event_at),
				KEY training_event (training_id,event_at)
			) ENGINE=InnoDB {$collate};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		if ( ! self::tables_exist() ) {
			return false;
		}
		if ( ! self::ensure_transactional_tables() ) {
			return false;
		}
		if ( ! self::required_columns_exist() ) {
			return false;
		}
		if ( $installed_version < 2 && ! self::quarantine_legacy_unsigned_rows() ) {
			return false;
		}
		update_option( self::OPTION_VERSION, self::VERSION, false );
		return true;
	}

	private static function quarantine_legacy_unsigned_rows(): bool {
		global $wpdb;
		$documents = self::documents_table();
		$training = self::training_table();
		$evidence = self::evidence_table();
		$now = current_time( 'mysql', true );

		$documents_result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$documents} d SET employee_user_id=0,updated_at=%s
			WHERE d.employee_user_id<>0 AND NOT EXISTS (
				SELECT 1 FROM {$training} t
				WHERE t.form_id=d.form_id AND t.submission_id=d.submission_id AND t.source_fingerprint=d.source_fingerprint
				AND t.status IN ('approved','superseded','revoked')
			)",
			$now
		) );
		$training_result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$training} SET employee_user_id=0,warning_code='ownership_claim_required',updated_at=%s
			WHERE employee_user_id<>0 AND status IN ('pending','rejected')",
			$now
		) );

		$inconsistent_result = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$training} t INNER JOIN {$evidence} e ON e.id=t.approved_revision_id
			WHERE t.status IN ('rejected','revoked','superseded') AND e.status='approved'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $documents_result || false === $training_result || null === $inconsistent_result ) {
			return false;
		}
		update_option( self::OPTION_LEGACY_REVIEW, (int) $inconsistent_result, false );
		return true;
	}

	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( array( self::documents_table(), self::catalog_table(), self::training_table(), self::evidence_table(), self::events_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	private static function ensure_transactional_tables(): bool {
		global $wpdb;
		$tables = array( self::documents_table(), self::catalog_table(), self::training_table(), self::evidence_table(), self::events_table() );
		foreach ( $tables as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || empty( $status['Engine'] ) ) {
				return false;
			}
			if ( 'innodb' !== strtolower( (string) $status['Engine'] ) ) {
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

	/** Verify physical columns; dbDelta output is advisory and cannot advance the schema gate. */
	private static function required_columns_exist(): bool {
		global $wpdb;
		$required = array(
			self::documents_table() => array( 'evidence_reference' ),
			self::training_table()  => array( 'warning_detail', 'review_reason', 'entered_by', 'entry_reason', 'manual_manifest' ),
			self::catalog_table()   => array( 'oltl_requirements' ),
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

	public static function documents_table(): string { global $wpdb; return $wpdb->prefix . 'ghca_acd_jotform_documents'; }
	public static function catalog_table(): string { global $wpdb; return $wpdb->prefix . 'ghca_acd_external_course_catalog'; }
	public static function training_table(): string { global $wpdb; return $wpdb->prefix . 'ghca_acd_external_training'; }
	public static function evidence_table(): string { global $wpdb; return $wpdb->prefix . 'ghca_acd_external_evidence'; }
	public static function events_table(): string { global $wpdb; return $wpdb->prefix . 'ghca_acd_evidence_events'; }
}
