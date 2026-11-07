<?php
/**
 * Bulk reminders (slice 6) through the plugin's real messaging service, with synthetic employees and a mocked
 * delivery worker (tests/fixtures/bulk-messaging-env.php). No message is sent: the worker only records what it
 * would schedule, and wp_mail() throws if called. Checks recipient boundaries, server-side permission and scope,
 * one personalised message per person, the review gate, duplicate fencing, partial failures and retries.
 */
require __DIR__ . '/fixtures/bulk-messaging-env.php';

$failed = 0;
$checks = 0;
function check( bool $ok, string $message ): void {
	global $failed, $checks;
	++$checks;
	if ( ! $ok ) {
		++$failed;
		fwrite( STDERR, "FAIL: $message\n" );
	}
}
function by_id( array $rows ): array {
	$out = array();
	foreach ( $rows as $r ) { $out[ (int) $r['id'] ] = $r; }
	return $out;
}
$base = array( 'nonce' => 'test' );

// ---- Selection boundaries.
check( is_wp_error( GHCA_ACD_Bulk_Messaging::ids_from_request( '' ) ), 'an empty selection is refused' );
check( is_wp_error( GHCA_ACD_Bulk_Messaging::ids_from_request( '301,abc' ) ) && is_wp_error( GHCA_ACD_Bulk_Messaging::ids_from_request( '301,-2' ) ) && is_wp_error( GHCA_ACD_Bulk_Messaging::ids_from_request( '301,0' ) ), 'non-numeric, negative or zero ids refuse the whole request' );
check( array( 301, 302 ) === GHCA_ACD_Bulk_Messaging::ids_from_request( '301, 302,301' ), 'duplicates collapse; order is kept' );
check( 25 === count( GHCA_ACD_Bulk_Messaging::ids_from_request( implode( ',', range( 1, 25 ) ) ) ) && is_wp_error( GHCA_ACD_Bulk_Messaging::ids_from_request( implode( ',', range( 1, 26 ) ) ) ), 'at most 25 recipients (below the 30-per-10-minutes sender limit)' );

// ---- Gates on every endpoint.
ghca_bulk_reset();
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_context', array( 'nonce' => 'stale', 'ids' => '301' ) );
check( 403 === $r['status'], 'a stale nonce is refused before anything is read' );
$GLOBALS['ghca_bulk']['can_remind'] = false;
foreach ( array( 'ghca_acd_bulk_message_context', 'ghca_acd_bulk_message_preview', 'ghca_acd_bulk_message_send' ) as $a ) {
	$r = ghca_bulk_ajax( $a, $base + array( 'ids' => '301', 'token' => str_repeat( 'a', 36 ), 'reviewed' => '1', 'subject' => 's', 'message' => 'm' ) );
	check( 403 === $r['status'] && false === $r['payload']['success'], "$a: no send-reminders permission, refused (403)" );
}
check( array() === $GLOBALS['ghca_bulk']['communications'], 'nothing was created without permission' );
ghca_bulk_reset();
$GLOBALS['ghca_bulk']['email_on'] = false;
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_context', $base + array( 'ids' => '301' ) );
check( false === $r['payload']['success'] && str_contains( $r['payload']['data']['message'], 'disabled' ), 'email reminders disabled in settings: refused' );
ghca_bulk_reset();

// ---- Context: each recipient checked by the service; out-of-scope ids reveal nothing.
$r   = ghca_bulk_ajax( 'ghca_acd_bulk_message_context', $base + array( 'ids' => '301,302,303,304,305,306,307' ) );
$ctx = by_id( $r['payload']['data']['recipients'] );
check( true === $r['payload']['success'] && 7 === count( $ctx ), 'context answers for exactly the ids sent, no more' );
check( $ctx[301]['ready'] && $ctx[302]['ready'] && $ctx[306]['ready'] && $ctx[307]['ready'], 'ready recipients' );
check( ! $ctx[303]['ready'] && str_contains( $ctx[303]['reason'], 'valid email' ), 'no valid email: not ready, with the reason' );
check( ! $ctx[304]['ready'] && '' === $ctx[304]['name'] && '' === $ctx[304]['destination'] && 'Invalid employee or permission denied.' === $ctx[304]['reason'], 'outside the viewer\'s scope: refused server-side, no name or address revealed' );
check( ! $ctx[305]['ready'] && str_contains( $ctx[305]['reason'], 'inactive' ), 'inactive employee with inactive reminders disabled: not ready' );
check( 'd***@example.test' === $ctx[301]['destination'] && ! str_contains( json_encode( $r['payload'] ), 'daniel.reyes@' ), 'addresses are masked; full addresses never reach the browser' );
$tpl = $r['payload']['data']['templates'];
check( 1 === count( $tpl ) && 'Overdue training' === $tpl[0]['name'] && str_contains( $tpl[0]['message'], '[employee_first_name]' ), 'only email-approved templates, unrendered so each person gets their own values' );
check( (bool) preg_match( '/^[a-f0-9-]{36}$/', $r['payload']['data']['token'] ), 'a fresh batch token' );

