<?php
require __DIR__ . '/bootstrap.php';

$at_bytes = 'null' . str_repeat( ' ', GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES - 4 );
archive_check( null === GHCA_ACD_Archive_Canonical_JSON::decode( $at_bytes ), 'CJSON-MAX-BYTES accepts exactly MAX_BYTES' );
archive_expect_exception( static function () use ( $at_bytes ): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( $at_bytes . ' ' );
}, 'CJSON-MAX-BYTES rejects MAX_BYTES plus one', InvalidArgumentException::class );

$depth = null;
for ( $i = 0; $i < GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH; $i++ ) { $depth = array( $depth ); }
archive_check( is_string( GHCA_ACD_Archive_Canonical_JSON::encode( $depth ) ), 'CJSON-MAX-DEPTH accepts the exact depth boundary' );
$too_deep = array( $depth );
archive_expect_exception( static function () use ( $too_deep ): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( $too_deep );
}, 'CJSON-MAX-DEPTH rejects one level beyond the boundary', InvalidArgumentException::class );
$cyclic_value = array();
$cyclic_value['self'] =& $cyclic_value;
archive_expect_exception( static function () use ( &$cyclic_value ): void {
	GHCA_ACD_Archive_Canonical_JSON::detach( $cyclic_value );
}, 'CJSON-DETACH-CYCLE rejects recursive PHP references at the canonical depth boundary', InvalidArgumentException::class );
$nested_canonical_object = true;
for ( $i = 0; $i < GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH; $i++ ) {
	$nested_canonical_object = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( 'level', $nested_canonical_object ) ) );
}
archive_check(
	GHCA_ACD_Archive_Canonical_JSON::detach( $nested_canonical_object ) === $nested_canonical_object
		&& is_string( GHCA_ACD_Archive_Canonical_JSON::encode( $nested_canonical_object ) ),
	'CJSON-NESTED-CANONICAL-MAX-DEPTH immutable nested canonical objects detach without repeated traversal at the exact depth boundary'
);
archive_expect_exception( static function () use ( $nested_canonical_object ): void {
	GHCA_ACD_Archive_Canonical_Object::from_members( array( array( 'overflow', $nested_canonical_object ) ) );
}, 'CJSON-NESTED-CANONICAL-DEPTH-OVERFLOW rejects a nested canonical object beyond the depth boundary', InvalidArgumentException::class );

$at_values = array_fill( 0, GHCA_ACD_Archive_Canonical_JSON::MAX_VALUES - 1, null );
archive_check( is_string( GHCA_ACD_Archive_Canonical_JSON::encode( $at_values ) ), 'CJSON-MAX-VALUES accepts exactly MAX_VALUES including the root' );
$over_values = $at_values;
$over_values[] = null;
archive_expect_exception( static function () use ( $over_values ): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( $over_values );
}, 'CJSON-MAX-VALUES rejects one value beyond the boundary', InvalidArgumentException::class );

$at_string = str_repeat( 'x', GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES );
archive_check( strlen( json_decode( GHCA_ACD_Archive_Canonical_JSON::encode( $at_string ) ) ) === GHCA_ACD_Archive_Canonical_JSON::MAX_STRING_BYTES, 'CJSON-MAX-STRING accepts exactly MAX_STRING_BYTES' );
archive_expect_exception( static function () use ( $at_string ): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( $at_string . 'x' );
}, 'CJSON-MAX-STRING rejects MAX_STRING_BYTES plus one', InvalidArgumentException::class );

archive_check( GHCA_ACD_Archive_Canonical_JSON::decode( (string) PHP_INT_MAX ) === PHP_INT_MAX, 'CJSON-INT-MAX accepts maximum supported integer' );
archive_check( GHCA_ACD_Archive_Canonical_JSON::decode( (string) PHP_INT_MIN ) === PHP_INT_MIN, 'CJSON-INT-MIN accepts minimum supported integer' );
archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( '9223372036854775808' );
}, 'CJSON-INT-OVERFLOW rejects positive out-of-range integer', InvalidArgumentException::class );
archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( '-9223372036854775809' );
}, 'CJSON-INT-UNDERFLOW rejects negative out-of-range integer', InvalidArgumentException::class );

