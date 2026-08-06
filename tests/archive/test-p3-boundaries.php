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
	is_string( $entrypoint )
		&& 1 === substr_count( $entrypoint, "require_once __DIR__ . '/includes/archive/bootstrap.php';" )
		&& 1 === preg_match_all( '/archive/i', $entrypoint ),
	'P3B3-ENTRYPOINT-ONE-ARCHIVE-REFERENCE permits only the constructed-dark bootstrap reference in the plugin entrypoint'
);
archive_check(
	0 === preg_match( '/\b(?:add_action|add_filter|register_activation_hook|register_deactivation_hook)\s*\(/i', $archive_sources ),
	'P3-BOUNDARY-NO-WORDPRESS-HOOKS adds no hook or activation wiring'
);
archive_check(
	0 === preg_match( '/\b(?:wp_schedule_event|wp_schedule_single_event|wp_next_scheduled|wp_clear_scheduled_hook|as_enqueue_async_action|as_schedule_single_action)\s*\(/i', $archive_sources )
		&& 0 === substr_count( $archive_sources, 'WP_CLI::add_command' ),
	'P3B3-ONLY-APPROVED-WPCLI-COMMAND-REGISTERED keeps the approved command identifier dormant and unregistered'
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
$allowed_handler_paths = array(
	str_replace( '\\', '/', $archive_root . '/application/class-archive-evidence-task-handler.php' ),
	str_replace( '\\', '/', $archive_root . '/application/class-archive-ledger-task-handler.php' ),
);
sort( $allowed_handler_paths, SORT_STRING );
$handler_classes = array();
preg_match_all( '/class\s+(GHCA_ACD_[A-Za-z0-9_]*Handler)\b/', $archive_sources, $handler_class_matches );
if ( isset( $handler_class_matches[1] ) ) {
	$handler_classes = array_values( array_unique( $handler_class_matches[1] ) );
	sort( $handler_classes, SORT_STRING );
}
$allowed_handler_classes = array(
	'GHCA_ACD_Archive_Evidence_Task_Handler',
	'GHCA_ACD_Archive_Ledger_Task_Handler',
);
sort( $allowed_handler_classes, SORT_STRING );
archive_check(
	$allowed_handler_paths === $handler_paths && $allowed_handler_classes === $handler_classes,
	'P3B2A-BOUNDARY-ONLY-APPROVED-HANDLERS permits only the approved ledger and evidence handler files and classes'
);
archive_check(
	0 === preg_match( '/worker-runner|class\s+GHCA_ACD_[A-Za-z0-9_]*Worker_Runner\b|class-[^\r\n\/]*(?:reset|capture|packet|verify)[^\r\n\/]*handler|class\s+GHCA_ACD_[A-Za-z0-9_]*(?:Reset|Capture|Packet|Verify)[A-Za-z0-9_]*Handler\b/i', $archive_sources . "\n" . implode( "\n", $archive_paths ) )
		&& is_string( $entrypoint )
		&& 1 === substr_count( $entrypoint, "require_once __DIR__ . '/includes/archive/bootstrap.php';" ),
	'P3B1-BOUNDARY-NO-DEFERRED-HANDLER-OR-RUNNER keeps reset, capture, packet, verify, runner, and runtime activation paths disabled'
);
archive_check(
	0 === preg_match( '/wp-load\.php|wp-config\.php|global\s+\$wpdb|\bDB_(?:NAME|USER|PASSWORD|HOST)\b/i', $archive_sources ),
	'P3B2A-NO-CURRENT-SITE-ACCESS adds no current-site bootstrap, global database handle, or credential source'
);
archive_check(
	0 === preg_match( '/\b(?:add_action|add_filter|register_activation_hook|register_deactivation_hook|wp_schedule_event|wp_schedule_single_event|register_rest_route)\s*\(/i', $archive_sources )
		&& 0 === substr_count( $archive_sources, 'WP_CLI::add_command' )
		&& is_string( $entrypoint )
		&& 1 === substr_count( $entrypoint, "require_once __DIR__ . '/includes/archive/bootstrap.php';" ),
	'P3B3-LOAD-DARK-REGISTERS-NO-ACTIVE-SURFACE adds only the fail-closed bootstrap and no hook, scheduler, controller, CLI, or activation registration'
);

$p3b2b_paths = array_values( array_filter( $archive_paths, static function ( string $path ): bool {
	return false !== stripos( basename( $path ), 'evidence-read-session' )
		|| false !== stripos( basename( $path ), 'learndash-archive-evidence-source' );
} ) );
sort( $p3b2b_paths, SORT_STRING );
$allowed_p3b2b_paths = array(
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-learndash-archive-evidence-source.php' ),
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-wpdb-archive-evidence-read-session.php' ),
);
sort( $allowed_p3b2b_paths, SORT_STRING );
archive_check(
	$allowed_p3b2b_paths === $p3b2b_paths
		&& class_exists( 'GHCA_ACD_LearnDash_Archive_Evidence_Source' )
		&& class_exists( 'GHCA_ACD_WPDB_Archive_Evidence_Read_Session' ),
	'P3B2B-BOUNDARY-ONLY-APPROVED-SOURCE-FILES permits only the approved LearnDash source and read-session classes'
);
archive_check(
	0 === preg_match( '/\b(?:get_option|switch_to_blog|wp_remote_|DB_(?:NAME|USER|PASSWORD|HOST))\b|global\s+\$wpdb/i', $archive_sources )
		&& 0 === preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', file_get_contents( $archive_root . '/infrastructure/class-learndash-archive-evidence-source.php' ) ),
	'P3B2B-NO-RUNTIME-DISCOVERY-OR-SOURCE-MUTATION keeps source configuration injected and evidence reads dark'
);

$p3b3_runtime_paths = array_values( array_filter( $archive_paths, static function ( string $path ): bool {
	return false !== stripos( basename( $path ), 'archive-module' )
		|| false !== stripos( basename( $path ), 'archive-code-version-attestor' )
		|| false !== stripos( basename( $path ), 'wordpress-archive-runtime-descriptor' )
		|| false !== stripos( basename( $path ), 'system-archive-clock' )
		|| false !== stripos( basename( $path ), 'random-archive-id-generator' )
		|| 'bootstrap.php' === strtolower( basename( $path ) );
} ) );
sort( $p3b3_runtime_paths, SORT_STRING );
$allowed_p3b3_runtime_paths = array(
	str_replace( '\\', '/', $archive_root . '/bootstrap.php' ),
	str_replace( '\\', '/', $archive_root . '/class-archive-module.php' ),
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-archive-code-version-attestor.php' ),
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-random-archive-id-generator.php' ),
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-system-archive-clock.php' ),
	str_replace( '\\', '/', $archive_root . '/infrastructure/class-wordpress-archive-runtime-descriptor.php' ),
);
sort( $allowed_p3b3_runtime_paths, SORT_STRING );
archive_check(
	$allowed_p3b3_runtime_paths === $p3b3_runtime_paths,
	'P3B3-BOUNDARY-ONLY-APPROVED-RUNTIME-FILES permits only the approved constructed-dark runtime files'
);
archive_check(
	0 === preg_match( '/\b(?:register_rest_route|wp_schedule_event|wp_schedule_single_event|as_enqueue_async_action|as_schedule_single_action)\s*\(/i', $archive_sources )
		&& 0 === substr_count( $archive_sources, 'WP_CLI::add_command' ),
	'P3B3-NO-REST-ADMIN-AJAX-WPCRON-ACTION-SCHEDULER-OR-CONTROLLER keeps the worker callback unregistered'
);
archive_check(
	class_exists( 'GHCA_ACD_Archive_Review_Intake' )
		&& method_exists( 'GHCA_ACD_Archive_Evidence_Source', 'read_consistent_review_evidence' )
		&& method_exists( 'GHCA_ACD_Archive_Evidence_Source', 'preflight' )
		&& 0 === preg_match( '/\b(?:add_action|add_filter|register_rest_route|WP_CLI::add_command)\s*\(/i', file_get_contents( $archive_root . '/application/class-archive-review-intake.php' ) ),
	'ACTIVATION-A18-REVIEW-AND-PREFLIGHT-CONSTRUCTED-DARK permits only the approved review and preflight contracts'
);

archive_finish();
