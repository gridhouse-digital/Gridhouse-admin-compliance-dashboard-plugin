<?php
// Employees-tab status wording (GRI-93). Synthetic; no WordPress, database or network.
require __DIR__ . '/bootstrap.php';
function __( $text, $domain = '' ) { return $text; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
require_once __DIR__ . '/../includes/class-data-provider.php';
require_once __DIR__ . '/../includes/class-table-ui.php';

$fails = 0;
function label_check( bool $ok, string $m ): void { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $m\n"; $ok || $fails++; }

$new_label = 'All assigned courses complete';
$options = GHCA_ACD_Data_Provider::get_status_options();
label_check( $new_label === ( $options['completed'] ?? null ), 'filter option text for the completed status is the new wording' );
label_check( array( 'completed', 'expiring_soon', 'in_progress', 'not_started', 'overdue', 'expired' ) === array_keys( $options ), 'internal status keys (filters, CSS classes, saved data) are unchanged' );
label_check( 'Completed' !== $options['completed'], 'bare "Completed" is no longer offered as the employee status filter' );

$provider = file_get_contents( __DIR__ . '/../includes/class-data-provider.php' );
label_check( 1 === preg_match( "/\\\$status_slug\\s*=\\s*'completed';\\s*(?:\\/\\/[^\\n]*\\n\\s*)?\\\$status_label\\s*=\\s*__\\( 'All assigned courses complete'/", $provider ), "employee row keeps status_slug 'completed' and shows the new label" );
label_check( false !== strpos( $provider, "'completed' === \$filters['status']" ) || false !== strpos( $provider, "\$filters['status'] === 'completed'" ), "status filter still matches the 'completed' value" );
label_check( false !== strpos( $provider, "'completed'   => __( 'Completed', 'ghca-acd' )," ), 'per-course status label stays "Completed" (a single course is complete)' );

$help = 'LearnDash enrolled courses only; orientation status is on the audit packet.';
$shortcodes = file_get_contents( __DIR__ . '/../includes/class-shortcodes.php' );
label_check( 2 === substr_count( $shortcodes, "render_sortable_header( 'status_slug', __( 'Status', 'ghca-acd' ), \$orderby, \$order, __( '$help', 'ghca-acd' ) )" ), 'both Status column headers pass the help text' );
$th = GHCA_ACD_Table_UI::render_sortable_header( 'status_slug', 'Status', 'name', 'asc', $help );
label_check( false !== strpos( $th, 'title="' . $help . '"' ) && false !== strpos( $th, 'data-ghca-sort="status_slug"' ), 'rendered Status header carries the tooltip and keeps its sort hook' );
label_check( false === strpos( GHCA_ACD_Table_UI::render_sortable_header( 'name', 'Employee', 'name', 'asc' ), 'title=' ), 'headers without help text render no tooltip' );

$compliance = file_get_contents( __DIR__ . '/../includes/class-compliance-program.php' );
label_check( false !== strpos( $compliance, "'New Hire Complete'" ), 'New Hire Complete wording is unchanged' );

echo 0 === $fails ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit( 0 === $fails ? 0 : 1 );
