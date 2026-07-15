<?php
require __DIR__ . '/bootstrap.php';

$value = array(
	'zeta'    => 2,
	'alpha'   => 'slash/é',
	'nested'  => array( 'two' => null, 'one' => true ),
	'ordered' => array( 3, 2, 1 ),
);

$golden = '{"alpha":"slash/é","nested":{"one":true,"two":null},"ordered":[3,2,1],"zeta":2}';
archive_check( GHCA_ACD_Archive_Canonical_JSON::encode( $value ) === $golden, 'ghca-cjson-1 golden bytes' );
archive_check( GHCA_ACD_Archive_Canonical_JSON::encode( GHCA_ACD_Archive_Canonical_JSON::decode_canonical( $golden ) ) === $golden, 'canonical bytes round-trip exactly' );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( '{"a":1,"a":2}' );
}, 'duplicate object keys fail closed', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( array( 'ratio' => 1.25 ) );
}, 'floats are rejected', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( '{"n":01}' );
}, 'non-canonical integer syntax is rejected', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::decode( '{"n":9223372036854775808}' );
}, 'integers outside the PHP 64-bit contract are rejected', InvalidArgumentException::class );

archive_expect_exception( static function (): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( array( 'bad' => "\xC3\x28" ) );
}, 'invalid UTF-8 is rejected', InvalidArgumentException::class );

archive_expect_exception( static function () use ( $golden ): void {
	GHCA_ACD_Archive_Canonical_JSON::decode_canonical( "{ \"alpha\":\"slash/é\",\"nested\":{\"one\":true,\"two\":null},\"ordered\":[3,2,1],\"zeta\":2}" );
}, 'non-canonical stored encoding is rejected', InvalidArgumentException::class );

$deep = null;
for ( $i = 0; $i < GHCA_ACD_Archive_Canonical_JSON::MAX_DEPTH + 1; $i++ ) {
	$deep = array( $deep );
}
archive_expect_exception( static function () use ( $deep ): void {
	GHCA_ACD_Archive_Canonical_JSON::encode( $deep );
}, 'excess depth is rejected', InvalidArgumentException::class );

archive_finish();