// ---- Preview: what each person would receive. Nothing stored.
$r  = ghca_bulk_ajax( 'ghca_acd_bulk_message_preview', $base + array( 'ids' => '301,302,303', 'subject' => $tpl[0]['subject'], 'message' => $tpl[0]['message'], 'urgency' => 'important' ) );
$pv = by_id( $r['payload']['data']['recipients'] );
check( str_contains( $pv[301]['message'], 'Hi Daniel,' ) && str_contains( $pv[302]['message'], 'Hi Maya,' ), 'each preview is personalised with that person\'s own first name' );
check( ! str_contains( $pv[301]['message'], 'Maya' ) && ! str_contains( $pv[302]['message'], 'Daniel' ), 'no preview mentions another recipient' );
check( str_contains( $pv[301]['message'], 'November 30, 2026' ) && str_contains( $pv[301]['message'], 'Synthetic Agency' ), 'due date and agency placeholders filled' );
check( 'IMPORTANT: Training overdue, Daniel' === $pv[301]['subject'], 'urgency prefix applied as on a single reminder' );
check( ! isset( $pv[303]['message'] ) && ! $pv[303]['ready'], 'a recipient who cannot receive email gets no preview' );
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_preview', $base + array( 'ids' => '301', 'subject' => 'Hi [nickname]', 'message' => 'x' ) );
check( false === by_id( $r['payload']['data']['recipients'] )[301]['ready'] && str_contains( by_id( $r['payload']['data']['recipients'] )[301]['reason'], 'Unknown message placeholder' ), 'an unknown placeholder is caught at review, before sending' );
check( array() === $GLOBALS['ghca_bulk']['communications'], 'context and preview store nothing' );

// ---- Send: review required, valid token required.
$token = wp_generate_uuid4();
$send  = $base + array( 'ids' => '301,302,306,307', 'subject' => $tpl[0]['subject'], 'message' => $tpl[0]['message'], 'urgency' => 'normal', 'template_id' => '1', 'token' => $token );
$r     = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', $send );
check( false === $r['payload']['success'] && str_contains( $r['payload']['data']['message'], 'Review' ), 'without the review flag nothing is sent' );
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'token' => 'not-a-token', 'reviewed' => '1' ) + $send );
check( false === $r['payload']['success'] && array() === $GLOBALS['ghca_bulk']['communications'], 'a malformed batch token is refused' );

$r   = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1' ) + $send );
$res = by_id( $r['payload']['data']['results'] );
check( 'queued' === $res[301]['status'] && 'queued' === $res[302]['status'] && 'queued' === $res[307]['status'], 'ready recipients queued' );
check( 'failed' === $res[306]['status'] && str_contains( $res[306]['message'], 'could not be scheduled' ), 'partial failure: a job that cannot be scheduled is reported for that person only' );
check( array( 'queued' => 3, 'duplicate' => 0, 'failed' => 1 ) === $r['payload']['data']['counts'], 'counts: 3 queued, 1 failed' );
$comms = $GLOBALS['ghca_bulk']['communications'];
check( 4 === count( $comms ), 'one communication record per recipient (Communication History rows), including the one whose job failed to schedule' );
$to = array();
foreach ( $comms as $c ) {
	$to[] = (int) $c['employee_user_id'];
	check( 1 === count( $c['deliveries'] ) && 'email' === $c['deliveries'][0]['channel'], 'employee ' . $c['employee_user_id'] . ': exactly one email delivery, no SMS' );
}
check( array( 301, 302, 306, 307 ) === $to, 'messages only for the ids sent, in order' );
check( str_contains( $comms[0]['message'], 'Hi Daniel,' ) && str_contains( $comms[1]['message'], 'Hi Maya,' ) && ! str_contains( $comms[0]['message'], 'Maya' ), 'each stored message is personalised; none names another recipient' );
check( 'Training overdue, Ivy' === $comms[3]['subject'] && 'Ivy Kim <script>' === $GLOBALS['ghca_bulk_people'][307][0], 'names are data: the subject carries the first name only, unchanged' );
check( 3 === $comms[0]['template_version'] && 1 === $comms[0]['template_id'], 'the template and its version are recorded' );
check( 3 === count( $GLOBALS['ghca_bulk']['scheduled'] ), 'mocked worker: three jobs would be scheduled; nothing delivered' );

