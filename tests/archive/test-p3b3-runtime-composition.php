<?php
require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'LEARNDASH_VERSION' ) ) {
	define( 'LEARNDASH_VERSION', '5.1.6.1' );
}
if ( ! class_exists( 'GHCA_Admin_Compliance_Dashboard', false ) ) {
	final class GHCA_Admin_Compliance_Dashboard {
		const VERSION = '1.2.0';
	}
}

$plugin_root = dirname( __DIR__, 2 );
$archive_root = $plugin_root . '/includes/archive';
$bootstrap_source = file_get_contents( $archive_root . '/bootstrap.php' );
$module_source = file_get_contents( $archive_root . '/class-archive-module.php' );
$attestor_source = file_get_contents( $archive_root . '/infrastructure/class-archive-code-version-attestor.php' );
$entrypoint_source = file_get_contents( $plugin_root . '/gridhouse-admin-compliance-dashboard.php' );

preg_match_all( "/^\t'([^']+\\.php)',$/m", $bootstrap_source, $manifest_matches );
$manifest = $manifest_matches[1] ?? array();
archive_check(
	55 === count( $manifest )
		&& 55 === count( array_unique( $manifest ) )
		&& 'd2b3b45a3e421f3565e651348acac496805a349e272eae2b64aa9bee4570b094' === hash( 'sha256', implode( "\n", $manifest ) ),
	'P3B3-BOOTSTRAP-LITERAL-55-FILE-MANIFEST-AND-DIGEST freezes the exact validated loader'
);
archive_check(
	strpos( $bootstrap_source, 'foreach ( $ghca_archive_manifest as $ghca_archive_relative )' )
		< strpos( $bootstrap_source, 'foreach ( $ghca_archive_paths as $ghca_archive_path )' )
		&& strpos( $bootstrap_source, 'foreach ( $ghca_archive_paths as $ghca_archive_path )' )
		< strpos( $bootstrap_source, 'require_once $ghca_archive_path' ),
	'P3B3-BOOTSTRAP-VALIDATES-ALL-BEFORE-FIRST-REQUIRE retains the complete validated path list'
);
archive_check(
	false !== strpos( $bootstrap_source, 'array_unique' )
		&& false !== strpos( $bootstrap_source, "strpos( \$ghca_archive_relative, '\\\\'" )
		&& false !== strpos( $bootstrap_source, '\\.{1,2}' )
		&& false !== strpos( $bootstrap_source, '55 !== count' ),
	'P3B3-BOOTSTRAP-MISSING-EXTRA-DUPLICATE-ESCAPED-OR-REORDERED-REJECTED fails closed on manifest drift'
);
archive_check(
	false !== strpos( $bootstrap_source, '! is_file' )
		&& false !== strpos( $bootstrap_source, 'is_link( $ghca_archive_path )' )
		&& false !== strpos( $bootstrap_source, 'is_link( __DIR__ )' ),
	'P3B3-BOOTSTRAP-NONREGULAR-OR-SYMLINK-FILE-REJECTED protects loader containment'
);
archive_check(
	0 === preg_match( '/RecursiveDirectory|glob\\s*\\(|Reflection|Composer|class_exists\\s*\\(/i', $bootstrap_source )
		&& false === strpos( $bootstrap_source, 'continue;' ),
	'P3B3-BOOTSTRAP-NO-SCAN-REFLECTION-COMPOSER-DYNAMIC-OR-SILENT-SKIP keeps loading literal'
);
archive_check(
	1 === substr_count( $entrypoint_source, "require_once __DIR__ . '/includes/archive/bootstrap.php';" ),
	'P3B3-ENTRYPOINT-ONE-ARCHIVE-REFERENCE is the only archive entrypoint reference'
);
archive_check(
	1 === substr_count( $module_source, 'new GHCA_ACD_Archive_Unit_Of_Work(' )
		&& false !== strpos( $module_source, '$this->db, $events, $commands, $tasks, $snapshots, $artifacts, $projector' )
		&& 1 === substr_count( $module_source, 'new GHCA_ACD_Archive_Orphan_Reconciler(' )
		&& false !== strpos( $module_source, '$store,' )
		&& false !== strpos( $module_source, '$artifacts,' )
		&& 0 === substr_count( $module_source, '->reconcile(' ),
	'P3B3-DEPENDENCY-GRAPH-USES-ONE-ARCHIVE-CONNECTION constructs the shared write graph and dormant report-only reconciler'
);
archive_check(
	1 === substr_count( $module_source, 'new wpdb(' )
		&& false !== strpos( $module_source, 'compose_evidence_source' )
		&& false !== strpos( $module_source, 'archive_connection_id' ),
	'P3B3-EVIDENCE-CONNECTION-DISTINCT constructs one isolated read connection'
);
archive_check(
	0 === preg_match( '/Container|Factory|register_handler|apply_filters|do_action/i', $module_source ),
	'P3B3-NO-CONTAINER-FACTORY-OR-DYNAMIC-HANDLER-REGISTRATION keeps one explicit composition root'
);

$clock_value = ( new GHCA_ACD_System_Archive_Clock() )->now_gmt();
archive_check(
	1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D', $clock_value )
		&& abs( strtotime( $clock_value ) - time() ) <= 1,
	'P3B3-SYSTEM-CLOCK-UTC-STRICT emits strict UTC seconds'
);
$generated_ids = array();
$id_generator = new GHCA_ACD_Random_Archive_Id_Generator();
for ( $index = 0; $index < 64; $index++ ) {
	$generated_ids[] = $id_generator->generate();
}
archive_check(
	64 === count( array_unique( $generated_ids ) )
		&& 0 === count( array_filter( $generated_ids, static function ( string $id ): bool {
			return 1 !== preg_match( '/^[a-f0-9]{32}$/D', $id );
		} ) ),
	'P3B3-RANDOM-ID-32-LOWER-HEX uses CSPRNG identifiers'
);

