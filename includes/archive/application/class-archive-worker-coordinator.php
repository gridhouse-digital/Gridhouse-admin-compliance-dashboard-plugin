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
		'task_prepared_result_invalid' => 'The prepared ledger result is invalid.',
		'task_outcome_commit_failed' => 'The authoritative task outcome could not be committed.',
		'task_attempts_exhausted' => 'The durable task exhausted its maximum attempts.',
		'archive_build_binding_invalid' => 'The archive build bindings are invalid.',
		'archive_evidence_incomplete' => 'The archive evidence is incomplete.',
		'archive_certificate_invalid' => 'The archive certificate is invalid.',
		'archive_source_drift' => 'The archive evidence source changed.',
		'archive_snapshot_invalid' => 'The archive snapshot is invalid.',
		'archive_ledger_invalid' => 'The archive ledger is invalid.',
		'archive_packet_invalid' => 'The archive packet is invalid.',
		'archive_verification_failed' => 'The archive verification failed.',
		'archive_immutable_conflict' => 'The retained archive evidence conflicts with the requested outcome.',
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
	/** @var GHCA_ACD_Archive_Build_Coordinator|null */
	private $build_coordinator;
	/** @var GHCA_ACD_Archive_Ledger_Task_Handler|null */
	private $ledger_validator;
	/** @var array<int,string> */
	private $installed_types;

	/** @param array<string,callable> $handlers */
	public function __construct( GHCA_ACD_WPDB_Archive_Task_Store $tasks, GHCA_ACD_Archive_Clock $clock, GHCA_ACD_Archive_Id_Generator $ids, string $lease_owner, array $handlers, $outcome_committer = null, ?GHCA_ACD_Archive_Build_Coordinator $build_coordinator = null, ?GHCA_ACD_Archive_Ledger_Task_Handler $ledger_validator = null ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $lease_owner ) ) {
			throw new InvalidArgumentException( 'Worker lease owner must be a 32-character lowercase hexadecimal identifier.' );
		}
		foreach ( $handlers as $task_type => $handler ) {
			if ( ! is_string( $task_type ) || ! in_array( $task_type, GHCA_ACD_WPDB_Archive_Task_Store::TASK_TYPES, true ) || ! is_callable( $handler ) ) {
				throw new InvalidArgumentException( 'Worker handler map is invalid.' );
			}
		}
		if ( null !== $outcome_committer && ! is_callable( $outcome_committer ) ) {
			throw new InvalidArgumentException( 'Worker outcome committer is invalid.' );
		}
		if ( null === $ledger_validator && isset( $handlers[ GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE ] )
			&& $handlers[ GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE ] instanceof GHCA_ACD_Archive_Ledger_Task_Handler ) {
			$ledger_validator = $handlers[ GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE ];
		}
		if ( isset( $handlers[ GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE ] )
			&& ( null === $ledger_validator || null === $build_coordinator ) ) {
			throw new InvalidArgumentException( 'The installed ledger handler requires its fenced Build Coordinator.' );
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
		$this->build_coordinator = $build_coordinator;
		$this->ledger_validator  = $ledger_validator;
		$this->installed_types   = array_keys( $handlers );
	}

	/** @return array<string,mixed> */
	public function run_once(): array {
		if ( array() === $this->installed_types ) {
			return array( 'status' => 'idle' );
		}
		$now   = $this->clock->now_gmt();
		$token = $this->ids->generate();
		$task  = $this->tasks->reclaim_expired( $this->lease_owner, $token, $now, $this->installed_types );
		if ( null === $task ) {
			$task = $this->tasks->claim_available( $this->lease_owner, $token, $now, $this->installed_types );
		}
		if ( null === $task ) {
			return array( 'status' => 'idle' );
		}
		if ( ! empty( $task['exhausted'] ) && GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE !== $task['task_type'] ) {
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

		$heartbeat = $this->heartbeat_callback( $task, $token );

		if ( GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE === $task['task_type'] ) {
			return $this->run_ledger( $task, $token, $heartbeat );
		}

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
			if ( ! is_callable( $this->outcome_committer ) ) {
				throw new UnexpectedValueException( 'No test outcome committer is installed.' );
			}
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

	/**
	 * @param array<string,mixed> $task
	 * @return array<string,mixed>
	 */
	private function run_ledger( array $task, string $token, callable $heartbeat ): array {
		$handler = $this->handlers[ GHCA_ACD_Archive_Task_Catalog::LEDGER_TASK_TYPE ];
		$validator = $this->ledger_validator;
		$key = GHCA_ACD_Archive_Digester::task_outcome( array(
			'logical_outcome'     => 'completed',
			'task_id'             => $task['task_id'],
			'task_schema_version' => GHCA_ACD_WPDB_Archive_Task_Store::TASK_SCHEMA_VERSION,
		) );
		$fence = array( 'task_id' => $task['task_id'], 'lease_owner' => $this->lease_owner, 'lease_token' => $token );

		try {
			$decision = $this->build_coordinator->recover_failure( $task, $key, $fence );
			if ( null !== $decision ) {
				return $this->finish_authoritative_decision( $task, $token, $validator, $key, $fence, $decision );
			}
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_outcome_fence_failed', 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'recovery' );
		} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'authoritative_open' );
		}

		try {
			$prepared = call_user_func( $handler, $task, $heartbeat );
			$prepared = $validator->validate_prepared_result( $task, $prepared );
		} catch ( UnexpectedValueException $error ) {
			return $this->dead( $task, 'task_prepared_result_invalid' );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_heartbeat_fence_failed', 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'handler' );
		} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $error ) {
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'handler' );
		} catch ( Throwable $error ) {
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'handler' );
		}

		try {
			return $this->commit_and_complete_ledger( $task, $token, $prepared, $key, $fence );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $error ) {
			if ( $this->is_fence_loss( $error, array( 'task_outcome_fence_failed', 'task_lease_lost', 'task_completion_fence_failed' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'outcome' );
		} catch ( Throwable $error ) {
			return $this->dispose_ledger_failure( $task, $token, $handler, $validator, $key, $fence, $error, 'outcome' );
		}
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $prepared
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	private function commit_and_complete_ledger( array $task, string $token, array $prepared, string $key, array $fence ): array {
		$this->tasks->assert_live_lease( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
		$response = $this->build_coordinator->record_ledger( $task, $prepared, $key, $fence );
		$outcome = array( 'logical_outcome' => 'completed', 'outcome' => array( 'result_code' => 'committed' ) );
		$this->assert_handler_outcome( $outcome );
		$this->tasks->complete( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
		return array( 'status' => 'completed', 'task_id' => $task['task_id'], 'response' => $response );
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @return array<string,mixed>
	 */
	private function dispose_ledger_failure( array $task, string $token, callable $handler, GHCA_ACD_Archive_Ledger_Task_Handler $validator, string $key, array $fence, Throwable $error, string $context ): array {
		$classification = $this->classify_ledger_failure( $error, $context );
		$final_attempt  = (int) $task['attempt_count'] >= GHCA_ACD_WPDB_Archive_Task_Store::MAX_ATTEMPTS;

		if ( ! $final_attempt ) {
			if ( 'permanent' !== $classification['kind'] ) {
				return $this->retry_or_dead( $task, $classification['task_code'] );
			}
		} else {
			try {
				$decision = $this->build_coordinator->recover_failure( $task, $key, $fence );
				if ( null !== $decision ) {
					return $this->finish_authoritative_decision( $task, $token, $validator, $key, $fence, $decision );
				}
			} catch ( GHCA_ACD_Archive_Persistence_Exception $recovery_error ) {
				if ( $this->is_fence_loss( $recovery_error, array( 'task_outcome_fence_failed', 'task_lease_lost', 'task_completion_fence_failed' ) ) ) {
					return $this->lease_lost( $task['task_id'] );
				}
				$classification = $this->classify_ledger_failure( $recovery_error, 'recovery' );
			} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $recovery_error ) {
				$context = 'authoritative_open';
				$classification = $this->classify_ledger_failure( $recovery_error, 'authoritative_open' );
			} catch ( Throwable $recovery_error ) {
				$classification = $this->classify_ledger_failure( $recovery_error, 'recovery' );
			}

			if ( 'retryable' === $classification['kind'] && 'authoritative_open' !== $context ) {
				try {
					$recovery_heartbeat = $this->heartbeat_callback( $task, $token );
					$prepared = call_user_func( $handler, $task, $recovery_heartbeat );
					$prepared = $validator->validate_prepared_result( $task, $prepared );
					return $this->commit_and_complete_ledger( $task, $token, $prepared, $key, $fence );
				} catch ( UnexpectedValueException $recovery_error ) {
					return $this->dead( $task, 'task_prepared_result_invalid' );
				} catch ( GHCA_ACD_Archive_Persistence_Exception $recovery_error ) {
					if ( $this->is_fence_loss( $recovery_error, array( 'task_heartbeat_fence_failed', 'task_outcome_fence_failed', 'task_lease_lost', 'task_completion_fence_failed' ) ) ) {
						return $this->lease_lost( $task['task_id'] );
					}
					$classification = $this->classify_ledger_failure( $recovery_error, 'recovery' );
				} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $recovery_error ) {
					$context = 'handler';
					$classification = $this->classify_ledger_failure( $recovery_error, 'handler' );
				} catch ( Throwable $recovery_error ) {
					$classification = $this->classify_ledger_failure( $recovery_error, 'handler' );
				}
				try {
					$decision = $this->build_coordinator->recover_failure( $task, $key, $fence );
					if ( null !== $decision ) {
						return $this->finish_authoritative_decision( $task, $token, $validator, $key, $fence, $decision );
					}
				} catch ( GHCA_ACD_Archive_Persistence_Exception $recovery_error ) {
					if ( $this->is_fence_loss( $recovery_error, array( 'task_outcome_fence_failed', 'task_lease_lost', 'task_completion_fence_failed' ) ) ) {
						return $this->lease_lost( $task['task_id'] );
					}
					$classification = $this->classify_ledger_failure( $recovery_error, 'recovery' );
				} catch ( GHCA_ACD_Archive_Artifact_Store_Exception $recovery_error ) {
					$context = 'authoritative_open';
					$classification = $this->classify_ledger_failure( $recovery_error, 'authoritative_open' );
				} catch ( Throwable $recovery_error ) {
					$classification = $this->classify_ledger_failure( $recovery_error, 'recovery' );
				}
			}

			if ( 'blocked' === $classification['kind'] || ( 'retryable' === $classification['kind'] && 'authoritative_open' === $context ) ) {
				return $this->dead( $task, $classification['task_code'] );
			}
		}

		$lifecycle_code = 'permanent' === $classification['kind']
			? $classification['lifecycle_code']
			: 'archive_build_attempts_exhausted';
		try {
			$this->tasks->assert_live_lease( $task['task_id'], $this->lease_owner, $token, $this->clock->now_gmt() );
			$decision = $this->build_coordinator->fail_archive(
				$task,
				$lifecycle_code,
				$key,
				$fence,
				'archive_immutable_conflict' === $lifecycle_code && in_array( $context, array( 'authoritative_open', 'recovery' ), true )
			);
			return $this->finish_authoritative_decision( $task, $token, $validator, $key, $fence, $decision );
		} catch ( GHCA_ACD_Archive_Persistence_Exception $commit_error ) {
			if ( $this->is_fence_loss( $commit_error, array( 'task_outcome_fence_failed', 'task_lease_lost' ) ) ) {
				return $this->lease_lost( $task['task_id'] );
			}
			return $final_attempt ? $this->dead( $task, 'task_outcome_commit_failed' ) : $this->retry_or_dead( $task, 'task_outcome_commit_failed' );
		}
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,string> $fence
	 * @param array<string,mixed> $decision
	 * @return array<string,mixed>
	 */
	private function finish_authoritative_decision( array $task, string $token, GHCA_ACD_Archive_Ledger_Task_Handler $validator, string $key, array $fence, array $decision ): array {
		if ( isset( $decision['decision'] ) && 'materialized' === $decision['decision'] ) {
			$prepared = $validator->recover_authoritative_prepared( $task );
			if ( null === $prepared ) {
				throw new GHCA_ACD_Archive_Persistence_Exception(
					GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
					'task_outcome_commit_failed',
					'The authoritative task outcome could not be committed.'
				);
			}
			return $this->commit_and_complete_ledger( $task, $token, $prepared, $key, $fence );
		}
		if ( isset( $decision['decision'], $decision['reason_code'] ) && 'failed' === $decision['decision']
			&& in_array( $decision['reason_code'], GHCA_ACD_Archive_Build_Coordinator::FAILURE_CODES, true ) ) {
			$task_code = 'archive_build_attempts_exhausted' === $decision['reason_code']
				? 'task_attempts_exhausted'
				: $decision['reason_code'];
			return $this->dead( $task, $task_code );
		}
		throw new GHCA_ACD_Archive_Persistence_Exception(
			GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED,
			'task_outcome_commit_failed',
			'The authoritative task outcome could not be committed.'
		);
	}

	/** @param array<string,mixed> $task */
	private function heartbeat_callback( array $task, string $token ): callable {
		$started = strtotime( $this->clock->now_gmt() );
		if ( false === $started ) {
			throw new LogicException( 'Worker heartbeat start time is invalid.' );
		}
		$last_heartbeat = $started;
		return function () use ( $task, $token, &$last_heartbeat ): void {
			$now = $this->clock->now_gmt();
			$now_epoch = strtotime( $now );
			if ( false === $now_epoch ) {
				throw new LogicException( 'Worker heartbeat time is invalid.' );
			}
			if ( $now_epoch - $last_heartbeat < GHCA_ACD_WPDB_Archive_Task_Store::HEARTBEAT_SECONDS ) {
				return;
			}
			$this->tasks->heartbeat(
				$task['task_id'],
				$this->lease_owner,
				$token,
				$now,
				GHCA_ACD_WPDB_Archive_Task_Store::lease_until( $now )
			);
			$last_heartbeat = $now_epoch;
		};
	}

	/** @return array{kind:string,task_code:string,lifecycle_code:?string} */
	private function classify_ledger_failure( Throwable $error, string $context ): array {
		$reason = $error instanceof GHCA_ACD_Archive_Persistence_Exception || $error instanceof GHCA_ACD_Archive_Artifact_Store_Exception
			? $error->reason_code()
			: '';
		if ( $error instanceof GHCA_ACD_Archive_Persistence_Exception ) {
			$category = $error->category();
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND === $category
				&& in_array( $context, array( 'handler', 'recovery', 'outcome' ), true )
				&& in_array( $reason, array( 'archive_build_binding_invalid', 'archive_snapshot_invalid', 'archive_ledger_invalid' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => $reason, 'lifecycle_code' => $reason );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $category
				&& 'outcome' === $context && 'archive_immutable_conflict' === $reason ) {
				return array( 'kind' => 'permanent', 'task_code' => $reason, 'lifecycle_code' => $reason );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $error->category()
				&& 'outcome' === $context
				&& in_array( $reason, array(
					'artifact_binding_invalid', 'artifact_identity_invalid', 'ledger_binding_invalid', 'ledger_snapshot_binding_mismatch',
					'artifact_descriptor_invalid', 'artifact_digest_invalid', 'artifact_role_type_invalid', 'unsupported_artifact_schema_version',
					'artifact_storage_key_invalid', 'artifact_filename_invalid', 'side_ledger_item_count_exceeded', 'ledger_item_count_mismatch',
					'ledger_item_schema_invalid', 'ledger_duplicate', 'ledger_gap', 'unsupported_ledger_item_schema_version',
					'ledger_item_canonical_invalid', 'ledger_manifest_digest_mismatch', 'artifact_contradictory_duplicate',
					'artifact_retained_binding_mismatch', 'artifact_authoritative_binding_mismatch', 'ledger_item_digest_mismatch',
				), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_immutable_conflict', 'lifecycle_code' => 'archive_immutable_conflict' );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND === $category
				&& 'outcome' === $context
				&& in_array( $reason, array( 'artifact_binding_invalid', 'artifact_identity_invalid', 'ledger_binding_invalid', 'ledger_snapshot_binding_mismatch' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_build_binding_invalid', 'lifecycle_code' => 'archive_build_binding_invalid' );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND === $category
				&& 'outcome' === $context
				&& in_array( $reason, array( 'artifact_descriptor_invalid', 'artifact_digest_invalid', 'artifact_role_type_invalid', 'unsupported_artifact_schema_version', 'artifact_storage_key_invalid', 'artifact_filename_invalid', 'side_ledger_item_count_exceeded', 'ledger_item_count_mismatch', 'ledger_item_schema_invalid', 'ledger_duplicate', 'ledger_gap', 'unsupported_ledger_item_schema_version', 'ledger_item_canonical_invalid', 'ledger_manifest_digest_mismatch' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_ledger_invalid', 'lifecycle_code' => 'archive_ledger_invalid' );
			}
			if ( in_array( $context, array( 'handler', 'recovery', 'outcome' ), true )
				&& ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INVALID_COMMAND === $category
					|| ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTEGRITY_BLOCKED === $category && 'snapshot_retained_binding_mismatch' === $reason ) )
				&& in_array( $reason, array( 'unsupported_snapshot_schema_version', 'unsupported_snapshot_canonical_version', 'snapshot_canonical_invalid', 'snapshot_schema_invalid', 'snapshot_retained_binding_mismatch' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_snapshot_invalid', 'lifecycle_code' => 'archive_snapshot_invalid' );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL === $category
				&& in_array( $context, array( 'handler', 'recovery', 'outcome' ), true )
				&& 'snapshot_lookup_failed' === $reason ) {
				return array( 'kind' => 'retryable', 'task_code' => 'outcome' === $context ? 'task_outcome_commit_failed' : 'task_handler_failed', 'lifecycle_code' => null );
			}
			if ( GHCA_ACD_Archive_Persistence_Exception::CATEGORY_INTERNAL === $category
				&& in_array( $context, array( 'recovery', 'outcome' ), true )
				&& in_array( $reason, array( 'artifact_insert_failed', 'artifact_lookup_failed', 'artifact_duplicate_lookup_failed', 'ledger_item_insert_failed', 'ledger_item_load_failed' ), true ) ) {
				$task_code = 'outcome' === $context ? 'task_outcome_commit_failed' : 'task_handler_failed';
				return array( 'kind' => 'retryable', 'task_code' => $task_code, 'lifecycle_code' => null );
			}
		}
		if ( $error instanceof GHCA_ACD_Archive_Artifact_Store_Exception ) {
			if ( 'authoritative_open' === $context
				&& in_array( $reason, array( 'artifact_size_mismatch', 'artifact_digest_mismatch', 'artifact_symlink_rejected' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_immutable_conflict', 'lifecycle_code' => 'archive_immutable_conflict' );
			}
			if ( 'handler' === $context && in_array( $reason, array( 'artifact_size_exceeded', 'artifact_media_invalid', 'artifact_size_mismatch', 'artifact_digest_mismatch' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_ledger_invalid', 'lifecycle_code' => 'archive_ledger_invalid' );
			}
			if ( 'handler' === $context && in_array( $reason, array( 'artifact_commit_collision', 'artifact_immutable_mismatch' ), true ) ) {
				return array( 'kind' => 'permanent', 'task_code' => 'archive_immutable_conflict', 'lifecycle_code' => 'archive_immutable_conflict' );
			}
			if ( 'authoritative_open' === $context && 'artifact_open_failed' === $reason ) {
				return array( 'kind' => 'retryable', 'task_code' => 'task_handler_failed', 'lifecycle_code' => null );
			}
			if ( 'handler' === $context && in_array( $reason, array( 'artifact_open_failed', 'artifact_directory_create_failed', 'artifact_write_failed', 'artifact_staging_collision', 'artifact_commit_failed' ), true ) ) {
				return array( 'kind' => 'retryable', 'task_code' => 'task_handler_failed', 'lifecycle_code' => null );
			}
			if ( 'handler' === $context && in_array( $reason, array( 'artifact_key_invalid', 'artifact_path_escape', 'artifact_symlink_rejected', 'artifact_atomic_commit_unsupported', 'artifact_permissions_unsafe' ), true ) ) {
				return array( 'kind' => 'blocked', 'task_code' => 'task_handler_failed', 'lifecycle_code' => null );
			}
		}
		return array( 'kind' => 'blocked', 'task_code' => 'outcome' === $context ? 'task_outcome_commit_failed' : 'task_handler_failed', 'lifecycle_code' => null );
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
