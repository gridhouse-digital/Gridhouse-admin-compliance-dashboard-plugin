<?php

/**
 * Immutable artifact descriptors and ordered ledger items.
 *
 * This repository persists database descriptors only. It never reads or
 * writes artifact bytes and exposes no update or delete operation.
 */
final class GHCA_ACD_WPDB_Archive_Artifact_Repository {
	const ARTIFACT_SCHEMA_VERSION = 1;
	const ITEM_SCHEMA_VERSION = 1;
	const MAX_LEDGER_ITEMS = 10000;

	/** @var wpdb|object */
	private $db;

	/** @param wpdb|object $db */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return wpdb|object */
	public function database() {
		return $this->db;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 * @param array<string,mixed> $binding
	 * @return array<string,mixed>
	 */
	public function insert_descriptor( array $descriptor, array $binding ): array {
		$row = $this->build_descriptor_row( $descriptor, $binding, false );
		$result = $this->db->insert( $this->artifacts_table(), $row, array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
		) );
		if ( false === $result || '' !== (string) $this->db->last_error ) {
			if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
				$stored = $this->find_duplicate_descriptor( $row );
				if ( null !== $stored && $this->rows_equal( $row, $stored ) ) {
					return $stored;
				}
				throw $this->integrity( 'artifact_contradictory_duplicate', 'The immutable artifact identity already exists with different descriptor content.' );
			}
			throw $this->internal( 'artifact_insert_failed', 'The immutable artifact descriptor could not be inserted.' );
		}
		return $this->validate_stored_descriptor( $row );
	}

	/**
	 * @param array<string,mixed> $expected Authoritative event bindings to compare.
	 * @return array<string,mixed>|null
	 */
	public function find_descriptor( string $artifact_id, array $expected = array() ) {
		$this->assert_id( $artifact_id, 'Artifact ID' );
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->artifacts_table()} WHERE artifact_id = %s", $artifact_id ), ARRAY_A );
		$this->assert_no_database_error( 'artifact_lookup_failed' );
		if ( null === $row ) {
			return null;
		}
		$row = $this->validate_stored_descriptor( $row );
		$this->assert_expected_descriptor( $row, $expected );
		return $row;
	}

	/**
	 * Validate the complete ledger batch before its first write, then insert it
	 * in canonical ordinal order.
	 *
	 * @param array<int,array<string,mixed>> $documents
	 * @param array<string,mixed> $binding
	 * @return array<int,array<string,mixed>>
	 */
	public function insert_ledger_items( array $documents, array $binding ): array {
		if ( count( $documents ) > self::MAX_LEDGER_ITEMS ) {
			throw $this->invalid( 'side_ledger_item_count_exceeded', 'The ledger item count exceeds the approved ceiling.' );
		}
		$this->assert_exact_fields( $binding, array(
			'archive_id', 'item_count', 'ledger_artifact_id', 'manifest_digest', 'snapshot_id', 'stream_id',
		), 'ledger_binding_invalid', false );
		if ( ! is_int( $binding['item_count'] ) || $binding['item_count'] !== count( $documents ) ) {
			throw $this->invalid( 'ledger_item_count_mismatch', 'The ledger item batch does not match the materialization event count.' );
		}
		foreach ( array( 'archive_id', 'ledger_artifact_id', 'snapshot_id', 'stream_id' ) as $id_field ) {
			$this->assert_id( $binding[ $id_field ], ucfirst( str_replace( '_', ' ', $id_field ) ) );
		}
		$this->assert_digest( $binding['manifest_digest'], 'Ledger manifest digest' );
		$seen_ordinals = array();
		foreach ( $documents as $expected_ordinal => $document ) {
			if ( ! is_array( $document ) || ! array_key_exists( 'item_ordinal', $document ) || ! is_int( $document['item_ordinal'] ) ) {
				throw $this->invalid( 'ledger_item_schema_invalid', 'Each ledger item must carry one integer ordinal.' );
			}
			if ( isset( $seen_ordinals[ $document['item_ordinal'] ] ) ) {
				throw $this->invalid( 'ledger_duplicate', 'A ledger batch contains a duplicate ordinal.' );
			}
			$seen_ordinals[ $document['item_ordinal'] ] = true;
			if ( $document['item_ordinal'] !== $expected_ordinal ) {
				throw $this->invalid( 'ledger_gap', 'Ledger item ordinals must be contiguous from zero.' );
			}
		}

		$rows = array();
		$item_digests = array();
		foreach ( $documents as $ordinal => $document ) {
			if ( ! is_array( $document ) ) {
				throw $this->invalid( 'ledger_item_schema_invalid', 'Each ledger item must be a canonical v1 document.' );
			}
			$row = $this->build_ledger_item_row( $document, $binding, $ordinal, false );
			$rows[] = $row;
			$item_digests[] = $row['item_digest'];
		}
		if ( ! hash_equals( $binding['manifest_digest'], GHCA_ACD_Archive_Digester::ledger_manifest( $item_digests ) ) ) {
			throw $this->invalid( 'ledger_manifest_digest_mismatch', 'The ordered ledger item digests do not match the materialization manifest.' );
		}

		foreach ( $rows as $row ) {
			$result = $this->db->insert( $this->ledger_items_table(), $row, array(
				'%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
			) );
			if ( false === $result || '' !== (string) $this->db->last_error ) {
				if ( GHCA_ACD_Archive_Db_Format::is_duplicate_key_error( $this->db ) ) {
					throw $this->integrity( 'ledger_duplicate', 'A ledger ordinal was already inserted.' );
				}
				throw $this->internal( 'ledger_item_insert_failed', 'The immutable ledger item could not be inserted.' );
			}
		}
		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	public function load_ledger_items( string $ledger_artifact_id ): array {
		$this->assert_id( $ledger_artifact_id, 'Ledger artifact ID' );
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->ledger_items_table()} WHERE ledger_artifact_id = %s ORDER BY item_ordinal ASC", $ledger_artifact_id ),
			ARRAY_A
		);
		$this->assert_no_database_error( 'ledger_item_load_failed' );
		$validated = array();
		foreach ( $rows as $expected_ordinal => $row ) {
			$row = $this->validate_stored_ledger_item( $row );
			if ( (string) $expected_ordinal !== (string) $row['item_ordinal'] ) {
				throw $this->integrity( 'ledger_gap', 'The retained ledger item ordinals are not contiguous from zero.' );
			}
			$validated[] = $row;
		}
		return $validated;
	}

	/**
	 * @param array<string,mixed> $descriptor
	 * @param array<string,mixed> $binding
	 * @return array<string,mixed>
	 */
	private function build_descriptor_row( array $descriptor, array $binding, bool $retained ): array {
		$this->assert_exact_fields( $descriptor, array(
			'artifact_id', 'artifact_kind', 'artifact_schema_version', 'byte_count', 'content_digest',
			'content_digest_algorithm', 'filename', 'media_type', 'producer_key', 'producer_version',
			'role_key', 'storage_adapter', 'storage_key',
		), 'artifact_descriptor_invalid', $retained );
		$this->assert_exact_fields( $binding, array(
			'archive_id', 'build_attempt_id', 'created_at_gmt', 'snapshot_digest', 'snapshot_id', 'stream_id',
		), 'artifact_binding_invalid', $retained );
		$fail = function ( string $reason, string $message ) use ( $retained ) {
			throw $retained ? $this->integrity( $reason, $message ) : $this->invalid( $reason, $message );
		};
		if ( self::ARTIFACT_SCHEMA_VERSION !== $descriptor['artifact_schema_version'] ) {
			$fail( 'unsupported_artifact_schema_version', 'The artifact descriptor schema version is not supported.' );
		}
		foreach ( array( 'artifact_id', 'archive_id', 'build_attempt_id', 'snapshot_id', 'stream_id' ) as $field ) {
			$value = array_key_exists( $field, $descriptor ) ? $descriptor[ $field ] : $binding[ $field ];
			if ( ! $this->is_id( $value ) ) {
				$fail( 'artifact_identity_invalid', 'The artifact descriptor identity is invalid.' );
			}
		}
		foreach ( array( 'content_digest', 'snapshot_digest' ) as $field ) {
			$value = array_key_exists( $field, $descriptor ) ? $descriptor[ $field ] : $binding[ $field ];
			if ( ! $this->is_digest( $value ) ) {
				$fail( 'artifact_digest_invalid', 'The artifact descriptor digest is invalid.' );
			}
		}
		$kind = $descriptor['artifact_kind'];
		$role = $descriptor['role_key'];
		$media = $descriptor['media_type'];
		$valid_kind = in_array( $kind, array( 'certificate', 'ledger', 'packet' ), true );
		$valid_role = ( 'certificate' === $kind && is_string( $role ) && 1 === preg_match( '/^course:[1-9][0-9]*$/', $role ) )
			|| ( 'ledger' === $kind && 'ledger' === $role )
			|| ( 'packet' === $kind && 'packet' === $role );
		$valid_media = ( 'ledger' === $kind && 'application/json' === $media )
			|| ( in_array( $kind, array( 'certificate', 'packet' ), true ) && 'application/pdf' === $media );
		if ( ! $valid_kind || ! $valid_role || ! $valid_media ) {
			$fail( 'artifact_role_type_invalid', 'The artifact kind, role, and media type binding is invalid.' );
		}
		if ( ! is_string( $descriptor['producer_key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $descriptor['producer_key'] )
			|| ! is_string( $descriptor['producer_version'] ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $descriptor['producer_version'] )
			|| ! is_int( $descriptor['byte_count'] ) || $descriptor['byte_count'] < 1
			|| 'sha256' !== $descriptor['content_digest_algorithm'] ) {
			$fail( 'artifact_descriptor_invalid', 'The artifact producer, byte count, or digest algorithm is invalid.' );
		}
		if ( 'private_local' !== $descriptor['storage_adapter'] || ! $this->valid_storage_key( $descriptor['storage_key'] ) ) {
			$fail( 'artifact_storage_key_invalid', 'The artifact storage locator must be an approved adapter and opaque relative immutable key.' );
		}
		if ( ! is_string( $descriptor['filename'] ) || '' === $descriptor['filename'] || strlen( $descriptor['filename'] ) > 255
			|| 1 === preg_match( '/[\\x00-\\x1f\\\\\/]/', $descriptor['filename'] ) ) {
			$fail( 'artifact_filename_invalid', 'The artifact filename is invalid.' );
		}
		try {
			$created = GHCA_ACD_Archive_Db_Format::utc_to_db( $binding['created_at_gmt'] );
		} catch ( Throwable $error ) {
			$fail( 'artifact_binding_invalid', 'The artifact creation time is invalid.' );
		}
		$dedupe = GHCA_ACD_Archive_Digester::artifact_dedupe( array(
			'archive_id'       => $binding['archive_id'],
			'build_attempt_id' => $binding['build_attempt_id'],
			'artifact_kind'    => $kind,
			'role_key'         => $role,
		) );
		return array(
			'artifact_id'              => $descriptor['artifact_id'],
			'stream_id'                => $binding['stream_id'],
			'archive_id'               => $binding['archive_id'],
			'snapshot_id'              => $binding['snapshot_id'],
			'build_attempt_id'         => $binding['build_attempt_id'],
			'artifact_kind'            => $kind,
			'artifact_schema_version'  => self::ARTIFACT_SCHEMA_VERSION,
			'producer_key'             => $descriptor['producer_key'],
			'producer_version'         => $descriptor['producer_version'],
			'role_key'                 => $role,
			'dedupe_digest'            => $dedupe,
			'storage_adapter'          => $descriptor['storage_adapter'],
			'storage_key'              => $descriptor['storage_key'],
			'filename'                 => $descriptor['filename'],
			'media_type'               => $media,
			'byte_count'               => $descriptor['byte_count'],
			'content_digest_algorithm' => $descriptor['content_digest_algorithm'],
			'content_digest'           => $descriptor['content_digest'],
			'snapshot_digest'          => $binding['snapshot_digest'],
			'created_at_gmt'           => $created,
		);
	}

	/**
	 * @param array<string,mixed> $document
	 * @param array<string,mixed> $binding
	 * @return array<string,mixed>
	 */
	private function build_ledger_item_row( array $document, array $binding, int $ordinal, bool $retained ): array {
		$fields = array(
			'archive_id', 'certificate_artifact_id', 'completed_at_gmt', 'completion_status', 'course_id',
			'course_stable_key', 'course_title', 'employee_user_id', 'item_ordinal', 'item_schema_version',
			'ledger_artifact_id', 'program_key', 'quiz_score_basis_points', 'snapshot_id', 'started_at_gmt',
			'stream_id', 'time_spent_seconds', 'cycle_key',
		);
		$this->assert_exact_fields( $document, $fields, 'ledger_item_schema_invalid', $retained );
		$fail = function ( string $reason, string $message ) use ( $retained ) {
			throw $retained ? $this->integrity( $reason, $message ) : $this->invalid( $reason, $message );
		};
		if ( self::ITEM_SCHEMA_VERSION !== $document['item_schema_version'] ) {
			$fail( 'unsupported_ledger_item_schema_version', 'The ledger item schema version is not supported.' );
		}
		if ( $document['item_ordinal'] !== $ordinal ) {
			$fail( 'ledger_gap', 'Ledger item ordinals must be contiguous from zero.' );
		}
		foreach ( array( 'archive_id', 'ledger_artifact_id', 'snapshot_id', 'stream_id' ) as $field ) {
			if ( ! $this->is_id( $document[ $field ] ) || $document[ $field ] !== $binding[ $field ] ) {
				$fail( 'ledger_snapshot_binding_mismatch', 'The ledger item contradicts its artifact, snapshot, or Archive Case binding.' );
			}
		}
		if ( null !== $document['certificate_artifact_id'] && ! $this->is_id( $document['certificate_artifact_id'] ) ) {
			$fail( 'ledger_item_schema_invalid', 'The ledger certificate artifact identity is invalid.' );
		}
		foreach ( array( 'employee_user_id', 'course_id', 'time_spent_seconds' ) as $decimal ) {
			if ( ! $this->is_unsigned_decimal( $document[ $decimal ] ) ) {
				$fail( 'ledger_item_schema_invalid', 'A ledger unsigned-decimal field is invalid.' );
			}
		}
		if ( ! is_string( $document['program_key'] ) || 1 !== preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $document['program_key'] )
			|| ! is_string( $document['cycle_key'] ) || '' === $document['cycle_key'] || strlen( $document['cycle_key'] ) > 191
			|| ! is_string( $document['course_title'] ) || '' === $document['course_title'] || strlen( $document['course_title'] ) > 255
			|| ( null !== $document['course_stable_key'] && ( ! is_string( $document['course_stable_key'] ) || '' === $document['course_stable_key'] || strlen( $document['course_stable_key'] ) > 191 ) )
			|| ! in_array( $document['completion_status'], array( 'not_started', 'in_progress', 'completed' ), true )
			|| ( null !== $document['quiz_score_basis_points'] && ( ! is_int( $document['quiz_score_basis_points'] ) || $document['quiz_score_basis_points'] < 0 || $document['quiz_score_basis_points'] > 10000 ) ) ) {
			$fail( 'ledger_item_schema_invalid', 'The ledger item evidence values are invalid.' );
		}
		foreach ( array( 'started_at_gmt', 'completed_at_gmt' ) as $time_field ) {
			if ( null !== $document[ $time_field ] ) {
				try {
					GHCA_ACD_Archive_Db_Format::utc_to_db( $document[ $time_field ] );
				} catch ( Throwable $error ) {
					$fail( 'ledger_item_schema_invalid', 'A ledger item timestamp is invalid.' );
				}
			}
		}
		try {
			$json = GHCA_ACD_Archive_Canonical_JSON::encode( $document );
		} catch ( Throwable $error ) {
			$fail( 'ledger_item_canonical_invalid', 'A ledger item is not a valid canonical document.' );
		}
		return array(
			'ledger_artifact_id'       => $document['ledger_artifact_id'],
			'stream_id'                => $document['stream_id'],
			'archive_id'               => $document['archive_id'],
			'snapshot_id'              => $document['snapshot_id'],
			'item_ordinal'             => $ordinal,
			'employee_user_id'         => $document['employee_user_id'],
			'program_key'              => $document['program_key'],
			'cycle_key'                => $document['cycle_key'],
			'cycle_key_digest'         => hash( 'sha256', $document['cycle_key'] ),
			'course_id'                => $document['course_id'],
			'course_stable_key'        => $document['course_stable_key'],
			'course_title'             => $document['course_title'],
			'completion_status'        => $document['completion_status'],
			'started_at_gmt'           => null === $document['started_at_gmt'] ? null : GHCA_ACD_Archive_Db_Format::utc_to_db( $document['started_at_gmt'] ),
			'completed_at_gmt'         => null === $document['completed_at_gmt'] ? null : GHCA_ACD_Archive_Db_Format::utc_to_db( $document['completed_at_gmt'] ),
			'time_spent_seconds'       => $document['time_spent_seconds'],
			'quiz_score_basis_points'  => $document['quiz_score_basis_points'],
			'certificate_artifact_id'  => $document['certificate_artifact_id'],
			'item_digest'              => GHCA_ACD_Archive_Digester::item( $document ),
			'item_schema_version'      => self::ITEM_SCHEMA_VERSION,
			'item_json'                => $json,
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_stored_descriptor( array $row ): array {
		$descriptor = array(
			'artifact_id'              => (string) $row['artifact_id'],
			'artifact_kind'            => (string) $row['artifact_kind'],
			'artifact_schema_version'  => (int) $row['artifact_schema_version'],
			'producer_key'             => (string) $row['producer_key'],
			'producer_version'         => (string) $row['producer_version'],
			'role_key'                 => (string) $row['role_key'],
			'storage_adapter'          => (string) $row['storage_adapter'],
			'storage_key'              => (string) $row['storage_key'],
			'filename'                 => (string) $row['filename'],
			'media_type'               => (string) $row['media_type'],
			'byte_count'               => ctype_digit( (string) $row['byte_count'] ) ? (int) $row['byte_count'] : -1,
			'content_digest_algorithm' => (string) $row['content_digest_algorithm'],
			'content_digest'           => (string) $row['content_digest'],
		);
		$binding = array(
			'stream_id'        => (string) $row['stream_id'],
			'archive_id'       => (string) $row['archive_id'],
			'snapshot_id'      => (string) $row['snapshot_id'],
			'build_attempt_id' => (string) $row['build_attempt_id'],
			'snapshot_digest'  => (string) $row['snapshot_digest'],
			'created_at_gmt'   => GHCA_ACD_Archive_Db_Format::db_to_utc( (string) $row['created_at_gmt'] ),
		);
		$expected = $this->build_descriptor_row( $descriptor, $binding, true );
		if ( ! $this->rows_equal( $expected, $row ) ) {
			throw $this->integrity( 'artifact_retained_binding_mismatch', 'The retained artifact descriptor contradicts its immutable identity or dedupe digest.' );
		}
		return $row;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function validate_stored_ledger_item( array $row ): array {
		if ( '1' !== (string) $row['item_schema_version'] ) {
			throw $this->integrity( 'unsupported_ledger_item_schema_version', 'The retained ledger item schema version is not supported.' );
		}
		try {
			$document = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( (string) $row['item_json'] );
		} catch ( Throwable $error ) {
			throw $this->integrity( 'ledger_item_canonical_invalid', 'The retained ledger item JSON is not canonical.' );
		}
		if ( ! is_array( $document ) ) {
			throw $this->integrity( 'ledger_item_canonical_invalid', 'The retained ledger item JSON is not a document.' );
		}
		$binding = array(
			'archive_id'         => (string) $row['archive_id'],
			'item_count'         => 0,
			'ledger_artifact_id' => (string) $row['ledger_artifact_id'],
			'manifest_digest'    => str_repeat( '0', 64 ),
			'snapshot_id'        => (string) $row['snapshot_id'],
			'stream_id'          => (string) $row['stream_id'],
		);
		$expected = $this->build_ledger_item_row( $document, $binding, (int) $row['item_ordinal'], true );
		$actual = $row;
		unset( $actual['ledger_item_id'] );
		if ( ! $this->rows_equal( $expected, $actual ) ) {
			throw $this->integrity( 'ledger_item_digest_mismatch', 'The retained ledger item contradicts its canonical document or digest.' );
		}
		$row['item_document'] = $document;
		return $row;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $expected */
	private function assert_expected_descriptor( array $row, array $expected ): void {
		$allowed = array( 'archive_id', 'artifact_kind', 'build_attempt_id', 'content_digest', 'snapshot_digest', 'snapshot_id', 'stream_id' );
		foreach ( $expected as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) || ! array_key_exists( $field, $row ) || (string) $row[ $field ] !== (string) $value ) {
				throw $this->integrity( 'artifact_authoritative_binding_mismatch', 'The retained artifact descriptor contradicts its authoritative event binding.' );
			}
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function find_duplicate_descriptor( array $row ) {
		$stored = $this->db->get_row( $this->db->prepare(
			"SELECT * FROM {$this->artifacts_table()} WHERE artifact_id = %s OR dedupe_digest = %s LIMIT 1",
			$row['artifact_id'], $row['dedupe_digest']
		), ARRAY_A );
		$this->assert_no_database_error( 'artifact_duplicate_lookup_failed' );
		return null === $stored ? null : $this->validate_stored_descriptor( $stored );
	}

	/** @param mixed $value */
	private function valid_storage_key( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 512
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)*$/', $value )
			&& false === strpos( $value, '..' ) && false === strpos( $value, '://' );
	}

	/** @param mixed $value */
	private function is_unsigned_decimal( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) {
			return false;
		}
		$maximum = '18446744073709551615';
		return strlen( $value ) < strlen( $maximum )
			|| ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	/** @param mixed $value */
	private function is_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	/** @param mixed $value */
	private function is_digest( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}

	/** @param mixed $value */
	private function assert_id( $value, string $label ): void {
		if ( ! $this->is_id( $value ) ) {
			throw $this->invalid( 'artifact_identity_invalid', $label . ' is invalid.' );
		}
	}

	/** @param mixed $value */
	private function assert_digest( $value, string $label ): void {
		if ( ! $this->is_digest( $value ) ) {
			throw $this->invalid( 'artifact_digest_invalid', $label . ' is invalid.' );
		}
	}

	/** @param array<string,mixed> $value @param array<int,string> $fields */
	private function assert_exact_fields( array $value, array $fields, string $reason, bool $retained ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $fields, SORT_STRING );
		if ( $actual !== $fields ) {
			throw $retained
				? $this->integrity( $reason, 'A retained side-record document does not match its closed v1 contract.' )
				: $this->invalid( $reason, 'A side-record document does not match its closed v1 contract.' );
		}
	}

	/** @param array<string,mixed> $expected @param array<string,mixed> $actual */
	private function rows_equal( array $expected, array $actual ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || (string) $actual[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	private function assert_no_database_error( string $reason ): void {
		if ( '' !== (string) $this->db->last_error ) {
			throw $this->internal( $reason, 'The artifact database operation failed.' );
		}
	}

	private function artifacts_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_artifacts';
	}

	private function ledger_items_table(): string {
		return $this->db->prefix . 'ghca_acd_archive_ledger_items';
	}

	private function invalid( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND, $reason, $message );
	}

	private function integrity( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED, $reason, $message );
	}

	private function internal( string $reason, string $message ): GHCA_ACD_Archive_Persistence_Exception {
		return new GHCA_ACD_Archive_Persistence_Exception( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL, $reason, $message );
	}
}
