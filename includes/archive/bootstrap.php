<?php

$ghca_archive_started = hrtime( true );
$ghca_archive_failure = static function () use ( $ghca_archive_started ): array {
	$ended = hrtime( true );
	$duration = $ended >= $ghca_archive_started ? intdiv( $ended - $ghca_archive_started, 1000000 ) : PHP_INT_MAX;
	return array(
		'code' => 'archive_runtime_load_failed',
		'claimed_count' => 0,
		'completed_count' => 0,
		'dead_count' => 0,
		'duration_ms' => $duration,
		'lease_lost_count' => 0,
		'retry_count' => 0,
		'stage' => 'load',
		'status' => 'blocked',
	);
};
$ghca_archive_manifest = array(
	'contracts/interface-archive-clock.php',
	'contracts/interface-archive-id-generator.php',
	'contracts/class-archive-evidence-source.php',
	'contracts/interface-archive-artifact-store.php',
	'contracts/interface-archive-event-store.php',
	'infrastructure/class-archive-empty-object.php',
	'infrastructure/class-archive-canonical-object.php',
	'infrastructure/class-archive-canonical-json.php',
	'infrastructure/class-archive-persistence-exception.php',
	'infrastructure/class-archive-artifact-store-exception.php',
	'infrastructure/class-archive-db-format.php',
	'infrastructure/class-archive-digester.php',
	'domain/class-archive-transition-exception.php',
	'domain/class-archive-event-types.php',
	'domain/class-archive-event-catalog.php',
	'domain/class-archive-cycle.php',
	'domain/class-archive-case-key.php',
	'domain/class-archive-actor.php',
	'domain/class-archive-reset-scope.php',
	'domain/class-archive-command.php',
	'domain/class-archive-client-intent.php',
	'domain/class-archive-event.php',
	'infrastructure/class-archive-event-stream-verifier.php',
	'domain/class-archive-case.php',
	'class-archive-schema.php',
	'class-archive-migrator.php',
	'infrastructure/class-wpdb-archive-event-store.php',
	'infrastructure/class-wpdb-archive-command-store.php',
	'infrastructure/class-wpdb-archive-task-store.php',
	'infrastructure/class-wpdb-archive-snapshot-store.php',
	'infrastructure/class-wpdb-archive-artifact-repository.php',
	'infrastructure/class-wpdb-archive-projection-repository.php',
	'infrastructure/class-archive-case-projector.php',
	'infrastructure/class-archive-revision-projector.php',
	'infrastructure/class-archive-reset-projector.php',
	'infrastructure/class-archive-projector.php',
	'application/class-archive-task-catalog.php',
	'application/class-archive-evidence-source-exception.php',
	'application/class-archive-evidence-result-validator.php',
	'application/class-archive-evidence-snapshot-preparer.php',
	'application/class-archive-ledger-materializer.php',
	'application/class-archive-unit-of-work.php',
	'application/class-archive-review-intake.php',
	'application/class-archive-build-coordinator.php',
	'application/class-archive-evidence-task-handler.php',
	'application/class-archive-ledger-task-handler.php',
	'application/class-archive-orphan-reconciler.php',
	'application/class-archive-worker-coordinator.php',
	'infrastructure/class-wpdb-archive-evidence-read-session.php',
	'infrastructure/class-learndash-archive-evidence-source.php',
	'infrastructure/class-private-archive-artifact-store.php',
	'infrastructure/class-system-archive-clock.php',
	'infrastructure/class-random-archive-id-generator.php',
	'infrastructure/class-archive-code-version-attestor.php',
	'infrastructure/class-wordpress-archive-runtime-descriptor.php',
	'class-archive-module.php',
);
$ghca_archive_digest = 'b39c21168cb7585c4a2a115ec4ad5359466ea2d63fdddeb86fdea9de81114a11';
$ghca_archive_root = realpath( __DIR__ );
$ghca_archive_paths = array();
if ( 56 !== count( $ghca_archive_manifest )
	|| 56 !== count( array_unique( $ghca_archive_manifest ) )
	|| ! hash_equals( $ghca_archive_digest, hash( 'sha256', implode( "\n", $ghca_archive_manifest ) ) )
	|| false === $ghca_archive_root || is_link( __DIR__ ) ) {
	return $ghca_archive_failure();
}
$ghca_archive_prefix = rtrim( str_replace( '\\', '/', $ghca_archive_root ), '/' ) . '/';
if ( '\\' === DIRECTORY_SEPARATOR ) {
	$ghca_archive_prefix = strtolower( $ghca_archive_prefix );
}
foreach ( $ghca_archive_manifest as $ghca_archive_relative ) {
	if ( ! is_string( $ghca_archive_relative ) || '' === $ghca_archive_relative
		|| false !== strpos( $ghca_archive_relative, '\\' )
		|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $ghca_archive_relative )
		|| preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $ghca_archive_relative )
		|| preg_match( '#^(?:/|[A-Za-z]:)#', $ghca_archive_relative ) ) {
		return $ghca_archive_failure();
	}
	$ghca_archive_path = realpath( $ghca_archive_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $ghca_archive_relative ) );
	$ghca_archive_normalized = false === $ghca_archive_path ? '' : str_replace( '\\', '/', $ghca_archive_path );
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$ghca_archive_normalized = strtolower( $ghca_archive_normalized );
	}
	if ( false === $ghca_archive_path || ! is_file( $ghca_archive_path ) || is_link( $ghca_archive_path )
		|| 0 !== strpos( $ghca_archive_normalized . '/', $ghca_archive_prefix ) ) {
		return $ghca_archive_failure();
	}
	$ghca_archive_paths[] = $ghca_archive_path;
}
try {
	foreach ( $ghca_archive_paths as $ghca_archive_path ) {
		require_once $ghca_archive_path;
	}
	return GHCA_ACD_Archive_Module::bootstrap();
} catch ( Throwable $ghca_archive_error ) {
	return $ghca_archive_failure();
}
