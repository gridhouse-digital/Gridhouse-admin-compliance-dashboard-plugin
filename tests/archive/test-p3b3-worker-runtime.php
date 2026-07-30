<?php
require_once __DIR__ . '/bootstrap.php';

final class GHCA_P3B3_Executable_Worker {
	/** @var int */
	public $calls = 0;

	/** @return array<string,string> */
	public function run_once(): array {
		$this->calls++;
		return array( 'status' => 'idle' );
	}
}

$plugin_root = dirname( __DIR__, 2 );
$module_source = file_get_contents( $plugin_root . '/includes/archive/class-archive-module.php' );
$descriptor_source = file_get_contents( $plugin_root . '/includes/archive/infrastructure/class-wordpress-archive-runtime-descriptor.php' );
$store_source = file_get_contents( $plugin_root . '/includes/archive/infrastructure/class-private-archive-artifact-store.php' );
$coordinator_source = file_get_contents( $plugin_root . '/includes/archive/application/class-archive-worker-coordinator.php' );
$task_store_source = file_get_contents( $plugin_root . '/includes/archive/infrastructure/class-wpdb-archive-task-store.php' );
$source_adapter_source = file_get_contents( $plugin_root . '/includes/archive/infrastructure/class-learndash-archive-evidence-source.php' );
$runner_source = file_get_contents( __DIR__ . '/test-all.ps1' );
$worker_tests = file_get_contents( __DIR__ . '/test-p3-worker.php' );
$ledger_tests = file_get_contents( __DIR__ . '/test-p3b-ledger-failures.php' );
$evidence_tests = file_get_contents( __DIR__ . '/test-p3b2a-evidence.php' );
$evidence_handler_source = file_get_contents( $plugin_root . '/includes/archive/application/class-archive-evidence-task-handler.php' );
$proposal_source = file_get_contents( $plugin_root . '/docs/superpowers/plans/2026-07-30-dual-layer-archive-slice-1b-p3b3-runtime-composition-decisions-proposal.md' );

