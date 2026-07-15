<?php

final class GHCA_ACD_Archive_Event_Stream_Verifier {
	/** @param array<int,GHCA_ACD_Archive_Event> $events */
	public static function verify( array $events ): bool {
		if ( empty( $events ) ) {
			return true;
		}
		$stream_id = null;
		$case_key_digest = null;
		$previous_digest = null;
		$expected_sequence = 1;
		$seen_event_ids = array();
		foreach ( $events as $event ) {
			if ( ! $event instanceof GHCA_ACD_Archive_Event || ! $event->is_recorded() || ! $event->verify_digest() ) {
				throw new InvalidArgumentException( 'Authoritative replay requires intact recorded events.' );
			}
			$event_id = $event->event_id();
			if ( isset( $seen_event_ids[ $event_id ] ) ) {
				throw new InvalidArgumentException( 'Authoritative stream repeats a globally unique event ID.' );
			}
			$seen_event_ids[ $event_id ] = true;
			if ( null === $stream_id ) {
				$stream_id = $event->stream_id();
				$case_key_digest = $event->case_key_digest();
			} elseif ( $event->stream_id() !== $stream_id || $event->case_key_digest() !== $case_key_digest ) {
				throw new InvalidArgumentException( 'Authoritative stream mixes stream or case identity.' );
			}
			if ( (string) $expected_sequence !== $event->stream_sequence() ) {
				throw new InvalidArgumentException( 'Authoritative stream sequence is gapped, duplicated, or reordered.' );
			}
			if ( 1 === $expected_sequence ) {
				if ( null !== $event->previous_event_digest() ) { throw new InvalidArgumentException( 'First event predecessor must be null.' ); }
			} elseif ( $event->previous_event_digest() !== $previous_digest ) {
				throw new InvalidArgumentException( 'Authoritative predecessor digest continuity failed.' );
			}
			$previous_digest = $event->event_digest();
			$expected_sequence++;
		}
		return true;
	}
}
