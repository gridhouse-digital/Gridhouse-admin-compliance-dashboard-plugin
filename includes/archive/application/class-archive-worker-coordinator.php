<?php

/** Directly callable, dark-mode coordinator for one durable task. */
final class GHCA_ACD_Archive_Worker_Coordinator {
	const OUTCOME_MAX_DEPTH        = 1;
	const OUTCOME_MAX_VALUES       = 1;
	const OUTCOME_VALUE_MAX_BYTES  = 9;
	const OUTCOME_MAX_BYTES        = 27;
	const FAILURE_MESSAGES = array(
		'task_schema_unsupported' => 'The retained task schema version is not supported.',
		'task_payload_invalid' => 'The retained task payload is invalid.',
		'task_type_unsupported' => 'The retained task type is not supported.',
		'task_handler_failed' => 'The task handler failed.',
		'task_outcome_commit_failed' => 'The authoritative task outcome could not be committed.',
		'task_attempts_exhausted' => 'The durable task exhausted its maximum attempts.',
	);

	/** @var GHCA_ACD_WPDB_Archive_Task_Store */
	private $tasks;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;
	/** @var GHCA_ACD_Archive_Id_Generator */
	private $ids;
	/** @var string */
	private $lease_owner;
	/** @var array<string,callable> */
	private $handlers;
	/** @var callable */
	private $outcome_committer;

