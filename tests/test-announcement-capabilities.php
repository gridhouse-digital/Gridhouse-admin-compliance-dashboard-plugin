<?php
require __DIR__ . '/bootstrap.php';

$GLOBALS['ghca_announce_args'] = array();
function register_post_type( $type, $args ): void { $GLOBALS['ghca_announce_args'] = $args; }
function register_post_meta(): void {}
function __( $text ) { return $text; }

require_once __DIR__ . '/../includes/class-announcements.php';
GHCA_ACD_Announcements::register_announce_cpt();

$capabilities = $GLOBALS['ghca_announce_args']['capabilities'] ?? array();
$blocked      = ! empty( $capabilities ) && count( array_unique( $capabilities ) ) === 1 && 'do_not_allow' === reset( $capabilities );

echo $blocked
  ? "PASS: generic WordPress post capabilities cannot mutate announcements\n"
  : "FAIL: announcement CPT exposes a generic WordPress post capability\n";
exit( $blocked ? 0 : 1 );
