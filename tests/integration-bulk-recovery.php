<?php
/**
 * Real WordPress + MySQL/MariaDB concurrency checks for bulk-message recovery: the scheduling lock, the recovery
 * transition and recover_delivery(), run by separate PHP processes against one database at the same instant.
 *
 * Runs only inside a disposable WordPress install whose wp-config.php defines GHCA_DISPOSABLE_TEST_SITE and whose
 * database name starts with "ghca_test"; it creates synthetic users and messaging rows there. Skips otherwise.
 * No mail is sent: the worker's process() is never called, and a pre_wp_mail filter blocks and counts any attempt.
 * Jobs are WordPress cron entries (DISABLE_WP_CRON keeps them from running); the test reads the cron option.
 *
 *   php wp-content/plugins/<plugin>/tests/integration-bulk-recovery.php
 */

$wp_root = dirname( __DIR__, 4 );
if ( ! is_file( $wp_root . '/wp-load.php' ) || ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) ) {
	echo "SKIP: needs a WordPress install, mysqli and pcntl\n";
	exit( 0 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once $wp_root . '/wp-load.php';
if ( ! defined( 'GHCA_DISPOSABLE_TEST_SITE' ) || 0 !== strpos( DB_NAME, 'ghca_test' ) || ! class_exists( 'GHCA_ACD_Messaging_Service' ) ) {
	echo "SKIP: not a disposable ghca_test site with the plugin active\n";
	exit( 0 );
}

// LearnDash is not installed on the disposable site. These stand-ins give the employee no courses; they only let
// the plugin build an employee record. WordPress, the database, the lock, the transitions and the service are real.
if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
	function learndash_user_get_enrolled_courses( $user_id = 0, $args = array(), $bypass = false ) { return array(); }
	function learndash_course_completed( $user_id, $course_id ) { return false; }
	function learndash_course_progress( $args = array() ) { return array(); }
	function learndash_course_status( $course_id, $user_id = null ) { return ''; }
	function learndash_get_course_certificate_link( $course_id, $user_id = null ) { return ''; }
	function learndash_get_groups_user_ids( $group_id ) { return array(); }
	function learndash_is_user_in_group( $user_id, $group_id ) { return false; }
	function learndash_user_get_course_progress( $user_id = 0, $course_id = 0, $type = 'summary' ) { return array(); }
}

$mail_attempts = 0;
add_filter( 'pre_wp_mail', static function () use ( &$mail_attempts ) { ++$mail_attempts; return false; } );

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	echo ( $ok ? 'ok - ' : 'FAIL - ' ) . $message . "\n";
	if ( ! $ok ) { ++$failed; }
}

