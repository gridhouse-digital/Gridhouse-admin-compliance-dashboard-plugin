<?php
// FPDI 2.6.9 + TCPDF: normal and multi-update PDFs still import and merge into a packet, the way
// GHCA_Audit_PDF::append_certificate does. Needs TCPDF (LearnDash bundles it, so it is not vendored here):
//   GHCA_TEST_TCPDF_PATH=/path/to/tcpdf.php php tests/test-fpdi-merge-integration.php
// Without it the test prints SKIP and exits 0.
$tcpdf = getenv( 'GHCA_TEST_TCPDF_PATH' );
if ( ! $tcpdf || ! is_file( $tcpdf ) ) { echo "SKIP: set GHCA_TEST_TCPDF_PATH to a tcpdf.php to run the FPDI + TCPDF merge check\n"; exit( 0 ); }
require __DIR__ . '/fpdi-fixtures.php';
require $tcpdf;
require __DIR__ . '/../includes/lib/fpdi/autoload.php';
use setasign\Fpdi\PdfParser\Filter\Flate;

$fails = 0;
function mi_check( bool $ok, string $label ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n"; $ok || $fails++; }

$normal = build_pdf( base_objects() );
$multi  = build_pdf( base_objects() );
$multi  = append_update( $multi, array( 4 => '<< /Length 41 >>' . "\nstream\nBT /F1 12 Tf 10 10 Td (Updated) Tj ET\nendstream" ) );
$multi  = append_update( $multi, array( 4 => '<< /Length 42 >>' . "\nstream\nBT /F1 12 Tf 10 10 Td (Updated2) Tj ET\nendstream" ) );
$dir = sys_get_temp_dir() . '/ghca-fpdi-merge-' . getmypid(); mkdir( $dir, 0700 );
file_put_contents( "$dir/normal.pdf", $normal ); file_put_contents( "$dir/multi.pdf", $multi );

$pdf = new \setasign\Fpdi\Tcpdf\Fpdi( 'P', 'mm', 'LETTER', true, 'UTF-8', false );
$pdf->setPrintHeader( false ); $pdf->setPrintFooter( false );
$pdf->AddPage(); $pdf->Write( 0, 'Cover page' );
$total = 0;
foreach ( array( 'normal', 'multi' ) as $name ) {
	Flate::resetDecodedBudget();
	$count = $pdf->setSourceFile( "$dir/$name.pdf" );
	mi_check( 1 === $count, "$name PDF: setSourceFile reports one page" );
	for ( $page = 1; $page <= $count; $page++ ) {
		$tpl = $pdf->importPage( $page ); $size = $pdf->getTemplateSize( $tpl );
		$pdf->AddPage( $size['width'] > $size['height'] ? 'L' : 'P', array( $size['width'], $size['height'] ) );
		$pdf->useTemplate( $tpl, 0, 0, $size['width'], $size['height'] );
		$total++;
	}
}
$pages = $pdf->getPage();
$out = $pdf->Output( '', 'S' );
mi_check( 3 === $pages && 2 === $total, 'merged packet has the cover plus both imported certificate pages' );
mi_check( 0 === strpos( $out, '%PDF-' ) && strlen( $out ) > 1000, 'merged packet is a PDF' );
file_put_contents( "$dir/out.pdf", $out );
$check = new \setasign\Fpdi\Tcpdf\Fpdi();
mi_check( 3 === $check->setSourceFile( "$dir/out.pdf" ), 'merged packet re-opens with three pages' );

foreach ( glob( "$dir/*.pdf" ) as $f ) { unlink( $f ); }
rmdir( $dir );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
