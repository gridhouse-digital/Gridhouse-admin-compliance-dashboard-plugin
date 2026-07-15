<?php

final class GHCA_ACD_Archive_Case_Key {
	/** @var string */ private $tenant_id;
	/** @var string */ private $site_id;
	/** @var string */ private $employee_user_id;
	/** @var string */ private $program_key;
	/** @var GHCA_ACD_Archive_Cycle */ private $cycle;
	/** @var string */ private $digest;

	public function __construct( string $tenant_id, string $site_id, string $employee_user_id, string $program_key, GHCA_ACD_Archive_Cycle $cycle ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $tenant_id ) ) {
			throw new InvalidArgumentException( 'Tenant ID is invalid.' );
		}
		self::assert_positive_decimal( $site_id, 'Site ID' );
		self::assert_positive_decimal( $employee_user_id, 'Employee user ID' );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $program_key ) ) {
			throw new InvalidArgumentException( 'Program key is invalid.' );
		}
		$this->tenant_id        = $tenant_id;
		$this->site_id          = $site_id;
		$this->employee_user_id = $employee_user_id;
		$this->program_key      = $program_key;
		$this->cycle            = $cycle;
		$this->digest           = GHCA_ACD_Archive_Digester::case_key( $tenant_id, $site_id, $employee_user_id, $program_key, $cycle->key() );
	}

	public function digest(): string { return $this->digest; }
	public function cycle(): GHCA_ACD_Archive_Cycle { return $this->cycle; }

	/** @return array<string,string> */
	public function canonical(): array {
		return array(
			'cycle_key'                => $this->cycle->key(),
			'employee_user_id_decimal' => $this->employee_user_id,
			'program_key'              => $this->program_key,
			'site_id_decimal'          => $this->site_id,
			'tenant_id'                => $this->tenant_id,
		);
	}

	private static function assert_positive_decimal( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			throw new InvalidArgumentException( $label . ' must be canonical positive decimal.' );
		}
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) { throw new InvalidArgumentException( $label . ' exceeds the BIGINT UNSIGNED range.' ); }
	}
}
