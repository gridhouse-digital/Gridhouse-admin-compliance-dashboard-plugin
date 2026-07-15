<?php

final class GHCA_ACD_Archive_Transition_Exception extends DomainException {
	/** @var string */
	private $reason_code;
	/** @var array<string,mixed> */
	private $safe_context;

	/** @param array<string,mixed> $context */
	public function __construct( string $reason_code, string $message, array $context = array() ) {
		$this->reason_code = $reason_code;
		$this->safe_context = GHCA_ACD_Archive_Canonical_JSON::detach( $context );
		parent::__construct( $message );
	}

	public function reason_code(): string {
		return $this->reason_code;
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return GHCA_ACD_Archive_Canonical_JSON::detach( $this->safe_context );
	}
}
