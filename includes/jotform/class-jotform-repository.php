<?php
/**
 * Persistence boundary for indexed Jotform documents and external evidence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Jotform_Repository {
	const OPTION_FORMS       = 'ghca_acd_jotform_forms';
	const OPTION_SYNC_STATE  = 'ghca_acd_jotform_sync_state';
	const OPTION_LAST_SYNC   = 'ghca_acd_jotform_last_sync';
	const OPTION_LAST_ERROR  = 'ghca_acd_jotform_last_error';
	const OPTION_ENABLED     = 'ghca_acd_jotform_enabled';
	const GRANT_OPTION_PREFIX = 'ghca_acd_jotform_grant_';
	/** Reserved form identifier for administrator manual entries; it never matches a configured Jotform form. */
	const MANUAL_FORM_ID = 'manual';

	/** @param array<string,mixed> $training */
	public static function is_manual( array $training ): bool {
		return self::MANUAL_FORM_ID === (string) ( $training['form_id'] ?? '' );
	}

	/**
	 * Inserts a pending manual-entry training row before its certificate is stored. The row id
	 * is needed for the evidence path and AAD, so the manifest is attached in a second step.
	 *
	 * @param array<string,mixed> $catalog Active catalog lesson selected by the administrator.
	 * @return int New training id, or 0 when any input fails validation or the insert fails.
	 */
	public static function insert_manual_training( int $employee_id, array $catalog, string $completion_date, string $fingerprint, string $capture_id, string $reason, int $actor_id, string $warning_detail = '' ): int {
		global $wpdb;
		$date   = self::sanitize_date( $completion_date );
		$reason = substr( sanitize_textarea_field( $reason ), 0, 1000 );
		if ( $employee_id <= 0 || ! get_userdata( $employee_id ) || empty( $catalog['id'] ) || empty( $catalog['active'] ) || ! $date || '' === trim( $reason ) || $actor_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) || ! preg_match( '/^[a-f0-9]{32}$/', $capture_id ) ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		try {
			$inserted = $wpdb->insert(
				GHCA_ACD_Jotform_Schema::training_table(),
				array(
					'employee_user_id'         => $employee_id,
					'form_id'                  => self::MANUAL_FORM_ID,
					'submission_id'            => $capture_id,
					'source_fingerprint'       => $fingerprint,
					'catalog_id'               => (int) $catalog['id'],
					'proposed_code'            => substr( sanitize_key( (string) ( $catalog['training_code'] ?? '' ) ), 0, 100 ),
					'proposed_title'           => substr( sanitize_text_field( (string) ( $catalog['title'] ?? '' ) ), 0, 191 ),
					'proposed_completion_date' => $date,
					'status'                   => 'pending',
					'warning_code'             => 'manual_entry',
					'warning_detail'           => '' !== $warning_detail ? substr( sanitize_text_field( $warning_detail ), 0, 255 ) : null,
					'entered_by'               => $actor_id,
					'entry_reason'             => $reason,
					'manual_manifest'          => null,
					'created_at'               => $now,
					'updated_at'               => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		} catch ( Throwable $e ) {
			return 0;
		}
		return 1 === (int) $inserted ? (int) $wpdb->insert_id : 0;
	}

	/** True when the identical certificate bytes are already pending or approved for this employee. */
	public static function manual_fingerprint_exists( int $employee_id, string $fingerprint ): bool {
		global $wpdb;
		if ( $employee_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . GHCA_ACD_Jotform_Schema::training_table() . " WHERE form_id=%s AND employee_user_id=%d AND source_fingerprint=%s AND status IN ('pending','approved') LIMIT 1",
			self::MANUAL_FORM_ID, $employee_id, $fingerprint
		) );
	}

	/**
	 * Attaches the stored certificate manifest to a manual row exactly once.
	 *
	 * @param array<int,array<string,mixed>> $manifest
	 */
	public static function attach_manual_manifest( int $id, array $manifest ): bool {
		global $wpdb;
		$json = wp_json_encode( array_values( $manifest ) );
		if ( $id <= 0 || empty( $manifest ) || ! is_string( $json ) ) {
			return false;
		}
		try {
			$updated = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Jotform_Schema::training_table() . " SET manual_manifest=%s,updated_at=%s WHERE id=%d AND form_id=%s AND status='pending' AND manual_manifest IS NULL",
				$json, current_time( 'mysql', true ), $id, self::MANUAL_FORM_ID
			) );
		} catch ( Throwable $e ) {
			return false;
		}
		return 1 === (int) $updated;
	}

	/**
	 * Removes a manual row whose upload did not complete (storage, manifest or audit event failed).
	 * Only a still-pending manual row with no approved revision can ever be deleted; approved,
	 * superseded, revoked and rejected rows are permanent history.
	 */
	public static function delete_unfinished_manual_training( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		try {
			$deleted = $wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . GHCA_ACD_Jotform_Schema::training_table() . " WHERE id=%d AND form_id=%s AND status='pending' AND approved_revision_id IS NULL",
				$id, self::MANUAL_FORM_ID
			) );
		} catch ( Throwable $e ) {
			return false;
		}
		return 1 === (int) $deleted;
	}

	/**
	 * @param array<string,mixed> $training
	 * @return array<int,array<string,mixed>>|null Validated stored manifest for a manual row.
	 */
	public static function manual_manifest( array $training ): ?array {
		if ( ! self::is_manual( $training ) ) {
			return null;
		}
		$manifest = json_decode( (string) ( $training['manual_manifest'] ?? '' ), true );
		if ( ! is_array( $manifest ) || empty( $manifest ) ) {
			return null;
		}
		foreach ( $manifest as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['file_index'], $item['bytes'], $item['sha256'], $item['storage_path'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $item['sha256'] ) ) {
				return null;
			}
		}
		return array_values( $manifest );
	}

	/** @return array<int,array<string,mixed>> Pending manual entries for one employee, newest first. */
	public static function pending_manual_training_for_employee( int $user_id, int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::training_table() . " WHERE employee_user_id=%d AND form_id=%s AND status='pending' ORDER BY created_at DESC,id DESC LIMIT %d",
			$user_id, self::MANUAL_FORM_ID, max( 1, min( 50, $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every manual entry for one employee in any state, newest first. The employee drawer
	 * uses this because manual evidence never appears in the Jotform documents table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function manual_training_for_employee( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE employee_user_id=%d AND form_id=%s ORDER BY created_at DESC,id DESC LIMIT %d',
			$user_id, self::MANUAL_FORM_ID, max( 1, min( 50, $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The authoritative stored manifest for one manual row.
	 *
	 * An approved row is served from its immutable evidence revision with the snapshot digest
	 * re-verified, so a tampered revision yields nothing. A still-pending row is served from
	 * its captured manifest. Rejected rows return null because approval never happened and
	 * the stored certificate was deleted on rejection.
	 *
	 * @param array<string,mixed> $training
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function manual_evidence_manifest( array $training ): ?array {
		if ( ! self::is_manual( $training ) ) {
			return null;
		}
		$status = (string) ( $training['status'] ?? '' );
		if ( 'pending' === $status ) {
			return self::manual_manifest( $training );
		}
		if ( ! in_array( $status, array( 'approved', 'superseded', 'revoked' ), true ) || empty( $training['approved_revision_id'] ) ) {
			return null;
		}
		$revision = self::evidence_revision( (int) $training['approved_revision_id'] );
		if ( ! $revision || ! hash_equals( (string) $revision['snapshot_digest'], hash( 'sha256', (string) $revision['catalog_snapshot'] . "\n" . (string) $revision['evidence_manifest'] ) ) ) {
			return null;
		}
		$manifest = json_decode( (string) $revision['evidence_manifest'], true );
		if ( ! is_array( $manifest ) || empty( $manifest ) ) {
			return null;
		}
		foreach ( $manifest as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['file_index'], $item['bytes'], $item['sha256'], $item['storage_path'] ) ) {
				return null;
			}
		}
		return array_values( $manifest );
	}

	/** Manual entries an employee has, in any state. Counted alongside indexed Jotform documents. */
	public static function manual_entry_count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE employee_user_id=%d AND form_id=%s',
			$user_id, self::MANUAL_FORM_ID
		) );
	}

	/** Everything the employee has on record: indexed Jotform documents plus manual entries. */
	public static function employee_record_count( int $user_id ): int {
		return self::document_count( $user_id ) + self::manual_entry_count( $user_id );
	}

	/**
	 * Jotform documents matching an optional search, unpaginated and bounded, so they can be
	 * merged with manual entries before paging. The cap is far above realistic per-employee
	 * volume; beyond it the oldest rows are omitted rather than the query being unbounded.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_documents_for_employee( int $user_id, string $search = '', int $cap = 200 ): array {
		$cap = max( 1, min( 200, $cap ) );
		$items = array();
		while ( count( $items ) < $cap ) {
			$page = self::search_documents_for_employee( $user_id, $search, min( 50, $cap - count( $items ) ), count( $items ) );
			$items = array_merge( $items, $page['items'] );
			if ( count( $page['items'] ) === 0 || count( $items ) >= $page['total'] ) {
				break;
			}
		}
		return $items;
	}

	/** @return array{approved:int,pending:int,rejected:int,other:int} External-training counts for one employee. */
	public static function external_training_summary( int $user_id ): array {
		global $wpdb;
		$counts = array( 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'other' => 0 );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS total FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE employee_user_id=%d GROUP BY status',
			$user_id
		), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = (string) $row['status'];
			$key = array_key_exists( $status, $counts ) ? $status : 'other';
			$counts[ $key ] += (int) $row['total'];
		}
		return $counts;
	}

	/** @return array<int,array<string,string>> */
	public static function forms(): array {
		$forms = get_option( self::OPTION_FORMS, array() );
		return is_array( $forms ) ? array_values( array_filter( $forms, static function ( $form ): bool { return is_array( $form ) && ! empty( $form['form_id'] ); } ) ) : array();
	}

	/** @return array<string,string>|null */
	public static function form( string $form_id ): ?array {
		foreach ( self::forms() as $form ) {
			if ( hash_equals( (string) $form['form_id'], $form_id ) ) {
				return $form;
			}
		}
		return null;
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 0 ) && ! empty( self::forms() );
	}

	/** @param array<string,mixed> $row */
	public static function upsert_document( array $row ): int {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::documents_table();
		$now   = current_time( 'mysql', true );
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table}
			(employee_user_id,form_id,submission_id,source_fingerprint,evidence_reference,question_id,file_index,form_title,field_label,file_name,media_type,file_size,source_created_at,source_updated_at,availability,created_at,updated_at)
			VALUES (%d,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE employee_user_id=IF(employee_user_id=0,VALUES(employee_user_id),employee_user_id),evidence_reference=IF(evidence_reference='',VALUES(evidence_reference),evidence_reference),form_title=VALUES(form_title),field_label=VALUES(field_label),file_name=VALUES(file_name),media_type=VALUES(media_type),file_size=VALUES(file_size),source_updated_at=VALUES(source_updated_at),availability=VALUES(availability),updated_at=VALUES(updated_at)",
			(int) ( $row['employee_user_id'] ?? 0 ),
			(string) $row['form_id'],
			(string) $row['submission_id'],
			(string) $row['source_fingerprint'],
			substr( sanitize_text_field( (string) ( $row['evidence_reference'] ?? '' ) ), 0, 128 ),
			(string) $row['question_id'],
			(int) $row['file_index'],
			substr( sanitize_text_field( (string) ( $row['form_title'] ?? '' ) ), 0, 191 ),
			substr( sanitize_text_field( (string) ( $row['field_label'] ?? '' ) ), 0, 191 ),
			substr( sanitize_file_name( (string) ( $row['file_name'] ?? '' ) ), 0, 255 ),
			substr( sanitize_mime_type( (string) ( $row['media_type'] ?? 'application/octet-stream' ) ), 0, 100 ),
			max( 0, (int) ( $row['file_size'] ?? 0 ) ),
			(string) ( $row['source_created_at'] ?? $now ),
			(string) ( $row['source_updated_at'] ?? $now ),
			(string) ( $row['availability'] ?? 'available' ),
			$now,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			return $id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE form_id=%s AND submission_id=%s AND source_fingerprint=%s AND question_id=%s AND file_index=%d",
			(string) $row['form_id'], (string) $row['submission_id'], (string) $row['source_fingerprint'], (string) $row['question_id'], (int) $row['file_index']
		) );
	}

	/** @param array<string,mixed> $row */
	public static function insert_training_candidate( array $row ): int {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::training_table();
		$found = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE form_id=%s AND submission_id=%s AND source_fingerprint=%s",
			(string) $row['form_id'], (string) $row['submission_id'], (string) $row['source_fingerprint']
		) );
		if ( $found > 0 ) {
			return $found;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'employee_user_id'        => (int) ( $row['employee_user_id'] ?? 0 ),
				'form_id'                 => (string) $row['form_id'],
				'submission_id'           => (string) $row['submission_id'],
				'source_fingerprint'      => (string) $row['source_fingerprint'],
				'catalog_id'              => ! empty( $row['catalog_id'] ) ? (int) $row['catalog_id'] : null,
				'proposed_code'           => substr( sanitize_key( (string) ( $row['proposed_code'] ?? '' ) ), 0, 100 ),
				'proposed_title'          => substr( sanitize_text_field( (string) ( $row['proposed_title'] ?? '' ) ), 0, 191 ),
				'proposed_completion_date'=> self::sanitize_date( (string) ( $row['proposed_completion_date'] ?? '' ) ),
				'status'                  => 'pending',
				'warning_code'            => ! empty( $row['warning_code'] ) ? substr( sanitize_key( (string) $row['warning_code'] ), 0, 64 ) : null,
				'warning_detail'          => ! empty( $row['warning_detail'] ) ? substr( sanitize_text_field( (string) $row['warning_detail'] ), 0, 255 ) : null,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Persist one indexed source revision as an all-or-nothing unit.
	 *
	 * @param array<int,array<string,mixed>> $document_rows
	 * @param array<string,mixed>            $candidate
	 */
	public static function persist_indexed_revision( array $document_rows, array $candidate ): bool {
		global $wpdb;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return false;
			}
			foreach ( $document_rows as $document_row ) {
				if ( ! is_array( $document_row ) || self::upsert_document( $document_row ) <= 0 ) {
					throw new RuntimeException( 'document_persistence_failed' );
				}
			}
			if ( self::insert_training_candidate( $candidate ) <= 0 ) {
				throw new RuntimeException( 'training_persistence_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit_failed' );
			}
			return true;
		} catch ( Throwable $error ) {
			self::rollback();
			return false;
		}
	}

	/** @return array<string,mixed>|null */
	public static function training( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE id=%d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,string> Existing source fingerprints for one immutable Jotform submission. */
	public static function submission_fingerprints( string $form_id, string $submission_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT source_fingerprint FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE form_id=%s AND submission_id=%s', $form_id, $submission_id ) );
		return array_values( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : array() ), static function ( string $fingerprint ): bool { return (bool) preg_match( '/^[a-f0-9]{64}$/', $fingerprint ); } ) );
	}

	/** Enrich only a previously blank pending identity warning; lifecycle and ownership remain immutable. */
	public static function enrich_identity_warning_detail( string $form_id, string $submission_id, string $fingerprint, string $detail ): bool {
		global $wpdb;
		$detail = substr( sanitize_text_field( $detail ), 0, 255 );
		if ( '' === $detail ) { return false; }
		$table = GHCA_ACD_Jotform_Schema::training_table();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET warning_detail=%s,updated_at=%s WHERE form_id=%s AND submission_id=%s AND source_fingerprint=%s AND status='pending' AND warning_code='identity_mismatch' AND (warning_detail IS NULL OR warning_detail='')", $detail, current_time( 'mysql', true ), $form_id, $submission_id, $fingerprint ) );
		return 1 === (int) $updated;
	}

	public static function confirm_pending_training( int $id, int $employee_id, int $catalog_id, string $completion_date ): bool {
		$date = self::sanitize_date( $completion_date );
		$training = self::training( $id );
		if ( ! $training || 'pending' !== (string) $training['status'] || $employee_id <= 0 || ! get_userdata( $employee_id ) || $catalog_id <= 0 || ! $date || ( (int) $training['employee_user_id'] > 0 && (int) $training['employee_user_id'] !== $employee_id ) ) {
			return false;
		}
		// Capture/preflight still needs the quarantined row. Assignment and warning
		// clearance are committed only by approve() with the immutable evidence.
		return true;
	}

	/** @return array<int,array<string,mixed>> */
	public static function training_for_employee( int $user_id, int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::training_table() . ' WHERE employee_user_id=%d ORDER BY updated_at DESC,id DESC LIMIT %d',
			$user_id, max( 1, min( 50, $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public static function pending_training_page( int $limit = 25, int $offset = 0, string $filter = 'all' ): array {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::training_table();
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$where = "status='pending'";
		if ( 'unsigned' === $filter ) { $where .= " AND employee_user_id=0 AND warning_code IN ('ownership_claim_missing','ownership_claim_invalid','employee_unresolved')"; }
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at ASC,id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int,array<string,mixed>> */
	public static function pending_training( int $limit = 100 ): array { return self::pending_training_page( $limit )['items']; }

	/** @return array<int,array<string,mixed>> */
	public static function active_approved_training( int $limit = 100 ): array {
		global $wpdb;
		$t = GHCA_ACD_Jotform_Schema::training_table();
		$e = GHCA_ACD_Jotform_Schema::evidence_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.*,e.catalog_snapshot,e.approved_at FROM {$t} t INNER JOIN {$e} e ON e.id=t.approved_revision_id WHERE t.status='approved' AND e.status='approved' ORDER BY e.approved_at DESC LIMIT %d", max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function documents_for_employee( int $user_id, int $limit = 3, int $offset = 0 ): array {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::documents_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.* FROM {$table} d WHERE d.employee_user_id=%d AND d.availability='available' AND " . self::current_document_revision_scope( 'd' ) . " ORDER BY d.source_created_at DESC,d.id DESC LIMIT %d OFFSET %d",
			$user_id, max( 1, min( 100, $limit ) ), max( 0, $offset )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function documents_for_training( array $training ): array {
		global $wpdb;
		if ( empty( $training['form_id'] ) || empty( $training['submission_id'] ) || empty( $training['source_fingerprint'] ) ) {
			return array();
		}
		$table = GHCA_ACD_Jotform_Schema::documents_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE form_id=%s AND submission_id=%s AND source_fingerprint=%s AND availability='available' ORDER BY question_id ASC,file_index ASC,id ASC",
			(string) $training['form_id'], (string) $training['submission_id'], (string) $training['source_fingerprint']
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function document_count( int $user_id ): int {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::documents_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} d WHERE d.employee_user_id=%d AND d.availability='available' AND " . self::current_document_revision_scope( 'd' ), $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public static function search_documents_for_employee( int $user_id, string $search, int $limit, int $offset ): array {
		global $wpdb;
		$table          = GHCA_ACD_Jotform_Schema::documents_table();
		$training_table = GHCA_ACD_Jotform_Schema::training_table();
		$limit = max( 1, min( 50, $limit ) );
		$offset = max( 0, $offset );
		$search = trim( $search );
		$where = "d.employee_user_id=%d AND d.availability='available' AND " . self::current_document_revision_scope( 'd' );
		$args = array( $user_id );
		if ( '' !== $search ) {
			$like = $wpdb->esc_like( $search );
			$where .= ' AND (d.file_name LIKE CONCAT(CHAR(37), %s, CHAR(37)) OR d.form_title LIKE CONCAT(CHAR(37), %s, CHAR(37)) OR d.field_label LIKE CONCAT(CHAR(37), %s, CHAR(37)))';
			array_push( $args, $like, $like, $like );
		}
		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} d WHERE {$where}", $args );
		$list_args = array_merge( $args, array( $limit, $offset ) );
		$list_sql = $wpdb->prepare( "SELECT d.*,(SELECT current_revision.status FROM {$training_table} current_revision WHERE current_revision.form_id=d.form_id AND current_revision.submission_id=d.submission_id AND current_revision.source_fingerprint=d.source_fingerprint ORDER BY current_revision.id DESC LIMIT 1) AS review_status FROM {$table} d WHERE {$where} ORDER BY d.source_created_at DESC,d.id DESC LIMIT %d OFFSET %d", $list_args );
		$items = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => (int) $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Limits employee-facing lists to the latest indexed source revision for each submission.
	 * Training candidates are the authoritative revision ledger: unlike document rows,
	 * they are committed for certificate-free revisions and ownership changes.
	 */
	private static function current_document_revision_scope( string $alias ): string {
		$table = GHCA_ACD_Jotform_Schema::training_table();
		return "EXISTS (SELECT 1 FROM {$table} current_revision WHERE current_revision.form_id={$alias}.form_id AND current_revision.submission_id={$alias}.submission_id AND current_revision.source_fingerprint={$alias}.source_fingerprint AND NOT EXISTS (SELECT 1 FROM {$table} newer_revision WHERE newer_revision.form_id={$alias}.form_id AND newer_revision.submission_id={$alias}.submission_id AND newer_revision.id>current_revision.id))";
	}

	/** @return array<string,mixed>|null */
	public static function document( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::documents_table() . ' WHERE id=%d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $document */
	public static function document_has_reviewed_employee_binding( array $document, int $employee_id ): bool {
		global $wpdb;
		if ( $employee_id <= 0 || empty( $document['form_id'] ) || empty( $document['submission_id'] ) || empty( $document['source_fingerprint'] ) ) {
			return false;
		}
		$table = GHCA_ACD_Jotform_Schema::training_table();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE form_id=%s AND submission_id=%s AND source_fingerprint=%s AND employee_user_id=%d AND warning_code IS NULL AND status IN ('pending','approved','superseded','revoked','rejected') LIMIT 1",
			(string) $document['form_id'], (string) $document['submission_id'], (string) $document['source_fingerprint'], $employee_id
		) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function catalog( bool $active_only = false ): array {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::catalog_table();
		$where = $active_only ? ' WHERE active=1' : '';
		$rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY parent_title ASC,title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public static function catalog_item( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::catalog_table() . ' WHERE id=%d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function match_catalog( string $code, string $title ): ?array {
		$result = self::catalog_match_result( $code, $title );
		return 'matched' === $result['status'] ? $result['item'] : null;
	}

	/** @return array{status:string,item:?array} */
	public static function catalog_match_result( string $code, string $title ): array {
		$code_norm  = sanitize_key( $code );
		$title_norm = self::normalize_title( $title );
		$matches    = array();
		foreach ( self::catalog( true ) as $item ) {
			if ( '' !== $code_norm && hash_equals( (string) $item['training_code'], $code_norm ) ) {
				return array( 'status' => 'matched', 'item' => $item );
			}
			$aliases = preg_split( '/[\r\n]+/', (string) ( $item['aliases'] ?? '' ) );
			$candidates = array_merge( array( (string) $item['title'] ), is_array( $aliases ) ? $aliases : array() );
			foreach ( $candidates as $candidate ) {
				if ( '' !== $title_norm && hash_equals( self::normalize_title( (string) $candidate ), $title_norm ) ) {
					$matches[] = $item;
					break;
				}
			}
		}
		if ( 1 === count( $matches ) ) {
			return array( 'status' => 'matched', 'item' => $matches[0] );
		}
		return array( 'status' => count( $matches ) > 1 ? 'ambiguous' : 'unmapped', 'item' => null );
	}

	public static function probable_duplicate_exists( int $employee_id, int $catalog_id, string $completion_date, string $submission_id ): bool {
		global $wpdb;
		$date = self::sanitize_date( $completion_date );
		if ( $employee_id <= 0 || $catalog_id <= 0 || ! $date ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . GHCA_ACD_Jotform_Schema::training_table() . " WHERE employee_user_id=%d AND catalog_id=%d AND proposed_completion_date=%s AND submission_id<>%s AND status IN ('pending','approved') LIMIT 1",
			$employee_id, $catalog_id, $date, $submission_id
		) );
	}

	/** @param array<string,mixed> $data */
	public static function save_catalog_item( array $data, int $actor_id ): int {
		global $wpdb;
		$table = GHCA_ACD_Jotform_Schema::catalog_table();
		$id    = max( 0, (int) ( $data['id'] ?? 0 ) );
		$now   = current_time( 'mysql', true );
		$category = sanitize_key( (string) ( $data['category'] ?? '' ) );
		$categories = class_exists( 'GHCA_Audit_Mapping' ) ? GHCA_Audit_Mapping::get_odp_categories() : array();
		$hours = max( 0, min( 100, round( (float) ( $data['credit_hours'] ?? 0 ), 2 ) ) );
		$code = substr( sanitize_key( (string) ( $data['training_code'] ?? '' ) ), 0, 100 );
		$title = substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 191 );
		$provider = substr( sanitize_text_field( (string) ( $data['provider'] ?? '' ) ), 0, 191 );
		$description = wp_kses_post( (string) ( $data['description'] ?? '' ) );
		$oltl_requirements = class_exists( 'GHCA_ACD_OLTL_Readiness' ) ? GHCA_ACD_OLTL_Readiness::sanitize_requirement_codes( $data['oltl_requirements'] ?? array() ) : array();
		if ( '' === $code || '' === $title || '' === $provider || '' === trim( wp_strip_all_tags( $description ) ) || $hours <= 0 || ! array_key_exists( $category, $categories ) ) {
			return 0;
		}
		$row   = array(
			'training_code' => $code,
			'parent_title'  => substr( sanitize_text_field( (string) ( $data['parent_title'] ?? '' ) ), 0, 191 ),
			'title'         => $title,
			'aliases'       => sanitize_textarea_field( (string) ( $data['aliases'] ?? '' ) ),
			'provider'      => $provider,
			'description'   => $description,
			'category'      => $category,
			'oltl_requirements' => wp_json_encode( $oltl_requirements ),
			'credit_hours'  => $hours,
			'active'        => empty( $data['active'] ) ? 0 : 1,
			'updated_by'    => $actor_id,
			'updated_at'    => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_by'] = $actor_id;
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $snapshot @param array<int,array<string,mixed>> $manifest */
	public static function approve( int $training_id, array $snapshot, array $manifest, int $actor_id, int $employee_id = 0, string $completion_date = '', string $approval_event_error = '' ): int {
		global $wpdb;
		$evidence_table = GHCA_ACD_Jotform_Schema::evidence_table();
		$training_table = GHCA_ACD_Jotform_Schema::training_table();
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT form_id,submission_id FROM {$training_table} WHERE id=%d", $training_id ), ARRAY_A );
		if ( ! is_array( $identity ) || '' === (string) $identity['form_id'] || '' === (string) $identity['submission_id'] ) {
			return 0;
		}
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return 0;
			}
			$locked_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$training_table} WHERE form_id=%s AND submission_id=%s ORDER BY id FOR UPDATE",
				(string) $identity['form_id'], (string) $identity['submission_id']
			), ARRAY_A );
			if ( ! is_array( $locked_rows ) ) {
				throw new RuntimeException( 'approval_lock_read_failed' );
			}
			$training = null;
			$old_training_rows = array();
			foreach ( $locked_rows as $locked_row ) {
				if ( $training_id === (int) $locked_row['id'] ) {
					$training = $locked_row;
				} elseif ( 'approved' === (string) $locked_row['status'] ) {
					$old_training_rows[] = $locked_row;
				}
			}
			if ( ! is_array( $training ) ) {
				throw new RuntimeException( 'approval_target_missing_after_lock' );
			}
			$employee_id = $employee_id > 0 ? $employee_id : (int) $training['employee_user_id'];
			$date = self::sanitize_date( '' !== $completion_date ? $completion_date : (string) $training['proposed_completion_date'] );
			$snapshot_date = self::sanitize_date( (string) ( $snapshot['completion_date'] ?? '' ) );
			if ( 'pending' !== (string) $training['status'] || $employee_id <= 0 || ! get_userdata( $employee_id ) || ! $date || $snapshot_date !== $date || empty( $snapshot['catalog_id'] ) || ( (int) $training['employee_user_id'] > 0 && (int) $training['employee_user_id'] !== $employee_id ) ) {
				throw new RuntimeException( 'approval_invariant_failed' );
			}
			$snapshot['completion_date'] = $date;
			$revision_max = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(revision_no),0) FROM {$evidence_table} WHERE training_id=%d FOR UPDATE", $training_id ) );
			if ( null === $revision_max ) {
				throw new RuntimeException( 'revision_read_failed' );
			}
			$revision = 1 + (int) $revision_max;
			foreach ( $old_training_rows as $old ) {
				if ( empty( $old['approved_revision_id'] ) ) {
					throw new RuntimeException( 'supersession_revision_missing' );
				}
			}
			$now = current_time( 'mysql', true );
			$snapshot_json = wp_json_encode( $snapshot );
			$manifest_json = wp_json_encode( $manifest );
			if ( ! is_string( $snapshot_json ) || ! is_string( $manifest_json ) ) {
				throw new RuntimeException( 'approval_serialization_failed' );
			}
			$inserted_evidence = $wpdb->insert( $evidence_table, array(
				'training_id'       => $training_id,
				'revision_no'       => $revision,
				'status'            => 'approved',
				'catalog_snapshot'  => $snapshot_json,
				'evidence_manifest' => $manifest_json,
				'snapshot_digest'   => hash( 'sha256', $snapshot_json . "\n" . $manifest_json ),
				'approved_by'       => $actor_id,
				'approved_at'       => $now,
			) );
			$revision_id = (int) $wpdb->insert_id;
			if ( 1 !== (int) $inserted_evidence || $revision_id <= 0 ) {
				throw new RuntimeException( 'evidence_insert_failed' );
			}
			foreach ( $old_training_rows as $old ) {
				if ( 1 !== (int) $wpdb->update( $evidence_table, array( 'status' => 'superseded' ), array( 'id' => (int) $old['approved_revision_id'], 'status' => 'approved' ) ) ) {
					throw new RuntimeException( 'evidence_supersession_failed' );
				}
				if ( 1 !== (int) $wpdb->update( $training_table, array( 'status' => 'superseded', 'updated_at' => $now ), array( 'id' => (int) $old['id'], 'status' => 'approved', 'approved_revision_id' => (int) $old['approved_revision_id'] ) ) ) {
					throw new RuntimeException( 'training_supersession_failed' );
				}
			}
			$documents_updated = $wpdb->update( GHCA_ACD_Jotform_Schema::documents_table(), array( 'employee_user_id' => $employee_id, 'updated_at' => $now ), array( 'form_id' => (string) $training['form_id'], 'submission_id' => (string) $training['submission_id'], 'source_fingerprint' => (string) $training['source_fingerprint'] ) );
			$updated = $wpdb->update( $training_table, array( 'employee_user_id' => $employee_id, 'status' => 'approved', 'catalog_id' => (int) $snapshot['catalog_id'], 'proposed_completion_date' => $date, 'warning_code' => null, 'warning_detail' => null, 'approved_revision_id' => $revision_id, 'updated_at' => $now ), array( 'id' => $training_id, 'status' => 'pending', 'approved_revision_id' => null, 'employee_user_id' => (int) $training['employee_user_id'] ) );
			if ( false === $documents_updated || 1 !== (int) $updated ) {
				throw new RuntimeException( 'training_update_failed' );
			}
			foreach ( is_array( $old_training_rows ) ? $old_training_rows : array() as $old ) {
				if ( ! self::event( 'training_superseded', $actor_id, (int) $old['employee_user_id'], array( 'training_id' => (int) $old['id'], 'revision_id' => (int) $old['approved_revision_id'] ) ) ) {
					throw new RuntimeException( 'supersession_event_failed' );
				}
			}
			if ( ! self::event( 'training_approved', $actor_id, $employee_id, array( 'training_id' => $training_id, 'revision_id' => $revision_id ), 'success', $approval_event_error ) ) {
				throw new RuntimeException( 'approval_event_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit_failed' );
			}
			return $revision_id;
		} catch ( Throwable $e ) {
			self::rollback();
			return 0;
		}
	}

	public static function reject_pending_training( int $training_id, int $actor_id = 0, string $reason = '' ): bool {
		global $wpdb;
		$training = self::training( $training_id );
		if ( ! $training || 'pending' !== (string) $training['status'] ) {
			return false;
		}
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
			$updated = $wpdb->update( GHCA_ACD_Jotform_Schema::training_table(), array( 'status' => 'rejected', 'review_reason' => '' === trim( $reason ) ? null : substr( sanitize_textarea_field( $reason ), 0, 1000 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $training_id, 'status' => 'pending', 'approved_revision_id' => null ) );
			if ( 1 !== (int) $updated || ! self::event( 'training_rejected', $actor_id, (int) $training['employee_user_id'], array( 'training_id' => $training_id ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'rejection_failed' );
			}
			return true;
		} catch ( Throwable $e ) { self::rollback(); return false; }
	}

	/** @return array<int,int> IDs rejected; only unsigned pending candidates can be changed. */
	public static function reject_unsigned_pending( int $limit, string $reason, string $cutoff_utc, int $actor_id, string $reason_digest ): array {
		global $wpdb;
		$reason = substr( sanitize_textarea_field( $reason ), 0, 1000 );
		if ( '' === trim( $reason ) ) { return array(); }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff_utc ) ) { return array(); }
		$table = GHCA_ACD_Jotform_Schema::training_table();
		try { $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status='pending' AND employee_user_id=0 AND warning_code IN ('ownership_claim_missing','ownership_claim_invalid','employee_unresolved') AND created_at<=%s ORDER BY created_at ASC,id ASC LIMIT %d", $cutoff_utc, max( 1, min( 100, $limit ) ) ) ); } catch ( Throwable $e ) { return array(); }
		$result = array();
		foreach ( (array) $ids as $id ) { if ( self::reject_unsigned_pending_with_event( (int) $id, $reason, $actor_id, $reason_digest ) ) { $result[] = (int) $id; } }
		return $result;
	}

	public static function reject_unsigned_pending_with_event( int $training_id, string $reason, int $actor_id, string $reason_digest ): bool {
		global $wpdb;
		$reason = substr( sanitize_textarea_field( $reason ), 0, 1000 );
		$training = self::training( $training_id );
		if ( ! $training || 'pending' !== (string) $training['status'] || 0 !== (int) $training['employee_user_id'] || ! in_array( (string) $training['warning_code'], array( 'ownership_claim_missing', 'ownership_claim_invalid', 'employee_unresolved' ), true ) || '' === $reason ) { return false; }
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
			if ( 1 !== (int) $wpdb->update( GHCA_ACD_Jotform_Schema::training_table(), array( 'status' => 'rejected', 'review_reason' => $reason, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $training_id, 'status' => 'pending', 'employee_user_id' => 0 ) ) || ! self::event( 'training_rejected_unsigned', $actor_id, 0, array( 'training_id' => $training_id ), 'success', $reason_digest ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'unsigned_rejection_failed' ); }
			return true;
		} catch ( Throwable $e ) { self::rollback(); return false; }
	}

	public static function revoke_evidence( int $training_id, int $actor_id, string $reason ): bool {
		global $wpdb;
		$training = self::training( $training_id );
		if ( ! $training || 'approved' !== $training['status'] || empty( $training['approved_revision_id'] ) || '' === trim( $reason ) ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
			$ok1 = $wpdb->update( GHCA_ACD_Jotform_Schema::evidence_table(), array( 'status' => 'revoked', 'revoked_by' => $actor_id, 'revoked_at' => $now, 'revocation_reason' => substr( sanitize_textarea_field( $reason ), 0, 1000 ) ), array( 'id' => (int) $training['approved_revision_id'], 'status' => 'approved' ) );
			$ok2 = $wpdb->update( GHCA_ACD_Jotform_Schema::training_table(), array( 'status' => 'revoked', 'updated_at' => $now ), array( 'id' => $training_id, 'status' => 'approved' ) );
			if ( 1 !== (int) $ok1 || 1 !== (int) $ok2 || ! self::event( 'training_revoked', $actor_id, (int) $training['employee_user_id'], array( 'training_id' => $training_id, 'revision_id' => (int) $training['approved_revision_id'] ) ) ) {
				throw new RuntimeException( 'Could not persist evidence revocation.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit evidence revocation.' );
			}
			return true;
		} catch ( Throwable $e ) {
			self::rollback();
			return false;
		}
	}

	/** Append a reviewed applicability revision; never rewrite an approved snapshot. */
	public static function set_orientation_applicability( int $training_id, int $expected_revision, bool $applies, int $actor_id, string $reason ): bool {
		global $wpdb;
		$reason = substr( trim( $reason ), 0, 1000 );
		if ( $actor_id <= 0 || $expected_revision <= 0 || '' === $reason ) { return false; }
		$t = GHCA_ACD_Jotform_Schema::training_table();
		$e = GHCA_ACD_Jotform_Schema::evidence_table();
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
			$training = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d FOR UPDATE", $training_id ), ARRAY_A );
			$revision = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$e} WHERE id=%d FOR UPDATE", $expected_revision ), ARRAY_A );
			if ( ! $training || ! $revision || 'approved' !== $training['status'] || 'approved' !== $revision['status'] || $expected_revision !== (int) $training['approved_revision_id'] || $training_id !== (int) $revision['training_id'] || ! hash_equals( (string) $revision['snapshot_digest'], hash( 'sha256', $revision['catalog_snapshot'] . "\n" . $revision['evidence_manifest'] ) ) ) {
				throw new RuntimeException( 'orientation_review_stale' );
			}
			$snapshot = json_decode( $revision['catalog_snapshot'], true );
			$manifest = json_decode( $revision['evidence_manifest'], true );
			if ( ! is_array( $snapshot ) || ! is_array( $manifest ) || ! $manifest || is_wp_error( GHCA_ACD_External_Evidence_Store::verify_stored_manifest( $training, $manifest ) ) ) {
				throw new RuntimeException( 'orientation_evidence_invalid' );
			}
			$now = current_time( 'mysql', true );
			$snapshot['applies_to_orientation'] = $applies;
			$snapshot['orientation_review'] = array( 'actor_id' => $actor_id, 'reviewed_at' => $now, 'reason' => $reason );
			$json = wp_json_encode( $snapshot );
			$maximum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(revision_no),0) FROM {$e} WHERE training_id=%d FOR UPDATE", $training_id ) );
			if ( ! is_string( $json ) || null === $maximum ) { throw new RuntimeException( 'orientation_snapshot_failed' ); }
			$inserted = $wpdb->insert( $e, array( 'training_id' => $training_id, 'revision_no' => 1 + (int) $maximum, 'status' => 'approved', 'catalog_snapshot' => $json, 'evidence_manifest' => $revision['evidence_manifest'], 'snapshot_digest' => hash( 'sha256', $json . "\n" . $revision['evidence_manifest'] ), 'approved_by' => $actor_id, 'approved_at' => $now ) );
			$new_id = (int) $wpdb->insert_id;
			if ( 1 !== (int) $inserted || $new_id <= 0 || 1 !== (int) $wpdb->update( $e, array( 'status' => 'superseded' ), array( 'id' => $expected_revision, 'status' => 'approved' ) ) || 1 !== (int) $wpdb->update( $t, array( 'approved_revision_id' => $new_id, 'updated_at' => $now ), array( 'id' => $training_id, 'status' => 'approved', 'approved_revision_id' => $expected_revision ) ) || ! self::event( 'training_orientation_reviewed', $actor_id, (int) $training['employee_user_id'], array( 'training_id' => $training_id, 'revision_id' => $new_id ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'orientation_review_failed' );
			}
			return true;
		} catch ( Throwable $error ) { self::rollback(); return false; }
	}

	/** @return array<int,array<string,mixed>> */
	public static function approved_courses( int $user_id ): array {
		global $wpdb;
		$t = GHCA_ACD_Jotform_Schema::training_table();
		$e = GHCA_ACD_Jotform_Schema::evidence_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id AS training_id,t.proposed_completion_date,e.id AS revision_id,e.catalog_snapshot,e.evidence_manifest,e.snapshot_digest
			FROM {$t} t INNER JOIN {$e} e ON e.id=t.approved_revision_id
			WHERE t.employee_user_id=%d AND t.status='approved' AND e.status='approved' ORDER BY t.proposed_completion_date ASC,t.id ASC",
			$user_id
		), ARRAY_A );
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! hash_equals( (string) $row['snapshot_digest'], hash( 'sha256', (string) $row['catalog_snapshot'] . "\n" . (string) $row['evidence_manifest'] ) ) ) {
				continue;
			}
			$snapshot = json_decode( (string) $row['catalog_snapshot'], true );
			$manifest = json_decode( (string) $row['evidence_manifest'], true );
			if ( ! is_array( $snapshot ) || ! is_array( $manifest ) ) {
				continue;
			}
			$row['snapshot'] = $snapshot;
			$row['manifest'] = $manifest;
			$result[] = $row;
		}
		return $result;
	}

	/** @return array<string,mixed>|null */
	public static function evidence_revision( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GHCA_ACD_Jotform_Schema::evidence_table() . ' WHERE id=%d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return string|false */
	public static function event( string $type, int $actor_id, int $employee_id, array $refs = array(), string $outcome = 'success', string $error = '' ) {
		global $wpdb;
		$request_id = bin2hex( random_bytes( 16 ) );
		$event_at   = current_time( 'mysql', true );
		$event_key  = hash( 'sha256', $type . '|' . $actor_id . '|' . $employee_id . '|' . wp_json_encode( $refs ) . '|' . $event_at . '|' . $request_id );
		try {
			$inserted = $wpdb->insert( GHCA_ACD_Jotform_Schema::events_table(), array(
				'event_key'           => $event_key,
				'event_type'          => substr( sanitize_key( $type ), 0, 40 ),
				'actor_user_id'       => $actor_id,
				'employee_user_id'    => $employee_id,
				'document_id'         => ! empty( $refs['document_id'] ) ? (int) $refs['document_id'] : null,
				'training_id'         => ! empty( $refs['training_id'] ) ? (int) $refs['training_id'] : null,
				'evidence_revision_id'=> ! empty( $refs['revision_id'] ) ? (int) $refs['revision_id'] : null,
				'request_id'          => $request_id,
				'outcome'             => in_array( $outcome, array( 'success', 'denied', 'failed' ), true ) ? $outcome : 'failed',
				'error_code'          => '' !== $error ? substr( sanitize_key( $error ), 0, 64 ) : null,
				'event_at'            => $event_at,
			) );
		} catch ( Throwable $e ) {
			return false;
		}
		return 1 === (int) $inserted ? $request_id : false;
	}

	/** Atomically issue a one-use document grant from the native options table. */
	public static function issue_document_grant( string $token, array $grant ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) || empty( $wpdb->options ) ) { return false; }
		try {
			$payload = wp_json_encode( $grant );
			if ( ! is_string( $payload ) || '' === $payload ) { return false; }
			$value = (string) ( time() + ( 5 * MINUTE_IN_SECONDS ) ) . '|' . $payload;
			return 1 === (int) $wpdb->insert( $wpdb->options, array( 'option_name' => self::GRANT_OPTION_PREFIX . hash( 'sha256', $token ), 'option_value' => $value, 'autoload' => 'no' ) );
		} catch ( Throwable $e ) { return false; }
	}

	/** Compare-and-delete consumes a grant once even when two requests read it concurrently. */
	public static function consume_document_grant( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) || empty( $wpdb->options ) ) { return null; }
		try {
			$name = self::GRANT_OPTION_PREFIX . hash( 'sha256', $token );
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
			if ( ! is_string( $value ) || false === strpos( $value, '|' ) || 1 !== (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $value ) ) ) { return null; }
			list( $expires, $payload ) = explode( '|', $value, 2 );
			$grant = json_decode( $payload, true );
			return ctype_digit( $expires ) && (int) $expires >= time() && is_array( $grant ) ? $grant : null;
		} catch ( Throwable $e ) { return null; }
	}

	public static function normalize_title( string $title ): string {
		$title = strtolower( remove_accents( trim( $title ) ) );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( preg_replace( '/\s+/', ' ', (string) $title ) );
	}

	public static function sanitize_date( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $value;
		}
		if ( preg_match( '#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $value, $m ) && checkdate( (int) $m[1], (int) $m[2], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		}
		$timestamp = strtotime( $value );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : null;
	}

	/** Best-effort cleanup must never turn a failed workflow into a fatal request. */
	private static function rollback(): void {
		global $wpdb;
		try { $wpdb->query( 'ROLLBACK' ); } catch ( Throwable $e ) {}
	}
}
