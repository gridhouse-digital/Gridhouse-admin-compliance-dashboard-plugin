<?php
// The compliance export handler (GHCA_ACD_Export::handle_export), run end to end in a child process with synthetic
// employees: output must parse with a strict RFC 4180 reader, keep exact field counts/values, and neutralise every
// formula-leading field including a leading line feed. No WordPress, network or real records.
require __DIR__ . '/csv-strict-parser.php';
$fails = 0;
function h_check( bool $ok, string $label ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $fails++; }

$names = array( 'Plain Person', '=Doe\\", @x', "\n=Lf Name", "Line1\nLine2 Name", '+1 Person', "\t=Tab", "\r=Cr" );
$cmd   = array( PHP_BINARY, '-d', 'display_errors=0', __DIR__ . '/csv-handler-runner.php' );
$proc  = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), array( 'GHCA_CSV_HANDLER_NAMES' => json_encode( $names ) ) ) );
$out   = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
proc_close( $proc );
h_check( '' === trim( $err ) && '' !== $out, 'handler ran to completion and produced output (no error output)' );
$rows = rfc4180_parse( $out );
h_check( null !== $rows && count( $names ) + 1 === count( $rows ), 'strict RFC 4180 parse succeeds; one header row plus one row per employee (' . ( null === $rows ? 'parse failed' : count( $rows ) ) . ' rows)' );
$width = $rows ? count( $rows[0] ) : 0;
$same  = $rows ? array_filter( $rows, static function ( $r ) use ( $width ) { return count( $r ) === $width; } ) : array();
h_check( 10 === $width && $rows && count( $same ) === count( $rows ), 'every row has exactly the 10 header fields' );
$ok_names = true;
foreach ( $names as $i => $name ) {
	$expected = in_array( $name[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ? "'" . $name : $name;
	if ( ( $rows[ $i + 1 ][0] ?? null ) !== $expected ) { $ok_names = false; }
}
h_check( $ok_names, 'employee-name field values are exact (formula-leading names, including a leading LF/TAB/CR, get one apostrophe prefix; embedded line breaks untouched)' );
$r = $rows[1] ?? array();
h_check( "Group\nTwo" === ( $r[2] ?? null ) && "'=1+1" === ( $r[6] ?? null ) && "'\n=2+2" === ( $r[7] ?? null ) && "line1\r\nline2" === ( $r[8] ?? null ) && "'@SUM(A1)" === ( $r[9] ?? null ), 'other columns: embedded breaks exact; =, leading-LF and @ values neutralised' );
$bad = 0;
foreach ( array_slice( $rows ?? array(), 0 ) as $row ) { foreach ( $row as $cell ) { if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) { $bad++; } } }
h_check( 0 === $bad, 'no exported cell begins with a formula character' );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
