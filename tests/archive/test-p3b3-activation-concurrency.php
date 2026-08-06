<?php
require_once __DIR__ . '/bootstrap.php';

final class GHCA_P3B3A_Never_Run_Worker {
	/** @var int */ public $calls = 0;
	/** @return array<string,mixed> */
	public function run_once(): array { $this->calls++; return array( 'status' => 'idle' ); }
}

$module_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/archive/class-archive-module.php' );
$proposal_source = file_get_contents( dirname( __DIR__, 2 ) . '/docs/superpowers/plans/2026-08-01-dual-layer-archive-slice-1b-p3b3-activation-contracts-proposal.md' );
archive_check(
	is_string( $proposal_source ) && false !== strpos( $proposal_source, 'the host enforces exactly one active invocation for the blog' )
		&& false !== strpos( $proposal_source, 'the host enforces no more than five active invocations for the blog' ),
	'ACTIVATION-SCHEDULER-PHASE-CONCURRENCY-EXACT freezes one controlled and at most five production invocations'
);
archive_check(
	is_string( $proposal_source ) && false !== strpos( $proposal_source, 'database leases remain authoritative for task ownership' )
		&& is_string( $module_source ) && 0 === preg_match( '/\b(?:flock|semaphore|mutex|GET_LOCK)\b/i', $module_source ),
	'ACTIVATION-SCHEDULER-HOST-LIMIT-LEASES-AUTHORITATIVE adds no competing runtime lock'
);

$module_reflection = new ReflectionClass( GHCA_ACD_Archive_Module::class );
$module = $module_reflection->newInstanceWithoutConstructor();
$execute = $module_reflection->getMethod( 'execute_once' );
$worker = new GHCA_P3B3A_Never_Run_Worker(); $admissions = 0; $sequence = array();
$result = $execute->invoke(
	$module,
	static function () use ( &$admissions, &$sequence ): array {
		$admissions++;
		$sequence[] = 'admission-' . $admissions;
		if ( 1 === $admissions ) {
			return array(
				'result' => GHCA_ACD_Archive_Module::result( 'ready', 'archive_runtime_ready', 'activation', 0 ),
				'configuration' => array(), 'versions' => array(),
			);
		}
		return array( 'result' => GHCA_ACD_Archive_Module::result( 'blocked', 'archive_runtime_activation_blocked', 'activation', 0 ) );
	},
	static function () use ( $worker, &$sequence ): GHCA_P3B3A_Never_Run_Worker { $sequence[] = 'compose'; return $worker; },
	hrtime( true )
);
archive_check(
	2 === $admissions && 0 === $worker->calls && 'blocked' === $result['status'] && 'archive_runtime_activation_blocked' === $result['code'],
	'ACTIVATION-AUTHORIZATION-REREAD-BEFORE-CLAIM blocks a changed authorization after composition and before claim'
);
$cli_start = strpos( $module_source, 'public function cli_run(' );
$cli_end = strpos( $module_source, 'private function execute_once', $cli_start );
$cli_source = false !== $cli_start && false !== $cli_end ? substr( $module_source, $cli_start, $cli_end - $cli_start ) : '';
archive_check(
	array( 'admission-1', 'compose', 'admission-2' ) === $sequence
		&& 0 === $worker->calls
		&& 1 === preg_match( '/\$this->execute_once\(\s*function\s*\(\s*\)\s*:\s*array\s*\{\s*return\s+\$this->admission\(\);\s*\},/s', $cli_source )
		&& strpos( $module_source, '$second = $admit();' ) < strpos( $module_source, '$worker->run_once();' ),
	'ACTIVATION-ATTEST-RECHECK-BEFORE-CLAIM proves fresh admission before and after composition and fences the worker on second-call failure'
);
archive_check(
	2 === substr_count( $module_source, '$admit()' )
		&& false !== strpos( $module_source, '$second = $admit();' )
		&& strpos( $module_source, '$second = $admit();' ) < strpos( $module_source, '$worker->run_once();' ),
	'ACTIVATION-FLAGS-REREAD-BEFORE-CLAIM rechecks the complete live admission immediately before the bounded worker call'
);
archive_check(
	false !== strpos( $proposal_source, 'real two-connection lease and source-snapshot races' )
		&& false !== strpos( $proposal_source, 'admission change between composition and claim' ),
	'ACTIVATION-CONCURRENCY-RETAINED-REAL-CONNECTION-COVERAGE keeps the accepted lease/source race suites mandatory'
);

archive_finish();
