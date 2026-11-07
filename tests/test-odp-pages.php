<?php
/**
 * End-to-end (synthetic) checks of the ODP bulletin pages through the real GHCA_ODP_Bulletins::render_screen()
 * path, one scenario per process via tests/odp-page-runner.php: signed in and out, agency and employee
 * audiences, library and detail, unknown and out-of-audience bulletins, the blocked feed (unavailable state),
 * classic and block themes, and main-query flags. Stand-in WordPress and theme; no network.
 * Run: php tests/test-odp-pages.php
 */
$failed = 0;
$passed = 0;
function check( $ok, $label ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $label\n" );
		$GLOBALS['failed']++;
		return;
	}
	$GLOBALS['passed']++;
}
/** @return array{html:string,rec:array<string,mixed>} */
function run( array $scenario ): array {
	$proc = proc_open( array( PHP_BINARY, __DIR__ . '/odp-page-runner.php', json_encode( $scenario ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$html = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	proc_close( $proc );
	$rec = array();
	if ( false !== ( $at = strpos( $err, '@@RECORD@@' ) ) ) {
		$rec = json_decode( trim( substr( $err, $at + 10 ) ), true ) ?: array();
	} else {
		fwrite( STDERR, $err );
	}
	return array( 'html' => $html, 'rec' => $rec );
}
function once( string $html, string $needle ): bool { return 1 === substr_count( $html, $needle ); }
function body_classes( string $html ): array {
	return preg_match( '/<body class="([^"]*)"/', $html, $m ) ? explode( ' ', $m[1] ) : array();
}

// ---- Library, agency lead, signed in, fresh cached feed.
$r = run( array( 'uri' => '/odp-bulletins/', 'can_view' => true, 'feed' => 'fresh' ) );
$h = $r['html'];
check( 200 === $r['rec']['status'], 'library: 200' );
check( 0 === $r['rec']['remote'], 'library: fresh cache, no network request' );
check( once( $h, 'id="masthead"' ) && once( $h, 'id="colophon"' ) && once( $h, '<!DOCTYPE' ) && once( $h, '<body' ), 'library: exactly one theme header, footer, document and body' );
check( false === strpos( $h, 'gh-appbar' ) && false === strpos( $h, 'gh-logo' ) && false === strpos( $h, 'class="gh-nav' ) && false === strpos( $h, 'gh-skip' ) && false === strpos( $h, 'gh-ui-body' ), 'library: no plugin app bar, logo, nav, skip link or standalone body' );
check( strpos( $h, 'id="masthead"' ) < strpos( $h, 'gh-odp-host' ) && strpos( $h, 'gh-odp-host' ) < strpos( $h, 'id="colophon"' ), 'library: content between the theme header and footer' );
check( false !== strpos( $h, 'Synthetic bulletin for everyone' ) && false !== strpos( $h, 'Synthetic bulletin for agency leads' ) && false === strpos( $h, 'Synthetic bulletin for employees' ), 'library: agency audience (both + agency lead, not employee-only)' );
check( false !== strpos( $h, 'href="https://staging.test/odp-bulletins/synthetic-both/"' ), 'library: card links to the bulletin page' );
check( false !== strpos( $h, 'gh-ui--admin' ) && false !== strpos( $h, 'data-theme="akansoh"' ) && false !== strpos( $h, '--gh-agency:#2f5d3a' ), 'library: agency context, theme and brand colour' );
check( false !== strpos( $h, 'id="gh-odp-data"' ) && false !== strpos( $h, 'id="gh-odp-drawer"' ), 'library: drawer payload and drawer' );
$bc = body_classes( $h );
check( in_array( 'gh-odp-page', $bc, true ) && in_array( 'ghca-ui-page', $bc, true ) && ! array_intersect( array( 'home', 'blog', 'error404' ), $bc ), 'library: body classes gh-odp-page and ghca-ui-page; no home, blog or error404' );
check( false === $r['rec']['header_flags']['is_home'] && false === $r['rec']['header_flags']['is_404'], 'library: the theme header sees is_home and is_404 false (the main query read it as the blog home)' );
check( isset( $r['rec']['styles']['ghca-redesign'], $r['rec']['scripts']['ghca-bulletins'] ), 'library: redesign stylesheet and bulletin script enqueued through WordPress' );
check( in_array( 'wp_robots', $r['rec']['filters'], true ) && in_array( 'pre_get_document_title', $r['rec']['filters'], true ), 'library: noindex and title through WordPress filters' );

// ---- Library, employee.
$r = run( array( 'uri' => '/odp-bulletins/', 'can_view' => false ) );
check( false !== strpos( $r['html'], 'Synthetic bulletin for employees' ) && false !== strpos( $r['html'], 'Synthetic bulletin for everyone' ) && false === strpos( $r['html'], 'Synthetic bulletin for agency leads' ) && false !== strpos( $r['html'], 'gh-ui--employee' ), 'employee library: employee + both, not agency-only' );

// ---- Search.
$r = run( array( 'uri' => '/odp-bulletins/?ghca_odp_q=agency', 'get' => array( 'ghca_odp_q' => 'agency' ) ) );
check( false !== strpos( $r['html'], 'Synthetic bulletin for agency leads' ) && false === strpos( $r['html'], 'Synthetic bulletin for everyone</a>' ), 'library search narrows the list' );

// ---- Detail.
$r = run( array( 'uri' => '/odp-bulletins/synthetic-both/' ) );
$h = $r['html'];
check( 200 === $r['rec']['status'] && false !== strpos( $h, 'Synthetic bulletin for everyone' ) && false !== strpos( $h, 'First paragraph of Synthetic bulletin for everyone' ), 'detail: 200 with headline and body' );
check( once( $h, 'id="masthead"' ) && once( $h, 'id="colophon"' ) && false === strpos( $h, 'gh-appbar' ), 'detail: one theme header and footer, no plugin header' );
check( false !== strpos( $h, 'href="https://staging.test/odp-bulletins/"' ), 'detail: link back to the library' );
check( false !== strpos( $h, 'https://example.test/source' ), 'detail: official source link' );
$r = run( array( 'uri' => '/odp-bulletins/synthetic-agency/', 'can_view' => false ) );
// Phase-1 behaviour, unchanged: the page says the bulletin is labeled for another audience and withholds its text.
check( false === strpos( $r['html'], 'First paragraph of Synthetic bulletin for agency leads' ) && false !== strpos( $r['html'], 'is not included in this view' ) && once( $r['html'], 'id="masthead"' ), 'detail: an agency-only bulletin\'s text is withheld from an employee, with the audience notice, inside the theme' );
$r = run( array( 'uri' => '/odp-bulletins/no-such-bulletin/' ) );
check( 404 === $r['rec']['status'] && once( $r['html'], 'id="masthead"' ) && once( $r['html'], 'id="colophon"' ), 'detail: unknown bulletin is a 404 inside the theme' );

// ---- Signed out: sent to log in before anything is read or printed.
foreach ( array( '/odp-bulletins/', '/odp-bulletins/synthetic-both/' ) as $uri ) {
	$r = run( array( 'uri' => $uri, 'logged_in' => false ) );
	check( 0 === strpos( (string) $r['rec']['redirect'], 'wp-login.php?redirect_to=' ) && '' === $r['html'] && 0 === $r['rec']['remote'] && null === $r['rec']['status'], "signed out $uri: login redirect, no page, no feed read" );
}

// ---- Feed blocked by the staging outbound guard (no cache): the unavailable state, inside the theme.
$r = run( array( 'uri' => '/odp-bulletins/', 'feed' => 'none' ) );
check( 503 === $r['rec']['status'] && 1 === $r['rec']['remote'], 'blocked feed: one refused request, 503' );
check( once( $r['html'], 'id="masthead"' ) && once( $r['html'], 'id="colophon"' ) && false === strpos( $r['html'], 'Synthetic bulletin' ), 'blocked feed: unavailable state inside the theme, no bulletins' );
check( false !== stripos( strip_tags( $r['html'] ), 'unavailable' ) || false !== stripos( strip_tags( $r['html'] ), 'can’t' ) || false !== stripos( strip_tags( $r['html'] ), 'could not' ), 'blocked feed: says so in words' );
$r = run( array( 'uri' => '/odp-bulletins/synthetic-both/', 'feed' => 'none' ) );
check( 503 === $r['rec']['status'] && once( $r['html'], 'id="masthead"' ), 'blocked feed: detail is 503 inside the theme' );

// ---- Block theme.
$r = run( array( 'uri' => '/odp-bulletins/', 'block_theme' => true ) );
$h = $r['html'];
check( 0 === strpos( $h, '<!DOCTYPE html>' ) && once( $h, '<!DOCTYPE' ) && once( $h, 'wp-block-template-part site-header' ) && once( $h, 'wp-block-template-part site-footer' ) && once( $h, '<!--wp_head-->' ) && once( $h, '<!--wp_footer-->' ), 'block theme: one document with the theme header and footer parts' );

// ---- Rewrite rules not flushed: WordPress marked the request a 404 before the route matched.
$r = run( array( 'uri' => '/odp-bulletins/', 'query' => '404' ) );
check( 200 === $r['rec']['status'] && false === $r['rec']['header_flags']['is_404'] && ! in_array( 'error404', body_classes( $r['html'] ), true ), 'unflushed rules: still 200, and the theme does not see a 404' );

// ---- Not a bulletin route: untouched.
$r = run( array( 'uri' => '/some-page/' ) );
check( '@@NOT-A-BULLETIN-ROUTE@@' === $r['html'] && null === $r['rec']['status'] && array() === $r['rec']['styles'] && 0 === $r['rec']['remote'], 'other URLs: nothing printed, enqueued or fetched' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed, passed $passed\n" );
	exit( 1 );
}
echo "PASS: $passed ODP page scenario checks\n";
