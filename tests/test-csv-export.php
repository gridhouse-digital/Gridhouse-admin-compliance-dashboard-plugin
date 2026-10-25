<?php
// F-004: every CSV export keeps RFC 4180 field boundaries and neutralises formula prefixes.
// Synthetic data only; no WordPress, network or real records.
require __DIR__ . '/test-audit-date-evidence.php';
require_once __DIR__ . '/../includes/class-csv-writer.php';

$csv_fails = 0;
function csv_check( bool $ok, string $label ): void { global $csv_fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $csv_fails++; }

/** Strict RFC 4180 reader: a closing quote must be followed by a comma, a line end or EOF. @return array<int,array<int,string>>|null null when malformed */
function rfc4180_parse( string $data ): ?array {
	$rows = array(); $row = array(); $field = ''; $quoted = false; $just_closed = false; $at_field_start = true;
	$n = strlen( $data );
	for ( $i = 0; $i < $n; $i++ ) {
		$c = $data[ $i ];
		if ( $quoted ) {
			if ( '"' === $c ) {
				if ( $i + 1 < $n && '"' === $data[ $i + 1 ] ) { $field .= '"'; $i++; } else { $quoted = false; $just_closed = true; }
			} else { $field .= $c; }
			continue;
		}
		if ( $just_closed && ',' !== $c && "\n" !== $c && "\r" !== $c ) { return null; }
		if ( '"' === $c ) {
			if ( ! $at_field_start ) { return null; }
			$quoted = true; $at_field_start = false; continue;
		}
		if ( ',' === $c ) { $row[] = $field; $field = ''; $just_closed = false; $at_field_start = true; continue; }
		if ( "\n" === $c || "\r" === $c ) {
			if ( "\r" === $c && $i + 1 < $n && "\n" === $data[ $i + 1 ] ) { $i++; }
			$row[] = $field; $rows[] = $row; $row = array(); $field = ''; $just_closed = false; $at_field_start = true; continue;
		}
		$field .= $c; $at_field_start = false;
	}
	if ( $quoted ) { return null; }
	if ( '' !== $field || $row || ! $at_field_start ) { $row[] = $field; $rows[] = $row; }
	return $rows;
}
function write_csv( array $rows ): string {
	$h = fopen( 'php://memory', 'w+' );
	foreach ( $rows as $r ) { GHCA_ACD_CSV::write_row( $h, $r ); }
	rewind( $h ); $out = stream_get_contents( $h ); fclose( $h );
	return $out;
}

$prefixes = array( '=', '+', '-', '@', "\t", "\r" );
$tricky = array(
	'Doe\\"', '\\"', '"\\"', '\\\\', '\\', '"', '""', 'a,b', "line1\nline2", "line1\r\nline2", "cr\rcr", 'plain',
	'\\",=1+1', '",=1+1', '=\\"x', '=1+1', '+SUM(A1)', '-2+3', '@cmd', "\t=1", "\r=1", "=\"x\",\"y\"", "=a\nb", ',=1+1', '\\",@x,"', 'ünï=cödé', '',
);
foreach ( $prefixes as $p ) { $tricky[] = $p . 'x'; $tricky[] = $p . '\\",' . $p; }

