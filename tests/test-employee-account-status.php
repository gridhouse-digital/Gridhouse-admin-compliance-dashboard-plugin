<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/class-data-provider.php';

$fails = 0;
function check_account_status( bool $condition, string $message ): void {
	global $fails;
	if ( $condition ) {
		echo "PASS: $message\n";
		return;
	}
	echo "FAIL: $message\n";
	++$fails;
}

$partition = new ReflectionMethod( 'GHCA_ACD_Data_Provider', 'partition_employee_records' );
if ( PHP_VERSION_ID < 80100 ) {
	$partition->setAccessible( true );
}
$records = array(
	array( 'user_id' => 1, 'name' => 'Active One' ),
	array( 'user_id' => 2, 'name' => 'Suspended Two' ),
	array( 'user_id' => 3, 'name' => 'Active Three' ),
);

$sets = $partition->invoke( null, $records, array( 2 ) );
check_account_status( array_column( $sets['active'], 'user_id' ) === array( 1, 3 ), 'suspended users are excluded from active employees' );
check_account_status( array_column( $sets['inactive'], 'user_id' ) === array( 2 ), 'suspended users appear in inactive employees' );
check_account_status( false === $sets['active'][0]['is_suspended'] && 'active' === $sets['active'][0]['account_status'], 'active records carry active account metadata' );
check_account_status( true === $sets['inactive'][0]['is_suspended'] && 'inactive' === $sets['inactive'][0]['account_status'], 'inactive records carry suspended account metadata' );

$sets = $partition->invoke( null, $records, array() );
check_account_status( 3 === count( $sets['active'] ) && empty( $sets['inactive'] ), 'no suspended IDs leaves every employee active' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
