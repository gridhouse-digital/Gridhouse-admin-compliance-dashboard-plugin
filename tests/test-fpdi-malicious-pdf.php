<?php
// F-005: the bundled FPDI (2.6.9) must reject malformed/malicious PDFs cleanly. Each fixture is synthetic and is
// parsed in an isolated child process with a memory limit and a wall-clock limit, so a runaway cannot take the
// test run down. Run: php tests/test-fpdi-malicious-pdf.php
const FIXTURE_MEMORY_LIMIT = '128M';
const FIXTURE_TIME_LIMIT   = 20;

$fails = 0;
function mp_check( bool $ok, string $label ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $fails++; }

require __DIR__ . '/fpdi-fixtures.php';

$fixtures = array();
$fixtures['normal'] = array( build_pdf( base_objects() ), 'ok' );
$multi = build_pdf( base_objects() );
$multi = append_update( $multi, array( 4 => '<< /Length 41 >>' . "\nstream\nBT /F1 12 Tf 10 10 Td (Updated) Tj ET\nendstream" ) );
$multi = append_update( $multi, array( 4 => '<< /Length 41 >>' . "\nstream\nBT /F1 12 Tf 10 10 Td (Updated2) Tj ET\nendstream" ) );
$fixtures['normal multi-update (3 xref sections)'] = array( $multi, 'ok' );
$fixtures['self-referencing /Prev'] = array( build_pdf( base_objects(), '/Prev {PREV}' ), 'reject' );

// A -> B -> A: section A's trailer points at B (patched in at the same width), B points back at A.
$a = build_pdf( base_objects(), '/Prev 9999999999' );
preg_match( '/startxref\n(\d+)\n/', $a, $m ); $a_off = (int) $m[1];
$ab = append_update( $a, array( 4 => '<< /Length 3 >>' . "\nstream\nq Q\nendstream" ), $a_off );
preg_match( '/startxref\n(\d+)\n%%EOF\n$/', $ab, $m ); $b_off = (int) $m[1];
$fixtures['A -> B -> A /Prev cycle'] = array( str_replace( '9999999999', sprintf( '%010d', $b_off ), $ab ), 'reject' );

$chain = build_pdf( base_objects() );
for ( $i = 0; $i < 3000; $i++ ) { $chain = append_update( $chain, array( 4 => '<< /Length 3 >>' . "\nstream\nq Q\nendstream" ) ); }
$fixtures['long acyclic /Prev chain (3000 updates)'] = array( $chain, 'bounded' );

$fixtures['/Prev beyond end of file'] = array( build_pdf( base_objects(), '/Prev 99999999' ), 'reject' );
$fixtures['negative /Prev'] = array( build_pdf( base_objects(), '/Prev -5' ), 'reject' );

// Cross-reference STREAM whose /Prev points at itself (uncompressed stream, /W [1 2 1]).
list( $xs, $xo ) = pdf_objects( "%PDF-1.5\n", base_objects() );
$xref_obj = strlen( $xs );
$entries = pack( 'CnC', 0, 0, 255 ); foreach ( $xo as $off ) { $entries .= pack( 'CnC', 1, $off, 0 ); } $entries .= pack( 'CnC', 1, $xref_obj, 0 );
$xs .= "5 0 obj\n<< /Type /XRef /Size 6 /W [1 2 1] /Root 1 0 R /Prev $xref_obj /Length " . strlen( $entries ) . " >>\nstream\n" . $entries . "\nendstream\nendobj\nstartxref\n$xref_obj\n%%EOF\n";
// (The free FPDI parser cannot read xref streams at all; it must reject this one cleanly rather than loop.)
$fixtures['cross-reference stream with self-referencing /Prev'] = array( $xs, 'reject' );

// Recursive page tree: /Pages is its own kid.
$loop = base_objects(); $loop[2] = '<< /Type /Pages /Kids [2 0 R] /Count 1 >>';
$fixtures['recursive page tree (Kids -> itself)'] = array( build_pdf( $loop ), 'reject' );
$loop2 = base_objects(); $loop2[2] = '<< /Type /Pages /Kids [5 0 R] /Count 1 >>'; $loop2[5] = '<< /Type /Pages /Parent 2 0 R /Kids [2 0 R] /Count 1 >>';
$fixtures['recursive page tree (A -> B -> A)'] = array( build_pdf( $loop2 ), 'reject' );
$parent = base_objects(); $parent[3] = '<< /Type /Page /Parent 3 0 R /Contents 4 0 R >>';
$fixtures['page whose /Parent is itself (inherited attributes)'] = array( build_pdf( $parent ), 'reject_or_ok' );

// (Undecodable / over-limit content streams are NOT a parser-level rejection: FPDI 2.6.8+ ignores them. The plugin's own
// evidence integrity check refuses them - see tests/test-fpdi-evidence-integrity.php.)

$dir = sys_get_temp_dir() . '/ghca-fpdi-fixtures-' . getmypid();
mkdir( $dir, 0700 );
foreach ( $fixtures as $label => $spec ) {
	$path = $dir . '/f' . md5( $label ) . '.pdf';
	file_put_contents( $path, $spec[0] );
	$cmd = array( PHP_BINARY, '-d', 'memory_limit=' . FIXTURE_MEMORY_LIMIT, '-d', 'display_errors=0', __DIR__ . '/fpdi-parse-runner.php', $path );
	$proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
	$out = ''; $start = microtime( true ); $timed_out = false;
	while ( true ) {
		$out .= stream_get_contents( $pipes[1] ); stream_get_contents( $pipes[2] );
		$status = proc_get_status( $proc );
		if ( ! $status['running'] ) { $out .= stream_get_contents( $pipes[1] ); break; }
		if ( microtime( true ) - $start > FIXTURE_TIME_LIMIT ) { $timed_out = true; proc_terminate( $proc, 9 ); break; }
		usleep( 20000 );
	}
	proc_close( $proc ); $elapsed = round( microtime( true ) - $start, 2 );
	$res = json_decode( trim( strrchr( "\n" . trim( $out ), "\n" ) ), true ) ?: array();
	$st = $res['status'] ?? ( $timed_out ? 'timeout' : 'no-result' );
	$detail = ( $res['class'] ?? '' ) . ( isset( $res['message'] ) ? ': ' . $res['message'] : '' );
	echo "  [$label] status=$st elapsed={$elapsed}s peak=" . ( $res['peak_mb'] ?? '?' ) . "MB $detail\n";
	mp_check( ! $timed_out && 'fatal' !== $st && 'no-result' !== $st, "$label: no fatal error, no hang (child exited normally within " . FIXTURE_TIME_LIMIT . 's)' );
	switch ( $spec[1] ) {
		case 'ok': mp_check( 'ok' === $st && 1 === ( $res['pages'] ?? 0 ) && ( $res['content_bytes'] ?? 0 ) > 0, "$label: imports normally (1 page, content readable)" ); break;
		case 'reject': mp_check( 'exception' === $st && 0 === strpos( (string) ( $res['class'] ?? '' ), 'setasign\\Fpdi\\' ), "$label: rejected with a caught FPDI exception" ); break;
		case 'reject_or_ok': mp_check( in_array( $st, array( 'exception', 'ok' ), true ), "$label: handled without a fatal (exception or ok)" ); break;
		case 'bounded': mp_check( in_array( $st, array( 'exception', 'ok' ), true ) && $elapsed < FIXTURE_TIME_LIMIT, "$label: handled within the time and memory bounds (" . $st . ')' ); break;
	}
}
foreach ( glob( $dir . '/*.pdf' ) as $f ) { unlink( $f ); }
rmdir( $dir );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
