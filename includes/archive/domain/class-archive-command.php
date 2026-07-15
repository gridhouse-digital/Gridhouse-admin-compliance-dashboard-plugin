<?php

/**
 * Immutable validated command envelope.
 *
 * One closed contract exists per approved business command. Caller intent
 * contains only caller-controlled facts available before receipt lookup;
 * server-generated identifiers and server-resolved fingerprint/policy facts
 * live in the accepted server facts. A command producing multiple events
 * (correction, drift detection, rebase recovery, finalization) keeps one
 * command identity and one atomic decision.
 */
final class GHCA_ACD_Archive_Command {
	/** @var string */ private $command_id;
	/** @var string */ private $type;
	/** @var int */ private $schema_version;
	/** @var string */ private $idempotency_scope_digest;
	/** @var string */ private $idempotency_key_digest;
	/** @var string */ private $expected_sequence;
	/** @var array<string,mixed> */ private $actor;
	/** @var array<string,mixed> */ private $caller_intent;
	/** @var array<string,mixed> */ private $server_facts;
	/** @var string */ private $client_intent_digest;
	/** @var string */ private $digest;

	/**
	 * @param array<string,mixed> $caller_intent
	 * @param array<string,mixed> $server_facts
	 * @param mixed $expected_sequence
	 */
	private function __construct( string $command_id, string $type, string $idempotency_scope_digest, string $idempotency_key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $command_id ) ) {
			throw new InvalidArgumentException( 'Command ID is invalid.' );
		}
		self::assert_digest( $idempotency_scope_digest );
		self::assert_digest( $idempotency_key_digest );
		self::assert_expected_sequence( $expected_sequence );
		self::validate_contract( $type, $caller_intent, $server_facts, $actor );
		$this->command_id               = $command_id;
		$this->type                     = $type;
		$this->schema_version           = 1;
		$this->idempotency_scope_digest = $idempotency_scope_digest;
		$this->idempotency_key_digest   = $idempotency_key_digest;
		$this->expected_sequence        = $expected_sequence;
		$this->actor                    = GHCA_ACD_Archive_Canonical_JSON::detach( $actor->canonical() );
		$this->caller_intent            = GHCA_ACD_Archive_Canonical_JSON::detach( $caller_intent );
		$this->server_facts             = GHCA_ACD_Archive_Canonical_JSON::detach( $server_facts );
		$this->client_intent_digest     = self::client_intent_digest_for( $this->type, $this->caller_intent );
		$this->digest                   = GHCA_ACD_Archive_Digester::command( $this->canonical() );
	}

	/**
	 * Closed generic entry over the approved command types only.
	 *
	 * @param array<string,mixed> $caller_intent
	 * @param array<string,mixed> $server_facts
	 * @param mixed $expected_sequence
	 */
	public static function from_parts( string $command_id, string $type, string $idempotency_scope_digest, string $idempotency_key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self {
		return new self( $command_id, $type, $idempotency_scope_digest, $idempotency_key_digest, $expected_sequence, $actor, $caller_intent, $server_facts );
	}

	public function expected_sequence(): string { return $this->expected_sequence; }
	public function digest(): string { return $this->digest; }
	public function client_intent_digest(): string { return $this->client_intent_digest; }
	public function type(): string { return $this->type; }

	/**
	 * Receipt-first ordering (Technical Design Section 8.1): recognition is
	 * decided purely from caller-controlled client intent, never by recomputing
	 * server-derived facts. This delegates to the pure client-intent contract,
	 * which a persistence layer can evaluate before resolving server facts at all.
	 */
	public function recognizes_response_loss_retry( self $candidate ): bool {
		return $this->client_intent()->recognizes_response_loss_retry( $candidate->client_intent() );
	}

	/**
	 * The minimum pure caller-intent contract for this command: it exposes the
	 * command type, dedupe identity, and client-intent digest with no server facts.
	 */
	public function client_intent(): GHCA_ACD_Archive_Client_Intent {
		return GHCA_ACD_Archive_Client_Intent::prepare( $this->type, $this->idempotency_scope_digest, $this->idempotency_key_digest, $this->caller_intent );
	}

	/** @return array<int,string> */
	public static function caller_contract( string $type ): array {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $type ] ) ) {
			throw new InvalidArgumentException( 'Unknown archive command type.' );
		}
		return $contracts[ $type ]['caller'];
	}

	/** @param array<string,mixed> $caller_intent */
	public static function validate_caller_intent( string $type, array $caller_intent ): void {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $type ] ) ) {
			throw new InvalidArgumentException( 'Unknown archive command type.' );
		}
		self::assert_exact_fields( $caller_intent, $contracts[ $type ]['caller'], 'Caller intent' );
		$fragment = $caller_intent;
		if ( 'RecordMaterializedArtifact' === $type ) {
			if ( ! in_array( $caller_intent['artifact_kind'], array( 'ledger', 'packet' ), true ) ) {
				throw new InvalidArgumentException( 'Materialized artifact kind is invalid.' );
			}
			$fragment = array( 'archive_id' => $caller_intent['archive_id'] );
		}
		GHCA_ACD_Archive_Event_Catalog::validate_payload_fragment( self::caller_event_type( $type ), $fragment );
		if ( 'ResolveSourceDriftRestored' === $type && 'restored' !== $caller_intent['resolution_kind'] ) {
			throw new InvalidArgumentException( 'Restoration resolution must use the restored kind; rebase requires the recovery command.' );
		}
	}

	private static function caller_event_type( string $type ): string {
		$event_types = array(
			'RequestArchive' => GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED,
			'RequestReplacementArchive' => GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED,
			'StartBuild' => GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED,
			'RecordEvidenceSnapshot' => GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED,
			'RecordMaterializedArtifact' => GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED,
			'VerifyAndFinalize' => GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED,
			'FailArchive' => GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED,
			'RetryArchive' => GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED,
			'CancelArchive' => GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED,
			'RequestCorrection' => GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED,
			'RequestReset' => GHCA_ACD_Archive_Event_Types::RESET_REQUESTED,
			'DeferReset' => GHCA_ACD_Archive_Event_Types::RESET_DEFERRED,
			'RejectReset' => GHCA_ACD_Archive_Event_Types::RESET_REJECTED,
			'CancelReset' => GHCA_ACD_Archive_Event_Types::RESET_CANCELLED,
			'AuthorizeReset' => GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED,
			'ExpireResetAuthorization' => GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED,
			'ClaimResetExecution' => GHCA_ACD_Archive_Event_Types::RESET_EXECUTION_CLAIMED,
			'CompleteReset' => GHCA_ACD_Archive_Event_Types::RESET_COMPLETED,
			'RecordResetFailedSafe' => GHCA_ACD_Archive_Event_Types::RESET_FAILED_SAFE,
			'RecordResetOutcomeUncertain' => GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN,
			'ReconcileResetAsCompleted' => GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_COMPLETED,
			'ReconcileResetAsNoChange' => GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_NO_CHANGE,
			'RequireResetRemediation' => GHCA_ACD_Archive_Event_Types::RESET_REMEDIATION_REQUIRED,
			'RecordResetRemediatedRestored' => GHCA_ACD_Archive_Event_Types::RESET_REMEDIATED_RESTORED,
			'DetectSourceDrift' => GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED,
			'ResolveSourceDriftRestored' => GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED,
			'RebaseSourceDriftRecovery' => GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED,
			'DetectUnprotectedReset' => GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED,
			'DismissUnprotectedReset' => GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DISMISSED,
			'ConfirmUnprotectedReset' => GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_CONFIRMED,
			'DetectIntegrityViolation' => GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED,
			'RecordIntegrityDisposition' => GHCA_ACD_Archive_Event_Types::INTEGRITY_INCIDENT_DISPOSITION_RECORDED,
		);
		return $event_types[ $type ];
	}

	/**
	 * Canonical client-intent digest formula, shared by the accepted command and
	 * the pure client-intent contract so the receipt-lookup key is computed one way.
	 *
	 * @param array<string,mixed> $caller_intent
	 */
	public static function client_intent_digest_for( string $type, array $caller_intent ): string {
		return GHCA_ACD_Archive_Digester::client_intent( array(
			'caller_intent'            => GHCA_ACD_Archive_Canonical_JSON::detach( $caller_intent ),
			'canonical_format_version' => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
			'command_schema_version'   => 1,
			'command_type'             => $type,
		) );
	}

	/** @return array<string,mixed> */ public function caller_intent(): array { return GHCA_ACD_Archive_Canonical_JSON::detach( $this->caller_intent ); }
	/** @return array<string,mixed> */ public function server_facts(): array { return GHCA_ACD_Archive_Canonical_JSON::detach( $this->server_facts ); }

	/**
	 * Server facts are an object-valued field. When a command has no server
	 * facts (e.g. FailArchive) the canonical document must still encode `{}`,
	 * never `[]`, so the digest binds the correct JSON type.
	 *
	 * @return array<string,mixed>|GHCA_ACD_Archive_Empty_Object
	 */
	private function canonical_server_facts() {
		if ( array() === $this->server_facts ) {
			return GHCA_ACD_Archive_Empty_Object::instance();
		}
		return GHCA_ACD_Archive_Canonical_JSON::detach( $this->server_facts );
	}

	/** @return array<string,mixed> */
	public function canonical(): array {
		return array(
			'actor'                    => GHCA_ACD_Archive_Canonical_JSON::detach( $this->actor ),
			'caller_intent'            => GHCA_ACD_Archive_Canonical_JSON::detach( $this->caller_intent ),
			'canonical_format_version' => GHCA_ACD_Archive_Canonical_JSON::FORMAT_VERSION,
			'client_intent_digest'     => $this->client_intent_digest,
			'command_id'               => $this->command_id,
			'command_schema_version'   => $this->schema_version,
			'command_type'             => $this->type,
			'expected_sequence'        => $this->expected_sequence,
			'idempotency_key_digest'   => $this->idempotency_key_digest,
			'idempotency_scope_digest' => $this->idempotency_scope_digest,
			'server_facts'             => $this->canonical_server_facts(),
		);
	}

	/**
	 * Closed caller/server field contracts, one per approved business command.
	 *
	 * @return array<string,array{caller:array<int,string>,server:array<int,string>}>
	 */
	private static function contracts(): array {
		return array(
			'RequestArchive'              => array( 'caller' => array( 'case_key', 'request_kind' ), 'server' => array( 'archive_id', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'subject_scope_digest' ) ),
			'RequestReplacementArchive'   => array( 'caller' => array( 'case_key', 'revoked_predecessor_archive_id' ), 'server' => array( 'archive_id', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'subject_scope_digest' ) ),
			'StartBuild'                  => array( 'caller' => array( 'archive_id' ), 'server' => array( 'build_attempt_id', 'retry_ordinal', 'snapshot_id', 'start_phase' ) ),
			'RecordEvidenceSnapshot'      => array( 'caller' => array( 'archive_id' ), 'server' => array( 'byte_count', 'captured_source_fingerprint', 'certificate_asset_ids', 'certificate_content_digests', 'completeness_policy', 'policy_digest', 'resolved_cycle', 'reviewed_source_fingerprint', 'revision_number', 'snapshot_digest', 'snapshot_id', 'snapshot_schema_version', 'subject_scope_digest' ) ),
			'RecordMaterializedArtifact'  => array( 'caller' => array( 'archive_id', 'artifact_kind' ), 'server' => array() /* variant fields validated below */ ),
			'VerifyAndFinalize'           => array( 'caller' => array( 'archive_id' ), 'server' => array( 'finalized', 'verified' ) ),
			'FailArchive'                 => array( 'caller' => array( 'archive_id', 'build_attempt_id', 'candidate_artifact_ids', 'failure_code', 'phase', 'retryable', 'sealed_snapshot_id' ), 'server' => array() ),
			'RetryArchive'                => array( 'caller' => array( 'archive_id' ), 'server' => array( 'new_build_attempt_id', 'prior_build_attempt_id', 'resume_phase', 'sealed_snapshot_id' ) ),
			'CancelArchive'               => array( 'caller' => array( 'archive_id', 'cancellation_reason' ), 'server' => array( 'build_attempt_id', 'retained_candidate_disposition_code' ) ),
			'RequestCorrection'           => array( 'caller' => array( 'reason_code', 'target_archive_id' ), 'server' => array( 'affected_scope_digest', 'correction_operation_id', 'invalidations', 'target_snapshot_id' ) ),
			'RequestReset'                => array( 'caller' => array( 'bound_archive_id', 'consent_mode', 'request_valid_until_gmt', 'scope' ), 'server' => array( 'reset_operation_id', 'scope_digest', 'snapshot_id' ) ),
			'DeferReset'                  => array( 'caller' => array( 'reset_operation_id' ), 'server' => array( 'condition_code', 'consent_expires_at_gmt', 'reevaluation_deadline_gmt' ) ),
			'RejectReset'                 => array( 'caller' => array( 'reset_operation_id' ), 'server' => array( 'rejection_code', 'safe_explanation' ) ),
			'CancelReset'                 => array( 'caller' => array( 'cancellation_reason', 'reset_operation_id' ), 'server' => array( 'authorization_id' ) ),
			'AuthorizeReset'              => array( 'caller' => array( 'reset_operation_id' ), 'server' => array( 'archive_id', 'authorization_id', 'expires_at_gmt', 'gateway_key', 'issued_at_gmt', 'scope_digest', 'snapshot_id', 'source_fingerprint' ) ),
			'ExpireResetAuthorization'    => array( 'caller' => array( 'reset_operation_id' ), 'server' => array( 'authorization_id', 'expiry_policy_code', 'observed_at_gmt', 'scheduled_expires_at_gmt' ) ),
			'ClaimResetExecution'         => array( 'caller' => array( 'reset_operation_id' ), 'server' => array( 'authorization_id', 'claimed_at_gmt', 'gateway_key', 'scope_digest', 'source_fingerprint', 'upstream_operation_id' ) ),
			'CompleteReset'               => array( 'caller' => array( 'affected_record_count', 'affected_records_digest', 'post_source_fingerprint', 'reset_operation_id', 'upstream_operation_id', 'verification_evidence_digest' ), 'server' => array() ),
			'RecordResetFailedSafe'       => array( 'caller' => array( 'no_change_proof_digest', 'probe_version', 'reset_operation_id', 'unchanged_source_fingerprint', 'upstream_operation_id' ), 'server' => array() ),
			'RecordResetOutcomeUncertain' => array( 'caller' => array( 'failure_code', 'last_known_phase', 'last_observation_digest', 'reset_operation_id', 'upstream_operation_id' ), 'server' => array() ),
			'ReconcileResetAsCompleted'   => array( 'caller' => array( 'evidence_digest', 'post_source_fingerprint', 'probe_version', 'proof_digest', 'reset_operation_id', 'upstream_operation_id' ), 'server' => array() ),
			'ReconcileResetAsNoChange'    => array( 'caller' => array( 'no_change_proof_digest', 'probe_version', 'reset_operation_id', 'source_fingerprint', 'upstream_operation_id' ), 'server' => array() ),
			'RequireResetRemediation'     => array( 'caller' => array( 'affected_scope_digest', 'evidence_digest', 'reset_operation_id', 'upstream_operation_id' ), 'server' => array( 'remediation_case_id' ) ),
			'RecordResetRemediatedRestored' => array( 'caller' => array( 'partial_effect_reference_id', 'remediation_case_id', 'reset_operation_id', 'restoration_proof_digest', 'restored_source_fingerprint', 'upstream_operation_id' ), 'server' => array() ),
			'DetectSourceDrift'           => array( 'caller' => array( 'archive_id', 'changed_component_codes', 'detection_point', 'observed_source_fingerprint' ), 'server' => array( 'expected_source_fingerprint', 'failure', 'incident_id', 'invalidations', 'snapshot_id' ) ),
			'ResolveSourceDriftRestored'  => array( 'caller' => array( 'incident_id', 'resolution_kind', 'resolution_reference_id', 'verified_source_fingerprint' ), 'server' => array() ),
			'RebaseSourceDriftRecovery'   => array( 'caller' => array( 'incident_id' ), 'server' => array( 'cancellation', 'correction', 'request', 'request_type', 'resolved', 'revocation' ) ),
			'DetectUnprotectedReset'      => array( 'caller' => array( 'detector_key', 'observed_source_fingerprint', 'probe_version', 'scope' ), 'server' => array( 'before_source_fingerprint', 'incident_id', 'invalidations' ) ),
			'DismissUnprotectedReset'     => array( 'caller' => array( 'incident_id', 'no_reset_proof_digest', 'verified_source_fingerprint' ), 'server' => array() ),
			'ConfirmUnprotectedReset'     => array( 'caller' => array( 'affected_scope_digest', 'evidence_digest', 'incident_id', 'remediation_requirement' ), 'server' => array() ),
			'DetectIntegrityViolation'    => array( 'caller' => array( 'containment_code', 'expected_digest', 'invariant_code', 'observed_digest', 'target_id', 'target_kind', 'verifier_version' ), 'server' => array( 'incident_id', 'invalidations' ) ),
			'RecordIntegrityDisposition'  => array( 'caller' => array( 'disposition_code', 'evidence_reference_ids', 'incident_id', 'reason_code', 'remaining_restrictions', 'reviewer_authority_code' ), 'server' => array() ),
		);
	}

	/** @param array<string,mixed> $caller @param array<string,mixed> $server */
	private static function validate_contract( string $type, array $caller, array $server, GHCA_ACD_Archive_Actor $actor ): void {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $type ] ) ) {
			throw new InvalidArgumentException( 'Unknown archive command type.' );
		}
		self::validate_caller_intent( $type, $caller );
		if ( 'RecordMaterializedArtifact' === $type ) {
			self::validate_materialization_variant( $caller, $server );
		} else {
			self::assert_exact_fields( $server, $contracts[ $type ]['server'], 'Server facts' );
			self::validate_decision_payloads( $type, $caller, $server );
		}
		$authority_scope = $actor->canonical()['authority_context']['subject_scope_digest'];
		$scope_documents = array( array_merge( $caller, $server ) );
		if ( 'RebaseSourceDriftRecovery' === $type ) {
			foreach ( array( 'request', 'correction' ) as $field ) {
				if ( isset( $server[ $field ] ) && is_array( $server[ $field ] ) ) {
					$scope_documents[] = $server[ $field ];
				}
			}
		}
		foreach ( $scope_documents as $scope_document ) {
			$requested_scope = GHCA_ACD_Archive_Event_Catalog::effective_subject_scope_digest( $scope_document );
			if ( null !== $requested_scope && $requested_scope !== $authority_scope ) {
				throw new InvalidArgumentException( 'Actor authority subject scope contradicts the requested subject scope.' );
			}
		}
	}

	/** @param array<string,mixed> $caller @param array<string,mixed> $server */
	private static function validate_decision_payloads( string $type, array $caller, array $server ): void {
		switch ( $type ) {
			case 'RequestArchive':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, array_merge( $caller, $server ) );
				return;
			case 'RequestReplacementArchive':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED, array_merge( $caller, $server ) );
				return;
			case 'StartBuild':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_BUILD_STARTED, array_merge( $caller, $server ) );
				return;
			case 'RecordEvidenceSnapshot':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::EVIDENCE_SNAPSHOT_CAPTURED, array_merge( $caller, $server ) );
				return;
			case 'VerifyAndFinalize':
				if ( ! is_array( $server['verified'] ) || ! is_array( $server['finalized'] ) ) {
					throw new InvalidArgumentException( 'Verification and finalization documents are required.' );
				}
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_VERIFIED, $server['verified'] );
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_FINALIZED, $server['finalized'] );
				if ( $server['verified']['archive_id'] !== $caller['archive_id'] || $server['finalized']['archive_id'] !== $caller['archive_id'] ) {
					throw new InvalidArgumentException( 'Finalization documents contradict the commanded archive identity.' );
				}
				return;
			case 'FailArchive':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED, array_merge( $caller, $server ) );
				return;
			case 'RetryArchive':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_RETRY_REQUESTED, array_merge( $caller, $server ) );
				return;
			case 'CancelArchive':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED, array_merge( $caller, $server ) );
				return;
			case 'RequestCorrection':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, array(
					'target_archive_id'       => $caller['target_archive_id'],
					'target_snapshot_id'      => $server['target_snapshot_id'],
					'correction_operation_id' => $server['correction_operation_id'],
					'reason_code'             => $caller['reason_code'],
					'affected_scope_digest'   => $server['affected_scope_digest'],
				) );
				$invalidated_ids = self::validate_invalidations( $server['invalidations'], $server['correction_operation_id'] );
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, array(
					'target_archive_id'               => $caller['target_archive_id'],
					'correction_operation_id'         => $server['correction_operation_id'],
					'revocation_reason_code'          => $caller['reason_code'],
					'invalidated_reset_operation_ids' => $invalidated_ids,
				) );
				return;
			case 'RequestReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_REQUESTED, array_merge( $caller, $server ) );
				return;
			case 'DeferReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_DEFERRED, array_merge( $caller, $server ) );
				return;
			case 'RejectReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_REJECTED, array_merge( $caller, $server ) );
				return;
			case 'CancelReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_CANCELLED, array_merge( $caller, $server ) );
				return;
			case 'AuthorizeReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZED, array_merge( $caller, $server ) );
				return;
			case 'ExpireResetAuthorization':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_AUTHORIZATION_EXPIRED, array_merge( $caller, $server ) );
				return;
			case 'ClaimResetExecution':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_EXECUTION_CLAIMED, array_merge( $caller, $server ) );
				return;
			case 'CompleteReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_COMPLETED, array_merge( $caller, $server ) );
				return;
			case 'RecordResetFailedSafe':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_FAILED_SAFE, array_merge( $caller, $server ) );
				return;
			case 'RecordResetOutcomeUncertain':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_OUTCOME_BECAME_UNCERTAIN, array_merge( $caller, $server ) );
				return;
			case 'ReconcileResetAsCompleted':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_COMPLETED, array_merge( $caller, $server ) );
				return;
			case 'ReconcileResetAsNoChange':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_RECONCILED_AS_NO_CHANGE, array_merge( $caller, $server ) );
				return;
			case 'RequireResetRemediation':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_REMEDIATION_REQUIRED, array_merge( $caller, $server ) );
				return;
			case 'RecordResetRemediatedRestored':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_REMEDIATED_RESTORED, array_merge( $caller, $server ) );
				return;
			case 'DetectSourceDrift':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_DETECTED, array(
					'incident_id'                 => $server['incident_id'],
					'archive_id'                  => $caller['archive_id'],
					'snapshot_id'                 => $server['snapshot_id'],
					'expected_source_fingerprint' => $server['expected_source_fingerprint'],
					'observed_source_fingerprint' => $caller['observed_source_fingerprint'],
					'detection_point'             => $caller['detection_point'],
					'changed_component_codes'     => $caller['changed_component_codes'],
				) );
				if ( null !== $server['failure'] ) {
					if ( ! is_array( $server['failure'] ) ) { throw new InvalidArgumentException( 'Drift failure document is invalid.' ); }
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_FAILED, $server['failure'] );
				}
				self::validate_invalidations( $server['invalidations'], $server['incident_id'] );
				return;
			case 'ResolveSourceDriftRestored':
				if ( 'restored' !== $caller['resolution_kind'] ) {
					throw new InvalidArgumentException( 'Restoration resolution must use the restored kind; rebase requires the recovery command.' );
				}
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED, array_merge( $caller, $server ) );
				return;
			case 'RebaseSourceDriftRecovery':
				if ( ! is_array( $server['resolved'] ) || ! is_array( $server['request'] ) ) {
					throw new InvalidArgumentException( 'Rebase recovery requires resolution and request documents.' );
				}
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::SOURCE_DRIFT_RESOLVED, $server['resolved'] );
				if ( 'replacement_rebased' !== $server['resolved']['resolution_kind'] || $server['resolved']['incident_id'] !== $caller['incident_id'] ) {
					throw new InvalidArgumentException( 'Rebase recovery resolution must rebase the commanded incident.' );
				}
				if ( 'initial' === $server['request_type'] ) {
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_REQUESTED, $server['request'] );
				} elseif ( 'replacement' === $server['request_type'] ) {
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::REPLACEMENT_ARCHIVE_REQUESTED, $server['request'] );
				} else {
					throw new InvalidArgumentException( 'Rebase recovery request type is invalid.' );
				}
				if ( null !== $server['cancellation'] ) {
					if ( ! is_array( $server['cancellation'] ) ) { throw new InvalidArgumentException( 'Rebase cancellation document is invalid.' ); }
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_CANCELLED, $server['cancellation'] );
				}
				if ( ( null === $server['correction'] ) !== ( null === $server['revocation'] ) ) {
					throw new InvalidArgumentException( 'Rebase correction and revocation must be provided together.' );
				}
				if ( null !== $server['correction'] ) {
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::CORRECTION_REQUESTED, $server['correction'] );
					self::validate_event_payload( GHCA_ACD_Archive_Event_Types::ARCHIVE_REVOKED, $server['revocation'] );
				}
				return;
			case 'DetectUnprotectedReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DETECTED, array(
					'incident_id'                 => $server['incident_id'],
					'scope'                       => $caller['scope'],
					'before_source_fingerprint'   => $server['before_source_fingerprint'],
					'observed_source_fingerprint' => $caller['observed_source_fingerprint'],
					'detector_key'                => $caller['detector_key'],
					'probe_version'               => $caller['probe_version'],
				) );
				self::validate_invalidations( $server['invalidations'], $server['incident_id'] );
				return;
			case 'DismissUnprotectedReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_DISMISSED, array_merge( $caller, $server ) );
				return;
			case 'ConfirmUnprotectedReset':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::UNPROTECTED_RESET_CONFIRMED, array_merge( $caller, $server ) );
				return;
			case 'DetectIntegrityViolation':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::INTEGRITY_VIOLATION_DETECTED, array_merge(
					array( 'incident_id' => $server['incident_id'] ),
					$caller
				) );
				self::validate_invalidations( $server['invalidations'], $server['incident_id'] );
				return;
			case 'RecordIntegrityDisposition':
				self::validate_event_payload( GHCA_ACD_Archive_Event_Types::INTEGRITY_INCIDENT_DISPOSITION_RECORDED, array_merge( $caller, $server ) );
				return;
		}
		throw new LogicException( 'Command contract has no payload validator.' );
	}

	/** @param array<string,mixed> $caller @param array<string,mixed> $server */
	private static function validate_materialization_variant( array $caller, array $server ): void {
		if ( 'ledger' === $caller['artifact_kind'] ) {
			self::assert_exact_fields( $server, array( 'build_attempt_id', 'content_digest', 'item_count', 'ledger_artifact_id', 'manifest_digest', 'snapshot_digest', 'snapshot_id' ), 'Server facts' );
			self::validate_event_payload( GHCA_ACD_Archive_Event_Types::LEDGER_MATERIALIZED, array_merge( array( 'archive_id' => $caller['archive_id'] ), $server ) );
			return;
		}
		if ( 'packet' === $caller['artifact_kind'] ) {
			self::assert_exact_fields( $server, array( 'build_attempt_id', 'certificate_content_digests', 'content_digest', 'packet_artifact_id', 'snapshot_digest', 'snapshot_id' ), 'Server facts' );
			self::validate_event_payload( GHCA_ACD_Archive_Event_Types::PACKET_MATERIALIZED, array_merge( array( 'archive_id' => $caller['archive_id'] ), $server ) );
			return;
		}
		throw new InvalidArgumentException( 'Materialized artifact kind is invalid.' );
	}

	/**
	 * @param array<int,array<string,mixed>>|mixed $invalidations
	 * @return array<int,string>
	 */
	private static function validate_invalidations( $invalidations, string $reference_id ): array {
		if ( ! is_array( $invalidations ) ) {
			throw new InvalidArgumentException( 'Invalidations must be a list of pre-claim reset operations.' );
		}
		$ids = array();
		$expected_index = 0;
		foreach ( $invalidations as $index => $entry ) {
			if ( $index !== $expected_index || ! is_array( $entry ) ) {
				throw new InvalidArgumentException( 'Invalidations must be a list of pre-claim reset operations.' );
			}
			$expected_index++;
			self::assert_exact_fields( $entry, array( 'authorization_id', 'reason_code', 'reset_operation_id' ), 'Invalidation entry' );
			self::validate_event_payload( GHCA_ACD_Archive_Event_Types::RESET_OPERATION_INVALIDATED, array(
				'reset_operation_id'        => $entry['reset_operation_id'],
				'authorization_id'          => $entry['authorization_id'],
				'invalidating_reference_id' => $reference_id,
				'reason_code'               => $entry['reason_code'],
			) );
			$ids[] = $entry['reset_operation_id'];
		}
		return $ids;
	}

	/** @param array<string,mixed> $fields */
	private static function validate_event_payload( string $event_type, array $fields ): void {
		$payload = array_merge( array( 'payload_schema_version' => 1 ), $fields );
		GHCA_ACD_Archive_Event_Catalog::validate_payload( $event_type, 1, $payload );
	}

	/*
	 * Named closed factories, one per approved business command.
	 */

	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function request_archive( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RequestArchive', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function request_replacement_archive( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RequestReplacementArchive', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function start_build( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'StartBuild', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_evidence_snapshot( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordEvidenceSnapshot', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_materialized_artifact( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordMaterializedArtifact', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function verify_and_finalize( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'VerifyAndFinalize', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function fail_archive( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'FailArchive', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function retry_archive( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RetryArchive', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function cancel_archive( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'CancelArchive', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function request_correction( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RequestCorrection', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function request_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RequestReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function defer_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'DeferReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function reject_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RejectReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function cancel_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'CancelReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function authorize_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'AuthorizeReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function expire_reset_authorization( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ExpireResetAuthorization', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function claim_reset_execution( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ClaimResetExecution', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function complete_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'CompleteReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_reset_failed_safe( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordResetFailedSafe', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_reset_outcome_uncertain( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordResetOutcomeUncertain', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function reconcile_reset_as_completed( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ReconcileResetAsCompleted', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function reconcile_reset_as_no_change( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ReconcileResetAsNoChange', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function require_reset_remediation( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RequireResetRemediation', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_reset_remediated_restored( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordResetRemediatedRestored', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function detect_source_drift( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'DetectSourceDrift', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function resolve_source_drift_restored( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ResolveSourceDriftRestored', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function rebase_source_drift_recovery( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RebaseSourceDriftRecovery', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function detect_unprotected_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'DetectUnprotectedReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function dismiss_unprotected_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'DismissUnprotectedReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function confirm_unprotected_reset( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'ConfirmUnprotectedReset', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function detect_integrity_violation( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'DetectIntegrityViolation', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }
	/** @param array<string,mixed> $caller_intent @param array<string,mixed> $server_facts */
	public static function record_integrity_disposition( string $command_id, string $scope_digest, string $key_digest, $expected_sequence, GHCA_ACD_Archive_Actor $actor, array $caller_intent, array $server_facts ): self { return new self( $command_id, 'RecordIntegrityDisposition', $scope_digest, $key_digest, $expected_sequence, $actor, $caller_intent, $server_facts ); }

	private static function assert_digest( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
			throw new InvalidArgumentException( 'Command digest identity is invalid.' );
		}
	}

	/**
	 * Expected stream sequence is a `BIGINT UNSIGNED` (INV-13): its value range
	 * exceeds PHP's signed integer, so the contract is a canonical unsigned
	 * decimal string. It rejects negatives, overflow, leading-zero variants,
	 * and every non-string value before PHP can coerce it.
	 *
	 * @param mixed $value
	 */
	private static function assert_expected_sequence( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/', $value ) ) {
			throw new InvalidArgumentException( 'Expected sequence must be a canonical unsigned decimal string.' );
		}
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) {
			throw new InvalidArgumentException( 'Expected sequence exceeds the BIGINT UNSIGNED range.' );
		}
	}

	/** @param array<string,mixed> $actual @param array<int,string> $expected */
	private static function assert_exact_fields( array $actual, array $expected, string $label ): void {
		$keys = array_keys( $actual );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( $label . ' fields do not match the closed command schema.' );
		}
	}
}
