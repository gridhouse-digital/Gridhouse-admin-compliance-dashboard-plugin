<?php

final class GHCA_ACD_Archive_Reset_Scope {
	/** @var string */ private $employee_user_id;
	/** @var string */ private $program_key;
	/** @var string */ private $cycle_key;
	/** @var array<int,string> */ private $course_ids;
	/** @var string */ private $digest;

	/** @param array<int,string> $course_ids */
	public function __construct( string $employee_user_id, string $program_key, string $cycle_key, array $course_ids ) {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $employee_user_id ) ) {
			throw new InvalidArgumentException( 'Reset employee ID is invalid.' );
		}
		self::assert_supported_decimal( $employee_user_id, 'Reset employee ID' );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $program_key ) ) {
			throw new InvalidArgumentException( 'Reset program key is invalid.' );
		}
		if ( '' === $cycle_key || strlen( $cycle_key ) > 191 ) {
			throw new InvalidArgumentException( 'Reset cycle key is invalid.' );
		}
		$normalized = array();
		foreach ( $course_ids as $course_id ) {
			if ( ! is_string( $course_id ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $course_id ) ) {
				throw new InvalidArgumentException( 'Reset course IDs must be canonical positive decimals.' );
			}
			self::assert_supported_decimal( $course_id, 'Reset course ID' );
			$normalized[ $course_id ] = $course_id;
		}
		$normalized = array_values( $normalized );
		usort( $normalized, static function ( string $left, string $right ): int {
			$length = strlen( $left ) <=> strlen( $right );
			return 0 !== $length ? $length : strcmp( $left, $right );
		} );
		$this->employee_user_id = $employee_user_id;
		$this->program_key      = $program_key;
		$this->cycle_key        = $cycle_key;
		$this->course_ids       = $normalized;
		$this->digest           = GHCA_ACD_Archive_Digester::digest_document( 'ghca-reset-scope-v1', $this->canonical() );
	}

	/** @return array<string,mixed> */
	public function canonical(): array {
		return array(
			'course_ids'               => $this->course_ids,
			'cycle_key'                => $this->cycle_key,
			'employee_user_id_decimal' => $this->employee_user_id,
			'program_key'              => $this->program_key,
		);
	}

	public function digest(): string { return $this->digest; }

	private static function assert_supported_decimal( string $value, string $label ): void {
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) { throw new InvalidArgumentException( $label . ' exceeds the BIGINT UNSIGNED range.' ); }
	}
}
