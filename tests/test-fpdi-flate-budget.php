<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/lib/fpdi/autoload.php';

use setasign\Fpdi\PdfParser\Filter\Flate;
use setasign\Fpdi\PdfParser\Filter\FlateException;

$flate = new Flate();
Flate::resetDecodedBudget();
$compressed = gzcompress( str_repeat( 'A', 17 * 1024 * 1024 ), 9 );

try {
	$first = $flate->decode( $compressed );
	unset( $first );
	$flate->decode( $compressed );
	echo "FAIL: aggregate decoded certificate budget was not enforced\n";
	exit( 1 );
} catch ( FlateException $exception ) {
	echo "PASS: aggregate decoded certificate budget is enforced\n";
}
