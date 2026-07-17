<?php

/**
 * Authoritative stream/event persistence contract (Technical Design 6.3).
 *
 * Implementations are append-only for events: no supported method updates or
 * deletes an event row. Every method that reads for a lifecycle decision must
 * be called inside the one open unit-of-work transaction on the shared
 * database connection; the interface exposes exactly the primitives the
 * Section 8.1 command transaction requires.
 */
interface GHCA_ACD_Archive_Event_Store {
	/**
	 * Locking stream lookup by case-key digest (SELECT ... FOR UPDATE).
	 * Returns the validated stream row or null when no stream exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_stream_for_update( string $case_key_digest );

	/**
	 * Insert the immutable stream identity row with a zero technical head.
	 * A concurrent-creation duplicate raises a stream_creation_race failure
	 * so the caller can retry its receipt/stream-lock decision cleanly.
	 *
	 * @param array<string,mixed> $identity
	 * @return array<string,mixed> The created stream row.
	 */
	public function create_stream( array $identity, string $now_gmt ): array;

	/**
	 * Load the complete ordered authoritative stream, validating every row at
	 * the database boundary, reconstructing verified recorded events, and
	 * verifying sequence/hash-chain continuity via the kernel verifier.
	 *
	 * @return array<int,GHCA_ACD_Archive_Event>
	 */
	public function load_events( string $stream_id ): array;

	/**
	 * Append-only insert of a fully recorded event batch.
	 *
	 * @param array<int,GHCA_ACD_Archive_Event> $recorded_events
	 */
	public function append_events( array $recorded_events ): void;

	/**
	 * Guarded technical head advance: only head_sequence, head_event_digest,
	 * and updated_at_gmt may change, and only from the exact expected values.
	 */
	public function advance_stream_head( string $stream_id, string $expected_sequence, ?string $expected_digest, string $new_sequence, string $new_digest, string $now_gmt ): void;
}