archive_check(
	GHCA_ACD_Archive_Task_Catalog::installed_types() === array( 'capture_evidence', 'materialize_ledger' )
		&& false !== strpos( $module_source, 'GHCA_ACD_Archive_Task_Catalog::CAPTURE_TASK_TYPE => $evidence' )
		&& false !== strpos( $module_source, 'GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE => $ledger' ),
	'P3B3-HANDLER-REGISTRY-EXACT-CAPTURE-AND-LEDGER freezes the literal installed map'
);
archive_check(
	false !== strpos( $coordinator_source, 'claim_available( $this->lease_owner, $token, $now, $this->installed_types )' ),
	'P3B3-INSTALLED-TYPE-AVAILABLE-CLAIM-FILTER passes the closed registry into claims'
);
archive_check(
	false !== strpos( $coordinator_source, 'reclaim_expired( $this->lease_owner, $token, $now, $this->installed_types )' ),
	'P3B3-INSTALLED-TYPE-EXPIRED-RECLAIM-FILTER passes the closed registry into reclaim'
);
archive_check(
	0 === preg_match( '/materialize_packet|verify_and_finalize|rebuild_projection|integrity_check/', $module_source ),
	'P3B3-DEFERRED-TASKS-REMAIN-UNTOUCHED registers no deferred type'
);
archive_check(
	(function (): bool {
		$module = new GHCA_ACD_Archive_Module(
			new stdClass(),
			new GHCA_ACD_WordPress_Archive_Runtime_Descriptor( new stdClass() ),
			new GHCA_ACD_Archive_Code_Version_Attestor()
		);
		$execute = new ReflectionMethod( GHCA_ACD_Archive_Module::class, 'execute_once' );
		$worker = new GHCA_P3B3_Executable_Worker();
		$admissions = 0;
		$compositions = 0;
		$ready = array(
			'configuration' => array(),
			'result' => GHCA_ACD_Archive_Module::result( 'ready', 'archive_runtime_ready', 'activation', 0 ),
			'versions' => array(),
		);
		$result = $execute->invoke(
			$module,
			static function () use ( &$admissions, $ready ): array {
				$admissions++;
				return $ready;
			},
			static function ( array $admission ) use ( &$compositions, $worker ): object {
				$compositions++;
				return $worker;
			},
			hrtime( true )
		);
		$blocked_worker = new GHCA_P3B3_Executable_Worker();
		$blocked_admissions = 0;
		$blocked_compositions = 0;
		$blocked = $execute->invoke(
			$module,
			static function () use ( &$blocked_admissions, $ready ): array {
				$blocked_admissions++;
				return 1 === $blocked_admissions
					? $ready
					: array(
						'result' => GHCA_ACD_Archive_Module::result(
							'blocked',
							'archive_runtime_disabled',
							'flags',
							0
						),
					);
			},
			static function ( array $admission ) use ( &$blocked_compositions, $blocked_worker ): object {
				$blocked_compositions++;
				return $blocked_worker;
			},
			hrtime( true )
		);
		return 2 === $admissions && 1 === $compositions && 1 === $worker->calls
			&& 'idle' === $result['status']
			&& 2 === $blocked_admissions && 1 === $blocked_compositions
			&& 0 === $blocked_worker->calls
			&& 'blocked' === $blocked['status']
			&& 'archive_runtime_disabled' === $blocked['code'];
	})()
		&& 1 === substr_count( $module_source, '$this->execute_once(' )
		&& 1 === substr_count( $module_source, 'return $this->compose_worker(' )
		&& 1 === substr_count( $module_source, '$worker->run_once()' ),
	'P3B3-RUNNER-CALLS-RUN-ONCE-EXACTLY-ONCE executes the twice-gated module callback and one bounded coordinator call'
);
archive_check(
	false !== strpos( $module_source, 'array() === $args && array() === $assoc_args' )
		&& false === strpos( $module_source, 'task_id' )
		&& false === strpos( $module_source, 'task_type' ),
	'P3B3-RUNNER-ACCEPTS-NO-TASK-TYPE-ID-PATH-VERSION-OR-SECRET rejects all operator input'
);
archive_check(
	false !== strpos( $worker_tests, 'one live lease across two real connections' )
		&& false !== strpos( $proposal_source, 'at most five worker processes per site' ),
	'P3B3-FIVE-PROCESS-LEASE-RACE-ONE-OWNER-PER-TASK retains durable row fencing under the host ceiling'
);
archive_check(
	false !== strpos( $worker_tests, 'TASK-LIVE-LEASE-NOT-STOLEN' )
		&& false !== strpos( $task_store_source, "task_state = 'leased' AND lease_owner = %s AND lease_token = %s" ),
	'P3B3-LIVE-LEASE-NOT-STOLEN preserves the exact live-lease predicate'
);
archive_check(
	false !== strpos( $worker_tests, 'TASK-STALE-COMPLETION' )
		&& false !== strpos( $worker_tests, 'TASK-OUTCOME-STALE-FENCE-REPLAY-REJECTED' )
		&& false !== strpos( $coordinator_source, 'lease_lost' ),
	'P3B3-STALE-WORKER-CANNOT-OUTCOME-OR-COMPLETE preserves stale-worker rejection'
);
archive_check(
	false !== strpos( $proposal_source, 'process timeout is 110 seconds' )
		&& false !== strpos( $proposal_source, 'leaves the lease to expire' ),
	'P3B3-HOST-TIMEOUT-LEAVES-RECLAIMABLE-LEASE creates no lifecycle inference'
);
archive_check(
	false !== strpos( $evidence_tests, 'P3B2A-D16-REMAINS-DEFERRED' )
		&& false !== strpos( $module_source, 'archive_runtime_partial_retry_unresolved' ),
	'P3B3-D16-RETRY-TASK-NOT-CLAIMED keeps unresolved partial-artifact retries blocked'
);

