<?php
/**
 * WordPress adapter for the shared bulletin service.
 *
 * Employee plugins in the same site should call GHCA_ODP_Bulletins::snapshot()
 * or GET /wp-json/ghca-odp/v1/bulletins?context=employee. They should not
 * register /odp-bulletins routes. Filter ghca_odp_route_owner to opt out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GHCA_ODP_Bulletins {
	const CACHE_OPTION    = 'ghca_odp_feed_cache';
	const REWRITE_OPTION  = 'ghca_odp_rewrite_version';
	const REWRITE_VERSION = '2';
	const REST_NAMESPACE  = 'ghca-odp/v1';
	const REFRESH_HOOK    = 'ghca_odp_refresh_feed';

	/** @var array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}|null */
	private static $read = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render_screen' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_feed' ) );
	}

	public static function register_routes(): void {
		if ( ! self::route_owner() ) {
			return;
		}
		foreach ( GHCA_ODP_Routes::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
		if ( self::REWRITE_VERSION !== (string) get_option( self::REWRITE_OPTION, '' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_rewrites_once' ), 99 );
		}
	}

	/**
	 * Flush after init so rules registered later in the same request are included.
	 * The version option makes this a one-time write.
	 */
	public static function flush_rewrites_once(): void {
		if ( self::REWRITE_VERSION === (string) get_option( self::REWRITE_OPTION, '' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	/** @param array<int,string> $vars */
	public static function query_vars( array $vars ): array {
		$vars[] = 'ghca_odp_screen';
		$vars[] = 'ghca_odp_id';
		return $vars;
	}

	public static function register_rest(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/bulletins',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( __CLASS__, 'rest_list_permission' ),
				'args'                => array(
					'context' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/bulletins/(?P<id>[a-z0-9]+(?:-[a-z0-9]+)*)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_item' ),
				'permission_callback' => array( __CLASS__, 'rest_item_permission' ),
			)
		);
	}

	/** @return bool|WP_Error */
	public static function rest_list_permission( WP_REST_Request $request ) {
		$decision = GHCA_ODP_Access::authorize_list(
			is_user_logged_in(),
			class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_view(),
			(string) $request->get_param( 'context' )
		);
		return self::permission_result( $decision );
	}

	/** @return bool|WP_Error */
	public static function rest_item_permission() {
		return self::permission_result( GHCA_ODP_Access::authorize_item( is_user_logged_in() ) );
	}

	/** @param array{ok:bool,status:int,code:string} $decision @return bool|WP_Error */
	private static function permission_result( array $decision ) {
		if ( ! empty( $decision['ok'] ) ) {
			return true;
		}
		return new WP_Error( 'ghca_odp_' . $decision['code'], self::permission_message( $decision['code'] ), array( 'status' => (int) $decision['status'] ) );
	}

	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		$context = self::trusted_context();
		if ( (string) $request->get_param( 'context' ) !== $context ) {
			return self::private_response( array( 'code' => 'forbidden' ), 403 );
		}
		$view    = self::snapshot( $context );
		$data    = array(
			'api_version'     => GHCA_ODP_Contract::API_VERSION,
			'context'         => $context,
			'audience_filter' => $view['audience_filter'],
			'state'           => $view['state'],
			'stale'           => $view['stale'],
			'updated_at'      => $view['updated_at'],
			'fetched_at'      => self::iso_from_unix( $view['fetched_at'] ),
			'bulletins'       => array_map( array( __CLASS__, 'rest_bulletin' ), $view['bulletins'] ),
		);
		return self::private_response( $data, 200 );
	}

	public static function rest_item( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'id' );
		$lookup = self::lookup( $id, self::trusted_context() );
		$data   = array(
			'api_version' => GHCA_ODP_Contract::API_VERSION,
			'context'     => self::trusted_context(),
			'result'      => $lookup['result'],
			'audience'    => $lookup['audience'],
			'bulletin'    => 'current' === $lookup['result'] && is_array( $lookup['bulletin'] ) ? self::rest_bulletin( $lookup['bulletin'] ) : null,
		);
		return self::private_response( $data, self::http_status( $lookup['result'] ) );
	}

	public static function http_status( string $result ): int {
		if ( 'unknown' === $result || 'scheduled' === $result ) {
			return 404;
		}
		if ( 'expired' === $result ) {
			return 410;
		}
		if ( 'unavailable' === $result || 'unconfirmed' === $result ) {
			return 503;
		}
		return 200;
	}

	/** @param array<string,mixed> $data */
	private static function private_response( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * Stable in-process API. $context is admin or employee.
	 *
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,updated_at:?string,bulletins:array<int,array<string,mixed>>,audience_filter:array<int,string>}
	 */
	public static function snapshot( string $context ): array {
		$read = self::read_feed( true );
		return GHCA_ODP_Feed::present( $read, time(), $context );
	}

	/**
	 * @return array{result:string,bulletin:?array,audience:?string}
	 */
	public static function lookup( string $id, ?string $context = null ): array {
		$read = self::read_feed( true );
		if ( null === $context ) {
			$context = self::trusted_context();
		}
		if ( 'unavailable' === $read['state'] || ! is_array( $read['feed'] ) ) {
			return array( 'result' => 'unavailable', 'bulletin' => null, 'audience' => null );
		}
		$stale = ! empty( $read['stale'] ) || in_array( $read['state'], array( 'cached', 'offline' ), true );
		return GHCA_ODP_Contract::resolve_item( $read['feed'], $id, time(), $context, $stale );
	}

	/**
	 * Page renders defer a stale refresh so HTML is not blocked on the worker.
	 * A cold cache still waits, up to the transport timeout, because there is nothing else to show.
	 *
	 * @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}
	 */
	public static function read_feed( bool $defer_stale = false ): array {
		if ( null !== self::$read ) {
			return self::$read;
		}
		$cache   = get_option( self::CACHE_OPTION, null );
		$cache   = is_array( $cache ) ? $cache : null;
		$refresh = ! ( $defer_stale && self::cache_is_usable_stale( $cache ) );
		$url     = apply_filters( 'ghca_odp_feed_url', GHCA_ODP_Contract::FEED_URL );
		if ( ! is_string( $url ) || ! GHCA_ODP_Contract::is_allowlisted( $url ) ) {
			$url = GHCA_ODP_Contract::FEED_URL;
		}
		$read = GHCA_ODP_Feed::read( time(), $cache, array( __CLASS__, 'transport' ), $url, GHCA_ODP_Feed::TTL, $refresh );
		if ( is_array( $read['cache_write'] ) ) {
			update_option( self::CACHE_OPTION, $read['cache_write'], false );
		}
		if ( ! $refresh ) {
			self::schedule_refresh();
		}
		self::$read = $read;
		return $read;
	}

	public static function refresh_feed(): void {
		self::$read = null;
		self::read_feed( false );
	}

	/** @param array<string,mixed>|null $cache */
	private static function cache_is_usable_stale( ?array $cache ): bool {
		if ( ! is_array( $cache ) || ! isset( $cache['fetched_at'], $cache['feed'] ) ) {
			return false;
		}
		$validated = GHCA_ODP_Contract::validate( $cache['feed'] );
		if ( empty( $validated['ok'] ) ) {
			return false;
		}
		$age = time() - (int) $cache['fetched_at'];
		return $age < 0 || $age >= GHCA_ODP_Feed::TTL;
	}

	private static function schedule_refresh(): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || wp_next_scheduled( self::REFRESH_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::REFRESH_HOOK );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	private static function trusted_context(): string {
		return GHCA_ODP_Access::context_for( class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_view() );
	}

	/** @return array{status:int,body:string} */
	public static function transport( string $url, int $timeout ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => GHCA_ODP_Feed::MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'Gridhouse-ODP-Consumer/' . GHCA_ODP_Contract::API_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'network' );
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}

	public static function render_screen(): void {
		$home = function_exists( 'home_url' ) ? (string) parse_url( home_url( '/' ), PHP_URL_PATH ) : '/';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$match = GHCA_ODP_Routes::match( GHCA_ODP_Routes::path_from_request( $uri, $home ) );
		if ( '' === $match['screen'] ) {
			return;
		}
		if ( ! self::route_owner() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		nocache_headers();
		$context = self::trusted_context();
		if ( 'single' === $match['screen'] ) {
			self::send_document( self::single_model( $match['id'], $context ) );
		}
		$query = isset( $_GET['ghca_odp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['ghca_odp_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read filter.
		$page  = isset( $_GET['ghca_odp_page'] ) ? absint( wp_unslash( $_GET['ghca_odp_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read filter.
		self::send_document( self::library_model( $context, $query, $page ) );
	}

	/** @param array<string,mixed> $model */
	private static function send_document( array $model ): void {
		status_header( (int) $model['http_status'] );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex' );
		echo GHCA_ODP_UI::document( $model ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document() escapes.
		exit;
	}

	/** @return array<string,mixed> */
	public static function library_model( string $context, string $query, int $page ): array {
		$view = self::snapshot( $context );
		$page_items = GHCA_ODP_Contract::paginate( $view['bulletins'], $query, $page, 9 );
		$cards = array();
		$payload_items = array();
		foreach ( $page_items['items'] as $item ) {
			$public = self::card( $item );
			$cards[] = $public;
			$payload_items[ $public['id'] ] = $public;
		}
		$order = array_keys( $payload_items );
		$total = (int) $page_items['total'];
		$from  = $total > 0 ? ( ( (int) $page_items['page'] - 1 ) * 9 ) + 1 : 0;
		$to    = $total > 0 ? $from + count( $cards ) - 1 : 0;
		$pages = (int) $page_items['pages'];
		$urls  = array();
		for ( $n = 1; $n <= $pages; $n++ ) {
			$urls[ $n ] = self::library_url( $page_items['query'], $n );
		}
		$body  = GHCA_ODP_UI::library( array(
			'state'    => $view['state'],
			'home_url'   => self::home_url( $context ),
			'home_label' => self::home_label( $context ),
			'page'       => (int) $page_items['page'],
			'pages'      => $pages,
			'page_urls'  => $urls,
			'context'  => $context,
			'kicker'   => 'admin' === $context ? __( 'Agency bulletins', 'ghca-acd' ) : __( 'Employee bulletins', 'ghca-acd' ),
			'action'   => self::library_url(),
			'query'    => $page_items['query'],
			'items'    => $cards,
			'total'    => $total,
			'from'     => $from,
			'to'       => $to,
			'prev_url' => (int) $page_items['page'] > 1 ? self::library_url( $page_items['query'], (int) $page_items['page'] - 1 ) : '',
			'next_url' => (int) $page_items['pages'] > (int) $page_items['page'] ? self::library_url( $page_items['query'], (int) $page_items['page'] + 1 ) : '',
			'payload'  => array(
				'disclaimer' => GHCA_ODP_Contract::DISCLAIMER,
				'bulletins'  => $payload_items,
				'order'      => $order,
			),
		) );
		$status = 'unavailable' === $view['state'] ? 503 : 200;
		return self::shell( $context, __( 'ODP Bulletins', 'ghca-acd' ), $body, $status );
	}

	/** @return array<string,mixed> */
	public static function single_model( string $id, string $context ): array {
		$lookup = self::lookup( $id, $context );
		$item   = 'current' === $lookup['result'] && is_array( $lookup['bulletin'] ) ? self::card( $lookup['bulletin'] ) : null;
		$more   = array();
		$view   = self::snapshot( $context );
		foreach ( $view['bulletins'] as $other ) {
			if ( count( $more ) >= 3 ) {
				break;
			}
			if ( (string) $other['id'] !== $id ) {
				$more[] = self::card( $other );
			}
		}
		$body   = GHCA_ODP_UI::single( array(
			'result'         => $lookup['result'],
			'home_url'       => self::home_url( $context ),
			'home_label'     => self::home_label( $context ),
			'more'           => $more,
			'library_url'    => self::library_url(),
			'kicker'         => __( 'ODP Bulletin', 'ghca-acd' ),
			'item'           => $item,
			'audience_label' => $lookup['audience'] ? GHCA_ODP_Contract::audience_label( (string) $lookup['audience'] ) : '',
			'audience_note'  => '',
		) );
		$title = $item ? (string) $item['headline'] : __( 'ODP Bulletin', 'ghca-acd' );
		return self::shell( $context, $title, $body, self::http_status( $lookup['result'] ) );
	}

	/** @return array<string,mixed> */
	public static function dashboard_fragment( string $context = 'admin' ): array {
		$view  = self::snapshot( $context );
		$cards = array();
		$payload = array();
		foreach ( $view['bulletins'] as $item ) {
			$public = self::card( $item );
			$cards[] = $public;
			$payload[ $public['id'] ] = $public;
		}
		$model = array(
			'state'       => $view['state'],
			'items'       => $cards,
			'library_url' => self::library_url(),
			'payload'     => array(
				'disclaimer' => GHCA_ODP_Contract::DISCLAIMER,
				'bulletins'  => $payload,
				'order'      => array_keys( $payload ),
			),
		);
		return array(
			'ticker'  => GHCA_ODP_UI::ticker( $model ),
			'drawer'  => GHCA_ODP_UI::drawer( $model ),
			'payload' => GHCA_ODP_UI::payload_script( $model ),
		);
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private static function card( array $item ): array {
		$url = self::item_url( (string) $item['id'] );
		return GHCA_ODP_UI::public_item( $item, $url, self::date_label( (string) $item['published_at'] ), self::date_parts( (string) $item['published_at'] ) );
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private static function rest_bulletin( array $item ): array {
		if ( isset( $item['url'] ) ) {
			unset( $item['published_unix'], $item['expires_unix'] );
			return $item;
		}
		return self::card( $item );
	}

	/** @return array<string,mixed> */
	private static function home_url( string $context ): string {
		if ( ! class_exists( 'GHCA_ACD_Data_Provider' ) ) {
			return home_url( '/' );
		}
		return 'admin' === $context
			? GHCA_ACD_Data_Provider::get_admin_dashboard_url()
			: GHCA_ACD_Data_Provider::get_page_url( 'employee-dashboard', '/employee-dashboard/' );
	}

	private static function home_label( string $context ): string {
		return 'admin' === $context ? __( 'Compliance dashboard', 'ghca-acd' ) : __( 'Employee dashboard', 'ghca-acd' );
	}

	/** @return array{short:string,year:string,long:string} */
	public static function date_parts( string $iso ): array {
		$parsed = GHCA_ODP_Contract::parse_iso( $iso );
		if ( null === $parsed ) {
			return array( 'short' => '', 'year' => '', 'long' => '' );
		}
		$fmt = function_exists( 'wp_date' ) ? 'wp_date' : 'gmdate';
		return array(
			'short' => (string) $fmt( 'M j', $parsed['unix'] ),
			'year'  => (string) $fmt( 'Y', $parsed['unix'] ),
			'long'  => (string) $fmt( 'l, F j, Y', $parsed['unix'] ),
		);
	}

	/** @return array<string,mixed> */
	private static function shell( string $context, string $title, string $body, int $status ): array {
		$admin = 'admin' === $context;
		$home  = self::home_url( $context );
		return array(
			'chrome'        => GHCA_ACD_Redesign::chrome( $context, '' ),
			'http_status'   => $status,
			'title'         => $title,
			'lang'          => str_replace( '_', '-', (string) get_locale() ),
			'css'           => self::asset_url( 'assets/gh-redesign.css' ),
			'js'            => self::asset_url( 'assets/gh-bulletins.js' ),
			'context'       => $context,
			'theme'         => GHCA_ACD_Redesign::theme_slug(),
			'home_url'      => $home,
			'home_label'    => self::home_label( $context ),
			'context_label' => $admin ? __( 'Agency view', 'ghca-acd' ) : __( 'Employee view', 'ghca-acd' ),
			'library_url'   => self::library_url(),
			'body_html'     => $body,
			'brand_style'   => class_exists( 'GHCA_ACD_Redesign' ) ? GHCA_ACD_Redesign::custom_brand_style() : '',
			'rest_nonce'    => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			'rest_root'     => function_exists( 'rest_url' ) ? rest_url( self::REST_NAMESPACE . '/' ) : '',
		);
	}

	public static function library_url( string $query = '', int $page = 1 ): string {
		$url = home_url( '/odp-bulletins/' );
		$args = array();
		if ( '' !== $query ) {
			$args['ghca_odp_q'] = $query;
		}
		if ( $page > 1 ) {
			$args['ghca_odp_page'] = (string) $page;
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public static function item_url( string $id ): string {
		if ( ! GHCA_ODP_Contract::valid_id( $id ) ) {
			return self::library_url();
		}
		return home_url( '/odp-bulletins/' . rawurlencode( $id ) . '/' );
	}

	public static function date_label( string $iso ): string {
		$parsed = GHCA_ODP_Contract::parse_iso( $iso );
		if ( null === $parsed ) {
			return '';
		}
		return function_exists( 'wp_date' ) ? wp_date( 'M j, Y', $parsed['unix'] ) : gmdate( 'M j, Y', $parsed['unix'] );
	}

	/** @param mixed $unix */
	private static function iso_from_unix( $unix ): ?string {
		if ( ! is_int( $unix ) && ! ctype_digit( (string) $unix ) ) {
			return null;
		}
		return gmdate( 'c', (int) $unix );
	}

	private static function asset_url( string $relative ): string {
		$plugin_file = dirname( __DIR__, 2 ) . '/gridhouse-admin-compliance-dashboard.php';
		$path        = plugin_dir_path( $plugin_file ) . $relative;
		$ver         = file_exists( $path ) ? (string) filemtime( $path ) : (string) GHCA_ODP_Contract::API_VERSION;
		return plugins_url( $relative, $plugin_file ) . '?ver=' . rawurlencode( $ver );
	}

	private static function route_owner(): bool {
		return GHCA_ODP_Routes::owns_routes( apply_filters( 'ghca_odp_route_owner', 'ghca-acd' ) );
	}

	private static function permission_message( string $code ): string {
		if ( 'auth' === $code ) {
			return __( 'Log in to read ODP bulletins.', 'ghca-acd' );
		}
		if ( 'forbidden' === $code ) {
			return __( 'That bulletin context does not match this account.', 'ghca-acd' );
		}
		return __( 'Bulletin context must be admin or employee.', 'ghca-acd' );
	}

	public static function deactivate(): void {
		delete_option( self::REWRITE_OPTION );
		flush_rewrite_rules( false );
	}
}
