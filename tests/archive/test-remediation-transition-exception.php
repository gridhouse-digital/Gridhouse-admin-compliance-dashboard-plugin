<?php
require __DIR__ . '/bootstrap.php';

$context = array( 'archive_id' => remediation_id( 'a' ), 'phase' => 'verifying', 'nested' => array( 'safe' => true ) );
$error = new GHCA_ACD_Archive_Transition_Exception( 'verification_blocked', 'Blocked.', $context );
$context['phase'] = 'mutated';
$copy = $error->context();
$copy['nested']['safe'] = false;
archive_check( 'verification_blocked' === $error->reason_code(), 'TRANSITION-EXCEPTION-REASON preserves stable reason code' );
archive_check( array( 'archive_id' => remediation_id( 'a' ), 'phase' => 'verifying', 'nested' => array( 'safe' => true ) ) === $error->context(), 'TRANSITION-EXCEPTION-CONTEXT deeply preserves supplied safe context' );

// T8: the negative-test oracle must itself reject a broad or incorrect expected
// exception class. This probe drives archive_expect_exception() against a broad
// class and against a wrong exact class, confirming each records a failure, then
// restores the global counters so the probe does not pollute the suite result.
global $archive_test_failures, $archive_test_checks;
$oracle_saved_failures = $archive_test_failures;
$oracle_saved_checks   = $archive_test_checks;
ob_start();
archive_expect_exception( static function (): void { throw new InvalidArgumentException( 'probe' ); }, 'oracle broad-class probe', Throwable::class );
$oracle_broad_rejected = ( $archive_test_failures > $oracle_saved_failures );
archive_expect_exception( static function (): void { throw new InvalidArgumentException( 'probe' ); }, 'oracle wrong-class probe', LogicException::class );
$oracle_wrong_rejected = ( $archive_test_failures > $oracle_saved_failures + 1 );
ob_end_clean();
$archive_test_failures = $oracle_saved_failures;
$archive_test_checks   = $oracle_saved_checks;
archive_check( $oracle_broad_rejected, 'T8-ORACLE-BROAD archive_expect_exception rejects a broad Throwable/Exception/Error expectation' );
archive_check( $oracle_wrong_rejected, 'T8-ORACLE-WRONG archive_expect_exception rejects an incorrect exact exception class' );

archive_finish();
