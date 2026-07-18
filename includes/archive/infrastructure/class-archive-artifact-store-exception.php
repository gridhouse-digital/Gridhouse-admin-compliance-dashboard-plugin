<?php

final class GHCA_ACD_Archive_Artifact_Store_Exception extends RuntimeException {
	/** @var string */
	private $reason_code;

	public function __construct( string $reason_code, string $message ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $reason_code ) ) {
			throw new InvalidArgumentException( 'Artifact-store failure reason code is invalid.' );
		}
		$this->reason_code = $reason_code;
		parent::__construct( $message );
	}

	public function reason_code(): string {
		return $this->reason_code;
	}
}