global $wpdb;
GHCA_ACD_Messaging_Schema::install();
update_option( GHCA_ACD_Settings::OPTION_REMINDER_EMAIL_ENABLED, 1 );
$admin = get_user_by( 'login', 'synthetic-admin' );
$emp   = get_user_by( 'login', 'synthetic-employee' );
if ( ! $emp ) {
	$id  = wp_insert_user( array( 'user_login' => 'synthetic-employee', 'user_email' => 'synthetic.employee@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'first_name' => 'Synthetic' ) );
	$emp = get_user_by( 'id', $id );
}
wp_set_current_user( $admin->ID );

$D = GHCA_ACD_Messaging_Schema::deliveries_table();
$C = GHCA_ACD_Messaging_Schema::communications_table();

/** A saved, never-scheduled email delivery, made through the real repository. */
function make_unscheduled( int $employee ): int {
	$r = GHCA_ACD_Messaging_Repository::create_communication(
		array( 'employee_user_id' => $employee, 'actor_user_id' => get_current_user_id(), 'urgency' => 'normal', 'subject' => 'Synthetic subject', 'message' => 'Synthetic message', 'idempotency_key' => hash( 'sha256', random_bytes( 16 ) ), 'requested_channels' => 'email' ),
		array( array( 'channel' => 'email', 'provider' => 'wordpress_mail', 'masked_destination' => 's***@example.test' ) )
	);
	GHCA_ACD_Messaging_Repository::mark_schedule_failed( (int) $r['delivery_id'] );
	return (int) $r['delivery_id'];
}
function row( int $id ): array {
	global $wpdb;
	wp_cache_flush();
	return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT state, attempt_count, last_error_code FROM ' . GHCA_ACD_Messaging_Schema::deliveries_table() . ' WHERE id = %d', $id ), ARRAY_A );
}
function lock_row( int $id ): ?string {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'ghca_acd_sched_lock_' . $id ) );
	return null === $v ? null : (string) $v;
}
function jobs( int $id ): int {
	global $wpdb;
	wp_cache_delete( 'cron', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	$cron = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" ) );
	$n    = 0;
	foreach ( (array) $cron as $ts => $hooks ) {
		if ( is_array( $hooks ) && isset( $hooks[ GHCA_ACD_Messaging_Worker::HOOK ] ) ) {
			foreach ( $hooks[ GHCA_ACD_Messaging_Worker::HOOK ] as $event ) {
				if ( array( $id ) === $event['args'] ) { ++$n; }
			}
		}
	}
	return $n;
}
function clear_jobs( int $id ): void {
	wp_clear_scheduled_hook( GHCA_ACD_Messaging_Worker::HOOK, array( $id ) );
}

/**
 * Runs $fn in $n forked processes that each open their own database connection and start at the same instant.
 * Returns each child's JSON result.
 */
function race( int $n, callable $fn ): array {
	global $wpdb;
	$start = microtime( true ) + 0.4;
	$pipes = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		$pid  = pcntl_fork();
		if ( 0 === $pid ) {
			fclose( $pair[0] );
			$wpdb->close();
			$wpdb->db_connect( false );
			wp_cache_flush();
			$wait = $start - microtime( true );
			if ( $wait > 0 ) { usleep( (int) ( $wait * 1e6 ) ); }
			$out = $fn( $i );
			fwrite( $pair[1], json_encode( $out ) );
			fclose( $pair[1] );
			exit( 0 );
		}
		fclose( $pair[1] );
		$pipes[ $pid ] = $pair[0];
	}
	$results = array();
	foreach ( $pipes as $pid => $pipe ) {
		$results[] = json_decode( (string) stream_get_contents( $pipe ), true );
		fclose( $pipe );
		pcntl_waitpid( $pid, $status );
	}
	$wpdb->close();
	$wpdb->db_connect( false );
	wp_cache_flush();
	return $results;
}

echo '# WordPress ' . get_bloginfo( 'version' ) . ', ' . $wpdb->db_server_info() . "\n";

// ---- 1. Simultaneous acquisition.
$id = make_unscheduled( $emp->ID );
$tokens = race( 8, static function () use ( $id ) { return GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 60 ); } );
$won    = array_values( array_filter( $tokens ) );
check( 1 === count( $won ) && 0 === strpos( (string) lock_row( $id ), $won[0] . '|' ), '8 processes acquire at once: exactly one gets a token, and the stored row is that token' );
check( true === GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $won[0] ) && null === lock_row( $id ), 'the winner releases its lock' );
check( false === GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $won[0] ), 'releasing again does nothing (repeated release)' );

// ---- 2. Expired owner A, successor B.
$A = GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 1 );
sleep( 2 );
$B = race( 1, static function () use ( $id ) { return GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 60 ); } )[0];
check( '' !== $A && '' !== $B && $A !== $B && 0 === strpos( (string) lock_row( $id ), $B . '|' ), 'A\'s lease expires; B (another process) takes the lock with its own token' );
check( false === GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $A ) && 0 === strpos( (string) lock_row( $id ), $B . '|' ), 'A releasing afterwards leaves B\'s lock in place' );
check( false === GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $A ) && array( 'state' => 'failed', 'attempt_count' => '0', 'last_error_code' => 'queue_schedule_failed' ) === row( $id ), 'A cannot requeue: the delivery row is unchanged' );
check( false === GHCA_ACD_Messaging_Repository::holds_schedule_lock( $id, $A ) && true === GHCA_ACD_Messaging_Repository::holds_schedule_lock( $id, $B ), 'ownership check: A no longer holds it, B does' );
check( true === GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $B ) && 'queued' === row( $id )['state'], 'B can requeue' );
check( false === GHCA_ACD_Messaging_Repository::mark_schedule_failed_as_owner( $id, $A ) && 'queued' === row( $id )['state'], 'A cannot mark B\'s requeued delivery failed' );
GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $B );

