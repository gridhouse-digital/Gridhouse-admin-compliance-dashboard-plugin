<?php

/**
 * Shared database value formatting for the archive persistence layer.
 *
 * Canonical event documents use UTC `YYYY-MM-DDTHH:MM:SSZ` strings and
 * canonical unsigned-decimal sequence strings (`BIGINT UNSIGNED` exceeds
 * PHP's signed integer range, so sequences are never cast to PHP int).
 * SQL rows use `DATETIME` text; these helpers convert exactly and fail
 * closed on any non-canonical value.
 */
final class GHCA_ACD_Archive_Db_Format {
	const SEQUENCE_LIMIT = '18446744073709551615';
	const MYSQL_DUPLICATE_KEY = 1062;
	const MYSQL_LOCK_TIMEOUT  = 1205;
	const MYSQL_DEADLOCK      = 1213;

	private function __construct() {}

	/** Convert canonical UTC (`...T...Z`) to SQL DATETIME text. */
	public static function utc_to_db( string $utc ): string {
		self::assert_utc( $utc );
		return str_replace( array( 'T', 'Z' ), array( ' ', '' ), $utc );
	}

	/** Convert SQL DATETIME text back to canonical UTC (`...T...Z`). */
	public static function db_to_utc( string $datetime ): string {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $datetime ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'invalid_stored_datetime',
				'A stored archive DATETIME value is not canonical.'
			);
		}
		return str_replace( ' ', 'T', $datetime ) . 'Z';
	}

	/** True when the value is a canonical unsigned decimal within BIGINT UNSIGNED. */
	public static function is_canonical_sequence( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) {
			return false;
		}
		$limit = self::SEQUENCE_LIMIT;
		return strlen( $value ) < strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) <= 0 );
	}

	/** @param mixed $value */
	public static function assert_canonical_sequence( $value, string $label ): void {
		if ( ! self::is_canonical_sequence( $value ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'invalid_sequence_value',
				$label . ' is not a canonical unsigned decimal sequence.'
			);
		}
	}

	/** Lossless string increment of a canonical unsigned decimal sequence. */
	public static function increment_sequence( string $sequence ): string {
		self::assert_canonical_sequence( $sequence, 'Sequence' );
		if ( 0 === strcmp( $sequence, self::SEQUENCE_LIMIT ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
				'sequence_overflow',
				'The stream sequence cannot exceed the BIGINT UNSIGNED range.'
			);
		}
		$digits = str_split( $sequence );
		for ( $index = count( $digits ) - 1; $index >= 0; $index-- ) {
			if ( '9' !== $digits[ $index ] ) {
				$digits[ $index ] = (string) ( (int) $digits[ $index ] + 1 );
				return implode( '', $digits );
			}
			$digits[ $index ] = '0';
		}
		return '1' . implode( '', $digits );
	}

	/** Exact equality of two canonical unsigned decimal sequences. */
	public static function sequences_equal( string $left, string $right ): bool {
		return 0 === strcmp( $left, $right );
	}

	/** True when $left > $right for canonical unsigned decimal sequences. */
	public static function sequence_greater_than( string $left, string $right ): bool {
		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) > strlen( $right );
		}
		return strcmp( $left, $right ) > 0;
	}

	/** @param wpdb|object $db */
	public static function database_error_code( $db ): int {
		try {
			$dbh = $db->dbh;
			if ( $dbh instanceof mysqli ) {
				return (int) mysqli_errno( $dbh );
			}
		} catch ( Throwable $error ) {
			return 0;
		}
		return 0;
	}

	/** @param wpdb|object $db */
	public static function is_duplicate_key_error( $db ): bool {
		return self::MYSQL_DUPLICATE_KEY === self::database_error_code( $db );
	}

	/** @param wpdb|object $db */
	public static function is_retryable_transaction_error( $db ): bool {
		$code = self::database_error_code( $db );
		return self::MYSQL_DEADLOCK === $code || self::MYSQL_LOCK_TIMEOUT === $code;
	}

	private static function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) {
			throw new GHCA_ACD_Archive_Persistence_Exception(
				GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL,
				'invalid_utc_value',
				'A canonical UTC timestamp was expected.'
			);
		}
	}
}
