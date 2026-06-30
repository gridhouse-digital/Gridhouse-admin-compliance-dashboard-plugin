<?php
require __DIR__ . '/bootstrap.php';

$fails = 0;
function check( bool $cond, string $msg ): void {
  global $fails;
  if ( $cond ) { echo "PASS: $msg\n"; } else { echo "FAIL: $msg\n"; $fails++; }
}

$now = 1000000000;
$day = 86400;

check( GHCA_Course_Lifespans::evaluate( false, 0, 365, 90, $now )['state'] === 'incomplete', 'not completed => incomplete' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 100 * $day, 0, 90, $now )['state'] === 'current', 'completed, no lifespan => current (complete-once)' );
check( GHCA_Course_Lifespans::evaluate( true, 0, 365, 90, $now )['state'] === 'current', 'completed, no timestamp => current (safe default)' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 400 * $day, 365, 90, $now )['state'] === 'expired', 'past lifespan => expired' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 300 * $day, 365, 90, $now )['state'] === 'expiring_soon', 'within warning window => expiring_soon' );
check( GHCA_Course_Lifespans::evaluate( true, $now - 10 * $day, 365, 90, $now )['state'] === 'current', 'far from expiry => current' );

$exp = GHCA_Course_Lifespans::evaluate( true, $now - 10 * $day, 365, 90, $now );
check( $exp['expiration_ts'] === $now + 355 * $day, 'expiration_ts = completed_ts + lifespan' );

check( GHCA_Course_Lifespans::rollup( array( 'current', 'expiring_soon', 'expired' ) ) === 'expired', 'rollup => worst is expired' );
check( GHCA_Course_Lifespans::rollup( array( 'current', 'expiring_soon', 'incomplete' ) ) === 'expiring_soon', 'rollup => expiring_soon' );
check( GHCA_Course_Lifespans::rollup( array( 'current', 'incomplete' ) ) === 'current', 'rollup => current' );

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( $fails === 0 ? 0 : 1 );
