<?php

/**
 * Stable persistence failure. Categories are the Technical Design Section 8.4
 * subset owned by the persistence boundary; messages are fixed strings and
 * never contain SQL text, credentials, or driver diagnostics.
 */
final class GHCA_ACD_Archive_Persistence_Exception extends RuntimeException {
	const CATEGORY_INVALID_COMMAND     = 'invalid_command';
	const CATEGORY_STREAM_CONFLICT     = 'stream_conflict';
	const CATEGORY_IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
	const CATEGORY_INTEGRITY_BLOCKED   = 'integrity_blocked';
	const CATEGORY_INTERNAL            = 'internal_persistence_failure';

	/** @var string */
	private $category;
	/** @var string */
	private $reason_code;

	public function __construct( string $category, string $reason_code, string $message ) {
		if ( ! in_array( $category, array(
			self::CATEGORY_INVALID_COMMAND,
			self::CATEGORY_STREAM_CONFLICT,
			self::CATEGORY_IDEMPOTENCY_CONFLICT,
			self::CATEGORY_INTEGRITY_BLOCKED,
			self::CATEGORY_INTERNAL,
		), true ) ) {
			throw new InvalidArgumentException( 'Persistence failure category is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $reason_code ) ) {
			throw new InvalidArgumentException( 'Persistence failure reason code is invalid.' );
		}
		$this->category    = $category;
		$this->reason_code = $reason_code;
		parent::__construct( $message );
	}

	public function category(): string {
		return $this->category;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}
}
