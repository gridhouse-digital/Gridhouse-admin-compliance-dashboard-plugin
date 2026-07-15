<?php

/**
 * Minimum pure caller-intent contract.
 *
 * Technical Design Section 8.1 requires the command receipt to be looked up by
 * dedupe identity and compared by caller-controlled client intent *before* any
 * server-derived fact is resolved or recomputed. This value object validates and
 * canonicalizes only the caller intent, and exposes the command type, dedupe
 * identity, and client-intent digest so a persistence layer can recognize a
 * response-loss retry without constructing the full accepted command.
 *
 * It reuses the closed caller-field contract on GHCA_ACD_Archive_Command and the
 * command's client-intent digest formula, so command-schema rules are never
 * duplicated here or in a future persistence layer.
 */
final class GHCA_ACD_Archive_Client_Intent {
	/** @var string */ private $type;
	/** @var string */ private $idempotency_scope_digest;
	/** @var string */ private $idempotency_key_digest;
	/** @var array<string,mixed> */ private $caller_intent;
	/** @var string */ private $client_intent_digest;

	/** @param array<string,mixed> $caller_intent */
	private function __construct( string $type, string $scope_digest, string $key_digest, array $caller_intent, string $client_intent_digest ) {
		$this->type                     = $type;
		$this->idempotency_scope_digest = $scope_digest;
		$this->idempotency_key_digest   = $key_digest;
		$this->caller_intent            = $caller_intent;
		$this->client_intent_digest     = $client_intent_digest;
	}

	/**
	 * Validate and canonicalize caller intent before any server fact exists.
	 *
	 * @param array<string,mixed> $caller_intent
	 */
	public static function prepare( string $type, string $scope_digest, string $key_digest, array $caller_intent ): self {
		self::assert_digest( $scope_digest, 'Idempotency scope digest' );
		self::assert_digest( $key_digest, 'Idempotency key digest' );
		GHCA_ACD_Archive_Command::validate_caller_intent( $type, $caller_intent );
		$canonical = GHCA_ACD_Archive_Canonical_JSON::detach( $caller_intent );
		$digest    = GHCA_ACD_Archive_Command::client_intent_digest_for( $type, $canonical );
		return new self( $type, $scope_digest, $key_digest, $canonical, $digest );
	}

	public function type(): string { return $this->type; }
	public function idempotency_scope_digest(): string { return $this->idempotency_scope_digest; }
	public function idempotency_key_digest(): string { return $this->idempotency_key_digest; }
	public function client_intent_digest(): string { return $this->client_intent_digest; }
	public function dedupe_digest(): string { return GHCA_ACD_Archive_Digester::idempotency( $this->idempotency_scope_digest, $this->idempotency_key_digest ); }
	/** @return array<string,mixed> */ public function caller_intent(): array { return GHCA_ACD_Archive_Canonical_JSON::detach( $this->caller_intent ); }

	/**
	 * A duplicate request is recognizable purely from caller intent: same command
	 * type, same dedupe identity, and the same client-intent digest. No server
	 * fact is recomputed to make this decision.
	 */
	public function recognizes_response_loss_retry( self $candidate ): bool {
		return $this->type === $candidate->type
			&& $this->idempotency_scope_digest === $candidate->idempotency_scope_digest
			&& $this->idempotency_key_digest === $candidate->idempotency_key_digest
			&& hash_equals( $this->client_intent_digest, $candidate->client_intent_digest );
	}

	private static function assert_digest( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
			throw new InvalidArgumentException( $label . ' is invalid.' );
		}
	}
}
