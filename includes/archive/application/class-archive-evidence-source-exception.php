<?php

/** Closed, sanitized failure surface for an injected evidence source. */
final class GHCA_ACD_Archive_Evidence_Source_Exception extends RuntimeException {
	const CATEGORY_INVALID             = 'invalid';
	const CATEGORY_RETRYABLE           = 'retryable';
	const CATEGORY_OPERATIONAL_BLOCKED = 'operational_blocked';
	const CATEGORY_INTEGRITY           = 'integrity';

	private const TUPLES = array(
		self::CATEGORY_INVALID => array(
			'archive_build_binding_invalid' => array( 'authoritative_load', 'command_prepare' ),
			'archive_snapshot_invalid' => array( 'source_validate', 'snapshot_prepare' ),
			'archive_evidence_prohibited' => array( 'source_validate' ),
			'archive_source_drift' => array( 'fingerprint_compare' ),
			'archive_evidence_incomplete' => array( 'pre_query_limit', 'normalize_limit', 'snapshot_byte_limit' ),
			'archive_certificate_invalid' => array( 'certificate_gate' ),
		),
		self::CATEGORY_RETRYABLE => array(
			'archive_source_read_failed' => array( 'transaction_start', 'source_query', 'transaction_commit' ),
			'archive_source_query_failed' => array( 'source_query' ),
		),
		self::CATEGORY_OPERATIONAL_BLOCKED => array(
			'archive_source_transaction_failed' => array( 'transaction_rollback', 'connection_close' ),
			'archive_source_schema_unsupported' => array( 'source_preflight' ),
			'task_handler_failed' => array(
				'task_validation', 'authoritative_load', 'command_prepare', 'source_validate',
				'snapshot_prepare', 'fingerprint_compare', 'transaction_start', 'source_query',
				'transaction_commit', 'transaction_rollback', 'connection_close', 'pre_query_limit',
				'normalize_limit', 'snapshot_byte_limit', 'source_preflight', 'certificate_gate',
				'authoritative_recovery', 'snapshot_commit',
			),
		),
		self::CATEGORY_INTEGRITY => array(
			'archive_immutable_conflict' => array( 'authoritative_recovery', 'snapshot_commit' ),
		),
	);

	const MESSAGES = array(
		'archive_build_binding_invalid'       => 'The archive build bindings are invalid.',
		'archive_snapshot_invalid'            => 'The archive snapshot is invalid.',
		'archive_evidence_prohibited'         => 'The evidence source contains a prohibited value.',
		'archive_source_drift'                => 'The archive evidence source changed.',
		'archive_source_read_failed'          => 'The archive evidence source could not be read.',
		'archive_source_transaction_failed'   => 'The archive evidence transaction could not be closed safely.',
		'archive_source_query_failed'         => 'The archive evidence query failed.',
		'archive_evidence_incomplete'         => 'The required archive evidence is incomplete or exceeds its approved limit.',
		'archive_source_schema_unsupported'   => 'The archive evidence source schema is not supported.',
		'archive_certificate_invalid'         => 'Required certificate evidence is not available in this slice.',
		'archive_immutable_conflict'          => 'The retained archive evidence conflicts with the requested outcome.',
		'task_handler_failed'                 => 'The task handler failed.',
	);

	/** @var string */
	private $failure_category;
	/** @var string */
	private $reason;
	/** @var string */
	private $operation_context;

	public function __construct( string $category, string $reason, string $context ) {
		if ( ! isset( self::TUPLES[ $category ][ $reason ] )
			|| ! in_array( $context, self::TUPLES[ $category ][ $reason ], true ) ) {
			throw new InvalidArgumentException( 'Evidence-source failure contract is invalid.' );
		}
		parent::__construct( self::MESSAGES[ $reason ] );
		$this->failure_category = $category;
		$this->reason           = $reason;
		$this->operation_context = $context;
	}

	public function category(): string { return $this->failure_category; }
	public function reason_code(): string { return $this->reason; }
	public function operation_context(): string { return $this->operation_context; }
}
