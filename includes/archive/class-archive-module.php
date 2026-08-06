<?php

/** Single explicit composition root for the constructed-dark archive runtime. */
final class GHCA_ACD_Archive_Module {
	private const EVIDENCE_LIMITS = array(
		'maximum_queries' => 32,
		'maximum_rows' => 10000,
		'maximum_transaction_milliseconds' => 2000,
	);
	private const CALCULATION_POLICY_KEY = 'time-independent';
	private const CALCULATION_POLICY_VERSION = 1;
	const WORKER_COMMAND = 'ghca-acd archive-worker run';
	const RESULT_KEYS = array(
		'code', 'claimed_count', 'completed_count', 'dead_count', 'duration_ms',
		'lease_lost_count', 'retry_count', 'stage', 'status',
	);
	const RESULT_MAP = array(
		'ready/archive_runtime_ready/activation' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_load_failed/load' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_disabled/flags' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_schema_mismatch/schema' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_attestation_failed/attestation' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_tenant_invalid/tenant' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_source_credentials_invalid/credentials' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_storage_invalid/storage' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_cursor_key_invalid/storage' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_review_capture_parity_unavailable/parity' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_calculation_policy_unapproved/calculation' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_handler_registry_invalid/registry' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_wakeup_unavailable/wakeup' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_partial_retry_unresolved/activation' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_activation_blocked/activation' => array( 0, 0, 0, 0, 0 ),
		'blocked/archive_runtime_internal_failure/worker' => array( 0, 0, 0, 0, 0 ),
		'idle/archive_worker_idle/worker' => array( 0, 0, 0, 0, 0 ),
		'completed/archive_worker_completed/worker' => array( 1, 1, 0, 0, 0 ),
		'retry/archive_worker_retry/worker' => array( 1, 0, 1, 0, 0 ),
		'dead/archive_worker_dead/worker' => array( 1, 0, 0, 1, 0 ),
		'lease_lost/archive_worker_lease_lost/worker' => array( 1, 0, 0, 0, 1 ),
	);

	/** @var string */
	private static $state = 'absent';
	/** @var object */
	private $db;
	/** @var GHCA_ACD_WordPress_Archive_Runtime_Descriptor */
	private $runtime;
	/** @var GHCA_ACD_Archive_Code_Version_Attestor */
	private $attestor;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;
	/** @var GHCA_ACD_Archive_Id_Generator */
	private $ids;
	/** @var GHCA_ACD_Archive_Orphan_Reconciler|null */
	private $orphan_reconciler;

	/** @param object $db */
	public function __construct(
		$db,
		GHCA_ACD_WordPress_Archive_Runtime_Descriptor $runtime,
		GHCA_ACD_Archive_Code_Version_Attestor $attestor,
		?GHCA_ACD_Archive_Clock $clock = null,
		?GHCA_ACD_Archive_Id_Generator $ids = null
	) {
		$this->db = $db;
		$this->runtime = $runtime;
		$this->attestor = $attestor;
		$this->clock = $clock ?: new GHCA_ACD_System_Archive_Clock();
		$this->ids = $ids ?: new GHCA_ACD_Random_Archive_Id_Generator();
	}

	/** Dark bootstrap performs no current-site read, write, connection, or registration. */
	public static function bootstrap(): array {
		$started = hrtime( true );
		self::$state = 'constructed_dark';
		return self::result( 'blocked', 'archive_runtime_disabled', 'flags', self::elapsed( $started ) );
	}

	public static function state(): string {
		return self::$state;
	}

	/**
	 * Fail-closed activation gate. P3B3 deliberately cannot pass the unresolved
	 * review-producer and D16 gates, so no worker surface is registered.
	 *
	 * @return array<string,mixed>
	 */
	public function activation_status(): array {
		$admission = $this->admission();
		return $admission['result'];
	}

