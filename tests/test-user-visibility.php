<?php
require __DIR__ . '/bootstrap.php';

$primary_group = 10;

function get_userdata( int $user_id ): object {
	return (object) array( 'ID' => $user_id );
}
function current_user_can( string $capability ): bool {
	return false;
}

final class GHCA_ACD_Roles {
	public static function user_can_manage_users(): bool {
		return false;
	}
}
final class GHCA_ACD_Data_Provider {
	public static function is_user_suspended( int $user_id ): bool {
		return false;
	}
	public static function get_user_primary_group_id( int $user_id ): int {
		global $primary_group;
		return $primary_group;
	}
	public static function get_employee_record( int $user_id ): array {
		throw new RuntimeException( 'Visibility checks must not build employee records.' );
	}
}
final class GHCA_ACD_Scoping {
	public static function get_visible_group_ids(): array {
		return array( 10 );
	}
}

require_once __DIR__ . '/../includes/class-user-report.php';

if ( ! GHCA_ACD_User_Report::can_view_user( 7 ) ) {
	echo "FAIL: in-scope employee was rejected\n";
	exit( 1 );
}

$primary_group = 20;
if ( GHCA_ACD_User_Report::can_view_user( 7 ) ) {
	echo "FAIL: out-of-scope employee was accepted\n";
	exit( 1 );
}

echo "PASS: visibility checks use direct group lookup without employee-record recursion\n";
