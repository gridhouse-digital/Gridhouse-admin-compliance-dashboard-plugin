<?php

/** Produces the reviewed source fingerprint through the accepted evidence path. */
final class GHCA_ACD_Archive_Review_Intake {
	private const LIMITS = array(
		'maximum_queries' => 32,
		'maximum_rows' => 10000,
		'maximum_transaction_milliseconds' => 2000,
	);

	/** @var GHCA_ACD_Archive_Evidence_Source */
	private $source;
	/** @var GHCA_ACD_Archive_Unit_Of_Work */
	private $uow;
	/** @var GHCA_ACD_Archive_Id_Generator */
	private $ids;

	public function __construct(
		GHCA_ACD_Archive_Evidence_Source $source,
		GHCA_ACD_Archive_Unit_Of_Work $uow,
		GHCA_ACD_Archive_Id_Generator $ids
	) {
		$this->source = $source;
		$this->uow = $uow;
		$this->ids = $ids;
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	public function execute( array $request, callable $checkpoint ): array {
		$keys = array_keys( $request );
		$expected = array( 'actor', 'case_key', 'idempotency_key' );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected
			|| ! $request['actor'] instanceof GHCA_ACD_Archive_Actor
			|| ! $request['case_key'] instanceof GHCA_ACD_Archive_Case_Key
			|| ! is_string( $request['idempotency_key'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $request['idempotency_key'] ) ) {
			throw new InvalidArgumentException( 'Archive review request is invalid.' );
		}

		$actor = $request['actor'];
		$case_key = $request['case_key'];
		$case = $case_key->canonical();
		$cycle = $case_key->cycle()->canonical();
		$review_identity = array( 'case_key' => $case, 'resolved_cycle' => $cycle );

		$checkpoint();
		$document = $this->source->read_consistent_review_evidence( $review_identity, self::LIMITS, $checkpoint );
		$checkpoint();
		$checkpoint();
		$fingerprint = GHCA_ACD_Archive_Digester::source_fingerprint( $document );
		$checkpoint();
		if ( ! isset( $document['policy']['policy_digest'] )
			|| ! is_string( $document['policy']['policy_digest'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $document['policy']['policy_digest'] ) ) {
			throw new GHCA_ACD_Archive_Evidence_Source_Exception(
				GHCA_ACD_Archive_Evidence_Source_Exception::CATEGORY_INVALID,
				'archive_evidence_invalid',
				'source_validate'
			);
		}
		$actor_document = $actor->canonical();
		$subject_scope = $actor_document['authority_context']['subject_scope_digest'];
		$validation_identity = array(
			'archive_id' => str_repeat( '0', 32 ),
			'case_key' => $case,
			'policy_digest' => $document['policy']['policy_digest'],
			'resolved_cycle' => $cycle,
			'reviewed_source_fingerprint' => $fingerprint,
			'revision_number' => 1,
			'stream_id' => str_repeat( '1', 32 ),
			'subject_scope_digest' => $subject_scope,
			'trigger_event_id' => str_repeat( '2', 32 ),
		);
		$document = ( new GHCA_ACD_Archive_Evidence_Result_Validator() )->validate( $document, $validation_identity );
		$checkpoint();

		$namespace_id = 'wp_user' === $actor_document['actor_kind']
			? $actor_document['actor_user_id']
			: $actor_document['authority_code'];
		$scope = array(
			'actor_or_integration_namespace' => $actor_document['actor_kind'] . ':' . $namespace_id,
			'case_key_digest_or_global_scope' => $case_key->digest(),
			'command_type' => 'RequestArchive',
			'site_id' => $case['site_id_decimal'],
			'tenant_id' => $case['tenant_id'],
		);
		$scope_digest = GHCA_ACD_Archive_Digester::idempotency_scope( $scope );
		$command = GHCA_ACD_Archive_Command::request_archive(
			$this->ids->generate(),
			$scope_digest,
			hash( 'sha256', $request['idempotency_key'] ),
			'0',
			$actor,
			array( 'case_key' => $case, 'request_kind' => 'initial' ),
			array(
				'archive_id' => $this->ids->generate(),
				'policy_digest' => $document['policy']['policy_digest'],
				'resolved_cycle' => $cycle,
				'reviewed_source_fingerprint' => $fingerprint,
				'revision_number' => 1,
				'subject_scope_digest' => $subject_scope,
			)
		);
		$checkpoint();
		$response = $this->uow->execute( array(
			'command' => $command,
			'case_key' => $case_key,
			'idempotency_scope' => $scope,
			'expected_head_digest' => null,
			'correlation_id' => $this->ids->generate(),
		) );
		$checkpoint();
		return $response;
	}
}
