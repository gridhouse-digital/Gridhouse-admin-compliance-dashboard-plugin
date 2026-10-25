<?php
/** Source contract: both asset URLs must change even when release timestamps match. */
$source = file_get_contents( __DIR__ . '/../gridhouse-admin-compliance-dashboard.php' );
preg_match( '/\* Version: ([^\r\n]+)/', $source, $header );
preg_match( "/const VERSION\s*=\s*'([^']+)'/", $source, $constant );
if ( empty( $header[1] ) || $header[1] !== ( $constant[1] ?? '' ) ) {
  throw new RuntimeException( 'Plugin header and asset release versions must match.' );
}
foreach ( array( 'css', 'js' ) as $extension ) {
  $expected = "self::VERSION . '-' . (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/dashboard.$extension' )";
  if ( 1 !== substr_count( $source, $expected ) ) {
    throw new RuntimeException( "Dashboard $extension must include release and timestamp in its cache key." );
  }
}
echo "PASS: header/runtime version match; CSS and JS use release plus timestamp (source contract, not CDN/browser verification).\n";
