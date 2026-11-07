<?php
/**
 * Bulletin markup, following the 4fa32636 design boards (.gh-ticker, .gh-bul,
 * .gh-bl, .gh-bp). Text is escaped. The drawer and the full page share one
 * plain-text body; the library shows the separate preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_UI {
	public static function icon( string $name, string $size = '' ): string {
		$class = 'gh-i' . ( '' !== $size ? ' gh-i--' . $size : '' ) . ' i-' . $name;
		return '<span class="' . esc_attr( $class ) . '" aria-hidden="true"></span>';
	}

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

	/**
	 * Shared app bar from the AdminChrome board.
	 *
	 * @param array<string,mixed> $chrome
	 */
	public static function appbar( array $chrome ): string {
		$logo = (string) ( $chrome['logo_url'] ?? '' );
		$org  = (string) ( $chrome['org'] ?? '' );
		$html = '<header class="gh-appbar"><div class="gh-container gh-appbar-in">';
		$html .= '<a class="gh-logo" href="' . esc_url( (string) ( $chrome['home_url'] ?? '' ) ) . '">';
		if ( '' !== $logo ) {
			$html .= '<span class="gh-logo-mark has-img" aria-hidden="true"><img src="' . esc_url( $logo ) . '" alt=""></span>';
		} else {
			$html .= '<span class="gh-logo-mark" aria-hidden="true">' . esc_html( (string) ( $chrome['mono'] ?? '' ) ) . '</span>';
		}
		$html .= '<span><span class="gh-logo-name">' . esc_html( $org ) . '</span><span class="gh-logo-sub">' . esc_html( (string) ( $chrome['sub'] ?? '' ) ) . '</span></span></a>';
		$nav = isset( $chrome['nav'] ) && is_array( $chrome['nav'] ) ? $chrome['nav'] : array();
		if ( $nav ) {
			$html .= '<nav class="gh-nav" aria-label="' . esc_attr__( 'Main', 'ghca-acd' ) . '">';
			foreach ( $nav as $link ) {
				$current = (string) ( $link['current'] ?? '' );
				$html   .= '<a href="' . esc_url( (string) $link['url'] ) . '"';
				$html   .= '' !== $current ? ' aria-current="' . esc_attr( $current ) . '"' : '';
				$html   .= ! empty( $link['tab'] ) ? ' data-ghca-tab-jump="' . esc_attr( (string) $link['tab'] ) . '"' : '';
				$html   .= '>' . self::icon( (string) $link['icon'] ) . esc_html( (string) $link['label'] ) . '</a>';
			}
			$html .= '</nav>';
		}
		$html .= '<div class="gh-appbar-tools">';
		if ( ! empty( $chrome['initials'] ) ) {
			$html .= '<span class="gh-avatar" role="img" aria-label="' . esc_attr( (string) ( $chrome['signed_in'] ?? '' ) ) . '">' . esc_html( (string) $chrome['initials'] ) . '</span>';
		}
		return $html . '</div></div></header>';
	}

	/** @param array<string,mixed> $model */
	public static function ticker( array $model ): string {
		$items = isset( $model['items'] ) && is_array( $model['items'] ) ? $model['items'] : array();
		$state = (string) ( $model['state'] ?? 'empty' );
		if ( ! $items && 'empty' === $state ) {
			return '';
		}
		$html  = '<section class="gh-ticker" data-gh-ticker aria-label="' . esc_attr__( 'ODP Bulletin', 'ghca-acd' ) . '">';
		$html .= '<span class="gh-ticker-lbl">' . self::icon( 'megaphone' ) . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</span>';
		if ( $items ) {
			$html .= '<div class="gh-ticker-vp"><ul class="gh-ticker-track" style="list-style:none;margin:0">';
			foreach ( $items as $item ) {
				$html .= '<li class="gh-ticker-item"><time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( (string) ( $item['date_short'] ?? $item['date_label'] ) ) . '</time>';
				$html .= '<a href="' . esc_url( (string) $item['url'] ) . '" data-gh-open-bulletin="' . esc_attr( (string) $item['id'] ) . '">' . esc_html( (string) $item['headline'] ) . '</a></li>';
			}
			$html .= '</ul></div>';
		} else {
			$html .= '<div class="gh-ticker-msg" role="status">' . esc_html( self::state_message( $state ) ) . '</div>';
		}
		$html .= '<div class="gh-ticker-ctl">';
		if ( $items && in_array( $state, array( 'cached', 'offline' ), true ) ) {
			$html .= '<span class="gh-ticker-state" role="status">' . self::icon( 'clock', 'sm' ) . esc_html__( 'Saved copy', 'ghca-acd' ) . '<span class="gh-sr"> ' . esc_html( self::state_message( $state ) ) . '</span></span>';
		}
		if ( count( $items ) > 1 ) {
			$html .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" data-gh-ticker-pause aria-pressed="false" aria-label="' . esc_attr__( 'Pause bulletin ticker', 'ghca-acd' ) . '" data-label-pause="' . esc_attr__( 'Pause bulletin ticker', 'ghca-acd' ) . '" data-label-play="' . esc_attr__( 'Play bulletin ticker', 'ghca-acd' ) . '">' . self::icon( 'pause' ) . '</button>';
		}
		$html .= '<a href="' . esc_url( (string) $model['library_url'] ) . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . '</a>';
		return $html . '</div></section>';
	}

	/** @param array<string,mixed> $model */
	public static function library( array $model ): string {
		$state = (string) ( $model['state'] ?? 'unavailable' );
		$query = (string) ( $model['query'] ?? '' );
		$items = isset( $model['items'] ) && is_array( $model['items'] ) ? $model['items'] : array();
		$total = (int) ( $model['total'] ?? 0 );
		$html  = '<div class="gh-odp"><div class="gh-odp-col"><div class="gh-odp-hd">';
		$html .= self::crumbs( $model, array( array( 'label' => __( 'ODP Bulletins', 'ghca-acd' ), 'url' => '' ) ) );
		$html .= '<div class="gh-odp-chiprow">' . self::chip() . '</div>';
		$html .= '<h1 id="gh-odp-h">' . esc_html__( 'ODP Bulletins', 'ghca-acd' ) . '</h1>';
		$html .= '<p class="gh-odp-intro">' . esc_html__( 'Plain-language updates to help you understand ODP guidance.', 'ghca-acd' ) . '</p>';
		$html .= '<div class="gh-odp-tools" role="search"><form method="get" action="' . esc_url( (string) $model['action'] ) . '">';
		$html .= '<div class="gh-input-wrap">' . self::icon( 'search' ) . '<label class="gh-sr" for="gh-odp-q">' . esc_html__( 'Search bulletins', 'ghca-acd' ) . '</label>';
		$html .= '<input id="gh-odp-q" class="gh-input" type="search" name="ghca_odp_q" maxlength="120" placeholder="' . esc_attr__( 'Search bulletins', 'ghca-acd' ) . '" value="' . esc_attr( $query ) . '"></div></form>';
		if ( $items ) {
			$count = '' !== $query
				? sprintf( /* translators: %d: matching bulletins */ __( '%d matching · newest first', 'ghca-acd' ), $total )
				: sprintf( /* translators: %d: bulletins in this view */ __( '%d bulletins · newest first', 'ghca-acd' ), $total );
			$html .= '<span class="gh-odp-count" role="status">' . esc_html( $count ) . '</span>';
		}
		$html .= '</div></div>';
		$html .= self::status_block( $state );
		if ( ! $items ) {
			if ( '' !== $query ) {
				$html .= self::empty_block( 'search', __( 'No bulletins match that search.', 'ghca-acd' ), __( 'Try a shorter word, or clear the search to see every bulletin.', 'ghca-acd' ) );
			} elseif ( 'fresh' === $state || 'empty' === $state ) {
				$html .= self::empty_block( 'megaphone', __( 'No ODP bulletins right now.', 'ghca-acd' ), __( 'New summaries appear here when Gridhouse publishes them.', 'ghca-acd' ) );
			}
			return $html . '</div>' . self::library_aside( $model ) . '</div>' . self::payload_script( $model );
		}
		$stale = in_array( $state, array( 'cached', 'offline' ), true );
		$html .= '<ol class="gh-bl' . ( $stale ? ' is-stale' : '' ) . '" aria-labelledby="gh-odp-h">';
		foreach ( $items as $item ) {
			$html .= '<li class="gh-bl-item"><div class="gh-bl-date"><strong>' . esc_html( (string) ( $item['date_md'] ?? $item['date_label'] ) ) . '</strong>' . esc_html( (string) ( $item['date_year'] ?? '' ) ) . '</div>';
			$html .= '<div class="gh-bl-main"><h2 class="gh-bl-h"><a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['headline'] ) . '</a></h2>';
			$html .= '<p class="gh-bl-p">' . esc_html( (string) $item['preview'] ) . '</p>';
			$html .= '<div class="gh-bl-ft"><a class="gh-bl-read" href="' . esc_url( (string) $item['url'] ) . '" data-gh-open-bulletin="' . esc_attr( (string) $item['id'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: bulletin headline */ __( 'Read bulletin: %s', 'ghca-acd' ), (string) $item['headline'] ) ) . '">' . esc_html__( 'Read bulletin', 'ghca-acd' ) . self::icon( 'arrow-right' ) . '</a>';
			if ( ! empty( $item['source_url'] ) ) {
				$html .= '<span class="gh-bl-meta">' . self::icon( 'file' ) . esc_html__( 'Official source linked', 'ghca-acd' ) . '</span>';
			}
			$html .= '<span class="gh-bl-meta">' . esc_html( (string) $item['audience_label'] ) . '</span>';
			$html .= '</div></div></li>';
		}
		$html .= '</ol>';
		$html .= self::pager( $model );
		return $html . '</div>' . self::library_aside( $model ) . '</div>' . self::payload_script( $model );
	}

	/** @param array<string,mixed> $model */
	public static function single( array $model ): string {
		$result = (string) ( $model['result'] ?? 'unknown' );
		$item   = 'current' === $result && isset( $model['item'] ) && is_array( $model['item'] ) ? $model['item'] : null;
		$trail  = array( array( 'label' => __( 'ODP Bulletins', 'ghca-acd' ), 'url' => (string) ( $model['library_url'] ?? '' ) ) );
		$trail[] = array( 'label' => $item ? (string) $item['headline'] : __( 'ODP Bulletin', 'ghca-acd' ), 'url' => '' );
		$html   = '<div class="gh-odp"><article class="gh-bp" aria-labelledby="gh-bp-h">' . self::crumbs( $model, $trail );
		if ( null === $item ) {
			$message = self::result_message( $result );
			if ( 'not_in_view' === $result && ! empty( $model['audience_label'] ) ) {
				$message = sprintf(
					/* translators: %s: audience label such as Agency leads */
					__( 'This bulletin is labeled for %s and is not included in this view. The label is not a permission, and the text is not shown here.', 'ghca-acd' ),
					(string) $model['audience_label']
				);
			}
			$html .= '<div class="gh-bp-top">' . self::chip() . '</div><h1 class="gh-bp-h" id="gh-bp-h">' . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</h1>';
			$html .= '<div class="gh-bul-msg" role="status"><span class="gh-bul-msgico">' . self::icon( 'info' ) . '</span><p>' . esc_html( $message ) . '</p></div>';
			$html .= self::back_nav( $model );
			return $html . '</article>' . self::more_aside( $model ) . '</div>';
		}
		$html .= '<div class="gh-bp-top">' . self::chip() . '<span class="gh-badge gh-badge--outline">' . esc_html( (string) $item['audience_label'] ) . '</span></div>';
		$html .= '<h1 class="gh-bp-h" id="gh-bp-h">' . esc_html( (string) $item['headline'] ) . '</h1>';
		if ( ! empty( $model['audience_note'] ) ) {
			$html .= '<p class="gh-odp-note" role="note">' . esc_html( (string) $model['audience_note'] ) . '</p>';
		}
		$published = sprintf( /* translators: %s: long publication date */ __( 'Published %s', 'ghca-acd' ), (string) ( $item['date_long'] ?? $item['date_label'] ) );
		$html .= '<div class="gh-bp-meta"><span class="gh-bul-date">' . self::icon( 'calendar' ) . '<time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( $published ) . '</time></span>';
		$html .= '<span><button class="gh-copy" type="button" data-gh-copy data-url="' . esc_attr( (string) $item['url'] ) . '">' . self::icon( 'link' ) . esc_html__( 'Copy link', 'ghca-acd' ) . '</button>';
		$html .= '<span data-gh-copy-status class="gh-sr" role="status"></span></span></div>';
		$html .= self::body_html( (string) $item['body'], 'gh-bp-body' );
		$html .= self::source_block( $item['source_url'] ?? null, 'page' );
		$html .= '<p class="gh-bp-note">' . self::icon( 'info' ) . esc_html( GHCA_ODP_Contract::DISCLAIMER ) . '</p>';
		$html .= self::back_nav( $model );
		return $html . '</article>' . self::more_aside( $model ) . '</div>';
	}

	public static function body_html( string $body, string $class = 'gh-bul-sum' ): string {
		$html = '<div class="' . esc_attr( $class ) . '"' . ( 'gh-bul-sum' === $class ? ' data-gh-drawer-body' : '' ) . '>';
		foreach ( GHCA_ODP_Contract::paragraphs( $body ) as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		return $html . '</div>';
	}

	/** @param mixed $url */
	public static function source_link( $url ): string {
		$safe = GHCA_ODP_Contract::safe_http_url( $url );
		if ( ! is_string( $safe ) || '' === $safe ) {
			return '';
		}
		return '<a class="gh-btn gh-btn--secondary" href="' . esc_url( $safe ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read source', 'ghca-acd' ) . self::icon( 'external' ) . '<span class="gh-sr"> ' . esc_html__( '(opens an external page in a new tab)', 'ghca-acd' ) . '</span></a>';
	}

	/** @param mixed $url */
	private static function source_block( $url, string $variant ): string {
		$link = self::source_link( $url );
		if ( '' === $link ) {
			return '';
		}
		$host = (string) parse_url( (string) GHCA_ODP_Contract::safe_http_url( $url ), PHP_URL_HOST );
		$meta = sprintf( /* translators: %s: source host name */ __( 'Opens %s in a new tab', 'ghca-acd' ), $host );
		return '<div class="gh-bp-src"><span class="gh-bul-srcico">' . self::icon( 'file' ) . '</span><span class="gh-bul-srcmeta"><strong>' . esc_html__( 'Official source', 'ghca-acd' ) . '</strong><span>' . esc_html( $meta ) . '</span></span>' . $link . '</div>';
	}

	/** @param array<string,mixed> $model */
	public static function drawer( array $model ): string {
		$library = esc_url( (string) ( $model['library_url'] ?? '' ) );
		$html  = '<div class="gh-odp-layer" id="gh-odp-drawer" hidden>';
		$html .= '<div class="gh-scrim" data-gh-drawer-close aria-hidden="true"></div>';
		$html .= '<aside class="gh-drawer gh-bul" role="dialog" aria-modal="true" aria-labelledby="gh-odp-drawer-title" tabindex="-1">';
		$html .= '<div class="gh-bul-hd"><span class="gh-bul-chip">' . self::icon( 'megaphone' ) . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</span>';
		$html .= '<span class="gh-bul-pos" data-gh-drawer-pos></span>';
		$html .= '<button type="button" class="gh-bul-x" data-gh-drawer-close data-gh-drawer-x aria-label="' . esc_attr__( 'Close bulletin', 'ghca-acd' ) . '">' . self::icon( 'x' ) . '</button></div>';
		$html .= '<div class="gh-bul-bd"><span class="gh-bul-date">' . self::icon( 'calendar' ) . '<time data-gh-drawer-meta></time></span>';
		$html .= '<h2 class="gh-bul-h" id="gh-odp-drawer-title" data-gh-drawer-title></h2>';
		$html .= '<div class="gh-bul-sum" data-gh-drawer-body></div>';
		$html .= '<div class="gh-bul-src" data-gh-drawer-source-wrap hidden><span class="gh-bul-eyebrow">' . esc_html__( 'Source', 'ghca-acd' ) . '</span>';
		$html .= '<div class="gh-bul-srcrow"><span class="gh-bul-srcico">' . self::icon( 'file' ) . '</span><span class="gh-bul-srcmeta"><strong>' . esc_html__( 'Official source', 'ghca-acd' ) . '</strong><span data-gh-drawer-source-host></span></span>';
		$html .= '<a class="gh-btn gh-btn--secondary gh-btn--sm" data-gh-drawer-source href="#" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read source', 'ghca-acd' ) . self::icon( 'external', 'sm' ) . '<span class="gh-sr"> ' . esc_html__( '(opens an external page in a new tab)', 'ghca-acd' ) . '</span></a></div></div>';
		$html .= '<p class="gh-bul-note">' . self::icon( 'info' ) . esc_html( GHCA_ODP_Contract::DISCLAIMER ) . '</p></div>';
		$html .= '<div class="gh-bul-ft"><a class="gh-btn gh-btn--secondary gh-btn--sm gh-bul-open" data-gh-drawer-full href="' . $library . '">' . self::icon( 'article', 'sm' ) . esc_html__( 'Open full page', 'ghca-acd' ) . '</a>';
		$html .= '<a class="gh-bul-all" href="' . $library . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . self::icon( 'arrow-right' ) . '</a>';
		$html .= '<div class="gh-bul-step"><button type="button" data-gh-drawer-step="-1" aria-label="' . esc_attr__( 'Newer bulletin', 'ghca-acd' ) . '">' . self::icon( 'chev-left' ) . '</button>';
		$html .= '<button type="button" data-gh-drawer-step="1" aria-label="' . esc_attr__( 'Older bulletin', 'ghca-acd' ) . '">' . self::icon( 'chev-right' ) . '</button></div></div>';
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
		$html .= '</head><body class="gh-ui-body">';
		$style = (string) ( $model['brand_style'] ?? '' );
		$html .= '<div class="ghca-ui"><div class="gh-app gh-ui--' . esc_attr( (string) $model['context'] ) . '" data-theme="' . esc_attr( (string) $model['theme'] ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		$html .= '<a class="gh-skip" href="#gh-odp-main">' . esc_html__( 'Skip to content', 'ghca-acd' ) . '</a>';
		$html .= self::appbar( isset( $model['chrome'] ) && is_array( $model['chrome'] ) ? $model['chrome'] : array() );
		$html .= '<main id="gh-odp-main" class="gh-container gh-odp-main">' . $body . '</main>';
		$html .= self::drawer( $model );
		$html .= '</div></div><script src="' . esc_url( (string) $model['js'] ) . '" defer></script></body></html>';
		return $html;
	}

	/**
	 * The bulletin library or page as it sits inside the active WordPress theme: no document, no app bar
	 * and no skip link of its own (the theme supplies the header, footer and site navigation). The
	 * full-width wrapper carries the dashboard's page surface (#F3EFEA) and the agency brand style.
	 *
	 * @param array<string,mixed> $model
	 */
	public static function themed( array $model ): string {
		$body  = (string) ( $model['body_html'] ?? '' );
		$style = (string) ( $model['brand_style'] ?? '' );
		$html  = '<div class="ghca-ui ghca-ui--bleed gh-odp-host">';
		$html .= '<div class="gh-app gh-ui--' . esc_attr( (string) $model['context'] ) . '" data-theme="' . esc_attr( (string) $model['theme'] ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		$html .= '<main id="gh-odp-main" class="gh-container gh-odp-main">' . $body . '</main>';
		$html .= self::drawer( $model );
		return $html . '</div></div>';
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

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,string> $dates short (Nov 2), md, year, long labels
	 * @return array<string,mixed>
	 */
	public static function public_item( array $item, string $url, string $date_label, array $dates = array() ): array {
		$source = GHCA_ODP_Contract::safe_http_url( $item['source_url'] ?? null );
		return array(
			'id'             => (string) $item['id'],
			'headline'       => (string) $item['headline'],
			'preview'        => (string) $item['preview'],
			'body'           => (string) $item['body'],
			'paragraphs'     => GHCA_ODP_Contract::paragraphs( (string) $item['body'] ),
			'published_at'   => (string) $item['published_at'],
			'date_label'     => $date_label,
			'date_short'     => (string) ( $dates['short'] ?? $date_label ),
			'date_md'        => (string) ( $dates['short'] ?? $date_label ),
			'date_year'      => (string) ( $dates['year'] ?? '' ),
			'date_long'      => (string) ( $dates['long'] ?? $date_label ),
			'audience'       => (string) $item['audience'],
			'audience_label' => GHCA_ODP_Contract::audience_label( (string) $item['audience'] ),
			'source_url'     => is_string( $source ) ? $source : null,
			'source_host'    => is_string( $source ) ? (string) parse_url( $source, PHP_URL_HOST ) : '',
			'url'            => $url,
		);
	}

	private static function chip(): string {
		return '<span class="gh-bul-chip">' . self::icon( 'megaphone' ) . esc_html__( 'ODP Bulletin', 'ghca-acd' ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $model
	 * @param array<int,array{label:string,url:string}> $trail
	 */
	private static function crumbs( array $model, array $trail ): string {
		$home = (string) ( $model['home_url'] ?? '' );
		$html = '<nav aria-label="' . esc_attr__( 'Breadcrumb', 'ghca-acd' ) . '"><ol class="gh-crumbs">';
		if ( '' !== $home ) {
			$html .= '<li><a href="' . esc_url( $home ) . '">' . esc_html( (string) ( $model['home_label'] ?? '' ) ) . '</a></li>';
		}
		foreach ( $trail as $step ) {
			$html .= '' !== $step['url']
				? '<li><a href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['label'] ) . '</a></li>'
				: '<li><span aria-current="page">' . esc_html( $step['label'] ) . '</span></li>';
		}
		return $html . '</ol></nav>';
	}

	private static function empty_block( string $icon, string $title, string $text ): string {
		return '<div class="gh-odp-empty" role="status"><span class="gh-bul-msgico">' . self::icon( $icon ) . '</span><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p></div>';
	}

	/** @param array<string,mixed> $model */
	private static function pager( array $model ): string {
		$pages = (int) ( $model['pages'] ?? 1 );
		$page  = (int) ( $model['page'] ?? 1 );
		$html  = '<nav class="gh-pager" aria-label="' . esc_attr__( 'Bulletin pages', 'ghca-acd' ) . '"><span>' . esc_html( sprintf(
			/* translators: 1: first visible row, 2: last visible row, 3: total matches */
			__( 'Showing %1$d–%2$d of %3$d', 'ghca-acd' ),
			(int) $model['from'],
			(int) $model['to'],
			(int) $model['total']
		) ) . '</span>';
		if ( $pages < 2 ) {
			return $html . '</nav>';
		}
		$urls  = isset( $model['page_urls'] ) && is_array( $model['page_urls'] ) ? $model['page_urls'] : array();
		$html .= '<div class="gh-pager-btns">';
		$html .= ! empty( $model['prev_url'] ) ? '<a href="' . esc_url( (string) $model['prev_url'] ) . '" aria-label="' . esc_attr__( 'Previous page', 'ghca-acd' ) . '">' . self::icon( 'chev-left' ) . '</a>' : '';
		for ( $n = 1; $n <= $pages; $n++ ) {
			$html .= $n === $page
				? '<a aria-current="page">' . esc_html( (string) $n ) . '</a>'
				: '<a href="' . esc_url( (string) ( $urls[ $n ] ?? '' ) ) . '">' . esc_html( (string) $n ) . '</a>';
		}
		$html .= ! empty( $model['next_url'] ) ? '<a href="' . esc_url( (string) $model['next_url'] ) . '" aria-label="' . esc_attr__( 'Next page', 'ghca-acd' ) . '">' . self::icon( 'chev-right' ) . '</a>' : '';
		return $html . '</div></nav>';
	}

	/** @param array<string,mixed> $model */
	private static function library_aside( array $model ): string {
		$html  = '<aside class="gh-odp-aside" aria-label="' . esc_attr__( 'About ODP bulletins', 'ghca-acd' ) . '">';
		$html .= '<div class="gh-odp-about">' . self::chip() . '<h2>' . esc_html__( 'What these are', 'ghca-acd' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Short, plain-language summaries of guidance from Pennsylvania’s Office of Developmental Programs, written by Gridhouse for your team.', 'ghca-acd' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Your agency’s own news lives in', 'ghca-acd' ) . ' <strong>' . esc_html__( 'Announcements', 'ghca-acd' ) . '</strong> ' . esc_html__( 'on your dashboard.', 'ghca-acd' ) . '</p></div>';
		$html .= '<div class="gh-odp-about"><h2>' . esc_html__( 'Only what applies to you', 'ghca-acd' ) . '</h2>';
		$html .= '<p>' . esc_html( self::context_lead( (string) ( $model['context'] ?? 'employee' ) ) ) . '</p></div>';
		return $html . '</aside>';
	}

	/** @param array<string,mixed> $model */
	private static function more_aside( array $model ): string {
		$more = isset( $model['more'] ) && is_array( $model['more'] ) ? $model['more'] : array();
		$html = '<aside class="gh-odp-aside" aria-label="' . esc_attr__( 'More bulletins', 'ghca-acd' ) . '"><div class="gh-odp-about"><h2>' . esc_html__( 'More bulletins', 'ghca-acd' ) . '</h2>';
		if ( $more ) {
			$html .= '<ul class="gh-odp-mini">';
			foreach ( $more as $item ) {
				$html .= '<li><a href="' . esc_url( (string) $item['url'] ) . '"><time datetime="' . esc_attr( (string) $item['published_at'] ) . '">' . esc_html( (string) $item['date_label'] ) . '</time>' . esc_html( (string) $item['headline'] ) . '</a></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p>' . esc_html__( 'No other bulletins in this view.', 'ghca-acd' ) . '</p>';
		}
		$html .= '<a class="gh-bul-all" href="' . esc_url( (string) ( $model['library_url'] ?? '' ) ) . '">' . esc_html__( 'All bulletins', 'ghca-acd' ) . self::icon( 'arrow-right' ) . '</a>';
		return $html . '</div></aside>';
	}

	/** @param array<string,mixed> $model */
	private static function back_nav( array $model ): string {
		return '<div class="gh-bp-nav"><a class="gh-btn gh-btn--secondary" href="' . esc_url( (string) ( $model['library_url'] ?? '' ) ) . '">' . self::icon( 'arrow-left' ) . esc_html__( 'Back to all bulletins', 'ghca-acd' ) . '</a></div>';
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
		$title = 'unavailable' === $state ? __( 'Bulletins unavailable', 'ghca-acd' ) : __( 'Showing a saved copy', 'ghca-acd' );
		return '<div class="gh-odp-stale gh-odp-status--' . esc_attr( $state ) . '" role="status">' . self::icon( 'clock' ) . '<div><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( self::state_message( $state ) ) . '</span></div></div>';
	}
}
