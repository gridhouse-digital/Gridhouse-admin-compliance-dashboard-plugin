<?php
/** Runnable regression for the employee-document search SQL boundary. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

final class GHCA_ACD_Jotform_Schema {
	public static function documents_table(): string { return 'wp_ghca_acd_jotform_documents'; }
	public static function training_table(): string { return 'wp_ghca_acd_external_training'; }
}

final class Document_Search_WPDB {
	/** @var array<int,array{query:string,args:array<int,mixed>}> */
	public array $prepared = array();

	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$this->prepared[] = array( 'query' => $query, 'args' => $args );
		return $query;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $query, string $format ): array {
		return array( array( 'id' => 7, 'review_status' => 'approved' ) );
	}

	public function get_var( string $query ): int { return 1; }
}

$wpdb = new Document_Search_WPDB();
require_once dirname( __DIR__ ) . '/includes/jotform/class-jotform-repository.php';

$result = GHCA_ACD_Jotform_Repository::search_documents_for_employee( 47, 'certificate', 75, -2 );
$list = $wpdb->prepared[1] ?? array( 'query' => '', 'args' => array() );
$fails = 0;

function document_search_query_check( bool $condition, string $message ): void {
	global $fails;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$fails += $condition ? 0 : 1;
}

document_search_query_check( false !== strpos( $list['query'], 'FROM wp_ghca_acd_external_training current_revision' ) && false === strpos( $list['query'], 'FROM  current_revision' ), 'review-status subquery uses the configured external-training table' );
document_search_query_check( array( 47, 'certificate', 'certificate', 'certificate', 50, 0 ) === $list['args'], 'search arguments retain employee scope and clamp pagination bounds' );
document_search_query_check( 1 === $result['total'] && 'approved' === $result['items'][0]['review_status'], 'repository returns the enriched document row and count' );

exit( $fails ? 1 : 0 );
