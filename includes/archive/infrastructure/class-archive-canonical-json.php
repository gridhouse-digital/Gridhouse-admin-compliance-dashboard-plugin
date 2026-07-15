<?php

/**
 * ghca-cjson-1 encoder/parser.
 *
 * The parser is intentionally small and strict so duplicate keys and integers
 * outside the supported PHP range are rejected before ordinary json_decode()
 * could collapse or coerce them.
 */
final class GHCA_ACD_Archive_Canonical_JSON {
	const FORMAT_VERSION  = 1;
	const MAX_BYTES       = 1048576;
	const MAX_DEPTH       = 32;
	const MAX_VALUES      = 10000;
	const MAX_STRING_BYTES = 262144;

	/** @var string */
	private $source = '';
	/** @var int */
	private $length = 0;
	/** @var int */
	private $offset = 0;
	/** @var int */
	private $items = 0;

	private function __construct( string $source ) {
		$this->source = $source;
		$this->length = strlen( $source );
	}

	/** @param mixed $value */
	public static function encode( $value ): string {
		$items  = 0;
		$result = self::encode_value( $value, 0, $items );
		if ( strlen( $result ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the byte limit.' );
		}
		return $result;
	}

	/**
	 * Return a deeply detached canonical scalar/array tree.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function detach( $value ) {
		$items = 0;
		$copy  = self::detach_value( $value, 0, $items );
		self::encode( $copy );
		return $copy;
	}

	/** @return mixed */
	public static function decode( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'JSON exceeds the byte limit.' );
		}
		self::assert_utf8( $json, 'JSON input' );
		$parser = new self( $json );
		$value  = $parser->parse_value( 0 );
		$parser->skip_whitespace();
		if ( $parser->offset !== $parser->length ) {
			throw new InvalidArgumentException( 'Unexpected trailing JSON data.' );
		}
		return $value;
	}

	/** @return mixed */
	public static function decode_canonical( string $json ) {
		$value = self::decode( $json );
		if ( self::encode( $value ) !== $json ) {
			throw new InvalidArgumentException( 'Stored JSON is not canonical ghca-cjson-1.' );
		}
		return $value;
	}

	/** @param mixed $value */
	private static function encode_value( $value, int $depth, int &$items ): string {
		if ( $depth > self::MAX_DEPTH ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the depth limit.' );
		}
		$items++;
		if ( $items > self::MAX_VALUES ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the value-count limit.' );
		}
		if ( $value instanceof GHCA_ACD_Archive_Empty_Object ) {
			return '{}';
		}
		if ( $value instanceof GHCA_ACD_Archive_Canonical_Object ) {
			return self::encode_object_members( $value->members(), $depth, $items );
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			throw new InvalidArgumentException( 'Floating-point values are not permitted.' );
		}
		if ( is_string( $value ) ) {
			return self::encode_string( $value );
		}
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				$parts = array();
				foreach ( $value as $item ) {
					$parts[] = self::encode_value( $item, $depth + 1, $items );
				}
				return '[' . implode( ',', $parts ) . ']';
			}
			return self::encode_object_map( $value, $depth, $items );
		}
		throw new InvalidArgumentException( 'Unsupported canonical JSON value type.' );
	}

	/** @param mixed $value @return mixed */
	private static function detach_value( $value, int $depth, int &$items ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the depth limit.' );
		}
		$items++;
		if ( $items > self::MAX_VALUES ) {
			throw new InvalidArgumentException( 'Canonical JSON exceeds the value-count limit.' );
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return $value;
		}
		if ( $value instanceof GHCA_ACD_Archive_Empty_Object ) {
			// The empty-object marker is an immutable singleton; sharing it is safe.
			return $value;
		}
		if ( $value instanceof GHCA_ACD_Archive_Canonical_Object ) {
			// Construction validates and deeply detaches every member. The object is
			// immutable, so sharing avoids recursively copying the same nested tree.
			return $value;
		}
		if ( is_float( $value ) || is_object( $value ) || is_resource( $value ) ) {
			throw new InvalidArgumentException( 'Unsupported canonical JSON value type.' );
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Unsupported canonical JSON value type.' );
		}
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = self::detach_value( $item, $depth + 1, $items );
		}
		return $copy;
	}

	/** @param array<mixed,mixed> $map */
	private static function encode_object_map( array $map, int $depth, int &$items ): string {
		$keys = array_keys( $map );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Canonical object keys must be strings.' );
			}
			self::assert_utf8( $key, 'Object key' );
			self::assert_representable_object_key( $key );
		}
		usort( $keys, static function ( string $left, string $right ): int {
			return strcmp( $left, $right );
		} );
		$parts = array();
		foreach ( $keys as $key ) {
			$parts[] = self::encode_string( $key ) . ':' . self::encode_value( $map[ $key ], $depth + 1, $items );
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	/** @param array<int,array{0:string,1:mixed}> $members */
	private static function encode_object_members( array $members, int $depth, int &$items ): string {
		usort( $members, static function ( array $left, array $right ): int {
			return strcmp( $left[0], $right[0] );
		} );
		$parts = array();
		foreach ( $members as $member ) {
			self::assert_utf8( $member[0], 'Object key' );
			$parts[] = self::encode_string( $member[0] ) . ':' . self::encode_value( $member[1], $depth + 1, $items );
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	private static function encode_string( string $value ): string {
		self::assert_utf8( $value, 'String value' );
		if ( strlen( $value ) > self::MAX_STRING_BYTES ) {
			throw new InvalidArgumentException( 'Canonical JSON string exceeds the byte limit.' );
		}
		$options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_UNESCAPED_LINE_TERMINATORS' ) ) {
			$options |= JSON_UNESCAPED_LINE_TERMINATORS;
		}
		$encoded = json_encode( $value, $options );
		if ( false === $encoded || JSON_ERROR_NONE !== json_last_error() ) {
			throw new InvalidArgumentException( 'String cannot be encoded as canonical JSON.' );
		}
		return $encoded;
	}

	private static function assert_utf8( string $value, string $label ): void {
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( $label . ' is not valid UTF-8.' );
		}
	}

	/**
	 * PHP casts canonical in-range integer strings used as array keys to
	 * integers, so ordinary PHP object maps cannot carry them. Callers that
	 * need such keys use the explicit canonical-object representation.
	 */
	private static function assert_representable_object_key( string $key ): void {
		if ( ! self::is_representable_object_key( $key ) ) {
			throw new InvalidArgumentException( 'JSON object key would be coerced to a PHP integer and cannot round-trip.' );
		}
	}

	private static function is_representable_object_key( string $key ): bool {
		if ( 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $key ) ) {
			return true;
		}
		$negative = '-' === $key[0];
		$digits   = $negative ? substr( $key, 1 ) : $key;
		$limit    = $negative ? ltrim( (string) PHP_INT_MIN, '-' ) : (string) PHP_INT_MAX;
		return strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 );
	}

	/** @param array<mixed,mixed> $value */
	private static function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_item ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}
		return true;
	}

	/** @return mixed */
	private function parse_value( int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new InvalidArgumentException( 'JSON exceeds the depth limit.' );
		}
		$this->items++;
		if ( $this->items > self::MAX_VALUES ) {
			throw new InvalidArgumentException( 'JSON exceeds the value-count limit.' );
		}
		$this->skip_whitespace();
		if ( $this->offset >= $this->length ) {
			throw new InvalidArgumentException( 'Unexpected end of JSON.' );
		}
		$char = $this->source[ $this->offset ];
		if ( '"' === $char ) {
			return $this->parse_string();
		}
		if ( '{' === $char ) {
			return $this->parse_object( $depth + 1 );
		}
		if ( '[' === $char ) {
			return $this->parse_array( $depth + 1 );
		}
		if ( '-' === $char || ( $char >= '0' && $char <= '9' ) ) {
			return $this->parse_integer();
		}
		if ( 0 === substr_compare( $this->source, 'true', $this->offset, 4 ) ) {
			$this->offset += 4;
			return true;
		}
		if ( 0 === substr_compare( $this->source, 'false', $this->offset, 5 ) ) {
			$this->offset += 5;
			return false;
		}
		if ( 0 === substr_compare( $this->source, 'null', $this->offset, 4 ) ) {
			$this->offset += 4;
			return null;
		}
		throw new InvalidArgumentException( 'Invalid JSON value.' );
	}

	private function parse_string(): string {
		$start = $this->offset;
		$this->offset++;
		$escaped = false;
		while ( $this->offset < $this->length ) {
			$char = $this->source[ $this->offset ];
			if ( $escaped ) {
				$escaped = false;
				$this->offset++;
				continue;
			}
			if ( '\\' === $char ) {
				$escaped = true;
				$this->offset++;
				continue;
			}
			if ( '"' === $char ) {
				$this->offset++;
				$token = substr( $this->source, $start, $this->offset - $start );
				$value = json_decode( $token );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_string( $value ) ) {
					throw new InvalidArgumentException( 'Invalid JSON string.' );
				}
				self::assert_utf8( $value, 'Decoded string' );
				if ( strlen( $value ) > self::MAX_STRING_BYTES ) {
					throw new InvalidArgumentException( 'JSON string exceeds the byte limit.' );
				}
				return $value;
			}
			if ( ord( $char ) < 0x20 ) {
				throw new InvalidArgumentException( 'Unescaped JSON control character.' );
			}
			$this->offset++;
		}
		throw new InvalidArgumentException( 'Unterminated JSON string.' );
	}

	/** @return array<string,mixed>|GHCA_ACD_Archive_Empty_Object|GHCA_ACD_Archive_Canonical_Object */
	private function parse_object( int $depth ) {
		$this->offset++;
		$this->skip_whitespace();
		$members = array();
		$seen    = array();
		$requires_explicit_object = false;
		if ( $this->consume( '}' ) ) {
			// A PHP array cannot distinguish {} from []; the explicit empty-object
			// marker preserves the object type so it round-trips as {}, not [].
			return GHCA_ACD_Archive_Empty_Object::instance();
		}
		while ( true ) {
			$this->skip_whitespace();
			if ( $this->offset >= $this->length || '"' !== $this->source[ $this->offset ] ) {
				throw new InvalidArgumentException( 'JSON object key must be a string.' );
			}
			$key = $this->parse_string();
			$identity = "\0" . $key;
			if ( array_key_exists( $identity, $seen ) ) {
				throw new InvalidArgumentException( 'Duplicate JSON object key.' );
			}
			$seen[ $identity ] = true;
			$requires_explicit_object = $requires_explicit_object || ! self::is_representable_object_key( $key );
			$this->skip_whitespace();
			if ( ! $this->consume( ':' ) ) {
				throw new InvalidArgumentException( 'Expected colon after JSON object key.' );
			}
			$members[] = array( $key, $this->parse_value( $depth ) );
			$this->skip_whitespace();
			if ( $this->consume( '}' ) ) {
				if ( $requires_explicit_object ) {
					return GHCA_ACD_Archive_Canonical_Object::from_members( $members );
				}
				$object = array();
				foreach ( $members as $member ) {
					$object[ $member[0] ] = $member[1];
				}
				return $object;
			}
			if ( ! $this->consume( ',' ) ) {
				throw new InvalidArgumentException( 'Expected comma in JSON object.' );
			}
		}
	}

	/** @return array<int,mixed> */
	private function parse_array( int $depth ): array {
		$this->offset++;
		$this->skip_whitespace();
		$values = array();
		if ( $this->consume( ']' ) ) {
			return $values;
		}
		while ( true ) {
			$values[] = $this->parse_value( $depth );
			$this->skip_whitespace();
			if ( $this->consume( ']' ) ) {
				return $values;
			}
			if ( ! $this->consume( ',' ) ) {
				throw new InvalidArgumentException( 'Expected comma in JSON array.' );
			}
		}
	}

	private function parse_integer(): int {
		$start = $this->offset;
		while ( $this->offset < $this->length && false !== strpos( '-+0123456789.eE', $this->source[ $this->offset ] ) ) {
			$this->offset++;
		}
		$token = substr( $this->source, $start, $this->offset - $start );
		if ( 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $token ) ) {
			throw new InvalidArgumentException( 'Only canonical in-range integers are permitted.' );
		}
		$negative = '-' === $token[0];
		$digits   = $negative ? substr( $token, 1 ) : $token;
		$limit    = $negative ? ltrim( (string) PHP_INT_MIN, '-' ) : (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
			throw new InvalidArgumentException( 'Integer is outside the supported PHP range.' );
		}
		return (int) $token;
	}

	private function skip_whitespace(): void {
		while ( $this->offset < $this->length && false !== strpos( " \t\r\n", $this->source[ $this->offset ] ) ) {
			$this->offset++;
		}
	}

	private function consume( string $expected ): bool {
		if ( $this->offset < $this->length && $this->source[ $this->offset ] === $expected ) {
			$this->offset++;
			return true;
		}
		return false;
	}
}