// ---- 3. Simultaneous takeover of one expired lock.
$id = make_unscheduled( $emp->ID );
GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 1 );
sleep( 2 );
$tokens = race( 8, static function () use ( $id ) { return GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 60 ); } );
$won    = array_values( array_filter( $tokens ) );
check( 1 === count( $won ) && 0 === strpos( (string) lock_row( $id ), $won[0] . '|' ), '8 processes try to take over one expired lock at once: exactly one succeeds' );
GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $won[0] );

// ---- 4. A takeover in flight blocks the stale owner's write until it commits; then that write matches nothing.
$id  = make_unscheduled( $emp->ID );
$A   = GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 60 );
$res = race( 2, static function ( $i ) use ( $id, $A ) {
	global $wpdb;
	if ( 0 === $i ) {
		// Successor: overwrite the lock inside a transaction and hold it uncommitted for 1.5 s.
		$x = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$x->query( 'START TRANSACTION' );
		$x->query( "UPDATE {$wpdb->options} SET option_value = 'successor00000000000000000000000|" . ( time() + 60 ) . "' WHERE option_name = 'ghca_acd_sched_lock_{$id}'" );
		usleep( 1500000 );
		$x->query( 'COMMIT' );
		$x->close();
		return array( 'role' => 'successor' );
	}
	usleep( 300000 );
	$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
	$t  = microtime( true );
	$ok = GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $A );
	return array( 'role' => 'stale', 'ok' => $ok, 'waited' => round( microtime( true ) - $t, 2 ), 'error' => $wpdb->last_error );
} );
$stale = $res[1];
check( false === $stale['ok'] && '' === $stale['error'], 'the stale owner\'s requeue, issued while a takeover is uncommitted, changes nothing (no error)' );
check( $stale['waited'] >= 0.9, 'it waited for the takeover to commit (' . $stale['waited'] . ' s): the ownership check in the UPDATE is a locking read, so the two statements are serialised' );
check( 'failed' === row( $id )['state'], 'the delivery stays failed/unscheduled' );

// ---- 5. Eligibility decided by the UPDATE: state and attempt changes after the read.
$id  = make_unscheduled( $emp->ID );
$tok = GHCA_ACD_Messaging_Repository::acquire_schedule_lock( $id, 60 );
$wpdb->update( $D, array( 'attempt_count' => 1 ), array( 'id' => $id ) );
check( false === GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $tok ) && array( 'state' => 'failed', 'attempt_count' => '1', 'last_error_code' => 'queue_schedule_failed' ) === row( $id ), 'attempt_count changed to 1 after the read: not requeued' );
$wpdb->update( $D, array( 'attempt_count' => 0, 'last_error_code' => 'worker_interrupted' ), array( 'id' => $id ) );
check( false === GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $tok ), 'failure reason changed (worker_interrupted, ambiguous): not requeued' );
$wpdb->update( $D, array( 'state' => 'accepted', 'last_error_code' => 'queue_schedule_failed' ), array( 'id' => $id ) );
check( false === GHCA_ACD_Messaging_Repository::requeue_unscheduled( $id, $tok ) && 'accepted' === row( $id )['state'], 'already sent: not requeued' );
$wpdb->update( $D, array( 'state' => 'failed', 'attempt_count' => 1, 'last_error_code' => 'wp_mail_failed' ), array( 'id' => $id ) );
check( true === GHCA_ACD_Messaging_Repository::requeue_failed( $id ) && 'queued' === row( $id )['state'], 'the manual Communication History retry (requeue_failed) keeps its broader policy' );
GHCA_ACD_Messaging_Repository::release_schedule_lock( $id, $tok );

// ---- 6. Concurrent retries through the full service: one job, one owner.
$id      = make_unscheduled( $emp->ID );
$results = race( 8, static function () use ( $id, $admin ) {
	wp_set_current_user( $admin->ID );
	$r = GHCA_ACD_Messaging_Service::recover_delivery( $id );
	return is_wp_error( $r ) ? array( 'status' => 'error:' . $r->get_error_code() ) : $r;
} );
$statuses = array_count_values( array_column( $results, 'status' ) );
check( 1 === ( $statuses['recovered'] ?? 0 ) && 8 === array_sum( $statuses ) && 0 === count( array_diff( array_keys( $statuses ), array( 'recovered', 'busy', 'pending' ) ) ), '8 simultaneous retries: exactly one recovered; the rest busy or already queued (' . json_encode( $statuses ) . ')' );
check( 'queued' === row( $id )['state'] && '0' === row( $id )['attempt_count'] && 1 === jobs( $id ), 'database after the race: the same delivery queued, never attempted, exactly one pending job' );
check( null === lock_row( $id ), 'no lock left behind' );
$again = GHCA_ACD_Messaging_Service::recover_delivery( $id );
check( 'pending' === $again['status'] && 1 === jobs( $id ), 'a later retry: already queued, still one job' );

