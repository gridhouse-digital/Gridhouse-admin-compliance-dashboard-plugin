<?php

final class GHCA_ACD_Archive_Actor {
	/** @var string */ private $kind;
	/** @var string|null */ private $user_id;
	/** @var string|null */ private $initiating_user_id;
	/** @var string */ private $source_channel;
	/** @var string */ private $authority_code;
	/** @var array<string,mixed> */ private $authority_context;

	/** @param array<string,mixed> $authority_context */
	public function __construct( string $kind, ?string $user_id, ?string $initiating_user_id, string $source_channel, string $authority_code, array $authority_context ) {
		if ( ! in_array( $kind, array( 'wp_user', 'system', 'worker', 'integration' ), true ) ) {
			throw new InvalidArgumentException( 'Actor kind is invalid.' );
		}
		if ( 'wp_user' === $kind && null === $user_id ) {
			throw new InvalidArgumentException( 'A wp_user actor requires a user ID.' );
		}
		if ( null !== $user_id ) { self::assert_decimal( $user_id, 'Actor user ID' ); }
		if ( null !== $initiating_user_id ) { self::assert_decimal( $initiating_user_id, 'Initiating user ID' ); }
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,31}$/', $source_channel ) ) {
			throw new InvalidArgumentException( 'Actor source channel is invalid.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/', $authority_code ) ) {
			throw new InvalidArgumentException( 'Actor authority code is invalid.' );
		}
		self::validate_authority_context( $authority_context );
		$this->kind               = $kind;
		$this->user_id            = $user_id;
		$this->initiating_user_id = $initiating_user_id;
		$this->source_channel     = $source_channel;
		$this->authority_code     = $authority_code;
		$this->authority_context  = GHCA_ACD_Archive_Canonical_JSON::detach( $authority_context );
	}

	/** @return array<string,mixed> */
	public function canonical(): array {
		return array(
			'actor_kind'         => $this->kind,
			'actor_user_id'      => $this->user_id,
			'authority_code'     => $this->authority_code,
			'authority_context'  => GHCA_ACD_Archive_Canonical_JSON::detach( $this->authority_context ),
			'initiating_user_id' => $this->initiating_user_id,
			'source_channel'     => $this->source_channel,
		);
	}

	/** @param array<string,mixed> $context */
	public static function validate_authority_context( array $context ): void {
		$actual = array_keys( $context );
		$expected = array( 'delegated_by_user_id', 'delegation_kind', 'subject_scope_digest' );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Authority context fields do not match v1.' );
		}
		if ( ! is_string( $context['subject_scope_digest'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $context['subject_scope_digest'] ) ) {
			throw new InvalidArgumentException( 'Authority subject scope digest is invalid.' );
		}
		if ( ! is_string( $context['delegation_kind'] ) || ! in_array( $context['delegation_kind'], array( 'none', 'on_behalf_of', 'system' ), true ) ) {
			throw new InvalidArgumentException( 'Authority delegation kind is invalid.' );
		}
		if ( null !== $context['delegated_by_user_id'] ) {
			if ( ! is_string( $context['delegated_by_user_id'] ) ) {
				throw new InvalidArgumentException( 'Delegating user ID is invalid.' );
			}
			self::assert_decimal( $context['delegated_by_user_id'], 'Delegating user ID' );
		}
		if ( 'on_behalf_of' === $context['delegation_kind'] && null === $context['delegated_by_user_id'] ) {
			throw new InvalidArgumentException( 'Delegated authority requires a delegating user ID.' );
		}
		if ( 'none' === $context['delegation_kind'] && null !== $context['delegated_by_user_id'] ) {
			throw new InvalidArgumentException( 'Non-delegated authority cannot name a delegating user.' );
		}
		GHCA_ACD_Archive_Canonical_JSON::encode( $context );
	}

	private static function assert_decimal( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			throw new InvalidArgumentException( $label . ' must be canonical positive decimal.' );
		}
		$limit = '18446744073709551615';
		if ( strlen( $value ) > strlen( $limit ) || ( strlen( $value ) === strlen( $limit ) && strcmp( $value, $limit ) > 0 ) ) {
			throw new InvalidArgumentException( $label . ' exceeds the BIGINT UNSIGNED range.' );
		}
	}
}
