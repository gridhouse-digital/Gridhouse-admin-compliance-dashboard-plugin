<?php
/**
 * Persistence boundary for reminder communications, deliveries, and templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ACD_Messaging_Repository {
	const MAX_DELIVERY_ATTEMPTS = 3;

	/**
	 * @param array<string,mixed> $communication
	 * @param array<string,mixed> $delivery
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_email( array $communication, array $delivery ) {
		return self::create_communication(
			array_merge( $communication, array( 'requested_channels' => 'email' ) ),
			array( array_merge( $delivery, array( 'channel' => 'email', 'provider' => 'wordpress_mail' ) ) )
		);
	}

	/**
	 * @param array<string,mixed> $communication
	 * @param array<int,array<string,mixed>> $deliveries
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_communication( array $communication, array $deliveries ) {
		global $wpdb;

		$existing = self::get_by_idempotency_key( (string) $communication['idempotency_key'] );
		if ( $existing ) {
			$existing['duplicate'] = true;
			return $existing;
		}

		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );

		$inserted = $wpdb->insert(
			GHCA_ACD_Messaging_Schema::communications_table(),
			array(
				'employee_user_id'  => (int) $communication['employee_user_id'],
				'actor_user_id'     => (int) $communication['actor_user_id'],
				'source'            => substr( sanitize_key( (string) ( $communication['source'] ?? 'employee_drawer' ) ), 0, 32 ),
				'template_id'       => ! empty( $communication['template_id'] ) ? (int) $communication['template_id'] : null,
				'template_version'  => ! empty( $communication['template_version'] ) ? (int) $communication['template_version'] : null,
				'urgency'           => (string) $communication['urgency'],
				'subject'           => (string) $communication['subject'],
				'message'           => (string) $communication['message'],
				'requested_channels'=> (string) $communication['requested_channels'],
				'idempotency_key'   => (string) $communication['idempotency_key'],
				'state'             => 'queued',
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$existing = self::get_by_idempotency_key( (string) $communication['idempotency_key'] );
			if ( $existing ) {
				$existing['duplicate'] = true;
				return $existing;
			}
			return new WP_Error( 'ghca_message_create_failed', __( 'The reminder could not be queued.', 'ghca-acd' ) );
		}

		$communication_id = (int) $wpdb->insert_id;
		$delivery_ids     = array();
		foreach ( $deliveries as $delivery ) {
			$delivery_inserted = $wpdb->insert(
				GHCA_ACD_Messaging_Schema::deliveries_table(),
				array(
					'communication_id'   => $communication_id,
					'channel'            => (string) $delivery['channel'],
					'masked_destination' => (string) $delivery['masked_destination'],
					'destination_hash'   => ! empty( $delivery['destination_hash'] ) ? (string) $delivery['destination_hash'] : null,
					'provider'           => (string) $delivery['provider'],
					'state'              => 'queued',
					'attempt_count'      => 0,
					'queued_at'          => $now,
					'updated_at'         => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false === $delivery_inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'ghca_message_delivery_create_failed', __( 'One or more reminder deliveries could not be queued.', 'ghca-acd' ) );
			}
			$delivery_ids[] = (int) $wpdb->insert_id;
		}
		$wpdb->query( 'COMMIT' );

		return array(
			'communication_id' => $communication_id,
			'delivery_id'      => (int) reset( $delivery_ids ),
			'delivery_ids'     => $delivery_ids,
			'state'            => 'queued',
			'duplicate'        => false,
		);
	}

	/** @return array<string,mixed>|null */
	public static function get_by_idempotency_key( string $key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT c.id AS communication_id, c.state, MIN(d.id) AS delivery_id
				 FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c
				 LEFT JOIN ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' d ON d.communication_id = c.id
				 WHERE c.idempotency_key = %s GROUP BY c.id, c.state LIMIT 1',
				$key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function get_delivery( int $delivery_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT d.*, c.employee_user_id, c.actor_user_id, c.urgency, c.subject, c.message, c.state AS communication_state
				 FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' d
				 INNER JOIN ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c ON c.id = d.communication_id
				 WHERE d.id = %d LIMIT 1',
				$delivery_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function claim_delivery( int $delivery_id ): ?array {
		global $wpdb;
		self::recover_expired_leases();

		$token   = hash( 'sha256', wp_generate_uuid4() . '|' . $delivery_id . '|' . microtime( true ) );
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + 120 );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
				 SET state = %s, attempt_count = attempt_count + 1, attempted_at = %s, updated_at = %s, lease_token = %s, lease_expires_at = %s
				 WHERE id = %d AND state = %s AND attempt_count < %d',
				'processing',
				$now,
				$now,
				$token,
				$expires,
				$delivery_id,
				'queued',
				self::MAX_DELIVERY_ATTEMPTS
			)
		);

		if ( 1 !== (int) $updated ) {
			return null;
		}

		$row = self::get_delivery( $delivery_id );
		if ( ! $row || ! hash_equals( $token, (string) $row['lease_token'] ) ) {
			return null;
		}

		return $row;
	}

	public static function recover_expired_leases(): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id,channel FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE state = %s AND lease_expires_at IS NOT NULL AND lease_expires_at < %s',
				'processing',
				$now
			),
			ARRAY_A
		);

		$count = 0;
		foreach ( (array) $rows as $row ) {
			$id             = (int) ( $row['id'] ?? 0 );
			$sms_uncertain  = 'sms' === (string) ( $row['channel'] ?? '' );
			$error_code     = $sms_uncertain ? 'sms_outcome_unknown' : 'worker_interrupted';
			$error_summary  = $sms_uncertain
				? __( 'The SMS worker stopped while the provider outcome was unknown. Reconcile the message in Twilio before sending another reminder.', 'ghca-acd' )
				: __( 'The background email worker was interrupted. Review the delivery before retrying.', 'ghca-acd' );
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
					 SET state = %s, failed_at = %s, updated_at = %s, lease_token = NULL, lease_expires_at = NULL, last_error_code = %s, last_error_summary = %s
					 WHERE id = %d AND state = %s AND lease_expires_at < %s',
					'failed',
					$now,
					$now,
					$error_code,
					$error_summary,
					$id,
					'processing',
					$now
				)
			);
			if ( 1 === (int) $updated ) {
				++$count;
				self::sync_communication_state( $id, 'failed' );
			}
		}

		return $count;
	}

	public static function mark_accepted( int $delivery_id, string $lease_token ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
				 SET state = %s, accepted_at = %s, updated_at = %s, lease_token = NULL, lease_expires_at = NULL, last_error_code = NULL, last_error_summary = NULL
				 WHERE id = %d AND state = %s AND lease_token = %s',
				'accepted',
				$now,
				$now,
				$delivery_id,
				'processing',
				$lease_token
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::sync_communication_state( $delivery_id, 'sent' );
		return true;
	}

	public static function mark_provider_accepted( int $delivery_id, string $lease_token, string $provider_ref ): bool {
		global $wpdb;
		if ( ! preg_match( '/^SM[a-f0-9]{32}$/i', $provider_ref ) ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
				 SET state = %s, provider_ref = %s, accepted_at = %s, updated_at = %s, lease_token = NULL, lease_expires_at = NULL, last_error_code = NULL, last_error_summary = NULL
				 WHERE id = %d AND state = %s AND lease_token = %s AND provider_ref IS NULL',
				'accepted', $provider_ref, $now, $now, $delivery_id, 'processing', $lease_token
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}
		self::sync_communication_state( $delivery_id );
		return true;
	}

	/** @return true|WP_Error */
	public static function apply_provider_status( string $provider_ref, string $provider_status, string $error_code = '' ) {
		global $wpdb;
		$map = array(
			'accepted' => 'accepted', 'queued' => 'accepted', 'scheduled' => 'accepted', 'sending' => 'accepted',
			'sent' => 'sent', 'delivered' => 'delivered', 'failed' => 'failed', 'undelivered' => 'undelivered',
		);
		$provider_status = sanitize_key( $provider_status );
		if ( ! isset( $map[ $provider_status ] ) ) {
			return new WP_Error( 'twilio_status_invalid', __( 'The Twilio delivery status is not supported.', 'ghca-acd' ) );
		}
		$next     = $map[ $provider_status ];
		$ranks    = array( 'queued' => 0, 'processing' => 0, 'accepted' => 1, 'sent' => 2, 'delivered' => 3, 'failed' => 3, 'undelivered' => 3 );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,state FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE provider = %s AND provider_ref = %s LIMIT 1', 'twilio', $provider_ref ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'twilio_delivery_not_found', __( 'The Twilio delivery reference is unknown.', 'ghca-acd' ) );
			}
			$current  = (string) $row['state'];
			$terminal = in_array( $current, array( 'delivered', 'failed', 'undelivered' ), true );
			if ( $terminal || ( $ranks[ $next ] ?? -1 ) < ( $ranks[ $current ] ?? -1 ) ) {
				return true;
			}

			$now     = current_time( 'mysql', true );
			$values  = array( 'state' => $next, 'updated_at' => $now );
			$formats = array( '%s', '%s' );
			if ( 'sent' === $next ) {
				$values['accepted_at'] = $now;
				$formats[] = '%s';
			} elseif ( 'delivered' === $next ) {
				$values['delivered_at'] = $now;
				$formats[] = '%s';
			} elseif ( in_array( $next, array( 'failed', 'undelivered' ), true ) ) {
				$values['failed_at'] = $now;
				$values['last_error_code'] = substr( sanitize_key( $error_code ?: 'twilio_' . $next ), 0, 64 );
				$values['last_error_summary'] = __( 'Twilio reported that the SMS was not delivered.', 'ghca-acd' );
				$formats = array_merge( $formats, array( '%s', '%s', '%s' ) );
			}
			$updated = $wpdb->update(
				GHCA_ACD_Messaging_Schema::deliveries_table(),
				$values,
				array( 'id' => (int) $row['id'], 'state' => $current ),
				$formats,
				array( '%d', '%s' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'twilio_status_store_failed', __( 'The Twilio delivery status could not be stored.', 'ghca-acd' ) );
			}
			if ( 1 === (int) $updated ) {
				self::sync_communication_state( (int) $row['id'] );
				return true;
			}
		}

		return new WP_Error( 'twilio_status_conflict', __( 'The Twilio delivery status changed concurrently. Twilio may retry this callback.', 'ghca-acd' ) );
	}

	public static function claim_webhook_event( string $event_key, string $type, string $provider_ref, string $provider_state ): bool {
		global $wpdb;
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . GHCA_ACD_Messaging_Schema::webhook_events_table() . ' (event_key,event_type,provider_ref,provider_state,processed_at) VALUES (%s,%s,%s,%s,%s)',
				$event_key,
				substr( sanitize_key( $type ), 0, 24 ),
				substr( sanitize_text_field( $provider_ref ), 0, 191 ),
				substr( sanitize_key( $provider_state ), 0, 32 ),
				current_time( 'mysql', true )
			)
		);
		return 1 === (int) $inserted;
	}

	public static function release_webhook_event( string $event_key ): void {
		global $wpdb;
		$wpdb->delete( GHCA_ACD_Messaging_Schema::webhook_events_table(), array( 'event_key' => $event_key ), array( '%s' ) );
	}

	public static function mark_failed( int $delivery_id, string $lease_token, string $code, string $summary ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
				 SET state = %s, failed_at = %s, updated_at = %s, lease_token = NULL, lease_expires_at = NULL, last_error_code = %s, last_error_summary = %s
				 WHERE id = %d AND state = %s AND lease_token = %s',
				'failed',
				$now,
				$now,
				sanitize_key( $code ),
				self::redact_error_summary( $summary ),
				$delivery_id,
				'processing',
				$lease_token
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::sync_communication_state( $delivery_id, 'failed' );
		return true;
	}

	public static function mark_schedule_failed( int $delivery_id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->update(
			GHCA_ACD_Messaging_Schema::deliveries_table(),
			array(
				'state'              => 'failed',
				'failed_at'          => $now,
				'updated_at'         => $now,
				'last_error_code'    => 'queue_schedule_failed',
				'last_error_summary' => __( 'The background email job could not be scheduled.', 'ghca-acd' ),
			),
			array( 'id' => $delivery_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::sync_communication_state( $delivery_id, 'failed' );
	}

	public static function requeue_failed( int $delivery_id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . GHCA_ACD_Messaging_Schema::deliveries_table() . '
				 SET state = %s, queued_at = %s, updated_at = %s, failed_at = NULL, last_error_code = NULL, last_error_summary = NULL
				 WHERE id = %d AND state = %s AND attempt_count < %d',
				'queued',
				$now,
				$now,
				$delivery_id,
				'failed',
				self::MAX_DELIVERY_ATTEMPTS
			)
		);

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::sync_communication_state( $delivery_id, 'queued' );
		return true;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function history_for_employee( int $employee_user_id, int $page = 1, int $per_page = 5, array $filters = array() ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 20, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$parts    = self::history_query_parts( $employee_user_id, $filters );
		$id_sql   = 'SELECT DISTINCT c.id FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c'
			. $parts['join'] . ' WHERE ' . implode( ' AND ', $parts['where'] )
			. ' ORDER BY c.id DESC LIMIT %d OFFSET %d';
		$id_args   = $parts['args'];
		$id_args[] = $per_page;
		$id_args[] = $offset;
		$id_query  = $wpdb->prepare( $id_sql, $id_args );

		$rows = $wpdb->get_results(
			'SELECT c.id AS communication_id, c.actor_user_id, c.source, c.urgency, c.subject, c.message, c.requested_channels, c.state AS communication_state,
				        c.created_at, d.id AS delivery_id, d.channel, d.masked_destination, d.provider, d.provider_ref, d.state AS delivery_state,
				        d.attempt_count, d.last_error_code, d.last_error_summary, d.accepted_at, d.delivered_at, d.failed_at
				 FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c
				 INNER JOIN (' . $id_query . ') page ON page.id = c.id
				 INNER JOIN ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' d ON d.communication_id = c.id
				 ORDER BY c.id DESC, d.id ASC',
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $filters */
	/**
	 * Per-employee delivery totals for the communication history header.
	 *
	 * One grouped query rather than a count per tile. Only states the schema
	 * actually records are reported -- there is no read/open tracking in this
	 * plugin, so nothing here implies the recipient opened anything.
	 *
	 * @return array{total:int,email:int,sms:int,delivered:int,failed:int}
	 */
	public static function history_stats( int $employee_user_id ): array {
		global $wpdb;

		$out = array( 'total' => 0, 'email' => 0, 'sms' => 0, 'delivered' => 0, 'failed' => 0 );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT d.channel AS channel, d.state AS state, COUNT(*) AS n
				 FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' d
				 INNER JOIN ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c ON c.id = d.communication_id
				 WHERE c.employee_user_id = %d
				 GROUP BY d.channel, d.state', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				max( 0, $employee_user_id )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$n       = (int) ( $row['n'] ?? 0 );
			$channel = (string) ( $row['channel'] ?? '' );
			$state   = (string) ( $row['state'] ?? '' );

			if ( 'email' === $channel ) {
				$out['email'] += $n;
			} elseif ( 'sms' === $channel ) {
				$out['sms'] += $n;
			}
			if ( 'delivered' === $state ) {
				$out['delivered'] += $n;
			} elseif ( in_array( $state, array( 'failed', 'undelivered' ), true ) ) {
				$out['failed'] += $n;
			}
		}

		$out['total'] = self::history_count( $employee_user_id );
		return $out;
	}

	public static function history_count( int $employee_user_id, array $filters = array() ): int {
		global $wpdb;
		$parts = self::history_query_parts( $employee_user_id, $filters );
		$sql   = 'SELECT COUNT(DISTINCT c.id) FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' c'
			. $parts['join'] . ' WHERE ' . implode( ' AND ', $parts['where'] );
		return (int) $wpdb->get_var(
			$wpdb->prepare( $sql, $parts['args'] )
		);
	}

	/** @return array<string,mixed>|null */
	public static function get_communication_for_employee( int $communication_id, int $employee_user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id AS communication_id, employee_user_id, source, urgency, subject, message, requested_channels, created_at
				 FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . '
				 WHERE id = %d AND employee_user_id = %d LIMIT 1',
				$communication_id,
				$employee_user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{join:string,where:array<int,string>,args:array<int,mixed>}
	 */
	private static function history_query_parts( int $employee_user_id, array $filters ): array {
		global $wpdb;

		$channel = isset( $filters['channel'] ) ? sanitize_key( (string) $filters['channel'] ) : '';
		$status  = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$search  = isset( $filters['search'] ) ? substr( sanitize_text_field( (string) $filters['search'] ), 0, 100 ) : '';
		$from    = self::valid_history_date( (string) ( $filters['date_from'] ?? '' ) );
		$to      = self::valid_history_date( (string) ( $filters['date_to'] ?? '' ) );
		$channels = array( 'email', 'sms' );
		$states   = array( 'queued', 'processing', 'accepted', 'sent', 'delivered', 'undelivered', 'failed', 'suppressed' );
		$where    = array( 'c.employee_user_id = %d' );
		$args     = array( max( 0, $employee_user_id ) );
		$join     = '';

		if ( in_array( $channel, $channels, true ) || in_array( $status, $states, true ) ) {
			$join = ' INNER JOIN ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' fd ON fd.communication_id = c.id';
		}
		if ( in_array( $channel, $channels, true ) ) {
			$where[] = 'fd.channel = %s';
			$args[]  = $channel;
		}
		if ( in_array( $status, $states, true ) ) {
			$where[] = 'fd.state = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(c.subject LIKE %s OR c.message LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( '' !== $from ) {
			$where[] = 'c.created_at >= %s';
			$args[]  = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where[] = 'c.created_at <= %s';
			$args[]  = $to . ' 23:59:59';
		}

		return array( 'join' => $join, 'where' => $where, 'args' => $args );
	}

	private static function valid_history_date( string $date ): string {
		$date = trim( $date );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return '';
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ? $date : '';
	}

	public static function recent_actor_count( int $actor_user_id, int $seconds ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' WHERE actor_user_id = %d AND created_at >= %s',
				$actor_user_id,
				$since
			)
		);
	}

	public static function employee_has_recent_message( int $employee_user_id, int $seconds ): bool {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . GHCA_ACD_Messaging_Schema::communications_table() . ' WHERE employee_user_id = %d AND created_at >= %s',
				$employee_user_id,
				$since
			)
		);
		return $count > 0;
	}

	/** @return array<int,int> */
	public static function queued_delivery_ids( int $limit = 25 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE state = %s AND attempt_count < %d ORDER BY queued_at ASC LIMIT %d',
				'queued',
				self::MAX_DELIVERY_ATTEMPTS,
				$limit
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_active_templates(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . GHCA_ACD_Messaging_Schema::templates_table() . ' WHERE active = 1 ORDER BY name ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_all_templates(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . GHCA_ACD_Messaging_Schema::templates_table() . ' ORDER BY active DESC, name ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null */
	public static function get_template( int $template_id, bool $active_only = true ): ?array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . GHCA_ACD_Messaging_Schema::templates_table() . ' WHERE id = %d';
		if ( $active_only ) {
			$sql .= ' AND active = 1';
		}
		$sql .= ' LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $template_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $data */
	public static function save_template( array $data ) {
		global $wpdb;
		$table = GHCA_ACD_Messaging_Schema::templates_table();
		$now   = current_time( 'mysql', true );
		$id    = isset( $data['id'] ) ? (int) $data['id'] : 0;

		$row = array(
			'name'             => (string) $data['name'],
			'description'      => (string) $data['description'],
			'allowed_channels' => (string) $data['allowed_channels'],
			'default_urgency'  => (string) $data['default_urgency'],
			'subject_template' => (string) $data['subject_template'],
			'message_template' => (string) $data['message_template'],
			'active'           => ! empty( $data['active'] ) ? 1 : 0,
			'updated_by'       => (int) $data['actor_user_id'],
			'updated_at'       => $now,
		);

		if ( $id > 0 ) {
			$current = self::get_template( $id, false );
			if ( ! $current ) {
				return new WP_Error( 'ghca_template_not_found', __( 'Message template not found.', 'ghca-acd' ) );
			}
			$row['version'] = (int) $current['version'] + 1;
			$result = $wpdb->update( $table, $row, array( 'id' => $id ) );
			return false === $result ? new WP_Error( 'ghca_template_update_failed', __( 'The message template could not be updated.', 'ghca-acd' ) ) : $id;
		}

		$row['version']    = 1;
		$row['created_by'] = (int) $data['actor_user_id'];
		$row['created_at'] = $now;
		$result = $wpdb->insert( $table, $row );
		return false === $result ? new WP_Error( 'ghca_template_create_failed', __( 'The message template could not be created.', 'ghca-acd' ) ) : (int) $wpdb->insert_id;
	}

	public static function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return '***';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
		return $first . '***@' . $parts[1];
	}

	private static function sync_communication_state( int $delivery_id, string $state = '' ): void {
		global $wpdb;
		$communication_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT communication_id FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE id = %d',
				$delivery_id
			)
		);
		if ( $communication_id <= 0 ) {
			return;
		}

		$states = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT state FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE communication_id = %d', $communication_id ) ) );
		if ( empty( $states ) ) {
			return;
		}
		$sent_states = array( 'accepted', 'sent', 'delivered' );
		$failed_states = array( 'failed', 'undelivered', 'suppressed' );
		$sent_count = count( array_intersect( $states, $sent_states ) );
		$failed_count = count( array_intersect( $states, $failed_states ) );
		if ( $sent_count === count( $states ) ) {
			$state = 'sent';
		} elseif ( $failed_count === count( $states ) ) {
			$state = 'failed';
		} elseif ( $sent_count > 0 && $failed_count > 0 ) {
			$state = 'partially_sent';
		} elseif ( in_array( 'processing', $states, true ) ) {
			$state = 'processing';
		} else {
			$state = 'queued';
		}

		$wpdb->update(
			GHCA_ACD_Messaging_Schema::communications_table(),
			array(
				'state'      => $state,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $communication_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function redact_error_summary( string $summary ): string {
		$summary = wp_strip_all_tags( $summary );
		$summary = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $summary );
		$summary = preg_replace( '/\+?[0-9][0-9() .\-]{7,}[0-9]/', '[redacted-phone]', (string) $summary );
		return substr( (string) $summary, 0, 500 );
	}
}
