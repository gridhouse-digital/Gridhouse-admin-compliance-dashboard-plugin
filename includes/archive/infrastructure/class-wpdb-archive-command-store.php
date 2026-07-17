<?php

/**
 * Insert-once command/idempotency receipts (Technical Design Section 9.5).
 *
 * Receipts are transport enforcement, not lifecycle authority. Recognition is
 * receipt-first: the stored receipt is matched purely by command type, dedupe
 * identity, exact canonical idempotency scope, and caller-controlled client
 * intent — server-derived facts are never recomputed to decide a match.
 */
final class GHCA_ACD_WPDB_Archive_Command_Store {
	const RESPONSE_SCHEMA_VERSION = 1;

	/** @var wpdb|object */
	private $db;

	/** @param wpdb|object $db */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return wpdb|object The connection this store writes through. */
	public function database() {
		return $this->db;
	}

	/**
	 * Look up a receipt by dedupe digest. Uses a plain read: inside the unit
	 * of work this runs after the stream row lock is held, so the transaction
	 * read view already includes any concurrently committed first delivery.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_receipt( string $dedupe_digest ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $dedupe_digest ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_dedupe_digest',
				'The dedupe digest must be 64 lowercase hexadecimal characters.'
			);
		}
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->commands_table()} WHERE dedupe_digest = %s",
			$dedupe_digest
		);
		$row = $this->db->get_row( $sql, ARRAY_A );
		if ( '' !== (string) $this->db->last_error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'receipt_lookup_failed',
				'The command receipt lookup failed.'
			);
		}
		return null === $row ? null : $row;
	}

	/**
	 * Compare a stored receipt against the caller-controlled client intent and
	 * canonical idempotency scope. A full match returns the stored stable
	 * response; any divergence under the same dedupe digest is an idempotency
	 * conflict (same key, different intent) and fails closed.
	 *
	 * @param array<string,mixed> $receipt
	 * @param array<string,mixed> $scope_document
	 * @return array<string,mixed> The stored response document.
	 */
	public function match_receipt( array $receipt, GHCA_ACD_Archive_Client_Intent $intent, array $scope_document ): array {
		$scope_json = GHCA_ACD_Archive_Canonical_JSON::encode( $scope_document );
		$matches    = (string) $receipt['command_type'] === $intent->type()
			&& (string) $receipt['idempotency_scope_digest'] === $intent->idempotency_scope_digest()
			&& (string) $receipt['idempotency_key_digest'] === $intent->idempotency_key_digest()
			&& is_string( $receipt['client_intent_digest'] )
			&& hash_equals( (string) $receipt['client_intent_digest'], $intent->client_intent_digest() )
			&& (string) $receipt['idempotency_scope_json'] === $scope_json;
		if ( ! $matches ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_IDEMPOTENCY_CONFLICT,
				'idempotency_conflict',
				'The idempotency identity was already used with different command intent or scope.'
			);
		}
		try {
			$response = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $receipt['response_json'] );
		} catch ( Throwable $error ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'receipt_response_invalid',
				'The stored command response is not canonical.'
			);
		}
		if ( ! is_array( $response ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'receipt_response_invalid',
				'The stored command response is not canonical.'
			);
		}
		return $response;
	}

	/**
	 * Insert-once receipt. A duplicate dedupe digest means a concurrent first
	 * delivery committed after this transaction began; the caller recovers by
	 * rolling back and re-reading the winner's receipt.
	 *
	 * @param array<string,mixed> $receipt
	 */
	public function insert_receipt( array $receipt ): void {
		$expected = array(
			'command_id', 'stream_id', 'command_type', 'command_schema_version',
			'canonical_format_version', 'idempotency_format_version', 'dedupe_digest',
			'idempotency_scope_digest', 'idempotency_scope_json', 'idempotency_key_digest',
			'client_intent_digest', 'command_digest', 'actor_user_id', 'decision',
			'result_code', 'first_stream_sequence', 'last_stream_sequence',
			'first_event_id', 'last_event_id', 'response_schema_version',
			'response_json', 'created_at_gmt',
		);
		$keys     = array_keys( $receipt );
		$sorted   = $expected;
		sort( $keys, SORT_STRING );
		sort( $sorted, SORT_STRING );
		if ( $keys !== $sorted ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_receipt_row',
				'Receipt fields do not match the v1 receipt contract.'
			);
		}
		$row = array();
		$formats = array();
		foreach ( $expected as $column ) {
			$row[ $column ] = $receipt[ $column ];
			$formats[]      = in_array( $column, array( 'command_schema_version', 'canonical_format_version', 'idempotency_format_version', 'response_schema_version' ), true ) ? '%d' : '%s';
		}
		$result = $this->db->insert( $this->commands_table(), $row, $formats );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_IDEMPOTENCY_CONFLICT,
					'receipt_insert_race',
					'A concurrent delivery of this command already recorded its receipt.'
				);
			}
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'receipt_insert_failed',
				'The command receipt could not be inserted.'
			);
		}
	}

	private function commands_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_commands';
	}
}