// Each tricky value, alone and surrounded by neighbours, must round-trip through a strict parser.
$rows = array();
foreach ( $tricky as $i => $value ) { $rows[] = array( 'left', $value, 'right' ); $rows[] = array( $value, $value ); $rows[] = array( $value ); }
$out = write_csv( $rows );
$parsed = rfc4180_parse( $out );
csv_check( null !== $parsed && count( $parsed ) === count( $rows ), 'strict RFC 4180 parser accepts the whole export with the right row count' );
$ok_fields = true; $ok_count = true; $ok_neutral = true;
foreach ( $rows as $r => $row ) {
	$got = $parsed[ $r ] ?? array();
	if ( count( $got ) !== count( $row ) ) { $ok_count = false; continue; }
	foreach ( $row as $c => $orig ) {
		$expected = '' !== $orig && in_array( $orig[0], $prefixes, true ) ? "'" . $orig : $orig;
		if ( $got[ $c ] !== $expected ) { $ok_fields = false; }
		if ( '' !== $got[ $c ] && in_array( $got[ $c ][0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) { $ok_neutral = false; }
	}
}
csv_check( $ok_count, 'field count is preserved for every row (backslash, quote, comma, newline, CR, tab, formula inputs)' );
csv_check( $ok_fields, 'every field round-trips exactly (original value, neutralised only when it starts with = + - @ tab CR)' );
csv_check( $ok_neutral, 'no parsed field starts with a formula character' );
// Neutralisation applies to each original field, not to the row.
$p = rfc4180_parse( write_csv( array( array( 'safe', '=1+1', "x\n=1+1", '\\",=1+1' ) ) ) );
csv_check( array( 'safe', "'=1+1", "x\n=1+1", '\\",=1+1' ) === $p[0], 'formula text is neutralised per field; embedded (non-leading) formula text is untouched' );
// Regression for the reported bypass: with the default escape this row mis-parses.
$h = fopen( 'php://memory', 'w+' ); fputcsv( $h, array( 'Doe\\"', ',=1+1', 'tail' ) ); rewind( $h ); $legacy = rfc4180_parse( stream_get_contents( $h ) );
csv_check( null === $legacy || array( 'Doe\\"', ',=1+1', 'tail' ) !== $legacy[0], 'control: PHP default-escape fputcsv does corrupt this row (the bug being fixed)' );
csv_check( array( array( 'Doe\\"', ",=1+1", 'tail' ) ) === rfc4180_parse( write_csv( array( array( 'Doe\\"', ',=1+1', 'tail' ) ) ) ), 'the same row is intact with the shared writer' );
csv_check( array( array( 'Doe\\"', ",=1+1", 'tail' ) ) === array_map( static function ( $r ) { return str_getcsv( $r, ',', '"', '' ); }, array( rtrim( write_csv( array( array( 'Doe\\"', ',=1+1', 'tail' ) ) ), "\n" ) ) ), 'str_getcsv with escape "" reads it back identically' );

// Both real exporters, end to end, with a hostile employee name.
global $fixture, $mappings;
reset_fixture();
$mappings = array( 900001 => array( 'odp_category' => 'person_centered', 'credit_hours' => 1, 'is_orientation' => 1 ) );
$employees = array( array( 'user_id' => 900001, 'name' => '=Doe\\",@x Last\\",+1"' ) );
foreach ( array( 'annual', 'orientation' ) as $tracker ) {
	$h = fopen( 'php://memory', 'w+' );
	$m = new ReflectionMethod( 'GHCA_Audit_Export', 'generate_' . $tracker . '_csv' ); if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); }
	$m->invoke( null, $h, $employees, $mappings );
	rewind( $h ); $data = stream_get_contents( $h ); fclose( $h );
	$rows = rfc4180_parse( $data );
	csv_check( null !== $rows && 2 === count( $rows ) && count( $rows[0] ) === count( $rows[1] ), "$tracker audit export: strict parse succeeds and the data row has as many fields as the header" );
	csv_check( "'=Doe\\\",@x" === $rows[1][1] && "Last\\\",+1\"" === $rows[1][0], "$tracker audit export: hostile name stays in its own fields" );
	csv_check( "'=Doe\\\",@x" === $rows[1][1], "$tracker audit export: formula-leading first name is neutralised" );
}

// No export may call fputcsv directly (the default escape would come back).
$offenders = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../includes', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( 'php' !== $f->getExtension() || false !== strpos( $f->getPathname(), '/lib/' ) || 'class-csv-writer.php' === $f->getFilename() ) { continue; }
	if ( preg_match( '/\bfputcsv\s*\(/', file_get_contents( $f->getPathname() ) ) ) { $offenders[] = $f->getFilename(); }
}
csv_check( array() === $offenders, 'no plugin file other than the shared writer calls fputcsv directly' . ( $offenders ? ': ' . implode( ', ', $offenders ) : '' ) );
csv_check( 1 === preg_match( "/fputcsv\\( \\\$out, [^;]*, ',', '\"', '' \\);/", file_get_contents( __DIR__ . '/../includes/class-csv-writer.php' ) ), 'the shared writer passes an explicit empty escape character' );

echo $csv_fails ? "\n$csv_fails FAILED\n" : "\nALL PASS\n";
exit( $csv_fails ? 1 : 0 );