// Empty objects and empty lists are distinct JSON types (TD-07). The explicit
// empty-object marker keeps {} encoding as {} and [] encoding as [].
archive_check( '{}' === GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Empty_Object::instance() ), 'CJSON-EMPTY-OBJECT-MARKER the empty-object marker encodes as {}' );
archive_check( '[]' === GHCA_ACD_Archive_Canonical_JSON::encode( array() ), 'CJSON-EMPTY-ARRAY-IS-LIST an empty PHP array encodes as an empty list []' );
archive_check( '{}' === GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Canonical_JSON::decode_canonical( '{}' ) ), 'CJSON-EMPTY-OBJECT decode preserves the object type and {} round-trips canonically' );
archive_check( '{"outer":{}}' === GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Canonical_JSON::decode_canonical( '{"outer":{}}' ) ), 'CJSON-EMPTY-OBJECT-NESTED a nested empty JSON object round-trips as {} not []' );
archive_check( GHCA_ACD_Archive_Canonical_JSON::decode( '{}' ) instanceof GHCA_ACD_Archive_Empty_Object, 'CJSON-EMPTY-OBJECT-DECODE-TYPE decoding {} yields the explicit empty-object marker, never an empty array' );
archive_check( '[]' === GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Canonical_JSON::decode_canonical( '[]' ) ), 'CJSON-EMPTY-LIST empty list round-trips canonically' );
$numeric_key_object = GHCA_ACD_Archive_Canonical_Object::from_members( array( array( '0', true ) ) );
archive_check(
	'{"0":true}' === GHCA_ACD_Archive_Canonical_JSON::encode( $numeric_key_object ) && '[true]' === GHCA_ACD_Archive_Canonical_JSON::encode( array( true ) ),
	'CJSON-NUMERIC-KEY-OBJECT an explicitly object-valued numeric key cannot silently encode as a list'
);
foreach ( array( '{"0":true}', '{"42":true}', '{"-7":true}' ) as $numeric_key_json ) {
	$decoded_numeric_object = GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $numeric_key_json );
	archive_check(
		$decoded_numeric_object instanceof GHCA_ACD_Archive_Canonical_Object && GHCA_ACD_Archive_Canonical_JSON::encode( $decoded_numeric_object ) === $numeric_key_json,
		'CJSON-NUMERIC-KEY-ROUNDTRIP ' . $numeric_key_json . ' remains an object and round-trips byte-for-byte'
	);
}
$non_coercible_keys = '{"08":true,"9223372036854775808":true}';
archive_check(
	GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $non_coercible_keys ) ) === $non_coercible_keys,
	'CJSON-NUMERIC-KEY-SAFE non-coercible numeric-shaped string keys round-trip byte-for-byte'
);

$composed = "\u{00E9}";
$decomposed = "e\u{0301}";
archive_check( GHCA_ACD_Archive_Canonical_JSON::encode( $composed ) !== GHCA_ACD_Archive_Canonical_JSON::encode( $decomposed ), 'CJSON-UNICODE preserves the non-normalization policy' );
archive_check( '"line\\n\\t\\u0000\\"\\\\"' === GHCA_ACD_Archive_Canonical_JSON::encode( "line\n\t\0\"\\" ), 'CJSON-ESCAPING freezes control and quote escaping' );
archive_check( '{"a":{"a":1,"b":2},"z":0}' === GHCA_ACD_Archive_Canonical_JSON::encode( array( 'z' => 0, 'a' => array( 'b' => 2, 'a' => 1 ) ) ), 'CJSON-NESTED-ORDER recursively sorts object keys' );
archive_check( '[3,1,2]' === GHCA_ACD_Archive_Canonical_JSON::encode( array( 3, 1, 2 ) ), 'CJSON-LIST-ORDER preserves list order' );

archive_finish();
