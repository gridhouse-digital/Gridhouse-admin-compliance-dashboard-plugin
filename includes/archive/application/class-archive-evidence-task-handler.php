<?php

/** Fake-source-capable, side-effect-free preparation handler for capture_evidence. */
final class GHCA_ACD_Archive_Evidence_Task_Handler {
	const LIMITS = array(
		'maximum_assets' => 10000,
		'maximum_queries' => 32,
		'maximum_rows' => 10000,
		'maximum_snapshot_bytes' => 1048576,
		'maximum_transaction_milliseconds' => 2000,
		'maximum_values' => 10000,
	);

	/** @var GHCA_ACD_Archive_Evidence_Source */
	private $source;
	/** @var GHCA_ACD_Archive_Evidence_Result_Validator */
	private $validator;
	/** @var GHCA_ACD_Archive_Evidence_Snapshot_Preparer */
	private $preparer;
	/** @var GHCA_ACD_Archive_Clock */
	private $clock;

	public function __construct(
		GHCA_ACD_Archive_Evidence_Source $source,
		GHCA_ACD_Archive_Evidence_Result_Validator $validator,
		GHCA_ACD_Archive_Evidence_Snapshot_Preparer $preparer,
		GHCA_ACD_Archive_Clock $clock
	) {
		$this->source = $source;
		$this->validator = $validator;
		$this->preparer = $preparer;
		$this->clock = $clock;
	}

	/** Compatibility with the handler map; the coordinator supplies authoritative context through prepare(). */
	public function __invoke( array $task, callable $heartbeat ): array {
		throw new LogicException( 'Capture evidence requires its authoritative preparation context.' );
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function prepare( array $task, array $context, callable $heartbeat ): array {
		$heartbeat();
		$evidence = $this->source->read_consistent_evidence( $context['capture_identity'], self::LIMITS, $heartbeat );
		$heartbeat();
		$evidence = $this->validator->validate( $evidence, $context['capture_identity'] );
		$fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $evidence );
		if ( ! hash_equals( $context['capture_identity']['reviewed_source_fingerprint'], $fingerprint ) ) {
			return array(
				'captured_source_fingerprint' => $fingerprint,
				'decision' => 'source_drift',
			);
		}
		$prepared = $this->preparer->prepare( $evidence, $context, $fingerprint, $this->clock->now_gmt() );
		return array(
			'byte_count' => $prepared['byte_count'],
			'captured_source_fingerprint' => $prepared['captured_source_fingerprint'],
			'decision' => 'snapshot',
			'snapshot_digest' => $prepared['snapshot_digest'],
			'snapshot_document' => $prepared['snapshot_document'],
		);
	}

	/** @param array<string,mixed> $context @param mixed $prepared @return array<string,mixed> */
	public function validate_prepared_result( array $context, $prepared ): array {
		if ( ! is_array( $prepared ) || ! isset( $prepared['decision'], $prepared['captured_source_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $prepared['captured_source_fingerprint'] ) ) {
			throw new UnexpectedValueException( 'The prepared evidence result is invalid.' );
		}
		if ( 'source_drift' === $prepared['decision'] ) {
			if ( array_keys( $prepared ) !== array( 'captured_source_fingerprint', 'decision' )
				|| hash_equals( $context['capture_identity']['reviewed_source_fingerprint'], $prepared['captured_source_fingerprint'] ) ) {
				throw new UnexpectedValueException( 'The prepared evidence result is invalid.' );
			}
			return $prepared;
		}
		if ( 'snapshot' !== $prepared['decision'] || array_keys( $prepared ) !== array(
			'byte_count', 'captured_source_fingerprint', 'decision', 'snapshot_digest', 'snapshot_document',
		) || ! is_int( $prepared['byte_count'] ) || $prepared['byte_count'] < 1
			|| $prepared['byte_count'] > GHCA_ACD_Archive_Canonical_JSON::MAX_BYTES
			|| ! is_string( $prepared['snapshot_digest'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $prepared['snapshot_digest'] )
			|| ! is_array( $prepared['snapshot_document'] )
			|| ! isset( $prepared['snapshot_document']['case'] ) || ! is_array( $prepared['snapshot_document']['case'] )
			|| ! isset( $prepared['snapshot_document']['case']['archive_id'], $prepared['snapshot_document']['case']['snapshot_id'], $prepared['snapshot_document']['case']['stream_id'] )
			|| $prepared['snapshot_document']['case']['archive_id'] !== $context['archive_id']
			|| $prepared['snapshot_document']['case']['snapshot_id'] !== $context['snapshot_id']
			|| $prepared['snapshot_document']['case']['stream_id'] !== $context['stream_id']
			|| ! hash_equals( $context['capture_identity']['reviewed_source_fingerprint'], $prepared['captured_source_fingerprint'] ) ) {
			throw new UnexpectedValueException( 'The prepared evidence result is invalid.' );
		}
		try {
			if ( ! hash_equals( $prepared['snapshot_digest'], GHCA_ACD_Archive_Digester::snapshot( $prepared['snapshot_document'] ) )
				|| $prepared['byte_count'] !== strlen( GHCA_ACD_Archive_Canonical_JSON::encode( $prepared['snapshot_document'] ) ) ) {
				throw new UnexpectedValueException( 'The prepared evidence result is invalid.' );
			}
		} catch ( UnexpectedValueException $error ) {
			throw $error;
		} catch ( Throwable $error ) {
			throw new UnexpectedValueException( 'The prepared evidence result is invalid.' );
		}
		return GHCA_ACD_Archive_Canonical_JSON::detach( $prepared );
	}
}
