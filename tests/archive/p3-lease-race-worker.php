<?php
require __DIR__ . '/persistence-bootstrap.php';

if ( 5 !== $argc ) {
	fwrite( STDERR, "invalid worker arguments\n" );
	exit( 2 );
}
while ( microtime( true ) < (float) $argv[4] ) {
	usleep( 1000 );
}
$store = new GHCA_ACD_WPDB_Archive_Task_Store( $wpdb );
$task  = $store->claim_available( $argv[1], $argv[2], $argv[3] );
echo json_encode( array( 'task_id' => null === $task ? null : $task['task_id'] ) );
