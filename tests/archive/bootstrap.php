<?php
// Standalone Slice 1A bootstrap. It deliberately does not load WordPress or the plugin entrypoint.
if ( ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) ) {
	define( 'GHCA_ACD_ARCHIVE_TESTING', true );
}

$archive_root = dirname( __DIR__, 2 ) . '/includes/archive';
$archive_files = array(
	'/contracts/interface-archive-clock.php',
	'/contracts/interface-archive-id-generator.php',
	'/infrastructure/class-archive-empty-object.php',
	'/infrastructure/class-archive-canonical-object.php',
	'/infrastructure/class-archive-canonical-json.php',
	'/infrastructure/class-archive-persistence-exception.php',
	'/infrastructure/class-archive-digester.php',
	'/domain/class-archive-transition-exception.php',
	'/domain/class-archive-event-types.php',
	'/domain/class-archive-event-catalog.php',
	'/domain/class-archive-cycle.php',
	'/domain/class-archive-case-key.php',
	'/domain/class-archive-actor.php',
	'/domain/class-archive-reset-scope.php',
	'/domain/class-archive-command.php',
	'/domain/class-archive-client-intent.php',
	'/domain/class-archive-event.php',
	'/infrastructure/class-archive-event-stream-verifier.php',
	'/domain/class-archive-case.php',
);
foreach ( $archive_files as $archive_file ) {
	if ( is_file( $archive_root . $archive_file ) ) {
		require_once $archive_root . $archive_file;
	}
}

require_once __DIR__ . '/remediation-fixtures.php';
require_once __DIR__ . '/test-helpers.php';
