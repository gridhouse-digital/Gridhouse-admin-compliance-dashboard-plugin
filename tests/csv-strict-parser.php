<?php
// Strict RFC 4180 reader and writer helper shared by the CSV export tests. Synthetic data only.

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
