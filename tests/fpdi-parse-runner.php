<?php
// Child process for tests/test-fpdi-malicious-pdf.php and tests/test-fpdi-evidence-integrity.php. Parses one PDF the way the packet builder does
// (cross-reference + page tree + first page content) and prints one JSON line. Not a standalone test.
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || ! is_file( $argv[1] ) ) { exit( 2 ); }
require __DIR__ . '/../includes/lib/fpdi/autoload.php';
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use setasign\Fpdi\PdfParser\Filter\Flate;

register_shutdown_function( static function () use ( &$done ) {
	if ( empty( $done ) ) { $e = error_get_last(); echo json_encode( array( 'status' => 'fatal', 'error' => $e['message'] ?? 'unknown' ) ), "\n"; }
} );
if ( ( $argv[2] ?? '' ) === 'plugin-check' ) {
	// Run the plugin-owned evidence integrity check (GHCA_Audit_PDF::certificate_content_is_decodable).
	define( 'ABSPATH', __DIR__ . '/' );
	require __DIR__ . '/../includes/class-audit-pdf.php';
	$ok = GHCA_Audit_PDF::certificate_content_is_decodable( $argv[1] );
	$done = true;
	echo json_encode( array( 'status' => $ok ? 'accepted' : 'rejected', 'peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ) ) ), "\n";
	exit( 0 );
}
try {
	Flate::resetDecodedBudget();
	$reader = new PdfReader( new PdfParser( StreamReader::createByFile( $argv[1] ) ) );
	$pages = $reader->getPageCount();
	$content = $pages > 0 ? $reader->getPage( 1 )->getContentStream() : '';
	$result = array( 'status' => 'ok', 'pages' => $pages, 'content_bytes' => strlen( $content ) );
} catch ( Throwable $e ) {
	$result = array( 'status' => 'exception', 'class' => get_class( $e ), 'message' => $e->getMessage() );
}
$done = true;
$result['peak_mb'] = round( memory_get_peak_usage( true ) / 1048576, 1 );
echo json_encode( $result ), "\n";
