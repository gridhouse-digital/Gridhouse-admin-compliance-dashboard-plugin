<?php
/**
 * Dual-Layer Archive Event-Sourcing Schema Definitions.
 *
 * Migration Identifier: 0001_create_archive_schema_v1
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) ) {
	exit;
}

final class GHCA_ACD_Archive_Schema {
	const SCHEMA_VERSION_OPTION = 'ghca_acd_archive_schema_version';
	const CURRENT_VERSION = '0001_create_archive_schema_v1';

	/**
	 * Get all schema definitions in the exact mandated creation order.
	 *
	 * @param string $prefix Active wpdb prefix.
	 * @param string $charset_collate Active wpdb charset and collation.
	 * @return array<string, string> Table name => CREATE TABLE statement.
	 */
	public static function get_schema( $prefix, $charset_collate ) {
		$ascii_bin = 'CHARACTER SET ascii COLLATE ascii_bin';
		
		$schemas = array();
		
		// 9.3
		$table = $prefix . 'ghca_acd_archive_streams';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			stream_id char(32) {$ascii_bin} NOT NULL,
			case_key_digest char(64) {$ascii_bin} NOT NULL,
			case_key_format_version smallint unsigned NOT NULL,
			tenant_id char(32) {$ascii_bin} NOT NULL,
			site_id bigint unsigned NOT NULL,
			employee_user_id bigint unsigned NOT NULL,
			program_key varchar(64) {$ascii_bin} NOT NULL,
			cycle_key varchar(191) NOT NULL,
			cycle_key_digest char(64) {$ascii_bin} NOT NULL,
			cycle_start_gmt datetime NOT NULL,
			cycle_end_gmt datetime NOT NULL,
			cycle_timezone varchar(64) {$ascii_bin} NOT NULL,
			cycle_policy_key varchar(64) {$ascii_bin} NOT NULL,
			head_sequence bigint unsigned NOT NULL DEFAULT 0,
			head_event_digest char(64) {$ascii_bin} DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (stream_id),
			UNIQUE KEY case_key_digest (case_key_digest),
			KEY employee_program (site_id, employee_user_id, program_key),
			KEY tenant_cycle (tenant_id, cycle_key_digest),
			KEY updated_at_gmt (updated_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.4
		$table = $prefix . 'ghca_acd_archive_events';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			event_row_id bigint unsigned NOT NULL AUTO_INCREMENT,
			event_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			case_key_digest char(64) {$ascii_bin} NOT NULL,
			case_key_format_version smallint unsigned NOT NULL,
			stream_sequence bigint unsigned NOT NULL,
			event_type varchar(64) {$ascii_bin} NOT NULL,
			event_schema_version smallint unsigned NOT NULL,
			canonical_format_version smallint unsigned NOT NULL,
			archive_id char(32) {$ascii_bin} DEFAULT NULL,
			build_attempt_id char(32) {$ascii_bin} DEFAULT NULL,
			reset_operation_id char(32) {$ascii_bin} DEFAULT NULL,
			actor_kind varchar(32) {$ascii_bin} NOT NULL,
			actor_user_id bigint unsigned DEFAULT NULL,
			initiating_user_id bigint unsigned DEFAULT NULL,
			source_channel varchar(32) {$ascii_bin} NOT NULL,
			authority_code varchar(64) {$ascii_bin} NOT NULL,
			authority_context_json longtext NOT NULL,
			occurred_at_gmt datetime NOT NULL,
			effective_at_gmt datetime DEFAULT NULL,
			correlation_id char(32) {$ascii_bin} NOT NULL,
			causation_event_id char(32) {$ascii_bin} DEFAULT NULL,
			command_id char(32) {$ascii_bin} DEFAULT NULL,
			upstream_operation_id varchar(191) {$ascii_bin} DEFAULT NULL,
			idempotency_scope_digest char(64) {$ascii_bin} DEFAULT NULL,
			idempotency_key_digest char(64) {$ascii_bin} DEFAULT NULL,
			command_digest char(64) {$ascii_bin} DEFAULT NULL,
			reason_code varchar(64) {$ascii_bin} DEFAULT NULL,
			reason_text text DEFAULT NULL,
			previous_event_digest char(64) {$ascii_bin} DEFAULT NULL,
			event_digest char(64) {$ascii_bin} NOT NULL,
			payload_json longtext NOT NULL,
			metadata_json longtext NOT NULL,
			recorded_at_gmt datetime NOT NULL,
			PRIMARY KEY  (event_row_id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY stream_sequence (stream_id, stream_sequence),
			KEY stream_row (stream_id, event_row_id),
			KEY archive_id (archive_id),
			KEY reset_operation_id (reset_operation_id),
			KEY command_id (command_id),
			KEY correlation_id (correlation_id),
			KEY type_time (event_type, recorded_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.5
		$table = $prefix . 'ghca_acd_archive_commands';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			command_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} DEFAULT NULL,
			command_type varchar(64) {$ascii_bin} NOT NULL,
			command_schema_version smallint unsigned NOT NULL,
			canonical_format_version smallint unsigned NOT NULL,
			idempotency_format_version smallint unsigned NOT NULL,
			dedupe_digest char(64) {$ascii_bin} NOT NULL,
			idempotency_scope_digest char(64) {$ascii_bin} NOT NULL,
			idempotency_scope_json longtext NOT NULL,
			idempotency_key_digest char(64) {$ascii_bin} NOT NULL,
			client_intent_digest char(64) {$ascii_bin} NOT NULL,
			command_digest char(64) {$ascii_bin} NOT NULL,
			actor_user_id bigint unsigned DEFAULT NULL,
			decision varchar(16) {$ascii_bin} NOT NULL,
			result_code varchar(64) {$ascii_bin} NOT NULL,
			first_stream_sequence bigint unsigned DEFAULT NULL,
			last_stream_sequence bigint unsigned DEFAULT NULL,
			first_event_id char(32) {$ascii_bin} DEFAULT NULL,
			last_event_id char(32) {$ascii_bin} DEFAULT NULL,
			response_schema_version smallint unsigned NOT NULL,
			response_json longtext NOT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (command_id),
			UNIQUE KEY dedupe_digest (dedupe_digest),
			KEY stream_created (stream_id, created_at_gmt),
			KEY actor_created (actor_user_id, created_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.6
		$table = $prefix . 'ghca_acd_archive_snapshots';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			snapshot_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			archive_id char(32) {$ascii_bin} NOT NULL,
			revision_number int unsigned NOT NULL,
			source_event_id char(32) {$ascii_bin} NOT NULL,
			snapshot_schema_version smallint unsigned NOT NULL,
			canonical_format_version smallint unsigned NOT NULL,
			source_fingerprint_version smallint unsigned NOT NULL,
			reviewed_source_fingerprint char(64) {$ascii_bin} NOT NULL,
			captured_source_fingerprint char(64) {$ascii_bin} NOT NULL,
			policy_digest char(64) {$ascii_bin} NOT NULL,
			completeness_policy varchar(64) {$ascii_bin} NOT NULL,
			completeness_result varchar(16) {$ascii_bin} NOT NULL,
			snapshot_digest char(64) {$ascii_bin} NOT NULL,
			snapshot_json longtext NOT NULL,
			byte_count bigint unsigned NOT NULL,
			item_count int unsigned NOT NULL,
			captured_by_user_id bigint unsigned DEFAULT NULL,
			captured_at_gmt datetime NOT NULL,
			PRIMARY KEY  (snapshot_id),
			UNIQUE KEY archive_id (archive_id),
			UNIQUE KEY stream_revision (stream_id,revision_number),
			KEY source_event_id (source_event_id),
			KEY captured_at_gmt (captured_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.7
		$table = $prefix . 'ghca_acd_archive_artifacts';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			artifact_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			archive_id char(32) {$ascii_bin} NOT NULL,
			snapshot_id char(32) {$ascii_bin} NOT NULL,
			build_attempt_id char(32) {$ascii_bin} NOT NULL,
			artifact_kind varchar(32) {$ascii_bin} NOT NULL,
			artifact_schema_version smallint unsigned NOT NULL,
			producer_key varchar(64) {$ascii_bin} NOT NULL,
			producer_version varchar(64) {$ascii_bin} NOT NULL,
			role_key varchar(191) {$ascii_bin} NOT NULL,
			dedupe_digest char(64) {$ascii_bin} NOT NULL,
			storage_adapter varchar(32) {$ascii_bin} NOT NULL,
			storage_key varchar(512) {$ascii_bin} NOT NULL,
			filename varchar(255) NOT NULL,
			media_type varchar(100) {$ascii_bin} NOT NULL,
			byte_count bigint unsigned NOT NULL,
			content_digest_algorithm varchar(32) {$ascii_bin} NOT NULL,
			content_digest char(64) {$ascii_bin} NOT NULL,
			snapshot_digest char(64) {$ascii_bin} NOT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (artifact_id),
			UNIQUE KEY dedupe_digest (dedupe_digest),
			KEY revision_kind (archive_id,artifact_kind),
			KEY snapshot_id (snapshot_id),
			KEY build_attempt_id (build_attempt_id)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.8
		$table = $prefix . 'ghca_acd_archive_ledger_items';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			ledger_item_id bigint unsigned NOT NULL AUTO_INCREMENT,
			ledger_artifact_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			archive_id char(32) {$ascii_bin} NOT NULL,
			snapshot_id char(32) {$ascii_bin} NOT NULL,
			item_ordinal int unsigned NOT NULL,
			employee_user_id bigint unsigned NOT NULL,
			program_key varchar(64) {$ascii_bin} NOT NULL,
			cycle_key varchar(191) NOT NULL,
			cycle_key_digest char(64) {$ascii_bin} NOT NULL,
			course_id bigint unsigned NOT NULL,
			course_stable_key varchar(191) {$ascii_bin} DEFAULT NULL,
			course_title varchar(255) NOT NULL,
			completion_status varchar(32) {$ascii_bin} NOT NULL,
			started_at_gmt datetime DEFAULT NULL,
			completed_at_gmt datetime DEFAULT NULL,
			time_spent_seconds bigint unsigned NOT NULL,
			quiz_score_basis_points int unsigned DEFAULT NULL,
			certificate_artifact_id char(32) {$ascii_bin} DEFAULT NULL,
			item_digest char(64) {$ascii_bin} NOT NULL,
			item_schema_version smallint unsigned NOT NULL,
			item_json longtext NOT NULL,
			PRIMARY KEY  (ledger_item_id),
			UNIQUE KEY ledger_ordinal (ledger_artifact_id,item_ordinal),
			KEY revision_course (archive_id,course_id),
			KEY employee_cycle (employee_user_id,program_key,cycle_key_digest),
			KEY completion_time (completed_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.9
		$table = $prefix . 'ghca_acd_archive_tasks';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			task_row_id bigint unsigned NOT NULL AUTO_INCREMENT,
			task_id char(32) {$ascii_bin} NOT NULL,
			trigger_kind varchar(16) {$ascii_bin} NOT NULL,
			trigger_event_id char(32) {$ascii_bin} DEFAULT NULL,
			trigger_command_id char(32) {$ascii_bin} DEFAULT NULL,
			stream_id char(32) {$ascii_bin} DEFAULT NULL,
			archive_id char(32) {$ascii_bin} DEFAULT NULL,
			build_attempt_id char(32) {$ascii_bin} DEFAULT NULL,
			reset_operation_id char(32) {$ascii_bin} DEFAULT NULL,
			task_type varchar(64) {$ascii_bin} NOT NULL,
			task_schema_version smallint unsigned NOT NULL,
			dedupe_digest char(64) {$ascii_bin} NOT NULL,
			payload_json longtext NOT NULL,
			task_state varchar(16) {$ascii_bin} NOT NULL,
			attempt_count int unsigned NOT NULL,
			max_attempts int unsigned NOT NULL,
			available_at_gmt datetime NOT NULL,
			lease_owner char(32) {$ascii_bin} DEFAULT NULL,
			lease_token char(32) {$ascii_bin} DEFAULT NULL,
			lease_until_gmt datetime DEFAULT NULL,
			last_error_code varchar(64) {$ascii_bin} DEFAULT NULL,
			last_error_text text DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			completed_at_gmt datetime DEFAULT NULL,
			PRIMARY KEY  (task_row_id),
			UNIQUE KEY task_id (task_id),
			UNIQUE KEY dedupe_digest (dedupe_digest),
			KEY claimable (task_state,available_at_gmt,lease_until_gmt),
			KEY stream_state (stream_id,task_state),
			KEY trigger_event_id (trigger_event_id),
			KEY trigger_command_id (trigger_command_id)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.10
		$table = $prefix . 'ghca_acd_archive_case_state';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			stream_id char(32) {$ascii_bin} NOT NULL,
			tenant_id char(32) {$ascii_bin} NOT NULL,
			site_id bigint unsigned NOT NULL,
			employee_user_id bigint unsigned NOT NULL,
			program_key varchar(64) {$ascii_bin} NOT NULL,
			cycle_key varchar(191) NOT NULL,
			cycle_key_digest char(64) {$ascii_bin} NOT NULL,
			cycle_start_gmt datetime NOT NULL,
			cycle_end_gmt datetime NOT NULL,
			cycle_timezone varchar(64) {$ascii_bin} NOT NULL,
			projected_sequence bigint unsigned NOT NULL,
			projected_event_digest char(64) {$ascii_bin} DEFAULT NULL,
			projection_schema_version smallint unsigned NOT NULL,
			current_archive_id char(32) {$ascii_bin} DEFAULT NULL,
			active_archive_id char(32) {$ascii_bin} DEFAULT NULL,
			correction_target_archive_id char(32) {$ascii_bin} DEFAULT NULL,
			build_state varchar(32) {$ascii_bin} DEFAULT NULL,
			validity_state varchar(32) {$ascii_bin} DEFAULT NULL,
			reset_state varchar(32) {$ascii_bin} NOT NULL,
			source_drift_state varchar(32) {$ascii_bin} NOT NULL,
			source_drift_incident_id char(32) {$ascii_bin} DEFAULT NULL,
			unprotected_reset_state varchar(32) {$ascii_bin} NOT NULL,
			unprotected_reset_incident_id char(32) {$ascii_bin} DEFAULT NULL,
			integrity_state varchar(32) {$ascii_bin} NOT NULL,
			integrity_incident_id char(32) {$ascii_bin} DEFAULT NULL,
			edit_locked tinyint(1) NOT NULL,
			reset_eligible tinyint(1) NOT NULL,
			edit_lock_reason varchar(64) {$ascii_bin} DEFAULT NULL,
			reset_block_reason varchar(64) {$ascii_bin} DEFAULT NULL,
			last_failure_code varchar(64) {$ascii_bin} DEFAULT NULL,
			state_changed_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (stream_id),
			KEY employee_program (site_id,employee_user_id,program_key),
			KEY active_cycle (active_archive_id,cycle_key_digest),
			KEY lifecycle (build_state,validity_state,reset_state),
			KEY incident_flags (source_drift_state,unprotected_reset_state,integrity_state),
			KEY source_drift_incident_id (source_drift_incident_id),
			KEY unprotected_reset_incident_id (unprotected_reset_incident_id),
			KEY integrity_incident_id (integrity_incident_id)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.11
		$table = $prefix . 'ghca_acd_archive_revision_state';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			archive_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			revision_number int unsigned NOT NULL,
			last_changed_sequence bigint unsigned NOT NULL,
			last_changed_event_digest char(64) {$ascii_bin} NOT NULL,
			build_state varchar(32) {$ascii_bin} NOT NULL,
			validity_state varchar(32) {$ascii_bin} NOT NULL,
			snapshot_id char(32) {$ascii_bin} DEFAULT NULL,
			ledger_artifact_id char(32) {$ascii_bin} DEFAULT NULL,
			packet_artifact_id char(32) {$ascii_bin} DEFAULT NULL,
			current_build_attempt_id char(32) {$ascii_bin} DEFAULT NULL,
			supersedes_archive_id char(32) {$ascii_bin} DEFAULT NULL,
			superseded_by_archive_id char(32) {$ascii_bin} DEFAULT NULL,
			failure_phase varchar(64) {$ascii_bin} DEFAULT NULL,
			failure_code varchar(64) {$ascii_bin} DEFAULT NULL,
			failure_text text DEFAULT NULL,
			requested_by_user_id bigint unsigned DEFAULT NULL,
			requested_at_gmt datetime NOT NULL,
			finalized_by_user_id bigint unsigned DEFAULT NULL,
			finalized_at_gmt datetime DEFAULT NULL,
			revoked_by_user_id bigint unsigned DEFAULT NULL,
			revoked_at_gmt datetime DEFAULT NULL,
			superseded_by_user_id bigint unsigned DEFAULT NULL,
			superseded_at_gmt datetime DEFAULT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (archive_id),
			UNIQUE KEY stream_revision (stream_id, revision_number),
			KEY stream_validity (stream_id, validity_state),
			KEY snapshot_id (snapshot_id),
			KEY finalized_at_gmt (finalized_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.12
		$table = $prefix . 'ghca_acd_archive_reset_state';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			reset_operation_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			archive_id char(32) {$ascii_bin} NOT NULL,
			snapshot_id char(32) {$ascii_bin} DEFAULT NULL,
			authorization_id char(32) {$ascii_bin} DEFAULT NULL,
			last_changed_sequence bigint unsigned NOT NULL,
			last_changed_event_digest char(64) {$ascii_bin} NOT NULL,
			reset_state varchar(32) {$ascii_bin} NOT NULL,
			scope_digest char(64) {$ascii_bin} NOT NULL,
			scope_schema_version smallint unsigned NOT NULL,
			scope_json longtext NOT NULL,
			requested_by_user_id bigint unsigned DEFAULT NULL,
			authorized_by_user_id bigint unsigned DEFAULT NULL,
			requested_at_gmt datetime NOT NULL,
			request_valid_until_gmt datetime DEFAULT NULL,
			deferred_until_gmt datetime DEFAULT NULL,
			defer_condition_code varchar(64) {$ascii_bin} DEFAULT NULL,
			authorized_at_gmt datetime DEFAULT NULL,
			expires_at_gmt datetime DEFAULT NULL,
			claimed_at_gmt datetime DEFAULT NULL,
			cancelled_at_gmt datetime DEFAULT NULL,
			invalidated_at_gmt datetime DEFAULT NULL,
			expired_at_gmt datetime DEFAULT NULL,
			outcome_at_gmt datetime DEFAULT NULL,
			reconciled_at_gmt datetime DEFAULT NULL,
			gateway_key varchar(64) {$ascii_bin} DEFAULT NULL,
			upstream_operation_id varchar(191) {$ascii_bin} DEFAULT NULL,
			outcome_code varchar(64) {$ascii_bin} DEFAULT NULL,
			reconciliation_code varchar(64) {$ascii_bin} DEFAULT NULL,
			failure_code varchar(64) {$ascii_bin} DEFAULT NULL,
			failure_text text DEFAULT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (reset_operation_id),
			UNIQUE KEY authorization_id (authorization_id),
			UNIQUE KEY gateway_operation (gateway_key, upstream_operation_id),
			KEY stream_state (stream_id, reset_state),
			KEY archive_id (archive_id),
			KEY claimed_at_gmt (claimed_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.13
		$table = $prefix . 'ghca_acd_archive_reset_authorizations';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			authorization_id char(32) {$ascii_bin} NOT NULL,
			reset_operation_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			archive_id char(32) {$ascii_bin} NOT NULL,
			snapshot_id char(32) {$ascii_bin} NOT NULL,
			scope_digest char(64) {$ascii_bin} NOT NULL,
			auth_state varchar(16) {$ascii_bin} NOT NULL,
			issued_event_id char(32) {$ascii_bin} NOT NULL,
			terminal_event_id char(32) {$ascii_bin} DEFAULT NULL,
			issued_at_gmt datetime NOT NULL,
			expires_at_gmt datetime NOT NULL,
			consumed_at_gmt datetime DEFAULT NULL,
			closed_at_gmt datetime DEFAULT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (authorization_id),
			UNIQUE KEY reset_operation_id (reset_operation_id),
			KEY state_expiry (auth_state, expires_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.14
		$table = $prefix . 'ghca_acd_archive_projection_heads';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			projector_key varchar(64) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			projection_schema_version smallint unsigned NOT NULL,
			projected_sequence bigint unsigned NOT NULL,
			projected_event_digest char(64) {$ascii_bin} DEFAULT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (projector_key, stream_id),
			KEY stream_sequence (stream_id, projected_sequence),
			KEY projector_version (projector_key, projection_schema_version)
		) ENGINE=InnoDB {$charset_collate};";

		// 9.15
		$table = $prefix . 'ghca_acd_archive_checkpoints';
		$schemas[ $table ] = "CREATE TABLE {$table} (
			checkpoint_id char(32) {$ascii_bin} NOT NULL,
			stream_id char(32) {$ascii_bin} NOT NULL,
			head_sequence bigint unsigned NOT NULL,
			head_event_digest char(64) {$ascii_bin} NOT NULL,
			algorithm varchar(32) {$ascii_bin} NOT NULL,
			key_id varchar(191) {$ascii_bin} DEFAULT NULL,
			signature longblob DEFAULT NULL,
			created_by_kind varchar(32) {$ascii_bin} NOT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (checkpoint_id),
			UNIQUE KEY stream_head (stream_id, head_sequence),
			KEY created_at_gmt (created_at_gmt)
		) ENGINE=InnoDB {$charset_collate};";

		return $schemas;
	}
}
