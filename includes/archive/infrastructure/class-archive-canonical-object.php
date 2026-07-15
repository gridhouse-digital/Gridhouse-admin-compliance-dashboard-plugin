<?php

/**
 * Explicit canonical JSON object whose string keys cannot be represented by a PHP array.
 */
final class GHCA_ACD_Archive_Canonical_Object {
	/** @var array<int,array{0:string,1:mixed}> */
	private $members;

	/** @param array<int,array{0:string,1:mixed}> $members */
	private function __construct( array $members ) {
		$this->members = $members;
	}

	/**
	 * @param array<int,array{0:string,1:mixed}> $members
	 * @return self
	 */
	public static function from_members( array $members ): self {
		$copy = array();
		$seen = array();
		$items = 1;
		foreach ( $members as $member ) {
			if ( ! is_array( $member ) || array_keys( $member ) !== array( 0, 1 ) || ! is_string( $member[0] ) ) {
				throw new InvalidArgumentException( 'Canonical object members must be [string key, value] pairs.' );
			}
			$identity = "\0" . $member[0];
			if ( array_key_exists( $identity, $seen ) ) {
				throw new InvalidArgumentException( 'Canonical object keys must be unique.' );
			}
			$seen[ $identity ] = true;
			$copy[] = array( $member[0], self::copy_value( $member[1], 1, $items ) );
		}
		$object = new self( $copy );
		GHCA_ACD_Archive_Canonical_JSON::encode( $object );
		return $object;
	}

	/** @return array<int,array{0:string,1:mixed}> */
	public function members(): array {
		return $this->members;
	}

	/**
	 * Copy arrays through by-value recursion to break PHP references. Nested
	 * canonical objects are already deeply immutable and can be safely shared.
	 * The final canonical encode validates the complete combined tree.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function copy_value( $value, int $depth, int &$items ) {
		if ( $depth > GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the depth limit.' );
		}
		$items++;
		if ( $items > GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the value-count limit.' );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = self::copy_value( $item, $depth + 1, $items );
		}
		return $copy;
	}
}