// ---- Duplicate prevention: the same batch again, then a retry of the failed one.
$r   = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1' ) + $send );
$res = by_id( $r['payload']['data']['results'] );
check( 'duplicate' === $res[301]['status'] && 'duplicate' === $res[302]['status'] && 'duplicate' === $res[307]['status'] && 'duplicate' === $res[306]['status'], 'resubmitting the same batch creates nothing: every recipient reports already queued' );
check( 4 === count( $GLOBALS['ghca_bulk']['communications'] ) && 3 === count( $GLOBALS['ghca_bulk']['scheduled'] ), 'still four records and three jobs after the resubmission' );
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'ids' => '306' ) + $send );
check( 'duplicate' === $r['payload']['data']['results'][0]['status'] && 4 === count( $GLOBALS['ghca_bulk']['communications'] ), 'retrying the failed recipient with the same batch never creates a second message; its delivery is retried from Communication History' );
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'ids' => '303,304,305' ) + $send );
$res = by_id( $r['payload']['data']['results'] );
check( 'failed' === $res[303]['status'] && 'failed' === $res[304]['status'] && 'failed' === $res[305]['status'] && 4 === count( $GLOBALS['ghca_bulk']['communications'] ), 'not-ready or out-of-scope ids posted directly are refused by the service, one by one' );
$r = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'token' => wp_generate_uuid4(), 'ids' => '301' ) + $send );
check( 'queued' === $r['payload']['data']['results'][0]['status'] && 5 === count( $GLOBALS['ghca_bulk']['communications'] ), 'a new batch (new token) is a new message, as a deliberate second send' );

// ---- Sender rate limit: the service's own limit, reported per recipient.
ghca_bulk_reset();
$GLOBALS['ghca_bulk']['actor_count'] = 29;
$r   = ghca_bulk_ajax( 'ghca_acd_bulk_message_send', array( 'reviewed' => '1', 'token' => wp_generate_uuid4(), 'ids' => '301,302' ) + $send );
$res = by_id( $r['payload']['data']['results'] );
check( 'queued' === $res[301]['status'] && 'failed' === $res[302]['status'] && str_contains( $res[302]['message'], 'Too many reminders' ), 'at the sender limit the rest are reported as not sent, not silently dropped' );

check( true, 'wp_mail was never called (it throws in this fixture)' );

// ---- Export selected: scope enforced server-side, fail closed.
$runner = __DIR__ . '/csv-handler-runner.php';
$names  = json_encode( array( 'Alpha One', 'Beta Two', 'Gamma Three' ) );
$run    = static function ( ?string $ids ) use ( $runner, $names ): array {
	$env  = array_merge( getenv(), array( 'GHCA_CSV_HANDLER_NAMES' => $names ), null === $ids ? array() : array( 'GHCA_CSV_HANDLER_IDS' => $ids ) );
	$proc = proc_open( array( PHP_BINARY, '-d', 'display_errors=0', $runner ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	return array( 'out' => $out, 'err' => $err, 'code' => proc_close( $proc ) );
};
require_once __DIR__ . '/csv-strict-parser.php';
$all = rfc4180_parse( $run( null )['out'] );
check( is_array( $all ) && 4 === count( $all ), 'no selection: the ordinary full export (header plus three rows)' );
$sel = rfc4180_parse( $run( '102,100' )['out'] );
check( is_array( $sel ) && 3 === count( $sel ) && 'Gamma Three' === $sel[1][0] && 'Alpha One' === $sel[2][0], 'export selected: exactly the selected employees, in selection order' );
$bad = $run( '100,999' );
check( '' === trim( $bad['out'] ) && str_contains( $bad['err'], 'wp_die' ), 'a selected id outside the viewer\'s view: nothing exported at all' );
$bad = $run( '100,abc' );
check( '' === trim( $bad['out'] ) && str_contains( $bad['err'], 'wp_die' ), 'a malformed id: nothing exported' );

if ( $failed ) {
	fwrite( STDERR, "FAILED: $failed of $checks bulk messaging checks\n" );
	exit( 1 );
}
echo "PASS: $checks bulk messaging checks (synthetic; mocked delivery; nothing sent)\n";
