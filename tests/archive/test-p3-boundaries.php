<?php
require_once __DIR__ . '/bootstrap.php';

$plugin_root = dirname( __DIR__, 2 );
$archive_root = $plugin_root . '/includes/archive';
$entrypoint = file_get_contents( $plugin_root . '/gridhouse-admin-compliance-dashboard.php' );

$archive_sources = '';
$archive_paths = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $archive_root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$path = str_replace( '\\', '/', $file->getPathname() );
	$archive_paths[] = $path;
	$archive_sources .= "\n" . file_get_contents( $file->getPathname() );
}

$p3_paths = array(
	$archive_root . '/application/class-archive-unit-of-work.php',
	$archive_root . '/application/class-archive-worker-coordinator.php',
	$archive_root . '/application/class-archive-orphan-reconciler.php',
	$archive_root . '/contracts/interface-archive-artifact-store.php',
	$archive_root . '/infrastructure/class-archive-artifact-store-exception.php',
	$archive_root . '/infrastructure/class-private-archive-artifact-store.php',
	$archive_root . '/infrastructure/class-wpdb-archive-task-store.php',
	$archive_root . '/infrastructure/class-archive-canonical-json.php',
	$archive_root . '/infrastructure/class-archive-digester.php',
);
$p3_sources = '';
foreach ( $p3_paths as $path ) {
	$p3_sources .= "\n" . file_get_contents( $path );
}

archive_check(
	is_string( $entrypoint ) && 0 === preg_match( '/archive/i', $entrypoint ),
	'P3-BOUNDARY-ENTRYPOINT-DARK has no archive reference in the plugin entrypoint'
);
archive_check(
	0 === preg_match( '/\b(?:add_action|add_filter|register_activation_hook|register_deactivation_hook)\s*\(/i', $archive_sources ),
	'P3-BOUNDARY-NO-WORDPRESS-HOOKS adds no hook or activation wiring'
);
archive_check(
	0 === preg_match( '/\b(?:wp_schedule_event|wp_schedule_single_event|wp_next_scheduled|wp_clear_scheduled_hook|as_enqueue_async_action|as_schedule_single_action)\s*\(|\bWP_CLI\s*::/i', $archive_sources ),
	'P3-BOUNDARY-NO-CRON-SCHEDULER-CLI adds no worker wake-up registration'
);
archive_check(
	0 === preg_match( '/\bregister_rest_route\s*\(|(?:^|\/)class-[^\/]*controller\.php$/im', $archive_sources . "\n" . implode( "\n", $archive_paths ) ),
	'P3-BOUNDARY-NO-REST-OR-CONTROLLER adds no REST or controller surface'
);
archive_check(
	0 === preg_match( '/wp-load\.php|wp-config\.php|global\s+\$wpdb|\bDB_(?:NAME|USER|PASSWORD|HOST)\b/i', $archive_sources ),
	'P3-BOUNDARY-NO-CURRENT-SITE-BOOTSTRAP accesses no current-site bootstrap or credentials'
);
archive_check(
	0 === preg_match( '/\bwp_remote_|\bcurl_(?:init|exec|multi)|\bfsockopen\s*\(|\bstream_socket_client\s*\(|https?:\/\//i', $archive_sources ),
	'P3-BOUNDARY-NO-NETWORK adds no network access'
);
archive_check(
	0 === preg_match( '/GHCA_ACD_ARCHIVE_PRIVATE_DIR|\bwp_upload_dir\s*\(|\bWP_CONTENT_DIR\b|\bUPLOADS\b/i', $p3_sources ),
	'P3-BOUNDARY-NO-RUNTIME-STORAGE-CONFIG adds no uploads fallback or configuration loader'
);
archive_check(
	0 === preg_match( '/\b(?:dbDelta|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $p3_sources ),
	'P3-BOUNDARY-NO-SCHEMA-DDL adds no schema mutation'
);
archive_check(
	0 === preg_match( '/\b(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\b[^;]*(?:archive_events|archive_snapshots|archive_artifacts|archive_ledger)/i', $p3_sources ),
	'P3-BOUNDARY-NO-IMMUTABLE-ROW-MUTATION adds no event, snapshot, artifact-descriptor, or ledger-row mutation'
);

$handler_paths = array_values( array_filter( $archive_paths, static function ( string $path ): bool {
	return false !== stripos( basename( $path ), 'handler' );
} ) );
sort( $handler_paths, SORT_STRING );
$allowed_handler_path = str_replace( '\\', '/', $archive_root . '/application/class-archive-ledger-task-handler.php' );
$handler_classes = array();
preg_match_all( '/class\s+(GHCA_ACD_[A-Za-z0-9_]*Handler)\b/', $archive_sources, $handler_class_matches );
if ( isset( $handler_class_matches[1] ) ) {
	$handler_classes = array_values( array_unique( $handler_class_matches[1] ) );
	sort( $handler_classes, SORT_STRING );
}
archive_check(
	array( $allowed_handler_path ) === $handler_paths
		&& array( 'GHCA_ACD_Archive_Ledger_Task_Handler' ) === $handler_classes,
	'P3B1-BOUNDARY-ONLY-LEDGER-HANDLER permits only the approved ledger handler file and class'
);
archive_check(
	0 === preg_match( '/worker-runner|class\s+GHCA_ACD_[A-Za-z0-9_]*Worker_Runner\b|class-[^\r\n\/]*(?:reset|capture|packet|verify)[^\r\n\/]*handler|class\s+GHCA_ACD_[A-Za-z0-9_]*(?:Reset|Capture|Packet|Verify)[A-Za-z0-9_]*Handler\b/i', $archive_sources . "\n" . implode( "\n", $archive_paths ) )
		&& is_string( $entrypoint ) && 0 === preg_match( '/archive/i', $entrypoint ),
	'P3B1-BOUNDARY-NO-DEFERRED-HANDLER-OR-RUNNER keeps reset, capture, packet, verify, runner, and runtime activation paths disabled'
);

archive_finish();
