<?php
// Standalone Slice 1A bootstrap. It deliberately does not load WordPress or the plugin entrypoint.
if ( ! defined( 'GHCA_ACD_ARCHIVE_TESTING' ) ) {
	define( 'GHCA_ACD_ARCHIVE_TESTING', true );
}

$archive_root = dirname( __DIR__, 2 ) . '/includes/archive';
$archive_files = array(
	'/contracts/interface-archive-clock.php',
	'/contracts/interface-archive-id-generator.php',
	'/contracts/class-archive-evidence-source.php',
	'/contracts/interface-archive-artifact-store.php',
	'/contracts/interface-archive-event-store.php',
	'/infrastructure/class-archive-empty-object.php',
	'/infrastructure/class-archive-canonical-object.php',
	'/infrastructure/class-archive-canonical-json.php',
	'/infrastructure/class-archive-persistence-exception.php',
	'/infrastructure/class-archive-artifact-store-exception.php',
	'/infrastructure/class-archive-db-format.php',
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
	'/class-archive-schema.php',
	'/class-archive-migrator.php',
	'/infrastructure/class-wpdb-archive-event-store.php',
	'/infrastructure/class-wpdb-archive-command-store.php',
	'/infrastructure/class-wpdb-archive-task-store.php',
	'/infrastructure/class-wpdb-archive-snapshot-store.php',
	'/infrastructure/class-wpdb-archive-artifact-repository.php',
	'/infrastructure/class-wpdb-archive-projection-repository.php',
	'/infrastructure/class-archive-case-projector.php',
	'/infrastructure/class-archive-revision-projector.php',
	'/infrastructure/class-archive-reset-projector.php',
	'/infrastructure/class-archive-projector.php',
	'/application/class-archive-task-catalog.php',
	'/application/class-archive-evidence-source-exception.php',
	'/application/class-archive-evidence-result-validator.php',
	'/application/class-archive-evidence-snapshot-preparer.php',
	'/application/class-archive-ledger-materializer.php',
	'/application/class-archive-unit-of-work.php',
	'/application/class-archive-review-intake.php',
	'/application/class-archive-build-coordinator.php',
	'/application/class-archive-evidence-task-handler.php',
	'/application/class-archive-ledger-task-handler.php',
	'/application/class-archive-orphan-reconciler.php',
	'/application/class-archive-worker-coordinator.php',
	'/infrastructure/class-wpdb-archive-evidence-read-session.php',
	'/infrastructure/class-learndash-archive-evidence-source.php',
	'/infrastructure/class-private-archive-artifact-store.php',
	'/infrastructure/class-system-archive-clock.php',
	'/infrastructure/class-random-archive-id-generator.php',
	'/infrastructure/class-archive-code-version-attestor.php',
	'/infrastructure/class-wordpress-archive-runtime-descriptor.php',
	'/class-archive-module.php',
);
foreach ( $archive_files as $archive_file ) {
	if ( is_file( $archive_root . $archive_file ) ) {
		require_once $archive_root . $archive_file;
	}
}

require_once __DIR__ . '/remediation-fixtures.php';
require_once __DIR__ . '/test-helpers.php';
