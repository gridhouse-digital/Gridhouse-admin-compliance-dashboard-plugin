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
	const CACHE_OPTION   = 'ghca_odp_feed_cache';
	const REWRITE_OPTION = 'ghca_odp_rewrite_version';
	const REST_NAMESPACE = 'ghca-odp/v1';

	/** @var array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array}|null */
	private static $read = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render_screen' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	public static function register_routes(): void {
		if ( ! self::route_owner() ) {
			return;
		}
		foreach ( GHCA_ODP_Routes::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
		if ( '1' !== (string) get_option( self::REWRITE_OPTION, '' ) ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, '1', false );
		}
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
		$context = (string) $request->get_param( 'context' );
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
		$lookup = self::lookup( $id );
		$status = 200;
		if ( 'unknown' === $lookup['result'] || 'scheduled' === $lookup['result'] ) {
			$status = 404;
		} elseif ( 'expired' === $lookup['result'] ) {
			$status = 410;
		} elseif ( 'unavailable' === $lookup['result'] ) {
			$status = 503;
		}
		$data = array(
			'api_version' => GHCA_ODP_Contract::API_VERSION,
			'result'      => $lookup['result'],
			'bulletin'    => 'current' === $lookup['result'] ? self::rest_bulletin( $lookup['bulletin'] ) : null,
		);
		return self::private_response( $data, $status );
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
		$read = self::read_feed();
		return GHCA_ODP_Feed::present( $read, time(), $context );
	}

	/**
	 * @return array{result:string,bulletin:?array<string,mixed>}
	 */
	public static function lookup( string $id ): array {
		$read = self::read_feed();
		if ( in_array( $read['state'], array( 'unavailable' ), true ) || ! is_array( $read['feed'] ) ) {
			return array( 'result' => 'unavailable', 'bulletin' => null );
		}
		$result = GHCA_ODP_Contract::classify( $read['feed'], $id, time() );
		$item   = 'current' === $result ? GHCA_ODP_Contract::find( $read['feed'], $id ) : null;
		return array( 'result' => $result, 'bulletin' => $item );
	}

	/** @return array{state:string,stale:bool,error:?string,fetched_at:?int,feed:?array,cache_write:?array} */
	public static function read_feed(): array {
		if ( null !== self::$read ) {
			return self::$read;
		}
		$cache = get_option( self::CACHE_OPTION, null );
		$cache = is_array( $cache ) ? $cache : null;
		$url   = apply_filters( 'ghca_odp_feed_url', GHCA_ODP_Contract::FEED_URL );
		if ( ! is_string( $url ) || ! GHCA_ODP_Contract::is_allowlisted( $url ) ) {
			$url = GHCA_ODP_Contract::FEED_URL;
		}
		$read = GHCA_ODP_Feed::read( time(), $cache, array( __CLASS__, 'transport' ), $url, GHCA_ODP_Feed::TTL );
		if ( is_array( $read['cache_write'] ) ) {
			update_option( self::CACHE_OPTION, $read['cache_write'], false );
		}
		self::$read = $read;
		return $read;
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
		$screen = get_query_var( 'ghca_odp_screen' );
		if ( 'library' !== $screen && 'single' !== $screen ) {
			return;
		}
		if ( ! self::route_owner() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		nocache_headers();
		$context = GHCA_ODP_Access::context_for( class_exists( 'GHCA_ACD_Roles' ) && GHCA_ACD_Roles::user_can_view() );
		if ( 'single' === $screen ) {
			self::send_document( self::single_model( (string) get_query_var( 'ghca_odp_id' ), $context ) );
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
		$total = (int) $page_items['total'];
		$from  = $total > 0 ? ( ( (int) $page_items['page'] - 1 ) * 9 ) + 1 : 0;
		$to    = $total > 0 ? $from + count( $cards ) - 1 : 0;
		$body  = GHCA_ODP_UI::library( array(
			'state'    => $view['state'],
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
			),
		) );
		return self::shell( $context, __( 'ODP Bulletins', 'ghca-acd' ), $body, 200 );
	}

	/** @return array<string,mixed> */
	public static function single_model( string $id, string $context ): array {
		$lookup = self::lookup( $id );
		$status = 200;
		if ( 'unknown' === $lookup['result'] || 'scheduled' === $lookup['result'] ) {
			$status = 404;
		} elseif ( 'expired' === $lookup['result'] ) {
			$status = 410;
		} elseif ( 'unavailable' === $lookup['result'] ) {
			$status = 503;
		}
		$note = '';
		$item = null;
		if ( 'current' === $lookup['result'] && is_array( $lookup['bulletin'] ) ) {
			$item = self::card( $lookup['bulletin'] );
			if ( ! GHCA_ODP_Contract::for_context( $lookup['bulletin'], $context ) ) {
				$note = sprintf(
					/* translators: 1: audience label, 2: admin or employee */
					__( 'This summary is labeled for %1$s. It is not included in the %2$s list. The label is not a permission.', 'ghca-acd' ),
					$item['audience_label'],
					'admin' === $context ? __( 'agency', 'ghca-acd' ) : __( 'employee', 'ghca-acd' )
				);
			}
		}
		$body = GHCA_ODP_UI::single( array(
			'result'        => $lookup['result'],
			'library_url'   => self::library_url(),
			'kicker'        => __( 'ODP Bulletin', 'ghca-acd' ),
			'item'          => $item,
			'audience_note' => $note,
		) );
		$title = $item ? (string) $item['headline'] : __( 'ODP Bulletin', 'ghca-acd' );
		return self::shell( $context, $title, $body, $status );
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
		return GHCA_ODP_UI::public_item( $item, $url, self::date_label( (string) $item['published_at'] ) );
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
	private static function shell( string $context, string $title, string $body, int $status ): array {
		$admin = 'admin' === $context;
		$home  = $admin && class_exists( 'GHCA_ACD_Data_Provider' )
			? GHCA_ACD_Data_Provider::get_admin_dashboard_url()
			: ( class_exists( 'GHCA_ACD_Data_Provider' ) ? GHCA_ACD_Data_Provider::get_page_url( 'employee-dashboard', '/employee-dashboard/' ) : home_url( '/' ) );
		return array(
			'http_status'   => $status,
			'title'         => $title,
			'lang'          => str_replace( '_', '-', (string) get_locale() ),
			'css'           => self::asset_url( 'assets/gh-redesign.css' ),
			'js'            => self::asset_url( 'assets/gh-bulletins.js' ),
			'context'       => $context,
			'theme'         => GHCA_ACD_Redesign::theme_slug(),
			'home_url'      => $home,
			'home_label'    => $admin ? __( 'Compliance dashboard', 'ghca-acd' ) : __( 'Employee dashboard', 'ghca-acd' ),
			'context_label' => $admin ? __( 'Agency view', 'ghca-acd' ) : __( 'Employee view', 'ghca-acd' ),
			'library_url'   => self::library_url(),
			'body_html'     => $body,
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
			return __( 'The agency bulletin list requires the compliance dashboard capability.', 'ghca-acd' );
		}
		return __( 'Bulletin context must be admin or employee.', 'ghca-acd' );
	}

	public static function deactivate(): void {
		delete_option( self::REWRITE_OPTION );
		flush_rewrite_rules( false );
	}
}
