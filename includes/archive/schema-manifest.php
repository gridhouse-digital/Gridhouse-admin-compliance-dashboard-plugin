<?php

return array (
  'ghca_acd_archive_streams' => 
  array (
    'columns' => 
    array (
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'case_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'case_key_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'tenant_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'site_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'employee_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'program_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_key' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_start_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_end_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_timezone' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_policy_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'head_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
        'default' => '0',
      ),
      'head_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'created_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
        ),
      ),
      'case_key_digest' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'case_key_digest',
        ),
      ),
      'employee_program' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'site_id',
          1 => 'employee_user_id',
          2 => 'program_key',
        ),
      ),
      'tenant_cycle' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'tenant_id',
          1 => 'cycle_key_digest',
        ),
      ),
      'updated_at_gmt' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'updated_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_events' => 
  array (
    'columns' => 
    array (
      'event_row_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
        'auto_increment' => true,
      ),
      'event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'case_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'case_key_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'stream_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'event_type' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'event_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'canonical_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'build_attempt_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reset_operation_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'actor_kind' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'actor_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'initiating_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'source_channel' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'authority_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'authority_context_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'occurred_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'effective_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'correlation_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'causation_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'command_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'upstream_operation_id' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'idempotency_scope_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'idempotency_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'command_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reason_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reason_text' => 
      array (
        'type' => 'text',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'previous_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'payload_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'metadata_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'recorded_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'event_row_id',
        ),
      ),
      'event_id' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'event_id',
        ),
      ),
      'stream_sequence' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'stream_sequence',
        ),
      ),
      'stream_row' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'event_row_id',
        ),
      ),
      'archive_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'archive_id',
        ),
      ),
      'reset_operation_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'reset_operation_id',
        ),
      ),
      'command_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'command_id',
        ),
      ),
      'correlation_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'correlation_id',
        ),
      ),
      'type_time' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'event_type',
          1 => 'recorded_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_commands' => 
  array (
    'columns' => 
    array (
      'command_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'command_type' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'command_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'canonical_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'idempotency_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'dedupe_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'idempotency_scope_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'idempotency_scope_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'idempotency_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'client_intent_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'command_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'actor_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'decision' => 
      array (
        'type' => 'varchar(16)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'result_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'first_stream_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'last_stream_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'first_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'last_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'response_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'response_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'created_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'command_id',
        ),
      ),
      'dedupe_digest' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'dedupe_digest',
        ),
      ),
      'stream_created' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'created_at_gmt',
        ),
      ),
      'actor_created' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'actor_user_id',
          1 => 'created_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_snapshots' => 
  array (
    'columns' => 
    array (
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'revision_number' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'source_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'canonical_format_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'source_fingerprint_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'reviewed_source_fingerprint' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'captured_source_fingerprint' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'policy_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'completeness_policy' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'completeness_result' => 
      array (
        'type' => 'varchar(16)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'byte_count' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'item_count' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'captured_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'captured_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'snapshot_id',
        ),
      ),
      'archive_id' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'archive_id',
        ),
      ),
      'stream_revision' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'revision_number',
        ),
      ),
      'source_event_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'source_event_id',
        ),
      ),
      'captured_at_gmt' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'captured_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_artifacts' => 
  array (
    'columns' => 
    array (
      'artifact_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'build_attempt_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'artifact_kind' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'artifact_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'producer_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'producer_version' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'role_key' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'dedupe_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'storage_adapter' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'storage_key' => 
      array (
        'type' => 'varchar(512)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'filename' => 
      array (
        'type' => 'varchar(255)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'media_type' => 
      array (
        'type' => 'varchar(100)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'byte_count' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'content_digest_algorithm' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'content_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'created_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'artifact_id',
        ),
      ),
      'dedupe_digest' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'dedupe_digest',
        ),
      ),
      'revision_kind' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'archive_id',
          1 => 'artifact_kind',
        ),
      ),
      'snapshot_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'snapshot_id',
        ),
      ),
      'build_attempt_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'build_attempt_id',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_ledger_items' => 
  array (
    'columns' => 
    array (
      'ledger_item_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
        'auto_increment' => true,
      ),
      'ledger_artifact_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'item_ordinal' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'employee_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'program_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_key' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'course_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'course_stable_key' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'course_title' => 
      array (
        'type' => 'varchar(255)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'completion_status' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'started_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'completed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'time_spent_seconds' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'quiz_score_basis_points' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'certificate_artifact_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'item_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'item_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'item_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'ledger_item_id',
        ),
      ),
      'ledger_ordinal' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'ledger_artifact_id',
          1 => 'item_ordinal',
        ),
      ),
      'revision_course' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'archive_id',
          1 => 'course_id',
        ),
      ),
      'employee_cycle' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'employee_user_id',
          1 => 'program_key',
          2 => 'cycle_key_digest',
        ),
      ),
      'completion_time' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'completed_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_tasks' => 
  array (
    'columns' => 
    array (
      'task_row_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
        'auto_increment' => true,
      ),
      'task_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'trigger_kind' => 
      array (
        'type' => 'varchar(16)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'trigger_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'trigger_command_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'build_attempt_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reset_operation_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'task_type' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'task_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'dedupe_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'payload_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'task_state' => 
      array (
        'type' => 'varchar(16)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'attempt_count' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'max_attempts' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'available_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'lease_owner' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'lease_token' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'lease_until_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'last_error_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'last_error_text' => 
      array (
        'type' => 'text',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'created_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'completed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'task_row_id',
        ),
      ),
      'task_id' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'task_id',
        ),
      ),
      'dedupe_digest' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'dedupe_digest',
        ),
      ),
      'claimable' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'task_state',
          1 => 'available_at_gmt',
          2 => 'lease_until_gmt',
        ),
      ),
      'stream_state' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'task_state',
        ),
      ),
      'trigger_event_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'trigger_event_id',
        ),
      ),
      'trigger_command_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'trigger_command_id',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_case_state' => 
  array (
    'columns' => 
    array (
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'tenant_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'site_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'employee_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'program_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_key' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_key_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'cycle_start_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_end_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'cycle_timezone' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'projected_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'projected_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'projection_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'current_archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'active_archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'correction_target_archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'build_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'validity_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reset_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'source_drift_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'source_drift_incident_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'unprotected_reset_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'unprotected_reset_incident_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'integrity_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'integrity_incident_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'edit_locked' => 
      array (
        'type' => 'tinyint(1)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'reset_eligible' => 
      array (
        'type' => 'tinyint(1)',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'edit_lock_reason' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reset_block_reason' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'last_failure_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'state_changed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
        ),
      ),
      'employee_program' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'site_id',
          1 => 'employee_user_id',
          2 => 'program_key',
        ),
      ),
      'active_cycle' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'active_archive_id',
          1 => 'cycle_key_digest',
        ),
      ),
      'lifecycle' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'build_state',
          1 => 'validity_state',
          2 => 'reset_state',
        ),
      ),
      'incident_flags' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'source_drift_state',
          1 => 'unprotected_reset_state',
          2 => 'integrity_state',
        ),
      ),
      'source_drift_incident_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'source_drift_incident_id',
        ),
      ),
      'unprotected_reset_incident_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'unprotected_reset_incident_id',
        ),
      ),
      'integrity_incident_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'integrity_incident_id',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_revision_state' => 
  array (
    'columns' => 
    array (
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'revision_number' => 
      array (
        'type' => 'int unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'last_changed_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'last_changed_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'build_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'validity_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'ledger_artifact_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'packet_artifact_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'current_build_attempt_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'supersedes_archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'superseded_by_archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'failure_phase' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'failure_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'failure_text' => 
      array (
        'type' => 'text',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'requested_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'requested_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'finalized_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'finalized_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'revoked_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'revoked_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'superseded_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'superseded_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'archive_id',
        ),
      ),
      'stream_revision' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'revision_number',
        ),
      ),
      'stream_validity' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'validity_state',
        ),
      ),
      'snapshot_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'snapshot_id',
        ),
      ),
      'finalized_at_gmt' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'finalized_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_reset_state' => 
  array (
    'columns' => 
    array (
      'reset_operation_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'authorization_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'last_changed_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'last_changed_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'reset_state' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'scope_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'scope_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'scope_json' => 
      array (
        'type' => 'longtext',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'requested_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'authorized_by_user_id' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'requested_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'request_valid_until_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'deferred_until_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'defer_condition_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'authorized_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'expires_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'claimed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'cancelled_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'invalidated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'expired_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'outcome_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'reconciled_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'gateway_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'upstream_operation_id' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'outcome_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'reconciliation_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'failure_code' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'failure_text' => 
      array (
        'type' => 'text',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'reset_operation_id',
        ),
      ),
      'authorization_id' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'authorization_id',
        ),
      ),
      'gateway_operation' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'gateway_key',
          1 => 'upstream_operation_id',
        ),
      ),
      'stream_state' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'reset_state',
        ),
      ),
      'archive_id' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'archive_id',
        ),
      ),
      'claimed_at_gmt' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'claimed_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_reset_authorizations' => 
  array (
    'columns' => 
    array (
      'authorization_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'reset_operation_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'archive_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'snapshot_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'scope_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'auth_state' => 
      array (
        'type' => 'varchar(16)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'issued_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'terminal_event_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'issued_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'expires_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'consumed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'closed_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'authorization_id',
        ),
      ),
      'reset_operation_id' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'reset_operation_id',
        ),
      ),
      'state_expiry' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'auth_state',
          1 => 'expires_at_gmt',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_projection_heads' => 
  array (
    'columns' => 
    array (
      'projector_key' => 
      array (
        'type' => 'varchar(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'projection_schema_version' => 
      array (
        'type' => 'smallint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'projected_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'projected_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'updated_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'projector_key',
          1 => 'stream_id',
        ),
      ),
      'stream_sequence' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'projected_sequence',
        ),
      ),
      'projector_version' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'projector_key',
          1 => 'projection_schema_version',
        ),
      ),
    ),
  ),
  'ghca_acd_archive_checkpoints' => 
  array (
    'columns' => 
    array (
      'checkpoint_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'stream_id' => 
      array (
        'type' => 'char(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'head_sequence' => 
      array (
        'type' => 'bigint unsigned',
        'ascii_bin' => false,
        'nullable' => false,
      ),
      'head_event_digest' => 
      array (
        'type' => 'char(64)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'algorithm' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'key_id' => 
      array (
        'type' => 'varchar(191)',
        'ascii_bin' => true,
        'nullable' => true,
      ),
      'signature' => 
      array (
        'type' => 'longblob',
        'ascii_bin' => false,
        'nullable' => true,
      ),
      'created_by_kind' => 
      array (
        'type' => 'varchar(32)',
        'ascii_bin' => true,
        'nullable' => false,
      ),
      'created_at_gmt' => 
      array (
        'type' => 'datetime',
        'ascii_bin' => false,
        'nullable' => false,
      ),
    ),
    'indexes' => 
    array (
      'PRIMARY' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'checkpoint_id',
        ),
      ),
      'stream_head' => 
      array (
        'unique' => true,
        'columns' => 
        array (
          0 => 'stream_id',
          1 => 'head_sequence',
        ),
      ),
      'created_at_gmt' => 
      array (
        'unique' => false,
        'columns' => 
        array (
          0 => 'created_at_gmt',
        ),
      ),
    ),
  ),
);
