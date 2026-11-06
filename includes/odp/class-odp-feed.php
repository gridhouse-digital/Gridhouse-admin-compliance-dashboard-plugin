<?php
/**
 * Fetch and last-good cache policy for the public bulletin feed.
 * Transport and storage are injected so this class does not touch the network.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_Feed {
	const TTL       = 1800;
	const TIMEOUT   = 8;
	const MAX_BYTES = 524288;

	/**
	 * @param array{fetched_at:int,feed:array<string,mixed>}|null $cache
	 * @param callable $transport function (string $url, int $timeout): array{status:int,body:string}
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}
	 */
	public static function read( int $now, ?array $cache, callable $transport, string $url, int $ttl = self::TTL ): array {
		if ( ! GHCA_ODP_Contract::is_allowlisted( $url ) ) {
			return self::unavailable( 'url_not_allowlisted' );
		}

		$cached = self::usable_cache( $cache );
		if ( null !== $cached && $ttl > 0 && ( $now - $cached['fetched_at'] ) >= 0 && ( $now - $cached['fetched_at'] ) < $ttl ) {
			return self::success( $cached['feed'], $cached['fetched_at'], $now, false, null );
		}

		try {
			$response = $transport( $url, self::TIMEOUT );
		} catch ( \Throwable $e ) {
			return self::preserve( $cached, 'offline', 'network' );
		}
		if ( ! is_array( $response ) ) {
			return self::preserve( $cached, 'offline', 'network' );
		}

		$status = (int) ( $response['status'] ?? 0 );
		$body   = (string) ( $response['body'] ?? '' );
		if ( $status <= 0 ) {
			return self::preserve( $cached, 'offline', 'network' );
		}
		if ( 200 !== $status ) {
			return self::preserve( $cached, 'cached', 'http_' . $status );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return self::preserve( $cached, 'cached', 'too_large' );
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return self::preserve( $cached, 'cached', 'malformed' );
		}
		$validated = GHCA_ODP_Contract::validate( $decoded );
		if ( empty( $validated['ok'] ) ) {
			return self::preserve( $cached, 'cached', (string) ( $validated['code'] ?? 'malformed' ) );
		}

		return self::success( $validated['feed'], $now, $now, false, array(
			'fetched_at' => $now,
			'feed'       => $validated['feed'],
		) );
	}

	/** @param array<string,mixed>|null $cache @return array{fetched_at:int,feed:array}|null */
	private static function usable_cache( ?array $cache ): ?array {
		if ( ! is_array( $cache ) || ! isset( $cache['fetched_at'], $cache['feed'] ) ) {
			return null;
		}
		$validated = GHCA_ODP_Contract::validate( $cache['feed'] );
		if ( empty( $validated['ok'] ) ) {
			return null;
		}
		return array(
			'fetched_at' => (int) $cache['fetched_at'],
			'feed'       => $validated['feed'],
		);
	}

	/**
	 * @param array{fetched_at:int,feed:array}|null $cached
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}
	 */
	private static function preserve( ?array $cached, string $kind, string $error ): array {
		if ( null === $cached ) {
			return self::unavailable( $error );
		}
		return array(
			'state'       => 'offline' === $kind ? 'offline' : 'cached',
			'stale'       => true,
			'error'       => $error,
			'fetched_at'  => $cached['fetched_at'],
			'feed'        => $cached['feed'],
			'cache_write' => null,
		);
	}

	/**
	 * @param array<string,mixed> $feed
	 * @param array{fetched_at:int,feed:array}|null $write
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}
	 */
	private static function success( array $feed, int $fetched_at, int $now, bool $stale, ?array $write ): array {
		$any = false;
		foreach ( array( 'admin', 'employee' ) as $context ) {
			if ( GHCA_ODP_Contract::select( $feed, $now, $context ) ) {
				$any = true;
				break;
			}
		}
		if ( ! $any && ! empty( $feed['bulletins'] ) ) {
			foreach ( $feed['bulletins'] as $item ) {
				if ( is_array( $item ) && GHCA_ODP_Contract::is_current( $item, $now ) ) {
					$any = true;
					break;
				}
			}
		}
		return array(
			'state'       => $any ? 'fresh' : 'empty',
			'stale'       => $stale,
			'error'       => null,
			'fetched_at'  => $fetched_at,
			'feed'        => $feed,
			'cache_write' => $write,
		);
	}

	/**
	 * Context list. A successful feed with nothing for this context is empty.
	 * A failed refresh stays cached or offline even when the saved list is empty.
	 *
	 * @param array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array} $read
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,updated_at:?string,bulletins:array<int,array<string,mixed>>,audience_filter:array<int,string>}
	 */
	public static function present( array $read, int $now, string $context ): array {
		$items = GHCA_ODP_Contract::select( $read['feed'] ?? null, $now, $context );
		$state = (string) $read['state'];
		if ( ( 'fresh' === $state || 'empty' === $state ) && ! $items ) {
			$state = 'empty';
		}
		$updated = is_array( $read['feed'] ?? null ) ? (string) ( $read['feed']['updated_at'] ?? '' ) : '';
		return array(
			'state'           => $state,
			'stale'           => ! empty( $read['stale'] ),
			'error'           => $read['error'] ?? null,
			'fetched_at'      => $read['fetched_at'] ?? null,
			'updated_at'      => '' !== $updated ? $updated : null,
			'bulletins'       => $items,
			'audience_filter' => GHCA_ODP_Contract::context_audiences( $context ),
		);
	}

	/** @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array} */
	private static function unavailable( string $error ): array {
		return array(
			'state'       => 'unavailable',
			'stale'       => false,
			'error'       => $error,
			'fetched_at'  => null,
			'feed'        => null,
			'cache_write' => null,
		);
	}
}
