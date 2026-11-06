<?php
/**
 * Pure ODP bulletin contract. No WordPress, no network, no credentials.
 *
 * Public item fields match the worker validator: id, headline, preview, body,
 * published_at, expires_at, audience, source_url. status is not part of the
 * public feed; if a row still carries it, only "published" is kept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_Contract {
	const SCHEMA_VERSION = 1;
	const API_VERSION    = 1;
	const FEED_URL       = 'https://gridhouse-odp-bulletins.gridhouse-digital10.workers.dev';
	const MAX_BULLETINS  = 200;
	const MAX_HEADLINE   = 500;
	const MAX_PREVIEW    = 2000;
	const MAX_BODY       = 50000;
	const DISCLAIMER     = 'This is a plain-language summary from Gridhouse, not an official ODP publication.';

	/** @return array<int,string> */
	public static function allowlist(): array {
		return array(
			self::FEED_URL,
			self::FEED_URL . '/',
			self::FEED_URL . '/bulletins.json',
		);
	}

	public static function is_allowlisted( string $url ): bool {
		return in_array( $url, self::allowlist(), true );
	}

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	/**
	 * @param mixed $payload
	 * @return array{ok:bool,code?:string,feed?:array<string,mixed>}
	 */
	public static function validate( $payload ): array {
		if ( ! is_array( $payload ) || ! array_key_exists( 'schema_version', $payload ) || ! array_key_exists( 'updated_at', $payload ) || ! array_key_exists( 'bulletins', $payload ) ) {
			return self::fail( 'malformed' );
		}
		if ( 1 !== $payload['schema_version'] ) {
			return self::fail( 'unknown_schema' );
		}
		$updated = self::parse_iso( $payload['updated_at'] );
		if ( null === $updated || ! is_array( $payload['bulletins'] ) || count( $payload['bulletins'] ) > self::MAX_BULLETINS ) {
			return self::fail( 'malformed' );
		}

		$items = array();
		$seen  = array();
		foreach ( $payload['bulletins'] as $row ) {
			if ( ! is_array( $row ) ) {
				return self::fail( 'malformed' );
			}
			$status = array_key_exists( 'status', $row ) ? trim( (string) $row['status'] ) : 'published';
			if ( 'published' !== $status ) {
				continue;
			}
			$item = self::validate_item( $row );
			if ( ! $item['ok'] ) {
				return self::fail( 'malformed' );
			}
			$id = $item['item']['id'];
			if ( isset( $seen[ $id ] ) ) {
				return self::fail( 'malformed' );
			}
			$seen[ $id ] = true;
			$items[]     = $item['item'];
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['published_unix'] <=> $a['published_unix'];
			}
		);

		return array(
			'ok'   => true,
			'feed' => array(
				'schema_version' => self::SCHEMA_VERSION,
				'updated_at'     => $updated['raw'],
				'bulletins'      => $items,
			),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{ok:bool,item?:array<string,mixed>}
	 */
	public static function validate_item( array $row ): array {
		$id       = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
		$headline = isset( $row['headline'] ) ? trim( (string) $row['headline'] ) : '';
		$preview  = isset( $row['preview'] ) ? trim( (string) $row['preview'] ) : '';
		$body     = isset( $row['body'] ) ? str_replace( array( "\r\n", "\r" ), "\n", (string) $row['body'] ) : '';
		$body     = trim( $body );
		if ( ! self::valid_id( $id ) || ! self::plain_text( $headline, self::MAX_HEADLINE ) || ! self::plain_text( $preview, self::MAX_PREVIEW ) || ! self::plain_text( $body, self::MAX_BODY ) ) {
			return array( 'ok' => false );
		}
		$audience = isset( $row['audience'] ) ? trim( (string) $row['audience'] ) : '';
		if ( ! in_array( $audience, array( 'employee', 'agency_lead', 'both' ), true ) ) {
			return array( 'ok' => false );
		}
		$published = self::parse_iso( $row['published_at'] ?? null );
		if ( null === $published ) {
			return array( 'ok' => false );
		}
		$expires_unix = null;
		$expires_raw  = null;
		if ( array_key_exists( 'expires_at', $row ) && null !== $row['expires_at'] && '' !== trim( (string) $row['expires_at'] ) ) {
			$expires = self::parse_iso( $row['expires_at'] );
			if ( null === $expires ) {
				return array( 'ok' => false );
			}
			$expires_unix = $expires['unix'];
			$expires_raw  = $expires['raw'];
		}
		$source = null;
		if ( array_key_exists( 'source_url', $row ) && null !== $row['source_url'] && '' !== trim( (string) $row['source_url'] ) ) {
			$safe = self::safe_http_url( $row['source_url'] );
			if ( false === $safe ) {
				return array( 'ok' => false );
			}
			$source = $safe;
		}

		return array(
			'ok'   => true,
			'item' => array(
				'id'              => $id,
				'headline'        => $headline,
				'preview'         => $preview,
				'body'            => $body,
				'published_at'    => $published['raw'],
				'published_unix'  => $published['unix'],
				'expires_at'      => $expires_raw,
				'expires_unix'    => $expires_unix,
				'audience'        => $audience,
				'source_url'      => $source,
			),
		);
	}

	/** @param mixed $value */
	public static function parse_iso( $value ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$raw = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $raw ) ) {
			return null;
		}
		$dt = date_create_immutable( $raw );
		if ( ! $dt ) {
			return null;
		}
		return array(
			'raw'  => $raw,
			'unix' => $dt->getTimestamp(),
		);
	}

	/**
	 * Empty becomes null. A non-empty unsafe URL returns false.
	 *
	 * @param mixed $value
	 * @return string|null|false
	 */
	public static function safe_http_url( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$url = trim( $value );
		if ( '' === $url ) {
			return null;
		}
		if ( preg_match( '/[\s<>]/', $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return $url;
	}

	/** @param array<string,mixed> $item */
	public static function is_current( array $item, int $now ): bool {
		if ( (int) $item['published_unix'] > $now ) {
			return false;
		}
		if ( null !== $item['expires_unix'] && (int) $item['expires_unix'] <= $now ) {
			return false;
		}
		return true;
	}

	/** @param array<string,mixed> $item */
	public static function for_context( array $item, string $context ): bool {
		$audience = (string) $item['audience'];
		if ( 'admin' === $context ) {
			return 'agency_lead' === $audience || 'both' === $audience;
		}
		if ( 'employee' === $context ) {
			return 'employee' === $audience || 'both' === $audience;
		}
		return false;
	}

	/**
	 * @param array<string,mixed>|null $feed
	 * @return array<int,array<string,mixed>>
	 */
	public static function select( ?array $feed, int $now, string $context ): array {
		if ( ! is_array( $feed ) || empty( $feed['bulletins'] ) || ! is_array( $feed['bulletins'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $feed['bulletins'] as $item ) {
			if ( ! is_array( $item ) || ! self::is_current( $item, $now ) || ! self::for_context( $item, $context ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/** @param array<string,mixed>|null $feed */
	public static function classify( ?array $feed, string $id, int $now ): string {
		if ( ! self::valid_id( $id ) || ! is_array( $feed ) || empty( $feed['bulletins'] ) ) {
			return 'unknown';
		}
		foreach ( $feed['bulletins'] as $item ) {
			if ( ! is_array( $item ) || (string) $item['id'] !== $id ) {
				continue;
			}
			if ( (int) $item['published_unix'] > $now ) {
				return 'scheduled';
			}
			if ( null !== $item['expires_unix'] && (int) $item['expires_unix'] <= $now ) {
				return 'expired';
			}
			return 'current';
		}
		return 'unknown';
	}

	/** @param array<string,mixed>|null $feed */
	public static function find( ?array $feed, string $id ): ?array {
		if ( ! is_array( $feed ) || empty( $feed['bulletins'] ) ) {
			return null;
		}
		foreach ( $feed['bulletins'] as $item ) {
			if ( is_array( $item ) && (string) $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/** @return array<int,string> */
	public static function paragraphs( string $body ): array {
		$body  = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$parts = preg_split( "/\n[ \t]*\n/", trim( $body ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			if ( '' === trim( $part ) ) {
				continue;
			}
			$out[] = $part;
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,query:string}
	 */
	public static function paginate( array $items, string $query, int $page, int $per_page = 9 ): array {
		$query = trim( $query );
		if ( strlen( $query ) > 120 ) {
			$query = substr( $query, 0, 120 );
		}
		if ( '' !== $query ) {
			$needle = self::lower( $query );
			$items  = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $needle ): bool {
						$hay = self::lower( (string) $item['headline'] . "\n" . (string) $item['preview'] . "\n" . (string) $item['body'] );
						return false !== strpos( $hay, $needle );
					}
				)
			);
		}
		$total = count( $items );
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$page  = min( $pages, max( 1, $page ) );
		$slice = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		return array(
			'items' => $slice,
			'total' => $total,
			'page'  => $page,
			'pages' => $total > 0 ? $pages : 0,
			'query' => $query,
		);
	}

	public static function audience_label( string $audience ): string {
		if ( 'employee' === $audience ) {
			return 'Employees';
		}
		if ( 'agency_lead' === $audience ) {
			return 'Agency leads';
		}
		if ( 'both' === $audience ) {
			return 'Employees and agency leads';
		}
		return 'Unlabeled';
	}

	/** @return array<int,string> */
	public static function context_audiences( string $context ): array {
		if ( 'admin' === $context ) {
			return array( 'agency_lead', 'both' );
		}
		if ( 'employee' === $context ) {
			return array( 'employee', 'both' );
		}
		return array();
	}

	private static function plain_text( string $value, int $max ): bool {
		if ( '' === $value || strlen( $value ) > $max ) {
			return false;
		}
		if ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
			return false;
		}
		return 1 !== preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value );
	}

	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/** @return array{ok:bool,code:string} */
	private static function fail( string $code ): array {
		return array(
			'ok'   => false,
			'code' => $code,
		);
	}
}
