<?php
require __DIR__ . '/bootstrap.php';

function add_shortcode(): void {}
function get_posts(): array { return array( 10, 20 ); }
function apply_filters( $tag, $value ) { return $value; }
function current_user_can(): bool { return false; }
function wp_get_current_user(): object { return (object) array( 'roles' => array( 'group_leader' ) ); }

final class GHCA_ACD_Roles {
  public static function user_has_unrestricted_view(): bool { return false; }
}

require_once __DIR__ . '/../includes/class-scoping.php';

$visible = GHCA_ACD_Scoping::get_visible_group_ids();
if ( array() !== $visible ) {
  echo "FAIL: group-leader scope must fail closed when the LearnDash helper is unavailable\n";
  exit( 1 );
}

echo "PASS: group-leader scope fails closed when the LearnDash helper is unavailable\n";
