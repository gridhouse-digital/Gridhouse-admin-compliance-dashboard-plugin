<?php
// Plugin-owned packet-evidence integrity check: GHCA_Audit_PDF::certificate_content_is_decodable().
// FPDI copies a single content stream undecoded and replaces any undecodable stream with a blank page, so the plugin
// verifies every page's content streams itself before a certificate may be merged. Synthetic fixtures only; each
// is checked in an isolated child process (memory + time limited). Runs in the default suite (no TCPDF needed).
// The end-to-end abort through ajax_merge is tests/test-fpdi-corrupt-stream-abort.php (needs TCPDF).
// Known leniency, deliberately not asserted: FPDI decodes a TRUNCATED Flate stream partially instead of failing, so
// the check cannot tell it from a good one without re-implementing FPDI's decoder.
require __DIR__ . '/fpdi-fixtures.php';
const EI_MEMORY_LIMIT = '256M';
const EI_TIME_LIMIT   = 30;

$fails = 0;
function ei_check( bool $ok, string $label ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $fails++; }

$garbage   = "\x78\x9c" . str_repeat( "\xde\xad\xbe\xef", 64 );             // not a valid zlib stream
$valid     = gzcompress( 'BT /F1 12 Tf 10 10 Td (Synthetic) Tj ET', 9 );
$big       = gzcompress( str_repeat( "\0", 33 * 1024 * 1024 ), 9 );         // ~33 KB on disk, 33 MiB decoded (> 32 MiB cap)
$stub      = '<< /Length 3 >>' . "\nstream\nq Q\nendstream";

$single = static function ( string $stream_object ): string { $o = base_objects(); $o[4] = $stream_object; return build_pdf( $o ); };
$multi_update = append_update( append_update( build_pdf( base_objects() ), array( 4 => $stub ) ), array( 4 => $stub ) );
$loop = base_objects(); $loop[2] = '<< /Type /Pages /Kids [2 0 R] /Count 1 >>';
$loop2 = base_objects(); $loop2[2] = '<< /Type /Pages /Kids [5 0 R] /Count 1 >>'; $loop2[5] = '<< /Type /Pages /Parent 2 0 R /Kids [2 0 R] /Count 1 >>';
$a = build_pdf( base_objects(), '/Prev 9999999999' );
preg_match( '/startxref\n(\d+)\n/', $a, $m ); $a_off = (int) $m[1];
$ab = append_update( $a, array( 4 => $stub ), $a_off );
preg_match( '/startxref\n(\d+)\n%%EOF\n$/', $ab, $m );

$cases = array(
	// label => array( pdf bytes, expected )
	'normal valid PDF (plain content stream)'                => array( build_pdf( base_objects() ), 'accepted' ),
	'normal valid PDF (Flate content stream)'                => array( $single( flate_stream_object( $valid ) ), 'accepted' ),
	'normal valid PDF, two content streams'                  => array( build_two_stream_pdf( flate_stream_object( $valid ), $stub ), 'accepted' ),
	'normal multi-update PDF (3 xref sections)'              => array( $multi_update, 'accepted' ),
	'page with no content stream (blank page)'               => array( build_pdf( array( 1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', 3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>' ) ), 'accepted' ),
	'one corrupt content stream'                             => array( $single( flate_stream_object( $garbage ) ), 'rejected' ),
	'one over-limit content stream (33 MiB decoded)'         => array( $single( flate_stream_object( $big ) ), 'rejected' ),
	'two content streams, one corrupt'                       => array( build_two_stream_pdf( flate_stream_object( $garbage ), $stub ), 'rejected' ),
	'two content streams, second one corrupt'                => array( build_two_stream_pdf( $stub, flate_stream_object( $garbage ) ), 'rejected' ),
	'two content streams, one over-limit'                    => array( build_two_stream_pdf( flate_stream_object( $big ), $stub ), 'rejected' ),
	'two content streams, second one over-limit'             => array( build_two_stream_pdf( $stub, flate_stream_object( $big ) ), 'rejected' ),
	'F-005 self-referencing /Prev'                           => array( build_pdf( base_objects(), '/Prev {PREV}' ), 'rejected' ),
	'F-005 A -> B -> A /Prev cycle'                          => array( str_replace( '9999999999', sprintf( '%010d', (int) $m[1] ), $ab ), 'rejected' ),
	'F-005 recursive page tree (Kids -> itself)'             => array( build_pdf( $loop ), 'rejected' ),
	'F-005 recursive page tree (A -> B -> A)'                => array( build_pdf( $loop2 ), 'rejected' ),
	'not a PDF at all'                                       => array( "GIF89a not a pdf\n", 'rejected' ),
);

$dir = sys_get_temp_dir() . '/ghca-evidence-integrity-' . getmypid();
mkdir( $dir, 0700 );
foreach ( $cases as $label => $spec ) {
	$path = $dir . '/f' . md5( $label ) . '.pdf';
	file_put_contents( $path, $spec[0] );
	$cmd  = array( PHP_BINARY, '-d', 'memory_limit=' . EI_MEMORY_LIMIT, '-d', 'display_errors=0', __DIR__ . '/fpdi-parse-runner.php', $path, 'plugin-check' );
	$proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
	$out = ''; $start = microtime( true ); $timed_out = false;
	while ( true ) {
		$out .= stream_get_contents( $pipes[1] ); stream_get_contents( $pipes[2] );
		$status = proc_get_status( $proc );
		if ( ! $status['running'] ) { $out .= stream_get_contents( $pipes[1] ); break; }
		if ( microtime( true ) - $start > EI_TIME_LIMIT ) { $timed_out = true; proc_terminate( $proc, 9 ); break; }
		usleep( 20000 );
	}
	proc_close( $proc );
	$res = json_decode( trim( strrchr( "\n" . trim( $out ), "\n" ) ), true ) ?: array();
	$got = $res['status'] ?? ( $timed_out ? 'timeout' : 'fatal' );
	echo '  [' . $label . '] ' . $got . ' ' . round( microtime( true ) - $start, 2 ) . 's peak=' . ( $res['peak_mb'] ?? '?' ) . "MB\n";
	ei_check( $got === $spec[1], "$label: " . $spec[1] . ' (no fatal, no hang)' );
}
foreach ( glob( $dir . '/*.pdf' ) as $f ) { unlink( $f ); }
rmdir( $dir );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
