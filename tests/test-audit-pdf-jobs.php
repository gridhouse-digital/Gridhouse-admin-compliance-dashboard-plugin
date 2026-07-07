<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-audit-pdf-jobs.php';

$fails = 0;
function check( bool $cond, string $msg ): void {
  global $fails;
  if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
}

// --- job id format ---------------------------------------------------------
check( GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'a1', 16 ) ), '32 hex chars => valid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( 'short' ), 'short id => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( str_repeat( 'g', 32 ) ), 'non-hex => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( '../../etc/passwd' ), 'traversal => invalid' );
check( ! GHCA_Audit_PDF_Jobs::is_valid_job_id( strtoupper( str_repeat( 'a1', 16 ) ) ), 'uppercase => invalid (we only mint lowercase)' );

// --- expiry -----------------------------------------------------------------
$now = 1000000000;
check( ! GHCA_Audit_PDF_Jobs::is_expired( $now - 3599, $now ), '59m59s old => live' );
check( GHCA_Audit_PDF_Jobs::is_expired( $now - 3601, $now ), '1h+1s old => expired' );
check( GHCA_Audit_PDF_Jobs::is_expired( 0, $now ), 'zero created => expired' );

// --- manifest validation ----------------------------------------------------
$good = array( 'owner' => 5, 'user_id' => 9, 'tracker' => 'annual', 'urls' => array( 'http://x/a' ), 'filename' => 'p.pdf', 'created' => $now - 60 );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 5, $now ) === true, 'good manifest => true' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( false, 5, $now ) === 'not_found', 'transient miss (false) => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( array(), 5, $now ) === 'not_found', 'empty array => not_found' );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $good, 6, $now ) === 'owner_mismatch', 'other admin => owner_mismatch' );
$stale = array_merge( $good, array( 'created' => $now - 7200 ) );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $stale, 5, $now ) === 'expired', 'stale => expired' );

// Zero-certificate jobs are legitimate (cover sheet only) — must validate.
$empty_urls = array_merge( $good, array( 'urls' => array() ) );
check( GHCA_Audit_PDF_Jobs::validate_manifest( $empty_urls, 5, $now ) === true, 'zero certs => still valid job' );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