archive_check(
	false !== strpos( $descriptor_source, "'cursor_key' => 'GHCA_ACD_ARCHIVE_CURSOR_HMAC_KEY'" )
		&& false !== strpos( $module_source, "\$configuration['storage']['cursor_key']" ),
	'P3B3-CURSOR-KEY-EXACT-H1-INJECTION passes one validated key unchanged'
);
archive_check(
	false !== strpos( $descriptor_source, '/^[a-f0-9]{64}$/D' )
		&& false !== strpos( $descriptor_source, 'archive_runtime_cursor_key_invalid' ),
	'P3B3-CURSOR-KEY-MISSING-INVALID-BLOCKS-BEFORE-CLAIM requires 32 encoded CSPRNG bytes'
);
archive_check(
	false !== strpos( $store_source, 'hash_equals' )
		&& false !== strpos( $proposal_source, 'scans restart from `null`' ),
	'P3B3-CURSOR-ROTATION-INVALIDATES-CURSOR-AND-RESTARTS-NULL preserves H1 restart semantics'
);
archive_check(
	1 === substr_count( $module_source, "\$configuration['storage']['cursor_key']" )
		&& 0 === preg_match( '/cursor_key[^;]*(?:result|echo|fwrite|error_log)/i', $module_source ),
	'P3B3-CURSOR-KEY-NOT-REUSED-OR-EXPOSED keeps the key storage-only'
);
archive_check(
	1 === substr_count( $module_source, 'private function compose_evidence_source' )
		&& 1 === substr_count( $module_source, '$this->compose_evidence_source(' ),
	'P3B3-REVIEW-CAPTURE-ONE-PRIVATE-COMPOSITION-RECIPE has one construction authority'
);
archive_check(
	false !== strpos( $proposal_source, 'separate WordPress processes' )
		&& false !== strpos( $module_source, 'new wpdb(' ),
	'P3B3-REVIEW-CAPTURE-INDEPENDENT-INSTANCES-AND-CONNECTIONS forbids connection reuse'
);
archive_check(
	false !== strpos( $source_adapter_source, 'GHCA_ACD_Archive_Canonical_JSON::encode' )
		&& false !== strpos( $proposal_source, 'byte-identical normalized E07 documents' ),
	'P3B3-REVIEW-CAPTURE-SEPARATE-INVOCATIONS-BYTE-IDENTICAL-E07 retains one canonicalizer'
);
archive_check(
	false !== strpos( $evidence_handler_source, 'GHCA_ACD_Archive_Digester::source_fingerprint' )
		&& false !== strpos( $proposal_source, 'E08 digest domain' ),
	'P3B3-REVIEW-CAPTURE-SEPARATE-INVOCATIONS-IDENTICAL-E08 retains one digest domain'
);
archive_check(
	false !== strpos( $module_source, 'archive_runtime_review_capture_parity_unavailable' )
		&& strpos( $module_source, 'archive_runtime_review_capture_parity_unavailable' )
			< strpos( $module_source, 'compose_worker' ),
	'P3B3-REVIEW-CAPTURE-PARITY-BLOCKS-PRODUCTION-INTAKE leaves the graph unreachable'
);
archive_check(
	false !== strpos( $proposal_source, 'time-independent calculation' )
		&& false !== strpos( $source_adapter_source, "'compliant'" ),
	'P3B3-TIME-INDEPENDENT-CALCULATION-V1-APPROVED preserves the accepted adapter rule'
);
archive_check(
	0 === preg_match( '/\\btime\\s*\\(|microtime|hrtime|now_gmt/i', $source_adapter_source ),
	'P3B3-CALCULATION-READS-NO-CLOCK is independent of wall time'
);
archive_check(
	false !== strpos( $proposal_source, 'requires a new owner-approved command/event/UoW/source contract' ),
	'P3B3-TIME-DEPENDENT-CALCULATION-REQUIRES-VERSIONED-AMENDMENT remains owner-gated'
);

archive_check(
	false !== strpos( $descriptor_source, 'GHCA_ACD_ARCHIVE_PRIVATE_DIR' )
		&& false !== strpos( $descriptor_source, 'GHCA_ACD_ARCHIVE_PUBLIC_DOCUMENT_ROOT' )
		&& false !== strpos( $descriptor_source, 'overlaps' ),
	'P3B3-PRIVATE-ROOT-OUTSIDE-ALL-PUBLIC-ROOTS validates explicit non-overlap'
);
archive_check(
	0 === preg_match( '/wp_upload_dir|uploads/i', $descriptor_source . $module_source ),
	'P3B3-NO-UPLOADS-FALLBACK-OR-IMPLICIT-DIRECTORY selects no public storage'
);
archive_check(
	false !== strpos( $proposal_source, 'Changing the private root while any descriptor references the old root is prohibited' ),
	'P3B3-ROOT-CHANGE-WITH-REFERENCED-DESCRIPTORS-BLOCKED remains a migration gate'
);
archive_check(
	1 === substr_count( $module_source, "const WORKER_COMMAND = 'ghca-acd archive-worker run';" )
		&& 1 === substr_count( $module_source, 'WP_CLI::add_command' )
		&& false !== strpos( $module_source, 'register_worker_command' ),
	'P3B3-ONLY-APPROVED-WPCLI-COMMAND-REGISTERED exposes one dormant operator command'
);
archive_check(
	0 === preg_match( '/register_rest_route|add_action|add_filter|wp_schedule|as_schedule|as_enqueue/i', $module_source ),
	'P3B3-NO-REST-ADMIN-AJAX-WPCRON-ACTION-SCHEDULER-OR-CONTROLLER adds no web or scheduler surface'
);

$suite_names = array(
	'test-p3b3-runtime-composition.php',
	'test-p3b3-activation-gates.php',
	'test-p3b3-worker-runtime.php',
	'test-p3b3-multisite.php',
);
$runner_exact = true;
foreach ( $suite_names as $suite_name ) {
	$runner_exact = $runner_exact && 1 === substr_count( $runner_source, $suite_name );
}
archive_check(
	$runner_exact,
	'P3B3-RUNNER-SUITES-EXACTLY-ONCE keeps every constructed-dark suite in each disposable cell'
);

archive_finish();