// ---- 7. Scheduling failure under concurrency, then recovery.
$id = make_unscheduled( $emp->ID );
$results = race( 6, static function () use ( $id, $admin ) {
	wp_set_current_user( $admin->ID );
	add_filter( 'pre_schedule_event', static function () { return false; } ); // cron refuses every event
	$r = GHCA_ACD_Messaging_Service::recover_delivery( $id );
	return is_wp_error( $r ) ? array( 'status' => 'error:' . $r->get_error_code() ) : $r;
} );
$statuses = array_count_values( array_column( $results, 'status' ) );
check( 0 === ( $statuses['recovered'] ?? 0 ) && 0 === count( array_diff( array_keys( $statuses ), array( 'unscheduled', 'busy' ) ) ), 'scheduling fails in all 6 racing retries: none reports recovered (' . json_encode( $statuses ) . ')' );
check( array( 'state' => 'failed', 'attempt_count' => '0', 'last_error_code' => 'queue_schedule_failed' ) === row( $id ) && 0 === jobs( $id ) && null === lock_row( $id ), 'database: back to failed/unscheduled, no job, no lock left' );
$r = GHCA_ACD_Messaging_Service::recover_delivery( $id );
check( 'recovered' === $r['status'] && 'queued' === row( $id )['state'] && 1 === jobs( $id ), 'scheduling works again: recovered with exactly one job' );

// ---- 8. Attempted, processing and sent deliveries are never recovered, even by racing retries.
foreach ( array( array( 'processing', 1, null ), array( 'accepted', 1, null ), array( 'failed', 1, 'worker_interrupted' ) ) as $case ) {
	$id = make_unscheduled( $emp->ID );
	$wpdb->update( $D, array( 'state' => $case[0], 'attempt_count' => $case[1], 'last_error_code' => $case[2] ), array( 'id' => $id ) );
	$results  = race( 4, static function () use ( $id, $admin ) { wp_set_current_user( $admin->ID ); $r = GHCA_ACD_Messaging_Service::recover_delivery( $id ); return is_wp_error( $r ) ? array( 'status' => 'error' ) : $r; } );
	$statuses = array_unique( array_column( $results, 'status' ) );
	check( 1 === count( $statuses ) && in_array( $statuses[0], array( 'processing', 'sent', 'delivery_failed' ), true ) && $case[0] === row( $id )['state'] && 0 === jobs( $id ), $case[0] . ( $case[2] ? ' (' . $case[2] . ')' : '' ) . ': 4 racing retries all report ' . $statuses[0] . '; row unchanged; no job' );
}

// ---- 9. Two jobs for one delivery still give one claim (the worker's compare-and-set).
$id = make_unscheduled( $emp->ID );
$wpdb->update( $D, array( 'state' => 'queued', 'last_error_code' => null ), array( 'id' => $id ) );
$claims = race( 8, static function () use ( $id ) { return null !== GHCA_ACD_Messaging_Repository::claim_delivery( $id ); } );
check( 1 === count( array_filter( $claims ) ) && 'processing' === row( $id )['state'] && '1' === row( $id )['attempt_count'], '8 processes claim one queued delivery at once: exactly one claim (claim only; nothing sent)' );

check( 0 === $mail_attempts, 'no mail was attempted in this process' );

// Cleanup: remove this run's synthetic rows and jobs.
$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$D}" ) );
foreach ( $ids as $d ) { clear_jobs( $d ); }
$wpdb->query( "DELETE FROM {$D}" );
$wpdb->query( "DELETE FROM {$C}" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ghca\\_acd\\_sched\\_lock\\_%'" );

echo $failed ? "FAILED: $failed of $checks real-database recovery checks\n" : "PASS: $checks real-database recovery checks (WordPress + MariaDB, separate processes; nothing sent)\n";
exit( $failed ? 1 : 0 );