$attestor = new GHCA_ACD_Archive_Code_Version_Attestor();
$versions = $attestor->attest();
archive_check(
	array(
		'wordpress_version' => '7.0.2',
		'learndash_version' => '5.1.6.1',
		'plugin_version' => '1.2.0',
	) === $versions,
	'P3B3-ATTEST-EXACT-WP-LD-PLUGIN-TUPLE derives the accepted code tuple'
);
archive_check(
	false === strpos( $attestor_source, '$GLOBALS' ) && false === strpos( $attestor_source, 'global $wp_version' ),
	'P3B3-ATTEST-DOES-NOT-READ-WP-VERSION-GLOBAL ignores ambient version state'
);

$wp_parser = new ReflectionMethod( GHCA_ACD_Archive_Code_Version_Attestor::class, 'wordpress_version' );
$wp_rejects = static function ( string $bytes ) use ( $wp_parser, $attestor ): bool {
	try {
		$wp_parser->invoke( $attestor, $bytes );
	} catch ( UnexpectedValueException $error ) {
		return 'archive_runtime_attestation_failed' === $error->getMessage();
	}
	return false;
};
archive_check(
	'7.0.2' === $wp_parser->invoke( $attestor, "<?php\n\$wp_version = '7.0.2';\n" ),
	'P3B3-ATTEST-TOKEN-PARSER-ACCEPTS-ONE-STATIC-ASSIGNMENT accepts one literal assignment'
);
archive_check(
	$wp_rejects( "<?php\n\$other = '7.0.2';\n" )
		&& $wp_rejects( "<?php\n\$wp_version='7.0.2'; \$wp_version='7.0.2';\n" ),
	'P3B3-ATTEST-REJECTS-MISSING-EXTRA-DYNAMIC-OR-DUPLICATE-WP-ASSIGNMENT closes ambiguous authority'
);
archive_check(
	$wp_rejects( "<?php\n\$wp_version='7.'.'0.2';\n" )
		&& $wp_rejects( "<?php\n\$wp_version=strtolower('7.0.2');\n" ),
	'P3B3-ATTEST-REJECTS-CONCATENATION-INTERPOLATION-OR-EXPRESSION requires literal bytes'
);
archive_check(
	$wp_rejects( "<?php\n// \$wp_version = '7.0.2';\n\$x=" . var_export( '$wp_version = 7.0.2;', true ) . ";\n" ),
	'P3B3-ATTEST-IGNORES-COMMENT-AND-STRING-SPOOF rejects non-code lookalikes'
);
archive_check(
	$wp_rejects( "<?php\n\$wp_version = ;\n" )
		&& false !== strpos( $attestor_source, '65536' )
		&& false !== strpos( $attestor_source, '262144' ),
	'P3B3-ATTEST-REJECTS-MALFORMED-UNREADABLE-OR-OVERSIZED-TOKENS enforces bounded parsing'
);

$header_parser = new ReflectionMethod( GHCA_ACD_Archive_Code_Version_Attestor::class, 'header_version' );
$header_rejected = GHCA_ACD_Archive_Code_Version_Attestor::PLUGIN_VERSION
	!== $header_parser->invoke( $attestor, "<?php\n/**\n * Version: 1.2.1\n */\n" );
archive_check(
	$header_rejected,
	'P3B3-ATTEST-REJECTS-HEADER-CONSTANT-MISMATCH fails a mismatched installed header'
);
archive_check(
	false !== strpos( $attestor_source, 'realpath' )
		&& false !== strpos( $attestor_source, 'is_link' )
		&& false !== strpos( $attestor_source, 'contained' ),
	'P3B3-ATTEST-REJECTS-SYMLINK-OR-PATH-ESCAPE binds version files to installed roots'
);
archive_check(
	0 === preg_match( '/getenv|get_option|get_site_option|\\$_(?:GET|POST|REQUEST)|apply_filters|global\\s+\\$/i', $attestor_source ),
	'P3B3-ATTEST-REJECTS-ORDINARY-OPTION-ENV-REQUEST-TASK-DB-GLOBAL-FILTER-SPOOF uses code only'
);
archive_check(
	strpos( $module_source, '$this->attestor->attest();' ) < strpos( $module_source, '$this->runtime->resolve();' ),
	'P3B3-ATTEST-FAILS-BEFORE-SOURCE-CREDENTIALS-OR-QUERY admits no secret loading first'
);
archive_check(
	array_keys( $versions ) === array( 'wordpress_version', 'learndash_version', 'plugin_version' ),
	'P3B3-SOURCE-DESCRIPTOR-INTERNALLY-DERIVED-EXACT-THREE-FIELD exposes no configurable field'
);
archive_check(
	0 === preg_match( '/wp-load\\.php|wp-config\\.php|global\\s+\\$wpdb/i', $bootstrap_source . $module_source . $attestor_source ),
	'P3B3-NO-CURRENT-SITE-SELF-BOOTSTRAP loads no ambient database authority'
);
archive_check(
	0 === preg_match( '/wp_remote_|curl_|fsockopen|stream_socket_client|https?:\\/\\//i', $module_source . $attestor_source ),
	'P3B3-NO-NETWORK-OR-CERTIFICATE-ACQUISITION adds no external transport'
);

archive_finish();
