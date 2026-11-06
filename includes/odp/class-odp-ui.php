<?php
/**
 * Bulletin markup. Text is escaped. The drawer and the full page share one
 * plain-text body; the library shows the separate preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_UI {
	public static function state_message( string $state ): string {
		if ( 'empty' === $state ) {
			return __( 'No ODP bulletins right now.', 'ghca-acd' );
		}
		if ( 'cached' === $state ) {
			return __( 'Showing the last bulletin feed that could be verified. A newer copy could not be loaded.', 'ghca-acd' );
		}
		if ( 'offline' === $state ) {
			return __( 'The bulletin service did not respond. Showing the last verified feed.', 'ghca-acd' );
		}
		if ( 'unavailable' === $state ) {
			return __( 'ODP bulletins are unavailable right now.', 'ghca-acd' );
		}
		return '';
	}

	public static function result_message( string $result ): string {
		if ( 'expired' === $result ) {
			return __( 'This bulletin is no longer available.', 'ghca-acd' );
		}
		if ( 'scheduled' === $result ) {
			return __( 'This bulletin is not available yet.', 'ghca-acd' );
		}
		if ( 'unavailable' === $result || 'unconfirmed' === $result ) {
			return __( 'This id cannot be confirmed until the bulletin feed refreshes. The saved copy does not contain it.', 'ghca-acd' );
		}
		if ( 'not_in_view' === $result ) {
			return __( 'This bulletin is not included in this view.', 'ghca-acd' );
		}
		return __( 'No bulletin with this id is in the current feed.', 'ghca-acd' );
	}

	/** @param array<string,mixed> $model */
	public static function ticker( array $model ): string {
		$items = isset( $model['items'] ) && is_array( $model['items'] ) ? $model['items'] : array();
		$state = (string) ( $model['state'] ?? 'empty' );
		if ( ! $items && 'empty' === $state ) {
			return '';
		}
		$html  = '<section class="gh-ticker" data-gh-ticker aria-label="' . esc_attr__( 'ODP bulletins', 'ghca-acd' ) . '">';
		$html .= '<span class="gh-ticker__mark">' . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</span>';
		if ( $items ) {
			$html .= '<button type="button" class="gh-ticker__pause" data-gh-ticker-pause data-label-pause="' . esc_attr__( 'Pause', 'ghca-acd' ) . '" data-label-play="' . esc_attr__( 'Play', 'ghca-acd' ) . '" aria-pressed="false">' . esc_html__( 'Pause', 'ghca-acd' ) . '</button>';
			$html .= '<div class="gh-ticker__viewport"><ul class="gh-ticker__track">';
			foreach ( $items as $item ) {
				$html .= '<li><a href="' . esc_url( (string) $item['url'] ) . '" data-gh-open-bulletin="' . esc_attr( (string) $item['id'] ) . '">';
				$html .= '<time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( (string) $item['date_label'] ) . '</time> ';
				$html .= esc_html( (string) $item['headline'] ) . '</a></li>';
			}
			$html .= '</ul></div>';
			if ( in_array( $state, array( 'cached', 'offline' ), true ) ) {
				$html .= '<p class="gh-ticker__status" role="status">' . esc_html( self::state_message( $state ) ) . '</p>';
			}
		} else {
			$html .= '<p class="gh-ticker__status" role="status">' . esc_html( self::state_message( $state ) ) . '</p>';
		}
		$html .= '<a class="gh-ticker__all" href="' . esc_url( (string) $model['library_url'] ) . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . '</a>';
		$html .= '</section>';
		return $html;
	}

	/** @param array<string,mixed> $model */
	public static function library( array $model ): string {
		$state = (string) ( $model['state'] ?? 'unavailable' );
		$query = (string) ( $model['query'] ?? '' );
		$items = isset( $model['items'] ) && is_array( $model['items'] ) ? $model['items'] : array();
		$html  = '<header class="gh-odp-head"><p class="gh-odp-kicker">' . esc_html( (string) $model['kicker'] ) . '</p>';
		$html .= '<h1>' . esc_html__( 'ODP Bulletins', 'ghca-acd' ) . '</h1>';
		$html .= '<p class="gh-odp-lead">' . esc_html( self::context_lead( (string) $model['context'] ) ) . '</p></header>';
		$html .= self::status_block( $state );
		$html .= '<form class="gh-odp-search" method="get" action="' . esc_url( (string) $model['action'] ) . '" role="search">';
		$html .= '<label>' . esc_html__( 'Search bulletins', 'ghca-acd' ) . ' <input type="search" name="ghca_odp_q" maxlength="120" value="' . esc_attr( $query ) . '"></label>';
		$html .= '<button type="submit">' . esc_html__( 'Search', 'ghca-acd' ) . '</button></form>';
		if ( ! $items ) {
			if ( '' !== $query ) {
				$html .= '<p class="gh-odp-empty" role="status">' . esc_html__( 'No bulletins match that search.', 'ghca-acd' ) . '</p>';
			} elseif ( 'fresh' === $state || 'empty' === $state ) {
				$html .= '<p class="gh-odp-empty" role="status">' . esc_html__( 'No ODP bulletins right now.', 'ghca-acd' ) . '</p>';
			}
			return $html . self::payload_script( $model );
		}
		$html .= '<p class="gh-odp-count">' . esc_html( sprintf(
			/* translators: 1: first visible row, 2: last visible row, 3: total matches */
			__( 'Showing %1$d–%2$d of %3$d', 'ghca-acd' ),
			(int) $model['from'],
			(int) $model['to'],
			(int) $model['total']
		) ) . '</p>';
		$html .= '<ul class="gh-odp-library">';
		foreach ( $items as $item ) {
			$html .= '<li class="gh-odp-card"><p class="gh-odp-card__meta"><time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( (string) $item['date_label'] ) . '</time>';
			$html .= ' <span>' . esc_html( (string) $item['audience_label'] ) . '</span></p>';
			$html .= '<h2><a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['headline'] ) . '</a></h2>';
			$html .= '<p>' . esc_html( (string) $item['preview'] ) . '</p>';
			$html .= '<button type="button" data-gh-open-bulletin="' . esc_attr( (string) $item['id'] ) . '">' . esc_html__( 'Read summary', 'ghca-acd' ) . '</button></li>';
		}
		$html .= '</ul>';
		$html .= '<nav class="gh-odp-pager" aria-label="' . esc_attr__( 'Bulletin pages', 'ghca-acd' ) . '">';
		if ( ! empty( $model['prev_url'] ) ) {
			$html .= '<a href="' . esc_url( (string) $model['prev_url'] ) . '">' . esc_html__( 'Previous', 'ghca-acd' ) . '</a>';
		}
		if ( ! empty( $model['next_url'] ) ) {
			$html .= '<a href="' . esc_url( (string) $model['next_url'] ) . '">' . esc_html__( 'Next', 'ghca-acd' ) . '</a>';
		}
		$html .= '</nav>';
		return $html . self::payload_script( $model );
	}

	/** @param array<string,mixed> $model */
	public static function single( array $model ): string {
		$result = (string) ( $model['result'] ?? 'unknown' );
		$html   = '<header class="gh-odp-head"><p class="gh-odp-kicker">' . esc_html( (string) $model['kicker'] ) . '</p>';
		$html  .= '<p><a href="' . esc_url( (string) $model['library_url'] ) . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . '</a></p></header>';
		if ( 'current' !== $result ) {
			$html .= '<h1>' . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</h1>';
			$message = self::result_message( $result );
			if ( 'not_in_view' === $result && ! empty( $model['audience_label'] ) ) {
				$message = sprintf(
					/* translators: %s: audience label such as Agency leads */
					__( 'This bulletin is labeled for %s and is not included in this view. The label is not a permission, and the text is not shown here.', 'ghca-acd' ),
					(string) $model['audience_label']
				);
			}
			$html .= '<p class="gh-odp-empty" role="status">' . esc_html( $message ) . '</p>';
			return $html;
		}
		$item  = $model['item'];
		$html .= '<article class="gh-odp-article">';
		$html .= '<p class="gh-odp-card__meta"><time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( (string) $item['date_label'] ) . '</time> ';
		$html .= '<span>' . esc_html( (string) $item['audience_label'] ) . '</span></p>';
		$html .= '<h1>' . esc_html( (string) $item['headline'] ) . '</h1>';
		if ( ! empty( $model['audience_note'] ) ) {
			$html .= '<p class="gh-odp-note" role="note">' . esc_html( (string) $model['audience_note'] ) . '</p>';
		}
		$html .= '<div class="gh-odp-tools"><button type="button" data-gh-copy data-url="' . esc_attr( (string) $item['url'] ) . '">' . esc_html__( 'Copy link', 'ghca-acd' ) . '</button>';
		$html .= '<span data-gh-copy-status class="gh-sr" role="status"></span></div>';
		$html .= self::body_html( (string) $item['body'] );
		$html .= self::source_link( $item['source_url'] ?? null );
		$html .= '<p class="gh-odp-disclaimer">' . esc_html( GHCA_ODP_Contract::DISCLAIMER ) . '</p>';
		$html .= '</article>';
		return $html;
	}

	public static function body_html( string $body ): string {
		$html = '<div class="gh-odp-body">';
		foreach ( GHCA_ODP_Contract::paragraphs( $body ) as $paragraph ) {
			$html .= '<p class="gh-odp-copy">' . esc_html( $paragraph ) . '</p>';
		}
		return $html . '</div>';
	}

	/** @param mixed $url */
	public static function source_link( $url ): string {
		$safe = GHCA_ODP_Contract::safe_http_url( $url );
		if ( ! is_string( $safe ) || '' === $safe ) {
			return '';
		}
		return '<p><a class="gh-odp-source" href="' . esc_url( $safe ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read source', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html__( '(opens an external page in a new tab)', 'ghca-acd' ) . '</span></a></p>';
	}

	/** @param array<string,mixed> $model */
	public static function drawer( array $model ): string {
		$html  = '<div class="gh-drawer" id="gh-odp-drawer" hidden>';
		$html .= '<div class="gh-drawer__backdrop" data-gh-drawer-close></div>';
		$html .= '<aside class="gh-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="gh-odp-drawer-title" tabindex="-1">';
		$html .= '<header class="gh-drawer__bar"><p>' . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</p>';
		$html .= '<button type="button" data-gh-drawer-close>' . esc_html__( 'Close', 'ghca-acd' ) . '</button></header>';
		$html .= '<p class="gh-odp-card__meta" data-gh-drawer-meta></p>';
		$html .= '<h2 id="gh-odp-drawer-title" data-gh-drawer-title></h2>';
		$html .= '<div class="gh-odp-tools"><a data-gh-drawer-full href="' . esc_url( (string) ( $model['library_url'] ?? '' ) ) . '">' . esc_html__( 'Open full page', 'ghca-acd' ) . '</a>';
		$html .= '<a href="' . esc_url( (string) ( $model['library_url'] ?? '' ) ) . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . '</a>';
		$html .= '<button type="button" data-gh-copy data-url="">' . esc_html__( 'Copy link', 'ghca-acd' ) . '</button>';
		$html .= '<span data-gh-copy-status class="gh-sr" role="status"></span></div>';
		$html .= '<div class="gh-odp-body" data-gh-drawer-body></div>';
		$html .= '<p data-gh-drawer-source-wrap hidden></p>';
		$html .= '<p class="gh-odp-disclaimer">' . esc_html( GHCA_ODP_Contract::DISCLAIMER ) . '</p>';
		$html .= '</aside></div>';
		return $html;
	}

	/** @param array<string,mixed> $model */
	public static function document( array $model ): string {
		$body = (string) ( $model['body_html'] ?? '' );
		$html = '<!DOCTYPE html><html lang="' . esc_attr( (string) ( $model['lang'] ?? 'en' ) ) . '"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<meta name="robots" content="noindex">';
		if ( ! empty( $model['rest_nonce'] ) ) {
			$html .= '<meta name="gh-odp-rest-nonce" content="' . esc_attr( (string) $model['rest_nonce'] ) . '">';
		}
		if ( ! empty( $model['rest_root'] ) ) {
			$html .= '<meta name="gh-odp-rest-root" content="' . esc_attr( (string) $model['rest_root'] ) . '">';
		}
		$html .= '<title>' . esc_html( (string) $model['title'] ) . '</title>';
		$html .= '<link rel="stylesheet" href="' . esc_url( (string) $model['css'] ) . '">';
		$html .= '</head><body class="gh-ui-body"><a class="gh-skip" href="#gh-odp-main">' . esc_html__( 'Skip to content', 'ghca-acd' ) . '</a>';
		$style = (string) ( $model['brand_style'] ?? '' );
		$html .= '<div class="gh-ui gh-ui--' . esc_attr( (string) $model['context'] ) . '" data-gh-theme="' . esc_attr( (string) $model['theme'] ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '">';
		$html .= '<header class="gh-ui-bar"><a href="' . esc_url( (string) $model['home_url'] ) . '">' . esc_html( (string) $model['home_label'] ) . '</a>';
		$html .= '<span>' . esc_html( (string) $model['context_label'] ) . '</span></header>';
		$html .= '<main id="gh-odp-main">' . $body . '</main>';
		$html .= self::drawer( $model );
		$html .= '</div><script src="' . esc_url( (string) $model['js'] ) . '" defer></script></body></html>';
		return $html;
	}

	/** @param array<string,mixed> $model */
	public static function payload_script( array $model ): string {
		$payload = isset( $model['payload'] ) && is_array( $model['payload'] ) ? $model['payload'] : array();
		$json    = json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}
		return '<script type="application/json" id="gh-odp-data">' . $json . '</script>';
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	public static function public_item( array $item, string $url, string $date_label ): array {
		$source = GHCA_ODP_Contract::safe_http_url( $item['source_url'] ?? null );
		return array(
			'id'             => (string) $item['id'],
			'headline'       => (string) $item['headline'],
			'preview'        => (string) $item['preview'],
			'body'           => (string) $item['body'],
			'paragraphs'     => GHCA_ODP_Contract::paragraphs( (string) $item['body'] ),
			'published_at'   => (string) $item['published_at'],
			'date_label'     => $date_label,
			'audience'       => (string) $item['audience'],
			'audience_label' => GHCA_ODP_Contract::audience_label( (string) $item['audience'] ),
			'source_url'     => is_string( $source ) ? $source : null,
			'url'            => $url,
		);
	}

	private static function context_lead( string $context ): string {
		if ( 'admin' === $context ) {
			return __( 'Agency view. This list shows bulletins labeled for agency leads, plus bulletins labeled for both. Employee-only labels stay off this list.', 'ghca-acd' );
		}
		return __( 'Employee view. This list shows bulletins labeled for employees, plus bulletins labeled for both.', 'ghca-acd' );
	}

	private static function status_block( string $state ): string {
		if ( ! in_array( $state, array( 'cached', 'offline', 'unavailable' ), true ) ) {
			return '';
		}
		return '<p class="gh-odp-status gh-odp-status--' . esc_attr( $state ) . '" role="status">' . esc_html( self::state_message( $state ) ) . '</p>';
	}
}
