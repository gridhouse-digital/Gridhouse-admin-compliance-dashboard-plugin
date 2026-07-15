<?php

final class GHCA_ACD_Archive_Cycle {
	/** @var string */ private $policy_key;
	/** @var int */ private $policy_version;
	/** @var string */ private $start_gmt;
	/** @var string */ private $end_gmt;
	/** @var string */ private $timezone;
	/** @var string */ private $display_label;
	/** @var string */ private $key;

	/** DLA-001: only the approved archive cycle policies may become immutable case identity. */
	const APPROVED_POLICY_KEYS = array( 'calendar_year', 'employee_start_date' );

	public function __construct( string $policy_key, int $policy_version, string $start_gmt, string $end_gmt, string $timezone, string $display_label ) {
		if ( ! in_array( $policy_key, self::APPROVED_POLICY_KEYS, true ) ) {
			throw new InvalidArgumentException( 'Cycle policy key is not an approved archive cycle policy.' );
		}
		if ( $policy_version < 1 ) {
			throw new InvalidArgumentException( 'Cycle policy version must be positive.' );
		}
		self::assert_utc( $start_gmt );
		self::assert_utc( $end_gmt );
		if ( strcmp( $start_gmt, $end_gmt ) >= 0 ) {
			throw new InvalidArgumentException( 'Cycle end must be after cycle start.' );
		}
		if ( 'UTC' !== $timezone && 1 !== preg_match( '/^[A-Za-z_]+\/[A-Za-z0-9_+\/-]+$/', $timezone ) ) {
			throw new InvalidArgumentException( 'Cycle timezone must be an IANA timezone.' );
		}
		try {
			new DateTimeZone( $timezone );
		} catch ( Exception $error ) {
			throw new InvalidArgumentException( 'Cycle timezone is not recognized.' );
		}
		if ( '' === trim( $display_label ) || strlen( $display_label ) > 191 || 1 !== preg_match( '//u', $display_label ) ) {
			throw new InvalidArgumentException( 'Cycle display label is invalid.' );
		}
		$key = 'v1|' . $policy_key . '|' . $policy_version . '|' . $start_gmt . '|' . $end_gmt . '|' . $timezone . '|[)';
		if ( strlen( $key ) > 191 ) {
			throw new InvalidArgumentException( 'Canonical cycle key exceeds storage bounds.' );
		}
		$this->policy_key     = $policy_key;
		$this->policy_version = $policy_version;
		$this->start_gmt      = $start_gmt;
		$this->end_gmt        = $end_gmt;
		$this->timezone       = $timezone;
		$this->display_label  = $display_label;
		$this->key            = $key;
	}

	public function key(): string { return $this->key; }
	public function start_gmt(): string { return $this->start_gmt; }
	public function end_gmt(): string { return $this->end_gmt; }
	public function timezone(): string { return $this->timezone; }

	/** @return array<string,mixed> */
	public function canonical(): array {
		return array(
			'boundary'       => '[)',
			'display_label'  => $this->display_label,
			'end_gmt'        => $this->end_gmt,
			'key'            => $this->key,
			'policy_key'     => $this->policy_key,
			'policy_version' => $this->policy_version,
			'start_gmt'      => $this->start_gmt,
			'timezone'       => $this->timezone,
		);
	}

	private static function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $value ) ) {
			throw new InvalidArgumentException( 'Cycle boundary must be canonical UTC.' );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			throw new InvalidArgumentException( 'Cycle boundary is not a real UTC date.' );
		}
	}
}

