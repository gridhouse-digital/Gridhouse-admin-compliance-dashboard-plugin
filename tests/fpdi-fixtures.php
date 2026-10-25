<?php
// Synthetic PDF builders shared by the FPDI tests. No real documents or employee data.

/** Assemble a PDF from objects. @param array<int,string> $objects body per object number @return array{0:string,1:array<int,int>} */
function pdf_objects( string $header, array $objects ): array {
	$pdf = $header; $offsets = array();
	foreach ( $objects as $n => $body ) { $offsets[ $n ] = strlen( $pdf ); $pdf .= "$n 0 obj\n$body\nendobj\n"; }
	return array( $pdf, $offsets );
}
function xref_section( array $offsets ): string {
	ksort( $offsets ); $out = "xref\n"; $numbers = array_keys( $offsets );
	// one subsection per run of consecutive object numbers
	$i = 0; $out .= '';
	while ( $i < count( $numbers ) ) {
		$j = $i; while ( $j + 1 < count( $numbers ) && $numbers[ $j + 1 ] === $numbers[ $j ] + 1 ) { $j++; }
		$out .= $numbers[ $i ] . ' ' . ( $j - $i + 1 ) . "\n";
		for ( $k = $i; $k <= $j; $k++ ) { $out .= sprintf( "%010d 00000 n \n", $offsets[ $numbers[ $k ] ] ); }
		$i = $j + 1;
	}
	return $out;
}
function base_objects( string $content = 'BT /F1 12 Tf 10 10 Td (Synthetic) Tj ET' ): array {
	return array(
		1 => '<< /Type /Catalog /Pages 2 0 R >>',
		2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
		4 => '<< /Length ' . strlen( $content ) . " >>\nstream\n$content\nendstream",
	);
}
/** Classic single-section PDF; $trailer_extra is appended inside the trailer dictionary ({PREV} = its own xref offset). */
function build_pdf( array $objects, string $trailer_extra = '' ): string {
	list( $pdf, $offsets ) = pdf_objects( "%PDF-1.4\n", $objects );
	$x = strlen( $pdf );
	$offsets = array( 0 => 0 ) + $offsets;
	$section = xref_section( $offsets );
	$section = preg_replace( '/^0000000000 00000 n /m', '0000000000 65535 f ', $section, 1 );
	$trailer_extra = str_replace( '{PREV}', (string) $x, $trailer_extra );
	return $pdf . $section . 'trailer << /Size ' . ( max( array_keys( $objects ) ) + 1 ) . " /Root 1 0 R $trailer_extra >>\nstartxref\n$x\n%%EOF\n";
}
/** Append an incremental update (replacing $changed objects) whose trailer points at the previous xref. */
function append_update( string $pdf, array $changed, ?int $prev = null, string $extra = '' ): string {
	preg_match_all( '/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m ); $prev = $prev ?? (int) $m[1][0];
	list( $body, $offsets ) = pdf_objects( '', $changed );
	$shift = strlen( $pdf ); foreach ( $offsets as $n => $o ) { $offsets[ $n ] = $o + $shift; }
	$x = $shift + strlen( $body );
	return $pdf . $body . xref_section( $offsets ) . 'trailer << /Size 5 /Root 1 0 R /Prev ' . $prev . " $extra >>\nstartxref\n$x\n%%EOF\n";
}

/** A FlateDecode stream object body for $data (already-compressed or garbage bytes). */
function flate_stream_object( string $data ): string {
	return '<< /Length ' . strlen( $data ) . " /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream";
}
/** A PDF whose single page has two content streams (objects 4 and 5). */
function build_two_stream_pdf( string $first, string $second ): string {
	$objects = base_objects();
	$objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents [4 0 R 5 0 R] >>';
	$objects[4] = $first;
	$objects[5] = $second;
	return build_pdf( $objects );
}