	/** Run only code-enforceable gates while both feature flags remain off. */
	public function preflight_status( callable $checkpoint ): array {
		$started = hrtime( true );
		try {
			$versions = $this->attestor->attest();
			$configuration = $this->runtime->preflight();
			$this->assert_calculation_policy();
			$this->assert_review_parity();
			$this->assert_handler_registry();
			$checkpoint();
			$source = $this->compose_evidence_source( $configuration, $versions );
			$source->preflight( self::EVIDENCE_LIMITS, $checkpoint );
			return self::result( 'ready', 'archive_runtime_ready', 'activation', self::elapsed( $started ) );
		} catch ( UnexpectedValueException $error ) {
			return $this->gate_failure( $error->getMessage(), $started );
		} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
			return self::result( 'blocked', 'archive_runtime_source_credentials_invalid', 'credentials', self::elapsed( $started ) );
		} catch ( Throwable $error ) {
			return self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		}
	}

	/**
	 * Direct review operation for a separately authorized caller. Bootstrap does
	 * not call or register it.
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	public function review( array $request, callable $checkpoint ): array {
		$admission = $this->admission();
		if ( ! $this->ready_admission( $admission ) ) {
			throw new UnexpectedValueException( $admission['result']['code'] ?? 'archive_runtime_load_failed' );
		}
		$checkpoint();
		$source = $this->compose_evidence_source( $admission['configuration'], $admission['versions'] );
		$persistence = $this->compose_persistence();
		return ( new GHCA_ACD_Archive_Review_Intake( $source, $persistence['uow'], $this->ids ) )->execute( $request, $checkpoint );
	}

	/** @return array<string,mixed> */
	private function admission(): array {
		$started = hrtime( true );
		try {
			$versions = $this->attestor->attest();
			$configuration = $this->runtime->resolve();
			$this->assert_calculation_policy();
			$this->assert_review_parity();
			$this->assert_handler_registry();
			$this->compose_evidence_source( $configuration, $versions )->preflight(
				self::EVIDENCE_LIMITS,
				static function (): void {}
			);
			if ( 'production' === $configuration['mode'] ) {
				return array(
					'result' => self::result( 'blocked', 'archive_runtime_partial_retry_unresolved', 'activation', self::elapsed( $started ) ),
				);
			}
			return array(
				'result' => self::result( 'ready', 'archive_runtime_ready', 'activation', self::elapsed( $started ) ),
				'configuration' => $configuration,
				'versions' => $versions,
			);
		} catch ( UnexpectedValueException $error ) {
			return array( 'result' => $this->gate_failure( $error->getMessage(), $started ) );
		} catch ( GHCA_ACD_Archive_Evidence_Source_Exception $error ) {
			return array( 'result' => self::result( 'blocked', 'archive_runtime_source_credentials_invalid', 'credentials', self::elapsed( $started ) ) );
		} catch ( Throwable $error ) {
			return array(
				'result' => self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) ),
			);
		}
	}

	/**
	 * Dormant registration point. Bootstrap never calls it; a separately
	 * authorized activation must pass every gate first.
	 *
	 * @return array<string,mixed>
	 */
	public function register_worker_command(): array {
		$status = $this->activation_status();
		if ( 'ready' !== $status['status'] ) {
			return $status;
		}
		return self::result( 'blocked', 'archive_runtime_activation_blocked', 'activation', $status['duration_ms'] );
	}

	/**
	 * Exact no-argument CLI boundary. It is unreachable while the retained
	 * parity and D16 activation blockers remain unresolved.
	 *
	 * @param array<int,mixed> $args
	 * @param array<string,mixed> $assoc_args
	 */
	public function cli_run( array $args = array(), array $assoc_args = array() ): void {
		$started = hrtime( true );
		ob_start();
		$previous = set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
			throw new ErrorException( 'Archive worker runtime warning.', 0, $severity, $file, $line );
		} );
		try {
			$result = ( array() === $args && array() === $assoc_args )
				? $this->execute_once(
					function (): array {
						return $this->admission();
					},
					function ( array $admission ): GHCA_ACD_Archive_Worker_Coordinator {
						return $this->compose_worker( $admission['configuration'], $admission['versions'] );
					},
					$started
				)
				: self::result( 'blocked', 'archive_runtime_activation_blocked', 'activation', self::elapsed( $started ) );
		} catch ( Throwable $error ) {
			$result = self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		} finally {
			restore_error_handler();
			ob_end_clean();
		}
		fwrite( STDOUT, self::encode_result( $result ) . "\n" );
		if ( class_exists( 'WP_CLI', false ) && method_exists( 'WP_CLI', 'halt' )
			&& ! in_array( $result['status'], array( 'idle', 'completed' ), true ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param callable():array<string,mixed> $admit
	 * @param callable(array<string,mixed>):object $compose
	 * @return array<string,mixed>
	 */
	private function execute_once( callable $admit, callable $compose, int $started ): array {
		$first = $admit();
		if ( ! $this->ready_admission( $first ) ) {
			return $this->admission_result( $first, $started );
		}
		$worker = $compose( $first );
		if ( ! is_object( $worker ) || ! is_callable( array( $worker, 'run_once' ) ) ) {
			return self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		}
		$second = $admit();
		if ( ! $this->ready_admission( $second ) ) {
			return $this->admission_result( $second, $started );
		}
		try {
			$worker_result = $worker->run_once();
		} catch ( Throwable $error ) {
			return self::result( 'blocked', 'archive_runtime_internal_failure', 'worker', self::elapsed( $started ) );
		}
		return is_array( $worker_result )
			? self::worker_result( $worker_result, self::elapsed( $started ) )
			: self::result( 'blocked', 'archive_runtime_internal_failure', 'worker', self::elapsed( $started ) );
	}

	/** @param array<string,mixed> $admission */
	private function ready_admission( array $admission ): bool {
		return isset( $admission['result'], $admission['configuration'], $admission['versions'] )
			&& is_array( $admission['result'] )
			&& isset( $admission['result']['status'] )
			&& 'ready' === $admission['result']['status']
			&& is_array( $admission['configuration'] )
			&& is_array( $admission['versions'] );
	}

	/**
	 * @param array<string,mixed> $admission
	 * @return array<string,mixed>
	 */
	private function admission_result( array $admission, int $started ): array {
		if ( ! isset( $admission['result'] ) || ! is_array( $admission['result'] )
			|| ! isset( $admission['result']['status'], $admission['result']['code'], $admission['result']['stage'] )
			|| ! is_string( $admission['result']['status'] )
			|| ! is_string( $admission['result']['code'] )
			|| ! is_string( $admission['result']['stage'] ) ) {
			return self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		}
		try {
			return self::result(
				$admission['result']['status'],
				$admission['result']['code'],
				$admission['result']['stage'],
				self::elapsed( $started )
			);
		} catch ( Throwable $error ) {
			return self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		}
	}

	/**
	 * Complete accepted graph. It remains private and unreachable from bootstrap
	 * while the activation blockers above are unresolved.
	 */
	private function compose_worker( array $configuration, array $versions ): GHCA_ACD_Archive_Worker_Coordinator {
		$persistence = $this->compose_persistence();
		$events = $persistence['events'];
		$tasks = $persistence['tasks'];
		$snapshots = $persistence['snapshots'];
		$artifacts = $persistence['artifacts'];
		$uow = $persistence['uow'];
		$build = new GHCA_ACD_Archive_Build_Coordinator( $events, $snapshots, $artifacts, $uow );
		$store = new GHCA_ACD_Private_Archive_Artifact_Store(
			$configuration['storage']['private_root'],
			$configuration['storage']['public_roots'],
			$configuration['storage']['cursor_key']
		);
		$this->orphan_reconciler = new GHCA_ACD_Archive_Orphan_Reconciler(
			$store,
			$artifacts,
			$this->clock
		);
		$ledger = new GHCA_ACD_Archive_Ledger_Task_Handler(
			$events, $snapshots, $artifacts, $store, new GHCA_ACD_Archive_Ledger_Materializer()
		);
		$evidence_source = $this->compose_evidence_source( $configuration, $versions );
		$evidence = new GHCA_ACD_Archive_Evidence_Task_Handler(
			$evidence_source,
			new GHCA_ACD_Archive_Evidence_Result_Validator(),
			new GHCA_ACD_Archive_Evidence_Snapshot_Preparer(),
			$this->clock
		);
		$handlers = array(
			GHCA_ACD_Archive_Task_Catalog::CAPTURE_TASK_TYPE => $evidence,
			GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE => $ledger,
		);
		$this->assert_handler_registry( array_keys( $handlers ) );
		return new GHCA_ACD_Archive_Worker_Coordinator(
			$tasks, $this->clock, $this->ids, $this->ids->generate(), $handlers, null, $build, $ledger
		);
	}

	/** @return array<string,object> */
	private function compose_persistence(): array {
		$events = new GHCA_ACD_WPDB_Archive_Event_Store( $this->db );
		$commands = new GHCA_ACD_WPDB_Archive_Command_Store( $this->db );
		$tasks = new GHCA_ACD_WPDB_Archive_Task_Store( $this->db );
		$snapshots = new GHCA_ACD_WPDB_Archive_Snapshot_Store( $this->db );
		$artifacts = new GHCA_ACD_WPDB_Archive_Artifact_Repository( $this->db );
		$projections = new GHCA_ACD_WPDB_Archive_Projection_Repository( $this->db );
		$projector = new GHCA_ACD_Archive_Projector( $projections );
		$uow = new GHCA_ACD_Archive_Unit_Of_Work(
			$this->db, $events, $commands, $tasks, $snapshots, $artifacts, $projector, $this->clock, $this->ids
		);
		return compact( 'events', 'commands', 'tasks', 'snapshots', 'artifacts', 'projections', 'projector', 'uow' );
	}

	/** @param array<int,string>|null $types */
	private function assert_handler_registry( ?array $types = null ): void {
		$types = $types ?: array(
			GHCA_ACD_Archive_Task_Catalog::CAPTURE_TASK_TYPE,
			GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE,
		);
		sort( $types, SORT_STRING );
		$installed = GHCA_ACD_Archive_Task_Catalog::installed_types();
		sort( $installed, SORT_STRING );
		if ( $types !== $installed ) {
			throw new UnexpectedValueException( 'archive_runtime_handler_registry_invalid' );
		}
	}

	private function assert_review_parity(): void {
		if ( ! interface_exists( 'GHCA_ACD_Archive_Evidence_Source', false )
			|| ! method_exists( 'GHCA_ACD_Archive_Evidence_Source', 'read_consistent_review_evidence' )
			|| ! method_exists( 'GHCA_ACD_Archive_Evidence_Source', 'preflight' )
			|| ! class_exists( 'GHCA_ACD_Archive_Review_Intake', false ) ) {
			throw new UnexpectedValueException( 'archive_runtime_review_capture_parity_unavailable' );
		}
	}

	private function assert_calculation_policy(): void {
		if ( ! class_exists( 'GHCA_ACD_LearnDash_Archive_Evidence_Source', false )
			|| ! defined( 'GHCA_ACD_LearnDash_Archive_Evidence_Source::CALCULATION_POLICY_KEY' )
			|| ! defined( 'GHCA_ACD_LearnDash_Archive_Evidence_Source::CALCULATION_POLICY_VERSION' )
			|| self::CALCULATION_POLICY_KEY !== GHCA_ACD_LearnDash_Archive_Evidence_Source::CALCULATION_POLICY_KEY
			|| self::CALCULATION_POLICY_VERSION !== GHCA_ACD_LearnDash_Archive_Evidence_Source::CALCULATION_POLICY_VERSION ) {
			throw new UnexpectedValueException( 'archive_runtime_calculation_policy_unapproved' );
		}
	}

	private function compose_evidence_source( array $configuration, array $versions ): GHCA_ACD_LearnDash_Archive_Evidence_Source {
		if ( ! class_exists( 'wpdb' ) ) {
			throw new UnexpectedValueException( 'archive_runtime_source_credentials_invalid' );
		}
		$source = new wpdb(
			$configuration['source']['user'],
			$configuration['source']['password'],
			$configuration['source']['database'],
			$configuration['source']['host']
		);
		if ( ! empty( $source->error ) ) {
			throw new UnexpectedValueException( 'archive_runtime_source_credentials_invalid' );
		}
		$source->suppress_errors( true );
		$source->set_charset( $source->dbh, 'utf8mb4' );
		if ( false === $source->query( "SET time_zone = '+00:00'" ) ) {
			$source->close();
			throw new UnexpectedValueException( 'archive_runtime_source_credentials_invalid' );
		}
		$session = new GHCA_ACD_WPDB_Archive_Evidence_Read_Session(
			$source,
			$configuration['evidence_descriptor'],
			array(
				'archive_connection_id' => $configuration['archive_connection']['connection_id'],
				'current_user' => $configuration['source']['account'],
			),
			$configuration['archive_tables']
		);
		return new GHCA_ACD_LearnDash_Archive_Evidence_Source( $session, $versions );
	}

	/** @return array<string,mixed> */
	private function gate_failure( string $code, int $started ): array {
		$stages = array(
			'archive_runtime_disabled' => 'flags',
			'archive_runtime_schema_mismatch' => 'schema',
			'archive_runtime_attestation_failed' => 'attestation',
			'archive_runtime_tenant_invalid' => 'tenant',
			'archive_runtime_source_credentials_invalid' => 'credentials',
			'archive_runtime_storage_invalid' => 'storage',
			'archive_runtime_cursor_key_invalid' => 'storage',
			'archive_runtime_review_capture_parity_unavailable' => 'parity',
			'archive_runtime_calculation_policy_unapproved' => 'calculation',
			'archive_runtime_handler_registry_invalid' => 'registry',
			'archive_runtime_activation_blocked' => 'activation',
		);
		if ( ! isset( $stages[ $code ] ) ) {
			return self::result( 'blocked', 'archive_runtime_load_failed', 'load', self::elapsed( $started ) );
		}
		return self::result( 'blocked', $code, $stages[ $code ], self::elapsed( $started ) );
	}

	/** @return array<string,mixed> */
	public static function worker_result( array $worker, int $duration_ms ): array {
		$status = isset( $worker['status'] ) && is_string( $worker['status'] ) ? $worker['status'] : '';
		$map = array(
			'idle' => array( 'archive_worker_idle', 'worker' ),
			'completed' => array( 'archive_worker_completed', 'worker' ),
			'retry' => array( 'archive_worker_retry', 'worker' ),
			'dead' => array( 'archive_worker_dead', 'worker' ),
			'lease_lost' => array( 'archive_worker_lease_lost', 'worker' ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return self::result( 'blocked', 'archive_runtime_internal_failure', 'worker', $duration_ms );
		}
		return self::result( $status, $map[ $status ][0], $map[ $status ][1], $duration_ms );
	}

	/** @return array<string,mixed> */
	public static function result( string $status, string $code, string $stage, int $duration_ms ): array {
		$key = $status . '/' . $code . '/' . $stage;
		if ( ! isset( self::RESULT_MAP[ $key ] ) ) {
			throw new InvalidArgumentException( 'Archive runtime result tuple is not permitted.' );
		}
		$counts = self::RESULT_MAP[ $key ];
		$result = array(
			'code' => $code,
			'claimed_count' => $counts[0],
			'completed_count' => $counts[1],
			'dead_count' => $counts[3],
			'duration_ms' => max( 0, $duration_ms ),
			'lease_lost_count' => $counts[4],
			'retry_count' => $counts[2],
			'stage' => $stage,
			'status' => $status,
		);
		if ( array_keys( $result ) !== self::RESULT_KEYS ) {
			throw new LogicException( 'Archive runtime result grammar is invalid.' );
		}
		return $result;
	}

	public static function encode_result( array $result ): string {
		if ( array_keys( $result ) !== self::RESULT_KEYS ) {
			throw new UnexpectedValueException( 'Archive runtime result grammar is invalid.' );
		}
		return GHCA_ACD_Archive_Canonical_JSON::encode( $result );
	}

	private static function elapsed( int $started ): int {
		$ended = hrtime( true );
		if ( $ended < $started ) {
			return PHP_INT_MAX;
		}
		$difference = $ended - $started;
		if ( $difference < 0 ) {
			return PHP_INT_MAX;
		}
		return intdiv( $difference, 1000000 );
	}
}