	/** @param array<string,callable> $handlers */
	public function __construct( GHCA_ACD_WPDB_Archive_Task_Store $tasks, GHCA_ACD_Archive_Clock $clock, GHCA_ACD_Archive_Id_Generator $ids, string $lease_owner, array $handlers, callable $outcome_committer ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $lease_owner ) ) {
			throw new InvalidArgumentException( 'Worker lease owner must be a 32-character lowercase hexadecimal identifier.' );
		}
		foreach ( $handlers as $task_type => $handler ) {
			if ( ! is_string( $task_type ) || ! in_array( $task_type, GHCA_ACD_WPDB_Archive_Task_Store::TASK_TYPES, true ) || ! is_callable( $handler ) ) {
				throw new InvalidArgumentException( 'Worker handler map is invalid.' );
			}
		}
		foreach ( self::FAILURE_MESSAGES as $code => $message ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $code ) || strlen( $message ) > 512 || 1 !== preg_match( '//u', $message ) ) {
				throw new LogicException( 'Worker failure catalog is invalid.' );
			}
		}
		$this->tasks             = $tasks;
		$this->clock             = $clock;
		$this->ids               = $ids;
		$this->lease_owner       = $lease_owner;
		$this->handlers          = $handlers;
		$this->outcome_committer = $outcome_committer;
	}

	/** @return array<string,mixed> */
	public function run_once(): array {
		$now   = $this->clock->now_gmt();
		$token = $this->ids->generate();
		$task  = $this->tasks->reclaim_expired( $this->lease_owner, $token, $now );
		if ( null === $task ) {
			$task = $this->tasks->claim_available( $this->lease_owner, $token, $now );
		}
		if ( null === $task ) {
			return array( 'status' => 'idle' );
		}
		if ( ! empty( $task['exhausted'] ) ) {
			return $this->dead( $task, 'task_attempts_exhausted' );
		}

		try {
			$task = $this->tasks->load_claimed( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			throw $error;
		}

		if ( (string) GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION !== (string) $task['task_schema_version'] ) {
			return $this->dead( $task, 'task_schema_unsupported' );
		}
		try {
			$task = $this->tasks->validate_claimed_v1( $task );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			$reason = $error->reason_code();
			if ( in_array( $reason, array( 'task_payload_invalid', 'task_type_unsupported' ), true ) ) {
				return $this->dead( $task, $reason );
			}
			throw $error;
		}
		if ( ! isset( $this->handlers[ $task['task_type'] ] ) ) {
			return $this->dead( $task, 'task_type_unsupported' );
		}

		$heartbeat = function () use ( $task, $token ): void {
			$now = $this->clock->now_gmt();
			$this->tasks->heartbeat(
				$task['task_id'],
				$this->lease_owner,
				$token,
				$now,
				GHCA_ACD_WPDB_Archive_Task_Store::lease_until( $now )
			);
		};

		try {
			$outcome = call_user_func( $this->handlers[ $task['task_type'] ], $task, $heartbeat );
			$this->assert_handler_outcome( $outcome );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_heartbeat_fence_failed', 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $this->retry_or_dead( $task, 'task_handler_failed' );
		} catch ( Throwable $error ) {
			return $this->retry_or_dead( $task, 'task_handler_failed' );
		}

		try {
			$key = GHCA_ACD_Archive_Digester::task_outcome( array(
				'logical_outcome'    => $outcome['logical_outcome'],
				'task_id'            => $task['task_id'],
				'task_schema_version' => GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION,
			) );
			$this->tasks->assert_live_lease( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
			$fence = array( 'task_id' => $task['task_id'], 'lease_owner' => $this->lease_owner, 'lease_token' => $token );
			$response = call_user_func( $this->outcome_committer, $task, $outcome['logical_outcome'], $outcome['outcome'], $key, $fence );
			if ( ! is_array( $response ) ) {
				throw new UnexpectedValueException( 'The outcome committer did not return a response document.' );
			}
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_outcome_fence_failed', 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $this->retry_or_dead( $task, 'task_outcome_commit_failed' );
		} catch ( Throwable $error ) {
			return $this->retry_or_dead( $task, 'task_outcome_commit_failed' );
		}

		try {
			$this->tasks->complete( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_completion_fence_failed' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			throw $error;
		}
		return array( 'status' => 'completed', 'task_id' => $task['task_id'], 'response' => $response );
	}

	/** @param mixed $outcome */
	private function assert_handler_outcome( $outcome ): void {
		if ( ! is_array( $outcome )
			|| array_keys( $outcome ) !== array( 'logical_outcome', 'outcome' )
			|| 'completed' !== $outcome['logical_outcome']
			|| ! is_array( $outcome['outcome'] ) ) {
			throw new UnexpectedValueException( 'The task handler outcome is invalid.' );
		}
		try {
			$canonical = GHCA_ACD_Archive_Canonical_JSON::encode( $outcome['outcome'] );
		} catch ( Throwable $error ) {
			throw new UnexpectedValueException( 'The task handler outcome is invalid.' );
		}
		if ( self::OUTCOME_MAX_VALUES !== count( $outcome['outcome'] )
			|| array_keys( $outcome['outcome'] ) !== array( 'result_code' )
			|| ! is_string( $outcome['outcome']['result_code'] )
			|| strlen( $outcome['outcome']['result_code'] ) > self::OUTCOME_VALUE_MAX_BYTES
			|| 'committed' !== $outcome['outcome']['result_code']
			|| self::OUTCOME_MAX_BYTES !== strlen( $canonical ) ) {
			throw new UnexpectedValueException( 'The task handler outcome is invalid.' );
		}
	}

	/** @param array<string,mixed> $task @return array<string,mixed> */
	private function retry_or_dead( array $task, string $code ): array {
		if ( (int) $task['attempt_count'] < GHCA_ACD_WPDB_Archive_Task_Store::MAX_ATTEMPTS ) {
			try {
				$this->tasks->retry( $task['task_id'], $this->lease_owner, $task['lease_token'], $code, self::FAILURE_MESSAGES[ $code ], $this->clock->now_gmt() );
			} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
				if ( $this->is_fence_loss( $error, array( 'task_retry_fence_failed' ) ) ) {
					return $this->lease_lost( $task['task_id'] );
				}
				throw $error;
			}
			return array( 'status' => 'retry', 'task_id' => $task['task_id'], 'reason_code' => $code );
		}
		return $this->dead( $task, 'task_attempts_exhausted' );
	}

	/** @param array<string,mixed> $task @return array<string,mixed> */
	private function dead( array $task, string $code ): array {
		try {
			$this->tasks->dead_letter( $task['task_id'], $this->lease_owner, $task['lease_token'], $code, self::FAILURE_MESSAGES[ $code ], $this->clock->now_gmt() );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_dead_letter_fence_failed' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			throw $error;
		}
		return array( 'status' => 'dead', 'task_id' => $task['task_id'], 'reason_code' => $code );
	}

	/** @param array<int,string> $reasons */
	private function is_fence_loss( GHCA_ACD_Archive_Persistence_Exception $error, array $reasons ): bool {
		return GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
			&& in_array( $error->reason_code(), $reasons, true );
	}

	/** @return array<string,string> */
	private function lease_lost( string $task_id ): array {
		return array( 'status' => 'lease_lost', 'task_id' => $task_id, 'reason_code' => 'task_lease_lost' );
	}
}
