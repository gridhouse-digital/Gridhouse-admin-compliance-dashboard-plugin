<?php
/**
 * Guards the vendored FPDI copy against missing files.
 *
 * A single absent class file (CrossReferenceException.php) once turned every
 * compressed-cross-reference PDF into a fatal "Class not found" Error that the
 * capture path reported as the misleading "not a readable PDF". The library is
 * vendored, so nothing else verifies it is complete.
 */

$root = dirname( __DIR__ );
$lib  = $root . '/includes/lib/fpdi';

$fails = 0;
function fpdi_lib_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

fpdi_lib_check( is_dir( $lib ), 'the vendored FPDI library directory exists' );

/** Map every setasign\Fpdi class name to the file the autoloader would require. */
$path_for = static function ( string $class ) use ( $lib ): string {
	return $lib . '/' . str_replace( '\\', '/', substr( $class, strlen( 'setasign\\Fpdi\\' ) ) ) . '.php';
};

$files = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $lib ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$files[] = $file->getPathname();
	}
}
fpdi_lib_check( count( $files ) > 40, 'the vendored library contains its full file set (' . count( $files ) . ' files)' );

/* Every imported setasign\Fpdi class must resolve to a real file. */
$missing = array();
$imports = 0;
foreach ( $files as $file ) {
	$source = (string) file_get_contents( $file );
	if ( ! preg_match_all( '/^use\s+(setasign\\\\Fpdi\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $source, $matches ) ) {
		continue;
	}
	foreach ( $matches[1] as $class ) {
		$imports++;
		$target = $path_for( $class );
		if ( ! is_file( $target ) ) {
			$missing[ $class ] = str_replace( $root . '/', '', $file );
		}
	}
}
fpdi_lib_check( $imports > 50, 'import statements were actually scanned (' . $imports . ' found)' );
fpdi_lib_check(
	empty( $missing ),
	'every imported FPDI class resolves to a file' . ( empty( $missing ) ? '' : ' -- MISSING: ' . implode( '; ', array_map(
		static function ( string $c, string $f ): string { return $c . ' (used by ' . $f . ')'; },
		array_keys( $missing ),
		array_values( $missing )
	) ) )
);

/* The class that regressed, named explicitly so a reviewer sees the history. */
$cross_reference_exception = $path_for( 'setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException' );
fpdi_lib_check( is_file( $cross_reference_exception ), 'CrossReferenceException.php is present (compressed-xref and encrypted PDFs need it)' );
if ( is_file( $cross_reference_exception ) ) {
	$source = (string) file_get_contents( $cross_reference_exception );
	fpdi_lib_check(
		false !== strpos( $source, 'const COMPRESSED_XREF' ) && false !== strpos( $source, 'const ENCRYPTED' ),
		'CrossReferenceException exposes the COMPRESSED_XREF and ENCRYPTED codes the store branches on'
	);
}

/* The capture path must turn those two codes into guidance, not a dead-end message. */
$store = (string) file_get_contents( $root . '/includes/jotform/class-external-evidence-store.php' );
fpdi_lib_check(
	false !== strpos( $store, 'unreadable_pdf_message' ) && false !== strpos( $store, 'CrossReferenceException::COMPRESSED_XREF' ) && false !== strpos( $store, 'CrossReferenceException::ENCRYPTED' ),
	'unreadable PDFs report the specific, actionable cause'
);
fpdi_lib_check(
	false !== strpos( $store, 'Print to PDF' ),
	'the uploader is told how to produce a mergeable copy'
);

echo 0 === $fails ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit( 0 === $fails ? 0 : 1 );
